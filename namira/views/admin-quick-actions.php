<?php if($db instanceof PDO&&$user&&Namira\Permissions::allows($db,$user,'dashboard.view')): ?>
<link rel="stylesheet" href="/assets/admin-shortcuts.css?v=20261114-1"><nav class="nm-admin-shortcuts" aria-label="دسترسی سریع مدیریت">
<?php if(Namira\Permissions::allows($db,$user,'martyrs.edit')): ?><a href="<?= e(url('admin',['tab'=>'martyrs','new'=>1])) ?>">+ تعریف شهید</a><?php endif ?>
<?php if(Namira\Permissions::allows($db,$user,'media.create')): ?><a href="<?= e(url('admin',['tab'=>'images']).'#media-upload') ?>">↑ بارگذاری تصویر</a><?php endif ?>
</nav>
<?php endif ?>
