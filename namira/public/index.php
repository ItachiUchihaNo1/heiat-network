<?php
declare(strict_types=1);
// cPanel deployment changes only this bootstrap path in the public copy.
require dirname(__DIR__).'/app/bootstrap.php';
Namira\Web::run();
