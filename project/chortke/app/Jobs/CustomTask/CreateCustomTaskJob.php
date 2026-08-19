<?php
declare(strict_types=1);

namespace App\Jobs\CustomTask;

use Core\Database;
use Core\Logger;
use Core\EventDispatcher;
use Core\RateLimiter;
use App\Models\User;
use App\Models\Ads;
use App\Services\Settings\AppSettings;
use App\Services\EscrowService;

class CreateCustomTaskJob
{
    private RateLimiter $rateLimiter;
    private AppSettings $appSettings;
    private Database $db;
    private User $userModel;
    private Ads $taskModel;
    private EscrowService $escrowService;
    private Logger $logger;
    private ?\App\Services\Shared\IdempotencyService $idempotencyService;
    private ?\App\Contracts\WalletServiceInterface $walletService;
    private ?\App\Contracts\OutboxServiceInterface $outbox = null;
    public function __construct(
        RateLimiter $rateLimiter,
        AppSettings $appSettings,
        Database $db,
        User $userModel,
        Ads $taskModel,
        EscrowService $escrowService,
        Logger $logger,
        \App\Services\Shared\IdempotencyService $idempotencyService,
        ?\App\Contracts\OutboxServiceInterface $outbox = null,
        ?\App\Contracts\WalletServiceInterface $walletService = null
    ) {        $this->rateLimiter = $rateLimiter;
        $this->appSettings = $appSettings;
        $this->db = $db;
        $this->userModel = $userModel;
        $this->taskModel = $taskModel;
        $this->escrowService = $escrowService;
        $this->logger = $logger;
        $this->outbox = $outbox;
        $this->idempotencyService = $idempotencyService;
        $this->walletService = $walletService;
    }

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
public function handle(array $payload): array
    {
        $creatorId = int_value($payload["creator_id"] ?? 0);
        $dataValue = $payload["data"] ?? null;
        if (!is_array($dataValue)) {
            return ["success" => false, "message" => "ساختار داده وظیفه نامعتبر است"];
        }
        $data = $dataValue;

        if ($creatorId <= 0) {
            return ["success" => false, "message" => "شناسه کاربر نامعتبر است"];
        }

        if (!$this->rateLimiter->attempt("custom_task:create:" . $creatorId, 5, 60)) {
            $wait = ceil($this->rateLimiter->availableIn("custom_task:create:" . $creatorId) / 60);
            return ["success" => false, "message" => "تعداد درخواست‌های شما بیش از حد مجاز است. لطفا {$wait} دقیقه دیگر امتحان کنید."];
        }

        if (!$this->appSettings->get("custom_task_enabled", 1)) {
            return ["success" => false, "message" => "سیستم وظایف سفارشی غیرفعال است."];
        }

        // 🛡️ CT-06: KYC validation enforcement for custom task advertisers
        if ($this->appSettings->get("custom_task_kyc_required", 1)) {
            $user = $this->userModel->find($creatorId);
            if (!$user || !in_array($user->kyc_status ?? 'unverified', ['verified', 'approved'], true)) {
                return ["success" => false, "message" => "برای ایجاد تسک سفارشی، احراز هویت (KYC) الزامی است."];
            }
        }

        $title = $data['title'] ?? null;
        $description = $data['description'] ?? null;
        if (!is_string($title) || trim($title) === '' || !is_string($description) || trim($description) === '') {
            return ["success" => false, "message" => "عنوان و توضیحات وظیفه الزامی است"];
        }
        $data['title'] = trim($title);
        $data['description'] = trim($description);

        $currency = strtolower(str_value($data["currency"] ?? "irt"));
        if (in_array($currency, ["irr", "rial"], true)) { $currency = "irt"; }
        $pricePerTask = str_value($data["price_per_task"] ?? 0);
        $quantity = int_value($data["total_quantity"] ?? $data["total_count"] ?? $data["quantity"] ?? 1);

        $minPrice = $currency === "usdt"
            ? str_value($this->appSettings->get("custom_task_min_price_usdt", 0.50))
            : str_value($this->appSettings->get("custom_task_min_price_irt", 5000));

        if (!is_numeric($pricePerTask) || bccomp($pricePerTask, $minPrice, 8) < 0) {
            $label = $currency === "usdt" 
                ? number_format((float)$minPrice, 2) . " USDT" 
                : number_format((float)$minPrice) . " تومان";
            return ["success" => false, "message" => "حداقل قیمت برای هر وظیفه {$label} است."];
        }

        $feePercent = str_value($this->appSettings->get("custom_task_site_fee_percent", 10));
        $totalBudget = bcmul($pricePerTask, (string)$quantity, 8);
        $feeAmount = bcdiv(bcmul($totalBudget, $feePercent, 8), '100', 8);
        $totalWithFee = bcadd($totalBudget, $feeAmount, 8);

        try {
            $this->db->beginTransaction();

            $this->userModel->findByIdForUpdate($creatorId);

            $explicitKeyRaw = $payload['idempotency_key'] ?? null;
            $explicitKey = is_string($explicitKeyRaw) ? $explicitKeyRaw : null;
            $idempotencyPayload = [
                "creator_id" => $creatorId,
                "title" => $data["title"],
                "amount" => $totalWithFee,
                "currency" => $currency
            ];

            $result = ($this->idempotencyService ?? throw new \RuntimeException("Idempotency service not available"))->executeWithTransaction('task.create', $creatorId, $idempotencyPayload, function() use (
                $creatorId, $data, $currency, $pricePerTask, $quantity, $feePercent, $totalBudget, $feeAmount, $totalWithFee
            ) {
                // 🛡️ All custom tasks start as "active" — moderation review was removed in v2
                // (AdminCustomTaskService still has legacy filtering for "pending_review" stats)
                $task = $this->taskModel->create([
                    "type" => "custom_task",
                    "user_id" => $creatorId,
                    "title" => $data["title"],
                    "description" => $data["description"],
                    "link" => $data["link"] ?? $data["target_link"] ?? $data["target_url"] ?? null,
                    "target_url" => $data["target_url"] ?? $data["link"] ?? $data["target_link"] ?? null,
                    "task_type" => $data["task_type"] ?? "custom",
                    "proof_type" => $data["proof_type"] ?? "text",
                    "proof_description" => $data["proof_description"] ?? "مدرک انجام تسک را طبق توضیح تسک ارسال کنید.",
                    "proof_schema" => json_encode(["type" => $data["proof_type"] ?? "text", "required" => true, "description" => $data["proof_description"] ?? null], JSON_UNESCAPED_UNICODE),
                    "sample_image" => $data["sample_image"] ?? null,
                    "price_per_task" => $pricePerTask,
                    "currency" => $currency,
                    "total_budget" => $totalBudget,
                    "remaining_budget" => $totalBudget,
                    "total_count" => $quantity,
                    "remaining_count" => $quantity,
                    "pending_count" => 0,
                    "completed_count" => 0,
                    "deadline_hours" => $data["deadline_hours"] ?? 24,
                    "auto_approve_hours" => int_value($this->appSettings->get("custom_task_auto_approve_hours", 48)),
                    "country_restriction" => $data["country_restriction"] ?? null,
                    "device_restriction" => $data["device_restriction"] ?? "all",
                    "os_restriction" => $data["os_restriction"] ?? null,
                    "status" => "active",
                    "site_commission_percent" => $feePercent,
                    "restrictions" => json_encode([
                        "daily_limit_per_user" => $data["daily_limit_per_user"] ?? 1,
                        "site_fee_amount" => $feeAmount,
                    ]),
                ]);

                $taskId = is_int($task) ? $task : 0;
                if ($taskId <= 0) {
                    throw new \Core\Exceptions\ApplicationException("خطا در ذخیره وظیفه.");
                }

                $taskObj = $this->taskModel->find($taskId);
                if ($taskObj === null) {
                    throw new \RuntimeException('وظیفه ایجاد شد اما بازیابی آن ناموفق بود.');
                }

                assert_fraud_allowed($creatorId, 'custom_task.create', ['amount' => $totalWithFee]);

                $walletService = $this->walletService ?? app(\App\Contracts\WalletServiceInterface::class);
                $txResult = $walletService->withdraw(
                    $creatorId,
                    $totalWithFee,
                    $currency,
                    ['type' => 'escrow', 'description' => "بودجه تسک سفارشی - " . $data['title'], 'idempotency_key' => "ct_budget_{$creatorId}_" . time()]
                );
                if (!($txResult['success'] ?? false)) {
                    throw new \Core\Exceptions\InsufficientBalanceException('موجودی کیف پول شما کافی نیست.');
                }
                
                $escrowResult = $this->escrowService->holdFunds(
                    (string)$taskId,
                    "custom_task_budget",
                    $creatorId, 
                    $creatorId,
                    $totalWithFee,
                    $currency
                );

                if (empty($escrowResult["ok"])) {
                    throw new \Core\Exceptions\ApplicationException($escrowResult["error"] ?? "خطا در مسدودسازی مبلغ بودجه وظیفه.");
                }

                $this->logger->info("Custom task created and budget escrowed", [
                    "task_id" => $taskId,
                    "creator_id" => $creatorId,
                    "budget" => $totalWithFee,
                    "escrow_id" => $escrowResult["escrow_id"] ?? null
                ]);

                try {
                    $this->outbox?->record("custom_task", $taskId, "custom_task.created", [
                        "task_id" => $taskId,
                        "module" => "custom_task",
                        "type" => "custom_task"
                    ]);
                } catch (\Throwable $evtErr) {
                    $this->logger->warning("custom_task.create.event_failed", [
                        "task_id" => $taskId,
                        "error" => $evtErr->getMessage()
                    ]);
                }

                return [
                    "success" => true,
                    "message" => "وظیفه با موفقیت ثبت شد.",
                    "task" => $taskObj,
                ];
            }, $explicitKey);

            $this->db->commit();
            return $result;

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            $this->logger->error("task.create.failed", [
                "channel" => "task",
                "error" => $e->getMessage(),
            ]);
            return ["success" => false, "message" => "خطا در ثبت وظیفه: " . $e->getMessage()];
        }
    }
}
