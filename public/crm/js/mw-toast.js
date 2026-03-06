/**
 * Mowology CRM — Centered Toast Notifications
 * Replaces native alert() with a styled, centered toast.
 *
 * Usage:
 *   mwToast('Saved successfully');                   // default (success)
 *   mwToast('Something went wrong', 'error');        // error variant
 *   mwToast('Heads up', 'warning');                  // warning
 *   mwToast('FYI', 'info');                          // info
 *   mwToast('Done', 'success', 5000);                // custom duration (ms)
 *
 * All existing alert() calls are automatically routed through this toast.
 */
(function () {
  'use strict';

  var CONTAINER_ID = 'mw-toast-container';
  var DEFAULT_DURATION = 3500;

  function getContainer() {
    var c = document.getElementById(CONTAINER_ID);
    if (!c) {
      c = document.createElement('div');
      c.id = CONTAINER_ID;
      c.className = 'mw-toast-container';
      document.body.appendChild(c);
    }
    return c;
  }

  /**
   * Detect toast type from message content when called via alert().
   */
  function detectType(msg) {
    var s = (msg || '').toLowerCase();
    if (s.indexOf('error') !== -1 || s.indexOf('fail') !== -1 || s.indexOf('invalid') !== -1) return 'error';
    if (s.indexOf('warning') !== -1 || s.indexOf('heads up') !== -1) return 'warning';
    if (s.indexOf('success') !== -1 || s.indexOf('saved') !== -1 || s.indexOf('merged') !== -1
        || s.indexOf('created') !== -1 || s.indexOf('deleted') !== -1 || s.indexOf('updated') !== -1
        || s.indexOf('sent') !== -1 || s.indexOf('added') !== -1) return 'success';
    return 'info';
  }

  var iconMap = {
    success: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    error:   '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    warning: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    info:    '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
  };

  function mwToast(message, type, duration) {
    type = type || detectType(message);
    duration = duration || DEFAULT_DURATION;

    var container = getContainer();
    var toast = document.createElement('div');
    toast.className = 'mw-toast mw-toast--' + type;

    toast.innerHTML =
      '<span class="mw-toast-icon">' + (iconMap[type] || iconMap.info) + '</span>' +
      '<span class="mw-toast-msg">' + escHtml(String(message)) + '</span>' +
      '<button type="button" class="mw-toast-close" aria-label="Close">&times;</button>';

    // Close on click
    toast.querySelector('.mw-toast-close').addEventListener('click', function () {
      dismiss(toast);
    });

    container.appendChild(toast);

    // Trigger entrance animation on next frame
    requestAnimationFrame(function () {
      toast.classList.add('mw-toast--visible');
    });

    // Auto-dismiss
    var timer = setTimeout(function () { dismiss(toast); }, duration);
    toast._timer = timer;
  }

  function dismiss(toast) {
    if (toast._dismissed) return;
    toast._dismissed = true;
    clearTimeout(toast._timer);
    toast.classList.remove('mw-toast--visible');
    toast.classList.add('mw-toast--exit');
    setTimeout(function () {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 300);
  }

  function escHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  // ── Override window.alert ──────────────────────────────────────
  window.alert = function (msg) {
    mwToast(String(msg));
  };

  // ── Expose globally ────────────────────────────────────────────
  window.mwToast = mwToast;
})();
