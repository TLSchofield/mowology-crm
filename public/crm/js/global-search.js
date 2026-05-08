/**
 * Global Search — Spotlight / Command Palette
 *
 * Open:  Cmd+K / Ctrl+K  OR  click the trigger button  OR  just start typing
 * Close: Esc  OR  click outside
 * Navigate: ↑ ↓ arrows, Enter goes to first result if none highlighted
 */
(function() {
  'use strict';

  var overlay = document.getElementById('mwSpotlight');
  var input   = document.getElementById('mwSpotlightInput');
  var body    = document.getElementById('mwSpotlightBody');

  if (!overlay || !input) return;

  var debounceTimer  = null;
  var activeIndex    = -1;
  var currentResults = [];
  var abortCtrl      = null;

  // ── Category meta ─────────────────────────────────
  var categoryMeta = {
    'Contacts':   { icon: 'user',        color: '#2D8659' },
    'Companies':  { icon: 'briefcase',   color: '#0d6efd' },
    'Properties': { icon: 'map-pin',     color: '#17a2b8' },
    'Quotes':     { icon: 'file-text',   color: '#4a90d9' },
    'Jobs':       { icon: 'briefcase',   color: '#e85d04' },
    'Invoices':   { icon: 'credit-card', color: '#7c3aed' },
    'Team':       { icon: 'users',       color: '#6c757d' }
  };

  // ── Avatar helpers ────────────────────────────────
  // Categories that get initials-based circular avatars (like Jobber)
  var avatarCategories = { 'Contacts': true, 'Companies': true, 'Team': true };

  // Deterministic color from name — stays consistent across searches
  var avatarPalette = [
    '#2D8659', '#1A5F4A', '#4a90d9', '#7c3aed',
    '#e85d04', '#17a2b8', '#d97706', '#0891b2',
    '#be185d', '#0d6efd'
  ];

  function avatarColor(name) {
    var code = 0;
    for (var i = 0; i < Math.min(name.length, 4); i++) {
      code += name.charCodeAt(i);
    }
    return avatarPalette[code % avatarPalette.length];
  }

  function avatarInitials(name) {
    var parts = name.trim().split(/\s+/).filter(Boolean);
    if (parts.length >= 2) {
      return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }
    return name.slice(0, 2).toUpperCase();
  }

  // ── Open / Close ──────────────────────────────────
  function open(prefill) {
    overlay.classList.add('mw-spotlight--open');
    document.body.style.overflow = 'hidden';
    if (prefill) {
      input.value = prefill;
      // Put cursor at end
      input.setSelectionRange(prefill.length, prefill.length);
    } else {
      input.value = '';
    }
    // Defer focus by one frame so the browser processes visibility: visible
    // before we call focus() — otherwise the first click lands on the overlay
    // and a second click is needed to land in the input.
    requestAnimationFrame(function() { input.focus(); });
    if (prefill && prefill.length >= 2) {
      showLoading();
      fetchResults(prefill);
    } else if (prefill && prefill.length === 1) {
      showRecent(); // show recent, search will fire when they type the 2nd char
    } else {
      showRecent();
    }
    activeIndex = -1;
  }

  function close() {
    overlay.classList.remove('mw-spotlight--open');
    document.body.style.overflow = '';
    input.value = '';
    body.innerHTML = '';
    if (abortCtrl) abortCtrl.abort();
  }

  function isOpen() {
    return overlay.classList.contains('mw-spotlight--open');
  }

  // ── "Just start typing" — any printable key opens spotlight ───
  // Only fires when no input/textarea/select/contenteditable is focused
  document.addEventListener('keydown', function(e) {
    // Cmd/Ctrl+K: toggle
    if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
      e.preventDefault();
      isOpen() ? close() : open();
      return;
    }

    // Esc: close
    if (e.key === 'Escape' && isOpen()) {
      e.preventDefault();
      close();
      return;
    }

    // Already open — let the overlay input handle everything
    if (isOpen()) return;

    // Modifier combos (except shift alone) — don't steal
    if (e.metaKey || e.ctrlKey || e.altKey) return;

    // Only printable single characters (letters, digits, common symbols)
    if (e.key.length !== 1) return;

    // Don't steal from existing inputs, textareas, selects, or contenteditable
    var tag = document.activeElement && document.activeElement.tagName;
    var ce  = document.activeElement && document.activeElement.isContentEditable;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || ce) return;

    // Open spotlight with this character pre-filled
    e.preventDefault();
    open(e.key);
  });

  // ── Click outside to close ────────────────────────
  overlay.addEventListener('mousedown', function(e) {
    if (e.target === overlay) close();
  });

  // ── Trigger buttons (click) ───────────────────────
  document.querySelectorAll('[data-spotlight-open]').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      open();
    });
  });

  // ── Overlay input: typing ─────────────────────────
  input.addEventListener('input', function() {
    clearTimeout(debounceTimer);
    var q = input.value.trim();

    if (q.length < 2) {
      showRecent();
      activeIndex = -1;
      return;
    }

    showLoading();
    debounceTimer = setTimeout(function() { fetchResults(q); }, 120);
  });

  // ── Overlay input: arrow keys + Enter ────────────
  input.addEventListener('keydown', function(e) {
    var items = body.querySelectorAll('.mw-spotlight-item');

    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (items.length) {
        activeIndex = Math.min(activeIndex + 1, items.length - 1);
        highlightItem(items);
      }
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (items.length) {
        activeIndex = Math.max(activeIndex - 1, 0);
        highlightItem(items);
      }
    } else if (e.key === 'Enter') {
      e.preventDefault();
      // Navigate to highlighted item, OR first item if none highlighted
      if (items.length) {
        var idx = activeIndex >= 0 ? activeIndex : 0;
        var url = items[idx] && items[idx].dataset.url;
        if (url) {
          saveRecent(items[idx].dataset.label, url, items[idx].dataset.category);
          window.location.href = url;
        }
      }
    }
  });

  function highlightItem(items) {
    items.forEach(function(item, i) {
      item.classList.toggle('mw-spotlight-item--active', i === activeIndex);
    });
    if (items[activeIndex]) {
      items[activeIndex].scrollIntoView({ block: 'nearest' });
    }
  }

  // ── Fetch results ─────────────────────────────────
  function fetchResults(q) {
    if (abortCtrl) abortCtrl.abort();
    abortCtrl = new AbortController();

    fetch('/crm/api/global-search.php?q=' + encodeURIComponent(q), {
      signal: abortCtrl.signal
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (!data.success) return;
      currentResults = data.results || [];
      activeIndex = -1;
      renderResults(currentResults, q);
    })
    .catch(function(err) {
      if (err.name !== 'AbortError') {
        body.innerHTML = '<div class="mw-spotlight-empty">Search failed. Try again.</div>';
      }
    });
  }

  // ── Render results ────────────────────────────────
  function renderResults(results, query) {
    if (!results.length) {
      body.innerHTML = '<div class="mw-spotlight-empty">' +
        '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.3;margin-bottom:8px"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>' +
        '<div>No results for "<strong>' + escapeHtml(query) + '</strong>"</div>' +
        '<div class="mw-spotlight-empty-hint">Try a different search term</div>' +
        '</div>';
      return;
    }

    var groups = {};
    results.forEach(function(r) {
      if (!groups[r.category]) groups[r.category] = [];
      groups[r.category].push(r);
    });

    var html = '';
    var itemIdx = 0;
    var categoryOrder = ['Contacts', 'Companies', 'Properties', 'Quotes', 'Jobs', 'Invoices', 'Team'];

    categoryOrder.forEach(function(cat) {
      if (!groups[cat]) return;
      var meta = categoryMeta[cat] || { icon: 'circle', color: '#666' };

      html += '<div class="mw-spotlight-group">';
      html += '<div class="mw-spotlight-category">';
      html += '<span class="mw-spotlight-category-dot" style="background:' + meta.color + '"></span>';
      html += escapeHtml(cat);
      html += '</div>';

      groups[cat].forEach(function(r) {
        // Use encodeURI for the href (preserves & correctly) and escapeHtml for data attrs
        html += '<a href="' + r.url.replace(/"/g, '%22') + '" class="mw-spotlight-item" ' +
                'data-url="' + escapeHtml(r.url) + '" ' +
                'data-label="' + escapeHtml(r.label) + '" ' +
                'data-category="' + escapeHtml(r.category) + '" ' +
                'data-index="' + itemIdx + '">';
        if (avatarCategories[r.category]) {
          // Initials-based circular avatar (Contacts, Team)
          html += '<span class="mw-spotlight-avatar" style="background:' + avatarColor(r.label) + '">';
          html += avatarInitials(r.label);
          html += '</span>';
        } else {
          // Icon badge for non-person categories
          html += '<span class="mw-spotlight-item-icon" style="color:' + meta.color + '">';
          html += featherIcon(r.icon || meta.icon);
          html += '</span>';
        }
        html += '<span class="mw-spotlight-item-content">';
        html += '<span class="mw-spotlight-item-label">' + highlightMatch(r.label, query) + '</span>';
        if (r.sublabel) {
          html += '<span class="mw-spotlight-item-sub">' + escapeHtml(r.sublabel) + '</span>';
        }
        html += '</span>';
        html += '<span class="mw-spotlight-item-arrow">';
        html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>';
        html += '</span>';
        html += '</a>';
        itemIdx++;
      });

      html += '</div>';
    });

    body.innerHTML = html;

    body.querySelectorAll('.mw-spotlight-item').forEach(function(item) {
      item.addEventListener('click', function() {
        saveRecent(item.dataset.label, item.dataset.url, item.dataset.category);
      });
    });
  }

  // ── Loading state ─────────────────────────────────
  function showLoading() {
    body.innerHTML =
      '<div class="mw-spotlight-loading">' +
        '<div class="mw-spotlight-pulse"></div>' +
        '<div class="mw-spotlight-pulse"></div>' +
        '<div class="mw-spotlight-pulse"></div>' +
      '</div>';
  }

  // ── Recent searches ───────────────────────────────
  function getRecent() {
    try { return JSON.parse(sessionStorage.getItem('mw_recent_searches') || '[]'); }
    catch(e) { return []; }
  }

  function saveRecent(label, url, category) {
    var recent = getRecent().filter(function(r) { return r.url !== url; });
    recent.unshift({ label: label, url: url, category: category });
    if (recent.length > 5) recent = recent.slice(0, 5);
    try { sessionStorage.setItem('mw_recent_searches', JSON.stringify(recent)); } catch(e) {}
  }

  function showRecent() {
    var recent = getRecent();
    if (!recent.length) {
      body.innerHTML =
        '<div class="mw-spotlight-hint-area">' +
          '<div class="mw-spotlight-hint-text">Search contacts, properties, quotes, jobs, invoices...</div>' +
          '<div class="mw-spotlight-shortcuts">' +
            '<span><kbd class="mw-kbd">&uarr;</kbd><kbd class="mw-kbd">&darr;</kbd> Navigate</span>' +
            '<span><kbd class="mw-kbd">Enter</kbd> Open</span>' +
            '<span><kbd class="mw-kbd">Esc</kbd> Close</span>' +
          '</div>' +
        '</div>';
      return;
    }

    var html = '<div class="mw-spotlight-group">';
    html += '<div class="mw-spotlight-category">';
    html += '<span class="mw-spotlight-category-dot" style="background:#999"></span>';
    html += 'Recent';
    html += '</div>';

    recent.forEach(function(r, i) {
      var meta = categoryMeta[r.category] || { icon: 'clock', color: '#999' };
      html += '<a href="' + escapeHtml(r.url) + '" class="mw-spotlight-item" ' +
              'data-url="' + escapeHtml(r.url) + '" ' +
              'data-label="' + escapeHtml(r.label) + '" ' +
              'data-category="' + escapeHtml(r.category || '') + '" ' +
              'data-index="' + i + '">';
      if (avatarCategories[r.category]) {
        html += '<span class="mw-spotlight-avatar" style="background:' + avatarColor(r.label) + '">';
        html += avatarInitials(r.label);
        html += '</span>';
      } else {
        html += '<span class="mw-spotlight-item-icon" style="color:' + meta.color + '">';
        html += '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>';
        html += '</span>';
      }
      html += '<span class="mw-spotlight-item-content">';
      html += '<span class="mw-spotlight-item-label">' + escapeHtml(r.label) + '</span>';
      html += '</span>';
      html += '<span class="mw-spotlight-item-arrow">';
      html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>';
      html += '</span>';
      html += '</a>';
    });

    html += '</div>';
    body.innerHTML = html;
  }

  // ── Helpers ───────────────────────────────────────
  function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function highlightMatch(text, query) {
    if (!query || !text) return escapeHtml(text);
    var escaped = escapeHtml(text);
    var re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
    return escaped.replace(re, '<mark class="mw-spotlight-mark">$1</mark>');
  }

  function featherIcon(name) {
    var icons = {
      'user':        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>',
      'map-pin':     '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>',
      'file-text':   '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>',
      'briefcase':   '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>',
      'credit-card': '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>',
      'users':       '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
      'clock':       '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>'
    };
    return icons[name] || icons['clock'];
  }

})();
