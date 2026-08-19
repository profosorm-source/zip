document.addEventListener('DOMContentLoaded', function() {
    const stars = document.querySelectorAll('.star');
    const ratingInput = document.getElementById('rating');
    
    // انتخاب ستاره
    stars.forEach(star => {
        star.addEventListener('click', function() {
            const rating = this.dataset.rating;
            ratingInput.value = rating;
            
            // به‌روزرسانی نمایش
            stars.forEach(s => {
                if (s.dataset.rating <= rating) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
            
            document.getElementById('rating-error').textContent = '';
        });
        
        // Hover effect
        star.addEventListener('mouseenter', function() {
            const rating = this.dataset.rating;
            stars.forEach(s => {
                if (s.dataset.rating <= rating) {
                    s.style.color = '#ffc107';
                }
            });
        });
    });
    
    // Reset on mouse leave
    document.querySelector('.star-rating').addEventListener('mouseleave', function() {
        const selectedRating = ratingInput.value;
        stars.forEach(s => {
            if (selectedRating && s.dataset.rating <= selectedRating) {
                s.style.color = '#ffc107';
            } else {
                s.style.color = '#ddd';
            }
        });
    });
    
    // ثبت امتیاز
    document.getElementById('submitRating').addEventListener('click', function() {
        const submissionId = document.getElementById('submission_id').value;
        const rating = document.getElementById('rating').value;
        const reviewText = document.getElementById('review_text').value.trim();
        
        // Validation
        if (!rating) {
            document.getElementById('rating-error').textContent = 'لطفاً امتیاز خود را انتخاب کنید.';
            return;
        }
        
        // ارسال به سرور
        fetch('/user/custom-tasks/rate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                submission_id: submissionId,
                rating: parseInt(rating),
                review_text: reviewText
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                $('#ratingModal').modal('hide');
                showAlert('success', data.message);
                location.reload(); // Reload to show new rating
            } else {
                if (data.errors) {
                    if (data.errors.rating) {
                        document.getElementById('rating-error').textContent = data.errors.rating[0];
                    }
                    if (data.errors.review_text) {
                        document.getElementById('review-error').textContent = data.errors.review_text[0];
                    }
                } else {
                    showAlert('error', data.message);
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showAlert('error', 'خطا در ثبت امتیاز');
        });
    });
});

// تابع باز کردن Modal
function openRatingModal(submissionId) {
    document.getElementById('submission_id').value = submissionId;
    document.getElementById('rating').value = '';
    document.getElementById('review_text').value = '';
    document.getElementById('rating-error').textContent = '';
    document.getElementById('review-error').textContent = '';
    
    // Reset stars
    document.querySelectorAll('.star').forEach(s => {
        s.classList.remove('active');
        s.style.color = '#ddd';
    });
    
    $('#ratingModal').modal('show');
}

function showAlert(type, message) {
    // این تابع بسته به نوع Alert System شما متفاوت خواهد بود
    alert(message);
}