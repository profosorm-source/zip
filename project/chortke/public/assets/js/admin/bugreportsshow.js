/* admin/bug-reports-show.js — استخراج‌شده از views/admin/bug-reports/show.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('bug-reports-show-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

document.addEventListener('DOMContentLoaded', function() {
    var reportId = __D[0];
    var csrfToken = __D[1];
    var baseUrl = __D[2];
window.apiCall = function(endpoint, body, onSuccess) {
        fetch(baseUrl + reportId + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
            body: JSON.stringify(body)
        }).then(function(r) { return r.json(); }).then(function(data) {
            if (data.success) {
                if (typeof notyf !== 'undefined') notyf.success(data.message);
                if (onSuccess) onSuccess(data); else location.reload();
            } else {
                if (typeof notyf !== 'undefined') notyf.error(data.message || 'خطا');
            }
        });
    }

    document.getElementById('applyStatus').addEventListener('click', function() {
        apiCall('/status', {
            status: document.getElementById('changeStatus').value,
            note: document.getElementById('statusNote').value
        });
    });

    document.getElementById('applyPriority').addEventListener('click', function() {
        apiCall('/priority', { priority: document.getElementById('changePriority').value });
    });

    document.getElementById('sendAdminComment').addEventListener('click', function() {
        var comment = document.getElementById('adminComment').value.trim();
        if (!comment) return;
        apiCall('/comment', {
            comment: comment,
            is_internal: document.getElementById('isInternal').checked
        });
    });

    document.getElementById('toggleSuspicious').addEventListener('click', function() {
        apiCall('/suspicious', {});
    });

    document.getElementById('deleteReport').addEventListener('click', function() {
        Swal.fire({
            title: 'حذف گزارش', text: 'آیا مطمئنید؟', icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#f44336',
            cancelButtonText: 'انصراف', confirmButtonText: 'حذف'
        }).then(function(result) {
            if (result.isConfirmed) {
                apiCall('/delete', {}, function() {
                    window.location = __D[3];
                });
            }
        });
    });
});

})();
