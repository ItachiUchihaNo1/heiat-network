<?php
declare(strict_types=1);
namespace Namira;
final class ShortLinks {
 public const PREFIXES=['profile'=>'m','image'=>'i'];
 public static function url(string $page,int $id):string {
  if(!isset(self::PREFIXES[$page])||$id<1)throw new \InvalidArgumentException('Unsupported short link');
  if(!CleanUrls::enabled())return DomainRouting::absolute($page,['id'=>$id]);
  $origin=DomainRouting::enabled()?DomainRouting::origin('PUBLIC_APP_URL'):rtrim(Env::get('APP_URL'),'/');
  return $origin.'/'.self::PREFIXES[$page].'/'.$id;
 }
}
