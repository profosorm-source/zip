(function() {
    'use strict';
    
    // DOM Elements
    const form = document.getElementById('contentForm');
    const submitBtn = document.getElementById('submitBtn');
    const agreementCheck = document.getElementById('agreement_accepted');
    const descField = document.getElementById('description');
    const titleField = document.getElementById('title');
    const descCount = document.getElementById('descCount');
    const titleCount = document.getElementById('title-count');
    const platformSelect = document.getElementById('platform');
    const videoUrlField = document.getElementById('video_url');
    const urlHint = document.getElementById('url-hint');
    
    // URL Patterns
    const urlPatterns = {
        'aparat': {
            pattern: /^https?:\/\/(www\.)?aparat\.com\/v\//i,
            hint: 'مثال: https://www.aparat.com/v/abcdef',
            placeholder: 'https://www.aparat.com/v/...'
        },
        'youtube': {
            pattern: /^https?:\/\/(www\.)?(youtube\.com\/watch\?v=|youtu\.be\/)/i,
            hint: 'مثال: https://www.youtube.com/watch?v=abcdef یا https://youtu.be/abcdef',
            placeholder: 'https://www.youtube.com/watch?v=...'
        },
        'upload_center': {
            pattern: /^https?:\/\/.+/i,
            hint: 'لینک دانلود مستقیم فایل ویدیو (از مدیافایر، پیکوفایل، گوگل درایو و غیره)',
            placeholder: 'https://...'
        }
    };
    
    // Enable submit button when agreement is checked
    agreementCheck.addEventListener('change', function() {
        submitBtn.disabled = !this.checked;
        validateField(agreementCheck);
    });
    
    // Character counter for description
    descField.addEventListener('input', function() {
        const length = this.value.length;
        descCount.textContent = length;
        
        if (length > 2000) {
            descCount.classList.add('text-danger');
        } else {
            descCount.classList.remove('text-danger');
        }
    });
    
    // Character counter for title
    titleField.addEventListener('input', function() {
        const length = this.value.length;
        titleCount.textContent = length;
        
        if (length < 5 || length > 255) {
            titleCount.classList.add('text-danger');
        } else {
            titleCount.classList.remove('text-danger');
        }
        
        validateField(titleField);
    });
    
    // Platform change handler
    platformSelect.addEventListener('change', function() {
        const platform = this.value;
        
        if (platform && urlPatterns[platform]) {
            urlHint.textContent = urlPatterns[platform].hint;
            videoUrlField.placeholder = urlPatterns[platform].placeholder;
        } else {
            urlHint.textContent = 'لینک کامل ویدیو را وارد کنید.';
            videoUrlField.placeholder = 'https://...';
        }
        
        validateField(platformSelect);
        
        // Re-validate URL if it has value
        if (videoUrlField.value) {
            validateField(videoUrlField);
        }
    });
    
    // URL validation on blur
    videoUrlField.addEventListener('blur', function() {
        validateField(videoUrlField);
    });
    
    // Real-time validation
    const fields = [platformSelect, videoUrlField, titleField, agreementCheck];
    fields.forEach(field => {
        field.addEventListener('blur', () => validateField(field));
    });
    
    // Field validation
    function validateField(field) {
        const value = field.value.trim();
        let isValid = true;
        let errorMessage = '';
        
        if (field === platformSelect) {
            if (!value) {
                isValid = false;
                errorMessage = 'انتخاب پلتفرم الزامی است.';
            }
        } else if (field === videoUrlField) {
            if (!value) {
                isValid = false;
                errorMessage = 'لینک ویدیو الزامی است.';
            } else {
                const platform = platformSelect.value;
                
                // Basic URL validation
                try {
                    new URL(value);
                } catch {
                    isValid = false;
                    errorMessage = 'فرمت لینک نامعتبر است.';
                }
                
                // Platform-specific validation
                if (isValid && platform && urlPatterns[platform]) {
                    if (!urlPatterns[platform].pattern.test(value)) {
                        isValid = false;
                        errorMessage = `لینک وارد شده مربوط به ${platform === 'aparat' ? 'آپارات' : 'یوتیوب'} نیست.`;
                    }
                }
            }
        } else if (field === titleField) {
            if (!value) {
                isValid = false;
                errorMessage = 'عنوان ویدیو الزامی است.';
            } else if (value.length < 5) {
                isValid = false;
                errorMessage = 'عنوان باید حداقل 5 کاراکتر باشد.';
            } else if (value.length > 255) {
                isValid = false;
                errorMessage = 'عنوان نباید بیشتر از 255 کاراکتر باشد.';
            }
        } else if (field === agreementCheck) {
            if (!field.checked) {
                isValid = false;
                errorMessage = 'پذیرش تعهدنامه الزامی است.';
            }
        }
        
        // Update UI
        const errorDiv = document.getElementById(field.id + '-error');
        
        if (!isValid) {
            field.classList.add('is-invalid');
            field.classList.remove('is-valid');
            if (errorDiv) {
                errorDiv.textContent = errorMessage;
            }
        } else {
            field.classList.remove('is-invalid');
            field.classList.add('is-valid');
            if (errorDiv) {
                errorDiv.textContent = '';
            }
        }
        
        return isValid;
    }
    
    // Form validation
    function validateForm() {
        let isValid = true;
        
        fields.forEach(field => {
            if (!validateField(field)) {
                isValid = false;
            }
        });
        
        return isValid;
    }
    
    // Form submission
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        // Validate form
        if (!validateForm()) {
            if (window.notyf) {
                notyf.error('لطفاً تمام فیلدهای الزامی را به درستی پر کنید.');
            }
            return;
        }
        
        // Disable button and show loading
        submitBtn.disabled = true;
        submitBtn.setAttribute('aria-busy', 'true');
        submitBtn.innerHTML = '<i class="material-icons spin">refresh</i> در حال ارسال...';
        
        // Prepare data
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            data[key] = value;
        });
        
        try {
            const response = await fetch(form.dataset.storeUrl || '/content/store', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': form.dataset.csrf || window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                },
                body: JSON.stringify(data)
            });
            
            const result = await response.json();
            
            if (result.success) {
                if (window.notyf) {
                    notyf.success(result.message || 'محتوا با موفقیت ثبت شد.');
                }
                
                // Redirect after delay
                setTimeout(() => {
                    window.location.href = form.dataset.indexUrl || '/content';
                }, 1500);
            } else {
                if (window.notyf) {
                    notyf.error(result.message || 'خطایی رخ داد.');
                }
                
                // Show field errors if any
                if (result.errors) {
                    Object.keys(result.errors).forEach(fieldName => {
                        const field = document.getElementById(fieldName);
                        const errorDiv = document.getElementById(fieldName + '-error');
                        
                        if (field && errorDiv) {
                            field.classList.add('is-invalid');
                            errorDiv.textContent = result.errors[fieldName][0] || result.errors[fieldName];
                        }
                    });
                }
                
                // Re-enable button
                submitBtn.disabled = !agreementCheck.checked;
                submitBtn.setAttribute('aria-busy', 'false');
                submitBtn.innerHTML = '<i class="material-icons">send</i> ارسال محتوا';
            }
        } catch (error) {
            console.error('Submit error:', error);
            
            if (window.notyf) {
                notyf.error('خطا در ارتباط با سرور. لطفاً دوباره تلاش کنید.');
            }
            
            // Re-enable button
            submitBtn.disabled = !agreementCheck.checked;
            submitBtn.setAttribute('aria-busy', 'false');
            submitBtn.innerHTML = '<i class="material-icons">send</i> ارسال محتوا';
        }
    });
    
    // Reset form
    document.getElementById('resetBtn').addEventListener('click', function() {
        // Clear validation classes
        fields.forEach(field => {
            field.classList.remove('is-valid', 'is-invalid');
        });
        
        // Reset counters
        descCount.textContent = '0';
        titleCount.textContent = '0';
        
        // Disable submit button
        submitBtn.disabled = true;
    });
    
    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        // Focus first field
        platformSelect.focus();
    });
})();