// ===== SERVICE WORKER FOR 柚舟Cloud =====
const CACHE_NAME = 'pancloud-v3';
const STATIC_ASSETS = [
  '/',
  '/index.php',
  '/assets/css/style.css',
  '/assets/js/app.js',
  '/manifest.json',
  'https://cdn.bootcdn.net/ajax/libs/font-awesome/6.5.1/css/all.min.css'
];

// Install event - cache static assets
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => {
        return cache.addAll(STATIC_ASSETS);
      })
      .catch((error) => {
      })
  );
  self.skipWaiting();
});

// Activate event - clean up old caches
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  self.clients.claim();
});

// Fetch event - network first, fallback to cache
self.addEventListener('fetch', (event) => {
  // Skip non-GET requests
  if (event.request.method !== 'GET') {
    return;
  }

  // Skip cross-origin requests
  if (!event.request.url.startsWith(self.location.origin)) {
    return;
  }

  event.respondWith(
    fetch(event.request)
      .then((response) => {
        // Clone the response for caching
        const responseToCache = response.clone();
        
        // Cache successful responses
        if (response.status === 200) {
          caches.open(CACHE_NAME)
            .then((cache) => {
              cache.put(event.request, responseToCache);
            });
        }
        
        return response;
      })
      .catch((error) => {
        return caches.match(event.request)
          .then((cachedResponse) => {
            if (cachedResponse) {
              return cachedResponse;
            }
            
            // Offline fallback for navigation requests
            if (event.request.mode === 'navigate') {
              return caches.match('/index.php');
            }
            
            // Return empty response for other failed requests
            return new Response('Offline', { status: 503 });
          });
      })
  );
});

// Push notifications (optional)
self.addEventListener('push', (event) => {
  if (!event.data) return;
  
  const data = event.data.json();
  const options = {
    body: data.body || '您有一条新消息',
    icon: 'assets/img/icon-192.svg',
    badge: 'assets/img/icon-192.svg',
    vibrate: [100, 50, 100],
    data: {
      dateOfArrival: Date.now(),
      primaryKey: 1
    },
    actions: [
      {
        action: 'view',
        title: '查看'
      }
    ]
  };
  
  event.waitUntil(
    self.registration.showNotification(data.title || '柚舟Cloud', options)
  );
});

// Notification click handler
self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  
  if (event.action === 'view') {
    event.waitUntil(
      clients.openWindow('/index.php')
    );
  }
});

// Background sync (optional, for offline uploads)
self.addEventListener('sync', (event) => {
  if (event.tag === 'sync-uploads') {
    event.waitUntil(
      // Handle background sync for uploads
      Promise.resolve()
    );
  }
});
