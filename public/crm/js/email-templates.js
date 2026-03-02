/**
 * email-templates.js
 * Handles the Email Templates tab in Settings.
 * Loaded only on settings.php via $extraHead.
 */
(function () {
    'use strict';

    const API = '/crm/api/email-templates.php';

    // ── State ────────────────────────────────────────────────────────────────
    let templates = {}; // keyed by template_key
    let dirty     = {}; // tracks which keys have unsaved changes

    // ── Init ─────────────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        loadTemplates();
        hookSettingsForm();
    });

    // ── Load all templates from API ──────────────────────────────────────────
    function loadTemplates() {
        fetch(API)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { return; }
                data.templates.forEach(function (tpl) {
                    templates[tpl.template_key] = tpl;
                    populateSection(tpl);
                });
            })
            .catch(function () {
                // Silently fail — fields will be empty
            });
    }

    // ── Populate one accordion section with template data ────────────────────
    function populateSection(tpl) {
        var key       = tpl.template_key;
        var subjectEl = document.getElementById('et_subject_' + key);
        var bodyEl    = document.getElementById('et_body_' + key);

        if (subjectEl) { subjectEl.value = tpl.subject   || ''; }
        if (bodyEl)    { bodyEl.value    = tpl.body_text || ''; }
    }

    // ── Hook the main settings save button to also save email templates ──────
    function hookSettingsForm() {
        var form = document.getElementById('settingsForm');
        if (!form) { return; }

        form.addEventListener('submit', function () {
            saveAllDirtyTemplates();
        });
    }

    // ── Save any templates that have been edited ─────────────────────────────
    function saveAllDirtyTemplates() {
        var keys = Object.keys(dirty);
        if (keys.length === 0) { return; }

        keys.forEach(function (key) {
            var subjectEl = document.getElementById('et_subject_' + key);
            var bodyEl    = document.getElementById('et_body_'    + key);
            if (!subjectEl || !bodyEl) { return; }

            saveTemplate(key, subjectEl.value, bodyEl.value, false);
        });
    }

    // ── Save a single template ───────────────────────────────────────────────
    function saveTemplate(key, subject, bodyText, showFeedback) {
        return fetch(API, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ key: key, subject: subject, body_text: bodyText }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                templates[key] = data.template;
                delete dirty[key];
                if (showFeedback) {
                    showToast('Template saved', 'success');
                }
            } else {
                if (showFeedback) {
                    showToast('Save failed: ' + (data.error || 'unknown error'), 'error');
                }
            }
        })
        .catch(function () {
            if (showFeedback) {
                showToast('Network error saving template', 'error');
            }
        });
    }

    // ── Mark a template as dirty when user edits it ──────────────────────────
    window.etMarkDirty = function (key) {
        dirty[key] = true;
    };

    // ── Preview email in a modal ─────────────────────────────────────────────
    window.etPreview = function (key) {
        var overlay  = document.getElementById('et-preview-overlay');
        var frame    = document.getElementById('et-preview-frame');
        var loading  = document.getElementById('et-preview-loading');

        if (!overlay || !frame) { return; }

        // Show loading state
        overlay.style.display = 'flex';
        frame.style.display   = 'none';
        if (loading) { loading.style.display = 'block'; }

        // Save current edits first so preview reflects unsaved changes
        var subjectEl = document.getElementById('et_subject_' + key);
        var bodyEl    = document.getElementById('et_body_'    + key);

        var savePromise;
        if (subjectEl && bodyEl) {
            savePromise = saveTemplate(key, subjectEl.value, bodyEl.value, false);
        } else {
            savePromise = Promise.resolve();
        }

        savePromise.then(function () {
            frame.src = API + '?preview=' + encodeURIComponent(key) + '&t=' + Date.now();
            frame.onload = function () {
                frame.style.display   = 'block';
                if (loading) { loading.style.display = 'none'; }
            };
        });
    };

    // ── Close preview overlay ────────────────────────────────────────────────
    window.etClosePreview = function () {
        var overlay = document.getElementById('et-preview-overlay');
        var frame   = document.getElementById('et-preview-frame');
        if (overlay) { overlay.style.display = 'none'; }
        if (frame)   { frame.src = 'about:blank'; }
    };

    // ── Reset a template to its default text ─────────────────────────────────
    window.etResetDefault = function (key) {
        if (!confirm('Reset "' + key.replace(/_/g, ' ') + '" to the default text? Your edits will be lost.')) {
            return;
        }

        fetch(API + '?defaults=' + encodeURIComponent(key))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && data.template) {
                    var tpl       = data.template;
                    var subjectEl = document.getElementById('et_subject_' + key);
                    var bodyEl    = document.getElementById('et_body_'    + key);
                    if (subjectEl) { subjectEl.value = tpl.subject   || ''; }
                    if (bodyEl)    { bodyEl.value    = tpl.body_text || ''; }
                    // Save immediately
                    saveTemplate(key, tpl.subject || '', tpl.body_text || '', true);
                }
            })
            .catch(function () {
                showToast('Could not load defaults', 'error');
            });
    };

    // ── Insert a placeholder at the cursor position in a textarea ────────────
    window.etInsertPlaceholder = function (targetId, placeholder) {
        var el = document.getElementById(targetId);
        if (!el) { return; }

        var start = el.selectionStart;
        var end   = el.selectionEnd;
        var text  = el.value;

        el.value = text.substring(0, start) + placeholder + text.substring(end);
        el.selectionStart = el.selectionEnd = start + placeholder.length;
        el.focus();

        // Mark as dirty
        var key = targetId.replace('et_subject_', '').replace('et_body_', '');
        dirty[key] = true;
    };

    // ── Toast helper (reuses existing CRM toast if available) ────────────────
    function showToast(msg, type) {
        // Try to use the CRM's existing toast
        var toast = document.getElementById('toast') || document.getElementById('settingsSuccess') || document.getElementById('settingsError');
        if (!toast) { return; }

        if (type === 'success' && document.getElementById('settingsSuccess')) {
            var el = document.getElementById('settingsSuccess');
            el.textContent = msg;
            el.style.display = 'block';
            setTimeout(function () { el.style.display = 'none'; }, 3000);
        } else if (type === 'error' && document.getElementById('settingsError')) {
            var el2 = document.getElementById('settingsError');
            el2.textContent = msg;
            el2.style.display = 'block';
            setTimeout(function () { el2.style.display = 'none'; }, 4000);
        }
    }

    // Close preview overlay when clicking backdrop
    document.addEventListener('click', function (e) {
        var overlay = document.getElementById('et-preview-overlay');
        if (overlay && e.target === overlay) {
            window.etClosePreview();
        }
    });

    // Close preview on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            window.etClosePreview();
        }
    });

}());
