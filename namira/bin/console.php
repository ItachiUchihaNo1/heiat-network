<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\{Db,Env,Archive,Upload,Shop,Xlsx};
if(PHP_SAPI!=='cli') { http_response_code(404); exit; }
try {
    $command=$argv[1]??'help'; $db=Db::connect();
    switch($command){
        case 'migrate': (require BASE_PATH.'/database/migrate.php')($db); \Namira\ProtocolSchema::migrate($db); \Namira\PlatformSchema::migrate($db); \Namira\CommunitySchema::migrate($db); \Namira\MartyrTaxonomySchema::migrate($db); echo "Migrations applied.\n"; break;
        case 'admin':
            $email=$argv[2]??''; $role=$argv[3]??'superadmin';
            if(!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($role,['superadmin','content','shop'],true)) throw new RuntimeException('Usage: php bin/console.php admin email@example.com superadmin|content|shop');
            fwrite(STDERR,"Enter a new password on stdin (minimum 12 characters; never put it in command arguments):\n"); $password=rtrim((string)fgets(STDIN),"\r\n");
            if(mb_strlen($password)<12) throw new RuntimeException('Password must contain at least 12 characters.');
            $id=Db::query($db,'SELECT id FROM users WHERE email=?',[$email])->fetchColumn();
            if($id) Db::query($db,'UPDATE users SET password_hash=?,role=?,active=1 WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$role,$id]);
            else Db::insert($db,'users',['email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role]);
            echo "Administrator saved.\n"; break;
        case 'disable-user': Db::query($db,'UPDATE users SET active=0 WHERE email=?',[$argv[2]??'']); echo "Account disabled.\n"; break;
        case 'seed':
            if(Env::get('APP_ENV')==='production') throw new RuntimeException('Demo seed is disabled in production.');
            $a=new Archive($db);
            foreach([['001254','محمد','رضایی','تهران'],['001255','علی','احمدی','اصفهان'],['001256','حسین','کریمی','خراسان رضوی']] as $row){
                if(Db::query($db,'SELECT id FROM martyrs WHERE code=?',[$row[0]])->fetchColumn()) continue;
                $id=$a->save(['code'=>$row[0],'first_name'=>$row[1],'last_name'=>$row[2],'province'=>$row[3],'city'=>$row[3],'summary'=>'داده آزمایشی برای بررسی سامانه؛ اطلاعات واقعی شهید نیست.','published'=>1]);
                // Deliberately neutral test artwork, not a fabricated portrait of a martyr.
                for($n=1;$n<=3;$n++){
                    $im=imagecreatetruecolor(550,620+$n*100); $bg=imagecolorallocate($im,220+$n*4,232,225); imagefill($im,0,0,$bg); $ink=imagecolorallocate($im,23,106,91); imagestring($im,5,30,40,'NAMIRA / TEST ASSET '.$row[0].'-'.$n,$ink);
                    imagefilledellipse($im,275,300,80+$n*20,80+$n*20,$ink); $file=tempnam(Env::storage(),'seed-'); imagejpeg($im,$file,90); imagedestroy($im);
                    try {Upload::image($db,$file,$row[0].'-'.$n.'.jpg',['title'=>'تصویر آزمایشی '.$n,'license_text'=>'دارایی آزمایشی سامانه؛ تصویر واقعی شهید نیست.']);} finally {unlink($file);}
                }
            }
            if(!Db::query($db,'SELECT id FROM products')->fetchColumn()){
                $pid=Db::insert($db,'products',['title'=>'تابلو آزمایشی نامیرا','category'=>'تابلو','description'=>'صرفاً برای آزمون خرید؛ محصول واقعی نیست.']);
                Db::insert($db,'variants',['product_id'=>$pid,'sku'=>'TEST-A3-WOOD','attributes'=>'A3 · قاب چوبی','price'=>250000,'stock'=>5]);
                Db::insert($db,'variants',['product_id'=>$pid,'sku'=>'TEST-A4-WOOD','attributes'=>'A4 · قاب چوبی','price'=>180000,'stock'=>3]);
            }
            echo "Test seed ready.\n"; break;
        case 'expire-orders': echo (new Shop($db))->expire()." expired orders released.\n"; break;
        case 'import': $rows=Xlsx::read($argv[2]??''); echo json_encode(Namira\Importer::commit($db,$rows),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n"; break;
        default: echo "Commands: migrate, admin EMAIL ROLE, disable-user EMAIL, seed, expire-orders, import FILE.xlsx\n";
    }
} catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
