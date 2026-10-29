<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CallHttp {
 public static function handles(string $action):bool {return str_starts_with($action,'call2-');}
 public static function modern(PDO $db,int $id):bool {if(!CallSchema::ready($db)||!$id)return false;$v=Db::query($db,'SELECT criteria FROM art_calls WHERE id=?',[$id])->fetchColumn();return $v&&(Protocol::decode($v)['workflow']??0)===2;}
 public static function arrayInput(array $in,string $key,array $default=[]):array {
  if(isset($in[$key.'_json'])){if(!is_string($in[$key.'_json'])||strlen($in[$key.'_json'])>3000000)throw new HttpError(422,'ساختار فرم بیش از ظرفیت مجاز است.');try{$v=json_decode($in[$key.'_json'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'ساختار فرم معتبر نیست.');}}
  else $v=$in[$key]??$default;if(!is_array($v))throw new HttpError(422,'فهرست اطلاعات معتبر لازم است.');return $v;
 }
 public static function json(array $data,int $status=200):never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);exit;}
 private static function previews():array {
  $f=$_FILES['verified_previews']??null;if(!$f)return [];$out=[];if(!is_array($f['name']))throw new HttpError(422,'شناسه پیش‌نمایش معتبر نیست.');
  foreach($f['name'] as $key=>$name){if(($f['error'][$key]??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)continue;if(!ctype_digit((string)$key))throw new HttpError(422,'شناسه پیش‌نمایش معتبر نیست.');$out[(int)$key]=Upload::receive(['name'=>$name,'tmp_name'=>$f['tmp_name'][$key],'error'=>$f['error'][$key],'size'=>$f['size'][$key]]);}return $out;
 }
 public static function action(PDO $db,string $action):never {
  CallSchema::requireReady($db);$actor=Security::user($db);if(!$actor)throw new HttpError(403,'ابتدا وارد حساب شوید.');
  $id=(int)($_POST['id']??0);$call=(int)($_POST['call_id']??0);$batch=(int)($_POST['batch_id']??0);$route='account';$params=['section'=>'submissions'];$data=[];$message='اطلاعات ثبت شد.';
  try{
   switch($action){
    case 'call2-save':
     $route='admin';$params=['tab'=>'calls']+($id?['edit'=>$id]:['new'=>1]);$input=$_POST;$input['common_fields']=self::arrayInput($_POST,'common_fields');$input['martyr_fields']=self::arrayInput($_POST,'martyr_fields');if(isset($_POST['martyr_selection_present'])){$input['martyr_fields']=[];$required=self::arrayInput($_POST,'martyr_required');foreach(self::arrayInput($_POST,'martyr_keys') as $key){if(!is_string($key))throw new HttpError(422,'فیلد شهید معتبر نیست.');$input['martyr_fields'][$key]=['required'=>in_array($key,$required,true)];}}$poster=Web::files('poster');if(count($poster)>1)throw new HttpError(422,'فقط یک پوستر انتخاب کنید.');
     $id=CallCampaigns::save($db,$actor,$input,$id,$poster?Upload::receive($poster[0]):null);$params=['tab'=>'calls','edit'=>$id];$message='فراخوان ذخیره شد؛ وضعیت انتشار مستقل است.';break;
    case 'call2-state':$route='admin';$params=['tab'=>'calls','edit'=>$id];CallCampaigns::state($db,$actor,$id,$_POST);break;
    case 'call2-section':
     $route='admin';$params=['tab'=>'calls','edit'=>$call,'section'=>$id];$input=$_POST;$input['fields']=self::arrayInput($_POST,'fields');$id=CallCampaigns::saveSection($db,$actor,$call,$input,$id);$params['section']=$id;$message='بخش و فرم اختصاصی ذخیره شد؛ پاسخ‌های قبلی محفوظ‌اند.';break;
    case 'call2-type':$route='admin';$params=['tab'=>'calls','edit'=>$call];$id=CallCampaigns::saveContentType($db,$actor,$_POST,$id);$data=['type_id'=>$id];break;
    case 'call2-start':
     $route='call';$params=['id'=>$call];$batch=CallEntries::start($db,$actor,$call,Security::text($_POST,'token',64));$route='submit-art';$params=['call_id'=>$call,'batch'=>$batch,'step'=>1];$data=['batch_id'=>$batch];$message='پیش‌نویس ارسال ایجاد شد.';break;
    case 'call2-common':
     $route='submit-art';$params=['call_id'=>$call,'batch'=>$batch,'step'=>1];$b=CallEntries::common($db,$actor,$batch,$_POST);$data=['revision'=>$b['revision']];$message='اطلاعات مشترک ذخیره شد.';break;
    case 'call2-work':
     $route='submit-art';$params=['call_id'=>$call,'batch'=>$batch,'step'=>2];$w=CallEntries::save($db,$actor,$batch,$_POST,$id);$data=['work_id'=>$w['id'],'revision'=>$w['revision']];
     if(!$id||!empty($_POST['refresh_fields']))$data['html']=CallUI::render('call-work-card',['db'=>$db,'actor'=>$actor,'batch'=>$w['batch'],'work'=>$w,'call'=>CallCampaigns::get($db,$w['call_id']),'errors'=>[]]);
     $message='پیش‌نویس اثر ذخیره شد.';break;
    case 'call2-upload':
     $route='submit-art';$params=['call_id'=>$call,'batch'=>$batch,'step'=>2];$files=Web::files('file');$previews=Web::files('preview');if(count($files)!==1||count($previews)>1)throw new HttpError(422,'هر بار یک فایل برای بارگذاری مستقل انتخاب کنید.');
     $data=CallEntries::upload($db,$actor,$batch,(int)($_POST['work_id']??0)?:null,Security::text($_POST,'field_key',160),Upload::receive($files[0]),$files[0]['name'],$previews?Upload::receive($previews[0]):null);$data['url']=url('call-file',['id'=>$data['id']]);$data['preview_url']=$data['preview']?url('call-file',['id'=>$data['id'],'size'=>'preview']):null;$message='فایل با موفقیت ذخیره شد.';break;
    case 'call2-remove-file':$route='submit-art';$params=['call_id'=>$call,'batch'=>$batch,'step'=>2];$data=CallEntries::removeFile($db,$actor,$id);$message='فایل از نسخه در حال ویرایش کنار گذاشته شد؛ نسخه‌های قبلی محفوظ‌اند.';break;
    case 'call2-delete-work':$route='submit-art';$params=['call_id'=>$call,'batch'=>$batch,'step'=>2];CallEntries::deleteDraft($db,$actor,$id);break;
    case 'call2-finalize':
     $route='submit-art';$params=['call_id'=>$call,'batch'=>$batch,'step'=>3];$input=$_POST;if(isset($_POST['work_ids'])||isset($_POST['work_ids_json']))$input['work_ids']=self::arrayInput($_POST,'work_ids');
     $data=CallEntries::finalize($db,$actor,$batch,$input);$_SESSION['call_receipts'][$batch]=$data;$message=fa($data['success']).' اثر ثبت نهایی شد؛ '.fa($data['failed']).' اثر نیاز به تکمیل دارد.';break;
    case 'call2-review':$route='admin';$params=['tab'=>'submissions','edit'=>$id];CallEntries::review($db,$actor,$id,$_POST);break;
    case 'call2-publish':
     $route='admin';$params=['tab'=>'submissions','edit'=>$id];$input=$_POST;$input['asset_ids']=self::arrayInput($_POST,'asset_ids');$data=CallPublishing::publish($db,$actor,$id,$input,self::previews());$message='نسخه تأییدشده اثر در صفحه شهید منتشر شد.';break;
    case 'call2-unpublish':$route='admin';$params=['tab'=>'submissions','edit'=>$id];CallPublishing::unpublish($db,$actor,$id,$_POST);$message='انتشار عمومی کنار گذاشته شد؛ سوابق و فایل‌ها محفوظ‌اند.';break;
    case 'call2-new-martyr':
     $route='admin';$params=['tab'=>'submissions','edit'=>$id];$person=CallMartyrBridge::create($db,$actor,$id,$_POST);$data=['martyr_id'=>$person,'edit_url'=>url('admin',['tab'=>'martyrs','edit'=>$person])];$message='پرونده جدید با فیلدهای اصلی ایجاد شد؛ هنوز انتشار عمومی نشده است.';break;
    case 'call2-export':
     $route='admin';$params=['tab'=>'submissions'];$options=$_POST;if(isset($_POST['selected_ids'])||isset($_POST['selected_ids_json']))$options['selected_ids']=self::arrayInput($_POST,'selected_ids');$scope=Security::text($_POST,'scope',20,false)?:'filtered';if(!in_array($scope,['all','filtered','selected'],true))throw new HttpError(422,'محدوده خروجی معتبر نیست.');if($scope==='selected'&&empty($options['selected_ids']))throw new HttpError(422,'حداقل یک اثر را انتخاب کنید.');if($scope!=='selected')unset($options['selected_ids']);$filters=$scope==='filtered'?self::arrayInput($_POST,'filters'):[];$id=CallExport::create($db,$actor,$filters,$options);$params['export']=$id;$data=CallExport::status(CallExport::get($db,$actor,$id));$message='ساخت خروجی آغاز شد.';break;
    case 'call2-export-step':$route='admin';$params=['tab'=>'submissions','export'=>$id];$data=CallExport::step($db,$actor,$id);$message=$data['state']==='ready'?'خروجی آماده است.':'ساخت خروجی ادامه دارد.';break;
    case 'call2-notifications-read':Db::query($db,'UPDATE call_notifications SET read_at=? WHERE user_id=? AND read_at IS NULL',[date(DATE_ATOM),$actor['id']]);break;
    default:throw new HttpError(404,'عملیات فراخوان پیدا نشد.');
   }
   unset($_SESSION['calls_feedback']);$next=url($route,$params);
   if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json'))self::json(['ok'=>true,'message'=>$message,'next'=>$next,'data'=>$data]);
   $_SESSION['flash']=$message;redirect($route,$params);
  }catch(HttpError|ProtocolErrors $e){
   $errors=$e instanceof ProtocolErrors?$e->errors:['form'=>$e->getMessage()];$status=$e instanceof HttpError?$e->status:422;
   if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json'))self::json(['ok'=>false,'message'=>$e->getMessage(),'errors'=>$errors],$status);
   $input=$_POST;unset($input['csrf']);$_SESSION['calls_feedback']=['route'=>$route,'params'=>$params,'input'=>$input,'errors'=>$errors];redirect($route,$params);
  }
 }
 public static function feedback(string $route,array $params):array {
  $f=$_SESSION['calls_feedback']??null;unset($_SESSION['calls_feedback']);return $f&&$f['route']===$route&&$f['params']===$params?['old'=>$f['input'],'errors'=>$f['errors']]:['old'=>[],'errors'=>[]];
 }
 public static function page(PDO $db,string $page,array|false $actor):string {
  CallSchema::requireReady($db);$data=['db'=>$db,'actor'=>$actor,'errors'=>[],'old'=>[]];
  if($page==='calls'){view('calls-list-v2',$data+['result'=>CallCampaigns::listing($db,$_GET,true)]);return 'فراخوان‌های نامیرا';}
  if($page==='call'){$call=CallCampaigns::get($db,(int)($_GET['id']??0),true);view('call-detail-v2',array_replace($data+['call'=>$call],self::feedback('call',['id'=>$call['id']])));return $call['title'].' | نامیرا';}
  if(!$actor||!MobileAuth::verified($db,$actor))redirect('member-login',['next_page'=>'call','call_id'=>(int)($_GET['call_id']??0)]);
  $batchId=(int)($_GET['batch']??0);$call=CallCampaigns::get($db,(int)($_GET['call_id']??0),true);
  if(!$batchId){view('call-detail-v2',$data+['call'=>$call]);return $call['title'];}
  $batch=CallEntries::batch($db,$batchId,$actor,true);if((int)$batch['call_id']!==(int)$call['id'])throw new HttpError(404,'ارسال پیدا نشد.');
  $step=max(1,min(3,(int)($_GET['step']??1)));$workRows=Db::query($db,"SELECT submission_id FROM call_work_details WHERE batch_id=? AND review_state<>'deleted' ORDER BY submission_id",[$batchId])->fetchAll(PDO::FETCH_COLUMN);$works=[];foreach($workRows as $id)$works[]=CallEntries::get($db,(int)$id,$actor,true);
  view('call-submit',array_replace($data+compact('call','batch','works','step'),self::feedback('submit-art',['call_id'=>$call['id'],'batch'=>$batchId,'step'=>$step])));return 'ارسال آثار | '.$call['title'];
 }
 public static function admin(PDO $db,array $actor,string $tab,array $data):void {
  $id=(int)($_GET['edit']??0);$params=['tab'=>$tab]+($id?['edit'=>$id]:(!empty($_GET['new'])?['new'=>1]:[]));if(array_key_exists('section',$_GET))$params['section']=(int)$_GET['section'];$data=array_replace($data,self::feedback('admin',$params));$data+=['actor'=>$actor,'db'=>$db,'old'=>[],'errors'=>[]];
  if($tab==='calls'){
   if($id||!empty($_GET['new'])){
    CallSchema::permit($db,$actor,'calls.edit');$call=$id?CallCampaigns::get($db,$id):null;$sectionId=(int)($_GET['section']??0);$section=null;foreach($call['sections']??[] as $s)if((int)$s['id']===$sectionId)$section=$s;
    view('call-admin-editor',$data+compact('call','section','sectionId')+['contentTypes'=>CallCampaigns::contentTypes($db),'martyrFields'=>CallMartyrFields::catalog($db)]);
   }else view('call-admin-list',$data+['result'=>CallCampaigns::listing($db,$_GET)]);
   return;
  }
  if(!empty($_GET['export'])){view('call-export',$data+['job'=>CallExport::get($db,$actor,(int)$_GET['export'])]);return;}
  if($id&&Db::query($db,'SELECT submission_id FROM call_work_details WHERE submission_id=?',[$id])->fetchColumn()){
   $prepared=CallPublishing::prepare($db,$actor,$id);$work=$prepared['work'];$person=(int)($_GET['compare_martyr']??$work['martyr_id']??0);
   $compare=$person?CallQueries::compare($db,$actor,$person):null;$terms=Db::query($db,"SELECT id,title FROM protocol_terms WHERE taxonomy='media_type' AND active=1 ORDER BY title")->fetchAll();
   view('call-admin-review',$data+$prepared+compact('compare','person','terms'));
  }else view('call-admin-works',$data+['result'=>CallQueries::works($db,$actor,$_GET),'calls'=>Db::query($db,'SELECT id,title FROM art_calls ORDER BY id DESC')->fetchAll(),'sections'=>Db::query($db,'SELECT id,call_id,title FROM call_sections ORDER BY title')->fetchAll()]);
 }
 public static function accountData(PDO $db,array $actor):array {
  $id=(int)($_GET['id']??0);$entry=$id?CallEntries::get($db,$id,$actor,true):null;
  $legacy=Db::query($db,'SELECT s.id,s.title,s.status,s.created_at FROM art_submissions s LEFT JOIN call_work_details w ON w.submission_id=s.id WHERE s.user_id=? AND w.submission_id IS NULL ORDER BY s.id DESC LIMIT 100',[$actor['id']])->fetchAll();
  return ['callsResult'=>CallQueries::works($db,$actor,$_GET,true),'callsBatches'=>CallQueries::batches($db,$actor),'callEntry'=>$entry,'legacySubmissions'=>$legacy,'callNotifications'=>Db::query($db,'SELECT * FROM call_notifications WHERE user_id=? ORDER BY id DESC LIMIT 30',[$actor['id']])->fetchAll()];
 }
 public static function file(PDO $db,bool $public=false):never {
  if($public){$a=CallFiles::publicFile($db,(int)($_GET['id']??0));$filename=Protocol::decode($a['payload'])['filename']??'file';}
  else{$a=CallFiles::privateFile($db,(int)($_GET['id']??0),Security::user($db));$filename=$a['filename'];}
  $preview=($_GET['size']??'')==='preview';if($preview&&empty($a['preview_path']))throw new HttpError(404,'این فایل پیش‌نمایش تصویری ندارد.');
  header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');Web::sendFile(Upload::absolute($a[$preview?'preview_path':'original_path']),$preview?'image/webp':$a['mime'],$preview?'inline':'attachment',$preview?'preview.webp':$filename);exit;
 }
 public static function download(PDO $db):never {
  $actor=Security::user($db);if(!$actor)throw new HttpError(404,'خروجی پیدا نشد.');$file=CallExport::download($db,$actor,(int)($_GET['id']??0),(int)($_GET['part']??1),Security::text($_GET,'kind',10));header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');Web::sendFile($file['path'],$file['mime'],'attachment',$file['filename']);exit;
 }
 public static function lookup(PDO $db):never {
  $actor=Security::user($db);if(!$actor)throw new HttpError(403,'ورود لازم است.');CallSchema::permit($db,$actor,'submissions.view');$kind=Security::text($_GET,'kind',20);$q=Security::text($_GET,'q',150,false);$rows=[];
  if(mb_strlen($q)>=2){
   if($kind==='martyrs'){$private=Permissions::allows($db,$actor,'martyrs.view');$parts=[];$args=[];foreach(array_slice(preg_split('/\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY),0,10) as $word){$parts[]='(code LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';$args=[...$args,...array_fill(0,3,'%'.$word.'%')];}$rows=Db::query($db,'SELECT id,code,first_name,last_name,published FROM martyrs WHERE '.($private?'1=1':'published=1').' AND '.implode(' AND ',$parts).' ORDER BY id DESC LIMIT 25',$args)->fetchAll();foreach($rows as &$r)$r=['id'=>$r['id'],'label'=>$r['code'].' ـ '.$r['first_name'].' '.$r['last_name']];unset($r);}
   elseif($kind==='artists'){$private=Permissions::allows($db,$actor,'artists.view');$rows=Db::query($db,'SELECT id,display_name AS label FROM artists WHERE archived=0'.($private?'':' AND public_visible=1').' AND display_name LIKE ? ORDER BY id DESC LIMIT 25',['%'.$q.'%'])->fetchAll();}
   else throw new HttpError(422,'نوع جست‌وجو معتبر نیست.');
  }self::json(['ok'=>true,'items'=>$rows]);
 }
 public static function geography(PDO $db):never {
  $actor=Security::user($db);if(!$actor)throw new HttpError(403,'ورود لازم است.');CallEntries::batch($db,(int)($_GET['batch']??0),$actor,true);
  $level=Security::text($_GET,'level',10);$parents=['province'=>'country','county'=>'province','city'=>'county'];if(!in_array($level,['country','province','county','city'],true))throw new HttpError(422,'سطح جغرافیا معتبر نیست.');$parent=(int)($_GET['parent']??0);
  if(isset($parents[$level])&&!Db::query($db,'SELECT id FROM protocol_terms WHERE id=? AND taxonomy=?',[$parent,$parents[$level]])->fetchColumn())throw new HttpError(422,'والد جغرافیا معتبر نیست.');
  $rows=Db::query($db,'SELECT id,title,parent_id,metadata FROM protocol_terms WHERE taxonomy=? AND active=1'.(isset($parents[$level])?' AND parent_id=?':'').' ORDER BY title',isset($parents[$level])?[$level,$parent]:[$level])->fetchAll();
  if($level==='city')$rows=array_values(array_filter($rows,fn($r)=>empty(Protocol::decode($r['metadata'])['village'])));foreach($rows as &$r)unset($r['metadata']);unset($r);self::json(['ok'=>true,'items'=>$rows]);
 }
}
