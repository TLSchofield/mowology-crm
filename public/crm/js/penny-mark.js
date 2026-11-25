/**
 * Penny's mark — three lines tumble while she is unsure; they lock into a triangle once the server confirms (Tim, 2026-10-23).
 * No circle, no ring. On a pending e-Transfer the lines tumble until Record is pressed; the lock happens only after
 * the server says the payment is recorded, so the mark never claims something that did not happen.
 *
 *   var m = PennyMark.create(hostElement);   // draws into hostElement
 *   m.busy(true);                            // spins faster: waiting for the server
 *   m.lock([true, true, true]);              // each side that is settled locks (all three = triangle + corner dots)
 *   m.unlock(); m.busy(false);               // back to tumbling (the server said no)
 * Decorative: the words beside it carry the state. Respects prefers-reduced-motion (holds still, snaps).
 * Colours come from CSS (.mw-pm line.is-free / .is-locked), never from this file.
 */
(function (global) {
    'use strict';
    var NS = 'http://www.w3.org/2000/svg';
    var CX = 50, CY = 52, R = 42;
    var A = [50, 8], B = [9, 80], C = [91, 80];
    var SIDES = [[A, B], [A, C], [B, C]];
    var SP = [1.5, -1.1, 2.0], PH = [0, 2.1, 4.2], LEN = [50, 44, 55];
    var reduce = !!(global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var marks = [], raf = 0, last = 0;

    function lerp(a, b, k) { return a + (b - a) * k; }
    function ease(k) { return k * k * (3 - 2 * k); }
    function free(i, t) {
        var cx = CX + Math.sin(t * 1.1 + PH[i]) * 11 + Math.sin(t * 2.6 + i) * 3;
        var cy = CY + Math.cos(t * 0.9 + PH[i] * 1.3) * 10 + Math.cos(t * 2.1 + i) * 3;
        var th = t * SP[i] + PH[i], L = LEN[i] * (1 + 0.08 * Math.sin(t * 1.7 + i * 2));
        return [[cx - Math.cos(th) * L / 2, cy - Math.sin(th) * L / 2], [cx + Math.cos(th) * L / 2, cy + Math.sin(th) * L / 2]].map(function (p) {
            var dx = p[0] - CX, dy = p[1] - CY, d = Math.sqrt(dx * dx + dy * dy);
            return d > R ? [CX + dx * R / d, CY + dy * R / d] : p;
        });
    }

    function create(host) {
        var svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('viewBox', '0 0 100 100');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('focusable', 'false');
        var m = { svg: svg, lines: [], dots: [], lock: [false, false, false], mix: [0, 0, 0], speed: 1, clock: Math.random() * 6 };
        for (var i = 0; i < 3; i++) {
            var l = document.createElementNS(NS, 'line');
            l.setAttribute('class', 'is-free');
            svg.appendChild(l);
            m.lines.push(l);
        }
        [A, B, C].forEach(function (p) {
            var d = document.createElementNS(NS, 'circle');
            d.setAttribute('cx', p[0]); d.setAttribute('cy', p[1]); d.setAttribute('r', '4.2');
            d.setAttribute('class', 'pm-dot'); d.setAttribute('opacity', '0');
            svg.appendChild(d);
            m.dots.push(d);
        });
        host.textContent = '';
        host.appendChild(svg);
        marks.push(m);
        draw(m, 0);
        if (!raf && !reduce) raf = global.requestAnimationFrame(frame);
        return {
            lock: function (flags) {
                m.lock = flags === true ? [true, true, true] : [!!flags[0], !!flags[1], !!flags[2]];
                if (reduce) { m.mix = m.lock.map(function (x) { return x ? 1 : 0; }); draw(m, 0); }
            },
            unlock: function () { m.lock = [false, false, false]; if (reduce) { m.mix = [0, 0, 0]; draw(m, 0); } },
            busy: function (on) { m.speed = on ? 2.6 : 1; }
        };
    }

    function draw(m, t) {
        var done = m.lock[0] && m.lock[1] && m.lock[2];
        for (var i = 0; i < 3; i++) {
            var f = free(i, t), k = ease(m.mix[i]), l = m.lines[i];
            l.setAttribute('x1', lerp(f[0][0], SIDES[i][0][0], k).toFixed(1));
            l.setAttribute('y1', lerp(f[0][1], SIDES[i][0][1], k).toFixed(1));
            l.setAttribute('x2', lerp(f[1][0], SIDES[i][1][0], k).toFixed(1));
            l.setAttribute('y2', lerp(f[1][1], SIDES[i][1][1], k).toFixed(1));
            l.setAttribute('class', m.lock[i] ? 'is-locked' : 'is-free');
            l.setAttribute('opacity', m.lock[i] ? '1' : String(0.6 + 0.35 * Math.abs(Math.sin(t * 1.8 + i))));
        }
        m.dots.forEach(function (d) { d.setAttribute('opacity', done ? '1' : '0'); });
    }

    function frame(ms) {
        var dt = last ? Math.min(0.05, (ms - last) / 1000) : 0.016;
        last = ms;
        if (!global.document.hidden) {
            marks = marks.filter(function (m) { return m.svg.isConnected; });
            marks.forEach(function (m) {
                m.clock += dt * m.speed;
                for (var i = 0; i < 3; i++) {
                    var g = m.lock[i] ? 1 : 0;
                    m.mix[i] += (g - m.mix[i]) * Math.min(1, dt * 6);
                    if (Math.abs(g - m.mix[i]) < 0.002) m.mix[i] = g;
                }
                draw(m, m.clock);
            });
        }
        raf = marks.length ? global.requestAnimationFrame(frame) : 0;
    }

    global.PennyMark = { create: create };
})(window);
