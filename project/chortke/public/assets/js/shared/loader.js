/**
 * Shared Page Loader
 * Hides the page loader element and removes it from the DOM after transition.
 */
(function () {
    'use strict';

    function hideLoader() {
        const loader = document.getElementById('pageLoader');
        if (!loader) return;

        loader.classList.add('hide');

        setTimeout(function () {
            if (loader && loader.parentNode) {
                loader.parentNode.removeChild(loader);
            }
        }, 500);
    }

    window.hidePageLoader = hideLoader;

    // Immediate attempt to hide
    if (document.readyState === 'complete') {
        hideLoader();
    } else if (document.readyState === 'interactive') {
        hideLoader();
    } else {
        document.addEventListener('DOMContentLoaded', hideLoader);
    }

    // Fallback: Force hide after 5 seconds regardless of events
    setTimeout(hideLoader, 5000);
})();
