/* Minimal service worker for basic offline support */
const CACHE_NAME = "resok-static-v17";
const CORE_ASSETS = [
  "index.html",
  "about.html",
  "assemblies.html",
  "blog.html",
  "coming-soon.html",
  "conferences.html",
  "contact.html",
  "guidelines.html",
  "knowledge.html",
  "learning.html",
  "media-learning.html",
  "membership.html",
  "membership-benefits.html",
  "patient-resources.html",
  "projects.html",
  "research.html",
  "sponsors.html",
  "workshops-and-training.html",
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

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;
      return fetch(request)
        .then((response) => {
          const responseClone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, responseClone));
          return response;
        })
        .catch(() => caches.match("index.html"));
    })
  );
});
