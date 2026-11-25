<?php
// Private-link register for ittai.ca/g/ (see gate.php). Key = SHA-256 of the code, never the code itself.
// Revoke a link by deleting its line and deploying. 'expires' is the last day it works (UTC).
return [
    'f0ce8ac4d15d129143c26f3cbaddf0b508b3cca4624e89e35767815bc772f696' => ['label' => 'gardeners-general', 'expires' => '2027-02-09'],
];
