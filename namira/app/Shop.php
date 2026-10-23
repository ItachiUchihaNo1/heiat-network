<?php
declare(strict_types=1);
namespace Namira;
use PDO;
final class Shop {
    public function __construct(private PDO $db) {}
    public function cartToken(): string {
        $token=$_SESSION['cart']??null;
        if(!is_string($token)||!Db::query($this->db,'SELECT token FROM carts WHERE token=?',[$token])->fetchColumn()){
            $token=bin2hex(random_bytes(32)); Db::insert($this->db,'carts',['token'=>$token,'created_at'=>date('Y-m-d H:i:s')]); $_SESSION['cart']=$token;
        } return $token;
    }
    public function items(string $token): array { return Db::query($this->db,'SELECT ci.quantity,v.*,p.title FROM cart_items ci JOIN variants v ON v.id=ci.variant_id JOIN products p ON p.id=v.product_id WHERE ci.cart_token=? AND p.published=1 ORDER BY v.id',[$token])->fetchAll(); }
    public function setQuantity(string $token,int $variant,int $quantity): void {
        if($quantity<0||$quantity>99) throw new HttpError(422,'تعداد نامعتبر است.');
        $v=Db::query($this->db,'SELECT v.* FROM variants v JOIN products p ON p.id=v.product_id WHERE v.id=? AND p.published=1',[$variant])->fetch();
        if(!$v||$quantity>(int)$v['stock']) throw new HttpError(422,'موجودی کافی نیست.');
        Db::transaction($this->db,function()use($token,$variant,$quantity){
            Db::query($this->db,'DELETE FROM cart_items WHERE cart_token=? AND variant_id=?',[$token,$variant]);
            if($quantity) Db::insert($this->db,'cart_items',['cart_token'=>$token,'variant_id'=>$variant,'quantity'=>$quantity]);
        });
    }
    public function checkout(string $cart,array $in): string {
        $gateway=new TestGateway(); // Real gateway intentionally unavailable until credentials and provider are supplied.
        $key=Security::text($in,'checkout_key',64);
        if(!preg_match('/^[a-f0-9]{64}$/D',$key)||!hash_equals($_SESSION['checkout_key']??'',$key)) throw new HttpError(422,'شناسه تسویه معتبر نیست.');
        $existing=Db::query($this->db,'SELECT token,cart_token FROM orders WHERE checkout_key=?',[$key])->fetch();
        if($existing){ if(!hash_equals($existing['cart_token'],$cart)) throw new HttpError(403,'اجازه دسترسی ندارید.'); return $existing['token']; }
        $phone=Security::phone(Security::text($in,'phone',20)); $postal=Security::normalize(Security::text($in,'postal_code',10));
        if(!preg_match('/^[0-9]{10}$/D',$postal)) throw new HttpError(422,'کدپستی باید ۱۰ رقم باشد.');
        $customer=Security::text($in,'customer',150); $address=Security::text($in,'address',2000); $code=Security::text($in,'discount_code',80,false);
        return Db::transaction($this->db,function()use($cart,$in,$key,$phone,$postal,$customer,$address,$code,$gateway){
            $items=$this->items($cart); if(!$items) throw new HttpError(422,'سبد خرید خالی است.');
            $subtotal=0;
            foreach($items as $item){
                $q=(int)$item['quantity']; $price=(int)($item['sale_price']??$item['price']);
                $changed=Db::query($this->db,'UPDATE variants SET stock=stock-? WHERE id=? AND stock>=?',[$q,$item['id'],$q])->rowCount();
                if(!$changed) throw new HttpError(409,'موجودی کالا تغییر کرده است.'); $subtotal+=$q*$price;
            }
            $discount=0;
            if($code!==''){
                $d=Db::query($this->db,'SELECT * FROM discounts WHERE code=?',[$code])->fetch();
                if(!$d||$d['expires']<date('Y-m-d H:i:s')||$subtotal<(int)$d['min_total']) throw new HttpError(422,'کد تخفیف معتبر نیست.');
                if(!Db::query($this->db,'UPDATE discounts SET uses=uses+1 WHERE code=? AND uses<max_uses',[$code])->rowCount()) throw new HttpError(422,'ظرفیت کد تخفیف پایان یافته است.');
                $discount=(int)floor($subtotal*(int)$d['percent']/100);
            }
            $shipping=max(0,(int)Env::get('SHIPPING_TOMAN','50000')); $token=bin2hex(random_bytes(32));
            $id=Db::insert($this->db,'orders',['token'=>$token,'checkout_key'=>$key,'cart_token'=>$cart,'customer'=>$customer,'phone'=>$phone,'address'=>$address,'postal_code'=>$postal,'subtotal'=>$subtotal,'discount'=>$discount,'shipping'=>$shipping,'total'=>$subtotal-$discount+$shipping,'discount_code'=>$code?:null,'status'=>'pending','created_at'=>date('Y-m-d H:i:s')]);
            foreach($items as $item) Db::insert($this->db,'order_items',['order_id'=>$id,'variant_id'=>$item['id'],'title'=>$item['title'],'attributes'=>$item['attributes'],'price'=>$item['sale_price']??$item['price'],'quantity'=>$item['quantity']]);
            Db::insert($this->db,'payments',['order_id'=>$id,'driver'=>$gateway->name(),'reference'=>bin2hex(random_bytes(24)),'status'=>'pending','amount'=>$subtotal-$discount+$shipping]);
            $this->history($id,'pending'); Db::query($this->db,'DELETE FROM cart_items WHERE cart_token=?',[$cart]); return $token;
        });
    }
    public function pay(string $token,array $in): void {
        $gateway=new TestGateway();
        Db::transaction($this->db,function()use($token,$in,$gateway){
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $o=Db::query($this->db,'SELECT * FROM orders WHERE token=?'.$lock,[$token])->fetch();
            if(!$o) throw new HttpError(404,'سفارش پیدا نشد.');
            $p=Db::query($this->db,'SELECT * FROM payments WHERE order_id=?',[$o['id']])->fetch();
            if($o['status']!=='pending'||$p['status']!=='pending') return; // Repeated callback is idempotent.
            $success=$gateway->verify($p,$in); $status=$success?'paid':'cancelled';
            Db::query($this->db,'UPDATE payments SET status=? WHERE id=?',[$success?'verified':'failed',$p['id']]);
            Db::query($this->db,'UPDATE orders SET status=? WHERE id=?',[$status,$o['id']]);
            if(!$success) $this->release($o); $this->history((int)$o['id'],$status);
        });
    }
    private function release(array $o): void {
        foreach(Db::query($this->db,'SELECT variant_id,quantity FROM order_items WHERE order_id=?',[$o['id']])->fetchAll() as $item) Db::query($this->db,'UPDATE variants SET stock=stock+? WHERE id=?',[$item['quantity'],$item['variant_id']]);
        if($o['discount_code']) Db::query($this->db,'UPDATE discounts SET uses=uses-1 WHERE code=? AND uses>0',[$o['discount_code']]);
    }
    public function transition(int $id,string $status,string $tracking=''): void {
        Db::transaction($this->db,function()use($id,$status,$tracking){
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $o=Db::query($this->db,'SELECT * FROM orders WHERE id=?'.$lock,[$id])->fetch();
            $allowed=['paid'=>['preparing','cancelled'],'preparing'=>['shipped','cancelled'],'shipped'=>['delivered'],'delivered'=>['returned'],'returned'=>['refunded']];
            if(!$o||!in_array($status,$allowed[$o['status']]??[],true)) throw new HttpError(422,'تغییر وضعیت مجاز نیست.');
            if($status==='shipped'&&$tracking==='') throw new HttpError(422,'کد رهگیری لازم است.');
            if($status==='refunded') throw new HttpError(503,'استرداد واقعی به اتصال درگاه و تأیید مالی نیاز دارد.');
            if($status==='cancelled') $this->release($o);
            Db::query($this->db,'UPDATE orders SET status=?,tracking=? WHERE id=?',[$status,$tracking?:$o['tracking'],$id]); $this->history($id,$status); Db::audit($this->db,'order.'.$status,(string)$id);
        });
    }
    public function expire(): int {
        $count=0;
        foreach(Db::query($this->db,"SELECT token FROM orders WHERE status='pending' AND created_at<?",[date('Y-m-d H:i:s',time()-1800)])->fetchAll() as $o){
            Db::transaction($this->db,function()use($o,&$count){
                $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
                $row=Db::query($this->db,'SELECT * FROM orders WHERE token=?'.$lock,[$o['token']])->fetch();
                if($row['status']!=='pending') return;
                Db::query($this->db,"UPDATE orders SET status='cancelled' WHERE id=?",[$row['id']]); Db::query($this->db,"UPDATE payments SET status='expired' WHERE order_id=?",[$row['id']]); $this->release($row); $this->history((int)$row['id'],'cancelled'); $count++;
            });
        } return $count;
    }
    public function history(int $id,string $status): void { Db::insert($this->db,'order_history',['order_id'=>$id,'status'=>$status,'created_at'=>date('Y-m-d H:i:s')]); }
}
