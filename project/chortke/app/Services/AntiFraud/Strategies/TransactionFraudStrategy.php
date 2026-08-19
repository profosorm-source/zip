<?php

declare(strict_types=1);

namespace App\Services\AntiFraud\Strategies;

use App\Contracts\AntiFraud\FraudCheckStrategyInterface;
use App\Contracts\LoggerInterface;
use App\Services\AntiFraud\VelocityCheckService;
use App\Services\AntiFraud\RateLimitingService;
use App\Services\AntiFraud\GeolocationIntelligenceService;
use App\Services\AntiFraud\AccountTakeoverService;
use App\Services\AntiFraud\DeviceIntelligenceService;
use App\Services\FeatureFlagService;

final class TransactionFraudStrategy implements FraudCheckStrategyInterface
{


    private \App\Contracts\LoggerInterface $logger;
    private VelocityCheckService $velocity;
    private RateLimitingService $rateLimiting;
    private GeolocationIntelligenceService $geoIntel;
    private AccountTakeoverService $ato;
    private DeviceIntelligenceService $deviceIntel;
    private FeatureFlagService $featureFlag;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        VelocityCheckService $velocity,
        RateLimitingService $rateLimiting,
        GeolocationIntelligenceService $geoIntel,
        AccountTakeoverService $ato,
        DeviceIntelligenceService $deviceIntel,
        FeatureFlagService $featureFlag
    ) {        $this->logger = $logger;
        $this->velocity = $velocity;
        $this->rateLimiting = $rateLimiting;
        $this->geoIntel = $geoIntel;
        $this->ato = $ato;
        $this->deviceIntel = $deviceIntel;
        $this->featureFlag = $featureFlag;

            }

    /**
     * @param array<string, mixed> $context
     */
    private function contextString(array $context, string $key, string $fallback = ''): string
    {
        $value = $context[$key] ?? null;
        return is_scalar($value) ? (string)$value : $fallback;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function contextArray(array $context, string $key): array
    {
        $value = $context[$key] ?? [];
        return is_array($value) ? $value : [];
    }

    /**
     * Financial transactions velocity and safety gates.
     */
    public function check(int $userId, string $action, array $context): array
    {
        $results = [];
        $ip = $this->contextString($context, 'ip', $this->clientIp());
        $ua = $this->contextString($context, 'user_agent', $this->userAgent());

        switch ($action) {
            case 'payment.create':
                // A. Velocity Checks (Payment limits, patterns)
                $results['velocity'] = $this->velocity->check($userId, 'deposit', $context);
                // B. Dynamic Rate Limiting
                $results['rate_limit'] = $this->rateLimiting->checkTokenBucket("payment:{$userId}", 'payment_attempt');
                break;

            case 'withdrawal.create':
                // A. Velocity Check
                $results['velocity'] = $this->velocity->check($userId, 'withdrawal', $context);
                // B. Geolocation Anomaly Checks
                $results['geolocation'] = $this->geoIntel->analyze($userId, $ip);
                // C. Check recent ATO flags
                $results['takeover'] = $this->ato->detect($userId, $ip, $ua);
                break;

            case 'wallet.transfer':
                // A. Velocity
                $results['velocity'] = $this->velocity->check($userId, 'transfer', $context);
                // B. Geolocation Anomaly Checks
                $results['geolocation'] = $this->geoIntel->analyze($userId, $ip);
                // C. Check recent ATO flags
                $results['takeover'] = $this->ato->detect($userId, $ip, $ua);
                // D. Dynamic Rate Limiting
                $results['rate_limit'] = $this->rateLimiting->checkTokenBucket("transfer:{$userId}", 'transfer_attempt');
                break;

            case 'crypto.deposit':
                // A. Velocity check
                $results['velocity'] = $this->velocity->check($userId, 'deposit', $context);
                // B. Heavy device analysis
                if (!$this->skipHeavyChecks($userId)) {
                    $deviceInfo = $this->contextArray($context, 'device_info');
                    if ($deviceInfo !== []) {
                        $results['device'] = $this->deviceIntel->comprehensiveAnalysis($deviceInfo);
                    }
                }
                break;

            case 'investment.pay':
            case 'ad.budget_withdraw':
            case 'banner.purchase':
            case 'seo.ad_budget':
            case 'lottery.participate':
            case 'prediction.bet':
            case 'scheduled_payment':
            case 'user.level_purchase':
            case 'vitrine.escrow':
            case 'influencer_order_create':
                // Financial commitments reserve or spend user funds. They receive
                // the same velocity, rate and account-takeover checks as transfers.
                $results['velocity'] = $this->velocity->check($userId, 'financial_commitment', $context);
                $results['rate_limit'] = $this->rateLimiting->checkTokenBucket("financial:{$userId}", 'financial_commitment');
                $results['geolocation'] = $this->geoIntel->analyze($userId, $ip);
                $results['takeover'] = $this->ato->detect($userId, $ip, $ua);
                break;
        }

        return $results;
    }

    private function skipHeavyChecks(?int $userId): bool
    {
        try {
            return (bool) $this->featureFlag->isEnabled('anti_fraud.heavy_checks_disabled', $userId);
        } catch (\Throwable $e) {
            $this->logger->error("anti_fraud.ff_circuit_breaker.failed", ['error' => $e->getMessage()]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, $userId ?? null, ['operation' => 'antifraud.transactionStrategy.featureFlagCheck']);
            return false;
        }
    }

    private function clientIp(): string
    {
        return get_client_ip();
    }

    private function userAgent(): string
    {
        return get_user_agent();
    }
}
