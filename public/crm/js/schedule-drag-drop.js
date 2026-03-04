/**
 * Schedule Drag-and-Drop Module
 *
 * Enables drag-and-drop functionality for stop cards on the weekly calendar.
 * - Drag stops between day columns to reschedule
 * - Calculates route_order based on drop position within the column
 * - Updates database via /crm/api/reschedule-stop.php
 * - Shows visual feedback (toast messages)
 * - Includes basic touch support for mobile devices
 */

(function () {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────────
  let draggedCard = null;
  let originalDate = null;
  let originalRouteOrder = null;

  // Touch-drag state
  let touchClone = null;
  let touchStartX = 0;
  let touchStartY = 0;
  let touchActive = false;

  // ── Feedback element references ────────────────────────────────────────
  const feedbackEl = document.getElementById('dragFeedback');
  const feedbackMsg = document.getElementById('dragMessage');

  // ── Initialization ─────────────────────────────────────────────────────

  /**
   * Initialize (or re-initialize) all drag-and-drop event listeners.
   * Safe to call multiple times — attaches fresh listeners to current DOM.
   */
  function initDragAndDrop() {
    // ── Draggable stop cards ───────────────────────────────────────────
    var stopCards = document.querySelectorAll('.mw-stop-card');

    stopCards.forEach(function (card) {
      // Make sure the card is draggable
      card.setAttribute('draggable', 'true');

      // Native drag events
      card.addEventListener('dragstart', handleDragStart);
      card.addEventListener('dragend', handleDragEnd);

      // Touch events for mobile
      card.addEventListener('touchstart', handleTouchStart, { passive: false });
      card.addEventListener('touchmove', handleTouchMove, { passive: false });
      card.addEventListener('touchend', handleTouchEnd);
    });

    // ── Drop targets: day columns ──────────────────────────────────────
    var dayColumns = document.querySelectorAll('.mw-day-column');

    dayColumns.forEach(function (col) {
      col.addEventListener('dragover', handleDragOver);
      col.addEventListener('dragenter', handleDragEnter);
      col.addEventListener('dragleave', handleDragLeave);
      col.addEventListener('drop', handleDrop);
    });
  }

  // ── Native Drag Handlers ───────────────────────────────────────────────

  /**
   * Drag start — store stop metadata, apply visual class, set drag image.
   */
  function handleDragStart(e) {
    draggedCard = this;
    originalDate = this.dataset.stopDate || '';
    originalRouteOrder = parseInt(this.dataset.routeOrder, 10) || 0;

    this.classList.add('dragging');

    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', this.dataset.stopId || '');

    // Custom green circle drag image
    var dragImage = new Image();
    dragImage.src =
      'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="40" height="40"%3E' +
      '%3Ccircle cx="20" cy="20" r="18" fill="%232D8659" opacity="0.85"/%3E%3C/svg%3E';
    e.dataTransfer.setDragImage(dragImage, 20, 20);
  }

  /**
   * Drag end — clean up classes regardless of whether a drop occurred.
   */
  function handleDragEnd() {
    if (draggedCard) {
      draggedCard.classList.remove('dragging');
    }

    // Remove highlight from every column
    document.querySelectorAll('.mw-day-column.drag-over').forEach(function (col) {
      col.classList.remove('drag-over');
    });
  }

  /**
   * Drag over — allow drop and keep the column highlighted.
   */
  function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
  }

  /**
   * Drag enter — add highlight class to the day column.
   */
  function handleDragEnter(e) {
    e.preventDefault();

    // Resolve to the nearest .mw-day-column (in case event fires on a child)
    var column = resolveColumn(e.target);
    if (column) {
      column.classList.add('drag-over');
    }
  }

  /**
   * Drag leave — remove highlight only when the cursor truly exits the column.
   */
  function handleDragLeave(e) {
    var column = resolveColumn(e.target);
    if (!column) return;

    // Only remove if the relatedTarget is outside this column
    if (!column.contains(e.relatedTarget)) {
      column.classList.remove('drag-over');
    }
  }

  /**
   * Drop — determine new date and route_order, then call the API.
   */
  function handleDrop(e) {
    e.preventDefault();
    e.stopPropagation();

    if (!draggedCard) return;

    var column = resolveColumn(e.target);
    if (!column) return;

    column.classList.remove('drag-over');

    var newDate = column.dataset.date;
    if (!newDate) return;

    // Calculate route order from drop Y-position within the column
    var newRouteOrder = calcRouteOrder(column, e.clientY);

    // If same date and same order, nothing to do
    if (newDate === originalDate && newRouteOrder === originalRouteOrder) {
      showFeedback('Stop not moved (same position)', 'warning');
      return;
    }

    // Fire the API call
    rescheduleStop(draggedCard, newDate, newRouteOrder);
  }

  // ── Touch Handlers (mobile support) ────────────────────────────────────

  /**
   * Touch start — record origin and prepare for possible drag.
   * We wait for touchmove to confirm intent (avoids blocking taps/scrolls).
   */
  function handleTouchStart(e) {
    if (e.touches.length !== 1) return;

    var touch = e.touches[0];
    touchStartX = touch.clientX;
    touchStartY = touch.clientY;

    draggedCard = this;
    originalDate = this.dataset.stopDate || '';
    originalRouteOrder = parseInt(this.dataset.routeOrder, 10) || 0;
    touchActive = false;
  }

  /**
   * Touch move — once the finger moves far enough, start the visual drag.
   */
  function handleTouchMove(e) {
    if (!draggedCard || e.touches.length !== 1) return;

    var touch = e.touches[0];
    var dx = touch.clientX - touchStartX;
    var dy = touch.clientY - touchStartY;

    // Require at least 10px movement to start dragging (prevents accidental drags)
    if (!touchActive && (Math.abs(dx) > 10 || Math.abs(dy) > 10)) {
      touchActive = true;
      draggedCard.classList.add('dragging');

      // Create a semi-transparent clone that follows the finger
      touchClone = draggedCard.cloneNode(true);
      touchClone.classList.add('mw-touch-drag-clone');
      touchClone.style.position = 'fixed';
      touchClone.style.pointerEvents = 'none';
      touchClone.style.opacity = '0.75';
      touchClone.style.zIndex = '9999';
      touchClone.style.width = draggedCard.offsetWidth + 'px';
      touchClone.style.transform = 'rotate(2deg)';
      document.body.appendChild(touchClone);
    }

    if (!touchActive) return;

    e.preventDefault(); // Prevent scrolling while dragging

    // Position clone under the finger
    if (touchClone) {
      touchClone.style.left = (touch.clientX - draggedCard.offsetWidth / 2) + 'px';
      touchClone.style.top = (touch.clientY - 20) + 'px';
    }

    // Highlight the column under the finger
    highlightColumnAtPoint(touch.clientX, touch.clientY);
  }

  /**
   * Touch end — if we were dragging, determine the drop target and reschedule.
   */
  function handleTouchEnd(e) {
    // Clean up clone
    if (touchClone && touchClone.parentNode) {
      touchClone.parentNode.removeChild(touchClone);
      touchClone = null;
    }

    if (!touchActive || !draggedCard) {
      draggedCard = null;
      touchActive = false;
      return;
    }

    draggedCard.classList.remove('dragging');

    // Find what column is under the last touch point
    var touch = e.changedTouches[0];
    var targetEl = document.elementFromPoint(touch.clientX, touch.clientY);
    var column = targetEl ? targetEl.closest('.mw-day-column') : null;

    // Clear all highlights
    document.querySelectorAll('.mw-day-column.drag-over').forEach(function (col) {
      col.classList.remove('drag-over');
    });

    if (!column) {
      draggedCard = null;
      touchActive = false;
      return;
    }

    var newDate = column.dataset.date;
    if (!newDate) {
      draggedCard = null;
      touchActive = false;
      return;
    }

    var newRouteOrder = calcRouteOrder(column, touch.clientY);

    if (newDate === originalDate && newRouteOrder === originalRouteOrder) {
      showFeedback('Stop not moved (same position)', 'warning');
      draggedCard = null;
      touchActive = false;
      return;
    }

    rescheduleStop(draggedCard, newDate, newRouteOrder);
    touchActive = false;
  }

  // ── API Call ───────────────────────────────────────────────────────────

  /**
   * POST to reschedule-stop.php to move a stop to a new date / route order.
   *
   * @param {HTMLElement} card        The .mw-stop-card element being moved
   * @param {string}      newDate     Target date (YYYY-MM-DD)
   * @param {number}      newRouteOrder  Position within the day
   */
  function rescheduleStop(card, newDate, newRouteOrder) {
    var stopId = parseInt(card.dataset.stopId, 10);

    // Validate date format
    if (!newDate || !/^\d{4}-\d{2}-\d{2}$/.test(newDate)) {
      showFeedback('Invalid date format: "' + newDate + '"', 'error');
      return;
    }

    // Visual loading state
    card.style.opacity = '0.5';
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
              // If the response body isn't valid JSON, use a generic message
              if (parseErr.message && parseErr.message.indexOf('Server error') === 0) {
                throw parseErr;
              }
              throw new Error('Server error (' + response.status + ')');
            });
        }
        return response.json();
      })
      .then(function (data) {
        // ── Capacity over-limit: show confirm toast, wait for user ──────
        if (data && data.warning) {
          card.style.opacity = '1';
          showCapacityWarning(card, newDate, newRouteOrder, data.message);
          return;
        }

        // Update the card's data attributes to reflect the new state
        card.dataset.stopDate = newDate;
        card.dataset.routeOrder = newRouteOrder;
        card.style.opacity = '1';

        // Format a friendly date for the toast
        var dateObj = new Date(newDate + 'T12:00:00'); // noon to avoid timezone shifting
        var dateStr = dateObj.toLocaleDateString('en-US', {
          weekday: 'short',
          month: 'short',
          day: 'numeric'
        });

        showFeedback('Stop moved to ' + dateStr + ' (position ' + newRouteOrder + ')', 'success');

        // Reload the page so the calendar re-renders with correct order
        setTimeout(function () {
          location.reload();
        }, 1500);

        draggedCard = null;
      })
      .catch(function (error) {
        // Restore card appearance
        card.style.opacity = '1';

        var msg = error.message || 'Failed to reschedule stop';

        // Distinguish network errors from API errors
        if (error instanceof TypeError && error.message === 'Failed to fetch') {
          msg = 'Connection error — check your internet and try again';
        }

        showFeedback(msg, 'error');
        console.error('Reschedule stop error:', error);

        draggedCard = null;
      });
  }

  // ── Helpers ────────────────────────────────────────────────────────────

  /**
   * Walk up from an element to find the nearest .mw-day-column.
   *
   * @param  {HTMLElement} el  Any element inside (or being) a day column
   * @return {HTMLElement|null}
   */
  function resolveColumn(el) {
    if (!el) return null;
    if (el.classList && el.classList.contains('mw-day-column')) return el;
    return el.closest ? el.closest('.mw-day-column') : null;
  }

  /**
   * Calculate route_order based on where the card was dropped vertically
   * within a day column. Looks at existing stop cards in the column and
   * returns the position the new card should occupy (1-based).
   *
   * @param  {HTMLElement} column   The .mw-day-column that received the drop
   * @param  {number}      clientY  The Y coordinate of the drop point
   * @return {number}               1-based route order
   */
  function calcRouteOrder(column, clientY) {
    // Get all stop cards currently in this column (excluding the one being dragged)
    var cards = Array.prototype.slice.call(
      column.querySelectorAll('.mw-stop-card:not(.dragging)')
    );

    if (cards.length === 0) {
      return 1; // First card in an empty column
    }

    // Walk through the cards and find where the drop Y falls
    for (var i = 0; i < cards.length; i++) {
      var rect = cards[i].getBoundingClientRect();
      var midY = rect.top + rect.height / 2;

      if (clientY < midY) {
        return i + 1; // Insert before this card
      }
    }

    // Dropped below all existing cards
    return cards.length + 1;
  }

  /**
   * During a touch drag, highlight the day column under the given point
   * and remove highlights from all others.
   *
   * @param {number} x  clientX
   * @param {number} y  clientY
   */
  function highlightColumnAtPoint(x, y) {
    var columns = document.querySelectorAll('.mw-day-column');

    columns.forEach(function (col) {
      var rect = col.getBoundingClientRect();
      var inside = (x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom);

      if (inside) {
        col.classList.add('drag-over');
      } else {
        col.classList.remove('drag-over');
      }
    });
  }

  // ── Feedback Toast ─────────────────────────────────────────────────────

  /**
   * Show an amber toast warning about crew capacity with a "Continue" button.
   * If the user confirms, resends the reschedule with force=1.
   */
  function showCapacityWarning(card, newDate, newRouteOrder, message) {
    if (!feedbackEl || !feedbackMsg) return;

    feedbackMsg.textContent = message || 'Crew day is over capacity — continue?';
    feedbackEl.className = 'mw-drag-feedback warning';
    feedbackEl.style.display = 'block';

    // Inject a "Continue anyway" button (removed after use)
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = 'Continue anyway';
    btn.className = 'mw-cap-confirm-btn';
    btn.style.cssText = 'margin-left:10px;padding:2px 10px;font-size:11px;cursor:pointer;background:#e85d04;color:#fff;border:none;border-radius:4px;';
    feedbackEl.appendChild(btn);

    btn.addEventListener('click', function () {
      btn.remove();
      // Re-run reschedule with force flag
      var stopId = parseInt(card.dataset.stopId, 10);
      card.style.opacity = '0.5';
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
          card.style.opacity = '1';
          if (data && data.success) {
            showFeedback('Stop moved (over capacity)', 'success');
            setTimeout(function () { location.reload(); }, 1500);
          } else {
            showFeedback(data.error || 'Move failed', 'error');
          }
        })
        .catch(function () {
          card.style.opacity = '1';
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

    type = type || 'info';
    feedbackMsg.textContent = message;

    // Reset classes then apply type-specific class
    feedbackEl.className = 'mw-drag-feedback';
    if (type === 'error') {
      feedbackEl.classList.add('error');
    } else if (type === 'success') {
      feedbackEl.classList.add('success');
    } else if (type === 'warning') {
      feedbackEl.classList.add('warning');
    }

    feedbackEl.style.display = 'block';

    // Auto-hide timings
    var timeout;
    switch (type) {
      case 'error':
        timeout = 10000; // 10s — long enough to read the message
        break;
      case 'loading':
        timeout = 10000;
        break;
      case 'success':
        timeout = 3000;
        break;
      case 'warning':
        timeout = 4000;
        break;
      default:
        timeout = 3000;
    }

    setTimeout(function () {
      feedbackEl.style.display = 'none';
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
