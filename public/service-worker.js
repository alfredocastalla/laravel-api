const CACHE_NAME = "barangaycare-shell-v212";
const APP_SHELL = [
  "/",
  "/manifest.webmanifest",
  "/app-icon.svg",
  "/icons/icon-192.png",
  "/icons/icon-512.png",
  "/assets/app.css?v=20261107-appcss53",
  "/laravel-service.js?v=20261105-service14",
  "/assets/app.js?v=20261107-directscript94",
  "/vendor/react.production.min.js",
  "/vendor/react-dom.production.min.js",
];

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  const requestUrl = new URL(event.request.url);
  if (event.request.method !== "GET" || requestUrl.origin !== self.location.origin || requestUrl.pathname.includes("/api/")) return;

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        if (response.ok) {
          return caches.open(CACHE_NAME)
            .then((cache) => cache.put(event.request, response.clone()))
            .catch((error) => console.error("BarangayCare offline cache could not store a page.", error))
            .then(() => response);
        }
        return response;
      })
      .catch(() => caches.match(event.request).then((cached) => {
        if (cached) return cached;
        if (event.request.mode === "navigate") {
          return caches.match("/").then((appShell) => appShell || new Response(
            "BarangayCare is offline. Reconnect to the internet and try again.",
            { status: 503, headers: { "Content-Type": "text/plain; charset=utf-8" } }
          ));
        }
        return new Response("This resource is unavailable offline.", {
          status: 503,
          headers: { "Content-Type": "text/plain; charset=utf-8" },
        });
      }))
  );
});
