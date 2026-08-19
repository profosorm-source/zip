<?php if (auth()): ?>
<!-- دکمه شناور گزارش باگ -->
<div id="bug-report-fab" title="گزارش مشکل" class="bug-fab">
    <span class="material-icons icon-lg">bug_report</span>
</div>

<!-- مودال گزارش باگ -->
<div class="modal fade" id="bugReportModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" class="modal-content-rounded">
            <div class="modal-header" class="modal-header-orange">
                <h6 class="modal-title text-white mb-0">
                    <span class="material-icons me-1" class="align-middle icon-sm">bug_report</span>
                    گزارش مشکل
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="bug-report-success" class="d-none" class="text-center py-4">
                    <span class="material-icons text-success">check_circle</span>
                    <h5 class="mt-3 text-success">گزارش ثبت شد!</h5>
                    <p class="text-muted">از همکاری شما متشکریم. تیم فنی بررسی خواهد کرد.</p>
                </div>

                <form id="bugReportForm" class="d-block">
  <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" id="bug_page_url" value="">
                    <input type="hidden" id="bug_page_title" value="">
                    <input type="hidden" id="bug_screen_resolution" value="">
                    <input type="hidden" id="bug_device_fingerprint" value="">

                    <div class="mb-3">
                        <label class="form-label fw-bold" class="fs-13">دسته‌بندی مشکل</label>
                        <select id="bug_category" class="form-select form-select-sm">
                            <option value="other">سایر</option>
                            <option value="ui_issue">مشکل ظاهری</option>
                            <option value="functional">مشکل عملکردی</option>
                            <option value="payment">مشکل پرداخت</option>
                            <option value="security">مشکل امنیتی</option>
                            <option value="performance">مشکل سرعت</option>
                            <option value="content">محتوای اشتباه</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold" class="fs-13">توضیحات مشکل <span class="text-danger">*</span></label>
                        <textarea id="bug_description" class="form-control" rows="4" placeholder="لطفاً مشکل را با جزئیات توضیح دهید..." maxlength="2000" required></textarea>
                        <div class="d-flex justify-content-between mt-1">
                            <small class="text-muted">حداقل ۱۰ کاراکتر</small>
                            <small class="text-muted"><span id="bug_char_count">0</span>/2000</small>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold" class="fs-13">اسکرین‌شات (اختیاری)</label>
                        <input type="file" id="bug_screenshot" class="form-control form-control-sm" accept="image/jpeg,image/png,image/gif,image/webp">
                        <small class="text-muted">حداکثر ۳ مگابایت - فقط تصویر</small>
                    </div>

                    <div class="alert alert-info py-2 mb-3" class="fs-12">
                        <span class="material-icons me-1" class="icon-sm align-middle">info</span>
                        گزارش‌های شما به تیم فنی ارسال می‌شود. از ارسال گزارش‌های بی‌مورد خودداری کنید.
                        <br>محدودیت: حداکثر ۲ گزارش در روز.
                    </div>

                    <div id="bug_error" class="alert alert-danger py-2 mb-3"></div>
                </form>
            </div>
            <div class="modal-footer" id="bug-report-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">انصراف</button>
                <button type="button" class="btn btn-sm btn-warning" id="submitBugReport" >
                    <span class="material-icons me-1" class="icon-sm align-middle">send</span>
                    ارسال گزارش
                </button>
            </div>
        </div>
    </div>
</div>


<?php endif; ?>