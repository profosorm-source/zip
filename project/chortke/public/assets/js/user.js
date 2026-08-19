(function(){
  'use strict';

  // ── Theme (Dark/Light) ──
  function togglePanelTheme(){
    var html = document.documentElement;
    var dark = html.getAttribute('data-theme') === 'dark';
    var nt = dark ? 'light' : 'dark';
    html.setAttribute('data-theme', nt);
    localStorage.setItem('panel_theme', nt);
    var ic = document.getElementById('themeIcon');
    if(ic) ic.textContent = nt === 'dark' ? 'light_mode' : 'dark_mode';
  }
  window.togglePanelTheme = togglePanelTheme; // Export globally

  // Immediate Theme application
  var t = localStorage.getItem('panel_theme') || 'light';
  document.documentElement.setAttribute('data-theme', t);
  var ic = document.getElementById('themeIcon');
  if(ic) ic.textContent = t === 'dark' ? 'light_mode' : 'dark_mode';

  document.addEventListener('DOMContentLoaded', function(){

    function closeAll(){
      document.querySelectorAll('[data-u-dd-menu].is-open').forEach(function(menu){
        menu.classList.remove('is-open');
        menu.style.left = '';
        menu.style.right = '';
      });

      document.querySelectorAll('[data-u-dd-toggle]').forEach(function(btn){
        btn.setAttribute('aria-expanded', 'false');
      });
    }

    function clampMenu(menu){
      // reset to default
      menu.style.right = '0px';
      menu.style.left = 'auto';

      var pad = 12;
      var rect = menu.getBoundingClientRect();

      // اگر از چپ بیرون زد
      if (rect.left < pad) {
        menu.style.left = pad + 'px';
        menu.style.right = 'auto';
      }

      // اگر از راست بیرون زد
      rect = menu.getBoundingClientRect();
      if (rect.right > (window.innerWidth - pad)) {
        menu.style.right = pad + 'px';
        menu.style.left = 'auto';
      }
    }

    document.addEventListener('click', function(e){
      var btn = e.target.closest('[data-u-dd-toggle]');
      var insideMenu = e.target.closest('[data-u-dd-menu]');

      // کلیک داخل منو => بسته نشود
      if (insideMenu && !btn) return;

      // کلیک روی دکمه
      if (btn) {
        e.preventDefault();

        var key = btn.getAttribute('data-u-dd-toggle');
        var menu = document.querySelector('[data-u-dd-menu="' + key + '"]');
        if (!menu) return;

        var wasOpen = menu.classList.contains('is-open');
        closeAll();

        if (!wasOpen) {
          menu.classList.add('is-open');
          btn.setAttribute('aria-expanded', 'true');
          clampMenu(menu);
        }
        return;
      }

      // کلیک بیرون => بستن همه
      closeAll();
    });

    // ESC
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') closeAll();
    });

    // Resize => clamp مجدد اگر باز بود
    window.addEventListener('resize', function(){
      var openMenu = document.querySelector('[data-u-dd-menu].is-open');
      if (openMenu) clampMenu(openMenu);
    });

    // ── Sidebar mobile toggle ──
    const sidebarToggle  = document.getElementById('sidebarToggle');
    const mainSidebar    = document.getElementById('mainSidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    if (sidebarToggle && mainSidebar) {
      sidebarToggle.addEventListener('click', function(e){
        e.stopPropagation();
        mainSidebar.classList.toggle('is-open');
        if(sidebarOverlay) sidebarOverlay.classList.toggle('is-open');
      });
    }
    if (sidebarOverlay) {
      sidebarOverlay.addEventListener('click', function(){
        mainSidebar.classList.remove('is-open');
        sidebarOverlay.classList.remove('is-open');
      });
    }

    // ── Submenu accordion ──
    document.querySelectorAll('[data-submenu-toggle]').forEach(function(btn){
      btn.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        var li = this.closest('li.has-submenu');
        if (!li) return;
        // close others
        document.querySelectorAll('li.has-submenu.open').forEach(function(other){
          if(other !== li) other.classList.remove('open');
        });
        li.classList.toggle('open');
      });
    });

    // ── Dropdown menus (notifications, settings) ──
    document.querySelectorAll('[data-dd-toggle]').forEach(function(trigger){
      trigger.addEventListener('click', function(e){
        e.stopPropagation();
        var key  = this.dataset.ddToggle;
        var menu = document.querySelector('[data-dd-menu="' + key + '"]');
        if (!menu) return;
        var rect = this.getBoundingClientRect();
        menu.style.top   = (rect.bottom + 6) + 'px';
        menu.style.right = (window.innerWidth - rect.right) + 'px';
        menu.style.left  = 'auto';
        // close others
        document.querySelectorAll('[data-dd-menu].is-open').forEach(function(m){
          if (m !== menu) m.classList.remove('is-open');
        });
        menu.classList.toggle('is-open');
      });
    });

    // click outside closes dropdowns
    document.addEventListener('click', function(){
      document.querySelectorAll('[data-dd-menu].is-open').forEach(function(m){
        m.classList.remove('is-open');
      });
    });

  });
})();