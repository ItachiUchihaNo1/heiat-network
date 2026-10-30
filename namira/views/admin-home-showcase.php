<?php use Namira\Security; ?>
<link rel="stylesheet" href="/assets/home-editor.css?v=013">
<section class="home-editor" data-home-editor>
<div class="he-intro"><div><h1>مدیریت صفحه اصلی</h1><p>بخش‌ها را انتخاب کنید، جابه‌جا کنید و ویترین نامیرا را بسازید.</p></div><a class="outline" href="<?= e(url('home')) ?>" target="_blank" rel="noopener">مشاهده سایت ↗</a></div>
<p class="notice">پیش‌نویس فقط برای مدیر قابل مشاهده است. «ذخیره و انتشار» چیدمان را مستقیماً روی سایت اعمال می‌کند. بخش‌های محتواییِ بدون نتیجه در سایت نمایش داده نمی‌شوند.</p>
<form id="home-editor-form" enctype="multipart/form-data" method="post">
<?= Security::field() ?><input type="hidden" name="action" value="home-showcase-save">
<div class="he-toolbar"><span id="he-save-state" role="status">نسخه <?= fa($state['version']) ?><?= $state['published_at']?' · انتشار: '.e($state['published_at']):' · چیدمان اولیه' ?></span><div><button type="button" class="outline" data-preview="390">پیش‌نمایش موبایل</button><button type="button" class="outline" data-preview="1200">پیش‌نمایش دسکتاپ</button><button type="button" class="outline" data-save="draft">ذخیره پیش‌نویس</button><button type="button" class="primary" data-save="publish">ذخیره و انتشار</button></div></div>
<p class="notice" id="he-feedback" role="alert" hidden></p><div id="he-blocks"></div>
<section class="panel he-add"><h2>افزودن بخش</h2><p>هر نوع بخش را می‌توانید چند بار اضافه کنید.</p><div><?php foreach(Namira\HomeShowcase::TYPES as $key=>$label): ?><button type="button" class="outline" data-add="<?= e($key) ?>"><?= e($label) ?> <span aria-hidden="true">＋</span></button><?php endforeach ?></div></section>
</form>
<dialog class="he-preview" id="he-preview"><div class="he-preview-bar"><b>پیش‌نمایش چیدمان ذخیره‌شده</b><button type="button" class="outline" data-close-preview>بستن ×</button></div><div class="he-preview-canvas"><iframe title="پیش‌نمایش صفحه اصلی نامیرا" id="he-frame"></iframe></div></dialog>
<script type="application/json" id="he-initial"><?= json_encode(['state'=>$state,'labels'=>$labels,'types'=>Namira\HomeShowcase::TYPES,'lookup'=>url('home-lookup'),'preview'=>url('home-preview')],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="/assets/home-editor.js?v=013" defer></script>
</section>
