/**
 * Owner Freedom dashboard charts.
 * Data: window.MW_FREEDOM = { score, metrics, trend[] } (set by freedom_appstack.php).
 * Chart.js v4 is loaded in <head>; colours come from the brand tokens, never hex here.
 */
(function () {
    'use strict';
    var D = window.MW_FREEDOM;
    if (!D || typeof Chart === 'undefined') return;

    var css = getComputedStyle(document.documentElement);
    var tok = function (name, fallback) { return (css.getPropertyValue(name) || fallback).trim(); };
    var C = {
        green:  tok('--mw-green',  '#2D8659'),
        dark:   tok('--mw-dark',   '#1A5F4A'),
        lime:   tok('--mw-lime',   '#7FD858'),
        light:  tok('--mw-light',  '#E8F3F0'),
        forest: tok('--mw-forest', '#0D3B2E'),
        orange: tok('--mw-orange', '#e85d04'),
        grey:   '#adb5bd',
        red:    '#c0392b'
    };
    var money = function (v) {
        var n = Math.round(Math.abs(v));
        return (v < 0 ? '-$' : '$') + n.toLocaleString();
    };
    var m = D.metrics || {};

    // ── Score ring ──────────────────────────────────────────────────────────
    var ring = document.getElementById('mwFdRing');
    if (ring) {
        var score = D.score === null ? 0 : D.score;
        var ringColor = score >= 100 ? C.lime : score >= 60 ? C.green : score >= 25 ? C.orange : C.red;
        new Chart(ring, {
            type: 'doughnut',
            data: { datasets: [{ data: [score, Math.max(0, 100 - score)], backgroundColor: [ringColor, C.light], borderWidth: 0, hoverOffset: 0 }] },
            options: {
                cutout: '78%', rotation: -120, circumference: 240, responsive: false, animation: { duration: 900 },
                plugins: { legend: { display: false }, tooltip: { enabled: false } }
            }
        });
    }

    // ── Waterfall: a week's revenue → what is left for the owner ────────────
    var wf = document.getElementById('mwFdWaterfall');
    if (wf) {
        var weeks = Math.max(0.14, m.weeks || 1);
        var revW  = (m.revenue || 0) / weeks;
        var crewW = (m.crew_labour_cost || 0) / weeks;
        var expW  = (m.expenses || 0) / weeks;
        var ohW   = (m.fixed_overhead || 0) / weeks;
        var repW  = m.replacement_cost_week || 0;
        var left  = m.available_week || 0;
        var target = m.target_week || 0;

        var steps = [
            { label: 'Revenue',        from: 0,                        to: revW,                       color: C.green },
            { label: 'Crew wages',     from: revW,                     to: revW - crewW,               color: C.grey },
            { label: 'Expenses',       from: revW - crewW,             to: revW - crewW - expW,        color: C.grey },
        ];
        if (ohW > 0) steps.push({ label: 'Fixed overhead', from: revW - crewW - expW, to: revW - crewW - expW - ohW, color: C.grey });
        steps.push({ label: 'Replacing you', from: revW - crewW - expW - ohW, to: left, color: C.orange });
        steps.push({ label: 'Left for you',  from: 0, to: left, color: left >= target ? C.lime : C.dark });

        new Chart(wf, {
            type: 'bar',
            data: {
                labels: steps.map(function (s) { return s.label; }),
                datasets: [{
                    data: steps.map(function (s) { return [Math.min(s.from, s.to), Math.max(s.from, s.to)]; }),
                    backgroundColor: steps.map(function (s) { return s.color; }),
                    borderRadius: 3, borderSkipped: false, barPercentage: 0.7
                }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                scales: {
                    x: { ticks: { callback: function (v) { return money(v); } }, grid: { color: C.light } },
                    y: { grid: { display: false } }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: function (ctx) {
                        var s = steps[ctx.dataIndex];
                        var delta = s.label === 'Left for you' ? s.to : s.to - s.from;
                        return (delta >= 0 ? '+' : '−') + money(Math.abs(delta)) + ' / week';
                    } } },
                    annotationLine: { x: target }
                }
            },
            plugins: [{
                id: 'annotationLine',
                afterDraw: function (chart, args, opts) {
                    if (!opts || !opts.x) return;
                    var x = chart.scales.x.getPixelForValue(opts.x);
                    var ctx = chart.ctx, area = chart.chartArea;
                    if (x < area.left || x > area.right) return;
                    ctx.save();
                    ctx.strokeStyle = C.forest; ctx.setLineDash([4, 4]); ctx.lineWidth = 1.5;
                    ctx.beginPath(); ctx.moveTo(x, area.top); ctx.lineTo(x, area.bottom); ctx.stroke();
                    ctx.fillStyle = C.forest; ctx.font = '11px sans-serif'; ctx.textAlign = 'center';
                    ctx.fillText('your cheque ' + money(opts.x), x, area.top - 4);
                    ctx.restore();
                }
            }]
        });
    }

    // ── Mix: revenue with vs without the owner ──────────────────────────────
    var mix = document.getElementById('mwFdMix');
    if (mix) {
        var withOwner = m.visit_revenue_owner || 0, crewOnly = m.visit_revenue_crew_only || 0;
        new Chart(mix, {
            type: 'doughnut',
            data: { labels: ['With you on site', 'Crew only'], datasets: [{ data: [withOwner, crewOnly], backgroundColor: [C.orange, C.green], borderWidth: 0 }] },
            options: { cutout: '65%', responsive: false, plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return c.label + ': ' + money(c.parsed); } } } } }
        });
    }

    // ── Trend: freedom % bars + owner hours line ────────────────────────────
    var tr = document.getElementById('mwFdTrend');
    if (tr && Array.isArray(D.trend)) {
        var labels = D.trend.map(function (t) { return t.label; });
        var pct    = D.trend.map(function (t) { return t.freedom_pct === null ? 0 : Math.max(-50, Math.min(150, t.freedom_pct)); });
        var hours  = D.trend.map(function (t) { return t.owner_hours_week; });
        new Chart(tr, {
            data: {
                labels: labels,
                datasets: [
                    { type: 'bar', label: 'Cheque covered without you (%)', data: pct, yAxisID: 'y',
                      backgroundColor: pct.map(function (p) { return p >= 100 ? C.lime : p >= 60 ? C.green : p >= 25 ? C.orange : C.red; }), borderRadius: 3 },
                    { type: 'line', label: 'Hours you worked / week', data: hours, yAxisID: 'y2',
                      borderColor: C.forest, backgroundColor: C.forest, tension: 0.3, pointRadius: 3 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: {
                    y:  { position: 'left', min: 0, suggestedMax: 120, ticks: { callback: function (v) { return v + '%'; } }, grid: { color: C.light },
                          title: { display: true, text: '% of cheque' } },
                    y2: { position: 'right', min: 0, suggestedMax: 50, grid: { drawOnChartArea: false }, title: { display: true, text: 'hours / week' } }
                },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function (c) {
                        return c.dataset.type === 'line' ? c.dataset.label + ': ' + c.parsed.y + ' h' : c.dataset.label + ': ' + Math.round(c.parsed.y) + '%';
                    } } },
                    targetLine: { y: 100 }
                }
            },
            plugins: [{
                id: 'targetLine',
                afterDraw: function (chart, args, opts) {
                    var y = chart.scales.y.getPixelForValue(opts.y);
                    var ctx = chart.ctx, area = chart.chartArea;
                    if (y < area.top || y > area.bottom) return;
                    ctx.save(); ctx.strokeStyle = C.dark; ctx.setLineDash([5, 4]); ctx.lineWidth = 1.5;
                    ctx.beginPath(); ctx.moveTo(area.left, y); ctx.lineTo(area.right, y); ctx.stroke();
                    ctx.fillStyle = C.dark; ctx.font = '11px sans-serif'; ctx.textAlign = 'right';
                    ctx.fillText('full cheque', area.right - 4, y - 5);
                    ctx.restore();
                }
            }]
        });
    }
})();
