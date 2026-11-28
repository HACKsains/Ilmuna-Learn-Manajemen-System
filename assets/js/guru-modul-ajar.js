/**
 * ==============================================================================
 * ILMUNA LEARN — MODUL AJAR SPA ENGINE (PROTA/PROSEM/KALDIK/JADWAL + QR LAMPIRAN)
 * ==============================================================================
 * Pure Vanilla ES6+ | Zero Page Reload | window.Ilmuna Only | Solar Icons Only
 * - ADMIN : Multi-Choice CP Creation, Monitoring Modul Ajar, Unlock Final, PDF Download
 * - GURU  : Strict Mapel Diampu, Pilih CP (1 Dropdown) -> Pilih TP (1 Dropdown) ->
 *           ATP/ITP -> Pilih Pertemuan PROTA/PROSEM/Kaldik/Jadwal, Multi-Lampiran QR Code
 * ==============================================================================
 */
(function () {
    'use strict';

    const CFG = window.ILMUNA_MODUL_AJAR_CFG || {
        baseUrl: '',
        endpoint: '/dashboard/guru/modul-ajar',
        activeRole: 'guru',
        isAdmin: false,
        canManageCp: false,
        canManageTp: true,
        canManageModul: true,
        canUnlockFinal: false
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
        1: 'Langkah 1 dari 6 • Pilih CP, TP, ATP/ITP & Slot Pertemuan PROTA/PROSEM/Jadwal',
        2: 'Langkah 2 dari 6 • Model Pembelajaran (Auto-Sintaks), Metode, Media & Sumber',
        3: 'Langkah 3 dari 6 • Pemahaman Bermakna & Pertanyaan Pemantik',
        4: 'Langkah 4 dari 6 • Alokasi Waktu JP & Skenario Kegiatan Pembelajaran',
        5: 'Langkah 5 dari 6 • Asesmen, Profil Pelajar Pancasila (P5-PPRA) & Refleksi',
        6: 'Langkah 6 dari 6 • Glosarium, LKPD & Multi-Lampiran (QR Code PDF)'
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

    async function parseJsonSafe(res) {
        const txt = await res.text();
        const trimmed = (txt || '').trim();
        if (!trimmed || trimmed.charAt(0) === '<') {
            throw new Error('Sesi login berakhir atau respons server bukan JSON. Silakan muat ulang halaman.');
        }
        try {
            return JSON.parse(trimmed);
        } catch (e) {
            throw new Error('Respons server tidak valid (JSON parse error).');
        }
    }

    async function apiGet(action, params) {
        const qs = new URLSearchParams(Object.assign({ api: '1', action: action }, params || {}));
        const res = await fetch(CFG.endpoint + '?' + qs.toString(), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await parseJsonSafe(res);
        if (!res.ok || json.status !== 'success') {
            throw new Error(json.message || 'Permintaan gagal diproses.');
        }
        return json;
    }

    async function apiPost(action, payload, isFormData) {
        const fetchOpts = {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        };
        if (isFormData) {
            payload.append('action', action);
            fetchOpts.body = payload;
        } else {
            fetchOpts.headers['Content-Type'] = 'application/json';
            fetchOpts.body = JSON.stringify(Object.assign({ action: action }, payload || {}));
        }
        const res = await fetch(CFG.endpoint + '?api=1&action=' + encodeURIComponent(action), fetchOpts);
        const json = await parseJsonSafe(res);
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
        if (!b) return 'Tautan Digital';
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

    function computeAutoModulTitle(pertemuanKe, cpIds, cpCatalog, tpIds, tpCatalog, tpCustom) {
        const pert = Math.max(1, parseInt(pertemuanKe, 10) || 1);
        const cpLabels = [];
        (cpIds || []).forEach(function (cid) {
            const c = (cpCatalog || []).find(function (x) { return Number(x.id) === Number(cid); });
            if (!c) return;
            let lbl = (c.nama_elemen || '').trim();
            if (!lbl) {
                const raw = String(c.cp || '').replace(/\s+/g, ' ').trim();
                lbl = raw.length > 45 ? (raw.slice(0, 42) + '...') : raw;
            }
            if (lbl && cpLabels.indexOf(lbl) === -1) cpLabels.push(lbl);
        });

        const tpLabels = [];
        (tpIds || []).forEach(function (tid) {
            const t = (tpCatalog || []).find(function (x) { return Number(x.id) === Number(tid); });
            if (!t) return;
            const kode = (t.kode_tp || '').trim();
            const materi = (t.materi_pokok || '').trim();
            let lbl = '';
            if (kode && materi) lbl = 'TP ' + kode + ' (' + materi + ')';
            else if (materi) lbl = materi;
            else if (kode) lbl = 'TP ' + kode;
            else {
                const raw = String(t.tp || '').replace(/\s+/g, ' ').trim();
                lbl = raw.length > 55 ? (raw.slice(0, 52) + '...') : raw;
            }
            if (lbl && tpLabels.indexOf(lbl) === -1) tpLabels.push(lbl);
        });

        if (!tpLabels.length && tpCustom && String(tpCustom).trim()) {
            const line = String(tpCustom).trim().split(/\r?\n/)[0].replace(/\s+/g, ' ').trim();
            if (line) tpLabels.push(line.length > 60 ? (line.slice(0, 57) + '...') : line);
        }

        const parts = ['Modul Pertemuan ' + pert];
        if (cpLabels.length) parts.push(cpLabels.join(', '));
        if (tpLabels.length) parts.push(tpLabels.join(', '));
        let title = parts.join(' - ');
        if (title.length > 245) title = title.slice(0, 242) + '...';
        return title;
    }

    function buildSintaksText(modelIds, modelsCatalog) {
        const selected = modelsCatalog.filter(function (m) { return modelIds.indexOf(Number(m.id)) !== -1; });
        if (!selected.length) return null;

        const pembukaan = '1. Guru membuka pembelajaran dengan salam, doa bersama, dan memeriksa kehadiran peserta didik.\n'
            + '2. Apersepsi: mengaitkan pengalaman peserta didik dengan materi pembelajaran.\n'
            + '3. Menyampaikan tujuan pembelajaran, pertanyaan pemantik, dan skenario kegiatan.';

        const intiLines = [];
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

        const penutup = '1. Guru bersama peserta didik merangkum kesimpulan pokok materi.\n'
            + '2. Melakukan refleksi singkat serta asesmen formatif / umpan balik.\n'
            + '3. Menyampaikan agenda pertemuan berikutnya dan menutup dengan doa.';

        return { pembukaan: pembukaan, inti: intiLines.join('\n').trim(), penutup: penutup };
    }

    /* ==========================================================================
     * MAIN APPLICATION STATE & CONTROLLER
     * ========================================================================== */
    const MA = {
        state: {
            activeTab: 'modul',
            activeSubView: 'subjects',
            kmpList: [],
            subjects: [],
            models: [],
            metodes: [],
            elemenNames: [],
            materiList: [],
            tahunList: [],
            defaultTahun: '2026/2027',
            defaultSem: 'Ganjil',
            uniqueGrades: [],
            counts: { cp: 0, tp: 0, modul: 0 },

            activeKmpId: 0,
            activeMeetingsData: null,
            activeModulId: 0,
            activeDetail: null,
            detailEditMode: false,

            cpElemenList: [],
            cpList: [],
            tpList: [],
            tpCpOptions: [],

            // Wizard state (Single CP -> Single TP -> Slot Pertemuan PROTA/PROSEM/Kaldik/Jadwal)
            wizStep: 1,
            wizKmpId: 0,
            wizCpList: [],
            wizTpList: [],
            wizSlots: [],
            wizSelectedCpId: 0,
            wizSelectedTpId: 0,
            wizSelectedModelIds: [],
            wizSelectedMetodeIds: [],
            quickReturnToWizard: false,

            popupTargetId: null,
            popupReadOnly: false
        },

        async init() {
            this.state.activeTab = CFG.canManageCp ? 'cp' : 'modul';
            this.bindEvents();
            await this.loadBootstrap(true);

            // Tangani Deep-Link URL dari Jadwal Mengajar / Presensi / Tugas / Proyek
            const qs = new URLSearchParams(window.location.search);
            const openModulId = parseInt(qs.get('open_modul') || '0', 10);
            const createKmpId = parseInt(qs.get('create_kmp') || '0', 10);
            const createPert = parseInt(qs.get('pertemuan') || '0', 10);

            if (openModulId > 0) {
                this.switchTab('modul');
                await this.openDetail(openModulId);
                return;
            }
            if (createKmpId > 0 && CFG.canManageModul) {
                this.switchTab('modul');
                await this.openKmpMeetings(createKmpId);
                await this.openCreateWizard(createKmpId);
                if (createPert > 0) {
                    const pEl = document.getElementById('wizPertemuan');
                    if (pEl) pEl.value = String(createPert);
                }
                return;
            }

            if (this.state.activeTab === 'cp') {
                await this.loadCpTab();
            }
        },

        bindEvents() {
            const self = this;

            document.querySelectorAll('#maMainTabs .curriculum-tab-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    self.switchTab(btn.getAttribute('data-tab'));
                });
            });

            const sSearch = document.getElementById('subjSearchInput');
            if (sSearch) sSearch.addEventListener('input', debounce(function () { self.renderSubjectsGrid(); }, 200));
            const sGrade = document.getElementById('subjGradeFilter');
            if (sGrade) sGrade.addEventListener('change', function () { self.renderSubjectsGrid(); });
            const sTahun = document.getElementById('subjTahunFilter');
            if (sTahun) sTahun.addEventListener('change', function () { self.renderSubjectsGrid(); });

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

            const cpS = document.getElementById('cpSearchInput');
            if (cpS) cpS.addEventListener('input', debounce(function () { self.loadCpTab(); }, 250));
            const cpK = document.getElementById('cpKmpFilter');
            if (cpK) cpK.addEventListener('change', function () { self.loadCpTab(); });

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
                this.state.elemenNames = d.elemen_names || [];
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

            const f1 = document.getElementById('flowStep1');
            const f2 = document.getElementById('flowStep2');
            const f3 = document.getElementById('flowStep3');
            [f1, f2, f3].forEach(function (el) { if (el) el.classList.remove('is-done', 'is-current'); });
            if (f1 && c.cp > 0) f1.classList.add('is-done');
            if (f2 && c.tp > 0) f2.classList.add('is-done');
            if (this.state.activeTab === 'cp' && f1) { f1.classList.remove('is-done'); f1.classList.add('is-current'); }
            else if (this.state.activeTab === 'tp' && f2) { f2.classList.remove('is-done'); f2.classList.add('is-current'); }
            else if (f3) { f3.classList.add('is-current'); }
        },

        populateGlobalDropdowns() {
            const gf = document.getElementById('subjGradeFilter');
            if (gf) {
                const prev = gf.value;
                gf.innerHTML = '<option value="">Semua Tingkat Kelas</option>' +
                    this.state.uniqueGrades.map(function (g) { return '<option value="' + esc(g) + '">Kelas ' + esc(g) + '</option>'; }).join('');
                gf.value = prev;
            }

            const tahunOpts = '<option value="">Semua Tahun Pelajaran</option>' +
                this.state.tahunList.map(function (t) { return '<option value="' + esc(t) + '">' + esc(t) + '</option>'; }).join('');
            ['subjTahunFilter', 'meetTahunFilter'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) { const prev = el.value; el.innerHTML = tahunOpts; el.value = prev; }
            });

            const kmpOptsAll = '<option value="">Semua Kelas &amp; Mata Pelajaran</option>' +
                this.state.kmpList.map(function (k) {
                    return '<option value="' + k.id_kmp + '">' + esc(k.nama_mata_pelajaran) + ' — ' + esc(k.nama_kelas) + '</option>';
                }).join('');
            ['cpKmpFilter', 'tpKmpFilter'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) { const prev = el.value; el.innerHTML = kmpOptsAll; el.value = prev; }
            });

            // Admin CP Modal dropdowns
            const cpMapelF = document.getElementById('cpFormMapelFilter');
            if (cpMapelF) {
                cpMapelF.innerHTML = '<option value="">Semua Mata Pelajaran</option>' +
                    this.state.subjects.map(function (s) {
                        return '<option value="' + s.id_mata_pelajaran + '">' + esc(s.nama_mata_pelajaran) + ' (' + s.classes.length + ' Rombel)</option>';
                    }).join('');
            }
            const cpElNama = document.getElementById('cpFormElemenNama');
            if (cpElNama) {
                cpElNama.innerHTML = '<option value="">-- Atau ketik Elemen baru di samping --</option>' +
                    this.state.elemenNames.map(function (n) {
                        return '<option value="' + esc(n) + '">' + esc(n) + '</option>';
                    }).join('');
            }
            const syncSrc = document.getElementById('syncSourceKmp');
            if (syncSrc) {
                syncSrc.innerHTML = this.state.kmpList.map(function (k) {
                    return '<option value="' + k.id_kmp + '">' + esc(k.nama_mata_pelajaran) + ' — ' + esc(k.nama_kelas) + ' (' + esc(k.fase) + ')</option>';
                }).join('');
            }

            // Wizard Mapel, Tahun, Materi
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
            if (tab === 'tp' && !CFG.canManageTp) return;
            this.state.activeTab = tab;
            document.querySelectorAll('#maMainTabs .curriculum-tab-btn').forEach(function (btn) {
                const isAct = btn.getAttribute('data-tab') === tab;
                btn.classList.toggle('is-active', isAct);
                btn.setAttribute('aria-selected', isAct ? 'true' : 'false');
            });
            const pMod = document.getElementById('paneModul');
            const pCp = document.getElementById('paneCp');
            const pTp = document.getElementById('paneTp');
            if (pMod) pMod.classList.toggle('is-active', tab === 'modul');
            if (pCp) pCp.classList.toggle('is-active', tab === 'cp');
            if (pTp) pTp.classList.toggle('is-active', tab === 'tp');

            this.updateTabCounters();

            if (tab === 'cp') {
                this.loadCpTab();
            } else if (tab === 'tp') {
                this.loadTpTab();
            } else if (tab === 'modul') {
                if (this.state.activeSubView === 'subjects') this.loadBootstrap(false);
                else if (this.state.activeSubView === 'meetings' && this.state.activeKmpId) this.loadMeetings(this.state.activeKmpId);
            }
        },

        jumpToTabWithKmp(tab, idKmp) {
            if (tab === 'tp' && !CFG.canManageTp) return;
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
         * SUB-VIEW A: SUBJECT CARDS GRID
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
                const emptyText = CFG.activeRole === 'guru'
                    ? 'Belum ada Mata Pelajaran & Rombel yang dipetakan untuk akun Guru Anda. Hubungi Admin Kurikulum untuk penugasan mapel.'
                    : 'Belum ada data Mata Pelajaran & Rombel yang sesuai dengan filter.';
                container.innerHTML =
                    '<div class="ma-empty-state">' +
                        '<div class="ma-empty-icon"><iconify-icon icon="solar:book-bookmark-bold"></iconify-icon></div>' +
                        '<h3>Tidak Ada Mata Pelajaran Ditemukan</h3>' +
                        '<p>' + emptyText + '</p>' +
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
                            (CFG.canManageTp ? '<span class="ma-mini-pill ' + tpPillClass + '"><iconify-icon icon="solar:target-bold"></iconify-icon> ' + cls.tp_count + ' TP</span>' : '') +
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
         * SUB-VIEW B: PERTEMUAN LIST PER KMP
         * ====================================================================== */
        setSubView(view, skipScroll) {
            this.state.activeSubView = view;
            document.getElementById('maViewSubjects').style.display = (view === 'subjects') ? 'block' : 'none';
            document.getElementById('maViewMeetings').style.display = (view === 'meetings') ? 'block' : 'none';
            document.getElementById('maViewDetail').style.display = (view === 'detail') ? 'block' : 'none';
            if (!skipScroll) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
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

                const info = d.kmp_info;
                document.getElementById('meetHeadTitle').innerHTML =
                    esc(info.nama_mata_pelajaran) + ' <span class="ma-tag ma-tag--teal">' + esc(info.nama_kelas) + '</span> <span class="ma-tag ma-tag--navy">' + esc(info.fase) + '</span>';
                document.getElementById('meetHeadMeta').innerHTML =
                    '<span><iconify-icon icon="solar:user-speak-bold"></iconify-icon> ' + esc(info.nama_guru) + '</span>' +
                    '<span><iconify-icon icon="solar:diploma-verified-bold"></iconify-icon> ' + d.cp_count + ' CP</span>' +
                    (CFG.canManageTp ? '<span><iconify-icon icon="solar:target-bold"></iconify-icon> ' + d.tp_count + ' TP</span>' : '') +
                    '<span><iconify-icon icon="solar:book-bookmark-bold"></iconify-icon> ' + d.modul_list.length + ' Pertemuan</span>';

                const sibSel = document.getElementById('meetSiblingSwitch');
                if (sibSel) {
                    sibSel.innerHTML = (d.siblings || []).map(function (sb) {
                        return '<option value="' + sb.id_kmp + '"' + (sb.id_kmp === info.id_kmp ? ' selected' : '') + '>' + esc(sb.nama_kelas) + '</option>';
                    }).join('');
                }

                let banners = '';
                if (d.cp_count === 0) {
                    if (CFG.canManageCp) {
                        banners += '<div class="ma-alert-banner ma-alert-banner--amber">' +
                            '<div class="ma-alert-banner__body">' +
                                '<iconify-icon icon="solar:info-circle-bold"></iconify-icon>' +
                                '<div>' +
                                    '<p class="ma-alert-banner__title">Capaian Pembelajaran (CP) belum tersedia untuk ' + esc(info.nama_kelas) + '</p>' +
                                    '<p class="ma-alert-banner__desc">Tambahkan CP (bisa langsung centang banyak kelas sekaligus) agar Guru dapat menyusun TP dan Modul Ajar.</p>' +
                                '</div>' +
                            '</div>' +
                            '<button type="button" class="ilmuna-btn ilmuna-btn--gold ilmuna-btn--sm" onclick="MA.openCpModal(null,' + info.id_kmp + ')">' +
                                '<iconify-icon icon="solar:add-circle-bold"></iconify-icon> Tambah CP' +
                            '</button>' +
                        '</div>';
                    } else {
                        banners += '<div class="ma-alert-banner ma-alert-banner--amber">' +
                            '<div class="ma-alert-banner__body">' +
                                '<iconify-icon icon="solar:shield-check-bold"></iconify-icon>' +
                                '<div>' +
                                    '<p class="ma-alert-banner__title">Capaian Pembelajaran (CP) belum diinput oleh Admin Kurikulum</p>' +
                                    '<p class="ma-alert-banner__desc">Hubungi Admin Kurikulum agar CP untuk ' + esc(info.nama_mata_pelajaran) + ' segera ditambahkan.</p>' +
                                '</div>' +
                            '</div>' +
                        '</div>';
                    }
                } else if (d.tp_count === 0 && CFG.canManageTp) {
                    banners += '<div class="ma-alert-banner ma-alert-banner--teal">' +
                        '<div class="ma-alert-banner__body">' +
                            '<iconify-icon icon="solar:target-bold"></iconify-icon>' +
                            '<div>' +
                                '<p class="ma-alert-banner__title">' + d.cp_count + ' CP sudah tersedia! Sekarang susun Tujuan Pembelajaran (TP &amp; ATP)</p>' +
                                '<p class="ma-alert-banner__desc">Buat TP &amp; ATP dari CP terlebih dahulu sebelum menyusun Modul Ajar.</p>' +
                            '</div>' +
                        '</div>' +
                        '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.jumpToTabWithKmp(\'tp\',' + info.id_kmp + ')">' +
                            '<iconify-icon icon="solar:add-circle-bold"></iconify-icon> Buat TP &amp; ATP Sekarang' +
                        '</button>' +
                    '</div>';
                }
                document.getElementById('meetReadinessBanners').innerHTML = banners;

                this.renderMeetingsList(d.modul_list || []);
            } catch (err) {
                notify('error', err.message, 'Gagal Memuat Pertemuan');
            }
        },

        renderMeetingsList(list) {
            const container = document.getElementById('maMeetingsContainer');
            if (!container) return;

            if (!list.length) {
                const cta = CFG.canManageModul
                    ? '<button type="button" class="ilmuna-btn ilmuna-btn--teal" onclick="MA.openCreateWizard(MA.state.activeKmpId)"><iconify-icon icon="solar:add-circle-bold"></iconify-icon> Buat Modul Ajar Pertama</button>'
                    : '';
                const desc = CFG.canManageModul
                    ? 'Susun Modul Ajar Kurikulum Merdeka terintegrasi dengan CP, TP/ATP/ITP, PROTA, PROSEM, Kaldik, dan Jadwal Mengajar.'
                    : 'Guru pengampu belum membuat Modul Ajar untuk rombel ini.';
                container.innerHTML =
                    '<div class="ma-empty-state">' +
                        '<div class="ma-empty-icon"><iconify-icon icon="solar:document-add-bold"></iconify-icon></div>' +
                        '<h3>Belum Ada Modul Ajar Pertemuan</h3>' +
                        '<p>' + desc + '</p>' +
                        cta +
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

                const dlModulUrl = CFG.endpoint + '?action=download_modul_pdf&id=' + m.id;
                const dlLapUrl = CFG.endpoint + '?action=download_laporan_pdf&id=' + m.id;

                let rightActions = '';
                if (CFG.canUnlockFinal && isFinal) {
                    rightActions += '<button type="button" class="ilmuna-btn ilmuna-btn--gold ilmuna-btn--sm" onclick="MA.unlockFinalModul(' + m.id + ', false)" title="Buka Kunci Final & Kembalikan ke Guru">' +
                        '<iconify-icon icon="solar:lock-unlocked-bold"></iconify-icon> Buka Kunci Final' +
                    '</button>';
                }
                if (CFG.canManageModul) {
                    rightActions += '<button type="button" class="ilmuna-btn-icon" title="Duplikasi Pertemuan" onclick="MA.duplicateModul(' + m.id + ')"><iconify-icon icon="solar:copy-bold"></iconify-icon></button>';
                    if (!isFinal) {
                        rightActions += '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--del" title="Hapus Modul Ajar" onclick="MA.deleteModul(' + m.id + ')"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>';
                    }
                }

                html += '<div class="ma-meeting-card">' +
                    '<div class="ma-meeting-card__top">' +
                        '<span class="ma-pertemuan-badge"><iconify-icon icon="solar:calendar-mark-bold"></iconify-icon> Pertemuan ke-' + m.pertemuan_ke + '</span>' +
                        stBadge +
                    '</div>' +
                    '<h3 class="ma-meeting-title" onclick="MA.openModulDetail(' + m.id + ')">' + esc(m.judul_modul) + '</h3>' +
                    '<div class="ma-meeting-meta-row">' +
                        '<span class="ma-meeting-meta-item"><iconify-icon icon="solar:calendar-bold"></iconify-icon> ' + formatDateIndo(m.tanggal) + (m.jam_mulai ? ' (' + esc(m.jam_mulai) + '-' + esc(m.jam_selesai || '') + ')' : '') + '</span>' +
                        '<span class="ma-meeting-meta-item"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> ' + m.jumlah_jp + ' JP (' + m.total_menit + ' mnt)</span>' +
                        '<span class="ma-meeting-meta-item"><iconify-icon icon="solar:bookmark-bold"></iconify-icon> ' + esc(m.tahun_pelajaran) + ' &bull; ' + esc(m.semester) + '</span>' +
                    '</div>' +
                    '<div class="ma-meeting-tags">' +
                        (m.primary_model_name ? '<span class="ma-tag ma-tag--gold">' + esc(m.primary_model_name) + '</span>' : '') +
                        (m.lampiran && m.lampiran.length ? '<span class="ma-tag ma-tag--teal"><iconify-icon icon="solar:qr-code-bold"></iconify-icon> ' + m.lampiran.length + ' Lampiran QR</span>' : '') +
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
                                '<iconify-icon icon="solar:eye-bold"></iconify-icon> Detail' +
                            '</button>' +
                            '<a href="' + dlModulUrl + '" target="_blank" class="ilmuna-btn ilmuna-btn--sky ilmuna-btn--sm" title="Unduh PDF Modul Ajar">' +
                                '<iconify-icon icon="solar:download-minimalistic-bold"></iconify-icon> PDF Modul' +
                            '</a>' +
                            ((isPub || isFinal) ? '<a href="' + dlLapUrl + '" target="_blank" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" title="Unduh Laporan Keterlaksanaan"><iconify-icon icon="solar:document-text-bold"></iconify-icon> Laporan PDF</a>' : '') +
                        '</div>' +
                        '<div class="ma-card-actions">' + rightActions + '</div>' +
                    '</div>' +
                '</div>';
            });

            html += '</div>';
            container.innerHTML = html;
        },

        async unlockFinalModul(id, fromDetail) {
            const ok = await confirmAction({
                title: 'Buka Kunci Final Modul Ajar',
                message: 'Buka kunci Final (Terkunci) modul ini dan kembalikan ke Guru dengan status Draft agar dapat diperbaiki/diisi kembali?',
                confirmText: 'Ya, Buka Kunci & Kembalikan'
            });
            if (!ok) return;
            try {
                const res = await apiPost('unlock_final', { id: id });
                notify('success', res.message);
                await this.loadBootstrap(false);
                if (fromDetail) {
                    await this.openModulDetail(id, false, true);
                } else {
                    await this.loadMeetings(this.state.activeKmpId);
                }
            } catch (err) {
                notify('error', err.message);
            }
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
                if (fromDetail) this.backToMeetings();
                else await this.loadMeetings(this.state.activeKmpId);
            } catch (err) {
                notify('error', err.message);
            }
        },

        /* ======================================================================
         * SUB-VIEW C: DETAIL & EDITOR MODUL AJAR (ADMIN READ-ONLY + UNLOCK + PDF | GURU FULL)
         * ====================================================================== */
        async openModulDetail(id, startEdit, preserveScroll) {
            const keepScroll = !!preserveScroll && this.state.activeSubView === 'detail';
            const prevY = keepScroll ? window.scrollY : 0;
            this.state.activeModulId = Number(id);
            this.state.detailEditMode = !!(startEdit && CFG.canManageModul);
            this.setSubView('detail', keepScroll);
            const cont = document.getElementById('maDetailContainer');
            if (cont && !keepScroll) cont.innerHTML = '<div class="ma-skeleton-card" style="height:260px;"></div>';

            try {
                const res = await apiGet('get_modul_detail', { id: id });
                this.state.activeDetail = res.data;
                this.state.activeKmpId = Number(res.data.modul.id_kelas_mata_pelajaran);
                if (!CFG.canManageModul || res.data.modul.status !== 'Draft' || res.data.modul.meeting_status === 'final') {
                    this.state.detailEditMode = false;
                }
                this.renderDetailView();
                if (keepScroll) {
                    window.scrollTo({ top: prevY, behavior: 'auto' });
                }
            } catch (err) {
                notify('error', err.message, 'Gagal Membuka Detail');
                this.backToMeetings();
            }
        },

        buildLampiranHtml(m) {
            const isFinal = m.meeting_status === 'final';
            if (!m.lampiran || !m.lampiran.length) {
                return '<p style="font-size:12.5px;color:#64748B;margin:0;">Belum ada lampiran digital terunggah.</p>';
            }
            return '<div class="ma-qr-list">' + m.lampiran.map(function (f, idx) {
                const fullUrl = f.url || (CFG.baseUrl + '/' + String(f.file_path || '').replace(/^\/+/, ''));
                const qrSrc = CFG.endpoint + '?api=1&action=qr_svg&url=' + encodeURIComponent(fullUrl);
                return '<div class="ma-qr-item">' +
                    '<div class="ma-qr-thumb" title="Scan QR untuk membuka lampiran">' +
                        '<img src="' + esc(qrSrc) + '" alt="QR Lampiran #' + (idx + 1) + '">' +
                    '</div>' +
                    '<div class="ma-qr-meta">' +
                        '<div class="ma-qr-name" title="' + esc(f.file_name) + '">#' + (idx + 1) + ' ' + esc(f.file_name) + '</div>' +
                        '<div style="font-size:11px;color:#64748B;">' + formatBytes(f.file_size) + ' &bull; QR tercetak otomatis di PDF</div>' +
                        '<div class="ma-qr-url" title="' + esc(fullUrl) + '">' + esc(fullUrl) + '</div>' +
                        '<div class="ma-qr-actions">' +
                            '<a href="' + esc(fullUrl) + '" target="_blank" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm"><iconify-icon icon="solar:external-link-bold"></iconify-icon> Buka / Unduh</a>' +
                            ((CFG.canManageModul && !isFinal) ? '<button type="button" class="ilmuna-btn ilmuna-btn--danger ilmuna-btn--sm" onclick="MA.deleteLampiran(' + m.id + ',\'' + esc(f.id) + '\')"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>' : '') +
                        '</div>' +
                    '</div>' +
                '</div>';
            }).join('') + '</div>';
        },

        updateDetailLampiranInPlace(newLampiran) {
            if (!this.state.activeDetail || !this.state.activeDetail.modul) return;
            this.state.activeDetail.modul.lampiran = Array.isArray(newLampiran) ? newLampiran : [];
            const formEl = document.getElementById('detInlineLinkForm');
            if (formEl) formEl.remove();
            const listWrap = document.getElementById('detLampiranList');
            if (listWrap) {
                listWrap.innerHTML = this.buildLampiranHtml(this.state.activeDetail.modul);
            }
        },

        renderDetailView() {
            const d = this.state.activeDetail;
            if (!d || !d.modul) return;
            const m = d.modul;
            const isFinal = m.meeting_status === 'final';
            const isDraft = m.status === 'Draft' && !isFinal;
            const isPub = m.status === 'Published' && !isFinal;
            const ed = CFG.canManageModul && this.state.detailEditMode && isDraft;
            const dis = ed ? '' : ' disabled';

            let stBadge = '<span class="ma-status-pill ma-status-pill--draft"><iconify-icon icon="solar:pen-bold"></iconify-icon> Draft</span>';
            if (isFinal) stBadge = '<span class="ma-status-pill ma-status-pill--final"><iconify-icon icon="solar:lock-bold"></iconify-icon> Final (Terkunci)</span>';
            else if (isPub) stBadge = '<span class="ma-status-pill ma-status-pill--published"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Published</span>';

            document.getElementById('detTitle').innerHTML = esc(m.judul_modul) + ' ' + stBadge;
            document.getElementById('detSubMeta').innerHTML =
                '<span><iconify-icon icon="solar:book-2-bold"></iconify-icon> ' + esc(m.nama_mata_pelajaran) + '</span>' +
                '<span><iconify-icon icon="solar:buildings-2-bold"></iconify-icon> ' + esc(m.nama_kelas) + ' (' + esc(m.fase) + ')</span>' +
                '<span><iconify-icon icon="solar:user-speak-bold"></iconify-icon> ' + esc(m.nama_guru) + '</span>' +
                '<span><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> ' + m.jumlah_jp + ' JP &times; ' + m.alokasi_per_jp + ' mnt = ' + m.total_menit + ' Menit</span>';

            const dlModulUrl = CFG.endpoint + '?action=download_modul_pdf&id=' + m.id;
            const dlLapUrl = CFG.endpoint + '?action=download_laporan_pdf&id=' + m.id;
            let actHtml = '';

            if (CFG.canManageModul) {
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
            }

            // Admin: tombol Buka Kunci Final
            if (CFG.canUnlockFinal && isFinal) {
                actHtml += '<button type="button" class="ilmuna-btn ilmuna-btn--gold ilmuna-btn--sm" onclick="MA.unlockFinalModul(' + m.id + ', true)"><iconify-icon icon="solar:lock-unlocked-bold"></iconify-icon> Buka Kunci Final (Kembalikan ke Guru)</button>';
            }

            actHtml += '<a href="' + dlModulUrl + '" target="_blank" class="ilmuna-btn ilmuna-btn--sky ilmuna-btn--sm"><iconify-icon icon="solar:download-minimalistic-bold"></iconify-icon> Unduh PDF Modul</a>';
            if (isPub || isFinal) {
                actHtml += '<a href="' + dlLapUrl + '" target="_blank" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm"><iconify-icon icon="solar:document-text-bold"></iconify-icon> Unduh Laporan PDF</a>';
            }
            if (CFG.canManageModul && !isFinal) {
                actHtml += '<button type="button" class="ilmuna-btn ilmuna-btn--danger ilmuna-btn--sm" onclick="MA.deleteModul(' + m.id + ', true)"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon> Hapus</button>';
            }
            document.getElementById('detTopActions').innerHTML = actHtml;

            let bannerHtml = '';
            if (isFinal) {
                const unlockHint = CFG.canUnlockFinal
                    ? 'Sebagai Administrator, Anda dapat menekan tombol <strong>"Buka Kunci Final"</strong> di atas untuk membuka modul ini kembali bagi Guru.'
                    : 'Modul ajar terkunci setelah finalisasi. Hubungi Admin Kurikulum jika perlu membuka kunci Final untuk revisi.';
                bannerHtml = '<div class="ma-alert-banner ma-alert-banner--teal"><div class="ma-alert-banner__body"><iconify-icon icon="solar:lock-bold"></iconify-icon><div><p class="ma-alert-banner__title">Pertemuan Sudah Difinalisasi (Terkunci)</p><p class="ma-alert-banner__desc">' + unlockHint + '</p></div></div></div>';
            } else if (!CFG.canManageModul) {
                bannerHtml = '<div class="ma-alert-banner ma-alert-banner--teal"><div class="ma-alert-banner__body"><iconify-icon icon="solar:eye-bold"></iconify-icon><div><p class="ma-alert-banner__title">Mode Pemantauan Administrator</p><p class="ma-alert-banner__desc">Anda dapat meninjau kelengkapan modul ajar dan mengunduh dokumen PDF resmi beserta QR Code lampiran.</p></div></div></div>';
            } else if (isPub) {
                bannerHtml = '<div class="ma-alert-banner ma-alert-banner--teal"><div class="ma-alert-banner__body"><iconify-icon icon="solar:check-circle-bold"></iconify-icon><div><p class="ma-alert-banner__title">Modul Diterbitkan — Catat Status Keterlaksanaan di Bawah</p><p class="ma-alert-banner__desc">Untuk menyunting rencana modul, klik "Kembalikan ke Draft". Jika KBM telah terlaksana, simpan keterlaksanaan lalu klik "Finalisasi".</p></div></div></div>';
            }

            // Single Dropdown CP & Single Dropdown TP pada Detail!
            const primaryCpId = (m.cp_ids && m.cp_ids[0]) ? Number(m.cp_ids[0]) : 0;
            const primaryTpId = (m.tp_ids && m.tp_ids[0]) ? Number(m.tp_ids[0]) : 0;

            const cpOptsHtml = '<option value="0">-- Pilih CP --</option>' + (d.cp_list || []).map(function (c) {
                const lbl = (c.nama_elemen ? '[' + c.nama_elemen + '] ' : '') + String(c.cp || '').slice(0, 100);
                return '<option value="' + c.id + '"' + (Number(c.id) === primaryCpId ? ' selected' : '') + '>' + esc(lbl) + '</option>';
            }).join('');

            const filteredDetTps = primaryCpId
                ? (d.tp_list || []).filter(function (t) { return Number(t.id_cp) === primaryCpId; })
                : (d.tp_list || []);
            const tpOptsHtml = '<option value="0">-- Pilih TP --</option>' + filteredDetTps.map(function (t) {
                const lbl = (t.kode_tp ? '[' + t.kode_tp + '] ' : '') + (t.materi_pokok ? t.materi_pokok + ' — ' : '') + String(t.tp || '').slice(0, 90);
                return '<option value="' + t.id + '"' + (Number(t.id) === primaryTpId ? ' selected' : '') + '>' + esc(lbl) + '</option>';
            }).join('');

            const modelHtml = this.state.models.map(function (mod) {
                const chk = m.model_ids.indexOf(Number(mod.id)) !== -1;
                return '<label class="ma-check-card' + (chk ? ' is-checked' : '') + '">' +
                    '<input type="checkbox" class="det-model-chk" value="' + mod.id + '"' + (chk ? ' checked' : '') + dis + ' onchange="this.closest(\'.ma-check-card\').classList.toggle(\'is-checked\', this.checked)">' +
                    '<div class="ma-check-card__body"><span class="ma-check-card__title">' + esc(mod.nama_model) + '</span><span class="ma-check-card__desc">' + esc((mod.deskripsi || '').slice(0, 85)) + '</span></div>' +
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

            const execDisabled = !(CFG.canManageModul && isPub);
            const buildExecCard = function (key, title, curStatus, curNote, dur) {
                const mkBtn = function (val, lbl) {
                    const cls = curStatus === val ? (' is-' + val) : '';
                    return '<button type="button" class="ma-exec-opt' + cls + '" data-exec-key="' + key + '" data-exec-val="' + val + '"' +
                        (execDisabled ? ' disabled' : ' onclick="MA.selectExecStatus(\'' + key + '\',\'' + val + '\')"') + '>' + lbl + '</button>';
                };
                return '<div class="ma-exec-card">' +
                    '<div class="ma-exec-card__title"><span>' + title + '</span><span class="ma-tag">' + dur + ' mnt</span></div>' +
                    '<input type="hidden" id="execStatus_' + key + '" value="' + esc(curStatus) + '">' +
                    '<div class="ma-exec-radio-row">' + mkBtn('belum', 'Belum') + mkBtn('sebagian', 'Sebagian') + mkBtn('terlaksana', 'Terlaksana') + '</div>' +
                    '<textarea class="ma-textarea" id="execNote_' + key + '" style="min-height:74px;" placeholder="Catatan pelaksanaan / kendala..."' + (execDisabled ? ' disabled' : '') + '>' + esc(curNote || '') + '</textarea>' +
                '</div>';
            };

            // Lampiran Grid dengan LIVE QR CODE tiap berkas/link!
            const attHtml = this.buildLampiranHtml(m);

            const sumDur = Number(m.durasi_pembukaan) + Number(m.durasi_inti) + Number(m.durasi_penutup);
            const durExceeded = sumDur > Number(m.total_menit);

            const canUploadAtt = (CFG.canManageModul && !isFinal);
            const uploadToolsHtml = canUploadAtt
                ? '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                    '<label class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" style="cursor:pointer;margin:0;"><iconify-icon icon="solar:upload-bold"></iconify-icon> Unggah File (Bisa >1)<input type="file" multiple style="display:none;" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.webp,.zip" onchange="MA.uploadLampiran(' + m.id + ', this)"></label>' +
                    '<button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="MA.promptAddLampiranLink(' + m.id + ')"><iconify-icon icon="solar:link-bold"></iconify-icon> Tambah Link</button>' +
                  '</div>'
                : '';

            document.getElementById('maDetailContainer').innerHTML = bannerHtml +
                // SECTION 1: INFORMASI PERTEMUAN
                '<div class="ma-section-card">' +
                    '<div class="ma-section-card__head"><h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:calendar-mark-bold"></iconify-icon></span> 1. Informasi Dasar &amp; Jadwal Pertemuan</h3></div>' +
                    '<div class="ma-section-card__body">' +
                        '<div class="ma-form-grid ma-form-grid--3">' +
                            '<div class="ma-field ma-field--full"><div class="ma-label-row"><label class="ma-label">Nama Modul Ajar (Otomatis)</label><span class="ma-tag ma-tag--teal">Modul Pertemuan (ke) - CP - TP</span></div><input type="text" class="ma-input" id="detJudul" value="' + esc(m.judul_modul) + '" readonly></div>' +
                            '<div class="ma-field"><label class="ma-label">Pertemuan Ke-</label><input type="number" class="ma-input" id="detPertemuan" value="' + m.pertemuan_ke + '" min="1"' + dis + ' oninput="MA.updateDetailAutoTitle()"></div>' +
                            '<div class="ma-field"><label class="ma-label">Fase</label><input type="text" class="ma-input" id="detFase" value="' + esc(m.fase) + '"' + dis + '></div>' +
                            '<div class="ma-field"><label class="ma-label">Tahun Pelajaran</label><select class="ma-select" id="detTahun"' + dis + '>' + tahunOpts + '</select></div>' +
                            '<div class="ma-field"><label class="ma-label">Semester</label><select class="ma-select" id="detSemester"' + dis + '><option value="Ganjil"' + (m.semester === 'Ganjil' ? ' selected' : '') + '>Ganjil</option><option value="Genap"' + (m.semester === 'Genap' ? ' selected' : '') + '>Genap</option></select></div>' +
                            '<div class="ma-field"><label class="ma-label">Tanggal Pelaksanaan</label><input type="text" class="ma-input" id="detTanggal" data-ilm-calendar="date" value="' + esc(m.tanggal || '') + '"' + dis + '></div>' +
                            '<div class="ma-field"><label class="ma-label">Jam Mulai &ndash; Selesai</label><div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;"><input type="time" class="ma-input" id="detJamMulai" value="' + esc(m.jam_mulai || '') + '"' + dis + '><input type="time" class="ma-input" id="detJamSelesai" value="' + esc(m.jam_selesai || '') + '"' + dis + '></div></div>' +
                            '<div class="ma-field"><label class="ma-label">Target Peserta Didik</label><input type="text" class="ma-input" id="detTargetDidik" value="' + esc(m.target_peserta_didik || '') + '"' + dis + '></div>' +
                            '<div class="ma-field" style="grid-column:span 2;"><label class="ma-label">Tautan Bank Materi (Opsional)</label><select class="ma-select" id="detMateriId"' + dis + '>' + materiOpts + '</select></div>' +
                        '</div>' +
                    '</div>' +
                '</div>' +

                // SECTION 2: STATUS KETERLAKSANAAN
                '<div class="ma-section-card">' +
                    '<div class="ma-section-card__head">' +
                        '<h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:checklist-minimalistic-bold"></iconify-icon></span> 2. Status Keterlaksanaan Kegiatan KBM</h3>' +
                        (!execDisabled ? '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.saveKeterlaksanaan(' + m.id + ')"><iconify-icon icon="solar:diskette-bold"></iconify-icon> Simpan Keterlaksanaan</button>' : '<span class="ma-tag">' + (isFinal ? 'Terkunci (Final)' : (!CFG.canManageModul ? 'Pantauan Admin' : 'Terbitkan (Publish) modul untuk mengisi')) + '</span>') +
                    '</div>' +
                    '<div class="ma-section-card__body"><div class="ma-exec-grid">' +
                        buildExecCard('pembukaan', 'Pembukaan / Pendahuluan', m.status_pembukaan, m.catatan_pembukaan, m.durasi_pembukaan) +
                        buildExecCard('inti', 'Kegiatan Inti', m.status_inti, m.catatan_inti, m.durasi_inti) +
                        buildExecCard('penutup', 'Penutup', m.status_penutup, m.catatan_penutup, m.durasi_penutup) +
                    '</div></div>' +
                '</div>' +

                // SECTION 3: CP -> TP -> ATP -> ITP (SINGLE DROPDOWN)
                '<div class="ma-section-card">' +
                    '<div class="ma-section-card__head"><h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:target-bold"></iconify-icon></span> 3. Capaian &amp; Tujuan Pembelajaran (CP &rarr; TP &rarr; ATP &rarr; ITP)</h3></div>' +
                    '<div class="ma-section-card__body">' +
                        '<div class="ma-form-grid">' +
                            '<div class="ma-field ma-field--full"><label class="ma-label">Capaian Pembelajaran (CP)</label><select class="ma-select" id="detCpSelect"' + dis + ' onchange="MA.onDetailCpSelectChange()">' + cpOptsHtml + '</select></div>' +
                            '<div class="ma-field ma-field--full"><label class="ma-label">Tujuan Pembelajaran (TP)</label><select class="ma-select" id="detTpSelect"' + dis + ' onchange="MA.onDetailTpSelectChange()">' + tpOptsHtml + '</select></div>' +
                            '<div class="ma-field"><div class="ma-label-row"><label class="ma-label">Alur Tujuan Pembelajaran (ATP)</label><button type="button" class="ma-expand-btn" onclick="MA.openTextPopup(\'Alur Tujuan Pembelajaran (ATP)\',\'detAtpCustom\',' + (!ed) + ')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button></div><textarea class="ma-textarea" id="detAtpCustom"' + dis + '>' + esc(m.atp_custom || '') + '</textarea></div>' +
                            '<div class="ma-field"><div class="ma-label-row"><label class="ma-label">Indikator Ketercapaian (ITP)</label><button type="button" class="ma-expand-btn" onclick="MA.openTextPopup(\'Indikator Ketercapaian (ITP)\',\'detItpCustom\',' + (!ed) + ')"><iconify-icon icon="solar:maximize-square-bold"></iconify-icon> Perbesar</button></div><textarea class="ma-textarea" id="detItpCustom"' + dis + '>' + esc(m.itp_custom || '') + '</textarea></div>' +
                            '<textarea id="detTpCustom" style="display:none;">' + esc(m.tp_custom || '') + '</textarea>' +
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

                // SECTION 5: PEMANTIK & KEGIATAN PEMBELAJARAN
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

                // SECTION 7: LKPD, GLOSARIUM & MULTI-LAMPIRAN + QR CODE
                '<div class="ma-section-card">' +
                    '<div class="ma-section-card__head">' +
                        '<h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:qr-code-bold"></iconify-icon></span> 7. LKPD, Glosarium &amp; Lampiran Digital (QR Code)</h3>' +
                        uploadToolsHtml +
                    '</div>' +
                    '<div class="ma-section-card__body">' +
                        '<div class="ma-form-grid" style="margin-bottom:14px;">' +
                            '<div class="ma-field"><label class="ma-label">Panduan LKPD</label><textarea class="ma-textarea" id="detLkpd"' + dis + '>' + esc(m.lkpd || '') + '</textarea></div>' +
                            '<div class="ma-field"><label class="ma-label">Bahan Bacaan</label><textarea class="ma-textarea" id="detBahan"' + dis + '>' + esc(m.bahan_bacaan || '') + '</textarea></div>' +
                            '<div class="ma-field ma-field--full"><label class="ma-label">Glosarium</label><textarea class="ma-textarea" id="detGlosarium"' + dis + '>' + esc(m.glosarium || '') + '</textarea></div>' +
                        '</div>' +
                        '<label class="ma-label" style="margin-bottom:8px;">Lampiran Digital (Setiap Lampiran Memiliki QR Code yang Tercetak di PDF)</label>' +
                        '<div id="detLampiranList">' + attHtml + '</div>' +
                    '</div>' +
                '</div>' +

                // SECTION 8: EKOSISTEM KBM TERINTEGRASI (PRESENSI, TUGAS & PROYEK)
                (function () {
                    const tugasList = d.tugas_list || [];
                    const proyekList = d.proyek_list || [];
                    const qBase = '?id_kelas=' + encodeURIComponent(m.id_kelas || 0) + '&id_kmp=' + encodeURIComponent(m.id_kelas_mata_pelajaran || 0);
                    const urlAbsensi = CFG.baseUrl + '/dashboard/guru/absensi' + qBase + '&pertemuan=' + encodeURIComponent(m.pertemuan_ke || 1);
                    const urlTugas = CFG.baseUrl + '/dashboard/guru/tugas' + qBase + '&id_modul_ajar=' + encodeURIComponent(m.id);
                    const urlProyek = CFG.baseUrl + '/dashboard/guru/proyek' + qBase + '&id_modul_ajar=' + encodeURIComponent(m.id);

                    const tugasHtml = tugasList.length
                        ? tugasList.map(function (t) {
                            return '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;margin-bottom:6px;font-size:12.5px;">' +
                                '<div><strong>' + esc(t.judul_tugas) + '</strong> <span class="ma-tag" style="margin-left:4px;">' + esc(t.jenis_tugas || 'Tugas') + '</span></div>' +
                                '<span style="font-size:11.5px;color:#64748B;">' + esc(t.status_tugas || '-') + '</span>' +
                            '</div>';
                        }).join('')
                        : '<div style="font-size:12px;color:#64748B;padding:6px 0;">Belum ada tugas yang ditautkan ke modul ajar ini.</div>';

                    const proyekHtml = proyekList.length
                        ? proyekList.map(function (p) {
                            return '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;background:#F0FDFA;border:1px solid #CCFBF1;border-radius:10px;margin-bottom:6px;font-size:12.5px;">' +
                                '<div><strong>' + esc(p.nama_proyek) + '</strong> <span class="ma-tag" style="margin-left:4px;">' + esc(p.tema_proyek || 'P5') + '</span></div>' +
                                '<span style="font-size:11.5px;color:#0F766E;font-weight:700;">' + esc(p.status_proyek || '-') + '</span>' +
                            '</div>';
                        }).join('')
                        : '<div style="font-size:12px;color:#64748B;padding:6px 0;">Belum ada proyek/P5 yang ditautkan ke modul ajar ini.</div>';

                    return '<div class="ma-section-card">' +
                        '<div class="ma-section-card__head">' +
                            '<h3 class="ma-section-card__title"><span class="ma-section-icon"><iconify-icon icon="solar:link-circle-bold"></iconify-icon></span> 8. Integrasi KBM (Presensi, Tugas &amp; Proyek P5)</h3>' +
                            '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                                '<a class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" href="' + esc(urlAbsensi) + '"><iconify-icon icon="solar:user-check-bold"></iconify-icon> Presensi Pertemuan ' + esc(m.pertemuan_ke) + '</a>' +
                                '<a class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" href="' + esc(urlTugas) + '"><iconify-icon icon="solar:clipboard-text-bold"></iconify-icon> Kelola Tugas</a>' +
                                '<a class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" href="' + esc(urlProyek) + '"><iconify-icon icon="solar:rocket-2-bold"></iconify-icon> Kelola Proyek &amp; P5</a>' +
                            '</div>' +
                        '</div>' +
                        '<div class="ma-section-card__body">' +
                            '<div class="ma-form-grid">' +
                                '<div class="ma-field"><label class="ma-label">Tugas Tertaut (' + tugasList.length + ')</label>' + tugasHtml + '</div>' +
                                '<div class="ma-field"><label class="ma-label">Proyek &amp; P5 Tertaut (' + proyekList.length + ')</label>' + proyekHtml + '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                })();

            if (window.IlmunaCalendar && typeof window.IlmunaCalendar.bind === 'function' && ed) {
                window.IlmunaCalendar.bind('#detTanggal', false, 'date');
                window.IlmunaCalendar.bind('#detJamMulai, #detJamSelesai', true, 'time');
            }
        },

        toggleDetailEdit(enable) {
            if (!CFG.canManageModul) return;
            this.state.detailEditMode = !!enable;
            this.renderDetailView();
        },

        onDetailCpSelectChange() {
            const d = this.state.activeDetail;
            if (!d) return;
            const cpId = Number(document.getElementById('detCpSelect').value) || 0;
            const tpSel = document.getElementById('detTpSelect');
            const filtered = cpId ? (d.tp_list || []).filter(function (t) { return Number(t.id_cp) === cpId; }) : (d.tp_list || []);
            tpSel.innerHTML = '<option value="0">-- Pilih TP --</option>' + filtered.map(function (t) {
                const lbl = (t.kode_tp ? '[' + t.kode_tp + '] ' : '') + (t.materi_pokok ? t.materi_pokok + ' — ' : '') + String(t.tp || '').slice(0, 90);
                return '<option value="' + t.id + '">' + esc(lbl) + '</option>';
            }).join('');
            this.updateDetailAutoTitle();
        },

        onDetailTpSelectChange() {
            const d = this.state.activeDetail;
            if (!d) return;
            const tpId = Number(document.getElementById('detTpSelect').value) || 0;
            const found = (d.tp_list || []).find(function (t) { return Number(t.id) === tpId; });
            if (found) {
                document.getElementById('detTpCustom').value = (found.kode_tp ? '[' + found.kode_tp + '] ' : '') + found.tp;
                const atps = (found.atp_list || []).map(function (a) { return '• ' + a.atp; });
                const itps = (found.itp_list || []).map(function (i) { return (i.kode_itp ? '[' + i.kode_itp + '] ' : '• ') + i.itp; });
                if (atps.length) document.getElementById('detAtpCustom').value = atps.join('\n');
                if (itps.length) document.getElementById('detItpCustom').value = itps.join('\n');
            }
            this.updateDetailAutoTitle();
        },

        updateDetailAutoTitle() {
            const d = this.state.activeDetail;
            if (!d) return;
            const pert = document.getElementById('detPertemuan') ? document.getElementById('detPertemuan').value : (d.modul.pertemuan_ke || 1);
            const cpId = document.getElementById('detCpSelect') ? Number(document.getElementById('detCpSelect').value) : 0;
            const tpId = document.getElementById('detTpSelect') ? Number(document.getElementById('detTpSelect').value) : 0;
            const tpCustom = document.getElementById('detTpCustom') ? document.getElementById('detTpCustom').value : '';
            const jEl = document.getElementById('detJudul');
            if (jEl) {
                jEl.value = computeAutoModulTitle(pert, cpId ? [cpId] : [], d.cp_list || [], tpId ? [tpId] : [], d.tp_list || [], tpCustom);
            }
        },

        applySintaksToDetail() {
            const ids = Array.from(document.querySelectorAll('.det-model-chk:checked')).map(function (c) { return Number(c.value); });
            if (!ids.length) { notify('warning', 'Centang minimal 1 Model Pembelajaran terlebih dahulu.'); return; }
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
                eB.value = b; eI.value = i; eT.value = Math.max(5, total - b - i);
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
            const cpId = Number(document.getElementById('detCpSelect').value) || 0;
            const tpId = Number(document.getElementById('detTpSelect').value) || 0;
            try {
                const payload = {
                    id: m.id,
                    pertemuan_ke: parseInt(document.getElementById('detPertemuan').value, 10) || 1,
                    fase: document.getElementById('detFase').value.trim(),
                    tahun_pelajaran: document.getElementById('detTahun').value,
                    semester: document.getElementById('detSemester').value,
                    tanggal: document.getElementById('detTanggal').value.trim(),
                    jam_mulai: document.getElementById('detJamMulai').value,
                    jam_selesai: document.getElementById('detJamSelesai').value,
                    target_peserta_didik: document.getElementById('detTargetDidik').value.trim(),
                    id_materi: document.getElementById('detMateriId').value || 0,
                    cp_ids: cpId ? [cpId] : [],
                    tp_ids: tpId ? [tpId] : [],
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
                await this.openModulDetail(m.id, false, true);
            } catch (err) {
                notify('error', err.message);
            }
        },

        selectExecStatus(key, val) {
            const inp = document.getElementById('execStatus_' + key);
            if (inp) inp.value = val;
            document.querySelectorAll('[data-exec-key="' + key + '"]').forEach(function (btn) {
                btn.classList.remove('is-belum', 'is-sebagian', 'is-terlaksana');
                if (btn.getAttribute('data-exec-val') === val) btn.classList.add('is-' + val);
            });
        },

        async saveKeterlaksanaan(id) {
            try {
                const res = await apiPost('save_keterlaksanaan', {
                    id: id,
                    status_pembukaan: document.getElementById('execStatus_pembukaan').value,
                    status_inti: document.getElementById('execStatus_inti').value,
                    status_penutup: document.getElementById('execStatus_penutup').value,
                    catatan_pembukaan: document.getElementById('execNote_pembukaan').value.trim(),
                    catatan_inti: document.getElementById('execNote_inti').value.trim(),
                    catatan_penutup: document.getElementById('execNote_penutup').value.trim()
                });
                notify('success', res.message);
                await this.openModulDetail(id, false, true);
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
                    ? 'Setelah diterbitkan (Published), Anda dapat mengisi status keterlaksanaan kegiatan KBM.'
                    : 'Kembalikan modul ajar ke Draft agar dapat disunting kembali?',
                confirmText: willPub ? 'Ya, Terbitkan' : 'Ya, Kembalikan ke Draft'
            });
            if (!ok) return;
            try {
                const res = await apiPost('toggle_publish', { id: id });
                notify('success', res.message);
                await this.loadBootstrap(false);
                await this.openModulDetail(id, false, true);
            } catch (err) {
                notify('error', err.message);
            }
        },

        async finalizeModul(id) {
            const ok = await confirmAction({
                title: 'Finalisasi Pertemuan Modul Ajar',
                message: 'Setelah difinalisasi, modul ajar dan keterlaksanaan akan terkunci. Hanya Admin Kurikulum yang dapat membuka kunci Final kembali. Yakin lanjutkan?',
                confirmText: 'Ya, Finalisasi',
                danger: true
            });
            if (!ok) return;
            try {
                const res = await apiPost('finalize_modul', { id: id });
                notify('success', res.message);
                await this.loadBootstrap(false);
                await this.openModulDetail(id, false, true);
            } catch (err) {
                notify('error', err.message);
            }
        },

        async uploadLampiran(id, fileInput) {
            if (!fileInput.files || !fileInput.files.length) return;
            const fd = new FormData();
            fd.append('id', String(id));
            for (let i = 0; i < fileInput.files.length; i++) {
                fd.append('file_lampiran[]', fileInput.files[i]);
            }
            try {
                const res = await apiPost('upload_lampiran', fd, true);
                notify('success', res.message);
                fileInput.value = '';
                this.updateDetailLampiranInPlace(res.data && res.data.lampiran ? res.data.lampiran : []);
            } catch (err) {
                notify('error', err.message);
                fileInput.value = '';
            }
        },

        promptAddLampiranLink(id) {
            // Gunakan input inline bersih pada modal text popup khusus link atau form ringan
            const listWrap = document.getElementById('detLampiranList');
            if (!listWrap) return;
            let formEl = document.getElementById('detInlineLinkForm');
            if (formEl) { formEl.remove(); return; }

            const div = document.createElement('div');
            div.id = 'detInlineLinkForm';
            div.style.cssText = 'background:#F0FDFA;border:1px solid #99F6E4;border-radius:12px;padding:12px;margin-bottom:12px;';
            div.innerHTML =
                '<div class="ma-form-grid" style="margin-bottom:8px;">' +
                    '<div class="ma-field"><label class="ma-label">Nama Lampiran</label><input type="text" class="ma-input" id="detLinkName" placeholder="Contoh: Slide Materi Google Drive"></div>' +
                    '<div class="ma-field"><label class="ma-label">URL Tautan (https://...)</label><input type="url" class="ma-input" id="detLinkUrl" placeholder="https://drive.google.com/..."></div>' +
                '</div>' +
                '<div style="display:flex;gap:8px;justify-content:flex-end;">' +
                    '<button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="document.getElementById(\'detInlineLinkForm\').remove()">Batal</button>' +
                    '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.submitAddLampiranLink(' + id + ')"><iconify-icon icon="solar:qr-code-bold"></iconify-icon> Simpan &amp; Buat QR</button>' +
                '</div>';
            listWrap.parentNode.insertBefore(div, listWrap);
        },

        async submitAddLampiranLink(id) {
            const name = document.getElementById('detLinkName').value.trim();
            const url = document.getElementById('detLinkUrl').value.trim();
            if (!url) { notify('warning', 'URL tautan lampiran wajib diisi.'); return; }
            try {
                const res = await apiPost('add_lampiran_link', { id: id, link_name: name, link_url: url });
                notify('success', res.message);
                this.updateDetailLampiranInPlace(res.data && res.data.lampiran ? res.data.lampiran : []);
            } catch (err) {
                notify('error', err.message);
            }
        },

        async deleteLampiran(id, attId) {
            const ok = await confirmAction({
                title: 'Hapus Lampiran',
                message: 'Hapus berkas/tautan lampiran ini dari modul ajar?',
                confirmText: 'Ya, Hapus',
                danger: true
            });
            if (!ok) return;
            try {
                const res = await apiPost('delete_lampiran', { id: id, attachment_id: attId });
                notify('success', res.message);
                this.updateDetailLampiranInPlace(res.data && res.data.lampiran ? res.data.lampiran : []);
            } catch (err) {
                notify('error', err.message);
            }
        },

        /* ======================================================================
         * 6-STEP WIZARD CREATE MODUL AJAR (KHUSUS GURU — CP -> TP -> ATP/ITP -> PERTEMUAN)
         * ====================================================================== */
        async openCreateWizard(preselectKmpId, prefillTp) {
            if (!CFG.canManageModul) {
                notify('info', 'Administrator hanya memantau, membuka kunci Final, dan mengunduh PDF.');
                return;
            }
            if (!this.state.subjects.length) {
                notify('warning', 'Belum ada Mata Pelajaran & Rombel yang Anda ampu.');
                return;
            }
            document.getElementById('formCreateModul').reset();
            this.state.wizCpList = [];
            this.state.wizTpList = [];
            this.state.wizSlots = [];
            this.state.wizSelectedCpId = 0;
            this.state.wizSelectedTpId = 0;
            this.state.wizSelectedModelIds = [];
            this.state.wizSelectedMetodeIds = [];

            document.getElementById('wizJadwalId').value = '0';
            document.getElementById('wizProsemDetailId').value = '0';
            document.getElementById('wizTahun').value = this.state.defaultTahun;
            document.getElementById('wizSemester').value = this.state.defaultSem;
            document.getElementById('wizAlokasiPerJp').value = 45;
            document.getElementById('wizJumlahJp').value = 2;
            document.getElementById('wizTargetDidik').value = 'Peserta Didik Reguler / Tipikal';
            document.getElementById('wizSelectedFilesInfo').textContent = '';
            document.getElementById('wizLinksList').innerHTML = '';
            document.getElementById('wizQuotaWrap').style.display = 'none';
            this.recalcWizDuration(true);

            this.renderWizardChips();
            this.renderWizardModelsAndMetodes();

            let targetMapelId = this.state.subjects[0].id_mata_pelajaran;
            if (preselectKmpId) {
                const foundKmp = this.state.kmpList.find(function (k) { return Number(k.id_kmp) === Number(preselectKmpId); });
                if (foundKmp) targetMapelId = foundKmp.id_mata_pelajaran;
            }
            document.getElementById('wizMapel').value = String(targetMapelId);
            await this.onWizMapelChange(preselectKmpId || 0, prefillTp || null);

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
            const items = inp.value.split(/[;,]\s*/).map(function (s) { return s.trim(); }).filter(Boolean);
            const idx = items.indexOf(textVal);
            if (idx === -1) { items.push(textVal); btn.classList.add('is-active'); }
            else { items.splice(idx, 1); btn.classList.remove('is-active'); }
            inp.value = items.join(sep);
        },

        renderWizardModelsAndMetodes() {
            const mGrid = document.getElementById('wizModelGrid');
            if (mGrid) {
                mGrid.innerHTML = this.state.models.map(function (mod) {
                    return '<label class="ma-check-card">' +
                        '<input type="checkbox" class="wiz-model-chk" value="' + mod.id + '" onchange="MA.onWizModelChange()">' +
                        '<div class="ma-check-card__body"><span class="ma-check-card__title">' + esc(mod.nama_model) + '</span><span class="ma-check-card__desc">' + esc((mod.deskripsi || '').slice(0, 85)) + '</span></div>' +
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

        async onWizMapelChange(forceKmpId, prefillTp) {
            const mapelId = Number(document.getElementById('wizMapel').value);
            const subj = this.state.subjects.find(function (s) { return Number(s.id_mata_pelajaran) === mapelId; });
            const classes = subj ? subj.classes : [];
            const kmpSel = document.getElementById('wizKmp');
            kmpSel.innerHTML = classes.map(function (c) {
                return '<option value="' + c.id_kmp + '">' + esc(c.nama_kelas) + ' (' + esc(c.fase) + ')</option>';
            }).join('');
            if (forceKmpId && classes.some(function (c) { return Number(c.id_kmp) === Number(forceKmpId); })) {
                kmpSel.value = String(forceKmpId);
            }
            await this.onWizKmpChange(prefillTp || null);
        },

        async onWizKmpChange(prefillTp) {
            const kmpId = Number(document.getElementById('wizKmp').value) || 0;
            this.state.wizKmpId = kmpId;
            if (!kmpId) return;

            try {
                const res = await apiGet('get_kmp_curriculum', { id_kmp: kmpId });
                this.state.wizCpList = res.data.cp_list || [];
                this.state.wizTpList = res.data.tp_list || [];
                document.getElementById('wizFase').value = res.data.fase || 'Fase E';

                // Populate 1 Dropdown CP
                const cpSel = document.getElementById('wizCpSelect');
                if (!this.state.wizCpList.length) {
                    cpSel.innerHTML = '<option value="">-- Belum ada CP dari Admin Kurikulum untuk mapel ini --</option>';
                } else {
                    cpSel.innerHTML = '<option value="">-- Pilih 1 Capaian Pembelajaran (CP) --</option>' +
                        this.state.wizCpList.map(function (c) {
                            const lbl = (c.nama_elemen ? '[' + c.nama_elemen + '] ' : '') + String(c.cp || '').slice(0, 100);
                            return '<option value="' + c.id + '">' + esc(lbl) + '</option>';
                        }).join('');
                }

                // Auto-select prefillTp or first CP
                if (prefillTp && prefillTp.id_cp) {
                    cpSel.value = String(prefillTp.id_cp);
                    await this.onWizCpSelectChange(prefillTp.id || 0);
                } else if (this.state.wizCpList.length) {
                    cpSel.value = String(this.state.wizCpList[0].id);
                    await this.onWizCpSelectChange(0);
                } else {
                    await this.onWizCpSelectChange(0);
                }
            } catch (err) {
                notify('error', err.message);
            }
        },

        async onWizCpSelectChange(forceTpId) {
            const cpId = Number(document.getElementById('wizCpSelect').value) || 0;
            this.state.wizSelectedCpId = cpId;

            const prevBox = document.getElementById('wizCpPreviewBox');
            const cpObj = this.state.wizCpList.find(function (c) { return Number(c.id) === cpId; });
            if (cpObj && prevBox) {
                prevBox.style.display = 'block';
                prevBox.innerHTML = '<strong>' + esc(cpObj.nama_elemen || 'CP') + (cpObj.fase ? ' (' + esc(cpObj.fase) + ')' : '') + ':</strong> ' + esc(cpObj.cp);
            } else if (prevBox) {
                prevBox.style.display = 'none';
            }

            // Filter 1 Dropdown TP berdasarkan CP terpilih
            const tpSel = document.getElementById('wizTpSelect');
            const tpsForCp = cpId ? this.state.wizTpList.filter(function (t) { return Number(t.id_cp) === cpId; }) : [];
            if (!cpId) {
                tpSel.innerHTML = '<option value="">-- Pilih CP terlebih dahulu --</option>';
            } else if (!tpsForCp.length) {
                tpSel.innerHTML = '<option value="">-- Belum ada TP untuk CP ini (Klik "Buat TP & ATP Baru") --</option>';
            } else {
                tpSel.innerHTML = '<option value="">-- Pilih 1 Tujuan Pembelajaran (TP) --</option>' +
                    tpsForCp.map(function (t) {
                        const lbl = (t.kode_tp ? '[' + t.kode_tp + '] ' : '') + (t.materi_pokok ? t.materi_pokok + ' — ' : '') + String(t.tp || '').slice(0, 85) + ' (' + (t.alokasi_jam || 2) + ' JP)';
                        return '<option value="' + t.id + '">' + esc(lbl) + '</option>';
                    }).join('');
            }

            if (forceTpId && tpsForCp.some(function (t) { return Number(t.id) === Number(forceTpId); })) {
                tpSel.value = String(forceTpId);
            } else if (tpsForCp.length) {
                tpSel.value = String(tpsForCp[0].id);
            }
            await this.onWizTpSelectChange();
        },

        async onWizTpSelectChange() {
            const tpId = Number(document.getElementById('wizTpSelect').value) || 0;
            this.state.wizSelectedTpId = tpId;

            const tpObj = this.state.wizTpList.find(function (t) { return Number(t.id) === tpId; });
            if (tpObj) {
                document.getElementById('wizTpCustom').value = (tpObj.kode_tp ? '[' + tpObj.kode_tp + '] ' : '') + tpObj.tp;
                const atpLines = (tpObj.atp_list || []).map(function (a) { return '• ' + a.atp; });
                const itpLines = (tpObj.itp_list || []).map(function (i) { return (i.kode_itp ? '[' + i.kode_itp + '] ' : '• ') + i.itp; });
                document.getElementById('wizAtpCustom').value = atpLines.join('\n');
                document.getElementById('wizItpCustom').value = itpLines.join('\n');
            } else {
                document.getElementById('wizTpCustom').value = '';
                document.getElementById('wizAtpCustom').value = '';
                document.getElementById('wizItpCustom').value = '';
            }

            await this.loadWizardPlanningSlots();
            this.updateWizAutoTitle();
        },

        async loadWizardPlanningSlots() {
            const kmpId = this.state.wizKmpId;
            const cpId = this.state.wizSelectedCpId;
            const tpId = this.state.wizSelectedTpId;
            const slotSel = document.getElementById('wizSlotSelect');
            const quotaWrap = document.getElementById('wizQuotaWrap');
            const quotaCards = document.getElementById('wizQuotaCards');
            const srcBadge = document.getElementById('wizSlotSourceBadge');

            if (!kmpId || !tpId) {
                this.state.wizSlots = [];
                if (quotaWrap) quotaWrap.style.display = 'none';
                if (slotSel) slotSel.innerHTML = '<option value="">-- Pilih CP & TP untuk memuat pertemuan PROTA/PROSEM/Jadwal --</option>';
                return;
            }

            try {
                if (slotSel) slotSel.innerHTML = '<option value="">Memuat jadwal & kuota PROTA/PROSEM/Kaldik...</option>';
                const res = await apiGet('get_planning_slots', { id_kmp: kmpId, id_cp: cpId, id_tp: tpId });
                const d = res.data;
                this.state.wizSlots = d.slots || [];

                // Tampilkan kartu Kuota CP & Kuota TP dari PROTA/PROSEM
                const cq = d.cp_quota || {};
                const tq = d.tp_quota || {};
                if (quotaWrap && quotaCards) {
                    quotaWrap.style.display = 'block';
                    quotaCards.innerHTML =
                        '<div class="ma-quota-card ma-quota-card--navy">' +
                            '<div class="ma-quota-card__label"><iconify-icon icon="solar:diploma-verified-bold"></iconify-icon> Kuota Pertemuan CP (PROTA/PROSEM)</div>' +
                            '<div class="ma-quota-card__val">' + (cq.kuota_pertemuan || 0) + ' Pertemuan (' + (cq.total_jp || 0) + ' JP)</div>' +
                            '<div class="ma-quota-card__sub">Terpakai: <strong>' + (cq.terpakai || 0) + '</strong> &bull; Sisa Kuota: <strong>' + (cq.sisa || 0) + '</strong></div>' +
                        '</div>' +
                        '<div class="ma-quota-card ma-quota-card--teal">' +
                            '<div class="ma-quota-card__label"><iconify-icon icon="solar:target-bold"></iconify-icon> Kuota TP Terpilih (' + esc(tq.tahun_pelajaran || '') + ' &bull; ' + esc(tq.semester || '') + ')</div>' +
                            '<div class="ma-quota-card__val">' + (tq.kuota_pertemuan || 0) + ' Pertemuan (' + (tq.total_jp || 0) + ' JP @ ' + (tq.jp_per_pertemuan || 2) + ' JP/pert)</div>' +
                            '<div class="ma-quota-card__sub">Terpakai: <strong>' + (tq.terpakai || 0) + '</strong> &bull; Sisa Kuota: <strong>' + (tq.sisa || 0) + '</strong></div>' +
                        '</div>';
                }

                if (srcBadge) {
                    const srcLabels = {
                        jadwal_pelajaran: 'Sumber: Jadwal Pelajaran & PROSEM',
                        prosem_kaldik: 'Sumber: PROSEM Mingguan & Kaldik',
                        kaldik: 'Sumber: Kaldik Hari Efektif & Alokasi TP'
                    };
                    srcBadge.textContent = srcLabels[d.source] || 'Tersinkron PROTA/PROSEM';
                }

                // Populate Dropdown Slot Pertemuan
                if (!this.state.wizSlots.length) {
                    slotSel.innerHTML = '<option value="">-- Tidak ada slot terjadwal, gunakan pengaturan manual di bawah --</option>';
                    const defs = d.defaults || {};
                    document.getElementById('wizPertemuanKe').value = defs.next_pertemuan || 1;
                    if (defs.tahun_pelajaran) document.getElementById('wizTahun').value = defs.tahun_pelajaran;
                    if (defs.semester) document.getElementById('wizSemester').value = defs.semester;
                    if (defs.jam_mulai) document.getElementById('wizJamMulai').value = defs.jam_mulai;
                    if (defs.jam_selesai) document.getElementById('wizJamSelesai').value = defs.jam_selesai;
                    if (defs.alokasi_jp) {
                        document.getElementById('wizJumlahJp').value = defs.alokasi_jp;
                        this.recalcWizDuration(true);
                    }
                    return;
                }

                slotSel.innerHTML = this.state.wizSlots.map(function (sl, idx) {
                    const statusLbl = sl.is_used ? '[Sudah Ada Modul]' : '[Tersedia]';
                    const tglStr = sl.tanggal ? (sl.hari + ', ' + formatDateIndo(sl.tanggal)) : 'Tanggal fleksibel';
                    const jamStr = sl.jam_mulai ? (sl.jam_mulai + '-' + (sl.jam_selesai || '')) : '';
                    return '<option value="' + idx + '">' +
                        'Pertemuan ke-' + sl.pertemuan_ke + ' • ' + tglStr + (jamStr ? ' (' + jamStr + ')' : '') + ' • ' + sl.alokasi_jp + ' JP • ' + esc(sl.source) + ' ' + statusLbl +
                    '</option>';
                }).join('');

                // Otomatis pilih slot pertama yang masih [Tersedia]
                let firstAvailIdx = this.state.wizSlots.findIndex(function (s) { return !s.is_used; });
                if (firstAvailIdx === -1) firstAvailIdx = 0;
                slotSel.value = String(firstAvailIdx);
                this.onWizSlotSelectChange();
            } catch (err) {
                notify('error', err.message);
            }
        },

        onWizSlotSelectChange() {
            const idx = parseInt(document.getElementById('wizSlotSelect').value, 10);
            const sl = (!isNaN(idx) && this.state.wizSlots[idx]) ? this.state.wizSlots[idx] : null;
            if (!sl) return;

            document.getElementById('wizJadwalId').value = String(sl.jadwal_id || 0);
            document.getElementById('wizProsemDetailId').value = String(sl.prosem_detail_id || 0);
            document.getElementById('wizPertemuanKe').value = String(sl.pertemuan_ke || 1);

            const tahunSel = document.getElementById('wizTahun');
            if (sl.tahun_pelajaran) {
                if (!Array.from(tahunSel.options).some(function (o) { return o.value === sl.tahun_pelajaran; })) {
                    const opt = document.createElement('option');
                    opt.value = sl.tahun_pelajaran;
                    opt.textContent = sl.tahun_pelajaran;
                    tahunSel.appendChild(opt);
                }
                tahunSel.value = sl.tahun_pelajaran;
            }
            if (sl.semester) document.getElementById('wizSemester').value = sl.semester;
            if (sl.tanggal) document.getElementById('wizTanggal').value = sl.tanggal;
            if (sl.jam_mulai) document.getElementById('wizJamMulai').value = sl.jam_mulai;
            if (sl.jam_selesai) document.getElementById('wizJamSelesai').value = sl.jam_selesai;
            if (sl.alokasi_jp) {
                document.getElementById('wizJumlahJp').value = String(sl.alokasi_jp);
                this.recalcWizDuration(true);
            }
            this.updateWizAutoTitle();
        },

        updateWizAutoTitle() {
            const pert = document.getElementById('wizPertemuanKe') ? document.getElementById('wizPertemuanKe').value : 1;
            const tpCustom = document.getElementById('wizTpCustom') ? document.getElementById('wizTpCustom').value : '';
            const autoTitle = computeAutoModulTitle(
                pert,
                this.state.wizSelectedCpId ? [this.state.wizSelectedCpId] : [],
                this.state.wizCpList,
                this.state.wizSelectedTpId ? [this.state.wizSelectedTpId] : [],
                this.state.wizTpList,
                tpCustom
            );
            const jEl = document.getElementById('wizJudul');
            if (jEl) jEl.value = autoTitle;
            return autoTitle;
        },

        onWizModelChange() {
            const ids = [];
            document.querySelectorAll('.wiz-model-chk').forEach(function (c) {
                c.closest('.ma-check-card').classList.toggle('is-checked', c.checked);
                if (c.checked) ids.push(Number(c.value));
            });
            this.state.wizSelectedModelIds = ids;
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
                eB.value = b; eI.value = i; eT.value = Math.max(5, total - b - i);
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

        previewWizFiles() {
            const fInp = document.getElementById('wizFileLampiran');
            const info = document.getElementById('wizSelectedFilesInfo');
            if (!fInp || !info) return;
            if (!fInp.files || !fInp.files.length) { info.textContent = ''; return; }
            const names = Array.from(fInp.files).map(function (f) { return f.name + ' (' + formatBytes(f.size) + ')'; });
            info.textContent = fInp.files.length + ' berkas dipilih: ' + names.join(', ');
        },

        addWizLinkRow() {
            const wrap = document.getElementById('wizLinksList');
            if (!wrap) return;
            const row = document.createElement('div');
            row.className = 'wiz-link-row';
            row.style.cssText = 'display:grid;grid-template-columns:1fr 1.4fr auto;gap:8px;align-items:center;';
            row.innerHTML =
                '<input type="text" class="ma-input wiz-link-name" placeholder="Nama lampiran (mis: Video / LKPD)">' +
                '<input type="url" class="ma-input wiz-link-url" placeholder="https://drive.google.com/..."> ' +
                '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--del" onclick="this.closest(\'.wiz-link-row\').remove()"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>';
            wrap.appendChild(row);
        },

        wizGoStep(step) {
            const target = Math.max(1, Math.min(6, Number(step)));
            if (target > 1 && this.state.wizStep === 1) {
                if (!this.state.wizKmpId) { notify('warning', 'Pilih Kelas / Rombel terlebih dahulu.'); return; }
                if (!this.state.wizSelectedCpId) { notify('warning', 'Pilih Capaian Pembelajaran (CP) terlebih dahulu.'); return; }
                if (!this.state.wizSelectedTpId) { notify('warning', 'Pilih Tujuan Pembelajaran (TP) terlebih dahulu.'); return; }
            }
            this.updateWizAutoTitle();
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

            if (target === 6) this.renderWizardSummary();
            const sc = document.getElementById('wizBodyScroll');
            if (sc) sc.scrollTop = 0;
        },

        wizNext() { this.wizGoStep(this.state.wizStep + 1); },
        wizPrev() { this.wizGoStep(this.state.wizStep - 1); },

        renderWizardSummary() {
            const el = document.getElementById('wizFinalSummary');
            if (!el) return;
            const judul = this.updateWizAutoTitle();
            const tgl = formatDateIndo(document.getElementById('wizTanggal').value);
            const jam = (document.getElementById('wizJamMulai').value || '') + ' - ' + (document.getElementById('wizJamSelesai').value || '');
            const jp = document.getElementById('wizJumlahJp').value || 2;
            const perJp = document.getElementById('wizAlokasiPerJp').value || 45;
            const fCnt = (document.getElementById('wizFileLampiran').files || []).length;
            const lCnt = Array.from(document.querySelectorAll('.wiz-link-url')).filter(function (i) { return i.value.trim(); }).length;

            el.innerHTML = '<div class="ma-alert-banner__body">' +
                '<iconify-icon icon="solar:check-circle-bold"></iconify-icon>' +
                '<div>' +
                    '<p class="ma-alert-banner__title">Siap Disimpan: ' + esc(judul) + '</p>' +
                    '<p class="ma-alert-banner__desc">Jadwal: <strong>' + esc(tgl) + ' (' + esc(jam) + ')</strong> &bull; ' + jp + ' JP (' + (jp * perJp) + ' Menit) &bull; ' + (fCnt + lCnt) + ' Lampiran QR Code.</p>' +
                '</div>' +
            '</div>';
        },

        async submitCreateModul() {
            if (!this.state.wizKmpId || !this.state.wizSelectedCpId || !this.state.wizSelectedTpId) {
                notify('warning', 'Lengkapi pilihan Kelas, CP, dan TP pada Langkah 1.');
                this.wizGoStep(1);
                return;
            }
            const btn = document.getElementById('wizBtnSave');
            btn.disabled = true;
            try {
                const fd = new FormData();
                fd.append('id_kelas_mata_pelajaran', String(this.state.wizKmpId));
                fd.append('id_jadwal_pelajaran', document.getElementById('wizJadwalId').value || '0');
                fd.append('id_prosem_detail', document.getElementById('wizProsemDetailId').value || '0');
                fd.append('pertemuan_ke', document.getElementById('wizPertemuanKe').value || '1');
                fd.append('fase', document.getElementById('wizFase').value);
                fd.append('tahun_pelajaran', document.getElementById('wizTahun').value);
                fd.append('semester', document.getElementById('wizSemester').value);
                fd.append('tanggal', document.getElementById('wizTanggal').value);
                fd.append('jam_mulai', document.getElementById('wizJamMulai').value);
                fd.append('jam_selesai', document.getElementById('wizJamSelesai').value);
                fd.append('id_materi', document.getElementById('wizMateriId').value || '0');
                fd.append('cp_ids', JSON.stringify([this.state.wizSelectedCpId]));
                fd.append('tp_ids', JSON.stringify([this.state.wizSelectedTpId]));
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

                // Multi-file upload
                const fInp = document.getElementById('wizFileLampiran');
                if (fInp && fInp.files && fInp.files.length) {
                    for (let i = 0; i < fInp.files.length; i++) {
                        fd.append('file_lampiran[]', fInp.files[i]);
                    }
                }
                // External links
                const links = [];
                document.querySelectorAll('.wiz-link-row').forEach(function (row) {
                    const nm = row.querySelector('.wiz-link-name').value.trim();
                    const ur = row.querySelector('.wiz-link-url').value.trim();
                    if (ur) links.push({ name: nm || ur, url: ur });
                });
                if (links.length) fd.append('lampiran_links', JSON.stringify(links));

                const res = await apiPost('create_modul', fd, true);
                notify('success', res.message);
                closeIlmunaModal('modalCreateModulAjar');
                await this.loadBootstrap(false);
                this.switchTab('modul');
                if (res.data && res.data.primary_id) {
                    await this.openModulDetail(res.data.primary_id, false);
                }
            } catch (err) {
                notify('error', err.message, 'Gagal Menyimpan Modul');
            } finally {
                btn.disabled = false;
            }
        },

        openQuickTpFromWizard() {
            if (!this.state.wizCpList.length) {
                notify('warning', 'Belum ada CP dari Admin Kurikulum untuk mata pelajaran ini.');
                return;
            }
            this.state.quickReturnToWizard = true;
            const preCp = this.state.wizSelectedCpId || this.state.wizCpList[0].id;
            this.state.tpCpOptions = this.state.wizCpList.map(function (c) {
                return Object.assign({ nama_kelas: '', nama_mata_pelajaran: '' }, c);
            });
            this.openTpModal(null, preCp);
        },

        /* ======================================================================
         * TAB CP & ELEMEN (ADMIN: MULTI-CHOICE KELAS | GURU: READ-ONLY)
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
                const adminBtns = CFG.canManageCp
                    ? '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">' +
                        '<button type="button" class="ilmuna-btn ilmuna-btn--teal" onclick="MA.openCpModal()"><iconify-icon icon="solar:add-circle-bold"></iconify-icon> Tambah CP (Multi-Kelas)</button>' +
                        '<button type="button" class="ilmuna-btn ilmuna-btn--light-outline" onclick="MA.openSyncCpModal()"><iconify-icon icon="solar:copy-bold"></iconify-icon> Sinkron antar Kelas</button>' +
                      '</div>'
                    : '';
                const emptyDesc = CFG.canManageCp
                    ? 'Tambahkan CP ke banyak kelas/rombel sekaligus dengan centang multi-pilih kelas.'
                    : 'Capaian Pembelajaran (CP) dan Elemen ditetapkan oleh Admin Kurikulum.';
                cont.innerHTML =
                    '<div class="ma-empty-state">' +
                        '<div class="ma-empty-icon"><iconify-icon icon="solar:diploma-verified-bold"></iconify-icon></div>' +
                        '<h3>Belum Ada Capaian Pembelajaran (CP)</h3>' +
                        '<p>' + emptyDesc + '</p>' +
                        adminBtns +
                    '</div>';
                return;
            }

            let html = '<div class="ma-cp-list">';
            list.forEach(function (c) {
                const guruTpBtn = CFG.canManageTp
                    ? '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="MA.createTpFromCp(' + c.id + ',' + c.id_kelas_mata_pelajaran + ')">' +
                        '<iconify-icon icon="solar:target-bold"></iconify-icon> Buat TP &amp; ATP dari CP ini' +
                      '</button>'
                    : '';
                const adminRowBtns = CFG.canManageCp
                    ? '<button type="button" class="ilmuna-btn-icon" title="Edit CP" onclick="MA.editCpById(' + c.id + ')"><iconify-icon icon="solar:pen-bold"></iconify-icon></button>' +
                      '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--del" title="Hapus CP" onclick="MA.deleteCp(' + c.id + ')"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>'
                    : '';
                html += '<div class="ma-cur-card">' +
                    '<div class="ma-cur-card__head">' +
                        '<div class="ma-cur-card__badges">' +
                            '<span class="ma-tag ma-tag--navy">' + esc(c.nama_mata_pelajaran) + '</span>' +
                            '<span class="ma-tag ma-tag--teal">' + esc(c.nama_kelas) + '</span>' +
                            (c.nama_elemen ? '<span class="ma-tag ma-tag--gold">Elemen: ' + esc(c.nama_elemen) + '</span>' : '') +
                            (c.fase ? '<span class="ma-tag">' + esc(c.fase) + '</span>' : '') +
                            (CFG.canManageTp ? '<span class="ma-mini-pill ma-mini-pill--tp">' + (c.tp_count || 0) + ' TP Terkait</span>' : '') +
                        '</div>' +
                        '<div class="ma-card-actions">' + guruTpBtn + adminRowBtns + '</div>' +
                    '</div>' +
                    '<p class="ma-cur-card__text">' + nl2brEsc(c.cp) + '</p>' +
                '</div>';
            });
            html += '</div>';
            cont.innerHTML = html;
        },

        openCpModal(editRow, forceKmpId) {
            if (!CFG.canManageCp) return;
            const isEdit = !!(editRow && editRow.id);
            document.getElementById('cpFormId').value = isEdit ? String(editRow.id) : '0';
            document.getElementById('cpFormSingleKmp').value = isEdit ? String(editRow.id_kelas_mata_pelajaran) : '0';
            document.getElementById('cpFormEditElemenId').value = isEdit ? String(editRow.id_elemen || 0) : '0';
            document.getElementById('cpModalTitle').textContent = isEdit ? 'Edit Capaian Pembelajaran (CP)' : 'Tambah CP (Multi-Pilih Kelas)';
            document.getElementById('cpFormNewElemen').value = '';
            document.getElementById('cpFormText').value = isEdit ? (editRow.cp || '') : '';

            const elNamaSel = document.getElementById('cpFormElemenNama');
            if (elNamaSel) {
                elNamaSel.value = (isEdit && editRow.nama_elemen) ? editRow.nama_elemen : '';
                if (isEdit && editRow.nama_elemen && elNamaSel.value !== editRow.nama_elemen) {
                    document.getElementById('cpFormNewElemen').value = editRow.nama_elemen;
                }
            }
            const faseSel = document.getElementById('cpFormFase');
            if (faseSel) faseSel.value = (isEdit && editRow.fase) ? editRow.fase : 'AUTO';

            const multiSec = document.getElementById('cpMultiClassSection');
            const editSec = document.getElementById('cpEditClassBadge');

            if (isEdit) {
                multiSec.style.display = 'none';
                editSec.style.display = 'flex';
                document.getElementById('cpEditClassLabel').value = (editRow.nama_mata_pelajaran || '') + ' — ' + (editRow.nama_kelas || '');
            } else {
                multiSec.style.display = 'flex';
                editSec.style.display = 'none';

                // Pre-filter mapel jika datang dari kelas tertentu
                const mapelF = document.getElementById('cpFormMapelFilter');
                let preCheckedKmp = forceKmpId ? Number(forceKmpId) : (Number(document.getElementById('cpKmpFilter') ? document.getElementById('cpKmpFilter').value : 0) || 0);
                if (preCheckedKmp && mapelF) {
                    const found = this.state.kmpList.find(function (k) { return Number(k.id_kmp) === preCheckedKmp; });
                    if (found) mapelF.value = String(found.id_mata_pelajaran);
                } else if (mapelF && this.state.subjects.length) {
                    mapelF.value = String(this.state.subjects[0].id_mata_pelajaran);
                }
                this.renderCpMultiClassGrid(preCheckedKmp ? [preCheckedKmp] : null);
            }

            openIlmunaModal('modalCpForm');
        },

        renderCpMultiClassGrid(preCheckedIds) {
            const grid = document.getElementById('cpFormKmpGrid');
            if (!grid) return;
            const filterMapel = Number(document.getElementById('cpFormMapelFilter').value) || 0;
            const items = filterMapel
                ? this.state.kmpList.filter(function (k) { return Number(k.id_mata_pelajaran) === filterMapel; })
                : this.state.kmpList;

            if (!items.length) {
                grid.innerHTML = '<p style="font-size:12.5px;color:#64748B;margin:4px 0;">Tidak ada rombel kelas pada mata pelajaran ini.</p>';
                return;
            }

            grid.innerHTML = items.map(function (k) {
                const isChk = Array.isArray(preCheckedIds) ? (preCheckedIds.indexOf(Number(k.id_kmp)) !== -1) : (filterMapel > 0);
                return '<label class="ma-check-card' + (isChk ? ' is-checked' : '') + '">' +
                    '<input type="checkbox" class="cp-kmp-chk" value="' + k.id_kmp + '"' + (isChk ? ' checked' : '') + ' onchange="this.closest(\'.ma-check-card\').classList.toggle(\'is-checked\', this.checked)">' +
                    '<div class="ma-check-card__body">' +
                        '<span class="ma-check-card__title">' + esc(k.nama_kelas) + ' <span class="ma-tag ma-tag--navy" style="font-size:10px;padding:1px 6px;">' + esc(k.fase) + '</span></span>' +
                        '<span class="ma-check-card__desc">' + esc(k.nama_mata_pelajaran) + ' &bull; ' + esc(k.nama_guru) + '</span>' +
                    '</div>' +
                '</label>';
            }).join('');
        },

        toggleAllCpClasses() {
            const chks = Array.from(document.querySelectorAll('.cp-kmp-chk'));
            const allChecked = chks.length > 0 && chks.every(function (c) { return c.checked; });
            chks.forEach(function (c) {
                c.checked = !allChecked;
                c.closest('.ma-check-card').classList.toggle('is-checked', c.checked);
            });
        },

        editCpById(id) {
            const row = this.state.cpList.find(function (c) { return Number(c.id) === Number(id); });
            if (row) this.openCpModal(row);
        },

        async submitCp(ev) {
            ev.preventDefault();
            const id = parseInt(document.getElementById('cpFormId').value, 10) || 0;
            const payload = {
                id: id,
                elemen_nama: document.getElementById('cpFormElemenNama').value.trim(),
                new_elemen_nama: document.getElementById('cpFormNewElemen').value.trim(),
                fase: document.getElementById('cpFormFase').value,
                cp: document.getElementById('cpFormText').value.trim()
            };

            if (id > 0) {
                payload.id_kelas_mata_pelajaran = parseInt(document.getElementById('cpFormSingleKmp').value, 10);
                payload.id_elemen = parseInt(document.getElementById('cpFormEditElemenId').value, 10) || 0;
            } else {
                const checkedKmpIds = Array.from(document.querySelectorAll('.cp-kmp-chk:checked')).map(function (c) { return Number(c.value); });
                if (!checkedKmpIds.length) {
                    notify('warning', 'Centang minimal 1 Kelas / Rombel terlebih dahulu.');
                    return;
                }
                payload.kmp_ids = checkedKmpIds;
            }

            try {
                const res = await apiPost('save_cp', payload);
                notify('success', res.message);
                closeIlmunaModal('modalCpForm');
                await this.loadBootstrap(false);
                await this.loadCpTab();
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
            let defaultSrc = '';
            this.state.subjects.forEach(function (s) {
                s.classes.forEach(function (c) {
                    if (!defaultSrc && c.cp_count > 0) defaultSrc = String(c.id_kmp);
                });
            });
            const srcEl = document.getElementById('syncSourceKmp');
            if (srcEl && defaultSrc) srcEl.value = defaultSrc;

            const grid = document.getElementById('syncTargetKmpGrid');
            if (grid) {
                grid.innerHTML = this.state.kmpList.map(function (k) {
                    return '<label class="ma-check-card">' +
                        '<input type="checkbox" class="sync-target-chk" value="' + k.id_kmp + '" onchange="this.closest(\'.ma-check-card\').classList.toggle(\'is-checked\', this.checked)">' +
                        '<div class="ma-check-card__body"><span class="ma-check-card__title">' + esc(k.nama_kelas) + ' (' + esc(k.fase) + ')</span><span class="ma-check-card__desc">' + esc(k.nama_mata_pelajaran) + '</span></div>' +
                    '</label>';
                }).join('');
            }
            openIlmunaModal('modalSyncCp');
        },

        async submitSyncCp(ev) {
            ev.preventDefault();
            const targets = Array.from(document.querySelectorAll('.sync-target-chk:checked')).map(function (c) { return Number(c.value); });
            if (!targets.length) { notify('warning', 'Centang minimal 1 kelas tujuan.'); return; }
            try {
                const res = await apiPost('sync_cp_mapel', {
                    source_kmp: parseInt(document.getElementById('syncSourceKmp').value, 10),
                    target_kmps: targets
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
         * TAB TP, ATP & ITP (KHUSUS GURU)
         * ====================================================================== */
        async createTpFromCp(idCp, idKmp) {
            if (!CFG.canManageTp) return;
            const kmpF = document.getElementById('tpKmpFilter');
            if (kmpF) kmpF.value = String(idKmp);
            this.switchTab('tp');
            await this.loadTpTab();
            this.openTpModal(null, idCp);
        },

        async loadTpTab() {
            if (!CFG.canManageTp) return;
            const cont = document.getElementById('maTpContainer');
            if (cont) cont.innerHTML = '<div class="ma-skeleton-card" style="height:160px;"></div>';
            try {
                const idKmp = document.getElementById('tpKmpFilter') ? document.getElementById('tpKmpFilter').value : '';
                const idCp = document.getElementById('tpCpFilter') ? document.getElementById('tpCpFilter').value : '';
                const q = document.getElementById('tpSearchInput') ? document.getElementById('tpSearchInput').value.trim() : '';

                const res = await apiGet('list_tp', { id_kmp: idKmp, id_cp: idCp, q: q });
                this.state.tpList = res.data.tp_list || [];
                this.state.tpCpOptions = res.data.cp_options || [];

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
                        '<h3>Belum Ada Tujuan Pembelajaran (TP &amp; ATP)</h3>' +
                        '<p>Pilih Capaian Pembelajaran (CP) yang ditetapkan Admin lalu buat Tujuan Pembelajaran (TP), ATP &amp; ITP sebelum menyusun Modul Ajar.</p>' +
                        '<button type="button" class="ilmuna-btn ilmuna-btn--teal" onclick="MA.openTpModal()">' +
                            '<iconify-icon icon="solar:add-circle-bold"></iconify-icon> Buat TP &amp; ATP Baru' +
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
                }).join('') || '<div style="font-size:12px;color:#94A3B8;">Belum ada ITP.</div>';

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
            if (!CFG.canManageTp) return;
            const cpSel = document.getElementById('tpFormCp');
            if (!this.state.tpCpOptions.length) {
                notify('warning', 'Belum ada Capaian Pembelajaran (CP) dari Admin Kurikulum untuk mata pelajaran Anda.');
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
                const res = await apiPost('save_tp', {
                    id: parseInt(document.getElementById('tpFormId').value, 10) || 0,
                    id_cp: parseInt(document.getElementById('tpFormCp').value, 10),
                    kode_tp: document.getElementById('tpFormKode').value.trim(),
                    alokasi_jam: parseInt(document.getElementById('tpFormJp').value, 10) || 4,
                    materi_pokok: document.getElementById('tpFormMateri').value.trim(),
                    tp: document.getElementById('tpFormText').value.trim(),
                    initial_atp: document.getElementById('tpFormInitAtp').value.trim(),
                    initial_itp: document.getElementById('tpFormInitItp').value.trim()
                });
                notify('success', res.message);
                closeIlmunaModal('modalTpForm');
                await this.loadBootstrap(false);

                if (this.state.quickReturnToWizard) {
                    this.state.quickReturnToWizard = false;
                    await this.onWizKmpChange({ id_cp: res.data.id_cp, id: res.data.id });
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
            if (!CFG.canManageTp) return;
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
         * TEXT POPUP EDITOR
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
                if (target) target.value = document.getElementById('textPopupArea').value;
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
