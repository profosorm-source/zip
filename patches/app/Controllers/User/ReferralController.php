<?php

namespace App\Controllers\User;

use App\Models\ReferralCommission;
use App\Services\Shared\ReferralService;
use App\Controllers\User\BaseUserController;

/**
 * ReferralController - Consolidated Referral System
 * 
 * استفاده از نئی ReferralService (Sprint 4 - Consolidation)
 * تمام referral functionality اب ایک service میں ہے
 */
class ReferralController extends BaseUserController
{
    private ReferralCommission $referralCommissionModel;
    private ReferralService    $referralService;

    public function __construct(
        ReferralCommission $referralCommissionModel,
        ReferralService    $referralService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->referralCommissionModel = $referralCommissionModel;
        $this->referralService = $referralService;
    }

    /**
     * صفحه اصلی زیرمجموعه‌گیری
     */
    public function index(): string
    {
        $userId = (int)$this->userId();
        $user   = $this->userService->find($userId);

        // آمار کلی کمیسیون‌ها - نئی ReferralService سے
        $stats = $this->referralService->getReferrerStats($userId);

        // تعداد و لیست زیرمجموعه‌ها
        $referredCount = $this->referralService->countReferredUsers($userId);
        $referredUsers = $this->referralService->getReferredUsers($userId, 10, 0);

        // آخرین ۱۰ کمیسیون
        $recentCommissions = $this->referralService->getByReferrer($userId, [], 10, 0);

        // برچسب‌گذاری کمیسیون‌ها
        foreach ($recentCommissions as $c) {
            $c->source_label = $c->source_type ?? 'ناشناخته';
            $c->status_label = self::statusLabel($c->status);
            $c->status_class = self::statusClass($c->status);
        }

        // لینک و درصدهای فعال
        $referralLink = url('/register?ref=' . ($user->referral_code ?? ''));

        $percents = [
            'task_reward'  => setting('referral_commission_task_percent',       10),
            'investment'   => setting('referral_commission_investment_percent',   5),
            'vip_purchase' => setting('referral_commission_vip_percent',          8),
            'story_order'  => setting('referral_commission_story_percent',        5),
        ];

        return $this->view('user/referral/index', [
            'user'              => $user,
            'stats'             => $stats,
            'referredCount'     => $referredCount,
            'referredUsers'     => $referredUsers,
            'recentCommissions' => $recentCommissions,
            'referralLink'      => $referralLink,
            'percents'          => $percents,
            'sourceTypes'       => [],
        ]);
    }

    /**
     * لیست کمیسیون‌ها (AJAX/JSON)
     */
    public function commissions(): void
    {
        $userId = (int)$this->userId();

        $filters = array_filter([
            'status'      => $this->request->get('status'),
            'source_type' => $this->request->get('source_type'),
            'currency'    => $this->request->get('currency'),
        ]);

        $page   = max(1, $this->request->int('page', 1));
        $limit  = 15;
        $offset = ($page - 1) * $limit;

        $commissions = $this->referralService->getByReferrer($userId, $filters, $limit, $offset);
        $total       = $this->referralService->countByReferrer($userId, $filters);

        foreach ($commissions as $c) {
            $c->created_at_jalali = to_jalali($c->created_at ?? '');
            $c->paid_at_jalali    = $c->paid_at ? to_jalali($c->paid_at) : null;
            $c->source_label      = $c->source_type ?? 'ناشناخته';
            $c->status_label      = self::statusLabel($c->status);
            $c->status_class      = self::statusClass($c->status);
        }

        $this->response->json([
            'success'     => true,
            'commissions' => $commissions,
            'total'       => $total,
            'page'        => $page,
            'pages'       => (int) ceil($total / $limit),
        ]);
    }

    /**
     * لیست زیرمجموعه‌ها (AJAX/JSON)
     */
    public function referredUsers(): void
    {
        $userId = (int)$this->userId();

        $page   = max(1, $this->request->int('page', 1));
        $limit  = 15;
        $offset = ($page - 1) * $limit;

        $users = $this->referralService->getReferredUsers($userId, $limit, $offset);
        $total = $this->referralService->countReferredUsers($userId);

        foreach ($users as $u) {
            $u->joined_at_jalali = to_jalali($u->joined_at ?? '');
        }

        $this->response->json([
            'success' => true,
            'users'   => $users,
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int) ceil($total / $limit),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────

    private static function statusLabel(string $status): string
    {
        return [
            'pending'   => 'در انتظار',
            'paid'      => 'پرداخت شده',
            'cancelled' => 'لغو شده',
            'failed'    => 'ناموفق',
        ][$status] ?? $status;
    }

    private static function statusClass(string $status): string
    {
        return [
            'pending'   => 'ref-badge--pending',
            'paid'      => 'ref-badge--paid',
            'cancelled' => 'ref-badge--danger',
            'failed'    => 'ref-badge--danger',
        ][$status] ?? 'ref-badge--muted';
    }

    // ── New Features ───────────────────────────────────────────

    /**
     * داشبورد پیشرفته با Analytics - نئی ReferralService
     */
    public function dashboard(): string
    {
        $userId = (int)$this->userId();

        // اطلاعات پایه
        $user = $this->userService->find($userId);
        $stats = $this->referralCommissionModel->getReferrerStats($userId);

        // Tier فعلی و پیشرفت
        $currentTier = $this->referralService->getCurrentTier($userId);
        $nextTierProgress = $this->referralService->checkAndUpgrade($userId);

        // Quality Score
        $qualityScore = $this->referralService->getScore($userId);
        $improvementSuggestions = $this->referralService->calculateScore($userId);

        // Milestones
        $achievedMilestones = $this->referralService->getUserAchievedMilestones($userId);
        $nextMilestone = $this->referralService->checkAndAwardMilestones($userId);

        // Analytics
        $analytics = $this->referralService->getReferralTrend($userId, 30);

        // Multi-tier earnings (اگر فعال باشه)
        $multiTierEarnings = null;
        if (setting('referral_multi_tier_enabled', 0)) {
            $multiTierEarnings = $this->referralService->processMultiTierCommissions($userId, 0, 'irt'); // Fixed missing args
        }

        return $this->view('user/referral/dashboard', [
            'user' => $user,
            'stats' => $stats,
            'current_tier' => $currentTier,
            'next_tier_progress' => $nextTierProgress,
            'quality_score' => $qualityScore,
            'improvement_suggestions' => $improvementSuggestions,
            'achieved_milestones' => $achievedMilestones,
            'next_milestone' => $nextMilestone,
            'analytics' => $analytics,
            'multi_tier_earnings' => $multiTierEarnings,
            'referralLink' => url('/register?ref=' . ($user->referral_code ?? ''))
        ]);
    }

    /**
     * صفحه Analytics - نئی ReferralService
     */
    public function analytics(): string
    {
        $userId = (int)$this->userId();

        $dashboard = $this->referralService->getReferralTrend($userId, 90);
        $comparison = $this->referralService->getCommissionTrend($userId, 30);

        return $this->view('user/referral/analytics', [
            'dashboard' => $dashboard,
            'comparison' => $comparison
        ]);
    }

    /**
     * صفحه Milestones - نئی ReferralService
     */
    public function milestones(): string
    {
        $userId = (int)$this->userId();

        $achieved = $this->referralService->getUserAchievedMilestones($userId);

        return $this->view('user/referral/milestones', [
            'achieved' => $achieved,
        ]);
    }

    /**
     * صفحه شبکه (Network) - Multi-tier - نئی ReferralService
     */
    

    /**
     * API: دریافت آمار لحظه‌ای - نئی ReferralService
     */
    public function apiStats(): void
    {
        $userId = (int)$this->userId();

        $this->response->json([
            'success' => true,
            'data' => [
                'tier' => $this->referralService->getCurrentTier($userId),
                'quality_score' => $this->referralService->getScore($userId),
                'conversion_rate' => $this->referralService->getConversionRate($userId, 7),
            ]
        ]);
    }

    public function generateCode(): void
    {
        $userId = (int)$this->userId();
        $user = $this->userService->find($userId);
        if (!$user) {
            $this->response->json(['success' => false, 'message' => 'کاربر یافت نشد.'], 404);
            return;
        }
        // BUGFIX: کد تولیدشده باید ذخیره شود. پیش از این کد فقط در حافظه ساخته
        // و در پاسخ برگردانده می‌شد اما هرگز در users.referral_code نوشته
        // نمی‌شد؛ در نتیجه هر فراخوان کد متفاوتی می‌داد و لینک معرفی
        // بازگشتی (/register?ref=…) هرگز به کاربری نگاشت نمی‌شد، چون
        // findByReferralCode() کد را از دیتابیس می‌خواند.
        $code = $user->referral_code ?? null;
        if ($code === null || $code === '') {
            // تولید کد یکتا با تعداد تلاش محدود تا با قید یکتایی ستون
            // users.referral_code برخورد نکند.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $candidate = 'REF_' . $userId . '_' . bin2hex(random_bytes(3));
                if ($this->userService->findByReferralCode($candidate) === null) {
                    $code = $candidate;
                    break;
                }
            }
            if ($code === null || $code === '') {
                $this->response->json(['success' => false, 'message' => 'تولید کد معرف ناموفق بود. لطفاً دوباره تلاش کنید.'], 500);
                return;
            }
            $this->userService->update($userId, ['referral_code' => $code]);
        }
        $this->response->json(['success' => true, 'referral_code' => $code, 'referral_link' => url('/register?ref=' . $code)]);
    }

    public function setReferrer(): void
    {
        try {
            $userId = (int)$this->userId();
            $refCode = trim(str_value($this->request->input('referral_code') ?? $this->request->input('code') ?? ''));
            if ($refCode === '' || mb_strlen($refCode) > 100) {
                $this->response->json(['success' => false, 'message' => 'کد معرف الزامی است.'], 422);
                return;
            }
            $user = $this->userService->find($userId);
            if ($user && !empty($user->referred_by)) {
                $this->response->json(['success' => false, 'message' => 'شما قبلاً معرف خود را ثبت کرده‌اید و امکان تغییر آن وجود ندارد.'], 422);
                return;
            }
            $referrer = null;
            try {
                $referrer = $this->userService->findByCredentials($refCode);
            } catch (\Throwable $e) {
                $referrer = null;
            }
            if (!$referrer) {
                $referrer = $this->userService->findByReferralCode($refCode);
            }
            if (!$referrer) {
                $this->response->json(['success' => false, 'message' => 'کد معرف معتبر نیست.'], 422);
                return;
            }
            if ((int)$referrer->id === $userId) {
                $this->response->json(['success' => false, 'message' => 'امکان ثبت خود به عنوان معرف وجود ندارد.'], 422);
                return;
            }
            $this->userService->updateUser($userId, ['referred_by' => (int)$referrer->id]);
            $this->response->json(['success' => true, 'message' => 'معرف با موفقیت ثبت شد.']);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->response->json(['success' => false, 'message' => 'کد معرف معتبر نیست.'], 422);
        }
    }

    public function claimRewards(): void
    {
        $userId = (int)$this->userId();
        $result = $this->referralService->checkAndAwardMilestones($userId);
        $this->response->json(['success' => true, 'result' => $result]);
    }

    /**
     * Master E2E Functional Browser Verification for Section 8.4 Referral Bounded Domain Operations (RF-01 to RF-05)
     */
}

