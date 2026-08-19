$('#password-form').on('submit', function(e) {
    e.preventDefault();
    
    const form = $(this);
    const btn = form.find('button[type="submit"]');
    const btnText = btn.html();
    
    btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> در حال ذخیره...');
    
    $.ajax({
        url: form.attr('action'),
        method: 'POST',
        data: form.serialize(),
        success: function(response) {
            if (response.success) {
                showToast(response.message, 'success');
                form[0].reset();
            } else {
                showToast(response.message, 'danger');
            }
            btn.prop('disabled', false).html(btnText);
        },
        error: function(xhr) {
            const response = xhr.responseJSON;
            
            if (response.errors) {
                for (let field in response.errors) {
                    showToast(response.errors[field][0], 'danger');
                }
            } else {
                showToast(response.message || 'خطایی رخ داد', 'danger');
            }
            
            btn.prop('disabled', false).html(btnText);
        }
    });
});