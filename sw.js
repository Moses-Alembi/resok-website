/* Minimal service worker for basic offline support.
   Bump CACHE_NAME on any deploy where CORE_ASSETS content changes, so old
   clients' caches get cleared out during the next activate cycle. */
const CACHE_NAME = "resok-static-v21";
const CORE_ASSETS = [
  "index.html",
  "about.html",
  "coming-soon.html",
  "contact.html",
  "membership.html",
  "membership-benefits.html",
  "manifest.json",
  "favicon.jpg",
  "assets/img/logo.png",
  "assets/img/social/whatsapp.svg",
  "assets/img/social/x.svg",
  "assets/img/social/linkedin.svg",
  "assets/img/social/facebook.svg"
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CACHE_NAME)
      .then((cache) => cache.addAll(CORE_ASSETS))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
      )
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (event) => {
  const { request } = event;
  if (request.method !== "GET") return;

  const url = new URL(request.url);

  // Never intercept API calls - always hit the network. Caching these would mean
  // membership status, payments, CPD points etc. could go stale in a way no amount
  // of reloading fixes, since the service worker (not the server) is being asked.
  if (url.pathname.includes("/api/")) return;

  // Members-only pages are per-visitor and must never be written to the cache: anything
  // stored here survives logout and is readable by the next person on a shared device.
  // Leaving these to the network also means the server-side gate is the only thing that
  // ever decides who sees them.
  const path = url.pathname.replace(/\/$/, "");
  const GATED = ["/learning", "/media-learning", "/assemblies", "/research", "/workshops-and-training"];
  if (GATED.includes(path.replace(/\.php$/, "")) || url.pathname.startsWith("/resok-portal/")) return;

  const isNavigation = request.mode === "navigate" || (request.headers.get("accept") || "").includes("text/html");

  if (isNavigation) {
    // Network-first for pages: always try to get the latest deployed version first,
    // falling back to the cache only when actually offline. This is what makes
    // deploys show up immediately instead of requiring a cache-clear.
    event.respondWith(
      fetch(request)
        .then((response) => {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
          return response;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match("index.html")))
    );
    return;
  }

  // Cache-first for static assets (images, css, js, fonts) - these rarely change and
  // benefit from being served instantly without a network round-trip.
  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;
      return fetch(request).then((response) => {
        const responseClone = response.clone();
        caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
        return response;
      });
    })
  );
});
