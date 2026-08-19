/* admin/tickets-show.js — پاسخ به تیکت + تغییر وضعیت (CSP-safe)
 * مقادیر از #tickets-show-data خوانده می‌شوند:
 *   [0]=reply url, [1]=csrf, [2]=change-status url, [3]=csrf
 */
(function () {
    'use strict';
    var __D = (function () { var el = document.getElementById('tickets-show-data'); try { return JSON.parse(el.textContent); } catch (e) { return []; } })();

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('replyForm');
        if (form) form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(this);
            fetch(__D[0], { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': __D[1] }, body: JSON.stringify({ ticket_id: fd.get('ticket_id'), message: fd.get('message') }) })
                .then(function (r) { return r.json(); }).then(function (data) { if (data.success) { notyf.success(data.message); setTimeout(function () { location.reload(); }, 1000); } else notyf.error(data.message); });
        });
    });

    window.changeStatus = function (id) {
        var status = document.getElementById('statusSelect').value;
        fetch(__D[2], { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': __D[3] }, body: JSON.stringify({ id: id, status: status }) })
            .then(function (r) { return r.json(); }).then(function (data) { if (data.success) { notyf.success(data.message); setTimeout(function () { location.reload(); }, 1000); } else notyf.error(data.message); });
    };
})();
