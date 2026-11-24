        <!-- Idle Timeout Warning Modal -->
        <div class="modal-backdrop" id="idleTimeoutModal" style="display: none; position: fixed; inset: 0; z-index: 9999; background: rgba(0,0,0,0.6); align-items: center; justify-content: center;">
            <div class="modal-box" style="background: #ffffff; border-radius: 24px; padding: 32px; max-width: 400px; width: 90%; text-align: center; box-shadow: 0 25px 60px rgba(0,0,0,0.3);">
                <div style="font-size: 48px; margin-bottom: 16px;">&#9200;</div>
                <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin-bottom: 8px;">Sesi Akan Berakhir</h3>
                <p style="color: #64748b; font-size: 13.5px; margin-bottom: 20px;">Anda tidak aktif selama beberapa saat. Sesi Anda akan berakhir dalam <strong id="idleCountdown">2</strong> menit.</p>
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <a href="index.php" class="btn btn-primary" style="flex: 1; border-radius: 20px;">Lanjutkan Sesi</a>
                    <a href="../logout.php" class="btn btn-outline" style="flex: 1; border-radius: 20px;">Logout</a>
                </div>
            </div>
        </div>

    </main> <!-- /boardto-content-canvas -->
    </div> <!-- /boardto-main -->
</div> <!-- /boardto-app-container -->

<script>
// Modal closer
document.querySelectorAll('[data-close-modal]').forEach(b => {
    b.addEventListener('click', (e) => {
        const modal = b.closest('.modal-backdrop');
        if (modal) modal.classList.remove('show');
    });
});

// Session timeout: ping every 60s, warn 2 min before logout
(function () {
    var timeout = parseInt('<?= Auth::getTimeout() ?>') * 1000;
    var warnBefore = parseInt('<?= Auth::getWarningBefore() ?>') * 1000;
    var lastActivity = <?= (int)($_SESSION['last_activity'] ?? time()) ?>;
    var warned = false;
    var modal = document.getElementById('idleTimeoutModal');
    var countdownEl = document.getElementById('idleCountdown');

    function ping() {
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
</script>

</body>
</html>
