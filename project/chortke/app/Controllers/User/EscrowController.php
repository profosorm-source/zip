<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Services\EscrowService;
use App\Services\User\UserService;
use App\Controllers\User\BaseUserController;
use App\Services\AuditTrail;
use App\Domain\Financial\Services\FinancialEscrowService;

class EscrowController extends BaseUserController
{
    // $userService inherited from parent
    // $logger inherited from BaseController

    private \App\Models\Escrow $escrowModel;
    private AuditTrail $auditTrail;
    private FinancialEscrowService $financialEscrow;

    public function __construct(
        UserService $userService,
        \App\Models\Escrow $escrowModel,
        AuditTrail $auditTrail,
        FinancialEscrowService $financialEscrow,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->userService   = $userService;
        $this->escrowModel   = $escrowModel;
        $this->auditTrail    = $auditTrail;
        $this->financialEscrow = $financialEscrow;
    }

    public function index(): void
    {
        $userId = (int)$this->userId();
        // BUGFIX-CTRL-RAW-SQL-2026-06: JOIN encapsulated in Escrow::getUserEscrowsWithParties().
        $escrows = $this->escrowModel->getUserEscrowsWithParties((int)$userId, 50);

        // SEC-2 fix (BUGFIX-FALLBACK-USER-2026-06)
        $userObj = $this->loadCurrentUserOrRedirect();
        $userId  = (int) $userObj->id;

        $this->view('user/wallet/escrows', [
            'title'   => 'صندوق امانات مالی (اسکرو)',
            'escrows' => $escrows,
            'user'    => $userObj
        ]);
    }

    public function create(): void
    {
        $userId = (int)$this->userId();
        // SEC-2 fix (BUGFIX-FALLBACK-USER-2026-06)
        $userObj = $this->loadCurrentUserOrRedirect();
        $userId  = (int) $userObj->id;

        $this->view('user/wallet/escrow-create', [
            'title' => 'ایجاد معامله امن (Escrow)',
            'user'  => $userObj
        ]);
    }

    public function store(): void
    {
        $this->validateCsrf();
        $buyerId = (int)$this->userId();
        $sellerIdentifier = trim($this->request->str('seller')) ?: trim($this->request->str('recipient'));
        $amountStr = trim($this->request->str('amount'));
        $orderTitle = trim($this->request->str('title', 'معامله امن اختصاصی'));

        if ($sellerIdentifier === '' || !is_numeric($amountStr) || bccomp($amountStr, '0', 4) <= 0) {
            $this->response->json(['success' => false, 'message' => 'اطلاعات فروشنده یا مبلغ معتبر نیست.']);
            return;
        }

        $seller = $this->userService->findByCredentials($sellerIdentifier);
        if (!$seller) {
            $this->response->json(['success' => false, 'message' => 'طرف معامله (فروشنده) یافت نشد.']);
            return;
        }

        $sellerId = (int)$seller->id;
        if ($sellerId === $buyerId) {
            $this->response->json(['success' => false, 'message' => 'امکان ایجاد معامله امن با خودتان وجود ندارد.']);
            return;
        }

        $orderId = random_int(100000, 999999);
        // Generic escrow must create the wallet hold and the escrow row in the
        // same transaction. Creating only the row would make a later payout
        // create money without reserving buyer funds.
        $res = $this->financialEscrow->holdCustomDealFunds(
            $orderId,
            $buyerId,
            $sellerId,
            $amountStr,
            'custom_deal_hold:' . $buyerId . ':' . $orderId
        );

        if (!empty($res['ok'])) {
            $this->auditTrail->record('escrow_created', $buyerId, [
                'escrow_id' => int_value($res['escrow_id'] ?? 0),
                'amount'    => $amountStr,
                'seller_id' => $sellerId,
            ], $buyerId);
            $this->response->json(['success' => true, 'message' => 'وجه در صندوق امانات قفل شد.', 'escrow_id' => $res['escrow_id'] ?? 0]);
        } else {
            $this->response->json(['success' => false, 'message' => $res['error'] ?? 'خطا در قفل‌گذاری وجه']);
        }
    }

    public function release(): void
    {
        $this->validateCsrf();
        $userId = (int)$this->userId();
        $escrowId = $this->request->int('escrow_id');

        $res = $this->financialEscrow->releaseCustomDealFunds(
            $escrowId,
            $userId,
            'custom_deal_release:' . $userId . ':' . $escrowId
        );
        if (!empty($res['ok'])) {
            $this->response->json(['success' => true, 'message' => 'وجه امانی با موفقیت آزاد و به فروشنده منتقل شد.']);
        } else {
            $this->response->json(['success' => false, 'message' => $res['error'] ?? 'خطا در آزادسازی']);
        }
    }
}
