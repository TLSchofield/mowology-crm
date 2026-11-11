<?php
/**
 * /crm/signup — the short address for the Ittai sign-up test APK (Tim, 2026-11-03).
 * Sends the phone straight to the current test build; change the one line below when the build moves.
 * Works on every host (mowology.ca and *.ittai.ca share this docroot); the APK itself lives on mowology.ca.
 */
header('Location: https://mowology.ca/crm/downloads/ittai-rehearsal-signup-v2-test.apk', true, 302);
header('Cache-Control: no-store');
exit;
