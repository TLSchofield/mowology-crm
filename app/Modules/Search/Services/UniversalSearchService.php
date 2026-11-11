<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/ChiefOfStaff/Services/CharlieAskService.php';

class UniversalSearchService
{
    /** Rows read per query before learned order and the per-group cut — a 2-letter search must not read every email. */
    public const FETCH_MAX = 60;

    private PDO $db;
    private ?string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today;
    }

    public static function normalize(string $q): string
    {
        return mb_substr(trim((string)preg_replace('/\s+/u', ' ', $q)), 0, 80);
    }

    public static function tokens(string $q): array
    {
        $out = [];
        foreach (preg_split('/\s+/u', mb_strtolower(self::normalize($q))) ?: [] as $word) {
            if ($word === '') continue;
            $out[] = CharlieAskService::nameForms($word);
            if (count($out) === 4) break;
        }
        return $out;
    }

    public static function digits(string $q): string
    {
        return (string)preg_replace('/\D+/', '', $q);
    }

    public static function prefix(string $q): string
    {
        $words = preg_split('/\s+/u', mb_strtolower(self::normalize($q))) ?: [];
        return mb_substr(implode(' ', array_slice(array_values(array_filter($words, 'strlen')), 0, 3)), 0, 40);
    }

    private function where(array $fields, array $tokenForms, array &$params, ?string $phoneField = null, string $phoneDigits = ''): string
    {
        $ands = [];
        foreach ($tokenForms as $forms) {
            $ors = [];
            foreach ($forms as $form) {
                foreach ($fields as $field) {
                    $ors[] = $field . ' LIKE ?';
                    $params[] = '%' . $form . '%';
                }
            }
            $ands[] = '(' . implode(' OR ', $ors) . ')';
        }
        $text = $ands ? implode(' AND ', $ands) : '1=1';
        if ($phoneField !== null && strlen($phoneDigits) >= 4) {
            $params[] = '%' . $phoneDigits . '%';
            return "(($text) OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($phoneField,''),'-',''),' ',''),'(',''),')',''),'.','') LIKE ?)";
        }
        return $text;
    }

    private function picked(array $items, int $userId, string $prefix): array
    {
        try {
            $s = $this->db->prepare('SELECT kind, entity_id, picks, last_at FROM search_picks WHERE user_id = ? AND prefix = ? ORDER BY picks DESC, last_at DESC LIMIT 200');
            $s->execute([$userId, $prefix]);
            $rank = [];
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $rank[$r['kind'] . ':' . $r['entity_id']] = [(int)$r['picks'], (string)$r['last_at']];
            usort($items, static function (array $a, array $b) use ($rank): int {
                $ar = $rank[$a['kind'] . ':' . $a['id']] ?? [0, ''];
                $br = $rank[$b['kind'] . ':' . $b['id']] ?? [0, ''];
                return $br[0] <=> $ar[0] ?: strcmp($br[1], $ar[1]);
            });
        } catch (Throwable $e) { /* migration may not be present yet */ }
        return $items;
    }

    private function group(string $key, string $title, array $items, int $limit, int $userId, string $prefix): ?array
    {
        if (!$items) return null;
        $items = $this->picked($items, $userId, $prefix);
        return ['key' => $key, 'title' => $title, 'more' => count($items) > $limit,
                'items' => array_slice($items, 0, $limit)];
    }

    /** Words that name a group of results ("draft quotes", "linda invoices"), removed from the LIKE words. */
    private const GROUP_WORDS = [
        'quote' => 'quotes', 'quotes' => 'quotes',
        'invoice' => 'invoices', 'invoices' => 'invoices',
        'job' => 'jobs', 'jobs' => 'jobs', 'plan' => 'jobs', 'plans' => 'jobs', 'contract' => 'jobs', 'contracts' => 'jobs',
        'receipt' => 'receipts', 'receipts' => 'receipts', 'expense' => 'receipts', 'expenses' => 'receipts',
        'email' => 'messages', 'emails' => 'messages', 'message' => 'messages', 'messages' => 'messages', 'text' => 'messages', 'texts' => 'messages',
        'property' => 'properties', 'properties' => 'properties', 'address' => 'properties',
        'supplier' => 'vendors', 'suppliers' => 'vendors', 'vendor' => 'vendors', 'vendors' => 'vendors',
        'client' => 'people', 'clients' => 'people', 'people' => 'people',
    ];

    /** Words that filter by state ("overdue", "draft quotes"), mapped to a state key. */
    private const STATE_WORDS = [
        'unpaid' => 'unpaid', 'owing' => 'unpaid', 'owes' => 'unpaid', 'overdue' => 'overdue',
        'draft' => 'draft', 'drafts' => 'draft', 'open' => 'open', 'accepted' => 'accepted',
    ];

    /**
     * Pull group and state words out of a query.
     * @return array{words:string[], groups:string[], state:string[]}
     */
    public static function filterWords(string $q): array
    {
        $words = $groups = $state = [];
        foreach (preg_split('/\s+/u', self::normalize($q)) ?: [] as $word) {
            if ($word === '') continue;
            $key = mb_strtolower((string)preg_replace('/[^\p{L}\p{N}]+/u', '', $word));
            if (isset(self::GROUP_WORDS[$key])) { $groups[self::GROUP_WORDS[$key]] = true; continue; }
            if (isset(self::STATE_WORDS[$key])) { $state[self::STATE_WORDS[$key]] = true; continue; }
            $words[] = $word;
        }
        return ['words' => $words, 'groups' => array_keys($groups), 'state' => array_keys($state)];
    }

    /** Groups a filter limits the search to, or null for all of them. */
    private static function onlyGroups(array $f): ?array
    {
        if ($f['groups']) return $f['groups'];
        $out = [];
        foreach ($f['state'] as $s) {
            if ($s === 'unpaid' || $s === 'overdue') $out[] = 'invoices';
            elseif ($s === 'draft' || $s === 'accepted') $out[] = 'quotes';
            else { $out[] = 'quotes'; $out[] = 'invoices'; }
        }
        return $out ? array_values(array_unique($out)) : null;
    }

    private static function cleanScope(?array $scope): ?array
    {
        if (!$scope) return null;
        $kind = (string)($scope['kind'] ?? '');
        $id = (int)($scope['id'] ?? 0);
        return ($kind === 'contact' || $kind === 'company') && $id > 0 ? ['kind' => $kind, 'id' => $id] : null;
    }

    /** Alias a property row for "this contact's properties": site contact, client, or a linked contact. */
    private static function contactProperty(string $a): string
    {
        return "($a.site_contact_id = ? OR $a.client_id = ? OR $a.id IN (SELECT pc.property_id FROM property_contacts pc WHERE pc.contact_id = ?))";
    }

    /** [condition, params] limiting a group to the scoped person; null when that group has nothing for them. */
    private function scopeCond(array $scope, string $what): ?array
    {
        $id = $scope['id'];
        $contact = $scope['kind'] === 'contact';
        switch ($what) {
            case 'properties': return $contact ? [self::contactProperty('p'), [$id, $id, $id]] : ['p.company_id = ?', [$id]];
            case 'quotes':     return $contact ? ['q.contact_id = ?', [$id]] : ['q.company_id = ?', [$id]];
            case 'invoices':   return $contact ? ['i.contact_id = ?', [$id]] : ['i.company_id = ?', [$id]];
            case 'contracts':  return $contact ? ['co.contact_id = ?', [$id]] : ['co.company_id = ?', [$id]];
            case 'plans':
                return $contact
                    ? ['(j.client_id = ? OR j.property_id IN (SELECT p.id FROM properties p WHERE ' . self::contactProperty('p') . '))', [$id, $id, $id, $id]]
                    : ['j.company_id = ?', [$id]];
            case 'messages':   return $contact ? ['m.contact_id = ?', [$id]] : ['m.contact_id IN (SELECT id FROM contacts WHERE company_id = ?)', [$id]];
            case 'receipts':   return $contact ? ['e.contact_id = ?', [$id]] : null;
        }
        return null;
    }

    private function allowed(string $kind, callable $can): bool
    {
        switch ($kind) {
            case 'contact': case 'company': case 'property': return (bool)$can('clients.view');
            case 'quote': case 'invoice': case 'vendor': return (bool)$can('billing.view');
            case 'job': case 'contract': return (bool)$can('jobs.view');
            case 'receipt': return $can('expenses.view') && $can('billing.view');
            case 'message': return $can('clients.view') && $can('billing.view');
            case 'team': return $can('team.view') && $can('billing.view');
        }
        return false;
    }

    /**
     * Rows of one kind as group items (one shape for search groups and learned results). $where is ANDed to the
     * kind's own base condition; $minimal is the companies fallback for installs without the billing columns.
     */
    private function fetch(string $kind, string $where, array $p, string $order, int $limit, bool $minimal = false): array
    {
        $items = [];
        switch ($kind) {
            case 'contact':
                $s = $this->db->prepare("SELECT c.id,c.first_name,c.last_name,c.email,c.phone FROM contacts c WHERE c.is_active=1 AND $where ORDER BY $order LIMIT $limit"); $s->execute($p);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                    $items[] = ['kind'=>'contact','id'=>(int)$r['id'],'label'=>$name,'sublabel'=>$r['email'] ?: ($r['phone'] ?? ''),'phone'=>$r['phone'] ?? null,'email'=>$r['email'] ?? null,'url'=>'/crm/clients_appstack.php?action=view_contact&id='.(int)$r['id']];
                }
                break;
            case 'company':
                $cols = $minimal ? 'co.id,co.company_name,co.company_type,NULL AS billing_city,NULL AS billing_email' : 'co.id,co.company_name,co.company_type,co.billing_city,co.billing_email';
                $s = $this->db->prepare("SELECT $cols FROM companies co WHERE co.account_status='active' AND $where ORDER BY $order LIMIT $limit"); $s->execute($p);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sub = ucfirst(str_replace('_', ' ', (string)($r['company_type'] ?? ''))); if (!empty($r['billing_city'])) $sub .= ($sub ? ' · ' : '') . $r['billing_city'];
                    $items[] = ['kind'=>'company','id'=>(int)$r['id'],'label'=>$r['company_name'],'sublabel'=>$sub,'phone'=>null,'email'=>$r['billing_email'] ?? null,'url'=>'/crm/companies/view.php?id='.(int)$r['id']];
                }
                break;
            case 'property':
                $s = $this->db->prepare("SELECT p.id,p.address,p.city,c.first_name,c.last_name FROM properties p LEFT JOIN contacts c ON c.id=p.site_contact_id WHERE p.status='active' AND $where ORDER BY $order LIMIT $limit"); $s->execute($p);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                    $items[] = ['kind'=>'property','id'=>(int)$r['id'],'property_id'=>(int)$r['id'],'label'=>$r['address'],'sublabel'=>implode(' · ', array_filter([$r['city'] ?? '', $name])),'url'=>'/crm/properties/view.php?id='.(int)$r['id']];
                }
                break;
            case 'quote':
                $s = $this->db->prepare("SELECT q.id,q.quote_number,q.title,q.status,COALESCE(NULLIF(q.amount,0),NULLIF(q.total_amount,0),0) AS total_amount,q.contact_id,q.sent_at,q.email_opened_at,q.viewed_at,c.first_name,c.last_name FROM quotes q LEFT JOIN contacts c ON c.id=q.contact_id LEFT JOIN properties pr ON pr.id=q.property_id WHERE $where ORDER BY $order LIMIT $limit"); $s->execute($p);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sub = ($r['status'] ?? '') . ' · $' . number_format((float)$r['total_amount'], 2);
                    if (!empty($r['sent_at'])) $sub .= ' · sent ' . date('M j', strtotime($r['sent_at']));
                    $items[] = ['kind'=>'quote','id'=>(int)$r['id'],'label'=>$r['quote_number'].(!empty($r['title']) ? ' — '.$r['title'] : ''),'sublabel'=>$sub,'status'=>$r['status'],'total'=>(float)$r['total_amount'],'contact_id'=>(int)$r['contact_id'],'sent_at'=>$r['sent_at'],'opened'=>!empty($r['email_opened_at']) || !empty($r['viewed_at']),'url'=>'/crm/quotes/view.php?id='.(int)$r['id']];
                }
                break;
            case 'invoice':
                $s = $this->db->prepare("SELECT i.id,i.invoice_number,i.total,i.balance_due,i.status,i.contact_id,i.due_date,i.paid_at FROM invoices i LEFT JOIN contacts c ON c.id=i.contact_id WHERE $where ORDER BY $order LIMIT $limit"); $s->execute($p);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $bal = (float)$r['balance_due'];
                    $sub = '$' . number_format((float)$r['total'], 2) . ' · ' . ($bal > 0 ? 'owing $' . number_format($bal, 2) : 'paid' . (!empty($r['paid_at']) ? ' ' . date('M j', strtotime($r['paid_at'])) : ''));
                    if ($bal > 0 && !empty($r['due_date']) && $r['due_date'] < $this->today()) $sub .= ' · ' . max(0, (int)((strtotime($this->today()) - strtotime($r['due_date'])) / 86400)) . ' days overdue';
                    $items[] = ['kind'=>'invoice','id'=>(int)$r['id'],'label'=>$r['invoice_number'],'sublabel'=>$sub,'balance'=>$bal,'status'=>$r['status'],'contact_id'=>(int)$r['contact_id'],'url'=>'/crm/invoices/view.php?id='.(int)$r['id']];
                }
                break;
            case 'job':
                $s = $this->db->prepare("SELECT j.id,j.plan_number,j.title,j.status,j.service_type FROM job_plans j WHERE $where ORDER BY $order LIMIT $limit"); $s->execute($p);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $items[] = ['kind'=>'job','id'=>(int)$r['id'],'label'=>$r['plan_number'].(!empty($r['title']) ? ' — '.$r['title'] : ''),'sublabel'=>implode(' · ', array_filter([$r['status'] ?? '', $r['service_type'] ?? ''])),'url'=>'/crm/jobs/view.php?id='.(int)$r['id']];
                break;
            case 'contract':
                $s = $this->db->prepare("SELECT co.id,co.contract_number,co.title,co.status,pr.address,c.first_name,c.last_name FROM contracts co LEFT JOIN properties pr ON pr.id=co.property_id LEFT JOIN contacts c ON c.id=co.contact_id WHERE $where ORDER BY $order LIMIT $limit"); $s->execute($p);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $items[] = ['kind'=>'contract','id'=>(int)$r['id'],'label'=>$r['contract_number'].(!empty($r['title']) ? ' — '.$r['title'] : ''),'sublabel'=>implode(' · ', array_filter([$r['status'] ?? '', $r['address'] ?? '', trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))])),'url'=>'/crm/contracts/view.php?id='.(int)$r['id']];
                break;
            case 'receipt':
                $s = $this->db->prepare("SELECT e.id,e.vendor_name_raw,e.description,e.total,e.expense_date,v.name vendor_name FROM expenses e LEFT JOIN vendors v ON v.id=e.vendor_id WHERE e.status NOT IN ('rejected','deleted') AND $where ORDER BY $order LIMIT $limit"); $s->execute($p);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $items[] = ['kind'=>'receipt','id'=>(int)$r['id'],'label'=>$r['vendor_name'] ?: ($r['vendor_name_raw'] ?: $r['description']),'sublabel'=>'$'.number_format((float)$r['total'], 2).' · '.date('M j, Y', strtotime($r['expense_date'])),'url'=>'/crm/expenses_appstack.php?id='.(int)$r['id']];
                break;
            case 'vendor':
                $s = $this->db->prepare("SELECT v.id,v.name FROM vendors v WHERE $where ORDER BY $order LIMIT $limit"); $s->execute($p);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $items[] = ['kind'=>'vendor','id'=>(int)$r['id'],'label'=>$r['name'],'sublabel'=>'','url'=>'/crm/expenses_appstack.php?vendor='.(int)$r['id']];
                break;
            case 'message':
                $s = $this->db->prepare("SELECT m.id,m.subject,m.snippet,m.from_addr,m.direction,m.sent_at,m.contact_id,c.first_name,c.last_name FROM sales_messages m LEFT JOIN contacts c ON c.id=m.contact_id WHERE $where ORDER BY $order LIMIT $limit"); $s->execute($p);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')) ?: $r['from_addr'];
                    $dir = $r['direction'] === 'inbound' ? '→ you' : 'you →';
                    $items[] = ['kind'=>'message','id'=>(int)$r['id'],'label'=>$name.(trim((string)($r['subject'] ?? '')) !== '' ? ': '.$r['subject'] : ''),'sublabel'=>$dir.' '.mb_substr((string)$r['snippet'], 0, 100).' · '.date('M j', strtotime($r['sent_at'])),'contact_id'=>(int)$r['contact_id']];
                }
                break;
            case 'team':
                $s = $this->db->prepare("SELECT u.id,u.full_name,u.email FROM users u WHERE u.is_active=1 AND $where ORDER BY $order LIMIT $limit"); $s->execute($p);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $items[] = ['kind'=>'team','id'=>(int)$r['id'],'label'=>$r['full_name'],'sublabel'=>$r['email'] ?? '','url'=>'/crm/team/index.php'];
                break;
        }
        return $items;
    }

    /** Ordering of each kind's rows, newest first, and the table alias its id column lives under. */
    private const KIND_ALIAS = ['contact'=>'c','company'=>'co','property'=>'p','quote'=>'q','invoice'=>'i','job'=>'j','contract'=>'co','receipt'=>'e','vendor'=>'v','message'=>'m','team'=>'u'];

    /** What this person picked before for this query, that the groups did not already show (the "what did you mean?" list). */
    private function learned(int $userId, string $prefix, array $groups, callable $can): ?array
    {
        if ($prefix === '' || $userId < 1) return null;
        $seen = [];
        foreach ($groups as $g) foreach ($g['items'] as $i) $seen[$i['kind'] . ':' . $i['id']] = true;
        try {
            $s = $this->db->prepare('SELECT kind, entity_id FROM search_picks WHERE user_id = ? AND prefix = ? ORDER BY picks DESC, last_at DESC LIMIT 20');
            $s->execute([$userId, $prefix]);
            $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { return null; /* migration may not be present yet */ }
        $items = [];
        foreach ($rows as $r) {
            if (count($items) >= 5) break;
            $kind = (string)$r['kind'];
            $id = (int)$r['entity_id'];
            if (isset($seen[$kind . ':' . $id]) || !isset(self::KIND_ALIAS[$kind]) || !$this->allowed($kind, $can)) continue;
            try {
                $a = self::KIND_ALIAS[$kind];
                $found = $this->fetch($kind, "$a.id = ?", [$id], "$a.id", 1);
            } catch (Throwable $e) { error_log('[search] learned ' . $kind . ' failed: ' . $e->getMessage()); continue; }
            if ($found) { $items[] = $found[0]; $seen[$kind . ':' . $id] = true; }
        }
        return $items ? ['key' => 'learned', 'title' => 'You picked before', 'more' => false, 'items' => $items] : null;
    }

    /**
     * Grouped owner search. $scope = ['kind' => 'contact'|'company', 'id' => int] narrows everything to one person
     * (the query may then be empty: newest rows of each group). Group words and state words in $q ("overdue",
     * "draft quotes") limit the groups and filter the rows; the remaining words go to LIKE.
     */
    /** "linda" lists Linda Chou and Linda Tam before Belinda: a word that IS a name beats one inside a name. Stable. */
    public static function wholeNamesFirst(array $items, array $tokens): array
    {
        $forms = [];
        foreach ($tokens as $set) foreach ((array)$set as $form) $forms[mb_strtolower((string)$form)] = true;
        $keyed = [];
        foreach (array_values($items) as $i => $item) {
            $hits = 0;
            foreach (preg_split('/[\s,.\-]+/u', mb_strtolower((string)($item['label'] ?? ''))) ?: [] as $w) if (isset($forms[$w])) $hits++;
            $keyed[] = [$hits, $i, $item];
        }
        usort($keyed, static function (array $a, array $b): int { return $b[0] <=> $a[0] ?: $a[1] <=> $b[1]; });
        return array_column($keyed, 2);
    }

    public function search(string $q, callable $can, int $userId, int $perGroup = 5, ?array $scope = null): array
    {
        $q = self::normalize($q);
        $scope = self::cleanScope($scope);
        $f = self::filterWords($q);
        $words = implode(' ', $f['words']);
        $tokens = self::tokens($words);
        $only = self::onlyGroups($f);
        if (!$tokens && !$scope && $only === null) return ['groups' => []];
        $prefix = self::prefix($q);
        $digits = self::digits($words);
        $on = static function (string $key) use ($only): bool { return $only === null || in_array($key, $only, true); };
        $state = $f['state'];
        $groups = [];

        if ($on('people') && !$scope && $can('clients.view')) try {
            $p = []; $where = $this->where(['c.first_name','c.last_name','c.email','c.phone'], $tokens, $p, 'c.phone', $digits);
            $items = self::wholeNamesFirst($this->fetch('contact', $where, $p, 'c.first_name,c.last_name', self::FETCH_MAX), $tokens);
            try {
                $p = []; $where = $this->where(['co.company_name','co.billing_email','co.billing_city'], $tokens, $p);
                $companies = $this->fetch('company', $where, $p, 'co.company_name', self::FETCH_MAX);
            } catch (Throwable $companyError) {
                error_log('[search] companies failed: ' . $companyError->getMessage());
                $p = []; $where = $this->where(['co.company_name'], $tokens, $p);
                $companies = $this->fetch('company', $where, $p, 'co.company_name', self::FETCH_MAX, true);
            }
            if ($g = $this->group('people', 'People', array_merge($items, $companies), $perGroup, $userId, $prefix)) $groups[] = $g;
        } catch (Throwable $e) { error_log('[search] people failed: ' . $e->getMessage()); }

        if ($on('properties') && $can('clients.view')) try {
            $conds = []; $p = [];
            if ($scope) { $sc = $this->scopeCond($scope, 'properties'); $conds[] = $sc[0]; $p = array_merge($p, $sc[1]); }
            $conds[] = $this->where(['p.address','p.city','p.postal_code','c.first_name','c.last_name'], $tokens, $p);
            if ($g = $this->group('properties', 'Properties', $this->fetch('property', implode(' AND ', $conds), $p, 'p.address', self::FETCH_MAX), $perGroup, $userId, $prefix)) $groups[] = $g;
        } catch (Throwable $e) { error_log('[search] properties failed: ' . $e->getMessage()); }

        if ($on('quotes') && $can('billing.view')) try {
            $conds = []; $p = [];
            if ($scope) { $sc = $this->scopeCond($scope, 'quotes'); $conds[] = $sc[0]; $p = array_merge($p, $sc[1]); }
            $statuses = [];
            if (in_array('draft', $state, true)) $statuses[] = 'draft';
            if (in_array('open', $state, true)) { $statuses[] = 'draft'; $statuses[] = 'sent'; }
            if (in_array('accepted', $state, true)) $statuses[] = 'accepted';
            if ($statuses) { $statuses = array_values(array_unique($statuses)); $conds[] = 'q.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'; $p = array_merge($p, $statuses); }
            $conds[] = $this->where(['q.quote_number','q.title','c.first_name','c.last_name','pr.address'], $tokens, $p);
            if ($g = $this->group('quotes', 'Quotes', $this->fetch('quote', implode(' AND ', $conds), $p, 'q.created_at DESC', self::FETCH_MAX), $perGroup, $userId, $prefix)) $groups[] = $g;
        } catch (Throwable $e) { error_log('[search] quotes failed: ' . $e->getMessage()); }

        if ($on('invoices') && $can('billing.view')) try {
            $conds = []; $p = [];
            if ($scope) { $sc = $this->scopeCond($scope, 'invoices'); $conds[] = $sc[0]; $p = array_merge($p, $sc[1]); }
            $overdue = in_array('overdue', $state, true);
            if ($overdue || in_array('unpaid', $state, true) || in_array('open', $state, true)) {
                $conds[] = "i.balance_due > 0 AND i.status NOT IN ('draft','cancelled')";
                if ($overdue) { $conds[] = 'i.due_date < ?'; $p[] = $this->today(); }
            } elseif (in_array('draft', $state, true)) {
                $conds[] = "i.status = 'draft'";   // "draft invoices"
            }
            $conds[] = $this->where(['i.invoice_number','i.bill_to_name','c.first_name','c.last_name','i.service_address'], $tokens, $p);
            $order = $overdue ? 'i.due_date ASC, i.id ASC' : 'i.created_at DESC';
            if ($g = $this->group('invoices', 'Invoices', $this->fetch('invoice', implode(' AND ', $conds), $p, $order, self::FETCH_MAX), $perGroup, $userId, $prefix)) $groups[] = $g;
        } catch (Throwable $e) { error_log('[search] invoices failed: ' . $e->getMessage()); }

        if ($on('jobs') && $can('jobs.view')) try {
            $items = [];
            try {
                $conds = []; $p = [];
                if ($scope) { $sc = $this->scopeCond($scope, 'plans'); $conds[] = $sc[0]; $p = array_merge($p, $sc[1]); }
                $conds[] = $this->where(['j.plan_number','j.title','j.service_type'], $tokens, $p);
                $items = $this->fetch('job', implode(' AND ', $conds), $p, 'j.created_at DESC', self::FETCH_MAX);
            } catch (Throwable $e) { error_log('[search] job plans failed: ' . $e->getMessage()); }
            try {
                $conds = []; $p = [];
                if ($scope) { $sc = $this->scopeCond($scope, 'contracts'); $conds[] = $sc[0]; $p = array_merge($p, $sc[1]); }
                $conds[] = $this->where(['co.contract_number','co.title','pr.address','c.first_name','c.last_name'], $tokens, $p);
                $items = array_merge($items, $this->fetch('contract', implode(' AND ', $conds), $p, "(co.status='active') DESC,co.id DESC", self::FETCH_MAX));
            } catch (Throwable $e) { error_log('[search] contracts failed: ' . $e->getMessage()); }
            if ($g = $this->group('jobs', 'Jobs', $items, $perGroup, $userId, $prefix)) $groups[] = $g;
        } catch (Throwable $e) { error_log('[search] jobs failed: ' . $e->getMessage()); }

        if ($on('receipts') && $can('expenses.view') && $can('billing.view') && (!$scope || $scope['kind'] === 'contact')) try {
            $conds = []; $p = [];
            if ($scope) { $sc = $this->scopeCond($scope, 'receipts'); $conds[] = $sc[0]; $p = array_merge($p, $sc[1]); }
            $conds[] = $this->where(['e.vendor_name_raw','v.name','e.description'], $tokens, $p);
            if ($g = $this->group('receipts', 'Receipts', $this->fetch('receipt', implode(' AND ', $conds), $p, 'e.expense_date DESC', self::FETCH_MAX), $perGroup, $userId, $prefix)) $groups[] = $g;
        } catch (Throwable $e) { error_log('[search] receipts failed: ' . $e->getMessage()); }

        if ($on('vendors') && !$scope && $can('billing.view')) try {
            $p = []; $where = $this->where(['v.name'], $tokens, $p);
            if ($g = $this->group('vendors', 'Suppliers', $this->fetch('vendor', $where, $p, 'v.name', self::FETCH_MAX), $perGroup, $userId, $prefix)) $groups[] = $g;
        } catch (Throwable $e) { error_log('[search] vendors failed: ' . $e->getMessage()); }

        if ($on('messages') && $can('clients.view') && $can('billing.view')) try {
            $conds = []; $p = [];
            if ($scope) { $sc = $this->scopeCond($scope, 'messages'); $conds[] = $sc[0]; $p = array_merge($p, $sc[1]); }
            $conds[] = $this->where(['m.subject','m.snippet','m.from_addr','c.first_name','c.last_name'], $tokens, $p);
            if ($g = $this->group('messages', 'Emails & texts', $this->fetch('message', implode(' AND ', $conds), $p, 'm.sent_at DESC', self::FETCH_MAX), $perGroup, $userId, $prefix)) $groups[] = $g;
        } catch (Throwable $e) { error_log('[search] messages failed: ' . $e->getMessage()); }

        if ($on('team') && !$scope && $can('team.view') && $can('billing.view')) try {
            $p = []; $where = $this->where(['u.full_name','u.email'], $tokens, $p);
            if ($g = $this->group('team', 'Team', $this->fetch('team', $where, $p, 'u.full_name', self::FETCH_MAX), $perGroup, $userId, $prefix)) $groups[] = $g;
        } catch (Throwable $e) { error_log('[search] team failed: ' . $e->getMessage()); }

        if (!$scope && ($learned = $this->learned($userId, $prefix, $groups, $can))) array_unshift($groups, $learned);
        return ['groups' => $groups];
    }
    /**
     * One client's page on the phone (mode=contact): who they are, their properties, open quotes, unpaid invoices and the
     * last few emails/texts — the same item shape as search(), so the phone reuses its rows. Null when the contact is gone.
     */
    public function contact(int $id, callable $can): ?array
    {
        if ($id < 1 || !$can('clients.view')) return null;
        $s = $this->db->prepare('SELECT c.id, c.first_name, c.last_name, c.email, c.phone, c.company_id FROM contacts c WHERE c.id = ? AND c.is_active = 1');
        try { $s->execute([$id]); } catch (Throwable $e) {
            // company_id is optional on older schemas.
            $s = $this->db->prepare('SELECT c.id, c.first_name, c.last_name, c.email, c.phone, NULL AS company_id FROM contacts c WHERE c.id = ? AND c.is_active = 1');
            $s->execute([$id]);
        }
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;
        $company = null;
        if (!empty($c['company_id'])) try {
            $q = $this->db->prepare('SELECT company_name FROM companies WHERE id = ?');
            $q->execute([(int)$c['company_id']]);
            $company = $q->fetchColumn() ?: null;
        } catch (Throwable $e) { error_log('[search] contact company failed: ' . $e->getMessage()); }
        $out = [
            'contact'    => ['id' => (int)$c['id'], 'name' => trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? '')),
                             'company' => $company, 'phone' => $c['phone'] ?: null, 'email' => $c['email'] ?: null],
            'properties' => [], 'quotes' => [], 'invoices' => [], 'messages' => [],
        ];

        try {
            $sql = "SELECT DISTINCT p.id, p.address, p.city FROM properties p
                    WHERE p.status = 'active' AND (p.site_contact_id = ? OR p.client_id = ?)
                    UNION
                    SELECT p.id, p.address, p.city FROM properties p JOIN property_contacts pc ON pc.property_id = p.id
                    WHERE p.status = 'active' AND pc.contact_id = ?
                    ORDER BY address LIMIT 20";
            $s = $this->db->prepare($sql);
            $s->execute([$id, $id, $id]);
        } catch (Throwable $e) {
            error_log('[search] contact properties (with property_contacts) failed: ' . $e->getMessage());
            $s = $this->db->prepare("SELECT p.id, p.address, p.city FROM properties p WHERE p.status = 'active' AND p.site_contact_id = ? ORDER BY p.address LIMIT 20");
            $s->execute([$id]);
        }
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out['properties'][] = ['kind' => 'property', 'id' => (int)$r['id'], 'property_id' => (int)$r['id'], 'label' => (string)$r['address'],
                                    'sublabel' => (string)($r['city'] ?? ''), 'url' => '/crm/properties/view.php?id=' . (int)$r['id']];
        }

        if ($can('billing.view')) {
            try {
                $s = $this->db->prepare("SELECT q.id, q.quote_number, q.title, q.status, COALESCE(NULLIF(q.amount,0),NULLIF(q.total_amount,0),0) AS total_amount, q.sent_at FROM quotes q
                    WHERE q.contact_id = ? AND q.status IN ('draft','sent','viewed','pending') ORDER BY q.created_at DESC LIMIT 10");
                $s->execute([$id]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sub = ($r['status'] ?? '') . ' · $' . number_format((float)$r['total_amount'], 2);
                    if (!empty($r['sent_at'])) $sub .= ' · sent ' . date('M j', strtotime((string)$r['sent_at']));
                    $out['quotes'][] = ['kind' => 'quote', 'id' => (int)$r['id'], 'label' => $r['quote_number'] . (!empty($r['title']) ? ' — ' . $r['title'] : ''),
                                        'sublabel' => $sub, 'url' => '/crm/quotes/view.php?id=' . (int)$r['id']];
                }
            } catch (Throwable $e) { error_log('[search] contact quotes failed: ' . $e->getMessage()); }
            try {
                $s = $this->db->prepare("SELECT i.id, i.invoice_number, i.total_amount, i.balance_due, i.due_date FROM invoices i
                    WHERE i.contact_id = ? AND i.balance_due > 0 AND i.status NOT IN ('draft','cancelled') ORDER BY i.due_date, i.id LIMIT 10");
                $s->execute([$id]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $sub = 'owing $' . number_format((float)$r['balance_due'], 2) . ' of $' . number_format((float)$r['total_amount'], 2);
                    if (!empty($r['due_date']) && $r['due_date'] < $this->today()) {
                        $sub .= ' · ' . (int)((strtotime($this->today()) - strtotime((string)$r['due_date'])) / 86400) . ' days overdue';
                    }
                    $out['invoices'][] = ['kind' => 'invoice', 'id' => (int)$r['id'], 'label' => (string)$r['invoice_number'],
                                          'sublabel' => $sub, 'url' => '/crm/invoices/view.php?id=' . (int)$r['id']];
                }
            } catch (Throwable $e) { error_log('[search] contact invoices failed: ' . $e->getMessage()); }
            try {
                $s = $this->db->prepare('SELECT m.id, m.subject, m.snippet, m.direction, m.sent_at FROM sales_messages m
                    WHERE m.contact_id = ? ORDER BY m.sent_at DESC, m.id DESC LIMIT 5');
                $s->execute([$id]);
                foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $dir = $r['direction'] === 'inbound' ? '→ you' : 'you →';
                    $out['messages'][] = ['kind' => 'message', 'id' => (int)$r['id'], 'label' => (string)($r['subject'] ?: '(no subject)'),
                                          'sublabel' => $dir . ' ' . mb_substr((string)$r['snippet'], 0, 100) . ' · ' . date('M j', strtotime((string)$r['sent_at']))];
                }
            } catch (Throwable $e) { error_log('[search] contact messages failed: ' . $e->getMessage()); }
        }
        return $out;
    }

    private function today(): string { return $this->today ?: date('Y-m-d'); }

    public function recordPick(int $userId, string $q, string $kind, int $entityId): bool
    {
        $allowed=['contact','company','property','quote','invoice','job','contract','receipt','vendor','message','team'];
        if($userId<1||$entityId<1||!in_array($kind,$allowed,true))return false;
        try{$s=$this->db->prepare('INSERT INTO search_picks (user_id,prefix,kind,entity_id,picks,last_at) VALUES (?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE picks=picks+1,last_at=NOW()');return $s->execute([$userId,self::prefix($q),$kind,$entityId]);}catch(Throwable $e){
            try { // SQLite test equivalent.
                $s=$this->db->prepare("INSERT INTO search_picks (user_id,prefix,kind,entity_id,picks,last_at) VALUES (?,?,?,?,1,CURRENT_TIMESTAMP) ON CONFLICT(user_id,prefix,kind,entity_id) DO UPDATE SET picks=picks+1,last_at=CURRENT_TIMESTAMP");return $s->execute([$userId,self::prefix($q),$kind,$entityId]);
            } catch(Throwable $ignored){error_log('[search] pick failed: '.$e->getMessage());return false;}
        }
    }

    public static function flatForWeb(array $groups): array
    {
        $icons=['people'=>'user','properties'=>'map-pin','quotes'=>'file-text','invoices'=>'credit-card','jobs'=>'briefcase','receipts'=>'file','vendors'=>'truck','messages'=>'mail','team'=>'users','learned'=>'star'];$out=[];
        foreach($groups as$g)foreach($g['items']??[]as$i){if(empty($i['url']))continue;$out[]=['category'=>$g['title'],'icon'=>$icons[$g['key']]??'file-text','label'=>$i['label'],'sublabel'=>$i['sublabel']??'','url'=>$i['url']];}return$out;
    }
}
