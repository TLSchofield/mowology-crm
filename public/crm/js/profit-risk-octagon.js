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
        var R   = o.maxR, CR = o.centerR;
        var factors = (data && data.factors) ? data.factors : [];

        // ── Overall risk score ───────────────────────────────────────────────
        var avgScore = factors.length > 0
            ? factors.reduce(function (s, f) { return s + (f.score_0_1 || 0); }, 0) / factors.length
            : 0;
        var riskLevel = avgScore >= 0.75 ? 'CRITICAL' : avgScore >= 0.5 ? 'HIGH' : avgScore >= 0.25 ? 'MEDIUM' : 'LOW';
        var riskColor = scoreColor(avgScore);

        var buf = [];

        // ── Defs ────────────────────────────────────────────────────────────
        buf.push('<defs>',
            '<filter id="mwProShadow" x="-20%" y="-20%" width="140%" height="140%">',
            '  <feDropShadow dx="0" dy="1" stdDeviation="2" flood-color="#000" flood-opacity="0.12"/>',
            '</filter>',
            '</defs>');

        // ── Octagon area fill (very subtle brand tint) ───────────────────────
        var bgPts = [];
        for (var bi = 0; bi < N; bi++) {
            var ba = START_A + bi * SEG_A;
            bgPts.push((cx + R * Math.cos(ba)).toFixed(1) + ',' + (cy + R * Math.sin(ba)).toFixed(1));
        }
        buf.push('<polygon points="' + bgPts.join(' ') + '" fill="rgba(45,134,89,0.04)" stroke="none"/>');

        // ── Outer guide ring ─────────────────────────────────────────────────
        buf.push('<circle cx="' + cx + '" cy="' + cy + '" r="' + (R + 14) + '" fill="none" stroke="#cce0d6" stroke-width="0.5" stroke-dasharray="3 4"/>');

        // ── Grid rings (25 / 50 / 75 / 100 %) ──────────────────────────────
        [0.25, 0.5, 0.75, 1.0].forEach(function (f, i) {
            var r  = R * f;
            var da = i < 3 ? ' stroke-dasharray="2 4"' : '';
            var sw = i === 3 ? '1' : '0.7';
            buf.push('<circle cx="' + cx + '" cy="' + cy + '" r="' + r.toFixed(1) +
                '" fill="none" stroke="#d4e6de" stroke-width="' + sw + '"' + da + '/>');
        });

        // ── Radial grid lines ────────────────────────────────────────────────
        for (var li = 0; li < N; li++) {
            var la = START_A + li * SEG_A;
            buf.push('<line x1="' + cx + '" y1="' + cy +
                '" x2="' + (cx + R * Math.cos(la)).toFixed(2) +
                '" y2="' + (cy + R * Math.sin(la)).toFixed(2) +
                '" stroke="#d4e6de" stroke-width="0.7"/>');
        }

        // ── Octagon outline ──────────────────────────────────────────────────
        var pts = [];
        for (var oi = 0; oi < N; oi++) {
            var oa = START_A + oi * SEG_A;
            pts.push((cx + R * Math.cos(oa)).toFixed(1) + ',' + (cy + R * Math.sin(oa)).toFixed(1));
        }
        buf.push('<polygon points="' + pts.join(' ') + '" fill="none" stroke="#a8c9bb" stroke-width="1.2"/>');

        // ── Risk wedges ──────────────────────────────────────────────────────
        for (var fi = 0; fi < Math.min(N, factors.length); fi++) {
            var f     = factors[fi];
            var score = Math.max(0, Math.min(1, f.score_0_1 || 0));
            var sR    = Math.max(3, score * R);

            var a1 = START_A + fi * SEG_A - SEG_A / 2;
            var a2 = START_A + fi * SEG_A + SEG_A / 2;
            var x1 = (cx + sR * Math.cos(a1)).toFixed(2);
            var y1 = (cy + sR * Math.sin(a1)).toFixed(2);
            var x2 = (cx + sR * Math.cos(a2)).toFixed(2);
            var y2 = (cy + sR * Math.sin(a2)).toFixed(2);

            var color = scoreColor(score);
            var delay = (fi * 80) + 'ms';

            var d = 'M' + cx.toFixed(2) + ',' + cy.toFixed(2) +
                    ' L' + x1 + ',' + y1 +
                    ' A' + sR.toFixed(2) + ',' + sR.toFixed(2) + ' 0 0,1 ' + x2 + ',' + y2 + ' Z';

            buf.push(
                '<path d="' + d + '" fill="' + color + '" opacity="0.72"' +
                ' stroke="rgba(255,255,255,0.85)" stroke-width="1.2"' +
                ' filter="url(#mwProShadow)"' +
                ' class="mw-pro-seg" style="animation-delay:' + delay + '"' +
                ' tabindex="0" role="img"' +
                ' aria-label="' + esc(f.label) + ': ' + esc(f.raw_value) + ' — ' + esc(f.explanation) + '">' +
                '<title>' + esc(f.label) + ': ' + esc(f.raw_value) + ' (' + esc(f.expected_value) + ' target) — ' + esc(f.explanation) + '</title>' +
                '</path>'
            );
        }

        // ── Overall risk halo ring (outside center, inside wedges) ────────────
        buf.push(
            '<circle cx="' + cx + '" cy="' + cy + '" r="' + (CR + 11) + '" fill="none" stroke="' + riskColor + '" stroke-width="2.5" opacity="0.18"/>',
            '<circle cx="' + cx + '" cy="' + cy + '" r="' + (CR + 11) + '" fill="none" stroke="' + riskColor + '" stroke-width="0.8" opacity="0.55" stroke-dasharray="4 3"/>'
        );

        // ── Centre circle ────────────────────────────────────────────────────
        var netProfit = (data && data.net_profit !== undefined) ? data.net_profit : 0;
        var netMargin = (data && data.net_margin !== undefined) ? data.net_margin : 0;
        var pc        = netProfit >= 0 ? '#16a34a' : '#dc2626';
        var profStr   = fmtCurrency(netProfit);
        var margStr   = netMargin.toFixed(1) + '%';

        buf.push(
            '<circle cx="' + cx + '" cy="' + cy + '" r="' + (CR + 5) + '" fill="white" stroke="' + riskColor + '" stroke-width="1.5"/>',
            '<circle cx="' + cx + '" cy="' + cy + '" r="' + CR + '" fill="white"/>',
            // Risk level
            '<text x="' + cx + '" y="' + (cy - 18) + '" font-size="5.5" fill="#94a3b8" text-anchor="middle" letter-spacing="1.5" font-family="system-ui,sans-serif">RISK</text>',
            '<text x="' + cx + '" y="' + (cy - 7) + '" font-size="9" font-weight="800" fill="' + riskColor + '" text-anchor="middle" letter-spacing="0.2" font-family="system-ui,sans-serif">' + esc(riskLevel) + '</text>',
            // Separator
            '<line x1="' + (cx - 16) + '" y1="' + (cy - 1) + '" x2="' + (cx + 16) + '" y2="' + (cy - 1) + '" stroke="#e2e8f0" stroke-width="0.8"/>',
            // Net profit + margin
            '<text x="' + cx + '" y="' + (cy + 11) + '" font-size="13" font-weight="800" fill="' + pc + '" text-anchor="middle" font-family="system-ui,sans-serif">' + esc(profStr) + '</text>',
            '<text x="' + cx + '" y="' + (cy + 23) + '" font-size="8.5" font-weight="700" fill="' + pc + '" text-anchor="middle" font-family="system-ui,sans-serif">' + esc(margStr) + '</text>'
        );

        // Labels omitted — the factor table on the right provides all context.

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
