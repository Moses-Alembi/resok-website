/* Staff chrome only: access decisions belong to staff/home and the PHP API. */
(function () {
  'use strict';
  const byId = (id) => document.getElementById(id);
  const root = document.documentElement;
  const themeButton = byId('staffThemeToggle');
  const themeKey = 'resok-admin-theme';
  function setTheme(value) {
    const theme = value === 'light' ? 'light' : 'dark';
    root.dataset.theme = theme;
    const label = 'Switch to ' + (theme === 'dark' ? 'light' : 'dark') + ' mode';
    themeButton.setAttribute('aria-label', label);
    themeButton.title = label;
  }
  setTheme(root.dataset.theme);
  themeButton.addEventListener('click', () => {
    setTheme(root.dataset.theme === 'dark' ? 'light' : 'dark');
    try { localStorage.setItem(themeKey, root.dataset.theme); } catch (e) { /* Still works for this tab. */ }
  });
  window.addEventListener('storage', (event) => {
    if (event.key === themeKey || event.key === null) setTheme(event.newValue);
  });

  const menuButton = byId('menuBtn');
  const sidebar = byId('side');
  const backdrop = byId('staffSidebarBackdrop');
  const mobile = window.matchMedia('(max-width: 980px)');
  function closeMenu(restoreFocus) {
    sidebar.classList.remove('open');
    backdrop.classList.remove('open');
    document.body.classList.remove('staff-menu-open');
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-label', 'Open staff navigation');
    sidebar.inert = mobile.matches;
    if (restoreFocus) menuButton.focus();
  }
  window.ResokStaffShell = { closeMenu };
  closeMenu();
  menuButton.addEventListener('click', () => {
    if (sidebar.classList.contains('open')) { closeMenu(true); return; }
    sidebar.inert = false;
    sidebar.classList.add('open');
    backdrop.classList.add('open');
    document.body.classList.add('staff-menu-open');
    menuButton.setAttribute('aria-expanded', 'true');
    menuButton.setAttribute('aria-label', 'Close staff navigation');
    const first = Array.from(sidebar.querySelectorAll('a[href]')).find((a) => !a.closest('[hidden]'));
    if (first) first.focus();
  });
  backdrop.addEventListener('click', () => closeMenu(true));
  sidebar.addEventListener('click', (event) => { if (event.target.closest('a[href]')) closeMenu(); });
  mobile.addEventListener('change', () => closeMenu());
  document.addEventListener('keydown', (event) => {
    if (!mobile.matches || !sidebar.classList.contains('open')) return;
    if (event.key === 'Escape') { closeMenu(true); event.preventDefault(); }
    if (event.key === 'Tab') {
      const items = [menuButton, ...Array.from(sidebar.querySelectorAll('a[href]')).filter((a) => !a.closest('[hidden]'))];
      const first = items[0], last = items[items.length - 1];
      if (event.shiftKey && (document.activeElement === first || !items.includes(document.activeElement))) {
        event.preventDefault(); last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || !items.includes(document.activeElement))) {
        event.preventDefault(); first.focus();
      }
    }
  });

  const search = byId('staffSearch');
  const results = byId('staffSearchResults');
  const searchWrap = byId('staffSearchWrap');
  function closeSearch() { results.hidden = true; search.setAttribute('aria-expanded', 'false'); }
  function searchSections() {
    const query = search.value.trim().toLocaleLowerCase();
    results.replaceChildren();
    if (!query) { closeSearch(); return; }
    const matches = Array.from(sidebar.querySelectorAll('a[href]')).filter((a) =>
      !a.closest('[hidden]') && a.textContent.toLocaleLowerCase().includes(query)
    ).slice(0, 8);
    matches.forEach((source) => {
      const hit = document.createElement('a');
      hit.className = 'admin-search-hit';
      hit.href = source.getAttribute('href');
      const title = document.createElement('strong');
      title.textContent = source.textContent.trim();
      hit.appendChild(title);
      results.appendChild(hit);
    });
    if (!matches.length) {
      const empty = document.createElement('p');
      empty.className = 'admin-search-empty'; empty.textContent = 'No matching staff sections.';
      results.appendChild(empty);
    }
    results.hidden = false;
    search.setAttribute('aria-expanded', 'true');
  }
  search.addEventListener('input', searchSections);
  search.addEventListener('focus', searchSections);
  search.addEventListener('keydown', (event) => {
    const first = results.querySelector('a');
    if (event.key === 'Enter' && first && !results.hidden) { event.preventDefault(); first.click(); }
    if (event.key === 'ArrowDown' && first && !results.hidden) { event.preventDefault(); first.focus(); }
  });
  results.addEventListener('click', (event) => {
    if (event.target.closest('a')) { search.value = ''; closeSearch(); }
  });
  searchWrap.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') { search.focus(); closeSearch(); }
  });
  document.addEventListener('click', (event) => { if (!searchWrap.contains(event.target)) closeSearch(); });
  searchWrap.addEventListener('focusout', (event) => { if (!searchWrap.contains(event.relatedTarget)) closeSearch(); });

  const fullscreen = byId('staffFullscreen');
  fullscreen.hidden = !document.fullscreenEnabled;
  fullscreen.addEventListener('click', async () => {
    try {
      if (document.fullscreenElement) await document.exitFullscreen();
      else await root.requestFullscreen();
    } catch (e) { /* The browser may disallow fullscreen in an embedded preview. */ }
  });
  document.addEventListener('fullscreenchange', () => {
    const active = !!document.fullscreenElement;
    const label = active ? 'Exit fullscreen' : 'Enter fullscreen';
    fullscreen.setAttribute('aria-label', label); fullscreen.title = label;
    fullscreen.querySelector('i').className = 'fas ' + (active ? 'fa-compress' : 'fa-expand');
  });
})();
