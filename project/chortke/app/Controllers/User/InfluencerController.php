<?php

namespace App\Controllers\User;

use App\Models\InfluencerModel;
use App\Models\StoryOrder;
use App\Models\Dispute;

use App\Services\InfluencerService;
use App\Services\Shared\DisputeService;
use App\Services\UploadService;
use App\Services\VerificationService;
use Core\Logger;
use Core\Database;
use App\Validators\Requests\CreateInfluencerOrderRequest;

class InfluencerController extends BaseUserController
{
    private InfluencerModel           $profileModel;
    private StoryOrder                  $orderModel;
    private Dispute           $disputeModel;
    private InfluencerModel             $reputationModel;
    private InfluencerService       $promotionService;
    private DisputeService              $disputeService;
    private VerificationService         $verificationService;
    private UploadService               $upload;
    // $logger inherited from parent

    public function __construct(
        InfluencerModel           $profileModel,
        StoryOrder                  $orderModel,
        Dispute           $disputeModel,
        InfluencerModel             $reputationModel,
        InfluencerService       $promotionService,
        DisputeService              $disputeService,
        VerificationService         $verificationService,
        UploadService               $upload,
        Logger                      $logger
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->profileModel        = $profileModel;
        $this->orderModel          = $orderModel;
        $this->disputeModel        = $disputeModel;
        $this->reputationModel     = $reputationModel;
        $this->promotionService    = $promotionService;
        $this->disputeService      = $disputeService;
        $this->verificationService = $verificationService;
        $this->upload              = $upload;
        $this->logger              = $logger;
    }

    // ══════════════════════════════════════════════════════
    //  پروفایل اینفلوئنسر
    // ══════════════════════════════════════════════════════

    public function myProfile(): void
    {
        $userId  = (int) user_id();
        $profile = $this->profileModel->findByUserId($userId);
        $stats   = $profile ? $this->normalizeInfluencerStats($this->reputationModel->getReputationStats((int)$profile->id)) : null;
        $orders  = $profile ? $this->orderModel->getByInfluencer((int)$profile->user_id, null, 20, 0) : [];
        $placedOrders = $this->orderModel->getByCustomer($userId, null, 20, 0);
        $marketProfiles = array_values(array_filter(
            $this->profileModel->getVerified([], 'priority', 12, 0),
            static fn($p): bool => (int)($p->user_id ?? 0) !== $userId
        ));
        // Batch fetch reputation stats (1 query instead of N)
        $marketIds = array_map(fn($mp) => (int)$mp->id, $marketProfiles);
        $marketStatsRaw = $this->reputationModel->getReputationStatsBatch($marketIds);
        $marketStatsMap = [];
        foreach ($marketProfiles as $marketProfile) {
            $pid = (int)$marketProfile->id;
            $marketStatsMap[$pid] = $this->normalizeInfluencerStats(
                $marketStatsRaw[$pid] ?? $this->reputationModel->getReputationStats($pid)
            );
        }
        $allDisputes = $this->disputeModel->getByUser($userId, 20, 0);
        $disputes = array_values(array_filter($allDisputes, static fn($d): bool => in_array((string)($d->ref_type ?? ''), ['influencer_order', 'story_order', 'order', 'influencer'], true)));
        $incomingNeedsAction = count(array_filter($orders, static fn($o): bool => in_array((string)($o->status ?? ''), ['pending', 'paid', 'pending_acceptance'], true)));
        $placedNeedsCheck = count(array_filter($placedOrders, static fn($o): bool => in_array((string)($o->status ?? ''), ['awaiting_buyer_check', 'proof_submitted'], true)));
        $verificationStatus = null;
        $verificationCode   = null;

        if ($profile) {
            $verificationStatus = $this->verificationService->getVerificationStatus((int)$profile->id);

            if (in_array($verificationStatus['status'], ['not_started', 'expired'], true)) {
                $generate = $this->verificationService->generateVerificationCode((int)$profile->id);
                if ($generate['ok']) {
                    $verificationCode = $generate['code'];
                    if (empty($profile->verification_code) || $profile->verification_code !== $verificationCode) {
                        $this->profileModel->update((int)$profile->id, ['verification_code' => $verificationCode]);
                        $profile = $this->profileModel->find((int)$profile->id);
                    }
                    $verificationStatus['status'] = 'pending';
                    $verificationStatus['code'] = $verificationCode;
                }
            } else {
                $verificationCode = $verificationStatus['code'] ?? $profile->verification_code ?? null;
            }
        }

        $this->view('user/influencer/my-profile', [
            'title'              => 'پروفایل اینفلوئنسر',
            'profile'            => $profile,
            'stats'              => $stats,
            'orders'             => $orders,
            'placedOrders'       => $placedOrders,
            'marketProfiles'     => $marketProfiles,
            'marketStatsMap'     => $marketStatsMap,
            'disputes'           => $disputes,
            'incomingNeedsAction'=> $incomingNeedsAction,
            'placedNeedsCheck'   => $placedNeedsCheck,
            'platforms'          => $this->platforms(),
            'categories'         => $this->profileModel->categories(),
            'verificationStatus' => $verificationStatus,
            'verificationCode'   => $verificationCode,
            'statusLabels'       => $this->orderModel->statusLabels(),
            'statusClasses'      => $this->orderModel->statusClasses(),
        ]);
    }

    public function register(): void
    {
        // COMPATIBILITY_REDIRECT: فرم ثبت/ویرایش از فاز ۲ داخل Hub تک‌صفحه‌ای /influencer است.
        redirect(url('/influencer?section=profile'));
    }

    /**
     * ذخیره پروفایل
     * ✅ File upload validation + ownership check
     */
    public function storeProfile(): void
    {
        $userId = (int) user_id();
        $data   = $this->request->body();

        $request = new \App\Validators\Requests\StoreInfluencerProfileRequest($data);

        if (!$request->validate()) {
            $errors = $request->errors();
            $firstError = reset($errors);
            $msg = is_array($firstError) ? reset($firstError) : $firstError;
            $this->session->setFlash('error', $msg ?: 'اطلاعات ورودی نامعتبر است.');
            redirect(url('/influencer/register'));
        }

        $validatedData = $request->validated();

        // ✅ File upload validation
        if (!empty($_FILES['profile_image']['name'])) {
            $validation = $this->validateProfileImage($_FILES['profile_image']);
            if (!$validation['valid']) {
                $this->session->setFlash('error', $validation['error']);
                redirect(url('/influencer/register'));
            }
            
            $up = $this->upload->upload($_FILES['profile_image'], 'influencer');
            if ($up['success']) {
                $validatedData['profile_image'] = $up['path'];
            } else {
                $this->session->setFlash('error', $up['error'] ?? 'خطا در آپلود تصویر.');
                redirect(url('/influencer/register'));
            }
        }

        $existing = $this->profileModel->findByUserId($userId);
        $platform = str_value($validatedData['platform'] ?? 'instagram');

        // Filter and whitelist safe fields to prevent mass assignment (C-12)
        $allowed = ['platform', 'username', 'bio', 'category', 'follower_count', 'profile_image'];
        $clean = array_intersect_key($validatedData, array_flip($allowed));

        $merged   = array_merge($clean, $this->extractPrices($data, $platform), ['user_id' => $userId]);

        try {
            if ($existing) {
                // Only allow update if owner
                if ((int)$existing->user_id !== $userId) {
                    $this->logger->warning('Unauthorized profile update attempt', [
                        'user_id' => $userId,
                        'profile_owner' => $existing->user_id,
                    ]);
                    http_response_code(403);
                    $this->response->json(['success' => false, 'message' => 'Unauthorized'], 403);
                    return;
                }

                if (in_array($existing->status, ['rejected'])) {
                    $merged['status'] = 'pending';
                    $merged['rejection_reason'] = null;
                }
                $ok  = $this->profileModel->update((int)$existing->id, $merged);
                $msg = $ok ? 'پروفایل بروزرسانی شد.' : 'خطا در بروزرسانی.';
            } else {
                $profile = $this->profileModel->create($merged);
                $ok  = $profile ? true : false;
                $msg = $ok ? 'پروفایل ثبت شد. منتظر تایید ادمین باشید.' : 'خطا در ثبت.';
            }

            if (!$ok) {
                $this->logger->error('influencer.store.failed', ['user_id' => $userId]);
            }

            $this->session->setFlash($ok ? 'success' : 'error', $msg);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('influencer.store.exception', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->session->setFlash('error', 'خطای سیستمی.');
        }

        redirect(url('/influencer?section=profile'));
    }

    /**
     * ✅ File upload validation method
     */
  /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>
     */
  private function validateProfileImage(array $file): array
{
    if (!isset($file['tmp_name']) || !is_uploaded_file(str_value($file['tmp_name']))) {
        return ['valid' => false, 'error' => 'فایل آپلود نشد'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return ['valid' => false, 'error' => 'نوع فایل نامعتبر است'];
    }
    $mime = finfo_file($finfo, str_value($file['tmp_name']));
    finfo_close($finfo);

    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
        return ['valid' => false, 'error' => 'فقط JPG, PNG و WebP مجاز هستند'];
    }

    $maxSize = 2 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        return ['valid' => false, 'error' => 'حجم فایل بیشتر از 2MB است'];
    }

    return ['valid' => true];
}

    /**
     * ثبت لینک پست تایید مالکیت
     */
    public function submitVerification(): void
    {
        $userId  = (int) user_id();
        $postUrl = \trim(str_value($this->request->post('post_url') ?? ''));

        if (empty($postUrl)) {
            $this->session->setFlash('error', 'لینک پست الزامی است.');
            redirect(url('/influencer'));
        }

        $profile = $this->profileModel->findByUserId($userId);
        if (!$profile) {
            $this->session->setFlash('error', 'پروفایل اینفلوئنسر یافت نشد.');
            redirect(url('/influencer/register'));
        }

        $status = $this->verificationService->getVerificationStatus((int)$profile->id);
        if (in_array($status['status'], ['not_started', 'expired'], true)) {
            $generate = $this->verificationService->generateVerificationCode((int)$profile->id);
            if (!$generate['ok']) {
                $this->session->setFlash('error', $generate['error'] ?? 'خطا در تولید کد تایید.');
                redirect(url('/influencer'));
            }

            if (empty($profile->verification_code) || $profile->verification_code !== $generate['code']) {
                $this->profileModel->update((int)$profile->id, ['verification_code' => $generate['code']]);
                $profile = $this->profileModel->find((int)$profile->id);
                if ($profile === null) {
                    $this->session->setFlash('error', 'پروفایل اینفلوئنسر یافت نشد.');
                    redirect(url('/influencer'));
                }
            }
        }

        $screenshotPath = null;
        if (!empty($_FILES['verification_screenshot']['name'])) {
            $validation = $this->validateProfileImage($_FILES['verification_screenshot']);
            if (!$validation['valid']) {
                $this->session->setFlash('error', $validation['error']);
                redirect(url('/influencer'));
            }
            $upload = $this->upload->upload($_FILES['verification_screenshot'], 'influencer-verification');
            if (!empty($upload['success'])) {
                $screenshotPath = $upload['path'] ?? null;
            } else {
                $this->session->setFlash('error', $upload['error'] ?? 'خطا در آپلود اسکرین‌شات.');
                redirect(url('/influencer'));
            }
        }

        $clientSignals = [
            'visible_code' => trim($this->request->str('visible_code')),
            'source' => 'web_screenshot_form',
        ];

        $result = $this->verificationService->submitVerificationProof((int)$profile->id, $userId, $postUrl, $screenshotPath, $clientSignals);

        $this->session->setFlash($result['ok'] ? 'success' : 'error', $result['message'] ?? ($result['error'] ?? 'خطا در ثبت مدرک تایید.'));
        redirect(url('/influencer'));
    }

    // ══════════════════════════════════════════════════════
    //  سفارش‌های دریافتی (اینفلوئنسر)
    // ══════════════════════════════════════════════════════

    public function myOrders(): void
    {
        // COMPATIBILITY_REDIRECT: سفارش‌های دریافتی داخل Hub تک‌صفحه‌ای نمایش داده می‌شوند.
        redirect(url('/influencer?section=incoming'));
    }

    public function respondOrder(): void
    {
        try {
            $id     = (int)($this->request->param('id') ?? 0);
            $action = $this->request->post('action') ?? '';
            $reason = $this->request->post('reason') ?? null;

            $result = $this->promotionService->respondToOrder($id, (int)user_id(), str_value($action), str_value($reason));

            if (is_ajax()) { $this->response->json($result); return; }
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('influencer.respondOrder', ['err' => $e->getMessage()]);
            if (is_ajax()) { $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']); return; }
            $this->session->setFlash('error', 'خطای سیستمی.');
        }
        redirect(url('/influencer/orders'));
    }

    public function submitProof(): void
    {
        try {
            $id        = (int)($this->request->param('id') ?? 0);
            $proofData = [
                'proof_link'  => \trim(str_value($this->request->post('proof_link') ?? '')),
                'proof_notes' => \trim(str_value($this->request->post('proof_notes') ?? '')),
            ];

            if (!empty($_FILES['proof_screenshot']['name'])) {
                $up = $this->upload->upload($_FILES['proof_screenshot'], 'inf-proof');
                if ($up['success']) $proofData['proof_screenshot'] = $up['path'];
            }

            $result = $this->promotionService->submitProof($id, (int)user_id(), $proofData);

            if (is_ajax()) { $this->response->json($result); return; }
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('influencer.submitProof', ['err' => $e->getMessage()]);
            if (is_ajax()) { $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']); return; }
            $this->session->setFlash('error', 'خطای سیستمی.');
        }
        redirect(url('/influencer/orders'));
    }

    // ══════════════════════════════════════════════════════
    //  پنل اختلاف (هر دو طرف)
    // ══════════════════════════════════════════════════════

    public function disputePanel(): void
    {
        $orderId = (int)($this->request->param('id') ?? 0);
        $userId  = (int) user_id();
        $order   = $this->orderModel->find($orderId);

        if (!$order) {
            $this->session->setFlash('error', 'سفارش یافت نشد.');
            redirect(url('/influencer/orders'));
        }

        // هر دو طرف می‌توانند این صفحه را ببینند
        $isInfluencer = (int)$order->influencer_user_id === $userId;
        $isCustomer   = (int)$order->customer_id === $userId;

        if (!$isInfluencer && !$isCustomer) {
            $this->session->setFlash('error', 'دسترسی غیرمجاز.');
            redirect(url('/influencer/orders'));
        }

        $dispute  = $this->disputeModel->findByOrderId($orderId);
        $messages = $dispute ? $this->disputeModel->getMessages((int)$dispute->id) : [];
        $role     = $isInfluencer ? 'influencer' : 'customer';

        $this->view('user/influencer/dispute-panel', [
            'title'    => 'پنل اختلاف',
            'order'    => $order,
            'dispute'  => $dispute,
            'messages' => $messages,
            'role'     => $role,
            'userId'   => $userId,
        ]);
    }

    public function sendDisputeMsg(): void
    {
        try {
            $orderId   = (int)($this->request->param('id') ?? 0);
            $userId    = (int) user_id();
            $order     = $this->orderModel->find($orderId);

            if (!$order) { $this->response->json(['success' => false, 'message' => 'سفارش یافت نشد.']); return; }

            $role    = (int)$order->influencer_user_id === $userId ? 'influencer' : 'customer';
            $dispute = $this->disputeModel->findByOrderId($orderId);
            if (!$dispute) { $this->response->json(['success' => false, 'message' => 'اختلاف یافت نشد.']); return; }

            $message    = \trim(str_value($this->request->post('message') ?? ''));
            $attachment = null;

            if (!empty($_FILES['attachment']['name'])) {
                $up = $this->upload->upload($_FILES['attachment'], 'dispute-evidence');
                if ($up['success']) $attachment = $up['path'];
            }

            $result = $this->disputeService->sendMessage((int)$dispute->id, $userId, $role, $message, $attachment);
            $this->response->json($result);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('influencer.sendDisputeMsg', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
        }
    }

    public function escalateDispute(): void
    {
        try {
            $orderId = (int)($this->request->param('id') ?? 0);
            $userId  = (int) user_id();
            $dispute = $this->disputeModel->findByOrderId($orderId);

            if (!$dispute) { $this->response->json(['success' => false, 'message' => 'اختلاف یافت نشد.']); return; }

            $result = $this->disputeService->escalateToAdmin((int)$dispute->id, $userId);
            $this->response->json($result);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('influencer.escalateDispute', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
        }
    }

    public function resolveDisputePeer(): void
    {
        try {
            $orderId    = (int)($this->request->param('id') ?? 0);
            $userId     = (int) user_id();
            $resolution = \trim(str_value($this->request->post('resolution') ?? ''));
            $verdict    = \trim(str_value($this->request->post('verdict') ?? 'favor_influencer'));
            $dispute    = $this->disputeModel->findByOrderId($orderId);

            if (!$dispute) { $this->response->json(['success' => false, 'message' => 'اختلاف یافت نشد.']); return; }

            $result = $this->disputeService->resolveByAgreement((int)$dispute->id, $userId, $resolution, $verdict);
            $this->response->json($result);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('influencer.resolveDisputePeer', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
        }
    }

    // ══════════════════════════════════════════════════════
    //  تبلیغ‌دهنده — لیست اینفلوئنسرها
    // ══════════════════════════════════════════════════════

    public function advertise(): void
    {
        // COMPATIBILITY_REDIRECT: بازار سفارش اینفلوئنسر داخل Hub تک‌صفحه‌ای است.
        redirect(url('/influencer?section=market'));
    }

    public function createOrder(): void
    {
        // COMPATIBILITY_REDIRECT: فرم سفارش داخل Hub تک‌صفحه‌ای است.
        $influencerId = $this->request->int('influencer_id');
        $suffix = $influencerId > 0 ? '&influencer_id=' . $influencerId : '';
        redirect(url('/influencer?section=market' . $suffix));
    }

    public function storeOrder(): void
    {
        $userId       = (int) user_id();
        $influencerId = $this->request->int('influencer_id');
        $data         = $this->request->body();

        $validator = new CreateInfluencerOrderRequest($data);
        $validator->validate();

        if ($validator->fails()) {
            $errors = $validator->errors();
            $firstError = reset($errors);
            $msg = is_array($firstError) ? reset($firstError) : $firstError;
            if (is_ajax()) {
                $this->response->json(['success' => false, 'message' => $msg ?: 'اطلاعات ورودی نامعتبر است.', 'errors' => $errors], 422);
                return;
            }
            $this->session->setFlash('error', $msg ?: 'اطلاعات ورودی نامعتبر است.');
            redirect(url('/influencer/ads/create?influencer_id=' . $influencerId));
        }

        $validatedData = $validator->validated();

        try {
            if (!empty($_FILES['brief_file']['name'])) {
                $up = $this->upload->upload($_FILES['brief_file'], 'inf-brief');
                if ($up['success']) $validatedData['media_path'] = $up['path'];
            }

            $result = $this->promotionService->createOrder($userId, $influencerId, $validatedData);
            if (is_ajax()) {
                $result['redirect'] = url('/influencer?section=placed');
                $this->response->json($result, !empty($result['success']) ? 200 : 422);
                return;
            }
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
            redirect($result['success']
                ? url('/influencer/ads/my-orders')
                : url('/influencer/ads/create?influencer_id=' . $influencerId)
            );
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('influencer.storeOrder', ['err' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطای سیستمی در ثبت سفارش.');
            redirect(url('/influencer/ads'));
        }
    }

    public function myPlacedOrders(): void
    {
        // COMPATIBILITY_REDIRECT: سفارش‌های ثبت‌شده داخل Hub تک‌صفحه‌ای نمایش داده می‌شوند.
        redirect(url('/influencer?section=placed'));
    }

    /**
     * تایید buyer — سفارش درست انجام شده
     */
    public function buyerConfirm(): void
    {
        try {
            $orderId = (int)($this->request->param('id') ?? 0);
            $result  = $this->promotionService->buyerConfirm($orderId, (int)user_id());

            if (is_ajax()) { $this->response->json($result); return; }
            $this->session->setFlash($result['success'] ? 'success' : 'error', $result['message']);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('influencer.buyerConfirm', ['err' => $e->getMessage()]);
            if (is_ajax()) { $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']); return; }
            $this->session->setFlash('error', 'خطای سیستمی.');
        }
        redirect(url('/influencer/ads/my-orders'));
    }

    /**
     * اعتراض buyer → شروع peer resolution
     */
    public function buyerDispute(): void
    {
        try {
            $orderId = (int)($this->request->param('id') ?? 0);
            $reason  = \trim(str_value($this->request->post('reason') ?? ''));
            $userId  = (int) user_id();

            if (empty($reason)) {
                $this->response->json(['success' => false, 'message' => 'دلیل اعتراض الزامی است.']);
                return;
            }

            // 🔐 Architectural Fix: Single command execution for domain consistency (Phase 1 Fix)
            // Banned duplicate call to disputeService->openDispute to prevent split-brain domain models
            $result = $this->promotionService->buyerDispute($orderId, $userId, $reason);
            $this->response->json($result);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('influencer.buyerDispute', ['err' => $e->getMessage()]);
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی.']);
        }
    }

    // ══════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════

    /**
     * Normalize reputation stats from InfluencerModel::getReputationStats() for view rendering.
     * getReputationStats() already returns proper field names; this method just casts types.
     * @param array<string, mixed>|null $stats
     */
    private function normalizeInfluencerStats(object|array|null $stats): object
    {
        $s = is_array($stats) ? (object)$stats : ($stats ?? (object)[]);
        return (object)[
            'total_points'     => (float)($s->total_points     ?? 0),
            'total_orders'     => (int)($s->total_orders       ?? 0),
            'completed_orders' => (int)($s->completed_orders   ?? 0),
            'disputed_orders'  => (int)($s->disputed_orders    ?? 0),
            'completion_rate'  => (float)($s->completion_rate  ?? 0),
            'dispute_rate'     => (float)($s->dispute_rate     ?? 0),
            'grade'            => (string)($s->grade            ?? '—'),
            'grade_label'      => (string)($s->grade_label      ?? '—'),
            'grade_color'      => (string)($s->grade_color      ?? 'success'),
            'stars'            => (int)($s->stars               ?? 0),
        ];
    }

    /** @return array<string, string> */
    private function platforms(): array
    {
        return ['instagram' => 'اینستاگرام', 'telegram' => 'تلگرام'];
    }

    /** @return array<string, mixed> */
    private function priceFields(): array
    {
        return [
            'instagram' => [
                'story_price_24h' => 'استوری ۲۴ ساعته',
                'post_price_24h'  => 'پست ۲۴ ساعته',
                'post_price_48h'  => 'پست ۴۸ ساعته',
                'post_price_72h'  => 'پست ۷۲ ساعته',
            ],
            'telegram' => [
                'sponsored_post_price' => 'پست اسپانسری',
                'pin_price'            => 'پین پیام',
                'forward_price'        => 'فوروارد پیام',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function extractPrices(array $d, string $platform): array
    {
        $out = [
            'story_price_24h'      => 0, 'post_price_24h'  => 0,
            'post_price_48h'       => 0, 'post_price_72h'  => 0,
            'sponsored_post_price' => 0, 'pin_price'       => 0,
            'forward_price'        => 0,
        ];
        $platformFields = $this->priceFields()[$platform] ?? [];
        if (!is_array($platformFields)) {
            return $out;
        }
        foreach ($platformFields as $k => $_) {
            $out[$k] = float_value($d[$k] ?? 0);
        }
        return $out;
    }

    /**
     * Master E2E Functional Browser Verification specifically for Section 9 Influencer Marketplace Bounded Domain Operations 🆕 (INF-01 to INF-15 🆕) Trapped sequence
     */
}
