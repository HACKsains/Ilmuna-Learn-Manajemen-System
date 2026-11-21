/**
 * ==============================================================================
 * ILMUNA LEARN — PORTAL PROYEK & P5 (PjBL) SISWA CONTROLLER
 * ==============================================================================
 * Pure Vanilla ES6+ | Zero Page Reload | window.Ilmuna Only | Solar Icons Only
 * ==============================================================================
 */
(function () {
    'use strict';

    const CFG = window.ILMUNA_SISWA_PROYEK_CFG || {
        baseUrl: '',
        endpoint: '/dashboard/siswa/proyek',
        siswaId: 0
    };

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
        console[type === 'error' ? 'error' : 'log']('[SiswaProyek]', t, message);
    }

    async function apiPost(action, data, isFormData) {
        let body;
        if (isFormData && data instanceof FormData) {
            data.append('action', action);
            if (CFG.siswaId > 0) data.append('siswa_id', String(CFG.siswaId));
            body = data;
        } else {
            const fd = new FormData();
            fd.append('action', action);
            if (CFG.siswaId > 0) fd.append('siswa_id', String(CFG.siswaId));
            if (data && typeof data === 'object') {
                Object.keys(data).forEach(function (k) {
                    if (data[k] !== undefined && data[k] !== null) fd.append(k, String(data[k]));
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
        if (!pred) return '';
        let cls = 'pry-badge--teal';
        if (pred.indexOf('(SB)') !== -1) cls = 'pry-badge--emerald';
        else if (pred.indexOf('(MB)') !== -1) cls = 'pry-badge--amber';
        else if (pred.indexOf('(BB)') !== -1) cls = 'pry-badge--rose';
        return '<span class="pry-badge ' + cls + '">' + esc(pred) + '</span>';
    }

    const SPRY = {
        state: {
            activeTab: 'proyek',
            items: [],
            allItems: [],
            mapelLoaded: false,
            filterKmp: 0,
            filterStatus: '',
            search: '',
            biayaList: []
        },

        async init() {
            this.bindEvents();
            await this.loadMyProyek();
        },

        bindEvents() {
            const self = this;
            document.querySelectorAll('#spryMainTabs .curriculum-tab-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    self.switchTab(btn.getAttribute('data-tab'));
                });
            });

            const fm = document.getElementById('spryFilterMapel');
            if (fm) fm.addEventListener('change', function () {
                self.state.filterKmp = parseInt(fm.value || '0', 10);
                self.loadMyProyek();
            });

            const fs = document.getElementById('spryFilterStatus');
            if (fs) fs.addEventListener('change', function () {
                self.state.filterStatus = fs.value || '';
                self.loadMyProyek();
            });

            let tSearch = null;
            const fSearch = document.getElementById('sprySearchInput');
            if (fSearch) fSearch.addEventListener('input', function () {
                clearTimeout(tSearch);
                tSearch = setTimeout(function () {
                    self.state.search = fSearch.value.trim();
                    self.loadMyProyek();
                }, 260);
            });

            const bf = document.getElementById('spryBiayaProyekFilter');
            if (bf) bf.addEventListener('change', function () {
                self.loadMyBiaya();
            });

            ['spryBiayaQty', 'spryBiayaHarga'].forEach(function (id) {
                const el = document.getElementById(id);
                if (el) el.addEventListener('input', function () { self.recalcBiayaPreview(); });
            });
        },

        switchTab(tab) {
            this.state.activeTab = tab;
            document.querySelectorAll('#spryMainTabs .curriculum-tab-btn').forEach(function (b) {
                const isAct = b.getAttribute('data-tab') === tab;
                b.classList.toggle('active', isAct);
                b.classList.toggle('is-active', isAct);
            });
            document.querySelectorAll('.pry-tab-pane').forEach(function (p) {
                p.style.display = (p.id === 'spry-tab-' + tab) ? 'block' : 'none';
            });
            if (tab === 'kelompok') this.renderMyTeams();
            if (tab === 'biaya') this.loadMyBiaya();
        },

        async loadMyProyek() {
            const grid = document.getElementById('spryCardGrid');
            if (grid) {
                grid.innerHTML = '<div class="pry-empty" style="grid-column:1/-1;"><iconify-icon icon="solar:refresh-bold"></iconify-icon><h4>Memuat Daftar Proyek Kelas...</h4></div>';
            }
            try {
                const res = await apiPost('list_my_proyek', {
                    id_kmp: this.state.filterKmp,
                    sub_status: this.state.filterStatus,
                    search: this.state.search
                });
                const d = res.data || {};
                this.state.items = d.items || [];
                if (!this.state.filterKmp && !this.state.filterStatus && !this.state.search) {
                    this.state.allItems = this.state.items.slice();
                    this.syncBiayaProjectSelectors();
                }

                if (!this.state.mapelLoaded && d.mapel_list) {
                    this.state.mapelLoaded = true;
                    const fm = document.getElementById('spryFilterMapel');
                    if (fm) {
                        fm.innerHTML = '<option value="0">Semua Mata Pelajaran</option>' + d.mapel_list.map(function (m) {
                            return '<option value="' + m.id_kmp + '">' + esc(m.nama_mata_pelajaran) + '</option>';
                        }).join('');
                    }
                }

                const s = d.stats || {};
                const bProyek = document.getElementById('badgeSiswaProyek');
                if (bProyek) bProyek.textContent = String(s.total || 0);

                this.renderCards();
                if (this.state.activeTab === 'kelompok') this.renderMyTeams();
            } catch (e) {
                if (grid) {
                    grid.innerHTML = '<div class="pry-empty" style="grid-column:1/-1;"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon><h4>Gagal Memuat Proyek</h4><p>' + esc(e.message) + '</p></div>';
                }
            }
        },

        syncBiayaProjectSelectors() {
            const list = this.state.allItems;
            const filterSel = document.getElementById('spryBiayaProyekFilter');
            const modalSel = document.getElementById('spryBiayaProyek');
            if (filterSel) {
                filterSel.innerHTML = '<option value="0">Semua Proyek Saya</option>' + list.map(function (p) {
                    return '<option value="' + p.id + '">' + esc(p.nama_proyek) + ' (' + esc(p.nama_mata_pelajaran) + ')</option>';
                }).join('');
            }
            if (modalSel) {
                modalSel.innerHTML = list.length
                    ? list.map(function (p) { return '<option value="' + p.id + '">' + esc(p.nama_proyek) + ' (' + esc(p.nama_mata_pelajaran) + ')</option>'; }).join('')
                    : '<option value="0">-- Belum ada proyek aktif --</option>';
            }
        },

        renderCards() {
            const grid = document.getElementById('spryCardGrid');
            if (!grid) return;
            const list = this.state.items;
            if (!list.length) {
                grid.innerHTML =
                    '<div class="pry-empty" style="grid-column:1/-1;">' +
                        '<iconify-icon icon="solar:rocket-2-bold-duotone"></iconify-icon>' +
                        '<h4>Belum Ada Proyek &amp; P5</h4>' +
                        '<p>Belum ada proyek pembelajaran yang sesuai filter saat ini.</p>' +
                    '</div>';
                return;
            }

            grid.innerHTML = list.map(function (p) {
                const sub = p.submission || null;
                const team = p.my_team || null;
                const nilaiDet = p.nilai_detail || null;
                const isKelompok = p.jenis_proyek === 'Kelompok';
                const canUploadByRole = !isKelompok || (team && Boolean(team.is_me_ketua));

                const p5List = String(p.dimensi_profil_pancasila || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 3);
                const p5Html = p5List.length
                    ? '<div class="pry-p5-chips">' + p5List.map(function (d) { return '<span class="pry-p5-chip">' + esc(d) + '</span>'; }).join('') + '</div>'
                    : '';

                const modulStrip = Number(p.id_modul_ajar) > 0
                    ? '<div class="pry-modul-strip"><div class="pry-modul-strip__text"><iconify-icon icon="solar:notebook-bookmark-bold" style="font-size:16px;flex-shrink:0;"></iconify-icon><span>P' + esc(p.modul_pertemuan_ke || p.pertemuan_ke || '-') + ' • ' + esc(p.judul_modul || 'Modul Ajar') + '</span></div></div>'
                    : '';

                const teamStrip = isKelompok
                    ? (team
                        ? '<div class="pry-team-mini-strip"><span><iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon> <strong>' + esc(team.nama_kelompok) + '</strong> (Peran: ' + esc(team.peran_anggota || 'Anggota') + (team.is_me_ketua ? ' • Ketua' : (team.nama_ketua ? ' • Ketua: ' + esc(team.nama_ketua) : '')) + ')</span><span>' + ((team.anggota || []).length) + ' anggota</span></div>'
                        : '<div class="pry-team-mini-strip" style="background:#FFFBEB;border-color:#FDE68A;color:#92400E;"><span><iconify-icon icon="solar:info-circle-bold"></iconify-icon> Proyek Kelompok — menunggu pembagian kelompok oleh guru</span></div>')
                    : '';

                const links = [];
                if (p.file_panduan) links.push('<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + p.file_panduan.replace(/^\/+/, '')) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:document-text-bold"></iconify-icon> Panduan Guru</a>');
                if (p.link_referensi) links.push('<a class="pry-link-chip" href="' + esc(p.link_referensi) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:link-bold"></iconify-icon> Referensi</a>');

                let subBoxHtml = '';
                let btnLabel = 'Kumpulkan Karya';
                let btnCls = 'ilmuna-btn--teal';
                let btnDisabled = false;
                let btnHint = '';

                if (sub) {
                    const isRev = sub.status_pengumpulan === 'Revisi' || (nilaiDet && nilaiDet.status_penilaian === 'Perlu Revisi');
                    const isLocked = (sub.status_pengumpulan === 'Disetujui' || sub.status_pengumpulan === 'Dipublikasikan') && (nilaiDet !== null || (sub.nilai_proyek !== null && sub.nilai_proyek !== ''));
                    btnLabel = isRev ? 'Kirim Revisi Karya' : (isLocked ? 'Sudah Dinilai' : 'Perbarui Karya');
                    btnCls = isRev ? 'ilmuna-btn--amber' : (isLocked ? 'ilmuna-btn--light-outline' : 'ilmuna-btn--navy');
                    btnDisabled = isLocked;

                    const subLinks = [];
                    if (sub.link_produk) subLinks.push('<a class="pry-link-chip" href="' + esc(sub.link_produk) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:link-bold"></iconify-icon> Karya</a>');
                    if (sub.link_video_presentasi) subLinks.push('<a class="pry-link-chip" href="' + esc(sub.link_video_presentasi) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:videocamera-bold"></iconify-icon> Video</a>');
                    if (sub.file_laporan) subLinks.push('<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + sub.file_laporan.replace(/^\/+/, '')) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:document-text-bold"></iconify-icon> ' + esc((sub.file_nama_asli || 'Laporan').slice(0, 22)) + '</a>');
                    if (sub.file_dokumentasi) subLinks.push('<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + sub.file_dokumentasi.replace(/^\/+/, '')) + '" target="_blank" rel="noopener"><iconify-icon icon="solar:gallery-bold"></iconify-icon> Dok</a>');

                    const lateTag = Number(sub.is_terlambat) === 1 ? ' <span class="pry-badge pry-badge--rose">Terlambat</span>' : '';
                    const revTag = Number(sub.revisi_ke) > 1 ? ' <span class="pry-badge pry-badge--amber">Rev #' + sub.revisi_ke + '</span>' : '';

                    // Rincian Nilai Identik Tugas.php: Kelompok + Individu / 2 = Nilai Akhir
                    let nilaiBlock = '';
                    if (nilaiDet) {
                        const isGrpGraded = nilaiDet.nilai_kelompok !== null && nilaiDet.nilai_kelompok !== undefined;
                        const formulaLine = isGrpGraded
                            ? '<div style="font-size:11px;color:#1E3A8A;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:6px 9px;margin-top:4px;">' +
                                'Kelompok: <strong>' + esc(nilaiDet.nilai_kelompok) + '</strong> &bull; Individu: <strong>' + esc(nilaiDet.nilai_individu) + '</strong> &rarr; ' +
                                '(' + esc(nilaiDet.nilai_kelompok) + ' + ' + esc(nilaiDet.nilai_individu) + ') &divide; 2 = <strong>' + esc(nilaiDet.nilai) + '</strong>' +
                              '</div>'
                            : (nilaiDet.nilai_remedial ? '<div style="font-size:11px;color:#0F766E;">Nilai Awal: ' + esc(nilaiDet.nilai) + ' &bull; Remedial: <strong>' + esc(nilaiDet.nilai_remedial) + '</strong></div>' : '');

                        const showScore = (nilaiDet.nilai_akhir_remedial !== null && nilaiDet.nilai_akhir_remedial !== undefined) ? nilaiDet.nilai_akhir_remedial : nilaiDet.nilai;
                        nilaiBlock =
                            '<div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:5px;">' +
                                '<strong style="font-size:15px;color:#047857;">Nilai Akhir: ' + esc(showScore) + '</strong>' +
                                (nilaiDet.predikat ? '<span class="pry-badge pry-badge--navy">Predikat ' + esc(nilaiDet.predikat) + '</span>' : '') +
                                predikatBadge(nilaiDet.predikat_p5 || sub.predikat_proyek) +
                                (nilaiDet.aspek_itp_ketercapaian ? '<span class="pry-badge pry-badge--teal">' + esc(nilaiDet.aspek_itp_ketercapaian) + '</span>' : '') +
                            '</div>' + formulaLine;
                    } else if (sub.nilai_proyek !== null && sub.nilai_proyek !== '') {
                        nilaiBlock =
                            '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:4px;">' +
                                '<strong style="font-size:15px;color:#047857;">Nilai: ' + esc(sub.nilai_proyek) + '</strong>' +
                                predikatBadge(sub.predikat_proyek) +
                            '</div>';
                    }

                    const evalNote = (nilaiDet && nilaiDet.catatan_guru) ? nilaiDet.catatan_guru : (sub.catatan_evaluasi_guru || '');
                    subBoxHtml =
                        '<div class="pry-sub-status-box ' + (isRev ? 'pry-sub-status-box--rev' : 'pry-sub-status-box--done') + '">' +
                            '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">' +
                                '<strong>' + esc(sub.judul_laporan_produk) + '</strong>' +
                                '<div>' + statusBadge(sub.status_pengumpulan) + lateTag + revTag + '</div>' +
                            '</div>' +
                            '<div style="font-size:11.5px;color:#475569;">Dikumpulkan: ' + formatDateIndo(sub.waktu_pengumpulan) + (sub.nama_pengumpul ? ' oleh ' + esc(sub.nama_pengumpul) : '') + '</div>' +
                            (subLinks.length ? '<div>' + subLinks.join('') + '</div>' : '') +
                            nilaiBlock +
                            (evalNote ? '<div style="font-size:11.5px;color:#1E293B;background:rgba(255,255,255,0.75);padding:6px 8px;border-radius:8px;border:1px solid rgba(15,23,42,0.08);"><strong>Catatan Guru:</strong> ' + esc(evalNote) + '</div>' : '') +
                        '</div>';
                } else {
                    subBoxHtml =
                        '<div class="pry-sub-status-box">' +
                            '<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">' +
                                '<span style="font-weight:700;color:#64748B;">Status Pengumpulan</span>' +
                                '<span class="pry-badge pry-badge--amber">Belum Dikumpulkan</span>' +
                            '</div>' +
                        '</div>';
                }

                if (!canUploadByRole && !btnDisabled) {
                    btnDisabled = true;
                    btnCls = 'ilmuna-btn--light-outline';
                    btnLabel = team ? 'Diunggah oleh Ketua' : 'Menunggu Kelompok';
                    btnHint = team && team.nama_ketua ? ('Ketua: ' + team.nama_ketua) : '';
                }

                return '<article class="pry-card">' +
                    '<div class="pry-card-head">' +
                        '<div style="min-width:0;flex:1;">' +
                            '<h3 class="pry-card-title">' + esc(p.nama_proyek) + '</h3>' +
                            '<div class="pry-card-sub">' +
                                '<span><iconify-icon icon="solar:book-2-bold"></iconify-icon> ' + esc(p.nama_mata_pelajaran) + '</span>' +
                                '<span>&bull;</span>' +
                                '<span>' + esc(p.nama_guru || 'Guru') + '</span>' +
                            '</div>' +
                        '</div>' +
                        '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;">' +
                            statusBadge(p.status_proyek) +
                            '<span class="pry-badge ' + (isKelompok ? 'pry-badge--navy' : 'pry-badge--purple') + '">' + esc(p.jenis_proyek) + '</span>' +
                        '</div>' +
                    '</div>' +
                    modulStrip +
                    teamStrip +
                    '<div class="pry-card-desc">' + esc(p.deskripsi_tujuan || '-') + '</div>' +
                    '<div class="pry-card-meta-grid">' +
                        '<div class="pry-card-meta-item"><iconify-icon icon="solar:palette-bold"></iconify-icon><span>Tema: ' + esc(p.tema_proyek) + '</span></div>' +
                        '<div class="pry-card-meta-item"><iconify-icon icon="solar:box-bold"></iconify-icon><span>Luaran: ' + esc(p.target_luaran) + '</span></div>' +
                        '<div class="pry-card-meta-item"><iconify-icon icon="solar:calendar-bold"></iconify-icon><span>Mulai: ' + formatDateIndo(p.jadwal_mulai) + '</span></div>' +
                        '<div class="pry-card-meta-item"><iconify-icon icon="solar:alarm-bold"></iconify-icon><span>Tenggat: ' + formatDateIndo(p.jadwal_selesai) + '</span></div>' +
                    '</div>' +
                    p5Html +
                    (links.length ? '<div>' + links.join('') + '</div>' : '') +
                    subBoxHtml +
                    '<div class="pry-card-footer">' +
                        '<span style="font-size:11.5px;color:#64748B;font-weight:600;">' + (btnHint ? esc(btnHint) : ('Nilai Maks: ' + esc(p.nilai_maksimal || 100))) + '</span>' +
                        '<button type="button" class="ilmuna-btn ' + btnCls + ' ilmuna-btn--sm" onclick="SPRY.openSubmitModal(' + p.id + ')"' + (btnDisabled ? ' disabled' : '') + '>' +
                            '<iconify-icon icon="' + (btnDisabled ? 'solar:lock-keyhole-bold' : 'solar:upload-bold') + '"></iconify-icon>' +
                            '<span>' + esc(btnLabel) + '</span>' +
                        '</button>' +
                    '</div>' +
                '</article>';
            }).join('');
        },

        openSubmitModal(proyekId) {
            const p = this.state.items.find(function (x) { return Number(x.id) === Number(proyekId); });
            if (!p) return;
            const sub = p.submission || null;
            const team = p.my_team || null;

            if (p.jenis_proyek === 'Kelompok' && (!team || !team.is_me_ketua)) {
                notify('warning', team && team.nama_ketua
                    ? ('Hanya Ketua Kelompok (' + team.nama_ketua + ') yang berhak mengunggah hasil karya kelompok ini.')
                    : 'Anda belum tergabung dalam kelompok proyek ini. Hubungi guru pembimbing.');
                return;
            }

            document.getElementById('sprySubForm').reset();
            document.getElementById('sprySubProyekId').value = String(p.id);
            document.getElementById('sprySubModalTitle').textContent = sub ? 'Perbarui / Revisi Karya Proyek' : 'Kumpulkan Hasil Karya Proyek';
            document.getElementById('sprySubModalSub').textContent = p.nama_proyek + ' • ' + p.nama_mata_pelajaran;

            document.getElementById('sprySubContextInfo').innerHTML =
                '<strong>' + esc(p.nama_proyek) + '</strong> (' + esc(p.jenis_proyek) + ')' +
                (team ? ('<br>Kelompok Anda: <strong>' + esc(team.nama_kelompok) + '</strong> — Sebagai Ketua Kelompok, unggahan ini otomatis tercatat untuk seluruh anggota tim.') : '') +
                '<br>Target Luaran: ' + esc(p.target_luaran) + ' &bull; Tenggat: <strong>' + formatDateIndo(p.jadwal_selesai) + '</strong>' +
                '<br>Batas ukuran berkas unggahan lokal: <strong>maksimal 1 MB</strong> (untuk berkas besar gunakan Tautan Google Drive / YouTube / Canva).' +
                (p.pedoman_penilaian_rubrik ? ('<br>Rubrik: ' + esc(p.pedoman_penilaian_rubrik)) : '');

            document.getElementById('sprySubJudul').value = sub ? (sub.judul_laporan_produk || '') : '';
            document.getElementById('sprySubDesc').value = sub ? (sub.deskripsi_produk || '') : '';
            document.getElementById('sprySubLinkProduk').value = sub ? (sub.link_produk || '') : '';
            document.getElementById('sprySubLinkVideo').value = sub ? (sub.link_video_presentasi || '') : '';
            const catEl = document.getElementById('sprySubCatatan');
            if (catEl) catEl.value = sub ? (sub.catatan_siswa || '') : '';

            document.getElementById('sprySubExistingLap').innerHTML = (sub && sub.file_laporan)
                ? '<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + sub.file_laporan.replace(/^\/+/, '')) + '" target="_blank"><iconify-icon icon="solar:document-text-bold"></iconify-icon> ' + esc(sub.file_nama_asli || 'Berkas laporan tersimpan') + '</a>'
                : '';
            document.getElementById('sprySubExistingDok').innerHTML = (sub && sub.file_dokumentasi)
                ? '<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + sub.file_dokumentasi.replace(/^\/+/, '')) + '" target="_blank"><iconify-icon icon="solar:gallery-bold"></iconify-icon> Berkas dokumentasi tersimpan</a>'
                : '';

            if (typeof window.openIlmunaModal === 'function') {
                window.openIlmunaModal('modalSiswaSubmitProyek');
            }
        },

        async submitKarya(e) {
            e.preventDefault();
            const fLap = document.getElementById('sprySubFileLap');
            const fDok = document.getElementById('sprySubFileDok');
            if (fLap && fLap.files && fLap.files[0] && fLap.files[0].size > 1024 * 1024) {
                notify('warning', 'Ukuran berkas laporan melebihi batas maksimal 1 MB. Kompres berkas atau gunakan kolom Tautan Karya.');
                return;
            }
            if (fDok && fDok.files && fDok.files[0] && fDok.files[0].size > 1024 * 1024) {
                notify('warning', 'Ukuran berkas dokumentasi melebihi batas maksimal 1 MB.');
                return;
            }
            const btn = document.getElementById('sprySubSubmitBtn');
            const orig = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = 'Mengunggah...'; }
            try {
                const fd = new FormData(document.getElementById('sprySubForm'));
                const res = await apiPost('submit_karya', fd, true);
                if (typeof window.closeIlmunaModal === 'function') window.closeIlmunaModal('modalSiswaSubmitProyek');
                notify('success', res.message || 'Karya proyek berhasil dikumpulkan!');
                await this.loadMyProyek();
            } catch (err) {
                notify('error', err.message || 'Gagal mengumpulkan karya proyek.');
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = orig; }
            }
        },

        renderMyTeams() {
            const wrap = document.getElementById('spryTeamContainer');
            if (!wrap) return;
            const teams = this.state.allItems.filter(function (p) { return p.my_team; });
            if (!teams.length) {
                wrap.innerHTML =
                    '<div class="pry-empty">' +
                        '<iconify-icon icon="solar:users-group-rounded-bold-duotone"></iconify-icon>' +
                        '<h4>Belum Tergabung dalam Kelompok Proyek</h4>' +
                        '<p>Daftar kelompok beserta peran Anda akan muncul otomatis setelah guru membagi kelompok proyek.</p>' +
                    '</div>';
                return;
            }

            wrap.innerHTML = '<div class="pry-team-grid">' + teams.map(function (p) {
                const t = p.my_team;
                const members = t.anggota || [];
                const memHtml = members.map(function (a) {
                    const isKetua = Number(t.id_ketua_kelompok) === Number(a.id_siswa);
                    const isMe = Number(a.id_siswa) === Number(CFG.siswaId);
                    return '<div class="pry-member-row" style="' + (isMe ? 'border-color:#0D7A87;background:#F0FDFA;' : '') + '">' +
                        '<div class="pry-member-info">' +
                            '<div class="pry-member-avatar">' + esc(initials(a.nama_siswa)) + '</div>' +
                            '<div style="min-width:0;">' +
                                '<div class="pry-member-name">' + esc(a.nama_siswa) + (isMe ? ' <span class="pry-badge pry-badge--teal">Saya</span>' : '') + (isKetua ? ' <span class="pry-badge pry-badge--amber">Ketua</span>' : '') + '</div>' +
                                '<div class="pry-member-role">' + esc(a.peran_anggota || 'Anggota') + ' &bull; NIS ' + esc(a.nis || '-') + '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
                }).join('');

                return '<div class="pry-team-card">' +
                    '<div class="pry-team-head">' +
                        '<div>' +
                            '<span class="pry-badge pry-badge--teal">Kelompok ' + esc(t.nomor_kelompok) + ' • ' + esc(p.nama_mata_pelajaran) + '</span>' +
                            '<h4 class="pry-team-title" style="margin-top:5px;">' + esc(t.nama_kelompok) + '</h4>' +
                            '<div style="font-size:11.5px;color:#64748B;margin-top:2px;">Proyek: ' + esc(p.nama_proyek) + '</div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="pry-team-members">' + memHtml + '</div>' +
                '</div>';
            }).join('') + '</div>';
        },

        async loadMyBiaya() {
            const wrap = document.getElementById('spryBiayaContainer');
            if (!wrap) return;
            wrap.innerHTML = '<div class="pry-empty"><iconify-icon icon="solar:refresh-bold"></iconify-icon><h4>Memuat Catatan Biaya...</h4></div>';
            try {
                const pid = parseInt((document.getElementById('spryBiayaProyekFilter') || {}).value || '0', 10);
                const res = await apiPost('list_my_biaya', { id_proyek: pid });
                const d = res.data || {};
                this.state.biayaList = d.items || [];
                this.renderBiaya(d.summary || {});
            } catch (e) {
                wrap.innerHTML = '<div class="pry-empty"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon><h4>Gagal Memuat Biaya</h4><p>' + esc(e.message) + '</p></div>';
            }
        },

        renderBiaya(sum) {
            const wrap = document.getElementById('spryBiayaContainer');
            if (!wrap) return;
            const rows = this.state.biayaList;
            const strip =
                '<div class="pry-budget-strip">' +
                    '<div class="pry-budget-box"><div class="pry-budget-box__lbl">Diverifikasi Guru</div><div class="pry-budget-box__val" style="color:#047857;">' + formatRupiah(sum.verified || 0) + '</div></div>' +
                    '<div class="pry-budget-box"><div class="pry-budget-box__lbl">Menunggu Verifikasi</div><div class="pry-budget-box__val" style="color:#B45309;">' + formatRupiah(sum.pending || 0) + '</div></div>' +
                    '<div class="pry-budget-box"><div class="pry-budget-box__lbl">Total Transaksi</div><div class="pry-budget-box__val">' + rows.length + ' Item</div></div>' +
                '</div>';

            if (!rows.length) {
                wrap.innerHTML = strip +
                    '<div class="pry-empty">' +
                        '<iconify-icon icon="solar:bill-list-bold-duotone"></iconify-icon>' +
                        '<h4>Belum Ada Catatan Pengeluaran</h4>' +
                        '<p>Catat pengeluaran bahan atau perlengkapan proyek Anda agar transparan dan diverifikasi guru pembimbing.</p>' +
                    '</div>';
                return;
            }

            wrap.innerHTML = strip +
                '<div class="pry-table-wrap"><table class="pry-table">' +
                    '<thead><tr>' +
                        '<th>Tanggal</th>' +
                        '<th>Proyek &amp; Item</th>' +
                        '<th>Qty &times; Harga</th>' +
                        '<th>Total &amp; Sumber</th>' +
                        '<th>Bukti</th>' +
                        '<th>Verifikasi</th>' +
                    '</tr></thead><tbody>' +
                    rows.map(function (b) {
                        const buktiHtml = b.bukti_transaksi
                            ? '<a class="pry-link-chip" href="' + esc(CFG.baseUrl + '/' + b.bukti_transaksi.replace(/^\/+/, '')) + '" target="_blank"><iconify-icon icon="solar:gallery-bold"></iconify-icon> Struk</a>'
                            : '<span style="color:#94A3B8;font-size:11.5px;">-</span>';
                        return '<tr>' +
                            '<td style="white-space:nowrap;">' + formatDateIndo(b.tanggal_pengeluaran) + '</td>' +
                            '<td><strong>' + esc(b.nama_item_biaya) + '</strong><div style="font-size:11px;color:#64748B;">' + esc(b.nama_proyek) + ' &bull; ' + esc(b.kategori_biaya) + '</div></td>' +
                            '<td style="white-space:nowrap;">' + Number(b.kuantitas) + ' ' + esc(b.satuan || 'pcs') + ' &times; ' + formatRupiah(b.harga_satuan) + '</td>' +
                            '<td><strong>' + formatRupiah(b.total_biaya) + '</strong><div style="font-size:11px;color:#64748B;">' + esc(b.sumber_dana) + '</div></td>' +
                            '<td>' + buktiHtml + '</td>' +
                            '<td>' + statusBadge(b.status_verifikasi) + '</td>' +
                        '</tr>';
                    }).join('') +
                '</tbody></table></div>';
        },

        openBiayaModal() {
            if (!this.state.allItems.length) {
                notify('warning', 'Belum ada proyek aktif untuk dicatat biayanya.');
                return;
            }
            document.getElementById('spryBiayaForm').reset();
            document.getElementById('spryBiayaTgl').value = new Date().toISOString().slice(0, 10);
            document.getElementById('spryBiayaQty').value = '1';
            document.getElementById('spryBiayaSatuan').value = 'paket';
            document.getElementById('spryBiayaHarga').value = '0';
            this.recalcBiayaPreview();
            if (typeof window.openIlmunaModal === 'function') window.openIlmunaModal('modalSiswaBiaya');
            if (window.IlmunaCalendar && typeof window.IlmunaCalendar.bind === 'function') {
                window.IlmunaCalendar.bind('#spryBiayaTgl', false, 'date');
            }
        },

        recalcBiayaPreview() {
            const q = parseFloat(document.getElementById('spryBiayaQty').value || '0') || 0;
            const h = parseFloat(document.getElementById('spryBiayaHarga').value || '0') || 0;
            const el = document.getElementById('spryBiayaTotalPreview');
            if (el) el.textContent = formatRupiah(q * h);
        },

        async submitBiaya(e) {
            e.preventDefault();
            const btn = document.getElementById('spryBiayaSubmitBtn');
            const orig = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = 'Menyimpan...'; }
            try {
                const fd = new FormData(document.getElementById('spryBiayaForm'));
                const res = await apiPost('save_my_biaya', fd, true);
                if (typeof window.closeIlmunaModal === 'function') window.closeIlmunaModal('modalSiswaBiaya');
                notify('success', res.message || 'Biaya proyek berhasil dicatat.');
                await this.loadMyBiaya();
            } catch (err) {
                notify('error', err.message || 'Gagal mencatat biaya proyek.');
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = orig; }
            }
        }
    };

    window.SPRY = SPRY;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { SPRY.init(); });
    } else {
        SPRY.init();
    }
})();
