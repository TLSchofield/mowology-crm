<?php
require_once __DIR__ . '/../loginAuth/auth.php';
requireLogin();
$user = getCurrentUser();

$pageTitle = 'Card Layout Designer';
$activePage = 'settings';

ob_start();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:ital,wght@0,400;0,500;1,400&family=Syne:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
/* ═══════════════════════════════════════════
   CARD LAYOUT DESIGNER  ·  cdx-* namespace
   ═══════════════════════════════════════════ */
:root {
  --cdx-forest: #0D3B2E;
  --cdx-green:  #2D8659;
  --cdx-dark:   #1A5F4A;
  --cdx-lime:   #7FD858;
  --cdx-orange: #e85d04;
  --cdx-canvas: #E8E5DF;
  --cdx-topbar: 57px;
  --cdx-font-mono: 'DM Mono', monospace;
  --cdx-font-display: 'Syne', sans-serif;
}

.cdx-root {
  display: flex; flex-direction: column;
  height: calc(100vh - var(--cdx-topbar));
  margin: -24px -24px 0;
  background: var(--cdx-canvas); overflow: hidden;
  font-family: var(--cdx-font-display);
}

/* ── Action Bar ── */
.cdx-actionbar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 20px; height: 52px;
  background: #fff; border-bottom: 1px solid #E5E1DA;
  flex-shrink: 0; gap: 16px;
}
.cdx-tabs { display: flex; gap: 3px; background: #F2EEE8; border-radius: 9px; padding: 3px; }
.cdx-tab {
  font-family: var(--cdx-font-display); font-size: 13px; font-weight: 700;
  padding: 6px 18px; border: none; background: transparent; color: #888;
  border-radius: 7px; cursor: pointer; transition: all .15s; letter-spacing: 0.01em;
}
.cdx-tab.active { background: var(--cdx-green); color: #fff; }
.cdx-tab:hover:not(.active) { background: rgba(45,134,89,.12); color: var(--cdx-green); }
.cdx-page-label { font-family: var(--cdx-font-mono); font-size: 11px; color: #B0A99F; letter-spacing: 0.06em; }
.cdx-actions { display: flex; gap: 8px; align-items: center; }
.cdx-btn {
  font-family: var(--cdx-font-display); font-size: 12px; font-weight: 700;
  padding: 7px 15px; border-radius: 8px; cursor: pointer; border: 1.5px solid transparent;
  transition: all .15s; letter-spacing: 0.02em; line-height: 1;
}
.cdx-btn--ghost { background: transparent; border-color: #D5D0C8; color: #666; }
.cdx-btn--ghost:hover { border-color: var(--cdx-green); color: var(--cdx-green); }
.cdx-btn--outline { background: transparent; border-color: var(--cdx-green); color: var(--cdx-green); }
.cdx-btn--outline:hover { background: rgba(45,134,89,.08); }
.cdx-btn--primary { background: var(--cdx-green); border-color: var(--cdx-green); color: #fff; }
.cdx-btn--primary:hover { background: var(--cdx-dark); border-color: var(--cdx-dark); }

/* ── View Mode Bar (Stop Card only) ── */
.cdx-view-bar {
  display: flex; align-items: center;
  height: 40px; padding: 0 16px;
  background: #F7F5F1; border-bottom: 1px solid #E5E1DA;
  flex-shrink: 0; gap: 4px;
}
.cdx-view-bar.hidden { display: none; }
.cdx-view-tab {
  display: flex; align-items: center; gap: 6px;
  font-family: var(--cdx-font-display); font-size: 11px; font-weight: 700;
  padding: 4px 13px; border-radius: 7px; cursor: pointer;
  border: 1.5px solid transparent; background: transparent; color: #AAA;
  transition: all .15s; letter-spacing: 0.01em;
}
.cdx-view-tab:hover:not(.active) { background: rgba(45,134,89,.08); color: var(--cdx-green); }
.cdx-view-tab.active { background: #fff; border-color: #DDD8CF; color: #333; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
.cdx-view-tab[data-view="expanded"].active { border-color: #E8C87A; color: #8B6A0E; background: #FFFBEF; }
.cdx-view-dot { width: 7px; height: 7px; border-radius: 50%; background: #D0CCC5; transition: background .15s; }
.cdx-view-tab.active .cdx-view-dot { background: var(--cdx-green); }
.cdx-view-tab[data-view="expanded"].active .cdx-view-dot { background: #D4A843; }
.cdx-view-meta { margin-left: 10px; font-size: 10px; color: #C0BAB0; font-family: var(--cdx-font-mono); }

/* ── Three-Column Layout ── */
.cdx-layout { display: flex; flex: 1; overflow: hidden; }

/* ── LEFT: Palette ── */
.cdx-palette {
  width: 214px; background: var(--cdx-forest);
  display: flex; flex-direction: column; flex-shrink: 0; overflow: hidden;
}
.cdx-palette-hdr {
  padding: 14px 14px 10px; font-family: var(--cdx-font-mono);
  font-size: 9px; font-weight: 500; letter-spacing: 0.12em; text-transform: uppercase;
  color: rgba(127,216,88,.65); border-bottom: 1px solid rgba(255,255,255,.06); flex-shrink: 0;
}
.cdx-palette-list {
  flex: 1; overflow-y: auto; padding: 8px;
  display: flex; flex-direction: column; gap: 5px;
}
.cdx-palette-list::-webkit-scrollbar { width: 3px; }
.cdx-palette-list::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 2px; }
.cdx-tile {
  background: rgba(255,255,255,.06); border: 1.5px solid rgba(255,255,255,.09);
  border-radius: 9px; padding: 9px 11px; cursor: grab;
  display: flex; align-items: center; gap: 9px; user-select: none; transition: all .15s;
}
.cdx-tile:hover:not(.cdx-tile--on-card) { background: rgba(45,134,89,.28); border-color: var(--cdx-green); transform: translateX(3px); }
.cdx-tile:active:not(.cdx-tile--on-card) { cursor: grabbing; }
.cdx-tile--on-card { opacity: .38; cursor: default; border-style: dashed; }
.cdx-tile-ico { width: 26px; height: 26px; background: rgba(127,216,88,.13); border-radius: 7px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: var(--cdx-lime); }
.cdx-tile-ico svg { width: 13px; height: 13px; }
.cdx-tile-lbl { font-size: 12px; font-weight: 600; color: rgba(255,255,255,.88); line-height: 1.2; }
.cdx-tile-on { font-family: var(--cdx-font-mono); font-size: 9px; color: var(--cdx-lime); background: rgba(127,216,88,.14); border-radius: 3px; padding: 1px 5px; margin-top: 2px; display: inline-block; }

/* ── CENTER: Canvas ── */
.cdx-canvas {
  flex: 1; display: flex; align-items: flex-start; justify-content: center;
  background: var(--cdx-canvas);
  background-image: radial-gradient(circle, #C8C3BA 1px, transparent 1px);
  background-size: 22px 22px; overflow-y: auto; padding: 28px 20px 40px;
}

/* Phone Frame */
.cdx-phone {
  width: 362px; flex-shrink: 0; background: #1C1C1E; border-radius: 52px; padding: 12px;
  box-shadow: 0 0 0 1px rgba(255,255,255,.1), 0 24px 64px rgba(0,0,0,.45), 0 8px 24px rgba(0,0,0,.3), inset 0 1px 0 rgba(255,255,255,.14);
  position: relative;
}
.cdx-phone::before {
  content: ''; position: absolute; left: 50%; transform: translateX(-50%); top: 18px;
  width: 120px; height: 32px; background: #000; border-radius: 18px; z-index: 20;
}
.cdx-screen { background: #F6F4F0; border-radius: 42px; overflow: hidden; min-height: 560px; display: flex; flex-direction: column; }
.cdx-statusbar { height: 52px; display: flex; align-items: flex-end; padding: 0 22px 8px; justify-content: space-between; flex-shrink: 0; }
.cdx-status-time { font-family: var(--cdx-font-mono); font-size: 14px; font-weight: 500; color: #111; }
.cdx-status-icons { display: flex; gap: 4px; align-items: center; }
.cdx-screen-inner { padding: 0 12px 20px; flex: 1; overflow: hidden; }

/* Compact Ghost (non-editable preview shown above expanded drop zone) */
.cdx-compact-ghost {
  background: #FAFAF7; border-bottom: 1px dashed #E0DAD0;
  pointer-events: none; user-select: none; opacity: .5; filter: grayscale(15%); display: none;
}
.cdx-ghost-lbl {
  padding: 4px 14px 3px; font-family: var(--cdx-font-mono); font-size: 9px; color: #BBB;
  text-transform: uppercase; letter-spacing: 0.1em; background: #EDEAE5;
  border-bottom: 1px solid #E0DBCF; display: flex; align-items: center; gap: 5px;
}
.cdx-exp-sep {
  display: none; align-items: center; justify-content: center; gap: 6px;
  padding: 6px 14px; background: #FFF9EC; border-top: 1px solid #EDE4C8;
  font-size: 10px; font-family: var(--cdx-font-mono); color: #B09650;
}

/* Card Drop Zone */
.cdx-drop-zone {
  background: #fff; border-radius: 14px; box-shadow: 0 2px 16px rgba(0,0,0,.09);
  overflow: hidden; min-height: 100px; position: relative; transition: outline .12s;
}
.cdx-drop-zone.cdx-dz-active { outline: 2px dashed var(--cdx-green); outline-offset: 2px; }
.cdx-drop-zone.cdx-dz-expanded { border-radius: 0 0 14px 14px; }
.cdx-dz-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 120px; color: #C5BFB8; font-size: 12px; font-family: var(--cdx-font-mono); gap: 8px; }
.cdx-dz-empty svg { color: #D5CFC6; }

/* Drop indicator */
.cdx-drop-ind { height: 0; margin: 0 10px; transition: height .1s; overflow: hidden; }
.cdx-drop-ind.show { height: 3px; background: var(--cdx-green); border-radius: 2px; animation: cdx-blink .7s ease infinite; }
@keyframes cdx-blink { 0%,100%{opacity:1} 50%{opacity:.5} }

/* Card Element Wrapper */
.cdx-card-el {
  position: relative; cursor: grab; border-left: 3px solid transparent;
  transition: border-color .12s, background .12s; user-select: none;
}
.cdx-card-el:hover { border-left-color: rgba(45,134,89,.4); background: rgba(45,134,89,.02); }
.cdx-card-el.cdx-el-sel { border-left-color: var(--cdx-lime); background: rgba(127,216,88,.05); }
.cdx-card-el.cdx-dragging { opacity: .35; }
.cdx-card-el:active { cursor: grabbing; }
.cdx-el-rm {
  position: absolute; right: 7px; top: 50%; transform: translateY(-50%);
  width: 20px; height: 20px; background: rgba(232,93,4,.1); border: none; border-radius: 50%;
  color: var(--cdx-orange); cursor: pointer; display: none; align-items: center; justify-content: center;
  font-size: 14px; font-weight: 700; line-height: 1; z-index: 5; padding: 0;
}
.cdx-card-el:hover .cdx-el-rm { display: flex; }

/* ── RIGHT: Properties ── */
.cdx-props { width: 214px; background: #FAFAF8; border-left: 1px solid #E5E1DA; display: flex; flex-direction: column; flex-shrink: 0; overflow: hidden; }
.cdx-props-hdr { padding: 14px 14px 12px; border-bottom: 1px solid #EEE9E2; flex-shrink: 0; }
.cdx-props-ttl { font-family: var(--cdx-font-mono); font-size: 9px; font-weight: 500; letter-spacing: 0.12em; text-transform: uppercase; color: #AAA; }
.cdx-props-name { font-size: 14px; font-weight: 700; color: #1A1A1A; margin-top: 3px; }
.cdx-props-desc { font-size: 11px; color: #999; margin-top: 2px; }
.cdx-props-body { flex: 1; overflow-y: auto; padding: 14px; display: flex; flex-direction: column; gap: 14px; }
.cdx-props-body::-webkit-scrollbar { width: 3px; }
.cdx-props-body::-webkit-scrollbar-thumb { background: #DDD; border-radius: 2px; }
.cdx-props-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; color: #C5BFB8; font-size: 11px; font-family: var(--cdx-font-mono); text-align: center; gap: 10px; padding: 24px; line-height: 1.5; }
.cdx-prop-grp { display: flex; flex-direction: column; gap: 6px; }
.cdx-prop-lbl { font-size: 10px; font-weight: 700; color: #AAA; text-transform: uppercase; letter-spacing: 0.08em; font-family: var(--cdx-font-mono); }
.cdx-prop-toggle { display: flex; align-items: center; justify-content: space-between; padding: 9px 11px; background: #F2EEE8; border-radius: 8px; cursor: pointer; }
.cdx-tgl-lbl { font-size: 12px; font-weight: 600; color: #333; }
.cdx-tgl { width: 34px; height: 19px; background: #CCC; border-radius: 10px; position: relative; transition: background .18s; }
.cdx-tgl.on { background: var(--cdx-green); }
.cdx-tgl::after { content: ''; position: absolute; left: 2px; top: 2px; width: 15px; height: 15px; background: #fff; border-radius: 50%; transition: left .18s; box-shadow: 0 1px 3px rgba(0,0,0,.2); }
.cdx-tgl.on::after { left: 17px; }
.cdx-prop-inp { font-family: var(--cdx-font-display); font-size: 12px; padding: 8px 11px; border: 1.5px solid #E0DBCF; border-radius: 8px; width: 100%; box-sizing: border-box; outline: none; background: #fff; color: #1A1A1A; transition: border-color .15s; }
.cdx-prop-inp:focus { border-color: var(--cdx-green); }
.cdx-swatches { display: flex; gap: 6px; flex-wrap: wrap; }
.cdx-swatch { width: 26px; height: 26px; border-radius: 6px; cursor: pointer; border: 2.5px solid transparent; transition: transform .1s, border-color .1s; }
.cdx-swatch:hover { transform: scale(1.15); }
.cdx-swatch.sel { border-color: #1A1A1A; }

/* ══ Card Element Preview Styles ══ */
.cdx-p-time { padding: 12px 14px 10px; border-bottom: 1px solid #F2EEE8; }
.cdx-p-time-main { font-family: var(--cdx-font-mono); font-size: 19px; font-weight: 500; color: #111; letter-spacing: -.02em; }
.cdx-p-time-range { font-size: 10px; color: #999; margin-top: 1px; font-family: var(--cdx-font-mono); }
.cdx-p-client { padding: 7px 14px; display: flex; align-items: center; gap: 9px; }
.cdx-p-avatar { width: 30px; height: 30px; background: var(--cdx-green); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 11px; font-weight: 700; flex-shrink: 0; }
.cdx-p-client-name { font-size: 13px; font-weight: 700; color: #111; }
.cdx-p-client-sub { font-size: 10px; color: #999; }
.cdx-p-addr { padding: 5px 14px; display: flex; align-items: flex-start; gap: 5px; color: #666; font-size: 11px; line-height: 1.4; }
.cdx-p-addr svg { margin-top: 1px; flex-shrink: 0; color: var(--cdx-green); }
.cdx-p-pills { padding: 6px 14px; display: flex; gap: 5px; flex-wrap: wrap; }
.cdx-p-pill { display: inline-flex; align-items: center; gap: 3px; padding: 3px 9px; background: rgba(45,134,89,.1); color: var(--cdx-green); border-radius: 20px; font-size: 10px; font-weight: 700; border-left: 3px solid var(--cdx-green); }
.cdx-p-meta { padding: 5px 14px; display: flex; align-items: center; gap: 5px; color: #777; font-size: 11px; font-family: var(--cdx-font-mono); }
.cdx-p-meta svg { color: var(--cdx-green); }
.cdx-p-photos { padding: 7px 14px; display: flex; gap: 6px; }
.cdx-p-photo { width: 48px; height: 48px; background: #EEE9E2; border-radius: 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #C0BAB2; font-family: var(--cdx-font-mono); font-size: 8px; gap: 3px; }
.cdx-p-btns { padding: 7px 14px; display: flex; gap: 7px; }
.cdx-p-btn { flex: 1; padding: 9px 6px; border-radius: 9px; font-size: 11px; font-weight: 700; text-align: center; font-family: var(--cdx-font-display); }
.cdx-p-btn-g { background: var(--cdx-green); color: #fff; }
.cdx-p-btn-d { background: var(--cdx-forest); color: #fff; }
.cdx-p-btn-o { background: transparent; color: var(--cdx-green); border: 1.5px solid var(--cdx-green); padding: 7px 6px; }
.cdx-p-profit { padding: 7px 14px 9px; }
.cdx-p-profit-lbl { font-size: 9px; color: #BBB; font-family: var(--cdx-font-mono); margin-bottom: 4px; }
.cdx-p-profit-bar { height: 7px; background: #EEE9E2; border-radius: 4px; overflow: hidden; margin-bottom: 3px; }
.cdx-p-profit-fill { height: 100%; width: 68%; background: linear-gradient(90deg, var(--cdx-green), var(--cdx-lime)); border-radius: 4px; }
.cdx-p-profit-val { font-family: var(--cdx-font-mono); font-size: 11px; font-weight: 500; color: var(--cdx-green); }
.cdx-p-notes { padding: 7px 14px 9px; background: #FFFBF5; border-top: 1px solid #F2EEE8; }
.cdx-p-notes-lbl { font-size: 9px; color: #BBB; font-family: var(--cdx-font-mono); margin-bottom: 3px; }
.cdx-p-notes-txt { font-size: 11px; color: #BDB7AF; font-style: italic; }
/* Schedule card */
.cdx-p-slot { padding: 10px 14px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid #F2EEE8; }
.cdx-p-slot-dot { width: 9px; height: 9px; background: var(--cdx-green); border-radius: 50%; flex-shrink: 0; }
.cdx-p-slot-time { font-family: var(--cdx-font-mono); font-size: 16px; font-weight: 500; color: #111; }
.cdx-p-svc-badge { padding: 4px 14px; display: flex; gap: 5px; flex-wrap: wrap; }
.cdx-p-badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 11px; background: var(--cdx-green); color: #fff; border-radius: 20px; font-size: 10px; font-weight: 700; }
.cdx-p-badge-alt { background: var(--cdx-dark); }
.cdx-p-crew { padding: 5px 14px; display: flex; align-items: center; gap: 5px; }
.cdx-p-avatars { display: flex; }
.cdx-p-cav { width: 22px; height: 22px; border-radius: 50%; background: var(--cdx-dark); border: 2px solid #fff; margin-right: -5px; display: flex; align-items: center; justify-content: center; font-size: 8px; font-weight: 700; color: #fff; }
.cdx-p-cav:nth-child(2) { background: #3D6B55; }
.cdx-p-crew-name { margin-left: 12px; font-size: 11px; color: #666; }
.cdx-p-rev { padding: 5px 14px; display: flex; align-items: center; gap: 5px; }
.cdx-p-rev-amt { font-family: var(--cdx-font-mono); font-size: 17px; font-weight: 500; color: var(--cdx-green); }
.cdx-p-rev-lbl { font-size: 10px; color: #AAA; }
.cdx-p-status { padding: 4px 14px; }
.cdx-p-stat { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; background: rgba(45,134,89,.1); color: var(--cdx-green); border-radius: 6px; font-size: 10px; font-weight: 700; border: 1px solid rgba(45,134,89,.2); }

/* Toast */
.cdx-toast { position: fixed; bottom: 24px; right: 24px; background: var(--cdx-forest); color: #fff; font-family: var(--cdx-font-mono); font-size: 12px; padding: 10px 18px; border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.3); z-index: 9999; transform: translateY(80px); transition: transform .3s cubic-bezier(.34,1.56,.64,1); pointer-events: none; }
.cdx-toast.show { transform: translateY(0); }
</style>
<?php
$extraHead = ob_get_clean();
?>
<?php include 'includes/appstack_head.php'; ?>

<div class="cdx-root" id="cdxRoot">

  <!-- ── ACTION BAR ── -->
  <div class="cdx-actionbar">
    <div style="display:flex;align-items:center;gap:16px;">
      <div class="cdx-tabs">
        <button class="cdx-tab active" data-card="stop" onclick="switchCard('stop')">Stop Card</button>
        <button class="cdx-tab" data-card="schedule" onclick="switchCard('schedule')">Schedule Card</button>
      </div>
      <span class="cdx-page-label">card-designer · live preview</span>
    </div>
    <div class="cdx-actions">
      <button class="cdx-btn cdx-btn--ghost" onclick="resetLayout()">Reset Default</button>
      <button class="cdx-btn cdx-btn--outline" onclick="exportJSON()">Export JSON</button>
      <button class="cdx-btn cdx-btn--primary" onclick="saveLayout()">Save Layout</button>
    </div>
  </div>

  <!-- ── VIEW MODE BAR (Stop Card only) ── -->
  <div class="cdx-view-bar" id="cdxViewBar">
    <button class="cdx-view-tab active" data-view="compact" onclick="switchView('compact')">
      <span class="cdx-view-dot"></span> Compact View
    </button>
    <button class="cdx-view-tab" data-view="expanded" onclick="switchView('expanded')">
      <span class="cdx-view-dot"></span> Expanded View
    </button>
    <span class="cdx-view-meta" id="cdxViewMeta">Header — always visible at top of card</span>
  </div>

  <!-- ── MAIN LAYOUT ── -->
  <div class="cdx-layout">

    <!-- LEFT: Palette -->
    <aside class="cdx-palette">
      <div class="cdx-palette-hdr">Elements</div>
      <div class="cdx-palette-list" id="cdxPaletteList"></div>
    </aside>

    <!-- CENTER: Phone Canvas -->
    <div class="cdx-canvas">
      <div class="cdx-phone">
        <div class="cdx-screen">
          <div class="cdx-statusbar">
            <span class="cdx-status-time">9:41</span>
            <div class="cdx-status-icons">
              <svg width="16" height="11" viewBox="0 0 16 11" fill="#111"><rect x="0" y="3" width="3" height="8" rx="1"/><rect x="4" y="2" width="3" height="9" rx="1"/><rect x="8" y="0" width="3" height="11" rx="1"/><rect x="12" y="0" width="2" height="8" rx="1" opacity=".3"/></svg>
              <svg width="14" height="11" viewBox="0 0 14 11" fill="none" stroke="#111" stroke-width="1.4"><path d="M1 3.5C3.5 1 10.5 1 13 3.5M3.5 6C5 4.5 9 4.5 10.5 6M6 8.5C6.7 7.8 7.3 7.8 8 8.5"/><circle cx="7" cy="10" r="1" fill="#111" stroke="none"/></svg>
              <svg width="25" height="12" viewBox="0 0 25 12" fill="none"><rect x=".5" y=".5" width="21" height="11" rx="3.5" stroke="#111" stroke-opacity=".35"/><rect x="2" y="2" width="17" height="8" rx="2" fill="#111"/><path d="M23 4v4a2 2 0 0 0 0-4Z" fill="#111" fill-opacity=".4"/></svg>
            </div>
          </div>
          <div class="cdx-screen-inner">
            <!-- Compact ghost: greyed, non-editable preview of compact layout (visible in expanded mode) -->
            <div class="cdx-compact-ghost" id="cdxCompactGhost">
              <div class="cdx-ghost-lbl">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                Compact header · always visible
              </div>
              <div id="cdxCompactGhostContent"></div>
            </div>
            <!-- Expanded separator -->
            <div class="cdx-exp-sep" id="cdxExpSep">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
              Tap expanded · designing below
            </div>
            <!-- Main editable drop zone -->
            <div class="cdx-drop-zone" id="cdxDropZone"
                 ondragover="onDZDragOver(event)"
                 ondragleave="onDZDragLeave(event)"
                 ondrop="onDZDrop(event)">
              <div class="cdx-dz-empty" id="cdxDZEmpty">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M3 9h18M9 21V9"/></svg>
                Drop elements from the palette
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT: Properties Panel -->
    <aside class="cdx-props">
      <div id="cdxPropsContent">
        <div class="cdx-props-empty" style="height:calc(100vh - 150px);display:flex">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="3"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>
          Select an element on the card to edit properties
        </div>
      </div>
    </aside>

  </div>
</div>

<div class="cdx-toast" id="cdxToast"></div>

<script>
/* ════════════════════════════════════════════════
   MOWOLOGY CARD LAYOUT DESIGNER  v2
   ════════════════════════════════════════════════ */

// ── SVG Icon Library ──
const IC = {
  clock:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>`,
  user:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>`,
  pin:     `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>`,
  tag:     `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>`,
  expand:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>`,
  cal:     `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`,
  cam:     `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>`,
  login:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>`,
  check:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 15.01 9 12.01"/></svg>`,
  nav:     `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>`,
  trend:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>`,
  file:    `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>`,
  users:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
  dollar:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>`,
  pulse:   `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>`,
  cursor:  `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l7.07 16.97 2.51-7.39 7.39-2.51L3 3z"/></svg>`,
};

// ── Card Definitions ──
// Stop Card has two independent views: compact (header) and expanded (detail on tap)
const CARD_DEFS = {
  stop: {
    label: 'Stop Card',
    hasViews: true,
    views: {
      compact:  { label: 'Compact View',  desc: 'Header — always visible at top of card',   defaults: ['stop-time','client-name','service-pills','duration'] },
      expanded: { label: 'Expanded View', desc: 'Detail — shown after tapping the card',     defaults: ['address','sq-ft','last-visit','photos','clock-in','complete-job'] },
    },
    elements: {
      'stop-time':     { label:'Stop Time',          icon:'clock',  desc:'Scheduled time window',        colorable:false },
      'client-name':   { label:'Client Name',         icon:'user',   desc:'Property & client info',       colorable:false },
      'address':       { label:'Address',             icon:'pin',    desc:'Service address',              colorable:false },
      'service-pills': { label:'Service Pills Row',   icon:'tag',    desc:'Service type badges (min 2)',  colorable:true  },
      'duration':      { label:'Duration',            icon:'clock',  desc:'Estimated job time',           colorable:false },
      'sq-ft':         { label:'Sq Ft',               icon:'expand', desc:'Property square footage',      colorable:false },
      'last-visit':    { label:'Last Visit',          icon:'cal',    desc:'Previous service date',        colorable:false },
      'photos':        { label:'Photo Placeholders',  icon:'cam',    desc:'Before / After / Extra slots', colorable:false },
      'clock-in':      { label:'Clock In Button',     icon:'login',  desc:'Start job timer',              colorable:true  },
      'complete-job':  { label:'Complete Job Button', icon:'check',  desc:'Mark job complete',            colorable:false },
      'optimize-route':{ label:'Optimize Route',      icon:'nav',    desc:'Route optimization CTA',       colorable:false },
      'profit-bar':    { label:'Profit Bar',          icon:'trend',  desc:'Job profitability indicator',  colorable:false },
      'notes':         { label:'Notes',               icon:'file',   desc:'Site notes field',             colorable:false },
    }
  },
  schedule: {
    label: 'Schedule Card',
    hasViews: false,
    defaults: ['time-slot','client-name','service-badge','duration','assigned-crew'],
    elements: {
      'time-slot':     { label:'Time Slot',     icon:'clock',  desc:'Scheduled appointment time',   colorable:false },
      'client-name':   { label:'Client Name',   icon:'user',   desc:'Client & property name',       colorable:false },
      'service-badge': { label:'Service Badges',icon:'tag',    desc:'Service type chips (min 2)',    colorable:true  },
      'duration':      { label:'Duration',      icon:'clock',  desc:'Estimated job duration',        colorable:false },
      'assigned-crew': { label:'Assigned Crew', icon:'users',  desc:'Crew member avatars',           colorable:false },
      'revenue':       { label:'Revenue',       icon:'dollar', desc:'Job revenue amount',            colorable:false },
      'status-badge':  { label:'Status Badge',  icon:'pulse',  desc:'Job status indicator',          colorable:true  },
      'address':       { label:'Address',       icon:'pin',    desc:'Service address line',          colorable:false },
    }
  }
};

const COLOR_SWATCHES = [
  { hex:'#2D8659', name:'Mowology Green' }, { hex:'#1A5F4A', name:'Dark Green' },
  { hex:'#7FD858', name:'Lime' },           { hex:'#0D3B2E', name:'Forest' },
  { hex:'#e85d04', name:'Orange' },         { hex:'#3B82F6', name:'Blue' },
  { hex:'#8B5CF6', name:'Purple' },         { hex:'#EC4899', name:'Pink' },
];

// ── App State ──
const state = {
  activeCard: 'stop',
  viewMode:   'compact',   // 'compact' | 'expanded'  (stop card only)
  layouts: {
    stop: {
      compact:  [...CARD_DEFS.stop.views.compact.defaults],
      expanded: [...CARD_DEFS.stop.views.expanded.defaults],
    },
    schedule: [...CARD_DEFS.schedule.defaults],
  },
  selectedElem: null,
  props: { stop: {}, schedule: {} },
  drag: { source: null, elemId: null, dropIndex: null },
};

// ── Layout Helpers ──
function getLayout() {
  if (state.activeCard === 'stop') return state.layouts.stop[state.viewMode];
  return state.layouts.schedule;
}
function setLayout(arr) {
  if (state.activeCard === 'stop') state.layouts.stop[state.viewMode] = arr;
  else state.layouts.schedule = arr;
}

// ── Render Engine ──
function render() {
  renderPalette();
  renderCard();
  renderProps();
  updateViewUI();
}

function updateViewUI() {
  const bar  = document.getElementById('cdxViewBar');
  const meta = document.getElementById('cdxViewMeta');
  if (state.activeCard === 'stop') {
    bar.classList.remove('hidden');
    document.querySelectorAll('.cdx-view-tab').forEach(t => t.classList.toggle('active', t.dataset.view === state.viewMode));
    const vd = CARD_DEFS.stop.views[state.viewMode];
    if (meta) meta.textContent = vd.desc;
  } else {
    bar.classList.add('hidden');
  }
  // Ghost + separator
  const ghost = document.getElementById('cdxCompactGhost');
  const sep   = document.getElementById('cdxExpSep');
  const dz    = document.getElementById('cdxDropZone');
  const isExp = state.activeCard === 'stop' && state.viewMode === 'expanded';
  if (ghost) ghost.style.display  = isExp ? 'block' : 'none';
  if (sep)   sep.style.display    = isExp ? 'flex'  : 'none';
  if (dz)    dz.classList.toggle('cdx-dz-expanded', isExp);
  if (isExp) renderCompactGhost();
}

function renderCompactGhost() {
  const content = document.getElementById('cdxCompactGhostContent');
  if (!content) return;
  const defs = CARD_DEFS.stop.elements;
  let html = '';
  state.layouts.stop.compact.forEach(id => {
    const def = defs[id];
    if (def) html += getStopPreview(id);
  });
  content.innerHTML = html || '<div class="cdx-p-meta" style="color:#CCC;font-style:italic">No compact elements set</div>';
}

function renderPalette() {
  const defs   = CARD_DEFS[state.activeCard].elements;
  const onCard = new Set(getLayout());
  let html = '';
  for (const [id, def] of Object.entries(defs)) {
    const on = onCard.has(id);
    html += `<div class="cdx-tile${on?' cdx-tile--on-card':''}"
      draggable="${on?'false':'true'}"
      data-elem-id="${id}">
      <div class="cdx-tile-ico">${IC[def.icon]||''}</div>
      <div style="flex:1;min-width:0">
        <div class="cdx-tile-lbl">${def.label}</div>
        ${on?'<div class="cdx-tile-on">on card</div>':''}
      </div>
    </div>`;
  }
  const list = document.getElementById('cdxPaletteList');
  list.innerHTML = html;
  list.querySelectorAll('.cdx-tile:not(.cdx-tile--on-card)').forEach(t => {
    t.addEventListener('dragstart', e => {
      state.drag = { source:'palette', elemId:t.dataset.elemId, dropIndex:null };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', t.dataset.elemId);
      const g = t.cloneNode(true);
      g.style.cssText = 'position:fixed;top:-200px;left:-200px;width:190px;opacity:.9;border-radius:9px;background:#1A5F4A;border:1.5px solid #7FD858;padding:9px 11px;display:flex;align-items:center;gap:9px;';
      document.body.appendChild(g);
      e.dataTransfer.setDragImage(g, 95, 24);
      setTimeout(()=>g.remove(),0);
    });
  });
}

function renderCard() {
  const layout = getLayout();
  const defs   = CARD_DEFS[state.activeCard].elements;
  const dz     = document.getElementById('cdxDropZone');
  const empty  = document.getElementById('cdxDZEmpty');
  if (empty) empty.style.display = layout.length ? 'none' : 'flex';

  dz.querySelectorAll('.cdx-card-el, .cdx-drop-ind').forEach(el => el.remove());

  layout.forEach((id, i) => {
    const def = defs[id];
    if (!def) return;
    const ind = document.createElement('div');
    ind.className = 'cdx-drop-ind'; ind.dataset.idx = i;
    dz.appendChild(ind);

    const wrap = document.createElement('div');
    wrap.className = 'cdx-card-el' + (state.selectedElem===id ? ' cdx-el-sel' : '');
    wrap.dataset.elemId = id;
    wrap.draggable = true;
    wrap.innerHTML = getPreview(state.activeCard, id) + `<button class="cdx-el-rm" title="Remove">×</button>`;
    wrap.addEventListener('dragstart', e => {
      state.drag = { source:'card', elemId:id, dropIndex:null };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', id);
      setTimeout(() => wrap.classList.add('cdx-dragging'), 0);
    });
    wrap.addEventListener('dragend', () => wrap.classList.remove('cdx-dragging'));
    wrap.addEventListener('click', e => {
      if (e.target.closest('.cdx-el-rm')) { removeElem(id); }
      else { state.selectedElem = (state.selectedElem===id) ? null : id; render(); }
    });
    dz.appendChild(wrap);
  });

  const last = document.createElement('div');
  last.className = 'cdx-drop-ind'; last.dataset.idx = layout.length;
  dz.appendChild(last);
}

function renderProps() {
  const panel = document.getElementById('cdxPropsContent');
  if (!state.selectedElem) {
    panel.innerHTML = `<div class="cdx-props-empty" style="height:calc(100vh - 150px);display:flex">
      ${IC.cursor}<span>Click an element on the card to edit its properties</span></div>`;
    return;
  }
  const def = CARD_DEFS[state.activeCard].elements[state.selectedElem];
  const p   = state.props[state.activeCard][state.selectedElem] || {};
  const vis = p.visible !== false;
  const col = p.color || '#2D8659';
  panel.innerHTML = `
    <div class="cdx-props-hdr">
      <div class="cdx-props-ttl">Properties</div>
      <div class="cdx-props-name">${def.label}</div>
      <div class="cdx-props-desc">${def.desc}</div>
    </div>
    <div class="cdx-props-body">
      <div class="cdx-prop-grp">
        <div class="cdx-prop-lbl">Visibility</div>
        <div class="cdx-prop-toggle" onclick="toggleVis()">
          <span class="cdx-tgl-lbl">Show element</span>
          <div class="cdx-tgl${vis?' on':''}"></div>
        </div>
      </div>
      <div class="cdx-prop-grp">
        <div class="cdx-prop-lbl">Custom Label</div>
        <input class="cdx-prop-inp" type="text" placeholder="${def.label}"
               value="${p.label||''}" oninput="updateLabel(this.value)">
      </div>
      ${def.colorable ? `
      <div class="cdx-prop-grp">
        <div class="cdx-prop-lbl">Colour Accent</div>
        <div class="cdx-swatches">
          ${COLOR_SWATCHES.map(c=>`<div class="cdx-swatch${col===c.hex?' sel':''}"
            style="background:${c.hex}" title="${c.name}"
            onclick="updateColor('${c.hex}')"></div>`).join('')}
        </div>
      </div>` : ''}
      <div class="cdx-prop-grp">
        <button class="cdx-btn cdx-btn--ghost" style="width:100%"
                onclick="removeElem('${state.selectedElem}')">Remove from card</button>
      </div>
    </div>`;
}

// ── Element Previews ──
function getPreview(cardType, id) {
  return cardType === 'stop' ? getStopPreview(id) : getSchedulePreview(id);
}

function getStopPreview(id) {
  switch(id) {
    case 'stop-time': return `<div class="cdx-p-time">
      <div class="cdx-p-time-main">9:00 AM</div>
      <div class="cdx-p-time-range">9:00 AM — 10:30 AM · Stop 3 of 8</div></div>`;
    case 'client-name': return `<div class="cdx-p-client">
      <div class="cdx-p-avatar">JR</div>
      <div><div class="cdx-p-client-name">Johnson Residence</div>
      <div class="cdx-p-client-sub">Sarah Johnson · Returning</div></div></div>`;
    case 'address': return `<div class="cdx-p-addr">
      <div style="width:12px;height:12px;flex-shrink:0;margin-top:1px">${IC.pin}</div>
      1234 Oak Street, Vancouver BC</div>`;
    case 'service-pills': return `<div class="cdx-p-pills">
      <span class="cdx-p-pill">🌿 Lawn Mowing</span>
      <span class="cdx-p-pill" style="border-left-color:#e85d04;color:#e85d04;background:rgba(232,93,4,.08)">✂️ Hedge Trim</span></div>`;
    case 'duration': return `<div class="cdx-p-meta">
      <div style="width:12px;height:12px">${IC.clock}</div> ~90 min</div>`;
    case 'sq-ft': return `<div class="cdx-p-meta">
      <div style="width:12px;height:12px">${IC.expand}</div> 2,400 sq ft</div>`;
    case 'last-visit': return `<div class="cdx-p-meta">
      <div style="width:12px;height:12px">${IC.cal}</div> Last visit: Mar 1, 2026</div>`;
    case 'photos': return `<div class="cdx-p-photos">
      <div class="cdx-p-photo"><div style="width:16px;height:16px">${IC.cam}</div>Before</div>
      <div class="cdx-p-photo"><div style="width:16px;height:16px">${IC.cam}</div>After</div>
      <div class="cdx-p-photo" style="border:1.5px dashed #CCC7BE;background:transparent">+</div></div>`;
    case 'clock-in': return `<div class="cdx-p-btns">
      <div class="cdx-p-btn cdx-p-btn-g">Clock In</div></div>`;
    case 'complete-job': return `<div class="cdx-p-btns">
      <div class="cdx-p-btn cdx-p-btn-d">Complete Job</div></div>`;
    case 'optimize-route': return `<div class="cdx-p-btns">
      <div class="cdx-p-btn cdx-p-btn-o">Optimize Route</div></div>`;
    case 'profit-bar': return `<div class="cdx-p-profit">
      <div class="cdx-p-profit-lbl">Job Profitability</div>
      <div class="cdx-p-profit-bar"><div class="cdx-p-profit-fill"></div></div>
      <div class="cdx-p-profit-val">$87 net · 68% margin</div></div>`;
    case 'notes': return `<div class="cdx-p-notes">
      <div class="cdx-p-notes-lbl">Site Notes</div>
      <div class="cdx-p-notes-txt">Side gate code: 4821. Watch for dog on left yard.</div></div>`;
    default: return `<div class="cdx-p-meta">${id}</div>`;
  }
}

function getSchedulePreview(id) {
  switch(id) {
    case 'time-slot': return `<div class="cdx-p-slot">
      <div class="cdx-p-slot-dot"></div>
      <div class="cdx-p-slot-time">9:00 AM</div></div>`;
    case 'client-name': return `<div class="cdx-p-client">
      <div class="cdx-p-avatar">JR</div>
      <div><div class="cdx-p-client-name">Johnson Residence</div>
      <div class="cdx-p-client-sub">Recurring · Lawn + Hedge</div></div></div>`;
    case 'service-badge': return `<div class="cdx-p-svc-badge">
      <span class="cdx-p-badge">🌿 Lawn Mowing</span>
      <span class="cdx-p-badge cdx-p-badge-alt">✂️ Hedge Trim</span></div>`;
    case 'duration': return `<div class="cdx-p-meta">
      <div style="width:12px;height:12px">${IC.clock}</div> 90 min</div>`;
    case 'assigned-crew': return `<div class="cdx-p-crew">
      <div class="cdx-p-avatars"><div class="cdx-p-cav">TS</div><div class="cdx-p-cav">AK</div></div>
      <span class="cdx-p-crew-name">Tim S, Alex K</span></div>`;
    case 'revenue': return `<div class="cdx-p-rev">
      <span class="cdx-p-rev-amt">$125</span>
      <span class="cdx-p-rev-lbl">est. revenue</span></div>`;
    case 'status-badge': return `<div class="cdx-p-status">
      <span class="cdx-p-stat">● Scheduled</span></div>`;
    case 'address': return `<div class="cdx-p-addr">
      <div style="width:12px;height:12px;flex-shrink:0">${IC.pin}</div>
      1234 Oak St, Vancouver</div>`;
    default: return `<div class="cdx-p-meta">${id}</div>`;
  }
}

// ── Drop Zone Events ──
function onDZDragOver(e) {
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  const dz = document.getElementById('cdxDropZone');
  dz.classList.add('cdx-dz-active');
  const indicators = dz.querySelectorAll('.cdx-drop-ind');
  const cardEls    = dz.querySelectorAll('.cdx-card-el');
  indicators.forEach(i => i.classList.remove('show'));
  let idx = getLayout().length;
  for (let i = 0; i < cardEls.length; i++) {
    const r = cardEls[i].getBoundingClientRect();
    if (e.clientY < r.top + r.height / 2) { idx = i; break; }
  }
  state.drag.dropIndex = idx;
  const target = dz.querySelector(`.cdx-drop-ind[data-idx="${idx}"]`);
  if (target) target.classList.add('show');
}

function onDZDragLeave(e) {
  const dz = document.getElementById('cdxDropZone');
  if (!dz.contains(e.relatedTarget)) {
    dz.classList.remove('cdx-dz-active');
    dz.querySelectorAll('.cdx-drop-ind').forEach(i => i.classList.remove('show'));
  }
}

function onDZDrop(e) {
  e.preventDefault();
  const dz = document.getElementById('cdxDropZone');
  dz.classList.remove('cdx-dz-active');
  dz.querySelectorAll('.cdx-drop-ind').forEach(i => i.classList.remove('show'));
  const { source, elemId, dropIndex } = state.drag;
  if (!elemId) return;
  const layout = getLayout();
  const idx    = dropIndex !== null ? dropIndex : layout.length;
  if (source === 'palette') {
    if (!layout.includes(elemId)) layout.splice(idx, 0, elemId);
  } else if (source === 'card') {
    const old = layout.indexOf(elemId);
    if (old !== -1) {
      layout.splice(old, 1);
      layout.splice(idx > old ? idx - 1 : idx, 0, elemId);
    }
  }
  state.drag = { source:null, elemId:null, dropIndex:null };
  render();
}

// ── Property Actions ──
function toggleVis() {
  const p = state.props[state.activeCard];
  if (!p[state.selectedElem]) p[state.selectedElem] = {};
  p[state.selectedElem].visible = p[state.selectedElem].visible === false ? true : false;
  renderProps();
}
function updateLabel(val) {
  const p = state.props[state.activeCard];
  if (!p[state.selectedElem]) p[state.selectedElem] = {};
  p[state.selectedElem].label = val;
}
function updateColor(hex) {
  const p = state.props[state.activeCard];
  if (!p[state.selectedElem]) p[state.selectedElem] = {};
  p[state.selectedElem].color = hex;
  renderProps();
}
function removeElem(id) {
  const layout = getLayout();
  const i = layout.indexOf(id);
  if (i !== -1) layout.splice(i, 1);
  if (state.selectedElem === id) state.selectedElem = null;
  render();
}

// ── Tab / View Switching ──
function switchCard(type) {
  state.activeCard  = type;
  state.viewMode    = 'compact';
  state.selectedElem = null;
  document.querySelectorAll('.cdx-tab').forEach(t => t.classList.toggle('active', t.dataset.card === type));
  render();
}

function switchView(mode) {
  state.viewMode    = mode;
  state.selectedElem = null;
  render();
}

// ── Action Bar ──
function resetLayout() {
  const defs = CARD_DEFS[state.activeCard];
  const label = defs.hasViews ? defs.views[state.viewMode].label : defs.label;
  if (!confirm(`Reset "${label}" to default? This will undo your changes.`)) return;
  if (defs.hasViews) {
    state.layouts.stop[state.viewMode] = [...defs.views[state.viewMode].defaults];
  } else {
    state.layouts.schedule = [...defs.defaults];
  }
  state.selectedElem = null;
  render();
  toast('Layout reset to default');
}

function exportJSON() {
  const data = buildExportData();
  const blob = new Blob([JSON.stringify(data, null, 2)], {type:'application/json'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `mowology-card-layout-${Date.now()}.json`;
  a.click();
  toast('Layout exported as JSON');
}

function saveLayout() {
  const data = buildExportData();
  localStorage.setItem('mw-card-layout', JSON.stringify(data));
  fetch('/crm/api/card-layout.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) }).catch(()=>{});
  toast('Layout saved ✓');
}

function buildExportData() {
  return {
    version: 2,
    savedAt: new Date().toISOString(),
    layouts: {
      stop: {
        compact:  { order: state.layouts.stop.compact,  props: state.props.stop },
        expanded: { order: state.layouts.stop.expanded, props: state.props.stop },
      },
      schedule: { order: state.layouts.schedule, props: state.props.schedule },
    }
  };
}

// ── Toast ──
function toast(msg, dur=2200) {
  const t = document.getElementById('cdxToast');
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), dur);
}

// ── Load saved layout ──
(function loadSaved() {
  try {
    const raw = localStorage.getItem('mw-card-layout');
    if (!raw) return;
    const d = JSON.parse(raw);
    if (d.version === 2) {
      if (d.layouts?.stop?.compact?.order)  state.layouts.stop.compact  = d.layouts.stop.compact.order;
      if (d.layouts?.stop?.expanded?.order) state.layouts.stop.expanded = d.layouts.stop.expanded.order;
      if (d.layouts?.schedule?.order)       state.layouts.schedule      = d.layouts.schedule.order;
      if (d.layouts?.stop?.compact?.props)  state.props.stop            = d.layouts.stop.compact.props;
      if (d.layouts?.schedule?.props)       state.props.schedule        = d.layouts.schedule.props;
    } else if (d.version === 1) {
      // v1 backward compat: map old flat stop layout to compact view
      if (d.layouts?.stop?.order) state.layouts.stop.compact = d.layouts.stop.order;
      if (d.layouts?.schedule?.order) state.layouts.schedule = d.layouts.schedule.order;
    }
  } catch(e) {}
})();

// ── Init ──
render();
</script>

<?php include 'includes/appstack_footer.php'; ?>
