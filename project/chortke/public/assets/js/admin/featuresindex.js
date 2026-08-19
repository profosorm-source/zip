/* admin/features-index.js — استخراج‌شده از views/admin/features/index.php (CSP-safe) */
(function(){
'use strict';
var __D=(function(){var el=document.getElementById('features-index-data');try{return JSON.parse(el.textContent);}catch(e){return [];}})();

class TagInput {
    constructor(containerId, inputId) {
        this.container = document.getElementById(containerId);
        this.input = document.getElementById(inputId);
        this.tags = [];
        
        this.input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && this.input.value.trim()) {
                e.preventDefault();
                this.addTag(this.input.value.trim());
                this.input.value = '';
            }
        });
    }
    
    addTag(value) {
        if (!this.tags.includes(value)) {
            this.tags.push(value);
            this.render();
            updatePreview();
        }
    }
    
    removeTag(value) {
        this.tags = this.tags.filter(t => t !== value);
        this.render();
        updatePreview();
    }
    
    setTags(tags) {
        this.tags = tags || [];
        this.render();
    }
    
    getTags() {
        return this.tags;
    }
    
    render() {
        const existingTags = this.container.querySelectorAll('.tag');
        existingTags.forEach(tag => tag.remove());
        
        this.tags.forEach(tag => {
            const tagEl = document.createElement('div');
            tagEl.className = 'tag';
            tagEl.innerHTML = `
                ${tag}
                <span class="remove" data-click="removeTagFromInput" data-args="${this.container.id}|${tag}">&times;</span>
            `;
            this.container.insertBefore(tagEl, this.input);
        });
    }
}

// Initialize all tag inputs
const rolesTagInput = new TagInput('rolesTagInput', 'roleInput');
const usersTagInput = new TagInput('usersTagInput', 'userInput');
const countriesTagInput = new TagInput('countriesTagInput', 'countryInput');
const devicesTagInput = new TagInput('devicesTagInput', 'deviceInput');
const routesTagInput = new TagInput('routesTagInput', 'routeInput');
const environmentsTagInput = new TagInput('environmentsTagInput', 'environmentInput');

window.removeTagFromInput = function(containerId, value) {
    const inputs = {
        'rolesTagInput': rolesTagInput,
        'usersTagInput': usersTagInput,
        'countriesTagInput': countriesTagInput,
        'devicesTagInput': devicesTagInput,
        'routesTagInput': routesTagInput,
        'environmentsTagInput': environmentsTagInput
    };
    
    if (inputs[containerId]) {
        inputs[containerId].removeTag(value);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Link percentage slider and input
    const slider = document.getElementById('editPercentage');
    const numberInput = document.getElementById('editPercentageValue');
    
    slider?.addEventListener('input', () => {
        numberInput.value = slider.value;
        updatePreview();
    });
    
    numberInput?.addEventListener('input', () => {
        slider.value = numberInput.value;
        updatePreview();
    });
    
    // Initialize filters
    const searchInput = document.getElementById('searchFeature');
    const statusFilter = document.getElementById('filterStatus');
    const typeFilter = document.getElementById('filterType');
    
    window.filterFeatures = function() {
        const searchTerm = searchInput.value.toLowerCase();
        const status = statusFilter.value;
        const type = typeFilter.value;
        
        document.querySelectorAll('.feature-card').forEach(card => {
            const name = card.dataset.name.toLowerCase();
            const cardStatus = card.dataset.status;
            const targeting = card.dataset.targeting || '';
            const percentage = parseInt(card.dataset.percentage);
            
            let show = true;
            
            if (searchTerm && !name.includes(searchTerm)) {
                show = false;
            }
            
            if (status && cardStatus !== status) {
                show = false;
            }
            
            if (type && !targeting.includes(type)) {
                show = false;
            }
            
            card.style.display = show ? 'block' : 'none';
        });
    }
    
    searchInput?.addEventListener('input', filterFeatures);
    statusFilter?.addEventListener('change', filterFeatures);
    typeFilter?.addEventListener('change', filterFeatures);
});

window.updatePreview = function() {
    const percentage = document.getElementById('editPercentageValue')?.value || 100;
    const roles = rolesTagInput.getTags();
    const users = usersTagInput.getTags();
    const countries = countriesTagInput.getTags();
    const devices = devicesTagInput.getTags();
    const routes = routesTagInput.getTags();
    const minAge = document.getElementById('editMinAge')?.value;
    const maxAge = document.getElementById('editMaxAge')?.value;
    const enabledFrom = document.getElementById('editEnabledFrom')?.value;
    const enabledUntil = document.getElementById('editEnabledUntil')?.value;
    const environments = environmentsTagInput.getTags();
    
    let preview = '<div class="d-flex flex-wrap gap-1">';
    
    if (percentage < 100) {
        preview += `<span class="badge badge-info">A/B: ${percentage}%</span>`;
    }
    
    if (roles.length > 0) {
        preview += `<span class="badge badge-primary">${roles.length} نقش</span>`;
    }
    
    if (users.length > 0) {
        preview += `<span class="badge badge-warning">${users.length} کاربر</span>`;
    }
    
    if (countries.length > 0) {
        preview += `<span class="badge badge-success">${countries.length} کشور</span>`;
    }
    
    if (devices.length > 0) {
        preview += `<span class="badge badge-secondary">${devices.length} دستگاه</span>`;
    }
    
    if (routes.length > 0) {
        preview += `<span class="badge badge-dark">${routes.length} مسیر</span>`;
    }
    
    if (minAge || maxAge) {
        preview += `<span class="badge badge-info">سن: ${minAge || 0}-${maxAge || '∞'}</span>`;
    }
    
    if (enabledFrom || enabledUntil) {
        preview += `<span class="badge badge-warning">زمان‌بندی</span>`;
    }
    
    if (environments.length > 0) {
        preview += `<span class="badge badge-light text-dark">${environments.join(', ')}</span>`;
    }
    
    if (preview === '<div class="d-flex flex-wrap gap-1">') {
        preview += '<small class="text-muted">هیچ محدودیتی اعمال نشده (عمومی)</small>';
    }
    
    preview += '</div>';
    
    document.getElementById('settingsPreview').innerHTML = preview;
}

window.showEditModal = function(feature) {
    document.getElementById('modalFeatureName').textContent = feature.name;
    document.getElementById('editFeatureName').value = feature.name;
    document.getElementById('editDescription').value = feature.description || '';
    
    const percentage = feature.enabled_percentage || 100;
    document.getElementById('editPercentage').value = percentage;
    document.getElementById('editPercentageValue').value = percentage;
    
    // Set advanced fields
    document.getElementById('editMinAge').value = feature.min_age || '';
    document.getElementById('editMaxAge').value = feature.max_age || '';
    document.getElementById('editEnabledFrom').value = feature.enabled_from ? 
        new Date(feature.enabled_from).toISOString().slice(0, 16) : '';
    document.getElementById('editEnabledUntil').value = feature.enabled_until ? 
        new Date(feature.enabled_until).toISOString().slice(0, 16) : '';
    document.getElementById('editPriority').value = feature.priority || 0;
    
    // Set tags
    const roles = feature.enabled_for_roles ? JSON.parse(feature.enabled_for_roles) : [];
    rolesTagInput.setTags(roles);
    
    const users = feature.enabled_for_users ? JSON.parse(feature.enabled_for_users) : [];
    usersTagInput.setTags(users.map(String));
    
    const countries = feature.enabled_for_countries ? JSON.parse(feature.enabled_for_countries) : [];
    countriesTagInput.setTags(countries);
    
    const devices = feature.enabled_for_devices ? JSON.parse(feature.enabled_for_devices) : [];
    devicesTagInput.setTags(devices);
    
    const routes = feature.enabled_for_routes ? JSON.parse(feature.enabled_for_routes) : [];
    routesTagInput.setTags(routes);
    
    const environments = feature.environments ? JSON.parse(feature.environments) : [];
    environmentsTagInput.setTags(environments);
    
    updatePreview();
    $('#editFeatureModal').modal('show');
}

window.saveFeature = function() {
    const name = document.getElementById('editFeatureName').value;
    const description = document.getElementById('editDescription').value;
    const percentage = parseInt(document.getElementById('editPercentageValue').value);
    const minAge = document.getElementById('editMinAge').value ? parseInt(document.getElementById('editMinAge').value) : null;
    const maxAge = document.getElementById('editMaxAge').value ? parseInt(document.getElementById('editMaxAge').value) : null;
    const enabledFrom = document.getElementById('editEnabledFrom').value;
    const enabledUntil = document.getElementById('editEnabledUntil').value;
    const priority = parseInt(document.getElementById('editPriority').value) || 0;
    
    const data = {
        name: name,
        description: description,
        enabled_percentage: percentage,
        enabled_for_roles: rolesTagInput.getTags(),
        enabled_for_users: usersTagInput.getTags().map(id => parseInt(id)),
        enabled_for_countries: countriesTagInput.getTags(),
        enabled_for_devices: devicesTagInput.getTags(),
        enabled_for_routes: routesTagInput.getTags(),
        min_age: minAge,
        max_age: maxAge,
        enabled_from: enabledFrom,
        enabled_until: enabledUntil,
        priority: priority,
        environments: environmentsTagInput.getTags()
    };
    
    fetch(__D[0], {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': __D[1]
        },
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            notyf.success('تنظیمات پیشرفته با موفقیت ذخیره شد');
            $('#editFeatureModal').modal('hide');
            setTimeout(() => location.reload(), 1000);
        } else {
            notyf.error(data.message || 'خطا در ذخیره تنظیمات');
        }
    })
    .catch(err => {
        notyf.error('خطا در ارتباط با سرور');
        console.error(err);
    });
}

window.showMetrics = function(featureName) {
    document.getElementById('metricsFeatureName').textContent = featureName;
    
    fetch(`${__D[2]}/${encodeURIComponent(featureName)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.getElementById('metricsContent').innerHTML = generateMetricsHTML(data.metrics);
        } else {
            document.getElementById('metricsContent').innerHTML = 
                '<div class="alert alert-danger">خطا در دریافت آمار</div>';
        }
    })
    .catch(err => {
        document.getElementById('metricsContent').innerHTML = 
            '<div class="alert alert-danger">خطا در ارتباط با سرور</div>';
        console.error(err);
    });
    
    $('#metricsModal').modal('show');
}

window.showHistory = function(featureName) {
    document.getElementById('historyFeatureName').textContent = featureName;
    
    fetch(`${__D[3]}/${encodeURIComponent(featureName)}`)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            document.getElementById('historyContent').innerHTML = generateHistoryHTML(data.history);
        } else {
            document.getElementById('historyContent').innerHTML = 
                '<div class="alert alert-danger">خطا در دریافت تاریخچه</div>';
        }
    })
    .catch(err => {
        document.getElementById('historyContent').innerHTML = 
            '<div class="alert alert-danger">خطا در ارتباط با سرور</div>';
        console.error(err);
    });
    
    $('#historyModal').modal('show');
}

window.generateMetricsHTML = function(metrics) {
    let html = `
        <div class="row">
            <div class="col-md-3">
                <div class="card text-center border-primary">
                    <div class="card-body">
                        <h3 class="text-primary">${metrics.total_checks || 0}</h3>
                        <p class="text-muted mb-0">کل بررسی‌ها</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-success">
                    <div class="card-body">
                        <h3 class="text-success">${metrics.enabled_count || 0}</h3>
                        <p class="text-muted mb-0">فعال</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-danger">
                    <div class="card-body">
                        <h3 class="text-danger">${metrics.disabled_count || 0}</h3>
                        <p class="text-muted mb-0">غیرفعال</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center border-info">
                    <div class="card-body">
                        <h3 class="text-info">${metrics.success_rate || 0}%</h3>
                        <p class="text-muted mb-0">نرخ موفقیت</p>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    if (metrics.avg_response_time > 0) {
        html += `
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card text-center border-warning">
                        <div class="card-body">
                            <h5 class="text-warning">${Math.round(metrics.avg_response_time)}ms</h5>
                            <p class="text-muted mb-0">میانگین زمان پاسخ</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card text-center border-secondary">
                        <div class="card-body">
                            <h5 class="text-secondary">${Math.round(metrics.max_response_time)}ms</h5>
                            <p class="text-muted mb-0">حداکثر زمان پاسخ</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    
    if (metrics.reasons && metrics.reasons.length > 0) {
        html += `
            <div class="mt-4">
                <h6><i class="material-icons">pie_chart</i> دلایل بررسی</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>دلیل</th>
                                <th>تعداد</th>
                                <th>درصد</th>
                            </tr>
                        </thead>
                        <tbody>
        `;
        
        metrics.reasons.forEach(reason => {
            html += `
                <tr>
                    <td>${reason.reason || 'نامشخص'}</td>
                    <td>${reason.count || 0}</td>
                    <td>${reason.percentage || 0}%</td>
                </tr>
            `;
        });
        
        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    }
    
    return html;
}

window.generateHistoryHTML = function(history) {
    if (!history || history.length === 0) {
        return '<div class="alert alert-info"><i class="material-icons">info</i> هیچ تاریخچه‌ای یافت نشد</div>';
    }
    
    let html = '<div class="timeline">';
    history.forEach(item => {
        const date = new Date(item.changed_at || item.created_at);
        const formattedDate = date.toLocaleDateString('fa-IR') + ' ' + date.toLocaleTimeString('fa-IR');
        const actionIcon = getActionIcon(item.change_type || item.action || 'updated');
        
        html += `
            <div class="timeline-item">
                <div class="timeline-marker bg-primary"></div>
                <div class="timeline-content">
                    <div class="d-flex justify-content-between align-items-start">
                        <h6 class="mb-1">
                            <i class="material-icons" style="font-size: 16px;">${actionIcon}</i>
                            ${item.change_type || item.action || 'به‌روزرسانی'}
                        </h6>
                        <small class="text-muted">${formattedDate}</small>
                    </div>
                    <p class="mb-1">${item.details || item.description || 'بدون جزئیات'}</p>
                    ${item.changed_by ? `<small class="text-info">توسط: ${item.changed_by}</small>` : ''}
                    ${item.old_values || item.new_values ? `
                        <div class="mt-2">
                            <small class="text-muted">تغییرات:</small>
                            <div class="diff-view mt-1">
                                ${generateDiffHTML(item.old_values, item.new_values)}
                            </div>
                        </div>
                    ` : ''}
                </div>
            </div>
        `;
    });
    html += '</div>';
    return html;
}

window.generateDiffHTML = function(oldValues, newValues) {
    if (!oldValues && !newValues) return '';
    
    let html = '';
    
    if (typeof oldValues === 'string') {
        try {
            oldValues = JSON.parse(oldValues);
        } catch (e) {
            oldValues = null;
        }
    }
    
    if (typeof newValues === 'string') {
        try {
            newValues = JSON.parse(newValues);
        } catch (e) {
            newValues = null;
        }
    }
    
    if (oldValues && typeof oldValues === 'object') {
        Object.keys(oldValues).forEach(key => {
            const oldVal = oldValues[key];
            const newVal = newValues && newValues[key] !== undefined ? newValues[key] : null;
            
            if (oldVal !== newVal) {
                html += `<div class="diff-line">
                    <code>${key}</code>: 
                    <span class="text-danger">${JSON.stringify(oldVal)}</span> → 
                    <span class="text-success">${JSON.stringify(newVal)}</span>
                </div>`;
            }
        });
    }
    
    return html || '<small class="text-muted">تغییرات جزئی</small>';
}

window.getActionIcon = function(action) {
    const icons = {
        'created': 'add_circle',
        'updated': 'edit',
        'enabled': 'toggle_on',
        'disabled': 'toggle_off',
        'deleted': 'delete'
    };
    return icons[action.toLowerCase()] || 'info';
}

window.toggleFeature = function(name) {
    Swal.fire({
        title: 'تغییر وضعیت فیچر',
        text: `آیا مطمئن هستید که می‌خواهید وضعیت "${name}" را تغییر دهید؟`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'بله، تغییر بده',
        cancelButtonText: 'لغو',
        customClass: {
            confirmButton: 'btn btn-primary',
            cancelButton: 'btn btn-secondary'
        }
    }).then((result) => {
        if (result.isConfirmed) {
            fetch(__D[4], {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': __D[5]
                },
                body: JSON.stringify({ name: name })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    notyf.success(data.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    notyf.error(data.message);
                }
            });
        }
    });
}

window.editFeature = function(name) {
    // Find the feature data from the current features
    const features = __D[6];
    const feature = features.find(f => f.name === name);
    
    if (feature) {
        showEditModal(feature);
    } else {
        notyf.error('فیچر مورد نظر یافت نشد');
    }
}

})();
