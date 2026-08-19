<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Contracts\WalletServiceInterface;
use App\Services\User\UserService;
use App\Controllers\User\BaseUserController;
use App\Validators\Requests\CreateTransferRequest;

class TransferController extends BaseUserController
{
    private WalletServiceInterface $walletService;
    // $userService inherited from parent
    // $logger inherited from BaseController

    public function __construct(
        WalletServiceInterface $walletService,
        UserService $userService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->walletService = $walletService;
        $this->userService   = $userService;
    }

    public function create(): void
    {
        $userId = (int)$this->userId();
        // SEC-2 fix (BUGFIX-FALLBACK-USER-2026-06)
        $userObj = $this->loadCurrentUserOrRedirect();
        $userId  = (int) $userObj->id;

        $this->view('user/wallet/transfer', [
            'title' => 'انتقال اعتبار بین کاربران',
            'user'  => $userObj
        ]);
    }

    public function store(): void
    {
        $senderId = (int)$this->userId();

        $request = new CreateTransferRequest($this->request->all());
        if (!$request->validate()) {
            $errors = $request->errors();
            $firstValue = reset($errors);
            $firstError = is_array($firstValue) ? reset($firstValue) : $firstValue;
            $this->response->json(['success' => false, 'message' => $firstError ?: 'اطلاعات ورودی نامعتبر است', 'errors' => $errors]);
            return;
        }
        $validated = $request->validated();
        $recipientIdentifier = str_value($validated['recipient'] ?? '');
        $amountStr = str_value($validated['amount'] ?? '');

        $recipient = $this->userService->findByCredentials($recipientIdentifier);
        if (!$recipient) {
            $this->response->json(['success' => false, 'message' => 'کاربر گیرنده یافت نشد.']);
            return;
        }

        $recipientId = (int)$recipient->id;
        if ($recipientId === $senderId) {
            $this->response->json(['success' => false, 'message' => 'امکان انتقال اعتبار به خودتان وجود ندارد.']);
            return;
        }

        try {
            $result = $this->walletService->transfer(
                $senderId,
                $recipientId,
                $amountStr,
                'irt',
                'انتقال اعتبار P2P'
            );

            if ($result === null || (is_object($result) && empty((array)$result))) {
                $this->response->json(['success' => false, 'message' => 'خطا در انجام انتقال']);
                return;
            }

            $this->response->json(['success' => true, 'message' => 'انتقال با موفقیت انجام شد', 'data' => (array)$result]);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\InvalidArgumentException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Core\Exceptions\InsufficientBalanceException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Core\Exceptions\InvalidStateException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            $this->response->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            if (isset($this->logger)) {
                $this->logger->error('transfer.failed', ['user_id' => $senderId, 'error' => $e->getMessage()]);
            }
            $this->response->json(['success' => false, 'message' => 'خطای سیستمی در انتقال'], 500);
        }
    }
}
