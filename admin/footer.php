    </main>
</div>

<script>
// Auto dismiss flash alerts or common modal behaviors
document.querySelectorAll('[data-close-modal]').forEach(b => {
    b.addEventListener('click', (e) => {
        const modal = b.closest('.modal-backdrop');
        if (modal) modal.classList.remove('show');
    });
});
document.querySelectorAll('.modal-backdrop').forEach(m => {
    m.addEventListener('click', (e) => {
        if (e.target === m && !e.target.querySelector('form')) m.classList.remove('show');
    });
});
document.querySelectorAll('.modal-backdrop form').forEach(f => {
    if (f.hasAttribute('data-no-fetch')) return;
    f.addEventListener('submit', (e) => {
        e.preventDefault();
        // Refresh session keep-alive sebelum async submit
        new Image().src = '../api/ping.php?_=' + Date.now();
        const action = f.getAttribute('action');
        const url = action
            ? new URL(action, window.location.href)
            : new URL(window.location.href);
        const method = (f.method || 'GET').toUpperCase();
        const isFileUpload = /multipart\/form-data/i.test(f.enctype || '');
        const formData = new FormData(f);
        const headers = {};
        if (!isFileUpload) {
            headers['Content-Type'] = 'application/x-www-form-urlencoded;charset=UTF-8';
        }
        const fullUrl = url.toString();
        const btn = f.querySelector('button[type="submit"]');
        const origBtnText = btn ? btn.textContent : '';
        if (btn) { btn.disabled = true; btn.textContent = 'Memproses...'; }
        console.log('[CBT] Modal submit →', fullUrl, 'csrf=' + (!!formData.get('_csrf')), 'file=' + (formData.get('csv_file')?.name || 'none'));
        fetch(fullUrl, { method, body: formData, credentials: 'same-origin', headers })
            .then(r => {
                console.log('[CBT] Modal response status=' + r.status);
                const loc = r.headers.get('Location');
                if (r.ok && loc) {
                    window.location.replace(loc);
                } else if (r.ok) {
                    window.location.reload();
                } else {
                    return r.text().then(txt => Promise.reject(new Error("HTTP " + r.status + ': ' + txt.substring(0, 200))));
                }
            })
            .catch(function(err) {
                console.error('[CBT] Modal submit error:', err);
                alert('Permintaan gagal, silakan coba lagi.\n' + err.message);
                if (btn) { btn.disabled = false; btn.textContent = origBtnText; }
            });
    }, true);
});
console.log('[CBT] Modal submit handlers attached to ' + document.querySelectorAll('.modal-backdrop form').length + ' forms');

// Toggle mode gelap / terang (tersimpan di localStorage)
(function () {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var root = document.documentElement;
        var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('cbt_theme', next); } catch (e) {}
    });
})();

// Dropdown notifikasi & menu user (delegasi aman)
(function () {
    if (typeof window.toggleFanDropdown === 'function') {
        return; // Sudah ditangani secara responsif di header.php
    }
    function bindToggle(btnId, menuId) {
        var btn = document.getElementById(btnId);
        var menu = document.getElementById(menuId);
        if (!btn || !menu) return;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            document.querySelectorAll('.dropdown-menu.show').forEach(function (m) {
                if (m !== menu) m.classList.remove('show');
            });
            menu.classList.toggle('show');
        });
    }
    bindToggle('notifBell', 'notifMenu');
    bindToggle('userPillBtn', 'userMenu');
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.dropdown-wrap')) {
            document.querySelectorAll('.dropdown-menu.show').forEach(function (m) {
                m.classList.remove('show');
            });
        }
    });
})();

// Session timeout: ping every 60s, warn 2 min before logout
(function () {
    var timeout = parseInt('<?= Auth::getTimeout() ?>') * 1000;
    var warnBefore = parseInt('<?= Auth::getWarningBefore() ?>') * 1000;
    var lastActivity = <?= (int)($_SESSION['last_activity'] ?? time()) ?>;
    var warned = false;
    var modal = document.getElementById('idleTimeoutModal');
    var countdownEl = document.getElementById('idleCountdown');

    function ping() {
        // Gunakan Image beacon, bukan fetch, agar tidak men-trigger ERR_INTERNET_DISCONNECTED
        // palsu di Chrome saat DevTools buka/tutup (Chromium compositor glitch).
        new Image().src = '../api/ping.php?_=' + Date.now();
        if (typeof navigator.sendBeacon === 'function') {
            try { navigator.sendBeacon('../api/ping.php'); } catch (e) {}
        }
    }

    setInterval(ping, 60000);

    document.addEventListener('mousemove', function () { lastActivity = Math.floor(Date.now() / 1000); warned = false; if (modal) modal.style.display = 'none'; });
    document.addEventListener('keydown', function () { lastActivity = Math.floor(Date.now() / 1000); warned = false; if (modal) modal.style.display = 'none'; });
    document.addEventListener('click', function () { lastActivity = Math.floor(Date.now() / 1000); warned = false; if (modal) modal.style.display = 'none'; });

    setInterval(function () {
        var elapsed = Math.floor(Date.now() / 1000) - lastActivity;
        var remaining = timeout - elapsed * 1000;
        var warnThreshold = timeout - warnBefore;
        if (elapsed * 1000 >= warnThreshold && !warned) {
            warned = true;
            if (modal) {
                modal.style.display = 'flex';
                var secsLeft = Math.ceil((remaining) / 1000);
                if (countdownEl) countdownEl.textContent = Math.max(1, Math.ceil(secsLeft / 60));
            }
        }
        if (elapsed * 1000 >= timeout) {
            window.location.href = '../logout.php';
        }
    }, 10000);
})();

// ── Page Transition: fade out → navigate → fade in on next load ──
(function () {
    var transitioning = false;
    var mainEl = document.querySelector('.fan-main-content');

    function startExit() {
        if (!mainEl || transitioning) return;
        transitioning = true;
        mainEl.style.animation = 'none';          // break any enter animation
        mainEl.offsetHeight;                       // force reflow
        mainEl.style.opacity = '0';
        mainEl.style.transform = 'translateY(6px)';
        mainEl.style.transition = 'opacity .2s ease, transform .2s ease';
        setTimeout(function () { transitioning = false; }, 220);
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[href]');
        if (!link || !mainEl) return;
        var href = link.getAttribute('href');
        // Hanya tangani link internal yang bukan modal / target="_blank" / download / hash-only
        if (link.hasAttribute('target') || link.hasAttribute('download') || !href || href.startsWith('#') || href.startsWith('javascript:') || href.startsWith('mailto:')) return;
        // Abaikan jika klik di dalam form atau tombol aksi halaman ini
        if (link.closest('form') || link.closest('.modal-backdrop')) return;
        e.preventDefault();
        startExit();
        setTimeout(function () { window.location.href = href; }, 230);
    });
})();
</script>

</body>
</html>
