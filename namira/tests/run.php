<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\{Db,Env,Archive,Upload,Xlsx,Importer,Security,HttpError,Shop,TestGateway};
$testRoot=BASE_PATH.'/storage/test-'.bin2hex(random_bytes(8)); mkdir($testRoot,0700,true);
putenv('STORAGE_PATH='.$testRoot); putenv('APP_ENV=testing'); putenv('PAYMENT_DRIVER=test');
$dsn=getenv('TEST_DB_DSN')?:'sqlite:'.$testRoot.'/test.sqlite';
if(!str_starts_with($dsn,'sqlite:')&&!str_contains($dsn,'dbname=namira_test')) throw new RuntimeException('Only an isolated namira_test database may be used.');
putenv('DB_DSN='.$dsn); putenv('DB_USER='.(getenv('TEST_DB_USER')?:'')); putenv('DB_PASSWORD='.(getenv('TEST_DB_PASSWORD')?:''));
$db=Db::connect(); $_SESSION=[]; $tests=0;
function check(bool $ok,string $message):void {global $tests;if(!$ok)throw new RuntimeException('FAIL: '.$message);$tests++;echo "PASS $message\n";}
function rejects(callable $fn,int $status,string $message):void {try{$fn();}catch(HttpError $e){check($e->status===$status,$message);return;}throw new RuntimeException('FAIL expected rejection: '.$message);}
try {
    $migrate=require BASE_PATH.'/database/migrate.php'; $migrate($db);$migrate($db);check((int)$db->query('SELECT COUNT(*) FROM migrations')->fetchColumn()===1,'migration can run twice');
    $a=new Archive($db);$id=$a->save(['code'=>'001254','first_name'=>'محمد','last_name'=>'رضایی','province'=>'تهران','summary'=>'آزمایشی','published'=>'1']);
    check($a->find($id)['code']==='001254','archive code preserves leading zeros');
    rejects(fn()=>$a->save(['code'=>'001254','first_name'=>'علی','last_name'=>'احمدی']),409,'duplicate archive code rejected');
    $file=$testRoot.'/source.jpg';$im=imagecreatetruecolor(40,60);imagefill($im,0,0,imagecolorallocate($im,23,106,91));imagejpeg($im,$file);imagedestroy($im);
    $image=Upload::image($db,$file,'001254-1.jpg',['title'=>'تصویر آزمون']);$stored=$a->image($image);
    check(hash_file('sha256',Upload::absolute($stored['original_path']))===hash_file('sha256',$file),'original image is byte-for-byte preserved');
    check(getimagesize(Upload::absolute($stored['thumb_path']))['mime']==='image/webp','WebP preview generated');
    check(Upload::image($db,$file,'001254-1.jpg')===$image,'repeated image upload is idempotent');
    rejects(fn()=>Upload::image($db,$file,'invalid.php'),422,'invalid image filename rejected');
    rejects(fn()=>Upload::image($db,$file,'999999-1.jpg'),422,'unmatched archive code rejected');
    file_put_contents($testRoot.'/bad.jpg','<?php echo 1;');rejects(fn()=>Upload::validate($testRoot.'/bad.jpg'),422,'disguised PHP upload rejected');
    file_put_contents($testRoot.'/polyglot.jpg',file_get_contents($file).'<?php echo 1;');rejects(fn()=>Upload::validate($testRoot.'/polyglot.jpg'),422,'image with PHP payload rejected');
    rejects(fn()=>Upload::absolute('../outside.jpg'),404,'storage traversal rejected');
    check($a->images(['province'=>'تهران','orientation'=>'portrait'])['count']===1,'combined image filters');check($a->images(['province'=>'اصفهان'])['count']===0,'province filter excludes other records');
    check($a->images(['q'=>'محمد رضایی'])['count']===1,'full name search across first and last name');
    $book=$testRoot.'/import.xlsx';Xlsx::write(['شهدا'=>[['code'=>'000042','first_name'=>'علی','last_name'=>'احمدی'],['code'=>'001254','first_name'=>'موجود','last_name'=>'موجود']]],$book);
    $rows=Xlsx::read($book);check($rows[0]['code']==='000042','XLSX roundtrip preserves code text');
    $r=Importer::commit($db,$rows);$r2=Importer::commit($db,$rows);check($r['inserted']===1&&$r2['inserted']===0,'repeated XLSX import creates no duplicates');
    $badbook=$testRoot.'/bad.xlsx';Xlsx::write(['x'=>[['code'=>'42','first_name'=>'علی','last_name'=>'احمدی']]],$badbook);$z=new ZipArchive();$z->open($badbook);$xml=$z->getFromName('xl/worksheets/sheet1.xml');$xml=str_replace('<c r="A2" t="inlineStr"><is><t xml:space="preserve">42</t></is></c>','<c r="A2"><v>42</v></c>',$xml);$z->addFromString('xl/worksheets/sheet1.xml',$xml);$z->close();rejects(fn()=>Xlsx::read($badbook),422,'numeric archive code rejected');
    $export=$a->export(['province'=>'تهران']);check(count($export['شهدا'])===1&&count($export['تصاویر'])===1,'filtered export contains matching martyrs and images');
    rejects(fn()=>Security::verify(['csrf'=>'bad']),419,'CSRF rejected');rejects(fn()=>Security::requireRole($db,'content'),403,'anonymous admin access rejected');
    $uid=Db::insert($db,'users',['email'=>'shop@example.test','password_hash'=>password_hash('Test-only-password',PASSWORD_DEFAULT),'role'=>'shop']);$_SESSION['user_id']=$uid;
    rejects(fn()=>Security::requireRole($db,'content'),403,'shop role cannot edit archive');check(Security::requireRole($db,'shop')['id']==$uid,'shop role can manage shop');unset($_SESSION['user_id']);
    rejects(fn()=>Security::phone('123'),422,'invalid request mobile rejected');
    $tracking=Namira\DesignRequests::create($db,['consent'=>'1','martyr_name'=>'شهید آزمون','requester'=>'کاربر','phone'=>'۰۹۱۲۱۲۳۴۵۶۷']);check(strlen($tracking)===32,'free design request persisted with random tracking code');
    $pid=Db::insert($db,'products',['title'=>'تابلو','category'=>'قاب','description'=>'آزمون']);$vid=Db::insert($db,'variants',['product_id'=>$pid,'sku'=>'TEST-1','attributes'=>'A3','price'=>250000,'stock'=>2]);
    $shop=new Shop($db);$cart=$shop->cartToken();$shop->setQuantity($cart,$vid,2);$_SESSION['checkout_key']=bin2hex(random_bytes(32));
    $input=['checkout_key'=>$_SESSION['checkout_key'],'customer'=>'کاربر آزمون','phone'=>'09121234567','address'=>'تهران، نشانی آزمایشی','postal_code'=>'1234567890'];
    $token=$shop->checkout($cart,$input);check((int)Db::query($db,'SELECT stock FROM variants WHERE id=?',[$vid])->fetchColumn()===0,'checkout reserves inventory atomically');check($shop->checkout($cart,$input)===$token,'repeated checkout is idempotent');
    rejects(fn()=>$shop->setQuantity($cart,$vid,1),422,'overselling blocked');
    $order=Db::query($db,'SELECT * FROM orders WHERE token=?',[$token])->fetch();$p=Db::query($db,'SELECT * FROM payments WHERE order_id=?',[$order['id']])->fetch();
    rejects(fn()=>$shop->pay($token,['reference'=>'wrong','result'=>'success']),422,'payment reference mismatch rejected');
    $shop->pay($token,['reference'=>$p['reference'],'result'=>'failure']);$shop->pay($token,['reference'=>$p['reference'],'result'=>'failure']);check((int)Db::query($db,'SELECT stock FROM variants WHERE id=?',[$vid])->fetchColumn()===2,'failed payment releases inventory exactly once');
    $_SESSION['checkout_key']=bin2hex(random_bytes(32));$input['checkout_key']=$_SESSION['checkout_key'];$shop->setQuantity($cart,$vid,1);$token=$shop->checkout($cart,$input);
    $o=Db::query($db,'SELECT * FROM orders WHERE token=?',[$token])->fetch();$p=Db::query($db,'SELECT * FROM payments WHERE order_id=?',[$o['id']])->fetch();
    $shop->pay($token,['reference'=>$p['reference'],'result'=>'success']);$shop->pay($token,['reference'=>$p['reference'],'result'=>'success']);check(Db::query($db,'SELECT status FROM orders WHERE id=?',[$o['id']])->fetchColumn()==='paid','successful test payment callback is idempotent');
    rejects(fn()=>$shop->transition((int)$o['id'],'delivered'),422,'illegal order transition rejected');$shop->transition((int)$o['id'],'preparing');rejects(fn()=>$shop->transition((int)$o['id'],'shipped'),422,'shipment requires tracking');$shop->transition((int)$o['id'],'shipped','TEST-POST');$shop->transition((int)$o['id'],'delivered');check(Db::query($db,'SELECT status FROM orders WHERE id=?',[$o['id']])->fetchColumn()==='delivered','manual shipping lifecycle');
    putenv('APP_ENV=production');rejects(fn()=>new TestGateway(),503,'test gateway disabled in production');
    echo "\n$tests checks passed on ".$db->getAttribute(PDO::ATTR_DRIVER_NAME).". Test storage: $testRoot\n";
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}


