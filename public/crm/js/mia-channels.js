/**
 * Mia's card → Channels: Google Business Profile (reviews to answer, this week's post),
 * social (Facebook connection, drafts from crew photos), the website (Search Console insight)
 * and listings. Mia drafts; Tim copies or clicks Post — nothing goes out on its own.
 * While Google hasn't approved API access, everything is "drafts only": Copy, then paste into Google.
 * API: /crm/api/mia-channels.php (?mode=summary; POST prepare / review_* / post_*).
 */
(function () {
    'use strict';
    var box = document.getElementById('mw-mia-chan');
    if (!box) return;
    var API = '/crm/api/mia-channels.php';
    var data = null, busy = false, prepared = false;

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function get(mode) {
        return fetch(API + '?mode=' + mode, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); });
    }
    function post(body) {
        body.csrf_token = window.MW_CSRF_TOKEN || '';
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { return r.json(); });
    }
    function copy(text, btn) {
        var done = function () { var t = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = t; }, 1500); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { fallback(); });
        } else { fallback(); }
        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = text; ta.setAttribute('readonly', ''); ta.className = 'mw-mia-ch-offscreen';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) {}
            ta.remove();
        }
    }
    function stars(n) { var s = ''; for (var i = 1; i <= 5; i++) s += i <= n ? '★' : '☆'; return s; }

    function load() {
        get('summary').then(function (r) {
            if (!r || !r.ok) { box.hidden = true; return; }
            data = r.channels;
            render();
            if (!prepared && data.ready && document.visibilityState === 'visible') {
                prepared = true;
                // At most once a week server-side; drafts this week's post and reads the website.
                post({ mode: 'prepare' }).then(function (p) { if (p && p.ok && !p.skipped) load(); }).catch(function () {});
            }
        }).catch(function () { box.hidden = true; });
    }

    function render(msg) {
        var d = data;
        box.hidden = false;
        var g = d.google, s = d.social, w = d.website, l = d.listings;
        var live = g.mode === 'live';
        var html = '<div class="mw-mia-ch-head"><b>Channels</b><span>Google · social · website · listings</span></div>';

        // Facebook first when it's broken — nothing posts until it's fixed.
        if (s.needs_reconnect) {
            html += '<div class="mw-mia-ch-alert" role="alert"><b>Reconnect Facebook.</b> ' + esc(s.message.replace(/^Reconnect Facebook — /, '')) +
                ' <a class="mw-mia-ch-btn is-primary" href="' + esc(s.reconnect_url) + '">Reconnect Facebook</a></div>';
        }

        // ── Google ───────────────────────────────────────────────────────
        html += '<section class="mw-mia-ch-sec"><div class="mw-mia-ch-title">Google Business Profile ' +
            '<span class="mw-mia-ch-pill ' + (live ? 'is-ok' : 'is-wait') + '">' + (live ? 'Connected' : 'Drafts only') + '</span></div>' +
            '<p class="mw-mia-ch-note">' + esc(g.reason) + '</p>';
        if (!live) {
            html += '<details class="mw-mia-ch-steps"><summary>Connect Google Business Profile — ' + g.steps.filter(function (x) { return x.done; }).length + ' of ' + g.steps.length + ' done</summary><ol>' +
                g.steps.map(function (x) {
                    return '<li class="' + (x.done ? 'is-done' : '') + '">' + esc(x.text) + (x.url ? ' <a href="' + esc(x.url) + '" target="_blank" rel="noopener">Open</a>' : '') + '</li>';
                }).join('') + '</ol></details>';
        }
        if (!d.ready) {
            html += '<p class="mw-mia-ch-note">Run migrations 1200–1202 to switch on reviews, post drafts and the website report.</p>';
        } else {
            html += '<div class="mw-mia-ch-sub">Reviews waiting for a reply · <b>' + g.waiting + '</b></div>';
            if (!g.reviews.length) html += '<p class="mw-mia-ch-note">None waiting. Paste a new review below and I\'ll draft the reply.</p>';
            g.reviews.forEach(function (r) {
                var canPost = live && r.source === 'api';
                html += '<div class="mw-mia-ch-item" data-review="' + r.id + '">' +
                    '<div class="mw-mia-ch-rv"><span class="mw-mia-ch-stars" aria-label="' + r.rating + ' stars">' + stars(+r.rating) + '</span> <b>' + esc(r.reviewer_name || 'A reviewer') + '</b>' +
                    (r.review_date ? ' <small>' + esc(r.review_date) + '</small>' : '') + '</div>' +
                    (r.comment ? '<blockquote>' + esc(r.comment) + '</blockquote>' : '') +
                    '<label class="mw-mia-lbl">My reply<textarea class="mw-mia-in" rows="3" data-f="reply">' + esc(r.reply_draft) + '</textarea></label>' +
                    (r.violations && r.violations.length ? '<div class="mw-mia-ch-warn">Check: ' + esc(r.violations.join(', ').replace(/_/g, ' ')) + '</div>' : '') +
                    '<div class="mw-mia-actions">' +
                      '<button type="button" class="mw-mia-ch-btn" data-do="copy-reply">Copy</button>' +
                      '<button type="button" class="mw-mia-ch-btn" data-do="review-done">I posted it</button>' +
                      (canPost ? '<button type="button" class="mw-mia-ch-btn is-primary" data-do="review-post">Post reply to Google</button>' : '') +
                      '<button type="button" class="mw-mia-skip" data-do="review-dismiss">No reply needed</button>' +
                    '</div></div>';
            });
            html += '<details class="mw-mia-ch-add"><summary>Paste a review</summary>' +
                '<div class="mw-mia-ch-form"><input class="mw-mia-in" data-a="reviewer_name" placeholder="Reviewer name (as on Google)">' +
                '<select class="mw-mia-in" data-a="rating"><option value="5">5 stars</option><option value="4">4 stars</option><option value="3">3 stars</option><option value="2">2 stars</option><option value="1">1 star</option></select>' +
                '<textarea class="mw-mia-in" rows="3" data-a="comment" placeholder="What they wrote"></textarea>' +
                '<button type="button" class="mw-mia-ch-btn is-primary" data-do="review-add">Add and draft a reply</button></div></details>';

            // This week's post
            var p = g.post;
            html += '<div class="mw-mia-ch-sub">This week\'s Google post' + (g.theme ? ' · ' + esc(g.theme.title) : '') + '</div>';
            if (!p) {
                html += '<p class="mw-mia-ch-note">No post drafted yet — I draft one each Monday.</p>';
            } else {
                html += '<div class="mw-mia-ch-item" data-post="' + p.id + '">' +
                    (p.photo_url ? '<img class="mw-mia-ch-photo" src="' + esc(p.photo_url) + '" alt="Photo picked for this post">' : '<p class="mw-mia-ch-note">No safe photo picked yet (photos need the privacy check first).</p>') +
                    '<label class="mw-mia-lbl">Post<textarea class="mw-mia-in" rows="5" data-f="post">' + esc(p.body) + '</textarea></label>' +
                    '<div class="mw-mia-ch-note">Button: ' + esc(p.cta_type || 'none') + (p.cta_url ? ' → ' + esc(p.cta_url) : '') + '. No phone number or link in the text — Google rejects those.</div>' +
                    '<div class="mw-mia-actions">' +
                      '<button type="button" class="mw-mia-ch-btn" data-do="copy-post">Copy</button>' +
                      '<button type="button" class="mw-mia-ch-btn" data-do="post-copied">I posted it</button>' +
                      (live ? '<button type="button" class="mw-mia-ch-btn is-primary" data-do="post-publish">Post to Google</button>' : '') +
                      '<button type="button" class="mw-mia-skip" data-do="post-dismiss">Skip this week</button>' +
                    '</div></div>';
            }
        }
        html += '</section>';

        // ── Social ───────────────────────────────────────────────────────
        var fb = s.facebook, ig = s.instagram;
        html += '<section class="mw-mia-ch-sec"><div class="mw-mia-ch-title">Social ' +
            pill(fb, 'Facebook') + ' ' + pill(ig, 'Instagram') + '</div>';
        if (s.drafts && s.drafts.length) {
            html += '<div class="mw-mia-ch-sub">Drafted from crew photos · waiting for your OK</div><ul class="mw-mia-ch-list">' +
                s.drafts.map(function (x) {
                    return '<li>' + esc(x.title) + ' <a href="/crm/marketing/social-post-editor.php?id=' + (+x.social_post_id) + '">Review</a></li>';
                }).join('') + '</ul>';
        } else {
            html += '<p class="mw-mia-ch-note">No drafts waiting. Each Monday I draft up to two posts from this season\'s before-and-after photos.</p>';
        }
        if (s.best) html += '<p class="mw-mia-ch-note">Best lately: ' + esc(s.best.title || s.best.service_type || 'a post') + ' — ' + (+s.best.engagement || 0) + ' likes, comments and saves.</p>';
        html += '</section>';

        // ── Website ──────────────────────────────────────────────────────
        html += '<section class="mw-mia-ch-sec"><div class="mw-mia-ch-title">Website</div>' +
            '<p class="mw-mia-ch-insight">' + esc(w.headline) + '</p>';
        if (w.suggestions && w.suggestions.length) {
            html += '<ul class="mw-mia-ch-list">' + w.suggestions.map(function (x) {
                return '<li>' + esc(x.text) + ' <a href="' + esc(x.cms_url || '/crm/cms-pages_appstack.php') + '">Open in CMS</a></li>';
            }).join('') + '</ul><p class="mw-mia-ch-note">Suggestions only — I never change a page.</p>';
        }
        if (w.top && w.top.length) {
            html += '<div class="mw-mia-ch-sub">Top searches (28 days)</div><div class="mw-mia-ch-tags">' + w.top.map(function (q) {
                return '<span>' + esc(q.query) + ' <b>' + q.clicks + '</b></span>';
            }).join('') + '</div>';
        }
        html += '</section>';

        // ── Listings ─────────────────────────────────────────────────────
        if (l) {
            html += '<section class="mw-mia-ch-sec"><div class="mw-mia-ch-title">Listings</div><p class="mw-mia-ch-note">' +
                l.verified + ' verified · ' + l.claimed + ' claimed · ' + (l.not_claimed + l.unknown) + ' to check' +
                (l.mismatches ? ' · <b>' + l.mismatches + ' with details that don\'t match</b>' : '') +
                ' <a href="' + esc(l.url) + '">Open listings</a></p></section>';
        }
        if (msg) html += '<div class="mw-mia-msg">' + esc(msg) + '</div>';
        box.innerHTML = html;
    }

    function pill(st, name) {
        var cls = st.status === 'connected' ? 'is-ok' : (st.status === 'reconnect' ? 'is-bad' : 'is-wait');
        var label = st.status === 'connected' ? 'connected' : (st.status === 'reconnect' ? 'reconnect' : (st.status === 'paused' ? 'paused' : 'not connected'));
        return '<span class="mw-mia-ch-pill ' + cls + '">' + name + ': ' + label + '</span>';
    }

    function after(r, okMsg) {
        busy = false;
        if (!r || !r.ok) { render((r && r.error) || 'That didn\'t work — nothing was posted.'); return; }
        get('summary').then(function (s) { if (s && s.ok) data = s.channels; render(okMsg); });
    }

    box.addEventListener('click', function (e) {
        var b = e.target.closest('[data-do]');
        if (!b || busy) return;
        var act = b.getAttribute('data-do');
        var rv = b.closest('[data-review]'), pt = b.closest('[data-post]');
        var reply = rv ? rv.querySelector('[data-f="reply"]').value : '';
        var body = pt ? pt.querySelector('[data-f="post"]').value : '';
        if (act === 'copy-reply') { copy(reply, b); post({ mode: 'review_save', id: +rv.getAttribute('data-review'), text: reply }); return; }
        if (act === 'copy-post') { copy(body, b); post({ mode: 'post_save', id: +pt.getAttribute('data-post'), body: body }); return; }
        if ((act === 'review-post' || act === 'post-publish') && !b.classList.contains('is-armed')) {
            b.classList.add('is-armed');
            var was = b.textContent;
            b.textContent = 'Tap again to post publicly';
            setTimeout(function () { if (b.isConnected) { b.classList.remove('is-armed'); b.textContent = was; } }, 6000);
            return;
        }
        busy = true;
        b.disabled = true;
        if (act === 'review-done') post({ mode: 'review_done', id: +rv.getAttribute('data-review'), text: reply }).then(function (r) { after(r, 'Marked as replied.'); });
        else if (act === 'review-post') post({ mode: 'review_post', id: +rv.getAttribute('data-review'), text: reply }).then(function (r) { after(r, 'Reply posted to Google.'); });
        else if (act === 'review-dismiss') post({ mode: 'review_dismiss', id: +rv.getAttribute('data-review') }).then(function (r) { after(r, 'Left without a reply.'); });
        else if (act === 'post-copied') post({ mode: 'post_decide', id: +pt.getAttribute('data-post'), action: 'copied', body: body }).then(function (r) { after(r, 'Noted — posted by you.'); });
        else if (act === 'post-publish') post({ mode: 'post_decide', id: +pt.getAttribute('data-post'), action: 'publish', body: body }).then(function (r) { after(r, 'Posted to Google.'); });
        else if (act === 'post-dismiss') post({ mode: 'post_decide', id: +pt.getAttribute('data-post'), action: 'dismiss' }).then(function (r) { after(r, 'Skipped this week\'s post.'); });
        else if (act === 'review-add') {
            var f = b.closest('.mw-mia-ch-form');
            var val = function (k) { return f.querySelector('[data-a="' + k + '"]').value; };
            post({ mode: 'review_add', reviewer_name: val('reviewer_name'), rating: +val('rating'), comment: val('comment') })
                .then(function (r) { after(r, 'Added. My reply is drafted above.'); });
        } else { busy = false; b.disabled = false; }
    });

    load();
})();
