<?php
declare(strict_types=1);

/**
 * Invoices shown on a contact's page.
 *
 * A strata rep / signatory is attached to a building three ways (properties.site_contact_id,
 * properties.quote_contact_id, property_contacts) and is the invoices.contact_id SIGNATORY,
 * while the payer is the invoice's client/company (see InvoiceRouting). The page used to look
 * only at site_contact_id properties, so a rep who is not the site contact saw $0 and no
 * invoices (Roland Bowman / Laburnum Heights, 2026-11-24). This lists every invoice that
 * reaches the contact by those links, names the account each is billed to, and totals
 * "outstanding" the same way the Penny team panel does (ContactTeamService::OPEN_STATUSES,
 * balance_due > 0.005).
 */
class ContactInvoiceService
{
    public const OPEN_STATUSES = ['sent', 'viewed', 'partial', 'overdue'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Property ids this contact is attached to, by every link. */
    public function propertyIds(int $contactId): array
    {
        $ids = $this->col('SELECT id FROM properties WHERE site_contact_id = ?', [$contactId]);
        $ids = array_merge($ids, $this->col('SELECT id FROM properties WHERE quote_contact_id = ?', [$contactId]));
        $ids = array_merge($ids, $this->col('SELECT property_id FROM property_contacts WHERE contact_id = ? AND ended_on IS NULL', [$contactId]));
        $ids = array_merge($ids, $this->col(
            'SELECT p.id FROM properties p JOIN clients c ON c.id = p.client_id WHERE c.legacy_contact_id = ?', [$contactId]));
        return self::uniq($ids);
    }

    /** The contact's own clients row (individual account), or 0. */
    public function ownClientId(int $contactId): int
    {
        $v = $this->col('SELECT id FROM clients WHERE legacy_contact_id = ? ORDER BY id LIMIT 1', [$contactId]);
        return (int)($v[0] ?? 0);
    }

    /**
     * @param int[] $planIds job plans of the contact's site-contact properties (kept from the old query)
     * @return array[] invoice rows + billed_to (name), billed_to_other (bool: billed to someone else's account)
     */
    public function invoices(int $contactId, array $planIds = []): array
    {
        $own = $this->ownClientId($contactId);
        $props = $this->propertyIds($contactId);
        $plans = self::uniq($planIds);

        $w = ['i.contact_id = ?'];
        $p = [$contactId];
        if ($own > 0) { $w[] = 'i.client_id = ?'; $p[] = $own; }
        if ($props) { $w[] = 'i.property_id IN (' . self::in($props) . ')'; }
        if ($plans) { $w[] = 'i.plan_id IN (' . self::in($plans) . ')'; }

        try {
            $s = $this->db->prepare("
                SELECT i.id, i.invoice_number, i.status, i.total, i.balance_due, i.issue_date, i.due_date,
                       i.amount_paid, i.payment_method, i.payment_reference, i.client_id, i.company_id,
                       co.company_name, cl.display_name AS client_name
                FROM invoices i
                LEFT JOIN companies co ON co.id = i.company_id
                LEFT JOIN clients cl ON cl.id = i.client_id
                WHERE " . implode(' OR ', $w) . "
                ORDER BY i.created_at DESC, i.id DESC");
            $s->execute($p);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        return self::label($rows, $own);
    }

    /** Pure: add billed_to / billed_to_other. A company beats the client row; no account → unlabelled. */
    public static function label(array $rows, int $ownClientId): array
    {
        foreach ($rows as &$r) {
            $name = trim((string)($r['company_name'] ?? '')) ?: trim((string)($r['client_name'] ?? ''));
            $cid = (int)($r['client_id'] ?? 0);
            $r['billed_to'] = $name;
            $r['billed_to_other'] = $name !== '' && (
                ((int)($r['company_id'] ?? 0) > 0) || ($cid > 0 && $ownClientId > 0 && $cid !== $ownClientId));
        }
        unset($r);
        return $rows;
    }

    /** Pure: [invoiced, paid, outstanding] — outstanding exactly as the team panel counts it. */
    public static function totals(array $rows): array
    {
        $invoiced = $paid = $out = 0.0;
        foreach ($rows as $r) {
            $invoiced += (float)($r['total'] ?? 0);
            $paid += (float)($r['amount_paid'] ?? 0);
            if (in_array($r['status'] ?? '', self::OPEN_STATUSES, true) && (float)($r['balance_due'] ?? 0) > 0.005) {
                $out += (float)$r['balance_due'];
            }
        }
        return ['invoiced' => round($invoiced, 2), 'paid' => round($paid, 2), 'outstanding' => round($out, 2)];
    }

    private function col(string $sql, array $p): array
    {
        try {
            $s = $this->db->prepare($sql);
            $s->execute($p);
            return $s->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function in(array $ids): string
    {
        $ids = array_map('intval', $ids);
        return $ids ? implode(',', $ids) : '0';
    }

    public static function uniq(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }
}
