(function(){
'use strict';
const D = window.ADS_SHOW_DATA || {};
const cv = document.getElementById('performanceChart');
if(cv){
  const mkChart = () => {
    const ctx = cv.getContext('2d');
    const mkGrad = (c1,c2)=>{ const g=ctx.createLinearGradient(0,0,0,220); g.addColorStop(0,c1); g.addColorStop(1,c2); return g; };
    const labels=['۶ روز قبل','۵ روز قبل','۴ روز قبل','۳ روز قبل','۲ روز قبل','دیروز','امروز'];
    const imp = D.impressions||0, clk = D.clicks||0;
    const impData = [0,0, Math.round(imp*0.15), Math.round(imp*0.35), Math.round(imp*0.6), Math.round(imp*0.82), imp];
    const clkData = [0,0, Math.round(clk*0.1), Math.round(clk*0.3), Math.round(clk*0.55), Math.round(clk*0.78), clk];
    if(window.Chart){
      new Chart(ctx,{type:'line',data:{labels, datasets:[
        {label:'نمایش',data:impData,borderColor:'#f0b90b',backgroundColor:mkGrad('rgba(240,185,11,.28)','rgba(240,185,11,0)'),tension:.4,fill:true,pointRadius:3,borderWidth:2.5},
        {label:'کلیک',data:clkData,borderColor:'#10b981',backgroundColor:mkGrad('rgba(16,185,129,.18)','rgba(16,185,129,0)'),tension:.4,fill:true,pointRadius:3,borderWidth:2.5}
      ]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.06)'}},x:{grid:{display:false}}}}});
      return;
    }
    // fallback
    const w=cv.width = cv.clientWidth||720, h=cv.height=190;
    ctx.clearRect(0,0,w,h);
    ctx.fillStyle='#f8fafc'; ctx.fillRect(0,0,w,h);
    const drawLine = (vals,color,y0)=>{ ctx.strokeStyle=color; ctx.lineWidth=3; ctx.beginPath(); vals.forEach((v,i)=>{ const x=30+i*((w-50)/6); const y=h-30-(v/Math.max(1,imp,clk))*110; i?ctx.lineTo(x,y):ctx.moveTo(x,y)}); ctx.stroke(); };
    drawLine(impData,'#f0b90b'); drawLine(clkData,'#10b981');
    ctx.fillStyle='#334155'; ctx.font='13px Vazirmatn,system-ui'; ctx.textAlign='center';
    ctx.fillText(`بازدید: ${imp} | کلیک: ${clk} | مصرف: ${Math.round(D.spent||0).toLocaleString('fa-IR')} تومان`, w/2, 22);
  };
  if(document.readyState==='complete') mkChart(); else window.addEventListener('load', mkChart);
  new ResizeObserver(()=>{ if(!window.Chart) mkChart(); }).observe(cv.parentElement);
}

// Exec table filter/search
const q = document.getElementById('execSearch');
const fBtns = document.querySelectorAll('#execFilters button');
const tbody = document.querySelector('#execTable tbody');
let curF='all';
function applyExecFilter(){
  const needle = (q?.value||'').trim().toLowerCase();
  let visible=0;
  tbody?.querySelectorAll('tr[data-status]').forEach(tr=>{
    const status = tr.dataset.status||'';
    const hay = tr.dataset.search||'';
    const matchStatus = curF==='all' || status.includes(curF) || (curF==='submitted' && status==='submitted') || (curF==='approved' && ['approved','completed'].includes(status)) || (curF==='rejected' && ['rejected','fraud'].includes(status)) || (curF==='pending' && ['pending','started','watching'].includes(status));
    const matchQ = !needle || hay.includes(needle);
    const show = matchStatus && matchQ;
    tr.style.display = show ? '' : 'none';
    // hide proof row too
    const proof = tr.nextElementSibling;
    if(proof && proof.classList.contains('proof-row')) proof.hidden = true;
    if(show) visible++;
  });
  const empty = document.getElementById('execEmpty');
  if(empty) empty.hidden = visible !== 0;
}
q?.addEventListener('input', applyExecFilter);
fBtns.forEach(b=>b.addEventListener('click',()=>{fBtns.forEach(x=>x.classList.remove('on')); b.classList.add('on'); curF=b.dataset.f; applyExecFilter();}));

// proof toggle
document.addEventListener('click', e=>{
  const t = e.target.closest('[data-toggle-proof]');
  if(t){ e.preventDefault(); const sel=t.getAttribute('data-toggle-proof'); const row=document.querySelector(sel); if(row) row.hidden = !row.hidden; }
  const ap = e.target.closest('[data-open-approve]');
  if(ap){ const id=ap.dataset.openApprove; document.querySelector('#proof-'+id)?.removeAttribute('hidden'); document.querySelector('#proof-'+id+' input[name="note"]')?.focus(); }
  const rj = e.target.closest('[data-open-reject]');
  if(rj){ const id=rj.dataset.openReject; document.querySelector('#proof-'+id)?.removeAttribute('hidden'); document.querySelector('#proof-'+id+' input[name="reason"]')?.focus(); }
});

// copy ad link
document.getElementById('copyAdLinkBtn')?.addEventListener('click', function(){
  navigator.clipboard?.writeText(this.dataset.url||location.href);
  const old=this.innerHTML; this.innerHTML='<span class="material-icons" style="font-size:18px">check</span>';
  setTimeout(()=>this.innerHTML=old,1200);
});

applyExecFilter();
})();
