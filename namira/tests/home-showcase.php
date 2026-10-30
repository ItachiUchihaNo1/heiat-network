<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Namira\{Db,Env,HomeShowcase as H,HttpError,Archive,Upload,AdminPanel,AdminPublishing,Artists,StoreManager,CallCampaigns};
$dir=BASE_PATH.'/storage/home-showcase-test-'.bin2hex(random_bytes(6));mkdir($dir,0700,true);putenv('STORAGE_PATH='.$dir);putenv('APP_ENV=testing');$dsn=getenv('TEST_SHOWCASE_DB_DSN')?:'sqlite:'.$dir.'/db.sqlite';
if(!str_starts_with($dsn,'sqlite:'.$dir.'/')&&!preg_match('/^mysql:.*dbname=namira_test_showcase(?:;|$)/D',$dsn))throw new RuntimeException('Isolated showcase database required');
putenv('DB_DSN='.$dsn);putenv('DB_USER='.(getenv('TEST_DB_USER')?:''));putenv('DB_PASSWORD='.(getenv('TEST_DB_PASSWORD')?:''));$db=Db::connect();$_SESSION=[];$checks=0;
function ok(bool $v,string $label):void{global $checks;if(!$v)throw new RuntimeException('FAIL '.$label);$checks++;echo 'PASS '.$label.PHP_EOL;}
function reject(callable $fn,int $status,string $label):void{try{$fn();}catch(HttpError $e){ok($e->status===$status,$label);return;}throw new RuntimeException('Expected rejection '.$label);}
function renderHome(PDO $db,bool $draft=false):string{$sections=H::sections($db,$draft);$page=$draft?'home-preview':'home';ob_start();view('home-showcase',compact('db','sections','page'));return ob_get_clean();}
set_error_handler(static function($n,$s,$f,$l){if(error_reporting()&$n)throw new ErrorException($s,0,$n,$f,$l);});
try {
 (require BASE_PATH.'/database/migrate.php')($db);$aid=Db::insert($db,'users',['email'=>'showcase-admin@example.test','password_hash'=>password_hash('showcase-admin-test-password',PASSWORD_DEFAULT),'role'=>'superadmin']);$actor=['id'=>$aid,'role'=>'superadmin','email'=>'showcase-admin@example.test'];$_SESSION['user_id']=$aid;AdminPanel::boot($db,$actor);
 $member=['id'=>Db::insert($db,'users',['email'=>'showcase-member@example.test','password_hash'=>'unused','role'=>'member']),'role'=>'member'];
 $a=new Archive($db);$person=$a->save(['code'=>'000123','first_name'=>'نام','last_name'=>'آزمون','published'=>1]);$privatePerson=$a->save(['code'=>'000124','first_name'=>'نام خصوصی','last_name'=>'منتشرنشده']);
 $source=$dir.'/fixture.png';$canvas=imagecreatetruecolor(220,330);$color=imagecolorallocate($canvas,130,31,55);imagefill($canvas,0,0,$color);imagepng($canvas,$source);imagedestroy($canvas);
 $image=Upload::attach($db,$person,$source,'000123-1.png',['title'=>'اثر عمومی']);AdminPublishing::publish($db,$actor,'media',$image);
 $canvas=imagecreatetruecolor(230,320);imagepng($canvas,$dir.'/private.png');imagedestroy($canvas);$privateImage=Upload::attach($db,$privatePerson,$dir.'/private.png','000124-1.png',['title'=>'اثر خصوصی']);AdminPublishing::publish($db,$actor,'media',$privateImage);Db::query($db,'UPDATE martyrs SET published=0 WHERE id=?',[$privatePerson]);
 $artists=new Artists($db,$actor);$artist=$artists->save(['display_name'=>'هنرمند عمومی','data'=>['summary'=>'معرفی عمومی','private_phone'=>'09120000000']]);AdminPublishing::publish($db,$actor,'artist',$artist);
 $privateArtist=$artists->save(['display_name'=>'هنرمند منتشرنشده']);
 $store=new StoreManager($db,$actor);$product=$store->product(['title'=>'محصول عمومی','delivery'=>'physical','production'=>'ready','pricing'=>'fixed','slug'=>'showcase-product','description'=>'محصول واقعی آزمون','published'=>1]);$store->variant($product,['sku'=>'SHOW-1','attributes'=>'مدل اول','price'=>120000,'stock'=>10,'active'=>1]);$privateProduct=$store->product(['title'=>'محصول مخفی','delivery'=>'physical','production'=>'ready','pricing'=>'fixed','slug'=>'showcase-hidden','published'=>0]);
 $call=CallCampaigns::save($db,$actor,['title'=>'فراخوان فعال','start_at'=>'2020-01-01 00:00:00','end_at'=>'2099-01-01 00:00:00','terms_text'=>'قوانین آزمون','accepting'=>1]);$ct=CallCampaigns::saveContentType($db,$actor,['title'=>'نوع آزمون','mode'=>'files','active'=>1]);CallCampaigns::saveSection($db,$actor,$call,['title'=>'بخش تصویر','content_type_id'=>$ct,'active'=>1]);$c=CallCampaigns::get($db,$call);CallCampaigns::state($db,$actor,$call,['status'=>'published','revision'=>$c['revision']]);
 $expired=CallCampaigns::save($db,$actor,['title'=>'فراخوان پایان‌یافته','end_at'=>'2020-01-01 00:00:00','terms_text'=>'قوانین']);CallCampaigns::saveSection($db,$actor,$expired,['title'=>'بخش تصویر','content_type_id'=>$ct,'active'=>1]);$c=CallCampaigns::get($db,$expired);CallCampaigns::state($db,$actor,$expired,['status'=>'published','revision'=>$c['revision']]);
 $initial=H::state($db);ok(count($initial['draft'])===7&&count(array_unique(array_column($initial['draft'],'type')))===7,'seven configurable default block types');
 ok(count(H::sections($db))===7,'all default sections resolve through public existing models');
 $coreBefore=Namira\DataBackup::counts($db);$draft=$initial['draft'];$draft[0]['title']='عنوان پیش‌نویس';$draft[0]['assets']=['desktop'=>['path'=>'private/forged.jpg']];
 $saved=H::save($db,$actor,['layout'=>json_encode($draft),'version'=>0,'intent'=>'draft']);
 ok($saved['version']===1&&$saved['published'][0]['title']!==$saved['draft'][0]['title'],'saved draft separate from public layout');
 ok(empty($saved['draft'][0]['assets']),'client cannot forge banner storage paths');
 ok(!str_contains(renderHome($db),'عنوان پیش‌نویس')&&str_contains(renderHome($db,true),'عنوان پیش‌نویس'),'public and admin preview read different stored layouts');
 reject(fn()=>H::save($db,$member,['layout'=>'[]','version'=>1]),403,'member cannot change showcase');
 reject(fn()=>H::save($db,$actor,['layout'=>'[]','version'=>0]),409,'stale save rejected without lost update');
 reject(fn()=>H::save($db,$actor,['layout'=>'not JSON','version'=>1]),422,'malformed layout rejected');
 $bad=$draft;$bad[0]['link']='javascript:alert(1)';reject(fn()=>H::save($db,$actor,['layout'=>json_encode($bad),'version'=>1]),422,'script destination rejected');
 foreach(['//foreign.test','https://user:password@example.test','/\\evil.test'] as $link)reject(fn()=>H::link($link),422,'unsafe destination rejected');
 $bad=$draft;$bad[1]['id']=$bad[0]['id'];reject(fn()=>H::save($db,$actor,['layout'=>json_encode($bad),'version'=>1]),422,'duplicate stable identifiers rejected');
 $bad=$draft;$bad[1]['count']=999;reject(fn()=>H::save($db,$actor,['layout'=>json_encode($bad),'version'=>1]),422,'unbounded content count rejected');
 $withBanner=H::save($db,$actor,['layout'=>json_encode($draft),'version'=>1,'intent'=>'draft'],['banner_hero001_desktop'=>$source,'banner_hero001_mobile'=>$source]);
 ok(count($withBanner['draft'][0]['assets'])===2,'independent responsive banner images stored');
 $asset=H::asset($db,'hero001','desktop',true);ok(is_file(Upload::absolute($asset['path']))&&$asset['mime']==='image/webp','banner converted to optimized private WebP');
 reject(fn()=>H::asset($db,'hero001','desktop'),404,'unpublished banner not public');
 $beforeFiles=glob($dir.'/home-banners/*');file_put_contents($dir.'/invalid.jpg','<?php invalid');reject(fn()=>H::save($db,$actor,['layout'=>json_encode($draft),'version'=>2],['banner_hero001_mobile'=>$dir.'/invalid.jpg']),422,'invalid uploaded image rejected');
 ok(H::state($db)['version']===2&&glob($dir.'/home-banners/*')===$beforeFiles,'failed image save preserves existing layout and files');
 $all=$withBanner['draft'];$published=H::save($db,$actor,['layout'=>json_encode($all),'version'=>2,'intent'=>'publish']);
 ok($published['published']===$published['draft']&&$published['published_at']!==null,'explicit publish atomically updates public layout');
 ok(count(glob($dir.'/backups/home-layout-*.json'))===1,'prior layout backed up before publication');
 ok(H::asset($db,'hero001','mobile')['path']===$asset['path']||isset($published['published'][0]['assets']['mobile']),'published banner readable through publication reference');
 $all[0]['remove_desktop']=true;$saved=H::save($db,$actor,['layout'=>json_encode($all),'version'=>3]);ok(isset(H::asset($db,'hero001','desktop')['path']),'removing a draft image preserves published image');
 $all=$saved['draft'];$dup=$all[1];$dup['id']='secondimages';$dup['mode']='manual';$dup['ids']=[$privateImage,$image];array_splice($all,2,0,[$dup]);$all[3]['mode']='manual';$all[3]['ids']=[$expired,$call];
 $all=array_reverse($all);$p=H::save($db,$actor,['layout'=>json_encode($all),'version'=>4,'intent'=>'publish']);
 ok(count($p['published'])===8&&$p['published'][0]['type']==='martyrs','duplicate block type and explicit order persist after reload');
 $manual=array_values(array_filter(H::sections($db),fn($b)=>$b['id']==='secondimages'))[0];ok(array_column($manual['items'],'id')==[$image],'manual media excludes private martyr association');
 ok(!H::content($db,'products',[$privateProduct])&&!H::content($db,'artists',[$privateArtist])&&!H::content($db,'martyrs',[$privatePerson]),'private products artists and martyrs remain excluded');
 ok(array_column(H::content($db,'calls',[$expired,$call]),'id')==[$call],'expired campaign excluded from manual selection');
 $artistPublic=H::content($db,'artists',[$artist])[0];ok(!isset($artistPublic['data']['private_phone']),'artist private contact not copied into public block');
 $html=renderHome($db);ok(!str_contains($html,'09120000000')&&!str_contains($html,'اثر خصوصی')&&!str_contains($html,'فراخوان پایان‌یافته'),'all public templates avoid private and expired content');
 ok(str_contains($html,'محصول عمومی')&&str_contains($html,fa('120,000'))&&str_contains($html,'فراخوان فعال'),'existing product price and campaign linked in template');
 $beforeTables=Namira\DataBackup::tables($db);ok(!array_filter($beforeTables,fn($t)=>str_starts_with($t,'home_')),'layout uses existing settings table without parallel content tables');
 foreach(['martyrs','images','products','artists','art_calls'] as $table)ok((int)Db::query($db,'SELECT COUNT(*) FROM '.$table)->fetchColumn()===$coreBefore[$table],'home operations preserve '.$table.' records');
 ok(isset(AdminPanel::navigation()['homepage'])&&AdminPanel::navigation()['homepage'][2]==='settings.manage','admin navigation guarded by existing settings permission');
 file_put_contents($dir.'/fixture.json',json_encode(['dsn'=>$dsn,'storage'=>$dir,'admin'=>$aid,'member'=>$member['id'],'image'=>$image,'product'=>$product,'artist'=>$artist,'martyr'=>$person,'call'=>$call]));if(str_starts_with($dsn,'sqlite:'))file_put_contents(BASE_PATH.'/storage/home-showcase-fixture.json',file_get_contents($dir.'/fixture.json'));
 echo $checks." showcase checks passed\n";
}finally{restore_error_handler();}
