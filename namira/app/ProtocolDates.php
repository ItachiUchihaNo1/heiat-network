<?php
declare(strict_types=1);
namespace Namira;
final class ProtocolDates {
    public static function read(mixed $input):array {
        if(!is_array($input))throw new HttpError(422,'ساختار تاریخ معتبر نیست.');
        $cal=$input['calendar']??'jalali';$precision=$input['precision']??'unknown';
        if(!in_array($cal,['jalali','gregorian','hijri'],true)||!in_array($precision,['day','month','year','unknown'],true))throw new HttpError(422,'تقویم یا دقت تاریخ معتبر نیست.');
        $out=['calendar'=>$cal,'precision'=>$precision,'year'=>null,'month'=>null,'day'=>null,'is_approximate'=>!empty($input['is_approximate']),'original_text'=>Security::text($input,'original_text',1000,false)];
        foreach(['year'=>9999,'month'=>12,'day'=>31] as $key=>$max){$v=$input[$key]??'';if($v!==''&&$v!==null){$v=Security::normalize((string)$v);if(!ctype_digit($v)||(int)$v<1||(int)$v>$max)throw new HttpError(422,'جزء تاریخ معتبر نیست: '.$key);$out[$key]=(int)$v;}}
        $required=match($precision){'day'=>['year','month','day'],'month'=>['year','month'],'year'=>['year'],default=>[]};
        foreach(['year','month','day'] as $key){if(in_array($key,$required,true)&&$out[$key]===null)throw new HttpError(422,'تاریخ ناقص با دقت انتخاب‌شده سازگار نیست.');if(!in_array($key,$required,true)&&$out[$key]!==null)throw new HttpError(422,'دقت تاریخ را با اجزای واردشده هماهنگ کنید.');}
        if($out['year']!==null&&$cal==='jalali'&&$out['year']>3177)throw new HttpError(422,'سال شمسی خارج از محدوده الگوریتم معتبر است؛ متن منبع را بدون تبدیل ثبت کنید.');
        if($precision==='day'){
            $max=$cal==='gregorian'?(checkdate($out['month'],$out['day'],$out['year'])?31:0):($cal==='hijri'?30:($out['month']<=6?31:($out['month']<=11?30:(self::jalaliLeap($out['year'])?30:29))));
            if($out['day']>$max)throw new HttpError(422,'تاریخ نامعتبر است.');
        }return $out;
    }
    // Leap calculation adapted from MIT-licensed jalaali-js; no implicit calendar conversion.
    public static function jalaliLeap(int $year):bool {
        $breaks=[-61,9,38,199,426,686,756,818,1111,1181,1210,1635,2060,2097,2192,2262,2324,2394,2456,3178];$previous=$breaks[0];$jump=0;
        foreach(array_slice($breaks,1) as $next){$jump=$next-$previous;if($year<$next)break;$previous=$next;}
        $n=$year-$previous;if($jump-$n<6)$n=$n-$jump+intdiv($jump+4,33)*33;$leap=(($n+1)%33-1)%4;if($leap===-1)$leap=4;return $leap===0;
    }
    public static function exact(array $d):bool{return ($d['precision']??'unknown')==='day'&&!($d['is_approximate']??false);}
    public static function compare(array $a,array $b):?int {if(!self::exact($a)||!self::exact($b)||$a['calendar']!==$b['calendar'])return null;return [$a['year'],$a['month'],$a['day']]<=>[$b['year'],$b['month'],$b['day']];}
    public static function label(array $d):string {if(($d['precision']??'unknown')==='unknown')return ($d['original_text']??'')?:'نامشخص';$parts=[];foreach(['year','month','day'] as $k)if(($d[$k]??null)!==null)$parts[]=$d[$k];return implode('/',$parts).' '.(['jalali'=>'شمسی','gregorian'=>'میلادی','hijri'=>'قمری'][$d['calendar']]??'').(!empty($d['is_approximate'])?'؛ تقریبی':'');}
    public static function age(array $birth,array $death):string {
        if(empty($birth['year'])||empty($death['year'])||$birth['calendar']!==$death['calendar'])return 'با داده موجود قابل محاسبه نیست';$years=$death['year']-$birth['year'];if($years<0)return 'نیازمند بررسی تعارض تاریخ‌ها';
        if(self::exact($birth)&&self::exact($death))return (string)($years-([$death['month'],$death['day']]<[$birth['month'],$birth['day']]?1:0)).' سال در تقویم ثبت‌شده';
        return max(0,$years-1).' تا '.($years+(!empty($birth['is_approximate'])||!empty($death['is_approximate'])?1:0)).' سال؛ بازه تقریبی در تقویم ثبت‌شده';
    }
}
