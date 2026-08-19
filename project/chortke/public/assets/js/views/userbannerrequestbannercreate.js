function updatePricing(type) {
    const categoryGroup = document.getElementById('categoryGroup');
    const priceAmount = document.getElementById('priceAmount');
    const durationDays = parseInt(document.querySelector('[name="duration_days"]').value);
    
    if (!type) type = document.querySelector('[name="banner_type"]').value;
    
    categoryGroup.style.display = type === 'startup' ? 'block' : 'none';
    
    if (type === 'startup' && durationDays === 7) {
        priceAmount.textContent = 'رایگان 🎉';
        priceAmount.style.color = '#28a745';
    } else if (type === 'startup') {
        const price = (durationDays - 7) * 500;
        priceAmount.textContent = price.toLocaleString('fa-IR') + ' تومان';
        priceAmount.style.color = '#333';
    } else {
        const price = durationDays * 2000;
        priceAmount.textContent = price.toLocaleString('fa-IR') + ' تومان';
        priceAmount.style.color = '#333';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    updatePricing();
});