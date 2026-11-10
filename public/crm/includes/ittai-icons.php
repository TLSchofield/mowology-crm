<?php
/**
 * Ittai icon sprite — state glyphs (shape carries the meaning, not colour) and 24-box line icons for the
 * desktop shell. Use with <svg><use href="#it-st-working"/></svg>. Printed once per page.
 */
function itIconSprite(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs>
<symbol id="it-st-working" viewBox="0 0 16 16"><path d="M8 1.8a6.2 6.2 0 1 1 0 12.4a6.2 6.2 0 1 1 0-12.4zM8 4.8V8l2.2 1.4" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
<symbol id="it-st-approval" viewBox="0 0 16 16"><path d="M8 1.5 15 14H1z" fill="currentColor"/></symbol>
<symbol id="it-st-approval-forming" viewBox="0 0 16 16"><path d="M3.1 12.4 6.3 6.2M9.4 3.4 13.6 10.9M2.4 14.1 12.2 13.3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></symbol>
<symbol id="it-st-approval-held" viewBox="0 0 16 16"><path d="M6.2 5.4 8 2.2 14.3 13.3H1.7l2.6-4.6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/></symbol>
<symbol id="it-st-approval-mastered" viewBox="0 0 16 16"><path d="M8 1.5 15 14H1z" fill="currentColor"/><path d="M8 5.9 11.4 12.2H4.6z" fill="none" stroke-width="1.1" stroke-linejoin="round" style="stroke:var(--it-surface)"/></symbol>
<symbol id="it-st-blocked" viewBox="0 0 16 16"><path d="M5.1 1h5.8L15 5.1v5.8L10.9 15H5.1L1 10.9V5.1z" fill="currentColor"/><path d="M4.6 8h6.8" fill="none" stroke-width="2.2" style="stroke:var(--it-surface)"/></symbol>
<symbol id="it-st-idle" viewBox="0 0 16 16"><path d="M8 2a6 6 0 1 1 0 12a6 6 0 1 1 0-12z" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="2.6 2.1"/></symbol>
<symbol id="it-st-unavailable" viewBox="0 0 16 16"><path d="M8 2a6 6 0 1 1 0 12a6 6 0 1 1 0-12zM3.8 12.2 12.2 3.8" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
<symbol id="it-i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></symbol>
<symbol id="it-i-down" viewBox="0 0 24 24"><path d="M6 9.5l6 6 6-6"/></symbol>
<symbol id="it-i-right" viewBox="0 0 24 24"><path d="M9.5 6l6 6-6 6"/></symbol>
<symbol id="it-i-left" viewBox="0 0 24 24"><path d="M14.5 6l-6 6 6 6"/></symbol>
<symbol id="it-i-updown" viewBox="0 0 24 24"><path d="m7 15 5 5 5-5M7 9l5-5 5 5"/></symbol>
<symbol id="it-w-check" viewBox="0 0 24 24"><path d="M6 12.5l4 4 8-9"/></symbol>
<symbol id="it-w-neq" viewBox="0 0 24 24"><path d="M6 9h12M6 15h12M15 5l-6 14"/></symbol>
<symbol id="it-w-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></symbol>
<symbol id="it-w-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 11v5M12 8h.01"/></symbol>
<symbol id="it-w-merge" viewBox="0 0 24 24"><path d="M6 20l6-8 6 8M12 12V4M9 7l3-3 3 3"/></symbol>
<symbol id="it-w-split" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="14" rx="2.5"/><path d="M12 5v14"/></symbol>
<symbol id="it-w-copies" viewBox="0 0 24 24"><path d="M8 7.5h9a2 2 0 0 1 2 2V20H10a2 2 0 0 1-2-2zM5 16.5V6a2 2 0 0 1 2-2h8"/></symbol>
<symbol id="it-w-eye" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6.5 9.5-6.5 9.5 6.5 9.5 6.5-3.5 6.5-9.5 6.5S2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="it-w-book" viewBox="0 0 24 24"><path d="M5 4.5h11a3 3 0 0 1 3 3v12H8a3 3 0 0 1-3-3zM5 16.5a3 3 0 0 1 3-3h11M9 8h6"/></symbol>
<symbol id="it-w-mail" viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="14" rx="2"/><path d="M4 7l8 6 8-6"/></symbol>
<symbol id="it-w-arrow" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
<symbol id="it-w-user" viewBox="0 0 24 24"><circle cx="12" cy="8.5" r="3.5"/><path d="M5 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/></symbol>
<symbol id="it-w-bank" viewBox="0 0 24 24"><path d="M3 21h18M5 20v-9M9.5 20v-9M14.5 20v-9M19 20v-9M2.5 10.5 12 4l9.5 6.5z"/></symbol>
<symbol id="it-w-alert" viewBox="0 0 24 24"><path d="M12 4 21 19H3zM12 10v4M12 16.5h.01"/></symbol>
</defs></svg>
<?php
}
