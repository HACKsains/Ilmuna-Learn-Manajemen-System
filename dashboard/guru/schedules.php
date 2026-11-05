<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — JADWAL MENGAJAR & KALENDER KBM GURU
 * ==============================================================================
 * Kepatuhan Penuh Sesuai README.md & Arsitektur Ilmuna Learn:
 * 1. Zero Title, Zero Subtitle, Data-First Principle.
 * 2. Segmented Tabs Multi-View ala `curriculum.php`.
 * 3. Filter Box & Action Toolbar ala `assessment-access.php`.
 * 4. Dialog & Notifikasi 100% `window.Ilmuna` (assets/js/alert.js).
 * 5. ZERO PAGE RELOAD POLICY: Seluruh aksi (tambah, edit, status toggle, jurnal,
 *    hapus, navigasi bulan, filter kelas/mapel, sinkronisasi PROSEM & Kaldik)
 *    berjalan secara in-place DOM mutation via async Fetch tanpa reload halaman.
 * 6. Integrasi Total & Dinamis dengan Kalender Pendidikan (Kaldik), PROTA, PROSEM,
 *    Alokasi Hari Mengajar (AHM), dan Rincian Pekan Efektif (RPE) — Bebas Hardcode.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole(['guru', 'admin']);

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$currentUser = getCurrentUser();

$currentGuru = null;
$idGuruLogged = 0;
$idUserLogged = (int)($currentUser['id'] ?? 0);
$namaGuruLogged = 'Dewan Guru';

try {
    $gStmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
    $gStmt->execute([$idUserLogged]);
    $currentGuru = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($currentGuru) {
        $idGuruLogged = (int)$currentGuru['id'];
        $namaGuruLogged = $currentGuru['nama_guru'];
    }
} catch (Exception $e) {}

$activeRole = hasRole('guru') ? 'guru' : 'admin';

// Ambil Identitas Sekolah & Pengaturan Semester Aktif (Anti-Hardcode)
$identitasSekolah = [];
try {
    $stmtSekolah = $pdo->query("SELECT * FROM identitas_sekolah LIMIT 1");
    $identitasSekolah = $stmtSekolah->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

$tahunAktif = !empty($identitasSekolah['tahun_pelajaran_aktif']) ? $identitasSekolah['tahun_pelajaran_aktif'] : '2026/2027';
$semesterAktif = !empty($identitasSekolah['semester_aktif']) ? $identitasSekolah['semester_aktif'] : 'Ganjil';

$namaBulanIndo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

/* ==============================================================================
 * AJAX API HANDLERS (JSON RESPONSE SESUAI HUKUM 6 README.md)
 * ============================================================================== */
if (isset($_REQUEST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $ajaxAction = $_REQUEST['ajax_action'];

    try {
        // 1. UPDATE STATUS KETERLAKSANAAN KBM (IN-PLACE LIVE UPDATE)
        if ($ajaxAction === 'update_status_pelaksanaan') {
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $newStatus = trim($_POST['status_pelaksanaan'] ?? 'Terlaksana');
            $catatan = trim($_POST['catatan_pelaksanaan'] ?? '');

            $allowedStatus = ['Belum Terlaksana', 'Terlaksana', 'Tidak Terlaksana', 'Ditunda'];
            if (!in_array($newStatus, $allowedStatus, true)) {
                echo json_encode(['success' => false, 'message' => 'Status tidak valid']);
                exit;
            }

            if ($scheduleId <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID Jadwal tidak valid']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE jadwal_pelajaran 
                SET status_pelaksanaan = ?, 
                    catatan_pelaksanaan = CASE WHEN ? != '' THEN ? ELSE catatan_pelaksanaan END
                WHERE id = ?
            ");
            $stmt->execute([$newStatus, $catatan, $catatan, $scheduleId]);

            echo json_encode([
                'success' => true,
                'message' => 'Status keterlaksanaan KBM diperbarui menjadi "' . $newStatus . '"',
                'data'    => [
                    'id'                 => $scheduleId,
                    'status_pelaksanaan' => $newStatus,
                    'catatan'            => $catatan
                ]
            ]);
            exit;
        }

        // 2. SIMPAN CATATAN JURNAL GURU (IN-PLACE)
        if ($ajaxAction === 'save_jurnal') {
            $scheduleId = (int)($_POST['schedule_id'] ?? 0);
            $catatan = trim($_POST['catatan_pelaksanaan'] ?? '');

            if ($scheduleId <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID Jadwal tidak valid']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE jadwal_pelajaran SET catatan_pelaksanaan = ? WHERE id = ?");
            $stmt->execute([$catatan, $scheduleId]);

            echo json_encode([
                'success' => true,
                'message' => 'Catatan jurnal mengajar berhasil disimpan',
                'data'    => ['id' => $scheduleId, 'catatan' => $catatan]
            ]);
            exit;
        }

        // 3. AMBIL DETAIL SEMUA JADWAL & AGENDA KALDIK PADA TANGGAL TERTENTU
        if ($ajaxAction === 'get_schedules_by_date') {
            $tanggal = trim($_POST['tanggal'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
                echo json_encode(['success' => false, 'message' => 'Format tanggal tidak valid']);
                exit;
            }

            // Info Kaldik pada tanggal ini
            $kaldikStmt = $pdo->prepare("SELECT status_hari, keterangan, warna_label FROM kalender_pendidikan WHERE tanggal = ? LIMIT 1");
            $kaldikStmt->execute([$tanggal]);
            $kaldikInfo = $kaldikStmt->fetch(PDO::FETCH_ASSOC);

            // Sesi KBM
            $query = "
                SELECT 
                    j.*,
                    k.nama_kelas,
                    mp.nama_mata_pelajaran,
                    mp.kode_mapel,
                    cp.cp as capaian_pembelajaran,
                    elem.nama_elemen,
                    elem.fase,
                    tp.kode_tp,
                    tp.tp as uraian_tp,
                    tp.materi_pokok as tp_materi_pokok
                FROM jadwal_pelajaran j
                LEFT JOIN kelas_mata_pelajaran kmp ON j.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN cp ON j.id_cp = cp.id
                LEFT JOIN elemen elem ON cp.id_elemen = elem.id
                LEFT JOIN tp ON j.id_tp = tp.id
                WHERE j.tanggal = ?
            ";
            $params = [$tanggal];

            if ($activeRole === 'guru' && ($idGuruLogged > 0 || $idUserLogged > 0)) {
                $query .= " AND (j.id_guru = ? OR j.id_guru = ?)";
                $params[] = $idGuruLogged;
                $params[] = $idUserLogged;
            }

            $query .= " ORDER BY j.jam_mulai ASC, j.jam_ke ASC";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => 'Berhasil memuat rincian KBM',
                'data'    => [
                    'tanggal'    => $tanggal,
                    'kaldik'     => $kaldikInfo ?: ['status_hari' => 'Efektif KBM', 'keterangan' => 'KBM Reguler'],
                    'schedules'  => $rows
                ]
            ]);
            exit;
        }

        // 4. AMBIL DATA KALENDER BULANAN SECARA ASINKRON (ZERO RELOAD UNTUK NAVIGASI BULAN & FILTER)
        if ($ajaxAction === 'get_month_calendar_data') {
            $month = (int)($_POST['month'] ?? date('n'));
            $year = (int)($_POST['year'] ?? date('Y'));
            $classId = (int)($_POST['class_id'] ?? 0);
            $mapelId = (int)($_POST['mapel_id'] ?? 0);
            $status = trim($_POST['status'] ?? 'Semua');
            $agenda = trim($_POST['agenda'] ?? 'Semua');

            if ($month < 1 || $month > 12) $month = (int)date('n');
            if ($year < 2020 || $year > 2035) $year = (int)date('Y');

            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $daysInMonth = (int)date('t', strtotime($startDate));
            $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

            // Ambil Kaldik Events
            $kaldikStmt = $pdo->prepare("SELECT tanggal, status_hari, keterangan, warna_label FROM kalender_pendidikan WHERE tanggal BETWEEN ? AND ?");
            $kaldikStmt->execute([$startDate, $endDate]);
            $kaldikEvents = [];
            foreach ($kaldikStmt->fetchAll(PDO::FETCH_ASSOC) as $ke) {
                $kaldikEvents[$ke['tanggal']] = $ke;
            }

            // Ambil Sesi Jadwal
            $q = "
                SELECT 
                    j.*,
                    k.nama_kelas,
                    mp.nama_mata_pelajaran,
                    mp.kode_mapel,
                    cp.cp as capaian_pembelajaran,
                    elem.nama_elemen,
                    elem.fase,
                    tp.kode_tp,
                    tp.tp as uraian_tp,
                    tp.materi_pokok as tp_materi_pokok
                FROM jadwal_pelajaran j
                LEFT JOIN kelas_mata_pelajaran kmp ON j.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN cp ON j.id_cp = cp.id
                LEFT JOIN elemen elem ON cp.id_elemen = elem.id
                LEFT JOIN tp ON j.id_tp = tp.id
                WHERE j.tanggal BETWEEN ? AND ?
            ";
            $p = [$startDate, $endDate];

            if ($activeRole === 'guru' && ($idGuruLogged > 0 || $idUserLogged > 0)) {
                $q .= " AND (j.id_guru = ? OR j.id_guru = ?)";
                $p[] = $idGuruLogged;
                $p[] = $idUserLogged;
            }

            if ($classId > 0) {
                $q .= " AND kmp.id_kelas = ?";
                $p[] = $classId;
            }

            if ($mapelId > 0) {
                $q .= " AND kmp.id_mata_pelajaran = ?";
                $p[] = $mapelId;
            }

            if ($status !== 'Semua') {
                $q .= " AND j.status_pelaksanaan = ?";
                $p[] = $status;
            }

            if ($agenda !== 'Semua') {
                $q .= " AND j.jenis_agenda = ?";
                $p[] = $agenda;
            }

            $q .= " ORDER BY j.tanggal ASC, j.jam_mulai ASC, j.jam_ke ASC";
            $stmt = $pdo->prepare($q);
            $stmt->execute($p);
            $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group by date
            $schedulesByDate = [];
            $totalJp = 0;
            foreach ($schedules as $sc) {
                $d = $sc['tanggal'];
                if (!isset($schedulesByDate[$d])) $schedulesByDate[$d] = [];
                $schedulesByDate[$d][] = $sc;
                $totalJp += (int)($sc['alokasi_jp'] ?: 2);
            }

            $prevM = $month - 1; $prevY = $year;
            if ($prevM < 1) { $prevM = 12; $prevY--; }

            $nextM = $month + 1; $nextY = $year;
            if ($nextM > 12) { $nextM = 1; $nextY++; }

            echo json_encode([
                'success' => true,
                'message' => 'Data kalender bulan berhasil dimuat',
                'data'    => [
                    'month'             => $month,
                    'year'              => $year,
                    'month_name'        => $namaBulanIndo[$month] . ' ' . $year,
                    'prev_month'        => $prevM,
                    'prev_year'         => $prevY,
                    'next_month'        => $nextM,
                    'next_year'         => $nextY,
                    'first_day_of_week' => (int)date('N', strtotime($startDate)),
                    'last_day_of_week'  => (int)date('N', strtotime($endDate)),
                    'days_in_month'     => $daysInMonth,
                    'total_sesi'        => count($schedules),
                    'total_jp'          => $totalJp,
                    'kaldik_events'     => $kaldikEvents,
                    'schedules_by_date' => $schedulesByDate,
                    'schedules_list'    => $schedules
                ]
            ]);
            exit;
        }

        // 5. SINKRONISASI OTOMATIS JADWAL KBM DARI PROSEM & KALDIK (IN-PLACE LIVE UPDATE)
        if ($ajaxAction === 'sync_from_prosem_kaldik') {
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            $tahun = trim($_POST['tahun'] ?? $tahunAktif);
            $semester = trim($_POST['semester'] ?? $semesterAktif);

            if ($idKmp <= 0) {
                echo json_encode(['success' => false, 'message' => 'Pilih Rombel Kelas & Mata Pelajaran terlebih dahulu']);
                exit;
            }

            // A. Ambil Alokasi Hari Mengajar Guru (AHM) untuk KMP ini
            $ahmStmt = $pdo->prepare("SELECT * FROM alokasi_hari_mengajar WHERE id_kelas_mata_pelajaran = ? AND semester = ? ORDER BY id ASC LIMIT 1");
            $ahmStmt->execute([$idKmp, $semester]);
            $ahm = $ahmStmt->fetch(PDO::FETCH_ASSOC);

            $hariKbm = !empty($ahm['hari']) ? $ahm['hari'] : 'Senin';
            $jpPekan = !empty($ahm['jp_per_minggu']) ? (int)$ahm['jp_per_minggu'] : 2;

            // B. Ambil Prosem & Prosem Detail untuk KMP ini
            $psStmt = $pdo->prepare("SELECT id FROM prosem WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ? LIMIT 1");
            $psStmt->execute([$idKmp, $tahun, $semester]);
            $idProsem = $psStmt->fetchColumn();

            if (!$idProsem) {
                $psStmt2 = $pdo->prepare("SELECT id FROM prosem WHERE id_kelas_mata_pelajaran = ? ORDER BY id DESC LIMIT 1");
                $psStmt2->execute([$idKmp]);
                $idProsem = $psStmt2->fetchColumn();
            }

            if (!$idProsem) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Belum ada Program Semester (PROSEM) untuk rombel mapel ini. Silakan buat atau generate PROSEM terlebih dahulu di menu Prota & Prosem.'
                ]);
                exit;
            }

            $dStmt = $pdo->prepare("
                SELECT psd.*, tp.id_cp, tp.kode_tp, tp.tp as uraian_tp, tp.materi_pokok
                FROM prosem_detail psd
                LEFT JOIN tp ON psd.id_tp = tp.id
                WHERE psd.id_prosem = ?
                ORDER BY psd.nomor_urut ASC, psd.id ASC
            ");
            $dStmt->execute([$idProsem]);
            $prosemAgendas = $dStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($prosemAgendas)) {
                echo json_encode(['success' => false, 'message' => 'Rincian agenda PROSEM masih kosong. Lakukan sinkronisasi atau tambahkan TP pada PROSEM.']);
                exit;
            }

            // C. Ambil Tanggal Efektif KBM dari Kaldik Sekolah untuk hari mengajar tersebut
            $kaldikStmt = $pdo->prepare("
                SELECT tanggal, hari, status_hari, keterangan 
                FROM kalender_pendidikan 
                WHERE tahun_pelajaran = ? AND semester = ? AND hari = ? AND status_hari = 'Efektif KBM'
                ORDER BY tanggal ASC
            ");
            $kaldikStmt->execute([$tahun, $semester, $hariKbm]);
            $effectiveDates = $kaldikStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($effectiveDates)) {
                $kaldikStmt2 = $pdo->prepare("
                    SELECT tanggal, hari, status_hari, keterangan 
                    FROM kalender_pendidikan 
                    WHERE hari = ? AND status_hari = 'Efektif KBM'
                    ORDER BY tanggal ASC LIMIT 24
                ");
                $kaldikStmt2->execute([$hariKbm]);
                $effectiveDates = $kaldikStmt2->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($effectiveDates)) {
                echo json_encode(['success' => false, 'message' => "Tidak ditemukan tanggal efektif KBM di Kalender Pendidikan untuk hari $hariKbm."]);
                exit;
            }

            $guruIdToSave = $idGuruLogged > 0 ? $idGuruLogged : ($idUserLogged ?: 1);

            // D. Petakan agenda PROSEM ke Tanggal Kaldik secara berurutan
            $pdo->beginTransaction();

            // Hapus jadwal eksisting untuk KMP di semester ini agar ter-sinkronisasi bersih
            $pdo->prepare("DELETE FROM jadwal_pelajaran WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ?")
                ->execute([$idKmp, $tahun, $semester]);

            $stmtIns = $pdo->prepare("
                INSERT INTO jadwal_pelajaran (
                    id_kelas_mata_pelajaran, id_guru, pertemuan_ke, tanggal, hari, jam_ke,
                    jam_mulai, jam_selesai, alokasi_jp, ruangan, jenis_agenda,
                    id_cp, id_tp, materi_pembahasan, status_pelaksanaan,
                    status_jadwal, tahun_pelajaran, semester
                ) VALUES (
                    ?, ?, ?, ?, ?, '1-2',
                    '07:30:00', '09:00:00', ?, 'Ruang Kelas', ?,
                    ?, ?, ?, 'Belum Terlaksana',
                    'Terjadwal', ?, ?
                )
            ");

            $syncedCount = 0;
            $totalDates = count($effectiveDates);
            $dateIndex = 0;

            foreach ($prosemAgendas as $idx => $agenda) {
                if ($dateIndex >= $totalDates) break;

                $targetDate = $effectiveDates[$dateIndex]['tanggal'];
                $targetHari = $effectiveDates[$dateIndex]['hari'];
                $alokasiJp = (int)$agenda['alokasi_waktu'] ?: $jpPekan;
                $kategoriAgenda = $agenda['kategori'] ?: 'Materi KBM';
                $materi = !empty($agenda['nama_agenda']) ? $agenda['nama_agenda'] : ($agenda['materi_pokok'] ?: $agenda['uraian_tp']);

                $stmtIns->execute([
                    $idKmp,
                    $guruIdToSave,
                    $idx + 1,
                    $targetDate,
                    $targetHari,
                    $alokasiJp,
                    $kategoriAgenda,
                    $agenda['id_cp'] ?: null,
                    $agenda['id_tp'] ?: null,
                    $materi,
                    $tahun,
                    $semester
                ]);

                $syncedCount++;
                $dateIndex++;
            }

            $pdo->commit();

            echo json_encode([
                'success' => true,
                'message' => "Berhasil menyinkronkan $syncedCount sesi KBM dari Kaldik & PROSEM untuk hari $hariKbm secara otomatis!",
                'data'    => [
                    'synced_count' => $syncedCount,
                    'hari'         => $hariKbm,
                    'tahun'        => $tahun,
                    'semester'     => $semester
                ]
            ]);
            exit;
        }

        // 6. SIMPAN / UPDATE JADWAL MENGAJAR MANUAL (IN-PLACE)
        if ($ajaxAction === 'save_schedule') {
            $id = (int)($_POST['id'] ?? 0);
            $idKmp = (int)($_POST['id_kelas_mata_pelajaran'] ?? 0);
            $tanggal = trim($_POST['tanggal'] ?? '');
            $hari = trim($_POST['hari'] ?? '');
            $jamKe = trim($_POST['jam_ke'] ?? '1-2');
            $jamMulai = trim($_POST['jam_mulai'] ?? '07:30');
            $jamSelesai = trim($_POST['jam_selesai'] ?? '09:00');
            $alokasiJp = (int)($_POST['alokasi_jp'] ?? 2);
            $ruangan = trim($_POST['ruangan'] ?? 'Ruang Kelas');
            $jenisAgenda = trim($_POST['jenis_agenda'] ?? 'Materi KBM');
            $idCp = !empty($_POST['id_cp']) ? (int)$_POST['id_cp'] : null;
            $idTp = !empty($_POST['id_tp']) ? (int)$_POST['id_tp'] : null;
            $materi = trim($_POST['materi_pembahasan'] ?? '');
            $statusPelaksanaan = trim($_POST['status_pelaksanaan'] ?? 'Belum Terlaksana');
            $catatan = trim($_POST['catatan_pelaksanaan'] ?? '');

            if (empty($tanggal) || empty($idKmp)) {
                echo json_encode(['success' => false, 'message' => 'Tanggal dan Rombel Mapel wajib diisi']);
                exit;
            }

            if (empty($hari)) {
                $daysMap = ['Sun'=>'Minggu','Mon'=>'Senin','Tue'=>'Selasa','Wed'=>'Rabu','Thu'=>'Kamis','Fri'=>'Jumat','Sat'=>'Sabtu'];
                $hari = $daysMap[date('D', strtotime($tanggal))] ?? 'Senin';
            }

            $guruIdToSave = $idGuruLogged > 0 ? $idGuruLogged : ($idUserLogged ?: 1);

            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE jadwal_pelajaran SET
                        id_kelas_mata_pelajaran = ?,
                        tanggal = ?,
                        hari = ?,
                        jam_ke = ?,
                        jam_mulai = ?,
                        jam_selesai = ?,
                        alokasi_jp = ?,
                        ruangan = ?,
                        jenis_agenda = ?,
                        id_cp = ?,
                        id_tp = ?,
                        materi_pembahasan = ?,
                        status_pelaksanaan = ?,
                        catatan_pelaksanaan = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $idKmp, $tanggal, $hari, $jamKe, $jamMulai, $jamSelesai,
                    $alokasiJp, $ruangan, $jenisAgenda, $idCp, $idTp,
                    $materi, $statusPelaksanaan, $catatan, $id
                ]);
                $msg = 'Jadwal mengajar KBM berhasil diperbarui!';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO jadwal_pelajaran (
                        id_kelas_mata_pelajaran, id_guru, tanggal, hari, jam_ke,
                        jam_mulai, jam_selesai, alokasi_jp, ruangan, jenis_agenda,
                        id_cp, id_tp, materi_pembahasan, status_pelaksanaan,
                        catatan_pelaksanaan, status_jadwal, tahun_pelajaran, semester
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, 'Terjadwal', ?, ?
                    )
                ");
                $stmt->execute([
                    $idKmp, $guruIdToSave, $tanggal, $hari, $jamKe,
                    $jamMulai, $jamSelesai, $alokasiJp, $ruangan, $jenisAgenda,
                    $idCp, $idTp, $materi, $statusPelaksanaan,
                    $catatan, $tahunAktif, $semesterAktif
                ]);
                $id = (int)$pdo->lastInsertId();
                $msg = 'Jadwal mengajar baru berhasil ditambahkan!';
            }

            // Fetch newly updated/created row with joined details
            $fetchStmt = $pdo->prepare("
                SELECT j.*, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel,
                       cp.cp as capaian_pembelajaran, elem.nama_elemen, tp.kode_tp, tp.tp as uraian_tp, tp.materi_pokok as tp_materi_pokok
                FROM jadwal_pelajaran j
                LEFT JOIN kelas_mata_pelajaran kmp ON j.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN cp ON j.id_cp = cp.id
                LEFT JOIN elemen elem ON cp.id_elemen = elem.id
                LEFT JOIN tp ON j.id_tp = tp.id
                WHERE j.id = ?
            ");
            $fetchStmt->execute([$id]);
            $savedRow = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'data'    => $savedRow
            ]);
            exit;
        }

        // 7. HAPUS JADWAL (IN-PLACE DOM REMOVAL)
        if ($ajaxAction === 'delete_schedule') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $pdo->prepare("DELETE FROM jadwal_pelajaran WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode([
                    'success' => true,
                    'message' => 'Sesi KBM berhasil dihapus dari jadwal',
                    'data'    => ['id' => $id]
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'ID Jadwal tidak valid']);
            }
            exit;
        }

        // 8. AMBIL ELEMEN, CP, DAN TP BERDASARKAN KMP
        if ($ajaxAction === 'get_cp_tp_by_kmp') {
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            
            $cpStmt = $pdo->prepare("
                SELECT cp.id, cp.cp as capaian_pembelajaran, elem.nama_elemen, elem.fase 
                FROM cp 
                LEFT JOIN elemen elem ON cp.id_elemen = elem.id 
                WHERE cp.id_kelas_mata_pelajaran = ? 
                ORDER BY elem.nama_elemen ASC, cp.id ASC
            ");
            $cpStmt->execute([$idKmp]);
            $cps = $cpStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($cps)) {
                $cps = $pdo->query("
                    SELECT cp.id, cp.cp as capaian_pembelajaran, elem.nama_elemen, elem.fase 
                    FROM cp 
                    LEFT JOIN elemen elem ON cp.id_elemen = elem.id 
                    ORDER BY cp.id ASC LIMIT 20
                ")->fetchAll(PDO::FETCH_ASSOC);
            }

            $tpStmt = $pdo->prepare("
                SELECT tp.id, tp.id_cp, tp.kode_tp, tp.tp, tp.materi_pokok, tp.alokasi_jam, elem.nama_elemen 
                FROM tp 
                JOIN cp ON tp.id_cp = cp.id 
                LEFT JOIN elemen elem ON cp.id_elemen = elem.id 
                WHERE cp.id_kelas_mata_pelajaran = ? 
                ORDER BY tp.kode_tp ASC, tp.id ASC
            ");
            $tpStmt->execute([$idKmp]);
            $tps = $tpStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($tps)) {
                $tps = $pdo->query("
                    SELECT tp.id, tp.id_cp, tp.kode_tp, tp.tp, tp.materi_pokok, tp.alokasi_jam, elem.nama_elemen 
                    FROM tp 
                    JOIN cp ON tp.id_cp = cp.id 
                    LEFT JOIN elemen elem ON cp.id_elemen = elem.id 
                    ORDER BY tp.id ASC LIMIT 20
                ")->fetchAll(PDO::FETCH_ASSOC);
            }

            echo json_encode([
                'success' => true,
                'message' => 'Berhasil memuat CP dan TP',
                'data'    => ['cps' => $cps, 'tps' => $tps]
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Action tidak dikenali']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/* ==============================================================================
 * LOAD TEACHER'S CLASSES & SUBJECTS (MULTI-ROMBEL)
 * ============================================================================== */
$classes = [];
$kmps = [];

$kmpQuery = "
    SELECT 
        kmp.id AS id_kmp,
        k.id AS id_kelas,
        k.nama_kelas,
        k.kode_kelas,
        mp.id AS id_mata_pelajaran,
        mp.nama_mata_pelajaran,
        mp.kode_mapel
    FROM kelas_mata_pelajaran kmp
    JOIN kelas k ON kmp.id_kelas = k.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
    WHERE 1=1
";
$kmpParams = [];

if ($activeRole === 'guru' && ($idGuruLogged > 0 || $idUserLogged > 0)) {
    $kmpQuery .= " AND (gmp.id_guru = ? OR kmp.id IN (SELECT id_kelas_mata_pelajaran FROM jadwal_pelajaran WHERE id_guru = ? OR id_guru = ?))";
    $kmpParams[] = $idGuruLogged;
    $kmpParams[] = $idGuruLogged;
    $kmpParams[] = $idUserLogged;
}
$kmpQuery .= " ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC";

$stmtKmps = $pdo->prepare($kmpQuery);
$stmtKmps->execute($kmpParams);
$kmps = $stmtKmps->fetchAll(PDO::FETCH_ASSOC);

if (empty($kmps)) {
    $kmps = $pdo->query("
        SELECT 
            kmp.id AS id_kmp,
            k.id AS id_kelas,
            k.nama_kelas,
            k.kode_kelas,
            mp.id AS id_mata_pelajaran,
            mp.nama_mata_pelajaran,
            mp.kode_mapel
        FROM kelas_mata_pelajaran kmp
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        ORDER BY k.nama_kelas ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$uniqueClasses = [];
$uniqueSubjects = [];
foreach ($kmps as $kItem) {
    $uniqueClasses[$kItem['id_kelas']] = [
        'id'   => $kItem['id_kelas'],
        'name' => $kItem['nama_kelas']
    ];
    $uniqueSubjects[$kItem['id_mata_pelajaran']] = [
        'id'   => $kItem['id_mata_pelajaran'],
        'name' => $kItem['nama_mata_pelajaran'],
        'kode' => $kItem['kode_mapel']
    ];
}

$firstKmpId = !empty($kmps) ? (int)$kmps[0]['id_kmp'] : 0;

/* ==============================================================================
 * INITIAL MONTH & FILTER STATE
 * ============================================================================== */
$currentYearActual = (int)date('Y');
$currentMonthActual = (int)date('n');

$filterYear = isset($_GET['year']) ? (int)$_GET['year'] : $currentYearActual;
$filterMonth = isset($_GET['month']) ? (int)$_GET['month'] : $currentMonthActual;
if ($filterMonth < 1) $filterMonth = 1;
if ($filterMonth > 12) $filterMonth = 12;
if ($filterYear < 2020 || $filterYear > 2035) $filterYear = $currentYearActual;

$filterClassId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$filterSubjectId = isset($_GET['mapel_id']) ? (int)$_GET['mapel_id'] : 0;
$filterStatus = trim($_GET['status'] ?? 'Semua');
$activeTab = trim($_GET['tab'] ?? 'calendar');

// Ambil Data RPE & Alokasi Mengajar untuk Tab 3
$rpeList = [];
$ahmList = [];
try {
    $rpeStmt = $pdo->prepare("
        SELECT * FROM pekan_efektif 
        WHERE tahun_pelajaran = ? AND semester = ?
        ORDER BY urutan_bulan ASC
    ");
    $rpeStmt->execute([$tahunAktif, $semesterAktif]);
    $rpeList = $rpeStmt->fetchAll(PDO::FETCH_ASSOC);

    $ahmStmt = $pdo->prepare("
        SELECT ahm.*, k.nama_kelas, mp.nama_mata_pelajaran
        FROM alokasi_hari_mengajar ahm
        JOIN kelas_mata_pelajaran kmp ON ahm.id_kelas_mata_pelajaran = kmp.id
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        WHERE ahm.semester = ?
        ORDER BY ahm.id ASC
    ");
    $ahmStmt->execute([$semesterAktif]);
    $ahmList = $ahmStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$pageTitle = 'Jadwal Mengajar & Kalender KBM';
require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<style>
/* ==============================================================================
 * DESIGN SYSTEM — SESUAI README.md & LUMINA GOLD NUSANTARA
 * ============================================================================== */
.sch-page-wrapper {
    width: 100%;
    margin: 0;
    padding: 0 0 32px;
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
}

/* SEGMENTED TABS (SAMA DENGAN curriculum.php) */
.curriculum-main-tabs {
    display: flex !important;
    align-items: center !important;
    gap: 8px !important;
    background: #F1F5F9 !important;
    border: 1px solid #E2E8F0 !important;
    border-radius: 14px !important;
    padding: 6px !important;
    margin-bottom: 16px !important;
    overflow-x: auto !important;
    scrollbar-width: none !important;
    width: 100% !important;
}
.curriculum-main-tabs::-webkit-scrollbar { display: none !important; }

.curriculum-tab-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    padding: 10px 18px !important;
    border-radius: 10px !important;
    border: 1px solid transparent !important;
    background: transparent !important;
    color: #475569 !important;
    font-size: 13px !important;
    font-weight: 700 !important;
    cursor: pointer !important;
    white-space: nowrap !important;
    flex: 1 1 0% !important;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
}
.curriculum-tab-btn:hover {
    color: #0D7A87 !important;
    background: rgba(255, 255, 255, 0.7) !important;
}
.curriculum-tab-btn.is-active {
    background: #FFFFFF !important;
    color: #0D7A87 !important;
    border-color: #CBD5E1 !important;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08) !important;
}
.curriculum-tab-counter {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-width: 22px !important;
    height: 20px !important;
    padding: 0 7px !important;
    border-radius: 9999px !important;
    font-size: 11px !important;
    font-weight: 800 !important;
    background: #E2E8F0 !important;
    color: #475569 !important;
}
.curriculum-tab-btn.is-active .curriculum-tab-counter {
    background: rgba(13, 122, 135, 0.15) !important;
    color: #0D7A87 !important;
}

/* FILTER BOX & ACTION TOOLBAR (SAMA DENGAN assessment-access.php) */
.ilmuna-filter-box {
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 16px;
    padding: 14px 16px;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px rgba(15, 23, 42, 0.03);
}
.ilmuna-filter-topline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.ilmuna-filter-bottomline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    padding-top: 10px;
    border-top: 1px solid #F1F5F9;
}

.sch-select {
    padding: 8px 12px;
    font-size: 0.85rem;
    border-radius: 10px;
    border: 1px solid #CBD5E1;
    background-color: #FFFFFF;
    color: #1E293B;
    font-weight: 600;
    outline: none;
    transition: border-color 0.2s;
}
.sch-select:focus {
    border-color: #0D7A87;
    box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12);
}

.sch-nav-btn {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    border: 1px solid #CBD5E1;
    background: #FFFFFF;
    color: #1E293B;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    cursor: pointer;
    transition: all 0.2s ease;
    border: none;
}
.sch-nav-btn:hover {
    background: #0D7A87;
    color: #FFFFFF;
}

/* Status Filter Pill */
.sch-filter-pill {
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 700;
    cursor: pointer;
    background: #F1F5F9;
    color: #475569;
    border: 1px solid transparent;
    transition: all 0.15s ease;
    user-select: none;
}
.sch-filter-pill:hover {
    background: #E2E8F0;
    color: #0D7A87;
}
.sch-filter-pill.is-active {
    background: #0D7A87;
    color: #FFFFFF;
}

/* KALENDER BESAR GRID */
.sch-cal-card {
    background: #FFFFFF;
    border-radius: 20px;
    border: 1px solid #E2E8F0;
    box-shadow: 0 4px 20px rgba(15, 23, 42, 0.04);
    overflow: hidden;
    transition: opacity 0.2s ease;
}

.sch-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    border-collapse: collapse;
}

.sch-day-header {
    background: #0F2D4A;
    color: #FFFFFF;
    text-align: center;
    padding: 12px 6px;
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-right: 1px solid rgba(255, 255, 255, 0.1);
}
.sch-day-header:last-child { border-right: none; }
.sch-day-header--sun { background: #7F1D1D; color: #FEE2E2; }

.sch-cell {
    min-height: 135px;
    border-right: 1px solid #E2E8F0;
    border-bottom: 1px solid #E2E8F0;
    padding: 8px 6px;
    background: #FFFFFF;
    position: relative;
    cursor: pointer;
    transition: background 0.15s ease, box-shadow 0.15s ease;
    display: flex;
    flex-direction: column;
}
.sch-cell:nth-child(7n) { border-right: none; }
.sch-cell:hover {
    background: #F1F8FA;
    box-shadow: inset 0 0 0 2px #0D7A87;
    z-index: 2;
}
.sch-cell--muted { background: #F8FAFC; opacity: 0.55; cursor: default; }
.sch-cell--sun { background: #FFFDFD; }
.sch-cell--today { background: #F0FDF4; border: 2px solid #10B981 !important; }

.sch-cell-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 5px;
}

.sch-date-num {
    font-size: 0.95rem;
    font-weight: 800;
    width: 26px;
    height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    color: #334155;
}
.sch-cell--today .sch-date-num { background: #10B981; color: #FFFFFF; }

/* KALDIK EVENT BADGE DI SEL TANGGAL */
.sch-kaldik-badge {
    display: block;
    padding: 3px 6px;
    border-radius: 6px;
    font-size: 0.69rem;
    font-weight: 700;
    background: rgba(225, 29, 72, 0.1);
    color: #BE123C;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    border-left: 3px solid #E11D48;
}
.sch-kaldik-badge--ujian {
    background: rgba(79, 70, 229, 0.1);
    color: #4338CA;
    border-left-color: #4F46E5;
}

/* Schedule Pills */
.sch-pills-list {
    display: flex;
    flex-direction: column;
    gap: 4px;
    overflow-y: auto;
    max-height: 145px;
}
.sch-pill {
    padding: 5px 7px;
    border-radius: 8px;
    font-size: 0.74rem;
    line-height: 1.25;
    border-left: 3px solid #0D7A87;
    background: #F8FAFC;
    border: 1px solid #EEF2F6;
    border-left-width: 3px;
    transition: transform 0.1s;
}
.sch-pill:hover { transform: translateY(-1px); }
.sch-pill--kbm { border-left-color: #0D7A87; background: rgba(13, 122, 135, 0.04); }
.sch-pill--sumatif { border-left-color: #D97706; background: rgba(217, 119, 6, 0.08); }
.sch-pill--sts { border-left-color: #4F46E5; background: rgba(79, 70, 229, 0.08); }
.sch-pill--asas { border-left-color: #E11D48; background: rgba(225, 29, 72, 0.08); }

.sch-status-dot {
    display: inline-block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    margin-right: 4px;
}
.sch-status-dot--terlaksana { background: #10B981; }
.sch-status-dot--belum { background: #94A3B8; }
.sch-status-dot--tidak { background: #EF4444; }
.sch-status-dot--ditunda { background: #F59E0B; }

/* ==============================================================================
 * README.md SECTION 4.4 — IN-PLACE DOM TRANSITION ANIMATIONS
 * ============================================================================== */
@keyframes rowSmoothIn {
    0% {
        opacity: 0;
        transform: translateY(-10px) scale(0.98);
        background-color: rgba(13, 122, 135, 0.15);
    }
    100% {
        opacity: 1;
        transform: translateY(0) scale(1);
        background-color: transparent;
    }
}

@keyframes rowSmoothOut {
    0% {
        opacity: 1;
        transform: scale(1);
        max-height: 80px;
    }
    100% {
        opacity: 0;
        transform: scale(0.95);
        max-height: 0;
        padding-top: 0;
        padding-bottom: 0;
        margin: 0;
    }
}

@keyframes rowFlash {
    0%   { background-color: rgba(245, 158, 11, 0.28); }
    100% { background-color: transparent; }
}

.row-smooth-in {
    animation: rowSmoothIn 0.38s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.row-smooth-out {
    animation: rowSmoothOut 0.32s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    overflow: hidden;
    pointer-events: none;
}
.row-flash {
    animation: rowFlash 1.5s ease-out forwards;
}

/* Responsive adjustments */
@media (max-width: 860px) {
    .sch-grid { grid-template-columns: 1fr; }
    .sch-day-header { display: none; }
    .sch-cell { min-height: auto; border-right: none; }
}
</style>

<div class="sch-page-wrapper">
    <!-- ==============================================================================
     * SEGMENTED TABS (STANDAR curriculum.php)
     * ============================================================================== -->
    <div class="curriculum-main-tabs">
        <button type="button" class="curriculum-tab-btn <?= $activeTab === 'calendar' ? 'is-active' : '' ?>" onclick="switchViewTab('calendar')">
            <iconify-icon icon="solar:calendar-date-bold"></iconify-icon>
            <span class="curriculum-tab-label">Kalender Besar KBM & Kaldik</span>
            <span class="curriculum-tab-counter" id="tabCounterCalendar">0 Sesi</span>
        </button>
        <button type="button" class="curriculum-tab-btn <?= $activeTab === 'prosem' ? 'is-active' : '' ?>" onclick="switchViewTab('prosem')">
            <iconify-icon icon="solar:calendar-mark-bold"></iconify-icon>
            <span class="curriculum-tab-label">Matriks Agenda PROSEM & KBM</span>
            <span class="curriculum-tab-counter" id="tabCounterProsem">0 JP</span>
        </button>
        <button type="button" class="curriculum-tab-btn <?= $activeTab === 'rpe' ? 'is-active' : '' ?>" onclick="switchViewTab('rpe')">
            <iconify-icon icon="solar:clock-circle-bold"></iconify-icon>
            <span class="curriculum-tab-label">Alokasi Jam & RPE Kaldik</span>
            <span class="curriculum-tab-counter"><?= count($rpeList) ?> Bulan</span>
        </button>
    </div>

    <!-- ==============================================================================
     * TOOLBAR & ACTION FILTER BOX (STANDAR assessment-access.php)
     * ============================================================================== -->
    <div class="ilmuna-filter-box">
        <div class="ilmuna-filter-topline">
            <!-- Navigator Bulan & Tahun (100% In-Place Async Tanpa Reload) -->
            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" class="sch-nav-btn" id="btnPrevMonth" onclick="navigateMonth(-1)" title="Bulan Sebelumnya">
                    <iconify-icon icon="solar:alt-arrow-left-bold"></iconify-icon>
                </button>
                <div style="font-weight: 800; font-size: 1.15rem; color: #0F172A; min-width: 170px; text-align: center;" id="monthNameLabel">
                    Memuat...
                </div>
                <button type="button" class="sch-nav-btn" id="btnNextMonth" onclick="navigateMonth(1)" title="Bulan Berikutnya">
                    <iconify-icon icon="solar:alt-arrow-right-bold"></iconify-icon>
                </button>
                <button type="button" class="btn btn-secondary btn-sm" onclick="jumpToCurrentMonth()" style="font-weight: 700; font-size: 0.8rem;">
                    Bulan Ini
                </button>
            </div>

            <!-- Action Buttons: Tambah Sesi & Sinkronkan dari PROSEM Kaldik -->
            <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                <button type="button" class="btn btn-primary btn-sm" id="btnSyncKaldik" onclick="triggerSyncProsemKaldik()" style="background: #0F2D4A; border-color: #0F2D4A; display: inline-flex; align-items: center; gap: 6px;">
                    <iconify-icon icon="solar:refresh-circle-bold"></iconify-icon>
                    <span>Sinkronkan dari PROSEM & Kaldik</span>
                </button>
                <button type="button" class="btn btn-primary btn-sm" onclick="openAddModal()" style="background: #0D7A87; border-color: #0D7A87; display: inline-flex; align-items: center; gap: 6px;">
                    <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                    <span>Tambah Jadwal KBM</span>
                </button>
                <a href="<?= BASE_URL ?>/dashboard/guru/prota-prosem" class="btn btn-secondary btn-sm" style="display: inline-flex; align-items: center; gap: 6px;">
                    <iconify-icon icon="solar:notebook-bold"></iconify-icon>
                    <span>Prota & Prosem</span>
                </a>
            </div>
        </div>

        <div class="ilmuna-filter-bottomline">
            <!-- Filter Rombel & Mapel Dinamis (In-Place Async) -->
            <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0;">
                <select name="class_id" id="filterClassSelect" class="sch-select" onchange="applyCalendarFilters()">
                    <option value="0">-- Semua Rombel Kelas --</option>
                    <?php foreach ($uniqueClasses as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filterClassId === (int)$c['id'] ? 'selected' : '' ?>>
                            Kelas <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="mapel_id" id="filterMapelSelect" class="sch-select" onchange="applyCalendarFilters()">
                    <option value="0">-- Semua Mata Pelajaran --</option>
                    <?php foreach ($uniqueSubjects as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= $filterSubjectId === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Status Pills (In-Place Async) -->
                <div style="display: flex; gap: 4px; align-items: center; margin-left: 6px;" id="statusPillsGroup">
                    <span class="sch-filter-pill is-active" data-status="Semua" onclick="setStatusFilter('Semua')">Semua</span>
                    <span class="sch-filter-pill" data-status="Terlaksana" onclick="setStatusFilter('Terlaksana')">✓ Terlaksana</span>
                    <span class="sch-filter-pill" data-status="Belum Terlaksana" onclick="setStatusFilter('Belum Terlaksana')">○ Belum Terlaksana</span>
                    <span class="sch-filter-pill" data-status="Ditunda" onclick="setStatusFilter('Ditunda')">⏱ Ditunda</span>
                </div>
            </div>

            <!-- Status Kaldik & Info Semester Aktif -->
            <div style="font-size: 0.82rem; color: #64748B; font-weight: 600;">
                Tahun Ajaran: <strong style="color: #0D7A87;"><?= htmlspecialchars($tahunAktif) ?></strong> | Semester: <strong><?= htmlspecialchars($semesterAktif) ?></strong>
            </div>
        </div>
    </div>

    <!-- ==============================================================================
     * TAB 1: KALENDER BESAR KBM & KALDIK (INTERAKTIF DATA-FIRST)
     * ============================================================================== -->
    <div id="tab-content-calendar" style="display: <?= $activeTab === 'calendar' ? 'block' : 'none' ?>;">
        <div class="sch-cal-card" id="calCardWrapper">
            <div class="sch-grid" id="calendarGrid">
                <!-- Day Headers (Senin s/d Minggu/Ahad) -->
                <div class="sch-day-header">Senin</div>
                <div class="sch-day-header">Selasa</div>
                <div class="sch-day-header">Rabu</div>
                <div class="sch-day-header">Kamis</div>
                <div class="sch-day-header">Jumat</div>
                <div class="sch-day-header">Sabtu</div>
                <div class="sch-day-header sch-day-header--sun">Minggu / Ahad</div>

                <!-- Sel Hari diisi via Javascript secara in-place tanpa reload -->
            </div>
        </div>
    </div>

    <!-- ==============================================================================
     * TAB 2: MATRIKS AGENDA PROSEM & KBM (DATA-FIRST VIEW)
     * ============================================================================== -->
    <div id="tab-content-prosem" style="display: <?= $activeTab === 'prosem' ? 'block' : 'none' ?>;">
        <div class="content-card" style="border-radius: 20px; border: 1px solid #E2E8F0;">
            <div class="content-card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="lms-table">
                        <thead>
                            <tr>
                                <th style="width: 50px; text-align: center;">No</th>
                                <th>Tanggal & Waktu</th>
                                <th>Rombel & Ruang</th>
                                <th>Mata Pelajaran</th>
                                <th>Capaian (CP) & Elemen</th>
                                <th>Tujuan Pembelajaran (TP)</th>
                                <th>Kategori Agenda</th>
                                <th>Status Keterlaksanaan</th>
                                <th style="text-align: right;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody id="matriksTableBody">
                            <!-- Diisi via in-place Javascript tanpa reload -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ==============================================================================
     * TAB 3: ALOKASI JAM & RPE KALDIK (INTEGRASI TABEL REAL BASIS DATA)
     * ============================================================================== -->
    <div id="tab-content-rpe" style="display: <?= $activeTab === 'rpe' ? 'block' : 'none' ?>;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
            <!-- Tabel Rincian Pekan Efektif (RPE) -->
            <div class="content-card" style="border-radius: 20px; border: 1px solid #E2E8F0;">
                <div class="content-card-header">
                    <h4 style="margin: 0; font-size: 1rem; font-weight: 800; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                        <iconify-icon icon="solar:calendar-bold" style="color: #0D7A87;"></iconify-icon>
                        Distribusi Pekan Efektif Kaldik (RPE)
                    </h4>
                </div>
                <div class="content-card-body" style="padding: 0;">
                    <table class="lms-table">
                        <thead>
                            <tr>
                                <th>Bulan</th>
                                <th style="text-align: center;">Hari Efektif</th>
                                <th style="text-align: center;">Jml Pekan</th>
                                <th style="text-align: center;">Pekan Efektif</th>
                                <th>Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rpeList)): ?>
                                <tr><td colspan="5" style="text-align: center; padding: 25px; color: #64748B;">Data RPE Kaldik belum disinkronkan.</td></tr>
                            <?php else: ?>
                                <?php foreach ($rpeList as $rpe): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($rpe['bulan']) ?></strong></td>
                                        <td style="text-align: center; font-weight: 700;"><?= (int)$rpe['total_hari_efektif'] ?> Hari</td>
                                        <td style="text-align: center;"><?= (int)$rpe['jumlah_pekan_total'] ?></td>
                                        <td style="text-align: center; font-weight: 800; color: #10B981;"><?= (int)$rpe['jumlah_pekan_efektif'] ?></td>
                                        <td style="font-size: 0.78rem; color: #64748B;"><?= htmlspecialchars($rpe['keterangan_tidak_efektif'] ?: 'KBM Efektif') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Tabel Alokasi Hari Mengajar Guru (AHM) -->
            <div class="content-card" style="border-radius: 20px; border: 1px solid #E2E8F0;">
                <div class="content-card-header">
                    <h4 style="margin: 0; font-size: 1rem; font-weight: 800; color: #0F172A; display: flex; align-items: center; gap: 8px;">
                        <iconify-icon icon="solar:user-speak-bold" style="color: #0D7A87;"></iconify-icon>
                        Alokasi Hari Mengajar Guru (AHM)
                    </h4>
                </div>
                <div class="content-card-body" style="padding: 0;">
                    <table class="lms-table">
                        <thead>
                            <tr>
                                <th>Rombel & Mapel</th>
                                <th>Hari Mengajar</th>
                                <th style="text-align: center;">JP / Pekan</th>
                                <th style="text-align: center;">Total JTM</th>
                                <th>Pola Mengajar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($ahmList)): ?>
                                <tr><td colspan="5" style="text-align: center; padding: 25px; color: #64748B;">Data Alokasi Hari Mengajar belum tersedia.</td></tr>
                            <?php else: ?>
                                <?php foreach ($ahmList as $ahm): ?>
                                    <tr>
                                        <td>
                                            <strong>Kelas <?= htmlspecialchars($ahm['nama_kelas']) ?></strong>
                                            <div style="font-size: 0.75rem; color: #64748B;"><?= htmlspecialchars($ahm['nama_mata_pelajaran']) ?></div>
                                        </td>
                                        <td><span class="badge badge-navy"><?= htmlspecialchars($ahm['hari']) ?></span></td>
                                        <td style="text-align: center; font-weight: 800; color: #0D7A87;"><?= (int)$ahm['jp_per_minggu'] ?> JP</td>
                                        <td style="text-align: center; font-weight: 800;"><?= (int)$ahm['total_jtm'] ?> JP</td>
                                        <td style="font-size: 0.75rem; color: #475569;"><?= htmlspecialchars($ahm['pola_pekan_mengajar'] ?: 'Standar Semester') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL: DETAIL KBM PER TANGGAL (INTERACTIVE JAM-JAM MENGAJAR & STATUS)
 * ============================================================================== -->
<div class="ilm-modal-backdrop" id="dayDetailModal">
    <div class="ilm-modal-dialog" style="max-width: 780px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon" style="background: rgba(13, 122, 135, 0.12); color: #0D7A87;">
                    <iconify-icon icon="solar:calendar-date-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="dayModalTitle">Rincian KBM Guru</h3>
                    <p class="ilm-modal-header__subtitle" id="dayModalSubtitle">Jadwal tatap muka, rincian CP/TP, dan pelacakan status terlaksana</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('dayDetailModal')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>

        <div class="ilm-modal-body" style="max-height: calc(88vh - 170px); overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid #E2E8F0; flex-wrap: wrap; gap: 8px;">
                <div id="dayKaldikStatusBadge" style="font-size: 0.84rem; font-weight: 700;">
                    Memuat data Kaldik...
                </div>
                <button type="button" class="btn btn-primary btn-sm" onclick="openAddModalForCurrentDate()" style="background: #0D7A87; border-color: #0D7A87; font-size: 0.8rem;">
                    <iconify-icon icon="solar:add-circle-bold"></iconify-icon> Tambah Sesi di Tanggal Ini
                </button>
            </div>

            <div id="daySessionsContainer" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Diisi secara dinamis via Javascript -->
            </div>
        </div>

        <div class="ilm-modal-footer">
            <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('dayDetailModal')">
                <span>Tutup</span>
            </button>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL: FORM TAMBAH / EDIT SESI MENGAJAR GURU (CP, TP, JAM KE, AGENDA)
 * ============================================================================== -->
<div class="ilm-modal-backdrop" id="scheduleFormModal">
    <div class="ilm-modal-dialog" style="max-width: 680px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon" style="background: rgba(13, 122, 135, 0.12); color: #0D7A87;">
                    <iconify-icon icon="solar:calendar-add-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="formModalTitle">Tambah Jadwal Mengajar KBM</h3>
                    <p class="ilm-modal-header__subtitle">Alokasi kelas, mapel, jam pelajaran (JP), CP, dan TP</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('scheduleFormModal')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>

        <form id="scheduleForm" onsubmit="handleSaveSchedule(event)">
            <input type="hidden" name="id" id="formSchedId" value="0">
            <input type="hidden" name="hari" id="formHari" value="Senin">

            <div class="ilm-modal-body" style="max-height: calc(88vh - 170px); overflow-y: auto;">
                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-weight: 700;">Rombel Kelas & Mata Pelajaran *</label>
                    <select name="id_kelas_mata_pelajaran" id="formKmp" class="form-control" required onchange="handleKmpChange()">
                        <option value="">-- Pilih Rombel & Mata Pelajaran --</option>
                        <?php foreach ($kmps as $k): ?>
                            <option value="<?= $k['id_kmp'] ?>">
                                Kelas <?= htmlspecialchars($k['nama_kelas']) ?> — <?= htmlspecialchars($k['nama_mata_pelajaran']) ?> (<?= htmlspecialchars($k['kode_mapel']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">Tanggal Pelaksanaan *</label>
                        <input type="date" name="tanggal" id="formTanggal" class="form-control" required onchange="updateHariFromDate(this.value)">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">Jenis Agenda / Kegiatan *</label>
                        <select name="jenis_agenda" id="formJenisAgenda" class="form-control" required>
                            <option value="Materi KBM">Materi KBM (Tatap Muka)</option>
                            <option value="Asesmen Sumatif">Asesmen Sumatif (Lingkup Materi)</option>
                            <option value="Sumatif Tengah Semester (STS)">Sumatif Tengah Semester (STS)</option>
                            <option value="Asesmen Akhir Semester (ASAS)">Asesmen Akhir Semester (ASAS)</option>
                            <option value="Remedial & Pengayaan">Remedial & Pengayaan</option>
                            <option value="Projek Penguatan P5">Projek Penguatan P5</option>
                        </select>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; margin-bottom: 14px;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">Jam Ke</label>
                        <input type="text" name="jam_ke" id="formJamKe" class="form-control" placeholder="1-2" value="1-2">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">Jam Mulai *</label>
                        <input type="time" name="jam_mulai" id="formJamMulai" class="form-control" value="07:30" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">Jam Selesai *</label>
                        <input type="time" name="jam_selesai" id="formJamSelesai" class="form-control" value="09:00" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">Alokasi JP</label>
                        <input type="number" name="alokasi_jp" id="formAlokasiJp" class="form-control" value="2" min="1" max="6">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-weight: 700;">Ruang Pembelajaran</label>
                    <input type="text" name="ruangan" id="formRuangan" class="form-control" placeholder="Contoh: Ruang Kelas X-A, Lab Keagamaan, Masjid" value="Ruang Kelas">
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-weight: 700;">Capaian Pembelajaran (CP) / Elemen</label>
                    <select name="id_cp" id="formCp" class="form-control" onchange="filterTpByCp(this.value)">
                        <option value="">-- Pilih Capaian Pembelajaran (CP) --</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-weight: 700;">Tujuan Pembelajaran (TP)</label>
                    <select name="id_tp" id="formTp" class="form-control" onchange="autoFillMateriFromTp(this)">
                        <option value="">-- Pilih Tujuan Pembelajaran (TP) --</option>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 14px;">
                    <label class="form-label" style="font-weight: 700;">Materi Pokok / Bahasan KBM</label>
                    <textarea name="materi_pembahasan" id="formMateri" class="form-control" rows="2" placeholder="Tuliskan materi pokok bahasan KBM..."></textarea>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">Status Pelaksanaan KBM</label>
                        <select name="status_pelaksanaan" id="formStatusPelaksanaan" class="form-control">
                            <option value="Belum Terlaksana">○ Belum Terlaksana</option>
                            <option value="Terlaksana">✓ Terlaksana</option>
                            <option value="Tidak Terlaksana">✕ Tidak Terlaksana</option>
                            <option value="Ditunda">⏱ Ditunda</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight: 700;">Catatan / Jurnal Guru</label>
                        <input type="text" name="catatan_pelaksanaan" id="formCatatan" class="form-control" placeholder="Catatan singkat realisasi kelas...">
                    </div>
                </div>
            </div>

            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('scheduleFormModal')">
                    <span>Batal</span>
                </button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary" id="btnSubmitForm" style="background: #0D7A87;">
                    <iconify-icon icon="solar:disk-bold"></iconify-icon>
                    <span>Simpan Jadwal KBM</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// ==============================================================================
// GLOBAL STATE (ZERO PAGE RELOAD ARCHITECTURE)
// ==============================================================================
let currentMonth = <?= $filterMonth ?>;
let currentYear = <?= $filterYear ?>;
let activeStatusFilter = '<?= $filterStatus ?>';
let activeDateSelected = '<?= date('Y-m-d') ?>';
let globalCps = [];
let globalTps = [];
let currentMonthData = null;

// Ganti Tab Segmented (In-Place DOM Switching)
function switchViewTab(tabName) {
    document.querySelectorAll('.curriculum-tab-btn').forEach(b => b.classList.remove('is-active'));
    document.querySelectorAll('[id^="tab-content-"]').forEach(c => c.style.display = 'none');

    const targetBtn = document.querySelector(`.curriculum-tab-btn[onclick*="'${tabName}'"]`);
    if (targetBtn) targetBtn.classList.add('is-active');

    const targetContent = document.getElementById(`tab-content-${tabName}`);
    if (targetContent) targetContent.style.display = 'block';

    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url.toString());
}

// Navigasi Bulan (100% In-Place Async Tanpa Reload)
function navigateMonth(direction) {
    currentMonth += direction;
    if (currentMonth < 1) {
        currentMonth = 12;
        currentYear--;
    } else if (currentMonth > 12) {
        currentMonth = 1;
        currentYear++;
    }
    loadCalendarDataAsync();
}

function jumpToCurrentMonth() {
    currentMonth = <?= $currentMonthActual ?>;
    currentYear = <?= $currentYearActual ?>;
    loadCalendarDataAsync();
}

function applyCalendarFilters() {
    loadCalendarDataAsync();
}

function setStatusFilter(statusVal) {
    activeStatusFilter = statusVal;
    document.querySelectorAll('#statusPillsGroup .sch-filter-pill').forEach(p => {
        if (p.getAttribute('data-status') === statusVal) p.classList.add('is-active');
        else p.classList.remove('is-active');
    });
    loadCalendarDataAsync();
}

// Load Data Kalender Bulanan Asinkron (Zero Page Reload)
function loadCalendarDataAsync() {
    const classId = document.getElementById('filterClassSelect').value;
    const mapelId = document.getElementById('filterMapelSelect').value;
    const calCard = document.getElementById('calCardWrapper');

    if (calCard) calCard.style.opacity = '0.4';

    const formData = new FormData();
    formData.append('ajax_action', 'get_month_calendar_data');
    formData.append('month', currentMonth);
    formData.append('year', currentYear);
    formData.append('class_id', classId);
    formData.append('mapel_id', mapelId);
    formData.append('status', activeStatusFilter);

    fetch('<?= BASE_URL ?>/dashboard/guru/schedules', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (calCard) calCard.style.opacity = '1';
        if (!res.success) {
            window.Ilmuna.error('Gagal', res.message || 'Gagal memuat kalender');
            return;
        }

        currentMonthData = res.data;
        renderCalendarInPlace(currentMonthData);
        renderMatriksTableInPlace(currentMonthData.schedules_list || []);

        // Update URL secara halus tanpa reload
        const url = new URL(window.location.href);
        url.searchParams.set('month', currentMonth);
        url.searchParams.set('year', currentYear);
        url.searchParams.set('class_id', classId);
        url.searchParams.set('mapel_id', mapelId);
        url.searchParams.set('status', activeStatusFilter);
        window.history.replaceState({}, '', url.toString());
    })
    .catch(err => {
        if (calCard) calCard.style.opacity = '1';
        window.Ilmuna.error('Error', err.message);
    });
}

// Render Ulang Kalender In-Place (Zero Page Reload)
function renderCalendarInPlace(data) {
    document.getElementById('monthNameLabel').innerText = data.month_name;
    document.getElementById('tabCounterCalendar').innerText = data.total_sesi + ' Sesi';
    document.getElementById('tabCounterProsem').innerText = data.total_jp + ' JP';

    const grid = document.getElementById('calendarGrid');
    
    // Header hari tetap
    let html = `
        <div class="sch-day-header">Senin</div>
        <div class="sch-day-header">Selasa</div>
        <div class="sch-day-header">Rabu</div>
        <div class="sch-day-header">Kamis</div>
        <div class="sch-day-header">Jumat</div>
        <div class="sch-day-header">Sabtu</div>
        <div class="sch-day-header sch-day-header--sun">Minggu / Ahad</div>
    `;

    // Padding hari sebelum tanggal 1
    const firstDow = data.first_day_of_week;
    for (let p = 1; p < firstDow; p++) {
        html += `<div class="sch-cell sch-cell--muted"><div class="sch-cell-top"><span class="sch-date-num">•</span></div></div>`;
    }

    const todayIso = '<?= date('Y-m-d') ?>';
    const schedulesByDate = data.schedules_by_date || {};
    const kaldikEvents = data.kaldik_events || {};

    for (let day = 1; day <= data.days_in_month; day++) {
        const mStr = String(data.month).padStart(2, '0');
        const dStr = String(day).padStart(2, '0');
        const dateIso = `${data.year}-${mStr}-${dStr}`;

        const isToday = (dateIso === todayIso);
        const dayDow = new Date(dateIso + 'T00:00:00').getDay();
        const isSun = (dayDow === 0);

        let cellCls = 'sch-cell';
        if (isToday) cellCls += ' sch-cell--today';
        if (isSun) cellCls += ' sch-cell--sun';

        const dayScheds = schedulesByDate[dateIso] || [];
        const kaldik = kaldikEvents[dateIso];

        html += `<div class="${cellCls}" data-date="${dateIso}" onclick="openDayDetailModal('${dateIso}')">`;
        html += `  <div class="sch-cell-top">`;
        html += `    <span class="sch-date-num">${day}</span>`;
        if (dayScheds.length > 0) {
            html += `    <span style="font-size: 0.7rem; font-weight: 800; color: #0D7A87; background: rgba(13,122,135,0.1); padding: 2px 6px; border-radius: 6px;">${dayScheds.length} KBM</span>`;
        }
        html += `  </div>`;

        if (kaldik && kaldik.status_hari !== 'Efektif KBM') {
            const isUjian = (kaldik.keterangan.includes('STS') || kaldik.keterangan.includes('ASAS') || kaldik.keterangan.includes('Ujian'));
            const kCls = isUjian ? 'sch-kaldik-badge sch-kaldik-badge--ujian' : 'sch-kaldik-badge';
            html += `  <span class="${kCls}" title="${escapeHtml(kaldik.keterangan)}">🏷️ ${escapeHtml(kaldik.keterangan)}</span>`;
        }

        if (dayScheds.length > 0) {
            html += `  <div class="sch-pills-list">`;
            dayScheds.forEach(sc => {
                const agendaLower = (sc.jenis_agenda || '').toLowerCase();
                let pMod = 'sch-pill--kbm';
                if (agendaLower.includes('sumatif')) pMod = 'sch-pill--sumatif';
                else if (agendaLower.includes('sts')) pMod = 'sch-pill--sts';
                else if (agendaLower.includes('asas')) pMod = 'sch-pill--asas';

                const stLower = (sc.status_pelaksanaan || 'belum terlaksana').toLowerCase();
                let dMod = 'sch-status-dot--belum';
                if (stLower === 'terlaksana') dMod = 'sch-status-dot--terlaksana';
                else if (stLower === 'tidak terlaksana') dMod = 'sch-status-dot--tidak';
                else if (stLower === 'ditunda') dMod = 'sch-status-dot--ditunda';

                const jamDisplay = (sc.jam_mulai || '').substring(0, 5);
                const jamKeBadge = sc.jam_ke ? 'J.' + sc.jam_ke : (sc.alokasi_jp + 'JP');
                const materi = sc.tp_materi_pokok || sc.materi_pembahasan || '';

                html += `
                    <div class="sch-pill ${pMod}" id="pill-sched-${sc.id}">
                        <div style="font-weight: 800; color: #0F172A; display: flex; justify-content: space-between;">
                            <span><span class="sch-status-dot ${dMod}"></span>${jamDisplay}</span>
                            <span style="font-size: 0.68rem; color: #0D7A87;">${escapeHtml(jamKeBadge)}</span>
                        </div>
                        <div style="color: #475569; font-size: 0.71rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <strong>${escapeHtml(sc.nama_kelas || 'Kelas')}</strong> · ${escapeHtml(sc.kode_mapel || sc.nama_mata_pelajaran)}
                        </div>
                        ${materi ? `<div style="color: #1E293B; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.7rem;">${escapeHtml(materi.substring(0, 24))}</div>` : ''}
                    </div>
                `;
            });
            html += `  </div>`;
        }

        html += `</div>`;
    }

    // Trailing padding cells
    const lastDow = data.last_day_of_week;
    if (lastDow < 7) {
        for (let t = 1; t <= (7 - lastDow); t++) {
            html += `<div class="sch-cell sch-cell--muted"><div class="sch-cell-top"><span class="sch-date-num">•</span></div></div>`;
        }
    }

    grid.innerHTML = html;
}

// Render Tabel Matriks PROSEM In-Place (Zero Page Reload)
function renderMatriksTableInPlace(schedules) {
    const tbody = document.getElementById('matriksTableBody');
    if (!tbody) return;

    if (!schedules || schedules.length === 0) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; padding: 40px; color: #64748B;">Belum ada jadwal KBM untuk kriteria ini. Klik "Sinkronkan dari PROSEM & Kaldik".</td></tr>`;
        return;
    }

    let html = '';
    schedules.forEach((sc, idx) => {
        let stClass = 'badge-sky';
        if (sc.status_pelaksanaan === 'Terlaksana') stClass = 'badge-emerald';
        else if (sc.status_pelaksanaan === 'Tidak Terlaksana') stClass = 'badge-rose';
        else if (sc.status_pelaksanaan === 'Ditunda') stClass = 'badge-gold';

        html += `
            <tr id="row-sched-${sc.id}" class="row-smooth-in">
                <td style="text-align: center; font-weight: 700;">${idx + 1}</td>
                <td>
                    <div style="font-weight: 700; color: #0F172A;">${sc.tanggal} (${escapeHtml(sc.hari)})</div>
                    <div style="font-size: 0.78rem; color: #0D7A87;">Jam Ke: ${escapeHtml(sc.jam_ke || '1-2')} (${(sc.jam_mulai||'').substring(0,5)} - ${(sc.jam_selesai||'').substring(0,5)})</div>
                </td>
                <td>
                    <span class="badge badge-navy">${escapeHtml(sc.nama_kelas || '-')}</span>
                    <div style="font-size: 0.75rem; color: #64748B; margin-top: 2px;">${escapeHtml(sc.ruangan || 'Ruang Kelas')}</div>
                </td>
                <td>
                    <strong>${escapeHtml(sc.nama_mata_pelajaran)}</strong>
                    <div style="font-size: 0.75rem; color: #64748B;">[${escapeHtml(sc.kode_mapel || '-')}]</div>
                </td>
                <td>
                    <span class="badge badge-teal">${escapeHtml(sc.nama_elemen || 'Kurikulum Merdeka')}</span>
                    <div style="font-size: 0.78rem; color: #334155; margin-top: 3px; max-width: 220px;">
                        ${escapeHtml((sc.capaian_pembelajaran || '-').substring(0, 70))}...
                    </div>
                </td>
                <td>
                    <strong style="color: #0D7A87;">${escapeHtml(sc.kode_tp || 'TP')}:</strong>
                    <div style="font-size: 0.8rem; color: #1E293B; max-width: 240px;">
                        ${escapeHtml(sc.uraian_tp || sc.materi_pembahasan || '-')}
                    </div>
                </td>
                <td><span class="badge badge-gold">${escapeHtml(sc.jenis_agenda || 'Materi KBM')}</span></td>
                <td><span class="badge ${stClass}" id="table-status-${sc.id}">${escapeHtml(sc.status_pelaksanaan || 'Belum Terlaksana')}</span></td>
                <td style="text-align: right;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="openDayDetailModal('${sc.tanggal}')" title="Buka Detail">
                        <iconify-icon icon="solar:eye-bold"></iconify-icon>
                    </button>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

// Buka Modal Rincian Hari (Ketika Sel Tanggal Di-Klik)
function openDayDetailModal(tanggal) {
    activeDateSelected = tanggal;
    const dateObj = new Date(tanggal + 'T00:00:00');
    const dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    
    const formattedDate = dayNames[dateObj.getDay()] + ', ' + dateObj.getDate() + ' ' + monthNames[dateObj.getMonth()] + ' ' + dateObj.getFullYear();
    document.getElementById('dayModalTitle').innerText = 'Jadwal KBM: ' + formattedDate;

    const container = document.getElementById('daySessionsContainer');
    container.innerHTML = '<div style="text-align: center; padding: 35px; color: #64748B;"><iconify-icon icon="solar:spinner-line" class="ilm-spin" style="font-size: 2rem;"></iconify-icon><br>Memuat jadwal tatap muka...</div>';

    openIlmunaModal('dayDetailModal');

    const formData = new FormData();
    formData.append('ajax_action', 'get_schedules_by_date');
    formData.append('tanggal', tanggal);

    fetch('<?= BASE_URL ?>/dashboard/guru/schedules', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (!res.success) {
            container.innerHTML = '<div class="alert alert-danger">' + (res.message || 'Gagal memuat jadwal.') + '</div>';
            return;
        }

        const kaldik = res.data.kaldik || {};
        const schedules = res.data.schedules || [];

        // Kaldik badge banner
        let kaldikHtml = '';
        if (kaldik.status_hari && kaldik.status_hari !== 'Efektif KBM') {
            kaldikHtml = `<span style="color: #BE123C; background: rgba(225,29,72,0.1); padding: 4px 10px; border-radius: 8px;">🏷️ Kaldik: ${escapeHtml(kaldik.keterangan || kaldik.status_hari)} (Non-Efektif KBM)</span>`;
        } else {
            kaldikHtml = `<span style="color: #0D7A87; background: rgba(13,122,135,0.1); padding: 4px 10px; border-radius: 8px;">✓ Kaldik: Hari Efektif KBM (${schedules.length} Sesi Terjadwal)</span>`;
        }
        document.getElementById('dayKaldikStatusBadge').innerHTML = kaldikHtml;

        if (schedules.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 40px 20px; background: #F8FAFC; border-radius: 16px; border: 1px dashed #CBD5E1;">
                    <iconify-icon icon="solar:calendar-minimalistic-bold-duotone" style="font-size: 3.2rem; color: #94A3B8;"></iconify-icon>
                    <h4 style="margin: 12px 0 6px; font-weight: 700; color: #334155;">Tidak Ada Jadwal Mengajar</h4>
                    <p style="color: #64748B; font-size: 0.88rem; max-width: 400px; margin: 0 auto 16px;">
                        Belum ada sesi KBM yang dialokasikan pada tanggal ini. Anda dapat menambahkan sesi baru atau menyinkronkan dari PROSEM.
                    </p>
                    <button type="button" class="btn btn-primary btn-sm" onclick="openAddModalForCurrentDate()" style="background: #0D7A87; border-color: #0D7A87;">
                        <iconify-icon icon="solar:add-circle-bold"></iconify-icon> Tambah Sesi KBM
                    </button>
                </div>
            `;
            return;
        }

        let html = '';
        schedules.forEach(item => {
            const currentStatus = item.status_pelaksanaan || 'Belum Terlaksana';
            const catatan = item.catatan_pelaksanaan || '';

            html += `
                <div class="content-card" style="border: 1px solid #E2E8F0; border-radius: 14px; padding: 14px; margin: 0;" id="card-session-${item.id}">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 6px;">
                        <div style="font-size: 0.95rem; font-weight: 800; color: #0F172A; display: flex; align-items: center; gap: 6px;">
                            <iconify-icon icon="solar:clock-circle-bold" style="color: #0D7A87;"></iconify-icon>
                            ${item.jam_mulai.substring(0,5)} - ${item.jam_selesai.substring(0,5)} WIB
                            <span style="font-size: 0.8rem; font-weight: 700; color: #64748B;">(Jam Ke: ${item.jam_ke || '1-2'})</span>
                        </div>
                        <div style="display: flex; gap: 6px;">
                            <span class="badge badge-navy">Kelas ${escapeHtml(item.nama_kelas || '-')}</span>
                            <span class="badge badge-gold">${escapeHtml(item.jenis_agenda || 'Materi KBM')}</span>
                            <span class="badge badge-teal">${escapeHtml(item.ruangan || 'Ruang Kelas')}</span>
                        </div>
                    </div>

                    <div style="margin-bottom: 10px;">
                        <h4 style="margin: 0 0 2px; font-size: 1rem; font-weight: 800; color: #0F172A;">
                            ${escapeHtml(item.nama_mata_pelajaran)} [${escapeHtml(item.kode_mapel || '-')}]
                        </h4>
                        <div style="font-size: 0.84rem; color: #334155;">
                            Materi Pokok: <strong style="color: #0D7A87;">${escapeHtml(item.materi_pembahasan || item.tp_materi_pokok || '-')}</strong>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 12px; font-size: 0.82rem;">
                        <div style="background: #F8FAFC; padding: 8px 10px; border-radius: 8px; border: 1px solid #EEF2F6;">
                            <div style="font-size: 0.72rem; font-weight: 700; color: #64748B; text-transform: uppercase;">Capaian (CP) & Elemen</div>
                            <div>${item.nama_elemen ? '<strong>[' + escapeHtml(item.nama_elemen) + ']</strong> ' : ''}${escapeHtml(item.capaian_pembelajaran ? item.capaian_pembelajaran.substring(0, 95) + '...' : '-')}</div>
                        </div>
                        <div style="background: #F8FAFC; padding: 8px 10px; border-radius: 8px; border: 1px solid #EEF2F6;">
                            <div style="font-size: 0.72rem; font-weight: 700; color: #64748B; text-transform: uppercase;">Tujuan Pembelajaran (TP)</div>
                            <div>${item.kode_tp ? '<strong>[' + escapeHtml(item.kode_tp) + ']</strong> ' : ''}${escapeHtml(item.uraian_tp || '-')}</div>
                        </div>
                    </div>

                    <!-- 1-Click Status Keterlaksanaan -->
                    <div style="background: #F1F5F9; padding: 10px 12px; border-radius: 10px; margin-bottom: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 0.76rem; font-weight: 700; color: #475569; text-transform: uppercase;">Status Pelaksanaan KBM:</span>
                            <span id="badge-status-${item.id}" style="font-size: 0.82rem; font-weight: 800; color: #0F172A;">${currentStatus}</span>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 6px;" id="status-buttons-${item.id}">
                            <button type="button" class="btn btn-sm ${currentStatus === 'Terlaksana' ? 'btn-primary' : 'btn-secondary'}" style="${currentStatus === 'Terlaksana' ? 'background: #10B981; border-color: #059669;' : ''}" onclick="updateScheduleStatus(${item.id}, 'Terlaksana')">
                                <iconify-icon icon="solar:check-circle-bold"></iconify-icon> ✓ Terlaksana
                            </button>
                            <button type="button" class="btn btn-sm ${currentStatus === 'Belum Terlaksana' ? 'btn-primary' : 'btn-secondary'}" onclick="updateScheduleStatus(${item.id}, 'Belum Terlaksana')">
                                <iconify-icon icon="solar:clock-circle-bold"></iconify-icon> ○ Belum Terlaksana
                            </button>
                            <button type="button" class="btn btn-sm ${currentStatus === 'Tidak Terlaksana' ? 'btn-danger' : 'btn-secondary'}" onclick="updateScheduleStatus(${item.id}, 'Tidak Terlaksana')">
                                <iconify-icon icon="solar:close-circle-bold"></iconify-icon> ✕ Tidak Terlaksana
                            </button>
                            <button type="button" class="btn btn-sm ${currentStatus === 'Ditunda' ? 'btn-primary' : 'btn-secondary'}" style="${currentStatus === 'Ditunda' ? 'background: #F59E0B; border-color: #D97706;' : ''}" onclick="updateScheduleStatus(${item.id}, 'Ditunda')">
                                <iconify-icon icon="solar:history-bold"></iconify-icon> ⏱ Ditunda
                            </button>
                        </div>
                    </div>

                    <!-- Jurnal Catatan Guru -->
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" id="jurnal-input-${item.id}" class="form-control" style="font-size: 0.82rem; padding: 6px 10px;" placeholder="Tuliskan catatan jurnal / realisasi materi..." value="${escapeHtml(catatan)}">
                        <button type="button" class="btn btn-secondary btn-sm" onclick="saveJurnalNote(${item.id})" style="white-space: nowrap; font-size: 0.8rem;">
                            <iconify-icon icon="solar:disk-bold"></iconify-icon> Simpan Jurnal
                        </button>
                        <button type="button" class="btn btn-danger btn-sm" onclick="deleteSchedule(${item.id})" title="Hapus Sesi">
                            <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
                        </button>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    })
    .catch(err => {
        container.innerHTML = '<div class="alert alert-danger">Error: ' + err.message + '</div>';
    });
}

// 1-Click Update Status Keterlaksanaan (In-Place DOM Mutation)
function updateScheduleStatus(scheduleId, newStatus) {
    const formData = new FormData();
    formData.append('ajax_action', 'update_status_pelaksanaan');
    formData.append('schedule_id', scheduleId);
    formData.append('status_pelaksanaan', newStatus);

    fetch('<?= BASE_URL ?>/dashboard/guru/schedules', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (!res.success) {
            window.Ilmuna.error('Gagal', res.message || 'Gagal mengubah status');
            return;
        }

        // 1. Update text badge di modal
        const badge = document.getElementById('badge-status-' + scheduleId);
        if (badge) badge.innerText = newStatus;

        // 2. Update status button highlight di modal
        const btnGroup = document.getElementById('status-buttons-' + scheduleId);
        if (btnGroup) {
            btnGroup.querySelectorAll('button').forEach(btn => {
                btn.className = 'btn btn-secondary btn-sm';
                btn.style.background = '';
                btn.style.borderColor = '';
            });
            const activeBtn = btnGroup.querySelector(`button[onclick*="'${newStatus}'"]`);
            if (activeBtn) {
                activeBtn.className = 'btn btn-primary btn-sm';
                if (newStatus === 'Terlaksana') {
                    activeBtn.style.background = '#10B981';
                    activeBtn.style.borderColor = '#059669';
                } else if (newStatus === 'Tidak Terlaksana') {
                    activeBtn.className = 'btn btn-danger btn-sm';
                } else if (newStatus === 'Ditunda') {
                    activeBtn.style.background = '#F59E0B';
                    activeBtn.style.borderColor = '#D97706';
                }
            }
        }

        // 3. Update pill dot di Kalender Besar (In-Place)
        const pill = document.getElementById('pill-sched-' + scheduleId);
        if (pill) {
            const dot = pill.querySelector('.sch-status-dot');
            if (dot) {
                dot.className = 'sch-status-dot';
                if (newStatus === 'Terlaksana') dot.classList.add('sch-status-dot--terlaksana');
                else if (newStatus === 'Tidak Terlaksana') dot.classList.add('sch-status-dot--tidak');
                else if (newStatus === 'Ditunda') dot.classList.add('sch-status-dot--ditunda');
                else dot.classList.add('sch-status-dot--belum');
            }
            pill.classList.remove('row-flash');
            void pill.offsetWidth;
            pill.classList.add('row-flash');
        }

        // 4. Update status badge di Tab 2 Matriks
        const tblBadge = document.getElementById('table-status-' + scheduleId);
        if (tblBadge) {
            tblBadge.innerText = newStatus;
            tblBadge.className = 'badge';
            if (newStatus === 'Terlaksana') tblBadge.classList.add('badge-emerald');
            else if (newStatus === 'Tidak Terlaksana') tblBadge.classList.add('badge-rose');
            else if (newStatus === 'Ditunda') tblBadge.classList.add('badge-gold');
            else tblBadge.classList.add('badge-sky');
        }

        window.Ilmuna.success('Status Diperbarui', res.message);
    })
    .catch(err => window.Ilmuna.error('Error', err.message));
}

// Simpan Catatan Jurnal Mengajar (In-Place)
function saveJurnalNote(scheduleId) {
    const input = document.getElementById('jurnal-input-' + scheduleId);
    if (!input) return;

    const val = input.value.trim();
    const formData = new FormData();
    formData.append('ajax_action', 'save_jurnal');
    formData.append('schedule_id', scheduleId);
    formData.append('catatan_pelaksanaan', val);

    fetch('<?= BASE_URL ?>/dashboard/guru/schedules', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (!res.success) {
            window.Ilmuna.error('Gagal', res.message || 'Gagal menyimpan catatan');
            return;
        }
        window.Ilmuna.success('Tersimpan', res.message);
    })
    .catch(err => window.Ilmuna.error('Error', err.message));
}

// Trigger Sinkronisasi Otomatis dari PROSEM & Kaldik (100% In-Place Async Tanpa Reload)
function triggerSyncProsemKaldik() {
    const currentKmpId = document.getElementById('filterClassSelect').value;
    const kmpIdToUse = currentKmpId > 0 ? currentKmpId : '<?= $firstKmpId ?>';

    window.Ilmuna.confirm(
        'warning',
        'Sinkronisasi dari PROSEM & Kaldik?',
        'Sistem akan memetakan agenda PROSEM secara berurutan ke tanggal-tanggal efektif KBM di Kalender Pendidikan sekolah secara otomatis. Lanjutkan?',
        'Ya, Sinkronkan Sekarang',
        'Batal'
    ).then(confirmed => {
        if (!confirmed) return;

        const btn = document.getElementById('btnSyncKaldik');
        if (btn) btn.disabled = true;

        const formData = new FormData();
        formData.append('ajax_action', 'sync_from_prosem_kaldik');
        formData.append('id_kmp', kmpIdToUse);
        formData.append('tahun', '<?= $tahunAktif ?>');
        formData.append('semester', '<?= $semesterAktif ?>');

        fetch('<?= BASE_URL ?>/dashboard/guru/schedules', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(res => {
            if (btn) btn.disabled = false;
            if (!res.success) {
                window.Ilmuna.error('Sinkronisasi Gagal', res.message);
                return;
            }
            window.Ilmuna.success('Berhasil', res.message);
            // Muat ulang data kalender secara asinkron tanpa reload halaman
            loadCalendarDataAsync();
        })
        .catch(err => {
            if (btn) btn.disabled = false;
            window.Ilmuna.error('Error', err.message);
        });
    });
}

// Hapus Jadwal dengan Konfirmasi Ilmuna & In-Place Removal
function deleteSchedule(id) {
    window.Ilmuna.confirm(
        'danger',
        'Hapus Jadwal Mengajar?',
        'Apakah Anda yakin ingin menghapus sesi KBM ini dari jadwal mengajar?',
        'Ya, Hapus Jadwal',
        'Batal'
    ).then(confirmed => {
        if (!confirmed) return;

        const formData = new FormData();
        formData.append('ajax_action', 'delete_schedule');
        formData.append('id', id);

        fetch('<?= BASE_URL ?>/dashboard/guru/schedules', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(res => {
            if (!res.success) {
                window.Ilmuna.error('Gagal', res.message || 'Gagal menghapus jadwal');
                return;
            }
            window.Ilmuna.success('Terhapus', res.message);

            // In-place remove dari modal
            const card = document.getElementById('card-session-' + id);
            if (card) {
                card.classList.add('row-smooth-out');
                setTimeout(() => card.remove(), 320);
            }

            // In-place remove dari sel kalender & update cell top badge
            const pill = document.getElementById('pill-sched-' + id);
            if (pill) {
                const cell = pill.closest('.sch-cell');
                pill.classList.add('row-smooth-out');
                setTimeout(() => {
                    pill.remove();
                    if (cell) {
                        const remaining = cell.querySelectorAll('.sch-pill').length;
                        const topEl = cell.querySelector('.sch-cell-top');
                        if (topEl) {
                            const badge = topEl.querySelector('span:nth-child(2)');
                            if (badge) {
                                if (remaining > 0) badge.innerText = remaining + ' KBM';
                                else badge.remove();
                            }
                        }
                    }
                }, 320);
            }

            // In-place remove dari Tab 2 Matriks
            const row = document.getElementById('row-sched-' + id);
            if (row) {
                row.classList.add('row-smooth-out');
                setTimeout(() => row.remove(), 320);
            }

            // Update counter tab
            const tabCounter = document.getElementById('tabCounterCalendar');
            if (tabCounter) {
                const current = parseInt(tabCounter.innerText || '0', 10);
                if (current > 0) tabCounter.innerText = (current - 1) + ' Sesi';
            }
        })
        .catch(err => window.Ilmuna.error('Error', err.message));
    });
}

// Modal Form Tambah/Edit Jadwal
function openAddModalForCurrentDate() {
    closeIlmunaModal('dayDetailModal');
    openAddModal(activeDateSelected);
}

function openAddModal(targetDate = null) {
    document.getElementById('formSchedId').value = '0';
    document.getElementById('formModalTitle').innerText = 'Tambah Jadwal Mengajar KBM';
    document.getElementById('btnSubmitForm').querySelector('span').innerText = 'Simpan Jadwal KBM';

    const defaultDate = targetDate || activeDateSelected || '<?= date('Y-m-d') ?>';
    document.getElementById('formTanggal').value = defaultDate;
    updateHariFromDate(defaultDate);

    document.getElementById('formJamKe').value = '1-2';
    document.getElementById('formJamMulai').value = '07:30';
    document.getElementById('formJamSelesai').value = '09:00';
    document.getElementById('formAlokasiJp').value = '2';
    document.getElementById('formMateri').value = '';
    document.getElementById('formCatatan').value = '';
    document.getElementById('formStatusPelaksanaan').value = 'Belum Terlaksana';

    openIlmunaModal('scheduleFormModal');
}

function updateHariFromDate(dateStr) {
    if (!dateStr) return;
    const daysMap = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    const d = new Date(dateStr + 'T00:00:00');
    const dayName = daysMap[d.getDay()] || 'Senin';
    document.getElementById('formHari').value = dayName;
}

function handleKmpChange() {
    const kmpId = document.getElementById('formKmp').value;
    if (!kmpId) {
        document.getElementById('formCp').innerHTML = '<option value="">-- Pilih Capaian Pembelajaran (CP) --</option>';
        document.getElementById('formTp').innerHTML = '<option value="">-- Pilih Tujuan Pembelajaran (TP) --</option>';
        return;
    }

    const formData = new FormData();
    formData.append('ajax_action', 'get_cp_tp_by_kmp');
    formData.append('id_kmp', kmpId);

    fetch('<?= BASE_URL ?>/dashboard/guru/schedules', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        if (!res.success) return;
        globalCps = res.data.cps || [];
        globalTps = res.data.tps || [];

        // CP Dropdown
        const cpSel = document.getElementById('formCp');
        let cpHtml = '<option value="">-- Pilih Capaian Pembelajaran (CP) --</option>';
        globalCps.forEach(c => {
            const elName = c.nama_elemen ? '[' + c.nama_elemen + '] ' : '';
            const cpText = c.capaian_pembelajaran ? c.capaian_pembelajaran.substring(0, 80) + '...' : '-';
            cpHtml += `<option value="${c.id}">${elName}${cpText}</option>`;
        });
        cpSel.innerHTML = cpHtml;

        // TP Dropdown
        populateTpDropdown(globalTps);
    });
}

function filterTpByCp(cpId) {
    if (!cpId) {
        populateTpDropdown(globalTps);
        return;
    }
    const filtered = globalTps.filter(t => String(t.id_cp) === String(cpId));
    populateTpDropdown(filtered.length > 0 ? filtered : globalTps);
}

function populateTpDropdown(tpsList) {
    const tpSel = document.getElementById('formTp');
    let tpHtml = '<option value="">-- Pilih Tujuan Pembelajaran (TP) --</option>';
    tpsList.forEach(t => {
        tpHtml += `<option value="${t.id}" data-materi="${escapeHtml(t.materi_pokok || '')}" data-jp="${t.alokasi_jam || 2}">[${t.kode_tp || 'TP'}] ${t.tp}</option>`;
    });
    tpSel.innerHTML = tpHtml;
}

function autoFillMateriFromTp(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    if (opt && opt.dataset.materi) {
        const materiArea = document.getElementById('formMateri');
        if (!materiArea.value.trim()) {
            materiArea.value = opt.dataset.materi;
        }
    }
}

// Mutasi DOM In-Place Secara Mulus (Tanpa Reload / Tanpa Render Ulang - README.md Section 4.4)
function mutateScheduleInPlace(sc) {
    if (!sc || !sc.id) return;

    const agendaLower = (sc.jenis_agenda || '').toLowerCase();
    let pMod = 'sch-pill--kbm';
    if (agendaLower.includes('sumatif')) pMod = 'sch-pill--sumatif';
    else if (agendaLower.includes('sts')) pMod = 'sch-pill--sts';
    else if (agendaLower.includes('asas')) pMod = 'sch-pill--asas';

    const stLower = (sc.status_pelaksanaan || 'belum terlaksana').toLowerCase();
    let dMod = 'sch-status-dot--belum';
    if (stLower === 'terlaksana') dMod = 'sch-status-dot--terlaksana';
    else if (stLower === 'tidak terlaksana') dMod = 'sch-status-dot--tidak';
    else if (stLower === 'ditunda') dMod = 'sch-status-dot--ditunda';

    const jamDisplay = (sc.jam_mulai || '').substring(0, 5);
    const jamKeBadge = sc.jam_ke ? 'J.' + sc.jam_ke : (sc.alokasi_jp + 'JP');
    const materi = sc.tp_materi_pokok || sc.materi_pembahasan || '';

    const pillHtml = `
        <div style="font-weight: 800; color: #0F172A; display: flex; justify-content: space-between;">
            <span><span class="sch-status-dot ${dMod}"></span>${jamDisplay}</span>
            <span style="font-size: 0.68rem; color: #0D7A87;">${escapeHtml(jamKeBadge)}</span>
        </div>
        <div style="color: #475569; font-size: 0.71rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
            <strong>${escapeHtml(sc.nama_kelas || 'Kelas')}</strong> · ${escapeHtml(sc.kode_mapel || sc.nama_mata_pelajaran)}
        </div>
        ${materi ? `<div style="color: #1E293B; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 0.7rem;">${escapeHtml(materi.substring(0, 24))}</div>` : ''}
    `;

    // 1. Sel Kalender
    let pill = document.getElementById('pill-sched-' + sc.id);
    if (pill) {
        pill.className = `sch-pill ${pMod} row-flash`;
        pill.innerHTML = pillHtml;
    } else {
        const cell = document.querySelector(`.sch-cell[data-date="${sc.tanggal}"]`);
        if (cell) {
            let pillsList = cell.querySelector('.sch-pills-list');
            if (!pillsList) {
                pillsList = document.createElement('div');
                pillsList.className = 'sch-pills-list';
                cell.appendChild(pillsList);
            }
            const newPill = document.createElement('div');
            newPill.id = 'pill-sched-' + sc.id;
            newPill.className = `sch-pill ${pMod} row-smooth-in`;
            newPill.innerHTML = pillHtml;
            pillsList.appendChild(newPill);

            const topEl = cell.querySelector('.sch-cell-top');
            if (topEl) {
                let countBadge = topEl.querySelector('span:nth-child(2)');
                const currentCount = pillsList.querySelectorAll('.sch-pill').length;
                if (!countBadge) {
                    countBadge = document.createElement('span');
                    countBadge.style.cssText = 'font-size: 0.7rem; font-weight: 800; color: #0D7A87; background: rgba(13,122,135,0.1); padding: 2px 6px; border-radius: 6px;';
                    topEl.appendChild(countBadge);
                }
                countBadge.innerText = currentCount + ' KBM';
            }

            const tabCounter = document.getElementById('tabCounterCalendar');
            if (tabCounter) {
                const current = parseInt(tabCounter.innerText || '0', 10);
                tabCounter.innerText = (current + 1) + ' Sesi';
            }
        }
    }

    // 2. Tab 2 Matriks
    let stClass = 'badge-sky';
    if (sc.status_pelaksanaan === 'Terlaksana') stClass = 'badge-emerald';
    else if (sc.status_pelaksanaan === 'Tidak Terlaksana') stClass = 'badge-rose';
    else if (sc.status_pelaksanaan === 'Ditunda') stClass = 'badge-gold';

    const rowInner = `
        <td style="text-align: center; font-weight: 700;">•</td>
        <td>
            <div style="font-weight: 700; color: #0F172A;">${sc.tanggal} (${escapeHtml(sc.hari)})</div>
            <div style="font-size: 0.78rem; color: #0D7A87;">Jam Ke: ${escapeHtml(sc.jam_ke || '1-2')} (${(sc.jam_mulai||'').substring(0,5)} - ${(sc.jam_selesai||'').substring(0,5)})</div>
        </td>
        <td>
            <span class="badge badge-navy">${escapeHtml(sc.nama_kelas || '-')}</span>
            <div style="font-size: 0.75rem; color: #64748B; margin-top: 2px;">${escapeHtml(sc.ruangan || 'Ruang Kelas')}</div>
        </td>
        <td>
            <strong>${escapeHtml(sc.nama_mata_pelajaran)}</strong>
            <div style="font-size: 0.75rem; color: #64748B;">[${escapeHtml(sc.kode_mapel || '-')}]</div>
        </td>
        <td>
            <span class="badge badge-teal">${escapeHtml(sc.nama_elemen || 'Kurikulum Merdeka')}</span>
            <div style="font-size: 0.78rem; color: #334155; margin-top: 3px; max-width: 220px;">
                ${escapeHtml((sc.capaian_pembelajaran || '-').substring(0, 70))}...
            </div>
        </td>
        <td>
            <strong style="color: #0D7A87;">${escapeHtml(sc.kode_tp || 'TP')}:</strong>
            <div style="font-size: 0.8rem; color: #1E293B; max-width: 240px;">
                ${escapeHtml(sc.uraian_tp || sc.materi_pembahasan || '-')}
            </div>
        </td>
        <td><span class="badge badge-gold">${escapeHtml(sc.jenis_agenda || 'Materi KBM')}</span></td>
        <td><span class="badge ${stClass}" id="table-status-${sc.id}">${escapeHtml(sc.status_pelaksanaan || 'Belum Terlaksana')}</span></td>
        <td style="text-align: right;">
            <button type="button" class="btn btn-secondary btn-sm" onclick="openDayDetailModal('${sc.tanggal}')" title="Buka Detail">
                <iconify-icon icon="solar:eye-bold"></iconify-icon>
            </button>
        </td>
    `;

    let row = document.getElementById('row-sched-' + sc.id);
    if (row) {
        row.innerHTML = rowInner;
        row.classList.remove('row-flash');
        void row.offsetWidth;
        row.classList.add('row-flash');
    } else {
        const tbody = document.getElementById('matriksTableBody');
        if (tbody) {
            const emptyTr = tbody.querySelector('tr td[colspan]');
            if (emptyTr) emptyTr.closest('tr').remove();

            const newTr = document.createElement('tr');
            newTr.id = 'row-sched-' + sc.id;
            newTr.className = 'row-smooth-in';
            newTr.innerHTML = rowInner;
            tbody.prepend(newTr);
        }
    }
}

// Simpan Jadwal Manual via AJAX (In-Place Live Injection)
function handleSaveSchedule(e) {
    e.preventDefault();
    const form = document.getElementById('scheduleForm');
    const formData = new FormData(form);
    formData.append('ajax_action', 'save_schedule');

    const btn = document.getElementById('btnSubmitForm');
    btn.disabled = true;

    fetch('<?= BASE_URL ?>/dashboard/guru/schedules', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(res => {
        btn.disabled = false;
        if (!res.success) {
            window.Ilmuna.error('Gagal', res.message || 'Gagal menyimpan jadwal');
            return;
        }

        window.Ilmuna.success('Berhasil', res.message);
        closeIlmunaModal('scheduleFormModal');

        // Mutasi DOM in-place tanpa reload / tanpa render ulang seluruh halaman
        if (res.data) {
            mutateScheduleInPlace(res.data);
        }

        // Jika modal rincian hari sedang dibuka untuk tanggal ini, buka ulang tanggal tersebut
        if (activeDateSelected && activeDateSelected === formData.get('tanggal')) {
            openDayDetailModal(formData.get('tanggal'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        window.Ilmuna.error('Error', err.message);
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

// Inisialisasi awal saat halaman siap (Zero Reload)
document.addEventListener('DOMContentLoaded', function () {
    loadCalendarDataAsync();
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
