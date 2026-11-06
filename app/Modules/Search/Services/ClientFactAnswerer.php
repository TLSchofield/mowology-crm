<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/ChiefOfStaff/Services/CharlieAskService.php';

class ClientFactAnswerer
{
    /** Every word intent() reads, so person() never takes one for a name. */
    public const INTENT_WORDS = [
        'quote', 'sent', 'send', 'went', 'gone', 'out', 'open', 'opened', 'seen', 'answer', 'answered', 'respond', 'responded', 'declined', 'reply', 'replied', 'accepted',
        'approved', 'status', 'paid', 'pay', 'owe', 'owes', 'owing', 'balance', 'outstanding', 'overdue', 'next', 'when',
        'visit', 'coming', 'scheduled', 'booked', 'say', 'said', 'says', 'wrote', 'email', 'emailed', 'message', 'text',
        'texted', 'heard', 'last', 'did', 'has', 'have', 'been', 'yet', 'what', 'from',
    ];

    private PDO $db;
    private ?string $today;

    public function __construct(PDO $db, ?string $today = null)
    {
        $this->db = $db;
        $this->today = $today;
    }

    private function today(): string { return $this->today ?: date('Y-m-d'); }

    private static function has(string $q, array $words): bool
    {
        foreach ($words as $word) if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $q)) return true;
        return false;
    }

    private function intent(string $q): ?string
    {
        if (self::has($q, ['quote']) && self::has($q, ['sent','send','went','gone','out','open','opened','seen','answer','answered','reply','replied','respond','responded','accepted','approved','declined','status'])) return 'quote_sent';
        if (self::has($q, ['paid','pay','owe','owes','owing','balance','outstanding','overdue'])) return 'paid';
        if (self::has($q, ['next','when','visit','coming','scheduled','booked'])) return 'next_visit';
        if (self::has($q, ['say','said','says','wrote','email','emailed','message','text','texted','reply','replied','heard'])) return 'last_message';
        return null;
    }

    /** Everyone the question could be about (contacts, then companies); answer() keeps the one who has the record. */
    private function people(string $q): array
    {
        // Intent words are not names: "what did linda say" must look up Linda only (terms() keeps "say").
        $terms = array_values(array_filter(CharlieAskService::terms($q), static function (string $t): bool {
            return !in_array(mb_strtolower($t), self::INTENT_WORDS, true);
        }));
        // Filler a phone keyboard leaves lowercase ("anything", "ever") names nobody: keep only words that name someone,
        // then every kept word must name the same person ("linda jones" beats Linda Smith).
        $terms = array_values(array_filter($terms, function (string $t): bool { return $this->namesSomeone($t); }));
        if (!$terms) return [];
        // Whole names first, so "linda" is Linda Chou or Linda Tam but not Belinda; part-words only when nothing is whole.
        $contacts = $this->contactsNamed($terms, true) ?: $this->contactsNamed($terms, false);
        $companies = [];
        try {
            $parts=[];$args=[];
            foreach ($terms as $term) {
                $forms=[];
                foreach(CharlieAskService::nameForms(mb_strtolower($term))as$form){$forms[]='LOWER(company_name) LIKE ?';$args[]='%'.$form.'%';}
                $parts[]='('.implode(' OR ',$forms).')';
            }
            $s=$this->db->prepare("SELECT id,company_name,billing_email FROM companies WHERE account_status='active' AND ".implode(' AND ',$parts).' LIMIT 5');$s->execute($args);
            foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r)$companies[]=['kind'=>'company','id'=>(int)$r['id'],'name'=>$r['company_name'],'phone'=>null,'email'=>$r['billing_email']??null];
        } catch(Throwable $e){/* contacts can still answer */}
        return array_merge($contacts, $companies);
    }

    /** @return array[] candidates; $whole = every word equals a first or last name (else a part of one). */
    private function contactsNamed(array $terms, bool $whole): array
    {
        $out = [];
        try {
            $parts=[];$args=[];
            foreach ($terms as $term) {
                $forms=[];
                foreach(CharlieAskService::nameForms(mb_strtolower($term))as$form){
                    $forms[]=$whole?'(LOWER(first_name) = ? OR LOWER(last_name) = ?)':'(LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ?)';
                    $v=$whole?$form:'%'.$form.'%';$args[]=$v;$args[]=$v;
                }
                $parts[]='('.implode(' OR ',$forms).')';
            }
            $s=$this->db->prepare('SELECT id,first_name,last_name,email,phone FROM contacts WHERE is_active=1 AND '.implode(' AND ',$parts).' LIMIT 10');$s->execute($args);
            foreach($s->fetchAll(PDO::FETCH_ASSOC)as$r)$out[]=['kind'=>'contact','id'=>(int)$r['id'],'name'=>trim($r['first_name'].' '.$r['last_name']),'phone'=>$r['phone']??null,'email'=>$r['email']??null];
        } catch(Throwable $e){error_log('[search] contactsNamed failed: '.$e->getMessage());}
        return $out;
    }

    private function namesSomeone(string $term): bool
    {
        try {
            foreach (CharlieAskService::nameForms(mb_strtolower($term)) as $form) {
                $s = $this->db->prepare('SELECT 1 FROM contacts WHERE is_active=1 AND (LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ?) LIMIT 1');
                $s->execute(['%' . $form . '%', '%' . $form . '%']);
                if ($s->fetchColumn()) return true;
                $s = $this->db->prepare("SELECT 1 FROM companies WHERE account_status='active' AND LOWER(company_name) LIKE ? LIMIT 1");
                $s->execute(['%' . $form . '%']);
                if ($s->fetchColumn()) return true;
            }
        } catch (Throwable $e) { error_log('[search] namesSomeone failed: ' . $e->getMessage()); }
        return false;
    }

    private function actions(array $person, ?array $open = null, bool $allowEmail = true): array
    {
        $out=[];if($open)$out[]=['kind'=>'open','label'=>$open['label'],'url'=>$open['url']];
        if(!empty($person['phone'])){$out[]=['kind'=>'call','label'=>'Call','phone'=>$person['phone']];$out[]=['kind'=>'text','label'=>'Text','phone'=>$person['phone']];}
        if($allowEmail&&!empty($person['email']))$out[]=['kind'=>'email','label'=>'Email','email'=>$person['email']];
        return$out;
    }

    public function answer(string $q, callable $can): ?array
    {
        $intent=$this->intent($q);if($intent===null)return null;
        // Six Lindas, one with a quote: "has lindas quote been sent" is about her. Two with an answer → not sure → null.
        $found=[];
        foreach($this->people($q) as $person){
            try {
                if($intent==='quote_sent')$r=$this->quote($person,$can);
                elseif($intent==='paid')$r=$this->paid($person,$can);
                elseif($intent==='next_visit')$r=$this->visit($person,$can);
                else $r=$this->message($person,$can);
            }catch(Throwable $e){error_log('[search] answer failed: '.$e->getMessage());$r=null;}
            if($r!==null)$found[]=$r;
            if(count($found)>1)return null;
        }
        return $found[0]??null;
    }

    private function owner(array $p, string $alias): array
    {
        return $p['kind']==='contact'?["$alias.contact_id = ?",[$p['id']]]:["$alias.company_id = ?",[$p['id']]];
    }

    private function result(string $answer,string $intent,array $p,array $actions): array
    {return['answer'=>$answer,'intent'=>$intent,'about'=>['kind'=>$p['kind'],'id'=>$p['id'],'name'=>$p['name']],'actions'=>$actions];}

    private function columnExists(string $table,string $column): bool
    {
        try {
            if($this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){
                foreach($this->db->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC)as$r)if(($r['name']??'')===$column)return true;
                return false;
            }
            $s=$this->db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");$s->execute([$table,$column]);return(int)$s->fetchColumn()>0;
        }catch(Throwable $e){return false;}
    }

    private function quote(array $p,callable $can): ?array
    {
        if(!$can('billing.view'))return null;[$where,$args]=$this->owner($p,'q');
        $viewCount=$this->columnExists('quotes','view_count')?'q.view_count':'NULL';
        $s=$this->db->prepare("SELECT q.id,q.quote_number,q.title,q.status,q.sent_at,q.accepted_at,q.email_opened_at,q.viewed_at,$viewCount AS view_count FROM quotes q WHERE $where ORDER BY q.created_at DESC,q.id DESC LIMIT 1");$s->execute($args);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)return null;
        $head=$p['name']."'s quote ".$r['quote_number'].(!empty($r['title'])?' ('.$r['title'].')':'');
        if(empty($r['sent_at']))$answer=$head.': not sent yet (draft)';
        else{$opened=$r['view_count']!==null?(int)$r['view_count']:((!empty($r['email_opened_at'])||!empty($r['viewed_at']))?1:0);$st=strtolower((string)$r['status']);$tail=!empty($r['accepted_at'])?'accepted '.date('M j',strtotime($r['accepted_at'])):($st==='accepted'?'accepted':($st==='declined'?'declined':($st==='expired'?'expired':'no reply yet')));$answer=$head.': sent '.date('M j',strtotime($r['sent_at'])).' · opened '.$opened.'× · '.$tail;}
        return$this->result($answer,'quote_sent',$p,$this->actions($p,['label'=>'Open quote','url'=>'/crm/quotes/view.php?id='.(int)$r['id']]));
    }

    private function paid(array $p,callable $can): ?array
    {
        if(!$can('billing.view'))return null;[$where,$args]=$this->owner($p,'i');
        $open="$where AND i.balance_due>0 AND i.status NOT IN ('draft','cancelled')";
        $s=$this->db->prepare("SELECT COUNT(*) n, COALESCE(SUM(i.balance_due),0) owed FROM invoices i WHERE $open");$s->execute($args);$tot=$s->fetch(PDO::FETCH_ASSOC);
        if((int)($tot['n']??0)>0){
            // Oldest by due date; an invoice with no due date comes last and is never given one.
            $s=$this->db->prepare("SELECT i.id,i.invoice_number,i.due_date FROM invoices i WHERE $open ORDER BY (i.due_date IS NULL),i.due_date,i.id LIMIT 1");$s->execute($args);$old=$s->fetch(PDO::FETCH_ASSOC);
            $n=(int)$tot['n'];$answer=$p['name'].' owes $'.number_format((float)$tot['owed'],2).' on '.$n.' invoice'.($n===1?'':'s').', oldest '.$old['invoice_number'];
            if(!empty($old['due_date'])){$answer.=' due '.date('M j',strtotime((string)$old['due_date']));if($old['due_date']<$this->today()){$days=(int)((strtotime($this->today())-strtotime((string)$old['due_date']))/86400);$answer.=' ('.$days.' days overdue)';}}
            return$this->result($answer,'paid',$p,$this->actions($p,['label'=>'Open invoice','url'=>'/crm/invoices/view.php?id='.(int)$old['id']]));
        }
        $paidColumn=$this->columnExists('invoices','paid_at')?'paid_at':($this->columnExists('invoices','payment_date')?'payment_date':null);
        $amounts=[];foreach(['amount_paid','total_amount','total'] as $col)if($this->columnExists('invoices',$col))$amounts[]="NULLIF(i.$col,0)";
        if($paidColumn===null||!$amounts)return null;
        $amountExpr='COALESCE('.implode(',',$amounts).',0)';
        $s=$this->db->prepare("SELECT i.id,i.invoice_number,$amountExpr AS paid_amount,i.$paidColumn AS paid_date FROM invoices i WHERE $where AND i.$paidColumn IS NOT NULL ORDER BY i.$paidColumn DESC,i.id DESC LIMIT 1");$s->execute($args);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)return null;$answer=$p['name'].' is paid up — last paid '.$r['invoice_number'].' ($'.number_format((float)$r['paid_amount'],2).') on '.date('M j',strtotime($r['paid_date']));return$this->result($answer,'paid',$p,$this->actions($p,['label'=>'Open invoice','url'=>'/crm/invoices/view.php?id='.(int)$r['id']]));
    }

    private function visit(array $p,callable $can): ?array
    {
        if(!$can('clients.view'))return null;
        if($p['kind']==='contact'){$where="(pr.site_contact_id=? OR pr.client_id=? OR EXISTS(SELECT 1 FROM property_contacts pc WHERE pc.property_id=pr.id AND pc.contact_id=?))";$args=[$p['id'],$p['id'],$p['id']];}else{$where='pr.company_id=?';$args=[$p['id']];}
        $s=$this->db->prepare("SELECT pr.id property_id,pr.address,jv.scheduled_date,jp.title FROM properties pr LEFT JOIN job_plans jp ON jp.property_id=pr.id LEFT JOIN job_visits jv ON jv.plan_id=jp.id AND jv.scheduled_date>=? AND jv.status NOT IN ('cancelled','skipped','completed') WHERE $where ORDER BY (jv.scheduled_date IS NULL),jv.scheduled_date LIMIT 1");$s->execute(array_merge([$this->today()],$args));$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)return null;
        $answer=!empty($r['scheduled_date'])?'Next visit at '.$r['address'].': '.date('D M j',strtotime($r['scheduled_date'])).(!empty($r['title'])?' ('.$r['title'].')':''):'No visit booked at '.$r['address'];
        return$this->result($answer,'next_visit',$p,$this->actions($p,['label'=>'Open property','url'=>'/crm/properties/view.php?id='.(int)$r['property_id']],false));
    }

    private function message(array $p,callable $can): ?array
    {
        if(!$can('clients.view')||!$can('billing.view')||$p['kind']!=='contact')return null;
        $s=$this->db->prepare("SELECT direction,sent_at,snippet FROM sales_messages WHERE contact_id=? ORDER BY sent_at DESC,id DESC LIMIT 1");$s->execute([$p['id']]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)return null;$snippet=mb_substr((string)$r['snippet'],0,140);$date=date('M j',strtotime($r['sent_at']));$answer=$r['direction']==='inbound'?$p['name'].' wrote '.$date.': “'.$snippet.'”':'You last wrote '.$p['name'].' '.$date.': “'.$snippet.'”';return$this->result($answer,'last_message',$p,$this->actions($p));
    }
}
