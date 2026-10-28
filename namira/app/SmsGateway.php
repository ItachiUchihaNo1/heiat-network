<?php
declare(strict_types=1);
namespace Namira;
interface SmsGateway { public function send(string $mobile,string $code):void; }
