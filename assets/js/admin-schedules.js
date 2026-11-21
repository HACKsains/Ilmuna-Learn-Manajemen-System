/**
 * ==============================================================================
 * ILMUNA LEARN — ADMIN WEEKLY TIMETABLE SPA (DRAG & DROP + LAZY LOAD)
 * ==============================================================================
 * Gaya Kaldik Mingguan (Senin-Sabtu x Jam Ke-1..10):
 * - 100% Lazy Load & In-Place DOM Update (Zero Page Reload)
 * - Drag & Drop geser jadwal antar hari & jam ke-
 * - Tarik langsung chip Mapel/Kelas/Guru ke sel kosong untuk menjadwalkan
 * - Deteksi bentrok Guru / Kelas / Ruangan otomatis
 * - Sinkronisasi otomatis ke Kaldik, PROTA/PROSEM, Jadwal Guru, Modul Ajar, Presensi
 * ==============================================================================
 */
(function () {
    'use strict';

    const CFG = window.ILMUNA_ADMIN_SCHED_CFG || {
        endpoint: '/dashboard/admin/schedules',
        defaultTahun: '2026/2027',
        defaultSemester: 'Ganjil'
    };

    const DAYS = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    const COLORS = ['teal', 'navy', 'gold', 'purple', 'emerald', 'rose'];

    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function notify(type, message, title) {
        const ui = window.Ilmuna;
        const resolved = title || (type === 'error' ? 'Gagal' : type === 'warning' ? 'Perhatian' : type === 'info' ? 'Informasi' : 'Berhasil');
        if (ui && typeof ui.toast === 'function') {
            ui.toast(type, resolved, message || '', 4200);
            return;
        }
        console[type === 'error' ? 'error' : 'log']('[AdminSched]', resolved, message);
    }

    async function confirmAction(title, message, confirmText, isDanger) {
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            return await window.Ilmuna.confirm(isDanger ? 'error' : 'warning', title, message, confirmText || 'Ya, Lanjutkan', 'Batal');
        }
        return true;
    }

    async function apiCall(action, payload) {
        const fd = new FormData();
        fd.append('api_action', action);
        Object.keys(payload || {}).forEach(function (k) {
            const v = payload[k];
            if (v !== undefined && v !== null) {
                fd.append(k, typeof v === 'object' ? JSON.stringify(v) : String(v));
            }
        });
        const res = await fetch(CFG.endpoint + '?api=1', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        });
        const json = await res.json();
        if (!res.ok || !json.success) {
            throw new Error(json.message || 'Gagal memproses permintaan.');
        }
        return json;
    }

    const ASCH = {
        state: {
            activeTab: 'board',
            viewMode: 'kelas', // 'kelas' | 'guru' | 'all'
            tahun: CFG.defaultTahun,
            semester: CFG.defaultSemester,
            selectedKelasId: 0,
            selectedGuruId: 0,
            searchQuery: '',

            bellSlots: [],
            kelasList: [],
            guruList: [],
            kmpList: [],
            slots: [],
            syncRows: [],
            dragPayload: null,
            lastFlashId: 0
        },

        async init() {
            this.bindTabs();
            await this.loadBootstrap(true);
        },

        bindTabs() {
            const self = this;
            document.querySelectorAll('#aschMainTabs .curriculum-tab-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    self.switchTab(btn.getAttribute('data-tab'));
                });
            });
        },

        switchTab(tab) {
            this.state.activeTab = tab;
            document.querySelectorAll('#aschMainTabs .curriculum-tab-btn').forEach(function (btn) {
                const act = btn.getAttribute('data-tab') === tab;
                btn.classList.toggle('is-active', act);
                btn.setAttribute('aria-selected', act ? 'true' : 'false');
            });
            ['board', 'bell', 'sync'].forEach(function (t) {
                const pane = document.getElementById('aschPane_' + t);
                if (pane) pane.classList.toggle('is-active', t === tab);
            });
            if (tab === 'bell') this.renderBellEditor();
            if (tab === 'sync') this.loadSyncStatus();
        },

        async loadBootstrap(initial) {
            const card = document.getElementById('aschGridCard');
            if (card) card.style.opacity = '0.5';
            try {
                const res = await apiCall('bootstrap', {
                    tahun_pelajaran: this.state.tahun,
                    semester: this.state.semester
                });
                const d = res.data;
                this.state.bellSlots = d.bell_slots || [];
                this.state.kelasList = d.kelas_list || [];
                this.state.guruList  = d.guru_list || [];
                this.state.kmpList   = d.kmp_list || [];
                this.state.slots     = d.slots || [];

                if (initial) {
                    if (!this.state.selectedKelasId && this.state.kelasList.length) {
                        this.state.selectedKelasId = Number(this.state.kelasList[0].id);
                    }
                    if (!this.state.selectedGuruId && this.state.guruList.length) {
                        this.state.selectedGuruId = Number(this.state.guruList[0].id);
                    }
                }

                this.populateFilterDropdowns();
                this.updateCounters();
                this.renderPalette();
                this.renderWeeklyGrid();
                if (this.state.activeTab === 'bell') this.renderBellEditor();
                if (this.state.activeTab === 'sync') this.loadSyncStatus();
            } catch (err) {
                notify('error', err.message, 'Gagal Memuat Jadwal');
            } finally {
                if (card) card.style.opacity = '1';
            }
        },

        populateFilterDropdowns() {
            const self = this;
            const kSel = document.getElementById('aschKelasSelect');
            if (kSel) {
                kSel.innerHTML = '<option value="0">-- Semua Rombel Kelas --</option>' +
                    this.state.kelasList.map(function (k) {
                        return '<option value="' + k.id + '"' + (Number(k.id) === self.state.selectedKelasId ? ' selected' : '') + '>' + esc(k.nama_kelas) + '</option>';
                    }).join('');
            }
            const gSel = document.getElementById('aschGuruSelect');
            if (gSel) {
                gSel.innerHTML = '<option value="0">-- Semua Guru Pengampu --</option>' +
                    this.state.guruList.map(function (g) {
                        return '<option value="' + g.id + '"' + (Number(g.id) === self.state.selectedGuruId ? ' selected' : '') + '>' + esc(g.nama_guru) + '</option>';
                    }).join('');
            }
            const mKmp = document.getElementById('modalSlotKmp');
            if (mKmp) {
                mKmp.innerHTML = '<option value="">-- Pilih Kelas &amp; Mata Pelajaran --</option>' +
                    this.state.kmpList.map(function (km) {
                        return '<option value="' + km.id_kmp + '">' + esc(km.nama_kelas) + ' — ' + esc(km.nama_mata_pelajaran) + ' (' + esc(km.nama_guru) + ')</option>';
                    }).join('');
            }
            const mJam = document.getElementById('modalSlotJamKe');
            if (mJam) {
                const kbmSlots = this.state.bellSlots.filter(function (s) { return Number(s.is_istirahat) === 0 && Number(s.jam_ke) > 0; });
                mJam.innerHTML = kbmSlots.map(function (s) {
                    return '<option value="' + s.jam_ke + '">' + esc(s.label) + ' (' + esc(s.jam_mulai_hm) + ' - ' + esc(s.jam_selesai_hm) + ')</option>';
                }).join('');
            }

            // Show/hide filter select based on viewMode
            if (kSel) kSel.style.display = (this.state.viewMode === 'kelas') ? '' : 'none';
            if (gSel) gSel.style.display = (this.state.viewMode === 'guru') ? '' : 'none';
        },

        setViewMode(mode) {
            this.state.viewMode = mode;
            document.querySelectorAll('.asch-mode-btn').forEach(function (b) {
                b.classList.toggle('is-active', b.getAttribute('data-mode') === mode);
            });
            const kSel = document.getElementById('aschKelasSelect');
            const gSel = document.getElementById('aschGuruSelect');
            if (kSel) kSel.style.display = (mode === 'kelas') ? '' : 'none';
            if (gSel) gSel.style.display = (mode === 'guru') ? '' : 'none';
            this.renderPalette();
            this.renderWeeklyGrid();
        },

        onTermChange() {
            this.state.tahun = document.getElementById('aschTahunSelect').value;
            this.state.semester = document.getElementById('aschSemesterSelect').value;
            this.loadBootstrap(false);
        },

        onKelasFilterChange() {
            this.state.selectedKelasId = Number(document.getElementById('aschKelasSelect').value) || 0;
            this.renderPalette();
            this.renderWeeklyGrid();
        },

        onGuruFilterChange() {
            this.state.selectedGuruId = Number(document.getElementById('aschGuruSelect').value) || 0;
            this.renderPalette();
            this.renderWeeklyGrid();
        },

        onSearchInput(val) {
            this.state.searchQuery = (val || '').trim().toLowerCase();
            this.renderPalette();
            this.renderWeeklyGrid();
        },

        updateCounters() {
            const elB = document.getElementById('cntTabBoard');
            const elBell = document.getElementById('cntTabBell');
            const elStat = document.getElementById('aschStatSlots');
            const elJp = document.getElementById('aschStatJp');
            const totalJp = this.state.slots.reduce(function (acc, s) { return acc + Number(s.durasi_jp || 1); }, 0);
            if (elB) elB.textContent = this.state.slots.length;
            if (elBell) elBell.textContent = this.state.bellSlots.filter(function (s) { return !s.is_istirahat; }).length;
            if (elStat) elStat.textContent = this.state.slots.length;
            if (elJp) elJp.textContent = totalJp;
        },

        getFilteredSlots() {
            const st = this.state;
            return st.slots.filter(function (s) {
                if (st.viewMode === 'kelas' && st.selectedKelasId > 0 && Number(s.id_kelas) !== st.selectedKelasId) return false;
                if (st.viewMode === 'guru' && st.selectedGuruId > 0 && Number(s.id_guru) !== st.selectedGuruId) return false;
                if (st.searchQuery) {
                    const hay = (s.nama_kelas + ' ' + s.nama_mata_pelajaran + ' ' + s.nama_guru + ' ' + (s.ruangan || '')).toLowerCase();
                    if (hay.indexOf(st.searchQuery) === -1) return false;
                }
                return true;
            });
        },

        renderPalette() {
            const wrap = document.getElementById('aschPaletteChips');
            if (!wrap) return;
            const st = this.state;

            // Hitung JP terjadwal per KMP
            const jpByKmp = {};
            st.slots.forEach(function (s) {
                const k = Number(s.id_kelas_mata_pelajaran);
                jpByKmp[k] = (jpByKmp[k] || 0) + Number(s.durasi_jp || 1);
            });

            const items = st.kmpList.filter(function (km) {
                if (st.viewMode === 'kelas' && st.selectedKelasId > 0 && Number(km.id_kelas) !== st.selectedKelasId) return false;
                if (st.viewMode === 'guru' && st.selectedGuruId > 0 && Number(km.id_guru) !== st.selectedGuruId) return false;
                if (st.searchQuery) {
                    const hay = (km.nama_kelas + ' ' + km.nama_mata_pelajaran + ' ' + km.nama_guru).toLowerCase();
                    if (hay.indexOf(st.searchQuery) === -1) return false;
                }
                return true;
            });

            if (!items.length) {
                wrap.innerHTML = '<span style="font-size:12px;color:#64748B;">Tidak ada pasangan Kelas-Mapel-Guru pada filter ini. Pastikan pemetaan mapel sudah dibuat di menu Mata Pelajaran &amp; Rombel.</span>';
                return;
            }

            wrap.innerHTML = items.map(function (km) {
                const jp = jpByKmp[km.id_kmp] || 0;
                return '<div class="asch-kmp-chip" draggable="true" ' +
                    'ondragstart="ASCH.onDragStartKmp(event,' + km.id_kmp + ')" ' +
                    'onclick="ASCH.openSlotModal(null, null, null, ' + km.id_kmp + ')" ' +
                    'title="Tarik ke kotak Hari &amp; Jam Ke- di bawah, atau klik untuk menjadwalkan">' +
                    '<iconify-icon icon="solar:hamburger-menu-linear" style="color:#94A3B8;"></iconify-icon>' +
                    '<span>' + esc(km.nama_mata_pelajaran) + '</span>' +
                    '<span class="asch-kmp-chip__sub">' + esc(km.nama_kelas) + ' &bull; ' + esc(km.nama_guru) + '</span>' +
                    '<span class="asch-kmp-chip__jp' + (jp === 0 ? ' is-zero' : '') + '">' + jp + ' JP</span>' +
                '</div>';
            }).join('');
        },

        renderWeeklyGrid() {
            const tbody = document.getElementById('aschGridBody');
            if (!tbody) return;
            const visibleSlots = this.getFilteredSlots();

            // Index slot start & span continuation by [hari][jam_ke]
            const startMap = {};
            const contMap  = {};
            DAYS.forEach(function (d) { startMap[d] = {}; contMap[d] = {}; });

            visibleSlots.forEach(function (sl) {
                const d = sl.hari;
                const sKe = Number(sl.jam_ke_mulai);
                const dur = Math.max(1, Number(sl.durasi_jp || 1));
                if (!startMap[d]) return;
                if (!startMap[d][sKe]) startMap[d][sKe] = [];
                startMap[d][sKe].push(sl);
                for (let k = sKe + 1; k < sKe + dur; k++) {
                    if (!contMap[d][k]) contMap[d][k] = [];
                    contMap[d][k].push(sl);
                }
            });

            const self = this;
            let html = '';

            this.state.bellSlots.forEach(function (bs) {
                if (Number(bs.is_istirahat) === 1) {
                    html += '<tr class="asch-break-row">' +
                        '<td class="asch-time-col"><div class="asch-jam-ke">' + esc(bs.label) + '</div><div class="asch-jam-range">' + esc(bs.jam_mulai_hm) + ' - ' + esc(bs.jam_selesai_hm) + '</div></td>' +
                        '<td colspan="6"><iconify-icon icon="solar:cup-hot-bold" style="vertical-align:middle;margin-right:4px;"></iconify-icon> ' + esc(bs.label) + ' (' + esc(bs.jam_mulai_hm) + ' &ndash; ' + esc(bs.jam_selesai_hm) + ' WIB)</td>' +
                    '</tr>';
                    return;
                }

                const jamKe = Number(bs.jam_ke);
                html += '<tr>';
                html += '<td class="asch-time-col">' +
                    '<div class="asch-jam-ke">' + esc(bs.label) + '</div>' +
                    '<div class="asch-jam-range">' + esc(bs.jam_mulai_hm) + ' - ' + esc(bs.jam_selesai_hm) + '</div>' +
                '</td>';

                DAYS.forEach(function (hari) {
                    const cards = startMap[hari][jamKe] || [];
                    const conts = contMap[hari][jamKe] || [];
                    const isEmpty = cards.length === 0 && conts.length === 0;

                    html += '<td class="asch-drop-cell' + (isEmpty ? ' is-empty' : '') + '" ' +
                        'data-hari="' + hari + '" data-jam-ke="' + jamKe + '" ' +
                        'ondragover="ASCH.onCellDragOver(event)" ' +
                        'ondragleave="ASCH.onCellDragLeave(event)" ' +
                        'ondrop="ASCH.onCellDrop(event,\'' + hari + '\',' + jamKe + ')" ' +
                        'onclick="ASCH.onCellClick(event,\'' + hari + '\',' + jamKe + ')">';

                    if (isEmpty) {
                        html += '<div class="asch-cell-add-hint"><iconify-icon icon="solar:add-circle-bold"></iconify-icon> Tambah</div>';
                    }

                    html += '<div class="asch-slot-stack">';
                    cards.forEach(function (sl) {
                        const dur = Math.max(1, Number(sl.durasi_jp || 1));
                        const endKe = Number(sl.jam_ke_mulai) + dur - 1;
                        const jamBadge = dur > 1 ? ('J.' + sl.jam_ke_mulai + '-' + endKe) : ('J.' + sl.jam_ke_mulai);
                        const color = COLORS.indexOf(sl.warna_kartu) !== -1 ? sl.warna_kartu : 'teal';
                        const flashCls = (Number(sl.id) === self.state.lastFlashId) ? ' asch-flash' : '';

                        html += '<div class="asch-card asch-card--' + color + flashCls + '" draggable="true" ' +
                            'data-slot-id="' + sl.id + '" ' +
                            'ondragstart="ASCH.onDragStartSlot(event,' + sl.id + ')" ' +
                            'ondragend="ASCH.onDragEnd(event)" ' +
                            'onclick="event.stopPropagation(); ASCH.openSlotModal(' + sl.id + ')">' +
                            '<div class="asch-card__top">' +
                                '<span class="asch-card__badge">' + esc(sl.nama_kelas) + '</span>' +
                                '<span class="asch-card__jp">' + esc(jamBadge) + ' &bull; ' + dur + ' JP</span>' +
                            '</div>' +
                            '<div class="asch-card__mapel">' + esc(sl.nama_mata_pelajaran) + '</div>' +
                            '<div class="asch-card__guru"><iconify-icon icon="solar:user-speak-bold" style="color:#0D7A87;flex-shrink:0;"></iconify-icon><span>' + esc(sl.nama_guru) + '</span></div>' +
                            '<div class="asch-card__foot">' +
                                '<span>' + esc(String(sl.jam_mulai || '').slice(0, 5)) + '-' + esc(String(sl.jam_selesai || '').slice(0, 5)) + '</span>' +
                                '<span>' + esc(sl.ruangan || 'Ruang Kelas') + '</span>' +
                            '</div>' +
                        '</div>';
                    });

                    conts.forEach(function (sl) {
                        html += '<div class="asch-span-cont" onclick="event.stopPropagation(); ASCH.openSlotModal(' + sl.id + ')" title="Lanjutan dari Jam ke-' + sl.jam_ke_mulai + '">' +
                            '&uarr; Lanjutan ' + esc(sl.nama_mata_pelajaran) + ' (' + esc(sl.nama_kelas) + ')' +
                        '</div>';
                    });

                    html += '</div></td>';
                });
                html += '</tr>';
            });

            tbody.innerHTML = html;
            this.state.lastFlashId = 0;
        },

        /* ======================================================================
         * DRAG & DROP HANDLERS (GESER-GESER GAYA KALDIK)
         * ====================================================================== */
        onDragStartSlot(ev, slotId) {
            this.state.dragPayload = { type: 'move_slot', slotId: Number(slotId) };
            ev.dataTransfer.effectAllowed = 'move';
            ev.dataTransfer.setData('text/plain', JSON.stringify(this.state.dragPayload));
            const card = ev.target.closest('.asch-card');
            if (card) card.classList.add('is-dragging');
        },

        onDragStartKmp(ev, kmpId) {
            this.state.dragPayload = { type: 'new_kmp', kmpId: Number(kmpId) };
            ev.dataTransfer.effectAllowed = 'copy';
            ev.dataTransfer.setData('text/plain', JSON.stringify(this.state.dragPayload));
        },

        onDragEnd(ev) {
            const card = ev.target.closest('.asch-card');
            if (card) card.classList.remove('is-dragging');
            document.querySelectorAll('.asch-drop-cell.is-drag-over').forEach(function (c) {
                c.classList.remove('is-drag-over');
            });
        },

        onCellDragOver(ev) {
            ev.preventDefault();
            const td = ev.currentTarget;
            td.classList.add('is-drag-over');
        },

        onCellDragLeave(ev) {
            ev.currentTarget.classList.remove('is-drag-over');
        },

        async onCellDrop(ev, targetHari, targetJamKe) {
            ev.preventDefault();
            ev.currentTarget.classList.remove('is-drag-over');

            let payload = this.state.dragPayload;
            if (!payload) {
                try { payload = JSON.parse(ev.dataTransfer.getData('text/plain')); } catch (e) {}
            }
            this.state.dragPayload = null;
            if (!payload) return;

            try {
                if (payload.type === 'move_slot') {
                    const existing = this.state.slots.find(function (s) { return Number(s.id) === Number(payload.slotId); });
                    if (existing && existing.hari === targetHari && Number(existing.jam_ke_mulai) === Number(targetJamKe)) {
                        return; // posisi sama
                    }
                    const res = await apiCall('move_weekly_slot', {
                        id: payload.slotId,
                        hari: targetHari,
                        jam_ke_mulai: targetJamKe
                    });
                    this.state.lastFlashId = Number(payload.slotId);
                    notify('success', res.message, 'Jadwal Digeser & Tersinkron');
                    await this.refreshSlotsInPlace();
                } else if (payload.type === 'new_kmp') {
                    const res = await apiCall('save_weekly_slot', {
                        id: 0,
                        tahun_pelajaran: this.state.tahun,
                        semester: this.state.semester,
                        id_kelas_mata_pelajaran: payload.kmpId,
                        hari: targetHari,
                        jam_ke_mulai: targetJamKe,
                        durasi_jp: 2,
                        ruangan: 'Ruang Kelas',
                        warna_kartu: 'teal'
                    });
                    this.state.lastFlashId = Number(res.data && res.data.id ? res.data.id : 0);
                    notify('success', res.message, 'Jadwal Ditambahkan & Tersinkron');
                    await this.refreshSlotsInPlace();
                }
            } catch (err) {
                notify('error', err.message, 'Bentrok / Gagal Memindahkan');
            }
        },

        onCellClick(ev, hari, jamKe) {
            if (ev.target.closest('.asch-card') || ev.target.closest('.asch-span-cont')) return;
            this.openSlotModal(null, hari, jamKe, null);
        },

        async refreshSlotsInPlace() {
            const res = await apiCall('list_slots', {
                tahun_pelajaran: this.state.tahun,
                semester: this.state.semester
            });
            this.state.slots = res.data.slots || [];
            this.updateCounters();
            this.renderPalette();
            this.renderWeeklyGrid();
        },

        /* ======================================================================
         * MODAL TAMBAH / EDIT / PINDAH / HAPUS SLOT JADWAL
         * ====================================================================== */
        openSlotModal(slotId, presetHari, presetJamKe, presetKmpId) {
            const isEdit = !!(slotId && Number(slotId) > 0);
            const existing = isEdit ? this.state.slots.find(function (s) { return Number(s.id) === Number(slotId); }) : null;

            document.getElementById('modalSlotId').value = existing ? existing.id : 0;
            document.getElementById('modalSlotTitle').textContent = existing ? 'Edit / Pindahkan Slot Jadwal' : 'Tambah Slot Jadwal Mingguan';
            document.getElementById('modalSlotDeleteBtn').style.display = existing ? 'inline-flex' : 'none';

            // Filter dropdown KMP supaya mudah dipilih
            const kmpSel = document.getElementById('modalSlotKmp');
            if (existing) {
                kmpSel.value = String(existing.id_kelas_mata_pelajaran);
            } else if (presetKmpId) {
                kmpSel.value = String(presetKmpId);
            } else {
                // Default KMP pertama yang sesuai filter kelas/guru aktif
                const st = this.state;
                const match = st.kmpList.find(function (k) {
                    if (st.viewMode === 'kelas' && st.selectedKelasId > 0) return Number(k.id_kelas) === st.selectedKelasId;
                    if (st.viewMode === 'guru' && st.selectedGuruId > 0) return Number(k.id_guru) === st.selectedGuruId;
                    return true;
                });
                kmpSel.value = match ? String(match.id_kmp) : '';
            }

            document.getElementById('modalSlotHari').value = existing ? existing.hari : (presetHari || 'Senin');
            document.getElementById('modalSlotJamKe').value = String(existing ? existing.jam_ke_mulai : (presetJamKe || 1));
            document.getElementById('modalSlotDurasi').value = String(existing ? existing.durasi_jp : 2);
            document.getElementById('modalSlotRuangan').value = existing ? (existing.ruangan || 'Ruang Kelas') : 'Ruang Kelas';
            document.getElementById('modalSlotCatatan').value = existing ? (existing.catatan || '') : '';
            this.selectCardColor(existing ? (existing.warna_kartu || 'teal') : 'teal');
            this.previewModalTime();

            openIlmunaModal('modalWeeklySlot');
        },

        selectCardColor(color) {
            document.getElementById('modalSlotColor').value = color;
            document.querySelectorAll('.asch-color-dot').forEach(function (d) {
                d.classList.toggle('is-active', d.getAttribute('data-color') === color);
            });
        },

        previewModalTime() {
            const startKe = Number(document.getElementById('modalSlotJamKe').value) || 1;
            const dur = Math.max(1, Number(document.getElementById('modalSlotDurasi').value) || 2);
            const endKe = startKe + dur - 1;
            const kbmMap = {};
            this.state.bellSlots.forEach(function (s) {
                if (!s.is_istirahat && s.jam_ke > 0) kbmMap[Number(s.jam_ke)] = s;
            });
            const sObj = kbmMap[startKe];
            const eObj = kbmMap[endKe];
            const preview = document.getElementById('modalTimePreview');
            if (preview) {
                const startTxt = sObj ? sObj.jam_mulai_hm : '07:00';
                const endTxt = eObj ? eObj.jam_selesai_hm : '08:30';
                preview.textContent = 'Jam ke-' + (dur > 1 ? (startKe + ' s/d ' + endKe) : startKe) + ' (' + startTxt + ' - ' + endTxt + ' WIB)';
            }
        },

        async submitSlotForm(ev) {
            ev.preventDefault();
            const btn = document.getElementById('modalSlotSaveBtn');
            btn.disabled = true;
            try {
                const res = await apiCall('save_weekly_slot', {
                    id: Number(document.getElementById('modalSlotId').value) || 0,
                    tahun_pelajaran: this.state.tahun,
                    semester: this.state.semester,
                    id_kelas_mata_pelajaran: Number(document.getElementById('modalSlotKmp').value),
                    hari: document.getElementById('modalSlotHari').value,
                    jam_ke_mulai: Number(document.getElementById('modalSlotJamKe').value),
                    durasi_jp: Number(document.getElementById('modalSlotDurasi').value),
                    ruangan: document.getElementById('modalSlotRuangan').value.trim(),
                    warna_kartu: document.getElementById('modalSlotColor').value,
                    catatan: document.getElementById('modalSlotCatatan').value.trim()
                });
                this.state.lastFlashId = Number(res.data && res.data.id ? res.data.id : 0);
                closeIlmunaModal('modalWeeklySlot');
                notify('success', res.message, 'Tersimpan & Tersinkron');
                await this.refreshSlotsInPlace();
            } catch (err) {
                notify('error', err.message, 'Gagal Menyimpan Jadwal');
            } finally {
                btn.disabled = false;
            }
        },

        async deleteActiveSlot() {
            const id = Number(document.getElementById('modalSlotId').value) || 0;
            if (!id) return;
            const ok = await confirmAction(
                'Hapus Slot Jadwal Mingguan?',
                'Slot jadwal ini akan dihapus dari papan mingguan dan jadwal pertemuan guru yang belum terlaksana akan disesuaikan ulang.',
                'Ya, Hapus Slot',
                true
            );
            if (!ok) return;
            try {
                const res = await apiCall('delete_weekly_slot', { id: id });
                closeIlmunaModal('modalWeeklySlot');
                notify('success', res.message);
                await this.refreshSlotsInPlace();
            } catch (err) {
                notify('error', err.message);
            }
        },

        /* ======================================================================
         * TAB 2: BEL SEKOLAH (PENGATURAN SLOT JAM PELAJARAN)
         * ====================================================================== */
        renderBellEditor() {
            const tbody = document.getElementById('aschBellTableBody');
            if (!tbody) return;
            tbody.innerHTML = this.state.bellSlots.map(function (bs, idx) {
                const isBreak = Number(bs.is_istirahat) === 1;
                return '<tr data-bell-id="' + bs.id + '">' +
                    '<td style="text-align:center;font-weight:800;color:#64748B;">' + (idx + 1) + '</td>' +
                    '<td><input type="text" class="asch-input bell-label" value="' + esc(bs.label) + '" style="width:100%;"></td>' +
                    '<td style="text-align:center;">' +
                        (isBreak
                            ? '<span class="badge badge-gold">Istirahat</span><input type="hidden" class="bell-jamke" value="0"><input type="hidden" class="bell-break" value="1">'
                            : '<input type="number" class="asch-input bell-jamke" value="' + bs.jam_ke + '" min="1" max="15" style="width:72px;text-align:center;"><input type="hidden" class="bell-break" value="0">') +
                    '</td>' +
                    '<td><input type="time" class="asch-input bell-mulai" value="' + esc(bs.jam_mulai_hm) + '" style="width:130px;"></td>' +
                    '<td><input type="time" class="asch-input bell-selesai" value="' + esc(bs.jam_selesai_hm) + '" style="width:130px;"></td>' +
                '</tr>';
            }).join('');
        },

        async saveBellSlots() {
            const rows = [];
            document.querySelectorAll('#aschBellTableBody tr[data-bell-id]').forEach(function (tr, idx) {
                rows.push({
                    id: Number(tr.getAttribute('data-bell-id')),
                    label: tr.querySelector('.bell-label').value.trim(),
                    jam_ke: Number(tr.querySelector('.bell-jamke').value) || 0,
                    is_istirahat: Number(tr.querySelector('.bell-break').value) || 0,
                    jam_mulai: tr.querySelector('.bell-mulai').value,
                    jam_selesai: tr.querySelector('.bell-selesai').value,
                    urutan: idx + 1
                });
            });
            try {
                const res = await apiCall('save_bell_slots', {
                    slots: rows,
                    tahun_pelajaran: this.state.tahun,
                    semester: this.state.semester
                });
                notify('success', res.message);
                await this.loadBootstrap(false);
            } catch (err) {
                notify('error', err.message);
            }
        },

        /* ======================================================================
         * TAB 3: SINKRONISASI KALDIK, PROTA/PROSEM, JADWAL GURU & MODUL AJAR
         * ====================================================================== */
        async loadSyncStatus() {
            const tbody = document.getElementById('aschSyncTableBody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#64748B;">Memuat status sinkronisasi Kaldik, PROTA/PROSEM &amp; Modul Ajar...</td></tr>';
            try {
                const res = await apiCall('get_sync_status', {
                    tahun_pelajaran: this.state.tahun,
                    semester: this.state.semester
                });
                this.state.syncRows = res.data.rows || [];
                this.renderSyncTable();
            } catch (err) {
                if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:24px;color:#DC2626;">' + esc(err.message) + '</td></tr>';
            }
        },

        renderSyncTable() {
            const tbody = document.getElementById('aschSyncTableBody');
            if (!tbody) return;
            if (!this.state.syncRows.length) {
                tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#64748B;">Belum ada data Kelas &amp; Mata Pelajaran.</td></tr>';
                return;
            }
            tbody.innerHTML = this.state.syncRows.map(function (r) {
                const hasWeekly = r.weekly_slots && r.weekly_slots.length > 0;
                const weeklyText = hasWeekly
                    ? r.weekly_slots.map(function (w) {
                        const endKe = Number(w.jam_ke_mulai) + Number(w.durasi_jp) - 1;
                        return '<span class="badge badge-teal" style="margin:2px;">' + esc(w.hari) + ' J.' + w.jam_ke_mulai + (endKe > w.jam_ke_mulai ? ('-' + endKe) : '') + ' (' + esc(String(w.jam_mulai).slice(0, 5)) + ')</span>';
                    }).join(' ')
                    : '<span class="badge badge-rose">Belum Dijadwalkan</span>';

                return '<tr>' +
                    '<td><strong>' + esc(r.nama_kelas) + '</strong><div style="font-size:11.5px;color:#64748B;">' + esc(r.nama_mata_pelajaran) + '</div></td>' +
                    '<td>' + esc(r.nama_guru) + '</td>' +
                    '<td>' + weeklyText + '</td>' +
                    '<td style="text-align:center;"><strong style="color:#0D7A87;">' + (r.jadwal_count || 0) + '</strong> Sesi<div style="font-size:11px;color:#64748B;">' + (r.ahm_total_jtm || 0) + ' JTM</div></td>' +
                    '<td style="text-align:center;">' + (r.has_prosem ? '<span class="badge badge-emerald">PROSEM Siap</span>' : '<span class="badge badge-gold">Belum PROSEM</span>') + '</td>' +
                    '<td style="text-align:center;"><strong>' + (r.modul_count || 0) + '</strong> Modul</td>' +
                    '<td style="text-align:right;">' +
                        '<button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" style="padding:5px 10px;font-size:11.5px;" onclick="ASCH.syncSingleKmp(' + r.id_kmp + ')"' + (!hasWeekly ? ' disabled' : '') + '>' +
                            '<iconify-icon icon="solar:refresh-bold"></iconify-icon> Sinkron' +
                        '</button>' +
                    '</td>' +
                '</tr>';
            }).join('');
        },

        async syncSingleKmp(idKmp) {
            try {
                const res = await apiCall('sync_single_kmp', {
                    id_kmp: idKmp,
                    tahun_pelajaran: this.state.tahun,
                    semester: this.state.semester
                });
                notify('success', res.message);
                await this.loadSyncStatus();
            } catch (err) {
                notify('error', err.message);
            }
        },

        async syncAllToKaldikAndGuru() {
            const ok = await confirmAction(
                'Sinkronkan Semua Jadwal ke Kaldik & Guru?',
                'Seluruh jadwal mingguan pada semester ini akan disinkronkan dengan Kalender Pendidikan (hari libur otomatis dilewati), Alokasi Hari Mengajar PROTA/PROSEM, Jadwal Guru, dan tanggal Modul Ajar. Lanjutkan?',
                'Ya, Sinkronkan Semua',
                false
            );
            if (!ok) return;
            const btn = document.getElementById('btnSyncAllWeekly');
            if (btn) btn.disabled = true;
            try {
                const res = await apiCall('sync_all_weekly', {
                    tahun_pelajaran: this.state.tahun,
                    semester: this.state.semester
                });
                notify('success', res.message, 'Sinkronisasi Selesai');
                if (this.state.activeTab === 'sync') await this.loadSyncStatus();
            } catch (err) {
                notify('error', err.message);
            } finally {
                if (btn) btn.disabled = false;
            }
        }
    };

    window.ASCH = ASCH;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { ASCH.init(); });
    } else {
        ASCH.init();
    }
})();
