/**
 * Contact page — "Team on this client" + "Recent conversation" (2026-10-07).
 *
 * Both cards are in the page hidden; this fills them from /crm/api/contact-team.php
 * (?mode=team | ?mode=comms). Any failure leaves them hidden — the page never breaks.
 * Read-only: every line is text (textContent), every link a plain <a>.
 */
(function () {
  'use strict';

  var API = '/crm/api/contact-team.php';
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var ICONS = { email: 'mail', sms: 'message-square', quote: 'file-text', invoice: 'file', payment: 'dollar-sign' };
  var CHANNELS = { email: 'Email', sms: 'Text', quote: 'Quote', invoice: 'Invoice', payment: 'Payment' };

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined && text !== null) n.textContent = text;
    return n;
  }

  function load(mode, id) {
    return fetch(API + '?mode=' + mode + '&contact_id=' + encodeURIComponent(id), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) { if (!d || !d.ok) throw new Error('not ok'); return d; });
  }

  function icons() {
    if (window.feather && typeof window.feather.replace === 'function') window.feather.replace();
  }

  function when(at) {
    var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(at || '');
    if (!m) return '';
    var d = MONTHS[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10);
    var now = new Date();
    if (parseInt(m[1], 10) !== now.getFullYear()) d += ', ' + m[1];
    if (m[4] && !(m[4] === '12' && m[5] === '00')) {
      var h = parseInt(m[4], 10);
      d += ' · ' + ((h % 12) || 12) + ':' + m[5] + (h < 12 ? 'am' : 'pm');
    }
    return d;
  }

  // ── Team on this client ────────────────────────────────────────────────
  function teamItem(it) {
    var li = el('li', 'mw-ct-item mw-ct-g' + it.group);
    var img = el('img', 'mw-ct-face');
    img.src = '/crm/img/heads/' + it.head + '.jpg';
    img.alt = '';
    img.width = 36;
    img.height = 36;
    li.appendChild(img);
    var body = el('div', 'mw-ct-body');
    var who = el('div', 'mw-ct-who');
    who.appendChild(el('strong', null, it.name));
    who.appendChild(el('span', 'mw-ct-role', it.role));
    body.appendChild(who);
    body.appendChild(el('div', 'mw-ct-text', it.text));
    if (it.link && it.link.url) {
      var a = el('a', 'mw-ct-link', it.link.label + ' →');
      a.href = it.link.url;
      body.appendChild(a);
    }
    li.appendChild(body);
    return li;
  }

  function team(card, id) {
    load('team', id).then(function (d) {
      if (!d.items || !d.items.length) return;
      var list = card.querySelector('.mw-ct-list');
      d.items.forEach(function (it) { list.appendChild(teamItem(it)); });
      var more = card.querySelector('.mw-ct-more');
      if (d.more > 0 && d.rest && d.rest.length) {
        more.textContent = '+' + d.more + ' more';
        more.hidden = false;
        more.addEventListener('click', function () {
          d.rest.forEach(function (it) { list.appendChild(teamItem(it)); });
          more.hidden = true;
        });
      }
      card.hidden = false;
    }).catch(function () { /* stay hidden */ });
  }

  // ── Recent conversation ────────────────────────────────────────────────
  function direction(e, first) {
    var them = first || 'Them';
    var us = e.who || 'Us';
    if (e.dir === 'in') return them + ' → us';
    if (e.dir === 'out') return us + ' → ' + (first || 'them');
    return '';
  }

  function commsEntry(e, first) {
    var li = el('li', 'mw-ct-ev ' + (e.dir === 'in' ? 'is-in' : 'is-out'));
    var ic = el('span', 'mw-ct-ic');
    var i = el('i');
    i.setAttribute('data-feather', ICONS[e.channel] || 'circle');
    ic.appendChild(i);
    li.appendChild(ic);
    var body = el('div', 'mw-ct-evbody');
    body.appendChild(el('div', 'mw-ct-meta', [when(e.at), direction(e, first), CHANNELS[e.channel] || e.channel].filter(Boolean).join(' · ')));
    var sum = el('div', 'mw-ct-sum', e.summary);
    body.appendChild(sum);
    if (e.url) {
      var a = el('a', 'mw-ct-link', 'Open →');
      a.href = e.url;
      body.appendChild(a);
    }
    li.appendChild(body);
    return li;
  }

  function comms(card, id) {
    load('comms', id).then(function (d) {
      var list = card.querySelector('.mw-ct-timeline');
      var btn = card.querySelector('.mw-ct-showmore');
      var entries = d.entries || [];
      var page = d.page || 15;
      var shown = 0;
      if (!entries.length) {
        list.appendChild(el('li', 'mw-ct-empty', 'Nothing with them in the last ' + (d.days || 90) + ' days.'));
      }
      function more() {
        entries.slice(shown, shown + page).forEach(function (e) { list.appendChild(commsEntry(e, d.first_name)); });
        shown = Math.min(entries.length, shown + page);
        btn.hidden = shown >= entries.length;
        icons();
      }
      btn.addEventListener('click', more);
      more();
      card.hidden = false;
      icons();
    }).catch(function () { /* stay hidden */ });
  }

  function start() {
    var t = document.getElementById('mw-ct-team');
    var c = document.getElementById('mw-ct-comms');
    if (t && t.dataset.contactId) team(t, t.dataset.contactId);
    if (c && c.dataset.contactId) comms(c, c.dataset.contactId);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
