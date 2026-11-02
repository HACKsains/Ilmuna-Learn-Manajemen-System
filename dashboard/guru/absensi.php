<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — SISTEM ABSENSI & MONITORING KBM (PORTAL GURU)
 * ==============================================================================
 * Sistem Presensi Terpadu Kurikulum Merdeka Khusus Dewan Guru:
 *   1. Integrasi Tabel Database: `absensi` & `perizinan` & `jadwal_pelajaran`.
 *   2. Cascading Selector: Wajib Pilih Rombel/Kelas -> Pilih Mata Pelajaran.
 *   3. Tab 1: Grid Presensi Siswa per Pertemuan KBM (P1 s/d P16)
 *      - Matrix kehadiran interaktif (H, I, S, A, T) & rekap persentase (%)
 *      - Auto-Alpa untuk pertemuan yang telah usai tanpa catatan presensi
 *      - Pop-up modal evaluasi & penyesuaian status kehadiran manual guru
 *      - Tombol Scan QR Absensi (redirect ke scanner presensi)
 *      - Ekspor Excel (.xls) & Ekspor PDF
 *   4. Tab 2: Persetujuan Izin Siswa (Dual-Approval / Approval Guru)
 *      - Daftar permohonan izin/sakit yang menunggu persetujuan guru
 *      - Lightbox modal bukti surat/foto perizinan & koordinat GPS Google Maps
 *      - Aksi Setujui: Otomatis sinkronisasi status ke tabel `absensi` ('I' / 'S')
 *      - Aksi Tolak: Dilengkapi formulir catatan/alasan penolakan guru
 *   5. Tema Lumina Gold & Emerald (Tanpa Hero Banner — Langsung Toolbar & Grid)
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole(['guru', 'admin']);
$activeRole = hasRole('guru') ? 'guru' : 'admin';
$GLOBALS['activeRole'] = $activeRole;

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$currentUser = getCurrentUser();

// Identifikasi profil guru pengampu
$currentGuru = null;
$idGuruLogged = 0;
try {
    $gStmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
    $gStmt->execute([$currentUser['id']]);
    $currentGuru = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($currentGuru) {
        $idGuruLogged = (int)$currentGuru['id'];
    }
} catch (Exception $e) {}

/* ==============================================================================
 * BACKEND AJAX HANDLERS (JSON RESPONSE)
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        // 1. LIST KELAS GURU
        if ($action === 'list_kelas_options') {
            $params = [];
            $whereClause = "";

            if ($activeRole === 'guru' && $idGuruLogged > 0) {
                $whereClause = "WHERE k.id IN (
                    SELECT kmp.id_kelas 
                    FROM kelas_mata_pelajaran kmp
                    LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                    WHERE gmp.id_guru = :id_guru 
                       OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM jadwal_pelajaran WHERE id_guru = :id_guru2)
                       OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM asesmen_sumatif WHERE id_guru = :id_guru3)
                )";
                $params[':id_guru']  = $idGuruLogged;
                $params[':id_guru2'] = $idGuruLogged;
                $params[':id_guru3'] = $idGuruLogged;
            }

            $sql = "SELECT k.id, k.nama_kelas, k.tahun_pelajaran,
                           (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa
                    FROM kelas k
                    {$whereClause}
                    ORDER BY k.nama_kelas ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $kelas = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fallback jika belum terikat spesifik guru
            if (empty($kelas) && $activeRole === 'guru') {
                $kelas = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran, (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = kelas.id) AS total_siswa FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'data' => ['kelas' => $kelas ?: []]]);
            exit;
        }

        // 2. FETCH MAPEL BY KELAS
        if ($action === 'get_mapel_by_kelas') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            $params = [$idKelas];
            $whereGuru = "";

            if ($activeRole === 'guru' && $idGuruLogged > 0) {
                $whereGuru = " AND (gmp.id_guru = ? OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM jadwal_pelajaran WHERE id_guru = ?) OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM asesmen_sumatif WHERE id_guru = ?))";
                $params[] = $idGuruLogged;
                $params[] = $idGuruLogged;
                $params[] = $idGuruLogged;
            }

            $stmt = $pdo->prepare("
                SELECT kmp.id AS id_kmp, kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.kode_mapel,
                       COALESCE(g.nama_guru, 'Guru Pengampu') AS nama_guru
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id_kelas = ? {$whereGuru}
                ORDER BY mp.nama_mata_pelajaran ASC
            ");
            $stmt->execute($params);
            $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fallback tampilkan semua mapel di kelas tersebut jika belum terikat spesifik
            if (empty($list)) {
                $stmtFallback = $pdo->prepare("
                    SELECT kmp.id AS id_kmp, kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.kode_mapel,
                           COALESCE(g.nama_guru, 'Guru Pengampu') AS nama_guru
                    FROM kelas_mata_pelajaran kmp
                    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                    LEFT JOIN guru g ON gmp.id_guru = g.id
                    WHERE kmp.id_kelas = ?
                    ORDER BY mp.nama_mata_pelajaran ASC
                ");
                $stmtFallback->execute([$idKelas]);
                $list = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode(['success' => true, 'data' => $list ?: []]);
            exit;
        }

        // 3. LOAD GRID ABSENSI (READ-ONLY MATRIX & AUTO-ALPA)
        if ($action === 'load_grid') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            $idKmp   = (int)($_POST['id_kmp'] ?? 0);

            if (!$idKelas || !$idKmp) {
                echo json_encode(['success' => false, 'message' => 'Silakan pilih Rombel/Kelas dan Mata Pelajaran terlebih dahulu.']);
                exit;
            }

            // Info Kelas & Mapel
            $stmtKm = $pdo->prepare("
                SELECT kmp.id AS id_kmp, k.id AS id_kelas, k.nama_kelas, k.tahun_pelajaran,
                       mp.id AS id_mapel, mp.nama_mata_pelajaran, mp.kode_mapel
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id = ? AND kmp.id_kelas = ?
                LIMIT 1
            ");
            $stmtKm->execute([$idKmp, $idKelas]);
            $kmDetail = $stmtKm->fetch(PDO::FETCH_ASSOC);

            if (!$kmDetail) {
                echo json_encode(['success' => false, 'message' => 'Data relasi kelas dan mata pelajaran tidak valid.']);
                exit;
            }

            // Ambil Siswa
            $stmtSiswa = $pdo->prepare("
                SELECT id, nis, nisn, nama_lengkap, foto
                FROM siswa
                WHERE id_kelas = ?
                ORDER BY nama_lengkap ASC
            ");
            $stmtSiswa->execute([$idKelas]);
            $siswaList = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);

            if (empty($siswaList)) {
                echo json_encode(['success' => true, 'data' => [
                    'grid_html' => '<div class="abs-empty"><iconify-icon icon="solar:users-group-rounded-bold" width="48"></iconify-icon><p>Belum ada siswa yang terdaftar di kelas ini.</p></div>',
                    'meta' => $kmDetail,
                    'total_siswa' => 0,
                    'total_pertemuan' => 0,
                    'alpa_inserted' => 0
                ]]);
                exit;
            }

            // Ambil Daftar Pertemuan KBM dari jadwal_pelajaran
            $stmtP = $pdo->prepare("
                SELECT id, pertemuan_ke, tanggal, jam_mulai, jam_selesai, alokasi_jp, materi_pembahasan, ruangan, status_jadwal
                FROM jadwal_pelajaran
                WHERE id_kelas_mata_pelajaran = ?
                ORDER BY pertemuan_ke ASC
            ");
            $stmtP->execute([$idKmp]);
            $pertemuanList = $stmtP->fetchAll(PDO::FETCH_ASSOC);

            // Jika belum ada pertemuan terjadwal, auto-generate standard 16 pertemuan
            if (empty($pertemuanList)) {
                $nowDate = new DateTime();
                $stmtGen = $pdo->prepare("
                    INSERT INTO jadwal_pelajaran (id_kelas_mata_pelajaran, id_guru, pertemuan_ke, tahun_pelajaran, semester, hari, tanggal, jam_mulai, jam_selesai, alokasi_jp, materi_pembahasan, ruangan, status_jadwal)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $hariList = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
                $namaHari = $hariList[($idKmp % 5)];
                $startDate = new DateTime('2026-07-20');
                
                for ($pIdx = 1; $pIdx <= 16; $pIdx++) {
                    $tglStr = $startDate->format('Y-m-d');
                    $materiStr = "Pertemuan $pIdx: Modul Pembelajaran " . ceil($pIdx / 2);
                    $stmtGen->execute([
                        $idKmp,
                        $idGuruLogged ?: null,
                        $pIdx,
                        $kmDetail['tahun_pelajaran'] ?: '2026/2027',
                        'Ganjil',
                        $namaHari,
                        $tglStr,
                        '07:30:00',
                        '09:00:00',
                        2,
                        $materiStr,
                        'Ruang ' . $kmDetail['nama_kelas'],
                        'Terjadwal'
                    ]);
                    $startDate->modify('+7 days');
                }

                // Muat ulang daftar pertemuan
                $stmtP->execute([$idKmp]);
                $pertemuanList = $stmtP->fetchAll(PDO::FETCH_ASSOC);
            }

            // AUTO-FILL ALPA: Cek pertemuan yang waktu selesainya sudah berlalu
            $nowStr = date('Y-m-d H:i:s');
            $alpaInserted = 0;
            $stmtCheckAbs = $pdo->prepare("SELECT id FROM absensi WHERE id_kelas_mata_pelajaran = ? AND id_siswa = ? AND pertemuan_ke = ? LIMIT 1");
            $stmtInsertAlpa = $pdo->prepare("
                INSERT INTO absensi (id_siswa, id_kelas_mata_pelajaran, id_jadwal, pertemuan_ke, status, waktu_absen, metode_absen, keterangan)
                VALUES (?, ?, ?, ?, 'A', NOW(), 'Manual Guru', 'Auto: Pertemuan selesai tanpa presensi')
            ");

            foreach ($pertemuanList as $p) {
                $tglP = $p['tanggal'];
                $jamSelesai = $p['jam_selesai'] ?: '23:59:59';
                $pEndDatetime = $tglP . ' ' . $jamSelesai;

                // Jika jadwal sudah lewat dan siswa belum absen
                if ($pEndDatetime < $nowStr) {
                    foreach ($siswaList as $s) {
                        $stmtCheckAbs->execute([$idKmp, $s['id'], $p['pertemuan_ke']]);
                        $exists = $stmtCheckAbs->fetchColumn();
                        if (!$exists) {
                            $stmtInsertAlpa->execute([$s['id'], $idKmp, $p['id'], $p['pertemuan_ke']]);
                            $alpaInserted++;
                        }
                    }
                }
            }

            // Load Indexed Attendance from tabel `absensi`
            $stmtAbs = $pdo->prepare("
                SELECT id, id_siswa, id_jadwal, pertemuan_ke, status, waktu_absen, metode_absen, keterangan, latitude, longitude
                FROM absensi
                WHERE id_kelas_mata_pelajaran = ?
            ");
            $stmtAbs->execute([$idKmp]);
            $absensiRows = $stmtAbs->fetchAll(PDO::FETCH_ASSOC);

            // Index map: [id_siswa][pertemuan_ke] => record
            $absensiMap = [];
            foreach ($absensiRows as $row) {
                $absensiMap[(int)$row['id_siswa']][(int)$row['pertemuan_ke']] = $row;
            }

            // Deteksi Pertemuan Aktif Hari Ini
            $aktifPId = 0;
            $aktifPLabel = '';
            $today = date('Y-m-d');
            $nowTime = date('H:i:s');
            foreach ($pertemuanList as $p) {
                if ($p['tanggal'] === $today) {
                    $aktifPId = (int)$p['id'];
                    $aktifPLabel = "P{$p['pertemuan_ke']} • " . date('d M Y', strtotime($p['tanggal'])) . " (" . substr($p['jam_mulai'], 0, 5) . " - " . substr($p['jam_selesai'], 0, 5) . ") " . ($p['ruangan'] ? "• " . $p['ruangan'] : "");
                    break;
                }
            }

            // BUILD GRID HTML
            ob_start();
            ?>
            <div class="abs-table-wrap">
                <table class="abs-table" id="absensiGridTable">
                    <thead>
                        <tr>
                            <th class="col-sticky col-no">No</th>
                            <th class="col-sticky col-nis">NIS</th>
                            <th class="col-sticky col-nisn">NISN</th>
                            <th class="col-sticky col-nama">Nama Siswa</th>
                            <?php foreach ($pertemuanList as $p): 
                                $isLive = ((int)$p['id'] === $aktifPId);
                                $tglFormat = !empty($p['tanggal']) ? date('d/m', strtotime($p['tanggal'])) : '-';
                            ?>
                                <th class="abs-col-pertemuan <?php echo $isLive ? 'pertemuan-aktif' : ''; ?>" 
                                    title="<?php echo htmlspecialchars("P{$p['pertemuan_ke']} : " . ($p['materi_pembahasan'] ?: 'KBM Terjadwal')); ?>">
                                    <div class="abs-th-pertemuan">
                                        <span class="abs-th-p">P<?php echo (int)$p['pertemuan_ke']; ?></span>
                                        <span class="abs-th-tgl"><?php echo $tglFormat; ?></span>
                                        <?php if ($isLive): ?>
                                            <span class="abs-th-live">LIVE</span>
                                        <?php endif; ?>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                            <!-- Rekap Kolom -->
                            <th class="abs-col-rekap rekap-h" title="Total Hadir (H)">H</th>
                            <th class="abs-col-rekap rekap-i" title="Total Izin (I)">I</th>
                            <th class="abs-col-rekap rekap-s" title="Total Sakit (S)">S</th>
                            <th class="abs-col-rekap rekap-a" title="Total Alpa (A)">A</th>
                            <th class="abs-col-rekap rekap-pct" title="Persentase Kehadiran Siswa">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $no = 1;
                        $totalPertemuan = count($pertemuanList);
                        foreach ($siswaList as $s): 
                            $sId = (int)$s['id'];
                            $cntH = 0; $cntI = 0; $cntS = 0; $cntA = 0; $cntT = 0;
                        ?>
                            <tr class="abs-mhs-row" data-siswa-id="<?php echo $sId; ?>" data-nama="<?php echo htmlspecialchars(strtolower($s['nama_lengkap'])); ?>" data-nis="<?php echo htmlspecialchars($s['nis']); ?>">
                                <td class="col-sticky col-no"><?php echo $no++; ?></td>
                                <td class="col-sticky col-nis"><?php echo htmlspecialchars($s['nis']); ?></td>
                                <td class="col-sticky col-nisn"><?php echo htmlspecialchars($s['nisn']); ?></td>
                                <td class="col-sticky col-nama">
                                    <div class="abs-siswa-cell">
                                        <span class="abs-siswa-name"><?php echo htmlspecialchars($s['nama_lengkap']); ?></span>
                                    </div>
                                </td>
                                <?php foreach ($pertemuanList as $p): 
                                    $pKe = (int)$p['pertemuan_ke'];
                                    $rec = $absensiMap[$sId][$pKe] ?? null;
                                    $st = $rec['status'] ?? '';
                                    $isLive = ((int)$p['id'] === $aktifPId);

                                    if ($st === 'H') $cntH++;
                                    elseif ($st === 'I') $cntI++;
                                    elseif ($st === 'S') $cntS++;
                                    elseif ($st === 'A') $cntA++;
                                    elseif ($st === 'T') { $cntT++; $cntH++; } // Terlambat dihitung hadir

                                    $statusLabel = [
                                        'H' => 'Hadir',
                                        'I' => 'Izin',
                                        'S' => 'Sakit',
                                        'A' => 'Alpa',
                                        'T' => 'Terlambat'
                                    ][$st] ?? 'Belum ada catatan';

                                    $tooltip = "{$s['nama_lengkap']} • P{$pKe} • Status: {$statusLabel}";
                                    if (!empty($rec['waktu_absen'])) {
                                        $tooltip .= " (" . date('H:i', strtotime($rec['waktu_absen'])) . " WIB)";
                                    }
                                    if (!empty($rec['metode_absen'])) {
                                        $tooltip .= " [{$rec['metode_absen']}]";
                                    }
                                ?>
                                    <td class="abs-td-cell">
                                        <div class="abs-cell <?php echo $st ? strtolower($st) : 'empty'; ?> <?php echo $isLive ? 'pertemuan-aktif' : ''; ?>"
                                             title="<?php echo htmlspecialchars($tooltip); ?>"
                                             data-siswa-id="<?php echo $sId; ?>"
                                             data-nama="<?php echo htmlspecialchars($s['nama_lengkap']); ?>"
                                             data-nis="<?php echo htmlspecialchars($s['nis']); ?>"
                                             data-pertemuan-ke="<?php echo $pKe; ?>"
                                             data-jadwal-id="<?php echo (int)$p['id']; ?>"
                                             data-tanggal="<?php echo htmlspecialchars($p['tanggal']); ?>"
                                             data-status="<?php echo htmlspecialchars($st); ?>"
                                             data-metode="<?php echo htmlspecialchars($rec['metode_absen'] ?? '-'); ?>"
                                             data-waktu="<?php echo htmlspecialchars($rec['waktu_absen'] ?? '-'); ?>"
                                             data-keterangan="<?php echo htmlspecialchars($rec['keterangan'] ?? ''); ?>"
                                             onclick="openCellDetailModal(this)">
                                            <span class="abs-cell-inner"><?php echo $st ? htmlspecialchars($st) : '-'; ?></span>
                                        </div>
                                    </td>
                                <?php endforeach; ?>

                                <?php 
                                $hadirValid = $cntH + $cntI + $cntS;
                                $persentase = $totalPertemuan > 0 ? round(($hadirValid / $totalPertemuan) * 100, 1) : 0;
                                $pctClass = 'pct-good';
                                if ($persentase < 75) $pctClass = 'pct-danger';
                                elseif ($persentase < 85) $pctClass = 'pct-warning';
                                ?>
                                <td class="abs-col-rekap rekap-h"><?php echo $cntH; ?></td>
                                <td class="abs-col-rekap rekap-i"><?php echo $cntI; ?></td>
                                <td class="abs-col-rekap rekap-s"><?php echo $cntS; ?></td>
                                <td class="abs-col-rekap rekap-a"><?php echo $cntA; ?></td>
                                <td class="abs-col-rekap rekap-pct">
                                    <span class="abs-pct-badge <?php echo $pctClass; ?>"><?php echo $persentase; ?>%</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $gridHtml = ob_get_clean();

            echo json_encode([
                'success' => true,
                'data' => [
                    'grid_html'             => $gridHtml,
                    'id_kmp'                => $idKmp,
                    'id_kelas'              => $idKelas,
                    'nama_kelas'            => $kmDetail['nama_kelas'],
                    'nama_mapel'            => $kmDetail['nama_mata_pelajaran'],
                    'tahun_pelajaran'       => $kmDetail['tahun_pelajaran'],
                    'total_siswa'           => count($siswaList),
                    'total_pertemuan'       => $totalPertemuan,
                    'pertemuan_aktif_id'    => $aktifPId,
                    'pertemuan_aktif_label' => $aktifPLabel,
                    'alpa_inserted'         => $alpaInserted,
                ]
            ]);
            exit;
        }

        // 4. QUICK UPDATE STATUS ABSENSI OLEH GURU
        if ($action === 'quick_update_cell') {
            $idKmp   = (int)($_POST['id_kmp'] ?? 0);
            $idSiswa = (int)($_POST['id_siswa'] ?? 0);
            $pKe     = (int)($_POST['pertemuan_ke'] ?? 0);
            $idJadwal= (int)($_POST['id_jadwal'] ?? 0);
            $newStatus = trim($_POST['status'] ?? '');
            $keterangan= trim($_POST['keterangan'] ?? '');

            if (!$idKmp || !$idSiswa || !$pKe) {
                echo json_encode(['success' => false, 'message' => 'Parameter data presensi tidak lengkap.']);
                exit;
            }

            if (!in_array($newStatus, ['H', 'I', 'S', 'A', 'T', 'RESET'], true)) {
                echo json_encode(['success' => false, 'message' => 'Status presensi tidak valid.']);
                exit;
            }

            if ($newStatus === 'RESET') {
                $stmtDel = $pdo->prepare("DELETE FROM absensi WHERE id_kelas_mata_pelajaran = ? AND id_siswa = ? AND pertemuan_ke = ?");
                $stmtDel->execute([$idKmp, $idSiswa, $pKe]);
                echo json_encode(['success' => true, 'message' => 'Catatan presensi berhasil direset.']);
                exit;
            }

            // Cek apakah sudah ada record
            $stmtCek = $pdo->prepare("SELECT id FROM absensi WHERE id_kelas_mata_pelajaran = ? AND id_siswa = ? AND pertemuan_ke = ? LIMIT 1");
            $stmtCek->execute([$idKmp, $idSiswa, $pKe]);
            $existId = $stmtCek->fetchColumn();

            if ($existId) {
                $stmtUpd = $pdo->prepare("
                    UPDATE absensi 
                    SET status = ?, metode_absen = 'Manual Guru', keterangan = ?, waktu_absen = NOW()
                    WHERE id = ?
                ");
                $stmtUpd->execute([$newStatus, $keterangan ?: 'Diperbarui Manual Guru', $existId]);
            } else {
                $stmtIns = $pdo->prepare("
                    INSERT INTO absensi (id_siswa, id_kelas_mata_pelajaran, id_jadwal, pertemuan_ke, status, waktu_absen, metode_absen, keterangan)
                    VALUES (?, ?, ?, ?, ?, NOW(), 'Manual Guru', ?)
                ");
                $stmtIns->execute([$idSiswa, $idKmp, $idJadwal ?: null, $pKe, $newStatus, $keterangan ?: 'Dicatat Manual Guru']);
            }

            echo json_encode(['success' => true, 'message' => 'Status presensi berhasil disimpan.']);
            exit;
        }

        // 5. LOAD DAFTAR PERSETUJUAN IZIN (DUAL-APPROVAL / APPROVAL GURU)
        if ($action === 'load_izin_list') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            $idKmp   = (int)($_POST['id_kmp'] ?? 0);

            $whereSql = "WHERE pz.approval_guru = 'Pending' AND pz.status_perizinan IN ('Menunggu Persetujuan Guru', 'Menunggu Persetujuan Ketua Kelas')";
            $params = [];

            if ($idKmp > 0) {
                $whereSql .= " AND pz.id_kelas_mata_pelajaran = ?";
                $params[] = $idKmp;
            } elseif ($idKelas > 0) {
                $whereSql .= " AND pz.id_siswa IN (SELECT id FROM siswa WHERE id_kelas = ?)";
                $params[] = $idKelas;
            }

            $sql = "
                SELECT pz.*, s.nama_lengkap AS nama_siswa, s.nis, s.foto AS foto_siswa,
                       k.nama_kelas, mp.nama_mata_pelajaran, jp.pertemuan_ke, jp.tanggal AS tgl_pertemuan
                FROM perizinan pz
                JOIN siswa s ON pz.id_siswa = s.id
                LEFT JOIN kelas k ON s.id_kelas = k.id
                LEFT JOIN kelas_mata_pelajaran kmp ON pz.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN jadwal_pelajaran jp ON pz.id_jadwal = jp.id
                {$whereSql}
                ORDER BY pz.tanggal_izin ASC, pz.created_at ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $izinList = $stmt->fetchAll(PDO::FETCH_ASSOC);

            ob_start();
            if (empty($izinList)):
                ?>
                <div class="abs-izin-empty">
                    <iconify-icon icon="solar:check-circle-bold" width="48" style="color: #10B981;"></iconify-icon>
                    <h4>Tidak Ada Permohonan Izin Menunggu Persetujuan</h4>
                    <p>Semua perizinan siswa pada kelas/mata pelajaran ini telah diproses atau belum diajukan.</p>
                </div>
                <?php
            else:
                foreach ($izinList as $iz):
                    $initials = mb_strtoupper(mb_substr($iz['nama_siswa'], 0, 2));
                    $jenisPillClass = ($iz['jenis_izin'] === 'Sakit') ? 'sakit' : 'izin';
                    $hasBukti = !empty($iz['bukti_perizinan']);
                    $hasGps = !empty($iz['latitude']) && !empty($iz['longitude']);
                ?>
                    <div class="abs-izin-item" id="izinCard-<?php echo $iz['id']; ?>">
                        <div class="abs-izin-item__header">
                            <div class="abs-izin-item__avatar">
                                <?php if (!empty($iz['foto_siswa'])): ?>
                                    <img src="<?php echo htmlspecialchars($iz['foto_siswa']); ?>" alt="Foto" onerror="this.style.display='none';">
                                <?php else: ?>
                                    <span><?php echo $initials; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="abs-izin-item__user-body">
                                <div class="abs-izin-item__name-row">
                                    <span class="abs-izin-item__name"><?php echo htmlspecialchars($iz['nama_siswa']); ?></span>
                                    <span class="abs-izin-item__nis">NIS: <?php echo htmlspecialchars($iz['nis']); ?></span>
                                    <span class="abs-izin-pill <?php echo $jenisPillClass; ?>"><?php echo strtoupper($iz['jenis_izin']); ?></span>
                                </div>
                                <div class="abs-izin-item__meta">
                                    <span><iconify-icon icon="solar:buildings-2-bold"></iconify-icon> <?php echo htmlspecialchars($iz['nama_kelas'] ?: 'Kelas'); ?></span>
                                    <span><iconify-icon icon="solar:book-bookmark-bold"></iconify-icon> <?php echo htmlspecialchars($iz['nama_mata_pelajaran'] ?: 'Mata Pelajaran'); ?></span>
                                    <span><iconify-icon icon="solar:calendar-bold"></iconify-icon> P<?php echo (int)($iz['pertemuan_ke'] ?: 1); ?> • <?php echo date('d M Y', strtotime($iz['tanggal_izin'])); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- ALASAN -->
                        <div class="abs-izin-alasan">
                            <strong>Alasan Siswa:</strong> <?php echo htmlspecialchars($iz['alasan'] ?: 'Tidak ada uraian alasan.'); ?>
                        </div>

                        <!-- APPROVAL CHAIN & BADGE -->
                        <div class="abs-izin-chain">
                            <span class="chain-step ok"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Verifikasi Ketua Kelas</span>
                            <span class="chain-arrow">→</span>
                            <span class="chain-step pending"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> Menunggu Persetujuan Guru Pengampu</span>
                        </div>

                        <!-- ACTIONS -->
                        <div class="abs-izin-actions">
                            <?php if ($hasBukti): ?>
                                <button type="button" class="btn-izin-action btn-izin-ghost"
                                    data-bukti="<?php echo htmlspecialchars($iz['bukti_perizinan']); ?>"
                                    data-nama="<?php echo htmlspecialchars($iz['nama_siswa']); ?>"
                                    data-nis="<?php echo htmlspecialchars($iz['nis']); ?>"
                                    data-alasan="<?php echo htmlspecialchars($iz['alasan']); ?>"
                                    data-lat="<?php echo htmlspecialchars($iz['latitude'] ?? ''); ?>"
                                    data-lng="<?php echo htmlspecialchars($iz['longitude'] ?? ''); ?>"
                                    data-alamat="<?php echo htmlspecialchars($iz['alamat_izin'] ?? ''); ?>"
                                    onclick="previewBuktiIzin(this)">
                                    <iconify-icon icon="solar:gallery-bold"></iconify-icon> Bukti Surat / Foto
                                </button>
                            <?php endif; ?>

                            <button type="button" class="btn-izin-action btn-izin-approve"
                                data-id="<?php echo $iz['id']; ?>"
                                data-nama="<?php echo htmlspecialchars($iz['nama_siswa']); ?>"
                                onclick="approveIzin(<?php echo $iz['id']; ?>, '<?php echo addslashes($iz['nama_siswa']); ?>')">
                                <iconify-icon icon="solar:check-circle-bold"></iconify-icon> Setujui Izin
                            </button>

                            <button type="button" class="btn-izin-action btn-izin-reject"
                                data-id="<?php echo $iz['id']; ?>"
                                data-nama="<?php echo htmlspecialchars($iz['nama_siswa']); ?>"
                                onclick="rejectIzin(<?php echo $iz['id']; ?>, '<?php echo addslashes($iz['nama_siswa']); ?>')">
                                <iconify-icon icon="solar:close-circle-bold"></iconify-icon> Tolak Izin
                            </button>
                        </div>
                    </div>
                <?php
                endforeach;
            endif;

            $izinHtml = ob_get_clean();
            echo json_encode([
                'success' => true,
                'data' => [
                    'list_html' => $izinHtml,
                    'total'     => count($izinList)
                ]
            ]);
            exit;
        }

        // 6. APPROVE / REJECT PERIZINAN OLEH GURU
        if ($action === 'proses_approval_izin') {
            $idIzin   = (int)($_POST['id_izin'] ?? 0);
            $keputusan= trim($_POST['keputusan'] ?? ''); // 'approve' atau 'reject'
            $catatan  = trim($_POST['catatan_guru'] ?? '');

            if (!$idIzin || !in_array($keputusan, ['approve', 'reject'], true)) {
                echo json_encode(['success' => false, 'message' => 'Parameter persetujuan tidak valid.']);
                exit;
            }

            $stmtIzin = $pdo->prepare("SELECT * FROM perizinan WHERE id = ? LIMIT 1");
            $stmtIzin->execute([$idIzin]);
            $izinRow = $stmtIzin->fetch(PDO::FETCH_ASSOC);

            if (!$izinRow) {
                echo json_encode(['success' => false, 'message' => 'Data perizinan tidak ditemukan.']);
                exit;
            }

            if ($keputusan === 'reject') {
                $stmtRej = $pdo->prepare("
                    UPDATE perizinan 
                    SET approval_guru = 'Rejected', status_perizinan = 'Ditolak',
                        id_guru = ?, waktu_approval_guru = NOW(), catatan_guru = ?
                    WHERE id = ?
                ");
                $stmtRej->execute([$idGuruLogged ?: null, $catatan ?: 'Ditolak oleh dewan guru', $idIzin]);

                echo json_encode(['success' => true, 'message' => 'Permohonan izin siswa telah ditolak.']);
                exit;
            }

            // APPROVE: Sync ke tabel `absensi`
            $statusAbsensi = ($izinRow['jenis_izin'] === 'Sakit') ? 'S' : 'I';
            $idKmp   = (int)$izinRow['id_kelas_mata_pelajaran'];
            $idSiswa = (int)$izinRow['id_siswa'];
            $pKe     = (int)$izinRow['pertemuan_ke'];
            $idJadwal= (int)$izinRow['id_jadwal'];

            // Upsert absensi
            $stmtCekAbs = $pdo->prepare("SELECT id FROM absensi WHERE id_kelas_mata_pelajaran = ? AND id_siswa = ? AND pertemuan_ke = ? LIMIT 1");
            $stmtCekAbs->execute([$idKmp, $idSiswa, $pKe]);
            $absId = $stmtCekAbs->fetchColumn();

            if ($absId) {
                $stmtUpdAbs = $pdo->prepare("
                    UPDATE absensi
                    SET status = ?, id_perizinan = ?, metode_absen = 'Sistem Perizinan',
                        keterangan = ?, waktu_absen = NOW(), latitude = ?, longitude = ?
                    WHERE id = ?
                ");
                $stmtUpdAbs->execute([
                    $statusAbsensi,
                    $idIzin,
                    'Izin disetujui Guru: ' . $izinRow['alasan'],
                    $izinRow['latitude'],
                    $izinRow['longitude'],
                    $absId
                ]);
            } else {
                $stmtInsAbs = $pdo->prepare("
                    INSERT INTO absensi (id_siswa, id_kelas_mata_pelajaran, id_jadwal, pertemuan_ke, status, id_perizinan, metode_absen, keterangan, waktu_absen, latitude, longitude)
                    VALUES (?, ?, ?, ?, ?, ?, 'Sistem Perizinan', ?, NOW(), ?, ?)
                ");
                $stmtInsAbs->execute([
                    $idSiswa,
                    $idKmp,
                    $idJadwal ?: null,
                    $pKe,
                    $statusAbsensi,
                    $idIzin,
                    'Izin disetujui Guru: ' . $izinRow['alasan'],
                    $izinRow['latitude'],
                    $izinRow['longitude']
                ]);
                $absId = $pdo->lastInsertId();
            }

            // Update tabel perizinan
            $stmtUpdIzin = $pdo->prepare("
                UPDATE perizinan 
                SET approval_guru = 'Approved', status_perizinan = 'Disetujui',
                    id_guru = ?, waktu_approval_guru = NOW(), catatan_guru = ?,
                    is_sync_to_absensi = 1, id_absensi = ?
                WHERE id = ?
            ");
            $stmtUpdIzin->execute([$idGuruLogged ?: null, $catatan ?: 'Disetujui oleh dewan guru', $absId, $idIzin]);

            echo json_encode([
                'success' => true,
                'message' => 'Izin berhasil disetujui dan otomatis disinkronkan ke tabel absensi siswa.',
                'absensi_id' => $absId
            ]);
            exit;
        }

        // 7. EXPORT EXCEL DATA (CSV / HTML TABLE XLS)
        if ($action === 'export_excel') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            $idKmp   = (int)($_POST['id_kmp'] ?? 0);

            $stmtKm = $pdo->prepare("
                SELECT kmp.id AS id_kmp, k.nama_kelas, k.tahun_pelajaran, mp.nama_mata_pelajaran
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id = ? AND kmp.id_kelas = ?
                LIMIT 1
            ");
            $stmtKm->execute([$idKmp, $idKelas]);
            $kmDetail = $stmtKm->fetch(PDO::FETCH_ASSOC);

            $stmtS = $pdo->prepare("SELECT id, nis, nisn, nama_lengkap FROM siswa WHERE id_kelas = ? ORDER BY nama_lengkap ASC");
            $stmtS->execute([$idKelas]);
            $siswaList = $stmtS->fetchAll(PDO::FETCH_ASSOC);

            $stmtP = $pdo->prepare("SELECT pertemuan_ke, tanggal FROM jadwal_pelajaran WHERE id_kelas_mata_pelajaran = ? ORDER BY pertemuan_ke ASC");
            $stmtP->execute([$idKmp]);
            $pertemuanList = $stmtP->fetchAll(PDO::FETCH_ASSOC);

            $stmtAbs = $pdo->prepare("SELECT id_siswa, pertemuan_ke, status FROM absensi WHERE id_kelas_mata_pelajaran = ?");
            $stmtAbs->execute([$idKmp]);
            $absRows = $stmtAbs->fetchAll(PDO::FETCH_ASSOC);
            $absMap = [];
            foreach ($absRows as $ar) {
                $absMap[(int)$ar['id_siswa']][(int)$ar['pertemuan_ke']] = $ar['status'];
            }

            $filename = "Rekap_Absensi_" . preg_replace('/[^A-Za-z0-9_-]/', '_', $kmDetail['nama_kelas'] . '_' . $kmDetail['nama_mata_pelajaran']) . ".xls";

            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"$filename\"");
            echo "\xEF\xBB\xBF"; // UTF-8 BOM
            ?>
            <table border="1">
                <tr>
                    <th colspan="<?php echo 4 + count($pertemuanList) + 5; ?>" style="font-size:16px; font-weight:bold; text-align:center;">
                        REKAPITULASI PRESENSI KBM SISWA — <?php echo strtoupper($kmDetail['nama_mata_pelajaran']); ?>
                    </th>
                </tr>
                <tr>
                    <th colspan="<?php echo 4 + count($pertemuanList) + 5; ?>" style="text-align:center;">
                        Kelas: <?php echo $kmDetail['nama_kelas']; ?> | Tahun Pelajaran: <?php echo $kmDetail['tahun_pelajaran']; ?> | Tanggal Cetak: <?php echo date('d-m-Y H:i'); ?> WIB
                    </th>
                </tr>
                <tr>
                    <th rowspan="2">No</th>
                    <th rowspan="2">NIS</th>
                    <th rowspan="2">NISN</th>
                    <th rowspan="2">Nama Lengkap</th>
                    <th colspan="<?php echo count($pertemuanList); ?>">Pertemuan KBM</th>
                    <th colspan="5">Rekapitulasi</th>
                </tr>
                <tr>
                    <?php foreach ($pertemuanList as $p): ?>
                        <th>P<?php echo $p['pertemuan_ke']; ?><br><?php echo !empty($p['tanggal']) ? date('d/m', strtotime($p['tanggal'])) : ''; ?></th>
                    <?php endforeach; ?>
                    <th>H</th>
                    <th>I</th>
                    <th>S</th>
                    <th>A</th>
                    <th>%</th>
                </tr>
                <?php 
                $no = 1;
                $totP = count($pertemuanList);
                foreach ($siswaList as $s): 
                    $sId = (int)$s['id'];
                    $h = 0; $i = 0; $sk = 0; $a = 0;
                ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td><?php echo $s['nis']; ?></td>
                        <td><?php echo $s['nisn']; ?></td>
                        <td><?php echo $s['nama_lengkap']; ?></td>
                        <?php foreach ($pertemuanList as $p): 
                            $st = $absMap[$sId][(int)$p['pertemuan_ke']] ?? '-';
                            if ($st === 'H' || $st === 'T') $h++;
                            elseif ($st === 'I') $i++;
                            elseif ($st === 'S') $sk++;
                            elseif ($st === 'A') $a++;
                        ?>
                            <td align="center"><?php echo $st; ?></td>
                        <?php endforeach; ?>
                        <?php 
                        $pct = $totP > 0 ? round((($h + $i + $sk) / $totP) * 100, 1) : 0;
                        ?>
                        <td align="center"><?php echo $h; ?></td>
                        <td align="center"><?php echo $i; ?></td>
                        <td align="center"><?php echo $sk; ?></td>
                        <td align="center"><?php echo $a; ?></td>
                        <td align="center"><?php echo $pct; ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </table>
            <?php
            exit;
        }

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()]);
        exit;
    }
}

$pageTitle = 'Sistem Presensi & Absensi KBM — Guru';
$pageIcon  = 'solar:checklist-minimalistic-bold';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<style>
/* ==============================================================================
 * LUMINA GOLD & EMERALD — THEME STYLES (PORTAL GURU ABSENSI)
 * ============================================================================== */
:root {
    --lum-navy: #1D3B5E;
    --lum-teal: #0D7A87;
    --lum-teal-hover: #095963;
    --lum-gold: #F59E0B;
    --lum-gold-light: #FEF3C7;
    --lum-bg-soft: #F8FAFC;
    --lum-border: #E2E8F0;
    --lum-text-dark: #0F172A;
    --lum-text-muted: #64748B;
}

.abs-container {
    width: 100%;
    margin-bottom: 30px;
}

/* TOP FILTER & CONTROL BAR (TANPA BANNER HERO) */
.abs-control-card {
    background: #FFFFFF;
    border: 1px solid var(--lum-border);
    border-radius: 16px;
    padding: 20px 24px;
    box-shadow: 0 4px 18px -4px rgba(13, 122, 135, 0.08);
    margin-bottom: 20px;
}

.abs-tab-nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 20px;
    border-bottom: 1.5px solid var(--lum-border);
    padding-bottom: 12px;
}

.abs-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 10px;
    background: transparent;
    border: none;
    font-size: 13.5px;
    font-weight: 700;
    color: var(--lum-text-muted);
    cursor: pointer;
    transition: all 0.25s ease;
}

.abs-tab-btn:hover {
    background: rgba(13, 122, 135, 0.06);
    color: var(--lum-teal);
}

.abs-tab-btn.active {
    background: linear-gradient(135deg, var(--lum-teal) 0%, var(--lum-navy) 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 14px -3px rgba(13, 122, 135, 0.4);
}

.abs-tab-badge {
    background: #EF4444;
    color: #FFFFFF;
    font-size: 11px;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 999px;
    line-height: 1;
}

.abs-tab-badge[data-count="0"] {
    display: none;
}

.abs-filter-grid {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: flex-end;
}

.abs-form-group {
    flex: 1;
    min-width: 250px;
}

.abs-form-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11.5px;
    font-weight: 700;
    color: var(--lum-navy);
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-bottom: 6px;
}

.abs-select {
    width: 100%;
    height: 42px;
    padding: 0 14px;
    border: 1.5px solid var(--lum-border);
    border-radius: 10px;
    background: #FFFFFF;
    font-size: 13px;
    font-weight: 600;
    color: var(--lum-text-dark);
    outline: none;
    transition: all 0.2s ease;
}

.abs-select:focus {
    border-color: var(--lum-teal);
    box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12);
}

.abs-select:disabled {
    background: #F1F5F9;
    color: #94A3B8;
    cursor: not-allowed;
}

/* META CHIPS */
.abs-meta-info {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px dashed var(--lum-border);
}

.abs-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 8px;
    background: #F1F5F9;
    border: 1px solid var(--lum-border);
    font-size: 12px;
    font-weight: 600;
    color: var(--lum-navy);
}

.abs-chip.live {
    background: #ECFDF5;
    border-color: #A7F3D0;
    color: #065F46;
}

/* TOOLBAR & BUTTONS */
.abs-toolbar-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 16px;
}

.abs-btn-group {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.abs-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 38px;
    padding: 0 14px;
    border-radius: 9px;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    border: 1.5px solid transparent;
    text-decoration: none;
    transition: all 0.2s ease;
}

.abs-btn-primary {
    background: linear-gradient(135deg, var(--lum-teal) 0%, var(--lum-navy) 100%);
    color: #FFFFFF;
    box-shadow: 0 3px 10px -2px rgba(13, 122, 135, 0.35);
}
.abs-btn-primary:hover {
    color: #FFFFFF;
    transform: translateY(-1px);
    box-shadow: 0 5px 14px -2px rgba(13, 122, 135, 0.45);
}

.abs-btn-success {
    background: #10B981;
    color: #FFFFFF;
}
.abs-btn-success:hover {
    background: #059669;
}

.abs-btn-outline {
    background: #FFFFFF;
    border-color: var(--lum-border);
    color: var(--lum-navy);
}
.abs-btn-outline:hover {
    background: #F8FAFC;
    border-color: #CBD5E1;
}

.abs-search-box {
    position: relative;
    min-width: 240px;
}

.abs-search-box input {
    width: 100%;
    height: 38px;
    padding: 0 12px 0 34px;
    border: 1.5px solid var(--lum-border);
    border-radius: 9px;
    font-size: 12.5px;
    outline: none;
    transition: border-color 0.2s;
}

.abs-search-box input:focus {
    border-color: var(--lum-teal);
}

.abs-search-box iconify-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #94A3B8;
    font-size: 16px;
}

/* LEGENDA */
.abs-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: center;
    padding: 10px 16px;
    background: #F8FAFC;
    border: 1px solid var(--lum-border);
    border-radius: 10px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--lum-navy);
    margin-bottom: 16px;
}

.abs-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 5px;
}

/* ==============================================================================
 * TABLE MATRIX PRESENSI
 * ============================================================================== */
.abs-table-wrap {
    overflow-x: auto;
    max-height: 700px;
    background: #FFFFFF;
    border: 1px solid var(--lum-border);
    border-radius: 14px;
    box-shadow: 0 4px 18px -4px rgba(15, 23, 42, 0.05);
    position: relative;
}

.abs-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 12.5px;
    text-align: center;
}

.abs-table th, .abs-table td {
    border-right: 1px solid var(--lum-border);
    border-bottom: 1px solid var(--lum-border);
    padding: 7px 5px;
    vertical-align: middle;
}

.abs-table thead tr th {
    background: #F8FAFC;
    color: var(--lum-navy);
    font-weight: 800;
    font-size: 11.5px;
    position: sticky;
    top: 0;
    z-index: 10;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}

/* STICKY SISWA COLUMNS */
.abs-table th.col-sticky,
.abs-table td.col-sticky {
    position: sticky;
    background: #FFFFFF;
    z-index: 5;
}

.abs-table thead th.col-sticky {
    background: #F8FAFC !important;
    z-index: 15 !important;
}

.abs-table tr:hover td.col-sticky {
    background: #F8FAFC !important;
}

.col-no   { left: 0; width: 40px; min-width: 40px; }
.col-nis  { left: 40px; width: 85px; min-width: 85px; font-weight: 600; color: #475569; font-family: monospace; }
.col-nisn { left: 125px; width: 95px; min-width: 95px; font-weight: 600; color: #475569; font-family: monospace; }
.col-nama { left: 220px; min-width: 220px; text-align: left !important; font-weight: 700; color: var(--lum-navy); box-shadow: 3px 0 6px rgba(0,0,0,0.04); }

.abs-th-pertemuan {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1px;
    min-width: 50px;
}

.abs-th-p {
    font-size: 12px;
    font-weight: 800;
    color: var(--lum-navy);
}

.abs-th-tgl {
    font-size: 9.5px;
    color: #64748B;
    font-weight: 600;
}

.abs-th-live {
    font-size: 8px;
    font-weight: 800;
    background: #EF4444;
    color: #FFFFFF;
    padding: 1px 4px;
    border-radius: 4px;
    letter-spacing: 0.5px;
    margin-top: 1px;
}

.abs-col-pertemuan.pertemuan-aktif {
    background: #EFF6FF !important;
    border-bottom: 2px solid #3B82F6 !important;
}

/* CELL BADGE PRESENSI */
.abs-td-cell {
    padding: 4px !important;
    min-width: 46px;
}

.abs-cell {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    user-select: none;
}

.abs-cell:hover {
    transform: scale(1.12);
    box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    z-index: 7;
}

.abs-cell.h { background: #22C55E; color: #FFFFFF; box-shadow: 0 2px 6px rgba(34, 197, 94, 0.3); }
.abs-cell.i { background: #F59E0B; color: #FFFFFF; box-shadow: 0 2px 6px rgba(245, 158, 11, 0.3); }
.abs-cell.s { background: #3B82F6; color: #FFFFFF; box-shadow: 0 2px 6px rgba(59, 130, 246, 0.3); }
.abs-cell.a { background: #EF4444; color: #FFFFFF; box-shadow: 0 2px 6px rgba(239, 68, 68, 0.3); }
.abs-cell.t { background: #8B5CF6; color: #FFFFFF; box-shadow: 0 2px 6px rgba(139, 92, 246, 0.3); }
.abs-cell.empty { background: #F8FAFC; border: 1.5px dashed #CBD5E1; color: #94A3B8; }

/* REKAP COLUMNS */
.abs-col-rekap {
    min-width: 38px;
    font-weight: 700;
    font-size: 11.5px;
}

.abs-col-rekap.rekap-h { color: #15803D; background: #F0FDF4; }
.abs-col-rekap.rekap-i { color: #B45309; background: #FFFBEB; }
.abs-col-rekap.rekap-s { color: #1D4ED8; background: #EFF6FF; }
.abs-col-rekap.rekap-a { color: #B91C1C; background: #FEF2F2; }
.abs-col-rekap.rekap-pct { min-width: 58px; font-weight: 800; background: #F8FAFC; }

.abs-pct-badge {
    padding: 3px 8px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 800;
    font-family: monospace;
}
.abs-pct-badge.pct-good { background: #DCFCE7; color: #15803D; }
.abs-pct-badge.pct-warning { background: #FEF3C7; color: #B45309; }
.abs-pct-badge.pct-danger { background: #FEE2E2; color: #B91C1C; }

/* ==============================================================================
 * TAB 2: PERSETUJUAN IZIN STYLES
 * ============================================================================== */
.abs-izin-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.abs-izin-item {
    background: #FFFFFF;
    border: 1px solid var(--lum-border);
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 3px 12px -3px rgba(13, 122, 135, 0.05);
    transition: all 0.25s ease;
}

.abs-izin-item:hover {
    border-color: #CBD5E1;
    box-shadow: 0 6px 20px -5px rgba(13, 122, 135, 0.1);
}

.abs-izin-item__header {
    display: flex;
    align-items: center;
    gap: 14px;
    margin-bottom: 12px;
}

.abs-izin-item__avatar {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--lum-teal) 0%, var(--lum-navy) 100%);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    font-weight: 800;
    overflow: hidden;
    flex-shrink: 0;
}

.abs-izin-item__avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.abs-izin-item__user-body {
    flex: 1;
    min-width: 0;
}

.abs-izin-item__name-row {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 4px;
}

.abs-izin-item__name {
    font-size: 14.5px;
    font-weight: 800;
    color: var(--lum-navy);
}

.abs-izin-item__nis {
    font-size: 11.5px;
    font-weight: 600;
    color: #475569;
    background: #F1F5F9;
    padding: 2px 8px;
    border-radius: 6px;
    font-family: monospace;
}

.abs-izin-pill {
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.5px;
}
.abs-izin-pill.sakit { background: #DBEAFE; color: #1E40AF; }
.abs-izin-pill.izin  { background: #FEF3C7; color: #92400E; }

.abs-izin-item__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    font-size: 11.5px;
    color: #64748B;
}

.abs-izin-item__meta span {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.abs-izin-alasan {
    font-size: 13px;
    color: #334155;
    background: #FFFDF5;
    border: 1px dashed #FDE68A;
    border-radius: 10px;
    padding: 10px 14px;
    margin-bottom: 12px;
    line-height: 1.5;
}

.abs-izin-chain {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11.5px;
    padding: 8px 12px;
    border-radius: 8px;
    background: #F0FDF4;
    border: 1px solid #BBF7D0;
    margin-bottom: 14px;
    flex-wrap: wrap;
}

.chain-step.ok { color: #15803D; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
.chain-step.pending { color: #B45309; font-weight: 700; display: inline-flex; align-items: center; gap: 4px; }
.chain-arrow { color: #94A3B8; font-weight: 800; }

.abs-izin-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

.btn-izin-action {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 36px;
    padding: 0 16px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    border: 1.5px solid transparent;
    transition: all 0.2s ease;
}

.btn-izin-approve {
    background: #10B981;
    color: #FFFFFF;
}
.btn-izin-approve:hover {
    background: #059669;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(16, 185, 129, 0.35);
}

.btn-izin-reject {
    background: #FFFFFF;
    color: #EF4444;
    border-color: #FECACA;
}
.btn-izin-reject:hover {
    background: #FEF2F2;
    border-color: #EF4444;
}

.btn-izin-ghost {
    background: #F8FAFC;
    color: var(--lum-navy);
    border-color: var(--lum-border);
}
.btn-izin-ghost:hover {
    background: #F1F5F9;
}

/* EMPTY STATE */
.abs-empty, .abs-izin-empty {
    text-align: center;
    padding: 50px 20px;
    color: #64748B;
}

.abs-empty iconify-icon, .abs-izin-empty iconify-icon {
    font-size: 48px;
    color: #CBD5E1;
    margin-bottom: 12px;
}

/* ==============================================================================
 * MODAL DIALOGS
 * ============================================================================== */
.abs-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: rgba(15, 23, 42, 0.65);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    opacity: 0;
    transition: opacity 0.2s ease;
}

.abs-modal-overlay.show {
    display: flex;
    opacity: 1;
}

.abs-modal-card {
    background: #FFFFFF;
    border-radius: 16px;
    width: 100%;
    max-width: 520px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    box-shadow: 0 20px 40px -10px rgba(15, 23, 42, 0.35);
    transform: scale(0.96);
    transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}

.abs-modal-overlay.show .abs-modal-card {
    transform: scale(1);
}

.abs-modal-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    background: #F8FAFC;
    border-bottom: 1px solid var(--lum-border);
}

.abs-modal-head h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 800;
    color: var(--lum-navy);
}

.abs-modal-close {
    background: none;
    border: none;
    font-size: 20px;
    color: #94A3B8;
    cursor: pointer;
    line-height: 1;
}
.abs-modal-close:hover { color: #EF4444; }

.abs-modal-body {
    padding: 20px;
    overflow-y: auto;
    font-size: 13px;
    color: #334155;
}

.abs-modal-foot {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 14px 20px;
    background: #F8FAFC;
    border-top: 1px solid var(--lum-border);
}

/* TOAST NOTIFICATION */
.abs-toast-container {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 100000;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.abs-toast {
    background: #FFFFFF;
    border-left: 4px solid var(--lum-teal);
    padding: 12px 18px;
    border-radius: 8px;
    box-shadow: 0 8px 24px -4px rgba(15, 23, 42, 0.2);
    min-width: 280px;
    font-size: 13px;
    font-weight: 600;
    color: var(--lum-navy);
    animation: slideInToast 0.3s ease;
}

@keyframes slideInToast {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
</style>

<!-- MAIN CONTAINER (TANPA BANNER HERO) -->
<div class="content-wrapper" style="padding-top: 10px;">
    <div class="abs-container">

        <!-- CARD KONTROL & FILTER UTAMA (CASCADING KELAS -> MAPEL) -->
        <div class="abs-control-card">
            <!-- TAB NAVIGATION DENGAN TOMBOL SCAN QR DI KANAN -->
            <div class="abs-tab-nav">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="abs-tab-btn active" data-tab="absensi">
                        <iconify-icon icon="solar:checklist-minimalistic-bold" style="font-size: 18px;"></iconify-icon>
                        <span>Presensi & Absensi KBM</span>
                    </button>
                    <button type="button" class="abs-tab-btn" data-tab="izin">
                        <iconify-icon icon="solar:letter-bold" style="font-size: 18px;"></iconify-icon>
                        <span>Persetujuan Izin Siswa</span>
                        <span class="abs-tab-badge" id="absTabIzinBadge" data-count="0">0</span>
                    </button>
                </div>
                <!-- TOMBOL SCAN QR ABSENSI DI SEBELAH KANAN TAB -->
                <a href="<?php echo BASE_URL; ?>/dashboard/guru/scan-qr" class="abs-btn abs-btn-primary" id="btnScanQRTop" style="margin-left: auto; height: 40px; padding: 0 18px; font-size: 13px; text-decoration: none;">
                    <iconify-icon icon="solar:qr-code-bold" style="font-size: 18px;"></iconify-icon>
                    <span>Scan QR Absensi</span>
                </a>
            </div>

            <!-- CASCADING SELECTOR -->
            <div class="abs-filter-grid">
                <!-- 1. PILIH KELAS -->
                <div class="abs-form-group">
                    <label class="abs-form-label">
                        <iconify-icon icon="solar:buildings-2-bold" style="color: var(--lum-teal); font-size: 16px;"></iconify-icon>
                        1. Rombel / Kelas
                    </label>
                    <select id="selectKelas" class="abs-select">
                        <option value="">— Pilih Kelas —</option>
                    </select>
                </div>

                <!-- 2. PILIH MATA PELAJARAN -->
                <div class="abs-form-group">
                    <label class="abs-form-label">
                        <iconify-icon icon="solar:book-bookmark-bold" style="color: var(--lum-teal); font-size: 16px;"></iconify-icon>
                        2. Mata Pelajaran yang Anda Ampu
                    </label>
                    <select id="selectMapel" class="abs-select" disabled>
                        <option value="">— Pilih Kelas Terlebih Dahulu —</option>
                    </select>
                </div>

                <!-- RESET -->
                <div style="flex-shrink: 0;">
                    <button type="button" id="btnResetFilter" class="abs-btn abs-btn-outline" style="height: 42px;">
                        <iconify-icon icon="solar:restart-bold"></iconify-icon> Reset
                    </button>
                </div>
            </div>

            <!-- META INFO CHIPS -->
            <div class="abs-meta-info" id="metaInfoWrap" style="display: none;"></div>
        </div>

        <!-- ============================================================== -->
        <!-- TAB PANEL 1: PRESENSI KBM (GRID P1 s/d P16) -->
        <!-- ============================================================== -->
        <div id="panelAbsensi" class="abs-tab-panel">
            <!-- TOOLBAR AKSI -->
            <div class="abs-toolbar-card" id="gridToolbar" style="display: none;">
                <div class="abs-btn-group">
                    <!-- LINK SCAN QR ABSENSI -->
                    <a href="<?php echo BASE_URL; ?>/dashboard/guru/scan-qr" class="abs-btn abs-btn-primary" id="btnScanQR" title="Buka Scanner QR Presensi">
                        <iconify-icon icon="solar:qr-code-bold" style="font-size: 16px;"></iconify-icon>
                        <span>Scan QR Absensi</span>
                    </a>

                    <!-- EKSPOR EXCEL -->
                    <button type="button" class="abs-btn abs-btn-success" id="btnExportExcel">
                        <iconify-icon icon="solar:file-text-bold" style="font-size: 16px;"></iconify-icon>
                        <span>Ekspor Excel (.xls)</span>
                    </button>

                    <!-- MUAT ULANG -->
                    <button type="button" class="abs-btn abs-btn-outline" id="btnReloadGrid">
                        <iconify-icon icon="solar:refresh-bold" style="font-size: 16px;"></iconify-icon>
                        <span>Muat Ulang</span>
                    </button>
                </div>

                <!-- CARI SISWA -->
                <div class="abs-search-box">
                    <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                    <input type="text" id="searchSiswaInput" placeholder="Cari nama atau NIS siswa...">
                </div>
            </div>

            <!-- LEGENDA KEHADIRAN -->
            <div class="abs-legend" id="gridLegend" style="display: none;">
                <span><span class="abs-dot" style="background:#22C55E;"></span> <strong>H</strong> : Hadir</span>
                <span><span class="abs-dot" style="background:#F59E0B;"></span> <strong>I</strong> : Izin</span>
                <span><span class="abs-dot" style="background:#3B82F6;"></span> <strong>S</strong> : Sakit</span>
                <span><span class="abs-dot" style="background:#EF4444;"></span> <strong>A</strong> : Alpa</span>
                <span><span class="abs-dot" style="background:#8B5CF6;"></span> <strong>T</strong> : Terlambat</span>
                <span><span class="abs-dot" style="background:#CBD5E1; border: 1px dashed #94A3B8;"></span> <strong>-</strong> : Belum ada catatan</span>
                <span style="margin-left: auto; color: #64748B; font-size: 11px;">
                    <iconify-icon icon="solar:info-circle-bold" style="color: var(--lum-teal);"></iconify-icon>
                    Klik cell untuk melihat rincian atau mengubah status secara manual.
                </span>
            </div>

            <!-- CONTAINER TABEL GRID -->
            <div id="gridContainer">
                <div class="abs-control-card" style="text-align: center; padding: 60px 20px;">
                    <iconify-icon icon="solar:clipboard-list-bold" style="font-size: 54px; color: #CBD5E1; margin-bottom: 14px;"></iconify-icon>
                    <h3 style="font-size: 17px; font-weight: 800; color: var(--lum-navy); margin-bottom: 6px;">Pilih Rombel / Kelas Terlebih Dahulu</h3>
                    <p style="font-size: 13px; color: #64748B; max-width: 460px; margin: 0 auto;">
                        Silakan pilih <strong>Rombel / Kelas</strong> pada filter di atas, kemudian pilih <strong>Mata Pelajaran</strong> untuk menampilkan matrix kehadiran KBM P1 s/d P16.
                    </p>
                </div>
            </div>
        </div>

        <!-- ============================================================== -->
        <!-- TAB PANEL 2: PERSETUJUAN IZIN SISWA -->
        <!-- ============================================================== -->
        <div id="panelIzin" class="abs-tab-panel" style="display: none;">
            <div class="abs-toolbar-card" style="margin-bottom: 16px;">
                <div style="font-size: 14px; font-weight: 800; color: var(--lum-navy); display: flex; align-items: center; gap: 8px;">
                    <iconify-icon icon="solar:letter-bold" style="color: var(--lum-teal); font-size: 18px;"></iconify-icon>
                    <span>Daftar Pengajuan Izin Siswa</span>
                    <span class="abs-tab-badge" id="izinCounterLabel" data-count="0">0</span>
                </div>
                <button type="button" class="abs-btn abs-btn-outline" id="btnReloadIzin">
                    <iconify-icon icon="solar:refresh-bold"></iconify-icon>
                    <span>Segarkan Permohonan</span>
                </button>
            </div>

            <div id="izinListContainer" class="abs-izin-list">
                <div class="abs-control-card" style="text-align: center; padding: 50px 20px;">
                    <iconify-icon icon="solar:letter-unread-bold" style="font-size: 48px; color: #CBD5E1; margin-bottom: 12px;"></iconify-icon>
                    <p style="font-size: 13.5px; color: #64748B;">Pilih kelas dan mata pelajaran untuk memuat daftar permohonan izin siswa.</p>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL DETAIL & QUICK UPDATE CELL PRESENSI -->
<!-- ============================================================== -->
<div class="abs-modal-overlay" id="modalCellDetail">
    <div class="abs-modal-card">
        <div class="abs-modal-head">
            <h3><iconify-icon icon="solar:user-check-bold" style="color: var(--lum-teal);"></iconify-icon> Rincian & Edit Presensi Siswa</h3>
            <button type="button" class="abs-modal-close" onclick="closeCellDetailModal()">&times;</button>
        </div>
        <div class="abs-modal-body">
            <div style="background: #F8FAFC; border: 1px solid var(--lum-border); border-radius: 10px; padding: 12px 16px; margin-bottom: 16px;">
                <div style="font-size: 14px; font-weight: 800; color: var(--lum-navy);" id="cellModalNama">-</div>
                <div style="font-size: 12px; color: #64748B; margin-top: 2px;">
                    NIS: <span id="cellModalNis" style="font-weight: 700; color: #334155;">-</span> • 
                    <span id="cellModalPertemuan" style="font-weight: 700; color: var(--lum-teal);">-</span>
                </div>
                <div style="font-size: 11.5px; color: #64748B; margin-top: 4px;">
                    Metode Terakhir: <strong id="cellModalMetode">-</strong> | Waktu: <strong id="cellModalWaktu">-</strong>
                </div>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: var(--lum-navy); margin-bottom: 8px;">
                    PILIH STATUS PRESENSI GURU:
                </label>
                <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px;">
                    <button type="button" class="abs-btn-status-choice" data-status="H" style="padding: 10px 4px; border-radius: 8px; border: 2px solid #22C55E; background: #DCFCE7; color: #15803D; font-weight: 800; cursor: pointer;">H (Hadir)</button>
                    <button type="button" class="abs-btn-status-choice" data-status="I" style="padding: 10px 4px; border-radius: 8px; border: 2px solid #F59E0B; background: #FEF3C7; color: #92400E; font-weight: 800; cursor: pointer;">I (Izin)</button>
                    <button type="button" class="abs-btn-status-choice" data-status="S" style="padding: 10px 4px; border-radius: 8px; border: 2px solid #3B82F6; background: #DBEAFE; color: #1E40AF; font-weight: 800; cursor: pointer;">S (Sakit)</button>
                    <button type="button" class="abs-btn-status-choice" data-status="A" style="padding: 10px 4px; border-radius: 8px; border: 2px solid #EF4444; background: #FEE2E2; color: #B91C1C; font-weight: 800; cursor: pointer;">A (Alpa)</button>
                    <button type="button" class="abs-btn-status-choice" data-status="T" style="padding: 10px 4px; border-radius: 8px; border: 2px solid #8B5CF6; background: #EDE9FE; color: #6D28D9; font-weight: 800; cursor: pointer;">T (Telat)</button>
                </div>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: var(--lum-navy); margin-bottom: 6px;">Catatan Tambahan Guru (Opsional):</label>
                <textarea id="cellModalKeterangan" class="abs-select" style="height: 60px; padding: 8px 12px; resize: none;" placeholder="Contoh: Sakit demam ada surat, atau terlambat 15 menit..."></textarea>
            </div>
        </div>
        <div class="abs-modal-foot">
            <button type="button" class="abs-btn abs-btn-outline" style="color: #EF4444;" onclick="submitCellStatus('RESET')">
                <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon> Reset Kosong
            </button>
            <button type="button" class="abs-btn abs-btn-outline" onclick="closeCellDetailModal()">Batal</button>
            <button type="button" class="abs-btn abs-btn-primary" onclick="submitCurrentCellStatus()">
                <iconify-icon icon="solar:disk-bold"></iconify-icon> Simpan Status
            </button>
        </div>
    </div>
</div>

<!-- ============================================================== -->
<!-- MODAL BUKTI FOTO / SURAT PERIZINAN -->
<!-- ============================================================== -->
<div class="abs-modal-overlay" id="modalBuktiIzin">
    <div class="abs-modal-card" style="max-width: 560px;">
        <div class="abs-modal-head">
            <h3><iconify-icon icon="solar:camera-bold" style="color: var(--lum-teal);"></iconify-icon> Bukti Permohonan Izin Siswa</h3>
            <button type="button" class="abs-modal-close" onclick="closeBuktiModal()">&times;</button>
        </div>
        <div class="abs-modal-body">
            <div style="margin-bottom: 14px;">
                <strong id="buktiNamaSiswa" style="font-size: 14px; color: var(--lum-navy);">-</strong>
                <span id="buktiNisSiswa" style="color: #64748B; font-size: 12px;">-</span>
                <p id="buktiAlasanSiswa" style="margin: 6px 0 0; font-size: 12.5px; color: #475569; background: #F8FAFC; padding: 8px 12px; border-radius: 8px;">-</p>
            </div>

            <div style="background: #0F172A; border-radius: 10px; overflow: hidden; text-align: center; min-height: 240px; display: flex; align-items: center; justify-content: center;">
                <img id="buktiFotoImg" src="" alt="Bukti Surat / Foto" style="max-width: 100%; max-height: 380px; object-fit: contain;">
            </div>

            <div id="buktiGpsSection" style="margin-top: 14px; display: none;">
                <div style="font-size: 11.5px; color: #64748B; margin-bottom: 6px;">
                    <iconify-icon icon="solar:map-point-bold" style="color: #EF4444;"></iconify-icon>
                    Lokasi Pengajuan: <span id="buktiLokasiText" style="font-weight: 700; color: #1E293B;">-</span>
                </div>
                <a href="#" target="_blank" rel="noopener" id="buktiGmapsLink" class="abs-btn abs-btn-outline" style="font-size: 11.5px; height: 32px;">
                    <iconify-icon icon="solar:map-bold"></iconify-icon> Buka Titik Koordinat di Google Maps
                </a>
            </div>
        </div>
        <div class="abs-modal-foot">
            <button type="button" class="abs-btn abs-btn-primary" onclick="closeBuktiModal()">Tutup</button>
        </div>
    </div>
</div>

<!-- TOAST CONTAINER -->
<div class="abs-toast-container" id="absToastContainer"></div>

<script>
/**
 * ==============================================================================
 * ILMUNA LEARN — CLIENT CONTROLLER ABSENSI GURU
 * ==============================================================================
 */
document.addEventListener('DOMContentLoaded', function() {
    var ajaxUrl = window.location.pathname;
    var currentKelasId = 0;
    var currentKmpId = 0;
    var activeTab = 'absensi';

    // State Cell Edit
    var targetCellData = null;
    var selectedCellStatus = null;

    // Element Selector
    var selectKelas = document.getElementById('selectKelas');
    var selectMapel = document.getElementById('selectMapel');
    var btnReset = document.getElementById('btnResetFilter');
    var metaWrap = document.getElementById('metaInfoWrap');
    var gridContainer = document.getElementById('gridContainer');
    var gridToolbar = document.getElementById('gridToolbar');
    var gridLegend = document.getElementById('gridLegend');
    var izinListContainer = document.getElementById('izinListContainer');
    var izinBadge = document.getElementById('absTabIzinBadge');
    var izinCounterLabel = document.getElementById('izinCounterLabel');

    // Toast Helper
    function showToast(msg, type) {
        var normType = (type === 'danger' || type === 'err' || type === 'error') ? 'error' : ((type === 'warn' || type === 'warning') ? 'warning' : 'success');
        var titleMap = { success: 'Berhasil', error: 'Gagal', warning: 'Peringatan', info: 'Informasi' };
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, titleMap[normType] || 'Pemberitahuan', msg, 4000);
        }
        var tc = document.getElementById('absToastContainer');
        if (!tc) return;
        var t = document.createElement('div');
        t.className = 'abs-toast';
        t.innerHTML = '<iconify-icon icon="solar:check-circle-bold" style="color:#10B981; margin-right:6px;"></iconify-icon> ' + msg;
        tc.appendChild(t);
        setTimeout(function() {
            t.style.opacity = '0';
            setTimeout(function() { t.remove(); }, 300);
        }, 4000);
    }

    /* ==========================================================
     * 1. TAB CONTROLLER
     * ========================================================== */
    var tabBtns = document.querySelectorAll('.abs-tab-btn');
    tabBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tabName = this.dataset.tab;
            activeTab = tabName;

            tabBtns.forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');

            if (tabName === 'absensi') {
                document.getElementById('panelAbsensi').style.display = 'block';
                document.getElementById('panelIzin').style.display = 'none';
            } else {
                document.getElementById('panelAbsensi').style.display = 'none';
                document.getElementById('panelIzin').style.display = 'block';
                loadIzinList();
            }
        });
    });

    /* ==========================================================
     * 2. CASCADING FILTER KELAS & MAPEL
     * ========================================================== */
    function loadKelasOptions() {
        var formData = new FormData();
        formData.append('action', 'list_kelas_options');

        fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.data && res.data.kelas) {
                    var options = '<option value="">— Pilih Kelas —</option>';
                    res.data.kelas.forEach(function(k) {
                        options += '<option value="' + k.id + '">' + k.nama_kelas + ' (' + k.total_siswa + ' Siswa)</option>';
                    });
                    selectKelas.innerHTML = options;
                    selectKelas.value = '';
                }
            })
            .catch(function(err) {
                console.error('Error load kelas:', err);
                selectKelas.innerHTML = '<option value="">Gagal memuat kelas</option>';
            });
    }

    selectKelas.addEventListener('change', function() {
        currentKelasId = parseInt(this.value, 10) || 0;
        selectMapel.innerHTML = '<option value="">Memuat mata pelajaran...</option>';
        selectMapel.disabled = true;
        metaWrap.style.display = 'none';
        gridToolbar.style.display = 'none';
        gridLegend.style.display = 'none';

        if (!currentKelasId) {
            selectMapel.innerHTML = '<option value="">— Pilih Kelas Terlebih Dahulu —</option>';
            gridContainer.innerHTML = '<div class="abs-control-card" style="text-align:center; padding:50px 20px;"><p style="color:#64748B;">Silakan pilih kelas terlebih dahulu.</p></div>';
            return;
        }

        var formData = new FormData();
        formData.append('action', 'get_mapel_by_kelas');
        formData.append('id_kelas', currentKelasId);

        fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.data && res.data.length > 0) {
                    var options = '<option value="">— Pilih Mata Pelajaran —</option>';
                    res.data.forEach(function(m) {
                        options += '<option value="' + m.id_kmp + '">' + m.nama_mata_pelajaran + ' (' + m.kode_mapel + ')</option>';
                    });
                    selectMapel.innerHTML = options;
                    selectMapel.disabled = false;

                    // Auto select mapel pertama
                    selectMapel.value = res.data[0].id_kmp;
                    selectMapel.dispatchEvent(new Event('change'));
                } else {
                    selectMapel.innerHTML = '<option value="">Belum ada mata pelajaran di kelas ini</option>';
                    selectMapel.disabled = true;
                }
            })
            .catch(function() {
                selectMapel.innerHTML = '<option value="">Gagal memuat mapel</option>';
                selectMapel.disabled = true;
            });
    });

    selectMapel.addEventListener('change', function() {
        currentKmpId = parseInt(this.value, 10) || 0;
        if (currentKmpId && currentKelasId) {
            loadGridAbsensi();
            loadIzinList();
        }
    });

    btnReset.addEventListener('click', function() {
        selectKelas.value = '';
        selectMapel.innerHTML = '<option value="">— Pilih Kelas Terlebih Dahulu —</option>';
        selectMapel.disabled = true;
        metaWrap.style.display = 'none';
        gridToolbar.style.display = 'none';
        gridLegend.style.display = 'none';
        gridContainer.innerHTML = '<div class="abs-control-card" style="text-align:center; padding:50px 20px;"><p style="color:#64748B;">Silakan pilih kelas dan mata pelajaran.</p></div>';
    });

    /* ==========================================================
     * 3. LOAD GRID MATRIX ABSENSI
     * ========================================================== */
    function loadGridAbsensi() {
        gridContainer.innerHTML = '<div class="abs-control-card" style="text-align:center; padding:60px 20px;"><iconify-icon icon="solar:refresh-circle-bold" class="rotate-anim" style="font-size:42px; color:var(--lum-teal);"></iconify-icon><p style="margin-top:12px; font-weight:700; color:var(--lum-navy);">Memuat Matrix Presensi KBM...</p></div>';

        var formData = new FormData();
        formData.append('action', 'load_grid');
        formData.append('id_kelas', currentKelasId);
        formData.append('id_kmp', currentKmpId);

        fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.data) {
                    var d = res.data;
                    gridContainer.innerHTML = d.grid_html;
                    gridToolbar.style.display = 'flex';
                    gridLegend.style.display = 'flex';

                    // Update Chips Meta
                    metaWrap.innerHTML = 
                        '<span class="abs-chip"><iconify-icon icon="solar:buildings-2-bold"></iconify-icon> ' + d.nama_kelas + '</span>' +
                        '<span class="abs-chip"><iconify-icon icon="solar:book-bookmark-bold"></iconify-icon> ' + d.nama_mapel + '</span>' +
                        '<span class="abs-chip"><iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon> ' + d.total_siswa + ' Siswa</span>' +
                        '<span class="abs-chip"><iconify-icon icon="solar:calendar-bold"></iconify-icon> ' + d.total_pertemuan + ' Pertemuan KBM</span>' +
                        (d.pertemuan_aktif_id ? '<span class="abs-chip live"><iconify-icon icon="solar:play-circle-bold"></iconify-icon> Pertemuan Aktif: ' + d.pertemuan_aktif_label + '</span>' : '');
                    metaWrap.style.display = 'flex';

                    if (parseInt(d.alpa_inserted, 10) > 0) {
                        showToast(d.alpa_inserted + ' siswa otomatis dicatat Alpa pada pertemuan yang telah usai.');
                    }
                } else {
                    gridContainer.innerHTML = '<div class="abs-control-card" style="text-align:center; padding:40px; color:#EF4444;"><p>' + (res.message || 'Gagal memuat grid presensi.') + '</p></div>';
                }
            })
            .catch(function(err) {
                console.error(err);
                gridContainer.innerHTML = '<div class="abs-control-card" style="text-align:center; padding:40px; color:#EF4444;"><p>Terjadi kesalahan koneksi.</p></div>';
            });
    }

    document.getElementById('btnReloadGrid').addEventListener('click', function() {
        if (currentKelasId && currentKmpId) {
            loadGridAbsensi();
        }
    });

    // PENCARIAN SISWA DI TABLE
    document.getElementById('searchSiswaInput').addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        var rows = document.querySelectorAll('#absensiGridTable tbody tr.abs-mhs-row');
        rows.forEach(function(r) {
            var nama = r.dataset.nama || '';
            var nis  = r.dataset.nis || '';
            if (q === '' || nama.indexOf(q) !== -1 || nis.indexOf(q) !== -1) {
                r.style.display = '';
            } else {
                r.style.display = 'none';
            }
        });
    });

    // EXPORT EXCEL
    document.getElementById('btnExportExcel').addEventListener('click', function() {
        if (!currentKelasId || !currentKmpId) {
            Ilmuna.alert('warning', 'Peringatan', 'Silakan pilih kelas dan mapel terlebih dahulu.');
            return;
        }
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = ajaxUrl;
        form.target = '_blank';

        var act = document.createElement('input'); act.type = 'hidden'; act.name = 'action'; act.value = 'export_excel'; form.appendChild(act);
        var k = document.createElement('input'); k.type = 'hidden'; k.name = 'id_kelas'; k.value = currentKelasId; form.appendChild(k);
        var m = document.createElement('input'); m.type = 'hidden'; m.name = 'id_kmp'; m.value = currentKmpId; form.appendChild(m);

        document.body.appendChild(form);
        form.submit();
        form.remove();
    });

    /* ==========================================================
     * 4. QUICK UPDATE CELL STATUS (MODAL)
     * ========================================================== */
    window.openCellDetailModal = function(cellEl) {
        targetCellData = {
            siswaId: cellEl.dataset.siswaId,
            nama: cellEl.dataset.nama,
            nis: cellEl.dataset.nis,
            pertemuanKe: cellEl.dataset.pertemuanKe,
            jadwalId: cellEl.dataset.jadwalId,
            tanggal: cellEl.dataset.tanggal,
            status: cellEl.dataset.status,
            metode: cellEl.dataset.metode,
            waktu: cellEl.dataset.waktu,
            keterangan: cellEl.dataset.keterangan
        };

        document.getElementById('cellModalNama').textContent = targetCellData.nama;
        document.getElementById('cellModalNis').textContent = targetCellData.nis;
        document.getElementById('cellModalPertemuan').textContent = 'Pertemuan ' + targetCellData.pertemuanKe + ' (' + targetCellData.tanggal + ')';
        document.getElementById('cellModalMetode').textContent = targetCellData.metode || '-';
        document.getElementById('cellModalWaktu').textContent = targetCellData.waktu || '-';
        document.getElementById('cellModalKeterangan').value = targetCellData.keterangan || '';

        selectedCellStatus = targetCellData.status || 'H';

        // Update button active state
        document.querySelectorAll('.abs-btn-status-choice').forEach(function(b) {
            if (b.dataset.status === selectedCellStatus) {
                b.style.transform = 'scale(1.08)';
                b.style.boxShadow = '0 0 0 3px rgba(13, 122, 135, 0.4)';
            } else {
                b.style.transform = 'none';
                b.style.boxShadow = 'none';
            }
        });

        document.getElementById('modalCellDetail').classList.add('show');
    };

    window.closeCellDetailModal = function() {
        document.getElementById('modalCellDetail').classList.remove('show');
        targetCellData = null;
    };

    document.querySelectorAll('.abs-btn-status-choice').forEach(function(btn) {
        btn.addEventListener('click', function() {
            selectedCellStatus = this.dataset.status;
            document.querySelectorAll('.abs-btn-status-choice').forEach(function(b) {
                b.style.transform = 'none';
                b.style.boxShadow = 'none';
            });
            this.style.transform = 'scale(1.08)';
            this.style.boxShadow = '0 0 0 3px rgba(13, 122, 135, 0.4)';
        });
    });

    window.submitCurrentCellStatus = function() {
        if (!targetCellData || !selectedCellStatus) return;
        var ket = document.getElementById('cellModalKeterangan').value;
        doSaveCellStatus(selectedCellStatus, ket);
    };

    window.submitCellStatus = function(statusOverride) {
        if (!targetCellData) return;
        doSaveCellStatus(statusOverride, '');
    };

    function doSaveCellStatus(st, ket) {
        var formData = new FormData();
        formData.append('action', 'quick_update_cell');
        formData.append('id_kmp', currentKmpId);
        formData.append('id_siswa', targetCellData.siswaId);
        formData.append('pertemuan_ke', targetCellData.pertemuanKe);
        formData.append('id_jadwal', targetCellData.jadwalId);
        formData.append('status', st);
        formData.append('keterangan', ket);

        fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) {
                    showToast('Presensi ' + targetCellData.nama + ' (P' + targetCellData.pertemuanKe + ') diperbarui.');
                    closeCellDetailModal();
                    loadGridAbsensi();
                } else {
                    Ilmuna.alert('error', 'Gagal', res.message || 'Gagal menyimpan status presensi.');
                }
            })
            .catch(function(err) {
                console.error(err);
                Ilmuna.alert('error', 'Kesalahan', 'Terjadi kesalahan jaringan.');
            });
    }

    /* ==========================================================
     * 5. TAB 2: LOAD PERSETUJUAN IZIN (DUAL-APPROVAL)
     * ========================================================== */
    function loadIzinList() {
        izinListContainer.innerHTML = '<div style="text-align:center; padding:40px;"><iconify-icon icon="solar:refresh-circle-bold" class="rotate-anim" style="font-size:36px; color:var(--lum-teal);"></iconify-icon><p style="margin-top:10px; color:#64748B;">Memuat permohonan izin...</p></div>';

        var formData = new FormData();
        formData.append('action', 'load_izin_list');
        formData.append('id_kelas', currentKelasId);
        formData.append('id_kmp', currentKmpId);

        fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success && res.data) {
                    izinListContainer.innerHTML = res.data.list_html;
                    var total = res.data.total || 0;
                    izinBadge.textContent = total;
                    izinBadge.setAttribute('data-count', total);
                    izinCounterLabel.textContent = total;
                    izinCounterLabel.setAttribute('data-count', total);
                } else {
                    izinListContainer.innerHTML = '<div style="padding:20px; color:#EF4444; text-align:center;">Gagal memuat izin.</div>';
                }
            })
            .catch(function(err) {
                console.error(err);
                izinListContainer.innerHTML = '<div style="padding:20px; color:#EF4444; text-align:center;">Terjadi kesalahan jaringan.</div>';
            });
    }

    document.getElementById('btnReloadIzin').addEventListener('click', loadIzinList);

    window.previewBuktiIzin = function(btn) {
        var buktiUrl = btn.dataset.bukti;
        var nama = btn.dataset.nama;
        var nis  = btn.dataset.nis;
        var alasan = btn.dataset.alasan;
        var lat = btn.dataset.lat;
        var lng = btn.dataset.lng;
        var alamat = btn.dataset.alamat;

        document.getElementById('buktiNamaSiswa').textContent = nama;
        document.getElementById('buktiNisSiswa').textContent = 'NIS: ' + nis;
        document.getElementById('buktiAlasanSiswa').textContent = alasan;
        document.getElementById('buktiFotoImg').src = buktiUrl;

        var gpsSec = document.getElementById('buktiGpsSection');
        if (lat && lng && lat !== '0' && lng !== '0') {
            document.getElementById('buktiLokasiText').textContent = (alamat ? alamat + ' (' : '') + lat + ', ' + lng + (alamat ? ')' : '');
            document.getElementById('buktiGmapsLink').href = 'https://www.google.com/maps/search/?api=1&query=' + lat + ',' + lng;
            gpsSec.style.display = 'block';
        } else {
            gpsSec.style.display = 'none';
        }

        document.getElementById('modalBuktiIzin').classList.add('show');
    };

    window.closeBuktiModal = function() {
        document.getElementById('modalBuktiIzin').classList.remove('show');
    };

    window.approveIzin = function(idIzin, namaSiswa) {
        var confirmMsg = 'Apakah Anda yakin ingin MENYETUJUI izin dari siswa ' + namaSiswa + '?\nStatus akan otomatis tercatat di tabel absensi.';
        var doApprove = function() {
            var formData = new FormData();
            formData.append('action', 'proses_approval_izin');
            formData.append('id_izin', idIzin);
            formData.append('keputusan', 'approve');
            formData.append('catatan_guru', 'Disetujui guru pengampu');

            fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        showToast('Izin ' + namaSiswa + ' berhasil disetujui!');
                        loadIzinList();
                        loadGridAbsensi();
                    } else {
                        Ilmuna.alert('error', 'Gagal', res.message || 'Gagal memproses persetujuan izin.');
                    }
                })
                .catch(function(err) {
                    console.error(err);
                    Ilmuna.alert('error', 'Kesalahan', 'Terjadi kesalahan jaringan.');
                });
        };

        Ilmuna.confirm('warning', 'Persetujuan Izin', confirmMsg, 'Ya, Setujui', 'Batal').then(function(ok) {
            if (ok) doApprove();
        });
    };

    window.rejectIzin = function(idIzin, namaSiswa) {
        Ilmuna.confirm('error', 'Tolak Izin Siswa?', 'Apakah Anda yakin ingin menolak permohonan izin dari siswa ' + namaSiswa + '?', 'Ya, Tolak Izin', 'Batal').then(function(ok) {
            if (!ok) return;

            var formData = new FormData();
            formData.append('action', 'proses_approval_izin');
            formData.append('id_izin', idIzin);
            formData.append('keputusan', 'reject');
            formData.append('catatan_guru', 'Berkas/alasan tidak memenuhi syarat.');

            fetch(ajaxUrl, { method: 'POST', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res.success) {
                        showToast('Izin ' + namaSiswa + ' telah ditolak.');
                        loadIzinList();
                    } else {
                        Ilmuna.alert('error', 'Gagal', res.message || 'Gagal menolak izin.');
                    }
                })
                .catch(function(err) {
                    console.error(err);
                    Ilmuna.alert('error', 'Kesalahan', 'Terjadi kesalahan jaringan.');
                });
        });
    };

    // Close modal on click outside
    document.querySelectorAll('.abs-modal-overlay').forEach(function(overlay) {
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                overlay.classList.remove('show');
            }
        });
    });

    // Inisialisasi awal
    loadKelasOptions();
});
</script>

<?php
require_once BASE_PATH . '/includes/footer.php';
?>
