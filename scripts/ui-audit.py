#!/usr/bin/env python3
"""scripts/ui-audit.py — mechanical phone-UI checks from the mobile-ui-craft skill (.claude/skills/mobile-ui-craft).
Reads tracked files under public/ (CSS, and <style> blocks + viewport metas in PHP/HTML). Informational: exit 0 unless --fail.
  M1  inputs/selects/textareas below 16px      M2  pinch-zoom locked in the viewport meta
  M4  fixed bottom bars without safe-area      M6  grids of 4+ columns with no phone override
Usage: python3 scripts/ui-audit.py [--fail] [--all]     (--all lists every hit, default top 5 per check)
"""
import re, subprocess, sys, collections

SKIP = ('crinum', 'vendor', 'classic.css', 'corporate.css', '.min.css', 'node_modules')
files = [f for f in subprocess.run(['git', 'ls-files', 'public'], capture_output=True, text=True).stdout.split()
         if f.endswith(('.css', '.php', '.html')) and not any(s in f for s in SKIP)]

def css_rules(text):
    """Yield (selector, body, in_phone_media) with a simple brace stack (no regex backtracking)."""
    text = re.sub(r'/\*.*?\*/', '', text, flags=re.S)
    stack, i, start = [], 0, 0          # stack of at-rule preludes; None for style rules
    n = len(text)
    while i < n:
        c = text[i]
        if c == '{':
            prelude = text[start:i].strip()
            if prelude.startswith('@'):
                stack.append(prelude); start = i + 1
            else:
                j = text.find('}', i)
                if j < 0: break
                phone = any('max-width' in p for p in stack if p.startswith('@media')) or \
                        any('pointer: coarse' in p or 'hover: none' in p for p in stack)
                yield prelude, text[i + 1:j], phone
                i = j; start = j + 1
        elif c == '}':
            if stack: stack.pop()
            start = i + 1
        i += 1

INPUT = re.compile(r'(^|[\s,>+~])(input|select|textarea)\b|form-control|form-select|input\[')
SKIPSEL = re.compile(r'::?(placeholder|-webkit)|label|legend|help|option|checkbox|radio|\.sr-only')
FS = re.compile(r'font-size:\s*([0-9.]+)(px|rem|em)')
COLS = re.compile(r'grid-template-columns:\s*([^;]+)')
hits = collections.defaultdict(list)
all_rules = {}
phone_selectors = set()
covered_files = set()    # files with a phone-only global 16px rule on select (covers every small input in that file)
coarse16 = set()          # (file, selector) with a phone-only font-size:16px rule
safe_selectors = set()   # selectors that get safe-area padding in ANY rule (it is often added in a later phone rule)

for f in files:
    raw = open(f, errors='ignore').read()
    if f.endswith('.css'):
        css = raw
    else:
        css = '\n'.join(re.findall(r'<style[^>]*>(.*?)</style>', raw, re.S))
        for m in re.finditer(r'<meta name="viewport"[^>]*>', raw):
            if re.search(r'user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*1(\.0)?(?![0-9])', m.group(0)):
                hits['M2 zoom locked'].append((f, 'viewport meta'))
    rules = list(css_rules(css)) if css else []
    all_rules[f] = rules
    for sel, body, phone in rules:
        if 'safe-area' in body:
            for s in sel.split(','): safe_selectors.add(s.strip())
        if phone:
            for s in sel.split(','): phone_selectors.add(s.strip())
            if re.search(r'font-size:\s*16px', body):
                for s in sel.split(','):
                    coarse16.add((f, s.strip()))
                    if re.match(r'select(:not\([^)]*\))*$', s.strip()): covered_files.add(f)

for f, rules in all_rules.items():
    for sel, body, phone in rules:
        s1 = ' '.join(sel.split())[:70]
        if INPUT.search(sel) and not SKIPSEL.search(sel) and not phone and f not in covered_files \
           and not any((f, x.strip()) in coarse16 for x in sel.split(',')):
            m = FS.search(body)
            if m:
                px = float(m.group(1)) * (1 if m.group(2) == 'px' else 16)
                if px < 16: hits['M1 input below 16px'].append((f, f'{px:g}px  {s1}'))
        if re.search(r'position:\s*fixed', body) and re.search(r'(^|[;\s])bottom:\s*0', body) \
           and 'safe-area' not in body and 'env(' not in body and not any(s.strip() in safe_selectors for s in sel.split(',')):
            hits['M4 fixed bottom, no safe-area'].append((f, s1))
        m = COLS.search(body)
        if m and not phone and 'it-shell' not in sel:   # it-shell = the desktop navigation, not a phone surface
            tracks = re.sub(r'repeat\(\s*(\d+)\s*,[^)]*\)', lambda r: ' x' * int(r.group(1)), m.group(1))
            if len([t for t in re.split(r'\s+', tracks.strip()) if t]) >= 4 and not any(s.strip() in phone_selectors for s in sel.split(',')):
                hits['M6 4+ column grid, no phone layout'].append((f, s1))


show_all = '--all' in sys.argv
total = 0
for k in ['M1 input below 16px', 'M2 zoom locked', 'M4 fixed bottom, no safe-area', 'M6 4+ column grid, no phone layout']:
    h = hits.get(k, []); total += len(h)
    by = collections.Counter(f for f, _ in h)
    print(f'{k}: {len(h)} in {len(by)} files')
    for f, d in (h if show_all else h[:5]): print(f'    {f}  {d}')
print(f'total {total}')
print('M1 and M2 are firm. M4 and M6 are candidates: safe-area can come from an inner element or another selector, and a grid can collapse through a rule this scan cannot match — open each before fixing.')
sys.exit(1 if total and '--fail' in sys.argv else 0)
