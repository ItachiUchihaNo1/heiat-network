<?php
declare(strict_types=1);
namespace Namira;
final class TestGateway implements PaymentGateway {
    public function __construct() {
        if(Env::get('APP_ENV')==='production'||Env::get('PAYMENT_DRIVER')!=='test') throw new HttpError(503,'پرداخت آزمایشی در محیط عملیاتی غیرفعال است.');
    }
    public function name(): string {return 'test';}
    public function verify(array $payment,array $input): bool {
        if(!hash_equals($payment['reference'],(string)($input['reference']??''))) throw new HttpError(422,'شناسه پرداخت نامعتبر است.');
        return ($input['result']??'')==='success';
    }
}
