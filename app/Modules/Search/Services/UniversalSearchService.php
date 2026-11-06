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
        $text = $ands ? implode(' AND ', $ands) : '1=0';
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

    public function search(string $q, callable $can, int $userId, int $perGroup = 5): array
    {
        $q = self::normalize($q);
        $tokens = self::tokens($q);
        if (!$tokens) return ['groups' => []];
        $prefix = self::prefix($q);
        $groups = [];

        if ($can('clients.view')) try {
            $items = [];
            $p = [];
            $where = $this->where(['c.first_name','c.last_name','c.email','c.phone'], $tokens, $p, 'c.phone', self::digits($q));
            $sql = "SELECT c.id,c.first_name,c.last_name,c.email,c.phone FROM contacts c WHERE c.is_active=1 AND $where ORDER BY c.first_name,c.last_name LIMIT " . self::FETCH_MAX;
            $s = $this->db->prepare($sql); $s->execute($p);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                $items[] = ['kind'=>'contact','id'=>(int)$r['id'],'label'=>$name,'sublabel'=>$r['email'] ?: ($r['phone'] ?? ''),'phone'=>$r['phone'] ?? null,'email'=>$r['email'] ?? null,'url'=>'/crm/clients_appstack.php?action=view_contact&id='.(int)$r['id']];
            }
            try {
                $p=[]; $where=$this->where(['co.company_name','co.billing_email','co.billing_city'],$tokens,$p);
                $s=$this->db->prepare("SELECT co.id,co.company_name,co.company_type,co.billing_city,co.billing_email FROM companies co WHERE co.account_status='active' AND $where ORDER BY co.company_name LIMIT " . self::FETCH_MAX); $s->execute($p);
            } catch (Throwable $companyError) {
                error_log('[search] companies failed: ' . $companyError->getMessage());
                $p=[]; $where=$this->where(['co.company_name'],$tokens,$p);
                $s=$this->db->prepare("SELECT co.id,co.company_name,co.company_type,NULL AS billing_city,NULL AS billing_email FROM companies co WHERE co.account_status='active' AND $where ORDER BY co.company_name LIMIT " . self::FETCH_MAX); $s->execute($p);
            }
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $sub=ucfirst(str_replace('_',' ',(string)($r['company_type'] ?? ''))); if (!empty($r['billing_city'])) $sub.=($sub?' · ':'').$r['billing_city'];
                $items[]=['kind'=>'company','id'=>(int)$r['id'],'label'=>$r['company_name'],'sublabel'=>$sub,'phone'=>null,'email'=>$r['billing_email'] ?? null,'url'=>'/crm/companies/view.php?id='.(int)$r['id']];
            }
            if ($g=$this->group('people','People',$items,$perGroup,$userId,$prefix)) $groups[]=$g;
        } catch (Throwable $e) { error_log('[search] people failed: '.$e->getMessage()); }

        if ($can('clients.view')) try {
            $p=[]; $where=$this->where(['p.address','p.city','p.postal_code','c.first_name','c.last_name'],$tokens,$p);
            $s=$this->db->prepare("SELECT p.id,p.address,p.city,c.first_name,c.last_name FROM properties p LEFT JOIN contacts c ON c.id=p.site_contact_id WHERE p.status='active' AND $where ORDER BY p.address LIMIT " . self::FETCH_MAX); $s->execute($p); $items=[];
            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$name=trim(($r['first_name']??'').' '.($r['last_name']??''));$sub=implode(' · ',array_filter([$r['city']??'',$name]));$items[]=['kind'=>'property','id'=>(int)$r['id'],'property_id'=>(int)$r['id'],'label'=>$r['address'],'sublabel'=>$sub,'url'=>'/crm/properties/view.php?id='.(int)$r['id']];}
            if($g=$this->group('properties','Properties',$items,$perGroup,$userId,$prefix))$groups[]=$g;
        } catch(Throwable $e){error_log('[search] properties failed: '.$e->getMessage());}

        if ($can('billing.view')) try {
            $p=[];$where=$this->where(['q.quote_number','q.title','c.first_name','c.last_name','pr.address',"'quote'"],$tokens,$p);
            $s=$this->db->prepare("SELECT q.id,q.quote_number,q.title,q.status,q.total_amount,q.contact_id,q.sent_at,q.email_opened_at,q.viewed_at,c.first_name,c.last_name FROM quotes q LEFT JOIN contacts c ON c.id=q.contact_id LEFT JOIN properties pr ON pr.id=q.property_id WHERE $where ORDER BY q.created_at DESC LIMIT " . self::FETCH_MAX);$s->execute($p);$items=[];
            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$sub=($r['status']??'').' · $'.number_format((float)$r['total_amount'],2);if(!empty($r['sent_at']))$sub.=' · sent '.date('M j',strtotime($r['sent_at']));$items[]=['kind'=>'quote','id'=>(int)$r['id'],'label'=>$r['quote_number'].(!empty($r['title'])?' — '.$r['title']:''),'sublabel'=>$sub,'status'=>$r['status'],'total'=>(float)$r['total_amount'],'contact_id'=>(int)$r['contact_id'],'sent_at'=>$r['sent_at'],'opened'=>!empty($r['email_opened_at'])||!empty($r['viewed_at']),'url'=>'/crm/quotes/view.php?id='.(int)$r['id']];}
            if($g=$this->group('quotes','Quotes',$items,$perGroup,$userId,$prefix))$groups[]=$g;
        }catch(Throwable $e){error_log('[search] quotes failed: '.$e->getMessage());}

        if ($can('billing.view')) try {
            $p=[];$where=$this->where(['i.invoice_number','i.bill_to_name','c.first_name','c.last_name','i.service_address',"'invoice'"],$tokens,$p);
            $s=$this->db->prepare("SELECT i.id,i.invoice_number,i.total,i.balance_due,i.status,i.contact_id,i.due_date,i.paid_at FROM invoices i LEFT JOIN contacts c ON c.id=i.contact_id WHERE $where ORDER BY i.created_at DESC LIMIT " . self::FETCH_MAX);$s->execute($p);$items=[];
            foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r){$bal=(float)$r['balance_due'];$sub='$'.number_format((float)$r['total'],2).' · '.($bal>0?'owing $'.number_format($bal,2):'paid'.(!empty($r['paid_at'])?' '.date('M j',strtotime($r['paid_at'])):''));if($bal>0&&!empty($r['due_date'])&&$r['due_date']<$this->today())$sub.=' · '.max(0,(int)((strtotime($this->today())-strtotime($r['due_date']))/86400)).' days overdue';$items[]=['kind'=>'invoice','id'=>(int)$r['id'],'label'=>$r['invoice_number'],'sublabel'=>$sub,'balance'=>$bal,'status'=>$r['status'],'contact_id'=>(int)$r['contact_id'],'url'=>'/crm/invoices/view.php?id='.(int)$r['id']];}
            if($g=$this->group('invoices','Invoices',$items,$perGroup,$userId,$prefix))$groups[]=$g;
        }catch(Throwable $e){error_log('[search] invoices failed: '.$e->getMessage());}

        if ($can('jobs.view')) try {
            $items=[];$p=[];$where=$this->where(['j.plan_number','j.title','j.service_type'],$tokens,$p);$s=$this->db->prepare("SELECT j.id,j.plan_number,j.title,j.status,j.service_type FROM job_plans j WHERE $where ORDER BY j.created_at DESC LIMIT " . self::FETCH_MAX);$s->execute($p);
            foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r)$items[]=['kind'=>'job','id'=>(int)$r['id'],'label'=>$r['plan_number'].(!empty($r['title'])?' — '.$r['title']:''),'sublabel'=>implode(' · ',array_filter([$r['status']??'',$r['service_type']??''])),'url'=>'/crm/jobs/view.php?id='.(int)$r['id']];
            $p=[];$where=$this->where(['co.contract_number','co.title','pr.address','c.first_name','c.last_name'],$tokens,$p);$s=$this->db->prepare("SELECT co.id,co.contract_number,co.title,co.status,pr.address,c.first_name,c.last_name FROM contracts co LEFT JOIN properties pr ON pr.id=co.property_id LEFT JOIN contacts c ON c.id=co.contact_id WHERE $where ORDER BY (co.status='active') DESC,co.id DESC LIMIT " . self::FETCH_MAX);$s->execute($p);
            foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r)$items[]=['kind'=>'contract','id'=>(int)$r['id'],'label'=>$r['contract_number'].(!empty($r['title'])?' — '.$r['title']:''),'sublabel'=>implode(' · ',array_filter([$r['status']??'',$r['address']??'',trim(($r['first_name']??'').' '.($r['last_name']??''))])),'url'=>'/crm/contracts/view.php?id='.(int)$r['id']];
            if($g=$this->group('jobs','Jobs',$items,$perGroup,$userId,$prefix))$groups[]=$g;
        }catch(Throwable $e){error_log('[search] jobs failed: '.$e->getMessage());}

        if ($can('expenses.view') && $can('billing.view')) try {
            $p=[];$where=$this->where(['e.vendor_name_raw','v.name','e.description'],$tokens,$p);$s=$this->db->prepare("SELECT e.id,e.vendor_name_raw,e.description,e.total,e.expense_date,v.name vendor_name FROM expenses e LEFT JOIN vendors v ON v.id=e.vendor_id WHERE e.status NOT IN ('rejected','deleted') AND $where ORDER BY e.expense_date DESC LIMIT " . self::FETCH_MAX);$s->execute($p);$items=[];
            foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r)$items[]=['kind'=>'receipt','id'=>(int)$r['id'],'label'=>$r['vendor_name']?:($r['vendor_name_raw']?:$r['description']),'sublabel'=>'$'.number_format((float)$r['total'],2).' · '.date('M j, Y',strtotime($r['expense_date'])),'url'=>'/crm/expenses_appstack.php?id='.(int)$r['id']];
            if($g=$this->group('receipts','Receipts',$items,$perGroup,$userId,$prefix))$groups[]=$g;
        }catch(Throwable $e){error_log('[search] receipts failed: '.$e->getMessage());}

        if ($can('billing.view')) try {
            $p=[];$where=$this->where(['v.name'],$tokens,$p);$s=$this->db->prepare("SELECT v.id,v.name FROM vendors v WHERE $where ORDER BY v.name LIMIT " . self::FETCH_MAX);$s->execute($p);$items=[];foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r)$items[]=['kind'=>'vendor','id'=>(int)$r['id'],'label'=>$r['name'],'sublabel'=>'','url'=>'/crm/expenses_appstack.php?vendor='.(int)$r['id']];if($g=$this->group('vendors','Suppliers',$items,$perGroup,$userId,$prefix))$groups[]=$g;
        }catch(Throwable $e){error_log('[search] vendors failed: '.$e->getMessage());}

        if ($can('clients.view') && $can('billing.view')) try {
            $p=[];$where=$this->where(['m.subject','m.snippet','m.from_addr','c.first_name','c.last_name'],$tokens,$p);$s=$this->db->prepare("SELECT m.id,m.subject,m.snippet,m.from_addr,m.direction,m.sent_at,m.contact_id,c.first_name,c.last_name FROM sales_messages m LEFT JOIN contacts c ON c.id=m.contact_id WHERE $where ORDER BY m.sent_at DESC LIMIT " . self::FETCH_MAX);$s->execute($p);$items=[];
            foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r){$name=trim(($r['first_name']??'').' '.($r['last_name']??''))?:$r['from_addr'];$dir=$r['direction']==='inbound'?'→ you':'you →';$sn=mb_substr((string)$r['snippet'],0,100);$items[]=['kind'=>'message','id'=>(int)$r['id'],'label'=>$name.': '.($r['subject']??''),'sublabel'=>$dir.' '.$sn.' · '.date('M j',strtotime($r['sent_at'])),'contact_id'=>(int)$r['contact_id']];}if($g=$this->group('messages','Emails & texts',$items,$perGroup,$userId,$prefix))$groups[]=$g;
        }catch(Throwable $e){error_log('[search] messages failed: '.$e->getMessage());}

        if ($can('team.view') && $can('billing.view')) try {
            $p=[];$where=$this->where(['u.full_name','u.email'],$tokens,$p);$s=$this->db->prepare("SELECT u.id,u.full_name,u.email FROM users u WHERE u.is_active=1 AND $where ORDER BY u.full_name LIMIT " . self::FETCH_MAX);$s->execute($p);$items=[];foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r)$items[]=['kind'=>'team','id'=>(int)$r['id'],'label'=>$r['full_name'],'sublabel'=>$r['email']??'','url'=>'/crm/team/index.php'];if($g=$this->group('team','Team',$items,$perGroup,$userId,$prefix))$groups[]=$g;
        }catch(Throwable $e){error_log('[search] team failed: '.$e->getMessage());}
        return ['groups'=>$groups];
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
                $s = $this->db->prepare("SELECT q.id, q.quote_number, q.title, q.status, q.total_amount, q.sent_at FROM quotes q
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
        $icons=['people'=>'user','properties'=>'map-pin','quotes'=>'file-text','invoices'=>'credit-card','jobs'=>'briefcase','receipts'=>'file','vendors'=>'truck','messages'=>'mail','team'=>'users'];$out=[];
        foreach($groups as$g)foreach($g['items']??[]as$i){if(empty($i['url']))continue;$out[]=['category'=>$g['title'],'icon'=>$icons[$g['key']]??'file-text','label'=>$i['label'],'sublabel'=>$i['sublabel']??'','url'=>$i['url']];}return$out;
    }
}
