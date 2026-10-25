<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CommunityWeb {
    public static function handles(string $action):bool{return in_array($action,['account-register','account-profile','claim-create','claim-review','call-save','call-state','submission-save','submission-review','submission-publish','contribution-save','contribution-review','contribution-merge','owned-artist-save','owned-artist-submit'],true);}
    public static function next(array $in):never {
        $next=Security::text($in,'next_page',30,false);if(!in_array($next,['account','submit-art','contribute','call'],true))$next='account';
        $params=[];foreach(['call_id','martyr_id'] as $key)if(!empty($in[$key]))$params[$key]=(int)$in[$key];if($next==='call')$params=['id'=>(int)($in['call_id']??0)];redirect($next,$params);
    }
    private static function sources(string $key,int $max):array {
        $files=Web::files($key);$previews=Web::files('previews');if(count($files)>$max||($previews&&count($previews)!==count($files)))throw new HttpError(422,'تعداد فایل‌ها یا پیش‌نمایش همراه معتبر نیست.');
        $result=[];foreach($files as $i=>$file)$result[]=['source'=>Upload::receive($file),'preview'=>isset($previews[$i])?Upload::receive($previews[$i]):null];return $result;
    }
    private static function verified():array {
        $f=$_FILES['verified_previews']??null;if(!$f)return [];if(!is_array($f['name'])||count($f['name'])>5)throw new HttpError(422,'حداکثر ۵ پیش‌نمایش تأییدشده مجاز است.');$out=[];
        foreach($f['name'] as $key=>$name){if(!ctype_digit((string)$key))throw new HttpError(422,'شناسه پیش‌نمایش معتبر نیست.');$file=['name'=>$name,'tmp_name'=>$f['tmp_name'][$key],'error'=>$f['error'][$key],'size'=>$f['size'][$key],'type'=>$f['type'][$key]];$out[(int)$key]=Upload::receive($file);}return $out;
    }
    private static function feedback(string $route,array $params):array {
        $f=$_SESSION['community_feedback']??null;unset($_SESSION['community_feedback']);
        return $f&&$f['route']===$route&&$f['params']===$params?['old'=>$f['input'],'errors'=>$f['errors']]:['old'=>null,'errors'=>[]];
    }
    private static function fail(\Throwable $e,string $route,array $params):never {
        if(str_contains($_SERVER['HTTP_ACCEPT']??'','application/json'))throw $e;
        $input=$_POST;unset($input['password'],$input['password_confirm'],$input['current_password'],$input['csrf']);
        $_SESSION['community_feedback']=['route'=>$route,'params'=>$params,'input'=>$input,'errors'=>$e instanceof ProtocolErrors?$e->errors:['form'=>$e->getMessage()]];redirect($route,$params);
    }
    public static function action(PDO $db,string $action):never {
        CommunitySchema::requireReady($db);$actor=Security::user($db);$id=(int)($_POST['id']??0);$back='account';$params=[];
        if($action==='account-register'){
            try{$id=Accounts::register($db,$_POST);session_regenerate_id(true);$_SESSION['user_id']=$id;$_SESSION['csrf']=bin2hex(random_bytes(32));self::next($_POST);}catch(HttpError|ProtocolErrors $e){self::fail($e,'register',[]);}
        }
        if(!$actor)throw new HttpError(403,'ابتدا وارد حساب شوید.');
        try{
            switch($action){
                case 'account-profile':$params=['section'=>'profile'];Accounts::profile($db,$actor,$_POST);break;
                case 'claim-create':$params=['section'=>'claims'];ArtistAccounts::claim($db,$actor,$_POST);break;
                case 'claim-review':$back='admin';$params=['tab'=>'claims','edit'=>$id];ArtistAccounts::review($db,$actor,$id,$_POST);break;
                case 'call-save':$back='admin';$params=['tab'=>'calls']+($id?['edit'=>$id]:['new'=>1]);$files=Web::files('poster');if(count($files)>1)throw new HttpError(422,'یک پوستر انتخاب کنید.');$id=(new Calls($db,$actor))->save($_POST,$id,$files?Upload::receive($files[0]):null);$params=['tab'=>'calls','edit'=>$id];break;
                case 'call-state':$back='admin';$params=['tab'=>'calls','edit'=>$id];(new Calls($db,$actor))->transition($id,$_POST);break;
                case 'submission-save':
                    $back='submit-art';$params=$id?['id'=>$id]:['call_id'=>(int)($_POST['call_id']??0)];$files=self::sources('artwork',1);
                    $id=(new Submissions($db,$actor))->submit($_POST,$files[0]['source']??null,$files[0]['preview']??null,$id);Web::uploadResponse('account',['section'=>'submissions','id'=>$id]);
                case 'submission-review':$back='admin';$params=['tab'=>'submissions','edit'=>$id];(new Submissions($db,$actor))->review($id,$_POST);break;
                case 'submission-publish':
                    $back='admin';$params=['tab'=>'submissions','edit'=>$id];$previews=self::verified();$row=(new Submissions($db,$actor))->get($id);$current=null;foreach($row['assets']??[] as $asset)if($asset['is_current'])$current=$asset['id'];
                    (new Submissions($db,$actor))->publish($id,$_POST,$previews[$current]??null);$_SESSION['flash']='اثر با انتساب و حقوق بررسی‌شده منتشر شد.';Web::uploadResponse('admin',$params);
                case 'contribution-save':
                    $back='contribute';$params=$id?['id'=>$id]:['martyr_id'=>(int)($_POST['martyr_id']??0)];$files=self::sources('photos',5);$id=(new Contributions($db,$actor))->submit($_POST,$files,$id);Web::uploadResponse('account',['section'=>'contributions','id'=>$id]);
                case 'contribution-review':$back='admin';$params=['tab'=>'contributions','edit'=>$id];(new Contributions($db,$actor))->review($id,$_POST);break;
                case 'contribution-merge':
                    $back='admin';$params=['tab'=>'contributions','edit'=>$id];(new Contributions($db,$actor))->merge($id,$_POST,self::verified());$_SESSION['flash']='فقط بخش‌های انتخاب‌شده به پیش‌نویس منتقل شدند؛ انتشار عمومی پرونده و رسانه جداگانه بررسی می‌شود.';Web::uploadResponse('admin',$params);
                case 'owned-artist-save':
                    $params=['section'=>'artists','id'=>$id];if(!ArtistAccounts::owns($db,$actor,$id))throw new HttpError(403,'این پروفایل به حساب شما متصل نیست.');$files=Web::files('avatar');if(count($files)>1)throw new HttpError(422,'یک تصویر انتخاب کنید.');(new Artists($db,$actor))->save($_POST,$id,$files?Upload::receive($files[0]):null);break;
                case 'owned-artist-submit':
                    $params=['section'=>'artists','id'=>$id];if(!ArtistAccounts::owns($db,$actor,$id))throw new HttpError(403,'این پروفایل به حساب شما متصل نیست.');(new Artists($db,$actor))->transition($id,'pending',$_POST);break;
                default:throw new HttpError(404,'عملیات پیدا نشد.');
            }
            $_SESSION['flash']='اطلاعات ثبت شد.';redirect($back,$params);
        }catch(HttpError|ProtocolErrors $e){self::fail($e,$back,$params);}
    }
    public static function lookup(PDO $db):never {
        CommunitySchema::requireReady($db);$kind=Security::text($_GET,'kind',20);$q=Security::normalize(Security::text($_GET,'q',150,false));$rows=[];
        if(mb_strlen($q)>=2){Security::limit($db,'public-lookup',120,60);
            if($kind==='artists'){$items=Db::query($db,'SELECT id,public_snapshot FROM artists WHERE public_visible=1 AND archived=0 AND public_snapshot LIKE ? ORDER BY id DESC LIMIT 20',['%'.$q.'%'])->fetchAll();foreach($items as $item)$rows[]=['id'=>(int)$item['id'],'label'=>Protocol::decode($item['public_snapshot'])['display_name']??''];}
            elseif($kind==='martyrs'){$where=['m.published=1'];$args=[];foreach(array_slice(preg_split('/\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY),0,10) as $word){$where[]='(m.code LIKE ? OR m.first_name LIKE ? OR m.last_name LIKE ? OR p.public_name LIKE ?)';$args=[...$args,...array_fill(0,4,'%'.$word.'%')];}
                $items=Db::query($db,'SELECT m.id,m.code,m.first_name,m.last_name,p.public_name FROM martyrs m LEFT JOIN martyr_protocols p ON p.martyr_id=m.id WHERE '.implode(' AND ',$where).' ORDER BY m.id DESC LIMIT 20',$args)->fetchAll();foreach($items as $item)$rows[]=['id'=>(int)$item['id'],'label'=>$item['code'].' · '.($item['public_name']?:trim($item['first_name'].' '.$item['last_name']))];
            }
        }
        header('Content-Type: application/json; charset=utf-8');header('Cache-Control: private, no-store');echo json_encode($rows,JSON_UNESCAPED_UNICODE);exit;
    }
    public static function file(PDO $db):never {
        CommunitySchema::requireReady($db);$actor=Security::user($db);if(!$actor)throw new HttpError(404,'فایل پیدا نشد.');$asset=IncomingAssets::file($db,(int)($_GET['id']??0),$actor);$preview=($_GET['size']??'')==='preview';
        Web::sendFile(Upload::absolute($asset[$preview?'preview_path':'original_path']),$preview?'image/webp':$asset['mime'],$preview?'inline':'attachment',$preview?'preview.webp':'original-'.(int)$asset['id'].'.'.(['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$asset['mime']]));exit;
    }
    public static function people(PDO $db,array $ids):array {
        $ids=array_values(array_filter(array_unique(array_map('intval',$ids)),fn($v)=>$v>0));if(!$ids)return [];
        return Db::query($db,'SELECT id,code,first_name,last_name FROM martyrs WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids)->fetchAll();
    }
    public static function show(PDO $db,string $page,array|false $user):string {
        CommunitySchema::requireReady($db);$data=['db'=>$db,'user'=>$user,'errors'=>[],'old'=>null];
        if($page==='register'){if($user)redirect('account');view('register',array_replace($data,self::feedback('register',[])));return 'ثبت حساب | نامیرا';}
        if(in_array($page,['submit-art','contribute','account'],true)&&!$user){$next=['next_page'=>$page];foreach(['call_id','martyr_id'] as $key)if(!empty($_GET[$key]))$next[$key]=(int)$_GET[$key];redirect('login',$next);}
        if($page==='calls'){view('calls',$data+['result'=>(new Calls($db,[]))->listing($_GET,true)]);return 'فراخوان‌های هنری | نامیرا';}
        if($page==='call'){$call=(new Calls($db,[]))->get((int)($_GET['id']??0),true);if(!$call)throw new HttpError(404,'فراخوان پیدا نشد.');view('call',$data+['call'=>$call]);return $call['title'].' | نامیرا';}
        if($page==='account'){
            $section=Security::text($_GET,'section',30,false)?:'overview';if(!in_array($section,['overview','profile','submissions','contributions','artists','claims','security'],true))throw new HttpError(404,'بخش حساب پیدا نشد.');$id=(int)($_GET['id']??0);
            $data+=['section'=>$section,'profile'=>Db::query($db,'SELECT * FROM account_profiles WHERE user_id=?',[$user['id']])->fetch(),'owned'=>ArtistAccounts::owned($db,$user),'storage_usage'=>IncomingAssets::usage($db,(int)$user['id'])];$data=array_replace($data,self::feedback('account',['section'=>$section]+($id?['id'=>$id]:[])));
            if($section==='submissions'){$service=new Submissions($db,$user);$data['result']=$service->listing($_GET,true);$data['entry']=$id?$service->get($id):null;if($data['entry']&&(int)$data['entry']['user_id']!==(int)$user['id'])throw new HttpError(404,'ارسال شما پیدا نشد.');}
            if($section==='contributions'){$service=new Contributions($db,$user);$data['result']=$service->listing($_GET,true);$data['entry']=$id?$service->get($id):null;if($data['entry']&&(int)$data['entry']['user_id']!==(int)$user['id'])throw new HttpError(404,'مشارکت شما پیدا نشد.');}
            if($section==='claims')$data['claims']=Db::query($db,'SELECT c.*,a.display_name FROM artist_account_claims c JOIN artists a ON a.id=c.artist_id WHERE c.user_id=? ORDER BY c.id DESC',[$user['id']])->fetchAll();
            if($section==='artists'&&$id){if(!ArtistAccounts::owns($db,$user,$id))throw new HttpError(403,'پروفایل به حساب شما متصل نیست.');$data['artist']=(new Artists($db,$user))->get($id);$data['terms']=Db::query($db,"SELECT * FROM protocol_terms WHERE taxonomy='art_specialty' ORDER BY title")->fetchAll();}
            view('account-community',$data);return 'حساب کاربری | نامیرا';
        }
        $id=(int)($_GET['id']??0);$entry=null;$routeParams=[];
        if($id){$entry=$page==='submit-art'?(new Submissions($db,$user))->get($id):(new Contributions($db,$user))->get($id);if(!$entry||(int)$entry['user_id']!==(int)$user['id'])throw new HttpError(404,'ارسال شما پیدا نشد.');if($entry['status']!=='needs_changes')throw new HttpError(409,'برای این ارسال درخواست اصلاح ثبت نشده است.');$routeParams=['id'=>$id];}
        $call=null;$callId=(int)($entry['call_id']??$_GET['call_id']??0);if($page==='submit-art'&&$callId){if($entry){$call=Db::query($db,"SELECT * FROM art_calls WHERE id=? AND status IN ('published','paused')",[$callId])->fetch();if($call)$call['criteria']=Protocol::decode($call['criteria']);}else $call=Calls::accepting($db,$callId);if(!$call)throw new HttpError(422,'فراخوان پذیرش ندارد.');}
        if(!$id)$routeParams=$page==='submit-art'?['call_id'=>$callId]:['martyr_id'=>(int)($_GET['martyr_id']??0)];
        $data=array_replace($data,self::feedback($page,$routeParams));$data+=['entry'=>$entry,'call'=>$call,'profile'=>Db::query($db,'SELECT * FROM account_profiles WHERE user_id=?',[$user['id']])->fetch(),'owned'=>ArtistAccounts::owned($db,$user),'terms'=>Db::query($db,"SELECT * FROM protocol_terms WHERE taxonomy IN ('art_specialty','media_type') AND active=1 ORDER BY title")->fetchAll()];
        view($page==='submit-art'?'submission-form':'contribution-form',$data);return ($page==='submit-art'?'ارسال اثر هنری':'مشارکت در تکمیل اطلاعات').' | نامیرا';
    }
    public static function admin(PDO $db,array $user,string $tab,array $data):void {
        $id=(int)($_GET['edit']??0);$params=['tab'=>$tab]+($id?['edit'=>$id]:(isset($_GET['new'])?['new'=>1]:[]));$data=array_replace($data,self::feedback('admin',$params));
        if($tab==='calls'){$service=new Calls($db,$user);$data+=['editing'=>$id?$service->get($id):null,'result'=>$service->listing($_GET),'terms'=>Db::query($db,"SELECT * FROM protocol_terms WHERE taxonomy IN ('media_type','art_specialty') AND active=1 ORDER BY title")->fetchAll()];view('admin-calls',$data);return;}
        if($tab==='claims'){$where='1=1';$args=[];if(!empty($_GET['status'])){$where='c.status=?';$args[]=$_GET['status'];}$data+=['result'=>AdminQueries::paginate($db,'SELECT c.*,a.display_name,u.email','FROM artist_account_claims c JOIN artists a ON a.id=c.artist_id JOIN users u ON u.id=c.user_id',$where,$args,['new'=>'c.id DESC'],$_GET),'editing'=>$id?Db::query($db,'SELECT c.*,a.display_name,u.email FROM artist_account_claims c JOIN artists a ON a.id=c.artist_id JOIN users u ON u.id=c.user_id WHERE c.id=?',[$id])->fetch():null];view('admin-claims',$data);return;}
        $service=$tab==='submissions'?new Submissions($db,$user):new Contributions($db,$user);$editing=$id?$service->get($id):null;if($id&&!$editing)throw new HttpError(404,'مورد پیدا نشد.');$data+=['editing'=>$editing,'result'=>$service->listing($_GET),'terms'=>Db::query($db,"SELECT * FROM protocol_terms WHERE taxonomy IN ('media_type','art_specialty') ORDER BY title")->fetchAll()];
        if($tab==='contributions'&&$editing&&$editing['martyr_id']&&Permissions::allows($db,$user,'martyrs.edit'))$data['bundle']=(new Protocol($db,$user))->get((int)$editing['martyr_id']);
        view($tab==='submissions'?'admin-submissions':'admin-contributions',$data);
    }
}
