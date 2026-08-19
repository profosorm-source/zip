<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\VelocityAndScoreModel;
use App\Contracts\LoggerInterface;
/**
 * EmailPhoneIntelligenceService
 *
 * تحلیل هوشمند ایمیل و شماره تلفن
 *
 * @phpstan-type EmailResult array<string, mixed>
 * @phpstan-type EmailStorage array{is_disposable: bool|int, is_free_provider: bool|int, mx_records_valid: bool|int}
 * @phpstan-type PhoneStorage array{country_code: string, line_type: string, is_voip: bool|int}
 * @phpstan-type MxResult array{valid: bool, records: list<string>}
 */
class EmailPhoneIntelligenceService
{
    private VelocityAndScoreModel $model;
    private const DISPOSABLE_DOMAINS = [
        'tempmail.com', 'guerrillamail.com', '10minutemail.com', 'mailinator.com',
        'throwaway.email', 'temp-mail.org', 'maildrop.cc', 'getnada.com',
        'mohmal.com', 'dispostable.com', 'yopmail.com', 'fakeinbox.com'
    ];
    
    private const FREE_EMAIL_PROVIDERS = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com',
        'icloud.com', 'mail.com', 'protonmail.com', 'gmx.com', 'zoho.com'
    ];

    private \App\Contracts\LoggerInterface $logger;
    public function __construct(
        \App\Contracts\LoggerInterface $logger,
        VelocityAndScoreModel $model
    ) {        $this->logger = $logger;

                $this->model = $model;
    }

    private function rowString(object $row, string $field, string $default = ''): string
    {
        $value = get_object_vars($row)[$field] ?? null;
        return is_scalar($value) ? (string)$value : $default;
    }

    private function rowBool(object $row, string $field): bool
    {
        return filter_var(get_object_vars($row)[$field] ?? null, FILTER_VALIDATE_BOOLEAN);
    }

    private function stringValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string)$value : $default;
    }

    /**
     * تحلیل کامل ایمیل
     */
    /** @return EmailResult */
    public function analyzeEmail(string $email): array
    {
        $email = strtolower(trim((string)$email));
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'is_valid' => false,
                'error' => 'فرمت ایمیل نامعتبر است'
            ];
        }
        
        [$localPart, $domain] = explode('@', $email);
        
        $cached = $this->getEmailFromCache($email);
        if ($cached) {
            return $cached;
        }
        
        $analysis = [
            'email' => $email,
            'domain' => $domain,
            'local_part' => $localPart,
            'is_valid' => true
        ];
        
        $analysis['is_disposable'] = $this->isDisposableEmail($domain);
        $analysis['is_free_provider'] = $this->isFreeEmailProvider($domain);
        
        $mxCheck = $this->checkMXRecords($domain);
        $analysis['mx_records_valid'] = $mxCheck['valid'];
        $analysis['mx_records'] = $mxCheck['records'];
        
        $analysis['risk_score'] = $this->calculateEmailRiskScore($analysis);
        $analysis['is_suspicious'] = $analysis['risk_score'] >= 60;
        
        $this->saveEmailToCache($email, $domain, $analysis);
        
        return $analysis;
    }

    private function isDisposableEmail(string $domain): bool
    {
        if (in_array($domain, self::DISPOSABLE_DOMAINS)) {
            return true;
        }
        
        $result = $this->model->getDomainIntelligence($domain);
        return $result instanceof \stdClass && $this->rowBool($result, 'is_disposable');
    }

    private function isFreeEmailProvider(string $domain): bool
    {
        return in_array($domain, self::FREE_EMAIL_PROVIDERS);
    }

    /** @return MxResult */
    private function checkMXRecords(string $domain): array
    {
        $normalizedDomain = strtolower(trim($domain));
        $isLocalEnv = str_value(config('app.env', env('APP_ENV', 'production'))) === 'local';
        $isNonRoutableDomain = $normalizedDomain === 'chortke.ir'
            || str_ends_with($normalizedDomain, '.ir')
            || str_ends_with($normalizedDomain, '.test')
            || str_ends_with($normalizedDomain, '.local');

        if ($isLocalEnv || $isNonRoutableDomain) {
            return [
                'valid' => true,
                'records' => [],
            ];
        }

        $mxRecords = [];
        $valid = @getmxrr($domain, $mxRecords);
        
        return [
            'valid' => $valid && !empty($mxRecords),
            'records' => $mxRecords
        ];
    }

    /** @param EmailResult $analysis */
    private function calculateEmailRiskScore(array $analysis): int
    {
        $score = 0;
        
        if ($analysis['is_disposable'] ?? false) {
            $score += 80;
        }
        
        if ($analysis['is_free_provider'] ?? false) {
            $score += 15;
        }
        
        if (!($analysis['mx_records_valid'] ?? true)) {
            $score += 70;
        }
        
        $localPart = $this->stringValue($analysis['local_part'] ?? null);
        if (strlen($localPart) < 3 || strlen($localPart) > 50) {
            $score += 20;
        }
        
        if (preg_match_all('/\d/', $localPart) > 5) {
            $score += 15;
        }
        
        return min(100, $score);
    }

    /**
     * تحلیل شماره تلفن
     */
    /** @return EmailResult */
    public function analyzePhone(string $phone): array
    {
        $normalizedPhone = preg_replace('/[^0-9+]/', '', $phone);
        $phone = is_string($normalizedPhone) ? $normalizedPhone : '';
        
        if (empty($phone)) {
            return [
                'is_valid' => false,
                'error' => 'فرمت شماره تلفن نامعتبر است'
            ];
        }
        
        $cached = $this->getPhoneFromCache($phone);
        if ($cached) {
            return $cached;
        }
        
        $analysis = [
            'phone' => $phone,
            'is_valid' => true
        ];
        
        $analysis['country_code'] = $this->extractCountryCode($phone);
        $analysis['is_voip'] = false;
        $analysis['line_type'] = 'unknown';
        
        $analysis['risk_score'] = $this->calculatePhoneRiskScore($analysis);
        $analysis['is_suspicious'] = $analysis['risk_score'] >= 60;
        
        $this->savePhoneToCache($phone, $analysis);
        
        return $analysis;
    }

    private function extractCountryCode(string $phone): ?string
    {
        if (strpos($phone, '+98') === 0) {
            return 'IR';
        } elseif (strpos($phone, '+1') === 0) {
            return 'US';
        } elseif (strpos($phone, '+44') === 0) {
            return 'GB';
        }
        
        return null;
    }

    /** @param EmailResult $analysis */
    private function calculatePhoneRiskScore(array $analysis): int
    {
        $score = 0;
        
        if ($analysis['is_voip'] ?? false) {
            $score += 60;
        }
        
        if (($analysis['line_type'] ?? '') === 'voip') {
            $score += 50;
        }
        
        $length = strlen($this->stringValue($analysis['phone'] ?? null));
        if ($length < 8 || $length > 15) {
            $score += 30;
        }
        
        return min(100, $score);
    }

    /** @return ?EmailResult */
    private function getEmailFromCache(string $email): ?array
    {
        $result = $this->model->getEmailFromCache($email);
        
        if (!$result) {
            return null;
        }
        
        $isDisposable = $this->rowBool($result, 'is_disposable');
        $isFreeProvider = $this->rowBool($result, 'is_free_provider');
        $mxRecordsValid = $this->rowBool($result, 'mx_records_valid');
        return [
            'email' => $this->rowString($result, 'email', $email),
            'domain' => $this->rowString($result, 'domain'),
            'is_disposable' => $isDisposable,
            'is_free_provider' => $isFreeProvider,
            'mx_records_valid' => $mxRecordsValid,
            'domain_reputation_score' => (int)$this->rowString($result, 'domain_reputation_score', '0'),
            'risk_score' => $this->calculateEmailRiskScore([
                'is_disposable' => $isDisposable,
                'is_free_provider' => $isFreeProvider,
                'mx_records_valid' => $mxRecordsValid,
                'local_part' => explode('@', $email)[0],
            ]),
            'from_cache' => true,
        ];
    }

    /** @param EmailResult $analysis */
    private function saveEmailToCache(string $email, string $domain, array $analysis): void
    {
        /** @var EmailStorage $storage */
        $storage = [
            'is_disposable' => filter_var($analysis['is_disposable'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'is_free_provider' => filter_var($analysis['is_free_provider'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'mx_records_valid' => filter_var($analysis['mx_records_valid'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
        $this->model->saveEmailToCache($email, $domain, $storage);
    }

    /** @return ?EmailResult */
    private function getPhoneFromCache(string $phone): ?array
    {
        $result = $this->model->getPhoneFromCache($phone);
        
        if (!$result) {
            return null;
        }
        
        return [
            'phone' => $this->rowString($result, 'phone', $phone),
            'country_code' => $this->rowString($result, 'country_code'),
            'carrier' => $this->rowString($result, 'carrier'),
            'line_type' => $this->rowString($result, 'line_type', 'unknown'),
            'is_voip' => $this->rowBool($result, 'is_voip'),
            'is_valid' => $this->rowBool($result, 'is_valid'),
            'from_cache' => true,
        ];
    }

    /** @param EmailResult $analysis */
    private function savePhoneToCache(string $phone, array $analysis): void
    {
        /** @var PhoneStorage $storage */
        $storage = [
            'country_code' => $this->stringValue($analysis['country_code'] ?? null),
            'line_type' => $this->stringValue($analysis['line_type'] ?? null, 'unknown'),
            'is_voip' => filter_var($analysis['is_voip'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
        $this->model->savePhoneToCache($phone, $storage);
    }

    public function updateDisposableList(): int
    {
        try {
            $url = 'https://raw.githubusercontent.com/disposable/disposable-email-domains/master/domains.txt';
            $content = @file_get_contents($url);
            
            if (!$content) {
                return 0;
            }
            
            $domains = array_filter(array_map('trim', explode("\n", $content)));
            $inserted = 0;
            
            foreach ($domains as $domain) {
                if ($this->model->updateDisposableDomain($domain)) {
                    $inserted++;
                }
            }
            
            $this->logger->info('email.disposable_list.updated', [
                'count' => $inserted
            ]);
            
            return $inserted;
        } catch (\Exception $e) {
            $this->logger->error('email.disposable_list.update_failed', [
                'error' => $e->getMessage()
            ]);
            \App\Services\Sentry\SentryExceptionHandler::captureException($e, null, ['operation' => 'antifraud.emailPhone.updateDisposableList']);
            return 0;
        }
    }

    public function cleanupCache(): int
    {
        return $this->model->cleanupOldCache();
    }
}

