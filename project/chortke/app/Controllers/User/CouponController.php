<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Services\Shared\CouponService;
use App\Models\CouponRedemption;
use App\Controllers\User\BaseUserController;
use Core\Database;

class CouponController extends BaseUserController
{
    private CouponService $couponService;
    private CouponRedemption $redemptionModel;

    public function __construct(
        CouponRedemption $redemptionModel,
        CouponService $couponService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->couponService = $couponService;
        $this->redemptionModel = $redemptionModel;
    }

    /**
     * اعتبارسنجی کوپن (AJAX)
     * POST /user/coupons/validate
     */
    public function validate(): void
    {
        $decoded = json_decode((file_get_contents('php://input') ?: ''), true);
        $data = is_array($decoded) ? $decoded : [];

        $code = trim(str_value($data['code'] ?? ''));
        $amount = str_value($data['amount'] ?? 0);
        $currency = str_value($data['currency'] ?? 'irt');
        $applicableTo = str_value($data['applicable_to'] ?? 'all');
        $userId = (int) user_id();

        if (empty($code) || !is_numeric($amount) || bccomp($amount, '0', 8) <= 0) {
            $this->response->json([
                'success' => false,
                'message' => 'اطلاعات ارسالی ناقص است'
            ]);
            return;
        }

        $result = $this->couponService->validateAndCalculate(
            $code,
            $amount,
            $currency,
            $userId,
            $applicableTo
        );

        if ($result['valid']) {
            $this->response->json([
                'success' => true,
                'data' => [
                    'coupon_id' => $result['coupon_id'],
                    'coupon_code' => $result['coupon_code'],
                    'original_amount' => $result['original_amount'],
                    'discount_amount' => $result['discount_amount'],
                    'final_amount' => $result['final_amount'],
                    'validation_token' => $result['validation_token']
                ],
                'message' => sprintf('کد تخفیف با موفقیت اعمال شد. تخفیف: %s', number_format(float_value($result['discount_amount'] ?? 0)))
            ]);
        } else {
            $this->response->json([
                'success' => false,
                'message' => $result['error']
            ]);
        }
    }

    /**
     * تاریخچه استفاده از کوپن‌ها
     * GET /user/coupons/history
     */
    public function history(): void
    {
        $userId = (int) user_id();
        $history = $this->redemptionModel->getUserHistory($userId);

        // CSP Rendering Bug Fix: استفاده از $this->view جهت انتساب صحیح خروجی به شیء Response
        $this->view('user/coupons/history', [
            'history' => $history,
            'user' => auth()
        ]);
    }

    /**
     * Master E2E Functional Browser Verification for Section 8.3 Coupon Bounded Domain Operations (CP-01 to CP-06)
     */
}
