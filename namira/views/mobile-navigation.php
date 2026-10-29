<?php
use Namira\{MobileNavigation as MobileNav,StoreOrderSchema,Security};
$mobileMenu=MobileNav::items($page,$user,$db instanceof PDO&&StoreOrderSchema::ready($db));
?>
<nav class="nm-bottom-nav" aria-label="ناوبری پایین موبایل">
<?php foreach($mobileMenu['bottom'] as $entry): ?>
<a class="nm-bottom-item<?= $entry['active']?' is-active':'' ?>" href="<?= e($entry['href']) ?>"<?= $entry['active']?' aria-current="page"':'' ?>><span class="nm-bottom-icon"><?= MobileNav::icon($entry['icon']) ?></span><span class="nm-bottom-label"><?= e($entry['label']) ?></span></a>
<?php endforeach ?>
</nav>
<dialog class="nm-drawer" id="mobile-navigation" aria-labelledby="mobile-navigation-title" data-mobile-drawer>
<div class="nm-drawer-header"><div><span class="nm-drawer-brand">نامیرا</span><h2 id="mobile-navigation-title">منوی نامیرا</h2></div><button class="nm-icon-button" type="button" data-mobile-close aria-label="بستن منو"><?= MobileNav::icon('close') ?></button></div>
<div class="nm-drawer-scroll">
<nav class="nm-drawer-links" aria-label="منوی کامل سایت">
<?php foreach($mobileMenu['groups'] as $group): ?>
<section class="nm-menu-group"><h3><?= e($group['title']) ?></h3>
<?php foreach($group['items'] as $entry): ?><a href="<?= e($entry['href']) ?>" data-mobile-page="<?= e($entry['page']) ?>"<?= $entry['fragment']?' data-mobile-fragment="gallery-filters"':'' ?><?= $entry['active']?' aria-current="page"':'' ?>><?= MobileNav::icon($entry['icon']) ?><span><?= e($entry['label']) ?></span></a><?php endforeach ?>
</section>
<?php endforeach ?>
</nav>
<?php if($user): ?><form method="post" class="nm-logout"><?= Security::field() ?><input type="hidden" name="action" value="logout"><button type="submit"><?= MobileNav::icon('logout') ?><span>خروج از حساب</span></button></form><?php endif ?>
</div>
</dialog>
