<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Reconcile only a verified Eitaa member placeholder, never arbitrary website identities. */
final class EitaaIdentity {
 public static function mergePlaceholder(PDO $db,int $source,int $target):void {
  if($source===$target)return;$lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$u=Db::query($db,'SELECT email,role FROM users WHERE id=?'.$lock,[$source])->fetch();$old=Db::query($db,'SELECT * FROM account_identities WHERE user_id=?'.$lock,[$source])->fetch();
  if(!$u||$u['role']!=='member'||!preg_match('/^eitaa-[a-f0-9]+@members\.invalid$/D',$u['email'])||!empty($old['mobile'])||Db::query($db,"SELECT role_key FROM user_roles WHERE user_id=? AND role_key<>'member'",[$source])->fetchColumn())throw new HttpError(409,'این حساب اطلاعات ورود مستقل دارد؛ اتصال خودکار مجاز نیست.');
  $tables=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?Db::query($db,'SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN):Db::query($db,"SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);$has=fn($t)=>in_array($t,$tables,true);
  // Two independent join applications or shopping carts need an explicit human decision; roll back everything.
  foreach(['artist_join_requests','account_cart_owners'] as $table)if($has($table)&&Db::query($db,'SELECT user_id FROM '.$table.' WHERE user_id=?',[$source])->fetchColumn()&&Db::query($db,'SELECT user_id FROM '.$table.' WHERE user_id=?',[$target])->fetchColumn())throw new HttpError(409,'دو حساب دارای سبد یا درخواست هنری هم‌زمان هستند؛ برای حفظ اطلاعات، اتصال باید توسط پشتیبانی بررسی شود.');
  foreach(['artist_accounts','artist_archive_selections'] as $table)if($has($table)){
   foreach(Db::query($db,'SELECT * FROM '.$table.' WHERE user_id=?',[$source])->fetchAll() as $row){$key=$table==='artist_accounts'?'artist_id':'image_id';if(Db::query($db,'SELECT user_id FROM '.$table.' WHERE user_id=? AND '.$key.'=?',[$target,$row[$key]])->fetchColumn())continue;$row['user_id']=$target;Db::insert($db,$table,$row);
    if($table==='artist_accounts'&&$has('artist_account_states')){$state=Db::query($db,'SELECT * FROM artist_account_states WHERE artist_id=? AND user_id=?',[$row['artist_id'],$source])->fetch();if($state){$state['user_id']=$target;Db::insert($db,'artist_account_states',$state);}}
   }
  }
  $owners=['incoming_assets'=>'owner_id','art_submissions'=>'user_id','public_contributions'=>'user_id','artist_account_claims'=>'user_id','artist_join_requests'=>'user_id','artist_work_claims'=>'user_id','account_cart_owners'=>'user_id','account_order_owners'=>'user_id','account_request_owners'=>'user_id','account_addresses'=>'user_id','call_batches'=>'user_id','call_work_details'=>'owner_user_id','store_order_details'=>'user_id','store_files'=>'owner_id','store_notifications'=>'user_id','store_quotes'=>'user_id','store_coupon_redemptions'=>'user_id','store_download_events'=>'user_id','design_request_details'=>'assigned_user_id','store_item_details'=>'assigned_user_id'];
  foreach($owners as $table=>$column)if($has($table))Db::query($db,'UPDATE '.$table.' SET '.$column.'=? WHERE '.$column.'=?',[$target,$source]);
  // Keep historical authorship and the old record for audit; it cannot establish another active account.
  Db::query($db,'UPDATE users SET active=0 WHERE id=?',[$source]);Db::audit($db,'account.eitaa.unify',$source.':'.$target);
 }
 public static function bindPhone(PDO $db,int $current,string $mobile):int {
  MobileAuth::lockMobile($db,$mobile);$known=Db::query($db,'SELECT user_id FROM account_identities WHERE mobile=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$mobile])->fetchColumn();$target=$known?(int)$known:$current;
  if($current&&$target!==$current)self::mergePlaceholder($db,$current,$target);
  if($target){$old=MobileAuth::identity($db,$target);if(!empty($old['mobile'])&&$old['mobile']!==$mobile)throw new HttpError(409,'شماره تأییدشده حساب متفاوت است.');$now=date(DATE_ATOM);if($old)Db::query($db,'UPDATE account_identities SET mobile=?,mobile_verified_at=?,revision=revision+1,updated_at=? WHERE user_id=?',[$mobile,$now,$now,$target]);else Db::insert($db,'account_identities',['user_id'=>$target,'first_name'=>'','last_name'=>'','mobile'=>$mobile,'mobile_verified_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);}
  return $target;
 }
}
