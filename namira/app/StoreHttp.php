<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class StoreHttp {
 public static function handles(string $a):bool{return str_starts_with($a,'commerce-');}
 public static function input(PDO $db,array|false $actor,array $p,array $in):array {
 $values=$in['custom']??[];if(!is_array($values))throw new HttpError(422,'اطلاعات اختصاصی معتبر نیست.');$form=StoreForms::forProduct($db,$p);$count=0;
 foreach($form['fields'] as $f)if(in_array($f['type'],['image','file'],true)){foreach(Web::files('upload_'.$f['key']) as $file){if(++$count>StoreSettings::get($db)['upload_count'])throw new HttpError(422,'تعداد فایل زیاد است.');$values[$f['key']][] = StoreFiles::put($db,$actor,Upload::receive($file),$file['name'],'input',['product_id'=>$p['id'],'field_key'=>$f['key']]);}}
 $_POST['custom']=$values;return $values;
 }
 public static function action(PDO $db,string $a):never {
 if(!StoreOrderSchema::ready($db))throw new HttpError(503,'مدیر اصلی باید ارتقای فروشگاه را اجرا کند.');$actor=Security::user($db);$back='cart';$params=[];$id=StoreCatalog::integer($_POST['id']??0);Security::limit($db,'commerce-action',120);
 try{
 if(in_array($a,['commerce-add','commerce-quote'],true)){[$p,$v]=StoreCart::product($db,StoreCatalog::integer($_POST['variant_id']??0,1));$back='product';$params=['id'=>$p['id']];$values=self::input($db,$actor,$p,$_POST);if($a==='commerce-quote'){if(!$actor||!MobileAuth::verified($db,$actor))redirect('member-login',['next_page'=>'product','id'=>$p['id']]);$id=StoreOrders::quote($db,$actor,(int)$v['id'],StoreCatalog::integer($_POST['quantity']??1,1,99),$values);$back='store-account';$params=['section'=>'quotes'];}else{StoreCart::add($db,$actor,(int)$v['id'],StoreCatalog::integer($_POST['quantity']??1,1,99),$values,$id);$back='cart';$params=[];}}
 elseif($a==='commerce-quantity')StoreCart::quantity($db,$actor,$id,StoreCatalog::integer($_POST['quantity']??0,0,99));
 else {
 if(!$actor)throw new HttpError(403,'ورود لازم است.');
 $order=StoreCatalog::integer($_POST['order_id']??0);if($order){$back='store-order';$params=['id'=>$order];}else{$back='admin';$params=['tab'=>'store-operations'];}
 switch($a){
 case 'commerce-checkout':$back='cart';$params=[];$token=StoreCheckout::create($db,$actor,$_POST,(int)($_POST['quote_id']??0));$order=(int)Db::query($db,'SELECT id FROM orders WHERE token=?',[$token])->fetchColumn();$back='store-order';$params=['id'=>$order];break;
 case 'commerce-pay':$o=StoreOrders::get($db,$actor,$order);StoreCheckout::pay($db,$actor,$o['o']['token'],$_POST);break;
 case 'commerce-retry':StoreCheckout::retry($db,$actor,$order);break;
 case 'commerce-cancel':StoreOrders::cancelPending($db,$actor,$order,Security::text($_POST,'reason',1000));break;
 case 'commerce-transition':StoreOps::transition($db,$actor,$id,Security::text($_POST,'status',30),StoreCatalog::integer($_POST['revision']??0),Security::text($_POST,'reason',1000,false),!empty($_POST['unpaid_override']));break;
 case 'commerce-assign':StoreOps::assign($db,$actor,$id,StoreCatalog::integer($_POST['user_id']??0),Security::text($_POST,'reason',1000,false));break;
 case 'commerce-approval':StoreOps::approval($db,$actor,$id,!empty($_POST['required']),Security::text($_POST,'reason',1000));break;
 case 'commerce-proof':case 'commerce-final':$files=Web::files('file');if(count($files)!==1)throw new HttpError(422,'یک فایل انتخاب کنید.');$kind=$a==='commerce-proof'?'proof':'final';$fid=StoreFiles::put($db,$actor,Upload::receive($files[0]),$files[0]['name'],$kind,['item_id'=>$id]);if($kind==='proof')StoreOps::proof($db,$actor,$id,$fid);else StoreAccess::audit($db,$actor,'final.upload','item',$id,[],['file_id'=>$fid]);break;
 case 'commerce-feedback':StoreOps::feedback($db,$actor,$id,StoreCatalog::integer($_POST['proof_id']??0,1),($_POST['decision']??'')==='approve',Security::text($_POST,'message',5000,false));break;
 case 'commerce-message':$fid=[];if($files=Web::files('file')){if(count($files)>1||!$id)throw new HttpError(422,'برای پیوست یک قلم سفارش انتخاب کنید.');$fid[] = StoreFiles::put($db,$actor,Upload::receive($files[0]),$files[0]['name'],'message',['item_id'=>$id]);}StoreOps::message($db,$actor,$order,$id?:null,Security::text($_POST,'message',5000),Security::text($_POST,'kind',30,false)?:'message',!empty($_POST['internal']),$fid);break;
 case 'commerce-ship':StoreOps::ship($db,$actor,$order,$_POST,$_POST['quantities']??[]);break;
 case 'commerce-delivered':StoreOps::delivered($db,$actor,$id);break;
 case 'commerce-bulk':StoreBulk::apply($db,$actor,$_POST);$params=['tab'=>'products'];break;
 case 'commerce-category-media':if(!Permissions::allows($db,$actor,'shop.manage'))throw new HttpError(403,'مدیریت دسته مجاز نیست.');$files=Web::files('file');if(count($files)>1)throw new HttpError(422,'یک تصویر انتخاب کنید.');StoreCategoryMedia::save($db,$actor,$id,$_POST,$files?Upload::receive($files[0]):null);$params=['tab'=>'store-categories'];break;
 case 'commerce-notifications-process':if(!Permissions::allows($db,$actor,'shop.manage'))throw new HttpError(403,'مدیریت اعلان مجاز نیست.');StoreDelivery::dispatch($db);$params=['tab'=>'store-notifications'];break;
 case 'commerce-notification-read':Db::query($db,'UPDATE store_notifications SET read_at=? WHERE id=? AND user_id=?',[date('Y-m-d H:i:s'),$id,$actor['id']]);$back='store-account';$params=['section'=>'messages'];break;
 case 'commerce-amend':StoreAmendment::save($db,$actor,$id,$_POST);break;
 case 'commerce-schedule':StoreProduction::schedule($db,$actor,$id,$_POST);break;
 case 'commerce-production':StoreProduction::record($db,$actor,$id,$_POST);break;
 case 'commerce-materials':StoreProduction::materials($db,$actor,StoreCatalog::integer($_POST['product_id']??0,1),$_POST['materials']??[]);$back='admin';$params=['tab'=>'products','edit'=>(int)$_POST['product_id']];break;
 case 'commerce-return-review':StoreReturns::review($db,$actor,$id,$_POST);break;
 case 'commerce-stock':StoreAccess::require($db,$actor,'production');$delta=filter_var($_POST['delta']??null,FILTER_VALIDATE_INT);if($delta===false||abs($delta)>1000000||$delta===0)throw new HttpError(422,'تغییر موجودی معتبر نیست.');Db::transaction($db,fn()=>StoreCheckout::stock($db,$id,$delta,$actor,Security::text($_POST,'reason',1000)));break;
 case 'commerce-refund':StoreOps::refund($db,$actor,$order,StoreCatalog::integer($_POST['amount']??0,1),Security::text($_POST,'reason',2000));break;
 case 'commerce-offer':StoreOrders::offer($db,$actor,$id,StoreCatalog::integer($_POST['amount']??0,1),strtotime(Security::text($_POST,'expires',30))?:0,Security::text($_POST,'reason',1000,false));$params=['tab'=>'store-quotes'];break;
 case 'commerce-form':$fields=$_POST['fields']??[];if(!is_array($fields))throw new HttpError(422,'فیلدها معتبر نیستند.');foreach($fields as &$f){if(!empty($f['remove'])){$f=null;continue;}$options=[];foreach(explode("\n",Security::text($f,'options_text',15000,false)) as $line){if(trim($line)==='')continue;$bits=explode('|',trim($line));$options[]=['value'=>trim($bits[0]),'label'=>trim($bits[1]??$bits[0]),'price'=>trim($bits[2]??'0')];}$f['options']=$options;$f['condition']=!empty($f['condition_field'])?['field'=>$f['condition_field'],'equals'=>$f['condition_equals']??'']:null;$f['formats']=array_values(array_filter(array_map('trim',explode(',',Security::text($f,'formats_text',150,false)?:($f['type']==='image'?'jpg,jpeg,png,webp':'jpg,jpeg,png,webp,pdf')))));}unset($f);$_POST['fields']=array_values(array_filter($fields));StoreForms::save($db,$actor,$_POST,$id);$params=['tab'=>'store-forms'];break;
 case 'commerce-form-copy':if(!Permissions::allows($db,$actor,'shop.manage'))throw new HttpError(403,'مدیریت فرم مجاز نیست.');$f=Db::query($db,'SELECT * FROM store_forms WHERE id=?',[$id])->fetch();if(!$f)throw new HttpError(404,'فرم پیدا نشد.');StoreForms::save($db,$actor,['title'=>$f['title'].' (رونوشت)','fields'=>Protocol::decode($f['fields_json']),'active'=>1]);$params=['tab'=>'store-forms'];break;
 case 'commerce-coupon':$rules=json_decode(Security::text($_POST,'rules_json',15000,false)?:'{}',true,20,JSON_THROW_ON_ERROR);StoreFinance::coupon($db,$actor,$_POST+['rules'=>$rules]);$params=['tab'=>'store-coupons'];break;
 case 'commerce-settings':$in=$_POST;if(isset($_POST['shipping_rows'])){$in['shipping']=[];foreach($_POST['shipping_rows'] as $row){if(!empty($row['remove'])||trim($row['id']??'')==='')continue;foreach(['provinces','cities'] as $scope)$row[$scope]=array_values(array_filter(array_map('trim',explode(',',Security::text($row,$scope.'_text',10000,false)))));$in['shipping'][]=$row;}}foreach(['shipping','banners','notification_templates'] as $k)if($k!=='shipping'||!isset($in['shipping']))$in[$k]=json_decode(Security::text($_POST,$k.'_json',30000,false)?:'[]',true,30,JSON_THROW_ON_ERROR);$in['notify_sms']=!empty($_POST['notify_sms']);$in['notify_email']=!empty($_POST['notify_email']);$in['enable_sales']=!empty($_POST['enable_sales']);StoreSettings::save($db,$actor,$in);$params=['tab'=>'store-settings'];break;
 case 'commerce-catalog-file':StoreAccess::require($db,$actor,'orders');$files=Web::files('file');if(count($files)!==1)throw new HttpError(422,'یک فایل انتخاب کنید.');StoreFiles::put($db,$actor,Upload::receive($files[0]),$files[0]['name'],'catalog',['product_id'=>StoreCatalog::integer($_POST['product_id']??0,1)]);$back='admin';$params=['tab'=>'products','edit'=>(int)$_POST['product_id']];break;
 case 'commerce-address':MemberServices::requireMember($db,$actor);$id=StoreCatalog::integer($_POST['id']??0);if(!$id)MemberServices::address($db,$actor,$_POST);else{$d=['title'=>Security::text($_POST,'title',100),'recipient'=>Security::text($_POST,'recipient',150),'phone'=>Security::phone(Security::text($_POST,'phone',20)),'postal_code'=>Security::normalize(Security::text($_POST,'postal_code',10)),'address'=>Security::text($_POST,'address',2000)];if(!preg_match('/^\d{10}$/D',$d['postal_code']))throw new HttpError(422,'کدپستی معتبر نیست.');if(!Db::query($db,'SELECT id FROM account_addresses WHERE id=? AND user_id=?',[$id,$actor['id']])->fetchColumn())throw new HttpError(404,'نشانی پیدا نشد.');Db::query($db,'UPDATE account_addresses SET title=?,recipient=?,phone=?,postal_code=?,address=? WHERE id=? AND user_id=?',[...array_values($d),$id,$actor['id']]);}$back='store-account';$params=['section'=>'addresses'];break;
 default:throw new HttpError(404,'عملیات پیدا نشد.');
 }
 }
 $_SESSION['flash']='عملیات ثبت شد.';unset($_SESSION['commerce_feedback']);
 }catch(HttpError|\JsonException $e){$_SESSION['commerce_feedback']=['input'=>$_POST,'message'=>$e instanceof \JsonException?'ساختار تنظیمات JSON معتبر نیست.':$e->getMessage()];$_SESSION['flash_type']='error';$_SESSION['flash']=$_SESSION['commerce_feedback']['message'];}
 Web::uploadResponse($back,$params);
 }
 public static function page(PDO $db,string $page,array|false $actor):void {
 if(!$actor&&$page!=='store-info')redirect('member-login',['next_page'=>'account']);$old=$_SESSION['commerce_feedback']['input']??[];unset($_SESSION['commerce_feedback']);
 if($page==='store-order'){$data=StoreOrders::get($db,$actor,StoreCatalog::integer($_GET['id']??0,1));view('store-order',$data+['db'=>$db,'user'=>$actor,'old'=>$old]);}
 elseif($page==='store-account'){$section=Security::text($_GET,'section',30,false)?:'orders';$result=$section==='orders'?StoreOrders::listing($db,$actor,$_GET,true):[];view('store-account',compact('db','actor','section','result','old'));}
 elseif($page==='store-info'){$section=Security::text($_GET,'section',30,false);$settings=StoreSettings::get($db);if(!in_array($section,['sales_terms','privacy','cancellation','shipping_policy','digital_rights','contact','faq','purchase_guide'],true))throw new HttpError(404,'صفحه پیدا نشد.');echo '<section class="panel prose"><h1>'.e(['sales_terms'=>'شرایط فروش','privacy'=>'حفظ اطلاعات','cancellation'=>'لغو و مرجوعی','shipping_policy'=>'ارسال','digital_rights'=>'حقوق فایل دیجیتال','contact'=>'تماس','faq'=>'پرسش‌های متداول','purchase_guide'=>'راهنمای خرید'][$section]).'</h1><p>'.nl2br(e($settings[$section]?:'این بخش هنوز توسط مدیریت تکمیل نشده است.')).'</p></section>';}
 }
 public static function file(PDO $db,array|false $actor):never {$id=StoreCatalog::integer($_GET['id']??0,1);$preview=!empty($_GET['preview']);$f=StoreFiles::authorize($db,$id,$actor,(int)($_GET['item_id']??0),false);$preview=$preview&&$f['preview_path']!=='';if(!$preview)$f=StoreFiles::authorize($db,$id,$actor,(int)($_GET['item_id']??0),true);$p=Upload::absolute($preview&&$f['preview_path']?$f['preview_path']:$f['path']);if(!is_file($p))throw new HttpError(404,'فایل پیدا نشد.');header('Content-Type: '.($preview&&$f['preview_path']?'image/webp':$f['mime']));header('Content-Length: '.filesize($p));header('Content-Disposition: '.($preview?'inline':'attachment').'; filename="namira-file-'.$id.'.'.($preview&&$f['preview_path']?'webp':pathinfo($f['filename'],PATHINFO_EXTENSION)).'"');header('Cache-Control: private, no-store');header('X-Robots-Tag: noindex, nofollow');readfile($p);exit;}
}
