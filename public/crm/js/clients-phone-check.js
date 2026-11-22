/* Moved out of public/crm/clients_appstack.php (clients refactor, no change in behaviour). PHP values arrive in window.mwClients.clients_phone_check. */
// ── Phone / mobile field validation ───────────────────────────────────────
// Flags inputs that contain URLs or clearly non-phone content with a red border
// and inline message. Server-side sanitizePhone() strips them on save anyway,
// but this gives instant feedback before the form is submitted.
function mwValidatePhone(input) {
    var v = input.value;
    var looksInvalid = /https?:\/\/|ftp:\/\/|www\.|\.php|\.html?|@/.test(v)
                    || (v.replace(/\D/g, '').length < 7 && v.trim().length > 4);
    if (looksInvalid) {
        input.classList.add('is-invalid');
        if (!input.parentNode.querySelector('.mw-phone-invalid-feedback')) {
            var fb = document.createElement('div');
            fb.className = 'invalid-feedback mw-phone-invalid-feedback';
            fb.textContent = "This doesn’t look like a phone number — it will be cleared on save.";
            input.insertAdjacentElement('afterend', fb);
        }
    } else {
        input.classList.remove('is-invalid');
        var fb2 = input.parentNode.querySelector('.mw-phone-invalid-feedback');
        if (fb2) fb2.remove();
    }
}
// Run on load to flag any bad values already in the form
document.querySelectorAll('input[name="phone"], input[name="mobile"]').forEach(mwValidatePhone);
