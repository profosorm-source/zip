<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Contracts\LoggerInterface;
use App\Models\IpAndDeviceModel;

class IPQualityService
{
    private IpAndDeviceModel $model;
    public function __construct(
        IpAndDeviceModel $model
    ) {        $this->model = $model;

            }

    /**
     * @return array<string, mixed>
     */
    public function check(string $ip): array
    {
        // Loopback / Private local IPs used in development and test suites are clean
        if (in_array($ip, ['127.0.0.1', '::1', 'localhost', '0.0.0.0'], true) ||
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return [
                'status' => 'clean',
                'score' => 0,
                'risk_score' => 0,
                'fraud_score' => 0,
                'is_tor' => false,
                'is_vpn' => false,
                'is_proxy' => false,
                'is_datacenter' => false,
                'is_suspicious' => false,
                'reasons' => [],
            ];
        }

        $isTor = $this->model->isTorNode($ip);
        $isVPN = $this->checkVPNRanges($ip);
        $isDatacenter = $this->checkDatacenterIP($ip);
        
        $score = 0;
        $reasons = [];
        
        if ($isTor) {
            $score = 100;
            $reasons[] = 'Tor Exit Node';
        } elseif ($isVPN) {
            $score = 70;
            $reasons[] = 'Commercial VPN';
        } elseif ($isDatacenter) {
            $score = 50;
            $reasons[] = 'Datacenter IP';
        }
        
        return [
            'status' => $score > 0 ? 'suspicious' : 'clean',
            'score' => $score,
            'risk_score' => $score,
            'fraud_score' => $score,
            'is_tor' => $isTor,
            'is_vpn' => $isVPN,
            'is_proxy' => $isTor || $isVPN,
            'is_datacenter' => $isDatacenter,
            'is_suspicious' => $score >= 50,
            'reasons' => $reasons,
        ];
    }

    private function checkVPNRanges(string $ip): bool
    {
        $ranges = $this->model->getSuspiciousIpRanges();
        foreach ($ranges as $range) {
            if (isset($range->ip_range) && $this->ipInRange($ip, $range->ip_range)) {
                return true;
            }
        }
        return false;
    }

    private function checkDatacenterIP(string $ip): bool
    {
        $ranges = config('anti_fraud.datacenter_ip_ranges', []);
        foreach ((array)$ranges as $range) {
            if ($this->ipInRange($ip, str_value($range))) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }
        [$subnet, $mask] = explode('/', $range);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }
        $maskLong = -1 << (32 - (int)$mask);
        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkIp(string $ip): array
    {
        return $this->check($ip);
    }

    public function blacklistIP(string $ip, string $reason, ?int $duration = null): void
    {
        $expiresAt = $duration ? date('Y-m-d H:i:s', time() + $duration) : null;
        $this->model->blacklistIp($ip, $reason, $expiresAt);
    }
}
