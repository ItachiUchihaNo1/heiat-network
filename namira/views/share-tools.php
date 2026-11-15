<?php $shortUrl=Namira\ShortLinks::url($sharePage,(int)$shareId); ?>
<link rel="stylesheet" href="/assets/share.css?v=018-ui">
<script src="/assets/share.js?v=018-ui" defer></script>
<section class="nm-share" aria-label="اشتراک‌گذاری" data-share-tools data-share-title="<?= e($shareTitle) ?>">
<button class="nm-share-action" type="button" data-share-native><?= Namira\MobileNavigation::icon('share') ?><span>اشتراک‌گذاری</span></button>
<button class="nm-share-action" type="button" data-share-copy><?= Namira\MobileNavigation::icon('copy') ?><span>کپی لینک</span></button>
<details class="nm-share-details"><summary><?= Namira\MobileNavigation::icon('link') ?><span>لینک کوتاه</span></summary><div class="nm-share-popover"><label>لینک کوتاه این صفحه<input type="text" dir="ltr" readonly value="<?= e($shortUrl) ?>" aria-label="لینک کوتاه؛ برای انتخاب و کپی"></label></div></details>
<span class="nm-share-status" role="status" aria-live="polite" data-share-status></span>
</section>
