<?php

namespace App\Controllers\User;

use App\Services\User\UserSettingsService;
use App\Services\Auth\AuthService;
use App\Validators\Requests\UpdateGeneralSettingsRequest;
use App\Services\User\UserService;
use App\Services\CaptchaService;

/**
 * SettingsController — مدیریت تنظیمات حساب کاربری
 */
class SettingsController extends BaseUserController
{
    private UserSettingsService $settingsService;

    public function __construct(
        UserSettingsService $settingsService,
        \Core\Session $session,
        \Core\Request $request,
        \Core\Response $response,
        \App\Services\Shared\PolicyService $policyService,
        \App\Contracts\LoggerInterface $logger,
        AuthService $authService,
        UserService $userService,
        CaptchaService $captchaService
    ) {
        parent::__construct($session, $request, $response, $policyService, $logger, $authService, $userService, $captchaService);
        $this->settingsService = $settingsService;
    }

    /**
     * ???? ??????? ?????
     */
    public function general(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();
            $settings = $this->settingsService->getAll($userId);

            $this->view('user/settings/general', [
                'title' => '??????? ?????',
                'settings' => $settings,
                'timezones' => timezone_identifiers_list(),
                'themes' => [
                    'light' => '????',
                    'dark' => '?????',
                    'auto' => '??????',
                ],
                'languages' => [
                    'fa' => '?????',
                    'en' => 'English',
                ],
                'date_formats' => [
                    'jalali' => '????? ?????',
                    'gregorian' => '????? ??????',
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.general.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '??? ?? ???????? ???????');
            $this->response->redirect(url('/dashboard'));
        }
    }

    /**
     * ????????? ??????? ?????
     */
    public function updateGeneral(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();

            $formRequest = new UpdateGeneralSettingsRequest($this->request->all());
            if (!$formRequest->validate()) {
                $errors = $formRequest->errors();
                $first = reset($errors);
            $firstError = is_array($first) ? reset($first) : $first;
                $this->session->setFlash('error', $firstError ?: 'تنظیمات نامعتبر است');
                $this->response->redirect(url('/settings/general'));
                return;
            }
            $validated = $formRequest->validated();

            $settings = [
                'language' => $validated['language'] ?? 'fa',
                'timezone' => $validated['timezone'] ?? 'Asia/Tehran',
                'theme' => $validated['theme'] ?? 'light',
                'date_format' => $validated['date_format'] ?? 'jalali',
                'items_per_page' => $this->request->int('items_per_page', 20),
            ];

            if ($this->settingsService->setMultiple($userId, $settings)) {
                $this->session->setFlash('success', '??????? ????? ??');
                $this->logger->info('settings.general.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', '??? ?? ????? ???????');
            }

            $this->response->redirect(url('/settings/general'));
        } catch (\Exception $e) {
            $this->logger->error('settings.general.update.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '???? ????? ????');
            $this->response->redirect(url('/settings/general'));
        }
    }

    /**
     * ???? ??????? ???? ?????
     */
    public function privacy(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();
            $settings = $this->settingsService->getAll($userId);

            $this->view('user/settings/privacy', [
                'title' => '??????? ???? ?????',
                'settings' => $settings,
                'visibility_options' => [
                    'public' => '?????',
                    'friends' => '??? ??????',
                    'private' => '?????',
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.privacy.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '??? ?? ???????? ???????');
            $this->response->redirect(url('/dashboard'));
        }
    }

    /**
     * ????????? ??????? ???? ?????
     */
    public function updatePrivacy(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();

            $settings = [
                'profile_visibility' => $this->request->post('profile_visibility') ?? 'public',
                'show_online_status' => (bool)$this->request->post('show_online_status'),
                'show_activity' => (bool)$this->request->post('show_activity'),
                'allow_messages' => (bool)$this->request->post('allow_messages'),
                'allow_friend_requests' => (bool)$this->request->post('allow_friend_requests'),
            ];

            if ($this->settingsService->setMultiple($userId, $settings)) {
                $this->session->setFlash('success', '??????? ????? ??');
                $this->logger->info('settings.privacy.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', '??? ?? ????? ???????');
            }

            $this->response->redirect(url('/settings/privacy'));
        } catch (\Exception $e) {
            $this->logger->error('settings.privacy.update.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '???? ????? ????');
            $this->response->redirect(url('/settings/privacy'));
        }
    }

    /**
     * ???? ??????? ??????
     */
    public function security(): void
    {
        $this->requireAuth();

        $userId = (int)$this->userId();
        $settings = [
            'login_alerts' => true,
            'suspicious_activity_alerts' => true,
            'session_timeout' => 30,
        ];
        $user = auth();

        try {
            $loaded = $this->settingsService->getAll((int)$userId);
            if (is_array($loaded)) {
                $settings = array_merge($settings, $loaded);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('settings.security.preferences_fallback', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $loadedUser = $this->userService->findById((int)$userId);
            if ($loadedUser) {
                $user = $loadedUser;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('settings.security.user_fallback', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->view('user/settings/security', [
            'title' => 'تنظیمات امنیتی',
            'settings' => $settings,
            'user' => $user,
        ]);
    }

    /**
     * ????????? ??????? ??????
     */
    public function updateSecurity(): void
    {
        $this->requireAuth();

        $userId = (int)$this->userId();
        $settings = [
            'login_alerts' => (bool)$this->request->post('login_alerts'),
            'suspicious_activity_alerts' => (bool)$this->request->post('suspicious_activity_alerts'),
            'session_timeout' => max(5, min(480, $this->request->int('session_timeout', 30))),
        ];

        $success = false;
        $message = 'تنظیمات امنیتی ذخیره نشد. لطفاً دوباره تلاش کنید.';

        try {
            $success = $this->settingsService->setMultiple($userId, $settings);
            if ($success) {
                $message = 'تنظیمات امنیتی با موفقیت ذخیره شد.';
                $this->session->setFlash('success', $message);
                $this->session->set('security_session_timeout_minutes', (string)$settings['session_timeout']);
                $this->logger->info('settings.security.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', $message);
            }
        } catch (\Throwable $e) {
            $message = 'خطا در ذخیره تنظیمات امنیتی. لطفاً چند لحظه بعد دوباره تلاش کنید.';
            $this->logger->error('settings.security.update.failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            $this->session->setFlash('error', $message);
        }

        if ($this->request->isAjax() || str_contains(str_value($this->request->header('Accept', '')), 'application/json')) {
            $this->response->json([
                'success' => $success,
                'message' => $message,
                'settings' => $settings,
            ], $success ? 200 : 422);
            return;
        }

        $this->response->redirect(url('/settings/security'));
    }

    /**
     * ???? ??????? ???????
     */
    public function notifications(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();
            $settings = $this->settingsService->getAll($userId);

            $this->view('user/settings/notifications', [
                'title' => '??????? ???????',
                'settings' => $settings,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.notifications.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '??? ?? ???????? ???????');
            $this->response->redirect(url('/dashboard'));
        }
    }

    /**
     * ????????? ??????? ???????
     */
    public function updateNotifications(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();

            $settings = [
                'email_notifications' => (bool)$this->request->post('email_notifications'),
                'push_notifications' => (bool)$this->request->post('push_notifications'),
                'sms_notifications' => (bool)$this->request->post('sms_notifications'),
                'marketing_emails' => (bool)$this->request->post('marketing_emails'),
            ];

            if ($this->settingsService->setMultiple($userId, $settings)) {
                $this->session->setFlash('success', '??????? ??????? ????? ??');
                $this->logger->info('settings.notifications.updated', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', '??? ?? ????? ???????');
            }

            $this->response->redirect(url('/settings/notifications'));
        } catch (\Exception $e) {
            $this->logger->error('settings.notifications.update.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '???? ????? ????');
            $this->response->redirect(url('/settings/notifications'));
        }
    }

    /**
     * ???? ???? ???? ??????
     */
    public function dataExport(): void
    {
        $this->requireAuth();

        try {
            $this->view('user/settings/data-export', [
                'title' => '???? ???? ??????? ??',
                'export_formats' => [
                    'json' => 'JSON',
                    'csv' => 'CSV',
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.data_export.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '??? ?? ???????? ????');
            $this->response->redirect(url('/dashboard'));
        }
    }

    /**
     * درخواست دریافت خروجی داده‌های کاربر
     */
    public function requestDataExport(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();
            $format = strtolower(str_value($this->request->post('format') ?? $this->request->get('format') ?? 'json'));
            if (!in_array($format, ['json', 'csv'], true)) {
                $format = 'json';
            }

            $exportService = app(\App\Services\DataExportService::class);
            $exportId = $exportService->requestExport($userId, $format);

            if ($exportId) {
                $content = $format === 'csv' ? $exportService->exportCSV($userId) : $exportService->exportJSON($userId);
                if ($content !== null) {
                    $exportService->saveExportFile($exportId, $format, $content);
                    $filename = "chortke_data_export_{$userId}." . $format;
                    header('Content-Type: ' . ($format === 'csv' ? 'text/csv; charset=utf-8' : 'application/json; charset=utf-8'));
                    header('Content-Disposition: attachment; filename="' . $filename . '"');
                    echo $content;
                    exit;
                }
                $this->session->setFlash('success', 'درخواست دریافت خروجی با موفقیت ثبت شد.');
            } else {
                $this->session->setFlash('error', 'خطا در ثبت درخواست خروجی داده‌ها.');
            }
            $this->response->redirect(url('/settings/data-export'));
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('settings.data_export_request.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', 'خطا در پردازش درخواست خروجی داده‌ها');
            $this->response->redirect(url('/settings/data-export'));
        }
    }

    /**
     * ??? ???? ??????
     */
    public function accountDeletion(): void
    {
        $this->requireAuth();

        try {
            $this->view('user/settings/account-deletion', [
                'title' => '??? ???? ??????',
            ]);
        } catch (\Exception $e) {
            $this->logger->error('settings.account_deletion.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '??? ?? ???????? ????');
            $this->response->redirect(url('/dashboard'));
        }
    }

    /**
     * ??????? ??? ???? ??????
     */
    public function requestAccountDeletion(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();
            $password = str_value($this->request->post('password') ?? '');

            if (empty($password)) {
                $this->session->setFlash('error', '??????? ?????? ???');
                $this->response->redirect(url('/settings/account-deletion'));
                return;
            }

            $result = $this->settingsService->requestAccountDeletion($userId, $password);
            if ($result['ok']) {
                $this->session->setFlash('success', '??????? ??? ??? ??. ???? ??? ?? 7 ??? ??? ????? ??');
                $this->logger->warning('settings.account_deletion_requested', ['user_id' => $userId]);
                $this->response->redirect(url('/dashboard'));
            } else {
                $this->session->setFlash('error', $result['message'] ?? '??? ?? ??????? ??? ????');
                $this->response->redirect(url('/settings/account-deletion'));
            }
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('settings.account_deletion_request.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '???? ????? ????');
            $this->response->redirect(url('/settings/account-deletion'));
        }
    }

    /**
     * ??? ??????? ??? ???? ??????
     */
    public function cancelAccountDeletion(): void
    {
        $this->requireAuth();

        try {
            $userId = (int)$this->userId();

            if ($this->settingsService->cancelAccountDeletion($userId)) {
                $this->session->setFlash('success', '??????? ??? ???? ??? ??');
                $this->logger->info('settings.account_deletion_cancelled', ['user_id' => $userId]);
            } else {
                $this->session->setFlash('error', '??? ?? ??? ???????');
            }

            $this->response->redirect(url('/settings/account-deletion'));
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('settings.account_deletion_cancel.failed', ['error' => $e->getMessage()]);
            $this->session->setFlash('error', '???? ????? ????');
            $this->response->redirect(url('/settings/account-deletion'));
        }
    }
}
