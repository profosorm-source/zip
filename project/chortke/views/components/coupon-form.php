<div class="coupon-section mb-4">
    <div class="card">
        <div class="card-body">
            <h6 class="card-title mb-3">
                <i class="fas fa-tag text-primary"></i> کد تخفیف دارید؟
            </h6>
            
            <div class="row align-items-end" id="couponFormContainer">
                <div class="col-md-8">
                    <input type="text" 
                           class="form-control" 
                           id="couponCode" 
                           placeholder="کد تخفیف را وارد کنید"
                           maxlength="50">
                </div>
                <div class="col-md-4">
                    <button type="button" 
                            class="btn btn-primary w-100" 
                            id="applyCouponBtn">
                        <i class="fas fa-check"></i> اعمال کوپن
                    </button>
                </div>
            </div>

            <div id="couponResult" class="mt-3" class="d-none"></div>
        </div>
    </div>
</div>