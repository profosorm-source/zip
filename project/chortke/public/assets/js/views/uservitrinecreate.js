// شمارش کاراکتر
const titleInput = document.querySelector('[name="title"]');
const descInput  = document.getElementById('descTextarea');
const titleCount = document.getElementById('titleCount');
const descCount  = document.getElementById('descCount');

if (titleInput) titleInput.addEventListener('input', () => { titleCount.textContent = titleInput.value.length; });
if (descInput)  descInput.addEventListener('input',  () => { descCount.textContent  = descInput.value.length; });

// نمایش/پنهان کردن فیلدهای وابسته به دسته
const categorySelect = document.getElementById('categorySelect');
const platformWrap   = document.getElementById('platformWrap');
const statsCard      = document.getElementById('statsCard');
const usernameWrap   = document.getElementById('usernameWrap');
const memberWrap     = document.getElementById('memberWrap');

const platformCategories = ['page', 'channel', 'group'];
const socialCategories   = ['page', 'channel', 'group'];

function updateFields() {
  const cat = categorySelect.value;
  const isDigital  = ['vps', 'vpn', 'website'].includes(cat);
  const isSocial   = socialCategories.includes(cat);

  platformWrap.style.display  = isDigital ? 'none' : '';
  usernameWrap.style.display  = isDigital ? 'none' : '';
  memberWrap.style.display    = isSocial ? '' : 'none';
}

if (categorySelect) {
  categorySelect.addEventListener('change', updateFields);
  updateFields();
}

// جلوگیری از ارسال دوباره
document.getElementById('vitrineForm')?.addEventListener('submit', function() {
  const btn = document.getElementById('submitBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> در حال ارسال...'; }
});