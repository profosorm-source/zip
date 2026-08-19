document.querySelectorAll('.btn-start-adtube').forEach(btn => {
  btn.addEventListener('click', function() {
    const id = this.dataset.id;
    this.disabled = true; this.innerHTML = '<span class="material-icons" style="font-size:14px;vertical-align:middle;">hourglass_top</span> درحال پردازش...';
    fetch('/adtube/start', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':document.querySelector('meta[name=csrf-token]')?.content||''},
      body: JSON.stringify({ad_id: id})
    }).then(r=>r.json()).then(d=>{
      if(d.success && d.execution_id) {
        location.href = `/adtube/${d.execution_id}/execute`;
      } else {
        alert(d.message || 'خطا در شروع'); this.disabled = false;
        this.innerHTML = '<span class="material-icons" style="font-size:16px;vertical-align:middle;">play_arrow</span> شروع تماشا';
      }
    });
  });
});