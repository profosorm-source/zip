/**
 * Shared Guest UI Behaviors
 * Mobile navigation, navbar scroll effect, scroll-to-top button.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        const toggle = document.getElementById('navbarToggle');
        const links = document.getElementById('navbarLinks');
        const icon = document.getElementById('navbarToggleIcon');

        if (toggle && links) {
            toggle.addEventListener('click', function () {
                const isActive = links.classList.toggle('active');
                if (icon) icon.textContent = isActive ? 'close' : 'menu';
            });

            document.addEventListener('click', function (e) {
                if (!toggle.contains(e.target) && !links.contains(e.target)) {
                    links.classList.remove('active');
                    if (icon) icon.textContent = 'menu';
                }
            });
        }

        const navbar = document.getElementById('guestNavbar');
        if (navbar) {
            window.addEventListener('scroll', function () {
                navbar.classList.toggle('scrolled', window.scrollY > 60);
            });
        }

        const scrollBtn = document.getElementById('scrollTopBtn');
        if (scrollBtn) {
            window.addEventListener('scroll', function () {
                scrollBtn.classList.toggle('visible', window.scrollY > 400);
            });

            scrollBtn.addEventListener('click', function () {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }
    });
})();
