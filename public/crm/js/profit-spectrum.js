/**
 * Profit Spectrum Renderer
 * ─────────────────────────────────────────────────────────────────────────────
 * Renders 5 canvas visualisations using real data injected by PHP as:
 *   window.SPECTRUM_DATA = { weeks52, months, services, crew, waterfall }
 *
 * Canvas IDs: spec-c1 … spec-c5
 * Card container IDs: sc1 … sc5
 *
 * v1 — wired to visit_margin_snapshots data
 */
(function () {
  'use strict';

  var D = window.SPECTRUM_DATA || { weeks52:[], months:[], services:[], crew:[], waterfall:[] };

  var GREEN = '#00ff88';
  var RED   = '#ff3322';

  // ── Helpers ──────────────────────────────────────────────────────────────────
  function setupCanvas(id) {
    var canvas = document.getElementById(id);
    if (!canvas) return null;
    var dpr = window.devicePixelRatio || 1;
    var W   = canvas.offsetWidth;
    var H   = parseInt(canvas.getAttribute('height'), 10) || 280;
    canvas.width  = W * dpr;
    canvas.height = H * dpr;
    var ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    return { canvas: canvas, ctx: ctx, W: W, H: H };
  }

  function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }
  function easeOutQuart(t) { return 1 - Math.pow(1 - t, 4); }
  function lerp(a, b, t)   { return a + (b - a) * t; }

  function fmt(v) {
    if (Math.abs(v) >= 1000) return '$' + (v / 1000).toFixed(1) + 'k';
    return '$' + Math.round(v);
  }

  function showEmpty(canvasId, msg) {
    var s = setupCanvas(canvasId);
    if (!s) return;
    s.ctx.fillStyle = 'rgba(255,255,255,0.14)';
    s.ctx.font      = '11px "JetBrains Mono", "Courier New", monospace';
    s.ctx.textAlign = 'center';
    s.ctx.fillText(msg, s.W / 2, s.H / 2 - 6);
    s.ctx.fillStyle = 'rgba(255,255,255,0.06)';
    s.ctx.fillText('Data builds as visits complete', s.W / 2, s.H / 2 + 14);
  }

  // ── VIZ 01 — SPECTRUM ─────────────────────────────────────────────────────
  function renderSpectrum() {
    var data = D.weeks52;
    var hasData = data.some(function(v) { return v !== 0; });
    if (!hasData) { showEmpty('spec-c1', 'No weekly data yet'); return; }

    var s = setupCanvas('spec-c1');
    if (!s) return;
    var ctx = s.ctx, W = s.W, H = s.H;
    var cy   = H / 2;
    var maxH = cy - 36;
    var maxV = Math.max.apply(null, data.map(Math.abs));
    var barW = (W - 24) / data.length;
    var bw   = Math.max(2, barW * 0.62);
    var progress = 0, start = null;

    function draw(p) {
      ctx.clearRect(0, 0, W, H);

      ctx.save();
      ctx.shadowBlur  = 10;
      ctx.shadowColor = 'rgba(255,255,255,0.2)';
      ctx.strokeStyle = 'rgba(255,255,255,0.12)';
      ctx.lineWidth   = 1;
      ctx.beginPath();
      ctx.moveTo(12, cy);
      ctx.lineTo(W - 12, cy);
      ctx.stroke();
      ctx.restore();

      [0.33, 0.66].forEach(function(f) {
        [cy - maxH * f, cy + maxH * f].forEach(function(y) {
          ctx.save();
          ctx.strokeStyle = 'rgba(255,255,255,0.04)';
          ctx.lineWidth   = 1;
          ctx.setLineDash([3, 6]);
          ctx.beginPath();
          ctx.moveTo(12, y);
          ctx.lineTo(W - 12, y);
          ctx.stroke();
          ctx.restore();
        });
      });

      data.forEach(function(val, i) {
        var animated = val * p;
        var bh = (Math.abs(animated) / maxV) * maxH;
        if (bh < 1) return;
        var isProfit = val >= 0;
        var color    = isProfit ? GREEN : RED;
        var x        = 12 + (i + 0.5) * barW;

        ctx.save();
        ctx.shadowBlur  = 28;
        ctx.shadowColor = color;
        ctx.globalAlpha = 0.35;
        ctx.fillStyle   = color;
        if (isProfit) ctx.fillRect(x - bw/2, cy - bh, bw, bh);
        else          ctx.fillRect(x - bw/2, cy,       bw, bh);
        ctx.restore();

        ctx.save();
        ctx.shadowBlur  = 10;
        ctx.shadowColor = color;
        var g = ctx.createLinearGradient(x, isProfit ? cy - bh : cy, x, isProfit ? cy : cy + bh);
        if (isProfit) {
          g.addColorStop(0,   '#00ff88');
          g.addColorStop(0.5, 'rgba(0,200,110,0.55)');
          g.addColorStop(1,   'rgba(0,80,50,0.08)');
        } else {
          g.addColorStop(0,   '#ff3322');
          g.addColorStop(0.5, 'rgba(200,60,40,0.55)');
          g.addColorStop(1,   'rgba(80,10,5,0.08)');
        }
        ctx.fillStyle = g;
        if (isProfit) ctx.fillRect(x - bw/2, cy - bh, bw, bh);
        else          ctx.fillRect(x - bw/2, cy,       bw, bh);
        ctx.restore();

        if (bh > 3) {
          ctx.save();
          ctx.shadowBlur  = 18;
          ctx.shadowColor = isProfit ? '#aaffdd' : '#ffaaaa';
          ctx.fillStyle   = isProfit ? '#ccffee' : '#ffcccc';
          var capH = Math.min(2.5, bh * 0.12);
          if (isProfit) ctx.fillRect(x - bw/2, cy - bh,           bw, capH);
          else          ctx.fillRect(x - bw/2, cy + bh - capH,    bw, capH);
          ctx.restore();
        }
      });

      ctx.font      = 'bold 11px sans-serif';
      ctx.fillStyle = 'rgba(0,255,136,0.55)';
      ctx.fillText('PROFIT ▲', 16, 22);
      ctx.fillStyle = 'rgba(255,51,34,0.55)';
      ctx.fillText('LOSS ▼', 16, H - 10);
      ctx.font      = '9px "JetBrains Mono", monospace';
      ctx.fillStyle = 'rgba(255,255,255,0.2)';
      ctx.textAlign = 'right';
      ctx.fillText('52 WEEKS', W - 16, H - 10);
      ctx.textAlign = 'left';
    }

    function animate(ts) {
      if (!start) start = ts;
      var t = Math.min((ts - start) / 1400, 1);
      progress = easeOutCubic(t);
      draw(progress);
      if (t < 1) requestAnimationFrame(animate);
    }
    requestAnimationFrame(animate);
  }

  // ── VIZ 02 — PULSE ───────────────────────────────────────────────────────
  function renderPulse() {
    var data = D.months;
    if (!data || data.length < 2) { showEmpty('spec-c2', 'Need 2+ months of data'); return; }

    var s = setupCanvas('spec-c2');
    if (!s) return;
    var ctx = s.ctx, W = s.W, H = s.H;
    var PAD  = { t: 20, b: 32, l: 20, r: 20 };
    var iW   = W - PAD.l - PAD.r;
    var iH   = H - PAD.t - PAD.b;
    var cy   = PAD.t + iH * 0.55;
    var maxP = Math.max.apply(null, data.map(function(d) { return Math.abs(d.profit); }));
    if (maxP === 0) maxP = 1;

    var pts = data.map(function(d, i) {
      return {
        x:      PAD.l + (i / (data.length - 1)) * iW,
        y:      cy - (d.profit / maxP) * (iH * 0.42),
        profit: d.profit,
        m:      d.m,
      };
    });

    var drawProg = 0, scanProg = 0, phase = 'draw', start = null;
    var particles = [];

    function addParticle(x, y, color) {
      for (var i = 0; i < 3; i++) {
        particles.push({
          x: x, y: y,
          vx: (Math.random() - 0.5) * 1.2,
          vy: (Math.random() - 0.8) * 1.4,
          alpha: 0.7,
          size:  1 + Math.random() * 1.5,
          color: color,
          life:  40 + Math.random() * 30,
        });
      }
    }

    function draw(dp, sp) {
      ctx.clearRect(0, 0, W, H);

      ctx.save();
      ctx.strokeStyle = 'rgba(255,255,255,0.1)';
      ctx.lineWidth   = 1;
      ctx.setLineDash([4, 8]);
      ctx.beginPath();
      ctx.moveTo(PAD.l, cy);
      ctx.lineTo(W - PAD.r, cy);
      ctx.stroke();
      ctx.setLineDash([]);
      ctx.restore();

      var totalLen = pts.length - 1;
      var drawn    = dp * totalLen;
      var drawnInt = Math.floor(drawn);

      for (var i = 0; i < drawnInt; i++) {
        var a   = pts[i];
        var b   = pts[Math.min(i + 1, pts.length - 1)];
        var col = a.profit >= 0 ? GREEN : RED;
        ctx.save();
        ctx.globalAlpha = 0.18;
        ctx.fillStyle   = col;
        ctx.beginPath();
        ctx.moveTo(a.x, cy);
        ctx.lineTo(a.x, a.y);
        ctx.lineTo(b.x, b.y);
        ctx.lineTo(b.x, cy);
        ctx.closePath();
        ctx.fill();
        ctx.restore();
      }

      if (drawnInt < pts.length - 1) {
        var frac = drawn - drawnInt;
        var pa   = pts[drawnInt];
        var pb   = pts[drawnInt + 1];
        var px   = lerp(pa.x, pb.x, frac);
        var py   = lerp(pa.y, pb.y, frac);
        ctx.save();
        ctx.globalAlpha = 0.12;
        ctx.fillStyle   = pa.profit >= 0 ? GREEN : RED;
        ctx.beginPath();
        ctx.moveTo(pa.x, cy);
        ctx.lineTo(pa.x, pa.y);
        ctx.lineTo(px, py);
        ctx.lineTo(px, cy);
        ctx.closePath();
        ctx.fill();
        ctx.restore();
      }

      ctx.save();
      ctx.lineWidth = 2.5;
      ctx.lineJoin  = 'round';
      ctx.lineCap   = 'round';
      for (var j = 0; j < drawnInt; j++) {
        var ja  = pts[j];
        var jb  = pts[j + 1];
        var jcol = ja.profit >= 0 ? GREEN : RED;
        ctx.shadowBlur  = 14;
        ctx.shadowColor = jcol;
        ctx.strokeStyle = jcol;
        ctx.beginPath();
        ctx.moveTo(ja.x, ja.y);
        ctx.lineTo(jb.x, jb.y);
        ctx.stroke();
      }
      if (drawnInt < pts.length - 1) {
        var ff   = drawn - drawnInt;
        var fa   = pts[drawnInt];
        var fb   = pts[drawnInt + 1];
        var fcol = fa.profit >= 0 ? GREEN : RED;
        ctx.shadowBlur  = 14;
        ctx.shadowColor = fcol;
        ctx.strokeStyle = fcol;
        ctx.beginPath();
        ctx.moveTo(fa.x, fa.y);
        ctx.lineTo(lerp(fa.x, fb.x, ff), lerp(fa.y, fb.y, ff));
        ctx.stroke();
      }
      ctx.restore();

      if (phase === 'scan' && sp >= 0) {
        var si  = sp * (pts.length - 1);
        var si0 = Math.floor(si);
        var si1 = Math.min(si0 + 1, pts.length - 1);
        var sf  = si - si0;
        var sx  = lerp(pts[si0].x, pts[si1].x, sf);
        var sy  = lerp(pts[si0].y, pts[si1].y, sf);
        var sCol = pts[si0].profit >= 0 ? GREEN : RED;

        if (Math.random() < 0.4) addParticle(sx, sy, sCol);

        for (var pi = particles.length - 1; pi >= 0; pi--) {
          var p = particles[pi];
          p.x += p.vx; p.y += p.vy; p.life--;
          p.alpha = Math.max(0, p.alpha - 0.025);
          if (p.life <= 0 || p.alpha <= 0) { particles.splice(pi, 1); continue; }
          ctx.save();
          ctx.globalAlpha = p.alpha;
          ctx.shadowBlur  = 6;
          ctx.shadowColor = p.color;
          ctx.fillStyle   = p.color;
          ctx.beginPath();
          ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
          ctx.fill();
          ctx.restore();
        }

        ctx.save();
        ctx.shadowBlur  = 20;
        ctx.shadowColor = sCol;
        ctx.fillStyle   = '#fff';
        ctx.beginPath();
        ctx.arc(sx, sy, 4, 0, Math.PI * 2);
        ctx.fill();
        ctx.shadowBlur  = 8;
        ctx.fillStyle   = sCol;
        ctx.beginPath();
        ctx.arc(sx, sy, 2.5, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
      }

      pts.forEach(function(p, i) {
        if (i > drawn + 0.5) return;
        var col = p.profit >= 0 ? GREEN : RED;
        ctx.save();
        ctx.shadowBlur  = 8;
        ctx.shadowColor = col;
        ctx.fillStyle   = col;
        ctx.beginPath();
        ctx.arc(p.x, p.y, 2.5, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
      });

      ctx.font      = '9px "JetBrains Mono", monospace';
      ctx.textAlign = 'center';
      pts.forEach(function(p) {
        if (!p.m) return;
        ctx.fillStyle = 'rgba(255,255,255,0.25)';
        ctx.fillText(p.m.toUpperCase(), p.x, H - 8);
      });
      ctx.textAlign = 'left';
    }

    function animate(ts) {
      if (!start) start = ts;
      var elapsed = ts - start;
      if (phase === 'draw') {
        var t = Math.min(elapsed / 1200, 1);
        drawProg = easeOutCubic(t);
        if (t >= 1) { phase = 'scan'; start = ts; }
      } else {
        scanProg = (elapsed / 2000) % 1;
      }
      draw(drawProg, scanProg);
      requestAnimationFrame(animate);
    }
    requestAnimationFrame(animate);
  }

  // ── VIZ 03 — RADAR ───────────────────────────────────────────────────────
  function renderRadar() {
    var services = D.services;
    if (!services || services.length < 3) {
      showEmpty('spec-c3', 'Need 3+ service types for radar');
      return;
    }

    var s = setupCanvas('spec-c3');
    if (!s) return;
    var ctx = s.ctx, W = s.W, H = s.H;
    var N   = services.length;
    var cx  = W / 2;
    var cy  = H / 2 + 8;
    var R   = Math.min(W, H) * 0.34;
    var angs = services.map(function(_, i) { return -Math.PI/2 + (i / N) * Math.PI * 2; });

    function pentPts(scale) {
      return angs.map(function(a) {
        return { x: cx + Math.cos(a) * R * scale, y: cy + Math.sin(a) * R * scale };
      });
    }

    function drawPent(pts, stroke, fill, blur, color) {
      ctx.save();
      if (blur > 0) { ctx.shadowBlur = blur; ctx.shadowColor = color; }
      ctx.beginPath();
      pts.forEach(function(p, i) { i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y); });
      ctx.closePath();
      if (fill)   { ctx.fillStyle   = fill;   ctx.fill();   }
      if (stroke) { ctx.strokeStyle = stroke; ctx.lineWidth = 1.5; ctx.stroke(); }
      ctx.restore();
    }

    var progress = 0, start = null;

    function draw(p) {
      ctx.clearRect(0, 0, W, H);

      [0.25, 0.5, 0.75, 1.0].forEach(function(sc) {
        drawPent(pentPts(sc), 'rgba(255,255,255,0.06)', null, 0, null);
      });

      angs.forEach(function(a) {
        var tp = { x: cx + Math.cos(a)*R, y: cy + Math.sin(a)*R };
        ctx.save();
        ctx.strokeStyle = 'rgba(255,255,255,0.06)';
        ctx.lineWidth   = 1;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.lineTo(tp.x, tp.y);
        ctx.stroke();
        ctx.restore();
      });

      var scaledPts = angs.map(function(a, i) {
        return {
          x: cx + Math.cos(a) * R * services[i].margin * p,
          y: cy + Math.sin(a) * R * services[i].margin * p,
        };
      });

      ctx.save();
      ctx.shadowBlur  = 20;
      ctx.shadowColor = GREEN;
      ctx.globalAlpha = 0.4;
      ctx.beginPath();
      scaledPts.forEach(function(pt, i) { i === 0 ? ctx.moveTo(pt.x, pt.y) : ctx.lineTo(pt.x, pt.y); });
      ctx.closePath();
      ctx.strokeStyle = GREEN;
      ctx.lineWidth   = 2;
      ctx.stroke();
      ctx.restore();

      drawPent(scaledPts, GREEN, 'rgba(0,255,136,0.12)', 15, GREEN);

      scaledPts.forEach(function(pt, i) {
        ctx.save();
        ctx.shadowBlur  = 12;
        ctx.shadowColor = GREEN;
        ctx.fillStyle   = GREEN;
        ctx.beginPath();
        ctx.arc(pt.x, pt.y, 4, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();

        if (p >= 0.98) {
          var pct = Math.round(services[i].margin * 100) + '%';
          var a   = angs[i];
          var lx  = cx + Math.cos(a) * (R * services[i].margin + 14);
          var ly  = cy + Math.sin(a) * (R * services[i].margin + 14);
          ctx.save();
          ctx.font      = '600 10px "JetBrains Mono", monospace';
          ctx.fillStyle = GREEN;
          ctx.textAlign = Math.abs(Math.cos(a)) < 0.1 ? 'center' : (Math.cos(a) > 0 ? 'left' : 'right');
          ctx.fillText(pct, lx, ly + 4);
          ctx.restore();
        }
      });

      angs.forEach(function(a, i) {
        var LR = R + 22;
        var lx = cx + Math.cos(a) * LR;
        var ly = cy + Math.sin(a) * LR;
        ctx.save();
        ctx.font         = '9px "JetBrains Mono", monospace';
        ctx.fillStyle    = 'rgba(255,255,255,0.38)';
        ctx.textAlign    = Math.abs(Math.cos(a)) < 0.15 ? 'center' : (Math.cos(a) > 0 ? 'left' : 'right');
        ctx.textBaseline = Math.sin(a) < -0.5 ? 'bottom' : (Math.sin(a) > 0.5 ? 'top' : 'middle');
        ctx.fillText(services[i].name.toUpperCase(), lx, ly);
        ctx.restore();
      });

      ctx.save();
      ctx.shadowBlur  = 8;
      ctx.shadowColor = GREEN;
      ctx.fillStyle   = 'rgba(0,255,136,0.4)';
      ctx.beginPath();
      ctx.arc(cx, cy, 3, 0, Math.PI * 2);
      ctx.fill();
      ctx.restore();
    }

    function animate(ts) {
      if (!start) start = ts;
      var t = Math.min((ts - start) / 1000, 1);
      progress = easeOutQuart(t);
      draw(progress);
      if (t < 1) requestAnimationFrame(animate);
    }
    requestAnimationFrame(animate);
  }

  // ── VIZ 04 — MATRIX ──────────────────────────────────────────────────────
  function renderMatrix() {
    var crew = D.crew;
    if (!crew || crew.length === 0) {
      showEmpty('spec-c4', 'No crew margin data this year');
      return;
    }

    var s = setupCanvas('spec-c4');
    if (!s) return;
    var ctx = s.ctx, W = s.W, H = s.H;
    var months = ['J','F','M','A','M','J','J','A','S','O','N','D'];
    var PAD  = { t: 28, b: 14, l: 60, r: 12 };
    var cols = 12;
    var rows = crew.length;
    var cw   = (W - PAD.l - PAD.r) / cols;
    var rh   = (H - PAD.t - PAD.b) / rows;
    var gap  = 3;

    var allVals = [];
    crew.forEach(function(c) { allVals = allVals.concat(c.months); });
    var maxV = Math.max.apply(null, allVals.map(Math.abs));
    if (maxV === 0) maxV = 1;

    var cells = [];
    crew.forEach(function(c, r) {
      c.months.forEach(function(val, col) {
        cells.push({ r: r, col: col, val: val, delay: (r * cols + col) * 22 });
      });
    });

    var start = null;

    function draw(elapsed) {
      ctx.clearRect(0, 0, W, H);

      ctx.font      = '8px "JetBrains Mono", monospace';
      ctx.textAlign = 'center';
      months.forEach(function(m, i) {
        var x = PAD.l + (i + 0.5) * cw;
        ctx.fillStyle = 'rgba(255,255,255,0.3)';
        ctx.fillText(m, x, 18);
      });

      ctx.textAlign    = 'right';
      ctx.textBaseline = 'middle';
      crew.forEach(function(c, r) {
        var y = PAD.t + (r + 0.5) * rh;
        ctx.fillStyle = 'rgba(255,255,255,0.35)';
        // Truncate long names
        var name = c.name.split(' ')[0];
        ctx.fillText(name, PAD.l - 6, y);
      });
      ctx.textAlign    = 'left';
      ctx.textBaseline = 'alphabetic';

      cells.forEach(function(cell) {
        var cellElapsed = elapsed - cell.delay;
        if (cellElapsed < 0) return;
        var t = Math.min(cellElapsed / 300, 1);
        var p = easeOutCubic(t);

        var x  = PAD.l + cell.col * cw + gap;
        var y  = PAD.t + cell.r * rh + gap;
        var bw = cw - gap * 2;
        var bh = rh - gap * 2;

        var norm = cell.val / maxV;
        var fillStyle, shadowColor, shadowBlur;

        if (cell.val < 0) {
          var intensity = Math.abs(norm);
          fillStyle   = 'rgba(255,51,34,' + (0.1 + intensity * 0.25) + ')';
          shadowColor = RED;
          shadowBlur  = 6 + intensity * 12;
        } else if (cell.val < 50) {
          fillStyle   = 'rgba(255,255,255,0.04)';
          shadowColor = 'transparent';
          shadowBlur  = 0;
        } else {
          var intensity2 = norm;
          fillStyle   = 'rgba(0,255,136,' + (0.05 + intensity2 * 0.28) + ')';
          shadowColor = GREEN;
          shadowBlur  = 4 + intensity2 * 14;
        }

        ctx.save();
        ctx.globalAlpha = p;
        ctx.shadowBlur  = shadowBlur * p;
        ctx.shadowColor = shadowColor;

        var radius = 4;
        ctx.beginPath();
        ctx.moveTo(x + radius, y);
        ctx.lineTo(x + bw - radius, y);
        ctx.quadraticCurveTo(x + bw, y, x + bw, y + radius);
        ctx.lineTo(x + bw, y + bh - radius);
        ctx.quadraticCurveTo(x + bw, y + bh, x + bw - radius, y + bh);
        ctx.lineTo(x + radius, y + bh);
        ctx.quadraticCurveTo(x, y + bh, x, y + bh - radius);
        ctx.lineTo(x, y + radius);
        ctx.quadraticCurveTo(x, y, x + radius, y);
        ctx.closePath();
        ctx.fillStyle = fillStyle;
        ctx.fill();

        if (cell.val > 100) {
          ctx.globalAlpha = p * 0.5;
          ctx.fillStyle   = cell.val > 0 ? 'rgba(0,255,136,0.4)' : 'rgba(255,51,34,0.4)';
          ctx.fillRect(x, y, bw, 1.5);
        }
        ctx.restore();
      });
    }

    function animate(ts) {
      if (!start) start = ts;
      draw(ts - start);
      requestAnimationFrame(animate);
    }
    requestAnimationFrame(animate);
  }

  // ── VIZ 05 — CASCADE (Waterfall) ─────────────────────────────────────────
  function renderCascade() {
    var data = D.waterfall;
    if (!data || data.length === 0 || data[0].value === 0) {
      showEmpty('spec-c5', 'No YTD cost data yet');
      return;
    }

    var s = setupCanvas('spec-c5');
    if (!s) return;
    var ctx = s.ctx, W = s.W, H = s.H;
    var PAD  = { t: 24, b: 48, l: 16, r: 16 };
    var iW   = W - PAD.l - PAD.r;
    var iH   = H - PAD.t - PAD.b;
    var barW = iW / data.length;
    var bw   = barW * 0.58;
    var maxV = data[0].value; // Revenue is always first + largest

    var bars = [];
    var running = 0;
    data.forEach(function(item, i) {
      var isTotal = item.type === 'total-pos' || item.type === 'total-neg';
      var top     = isTotal ? 0 : running;
      var bot     = isTotal ? Math.max(0, item.value) : running + item.value;
      bars.push({
        label:   item.label,
        value:   item.value,
        type:    item.type,
        idx:     i,
        top:     top,
        bot:     bot,
        isTotal: isTotal,
        x:       PAD.l + (i + 0.5) * barW,
      });
      if (!isTotal) running += item.value;
    });

    var scaleY = function(v) { return PAD.t + iH - (Math.max(0, v) / maxV) * iH; };
    var guides  = [0, maxV * 0.25, maxV * 0.5, maxV * 0.75, maxV].map(function(v) { return Math.round(v); });
    var progress = 0, start = null;

    function draw(p) {
      ctx.clearRect(0, 0, W, H);

      guides.forEach(function(v) {
        var gy = scaleY(v);
        if (gy < PAD.t || gy > PAD.t + iH) return;
        ctx.save();
        ctx.strokeStyle = 'rgba(255,255,255,0.05)';
        ctx.lineWidth   = 1;
        ctx.beginPath();
        ctx.moveTo(PAD.l, gy);
        ctx.lineTo(W - PAD.r, gy);
        ctx.stroke();
        ctx.font      = '8px "JetBrains Mono", monospace';
        ctx.fillStyle = 'rgba(255,255,255,0.18)';
        ctx.textAlign = 'left';
        ctx.fillText(fmt(v), PAD.l + 2, gy - 3);
        ctx.restore();
      });

      bars.forEach(function(bar, i) {
        var animP = Math.max(0, Math.min(1, (p * bars.length - i)));
        if (animP <= 0) return;

        var topY  = scaleY(Math.max(bar.top, bar.bot));
        var botY  = scaleY(Math.min(bar.top, bar.bot));
        var barH  = (botY - topY) * animP;
        var bx    = bar.x - bw / 2;
        var isPos = bar.value >= 0;
        var color = isPos ? GREEN : RED;
        var isNet = bar.label === 'Net';

        ctx.save();
        ctx.shadowBlur  = isNet ? 30 : 18;
        ctx.shadowColor = color;
        ctx.globalAlpha = 0.3;
        ctx.fillStyle   = color;
        ctx.fillRect(bx - (isNet ? 4 : 0), topY, bw + (isNet ? 8 : 0), barH);
        ctx.restore();

        ctx.save();
        ctx.shadowBlur  = 10;
        ctx.shadowColor = color;
        var g = ctx.createLinearGradient(bar.x, topY, bar.x, topY + barH);
        if (isPos) {
          g.addColorStop(0,   '#00ff88');
          g.addColorStop(0.6, 'rgba(0,180,90,0.7)');
          g.addColorStop(1,   'rgba(0,80,40,0.3)');
        } else {
          g.addColorStop(0,   'rgba(180,40,20,0.3)');
          g.addColorStop(0.4, 'rgba(200,60,40,0.7)');
          g.addColorStop(1,   '#ff3322');
        }
        ctx.fillStyle = g;
        ctx.fillRect(bx - (isNet ? 4 : 0), topY, bw + (isNet ? 8 : 0), barH);

        ctx.shadowBlur  = 14;
        ctx.shadowColor = color;
        ctx.fillStyle   = isPos ? '#aaffdd' : '#ffaaaa';
        ctx.fillRect(bx - (isNet ? 4 : 0), isPos ? topY : topY + barH - 2, bw + (isNet ? 8 : 0), 2);
        ctx.restore();

        if (i < bars.length - 1 && animP >= 1) {
          var nextBar = bars[i + 1];
          var connY   = scaleY(Math.max(0, bar.bot));
          ctx.save();
          ctx.strokeStyle = 'rgba(255,255,255,0.12)';
          ctx.lineWidth   = 1;
          ctx.setLineDash([3, 5]);
          ctx.beginPath();
          ctx.moveTo(bar.x + bw/2,     connY);
          ctx.lineTo(nextBar.x - bw/2, connY);
          ctx.stroke();
          ctx.setLineDash([]);
          ctx.restore();
        }

        if (barH > 18 && animP >= 0.8) {
          ctx.save();
          ctx.font      = (isNet ? '600 10px' : '600 9px') + ' "JetBrains Mono", monospace';
          ctx.fillStyle = isPos ? 'rgba(0,255,136,0.9)' : 'rgba(255,100,80,0.9)';
          ctx.textAlign = 'center';
          ctx.fillText(fmt(bar.value), bar.x, topY + barH/2 + 4);
          ctx.restore();
        }

        if (animP >= 0.5) {
          ctx.save();
          ctx.font      = (isNet ? '700 9px' : '9px') + ' "JetBrains Mono", monospace';
          ctx.fillStyle = isNet ? GREEN : 'rgba(255,255,255,0.35)';
          ctx.textAlign = 'center';
          ctx.fillText(bar.label.toUpperCase(), bar.x, H - 10);
          ctx.restore();
        }
      });
    }

    function animate(ts) {
      if (!start) start = ts;
      var t = Math.min((ts - start) / 1600, 1);
      progress = easeOutCubic(t);
      draw(progress);
      if (t < 1) requestAnimationFrame(animate);
    }
    requestAnimationFrame(animate);
  }

  // ── Init ─────────────────────────────────────────────────────────────────
  var rendered = {};

  function renderCard(id) {
    if (rendered[id]) return;
    rendered[id] = true;
    switch (id) {
      case 'sc1': renderSpectrum(); break;
      case 'sc2': renderPulse();    break;
      case 'sc3': renderRadar();    break;
      case 'sc4': renderMatrix();   break;
      case 'sc5': renderCascade();  break;
    }
  }

  function initAll() {
    ['sc1','sc2','sc3','sc4','sc5'].forEach(renderCard);
  }

  // Wait for fonts before rendering (avoids canvas text with fallback fonts)
  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(initAll);
  } else {
    document.addEventListener('DOMContentLoaded', initAll);
    initAll(); // also try immediately
  }

  // Resize: re-render all
  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      rendered = {};
      initAll();
    }, 200);
  });

})();
