<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class AdminQueries {
    public static function paginate(PDO $db,string $select,string $from,string $where,array $args,array $sorts,array $f,int $per=25):array {
        $count=(int)Db::query($db,'SELECT COUNT(*) '.$from.' WHERE '.$where,$args)->fetchColumn();$pages=max(1,(int)ceil($count/$per));$page=min($pages,max(1,(int)($f['p']??1)));$sort=$sorts[$f['sort']??'']??reset($sorts);
        $items=Db::query($db,$select.' '.$from.' WHERE '.$where.' ORDER BY '.$sort.' LIMIT '.$per.' OFFSET '.(($page-1)*$per),$args)->fetchAll();return compact('items','count','page','pages','per');
    }
    public static function martyrs(PDO $db,array $f):array {
        $where=['1=1'];$args=[];$q=Security::normalize(Security::text($f,'q',150,false));if($q!==''){$where[]='(m.code LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR m.father_name LIKE ? OR p.search_text LIKE ?)';$args=[...$args,...array_fill(0,5,'%'.$q.'%')];}
        $status=Security::text($f,'status',30,false);if($status!==''){if($status==='unpublished')$where[]="COALESCE(p.status,'draft') NOT IN ('published','archived') AND m.published=0";else{$where[]="COALESCE(p.status,CASE WHEN m.published=1 THEN 'published' ELSE 'draft' END)=?";$args[]=$status;}}
        if(($f['completion']??'')==='incomplete')$where[]="(m.summary='' OR NOT EXISTS(SELECT 1 FROM martyr_sections s WHERE s.martyr_id=m.id AND s.step=2))";
        if(($f['completion']??'')==='no-images')$where[]='NOT EXISTS(SELECT 1 FROM media_martyrs mm WHERE mm.martyr_id=m.id)';
        $from='FROM martyrs m LEFT JOIN martyr_protocols p ON p.martyr_id=m.id';$sql=implode(' AND ',$where);
        $draftFather="JSON_EXTRACT((SELECT payload FROM martyr_sections ms WHERE ms.martyr_id=m.id AND ms.step=1),'$.father_name')";if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql')$draftFather='JSON_UNQUOTE('.$draftFather.')';
        $select="SELECT m.*,COALESCE($draftFather,m.father_name) AS draft_father_name,COALESCE(p.display_name,'') AS display_name,COALESCE(p.status,CASE WHEN m.published=1 THEN 'published' ELSE 'draft' END) AS status,p.updated_at,p.revision,(SELECT COUNT(*) FROM media_martyrs mm WHERE mm.martyr_id=m.id) AS image_count,(SELECT COUNT(*) FROM media_martyrs mm JOIN images i ON i.id=mm.image_id WHERE mm.martyr_id=m.id AND i.kind='design') AS artwork_count,(SELECT i.id FROM images i JOIN media_martyrs mm ON mm.image_id=i.id WHERE mm.martyr_id=m.id ORDER BY mm.is_featured DESC,i.sequence_no LIMIT 1) AS feature_id";
        return self::paginate($db,$select,$from,$sql,$args,['new'=>'m.id DESC','name'=>'m.last_name,m.first_name,m.id','updated'=>'p.updated_at DESC,m.id DESC','code'=>'m.code,m.id'],$f);
    }
    public static function media(PDO $db,array $f):array {
        $where=['1=1'];$args=[];$q=Security::text($f,'q',150,false);if($q!==''){$where[]='(d.title LIKE ? OR i.designer LIKE ? OR m.code LIKE ?)';$args=[...array_fill(0,3,'%'.$q.'%')];}
        foreach(['status'=>'d.status','type_id'=>'d.type_id'] as $key=>$col)if(($f[$key]??'')!==''){if($key==='status'&&$f[$key]==='unpublished')$where[]="d.status NOT IN ('published','archived')";else{$where[]=$col.'=?';$args[]=$key==='type_id'?(int)$f[$key]:Security::text($f,$key,30);}}
        if(($f['uncredited']??'')==='1')$where[]='NOT EXISTS(SELECT 1 FROM media_artists ma WHERE ma.image_id=i.id)';
        $from='FROM images i JOIN media_details d ON d.image_id=i.id JOIN martyrs m ON m.id=i.martyr_id LEFT JOIN protocol_terms t ON t.id=d.type_id';
        $select='SELECT i.*,d.title AS draft_title,d.status,d.revision,d.type_id,d.updated_at,t.title AS type_name,m.code,(SELECT COUNT(*) FROM media_artists ma WHERE ma.image_id=i.id) AS creator_count';
        return self::paginate($db,$select,$from,implode(' AND ',$where),$args,['new'=>'i.id DESC','updated'=>'d.updated_at DESC','downloads'=>'i.downloads DESC'],$f);
    }
    public static function dashboard(PDO $db,array $user):array {
        $stats=[];$add=function($key,$label,$sql,$tab)use($db,&$stats){$stats[]=['key'=>$key,'label'=>$label,'value'=>(int)$db->query($sql)->fetchColumn(),'tab'=>$tab];};
        if(Permissions::allows($db,$user,'martyrs.view')){$add('martyrs','پرونده شهدا','SELECT COUNT(*) FROM martyrs','martyrs');$add('incomplete','پرونده نیازمند تکمیل',"SELECT COUNT(*) FROM martyrs WHERE summary=''",'martyrs');}
        if(Permissions::allows($db,$user,'media.view')){$add('media','رسانه‌ها','SELECT COUNT(*) FROM images','images');$add('pending_media','رسانه منتشرنشده',"SELECT COUNT(*) FROM media_details WHERE status NOT IN ('published','archived')",'images');$add('uncredited','رسانه بدون پدیدآورنده متصل','SELECT COUNT(*) FROM images i WHERE NOT EXISTS(SELECT 1 FROM media_artists ma WHERE ma.image_id=i.id)','images');}
        if(Permissions::allows($db,$user,'artists.view'))$add('artists','پرونده هنرمندان','SELECT COUNT(*) FROM artists WHERE archived=0','artists');
        if(Permissions::allows($db,$user,'requests.view'))$add('requests','درخواست طراحی در جریان',"SELECT COUNT(*) FROM design_requests WHERE status IN ('submitted','reviewing','accepted')",'requests');
        if(Permissions::allows($db,$user,'shop.manage')){$add('orders','سفارش نیازمند رسیدگی',"SELECT COUNT(*) FROM orders WHERE status IN ('paid','preparing')",'orders');$add('low_stock','تنوع کم‌موجودی','SELECT COUNT(*) FROM variants WHERE stock<=3','products');}
        if(CommunitySchema::ready($db)){
            if(Permissions::allows($db,$user,'calls.view'))$add('calls','فراخوان منتشرشده',"SELECT COUNT(*) FROM art_calls WHERE status='published'",'calls');
            if(Permissions::allows($db,$user,'submissions.view'))$add('incoming_works','اثر دریافتی منتشرنشده',"SELECT COUNT(*) FROM art_submissions WHERE status IN ('submitted','reviewing')",'submissions');
            if(Permissions::allows($db,$user,'contributions.view'))$add('contributions','مشارکت دریافتی',"SELECT COUNT(*) FROM public_contributions WHERE status IN ('submitted','reviewing')",'contributions');
            if(Permissions::allows($db,$user,'artists.claims.review'))$add('claims','اتصال هنرمند در صف',"SELECT COUNT(*) FROM artist_account_claims WHERE status='pending'",'claims');
        }
        $activities=($user['role']==='superadmin')?Db::query($db,'SELECT a.*,u.email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 12')->fetchAll():Db::query($db,'SELECT a.*,u.email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.user_id=? ORDER BY a.id DESC LIMIT 12',[$user['id']])->fetchAll();
        return compact('stats','activities');
    }
}
