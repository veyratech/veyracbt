/**
 * CBT EXAM ENGINE JAVASCRIPT
 * Real-time autosave, dynamic question switcher, timer countdown & anti-cheat proctoring
 */

class CBTEngine {
    constructor(config) {
        this.sessionId = config.sessionId;
        this.tenantId = config.tenantId;
        this.examId = config.examId;
        this.questions = config.questions || [];
        this.currentIndex = 0;
        this.remainingSeconds = config.remainingSeconds || 3600;
        this.maxViolations = config.maxViolations || 3;
        this.violations = config.violations || 0;
        this.apiUrl = config.apiUrl || 'api-save.php';
        this.finishUrl = config.finishUrl || 'finish.php';
        this.csrfToken = config.csrfToken || '';
        this.timerInterval = null;
        this.isSubmitting = false;
        this.warn5MinShown = false;
        this.warn2MinShown = false;
        this.warn1MinShown = false;

        this.lastBroadcastId = 0;
        this.extraTimeMinutes = config.extraTimeMinutes || 0;

        this.offlineQueue = [];
        this.isFlushingQueue = false;
        this.currentFontSize = localStorage.getItem('cbt_font_size') || 'medium';
        // Counter kegagalan berturut-turut: satu heartbeat/save gagal tidak langsung tandai offline, perlu 3x gagal; menghindari false-positive saat hosting lambat
        this.consecutiveFailures = 0;

        this.initElements();
        this.initEvents();
        this.initNetworkListeners();
        this.loadOfflineQueue();
        this.initFontResizer();
        this.applyFontSize(this.currentFontSize);
        this.initBeforeUnload();
        this.startTimer();
        this.initAntiCheat();
        this.initFullscreen();
        this.renderQuestion(0);
        this.updateNavGrid();
        this.initHeartbeat();
        this.initWebcam();
    }

    initElements() {
        this.elTimer = document.getElementById('timerDisplay');
        this.elTimerBox = document.getElementById('timerBox');
        this.elQNumber = document.getElementById('qNumberDisplay');
        this.elQText = document.getElementById('qTextDisplay');
        this.elOptions = document.getElementById('optionsContainer');
        this.elBtnPrev = document.getElementById('btnPrev');
        this.elBtnNext = document.getElementById('btnNext');
        this.elBtnDoubt = document.getElementById('btnDoubt');
        this.elBtnFinish = document.getElementById('btnFinish');
        this.elNavGrid = document.getElementById('questionGrid');
        this.elViolationBanner = document.getElementById('violationBanner');
        this.elViolationText = document.getElementById('violationText');

        // Modals
        this.modalConfirm = document.getElementById('modalConfirmFinish');
        this.modalWarning = document.getElementById('modalWarningViolation');
        this.elTimerWarningBanner = document.getElementById('timerWarningBanner');

        // Network & Enhancements
        this.elNetworkBadge = document.getElementById('networkBadge');
        this.elNetworkText = document.getElementById('networkText');
        this.elOfflineBanner = document.getElementById('offlineBanner');
        this.modalImageZoom = document.getElementById('modalImageZoom');
        this.zoomImageTarget = document.getElementById('zoomImageTarget');
    }

    initEvents() {
        if (this.elBtnPrev) {
            this.elBtnPrev.addEventListener('click', () => {
                if (this.currentIndex > 0) this.renderQuestion(this.currentIndex - 1);
            });
        }

        if (this.elBtnNext) {
            this.elBtnNext.addEventListener('click', () => {
                if (this.currentIndex < this.questions.length - 1) {
                    this.renderQuestion(this.currentIndex + 1);
                }
            });
        }

        if (this.elBtnDoubt) {
            this.elBtnDoubt.addEventListener('click', () => this.toggleDoubt());
        }

        if (this.elBtnFinish) {
            this.elBtnFinish.addEventListener('click', () => this.openFinishModal());
        }

        // Prevent double-click while loading
        this.elBtnFinish?.addEventListener('click', () => {
            if (this.isSubmitting) {
                this.elBtnFinish.disabled = true;
            }
        });

        // Close modal buttons
        document.querySelectorAll('[data-close-modal]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const target = btn.closest('.modal-backdrop');
                if (target) target.classList.remove('show');
            });
        });

        // Submit form inside modal
        const btnSubmitFinal = document.getElementById('btnSubmitFinal');
        if (btnSubmitFinal) {
            btnSubmitFinal.addEventListener('click', () => this.submitExam());
        }
    }

    startTimer() {
        this.updateTimerDisplay();
        this.timerInterval = setInterval(() => {
            if (this.remainingSeconds <= 0) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
                alert('Waktu ujian telah habis! Sistem akan mengumpulkan jawaban Anda secara otomatis.');
                this.submitExam();
                return;
            }
            this.remainingSeconds--;
            this.updateTimerDisplay();
        }, 1000);
    }

    updateTimerDisplay() {
        if (!this.elTimer) return;
        const hours = Math.floor(this.remainingSeconds / 3600);
        const minutes = Math.floor((this.remainingSeconds % 3600) / 60);
        const seconds = this.remainingSeconds % 60;

        const hStr = String(hours).padStart(2, '0');
        const mStr = String(minutes).padStart(2, '0');
        const sStr = String(seconds).padStart(2, '0');

        this.elTimer.textContent = `${hStr}:${mStr}:${sStr}`;
        // Also update mobile inline timer if present (same countdown, different DOM node)
        const elMobileDigits = document.getElementById('timerDisplayMobile');
        if (elMobileDigits) elMobileDigits.textContent = `${hStr}:${mStr}:${sStr}`;

        if (this.remainingSeconds <= 60 && !this.warn1MinShown) {
            this.warn1MinShown = true;
            this.elTimerBox.className = 'timer-box critical';
            const elMobileBox = document.getElementById('timerBoxMobile');
            if (elMobileBox) elMobileBox.className = 'timer-inline-mobile timer-box critical';
            this.showTimerWarningModal('critical');
            this.playAudioWarning('critical');
        } else if (this.remainingSeconds <= 120 && this.remainingSeconds > 60 && !this.warn2MinShown) {
            this.warn2MinShown = true;
            this.elTimerBox.className = 'timer-box danger';
            const elMobileBox = document.getElementById('timerBoxMobile');
            if (elMobileBox) elMobileBox.className = 'timer-inline-mobile timer-box danger';
            this.showTimerWarningModal('danger');
            this.playAudioWarning('danger');
        } else if (this.remainingSeconds <= 300 && this.remainingSeconds > 120 && !this.warn5MinShown) {
            this.warn5MinShown = true;
            this.elTimerBox.className = 'timer-box warning';
            const elMobileBox = document.getElementById('timerBoxMobile');
            if (elMobileBox) elMobileBox.className = 'timer-inline-mobile timer-box warning';
            this.showTimerWarningModal('warning');
            this.playAudioWarning('warning');
        }
    }

    showTimerWarningModal(level) {
        const banners = {
            warning: { text: 'Hampir habis! Sisa waktu 5 menit. Segera selesaikan jawaban Anda.', cls: 'warning' },
            danger:  { text: 'WAKTU HABIS CEPAT! Sisa 2 menit!', cls: 'danger' },
            critical:{ text: '1 MENIT SISA! KUMPULKAN SEKAARNG!', cls: 'critical' }
        };

        if (this.elTimerWarningBanner) {
            const b = banners[level];
            this.elTimerWarningBanner.textContent = b.text;
            this.elTimerWarningBanner.className = 'timer-warning-banner ' + b.cls;
            this.elTimerWarningBanner.style.display = 'block';
            setTimeout(() => { this.elTimerWarningBanner.style.display = 'none'; }, 6000);
        }

        let modalId = 'modalTimerWarning';
        let modal = document.getElementById(modalId);
        if (!modal) {
            modal = document.createElement('div');
            modal.className = 'modal-backdrop';
            modal.id = modalId;
            modal.innerHTML = `
                <div class="modal-dialog">
                    <div class="modal-header" id="timerModalHeader">
                        <h3 style="font-size: 16px; font-weight: 700;" id="timerModalTitle">&#9200; Peringatan Waktu</h3>
                        <button type="button" class="btn btn-outline btn-sm" data-close-modal>&times;</button>
                    </div>
                    <div class="modal-body">
                        <p style="color: var(--text-body); font-size: 14px; line-height: 1.6; margin-bottom: 12px;" id="timerModalMessage"></p>
                        <div style="background: var(--bg-subtle); border-radius: 8px; padding: 12px; font-size: 13px; color: var(--text-muted); border-left: 4px solid #f59e0b;" id="timerModalTip">
                            &#9888; Periksa kembali jawaban Anda sebelum mengumpulkan.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-close-modal>Lanjutkan Menjawab</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        const header = document.getElementById('timerModalHeader');
        const title = document.getElementById('timerModalTitle');
        const msg = document.getElementById('timerModalMessage');
        const tip = document.getElementById('timerModalTip');

        if (level === 'critical') {
            header.style.background = '#450a0a';
            header.style.borderBottom = '1px solid #991b1b';
            title.style.color = '#fca5a5';
            title.textContent = '&#128293; Sisa Waktu 1 Menit!';
            msg.textContent = 'Waktu ujian hampir habis! Selesaikan semua jawaban Anda sekarang.';
            msg.style.color = '#ef4444';
            tip.style.borderLeftColor = '#ef4444';
        } else if (level === 'danger') {
            header.style.background = '#450a0a';
            header.style.borderBottom = '1px solid #991b1b';
            title.style.color = '#fca5a5';
            title.textContent = '&#9888; Sisa Waktu 2 Menit!';
            msg.textContent = 'Hanya tersisa 2 menit! Segera selesaikan dan periksa jawaban Anda.';
            msg.style.color = '#f87171';
            tip.style.borderLeftColor = '#f87171';
        } else {
            header.style.background = '#78350f';
            header.style.borderBottom = '1px solid #92400e';
            title.style.color = '#fcd34d';
            title.textContent = '&#9200; Sisa Waktu 5 Menit!';
            msg.textContent = 'Sisa waktu 5 menit! Segera selesaikan jawaban Anda.';
            msg.style.color = '#fbbf24';
            tip.style.borderLeftColor = '#fbbf24';
        }

        modal.classList.add('show');

        document.querySelectorAll('[data-close-modal]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const target = e.target.closest('.modal-backdrop');
                if (target && target.id === modalId) {
                    target.classList.remove('show');
                }
            }, { once: true });
        });
    }

    playAudioWarning(level) {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);

            if (level === 'critical') {
                osc.frequency.value = 880;
                gain.gain.value = 0.3;
                osc.start();
                osc.stop(ctx.currentTime + 0.15);
                setTimeout(() => {
                    const o2 = ctx.createOscillator();
                    const g2 = ctx.createGain();
                    o2.connect(g2);
                    g2.connect(ctx.destination);
                    o2.frequency.value = 880;
                    g2.gain.value = 0.3;
                    o2.start();
                    o2.stop(ctx.currentTime + 0.15);
                }, 200);
            } else if (level === 'danger') {
                osc.frequency.value = 660;
                gain.gain.value = 0.25;
                osc.start();
                osc.stop(ctx.currentTime + 0.2);
            } else {
                osc.frequency.value = 440;
                gain.gain.value = 0.2;
                osc.start();
                osc.stop(ctx.currentTime + 0.15);
            }
        } catch (e) {
            console.warn('Audio warning not supported');
        }
    }

    initFullscreen() {
        const el = document.documentElement;
        const badge = document.getElementById('fullscreenBadge');

        const updateBadge = (active) => {
            if (badge) {
                if (active) {
                    badge.classList.remove('lost');
                } else {
                    badge.classList.add('lost');
                }
            }
        };

        const requestFullscreen = () => {
            if (el.requestFullscreen) {
                el.requestFullscreen().then(() => updateBadge(true)).catch(() => {});
            } else if (el.webkitRequestFullscreen) {
                el.webkitRequestFullscreen();
                updateBadge(true);
            }
        };

        requestFullscreen();

        // Browser menolak fullscreen tanpa gestur pengguna → coba lagi
        // pada interaksi pengguna berikutnya (tanpa loop retry berputar).
        const onUserGesture = () => {
            if (document.fullscreenElement || document.webkitFullscreenElement) return;
            requestFullscreen();
        };
        document.addEventListener('pointerdown', onUserGesture);
        document.addEventListener('keydown', onUserGesture);

        const handleFullscreenChange = () => {
            const isFullscreen = !!(document.fullscreenElement || document.webkitFullscreenElement);
            if (isFullscreen) {
                updateBadge(true);
            } else {
                updateBadge(false);
                requestFullscreen();
                this.handleViolation('Keluar dari mode fullscreen');
            }
        };

        document.addEventListener('fullscreenchange', handleFullscreenChange);
        document.addEventListener('webkitfullscreenchange', handleFullscreenChange);
    }

    renderQuestion(index) {
        if (index < 0 || index >= this.questions.length) return;

        // Hentikan audio sebelumnya jika sedang diputar
        if (this._currentAudio) {
            try { this._currentAudio.pause(); } catch(e) {}
            this._currentAudio = null;
        }

        this.currentIndex = index;
        const q = this.questions[index];

        // Update header & text
        if (this.elQNumber) this.elQNumber.textContent = `Soal No. ${index + 1} dari ${this.questions.length}`;
        if (this.elQText) {
            let textHtml = q.question_text || '';

            // 1. Gambar Soal
            if (q.question_image) {
                const imgSrc = `../${q.question_image}`;
                textHtml += `
                    <div class="zoomable-image-wrap" onclick="window.engine.zoomImage('${imgSrc}')" title="Klik untuk memperbesar gambar">
                        <img src="${imgSrc}" alt="Gambar soal" class="zoomable-image">
                        <span class="zoom-hint-badge">🔍 Klik Perbesar</span>
                    </div>`;
            }

            // 2. Video Soal (YouTube atau Video File/URL)
            if (q.question_video && q.question_video.trim() !== '') {
                const vid = q.question_video.trim();
                const ytMatch = vid.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i);
                if (ytMatch && ytMatch[1]) {
                    textHtml += `
                        <div class="cbt-video-wrap">
                            <div class="cbt-video-header">🎬 Video Pendukung Soal (YouTube)</div>
                            <div class="cbt-video-iframe-box">
                                <iframe src="https://www.youtube-nocookie.com/embed/${ytMatch[1]}?rel=0&modestbranding=1" 
                                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                                        allowfullscreen></iframe>
                            </div>
                        </div>`;
                } else {
                    const vidSrc = vid.startsWith('http') ? vid : `../${vid}`;
                    textHtml += `
                        <div class="cbt-video-wrap">
                            <div class="cbt-video-header">🎬 Video Pendukung Soal</div>
                            <video controls controlsList="nodownload" playsinline preload="metadata" class="cbt-video-player">
                                <source src="${vidSrc}">
                                Browser Anda tidak mendukung pemutar video.
                            </video>
                        </div>`;
                }
            }

            // 3. Audio Listening Soal (dengan enforcement batas putar)
            if (q.question_audio && q.question_audio.trim() !== '') {
                const audioSrc = q.question_audio.startsWith('http') ? q.question_audio : `../${q.question_audio}`;
                const limit = parseInt(q.audio_play_limit, 10) || 0; // 0 = unlimited
                const storageKey = `cbt_audio_plays_${this.sessionId}_${q.id}`;
                let playCount = parseInt(sessionStorage.getItem(storageKey) || '0', 10);
                const isExceeded = (limit > 0 && playCount >= limit);

                textHtml += `
                    <div class="cbt-audio-card ${isExceeded ? 'disabled' : ''}" id="audioCard_${q.id}">
                        <div class="cbt-audio-header">
                            <div class="cbt-audio-title">
                                <span class="cbt-audio-icon">🎧</span>
                                <div>
                                    <div class="cbt-audio-label">Audio Listening Test</div>
                                    <div class="cbt-audio-hint">Dengarkan audio berikut untuk menjawab soal ini</div>
                                </div>
                            </div>
                            <div class="cbt-audio-badge ${isExceeded ? 'limit-reached' : ''}" id="audioBadge_${q.id}">
                                ${limit === 0 
                                    ? '♾️ Pemutaran Bebas' 
                                    : (isExceeded 
                                        ? '🚫 Batas Putar Habis (' + playCount + '/' + limit + 'x)' 
                                        : '▶️ Kesempatan: ' + (limit - playCount) + ' dari ' + limit + 'x')}
                            </div>
                        </div>
                        <div class="cbt-audio-body">
                            <audio id="cbtAudio_${q.id}" controls controlsList="nodownload" preload="metadata" 
                                   style="width: 100%; height: 42px; border-radius: 8px; ${isExceeded ? 'pointer-events:none; opacity:0.35;' : ''}">
                                <source src="${audioSrc}">
                                Browser Anda tidak mendukung elemen audio.
                            </audio>
                            <div class="cbt-audio-notice" id="audioNotice_${q.id}">
                                ${isExceeded 
                                    ? 'Batas pemutaran audio untuk butir soal ini telah habis (' + limit + ' kali). Audio telah dinonaktifkan.' 
                                    : (limit > 0 ? 'Perhatian: Audio hanya dapat diputar ' + limit + ' kali. Hitungan berkurang saat audio mulai diputar.' : '')}
                            </div>
                        </div>
                    </div>`;
            }

            this.elQText.innerHTML = textHtml;

            // Bind event listener untuk audio listening
            if (q.question_audio && q.question_audio.trim() !== '') {
                const audioEl = document.getElementById(`cbtAudio_${q.id}`);
                if (audioEl) {
                    this._currentAudio = audioEl;
                    const limit = parseInt(q.audio_play_limit, 10) || 0;
                    const storageKey = `cbt_audio_plays_${this.sessionId}_${q.id}`;

                    audioEl.addEventListener('play', () => {
                        if (limit > 0) {
                            let count = parseInt(sessionStorage.getItem(storageKey) || '0', 10);
                            if (count >= limit) {
                                audioEl.pause();
                                audioEl.currentTime = 0;
                                audioEl.style.pointerEvents = 'none';
                                audioEl.style.opacity = '0.35';
                                return;
                            }
                            if (!audioEl._countedThisPlay) {
                                audioEl._countedThisPlay = true;
                                count++;
                                sessionStorage.setItem(storageKey, String(count));
                                const badge = document.getElementById(`audioBadge_${q.id}`);
                                const notice = document.getElementById(`audioNotice_${q.id}`);
                                const rem = limit - count;
                                if (rem <= 0) {
                                    if (badge) {
                                        badge.className = 'cbt-audio-badge limit-reached';
                                        badge.textContent = `🚫 Batas Putar Habis (${count}/${limit}x)`;
                                    }
                                    if (notice) {
                                        notice.textContent = `Batas pemutaran audio untuk butir soal ini telah habis (${limit} kali).`;
                                    }
                                } else {
                                    if (badge) {
                                        badge.textContent = `▶️ Kesempatan: ${rem} dari ${limit}x`;
                                    }
                                }
                            }
                        }
                    });

                    audioEl.addEventListener('ended', () => {
                        if (limit > 0) {
                            let count = parseInt(sessionStorage.getItem(storageKey) || '0', 10);
                            if (count >= limit) {
                                audioEl.style.pointerEvents = 'none';
                                audioEl.style.opacity = '0.35';
                                const card = document.getElementById(`audioCard_${q.id}`);
                                if (card) card.classList.add('disabled');
                            }
                        }
                    });
                }
            }
        }

        // Render Options (PG) atau Essay textarea
        if (this.elOptions) {
            if (q.question_type === 'essay') {
                const essayVal = q.answer_text || '';
                this.elOptions.innerHTML = `
                    <div style="margin-bottom: 8px; font-size: 12.5px; color: var(--text-muted);">
                        Soal Essay — ketik jawaban Anda. Jawaban tersimpan otomatis dan dikoreksi oleh guru.
                    </div>
                    <textarea id="essayInput" rows="7" maxlength="8000"
                        style="width:100%; border:1px solid var(--border); border-radius:10px; background:var(--bg-card);
                               color:var(--text-body); padding:12px 14px; font-size:14px; font-family:inherit; resize:vertical;"
                        placeholder="Tulis jawaban essay Anda di sini...">${essayVal}</textarea>
                    <div id="essaySaveHint" style="font-size: 11.5px; color: var(--text-muted); margin-top: 6px;">&nbsp;</div>
                `;
                const ta = document.getElementById('essayInput');
                if (ta) {
                    let t = null;
                    ta.addEventListener('input', () => {
                        q.answer_text = ta.value;
                        clearTimeout(t);
                        t = setTimeout(() => this.saveEssayAnswer(q), 1200);
                        this.updateNavGrid();
                    });
                    ta.addEventListener('blur', () => {
                        clearTimeout(t);
                        if (q.answer_text !== (q._lastSavedEssay || '')) this.saveEssayAnswer(q);
                    });
                }
            } else {
            const choices = [
                { key: 'A', text: q.option_a },
                { key: 'B', text: q.option_b },
                { key: 'C', text: q.option_c },
                { key: 'D', text: q.option_d },
                { key: 'E', text: q.option_e }
            ];

            let html = '';
            choices.forEach(c => {
                if (c.text && c.text.trim() !== '') {
                    const isSelected = (q.student_answer === c.key);
                    html += `
                        <div class="option-item ${isSelected ? 'selected' : ''}" data-opt="${c.key}">
                            <div class="opt-badge">${c.key}</div>
                            <div class="opt-content">${c.text}</div>
                        </div>
                    `;
                }
            });
            this.elOptions.innerHTML = html;

            // Bind click options
            this.elOptions.querySelectorAll('.option-item').forEach(item => {
                item.addEventListener('click', () => {
                    const chosen = item.getAttribute('data-opt');
                    this.selectAnswer(chosen);
                });
            });
            }
        }

        // Update Prev / Next buttons
        if (this.elBtnPrev) this.elBtnPrev.disabled = (index === 0);
        if (this.elBtnNext) {
            if (index === this.questions.length - 1) {
                this.elBtnNext.style.display = 'none';
            } else {
                this.elBtnNext.style.display = 'inline-flex';
            }
        }

        // Update Doubt button
        if (this.elBtnDoubt) {
            if (q.is_doubt == 1) {
                this.elBtnDoubt.classList.add('active');
                this.elBtnDoubt.innerHTML = `<span>&#9745;</span> Ragu-ragu`;
            } else {
                this.elBtnDoubt.classList.remove('active');
                this.elBtnDoubt.innerHTML = `<span>&#9633;</span> Ragu-ragu`;
            }
        }

        this.updateNavGrid();
        this.applyFontSize(this.currentFontSize);

        // Render rumus matematika & simbol sains jika KaTeX tersedia
        if (window.renderMathInElement) {
            try {
                renderMathInElement(this.elQText, {
                    delimiters: [
                        {left: '$$', right: '$$', display: true},
                        {left: '$', right: '$', display: false},
                        {left: '\\(', right: '\\)', display: false},
                        {left: '\\[', right: '\\]', display: true}
                    ],
                    throwOnError: false
                });
                renderMathInElement(this.elOptions, {
                    delimiters: [
                        {left: '$$', right: '$$', display: true},
                        {left: '$', right: '$', display: false},
                        {left: '\\(', right: '\\)', display: false},
                        {left: '\\[', right: '\\]', display: true}
                    ],
                    throwOnError: false
                });
            } catch (e) {}
        }
    }

    isAnswered(q) {
        if (q.question_type === 'essay') {
            return !!(q.answer_text && q.answer_text.trim() !== '');
        }
        return !!(q.student_answer && q.student_answer !== '');
    }

    saveEssayAnswer(q) {
        q._lastSavedEssay = q.answer_text || '';
        const hint = document.getElementById('essaySaveHint');
        this.sendSaveRequest({
            action: 'save_answer',
            session_id: this.sessionId,
            question_id: q.id,
            answer: '',
            answer_text: q.answer_text || '',
            is_doubt: q.is_doubt ? 1 : 0
        }).then(() => {
            if (hint) hint.textContent = 'Tersimpan otomatis ✓';
        });
    }

    updateNavGrid() {
        if (!this.elNavGrid) return;
        let html = '';
        this.questions.forEach((q, idx) => {
            let statusClass = '';
            if (this.isAnswered(q)) {
                statusClass = (q.is_doubt == 1) ? 'doubt' : 'answered';
            } else if (q.is_doubt == 1) {
                statusClass = 'doubt';
            }
            const currentClass = (idx === this.currentIndex) ? 'current' : '';

            html += `<button type="button" class="q-btn ${statusClass} ${currentClass}" data-index="${idx}">${idx + 1}</button>`;
        });
        this.elNavGrid.innerHTML = html;

        // Bind clicks on grid
        this.elNavGrid.querySelectorAll('.q-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.getAttribute('data-index'), 10);
                this.renderQuestion(idx);
            });
        });
    }

    selectAnswer(optionKey) {
        const q = this.questions[this.currentIndex];
        q.student_answer = optionKey;

        // Update UI
        this.elOptions.querySelectorAll('.option-item').forEach(el => {
            if (el.getAttribute('data-opt') === optionKey) {
                el.classList.add('selected');
            } else {
                el.classList.remove('selected');
            }
        });

        this.updateNavGrid();

        // Autosave via fetch
        this.sendSaveRequest({
            action: 'save_answer',
            session_id: this.sessionId,
            question_id: q.id,
            answer: optionKey,
            is_doubt: q.is_doubt ? 1 : 0
        });
    }

    toggleDoubt() {
        const q = this.questions[this.currentIndex];
        q.is_doubt = (q.is_doubt == 1) ? 0 : 1;

        if (this.elBtnDoubt) {
            if (q.is_doubt == 1) {
                this.elBtnDoubt.classList.add('active');
                this.elBtnDoubt.innerHTML = `<span>&#9745;</span> Ragu-ragu`;
            } else {
                this.elBtnDoubt.classList.remove('active');
                this.elBtnDoubt.innerHTML = `<span>&#9633;</span> Ragu-ragu`;
            }
        }

        this.updateNavGrid();

        this.sendSaveRequest({
            action: 'toggle_doubt',
            session_id: this.sessionId,
            question_id: q.id,
            answer: q.student_answer || '',
            is_doubt: q.is_doubt
        });
    }

    /**
     * Penanganan pelanggaran proctoring (tab switch / blur / fullscreen).
     * Menjadi metode kelas agar bisa dipakai juga oleh initFullscreen().
     * Debounce 1.5 detik agar satu aksi siswa (blur + visibilitychange)
     * tidak dihitung dua kali.
     */
    handleViolation(reason) {
        if (this.isSubmitting) return;
        const now = Date.now();
        if (this._lastViolationAt && (now - this._lastViolationAt) < 1500) return;
        this._lastViolationAt = now;

        this.violations++;

        // Kirim log pelanggaran ke server (server yang menghitung & memblokir)
        this.sendSaveRequest({
            action: 'log_violation',
            session_id: this.sessionId,
            reason: reason,
            violations_count: this.violations
        });

        // Tampilkan warning banner & modal
        if (this.elViolationBanner && this.elViolationText) {
            this.elViolationText.textContent = `Peringatan! Pelanggaran terdeteksi (${this.violations}/${this.maxViolations}). Jangan berpindah aplikasi!`;
            this.elViolationBanner.style.display = 'block';
            setTimeout(() => { this.elViolationBanner.style.display = 'none'; }, 4000);
        }

        const modalVio = document.getElementById('modalWarningViolation');
        if (modalVio) {
            document.getElementById('vioCountText').textContent = `${this.violations} dari batas maksimal ${this.maxViolations}`;
            modalVio.classList.add('show');
        }

        if (this.violations >= this.maxViolations) {
            alert(`Anda telah melakukan pelanggaran berpindah tab/layar sebanyak ${this.violations} kali! Ujian otomatis dihentikan dan disubmit.`);
            this.submitExam();
        }
    }

    initAntiCheat() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.handleViolation('Membuka tab atau browser lain');
            }
        });

        window.addEventListener('blur', () => {
            // Jika tab sudah tersembunyi, visibilitychange sudah menghitungnya
            if (document.hidden) return;
            this.handleViolation('Kehilangan fokus layar ujian');
        });
    }

    openFinishModal() {
        if (this.isSubmitting) return;

        let answered = 0;
        let doubt = 0;
        let empty = 0;

        this.questions.forEach(q => {
            if (this.isAnswered(q)) {
                answered++;
            } else {
                empty++;
            }
            if (q.is_doubt == 1) {
                doubt++;
            }
        });

        const elAnswered = document.getElementById('statAnswered');
        const elDoubt = document.getElementById('statDoubt');
        const elEmpty = document.getElementById('statEmpty');
        if (elAnswered) elAnswered.textContent = answered;
        if (elDoubt) elDoubt.textContent = doubt;
        if (elEmpty) elEmpty.textContent = empty;

        if (this.modalConfirm) {
            this.modalConfirm.classList.add('show');
        }
    }

    async submitExam() {
        if (this.isSubmitting) return;
        this.isSubmitting = true;

        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
        if (this.webcamInterval) {
            clearInterval(this.webcamInterval);
            this.webcamInterval = null;
        }
        if (this.webcamStream) {
            try { this.webcamStream.getTracks().forEach(t => t.stop()); } catch (e) {}
            this.webcamStream = null;
        }
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }

        const btn = document.getElementById('btnSubmitFinal');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Memproses Jawaban...';
        }

        // Flush semua jawaban offline sebelum submit agar tidak hilang
        await this.flushOfflineQueue();

        try {
            const res = await fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'finish_exam',
                    session_id: this.sessionId,
                    csrf_token: this.csrfToken
                })
            });
            const data = await res.json();
            window.location.href = this.finishUrl + '?session_id=' + this.sessionId;
        } catch (err) {
            console.error('Error submitting exam:', err);
            window.location.href = this.finishUrl + '?session_id=' + this.sessionId;
        }
    }

    async sendSaveRequest(payload) {
        try {
            payload.csrf_token = this.csrfToken;
            const res = await fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            this.setOnlineStatus(true);
            this.flushOfflineQueue();

            if (!data.success && data.blocked) {
                alert('Sesi ujian telah diblokir / selesai!');
                window.location.href = this.finishUrl + '?session_id=' + this.sessionId;
            } else if (data.time_expired) {
                alert('Waktu ujian telah habis! Nilai Anda: ' + (Number(data.score) || 0).toFixed(1));
                window.location.href = this.finishUrl + '?session_id=' + this.sessionId;
            }
        } catch (err) {
            console.warn('Autosave sync failed, queueing offline:', err);
            // Satu gagal tidak langsung offline; kumpulkan ke queue dulu, tunggu re-connect
            this.consecutiveFailures = (this.consecutiveFailures || 0) + 1;
            if (this.consecutiveFailures >= 3) {
                this.setOnlineStatus(false);
            }
            this.enqueueOfflineSave(payload);
        }
    }

    initHeartbeat() {
        this.heartbeatInterval = setInterval(async () => {
            if (this.isSubmitting) return;
            try {
                const res = await fetch(this.apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'heartbeat',
                        session_id: this.sessionId,
                        last_broadcast_id: this.lastBroadcastId || 0,
                        csrf_token: this.csrfToken
                    })
                });
                const data = await res.json();
                if (!data || !data.success) {
                    this.setOnlineStatus(false);
                    return;
                }

                this.consecutiveFailures = 0;
                this.setOnlineStatus(true);
                this.flushOfflineQueue();

                // Cek status sesi dari pengawas
                if (data.session_status === 'blocked') {
                    window.location.href = this.finishUrl + '?session_id=' + this.sessionId;
                    return;
                }
                if (data.session_status === 'completed') {
                    window.location.href = this.finishUrl + '?session_id=' + this.sessionId;
                    return;
                }

                // Cek penambahan waktu kompensasi ekstra dari pengawas
                if (typeof data.extra_time_minutes === 'number' && data.extra_time_minutes > (this.extraTimeMinutes || 0)) {
                    const added = data.extra_time_minutes - (this.extraTimeMinutes || 0);
                    this.extraTimeMinutes = data.extra_time_minutes;
                    this.remainingSeconds = data.remaining_seconds || (this.remainingSeconds + (added * 60));
                    this.updateTimerDisplay();
                    this.showAnnouncement({
                        sender_name: 'Pengawas Ruang',
                        message: `Waktu ujian Anda telah ditambah kompensasi +${added} menit oleh pengawas!`
                    });
                } else if (data.remaining_seconds && Math.abs(data.remaining_seconds - this.remainingSeconds) > 30) {
                    // Sinkronisasi jika perbedaan waktu > 30 detik
                    this.remainingSeconds = data.remaining_seconds;
                    this.updateTimerDisplay();
                }

                // Tampilkan pesan siaran (broadcast) dari pengawas
                if (Array.isArray(data.announcements) && data.announcements.length > 0) {
                    data.announcements.forEach(a => {
                        if (a.id > this.lastBroadcastId) {
                            this.lastBroadcastId = a.id;
                            this.showAnnouncement(a);
                        }
                    });
                }
            } catch (err) {
                // Hanya tandai offline setelah beberapa kali gagal berturut-turut
                this.consecutiveFailures = (this.consecutiveFailures || 0) + 1;
                if (this.consecutiveFailures >= 3) {
                    this.setOnlineStatus(false);
                }
                console.warn('Heartbeat gagal (' + this.consecutiveFailures + '/3):', err.message);
            }
        }, 10000);
    }

    showAnnouncement(ann) {
        let container = document.getElementById('cbtAnnouncementToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'cbtAnnouncementToastContainer';
            container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;max-width:400px;display:flex;flex-direction:column;gap:10px;pointer-events:none;';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.style.cssText = 'pointer-events:auto;background:#0f172a;border-left:4px solid #3b82f6;color:#f8fafc;padding:14px 18px;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.6);font-size:13.5px;line-height:1.5;animation:slideIn 0.3s ease;';
        toast.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <span style="font-weight:700;color:#60a5fa;display:flex;align-items:center;gap:6px;">
                📢 Pesan Pengawas (${ann.sender_name || 'Pengawas Ruang'})
            </span>
            <button style="background:none;border:none;color:#94a3b8;cursor:pointer;font-size:18px;line-height:1;padding:0 4px;" onclick="this.parentElement.parentElement.remove()">&times;</button>
        </div>
        <div style="color:#e2e8f0;font-size:13px;">${ann.message}</div>`;

        container.appendChild(toast);
        setTimeout(() => {
            if (toast && toast.parentElement) toast.remove();
        }, 12000);
    }

    async initWebcam() {
        const video = document.getElementById('cbtWebcamVideo');
        const placeholder = document.getElementById('cbtWebcamPlaceholder');
        const badge = document.getElementById('webcamStatusBadge');
        const dot = document.getElementById('webcamIndicatorDot');

        if (!video || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            if (placeholder) placeholder.textContent = 'Browser tidak mendukung webcam';
            if (badge) badge.textContent = 'Tidak Didukung';
            return;
        }

        try {
            this.webcamStream = await navigator.mediaDevices.getUserMedia({
                video: { width: { ideal: 320 }, height: { ideal: 240 }, facingMode: 'user' },
                audio: false
            });
            video.srcObject = this.webcamStream;
            video.style.display = 'none'; // Sembunyikan feed live dari siswa, tapi kamera tetap aktif background
            video.onloadedmetadata = () => {
                video.play();
                if (placeholder) placeholder.style.display = 'none';
                if (badge) { badge.textContent = 'Aktif (Live)'; badge.style.color = '#10b981'; }
                if (dot) dot.style.background = '#10b981';
                // Mulai kirim live snapshot berkala
                this.startWebcamStreaming();
            };
        } catch (err) {
            console.warn('Webcam permission denied or error:', err);
            if (placeholder) placeholder.innerHTML = '⚠️ Akses kamera tidak diizinkan.<br><small style="color:#ef4444;">Pengawas memantau status ini.</small>';
            if (badge) { badge.textContent = 'Ditolak / Mati'; badge.style.color = '#ef4444'; }
            if (dot) { dot.style.background = '#ef4444'; dot.style.animation = 'none'; }
            this.sendWebcamStatus('denied');
        }
    }

    startWebcamStreaming() {
        // Ambil snapshot awal setelah 1 detik
        setTimeout(() => this.captureWebcamFrame(), 1000);
        // Interval kirim snapshot setiap 4 detik
        this.webcamInterval = setInterval(() => {
            if (this.isSubmitting) return;
            this.captureWebcamFrame();
        }, 4000);
    }

    captureWebcamFrame() {
        const video = document.getElementById('cbtWebcamVideo');
        const canvas = document.getElementById('cbtWebcamCanvas');
        if (!video || !canvas || video.readyState < 2) return;

        const ctx = canvas.getContext('2d');
        if (!ctx) return;

        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        try {
            const dataUrl = canvas.toDataURL('image/jpeg', 0.55);
            fetch(this.apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'upload_webcam',
                    session_id: this.sessionId,
                    image_data: dataUrl,
                    csrf_token: this.csrfToken
                })
            }).catch(() => {});
        } catch (e) {
            // Abaikan kesalahan capture
        }
    }

    sendWebcamStatus(st) {
        fetch(this.apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'webcam_status',
                session_id: this.sessionId,
                status: st,
                csrf_token: this.csrfToken
            })
        }).catch(() => {});
    }

    /* -------------------------------------------------------------- */
    /* FITUR BARU: NETWORK LISTENER & OFFLINE QUEUE SYNC               */
    /* -------------------------------------------------------------- */
    initNetworkListeners() {
        window.addEventListener('online', () => {
            this.setOnlineStatus(true);
            this.flushOfflineQueue();
        });
        window.addEventListener('offline', () => {
            this.setOnlineStatus(false);
        });
    }

    setOnlineStatus(isOnline) {
        if (this.elNetworkBadge) {
            if (isOnline) {
                this.elNetworkBadge.classList.remove('offline');
                if (this.elNetworkText) this.elNetworkText.textContent = 'ONLINE';
            } else {
                this.elNetworkBadge.classList.add('offline');
                if (this.elNetworkText) this.elNetworkText.textContent = 'OFFLINE';
            }
        }
        if (this.elOfflineBanner) {
            this.elOfflineBanner.style.display = isOnline ? 'none' : 'block';
        }
    }

    loadOfflineQueue() {
        try {
            const stored = localStorage.getItem('cbt_offline_queue_' + this.sessionId);
            if (stored) {
                this.offlineQueue = JSON.parse(stored) || [];
            }
        } catch (e) {
            this.offlineQueue = [];
        }
    }

    saveOfflineQueue() {
        try {
            localStorage.setItem('cbt_offline_queue_' + this.sessionId, JSON.stringify(this.offlineQueue));
        } catch (e) {}
    }

    enqueueOfflineSave(payload) {
        if (['upload_webcam', 'heartbeat', 'webcam_status'].includes(payload.action)) return;
        this.offlineQueue.push(payload);
        this.saveOfflineQueue();
    }

    async flushOfflineQueue() {
        if (this.isFlushingQueue || !this.offlineQueue || this.offlineQueue.length === 0) return;
        this.isFlushingQueue = true;
        while (this.offlineQueue.length > 0) {
            const item = this.offlineQueue[0];
            try {
                const res = await fetch(this.apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(item),
                    credentials: 'include'
                });
                if (res.ok) {
                    this.offlineQueue.shift();
                    this.saveOfflineQueue();
                } else {
                    break;
                }
            } catch (e) {
                break;
            }
        }
        this.isFlushingQueue = false;
        if (this.offlineQueue.length === 0) {
            try { localStorage.removeItem('cbt_offline_queue_' + this.sessionId); } catch (e) {}
        }
    }

    /* -------------------------------------------------------------- */
    /* FITUR BARU: FONT RESIZER (A- / A / A+)                          */
    /* -------------------------------------------------------------- */
    initFontResizer() {
        document.querySelectorAll('.btn-font-resizer').forEach(btn => {
            btn.addEventListener('click', () => {
                const size = btn.getAttribute('data-font');
                if (size) this.applyFontSize(size);
            });
        });
    }

    applyFontSize(size) {
        this.currentFontSize = size;
        const card = document.querySelector('.question-card');
        if (card) {
            card.classList.remove('font-small', 'font-medium', 'font-large');
            card.classList.add('font-' + size);
        }
        document.querySelectorAll('.btn-font-resizer').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-font') === size);
        });
        try { localStorage.setItem('cbt_font_size', size); } catch (e) {}
    }

    /* -------------------------------------------------------------- */
    /* FITUR BARU: PROTEKSI BEFOREUNLOAD (PREVENT ACCIDENTAL EXIT)     */
    /* -------------------------------------------------------------- */
    initBeforeUnload() {
        window.addEventListener('beforeunload', (e) => {
            if (!this.isSubmitting) {
                e.preventDefault();
                e.returnValue = 'Ujian sedang berlangsung! Apakah Anda yakin ingin keluar?';
                return e.returnValue;
            }
        });
    }

    /* -------------------------------------------------------------- */
    /* FITUR BARU: ZOOM GAMBAR SOAL (LIGHTBOX)                         */
    /* -------------------------------------------------------------- */
    zoomImage(src) {
        if (this.zoomImageTarget && this.modalImageZoom) {
            this.zoomImageTarget.src = src;
            this.modalImageZoom.classList.add('show');
        }
    }
}
