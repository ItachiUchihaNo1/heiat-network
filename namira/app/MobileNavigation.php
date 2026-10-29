<?php
declare(strict_types=1);
namespace Namira;

final class MobileNavigation {
    public static function section(string $page):string {
        if(in_array($page,['gallery','image','profile'],true))return 'gallery';
        if(in_array($page,['artists','artist'],true))return 'artists';
        if(in_array($page,['shop','product','cart'],true))return 'shop';
        if(in_array($page,['account','member-login','login','store-account','store-order','order','contribute','request-track'],true))return 'account';
        return $page==='home'?'home':'';
    }
    public static function items(string $page,array|false $user,bool $storeReady):array {
        $current=self::section($page);
        $item=static fn(string $label,string $target,string $icon,array $params=[],string $fragment='',?bool $active=null):array=>[
            'label'=>$label,'href'=>url($target,$params).$fragment,'icon'=>$icon,
            'active'=>$active??($page===$target&&$fragment===''),'page'=>$target,'fragment'=>$fragment
        ];
        $account=$user?'account':'member-login';
        $bottom=[];
        foreach([['خانه','home','home'],['تصاویر','gallery','gallery'],['فروشگاه','shop','shop'],['هنرمندان','artists','palette'],['حساب',$account,'user']] as [$label,$target,$icon]){
            $entry=$item($label,$target,$icon);$entry['active']=$current===($label==='حساب'?'account':$target);$bottom[]=$entry;
        }
        $groups=[
            ['title'=>'بخش‌های اصلی','items'=>[
                $item('خانه','home','home'),$item('همه تصاویر','gallery','gallery',[], '',$current==='gallery'),
                $item('دسته‌بندی تصاویر و شهدا','gallery','categories',[],'#gallery-filters',false),
                $item('هنرمندان','artists','palette',[],'',$current==='artists'),$item('فراخوان‌ها','calls','megaphone')
            ]],
            ['title'=>'خدمات نامیرا','items'=>[
                $item('درخواست طراحی رایگان','request','pen'),$item('فروشگاه','shop','shop',[],'',$page==='shop'||$page==='product'),$item('سبد خرید','cart','cart')
            ]],
            ['title'=>'اطلاعات و راهنما','items'=>[$item('درباره نامیرا','about','info')]],
            ['title'=>'حساب کاربری','items'=>[$item($user?'حساب کاربری':'ورود / ثبت‌نام',$account,'user',[],'',$current==='account')]]
        ];
        if($storeReady){
            $groups[1]['items'][]=$item('سفارش‌های من','store-account','orders',['section'=>'orders'], '',in_array($page,['store-account','store-order'],true));
            foreach([['تماس با ما','contact','phone'],['سؤالات متداول','faq','help'],['قوانین و مقررات','sales_terms','shield']] as [$label,$section,$icon])
                $groups[2]['items'][]=$item($label,'store-info',$icon,['section'=>$section], '',$page==='store-info'&&($_GET['section']??'')===$section);
        }
        return compact('bottom','groups');
    }
    public static function icon(string $name):string {
        $paths=[
            'home'=>'<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
            'gallery'=>'<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 7"/>',
            'palette'=>'<path d="M12 3a9 9 0 1 0 0 18h1a2 2 0 0 0 1-3.7 1.5 1.5 0 0 1 1-2.8h2A4 4 0 0 0 21 10c0-4-4-7-9-7Z"/><circle cx="7.5" cy="10" r=".7"/><circle cx="11" cy="6.5" r=".7"/><circle cx="16" cy="8" r=".7"/><circle cx="7.5" cy="15" r=".7"/>',
            'shop'=>'<path d="M4 10v11h16V10M9 21v-7h6v7M3 6l2-3h14l2 3v3a3 3 0 0 1-5 2 3 3 0 0 1-4 0 3 3 0 0 1-4 0 3 3 0 0 1-5-2Z"/><path d="M3 6h18"/>',
            'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
            'categories'=>'<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
            'megaphone'=>'<path d="m3 9 16-6v18L3 15V9Zm4 7 2 5h3l-2-4M22 8v8"/>',
            'pen'=>'<path d="m15 4 5 5M4 20l5-1L21 7a2 2 0 0 0-5-4L4 15l-1 6Z"/>',
            'cart'=>'<path d="M2 3h3l3 12h11l3-9H6M8 18h11"/><circle cx="9" cy="21" r="1"/><circle cx="18" cy="21" r="1"/>',
            'orders'=>'<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3v3h6V3M9 11h6M9 15h6"/>',
            'info'=>'<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
            'phone'=>'<path d="m6 3 4 4-3 3a15 15 0 0 0 7 7l3-3 4 4-2 3C10 23 1 14 3 5l3-2Z"/>',
            'help'=>'<circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 1 1 5 2c-1 .5-2 1-2 3M12 17h.01"/>',
            'shield'=>'<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
            'logout'=>'<path d="M10 4H4v16h6M9 12h12m-4-4 4 4-4 4"/>',
            'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
            'close'=>'<path d="m6 6 12 12M6 18 18 6"/>'
        ];
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.($paths[$name]??$paths['info']).'</svg>';
    }
}
