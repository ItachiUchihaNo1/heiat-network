<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class BalePayments {
 public function __construct(private PDO $db,private BaleClient $client){}
 public static function available():bool{return (bool)BaleClient::activation();}
 public static function setup(PDO $db,array $actor,bool $replace=false,?BaleClient $client=null):void {
  if(!Permissions::allows($db,$actor,'settings.manage'))throw new HttpError(403,'تنظیم اتصال مجاز نیست.');
  if(!BaleClient::configured())throw new HttpError(422,'ابتدا دو توکن را در فایل خصوصی تنظیم کنید.');BalePaymentSchema::migrate($db);$client??=new BaleClient();
  if(strlen(BaleClient::secret())!==64){$p=Env::privateRoot().'/.bale-webhook-secret';$h=fopen($p,'x');if(!$h)throw new HttpError(503,'ایجاد کلید خصوصی ممکن نشد.');fwrite($h,bin2hex(random_bytes(32)));fclose($h);chmod($p,0600);}
  $me=$client->call('getMe');if(!is_array($me)||($me['is_bot']??false)!==true||!preg_match('/^[A-Za-z0-9_]{5,64}$/D',$me['username']??''))throw new HttpError(422,'توکن بازو معتبر نیست.');
  $url=DomainRouting::origin('PUBLIC_APP_URL').'/index.php?page=bale-webhook&key='.BaleClient::secret();if(!str_starts_with($url,'https://'))throw new HttpError(422,'دامنه امن عمومی تنظیم نشده است.');
  $info=$client->call('getWebhookInfo');if(!is_array($info))throw new HttpError(503,'وضعیت اتصال بله مشخص نیست.');if(!empty($info['url'])&&$info['url']!==$url&&!$replace)throw new HttpError(409,'این بازو به سامانه دیگری متصل است؛ برای جایگزینی، گزینه تأیید تغییر وب‌هوک را انتخاب کنید.');
  if($client->call('setWebhook',['url'=>$url])!==true)throw new HttpError(503,'ثبت وب‌هوک بله تأیید نشد.');$info=$client->call('getWebhookInfo');if(($info['url']??'')!==$url)throw new HttpError(503,'نشانی اتصال بله تأیید نشد.');
  $p=BaleClient::marker();$tmp=$p.'.'.bin2hex(random_bytes(8));file_put_contents($tmp,Protocol::encode(['username'=>$me['username'],'fingerprint'=>BaleClient::fingerprint(),'at'=>date(DATE_ATOM)]),LOCK_EX);chmod($tmp,0600);rename($tmp,$p);Db::audit($db,'bale.setup','connected');
 }
 public static function assertRetrySafe(PDO $db,int $order):void {
  if(!BalePaymentSchema::ready($db))return;
  if(Db::query($db,"SELECT b.attempt_id FROM bale_payments b JOIN store_payment_attempts a ON a.id=b.attempt_id WHERE a.order_id=? AND b.transaction_id IS NOT NULL AND b.state IN ('prechecked','review')",[$order])->fetchColumn())throw new HttpError(409,'پرداخت بله در حال بررسی است؛ ابتدا بررسی وضعیت پرداخت را بزنید یا با پشتیبانی تماس بگیرید.');
 }
 private function row(string $reference,bool $lock=false):array|false {
  return Db::query($this->db,'SELECT a.*,b.state,b.payer_id,b.transaction_id,b.receipt,b.message_id FROM store_payment_attempts a JOIN bale_payments b ON b.attempt_id=a.id WHERE a.driver=? AND a.reference=?'.($lock?StoreAccess::lock($this->db):''),['bale',$reference])->fetch();
 }
 private function order(array $a,bool $lock=false):array {return Db::query($this->db,'SELECT o.*,d.user_id,d.expires_at,d.reservation_state FROM orders o JOIN store_order_details d ON d.order_id=o.id WHERE o.id=?'.($lock?StoreAccess::lock($this->db):''),[$a['order_id']])->fetch();}
 private function payable(array $a,array $o):bool{return $a['status']==='pending'&&$o['status']==='pending'&&$o['reservation_state']==='reserved'&&(int)$a['amount']===(int)$o['total']&&(int)$o['expires_at']>=time();}
 public function begin(array $actor,int $order):string {
  if(!self::available()||!BalePaymentSchema::ready($this->db))throw new HttpError(503,'پرداخت بله هنوز فعال نشده است.');
  return Db::transaction($this->db,function()use($actor,$order){$o=Db::query($this->db,'SELECT * FROM orders WHERE id=?'.StoreAccess::lock($this->db),[$order])->fetch();if(!$o||!StoreAccess::owner($this->db,$order,$actor))throw new HttpError(404,'سفارش پیدا نشد.');
   $a=Db::query($this->db,"SELECT * FROM store_payment_attempts WHERE order_id=? AND driver='bale' ORDER BY id DESC LIMIT 1",[$order])->fetch();if(!$a)throw new HttpError(422,'تلاش پرداخت بله پیدا نشد.');$o=$this->order($a);
   if(!$this->payable($a,$o))throw new HttpError(409,'سفارش قابل پرداخت نیست؛ وضعیت یا رزرو را بررسی کنید.');
   if(!Db::query($this->db,'SELECT attempt_id FROM bale_payments WHERE attempt_id=?',[$a['id']])->fetchColumn())Db::insert($this->db,'bale_payments',['attempt_id'=>$a['id'],'state'=>'linked','updated_at'=>date(DATE_ATOM)]);
   return 'https://ble.ir/'.BaleClient::activation()['username'].'?start=pay_'.$a['reference'];
  });
 }
 public function handle(array $update):void {
  if(isset($update['pre_checkout_query'])&&is_array($update['pre_checkout_query'])){$this->precheck($update['pre_checkout_query']);return;}
  $m=$update['message']??null;if(!is_array($m)||($m['chat']['type']??'')!=='private')return;
  $payer=(string)($m['from']['id']??'');if(!preg_match('/^[1-9][0-9]{0,19}$/D',$payer)||(string)($m['chat']['id']??'')!==$payer)return;
  if(isset($m['successful_payment'])&&is_array($m['successful_payment'])){$this->success($m['successful_payment'],$payer);return;}
  if(preg_match('/^\/start(?:@[A-Za-z0-9_]+)? pay_([a-f0-9]{48})$/D',$m['text']??'',$match))$this->invoice($match[1],$payer);
 }
 public function invoice(string $reference,string $payer):void {
  $a=Db::transaction($this->db,function()use($reference,$payer){$a=$this->row($reference);if(!$a)return false;$o=$this->order($a,true);$a=$this->row($reference,true);if(!$this->payable($a,$o)||$a['state']!=='linked')return false;
   Db::query($this->db,"UPDATE bale_payments SET state='invoice_sending',payer_id=?,updated_at=? WHERE attempt_id=?",[$payer,date(DATE_ATOM),$a['id']]);return $a;});if(!$a)return;
  // No automatic retry: a timeout may still have created an invoice at the provider.
  try{$r=$this->client->call('sendInvoice',['chat_id'=>$payer,'title'=>'سفارش نامیرا #'.$a['order_id'],'description'=>'پرداخت سفارش در سامانه نامیرا','payload'=>$a['reference'],'provider_token'=>Env::get('BALE_PROVIDER_TOKEN'),'prices'=>[['label'=>'مبلغ سفارش','amount'=>(int)$a['amount']*10]]]);
   if(!is_array($r)||!isset($r['message_id']))throw new HttpError(503,'پاسخ صورتحساب معتبر نیست.');Db::query($this->db,"UPDATE bale_payments SET state='invoice_sent',message_id=?,updated_at=? WHERE attempt_id=? AND state='invoice_sending'",[(string)$r['message_id'],date(DATE_ATOM),$a['id']]);
  }catch(\Throwable $e){Db::query($this->db,"UPDATE bale_payments SET state='invoice_unknown',updated_at=? WHERE attempt_id=? AND state='invoice_sending'",[date(DATE_ATOM),$a['id']]);throw $e;}
 }
 public function precheck(array $q):void {
  $id=$q['id']??'';if(!is_string($id)||$id===''||strlen($id)>150)return;$ref=$q['invoice_payload']??'';if(!is_string($ref))return;
  $ok=Db::transaction($this->db,function()use($q,$ref,$id){$a=$this->row($ref);if(!$a)return false;$o=$this->order($a,true);$a=$this->row($ref,true);
   if(!$this->payable($a,$o)||!in_array($a['state'],['invoice_sent','invoice_sending','invoice_unknown','prechecked'],true)||($q['currency']??'')!=='IRR'||!is_int($q['total_amount']??null)||$q['total_amount']!==(int)$a['amount']*10||(string)($q['from']['id']??'')!==$a['payer_id']||($a['transaction_id']!==null&&$a['transaction_id']!==$id))return false;
   if($a['transaction_id']===null){Db::query($this->db,"UPDATE bale_payments SET transaction_id=?,state='prechecked',updated_at=? WHERE attempt_id=?",[$id,date(DATE_ATOM),$a['id']]);Db::query($this->db,'UPDATE store_order_details SET expires_at=? WHERE order_id=?',[max((int)$o['expires_at'],time()+120),$o['id']]);}return true;});
  $args=['pre_checkout_query_id'=>$id,'ok'=>$ok];if(!$ok)$args['error_message']='سفارش، مبلغ یا مهلت پرداخت معتبر نیست؛ به نامیرا بازگردید.';$this->client->call('answerPreCheckoutQuery',$args);
 }
 public function success(array $p,string $payer):void {
  $ref=$p['invoice_payload']??'';if(!is_string($ref))return;$a=$this->row($ref);if(!$a||$a['state']==='verified')return;
  if(($p['currency']??'')!=='IRR'||!is_int($p['total_amount']??null)||$p['total_amount']!==(int)$a['amount']*10||$a['payer_id']!==$payer||!is_string($p['telegram_payment_charge_id']??null)||$a['transaction_id']!==$p['telegram_payment_charge_id'])throw new HttpError(422,'نتیجه پرداخت با صورتحساب تطابق ندارد.');
  $receipt=$p['provider_payment_charge_id']??'';if(is_string($receipt)&&strlen($receipt)<=150)Db::query($this->db,'UPDATE bale_payments SET receipt=? WHERE attempt_id=?',[$receipt,$a['id']]);$this->reconcile($ref);if(($this->row($ref)['state']??'')==='prechecked')throw new HttpError(503,'تأیید نهایی پرداخت هنوز دریافت نشده است.');
 }
 public function check(array $actor,int $order):void {
  if(!StoreAccess::owner($this->db,$order,$actor))throw new HttpError(404,'سفارش پیدا نشد.');Security::limit($this->db,'bale-check-'.$order,8,60);
  foreach(Db::query($this->db,"SELECT a.reference FROM store_payment_attempts a JOIN bale_payments b ON b.attempt_id=a.id WHERE a.order_id=? AND b.transaction_id IS NOT NULL AND b.state IN ('prechecked','review')",[$order])->fetchAll(PDO::FETCH_COLUMN) as $ref)$this->reconcile($ref);
 }
 public function reconcile(string $ref):void {
  $a=$this->row($ref);if(!$a||!$a['transaction_id']||!in_array($a['state'],['prechecked','review'],true))return;
  $r=$this->client->call('inquireTransaction',['transaction_id'=>$a['transaction_id']]);
  if(!is_array($r)||($r['id']??null)!==$a['transaction_id']||!is_int($r['amount']??null)||$r['amount']!==(int)$a['amount']*10||(string)($r['userID']??'')!==$a['payer_id'])throw new HttpError(422,'استعلام پرداخت با سفارش تطابق ندارد.');
  if(($r['status']??'')==='pending')return;if(!in_array($r['status']??'',['paid','failed','rejected'],true))throw new HttpError(503,'وضعیت استعلام پرداخت مشخص نیست.');
  Db::transaction($this->db,function()use($ref,$r){$a=$this->row($ref);$o=$this->order($a,true);$a=$this->row($ref,true);if($a['state']==='verified')return;$actor=Db::query($this->db,'SELECT id,role,email FROM users WHERE id=?',[$o['user_id']])->fetch();
   if($r['status']==='paid'&&!$this->payable($a,$o)){$first=$a['state']!=='review';Db::query($this->db,"UPDATE bale_payments SET state='review',updated_at=? WHERE attempt_id=?",[date(DATE_ATOM),$a['id']]);if($first){StoreOps::notify($this->db,$actor['id'],$o['id'],'bale-review-'.$a['id'],'وجه دریافت شده اما سفارش نیازمند بررسی مالی است؛ با پشتیبانی تماس بگیرید.');StoreAccess::audit($this->db,$actor,'payment.late_review','order',(int)$o['id'],[],['attempt'=>$a['id']]);}return;}
   if($a['status']==='pending'&&$o['status']==='pending')StoreCheckout::finalize($this->db,$o,$a,$r['status']==='paid',$actor);
   Db::query($this->db,'UPDATE bale_payments SET state=?,updated_at=? WHERE attempt_id=?',[$r['status']==='paid'?'verified':'failed',date(DATE_ATOM),$a['id']]);
  });
 }
 public static function webhook():void {
  header('Cache-Control: no-store');header('Content-Type: application/json');$secret=BaleClient::secret();$key=$_GET['key']??null;
  if(DomainRouting::surface()==='admin'||$_SERVER['REQUEST_METHOD']!=='POST'||strlen($secret)!==64||!is_string($key)||!hash_equals($secret,$key)||!self::available()){http_response_code(404);echo '{"ok":false}';return;}
  try{$raw=file_get_contents('php://input',false,null,0,131073);if(strlen($raw)>131072)throw new HttpError(413,'درخواست بزرگ است.');$u=json_decode($raw,true,24,JSON_THROW_ON_ERROR);if(!is_array($u)||!is_int($u['update_id']??null))throw new HttpError(422,'آپدیت معتبر نیست.');$db=Db::connect();if(!BalePaymentSchema::ready($db))throw new HttpError(503,'ساختار پرداخت آماده نیست.');(new self($db,new BaleClient()))->handle($u);echo '{"ok":true}';}
  catch(\Throwable $e){http_response_code($e instanceof HttpError&&$e->status<500?200:503);error_log('Bale webhook failed: '.($e instanceof HttpError?$e->status:'internal'));echo '{"ok":false}';}
 }
}
