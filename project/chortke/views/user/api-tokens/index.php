<?php
$title = 'توکن‌های API';
$hideSidebar = true;
$tokens = $tokens ?? [];
$newToken = $newToken ?? null;

ob_start();
?>

<div id="accountApiTokensRoot" class="acc-wrap" data-revoke-base="<?= e(url('/api-tokens')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <section class="acc-hero">
        <div class="acc-hero__main">
            <div class="acc-hero__icon"><i class="material-icons">vpn_key</i></div>
            <div>
                <div class="acc-hero__eyebrow">API Access</div>
                <h1 class="acc-hero__title">توکن‌های API</h1>
                <p class="acc-hero__sub">برای اتصال برنامه‌های خارجی به داده‌های حساب، توکن‌های امن بسازید و دسترسی‌ها را مدیریت کنید.</p>
            </div>
        </div>
        <div class="acc-hero__side">
            <a href="<?= url('/profile') ?>" class="acc-btn acc-btn-panel"><i class="material-icons">arrow_forward</i> بازگشت به مرکز حساب</a>
            <button class="acc-btn acc-btn-primary" data-bs-toggle="modal" data-bs-target="#createTokenModal"><i class="material-icons">add</i> توکن جدید</button>
        </div>
    </section>

    <div class="acc-hub-layout">
        <?php $activeSpoke = 'api'; include view_path('user.account._account-nav'); ?>
        <main class="acc-hub-main">
            <?php if (!empty($newToken)): ?>
                <div class="acc-alert acc-alert-warning">
                    <i class="material-icons">warning</i>
                    <div style="width:100%;">
                        <strong>توکن شما ساخته شد — فقط یک‌بار نمایش داده می‌شود.</strong>
                        <p style="margin:6px 0 10px;color:var(--acc-text-dim);">این توکن را در جای امن ذخیره کنید. پس از خروج از صفحه دیگر قابل مشاهده نیست.</p>
                        <div style="display:flex;gap:8px;">
                            <input type="text" id="newTokenInput" class="acc-input acc-num" value="<?= e($newToken) ?>" readonly>
                            <button type="button" class="acc-btn acc-btn-secondary" data-action="copy-token"><i class="material-icons">content_copy</i> کپی</button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <section class="acc-stats">
                <div class="acc-stat acc-stat--gold"><div class="acc-stat__icon"><i class="material-icons">vpn_key</i></div><div><span class="acc-stat__lbl">توکن‌های فعال</span><span class="acc-stat__val acc-num"><?= number_format(count($tokens)) ?></span><span class="acc-stat__unit">حداکثر ۱۰ توکن</span></div></div>
                <div class="acc-stat acc-stat--blue"><div class="acc-stat__icon"><i class="material-icons">api</i></div><div><span class="acc-stat__lbl">Header استفاده</span><span class="acc-stat__val">Bearer</span><span class="acc-stat__unit">Authorization Header</span></div></div>
                <div class="acc-stat acc-stat--green"><div class="acc-stat__icon"><i class="material-icons">visibility</i></div><div><span class="acc-stat__lbl">سطح پیش‌فرض</span><span class="acc-stat__val">Read</span><span class="acc-stat__unit">دسترسی خواندن</span></div></div>
                <div class="acc-stat acc-stat--red"><div class="acc-stat__icon"><i class="material-icons">security</i></div><div><span class="acc-stat__lbl">امنیت</span><span class="acc-stat__val">محرمانه</span><span class="acc-stat__unit">عدم اشتراک توکن</span></div></div>
            </section>

            <section class="acc-section">
                <div class="acc-section__header"><div class="acc-section__title"><i class="material-icons">integration_instructions</i> نحوه استفاده</div><a href="<?= url('/api/v1/docs') ?>" target="_blank" class="acc-btn acc-btn-ghost"><i class="material-icons">open_in_new</i> مستندات API</a></div>
                <div class="acc-section__body">
                    <div class="acc-form-row">
                        <div><small style="color:var(--acc-text-faint);">ارسال در Header</small><code class="acc-code">Authorization: Bearer &lt;token&gt;</code></div>
                        <div><small style="color:var(--acc-text-faint);">نمونه endpoint</small><code class="acc-code">GET <?= e(url('/api/v1/wallet')) ?></code></div>
                    </div>
                </div>
            </section>

            <section class="acc-section" style="margin-top:16px;">
                <div class="acc-section__header"><div class="acc-section__title"><i class="material-icons">table_rows</i> توکن‌های فعال</div><button class="acc-btn acc-btn-primary" data-bs-toggle="modal" data-bs-target="#createTokenModal"><i class="material-icons">add</i> ساخت توکن</button></div>
                <?php if (empty($tokens)): ?>
                    <div class="acc-empty"><i class="material-icons">vpn_key_off</i><h3>هنوز توکنی نساخته‌اید</h3><p>برای اتصال برنامه خارجی، اولین توکن API خود را بسازید.</p><button class="acc-btn acc-btn-primary" data-bs-toggle="modal" data-bs-target="#createTokenModal">اولین توکن را بسازید</button></div>
                <?php else: ?>
                    <div class="acc-table-wrap">
                        <table class="acc-table">
                            <thead><tr><th>نام</th><th>دسترسی‌ها</th><th>آخرین استفاده</th><th>تعداد استفاده</th><th>انقضا</th><th>ساخته شده</th><th>عملیات</th></tr></thead>
                            <tbody>
                                <?php foreach ($tokens as $token): ?>
                                    <?php $expired = !empty($token->expires_at) && strtotime($token->expires_at) < time(); ?>
                                    <tr>
                                        <td><strong><?= e($token->name ?? '—') ?></strong></td>
                                        <td><?php foreach (explode(',', $token->scopes ?? 'read') as $scope): ?><span class="acc-badge acc-badge--muted"><?= e(trim($scope)) ?></span> <?php endforeach; ?></td>
                                        <td><?= !empty($token->last_used_at) ? to_jalali($token->last_used_at) : 'هرگز' ?></td>
                                        <td class="acc-num"><?= number_format((int)($token->use_count ?? 0)) ?></td>
                                        <td><span class="acc-badge <?= $expired ? 'acc-badge--danger' : 'acc-badge--success' ?>"><?= !empty($token->expires_at) ? to_jalali($token->expires_at) . ($expired ? ' (منقضی)' : '') : 'بدون انقضا' ?></span></td>
                                        <td><?= to_jalali($token->created_at ?? '') ?></td>
                                        <td><button class="acc-btn acc-btn-danger" data-action="revoke-token" data-token-id="<?= (int)($token->id ?? 0) ?>" data-token-name="<?= e($token->name ?? '') ?>"><i class="material-icons">delete</i> ابطال</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>
</div>

<div class="modal fade acc-modal" id="createTokenModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= url('/api-tokens/create') ?>">
                <?= csrf_field() ?>
                <div class="modal-header"><h5 class="modal-title">ساخت توکن جدید</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label">نام توکن</label><input type="text" name="name" class="form-control" placeholder="مثلاً: اپلیکیشن موبایل" required maxlength="100"><div class="form-text">نامی توصیفی برای تشخیص توکن.</div></div>
                    <div class="mb-3"><label class="form-label">انقضا</label><select name="expires_in" class="form-select"><option value="30">۳۰ روز</option><option value="90">۹۰ روز</option><option value="365">یک سال</option><option value="0">بدون انقضا</option></select></div>
                    <div class="mb-3"><label class="form-label">دسترسی</label><select name="scope" class="form-select"><option value="read">read</option><option value="read,wallet">read,wallet</option></select></div>
                </div>
                <div class="modal-footer"><button type="button" class="acc-btn acc-btn-secondary" data-bs-dismiss="modal">انصراف</button><button type="submit" class="acc-btn acc-btn-primary">ساخت توکن</button></div>
            </form>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/useraccount.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userapitokensindex.js') . '"></script>';
include view_path('layouts.user');
?>
