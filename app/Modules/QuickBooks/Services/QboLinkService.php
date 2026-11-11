<?php
declare(strict_types=1);

/** Shared qbo_links writer used by sending and by the onboarding import. */
final class QboLinkService
{
    /** @return string saved, unchanged, or conflict */
    public static function save(PDO $db, int $siteId, string $realmId, string $type, string $entityId,
                                ?string $qboId, ?string $token, string $requestId, string $status,
                                ?float $total, ?string $message, string $now, bool $keepDifferent = false): string
    {
        $find = $db->prepare('SELECT id, qbo_id FROM qbo_links WHERE site_id = ? AND realm_id = ? AND entity_type = ? AND entity_id = ?');
        $find->execute([$siteId, $realmId, $type, $entityId]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $existing = (string)($row['qbo_id'] ?? '');
            if ($keepDifferent && $existing !== '' && $qboId !== null && $existing !== $qboId) return 'conflict';
            if ($keepDifferent && $existing === (string)$qboId) return 'unchanged';
            $u = $db->prepare('UPDATE qbo_links SET qbo_id = ?, sync_token = ?, request_id = ?, status = ?, qbo_total = ?, message = ?, sent_at = ?
                               WHERE site_id = ? AND realm_id = ? AND entity_type = ? AND entity_id = ?');
            $u->execute([$qboId ?: null, $token ?: null, $requestId, $status, $total,
                         $message !== null ? mb_substr($message, 0, 500) : null, $now,
                         $siteId, $realmId, $type, $entityId]);
            return 'saved';
        }
        $db->prepare('INSERT INTO qbo_links (qbo_id, sync_token, request_id, status, qbo_total, message, sent_at, site_id, realm_id, entity_type, entity_id)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$qboId ?: null, $token ?: null, $requestId, $status, $total,
                      $message !== null ? mb_substr($message, 0, 500) : null, $now,
                      $siteId, $realmId, $type, $entityId]);
        return 'saved';
    }
}
