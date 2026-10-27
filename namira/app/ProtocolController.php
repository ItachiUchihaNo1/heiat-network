<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class ProtocolController {
    public static function ensure(PDO $db,array $actor):void {
        if(ProtocolSchema::ready($db))return;
        if($actor['role']!=='superadmin')throw new HttpError(503,'مدیر اصلی یک بار بخش شهدا را باز کند تا به‌روزرسانی ساختار فرم انجام شود.');
        ProtocolSchema::migrate($db);
    }
    public static function action(PDO $db,string $action):never {
        $actor=Security::requireRole($db,'content');self::ensure($db,$actor);$p=new Protocol($db,$actor);
        $id=(int)($_POST['id']??0);$step=max(1,min(8,(int)($_POST['step']??1)));$panel=Security::text($_POST,'panel',20,false);
        try{
            switch($action){
                case 'protocol-quick':$id=$p->quick($_POST);$step=1;break;
                case 'protocol-save':$id=$p->save($id,$step,$_POST);break;
                case 'protocol-transition':$p->transition($id,Security::text($_POST,'status',30),$_POST);$step=8;break;
                case 'protocol-shared':$p->shared($panel,$_POST,!empty($_POST['shared_id'])?(int)$_POST['shared_id']:null);break;
                case 'protocol-term':self::term($db,$actor,$_POST);$panel='terms';break;
                default:throw new HttpError(404,'عملیات پیدا نشد.');
            }
            unset($_SESSION['protocol_feedback']);$_SESSION['flash']=$action==='protocol-transition'?'وضعیت پرونده به «'.Protocol::statusLabel((string)$_POST['status']).'» تغییر کرد.':'اطلاعات ذخیره شد. شناسه پرونده ثابت می‌ماند.';
        }catch(ProtocolErrors|HttpError $e){
            $_SESSION['protocol_feedback']=['input'=>$_POST,'status'=>$e instanceof HttpError?$e->status:422,'errors'=>$e instanceof ProtocolErrors?$e->errors:['form'=>$e->getMessage()],'id'=>$id,'step'=>max(1,min(8,(int)($_POST['return_step']??$step))),'panel'=>$panel];
        }
        $returnStep=(int)($_POST['return_step']??$step);if($returnStep<1||$returnStep>8)$returnStep=$step;$args=['tab'=>'martyrs','edit'=>$id,'step'=>$returnStep];if($panel)$args['panel']=$panel;redirect('admin',$args);
    }
    public static function show(PDO $db,array $actor):void {
        self::ensure($db,$actor);$p=new Protocol($db,$actor);$id=(int)($_GET['edit']??0);$step=max(1,min(8,(int)($_GET['step']??1)));$panel=Security::text($_GET,'panel',20,false);
        $bundle=$id?$p->get($id):null;$feedback=$_SESSION['protocol_feedback']??null;unset($_SESSION['protocol_feedback']);
        if($feedback&&($feedback['id']!==$id||$feedback['step']!==$step||$feedback['panel']!==$panel))$feedback=null;
        $fieldDefinitions=[];foreach([1,2,3,4,5,6,7,'source','event','event_location','education','affiliation','military','residence','burial','person_event','evidence'] as $part)$fieldDefinitions[$part]=$p->definitions($part);
        $terms=$p->terms();$sources=$p->sources();$events=$p->events();$errors=$feedback['errors']??[];$old=$feedback['input']??null;$feedbackStatus=$feedback['status']??422;
        $martyrs=Db::query($db,"SELECT m.id,m.code,m.first_name,m.last_name,m.published,p.display_name,p.status FROM martyrs m LEFT JOIN martyr_protocols p ON p.martyr_id=m.id ORDER BY m.id DESC LIMIT 100")->fetchAll();
        $warnings=$bundle?$p->warnings($bundle):[];$similar=$bundle?$p->similar($bundle):[];$preview=$bundle?$p->publicSnapshot($bundle,[]):[];
        view('protocol',compact('db','actor','id','step','panel','bundle','terms','sources','events','errors','old','martyrs','warnings','similar','preview','fieldDefinitions','feedbackStatus'));
    }
    public static function term(PDO $db,array $actor,array $in):int {
        if(MartyrTaxonomySchema::ready($db))return (new MartyrCatalogManager($db,$actor))->term($in);
        if($actor['role']!=='superadmin')throw new HttpError(403,'ویرایش فرهنگ‌ها فقط برای مدیر مجاز است.');
        $tax=Security::text($in,'taxonomy',40);if(!isset(ProtocolSpec::taxonomies()[$tax]))throw new HttpError(422,'فرهنگ معتبر نیست.');
        $title=Security::text($in,'title',200);$slug=Security::text($in,'slug',100);if(!preg_match('/^[a-z0-9_-]+$/D',$slug))throw new HttpError(422,'نامک لاتین معتبر لازم است.');
        $parent=!empty($in['parent_id'])?(int)$in['parent_id']:null;$pid=(int)($in['term_id']??0);
        if($parent){$t=Db::query($db,'SELECT * FROM protocol_terms WHERE id=?',[$parent])->fetch();$expected=['province'=>'country','county'=>'province','city'=>'county'][$tax]??$tax;if(!$t||$parent===$pid||$t['taxonomy']!==$expected)throw new HttpError(422,'والد فرهنگ سازگار نیست.');$seen=[$pid];$walk=$t;while($walk){if(in_array((int)$walk['id'],$seen,true))throw new HttpError(422,'چرخه در سلسله‌مراتب مجاز نیست.');$seen[]=(int)$walk['id'];$walk=$walk['parent_id']?Db::query($db,'SELECT * FROM protocol_terms WHERE id=?',[$walk['parent_id']])->fetch():null;}}
        if(in_array($tax,['province','county','city'],true)&&!$parent)throw new HttpError(422,'برای این سطح جغرافیایی، والد لازم است.');
        $source=!empty($in['source_id'])?(int)$in['source_id']:null;
        if($source&&!Db::query($db,'SELECT id FROM protocol_sources WHERE id=?',[$source])->fetchColumn())throw new HttpError(422,'منبع یافت نشد.');
        if($tax==='rank'&&!$source)throw new HttpError(422,'درجه فقط با عنوان دقیق و منبع معتبر وارد فرهنگ می‌شود.');
        $meta=['source_id'=>$source];if($tax==='collection'){$mode=$in['collection_mode']??'editorial';if(!in_array($mode,['editorial','filter'],true))throw new HttpError(422,'نوع مجموعه معتبر نیست.');$meta['mode']=$mode;$meta['filter_field']=Security::text($in,'filter_field',80,false);$meta['filter_term_id']=!empty($in['filter_term_id'])?(int)$in['filter_term_id']:null;if($mode==='filter'){if(!in_array($meta['filter_field'],['occupations','education_status','expertise','nationalities'],true)||!$meta['filter_term_id']||!Db::query($db,'SELECT id FROM protocol_terms WHERE id=?',[$meta['filter_term_id']])->fetchColumn())throw new HttpError(422,'شرط مجموعه مبتنی بر فیلتر کامل نیست.');}}
        $values=['taxonomy'=>$tax,'title'=>$title,'slug'=>$slug,'parent_id'=>$parent,'sort_order'=>(int)($in['sort_order']??0),'active'=>!empty($in['active'])?1:0,'aliases'=>Protocol::encode(array_values(array_filter(array_map('trim',preg_split('/\R/u',Security::text($in,'aliases',10000,false)))))),'metadata'=>Protocol::encode($meta)];
        return Db::transaction($db,function()use($db,$pid,$values,$actor){$before=$pid?Db::query($db,'SELECT * FROM protocol_terms WHERE id=?',[$pid])->fetch():null;if($pid&&!$before)throw new HttpError(404,'گزینه پیدا نشد.');if($before&&$before['taxonomy']!==$values['taxonomy'])throw new HttpError(422,'نوع فرهنگ گزینه موجود را تغییر ندهید.');if(Db::query($db,'SELECT id FROM protocol_terms WHERE taxonomy=? AND slug=? AND id<>?',[$values['taxonomy'],$values['slug'],$pid])->fetchColumn())throw new HttpError(422,'نامک در این فرهنگ تکراری است.');
            if($pid)Db::query($db,'UPDATE protocol_terms SET '.implode(',',array_map(fn($k)=>$k.'=?',array_keys($values))).' WHERE id=?',[...array_values($values),$pid]);else $pid=Db::insert($db,'protocol_terms',$values);
            Db::insert($db,'protocol_changes',['martyr_id'=>null,'user_id'=>$actor['id'],'field_key'=>'term.'.$pid,'before_value'=>Protocol::encode($before),'after_value'=>Protocol::encode($values),'reason'=>'مدیریت فرهنگ','created_at'=>date('Y-m-d H:i:s')]);return $pid;});
    }
}
