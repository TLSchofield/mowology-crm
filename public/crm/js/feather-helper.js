/**
 * Feather Icons Helper - Safe Icon Hydration
 * Prevents "feather is not defined" and "Cannot read property 'toSvg'" errors
 *
 * Usage:
 *   hydrateFeatherIcons()           // hydrate entire document
 *   hydrateFeatherIcons(container)  // hydrate specific DOM element
 *   isFeatherAvailable()            // check if Feather is loaded
 */

(function(global) {
  'use strict';

  /**
   * Safely hydrate Feather Icons in a scope
   * @param {Element|Document} scope - DOM element or document to hydrate
   * @param {Object} options - Options to pass to feather.replace()
   * @returns {Boolean} true if hydration succeeded, false otherwise
   */
  global.hydrateFeatherIcons = function(scope, options) {
    // Scope defaults to document
    if (!scope) {
      scope = document;
    }

    // Options defaults
    options = options || {};

    // Guard: window.feather must exist
    if (!window.feather) {
      console.warn('[Feather Icons] Library not loaded. Icons will not render.');
      return false;
    }

    // Guard: feather.replace must be a function
    if (typeof feather.replace !== 'function') {
      console.warn('[Feather Icons] feather.replace() is not available.');
      return false;
    }

    try {
      // Collect all data-feather icons and replace invalid ones with fallback
      var selector = scope === document || scope.nodeType === 9 ? document : scope;
      var icons = selector.querySelectorAll('[data-feather]');
      var missingIcons = [];
      var replaced = 0;

      icons.forEach(function(icon) {
        var iconName = icon.getAttribute('data-feather');
        if (iconName && !feather.icons[iconName]) {
          missingIcons.push(iconName);
          // Replace with fallback icon to prevent crash
          icon.setAttribute('data-feather', 'alert-circle');
          replaced++;
        }
      });

      // Log missing icons but don't fail
      if (missingIcons.length > 0) {
        console.warn('[Feather Icons] Missing icon definitions: ' + missingIcons.join(', ') + ' (replaced with fallback)');
      }

      // If scope is a document fragment, use standard call
      if (scope === document || scope.nodeType === 9) {
        feather.replace(options);
      } else {
        // For specific element scope, replace only within that element
        feather.replace({ root: scope, ...options });
      }
      return true;
    } catch (error) {
      console.error('[Feather Icons] Error during replace:', error);
      return false;
    }
  };

  /**
   * Check if Feather Icons library is available and functional
   * @returns {Boolean}
   */
  global.isFeatherAvailable = function() {
    return !!(window.feather && typeof feather.replace === 'function');
  };

  // Auto-initialize Feather icons when DOM is ready
  // This catches icons that weren't hydrated by other code
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
      if (window.feather) {
        hydrateFeatherIcons();
      }
    });
  } else {
    // DOM is already loaded
    if (window.feather) {
      hydrateFeatherIcons();
    }
  }

})(window);
