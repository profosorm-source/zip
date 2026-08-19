<?php
// app/Controllers/Admin/SocialAccountController.php

namespace App\Controllers\Admin;

use App\Services\SocialAccountService;
use App\Controllers\Admin\BaseAdminController;

class SocialAccountController extends BaseAdminController
{
    private SocialAccountService $socialAccountService;

    public function __construct(SocialAccountService $socialAccountService, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->socialAccountService = $socialAccountService;
    }

    /**
     * لیست تمام حساب‌ها
     */
    public function index(): string
    {
        $page = int_value($this->request->query('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $filters = [];
        if (!empty($this->request->query('status'))) $filters['status'] = $this->request->query('status');
        if (!empty($this->request->query('platform'))) $filters['platform'] = $this->request->query('platform');
        if (!empty($this->request->query('search'))) $filters['search'] = $this->request->query('search');

        // Use service to get accounts
        $accounts = $this->socialAccountService->getAllForAdmin($filters, $limit, $offset);
        $total = $this->socialAccountService->countForAdmin($filters);
        $totalPages = \ceil($total / $limit);

        return view('admin.social-accounts.index', [
            'accounts'    => $accounts,
            'filters'     => $filters,
            'page'        => $page,
            'totalPages'  => $totalPages,
            'total'       => $total,
        ]);
    }

    /**
     * جزئیات حساب
     */
    public function show(): string
    {
        $id = $this->request->int('id');

        // Use service to find account
        $account = $this->socialAccountService->findForAdmin($id);
        if (!$account) {
            $this->session->setFlash('error', 'حساب یافت نشد.');
            return redirect(url('/admin/social-accounts'));
        }

        return view('admin.social-accounts.show', [
            'account' => $account,
        ]);
    }

    /**
     * تایید — Ajax
     */
    public function verify(): void
    {
        $id = $this->request->int('id');
        $adminId = $this->requireAdminId();
        $result = $this->socialAccountService->verify($id, $adminId);

        $this->logger->activity('social_account_verify', 'تایید حساب اجتماعی #' . $id, $adminId, ['entity_type' => 'social_account', 'entity_id' => $id]);

        $this->response->json($result);
    }

    /**
     * رد — Ajax
     */
    public function reject(): void
    {
        $id = $this->request->int('id');

        $body = $this->request->body();
        $reason = str_value($body['reason'] ?? '');

        if (empty($reason)) {
            $this->response->json(['success' => false, 'message' => 'لطفاً دلیل رد را وارد کنید.']);
            return;
        }

        $adminId = $this->requireAdminId();
        $result = $this->socialAccountService->reject($id, $adminId, $reason);

        $this->logger->activity('social_account_reject', 'رد حساب اجتماعی #' . $id . ': ' . $reason, $adminId, ['entity_type' => 'social_account', 'entity_id' => $id]);

        $this->response->json($result);
    }
}