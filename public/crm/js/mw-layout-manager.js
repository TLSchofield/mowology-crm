/**
 * Mowology CRM — Card Layout Manager
 * ────────────────────────────────────
 * Lets admins drag-and-drop cards + resize their column widths
 * on any CRM page. Saves layout per-page to localStorage.
 *
 * - Admin-only (checks window.MW_USER_ROLE)
 * - Uses native HTML5 Drag & Drop (no library)
 * - Keeps Bootstrap 4 grid (rebuilds .row > .col-md-{span})
 * - Non-card content (headings, breadcrumbs) stays untouched
 */
(function () {
  'use strict';

  // ── Gate: admin only ──────────────────────────────────────────
  if (window.MW_USER_ROLE !== 'admin') return;

  // ── Constants ─────────────────────────────────────────────────
  var STORAGE_PREFIX = 'mw-layout:';
  var SPAN_OPTIONS = [2, 3, 4, 6, 8, 12];

  // ── State ─────────────────────────────────────────────────────
  var pageKey = STORAGE_PREFIX + window.location.pathname;
  var editing = false;
  var cardMap = {};      // id → {element, span}
  var layoutOrder = [];  // [{id, span}]
  var container = null;  // .container-fluid
  var nonCardNodes = []; // preserved non-row content
  var draggedId = null;

  // ── Boot ──────────────────────────────────────────────────────
  document.addEventListener('DOMContentLoaded', init);

  function init() {
    container = document.querySelector('main.content > .container-fluid');
    if (!container) return;

    // Detect current layout from Bootstrap grid
    var detected = detectLayout();
    if (detected.length === 0) return;

    // Preserve non-row content (headings, breadcrumbs, etc.)
    preserveNonRowContent();

    // Check for saved layout
    var saved = loadSaved();
    if (saved) {
      // Merge: use saved order/spans, but handle new/removed cards
      layoutOrder = mergeSavedWithDetected(saved, detected);
    } else {
      layoutOrder = detected;
    }

    // Build cardMap
    buildCardMap();

    // Apply saved layout if one exists
    if (saved) {
      rebuildGrid();
    }

    // Add the edit toggle button
    addEditToggle();
  }

  // ── Detect current Bootstrap layout ───────────────────────────
  function detectLayout() {
    var layout = [];
    var rows = container.querySelectorAll(':scope > .row');

    rows.forEach(function (row) {
      var cols = row.querySelectorAll(':scope > [class*="col-"]');
      cols.forEach(function (col) {
        var cards = [];
        for (var i = 0; i < col.children.length; i++) {
          if (col.children[i].classList.contains('card')) {
            cards.push(col.children[i]);
          }
        }
        var span = parseColSpan(col);
        cards.forEach(function (card, idx) {
          var id = deriveCardId(card, layout.length);
          card.setAttribute('data-mw-card-id', id);
          // If multiple cards in one col, give each the full col span
          layout.push({ id: id, span: span });
        });
      });
    });

    return layout;
  }

  function parseColSpan(col) {
    var cls = col.className;
    // Try lg first, then md, then sm, then plain col-N
    var match = cls.match(/col-lg-(\d+)/) ||
                cls.match(/col-xl-(\d+)/) ||
                cls.match(/col-md-(\d+)/) ||
                cls.match(/col-sm-(\d+)/) ||
                cls.match(/col-(\d+)(?!\w)/);
    if (match) return parseInt(match[1], 10);
    // col with no number = auto (treat as 12)
    return 12;
  }

  function deriveCardId(card, fallbackIndex) {
    var titleEl = card.querySelector('.card-title, .card-header h5, .card-header h6');
    if (titleEl) {
      var text = titleEl.textContent.trim();
      if (text.length > 0 && text.length < 60) {
        return slugify(text);
      }
    }
    return 'card-' + fallbackIndex;
  }

  function slugify(text) {
    return text.toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-|-$/g, '')
      .substring(0, 40);
  }

  // ── Preserve non-row content ──────────────────────────────────
  function preserveNonRowContent() {
    nonCardNodes = [];
    var children = Array.prototype.slice.call(container.children);
    children.forEach(function (child) {
      if (!child.classList.contains('row')) {
        nonCardNodes.push({ element: child, isBeforeFirstRow: true });
      }
    });
    // Actually track position relative to rows
    nonCardNodes = [];
    var sawRow = false;
    children.forEach(function (child, idx) {
      if (child.classList.contains('row')) {
        sawRow = true;
      } else {
        nonCardNodes.push({ element: child, index: idx });
      }
    });
  }

  // ── Build card map from current DOM ───────────────────────────
  function buildCardMap() {
    cardMap = {};
    var allCards = container.querySelectorAll('.card[data-mw-card-id]');
    allCards.forEach(function (card) {
      var id = card.getAttribute('data-mw-card-id');
      cardMap[id] = card;
    });
  }

  // ── Merge saved layout with detected (handle new/removed cards)
  function mergeSavedWithDetected(saved, detected) {
    var detectedIds = {};
    detected.forEach(function (item) { detectedIds[item.id] = item; });

    var merged = [];
    var usedIds = {};

    // First: add saved items that still exist in DOM
    saved.forEach(function (item) {
      if (detectedIds[item.id]) {
        merged.push({ id: item.id, span: item.span });
        usedIds[item.id] = true;
      }
    });

    // Then: append new cards not in saved layout
    detected.forEach(function (item) {
      if (!usedIds[item.id]) {
        merged.push({ id: item.id, span: item.span });
      }
    });

    return merged;
  }

  // ── Rebuild Bootstrap grid from layoutOrder ───────────────────
  function rebuildGrid() {
    // Remove existing rows
    var rows = container.querySelectorAll(':scope > .row');
    rows.forEach(function (row) { row.remove(); });

    // Build new rows, filling up to 12 cols per row
    var currentRow = createRow();
    var currentWidth = 0;

    layoutOrder.forEach(function (item) {
      var card = cardMap[item.id];
      if (!card) return; // card no longer in DOM

      if (currentWidth + item.span > 12 && currentWidth > 0) {
        container.appendChild(currentRow);
        currentRow = createRow();
        currentWidth = 0;
      }

      var col = document.createElement('div');
      col.className = 'col-md-' + item.span + ' mb-3';
      col.appendChild(card);
      currentRow.appendChild(col);
      currentWidth += item.span;
    });

    // Append last row if it has content
    if (currentRow.children.length > 0) {
      container.appendChild(currentRow);
    }

    // Re-run feather icons if available
    if (window.feather) {
      try { feather.replace(); } catch (e) {}
    }
  }

  function createRow() {
    var row = document.createElement('div');
    row.className = 'row';
    return row;
  }

  // ── Edit mode toggle ──────────────────────────────────────────
  function addEditToggle() {
    var btn = document.createElement('button');
    btn.className = 'mw-layout-toggle';
    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>' +
      '<rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>' +
      ' <span class="mw-layout-toggle-label">Edit Layout</span>';
    btn.title = 'Drag cards and resize columns';
    btn.addEventListener('click', function () {
      if (editing) {
        exitEditMode();
      } else {
        enterEditMode();
      }
    });
    document.body.appendChild(btn);
  }

  // ── Enter edit mode ───────────────────────────────────────────
  function enterEditMode() {
    editing = true;
    document.body.classList.add('mw-layout-editing');

    // Update toggle button
    var toggleBtn = document.querySelector('.mw-layout-toggle');
    if (toggleBtn) {
      toggleBtn.classList.add('mw-layout-toggle-active');
      toggleBtn.querySelector('.mw-layout-toggle-label').textContent = 'Editing...';
    }

    // Add toolbar
    addToolbar();

    // Inject drag handles + width controls into each card
    layoutOrder.forEach(function (item) {
      var card = cardMap[item.id];
      if (!card) return;
      injectEditUI(card, item);
    });
  }

  function exitEditMode() {
    editing = false;
    document.body.classList.remove('mw-layout-editing');

    // Update toggle button
    var toggleBtn = document.querySelector('.mw-layout-toggle');
    if (toggleBtn) {
      toggleBtn.classList.remove('mw-layout-toggle-active');
      toggleBtn.querySelector('.mw-layout-toggle-label').textContent = 'Edit Layout';
    }

    // Remove toolbar
    var toolbar = document.querySelector('.mw-layout-toolbar');
    if (toolbar) toolbar.remove();

    // Remove all edit UI from cards
    document.querySelectorAll('.mw-card-edit-ui').forEach(function (el) {
      el.remove();
    });

    // Remove draggable attribute
    document.querySelectorAll('.card[draggable]').forEach(function (card) {
      card.removeAttribute('draggable');
      card.classList.remove('mw-card-editable');
    });
  }

  // ── Toolbar (Save / Reset / Cancel) ───────────────────────────
  function addToolbar() {
    var toolbar = document.createElement('div');
    toolbar.className = 'mw-layout-toolbar';

    var saveBtn = document.createElement('button');
    saveBtn.className = 'btn btn-sm btn-success mr-2';
    saveBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save Layout';
    saveBtn.addEventListener('click', function () {
      saveLayout();
      exitEditMode();
      showToast('Layout saved');
    });

    var resetBtn = document.createElement('button');
    resetBtn.className = 'btn btn-sm btn-outline-secondary mr-2';
    resetBtn.textContent = 'Reset to Default';
    resetBtn.addEventListener('click', function () {
      clearSaved();
      exitEditMode();
      window.location.reload();
    });

    var cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-sm btn-outline-secondary';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.addEventListener('click', function () {
      // Revert to last saved state
      var saved = loadSaved();
      if (saved) {
        layoutOrder = mergeSavedWithDetected(saved, layoutOrder);
        rebuildGrid();
      }
      exitEditMode();
    });

    toolbar.appendChild(saveBtn);
    toolbar.appendChild(resetBtn);
    toolbar.appendChild(cancelBtn);

    // Insert at top of container
    container.insertBefore(toolbar, container.firstChild);
  }

  // ── Inject edit UI into a card ────────────────────────────────
  function injectEditUI(card, item) {
    card.classList.add('mw-card-editable');
    card.setAttribute('draggable', 'true');

    // Create edit bar
    var editBar = document.createElement('div');
    editBar.className = 'mw-card-edit-ui';

    // Drag handle
    var handle = document.createElement('span');
    handle.className = 'mw-card-drag-handle';
    handle.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="5" r="1.5"/><circle cx="15" cy="5" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="19" r="1.5"/><circle cx="15" cy="19" r="1.5"/></svg>';
    handle.title = 'Drag to reorder';

    // Width controls
    var widthGroup = document.createElement('span');
    widthGroup.className = 'mw-card-width-controls';

    SPAN_OPTIONS.forEach(function (spanVal) {
      var btn = document.createElement('button');
      btn.className = 'mw-card-width-btn' + (item.span === spanVal ? ' active' : '');
      btn.textContent = spanVal;
      btn.title = spanVal + ' columns wide';
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        // Update span
        for (var i = 0; i < layoutOrder.length; i++) {
          if (layoutOrder[i].id === item.id) {
            layoutOrder[i].span = spanVal;
            break;
          }
        }
        // Rebuild grid
        rebuildGrid();
        // Re-inject edit UI (since rebuild destroys it)
        reenterEditMode();
      });
      widthGroup.appendChild(btn);
    });

    editBar.appendChild(handle);
    editBar.appendChild(widthGroup);

    // Insert at top of card
    card.insertBefore(editBar, card.firstChild);

    // Drag events
    card.addEventListener('dragstart', onDragStart);
    card.addEventListener('dragend', onDragEnd);
    card.addEventListener('dragover', onDragOver);
    card.addEventListener('dragleave', onDragLeave);
    card.addEventListener('drop', onDrop);
  }

  function reenterEditMode() {
    // Remove old edit UI
    document.querySelectorAll('.mw-card-edit-ui').forEach(function (el) { el.remove(); });
    document.querySelectorAll('.card[draggable]').forEach(function (card) {
      card.removeAttribute('draggable');
      card.classList.remove('mw-card-editable');
    });
    // Re-inject
    layoutOrder.forEach(function (item) {
      var card = cardMap[item.id];
      if (!card) return;
      injectEditUI(card, item);
    });
  }

  // ── Drag & Drop handlers ──────────────────────────────────────
  function onDragStart(e) {
    var card = e.target.closest('.card[data-mw-card-id]');
    if (!card) return;
    draggedId = card.getAttribute('data-mw-card-id');
    card.classList.add('mw-card-dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', draggedId);

    // Highlight all sortable containers
    document.querySelectorAll('.mw-card-editable').forEach(function (c) {
      if (c !== card) c.classList.add('mw-card-drop-target');
    });
  }

  function onDragEnd(e) {
    var card = e.target.closest('.card[data-mw-card-id]');
    if (card) card.classList.remove('mw-card-dragging');
    draggedId = null;

    // Clean up all indicators
    document.querySelectorAll('.mw-card-drop-target, .mw-card-drop-before, .mw-card-drop-after').forEach(function (el) {
      el.classList.remove('mw-card-drop-target', 'mw-card-drop-before', 'mw-card-drop-after');
    });
  }

  function onDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';

    var card = e.target.closest('.card[data-mw-card-id]');
    if (!card || card.getAttribute('data-mw-card-id') === draggedId) return;

    // Determine if dropping before or after
    var rect = card.getBoundingClientRect();
    var midY = rect.top + rect.height / 2;

    // Clean previous indicators on this card
    card.classList.remove('mw-card-drop-before', 'mw-card-drop-after');

    if (e.clientY < midY) {
      card.classList.add('mw-card-drop-before');
    } else {
      card.classList.add('mw-card-drop-after');
    }
  }

  function onDragLeave(e) {
    var card = e.target.closest('.card[data-mw-card-id]');
    if (card) {
      card.classList.remove('mw-card-drop-before', 'mw-card-drop-after');
    }
  }

  function onDrop(e) {
    e.preventDefault();

    var targetCard = e.target.closest('.card[data-mw-card-id]');
    if (!targetCard || !draggedId) return;

    var targetId = targetCard.getAttribute('data-mw-card-id');
    if (targetId === draggedId) return;

    // Determine drop position
    var rect = targetCard.getBoundingClientRect();
    var midY = rect.top + rect.height / 2;
    var dropAfter = e.clientY >= midY;

    // Reorder layoutOrder
    var draggedItem = null;
    var draggedIdx = -1;
    for (var i = 0; i < layoutOrder.length; i++) {
      if (layoutOrder[i].id === draggedId) {
        draggedItem = layoutOrder[i];
        draggedIdx = i;
        break;
      }
    }
    if (!draggedItem) return;

    // Remove dragged item
    layoutOrder.splice(draggedIdx, 1);

    // Find target position
    var targetIdx = -1;
    for (var j = 0; j < layoutOrder.length; j++) {
      if (layoutOrder[j].id === targetId) {
        targetIdx = j;
        break;
      }
    }
    if (targetIdx === -1) return;

    // Insert at correct position
    var insertIdx = dropAfter ? targetIdx + 1 : targetIdx;
    layoutOrder.splice(insertIdx, 0, draggedItem);

    // Rebuild and re-enter edit mode
    rebuildGrid();
    reenterEditMode();

    // Clean up
    document.querySelectorAll('.mw-card-drop-target, .mw-card-drop-before, .mw-card-drop-after').forEach(function (el) {
      el.classList.remove('mw-card-drop-target', 'mw-card-drop-before', 'mw-card-drop-after');
    });
  }

  // ── localStorage persistence ──────────────────────────────────
  function saveLayout() {
    var data = layoutOrder.map(function (item) {
      return { id: item.id, span: item.span };
    });
    try {
      localStorage.setItem(pageKey, JSON.stringify({
        version: 1,
        savedAt: new Date().toISOString(),
        cards: data
      }));
    } catch (e) { /* quota exceeded */ }
  }

  function loadSaved() {
    try {
      var raw = localStorage.getItem(pageKey);
      if (!raw) return null;
      var data = JSON.parse(raw);
      if (!data || data.version !== 1 || !Array.isArray(data.cards)) return null;
      return data.cards;
    } catch (e) {
      return null;
    }
  }

  function clearSaved() {
    try { localStorage.removeItem(pageKey); } catch (e) {}
  }

  // ── Toast helper (uses mw-toast if available) ─────────────────
  function showToast(msg) {
    if (window.mwToast) {
      mwToast(msg, 'success');
    } else {
      // Fallback: brief inline message
      var toast = document.createElement('div');
      toast.style.cssText = 'position:fixed;bottom:80px;right:20px;background:#2D8659;color:#fff;padding:10px 18px;border-radius:6px;font-size:0.85rem;z-index:9999;box-shadow:0 2px 8px rgba(0,0,0,.2);';
      toast.textContent = msg;
      document.body.appendChild(toast);
      setTimeout(function () { toast.remove(); }, 2000);
    }
  }

})();
