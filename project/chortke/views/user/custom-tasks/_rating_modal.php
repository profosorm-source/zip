<?php
$title = $title ?? 'امتیازدهی';
ob_start();
?>

<!-- Modal امتیازدهی -->
<div id="ratingModal" class="modal fade" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">امتیازدهی و نظر</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <form id="ratingForm">
  <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
                    <input type="hidden" id="submission_id" name="submission_id">
                    
                    <!-- امتیاز ستاره‌ای -->
                    <div class="form-group">
                        <label>امتیاز شما:</label>
                        <div class="star-rating" dir="ltr">
                            <span class="star" data-rating="5">★</span>
                            <span class="star" data-rating="4">★</span>
                            <span class="star" data-rating="3">★</span>
                            <span class="star" data-rating="2">★</span>
                            <span class="star" data-rating="1">★</span>
                        </div>
                        <input type="hidden" id="rating" name="rating" required>
                        <small class="text-danger" id="rating-error"></small>
                    </div>

                    <!-- متن نظر -->
                    <div class="form-group">
                        <label>نظر شما (اختیاری):</label>
                        <textarea 
                            class="form-control" 
                            id="review_text" 
                            name="review_text" 
                            rows="4" 
                            placeholder="نظر خود را در مورد این تسک بنویسید..."
                            maxlength="1000"
                        ></textarea>
                        <small class="text-muted">حداقل 20 کاراکتر (در صورت تمایل)</small>
                        <small class="text-danger" id="review-error"></small>
                    </div>

                    <div class="alert alert-info">
                        <i class="fa fa-info-circle"></i>
                        نظر شما برای سایر کاربران قابل مشاهده خواهد بود و به بهبود کیفیت کمک می‌کند.
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">بستن</button>
                <button type="button" class="btn btn-primary" id="submitRating">ثبت امتیاز</button>
            </div>
        </div>
    </div>
</div>




<?php
$content = ob_get_clean();
$styles = '<link rel="stylesheet" href="' . asset('assets/css/views/usercustomtasksratingmodal.css') . '">';
$scripts = '<script nonce="<?= e(csp_nonce()) ?>" src="' . asset('assets/js/views/usercustomtasksratingmodal.js') . '"></script>';
include view_path('layouts.user');
?>