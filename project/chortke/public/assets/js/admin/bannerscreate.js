/* admin/banners-create.js — منطق فرم ساخت/ویرایش بنر */
(function () {
    'use strict';
    window.toggleCategory = function (type) {
        var el = document.getElementById('categoryGroup');
        if (el) {
            el.style.display = (type === 'startup' || type === 'user') ? 'block' : 'none';
        }
    };
})();
