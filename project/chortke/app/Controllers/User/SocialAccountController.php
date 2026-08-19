<?php
// app/Controllers/User/SocialAccountController.php

namespace App\Controllers\User;

use App\Services\SocialAccountService;
use App\Services\UploadService;
use App\Policies\RateLimitPolicy;
use App\Controllers\User\BaseUserController;
use App\Validators\Requests\StoreSocialAccountRequest;

class SocialAccountController extends BaseUserController
{
    private \App\Services\SocialAccountService $service;

    public function __construct(
        SocialAccountService $socialAccountService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->service = $socialAccountService;
    }

    /**
     * لیست حساب‌های کاربر
     */
    public function index(): void
    {
        $userId = (int) user_id();
        $accounts = $this->service->getByUser($userId);

        $platforms = [
            'instagram' => 'اینستاگرام',
            'youtube'   => 'یوتیوب',
            'telegram'  => 'تلگرام',
            'tiktok'    => 'تیک‌تاک',
            'twitter'   => 'توییتر (X)',
        ];

        view('user.social-accounts.index', [
            'accounts'  => $accounts,
            'platforms' => $platforms,
        ]);
    }

    /**
     * فرم ثبت حساب جدید
     */
    public function showCreate(): void
    {
        $platforms = [
            'instagram' => 'اینستاگرام',
            'youtube'   => 'یوتیوب',
            'telegram'  => 'تلگرام',
            'tiktok'    => 'تیک‌تاک',
            'twitter'   => 'توییتر (X)',
        ];

        view('user.social-accounts.create', [
            'platforms' => $platforms,
        ]);
    }

    /**
     * ثبت حساب جدید — POST
     */
    public function store(): void
    {
                
        $data = $this->request->body();
        $validator = new StoreSocialAccountRequest($data);
        $validator->validate();

        if ($validator->fails()) {
            $errors = $validator->errors();
            $firstKey = array_key_first($errors);
            $messages = $firstKey !== null && is_array($errors[$firstKey] ?? null) ? $errors[$firstKey] : [];
            $this->session->setFlash('error', is_string($messages[0] ?? null) ? $messages[0] : 'اطلاعات ورودی نامعتبر است.');
            redirect(url('/social-accounts/create'));
        }

        $result = $this->service->register((int) user_id(), $validator->validated());
        RateLimitPolicy::enforce('social_account_add', (int)user_id(), true);

        if ($result['success']) {
            $this->session->setFlash('success', $result['message']);
            $isMobile = ($this->session->get('social_source') === 'mobile') || (str_value($this->request->get('source') ?? $this->request->param('source') ?? '') === 'mobile');
            if ($isMobile) {
                $mobileScheme = str_value(config('app.mobile.scheme', 'chortke'));
                redirect("{$mobileScheme}://social/account-result?status=success&provider=" . urlencode(str_value($data['provider'] ?? 'unknown')));
            }
            redirect(url('/social-accounts'));
        }

        $this->session->setFlash('error', $result['message']);
        redirect(url('/social-accounts/create'));
    }

    /**
     * فرم ویرایش
     */
    public function showEdit(): void
    {
                $id = $this->request->int('id');

        $account = $this->service->find($id);
        if (!$account || $account->user_id !== user_id()) {
            $this->session->setFlash('error', 'حساب یافت نشد.');
            redirect(url('/social-accounts'));
        }

        if ($account->status === 'verified') {
            $this->session->setFlash('error', 'حساب تایید‌شده قابل ویرایش نیست.');
            redirect(url('/social-accounts'));
        }

        view('user.social-accounts.edit', [
            'account' => $account,
        ]);
    }

    /**
     * بروزرسانی — POST
     */
    public function update(): void
    {
        $id = $this->request->int('id');
        $data = $this->request->body();

        $validator = new StoreSocialAccountRequest($data);
        $validator->validate();

        if ($validator->fails()) {
            $errors = $validator->errors();
            $firstKey = array_key_first($errors);
            $messages = $firstKey !== null && is_array($errors[$firstKey] ?? null) ? $errors[$firstKey] : [];
            $this->session->setFlash('error', is_string($messages[0] ?? null) ? $messages[0] : 'اطلاعات ورودی نامعتبر است.');
            redirect(url('/social-accounts/' . $id . '/edit'));
        }

        $result = $this->service->updateByUser($id, (int) user_id(), $validator->validated());

        if ($result['success']) {
            $this->session->setFlash('success', $result['message']);
            redirect(url('/social-accounts'));
        }

        $this->session->setFlash('error', $result['message']);
        redirect(url('/social-accounts/' . $id . '/edit'));
    }

    /**
     * حذف — Ajax
     */
    public function delete(): void
    {
                        $id = $this->request->int('id');

        $result = $this->service->delete($id, (int) user_id());

        $this->response->json($result);
    }
}