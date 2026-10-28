<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class DesignRequests {
    public static function create(PDO $db,array $in,array $files=[]): string {
        if(MemberSchema::ready($db))MemberServices::requireMember($db);
        if(($in['consent']??'')!=='1') throw new HttpError(422,'رضایت برای تماس و استفاده از تصاویر اولیه لازم است.');
        $data=['tracking'=>bin2hex(random_bytes(16)),'martyr_name'=>Security::text($in,'martyr_name'),'martyr_code'=>Security::text($in,'martyr_code',80,false),'requester'=>Security::text($in,'requester',150),'phone'=>Security::phone(Security::text($in,'phone',20)),'notes'=>Security::text($in,'notes',5000,false),'created_at'=>date('Y-m-d H:i:s')];
        if(count($files)>5) throw new HttpError(422,'حداکثر ۵ تصویر اولیه مجاز است.');
        $saved=[];
        try {
            foreach($files as $f) $saved[]=Upload::storePrivate(Upload::receive($f),'requests');
            Db::transaction($db,function()use($db,$data,$saved,$in){ $id=Db::insert($db,'design_requests',$data);if(MemberSchema::ready($db)){ $actor=MemberServices::requireMember($db);Db::insert($db,'account_request_owners',['request_id'=>$id,'user_id'=>$actor['id'],'linked_at'=>date(DATE_ATOM)]);if(!empty($in['martyr_id'])){ $person=(int)$in['martyr_id'];if(!Db::query($db,'SELECT id FROM martyrs WHERE id=? AND published=1',[$person])->fetchColumn())throw new HttpError(422,'شهید عمومی معتبر نیست.');Db::insert($db,'design_request_details',['request_id'=>$id,'martyr_id'=>$person]);}} foreach($saved as $f) Db::insert($db,'request_files',['request_id'=>$id,'path'=>$f['path'],'mime'=>$f['mime']]); });
        } catch(\Throwable $e){ foreach($saved as $f) unlink(Upload::absolute($f['path'])); throw $e; }
        return $data['tracking'];
    }
}
