<?php

namespace App\Controllers;

use App\Services\CaptchaService;
use App\Controllers\BaseController;

class TestCaptchaController extends BaseController
{
    
    public function __construct(
        ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
    }
    
    /**
     * صفحه تست
     */
    public function index(): mixed
    {
        return view('test-captcha');
    }
    
    /**
     * بررسی CAPTCHA
     */
    public function verify(): void
{
    if (verify_captcha()) {
        $this->session->setFlash('success', '✅ CAPTCHA به درستی حل شد!');
    } else {
        $this->session->setFlash('error', '❌ CAPTCHA اشتباه است!');
    }

    redirect('test-captcha');
}
}