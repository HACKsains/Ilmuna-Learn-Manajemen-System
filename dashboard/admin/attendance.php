<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — REKAP ABSENSI & MONITORING PERIZINAN (ADMIN)
 * ==============================================================================
 * File ini menggantikan tampilan lama "Jurnal Mengajar & Rekap Presensi PAI"
 * (view attendances) dengan rekap grid lengkap + monitoring perizinan.
 *
 * ATURAN TAMPILAN:
 *  - SEMUA siswa di kelas selalu tampil, tanpa terkecuali.
 *  - Cell yang belum ada record → tampil "·" (abu-abu putus-putus).
 *  - Statistik H/I/S/A/T siswa tanpa record → 0, dan % = 0%.
 *  - Kalau mapel belum punya pertemuan sama sekali, tetap render kolom
 *    H / I / S / A / T / % dengan nilai 0 (layout konsisten).
 *  - data-ajax = $_SERVER['REQUEST_URI'] → POST selalu sampai ke file ini.
 *  - Semua error PHP dikembalikan sebagai JSON dengan pesan asli.
 *
 * EXPORT:
 *  - Excel (.xlsx) via backend terpisah `attendance-excel.php`
 *    (2 sheet: Rekap Absensi + Info Pertemuan, tema Lumina Gold)
 *
 * SUMBER DATA:
 *  - `absensi`             : status H/I/S/A/T, koordinat, jarak_meter, dll
 *  - `jadwal_pelajaran`    : pertemuan_ke, tanggal, jam, materi, status
 *  - `perizinan`           : jenis_izin, alasan, bukti, approval ketua & guru
 *  - `kelas_mata_pelajaran`, `kelas`, `mata_pelajaran`, `siswa`, `guru`
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('admin');

$pdo = getDBConnection();
$pageTitle = 'Rekap Absensi & Monitoring Perizinan';
$pageIcon  = 'solar:checklist-minimalistic-bold';

/* ==============================================================================
 * 1. AJAX HANDLERS
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    set_error_handler(function ($no, $str, $file, $line) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'data'    => [],
            'message' => 'PHP Error: ' . $str . ' @ ' . basename($file) . ':' . $line,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'data'    => [],
                'message' => 'Fatal: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line'],
            ], JSON_UNESCAPED_UNICODE);
        }
    });

    function sendRes($success, $data = [], $message = '') {
        echo json_encode(['success' => $success, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $act = $_POST['action'];

    /* ---------- 1.1 LIST MAPEL BY KELAS ---------- */
    if ($act === 'get_mapel_by_kelas') {
        $idKelas = (int)($_POST['kelas_id'] ?? 0);
        if (!$idKelas) sendRes(false, [], 'Pilih kelas terlebih dahulu.');

        try {
            $stmt = $pdo->prepare("
                SELECT kmp.id AS id_kmp, kmp.id_mata_pelajaran,
                       mp.nama_mata_pelajaran, mp.kode_mapel, mp.semester
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id_kelas = ?
                ORDER BY mp.nama_mata_pelajaran ASC
            ");
            $stmt->execute([$idKelas]);
            sendRes(true, $stmt->fetchAll(PDO::FETCH_ASSOC), 'OK');
        } catch (Throwable $e) {
            sendRes(false, [], 'Gagal memuat mapel: ' . $e->getMessage());
        }
    }

    /* ---------- 1.2 LOAD REKAP GRID ---------- */
    if ($act === 'load_rekap_grid') {
        $idKmp = (int)($_POST['kmp_id'] ?? 0);
        if (!$idKmp) sendRes(false, [], 'Pilih mata pelajaran terlebih dahulu.');

        try {
            $stmtInfo = $pdo->prepare("
                SELECT kmp.id, kmp.id_kelas, k.nama_kelas, k.tahun_pelajaran,
                       mp.nama_mata_pelajaran, mp.semester,
                       COALESCE(g.nama_guru, 'Guru Pengampu') AS nama_guru
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id = ?
            ");
            $stmtInfo->execute([$idKmp]);
            $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
            if (!$info) sendRes(false, [], 'Data kelas-mapel tidak ditemukan.');

            /* SEMUA siswa di kelas */
            $stmtSiswa = $pdo->prepare("
                SELECT id, nis, nisn, nama_lengkap, foto
                FROM siswa
                WHERE id_kelas = ?
                ORDER BY nama_lengkap ASC
            ");
            $stmtSiswa->execute([$info['id_kelas']]);
            $siswaList = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);

            /* Pertemuan: utamakan jadwal_pelajaran */
            $pertemuanList = [];
            try {
                $stmtPertemuan = $pdo->prepare("
                    SELECT jp.pertemuan_ke,
                           MIN(jp.tanggal)           AS tanggal,
                           MIN(jp.jam_mulai)         AS jam_mulai,
                           MIN(jp.jam_selesai)       AS jam_selesai,
                           MAX(jp.materi_pembahasan) AS materi,
                           MAX(jp.status_jadwal)     AS status_jadwal
                    FROM jadwal_pelajaran jp
                    WHERE jp.id_kelas_mata_pelajaran = ?
                    GROUP BY jp.pertemuan_ke
                    ORDER BY jp.pertemuan_ke ASC
                ");
                $stmtPertemuan->execute([$idKmp]);
                $pertemuanList = $stmtPertemuan->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $pertemuanList = [];
            }

            /* Fallback: derive dari absensi */
            if (empty($pertemuanList)) {
                $stmtPertemuan = $pdo->prepare("
                    SELECT pertemuan_ke, MIN(DATE(waktu_absen)) AS tanggal
                    FROM absensi
                    WHERE id_kelas_mata_pelajaran = ?
                    GROUP BY pertemuan_ke
                    ORDER BY pertemuan_ke ASC
                ");
                $stmtPertemuan->execute([$idKmp]);
                $pertemuanList = $stmtPertemuan->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pertemuanList as &$p) {
                    $p['jam_mulai'] = null;
                    $p['jam_selesai'] = null;
                    $p['materi'] = null;
                    $p['status_jadwal'] = null;
                }
                unset($p);
            }

            /* Absensi indexed */
            $stmtAbsensi = $pdo->prepare("
                SELECT id, id_siswa, id_jadwal, pertemuan_ke, status, keterangan,
                       latitude, longitude, jarak_meter, waktu_absen,
                       metode_absen, id_perizinan
                FROM absensi
                WHERE id_kelas_mata_pelajaran = ?
            ");
            $stmtAbsensi->execute([$idKmp]);
            $absensiRows = $stmtAbsensi->fetchAll(PDO::FETCH_ASSOC);

            $absensiIndexed = [];
            foreach ($absensiRows as $a) {
                $absensiIndexed[(int)$a['id_siswa']][(int)$a['pertemuan_ke']] = $a;
            }

            ob_start();
            ?>
            <div class="ilmuna-abs-table-wrap">
                <?php if (!empty($siswaList) && empty($pertemuanList)): ?>
                    <div style="padding:12px 18px; background:#FFFBEB; border-bottom:1px solid #FDE68A;
                                font-size:12.5px; color:#92400E; display:flex; align-items:center; gap:8px;">
                        <iconify-icon icon="solar:info-circle-bold" width="16"></iconify-icon>
                        <span>Belum ada pertemuan KBM untuk mapel ini. Kolom kehadiran akan terisi
                              setelah guru membuat jadwal atau mencatat absensi.</span>
                    </div>
                <?php endif; ?>

                <table class="ilmuna-abs-table">
                    <thead>
                        <tr>
                            <th class="col-siswa">Siswa</th>
                            <?php if (!empty($pertemuanList)): ?>
                                <?php foreach ($pertemuanList as $p): ?>
                                    <th class="col-tgl" title="<?= htmlspecialchars($p['materi'] ?: '') ?>">
                                        <div class="col-tgl-inner">
                                            <span class="col-tgl-date">P<?= (int)$p['pertemuan_ke'] ?></span>
                                            <span class="col-tgl-day">
                                                <?= $p['tanggal'] ? date('d/m', strtotime($p['tanggal'])) : '—' ?>
                                            </span>
                                        </div>
                                    </th>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <th class="col-stat">H</th>
                            <th class="col-stat">I</th>
                            <th class="col-stat">S</th>
                            <th class="col-stat">A</th>
                            <th class="col-stat">T</th>
                            <th class="col-stat">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($siswaList)): ?>
                            <tr><td colspan="<?= count($pertemuanList) + 7 ?>" class="empty-state">
                                <iconify-icon icon="solar:users-group-rounded-bold" width="42"></iconify-icon>
                                <p>Belum ada siswa di rombel ini</p>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($siswaList as $s):
                                $h = $i = $sk = $a = $t = 0;
                                $total = count($pertemuanList);
                                foreach ($pertemuanList as $p) {
                                    $ab = $absensiIndexed[(int)$s['id']][(int)$p['pertemuan_ke']] ?? null;
                                    if (!$ab) continue;
                                    switch ($ab['status']) {
                                        case 'H': $h++; break;
                                        case 'I': $i++; break;
                                        case 'S': $sk++; break;
                                        case 'A': $a++; break;
                                        case 'T': $t++; break;
                                    }
                                }
                                $pct = $total > 0 ? round((($h + $t) / $total) * 100, 1) : 0;
                            ?>
                                <tr>
                                    <td class="col-siswa">
                                        <div class="siswa-mini">
                                            <div class="siswa-mini__avatar">
                                                <?php if (!empty($s['foto'])): ?>
                                                    <img src="<?= htmlspecialchars(BASE_URL . '/' . $s['foto']) ?>" alt="">
                                                <?php else: ?>
                                                    <?= strtoupper(mb_substr($s['nama_lengkap'], 0, 1)) ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="siswa-mini__info">
                                                <strong><?= htmlspecialchars($s['nama_lengkap']) ?></strong>
                                                <span><?= htmlspecialchars($s['nisn'] ?: $s['nis']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <?php if (!empty($pertemuanList)): ?>
                                        <?php foreach ($pertemuanList as $p):
                                            $ab = $absensiIndexed[(int)$s['id']][(int)$p['pertemuan_ke']] ?? null;
                                            $st = $ab['status'] ?? '';
                                            $cls = $st ? 'cell-' . strtolower($st) : 'cell-empty';
                                            $title = 'Belum ada keterangan';
                                            if ($ab) {
                                                $parts = [];
                                                if (!empty($ab['waktu_absen'])) $parts[] = date('d M Y H:i', strtotime($ab['waktu_absen']));
                                                if (!empty($ab['metode_absen'])) $parts[] = $ab['metode_absen'];
                                                if (!empty($ab['keterangan']))    $parts[] = $ab['keterangan'];
                                                if (!empty($ab['jarak_meter']))   $parts[] = round($ab['jarak_meter']) . 'm dari titik';
                                                $title = implode(' · ', $parts) ?: 'Tercatat';
                                            }
                                        ?>
                                            <td class="cell-abs <?= $cls ?>">
                                                <div class="cell-abs-inner" title="<?= htmlspecialchars($title) ?>">
                                                    <?= $st ?: '·' ?>
                                                </div>
                                            </td>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <td class="col-stat"><strong class="stat-H"><?= $h ?></strong></td>
                                    <td class="col-stat"><strong class="stat-I"><?= $i ?></strong></td>
                                    <td class="col-stat"><strong class="stat-S"><?= $sk ?></strong></td>
                                    <td class="col-stat"><strong class="stat-A"><?= $a ?></strong></td>
                                    <td class="col-stat"><strong class="stat-T"><?= $t ?></strong></td>
                                    <td class="col-stat">
                                        <strong class="<?= $pct >= 75 ? 'stat-good' : 'stat-bad' ?>"><?= $pct ?>%</strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $gridHtml = ob_get_clean();

            sendRes(true, [
                'grid_html'      => $gridHtml,
                'kmp_info'       => $info,
                'jml_siswa'      => count($siswaList),
                'jml_pertemuan'  => count($pertemuanList),
            ], 'OK');
        } catch (Throwable $e) {
            sendRes(false, [], 'Gagal memuat rekap: ' . $e->getMessage());
        }
    }

    /* ---------- 1.3 LOAD IZIN LIST ---------- */
    if ($act === 'load_izin_list') {
        $idKmp  = (int)($_POST['kmp_id'] ?? 0);
        $filter = $_POST['filter_status'] ?? 'all';
        if (!$idKmp) sendRes(false, [], 'Pilih mata pelajaran terlebih dahulu.');

        try {
            $where = "p.id_kelas_mata_pelajaran = ?";
            $params = [$idKmp];
            if (in_array($filter, ['pending', 'approved', 'rejected'], true)) {
                if ($filter === 'pending') {
                    $where .= " AND p.status_perizinan IN ('Menunggu Persetujuan Ketua Kelas','Menunggu Persetujuan Guru')";
                } elseif ($filter === 'approved') {
                    $where .= " AND p.status_perizinan = 'Disetujui'";
                } else {
                    $where .= " AND p.status_perizinan = 'Ditolak'";
                }
            }

            $stmt = $pdo->prepare("
                SELECT p.*,
                       s.nama_lengkap, s.nis, s.nisn, s.foto,
                       jp.pertemuan_ke AS jp_pertemuan_ke,
                       jp.tanggal     AS tgl_pertemuan,
                       uk.full_name   AS nama_ketua,
                       ug.full_name   AS nama_guru
                FROM perizinan p
                JOIN siswa s ON p.id_siswa = s.id
                LEFT JOIN jadwal_pelajaran jp ON p.id_jadwal = jp.id
                LEFT JOIN users uk ON p.id_ketua_kelas = uk.id
                LEFT JOIN users ug ON p.id_guru = ug.id
                WHERE {$where}
                ORDER BY p.created_at DESC
                LIMIT 200
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $statusMap = [
                'Menunggu Persetujuan Ketua Kelas' => ['label' => 'Pending Ketua', 'cls' => 'pending'],
                'Menunggu Persetujuan Guru'        => ['label' => 'Pending Guru',  'cls' => 'pending'],
                'Disetujui'                        => ['label' => 'Disetujui',     'cls' => 'approved'],
                'Ditolak'                          => ['label' => 'Ditolak',       'cls' => 'rejected'],
            ];

            ob_start();
            if (empty($rows)):
                ?>
                <div class="ilmuna-izin-empty">
                    <iconify-icon icon="solar:check-circle-bold" width="44"></iconify-icon>
                    <p>Tidak ada data perizinan</p>
                    <small>Belum ada pengajuan izin untuk kelas-mapel ini.</small>
                </div>
                <?php
            else:
                foreach ($rows as $iz):
                    $initials = '';
                    $parts = preg_split('/\s+/', trim($iz['nama_lengkap']));
                    if (!empty($parts[0])) $initials .= mb_substr($parts[0], 0, 1);
                    if (!empty($parts[1])) $initials .= mb_substr($parts[1], 0, 1);
                    $initials = mb_strtoupper($initials);
                    $stMeta = $statusMap[$iz['status_perizinan']] ?? ['label' => $iz['status_perizinan'], 'cls' => 'default'];
                    $jenisLabel = $iz['jenis_izin'] === 'Sakit' ? 'SAKIT' : 'IZIN';
                    $jenisCls   = $iz['jenis_izin'] === 'Sakit' ? 's' : 'i';
                    $pertemuanKe = $iz['jp_pertemuan_ke'] ?: $iz['pertemuan_ke'];
                    ?>
                    <div class="ilmuna-izin-item" data-izin-id="<?= (int)$iz['id'] ?>">
                        <div class="ilmuna-izin-item__user">
                            <div class="ilmuna-izin-item__avatar">
                                <?php if (!empty($iz['foto'])): ?>
                                    <img src="<?= htmlspecialchars(BASE_URL . '/' . $iz['foto']) ?>" alt="avatar" onerror="this.style.display='none'; this.parentNode.innerHTML='<?= htmlspecialchars($initials) ?>';">
                                <?php else: ?>
                                    <?= htmlspecialchars($initials) ?>
                                <?php endif; ?>
                            </div>
                            <div class="ilmuna-izin-item__body">
                                <div class="ilmuna-izin-item__title">
                                    <?= htmlspecialchars($iz['nama_lengkap']) ?>
                                    <span class="ilmuna-izin-item__nim"><?= htmlspecialchars($iz['nisn'] ?: $iz['nis']) ?></span>
                                </div>
                                <div class="ilmuna-izin-item__meta">
                                    <span><iconify-icon icon="solar:calendar-bold" width="12"></iconify-icon> <?= date('d M Y', strtotime($iz['tanggal_izin'])) ?></span>
                                    <?php if (!empty($pertemuanKe)): ?>
                                        <span><iconify-icon icon="solar:notebook-bold" width="12"></iconify-icon> P<?= (int)$pertemuanKe ?></span>
                                    <?php endif; ?>
                                    <span><iconify-icon icon="solar:clock-circle-bold" width="12"></iconify-icon> <?= date('H:i', strtotime($iz['created_at'] ?? 'now')) ?></span>
                                </div>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-end;">
                                <span class="ilmuna-izin-pill ilmuna-izin-pill--<?= $jenisCls ?>"><?= $jenisLabel ?></span>
                                <span class="ilmuna-izin-status ilmuna-izin-status--<?= $stMeta['cls'] ?>"><?= htmlspecialchars($stMeta['label']) ?></span>
                            </div>
                        </div>
                        <?php if (!empty($iz['alasan'])): ?>
                            <div class="ilmuna-izin-alasan"><strong>Alasan:</strong> <?= htmlspecialchars($iz['alasan']) ?></div>
                        <?php endif; ?>
                        <div class="ilmuna-izin-actions">
                            <button type="button" class="ilmuna-izin-btn ilmuna-izin-btn--ghost"
                                data-act="view-detail"
                                data-nama="<?= htmlspecialchars($iz['nama_lengkap']) ?>"
                                data-nim="<?= htmlspecialchars($iz['nisn'] ?: $iz['nis']) ?>"
                                data-jenis="<?= htmlspecialchars($jenisLabel) ?>"
                                data-tanggal="<?= date('d M Y', strtotime($iz['tanggal_izin'])) ?>"
                                data-alasan="<?= htmlspecialchars($iz['alasan'] ?? '') ?>"
                                data-foto="<?= htmlspecialchars(!empty($iz['bukti_perizinan']) ? BASE_URL . '/' . $iz['bukti_perizinan'] : '') ?>"
                                data-lat="<?= htmlspecialchars($iz['latitude'] ?? '') ?>"
                                data-lng="<?= htmlspecialchars($iz['longitude'] ?? '') ?>"
                                data-alamat="<?= htmlspecialchars($iz['alamat_izin'] ?? '') ?>"
                                data-ketua-status="<?= htmlspecialchars($iz['approval_ketua_kelas']) ?>"
                                data-ketua-nama="<?= htmlspecialchars($iz['nama_ketua'] ?? '') ?>"
                                data-ketua-waktu="<?= !empty($iz['waktu_approval_ketua']) ? date('d M Y H:i', strtotime($iz['waktu_approval_ketua'])) : '' ?>"
                                data-ketua-catatan="<?= htmlspecialchars($iz['catatan_ketua_kelas'] ?? '') ?>"
                                data-guru-status="<?= htmlspecialchars($iz['approval_guru']) ?>"
                                data-guru-nama="<?= htmlspecialchars($iz['nama_guru'] ?? '') ?>"
                                data-guru-waktu="<?= !empty($iz['waktu_approval_guru']) ? date('d M Y H:i', strtotime($iz['waktu_approval_guru'])) : '' ?>"
                                data-guru-catatan="<?= htmlspecialchars($iz['catatan_guru'] ?? '') ?>">
                                <iconify-icon icon="solar:eye-bold" width="14"></iconify-icon>
                                Detail
                            </button>
                            <?php if (!empty($iz['bukti_perizinan'])): ?>
                                <button type="button" class="ilmuna-izin-btn ilmuna-izin-btn--ghost"
                                    data-act="view-bukti"
                                    data-foto="<?= htmlspecialchars(BASE_URL . '/' . $iz['bukti_perizinan']) ?>"
                                    data-nama="<?= htmlspecialchars($iz['nama_lengkap']) ?>">
                                    <iconify-icon icon="solar:camera-bold" width="14"></iconify-icon>
                                    Bukti
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                endforeach;
            endif;
            $html = ob_get_clean();

            sendRes(true, ['list_html' => $html, 'total' => count($rows)], 'OK');
        } catch (Throwable $e) {
            sendRes(false, [], 'Gagal memuat izin: ' . $e->getMessage());
        }
    }

    /* ---------- 1.4 EXPORT: dipindah ke attendance-excel.php ---------- */
    // Handler `export_data` dihapus. Frontend mengarahkan user ke
    // attendance-excel.php?action=export_excel&kmp_id=X untuk unduh .xlsx.

    sendRes(false, [], 'Aksi tidak dikenali: ' . $act);
}

/* ==============================================================================
 * 2. MASTER DATA
 * ============================================================================== */
$kelasList = $pdo->query("
    SELECT id, nama_kelas, tahun_pelajaran
    FROM kelas
    ORDER BY nama_kelas ASC
")->fetchAll(PDO::FETCH_ASSOC);

$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-attendance-monitor.css')
    ? filemtime(BASE_PATH . '/assets/css/admin-attendance-monitor.css')
    : '1.0';

/* data-ajax = URL request saat ini (tanpa query string) */
$selfUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
if (!$selfUrl) $selfUrl = BASE_URL . '/dashboard/admin/attendance';

/* URL absolut ke backend Excel terpisah */
$excelUrl = BASE_URL . '/dashboard/admin/attendance-excel.php';

$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-assessment-access.css?v=' . $cssVersion . '">
<style>
.ilmuna-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:20px;
    font-size:11.5px; font-weight:700; border:1px solid transparent; }
.ilmuna-badge--navy    { background:#E0E7FF; color:#1E40AF; border-color:#C7D2FE; }
.ilmuna-badge--teal    { background:#CCFBF1; color:#0F766E; border-color:#99F6E4; }
.ilmuna-badge--sky     { background:#DBEAFE; color:#1E40AF; border-color:#BFDBFE; }
.ilmuna-badge--gold    { background:#FEF3C7; color:#92400E; border-color:#FDE68A; }
.ilmuna-badge--emerald { background:#D1FAE5; color:#065F46; border-color:#A7F3D0; }

.ilmuna-abs-tabs { display:flex; gap:0; border-bottom:2px solid #E2E8F0; margin-bottom:20px; overflow-x:auto; scrollbar-width:none; }
.ilmuna-abs-tabs::-webkit-scrollbar { display:none; }
.ilmuna-abs-tab { display:inline-flex; align-items:center; gap:8px; padding:12px 20px; border:none; background:transparent;
    border-bottom:3px solid transparent; margin-bottom:-2px; font-size:13.5px; font-weight:700; color:#64748B;
    cursor:pointer; white-space:nowrap; transition:all .18s ease; }
.ilmuna-abs-tab:hover { color:#0D7A87; background:rgba(13,122,135,.05); }
.ilmuna-abs-tab.is-active { color:#0D7A87; border-bottom-color:#0D7A87; }

.ilmuna-abs-filter-card { background:#FFF; border:1px solid #E2E8F0; border-radius:14px; padding:18px 20px; margin-bottom:20px;
    box-shadow:0 2px 8px -3px rgba(15,23,42,.06); }
.ilmuna-abs-filter-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.ilmuna-abs-filter-label { display:block; font-size:11.5px; font-weight:700; color:#1D3B5E; margin-bottom:6px;
    text-transform:uppercase; letter-spacing:.05em; }
.ilmuna-abs-filter-label .req { color:#EF4444; }
.ilmuna-abs-select { width:100%; padding:10px 14px; border:1.5px solid #CBD5E1; border-radius:10px; background:#FFF;
    font-family:inherit; font-size:13.5px; font-weight:600; color:#1E293B; outline:none; transition:all .18s ease; }
.ilmuna-abs-select:focus { border-color:#0D7A87; box-shadow:0 0 0 3px rgba(13,122,135,.12); }
.ilmuna-abs-select:disabled { background:#F8FAFC; color:#94A3B8; cursor:not-allowed; }

.ilmuna-abs-table-wrap { overflow-x:auto; background:#FFF; border:1px solid #E2E8F0; border-radius:14px;
    box-shadow:0 2px 8px -3px rgba(15,23,42,.06); }
.ilmuna-abs-table { width:100%; border-collapse:separate; border-spacing:0; font-size:13px; }
.ilmuna-abs-table thead th { background:#F8FAFC; padding:12px 10px; text-align:center; font-weight:800; color:#1D3B5E;
    font-size:11px; text-transform:uppercase; letter-spacing:.04em; border-bottom:2px solid #E2E8F0;
    position:sticky; top:0; z-index:2; }
.ilmuna-abs-table thead th.col-siswa { text-align:left; padding-left:20px; min-width:240px;
    position:sticky; left:0; z-index:3; background:#F8FAFC; border-right:2px solid #E2E8F0; }
.ilmuna-abs-table thead th.col-tgl { min-width:64px; }
.col-tgl-inner { display:flex; flex-direction:column; gap:2px; }
.col-tgl-date { font-weight:800; color:#0D7A87; font-size:11.5px; }
.col-tgl-day { font-weight:600; color:#94A3B8; font-size:9.5px; }
.ilmuna-abs-table thead th.col-stat { min-width:36px; background:#F1F5F9; color:#334155; border-left:1px solid #E2E8F0; }

.ilmuna-abs-table tbody td { padding:8px 10px; text-align:center; border-bottom:1px solid #F1F5F9; }
.ilmuna-abs-table tbody td.col-siswa { padding:10px 20px; text-align:left;
    position:sticky; left:0; z-index:1; background:#FFF; border-right:2px solid #E2E8F0; }
.ilmuna-abs-table tbody tr:hover td.col-siswa { background:#F8FAFC; }
.ilmuna-abs-table tbody td.col-stat { background:#FAFBFC; border-left:1px solid #F1F5F9; font-family:monospace; font-size:12px; }
.stat-H { color:#059669; } .stat-I { color:#D97706; } .stat-S { color:#2563EB; }
.stat-A { color:#DC2626; } .stat-T { color:#7C3AED; }
.stat-good { color:#059669; } .stat-bad { color:#DC2626; }

.siswa-mini { display:flex; align-items:center; gap:10px; }
.siswa-mini__avatar { width:34px; height:34px; border-radius:10px;
    background:linear-gradient(135deg,#1D3B5E 0%,#0D7A87 100%); color:#FFF;
    display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px;
    flex-shrink:0; overflow:hidden; }
.siswa-mini__avatar img { width:100%; height:100%; object-fit:cover; }
.siswa-mini__info { display:flex; flex-direction:column; min-width:0; }
.siswa-mini__info strong { font-size:12.5px; color:#1E293B; font-weight:700;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.siswa-mini__info span { font-size:10.5px; color:#64748B; font-family:monospace; }

.cell-abs-inner { display:inline-flex; align-items:center; justify-content:center;
    width:32px; height:32px; border-radius:8px; font-weight:800; font-size:13px; transition:transform .15s ease; }
.cell-abs:hover .cell-abs-inner { transform:scale(1.08); }
.cell-h .cell-abs-inner { background:linear-gradient(135deg,#10B981,#059669); color:#FFF; box-shadow:0 3px 8px -2px rgba(16,185,129,.45); }
.cell-i .cell-abs-inner { background:linear-gradient(135deg,#F59E0B,#D97706); color:#FFF; box-shadow:0 3px 8px -2px rgba(245,158,11,.45); }
.cell-s .cell-abs-inner { background:linear-gradient(135deg,#3B82F6,#2563EB); color:#FFF; box-shadow:0 3px 8px -2px rgba(59,130,246,.45); }
.cell-a .cell-abs-inner { background:linear-gradient(135deg,#EF4444,#DC2626); color:#FFF; box-shadow:0 3px 8px -2px rgba(239,68,68,.45); }
.cell-t .cell-abs-inner { background:linear-gradient(135deg,#8B5CF6,#7C3AED); color:#FFF; box-shadow:0 3px 8px -2px rgba(139,92,246,.45); }
.cell-empty .cell-abs-inner { background:#F1F5F9; color:#CBD5E1; border:1.5px dashed #CBD5E1; }

.empty-state { padding:48px 20px; text-align:center; color:#94A3B8; }
.empty-state iconify-icon { color:#CBD5E1; display:block; margin:0 auto 8px; }
.empty-state p { margin:0; font-size:13px; font-weight:600; }

.ilmuna-abs-legend { display:flex; flex-wrap:wrap; gap:14px; padding:12px 18px; background:#F8FAFC;
    border-radius:10px; margin-top:16px; font-size:11.5px; color:#475569; }
.ilmuna-abs-legend span { display:inline-flex; align-items:center; gap:6px; font-weight:600; }
.ilmuna-abs-legend-dot { width:14px; height:14px; border-radius:4px; }

.ilmuna-izin-list { display:flex; flex-direction:column; gap:12px; margin-top:16px; }
.ilmuna-izin-empty { text-align:center; padding:40px 20px; background:#ECFDF5; border:1.5px dashed #A7F3D0;
    border-radius:12px; color:#64748B; }
.ilmuna-izin-empty iconify-icon { color:#10B981; display:block; margin:0 auto 10px; }
.ilmuna-izin-empty p { margin:0 0 4px; font-size:14px; font-weight:700; color:#065F46; }
.ilmuna-izin-empty small { font-size:12px; color:#10B981; }

.ilmuna-izin-item { background:#FFF; border:1px solid #E2E8F0; border-radius:14px; padding:16px 18px; transition:all .22s ease; }
.ilmuna-izin-item:hover { border-color:#CBD5E1; box-shadow:0 6px 20px -8px rgba(15,23,42,.1); }
.ilmuna-izin-item__user { display:flex; align-items:center; gap:14px; margin-bottom:12px; }
.ilmuna-izin-item__avatar { width:48px; height:48px; border-radius:50%;
    background:linear-gradient(135deg,#1D3B5E 0%,#0D7A87 100%); color:#FFF; font-weight:800; font-size:15px;
    display:flex; align-items:center; justify-content:center; flex-shrink:0; overflow:hidden;
    border:2px solid #FFF; box-shadow:0 3px 10px -3px rgba(13,122,135,.35); }
.ilmuna-izin-item__avatar img { width:100%; height:100%; object-fit:cover; }
.ilmuna-izin-item__body { flex:1; min-width:0; }
.ilmuna-izin-item__title { font-size:14px; font-weight:800; color:#1E293B; margin-bottom:4px;
    display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.ilmuna-izin-item__nim { font-size:10.5px; font-weight:700; color:#0D7A87; background:#F0FDFA;
    padding:2px 8px; border-radius:6px; font-family:monospace; border:1px solid #99F6E4; }
.ilmuna-izin-item__meta { display:flex; flex-wrap:wrap; gap:12px; font-size:11.5px; color:#64748B; }
.ilmuna-izin-item__meta span { display:inline-flex; align-items:center; gap:4px; }
.ilmuna-izin-item__meta iconify-icon { color:#0D7A87; }

.ilmuna-izin-pill { font-size:10px; font-weight:800; padding:5px 12px; border-radius:20px;
    text-transform:uppercase; letter-spacing:.05em; flex-shrink:0; }
.ilmuna-izin-pill--i { background:#FEF3C7; color:#92400E; }
.ilmuna-izin-pill--s { background:#DBEAFE; color:#1E40AF; }
.ilmuna-izin-status { font-size:9.5px; font-weight:800; padding:4px 10px; border-radius:6px;
    text-transform:uppercase; letter-spacing:.04em; }
.ilmuna-izin-status--pending  { background:#FEF3C7; color:#92400E; }
.ilmuna-izin-status--approved { background:#DCFCE7; color:#166534; }
.ilmuna-izin-status--rejected { background:#FEE2E2; color:#991B1B; }
.ilmuna-izin-status--default  { background:#E2E8F0; color:#475569; }

.ilmuna-izin-alasan { background:#F8FAFC; border:1px dashed #CBD5E1; border-radius:10px;
    padding:10px 14px; font-size:12.5px; color:#475569; margin-bottom:12px; }
.ilmuna-izin-alasan strong { color:#1D3B5E; }

.ilmuna-izin-actions { display:flex; gap:8px; flex-wrap:wrap; }
.ilmuna-izin-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:9px;
    font-family:inherit; font-size:12px; font-weight:700; cursor:pointer; border:1.5px solid transparent;
    transition:all .18s ease; }
.ilmuna-izin-btn--ghost { background:#FFFBEB; color:#92400E; border-color:#FCD34D; }
.ilmuna-izin-btn--ghost:hover { background:#FFF; border-color:#F59E0B; }

.ilmuna-evidence-modal { position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,.7);
    backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; padding:20px;
    opacity:0; pointer-events:none; transition:opacity .22s ease; }
.ilmuna-evidence-modal.is-open { opacity:1; pointer-events:auto; }
.ilmuna-evidence-box { background:#FFF; border-radius:18px; overflow:hidden; max-width:600px; width:100%;
    max-height:90vh; display:flex; flex-direction:column; box-shadow:0 24px 60px -20px rgba(0,0,0,.5);
    transform:scale(.96); transition:transform .22s cubic-bezier(.16,1,.3,1); }
.ilmuna-evidence-modal.is-open .ilmuna-evidence-box { transform:scale(1); }
.ilmuna-evidence-head { padding:16px 20px; border-bottom:1px solid #E2E8F0;
    background:linear-gradient(135deg,#1D3B5E 0%,#0D7A87 100%); color:#FFF;
    display:flex; align-items:center; justify-content:space-between; }
.ilmuna-evidence-head h3 { margin:0; font-size:15px; font-weight:800; color:#FFF;
    display:flex; align-items:center; gap:8px; }
.ilmuna-evidence-close { background:rgba(255,255,255,.15); border:none; color:#FFF;
    width:30px; height:30px; border-radius:8px; cursor:pointer;
    display:flex; align-items:center; justify-content:center; }
.ilmuna-evidence-close:hover { background:rgba(255,255,255,.25); }
.ilmuna-evidence-body { padding:20px; overflow-y:auto; flex:1; }
.ilmuna-evidence-body img { width:100%; border-radius:10px; background:#F1F5F9; display:block; margin-bottom:16px; }
.ilmuna-evidence-meta { display:flex; flex-direction:column; gap:8px; font-size:12.5px; color:#475569; }
.ilmuna-evidence-meta > div { display:flex; gap:10px; align-items:flex-start; }
.ilmuna-evidence-meta strong { color:#1D3B5E; min-width:90px; font-weight:700; font-size:11.5px;
    text-transform:uppercase; letter-spacing:.04em; }
.ilmuna-evidence-map { display:inline-flex; align-items:center; gap:6px; padding:8px 14px;
    background:#EFF6FF; border:1px solid #93C5FD; color:#1E40AF; border-radius:9px;
    font-size:12px; font-weight:700; text-decoration:none; margin-top:10px; }
.ilmuna-evidence-map:hover { background:#DBEAFE; }
.ilmuna-evidence-foot { padding:14px 20px; border-top:1px solid #E2E8F0; background:#F8FAFC;
    display:flex; justify-content:flex-end; }

.ilmuna-timeline { margin-top:16px; padding-top:16px; border-top:1px dashed #E2E8F0;
    display:flex; flex-direction:column; gap:10px; }
.ilmuna-timeline-step { display:flex; gap:12px; align-items:flex-start;
    padding:10px 14px; border-radius:10px; background:#F8FAFC; border:1px solid #E2E8F0; }
.ilmuna-timeline-step--approved { background:#F0FDF4; border-color:#BBF7D0; }
.ilmuna-timeline-step--rejected { background:#FEF2F2; border-color:#FECACA; }
.ilmuna-timeline-step--pending  { background:#FFFBEB; border-color:#FDE68A; }
.ilmuna-timeline-icon { width:28px; height:28px; border-radius:50%;
    display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.ilmuna-timeline-step--approved .ilmuna-timeline-icon { background:#DCFCE7; color:#16A34A; }
.ilmuna-timeline-step--rejected .ilmuna-timeline-icon { background:#FEE2E2; color:#DC2626; }
.ilmuna-timeline-step--pending .ilmuna-timeline-icon  { background:#FEF3C7; color:#D97706; }
.ilmuna-timeline-body { flex:1; min-width:0; }
.ilmuna-timeline-title { font-size:12.5px; font-weight:800; color:#1E293B; margin-bottom:2px; }
.ilmuna-timeline-meta { font-size:11px; color:#64748B; line-height:1.4; }
.ilmuna-timeline-catatan { font-size:11.5px; color:#475569; margin-top:6px;
    padding:6px 10px; background:#FFF; border-radius:6px; border-left:3px solid #CBD5E1; }

.ilmuna-toast-wrap { position:fixed; top:80px; right:20px; z-index:999998; display:flex;
    flex-direction:column; gap:10px; max-width:340px; pointer-events:none; }
.ilmuna-toast-item { background:#FFF; border-left:4px solid #10B981; border-radius:12px;
    box-shadow:0 12px 32px -8px rgba(0,0,0,.22); padding:12px 16px; font-family:inherit;
    pointer-events:auto; opacity:0; transform:translateX(20px); transition:all .3s ease; }
.ilmuna-toast-item.show { opacity:1; transform:translateX(0); }
.ilmuna-toast-item.is-error { border-left-color:#EF4444; }
.ilmuna-toast-item.is-warn { border-left-color:#F59E0B; }
.ilmuna-toast-item.is-info { border-left-color:#3B82F6; }
.ilmuna-toast-title { font-size:13px; font-weight:800; color:#1E293B; margin-bottom:3px; }
.ilmuna-toast-msg { font-size:12px; color:#475569; line-height:1.4; }

.ilmuna-abs-actions { display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;
    padding-bottom:14px; margin-bottom:14px; border-bottom:1px dashed #E2E8F0; }
</style>';

require_once BASE_PATH . '/includes/header.php';
?>

<div class="ilmuna-results-app" id="appAbsensi"
     data-ajax="<?= htmlspecialchars($selfUrl) ?>"
     data-excel="<?= htmlspecialchars($excelUrl) ?>">

    <div class="ilmuna-abs-tabs" role="tablist">
        <button type="button" class="ilmuna-abs-tab is-active" data-tab="rekap">
            <iconify-icon icon="solar:checklist-minimalistic-bold" width="16"></iconify-icon>
            <span>Rekap Absensi</span>
        </button>
        <button type="button" class="ilmuna-abs-tab" data-tab="izin">
            <iconify-icon icon="solar:letter-bold" width="16"></iconify-icon>
            <span>Monitoring Perizinan</span>
        </button>
    </div>

    <!-- TAB: REKAP -->
    <div id="panelRekap" data-panel="rekap">
        <div class="ilmuna-abs-filter-card">
            <div class="ilmuna-abs-actions">
                <button type="button" class="ilmuna-btn ilmuna-btn--emerald" id="btnExportExcel">
                    <iconify-icon icon="solar:file-spreadsheet-bold" width="16"></iconify-icon>
                    <span>Export Excel</span>
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnExportPdf">
                    <iconify-icon icon="solar:printer-bold" width="16"></iconify-icon>
                    <span>Cetak / PDF</span>
                </button>
            </div>
            <div class="ilmuna-abs-filter-row">
                <div style="flex: 1; min-width: 220px;">
                    <label class="ilmuna-abs-filter-label">1. Rombel Kelas <span class="req">*</span></label>
                    <select id="selectKelas" class="ilmuna-abs-select">
                        <option value="">— Pilih Rombel Kelas —</option>
                        <?php foreach ($kelasList as $k): ?>
                            <option value="<?= (int)$k['id'] ?>">
                                <?= htmlspecialchars($k['nama_kelas']) ?> (<?= htmlspecialchars($k['tahun_pelajaran']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1; min-width: 220px;">
                    <label class="ilmuna-abs-filter-label">2. Mata Pelajaran <span class="req">*</span></label>
                    <select id="selectMapel" class="ilmuna-abs-select" disabled>
                        <option value="">— Pilih Kelas Dahulu —</option>
                    </select>
                </div>
            </div>
            <div id="absMetaChips" style="display:none; margin-top:14px; padding:12px 14px;
                 background:#F8FAFC; border-radius:10px; flex-wrap:wrap; gap:8px;"></div>
        </div>

        <div id="absGridContainer">
            <div class="ilmuna-izin-empty" style="background:#F8FAFC; border-color:#E2E8F0;">
                <iconify-icon icon="solar:clipboard-remove-bold" width="44" style="color:#CBD5E1;"></iconify-icon>
                <p style="color:#1E293B;">Pilih Kelas & Mata Pelajaran</p>
                <small style="color:#94A3B8;">Rekap absensi akan muncul di sini setelah Anda memilih rombel dan mapel.</small>
            </div>
        </div>

        <div class="ilmuna-abs-legend" id="absLegend" style="display:none;">
            <span><span class="ilmuna-abs-legend-dot" style="background:#10B981;"></span> H — Hadir</span>
            <span><span class="ilmuna-abs-legend-dot" style="background:#F59E0B;"></span> I — Izin</span>
            <span><span class="ilmuna-abs-legend-dot" style="background:#3B82F6;"></span> S — Sakit</span>
            <span><span class="ilmuna-abs-legend-dot" style="background:#EF4444;"></span> A — Alpa</span>
            <span><span class="ilmuna-abs-legend-dot" style="background:#8B5CF6;"></span> T — Terlambat</span>
            <span><span class="ilmuna-abs-legend-dot" style="background:#F1F5F9; border:1.5px dashed #CBD5E1;"></span> Belum ada keterangan</span>
        </div>
    </div>

    <!-- TAB: IZIN -->
    <div id="panelIzin" data-panel="izin" style="display:none;">
        <div class="ilmuna-abs-filter-card">
            <div class="ilmuna-abs-filter-row">
                <div style="flex: 1; min-width: 220px;">
                    <label class="ilmuna-abs-filter-label">1. Rombel Kelas <span class="req">*</span></label>
                    <select id="selectIzinKelas" class="ilmuna-abs-select">
                        <option value="">— Pilih Rombel Kelas —</option>
                        <?php foreach ($kelasList as $k): ?>
                            <option value="<?= (int)$k['id'] ?>">
                                <?= htmlspecialchars($k['nama_kelas']) ?> (<?= htmlspecialchars($k['tahun_pelajaran']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex: 1; min-width: 220px;">
                    <label class="ilmuna-abs-filter-label">2. Mata Pelajaran <span class="req">*</span></label>
                    <select id="selectIzinMapel" class="ilmuna-abs-select" disabled>
                        <option value="">— Pilih Kelas Dahulu —</option>
                    </select>
                </div>
                <div style="flex: 0 0 200px;">
                    <label class="ilmuna-abs-filter-label">Filter Status</label>
                    <select id="selectIzinStatus" class="ilmuna-abs-select">
                        <option value="all">Semua Status</option>
                        <option value="pending">Menunggu Persetujuan</option>
                        <option value="approved">Disetujui</option>
                        <option value="rejected">Ditolak</option>
                    </select>
                </div>
                <div style="display:flex; align-items:flex-end;">
                    <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnReloadIzin" disabled>
                        <iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon>
                        Muat Ulang
                    </button>
                </div>
            </div>
        </div>

        <div id="izinListContainer">
            <div class="ilmuna-izin-empty">
                <iconify-icon icon="solar:filter-bold" width="44"></iconify-icon>
                <p>Pilih Kelas Terlebih Dahulu</p>
                <small>Setelah memilih kelas & mapel, daftar perizinan siswa akan muncul di sini.</small>
            </div>
        </div>
    </div>

</div>

<div class="ilmuna-evidence-modal" id="evidenceModal">
    <div class="ilmuna-evidence-box">
        <div class="ilmuna-evidence-head">
            <h3 id="evidenceTitle"><iconify-icon icon="solar:document-text-bold" width="18"></iconify-icon> Detail Perizinan</h3>
            <button type="button" class="ilmuna-evidence-close" id="evidenceClose">
                <iconify-icon icon="solar:close-circle-bold" width="18"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-evidence-body" id="evidenceBody"></div>
        <div class="ilmuna-evidence-foot">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="evidenceCloseBtn">Tutup</button>
        </div>
    </div>
</div>

<div class="ilmuna-toast-wrap" id="toastWrap"></div>

<script>
(function () {
    'use strict';
    var app = document.getElementById('appAbsensi');
    if (!app) return;
    var AJAX_URL  = app.dataset.ajax;
    var EXCEL_URL = app.dataset.excel || AJAX_URL.replace(/[^\/]*$/, 'attendance-excel.php');

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function postFD(fd) {
        return fetch(AJAX_URL, {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
        }).then(function(r) {
            return r.text().then(function(txt) {
                try {
                    return JSON.parse(txt);
                } catch (err) {
                    console.error('Invalid JSON. AJAX_URL =', AJAX_URL, '| Respons:', txt.slice(0, 500));
                    throw new Error('Server mengembalikan HTML, bukan JSON. Cek AJAX_URL di Network tab.');
                }
            });
        });
    }

    var toastWrap = document.getElementById('toastWrap');
    function toast(type, title, msg) {
        var normType = (type === 'warn') ? 'warning' : ((type === 'danger' || type === 'error' || type === 'err') ? 'error' : (type || 'info'));
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, title, msg, 5000);
        }
        var cls = 'ilmuna-toast-item';
        if (type === 'error') cls += ' is-error';
        else if (type === 'warn') cls += ' is-warn';
        else if (type === 'info') cls += ' is-info';
        var el = document.createElement('div');
        el.className = cls;
        el.innerHTML = '<div class="ilmuna-toast-title">' + escHtml(title) + '</div>'
                     + '<div class="ilmuna-toast-msg">' + escHtml(msg) + '</div>';
        toastWrap.appendChild(el);
        requestAnimationFrame(function(){ el.classList.add('show'); });
        setTimeout(function(){
            el.classList.remove('show');
            setTimeout(function(){ el.remove(); }, 350);
        }, 5500);
    }

    var tabs = document.querySelectorAll('.ilmuna-abs-tab');
    var panels = {
        rekap: document.getElementById('panelRekap'),
        izin:  document.getElementById('panelIzin')
    };
    var activeTab = 'rekap';

    tabs.forEach(function(t) {
        t.addEventListener('click', function() {
            var name = t.dataset.tab;
            if (name === activeTab) return;
            activeTab = name;
            tabs.forEach(function(x) { x.classList.toggle('is-active', x.dataset.tab === name); });
            Object.keys(panels).forEach(function(k) {
                panels[k].style.display = (k === name) ? '' : 'none';
            });
            if (name === 'izin' && currentIzinKmp) loadIzinList();
        });
    });

    var selectKelas     = document.getElementById('selectKelas');
    var selectMapel     = document.getElementById('selectMapel');
    var absGridContainer= document.getElementById('absGridContainer');
    var absMetaChips    = document.getElementById('absMetaChips');
    var absLegend       = document.getElementById('absLegend');
    var btnExportExcel  = document.getElementById('btnExportExcel');
    var btnExportPdf    = document.getElementById('btnExportPdf');

    var currentKmp = 0;

    function resetRekapView() {
        currentKmp = 0;
        absMetaChips.style.display = 'none';
        absLegend.style.display = 'none';
        absGridContainer.innerHTML = '<div class="ilmuna-izin-empty" style="background:#F8FAFC;border-color:#E2E8F0;">'
            + '<iconify-icon icon="solar:clipboard-remove-bold" width="44" style="color:#CBD5E1;"></iconify-icon>'
            + '<p style="color:#1E293B;">Pilih Kelas & Mata Pelajaran</p>'
            + '<small style="color:#94A3B8;">Rekap absensi akan muncul di sini.</small></div>';
    }

    function loadMapelOptions(kelasId, targetSelect, onLoaded) {
        targetSelect.innerHTML = '<option value="">— Memuat... —</option>';
        targetSelect.disabled = true;
        var fd = new FormData();
        fd.append('action', 'get_mapel_by_kelas');
        fd.append('kelas_id', kelasId);
        postFD(fd)
            .then(function(res) {
                if (!res.success || !res.data.length) {
                    targetSelect.innerHTML = '<option value="">— Belum Ada Mapel —</option>';
                    return;
                }
                var html = '<option value="">— Pilih Mata Pelajaran —</option>';
                res.data.forEach(function(m) {
                    html += '<option value="' + m.id_kmp + '">' + escHtml(m.nama_mata_pelajaran) + '</option>';
                });
                targetSelect.innerHTML = html;
                targetSelect.disabled = false;
                if (onLoaded) onLoaded();
            })
            .catch(function(err) {
                console.error('[loadMapel]', err);
                targetSelect.innerHTML = '<option value="">— Gagal Memuat —</option>';
                toast('error', 'Gagal Memuat Mapel', err.message || 'Cek console/Network.');
            });
    }

    selectKelas.addEventListener('change', function() {
        var kId = this.value;
        resetRekapView();
        if (!kId) {
            selectMapel.innerHTML = '<option value="">— Pilih Kelas Dahulu —</option>';
            selectMapel.disabled = true;
            return;
        }
        loadMapelOptions(kId, selectMapel);
    });

    selectMapel.addEventListener('change', function() {
        currentKmp = parseInt(this.value, 10) || 0;
        if (!currentKmp) { resetRekapView(); return; }
        absGridContainer.innerHTML = '<div style="text-align:center;padding:40px;color:#64748B;">'
            + '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="28"></iconify-icon>'
            + '<p style="margin-top:8px;">Memuat rekap absensi...</p></div>';

        var fd = new FormData();
        fd.append('action', 'load_rekap_grid');
        fd.append('kmp_id', currentKmp);
        postFD(fd)
            .then(function(res) {
                if (!res.success) {
                    absGridContainer.innerHTML = '<div class="ilmuna-izin-empty" style="background:#FEF2F2;border-color:#FECACA;">'
                        + '<p style="color:#B91C1C;">Gagal memuat: ' + escHtml(res.message) + '</p></div>';
                    return;
                }
                absGridContainer.innerHTML = res.data.grid_html;
                absLegend.style.display = 'flex';

                var info = res.data.kmp_info;
                absMetaChips.innerHTML =
                    '<span class="ilmuna-badge ilmuna-badge--navy"><iconify-icon icon="solar:users-group-two-rounded-bold" width="12"></iconify-icon> ' + escHtml(info.nama_kelas) + '</span>'
                  + '<span class="ilmuna-badge ilmuna-badge--teal"><iconify-icon icon="solar:notebook-bold" width="12"></iconify-icon> ' + escHtml(info.nama_mata_pelajaran) + '</span>'
                  + '<span class="ilmuna-badge ilmuna-badge--sky"><iconify-icon icon="solar:user-speak-bold" width="12"></iconify-icon> ' + escHtml(info.nama_guru) + '</span>'
                  + '<span class="ilmuna-badge ilmuna-badge--gold"><iconify-icon icon="solar:users-group-rounded-bold" width="12"></iconify-icon> ' + res.data.jml_siswa + ' Siswa</span>'
                  + '<span class="ilmuna-badge ilmuna-badge--emerald"><iconify-icon icon="solar:calendar-bold" width="12"></iconify-icon> ' + res.data.jml_pertemuan + ' Pertemuan</span>';
                absMetaChips.style.display = 'flex';
            })
            .catch(function(err) {
                console.error('[loadGrid]', err);
                absGridContainer.innerHTML = '<div class="ilmuna-izin-empty" style="background:#FEF2F2;border-color:#FECACA;">'
                    + '<p style="color:#B91C1C;">' + escHtml(err.message || 'Koneksi bermasalah') + '</p></div>';
                toast('error', 'Gagal', err.message || 'Koneksi bermasalah.');
            });
    });

    /* ============================================================
     * EXPORT EXCEL (via backend terpisah — attendance-excel.php)
     * ============================================================ */
    btnExportExcel.addEventListener('click', function() {
        if (!currentKmp) {
            toast('warn', 'Belum Dipilih', 'Pilih kelas & mapel terlebih dahulu.');
            return;
        }
        toast('info', 'Menyiapkan Excel', 'File Excel akan segera diunduh...');
        window.location.href = EXCEL_URL
                + '?action=export_excel'
                + '&kmp_id=' + encodeURIComponent(currentKmp);
    });

    btnExportPdf.addEventListener('click', function() {
        if (!currentKmp) {
            toast('warn', 'Belum Dipilih', 'Pilih kelas & mapel terlebih dahulu.');
            return;
        }
        window.print();
    });

    /* ============================================================
     * TAB IZIN
     * ============================================================ */
    var selectIzinKelas   = document.getElementById('selectIzinKelas');
    var selectIzinMapel   = document.getElementById('selectIzinMapel');
    var selectIzinStatus  = document.getElementById('selectIzinStatus');
    var btnReloadIzin     = document.getElementById('btnReloadIzin');
    var izinListContainer = document.getElementById('izinListContainer');
    var currentIzinKmp    = 0;

    selectIzinKelas.addEventListener('change', function() {
        var kId = this.value;
        currentIzinKmp = 0;
        btnReloadIzin.disabled = true;
        if (!kId) {
            selectIzinMapel.innerHTML = '<option value="">— Pilih Kelas Dahulu —</option>';
            selectIzinMapel.disabled = true;
            izinListContainer.innerHTML = '<div class="ilmuna-izin-empty">'
                + '<iconify-icon icon="solar:filter-bold" width="44"></iconify-icon>'
                + '<p>Pilih Kelas Terlebih Dahulu</p>'
                + '<small>Setelah memilih kelas, pilih mapel untuk melihat daftar izin.</small></div>';
            return;
        }
        loadMapelOptions(kId, selectIzinMapel);
        izinListContainer.innerHTML = '<div class="ilmuna-izin-empty">'
            + '<iconify-icon icon="solar:filter-bold" width="44"></iconify-icon>'
            + '<p>Pilih Mata Pelajaran</p>'
            + '<small>Pilih salah satu mapel dari kelas ini untuk melihat daftar izin.</small></div>';
    });

    selectIzinMapel.addEventListener('change', function() {
        currentIzinKmp = parseInt(this.value, 10) || 0;
        btnReloadIzin.disabled = !currentIzinKmp;
        if (currentIzinKmp) loadIzinList();
    });

    selectIzinStatus.addEventListener('change', function() {
        if (currentIzinKmp) loadIzinList();
    });

    btnReloadIzin.addEventListener('click', function() {
        if (currentIzinKmp) loadIzinList();
    });

    function loadIzinList() {
        if (!currentIzinKmp) return;
        izinListContainer.innerHTML = '<div style="text-align:center;padding:40px;color:#64748B;">'
            + '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="28"></iconify-icon>'
            + '<p style="margin-top:8px;">Memuat daftar perizinan...</p></div>';

        var fd = new FormData();
        fd.append('action', 'load_izin_list');
        fd.append('kmp_id', currentIzinKmp);
        fd.append('filter_status', selectIzinStatus.value);
        postFD(fd)
            .then(function(res) {
                if (!res.success) {
                    izinListContainer.innerHTML = '<div class="ilmuna-izin-empty" style="background:#FEF2F2;border-color:#FECACA;">'
                        + '<p style="color:#B91C1C;">Gagal: ' + escHtml(res.message) + '</p></div>';
                    return;
                }
                izinListContainer.innerHTML = res.data.list_html;
            })
            .catch(function(err) {
                console.error('[loadIzin]', err);
                izinListContainer.innerHTML = '<div class="ilmuna-izin-empty" style="background:#FEF2F2;border-color:#FECACA;">'
                    + '<p style="color:#B91C1C;">' + escHtml(err.message || 'Koneksi bermasalah') + '</p></div>';
            });
    }

    izinListContainer.addEventListener('click', function(e) {
        var btn = e.target.closest('.ilmuna-izin-btn[data-act]');
        if (!btn) return;
        var act = btn.dataset.act;

        if (act === 'view-bukti') {
            openEvidence({ title: 'Bukti Perizinan', foto: btn.dataset.foto, nama: btn.dataset.nama, showMeta: false });
            return;
        }
        if (act === 'view-detail') {
            openEvidence({
                title: 'Detail Perizinan',
                foto: btn.dataset.foto,
                nama: btn.dataset.nama,
                nim: btn.dataset.nim,
                jenis: btn.dataset.jenis,
                tanggal: btn.dataset.tanggal,
                alasan: btn.dataset.alasan,
                lat: parseFloat(btn.dataset.lat) || null,
                lng: parseFloat(btn.dataset.lng) || null,
                alamat: btn.dataset.alamat,
                showMeta: true,
                timeline: {
                    ketua: {
                        status: btn.dataset.ketuaStatus,
                        nama: btn.dataset.ketuaNama,
                        waktu: btn.dataset.ketuaWaktu,
                        catatan: btn.dataset.ketuaCatatan
                    },
                    guru: {
                        status: btn.dataset.guruStatus,
                        nama: btn.dataset.guruNama,
                        waktu: btn.dataset.guruWaktu,
                        catatan: btn.dataset.guruCatatan
                    }
                }
            });
            return;
        }
    });

    var evidenceModal = document.getElementById('evidenceModal');
    var evidenceTitle = document.getElementById('evidenceTitle');
    var evidenceBody  = document.getElementById('evidenceBody');

    function renderTimelineStep(label, t) {
        if (!t) return '';
        var status = (t.status || 'Pending').toLowerCase();
        var cls = status === 'approved' ? 'approved' : (status === 'rejected' ? 'rejected' : 'pending');
        var icon = status === 'approved' ? 'solar:check-circle-bold'
                 : (status === 'rejected' ? 'solar:close-circle-bold' : 'solar:hourglass-bold');
        var statusLabel = status === 'approved' ? 'Disetujui'
                        : (status === 'rejected' ? 'Ditolak' : 'Menunggu Persetujuan');
        var meta = [];
        if (t.nama) meta.push(t.nama);
        if (t.waktu) meta.push(t.waktu);
        return '<div class="ilmuna-timeline-step ilmuna-timeline-step--' + cls + '">'
             + '<div class="ilmuna-timeline-icon"><iconify-icon icon="' + icon + '" width="16"></iconify-icon></div>'
             + '<div class="ilmuna-timeline-body">'
             + '<div class="ilmuna-timeline-title">' + label + ' — ' + statusLabel + '</div>'
             + '<div class="ilmuna-timeline-meta">' + escHtml(meta.join(' · ') || '—') + '</div>'
             + (t.catatan ? '<div class="ilmuna-timeline-catatan">' + escHtml(t.catatan) + '</div>' : '')
             + '</div></div>';
    }

    function openEvidence(data) {
        evidenceTitle.innerHTML = '<iconify-icon icon="solar:document-text-bold" width="18"></iconify-icon> ' + escHtml(data.title || 'Detail');
        var html = '';
        if (data.foto) {
            html += '<img src="' + escHtml(data.foto) + '" alt="Bukti" onerror="this.style.display=\'none\'">';
        }
        if (data.showMeta) {
            html += '<div class="ilmuna-evidence-meta">';
            if (data.nama)    html += '<div><strong>Nama</strong> <span>' + escHtml(data.nama) + '</span></div>';
            if (data.nim)     html += '<div><strong>NISN/NIS</strong> <span>' + escHtml(data.nim) + '</span></div>';
            if (data.jenis)   html += '<div><strong>Jenis</strong> <span>' + escHtml(data.jenis) + '</span></div>';
            if (data.tanggal) html += '<div><strong>Tanggal Izin</strong> <span>' + escHtml(data.tanggal) + '</span></div>';
            if (data.alasan)  html += '<div><strong>Alasan</strong> <span>' + escHtml(data.alasan) + '</span></div>';
            if (data.alamat)  html += '<div><strong>Alamat</strong> <span>' + escHtml(data.alamat) + '</span></div>';
            if (data.lat && data.lng) {
                html += '<div><strong>Koordinat</strong> <span>' + data.lat.toFixed(6) + ', ' + data.lng.toFixed(6) + '</span></div>';
            }
            html += '</div>';
            if (data.lat && data.lng) {
                html += '<a href="https://www.google.com/maps?q=' + data.lat + ',' + data.lng + '" '
                     + 'target="_blank" rel="noopener" class="ilmuna-evidence-map">'
                     + '<iconify-icon icon="solar:map-point-bold" width="14"></iconify-icon> Buka di Google Maps</a>';
            }
        }
        if (data.timeline) {
            html += '<div class="ilmuna-timeline">';
            html += renderTimelineStep('Verifikasi Ketua Kelas', data.timeline.ketua);
            html += renderTimelineStep('Persetujuan Guru', data.timeline.guru);
            html += '</div>';
        }
        evidenceBody.innerHTML = html;
        evidenceModal.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }

    function closeEvidence() {
        evidenceModal.classList.remove('is-open');
        document.body.style.overflow = '';
    }
    document.getElementById('evidenceClose').addEventListener('click', closeEvidence);
    document.getElementById('evidenceCloseBtn').addEventListener('click', closeEvidence);
    evidenceModal.addEventListener('click', function(e) { if (e.target === evidenceModal) closeEvidence(); });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && evidenceModal.classList.contains('is-open')) closeEvidence();
    });

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>