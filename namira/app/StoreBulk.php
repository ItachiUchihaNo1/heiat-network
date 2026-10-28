<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class StoreBulk {
 public static function apply(PDO $db,array $actor,array $in):void {
 if(!Permissions::allows($db,$actor,'shop.manage'))throw new HttpError(403,'مدیریت محصولات مجاز نیست.');if(($in['confirm_bulk']??'')!=='1')throw new HttpError(422,'تأیید اقدام گروهی لازم است.');$ids=$in['products']??[];if(!is_array($ids)||!$ids||count($ids)>100)throw new HttpError(422,'یک تا ۱۰۰ محصول انتخاب کنید.');$ids=array_unique(array_map(fn($v)=>StoreCatalog::integer($v,1),$ids));$action=Security::text($in,'operation',30);$reason=Security::text($in,'reason',1000);
 Db::transaction($db,function()use($db,$actor,$ids,$action,$reason,$in){foreach($ids as $id){Db::query($db,'SELECT id FROM products WHERE id=?'.StoreAccess::lock($db),[$id])->fetch();$p=(new StoreCatalog($db))->get($id,true);$data=$p+$p['meta'];$data['slug']=$p['slug']?:'product-'.$id;$data['legacy_category']=$p['category'];$manager=new StoreManager($db,$actor);
 if(in_array($action,['publish','unpublish','archive'],true)){if($action==='archive')$data['archived']=1;else $data['published']=$action==='publish'?1:0;$manager->product($data,$id);}
 elseif($action==='category'){$cat=StoreCatalog::integer($in['category_id']??0,1);$data['categories_present']=1;$data['categories']=array_unique([...array_column($p['categories'],'id'),$cat]);$manager->product($data,$id);}
 elseif($action==='price'){$price=StoreCatalog::integer($in['price']??null,0,1000000000);foreach($p['variants'] as $v){$v['expected_stock']=$v['stock'];$v['price']=$price;$v['sale_price']='';$v['reason']=$reason;$manager->variant($id,$v,(int)$v['id']);}}
 else throw new HttpError(422,'نوع اقدام گروهی معتبر نیست.');StoreAccess::audit($db,$actor,'bulk.'.$action,'product',$id,[],['operation'=>$action],$reason);}});
 }
}
