/* Counter-scrolling photo columns for gallery.html.
 *
 * The section is made as tall as the columns need to travel, and its stage sticks under the
 * navbar. Scrolling through the section moves the outer columns down and the middle one up,
 * each by exactly its own overflow, so every photo passes through view once. Movement eases
 * toward the scroll position instead of snapping to it, which gives the smooth-scroll feel
 * without hijacking the page's native scrolling.
 *
 * Nothing happens with reduced motion: the stage stays the plain grid the CSS already draws.
 */
(() => {
  const section = document.querySelector('.scroll-gallery');
  if (!section) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const stage = section.querySelector('.scroll-gallery__stage');
  const items = Array.from(stage.querySelectorAll('.scroll-gallery__item'));
  const EASE = 0.1;

  let columns = [];
  let travel = [];
  let target = 0;
  let current = 0;
  let frame = 0;

  const columnCount = () => (window.innerWidth < 700 ? 2 : 3);

  // Photos are dealt round-robin, so each column mixes events rather than running in order.
  function build() {
    const count = columnCount();
    if (count === columns.length) return;
    columns = Array.from({ length: count }, () => {
      const col = document.createElement('div');
      col.className = 'scroll-gallery__col';
      return col;
    });
    items.forEach((item, i) => columns[i % count].append(item));
    stage.replaceChildren(...columns);
  }

  function measure() {
    // --navbar-height undershoots the real bar on phones (128px against 160px), so stick
    // below the navbar as drawn rather than as declared.
    const navbar = document.querySelector('.navbar');
    if (navbar) section.style.setProperty('--gallery-top', `${navbar.offsetHeight}px`);
    const view = stage.clientHeight;
    travel = columns.map((col) => Math.max(0, col.offsetHeight - view));
    section.style.height = `${view + Math.max(0, ...travel)}px`;
    target = current = progress();
    render();
  }

  // 0 when the stage first sticks, 1 when it lets go.
  function progress() {
    const stickTop = parseFloat(getComputedStyle(stage).top) || 0;
    const distance = section.offsetHeight - stage.clientHeight;
    if (distance <= 0) return 0;
    const p = (stickTop - section.getBoundingClientRect().top) / distance;
    return Math.min(1, Math.max(0, p));
  }

  function render() {
    columns.forEach((col, i) => {
      const y = i % 2 === 0 ? -travel[i] * (1 - current) : -travel[i] * current;
      col.style.transform = `translate3d(0, ${y.toFixed(1)}px, 0)`;
    });
  }

  function tick() {
    current += (target - current) * EASE;
    if (Math.abs(target - current) < 0.0005) current = target;
    render();
    frame = current === target ? 0 : requestAnimationFrame(tick);
  }

  function onScroll() {
    target = progress();
    if (!frame) frame = requestAnimationFrame(tick);
  }

  let resizeTimer = 0;
  function onResize() {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      build();
      measure();
    }, 120);
  }

  section.classList.add('is-live');
  build();
  measure();
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onResize);
  // Fonts can shift caption heights after first paint.
  window.addEventListener('load', measure);
})();
