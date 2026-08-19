/**
 * Shared Theme Toggle
 * Handles both admin and user panel dark/light themes.
 */
(function () {
    'use strict';

    function setIcon(isDark) {
        const icon = document.getElementById('themeIcon');
        if (icon) {
            icon.textContent = isDark ? 'light_mode' : 'dark_mode';
        }
    }

    function applyUserTheme() {
        const stored = localStorage.getItem('panel_theme') || 'light';
        const html = document.documentElement;
        html.setAttribute('data-theme', stored);
        setIcon(stored === 'dark');
    }

    function toggleUserTheme() {
        const html = document.documentElement;
        const isDark = html.getAttribute('data-theme') === 'dark';
        const next = isDark ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        localStorage.setItem('panel_theme', next);
        setIcon(next === 'dark');
    }

    function applyAdminTheme() {
        const saved = localStorage.getItem('adminTheme');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const html = document.documentElement;

        if (saved === 'light' || (saved === null && !prefersDark)) {
            html.classList.add('light');
            setIcon(false);
        } else {
            html.classList.remove('light');
            setIcon(true);
        }
    }

    function toggleAdminTheme() {
        const html = document.documentElement;
        const icon = document.getElementById('themeIcon');
        const isLight = html.classList.contains('light');

        if (isLight) {
            html.classList.remove('light');
            localStorage.setItem('adminTheme', 'dark');
            if (icon) icon.textContent = 'light_mode';
        } else {
            html.classList.add('light');
            localStorage.setItem('adminTheme', 'light');
            if (icon) icon.textContent = 'dark_mode';
        }
    }

    window.togglePanelTheme = toggleUserTheme;
    window.adminToggleTheme = toggleAdminTheme;

    function attachThemeButton() {
        const btn = document.getElementById('themeToggleBtn');
        if (!btn) return;

        // user panel uses data-theme="light/dark" on <html>
        if (document.documentElement.hasAttribute('data-theme')) {
            btn.addEventListener('click', toggleUserTheme);
        } else {
            btn.addEventListener('click', toggleAdminTheme);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        // User panel uses data-theme="light/dark" on <html>.
        // Admin panel uses the legacy html.light class.
        // Do not run both systems on the same page; otherwise navbar/components
        // can receive mixed theme states from two different localStorage keys.
        if (document.documentElement.hasAttribute('data-theme')) {
            applyUserTheme();
        } else {
            applyAdminTheme();
        }
        attachThemeButton();
    });
})();
