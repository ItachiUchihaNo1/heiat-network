<?php
declare(strict_types=1);
namespace Namira;
final class BaleGateway implements PaymentGateway {
 public function __construct(){if(!BaleClient::activation())throw new HttpError(503,'اتصال پرداخت بله باید در تنظیمات فروشگاه فعال شود.');}
 public function name():string{return 'bale';}
 public function verify(array $payment,array $input):bool {throw new HttpError(403,'پرداخت بله فقط با استعلام معتبر سمت سرور تأیید می‌شود.');}
}
