<?php
declare(strict_types=1);
namespace Namira;
final class BaleClient {
 public function __construct(private ?\Closure $transport=null){if($transport&&Env::get('APP_ENV')!=='testing')throw new HttpError(503,'انتقال آزمایشی فقط در آزمون مجاز است.');}
 public static function configured():bool {return Env::get('PAYMENT_DRIVER')==='bale'&&preg_match('/^[0-9]+:[A-Za-z0-9_-]+$/D',Env::get('BALE_BOT_TOKEN'))&&Env::get('BALE_PROVIDER_TOKEN')!==''&&!(Env::get('APP_ENV')==='production'&&str_starts_with(Env::get('BALE_PROVIDER_TOKEN'),'WALLET-TEST-'));}
 public function call(string $method,array $data=[]):mixed {
  if(!in_array($method,['getMe','getWebhookInfo','setWebhook','sendInvoice','answerPreCheckoutQuery','inquireTransaction'],true)||!self::configured())throw new HttpError(503,'تنظیمات پرداخت بله کامل نیست.');
  if($this->transport)$reply=($this->transport)($method,$data);
  else {
   if(!function_exists('curl_init'))throw new HttpError(503,'افزونه cURL لازم است.');
   $ch=curl_init('https://tapi.bale.ai/bot'.Env::get('BALE_BOT_TOKEN').'/'.$method);$body='';
   curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>$method==='answerPreCheckoutQuery'?7:15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}]);
   $ok=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);curl_close($ch);
   if($ok===false||$errno||$status!==200)throw new HttpError(503,'پاسخ معتبر از بله دریافت نشد؛ پیش از تلاش مجدد وضعیت سفارش را بررسی کنید.');
   try{$reply=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(503,'پاسخ بله معتبر نیست.');}
  }
  if(!is_array($reply)||($reply['ok']??null)!==true||!array_key_exists('result',$reply))throw new HttpError(503,'درخواست پرداخت توسط بله پذیرفته نشد.');
  return $reply['result'];
 }
 public static function marker():string{return Env::privateRoot().'/.bale-ready.json';}
 public static function secret():string{$p=Env::privateRoot().'/.bale-webhook-secret';return is_file($p)?trim((string)file_get_contents($p)):'';}
 public static function fingerprint():string{return hash('sha256',Env::get('BALE_BOT_TOKEN').'|'.Env::get('BALE_PROVIDER_TOKEN').'|'.Env::get('PUBLIC_APP_URL').'|'.self::secret());}
 public static function activation():array {
  if(!self::configured()||strlen(self::secret())!==64||!is_file(self::marker()))return [];
  $a=json_decode((string)file_get_contents(self::marker()),true);return is_array($a)&&hash_equals(self::fingerprint(),$a['fingerprint']??'')&&preg_match('/^[A-Za-z0-9_]{5,64}$/D',$a['username']??'')?$a:[];
 }
}
