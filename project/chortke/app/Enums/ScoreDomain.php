<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical score domains.
 *
 * 🛡️ M-3 Fix: دامنه‌های داینامیک به جای wildcard (str_starts_with) به صورت صریح
 *              از ترکیب prefix + ModuleContext تولید می‌شوند تا قابل پیش‌بینی و گزارش‌گیری باشند.
 *
 * دامنه‌های امتیازدهی عمداً از هم جدا هستند. مثلا social_trust فقط برای منطق
 * اعتماد تسک‌های اجتماعی است و نباید به عنوان trust عمومی همه ماژول‌ها تفسیر شود.
 */
enum ScoreDomain: string
{
    // =========== Static Domains (ثابت) ===========
    case Fraud = 'fraud';
    case Task = 'task';
    case SocialTrust = 'social_trust';
    case Referral = 'referral';
    case Activity = 'activity';
    case Loyalty = 'loyalty';
    case Reputation = 'reputation';
    case LotteryChance = 'lottery_chance';
    case PredictionAccuracy = 'prediction_accuracy';
    case VitrineRating = 'vitrine_rating';

    // =========== Dynamic Domain Prefixes ===========
    // این prefixها با ModuleContext ترکیب می‌شوند
    // مثلاً trust_social_tasks, xp_youtube_tasks
    public const PREFIX_TRUST = 'trust_';
    public const PREFIX_XP = 'xp_';
    public const PREFIX_REPUTATION = 'reputation_';

    /**
     * تولید نام دامنه داینامیک از prefix + ماژول
     * 
     * 🛡️ M-3 Fix: مسیر واحد برای تولید دامنه‌های داینامیک
     */
    public static function dynamicDomain(string $prefix, ModuleContext $context): string
    {
        $validPrefixes = [self::PREFIX_TRUST, self::PREFIX_XP, self::PREFIX_REPUTATION];
        if (!in_array($prefix, $validPrefixes, true)) {
            throw new \InvalidArgumentException("Invalid dynamic domain prefix: {$prefix}");
        }
        return $prefix . $context->value;
    }

    /**
     * دریافت لیست کامل دامنه‌های داینامیک معتبر
     * 
     * 🛡️ M-3 Fix: همه دامنه‌های ممکن از ترکیب prefix + ModuleContext تولید می‌شوند
     */
    /** @return list<string> */
    public static function getDynamicDomains(): array
    {
        $domains = [];
        $prefixes = [self::PREFIX_TRUST, self::PREFIX_XP, self::PREFIX_REPUTATION];
        
        foreach ($prefixes as $prefix) {
            foreach (ModuleContext::cases() as $context) {
                $domains[] = $prefix . $context->value;
            }
        }
        
        return $domains;
    }

    /**
     * دریافت لیست کامل همه دامنه‌های معتبر (static + dynamic)
     * 
     * 🛡️ M-3 Fix: برای گزارش‌گیری و اعتبارسنجی یکجا
     */
    /** @return list<string> */
    public static function allValid(): array
    {
        return array_merge(self::values(), self::getDynamicDomains());
    }

    public static function normalize(string $domain): string
    {
        $domain = strtolower(trim((string)$domain));

        // Legacy compatibility only: do not persist new generic "trust" buckets.
        if ($domain === 'trust') {
            return self::SocialTrust->value;
        }

        return $domain;
    }

    public static function tryFromNormalized(string $domain): ?self
    {
        return self::tryFrom(self::normalize($domain));
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn(self $case) => $case->value, self::cases());
    }

    /**
     * 🛡️ M-3 Fix: اعتبارسنجی دقیق به جای wildcard
     * 
     * قبلی: str_starts_with($domain, 'trust_') → خیلی loose
     * جدید: in_array($domain, self::allValid()) → دقیق و قابل پیش‌بینی
     */
    public static function isValid(string $domain): bool
    {
        $normalized = self::normalize($domain);
        
        // 1. Static domain (enum case)
        if (self::tryFrom($normalized) !== null) {
            return true;
        }

        // 2. 🛡️ M-3 Fix: Dynamic domain (strict validation against generated list)
        return in_array($normalized, self::getDynamicDomains(), true);
    }
}
