#!/usr/bin/env node
// Guardian page audit — measures what the RENDERED page actually does, with no model. Public pages only.
// Usage: node page-audit.mjs <out-dir> <targets.json>
// Needs playwright-core + axe-core installed in ~/.mowology-guardian/node_modules (scripts/guardian/install.sh) and Google Chrome.
// Writes <out-dir>/metrics.json and <out-dir>/shots/<page>-<width>.png. Every number is measured in the browser; nothing is estimated.
import { createRequire } from 'node:module';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const home = process.env.GUARDIAN_HOME || path.join(os.homedir(), '.mowology-guardian');
const require = createRequire(path.join(home, 'noop.js'));
const { chromium } = require('playwright-core');
const axeSource = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

const [outDir, targetsFile] = process.argv.slice(2);
const targets = JSON.parse(fs.readFileSync(targetsFile, 'utf8'));
fs.mkdirSync(path.join(outDir, 'shots'), { recursive: true });
const slug = (s) => s.replace(/^https?:\/\//, '').replace(/[^a-z0-9]+/gi, '-').replace(/-+$/, '').toLowerCase();

// Runs inside the page. Returns plain measurements.
function measure() {
  const vis = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
  const label = (el) => (el.getAttribute('aria-label') || el.textContent || el.getAttribute('title') || el.getAttribute('alt') || el.id || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 50);
  const small = [];
  for (const el of document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button], summary')) {
    if (!vis(el)) continue;
    const r = el.getBoundingClientRect();
    // inline text links inside a paragraph are exempt from the 44px target rule (WCAG 2.2 SC 2.5.8 inline exception)
    if (el.tagName === 'A' && getComputedStyle(el).display === 'inline') continue;
    if (r.width < 44 || r.height < 44) small.push({ what: label(el), w: Math.round(r.width), h: Math.round(r.height) });
  }
  const tiny = [];
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let n;
  while ((n = walker.nextNode())) {
    if (!n.nodeValue.trim()) continue;
    const el = n.parentElement; if (!el || !vis(el) || ['SCRIPT', 'STYLE', 'NOSCRIPT'].includes(el.tagName)) continue;
    const fs_ = parseFloat(getComputedStyle(el).fontSize);
    if (fs_ < 12) tiny.push({ text: n.nodeValue.trim().slice(0, 40), px: fs_ });
  }
  const unlabeled = [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea')].filter((e) => vis(e) && !e.labels?.length && !e.getAttribute('aria-label') && !e.getAttribute('aria-labelledby') && !e.getAttribute('title')).map((e) => e.name || e.id || e.type);
  const imgsNoAlt = [...document.images].filter((i) => vis(i) && !i.hasAttribute('alt')).map((i) => (i.currentSrc || i.src).slice(-60));
  const emptyLinks = [...document.querySelectorAll('a[href]')].filter((a) => vis(a) && !a.textContent.trim() && !a.getAttribute('aria-label') && !a.querySelector('img[alt]:not([alt=""])')).length;
  const nav = performance.getEntriesByType('navigation')[0];
  const bytes = performance.getEntriesByType('resource').reduce((s, r) => s + (r.transferSize || 0), 0) + (nav?.transferSize || 0);
  const meta = (n) => document.querySelector(`meta[name="${n}"]`)?.content || '';
  return {
    title: document.title, titleLen: document.title.length, description: meta('description'), descriptionLen: meta('description').length,
    canonical: document.querySelector('link[rel=canonical]')?.href || '', lang: document.documentElement.lang || '',
    viewportMeta: !!document.querySelector('meta[name=viewport]'), h1: document.querySelectorAll('h1').length,
    hOrderSkips: (() => { let prev = 0, skips = 0; for (const h of document.querySelectorAll('h1,h2,h3,h4,h5,h6')) { const l = +h.tagName[1]; if (prev && l > prev + 1) skips++; prev = l; } return skips; })(),
    overflowX: document.documentElement.scrollWidth > window.innerWidth + 1, scrollWidth: document.documentElement.scrollWidth, innerWidth: window.innerWidth,
    smallTargets: small.length, smallTargetSamples: small.slice(0, 6), tinyText: tiny.length, tinyTextSamples: tiny.slice(0, 4),
    unlabeledInputs: unlabeled.length, imagesNoAlt: imgsNoAlt.length, imagesNoAltSamples: imgsNoAlt.slice(0, 3), emptyLinks,
    transferKB: Math.round(bytes / 1024), domNodes: document.getElementsByTagName('*').length,
    ttfbMs: nav ? Math.round(nav.responseStart) : null, domContentLoadedMs: nav ? Math.round(nav.domContentLoadedEventEnd) : null, loadMs: nav ? Math.round(nav.loadEventEnd) : null,
  };
}

const vitals = () => new Promise((resolve) => {
  let lcp = null, cls = 0;
  new PerformanceObserver((l) => { for (const e of l.getEntries()) lcp = Math.round(e.startTime); }).observe({ type: 'largest-contentful-paint', buffered: true });
  new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) cls += e.value; }).observe({ type: 'layout-shift', buffered: true });
  setTimeout(() => resolve({ lcpMs: lcp, cls: Math.round(cls * 1000) / 1000 }), 1500);
});

const browser = await chromium.launch({ channel: 'chrome', headless: true });
const results = [];
for (const t of targets.web) {
  for (const [w, h] of targets.viewports) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2, bypassCSP: true, userAgent: 'IttaiGuardian/1 (read-only audit)' });
    const page = await ctx.newPage();
    const consoleErrors = [], failed = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 160)); });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + String(e).slice(0, 160)));
    page.on('response', (r) => { if (r.status() >= 400) failed.push(r.status() + ' ' + r.url().slice(0, 120)); });
    const rec = { page: t.name, url: t.url, width: w, ok: false };
    try {
      const resp = await page.goto(t.url, { waitUntil: 'networkidle', timeout: 45000 });
      rec.status = resp?.status();
      rec.measure = await page.evaluate(measure);
      Object.assign(rec.measure, await page.evaluate(vitals));
      await page.addScriptTag({ content: axeSource });
      const axe = await page.evaluate(async () => { const r = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'] } }); return r.violations.map((v) => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, sample: v.nodes[0]?.target?.join(' ').slice(0, 80) })); });
      rec.axe = axe;
      rec.consoleErrors = consoleErrors.slice(0, 5); rec.failedRequests = failed.slice(0, 8);
      const shot = path.join('shots', `${slug(t.url)}-${w}.png`);
      await page.screenshot({ path: path.join(outDir, shot), fullPage: false });
      rec.screenshot = shot; rec.ok = true;
      if (w === targets.viewports[0][0]) {          // internal links: checked once per page, at the first viewport
        const links = await page.evaluate(() => [...new Set([...document.querySelectorAll('a[href]')].map((a) => a.href).filter((u) => u.startsWith(location.origin) && !u.includes('#')))].slice(0, 30));
        rec.brokenLinks = [];
        for (const u of links) { try { const r = await ctx.request.get(u, { timeout: 15000, maxRedirects: 5 }); if (r.status() >= 400) rec.brokenLinks.push(r.status() + ' ' + u); } catch (e) { rec.brokenLinks.push('error ' + u); } }
        rec.linksChecked = links.length;
      }
    } catch (e) { rec.error = String(e).slice(0, 200); }
    results.push(rec);
    await ctx.close();
  }
}
// site-level files, once per origin
const site = {};
for (const origin of [...new Set(targets.web.map((t) => new URL(t.url).origin))]) {
  const ctx = await browser.newContext();
  const get = async (p) => { try { const r = await ctx.request.get(origin + p, { timeout: 15000 }); return { status: r.status(), body: r.status() < 400 ? (await r.text()).slice(0, 4000) : '' }; } catch (e) { return { status: 0, body: '' }; } };
  const robots = await get('/robots.txt'), sitemap = await get('/sitemap.xml');
  site[origin] = { robotsStatus: robots.status, robotsHasSitemap: /sitemap:/i.test(robots.body), robotsBlocksAll: /Disallow:\s*\/\s*$/im.test(robots.body), sitemapStatus: sitemap.status, sitemapUrls: (sitemap.body.match(/<loc>/g) || []).length };
  await ctx.close();
}
await browser.close();
fs.writeFileSync(path.join(outDir, 'metrics.json'), JSON.stringify({ at: new Date().toISOString(), tool: 'playwright-core + axe-core, system Chrome', results, site }, null, 1));
console.log(`audited ${results.length} page views; ${results.filter((r) => !r.ok).length} failed to load`);
