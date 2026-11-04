// Ittai visual review — screenshot a screen at every device size and mode, plus a contact sheet. No installs (Chrome + Node).
// usage: node review-shots.mjs <out-dir> <url-or-file> [<url-or-file> ...] [--cookie=MOWOSESS-value] [--sizes=375x812,768x1024,1024x768,1440x900]
//        [--modes=light,dark,field] [--strict]   (--strict: exit 1 if any screen fails — for CI / pre-deploy)
// Checks per screenshot (design system v1.2.2): horizontal overflow · tap targets (48px <1024 wide, 44px desktop) · text under 12px · literal
// colours in the page's own CSS (tokens only) · RENDERED text contrast against the real
// background (AAA: 7:1, 4.5:1 large; disabled exempt; gradients/images reported as unknown) · unnamed icon-only controls · images without alt.
// Modes: light / dark set data-theme on <html> AND emulate prefers-color-scheme; field sets data-mode="field" + data-density="field".
// LOCAL TEST SITE / local files ONLY (refuses any host but localhost/127.0.0.1/file:) — synthetic data, never production.
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { resolve, basename } from 'node:path';

const args = process.argv.slice(2);
const opt = k => (args.find(a => a.startsWith(`--${k}=`)) || '').split('=').slice(1).join('=');
const pos = args.filter(a => !a.startsWith('--'));
const [outDir, ...targets] = pos;
if (!outDir || !targets.length) { console.error('usage: node review-shots.mjs <out-dir> <url-or-file>...'); process.exit(2); }
const sizes = (opt('sizes') || '375x812,768x1024,1024x768,1440x900').split(',').map(s => s.split('x').map(Number));
const modes = (opt('modes') || 'light,dark,field').split(',');
const cookie = opt('cookie');
const urls = targets.map(t => /^https?:|^file:/.test(t) ? t : 'file://' + resolve(t));
for (const u of urls) {
  const h = new URL(u);
  if (h.protocol !== 'file:' && !['localhost', '127.0.0.1'].includes(h.hostname)) { console.error('REFUSED (not local): ' + u); process.exit(3); }
}
mkdirSync(outDir, { recursive: true });

const port = 9400 + Math.floor(Math.random() * 500);
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ['--headless=new', '--disable-gpu', '--hide-scrollbars', `--remote-debugging-port=${port}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/rev')}`, 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ver; for (let i = 0; i < 60 && !ver; i++) { try { ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); } catch { await sleep(200); } }
const ws = new WebSocket(ver.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r);
let id = 0; const pend = new Map();
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pend.has(d.id)) { pend.get(d.id)(d); pend.delete(d.id); } };
const send = (method, params = {}, sessionId) => new Promise(r => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params, sessionId })); });
const { result: { targetId } } = await send('Target.createTarget', { url: 'about:blank' });
const { result: { sessionId: s } } = await send('Target.attachToTarget', { targetId, flatten: true });
await send('Page.enable', {}, s); await send('Runtime.enable', {}, s);
if (cookie) { await send('Network.enable', {}, s); await send('Network.setCookie', { name: 'MOWOSESS', value: cookie, domain: 'localhost', path: '/' }, s); }

const rows = [];
for (const url of urls) {
  const name = basename(new URL(url).pathname).replace(/\.\w+$/, '') || 'page';
  for (const [w, h] of sizes) for (const mode of modes) {
    await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 600 }, s);
    await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: mode === 'dark' ? 'dark' : 'light' }] }, s);
    await send('Page.navigate', { url }, s); await sleep(2500);
    await send('Runtime.evaluate', { expression: `(() => { const r = document.documentElement;
      r.setAttribute('data-theme', ${JSON.stringify(mode === 'dark' ? 'dark' : 'light')});
      if (${JSON.stringify(mode)} === 'field') { r.setAttribute('data-mode','field'); r.setAttribute('data-density','field'); } })()` }, s);
    await sleep(400);
    // Checks a reviewer needs: horizontal overflow, and tap targets under the size for this width.
    const { result: chk } = await send('Runtime.evaluate', { returnByValue: true, expression: `(() => {
      const minT = ${w} < 1024 ? 48 : 44, small = [];
      document.querySelectorAll('a,button,input,select,textarea,[role=button],label').forEach(el => {
        const b = el.getBoundingClientRect(); if (!b.width || !b.height) return;
        // A checkbox/radio inside a big enough label row is fine: the row is the target (COMPONENTS.md §4).
        const lab = el.closest('label'); if (lab && el !== lab && lab.getBoundingClientRect().height >= minT - 0.5) return;
        if (b.height < minT - 0.5 && b.width < 400) small.push(((el.innerText ?? el.textContent ?? "") || el.getAttribute('aria-label') || el.tagName).trim().slice(0, 30) + ' ' + Math.round(b.width) + 'x' + Math.round(b.height));
      });
      // ── Rendered contrast (WCAG 1.4.3): every visible element that holds its own text, against the background it actually sits on.
      // NB: this code sits in a template literal — backslashes would be eaten, so no regex escapes here (a bug caught by the negative control).
      const parse = c => { if (!c || !c.startsWith('rgb')) return null; const p = c.slice(c.indexOf('(') + 1, c.lastIndexOf(')')).split(/[ ,/]+/).filter(Boolean).map(Number);
                           return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
      const lin = v => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
      const L = c => 0.2126 * lin(c.r) + 0.7152 * lin(c.g) + 0.0722 * lin(c.b);
      const over = (top, under) => ({ r: top.r * top.a + under.r * (1 - top.a), g: top.g * top.a + under.g * (1 - top.a), b: top.b * top.a + under.b * (1 - top.a), a: 1 });
      const bgOf = el => { const stack = []; for (let n = el; n; n = n.parentElement) { const cs = getComputedStyle(n);
                             if (cs.backgroundImage && cs.backgroundImage !== 'none') return null;           // gradient/image: can't judge
                             const c = parse(cs.backgroundColor); if (c && c.a > 0) { stack.push(c); if (c.a >= 1) break; } }
                           let base = { r: 255, g: 255, b: 255, a: 1 }; for (let i = stack.length - 1; i >= 0; i--) base = over(stack[i], base); return base; };
      const lowText = []; let textChecked = 0, unknownBg = 0;
      document.querySelectorAll('body *').forEach(el => {
        const own = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim()); if (!own) return;
        if (el.closest('svg')) return;   // SVG text: fill, not color — judged by eye (v1.1: SVG links have no innerText)
        const cs = getComputedStyle(el); if (cs.visibility === 'hidden' || +cs.opacity === 0) return;
        const b = el.getBoundingClientRect(); if (!b.width || !b.height || b.bottom < 0 || b.top > innerHeight) return;
        const fg = parse(cs.color); const bg = bgOf(el); if (!fg) return; if (!bg) { unknownBg++; return; }
        const f = over(fg, bg), hi = Math.max(L(f), L(bg)), lo = Math.min(L(f), L(bg)), ratio = (hi + 0.05) / (lo + 0.05);
        const size = parseFloat(cs.fontSize), large = size >= 24 || (size >= 18.66 && +cs.fontWeight >= 700);
        const disabled = el.closest('[disabled],[aria-disabled="true"]');
        if (!isFinite(ratio)) { unknownBg++; return; }
        textChecked++; if (!disabled && ratio < (large ? 4.5 : 7)) lowText.push((el.innerText ?? el.textContent ?? "").trim().slice(0, 28) + ' ' + ratio.toFixed(2) + ':1');
      });
      // ── v1.2 rules: no text under 12px; no literal colours in the page's own CSS (tokens.css is the only place hex lives).
      const tiny = []; document.querySelectorAll('body *').forEach(el => {
        if (![...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) return;
        const cs = getComputedStyle(el), b = el.getBoundingClientRect(); if (cs.visibility === 'hidden' || !b.width || !b.height) return;
        if (parseFloat(cs.fontSize) < 12) tiny.push((el.innerText ?? el.textContent ?? '').trim().slice(0, 24) + ' ' + cs.fontSize);
      });
      const literal = []; for (const sh of document.styleSheets) { let rules; try { rules = sh.cssRules; } catch (e) { continue; }
        if ((sh.href || '').includes('tokens')) continue;
        for (const r of rules) { const t = r.cssText || ''; if (/(^|[^-\\w&])#[0-9a-fA-F]{3,8}\\b|rgba?\\(|hsla?\\(/.test(t.replace(/url\\([^)]*\\)/g, ''))) literal.push(t.slice(0, 60)); } }
      // ── Names (WCAG 4.1.2 / 1.1.1): icon-only controls need an accessible name; images need alt.
      const unnamed = [...document.querySelectorAll('button,a[href],[role=button]')].filter(el => { const b = el.getBoundingClientRect(); return b.width && !((el.innerText ?? el.textContent ?? "").trim() || el.getAttribute('aria-label') || el.getAttribute('title') || el.querySelector('img[alt]:not([alt=""])')); }).length;
      const noAlt = [...document.querySelectorAll('img')].filter(i => !i.hasAttribute('alt')).length;
      return { overflowX: document.documentElement.scrollWidth > window.innerWidth + 1, smallTargets: small.slice(0, 12), smallCount: small.length,
               textChecked, unknownBg, lowCount: lowText.length, lowText: lowText.slice(0, 10), unnamed, noAlt, tinyCount: tiny.length, tiny: tiny.slice(0, 8), literalCount: literal.length, literal: literal.slice(0, 5) };
    })()` }, s);
    const { result: shot } = await send('Page.captureScreenshot', { format: 'png' }, s);
    const file = `${name}-${w}x${h}-${mode}.png`;
    writeFileSync(`${outDir}/${file}`, Buffer.from(shot.data, 'base64'));
    const c = chk.result.value; rows.push({ name, w, h, mode, file, ...c });
    console.log(`${file}  overflowX=${c.overflowX}  targets<${w < 1024 ? 48 : 44}px: ${c.smallCount}  contrast fails: ${c.lowCount}/${c.textChecked}  unnamed controls: ${c.unnamed}  img without alt: ${c.noAlt}  text<12px: ${c.tinyCount}  literal colours: ${c.literalCount}`);
  }
}
const esc = t => String(t).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
writeFileSync(`${outDir}/contact-sheet.html`, `<!doctype html><meta charset="utf-8"><title>Review contact sheet</title>
<style>body{font:14px/1.4 system-ui;margin:24px;background:#f4f5f3;color:#131d17}h2{margin:28px 0 8px}.g{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start}
figure{margin:0;background:#fff;border:1px solid #d9dfda;padding:8px;max-width:100%}img{display:block;max-width:100%;height:auto;max-height:520px}
figcaption{margin-top:6px;font-size:12px}.bad{color:#a12c1f;font-weight:600}</style>
${[...new Set(rows.map(r => r.name))].map(n => `<h2>${esc(n)}</h2><div class="g">${rows.filter(r => r.name === n).map(r => `<figure><img src="${esc(r.file)}" alt="${esc(n)} ${r.w}x${r.h} ${r.mode}">
<figcaption>${r.w}×${r.h} · ${r.mode}${r.overflowX ? ' · <span class="bad">horizontal overflow</span>' : ''}${r.smallCount ? ` · <span class="bad">${r.smallCount} small targets</span>: ${esc(r.smallTargets.join('; '))}` : ''}${r.lowCount ? ` · <span class="bad">${r.lowCount} contrast fails</span>: ${esc(r.lowText.join('; '))}` : ''}${r.unnamed ? ` · <span class="bad">${r.unnamed} unnamed controls</span>` : ''}${r.noAlt ? ` · <span class="bad">${r.noAlt} images without alt</span>` : ''}${r.tinyCount ? ` · <span class="bad">${r.tinyCount} texts under 12px</span>: ${esc(r.tiny.join('; '))}` : ''}${r.literalCount ? ` · <span class="bad">${r.literalCount} literal colours</span>: ${esc(r.literal.join('; '))}` : ''}</figcaption></figure>`).join('')}</div>`).join('')}`);
writeFileSync(`${outDir}/report.json`, JSON.stringify(rows.map(({ file, ...r }) => ({ file, ...r })), null, 1));
const failures = rows.filter(r => r.overflowX || r.smallCount || r.lowCount || r.unnamed || r.noAlt || r.tinyCount || r.literalCount).length;
console.log('contact sheet: ' + outDir + '/contact-sheet.html  · report.json  · screens with failures: ' + failures + '/' + rows.length);
if (args.includes('--strict') && failures) { ws.close(); chrome.kill(); process.exit(1); }
ws.close(); chrome.kill();
