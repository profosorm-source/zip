<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Services\Analytics\AnalyticsQueryService;
use App\Services\BannerService;
use App\Services\InfluencerService;
use App\Models\LotteryRound;

class HomeController extends BaseController
{
    private AnalyticsQueryService $analyticsService;
    private BannerService $bannerService;
    private InfluencerService $influencerService;
    private LotteryRound $lotteryModel;

    public function __construct(
        AnalyticsQueryService $analyticsService,
        BannerService $bannerService,
        InfluencerService $influencerService,
        LotteryRound $lotteryModel
    ) {
        parent::__construct();
        $this->analyticsService  = $analyticsService;
        $this->bannerService     = $bannerService;
        $this->influencerService = $influencerService;
        $this->lotteryModel      = $lotteryModel;
    }

    public function index(): mixed
    {
        // Public homepage must never stay unavailable because an optional
        // dashboard/marketing data source is slow or temporarily unavailable.
        // Each dynamic block is loaded independently with safe fallbacks.
        $stats = (object)[
            'users'        => 0,
            'tasks'        => 0,
            'transactions' => 0,
            'winners'      => 0,
        ];
        $banners = [];
        $influencers = [];
        $winners = [];

        try {
            $analyticsService = $this->analyticsService;
            $summaryValue = $analyticsService->getDashboardSummary(['users', 'task', 'financial', 'lottery']);
            $summary = is_array($summaryValue) ? $summaryValue : [];
            $users = is_array($summary['users'] ?? null) ? $summary['users'] : [];
            $tasks = is_array($summary['tasks'] ?? null) ? $summary['tasks'] : [];
            $financials = is_array($summary['financials'] ?? null) ? $summary['financials'] : [];
            $lotteries = is_array($summary['lotteries'] ?? null) ? $summary['lotteries'] : [];
            $stats = (object)[
                'users' => int_value($users['total'] ?? 0),
                'tasks' => int_value($tasks['total_completed'] ?? 0),
                'transactions' => int_value($financials['total_count'] ?? 0),
                'winners' => int_value($lotteries['total_winners'] ?? 0),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('home.analytics_unavailable', ['error' => $e->getMessage()]);
        }

        $bannerData = $this->bannerService->getActiveBanners('home');
        $banners = $bannerData['banners'] ?? [];

        try {
            $rawInfluencers = $this->influencerService->listVerifiedProfiles([], 'priority', 10);
            $influencers = array_map(function($inf) {
                return (object)[
                    'id'                 => $inf->id ?? 0,
                    'instagram_username' => $inf->username ?? '',
                    'follower_count'     => $inf->follower_count ?? 0,
                    'story_price'        => $inf->story_price_24h ?? 0,
                    'avatar'             => $inf->profile_image ?? null,
                ];
            }, is_array($rawInfluencers) ? $rawInfluencers : []);
        } catch (\Throwable $e) {
            $this->logger->warning('home.influencers_unavailable', ['error' => $e->getMessage()]);
        }

        try {
            $rawWinners = $this->lotteryModel->getCompletedRounds(5);
            $winners = array_map(function($w) {
                return (object)[
                    'id'           => $w->id ?? 0,
                    'full_name'    => $w->winner_name ?? 'کاربر',
                    'prize_amount' => $w->prize_amount ?? 0,
                    'created_at'   => $w->end_date ?? date('Y-m-d'),
                ];
            }, is_array($rawWinners) ? $rawWinners : []);
        } catch (\Throwable $e) {
            $this->logger->warning('home.lottery_winners_unavailable', ['error' => $e->getMessage()]);
        }

        $isLoggedIn = auth() !== null;

        $faqs = [
            [
                'question' => 'چرتکه چیست؟',
                'answer'   => 'چرتکه یک پلتفرم حرفه‌ای کسب درآمد آنلاین است. با انجام تسک‌های شبکه‌های اجتماعی، سرمایه‌گذاری، قرعه‌کشی روزانه، تولید محتوا و معرفی دوستان درآمد واقعی کسب کنید.',
            ],
            [
                'question' => 'چگونه ثبت‌نام کنم؟',
                'answer'   => 'با کلیک روی دکمه «ثبت‌نام» و وارد کردن نام، ایمیل، شماره موبایل و رمز عبور در کمتر از ۳۰ ثانیه عضو شوید. ثبت‌نام کاملاً رایگان است.',
            ],
            [
                'question' => 'حداقل مبلغ برداشت چقدر است؟',
                'answer'   => 'حداقل مبلغ برداشت بر اساس تنظیمات فعلی سایت تعیین می‌شود. برای اطلاع دقیق از مبلغ به بخش کیف پول در پنل کاربری خود مراجعه کنید.',
            ],
            [
                'question' => 'آیا سرمایه‌گذاری ریسک دارد؟',
                'answer'   => 'بله، سرمایه‌گذاری همواره ریسک سود و ضرر دارد. لطفاً قبل از سرمایه‌گذاری هشدارهای ریسک را به دقت مطالعه کنید. سیستم هیچ تضمینی برای سود نمی‌دهد.',
            ],
            [
                'question' => 'سیستم قرعه‌کشی چگونه کار می‌کند؟',
                'answer'   => 'هر روز ۳ عدد تصادفی تولید می‌شود و شما یکی را انتخاب می‌کنید. سیستم وزن‌دهی خودکار و عادلانه برنده نهایی را انتخاب می‌کند. هیچ کاربری حذف نمی‌شود و امید همه تا آخر حفظ می‌شود.',
            ],
            [
                'question' => 'چگونه احراز هویت انجام دهم؟',
                'answer'   => 'در بخش پروفایل، تصویر کارت ملی و سلفی با دست‌نوشته آپلود کنید. پس از بررسی توسط تیم ما، حساب شما تأیید خواهد شد و دسترسی کامل خواهید داشت.',
            ],
            [
                'question' => 'آیا می‌توانم با تتر (USDT) کار کنم؟',
                'answer'   => 'بله. بخش سرمایه‌گذاری بر اساس تتر عمل می‌کند. همچنین اگر سایت در حالت تتری فعال باشد، تمام بخش‌ها با تتر کار خواهند کرد.',
            ],
        ];

        // view() در این پروژه خودش خروجی را echo می‌کند و string هم برمی‌گرداند.
        // اگر آن string را از action برگردانیم، Router دوباره echo می‌کند و صفحه دوبار رندر می‌شود.
        view('welcome', [
            'stats'        => $stats,
            'banners'      => $banners,
            'influencers'  => $influencers,
            'winners'      => $winners,
            'faqs'         => $faqs,
        ]);
        return null;
    }
}
