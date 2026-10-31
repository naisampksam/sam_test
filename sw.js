// Service worker: shows task notifications sent by the server (inc/push.php) and opens the task when tapped.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = { title: 'Looma', body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'Looma', {
    body: d.body || '', icon: d.icon, badge: d.icon, tag: d.tag, renotify: !!d.tag, data: { url: d.url || './' }
  }));
});

self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = new URL((e.notification.data && e.notification.data.url) || './', self.location.href).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].url === url && 'focus' in list[i]) return list[i].focus();
    }
    for (var j = 0; j < list.length; j++) {
      if ('navigate' in list[j] && new URL(list[j].url).origin === self.location.origin) return list[j].navigate(url).then(function (c) { return c && c.focus(); });
    }
    return self.clients.openWindow(url);
  }));
});
