<?php
declare(strict_types=1);
namespace Tests\Integration\Financial;
use Tests\Support\ResetsConfiguredRedis;
use App\Contracts\WalletServiceInterface; use App\Domain\Financial\Services\FinancialEscrowService; use App\Services\VitrineService; use Core\Container; use Core\Database; use PHPUnit\Framework\TestCase;
/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class VitrineEscrowCommissionInvariantTest extends TestCase {
    use ResetsConfiguredRedis;
 private Database $db; private WalletServiceInterface $wallet; private FinancialEscrowService $financial; private VitrineService $vitrine; private int $buyer=1; private int $seller=2; private int $ob;
 protected function setUp():void{parent::setUp();ini_set('error_log', sys_get_temp_dir() . '/chortke-financial-integration.log');config_set('app.key', 'testing-app-key-32-characters-long!!');$this->ob=ob_get_level();ob_start();try{$c=Container::getInstance();$this->db=$c->make(Database::class);$this->wallet=$c->make(WalletServiceInterface::class);$this->financial=$c->make(FinancialEscrowService::class);$this->vitrine=$c->make(VitrineService::class);$this->db->getPdo();$this->db->query("DELETE FROM score_events WHERE entity_type='user' AND domain='fraud' AND entity_id IN (1,2,3,4)");$this->db->query("UPDATE users SET fraud_score=0,is_blacklisted=0,status='active' WHERE id IN (1,2,3,4)");$this->db->query("UPDATE user_scores SET score=0 WHERE domain='fraud' AND user_id IN (1,2,3,4)");$this->resetConfiguredRedis([1,2,3,4]);}catch(\Throwable $e){$this->fail('Financial DB unavailable: '.$e->getMessage());}$this->db->beginTransaction();$this->setWallet($this->buyer,'500.00000000','0.00000000');$this->setWallet($this->seller,'0.00000000','0.00000000');}
 protected function tearDown():void{if(isset($this->db)&&$this->db->inTransaction())$this->db->rollback();while(isset($this->ob)&&ob_get_level()>$this->ob)ob_end_clean();parent::tearDown();}
 /** @test */ public function service_lock_and_seller_cancel_keep_listing_wallet_and_escrow_in_sync():void{
  $this->setWallet($this->buyer,'500.00000000','0.00000000');
  $this->setWallet($this->seller,'0.00000000','0.00000000');
  $this->db->query("INSERT INTO vitrine_listings (seller_id,title,status,category,platform,price_usdt,currency,created_at,updated_at) VALUES (?, ?, 'active', ?, ?, ?, 'usdt', NOW(), NOW())",[$this->seller,'integration vitrine','digital','web','20.0000']);
  $listingId=(int)$this->db->lastInsertId();
  $lock=$this->vitrine->lockEscrow($this->buyer,$listingId);
  $this->assertTrue((bool)($lock['success']??false),(json_encode($lock) ?: ''));
  $this->assertWallet($this->buyer,'480.00000000','20.00000000');
  $this->assertSame('in_escrow',(string)$this->db->fetchColumn('SELECT status FROM vitrine_listings WHERE id=?',[$listingId]));
  $this->assertSame($this->buyer,(int)$this->db->fetchColumn('SELECT buyer_id FROM vitrine_listings WHERE id=?',[$listingId]));
  $this->assertSame('in_escrow',(string)$this->db->fetchColumn("SELECT status FROM escrow_transactions WHERE order_id=? AND order_type='vitrine_listing'",[$listingId]));
  $cancel=$this->vitrine->cancelListing($this->seller,$listingId);
  $this->assertTrue((bool)($cancel['success']??false),(json_encode($cancel) ?: ''));
  $this->assertWallet($this->buyer,'500.00000000','0.00000000');
  $this->assertSame('cancelled',(string)$this->db->fetchColumn('SELECT status FROM vitrine_listings WHERE id=?',[$listingId]));
  $this->assertSame('refunded',(string)$this->db->fetchColumn("SELECT status FROM escrow_transactions WHERE order_id=? AND order_type='vitrine_listing'",[$listingId]));
 }
 /** @test */ public function release_splits_usdt_locked_funds_between_seller_and_platform():void{
  [$listing,$escrow,$hold]=$this->createEscrow('20.00000000');$r=$this->financial->releaseVitrineFunds($listing,$this->seller,'20.00000000','test-vitrine-'.bin2hex(random_bytes(8)));$this->assertTrue((bool)($r['ok']??false),(json_encode($r) ?: ''));$net=bcadd(str_value($r['net_amount']??''),'0',8);$fee=bcadd(str_value($r['commission']??''),'0',8);$this->assertSame('480.00000000',(string)$this->row($this->buyer)->balance_usdt);$this->assertSame('0.00000000',(string)$this->row($this->buyer)->locked_usdt);$this->assertSame($net,(string)$this->row($this->seller)->balance_usdt);$this->assertSame('released',(string)$this->db->fetchColumn('SELECT status FROM escrow_transactions WHERE id=?',[$escrow]));$this->assertSame('completed',(string)$this->db->fetchColumn('SELECT status FROM transactions WHERE transaction_id=?',[$hold]));$platform=(string)$this->db->fetchColumn("SELECT COALESCE(SUM(credit)-SUM(debit),0) FROM ledger_entries WHERE account='platform_revenue' AND currency='usdt'");$this->assertSame($fee,$platform);$clearing=(string)$this->db->fetchColumn("SELECT COALESCE(SUM(debit)-SUM(credit),0) FROM ledger_entries WHERE account='escrow_payout' AND currency='usdt'");$this->assertSame('0.00000000',$clearing);
 }
 private function assertWallet(int $id,string $balance,string $locked):void{$w=$this->db->fetch('SELECT balance_usdt,locked_usdt FROM wallets WHERE user_id=? FOR UPDATE',[$id]);$this->assertInstanceOf(\stdClass::class,$w);$this->assertSame($balance,(string)$w->balance_usdt);$this->assertSame($locked,(string)$w->locked_usdt);}
 /** @return array{0:int,1:int,2:string} */ private function createEscrow(string $a):array{$id=random_int(100000000,999999999);$h=$this->wallet->withdraw($this->buyer,$a,'usdt',['type'=>'vitrine_escrow','listing_id'=>$id,'ref_id'=>$id,'ref_type'=>'vitrine_listing','idempotency_key'=>'test-vh-'.bin2hex(random_bytes(8))]);$this->assertTrue((bool)($h['success']??false));$this->db->query("INSERT INTO escrow_transactions (order_id,order_type,buyer_id,seller_id,amount,currency,status,held_at,expires_at) VALUES (?, 'vitrine_listing', ?, ?, ?, 'usdt','in_escrow',NOW(),DATE_ADD(NOW(),INTERVAL 1 DAY))",[$id,$this->buyer,$this->seller,$a]);return[$id,(int)$this->db->lastInsertId(),str_value($h['transaction_id']??'')];}
 private function setWallet(int $id,string $b,string $l):void{$this->db->query('INSERT INTO wallets (user_id, balance_usdt, locked_usdt, is_frozen) VALUES (?, ?, ?, 0) ON DUPLICATE KEY UPDATE balance_usdt = VALUES(balance_usdt), locked_usdt = VALUES(locked_usdt), is_frozen = 0',[$id,$b,$l]);} private function row(int $id):\stdClass{$w=$this->db->fetch('SELECT balance_usdt,locked_usdt FROM wallets WHERE user_id=? FOR UPDATE',[$id]);$this->assertInstanceOf(\stdClass::class,$w);return$w;}
}
