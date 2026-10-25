<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class AdminPanel {
    public static function navigation():array{return [
        'dashboard'=>['داشبورد','نمای کلی','dashboard.view'],
        'martyrs'=>['بانک شهدا','بانک‌ها و آرشیو','martyrs.view'],
        'artists'=>['بانک هنرمندان','بانک‌ها و آرشیو','artists.view'],
        'images'=>['تصاویر و آثار','بانک‌ها و آرشیو','media.view'],
        'import'=>['ورود و خروج گروهی','بانک‌ها و آرشیو','martyrs.edit'],
        'categories'=>['دسته‌بندی و داده پایه','بانک‌ها و آرشیو','taxonomy.view'],
        'calls'=>['فراخوان‌های هنری','فراخوان و مشارکت','calls.view'],
        'submissions'=>['آثار ارسالی','فراخوان و مشارکت','submissions.view'],
        'contributions'=>['مشارکت‌های مردمی','فراخوان و مشارکت','contributions.view'],
        'claims'=>['اتصال حساب هنرمند','فراخوان و مشارکت','artists.claims.review'],
        'requests'=>['درخواست طراحی','رسیدگی','requests.view'],
        'products'=>['محصولات و موجودی','فروشگاه','shop.manage'],
        'orders'=>['سفارش‌ها','فروشگاه','shop.manage'],
        'returns'=>['مرجوعی‌ها','فروشگاه','shop.manage'],
        'users'=>['کاربران و مجوزها','مدیریت','users.manage'],
        'reports'=>['گزارش‌ها و خروجی','مدیریت','reports.archive'],
        'settings'=>['تنظیمات و پشتیبان','مدیریت','settings.manage']
    ];}
    public static function boot(PDO $db,array $user):void {
        if(PlatformSchema::ready($db)&&CommunitySchema::ready($db))return;
        if($user['role']!=='superadmin')throw new HttpError(503,'مدیر اصلی باید یک بار پنل را باز کند تا پشتیبان و ارتقای ساختار اجرا شود.');
        PlatformSchema::migrate($db);CommunitySchema::migrate($db);
    }
    public static function show(PDO $db,array|false $user):void {
        if(!$user)redirect('login');if(!Permissions::allows($db,$user,'dashboard.view'))redirect('account');
        self::boot($db,$user);$tab=Security::text($_GET,'tab',30,false)?:'dashboard';$nav=self::navigation();if(!isset($nav[$tab]))throw new HttpError(404,'بخش مدیریت پیدا نشد.');
        Permissions::require($db,$nav[$tab][2]);$label=$nav[$tab][0];$data=['db'=>$db,'user'=>$user,'tab'=>$tab,'actor'=>$user];
        $old=$_SESSION['panel_feedback']['input']??null;$errors=$_SESSION['panel_feedback']['errors']??[];unset($_SESSION['panel_feedback']);$data+=compact('old','errors');
        ob_start();
        switch($tab){
            case 'dashboard':view('admin-dashboard',$data+AdminQueries::dashboard($db,$user));break;
            case 'martyrs':
                if(array_key_exists('edit',$_GET)||!empty($_GET['new'])){if(!Permissions::allows($db,$user,'martyrs.edit'))throw new HttpError(403,'مجوز ویرایش پرونده را ندارید.');ProtocolController::show($db,$user);}
                else view('admin-martyrs',$data+['result'=>AdminQueries::martyrs($db,$_GET)]);break;
            case 'artists':
                $service=new Artists($db,$user);$editing=!empty($_GET['edit'])?$service->get((int)$_GET['edit']):null;
                view('admin-artists',$data+['editing'=>$editing,'result'=>$service->listing($_GET),'terms'=>Db::query($db,'SELECT * FROM protocol_terms ORDER BY taxonomy,sort_order,title')->fetchAll()]);break;
            case 'images':
                $service=new Media($db,$user);$editing=!empty($_GET['edit'])?$service->get((int)$_GET['edit']):null;
                view('admin-media',$data+['editing'=>$editing,'result'=>AdminQueries::media($db,$_GET),'types'=>Db::query($db,"SELECT * FROM protocol_terms WHERE taxonomy='media_type' ORDER BY sort_order,title")->fetchAll()]);break;
            case 'calls':case 'submissions':case 'contributions':case 'claims':CommunityWeb::admin($db,$user,$tab,$data);break;
            case 'users':
                $q=Security::text($_GET,'q',150,false);$where=$q!==''?'email LIKE ?':'1=1';$args=$q!==''?['%'.$q.'%']:[];$result=AdminQueries::paginate($db,'SELECT id,email,role,active','FROM users',$where,$args,['new'=>'id DESC'],$_GET);
                view('admin-users',$data+['result'=>$result]);break;
            case 'reports':view('admin-reports',$data);break;
            case 'settings':view('admin-settings',$data);break;
            default:view('admin-legacy',$data);
        }
        $body=ob_get_clean();view('admin-shell',compact('body','db','user','tab','nav','label','errors'));
    }
    public static function handles(string $action):bool{return in_array($action,['artist-save','artist-transition','media-save','media-transition','backup-create','user-save'],true);}
    public static function action(PDO $db,string $action):never {
        $user=Security::user($db);if(!$user)throw new HttpError(403,'ورود لازم است.');self::boot($db,$user);$id=(int)($_POST['id']??0);$tab=$action==='user-save'?'users':(str_starts_with($action,'artist')?'artists':(str_starts_with($action,'media')?'images':'settings'));
        try{
            switch($action){
                case 'artist-save':$avatars=Web::files('avatar');if(count($avatars)>1)throw new HttpError(422,'یک تصویر پروفایل انتخاب کنید.');$id=(new Artists($db,$user))->save($_POST,$id,$avatars?Upload::receive($avatars[0]):null);break;
                case 'artist-transition':(new Artists($db,$user))->transition($id,Security::text($_POST,'status',30),$_POST);break;
                case 'media-save':(new Media($db,$user))->save($id,$_POST);break;
                case 'media-transition':(new Media($db,$user))->transition($id,Security::text($_POST,'status',30),$_POST);break;
                case 'user-save':$id=Accounts::save($db,$user,$_POST);break;
                case 'backup-create':Permissions::require($db,'settings.manage');$backup=DataBackup::create($db);Db::audit($db,'backup.create',$backup['name']);break;
            }
            $_SESSION['flash']='تغییرات ثبت شد. انتشار عمومی فقط پس از تأیید ناشر انجام می‌شود.';
        }catch(ProtocolErrors|HttpError $e){$input=$_POST;unset($input['password'],$input['password_confirm'],$input['current_password']);$_SESSION['panel_feedback']=['input'=>$input,'errors'=>$e instanceof ProtocolErrors?$e->errors:['form'=>$e->getMessage()]];$_SESSION['flash_type']='error';}
        redirect('admin',['tab'=>$tab]+($id?['edit'=>$id]:[]));
    }
    public static function pagination(array $result,array $f):void{view('pagination',['result'=>$result,'f'=>$f]);}
}
