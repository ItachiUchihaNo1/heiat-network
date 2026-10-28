<?php
 declare(strict_types=1);
 namespace Namira;
 use PDO;
 final class StoreCatalog {
 public const DELIVERY=['physical'=>'فیزیکی','digital'=>'دیجیتال','hybrid'=>'فیزیکی و دیجیتال'];
 public const PRODUCTION=['ready'=>'آماده','custom'=>'شخصی‌سازی دستی'];
 public const PRICING=['fixed'=>'ثابت','variable'=>'براساس گزینه‌ها','quote'=>'استعلام قیمت'];
 public function __construct(private PDO $db){}
 public static function searchText(string $text):string {return preg_replace('/[\s\x{200c}]+/u',' ',Security::normalize($text));}
 public function categories(bool $public=true):array {return Db::query($this->db,'SELECT c.*, (SELECT COUNT(*) FROM store_category_products x JOIN products p ON p.id=x.product_id LEFT JOIN store_product_details d ON d.product_id=p.id WHERE x.category_id=c.id AND p.published=1 AND COALESCE(d.archived,0)=0) AS product_count FROM store_categories c '.($public?'WHERE c.active=1 ':'').'ORDER BY c.sort_order,c.title')->fetchAll();}
 public function descendants(int $id):array {
 $all=$this->categories();$ids=[];$todo=[$id];while($todo){$p=array_shift($todo);if(in_array($p,$ids,true))continue;$ids[]=$p;foreach($all as $c)if((int)$c['parent_id']===$p)$todo[]=(int)$c['id'];}return $ids;
 }
 public function attributes(bool $public=true):array {
 $rows=Db::query($this->db,'SELECT a.id AS attribute_id,a.attribute_key,a.title AS attribute_title,a.filterable,v.* FROM store_attributes a JOIN store_attribute_values v ON v.attribute_id=a.id '.($public?'WHERE a.active=1 AND a.filterable=1 AND v.active=1 ':'').'ORDER BY a.sort_order,a.id,v.sort_order,v.title')->fetchAll();$out=[];
 foreach($rows as $r){$k=(int)$r['attribute_id'];$out[$k]??=['id'=>$k,'title'=>$r['attribute_title'],'key'=>$r['attribute_key'],'values'=>[]];$out[$k]['values'][]=$r;}return array_values($out);
 }
 public function listing(array $f,bool $staff=false):array {
 $w=[$staff?'1=1':'p.published=1 AND COALESCE(d.archived,0)=0'];$a=[];
 $q=self::searchText(Security::text($f,'q',150,false));if($q!==''){
 $expr="REPLACE(REPLACE(REPLACE(REPLACE(p.title,'ي','ی'),'ك','ک'),'‌',' '),'  ',' ')";
 foreach(explode(' ',$q) as $word){if($word==='')continue;$like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$word).'%';$w[]="($expr LIKE ? ESCAPE '!' OR REPLACE(REPLACE(p.description,'ي','ی'),'ك','ک') LIKE ? ESCAPE '!' OR d.payload LIKE ? ESCAPE '!' OR EXISTS(SELECT 1 FROM variants sq WHERE sq.product_id=p.id AND sq.sku LIKE ? ESCAPE '!') OR EXISTS(SELECT 1 FROM store_category_products scp JOIN store_categories sc ON sc.id=scp.category_id WHERE scp.product_id=p.id AND sc.title LIKE ? ESCAPE '!'))";array_push($a,$like,$like,$like,$like,$like);}}
 if(!empty($f['category'])){$ids=$this->descendants(self::integer($f['category'],1));$w[]='EXISTS(SELECT 1 FROM store_category_products cp JOIN store_categories cc ON cc.id=cp.category_id WHERE cp.product_id=p.id AND cc.active=1 AND cp.category_id IN ('.implode(',',array_fill(0,count($ids),'?')).'))';array_push($a,...$ids);}
 foreach(['delivery'=>self::DELIVERY,'production'=>self::PRODUCTION,'pricing'=>self::PRICING] as $k=>$choices)if(($f[$k]??'')!==''){if(!is_string($f[$k])||!isset($choices[$f[$k]]))throw new HttpError(422,'نوع فیلتر معتبر نیست.');$default=$k==='delivery'?'physical':($k==='production'?'ready':'fixed');$w[]="COALESCE(d.$k,'$default')=?";$a[]=$f[$k];}
 $v=['v.product_id=p.id','COALESCE(vd.active,1)=1'];$va=[];
 foreach(['min_price'=>'>=','max_price'=>'<='] as $k=>$op)if(($f[$k]??'')!==''){$v[]='COALESCE(v.sale_price,v.price)'.$op.'CAST(? AS DECIMAL(20,0))';$va[]=self::integer($f[$k],0,1000000000);}
 if(!empty($f['in_stock']))$v[]='(v.stock>0 OR vd.unlimited=1)';if(!empty($f['discounted']))$v[]='v.sale_price IS NOT NULL AND v.sale_price<v.price';
 $selected=$f['attribute']??[];if(!is_array($selected)||count($selected)>30)throw new HttpError(422,'ویژگی‌های فیلتر معتبر نیستند.');
 foreach($selected as $attribute=>$values){$attribute=self::integer($attribute,1);if(!is_array($values)||count($values)>50)throw new HttpError(422,'مقادیر ویژگی معتبر نیستند.');$ids=array_values(array_unique(array_map(fn($x)=>self::integer($x,1),$values)));if(!$ids)continue;$v[]='EXISTS(SELECT 1 FROM store_variant_values vv JOIN store_attribute_values av ON av.id=vv.value_id JOIN store_attributes aa ON aa.id=av.attribute_id WHERE vv.variant_id=v.id AND av.attribute_id=? AND aa.active=1 AND aa.filterable=1 AND av.active=1 AND av.id IN ('.implode(',',array_fill(0,count($ids),'?')).'))';array_push($va,$attribute,...$ids);}
 if(!$staff||count($v)>2){$w[]='EXISTS(SELECT 1 FROM variants v LEFT JOIN store_variant_details vd ON vd.variant_id=v.id WHERE '.implode(' AND ',$v).')';array_push($a,...$va);}
 $from=' FROM products p LEFT JOIN store_product_details d ON d.product_id=p.id WHERE '.implode(' AND ',$w);
 $count=(int)Db::query($this->db,'SELECT COUNT(*)'.$from,$a)->fetchColumn();$per=24;$page=max(1,min(max(1,(int)ceil($count/$per)),self::integer($f['p']??1,1,1000000)));
 $sorts=['new'=>'p.id DESC','price'=>'min_price ASC,p.id DESC','price_desc'=>'min_price DESC,p.id DESC','featured'=>'COALESCE(d.featured,0) DESC,COALESCE(d.sort_order,0),p.id DESC','best'=>'sold DESC,p.id DESC','relevant'=>'COALESCE(d.featured,0) DESC,p.id DESC'];$sortKey=Security::text($f,'sort',30,false)?:'new';$sort=$sorts[$sortKey]??$sorts['new'];
 $sql="SELECT p.*,d.slug,COALESCE(d.delivery,'physical') AS delivery,COALESCE(d.production,'ready') AS production,COALESCE(d.pricing,'fixed') AS pricing,COALESCE(d.archived,0) AS archived,COALESCE(d.featured,0) AS featured,(SELECT MIN(COALESCE(v.sale_price,v.price)) FROM variants v LEFT JOIN store_variant_details vd ON vd.variant_id=v.id WHERE v.product_id=p.id AND COALESCE(vd.active,1)=1) AS min_price,(SELECT MIN(v.price) FROM variants v LEFT JOIN store_variant_details vd ON vd.variant_id=v.id WHERE v.product_id=p.id AND COALESCE(vd.active,1)=1) AS original_price,(SELECT MAX(CASE WHEN v.stock>0 OR vd.unlimited=1 THEN 1 ELSE 0 END) FROM variants v LEFT JOIN store_variant_details vd ON vd.variant_id=v.id WHERE v.product_id=p.id AND COALESCE(vd.active,1)=1) AS available,(SELECT sm.id FROM store_media sm WHERE sm.product_id=p.id ORDER BY sm.sort_order,sm.id LIMIT 1) AS cover_id,(SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN payments pay ON pay.order_id=o.id WHERE oi.variant_id IN(SELECT id FROM variants WHERE product_id=p.id) AND pay.status='verified' AND o.status NOT IN('cancelled','refunded')) AS sold".$from.' ORDER BY '.$sort.' LIMIT '.$per.' OFFSET '.(($page-1)*$per);
 return ['items'=>Db::query($this->db,$sql,$a)->fetchAll(),'count'=>$count,'page'=>$page,'per'=>$per,'pages'=>(int)ceil($count/$per)];
 }
 public function get(int $id,bool $staff=false):array {
 $r=Db::query($this->db,'SELECT p.*,d.slug,d.delivery,d.production,d.pricing,d.featured,d.sort_order,d.archived,d.revision,d.payload,d.created_at,d.updated_at FROM products p LEFT JOIN store_product_details d ON d.product_id=p.id WHERE p.id=?'.($staff?'':' AND p.published=1 AND COALESCE(d.archived,0)=0'),[$id])->fetch();if(!$r)throw new HttpError(404,'محصول پیدا نشد.');
 $r['delivery']??='physical';$r['production']??='ready';$r['pricing']??='fixed';$r['revision']??=0;$r['meta']=json_decode($r['payload']??'{}',true,32,JSON_THROW_ON_ERROR);
 $r['variants']=Db::query($this->db,'SELECT v.*,COALESCE(d.active,1) AS active,COALESCE(d.unlimited,0) AS unlimited,COALESCE(d.reorder_point,0) AS reorder_point FROM variants v LEFT JOIN store_variant_details d ON d.variant_id=v.id WHERE v.product_id=?'.($staff?'':' AND COALESCE(d.active,1)=1').' ORDER BY v.id',[$id])->fetchAll();
 $r['values']=Db::query($this->db,'SELECT x.variant_id,v.id,v.attribute_id,v.title FROM store_variant_values x JOIN variants sv ON sv.id=x.variant_id JOIN store_attribute_values v ON v.id=x.value_id WHERE sv.product_id=?',[$id])->fetchAll();
 $r['media']=Db::query($this->db,'SELECT id,alt,sort_order FROM store_media WHERE product_id=? ORDER BY sort_order,id',[$id])->fetchAll();
 $r['categories']=Db::query($this->db,'SELECT c.* FROM store_category_products x JOIN store_categories c ON c.id=x.category_id WHERE x.product_id=?'.($staff?'':' AND c.active=1').' ORDER BY c.sort_order,c.title',[$id])->fetchAll();return $r;
 }
 public static function integer(mixed $v,int $min=0,int $max=2147483647):int {if(!is_scalar($v)||filter_var($v,FILTER_VALIDATE_INT)===false||(int)$v<$min||(int)$v>$max)throw new HttpError(422,'مقدار عددی معتبر نیست.');return (int)$v;}
 public static function checkoutReady(array $p):bool {return $p['delivery']==='physical'&&$p['production']==='ready'&&$p['pricing']!=='quote'&&!array_filter($p['variants']??[],fn($v)=>!empty($v['unlimited']));}
 }
