<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class StoreSeo {
 public static function base():string {$url=rtrim(Env::get('APP_URL'),'/');if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['https','http'],true))return '';return $url;}
 public static function tags(string $page,string $title,?array $product=null,bool $preview=false):array {$base=self::base();$private=!in_array($page,['home','gallery','martyrs','profile','image','artists','artist','calls','call','shop','product','store-info','about'],true)||$preview;$desc=$product?($product['meta']['seo_description']??$product['meta']['summary']??mb_substr(strip_tags($product['description']),0,160)):'نامیرا، سامانه جامع تصاویر شهدا';$canonical=DomainRouting::absolute($page,CleanUrls::canonicalParams($page,$product?['id'=>$product['id']]:$_GET));$image=$product&&$product['media']?DomainRouting::absolute('store-media',['id'=>$product['media'][0]['id']]):'';return compact('private','desc','canonical','image','title');}
 public static function sitemap(PDO $db):string {return CleanUrls::sitemap($db);}
}
