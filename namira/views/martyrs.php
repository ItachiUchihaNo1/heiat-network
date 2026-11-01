<link rel="stylesheet" href="/assets/home-showcase.css?v=014-1">
<section class="home-showcase martyr-directory"><div class="heading"><h1>شهدا</h1><span data-gallery-result-count><?= fa($result['count']) ?> شهید</span></div>
<?php $filterRoute='martyrs';$filterUnit='شهید';view('gallery-filter-bar',compact('db','f','catalogFilters','result','filterRoute','filterUnit')); ?>
<section data-gallery-results aria-live="polite"><?php view('martyr-results',compact('result','f')); ?></section></section>