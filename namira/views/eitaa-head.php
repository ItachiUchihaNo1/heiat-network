<?php
use Namira\{EitaaApp,Security,Db};
$eitaaUser=$user??false;$eitaaRow=false;
if($eitaaUser&&isset($db)&&$db instanceof PDO&&EitaaApp::ready($db))$eitaaRow=Db::query($db,'SELECT eitaa_id,welcome_state FROM eitaa_accounts WHERE user_id=?',[$eitaaUser['id']])->fetch();
$eitaaAuthenticated=$eitaaRow&&($eitaaRow['eitaa_id']===($_SESSION['eitaa_id']??null));
?>
<script src="https://developer.eitaa.com/eitaa-web-app.js"></script>
<meta name="namira-eitaa" data-csrf="<?= e(Security::csrf()) ?>" data-authenticated="<?= $eitaaAuthenticated?'1':'0' ?>" data-user="<?= $eitaaUser?'1':'0' ?>" data-welcome="<?= e($eitaaRow['welcome_state']??'pending') ?>">
<link rel="stylesheet" href="/assets/eitaa.css?v=20261116-1">
<script src="/assets/eitaa.js?v=20261116-1" defer></script>
