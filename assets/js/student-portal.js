/**
 * Student Portal — CSP-compliant (no inline scripts or event attributes)
 * All interactions use data-attributes + delegated listeners.
 */
(function () {
    'use strict';

    /* ── Sidebar toggle (header.php) ─────────────────────────── */
    document.addEventListener('click', function (e) {
        // Open sidebar
        var openBtn = e.target.closest('[data-open-sidebar]');
        if (openBtn) {
            e.preventDefault();
            var sb = document.getElementById('sbNav');
            var ov = document.getElementById('sbOverlay');
            if (sb) sb.classList.add('open');
            if (ov) ov.classList.add('show');
            document.body.style.overflow = 'hidden';
            return;
        }
        // Close sidebar
        var closeBtn = e.target.closest('[data-close-sidebar]');
        if (closeBtn || (e.target.id === 'sbOverlay')) {
            e.preventDefault();
            var sb2 = document.getElementById('sbNav');
            var ov2 = document.getElementById('sbOverlay');
            if (sb2) sb2.classList.remove('open');
            if (ov2) ov2.classList.remove('show');
            document.body.style.overflow = '';
            return;
        }
        // ID card modal close
        var icClose = e.target.closest('[data-close-idcard]');
        if (icClose) { closeIdCardModal(); return; }
        // Modal close buttons (exam start modal)
        var modalClose = e.target.closest('[data-modal-close]');
        if (modalClose) { closeModal(); return; }
        // Click on modal overlay itself → close
        if (e.target.id === 'modalStart') { closeModal(); return; }
    });

    /* ── ID Card Modal ─────────────────────────────────────── */
    window.openIdCardModal = function () {
        var m = document.getElementById('modalIdCard');
        if (m) m.style.display = 'flex';
    };
    window.closeIdCardModal = function () {
        var m = document.getElementById('modalIdCard');
        if (m) m.style.display = 'none';
    };

    /* ── Print ID Card (opens clean print window) ─────────── */
    window.printIdCard = function () {
        var btn = document.querySelector('[data-print-idcard]');
        if (!btn) return;
        try {
            var d = JSON.parse(btn.getAttribute('data-card-data') || '{}');
            var html = buildPrintPage(d);
            var w = window.open('', '_blank', 'width=820,height=560');
            if (w) { w.document.open(); w.document.write(html); w.document.close(); }
            else { alert('Popup diblokir browser. Izinkan popup untuk halaman ini, lalu coba lagi.'); }
        } catch (ex) { console.warn('[ID Card] print error:', ex); }
    };

    function escHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function buildPrintPage(d) {
        var rulesHtml = '';
        (d.rules || []).slice(0, 4).forEach(function(r){
            rulesHtml += '<li>' + escHtml(r) + '</li>';
        });
        var photoEl = d.photo
            ? '<img src="' + d.photo + '" class="pbi">'
            : '<div class="pinit">' + escHtml(d.initials) + '</div>';
        return [
            '<!DOCTYPE html><html lang="id"><head>',
            '<meta charset="UTF-8"><title>Cetak Kartu Peserta</title>',
            '<link rel="preconnect" href="https://fonts.googleapis.com">',
            '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800&display=swap" rel="stylesheet">',
            '<style>@keyframes sh{0%{background-position:-200%}100%{background-position:200%}}',
            'body{margin:0;padding:24px;font-family:Inter,sans-serif;background:#fff}',
            '.pw{display:flex;gap:32px;justify-content:center;flex-wrap:wrap}',
            '.cc{width:340px;height:220px;border-radius:16px;overflow:hidden;position:relative;background:#0d1f3c;border:1px solid rgba(255,255,255,.1);box-shadow:0 8px 32px rgba(0,0,0,.25);display:flex;flex-direction:column;justify-content:space-between}',
            '.cw{height:82px;background:linear-gradient(135deg,'+d.primary+',#0ea5e9 50%,'+d.secondary+');position:relative;overflow:hidden}.cw::before{content:"";position:absolute;inset:0;background:radial-gradient(ellipse at 30% 0%,rgba(255,255,255,.18) 0%,transparent 60%)}.wp{position:absolute;inset:0;opacity:.07;background-image:repeating-linear-gradient(45deg,transparent,transparent 3px,rgba(255,255,255,.5) 3px,rgba(255,255,255,.5) 4px),repeating-linear-gradient(-45deg,transparent,transparent 3px,rgba(255,255,255,.5) 3px,rgba(255,255,255,.5) 4px)}.wsb{position:absolute;top:10px;right:12px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:3px 8px;font-size:7px;font-weight:800;color:#fff;text-transform:uppercase;letter-spacing:.8px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:140px}',
            '.pb{position:absolute;top:50px;left:50%;transform:translateX(-50%);width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#1e3a5f,#0d1f3c);border:2.5px solid rgba(255,255,255,.25);box-shadow:0 0 20px rgba(14,165,233,.3),0 4px 16px rgba(0,0,0,.4);overflow:hidden;display:flex;align-items:center;justify-content:center;z-index:3}',
            '.pbi{width:100%;height:100%;object-fit:cover}.pinit{font-size:20px;font-weight:900;color:rgba(255,255,255,.45)}',
            '.pf{margin-top:34px;padding:0 14px;text-align:center;flex:1;display:flex;flex-direction:column;justify-content:space-between;position:relative;z-index:2}',
            '.pn{font-size:11px;font-weight:800;color:#f1f5f9;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
            '.prt{display:inline-block;background:rgba(14,165,233,.15);border:1px solid rgba(14,165,233,.3);border-radius:999px;padding:2px 10px;font-size:6.5px;font-weight:800;color:#38bdf8;text-transform:uppercase;letter-spacing:1.2px;margin-top:4px}',
            '.pig{display:grid;grid-template-columns:1fr 1fr;gap:3px 8px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:8px;padding:5px 7px;margin:4px auto 0;width:100%}.pic{display:flex;flex-direction:column;align-items:center;text-align:center}.pil{font-size:5.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px}.piv{font-size:8px;font-weight:700;color:#e2e8f0;margin-top:1px}.piv.pw2{color:#f87171;font-family:monospace;letter-spacing:1px}',
            '.pba{display:flex;flex-direction:column;align-items:center;margin-top:2px}',
            '.cwb{height:16px;background:linear-gradient(90deg,'+d.primary+','+d.secondary+','+d.primary+');background-size:200% 100%;animation:sh 3s linear infinite;display:flex;align-items:center;justify-content:center;position:relative}.cwb::before{content:"";position:absolute;inset:0;background:repeating-linear-gradient(90deg,transparent,transparent 2px,rgba(255,255,255,.06) 2px,rgba(255,255,255,.06) 4px)}.cbt{font-size:6px;font-weight:800;color:rgba(255,255,255,.9);letter-spacing:1.5px;text-transform:uppercase;position:relative;z-index:1}',
            '.cbi{padding:14px 16px;height:100%;display:flex;flex-direction:column;position:relative;z-index:2}.cbtbar{height:4px;border-radius:2px;margin:-14px -16px 10px -16px;background:linear-gradient(90deg,'+d.primary+','+d.secondary+')}.cs2t{font-size:8px;font-weight:800;color:#f1f5f9;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid rgba(255,255,255,.08)}.csr{list-style:none;padding:0;margin:0 0 8px 0}.csr li{font-size:6.5px;color:#94a3b8;line-height:1.5;padding-left:8px;position:relative;margin-bottom:3px}.csr li::before{content:">";position:absolute;left:0;color:#0ea5e9;font-weight:700}',
            '.csig{text-align:center;margin-top:auto;padding-top:8px;border-top:1px dashed rgba(255,255,255,.08)}.cstl{font-size:6px;color:#64748b;margin-bottom:6px}.csnm{font-size:8px;font-weight:800;color:#f1f5f9;text-decoration:underline;text-underline-offset:3px}',
            '@media print{body{padding:16px}.no-print{display:none!important}}',
            '</style></head><body>',
            '<div class="pw">',
            '<div class="cc"><div class="cw"><div class="wp"></div><div class="wsb">'+escHtml(d.school)+'</div></div>',
            '<div class="pb">'+photoEl+'</div>',
            '<div class="pf"><div><div class="pn">'+escHtml(d.name)+'</div><div class="prt">PESERTA UJIAN CBT</div>',
            '<div class="pig"><div class="pic"><span class="pil">NIS</span><span class="piv">'+escHtml(d.nis)+'</span></div><div class="pic"><span class="pil">Kelas</span><span class="piv">'+escHtml(d.class)+'</span></div><div class="pic"><span class="pil">Sandi</span><span class="piv pw2">&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;&#x2022;</span></div><div class="pic"><span class="pil">Tahun</span><span class="piv">'+escHtml(d.year)+'</span></div></div>',
            '<div class="pba">'+d.barcode+'</div></div>',
            '<div class="cwb"><span class="cbt">'+escHtml(d.title)+'</span></div></div>',
            '<div class="cc"><div class="cbi"><div class="cbtbar"></div>',
            '<div class="cs2t">Tata Tertib Ujian</div><ul class="csr">'+rulesHtml+'</ul>',
            (d.contact?'<div style="background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:6px;padding:5px 7px;font-size:6.5px;color:#64748b;margin-top:4px;"><strong>Layanan:</strong> '+escHtml(d.contact)+' &bull; <strong>Tahun:</strong> '+escHtml(d.year)+'</div>':''),
            '<div class="csig"><div class="cstl">'+escHtml(d.signerTitle)+',</div><div class="csnm">'+escHtml(d.signerName)+'</div></div></div></div>',
            '</div>',
            '<div class="no-print" style="text-align:center;margin-top:24px;">',
            '<button onclick="window.print()" style="padding:12px 32px;border-radius:12px;border:none;background:linear-gradient(135deg,#0284c7,#0ea5e9);color:#fff;font-size:14px;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(2,132,199,.3);font-family:Inter,sans-serif;">&#x1F5A8; Cetak Sekarang</button>',
            ' &nbsp; <button onclick="window.close()" style="padding:12px 32px;border-radius:12px;border:1px solid #e2e8f0;background:#fff;color:#475569;font-size:14px;font-weight:600;cursor:pointer;font-family:Inter,sans-serif;">Tutup</button>',
            '</div></body></html>'
        ].join('');
    }

    /* ── Exam Start Modal ──────────────────────────────────── */
    window.openStartModal = function (id, title, token) {
        var eid = document.getElementById('modalExamId');
        var etitle = document.getElementById('modalExamTitle');
        var inp = document.getElementById('modalTokenInput');
        var hint = document.getElementById('tokenHint');
        var modal = document.getElementById('modalStart');
        if (!eid || !modal) return;
        eid.value = id;
        if (etitle) etitle.textContent = title;
        if (inp) inp.value = token || '';
        if (hint) {
            if (token) { hint.textContent = 'Token Pengawas: ' + token; hint.style.color = '#d97706'; }
            else { hint.textContent = 'Minta token resmi kepada pengawas.'; hint.style.color = '#0284c7'; }
        }
        modal.classList.add('show');
        if (inp) setTimeout(function(){ inp.focus(); }, 150);
    };
    window.closeModal = function () {
        var m = document.getElementById('modalStart');
        if (m) m.classList.remove('show');
    };
    window.startAnyExam = function () {
        var cdEl = document.getElementById('cbtCountdownData');
        if (!cdEl) return;
        try {
            var cd = JSON.parse(cdEl.getAttribute('data-countdowns') || '[]');
            if (cd.length > 0) window.openStartModal(cd[0].id, cd[0].title, '');
        } catch(e) {}
    };

    /* ── Live Countdown Timer ──────────────────────────────── */
    (function initCountdown() {
        var cdEl = document.getElementById('cbtCountdownData');
        if (!cdEl) return;
        var countdowns = [];
        try { countdowns = JSON.parse(cdEl.getAttribute('data-countdowns') || '[]'); } catch(e) {}
        if (countdowns.length === 0) return;
        function pad(n) { return String(n).padStart(2, '0'); }
        function tick() {
            var el = document.getElementById('lcTime');
            if (!el) return;
            var cd = countdowns[0];
            if (!cd) return;
            var diff = cd.end - Math.floor(Date.now() / 1000);
            if (diff <= 0) { el.textContent = '00:00:00'; return; }
            el.textContent = pad(Math.floor(diff / 3600)) + ':' + pad(Math.floor((diff % 3600) / 60)) + ':' + pad(diff % 60);
        }
        var titleEl = document.getElementById('lcTitle');
        var subjEl = document.getElementById('lcSubject');
        if (titleEl && countdowns[0]) titleEl.textContent = countdowns[0].title;
        if (subjEl && countdowns[0]) subjEl.textContent = countdowns[0].subject;
        tick();
        setInterval(tick, 1000);
        var lcBtn = document.getElementById('lcStartBtn');
        if (lcBtn) lcBtn.addEventListener('click', function(e) { e.preventDefault(); window.startAnyExam(); });
    })();

    /* ── Theme Toggle + Tenant Auto-Submit ─────────────────── */
    (function initTheme() {
        var saved = localStorage.getItem('cbt_theme');
        if (saved === 'dark' || (!saved && matchMedia('(prefers-color-scheme:dark)').matches)) {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
        function toggleTheme() {
            var d = document.documentElement;
            var next = d.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            d.setAttribute('data-theme', next);
            try { localStorage.setItem('cbt_theme', next); } catch(x) {}
        }
        // Support both #themeToggle (student header) and [data-toggle-theme] buttons
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('#themeToggle, [data-toggle-theme]');
            if (btn) { e.preventDefault(); toggleTheme(); }
        });
        // Auto-submit tenant select on login page
        var sel = document.getElementById('tenantSelect');
        if (sel) sel.addEventListener('change', function(){ this.form.submit(); });
    })();

    /* ── Fullscreen toggle (topbar #btnFullscreen) ─────────── */
    (function initFullscreenBtn() {
        var btn = document.getElementById('btnFullscreen');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var el = document.documentElement;
            var isFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
            try {
                if (isFs) {
                    (document.exitFullscreen || document.webkitExitFullscreen).call(document);
                } else if (el.requestFullscreen) {
                    el.requestFullscreen().catch(function () {});
                } else if (el.webkitRequestFullscreen) {
                    el.webkitRequestFullscreen();
                }
            } catch (x) {}
        });
    })();

    /* ── ESC key closes modals ─────────────────────────────── */
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            closeModal();
            closeIdCardModal();
        }
    });

})();
