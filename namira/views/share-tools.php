<?php $shortUrl=Namira\ShortLinks::url($sharePage,(int)$shareId); ?>
<link rel="stylesheet" href="/assets/share.css?v=20261112-1">
<script src="/assets/share.js?v=20261112-1" defer></script>
<section class="nm-share" aria-label="اشتراک‌گذاری" data-share-tools data-share-title="<?= e($shareTitle) ?>">
<label class="nm-share-link">لینک کوتاه<input type="text" dir="ltr" readonly value="<?= e($shortUrl) ?>" aria-label="لینک کوتاه؛ برای انتخاب و کپی"></label>
<div class="nm-share-buttons"><button class="primary" type="button" data-share-native>اشتراک‌گذاری</button><button class="outline" type="button" data-share-copy>کپی لینک</button></div>
<span class="nm-share-status" role="status" aria-live="polite" data-share-status></span>
</section>
