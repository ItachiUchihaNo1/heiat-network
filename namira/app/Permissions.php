<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Permissions {
    public static function defaults():array{return [
        'superadmin'=>['*'],
        'content'=>['dashboard.view','martyrs.view','martyrs.edit','media.view','media.create','media.edit','artists.view','artists.edit','taxonomy.view','reports.archive','requests.view','requests.edit'],
        'shop'=>['dashboard.view','shop.*','reports.shop'],
        'media_manager'=>['dashboard.view','media.*','artists.view','martyrs.view','taxonomy.view','reports.archive'],
        'reviewer'=>['dashboard.view','media.create','media.edit','martyrs.view','media.view','media.review','media.publish','submissions.*','artists.view','artists.review','artists.publish','artists.claims.review'],
        'call_manager'=>['dashboard.view','calls.*','submissions.*','artists.view','martyrs.view'],
        'contribution_manager'=>['dashboard.view','contributions.*','martyrs.view','martyrs.edit','media.view','media.create','media.edit'],
        'design_manager'=>['dashboard.view','requests.*','artists.view','martyrs.view','media.view'],
        'artist'=>['account.view','submissions.create','contributions.create','requests.create'],
        'member'=>['account.view','submissions.create','contributions.create','requests.create']
    ];}
    public static function allows(PDO $db,array|false $user,string $permission):bool {
        if(!$user)return false;if(($user['role']??'')==='superadmin')return true;
        $roles=[$user['role']??''];$grants=self::defaults()[$user['role']??'']??[];
        if(PlatformSchema::ready($db)){foreach(Db::query($db,'SELECT role_key FROM user_roles WHERE user_id=?',[$user['id']])->fetchAll(PDO::FETCH_COLUMN) as $role)$roles[]=$role;foreach(array_unique($roles) as $role)foreach(Db::query($db,'SELECT permission FROM role_permissions WHERE role_key=?',[$role])->fetchAll(PDO::FETCH_COLUMN) as $grant)$grants[]=$grant;}
        foreach($grants as $grant)if($grant==='*'||$grant===$permission||(str_ends_with($grant,'.*')&&str_starts_with($permission,substr($grant,0,-1))))return true;return false;
    }
    public static function require(PDO $db,string $permission):array {$user=Security::user($db);if(!self::allows($db,$user,$permission))throw new HttpError(403,'مجوز انجام این عملیات را ندارید.');return $user;}
}
