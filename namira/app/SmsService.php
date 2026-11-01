<?php
declare(strict_types=1);
namespace Namira;
use PDO;
/** Central OTP/notification sender. No automatic retry after an ambiguous network response. */
final class SmsService implements SmsGateway {
 public function __construct(private PDO $db,private ?TsmsClient $client=null){
  if($client&&Env::get('APP_ENV','production')!=='testing')throw new HttpError(503,'اتصال آزمایشی در محیط واقعی مجاز نیست.');
 }
 public static function available():bool{return strlen(Env::get('OTP_SECRET'))>=32&&TsmsClient::ready();}
 public function send(string $mobile,string $code):void {$this->sendOtp($mobile,$code,'code:'.hash('sha256',$mobile.':'.$code));}
 public function sendOtp(string $mobile,string $code,string $challenge):void {
  if(!preg_match('/^[0-9]{6}$/D',$code))throw new HttpError(422,'کد تأیید معتبر نیست.');
  $template=Env::get('SMS_OTP_TEMPLATE',"نامیرا\nکد ورود: {code}\nاعتبار: ۲ دقیقه");if(substr_count($template,'{code}')!==1)throw new HttpError(503,'قالب کد تأیید معتبر نیست.');
  $message=str_replace('{code}',$code,$template);if(mb_strlen($message)>70)throw new HttpError(503,'قالب کد تأیید باید کوتاه‌تر از ۷۱ نویسه باشد.');
  if($this->once($mobile,$message,'otp:'.$challenge)===false)throw new HttpError(409,'این ارسال قبلاً پردازش شده است.');
 }
 /** Trusted server callers only; use e.g. order:125:shipped:v1. false means this event was already claimed. */
 public function notify(string $mobile,string $message,string $event):string|false {return $this->once($mobile,$message,'notice:'.$event);}
 private function quota(string $key,int $max):void {
  $now=time();$expires=(int)(floor($now/3600)*3600+3600);$key.=':'.$expires;
  $sql=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?'INSERT INTO rate_limits(bucket,hits,expires) VALUES(?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1':'INSERT INTO rate_limits(bucket,hits,expires) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET hits=hits+1';
  Db::query($this->db,$sql,[$key,$expires]);if((int)Db::query($this->db,'SELECT hits FROM rate_limits WHERE bucket=?',[$key])->fetchColumn()>$max)throw new HttpError(429,'سقف ارسال پیامک موقتاً تکمیل شده است.');
 }
 private function once(string $mobile,string $message,string $event):string|false {
  if(!$this->client&&!self::available())throw new HttpError(503,'ارسال پیامکی هنوز فعال و پیکربندی نشده است.');
  $secret=Env::get('OTP_SECRET');if(strlen($secret)<32)throw new HttpError(503,'کلید امنیت پیامک تنظیم نشده است.');$mobile=Security::phone($mobile);if(strlen($event)>240||trim($event)===''||trim($message)===''||mb_strlen($message)>1000)throw new HttpError(422,'مشخصات ارسال معتبر نیست.');
  $digest=hash_hmac('sha256',$mobile,$secret);$bucket='sms-once-'.hash_hmac('sha256',$mobile.'|'.$event,$secret);
  try{$claim=Db::transaction($this->db,function()use($bucket,$digest){
   $now=time();$suffix=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';$old=Db::query($this->db,'SELECT expires FROM rate_limits WHERE bucket=?'.$suffix,[$bucket])->fetchColumn();if($old!==false&&(int)$old>$now)return false;
   if($old!==false)Db::query($this->db,'UPDATE rate_limits SET hits=1,expires=? WHERE bucket=?',[$now+604800,$bucket]);else Db::insert($this->db,'rate_limits',['bucket'=>$bucket,'hits'=>1,'expires'=>$now+604800]);
   $this->quota('sms-total',max(1,min(1000,(int)Env::get('SMS_HOURLY_LIMIT','100'))));$this->quota('sms-mobile-'.$digest,max(1,min(100,(int)Env::get('SMS_MOBILE_HOURLY_LIMIT','10'))));return true;
  });}catch(\PDOException $e){if($e->getCode()==='23000')return false;throw new HttpError(503,'ثبت امن درخواست پیامک انجام نشد؛ دوباره تلاش کنید.');}
  if(!$claim)return false;
  try{$reply=($this->client??TsmsClient::configured())->sendMessage($mobile,$message);if(!TsmsClient::accepted($reply))throw new HttpError(503,'قالب پاسخ موفق TSMS هنوز تأیید نشده است.');}
  catch(\Throwable $error){Db::query($this->db,'UPDATE rate_limits SET hits=-1 WHERE bucket=?',[$bucket]);$kind=$error instanceof HttpError&&str_starts_with($error->getMessage(),'TSMS:')&&preg_match('/\(([1-9]|14)\)$/D',$error->getMessage(),$matched)?'provider-'.$matched[1]:'unconfirmed';error_log('Namira SMS: event='.substr($bucket,9,16).' kind='.$kind);throw new HttpError(503,'ارسال پیامک تأیید نشد؛ از تکرار خودکار جلوگیری شد.');}
  Db::query($this->db,'UPDATE rate_limits SET hits=2 WHERE bucket=?',[$bucket]);return $reply;
 }
}
