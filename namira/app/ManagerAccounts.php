<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Custom manager grants use the existing role tables; legacy roles are untouched. */
final class ManagerAccounts {
 public const GROUPS=[
  'martyrs'=>['ثبت و ویرایش شهدا',['martyrs.view','martyrs.edit']],
  'media'=>['بارگذاری و مدیریت تصاویر',['media.view','media.create','media.edit']],
  'artists'=>['مدیریت هنرمندان',['artists.view','artists.edit']],
  'taxonomy'=>['مدیریت دسته‌بندی‌ها',['taxonomy.view','taxonomy.manage']],
  'calls'=>['مدیریت فراخوان‌ها و آثار دریافتی',['calls.*','submissions.*','martyrs.view','artists.view']],
  'requests'=>['مدیریت درخواست‌های طراحی',['requests.view','requests.edit']],
  'contributions'=>['مدیریت مشارکت‌های مردمی',['contributions.*','martyrs.view','media.view']],
  'reports'=>['گزارش آرشیو',['reports.archive','martyrs.view','media.view']]
 ];
 public static function selected(PDO $db,int $id):array {
  $user=Db::query($db,'SELECT id,role FROM users WHERE id=?',[$id])->fetch();if(!$user)return [];$groups=[];
  foreach(self::GROUPS as $key=>[$label,$grants])if(array_reduce($grants,fn($ok,$grant)=>$ok&&Permissions::allows($db,$user,$grant),true))$groups[]=$key;return $groups;
 }
 public static function save(PDO $db,array $actor,array $in):int {
  if(($actor['role']??'')!=='superadmin'||!Permissions::allows($db,$actor,'users.manage'))throw new HttpError(403,'تعیین دسترسی مدیران فقط برای مدیر اصلی مجاز است.');
  $id=filter_var($in['id']??0,FILTER_VALIDATE_INT);if($id===false||$id<0)throw new HttpError(422,'شناسه حساب معتبر نیست.');
  $groups=$in['groups']??[];if(!is_array($groups)||count($groups)>count(self::GROUPS))throw new HttpError(422,'مجوزهای انتخاب‌شده معتبر نیستند.');foreach($groups as $g)if(!is_string($g)||!isset(self::GROUPS[$g]))throw new HttpError(422,'مجوز ناشناخته قابل ثبت نیست.');$groups=array_values(array_unique($groups));
  return Db::transaction($db,function()use($db,$actor,$id,$in,$groups){
   $lock=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$old=$id?Db::query($db,'SELECT id,role FROM users WHERE id=?'.$lock,[$id])->fetch():false;
   if($old&&$old['role']==='superadmin')throw new HttpError(422,'حساب مدیر اصلی را از بخش کاربران مدیریت کنید؛ این فرم مخصوص مدیران با مجوز محدود است.');
   $id=Accounts::save($db,$actor,['id'=>$id,'email'=>$in['email']??'','password'=>$in['password']??'','active'=>$in['active']??0,'roles'=>['member']]);
   $key='manager_'.$id;if(!Db::query($db,'SELECT role_key FROM roles WHERE role_key=?',[$key])->fetchColumn())Db::insert($db,'roles',['role_key'=>$key,'title'=>'مدیر با دسترسی انتخابی']);
   Db::query($db,'DELETE FROM role_permissions WHERE role_key=?',[$key]);$permissions=['dashboard.view'];foreach($groups as $g)$permissions=[...$permissions,...self::GROUPS[$g][1]];
   foreach(array_unique($permissions) as $permission)Db::insert($db,'role_permissions',['role_key'=>$key,'permission'=>$permission]);Db::insert($db,'user_roles',['user_id'=>$id,'role_key'=>$key]);Db::audit($db,'manager.access.save',(string)$id);return $id;
  });
 }
 public static function listing(PDO $db,array $f):array {
  $roles=[];foreach(Permissions::defaults() as $role=>$grants)if(in_array('dashboard.view',$grants,true)||in_array('*',$grants,true))$roles[]=$role;
  $where='(u.role IN ('.implode(',',array_fill(0,count($roles),'?')).") OR EXISTS(SELECT 1 FROM user_roles ur JOIN role_permissions rp ON rp.role_key=ur.role_key WHERE ur.user_id=u.id AND rp.permission IN ('dashboard.view','*')))";$args=$roles;
  $q=Security::text($f,'q',190,false);if($q!==''){$where.=' AND u.email LIKE ?';$args[]='%'.$q.'%';}
  return AdminQueries::paginate($db,'SELECT u.id,u.email,u.role,u.active','FROM users u',$where,$args,['new'=>'u.id DESC'],$f);
 }
}
