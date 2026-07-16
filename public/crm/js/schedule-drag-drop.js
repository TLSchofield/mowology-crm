/**
 * Schedule Drag-and-Drop Module
 *
 * Enables drag-and-drop for stop cards on the weekly calendar, via a single
 * Pointer Events implementation shared by mouse, touch and pen:
 * - A live clone of the card follows the pointer for the whole gesture.
 * - A drop-position indicator line shows exactly where the card will land
 *   within the target day column.
 * - On drop, the real card node moves in place (FLIP-animated) and the
 *   affected day column(s)' summary stats are patched from the API
 *   response — no page reload.
 * - Calculates route_order based on drop position within the column.
 * - Updates the database via /crm/api/reschedule-stop.php.
 * - Shows visual feedback (toast messages).
 *
 * This does NOT touch the separate "tray visit" drag-in feature wired up in
 * schedule.php (dragging an unscheduled job from the sidebar tray onto a day
 * column) — that stays on native HTML5 drag-and-drop, on different source
 * elements (.mw-tray-card), and the two simply don't interact.
 */

(function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────────
  var DRAG_THRESHOLD = 6; // px of movement before a press becomes a drag

  var pressCard = null;      // card under the pointer since pointerdown
  var pressPointerId = null;
  var pressStartX = 0;
  var pressStartY = 0;

  var dragging = false;      // true once the threshold has been exceeded
  var draggedCard = null;
  var originalDate = null;
  var originalRouteOrder = null;

  var cardClone = null;
  var cloneOffsetX = 0;
  var cloneOffsetY = 0;

  var dropIndicator = null;  // the insertion-line element, created lazily
  var currentColumn = null;  // day column currently hovered while dragging
  var currentBefore = null;  // card the indicator/drop would land before (or null = end)
  var currentIndex = 0;      // 0-based position among the target column's other cards

  // ── Feedback element references ────────────────────────────────────────
  var feedbackEl = document.getElementById('dragFeedback');
  var feedbackMsg = document.getElementById('dragMessage');

  // Tracks the active auto-hide timer so stale timers can be cancelled
  var autoHideTimer = null;

  // ── Initialization ─────────────────────────────────────────────────────

  /**
   * Initialize (or re-initialize) all drag-and-drop event listeners.
   * Safe to call multiple times — identical listener+function pairs are
   * de-duplicated by the browser, so re-binding an already-bound card is a
   * no-op; newly added cards (e.g. injectPlaceholderStop) pick up listeners.
   */
  function initDragAndDrop() {
    var stopCards = document.querySelectorAll('.mw-stop-card');
    stopCards.forEach(function (card) {
      card.addEventListener('pointerdown', handlePointerDown);
    });
  }

  // ── Pointer Handlers ─────────────────────────────────────────────────────

  function handlePointerDown(e) {
    // Only the primary button/touch/pen contact starts a drag.
    if (e.button !== undefined && e.button !== 0) return;

    pressCard = this;
    pressPointerId = e.pointerId;
    pressStartX = e.clientX;
    pressStartY = e.clientY;
    dragging = false;

    // Keep receiving move/up for this pointer even if the cursor leaves the
    // card (or briefly the viewport) mid-gesture.
    if (this.setPointerCapture) {
      try { this.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
    }

    document.addEventListener('pointermove', handlePointerMove, { passive: false });
    document.addEventListener('pointerup', handlePointerUp);
    document.addEventListener('pointercancel', handlePointerCancel);
  }

  function handlePointerMove(e) {
    if (!pressCard || e.pointerId !== pressPointerId) return;

    if (!dragging) {
      var dx = e.clientX - pressStartX;
      var dy = e.clientY - pressStartY;
      if (Math.sqrt(dx * dx + dy * dy) < DRAG_THRESHOLD) return;
      startDrag(e);
    }

    e.preventDefault(); // stop page scroll/selection while actively dragging
    moveCloneTo(e.clientX, e.clientY);
    updateDropTarget(e.clientX, e.clientY);
  }

  function handlePointerUp(e) {
    if (!pressCard || e.pointerId !== pressPointerId) return;
    cleanupPointerListeners();

    if (dragging) {
      finishDrag();
    }
    resetPressState();
  }

  function handlePointerCancel(e) {
    if (!pressCard || e.pointerId !== pressPointerId) return;
    cleanupPointerListeners();
    if (dragging) {
      cancelDrag();
    }
    resetPressState();
  }

  function cleanupPointerListeners() {
    document.removeEventListener('pointermove', handlePointerMove);
    document.removeEventListener('pointerup', handlePointerUp);
    document.removeEventListener('pointercancel', handlePointerCancel);
    if (pressCard && pressCard.releasePointerCapture) {
      try { pressCard.releasePointerCapture(pressPointerId); } catch (err) { /* ignore */ }
    }
  }

  function resetPressState() {
    pressCard = null;
    pressPointerId = null;
    dragging = false;
  }

  // ── Drag lifecycle ───────────────────────────────────────────────────────

  /**
   * Threshold exceeded — commit to a drag. Clones the real card so it can
   * follow the pointer, and leaves a dashed placeholder in the original slot.
   */
  function startDrag(e) {
    dragging = true;
    draggedCard = pressCard;
    originalDate = draggedCard.dataset.stopDate || '';
    originalRouteOrder = parseInt(draggedCard.dataset.routeOrder, 10) || 0;

    var rect = draggedCard.getBoundingClientRect();
    cloneOffsetX = pressStartX - rect.left;
    cloneOffsetY = pressStartY - rect.top;

    cardClone = draggedCard.cloneNode(true);
    cardClone.classList.add('mw-stop-card-clone');
    cardClone.classList.remove('dragging');
    cardClone.style.width = rect.width + 'px';
    document.body.appendChild(cardClone);
    moveCloneTo(e.clientX, e.clientY);

    draggedCard.classList.add('dragging');

    currentColumn = resolveColumn(draggedCard);
    currentBefore = draggedCard.nextElementSibling;
    currentIndex = originalRouteOrder > 0 ? originalRouteOrder - 1 : 0;
  }

  function moveCloneTo(clientX, clientY) {
    if (!cardClone) return;
    cardClone.style.left = (clientX - cloneOffsetX) + 'px';
    cardClone.style.top  = (clientY - cloneOffsetY) + 'px';
  }

  /**
   * Find the day column + insertion point under the pointer (hiding the
   * clone momentarily so elementFromPoint sees what's beneath it), and move
   * the placeholder + indicator line to that spot.
   */
  function updateDropTarget(clientX, clientY) {
    // The clone has pointer-events:none (CSS), so elementFromPoint already
    // sees through it to whatever's underneath — no need to hide/show it.
    var el = document.elementFromPoint(clientX, clientY);
    var column = resolveColumn(el);

    document.querySelectorAll('.mw-day-column.drag-over').forEach(function (col) {
      if (col !== column) col.classList.remove('drag-over');
    });

    if (!column) {
      currentColumn = null;
      removeDropIndicator();
      return;
    }

    column.classList.add('drag-over');
    currentColumn = column;

    var insertion = findInsertionPoint(column, clientY);
    currentBefore = insertion.before;
    currentIndex = insertion.index;
    showDropIndicatorAt(column, insertion.before);
  }

  /**
   * Where among a column's existing cards (excluding the dragged one) does
   * clientY fall? Mirrors the old calcRouteOrder math, but also returns the
   * sibling to insert before, for both the live indicator and the real drop.
   */
  function findInsertionPoint(column, clientY) {
    var cards = Array.prototype.slice.call(
      column.querySelectorAll('.mw-stop-card:not(.dragging)')
    );
    for (var i = 0; i < cards.length; i++) {
      var rect = cards[i].getBoundingClientRect();
      var midY = rect.top + rect.height / 2;
      if (clientY < midY) {
        return { index: i, before: cards[i] };
      }
    }
    return { index: cards.length, before: null };
  }

  function ensureDropIndicator() {
    if (!dropIndicator) {
      dropIndicator = document.createElement('div');
      dropIndicator.className = 'mw-drop-indicator';
    }
    return dropIndicator;
  }

  function showDropIndicatorAt(column, before) {
    var indicator = ensureDropIndicator();
    if (before) {
      column.insertBefore(indicator, before);
    } else {
      column.appendChild(indicator);
    }
  }

  function removeDropIndicator() {
    if (dropIndicator && dropIndicator.parentNode) {
      dropIndicator.parentNode.removeChild(dropIndicator);
    }
  }

  /**
   * Pointer released mid-drag — finalize the drop.
   */
  function finishDrag() {
    removeDropIndicator();
    document.querySelectorAll('.mw-day-column.drag-over').forEach(function (col) {
      col.classList.remove('drag-over');
    });
    if (cardClone && cardClone.parentNode) cardClone.parentNode.removeChild(cardClone);
    cardClone = null;

    var card = draggedCard;
    draggedCard = null;

    if (!currentColumn) {
      // Dropped outside any column — snap back.
      card.classList.remove('dragging');
      return;
    }

    var newDate = currentColumn.dataset.date;
    if (!newDate) {
      card.classList.remove('dragging');
      return;
    }

    var newRouteOrder = currentIndex + 1;

    if (newDate === originalDate && newRouteOrder === originalRouteOrder) {
      card.classList.remove('dragging');
      showFeedback('Stop not moved (same position)', 'warning');
      return;
    }

    rescheduleStop(card, currentColumn, currentBefore, newDate, newRouteOrder);
  }

  /**
   * Pointer cancelled (e.g. browser gesture interruption) — snap back to
   * the original spot with no API call.
   */
  function cancelDrag() {
    removeDropIndicator();
    document.querySelectorAll('.mw-day-column.drag-over').forEach(function (col) {
      col.classList.remove('drag-over');
    });
    if (cardClone && cardClone.parentNode) cardClone.parentNode.removeChild(cardClone);
    cardClone = null;
    if (draggedCard) draggedCard.classList.remove('dragging');
    draggedCard = null;
  }

  // ── API Call ───────────────────────────────────────────────────────────

  /**
   * POST to reschedule-stop.php, then move the real card node into place
   * and patch the affected day column(s)' summary stats — no page reload.
   *
   * @param {HTMLElement} card         The .mw-stop-card element being moved
   * @param {HTMLElement} targetColumn The .mw-day-column it's dropping into
   * @param {HTMLElement|null} before  Sibling to insert before (null = append)
   * @param {string} newDate           Target date (YYYY-MM-DD)
   * @param {number} newRouteOrder     Position within the day
   */
  function rescheduleStop(card, targetColumn, before, newDate, newRouteOrder) {
    var stopId = parseInt(card.dataset.stopId, 10);

    if (!newDate || !/^\d{4}-\d{2}-\d{2}$/.test(newDate)) {
      card.classList.remove('dragging');
      showFeedback('Invalid date format: "' + newDate + '"', 'error');
      return;
    }

    showFeedback('Updating schedule...', 'loading');

    var payload = {
      stop_id: stopId,
      new_date: newDate,
      new_route_order: newRouteOrder
    };

    fetch('/crm/api/reschedule-stop.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (response) {
        if (!response.ok) {
          return response.json()
            .then(function (data) {
              throw new Error(data.error || 'Server error (' + response.status + ')');
            })
            .catch(function (parseErr) {
              if (parseErr.message && parseErr.message.indexOf('Server error') === 0) {
                throw parseErr;
              }
              throw new Error('Server error (' + response.status + ')');
            });
        }
        return response.json();
      })
      .then(function (data) {
        if (data && data.warning) {
          card.classList.remove('dragging');
          showCapacityWarning(card, targetColumn, before, newDate, newRouteOrder, data.message);
          return;
        }

        applyMove(card, targetColumn, before, newDate, newRouteOrder, data);
        showFeedback('Stop moved to ' + formatFriendlyDate(newDate) + ' (position ' + newRouteOrder + ')', 'success');
      })
      .catch(function (error) {
        card.classList.remove('dragging');
        var msg = error.message || 'Failed to reschedule stop';
        if (error instanceof TypeError && error.message === 'Failed to fetch') {
          msg = 'Connection error — check your internet and try again';
        }
        showFeedback(msg, 'error');
        console.error('Reschedule stop error:', error);
      });
  }

  /**
   * Move the real card DOM node into its new slot (FLIP-animated) and patch
   * the origin/destination day columns' summary cards from the API response.
   */
  function applyMove(card, targetColumn, before, newDate, newRouteOrder, data) {
    var fromRect = card.getBoundingClientRect();

    if (before) {
      targetColumn.insertBefore(card, before);
    } else {
      targetColumn.appendChild(card);
    }

    card.classList.remove('dragging');
    card.dataset.stopDate = newDate;
    card.dataset.routeOrder = String(newRouteOrder);

    var toRect = card.getBoundingClientRect();
    var dx = fromRect.left - toRect.left;
    var dy = fromRect.top - toRect.top;
    if ((dx || dy) && typeof card.animate === 'function') {
      card.animate(
        [{ transform: 'translate(' + dx + 'px,' + dy + 'px)' }, { transform: 'none' }],
        { duration: 200, easing: 'cubic-bezier(0.32,0.72,0,1)' }
      );
    }

    patchDayCards((data && data.day_cards) || {});
  }

  /**
   * Replace each affected day column's .mw-dsc summary card with the fresh,
   * server-rendered fragment returned by reschedule-stop.php.
   */
  function patchDayCards(dayCards) {
    Object.keys(dayCards).forEach(function (date) {
      var column = document.querySelector('.mw-day-column[data-date="' + date + '"]');
      if (!column) return;
      var existing = column.querySelector('.mw-dsc');
      if (existing) {
        existing.outerHTML = dayCards[date];
      } else {
        column.insertAdjacentHTML('afterbegin', dayCards[date]);
      }
    });
  }

  function formatFriendlyDate(dateStr) {
    var dateObj = new Date(dateStr + 'T12:00:00'); // noon to avoid timezone shifting
    return dateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
  }

  // ── Helpers ────────────────────────────────────────────────────────────

  /**
   * Walk up from an element to find the nearest .mw-day-column.
   */
  function resolveColumn(el) {
    if (!el) return null;
    if (el.classList && el.classList.contains('mw-day-column')) return el;
    return el.closest ? el.closest('.mw-day-column') : null;
  }

  // ── Feedback Toast ─────────────────────────────────────────────────────

  /**
   * Show an amber toast warning about crew capacity with a "Continue" button.
   * If the user confirms, resends the reschedule with force=1.
   */
  function showCapacityWarning(card, targetColumn, before, newDate, newRouteOrder, message) {
    if (!feedbackEl || !feedbackMsg) return;

    clearTimeout(autoHideTimer);
    autoHideTimer = null;

    feedbackMsg.textContent = message || 'Crew day is over capacity — continue?';
    feedbackEl.className = 'mw-drag-feedback warning';
    feedbackEl.style.display = 'block';

    feedbackEl.querySelectorAll('.mw-cap-confirm-btn, .mw-cap-cancel-btn').forEach(function (el) { el.remove(); });

    var cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.textContent = 'Cancel';
    cancelBtn.className = 'mw-cap-cancel-btn';
    cancelBtn.style.cssText = 'margin-left:8px;padding:2px 10px;font-size:11px;cursor:pointer;background:transparent;color:#fff;border:1px solid rgba(255,255,255,0.4);border-radius:4px;';
    feedbackEl.appendChild(cancelBtn);

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = 'Continue anyway';
    btn.className = 'mw-cap-confirm-btn';
    btn.style.cssText = 'margin-left:6px;padding:2px 10px;font-size:11px;cursor:pointer;background:#e85d04;color:#fff;border:none;border-radius:4px;';
    feedbackEl.appendChild(btn);

    cancelBtn.addEventListener('click', function () {
      cancelBtn.remove();
      btn.remove();
      feedbackEl.style.display = 'none';
    });

    btn.addEventListener('click', function () {
      btn.remove();
      var stopId = parseInt(card.dataset.stopId, 10);
      showFeedback('Moving stop...', 'loading');

      fetch('/crm/api/reschedule-stop.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          stop_id: stopId,
          new_date: newDate,
          new_route_order: newRouteOrder,
          force: 1
        })
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.success) {
            applyMove(card, targetColumn, before, newDate, newRouteOrder, data);
            showFeedback('Stop moved (over capacity)', 'success');
          } else {
            showFeedback((data && data.error) || 'Move failed', 'error');
          }
        })
        .catch(function () {
          showFeedback('Connection error', 'error');
        });
    });
  }

  /**
   * Show a toast-style feedback message.
   *
   * @param {string} message  Text to display
   * @param {string} type     One of: success | error | warning | loading | info
   */
  function showFeedback(message, type) {
    if (!feedbackEl || !feedbackMsg) return;

    clearTimeout(autoHideTimer);
    autoHideTimer = null;

    feedbackEl.querySelectorAll('.mw-cap-confirm-btn, .mw-cap-cancel-btn').forEach(function (el) { el.remove(); });

    type = type || 'info';
    feedbackMsg.textContent = message;

    feedbackEl.className = 'mw-drag-feedback';
    if (type === 'error') {
      feedbackEl.classList.add('error');
    } else if (type === 'success') {
      feedbackEl.classList.add('success');
    } else if (type === 'warning') {
      feedbackEl.classList.add('warning');
    }

    feedbackEl.style.display = 'block';

    var timeout;
    switch (type) {
      case 'error':   timeout = 10000; break;
      case 'loading': timeout = 30000; break;
      case 'success': timeout = 3000;  break;
      case 'warning': timeout = 6000;  break;
      default:        timeout = 3000;
    }

    autoHideTimer = setTimeout(function () {
      feedbackEl.style.display = 'none';
      autoHideTimer = null;
    }, timeout);
  }

  // ── Bootstrap ──────────────────────────────────────────────────────────

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDragAndDrop);
  } else {
    initDragAndDrop();
  }

  // Expose re-init for pages that add stops dynamically (e.g., after AJAX)
  window.reinitScheduleDragDrop = initDragAndDrop;
})();
