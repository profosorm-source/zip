// فرمت کردن خودکار شماره کارت
document.getElementById('card_number').addEventListener('input', function(e) {
    let value = e.target.value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
    let formattedValue = value.match(/.{1,4}/g)?.join('-') || value;
    e.target.value = formattedValue;
});

// فقط اعداد برای شبا
document.getElementById('sheba').addEventListener('input', function(e) {
    e.target.value = e.target.value.replace(/[^0-9]/gi, '');
});

// اعتبارسنجی فرم
document.getElementById('bankCardForm').addEventListener('submit', function(e) {
    const cardNumber = document.getElementById('card_number').value.replace(/-/g, '');
    
    if (cardNumber.length !== 16) {
        e.preventDefault();
        notyf.error('شماره کارت باید 16 رقم باشد');
        return false;
    }
    
    const sheba = document.getElementById('sheba').value;
    if (sheba && sheba.length !== 24) {
        e.preventDefault();
        notyf.error('شماره شبا باید 24 رقم باشد');
        return false;
    }
});