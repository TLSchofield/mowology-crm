<?php
declare(strict_types=1);
// The Ittai legal pages' facts, read by mowology.ca (terms.php, ittai-privacy.php) and ittai.ca (/terms/, /privacy/).
// Not reviewed by a lawyer. The bracketed choices of the 2026-10-25 terms draft were settled on 2026-11-06 with the
// draft's own suggestions (Tim: Mowology Lawns & Landscapes Ltd is the party for now) for the Intuit app listing.
// The sign-in gate stays off until a terms_versions row is activated (migration 1381); TERMS_VERSION must match it.
if (!defined('TERMS_VERSION')) {
    define('TERMS_VERSION', '2026-11-06');
    define('TERMS_EFFECTIVE', 'November 6, 2026');
    define('ITTAI_PRIVACY_EFFECTIVE', 'November 6, 2026');
    define('ITTAI_LEGAL_ENTITY', 'Mowology Lawns &amp; Landscapes Ltd');
}
