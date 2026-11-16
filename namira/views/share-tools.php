<?php $shareUrl=Namira\DomainRouting::absolute($sharePage,['id'=>(int)$shareId]); ?>
<link rel="stylesheet" href="/assets/share.css?v=021"><script src="/assets/share.js?v=021" defer></script>
<div class="nm-share" role="group" aria-label="اشتراک‌گذاری" data-share-tools data-share-title="<?= e($shareTitle) ?>" data-share-url="<?= e($shareUrl) ?>">
<button class="nm-share-action" type="button" data-share-native aria-label="اشتراک‌گذاری" title="اشتراک‌گذاری"><?= Namira\MobileNavigation::icon('share') ?></button>
<button class="nm-share-action" type="button" data-share-copy aria-label="کپی لینک" title="کپی لینک"><?= Namira\MobileNavigation::icon('copy') ?></button>
<span class="nm-share-status" role="status" aria-live="polite" data-share-status></span>
</div>
