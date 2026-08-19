<?php $title = 'ثبت ترید جدید';  ob_start(); ?>
<div id="investmentRoot" data-base="<?= url('/admin/investment') ?>" data-withdrawals-base="<?= url('/admin/investment/withdrawals') ?>" data-apply-url="<?= url('/admin/investment/apply-profit') ?>" data-trades-base="<?= url('/admin/investment/trades') ?>" data-trades-store="<?= url('/admin/investment/trades/store') ?>" data-trades-list="<?= url('/admin/investment/trades') ?>"></div>


<div class="content-header">
    <h4><i class="material-icons">candlestick_chart</i> ثبت ترید جدید</h4>
    <a href="<?= url('/admin/investment/trades') ?>" class="btn btn-outline-secondary btn-sm">
        <i class="material-icons">arrow_back</i> بازگشت
    </a>
</div>

<div class="card">
    <div class="card-header"><h5>اطلاعات معامله</h5></div>
    <div class="card-body">
        <form id="tradeForm">
  <input type="hidden" name="_csrf_token" value="<?= csrf_token() ?>">
            <div class="form-row">
                <div class="form-group col-md-3">
                    <label>جفت ارز</label>
                    <select name="pair" class="form-control">
                        <option value="XAUUSD">طلا (XAUUSD)</option>
                        <option value="EURUSD">EURUSD</option>
                        <option value="GBPUSD">GBPUSD</option>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label>جهت <span class="text-danger">*</span></label>
                    <select name="direction" class="form-control" required>
                        <option value="buy">خرید (Buy)</option>
                        <option value="sell">فروش (Sell)</option>
                    </select>
                </div>
                <div class="form-group col-md-3">
                    <label>قیمت باز <span class="text-danger">*</span></label>
                    <input type="number" name="open_price" class="form-control" dir="ltr" step="0.01" required>
                </div>
                <div class="form-group col-md-3">
                    <label>حجم (Lot)</label>
                    <input type="number" name="lot_size" class="form-control" dir="ltr" step="0.01" value="0.01">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>زمان باز <span class="text-danger">*</span></label>
                    <input type="datetime-local" name="open_time" class="form-control" required>
                </div>
                <div class="form-group col-md-4">
                    <label>Stop Loss</label>
                    <input type="number" name="stop_loss" class="form-control" dir="ltr" step="0.01">
                </div>
                <div class="form-group col-md-4">
                    <label>Take Profit</label>
                    <input type="number" name="take_profit" class="form-control" dir="ltr" step="0.01">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group col-md-4">
                    <label>قیمت بسته (اختیاری)</label>
                    <input type="number" name="close_price" class="form-control" dir="ltr" step="0.01">
                </div>
                <div class="form-group col-md-4">
                    <label>زمان بسته (اختیاری)</label>
                    <input type="datetime-local" name="close_time" class="form-control">
                </div>
                <div class="form-group col-md-2">
                    <label>درصد سود/ضرر</label>
                    <input type="number" name="profit_loss_percent" class="form-control" dir="ltr" step="0.01">
                </div>
                <div class="form-group col-md-2">
                    <label>مبلغ سود/ضرر</label>
                    <input type="number" name="profit_loss_amount" class="form-control" dir="ltr" step="0.01">
                </div>
            </div>
            <div class="form-group">
                <label>توضیحات</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="جزئیات ترید..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="material-icons">save</i> ثبت ترید
            </button>
        </form>
    </div>
</div>



<?php $content = ob_get_clean(); include view_path('layouts.admin'); ?>
