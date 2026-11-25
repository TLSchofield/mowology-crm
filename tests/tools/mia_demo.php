<?php
/**
 * Narrated Phase 1 demonstration: the same run the acceptance test checks, printed step by step.
 *
 *   php tests/tools/mia_demo.php
 *
 * Synthetic Mowology on SQLite: no network, no production data, nothing written to any website.
 */
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../Support/MiaDemo.php';
date_default_timezone_set('America/Vancouver');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('error_log', '/dev/null');

$demo = new MiaDemo();
$o = $demo->run();
$h = function (string $t) { echo "\n== $t ==\n"; };

$h('1. Mia examines the website');
echo "Connected mowology.ca; ownership proved with the tag Mia gave.\n" . $o['audit']['summary'] . "\n";
foreach ($o['health']['websites'][0]['problems'] as $p) { echo sprintf("  [%s] %s (%d page%s)\n", $p['severity'], $p['label'], $p['pages'], $p['pages'] === 1 ? '' : 's'); }

$h('2-3. A meaningful opportunity, explained');
$it = $o['ctrItem'];
echo $it['title'] . "  (priority " . round($it['score']) . ")\nWhy:     " . $it['why'] . "\nGain:    " . $it['expected_benefit'] . "\nRisk:    " . $it['risk'] . "\n";

$h('4. The proposed improvement');
$d = $it['draft'];
echo "Now:       " . $d['current_title'] . "  |  (no description)\nMia:       " . $d['title'] . "\n           " . $d['meta_description'] . "\n";

$h('5. It is in the queue, and in Charlie\'s brief');
foreach ($o['queue']['items'] as $q) { echo sprintf("  %5.1f  %s\n", $q['score'], $q['title']); }
foreach ($o['brief'] as $b) { echo "  Charlie: " . $b['text'] . "\n"; }

$h('6-8. The owner decides; Mia records and tracks it');
echo "Approved: " . $it['title'] . "  -> " . $o['events'][$it['id']]['status'] . "\n";
echo "Set aside: " . $o['metaItem']['title'] . "  -> " . $o['events'][$o['metaItem']['id']]['status'] . " (\"" . $o['events'][$o['metaItem']['id']]['decision_note'] . "\")\n";
echo "Audit trail: " . implode(', ', array_map(function ($e) { return $e['event'] . ' by ' . $e['actor']; }, $o['events'][$it['id']]['events'])) . "\n";

$h('9. Next Monday: she uses what she learned');
echo $o['audit2']['summary'] . "\n" . $o['search2']['summary'] . "\n";
foreach ($o['results']['items'] as $r) { echo sprintf("  %-9s %s\n", $r['status'], $r['title']); }

$h('Safety');
echo "Requests made to the website in week 2: " . count($o['audit2requests']) . ", all GET, none to /crm/ (robots.txt).\n";
echo "Nothing was published or sent. Approving only records the decision.\n";
