/**
 * Chortke Professional Abacus Loader — v2
 * نسخهٔ حرفه‌ای لودینگ چرتکه (آباکوس)
 * ─────────────────────────────────────────────────────────────
 * چرتکهٔ سه‌بعدی با قاب چوبی، مهره‌های براق و انیمیشن «شمردن».
 * API سازگار با نسخهٔ قبلی:
 *   ChortkeLoader.show('متن')
 *   ChortkeLoader.hide()
 *   $(...).chortkeLoader('show' | 'hide')
 * ─────────────────────────────────────────────────────────────
 */
(function () {
    'use strict';

    window.ChortkeLoader = {
        _injected: false,

        /**
         * نمایش لودینگ تمام‌صفحه
         * @param {string} text - متن نمایش داده شده (اختیاری)
         */
        show: function (text) {
            text = text || 'در حال بارگذاری...';
            this.hide();
            if (!this._injected) { this.injectStyles(); this._injected = true; }

            var rods = '';
            // ۵ میله؛ هر میله یک مهرهٔ «بالایی» (طلایی) + ۴ مهرهٔ «پایینی» (آبی)
            for (var r = 1; r <= 5; r++) {
                rods +=
                    '<div class="ck-rod" style="--d:' + (r * 0.12) + 's">' +
                        '<div class="ck-rod-line"></div>' +
                        '<div class="ck-bead ck-bead-top"></div>' +
                        '<div class="ck-divider"></div>' +
                        '<div class="ck-bead ck-bead-bot b1"></div>' +
                        '<div class="ck-bead ck-bead-bot b2"></div>' +
                        '<div class="ck-bead ck-bead-bot b3"></div>' +
                        '<div class="ck-bead ck-bead-bot b4"></div>' +
                    '</div>';
            }

            var html =
                '<div id="chortke-loader" class="ck-overlay" role="status" aria-live="polite" aria-label="' + this._escAttr(text) + '">' +
                    '<div class="ck-box">' +
                        '<div class="ck-frame" aria-hidden="true">' +
                            '<div class="ck-frame-top"></div>' +
                            '<div class="ck-rods">' + rods + '</div>' +
                            '<div class="ck-frame-bot"></div>' +
                        '</div>' +
                        '<div class="ck-text">' + this._escHtml(text) + '<span class="ck-dots"><i>.</i><i>.</i><i>.</i></span></div>' +
                        '<div class="ck-progress" aria-hidden="true"><div class="ck-progress-bar"></div></div>' +
                    '</div>' +
                '</div>';

            document.body.insertAdjacentHTML('beforeend', html);
        },

        /**
         * مخفی کردن لودینگ
         */
        hide: function () {
            var el = document.getElementById('chortke-loader');
            if (!el) return;
            el.classList.add('ck-hiding');
            setTimeout(function () {
                if (el && el.parentNode) el.parentNode.removeChild(el);
            }, 350);
        },

        _escHtml: function (v) {
            return String(v)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        },
        _escAttr: function (v) { return this._escHtml(v); },

        /**
         * تزریق استایل‌های لودینگ به <head>
         */
        injectStyles: function () {
            if (document.getElementById('chortke-loader-style')) return;
            var css =
            ':root{--ck-bg:rgba(7,12,24,.78);--ck-frame1:#7c4a1e;--ck-frame2:#5a3415;--ck-rod:#caa472;' +
            '--ck-bead1:#fbbf24;--ck-bead2:#d97706;--ck-bead-bot1:#38bdf8;--ck-bead-bot2:#0369a1;--ck-text:#cbd5e1;--ck-track:#334155;}' +
            '.ck-overlay{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;' +
                'background:var(--ck-bg);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);' +
                'animation:ck-fade .35s ease both;}' +
            '.ck-overlay.ck-hiding{animation:ck-fade .35s ease reverse both;}' +
            '@keyframes ck-fade{from{opacity:0}to{opacity:1}}' +

            '.ck-box{display:flex;flex-direction:column;align-items:center;gap:22px;animation:ck-pop .5s cubic-bezier(.34,1.56,.64,1) both;}' +
            '@keyframes ck-pop{from{transform:scale(.85) translateY(10px);opacity:0}to{transform:none;opacity:1}}' +

            /* قاب چوبی */
            '.ck-frame{position:relative;padding:0 16px;border-radius:18px;' +
                'background:linear-gradient(145deg,var(--ck-frame1),var(--ck-frame2));' +
                'box-shadow:0 18px 45px rgba(0,0,0,.55),inset 0 2px 4px rgba(255,255,255,.12),inset 0 -3px 6px rgba(0,0,0,.4);}' +
            '.ck-frame-top,.ck-frame-bot{height:16px;margin:0 -16px;border-radius:18px;' +
                'background:linear-gradient(180deg,#8a5524,#6b3f1a);box-shadow:inset 0 2px 3px rgba(255,255,255,.18),inset 0 -2px 4px rgba(0,0,0,.4);}' +
            '.ck-rods{display:flex;gap:22px;padding:6px 4px;}' +

            /* میله */
            '.ck-rod{position:relative;width:26px;height:170px;display:flex;flex-direction:column;align-items:center;}' +
            '.ck-rod-line{position:absolute;top:0;bottom:0;left:50%;transform:translateX(-50%);width:4px;' +
                'background:linear-gradient(90deg,#a07a45,var(--ck-rod),#a07a45);border-radius:3px;box-shadow:0 0 2px rgba(0,0,0,.4);}' +
            '.ck-divider{position:absolute;top:46px;left:-6px;right:-6px;height:5px;border-radius:3px;' +
                'background:linear-gradient(180deg,#8a5524,#5a3415);box-shadow:inset 0 1px 2px rgba(255,255,255,.15);z-index:3;}' +

            /* مهره‌ها */
            '.ck-bead{position:absolute;left:50%;width:24px;height:16px;border-radius:50%;z-index:2;' +
                'box-shadow:0 3px 6px rgba(0,0,0,.4),inset 0 2px 3px rgba(255,255,255,.5),inset 0 -3px 4px rgba(0,0,0,.35);}' +
            '.ck-bead::after{content:"";position:absolute;top:3px;left:6px;width:6px;height:3px;border-radius:50%;background:rgba(255,255,255,.6);}' +

            /* مهرهٔ بالایی (طلایی) */
            '.ck-bead-top{background:radial-gradient(circle at 35% 30%,#fde68a,var(--ck-bead1) 45%,var(--ck-bead2));' +
                'animation:ck-top 1.8s var(--d) infinite ease-in-out;}' +
            '@keyframes ck-top{0%,100%{transform:translateX(-50%) translateY(4px)}45%,55%{transform:translateX(-50%) translateY(28px)}}' +

            /* مهره‌های پایینی (آبی) */
            '.ck-bead-bot{background:radial-gradient(circle at 35% 30%,#bae6fd,var(--ck-bead-bot1) 45%,var(--ck-bead-bot2));}' +
            '.ck-bead-bot.b1{bottom:58px;animation:ck-bot 1.8s var(--d) infinite ease-in-out;}' +
            '.ck-bead-bot.b2{bottom:39px;animation:ck-bot 1.8s var(--d) infinite ease-in-out;}' +
            '.ck-bead-bot.b3{bottom:20px;animation:ck-bot 1.8s var(--d) infinite ease-in-out;}' +
            '.ck-bead-bot.b4{bottom:1px;animation:ck-bot 1.8s var(--d) infinite ease-in-out;}' +
            '@keyframes ck-bot{0%,100%{transform:translateX(-50%) translateY(0)}45%,55%{transform:translateX(-50%) translateY(-30px)}}' +

            /* متن + سه‌نقطه */
            '.ck-text{color:var(--ck-text);font-size:15px;font-weight:600;letter-spacing:.5px;font-family:Vazirmatn,Tahoma,sans-serif;}' +
            '.ck-dots i{opacity:.2;animation:ck-dot 1.4s infinite}.ck-dots i:nth-child(2){animation-delay:.2s}.ck-dots i:nth-child(3){animation-delay:.4s}' +
            '@keyframes ck-dot{0%,100%{opacity:.2}50%{opacity:1}}' +

            /* نوار پیشرفت نامعین */
            '.ck-progress{width:190px;height:4px;border-radius:4px;background:var(--ck-track);overflow:hidden;}' +
            '.ck-progress-bar{width:40%;height:100%;border-radius:4px;background:linear-gradient(90deg,#fbbf24,#38bdf8);animation:ck-load 1.4s infinite ease-in-out;}' +
            '@keyframes ck-load{0%{transform:translateX(-120%)}100%{transform:translateX(360%)}}' +

            /* حالت روشن */
            'html.light .ck-overlay{--ck-bg:rgba(241,245,249,.8);--ck-text:#475569;--ck-track:#cbd5e1;}' +

            /* دسترسی‌پذیری: کاهش حرکت */
            '@media (prefers-reduced-motion:reduce){.ck-bead,.ck-progress-bar,.ck-dots i{animation-duration:.001ms!important;animation-iteration-count:1!important}' +
                '.ck-box{animation:none}.ck-overlay{animation:none}.ck-progress-bar{width:100%}}';

            var s = document.createElement('style');
            s.id = 'chortke-loader-style';
            s.textContent = css;
            document.head.appendChild(s);
        }
    };

    // سازگاری با jQuery (در صورت موجود بودن)
    if (typeof window.jQuery !== 'undefined') {
        window.jQuery.fn.chortkeLoader = function (action) {
            if (action === 'show') window.ChortkeLoader.show();
            else if (action === 'hide') window.ChortkeLoader.hide();
            return this;
        };
    }
})();
