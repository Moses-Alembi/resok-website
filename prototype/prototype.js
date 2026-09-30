/*
 * ReSoK homepage prototype: interaction and motion.
 *
 * Everything here is progressive. Without this script, without GSAP, or with reduced motion,
 * the page is complete and readable; motion only adds entrance, reveal and scroll effects.
 */
(() => {
  'use strict';

  const root = document.documentElement;
  const hasGsap = typeof window.gsap !== 'undefined' && typeof window.ScrollTrigger !== 'undefined';
  if (!hasGsap) root.classList.remove('motion');
  const motion = root.classList.contains('motion');
  const finePointer = matchMedia('(pointer: fine)').matches;

  /* ---------------- navigation ---------------- */
  const nav = document.getElementById('nav');
  const hero = document.querySelector('.hero');
  let lastY = window.scrollY;
  const onScroll = () => {
    const y = window.scrollY;
    const threshold = hero ? hero.offsetHeight * 0.72 : 80;
    nav.classList.toggle('is-scrolled', y > threshold);
    // Tuck the bar away while reading downwards; bring it back on any upward scroll.
    nav.classList.toggle('is-hidden', y > threshold + 300 && y > lastY && !root.classList.contains('menu-open'));
    lastY = y;
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------------- mobile menu ---------------- */
  const toggle = document.querySelector('.nav-toggle');
  const menu = document.getElementById('menu');
  const setMenu = (open) => {
    root.classList.toggle('menu-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    menu.setAttribute('aria-hidden', String(!open));
    if (lenis) open ? lenis.stop() : lenis.start();
  };
  toggle.addEventListener('click', () => setMenu(!root.classList.contains('menu-open')));
  menu.querySelectorAll('a').forEach((a) => a.addEventListener('click', () => setMenu(false)));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setMenu(false); });

  /* ---------------- smooth scroll (desktop, motion only) ---------------- */
  let lenis = null;
  if (motion && finePointer && typeof window.Lenis !== 'undefined') {
    lenis = new window.Lenis({ duration: 1.15, smoothWheel: true });
    lenis.on('scroll', window.ScrollTrigger.update);
    gsap.ticker.add((t) => lenis.raf(t * 1000));
    gsap.ticker.lagSmoothing(0);
  }
  document.querySelectorAll('a[href^="#"]').forEach((a) => {
    a.addEventListener('click', (e) => {
      const id = a.getAttribute('href');
      const target = id.length > 1 ? document.querySelector(id) : null;
      if (!target) return;
      e.preventDefault();
      if (lenis) lenis.scrollTo(target, { offset: id === '#top' ? 0 : -40 });
      else target.scrollIntoView({ behavior: motion ? 'smooth' : 'auto' });
      target.setAttribute('tabindex', '-1');
      target.focus({ preventScroll: true });
    });
  });

  /* ---------------- workshop hover preview (desktop) ---------------- */
  const preview = document.querySelector('.ws-preview');
  if (preview && finePointer) {
    const img = preview.querySelector('img');
    let x = 0, y = 0, px = 0, py = 0, raf = 0;
    const follow = () => {
      px += (x - px) * 0.18; py += (y - py) * 0.18;
      preview.style.left = px + 'px'; preview.style.top = py + 'px';
      raf = Math.abs(x - px) + Math.abs(y - py) > 0.5 ? requestAnimationFrame(follow) : 0;
    };
    document.querySelectorAll('.ws-list a').forEach((a) => {
      a.addEventListener('mouseenter', (e) => {
        img.src = a.dataset.img;
        if (!preview.classList.contains('is-on')) { px = x = e.clientX + 170; py = y = e.clientY; }
        preview.classList.add('is-on');
      });
      a.addEventListener('mousemove', (e) => {
        x = e.clientX + 170; y = e.clientY;
        if (!raf) raf = requestAnimationFrame(follow);
      });
      a.addEventListener('mouseleave', () => preview.classList.remove('is-on'));
    });
  }

  /* ---------------- knowledge filter ---------------- */
  const filterButtons = document.querySelectorAll('.k-filters button');
  filterButtons.forEach((b) => b.addEventListener('click', () => {
    filterButtons.forEach((o) => { o.classList.toggle('is-on', o === b); o.setAttribute('aria-pressed', String(o === b)); });
    const f = b.dataset.filter;
    document.querySelectorAll('.k-card').forEach((c) => c.classList.toggle('is-hidden', f !== 'all' && c.dataset.cat !== f));
    if (hasGsap) window.ScrollTrigger.refresh();
  }));

  /* ---------------- small things ---------------- */
  const badge = document.querySelector('.proto-badge');
  badge.querySelector('button').addEventListener('click', () => badge.classList.add('is-hidden'));
  document.querySelectorAll('[data-year]').forEach((n) => { n.textContent = new Date().getFullYear(); });

  if (!motion) {
    const loader = document.querySelector('.loader');
    if (loader) loader.remove();
    return;
  }

  /* ================= motion ================= */
  gsap.registerPlugin(ScrollTrigger);
  const ease = 'expo.out';
  // The script has taken over, so cancel the CSS failsafe that would otherwise reveal the hero
  // at 4s and cut into the entrance.
  gsap.set(['.hero-title .line > span', '.hero .reveal-up', '.hero-media', '.loader'], { animation: 'none' });

  // Split headings into words so each can rise from behind its own mask. Italic accents keep
  // their <em>; screen readers still read the heading as one piece of text.
  document.querySelectorAll('.split').forEach((el) => {
    const wrapWords = (node) => {
      [...node.childNodes].forEach((child) => {
        if (child.nodeType === 3) {
          const frag = document.createDocumentFragment();
          child.textContent.split(/(\s+)/).forEach((part) => {
            if (!part) return;
            if (/^\s+$/.test(part)) { frag.appendChild(document.createTextNode(' ')); return; }
            const w = document.createElement('span'); w.className = 'w';
            const i = document.createElement('span'); i.textContent = part;
            w.appendChild(i); frag.appendChild(w);
          });
          child.replaceWith(frag);
        } else if (child.nodeType === 1) wrapWords(child);
      });
    };
    wrapWords(el);
    gsap.from(el.querySelectorAll('.w > span'), {
      yPercent: 105, duration: 1.3, ease, stagger: 0.06,
      scrollTrigger: { trigger: el, start: 'top 85%' },
    });
  });

  // Entrance: short loader (first visit of the session only), then the hero builds.
  const loader = document.querySelector('.loader');
  let seen = false;
  try { seen = sessionStorage.getItem('resok-proto-seen') === '1'; sessionStorage.setItem('resok-proto-seen', '1'); } catch (e) { /* private mode */ }
  const intro = gsap.timeline({ defaults: { ease } });
  if (loader && !seen) {
    intro
      .from('.loader-word', { yPercent: 110, duration: 1 })
      .from('.loader-sub', { opacity: 0, y: 10, duration: .8 }, '<.3')
      .to('.loader-inner', { opacity: 0, y: -20, duration: .5, ease: 'power2.in' }, '+=.35')
      .to(loader, { clipPath: 'inset(0 0 100% 0)', duration: 1, ease: 'expo.inOut' }, '-=.1')
      .set(loader, { display: 'none' });
  } else if (loader) {
    loader.remove();
  }
  intro
    .fromTo('.hero-media', { clipPath: 'inset(0 0 0 100%)' }, { clipPath: 'inset(0 0 0 0%)', duration: 1.6, ease: 'expo.inOut' }, seen ? 0 : '-=.75')
    .fromTo('.hero-media img', { scale: 1.25 }, { scale: 1, duration: 2.2 }, '<')
    // y: 0 clears the CSS starting offset, which GSAP would otherwise keep as a pixel shift.
    .fromTo('.hero-title .line > span', { y: 0, yPercent: 105 }, { y: 0, yPercent: 0, duration: 1.4, stagger: .1 }, '<.35')
    .fromTo('.hero .reveal-up', { opacity: 0, y: 24 }, { opacity: 1, y: 0, duration: 1.1, stagger: .08 }, '<.5');

  // Hero drifts as it leaves.
  gsap.to('.hero-media img', { yPercent: 10, scale: 1.08, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'top top', end: 'bottom top', scrub: true } });
  gsap.to('.hero-inner', { yPercent: -12, opacity: .2, ease: 'none', scrollTrigger: { trigger: '.hero', start: 'center center', end: 'bottom top', scrub: true } });

  // Generic reveals, batched so a long page does not create hundreds of triggers.
  gsap.set('.reveal', { opacity: 0, y: 36 });
  ScrollTrigger.batch('.reveal', {
    start: 'top 90%', once: true,
    onEnter: (els) => gsap.to(els, { opacity: 1, y: 0, duration: 1.1, ease, stagger: .08, overwrite: true }),
  });

  // Images unmask upward while settling from a slight zoom.
  document.querySelectorAll('[data-mask]').forEach((m) => {
    const img = m.querySelector('img');
    const tl = gsap.timeline({ scrollTrigger: { trigger: m, start: 'top 85%' } });
    tl.fromTo(m, { clipPath: 'inset(100% 0 0 0)' }, { clipPath: 'inset(0% 0 0 0)', duration: 1.5, ease: 'expo.inOut' })
      .fromTo(img, { scale: 1.3 }, { scale: 1.1, duration: 2, ease }, '<');
    gsap.fromTo(img, { yPercent: -5 }, { yPercent: 5, ease: 'none', scrollTrigger: { trigger: m, start: 'top bottom', end: 'bottom top', scrub: true } });
  });

  // Full-bleed backgrounds move slower than the page.
  document.querySelectorAll('.advocacy-media img, .closing-media img').forEach((img) => {
    gsap.fromTo(img, { yPercent: -6 }, { yPercent: 6, ease: 'none', scrollTrigger: { trigger: img.closest('section'), start: 'top bottom', end: 'bottom top', scrub: true } });
  });

  // PUMZI letters rise one by one.
  gsap.from('.pumzi-letters li', {
    yPercent: 40, opacity: 0, duration: 1.2, ease, stagger: .09,
    scrollTrigger: { trigger: '.pumzi-letters', start: 'top 85%' },
  });

  // Closing word assembles with the scroll.
  gsap.from('.closing-word span', {
    yPercent: 60, opacity: 0, ease: 'power3.out', stagger: .06,
    scrollTrigger: { trigger: '.closing', start: 'top 70%', end: 'center center', scrub: 1 },
  });

  // Events: on wide screens the section pins and the listing travels sideways; phones swipe.
  const mm = gsap.matchMedia();
  mm.add('(min-width: 761px)', () => {
    const track = document.querySelector('.events-track');
    const distance = () => Math.max(0, track.scrollWidth - window.innerWidth + parseFloat(getComputedStyle(document.querySelector('.events-track-wrap')).paddingLeft));
    const tween = gsap.to(track, {
      x: () => -distance(), ease: 'none',
      scrollTrigger: {
        trigger: '.events-pin', start: 'top top', end: () => '+=' + distance(),
        pin: true, scrub: 1, invalidateOnRefresh: true, anticipatePin: 1,
      },
    });
    return () => tween.scrollTrigger && tween.scrollTrigger.kill();
  });

  // Images load lazily; recalculate trigger positions once they have their real size.
  window.addEventListener('load', () => ScrollTrigger.refresh());
})();
