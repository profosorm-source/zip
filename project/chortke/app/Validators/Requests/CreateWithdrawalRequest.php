<?php

declare(strict_types=1);

namespace App\Validators\Requests;

use App\Validators\BaseFormRequest;
use App\Services\Settings\AppSettings;
use App\Exceptions\BusinessException;

/**
 * فرم درخواست برداشت وجه
 *
 * لایه Input Validation + اعتبارسنجی اولیه بیزینسی
 * استفاده در WithdrawalController::store()
 */
class CreateWithdrawalRequest extends BaseFormRequest
{
    protected ?AppSettings $appSettings = null;

    public function setAppSettings(AppSettings $settings): void
    {
        $this->appSettings = $settings;
    }
    public function rules(): array
    {
        $rules = [
            'amount'           => 'required|numeric|min:1000',
            // Ensure amount is positive and valid
            'currency'         => 'required|in:IRT,USDT',
            'idempotency_key'  => 'required|string|min:10|max:128',
            'user_description' => 'nullable|string|max:500',
        ];

        $currency = strtoupper(str_value($this->data['currency'] ?? 'IRT'));

        if ($currency === 'IRT') {
            $rules['bank_card_id'] = 'required|integer|min:1';
        } else {
            $rules['crypto_wallet']   = 'required|string|min:10|max:120';
            $rules['crypto_network']  = 'required|in:BNB20,TRC20,TON,SOL';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'amount.required'           => 'مبلغ برداشت الزامی است',
            'amount.numeric'            => 'مبلغ باید عدد باشد',
            'amount.min'                => 'مبلغ وارد شده کمتر از حداقل مجاز است',
            'currency.required'         => 'انتخاب ارز الزامی است',
            'currency.in'               => 'ارز انتخاب شده معتبر نیست',
            'bank_card_id.required'     => 'انتخاب کارت بانکی الزامی است',
            'crypto_wallet.required'    => 'آدرس والت کریپتو الزامی است',
            'crypto_network.required'   => 'انتخاب شبکه الزامی است',
            'idempotency_key.required'  => 'کلید یکتای درخواست الزامی است',
            'user_description.max'      => 'توضیحات نمی‌تواند بیش از ۵۰۰ کاراکتر باشد',
        ];
    }

    public function validate(): bool
    {
        if (!parent::validate()) {
            return false;
        }

        $validated = $this->validated();
        $currency = strtoupper(str_value($validated['currency'] ?? 'IRT'));
        $amountStr = str_value($validated['amount'] ?? '0');

        if (bccomp($amountStr, '0', 8) <= 0) {
            $this->errors['amount'][] = 'مبلغ برداشت باید بیشتر از صفر باشد';
            return false;
        }

        if ($this->appSettings !== null) {
            $minKey = $currency === 'IRT' ? 'min_withdrawal_irt' : 'min_withdrawal_usdt';
            $defaultMin = $currency === 'IRT' ? '50000' : '10';
            $minAmountStr = str_value($this->appSettings->get($minKey, $defaultMin));

            if (bccomp($amountStr, $minAmountStr, 8) < 0) {
                $this->errors['amount'][] = "حداقل مبلغ برداشت برای {$currency} برابر {$minAmountStr} است";
                return false;
            }

            // چک اعشاری برای تومان با بررسی امن رشته‌ای
            if ($currency === 'IRT' && str_contains($amountStr, '.') && preg_match('/\.[0-9]*[1-9]/', $amountStr)) {
                $this->errors['amount'][] = 'مبلغ تومان نمی‌تواند اعشاری باشد';
                return false;
            }
        } else {
            if (bccomp($amountStr, '10000', 8) < 0) {
                $this->errors['amount'][] = 'حداقل مبلغ برداشت ۱۰۰۰۰ تومان است';
                return false;
            }
        }

        return true;
    }
}
