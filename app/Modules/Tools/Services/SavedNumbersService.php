<?php
declare(strict_types=1);

final class SavedNumbersService
{
    public const SAVE_TEXT = 'Save my numbers so I can come back to them';
    public const NEWS_TEXT = 'Send me Ittai news by email. You can unsubscribe at any time.';
    public const BENCHMARK_TEXT = 'Add my numbers, without my name, to local benchmarks (shown only for groups of 10 or more businesses).';
    private PDO $db;
    public function __construct(PDO $db) { $this->db=$db; }
    private static function token(): array { $raw=random_bytes(32); return [rtrim(strtr(base64_encode($raw),'+/','-_'),'='),hash('sha256',$raw)]; }
    private static function hashToken(string $token): string { $raw=base64_decode(strtr($token,'-_','+/'),true); return $raw===false?'':hash('sha256',$raw); }
    private function driver(): string { return (string)$this->db->getAttribute(PDO::ATTR_DRIVER_NAME); }
    private function now(): string { return gmdate('Y-m-d H:i:s'); }
    public function createPending(string $email,string $tool,array $inputs,bool $news,bool $benchmark,string $source): array
    {
        [$confirm,$ch]=self::token(); [$delete,$dh]=self::token(); $email=strtolower(trim($email));
        $sql='INSERT INTO saved_numbers (email,email_hash,tool,inputs_json,version,status,confirm_token_hash,delete_token_hash,consent_save_text,consent_news_text,consent_benchmark_text,source_page,created_at,last_used_at) VALUES (?,?,?,?,1,\'pending\',?,?,?,?,?,?,?,?)';
        $st=$this->db->prepare($sql); $now=$this->now();
        $st->execute([$email,hash('sha256',$email),$tool,json_encode($inputs),$ch,$dh,self::SAVE_TEXT,$news?self::NEWS_TEXT:null,$benchmark?self::BENCHMARK_TEXT:null,$source,$now,$now]);
        return ['id'=>(int)$this->db->lastInsertId(),'confirm_token'=>$confirm,'delete_token'=>$delete];
    }
    private function row(string $column,string $token): ?array
    {
        $hash=self::hashToken($token); if($hash==='')return null;
        $st=$this->db->prepare("SELECT * FROM saved_numbers WHERE {$column} = ? LIMIT 1"); $st->execute([$hash]); $r=$st->fetch(PDO::FETCH_ASSOC);
        return $r && hash_equals((string)$r[$column],$hash)?$r:null;
    }
    public function findConfirm(string $token): ?array { return $this->row('confirm_token_hash',$token); }
    public function findDelete(string $token): ?array { return $this->row('delete_token_hash',$token); }
    public function confirm(string $token): ?array
    {
        $r=$this->findConfirm($token); if(!$r||$r['status']!=='pending')return null; $now=$this->now();
        $st=$this->db->prepare('UPDATE saved_numbers SET status=\'confirmed\',confirmed_at=?,last_used_at=?,consent_save_at=?,consent_news_at=?,consent_benchmark_at=? WHERE id=? AND status=\'pending\'');
        $st->execute([$now,$now,$now,$r['consent_news_text']!==null?$now:null,$r['consent_benchmark_text']!==null?$now:null,$r['id']]);
        [$delete,$deleteHash]=self::token();$this->db->prepare('UPDATE saved_numbers SET delete_token_hash=? WHERE id=?')->execute([$deleteHash,$r['id']]);$row=$this->findDeleteTokenRow((int)$r['id']);$row['delete_token']=$delete;return $row;
    }
    private function findDeleteTokenRow(int $id): ?array { $s=$this->db->prepare('SELECT * FROM saved_numbers WHERE id=?');$s->execute([$id]);$r=$s->fetch(PDO::FETCH_ASSOC);return $r?:null; }
    public function delete(string $token): bool { $r=$this->findDelete($token);if(!$r)return false;$s=$this->db->prepare('DELETE FROM saved_numbers WHERE id=?');$s->execute([$r['id']]);return $s->rowCount()===1; }
    public function keep(string $token): bool { $r=$this->findConfirm($token);if(!$r||$r['status']!=='confirmed')return false;$s=$this->db->prepare('UPDATE saved_numbers SET last_used_at=?,reminder_sent_at=NULL WHERE id=?');$s->execute([$this->now(),$r['id']]);return true; }
    public function issueReminderTokens(int $id): array { [$keep,$keepHash]=self::token();[$delete,$deleteHash]=self::token();$s=$this->db->prepare('UPDATE saved_numbers SET confirm_token_hash=?,delete_token_hash=? WHERE id=?');$s->execute([$keepHash,$deleteHash,$id]);return ['keep'=>$keep,'delete'=>$delete]; }
    public function purge(?DateTimeImmutable $at=null,?callable $remind=null): array
    {
        $at=$at??new DateTimeImmutable('now',new DateTimeZone('UTC')); $pending=$at->modify('-48 hours')->format('Y-m-d H:i:s'); $unused=$at->modify('-12 months')->format('Y-m-d H:i:s'); $warn=$at->modify('-11 months -15 days')->format('Y-m-d H:i:s');
        $s=$this->db->prepare("DELETE FROM saved_numbers WHERE status='pending' AND created_at < ?");$s->execute([$pending]);$p=$s->rowCount();
        $s=$this->db->prepare("DELETE FROM saved_numbers WHERE status='confirmed' AND COALESCE(last_used_at,confirmed_at) < ?");$s->execute([$unused]);$d=$s->rowCount();
        $s=$this->db->prepare("SELECT * FROM saved_numbers WHERE status='confirmed' AND COALESCE(last_used_at,confirmed_at) < ? AND COALESCE(last_used_at,confirmed_at) >= ? AND reminder_sent_at IS NULL");$s->execute([$warn,$unused]);$n=0;
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){$tokens=$this->issueReminderTokens((int)$row['id']);if($remind===null||$remind($row,$tokens['keep'],$tokens['delete'])!==false){$u=$this->db->prepare('UPDATE saved_numbers SET reminder_sent_at=? WHERE id=?');$u->execute([$at->format('Y-m-d H:i:s'),$row['id']]);$n++;}}
        return ['pending_deleted'=>$p,'confirmed_deleted'=>$d,'reminders_sent'=>$n];
    }
}
