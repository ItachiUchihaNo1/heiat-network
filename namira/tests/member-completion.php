<?php
declare(strict_types=1);
require __DIR__.'/members.php';
use Namira\{AccountUi,Db,MobileAuth,HttpError};
putenv('APP_ENV=testing');$completionChecks=0;
function completionOk(bool $value,string $name):void{global $completionChecks;if(!$value)throw new RuntimeException('FAIL '.$name);$completionChecks++;echo "PASS $name\n";}
$phone='09124449999';$historic=Db::insert($db,'users',['email'=>'contact-collision@example.test','password_hash'=>password_hash('previous-owner-password',PASSWORD_DEFAULT),'role'=>'superadmin','active'=>1]);
Db::insert($db,'account_profiles',['user_id'=>$historic,'display_name'=>'حساب قدیمی محفوظ','phone'=>$phone,'created_at'=>date(DATE_ATOM),'updated_at'=>date(DATE_ATOM)]);
$beforeUser=Db::query($db,'SELECT * FROM users WHERE id=?',[$historic])->fetch();$beforeProfile=Db::query($db,'SELECT * FROM account_profiles WHERE user_id=?',[$historic])->fetch();
Db::query($db,'DELETE FROM rate_limits');$gateway=new CaptureSms();$challenge=MobileAuth::request($db,$phone,'login',null,$gateway);$proof=MobileAuth::verify($db,$challenge,$gateway->code);
AccountUi::migrate($db);$input=['first_name'=>'محمد جواد','last_name'=>'آزمون','new_password'=>'','password_confirm'=>'','province_id'=>'','county_id'=>''];
$new=Db::transaction($db,function()use($db,$proof,$input){$id=MobileAuth::finish($db,$proof,$input);AccountUi::password($db,$id,$input);AccountUi::saveLocation($db,$id,$input);return $id;});
completionOk($new!==$historic,'verified contact collision completes as a separate member');
completionOk(MobileAuth::verified($db,['id'=>$new]),'new identity has verified mobile');
completionOk(Db::query($db,'SELECT role FROM users WHERE id=?',[$new])->fetchColumn()==='member','historical staff role is never inherited');
completionOk(Db::query($db,'SELECT * FROM users WHERE id=?',[$historic])->fetch()===$beforeUser,'historical password role and active flag preserved');
completionOk(Db::query($db,'SELECT * FROM account_profiles WHERE user_id=?',[$historic])->fetch()===$beforeProfile,'historical profile preserved byte for byte');
completionOk(!MobileAuth::identity($db,$historic),'old staff contact is not silently verified');
completionOk((int)MobileAuth::finish($db,$proof,[])===$new,'repeat completion resolves to same verified member');
completionOk(MobileAuth::identity($db,$new)['first_name']==='محمد جواد','completion names roundtrip');
completionOk((int)Db::query($db,'SELECT COUNT(*) FROM account_identities WHERE mobile=?',[$phone])->fetchColumn()===1,'only one verified identity owns the phone');
$validPassword=['new_password'=>'482619','password_confirm'=>'482619'];AccountUi::password($db,$new,$validPassword);
completionOk(AccountUi::authenticate($db,['mobile'=>$phone,'password'=>'482619'])===$new,'permanent mobile login works after completion');
AccountUi::password($db,$new,['new_password'=>'۴۸۲۶۱۹','password_confirm'=>'۴۸۲۶۱۹']);completionOk(AccountUi::authenticate($db,['mobile'=>$phone,'password'=>'۴۸۲۶۱۹'])===$new,'Persian password is preserved and authenticates');
foreach(['12345','abcde',"12345\0"] as $bad){try{AccountUi::password($db,$new,['new_password'=>$bad,'password_confirm'=>$bad]);throw new RuntimeException('invalid PIN accepted');}catch(HttpError $e){completionOk($e->status===422,'password shorter than six or containing NUL is rejected');}}
Db::query($db,'UPDATE users SET password_hash=? WHERE id=?',[password_hash('previous-long-password',PASSWORD_DEFAULT),$new]);completionOk(AccountUi::authenticate($db,['mobile'=>$phone,'password'=>'previous-long-password'])===$new,'existing longer password remains usable');
$_SESSION['otp_proof']=$proof+['verified_until'=>time()+120];$_GET=['legacy'=>'1'];ob_start();Namira\MemberWeb::login($db);$html=ob_get_clean();completionOk(!str_contains($html,'auth-legacy')&&!str_contains($html,'۱۲ نویسه')&&str_contains($html,'minlength="6" maxlength="1024"'),'completion UI removes old login and allows longer passwords');
foreach(['abcdef','1234567','letters-and-digits-123'] as $password){AccountUi::password($db,$new,['new_password'=>$password,'password_confirm'=>$password]);completionOk(AccountUi::authenticate($db,['mobile'=>$phone,'password'=>$password])===$new,'password with six or more characters accepted');}
$count=(int)Db::query($db,'SELECT COUNT(*) FROM users')->fetchColumn();
try{Db::transaction($db,function()use($db){$id=MobileAuth::finish($db,['mobile'=>'09124448888','purpose'=>'login'],['first_name'=>'ثبت','last_name'=>'نام']);AccountUi::password($db,$id,['new_password'=>'short','password_confirm'=>'short']);});throw new RuntimeException('invalid password accepted');}catch(HttpError $e){completionOk($e->status===422,'invalid completion password rejected');}
completionOk((int)Db::query($db,'SELECT COUNT(*) FROM users')->fetchColumn()===$count,'failed completion leaves no partial account');
echo "$completionChecks member completion checks passed on ".$db->getAttribute(PDO::ATTR_DRIVER_NAME).".\n";
