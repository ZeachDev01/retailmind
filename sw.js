// Root Service Worker entrypoint
try {
  importScripts('/src/frontend/sw.js');
} catch (_) {
  importScripts('./src/frontend/sw.js');
}
