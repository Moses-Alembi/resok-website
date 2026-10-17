/*
 * The ReSoK-style header's menu (assets/css/resok-header.css): the mobile menu, and the
 * slimmer header once the page scrolls. Mirrors the main site's behaviour.
 */
(function () {
  var navbar = document.querySelector('.rh-navbar');
  var burger = document.getElementById('rhHamburger');
  var menu = document.getElementById('rhMenu');
  if (!navbar) return;
  function sync() { navbar.classList.toggle('scrolled', window.scrollY > 40); }
  sync();
  window.addEventListener('scroll', sync, { passive: true });
  if (!burger || !menu) return;
  function close() {
    burger.classList.remove('active');
    menu.classList.remove('active');
    document.body.classList.remove('rh-nav-open');
    burger.setAttribute('aria-expanded', 'false');
  }
  burger.addEventListener('click', function () {
    var open = !menu.classList.contains('active');
    burger.classList.toggle('active', open);
    menu.classList.toggle('active', open);
    document.body.classList.toggle('rh-nav-open', open);
    burger.setAttribute('aria-expanded', String(open));
  });
  menu.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', close); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
  window.addEventListener('resize', function () { if (window.innerWidth > 992) close(); });
})();
