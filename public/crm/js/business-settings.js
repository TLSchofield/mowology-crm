/**
 * Business Settings Management
 *
 * Handles loading, editing, and saving business configuration
 * Settings include: company info, branding, invoices, email, and messages
 */

(function () {
  'use strict';

  const loadingEl = document.getElementById('settingsLoading');
  const errorEl = document.getElementById('settingsError');
  const successEl = document.getElementById('settingsSuccess');
  const formEl = document.getElementById('settingsForm');

  /**
   * Load settings on page load
   */
  function loadSettings() {
    fetch('/crm/api/business-settings.php')
      .then((response) => {
        if (!response.ok) throw new Error('Failed to load settings');
        return response.json();
      })
      .then((data) => {
        populateForm(data.settings);
        showForm();
        hideLoading();
      })
      .catch((error) => {
        console.error('Error loading settings:', error);
        showError('Failed to load settings: ' + error.message);
        hideLoading();
      });
  }

  /**
   * Populate form with settings data
   */
  function populateForm(settings) {
    Object.keys(settings).forEach((key) => {
      const element = document.getElementById(key);
      if (element) {
        element.value = settings[key] || '';

        // Update related display elements
        if (key === 'brand_color_primary') {
          const textEl = document.getElementById('brand_color_primary_text');
          if (textEl) textEl.value = settings[key] || '#2D8659';
        } else if (key === 'brand_color_secondary') {
          const textEl = document.getElementById('brand_color_secondary_text');
          if (textEl) textEl.value = settings[key] || '#7FD858';
        }
      }
    });

    // Update logo preview
    updateLogoPreview(settings.logo_path);
  }

  /**
   * Update color text displays when color picker changes
   */
  function setupColorPickers() {
    const primaryColor = document.getElementById('brand_color_primary');
    const primaryText = document.getElementById('brand_color_primary_text');
    const secondaryColor = document.getElementById('brand_color_secondary');
    const secondaryText = document.getElementById('brand_color_secondary_text');

    if (primaryColor) {
      primaryColor.addEventListener('change', (e) => {
        if (primaryText) primaryText.value = e.target.value;
      });
    }

    if (secondaryColor) {
      secondaryColor.addEventListener('change', (e) => {
        if (secondaryText) secondaryText.value = e.target.value;
      });
    }

    // Also update on input (real-time)
    if (primaryColor) {
      primaryColor.addEventListener('input', (e) => {
        if (primaryText) primaryText.value = e.target.value;
      });
    }

    if (secondaryColor) {
      secondaryColor.addEventListener('input', (e) => {
        if (secondaryText) secondaryText.value = e.target.value;
      });
    }
  }

  /**
   * Update logo preview
   */
  function updateLogoPreview(logoPath) {
    const preview = document.getElementById('logoPreview');
    if (!preview) return;

    if (!logoPath) {
      preview.innerHTML =
        '<span class="text-muted">No logo configured</span>';
      return;
    }

    const altText =
      document.getElementById('logo_alt_text').value || 'Company Logo';
    preview.innerHTML = `<img src="${logoPath}" alt="${altText}" style="max-width: 100%; max-height: 150px;">`;
  }

  /**
   * Listen for logo path changes
   */
  function setupLogoPreview() {
    const logoPath = document.getElementById('logo_path');
    if (logoPath) {
      logoPath.addEventListener('change', () => {
        updateLogoPreview(logoPath.value);
      });
      logoPath.addEventListener('input', () => {
        updateLogoPreview(logoPath.value);
      });
    }
  }

  /**
   * Handle form submission
   */
  function setupFormSubmit() {
    if (!formEl) return;

    formEl.addEventListener('submit', (e) => {
      e.preventDefault();

      // Collect form data
      const settings = {};
      const inputs = formEl.querySelectorAll('input, textarea');

      inputs.forEach((input) => {
        if (input.id && input.id !== 'brand_color_primary_text' && input.id !== 'brand_color_secondary_text') {
          settings[input.id] = input.value;
        }
      });

      // Save settings
      saveSettings(settings);
    });
  }

  /**
   * Save settings via API
   */
  function saveSettings(settings) {
    // Disable submit button
    const submitBtn = formEl.querySelector('button[type="submit"]');
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.innerHTML =
        '<span class="spinner-border spinner-border-sm me-2"></span>Saving...';
    }

    fetch('/crm/api/business-settings.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(settings),
    })
      .then((response) => {
        if (!response.ok) {
          return response.json().then((data) => {
            throw new Error(data.error || 'Failed to save settings');
          });
        }
        return response.json();
      })
      .then((data) => {
        showSuccess('Settings saved successfully');
        // Re-populate form with saved data
        populateForm(data.settings);
        // Hide success message after 3 seconds
        setTimeout(() => {
          successEl.style.display = 'none';
        }, 3000);
      })
      .catch((error) => {
        console.error('Error saving settings:', error);
        showError('Failed to save settings: ' + error.message);
      })
      .finally(() => {
        // Re-enable submit button
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.innerHTML = 'Save Settings';
        }
      });
  }

  /**
   * Show loading state
   */
  function showLoading() {
    if (loadingEl) loadingEl.style.display = 'block';
    if (formEl) formEl.style.display = 'none';
  }

  /**
   * Hide loading state
   */
  function hideLoading() {
    if (loadingEl) loadingEl.style.display = 'none';
  }

  /**
   * Show form
   */
  function showForm() {
    if (formEl) formEl.style.display = 'block';
  }

  /**
   * Show error message
   */
  function showError(message) {
    if (errorEl) {
      errorEl.innerHTML = message;
      errorEl.style.display = 'block';
    }
  }

  /**
   * Show success message
   */
  function showSuccess(message) {
    if (successEl) {
      successEl.innerHTML = '✓ ' + message;
      successEl.style.display = 'block';
    }
    if (errorEl) {
      errorEl.style.display = 'none';
    }
  }

  /**
   * Initialize
   */
  function init() {
    showLoading();
    loadSettings();
    setupColorPickers();
    setupLogoPreview();
    setupFormSubmit();
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
