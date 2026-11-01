<?php
declare(strict_types=1);
namespace Namira;
/** GET fields and error codes are copied from the supplied TSMS Python sender. No receive/credit APIs. */
final class TsmsClient {
 public const ORIGINAL_URL='http://tsms.ir/url/tsmshttp.php';
 public const SEND_URL='https://tsms.ir/url/tsmshttp.php';
 private const ERRORS=['1'=>'خطای سرور','2'=>'پیام چندقسمتی مجاز نیست','3'=>'شماره مقصد نامعتبر','4'=>'پارامتر نامعتبر','5'=>'متن خالی','6'=>'مقصد خالی','7'=>'اطلاعات ورود نامعتبر','8'=>'ترافیک زیاد','9'=>'سرویس قطع است','14'=>'مهلت ارتباط پایان یافت'];
 public function __construct(private string $username,private string $password,private string $sender,private string $url=self::ORIGINAL_URL,private ?\Closure $transport=null){
  if($transport&&Env::get('APP_ENV','production')!=='testing')throw new HttpError(503,'درایور آزمایشی پیامک در این محیط مجاز نیست.');
  if(!in_array($url,[self::ORIGINAL_URL,str_replace('http:','https:',self::ORIGINAL_URL)],true))throw new HttpError(503,'نشانی TSMS باید همان نشانی نمونه یا نسخه HTTPS تأییدشده آن باشد.');
 }
 public static function ready():bool {
  return Env::get('SMS_DRIVER')==='tsms'&&Env::get('SMS_ENABLED')==='1'&&Env::get('TSMS_USERNAME')!==''&&Env::get('TSMS_PASSWORD')!==''&&preg_match('/^\+?[0-9]{3,20}$/D',Env::get('TSMS_SENDER'))&&function_exists('curl_init');
 }
 public static function configured():self {return new self(Env::get('TSMS_USERNAME'),Env::get('TSMS_PASSWORD'),Env::get('TSMS_SENDER'),self::SEND_URL);}
 public function sendMessage(string $mobile,string $message):string {
  $mobile=Security::phone($mobile);if($this->username===''||$this->password===''||!preg_match('/^\+?[0-9]{3,20}$/D',$this->sender))throw new HttpError(503,'تنظیمات TSMS کامل نیست.');
  if(trim($message)===''||mb_strlen($message)>1000)throw new HttpError(422,'متن پیامک باید بین ۱ تا ۱۰۰۰ نویسه باشد.');
  $params=['from'=>$this->sender,'to'=>$mobile,'username'=>$this->username,'password'=>$this->password,'message'=>$message];
  try{
   if($this->transport)$reply=($this->transport)($this->url,$params);
   else{
    if(parse_url($this->url,PHP_URL_SCHEME)!=='https'||!function_exists('curl_init'))throw new HttpError(503,'ارسال واقعی فقط با HTTPS تأییدشده و cURL مجاز است.');
    $ch=curl_init($this->url.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986));$body='';
    curl_setopt_array($ch,[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'Namira-TSMS/1.0',CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>65536)return 0;$body.=$chunk;return strlen($chunk);}]);
    try{$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);if($ok===false)throw new HttpError(503,'پاسخ ارسال TSMS نامشخص است؛ ارسال خودکار تکرار نمی‌شود.');$reply=['status'=>$status,'body'=>$body];}finally{curl_close($ch);}
   }
  }catch(\Throwable){throw new HttpError(503,'ارتباط TSMS تأیید نشد؛ ارسال خودکار تکرار نمی‌شود.');}
  if(!is_array($reply)||($reply['status']??0)<200||($reply['status']??0)>=300||!is_string($reply['body']??null)||strlen($reply['body'])>65536)throw new HttpError(503,'پاسخ HTTP سرویس TSMS معتبر نیست.');
  $text=trim($reply['body']);if($text===''||!mb_check_encoding($text,'UTF-8'))throw new HttpError(503,'پاسخ TSMS خالی یا نامعتبر است.');
  if(isset(self::ERRORS[$text]))throw new HttpError(503,'TSMS: '.self::ERRORS[$text].' ('.$text.')');
  return $text;
 }
 /** Numeric receipt IDs are accepted; error and unknown responses fail closed. */
 public static function accepted(string $reply):bool {
  if(isset(self::ERRORS[$reply]))return false;
  if(Env::get('TSMS_RESPONSE_MODE')==='exact')return Env::get('TSMS_SUCCESS_VALUE')!==''&&hash_equals(Env::get('TSMS_SUCCESS_VALUE'),$reply);
  return (Env::get('TSMS_RESPONSE_MODE')?:'numeric_ids')==='numeric_ids'&&preg_match('/^[1-9][0-9]{1,19}(?:,[1-9][0-9]{1,19})*$/D',$reply)===1&&!array_filter(explode(',',$reply),fn($id)=>(float)$id<=14);
 }
}
