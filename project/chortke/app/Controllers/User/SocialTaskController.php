<?php

namespace App\Controllers\User;

use App\Services\SocialTask\SocialTaskService;
use App\Validators\Requests\CreateSocialTaskRequest;
use App\Validators\Requests\ExecuteSocialTaskRequest;
use App\Controllers\User\BaseUserController;

class SocialTaskController extends BaseUserController
{
    private SocialTaskService $socialTaskService;

    public function __construct(SocialTaskService $socialTaskService, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->socialTaskService = $socialTaskService;
    }

    /** @return array<string, string> */
    private function platforms(): array
    {
        return ['instagram' => 'اینستاگرام', 'telegram' => 'تلگرام', 'twitter' => 'توییتر'];
    }

    /** @return array<string, string> */
    private function taskTypes(): array
    {
        return ['follow' => 'فالو', 'like' => 'لایک', 'comment' => 'کامنت', 'share' => 'اشتراک‌گذاری'];
    }

    public function index(): void
    {
        $filters = [
            'platform' => $this->request->str('platform'),
            'task_type' => $this->request->str('task_type'),
            'sort' => $this->request->str('sort', 'newest'),
            'search' => str_value($this->request->get('q') ?: $this->request->get('search', '')),
            'is_mobile' => $this->request->get('mobile') ? 1 : 0,
        ];
        $result = $this->socialTaskService->getTasksForExecutor((int)$this->userId(), $filters, 24);
        $this->view('user/social-tasks/index', [
            'title' => 'تسک‌های اجتماعی',
            'tasks' => $result['tasks'] ?? [],
            'trust_score' => $result['trust_score'] ?? 50,
            'filters' => $filters,
            'platforms' => $this->platforms(),
            'task_types' => $this->taskTypes(),
        ]);
    }

    public function executorDashboard(): void
    {
        $this->view('user/social-tasks/dashboard', [
            'title' => 'داشبورد تسک‌های اجتماعی',
            'stats' => $this->socialTaskService->getExecutorStats((int)$this->userId()),
            'trust_score' => 50,
        ]);
    }

    public function history(): void
    {
        $page = max(1, $this->request->int('page', 1));
        $history = $this->socialTaskService->getExecutorHistory((int)$this->userId(), 20, ($page - 1) * 20);
        $this->view('user/social-tasks/history', [
            'title' => 'تاریخچه تسک‌ها',
            'history' => $history,
            'stats' => $this->socialTaskService->getExecutorStats((int)$this->userId()),
            'trust_score' => 50,
            'page' => $page,
        ]);
    }

    public function create(): void
    {
        $request = new CreateSocialTaskRequest($this->request->all());
        if (!$request->validate()) {
            $this->response->json(['success' => false, 'errors' => $request->errors()], 422);
            return;
        }
        $result = $this->socialTaskService->createTask((int)$this->userId(), $request->validated());
        $this->response->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function start(): void
    {
        $adId = int_value(
            $this->request->param('id')
            ?? $this->request->input('ad_id')
            ?? $this->request->input('task_id')
            ?? 0
        );
        $result = $this->socialTaskService->startExecution((int)$this->userId(), $adId, [
            'ip' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
        if (!empty($result['success']) && !empty($result['execution_id'])) {
            $result['redirect_url'] = url('/social-tasks/' . (int)$result['execution_id'] . '/execute');
            $result['message'] = $result['message'] ?? 'تسک اجتماعی شروع شد.';
        }
        $this->response->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function showExecute(): void
    {
        $executionId = (int)$this->request->param('id');
        $execution = $this->socialTaskService->getExecutionWithAd($executionId, (int)$this->userId());
        if (!$execution || (int)$execution->executor_id !== (int)$this->userId()) {
            $this->session->setFlash('error', 'اجرای تسک یافت نشد.');
            $this->response->redirect(url('/social-tasks'));
            return;
        }
        $this->view('user/social-tasks/execute', [
            'title' => 'انجام تسک',
            'execution' => $execution,
            'task' => $execution,
        ]);
    }

    public function submit(): void
    {
        $executionId = (int)$this->request->param('id');
        $payload = $this->request->all();
        $result = $this->socialTaskService->submitExecution((int)$this->userId(), $executionId, $payload);
        $this->response->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function execute(): void
    {
        $request = new ExecuteSocialTaskRequest($this->request->all());
        if (!$request->validate()) {
            $this->response->json(['success' => false, 'errors' => $request->errors()], 422);
            return;
        }
        $result = $this->socialTaskService->executeTask((int)$this->userId(), $request->validated());
        $this->response->json($result, !empty($result['success']) ? 200 : 422);
    }

    public function executionDetail(): void
    {
        // Compatibility route from older /social-ads URLs.
        $this->showExecute();
    }

    public function approveExecution(): void
    {
        $this->response->json([
            'success' => false,
            'message' => 'تأیید اجرای تسک از مسیر تبلیغ‌دهنده/مدیریت انجام می‌شود.'
        ], 422);
    }

    public function rejectExecution(): void
    {
        $this->response->json([
            'success' => false,
            'message' => 'رد اجرای تسک از مسیر تبلیغ‌دهنده/مدیریت انجام می‌شود.'
        ], 422);
    }

    public function rateExecutionForm(): void
    {
        $this->session->setFlash('warning', 'امتیازدهی مستقیم برای این مسیر فعال نیست.');
        $this->response->redirect(url('/tasks?type=social'));
    }

    public function rateExecution(): void
    {
        $this->response->json(['success' => false, 'message' => 'ثبت امتیاز فعلاً از این مسیر فعال نیست.'], 422);
    }

    public function ratingHistory(): void
    {
        $this->history();
    }

    public function rate(): void
    {
        $this->response->json(['success' => false, 'message' => 'ثبت امتیاز فعلاً از این مسیر فعال نیست.'], 422);
    }
}
