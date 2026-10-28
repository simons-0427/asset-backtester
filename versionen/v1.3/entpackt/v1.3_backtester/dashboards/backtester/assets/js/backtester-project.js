const toggle = document.querySelector('.nav-toggle');
const menu = document.getElementById('mobile-menu');

if (toggle && menu) {
  const closeMenu = () => {
    toggle.classList.remove('open');
    menu.classList.remove('open');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-label', 'Menü öffnen');
  };

  toggle.addEventListener('click', () => {
    const open = toggle.classList.toggle('open');
    menu.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Menü schließen' : 'Menü öffnen');
  });

  menu.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeMenu));
}

