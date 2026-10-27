<?php if(!$result['items']): ?><div class="empty"><p>تصویری با این فیلترها پیدا نشد.</p><a class="outline" href="<?= e(url('gallery')) ?>" data-gallery-reset>پاک‌کردن همه فیلترها</a></div><?php else:view('cards',['images'=>$result['items']]);endif ?>
<?php view('public-pagination',['count'=>$result['count'],'per'=>24,'page'=>max(1,(int)($f['p']??1)),'route'=>'gallery','filters'=>$f]); ?>
