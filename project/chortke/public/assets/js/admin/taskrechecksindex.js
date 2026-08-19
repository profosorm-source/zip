/* admin/task-rechecks-index.js — استخراج‌شده از views/admin/task-rechecks/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('task-rechecks-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

document.querySelectorAll('.btn-rc-pass').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        Swal.fire({title:'تایید',text:'کاربر هنوز فالو/سابسکرایب دارد؟',icon:'question',showCancelButton:true,confirmButtonText:'بله',cancelButtonText:'انصراف',confirmButtonColor:'#4caf50'})
        .then(r=>{if(r.isConfirmed){fetch(`${__D[0]}/${id}/pass`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':__D[1]},body:JSON.stringify({_csrf_token:__D[2]})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),800);}else notyf.error(d.message);});}});
    });
});

document.querySelectorAll('.btn-rc-fail').forEach(btn => {
    btn.addEventListener('click', function() {
        const id = this.dataset.id;
        Swal.fire({title:'شکست',text:'کاربر آنفالو کرده؟ جریمه اعمال و پول بازگشت داده می‌شود.',icon:'warning',showCancelButton:true,confirmButtonText:'بله، جریمه',cancelButtonText:'انصراف',confirmButtonColor:'#f44336'})
        .then(r=>{if(r.isConfirmed){fetch(`${__D[3]}/${id}/fail`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':__D[4]},body:JSON.stringify({_csrf_token:__D[5]})}).then(r=>r.json()).then(d=>{if(d.success){notyf.success(d.message);setTimeout(()=>location.reload(),800);}else notyf.error(d.message);});}});
    });
});

})();
