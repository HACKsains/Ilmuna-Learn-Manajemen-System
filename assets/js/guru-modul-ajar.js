/**
 * ==============================================================================
 * ILMUNA LEARN — MODUL AJAR SPA ENGINE (CP -> TP/ATP/ITP -> MODUL AJAR)
 * ==============================================================================
 * Pure Vanilla ES6+ | Zero Page Reload | window.Ilmuna Dialogs Only | Solar Icons
 * ==============================================================================
 */
(function () {
    'use strict';

    const CFG = window.ILMUNA_MODUL_AJAR_CFG || {
        baseUrl: '',
        endpoint: '/dashboard/guru/modul-ajar',
        activeRole: 'guru',
        isAdmin: false
    };

    const P5_PRESETS = [
        'Beriman, Bertakwa kepada Tuhan YME & Berakhlak Mulia',
        'Berkebinekaan Global',
        'Bergotong Royong',
        'Mandiri',
        'Bernalar Kritis',
        'Kreatif',
        'Ta\'addub (Berkeadaban)',
        'Qudwah (Keteladanan)',
        'Tawassuth (Mengambil Jalan Tengah)',
        'Tasamuh (Toleransi)',
        'Syura (Musyawarah)'
    ];

    const MEDIA_PRESETS = [
        'LCD Proyektor', 'Slide PPT Interaktif', 'Papan Tulis & Spidol',
        'Al-Qur\'an / Mushaf', 'Video Pembelajaran', 'LKPD Digital',
        'Laptop / Smartphone', 'Kartu Studi Kasus', 'Mind Mapping'
    ];

    const SUMBER_PRESETS = [
        'Buku Paket Kemendikbudristek', 'Al-Qur\'an & Terjemah Kemenag',
        'Modul Ajar Guru', 'Tafsir & Hadis Rujukan', 'Artikel Ilmiah & Jurnal',
        'Video Edukasi Terkurasi', 'Lingkungan Sekitar'
    ];

    const WIZ_SUBTITLES = {
        1: 'Langkah 1 dari 6 • Informasi Dasar Pertemuan & Multi-Kelas',
        2: 'Langkah 2 dari 6 • Pilih CP, TP, ITP/ATP & Komponen Pembelajaran',
        3: 'Langkah 3 dari 6 • Pemahaman Bermakna & Pertanyaan Pemantik',
        4: 'Langkah 4 dari 6 • Alokasi Waktu JP & Skenario Kegiatan Pembelajaran',
        5: 'Langkah 5 dari 6 • Asesmen, Profil Pelajar Pancasila (P5-PPRA) & Refleksi',
        6: 'Langkah 6 dari 6 • LKPD, Glosarium, Lampiran & Simpan Modul'
    };

    /* ==========================================================================
     * HELPERS
     * ========================================================================== */
    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function nl2brEsc(str) {
        return esc(str).replace(/\r?\n/g, '<br>');
    }

    function notify(type, message, title) {
        const ui = window.Ilmuna;
        const resolvedTitle = title || (type === 'error' ? 'Gagal' : type === 'warning' ? 'Perhatian' : type === 'info' ? 'Informasi' : 'Berhasil');
        if (ui && typeof ui.toast === 'function') {
            ui.toast(type, resolvedTitle, message || '', 4500);
            return;
        }
        console[type === 'error' ? 'error' : 'log']('[ModulAjar]', resolvedTitle, message);
    }

    async function confirmAction(opts) {
        const ui = window.Ilmuna;
        if (ui && typeof ui.confirm === 'function') {
            const type = opts && opts.danger ? 'error' : 'warning';
            return await ui.confirm(
                type,
                (opts && opts.title) || 'Konfirmasi',
                (opts && opts.message) || 'Yakin ingin melanjutkan?',
                (opts && opts.confirmText) || 'Ya, Lanjutkan'
            );
        }
        return true;
    }

    async function apiGet(action, params) {
        const qs = new URLSearchParams(Object.assign({ api: '1', action: action }, params || {}));
        const res = await fetch(CFG.endpoint + '?' + qs.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });
        const json = await res.json();
        if (!res.ok || json.status !== 'success') {
            throw new Error(json.message || 'Permintaan gagal diproses.');
        }
        return json;
    }

    async function apiPost(action, payload, isFormData) {
        let fetchOpts = {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        };
        if (isFormData) {
            payload.append('action', action);
            fetchOpts.body = payload;
        } else {
            fetchOpts.headers['Content-Type'] = 'application/json';
            fetchOpts.body = JSON.stringify(Object.assign({ action: action }, payload || {}));
        }
        const res = await fetch(CFG.endpoint + '?api=1&action=' + encodeURIComponent(action), fetchOpts);
        const json = await res.json();
        if (!res.ok || json.status !== 'success') {
            throw new Error(json.message || 'Permintaan gagal disimpan.');
        }
        return json;
    }

    function getSubjectIcon(name) {
        const n = (name || '').toLowerCase();
        if (n.indexOf('qur') !== -1 || n.indexOf('hadit') !== -1 || n.indexOf('tajwid') !== -1) return 'solar:book-2-bold';
        if (n.indexOf('akidah') !== -1 || n.indexOf('akhlak') !== -1) return 'solar:heart-angle-bold';
        if (n.indexOf('fiqih') !== -1 || n.indexOf('fikih') !== -1) return 'solar:scale-bold';
        if (n.indexOf('sejarah') !== -1 || n.indexOf('ski') !== -1) return 'solar:history-bold';
        if (n.indexOf('arab') !== -1 || n.indexOf('bahasa') !== -1) return 'solar:chat-round-line-bold';
        if (n.indexOf('pai') !== -1 || n.indexOf('agama') !== -1) return 'solar:star-shine-bold';
        if (n.indexOf('matematika') !== -1) return 'solar:calculator-bold';
        if (n.indexOf('ipa') !== -1 || n.indexOf('fisika') !== -1 || n.indexOf('kimia') !== -1) return 'solar:atom-bold';
        return 'solar:book-bookmark-bold';
    }

    function getProgressColor(pct) {
        if (pct >= 80) return '#10B981';
        if (pct >= 40) return '#0D7A87';
        if (pct > 0) return '#F59E0B';
        return '#CBD5E1';
    }

    function formatBytes(bytes) {
        const b = Number(bytes || 0);
        if (!b) return '';
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(b) / Math.log(1024));
        return (b / Math.pow(1024, i)).toFixed(1) + ' ' + sizes[i];
    }

    function formatDateIndo(ymd) {
        if (!ymd) return '-';
        const p = String(ymd).split('-');
        if (p.length !== 3) return ymd;
        const bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        return parseInt(p[2], 10) + ' ' + (bln[parseInt(p[1], 10)] || p[1]) + ' ' + p[0];
    }

    function debounce(fn, delay) {
        let t = null;
        return function () {
            const args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(null, args); }, delay);
        };
    }

    function buildSintaksText(modelIds, modelsCatalog) {
        const selected = modelsCatalog.filter(function (m) { return modelIds.indexOf(Number(m.id)) !== -1; });
        if (!selected.length) return null;

        let pembukaan = '1. Guru membuka pembelajaran dengan salam, doa bersama, dan memeriksa kehadiran peserta didik.\n'
            + '2. Apersepsi: mengaitkan pengalaman peserta didik dengan materi pembelajaran.\n'
            + '3. Menyampaikan tujuan pembelajaran, pertanyaan pemantik, dan skenario kegiatan.';

        let intiLines = [];
        selected.forEach(function (m) {
            intiLines.push('=== Model: ' + m.nama_model + ' ===');
            if (Array.isArray(m.sintaks_steps) && m.sintaks_steps.length > 0) {
                m.sintaks_steps.forEach(function (st) {
                    intiLines.push('Tahap ' + st.tahap_ke + ' — ' + st.nama_fase + ':');
                    intiLines.push('  • Aktivitas Guru: ' + st.aktivitas_guru);
                    intiLines.push('  • Aktivitas Siswa: ' + st.aktivitas_siswa);
                });
            } else if (m.sintaks) {
                intiLines.push(m.sintaks);
            }
            intiLines.push('');
        });

        let penutup = '1. Guru bersama peserta didik merangkum kesimpulan pokok materi.\n'
            + '2. Melakukan refleksi singkat serta asesmen formatif / umpan balik.\n'
            + '3. Menyampaikan agenda pertemuan berikutnya dan menutup dengan doa.';

        return {
            pembukaan: pembukaan,
            inti: intiLines.join('\n').trim(),
            penutup: penutup
        };
    }

    /* ==========================================================================
     * MAIN APPLICATION STATE & CONTROLLER
     * ========================================================================== */
    const MA = {
        state: {
            activeTab: 'modul',
            activeSubView: 'subjects', // subjects | meetings | detail
            kmpList: [],
            subjects: [],
            models: [],
            metodes: [],
            materiList: [],
            tahunList: [],
            defaultTahun: '2026/2027',
            defaultSem: 'Ganjil',
            uniqueGrades: [],
            counts: { cp: 0, tp: 0, modul: 0 },

            // Active selections
            activeKmpId: 0,
            activeMeetingsData: null,
            activeModulId: 0,
            activeDetail: null,
            detailEditMode: false,

            // CP & TP tab caches
            cpElemenList: [],
            cpList: [],
            tpList: [],
            tpCpOptions: [],

            // Wizard state
            wizStep: 1,
            wizCpList: [],
            wizTpList: [],
            wizSelectedCpIds: [],
            wizSelectedTpIds: [],
            wizSelectedModelIds: [],
            wizSelectedMetodeIds: [],
            wizSelectedKmpIds: [],
            quickReturnToWizard: false,

            // Text popup state
            popupTargetId: null,
            popupCallback: null,
            popupReadOnly: false
        },

        async init() {
            this.bindEvents();
            await this.loadBootstrap(true);
        },

        bindEvents() {
            const self = this;

            // Main tabs
            document.querySelectorAll('#maMainTabs .curriculum-tab-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    self.switchTab(btn.getAttribute('data-tab'));
                });
            });

            // Subject grid filters
            const sSearch = document.getElementById('subjSearchInput');
            if (sSearch) sSearch.addEventListener('input', debounce(function () { self.renderSubjectsGrid(); }, 200));
            const sGrade = document.getElementById('subjGradeFilter');
            if (sGrade) sGrade.addEventListener('change', function () { self.renderSubjectsGrid(); });
            const sTahun = document.getElementById('subjTahunFilter');
            if (sTahun) sTahun.addEventListener('change', function () { self.renderSubjectsGrid(); });

            // Meetings filters
            const mSearch = document.getElementById('meetSearchInput');
            if (mSearch) mSearch.addEventListener('input', debounce(function () { self.loadMeetings(self.state.activeKmpId); }, 250));
            const mStatus = document.getElementById('meetStatusFilter');
            if (mStatus) mStatus.addEventListener('change', function () { self.loadMeetings(self.state.activeKmpId); });
            const mTahun = document.getElementById('meetTahunFilter');
            if (mTahun) mTahun.addEventListener('change', function () { self.loadMeetings(self.state.activeKmpId); });
            const mSib = document.getElementById('meetSiblingSwitch');
            if (mSib) mSib.addEventListener('change', function () {
                const kid = parseInt(mSib.value, 10);
                if (kid > 0) self.openKmpMeetings(kid);
            });

            // CP filters
            const cpS = document.getElementById('cpSearchInput');
            if (cpS) cpS.addEventListener('input', debounce(function () { self.loadCpTab(); }, 250));
            const cpK = document.getElementById('cpKmpFilter');
            if (cpK) cpK.addEventListener('change', function () { self.loadCpTab(); });

            // TP filters
            const tpS = document.getElementById('tpSearchInput');
            if (tpS) tpS.addEventListener('input', debounce(function () { self.loadTpTab(); }, 250));
            const tpK = document.getElementById('tpKmpFilter');
            if (tpK) tpK.addEventListener('change', function () {
                const cpSel = document.getElementById('tpCpFilter');
                if (cpSel) cpSel.value = '';
                self.loadTpTab();
            });
            const tpC = document.getElementById('tpCpFilter');
            if (tpC) tpC.addEventListener('change', function () { self.loadTpTab(); });
        },

        async loadBootstrap(initial) {
            try {
                const res = await apiGet('bootstrap');
                const d = res.data;
                this.state.kmpList = d.kmp_list || [];
                this.state.subjects = d.subjects || [];
                this.state.models = d.models || [];
                this.state.metodes = d.metodes || [];
                this.state.materiList = d.materi_list || [];
                this.state.tahunList = d.tahun_list || ['2026/2027'];
                this.state.defaultTahun = d.default_tahun || '2026/2027';
                this.state.defaultSem = d.default_sem || 'Ganjil';
                this.state.uniqueGrades = d.unique_grades || [];
                this.state.counts = d.counts || { cp: 0, tp: 0, modul: 0 };

                this.updateTabCounters();
                this.populateGlobalDropdowns();

                if (initial || this.state.activeSubView === 'subjects') {
                    this.renderSubjectsGrid();
                }
            } catch (err) {
                notify('error', err.message, 'Gagal Memuat Data');
                const c = document.getElementById('maSubjectsContainer');
                if (c) c.innerHTML = '<div class="ma-empty-state"><div class="ma-empty-icon"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon></div><h3>Gagal Memuat Data</h3><p>' + esc(err.message) + '</p></div>';
            }
        },

        updateTabCounters() {
            const c = this.state.counts;
            const elM = document.getElementById('cntTabModul');
            const elC = document.getElementById('cntTabCp');
            const elT = document.getElementById('cntTabTp');
            if (elM) elM.textContent = c.modul || 0;
            if (elC) elC.textContent = c.cp || 0;
            if (elT) elT.textContent = c.tp || 0;

            // Update flow ribbon pills
            const f1 = document.getElementById('flowStep1');
            const f2 = document.getElementById('flowStep2');
            const f3 = document.getElementById('flowStep3');
            if (f1 && f2 && f3) {
                [f1, f2, f3].forEach(function (el) { el.classList.remove('is-done', 'is-current'); });
                if (c.cp > 0) f1.classList.add('is-done');
                if (c.tp > 0) f2.classList.add('is-done');
                if (this.state.activeTab === 'cp') { f1.classList.remove('is-done'); f1.classList.add('is-current'); }
                else if (this.state.activeTab === 'tp') { f2.classList.remove('is-done'); f2.classList.add('is-current'); }
                else { f3.classList.add('is-current'); }
            }
        },

        populateGlobalDropdowns() {
            // Grade filter
            const gf = document.getElementById('subjGradeFilter');
            if (gf) {
                const prev = gf.value;
                gf.innerHTML = '<option value="">Semua Tingkat Kelas</option>' +
                    this.state.uniqueGrades.map(function (g) {
                        return '<option value="' + esc(g) + '">Kelas ' + esc(g) + '</option>';
                    }).join('');
                gf.value = prev;
            }

            // Tahun filters
            const tahunOpts = '<option value="">Semua Tahun Pelajaran</option>' +
                this.state.tahunList.map(function (t) { return '<option value="' + esc(t) + '">' + esc(t) + '</option>'; }).join('');
            ['subjTahunFilter', 'meetTahunFilter'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) {
                    const prev = el.value;
                    el.innerHTML = tahunOpts;
                    el.value = prev;
                }
            });

            // KMP filter dropdowns (CP tab, TP tab, CP modal, Sync modal)
            const kmpOptsAll = '<option value="">Semua Kelas &amp; Mata Pelajaran</option>' +
                this.state.kmpList.map(function (k) {
                    return '<option value="' + k.id_kmp + '">' + esc(k.nama_mata_pelajaran) + ' — ' + esc(k.nama_kelas) + '</option>';
                }).join('');
            const kmpOptsReq = this.state.kmpList.map(function (k) {
                return '<option value="' + k.id_kmp + '">' + esc(k.nama_mata_pelajaran) + ' — ' + esc(k.nama_kelas) + ' (' + esc(k.fase) + ')</option>';
            }).join('');

            ['cpKmpFilter', 'tpKmpFilter'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) {
                    const prev = el.value;
                    el.innerHTML = kmpOptsAll;
                    el.value = prev;
                }
            });
            ['cpFormKmp', 'syncSourceKmp', 'syncTargetKmp'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) el.innerHTML = kmpOptsReq;
            });

            // Wizard Mapel & Tahun & Materi
            const wizMapel = document.getElementById('wizMapel');
            if (wizMapel) {
                wizMapel.innerHTML = this.state.subjects.map(function (s) {
                    return '<option value="' + s.id_mata_pelajaran + '">' + esc(s.nama_mata_pelajaran) + ' (' + esc(s.kode_mapel) + ')</option>';
                }).join('');
            }
            const wizTahun = document.getElementById('wizTahun');
            if (wizTahun) {
                wizTahun.innerHTML = this.state.tahunList.map(function (t) {
                    return '<option value="' + esc(t) + '">' + esc(t) + '</option>';
                }).join('');
                wizTahun.value = this.state.defaultTahun;
            }
            const wizMat = document.getElementById('wizMateriId');
            if (wizMat) {
                wizMat.innerHTML = '<option value="">-- Tidak ditautkan ke bank materi --</option>' +
                    this.state.materiList.map(function (m) {
                        return '<option value="' + m.id + '">' + esc(m.judul_materi) + ' (' + esc(m.tipe_materi || 'Teks') + ')</option>';
                    }).join('');
            }
        },

        switchTab(tab) {
            this.state.activeTab = tab;
            document.querySelectorAll('#maMainTabs .curriculum-tab-btn').forEach(function (btn) {
                const isAct = btn.getAttribute('data-tab') === tab;
                btn.classList.toggle('is-active', isAct);
                btn.setAttribute('aria-selected', isAct ? 'true' : 'false');
            });
            document.getElementById('paneModul').classList.toggle('is-active', tab === 'modul');
            document.getElementById('paneCp').classList.toggle('is-active', tab === 'cp');
            document.getElementById('paneTp').classList.toggle('is-active', tab === 'tp');

            this.updateTabCounters();

            if (tab === 'cp') {
                this.loadCpTab();
            } else if (tab === 'tp') {
                this.loadTpTab();
            } else if (tab === 'modul') {
                if (this.state.activeSubView === 'subjects') {
                    this.loadBootstrap(false);
                } else if (this.state.activeSubView === 'meetings' && this.state.activeKmpId) {
                    this.loadMeetings(this.state.activeKmpId);
                }
            }
        },

        jumpToTabWithKmp(tab, idKmp) {
            if (tab === 'cp') {
                const f = document.getElementById('cpKmpFilter');
                if (f) f.value = idKmp ? String(idKmp) : '';
            } else if (tab === 'tp') {
                const f = document.getElementById('tpKmpFilter');
                if (f) f.value = idKmp ? String(idKmp) : '';
                const cf = document.getElementById('tpCpFilter');
                if (cf) cf.value = '';
            }
            this.switchTab(tab);
        },

        /* ======================================================================
         * SUB-VIEW A: SUBJECT CARDS GRID (MODULAJARLISTPAGE)
         * ====================================================================== */
        renderSubjectsGrid() {
            const container = document.getElementById('maSubjectsContainer');
            if (!container) return;

            const q = (document.getElementById('subjSearchInput') ? document.getElementById('subjSearchInput').value : '').trim().toLowerCase();
            const grade = document.getElementById('subjGradeFilter') ? document.getElementById('subjGradeFilter').value : '';
            const tahun = document.getElementById('subjTahunFilter') ? document.getElementById('subjTahunFilter').value : '';

            const filtered = this.state.subjects.map(function (s) {
                const matchedClasses = s.classes.filter(function (c) {
                    if (grade && c.grade_label !== grade) return false;
                    if (tahun && c.tahun_pelajaran !== tahun) return false;
                    if (q) {
                        const hay = (s.nama_mata_pelajaran + ' ' + s.kode_mapel + ' ' + c.nama_kelas + ' ' + c.nama_guru).toLowerCase();
                        if (hay.indexOf(q) === -1) return false;
                    }
                    return true;
                });
                return Object.assign({}, s, { classes: matchedClasses });
            }).filter(function (s) { return s.classes.length > 0; });

            if (!filtered.length) {
                container.innerHTML =
                    '<div class="ma-empty-state">' +
                        '<div class="ma-empty-icon"><iconify-icon icon="solar:book-bookmark-bold"></iconify-icon></div>' +
                        '<h3>Belum Ada Mata Pelajaran / Rombel yang Cocok</h3>' +
                        '<p>Pastikan data Mata Pelajaran &amp; Rombel Kelas sudah dipetakan, atau ubah kata kunci pencarian dan filter tingkat kelas.</p>' +
                    '</div>';
                return;
            }

            let html = '<div class="ma-subject-grid">';
            filtered.forEach(function (subj) {
                const icon = getSubjectIcon(subj.nama_mata_pelajaran);
                html += '<div class="ma-subject-card">';
                html += '<div class="ma-subject-card__head">' +
                    '<div class="ma-subject-icon"><iconify-icon icon="' + icon + '"></iconify-icon></div>' +
                    '<div class="ma-subject-meta">' +
                        '<span class="ma-subject-code">' + esc(subj.kode_mapel) + ' &bull; KKTP ' + esc(subj.kktp) + '</span>' +
                        '<h3 class="ma-subject-title">' + esc(subj.nama_mata_pelajaran) + '</h3>' +
                        '<div class="ma-subject-sub">' + subj.classes.length + ' Rombel &bull; ' + subj.total_modul + ' Modul Ajar</div>' +
                    '</div>' +
                '</div>';

                html += '<div class="ma-subject-card__body">';
                subj.classes.forEach(function (cls) {
                    const pColor = getProgressColor(cls.progress_pct);
                    const cpPillClass = cls.cp_count > 0 ? 'ma-mini-pill--cp' : 'ma-mini-pill--empty';
                    const tpPillClass = cls.tp_count > 0 ? 'ma-mini-pill--tp' : 'ma-mini-pill--empty';

                    html += '<div class="ma-class-row" onclick="MA.openKmpMeetings(' + cls.id_kmp + ')">' +
                        '<div class="ma-class-row__top">' +
                            '<div class="ma-class-name">' +
                                '<iconify-icon icon="solar:buildings-2-bold" style="color:#0D7A87;flex-shrink:0;"></iconify-icon>' +
                                '<span>' + esc(cls.nama_kelas) + '</span>' +
                            '</div>' +
                            '<span class="ma-tag ma-tag--navy">' + esc(cls.fase) + '</span>' +
                        '</div>' +
                        '<div class="ma-class-guru">' +
                            '<iconify-icon icon="solar:user-speak-bold" style="color:#94A3B8;"></iconify-icon>' +
                            '<span>' + esc(cls.nama_guru) + '</span>' +
                        '</div>' +
                        '<div class="ma-class-stats">' +
                            '<span class="ma-mini-pill ' + cpPillClass + '"><iconify-icon icon="solar:diploma-verified-bold"></iconify-icon> ' + cls.cp_count + ' CP</span>' +
                            '<span class="ma-mini-pill ' + tpPillClass + '"><iconify-icon icon="solar:target-bold"></iconify-icon> ' + cls.tp_count + ' TP</span>' +
                            '<span class="ma-mini-pill ma-mini-pill--ma"><iconify-icon icon="solar:book-bookmark-bold"></iconify-icon> ' + cls.modul_total + ' Modul' +
                                (cls.modul_final > 0 ? ' (' + cls.modul_final + ' Final)' : (cls.modul_published > 0 ? ' (' + cls.modul_published + ' Aktif)' : '')) +
                            '</span>' +
                        '</div>' +
                        '<div class="ma-progress-wrap" title="Persentase Modul Diterbitkan / Final">' +
                            '<div class="ma-progress-track"><div class="ma-progress-fill" style="width:' + cls.progress_pct + '%;background:' + pColor + ';"></div></div>' +
                            '<span class="ma-progress-pct">' + cls.progress_pct + '%</span>' +
                        '</div>' +
                    '</div>';
                });
                html += '</div></div>';
            });
            html += '</div>';
            container.innerHTML = html;
        },

        /* ======================================================================
         * SUB-VIEW B: PERTEMUAN LIST PER KMP (PERTEMUANLISTPAGE)
         * ====================================================================== */
        setSubView(view) {
            this.state.activeSubView = view;
            document.getElementById('maViewSubjects').style.display = (view === 'subjects') ? 'block' : 'none';
            document.getElementById('maViewMeetings').style.display = (view === 'meetings') ? 'block' : 'none';
            document.getElementById('maViewDetail').style.display = (view === 'detail') ? 'block' : 'none';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        backToSubjects() {
            this.setSubView('subjects');
            this.loadBootstrap(false);
        },

        backToMeetings() {
            if (this.state.activeKmpId > 0) {
                this.setSubView('meetings');
                this.loadMeetings(this.state.activeKmpId);
            } else {
                this.backToSubjects();
            }
        },

        async openKmpMeetings(idKmp) {
            this.state.activeKmpId = Number(idKmp);
            this.setSubView('meetings');
            await this.loadMeetings(idKmp);
        },

        async loadMeetings(idKmp) {
            const container = document.getElementById('maMeetingsContainer');
            if (container) {
                container.innerHTML = '<div class="ma-skeleton-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));"><div class="ma-skeleton-card"></div><div class="ma-skeleton-card"></div></div>';
            }
            try {
                const q = document.getElementById('meetSearchInput') ? document.getElementById('meetSearchInput').value.trim() : '';
                const status = document.getElementById('meetStatusFilter') ? document.getElementById('meetStatusFilter').value : '';
                const tahun = document.getElementById('meetTahunFilter') ? document.getElementById('meetTahunFilter').value : '';

                const res = await apiGet('list_modul', { id_kmp: idKmp, q: q, status: status, tahun: tahun });
                const d = res.data;
                this.state.activeMeetingsData = d;

                // Header
                const info = d.kmp_info;
                document.getElementById('meetHeadTitle').innerHTML =
                    esc(info.nama_mata_pelajaran) + ' <span class="ma-tag ma-tag--teal">' + esc(info.nama_kelas) + '</span> <span class="ma-tag ma-tag--navy">' + esc(info.fase) + '</span>';
                document.getElementById('meetHeadMeta').innerHTML =
                    '<span><iconify-icon icon="solar:user-speak-bold"></iconify-icon> ' + esc(info.nama_guru) + '</span>' +
                    '<span><iconify-icon icon="solar:diploma-verified-bold"></iconify-icon> ' + d.cp_count + ' CP</span>' +
                    '<span><iconify-icon icon="solar:target-bold"></iconify-icon> ' + d.tp_count + ' TP</span>' +
                    '<span><iconify-icon icon="solar:book-bookmark-bold"></iconify-icon> ' + d.modul_list.length + ' Pertemuan</span>';

                // Sibling switcher
                const sibSel = document.getElementById('meetSiblingSwitch');
                if (sibSel) {
                    sibSel.innerHTML = (d.siblings || []).map(function (sb) {
                        return '<option value="' + sb.id_kmp + '"' + (sb.id_kmp === info.id_kmp ? ' selected' : '') + '>' + esc(sb.nama_kelas) + '</option>';
                    }).join('');
                }

                // Readiness guidance banners (CP -> TP -> Modul Ajar)
                let banners = '';
                if (d.cp_count === 0) {
                    const syncBtn = d.sibling_with_cp
                        ? '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.quickSyncCpFromSibling(' + d.sibling_with_cp.id_kmp + ',' + info.id_kmp + ')">' +
                            '<iconify-icon icon="solar:copy-bold"></iconify-icon> Salin ' + d.sibling_with_cp.cnt + ' CP &amp; TP dari ' + esc(d.sibling_with_cp.nama_kelas) +
                          '</button>'
                        : '';
                    banners += '<div class="ma-alert-banner ma-alert-banner--amber">' +
                        '<div class="ma-alert-banner__body">' +
                            '<iconify-icon icon="solar:info-circle-bold"></iconify-icon>' +
                            '<div>' +
                                '<p class="ma-alert-banner__title">Langkah 1: Capaian Pembelajaran (CP) belum tersedia untuk ' + esc(info.nama_kelas) + '</p>' +
                                '<p class="ma-alert-banner__desc">Tambahkan atau salin CP terlebih dahulu agar Anda dapat menyusun TP dan mengaitkannya ke Modul Ajar.</p>' +
                            '</div>' +
                        '</div>' +
                        '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                            syncBtn +
                            '<button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.jumpToTabWithKmp(\'cp\',' + info.id_kmp + ')">' +
                                '<iconify-icon icon="solar:diploma-verified-bold"></iconify-icon> Kelola CP' +
                            '</button>' +
                        '</div>' +
                    '</div>';
                } else if (d.tp_count === 0) {
                    banners += '<div class="ma-alert-banner ma-alert-banner--teal">' +
                        '<div class="ma-alert-banner__body">' +
                            '<iconify-icon icon="solar:target-bold"></iconify-icon>' +
                            '<div>' +
                                '<p class="ma-alert-banner__title">Langkah 2: ' + d.cp_count + ' CP sudah tersedia! Sekarang buat Tujuan Pembelajaran (TP)</p>' +
                                '<p class="ma-alert-banner__desc">Susun TP beserta ATP &amp; ITP dari CP yang ada agar langsung muncul otomatis saat membuat Modul Ajar.</p>' +
                            '</div>' +
                        '</div>' +
                        '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.jumpToTabWithKmp(\'tp\',' + info.id_kmp + ')">' +
                            '<iconify-icon icon="solar:add-circle-bold"></iconify-icon> Buat TP Sekarang' +
                        '</button>' +
                    '</div>';
                }
                document.getElementById('meetReadinessBanners').innerHTML = banners;

                this.renderMeetingsList(d.modul_list || []);
            } catch (err) {
                notify('error', err.message, 'Gagal Memuat Pertemuan');
            }
        },

        async quickSyncCpFromSibling(srcKmp, dstKmp) {
            const ok = await confirmAction({
                title: 'Sinkronisasi CP & TP',
                message: 'Salin seluruh Elemen, CP, TP, ATP, dan ITP dari kelas sumber ke kelas ini?',
                confirmText: 'Ya, Salin Sekarang'
            });
            if (!ok) return;
            try {
                const res = await apiPost('sync_cp_mapel', { source_kmp: srcKmp, target_kmp: dstKmp, include_tp: 1 });
                notify('success', res.message);
                await this.loadBootstrap(false);
                await this.loadMeetings(dstKmp);
            } catch (err) {
                notify('error', err.message);
            }
        },

        renderMeetingsList(list) {
            const container = document.getElementById('maMeetingsContainer');
            if (!container) return;

            if (!list.length) {
                container.innerHTML =
                    '<div class="ma-empty-state">' +
                        '<div class="ma-empty-icon"><iconify-icon icon="solar:document-add-bold"></iconify-icon></div>' +
                        '<h3>Belum Ada Modul Ajar Pertemuan</h3>' +
                        '<p>Mulai buat Modul Ajar Kurikulum Merdeka 6 langkah lengkap dengan CP, TP, Sintaks Model Pembelajaran, dan Alokasi Waktu otomatis.</p>' +
                        '<button type="button" class="ilmuna-btn ilmuna-btn--teal" onclick="MA.openCreateWizard(MA.state.activeKmpId)">' +
                            '<iconify-icon icon="solar:add-circle-bold"></iconify-icon> Buat Modul Ajar Pertemuan Pertama' +
                        '</button>' +
                    '</div>';
                return;
            }

            const execLabel = { terlaksana: 'Terlaksana', sebagian: 'Sebagian', belum: 'Belum' };
            let html = '<div class="ma-meeting-grid">';

            list.forEach(function (m) {
                const isFinal = m.meeting_status === 'final';
                const isPub = m.status === 'Published';
                let stBadge = '<span class="ma-status-pill ma-status-pill--draft"><iconify-icon icon="solar:pen-bold"></iconify-icon> Draft</span>';
                if (isFinal) {
                    stBadge = '<span class="ma-status-pill ma-status-pill--final"><iconify-icon icon="solar:lock-bold"></iconify-icon> Final (Terkunci)</span>';
                } else if (isPub) {
                    stBadge = '<span class="ma-status-pill ma-status-pill--published"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Published</span>';
                } else if (m.status === 'Archived') {
                    stBadge = '<span class="ma-status-pill ma-status-pill--archived"><iconify-icon icon="solar:archive-bold"></iconify-icon> Archived</span>';
                }

                const printModulUrl = CFG.endpoint + '?action=print_modul&id=' + m.id;
                const printLapUrl = CFG.endpoint + '?action=print_laporan&id=' + m.id;

                html += '<div class="ma-meeting-card">' +
                    '<div class="ma-meeting-card__top">' +
                        '<span class="ma-pertemuan-badge"><iconify-icon icon="solar:calendar-mark-bold"></iconify-icon> Pertemuan ke-' + m.pertemuan_ke + '</span>' +
                        stBadge +
                    '</div>' +
                    '<h3 class="ma-meeting-title" onclick="MA.openModulDetail(' + m.id + ')">' + esc(m.judul_modul) + '</h3>' +
                    '<div class="ma-meeting-meta-row">' +
                        '<span class="ma-meeting-meta-item"><iconify-icon icon="solar:calendar-bold"></iconify-icon> ' + formatDateIndo(m.tanggal) + '</span>' +
                        '<span class="ma-meeting-meta-item"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> ' + m.jumlah_jp + ' JP (' + m.total_menit + ' mnt)</span>' +
                        '<span class="ma-meeting-meta-item"><iconify-icon icon="solar:bookmark-bold"></iconify-icon> ' + esc(m.tahun_pelajaran) + ' &bull; ' + esc(m.semester) + '</span>' +
                    '</div>' +
                    '<div class="ma-meeting-tags">' +
                        '<span class="ma-tag ma-tag--navy">' + m.cp_ids.length + ' CP</span>' +
                        '<span class="ma-tag ma-tag--teal">' + m.tp_ids.length + ' TP</span>' +
                        (m.primary_model_name ? '<span class="ma-tag ma-tag--gold">' + esc(m.primary_model_name) + '</span>' : '') +
                        (m.lampiran && m.lampiran.length ? '<span class="ma-tag"><iconify-icon icon="solar:paperclip-bold"></iconify-icon> ' + m.lampiran.length + ' File</span>' : '') +
                    '</div>' +
                    '<div class="ma-exec-mini">' +
                        '<span>Keterlaksanaan:</span>' +
                        '<span class="ma-exec-dot ma-exec-dot--' + esc(m.status_pembukaan) + '">Buka: ' + (execLabel[m.status_pembukaan] || 'Belum') + '</span>' +
                        '<span class="ma-exec-dot ma-exec-dot--' + esc(m.status_inti) + '">Inti: ' + (execLabel[m.status_inti] || 'Belum') + '</span>' +
                        '<span class="ma-exec-dot ma-exec-dot--' + esc(m.status_penutup) + '">Tutup: ' + (execLabel[m.status_penutup] || 'Belum') + '</span>' +
                    '</div>' +
                    '<div class="ma-meeting-card__foot">' +
                        '<div class="ma-card-actions">' +
                            '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.openModulDetail(' + m.id + ')">' +
                                '<iconify-icon icon="solar:eye-bold"></iconify-icon> Buka Detail' +
                            '</button>' +
                            '<a href="' + printModulUrl + '" target="_blank" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" title="Cetak / Unduh PDF Modul Ajar">' +
                                '<iconify-icon icon="solar:printer-bold"></iconify-icon> PDF Modul' +
                            '</a>' +
                            ((isPub || isFinal) ? '<a href="' + printLapUrl + '" target="_blank" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" title="Cetak Laporan Keterlaksanaan"><iconify-icon icon="solar:document-text-bold"></iconify-icon> Laporan</a>' : '') +
                        '</div>' +
                        '<div class="ma-card-actions">' +
                            '<button type="button" class="ilmuna-btn-icon" title="Duplikasi Pertemuan" onclick="MA.duplicateModul(' + m.id + ')"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>' +
                            (!isFinal ? '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--del" title="Hapus Modul Ajar" onclick="MA.deleteModul(' + m.id + ')"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>' : '') +
                        '</div>' +
                    '</div>' +
                '</div>';
            });

            html += '</div>';
            container.innerHTML = html;
        },

        async duplicateModul(id) {
            const ok = await confirmAction({
                title: 'Duplikasi Modul Ajar',
                message: 'Buat salinan Draft baru dari modul ajar ini untuk pertemuan berikutnya?',
                confirmText: 'Ya, Duplikasi'
            });
            if (!ok) return;
            try {
                const res = await apiPost('duplicate_modul', { id: id });
                notify('success', res.message);
                await this.loadBootstrap(false);
                await this.loadMeetings(this.state.activeKmpId);
            } catch (err) {
                notify('error', err.message);
            }
        },

        async deleteModul(id, fromDetail) {
            const ok = await confirmAction({
                title: 'Hapus Modul Ajar',
                message: 'Modul ajar beserta seluruh berkas lampirannya akan dihapus permanen. Lanjutkan?',
                confirmText: 'Ya, Hapus',
                danger: true
            });
            if (!ok) return;
            try {
                const res = await apiPost('delete_modul', { id: id });
                notify('success', res.message);
                await this.loadBootstrap(false);
                if (fromDetail) {
                    this.backToMeetings();
                } else {
                    await this.loadMeetings(this.state.activeKmpId);
                }
            } catch (err) {
                notify('error', err.message);
            }
        },

        /* ======================================================================
         * SUB-VIEW C: DETAIL & EDITOR MODUL AJAR (MODULAJARDETAILPAGE)
         * ====================================================================== */
        async openModulDetail(id, startEdit) {
            this.state.activeModulId = Number(id);
            this.state.detailEditMode = !!startEdit;
            this.setSubView('detail');
            const cont = document.getElementById('maDetailContainer');
            if (cont) cont.innerHTML = '<div class="ma-skeleton-card" style="height:260px;"></div>';

            try {
                const res = await apiGet('get_modul_detail', { id: id });
                this.state.activeDetail = res.data;
                this.state.activeKmpId = Number(res.data.modul.id_kelas_mata_pelajaran);
                if (res.data.modul.status !== 'Draft' || res.data.modul.meeting_status === 'final') {
                    this.state.detailEditMode = false;
                }
                this.renderDetailView();
            } catch (err) {
                notify('error', err.message, 'Gagal Membuka Detail');
                this.backToMeetings();
            }
        },

        renderDetailView() {
            const d = this.state.activeDetail;
            if (!d || !d.modul) return;
            const m = d.modul;
            const isFinal = m.meeting_status === 'final';
            const isDraft = m.status === 'Draft' && !isFinal;
            const isPub = m.status === 'Published' && !isFinal;
            const ed = this.state.detailEditMode && isDraft;
            const dis = ed ? '' : ' disabled';

            // Header Title & Status
            let stBadge = '<span class="ma-status-pill ma-status-pill--draft"><iconify-icon icon="solar:pen-bold"></iconify-icon> Draft</span>';
            if (isFinal) stBadge = '<span class="ma-status-pill ma-status-pill--final"><iconify-icon icon="solar:lock-bold"></iconify-icon> Final (Terkunci)</span>';
            else if (isPub) stBadge = '<span class="ma-status-pill ma-status-pill--published"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Published</span>';

            document.getElementById('detTitle').innerHTML =
                'Pertemuan ke-' + m.pertemuan_ke + ': ' + esc(m.judul_modul) + ' ' + stBadge;
            document.getElementById('detSubMeta').innerHTML =
                '<span><iconify-icon icon="solar:book-2-bold"></iconify-icon> ' + esc(m.nama_mata_pelajaran) + '</span>' +
                '<span><iconify-icon icon="solar:buildings-2-bold"></iconify-icon> ' + esc(m.nama_kelas) + ' (' + esc(m.fase) + ')</span>' +
                '<span><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> ' + m.jumlah_jp + ' JP &times; ' + m.alokasi_per_jp + ' mnt = ' + m.total_menit + ' Menit</span>';

            // Header Actions
            const printModulUrl = CFG.endpoint + '?action=print_modul&id=' + m.id;
            const printLapUrl = CFG.endpoint + '?action=print_laporan&id=' + m.id;
            let actHtml = '';
            if (isDraft) {
                if (ed) {
                    actHtml += '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.saveDetailChanges()"><iconify-icon icon="solar:diskette-bold"></iconify-icon> Simpan Perubahan</button>';
                    actHtml += '<button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.toggleDetailEdit(false)">Batal Edit</button>';
                } else {
                    actHtml += '<button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.toggleDetailEdit(true)"><iconify-icon icon="solar:pen-bold"></iconify-icon> Mode Edit</button>';
                    actHtml += '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.togglePublishModul(' + m.id + ')"><iconify-icon icon="solar:rocket-2-bold"></iconify-icon> Terbitkan (Publish)</button>';
                }
            } else if (isPub) {
                actHtml += '<button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.togglePublishModul(' + m.id + ')"><iconify-icon icon="solar:undo-left-bold"></iconify-icon> Kembalikan ke Draft</button>';
                actHtml += '<button type="button" class="ilmuna-btn ilmuna-btn--gold ilmuna-btn--sm" onclick="MA.finalizeModul(' + m.id + ')"><iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon> Finalisasi</button>';
            }
            actHtml += '<a href="' + printModulUrl + '" target="_blank" class="ilmuna-btn ilmuna-btn--sky ilmuna-btn--sm"><iconify-icon icon="solar:printer-bold"></iconify-icon> PDF Modul</a>';
            if (isPub || isFinal) {
                actHtml += '<a href="' + printLapUrl + '" target="_blank" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm"><iconify-icon icon="solar:document-text-bold"></iconify-icon> Laporan PDF</a>';
            }
            if (!isFinal) {
                actHtml += '<button type="button" class="ilmuna-btn ilmuna-btn--danger ilmuna-btn--sm" onclick="MA.deleteModul(' + m.id + ', true)"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon> Hapus</button>';
            }
            document.getElementById('detTopActions').innerHTML = actHtml;

            // Status Info Banner
            let bannerHtml = '';
            if (isFinal) {
                bannerHtml = '<div class="ma-alert-banner ma-alert-banner--teal"><div class="ma-alert-banner__body"><iconify-icon icon="solar:lock-bold"></iconify-icon><div><p class="ma-alert-banner__title">Pertemuan Sudah Difinalisasi (Terkunci Permanen)</p><p class="ma-alert-banner__desc">Seluruh isi modul ajar dan catatan keterlaksanaan bersifat arsip resmi (read-only) dan siap dicetak.</p></div></div></div>';
            } else if (isPub) {
                bannerHtml = '<div class="ma-alert-banner ma-alert-banner--teal"><div class="ma-alert-banner__body"><iconify-icon icon="solar:check-circle-bold"></iconify-icon><div><p class="ma-alert-banner__title">Modul Sudah Diterbitkan — Catat Status Keterlaksanaan di Bawah</p><p class="ma-alert-banner__desc">Untuk mengubah isi rencana modul, klik "Kembalikan ke Draft". Jika KBM sudah selesai dilaksanakan, isi Status Keterlaksanaan lalu klik "Finalisasi".</p></div></div></div>';
            }

            // Build CP Checkboxes
            const cpChecksHtml = (d.cp_list || []).length
                ? d.cp_list.map(function (c) {
                    const chk = m.cp_ids.indexOf(Number(c.id)) !== -1;
                    return '<label class="ma-check-card' + (chk ? ' is-checked' : '') + '">' +
                        '<input type="checkbox" class="det-cp-chk" value="' + c.id + '"' + (chk ? ' checked' : '') + dis + ' onchange="MA.onDetailCpToggle()">' +
                        '<div class="ma-check-card__body">' +
                            '<span class="ma-check-card__title">' + esc(c.nama_elemen || 'Capaian Pembelajaran') + (c.fase ? ' • ' + esc(c.fase) : '') + '</span>' +
                            '<span class="ma-check-card__desc">' + esc(c.cp) + '</span>' +
                        '</div></label>';
                }).join('')
                : '<p style="font-size:12.5px;color:#64748B;margin:0;">Belum ada CP terdaftar di kelas ini.</p>';

            // Build TP Checkboxes
            const tpChecksHtml = (d.tp_list || []).length
                ? d.tp_list.map(function (t) {
                    const chk = m.tp_ids.indexOf(Number(t.id)) !== -1;
                    return '<label class="ma-check-card' + (chk ? ' is-checked' : '') + '">' +
                        '<input type="checkbox" class="det-tp-chk" value="' + t.id + '" data-cp="' + t.id_cp + '"' + (chk ? ' checked' : '') + dis + ' onchange="MA.onDetailTpToggle()">' +
                        '<div class="ma-check-card__body">' +
                            '<span class="ma-check-card__title">' + esc(t.kode_tp || ('TP-' + t.id)) + (t.materi_pokok ? ' — ' + esc(t.materi_pokok) : '') + ' (' + (t.alokasi_jam || 2) + ' JP)</span>' +
                            '<span class="ma-check-card__desc">' + esc(t.tp) + '</span>' +
                        '</div></label>';
                }).join('')
                : '<p style="font-size:12.5px;color:#64748B;margin:0;">Belum ada TP terdaftar di kelas ini.</p>';

            // Build Model & Metode Checkboxes
            const modelHtml = this.state.models.map(function (mod) {
                const chk = m.model_ids.indexOf(Number(mod.id)) !== -1;
                return '<label class="ma-check-card' + (chk ? ' is-checked' : '') + '">' +
                    '<input type="checkbox" class="det-model-chk" value="' + mod.id + '"' + (chk ? ' checked' : '') + dis + ' onchange="this.closest(\'.ma-check-card\').classList.toggle(\'is-checked\', this.checked)">' +
                    '<div class="ma-check-card__body"><span class="ma-check-card__title">' + esc(mod.nama_model) + '</span><span class="ma-check-card__desc">' + esc((mod.deskripsi || '').slice(0, 90)) + '</span></div>' +
                '</label>';
            }).join('');

            const metodeHtml = this.state.metodes.map(function (mt) {
                const chk = m.metode_ids.indexOf(Number(mt.id)) !== -1;
                return '<label class="ma-check-card' + (chk ? ' is-checked' : '') + '">' +
                    '<input type="checkbox" class="det-metode-chk" value="' + mt.id + '"' + (chk ? ' checked' : '') + dis + ' onchange="this.closest(\'.ma-check-card\').classList.toggle(\'is-checked\', this.checked)">' +
                    '<div class="ma-check-card__body"><span class="ma-check-card__title">' + esc(mt.nama_metode) + '</span></div>' +
                '</label>';
            }).join('');

            const materiOpts = '<option value="">-- Tidak ditautkan --</option>' + this.state.materiList.map(function (mt) {
                return '<option value="' + mt.id + '"' + (Number(m.id_materi) === Number(mt.id) ? ' selected' : '') + '>' + esc(mt.judul_materi) + '</option>';
            }).join('');

            const tahunOpts = this.state.tahunList.map(function (t) {
                return '<option value="' + esc(t) + '"' + (m.tahun_pelajaran === t ? ' selected' : '') + '>' + esc(t) + '</option>';
            }).join('');

            // Execution status cards
            const execDisabled = !isPub;
            const buildExecCard = function (key, title, curStatus, curNote, dur) {
                const mkBtn = function (val, lbl) {
                    const cls = curStatus === val ? (' is-' + val) : '';
                    return '<button type="button" class="ma-exec-opt' + cls + '" data-exec-key="' + key + '" data-exec-val="' + val + '"' +
                        (execDisabled ? ' disabled' : ' onclick="MA.selectExecStatus(\'' + key + '\',\'' + val + '\')"') + '>' + lbl + '</button>';
                };
                return '<div class="ma-exec-card">' +
                    '<div class="ma-exec-card__title"><span>' + title + '</span><span class="ma-tag">' + dur + ' mnt</span></div>' +
                    '<input type="hidden" id="execStatus_' + key + '" value="' + esc(curStatus) + '">' +
                    '<div class="ma-exec-radio-row">' +
                        mkBtn('belum', 'Belum') +
                        mkBtn('sebagian', 'Sebagian') +
                        mkBtn('terlaksana', 'Terlaksana') +
                    '</div>' +
                    '<textarea class="ma-textarea" id="execNote_' + key + '" style="min-height:74px;" placeholder="Catatan pelaksanaan / kendala..."' + (execDisabled ? ' disabled' : '') + '>' + esc(curNote || '') + '</textarea>' +
                '</div>';
            };

            // Attachments list
            const attHtml = (m.lampiran && m.lampiran.length)
                ? m.lampiran.map(function (f) {
                    const dlUrl = CFG.baseUrl + '/' + String(f.file_path || '').replace(/^\/+/, '');
                    return '<div class="ma-file-item">' +
                        '<div class="ma-file-item__left">' +
                            '<iconify-icon icon="solar:file-text-bold" style="font-size:22px;color:#0D7A87;flex-shrink:0;"></iconify-icon>' +
                            '<div style="min-width:0;">' +
                                '<div class="ma-file-item__name">' + esc(f.file_name) + '</div>' +
                                '<div class="ma-file-item__size">' + formatBytes(f.file_size) + '</div>' +
                            '</div>' +
                        '</div>' +
                        '<div style="display:flex;gap:6px;">' +
                            '<a href="' + esc(dlUrl) + '" target="_blank" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm"><iconify-icon icon="solar:download-minimalistic-bold"></iconify-icon> Unduh</a>' +
                            (!isFinal ? '<button type="button" class="ilmuna-btn ilmuna-btn--danger ilmuna-btn--sm" onclick="MA.deleteLampiran(' + m.id + ',\'' + esc(f.id) + '\')"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>' : '') +
                        '</div>' +
                    '</div>';
                }).join('')
                : '<p style="font-size:12.5px;color:#64748B;margin:0;">Belum ada berkas lampiran terunggah.</p>';

            const sumDur = Number(m.durasi_pembukaan) + Number(m.durasi_inti) + Number(m.durasi_penutup);
            const durExceeded = sumDur > Number(m.total_menit);

            document.getElementById('maDetailContainer').innerHTML = bannerHtml +
                // SECTION 1: INFORMASI PERTEMUAN
                '<div class="ma-section-card">' +
                    '<div class="ma-section-card__head"><h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:calendar-mark-bold"></iconify-icon></span> 1. Informasi Dasar Pertemuan</h3></div>' +
                    '<div class="ma-section-card__body">' +
                        '<div class="ma-form-grid ma-form-grid--3">' +
                            '<div class="ma-field ma-field--full"><label class="ma-label">Judul Modul / Materi Pokok</label><input type="text" class="ma-input" id="detJudul" value="' + esc(m.judul_modul) + '"' + dis + '></div>' +
                            '<div class="ma-field"><label class="ma-label">Pertemuan Ke-</label><input type="number" class="ma-input" id="detPertemuan" value="' + m.pertemuan_ke + '" min="1"' + dis + '></div>' +
                            '<div class="ma-field"><label class="ma-label">Fase</label><input type="text" class="ma-input" id="detFase" value="' + esc(m.fase) + '"' + dis + '></div>' +
                            '<div class="ma-field"><label class="ma-label">Tahun Pelajaran</label><select class="ma-select" id="detTahun"' + dis + '>' + tahunOpts + '</select></div>' +
                            '<div class="ma-field"><label class="ma-label">Semester</label><select class="ma-select" id="detSemester"' + dis + '><option value="Ganjil"' + (m.semester === 'Ganjil' ? ' selected' : '') + '>Ganjil</option><option value="Genap"' + (m.semester === 'Genap' ? ' selected' : '') + '>Genap</option></select></div>' +
                            '<div class="ma-field"><label class="ma-label">Tanggal Pelaksanaan</label><input type="text" class="ma-input" id="detTanggal" data-ilm-calendar="date" value="' + esc(m.tanggal || '') + '" placeholder="Pilih tanggal..."' + dis + '></div>' +
                            '<div class="ma-field"><label class="ma-label">Jam Mulai &ndash; Selesai</label><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;"><input type="time" class="ma-input" id="detJamMulai" value="' + esc(m.jam_mulai || '') + '"' + dis + '><input type="time" class="ma-input" id="detJamSelesai" value="' + esc(m.jam_selesai || '') + '"' + dis + '></div></div>' +
                            '<div class="ma-field"><label class="ma-label">Target Peserta Didik</label><input type="text" class="ma-input" id="detTargetDidik" value="' + esc(m.target_peserta_didik || '') + '"' + dis + '></div>' +
                            '<div class="ma-field" style="grid-column:span 2;"><label class="ma-label">Tautan Bank Materi (Opsional)</label><select class="ma-select" id="detMateriId"' + dis + '>' + materiOpts + '</select></div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +

                // SECTION 2: STATUS KETERLAKSANAAN (TAMPIL DI ATAS BILA PUBLISHED / FINAL)
                '<div class="ma-section-card">' +
                    '<div class="ma-section-card__head">' +
                        '<h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:checklist-minimalistic-bold"></iconify-icon></span> 2. Status Keterlaksanaan Kegiatan KBM</h3>' +
                        (isPub ? '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.saveKeterlaksanaan(' + m.id + ')"><iconify-icon icon="solar:diskette-bold"></iconify-icon> Simpan Keterlaksanaan</button>' : '<span class="ma-tag">' + (isFinal ? 'Terkunci (Final)' : 'Terbitkan (Publish) modul untuk mengisi') + '</span>') +
                    '</div>' +
                    '<div class="ma-section-card__body">' +
                        '<div class="ma-exec-grid">' +
                            buildExecCard('pembukaan', 'Pembukaan / Pendahuluan', m.status_pembukaan, m.catatan_pembukaan, m.durasi_pembukaan) +
                            buildExecCard('inti', 'Kegiatan Inti', m.status_inti, m.catatan_inti, m.durasi_inti) +
                            buildExecCard('penutup', 'Penutup', m.status_penutup, m.catatan_penutup, m.durasi_penutup) +
                        '</div>' +
                    '</div>' +
                '</div>' +

                // SECTION 3: CP, TP, ITP & ATP
                '<div class="ma-section-card">' +
                    '<div class="ma-section-card__head"><h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:target-bold"></iconify-icon></span> 3. Capaian &amp; Tujuan Pembelajaran (CP &rarr; TP &rarr; ITP &rarr; ATP)</h3></div>' +
                    '<div class="ma-section-card__body">' +
                        '<div class="ma-form-grid">' +
                            '<div class="ma-field ma-field--full"><label class="ma-label">Capaian Pembelajaran (CP) Terpilih</label><div class="ma-check-list">' + cpChecksHtml + '</div></div>' +
                            '<div class="ma-field ma-field--full"><label class="ma-label">Tujuan Pembelajaran (TP) Terpilih</label><div class="ma-check-list">' + tpChecksHtml + '</div></div>' +
                            '<div class="ma-field ma-field--full"><div class="ma-label-row"><label class="ma-label">Narasi Tujuan Pembelajaran (TP)</label><button type="button" class="ma-expand-btn" onclick="MA.openTextPopup(\'Narasi Tujuan Pembelajaran\',\'detTpCustom\',' + (!ed) + ')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button></div><textarea class="ma-textarea" id="detTpCustom"' + dis + '>' + esc(m.tp_custom || '') + '</textarea></div>' +
                            '<div class="ma-field"><div class="ma-label-row"><label class="ma-label">Indikator Ketercapaian (ITP)</label><button type="button" class="ma-expand-btn" onclick="MA.openTextPopup(\'Indikator Ketercapaian (ITP)\',\'detItpCustom\',' + (!ed) + ')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button></div><textarea class="ma-textarea" id="detItpCustom"' + dis + '>' + esc(m.itp_custom || '') + '</textarea></div>' +
                            '<div class="ma-field"><div class="ma-label-row"><label class="ma-label">Alur Tujuan Pembelajaran (ATP)</label><button type="button" class="ma-expand-btn" onclick="MA.openTextPopup(\'Alur Tujuan Pembelajaran (ATP)\',\'detAtpCustom\',' + (!ed) + ')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button></div><textarea class="ma-textarea" id="detAtpCustom"' + dis + '>' + esc(m.atp_custom || '') + '</textarea></div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +

                // SECTION 4: KOMPONEN PEMBELAJARAN
                '<div class="ma-section-card">' +
                    '<div class="ma-section-card__head">' +
                        '<h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:layers-minimalistic-bold"></iconify-icon></span> 4. Model, Metode, Media &amp; Sumber Belajar</h3>' +
                        (ed ? '<button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.applySintaksToDetail()"><iconify-icon icon="solar:magic-stick-3-bold"></iconify-icon> Terapkan Sintaks Model ke Kegiatan</button>' : '') +
                    '</div>' +
                    '<div class="ma-section-card__body">' +
                        '<div class="ma-form-grid">' +
                            '<div class="ma-field ma-field--full"><label class="ma-label">Model Pembelajaran</label><div class="ma-check-grid">' + modelHtml + '</div></div>' +
                            '<div class="ma-field ma-field--full"><label class="ma-label">Metode Pembelajaran</label><div class="ma-check-grid">' + metodeHtml + '</div></div>' +
                            '<div class="ma-field"><label class="ma-label">Media &amp; Sarana Prasarana</label><textarea class="ma-textarea" id="detMedia"' + dis + '>' + esc(m.media_pembelajaran || '') + '</textarea></div>' +
                            '<div class="ma-field"><label class="ma-label">Sumber Belajar / Daftar Pustaka</label><textarea class="ma-textarea" id="detSumber"' + dis + '>' + esc(m.sumber_belajar || '') + '</textarea></div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +

                // SECTION 5: PEMAHAMAN BERMAKNA, PEMANTIK & KEGIATAN PEMBELAJARAN
                '<div class="ma-section-card">' +
                    '<div class="ma-section-card__head"><h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:clipboard-list-bold"></iconify-icon></span> 5. Pemantik, Alokasi Waktu &amp; Skenario Kegiatan</h3></div>' +
                    '<div class="ma-section-card__body">' +
                        '<div class="ma-form-grid" style="margin-bottom:14px;">' +
                            '<div class="ma-field"><div class="ma-label-row"><label class="ma-label">Pemahaman Bermakna</label><button type="button" class="ma-expand-btn" onclick="MA.openTextPopup(\'Pemahaman Bermakna\',\'detPemahaman\',' + (!ed) + ')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button></div><textarea class="ma-textarea" id="detPemahaman"' + dis + '>' + esc(m.pemahaman_bermakna || '') + '</textarea></div>' +
                            '<div class="ma-field"><div class="ma-label-row"><label class="ma-label">Pertanyaan Pemantik</label><button type="button" class="ma-expand-btn" onclick="MA.openTextPopup(\'Pertanyaan Pemantik\',\'detPemantik\',' + (!ed) + ')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button></div><textarea class="ma-textarea" id="detPemantik"' + dis + '>' + esc(m.pertanyaan_pemantik || '') + '</textarea></div>' +
                        '</div>' +

                        '<div class="ma-form-grid ma-form-grid--4" style="margin-bottom:12px;">' +
                            '<div class="ma-field"><label class="ma-label">Menit / JP</label><input type="number" class="ma-input" id="detPerJp" value="' + m.alokasi_per_jp + '"' + dis + ' oninput="MA.recalcDetDuration(true)"></div>' +
                            '<div class="ma-field"><label class="ma-label">Jumlah JP</label><input type="number" class="ma-input" id="detJmlJp" value="' + m.jumlah_jp + '"' + dis + ' oninput="MA.recalcDetDuration(true)"></div>' +
                            '<div class="ma-field"><label class="ma-label">Pembukaan (mnt)</label><input type="number" class="ma-input" id="detDurBuka" value="' + m.durasi_pembukaan + '"' + dis + ' oninput="MA.recalcDetDuration(false)"></div>' +
                            '<div class="ma-field"><label class="ma-label">Inti / Penutup (mnt)</label><div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;"><input type="number" class="ma-input" id="detDurInti" value="' + m.durasi_inti + '"' + dis + ' oninput="MA.recalcDetDuration(false)"><input type="number" class="ma-input" id="detDurTutup" value="' + m.durasi_penutup + '"' + dis + ' oninput="MA.recalcDetDuration(false)"></div></div>' +
                        '</div>' +
                        '<div class="ma-duration-box' + (durExceeded ? ' is-exceeded' : '') + '" id="detDurationSummary" style="margin-bottom:14px;">' +
                            '<span>Total Alokasi: <strong>' + m.total_menit + ' Menit</strong> (' + m.jumlah_jp + ' JP &times; ' + m.alokasi_per_jp + ' mnt)</span>' +
                            '<span>Terpakai: <strong>' + sumDur + ' Menit</strong>' + (durExceeded ? ' — Melebihi Alokasi!' : '') + '</span>' +
                        '</div>' +

                        '<div class="ma-form-grid">' +
                            '<div class="ma-field ma-field--full"><label class="ma-label">Persiapan Pembelajaran</label><textarea class="ma-textarea" id="detPersiapan"' + dis + '>' + esc(m.persiapan_pembelajaran || '') + '</textarea></div>' +
                            '<div class="ma-field ma-field--full"><div class="ma-label-row"><label class="ma-label">Kegiatan Pendahuluan / Pembukaan</label><button type="button" class="ma-expand-btn" onclick="MA.openTextPopup(\'Kegiatan Pendahuluan\',\'detKegBuka\',' + (!ed) + ')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button></div><textarea class="ma-textarea" id="detKegBuka"' + dis + '>' + esc(m.kegiatan_pendahuluan || '') + '</textarea></div>' +
                            '<div class="ma-field ma-field--full"><div class="ma-label-row"><label class="ma-label">Kegiatan Inti</label><button type="button" class="ma-expand-btn" onclick="MA.openTextPopup(\'Kegiatan Inti\',\'detKegInti\',' + (!ed) + ')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button></div><textarea class="ma-textarea ma-textarea--lg" id="detKegInti"' + dis + '>' + esc(m.kegiatan_inti || '') + '</textarea></div>' +
                            '<div class="ma-field ma-field--full"><div class="ma-label-row"><label class="ma-label">Kegiatan Penutup</label><button type="button" class="ma-expand-btn" onclick="MA.openTextPopup(\'Kegiatan Penutup\',\'detKegTutup\',' + (!ed) + ')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button></div><textarea class="ma-textarea" id="detKegTutup"' + dis + '>' + esc(m.kegiatan_penutup || '') + '</textarea></div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +

                // SECTION 6: ASESMEN, P5-PPRA & REFLEKSI
                '<div class="ma-section-card">' +
                    '<div class="ma-section-card__head"><h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:medal-star-circle-bold"></iconify-icon></span> 6. Asesmen, Profil Pelajar Pancasila &amp; Refleksi</h3></div>' +
                    '<div class="ma-section-card__body">' +
                        '<div class="ma-form-grid">' +
                            '<div class="ma-field ma-field--full"><label class="ma-label">Profil Pelajar Pancasila &amp; Rahmatan Lil Alamin</label><textarea class="ma-textarea" id="detP5"' + dis + '>' + esc(m.profil_pelajar_pancasila || '') + '</textarea></div>' +
                            '<div class="ma-field"><label class="ma-label">Asesmen Diagnostik</label><textarea class="ma-textarea" id="detAsDiag"' + dis + '>' + esc(m.asesmen_diagnostik || '') + '</textarea></div>' +
                            '<div class="ma-field"><label class="ma-label">Asesmen Formatif</label><textarea class="ma-textarea" id="detAsForm"' + dis + '>' + esc(m.asesmen_formatif || '') + '</textarea></div>' +
                            '<div class="ma-field"><label class="ma-label">Asesmen Sumatif</label><textarea class="ma-textarea" id="detAsSum"' + dis + '>' + esc(m.asesmen_sumatif || '') + '</textarea></div>' +
                            '<div class="ma-field"><label class="ma-label">Pengayaan &amp; Remedial</label><textarea class="ma-textarea" id="detPengRem"' + dis + '>' + esc(m.pengayaan_remedial || '') + '</textarea></div>' +
                            '<div class="ma-field"><label class="ma-label">Refleksi Guru</label><textarea class="ma-textarea" id="detRefGuru"' + dis + '>' + esc(m.refleksi_guru || '') + '</textarea></div>' +
                            '<div class="ma-field"><label class="ma-label">Refleksi Peserta Didik</label><textarea class="ma-textarea" id="detRefSiswa"' + dis + '>' + esc(m.refleksi_siswa || '') + '</textarea></div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +

                // SECTION 7: LKPD, GLOSARIUM & LAMPIRAN
                '<div class="ma-section-card">' +
                    '<div class="ma-section-card__head">' +
                        '<h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:paperclip-bold"></iconify-icon></span> 7. LKPD, Glosarium &amp; Berkas Lampiran</h3>' +
                        (!isFinal ? '<label class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" style="cursor:pointer;margin:0;"><iconify-icon icon="solar:upload-bold"></iconify-icon> Unggah Lampiran<input type="file" style="display:none;" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.zip" onchange="MA.uploadLampiran(' + m.id + ', this)"></label>' : '') +
                    '</div>' +
                    '<div class="ma-section-card__body">' +
                        '<div class="ma-form-grid" style="margin-bottom:14px;">' +
                            '<div class="ma-field"><label class="ma-label">Panduan LKPD</label><textarea class="ma-textarea" id="detLkpd"' + dis + '>' + esc(m.lkpd || '') + '</textarea></div>' +
                            '<div class="ma-field"><label class="ma-label">Bahan Bacaan</label><textarea class="ma-textarea" id="detBahan"' + dis + '>' + esc(m.bahan_bacaan || '') + '</textarea></div>' +
                            '<div class="ma-field ma-field--full"><label class="ma-label">Glosarium</label><textarea class="ma-textarea" id="detGlosarium"' + dis + '>' + esc(m.glosarium || '') + '</textarea></div>' +
                        '</div>' +
                        '<label class="ma-label" style="margin-bottom:8px;">Daftar Berkas Lampiran</label>' +
                        '<div class="ma-file-list" id="detLampiranList">' + attHtml + '</div>' +
                    '</div>' +
                '</div>';

            if (window.IlmunaCalendar && typeof window.IlmunaCalendar.bind === 'function') {
                if (ed) {
                    window.IlmunaCalendar.bind('#detTanggal', false, 'date');
                    window.IlmunaCalendar.bind('#detJamMulai, #detJamSelesai', true, 'time');
                }
            }
        },

        toggleDetailEdit(enable) {
            this.state.detailEditMode = !!enable;
            this.renderDetailView();
        },

        onDetailCpToggle() {
            document.querySelectorAll('.det-cp-chk').forEach(function (chk) {
                chk.closest('.ma-check-card').classList.toggle('is-checked', chk.checked);
            });
        },

        onDetailTpToggle() {
            document.querySelectorAll('.det-tp-chk').forEach(function (chk) {
                chk.closest('.ma-check-card').classList.toggle('is-checked', chk.checked);
            });
        },

        applySintaksToDetail() {
            const ids = Array.from(document.querySelectorAll('.det-model-chk:checked')).map(function (c) { return Number(c.value); });
            if (!ids.length) {
                notify('warning', 'Centang minimal 1 Model Pembelajaran terlebih dahulu.');
                return;
            }
            const tpl = buildSintaksText(ids, this.state.models);
            if (!tpl) return;
            const bEl = document.getElementById('detKegBuka');
            const iEl = document.getElementById('detKegInti');
            const tEl = document.getElementById('detKegTutup');
            if (bEl && !bEl.value.trim()) bEl.value = tpl.pembukaan;
            if (iEl) iEl.value = tpl.inti;
            if (tEl && !tEl.value.trim()) tEl.value = tpl.penutup;
            notify('success', 'Sintaks model pembelajaran berhasil diterapkan ke Kegiatan Pembelajaran.');
        },

        recalcDetDuration(autoSplit) {
            const perJp = Math.max(10, parseInt(document.getElementById('detPerJp').value, 10) || 45);
            const jmlJp = Math.max(1, parseInt(document.getElementById('detJmlJp').value, 10) || 2);
            const total = perJp * jmlJp;
            const eB = document.getElementById('detDurBuka');
            const eI = document.getElementById('detDurInti');
            const eT = document.getElementById('detDurTutup');
            if (autoSplit) {
                const b = Math.round(total * 0.15);
                const i = Math.round(total * 0.70);
                const t = Math.max(5, total - b - i);
                eB.value = b; eI.value = i; eT.value = t;
            }
            const sum = (parseInt(eB.value, 10) || 0) + (parseInt(eI.value, 10) || 0) + (parseInt(eT.value, 10) || 0);
            const box = document.getElementById('detDurationSummary');
            if (box) {
                box.classList.toggle('is-exceeded', sum > total);
                box.innerHTML = '<span>Total Alokasi: <strong>' + total + ' Menit</strong> (' + jmlJp + ' JP &times; ' + perJp + ' mnt)</span>' +
                    '<span>Terpakai: <strong>' + sum + ' Menit</strong>' + (sum > total ? ' — Melebihi Alokasi!' : '') + '</span>';
            }
        },

        async saveDetailChanges() {
            const m = this.state.activeDetail && this.state.activeDetail.modul;
            if (!m) return;
            try {
                const payload = {
                    id: m.id,
                    judul_modul: document.getElementById('detJudul').value.trim(),
                    pertemuan_ke: parseInt(document.getElementById('detPertemuan').value, 10) || 1,
                    fase: document.getElementById('detFase').value.trim(),
                    tahun_pelajaran: document.getElementById('detTahun').value,
                    semester: document.getElementById('detSemester').value,
                    tanggal: document.getElementById('detTanggal').value.trim(),
                    jam_mulai: document.getElementById('detJamMulai').value,
                    jam_selesai: document.getElementById('detJamSelesai').value,
                    target_peserta_didik: document.getElementById('detTargetDidik').value.trim(),
                    id_materi: document.getElementById('detMateriId').value || 0,
                    cp_ids: Array.from(document.querySelectorAll('.det-cp-chk:checked')).map(function (c) { return Number(c.value); }),
                    tp_ids: Array.from(document.querySelectorAll('.det-tp-chk:checked')).map(function (c) { return Number(c.value); }),
                    model_ids: Array.from(document.querySelectorAll('.det-model-chk:checked')).map(function (c) { return Number(c.value); }),
                    metode_ids: Array.from(document.querySelectorAll('.det-metode-chk:checked')).map(function (c) { return Number(c.value); }),
                    tp_custom: document.getElementById('detTpCustom').value.trim(),
                    itp_custom: document.getElementById('detItpCustom').value.trim(),
                    atp_custom: document.getElementById('detAtpCustom').value.trim(),
                    media_pembelajaran: document.getElementById('detMedia').value.trim(),
                    sumber_belajar: document.getElementById('detSumber').value.trim(),
                    pemahaman_bermakna: document.getElementById('detPemahaman').value.trim(),
                    pertanyaan_pemantik: document.getElementById('detPemantik').value.trim(),
                    alokasi_per_jp: parseInt(document.getElementById('detPerJp').value, 10) || 45,
                    jumlah_jp: parseInt(document.getElementById('detJmlJp').value, 10) || 2,
                    durasi_pembukaan: parseInt(document.getElementById('detDurBuka').value, 10) || 15,
                    durasi_inti: parseInt(document.getElementById('detDurInti').value, 10) || 60,
                    durasi_penutup: parseInt(document.getElementById('detDurTutup').value, 10) || 15,
                    persiapan_pembelajaran: document.getElementById('detPersiapan').value.trim(),
                    kegiatan_pendahuluan: document.getElementById('detKegBuka').value.trim(),
                    kegiatan_inti: document.getElementById('detKegInti').value.trim(),
                    kegiatan_penutup: document.getElementById('detKegTutup').value.trim(),
                    profil_pelajar_pancasila: document.getElementById('detP5').value.trim(),
                    asesmen_diagnostik: document.getElementById('detAsDiag').value.trim(),
                    asesmen_formatif: document.getElementById('detAsForm').value.trim(),
                    asesmen_sumatif: document.getElementById('detAsSum').value.trim(),
                    pengayaan_remedial: document.getElementById('detPengRem').value.trim(),
                    refleksi_guru: document.getElementById('detRefGuru').value.trim(),
                    refleksi_siswa: document.getElementById('detRefSiswa').value.trim(),
                    lkpd: document.getElementById('detLkpd').value.trim(),
                    bahan_bacaan: document.getElementById('detBahan').value.trim(),
                    glosarium: document.getElementById('detGlosarium').value.trim()
                };
                const res = await apiPost('update_modul', payload);
                notify('success', res.message);
                await this.openModulDetail(m.id, false);
            } catch (err) {
                notify('error', err.message);
            }
        },

        selectExecStatus(key, val) {
            const inp = document.getElementById('execStatus_' + key);
            if (inp) inp.value = val;
            document.querySelectorAll('[data-exec-key="' + key + '"]').forEach(function (btn) {
                btn.classList.remove('is-belum', 'is-sebagian', 'is-terlaksana');
                if (btn.getAttribute('data-exec-val') === val) {
                    btn.classList.add('is-' + val);
                }
            });
        },

        async saveKeterlaksanaan(id) {
            try {
                const payload = {
                    id: id,
                    status_pembukaan: document.getElementById('execStatus_pembukaan').value,
                    status_inti: document.getElementById('execStatus_inti').value,
                    status_penutup: document.getElementById('execStatus_penutup').value,
                    catatan_pembukaan: document.getElementById('execNote_pembukaan').value.trim(),
                    catatan_inti: document.getElementById('execNote_inti').value.trim(),
                    catatan_penutup: document.getElementById('execNote_penutup').value.trim()
                };
                const res = await apiPost('save_keterlaksanaan', payload);
                notify('success', res.message);
                await this.openModulDetail(id, false);
            } catch (err) {
                notify('error', err.message);
            }
        },

        async togglePublishModul(id) {
            const m = this.state.activeDetail && this.state.activeDetail.modul;
            const willPub = m && m.status !== 'Published';
            const ok = await confirmAction({
                title: willPub ? 'Terbitkan Modul Ajar' : 'Kembalikan ke Draft',
                message: willPub
                    ? 'Setelah diterbitkan (Published), isi rencana modul terkunci dan Anda dapat mengisi status keterlaksanaan KBM.'
                    : 'Kembalikan modul ajar ke Draft agar dapat disunting kembali?',
                confirmText: willPub ? 'Ya, Terbitkan' : 'Ya, Kembalikan ke Draft'
            });
            if (!ok) return;
            try {
                const res = await apiPost('toggle_publish', { id: id });
                notify('success', res.message);
                await this.loadBootstrap(false);
                await this.openModulDetail(id, false);
            } catch (err) {
                notify('error', err.message);
            }
        },

        async finalizeModul(id) {
            const ok = await confirmAction({
                title: 'Finalisasi Pertemuan Modul Ajar',
                message: 'PERHATIAN: Setelah difinalisasi, modul ajar dan status keterlaksanaan akan terkunci permanen dan tidak dapat diubah atau dihapus lagi. Yakin ingin melanjutkan?',
                confirmText: 'Ya, Finalisasi Permanen',
                danger: true
            });
            if (!ok) return;
            try {
                const res = await apiPost('finalize_modul', { id: id });
                notify('success', res.message);
                await this.loadBootstrap(false);
                await this.openModulDetail(id, false);
            } catch (err) {
                notify('error', err.message);
            }
        },

        async uploadLampiran(id, fileInput) {
            if (!fileInput.files || !fileInput.files[0]) return;
            const fd = new FormData();
            fd.append('id', String(id));
            fd.append('file_lampiran', fileInput.files[0]);
            try {
                const res = await apiPost('upload_lampiran', fd, true);
                notify('success', res.message);
                fileInput.value = '';
                await this.openModulDetail(id, this.state.detailEditMode);
            } catch (err) {
                notify('error', err.message);
                fileInput.value = '';
            }
        },

        async deleteLampiran(id, attId) {
            const ok = await confirmAction({
                title: 'Hapus Lampiran',
                message: 'Hapus berkas lampiran ini dari modul ajar?',
                confirmText: 'Ya, Hapus',
                danger: true
            });
            if (!ok) return;
            try {
                const res = await apiPost('delete_lampiran', { id: id, attachment_id: attId });
                notify('success', res.message);
                await this.openModulDetail(id, this.state.detailEditMode);
            } catch (err) {
                notify('error', err.message);
            }
        },

        /* ======================================================================
         * 6-STEP WIZARD CREATE MODUL AJAR (MODULAJARCREATE)
         * ====================================================================== */
        async openCreateWizard(preselectKmpId, prefillTp) {
            if (!this.state.subjects.length) {
                notify('warning', 'Belum ada data Mata Pelajaran & Rombel Kelas yang tersedia.');
                return;
            }
            // Reset form
            document.getElementById('formCreateModul').reset();
            this.state.wizSelectedCpIds = [];
            this.state.wizSelectedTpIds = [];
            this.state.wizSelectedModelIds = [];
            this.state.wizSelectedMetodeIds = [];
            this.state.wizSelectedKmpIds = [];

            document.getElementById('wizTahun').value = this.state.defaultTahun;
            document.getElementById('wizSemester').value = this.state.defaultSem;
            document.getElementById('wizAlokasiPerJp').value = 45;
            document.getElementById('wizJumlahJp').value = 2;
            document.getElementById('wizTargetDidik').value = 'Peserta Didik Reguler / Tipikal';
            document.getElementById('wizJamMulai').value = '07:30';
            document.getElementById('wizJamSelesai').value = '09:00';
            document.getElementById('wizTanggal').value = new Date().toISOString().slice(0, 10);
            this.recalcWizDuration(true);

            // Render Preset Chips (Media, Sumber, P5)
            this.renderWizardChips();
            this.renderWizardModelsAndMetodes();

            // Select subject & class
            let targetMapelId = this.state.subjects[0].id_mata_pelajaran;
            if (preselectKmpId) {
                const foundKmp = this.state.kmpList.find(function (k) { return Number(k.id_kmp) === Number(preselectKmpId); });
                if (foundKmp) {
                    targetMapelId = foundKmp.id_mata_pelajaran;
                    this.state.wizSelectedKmpIds = [Number(foundKmp.id_kmp)];
                }
            }
            document.getElementById('wizMapel').value = String(targetMapelId);
            await this.onWizMapelChange(preselectKmpId || 0);

            // Prefill from TP button if provided
            if (prefillTp) {
                if (prefillTp.materi_pokok) {
                    document.getElementById('wizJudul').value = prefillTp.materi_pokok;
                }
                if (prefillTp.id_cp) {
                    this.state.wizSelectedCpIds = [Number(prefillTp.id_cp)];
                }
                if (prefillTp.id) {
                    this.state.wizSelectedTpIds = [Number(prefillTp.id)];
                }
                this.renderWizardCpTpLists();
                this.syncWizardTpOutputs();
            }

            this.wizGoStep(1);
            openIlmunaModal('modalCreateModulAjar');
            if (window.IlmunaCalendar && typeof window.IlmunaCalendar.bind === 'function') {
                window.IlmunaCalendar.bind('#wizTanggal', false, 'date');
                window.IlmunaCalendar.bind('#wizJamMulai, #wizJamSelesai', true, 'time');
            }
        },

        renderWizardChips() {
            const buildChips = function (containerId, presets, inputId) {
                const el = document.getElementById(containerId);
                if (!el) return;
                el.innerHTML = presets.map(function (item) {
                    return '<button type="button" class="ma-chip-btn" onclick="MA.togglePresetChip(this,\'' + inputId + '\',\'' + esc(item).replace(/'/g, "\\'") + '\')">' + esc(item) + '</button>';
                }).join('');
            };
            buildChips('wizMediaChips', MEDIA_PRESETS, 'wizMedia');
            buildChips('wizSumberChips', SUMBER_PRESETS, 'wizSumber');
            buildChips('wizP5Chips', P5_PRESETS, 'wizP5');
        },

        togglePresetChip(btn, inputId, textVal) {
            const inp = document.getElementById(inputId);
            if (!inp) return;
            const sep = (inputId === 'wizP5') ? '; ' : ', ';
            let items = inp.value.split(/[;,]\s*/).map(function (s) { return s.trim(); }).filter(Boolean);
            const idx = items.indexOf(textVal);
            if (idx === -1) {
                items.push(textVal);
                btn.classList.add('is-active');
            } else {
                items.splice(idx, 1);
                btn.classList.remove('is-active');
            }
            inp.value = items.join(sep);
        },

        renderWizardModelsAndMetodes() {
            const mGrid = document.getElementById('wizModelGrid');
            if (mGrid) {
                mGrid.innerHTML = this.state.models.map(function (mod) {
                    return '<label class="ma-check-card">' +
                        '<input type="checkbox" class="wiz-model-chk" value="' + mod.id + '" onchange="MA.onWizModelChange()">' +
                        '<div class="ma-check-card__body"><span class="ma-check-card__title">' + esc(mod.nama_model) + '</span><span class="ma-check-card__desc">' + esc((mod.deskripsi || '').slice(0, 90)) + '</span></div>' +
                    '</label>';
                }).join('');
            }
            const mtGrid = document.getElementById('wizMetodeGrid');
            if (mtGrid) {
                mtGrid.innerHTML = this.state.metodes.map(function (mt) {
                    return '<label class="ma-check-card">' +
                        '<input type="checkbox" class="wiz-metode-chk" value="' + mt.id + '" onchange="MA.onWizMetodeChange()">' +
                        '<div class="ma-check-card__body"><span class="ma-check-card__title">' + esc(mt.nama_metode) + '</span></div>' +
                    '</label>';
                }).join('');
            }
        },

        async onWizMapelChange(forceKmpId) {
            const mapelId = Number(document.getElementById('wizMapel').value);
            const subj = this.state.subjects.find(function (s) { return Number(s.id_mata_pelajaran) === mapelId; });
            const classes = subj ? subj.classes : [];

            const grid = document.getElementById('wizClassCheckGrid');
            if (!grid) return;

            let defaultSelect = [];
            if (forceKmpId && classes.some(function (c) { return Number(c.id_kmp) === Number(forceKmpId); })) {
                defaultSelect = [Number(forceKmpId)];
            } else if (classes.length) {
                defaultSelect = [Number(classes[0].id_kmp)];
            }
            this.state.wizSelectedKmpIds = defaultSelect;

            grid.innerHTML = classes.map(function (c) {
                const chk = defaultSelect.indexOf(Number(c.id_kmp)) !== -1;
                return '<label class="ma-check-card' + (chk ? ' is-checked' : '') + '">' +
                    '<input type="checkbox" class="wiz-kmp-chk" value="' + c.id_kmp + '"' + (chk ? ' checked' : '') + ' onchange="MA.onWizClassToggle()">' +
                    '<div class="ma-check-card__body">' +
                        '<span class="ma-check-card__title">' + esc(c.nama_kelas) + ' (' + esc(c.fase) + ')</span>' +
                        '<span class="ma-check-card__desc">' + c.cp_count + ' CP &bull; ' + c.tp_count + ' TP &bull; ' + c.modul_total + ' Modul</span>' +
                    '</div>' +
                '</label>';
            }).join('');

            await this.loadWizardCurriculumForPrimaryKmp();
        },

        async wizToggleAllClasses() {
            const chks = Array.from(document.querySelectorAll('.wiz-kmp-chk'));
            const allChecked = chks.every(function (c) { return c.checked; });
            chks.forEach(function (c) {
                c.checked = !allChecked;
                c.closest('.ma-check-card').classList.toggle('is-checked', c.checked);
            });
            await this.onWizClassToggle();
        },

        async onWizClassToggle() {
            const ids = [];
            document.querySelectorAll('.wiz-kmp-chk').forEach(function (c) {
                c.closest('.ma-check-card').classList.toggle('is-checked', c.checked);
                if (c.checked) ids.push(Number(c.value));
            });
            this.state.wizSelectedKmpIds = ids;
            await this.loadWizardCurriculumForPrimaryKmp();
        },

        async loadWizardCurriculumForPrimaryKmp() {
            const primaryKmp = this.state.wizSelectedKmpIds[0] || 0;
            if (!primaryKmp) {
                this.state.wizCpList = [];
                this.state.wizTpList = [];
                this.renderWizardCpTpLists();
                return;
            }
            try {
                const res = await apiGet('get_kmp_curriculum', { id_kmp: primaryKmp });
                this.state.wizCpList = res.data.cp_list || [];
                this.state.wizTpList = res.data.tp_list || [];
                document.getElementById('wizPertemuanKe').value = res.data.next_pertemuan || 1;
                if (res.data.fase) {
                    const fSel = document.getElementById('wizFase');
                    if (fSel) fSel.value = res.data.fase;
                }
                this.renderWizardCpTpLists();
            } catch (err) {
                notify('error', err.message);
            }
        },

        renderWizardCpTpLists() {
            const self = this;
            const warnEl = document.getElementById('wizCpEmptyWarn');
            const cpEl = document.getElementById('wizCpList');
            const tpEl = document.getElementById('wizTpList');

            if (!this.state.wizCpList.length) {
                if (warnEl) {
                    warnEl.innerHTML = '<div class="ma-alert-banner ma-alert-banner--amber" style="margin-bottom:14px;"><div class="ma-alert-banner__body"><iconify-icon icon="solar:info-circle-bold"></iconify-icon><div><p class="ma-alert-banner__title">Kelas ini belum memiliki Capaian Pembelajaran (CP)</p><p class="ma-alert-banner__desc">Klik tombol "Tambah CP Baru" di bawah atau sinkronkan CP dari tab Capaian Pembelajaran.</p></div></div></div>';
                }
                if (cpEl) cpEl.innerHTML = '<p style="font-size:12.5px;color:#64748B;margin:4px 0;">Belum ada CP untuk kelas terpilih.</p>';
            } else {
                if (warnEl) warnEl.innerHTML = '';
                if (cpEl) {
                    cpEl.innerHTML = this.state.wizCpList.map(function (c) {
                        const chk = self.state.wizSelectedCpIds.indexOf(Number(c.id)) !== -1;
                        return '<label class="ma-check-card' + (chk ? ' is-checked' : '') + '">' +
                            '<input type="checkbox" class="wiz-cp-chk" value="' + c.id + '"' + (chk ? ' checked' : '') + ' onchange="MA.onWizCpChange()">' +
                            '<div class="ma-check-card__body">' +
                                '<span class="ma-check-card__title">' + esc(c.nama_elemen || 'Capaian Pembelajaran') + (c.fase ? ' • ' + esc(c.fase) : '') + '</span>' +
                                '<span class="ma-check-card__desc">' + esc(c.cp) + '</span>' +
                            '</div>' +
                        '</label>';
                    }).join('');
                }
            }

            // Filter TP list by selected CPs (or show all TPs of this KMP if none checked yet)
            const selCps = this.state.wizSelectedCpIds;
            const visibleTps = selCps.length
                ? this.state.wizTpList.filter(function (t) { return selCps.indexOf(Number(t.id_cp)) !== -1; })
                : this.state.wizTpList;

            if (tpEl) {
                if (!visibleTps.length) {
                    tpEl.innerHTML = '<p style="font-size:12.5px;color:#64748B;margin:4px 0;">Belum ada TP untuk CP terpilih. Klik tombol <strong>"Buat TP Cepat"</strong> di atas untuk langsung menambah TP.</p>';
                } else {
                    tpEl.innerHTML = visibleTps.map(function (t) {
                        const chk = self.state.wizSelectedTpIds.indexOf(Number(t.id)) !== -1;
                        return '<label class="ma-check-card' + (chk ? ' is-checked' : '') + '">' +
                            '<input type="checkbox" class="wiz-tp-chk" value="' + t.id + '"' + (chk ? ' checked' : '') + ' onchange="MA.onWizTpChange()">' +
                            '<div class="ma-check-card__body">' +
                                '<span class="ma-check-card__title">' + esc(t.kode_tp || ('TP-' + t.id)) + (t.materi_pokok ? ' — ' + esc(t.materi_pokok) : '') + ' (' + (t.alokasi_jam || 2) + ' JP)</span>' +
                                '<span class="ma-check-card__desc">' + esc(t.tp) + '</span>' +
                            '</div>' +
                        '</label>';
                    }).join('');
                }
            }
        },

        onWizCpChange() {
            const ids = [];
            document.querySelectorAll('.wiz-cp-chk').forEach(function (c) {
                c.closest('.ma-check-card').classList.toggle('is-checked', c.checked);
                if (c.checked) ids.push(Number(c.value));
            });
            this.state.wizSelectedCpIds = ids;
            this.renderWizardCpTpLists();
        },

        onWizTpChange() {
            const ids = [];
            document.querySelectorAll('.wiz-tp-chk').forEach(function (c) {
                c.closest('.ma-check-card').classList.toggle('is-checked', c.checked);
                if (c.checked) ids.push(Number(c.value));
            });
            this.state.wizSelectedTpIds = ids;
            this.syncWizardTpOutputs();
        },

        syncWizardTpOutputs() {
            const selTps = this.state.wizTpList.filter(function (t) {
                return MA.state.wizSelectedTpIds.indexOf(Number(t.id)) !== -1;
            });
            if (!selTps.length) return;

            const tpLines = [];
            const itpLines = [];
            const atpLines = [];
            selTps.forEach(function (t, idx) {
                tpLines.push((t.kode_tp ? '[' + t.kode_tp + '] ' : (idx + 1) + '. ') + t.tp);
                (t.itp_list || []).forEach(function (i) {
                    itpLines.push((i.kode_itp ? '[' + i.kode_itp + '] ' : '• ') + i.itp);
                });
                (t.atp_list || []).forEach(function (a) {
                    atpLines.push('• ' + a.atp);
                });
            });

            document.getElementById('wizTpCustom').value = tpLines.join('\n');
            if (itpLines.length) document.getElementById('wizItpCustom').value = itpLines.join('\n');
            if (atpLines.length) document.getElementById('wizAtpCustom').value = atpLines.join('\n');

            // Auto-fill judul if still blank
            const jEl = document.getElementById('wizJudul');
            if (jEl && !jEl.value.trim() && selTps[0].materi_pokok) {
                jEl.value = selTps[0].materi_pokok;
            }
        },

        onWizModelChange() {
            const ids = [];
            document.querySelectorAll('.wiz-model-chk').forEach(function (c) {
                c.closest('.ma-check-card').classList.toggle('is-checked', c.checked);
                if (c.checked) ids.push(Number(c.value));
            });
            this.state.wizSelectedModelIds = ids;
            // Auto-populate sintaks jika kegiatan inti masih kosong
            const intiEl = document.getElementById('wizKegInti');
            if (ids.length && intiEl && !intiEl.value.trim()) {
                this.applySintaksToWizard(true);
            }
        },

        onWizMetodeChange() {
            const ids = [];
            document.querySelectorAll('.wiz-metode-chk').forEach(function (c) {
                c.closest('.ma-check-card').classList.toggle('is-checked', c.checked);
                if (c.checked) ids.push(Number(c.value));
            });
            this.state.wizSelectedMetodeIds = ids;
        },

        applySintaksToWizard(silent) {
            if (!this.state.wizSelectedModelIds.length) {
                if (!silent) notify('warning', 'Pilih minimal 1 Model Pembelajaran terlebih dahulu.');
                return;
            }
            const tpl = buildSintaksText(this.state.wizSelectedModelIds, this.state.models);
            if (!tpl) return;
            const buka = document.getElementById('wizKegPendahuluan');
            const inti = document.getElementById('wizKegInti');
            const tutup = document.getElementById('wizKegPenutup');
            if (buka && !buka.value.trim()) buka.value = tpl.pembukaan;
            if (inti) inti.value = tpl.inti;
            if (tutup && !tutup.value.trim()) tutup.value = tpl.penutup;
            if (!silent) notify('success', 'Template sintaks model pembelajaran otomatis diisi ke Langkah 4 (Kegiatan).');
        },

        recalcWizDuration(autoSplit) {
            const perJp = Math.max(10, parseInt(document.getElementById('wizAlokasiPerJp').value, 10) || 45);
            const jmlJp = Math.max(1, parseInt(document.getElementById('wizJumlahJp').value, 10) || 2);
            const total = perJp * jmlJp;
            const eB = document.getElementById('wizDurPembukaan');
            const eI = document.getElementById('wizDurInti');
            const eT = document.getElementById('wizDurPenutup');
            if (autoSplit) {
                const b = Math.round(total * 0.15);
                const i = Math.round(total * 0.70);
                const t = Math.max(5, total - b - i);
                eB.value = b; eI.value = i; eT.value = t;
            }
            const bVal = parseInt(eB.value, 10) || 0;
            const iVal = parseInt(eI.value, 10) || 0;
            const tVal = parseInt(eT.value, 10) || 0;
            const sum = bVal + iVal + tVal;
            const box = document.getElementById('wizDurationSummary');
            if (box) {
                box.classList.toggle('is-exceeded', sum > total);
                box.innerHTML = '<span>Total Alokasi: <strong>' + total + ' Menit</strong> (' + jmlJp + ' JP &times; ' + perJp + ' Menit)</span>' +
                    '<span>Terpakai: <strong>' + sum + ' Menit</strong> (Buka ' + bVal + 'm + Inti ' + iVal + 'm + Tutup ' + tVal + 'm)' + (sum > total ? ' — Melebihi Alokasi!' : '') + '</span>';
            }
        },

        wizGoStep(step) {
            const target = Math.max(1, Math.min(6, Number(step)));
            // Validasi ringan saat berpindah dari langkah 1 ke langkah > 1
            if (target > 1 && this.state.wizStep === 1) {
                if (!this.state.wizSelectedKmpIds.length) {
                    notify('warning', 'Pilih minimal 1 Kelas / Rombel terlebih dahulu.');
                    return;
                }
                if (!document.getElementById('wizJudul').value.trim()) {
                    notify('warning', 'Isi Judul Modul Ajar / Topik Materi Pokok terlebih dahulu.');
                    document.getElementById('wizJudul').focus();
                    return;
                }
            }
            this.state.wizStep = target;
            document.getElementById('wizSubtitle').textContent = WIZ_SUBTITLES[target] || '';

            document.querySelectorAll('#wizStepper .ma-step-pill').forEach(function (pill) {
                const s = Number(pill.getAttribute('data-step'));
                pill.classList.toggle('is-active', s === target);
                pill.classList.toggle('is-done', s < target);
            });
            document.querySelectorAll('#formCreateModul .ma-wizard-step').forEach(function (pane) {
                pane.classList.toggle('is-active', Number(pane.getAttribute('data-step')) === target);
            });

            document.getElementById('wizBtnPrev').style.visibility = (target === 1) ? 'hidden' : 'visible';
            document.getElementById('wizBtnNext').style.display = (target < 6) ? 'inline-flex' : 'none';
            document.getElementById('wizBtnSave').style.display = (target === 6) ? 'inline-flex' : 'none';

            if (target === 6) {
                this.renderWizardSummary();
            }
            const sc = document.getElementById('wizBodyScroll');
            if (sc) sc.scrollTop = 0;
        },

        wizNext() { this.wizGoStep(this.state.wizStep + 1); },
        wizPrev() { this.wizGoStep(this.state.wizStep - 1); },

        renderWizardSummary() {
            const el = document.getElementById('wizFinalSummary');
            if (!el) return;
            const judul = document.getElementById('wizJudul').value.trim() || '(Belum diisi)';
            const kelasCnt = this.state.wizSelectedKmpIds.length;
            const cpCnt = this.state.wizSelectedCpIds.length;
            const tpCnt = this.state.wizSelectedTpIds.length;
            const pert = document.getElementById('wizPertemuanKe').value || 1;
            const jp = document.getElementById('wizJumlahJp').value || 2;
            const perJp = document.getElementById('wizAlokasiPerJp').value || 45;

            el.innerHTML = '<div class="ma-alert-banner__body">' +
                '<iconify-icon icon="solar:check-circle-bold"></iconify-icon>' +
                '<div>' +
                    '<p class="ma-alert-banner__title">Siap Disimpan: ' + esc(judul) + '</p>' +
                    '<p class="ma-alert-banner__desc">Target: <strong>' + kelasCnt + ' Kelas/Rombel</strong> &bull; Pertemuan ke-' + esc(pert) + ' &bull; ' + jp + ' JP (' + (jp * perJp) + ' Menit) &bull; ' + cpCnt + ' CP &amp; ' + tpCnt + ' TP terkait.</p>' +
                '</div>' +
            '</div>';
        },

        async submitCreateModul() {
            if (!this.state.wizSelectedKmpIds.length) {
                notify('warning', 'Pilih minimal 1 Kelas / Rombel.');
                this.wizGoStep(1);
                return;
            }
            const judul = document.getElementById('wizJudul').value.trim();
            if (!judul) {
                notify('warning', 'Judul Modul Ajar wajib diisi.');
                this.wizGoStep(1);
                return;
            }

            const btn = document.getElementById('wizBtnSave');
            btn.disabled = true;
            try {
                const fd = new FormData();
                fd.append('kmp_ids', JSON.stringify(this.state.wizSelectedKmpIds));
                fd.append('pertemuan_ke', document.getElementById('wizPertemuanKe').value || '1');
                fd.append('judul_modul', judul);
                fd.append('fase', document.getElementById('wizFase').value);
                fd.append('tahun_pelajaran', document.getElementById('wizTahun').value);
                fd.append('semester', document.getElementById('wizSemester').value);
                fd.append('tanggal', document.getElementById('wizTanggal').value);
                fd.append('jam_mulai', document.getElementById('wizJamMulai').value);
                fd.append('jam_selesai', document.getElementById('wizJamSelesai').value);
                fd.append('id_materi', document.getElementById('wizMateriId').value || '0');
                fd.append('cp_ids', JSON.stringify(this.state.wizSelectedCpIds));
                fd.append('tp_ids', JSON.stringify(this.state.wizSelectedTpIds));
                fd.append('tp_custom', document.getElementById('wizTpCustom').value.trim());
                fd.append('itp_custom', document.getElementById('wizItpCustom').value.trim());
                fd.append('atp_custom', document.getElementById('wizAtpCustom').value.trim());
                fd.append('model_ids', JSON.stringify(this.state.wizSelectedModelIds));
                fd.append('metode_ids', JSON.stringify(this.state.wizSelectedMetodeIds));
                fd.append('media_pembelajaran', document.getElementById('wizMedia').value.trim());
                fd.append('sumber_belajar', document.getElementById('wizSumber').value.trim());
                fd.append('pemahaman_bermakna', document.getElementById('wizPemahaman').value.trim());
                fd.append('pertanyaan_pemantik', document.getElementById('wizPemantik').value.trim());
                fd.append('alokasi_per_jp', document.getElementById('wizAlokasiPerJp').value || '45');
                fd.append('jumlah_jp', document.getElementById('wizJumlahJp').value || '2');
                fd.append('durasi_pembukaan', document.getElementById('wizDurPembukaan').value || '15');
                fd.append('durasi_inti', document.getElementById('wizDurInti').value || '60');
                fd.append('durasi_penutup', document.getElementById('wizDurPenutup').value || '15');
                fd.append('target_peserta_didik', document.getElementById('wizTargetDidik').value.trim());
                fd.append('persiapan_pembelajaran', document.getElementById('wizPersiapan').value.trim());
                fd.append('kegiatan_pendahuluan', document.getElementById('wizKegPendahuluan').value.trim());
                fd.append('kegiatan_inti', document.getElementById('wizKegInti').value.trim());
                fd.append('kegiatan_penutup', document.getElementById('wizKegPenutup').value.trim());
                fd.append('profil_pelajar_pancasila', document.getElementById('wizP5').value.trim());
                fd.append('asesmen_diagnostik', document.getElementById('wizAsDiag').value.trim());
                fd.append('asesmen_formatif', document.getElementById('wizAsForm').value.trim());
                fd.append('asesmen_sumatif', document.getElementById('wizAsSum').value.trim());
                fd.append('pengayaan_remedial', document.getElementById('wizPengRem').value.trim());
                fd.append('refleksi_guru', document.getElementById('wizRefGuru').value.trim());
                fd.append('refleksi_siswa', document.getElementById('wizRefSiswa').value.trim());
                fd.append('lkpd', document.getElementById('wizLkpd').value.trim());
                fd.append('bahan_bacaan', document.getElementById('wizBahanBacaan').value.trim());
                fd.append('glosarium', document.getElementById('wizGlosarium').value.trim());
                fd.append('status', document.getElementById('wizStatusInit').value);

                const fInp = document.getElementById('wizFileLampiran');
                if (fInp && fInp.files && fInp.files[0]) {
                    fd.append('file_lampiran', fInp.files[0]);
                }

                const res = await apiPost('create_modul', fd, true);
                notify('success', res.message);
                closeIlmunaModal('modalCreateModulAjar');
                await this.loadBootstrap(false);

                // Langsung buka detail modul yang baru dibuat
                this.switchTab('modul');
                if (res.data && res.data.primary_id) {
                    await this.openModulDetail(res.data.primary_id, false);
                } else if (res.data && res.data.primary_kmp) {
                    await this.openKmpMeetings(res.data.primary_kmp);
                }
            } catch (err) {
                notify('error', err.message, 'Gagal Menyimpan Modul');
            } finally {
                btn.disabled = false;
            }
        },

        openQuickCpFromWizard() {
            this.state.quickReturnToWizard = true;
            const primaryKmp = this.state.wizSelectedKmpIds[0] || 0;
            this.openCpModal(null, primaryKmp);
        },

        openQuickTpFromWizard() {
            if (!this.state.wizCpList.length) {
                notify('warning', 'Tambahkan CP terlebih dahulu sebelum membuat TP.');
                return;
            }
            this.state.quickReturnToWizard = true;
            const preCp = this.state.wizSelectedCpIds[0] || this.state.wizCpList[0].id;
            // Populate CP options from wizard CP list
            this.state.tpCpOptions = this.state.wizCpList.map(function (c) {
                return Object.assign({ nama_kelas: '', nama_mata_pelajaran: '' }, c);
            });
            this.openTpModal(null, preCp);
        },

        /* ======================================================================
         * TAB 1: CAPAIAN PEMBELAJARAN (CP) & ELEMEN
         * ====================================================================== */
        async loadCpTab() {
            const cont = document.getElementById('maCpContainer');
            if (cont) cont.innerHTML = '<div class="ma-skeleton-card" style="height:140px;"></div>';
            try {
                const idKmp = document.getElementById('cpKmpFilter') ? document.getElementById('cpKmpFilter').value : '';
                const q = document.getElementById('cpSearchInput') ? document.getElementById('cpSearchInput').value.trim() : '';
                const res = await apiGet('list_cp', { id_kmp: idKmp, q: q });
                this.state.cpElemenList = res.data.elemen_list || [];
                this.state.cpList = res.data.cp_list || [];
                this.renderCpList();
            } catch (err) {
                notify('error', err.message);
            }
        },

        renderCpList() {
            const cont = document.getElementById('maCpContainer');
            if (!cont) return;
            const list = this.state.cpList;

            if (!list.length) {
                cont.innerHTML =
                    '<div class="ma-empty-state">' +
                        '<div class="ma-empty-icon"><iconify-icon icon="solar:diploma-verified-bold"></iconify-icon></div>' +
                        '<h3>Belum Ada Capaian Pembelajaran (CP)</h3>' +
                        '<p>Tambahkan CP baru atau sinkronkan CP dari kelas lain pada mata pelajaran yang sama.</p>' +
                        '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">' +
                            '<button type="button" class="ilmuna-btn ilmuna-btn--teal" onclick="MA.openCpModal()"><iconify-icon icon="solar:add-circle-bold"></iconify-icon> Tambah CP Baru</button>' +
                            '<button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="MA.openSyncCpModal()"><iconify-icon icon="solar:copy-bold"></iconify-icon> Sinkron dari Kelas Lain</button>' +
                        '</div>' +
                    '</div>';
                return;
            }

            let html = '<div class="ma-cp-list">';
            list.forEach(function (c) {
                html += '<div class="ma-cur-card">' +
                    '<div class="ma-cur-card__head">' +
                        '<div class="ma-cur-card__badges">' +
                            '<span class="ma-tag ma-tag--navy">' + esc(c.nama_mata_pelajaran) + '</span>' +
                            '<span class="ma-tag ma-tag--teal">' + esc(c.nama_kelas) + '</span>' +
                            (c.nama_elemen ? '<span class="ma-tag ma-tag--gold">Elemen: ' + esc(c.nama_elemen) + '</span>' : '') +
                            (c.fase ? '<span class="ma-tag">' + esc(c.fase) + '</span>' : '') +
                            '<span class="ma-mini-pill ma-mini-pill--tp">' + (c.tp_count || 0) + ' TP Terkait</span>' +
                        '</div>' +
                        '<div class="ma-card-actions">' +
                            '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.createTpFromCp(' + c.id + ',' + c.id_kelas_mata_pelajaran + ')">' +
                                '<iconify-icon icon="solar:target-bold"></iconify-icon> Buat TP dari CP ini' +
                            '</button>' +
                            '<button type="button" class="ilmuna-btn-icon" title="Edit CP" onclick="MA.editCpById(' + c.id + ')"><iconify-icon icon="solar:pen-bold"></iconify-icon></button>' +
                            '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--del" title="Hapus CP" onclick="MA.deleteCp(' + c.id + ')"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>' +
                        '</div>' +
                    '</div>' +
                    '<p class="ma-cur-card__text">' + nl2brEsc(c.cp) + '</p>' +
                '</div>';
            });
            html += '</div>';
            cont.innerHTML = html;
        },

        async openCpModal(editRow, forceKmpId) {
            document.getElementById('cpFormId').value = editRow ? editRow.id : '0';
            document.getElementById('cpModalTitle').textContent = editRow ? 'Edit Capaian Pembelajaran (CP)' : 'Tambah Capaian Pembelajaran (CP)';
            document.getElementById('cpFormNewElemen').value = '';
            document.getElementById('cpFormText').value = editRow ? (editRow.cp || '') : '';

            const kmpSel = document.getElementById('cpFormKmp');
            const filterKmp = document.getElementById('cpKmpFilter') ? document.getElementById('cpKmpFilter').value : '';
            if (editRow && editRow.id_kelas_mata_pelajaran) {
                kmpSel.value = String(editRow.id_kelas_mata_pelajaran);
            } else if (forceKmpId) {
                kmpSel.value = String(forceKmpId);
            } else if (filterKmp) {
                kmpSel.value = String(filterKmp);
            }

            await this.populateElemenForCpForm(editRow ? editRow.id_elemen : 0);
            openIlmunaModal('modalCpForm');
        },

        editCpById(id) {
            const row = this.state.cpList.find(function (c) { return Number(c.id) === Number(id); });
            if (row) this.openCpModal(row);
        },

        async populateElemenForCpForm(selectedElemenId) {
            const kmpId = document.getElementById('cpFormKmp').value;
            const elSel = document.getElementById('cpFormElemen');
            if (!elSel) return;
            try {
                const res = await apiGet('list_cp', { id_kmp: kmpId });
                const els = res.data.elemen_list || [];
                elSel.innerHTML = '<option value="0">-- Buat Elemen Baru di Samping --</option>' +
                    els.map(function (e) {
                        return '<option value="' + e.id + '">' + esc(e.nama_elemen) + ' (' + esc(e.fase || '') + ')</option>';
                    }).join('');
                if (selectedElemenId) elSel.value = String(selectedElemenId);
            } catch (e) {}
        },

        async submitCp(ev) {
            ev.preventDefault();
            try {
                const payload = {
                    id: parseInt(document.getElementById('cpFormId').value, 10) || 0,
                    id_kelas_mata_pelajaran: parseInt(document.getElementById('cpFormKmp').value, 10),
                    id_elemen: parseInt(document.getElementById('cpFormElemen').value, 10) || 0,
                    new_elemen_nama: document.getElementById('cpFormNewElemen').value.trim(),
                    cp: document.getElementById('cpFormText').value.trim()
                };
                const res = await apiPost('save_cp', payload);
                notify('success', res.message);
                closeIlmunaModal('modalCpForm');
                await this.loadBootstrap(false);

                if (this.state.quickReturnToWizard) {
                    this.state.quickReturnToWizard = false;
                    if (res.data && res.data.id) {
                        this.state.wizSelectedCpIds.push(Number(res.data.id));
                    }
                    await this.loadWizardCurriculumForPrimaryKmp();
                } else {
                    await this.loadCpTab();
                }
            } catch (err) {
                notify('error', err.message);
            }
        },

        async deleteCp(id) {
            const ok = await confirmAction({
                title: 'Hapus Capaian Pembelajaran',
                message: 'Hapus CP ini dari database?',
                confirmText: 'Ya, Hapus',
                danger: true
            });
            if (!ok) return;
            try {
                const res = await apiPost('delete_cp', { id: id });
                notify('success', res.message);
                await this.loadBootstrap(false);
                await this.loadCpTab();
            } catch (err) {
                notify('error', err.message);
            }
        },

        openSyncCpModal() {
            // Pilih default sumber yang sudah punya CP
            let defaultSrc = '';
            this.state.subjects.forEach(function (s) {
                s.classes.forEach(function (c) {
                    if (!defaultSrc && c.cp_count > 0) defaultSrc = String(c.id_kmp);
                });
            });
            const srcEl = document.getElementById('syncSourceKmp');
            if (srcEl && defaultSrc) srcEl.value = defaultSrc;
            openIlmunaModal('modalSyncCp');
        },

        async submitSyncCp(ev) {
            ev.preventDefault();
            try {
                const res = await apiPost('sync_cp_mapel', {
                    source_kmp: parseInt(document.getElementById('syncSourceKmp').value, 10),
                    target_kmp: parseInt(document.getElementById('syncTargetKmp').value, 10),
                    include_tp: document.getElementById('syncIncludeTp').checked ? 1 : 0
                });
                notify('success', res.message);
                closeIlmunaModal('modalSyncCp');
                await this.loadBootstrap(false);
                await this.loadCpTab();
            } catch (err) {
                notify('error', err.message);
            }
        },

        /* ======================================================================
         * TAB 2: TUJUAN PEMBELAJARAN (TP), ATP & ITP
         * ====================================================================== */
        async createTpFromCp(idCp, idKmp) {
            const kmpF = document.getElementById('tpKmpFilter');
            if (kmpF) kmpF.value = String(idKmp);
            this.switchTab('tp');
            await this.loadTpTab();
            this.openTpModal(null, idCp);
        },

        async loadTpTab() {
            const cont = document.getElementById('maTpContainer');
            if (cont) cont.innerHTML = '<div class="ma-skeleton-card" style="height:160px;"></div>';
            try {
                const idKmp = document.getElementById('tpKmpFilter') ? document.getElementById('tpKmpFilter').value : '';
                const idCp = document.getElementById('tpCpFilter') ? document.getElementById('tpCpFilter').value : '';
                const q = document.getElementById('tpSearchInput') ? document.getElementById('tpSearchInput').value.trim() : '';

                const res = await apiGet('list_tp', { id_kmp: idKmp, id_cp: idCp, q: q });
                this.state.tpList = res.data.tp_list || [];
                this.state.tpCpOptions = res.data.cp_options || [];

                // Populate CP filter dropdown
                const cpF = document.getElementById('tpCpFilter');
                if (cpF) {
                    const prev = cpF.value;
                    cpF.innerHTML = '<option value="">Semua CP</option>' +
                        this.state.tpCpOptions.map(function (c) {
                            const shortTxt = (c.nama_elemen ? '[' + c.nama_elemen + '] ' : '') + (c.cp || '').slice(0, 65);
                            return '<option value="' + c.id + '">' + esc(c.nama_kelas + ' — ' + shortTxt) + '...</option>';
                        }).join('');
                    cpF.value = prev;
                }

                this.renderTpList();
            } catch (err) {
                notify('error', err.message);
            }
        },

        renderTpList() {
            const cont = document.getElementById('maTpContainer');
            if (!cont) return;
            const list = this.state.tpList;

            if (!list.length) {
                cont.innerHTML =
                    '<div class="ma-empty-state">' +
                        '<div class="ma-empty-icon"><iconify-icon icon="solar:target-bold"></iconify-icon></div>' +
                        '<h3>Belum Ada Tujuan Pembelajaran (TP)</h3>' +
                        '<p>Pilih Capaian Pembelajaran (CP) yang sudah tersedia lalu buat Tujuan Pembelajaran (TP) beserta ATP &amp; ITP sebelum menyusun Modul Ajar.</p>' +
                        '<button type="button" class="ilmuna-btn ilmuna-btn--teal" onclick="MA.openTpModal()">' +
                            '<iconify-icon icon="solar:add-circle-bold"></iconify-icon> Buat TP Baru dari CP' +
                        '</button>' +
                    '</div>';
                return;
            }

            let html = '<div class="ma-tp-list">';
            list.forEach(function (t) {
                const atpRows = (t.atp_list || []).map(function (a) {
                    return '<div class="ma-nested-item">' +
                        '<div><strong>#' + (a.urutan || 1) + '</strong> ' + esc(a.atp) + '</div>' +
                        '<div style="display:flex;gap:4px;flex-shrink:0;">' +
                            '<button type="button" class="ilmuna-btn-icon" style="width:26px;height:26px;" onclick="MA.editSubTp(\'atp\',' + t.id + ',' + a.id + ')"><iconify-icon icon="solar:pen-bold"></iconify-icon></button>' +
                            '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--del" style="width:26px;height:26px;" onclick="MA.deleteSubTp(\'atp\',' + a.id + ')"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>' +
                        '</div>' +
                    '</div>';
                }).join('') || '<div style="font-size:12px;color:#94A3B8;">Belum ada ATP.</div>';

                const itpRows = (t.itp_list || []).map(function (i) {
                    return '<div class="ma-nested-item">' +
                        '<div>' + (i.kode_itp ? '<strong>[' + esc(i.kode_itp) + ']</strong> ' : '') + esc(i.itp) +
                            (i.deskripsi_ketercapaian ? '<div style="font-size:11.5px;color:#64748B;margin-top:2px;">Kriteria: ' + esc(i.deskripsi_ketercapaian) + '</div>' : '') +
                        '</div>' +
                        '<div style="display:flex;gap:4px;flex-shrink:0;">' +
                            '<button type="button" class="ilmuna-btn-icon" style="width:26px;height:26px;" onclick="MA.editSubTp(\'itp\',' + t.id + ',' + i.id + ')"><iconify-icon icon="solar:pen-bold"></iconify-icon></button>' +
                            '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--del" style="width:26px;height:26px;" onclick="MA.deleteSubTp(\'itp\',' + i.id + ')"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>' +
                        '</div>' +
                    '</div>';
                }).join('') || '<div style="font-size:12px;color:#94A3B8;">Belum ada Indikator Ketercapaian (ITP).</div>';

                html += '<div class="ma-cur-card">' +
                    '<div class="ma-cur-card__head">' +
                        '<div class="ma-cur-card__badges">' +
                            '<span class="ma-pertemuan-badge" style="padding:4px 10px;">' + esc(t.kode_tp || ('TP-' + t.id)) + '</span>' +
                            '<span class="ma-tag ma-tag--navy">' + esc(t.nama_mata_pelajaran) + '</span>' +
                            '<span class="ma-tag ma-tag--teal">' + esc(t.nama_kelas) + '</span>' +
                            (t.nama_elemen ? '<span class="ma-tag ma-tag--gold">' + esc(t.nama_elemen) + '</span>' : '') +
                            '<span class="ma-tag">' + (t.alokasi_jam || 2) + ' JP</span>' +
                        '</div>' +
                        '<div class="ma-card-actions">' +
                            '<button type="button" class="ilmuna-btn ilmuna-btn--gold ilmuna-btn--sm" onclick="MA.createModulFromTp(' + t.id + ')">' +
                                '<iconify-icon icon="solar:book-bookmark-bold"></iconify-icon> Buat Modul Ajar' +
                            '</button>' +
                            '<button type="button" class="ilmuna-btn-icon" title="Edit TP" onclick="MA.editTpById(' + t.id + ')"><iconify-icon icon="solar:pen-bold"></iconify-icon></button>' +
                            '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--del" title="Hapus TP" onclick="MA.deleteTp(' + t.id + ')"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>' +
                        '</div>' +
                    '</div>' +
                    (t.materi_pokok ? '<div style="font-weight:800;font-size:14px;color:#0F1D2F;margin-bottom:4px;">Materi Pokok: ' + esc(t.materi_pokok) + '</div>' : '') +
                    '<p class="ma-cur-card__text">' + nl2brEsc(t.tp) + '</p>' +
                    '<div style="font-size:11.5px;color:#64748B;background:#F8FAFC;padding:7px 10px;border-radius:8px;border:1px solid #E2E8F0;margin-bottom:8px;"><strong>CP Induk:</strong> ' + esc((t.cp_text || '').slice(0, 180)) + ((t.cp_text || '').length > 180 ? '...' : '') + '</div>' +
                    '<div class="ma-form-grid">' +
                        '<div class="ma-nested-box">' +
                            '<div class="ma-nested-title"><span><iconify-icon icon="solar:routing-2-bold"></iconify-icon> Alur Tujuan Pembelajaran (ATP)</span><button type="button" class="ma-expand-btn" onclick="MA.openSubTpModal(\'atp\',' + t.id + ')">+ Tambah ATP</button></div>' +
                            atpRows +
                        '</div>' +
                        '<div class="ma-nested-box">' +
                            '<div class="ma-nested-title"><span><iconify-icon icon="solar:checklist-minimalistic-bold"></iconify-icon> Indikator Ketercapaian (ITP)</span><button type="button" class="ma-expand-btn" onclick="MA.openSubTpModal(\'itp\',' + t.id + ')">+ Tambah ITP</button></div>' +
                            itpRows +
                        '</div>' +
                    '</div>' +
                '</div>';
            });
            html += '</div>';
            cont.innerHTML = html;
        },

        createModulFromTp(idTp) {
            const tpRow = this.state.tpList.find(function (t) { return Number(t.id) === Number(idTp); });
            if (!tpRow) return;
            this.switchTab('modul');
            this.openCreateWizard(tpRow.id_kelas_mata_pelajaran, tpRow);
        },

        openTpModal(editRow, forceCpId) {
            const cpSel = document.getElementById('tpFormCp');
            if (!this.state.tpCpOptions.length) {
                notify('warning', 'Belum ada Capaian Pembelajaran (CP). Buat atau sinkronkan CP di Tab 1 terlebih dahulu.');
                return;
            }
            cpSel.innerHTML = this.state.tpCpOptions.map(function (c) {
                const label = (c.nama_mata_pelajaran ? c.nama_mata_pelajaran + ' (' + c.nama_kelas + ') • ' : '') +
                    (c.nama_elemen ? '[' + c.nama_elemen + '] ' : '') + (c.cp || '').slice(0, 80);
                return '<option value="' + c.id + '">' + esc(label) + '</option>';
            }).join('');

            document.getElementById('tpFormId').value = editRow ? editRow.id : '0';
            document.getElementById('tpModalTitle').textContent = editRow ? 'Edit Tujuan Pembelajaran (TP)' : 'Buat Tujuan Pembelajaran (TP)';
            document.getElementById('tpFormKode').value = editRow ? (editRow.kode_tp || '') : '';
            document.getElementById('tpFormJp').value = editRow ? (editRow.alokasi_jam || 4) : 4;
            document.getElementById('tpFormMateri').value = editRow ? (editRow.materi_pokok || '') : '';
            document.getElementById('tpFormText').value = editRow ? (editRow.tp || '') : '';
            document.getElementById('tpFormInitAtp').value = '';
            document.getElementById('tpFormInitItp').value = '';

            // Initial ATP/ITP hanya saat tambah baru
            document.getElementById('tpInitAtpWrap').style.display = editRow ? 'none' : 'flex';
            document.getElementById('tpInitItpWrap').style.display = editRow ? 'none' : 'flex';

            if (editRow && editRow.id_cp) cpSel.value = String(editRow.id_cp);
            else if (forceCpId) cpSel.value = String(forceCpId);

            openIlmunaModal('modalTpForm');
        },

        editTpById(id) {
            const row = this.state.tpList.find(function (t) { return Number(t.id) === Number(id); });
            if (row) this.openTpModal(row);
        },

        async submitTp(ev) {
            ev.preventDefault();
            try {
                const payload = {
                    id: parseInt(document.getElementById('tpFormId').value, 10) || 0,
                    id_cp: parseInt(document.getElementById('tpFormCp').value, 10),
                    kode_tp: document.getElementById('tpFormKode').value.trim(),
                    alokasi_jam: parseInt(document.getElementById('tpFormJp').value, 10) || 4,
                    materi_pokok: document.getElementById('tpFormMateri').value.trim(),
                    tp: document.getElementById('tpFormText').value.trim(),
                    initial_atp: document.getElementById('tpFormInitAtp').value.trim(),
                    initial_itp: document.getElementById('tpFormInitItp').value.trim()
                };
                const res = await apiPost('save_tp', payload);
                notify('success', res.message);
                closeIlmunaModal('modalTpForm');
                await this.loadBootstrap(false);

                if (this.state.quickReturnToWizard) {
                    this.state.quickReturnToWizard = false;
                    if (res.data && res.data.id) {
                        this.state.wizSelectedTpIds.push(Number(res.data.id));
                        if (res.data.id_cp && this.state.wizSelectedCpIds.indexOf(Number(res.data.id_cp)) === -1) {
                            this.state.wizSelectedCpIds.push(Number(res.data.id_cp));
                        }
                    }
                    await this.loadWizardCurriculumForPrimaryKmp();
                    this.syncWizardTpOutputs();
                } else {
                    await this.loadTpTab();
                }
            } catch (err) {
                notify('error', err.message);
            }
        },

        async deleteTp(id) {
            const ok = await confirmAction({
                title: 'Hapus Tujuan Pembelajaran (TP)',
                message: 'TP beserta seluruh ATP dan ITP di bawahnya akan dihapus permanen. Lanjutkan?',
                confirmText: 'Ya, Hapus',
                danger: true
            });
            if (!ok) return;
            try {
                const res = await apiPost('delete_tp', { id: id });
                notify('success', res.message);
                await this.loadBootstrap(false);
                await this.loadTpTab();
            } catch (err) {
                notify('error', err.message);
            }
        },

        openSubTpModal(mode, idTp, editObj) {
            document.getElementById('subTpMode').value = mode;
            document.getElementById('subTpParentId').value = String(idTp);
            document.getElementById('subTpId').value = editObj ? String(editObj.id) : '0';

            const isItp = mode === 'itp';
            document.getElementById('subTpModalTitle').textContent = (editObj ? 'Edit ' : 'Tambah ') + (isItp ? 'Indikator Ketercapaian (ITP)' : 'Alur Tujuan Pembelajaran (ATP)');
            document.getElementById('subTpTextLabel').innerHTML = (isItp ? 'Uraian Indikator (ITP)' : 'Uraian Alur (ATP)') + ' <span class="req">*</span>';
            document.getElementById('subTpKodeWrap').style.display = isItp ? 'flex' : 'none';
            document.getElementById('subTpDescWrap').style.display = isItp ? 'flex' : 'none';

            document.getElementById('subTpKode').value = editObj ? (editObj.kode_itp || '') : '';
            document.getElementById('subTpUrutan').value = editObj ? (editObj.urutan || 1) : 1;
            document.getElementById('subTpText').value = editObj ? (isItp ? editObj.itp : editObj.atp) : '';
            document.getElementById('subTpDesc').value = editObj ? (editObj.deskripsi_ketercapaian || '') : '';

            openIlmunaModal('modalSubTpForm');
        },

        editSubTp(mode, idTp, subId) {
            const tpRow = this.state.tpList.find(function (t) { return Number(t.id) === Number(idTp); });
            if (!tpRow) return;
            const arr = mode === 'itp' ? (tpRow.itp_list || []) : (tpRow.atp_list || []);
            const found = arr.find(function (x) { return Number(x.id) === Number(subId); });
            if (found) this.openSubTpModal(mode, idTp, found);
        },

        async submitSubTp(ev) {
            ev.preventDefault();
            const mode = document.getElementById('subTpMode').value;
            const isItp = mode === 'itp';
            try {
                const payload = isItp ? {
                    id: parseInt(document.getElementById('subTpId').value, 10) || 0,
                    id_tp: parseInt(document.getElementById('subTpParentId').value, 10),
                    kode_itp: document.getElementById('subTpKode').value.trim(),
                    urutan: parseInt(document.getElementById('subTpUrutan').value, 10) || 1,
                    itp: document.getElementById('subTpText').value.trim(),
                    deskripsi_ketercapaian: document.getElementById('subTpDesc').value.trim()
                } : {
                    id: parseInt(document.getElementById('subTpId').value, 10) || 0,
                    id_tp: parseInt(document.getElementById('subTpParentId').value, 10),
                    urutan: parseInt(document.getElementById('subTpUrutan').value, 10) || 1,
                    atp: document.getElementById('subTpText').value.trim()
                };
                const res = await apiPost(isItp ? 'save_itp' : 'save_atp', payload);
                notify('success', res.message);
                closeIlmunaModal('modalSubTpForm');
                await this.loadTpTab();
            } catch (err) {
                notify('error', err.message);
            }
        },

        async deleteSubTp(mode, subId) {
            const ok = await confirmAction({
                title: 'Hapus ' + mode.toUpperCase(),
                message: 'Yakin ingin menghapus item ' + mode.toUpperCase() + ' ini?',
                confirmText: 'Ya, Hapus',
                danger: true
            });
            if (!ok) return;
            try {
                const res = await apiPost(mode === 'itp' ? 'delete_itp' : 'delete_atp', { id: subId });
                notify('success', res.message);
                await this.loadTpTab();
            } catch (err) {
                notify('error', err.message);
            }
        },

        /* ======================================================================
         * TEXT POPUP MODAL (ALA TEXTPOPUPMODAL)
         * ====================================================================== */
        openTextPopup(title, targetInputId, readOnly) {
            const el = document.getElementById(targetInputId);
            if (!el) return;
            this.state.popupTargetId = targetInputId;
            this.state.popupReadOnly = !!readOnly;

            document.getElementById('textPopupTitle').textContent = title || 'Editor Teks';
            const area = document.getElementById('textPopupArea');
            area.value = el.value || '';
            area.readOnly = !!readOnly;
            document.getElementById('textPopupSaveBtn').style.display = readOnly ? 'none' : 'inline-flex';
            openIlmunaModal('modalTextPopup');
        },

        saveTextPopup() {
            if (this.state.popupTargetId && !this.state.popupReadOnly) {
                const target = document.getElementById(this.state.popupTargetId);
                if (target) {
                    target.value = document.getElementById('textPopupArea').value;
                }
            }
            closeIlmunaModal('modalTextPopup');
        }
    };

    window.MA = MA;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { MA.init(); });
    } else {
        MA.init();
    }
})();
