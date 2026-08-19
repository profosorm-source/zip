(function () {
  'use strict';

  /* ── Sidebar (mobile) ── */
  const sidebar   = document.querySelector('.sidebar');
  const toggleBtn = document.getElementById('adminSidebarToggle');

  /* inject overlay */
  let overlay = document.querySelector('.admin-sidebar-overlay');
  if (!overlay && sidebar) {
    overlay = document.createElement('div');
    overlay.className = 'admin-sidebar-overlay';
    document.body.appendChild(overlay);
  }

  function openSidebar() {
    if (!sidebar) return;
    sidebar.classList.add('is-open');
    overlay && overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('is-open');
    overlay && overlay.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
  if (overlay)   overlay.addEventListener('click', closeSidebar);

  window.addEventListener('resize', function () {
    if (window.innerWidth > 768) closeSidebar();
  });

  /* ─── Sub-menu toggle (Admin) ─── */
  window.toggleAdminSub = function(el) {
    const sub = el.nextElementSibling;
    if (!sub || !sub.classList.contains('nav-submenu')) return;
    const isOpen = sub.classList.contains('open');
    // Close all subs
    document.querySelectorAll('.nav-submenu.open').forEach(s => {
        s.classList.remove('open');
        s.style.maxHeight = '';
        const btn = s.previousElementSibling;
        if (btn) btn.classList.remove('open');
    });
    // Toggle current
    if (!isOpen) {
        sub.classList.add('open');
        sub.style.maxHeight = sub.scrollHeight + 'px';
        el.classList.add('open');
    }
  };

  /* ── Menu Sections accordion ── */
  document.addEventListener('DOMContentLoaded', function () {
    const sections = document.querySelectorAll('.menu-section');

    sections.forEach(function (section) {
      const title    = section.querySelector('.section-title');
      const hasActive = section.querySelector('.submenu li.active');

      /* auto-open if there's an active item */
      if (hasActive) {
        section.classList.add('open');
        if (title) title.classList.add('has-active');
      }

      if (title) {
        title.addEventListener('click', function () {
          const isOpen = section.classList.contains('open');

          /* close siblings */
          sections.forEach(function (s) {
            s.classList.remove('open');
          });

          if (!isOpen) section.classList.add('open');
        });
      }
    });

    // ─── Menu search ───
    const searchInput = document.getElementById('sidebarMenuSearch');
    const nav = document.getElementById('sidebarNav');
    if (searchInput && nav) {
        searchInput.addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            const allItems = nav.querySelectorAll('.nav-item, .nav-sub-item');
            const sectionsNav = nav.querySelectorAll('.nav-section');

            if (!q) {
                allItems.forEach(i => i.style.display = '');
                sectionsNav.forEach(s => s.style.display = '');
                nav.querySelectorAll('.nav-submenu').forEach(m => {
                    if (!m.classList.contains('open')) m.style.maxHeight = '';
                });
                return;
            }

            sectionsNav.forEach(section => {
                let hasVisible = false;
                section.querySelectorAll('.nav-item, .nav-sub-item').forEach(item => {
                    const label = item.querySelector('.nav-label, .nav-sub-dot')?.nextSibling?.textContent?.toLowerCase() || item.textContent.toLowerCase();
                    const match = label.includes(q);
                    item.style.display = match ? '' : 'none';
                    if (match) hasVisible = true;
                });
                // Show all submenus when searching
                section.querySelectorAll('.nav-submenu').forEach(m => m.style.maxHeight = hasVisible ? '500px' : '');
                section.style.display = hasVisible ? '' : 'none';
            });
        });
    }

    // Init: open currently active sub-menus
    document.querySelectorAll('.nav-submenu').forEach(sub => {
        if (sub.classList.contains('open')) {
            sub.style.maxHeight = sub.scrollHeight + 'px';
        }
    });

    // Attach data-submenu-toggle for admin sidebar (declarative, no inline onclick)
    document.querySelectorAll('[data-submenu-toggle]').forEach(function (btn) {
      if (btn.classList.contains('has-sub') || btn.closest('.nav-section')) {
        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          toggleAdminSub(this);
        });
      }
    });
  });

})();