<?php

namespace App\Controllers\Admin;

use Core\Response;

use App\Services\FeatureFlagService;
use App\Controllers\Admin\BaseAdminController;
use App\Policies\FeatureFlagPolicy;

class FeatureFlagController extends BaseAdminController
{
    private FeatureFlagService $featureService;
    private FeatureFlagPolicy $policy;
    
    public function __construct(FeatureFlagService $featureService, FeatureFlagPolicy $policy, ?\App\Contracts\LoggerInterface $logger = null) {
        parent::__construct(null, null, null, null, $logger);
        $this->featureService = $featureService;
        $this->policy = $policy;
    }
    
    public function index(): string
    {
        $user = auth_user();
        if (!$this->policy->view($user)) {
            $this->response->json([
                'success' => false,
                'message' => 'شما دسترسی لازم برای مشاهده فیچرها را ندارید.'
            ], 403);
        }
        
        try {
            $features = $this->featureService->getAll();
            
            return view('admin/features/index', [
                'features' => $features
            ]);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('feature_flag.index.failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return view('admin/features/index', [
                'features' => [],
                'error' => 'خطا در دریافت لیست فیچرها'
            ]);
        }
    }
    
    public function toggle(): void
    {
        try {
            $data = $this->request->json();
            $name = str_value($data['name'] ?? '');
            
            if (!$name) {
                $this->response->json([
                    'success' => false, 
                    'message' => 'نام فیچر الزامی است.'
                ], 400);
                return;
            }
            
            $feature = $this->featureService->findByName($name);
            if (!$feature) {
                $this->response->json([
                    'success' => false,
                    'message' => 'فیچر مورد نظر یافت نشد.'
                ], 404);
                return;
            }
            
            $user = auth_user();
            if (!$this->policy->update($user, $feature)) {
                $this->response->json([
                    'success' => false,
                    'message' => 'شما دسترسی لازم برای تغییر این فیچر را ندارید.'
                ], 403);
                return;
            }
            
            if ($this->featureService->toggle($name)) {
                $newStatus = !$feature->enabled ? 'فعال' : 'غیرفعال';
                
                $this->auditLog(
                    'feature_toggled',
                    'feature_flag',
                    $feature->id ?? 0,
                    ['name' => $name, 'enabled' => $feature->enabled],
                    ['name' => $name, 'enabled' => !$feature->enabled]
                );
                
                $this->response->json([
                    'success' => true,
                    'message' => "وضعیت فیچر به {$newStatus} تغییر کرد."
                ]);
            }
            
            $this->response->json([
                'success' => false, 
                'message' => 'خطا در تغییر وضعیت.'
            ], 500);
            
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('feature_flag.toggle.failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            $this->response->json([
                'success' => false,
                'message' => 'خطای سرور در پردازش درخواست.'
            ], 500);
        }
    }
    
    public function update(): void
    {
        try {
            $data = $this->request->json();
            $name = str_value($data['name'] ?? '');
            
            if (!$name) {
                $this->response->json([
                    'success' => false,
                    'message' => 'نام فیچر الزامی است.'
                ], 400);
                return;
            }
            
            $feature = $this->featureService->findByName($name);
            if (!$feature) {
                $this->response->json([
                    'success' => false,
                    'message' => 'فیچر مورد نظر یافت نشد.'
                ], 404);
                return;
            }
            
            $user = auth_user();
            if (!$this->policy->update($user, $feature)) {
                $this->response->json([
                    'success' => false,
                    'message' => 'شما دسترسی لازم برای ویرایش این فیچر را ندارید.'
                ], 403);
                return;
            }
            
            $updateData = [];
            
            if (isset($data['description'])) {
                $updateData['description'] = trim(str_value($data['description']));
            }
            
            if (isset($data['enabled_percentage'])) {
                $percentage = int_value($data['enabled_percentage']);
                
                if ($percentage < 0 || $percentage > 100) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'درصد فعال‌سازی باید بین 0 تا 100 باشد.'
                    ], 400);
                }
                
                $updateData['enabled_percentage'] = $percentage;
            }
            
            if (isset($data['enabled_for_roles'])) {
                $roles = $data['enabled_for_roles'];
                
                if (!is_array($roles)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت نقش‌ها نامعتبر است.'
                    ], 400);
                    return;
                }
                
                $roles = array_filter(array_map(static fn($v) => trim(str_value($v)), $roles));
                $updateData['enabled_for_roles'] = $roles;
            }
            
            if (isset($data['enabled_for_users'])) {
                $users = $data['enabled_for_users'];
                
                if (!is_array($users)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت کاربران نامعتبر است.'
                    ], 400);
                    return;
                }
                
                $users = array_filter(array_map(static fn($v) => int_value($v), $users));
                $updateData['enabled_for_users'] = $users;
            }
            
            if (empty($updateData)) {
                $this->response->json([
                    'success' => false,
                    'message' => 'هیچ تغییری برای ذخیره وجود ندارد.'
                ], 400);
            }
            
            if ($this->featureService->update($name, $updateData)) {
                $this->auditLog(
                    'feature_updated',
                    'feature_flag',
                    $feature->id ?? 0,
                    null,
                    $updateData
                );
                
                $this->response->json([
                    'success' => true,
                    'message' => 'تنظیمات فیچر با موفقیت ذخیره شد.'
                ]);
            }
            
            $this->response->json([
                'success' => false,
                'message' => 'خطا در ذخیره تنظیمات.'
            ], 500);
            
        } catch (\InvalidArgumentException $e) {
            $this->response->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
            
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('feature_flag.update.failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            $this->response->json([
                'success' => false,
                'message' => 'خطای سرور در پردازش درخواست.'
            ], 500);
        }
    }
    
    public function create(): void
    {
        try {
            $user = auth_user();
            if (!$this->policy->create($user)) {
                $this->response->json([
                    'success' => false,
                    'message' => 'شما دسترسی لازم برای ایجاد فیچر را ندارید.'
                ], 403);
            }
            
            $data = $this->request->json() ?? [];
            
            $required = ['name', 'description'];
            foreach ($required as $field) {
                if (empty($data[$field])) {
                    $this->response->json([
                        'success' => false,
                        'message' => "فیلد {$field} الزامی است."
                    ], 400);
                }
            }
            
            if (!preg_match('/^[a-zA-Z0-9_]+$/', str_value($data['name']))) {
                $this->response->json([
                    'success' => false,
                    'message' => 'نام فیچر فقط می‌تواند شامل حروف انگلیسی، اعداد و _ باشد.'
                ], 400);
            }
            
            if ($this->featureService->create($data)) {
                $this->auditLog(
                    'feature_created',
                    'feature_flag',
                    0,
                    null,
                    $data
                );
                
                $this->response->json([
                    'success' => true,
                    'message' => 'فیچر با موفقیت ایجاد شد.'
                ]);
            }
            
            $this->response->json([
                'success' => false,
                'message' => 'خطا در ایجاد فیچر.'
            ], 500);
            
        } catch (\InvalidArgumentException $e) {
            $this->response->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
            
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('feature_flag.create.failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
    
    public function delete(): void
    {
        try {
            $data = $this->request->json();
            $name = str_value($data['name'] ?? '');
            
            if (!$name) {
                $this->response->json([
                    'success' => false,
                    'message' => 'نام فیچر الزامی است.'
                ], 400);
                return;
            }
            
            $feature = $this->featureService->findByName($name);
            if (!$feature) {
                $this->response->json([
                    'success' => false,
                    'message' => 'فیچر مورد نظر یافت نشد.'
                ], 404);
                return;
            }
            
            $user = auth_user();
            if (!$this->policy->delete($user, $feature)) {
                $this->response->json([
                    'success' => false,
                    'message' => 'شما دسترسی لازم برای حذف این فیچر را ندارید.'
                ], 403);
                return;
            }
            
            if ($this->featureService->delete($name)) {
                $this->auditLog(
                    'feature_deleted',
                    'feature_flag',
                    $feature->id ?? 0,
                    ['name' => $name],
                    null
                );
                
                $this->response->json([
                    'success' => true,
                    'message' => 'فیچر با موفقیت حذف شد.'
                ]);
            }
            
            $this->response->json([
                'success' => false,
                'message' => 'خطا در حذف فیچر.'
            ], 500);
            
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('feature_flag.delete.failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->response->json([
                'success' => false,
                'message' => 'خطای سرور.'
            ], 500);
        }
    }
    
    public function getStats(): void
    {
        try {
            $stats = $this->featureService->getStats();
            
            $this->response->json([
                'success' => true,
                'stats' => $stats
            ]);
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('feature_flag.stats.failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage()
            ]);
            
            $this->response->json([
                'success' => false,
                'message' => 'خطا در دریافت آمار'
            ], 500);
        }
    }
    
    public function advancedUpdate(): void
    {
        try {
            $data = $this->request->json();
            $name = str_value($data['name'] ?? '');
            
            if (!$name) {
                $this->response->json([
                    'success' => false,
                    'message' => 'نام فیچر الزامی است.'
                ], 400);
                return;
            }
            
            $feature = $this->featureService->findByName($name);
            if (!$feature) {
                $this->response->json([
                    'success' => false,
                    'message' => 'فیچر مورد نظر یافت نشد.'
                ], 404);
                return;
            }
            
            $user = auth_user();
            if (!$this->policy->update($user, $feature)) {
                $this->response->json([
                    'success' => false,
                    'message' => 'شما دسترسی لازم برای ویرایش این فیچر را ندارید.'
                ], 403);
                return;
            }
            
            $updateData = [];
            
            // Basic fields
            if (isset($data['description'])) {
                $updateData['description'] = trim(str_value($data['description']));
            }
            
            if (isset($data['enabled'])) {
                $updateData['enabled'] = (bool) $data['enabled'];
            }
            
            if (isset($data['enabled_percentage'])) {
                $percentage = int_value($data['enabled_percentage']);
                if ($percentage < 0 || $percentage > 100) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'درصد فعال‌سازی باید بین 0 تا 100 باشد.'
                    ], 400);
                }
                $updateData['enabled_percentage'] = $percentage;
            }
            
            // Advanced targeting
            if (isset($data['enabled_for_roles'])) {
                $roles = $data['enabled_for_roles'];
                if (!is_array($roles)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت نقش‌ها نامعتبر است.'
                    ], 400);
                    return;
                }
                $updateData['enabled_for_roles'] = array_filter(array_map(static fn($v) => trim(str_value($v)), $roles));
            }
            
            if (isset($data['enabled_for_users'])) {
                $users = $data['enabled_for_users'];
                if (!is_array($users)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت کاربران نامعتبر است.'
                    ], 400);
                    return;
                }
                $updateData['enabled_for_users'] = array_filter(array_map(static fn($v) => int_value($v), $users));
            }
            
            if (isset($data['enabled_for_countries'])) {
                $countries = $data['enabled_for_countries'];
                if (!is_array($countries)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت کشورها نامعتبر است.'
                    ], 400);
                    return;
                }
                $updateData['enabled_for_countries'] = array_filter(array_map(static fn($v) => strtoupper(trim(str_value($v))), $countries));
            }
            
            if (isset($data['enabled_for_devices'])) {
                $devices = $data['enabled_for_devices'];
                if (!is_array($devices)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت دستگاه‌ها نامعتبر است.'
                    ], 400);
                    return;
                }
                $updateData['enabled_for_devices'] = array_filter(array_map(static fn($v) => trim(str_value($v)), $devices));
            }
            
            if (isset($data['enabled_for_routes'])) {
                $routes = $data['enabled_for_routes'];
                if (!is_array($routes)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت مسیرها نامعتبر است.'
                    ], 400);
                    return;
                }
                $updateData['enabled_for_routes'] = array_filter(array_map(static fn($v) => trim(str_value($v)), $routes));
            }
            
            if (isset($data['min_age'])) {
                $minAge = int_value($data['min_age']);
                if ($minAge < 0 || $minAge > 120) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'سن حداقل باید بین 0 تا 120 باشد.'
                    ], 400);
                }
                $updateData['min_age'] = $minAge;
            }
            
            if (isset($data['max_age'])) {
                $maxAge = int_value($data['max_age']);
                if ($maxAge < 0 || $maxAge > 120) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'سن حداکثر باید بین 0 تا 120 باشد.'
                    ], 400);
                }
                $updateData['max_age'] = $maxAge;
            }
            
            // Time-based scheduling
            if (isset($data['enabled_from'])) {
                $enabledFrom = trim(str_value($data['enabled_from']));
                if (!empty($enabledFrom) && !strtotime($enabledFrom)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت تاریخ شروع نامعتبر است.'
                    ], 400);
                }
                $updateData['enabled_from'] = empty($enabledFrom) ? null : $enabledFrom;
            }
            
            if (isset($data['enabled_until'])) {
                $enabledUntil = trim(str_value($data['enabled_until']));
                if (!empty($enabledUntil) && !strtotime($enabledUntil)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت تاریخ پایان نامعتبر است.'
                    ], 400);
                }
                $updateData['enabled_until'] = empty($enabledUntil) ? null : $enabledUntil;
            }
            
            // Dependencies and environments
            if (isset($data['depends_on'])) {
                $dependsOn = $data['depends_on'];
                if (!is_array($dependsOn)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت وابستگی‌ها نامعتبر است.'
                    ], 400);
                    return;
                }
                $updateData['depends_on'] = array_filter(array_map(static fn($v) => trim(str_value($v)), $dependsOn));
            }
            
            if (isset($data['environments'])) {
                $environments = $data['environments'];
                if (!is_array($environments)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت محیط‌ها نامعتبر است.'
                    ], 400);
                    return;
                }
                $updateData['environments'] = array_filter(array_map(static fn($v) => trim(str_value($v)), $environments));
            }
            
            if (isset($data['tags'])) {
                $tags = $data['tags'];
                if (!is_array($tags)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت تگ‌ها نامعتبر است.'
                    ], 400);
                    return;
                }
                $updateData['tags'] = array_filter(array_map(static fn($v) => trim(str_value($v)), $tags));
            }
            
            if (isset($data['metadata'])) {
                $metadata = $data['metadata'];
                if (!is_array($metadata)) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'فرمت متادیتا نامعتبر است.'
                    ], 400);
                }
                $updateData['metadata'] = $metadata;
            }
            
            if (isset($data['priority'])) {
                $priority = int_value($data['priority']);
                if ($priority < 0 || $priority > 100) {
                    $this->response->json([
                        'success' => false,
                        'message' => 'اولویت باید بین 0 تا 100 باشد.'
                    ], 400);
                }
                $updateData['priority'] = $priority;
            }
            
            if (empty($updateData)) {
                $this->response->json([
                    'success' => false,
                    'message' => 'هیچ تغییری برای ذخیره وجود ندارد.'
                ], 400);
            }
            
            if ($this->featureService->update($name, $updateData)) {
                $this->auditLog(
                    'feature_advanced_updated',
                    'feature_flag',
                    $feature->id ?? 0,
                    null,
                    $updateData
                );
                
                $this->response->json([
                    'success' => true,
                    'message' => 'تنظیمات پیشرفته فیچر با موفقیت ذخیره شد.'
                ]);
            }
            
            $this->response->json([
                'success' => false,
                'message' => 'خطا در ذخیره تنظیمات پیشرفته.'
            ], 500);
            
        } catch (\InvalidArgumentException $e) {
            $this->response->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
            
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('feature_flag.advanced_update.failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            $this->response->json([
                'success' => false,
                'message' => 'خطای سرور در پردازش درخواست.'
            ], 500);
        }
    }
    
    public function getMetrics(string $name): void
    {
        try {
            if (!$name) {
                $this->response->json([
                    'success' => false,
                    'message' => 'نام فیچر الزامی است.'
                ], 400);
            }
            
            $feature = $this->featureService->findByName($name);
            if (!$feature) {
                $this->response->json([
                    'success' => false,
                    'message' => 'فیچر مورد نظر یافت نشد.'
                ], 404);
            }
            
            $metrics = $this->featureService->getMetrics($name);
            
            // Aggregate metrics
            $aggregated = [
                'total_checks' => 0,
                'enabled_count' => 0,
                'disabled_count' => 0,
                'success_rate' => 0,
                'avg_response_time' => 0,
                'max_response_time' => 0,
                'reasons' => []
            ];
            
            foreach ($metrics as $metric) {
                $aggregated['total_checks'] += $metric->total_checks;
                $aggregated['enabled_count'] += $metric->allowed_count;
                $aggregated['disabled_count'] += $metric->denied_count;
                $aggregated['reasons'][] = [
                    'reason' => $metric->check_reason,
                    'count' => $metric->reason_count,
                    'percentage' => $aggregated['total_checks'] > 0 ? 
                        round(($metric->reason_count / $aggregated['total_checks']) * 100, 1) : 0
                ];
                
                if (isset($metric->avg_response_time) && $metric->avg_response_time) {
                    $aggregated['avg_response_time'] = max($aggregated['avg_response_time'], $metric->avg_response_time);
                }
                
                if (isset($metric->max_response_time) && $metric->max_response_time) {
                    $aggregated['max_response_time'] = max($aggregated['max_response_time'], $metric->max_response_time);
                }
            }
            
            if ($aggregated['total_checks'] > 0) {
                $aggregated['success_rate'] = round(($aggregated['enabled_count'] / $aggregated['total_checks']) * 100, 1);
            }
            
            $this->response->json([
                'success' => true,
                'metrics' => $aggregated
            ]);
            
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('feature_flag.metrics.failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            $this->response->json([
                'success' => false,
                'message' => 'خطا در دریافت آمار.'
            ], 500);
        }
    }
    
    public function getHistory(string $name): void
    {
        try {
            if (!$name) {
                $this->response->json([
                    'success' => false,
                    'message' => 'نام فیچر الزامی است.'
                ], 400);
            }
            
            $feature = $this->featureService->findByName($name);
            if (!$feature) {
                $this->response->json([
                    'success' => false,
                    'message' => 'فیچر مورد نظر یافت نشد.'
                ], 404);
            }
            
            $history = $this->featureService->getHistory($name);
            
            $this->response->json([
                'success' => true,
                'history' => $history
            ]);
            
        } catch (\Core\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('feature_flag.history.failed', [
                'channel' => 'feature_flag',
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            
            $this->response->json([
                'success' => false,
                'message' => 'خطا در دریافت تاریخچه.'
            ], 500);
        }
    }
}
