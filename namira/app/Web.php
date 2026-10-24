<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Web {
    public static function files(string $key): array {
        $f=$_FILES[$key]??null; if(!$f) return [];
        if(!is_array($f['name'])) return $f['error']===UPLOAD_ERR_NO_FILE?[]:[$f];
        $rows=[]; foreach($f['name'] as $i=>$name) if($f['error'][$i]!==UPLOAD_ERR_NO_FILE) $rows[]=['name'=>$name,'tmp_name'=>$f['tmp_name'][$i],'error'=>$f['error'][$i],'size'=>$f['size'][$i],'type'=>$f['type'][$i]];
        return $rows;
    }
    public static function run(): void {
        Security::start(); $db=null; $page='home';
        try {
            $page=Security::text($_GET,'page',40,false)?:'home';
            if($page==='setup'){ Installer::web(); return; }
            $db=Db::connect(); $archive=new Archive($db); $shop=new Shop($db);
            if($_SERVER['REQUEST_METHOD']==='POST'){ Security::verify($_POST); self::action($db,$archive,$shop); }
            if($page==='media'||$page==='download') { self::media($db,$archive,$page); return; }
            if($page==='request-file') {
                Security::requireRole($db,'content'); $f=Db::query($db,'SELECT * FROM request_files WHERE id=?',[(int)($_GET['id']??0)])->fetch();
                if(!$f) throw new HttpError(404,'فایل پیدا نشد.'); self::sendFile(Upload::absolute($f['path']),$f['mime'],'attachment','request-image'); return;
            }
            if($page==='export') {
                Security::requireRole($db,'content'); $tmp=tempnam(Env::storage(),'export-');
                try { Xlsx::write($archive->export($_GET),$tmp); self::sendFile($tmp,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','attachment','namira-archive.xlsx'); } finally { unlink($tmp); } return;
            }
            $user=Security::user($db); $title='نامیرا | سامانه جامع تصاویر شهدا';
            header('Cache-Control: private, no-store');
            ob_start();
            switch($page){
                case 'home': case 'gallery':
                    $f=$_GET; $result=$archive->images($f,max(1,(int)($_GET['p']??1))); $categories=Db::query($db,'SELECT * FROM categories ORDER BY name')->fetchAll();
                    $options=[]; foreach(['province','city','job','organization'] as $key) $options[$key]=Db::query($db,'SELECT DISTINCT '.$key.' AS value FROM martyrs WHERE published=1 AND '.$key."<>'' ORDER BY ".$key)->fetchAll();
                    view('gallery',compact('page','f','result','categories','options')); break;
                case 'profile':
                    $m=$archive->find((int)($_GET['id']??0)); if(!$m)throw new HttpError(404,'پروفایل پیدا نشد.');
                    $title='شهید '.$m['first_name'].' '.$m['last_name'].' | نامیرا';
                    $images=Db::query($db,'SELECT i.*,m.first_name,m.last_name,m.code,m.province,m.city FROM images i JOIN martyrs m ON m.id=i.martyr_id WHERE i.martyr_id=? AND i.published=1 ORDER BY i.sequence_no',[$m['id']])->fetchAll();
                    view('profile',compact('m','images')); break;
                case 'image': $im=$archive->image((int)($_GET['id']??0)); if(!$im)throw new HttpError(404,'تصویر پیدا نشد.'); $title=$im['title'].' | نامیرا'; view('image',compact('im')); break;
                case 'request': view('request'); break;
                case 'request-track':
                    $token=Security::text($_GET,'token',64,false); $request=$token?Db::query($db,'SELECT tracking,status,created_at FROM design_requests WHERE tracking=?',[$token])->fetch():false;
                    view('request-track',compact('request')); break;
                case 'shop':
                    $args=[]; $w=['p.published=1']; $q=Security::text($_GET,'q',150,false); if($q!==''){ $w[]='p.title LIKE ?'; $args[]='%'.$q.'%'; }
                    $cat=Security::text($_GET,'category',100,false); if($cat!==''){$w[]='p.category=?';$args[]=$cat;}
                    if(is_numeric($_GET['max_price']??null)){ $w[]='COALESCE(v.sale_price,v.price)<=?';$args[]=max(0,(int)$_GET['max_price']); }
                    $sort=['price'=>'COALESCE(v.sale_price,v.price)','new'=>'p.id DESC'][$_GET['sort']??'new']??'p.id DESC';
                    $products=Db::query($db,'SELECT p.*,v.id AS variant_id,v.attributes,v.price,v.sale_price,v.stock FROM products p JOIN variants v ON v.product_id=p.id WHERE '.implode(' AND ',$w).' ORDER BY '.$sort.' LIMIT 100',$args)->fetchAll();
                    view('shop',compact('products')); break;
                case 'product':
                    $product=Db::query($db,'SELECT * FROM products WHERE id=? AND published=1',[(int)($_GET['id']??0)])->fetch(); if(!$product)throw new HttpError(404,'محصول پیدا نشد.');
                    $variants=Db::query($db,'SELECT * FROM variants WHERE product_id=?',[$product['id']])->fetchAll(); view('product',compact('product','variants')); break;
                case 'cart': $items=$shop->items($shop->cartToken()); if(!isset($_SESSION['checkout_key'])||($items&&Db::query($db,'SELECT id FROM orders WHERE checkout_key=?',[$_SESSION['checkout_key']])->fetchColumn())) $_SESSION['checkout_key']=bin2hex(random_bytes(32)); view('cart',compact('items')); break;
                case 'order':
                    $token=Security::text($_GET,'token',64); $order=Db::query($db,'SELECT * FROM orders WHERE token=?',[$token])->fetch(); if(!$order)throw new HttpError(404,'سفارش پیدا نشد.');
                    $items=Db::query($db,'SELECT * FROM order_items WHERE order_id=?',[$order['id']])->fetchAll(); $payment=Db::query($db,'SELECT * FROM payments WHERE order_id=?',[$order['id']])->fetch(); view('order',compact('order','items','payment')); break;
                case 'login': view('login'); break;
                case 'admin': self::admin($db,$user); break;
                case 'about': view('about'); break;
                default: throw new HttpError(404,'صفحه پیدا نشد.');
            }
            $content=ob_get_clean(); view('layout',compact('content','page','title','user'));
        } catch(\Throwable $ex){
            while(ob_get_level()) ob_end_clean(); $status=$ex instanceof HttpError?$ex->status:503; http_response_code($status);
            $message=$ex instanceof HttpError?$ex->getMessage():'سرویس موقتاً در دسترس نیست؛ تنظیم پایگاه داده یا گزارش خطا را بررسی کنید.';
            if(!$ex instanceof HttpError) error_log('Namira: '.$ex->getMessage());
            $title='نامیرا | '.$status; $user=false; $content='<section class="request"><h1>'.e(fa($status)).'</h1><p role="alert">'.e($message).'</p><p><a class="outline" href="'.e(url('home')).'">صفحه اصلی</a></p></section>'; view('layout',compact('content','page','title','user'));
        }
    }
    private static function action(PDO $db,Archive $archive,Shop $shop): never {
        $a=Security::text($_POST,'action',40);
        switch($a){
            case 'login':
                Security::limit($db,'login',8); $email=Security::text($_POST,'email',190); $password=Security::text($_POST,'password',1024);
                $u=Db::query($db,'SELECT * FROM users WHERE email=? AND active=1',[$email])->fetch();
                $dummy='$2y$10$wHVV71XhPT3QCKoRV4pxXej0zu1ZokgOphJ9BSkS0HAsRkUPdMQpK';
                if(!password_verify($password,$u['password_hash']??$dummy)||!$u) throw new HttpError(422,'ایمیل یا رمز معتبر نیست.');
                session_regenerate_id(true); $_SESSION['user_id']=$u['id']; $_SESSION['csrf']=bin2hex(random_bytes(32)); redirect('admin');
            case 'logout': $_SESSION=[]; session_regenerate_id(true); redirect('home');
            case 'martyr-save': Security::requireRole($db,'content'); $id=$archive->save($_POST,!empty($_POST['id'])?(int)$_POST['id']:null); $_SESSION['flash']='اطلاعات ذخیره شد.'; redirect('admin',['tab'=>'martyrs','edit'=>$id]);
            case 'category': Security::requireRole($db,'content'); Db::insert($db,'categories',['name'=>Security::text($_POST,'name',150)]); Db::audit($db,'category.create',''); redirect('admin',['tab'=>'categories']);
            case 'images':
                Security::requireRole($db,'content'); $files=self::files('images'); if(!$files||count($files)>10) throw new HttpError(422,'از ۱ تا ۱۰ فایل در هر نوبت انتخاب کنید.'); $messages=[];
                foreach($files as $f){ try{ $id=Upload::image($db,Upload::receive($f),$f['name'],$_POST); $messages[]=$f['name'].' : ثبت/موجود '.$id; }catch(\Throwable $ex){ error_log('Upload: '.$ex->getMessage()); $messages[]=$f['name'].' : '.($ex instanceof HttpError?$ex->getMessage():'خطای پردازش؛ دوباره تلاش کنید'); } }
                $_SESSION['flash']=implode("\n",$messages); redirect('admin',['tab'=>'images']);
            case 'image-publish': Security::requireRole($db,'content'); Db::query($db,'UPDATE images SET published=? WHERE id=?',[(int)!empty($_POST['published']),(int)$_POST['id']]); Db::audit($db,'image.publish',(string)(int)$_POST['id']); redirect('admin',['tab'=>'images']);
            case 'import-preview': Security::requireRole($db,'content'); $files=self::files('excel'); if(count($files)!==1) throw new HttpError(422,'یک فایل XLSX انتخاب کنید.'); $_SESSION['import_rows']=Xlsx::read(Upload::receive($files[0])); redirect('admin',['tab'=>'import']);
            case 'import-commit': Security::requireRole($db,'content'); $result=Importer::commit($db,$_SESSION['import_rows']??[]); unset($_SESSION['import_rows']); $_SESSION['import_result']=$result; $_SESSION['flash']='ورود انجام شد: '.$result['inserted'].' جدید، '.$result['skipped'].' بدون تغییر، '.count($result['errors']).' خطا.'; redirect('admin',['tab'=>'import']);
            case 'request': Security::limit($db,'request',5,3600); $token=DesignRequests::create($db,$_POST,self::files('photos')); redirect('request-track',['token'=>$token]);
            case 'request-status': Security::requireRole($db,'content'); $status=Security::text($_POST,'status',40); if(!in_array($status,['submitted','reviewing','accepted','completed','rejected'],true)) throw new HttpError(422,'وضعیت نامعتبر است.'); Db::query($db,'UPDATE design_requests SET status=? WHERE id=?',[$status,(int)$_POST['id']]); Db::audit($db,'request.status',(string)(int)$_POST['id']); redirect('admin',['tab'=>'requests']);
            case 'cart': $shop->setQuantity($shop->cartToken(),(int)($_POST['variant_id']??0),(int)($_POST['quantity']??1)); redirect('cart');
            case 'checkout': Security::limit($db,'checkout',20); $token=$shop->checkout($shop->cartToken(),$_POST); redirect('order',['token'=>$token]);
            case 'test-pay': $token=Security::text($_POST,'token',64); $shop->pay($token,$_POST); redirect('order',['token'=>$token]);
            case 'order-status': Security::requireRole($db,'shop'); $shop->transition((int)$_POST['id'],Security::text($_POST,'status',40),Security::text($_POST,'tracking',100,false)); redirect('admin',['tab'=>'orders']);
            case 'return':
                Security::limit($db,'return',5); $token=Security::text($_POST,'token',64); $o=Db::query($db,"SELECT id FROM orders WHERE token=? AND status='delivered'",[$token])->fetchColumn(); if(!$o)throw new HttpError(422,'سفارش قابل مرجوعی نیست.');
                Db::insert($db,'return_requests',['order_id'=>$o,'reason'=>Security::text($_POST,'reason',3000),'created_at'=>date('Y-m-d H:i:s')]); $_SESSION['flash']='درخواست مرجوعی ثبت شد.'; redirect('order',['token'=>$token]);
            case 'product':
                Security::requireRole($db,'shop'); $price=filter_var($_POST['price']??null,FILTER_VALIDATE_INT); $stock=filter_var($_POST['stock']??null,FILTER_VALIDATE_INT);
                if($price===false||$price<1||$price>1000000000||$stock===false||$stock<0||$stock>1000000)throw new HttpError(422,'قیمت یا موجودی معتبر نیست.');
                Db::transaction($db,function()use($db,$price,$stock){
                    $id=Db::insert($db,'products',['title'=>Security::text($_POST,'title'),'category'=>Security::text($_POST,'category',100),'description'=>Security::text($_POST,'description',10000,false),'image_id'=>!empty($_POST['image_id'])?(int)$_POST['image_id']:null]);
                    Db::insert($db,'variants',['product_id'=>$id,'sku'=>Security::text($_POST,'sku',100),'attributes'=>Security::text($_POST,'attributes',255),'price'=>$price,'stock'=>$stock]); Db::audit($db,'product.create',(string)$id);
                }); redirect('admin',['tab'=>'products']);
            case 'stock': Security::requireRole($db,'shop'); $n=filter_var($_POST['stock']??null,FILTER_VALIDATE_INT); if($n===false||$n<0||$n>1000000)throw new HttpError(422,'موجودی معتبر نیست.'); Db::query($db,'UPDATE variants SET stock=? WHERE id=?',[$n,(int)$_POST['id']]); Db::audit($db,'stock.set',(string)(int)$_POST['id']); redirect('admin',['tab'=>'products']);
            default: throw new HttpError(404,'عملیات پیدا نشد.');
        }
    }
    private static function admin(PDO $db,array|false $user): void {
        if(!$user) redirect('login'); $tab=Security::text($_GET,'tab',30,false)?:($user['role']==='shop'?'orders':'martyrs');
        $area=in_array($tab,['products','orders','returns'],true)?'shop':($tab==='users'?'users':'content'); Security::requireRole($db,$area);
        $data=['db'=>$db,'tab'=>$tab,'user'=>$user];
        if($tab==='martyrs'){ $data['martyrs']=Db::query($db,'SELECT * FROM martyrs ORDER BY id DESC LIMIT 100')->fetchAll(); $data['editing']=!empty($_GET['edit'])?(new Archive($db))->find((int)$_GET['edit'],false):false; $data['categories']=Db::query($db,'SELECT * FROM categories')->fetchAll(); $data['selected']=$data['editing']?Db::query($db,'SELECT category_id FROM martyr_categories WHERE martyr_id=?',[$data['editing']['id']])->fetchAll(\PDO::FETCH_COLUMN):[]; }
        view('admin',$data);
    }
    private static function media(PDO $db,Archive $archive,string $page): void {
        $im=$archive->image((int)($_GET['id']??0)); if(!$im)throw new HttpError(404,'تصویر پیدا نشد.');
        if($page==='download'){ Security::limit($db,'download',120,60); $path=$im['original_path']; $mime=$im['mime']; Db::query($db,'UPDATE images SET downloads=downloads+1 WHERE id=?',[$im['id']]); $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]; $name=$im['code'].'-'.$im['sequence_no'].'.'.$ext; }
        else { $path=($_GET['size']??'')==='large'?$im['preview_path']:$im['thumb_path'];$mime='image/webp';$name='preview.webp'; }
        self::sendFile(Upload::absolute($path),$mime,$page==='download'?'attachment':'inline',$name);
    }
    private static function sendFile(string $path,string $mime,string $disposition,string $name): void {
        header('Content-Type: '.$mime); header('Content-Length: '.filesize($path)); header('Content-Disposition: '.$disposition.'; filename="'.$name.'"'); header('Cache-Control: private, no-store'); readfile($path);
    }
}


