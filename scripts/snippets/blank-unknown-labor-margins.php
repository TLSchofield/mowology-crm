// Margin rows with NO labor figure cannot have a margin (2026-10-26): older captures stored labor NULL and a 100% margin.
// Unknown labor means unknown margin: gross_margin and margin_pct become NULL on those rows. Preview by default, `--commit` applies.
//   scripts/prod-run.sh scripts/snippets/blank-unknown-labor-margins.php [--commit]
$out = ['before' => $db->query("SELECT COUNT(*) n, ROUND(SUM(quoted_amount),2) quoted, MIN(computed_at) first_at, MAX(computed_at) last_at, MIN(margin_pct) min_pct, MAX(margin_pct) max_pct FROM visit_margin_snapshots WHERE labor_cost IS NULL AND margin_pct IS NOT NULL")->fetch(PDO::FETCH_ASSOC),
        'by_service' => $db->query("SELECT service_type, COUNT(*) n FROM visit_margin_snapshots WHERE labor_cost IS NULL AND margin_pct IS NOT NULL GROUP BY service_type")->fetchAll(PDO::FETCH_ASSOC),
        'avg_before' => $db->query("SELECT service_type, COUNT(margin_pct) with_margin, ROUND(AVG(margin_pct),1) avg_pct FROM visit_margin_snapshots GROUP BY service_type HAVING COUNT(*) >= 5")->fetchAll(PDO::FETCH_ASSOC)];
$out['rows_updated'] = $db->exec("UPDATE visit_margin_snapshots SET gross_margin = NULL, margin_pct = NULL WHERE labor_cost IS NULL AND margin_pct IS NOT NULL");
$out['avg_after'] = $db->query("SELECT service_type, COUNT(margin_pct) with_margin, ROUND(AVG(margin_pct),1) avg_pct FROM visit_margin_snapshots GROUP BY service_type HAVING COUNT(*) >= 5")->fetchAll(PDO::FETCH_ASSOC);
