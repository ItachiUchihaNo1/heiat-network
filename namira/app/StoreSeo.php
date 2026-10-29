<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class StoreSeo {
 public static function base():string {$url=rtrim(Env::get('APP_URL'),'/');if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['https','http'],true))return '';return $url;}
 public static function tags(string $page,string $title,?array $product=null,bool $preview=false):array {$base=self::base();$private=!in_array($page,['home','gallery','profile','image','artists','artist','calls','call','shop','product','store-info','about'],true)||$preview;$desc=$product?($product['meta']['seo_description']??$product['meta']['summary']??mb_substr(strip_tags($product['description']),0,160)):'نامیرا، سامانه جامع تصاویر شهدا';$canonical=DomainRouting::absolute($page,$product?['id'=>$product['id']]:[]);$image=$product&&$product['media']?DomainRouting::absolute('store-media',['id'=>$product['media'][0]['id']]):'';return compact('private','desc','canonical','image','title');}
 public static function sitemap(PDO $db):string {$base=self::base();$urls=[$base.url('shop')];foreach(Db::query($db,'SELECT p.id FROM products p LEFT JOIN store_product_details d ON d.product_id=p.id WHERE p.published=1 AND COALESCE(d.archived,0)=0 ORDER BY p.id LIMIT 49000')->fetchAll() as $p)$urls[]=$base.url('product',['id'=>$p['id']]);foreach(Db::query($db,'SELECT id FROM store_categories WHERE active=1 LIMIT 900')->fetchAll() as $c)$urls[]=$base.url('shop',['category'=>$c['id']]);return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('',array_map(fn($u)=>'<url><loc>'.htmlspecialchars($u,ENT_XML1|ENT_QUOTES,'UTF-8').'</loc></url>',$urls)).'</urlset>';}
}
