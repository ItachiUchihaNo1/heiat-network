<?php
declare(strict_types=1);
namespace Namira;
final class ProtocolErrors extends \RuntimeException {
    public function __construct(public readonly array $errors){parent::__construct('اطلاعات فرم را بررسی کنید.');}
}
