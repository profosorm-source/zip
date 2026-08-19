<?php

namespace App\Controllers\Admin;

use App\Services\BannerService;
use App\Controllers\Admin\BaseAdminController;
use App\Services\UploadService;
use App\Services\Search\SearchOrchestrator;
use App\Services\Ads\AdsBudgetSettlementService;

class BannerController extends BaseAdminController
{
    private BannerService $bannerService;
    private UploadService $uploadService;
    private \App\Models\Ads $banner;
    private \App\Models\BannerPlacement $placement;
    private AdsBudgetSettlementService $adsBudgetSettlement;

    public function __construct(
        BannerService $bannerService,
        UploadService $uploadService,
        \App\Models\Ads $banner,
        \App\Models\BannerPlacement $placement,
        AdsBudgetSettlementService $adsBudgetSettlement,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        parent::__construct(null, null, null, null, $logger);
        $this->bannerService = $bannerService;
        $this->uploadService = $uploadService;
        $this->banner = $banner;
        $this->placement = $placement;
        $this->adsBudgetSettlement = $adsBudgetSettlement;
    }

    public function index(): void
    {
        $page = max(1, $this->request->int('page', 1));
        $perPage = 20;

        $filters = array_filter([
            'placement' => $this->request->get('placement'),
            'banner_type' => $this->request->get('banner_type'),
            'category' => $this->request->get('category'),
            'is_active' => $this->request->get('is_active'),
            'status' => $this->request->get('status'),
        ], fn($v) => $v !== null && $v !== '');

        $search = trim(str_value($this->request->get('search', '')));
        $offset = ($page - 1) * $perPage;

        // PRIMARY: banner search belongs to BannerService; SearchOrchestrator::searchBanners was a legacy leftover.
        $result = $this->bannerService->searchBanners($search, $filters, $perPage, $offset);
        $banners = $result['items'] ?? [];
        $total = $result['total'] ?? 0;
                     
        // Use service to get placements
        $placements = $this->bannerService->getAllPlacements();
        
        // Get stats from service
        $stats = $this->bannerService->getStats();

        view('admin.banners.index', compact('banners', 'placements', 'filters', 'stats', 'total', 'page', 'perPage', 'search'));
    }

    public function create(): void
    {
        $placements = $this->placement->all();
        view('admin.banners.create', compact('placements'));
    }

    public function showCreate(): void
    {
        $this->create();
    }

    public function store(): void
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $input = $this->request->all();
        $request = new \App\Validators\Requests\CreateBannerRequest($input);

        if (!$request->validate()) {
            $errors = $request->errors();
            $firstError = reset($errors);
            $msg = is_array($firstError) ? reset($firstError) : $firstError;
            $this->session->setFlash('error', $msg ?: 'اطلاعات ورودی نامعتبر است.');
            redirect('/admin/banners/create');
        }

        $validatedData = $request->validated();
        $title = trim(str_value($validatedData['title']));
        $placement = trim(str_value($validatedData['placement']));

        // استفاده از UploadService (Sprint 6)
        $imagePath = null;
        if ($this->request->hasFile('image')) {
            $file = $this->request->file('image');
            if ($file === null) {
                throw new \Core\Exceptions\BusinessException('ساختار فایل بنر نامعتبر است.');
            }
            $result = $this->uploadService->upload($file, 'banners', ['jpg', 'png', 'webp', 'gif'], 5 * 1024 * 1024);
            if ($result['success']) {
                $imagePath = $result['path'];
            } else {
                $this->session->setFlash('error', 'خرابی در آپلود تصویر: ' . $result['message']);
                redirect('/admin/banners/create');
            }
        }

        $link = $validatedData['link'] ?? '';

        $data = [
            'type' => 'banner', // اجبار نوع متمرکز
            'title' => $title,
            'image_path' => $imagePath,
            'link' => $link,
            'placement' => $placement,
            'banner_type' => $this->request->input('banner_type', 'system'),
            'category' => $this->request->input('category'),
            'sort_order' => $this->request->int('sort_order', 0),
            'is_active' => $this->request->int('is_active', 1),
            'start_date' => $this->request->input('start_date'),
            'end_date' => $this->request->input('end_date'),
            'target' => $this->request->input('target', '_blank'),
            'alt_text' => $this->request->input('alt_text'),
            'user_id' => user_id(), // نگاشت یکدست به user_id
            'status' => 'active'
        ];

        $id = $this->banner->create($data);

        $this->session->setFlash('success', 'بنر ایجاد شد');
        redirect('/admin/banners');
    }

    public function edit(): void
    {
        $id = int_value($this->request->param('id') ?? $this->request->get('id', 0));
        $banner = $this->banner->find($id);

        if (!$banner) {
            $this->session->setFlash('error', 'بنر یافت نشد');
            redirect('/admin/banners');
        }

        $placements = $this->placement->all();
        view('admin.banners.edit', compact('banner', 'placements'));
    }

    public function showEdit(): void
    {
        $this->edit();
    }

    public function update(): void
    {
        // CORE-036: CSRF Protection
        $this->validateCsrf();

        $id = int_value($this->request->param('id') ?? $this->request->input('id', 0));
        $banner = $this->banner->find($id);

        if (!$banner || $banner->type !== 'banner') {
            $this->session->setFlash('error', 'بنر یافت نشد');
            redirect('/admin/banners');
        }

        $input = $this->request->all();
        $request = new \App\Validators\Requests\CreateBannerRequest($input);

        if (!$request->validate()) {
            $errors = $request->errors();
            $firstError = reset($errors);
            $msg = is_array($firstError) ? reset($firstError) : $firstError;
            $this->session->setFlash('error', $msg ?: 'اطلاعات ورودی نامعتبر است.');
            redirect('/admin/banners/edit?id=' . $id);
        }

        $validatedData = $request->validated();
        $link = $validatedData['link'] ?? '';

        // استفاده از UploadService (Sprint 6)
        $imagePath = null;
        if ($this->request->hasFile('image')) {
            $file = $this->request->file('image');
            if ($file === null) {
                throw new \Core\Exceptions\BusinessException('ساختار فایل بنر نامعتبر است.');
            }
            $result = $this->uploadService->upload($file, 'banners', ['jpg', 'png', 'webp', 'gif'], 5 * 1024 * 1024);
            if ($result['success']) {
                $imagePath = $result['path'];
            } else {
                $this->session->setFlash('error', 'خرابی در آپلود تصویر: ' . $result['message']);
                redirect('/admin/banners/edit?id=' . $id);
            }
        }

        $data = [
            'title' => trim(str_value($validatedData['title'])),
            'link' => $link,
            'placement' => trim(str_value($validatedData['placement'])),
            'category' => $this->request->input('category'),
            'sort_order' => $this->request->int('sort_order', 0),
            'is_active' => $this->request->int('is_active', 1),
            'start_date' => $this->request->input('start_date'),
            'end_date' => $this->request->input('end_date'),
            'target' => $this->request->input('target', '_blank'),
            'alt_text' => $this->request->input('alt_text'),
        ];

        if ($imagePath) {
            $data['image_path'] = $imagePath;
        }

        $this->banner->update($id, $data);

        $this->session->setFlash('success', 'بنر بروزرسانی شد');
        redirect('/admin/banners');
    }

    public function approve(): void
    {
        // PRIMARY: admin specialized banner approval delegates to unified Ads action.
        $this->validateCsrf();
        $id = $this->bannerIdFromRequest();
        $result = $this->adsBudgetSettlement->applyAdminAction($id, 'approve', (int)user_id(), 'تأیید از پنل تخصصی بنر');
        $this->session->setFlash(!empty($result['success']) ? 'success' : 'error', $result['message'] ?? 'عملیات انجام نشد.');
        redirect('/admin/banners');
    }

    public function reject(): void
    {
        // PRIMARY: reject must refund remaining held ad budget through unified settlement.
        $this->validateCsrf();
        $id = $this->bannerIdFromRequest();
        $reason = trim($this->request->str('reason', 'رد از پنل تخصصی بنر'));
        $result = $this->adsBudgetSettlement->applyAdminAction($id, 'reject', (int)user_id(), $reason !== '' ? $reason : 'رد از پنل تخصصی بنر');
        $this->session->setFlash(!empty($result['success']) ? 'success' : 'error', $result['message'] ?? 'رد بنر انجام نشد.');
        redirect('/admin/banners');
    }

    public function delete(): void
    {
        // PRIMARY: soft delete delegates to unified action so remaining budget is refunded.
        $this->validateCsrf();
        $id = $this->bannerIdFromRequest();
        $result = $this->adsBudgetSettlement->applyAdminAction($id, 'delete', (int)user_id(), 'حذف نرم از پنل تخصصی بنر');
        $this->session->setFlash(!empty($result['success']) ? 'success' : 'error', $result['message'] ?? 'حذف بنر انجام نشد.');
        redirect('/admin/banners');
    }

    public function toggle(): void
    {
        $this->validateCsrf();
        $id = $this->bannerIdFromRequest();
        $banner = $this->banner->find($id);
        if (!$banner || (string)$banner->type !== 'banner') {
            $this->session->setFlash('error', 'بنر یافت نشد');
            redirect('/admin/banners');
        }
        $action = ((string)$banner->status === 'paused' || (int)($banner->is_active ?? 1) === 0) ? 'resume' : 'pause';
        $result = $this->adsBudgetSettlement->applyAdminAction($id, $action, (int)user_id(), 'تغییر وضعیت از پنل تخصصی بنر');
        $this->session->setFlash(!empty($result['success']) ? 'success' : 'error', $result['message'] ?? 'تغییر وضعیت انجام نشد.');
        redirect('/admin/banners');
    }

    public function placements(): void
    {
        $placements = $this->placement->allWithBannerCount();
        view('admin.banners.placements', compact('placements'));
    }

    public function updatePlacement(): void
    {
        $this->validateCsrf();
        $id = int_value($this->request->param('id') ?? $this->request->input('id', 0));
        $allowedFields = [
            'title', 'description', 'is_active', 'show_on_mobile', 'show_on_desktop',
            'max_banners', 'rotation_speed', 'display_style', 'auto_rotate',
            'max_width', 'max_height'
        ];
        $input = array_intersect_key($this->request->all(), array_flip($allowedFields));
        $result = $this->bannerService->updatePlacement($id, $input);
        $this->session->setFlash(!empty($result['success']) ? 'success' : 'error', $result['message'] ?? 'به‌روزرسانی جایگاه انجام نشد.');
        redirect('/admin/banners/placements');
    }

    public function togglePlacement(): void
    {
        $this->validateCsrf();
        $id = int_value($this->request->param('id') ?? $this->request->input('id', 0));
        $result = $this->bannerService->togglePlacement($id);
        $this->session->setFlash(!empty($result['success']) ? 'success' : 'error', $result['message'] ?? 'تغییر وضعیت جایگاه انجام نشد.');
        redirect('/admin/banners/placements');
    }

    private function bannerIdFromRequest(): int
    {
        return int_value($this->request->param('id') ?? $this->request->input('id', 0));
    }

    public function stats(): void
    {
        // استفاده از BannerService برای دریافت آمار بنرها
        $stats = $this->bannerService->getStats();
        $placements = $this->placement->allWithBannerCount();
        view('admin.banners.stats', compact('stats', 'placements'));
    }
}
