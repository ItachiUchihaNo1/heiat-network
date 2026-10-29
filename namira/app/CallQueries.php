<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallQueries {
 public static function filter(PDO $db,array $f,bool $own=false,?int $user=null):array {
  $from='FROM art_submissions s JOIN call_work_details w ON w.submission_id=s.id JOIN call_batches b ON b.id=w.batch_id JOIN art_calls c ON c.id=b.call_id JOIN users u ON u.id=s.user_id LEFT JOIN account_identities i ON i.user_id=u.id LEFT JOIN account_profiles p ON p.user_id=u.id LEFT JOIN call_sections sec ON sec.id=w.section_id';
  $where=["w.review_state<>'deleted'"];$args=[];if($own){$where[]='s.user_id=?';$args[]=$user;}else $where[]='w.tracking IS NOT NULL';
  foreach(['call_id'=>'b.call_id','section_id'=>'w.section_id','martyr_id'=>'s.martyr_id','sender_id'=>'s.user_id','review_state'=>'w.review_state','publication_state'=>'w.publication_state'] as $key=>$col)if(($f[$key]??'')!==''){$where[]=$col.'=?';$args[]=in_array($key,['call_id','section_id','martyr_id','sender_id'],true)?CallForms::integer($f[$key],1,2147483647):Security::text($f,$key,100);}
  if(($f['ai_used']??'')!==''){$where[]='w.ai_used=?';$args[]=CallForms::integer($f['ai_used'],0,1);}
  foreach(['q'=>['s.title','w.tracking','b.payload','w.owner_payload','u.email','i.first_name','i.last_name','p.display_name'],'martyr_q'=>['b.payload'],'sender_q'=>['u.email','i.first_name','i.last_name','p.display_name'],'owner_q'=>['w.owner_payload'],'tracking'=>['w.tracking']] as $key=>$columns){
   $q=Security::text($f,$key,200,false);if($q==='')continue;$or=[];foreach($columns as $col){$or[]=$col.' LIKE ?';$args[]='%'.$q.'%';}$where[]='('.implode(' OR ',$or).')';
  }
  foreach(['date_from'=>['>=','00:00:00'],'date_to'=>['<=','23:59:59']] as $key=>$rule){$v=Security::text($f,$key,10,false);if($v==='')continue;$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new HttpError(422,'بازه تاریخ معتبر وارد کنید.');$where[]='w.submitted_at '.$rule[0].' ?';$args[]=$v.' '.$rule[1];}
  return [$from,implode(' AND ',$where),$args];
 }
 public static function works(PDO $db,array $actor,array $f=[],bool $own=false):array {
  if(!$own)CallSchema::permit($db,$actor,'submissions.view');[$from,$where,$args]=self::filter($db,$f,$own,(int)$actor['id']);
  return AdminQueries::paginate($db,'SELECT s.id,s.title,s.revision,s.user_id,s.martyr_id,s.artist_id,s.review_note,s.created_at,s.updated_at,w.*,c.title AS call_title,sec.title AS section_title,b.payload AS batch_payload,u.email,i.first_name AS sender_first,i.last_name AS sender_last,p.display_name AS sender_name',$from,$where,$args,['new'=>'s.id DESC','submitted'=>'w.submitted_at DESC','updated'=>'s.updated_at DESC'],$f,20);
 }
 public static function batches(PDO $db,array $actor):array {return Db::query($db,'SELECT b.*,c.title AS call_title,(SELECT COUNT(*) FROM call_work_details w WHERE w.batch_id=b.id AND w.review_state<>?) AS work_count FROM call_batches b JOIN art_calls c ON c.id=b.call_id WHERE b.user_id=? ORDER BY b.id DESC',['deleted',$actor['id']])->fetchAll();}
 public static function compare(PDO $db,array $actor,int $person):array {
  CallSchema::permit($db,$actor,'submissions.view');$images=(new Archive($db))->forPerson($person,1,0);return ['images'=>$images,'texts'=>CallPublishing::publicWorks($db,$person)];
 }
}
