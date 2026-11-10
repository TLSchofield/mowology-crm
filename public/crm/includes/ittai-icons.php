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
<symbol id="it-st-blocked" viewBox="0 0 16 16"><path d="M5.1 1h5.8L15 5.1v5.8L10.9 15H5.1L1 10.9V5.1z" fill="currentColor"/><path d="M4.6 8h6.8" fill="none" stroke-width="2.2" style="stroke:var(--it-surface)"/></symbol>
<symbol id="it-st-idle" viewBox="0 0 16 16"><path d="M8 2a6 6 0 1 1 0 12a6 6 0 1 1 0-12z" fill="none" stroke="currentColor" stroke-width="2" stroke-dasharray="2.6 2.1"/></symbol>
<symbol id="it-st-unavailable" viewBox="0 0 16 16"><path d="M8 2a6 6 0 1 1 0 12a6 6 0 1 1 0-12zM3.8 12.2 12.2 3.8" fill="none" stroke="currentColor" stroke-width="2"/></symbol>
<symbol id="it-i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/></symbol>
<symbol id="it-i-down" viewBox="0 0 24 24"><path d="M6 9.5l6 6 6-6"/></symbol>
<symbol id="it-i-right" viewBox="0 0 24 24"><path d="M9.5 6l6 6-6 6"/></symbol>
<symbol id="it-i-left" viewBox="0 0 24 24"><path d="M14.5 6l-6 6 6 6"/></symbol>
<symbol id="it-i-updown" viewBox="0 0 24 24"><path d="m7 15 5 5 5-5M7 9l5-5 5 5"/></symbol>
</defs></svg>
<?php
}
