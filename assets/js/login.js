/**
 * CBT Login Page JS — CSP-safe (no inline <script> needed)
 */
(function () {
    'use strict';

    /* ── Toggle password visibility ──────────────────────── */
    var btn  = document.getElementById('togglePwd');
    var input = document.getElementById('passwordInput');
    var icon = document.getElementById('eyeIcon');
    if (btn && input && icon) {
        btn.addEventListener('click', function () {
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>';
            }
        });
    }

    /* ── Tenant selection & localStorage enhancer ────────── */
    var searchInput = document.getElementById('schoolSearchInput');
    var selectEl    = document.getElementById('tenantCodeSelect');
    var formEl      = document.getElementById('studentLoginForm');
    var savedNotice = document.getElementById('savedSchoolNotice');
    var activeHidden = document.getElementById('tenantCodeInput');

    // Remember tenant when auto-detected
    if (activeHidden && activeHidden.value) {
        try { localStorage.setItem('cbt_remembered_tenant', activeHidden.value); } catch (_) {}
    }

    if (selectEl) {
        var isResetting = window.location.search.indexOf('ganti') !== -1;
        // Restore remembered tenant
        if (!isResetting) {
            try {
                var remembered = localStorage.getItem('cbt_remembered_tenant');
                if (remembered && (!selectEl.value || selectEl.value === '')) {
                    for (var i = 0; i < selectEl.options.length; i++) {
                        if (selectEl.options[i].value === remembered) {
                            selectEl.selectedIndex = i;
                            if (savedNotice) savedNotice.style.display = 'block';
                            break;
                        }
                    }
                }
            } catch (_) {}
        }
        // Save tenant on submit
        if (formEl) {
            formEl.addEventListener('submit', function () {
                if (selectEl.value) {
                    try { localStorage.setItem('cbt_remembered_tenant', selectEl.value); } catch (_) {}
                }
            });
        }
        // Quick-filter school names
        if (searchInput) {
            var originalOptions = Array.from(selectEl.options);
            searchInput.addEventListener('input', function () {
                var query = this.value.trim().toLowerCase();
                selectEl.innerHTML = '';
                var matched = 0;
                originalOptions.forEach(function (opt) {
                    var dataText = (opt.dataset && opt.dataset.name) ? opt.dataset.name : opt.text.toLowerCase();
                    if (opt.value === '' || dataText.indexOf(query) !== -1) {
                        selectEl.appendChild(opt.cloneNode(true));
                        if (opt.value !== '') matched++;
                    }
                });
                if (matched === 1) {
                    for (var j = 0; j < selectEl.options.length; j++) {
                        if (selectEl.options[j].value !== '') {
                            selectEl.selectedIndex = j;
                            break;
                        }
                    }
                }
            });
        }
    }

    /* ── Demo login helper (exposed globally) ────────────── */
    window.fillStudentDemo = function (code, nis) {
        var sel   = document.getElementById('tenantCodeSelect');
        var u     = document.getElementById('nisInput');
        var p     = document.getElementById('passwordInput');
        if (sel)   sel.value   = code;
        if (u)     u.value     = nis;
        if (p)     { p.value   = 'password'; p.focus(); }
    };
})();
