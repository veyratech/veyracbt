/**
 * CBT Student Dashboard — CSP-safe vanilla JS (zero inline scripts)
 */
(function () {
    'use strict';

    /* ── Countdown data (read from hidden dataset element) ─── */
    var _CBT_COUNTDOWNS = [];
    var cdEl = document.getElementById('cbtCountdownData');
    if (cdEl) {
        try { _CBT_COUNTDOWNS = JSON.parse(cdEl.getAttribute('data-countdowns') || '[]'); } catch (_) {}
    }
    window._CBT_COUNTDOWNS = _CBT_COUNTDOWNS;

    /* ── Modal helpers ─────────────────────────────────────── */
    window.openStartModal = function (examId, examTitle, demoToken) {
        var elId      = document.getElementById('modalExamId');
        var elTitle   = document.getElementById('modalExamTitle');
        var elInput   = document.getElementById('modalTokenInput');
        var elHint    = document.getElementById('tokenHint');
        if (!elId || !elTitle) return;
        elId.value          = examId;
        elTitle.textContent = examTitle;
        elInput.value       = demoToken || '';
        if (elHint) {
            if (demoToken) {
                elHint.innerHTML = 'Token Pengawas (Mode Simulasi): <strong>' + demoToken + '</strong>';
                elHint.style.color = '#d97706';
            } else {
                elHint.innerHTML = '<span style="color:var(--std-text-muted);font-weight:500;">Minta token resmi kepada pengawas ruang ujian</span>';
                elHint.style.color = '';
            }
        }
        var modal = document.getElementById('modalStart');
        if (modal) modal.style.display = 'flex';
        setTimeout(function () { if (elInput) elInput.focus(); }, 150);
    };

    window.closeStartModal = function () {
        var modal = document.getElementById('modalStart');
        if (!modal) return;
        modal.style.display = 'none';
        var input = document.getElementById('modalTokenInput');
        if (input) input.value = '';
    };

    window.closeModal = window.closeStartModal;

    /* ── ESC key closes modal ──────────────────────────────── */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) window.closeStartModal();
    });

    /* ── Stat period switcher (Hari Ini / Mendatang / Selesai) ─ */
    window.switchStatPeriod = function (btn) {
        var period = btn.getAttribute('data-stat-period');
        document.querySelectorAll('.ms-switcher button').forEach(function (b) {
            b.classList.remove('active');
        });
        btn.classList.add('active');
        document.querySelectorAll('.ms-tab-content').forEach(function (row) {
            if (row.getAttribute('data-tab') === period) {
                row.classList.add('active');
            } else {
                row.classList.remove('active');
                row.setAttribute('style', 'display:none;');
            }
        });
    };

    /* ── Mobile bottom-nav active state ────────────────────── */
    (function initBottomNav() {
        var path = window.location.pathname;
        var btns = document.querySelectorAll('.mb-btn');
        btns.forEach(function (b) { b.classList.remove('active'); });
        if (path.indexOf('dashboard') !== -1)           btns[0] && btns[0].classList.add('active');
        else if (path.indexOf('history') !== -1 || path.indexOf('values') !== -1) {
            btns[1] && btns[1].classList.add('active');
            btns[3] && btns[3].classList.add('active');
        } else if (path.indexOf('profile') !== -1) {
            btns[4] && btns[4].classList.add('active');
        }
    })();

    /* ── Delegated click handler ───────────────────────────── */
    document.addEventListener('click', function (e) {
        // "Kerjakan" floating action button
        var kerjakanBtn = e.target.closest('[data-start-exam]');
        if (kerjakanBtn) {
            e.preventDefault();
            window.startAnyExam();
            return;
        }
        // Modal open triggers
        var modalBtn = e.target.closest('[data-modal-open]');
        if (modalBtn) {
            e.preventDefault();
            var id    = parseInt(modalBtn.getAttribute('data-exam-id'), 10);
            var title = modalBtn.getAttribute('data-exam-title') || '';
            var token = modalBtn.getAttribute('data-exam-token') || '';
            window.openStartModal(id, title, token);
            return;
        }
        // Close modal via overlay or close button
        var overlay = e.target.id === 'modalStart';
        if (overlay) { window.closeStartModal(); return; }
        if (e.target.classList.contains('btn-close-modal')) {
            window.closeStartModal();
        }
    });

    /* ── startAnyExam ──────────────────────────────────────── */
    window.startAnyExam = function () {
        if (_CBT_COUNTDOWNS && _CBT_COUNTDOWNS.length > 0) {
            var cd = _CBT_COUNTDOWNS[0];
            window.openStartModal(cd.id, cd.title, '');
            return;
        }
        var firstBtn = document.querySelector('[data-modal-open]');
        if (firstBtn) firstBtn.click();
    };

    /* ── Countdown tickers ─────────────────────────────────── */
    function pad(n) { return String(n).padStart(2, '0'); }

    function bindCountdown(el, cd) {
        if (!el || !cd) return;
        function tick() {
            var diff = cd.end - Math.floor(Date.now() / 1000);
            if (diff <= 0) { el.textContent = '00:00:00'; return; }
            el.textContent = pad(Math.floor(diff / 3600)) + ':'
                           + pad(Math.floor((diff % 3600) / 60)) + ':'
                           + pad(diff % 60);
        }
        tick();
        setInterval(tick, 1000);
    }

    /* ── Init on DOM ready ─────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        // Bind countdown timers to .maehTimer elements (legacy support)
        if (_CBT_COUNTDOWNS && _CBT_COUNTDOWNS.length > 0) {
            document.querySelectorAll('.maehTimer').forEach(function (el) {
                var id = parseInt(el.getAttribute('data-cd-id'), 10);
                if (isNaN(id)) return;
                var cd = _CBT_COUNTDOWNS.find(function (c) { return c.id === id; });
                if (cd) bindCountdown(el, cd);
            });
        }
    });

})();
