<?php
/**
 * Tenant-local business identity for Penny onboarding.
 * Other AI heads may read it only through current(); never copy it to head memory or shared Knowledge.
 */
declare(strict_types=1);

require_once __DIR__ . '/Registry/BusinessRegistryProvider.php';

final class BusinessIdentityService
{
    public const ATTRIBUTION = 'Contains information licensed under the Open Government Licence – British Columbia.';
    private PDO $db;
    private BusinessRegistryProvider $provider;
    private int $userId;
    public function __construct(PDO $db, BusinessRegistryProvider $provider, int $userId = 0)
    { $this->db=$db; $this->provider=$provider; $this->userId=$userId; }

    /** Canonical tenant-local read for Penny and every other head. */
    public function current(bool $canEdit=false): array
    {
        try { $row=$this->row(); $gst=$this->gstRegistration(); }
        catch (Throwable $e) { return $this->databaseFailure($e); }
        $stage=self::stage($row,$gst);
        return ['ok'=>true,'identity'=>$row?$this->publicIdentity($row):null,
            'knowledge'=>['stage'=>$stage,'next'=>self::nextSteps($stage)],'can_edit'=>$canEdit,'attribution'=>self::ATTRIBUTION];
    }
    public function search(string $name): array
    { $r=$this->provider->search($name); return ['ok'=>true,'status'=>$r->status,'matches'=>$r->matches,'source'=>'orgbook_bc','attribution'=>self::ATTRIBUTION]; }
    /** Read tenant-owned contact suggestions only; unavailable settings are an empty list. */
    public function contacts(): array
    {
        try {
            $s=$this->db->prepare('SELECT company_website, company_phone, company_address FROM business_settings WHERE id = ?');
            $s->execute([1]);
            $row=$s->fetch(PDO::FETCH_ASSOC);
            return self::mapContacts($row?:[]);
        } catch (Throwable $e) {
            return [];
        }
    }
    /** Pure business_settings row-to-contact mapping. */
    public static function mapContacts(array $row): array
    {
        $contacts=[];
        foreach(['website'=>'company_website','phone'=>'company_phone'] as $field=>$column){
            $value=trim((string)($row[$column]??''));
            if($value!=='')$contacts[]=['field'=>$field,'value'=>$value,'source'=>'your_settings','status'=>'suggested'];
        }
        $parts=preg_split('/\R/u',(string)($row['company_address']??''))?:[];
        $parts=array_values(array_filter(array_map('trim',$parts),static function(string $part): bool{return $part!=='';}));
        if($parts)$contacts[]=['field'=>'address','value'=>implode(', ',$parts),'source'=>'your_settings','status'=>'suggested'];
        return $contacts;
    }
    public function start(string $name,bool $canEdit=true): array
    { try{$this->upsert(['entered_name'=>$name,'entered_name_provenance'=>'owner_provided']);}catch(Throwable $e){return $this->databaseFailure($e);} return $this->current($canEdit); }
    public function confirm(string $recordId,bool $canEdit=true): array
    {
        $record=$this->provider->fetch($recordId);
        if($record===null)return ['ok'=>false,'error'=>'Registry record is unavailable or no longer exists'];
        $r=$record->fields;
        if(($r['record_id']??'')!==$recordId)return ['ok'=>false,'error'=>'Registry returned a different record'];
        $values=['source'=>'orgbook_bc','source_record_id'=>$recordId,'registration_type'=>'bc_registration',
            'registry_verified_at'=>date('Y-m-d H:i:s'),'owner_confirmed_at'=>date('Y-m-d H:i:s'),
            'registry_business_number'=>$r['registry_business_number']??null];
        $map=['legal_name'=>'legal_name','registration_number'=>'registration_number','legal_structure'=>'legal_structure',
            'jurisdiction'=>'jurisdiction','status'=>'registration_status','registration_type'=>'registration_type'];
        foreach($map as $source=>$target)if(array_key_exists($source,$r)){$values[$target]=$r[$source];$values[$target.'_provenance']='registry_verified';}
        if(!empty($r['operating_names'][0])){$values['operating_name']=$r['operating_names'][0];$values['operating_name_provenance']='registry_verified';}
        try{$existing=$this->row();}catch(Throwable $e){return $this->databaseFailure($e);}
        if(!$existing||empty($existing['entered_name'])){$entered=$values['operating_name']??$values['legal_name']??null;if(is_string($entered)&&$entered!==''){$values['entered_name']=$entered;$values['entered_name_provenance']='registry_verified';}}
        try{$this->upsert($values);}catch(Throwable $e){return $this->databaseFailure($e);} return $this->current($canEdit);
    }
    public function manual(array $fields,bool $canEdit=true): array
    {
        $clean=[];
        foreach(['legal_name','operating_name','legal_structure','jurisdiction','registration_number'] as $field){
            if(array_key_exists($field,$fields)&&!is_string($fields[$field]))throw new InvalidArgumentException('Please check what you typed and try again.');
            $value=trim($fields[$field]??'');$length=function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value);
            if(($field==='legal_name'&&$length<2)||$length>255)throw new InvalidArgumentException('Please check what you typed and try again.');
            $clean[$field]=$value;
        }
        $values=['source'=>'owner','source_record_id'=>null,'registry_business_number'=>null,'registry_verified_at'=>null,
            'owner_confirmed_at'=>null,'registration_status'=>null,'registration_status_provenance'=>null];
        foreach(['legal_name','operating_name','legal_structure','jurisdiction','registration_number'] as $field){
            $values[$field]=$clean[$field]!==''?$clean[$field]:null;
            $values[$field.'_provenance']=$values[$field]!==null?'owner_provided':null;
        }
        $values['registration_type']=$values['registration_number']!==null?'bc_registration':null;
        $values['registration_type_provenance']=$values['registration_type']?'owner_provided':null;
        try { if ($this->row() === null && !empty($values['legal_name'])) { $values['entered_name']=$values['legal_name']; $values['entered_name_provenance']='owner_provided'; } }
        catch (Throwable $e) { return $this->databaseFailure($e); }
        try{$this->upsert($values);}catch(Throwable $e){return $this->databaseFailure($e);} return $this->current($canEdit);
    }
    /** Pure stage rules: no I/O and never returns mastered in Step 1. */
    public static function stage(?array $row,?string $gstRegistration): ?string
    {
        if(!$row||empty($row['entered_name']))return null;
        $manual=($row['source']??null)==='owner'&&!empty($row['legal_name']);
        $confirmed=($row['source']??null)==='orgbook_bc'&&!empty($row['owner_confirmed_at']);
        if(!$manual&&!$confirmed)return 'recognized';
        if($confirmed&&($row['registration_status']??null)==='active'&&self::firstNine((string)($row['registry_business_number']??''))!==null
            &&self::firstNine((string)$gstRegistration)===self::firstNine((string)$row['registry_business_number']))return 'understood';
        return 'identified';
    }
    private static function firstNine(string $value): ?string
    {$digits=preg_replace('/\D+/','',$value)??'';return strlen($digits)>=9?substr($digits,0,9):null;}
    private static function nextSteps(?string $stage): array
    {
        if($stage===null)return ['Enter the name your business uses.'];
        if($stage==='recognized')return ['Search for the business in OrgBook BC or enter its details manually.'];
        if($stage==='identified')return ['Confirm that the GST registration begins with the same nine digits as the registry business number.'];
        return ['Business identity and GST registration have been independently cross-checked.'];
    }
    private function row(): ?array
    {$s=$this->db->prepare('SELECT * FROM business_identity WHERE id = ?');$s->execute([1]);$row=$s->fetch(PDO::FETCH_ASSOC);return $row?:null;}
    private function gstRegistration(): ?string
    {$s=$this->db->prepare('SELECT gst_registration FROM business_settings WHERE id = ?');$s->execute([1]);$v=$s->fetchColumn();return $v===false?null:(string)$v;}
    private function upsert(array $values): void
    {
        $this->db->beginTransaction();
        try{
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $s=$this->db->prepare('SELECT id FROM business_identity WHERE id = ?'.$lock);$s->execute([1]);$exists=(bool)$s->fetchColumn();$values['updated_by']=$this->userId;
            if(!$exists){$columns=array_merge(['id'],array_keys($values));$params=array_merge([1],array_values($values));
                $sql='INSERT INTO business_identity (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')';}
            else{$sets=[];foreach(array_keys($values)as$key)$sets[]='`'.$key.'` = ?';$sql='UPDATE business_identity SET '.implode(',',$sets).', updated_at = CURRENT_TIMESTAMP WHERE id = 1';$params=array_values($values);}
            $this->db->prepare($sql)->execute($params);$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function publicIdentity(array $row): array
    {
        $fields=['entered_name','legal_name','operating_name','registration_number','registration_type','legal_structure','jurisdiction','registration_status','source','source_record_id','registry_verified_at','owner_confirmed_at'];
        $identity=[];foreach($fields as$field)$identity[$field]=$row[$field]??null;$identity['provenance']=[];
        foreach(['entered_name','legal_name','operating_name','registration_number','registration_type','legal_structure','jurisdiction','registration_status']as$field)
            if(!empty($row[$field.'_provenance']))$identity['provenance'][$field]=$row[$field.'_provenance'];
        return $identity;
    }
    private function databaseFailure(Throwable $e): array
    {
        $message=strtolower($e->getMessage());
        if(strpos($message,'business_identity')!==false&&(strpos($message,"doesn't exist")!==false||strpos($message,'no such table')!==false||strpos($message,'base table')!==false))
            return ['ok'=>false,'error'=>'migration 1373 has not been run'];
        error_log('BusinessIdentityService database failure: '.get_class($e));return ['ok'=>false,'error'=>'Business identity could not be saved'];
    }
}
