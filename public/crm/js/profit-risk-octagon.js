/**
 * Profit Risk Octagon — Net Profit Risk Visualization
 * Self-contained SVG component, no dependencies.
 *
 * Usage:
 *   var oct = new ProfitRiskOctagon(containerEl, { /* options *\/ });
 *   oct.render(data);  // data = API response .data object
 *   oct.render(newData); // smooth update
 */
(function (global) {
    'use strict';

    var N          = 8;                          // 8 risk factors
    var TAU        = Math.PI * 2;
    var START_A    = -Math.PI / 2;               // top
    var SEG_A      = TAU / N;                    // 45° per segment
    var NS         = 'http://www.w3.org/2000/svg';

    // ── Color scale (low-risk green → critical red) ────────────────────────
    function scoreColor(s) {
        if (s >= 0.75) return '#ef4444';         // critical — red
        if (s >= 0.50) return '#f97316';         // high — orange
        if (s >= 0.25) return '#f59e0b';         // medium — amber
        return '#22c55e';                        // low — green
    }

    function fmtCurrency(v) {
        var abs = Math.abs(v);
        var str;
        if      (abs >= 10000) str = '$' + (abs / 1000).toFixed(0) + 'k';
        else if (abs >= 1000)  str = '$' + (abs / 1000).toFixed(1) + 'k';
        else                   str = '$' + abs.toFixed(0);
        return v < 0 ? '-' + str : str;
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // ── Constructor ────────────────────────────────────────────────────────
    function ProfitRiskOctagon(container, options) {
        this.container = container;
        this.opts = Object.assign({
            size:         280,   // viewBox width & height
            maxR:          82,   // max segment radius
            labelR:       112,   // label centre radius
            centerR:       44,   // inner white circle radius
        }, options || {});

        var o  = this.opts;
        this.cx = o.size / 2;
        this.cy = o.size / 2;

        // Create SVG element once
        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 ' + o.size + ' ' + o.size);
        svg.setAttribute('class', 'mw-pro-svg');
        svg.setAttribute('role', 'img');
        svg.setAttribute('aria-label', 'Net Profit Risk Analysis');
        this.svg = svg;
        this.container.appendChild(svg);
    }

    // ── Public: render / update ───────────────────────────────────────────
    ProfitRiskOctagon.prototype.render = function (data) {
        this._data = data;
        this._build(data);
    };

    // ── Private: full SVG rebuild ──────────────────────────────────────────
    ProfitRiskOctagon.prototype._build = function (data) {
        var o   = this.opts;
        var cx  = this.cx, cy = this.cy;
        var R   = o.maxR, LR = o.labelR, CR = o.centerR;
        var factors = (data && data.factors) ? data.factors : [];

        var buf = [];

        // ── Defs ────────────────────────────────────────────────────────────
        buf.push('<defs>');
        buf.push(
            '<filter id="mwProGlowA" x="-60%" y="-60%" width="220%" height="220%">',
            '  <feGaussianBlur stdDeviation="4" result="b"/>',
            '  <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>',
            '</filter>',
            '<filter id="mwProGlowB" x="-80%" y="-80%" width="260%" height="260%">',
            '  <feGaussianBlur stdDeviation="6" result="b"/>',
            '  <feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge>',
            '</filter>',
            '<radialGradient id="mwProBg" cx="50%" cy="50%" r="50%">',
            '  <stop offset="0%"   stop-color="#0f172a"/>',
            '  <stop offset="100%" stop-color="#060d1a"/>',
            '</radialGradient>'
        );
        buf.push('</defs>');

        // ── Background ──────────────────────────────────────────────────────
        buf.push(
            '<rect width="' + o.size + '" height="' + o.size + '" fill="url(#mwProBg)" rx="14"/>',
            // subtle outer ring
            '<circle cx="' + cx + '" cy="' + cy + '" r="' + (R + 18) + '" fill="none" stroke="#1e293b" stroke-width="0.5" stroke-dasharray="3 4"/>'
        );

        // ── Grid rings (25 / 50 / 75 / 100 %) ──────────────────────────────
        [0.25, 0.5, 0.75, 1.0].forEach(function (f, i) {
            var r   = R * f;
            var da  = i < 3 ? ' stroke-dasharray="2 4"' : '';
            var sw  = i === 3 ? '1' : '0.7';
            buf.push('<circle cx="' + cx + '" cy="' + cy + '" r="' + r.toFixed(1) +
                '" fill="none" stroke="#1e293b" stroke-width="' + sw + '"' + da + '/>');
        });

        // ── Radial grid lines ────────────────────────────────────────────────
        for (var li = 0; li < N; li++) {
            var la = START_A + li * SEG_A;
            buf.push('<line x1="' + cx + '" y1="' + cy +
                '" x2="' + (cx + R * Math.cos(la)).toFixed(2) +
                '" y2="' + (cy + R * Math.sin(la)).toFixed(2) +
                '" stroke="#1e293b" stroke-width="0.7"/>');
        }

        // ── Octagon outline ──────────────────────────────────────────────────
        var pts = [];
        for (var oi = 0; oi < N; oi++) {
            var oa = START_A + oi * SEG_A;
            pts.push((cx + R * Math.cos(oa)).toFixed(1) + ',' + (cy + R * Math.sin(oa)).toFixed(1));
        }
        buf.push('<polygon points="' + pts.join(' ') + '" fill="none" stroke="#334155" stroke-width="1.2"/>');

        // ── Risk wedges ──────────────────────────────────────────────────────
        for (var fi = 0; fi < Math.min(N, factors.length); fi++) {
            var f     = factors[fi];
            var score = Math.max(0, Math.min(1, f.score_0_1 || 0));
            var sR    = Math.max(3, score * R);

            var a1  = START_A + fi * SEG_A - SEG_A / 2;
            var a2  = START_A + fi * SEG_A + SEG_A / 2;
            var x1  = (cx + sR * Math.cos(a1)).toFixed(2);
            var y1  = (cy + sR * Math.sin(a1)).toFixed(2);
            var x2  = (cx + sR * Math.cos(a2)).toFixed(2);
            var y2  = (cy + sR * Math.sin(a2)).toFixed(2);

            var color  = scoreColor(score);
            var glow   = score >= 0.75 ? ' filter="url(#mwProGlowB)"'
                       : score >= 0.50 ? ' filter="url(#mwProGlowA)"' : '';
            var delay  = (fi * 90) + 'ms';

            var d = 'M' + cx.toFixed(2) + ',' + cy.toFixed(2) +
                    ' L' + x1 + ',' + y1 +
                    ' A' + sR.toFixed(2) + ',' + sR.toFixed(2) + ' 0 0,1 ' + x2 + ',' + y2 + ' Z';

            buf.push(
                '<path d="' + d + '" fill="' + color + '" opacity="0.9"' + glow +
                ' class="mw-pro-seg" style="animation-delay:' + delay + '"' +
                ' tabindex="0" role="img"' +
                ' aria-label="' + esc(f.label) + ': ' + esc(f.raw_value) + ' — ' + esc(f.explanation) + '">' +
                '<title>' + esc(f.label) + ': ' + esc(f.raw_value) + ' (' + esc(f.expected_value) + ' target) — ' + esc(f.explanation) + '</title>' +
                '</path>'
            );
        }

        // ── Centre circle ────────────────────────────────────────────────────
        var netProfit  = (data && data.net_profit  !== undefined) ? data.net_profit  : 0;
        var netMargin  = (data && data.net_margin  !== undefined) ? data.net_margin  : 0;
        var pc         = netProfit >= 0 ? '#22c55e' : '#ef4444';
        var profStr    = fmtCurrency(netProfit);
        var margStr    = netMargin.toFixed(1) + '%';

        buf.push(
            '<circle cx="' + cx + '" cy="' + cy + '" r="' + (CR + 5) + '" fill="#0f172a" stroke="' + pc + '" stroke-width="1.5" opacity="0.85"/>',
            '<circle cx="' + cx + '" cy="' + cy + '" r="' + CR + '" fill="#060d1a"/>',
            '<text x="' + cx + '" y="' + (cy - 15) + '" font-size="6" fill="#475569" text-anchor="middle" letter-spacing="1" font-family="system-ui,sans-serif">NET PROFIT</text>',
            '<text x="' + cx + '" y="' + (cy + 2)  + '" font-size="15" font-weight="800" fill="' + pc + '" text-anchor="middle" font-family="system-ui,sans-serif">' + esc(profStr) + '</text>',
            '<text x="' + cx + '" y="' + (cy + 16) + '" font-size="10" font-weight="700" fill="' + pc + '" text-anchor="middle" font-family="system-ui,sans-serif">' + esc(margStr) + '</text>',
            '<text x="' + cx + '" y="' + (cy + 27) + '" font-size="6" fill="#475569" text-anchor="middle" letter-spacing="0.8" font-family="system-ui,sans-serif">MARGIN</text>'
        );

        // ── Perimeter labels ─────────────────────────────────────────────────
        for (var lbi = 0; lbi < Math.min(N, factors.length); lbi++) {
            var lf     = factors[lbi];
            var ls     = Math.max(0, Math.min(1, lf.score_0_1 || 0));
            var la2    = START_A + lbi * SEG_A;
            var lx     = cx + LR * Math.cos(la2);
            var ly     = cy + LR * Math.sin(la2);
            var anchor = lx > cx + 6 ? 'start' : (lx < cx - 6 ? 'end' : 'middle');
            var lc     = ls >= 0.50 ? '#fb923c' : (ls >= 0.25 ? '#fbbf24' : '#94a3b8');

            buf.push(
                '<text x="' + lx.toFixed(1) + '" y="' + (ly - 3).toFixed(1) +
                '" font-size="7.5" font-weight="600" fill="' + lc +
                '" text-anchor="' + anchor + '" font-family="system-ui,sans-serif">' + esc(lf.label) + '</text>',
                '<text x="' + lx.toFixed(1) + '" y="' + (ly + 8).toFixed(1) +
                '" font-size="6.5" fill="#64748b" text-anchor="' + anchor +
                '" font-family="system-ui,sans-serif">' + esc(lf.raw_value) + '</text>'
            );
        }

        this.svg.innerHTML = buf.join('\n');
    };

    // ── Expose globally ────────────────────────────────────────────────────
    global.ProfitRiskOctagon = ProfitRiskOctagon;

}(window));


// ── Schedule page integration ─────────────────────────────────────────────────
// Runs after DOM ready; attaches the octagon modal logic.
(function () {
    'use strict';

    var CSRF_META = document.querySelector('meta[name="csrf-token"]');

    function csrfToken() {
        var el = document.querySelector('input[name="csrf_token"]') ||
                 document.querySelector('[data-csrf]');
        return el ? (el.value || el.dataset.csrf || '') : '';
    }

    function getFirstPlanId(card) {
        try {
            var visits = JSON.parse(card.dataset.visits || '[]');
            for (var i = 0; i < visits.length; i++) {
                if (visits[i].plan_id) return parseInt(visits[i].plan_id, 10);
            }
        } catch (e) {}
        return 0;
    }

    // ── Build or retrieve the modal ───────────────────────────────────────
    function getModal() {
        var m = document.getElementById('mwProModal');
        if (m) return m;

        m = document.createElement('div');
        m.id        = 'mwProModal';
        m.className = 'mw-pro-modal-overlay';
        m.setAttribute('role', 'dialog');
        m.setAttribute('aria-modal', 'true');
        m.setAttribute('aria-label', 'Net Profit Risk Analysis');
        m.innerHTML =
            '<div class="mw-pro-modal">' +
            '  <div class="mw-pro-modal-header">' +
            '    <div>' +
            '      <div class="mw-pro-modal-title" id="mwProModalTitle">Net Profit Risk</div>' +
            '      <div class="mw-pro-modal-sub" id="mwProModalSub"></div>' +
            '    </div>' +
            '    <button class="mw-pro-modal-close" id="mwProModalClose" aria-label="Close">' +
            '      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">' +
            '        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>' +
            '      </svg>' +
            '    </button>' +
            '  </div>' +
            '  <div class="mw-pro-svg-wrap" id="mwProSvgWrap"></div>' +
            '  <div class="mw-pro-legend" id="mwProLegend">' +
            '    <span class="mw-pro-leg-item"><span class="mw-pro-leg-dot" style="background:#22c55e"></span>Low risk</span>' +
            '    <span class="mw-pro-leg-item"><span class="mw-pro-leg-dot" style="background:#f59e0b"></span>Medium</span>' +
            '    <span class="mw-pro-leg-item"><span class="mw-pro-leg-dot" style="background:#f97316"></span>High</span>' +
            '    <span class="mw-pro-leg-item"><span class="mw-pro-leg-dot" style="background:#ef4444"></span>Critical</span>' +
            '  </div>' +
            '  <p class="mw-pro-updated" id="mwProUpdated"></p>' +
            '</div>';

        document.body.appendChild(m);

        // Close on overlay click or close button
        m.addEventListener('click', function (e) {
            if (e.target === m || e.target.closest('#mwProModalClose')) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeModal();
        });

        return m;
    }

    var _octagon = null;

    function openModal(planId, stopLabel) {
        var modal = getModal();
        var wrap  = document.getElementById('mwProSvgWrap');
        var title = document.getElementById('mwProModalTitle');
        var sub   = document.getElementById('mwProModalSub');
        var upd   = document.getElementById('mwProUpdated');

        title.textContent = 'Net Profit Risk';
        sub.textContent   = stopLabel || '';
        upd.textContent   = '';

        // Show modal with loading state
        wrap.innerHTML = '<div class="mw-pro-loading"><div class="mw-pro-spinner"></div><span>Analysing…</span></div>';
        modal.classList.add('is-open');
        document.body.classList.add('mw-pro-no-scroll');

        var url = (window.location.pathname.replace(/\/[^/]*$/, '') + '/api/profit-risk-factors.php')
                      .replace(/\/+/g, '/');

        fetch('/crm/api/profit-risk-factors.php?plan_id=' + planId, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (!resp.success) throw new Error(resp.error || 'API error');

            if (!resp.has_data) {
                wrap.innerHTML =
                    '<div class="mw-pro-empty">' +
                    '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#334155" stroke-width="1.5">' +
                    '<circle cx="12" cy="12" r="10"/>' +
                    '<line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>' +
                    '</svg>' +
                    '<p>' + (resp.message || 'No data yet') + '</p>' +
                    '</div>';
                return;
            }

            var d = resp.data;
            title.textContent = 'Net Profit Risk';
            sub.textContent   = d.plan_number + (d.plan_title ? ' — ' + d.plan_title : '');
            upd.textContent   = 'Updated ' + new Date(d.updated_at).toLocaleTimeString();

            // Render octagon
            wrap.innerHTML = '';
            if (!_octagon || _octagon.container !== wrap) {
                _octagon = new ProfitRiskOctagon(wrap);
            }
            _octagon.render(d);
        })
        .catch(function (err) {
            wrap.innerHTML =
                '<div class="mw-pro-empty"><p style="color:#f87171">Error: ' +
                String(err.message).replace(/</g, '&lt;') + '</p></div>';
        });
    }

    function closeModal() {
        var modal = document.getElementById('mwProModal');
        if (modal) modal.classList.remove('is-open');
        document.body.classList.remove('mw-pro-no-scroll');
    }

    // ── Attach trigger button click ───────────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.mw-pro-trigger');
        if (!btn) return;
        e.stopPropagation();  // don't bubble to card click handler

        // Standalone use (e.g. job detail view): plan-id on the button itself
        var planId = btn.dataset.planId ? parseInt(btn.dataset.planId, 10) : 0;
        var addr   = btn.dataset.address || '';

        // Schedule view fallback: read from parent stop card's data-visits JSON
        if (!planId) {
            var card = btn.closest('[data-stop-id]');
            planId   = getFirstPlanId(card);
            addr     = (card && card.dataset.propertyAddress) || '';
        }

        if (!planId) return;
        openModal(planId, addr);
    });

}());
