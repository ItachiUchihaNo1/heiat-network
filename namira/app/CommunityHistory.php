<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CommunityHistory {
    public static function add(PDO $db,string $kind,int $id,array $actor,string $from,string $to,string $note):void {
        Db::insert($db,'submission_history',['entity_type'=>$kind,'entity_id'=>$id,'user_id'=>$actor['id'],'from_status'=>$from,'to_status'=>$to,'note'=>$note,'created_at'=>date('Y-m-d H:i:s')]);
        Db::insert($db,'audit_logs',['user_id'=>$actor['id'],'action'=>$kind.'.'.$to,'target'=>(string)$id,'created_at'=>date('Y-m-d H:i:s')]);
    }
    public static function requireActor(PDO $db,array $actor):array {
        $user=Db::query($db,'SELECT id,email,role FROM users WHERE id=? AND active=1',[(int)($actor['id']??0)])->fetch();if(!$user)throw new HttpError(403,'حساب فعال و ورود به سامانه لازم است.');return $user;
    }
    public static function rows(PDO $db,string $kind,int $id):array{return Db::query($db,'SELECT h.*,u.email FROM submission_history h LEFT JOIN users u ON u.id=h.user_id WHERE entity_type=? AND entity_id=? ORDER BY h.id DESC LIMIT 50',[$kind,$id])->fetchAll();}
}
