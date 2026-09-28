/* Marketing Studio — app logic. Vanilla JS, no build. */
(() => {
  const S = window.STUDIO;
  const $ = (id) => document.getElementById(id);
  const api = async (path, body) => {
    const r = await fetch(path, body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {});
    return r.json();
  };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const reduced = matchMedia('(prefers-reduced-motion: reduce)').matches;

  const state = { ctx: null, pieces: [], piece: null, step: 0, saveTimer: null, pollTimer: null };

  // ── boot ────────────────────────────────────────────────────────────────
  async function boot() {
    state.ctx = await api('/api/context');
    await loadPipeline();
    const last = localStorage.getItem('studio:lastPiece');
    const found = state.pieces.find((p) => p.id === last);
    if (found) openPiece(found); else newPiece();
    $('btnPipeline').onclick = () => toggleDrawer(true);
    $('btnCloseDrawer').onclick = () => toggleDrawer(false);
    $('scrim').onclick = () => toggleDrawer(false);
    $('btnNew').onclick = newPiece;
    $('btnBack').onclick = () => go(state.step - 1);
    $('btnNext').onclick = () => go(state.step + 1);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') toggleDrawer(false); });
  }

  async function loadPipeline() {
    const d = await api('/api/pipeline');
    state.pieces = (d.pieces || []).sort((a, b) => (b.updated || 0) - (a.updated || 0));
    $('pipelineCount').textContent = state.pieces.filter((p) => p.status !== 'shipped').length;
  }

  function newPiece() {
    state.piece = { title: '', type: '', typeLabel: '', skills: [], ship: '', status: 'brief', brief: { audience: '', awareness: '', action: '', keyword: '', proof: '', mustSay: '', mustNotSay: '', length: '', notes: '' }, checks: {}, ship_checks: {} };
    state.step = 0;
    render();
  }

  function openPiece(p) {
    state.piece = p;
    state.step = stepFor(p);
    localStorage.setItem('studio:lastPiece', p.id || '');
    render();
  }

  function stepFor(p) {
    return { brief: 1, with_claude: 3, 'with-claude': 3, review: 4, ship: 5, shipped: 5 }[p.status] ?? 0;
  }

  // ── persistence ─────────────────────────────────────────────────────────
  function save(immediate) {
    clearTimeout(state.saveTimer);
    const run = async () => {
      if (!state.piece.type) return;
      const d = await api('/api/pipeline', { piece: state.piece });
      // Merge only the server-assigned fields back; never replace the object,
      // or edits made while the request was in flight (and every closure
      // holding state.piece.brief) would be lost.
      if (d.piece) { const { id, slug, created, updated } = d.piece; Object.assign(state.piece, { id, slug, created, updated }); localStorage.setItem('studio:lastPiece', id); }
      await loadPipeline();
      $('savedNote').textContent = 'Saved ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      renderRailPiece();
    };
    if (immediate) return run();
    state.saveTimer = setTimeout(run, 500);
  }

  // ── navigation ──────────────────────────────────────────────────────────
  function go(n) {
    if (n < 0 || n > 5) return;
    if (n > state.step && !canLeave(state.step)) return;
    if (n >= 1 && !state.piece.type) { toast('Choose a piece type first.'); return; }
    state.step = n;
    if (n === 1 && state.piece.status === 'brief') save(true);
    render();
    $('canvas').focus({ preventScroll: true });
    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
  }

  function canLeave(step) {
    const b = state.piece.brief;
    if (step === 1 && !(b.audience && b.awareness && b.action)) { toast('Audience, awareness and the one action are the minimum.'); return false; }
    return true;
  }

  // ── render ──────────────────────────────────────────────────────────────
  function render() {
    clearInterval(state.pollTimer);
    const step = S.steps[state.step];
    $('stepEyebrow').textContent = `Step ${state.step + 1} of 6`;
    $('stepTitle').textContent = step.title;
    $('stepLede').textContent = step.lede;
    $('btnBack').disabled = state.step === 0;
    $('btnNext').textContent = state.step === 5 ? 'Done' : 'Continue';
    $('btnNext').hidden = state.step === 3 && state.piece.status !== 'review';
    renderRail();
    renderRailPiece();
    const body = $('stepBody');
    body.innerHTML = '';
    [renderChoose, renderBrief, renderVoice, renderClaude, renderReview, renderShip][state.step](body);
  }

  function renderRail() {
    const ol = $('railSteps');
    ol.innerHTML = '';
    const prog = document.createElement('div'); prog.className = 'rail__progress'; ol.appendChild(prog);
    S.steps.forEach((s, i) => {
      const li = document.createElement('li');
      li.className = 'rail__step' + (i < state.step ? ' is-done' : i === state.step ? ' is-current' : '');
      li.innerHTML = `<button type="button" aria-current="${i === state.step ? 'step' : 'false'}"><span class="rail__dot">${i < state.step ? '✓' : i + 1}</span><span class="rail__label">${esc(s.title)}</span></button>`;
      li.querySelector('button').onclick = () => { if (i <= state.step || state.piece.type) { state.step = i; render(); } };
      ol.appendChild(li);
    });
    requestAnimationFrame(() => {
      const items = ol.querySelectorAll('.rail__step');
      const cur = items[state.step];
      if (cur) prog.style.height = (cur.offsetTop + 16) + 'px';
    });
    countNumeral(state.step + 1);
  }

  function countNumeral(target) {
    const el = $('railNumeral');
    const from = parseInt(el.textContent, 10) || target;
    if (reduced || from === target) { el.textContent = target; return; }
    const dir = target > from ? 1 : -1; let n = from;
    const tick = () => { n += dir; el.textContent = n; if (n !== target) setTimeout(tick, 90); };
    setTimeout(tick, 60);
  }

  function renderRailPiece() {
    const p = state.piece; const el = $('railPiece');
    if (!p || !p.type) { el.innerHTML = '<span class="empty">No piece yet.</span>'; return; }
    el.innerHTML = `<strong>${esc(p.title || p.typeLabel)}</strong>${esc(p.typeLabel)} · ${esc(statusLabel(p.status))}`;
  }

  function statusLabel(s) { return { brief: 'Briefing', 'with-claude': 'With Claude', review: 'In review', ship: 'Ready to ship', shipped: 'Shipped' }[s] || s; }

  // Step 1 — choose
  function renderChoose(body) {
    const wrap = document.createElement('div'); wrap.className = 'choices';
    S.types.forEach((t) => {
      const b = document.createElement('button'); b.type = 'button'; b.className = 'choice'; b.setAttribute('aria-pressed', state.piece.type === t.id);
      b.innerHTML = `<span class="choice__label">${esc(t.label)}</span><span class="choice__blurb">${esc(t.blurb)}</span><span class="choice__meta">${esc(t.skills.join(' · '))}</span>`;
      b.onclick = () => { Object.assign(state.piece, { type: t.id, typeLabel: t.label, skills: t.skills, ship: t.ship }); if (!state.piece.brief.length) state.piece.brief.length = t.length; render(); save(); };
      wrap.appendChild(b);
    });
    body.appendChild(wrap);
    body.appendChild(field('Working title', 'title', state.piece.title, 'e.g. Fall cleanup follow-up for Kits townhouses', (v) => { state.piece.title = v; save(); }, 'Short and specific. It becomes the file name in the outbox.'));
  }

  // Step 2 — brief
  function renderBrief(body) {
    const b = state.piece.brief; const t = S.types.find((x) => x.id === state.piece.type) || {};
    body.appendChild(panel(`<h3>${esc(t.label)}</h3><p class="hint">${esc(t.blurb)} Ships to: ${esc(t.ship)}.</p>`));
    // audience
    const aud = document.createElement('div'); aud.className = 'field';
    aud.innerHTML = `<span class="field__label">Who is this for</span><div class="chips"></div><p class="hint" id="audNote"></p>`;
    S.audiences.forEach((a) => {
      const c = document.createElement('button'); c.type = 'button'; c.className = 'chip'; c.textContent = a.label; c.setAttribute('aria-pressed', b.audience === a.label);
      c.onclick = () => { b.audience = a.label; save(); renderBrief.refresh(); };
      aud.querySelector('.chips').appendChild(c);
    });
    const an = S.audiences.find((a) => a.label === b.audience); aud.querySelector('#audNote').textContent = an ? an.note : 'Pick one. A piece for everyone is a piece for no one.';
    body.appendChild(aud);
    // awareness
    const aw = document.createElement('div'); aw.className = 'field';
    aw.innerHTML = `<span class="field__label">What they already know when they arrive</span><div class="options"></div><p class="hint">The awareness stage picks the opening. The recommended lead type is shown on the right (Masterson &amp; Forde).</p>`;
    S.awareness.forEach((a) => {
      const o = document.createElement('button'); o.type = 'button'; o.className = 'option'; o.setAttribute('aria-pressed', b.awareness === a.label);
      o.innerHTML = `<span>${esc(a.label)}</span><span class="option__note">${esc(a.note)}</span><span class="option__lead">Lead: ${esc(a.lead)}</span>`;
      o.onclick = () => { b.awareness = a.label; save(); renderBrief.refresh(); };
      aw.querySelector('.options').appendChild(o);
    });
    body.appendChild(aw);
    body.appendChild(field('The one action', 'action', b.action, 'e.g. Book a free site walk before the AGM', (v) => { b.action = v; save(); }, 'One inevitable response. If two actions compete, the piece is two pieces.'));
    // keyword with hints
    const kw = field('Target query or topic', 'keyword', b.keyword, 'e.g. strata landscaping vancouver', (v) => { b.keyword = v; save(); }, S.keywordHintNote);
    const chips = document.createElement('div'); chips.className = 'chips';
    S.keywordHints.forEach((k) => { const c = document.createElement('button'); c.type = 'button'; c.className = 'chip'; c.textContent = k; c.setAttribute('aria-pressed', b.keyword === k); c.onclick = () => { b.keyword = k; save(); renderBrief.refresh(); }; chips.appendChild(c); });
    kw.appendChild(chips); body.appendChild(kw);
    // proof
    const pr = document.createElement('div'); pr.className = 'field';
    pr.innerHTML = `<span class="field__label">Proof we can use</span><div class="chips"></div><p class="hint">Only true, verifiable points. Anything not on this list needs the owner's confirmation before it goes in copy.</p>`;
    const chosen = new Set((b.proof || '').split(' | ').filter(Boolean));
    S.proofs.forEach((x) => { const c = document.createElement('button'); c.type = 'button'; c.className = 'chip'; c.textContent = x; c.setAttribute('aria-pressed', chosen.has(x)); c.onclick = () => { chosen.has(x) ? chosen.delete(x) : chosen.add(x); b.proof = [...chosen].join(' | '); save(); renderBrief.refresh(); }; pr.querySelector('.chips').appendChild(c); });
    body.appendChild(pr);
    const row = document.createElement('div'); row.className = 'row';
    row.appendChild(field('Must say', 'mustSay', b.mustSay, 'e.g. the snow plan is for strata and commercial only', (v) => { b.mustSay = v; save(); }));
    row.appendChild(field('Must not say', 'mustNotSay', b.mustNotSay, 'e.g. no prices; no "24/7"', (v) => { b.mustNotSay = v; save(); }));
    body.appendChild(row);
    body.appendChild(selectField('Length and format', b.length, S.lengths.concat(t.length && !S.lengths.includes(t.length) ? [t.length] : []), (v) => { b.length = v; save(); }));
    body.appendChild(field('Notes for Claude', 'notes', b.notes, 'What you saw on the site walk, the season, anything in the customer’s own words', (v) => { b.notes = v; save(); }, 'Real details become the most human part of the draft.', true));
  }
  renderBrief.refresh = () => { const body = $('stepBody'); const y = window.scrollY; body.innerHTML = ''; renderBrief(body); window.scrollTo(0, y); };

  // Step 3 — voice
  function renderVoice(body) {
    const c = state.ctx;
    body.appendChild(panel(`<h3>Brand voice card</h3><pre class="voice">${esc(c.voiceCard || 'No voice card found. Run the brand-voice extraction first.')}</pre>`, true));
    const aw = S.awareness.find((a) => a.label === state.piece.brief.awareness);
    if (aw) body.appendChild(panel(`<h3>Opening for this reader</h3><p>${esc(state.piece.brief.awareness)} → <strong>${esc(aw.lead)}</strong> lead. ${esc(aw.note)}</p>`));
    if (c.never && c.never.length) body.appendChild(panel(`<h3>Never</h3><ul class="never">${c.never.map((n) => `<li>${esc(n)}</li>`).join('')}</ul>`));
    const flags = document.createElement('div'); flags.className = 'panel';
    flags.innerHTML = `<h3>Compliance flags in this piece</h3><p class="hint">Tick anything the draft will contain. Two or more ticked means a careful read of the compliance checklist before it ships.</p><ul class="checks"></ul>`;
    const ul = flags.querySelector('ul'); state.piece.flags = state.piece.flags || {};
    S.compliance.forEach((f, i) => ul.appendChild(checkRow(f, !!state.piece.flags[i], (v) => { state.piece.flags[i] = v; save(); })));
    body.appendChild(flags);
    if (c.openQuestions && c.openQuestions.length) body.appendChild(panel(`<h3>Open questions still unanswered</h3><ul class="never" style="--x:0">${c.openQuestions.map((q) => `<li>${esc(q)}</li>`).join('')}</ul><p class="hint">Answer these in ${esc(c.contextPath)} and every skill picks them up.</p>`));
  }

  // Step 4 — hand to Claude
  function renderClaude(body) {
    const p = state.piece; const b = p.brief;
    body.appendChild(panel(`<h3>What Claude will get</h3><dl class="kv"><dt>Piece</dt><dd>${esc(p.typeLabel)} · ${esc(p.title || 'untitled')}</dd><dt>Skills</dt><dd>${esc(p.skills.join(', '))}</dd><dt>Audience</dt><dd>${esc(b.audience)} · ${esc(b.awareness)}</dd><dt>Action</dt><dd>${esc(b.action)}</dd><dt>Query</dt><dd>${esc(b.keyword || '—')}</dd><dt>Proof</dt><dd>${esc(b.proof || '—')}</dd><dt>Length</dt><dd>${esc(b.length || '—')}</dd></dl>`));
    const act = document.createElement('div'); act.className = 'panel panel--raised';
    const handed = p.status === 'with-claude' || p.status === 'review';
    act.innerHTML = `<h3>${handed ? 'Handed to Claude' : 'Send to Claude'}</h3>
      <p class="hint">Writes the brief to the outbox, copies the prompt to your clipboard and opens the Claude app. In a Code session for this repo, type <strong>/studio</strong> or paste the prompt. When the draft comes back, this page updates by itself.</p>
      <div class="actions" style="margin-top:16px"><button class="btn btn--primary" id="btnSend">${handed ? 'Send again' : 'Send to Claude'}</button><span class="status ${handed ? 'is-live' : ''}" id="handStatus">${handed ? 'Waiting for the draft…' : 'Not sent yet'}</span></div>
      <pre class="prompt" id="promptBox" ${handed ? '' : 'hidden'}></pre>`;
    body.appendChild(act);
    if (handed && p.briefPath) $('promptBox').textContent = `/studio ${p.slug}`;
    $('btnSend').onclick = async () => {
      await save(true);
      const d = await api('/api/handoff', { id: state.piece.id });
      if (d.error) { toast(d.error); return; }
      Object.assign(state.piece, { status: d.piece.status, handedOff: d.piece.handedOff, briefPath: d.piece.briefPath, slug: d.piece.slug }); $('promptBox').hidden = false; $('promptBox').textContent = d.prompt;
      $('handStatus').className = 'status is-live'; $('handStatus').textContent = (d.copied ? 'Prompt copied. ' : '') + (d.opened ? 'Claude opened. ' : '') + 'Waiting for the draft…';
      $('btnSend').textContent = 'Send again'; await loadPipeline(); renderRailPiece(); pollDraft();
    };
    if (handed) pollDraft();
  }

  function pollDraft() {
    clearInterval(state.pollTimer);
    const check = async () => {
      const d = await api('/api/draft?id=' + encodeURIComponent(state.piece.id));
      if (d.ready) { clearInterval(state.pollTimer); state.piece.status = 'review'; state.piece.draftPath = d.path; await save(true); toast('Draft is back from Claude.'); state.step = 4; render(); }
    };
    state.pollTimer = setInterval(check, 4000); check();
  }

  // Step 5 — review
  function renderReview(body) {
    const p = state.piece; const host = document.createElement('div'); host.className = 'draft'; host.innerHTML = '<p class="empty">No draft yet. Hand the piece to Claude first.</p>'; body.appendChild(host);
    api('/api/draft?id=' + encodeURIComponent(p.id)).then((d) => { if (d.ready) { host.innerHTML = md(d.markdown); const open = document.createElement('div'); open.className = 'actions'; open.style.marginTop = '16px'; open.innerHTML = `<button class="btn btn--quiet btn--sm" id="btnOpenDraft">Open the file</button><span class="status is-ok">${esc(d.path)}</span>`; host.appendChild(open); $('btnOpenDraft').onclick = () => api('/api/open', { path: d.path }); } });
    const rub = document.createElement('div'); rub.className = 'panel'; rub.innerHTML = '<h3>Review rubric</h3><ul class="checks"></ul>';
    const ul = rub.querySelector('ul'); p.checks = p.checks || {};
    S.reviewRubric.forEach((r, i) => ul.appendChild(checkRow(r, !!p.checks[i], (v) => { p.checks[i] = v; save(); })));
    body.appendChild(rub);
    const back = document.createElement('div'); back.className = 'panel';
    back.innerHTML = `<h3>Send it back with notes</h3><div class="field"><textarea id="revNotes" placeholder="What to change, in one or two lines each">${esc(p.revisionNotes || '')}</textarea></div><div class="actions" style="margin-top:12px"><button class="btn btn--quiet" id="btnRevise">Send back to Claude</button><span class="hint">Appends your notes to the brief and re-runs /studio. The draft is overwritten.</span></div>`;
    body.appendChild(back);
    $('revNotes').oninput = (e) => { p.revisionNotes = e.target.value; save(); };
    $('btnRevise').onclick = async () => { if (!p.revisionNotes) { toast('Write a note first.'); return; } await save(true); const d = await api('/api/handoff', { id: p.id, revision: true }); if (!d.error) { Object.assign(state.piece, { status: d.piece.status, handedOff: d.piece.handedOff, briefPath: d.piece.briefPath }); state.step = 3; render(); toast('Sent back. Type /studio ' + p.slug + ' in Claude.'); } };
  }

  // Step 6 — ship
  function renderShip(body) {
    const p = state.piece; const t = S.types.find((x) => x.id === p.type) || {};
    body.appendChild(panel(`<h3>Where it goes</h3><p>${esc(t.ship)}</p>${(t.links || []).length ? `<div class="links" style="margin-top:12px">${t.links.map((l) => `<a href="${esc(l.url)}" target="_blank" rel="noopener">${esc(l.label)} ↗</a>`).join('')}</div>` : ''}`));
    const list = document.createElement('div'); list.className = 'panel'; list.innerHTML = '<h3>Before you call it shipped</h3><ul class="checks"></ul>';
    const ul = list.querySelector('ul'); p.ship_checks = p.ship_checks || {};
    (t.shipList || []).forEach((r, i) => ul.appendChild(checkRow(r, !!p.ship_checks[i], (v) => { p.ship_checks[i] = v; save(); })));
    body.appendChild(list);
    const done = document.createElement('div'); done.className = 'panel panel--raised';
    done.innerHTML = `<h3>${p.status === 'shipped' ? 'Shipped' : 'Mark as shipped'}</h3><p class="hint">Moves it out of the active pipeline. Afterwards: watch Search Console for pages, reply rates for email, and add the result to the piece’s notes.</p><div class="actions" style="margin-top:12px"><button class="btn btn--primary" id="btnShip" ${p.status === 'shipped' ? 'disabled' : ''}>${p.status === 'shipped' ? 'Shipped ' + new Date((p.shippedAt || 0) * 1000).toLocaleDateString() : 'Mark shipped'}</button></div>`;
    body.appendChild(done);
    $('btnShip').onclick = async () => { p.status = 'shipped'; p.shippedAt = Math.floor(Date.now() / 1000); await save(true); render(); toast('Shipped. Nice.'); };
  }

  // ── drawer ──────────────────────────────────────────────────────────────
  function toggleDrawer(open) {
    $('drawer').hidden = !open; $('scrim').hidden = !open; $('btnPipeline').setAttribute('aria-expanded', open);
    if (open) renderDrawer();
  }
  function renderDrawer() {
    const host = $('drawerBody'); host.innerHTML = '';
    const groups = [['brief', 'Briefing'], ['with-claude', 'With Claude'], ['review', 'In review'], ['ship', 'Ready to ship'], ['shipped', 'Shipped']];
    let any = false;
    groups.forEach(([k, label]) => {
      const items = state.pieces.filter((p) => p.status === k);
      if (!items.length) return; any = true;
      const g = document.createElement('section'); g.className = 'group'; g.innerHTML = `<h3>${label} · ${items.length}</h3>`;
      items.forEach((p) => {
        const b = document.createElement('button'); b.type = 'button'; b.className = 'piece';
        b.innerHTML = `<span><span class="piece__title">${esc(p.title || p.typeLabel)}</span><br><span class="piece__meta">${esc(p.typeLabel)} · ${esc(p.brief?.audience || '')} · ${new Date((p.updated || 0) * 1000).toLocaleDateString()}</span></span><span class="piece__step">step ${stepFor(p) + 1}</span>`;
        b.onclick = () => { toggleDrawer(false); openPiece(p); };
        g.appendChild(b);
      });
      host.appendChild(g);
    });
    if (!any) host.innerHTML = '<p class="empty">Nothing in the pipeline yet. Start with a piece.</p>';
  }

  // ── small helpers ───────────────────────────────────────────────────────
  function field(label, key, value, placeholder, onInput, hint, multiline) {
    const w = document.createElement('div'); w.className = 'field';
    const id = 'f_' + key + '_' + Math.random().toString(36).slice(2, 7);
    w.innerHTML = `<label for="${id}">${esc(label)}</label>${multiline ? `<textarea id="${id}" placeholder="${esc(placeholder)}">${esc(value)}</textarea>` : `<input type="text" id="${id}" value="${esc(value)}" placeholder="${esc(placeholder)}">`}${hint ? `<p class="hint">${esc(hint)}</p>` : ''}`;
    w.querySelector('#' + id).oninput = (e) => onInput(e.target.value);
    return w;
  }
  function selectField(label, value, options, onChange) {
    const w = document.createElement('div'); w.className = 'field'; const id = 'sel_' + Math.random().toString(36).slice(2, 7);
    w.innerHTML = `<label for="${id}">${esc(label)}</label><select id="${id}"><option value="">Choose…</option>${options.map((o) => `<option ${o === value ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>`;
    w.querySelector('select').onchange = (e) => onChange(e.target.value); return w;
  }
  function panel(html, raised) { const d = document.createElement('div'); d.className = 'panel' + (raised ? ' panel--raised' : ''); d.innerHTML = html; return d; }
  function checkRow(label, checked, onChange) {
    const li = document.createElement('li'); li.className = 'check' + (checked ? ' is-checked' : '');
    li.innerHTML = `<input type="checkbox" ${checked ? 'checked' : ''}><span>${esc(label)}</span>`;
    const cb = li.querySelector('input'); cb.onchange = () => { li.classList.toggle('is-checked', cb.checked); onChange(cb.checked); }; li.onclick = (e) => { if (e.target !== cb) { cb.checked = !cb.checked; cb.onchange(); } };
    return li;
  }
  function md(src) { // small, safe markdown: headings, lists, paragraphs, bold
    const lines = esc(src).split('\n'); let out = '', inList = false;
    const inline = (s) => s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/(^|[^*])\*([^*\n]+?)\*(?!\*)/g, '$1<em>$2</em>').replace(/`(.+?)`/g, '<code>$1</code>');
    lines.forEach((l) => {
      const h = l.match(/^(#{1,3})\s+(.*)/); const li = l.match(/^\s*[-*]\s+(.*)/);
      if (li) { if (!inList) { out += '<ul>'; inList = true; } out += `<li>${inline(li[1])}</li>`; return; }
      if (inList) { out += '</ul>'; inList = false; }
      if (h) out += `<h${h[1].length}>${inline(h[2])}</h${h[1].length}>`; else if (l.trim()) out += `<p>${inline(l)}</p>`;
    });
    if (inList) out += '</ul>'; return out;
  }
  let toastTimer; function toast(msg) { const t = $('toast'); t.textContent = msg; t.hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.hidden = true; }, 2800); }

  boot();
})();
