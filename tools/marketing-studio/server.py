#!/usr/bin/env python3
"""
Mowology Marketing Studio — local server.

Serves the Studio UI and a tiny JSON API over 127.0.0.1 only. No dependencies
beyond the Python standard library.

  GET  /                       the app
  GET  /api/context            voice card, never-list, open questions, skill map, keyword hints
  GET  /api/pipeline           all pieces (state/pipeline.json)
  POST /api/pipeline           save one piece  {piece}
  POST /api/pipeline/delete    {id}
  POST /api/handoff            {id} → writes outbox/<slug>.md, copies the prompt, opens Claude
  GET  /api/draft?id=          returns outbox/<slug>.draft.md if Claude has written it
  POST /api/open               {path} → opens a repo-relative file with the default app

The hand-off to Claude is file-based on purpose: the desktop app has no CLI on
this machine, so the Studio writes the brief and Claude picks it up with /studio.
"""
import json, os, re, subprocess, sys, time, uuid
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..'))
APP = os.path.join(HERE, 'app')
STATE = os.path.join(HERE, 'state', 'pipeline.json')
OUTBOX = os.path.join(HERE, 'outbox')
CONTEXT = os.path.join(REPO, '.agents', 'product-marketing-context.md')
PORT = int(os.environ.get('STUDIO_PORT', '8740'))

os.makedirs(os.path.dirname(STATE), exist_ok=True)
os.makedirs(OUTBOX, exist_ok=True)


def read_pipeline():
    if not os.path.exists(STATE):
        return {'pieces': []}
    with open(STATE) as f:
        return json.load(f)


def write_pipeline(data):
    tmp = STATE + '.tmp'
    with open(tmp, 'w') as f:
        json.dump(data, f, indent=2)
    os.replace(tmp, STATE)


def slugify(text):
    s = re.sub(r'[^a-z0-9]+', '-', text.lower()).strip('-')
    return s[:60] or 'piece'


def load_context():
    voice, never, questions = '', [], []
    if os.path.exists(CONTEXT):
        md = open(CONTEXT).read()
        m = re.search(r'```\n(BRAND VOICE CARD.*?)```', md, re.S)
        voice = m.group(1).strip() if m else ''
        nm = re.search(r'^Never:\s*(.+?)(?:\n\S|\n```)', md, re.S | re.M)
        if nm:
            never = [x.strip() for x in re.split(r',\s*', nm.group(1).replace('\n', ' ')) if x.strip()]
        qm = re.search(r'## 9\. Open questions for the owner\n(.*)', md, re.S)
        if qm:
            questions = [re.sub(r'^\d+\.\s*', '', l).strip() for l in qm.group(1).splitlines() if re.match(r'^\d+\.', l.strip())]
    return {'voiceCard': voice, 'never': never, 'openQuestions': questions,
            'contextPath': os.path.relpath(CONTEXT, REPO), 'hasContext': os.path.exists(CONTEXT)}


def compose_brief(piece, ctx):
    """The markdown Claude will read via /studio."""
    b = piece.get('brief', {})
    lines = [
        f"# Studio brief — {piece.get('title') or piece.get('typeLabel')}",
        '',
        f"- Piece type: {piece.get('typeLabel')} (`{piece.get('type')}`)",
        f"- Skills to use: {', '.join(piece.get('skills', []))}",
        f"- Channel / where it ships: {piece.get('ship', '')}",
        f"- Audience: {b.get('audience', '')}",
        f"- Awareness on arrival: {b.get('awareness', '')}",
        f"- The one action: {b.get('action', '')}",
        f"- Target query / topic: {b.get('keyword', '')}",
        f"- Proof available (true only): {b.get('proof', '')}",
        f"- Must say: {b.get('mustSay', '')}",
        f"- Must not say: {b.get('mustNotSay', '')}",
        f"- Length / format: {b.get('length', '')}",
        f"- Notes from the owner: {b.get('notes', '')}",
        '',
        '## Voice (from .agents/product-marketing-context.md)',
        '```', ctx.get('voiceCard', ''), '```',
        '',
        '## What to return',
        f"Write the finished draft to `tools/marketing-studio/outbox/{piece['slug']}.draft.md` with these sections: ",
        '`# Draft`, `## Annotations` (which lead type, which objections are answered where, where proof sits), ',
        '`## Alternatives` (2–3 headline/CTA options), `## Compliance flags` (any number, testimonial or guarantee that needs checking). ',
        f"Then set this piece's status to `review` in `tools/marketing-studio/state/pipeline.json` (id `{piece['id']}`).",
    ]
    tb = [t for t in piece.get('tacticsBrief', []) if t.get('name')]
    if tb:
        lines += ['', '## Tactics (Pip Decks concepts, chosen for this piece type)', '',
                  'Apply each card. The owner\'s answer for this piece wins over the standing answer.', '']
        for t in tb:
            lines.append(f"- **{t['name']}** ({t.get('deck', '')}): {t.get('ask', '')}")
            if t.get('standing'):
                lines.append(f"  - Standing answer: {t['standing']}")
            if t.get('answer'):
                lines.append(f"  - For this piece: {t['answer']}")
    if piece.get('revisionNotes'):
        lines += ['', '## Revision notes', '', piece['revisionNotes'].strip(), '']
    return '\n'.join(lines) + '\n'


def compose_prompt(piece):
    return (f"/studio {piece['slug']}\n\n"
            f"Pick up the Marketing Studio brief at tools/marketing-studio/outbox/{piece['slug']}.md, "
            f"read .agents/product-marketing-context.md, use the {', '.join(piece.get('skills', []))} skill(s), "
            f"write the draft to tools/marketing-studio/outbox/{piece['slug']}.draft.md and mark the piece as review.")


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *a, **k):
        super().__init__(*a, directory=APP, **k)

    def log_message(self, fmt, *args):
        sys.stderr.write('[studio] ' + (fmt % args) + '\n')

    def send_json(self, obj, code=200):
        body = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def read_json(self):
        n = int(self.headers.get('Content-Length', '0') or 0)
        return json.loads(self.rfile.read(n) or b'{}')

    def do_GET(self):
        u = urlparse(self.path)
        if u.path == '/api/context':
            ctx = load_context()
            ctx['repo'] = REPO
            return self.send_json(ctx)
        if u.path == '/api/pipeline':
            return self.send_json(read_pipeline())
        if u.path == '/api/draft':
            pid = parse_qs(u.query).get('id', [''])[0]
            data = read_pipeline()
            piece = next((p for p in data['pieces'] if p['id'] == pid), None)
            if not piece:
                return self.send_json({'error': 'no such piece'}, 404)
            path = os.path.join(OUTBOX, piece['slug'] + '.draft.md')
            if not os.path.exists(path):
                return self.send_json({'ready': False})
            return self.send_json({'ready': True, 'markdown': open(path).read(),
                                   'path': os.path.relpath(path, REPO),
                                   'modified': int(os.path.getmtime(path))})
        if u.path == '/':
            self.path = '/index.html'
        return super().do_GET()

    def do_POST(self):
        u = urlparse(self.path)
        body = self.read_json()
        if u.path == '/api/pipeline':
            piece = body.get('piece') or {}
            data = read_pipeline()
            if not piece.get('id'):
                piece['id'] = uuid.uuid4().hex[:10]
                piece['created'] = int(time.time())
            piece['updated'] = int(time.time())
            piece['slug'] = piece.get('slug') or slugify((piece.get('title') or piece.get('typeLabel') or 'piece'))
            data['pieces'] = [p for p in data['pieces'] if p['id'] != piece['id']] + [piece]
            write_pipeline(data)
            return self.send_json({'ok': True, 'piece': piece})
        if u.path == '/api/pipeline/delete':
            data = read_pipeline()
            data['pieces'] = [p for p in data['pieces'] if p['id'] != body.get('id')]
            write_pipeline(data)
            return self.send_json({'ok': True})
        if u.path == '/api/handoff':
            data = read_pipeline()
            piece = next((p for p in data['pieces'] if p['id'] == body.get('id')), None)
            if not piece:
                return self.send_json({'error': 'no such piece'}, 404)
            ctx = load_context()
            brief_path = os.path.join(OUTBOX, piece['slug'] + '.md')
            with open(brief_path, 'w') as f:
                f.write(compose_brief(piece, ctx))
            prompt = compose_prompt(piece)
            copied = False
            try:
                subprocess.run(['pbcopy'], input=prompt.encode(), check=True)
                copied = True
            except Exception:
                pass
            opened = False
            if body.get('openClaude', True):
                try:
                    subprocess.run(['open', '-a', 'Claude'], check=True)
                    opened = True
                except Exception:
                    pass
            piece['status'] = 'with-claude'
            piece['handedOff'] = int(time.time())
            piece['briefPath'] = os.path.relpath(brief_path, REPO)
            write_pipeline(data)
            return self.send_json({'ok': True, 'prompt': prompt, 'copied': copied, 'opened': opened,
                                   'briefPath': piece['briefPath'], 'piece': piece})
        if u.path == '/api/open':
            rel = body.get('path', '')
            path = os.path.abspath(os.path.join(REPO, rel))
            if not path.startswith(REPO) or not os.path.exists(path):
                return self.send_json({'error': 'not found'}, 404)
            subprocess.run(['open', path])
            return self.send_json({'ok': True})
        return self.send_json({'error': 'unknown endpoint'}, 404)


if __name__ == '__main__':
    httpd = ThreadingHTTPServer(('127.0.0.1', PORT), Handler)
    print(f'Mowology Marketing Studio → http://127.0.0.1:{PORT}/  (repo: {REPO})')
    try:
        httpd.serve_forever()
    except KeyboardInterrupt:
        pass
