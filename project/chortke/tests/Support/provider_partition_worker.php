<?php

declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap/testing.php';
[$script,$baseUrl,$resultFile]=$argv;
config_set('payment.zarinpal.api_url',rtrim($baseUrl,'/').'/v4/payment');
config_set('payment.zarinpal.payment_url','https://gateway.example.test/pay');
$c=\Core\Application::getInstance()->container;
$db=$c->make(\Core\Database::class);
$model=new class($db) extends \App\Models\PaymentGateway{
    public function getActiveGateway(string $name):?\stdClass{return (object)['merchant_id'=>'chaos-merchant','is_test_mode'=>false];}
};
$gateway=new \App\Services\Payment\ZarinPalGateway($model,$c->make(\Core\CircuitBreaker::class),$c->make(\App\Contracts\LoggerInterface::class));
$started=microtime(true);$result=$gateway->createPayment('12500','provider partition recovery','https://merchant.example/callback');
$result['elapsed']=microtime(true)-$started;
file_put_contents($resultFile,json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
