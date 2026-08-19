/**
 * admin/inline-actions.js
 * ─────────────────────────────────────────────────────────────
 * جایگزین امن برای onclick/onchange inline که توسط CSP بلاک می‌شوند.
 *
 * نحوهٔ استفاده در ویو (به‌جای onclick="fn(1,'x')"):
 *     data-click="fn"  data-args="1|x"
 * یا برای onchange:
 *     data-change="fn"
 *
 * این فایل با event delegation روی document کار می‌کند و فقط
 * تابعی را اجرا می‌کند که به‌صورت سراسری (window.fn) از قبل در
 * همان صفحه تعریف شده باشد. هیچ eval/Function() استفاده نمی‌شود.
 *
 * آرگومان‌ها از data-args با جداکنندهٔ "|" خوانده می‌شوند و به‌صورت
 * هوشمند به عدد/بولین/null/رشته تبدیل می‌شوند.
 * ─────────────────────────────────────────────────────────────
 */
(function () {
    'use strict';

    function coerce(v) {
        if (v === 'true') return true;
        if (v === 'false') return false;
        if (v === 'null') return null;
        if (v !== '' && !isNaN(v) && /^-?\d+(\.\d+)?$/.test(v)) return Number(v);
        return v;
    }

    function parseArgs(el) {
        var raw = el.getAttribute('data-args');
        if (raw === null || raw === '') return [];
        return raw.split('|').map(coerce);
    }

    function callNamed(name, args, el) {
        if (!name) return;
        var fn = window[name];
        if (typeof fn === 'function') {
            // اگر تابع به المنت نیاز دارد، با data-pass-el می‌توان المنت را هم پاس داد
            if (el.hasAttribute('data-pass-el')) args = args.concat([el]);
            try { fn.apply(window, args); }
            catch (e) { console.error('inline-action error in ' + name + ':', e); }
        } else {
            console.warn('inline-action: function not found ->', name);
        }
    }

    document.addEventListener('click', function (e) {
        // data-href: ناوبری (جایگزین window.location.href='...')
        var nav = e.target.closest('[data-href]');
        if (nav) {
            if (nav.hasAttribute('data-stop')) e.stopPropagation();
            window.location.href = nav.getAttribute('data-href');
            return;
        }

        // data-confirm روی فرم/دکمه (جایگزین onclick="return confirm(...)")
        var conf = e.target.closest('[data-confirm]');
        if (conf) {
            if (!window.confirm(conf.getAttribute('data-confirm'))) {
                e.preventDefault();
                return;
            }
        }

        // data-submit: ارسال یک فرم هدف (جایگزین getElementById('form').submit())
        var sub = e.target.closest('[data-submit]');
        if (sub) {
            var frm = document.querySelector(sub.getAttribute('data-submit'));
            if (frm) frm.submit();
            return;
        }

        // data-toggle-class: افزودن/حذف/تاگل کلاس روی یک عنصر هدف
        // data-toggle-class="show" data-target="#rejectOverlay" data-mode="add|remove|toggle"
        var tog = e.target.closest('[data-toggle-class]');
        if (tog) {
            var target = document.querySelector(tog.getAttribute('data-target'));
            if (target) {
                var cls = tog.getAttribute('data-toggle-class');
                var mode = tog.getAttribute('data-mode') || 'toggle';
                target.classList[mode](cls);
            }
            return;
        }

        // data-set-value: تنظیم مقدار یک input هدف (جایگزین getElementById(..).value='x')
        var sv = e.target.closest('[data-set-value]');
        if (sv) {
            var t2 = document.querySelector(sv.getAttribute('data-target'));
            if (t2) t2.value = sv.getAttribute('data-set-value');
            // اگر data-click هم داشت ادامه بده تا تابع هم اجرا شود
        }

        // data-click: فراخوانی تابع نام‌دار
        var el = e.target.closest('[data-click]');
        if (!el) return;
        if (el.tagName === 'A' && el.getAttribute('href') === '#') e.preventDefault();
        if (el.hasAttribute('data-stop')) e.stopPropagation();
        callNamed(el.getAttribute('data-click'), parseArgs(el), el);
    });

    // data-confirm روی فرم: تأیید پیش از ارسال (جایگزین onsubmit="return confirm(...)")
    document.addEventListener('submit', function (e) {
        var frm = e.target.closest('form[data-confirm]');
        if (frm && !window.confirm(frm.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });

    document.addEventListener('change', function (e) {
        // data-autosubmit: ارسال فرم والد هنگام تغییر (جایگزین onchange="this.form.submit()")
        var auto = e.target.closest('[data-autosubmit]');
        if (auto && auto.form) {
            auto.form.submit();
            return;
        }

        // data-nav-value: ناوبری با مقدار انتخاب‌شده (جایگزین onchange="location.href='?x='+this.value")
        var navv = e.target.closest('[data-nav-value]');
        if (navv) {
            window.location.href = navv.getAttribute('data-nav-value') + encodeURIComponent(navv.value);
            return;
        }

        var el = e.target.closest('[data-change]');
        if (!el) return;
        var args = parseArgs(el);
        // data-pass-value: مقدار المنت را پاس بده (this.value)
        if (el.hasAttribute('data-pass-value')) args = args.concat([el.value]);
        // data-pass-checked: وضعیت چک‌باکس را پاس بده (this.checked)
        else if (el.hasAttribute('data-pass-checked')) args = args.concat([el.checked]);
        else args = args.concat([el]);
        callNamed(el.getAttribute('data-change'), args, el);
    });
})();
