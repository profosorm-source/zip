<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Services\Interaction\FavoriteService;
use App\Services\Interaction\RatingService;
use App\Services\Interaction\ReportService;
use App\Enums\ModuleContext;
use App\Validators\Requests\ToggleFavoriteRequest;
use App\Validators\Requests\RateInteractionRequest;
use App\Validators\Requests\ReportInteractionRequest;

/**
 * InteractionApiController - نقطه پایانی واحد برای تمامی تعاملات (لایک، امتیاز، ریپورت) به صورت پلیمورفیک
 */
class InteractionApiController extends BaseApiController
{
    private FavoriteService $favoriteService;
    private RatingService $ratingService;
    private ReportService $reportService;
    private \App\Services\VitrineService $vitrineService;

    public function __construct(
        FavoriteService $favoriteService,
        RatingService $ratingService,
        ReportService $reportService,
        \App\Services\VitrineService $vitrineService
    , ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->favoriteService = $favoriteService;
        $this->ratingService = $ratingService;
        $this->reportService = $reportService;
        $this->vitrineService = $vitrineService;
    }

    /** لیست آگهی‌های ویترین */
    public function vitrineList(): void
    {
        [$page, $perPage, $offset] = $this->paginationParams(20);
        $rawFilters = $this->request->get();
        $filters = is_array($rawFilters) ? $rawFilters : [];
        $result = $this->vitrineService->getListings($filters, $perPage, $offset);
        $total = is_numeric($result['total'] ?? null) ? (int)$result['total'] : 0;
        $listings = is_array($result['listings'] ?? null) ? $result['listings'] : [];
        $this->paginated($listings, $total, $page, $perPage);
    }

    /** نمایش جزئیات آگهی ویترین */
    public function vitrineShow(): void
    {
        $userId = (int)$this->userId();
        $id = $this->request->int('id');
        $details = $this->vitrineService->getListingDetails($id, $userId);
        if (!$details) {
            $this->error('آگهی یافت نشد یا دسترسی مجاز نیست', 404, 'VITRINE_NOT_FOUND');
        }
        $this->success($details);
    }

    /** ثبت درخواست معامله در ویترین */
    public function vitrineTradeRequest(): void
    {
        $userId = (int)$this->userId();
        $id = int_value($this->request->param('id') ?? $this->request->get('id') ?? 0);
        $data = $this->request->body();
        $check = $this->vitrineService->canTrade($userId);
        if (empty($check['ok'])) {
            $this->error($check['message'] ?? 'مجاز به معامله نیستید', 403);
        }
        if ($id <= 0) {
            $this->error('شناسه آگهی نامعتبر است', 422, 'INVALID_LISTING_ID');
        }
        $requestModel = $this->container->make(\App\Models\VitrineRequest::class);
        try {
            $reqId = $requestModel->createRequest(array_merge($data, [
                'listing_id' => $id,
                'requester_id' => $userId,
                'buyer_id' => $userId,
                'status' => 'pending',
            ]));
            $this->success(['request_id' => $reqId], 'درخواست معامله با موفقیت ثبت شد', 201);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('api.vitrine_trade.failed', [
                'user_id' => $userId,
                'listing_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $this->error('خطا در ثبت درخواست معامله', 422);
        }
    }

    /**
     * POST /api/v1/interactions/favorite/toggle
     */
    public function toggleFavorite(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $body = $this->request->body();

        $validator = new ToggleFavoriteRequest($body);
        $validator->validate();

        if ($validator->fails()) {
            $this->error('اطلاعات ورودی نامعتبر است.', 422, implode(', ', array_map(static fn($v) => is_array($v) ? implode(', ', $v) : str_value($v), $validator->errors())));
        }

        $data = $validator->validated();
        $context = ModuleContext::tryFrom(str_value($data['context'])) ?? ModuleContext::GLOBAL;

        $success = $this->favoriteService->toggle(
            $user->id,
            str_value($data['type']),
            int_value($data['id']),
            $context
        );

        if ($success) {
            $hasFavorited = $this->favoriteService->hasFavorited($user->id, str_value($data['type']), int_value($data['id']));
            $this->success([
                'message' => 'عملیات با موفقیت انجام شد.',
                'is_favorited' => $hasFavorited
            ]);
        } else {
            $this->error('خطا در ثبت علاقه‌مندی.', 500);
        }
    }

    /**
     * POST /api/v1/interactions/rate
     */
    public function rate(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $body = $this->request->body();

        $validator = new RateInteractionRequest($body);
        $validator->validate();

        if ($validator->fails()) {
            $this->error('اطلاعات ورودی نامعتبر است.', 422, implode(', ', array_map(static fn($v) => is_array($v) ? implode(', ', $v) : str_value($v), $validator->errors())));
        }

        $data = $validator->validated();
        $context = ModuleContext::tryFrom(str_value($data['context'])) ?? ModuleContext::GLOBAL;

        $success = $this->ratingService->rate(
            $user->id,
            str_value($data['type']),
            int_value($data['id']),
            $context,
            int_value($data['rating'])
        );

        if ($success) {
            $average = $this->ratingService->getAverageRating(str_value($data['type']), int_value($data['id']));
            $this->success([
                'message' => 'امتیاز با موفقیت ثبت شد.',
                'average_rating' => $average
            ]);
        } else {
            $this->error('خطا در ثبت امتیاز.', 500);
        }
    }

    /**
     * POST /api/v1/interactions/report
     */
    public function report(): void
    {
        $user = $this->currentUser();
        if ($user === null) { $this->error('احراز هویت لازم است', 401); return; }
        $body = $this->request->body();

        $validator = new ReportInteractionRequest($body);
        $validator->validate();

        if ($validator->fails()) {
            $this->error('اطلاعات ورودی نامعتبر است.', 422, implode(', ', array_map(static fn($v) => is_array($v) ? implode(', ', $v) : str_value($v), $validator->errors())));
        }

        $data = $validator->validated();
        $context = ModuleContext::tryFrom(str_value($data['context'])) ?? ModuleContext::GLOBAL;

        $success = $this->reportService->submit(
            $user->id,
            str_value($data['type']),
            int_value($data['id']),
            $context,
            str_value($data['reason']),
            is_string($data['description'] ?? null) ? $data['description'] : null
        );

        if ($success) {
            $this->success([
                'message' => 'گزارش شما با موفقیت ثبت شد و بررسی خواهد شد.'
            ]);
        } else {
            $this->error('خطا در ثبت گزارش.', 500);
        }
    }
}
