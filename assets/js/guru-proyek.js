/**
 * ==============================================================================
 * ILMUNA LEARN — PROYEK & P5 (PjBL) SPA CONTROLLER
 * ==============================================================================
 * Terintegrasi penuh dengan Modul Ajar, Jadwal Mengajar, TP/ITP, Kelompok Tim,
 * Hasil Karya & Penilaian P5 (SB/BSH/MB/BB), serta Transparansi Anggaran Proyek.
 * Pure Vanilla ES6+ | Zero Page Reload | window.Ilmuna Only | Solar Icons Only
 * ==============================================================================
 */
(function () {
    'use strict';

    const CFG = window.ILMUNA_PROYEK_CFG || {
        baseUrl: '',
        endpoint: '/dashboard/guru/proyek',
        activeRole: 'guru',
        canManage: true
    };

    const P5_DIMENSIONS = [
        'Beriman, Bertakwa kepada Tuhan YME & Berakhlak Mulia',
        'Berkebinekaan Global',
        'Bergotong Royong',
        'Mandiri',
        'Bernalar Kritis',
        'Kreatif',
        'Rahmatan Lil Alamin (PPRA)'
    ];

    const ROLE_PRESETS = [
        'Ketua Tim', 'Sekretaris', 'Bendahara', 'Penulis Naskah',
        'Peneliti', 'Desainer / Editor', 'Presenter', 'Dokumentasi', 'Anggota'
    ];

    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatDateIndo(ymd) {
        if (!ymd) return '-';
        const p = String(ymd).slice(0, 10).split('-');
        if (p.length !== 3) return ymd;
        const bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        return parseInt(p[2], 10) + ' ' + (bln[parseInt(p[1], 10)] || p[1]) + ' ' + p[0];
    }

    function formatRupiah(num) {
        const n = Math.round(Number(num) || 0);
        return 'Rp ' + n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function initials(name) {
        const parts = String(name || 'S').trim().split(/\s+/);
        return ((parts[0] || 'S')[0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
    }

    function notify(type, message, title) {
        const ui = window.Ilmuna;
        const t = title || (type === 'error' ? 'Gagal' : type === 'warning' ? 'Perhatian' : type === 'info' ? 'Informasi' : 'Berhasil');
        if (ui && typeof ui.toast === 'function') {
            ui.toast(type, t, message || '', 4500);
            return;
        }
        console[type === 'error' ? 'error' : 'log']('[Proyek]', t, message);
    }

    async function confirmAction(type, title, message, confirmText) {
        const ui = window.Ilmuna;
        if (ui && typeof ui.confirm === 'function') {
            return await ui.confirm(type || 'warning', title || 'Konfirmasi', message || '', confirmText || 'Ya, Lanjutkan', 'Batal');
        }
        return true;
    }

    async function apiPost(action, data, isFormData) {
        let body;
        if (isFormData && data instanceof FormData) {
            data.append('action', action);
            body = data;
        } else {
            const fd = new FormData();
            fd.append('action', action);
            if (data && typeof data === 'object') {
                Object.keys(data).forEach(function (k) {
                    if (data[k] !== undefined && data[k] !== null) {
                        fd.append(k, String(data[k]));
                    }
                });
            }
            body = fd;
        }
        const res = await fetch(CFG.endpoint + '?api=1', {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Permintaan gagal diproses.');
        }
        return json;
    }

    function statusBadge(status) {
        const map = {
            'Perencanaan': 'pry-badge--slate',
            'Sedang Berjalan': 'pry-badge--teal',
            'Tahap Pengujian': 'pry-badge--amber',
            'Selesai': 'pry-badge--emerald',
            'Diarsipkan': 'pry-badge--slate',
            'Diserahkan': 'pry-badge--navy',
            'Revisi': 'pry-badge--rose',
            'Disetujui': 'pry-badge--emerald',
            'Dipublikasikan': 'pry-badge--purple',
            'Menunggu Verifikasi': 'pry-badge--amber',
            'Diverifikasi Guru': 'pry-badge--emerald',
            'Ditolak': 'pry-badge--rose'
        };
        return '<span class="pry-badge ' + (map[status] || 'pry-badge--slate') + '">' + esc(status || '-') + '</span>';
    }

    function predikatBadge(pred) {
        if (!pred) return '<span class="pry-badge pry-badge--slate">Belum Dinilai</span>';
        let cls = 'pry-badge--slate';
        if (pred.indexOf('(SB)') !== -1) cls = 'pry-badge--emerald';
        else if (pred.indexOf('(BSH)') !== -1) cls = 'pry-badge--teal';
        else if (pred.indexOf('(MB)') !== -1) cls = 'pry-badge--amber';
        else if (pred.indexOf('(BB)') !== -1) cls = 'pry-badge--rose';
        return '<span class="pry-badge ' + cls + '">' + esc(pred) + '</span>';
    }

    function suggestPredikat(score) {
        const n = parseFloat(score);
        if (isNaN(n)) return '';
        if (n >= 90) return 'Sangat Berkembang (SB)';
        if (n >= 75) return 'Berkembang Sesuai Harapan (BSH)';
        if (n >= 60) return 'Mulai Berkembang (MB)';
        return 'Belum Berkembang (BB)';
    }

    function suggestHuruf(score) {
        const n = parseFloat(score);
        if (isNaN(n)) return '-';
        if (n >= 90) return 'A';
        if (n >= 80) return 'B';
        if (n >= 70) return 'C';
        if (n >= 60) return 'D';
        return 'E';
    }

    const PRY = {
        state: {
            activeTab: 'proyek',
            kmpList: [],
            kelasList: [],
            proyekList: [],
            modulFilterCatalog: [],
            formModulCatalog: [],
            selectedProyekId: 0,
            selectedProyekMeta: null,
            totalSiswaKelas: 0,
            unassignedStudents: [],
            kelasStudents: [],
            kelompokList: [],
            submissionList: [],
            biayaList: [],
            filterKelas: 0,
            filterKmp: 0,
            filterModul: 0,
            filterStatus: '',
            search: '',
            selectedP5: []
        },

        async init() {
            this.bindEvents();
            await this.loadBootstrap();

            // Tangani Deep-Link URL dari Jadwal Mengajar / Modul Ajar: ?id_kelas=&id_kmp=&id_modul_ajar=
            const qs = new URLSearchParams(window.location.search);
            const qKelas = parseInt(qs.get('id_kelas') || '0', 10);
            const qKmp = parseInt(qs.get('id_kmp') || '0', 10);
            const qModul = parseInt(qs.get('id_modul_ajar') || '0', 10);

            if (qKmp > 0) {
                const kmpObj = this.state.kmpList.find(function (x) { return Number(x.id_kmp) === qKmp; });
                if (kmpObj) {
                    this.state.filterKelas = Number(kmpObj.id_kelas);
                    const fk = document.getElementById('pryFilterKelas');
                    if (fk) fk.value = String(this.state.filterKelas);
                    this.populateMapelFilter();
                    this.state.filterKmp = qKmp;
                    const fm = document.getElementById('pryFilterKmp');
                    if (fm) fm.value = String(qKmp);
                    await this.loadModulFilterOptions(qKmp);
                    if (qModul > 0) {
                        this.state.filterModul = qModul;
                        const fmod = document.getElementById('pryFilterModul');
                        if (fmod) fmod.value = String(qModul);
                    }
                }
            } else if (qKelas > 0) {
                this.state.filterKelas = qKelas;
                const fk = document.getElementById('pryFilterKelas');
                if (fk) fk.value = String(qKelas);
                this.populateMapelFilter();
            }

            await this.loadProyekList();
        },

        bindEvents() {
            const self = this;
            document.querySelectorAll('#pryMainTabs .curriculum-tab-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    self.switchTab(btn.getAttribute('data-tab'));
                });
            });

            const fk = document.getElementById('pryFilterKelas');
            if (fk) fk.addEventListener('change', function () {
                self.state.filterKelas = parseInt(fk.value || '0', 10);
                self.state.filterKmp = 0;
                self.state.filterModul = 0;
                self.populateMapelFilter();
                self.resetModulFilter();
                self.loadProyekList();
            });

            const fm = document.getElementById('pryFilterKmp');
            if (fm) fm.addEventListener('change', async function () {
                self.state.filterKmp = parseInt(fm.value || '0', 10);
                self.state.filterModul = 0;
                if (self.state.filterKmp > 0) {
                    await self.loadModulFilterOptions(self.state.filterKmp);
                } else {
                    self.resetModulFilter();
                }
                self.loadProyekList();
            });

            const fmod = document.getElementById('pryFilterModul');
            if (fmod) fmod.addEventListener('change', function () {
                self.state.filterModul = parseInt(fmod.value || '0', 10);
                self.loadProyekList();
            });

            const fstat = document.getElementById('pryFilterStatus');
            if (fstat) fstat.addEventListener('change', function () {
                self.state.filterStatus = fstat.value || '';
                self.loadProyekList();
            });

            let tSearch = null;
            const fsearch = document.getElementById('prySearchInput');
            if (fsearch) fsearch.addEventListener('input', function () {
                clearTimeout(tSearch);
                tSearch = setTimeout(function () {
                    self.state.search = fsearch.value.trim();
                    self.loadProyekList();
                }, 260);
            });

            // Sync dropdown proyek aktif di Tab 2, 3, 4
            ['pryTeamProyekSelect', 'prySubProyekSelect', 'pryBudgetProyekSelect'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) {
                    el.addEventListener('change', function () {
                        const pid = parseInt(el.value || '0', 10);
                        self.selectActiveProyek(pid);
                    });
                }
            });

            // Auto-hitung predikat P5 saat input nilai di modal penilaian
            const gradeScore = document.getElementById('gradeNilai');
            if (gradeScore) {
                gradeScore.addEventListener('input', function () {
                    const predSel = document.getElementById('gradePredikat');
                    if (predSel && gradeScore.value !== '') {
                        predSel.value = suggestPredikat(gradeScore.value);
                    }
                });
            }

            // Auto-hitung total biaya di modal anggaran
            ['biayaQty', 'biayaHarga'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', function () { self.recalcBiayaPreview(); });
            });
        },

        async loadBootstrap() {
            try {
                const res = await apiPost('bootstrap', {});
                const d = res.data || {};
                this.state.kmpList = d.kmp_list || [];
                this.state.kelasList = d.kelas_list || [];

                const fk = document.getElementById('pryFilterKelas');
                const formK = document.getElementById('pryFormKelas');
                const opts = '<option value="0">Semua Rombel Kelas</option>' + this.state.kelasList.map(function (k) {
                    return '<option value="' + k.id_kelas + '">' + esc(k.nama_kelas) + '</option>';
                }).join('');
                if (fk) fk.innerHTML = opts;
                if (formK) {
                    formK.innerHTML = '<option value="0">-- Pilih Rombel Kelas --</option>' + this.state.kelasList.map(function (k) {
                        return '<option value="' + k.id_kelas + '">' + esc(k.nama_kelas) + '</option>';
                    }).join('');
                }
                this.populateMapelFilter();
            } catch (e) {
                notify('error', e.message || 'Gagal memuat data awal proyek.');
            }
        },

        populateMapelFilter() {
            const fm = document.getElementById('pryFilterKmp');
            if (!fm) return;
            const kId = this.state.filterKelas;
            const list = kId > 0
                ? this.state.kmpList.filter(function (x) { return Number(x.id_kelas) === kId; })
                : this.state.kmpList;
            fm.innerHTML = '<option value="0">Semua Mata Pelajaran</option>' + list.map(function (x) {
                const lbl = kId > 0 ? x.nama_mata_pelajaran : (x.nama_kelas + ' — ' + x.nama_mata_pelajaran);
                return '<option value="' + x.id_kmp + '">' + esc(lbl) + '</option>';
            }).join('');
        },

        resetModulFilter() {
            const fmod = document.getElementById('pryFilterModul');
            if (!fmod) return;
            fmod.innerHTML = '<option value="0">Semua Modul Ajar</option>';
            fmod.disabled = true;
            this.state.modulFilterCatalog = [];
        },

        async loadModulFilterOptions(idKmp) {
            const fmod = document.getElementById('pryFilterModul');
            if (!fmod) return;
            fmod.innerHTML = '<option value="0">Memuat Modul Ajar...</option>';
            fmod.disabled = true;
            try {
                const res = await apiPost('list_modul_by_kmp', { id_kmp: idKmp });
                const list = res.data || [];
                this.state.modulFilterCatalog = list;
                fmod.innerHTML = '<option value="0">Semua Modul Ajar (' + list.length + ')</option>' + list.map(function (m) {
                    return '<option value="' + m.id + '">P' + m.pertemuan_ke + ' — ' + esc((m.judul_modul || '').slice(0, 58)) + '</option>';
                }).join('');
                fmod.disabled = false;
            } catch (e) {
                fmod.innerHTML = '<option value="0">Semua Modul Ajar</option>';
                fmod.disabled = false;
            }
        },

        switchTab(tab) {
            this.state.activeTab = tab;
            document.querySelectorAll('#pryMainTabs .curriculum-tab-btn').forEach(function (b) {
                b.classList.toggle('active', b.getAttribute('data-tab') === tab);
            });
            document.querySelectorAll('.pry-tab-pane').forEach(function (p) {
                p.style.display = (p.id === 'pry-tab-' + tab) ? 'block' : 'none';
            });

            if (tab !== 'proyek') {
                if (this.state.selectedProyekId <= 0 && this.state.proyekList.length > 0) {
                    this.state.selectedProyekId = Number(this.state.proyekList[0].id);
                }
                this.syncProyekSelectors();
                this.loadActiveSubTab();
            }
        },

        syncProyekSelectors() {
            const opts = this.state.proyekList.length === 0
                ? '<option value="0">-- Belum Ada Proyek --</option>'
                : this.state.proyekList.map(function (p) {
                    const modTag = p.pertemuan_ke ? ' [P' + p.pertemuan_ke + ']' : '';
                    return '<option value="' + p.id + '">' + esc(p.nama_proyek) + modTag + ' (' + esc(p.nama_kelas) + ' • ' + esc(p.nama_mata_pelajaran) + ')</option>';
                }).join('');

            ['pryTeamProyekSelect', 'prySubProyekSelect', 'pryBudgetProyekSelect'].forEach((id) => {
                const el = document.getElementById(id);
                if (el) {
                    el.innerHTML = opts;
                    if (this.state.selectedProyekId > 0) {
                        el.value = String(this.state.selectedProyekId);
                    }
                }
            });
        },

        selectActiveProyek(proyekId) {
            this.state.selectedProyekId = Number(proyekId || 0);
            this.syncProyekSelectors();
            this.loadActiveSubTab();
        },

        openProyekSection(proyekId, tab) {
            this.state.selectedProyekId = Number(proyekId || 0);
            this.switchTab(tab);
        },

        async loadActiveSubTab() {
            if (this.state.activeTab === 'kelompok') {
                await this.loadKelompokTab();
            } else if (this.state.activeTab === 'karya') {
                await this.loadSubmissionsTab();
            } else if (this.state.activeTab === 'anggaran') {
                await this.loadBiayaTab();
            }
        },

        /* ======================================================================
         * TAB 1: DAFTAR PROYEK & P5
         * ====================================================================== */
        async loadProyekList() {
            const wrap = document.getElementById('pryCardGrid');
            if (wrap) {
                wrap.innerHTML = '<div class="pry-empty" style="grid-column:1/-1;"><iconify-icon icon="solar:refresh-bold"></iconify-icon><h4>Memuat Daftar Proyek...</h4></div>';
            }
            try {
                const res = await apiPost('list_proyek', {
                    id_kelas: this.state.filterKelas,
                    id_kmp: this.state.filterKmp,
                    id_modul_ajar: this.state.filterModul,
                    status_proyek: this.state.filterStatus,
                    search: this.state.search
                });
                const d = res.data || {};
                this.state.proyekList = d.items || [];
                const s = d.stats || {};
                this.updateKpi(s);
                this.renderContextBanner();
                this.renderProyekCards();
                this.syncProyekSelectors();

                if (this.state.selectedProyekId > 0) {
                    const stillExists = this.state.proyekList.some((x) => Number(x.id) === this.state.selectedProyekId);
                    if (!stillExists) {
                        this.state.selectedProyekId = this.state.proyekList.length > 0 ? Number(this.state.proyekList[0].id) : 0;
                        this.syncProyekSelectors();
                    }
                }
                if (this.state.activeTab !== 'proyek') {
                    this.loadActiveSubTab();
                }
            } catch (e) {
                if (wrap) {
                    wrap.innerHTML = '<div class="pry-empty" style="grid-column:1/-1;"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon><h4>Gagal Memuat Proyek</h4><p>' + esc(e.message) + '</p></div>';
                }
            }
        },

        updateKpi(s) {
            const setTxt = function (id, v) { const el = document.getElementById(id); if (el) el.textContent = v; };
            setTxt('kpiTotalProyek', String(s.total_proyek || 0));
            setTxt('kpiLinkedModul', String(s.linked_modul || 0));
            setTxt('kpiTotalKarya', String(s.total_karya || 0) + ' / ' + String(s.karya_dinilai || 0) + ' dinilai');
            setTxt('kpiTotalBiaya', formatRupiah(s.total_biaya || 0));
        },

        renderContextBanner() {
            const el = document.getElementById('pryContextBanner');
            if (!el) return;
            if (this.state.filterModul > 0) {
                const m = this.state.modulFilterCatalog.find((x) => Number(x.id) === this.state.filterModul);
                el.style.display = 'flex';
                el.innerHTML =
                    '<div class="pry-context-banner__info">' +
                        '<iconify-icon icon="solar:notebook-bookmark-bold" style="font-size:18px;"></iconify-icon>' +
                        '<span>Menampilkan proyek khusus untuk Modul Ajar: <strong>' + esc(m ? ('P' + m.pertemuan_ke + ' — ' + m.judul_modul) : ('ID #' + this.state.filterModul)) + '</strong></span>' +
                    '</div>' +
                    '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                        '<a class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" href="' + esc(CFG.baseUrl + '/dashboard/guru/modul-ajar?open_modul=' + this.state.filterModul) + '"><iconify-icon icon="solar:book-2-bold"></iconify-icon> Buka Modul Ajar</a>' +
                        '<button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="PRY.clearModulFilter()">Tampilkan Semua Modul</button>' +
                    '</div>';
            } else {
                el.style.display = 'none';
            }
        },

        clearModulFilter() {
            this.state.filterModul = 0;
            const fmod = document.getElementById('pryFilterModul');
            if (fmod) fmod.value = '0';
            this.loadProyekList();
        },

        renderProyekCards() {
            const wrap = document.getElementById('pryCardGrid');
            if (!wrap) return;
            const list = this.state.proyekList;
            if (!list.length) {
                wrap.innerHTML =
                    '<div class="pry-empty" style="grid-column:1/-1;">' +
                        '<iconify-icon icon="solar:rocket-2-bold-duotone"></iconify-icon>' +
                        '<h4>Belum Ada Proyek &amp; P5 (PjBL)</h4>' +
                        '<p>Buat proyek pembelajaran baru dan tautkan langsung ke Modul Ajar serta Pertemuan agar tersinkronisasi ke Jadwal Mengajar Guru.</p>' +
                        (CFG.canManage ? '<button type="button" class="ilmuna-btn ilmuna-btn--teal" onclick="PRY.openProyekModal(0)"><iconify-icon icon="solar:add-circle-bold"></iconify-icon> Buat Proyek Baru</button>' : '') +
                    '</div>';
                return;
            }

            wrap.innerHTML = list.map(function (p) {
                const p5List = String(p.dimensi_profil_pancasila || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 3);
                const p5Html = p5List.length
                    ? '<div class="pry-p5-chips">' + p5List.map(function (d) { return '<span class="pry-p5-chip">' + esc(d) + '</span>'; }).join('') + '</div>'
                    : '';

                const modulStrip = Number(p.id_modul_ajar) > 0
                    ? '<div class="pry-modul-strip">' +
                        '<div class="pry-modul-strip__text">' +
                            '<iconify-icon icon="solar:notebook-bookmark-bold" style="font-size:16px;flex-shrink:0;"></iconify-icon>' +
                            '<span>P' + esc(p.modul_pertemuan_ke || p.pertemuan_ke || '-') + ' • ' + esc(p.judul_modul || 'Modul Ajar') + '</span>' +
                        '</div>' +
                        '<a href="' + esc(CFG.baseUrl + '/dashboard/guru/modul-ajar?open_modul=' + p.id_modul_ajar) + '">Buka Modul &rarr;</a>' +
                      '</div>'
                    : '<div class="pry-modul-strip" style="background:#FFFBEB;border-color:#FDE68A;color:#92400E;">' +
                        '<div class="pry-modul-strip__text">' +
                            '<iconify-icon icon="solar:info-circle-bold" style="font-size:16px;flex-shrink:0;"></iconify-icon>' +
                            '<span>Belum ditautkan ke Modul Ajar</span>' +
                        '</div>' +
                      '</div>';

                const guideLink = p.file_panduan
                    ? '<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + p.file_panduan.replace(/^\/+/, '')) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:document-text-bold"></iconify-icon> Panduan</a>'
                    : '';
                const refLink = p.link_referensi
                    ? '<a class="pry-link-chip" href="' + esc(p.link_referensi) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:link-bold"></iconify-icon> Referensi</a>'
                    : '';

                return '<article class="pry-card">' +
                    '<div class="pry-card-head">' +
                        '<div style="min-width:0;flex:1;">' +
                            '<h3 class="pry-card-title">' + esc(p.nama_proyek) + '</h3>' +
                            '<div class="pry-card-sub">' +
                                '<span><iconify-icon icon="solar:buildings-2-bold"></iconify-icon> ' + esc(p.nama_kelas) + '</span>' +
                                '<span>&bull;</span>' +
                                '<span>' + esc(p.nama_mata_pelajaran) + '</span>' +
                            '</div>' +
                        '</div>' +
                        '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">' +
                            statusBadge(p.status_proyek) +
                            '<span class="pry-badge ' + (p.jenis_proyek === 'Kelompok' ? 'pry-badge--navy' : 'pry-badge--purple') + '">' +
                                '<iconify-icon icon="' + (p.jenis_proyek === 'Kelompok' ? 'solar:users-group-rounded-bold' : 'solar:user-bold') + '"></iconify-icon> ' + esc(p.jenis_proyek) +
                            '</span>' +
                        '</div>' +
                    '</div>' +
                    modulStrip +
                    '<div class="pry-card-desc">' + esc(p.deskripsi_tujuan || '-') + '</div>' +
                    '<div class="pry-card-meta-grid">' +
                        '<div class="pry-card-meta-item"><iconify-icon icon="solar:palette-bold"></iconify-icon><span title="' + esc(p.tema_proyek) + '">Tema: ' + esc(p.tema_proyek) + '</span></div>' +
                        '<div class="pry-card-meta-item"><iconify-icon icon="solar:box-bold"></iconify-icon><span title="' + esc(p.target_luaran) + '">Luaran: ' + esc(p.target_luaran) + '</span></div>' +
                        '<div class="pry-card-meta-item"><iconify-icon icon="solar:calendar-bold"></iconify-icon><span>' + formatDateIndo(p.jadwal_mulai) + ' &ndash; ' + formatDateIndo(p.jadwal_selesai) + '</span></div>' +
                        '<div class="pry-card-meta-item"><iconify-icon icon="solar:wallet-money-bold"></iconify-icon><span>Biaya: ' + formatRupiah(p.total_biaya || 0) + '</span></div>' +
                    '</div>' +
                    p5Html +
                    ((guideLink || refLink) ? '<div>' + guideLink + refLink + '</div>' : '') +
                    '<div class="pry-card-footer">' +
                        '<div class="pry-card-stats">' +
                            '<span><iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon> ' + (p.jumlah_kelompok || 0) + ' Tim</span>' +
                            '<span><iconify-icon icon="solar:folder-check-bold"></iconify-icon> ' + (p.jumlah_karya || 0) + ' Karya</span>' +
                        '</div>' +
                        '<div class="pry-card-actions">' +
                            '<button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" onclick="PRY.openProyekSection(' + p.id + ',\'kelompok\')" title="Kelola Kelompok"><iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon> Tim</button>' +
                            '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="PRY.openProyekSection(' + p.id + ',\'karya\')" title="Nilai Hasil Karya"><iconify-icon icon="solar:medal-star-bold"></iconify-icon> Karya</button>' +
                            '<button type="button" class="pry-icon-btn" onclick="PRY.openProyekSection(' + p.id + ',\'anggaran\')" title="Anggaran Proyek"><iconify-icon icon="solar:wallet-money-bold"></iconify-icon></button>' +
                            (CFG.canManage ? '<button type="button" class="pry-icon-btn" onclick="PRY.openProyekModal(' + p.id + ')" title="Edit Proyek"><iconify-icon icon="solar:pen-bold"></iconify-icon></button>' : '') +
                            (CFG.canManage ? '<button type="button" class="pry-icon-btn pry-icon-btn--danger" onclick="PRY.deleteProyek(' + p.id + ',\'' + esc(p.nama_proyek).replace(/'/g, "\\'") + '\')" title="Hapus Proyek"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>' : '') +
                        '</div>' +
                    '</div>' +
                '</article>';
            }).join('');
        },

        /* ======================================================================
         * MODAL CREATE / EDIT PROYEK (TERINTEGRASI MODUL AJAR)
         * ====================================================================== */
        renderP5Picker(selectedCsv) {
            const arr = String(selectedCsv || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            this.state.selectedP5 = arr;
            const wrap = document.getElementById('pryP5Picker');
            if (!wrap) return;
            wrap.innerHTML = P5_DIMENSIONS.map(function (dim) {
                const active = arr.some(function (x) { return x.toLowerCase() === dim.toLowerCase(); });
                return '<button type="button" class="pry-p5-opt' + (active ? ' is-selected' : '') + '" data-dim="' + esc(dim) + '" onclick="PRY.toggleP5Dim(this)">' +
                    '<iconify-icon icon="' + (active ? 'solar:check-circle-bold' : 'solar:add-circle-linear') + '"></iconify-icon> ' + esc(dim) +
                '</button>';
            }).join('');
            const hidden = document.getElementById('pryFormDimensi');
            if (hidden) hidden.value = arr.join(', ');
        },

        toggleP5Dim(btn) {
            const dim = btn.getAttribute('data-dim') || '';
            const idx = this.state.selectedP5.findIndex(function (x) { return x.toLowerCase() === dim.toLowerCase(); });
            if (idx >= 0) {
                this.state.selectedP5.splice(idx, 1);
            } else {
                this.state.selectedP5.push(dim);
            }
            this.renderP5Picker(this.state.selectedP5.join(', '));
        },

        async onFormKelasChange(preselectKmpId) {
            const kId = parseInt(document.getElementById('pryFormKelas').value || '0', 10);
            const kmpSel = document.getElementById('pryFormKmp');
            const modSel = document.getElementById('pryFormModul');
            const infoBox = document.getElementById('pryFormModulInfo');
            if (infoBox) infoBox.style.display = 'none';

            const list = this.state.kmpList.filter(function (x) { return Number(x.id_kelas) === kId; });
            kmpSel.innerHTML = '<option value="0">-- Pilih Mata Pelajaran --</option>' + list.map(function (x) {
                return '<option value="' + x.id_kmp + '">' + esc(x.nama_mata_pelajaran) + '</option>';
            }).join('');
            kmpSel.disabled = list.length === 0;
            modSel.innerHTML = '<option value="0">-- Pilih Mata Pelajaran terlebih dahulu --</option>';
            modSel.disabled = true;

            if (preselectKmpId && Number(preselectKmpId) > 0) {
                kmpSel.value = String(preselectKmpId);
                await this.onFormKmpChange();
            }
        },

        async onFormKmpChange(preselectModulId) {
            const idKmp = parseInt(document.getElementById('pryFormKmp').value || '0', 10);
            const modSel = document.getElementById('pryFormModul');
            const infoBox = document.getElementById('pryFormModulInfo');
            if (infoBox) infoBox.style.display = 'none';
            this.state.formModulCatalog = [];

            if (idKmp <= 0) {
                modSel.innerHTML = '<option value="0">-- Pilih Mata Pelajaran terlebih dahulu --</option>';
                modSel.disabled = true;
                return;
            }
            modSel.innerHTML = '<option value="0">Memuat daftar Modul Ajar...</option>';
            modSel.disabled = true;
            try {
                const res = await apiPost('list_modul_by_kmp', { id_kmp: idKmp });
                const list = res.data || [];
                this.state.formModulCatalog = list;
                modSel.innerHTML = '<option value="0">-- Opsional: Tanpa Tautan Modul Ajar --</option>' + list.map(function (m) {
                    const tgl = m.tanggal ? (' • ' + formatDateIndo(m.tanggal)) : '';
                    return '<option value="' + m.id + '">Pertemuan ' + m.pertemuan_ke + ' — ' + esc((m.judul_modul || '').slice(0, 62)) + tgl + '</option>';
                }).join('');
                modSel.disabled = false;
                if (preselectModulId && Number(preselectModulId) > 0) {
                    modSel.value = String(preselectModulId);
                    this.onFormModulChange(true);
                }
            } catch (e) {
                modSel.innerHTML = '<option value="0">-- Gagal memuat Modul Ajar --</option>';
                modSel.disabled = false;
            }
        },

        onFormModulChange(isPrefill) {
            const modId = parseInt(document.getElementById('pryFormModul').value || '0', 10);
            const infoBox = document.getElementById('pryFormModulInfo');
            const m = this.state.formModulCatalog.find(function (x) { return Number(x.id) === modId; });
            if (!m) {
                if (infoBox) infoBox.style.display = 'none';
                document.getElementById('pryFormTpId').value = '';
                document.getElementById('pryFormItpId').value = '';
                document.getElementById('pryFormPertemuanKe').value = '';
                document.getElementById('pryFormJadwalId').value = '';
                return;
            }

            document.getElementById('pryFormTpId').value = m.id_tp || '';
            document.getElementById('pryFormItpId').value = m.id_itp || '';
            document.getElementById('pryFormPertemuanKe').value = m.pertemuan_ke || '';
            document.getElementById('pryFormJadwalId').value = m.id_jadwal_pelajaran || '';

            if (infoBox) {
                infoBox.style.display = 'block';
                infoBox.innerHTML =
                    '<strong><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Tersinkron ke Modul Ajar Pertemuan ' + esc(m.pertemuan_ke) + '</strong><br>' +
                    'Judul: ' + esc(m.judul_modul) + (m.tanggal ? (' • Tanggal: ' + formatDateIndo(m.tanggal)) : '') +
                    (m.kode_tp ? ('<br>TP Terkait: [' + esc(m.kode_tp) + '] ' + esc((m.uraian_tp || '').slice(0, 110))) : '');
            }

            if (!isPrefill) {
                const startEl = document.getElementById('pryFormMulai');
                const endEl = document.getElementById('pryFormSelesai');
                if (m.tanggal && startEl && !startEl.value) {
                    startEl.value = m.tanggal;
                }
                if (m.tanggal && endEl && !endEl.value) {
                    const dt = new Date(m.tanggal + 'T00:00:00');
                    dt.setDate(dt.getDate() + 14);
                    endEl.value = dt.toISOString().slice(0, 10);
                }
                if (m.profil_pelajar_pancasila && this.state.selectedP5.length === 0) {
                    this.renderP5Picker(m.profil_pelajar_pancasila);
                }
            }
        },

        async openProyekModal(proyekId) {
            const form = document.getElementById('pryForm');
            if (form) form.reset();
            document.getElementById('pryFormId').value = String(proyekId || 0);
            document.getElementById('pryFormTpId').value = '';
            document.getElementById('pryFormItpId').value = '';
            document.getElementById('pryFormPertemuanKe').value = '';
            document.getElementById('pryFormJadwalId').value = '';
            document.getElementById('pryFormModalTitle').textContent = proyekId > 0 ? 'Edit Proyek & P5 (PjBL)' : 'Buat Proyek & P5 Baru';
            document.getElementById('pryFormExistingFile').innerHTML = '';
            const infoBox = document.getElementById('pryFormModulInfo');
            if (infoBox) infoBox.style.display = 'none';

            if (proyekId > 0) {
                const item = this.state.proyekList.find(function (x) { return Number(x.id) === Number(proyekId); });
                if (!item) return;
                document.getElementById('pryFormKelas').value = String(item.id_kelas || 0);
                await this.onFormKelasChange(item.id_kelas_mata_pelajaran);
                await this.onFormKmpChange(item.id_modul_ajar || 0);
                document.getElementById('pryFormNama').value = item.nama_proyek || '';
                document.getElementById('pryFormTema').value = item.tema_proyek || '';
                document.getElementById('pryFormJenis').value = item.jenis_proyek || 'Kelompok';
                document.getElementById('pryFormStatus').value = item.status_proyek || 'Sedang Berjalan';
                document.getElementById('pryFormLuaran').value = item.target_luaran || '';
                document.getElementById('pryFormMulai').value = item.jadwal_mulai || '';
                document.getElementById('pryFormSelesai').value = item.jadwal_selesai || '';
                document.getElementById('pryFormNilaiMax').value = item.nilai_maksimal || 100;
                document.getElementById('pryFormDeskripsi').value = item.deskripsi_tujuan || '';
                document.getElementById('pryFormRubrik').value = item.pedoman_penilaian_rubrik || '';
                document.getElementById('pryFormLinkRef').value = item.link_referensi || '';
                this.renderP5Picker(item.dimensi_profil_pancasila || '');
                if (item.file_panduan) {
                    document.getElementById('pryFormExistingFile').innerHTML =
                        '<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + item.file_panduan.replace(/^\/+/, '')) + '" target="_blank"><iconify-icon icon="solar:document-text-bold"></iconify-icon> Berkas panduan saat ini</a>';
                }
            } else {
                const today = new Date().toISOString().slice(0, 10);
                const plus14 = new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10);
                document.getElementById('pryFormMulai').value = today;
                document.getElementById('pryFormSelesai').value = plus14;
                document.getElementById('pryFormNilaiMax').value = '100';
                this.renderP5Picker('Bergotong Royong, Bernalar Kritis, Kreatif');

                // Prefill kelas/mapel/modul dari filter aktif
                if (this.state.filterKelas > 0) {
                    document.getElementById('pryFormKelas').value = String(this.state.filterKelas);
                    await this.onFormKelasChange(this.state.filterKmp || 0);
                    if (this.state.filterKmp > 0 && this.state.filterModul > 0) {
                        await this.onFormKmpChange(this.state.filterModul);
                    }
                } else {
                    document.getElementById('pryFormKelas').value = '0';
                    await this.onFormKelasChange(0);
                }
            }

            if (typeof window.openIlmunaModal === 'function') {
                window.openIlmunaModal('modalProyekForm');
            }
            if (window.IlmunaCalendar && typeof window.IlmunaCalendar.bind === 'function') {
                window.IlmunaCalendar.bind('#pryFormMulai, #pryFormSelesai', false, 'date');
            }
        },

        async submitProyekForm(e) {
            e.preventDefault();
            const btn = document.getElementById('pryFormSubmitBtn');
            const orig = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = 'Menyimpan...'; }
            try {
                const fd = new FormData(document.getElementById('pryForm'));
                fd.set('dimensi_profil_pancasila', this.state.selectedP5.join(', '));
                const res = await apiPost('save_proyek', fd, true);
                if (typeof window.closeIlmunaModal === 'function') window.closeIlmunaModal('modalProyekForm');
                notify('success', res.message || 'Proyek berhasil disimpan.');
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal menyimpan proyek.');
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = orig; }
            }
        },

        async deleteProyek(id, nama) {
            const ok = await confirmAction('error', 'Hapus Proyek?', 'Proyek "' + nama + '" beserta kelompok, pengumpulan karya, dan catatan anggarannya akan dihapus permanen.', 'Ya, Hapus');
            if (!ok) return;
            try {
                const res = await apiPost('delete_proyek', { id: id });
                notify('success', res.message || 'Proyek berhasil dihapus.');
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal menghapus proyek.');
            }
        },

        /* ======================================================================
         * TAB 2: KELOMPOK & PERAN TIM (100% IDENTIK SISTEM KELOMPOK TUGAS.PHP)
         * ====================================================================== */
        async loadKelompokTab() {
            const wrap = document.getElementById('pryTeamContainer');
            if (!wrap) return;
            if (this.state.selectedProyekId <= 0) {
                wrap.innerHTML = '<div class="pry-empty"><iconify-icon icon="solar:users-group-rounded-bold-duotone"></iconify-icon><h4>Pilih Proyek Terlebih Dahulu</h4><p>Buat atau pilih proyek untuk mengatur kelompok dan peran anggota tim siswa.</p></div>';
                return;
            }
            wrap.innerHTML = '<div class="pry-empty"><iconify-icon icon="solar:refresh-bold"></iconify-icon><h4>Memuat Kelompok Tim...</h4></div>';
            try {
                const res = await apiPost('get_kelompok_state', { id_proyek: this.state.selectedProyekId });
                const d = res.data || {};
                this.state.selectedProyekMeta = d.proyek || null;
                this.state.totalSiswaKelas = Number(d.total_siswa_kelas || (d.students || []).length || 0);
                this.state.kelompokList = d.kelompok || [];
                this.state.unassignedStudents = d.unassigned_siswa || [];
                this.state.kelasStudents = d.students || [];

                const infoEl = document.getElementById('pryTeamGenInfo');
                if (infoEl && d.proyek) {
                    infoEl.innerHTML = 'Kelas: <strong>' + esc(d.proyek.nama_kelas) + '</strong> &bull; Mapel: <strong>' + esc(d.proyek.nama_mata_pelajaran) + '</strong> &bull; Total Siswa: <strong>' + this.state.totalSiswaKelas + ' Santri</strong>. Tentukan jumlah kelompok lalu klik Acak &amp; Bagi.';
                }
                this.updateJmlKelompokEstimate();
                this.renderKelompokTab();
            } catch (e) {
                wrap.innerHTML = '<div class="pry-empty"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon><h4>Gagal Memuat Kelompok</h4><p>' + esc(e.message) + '</p></div>';
            }
        },

        adjustJmlKelompok(delta) {
            const inp = document.getElementById('pryGenCountInput');
            if (!inp) return;
            let val = (parseInt(inp.value || '4', 10) || 4) + delta;
            val = Math.max(2, Math.min(15, val));
            inp.value = String(val);
            this.updateJmlKelompokEstimate();
        },

        updateJmlKelompokEstimate() {
            const inp = document.getElementById('pryGenCountInput');
            const badge = document.getElementById('pryGenEstimateBadge');
            if (!inp || !badge) return;
            const k = Math.max(2, parseInt(inp.value || '4', 10) || 4);
            const tot = this.state.totalSiswaKelas || 0;
            const est = tot > 0 ? Math.ceil(tot / k) : 0;
            badge.textContent = '±' + est + ' siswa/klp';
        },

        async executeAutoGenerateInline() {
            if (this.state.selectedProyekId <= 0) {
                notify('warning', 'Pilih proyek terlebih dahulu.');
                return;
            }
            const inp = document.getElementById('pryGenCountInput');
            const jumlah = Math.max(2, Math.min(15, parseInt((inp && inp.value) || '4', 10) || 4));
            const ok = await confirmAction(
                'warning',
                'Acak & Bagi Kelompok Otomatis?',
                'Seluruh ' + this.state.totalSiswaKelas + ' santri di kelas akan dibagi secara acak & merata ke dalam ' + jumlah + ' kelompok (beserta ketua kelompok otomatis). Susunan kelompok sebelumnya akan diganti.',
                'Ya, Acak & Bagi Sekarang'
            );
            if (!ok) return;
            try {
                const res = await apiPost('auto_generate_kelompok', {
                    id_proyek: this.state.selectedProyekId,
                    jumlah_kelompok: jumlah
                });
                notify('success', res.message || 'Kelompok otomatis berhasil dibuat.');
                await this.loadKelompokTab();
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal membagi kelompok.');
            }
        },

        renderKelompokTab() {
            const wrap = document.getElementById('pryTeamContainer');
            if (!wrap) return;
            const teams = this.state.kelompokList;
            const unassigned = this.state.unassignedStudents || [];
            const totSiswa = this.state.totalSiswaKelas || 0;

            if (!teams.length) {
                wrap.innerHTML =
                    '<div class="pry-empty">' +
                        '<iconify-icon icon="solar:users-group-two-rounded-bold-duotone"></iconify-icon>' +
                        '<h4>Belum Ada Kelompok Terbentuk</h4>' +
                        '<p>Proyek ini belum memiliki susunan kelompok. Klik <strong>"Acak &amp; Bagi Kelompok"</strong> di atas untuk membagi <strong>' + totSiswa + ' santri</strong> secara merata dan adil.</p>' +
                        (CFG.canManage ? '<button type="button" class="ilmuna-btn ilmuna-btn--navy" onclick="PRY.executeAutoGenerateInline()"><iconify-icon icon="solar:magic-stick-3-bold"></iconify-icon> Bagi Kelompok Otomatis Sekarang</button>' : '') +
                    '</div>';
                return;
            }

            let html =
                '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">' +
                    '<div style="font-size:15px;font-weight:800;color:#1D3B5E;display:flex;align-items:center;gap:8px;">' +
                        '<span>Daftar Susunan Kelompok</span>' +
                        '<span class="pry-badge pry-badge--purple">' + teams.length + ' Kelompok</span>' +
                    '</div>' +
                    '<div style="font-size:12.5px;color:#64748B;">Total Anggota Terbagi: <strong>' + (totSiswa - unassigned.length) + '</strong> dari <strong>' + totSiswa + '</strong> Siswa</div>' +
                '</div>' +
                '<div class="pry-team-grid">';

            teams.forEach(function (t) {
                const members = t.anggota || [];
                const hasKetua = Number(t.id_ketua_kelompok) > 0 && t.nama_ketua;

                let moveOpts = '<option value="">Pindah ke...</option>';
                teams.forEach(function (other) {
                    if (Number(other.id) !== Number(t.id)) {
                        moveOpts += '<option value="' + other.id + '">Kelompok ' + other.nomor_kelompok + '</option>';
                    }
                });

                let ketuaOpts = '<option value="">-- Pilih Ketua --</option>';
                members.forEach(function (a) {
                    const sel = Number(a.id_siswa) === Number(t.id_ketua_kelompok) ? ' selected' : '';
                    ketuaOpts += '<option value="' + a.id_siswa + '"' + sel + '>' + esc(a.nama_siswa) + '</option>';
                });

                const ketuaBox =
                    '<div style="background:' + (hasKetua ? '#FFFBEB' : '#FEF2F2') + ';border:1px solid ' + (hasKetua ? '#FDE68A' : '#FECACA') + ';border-radius:11px;padding:10px 12px;">' +
                        '<div style="display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:6px;">' +
                            '<span style="font-size:11px;font-weight:800;color:#92400E;display:inline-flex;align-items:center;gap:4px;"><iconify-icon icon="solar:crown-star-bold" style="color:#F59E0B;font-size:14px;"></iconify-icon> KETUA KELOMPOK</span>' +
                            (hasKetua ? '<span class="pry-badge pry-badge--amber">TERPILIH</span>' : '<span class="pry-badge pry-badge--rose">BELUM DITENTUKAN</span>') +
                        '</div>' +
                        '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">' +
                            '<div style="min-width:0;flex:1;">' +
                                (hasKetua
                                    ? '<div style="font-size:13px;font-weight:800;color:#78350F;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(t.nama_ketua) + '</div><div style="font-size:10.5px;color:#92400E;">NISN: ' + esc(t.nisn_ketua || t.nis_ketua || '-') + '</div>'
                                    : '<div style="font-size:11.5px;color:#991B1B;">Pilih salah satu anggota sebagai ketua (berhak mengunggah karya).</div>') +
                            '</div>' +
                            (CFG.canManage && members.length
                                ? '<select class="pry-select" style="width:auto;max-width:140px;height:30px;padding:0 8px;font-size:11.5px;border-color:#FCD34D;" onchange="PRY.setKetuaKelompok(' + t.id + ', this.value)">' + ketuaOpts + '</select>'
                                : '') +
                        '</div>' +
                    '</div>';

                const memHtml = members.length
                    ? members.map(function (a) {
                        const isKetua = Number(t.id_ketua_kelompok) === Number(a.id_siswa);
                        return '<div class="pry-member-row" style="' + (isKetua ? 'background:#FFFBEB;border-color:#FDE68A;' : '') + '">' +
                            '<div class="pry-member-info">' +
                                '<div class="pry-member-avatar" style="' + (isKetua ? 'background:linear-gradient(135deg,#D97706,#F59E0B);' : '') + '">' + esc(initials(a.nama_siswa)) + '</div>' +
                                '<div style="min-width:0;">' +
                                    '<div class="pry-member-name">' + esc(a.nama_siswa) + (isKetua ? ' <span class="pry-badge pry-badge--amber" style="margin-left:3px;">Ketua</span>' : '') + '</div>' +
                                    '<div class="pry-member-role">' + esc(a.peran_anggota || 'Anggota') + ' &bull; NISN ' + esc(a.nisn || a.nis || '-') + '</div>' +
                                '</div>' +
                            '</div>' +
                            (CFG.canManage
                                ? '<div style="display:flex;align-items:center;gap:4px;flex-shrink:0;">' +
                                    (!isKetua ? '<button type="button" class="pry-icon-btn" style="width:27px;height:27px;font-size:14px;color:#D97706;" onclick="PRY.setKetuaKelompok(' + t.id + ',' + a.id_siswa + ')" title="Jadikan Ketua Kelompok"><iconify-icon icon="solar:crown-star-bold"></iconify-icon></button>' : '') +
                                    (teams.length > 1 ? '<select class="pry-select" style="width:82px;height:27px;padding:0 5px;font-size:11px;" onchange="PRY.moveAnggota(' + a.id_siswa + ', this.value)" title="Pindahkan ke kelompok lain">' + moveOpts + '</select>' : '') +
                                    '<button type="button" class="pry-icon-btn pry-icon-btn--danger" style="width:27px;height:27px;font-size:14px;" onclick="PRY.removeAnggota(' + a.id + ')" title="Keluarkan"><iconify-icon icon="solar:close-circle-bold"></iconify-icon></button>' +
                                  '</div>'
                                : '') +
                        '</div>';
                    }).join('')
                    : '<div style="font-size:12px;color:#94A3B8;padding:12px;text-align:center;background:#FFFFFF;border-radius:10px;border:1px dashed #E2E8F0;">Belum ada anggota di kelompok ini.</div>';

                html +=
                    '<div class="pry-team-card">' +
                        '<div class="pry-team-head">' +
                            '<div style="min-width:0;flex:1;">' +
                                '<span class="pry-badge pry-badge--purple">#' + esc(t.nomor_kelompok) + '</span>' +
                                '<h4 class="pry-team-title" style="margin-top:5px;">' + esc(t.nama_kelompok) + '</h4>' +
                            '</div>' +
                            (CFG.canManage
                                ? '<div style="display:flex;gap:5px;">' +
                                    '<button type="button" class="pry-icon-btn" onclick="PRY.openAddMemberModal(' + t.id + ',\'' + esc(t.nama_kelompok).replace(/'/g, "\\'") + '\')" title="Tambah Anggota"><iconify-icon icon="solar:user-plus-bold"></iconify-icon></button>' +
                                    '<button type="button" class="pry-icon-btn" onclick="PRY.openRenameTeamModal(' + t.id + ',\'' + esc(t.nama_kelompok).replace(/'/g, "\\'") + '\')" title="Ubah Nama Kelompok"><iconify-icon icon="solar:pen-bold"></iconify-icon></button>' +
                                    '<button type="button" class="pry-icon-btn pry-icon-btn--danger" onclick="PRY.deleteKelompok(' + t.id + ',\'' + esc(t.nama_kelompok).replace(/'/g, "\\'") + '\')" title="Hapus Kelompok"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>' +
                                  '</div>'
                                : '') +
                        '</div>' +
                        ketuaBox +
                        '<div style="font-size:11px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:0.04em;">Anggota Kelompok (' + members.length + ')</div>' +
                        '<div class="pry-team-members">' + memHtml + '</div>' +
                    '</div>';
            });

            html += '</div>';

            // KOTAK SANTRI BELUM BERKELOMPOK (UNASSIGNED POOL — IDENTIK TUGAS.PHP)
            if (unassigned.length > 0) {
                let addOpts = '<option value="">+ Masukkan ke Kelompok...</option>';
                teams.forEach(function (k) {
                    addOpts += '<option value="' + k.id + '">Kelompok ' + k.nomor_kelompok + ' (' + esc(k.nama_kelompok) + ')</option>';
                });

                html +=
                    '<div style="margin-top:18px;background:#FFF1F2;border:1px solid #FECDD3;border-radius:16px;padding:16px;">' +
                        '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px;">' +
                            '<div style="display:flex;align-items:center;gap:8px;font-size:14.5px;font-weight:800;color:#9F1239;">' +
                                '<iconify-icon icon="solar:user-cross-bold" style="font-size:20px;color:#E11D48;"></iconify-icon>' +
                                '<span>Santri Belum Berkelompok (' + unassigned.length + ' Siswa)</span>' +
                            '</div>' +
                            '<div style="font-size:12px;color:#881337;">Pilih kelompok tujuan di setiap siswa untuk langsung memasukkan.</div>' +
                        '</div>' +
                        '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:10px;">' +
                            unassigned.map(function (sw) {
                                return '<div style="background:#FFFFFF;border:1px solid #FECDD3;border-radius:11px;padding:9px 12px;display:flex;align-items:center;justify-content:space-between;gap:8px;">' +
                                    '<div class="pry-member-info">' +
                                        '<div class="pry-member-avatar" style="background:#FFE4E6;color:#BE123C;">' + esc(initials(sw.nama_siswa)) + '</div>' +
                                        '<div style="min-width:0;">' +
                                            '<div class="pry-member-name" style="color:#881337;">' + esc(sw.nama_siswa) + '</div>' +
                                            '<div style="font-size:10.5px;color:#9F1239;">NISN: ' + esc(sw.nisn || sw.nis || '-') + '</div>' +
                                        '</div>' +
                                    '</div>' +
                                    (CFG.canManage ? '<select class="pry-select" style="width:135px;height:30px;padding:0 6px;font-size:11px;border-color:#FDA4AF;color:#881337;" onchange="PRY.quickAddUnassigned(' + sw.id + ', this.value)">' + addOpts + '</select>' : '') +
                                '</div>';
                            }).join('') +
                        '</div>' +
                    '</div>';
            }

            wrap.innerHTML = html;
        },

        async setKetuaKelompok(idKelompok, idKetua) {
            if (!idKetua) return;
            try {
                const res = await apiPost('set_ketua_kelompok', { id_kelompok: idKelompok, id_ketua: idKetua });
                notify('success', res.message || 'Ketua kelompok diperbarui.');
                await this.loadKelompokTab();
            } catch (err) {
                notify('error', err.message || 'Gagal menetapkan ketua kelompok.');
            }
        },

        async moveAnggota(idSiswa, idTujuan) {
            if (!idTujuan) return;
            try {
                const res = await apiPost('move_anggota_kelompok', {
                    id_proyek: this.state.selectedProyekId,
                    id_siswa: idSiswa,
                    id_kelompok_tujuan: idTujuan
                });
                notify('success', res.message || 'Siswa dipindahkan.');
                await this.loadKelompokTab();
            } catch (err) {
                notify('error', err.message || 'Gagal memindahkan siswa.');
            }
        },

        async quickAddUnassigned(idSiswa, idKelompok) {
            if (!idKelompok) return;
            try {
                const res = await apiPost('add_anggota', {
                    id_proyek_kelompok: idKelompok,
                    id_siswa: idSiswa,
                    peran_anggota: 'Anggota'
                });
                notify('success', res.message || 'Siswa dimasukkan ke kelompok.');
                await this.loadKelompokTab();
            } catch (err) {
                notify('error', err.message || 'Gagal memasukkan siswa.');
            }
        },

        openRenameTeamModal(idKelompok, currentName) {
            document.getElementById('renameTeamId').value = String(idKelompok);
            document.getElementById('renameTeamInput').value = currentName || '';
            if (typeof window.openIlmunaModal === 'function') window.openIlmunaModal('modalRenameTeam');
        },

        async submitRenameTeam(e) {
            e.preventDefault();
            try {
                const res = await apiPost('rename_kelompok', {
                    id_kelompok: document.getElementById('renameTeamId').value,
                    nama_kelompok: document.getElementById('renameTeamInput').value
                });
                if (typeof window.closeIlmunaModal === 'function') window.closeIlmunaModal('modalRenameTeam');
                notify('success', res.message || 'Nama kelompok diperbarui.');
                await this.loadKelompokTab();
            } catch (err) {
                notify('error', err.message || 'Gagal mengubah nama kelompok.');
            }
        },

        async resetSemuaKelompok() {
            if (this.state.selectedProyekId <= 0) {
                notify('warning', 'Pilih proyek terlebih dahulu.');
                return;
            }
            const ok = await confirmAction('error', 'Reset Seluruh Kelompok?', 'Semua kelompok dan daftar anggotanya pada proyek ini akan dihapus dan dikembalikan menjadi belum berkelompok.', 'Ya, Reset Semua');
            if (!ok) return;
            try {
                const res = await apiPost('reset_semua_kelompok', { id_proyek: this.state.selectedProyekId });
                notify('success', res.message || 'Seluruh kelompok berhasil di-reset.');
                await this.loadKelompokTab();
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal me-reset kelompok.');
            }
        },

        openAddTeamModal() {
            if (this.state.selectedProyekId <= 0) {
                notify('warning', 'Pilih proyek terlebih dahulu.');
                return;
            }
            document.getElementById('teamForm').reset();
            document.getElementById('teamNomor').value = String(this.state.kelompokList.length + 1);
            document.getElementById('teamNama').value = 'Tim ' + (this.state.kelompokList.length + 1);
            const ketuaSel = document.getElementById('teamKetua');
            ketuaSel.innerHTML = '<option value="0">-- Opsional: Pilih Ketua Tim --</option>' + this.state.kelasStudents.map(function (s) {
                return '<option value="' + s.id + '">' + esc(s.nama_siswa) + ' (' + esc(s.nis || '-') + ')</option>';
            }).join('');
            if (typeof window.openIlmunaModal === 'function') window.openIlmunaModal('modalTeamForm');
        },

        async submitTeamForm(e) {
            e.preventDefault();
            try {
                const res = await apiPost('save_kelompok', {
                    id_proyek: this.state.selectedProyekId,
                    nomor_kelompok: document.getElementById('teamNomor').value,
                    nama_kelompok: document.getElementById('teamNama').value,
                    id_ketua_kelompok: document.getElementById('teamKetua').value
                });
                if (typeof window.closeIlmunaModal === 'function') window.closeIlmunaModal('modalTeamForm');
                notify('success', res.message || 'Kelompok berhasil ditambahkan.');
                await this.loadKelompokTab();
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal menambah kelompok.');
            }
        },

        openAddMemberModal(kelompokId, teamName) {
            document.getElementById('memberKelompokId').value = String(kelompokId);
            document.getElementById('memberModalSubtitle').textContent = 'Kelompok: ' + teamName;
            const usedIds = {};
            this.state.kelompokList.forEach(function (t) {
                (t.anggota || []).forEach(function (a) { usedIds[Number(a.id_siswa)] = true; });
            });
            const sel = document.getElementById('memberSiswaId');
            const avail = this.state.kelasStudents.filter(function (s) { return !usedIds[Number(s.id)]; });
            sel.innerHTML = avail.length
                ? avail.map(function (s) { return '<option value="' + s.id + '">' + esc(s.nama_siswa) + ' (' + esc(s.nis || '-') + ')</option>'; }).join('')
                : '<option value="0">-- Semua siswa sudah masuk kelompok --</option>';

            const roleSel = document.getElementById('memberPeran');
            roleSel.innerHTML = ROLE_PRESETS.map(function (r) { return '<option value="' + esc(r) + '">' + esc(r) + '</option>'; }).join('');
            if (typeof window.openIlmunaModal === 'function') window.openIlmunaModal('modalMemberForm');
        },

        async submitMemberForm(e) {
            e.preventDefault();
            const sid = parseInt(document.getElementById('memberSiswaId').value || '0', 10);
            if (sid <= 0) {
                notify('warning', 'Tidak ada siswa tersedia untuk ditambahkan.');
                return;
            }
            try {
                const res = await apiPost('add_anggota', {
                    id_proyek_kelompok: document.getElementById('memberKelompokId').value,
                    id_siswa: sid,
                    peran_anggota: document.getElementById('memberPeran').value
                });
                if (typeof window.closeIlmunaModal === 'function') window.closeIlmunaModal('modalMemberForm');
                notify('success', res.message || 'Anggota berhasil ditambahkan.');
                await this.loadKelompokTab();
            } catch (err) {
                notify('error', err.message || 'Gagal menambah anggota.');
            }
        },

        async removeAnggota(anggotaId) {
            try {
                const res = await apiPost('remove_anggota', { id: anggotaId });
                notify('success', res.message || 'Anggota dikeluarkan.');
                await this.loadKelompokTab();
            } catch (err) {
                notify('error', err.message || 'Gagal mengeluarkan anggota.');
            }
        },

        async deleteKelompok(id, nama) {
            const ok = await confirmAction('error', 'Hapus Kelompok?', 'Kelompok "' + nama + '" beserta daftar anggotanya akan dihapus.', 'Hapus');
            if (!ok) return;
            try {
                const res = await apiPost('delete_kelompok', { id: id });
                notify('success', res.message || 'Kelompok dihapus.');
                await this.loadKelompokTab();
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal menghapus kelompok.');
            }
        },

        openAutoGroupModal() {
            if (this.state.selectedProyekId <= 0) {
                notify('warning', 'Pilih proyek terlebih dahulu.');
                return;
            }
            document.getElementById('autoGroupCount').value = '4';
            if (typeof window.openIlmunaModal === 'function') window.openIlmunaModal('modalAutoGroup');
        },

        async submitAutoGroup(e) {
            e.preventDefault();
            const jumlah = parseInt(document.getElementById('autoGroupCount').value || '4', 10);
            const ok = await confirmAction('warning', 'Bagi Kelompok Otomatis?', 'Siswa di kelas akan dibagi acak secara merata ke dalam ' + jumlah + ' kelompok. Kelompok lama pada proyek ini akan diperbarui.', 'Ya, Bagi Otomatis');
            if (!ok) return;
            try {
                const res = await apiPost('auto_generate_kelompok', {
                    id_proyek: this.state.selectedProyekId,
                    jumlah_kelompok: jumlah
                });
                if (typeof window.closeIlmunaModal === 'function') window.closeIlmunaModal('modalAutoGroup');
                notify('success', res.message || 'Kelompok otomatis berhasil dibuat.');
                await this.loadKelompokTab();
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal membagi kelompok.');
            }
        },

        /* ======================================================================
         * TAB 3: HASIL KARYA & PENILAIAN P5
         * ====================================================================== */
        async loadSubmissionsTab() {
            const wrap = document.getElementById('prySubmissionsContainer');
            if (!wrap) return;
            if (this.state.selectedProyekId <= 0) {
                wrap.innerHTML = '<div class="pry-empty"><iconify-icon icon="solar:medal-star-bold-duotone"></iconify-icon><h4>Pilih Proyek Terlebih Dahulu</h4><p>Pilih proyek untuk memantau pengumpulan karya siswa dan memberikan nilai serta predikat P5.</p></div>';
                return;
            }
            wrap.innerHTML = '<div class="pry-empty"><iconify-icon icon="solar:refresh-bold"></iconify-icon><h4>Memuat Hasil Karya...</h4></div>';
            try {
                const res = await apiPost('list_submissions', { id_proyek: this.state.selectedProyekId });
                const d = res.data || {};
                this.state.submissionList = d.items || [];
                this.state.kelompokList = d.kelompok || [];
                this.state.kelasStudents = d.students || [];
                this.renderSubmissionsTab();
            } catch (e) {
                wrap.innerHTML = '<div class="pry-empty"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon><h4>Gagal Memuat Hasil Karya</h4><p>' + esc(e.message) + '</p></div>';
            }
        },

        renderSubmissionsTab() {
            const wrap = document.getElementById('prySubmissionsContainer');
            if (!wrap) return;
            const rows = this.state.submissionList;
            if (!rows.length) {
                wrap.innerHTML =
                    '<div class="pry-empty">' +
                        '<iconify-icon icon="solar:folder-with-files-bold-duotone"></iconify-icon>' +
                        '<h4>Belum Ada Pengumpulan Karya</h4>' +
                        '<p>Siswa belum mengunggah karya untuk proyek ini. Anda juga dapat mencatat pengumpulan fisik/tautan secara langsung lewat tombol "Catat Karya Siswa".</p>' +
                    '</div>';
                return;
            }

            wrap.innerHTML =
                '<div id="pryBulkBar" style="display:none;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:12px;padding:10px 14px;margin-bottom:12px;">' +
                    '<div style="font-size:12.5px;font-weight:700;color:#1E3A8A;"><span id="pryBulkCount">0</span> Pengumpulan Terpilih</div>' +
                    '<div style="display:flex;gap:8px;flex-wrap:wrap;">' +
                        '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="PRY.bulkGradeQuick(100, \'Tuntas\')"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Tandai Tuntas (100)</button>' +
                        '<button type="button" class="ilmuna-btn ilmuna-btn--amber ilmuna-btn--sm" onclick="PRY.bulkGradeQuick(0, \'Perlu Revisi\')"><iconify-icon icon="solar:refresh-circle-bold"></iconify-icon> Minta Revisi</button>' +
                    '</div>' +
                '</div>' +
                '<div class="pry-table-wrap"><table class="pry-table">' +
                    '<thead><tr>' +
                        '<th style="width:36px;"><input type="checkbox" id="pryChkAllSub" onchange="PRY.toggleSelectAllSubs(this)"></th>' +
                        '<th>Siswa / Kelompok</th>' +
                        '<th>Judul &amp; Deskripsi Karya</th>' +
                        '<th>Tautan &amp; Berkas</th>' +
                        '<th>Status</th>' +
                        '<th>Nilai &amp; Predikat P5</th>' +
                        '<th style="text-align:right;">Aksi</th>' +
                    '</tr></thead><tbody>' +
                    rows.map(function (r) {
                        const links = [];
                        if (r.link_produk) links.push('<a class="pry-link-chip" href="' + esc(r.link_produk) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:link-bold"></iconify-icon> Produk</a>');
                        if (r.link_video_presentasi) links.push('<a class="pry-link-chip" href="' + esc(r.link_video_presentasi) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:videocamera-bold"></iconify-icon> Video</a>');
                        if (r.file_laporan) links.push('<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + r.file_laporan.replace(/^\/+/, '')) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:document-text-bold"></iconify-icon> ' + esc((r.file_nama_asli || 'Laporan').slice(0, 22)) + '</a>');
                        if (r.file_dokumentasi) links.push('<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + r.file_dokumentasi.replace(/^\/+/, '')) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:gallery-bold"></iconify-icon> Dok</a>');

                        const lateBadge = Number(r.is_terlambat) === 1 ? ' <span class="pry-badge pry-badge--rose">Terlambat</span>' : '';
                        const revBadge = Number(r.revisi_ke) > 1 ? ' <span class="pry-badge pry-badge--amber">Rev #' + r.revisi_ke + '</span>' : '';

                        const dispScore = (r.rata_nilai_anggota !== null && r.rata_nilai_anggota !== undefined && r.rata_nilai_anggota !== '') ? r.rata_nilai_anggota : r.nilai_proyek;
                        const grpNote = (r.nama_kelompok && r.nilai_kelompok_saved !== null && r.nilai_kelompok_saved !== undefined)
                            ? '<div style="font-size:11px;color:#1D4ED8;font-weight:700;">Kelompok: ' + esc(r.nilai_kelompok_saved) + ' &bull; ' + (r.jumlah_anggota_dinilai || 0) + ' siswa dinilai</div>'
                            : '';
                        const scoreHtml = (dispScore !== null && dispScore !== '')
                            ? '<div style="font-size:15px;font-weight:800;color:#0F172A;margin-bottom:3px;">' + esc(dispScore) + (r.predikat_huruf_saved ? ' <span class="pry-badge pry-badge--navy">' + esc(r.predikat_huruf_saved) + '</span>' : '') + '</div>' + grpNote + predikatBadge(r.predikat_proyek)
                            : predikatBadge('');

                        return '<tr>' +
                            '<td><input type="checkbox" class="pry-chk-sub" value="' + r.id + '" onchange="PRY.updateBulkBar()"></td>' +
                            '<td><strong>' + esc(r.nama_siswa) + '</strong>' + (r.nama_kelompok ? ' <div><span class="pry-badge pry-badge--navy" style="margin-top:3px;">' + esc(r.nama_kelompok) + '</span></div>' : '') + '<div style="font-size:11px;color:#64748B;margin-top:2px;">NIS: ' + esc(r.nis || '-') + '</div></td>' +
                            '<td><strong>' + esc(r.judul_laporan_produk) + '</strong><div style="font-size:11.5px;color:#475569;margin-top:3px;max-width:260px;">' + esc((r.deskripsi_produk || '').slice(0, 130)) + '</div>' + (r.catatan_siswa ? '<div style="font-size:11px;color:#0F766E;margin-top:3px;font-style:italic;">"' + esc(r.catatan_siswa.slice(0, 90)) + '"</div>' : '') + '</td>' +
                            '<td>' + (links.length ? links.join('') : '<span style="color:#94A3B8;font-size:11.5px;">Tanpa lampiran</span>') + '<div style="font-size:10.5px;color:#64748B;margin-top:3px;">' + esc(r.tipe_pengumpulan || 'File Upload') + (r.file_ukuran_kb ? ' • ' + r.file_ukuran_kb + ' KB' : '') + '</div></td>' +
                            '<td>' + statusBadge(r.status_pengumpulan) + lateBadge + revBadge + '<div style="font-size:10.5px;color:#64748B;margin-top:4px;">' + formatDateIndo(r.waktu_pengumpulan) + '</div></td>' +
                            '<td>' + scoreHtml + (r.catatan_evaluasi_guru ? '<div style="font-size:11px;color:#475569;margin-top:4px;max-width:200px;">"' + esc(r.catatan_evaluasi_guru) + '"</div>' : '') + '</td>' +
                            '<td style="text-align:right;white-space:nowrap;">' +
                                (CFG.canManage ? '<button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" onclick="PRY.openGradeModal(' + r.id + ')"><iconify-icon icon="solar:star-bold"></iconify-icon> Nilai</button>' : '-') +
                            '</td>' +
                        '</tr>';
                    }).join('') +
                '</tbody></table></div>';
        },

        toggleSelectAllSubs(master) {
            document.querySelectorAll('.pry-chk-sub').forEach(function (cb) { cb.checked = master.checked; });
            this.updateBulkBar();
        },

        updateBulkBar() {
            const checked = document.querySelectorAll('.pry-chk-sub:checked');
            const bar = document.getElementById('pryBulkBar');
            const cnt = document.getElementById('pryBulkCount');
            if (cnt) cnt.textContent = String(checked.length);
            if (bar) bar.style.display = checked.length > 0 ? 'flex' : 'none';
        },

        async bulkGradeQuick(nilai, statusPenilaian) {
            const ids = Array.from(document.querySelectorAll('.pry-chk-sub:checked')).map(function (c) { return parseInt(c.value, 10); });
            if (!ids.length) return;
            const ok = await confirmAction('warning', statusPenilaian === 'Perlu Revisi' ? 'Minta Revisi Massal?' : 'Tandai Tuntas Massal?', 'Terapkan status "' + statusPenilaian + '" untuk ' + ids.length + ' pengumpulan proyek terpilih (termasuk seluruh anggota kelompok)?', 'Ya, Terapkan');
            if (!ok) return;
            try {
                const res = await apiPost('bulk_grade_submissions', {
                    ids: JSON.stringify(ids),
                    nilai: nilai,
                    status_penilaian: statusPenilaian,
                    catatan: statusPenilaian === 'Perlu Revisi' ? 'Perlu perbaikan berkas sesuai instruksi proyek.' : 'Tuntas dan memenuhi kriteria penilaian proyek.'
                });
                notify('success', res.message || 'Penilaian massal berhasil.');
                await this.loadSubmissionsTab();
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal memproses penilaian massal.');
            }
        },

        openManualSubmissionModal() {
            if (this.state.selectedProyekId <= 0) {
                notify('warning', 'Pilih proyek terlebih dahulu.');
                return;
            }
            document.getElementById('subManualForm').reset();
            const sSel = document.getElementById('subManualSiswa');
            sSel.innerHTML = this.state.kelasStudents.map(function (s) {
                return '<option value="' + s.id + '">' + esc(s.nama_siswa) + ' (' + esc(s.nis || '-') + ')</option>';
            }).join('');
            const kSel = document.getElementById('subManualKelompok');
            kSel.innerHTML = '<option value="0">-- Individu / Tanpa Kelompok --</option>' + this.state.kelompokList.map(function (t) {
                return '<option value="' + t.id + '">Kelompok ' + t.nomor_kelompok + ' — ' + esc(t.nama_kelompok) + '</option>';
            }).join('');
            if (typeof window.openIlmunaModal === 'function') window.openIlmunaModal('modalManualSubmission');
        },

        async submitManualSubmission(e) {
            e.preventDefault();
            try {
                const fd = new FormData(document.getElementById('subManualForm'));
                fd.append('id_proyek', String(this.state.selectedProyekId));
                const res = await apiPost('save_manual_submission', fd, true);
                if (typeof window.closeIlmunaModal === 'function') window.closeIlmunaModal('modalManualSubmission');
                notify('success', res.message || 'Karya siswa berhasil dicatat.');
                await this.loadSubmissionsTab();
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal mencatat karya siswa.');
            }
        },

        async openGradeModal(subId) {
            try {
                const res = await apiPost('get_submission_detail', { id_pengumpulan: subId });
                const s = res.data;
                if (!s) return;

                document.getElementById('gradeSubId').value = String(s.id);
                const maxScore = parseFloat(s.nilai_maksimal || 100) || 100;
                document.getElementById('gradeMaxScore').value = String(maxScore);
                document.getElementById('gradeModalSubtitle').textContent = (s.nama_siswa || 'Siswa') + (s.nama_kelompok ? ' • ' + s.nama_kelompok : '') + ' — ' + (s.judul_laporan_produk || '');

                const subLinks = [];
                if (s.link_produk) subLinks.push('<a class="pry-link-chip" href="' + esc(s.link_produk) + '" target="_blank"><iconify-icon icon="solar:link-bold"></iconify-icon> Produk</a>');
                if (s.link_video_presentasi) subLinks.push('<a class="pry-link-chip" href="' + esc(s.link_video_presentasi) + '" target="_blank"><iconify-icon icon="solar:videocamera-bold"></iconify-icon> Video</a>');
                if (s.file_laporan) subLinks.push('<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + s.file_laporan.replace(/^\/+/, '')) + '" target="_blank"><iconify-icon icon="solar:document-text-bold"></iconify-icon> ' + esc(s.file_nama_asli || 'Laporan') + '</a>');
                if (s.file_dokumentasi) subLinks.push('<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + s.file_dokumentasi.replace(/^\/+/, '')) + '" target="_blank"><iconify-icon icon="solar:gallery-bold"></iconify-icon> Dokumentasi</a>');

                document.getElementById('gradeSubSummaryBox').innerHTML =
                    '<strong>' + esc(s.judul_laporan_produk || '-') + '</strong>' +
                    '<div style="margin-top:3px;">' + esc(s.deskripsi_produk || '') + '</div>' +
                    (s.catatan_siswa ? '<div style="margin-top:4px;font-style:italic;">Catatan Siswa: "' + esc(s.catatan_siswa) + '"</div>' : '') +
                    (subLinks.length ? '<div style="margin-top:6px;">' + subLinks.join('') + '</div>' : '');

                const isGroup = (s.jenis_proyek === 'Kelompok' && Number(s.id_proyek_kelompok) > 0 && Array.isArray(s.anggota_kelompok) && s.anggota_kelompok.length > 0);
                document.getElementById('gradeIsGroup').value = isGroup ? '1' : '0';

                const boxGrp = document.getElementById('gradeBoxGroup');
                const boxInd = document.getElementById('gradeBoxIndividu');
                const inpKel = document.getElementById('gradeNilaiKelompok');
                const inpInd = document.getElementById('gradeNilai');

                if (isGroup) {
                    boxGrp.style.display = 'flex';
                    boxInd.style.display = 'none';
                    inpInd.removeAttribute('required');
                    inpKel.setAttribute('required', 'required');
                    inpKel.max = String(maxScore);

                    const members = s.anggota_kelompok || [];
                    document.getElementById('gradeGroupCountBadge').textContent = members.length + ' Siswa';
                    const grpVal = (s.nilai_kelompok !== null && s.nilai_kelompok !== undefined) ? parseFloat(s.nilai_kelompok) : ((s.nilai !== null && s.nilai !== undefined) ? parseFloat(s.nilai) : 85);
                    inpKel.value = String(grpVal);

                    const listEl = document.getElementById('gradeGroupMembersList');
                    listEl.innerHTML = members.map(function (m) {
                        const indVal = (m.nilai_individu !== null && m.nilai_individu !== undefined) ? parseFloat(m.nilai_individu) : grpVal;
                        const finalVal = ((grpVal + indVal) / 2);
                        const isKetua = Number(s.id_ketua_kelompok) === Number(m.id);
                        return '<div class="pry-member-grade-row" data-siswa-id="' + m.id + '" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:11px;padding:10px 12px;display:flex;flex-direction:column;gap:8px;">' +
                            '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">' +
                                '<div style="display:flex;align-items:center;gap:8px;min-width:0;">' +
                                    '<div class="pry-member-avatar">' + esc(initials(m.nama_lengkap)) + '</div>' +
                                    '<div style="min-width:0;">' +
                                        '<div style="font-size:12.5px;font-weight:800;color:#0F172A;">' + esc(m.nama_lengkap) + (isKetua ? ' <span class="pry-badge pry-badge--amber">Ketua</span>' : '') + '</div>' +
                                        '<div style="font-size:11px;color:#64748B;">' + esc(m.peran_anggota || 'Anggota') + ' &bull; NIS ' + esc(m.nis || '-') + '</div>' +
                                    '</div>' +
                                '</div>' +
                                '<span class="pry-badge pry-badge--navy" id="pryMemPred_' + m.id + '">' + suggestHuruf(finalVal) + '</span>' +
                            '</div>' +
                            '<div style="display:grid;grid-template-columns:1fr 1.2fr;gap:10px;align-items:center;">' +
                                '<div>' +
                                    '<div style="font-size:10.5px;font-weight:700;color:#64748B;margin-bottom:3px;">Nilai Individu (0-' + maxScore + '):</div>' +
                                    '<input type="number" class="pry-input pry-member-score-inp" data-siswa-id="' + m.id + '" min="0" max="' + maxScore + '" step="0.5" value="' + indVal + '" oninput="PRY.recalcGroupScores()" style="font-weight:800;">' +
                                '</div>' +
                                '<div style="background:#FFFFFF;border:1px dashed #CBD5E1;border-radius:9px;padding:7px 10px;text-align:center;">' +
                                    '<div style="font-size:10px;color:#64748B;">Nilai Akhir &amp; P5:</div>' +
                                    '<div style="font-size:15px;font-weight:800;color:#0D7A87;" id="pryMemFinal_' + m.id + '">' + finalVal.toFixed(2) + '</div>' +
                                    '<div style="font-size:10px;font-weight:700;color:#475569;" id="pryMemP5_' + m.id + '">' + esc(suggestPredikat(finalVal)) + '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>';
                    }).join('');
                    this.recalcGroupScores();
                } else {
                    boxGrp.style.display = 'none';
                    boxInd.style.display = 'grid';
                    inpKel.removeAttribute('required');
                    inpInd.setAttribute('required', 'required');
                    inpInd.max = String(maxScore);
                    inpInd.value = (s.nilai !== null && s.nilai !== undefined) ? s.nilai : '';
                    document.getElementById('gradeRemedial').value = (s.nilai_remedial !== null && s.nilai_remedial !== undefined) ? s.nilai_remedial : '';
                    document.getElementById('gradePredikat').value = s.predikat_p5 || (s.nilai ? suggestPredikat(s.nilai) : 'Berkembang Sesuai Harapan (BSH)');
                }

                document.getElementById('gradeAspekItp').value = s.aspek_itp_ketercapaian || 'Tercapai';
                document.getElementById('gradeStatusPenilaian').value = s.status_penilaian || 'Dinilai';
                document.getElementById('gradeStatus').value = s.status_pengumpulan === 'Diserahkan' ? 'Disetujui' : (s.status_pengumpulan || 'Disetujui');
                document.getElementById('gradeCatatan').value = s.catatan_guru || '';

                if (typeof window.openIlmunaModal === 'function') window.openIlmunaModal('modalGradeSubmission');
            } catch (err) {
                notify('error', err.message || 'Gagal membuka detail penilaian.');
            }
        },

        recalcGroupScores() {
            const maxScore = parseFloat(document.getElementById('gradeMaxScore').value || '100') || 100;
            const grpVal = Math.min(maxScore, Math.max(0, parseFloat(document.getElementById('gradeNilaiKelompok').value || '0') || 0));
            document.querySelectorAll('.pry-member-score-inp').forEach(function (inp) {
                const sid = inp.getAttribute('data-siswa-id');
                const indVal = Math.min(maxScore, Math.max(0, parseFloat(inp.value || '0') || 0));
                const fin = Math.min(maxScore, (grpVal + indVal) / 2);
                const finEl = document.getElementById('pryMemFinal_' + sid);
                const p5El = document.getElementById('pryMemP5_' + sid);
                const predEl = document.getElementById('pryMemPred_' + sid);
                if (finEl) finEl.textContent = fin.toFixed(2);
                if (p5El) p5El.textContent = suggestPredikat(fin);
                if (predEl) predEl.textContent = suggestHuruf(fin);
            });
        },

        async submitGradeForm(e) {
            e.preventDefault();
            const btn = document.getElementById('gradeSubmitBtn');
            const orig = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = 'Menyimpan...'; }
            try {
                const isGroup = document.getElementById('gradeIsGroup').value === '1';
                const payload = {
                    id: document.getElementById('gradeSubId').value,
                    aspek_itp: document.getElementById('gradeAspekItp').value,
                    status_penilaian: document.getElementById('gradeStatusPenilaian').value,
                    status_pengumpulan: document.getElementById('gradeStatus').value,
                    catatan_evaluasi_guru: document.getElementById('gradeCatatan').value
                };

                if (isGroup) {
                    payload.nilai_kelompok = document.getElementById('gradeNilaiKelompok').value;
                    const arr = [];
                    document.querySelectorAll('.pry-member-score-inp').forEach(function (inp) {
                        arr.push({
                            id_siswa: parseInt(inp.getAttribute('data-siswa-id'), 10),
                            nilai_individu: parseFloat(inp.value || '0') || 0
                        });
                    });
                    payload.anggota_scores = JSON.stringify(arr);
                } else {
                    payload.nilai_proyek = document.getElementById('gradeNilai').value;
                    payload.nilai_remedial = document.getElementById('gradeRemedial').value;
                    payload.predikat_proyek = document.getElementById('gradePredikat').value;
                }

                const res = await apiPost('grade_submission', payload);
                if (typeof window.closeIlmunaModal === 'function') window.closeIlmunaModal('modalGradeSubmission');
                notify('success', res.message || 'Nilai dan predikat P5 tersimpan.');
                await this.loadSubmissionsTab();
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal menyimpan penilaian.');
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = orig; }
            }
        },

        /* ======================================================================
         * TAB 4: ANGGARAN & BIAYA PROYEK
         * ====================================================================== */
        async loadBiayaTab() {
            const wrap = document.getElementById('pryBudgetContainer');
            if (!wrap) return;
            if (this.state.selectedProyekId <= 0) {
                wrap.innerHTML = '<div class="pry-empty"><iconify-icon icon="solar:wallet-money-bold-duotone"></iconify-icon><h4>Pilih Proyek Terlebih Dahulu</h4><p>Pilih proyek untuk memantau rincian pengeluaran dan verifikasi transparansi biaya.</p></div>';
                return;
            }
            wrap.innerHTML = '<div class="pry-empty"><iconify-icon icon="solar:refresh-bold"></iconify-icon><h4>Memuat Anggaran Proyek...</h4></div>';
            try {
                const res = await apiPost('list_biaya', { id_proyek: this.state.selectedProyekId });
                const d = res.data || {};
                this.state.biayaList = d.items || [];
                this.state.kelompokList = d.kelompok || [];
                this.state.kelasStudents = d.students || [];
                this.renderBiayaTab(d.summary || {});
            } catch (e) {
                wrap.innerHTML = '<div class="pry-empty"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon><h4>Gagal Memuat Anggaran</h4><p>' + esc(e.message) + '</p></div>';
            }
        },

        renderBiayaTab(sum) {
            const wrap = document.getElementById('pryBudgetContainer');
            if (!wrap) return;
            const rows = this.state.biayaList;
            const strip =
                '<div class="pry-budget-strip">' +
                    '<div class="pry-budget-box"><div class="pry-budget-box__lbl">Total Terverifikasi</div><div class="pry-budget-box__val" style="color:#047857;">' + formatRupiah(sum.verified || 0) + '</div></div>' +
                    '<div class="pry-budget-box"><div class="pry-budget-box__lbl">Menunggu Verifikasi</div><div class="pry-budget-box__val" style="color:#B45309;">' + formatRupiah(sum.pending || 0) + '</div></div>' +
                    '<div class="pry-budget-box"><div class="pry-budget-box__lbl">Total Item Tercatat</div><div class="pry-budget-box__val">' + (rows.length || 0) + ' Transaksi</div></div>' +
                '</div>';

            if (!rows.length) {
                wrap.innerHTML = strip +
                    '<div class="pry-empty">' +
                        '<iconify-icon icon="solar:bill-list-bold-duotone"></iconify-icon>' +
                        '<h4>Belum Ada Catatan Anggaran</h4>' +
                        '<p>Catat pengeluaran bahan baku, percetakan, atau kebutuhan proyek untuk transparansi biaya kelompok.</p>' +
                    '</div>';
                return;
            }

            wrap.innerHTML = strip +
                '<div class="pry-table-wrap"><table class="pry-table">' +
                    '<thead><tr>' +
                        '<th>Tanggal</th>' +
                        '<th>Item &amp; Kategori</th>' +
                        '<th>Pencatat / Tim</th>' +
                        '<th>Qty &times; Harga</th>' +
                        '<th>Total &amp; Sumber</th>' +
                        '<th>Verifikasi</th>' +
                        '<th style="text-align:right;">Aksi</th>' +
                    '</tr></thead><tbody>' +
                    rows.map(function (b) {
                        return '<tr>' +
                            '<td style="white-space:nowrap;">' + formatDateIndo(b.tanggal_pengeluaran) + '</td>' +
                            '<td><strong>' + esc(b.nama_item_biaya) + '</strong><div style="margin-top:3px;"><span class="pry-badge pry-badge--slate">' + esc(b.kategori_biaya) + '</span></div></td>' +
                            '<td>' + esc(b.nama_siswa || '-') + (b.nama_kelompok ? '<div style="font-size:11px;color:#64748B;">' + esc(b.nama_kelompok) + '</div>' : '') + '</td>' +
                            '<td style="white-space:nowrap;">' + Number(b.kuantitas) + ' ' + esc(b.satuan || 'pcs') + ' &times; ' + formatRupiah(b.harga_satuan) + '</td>' +
                            '<td><strong style="color:#1D3B5E;">' + formatRupiah(b.total_biaya) + '</strong><div style="font-size:11px;color:#64748B;">' + esc(b.sumber_dana) + '</div></td>' +
                            '<td>' + statusBadge(b.status_verifikasi) + '</td>' +
                            '<td style="text-align:right;white-space:nowrap;">' +
                                (CFG.canManage ? (
                                    '<button type="button" class="pry-icon-btn" onclick="PRY.verifyBiaya(' + b.id + ',\'Diverifikasi Guru\')" title="Verifikasi / Setujui"><iconify-icon icon="solar:check-circle-bold"></iconify-icon></button> ' +
                                    '<button type="button" class="pry-icon-btn pry-icon-btn--danger" onclick="PRY.verifyBiaya(' + b.id + ',\'Ditolak\')" title="Tolak"><iconify-icon icon="solar:close-circle-bold"></iconify-icon></button> ' +
                                    '<button type="button" class="pry-icon-btn pry-icon-btn--danger" onclick="PRY.deleteBiaya(' + b.id + ')" title="Hapus"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>'
                                ) : '-') +
                            '</td>' +
                        '</tr>';
                    }).join('') +
                '</tbody></table></div>';
        },

        openAddBiayaModal() {
            if (this.state.selectedProyekId <= 0) {
                notify('warning', 'Pilih proyek terlebih dahulu.');
                return;
            }
            document.getElementById('biayaForm').reset();
            document.getElementById('biayaTanggal').value = new Date().toISOString().slice(0, 10);
            document.getElementById('biayaQty').value = '1';
            document.getElementById('biayaSatuan').value = 'paket';
            document.getElementById('biayaHarga').value = '0';
            this.recalcBiayaPreview();

            const sSel = document.getElementById('biayaSiswa');
            sSel.innerHTML = this.state.kelasStudents.map(function (s) {
                return '<option value="' + s.id + '">' + esc(s.nama_siswa) + ' (' + esc(s.nis || '-') + ')</option>';
            }).join('');
            const kSel = document.getElementById('biayaKelompok');
            kSel.innerHTML = '<option value="0">-- Umum / Semua Tim --</option>' + this.state.kelompokList.map(function (t) {
                return '<option value="' + t.id + '">Kelompok ' + t.nomor_kelompok + ' — ' + esc(t.nama_kelompok) + '</option>';
            }).join('');

            if (typeof window.openIlmunaModal === 'function') window.openIlmunaModal('modalBiayaForm');
            if (window.IlmunaCalendar && typeof window.IlmunaCalendar.bind === 'function') {
                window.IlmunaCalendar.bind('#biayaTanggal', false, 'date');
            }
        },

        recalcBiayaPreview() {
            const q = parseFloat(document.getElementById('biayaQty').value || '0') || 0;
            const h = parseFloat(document.getElementById('biayaHarga').value || '0') || 0;
            const el = document.getElementById('biayaTotalPreview');
            if (el) el.textContent = formatRupiah(q * h);
        },

        async submitBiayaForm(e) {
            e.preventDefault();
            try {
                const fd = new FormData(document.getElementById('biayaForm'));
                fd.append('id_proyek', String(this.state.selectedProyekId));
                const res = await apiPost('save_biaya', fd, true);
                if (typeof window.closeIlmunaModal === 'function') window.closeIlmunaModal('modalBiayaForm');
                notify('success', res.message || 'Biaya proyek berhasil dicatat.');
                await this.loadBiayaTab();
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal mencatat biaya.');
            }
        },

        async verifyBiaya(id, status) {
            try {
                const res = await apiPost('verify_biaya', { id: id, status_verifikasi: status });
                notify('success', res.message || 'Status verifikasi diperbarui.');
                await this.loadBiayaTab();
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal memperbarui status.');
            }
        },

        async deleteBiaya(id) {
            const ok = await confirmAction('error', 'Hapus Catatan Biaya?', 'Item pengeluaran ini akan dihapus dari laporan anggaran proyek.', 'Hapus');
            if (!ok) return;
            try {
                const res = await apiPost('delete_biaya', { id: id });
                notify('success', res.message || 'Item biaya dihapus.');
                await this.loadBiayaTab();
                await this.loadProyekList();
            } catch (err) {
                notify('error', err.message || 'Gagal menghapus item biaya.');
            }
        }
    };

    window.PRY = PRY;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { PRY.init(); });
    } else {
        PRY.init();
    }
})();
