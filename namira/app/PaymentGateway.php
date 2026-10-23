<?php
declare(strict_types=1);
namespace Namira;
interface PaymentGateway {
    public function name(): string;
    public function verify(array $payment,array $input): bool;
}
