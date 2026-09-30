/*
 * ReSoK platform prototype: one definition of the site structure drives the header, the mega
 * menus, the mobile menu and the footer on every page. This is the pattern proposed for the real
 * site, where each page currently carries its own copy of the menu.
 *
 * Status: 'live' works today, 'dev' is built but not yet open, 'soon' is planned only.
 */
(() => {
  'use strict';
  const S = '../../'; // prototype/platform/ -> site root

  const NAV = [
    {
      id: 'about', label: 'About',
      intro: 'Formerly KAPTLD — the professional society for respiratory health in Kenya.',
      cta: { label: 'Who we are', href: S + 'about.html' },
      links: [
        { label: 'Who we are', note: 'History, mission and PUMZI values', href: S + 'about.html', s: 'live' },
        { label: 'Leadership & governance', note: 'Board and secretariat', href: S + 'about.html', s: 'live' },
        { label: 'Strategic Plan 2026–2035', note: 'Priorities for the decade', href: S + 'about.html', s: 'live' },
        { label: 'Projects', note: 'TB, post-TB and integrated care', href: S + 'projects.html', s: 'live' },
        { label: 'Partners & sponsors', note: 'Who we work with', href: S + 'sponsors.html', s: 'live' },
        { label: 'Contact & donate', note: 'Regent Courts, Nairobi', href: S + 'contact.html', s: 'live' },
      ],
      feature: { img: '../img/society-sm.jpg', tag: 'The Society', title: 'Clinicians, researchers and partners for lung health' },
    },
    {
      id: 'membership', label: 'Membership',
      intro: 'Join Kenya\'s respiratory community and manage everything in My ReSoK.',
      cta: { label: 'Become a member', href: S + 'resok-portal/public/' },
      links: [
        { label: 'Become a member', note: 'Apply and pay online', href: S + 'resok-portal/public/', s: 'live' },
        { label: 'Member benefits', note: 'What membership includes', href: S + 'membership-benefits.html', s: 'live' },
        { label: 'My ReSoK', note: 'Card, payments, renewal, certificates', href: 'my-resok.html', s: 'live' },
        { label: 'CPD & certificates', note: 'Tokens from accredited events', href: S + 'events.html', s: 'live' },
        { label: 'Assemblies & working groups', note: 'Members only', href: S + 'assemblies', s: 'live', members: true },
        { label: 'Online elections', note: 'Built — opening to members', href: 'my-resok.html', s: 'dev' },
        { label: 'Member directory', note: 'Find peers by specialty and county', href: 'coming-soon.html', s: 'soon' },
      ],
      feature: { img: '../img/members-sm.jpg', tag: 'My ReSoK', title: 'Your membership, CPD and certificates in one place' },
    },
    {
      id: 'learning', label: 'Learning',
      intro: 'Hands-on training, accredited CMEs and a growing online academy.',
      cta: { label: 'Explore Learning', href: 'learning.html' },
      links: [
        { label: 'Workshops & training', note: 'Bronchoscopy, pleural, ultrasound', href: S + 'workshops-and-training.html', s: 'live' },
        { label: 'CMEs & webinars', note: 'CPD-accredited sessions', href: S + 'events.html', s: 'live' },
        { label: 'Knowledge Hub', note: 'Clinical topics and resources', href: S + 'knowledge.html', s: 'live' },
        { label: 'Members\' learning library', note: 'Members only', href: S + 'learning', s: 'live', members: true },
        { label: 'Recorded sessions', note: 'Members only', href: S + 'media-learning', s: 'live', members: true },
        { label: 'Virtual Academy', note: 'Courses, quizzes, certificates', href: S + 'resok-portal/public/academy', s: 'dev' },
        { label: 'Podcasts', note: 'Conversations in lung health', href: 'coming-soon.html', s: 'soon' },
      ],
      feature: { img: '../img/edu-teach-sm.jpg', tag: 'Workshops', title: 'Hands-on bronchoscopy, pleural and ultrasound training' },
    },
    {
      id: 'research', label: 'Research',
      intro: 'Research partnerships shaping TB and respiratory care in Kenya and Africa.',
      cta: { label: 'Research programmes', href: S + 'projects.html' },
      links: [
        { label: 'Research programmes', note: 'LIGHT, ITARA, ALBORADA', href: S + 'projects.html', s: 'live' },
        { label: 'Research & publications', note: 'Members only', href: S + 'research', s: 'live', members: true },
        { label: 'Guidelines & tools', note: 'Clinical guidance and checklists', href: S + 'guidelines.html', s: 'live' },
        { label: 'Publications library', note: 'Members only', href: S + 'publication', s: 'live', members: true },
        { label: 'Research repository', note: 'Members\' abstracts and papers', href: 'coming-soon.html', s: 'soon' },
      ],
      feature: { img: '../img/research-notes-sm.jpg', tag: 'LIGHT', title: 'ReSoK is LIGHT\'s research partner in Kenya' },
    },
    {
      id: 'events', label: 'Events',
      intro: 'Conferences, symposia, CMEs and camps — with CPD for every accredited session.',
      cta: { label: 'Upcoming events', href: S + 'events.html' },
      links: [
        { label: 'Upcoming events & CPD', note: 'Register and collect tokens', href: S + 'events.html', s: 'live' },
        { label: 'Conferences', note: 'KISLHC and symposia', href: S + 'conferences.html', s: 'live' },
        { label: 'KISLHC', note: 'Conference platform', href: S + 'kislhc/index', s: 'live' },
        { label: 'Workshops', note: 'Hands-on camps', href: S + 'workshops-and-training.html', s: 'live' },
        { label: 'Event registration in My ReSoK', note: 'Built — opening to members', href: 'my-resok.html', s: 'dev' },
      ],
      feature: { img: '../img/events-hall-sm.jpg', tag: 'KISLHC 2026', title: '7th Kenya International Scientific Lung Health Conference' },
    },
    {
      id: 'lung-health', label: 'Lung Health',
      intro: 'Plain-language information, awareness and advocacy for patients and the public.',
      cta: { label: 'Patient resources', href: S + 'patient-resources.html' },
      links: [
        { label: 'Patient resources', note: 'Symptoms, tests and care', href: S + 'patient-resources.html', s: 'live' },
        { label: 'Lung health topics', note: 'TB, asthma, COPD and more', href: S + 'knowledge.html', s: 'live' },
        { label: 'Advocacy & awareness', note: 'Awareness days and campaigns', href: S + 'blog.html', s: 'live' },
        { label: 'Community outreach', note: 'Screening and education', href: S + 'blog.html', s: 'live' },
      ],
      feature: { img: '../img/advocacy-sm.jpg', tag: 'Outreach', title: 'From the clinic to the community' },
    },
    {
      id: 'news', label: 'News',
      intro: 'Stories from the field, the clinic and the lab.',
      cta: { label: 'All news', href: S + 'blog.html' },
      links: [
        { label: 'News & articles', note: 'Society updates', href: S + 'blog.html', s: 'live' },
        { label: 'Gallery', note: 'Events in pictures', href: S + 'gallery.html', s: 'live' },
        { label: 'Symposium recordings', note: 'Watch past sessions', href: S + 'conferences.html', s: 'live' },
      ],
      feature: { img: '../img/k-light-sm.jpg', tag: 'Research · Jul 2025', title: 'LIGHT partners spearhead a scientific writing workshop' },
    },
  ];

  const LABEL = { live: 'Live', dev: 'In development', soon: 'Coming soon' };
  const chip = (s) => `<span class="chip chip--${s}">${LABEL[s]}</span>`;
  const chev = '<svg viewBox="0 0 10 10" aria-hidden="true"><path d="M1 3l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';
  const current = document.body.dataset.section || '';

  const link = (l) => {
    const right = l.s === 'live' ? (l.members ? '<span class="chip chip--members">Members</span>' : '') : chip(l.s);
    const body = `<span>${l.label}<small>${l.note}</small></span>${right}`;
    return l.s === 'soon' && l.href === 'coming-soon.html'
      ? `<a class="is-soon-link" href="${l.href}" style="color:var(--grey)">${body}</a>`
      : `<a href="${l.href}">${body}</a>`;
  };

  const header = `
    <div class="utility"><div class="wrap">
      <span>Respiratory Society of Kenya · Healthy lungs for all people in Kenya and beyond</span>
      <nav aria-label="Utility">
        <a href="${S}verify.html">Verify a certificate</a>
        <a href="${S}contact.html#donate">Donate</a>
        <a class="u-strong" href="my-resok.html">My ReSoK</a>
      </nav>
    </div></div>
    <header class="header"><div class="wrap">
      <a class="brand" href="index.html"><img src="../img/logo.png" alt="Respiratory Society of Kenya" width="768" height="204"></a>
      <nav class="mainnav" id="mainnav" aria-label="Primary"><ul>
        ${NAV.map((n) => `
          <li class="nav-item" data-id="${n.id}">
            <button class="nav-btn" type="button" aria-expanded="false" ${n.id === current ? 'aria-current="true"' : ''}>${n.label}${chev}</button>
            <div class="mega"><div class="mega-inner">
              <div class="mega-intro"><h3>${n.label}</h3><p>${n.intro}</p><a class="more" href="${n.cta.href}">${n.cta.label}</a></div>
              <div class="mega-links">${n.links.map(link).join('')}</div>
              <a class="mega-feature" href="${n.cta.href}"><img src="${n.feature.img}" alt="" loading="lazy"><div><span>${n.feature.tag}</span><strong>${n.feature.title}</strong></div></a>
            </div></div>
          </li>`).join('')}
      </ul></nav>
      <div class="header-actions">
        <button class="icon-btn" type="button" aria-label="Search"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="9" r="6"/><path d="M14 14l4 4"/></svg></button>
        <a class="btn btn-primary" href="${S}resok-portal/public/">Become a member</a>
        <button class="icon-btn menu-toggle" type="button" aria-label="Open menu" aria-controls="mainnav" aria-expanded="false"><svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h14M3 14h14"/></svg></button>
      </div>
    </div></header>`;

  const footer = `
    <footer class="footer"><div class="wrap">
      <div class="footer-grid">
        <div><img src="../img/logo-light.png" alt="Respiratory Society of Kenya"><p>Regent Courts, Argwings Kodhek Road, Nairobi<br>+254 735 700 660 · info@resok.org</p></div>
        ${NAV.filter((n) => ['membership', 'learning', 'research', 'events'].includes(n.id)).map((n) => `
          <div><h4>${n.label}</h4>${n.links.filter((l) => l.s !== 'soon').slice(0, 5).map((l) => `<a href="${l.href}">${l.label}</a>`).join('')}</div>`).join('')}
      </div>
      <div class="footer-bottom"><span>© 2026 Respiratory Society of Kenya</span><span>About · Lung Health · News · Privacy · Cookies</span></div>
    </div></footer>`;

  document.querySelector('[data-header]').outerHTML = header;
  const f = document.querySelector('[data-footer]');
  if (f) f.outerHTML = footer;

  /* ---- mega menu behaviour: hover on desktop, click/tap everywhere, Esc closes ---- */
  const items = [...document.querySelectorAll('.nav-item')];
  const desktop = () => matchMedia('(min-width: 1025px)').matches;
  let timer = 0;
  const open = (item) => {
    items.forEach((i) => {
      const on = i === item;
      i.classList.toggle('is-open', on);
      i.querySelector('.nav-btn').setAttribute('aria-expanded', String(on));
    });
  };
  items.forEach((item) => {
    const btn = item.querySelector('.nav-btn');
    btn.addEventListener('click', () => open(item.classList.contains('is-open') ? null : item));
    item.addEventListener('mouseenter', () => { if (!desktop()) return; clearTimeout(timer); timer = setTimeout(() => open(item), 90); });
    item.addEventListener('mouseleave', () => { if (!desktop()) return; clearTimeout(timer); timer = setTimeout(() => open(null), 160); });
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') { open(null); setNav(false); } });
  document.addEventListener('click', (e) => { if (desktop() && !e.target.closest('.nav-item')) open(null); });

  const toggle = document.querySelector('.menu-toggle');
  const setNav = (on) => {
    document.body.classList.toggle('nav-open', on);
    toggle.setAttribute('aria-expanded', String(on));
    toggle.setAttribute('aria-label', on ? 'Close menu' : 'Open menu');
  };
  toggle.addEventListener('click', () => setNav(!document.body.classList.contains('nav-open')));
  document.addEventListener('click', (e) => {
    if (document.body.classList.contains('nav-open') && !e.target.closest('.mainnav') && !e.target.closest('.menu-toggle')) setNav(false);
  });

  // Links inside the prototype that go nowhere yet (sample data) shouldn't navigate.
  document.querySelectorAll('[data-demo]').forEach((a) => a.addEventListener('click', (e) => e.preventDefault()));
})();
