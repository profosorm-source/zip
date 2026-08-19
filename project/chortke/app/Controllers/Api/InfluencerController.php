<?php

namespace App\Controllers\Api;

use App\Models\InfluencerModel;
use App\Models\StoryOrder;
use App\Models\Dispute;
use App\Services\InfluencerService;
use App\Services\Shared\DisputeService;
use App\Services\UploadService;
use App\Services\VerificationService;

/**
 * API\InfluencerController
 *
 * -- پروفایل اینفلوئنسر --
 * GET    /api/v1/influencer/profile               → پروفایل خودم
 * POST   /api/v1/influencer/profile               → ثبت / ویرایش پروفایل
 * POST   /api/v1/influencer/profile/verify        → ثبت لینک پست تایید مالکیت
 *
 * -- بازار (عمومی) --
 * GET    /api/v1/influencer/list                  → لیست اینفلوئنسرهای تایید شده
 * GET    /api/v1/influencer/{id}                  → جزئیات + رتبه یک پروفایل
 *
 * -- سفارش‌ها (تبلیغ‌دهنده) --
 * POST   /api/v1/influencer/orders                → ثبت سفارش
 * GET    /api/v1/influencer/orders/placed         → سفارش‌هایی که داده‌ام
 * POST   /api/v1/influencer/orders/{id}/confirm   → تایید انجام
 * POST   /api/v1/influencer/orders/{id}/dispute   → اعتراض
 *
 * -- سفارش‌ها (اینفلوئنسر) --
 * GET    /api/v1/influencer/orders/received       → سفارش‌های دریافتی
 * POST   /api/v1/influencer/orders/{id}/respond   → قبول / رد
 * POST   /api/v1/influencer/orders/{id}/proof     → ثبت مدرک
 *
 * -- اختلاف --
 * GET    /api/v1/influencer/orders/{id}/dispute   → جزئیات اختلاف
 * POST   /api/v1/influencer/orders/{id}/dispute/message   → ارسال پیام
 * POST   /api/v1/influencer/orders/{id}/dispute/escalate  → ارجاع به مدیر
 * POST   /api/v1/influencer/orders/{id}/dispute/resolve   → توافق دوطرفه
 */
class InfluencerController extends BaseApiController
{
    private InfluencerService       $promotionService;
    private DisputeService              $disputeService;
    private VerificationService         $verificationService;
    private UploadService               $uploadService;
    private InfluencerModel             $profileModel;
    private StoryOrder                  $orderModel;
    private Dispute                     $disputeModel;

    public function __construct(
        InfluencerService       $promotionService,
        DisputeService              $disputeService,
        VerificationService         $verificationService,
        UploadService               $uploadService,
        InfluencerModel             $profileModel,
        StoryOrder                  $orderModel,
        Dispute                     $disputeModel,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->promotionService  = $promotionService;
        $this->disputeService    = $disputeService;
        $this->verificationService = $verificationService;
        $this->uploadService     = $uploadService;
        $this->profileModel      = $profileModel;
        $this->orderModel        = $orderModel;
        $this->disputeModel      = $disputeModel;
    }

    // ══════════════════════════════════════════════════════
    //  پروفایل اینفلوئنسر
    // ══════════════════════════════════════════════════════

    /**
     * GET /api/v1/influencer/profile
     * پروفایل اینفلوئنسر خودم + آمار رتبه
     */
    public function myProfile(): void
    {
        $userId = (int)$this->userId();
        $profile = $this->promotionService->getProfileByUserId($userId);

        if (!$profile || !isset($profile->id)) {
            $this->success(null, 'پروفایلی ثبت نشده است');
            return;
        }

        $stats = $this->profileModel->getReputationStats((int)$profile->id);
        $verificationStatus = $this->verificationService->getVerificationStatus((int)$profile->id);

        $this->success([
            'profile' => $this->formatProfile($profile),
            'stats'   => $this->formatStats($stats),
            'verification' => $verificationStatus,
        ]);
    }

    /**
     * POST /api/v1/influencer/profile
     * ثبت پروفایل جدید یا ویرایش
     */
    public function saveProfile(): void
    {
        $userId = (int)$this->userId();
        $data   = $this->request->body();

        $required = ['username', 'page_url', 'follower_count'];
        $errors   = [];
        foreach ($required as $f) {
            if (empty($data[$f])) $errors[$f] = "فیلد {$f} الزامی است";
        }
        if (!empty($errors)) $this->validationError($errors);

        $existing = $this->profileModel->findByUserId($userId);

        if ($existing) {
            $merged = \array_merge((array)$existing, $data);
            if (\in_array($existing->status, ['rejected'])) {
                $merged['status'] = 'pending';
                $merged['rejection_reason'] = null;
            }
            $ok = $this->profileModel->update((int)$existing->id, $merged);
            if (!$ok) { $this->error('خطا در بروزرسانی پروفایل', 400, 'PROFILE_UPDATE_FAILED'); return; }
            $profile = $this->profileModel->find((int)$existing->id);
        } else {
            $result = $this->promotionService->registerInfluencer($userId, $data);
            if (!$result['success']) { $this->error($result['message'], 422); return; }
            $profile = $result['profile'] ?? null;
        }

        if ($profile === null) {
            $this->error('پروفایل قابل بازیابی نیست.', 500, 'PROFILE_LOAD_FAILED');
            return;
        }

        $this->success([
            'profile'           => $this->formatProfile($profile),
            'verification_code' => $profile->verification_code ?? null,
        ], $existing ? 'پروفایل بروزرسانی شد.' : 'پروفایل ثبت شد. کد تایید را منتشر کنید.');
    }

    /**
     * POST /api/v1/influencer/profile/verify
     * ثبت لینک پست تایید مالکیت
     */
    public function submitVerification(): void
    {
        $userId = (int)$this->userId();
        $rawPostUrl = $this->request->body('post_url');
        $postUrl = \trim(is_string($rawPostUrl) ? $rawPostUrl : '');

        if (empty($postUrl)) { $this->validationError(['post_url' => 'لینک پست الزامی است']); return; }

        $profile = $this->profileModel->findByUserId($userId);
        if (!$profile) { $this->error('پروفایل یافت نشد.', 404); return; }

        $status = $this->verificationService->getVerificationStatus((int)$profile->id);
        if (in_array($status['status'], ['not_started', 'expired'], true)) {
            $generate = $this->verificationService->generateVerificationCode((int)$profile->id);
            if (!$generate['ok']) {
                $this->error(str_value($generate['error'] ?? 'خطا در تولید کد تایید'), 422);
                return;
            }
        }

        $result = $this->verificationService->submitVerificationProof((int)$profile->id, $userId, $postUrl);
        if (!$result['ok']) { $this->error(str_value($result['error'] ?? 'خطا در ثبت لینک تایید'), 422); return; }

        $this->success(['verification_id' => $result['verification_id']], str_value($result['message'] ?? 'اثبات ارسال شد.'));
    }

    // ══════════════════════════════════════════════════════
    //  بازار — لیست و جزئیات
    // ══════════════════════════════════════════════════════

    /**
     * GET /api/v1/influencer/list
     * لیست اینفلوئنسرهای تایید شده با فیلتر و رتبه
     */
    public function getList(): void
    {
        [$page, $perPage, $offset] = $this->paginationParams(15);

        $filters = [
            'category'      => $this->request->get('category')      ?? '',
            'platform'      => $this->request->get('platform')      ?? '',
            'search'        => $this->request->get('search')        ?? '',
            'min_followers' => $this->request->get('min_followers') ?? '',
            'max_price'     => $this->request->get('max_price')     ?? '',
        ];
        $sortRaw = $this->request->get('sort');
        $sort = is_string($sortRaw) ? $sortRaw : 'priority';

        $profiles = $this->promotionService->listVerifiedProfiles($filters, $sort, $perPage, $offset);
        $total    = $this->promotionService->countVerifiedProfiles($filters);

        // Batch fetch reputation stats (1 query instead of N)
        $profileIds = array_map(fn($p) => (int)$p->id, $profiles);
        $statsMap   = $this->profileModel->getReputationStatsBatch($profileIds);

        $items = \array_map(function($p) use ($statsMap) {
            $stats = $statsMap[(int)$p->id] ?? $this->profileModel->getReputationStats((int)$p->id);
            return \array_merge($this->formatProfile($p), ['stats' => $this->formatStats($stats)]);
        }, $profiles);

        $this->paginated($items, $total, $page, $perPage);
    }

    /**
     * GET /api/v1/influencer/{id}
     * جزئیات کامل یک اینفلوئنسر
     */
    public function show(): void
    {
        $id      = (int)($this->request->param('id') ?? 0);
        $profile = $this->promotionService->getProfileById($id);

        if (!$profile || !isset($profile->id) || $profile->status !== 'verified' || !(int)$profile->is_active) {
            $this->error('اینفلوئنسر یافت نشد', 404, 'NOT_FOUND');
            return;
        }

        $stats = $this->profileModel->getReputationStats($id);

        $this->success([
            'profile' => $this->formatProfile($profile),
            'stats'   => $this->formatStats($stats),
            'pricing' => $this->formatPricing($profile),
        ]);
    }

    // ══════════════════════════════════════════════════════
    //  سفارش‌ها — تبلیغ‌دهنده
    // ══════════════════════════════════════════════════════

    /**
     * POST /api/v1/influencer/orders
     */
    public function createOrder(): void
    {
        $userId = (int)$this->userId();
        $data   = $this->request->body();

        if (empty($data['influencer_id'])) {
            $this->validationError(['influencer_id' => 'شناسه اینفلوئنسر الزامی است']);
        }
        if (empty($data['order_type'])) {
            $this->validationError(['order_type' => 'نوع سفارش الزامی است']);
        }
        if (empty($data['caption'])) {
            $this->validationError(['caption' => 'توضیح سفارش الزامی است']);
        }

        $influencerId = is_numeric($data['influencer_id'] ?? null) ? (int)$data['influencer_id'] : 0;

        $result = $this->promotionService->createOrder(
            $userId,
            $influencerId,
            $data
        );

        if (!$result['success']) { $this->error($result['message'], 422, 'ORDER_FAILED'); return; }

        $order = $result['order'] ?? null;
        if ($order === null) {
            $this->error('سفارش ثبت نشد.', 500, 'ORDER_CREATE_FAILED');
            return;
        }

        $this->success(
            ['order' => $this->formatOrder($order)],
            $result['message'],
            201
        );
    }

    /**
     * GET /api/v1/influencer/orders/placed
     */
    public function myPlacedOrders(): void
    {
        $userId = (int)$this->userId();
        [$page, $perPage, $offset] = $this->paginationParams(20);
        $statusRaw = $this->request->get('status');
        $status = $statusRaw !== null && is_scalar($statusRaw) ? (string)$statusRaw : null;

        $orders = $this->promotionService->getOrdersByCustomer($userId, $status, $perPage, $offset);
        $total  = $this->promotionService->countOrdersByCustomer($userId, $status);

        $this->paginated(
            \array_map([$this, 'formatOrder'], $orders),
            $total, $page, $perPage
        );
    }

    /**
     * POST /api/v1/influencer/orders/{id}/confirm
     */
    public function buyerConfirm(): void
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $result  = $this->promotionService->buyerConfirm($orderId, (int)$this->userId());

        if (!$result['success']) $this->error($result['message'], 422);
        $this->success(null, $result['message']);
    }

    /**
     * POST /api/v1/influencer/orders/{id}/dispute
     */
    public function buyerDispute(): void
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $rawReason = $this->request->body('reason');
        $reason = \trim(is_string($rawReason) ? $rawReason : '');

        if (empty($reason)) { $this->validationError(['reason' => 'دلیل اعتراض الزامی است']); return; }

        // 🔐 Architectural Fix: Single command execution for domain consistency (Phase 1 Fix)
        // Banned duplicate call to disputeService->openDispute to prevent split-brain domain models
        $result = $this->promotionService->buyerDispute($orderId, (int)$this->userId(), $reason);
        if (!$result['success']) $this->error($result['message'], 422);

        $this->success(
            ['order_id' => $orderId],
            $result['message'],
            201
        );
    }

    // ══════════════════════════════════════════════════════
    //  سفارش‌ها — اینفلوئنسر
    // ══════════════════════════════════════════════════════

    /**
     * GET /api/v1/influencer/orders/received
     */
    public function receivedOrders(): void
    {
        $userId = (int)$this->userId();
        $profile = $this->profileModel->findByUserId($userId);

        if (!$profile) { $this->error('ابتدا پروفایل ثبت کنید', 403, 'NO_PROFILE'); return; }

        [$page, $perPage, $offset] = $this->paginationParams(20);
        $statusRaw = $this->request->get('status');
        $status = $statusRaw !== null && is_scalar($statusRaw) ? (string)$statusRaw : null;

        $orders = $this->orderModel->getByInfluencer($userId, $status, $perPage, $offset);
        $total  = \count($this->orderModel->getByInfluencer($userId, $status, 1000, 0));

        $this->paginated(
            \array_map([$this, 'formatOrder'], $orders),
            $total, $page, $perPage
        );
    }

    /**
     * POST /api/v1/influencer/orders/{id}/respond
     * body: { "action": "accept"|"reject", "reason": "..." }
     */
    public function respondOrder(): void
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $actionRaw = $this->request->body('action');
        $reasonRaw = $this->request->body('reason');
        $action  = is_string($actionRaw) ? $actionRaw : '';
        $reason  = is_string($reasonRaw) ? $reasonRaw : null;

        if (!\in_array($action, ['accept', 'reject'])) {
            $this->validationError(['action' => 'مقدار باید accept یا reject باشد']);
            return;
        }

        $result = $this->promotionService->respondToOrder(
            $orderId, (int)$this->userId(), $action, $reason
        );

        if (!$result['success']) $this->error($result['message'], 422);
        $this->success(null, $result['message']);
    }

    /**
     * POST /api/v1/influencer/orders/{id}/proof
     * multipart/form-data: proof_link, proof_notes, proof_screenshot(file)
     */
    public function submitProof(): void
    {
        $orderId   = (int)($this->request->param('id') ?? 0);
        $rawProofLink  = $this->request->body('proof_link');
        $rawProofNotes = $this->request->body('proof_notes');
        $proofData = [
            'proof_link'  => \trim(is_string($rawProofLink) ? $rawProofLink : ''),
            'proof_notes' => \trim(is_string($rawProofNotes) ? $rawProofNotes : ''),
        ];

        if (empty($proofData['proof_link'])) {
            $this->validationError(['proof_link' => 'لینک مدرک الزامی است']);
            return;
        }

        if (!empty($_FILES['proof_screenshot']['name'])) {
            $up = $this->uploadService->upload($_FILES['proof_screenshot'], 'inf-proof');
            if ($up['success']) $proofData['proof_screenshot'] = $up['path'];
        }

        $result = $this->promotionService->submitProof($orderId, (int)$this->userId(), $proofData);

        if (!$result['success']) $this->error($result['message'], 422);
        $this->success(null, $result['message']);
    }

    // ══════════════════════════════════════════════════════
    //  اختلاف
    // ══════════════════════════════════════════════════════

    /**
     * GET /api/v1/influencer/orders/{id}/dispute
     */
    public function getDispute(): void
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $userId = (int)$this->userId();
        $order   = $this->orderModel->find((int)$orderId);

        if (!$order || !isset($order->id)) {
            $this->error('سفارش یافت نشد', 404);
            return;
        }

        $isParty = (int)($order?->customer_id ?? 0) === $userId
                || (int)($order?->influencer_user_id ?? 0) === $userId;
        if (!$isParty) {
            $this->error('دسترسی غیرمجاز', 403, 'FORBIDDEN');
            return;
        }

        $dispute  = $this->disputeModel->findByOrderId($orderId);
        if (!$dispute || !isset($dispute->id)) {
            $this->error('اختلافی یافت نشد', 404, 'NO_DISPUTE');
            return;
        }

        $messages = $this->disputeModel->getMessages((int)$dispute->id);
        $role     = (int)$order->influencer_user_id === $userId ? 'influencer' : 'customer';

        $this->success([
            'dispute'  => $this->formatDispute($dispute),
            'messages' => \array_map([$this, 'formatDisputeMessage'], $messages),
            'role'     => $role,
            'order'    => $this->formatOrder($order),
        ]);
    }

    /**
     * POST /api/v1/influencer/orders/{id}/dispute/message
     * body: { "message": "..." }, file: attachment
     */
    public function sendDisputeMessage(): void
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $userId = (int)$this->userId();
        $rawText = $this->request->body('message');
        $text    = \trim(is_string($rawText) ? $rawText : '');

        if (empty($text)) { $this->validationError(['message' => 'متن پیام الزامی است']); return; }

        $order = $this->orderModel->find((int)$orderId);
        if (!$order) { $this->error('سفارش یافت نشد', 404); return; }

        $role    = (int)$order->influencer_user_id === $userId ? 'influencer' : 'customer';
        $dispute = $this->disputeModel->findByOrderId($orderId);
        if (!$dispute) { $this->error('اختلاف یافت نشد', 404); return; }

        $attachment = null;
        if (!empty($_FILES['attachment']['name'])) {
            $up = $this->uploadService->upload($_FILES['attachment'], 'dispute-evidence');
            if ($up['success']) $attachment = $up['path'];
        }

        $result = $this->disputeService->sendMessage(
            (int)$dispute->id, $userId, $role, $text, $attachment
        );

        if (empty($result['success'])) {
            $this->error(is_string($result['message'] ?? null) ? $result['message'] : 'ثبت پیام اختلاف ناموفق بود.', 422);
            return;
        }
        $messageRow = $result['message_item'] ?? null;
        if (!($messageRow instanceof \stdClass)) {
            $this->error('پیام ثبت شد اما قابل بازیابی نیست.', 500);
            return;
        }
        $this->success(
            ['message' => $this->formatDisputeMessage($messageRow)],
            'پیام اختلاف ثبت شد.',
            201
        );
    }

    /**
     * POST /api/v1/influencer/orders/{id}/dispute/escalate
     */
    public function escalateDispute(): void
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $dispute = $this->disputeModel->findByOrderId($orderId);
        if (!$dispute) { $this->error('اختلاف یافت نشد', 404); return; }

        $result = $this->disputeService->escalateToAdmin((int)$dispute->id, (int)$this->userId());
        if (!$result['success']) { $this->error(is_string($result['message']) ? $result['message'] : 'خطا در ارجاع اختلاف.', 422); return; }
        $this->success(null, is_string($result['message']) ? $result['message'] : 'اختلاف ارجاع شد.');
    }

    /**
     * POST /api/v1/influencer/orders/{id}/dispute/resolve
     * body: { "verdict": "favor_influencer|favor_customer|partial", "resolution": "..." }
     */
    public function resolveDispute(): void
    {
        $orderId    = (int)($this->request->param('id') ?? 0);
        $verdictRaw = $this->request->body('verdict');
        $resolutionRaw = $this->request->body('resolution');
        $verdict    = is_string($verdictRaw) ? $verdictRaw : '';
        $resolution = is_string($resolutionRaw) ? $resolutionRaw : '';

        $allowed = ['favor_influencer', 'favor_customer', 'partial'];
        if (!\in_array($verdict, $allowed)) {
            $this->validationError(['verdict' => 'مقدار verdict نامعتبر است']);
            return;
        }

        $user = $this->currentUser();
        if ($user === null) { $this->error('Unauthorized', 401); return; }
        if (!in_array((string)($user->role ?? ''), ['admin', 'super_admin'], true)) {
            $this->error('فقط ادمین مجاز به داوری اختلاف است', 403, 'FORBIDDEN');
            return;
        }

        $dispute = $this->disputeModel->findByOrderId($orderId);
        if (!$dispute) { $this->error('اختلاف یافت نشد', 404); return; }

        $result = $this->disputeService->resolveByAgreement(
            (int)$dispute->id, (int)$this->userId(), $resolution, $verdict
        );

        if (!$result['success']) { $this->error(is_string($result['message']) ? $result['message'] : 'خطا در حل اختلاف.', 422); return; }
        $this->success(null, is_string($result['message']) ? $result['message'] : 'اختلاف حل شد.');
    }

    // ══════════════════════════════════════════════════════
    //  Formatters — خروجی یکدست برای موبایل
    // ══════════════════════════════════════════════════════

    /** @return array<string, mixed> */
    private function formatProfile(\stdClass $p): array
    {
        $userId = (int)$this->userId();
        $isOwnerOrAdmin = ((int)($p->user_id ?? 0) === $userId) || in_array($this->currentUser()?->role ?? '', ['admin', 'super_admin'], true);

        $formatted = [
            'id'               => (int)$p->id,
            'username'         => $p->username,
            'page_url'         => $p->page_url,
            'platform'         => $p->platform ?? 'instagram',
            'profile_image'    => $p->profile_image ?? null,
            'follower_count'   => (int)($p->follower_count ?? 0),
            'engagement_rate'  => (float)($p->engagement_rate ?? 0),
            'category'         => $p->category ?? null,
            'bio'              => $p->bio ?? null,
            'status'           => $p->status,
            'is_active'        => (bool)($p->is_active ?? false),
            'total_orders'     => (int)($p->total_orders ?? 0),
            'completed_orders' => (int)($p->completed_orders ?? 0),
            'pricing'          => $this->formatPricing($p),
            'verified_at'      => $p->verified_at ?? null,
        ];

        // 🛡️ Security Fix (Issue #24): Verification code is private and only included for profile owner or admin
        if ($isOwnerOrAdmin && !empty($p->verification_code)) {
            $formatted['verification_code'] = $p->verification_code;
        }

        return $formatted;
    }

    /** @return list<array{type: string, hours: int, price: float, label: string}> */
    private function formatPricing(\stdClass $p): array
    {
        $pricing = [];
        if (($p->story_price_24h ?? 0) > 0)
            $pricing[] = ['type'=>'story','hours'=>24,'price'=>(float)$p->story_price_24h,'label'=>'استوری ۲۴ ساعته'];
        if (($p->post_price_24h ?? 0) > 0)
            $pricing[] = ['type'=>'post','hours'=>24,'price'=>(float)$p->post_price_24h,'label'=>'پست ۲۴ ساعته'];
        if (($p->post_price_48h ?? 0) > 0)
            $pricing[] = ['type'=>'post','hours'=>48,'price'=>(float)$p->post_price_48h,'label'=>'پست ۴۸ ساعته'];
        if (($p->post_price_72h ?? 0) > 0)
            $pricing[] = ['type'=>'post','hours'=>72,'price'=>(float)$p->post_price_72h,'label'=>'پست ۷۲ ساعته'];
        return $pricing;
    }

/**
 * @param array<string, mixed> $s
 * @return array<string, mixed>
 */
private function formatStats(object|array $s): array
    {
        $s = is_array($s) ? (object)$s : $s;
        return [
            'total_points'     => (int)($s->total_points     ?? 0),
            'total_orders'     => (int)($s->total_orders     ?? 0),
            'completed_orders' => (int)($s->completed_orders ?? 0),
            'disputed_orders'  => (int)($s->disputed_orders  ?? 0),
            'completion_rate'  => (int)($s->completion_rate  ?? 0),
            'dispute_rate'     => (int)($s->dispute_rate     ?? 0),
            'grade'            => $s->grade       ?? '—',
            'grade_label'      => $s->grade_label ?? '—',
            'stars'            => (int)($s->stars ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function formatOrder(\stdClass $o): array
    {
        return [
            'id'                      => (int)$o->id,
            'order_type'              => $o->order_type,
            'duration_hours'          => (int)($o->duration_hours ?? 24),
            'caption'                 => $o->caption ?? null,
            'link'                    => $o->link ?? null,
            'preferred_publish_time'  => $o->preferred_publish_time ?? null,
            'price'                   => (float)($o->price ?? 0),
            'influencer_earning'      => (float)($o->influencer_earning ?? 0),
            'currency'                => $o->currency ?? 'irt',
            'status'                  => $o->status,
            'proof_link'              => $o->proof_link ?? null,
            'proof_notes'             => $o->proof_notes ?? null,
            'proof_screenshot'        => $o->proof_screenshot ?? null,
            'proof_submitted_at'      => $o->proof_submitted_at ?? null,
            'buyer_check_deadline'    => $o->buyer_check_deadline ?? null,
            'influencer_username'     => $o->influencer_username ?? null,
            'influencer_avatar'       => $o->influencer_avatar ?? null,
            'customer_name'           => $o->customer_name ?? null,
            'created_at'              => $o->created_at,
            'updated_at'              => $o->updated_at ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function formatDispute(\stdClass $d): array
    {
        return [
            'id'              => (int)$d->id,
            'order_id'        => (int)$d->order_id,
            'status'          => $d->status,
            'reason'          => $d->reason ?? null,
            'peer_deadline'   => $d->peer_deadline ?? null,
            'resolution_note' => $d->resolution_note ?? null,
            'admin_verdict'   => $d->admin_verdict ?? null,
            'created_at'      => $d->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function formatDisputeMessage(\stdClass $m): array
    {
        return [
            'id'          => (int)$m->id,
            'role'        => $m->role,
            'sender_name' => $m->sender_name ?? null,
            'message'     => $m->message,
            'attachment'  => $m->attachment ?? null,
            'created_at'  => $m->created_at,
        ];
    }
}
