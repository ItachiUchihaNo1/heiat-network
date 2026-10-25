<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Importer {
    public static function preview(PDO $db,array $rows): array {
        $seen=[]; $result=[];
        foreach($rows as $i=>$r){
            $code=Security::normalize((string)$r['code']); $r['code']=$code; $status='new'; $error='';
            if(!preg_match('/^[A-Za-z0-9_]{1,80}$/D',$code)||empty($r['first_name'])||empty($r['last_name'])||mb_strlen($r['first_name'])>150||mb_strlen($r['last_name'])>150){$status='invalid';$error='کد یا نام معتبر نیست';}
            elseif(isset($seen[$code])){$status='duplicate';$error='تکرار در همین فایل';}
            elseif(Db::query($db,'SELECT id FROM martyrs WHERE code=?',[$code])->fetchColumn()){$status='existing';$error='موجود؛ بدون تغییر حفظ می‌شود';}
            $seen[$code]=true; $result[]=$r+['row'=>$i+2,'status'=>$status,'error'=>$error];
        } return $result;
    }
    public static function commit(PDO $db,array $rows): array {
        $result=['inserted'=>0,'skipped'=>0,'errors'=>[]];
        foreach(self::preview($db,$rows) as $r){
            if($r['status']!=='new'){ $result['skipped']++; if($r['status']==='invalid')$result['errors'][]=$r; continue; }
            try { unset($r['published']); (new Archive($db))->save($r); $result['inserted']++; }
            catch(\Throwable $e){ $result['errors'][]=['row'=>$r['row'],'code'=>$r['code'],'error'=>$e instanceof HttpError?$e->getMessage():'خطای پایگاه داده؛ ردیف وارد نشد']; }
        }
        Db::audit($db,'archive.import',json_encode(['inserted'=>$result['inserted'],'skipped'=>$result['skipped'],'errors'=>count($result['errors'])])); return $result;
    }
}

