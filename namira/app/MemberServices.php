<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class MemberServices {
    public static function requireMember(PDO $db,?array $actor=null):array {
        $actor=$actor??Security::user($db);if(!$actor||!MobileAuth::verified($db,$actor))throw new HttpError(403,'برای این خدمت وارد حساب شوید و موبایل را تأیید کنید.');
        return CommunityHistory::requireActor($db,$actor);
    }
    public static function route(array $in):array {
        $page=is_string($in['next_page']??null)?$in['next_page']:'account';if(!in_array($page,['account','submit-art','contribute','call','request','cart','product'],true))$page='account';
        $params=[];foreach(['call_id','martyr_id','id'] as $k)if(is_scalar($in[$k]??null)&&ctype_digit((string)$in[$k]))$params[$k]=(int)$in[$k];if($page==='call')$params=['id'=>(int)($in['call_id']??$in['id']??0)];
        return [$page,$params];
    }
    public static function cart(PDO $db,array $actor):string {
        self::requireMember($db,$actor);
        return Db::transaction($db,function()use($db,$actor){
            Db::query($db,'SELECT id FROM users WHERE id=?'.($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''),[$actor['id']])->fetch();
            $token=Db::query($db,'SELECT cart_token FROM account_cart_owners WHERE user_id=?',[$actor['id']])->fetchColumn();if($token)return $token;
            $token=bin2hex(random_bytes(32));Db::insert($db,'carts',['token'=>$token,'created_at'=>date(DATE_ATOM)]);Db::insert($db,'account_cart_owners',['user_id'=>$actor['id'],'cart_token'=>$token,'created_at'=>date(DATE_ATOM)]);return $token;
        });
    }
    public static function order(PDO $db,string $token,array $actor,bool $staff=false):array {
        if($staff&&Permissions::allows($db,$actor,'shop.manage')){ $row=Db::query($db,'SELECT * FROM orders WHERE token=?',[$token])->fetch();if(!$row)throw new HttpError(404,'سفارش پیدا نشد.');return $row;}
        $order=Db::query($db,'SELECT o.* FROM orders o JOIN account_order_owners x ON x.order_id=o.id WHERE o.token=? AND x.user_id=?',[$token,$actor['id']])->fetch();if(!$order)throw new HttpError(404,'سفارش شما پیدا نشد.');return $order;
    }
    public static function request(PDO $db,int $id,array $actor):array {
        $row=Db::query($db,'SELECT * FROM design_requests WHERE id=?',[$id])->fetch();if(!$row)throw new HttpError(404,'درخواست پیدا نشد.');
        if(!Permissions::allows($db,$actor,'requests.view')&&!Db::query($db,'SELECT request_id FROM account_request_owners WHERE request_id=? AND user_id=?',[$id,$actor['id']])->fetchColumn())throw new HttpError(404,'درخواست شما پیدا نشد.');return $row;
    }
    public static function address(PDO $db,array $actor,array $in):void {
        self::requireMember($db,$actor);$id=(int)($in['id']??0);
        if($id){Db::query($db,'DELETE FROM account_addresses WHERE id=? AND user_id=?',[$id,$actor['id']]);return;}
        $postal=Security::normalize(Security::text($in,'postal_code',10));if(!preg_match('/^[0-9]{10}$/D',$postal))throw new HttpError(422,'کدپستی باید ۱۰ رقم باشد.');
        Db::insert($db,'account_addresses',['user_id'=>$actor['id'],'title'=>Security::text($in,'title',100),'recipient'=>Security::text($in,'recipient',150),'phone'=>Security::phone(Security::text($in,'phone',20)),'postal_code'=>$postal,'address'=>Security::text($in,'address',2000),'created_at'=>date(DATE_ATOM)]);
    }
    public static function message(PDO $db,array $actor,int $id,array $in,array $files=[]):void {
        self::request($db,$id,$actor);$staff=Permissions::allows($db,$actor,'requests.edit');if(!$staff)self::requireMember($db,$actor);
        $kind=Security::text($in,'kind',30,false)?:'reply';if(!in_array($kind,$staff?['reply','needs_changes','delivery']:['reply'],true))throw new HttpError(403,'نوع پیام مجاز نیست.');
        $text=Security::text($in,'message',5000);if(count($files)>1)throw new HttpError(422,'یک تصویر پیوست انتخاب کنید.');$stored=null;
        try{if($files)$stored=Upload::storePrivate(Upload::receive($files[0]),'requests');
            Db::transaction($db,function()use($db,$actor,$id,$kind,$text,$stored){$file=$stored?Db::insert($db,'request_files',['request_id'=>$id,'path'=>$stored['path'],'mime'=>$stored['mime']]):null;Db::insert($db,'design_request_messages',['request_id'=>$id,'user_id'=>$actor['id'],'message'=>$text,'file_id'=>$file,'kind'=>$kind,'created_at'=>date(DATE_ATOM)]);Db::audit($db,'request.message',(string)$id);});
        }catch(\Throwable $e){if($stored)@unlink(Upload::absolute($stored['path']));throw $e;}
    }
}
