<?php
 declare(strict_types=1);
 namespace Namira;
 use PDO;
 final class StoreWeb {
 public static function handles(string $a):bool {return str_starts_with($a,'store-');}
 public static function catalog(PDO $db,array $f):array {$c=new StoreCatalog($db);return ['result'=>$c->listing($f),'categories'=>$c->categories(),'attributes'=>$c->attributes(),'f'=>$f];}
 public static function admin(PDO $db,array $user,string $tab,array $data):void {
 $catalog=new StoreCatalog($db);$editing=!empty($_GET['edit'])&&$tab==='products'?$catalog->get(StoreCatalog::integer($_GET['edit'],1),true):null;
 $feedback=$_SESSION['store_feedback']??null;unset($_SESSION['store_feedback']);view('store-admin',$data+['editing'=>$editing,'catalog'=>$catalog,'categories'=>$catalog->categories(false),'attributes'=>$catalog->attributes(false),'feedback'=>$feedback]);
 }
 public static function action(PDO $db,string $action):never {
 $actor=Permissions::require($db,'shop.manage');AdminPanel::boot($db,$actor);$manager=new StoreManager($db,$actor);$id=StoreCatalog::integer($_POST['id']??0);$tab='products';$edit=(int)($_POST['product_id']??$id);
 try {
 switch($action){
 case 'store-product':$edit=$manager->product($_POST,$id);break;
 case 'store-copy':$edit=$manager->copy($id);break;
 case 'store-variant':$edit=StoreCatalog::integer($_POST['product_id']??null,1);$manager->variant($edit,$_POST,$id);break;
 case 'store-category':$tab='store-categories';$manager->category($_POST,$id);$edit=0;break;
 case 'store-attribute':$tab='store-attributes';$manager->attribute($_POST,$id);$edit=0;break;
 case 'store-value':$tab='store-attributes';$manager->value(StoreCatalog::integer($_POST['attribute_id']??null,1),$_POST,$id);$edit=0;break;
 case 'store-media-edit':$edit=StoreCatalog::integer($_POST['product_id']??null,1);$manager->media($edit,$id,$_POST);break;
 case 'store-media':$edit=StoreCatalog::integer($_POST['product_id']??null,1);$files=Web::files('catalog_image');if(count($files)!==1)throw new HttpError(422,'یک تصویر انتخاب کنید.');Security::limit($db,'store-media',30);StoreMedia::put($db,$actor,$edit,Upload::receive($files[0]),Security::text($_POST,'alt',255,false),StoreCatalog::integer($_POST['sort_order']??0));break;
 default:throw new HttpError(404,'عملیات فروشگاه پیدا نشد.');
 }
 $_SESSION['flash']='تغییرات فروشگاه ذخیره شد.';
 }catch(HttpError $e){$_SESSION['store_feedback']=['action'=>$action,'input'=>$_POST,'message'=>$e->getMessage()];$_SESSION['flash_type']='error';$_SESSION['flash']=$e->getMessage();}
 redirect('admin',['tab'=>$tab]+($edit?['edit'=>$edit]:[]));
 }
 }
