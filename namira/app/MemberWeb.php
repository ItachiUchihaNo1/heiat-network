<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class MemberWeb {
    public static function handles(string $a):bool{return in_array($a,['mobile-request','mobile-verify','mobile-complete','member-profile','member-address','request-message'],true);}
    public static function action(PDO $db,string $a):never {
        if(!MemberSchema::ready($db))throw new HttpError(503,'ارتقای حساب‌ها هنوز اجرا نشده است.');$actor=Security::user($db);$back='account';$params=[];
        try{
            if(str_starts_with($a,'mobile-')){
                $back='member-login';if($a==='mobile-request'){[$next,$nextParams]=MemberServices::route($_POST);$_SESSION['member_next']=[$next,$nextParams];}
                if($a==='mobile-request'){$purpose=$actor?'change':'login';$id=MobileAuth::request($db,Security::text($_POST,'mobile',20),$purpose,$actor?:null);$_SESSION['otp_challenge']=$id;redirect('member-login');}
                if($a==='mobile-verify'){$proof=MobileAuth::verify($db,(string)($_SESSION['otp_challenge']??''),Security::normalize(Security::text($_POST,'code',6)));$_SESSION['otp_proof']=$proof+['verified_until'=>time()+120];unset($_SESSION['otp_challenge']);}
                $proof=$_SESSION['otp_proof']??null;if(!$proof||($proof['verified_until']??0)<time())throw new HttpError(422,'تأیید موبایل منقضی شده است.');
                $known=Db::query($db,'SELECT user_id FROM account_identities WHERE mobile=?',[$proof['mobile']])->fetchColumn();
                if(!$known&&!$actor&&$a==='mobile-verify'){redirect('member-login');}
                $id=MobileAuth::finish($db,$proof,$_POST,$actor?:null);unset($_SESSION['otp_proof']);session_regenerate_id(true);$_SESSION['user_id']=$id;$_SESSION['csrf']=bin2hex(random_bytes(32));[$page,$p]=$_SESSION['member_next']??['account',[]];unset($_SESSION['member_next']);redirect($page,$p);
            }
            if(!$actor)throw new HttpError(403,'ابتدا وارد حساب شوید.');
            if($a==='member-profile'){$params=['section'=>'profile'];$first=Security::text($_POST,'first_name',150);$last=Security::text($_POST,'last_name',150);$now=date(DATE_ATOM);Db::transaction($db,function()use($db,$actor,$first,$last,$now){if(MobileAuth::identity($db,(int)$actor['id']))Db::query($db,'UPDATE account_identities SET first_name=?,last_name=?,revision=revision+1,updated_at=? WHERE user_id=?',[$first,$last,$now,$actor['id']]);else Db::insert($db,'account_identities',['user_id'=>$actor['id'],'first_name'=>$first,'last_name'=>$last,'created_at'=>$now,'updated_at'=>$now]);if(Db::query($db,'SELECT user_id FROM account_profiles WHERE user_id=?',[$actor['id']])->fetchColumn())Db::query($db,'UPDATE account_profiles SET display_name=?,updated_at=? WHERE user_id=?',[$first.' '.$last,$now,$actor['id']]);else Db::insert($db,'account_profiles',['user_id'=>$actor['id'],'display_name'=>$first.' '.$last,'phone'=>'','created_at'=>$now,'updated_at'=>$now]);});}
            if($a==='member-address'){$params=['section'=>'addresses'];MemberServices::address($db,$actor,$_POST);}
            if($a==='request-message'){Security::limit($db,'design-message',20,3600);$id=(int)($_POST['request_id']??0);$params=['section'=>'requests','id'=>$id];if(Permissions::allows($db,$actor,'requests.edit')){$back='admin';$params=['tab'=>'requests','edit'=>$id];}MemberServices::message($db,$actor,$id,$_POST,Web::files('photo'));}
            $_SESSION['flash']='اطلاعات ذخیره شد.';redirect($back,$params);
        }catch(HttpError|ProtocolErrors $e){$_SESSION['flash']=$e->getMessage();$_SESSION['flash_type']='error';$safe=$_POST;unset($safe['csrf'],$safe['code'],$safe['mobile']);$_SESSION['member_old']=$safe;redirect($back,$params);}
    }
    public static function login(PDO $db):void {view('mobile-login',['db'=>$db,'actor'=>Security::user($db),'proof'=>$_SESSION['otp_proof']??null,'challenge'=>$_SESSION['otp_challenge']??null]);}
    public static function account(PDO $db,array $user):void {
        $section=Security::text($_GET,'section',30,false)?:'overview';$id=(int)($_GET['id']??0);$sections=['overview'=>'نمای حساب','profile'=>'اطلاعات حساب','requests'=>'درخواست‌های طراحی','contributions'=>'مشارکت‌های من','submissions'=>'آثار فراخوان','cart'=>'سبد خرید','orders'=>'سفارش‌ها','addresses'=>'آدرس‌ها','messages'=>'پیام‌ها'];
        if(!isset($sections[$section]))throw new HttpError(404,'بخش حساب پیدا نشد.');
        if($section==='cart')redirect('cart');$data=['db'=>$db,'user'=>$user,'section'=>$section,'sections'=>$sections,'identity'=>MobileAuth::identity($db,(int)$user['id']),'profile'=>Db::query($db,'SELECT * FROM account_profiles WHERE user_id=?',[$user['id']])->fetch(),'entry'=>null,'items'=>[],'old'=>$_SESSION['member_old']??[]];unset($_SESSION['member_old']);
        if(in_array($section,['contributions','submissions'],true)){$service=$section==='contributions'?new Contributions($db,$user):new Submissions($db,$user);$data['result']=$service->listing($_GET,true);if($id){$entry=$service->get($id);if(!$entry||(int)$entry['user_id']!==(int)$user['id'])throw new HttpError(404,'مورد پیدا نشد.');$data['entry']=$entry;}}
        if($section==='requests'){$data['items']=Db::query($db,'SELECT r.* FROM design_requests r JOIN account_request_owners x ON x.request_id=r.id WHERE x.user_id=? ORDER BY r.id DESC LIMIT 100',[$user['id']])->fetchAll();if($id){if(!Db::query($db,'SELECT request_id FROM account_request_owners WHERE request_id=? AND user_id=?',[$id,$user['id']])->fetchColumn())throw new HttpError(404,'درخواست شما پیدا نشد.');$data['entry']=MemberServices::request($db,$id,$user);$data['messages']=Db::query($db,'SELECT * FROM design_request_messages WHERE request_id=? ORDER BY id',[$id])->fetchAll();$data['files']=Db::query($db,'SELECT id FROM request_files WHERE request_id=?',[$id])->fetchAll();}}
        if($section==='orders'){$data['items']=Db::query($db,'SELECT o.* FROM orders o JOIN account_order_owners x ON x.order_id=o.id WHERE x.user_id=? ORDER BY o.id DESC LIMIT 100',[$user['id']])->fetchAll();}
        if($section==='addresses')$data['items']=Db::query($db,'SELECT * FROM account_addresses WHERE user_id=? ORDER BY id DESC',[$user['id']])->fetchAll();
        if(in_array($section,['overview','messages'],true)){$data['activity']=Db::query($db,"SELECT h.entity_type,h.entity_id,h.to_status,h.created_at FROM submission_history h WHERE EXISTS(SELECT 1 FROM public_contributions c WHERE h.entity_type='contribution' AND c.id=h.entity_id AND c.user_id=?) OR EXISTS(SELECT 1 FROM art_submissions s WHERE h.entity_type='art_submission' AND s.id=h.entity_id AND s.user_id=?) ORDER BY h.id DESC LIMIT 20",[$user['id'],$user['id']])->fetchAll();$data['requestMessages']=Db::query($db,'SELECT m.request_id,m.kind,m.message,m.created_at FROM design_request_messages m JOIN account_request_owners x ON x.request_id=m.request_id WHERE x.user_id=? ORDER BY m.id DESC LIMIT 20',[$user['id']])->fetchAll();}
        view('member-account',$data);
    }
}
