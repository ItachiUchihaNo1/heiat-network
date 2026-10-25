<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class CommunityUI {
    public const STATES=['draft'=>'پیش‌نویس','pending'=>'در انتظار بررسی','submitted'=>'ارسال‌شده','reviewing'=>'در حال بررسی','needs_changes'=>'نیازمند اصلاح','approved'=>'تأییدشده','partial_approved'=>'پذیرش بخشی از اطلاعات','rejected'=>'ردشده','published'=>'منتشرشده','merged'=>'منتقل‌شده به پیش‌نویس','paused'=>'متوقف','archived'=>'بایگانی','revoked'=>'اتصال لغوشده'];
    public static function state(string $key):string{return self::STATES[$key]??$key;}
    public static function value(array $in,string $key,mixed $fallback=''):string {$v=$in[$key]??$fallback;return is_scalar($v)?(string)$v:'';}
    public static function errors(array $errors):void {if(!$errors)return;echo '<div class="notice notice-error" role="alert"><b>اطلاعات ثبت نشد؛ ورودی‌های شما حفظ شده‌اند.</b><ul>';foreach($errors as $error)echo '<li>'.e($error).'</li>';echo '</ul></div>';}
    public static function lookup(string $name,string $label,string $kind,array $options,int $selected=0,bool $private=false):void {
        echo '<div class="community-lookup" '.($private?'data-lookup':'data-public-lookup').'="'.e($kind).'"><label>'.e($label).'<input type="search" '.($private?'data-lookup-input':'data-public-lookup-input').' aria-label="جست‌وجوی '.e($label).'" placeholder="نام یا کد را جست‌وجو کنید"></label><div '.($private?'data-lookup-results':'data-public-results').' class="lookup-results" aria-live="polite"></div><select name="'.e($name).'" '.($private?'data-lookup-target':'data-public-target').' aria-label="'.e($label).'"><option value="">انتخاب نشده / پیشنهاد جدید</option>';foreach($options as $option)echo '<option value="'.(int)$option['id'].'" '.((int)$option['id']===$selected?'selected':'').'>'.e($option['label']).'</option>';echo '</select></div>';
    }
    public static function people(PDO $db,array $ids,bool $public=true):array {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));if(!$ids)return [];
        return array_map(fn($p)=>['id'=>$p['id'],'label'=>$p['code'].' · '.(($p['public_name']??'')?:trim($p['first_name'].' '.$p['last_name']))],Db::query($db,'SELECT m.id,m.code,m.first_name,m.last_name,p.public_name FROM martyrs m LEFT JOIN martyr_protocols p ON p.martyr_id=m.id WHERE m.id IN ('.implode(',',array_fill(0,count($ids),'?')).')'.($public?' AND m.published=1':''),$ids)->fetchAll());
    }
    public static function artists(PDO $db,array $ids,bool $public=true):array {
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));if(!$ids)return [];
        $rows=Db::query($db,'SELECT id,display_name,public_snapshot FROM artists WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).')'.($public?' AND public_visible=1 AND archived=0':''),$ids)->fetchAll();
        return array_map(fn($a)=>['id'=>$a['id'],'label'=>$public?(Protocol::decode($a['public_snapshot'])['display_name']??''):$a['display_name']],$rows);
    }
    public static function assets(array $assets):void {
        echo '<div class="incoming-grid">';foreach($assets as $a){$id=(int)($a['asset_id']??$a['id']);if(!$id)continue;echo '<article class="incoming-card"><a href="'.e(url('community-file',['id'=>$id])).'"><img loading="lazy" src="'.e(url('community-file',['id'=>$id,'size'=>'preview'])).'" alt="پیش‌نمایش فایل ارسالی"></a><p>'.fa($a['width'].' × '.$a['height']).' · '.fa(round($a['bytes']/1048576,2)).' مگابایت</p><a class="outline" href="'.e(url('community-file',['id'=>$id])).'">بررسی / دریافت اصل فایل</a></article>';}echo '</div>';
    }
    public static function history(PDO $db,string $kind,int $id):void {
        echo '<details class="panel"><summary>سابقه بررسی و اصلاحات (۵۰ مورد آخر)</summary>';foreach(CommunityHistory::rows($db,$kind,$id) as $h){$note=$h['note'];$decoded=json_decode($note,true);if(is_array($decoded))$note=json_encode($decoded,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);echo '<article class="community-history"><p><b>'.e(self::state($h['from_status'])).' ← '.e(self::state($h['to_status'])).'</b> · '.e($h['created_at']).'</p><pre>'.e($note).'</pre></article>';}echo '</details>';
    }
}
