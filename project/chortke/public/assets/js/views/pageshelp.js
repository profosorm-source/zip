// Highlight active help nav item on scroll
document.addEventListener('DOMContentLoaded', function() {
    const sections = document.querySelectorAll('.help-section');
    const navItems = document.querySelectorAll('.help-nav-item');
    
    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                navItems.forEach(item => item.classList.remove('active'));
                const id = entry.target.id;
                const active = document.querySelector(`.help-nav-item[href="#${id}"]`);
                if (active) active.classList.add('active');
            }
        });
    }, { threshold: 0.4 });
    
    sections.forEach(s => observer.observe(s));
});