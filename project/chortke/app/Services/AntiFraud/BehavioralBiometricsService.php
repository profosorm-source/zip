<?php

declare(strict_types=1);

namespace App\Services\AntiFraud;

use App\Models\IpAndDeviceModel;
use App\Contracts\LoggerInterface;
/**
 * BehavioralBiometricsService
 *
 * تحلیل رفتار کاربر برای تشخیص Bot و Account Takeover
 *
 * @phpstan-type Analysis array<string, mixed>
 * @phpstan-type TypingHistory array{avg_interval: float, stddev_interval: float}
 */
class BehavioralBiometricsService
{
    // ─── Sample buffer limits ─────────────────────────────────────────
    private const MAX_SAMPLE_SIZE        = 250;   // حداکثر نمونه برای هر تحلیل
    private const MIN_KEYSTROKE_SAMPLE   = 10;    // حداقل نمونه‌ی تایپ برای تحلیل
    private const MIN_MOVEMENT_SAMPLE    = 20;    // حداقل نمونه‌ی حرکت ماوس

    // ─── Timing thresholds (milliseconds) ────────────────────────────
    private const KEYSTROKE_INTERVAL_BOT_MAX    = 50;   // زیر این = احتمال Bot تایپ
    private const KEYSTROKE_INTERVAL_NATURAL_MIN= 200;  // بالای این = تایپ طبیعی
    private const MOUSE_SPEED_INHUMAN_MAX       = 5000; // بالای این = سرعت غیرطبیعی (px/s)
    private const FORM_FILL_BOT_MAX_MS          = 2000; // زیر این = پر کردن فرم خیلی سریع

    // ─── Statistical thresholds ──────────────────────────────────────
    private const STDDEV_BOT_THRESHOLD          = 10;   // انحراف معیار زیر این = ربات
    private const CURVATURE_BOT_THRESHOLD       = 0.1;  // انحراف مسیر زیر این = ربات
    private const DEVIATION_OUTLIER_THRESHOLD   = 100;
    /** M-19: number of standard deviations from the personal baseline that counts as an outlier. */
    private const DEVIATION_ZSCORE_THRESHOLD = 3.0;
    /** M-19: below this stddev the personal baseline is too flat to trust a z-score, so use the absolute threshold. */
    private const MIN_MEANINGFUL_STDDEV = 5.0;
    private const SUSPICIOUS_SCORE_THRESHOLD    = 50;   // امتیاز بالای این = مشکوک

    // ─── Timing conversion ───────────────────────────────────────────
    private const MS_TO_SECONDS_DIVISOR        = 1000;  // تبدیل ms به ثانیه

    // ─── Form minimum fields ─────────────────────────────────────────
    private const MIN_FIELDS_FOR_FORM_CHECK    = 3;     // حداقل فیلد برای بررسی فرم

    private IpAndDeviceModel $model;
    private \Core\Cache $cache;
public function __construct(
        \Core\Cache $cache,
        IpAndDeviceModel $model
    ) {    $this->cache = $cache;

        
        $this->model = $model;
        }

    /**
     * تحلیل الگوی تایپ (Typing Pattern)
     */
    /**
     * @param list<array<string, mixed>> $keystrokes
     * @return Analysis
     */
    public function analyzeTypingPattern(int $userId, array $keystrokes): array
    {
        // Performance Guard: Check Redis cache first to avoid redundant heavy O(N) calculations
        $cacheKey = "biometrics:typing:{$userId}";
        $cached = $this->cache->get($cacheKey);
        $cachedCount = is_array($cached) && is_scalar($cached['keystroke_count'] ?? null) && is_numeric((string)$cached['keystroke_count'])
            ? (int)$cached['keystroke_count'] : null;
        if (is_array($cached) && $cachedCount !== null && count($keystrokes) <= $cachedCount) {
            return $cached;
        }

        if (count($keystrokes) > self::MAX_SAMPLE_SIZE) {
            $keystrokes = \array_slice($keystrokes, 0, self::MAX_SAMPLE_SIZE);
        }

        if (count($keystrokes) < self::MIN_KEYSTROKE_SAMPLE) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل وجود ندارد',
                'keystroke_count' => count($keystrokes)
            ];
        }
        
        $intervals = [];
        $holdTimes = [];
        /** @var array<string, list<float>> $pendingKeyDowns */
        $pendingKeyDowns = [];
        /** @var list<float> $downTimestamps */
        $downTimestamps = [];
        
        foreach ($keystrokes as $event) {
            $type = is_string($event['type'] ?? null) ? $event['type'] : '';
            $timestamp = is_scalar($event['timestamp'] ?? null) && is_numeric((string)$event['timestamp']) ? (float)$event['timestamp'] : null;
            if ($timestamp === null) continue;
            // Privacy guard: use only a stable hash to pair down/up events.
            $keyId = md5(is_scalar($event['key'] ?? null) ? (string)$event['key'] : 'unknown');
            
            if ($type === 'down') {
                $pendingKeyDowns[$keyId][] = $timestamp;
                $downTimestamps[] = $timestamp;
            } elseif ($type === 'up' && isset($pendingKeyDowns[$keyId]) && $pendingKeyDowns[$keyId] !== []) {
                $downAt = array_shift($pendingKeyDowns[$keyId]);
                if ($downAt !== null && $timestamp >= $downAt) $holdTimes[] = $timestamp - $downAt;
            }
        }
        
        sort($downTimestamps);
        
        for ($i = 1; $i < count($downTimestamps); $i++) {
            $intervals[] = $downTimestamps[$i] - $downTimestamps[$i - 1];
        }
        
        if (empty($intervals) || empty($holdTimes)) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل فاصله‌ها وجود ندارد'
            ];
        }
        
        $avgInterval = array_sum($intervals) / count($intervals);
        $stddevInterval = $this->standardDeviation($intervals);
        
        $avgHoldTime = array_sum($holdTimes) / count($holdTimes);
        $stddevHoldTime = $this->standardDeviation($holdTimes);
        
        $suspiciousReasons = [];
        $riskScore = 0;
        
        if ($stddevInterval < self::STDDEV_BOT_THRESHOLD && count($intervals) > 20) {
            $suspiciousReasons[] = 'فاصله تایپ خیلی یکنواخت (احتمال Bot)';
            $riskScore += 40;
        }
        
        if ($avgInterval < self::KEYSTROKE_INTERVAL_BOT_MAX) {
            $suspiciousReasons[] = 'سرعت تایپ غیرمعمول بالا';
            $riskScore += 35;
        }
        
        if ($stddevHoldTime < (self::STDDEV_BOT_THRESHOLD / 2) && count($holdTimes) > 20) {
            $suspiciousReasons[] = 'زمان نگه‌داشتن کلیدها یکسان';
            $riskScore += 25;
        }
        
        $historicalPattern = $this->getUserTypingHistory($userId);
        if ($historicalPattern) {
            // M-19 FIX: comparing the raw absolute distance from the historical *mean* against a
            // single fixed threshold ignored how variable each user naturally is. A user who
            // legitimately types with high variance was flagged, while a bot mimicking an
            // unusually steady typist slipped under the threshold. The deviation is now measured
            // in standard-deviation units (a z-score) using the user's own stored stddev, so the
            // outlier test adapts to each individual's baseline. When no meaningful historical
            // spread exists we fall back to the absolute threshold rather than dividing by zero.
            $deviation = abs($avgInterval - $historicalPattern['avg_interval']);
            $historicalStddev = (float)($historicalPattern['stddev_interval'] ?? 0.0);
            $isOutlier = $historicalStddev >= self::MIN_MEANINGFUL_STDDEV
                ? ($deviation / $historicalStddev) > self::DEVIATION_ZSCORE_THRESHOLD
                : $deviation > self::DEVIATION_OUTLIER_THRESHOLD;
            if ($isOutlier) {
                $suspiciousReasons[] = 'تغییر ناگهانی الگوی تایپ نسبت به سابقه';
                $riskScore += 30;
            }
        }
        
        $this->saveTypingPattern($userId, [
            'avg_interval' => $avgInterval,
            'stddev_interval' => $stddevInterval,
            'avg_hold_time' => $avgHoldTime,
            'keystroke_count' => count($keystrokes)
        ]);
        
        $result = [
            'is_suspicious' => $riskScore >= self::SUSPICIOUS_SCORE_THRESHOLD,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'metrics' => [
                'avg_interval_ms' => round($avgInterval, 2),
                'stddev_interval_ms' => round($stddevInterval, 2),
                'avg_hold_time_ms' => round($avgHoldTime, 2),
                'keystroke_count' => count($keystrokes)
            ]
        ];

        // Cache the result for 5 minutes to reduce CPU load (Issue 4)
        $this->cache->put($cacheKey, $result, 5);

        return $result;
    }

    /**
     * تحلیل الگوی حرکت موس (Mouse Movement Pattern)
     */
    /**
     * @param list<array<string, mixed>> $movements
     * @return Analysis
     */
    public function analyzeMousePattern(int $userId, array $movements): array
    {
        // Performance Guard (Issue 4): Cache results to prevent O(N) overhead on repeated requests
        $cacheKey = "biometrics:mouse:{$userId}";
        $cached = $this->cache->get($cacheKey);
        $cachedCount = is_array($cached) && is_scalar($cached['movement_count'] ?? null) && is_numeric((string)$cached['movement_count'])
            ? (int)$cached['movement_count'] : null;
        if (is_array($cached) && $cachedCount !== null && count($movements) <= $cachedCount) {
            return $cached;
        }

        if (count($movements) > self::MAX_SAMPLE_SIZE) {
            $movements = \array_slice($movements, 0, self::MAX_SAMPLE_SIZE);
        }

        if (count($movements) < self::MIN_MOVEMENT_SAMPLE) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل موس وجود ندارد',
                'movement_count' => count($movements)
            ];
        }
        
        $distances = [];
        $angles = [];
        $speeds = [];
        $curvatures = [];
        
        for ($i = 1; $i < count($movements); $i++) {
            $prev = $movements[$i - 1];
            $curr = $movements[$i];
            
            $dx = $curr['x'] - $prev['x'];
            $dy = $curr['y'] - $prev['y'];
            $distance = sqrt($dx * $dx + $dy * $dy);
            $distances[] = $distance;
            
            $angle = atan2($dy, $dx);
            $angles[] = $angle;
            
            $timeDiff = ($curr['timestamp'] - $prev['timestamp']) / self::MS_TO_SECONDS_DIVISOR;
            if ($timeDiff > 0) {
                $speed = $distance / $timeDiff;
                $speeds[] = $speed;
            }
            
            if ($i >= 2) {
                $prevAngle = $angles[$i - 2];
                $curvature = abs($angle - $prevAngle);
                $curvatures[] = $curvature;
            }
        }
        
        $inputMethod = $this->detectInputMethod($movements);
        $isTouch = in_array($inputMethod, ['touch', 'hybrid'], true);

        $suspiciousReasons = [];
        $riskScore = 0;
        
        $avgCurvature = !empty($curvatures) ? array_sum($curvatures) / count($curvatures) : 0;
        // اصلاح کلیدی معماری موبایل: در صفحات لمسی موبایل، اسکرول و سوایپ به صورت خطی و مستقیم انجام می‌شود، لذا جریمه انحنای خطی حذف می‌شود
        if (!$isTouch && $avgCurvature < self::CURVATURE_BOT_THRESHOLD && count($movements) > 50) {
            $suspiciousReasons[] = 'حرکت موس خطی و غیرطبیعی';
            $riskScore += 45;
        }
        
        $stddevSpeed = $this->standardDeviation($speeds);
        if ($stddevSpeed < 10 && count($speeds) > 30) {
            $suspiciousReasons[] = 'سرعت موس یکنواخت';
            $riskScore += 35;
        }
        
        if (count($movements) < 50) {
            $suspiciousReasons[] = 'تعامل موس خیلی کم';
            $riskScore += 20;
        }
        
        $pauseCount = 0;
        for ($i = 1; $i < count($movements); $i++) {
            $timeDiff = ($movements[$i]['timestamp'] - $movements[$i - 1]['timestamp']) / 1000;
            if ($timeDiff > 0.5) {
                $pauseCount++;
            }
        }
        
        if ($pauseCount < 3 && count($movements) > 100) {
            $suspiciousReasons[] = 'عدم توقف طبیعی در حرکت موس';
            $riskScore += 30;
        }
        
        $result = [
            'is_suspicious' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'metrics' => [
                'movement_count' => count($movements),
                'avg_curvature' => round($avgCurvature, 4),
                'avg_speed_px_s' => !empty($speeds) ? round(array_sum($speeds) / count($speeds), 2) : 0,
                'stddev_speed' => round($stddevSpeed, 2),
                'pause_count' => $pauseCount
            ]
        ];

        // Cache for 5 minutes
        $this->cache->put($cacheKey, $result, 5);

        return $result;
    }

    /**
     * تحلیل الگوی کلیک (Click Pattern)
     */
    /**
     * @param list<array<string, mixed>> $clicks
     * @return Analysis
     */
    public function analyzeClickPattern(array $clicks): array
    {
        // Performance Guard: Bound analysis count to block massive click payloads
        if (count($clicks) > self::MAX_SAMPLE_SIZE) {
            $clicks = \array_slice($clicks, 0, self::MAX_SAMPLE_SIZE);
        }

        if (count($clicks) < 5) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل کلیک وجود ندارد'
            ];
        }
        
        $intervals = [];
        for ($i = 1; $i < count($clicks); $i++) {
            $interval = $clicks[$i]['timestamp'] - $clicks[$i - 1]['timestamp'];
            $intervals[] = $interval;
        }
        
        $avgInterval = array_sum($intervals) / count($intervals);
        $stddevInterval = $this->standardDeviation($intervals);
        
        $suspiciousReasons = [];
        $riskScore = 0;
        
        if ($avgInterval < self::KEYSTROKE_INTERVAL_NATURAL_MIN && $stddevInterval < 20) {
            $suspiciousReasons[] = 'کلیک‌های خیلی سریع و یکنواخت (احتمال Auto-clicker)';
            $riskScore += 60;
        }
        
        $positions = array_map(fn($c) => $c['x'] . ',' . $c['y'], $clicks);
        $uniquePositions = array_unique($positions);
        
        if (count($uniquePositions) < count($clicks) * 0.3) {
            $suspiciousReasons[] = 'کلیک‌های تکراری در نقاط مشابه';
            $riskScore += 25;
        }
        
        return [
            'is_suspicious' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'metrics' => [
                'click_count' => count($clicks),
                'avg_interval_ms' => round($avgInterval, 2),
                'stddev_interval_ms' => round($stddevInterval, 2),
                'unique_positions' => count($uniquePositions)
            ]
        ];
    }

    /**
     * تحلیل الگوی اسکرول (Scroll Behavior)
     */
    /**
     * @param list<array<string, mixed>> $scrolls
     * @return Analysis
     */
    public function analyzeScrollBehavior(array $scrolls): array
    {
        // Performance Guard: Keep tracking within safe iterative range
        if (count($scrolls) > self::MAX_SAMPLE_SIZE) {
            $scrolls = \array_slice($scrolls, 0, self::MAX_SAMPLE_SIZE);
        }

        if (count($scrolls) < 5) {
            return [
                'is_suspicious' => false,
                'reason' => 'داده کافی برای تحلیل اسکرول وجود ندارد'
            ];
        }
        
        $speeds = [];
        $directions = [];
        
        for ($i = 1; $i < count($scrolls); $i++) {
            $prev = $scrolls[$i - 1];
            $curr = $scrolls[$i];
            
            $distance = abs($curr['position'] - $prev['position']);
            $timeDiff = ($curr['timestamp'] - $prev['timestamp']) / 1000;
            
            if ($timeDiff > 0) {
                $speed = $distance / $timeDiff;
                $speeds[] = $speed;
            }
            
            $directions[] = $curr['direction'];
        }
        
        $avgSpeed = !empty($speeds) ? array_sum($speeds) / count($speeds) : 0;
        
        $suspiciousReasons = [];
        $riskScore = 0;
        
        if ($avgSpeed > self::MOUSE_SPEED_INHUMAN_MAX) {
            $suspiciousReasons[] = 'سرعت اسکرول غیرطبیعی بالا';
            $riskScore += 40;
        }
        
        $upScrolls = array_filter($directions, fn($d) => $d === 'up');
        if (count($upScrolls) === 0 && count($scrolls) > 20) {
            $suspiciousReasons[] = 'عدم اسکرول به سمت بالا (رفتار غیرطبیعی)';
            $riskScore += 25;
        }
        
        return [
            'is_suspicious' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'metrics' => [
                'scroll_count' => count($scrolls),
                'avg_speed_px_s' => round($avgSpeed, 2),
                'up_scroll_ratio' => round(count($upScrolls) / count($scrolls), 2)
            ]
        ];
    }

    /**
     * تحلیل الگوی تعامل با فرم (Form Interaction)
     */
    /**
     * @param array<string, mixed> $formData
     * @return Analysis
     */
    public function analyzeFormInteraction(array $formData): array
    {
        $suspiciousReasons = [];
        $riskScore = 0;
        
        $submitTime = is_scalar($formData['submit_time'] ?? null) && is_numeric((string)$formData['submit_time']) ? (float)$formData['submit_time'] : null;
        $formLoadTime = is_scalar($formData['form_load_time'] ?? null) && is_numeric((string)$formData['form_load_time']) ? (float)$formData['form_load_time'] : null;
        $fillTime = $submitTime !== null && $formLoadTime !== null && $submitTime >= $formLoadTime ? $submitTime - $formLoadTime : 0.0;
        $fields = is_array($formData['fields'] ?? null) ? $formData['fields'] : [];
        
        if ($fillTime > 0 && $fillTime < self::FORM_FILL_BOT_MAX_MS && count($fields) >= self::MIN_FIELDS_FOR_FORM_CHECK) {
            $suspiciousReasons[] = 'پر کردن فرم خیلی سریع (احتمال Auto-fill)';
            $riskScore += 50;
        }
        
        $focusEvents = $formData['focus_count'] ?? 0;
        if ($focusEvents < count($fields) * 0.5) {
            $suspiciousReasons[] = 'تعداد focus event کمتر از حد انتظار';
            $riskScore += 30;
        }
        
        if (($formData['field_changes'] ?? 0) === 0) {
            $suspiciousReasons[] = 'عدم ویرایش یا تغییر فیلدها';
            $riskScore += 40;
        }
        
        return [
            'is_suspicious' => $riskScore >= 50,
            'risk_score' => min(100, $riskScore),
            'reasons' => $suspiciousReasons,
            'metrics' => [
                'fill_time_ms' => $fillTime,
                'focus_count' => $focusEvents,
                'field_count' => count($fields)
            ]
        ];
    }

    /**
     * تحلیل جامع رفتاری
     */
    /**
     * @param array<string, mixed> $behaviorData
     * @return Analysis
     */
    public function comprehensiveAnalysis(int $userId, array $behaviorData): array
    {
        $results = [];
        
        if (is_array($behaviorData['keystrokes'] ?? null)) {
            $results['typing'] = $this->analyzeTypingPattern($userId, $behaviorData['keystrokes']);
        }
        
        if (is_array($behaviorData['mouse_movements'] ?? null)) {
            $results['mouse'] = $this->analyzeMousePattern($userId, $behaviorData['mouse_movements']);
        }
        
        if (is_array($behaviorData['clicks'] ?? null)) {
            $results['clicks'] = $this->analyzeClickPattern($behaviorData['clicks']);
        }
        
        if (is_array($behaviorData['scrolls'] ?? null)) {
            $results['scroll'] = $this->analyzeScrollBehavior($behaviorData['scrolls']);
        }
        
        if (is_array($behaviorData['form'] ?? null)) {
            $results['form'] = $this->analyzeFormInteraction($behaviorData['form']);
        }
        
        $totalRisk = 0;
        $count = 0;
        
        foreach ($results as $analysis) {
            if (isset($analysis['risk_score'])) {
                $totalRisk += $analysis['risk_score'];
                $count++;
            }
        }
        
        $avgRisk = $count > 0 ? $totalRisk / $count : 0;
        
        return [
            'overall_risk_score' => round($avgRisk, 2),
            'is_bot_likely' => $avgRisk >= 60,
            'analyses' => $results
        ];
    }

    /** @param list<float|int> $values */
    private function standardDeviation(array $values): float
    {
        if (empty($values)) {
            return 0;
        }
        
        $mean = array_sum($values) / count($values);
        $variance = 0;
        
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        
        $variance /= count($values);
        
        return sqrt($variance);
    }

    /** @return ?TypingHistory */
    private function getUserTypingHistory(int $userId): ?array
    {
        $result = $this->model->getLastTypingPattern($userId);
        
        if (!$result) {
            return null;
        }
        
        return [
            'avg_interval' => isset($result->avg_interval) ? (float)$result->avg_interval : 0.0,
            'stddev_interval' => isset($result->stddev_interval) ? (float)$result->stddev_interval : 0.0,
        ];
    }

    /** @param array{avg_interval: float|int, stddev_interval: float|int, avg_hold_time: float|int, keystroke_count: int} $pattern */
    private function saveTypingPattern(int $userId, array $pattern): void
    {
        $this->model->saveTypingPattern($userId, $pattern);
    }

    /** @param list<array<string, mixed>> $events */
    public function detectInputMethod(array $events): string
    {
        $hasTouchEvents = false;
        $hasMouseEvents = false;
        
        foreach ($events as $event) {
            if (isset($event['type'])) {
                if (in_array($event['type'], ['touchstart', 'touchmove', 'touchend'])) {
                    $hasTouchEvents = true;
                }
                if (in_array($event['type'], ['mousedown', 'mousemove', 'mouseup'])) {
                    $hasMouseEvents = true;
                }
            }
        }
        
        if ($hasTouchEvents && !$hasMouseEvents) {
            return 'touch';
        } elseif ($hasMouseEvents && !$hasTouchEvents) {
            return 'mouse';
        } elseif ($hasTouchEvents && $hasMouseEvents) {
            return 'hybrid';
        }
        
        return 'unknown';
    }
}


