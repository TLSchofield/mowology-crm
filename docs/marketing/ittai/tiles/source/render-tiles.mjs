import { spawn } from 'node:child_process';
import { writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
const [,, outDir, ...files] = process.argv;
const port = 9400 + Math.floor(Math.random() * 500);
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
  ['--headless=new', '--disable-gpu', '--hide-scrollbars', `--remote-debugging-port=${port}`, `--user-data-dir=${mkdtempSync(tmpdir() + '/tiles')}`, 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ver; for (let i = 0; i < 60 && !ver; i++) { try { ver = await (await fetch(`http://127.0.0.1:${port}/json/version`)).json(); } catch { await sleep(200); } }
const ws = new WebSocket(ver.webSocketDebuggerUrl); await new Promise(r => ws.onopen = r);
let id = 0; const pend = new Map();
ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pend.has(d.id)) { pend.get(d.id)(d); pend.delete(d.id); } };
const send = (method, params = {}, sessionId) => new Promise(r => { const i = ++id; pend.set(i, r); ws.send(JSON.stringify({ id: i, method, params, sessionId })); });
const { result: { targetId } } = await send('Target.createTarget', { url: 'about:blank' });
const { result: { sessionId: s } } = await send('Target.attachToTarget', { targetId, flatten: true });
await send('Page.enable', {}, s); await send('Runtime.enable', {}, s);
await send('Emulation.setDeviceMetricsOverride', { width: 1300, height: 4200, deviceScaleFactor: 1, mobile: false }, s);
for (const f of files) {
  const name = f.split('/').pop().replace(/\.html$/, '');
  for (const theme of ['light', 'navy']) {
    await send('Page.navigate', { url: 'file://' + f + (theme === 'navy' ? '?variant=navy' : '') }, s); await sleep(3000);
    await send('Runtime.evaluate', { expression: 'document.fonts.ready.then(()=>1)', awaitPromise: true }, s);
    const { result: { result: { value: boxes } } } = await send('Runtime.evaluate', { returnByValue: true, expression:
      `[...document.querySelectorAll('.artboard')].map(a=>{const b=a.getBoundingClientRect();return {x:b.x+scrollX,y:b.y+scrollY,w:b.width,h:b.height,story:b.height>b.width*1.3}})` }, s);
    let sq = 0;
    for (let i = 0; i < boxes.length; i++) {
      const b = boxes[i]; const kind = b.story ? 'story' : (boxes.filter(x=>!x.story).length > 1 ? 'slide-' + (++sq) : 'square'); const target = 1080;
      const scale = target / b.w;
      const { result: { data } } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x: b.x, y: b.y, width: b.w, height: b.h, scale } }, s);
      const out = `${outDir}/${name}-${kind}-${theme}.png`;
      writeFileSync(out, Buffer.from(data, 'base64')); console.log(out, Math.round(b.w * scale) + 'x' + Math.round(b.h * scale));
    }
  }
}
ws.close(); chrome.kill();
