/**
 * Shared Flash Messages
 * Reads flash messages from a JSON bootstrap tag and shows them via Notyf.
 *
 * Layout usage:
 *   <script type="application/json" id="flash-messages">
 *     {"success":"...","error":"...","warning":"..."}
 *   </script>
 *   <script src="assets/js/shared/flash.js"></script>
 */
(function () {
    'use strict';

    function show() {
        if (typeof Notyf === 'undefined') return;

        const notyf = new Notyf({
            duration: 5000,
            position: { x: 'left', y: 'top' },
            dismissible: true
        });

        const el = document.getElementById('flash-messages');
        if (!el) return;

        try {
            const data = JSON.parse(el.textContent);

            if (data.success) {
                notyf.success(String(data.success));
            }
            if (data.error) {
                notyf.error(String(data.error));
            }
            if (data.warning) {
                notyf.open({ type: 'warning', message: String(data.warning) });
            }
        } catch (e) {
            // Ignore invalid JSON
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', show);
    } else {
        show();
    }
})();
