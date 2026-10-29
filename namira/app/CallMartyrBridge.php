<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallMartyrBridge {
 public static function create(PDO $db,array $actor,int $workId,array $in):int {
  CallSchema::permit($db,$actor,'submissions.publish');CallSchema::permit($db,$actor,'martyrs.edit');
  if(($in['identity_ack']??'')!=='1')throw new HttpError(422,'اطلاعات هویتی پیشنهادی را پیش از ساخت پرونده بررسی و تأیید کنید.');
  SimpleMartyrSchema::ensure($db,$actor);
  return Db::transaction($db,function()use($db,$actor,$workId,$in){
   $work=CallEntries::get($db,$workId,$actor,false,true);$version=CallPublishing::version($db,$work);$snap=$version['data'];
   $proposal=CallMartyrFields::validate($db,$actor,$snap['martyr_definitions'],(array)($in['martyr']??$snap['martyr']),true);
   $code=Security::text($in,'code',80);$first=Security::text($in,'first_name',150,false)?:($proposal['first_name']??'');$last=Security::text($in,'last_name',150,false)?:($proposal['last_name']??'');
   $protocol=new Protocol($db,$actor);$person=$protocol->quick(['code'=>$code,'first_name'=>$first,'last_name'=>$last]);
   $proposal['archive_code']=$code;$proposal['first_name']=$first;$proposal['last_name']=$last;
   foreach(range(1,7) as $step){$defs=SimpleMartyrForm::definitions($db,$step);$part=array_intersect_key($proposal,$defs);if(!$part)continue;
    $revision=$protocol->get($person)['profile']['revision'];SimpleMartyrForm::save($db,$actor,$person,$step,['revision'=>$revision,'form_complete'=>'1','data'=>$part]);
   }
   Db::query($db,'UPDATE art_submissions SET martyr_id=?,revision=revision+1,updated_at=? WHERE id=?',[$person,date('Y-m-d H:i:s'),$workId]);
   CommunityHistory::add($db,'art_submission',$workId,$actor,'proposal','identity_draft_created',Protocol::encode(['martyr_id'=>$person,'version_id'=>$version['id']]));
   return $person;
  });
 }
}
