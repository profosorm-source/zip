<?php

namespace App\Controllers\Admin;

use App\Controllers\Admin\BaseAdminController;
use App\Services\AntiFraud\RiskPolicyService;

class RiskPolicyController extends BaseAdminController
{
    private RiskPolicyService $riskPolicyService;

    public function __construct(RiskPolicyService $policyService, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->riskPolicyService = $policyService;
    }

    public function index(): void
    {
        $this->view('admin.risk-policies.index', [
            'policies' => $this->riskPolicyService->getPoliciesWithDefaults(),
        ]);
    }

    public function update(): void
    {
        if (!$this->request->isPost()) {
            redirect('/admin/risk-policies');
        }

        $domain = trim($this->request->str('domain'));
        $keyName = trim($this->request->str('key_name'));
        $value = $this->request->post('value', '');
        $valueType = trim($this->request->str('value_type', 'string'));
        $description = trim($this->request->str('description'));

        if ($domain === '' || $keyName === '') {
            $this->session->setFlash('error', 'دامنه و کلید الزامی است.');
            redirect('/admin/risk-policies');
        }

        $adminId = $this->session->get('user_id');
        $ok = $this->riskPolicyService->set(
            $domain,
            $keyName,
            $value,
            $valueType,
            $adminId ? int_value($adminId) : null,
            $description
        );

        if ($ok) {
            $this->session->setFlash('success', 'تنظیمات با موفقیت ذخیره شد.');
            redirect('/admin/risk-policies');
        }

        $this->session->setFlash('error', 'خطا در ذخیره تنظیمات.');
        redirect('/admin/risk-policies');
    }
}