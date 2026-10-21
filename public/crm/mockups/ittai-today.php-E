<?php
/**
 * Ittai — "Today with the office" (design pass 3, owner 2026-10-18: "get this onto production so I can
 * see how it feels"). A working MOCK: today's items as a fixed snapshot, real portraits, the real clicks.
 * Not wired to live data yet — Charlie's consequence ranking and the heads' live states come next.
 * Design source: the "Ittai Dashboard Top" canvas (Blend pass 3). Kanji role badges are a proposed v1.3
 * addition to the frozen Ittai v1.2 system, pending a native-speaker check.
 *
 * Login + settings.edit (same as the Design Mockups page): /crm/mockups/ is otherwise public, and this
 * shows the business's real figures. No client names.
 */
require_once __DIR__ . '/../../loginAuth/auth.php';
requireLogin();
requirePermission('settings.edit');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ittai — today with the office</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Zen+Kaku+Gothic+New:wght@500&display=swap" rel="stylesheet">
<style>
:root{--page:#F4F5F3;--surface:#FFFFFF;--sunk:#EEF1EE;--selected:#E8ECE9;--line:#D9DFDA;--text:#131D17;--meta:#56635B;
  --tenant:#1B5F3C;--on-tenant:#FFFFFF;--tenant-mark:#2D8659;--complete:#1B5F3C;--attention:#84530F;--progress:#2F5E8C}
*{box-sizing:border-box}
body{margin:0;background:var(--page);color:var(--text);font-family:Inter,system-ui,sans-serif;font-size:14px;line-height:20px;font-variant-numeric:tabular-nums}
button{font:inherit;cursor:pointer}
button:focus-visible{outline:2px solid var(--progress);outline-offset:2px}
.wrap{max-width:1440px;margin:0 auto;padding:40px 48px;display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap}
.office{flex:0 1 480px;min-width:0;background:var(--surface);border:1px solid var(--line);display:flex;flex-direction:column;align-items:center;padding:8px 8px 14px}
#hex{width:100%;height:auto;max-width:464px}
.legend{display:flex;flex-wrap:wrap;justify-content:center;gap:4px 16px;font-size:12px;line-height:16px;font-weight:500;color:var(--meta)}
.legend span{display:inline-flex;align-items:center;gap:6px}
.main{flex:1 1 560px;min-width:0;display:flex;flex-direction:column;gap:16px}
.eyebrow{font-size:12px;line-height:16px;font-weight:500;color:var(--meta)}
.h{font-size:18px;line-height:24px;font-weight:600}
.pickrow{display:flex;gap:24px;align-items:flex-end;flex-wrap:wrap}
.three{flex:1 1 420px;min-width:0;display:flex;flex-direction:column;gap:8px}
.tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.queue{flex:none;width:168px;display:flex;flex-direction:column;gap:8px;padding-left:24px;border-left:1px solid var(--line)}
.tab{text-align:left;min-height:60px;padding:8px 14px;border:1px solid var(--line);border-radius:10px;background:var(--surface);display:flex;flex-direction:column;gap:2px;color:var(--text)}
.tab[aria-pressed="true"]{background:var(--selected);border:2px solid var(--tenant);padding:7px 13px}
.tab b{font-weight:600}
.tab small{font-size:12px;line-height:16px;font-weight:500;color:var(--meta)}
.tab small.late{color:var(--attention)}
.card{background:var(--surface);border:1px solid var(--line);display:flex;flex-direction:column}
.card-top{padding:22px 28px 18px;display:flex;flex-direction:column;gap:6px}
.tag{display:inline-flex;align-items:center;gap:6px;font-size:12px;line-height:16px;font-weight:500;color:var(--attention)}
.anchor{display:flex;align-items:center;gap:20px;flex-wrap:wrap}
.amount{font-size:36px;line-height:44px;font-weight:600;letter-spacing:-.02em}
.primary{height:44px;padding:0 20px;border-radius:10px;border:0;background:var(--tenant);color:var(--on-tenant);font-weight:600;white-space:nowrap}
.ghost{height:44px;padding:0 12px;border:0;background:transparent;color:var(--meta);font-weight:500}
.why{font-size:13px;line-height:20px;color:var(--meta)}
.stages{list-style:none;margin:0;padding:18px 28px 20px;border-top:1px solid var(--line);display:grid;grid-template-columns:repeat(4,minmax(0,1fr));column-gap:24px}
.stage{display:flex;flex-direction:column;gap:6px}
.stage-top{display:flex;align-items:center}
.face{position:relative;width:48px;height:48px;flex:none}
.face img{position:absolute;left:6px;top:6px;width:36px;height:36px;border-radius:999px;object-fit:cover}
.kanji{position:absolute;right:-2px;bottom:-2px;width:20px;height:20px;border-radius:999px;background:var(--surface);border:1px solid var(--line);display:flex;align-items:center;justify-content:center;font-family:'Zen Kaku Gothic New',sans-serif;font-size:12px;font-weight:500;line-height:1}
.you{width:48px;height:48px;display:flex;align-items:center;justify-content:center;position:relative;flex:none}
.you span{width:36px;height:36px;border-radius:999px;background:var(--sunk);box-shadow:0 0 0 2px var(--attention);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600}
.you svg{position:absolute;inset:0}
.solid{flex:1;height:2px;background:var(--complete);margin-left:12px}
.dashed{flex:1;height:0;border-top:2px dashed var(--line);margin-left:12px}
.stage-title{font-size:16px;line-height:24px;font-weight:600}
.stage-who{font-size:13px;line-height:20px;color:var(--meta)}
.card-foot{padding:10px 28px;border-top:1px solid var(--line);display:flex;justify-content:flex-end}
.link{border:0;background:transparent;padding:0;min-height:24px;color:var(--tenant);font-weight:500;white-space:nowrap}
.note{font-size:13px;color:var(--meta)}
.mock{font-size:12px;color:var(--meta);text-align:center;padding:0 48px 24px}
/* Tablet and small laptop: the office spans the width above the decisions, never a 480 px column with a gap beside it */
@media (max-width:1100px){.office{flex-basis:100%}.main{flex-basis:100%;order:-1}}  /* decisions first, as on the phone Home (v1.2) */
/* Phone: hexagon labels would shrink below 12 px, so the names move into readable text under it */
.onthis{display:none;list-style:none;margin:8px 0 0;padding:0;width:100%;border-top:1px solid var(--line)}
.onthis li{display:flex;align-items:center;gap:10px;min-height:44px;border-bottom:1px solid var(--line);padding:0 8px}
.onthis img{width:28px;height:28px;border-radius:999px;object-fit:cover}
.onthis .k{font-family:'Zen Kaku Gothic New',sans-serif;font-size:13px;color:var(--meta)}
.onthis b{font-weight:600}
.onthis small{margin-left:auto;font-size:12px;font-weight:500}
@media (max-width:700px){.wrap{padding:20px 16px}.tabs{grid-template-columns:1fr}.queue{width:100%;padding-left:0;border-left:0}
  .pickrow{gap:16px}.stages{grid-template-columns:repeat(2,minmax(0,1fr));row-gap:16px;padding:16px}.card-top{padding:18px 16px 14px}.card-foot{padding:10px 16px}
  .amount{font-size:28px;line-height:36px}.anchor{gap:8px 16px}
  #hex .lbl{display:none}.onthis{display:block}.legend{margin-top:8px}}
</style>
</head>
<body>
<div class="wrap">
  <section class="office" aria-label="Who is on this">
    <svg id="hex" viewBox="-70 0 560 340" role="img" aria-label=""></svg>
    <ul class="onthis" id="onthis" aria-label="Who is on this"></ul>
    <div class="legend">
      <span><svg width="18" height="6" aria-hidden="true"><line x1="2" y1="3" x2="16" y2="3" stroke="#1B5F3C" stroke-width="3" stroke-linecap="round"/></svg>Their part is done</span>
      <span><svg width="18" height="6" aria-hidden="true"><line x1="2" y1="3" x2="16" y2="3" stroke="#84530F" stroke-width="3" stroke-linecap="round"/></svg>Waiting on you</span>
      <span><svg width="18" height="6" aria-hidden="true"><line x1="2" y1="3" x2="16" y2="3" stroke="#56635B" stroke-width="3" stroke-linecap="round" stroke-dasharray="0.1 5"/></svg>Acts next</span>
    </div>
  </section>

  <section class="main" aria-label="What needs you">
    <div class="pickrow">
      <div class="three">
        <div><div class="eyebrow">Charlie · ranked by what happens if it waits</div><div class="h">Three things need you today</div></div>
        <div class="tabs" role="group" aria-label="Today's three" id="tabs"></div>
      </div>
      <div class="queue">
        <div class="eyebrow">Up next in the queue</div>
        <button type="button" class="tab" id="queueTab" aria-pressed="false"><b>Receipts</b><small>57 ready · Penny</small></button>
      </div>
    </div>
    <article class="card" id="card" aria-live="polite"></article>
    <span class="note">20 more items are with the team and can wait.</span>
  </section>
</div>
<p class="mock">Design mock · today's items as a snapshot (18 Oct) · not live yet</p>

<script>
(function () {
  'use strict';
  var IMG = '/crm/img/heads/';
  var GREEN = '#1B5F3C', AMBER = '#84530F', META = '#56635B', LINE = '#D9DFDA', INK = '#131D17';
  var SEAT = {
    yui:   { name: 'Yui', role: 'Communications', kanji: '結', cx: 210, cy: 30,  tx: 240, ty: 26,  anchor: 'start', side: [167, 74.7, 253, 74.7] },
    sam:   { name: 'Sam', role: 'Sales',          kanji: '商', cx: 330, cy: 98,  tx: 360, ty: 94,  anchor: 'start', side: [271, 85.1, 314, 159.6] },
    penny: { name: 'Penny', role: 'Finance',      kanji: '計', cx: 330, cy: 242, tx: 360, ty: 238, anchor: 'start', side: [314, 180.4, 271, 254.9] },
    otto:  { name: 'Otto', role: 'Operations',    kanji: '運', cx: 210, cy: 310, tx: 240, ty: 306, anchor: 'start', side: [253, 265.3, 167, 265.3] },
    mia:   { name: 'Mia', role: 'Marketing',      kanji: '広', cx: 90,  cy: 242, tx: 60,  ty: 238, anchor: 'end',   side: [149, 254.9, 106, 180.4] },
    ada:   { name: 'Ada', role: 'Technology',     kanji: '技', cx: 90,  cy: 98,  tx: 60,  ty: 94,  anchor: 'end',   side: [106, 159.6, 149, 85.1] }
  };
  function done(k, title) { return { k: k, kind: 'done', title: title }; }
  function you(title) { return { kind: 'you', title: title }; }
  function next(k, title) { return { k: k, kind: 'next', title: title }; }
  var CARDS = [
    { label: 'Payroll', urgency: 'Late since Oct 15', late: true, amount: '[CRA AMOUNT]', title: 'Payroll remittance to CRA', action: 'Open the remittance',
      why: 'First: the penalty grows each day it waits.', more: 'What Penny has', lit: { penny: 'done' },
      stages: [done('penny', 'Ready'), you('Your payment'), next('penny', 'Recorded')] },
    { label: 'Overdue invoices', urgency: '$6,494 · 31 invoices', amount: '$6,494.44', title: '31 overdue invoices → reminders', action: 'Review 31 reminders',
      why: 'Money earned and not yet collected. One reminder each, PDF attached.', more: 'Show the 31', lit: { penny: 'done', yui: 'next' },
      stages: [done('penny', 'Drafted'), you('Your review'), next('yui', 'Sent')] },
    { label: 'Unbilled visits', urgency: '$1,521 · 27 visits', amount: '$1,521.47', title: '27 finished visits → invoices', action: 'Review 27 invoices',
      why: 'Work done and timed, not yet billed.', more: 'Show the 27', lit: { otto: 'done', penny: 'done', yui: 'next' },
      stages: [done('otto', 'Checked'), done('penny', 'Drafted'), you('Your review'), next('yui', 'Sent')] },
    { label: 'Receipts', urgency: '57 ready', amount: '57 receipts', title: 'Receipts read and coded → the books', action: 'Review 57 receipts',
      why: 'Next in the queue: the GST you can claim back waits on these.', more: 'Show the 57', lit: { penny: 'wait' },
      stages: [done('penny', 'Coded'), you('Your review'), next('penny', 'Booked')] }
  ];
  var at = 0;

  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function colorFor(st) { return st === 'done' ? GREEN : st === 'wait' ? AMBER : st === 'next' ? META : LINE; }

  function hex(card) {
    var h = '<defs><clipPath id="q-c"><circle cx="210" cy="170" r="26"/></clipPath>';
    Object.keys(SEAT).forEach(function (k) { var s = SEAT[k]; h += '<clipPath id="q-' + k + '"><circle cx="' + s.cx + '" cy="' + s.cy + '" r="18"/></clipPath>'; });
    h += '</defs><g stroke="' + LINE + '" stroke-width="1">' +
      '<line x1="210" y1="140" x2="210" y2="83"/><line x1="236" y1="155" x2="285" y2="127"/><line x1="236" y1="185" x2="285" y2="213"/>' +
      '<line x1="210" y1="200" x2="210" y2="257"/><line x1="184" y1="185" x2="135" y2="213"/><line x1="184" y1="155" x2="135" y2="127"/></g>';
    var names = [];
    Object.keys(SEAT).forEach(function (k) {
      var s = SEAT[k], st = card.lit[k], on = !!st, c = colorFor(st);
      h += '<line x1="' + s.side[0] + '" y1="' + s.side[1] + '" x2="' + s.side[2] + '" y2="' + s.side[3] + '" stroke="' + c + '" stroke-width="' + (on ? 5 : 1.5) + '"' +
           (st === 'next' ? ' stroke-dasharray="0.1 8"' : '') + ' stroke-linecap="round"/>';
      if (on) names.push(s.name + ' (' + s.role + ')');
    });
    h += '<image href="' + IMG + 'charlie.jpg" x="184" y="144" width="52" height="52" clip-path="url(#q-c)" preserveAspectRatio="xMidYMid slice"/>' +
         '<circle cx="210" cy="170" r="28" fill="none" stroke="#2F5E8C" stroke-width="1.5"/>' +
         '<circle cx="232" cy="192" r="10" fill="#fff" stroke="' + LINE + '"/>' +
         '<text x="232" y="196" text-anchor="middle" font-family="Zen Kaku Gothic New, sans-serif" font-size="12" font-weight="500" fill="' + INK + '">統</text>' +
         '<text class="lbl" x="210" y="222" text-anchor="middle" font-family="Inter, system-ui, sans-serif" font-size="12" font-weight="500" fill="' + META + '">Charlie · Chief of Staff</text>';
    Object.keys(SEAT).forEach(function (k) {
      var s = SEAT[k], st = card.lit[k], on = !!st, c = colorFor(st);
      h += '<image href="' + IMG + k + '.jpg" x="' + (s.cx - 18) + '" y="' + (s.cy - 18) + '" width="36" height="36" clip-path="url(#q-' + k + ')" preserveAspectRatio="xMidYMid slice"/>' +
           '<circle cx="' + s.cx + '" cy="' + s.cy + '" r="18.5" fill="none" stroke="' + (on ? c : LINE) + '" stroke-width="' + (on ? 2 : 1) + '"/>' +
           '<circle cx="' + (s.cx + 15) + '" cy="' + (s.cy + 15) + '" r="10" fill="#fff" stroke="' + LINE + '"/>' +
           '<text x="' + (s.cx + 15) + '" y="' + (s.cy + 19) + '" text-anchor="middle" font-family="Zen Kaku Gothic New, sans-serif" font-size="12" font-weight="500" fill="' + INK + '">' + s.kanji + '</text>' +
           '<text class="lbl" x="' + s.tx + '" y="' + s.ty + '" text-anchor="' + s.anchor + '" font-family="Inter, system-ui, sans-serif" font-size="14" font-weight="' + (on ? 600 : 500) + '" fill="' + INK + '">' + s.name + '</text>' +
           '<text class="lbl" x="' + s.tx + '" y="' + (s.ty + 16) + '" text-anchor="' + s.anchor + '" font-family="Inter, system-ui, sans-serif" font-size="12" font-weight="500" fill="' + (on ? c : META) + '">' + s.role + '</text>';
    });
    var list = '<li><img src="' + IMG + 'charlie.jpg" alt=""><span class="k">統</span><b>Charlie</b><span style="color:' + META + '">Chief of Staff</span></li>';
    Object.keys(SEAT).forEach(function (k) {
      var s = SEAT[k], st = card.lit[k]; if (!st) return;
      var word = st === 'done' ? 'done' : st === 'wait' ? 'waiting on you' : 'acts next';
      list += '<li><img src="' + IMG + k + '.jpg" alt=""><span class="k">' + s.kanji + '</span><b>' + s.name + '</b><span style="color:' + META + '">' + s.role + '</span><small style="color:' + colorFor(st) + '">' + word + '</small></li>';
    });
    document.getElementById('onthis').innerHTML = list;
    var svg = document.getElementById('hex');
    svg.innerHTML = h;
    svg.setAttribute('aria-label', 'Charlie, Chief of Staff, in the centre. On this: ' + names.join(', ') + '.');
  }

  function stage(st, isLast) {
    var line = st.kind === 'done' ? '<div class="solid" aria-hidden="true"></div>' : (isLast ? '' : '<div class="dashed" aria-hidden="true"></div>');
    var who, status, statusColor = st.kind === 'done' ? GREEN : st.kind === 'you' ? AMBER : META;
    var head;
    if (st.kind === 'you') {
      who = 'You'; status = 'now';
      head = '<div class="you"><svg width="48" height="48" viewBox="0 0 48 48" aria-hidden="true"><path d="M3 12V3h9" fill="none" stroke="#84530F" stroke-width="1.5"/><path d="M45 36v9h-9" fill="none" stroke="#84530F" stroke-width="1.5"/></svg><span>You</span></div>';
    } else {
      var s = SEAT[st.k]; who = s.name; status = st.kind === 'done' ? 'done' : 'next';
      var ring = st.kind === 'done' ? GREEN : LINE, op = st.kind === 'next' ? '.6' : '1';
      head = '<div class="face"><img src="' + IMG + st.k + '.jpg" alt="" style="box-shadow:0 0 0 2px ' + ring + ';opacity:' + op + '"><span class="kanji" aria-hidden="true">' + s.kanji + '</span></div>';
    }
    var muted = st.kind === 'next';
    return '<li class="stage"><div class="stage-top">' + head + line + '</div>' +
      '<div class="stage-title" style="color:' + (muted ? META : INK) + '">' + esc(st.title) + '</div>' +
      '<div class="stage-who"><span style="font-weight:500;color:' + (muted ? META : INK) + '">' + who + '</span> · <span style="color:' + statusColor + '">' + status + '</span></div></li>';
  }

  function render() {
    var card = CARDS[at];
    var tabs = document.getElementById('tabs');
    tabs.innerHTML = CARDS.slice(0, 3).map(function (c, i) {
      return '<button type="button" class="tab" data-i="' + i + '" aria-pressed="' + (i === at) + '"><b>' + esc(c.label) + '</b><small class="' + (c.late ? 'late' : '') + '">' + esc(c.urgency) + '</small></button>';
    }).join('');
    document.getElementById('queueTab').setAttribute('aria-pressed', String(at === 3));
    var stages = card.stages.map(function (st, i) { return stage(st, i === card.stages.length - 1); }).join('');
    document.getElementById('card').innerHTML =
      '<div class="card-top"><span class="tag"><svg width="14" height="14" viewBox="0 0 16 16" aria-hidden="true"><path d="M8 1.5 15 14.5H1z" fill="#84530F"/></svg>' + esc(card.title) + '</span>' +
      '<div class="anchor"><div class="amount">' + esc(card.amount) + '</div><button type="button" class="primary">' + esc(card.action) + '</button><button type="button" class="ghost">Not now</button></div>' +
      '<span class="why">' + esc(card.why) + '</span></div>' +
      '<ol class="stages" aria-label="Who does what">' + stages + '</ol>' +
      '<div class="card-foot"><button type="button" class="link">' + esc(card.more) + '</button></div>';
    hex(card);
  }

  document.getElementById('tabs').addEventListener('click', function (e) {
    var b = e.target.closest('[data-i]'); if (!b) return; at = parseInt(b.getAttribute('data-i'), 10); render();
  });
  document.getElementById('queueTab').addEventListener('click', function () { at = 3; render(); });
  render();
})();
</script>
</body>
</html>
