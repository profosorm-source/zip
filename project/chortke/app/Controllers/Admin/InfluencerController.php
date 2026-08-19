<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Models\Dispute;
use App\Models\InfluencerModel;
use App\Models\StoryOrder;
use App\Services\AuditTrail;
use App\Services\InfluencerService;
use App\Services\Search\SearchOrchestrator;
use App\Services\Shared\DisputeService;
use App\Services\VerificationService;

class InfluencerController extends BaseAdminController
{
    private InfluencerModel $profileModel;
    private StoryOrder $orderModel;
    private Dispute $disputeModel;
    private DisputeService $disputeService;
    private VerificationService $verificationService;
    private AuditTrail $auditTrail;

    public function __construct(
        InfluencerModel $profileModel,
        StoryOrder $orderModel,
        Dispute $disputeModel,
        DisputeService $disputeService,
        VerificationService $verificationService,
        AuditTrail $auditTrail,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->profileModel = $profileModel;
        $this->orderModel = $orderModel;
        $this->disputeModel = $disputeModel;
        $this->disputeService = $disputeService;
        $this->verificationService = $verificationService;
        $this->auditTrail = $auditTrail;
    }

    public function orders(): void
    {
        $search = trim($this->request->str('search'));
        $filters = [
            'status' => $this->request->get('status'),
            'order_type' => $this->request->get('order_type'),
            'search' => $search,
        ];
        $page = max(1, $this->request->int('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;
        $orders = $this->orderModel->adminList($filters, $limit, $offset);
        $total = $this->orderModel->adminCount($filters);
        $stats = $this->orderModel->globalStats();

        view('admin.influencer.orders', [
            'orders' => $orders,
            'total' => $total,
            'page' => $page,
            'pages' => (int)ceil($total / $limit),
            'filters' => $filters,
            'search' => $search,
            'stats' => $stats,
            'statusLabels' => $this->orderModel->statusLabels(),
            'statusClasses' => $this->orderModel->statusClasses(),
        ]);
    }

    public function profiles(): void
    {
        $search = trim($this->request->str('search'));
        $filters = [
            'status' => $this->request->get('status'),
            'search' => $search,
        ];
        $page = max(1, $this->request->int('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;
        $profiles = $this->profileModel->adminListProfiles($filters, $limit, $offset);
        $total = $this->profileModel->adminCountProfiles($filters);

        view('admin.influencer.profiles', [
            'profiles' => $profiles,
            'total' => $total,
            'page' => $page,
            'pages' => (int)ceil($total / $limit),
            'filters' => $filters,
            'search' => $search,
            'statusLabels' => $this->profileModel->profileStatusLabels(),
        ]);
    }

    public function approveProfile(): void
    {
        $decodedBody = json_decode((file_get_contents('php://input') ?: ''), true);
        $body = is_array($decodedBody) ? $decodedBody : [];
        $profileId = int_value($body['profile_id'] ?? 0);
        $decision = str_value($body['decision'] ?? '');
        $reason = trim(str_value($body['reason'] ?? ''));

        if ($profileId <= 0 || !in_array($decision, ['approve', 'reject', 'suspend'], true)) {
            $this->response->json(['success' => false, 'message' => 'پارامترهای نامعتبر.'], 422);
            return;
        }
        if (in_array($decision, ['reject', 'suspend'], true) && mb_strlen((string)$reason) < 5) {
            $this->response->json(['success' => false, 'message' => 'دلیل باید حداقل ۵ کاراکتر باشد.'], 422);
            return;
        }

        $profile = $this->profileModel->find($profileId);
        if (!$profile) {
            $this->response->json(['success' => false, 'message' => 'پروفایل یافت نشد.'], 404);
            return;
        }

        $adminId = (int)$this->userId();
        $verification = ($profile->status === 'pending_admin_review')
            ? $this->verificationService->getPendingVerificationByProfile($profileId)
            : null;

        if ($decision === 'approve') {
            if ($verification) {
                $result = $this->verificationService->approveVerification((int)$verification->id, $adminId);
                $this->response->json(['success' => !empty($result['ok']), 'message' => $result['message'] ?? ($result['error'] ?? '')], !empty($result['ok']) ? 200 : 422);
                return;
            }
            $this->profileModel->update($profileId, ['status' => 'verified', 'verified_by' => $adminId, 'verified_at' => date('Y-m-d H:i:s'), 'is_active' => 1]);
            $this->auditTrail->record('influencer.profile.approved', (int)$profile->user_id, ['channel' => 'influencer', 'profile_id' => $profileId, 'username' => $profile->username], $adminId);
            $this->response->json(['success' => true, 'message' => 'پیج تأیید شد.']);
            return;
        }

        if ($decision === 'reject') {
            if ($verification) {
                $result = $this->verificationService->rejectVerification((int)$verification->id, $adminId, e($reason, ENT_QUOTES, 'UTF-8'));
                $this->response->json(['success' => !empty($result['ok']), 'message' => $result['message'] ?? ($result['error'] ?? '')], !empty($result['ok']) ? 200 : 422);
                return;
            }
            $this->profileModel->update($profileId, ['status' => 'rejected', 'rejection_reason' => e($reason, ENT_QUOTES, 'UTF-8')]);
            $this->auditTrail->record('influencer.profile.rejected', (int)$profile->user_id, ['channel' => 'influencer', 'profile_id' => $profileId, 'username' => $profile->username, 'reason' => $reason], $adminId);
            $this->response->json(['success' => true, 'message' => 'پیج رد شد.']);
            return;
        }

        $this->profileModel->update($profileId, ['status' => 'suspended', 'is_active' => 0, 'suspended_at' => date('Y-m-d H:i:s'), 'suspended_reason' => e($reason, ENT_QUOTES, 'UTF-8')]);
        $this->auditTrail->record('influencer.profile.suspended', (int)$profile->user_id, ['channel' => 'influencer', 'profile_id' => $profileId, 'username' => $profile->username, 'reason' => $reason], $adminId);
        $this->response->json(['success' => true, 'message' => 'پیج تعلیق شد.']);
    }

    public function disputes(): void
    {
        $filters = [
            'status' => $this->request->get('status'),
            'search' => $this->request->get('search'),
            'ref_type' => ['influencer_order', 'story_order', 'order', 'influencer'],
        ];
        $page = max(1, $this->request->int('page', 1));
        $limit = min(100, max(1, $this->request->int('limit', 30)));
        $offset = ($page - 1) * $limit;

        $disputes = $this->disputeModel->adminList($filters, $limit, $offset);
        $total = $this->disputeModel->adminCount($filters);

        view('admin.influencer.disputes', [
            'disputes' => $disputes,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $limit)),
            'filters' => $filters,
            'statusLabels' => [
                'open' => 'باز',
                'open_peer' => 'گفت‌وگوی طرفین',
                'escalated' => 'ارجاع به مدیر',
                'resolved_peer' => 'حل دوستانه',
                'resolved_admin' => 'حل توسط مدیر',
                'closed' => 'بسته',
            ],
        ]);
    }

    public function disputeDetail(): void
    {
        $disputeId = (int)$this->request->param('id');
        $dispute = $this->disputeModel->find($disputeId);
        if (!$dispute) {
            $this->session->setFlash('error', 'اختلاف یافت نشد.');
            redirect(url('/admin/influencer/disputes'));
        }
        $messages = $this->disputeModel->getMessages($disputeId);
        $order = $this->orderModel->find((int)($dispute->ref_id ?? $dispute->order_id ?? 0));

        view('admin.influencer.dispute-detail', ['dispute' => $dispute, 'messages' => $messages, 'order' => $order]);
    }

    public function resolveDispute(): void
    {
        $decodedBody = json_decode((file_get_contents('php://input') ?: ''), true);
        $body = is_array($decodedBody) ? $decodedBody : [];
        $disputeId = int_value($body['dispute_id'] ?? $this->request->param('id') ?? 0);
        $verdict = str_value($body['verdict'] ?? '');
        $note = trim(str_value($body['note'] ?? ''));
        $refundPercent = is_scalar($body['refund_percent'] ?? null) ? str_value($body['refund_percent']) : '0';

        if (!in_array($verdict, ['favor_influencer', 'favor_customer', 'partial'], true)) {
            $this->response->json(['success' => false, 'message' => 'رأی نامعتبر است.'], 422);
            return;
        }
        if (mb_strlen((string)$note) < 10) {
            $this->response->json(['success' => false, 'message' => 'توضیحات رأی باید حداقل ۱۰ کاراکتر باشد.'], 422);
            return;
        }
        if (!is_numeric($refundPercent) || bccomp($refundPercent, '0', 8) < 0 || bccomp($refundPercent, '100', 8) > 0) {
            $this->response->json(['success' => false, 'message' => 'درصد بازگشت باید بین ۰ تا ۱۰۰ باشد.'], 422);
            return;
        }

        $result = $this->disputeService->adminResolve($disputeId, (int)$this->userId(), $verdict, e($note, ENT_QUOTES, 'UTF-8'), $refundPercent);
        $this->response->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function verificationRequests(): void
    {
        $page = max(1, $this->request->int('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;
        $requests = $this->verificationService->getVerificationRequests($limit, $offset);
        $total = $this->verificationService->countVerificationRequests();
        view('admin.influencer.verifications', ['requests' => $requests, 'page' => $page, 'pages' => (int)ceil($total / $limit), 'total' => $total]);
    }

    public function approveVerification(): void
    {
        $decodedBody = json_decode((file_get_contents('php://input') ?: ''), true);
        $body = is_array($decodedBody) ? $decodedBody : [];
        $verificationId = int_value($body['verification_id'] ?? 0);
        if ($verificationId <= 0) {
            $this->response->json(['success' => false, 'message' => 'شناسه تأیید نامعتبر است.'], 422);
            return;
        }
        $result = $this->verificationService->approveVerification($verificationId, (int)$this->userId());
        $this->response->json(['success' => !empty($result['ok']), 'message' => $result['message'] ?? ($result['error'] ?? '')], !empty($result['ok']) ? 200 : 422);
    }

    public function rejectVerification(): void
    {
        $decodedBody = json_decode((file_get_contents('php://input') ?: ''), true);
        $body = is_array($decodedBody) ? $decodedBody : [];
        $verificationId = int_value($body['verification_id'] ?? 0);
        $reason = trim(str_value($body['reason'] ?? ''));
        if ($verificationId <= 0 || mb_strlen($reason) < 5) {
            $this->response->json(['success' => false, 'message' => 'شناسه یا دلیل نامعتبر است.'], 422);
            return;
        }
        $result = $this->verificationService->rejectVerification($verificationId, (int)$this->userId(), e($reason, ENT_QUOTES, 'UTF-8'));
        $this->response->json(['success' => !empty($result['ok']), 'message' => $result['message'] ?? ($result['error'] ?? '')], !empty($result['ok']) ? 200 : 422);
    }
}
