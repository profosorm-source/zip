/* admin/roles-index.js — استخراج‌شده از views/admin/roles/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('roles-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.btn-delete-role').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const id=this.dataset.id, name=this.dataset.name, users=parseInt(this.dataset.users);
      if (users > 0) { Swal.fire({title:'عملیات غیرممکن',text:'این نقش '+users+' کاربر فعال دارد.',icon:'warning',confirmButtonText:'متوجه شدم'}); return; }
      Swal.fire({title:'حذف نقش',text:'آیا از حذف نقش «'+name+'» اطمینان دارید؟',icon:'warning',showCancelButton:true,confirmButtonColor:'#f44336',cancelButtonColor:'#999',confirmButtonText:'بله، حذف شود',cancelButtonText:'انصراف'})
      .then(function(result) {
        if (result.isConfirmed) {
          fetch(__D[0]+id+'/delete',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json','X-CSRF-TOKEN':__D[1]},body:JSON.stringify({csrf_token:__D[2]})})
          .then(r=>r.json()).then(data=>{if(data.success){notyf.success(data.message);var row=document.getElementById('role-row-'+id);if(row)row.remove();}else{notyf.error(data.message);}});
        }
      });
    });
  });
  document.querySelectorAll('.btn-toggle-role').forEach(function(btn) {
    btn.addEventListener('click', function() {
      const id=this.dataset.id, btnEl=this;
      fetch(__D[3]+id+'/toggle',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/json','X-CSRF-TOKEN':__D[4]},body:JSON.stringify({csrf_token:__D[5]})})
      .then(r=>r.json()).then(data=>{
        if(data.success){
          notyf.success(data.message);
          btnEl.dataset.status=data.new_status;
          btnEl.querySelector('i').textContent=data.new_status?'toggle_on':'toggle_off';
          var row=document.getElementById('role-row-'+id);
          if(row){var badge=row.querySelector('td:nth-child(6) span');if(data.new_status){badge.className='bx-badge badge-success';badge.textContent='فعال';}else{badge.className='bx-badge badge-danger';badge.textContent='غیرفعال';}}
        }else notyf.error(data.message);
      });
    });
  });
});

})();
