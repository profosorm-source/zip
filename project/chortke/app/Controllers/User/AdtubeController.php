<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Models\Ads;
use App\Models\AdTubeExecutionModel;
use App\Services\Ads\AdsBudgetSettlementService;
use App\Services\AdSystemManager;

/**
 * AdtubeController — Executor-only controller for AdTube (video watch) ads.
 *
 * NOTE: Advertiser management (create, pause, stats, list) is unified under /ads (AdsController).
 * This controller only handles the worker/executor side: discovering videos, watching, and submission.
 */
class AdtubeController extends BaseUserController
{
    private Ads $adModel;
    private AdTubeExecutionModel $executionModel;
    private \App\Services\Ads\AdsBudgetSettlementService $adsBudgetSettlement;
    private \App\Adapters\AdVideoRewardManager $rewardManager;

    public function __construct(
        Ads $adModel,
        AdTubeExecutionModel $executionModel,
        \App\Services\Ads\AdsBudgetSettlementService $adsBudgetSettlement,
        \App\Adapters\AdVideoRewardManager $rewardManager,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        $this->adModel = $adModel;
        $this->executionModel = $executionModel;
        $this->adsBudgetSettlement = $adsBudgetSettlement;
        $this->rewardManager = $rewardManager;
        parent::__construct(null, null, null, null, $logger);
    }

    /**
     * داشبورد انجام‌دهنده — لیست ویدیوهای فعال AdTube
     */
    public function index(): void
    {
        $userId = (int)user_id();

        // Active ads of type 'adtube' with remaining budget and slots
        $tasks = $this->adModel->getAvailableCustomTasks(
            $userId,
            ['platform' => 'youtube'],
            20, 0, 'adtube'
        );

        $stats = [
            'total_completed_today' => $this->executionModel->countCompletedToday($userId),
            'active_watching' => $this->executionModel->countActiveForUser($userId),
            'total_earned_today' => 0, // Calculated below if needed
        ];

        $rewardAds = $this->rewardManager->getAvailableRewardAds();

        $this->view('user/adtube/index', [
            'title' => 'AdTube — کسب درآمد از یوتیوب و تبلیغات جایزه‌دار',
            'tasks' => $tasks,
            'stats' => $stats,
            'rewardAds' => $rewardAds,
            'trust_score' => 50, // Placeholder; could be fetched from ScoreService if needed
        ]);
    }

    public function income(): void
    {
        $this->index();
    }

    /**
     * شروع فرآیند اجرای یک ویدیو (AJAX)
     */
    public function start(): void
    {
        header('Content-Type: application/json');
        try {
            $body = $this->request->body();
            $adId = int_value($body['ad_id'] ?? $this->request->param('id'));
            $userId = (int)user_id();

            if (!$adId) {
                echo json_encode(['success' => false, 'message' => 'شناسه ویدیو نامعتبر است.']);
                return;
            }

            // Verify ad exists and is active
            $ad = $this->adModel->find($adId);
            if (!$ad || $ad->type !== 'adtube' || !in_array($ad->status, ['active', 'approved'], true)) {
                echo json_encode(['success' => false, 'message' => 'آگهی ویدیویی فعال یافت نشد.']);
                return;
            }

            // Check if user has too many active watches
            if ($this->executionModel->countActiveForUser($userId) >= 3) {
                echo json_encode(['success' => false, 'message' => 'حداکثر ۳ ویدیو همزمان می‌توانید تماشا کنید.']);
                return;
            }

            $context = [
                'ip' => $this->request->ip(),
                'user_agent' => $this->request->userAgent(),
            ];

            $execution = $this->executionModel->findOrCreate($adId, $userId, $context);

            if (!$execution) {
                echo json_encode(['success' => false, 'message' => 'خطا در ایجاد رکورد اجرا.']);
                return;
            }

            // Transition to watching
            $this->executionModel->startWatching((int)$execution->id);

            echo json_encode([
                'success' => true,
                'execution_id' => $execution->id,
                'redirect_url' => url('/adtube/' . $execution->id . '/execute'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('adtube.start.failed', ['err' => $e->getMessage()]);
            echo json_encode(['success' => false, 'message' => 'خطا در شروع تماشای ویدیو.']);
        }
    }

    /**
     * صفحه پخش ویدیو و ثبت اثبات
     */
    public function showExecute(): void
    {
        $userId = (int)user_id();
        $execId = (int)$this->request->param('id');

        $execution = $this->executionModel->findByIdWithAd($execId, $userId);

        if (!$execution) {
            $this->session->setFlash('error', 'اجرای مورد نظر یافت نشد.');
            redirect(url('/adtube'));
        }

        // Only allow if status is pending or watching
        if (!in_array($execution->status, ['pending', 'watching'], true)) {
            $this->session->setFlash('warning', 'این ویدیو قبلاً ثبت شده است.');
            redirect(url('/adtube'));
        }

        $this->view('user/adtube/execute', [
            'title' => 'تماشای ویدیو و کسب درآمد',
            'execution' => $execution,
            'task' => $execution,
        ]);
    }

    /**
     * ثبت و ارسال نهایی نتایج (AJAX)
     */
    public function submit(): void
    {
        header('Content-Type: application/json');
        try {
            $execId = (int)$this->request->param('id');
            $userId = (int)user_id();
            $body = $this->request->body();

            $execution = $this->executionModel->findById($execId);
            if (!$execution || (int)$execution->executor_id !== $userId) {
                echo json_encode(['success' => false, 'message' => 'دسترسی غیرمجاز.']);
                return;
            }

            if ($execution->status !== 'watching') {
                echo json_encode(['success' => false, 'message' => 'وضعیت اجرا نامعتبر است.']);
                return;
            }

            // Normalization: handle both watched_seconds and watch_time
            $watchTime = int_value($body['watch_time'] ?? $body['watched_seconds'] ?? 0);
            $speed = float_value($body['playback_speed'] ?? 1.0);
            if ($speed <= 0) { $speed = 1.0; }

            $requiredDuration = int_value($execution->watch_duration_seconds ?? $execution->required_duration ?? 30);
            if ($requiredDuration <= 0) { $requiredDuration = 30; }

            $progress = int_value($body['progress_percent'] ?? 0);
            if ($progress <= 0 && $watchTime > 0) {
                $progress = (int)min(100, round(($watchTime / $requiredDuration) * 100));
            }

            // 🛡️ Fraud Guard 1: Server-side elapsed time verification
            $startedAt = 0;
            if (!empty($execution->created_at)) {
                $startedAt = strtotime((string)$execution->created_at);
            } elseif (!empty($execution->updated_at)) {
                $startedAt = strtotime((string)$execution->updated_at);
            }

            if ($startedAt > 0) {
                $elapsedSeconds = time() - $startedAt;
                // Minimum time expected to watch: (watchTime / speed) - 2s tolerance for latency
                $minExpectedTime = max(2, (int)floor(($watchTime / max(1.0, $speed)) - 2));
                if ($elapsedSeconds < $minExpectedTime) {
                    $this->executionModel->markRejected($execId, "تقلب زمان مشاهده: زمان سپری‌شده سرور {$elapsedSeconds}s، ادعا {$watchTime}s");
                    echo json_encode(['success' => false, 'message' => 'زمان تماشای واقعی با زمان سپری‌شده بر روی سرور مطابقت ندارد.']);
                    return;
                }
            }

            // Minimum validation: at least 80% watched and reasonable speed
            if ($progress < 80) {
                $this->executionModel->markRejected($execId, 'تماشای ناقص: ' . $progress . '%');
                echo json_encode(['success' => false, 'message' => 'ویدیو باید حداقل ۸۰٪ تماشا شود (' . $progress . '٪).']);
                return;
            }

            if ($speed > 2.5) {
                $this->executionModel->markRejected($execId, 'سرعت پخش غیرعادی: ' . $speed . 'x');
                echo json_encode(['success' => false, 'message' => 'سرعت پخش غیرعادی تشخیص داده شد.']);
                return;
            }

            $finance = $this->adsBudgetSettlement;
            $settlement = $finance->settleAdTubeView($execId, $userId, $watchTime, $progress, $speed);

            echo json_encode([
                'success' => !empty($settlement['success']),
                'message' => $settlement['message'] ?? (!empty($settlement['success']) ? 'ویدیو با موفقیت ثبت و پاداش پرداخت شد.' : 'تسویه ویدیو انجام نشد.'),
                'execution_id' => $execId,
                'settlement' => $settlement,
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            $this->logger->error('adtube.submit.failed', ['err' => $e->getMessage()]);
            echo json_encode(['success' => false, 'message' => 'خطای سیستمی در ثبت نهایی.']);
        }
    }

    /**
     * تاریخچه فعالیت کاربر در AdTube
     */
    public function history(): void
    {
        $userId = (int)user_id();
        $page = max(1, $this->request->int('page', 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $history = $this->executionModel->getHistory($userId, $limit, $offset);

        $this->view('user/adtube/history', [
            'title' => 'تاریخچه کسب درآمد AdTube',
            'history' => $history,
            'page' => $page,
        ]);
    }

    /**
     * 🛡️ وب‌هوک امن سرور به سرور (S2S Callback Verification)
     * دریافت تاییدیه تماشای ویدیو از سرورهای تپسل و ادموب و واریز پاداش
     */
    public function s2sWebhook(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $network = (string)$this->request->param('network');
        $payload = $this->request->body();
        // Finding #7 Fix: Enforce Webhook signature strictly from HTTP headers (never Query String)
        $receivedSignature = str_value($this->request->header('X-Webhook-Signature', $this->request->header('X-AdTube-Signature', '')));
        $timestamp = int_value($this->request->header('X-Webhook-Timestamp', $this->request->header('X-AdTube-Timestamp', 0)));

        // Finding #6 Fix: Freshness verification (max 300s window)
        if ($timestamp > 0 && abs(time() - $timestamp) > 300) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'درخواست وب‌هوک منقضی شده است (Timestamp Expired)']);
            return;
        }

        try {
            $adapter = $this->rewardManager->getAdapter($network);
            if (!$adapter->verifyS2SHmac($payload, $receivedSignature)) {
                http_response_code(401);
                echo json_encode(['success' => false, 'message' => 'امضای وب‌هوک نامعتبر است (HMAC Mismatch)']);
                return;
            }

            $userId = int_value($payload['user_id'] ?? $payload['sub_id'] ?? 0);
            if ($userId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'شناسه کاربری نامعتبر است']);
                return;
            }

            $payoutMoney = $adapter->calculateUserPayout();
            $walletService = $this->container->make(\App\Contracts\WalletServiceInterface::class);

            // واریز اتمیک تحت قفل FOR UPDATE
            $walletService->depositInTransaction($userId, $payoutMoney->getAmount(), strtolower($payoutMoney->getCurrency()), [
                'type' => 'rewarded_video_ad',
                'network' => $network,
                'description' => "پاداش تماشای ویدیوی تبلیغاتی {$network}",
                'idempotency_key' => "s2s_{$network}_" . hash('sha256', (string)json_encode($payload))
            ]);

            $this->logger->info('s2s_webhook.reward_processed', ['user_id' => $userId, 'network' => $network, 'payout' => $payoutMoney->getAmount()]);
            echo json_encode(['success' => true, 'message' => 'پاداش با موفقیت تایید و واریز شد']);
        } catch (\Throwable $e) {
            $this->logger->error('s2s_webhook.failed', ['network' => $network, 'error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'خطای سرور در پردازش وب‌هوک']);
        }
    }

    /**
     * فعال‌سازی شتاب‌دهنده رشد سطح (XP Boost) پس از تماشای ویدیوی تبلیغاتی در داشبورد
     */
    public function claimBoost(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $userId = (int)$this->userId();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'برای دریافت پاداش ابتدا وارد شوید.']);
            return;
        }

        try {
            // 🛡️ Fraud Guard: Verify user has completed at least one AdTube watch execution today
            $completedCount = $this->executionModel->countCompletedToday($userId);
            if ($completedCount <= 0) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'برای فعال‌سازی شتاب‌دهنده، ابتدا باید حداقل ۱ ویدیوی تبلیغاتی AdTube را به طور کامل تماشا کنید.']);
                return;
            }

            $cache = \Core\Cache::getInstance();
            $cooldownKey = "xp_boost_claimed:{$userId}";
            if ($cache->get($cooldownKey)) {
                http_response_code(429);
                echo json_encode(['success' => false, 'message' => 'شما قبلاً امروز پاداش ویدیوی شتاب‌دهنده را دریافت کرده‌اید. هر ۲۴ ساعت یکبار امکان فعال‌سازی وجود دارد.']);
                return;
            }

            $activeKey = "xp_boost_active:{$userId}";
            $cache->set($cooldownKey, true, 86400); // 24h cooldown
            $cache->set($activeKey, true, 86400);   // 24h active boost

            $xpGrowthRate = int_value(config('video_rewards.xp_growth_rate', setting('xp_growth_rate', 50)));
            $this->logger->info('user.xp_boost_activated', ['user_id' => $userId, 'rate' => $xpGrowthRate]);

            echo json_encode([
                'success' => true,
                'message' => "شتاب‌دهنده {$xpGrowthRate}٪ رشد سطح کاربری با موفقیت تا ۲۴ ساعت آینده برای حساب شما فعال شد."
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('adtube.claim_boost.failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'خطا در فعال‌سازی شتاب‌دهنده.']);
        }
    }
}
