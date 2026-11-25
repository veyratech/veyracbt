/**
 * Service Worker Registration — CBT Online Student PWA
 * Menangani: instalasi app, notifikasi push, cache offline
 */
(function () {
    'use strict';

    // ── SW DISABLED — pages must always be fresh (no cache) ────────────
    // Service worker registration removed to prevent cached responses on refresh.
    // Browser will still respect manifest scope for PWA install prompt.
    /*
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/cbtonline/sw.js', { scope: '/cbtonline/student/' })
                .then(function (reg) {
                    console.log('[PWA] Service Worker terdaftar → scope:', reg.scope);
                    updateSwStatus(reg);
                })
                .catch(function (err) {
                    console.warn('[PWA] Registrasi SW gagal:', err);
                });
        });
    }
    */
    // Also block ANY existing SW from re-registering on this page — DISABLED to prevent interference
    /*
    if ('serviceWorker' in navigator && navigator.serviceWorker.ready) {
        navigator.serviceWorker.ready.then(function(reg) { reg.unregister(); });
    }
    */

    // ── Install Prompt (disabled — conflict with desktop display) ─────
    // Removed: beforeinstallprompt handler was causing console warnings

    // ── Push notification permission (untuk pengumuman guru) ─────
    function requestNotifPermission() {
        if (!('Notification' in window)) return;
        const prefKey = 'cbt_notif_pref';
        const stored = localStorage.getItem(prefKey);
        if (stored === 'granted' || stored === 'denied') return; // sudah ditanya sebelumnya
        Notification.requestPermission().then(function (perm) {
            localStorage.setItem(prefKey, perm);
            if (perm === 'granted') showToast('Notifikasi diaktifkan');
        });
    }
    // Tampilkan tombol notifikasi setelah 5 detik (user sudah familiar)
    setTimeout(requestNotifPermission, 5000);

    // ── Update badge/install status UI ────────────────────────────
    function updateSwStatus(reg) {
        const el = document.getElementById('pwa-status');
        if (!el || !reg.active) return;
        const state = reg.installing ? 'Menginstal...' :
                      reg.waiting   ? 'Siap offline' : 'Aktif';
        el.textContent = 'PWA: ' + state;
    }

    // ── Toast notification helper ──────────────────────────────────
    function showToast(msg) {
        let t = document.getElementById('pwa-toast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'pwa-toast';
            t.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);' +
                              'background:#0f172a;color:#fff;padding:10px 20px;border-radius:12px;' +
                              'font-size:13px;font-weight:700;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,.3);' +
                              'transition:opacity .3s;pointer-events:none;max-width:90vw;text-align:center;';
            document.body.appendChild(t);
        }
        t.textContent = msg;
        t.style.opacity = '1';
        clearTimeout(t._timer);
        t._timer = setTimeout(function () { t.style.opacity = '0'; }, 3000);
    }
    window.__pwaToast = showToast;
})();
