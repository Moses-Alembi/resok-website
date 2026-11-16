/* No services, account actions or production scripts: only prototype interactions. */
(() => {
  'use strict';
  const header = document.querySelector('.header');
  const menu = document.querySelector('#mobile-menu');
  const toggle = document.querySelector('.menu-toggle');
  const mobile = matchMedia('(max-width: 1000px)');
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  const main = document.querySelector('main');
  const footer = document.querySelector('footer');
  function setMenu(open, returnFocus = false) {
    menu.hidden = !open;
    toggle.setAttribute('aria-expanded', String(open));
    toggle.innerHTML = open ? 'Close <span aria-hidden="true">×</span>' : 'Menu <span aria-hidden="true">☰</span>';
    document.body.classList.toggle('menu-open', open);
    main.inert = open;
    footer.inert = open;
    document.querySelector('.utility').inert = open;
    if (open) menu.querySelector('a').focus();
    else if (returnFocus) toggle.focus();
  }
  toggle.addEventListener('click', () => setMenu(menu.hidden));
  menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setMenu(false)));
  mobile.addEventListener('change', () => setMenu(false));
  document.addEventListener('keydown', event => {
    if (menu.hidden) return;
    if (event.key === 'Escape') setMenu(false, true);
    if (event.key === 'Tab') {
      const items = [toggle, ...menu.querySelectorAll('a')];
      const first = items[0], last = items[items.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }
  });
  function updateHeader() { header.classList.toggle('scrolled', scrollY > 24); }
  addEventListener('scroll', updateHeader, {passive:true});
  updateHeader();

  document.querySelectorAll('a[href^="#"]').forEach(link => link.addEventListener('click', event => {
    const target = document.getElementById(link.hash.slice(1));
    if (!target) return;
    event.preventDefault();
    setMenu(false);
    target.setAttribute('tabindex', '-1');
    target.focus({preventScroll:true});
    target.scrollIntoView({behavior:reducedMotion.matches ? 'instant' : 'smooth'});
    history.replaceState(null, '', link.hash);
  }));

  const stories = [...document.querySelectorAll('[data-category]')];
  const filters = [...document.querySelectorAll('[data-filter]')];
  filters.forEach(button => button.addEventListener('click', () => {
    filters.forEach(item => item.setAttribute('aria-pressed', String(item === button)));
    stories.forEach(story => { story.hidden = button.dataset.filter !== 'all' && story.dataset.category !== button.dataset.filter; });
    const count = stories.filter(story => !story.hidden).length;
    document.querySelector('#filter-status').textContent = `${count} ${count === 1 ? 'article' : 'articles'} shown.`;
  }));

  const previews = {
    membership: {title:'A place in the community.', copy:'Professional connection, continuing education and scientific exchange. This concept shows how membership could be introduced on the new homepage. Explore the existing membership benefits for current details.', label:'View membership benefits ↗', url:'../../membership-benefits.html'},
    portal: {title:'Your ReSoK, in one place.', copy:'A proposed gateway to your membership, learning and professional community. The existing My ReSoK service remains separate from this design preview.', label:'Explore membership benefits ↗', url:'../../membership-benefits.html'},
    academy: {title:'The next chapter in learning.', copy:'The ReSoK Virtual Academy is presented here as a coming-soon learning destination. Courses and enrolment are not part of this visual prototype.', label:'Explore current workshops ↗', url:'../../workshops-and-training.html'},
    cme: {title:'Diagnosing Bronchiectasis on Chest CT Scan', copy:'1 October 2026 · 7:00 PM EAT · Zoom. A CME session with consultant radiologist Dr Maxwell Gachie. Refer to the existing events page for event details.', label:'View ReSoK events ↗', url:'../../events.html'},
    webinar: {title:'Infectious Lung Disorders', copy:'28 September 2026 · Past webinar. Spectrum of Surgical Treatment Options. Explore the existing events page for the Society’s learning programme.', label:'View ReSoK events ↗', url:'../../events.html'}
  };
  const dialog = document.querySelector('.preview-dialog');
  let dialogTrigger;
  document.querySelectorAll('[data-preview]').forEach(button => button.addEventListener('click', () => {
    const content = previews[button.dataset.preview];
    dialogTrigger = button;
    document.querySelector('#preview-title').textContent = content.title;
    document.querySelector('#preview-copy').textContent = content.copy;
    const link = document.querySelector('#preview-link');
    link.textContent = content.label;
    link.href = content.url;
    dialog.showModal();
    document.body.classList.add('dialog-open');
  }));
  dialog.querySelector('.dialog-close').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => {
    const rect = dialog.getBoundingClientRect();
    if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
  });
  dialog.addEventListener('close', () => { document.body.classList.remove('dialog-open'); dialogTrigger?.focus(); });

  // The page starts fully visible. Only off-screen photographs receive a one-time reveal.
  if ('IntersectionObserver' in window && !reducedMotion.matches) {
    const observer = new IntersectionObserver(entries => entries.forEach(entry => {
      if (entry.isIntersecting) { entry.target.classList.remove('is-waiting'); observer.unobserve(entry.target); }
    }), {threshold:0.08});
    document.querySelectorAll('.reveal').forEach(element => {
      if (element.getBoundingClientRect().top > innerHeight) { element.classList.add('is-waiting'); observer.observe(element); }
    });
    reducedMotion.addEventListener('change', () => {
      if (reducedMotion.matches) { observer.disconnect(); document.querySelectorAll('.is-waiting').forEach(element => element.classList.remove('is-waiting')); }
    });
  }
})();

