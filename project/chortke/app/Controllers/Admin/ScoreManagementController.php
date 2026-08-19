<?php

namespace App\Controllers\Admin;

use App\Services\ScoreService;

class ScoreManagementController extends BaseAdminController
{
    private ScoreService $scoreService;

    public function __construct(
        ScoreService $scoreService,
        ?\Core\Session $session = null,
        ?\Core\Request $request = null,
        ?\Core\Response $response = null,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct($session, $request, $response, null, $logger);
        $this->scoreService = $scoreService;
    }

    public function showUserScores(int $id): void
    {
        $this->requireAdmin();
        $userId = (int)$id;

        $user = $this->scoreService->getUserForScoreManagement($userId);
        if (!$user) {
            $this->response->setStatusCode(404);
            $this->response->setContent('کاربر یافت نشد');
            return;
        }

        $fraudRaw = (float)($user->fraud_score ?? 0);
        $fraudEffective = $this->scoreService->getEffectiveScore($userId, 'fraud', $fraudRaw);

        $taskRaw = $this->scoreService->getTaskRawRisk($userId);
        $taskEffective = $this->scoreService->getEffectiveScore($userId, 'task', $taskRaw);

        $fraudAdjustments = $this->scoreService->getActiveAdjustments($userId, 'fraud');
        $taskAdjustments = $this->scoreService->getActiveAdjustments($userId, 'task');

        $recentEvents = $this->scoreService->getRecentScoreEvents($userId, 50);

        $this->view('admin/fraud/user-scores', [
            'user' => $user,
            'fraud_raw' => $fraudRaw,
            'fraud_effective' => $fraudEffective,
            'task_raw' => $taskRaw,
            'task_effective' => $taskEffective,
            'fraud_adjustments' => $fraudAdjustments,
            'task_adjustments' => $taskAdjustments,
            'events' => $recentEvents,
        ]);
    }

    public function adjustScore(int $id): void
    {
        $this->requireAdmin();

        if (strtoupper($this->request->method()) !== 'POST') {
            $this->response->redirect('/admin/users/' . (int)$id . '/scores');
        }

        $userId = (int)$id;
        $domain = trim($this->request->str('domain', 'fraud'));
        $operation = strtolower(trim($this->request->str('operation', 'add')));
        $value = $this->request->float('value');
        $reason = trim($this->request->str('reason'));
        $expiresAt = trim($this->request->str('expires_at'));

        if (!in_array($domain, ['fraud', 'task'], true)) {
            $this->flash('error', 'دامنه امتیاز نامعتبر است.');
            $this->response->redirect('/admin/users/' . $userId . '/scores');
        }

        if (!in_array($operation, ['set', 'add', 'subtract'], true)) {
            $this->flash('error', 'عملیات نامعتبر است.');
            $this->response->redirect('/admin/users/' . $userId . '/scores');
        }

        if ($reason === '') {
            $this->flash('error', 'ثبت دلیل برای اصلاح امتیاز الزامی است.');
            $this->response->redirect('/admin/users/' . $userId . '/scores');
        }

        $adminId = $this->currentAdminId();

        $result = $this->scoreService->createAdjustment(
            $userId,
            $domain,
            $operation,
            $value,
            $reason,
            ($expiresAt !== '' ? $expiresAt : null),
            $adminId
        );

        if ($result['success']) {
            $this->flash('success', str_value($result['message'] ?? 'اصلاح امتیاز ثبت شد.'));
        } else {
            $this->flash('error', str_value($result['message'] ?? 'ثبت اصلاح امتیاز ناموفق بود.'));
        }

        $this->response->redirect('/admin/users/' . $userId . '/scores');
    }

    public function revokeAdjustment(int $id): void
    {
        $this->requireAdmin();

        if (strtoupper($this->request->method()) !== 'POST') {
            $this->response->redirect('/admin/dashboard');
        }

        $adjustmentId = (int)$id;
        $reason = trim($this->request->str('reason', 'revoke_by_admin'));
        $adminId = $this->currentAdminId();

        $success = $this->scoreService->revokeScoreAdjustment($adjustmentId, $adminId, $reason);

        if ($success) {
            $this->flash('success', 'اصلاح امتیاز غیرفعال شد.');
            $this->response->redirect('/admin/users/' . $adjustmentId . '/scores');
        } else {
            $this->flash('error', 'رکورد اصلاح امتیاز یافت نشد.');
            $this->response->redirect('/admin/dashboard');
        }
    }

    public function history(int $id): void
    {
        $this->requireAdmin();
        $userId = (int)$id;

        $user = $this->scoreService->getUserForScoreManagement($userId);
        if (!$user) {
            $this->response->setStatusCode(404);
            $this->response->setContent('کاربر یافت نشد');
            return;
        }

        $events = $this->scoreService->getRecentScoreEvents($userId, 200);

        // اگر ویوی تاریخچه جدا نداری، همین view اصلی را با events بیشتر باز کن
        $this->view('admin/fraud/user-scores', [
            'user' => $user,
            'fraud_raw' => (float)($user->fraud_score ?? 0),
            'fraud_effective' => $this->scoreService->getEffectiveScore($userId, 'fraud', (float)($user->fraud_score ?? 0)),
            'task_raw' => $this->scoreService->getTaskRawRisk($userId),
            'task_effective' => $this->scoreService->getEffectiveScore($userId, 'task', $this->scoreService->getTaskRawRisk($userId)),
            'fraud_adjustments' => $this->scoreService->getActiveAdjustments($userId, 'fraud'),
            'task_adjustments' => $this->scoreService->getActiveAdjustments($userId, 'task'),
            'events' => $events,
        ]);
    }


    private function currentAdminId(): ?int
    {
        $id = $this->session->get('user_id') ?? $this->session->get('admin_id');
        return $id ? int_value($id) : null;
    }

    private function flash(string $type, string $message): void
    {
        $this->session->set('flash.' . $type, $message);
    }
}