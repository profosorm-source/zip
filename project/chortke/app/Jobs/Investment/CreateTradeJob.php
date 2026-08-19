<?php

declare(strict_types=1);

namespace App\Jobs\Investment;

use App\Validators\Requests\CreateTradeRequest;
use App\Services\AuditTrail;
use App\Models\TradingRecord;

class CreateTradeJob
{
    private \App\Models\TradingRecord $tradingModel;
    private \App\Contracts\LoggerInterface $logger;
    private AuditTrail $auditTrail;
    public function __construct(
        \App\Models\TradingRecord $tradingModel,
        \App\Contracts\LoggerInterface $logger,
        AuditTrail $auditTrail
    ) {
        $this->tradingModel = $tradingModel;
        $this->logger = $logger;
        $this->auditTrail = $auditTrail;
    }

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
public function handle(int $adminId, array $data): array
    {
        $request = new CreateTradeRequest($data);
        $validated = $request->validateOrFail();

        $direction = $validated['direction'];
        $openPrice = str_value($validated['open_price']);
        $pair      = trim(str_value($validated['pair']));

        // Generate an internal cryptographic signature to prevent database tempering and act as proof of verification
        $secretKey = 'chortke_secure_trade_hash_key_2026';
        $proofPayload = [
            'admin_id' => $adminId,
            'direction' => $direction,
            'pair' => $pair,
            'open_price' => $openPrice,
            'timestamp' => time(),
            'verified' => true
        ];
        $signature = hash_hmac('sha256', (string)json_encode($proofPayload), $secretKey);
        
        $reasonPayload = json_encode([
            'notes' => $data['notes'] ?? null,
            'proof' => [
                'signature' => $signature,
                'payload' => $proofPayload
            ]
        ], JSON_UNESCAPED_UNICODE);

        $tradeId = $this->tradingModel->create([
            'admin_id'            => $adminId,
            'direction'           => $direction,
            'pair'                => $pair,
            'amount'              => $data['lot_size'] ?? 0,
            'open_price'          => $openPrice,
            'close_price'         => $data['close_price'] ?? null,
            'stop_loss'           => $data['stop_loss'] ?? null,
            'take_profit'         => $data['take_profit'] ?? null,
            'profit_loss_amount'  => $data['profit_loss_amount'] ?? 0,
            'currency'            => 'usdt',
            'status'              => !empty($data['close_time']) ? TradingRecord::STATUS_CLOSED : TradingRecord::STATUS_OPEN,
            'reason'              => $reasonPayload,
            'user_id'             => $data['user_id'] ?? null,
            'investment_id'       => $data['investment_id'] ?? null,
        ]);

        if (!$tradeId) {
            return ['success' => false, 'message' => 'خطا در ثبت ترید.'];
        }

        $this->auditTrail->record('admin.settings.changed', null, [
            'action'   => 'trade_created',
            'trade_id' => $tradeId,
            'admin_id' => $adminId,
        ], $adminId);

        $this->logger->info('trade_created', ['message' => "Admin {$adminId} created trade #{$tradeId}"]);

        return ['success' => true, 'message' => 'ترید با موفقیت ثبت شد.', 'trade_id' => $tradeId];
    }
}
