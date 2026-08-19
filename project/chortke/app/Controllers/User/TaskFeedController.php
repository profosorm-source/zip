<?php

declare(strict_types=1);

namespace App\Controllers\User;

use App\Controllers\BaseController;
use App\Services\UnifiedTaskService;

/**
 * TaskFeedController - The Master Dashboard for Workers to Find and Filter Earning Tasks.
 */
class TaskFeedController extends BaseController
{
    private UnifiedTaskService $taskService;

    public function __construct(
        UnifiedTaskService $taskService,
        ?\App\Contracts\LoggerInterface $logger = null
    ) {
        $this->taskService = $taskService;
        parent::__construct(null, null, null, null, $logger);
    }

    /**
     * Display the Unified Dynamic Task Feed / Earning Hub.
     */
    public function index(): void
    {
        $userId = (int)user_id();
        $filters = [
            'type'      => $this->request->query('type'),
            'platform'  => $this->request->query('platform'),
            'min_price' => $this->request->query('min_price'),
            'max_price' => $this->request->query('max_price'),
            'q'         => $this->request->query('q'),
            'sort'      => $this->request->query('sort', 'newest'),
        ];

        $page   = max(1, $this->request->int('page', 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $tasks = [];
        $totalTasks = 0;
        $totalPages = 1;
        $platforms = [];
        $userStats = (object)['total_completed' => 0, 'total_earned' => 0, 'pending' => 0];

        try {
            $tasks      = $this->taskService->getTasksForExecutor($userId, $filters, $limit, $offset);
            $totalTasks = (int)$this->taskService->countTasksForExecutor($userId, $filters);
            $totalPages = max(1, (int)ceil($totalTasks / $limit));
            $platforms  = $this->taskService->getAvailablePlatforms();
            $userStats  = (object)$this->taskService->getExecutorStats($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('earn_hub.feed_unavailable', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        view('user.tasks.feed', [
            'tasks'       => $tasks,
            'totalTasks'  => $totalTasks,
            'totalPages'  => $totalPages,
            'currentPage' => $page,
            'filters'     => $filters,
            'platforms'   => $platforms,
            'userStats'   => $userStats,
            'totalDone'   => $userStats->total_completed ?? 0,
        ]);
    }
}
