<?php

namespace App\Controllers;

use App\Services\BannerService;
use App\Controllers\BaseController;

class BannerController extends BaseController
{
    private \App\Services\BannerService $bannerService;
    public function __construct(
        \App\Services\BannerService $bannerService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->bannerService = $bannerService;
    }

    /**
     * ثبت کلیک بنر (برای همه کاربران - حتی مهمان)
     */
    public function click(): mixed
    {
        $id = $this->request->int('id');

        $service = $this->bannerService;
        $result = $service->trackClick($id);

        if ($result['success'] && !empty($result['redirect'])) {
            $url = str_value($result['redirect'] ?? '');
            
            // Extra layer of validation inside Controller
            $parsed = parse_url($url);
            $host = strtolower($parsed['host'] ?? '');
            
            $allowedDomains = ['chortke.com', 'trusted-partner.com', 'example.com'];
            $currentHost = strtolower((string)parse_url($this->urlGenerator->origin(), PHP_URL_HOST));
            if ($currentHost !== '') {
                $allowedDomains[] = $currentHost;
            }
            
            $isAllowedHost = false;
            foreach ($allowedDomains as $allowed) {
                if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                    $isAllowedHost = true;
                    break;
                }
            }
            
            if ($isAllowedHost && filter_var($url, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $url)) {
                redirect($url);
            }
        }

        redirect(url('/')); 
    }
}