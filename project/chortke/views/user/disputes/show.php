<?php
$dispute = $dispute ?? null;
$messages = $messages ?? [];
$statusLabels = [
    'open' => 'باز', 'open_peer' => 'در انتظار طرفین', 'under_review' => 'در حال بررسی',
    'escalated' => 'ارجاع‌شده به مدیریت', 'resolved_peer' => 'حل‌شده با توافق',
    'resolved_admin' => 'حل‌شده توسط مدیریت', 'resolved_for_executor' => 'حل‌شده به نفع مجری',
    'resolved_for_advertiser' => 'حل‌شده به نفع کارفرما', 'closed' => 'بسته',
];
$title = 'جزئیات پرونده #' . (int)($dispute->id ?? 0);
ob_start();
?>



<div class="container-fluid py-3" dir="rtl">
    <div class="row">
        <div class="col-lg-4 mb-4 mb-lg-0">
            <!-- Side Info Card -->
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-body">
                    <a href="<?= url('/disputes') ?>" class="btn btn-light btn-sm rounded-pill mb-3">
                        <i class="material-icons small align-middle ms-1">arrow_forward</i> بازگشت به لیست
                    </a>
                    <h5 class="fw-bold mb-3">جزئیات پرونده #<?= $dispute->id ?></h5>
                    <div class="d-flex justify-content-between mb-2 border-bottom pb-2">
                        <span class="text-muted small">وضعیت:</span>
                        <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill border border-primary border-opacity-25"><?= e($statusLabels[(string)$dispute->status] ?? (string)$dispute->status) ?></span>
                    </div>
                    <div class="mb-3">
                        <span class="text-muted small d-block mb-1">علت باز شدن پرونده:</span>
                        <div class="p-2 bg-light rounded-3 small fw-semibold"><?= e($dispute->reason) ?></div>
                    </div>
                    <div class="alert alert-info py-2 px-3 rounded-3 small d-flex align-items-center" role="alert">
                        <i class="material-icons me-2">info</i>
                        لطفاً در گفت‌وگو احترام را رعایت نمایید. مدیران سیستم بر این گفت‌وگو نظارت دارند.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <!-- Dynamic Chat Hub -->
            <div class="chat-container">
                <!-- Header -->
                <div class="p-3 border-bottom bg-white bg-opacity-50 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <div class="avatar bg-warning bg-opacity-10 text-warning rounded-circle d-flex align-items-center justify-content-center" class="avatar-40">
                            <i class="material-icons">forum</i>
                        </div>
                        <div>
                            <h6 class="mb-0 fw-bold">گفت‌وگوی حل اختلاف</h6>
                            <small class="text-muted">در انتظار پاسخ طرفین یا داوری</small>
                        </div>
                    </div>
                </div>

                <!-- Body -->
                <div class="chat-messages d-flex flex-column" id="chatArea">
                    <?php foreach($messages as $msg): 
                        $isMe = (int)$msg->user_id === user_id();
                        $cls = $isMe ? 'me' : 'opponent';
                    ?>
                        <div class="chat-bubble <?= $cls ?>">
                            <div class="small fw-bold mb-1 opacity-75"><?= e($isMe ? 'شما' : ($msg->sender_name ?? 'طرف مقابل')) ?></div>
                            <div><?= nl2br(e($msg->message)) ?></div>
                            <span class="chat-time"><?= date('H:i', strtotime($msg->created_at)) ?></span>
                        </div>
                    <?php endforeach; ?>

                    <?php if(empty($messages)): ?>
                        <div class="text-center my-auto text-muted py-5">
                            <i class="material-icons opacity-25 icon-60">chat_bubble_outline</i>
                            <p class="mt-2 fw-semibold">هنوز هیچ پیامی ثبت نشده است.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Input -->
                <?php if(!in_array((string)$dispute->status, \App\Models\Dispute::CLOSED_STATUSES, true)): ?>
                    <div class="chat-input-area">
                        <form action="<?= url("/disputes/{$dispute->id}/reply") ?>" method="POST">
                            <?= csrf_field() ?>
                            <div class="input-group">
                                <textarea name="message" class="form-control border-0 bg-light rounded-4 shadow-none" rows="2" placeholder="پیام خود را اینجا بنویسید..." class="resize-none" required></textarea>
                                <button type="submit" class="btn btn-primary rounded-circle ms-2 d-flex align-items-center justify-content-center shadow" class="btn-send">
                                    <i class="material-icons">send</i>
                                </button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="chat-input-area text-center bg-light">
                        <div class="text-muted small fw-bold"><i class="material-icons small align-middle me-1">lock</i> این پرونده بسته شده است و امکان ارسال پیام جدید وجود ندارد.</div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>



<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/userdisputesshow.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/userdisputesshow.js') . '"></script>';
include view_path('layouts.user');
?>