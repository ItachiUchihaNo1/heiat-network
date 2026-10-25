<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Protocol {
    public function __construct(private PDO $db,private array $actor){if(!in_array($actor['role']??'', ['content','superadmin'],true))throw new HttpError(403,'دسترسی به پرونده مجاز نیست.');}
    public static function decode(string $json):array{return json_decode($json,true,128,JSON_THROW_ON_ERROR);}
    public static function encode(mixed $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public function get(int $id):array {
        $core=Db::query($this->db,'SELECT * FROM martyrs WHERE id=?',[$id])->fetch();if(!$core)throw new HttpError(404,'پرونده پیدا نشد.');
        $p=Db::query($this->db,'SELECT * FROM martyr_protocols WHERE martyr_id=?',[$id])->fetch();
        if(!$p){
            $display=trim($core['first_name'].' '.$core['last_name']);
            Db::insert($this->db,'martyr_protocols',['martyr_id'=>$id,'display_name'=>$display,'archive_code'=>$core['code'],'search_text'=>Security::normalize($display.' '.$core['father_name']),'public_name'=>$core['published']?$display:'','public_snapshot'=>'{}','status'=>$core['published']?'published':'draft','public_fields'=>'[]','updated_at'=>date('Y-m-d H:i:s')]);
            $legacy=['display_name'=>$display,'first_name'=>$core['first_name'],'last_name'=>$core['last_name'],'father_name'=>$core['father_name'],'mother_name'=>'','identity_status'=>'unknown','archive_code'=>$core['code'],'short_bio'=>$core['summary'],'biography'=>ProtocolValidator::safeHtml($core['biography']),'legacy_unreviewed'=>true];
            Db::insert($this->db,'martyr_sections',['martyr_id'=>$id,'step'=>1,'payload'=>self::encode($legacy)]);
            $p=Db::query($this->db,'SELECT * FROM martyr_protocols WHERE martyr_id=?',[$id])->fetch();
        }
        $sections=[];foreach(Db::query($this->db,'SELECT * FROM martyr_sections WHERE martyr_id=?',[$id])->fetchAll() as $s)$sections[(int)$s['step']]=self::decode($s['payload']);
        foreach(Db::query($this->db,'SELECT * FROM protocol_person_terms WHERE martyr_id=?',[$id])->fetchAll() as $t)$sections[(int)$t['step']][$t['field_key']][]=(int)$t['term_id'];
        $records=[];foreach(Db::query($this->db,'SELECT * FROM protocol_records WHERE martyr_id=? AND archived=0 ORDER BY id',[$id])->fetchAll() as $r){$r['data']=self::decode($r['payload']);foreach(Db::query($this->db,'SELECT * FROM protocol_record_terms WHERE record_id=?',[$r['id']])->fetchAll() as $t)$r['data'][$t['field_key']][]=(int)$t['term_id'];$records[$r['kind']][]=$r;}
        return ['core'=>$core,'profile'=>$p,'sections'=>$sections,'records'=>$records];
    }
    public function sources():array{return Db::query($this->db,'SELECT * FROM protocol_sources ORDER BY id DESC')->fetchAll();}
    public function events():array{$events=Db::query($this->db,'SELECT * FROM protocol_events ORDER BY title')->fetchAll();foreach($events as &$event){$event['data']=self::decode($event['payload']);foreach(Db::query($this->db,'SELECT * FROM protocol_event_terms WHERE event_id=?',[$event['id']])->fetchAll() as $t)$event['data'][$t['field_key']][]=(int)$t['term_id'];$event['locations']=Db::query($this->db,'SELECT * FROM protocol_event_locations WHERE event_id=? AND archived=0 ORDER BY id',[$event['id']])->fetchAll();$event['data']['locations']=array_map(fn($r)=>self::decode($r['payload']),$event['locations']);}return $events;}
    public function terms():array{return Db::query($this->db,'SELECT * FROM protocol_terms ORDER BY taxonomy,sort_order,title')->fetchAll();}
    private function audit(?int $id,string $field,mixed $before,mixed $after,string $reason=''):void {if(self::encode($before)===self::encode($after))return;Db::insert($this->db,'protocol_changes',['martyr_id'=>$id,'user_id'=>$this->actor['id'],'field_key'=>$field,'before_value'=>self::encode($before),'after_value'=>self::encode($after),'reason'=>$reason,'created_at'=>date('Y-m-d H:i:s')]);}
    private function lock(int $id,int $revision):array {
        $suffix=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$p=Db::query($this->db,'SELECT * FROM martyr_protocols WHERE martyr_id=?'.$suffix,[$id])->fetch();
        if(!$p||(int)$p['revision']!==$revision)throw new HttpError(409,'پرونده در صفحه دیگری تغییر کرده است. اطلاعات شما حفظ شده؛ نسخه تازه را بررسی کنید.');return $p;
    }
    private function termRows(string $table,array $owner,array $definitions,array &$data):void {
        foreach($definitions as $field=>$def)if($def['type']==='terms'){
            $where=implode(' AND ',array_map(fn($k)=>$k.'=?',array_keys($owner)));Db::query($this->db,'DELETE FROM '.$table.' WHERE '.$where.' AND field_key=?',[...array_values($owner),$field]);
            foreach($data[$field]??[] as $term)Db::insert($this->db,$table,$owner+['field_key'=>$field,'term_id'=>$term]);unset($data[$field]);
        }
    }
    public function save(int $id,int $step,array $in):int {
        if($step<1||$step>7)throw new HttpError(422,'بخش فرم معتبر نیست.');
        if(($in['form_complete']??'')!=='1')throw new HttpError(422,'بخشی از فرم به سرور نرسیده است؛ تعداد رکوردهای این نوبت را کم کنید یا محدودیت ورودی PHP را بررسی کنید. هیچ داده‌ای ذخیره نشد.');
        $old=$id?$this->get($id):null;$validator=new ProtocolValidator($this->db,$this->actor,$id?:null);$input=is_array($in['data']??null)?$in['data']:[];$data=$validator->fields(ProtocolSpec::fields($step),$input,'data.', $old['sections'][$step]??[]);
        if($step===1){if(empty($data['display_name']))$data['display_name']=trim(($data['first_name']??'').' '.($data['last_name']??''));if($data['display_name']==='')$validator->errors['data.display_name']='نام نمایشی برای پیش‌نویس لازم است.';if(!isset($input['identity_status']))$validator->errors['data.identity_status']='وضعیت شناسایی هویت را تعیین کنید.';
            if(!empty($data['archive_code'])){$data['archive_code']=Security::normalize($data['archive_code']);if(!preg_match('/^[A-Za-z0-9_]{1,80}$/D',$data['archive_code']))$validator->errors['data.archive_code']='کد آرشیو متنی و حداکثر ۸۰ نویسه لاتین، رقم یا زیرخط باشد.';else {$existing=Db::query($this->db,'SELECT id FROM martyrs WHERE code=?',[$data['archive_code']])->fetchColumn();if($existing&&(int)$existing!==$id)$validator->errors['data.archive_code']='کد آرشیو تکراری است.';}}}
        if(!$id&&$step!==1)$validator->errors['data.display_name']='ابتدا بخش هویت را ذخیره کنید.';
        $recordData=[];
        foreach(ProtocolSpec::records($step) as $kind=>$label){$rows=$in['records'][$kind]??[];if(!is_array($rows)||count($rows)>50){$validator->errors['records.'.$kind]='حداکثر ۵۰ رکورد در هر نوبت مجاز است.';continue;}
            foreach($rows as $key=>$row){if(!is_array($row))continue;$rid=(int)($row['id']??0);$prior=null;if($rid){$prior=Db::query($this->db,'SELECT * FROM protocol_records WHERE id=? AND martyr_id=? AND kind=?',[$rid,$id,$kind])->fetch();if(!$prior){$validator->errors['records.'.$kind.'.'.$key]='رکورد متعلق به این پرونده نیست.';continue;}}
                if(!empty($row['_archive'])){$recordData[]=['id'=>$rid,'kind'=>$kind,'archived'=>1,'data'=>[]];continue;}
                $values=$validator->fields(ProtocolSpec::fields($kind),$row,'records.'.$kind.'.'.$key.'.',$prior?$this->recordValues($prior):[]);
                if($kind==='military'&&empty($data['military_background']))$validator->errors['data.military_background']='برای ثبت سابقه نظامی، گزینه دارای سابقه را فعال کنید.';
                if($kind==='person_event'&&empty($values['event_id']))$validator->errors['records.'.$kind.'.'.$key.'.event_id']='واقعه مشترک را انتخاب کنید.';
                if($kind==='evidence'&&(empty($values['source_id'])||empty($values['claim_key'])))$validator->errors['records.'.$kind.'.'.$key.'.source_id']='منبع و ادعای مرتبط لازم است.';
                $recordData[]=['id'=>$rid,'kind'=>$kind,'archived'=>0,'data'=>$values];
            }
        }
        if($step===2){$cmp=ProtocolDates::compare($data['birth_date']??[],$data['martyrdom_date']??[]);if($cmp!==null&&$cmp>0)$validator->errors['data.birth_date']='تولد قطعی نمی‌تواند پس از شهادت قطعی باشد.';}
        $validator->assert();
        return Db::transaction($this->db,function()use($id,$step,$data,$in,$recordData,$old){
            if(!$id){$code=$data['archive_code']?:'nm_'.bin2hex(random_bytes(10));$id=Db::insert($this->db,'martyrs',['code'=>$code,'first_name'=>$data['first_name'],'last_name'=>$data['last_name'],'father_name'=>$data['father_name'],'summary'=>$data['short_bio'],'biography'=>$data['biography'],'will_text'=>'','memories'=>'','published'=>0,'created_at'=>date('Y-m-d H:i:s')]);Db::insert($this->db,'martyr_protocols',['martyr_id'=>$id,'display_name'=>$data['display_name'],'archive_code'=>$data['archive_code']?:null,'search_text'=>Security::normalize($data['display_name']),'public_name'=>'','public_snapshot'=>'{}','status'=>'draft','public_fields'=>'[]','updated_at'=>date('Y-m-d H:i:s')]);$profile=Db::query($this->db,'SELECT * FROM martyr_protocols WHERE martyr_id=?',[$id])->fetch();}
            else $profile=$this->lock($id,(int)($in['revision']??-1));
            $payload=$data;$this->termRows('protocol_person_terms',['martyr_id'=>$id,'step'=>$step],ProtocolSpec::fields($step),$payload);
            $exists=Db::query($this->db,'SELECT step FROM martyr_sections WHERE martyr_id=? AND step=?',[$id,$step])->fetchColumn();if($exists)Db::query($this->db,'UPDATE martyr_sections SET payload=? WHERE martyr_id=? AND step=?',[self::encode($payload),$id,$step]);else Db::insert($this->db,'martyr_sections',['martyr_id'=>$id,'step'=>$step,'payload'=>self::encode($payload)]);
            if($step===1){$core=['first_name'=>$data['first_name'],'last_name'=>$data['last_name'],'father_name'=>$data['father_name'],'summary'=>$data['short_bio'],'biography'=>$data['biography']];if($data['archive_code']!=='')$core['code']=$data['archive_code'];if(!$old||!$old['core']['published'])Db::query($this->db,'UPDATE martyrs SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($core))).' WHERE id=?',[...array_values($core),$id]);Db::query($this->db,'UPDATE martyr_protocols SET display_name=?,archive_code=?,search_text=? WHERE martyr_id=?',[$data['display_name'],$data['archive_code']?:null,Security::normalize(implode(' ',[$data['display_name'],$data['first_name'],$data['last_name'],$data['father_name'],implode(' ',$data['alternate_names'])])),$id]);}
            foreach($recordData as $record){$rid=$record['id'];if($record['archived']){if($rid){Db::query($this->db,'UPDATE protocol_records SET archived=1 WHERE id=? AND martyr_id=?',[$rid,$id]);$this->audit($id,'record.'.$rid,$this->record($rid),['archived'=>true],Security::text($in,'reason',2000,false));}continue;}
                $value=$record['data'];$event=$record['kind']==='person_event'?($value['event_id']??null):null;$source=$value['source_id']??null;
                if($event&&Db::query($this->db,"SELECT id FROM protocol_records WHERE martyr_id=? AND kind='person_event' AND event_id=? AND archived=0 AND id<>?",[$id,$event,$rid])->fetchColumn())throw new HttpError(422,'این واقعه قبلاً به پرونده متصل شده است؛ همان ارتباط را ویرایش کنید.');
                $before=$rid?$this->record($rid):null;
                if(!$rid)$rid=Db::insert($this->db,'protocol_records',['martyr_id'=>$id,'kind'=>$record['kind'],'event_id'=>$event,'source_id'=>$source,'payload'=>'{}']);
                $this->termRows('protocol_record_terms',['record_id'=>$rid],ProtocolSpec::fields($record['kind']),$value);
                Db::query($this->db,'UPDATE protocol_records SET payload=?,event_id=?,source_id=?,archived=0 WHERE id=?',[self::encode($value),$event,$source,$rid]);$this->audit($id,'record.'.$rid,$before,$record['data'],Security::text($in,'reason',2000,false));
            }
            foreach(ProtocolSpec::fields($step) as $key=>$def)$this->audit($id,'section.'.$step.'.'.$key,$old['sections'][$step][$key]??null,$data[$key]??null,Security::text($in,'reason',2000,false));
            $next=in_array($profile['status'],['approved','pending'],true)?'draft':$profile['status'];Db::query($this->db,'UPDATE martyr_protocols SET revision=revision+1,status=?,updated_at=? WHERE martyr_id=?',[$next,date('Y-m-d H:i:s'),$id]);return $id;
        });
    }
    private function recordValues(array $prior):array { $out=self::decode($prior['payload']);foreach(Db::query($this->db,'SELECT * FROM protocol_record_terms WHERE record_id=?',[$prior['id']])->fetchAll() as $t)$out[$t['field_key']][]=(int)$t['term_id'];return $out; }
    private function record(int $id):array|false{return Db::query($this->db,'SELECT * FROM protocol_records WHERE id=?',[$id])->fetch();}
    public function shared(string $kind,array $in,?int $id=null):int {
        if(!in_array($kind,['source','event'],true))throw new HttpError(422,'نوع داده مشترک معتبر نیست.');if($id&&$this->actor['role']!=='superadmin')throw new HttpError(403,'ویرایش داده مشترک استفاده‌شده فقط با مدیر است.');
        $v=new ProtocolValidator($this->db,$this->actor);$data=$v->fields(ProtocolSpec::fields($kind),is_array($in['data']??null)?$in['data']:[],'data.');if(empty($data['title']))$v->errors['data.title']='عنوان دقیق لازم است.';$v->assert();
        if($kind==='event'&&ProtocolDates::compare($data['start_date'],$data['end_date'])===1)throw new HttpError(422,'شروع واقعه پس از پایان آن است.');if($kind==='event'&&$id&&($data['parent_id']??null)===$id)throw new HttpError(422,'واقعه والد نمی‌تواند خود واقعه باشد.');
        $locations=[];if($kind==='event'){$input=$in['locations']??[];if(!is_array($input)||count($input)>20)throw new HttpError(422,'حداکثر ۲۰ محل در هر نوبت ثبت شود.');foreach($input as $key=>$location){$lid=(int)($location['id']??0);if($lid&&!Db::query($this->db,'SELECT id FROM protocol_event_locations WHERE id=? AND event_id=?',[$lid,$id])->fetchColumn())throw new HttpError(422,'محل متعلق به واقعه نیست.');$locationData=$v->fields(ProtocolSpec::fields('event_location'),$location,'locations.'.$key.'.');$locations[]=['id'=>$lid,'data'=>$locationData,'archived'=>!empty($location['_archive'])?1:0];}$v->assert();}
        $table=$kind==='source'?'protocol_sources':'protocol_events';return Db::transaction($this->db,function()use($kind,$table,$data,$id,$in,$locations){$before=$id?Db::query($this->db,'SELECT * FROM '.$table.' WHERE id=?',[$id])->fetch():null;if($id&&!$before)throw new HttpError(404,'رکورد پیدا نشد.');$payload=$data;
            if(!$id)$id=Db::insert($this->db,$table,['title'=>$data['title'],'payload'=>'{}','created_by'=>$this->actor['id'],'created_at'=>date('Y-m-d H:i:s')]);
            if($kind==='event')$this->termRows('protocol_event_terms',['event_id'=>$id],ProtocolSpec::fields('event'),$payload);
            Db::query($this->db,'UPDATE '.$table.' SET title=?,payload=? WHERE id=?',[$data['title'],self::encode($payload),$id]);foreach($locations as $location){if($location['id'])Db::query($this->db,'UPDATE protocol_event_locations SET payload=?,archived=? WHERE id=? AND event_id=?',[self::encode($location['data']),$location['archived'],$location['id'],$id]);elseif(!$location['archived'])Db::insert($this->db,'protocol_event_locations',['event_id'=>$id,'payload'=>self::encode($location['data'])]);}$auditData=$data;if($kind==='event')$auditData['locations']=$locations;$this->audit(null,$kind.'.'.$id,$before,$auditData,Security::text($in,'reason',2000,false));return $id;});
    }
    public function warnings(array $bundle):array {
        $s=$bundle['sections'];$r=$bundle['records'];$warnings=[];
        foreach(['birth_date'=>'تاریخ تولد','martyrdom_date'=>'تاریخ شهادت'] as $key=>$label)if(($s[2][$key]['precision']??'unknown')!=='day'||!empty($s[2][$key]['is_approximate']))$warnings[]=$label.' کامل یا دقیق ثبت نشده است.';
        if(empty($r['burial']))$warnings[]='اطلاعات مزار ثبت نشده است.';
        if(!empty($s[4]['military_background'])&&empty($r['military']))$warnings[]='جزئیات سابقه نظامی نیازمند تکمیل است.';
        foreach($r['burial']??[] as $b){if(ProtocolDates::compare($b['data']['burial_date']??[],$s[2]['martyrdom_date']??[])===-1)$warnings[]='تاریخ تدفین پیش از تاریخ شهادت است؛ تعارض نیازمند بررسی است.';}
        foreach($r['person_event']??[] as $event){if(ProtocolDates::compare($event['data']['injury_date']??[],$s[2]['martyrdom_date']??[])===1)$warnings[]='تاریخ آسیب پس از شهادت است؛ نیازمند بررسی است.';}
        $current=array_filter($r['burial']??[],fn($b)=>!empty($b['data']['is_current']));if(count($current)>1)$warnings[]='بیش از یک محل فعلی مزار ثبت شده است.';
        foreach($r['evidence']??[] as $e)if(($e['data']['review_status']??'')==='conflict')$warnings[]='پرونده دارای تعارض منابع است؛ روایت‌های پیشین حفظ شده‌اند.';
        return array_values(array_unique($warnings));
    }
    public function transition(int $id,string $status,array $in):void {
        $bundle=$this->get($id);$from=$bundle['profile']['status'];$allowed=['draft'=>['pending','archived'],'needs_changes'=>['pending','archived'],'pending'=>['approved','needs_changes','archived'],'approved'=>['published','needs_changes','archived'],'published'=>['pending','archived'],'archived'=>['draft']];
        if(!in_array($status,$allowed[$from]??[],true))throw new HttpError(422,'تغییر وضعیت مجاز نیست.');if($status!=='pending'&&$this->actor['role']!=='superadmin')throw new HttpError(403,'بازبینی، انتشار و بایگانی فقط برای مدیر مجاز است.');
        if($status==='pending'){
            $errors=[];if(empty($bundle['sections'][1]['display_name']))$errors['display_name']='نام نمایشی لازم است.';if(empty($bundle['sections'][1]['short_bio']))$errors['short_bio']='معرفی کوتاه برای درخواست بازبینی لازم است.';if(!in_array($bundle['sections'][5]['martyrdom_info_status']??'missing',['unknown','documented'],true))$errors['martyrdom_info_status']='وضعیت اطلاعات شهادت را تعیین کنید؛ نامشخص هم مجاز است.';
            $intro=array_filter($bundle['records']['evidence']??[],fn($e)=>in_array($e['data']['claim_key']??'',['identity','identity.display_name','identity.identification'],true)&&!empty($e['data']['source_id']));if(!$intro)$errors['source']='حداقل یک منبع معرفی یا شناسایی با ادعای identity لازم است.';if($errors)throw new ProtocolErrors($errors);
        }
        $warnings=$this->warnings($bundle);$reason=Security::text($in,'reason',5000,in_array($status,['needs_changes','archived'],true));
        if($status==='published'){
            $publicationErrors=[];
            $scientist=false;foreach($bundle['sections'][3]['occupations']??[] as $term){if(Db::query($this->db,'SELECT title FROM protocol_terms WHERE id=?',[$term])->fetchColumn()==='دانشمند')$scientist=true;}
            if($scientist&&!array_filter($bundle['records']['evidence']??[],fn($e)=>in_array($e['data']['claim_key']??'',['occupation','section.3.occupations'],true)&&!empty($e['data']['source_id'])))$publicationErrors['occupation']='عنوان دانشمند برای انتشار به منبع مرتبط با ادعای occupation نیاز دارد.';
            foreach($bundle['records']['military']??[] as $mil){foreach(['rank_event','rank_martyrdom','rank_posthumous','position'] as $field)if(!empty($mil['data'][$field])&&empty($mil['data'][$field.'_source']))$publicationErrors['military.'.$mil['id'].'.'.$field]='منبع اختصاصی هر درجه و سمت نظامی برای انتشار لازم است.';}
            if($publicationErrors)throw new ProtocolErrors($publicationErrors);
            if(($in['publication_ack']??'')!=='1')throw new HttpError(422,'تأیید صریح مجوز ناشر برای انتشار لازم است.');
            if($warnings&&(($in['incomplete_ack']??'')!=='1'||$reason===''))throw new HttpError(422,'برای انتشار پرونده ناقص یا متعارض، تأیید هشدارها و دلیل لازم است.');
        }
        Db::transaction($this->db,function()use($id,$status,$in,$from,$reason,$bundle){$this->lock($id,(int)($in['revision']??-1));$snapshot=$bundle['profile']['public_snapshot'];$publicName=$bundle['profile']['public_name'];$publicFields=self::decode($bundle['profile']['public_fields']);
            if($status==='published'){$publicFields=!empty($in['publish_mother_name'])?['mother_name']:[];$snapshot=self::encode($this->publicSnapshot($bundle,$publicFields));$publicName=$bundle['profile']['display_name'];
                Db::query($this->db,'DELETE FROM protocol_memberships WHERE martyr_id=?',[$id]);
                $selected=$bundle['sections'][6]['collections']??[];foreach($this->terms() as $candidate){if($candidate['taxonomy']!=='collection'||!$candidate['active'])continue;$meta=self::decode($candidate['metadata']);if(($meta['mode']??'')==='filter'){foreach($bundle['sections'] as $sec)if(in_array((int)($meta['filter_term_id']??0),$sec[$meta['filter_field']??'']??[],true))$selected[]=(int)$candidate['id'];}}
                foreach(array_unique($selected) as $term){$catalog=Db::query($this->db,'SELECT metadata FROM protocol_terms WHERE id=?',[$term])->fetchColumn();$meta=$catalog?self::decode($catalog):[];if(($meta['mode']??'')==='filter')continue; $evidence=array_values(array_filter($bundle['records']['evidence']??[],fn($e)=>in_array($e['data']['claim_key']??'',['collections','collections.'.$term],true)&&($e['data']['review_status']??'')==='reviewed'&&!empty($e['data']['source_id'])));if(!$evidence)throw new HttpError(422,'انتساب مجموعه‌های موضوعی به منبع و تأیید بازبین در بخش منابع نیاز دارد.');Db::insert($this->db,'protocol_memberships',['martyr_id'=>$id,'term_id'=>$term,'source_id'=>$evidence[0]['data']['source_id'],'approved_by'=>$this->actor['id'],'approved_at'=>date('Y-m-d H:i:s')]);}
            }
            Db::query($this->db,'UPDATE martyr_protocols SET status=?,public_snapshot=?,public_name=?,public_fields=?,revision=revision+1,updated_at=? WHERE martyr_id=?',[$status,$snapshot,$publicName,self::encode($publicFields),date('Y-m-d H:i:s'),$id]);
            if($status==='published'){ $identity=$bundle['sections'][1]??[]; Db::query($this->db,'UPDATE martyrs SET published=1,first_name=?,last_name=?,father_name=?,summary=?,biography=? WHERE id=?',[$identity['first_name']??'', $identity['last_name']??'', $identity['father_name']??'', $identity['short_bio']??'', $identity['biography']??'', $id]);}if($status==='archived')Db::query($this->db,'UPDATE martyrs SET published=0 WHERE id=?',[$id]);
            Db::insert($this->db,'protocol_reviews',['martyr_id'=>$id,'user_id'=>$this->actor['id'],'from_status'=>$from,'to_status'=>$status,'reason'=>$reason,'incomplete_ack'=>!empty($in['incomplete_ack'])?1:0,'created_at'=>date('Y-m-d H:i:s')]);$this->audit($id,'publication.status',$from,$status,$reason);
        });
    }
    public function publicSnapshot(array $bundle,array $publicFields):array {
        $data=['display_name'=>$bundle['profile']['display_name'],'archive_code'=>$bundle['profile']['archive_code'],'sections'=>$bundle['sections'],'records'=>$bundle['records'],'terms'=>[],'sources'=>[],'events'=>[],'legacy_text'=>['will_text'=>$bundle['core']['will_text']??'','memories'=>$bundle['core']['memories']??'']];
        foreach($this->terms() as $term){$data['terms'][$term['id']]=$term['title'];if($term['taxonomy']==='collection'&&$term['active']){$meta=self::decode($term['metadata']);if(($meta['mode']??'')==='filter')foreach($bundle['sections'] as $section)if(in_array((int)($meta['filter_term_id']??0),$section[$meta['filter_field']??'']??[],true))$data['sections'][6]['collections'][]=(int)$term['id'];}}if(isset($data['sections'][6]['collections']))$data['sections'][6]['collections']=array_values(array_unique($data['sections'][6]['collections']));
        foreach($this->sources() as $source)$data['sources'][$source['id']]=['title'=>$source['title'],'data'=>self::decode($source['payload'])];
        foreach($this->events() as $event)$data['events'][$event['id']]=['title'=>$event['title'],'data'=>$event['data']];
        // Include only referenced shared records, never unrelated archive entries.
        $sourceIds=[];$eventIds=[];$collect=function(mixed $item)use(&$collect,&$sourceIds,&$eventIds){if(!is_array($item))return;foreach($item as $k=>$v){if((str_contains((string)$k,'source')||$k==='source_id')&&is_int($v))$sourceIds[]=$v;if($k==='event_id'&&is_int($v))$eventIds[]=$v;if(is_array($v))$collect($v);}};$collect([$bundle['sections'],$bundle['records']]);
        foreach(array_intersect_key($data['events'],array_flip($eventIds)) as $event)$collect($event['data']);
        $data['sources']=array_intersect_key($data['sources'],array_flip($sourceIds));$data['events']=array_intersect_key($data['events'],array_flip($eventIds));
        $clean=function(mixed $value)use(&$clean,$publicFields):mixed{if(!is_array($value))return $value;$out=[];foreach($value as $key=>$v){if(in_array($key,['address_text','latitude','longitude','user_id','created_by','public_snapshot','before_value','after_value'],true))continue;if($key==='mother_name'&&!in_array('mother_name',$publicFields,true))continue;if($key==='payload')continue;$out[$key]=$clean($v);}return $out;};return $clean($data);
    }
    public function similar(array $bundle):array {
        $name=Security::normalize($bundle['profile']['display_name']);return array_values(array_filter(Db::query($this->db,'SELECT martyr_id,display_name,search_text FROM martyr_protocols WHERE martyr_id<>? LIMIT 500',[$bundle['core']['id']])->fetchAll(),fn($p)=>str_contains($p['search_text'],$name)||Security::normalize($p['display_name'])===$name));
    }
}
