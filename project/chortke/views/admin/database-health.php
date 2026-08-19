<?php
$title = $title ?? 'سلامت دیتابیس';
$slowQueries = is_array($slowQueries ?? null) ? $slowQueries : [];
$deadlocks = is_array($deadlocks ?? null) ? $deadlocks : [];
ob_start();
$toFaNum = fn($n) => is_numeric($n) ? fa_number($n) : $n;
?>
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">🗄 سلامت دیتابیس</h1>
        <a href="<?= url('/admin/dashboard') ?>" class="btn btn-sm btn-outline-primary">← بازگشت به داشبورد</a>
    </div>

    <?php if (!empty($error)): ?>
    <div class="alert alert-warning">⚠️ <?= e($error) ?></div>
    <?php endif; ?>

    <!-- ══ Stats Row ══ -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-muted">کوئری‌های کند</h5>
                    <h2 class="<?= count($slowQueries) > 20 ? 'text-danger' : (count($slowQueries) > 5 ? 'text-warning' : 'text-success') ?>">
                        <?= fa_number(count($slowQueries)) ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-muted">بن‌بست‌های اخیر</h5>
                    <h2 class="<?= count($deadlocks) > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= fa_number(count($deadlocks)) ?>
                    </h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-muted">جداول بررسی‌شده</h5>
                    <h2 class="text-primary"><?= fa_number(count($tableStats)) ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="text-muted">مشکلات سلامت</h5>
                    <?php $failedCount = count(array_filter($healthChecks, fn($v) => $v === false)); ?>
                    <h2 class="<?= $failedCount > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= fa_number($failedCount) ?>
                    </h2>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ Connection Pool ══ -->
    <?php if (!empty($connStats)): ?>
    <div class="card mb-4">
        <div class="card-header"><strong>📡 Connection Pool</strong></div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($connStats as $k => $v): ?>
                <div class="col-md-3 mb-2">
                    <small class="text-muted d-block"><?= e($k) ?></small>
                    <strong><?= e(is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE)) ?></strong>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ Slow Queries ══ -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between">
            <strong>🐌 کوئری‌های کند (۳۰ مورد اخیر)</strong>
            <small class="text-muted">از performance_schema</small>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>کوئری</th>
                        <th style="width:80px">تعداد</th>
                        <th style="width:90px">میانگین (ms)</th>
                        <th style="width:90px">بیشینه (ms)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($slowQueries)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">✅ هیچ کوئری کندی یافت نشد</td></tr>
                    <?php else: ?>
                    <?php $i = 0; foreach ($slowQueries as $q): $i++; ?>
                    <tr class="<?= ($q->avg_time_ms ?? 0) > 1000 ? 'table-danger' : (($q->avg_time_ms ?? 0) > 500 ? 'table-warning' : '') ?>">
                        <td><?= fa_number($i) ?></td>
                        <td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($q->sql_text ?? '') ?>">
                            <code><?= e(substr($q->sql_text ?? $q->slow_query_threshold_seconds ?? '—', 0, 120)) ?></code>
                        </td>
                        <td><?= fa_number($q->query_count ?? 0) ?></td>
                        <td><?= fa_number($q->avg_time_ms ?? 0) ?></td>
                        <td><?= fa_number($q->max_time_ms ?? 0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══ Deadlocks + Health ══ -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><strong>🔒 بن‌بست‌های InnoDB</strong></div>
                <div class="card-body">
                    <?php if (empty($deadlocks)): ?>
                    <p class="text-success mb-0">✅ هیچ بن‌بستی شناسایی نشد</p>
                    <?php else: ?>
                    <?php foreach ($deadlocks as $i => $d): ?>
                    <div class="alert alert-danger mb-2">
                        <strong>بن‌بست #<?= fa_number($i + 1) ?></strong>
                        <small class="d-block text-muted"><?= e($d['detected_at'] ?? 'نامشخص') ?></small>
                        <p class="mb-0 mt-1"><small><?= e($d['summary'] ?? '') ?></small></p>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header"><strong>🩺 سلامت عمومی</strong></div>
                <div class="card-body">
                    <?php if (empty($healthChecks)): ?>
                    <p class="text-muted mb-0">اطلاعاتی موجود نیست</p>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($healthChecks as $check => $ok): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center py-2 px-0 border-0">
                            <span><?= e($check) ?></span>
                            <span class="badge <?= $ok ? 'bg-success' : 'bg-danger' ?>">
                                <?= $ok ? '✅ سالم' : '❌ مشکل' ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ Table Stats ══ -->
    <div class="card mb-4">
        <div class="card-header"><strong>📊 آمار جداول</strong></div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>نام جدول</th>
                        <th>تعداد رکورد</th>
                        <th>حجم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tableStats as $tbl): ?>
                    <tr>
                        <td><code><?= e($tbl->table_name ?? $tbl->TABLE_NAME ?? '—') ?></code></td>
                        <td><?= fa_number($tbl->row_count ?? $tbl->TABLE_ROWS ?? 0) ?></td>
                        <td><?= e($tbl->size_mb ?? $tbl->SIZE_MB ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══ Index Recommendations ══ -->
    <?php if (!empty($indexRecs)): ?>
    <div class="card">
        <div class="card-header"><strong>🔧 پیشنهاد ایندکس</strong></div>
        <div class="card-body">
            <?php foreach ($indexRecs as $tblName => $recs): ?>
            <h6 class="mt-2">جدول: <code><?= e($tblName) ?></code></h6>
            <?php foreach ($recs as $rec): ?>
            <div class="alert alert-info mb-1 py-2">
                <strong><?= e($rec['column']) ?>:</strong>
                <code class="d-block mt-1"><?= e($rec['suggestion']) ?></code>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php
$content = ob_get_clean();
include view_path('layouts.admin');
