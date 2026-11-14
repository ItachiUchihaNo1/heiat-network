<?php
declare(strict_types=1);
// Static loader: the atomic link is read on every request, even with OPcache enabled.
$pointer=__DIR__.'/deploy-state/current';clearstatcache(true,$pointer);$target=readlink($pointer);
if(!is_string($target)||!preg_match('~^\.\./releases/([a-f0-9]{40}-[a-f0-9]{12})$~D',$target,$match))throw new RuntimeException('Invalid application release pointer');
define('NAMIRA_RELEASE',$match[1]);header('X-Namira-Release: '.NAMIRA_RELEASE);
require __DIR__.'/releases/'.NAMIRA_RELEASE.'/app/bootstrap.php';
