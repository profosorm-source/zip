/* public/assets/js/admin/roles-edit.js — استخراج‌شده از views/admin/roles/edit.php برای سازگاری با CSP و کش‌پذیری */
(function () {
  'use strict';
document.addEventListener('DOMContentLoaded', function() {
    // انتخاب همه
    document.getElementById('btn-select-all').addEventListener('click', function() {
        document.querySelectorAll('.perm-checkbox').forEach(function(cb) { cb.checked = true; });
        document.querySelectorAll('.group-toggle').forEach(function(cb) { cb.checked = true; cb.indeterminate = false; });
    });
    
    // حذف همه
    document.getElementById('btn-deselect-all').addEventListener('click', function() {
        document.querySelectorAll('.perm-checkbox').forEach(function(cb) { cb.checked = false; });
        document.querySelectorAll('.group-toggle').forEach(function(cb) { cb.checked = false; cb.indeterminate = false; });
    });
    
    // تاگل گروه
    document.querySelectorAll('.group-toggle').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            var group = this.dataset.group;
            var checked = this.checked;
            this.indeterminate = false;
            document.querySelectorAll('.perm-group-' + group).forEach(function(cb) {
                cb.checked = checked;
            });
        });
        
        // وضعیت اولیه indeterminate
        var group = toggle.dataset.group;
        var allInGroup = document.querySelectorAll('.perm-group-' + group);
        var checkedInGroup = document.querySelectorAll('.perm-group-' + group + ':checked');
        if (checkedInGroup.length > 0 && checkedInGroup.length < allInGroup.length) {
            toggle.indeterminate = true;
            toggle.checked = false;
        }
    });
    
    // بروزرسانی تاگل گروه
    document.querySelectorAll('.perm-checkbox').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var classes = this.className.split(' ');
            var groupClass = classes.find(function(c) { return c.startsWith('perm-group-'); });
            if (!groupClass) return;
            var group = groupClass.replace('perm-group-', '');
            var allInGroup = document.querySelectorAll('.perm-group-' + group);
            var checkedInGroup = document.querySelectorAll('.perm-group-' + group + ':checked');
            var groupToggle = document.querySelector('.group-toggle[data-group="' + group + '"]');
            if (groupToggle) {
                if (checkedInGroup.length === 0) {
                    groupToggle.checked = false;
                    groupToggle.indeterminate = false;
                } else if (allInGroup.length === checkedInGroup.length) {
                    groupToggle.checked = true;
                    groupToggle.indeterminate = false;
                } else {
                    groupToggle.checked = false;
                    groupToggle.indeterminate = true;
                }
            }
        });
    });
});
})();
