<?php

declare(strict_types=1);

namespace App\Traits;

/**
 * ValidatesExternalUrl — SSRF Mitigation Trait
 *
 * از این trait در هر Adapter یا Service که به URL خارجی HTTP call می‌کند
 * استفاده کنید تا از Server-Side Request Forgery (SSRF) جلوگیری شود.
 *
 * ── استفاده ──────────────────────────────────────────────────────────
 *   class MyAdapter {
 *       use ValidatesExternalUrl;
 *
 *       public function call(string $url): array {
 *           if (!$this->isExternalUrlSafe($url)) {
 *               throw new \RuntimeException('URL blocked: SSRF protection');
 *           }
 *           // ادامه HTTP call
 *       }
 *   }
 * ──────────────────────────────────────────────────────────────────────
 */
trait ValidatesExternalUrl
{
    /**
     * بررسی ایمن بودن URL برای HTTP call خارجی
     *
     * چه چیزهایی block می‌شوند:
     *   - localhost, 127.0.0.1, ::1, 0.0.0.0
     *   - IP های private (192.168.x.x, 10.x.x.x, 172.16-31.x.x)
     *   - IP های reserved و loopback
     *   - URL هایی که resolve نمی‌شوند
     *   - scheme های غیر http/https
     *   - اگر $allowedHosts غیرخالی باشد: دامنه‌ای که در allowlist نیست
     *
     * ترتیب الزامی است: اول SSRF (IP خصوصی/رزرو)، بعد allowlist.
     * allowlist خالی یعنی فقط کنترل SSRF (سازگاری عقب‌رو).
     *
     * @param list<string> $allowedHosts
     */
    protected function isExternalUrlSafe(string $url, array $allowedHosts = []): bool
    {
        if (empty($url)) {
            return false;
        }

        $parts = parse_url($url);

        // scheme باید http یا https باشد
        if (!$parts || !isset($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = $parts['host'];

        // Defensive check قبل از DNS resolution
        $forbiddenHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0', '[::]', '[::1]'];
        if (in_array(strtolower((string)$host), $forbiddenHosts, true)) {
            return false;
        }

        // حذف bracket های IPv6
        $cleanHost = trim($host, '[]');

        // بررسی مستقیم IPv4/IPv6 بدون نیاز به DNS
        if (filter_var($cleanHost, FILTER_VALIDATE_IP)) {
            $isPublic = (bool) filter_var(
                $cleanHost,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            return $isPublic && $this->hostIsAllowed($host, $allowedHosts);
        }

        // 🛡️ DNS Rebinding & Multi-IP Resolution Protection (Issue #11 Fix):
        // Resolve ALL A and AAAA records for the host and reject if ANY IP is private/reserved
        $ips = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($cleanHost, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $r) {
                    if (!empty($r['ip'])) $ips[] = $r['ip'];
                    if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
                }
            }
        }

        // Fallback to gethostbynamel
        if (empty($ips)) {
            $hostIps = @gethostbynamel($cleanHost);
            if (is_array($hostIps)) {
                $ips = $hostIps;
            } else {
                $singleIp = gethostbyname($cleanHost);
                if ($singleIp && $singleIp !== $cleanHost) {
                    $ips[] = $singleIp;
                }
            }
        }

        if (empty($ips)) {
            return false;
        }

        foreach ($ips as $ip) {
            $cleanIp = trim((string)$ip, '[]');
            $isValidPublic = filter_var(
                $cleanIp,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if (!$isValidPublic) {
                return false; // Reject if ANY resolved IP is in private/reserved range (SSRF / DNS Rebinding)
            }
        }

        return $this->hostIsAllowed($host, $allowedHosts);
    }

    /**
     * Optional hostname allowlist applied AFTER SSRF checks.
     * Comparison is exact and case-insensitive (no wildcards).
     *
     * @param list<string> $allowedHosts
     */
    protected function hostIsAllowed(string $host, array $allowedHosts): bool
    {
        if ($allowedHosts === []) {
            return true;
        }

        $normalizedHost = strtolower(trim($host, '[]'));
        foreach ($allowedHosts as $allowed) {
            if (!is_string($allowed)) {
                continue;
            }
            $normalizedAllowed = strtolower(trim($allowed));
            if ($normalizedAllowed !== '' && $normalizedAllowed === $normalizedHost) {
                return true;
            }
        }

        return false;
    }

    /**
     * بررسی و throw در صورت unsafe بودن URL
     * برای استفاده در جایی که exception مناسب‌تر از bool است
     */
    protected function assertExternalUrlSafe(string $url, string $context = 'HTTP call'): void
    {
        if (!$this->isExternalUrlSafe($url)) {
            throw new \RuntimeException(
                "SSRF Protection: URL blocked for {$context}: " . parse_url($url, PHP_URL_HOST)
            );
        }
    }
}
