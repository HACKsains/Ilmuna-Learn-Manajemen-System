<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PAPAN JADWAL PELAJARAN MINGGUAN ADMIN (DRAG & DROP + LAZY LOAD)
 * ==============================================================================
 * Sistem Jadwal Mingguan Interaktif ala Kaldik:
 *   1. Lazy Load penuh via JSON API (Zero Page Reload)
 *   2. Drag & Drop kartu jadwal antar Hari (Senin-Sabtu) & Jam Ke-1..10
 *   3. Tarik langsung chip Mapel/Kelas/Guru ke sel hari & jam ke-
 *   4. Deteksi bentrok Guru / Kelas / Ruangan secara realtime
 *   5. Otomatis sinkron ke Kaldik (skip hari libur), Alokasi Hari Mengajar
 *      (PROTA/PROSEM), Jadwal Mengajar Guru, Modul Ajar & Presensi KBM
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';
require_once BASE_PATH . '/includes/academic-sync-engine.php';

requireRole(['admin', 'guru']);

if (hasRole('guru') && !hasRole('admin')) {
    header('Location: ' . BASE_URL . '/dashboard/guru/schedules');
    exit;
}

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
ilmuna_ensure_academic_sync_schema($pdo);

$activeTerm = ilmuna_acad_active_term($pdo);
$defaultTahun = $activeTerm['tahun'];
$defaultSem   = $activeTerm['semester'];

$fetchWeeklySlots = function ($pdo, $tahun, $semester) {
    $stmt = $pdo->prepare("
        SELECT jm.*,
               kmp.id_kelas, kmp.id_mata_pelajaran,
               k.nama_kelas,
               mp.nama_mata_pelajaran, mp.kode_mapel,
               COALESCE(g.id, g2.id, 0) AS resolved_guru_id,
               COALESCE(g.nama_guru, g2.nama_guru, 'Belum Ditentukan') AS nama_guru
        FROM jadwal_mingguan jm
        JOIN kelas_mata_pelajaran kmp ON jm.id_kelas_mata_pelajaran = kmp.id
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru g ON jm.id_guru = g.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g2 ON gmp.id_guru = g2.id
        WHERE jm.tahun_pelajaran = ? AND jm.semester = ?
        ORDER BY FIELD(jm.hari,'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'), jm.jam_ke_mulai ASC, k.nama_kelas ASC
    ");
    $stmt->execute([$tahun, $semester]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['id_kelas_mata_pelajaran'] = (int)$r['id_kelas_mata_pelajaran'];
        $r['id_kelas'] = (int)$r['id_kelas'];
        $r['id_mata_pelajaran'] = (int)$r['id_mata_pelajaran'];
        $r['id_guru'] = (int)$r['resolved_guru_id'];
        $r['jam_ke_mulai'] = (int)$r['jam_ke_mulai'];
        $r['durasi_jp'] = max(1, (int)$r['durasi_jp']);
    }
    unset($r);
    return $rows;
};

/* ==============================================================================
 * AJAX JSON API (?api=1)
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] == '1') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $act = trim((string)($_POST['api_action'] ?? $_GET['api_action'] ?? ''));

    try {
        if ($act === 'bootstrap') {
            $tahun = trim((string)($_POST['tahun_pelajaran'] ?? $defaultTahun)) ?: $defaultTahun;
            $semester = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : $defaultSem;

            $bellSlots = ilmuna_get_bell_slots($pdo);
            $kelasList = $pdo->query("SELECT id, nama_kelas, kode_kelas FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
            $guruList  = $pdo->query("SELECT id, nama_guru, nip FROM guru ORDER BY nama_guru ASC")->fetchAll(PDO::FETCH_ASSOC);
            $kmpList   = $pdo->query("
                SELECT kmp.id AS id_kmp, kmp.id_kelas, kmp.id_mata_pelajaran,
                       k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel,
                       COALESCE(g.id, 0) AS id_guru,
                       COALESCE(g.nama_guru, 'Belum Ditentukan') AS nama_guru
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($kmpList as &$km) {
                $km['id_kmp'] = (int)$km['id_kmp'];
                $km['id_kelas'] = (int)$km['id_kelas'];
                $km['id_mata_pelajaran'] = (int)$km['id_mata_pelajaran'];
                $km['id_guru'] = (int)$km['id_guru'];
            }
            unset($km);

            $slots = $fetchWeeklySlots($pdo, $tahun, $semester);

            echo json_encode([
                'success' => true,
                'data' => [
                    'bell_slots' => $bellSlots,
                    'kelas_list' => $kelasList,
                    'guru_list'  => $guruList,
                    'kmp_list'   => $kmpList,
                    'slots'      => $slots,
                    'tahun'      => $tahun,
                    'semester'   => $semester,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($act === 'list_slots') {
            $tahun = trim((string)($_POST['tahun_pelajaran'] ?? $defaultTahun)) ?: $defaultTahun;
            $semester = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : $defaultSem;
            echo json_encode([
                'success' => true,
                'data' => ['slots' => $fetchWeeklySlots($pdo, $tahun, $semester)],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($act === 'save_weekly_slot') {
            $id      = (int)($_POST['id'] ?? 0);
            $tahun   = trim((string)($_POST['tahun_pelajaran'] ?? $defaultTahun)) ?: $defaultTahun;
            $semester= in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : $defaultSem;
            $idKmp   = (int)($_POST['id_kelas_mata_pelajaran'] ?? 0);
            $hari    = in_array($_POST['hari'] ?? '', ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'], true) ? $_POST['hari'] : 'Senin';
            $jamKe   = max(1, min(15, (int)($_POST['jam_ke_mulai'] ?? 1)));
            $durasi  = max(1, min(8, (int)($_POST['durasi_jp'] ?? 2)));
            $ruangan = trim((string)($_POST['ruangan'] ?? 'Ruang Kelas')) ?: 'Ruang Kelas';
            $warna   = in_array($_POST['warna_kartu'] ?? '', ['teal','navy','gold','purple','emerald','rose'], true) ? $_POST['warna_kartu'] : 'teal';
            $catatan = trim((string)($_POST['catatan'] ?? ''));

            if ($idKmp <= 0) {
                echo json_encode(['success' => false, 'message' => 'Pilih Kelas & Mata Pelajaran terlebih dahulu.']);
                exit;
            }

            $kStmt = $pdo->prepare("
                SELECT kmp.id, kmp.id_kelas, COALESCE(gmp.id_guru, 0) AS id_guru, k.nama_kelas, mp.nama_mata_pelajaran
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                WHERE kmp.id = ? LIMIT 1
            ");
            $kStmt->execute([$idKmp]);
            $kInfo = $kStmt->fetch(PDO::FETCH_ASSOC);
            if (!$kInfo) {
                echo json_encode(['success' => false, 'message' => 'Relasi Kelas & Mata Pelajaran tidak ditemukan.']);
                exit;
            }

            $conflict = ilmuna_check_weekly_conflict($pdo, $tahun, $semester, $hari, $jamKe, $durasi, (int)$kInfo['id_kelas'], (int)$kInfo['id_guru'], $ruangan, $id);
            if ($conflict !== null) {
                echo json_encode(['success' => false, 'message' => $conflict]);
                exit;
            }

            $bellSlots = ilmuna_get_bell_slots($pdo);
            $calc = ilmuna_calc_slot_times($bellSlots, $jamKe, $durasi);
            $guruId = (int)$kInfo['id_guru'] ?: null;
            $oldKmpId = 0;

            if ($id > 0) {
                $oldStmt = $pdo->prepare("SELECT id_kelas_mata_pelajaran FROM jadwal_mingguan WHERE id = ? LIMIT 1");
                $oldStmt->execute([$id]);
                $oldKmpId = (int)$oldStmt->fetchColumn();

                $pdo->prepare("
                    UPDATE jadwal_mingguan
                    SET tahun_pelajaran = ?, semester = ?, id_kelas_mata_pelajaran = ?, id_guru = ?,
                        hari = ?, jam_ke_mulai = ?, durasi_jp = ?, jam_mulai = ?, jam_selesai = ?,
                        ruangan = ?, warna_kartu = ?, catatan = ?
                    WHERE id = ?
                ")->execute([
                    $tahun, $semester, $idKmp, $guruId,
                    $hari, $calc['jam_ke_mulai'], $durasi, $calc['jam_mulai'], $calc['jam_selesai'],
                    $ruangan, $warna, $catatan, $id
                ]);
                $savedId = $id;
            } else {
                $pdo->prepare("
                    INSERT INTO jadwal_mingguan
                        (tahun_pelajaran, semester, id_kelas_mata_pelajaran, id_guru, hari, jam_ke_mulai, durasi_jp, jam_mulai, jam_selesai, ruangan, warna_kartu, catatan)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $tahun, $semester, $idKmp, $guruId,
                    $hari, $calc['jam_ke_mulai'], $durasi, $calc['jam_mulai'], $calc['jam_selesai'],
                    $ruangan, $warna, $catatan
                ]);
                $savedId = (int)$pdo->lastInsertId();
            }

            // Otomatis sinkronkan KMP ke Alokasi Hari Mengajar (PROTA/PROSEM) & Jadwal Guru (skip libur Kaldik)!
            $syncRes = ilmuna_sync_kmp_from_weekly($pdo, $idKmp, $tahun, $semester);
            if ($oldKmpId > 0 && $oldKmpId !== $idKmp) {
                ilmuna_sync_kmp_from_weekly($pdo, $oldKmpId, $tahun, $semester);
            }

            echo json_encode([
                'success' => true,
                'message' => "{$kInfo['nama_mata_pelajaran']} ({$kInfo['nama_kelas']}) di {$hari} Jam ke-{$calc['jam_ke_label']} tersimpan & otomatis tersinkron ({$syncRes['synced']} pertemuan efektif Kaldik).",
                'data'    => ['id' => $savedId, 'sync' => $syncRes],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($act === 'move_weekly_slot') {
            $id    = (int)($_POST['id'] ?? 0);
            $hari  = in_array($_POST['hari'] ?? '', ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'], true) ? $_POST['hari'] : '';
            $jamKe = max(1, min(15, (int)($_POST['jam_ke_mulai'] ?? 1)));

            if ($id <= 0 || $hari === '') {
                echo json_encode(['success' => false, 'message' => 'Parameter pemindahan jadwal tidak valid.']);
                exit;
            }

            $curStmt = $pdo->prepare("
                SELECT jm.*, kmp.id_kelas, COALESCE(jm.id_guru, gmp.id_guru, 0) AS resolved_guru_id,
                       k.nama_kelas, mp.nama_mata_pelajaran
                FROM jadwal_mingguan jm
                JOIN kelas_mata_pelajaran kmp ON jm.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                WHERE jm.id = ? LIMIT 1
            ");
            $curStmt->execute([$id]);
            $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
            if (!$cur) {
                echo json_encode(['success' => false, 'message' => 'Slot jadwal tidak ditemukan.']);
                exit;
            }

            $durasi = max(1, (int)$cur['durasi_jp']);
            $conflict = ilmuna_check_weekly_conflict(
                $pdo, $cur['tahun_pelajaran'], $cur['semester'], $hari, $jamKe, $durasi,
                (int)$cur['id_kelas'], (int)$cur['resolved_guru_id'], (string)$cur['ruangan'], $id
            );
            if ($conflict !== null) {
                echo json_encode(['success' => false, 'message' => $conflict]);
                exit;
            }

            $bellSlots = ilmuna_get_bell_slots($pdo);
            $calc = ilmuna_calc_slot_times($bellSlots, $jamKe, $durasi);

            $pdo->prepare("
                UPDATE jadwal_mingguan
                SET hari = ?, jam_ke_mulai = ?, jam_mulai = ?, jam_selesai = ?
                WHERE id = ?
            ")->execute([$hari, $calc['jam_ke_mulai'], $calc['jam_mulai'], $calc['jam_selesai'], $id]);

            $syncRes = ilmuna_sync_kmp_from_weekly($pdo, (int)$cur['id_kelas_mata_pelajaran'], $cur['tahun_pelajaran'], $cur['semester']);

            echo json_encode([
                'success' => true,
                'message' => "{$cur['nama_mata_pelajaran']} ({$cur['nama_kelas']}) digeser ke {$hari} Jam ke-{$calc['jam_ke_label']} & otomatis disinkronkan ({$syncRes['synced']} sesi guru).",
                'data'    => ['id' => $id, 'sync' => $syncRes],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($act === 'delete_weekly_slot') {
            $id = (int)($_POST['id'] ?? 0);
            $cur = $pdo->prepare("SELECT id_kelas_mata_pelajaran, tahun_pelajaran, semester FROM jadwal_mingguan WHERE id = ? LIMIT 1");
            $cur->execute([$id]);
            $row = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'Slot jadwal tidak ditemukan.']);
                exit;
            }
            $pdo->prepare("DELETE FROM jadwal_mingguan WHERE id = ?")->execute([$id]);
            ilmuna_sync_kmp_from_weekly($pdo, (int)$row['id_kelas_mata_pelajaran'], $row['tahun_pelajaran'], $row['semester']);

            echo json_encode(['success' => true, 'message' => 'Slot jadwal mingguan dihapus & jadwal guru telah disesuaikan.']);
            exit;
        }

        if ($act === 'save_bell_slots') {
            $rawSlots = json_decode((string)($_POST['slots'] ?? '[]'), true);
            $tahun = trim((string)($_POST['tahun_pelajaran'] ?? $defaultTahun)) ?: $defaultTahun;
            $semester = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : $defaultSem;

            if (!is_array($rawSlots) || empty($rawSlots)) {
                echo json_encode(['success' => false, 'message' => 'Data jam pelajaran kosong.']);
                exit;
            }
            $upd = $pdo->prepare("UPDATE jam_pelajaran_slot SET label = ?, jam_ke = ?, jam_mulai = ?, jam_selesai = ?, urutan = ? WHERE id = ?");
            foreach ($rawSlots as $rs) {
                $jm = preg_match('/^\d{2}:\d{2}/', (string)$rs['jam_mulai']) ? (substr($rs['jam_mulai'], 0, 5) . ':00') : '07:00:00';
                $js = preg_match('/^\d{2}:\d{2}/', (string)$rs['jam_selesai']) ? (substr($rs['jam_selesai'], 0, 5) . ':00') : '07:45:00';
                $upd->execute([trim((string)$rs['label']), (int)$rs['jam_ke'], $jm, $js, (int)$rs['urutan'], (int)$rs['id']]);
            }

            // Hitung ulang jam_mulai & jam_selesai seluruh jadwal_mingguan pada semester ini lalu sinkronkan
            $bellSlots = ilmuna_get_bell_slots($pdo);
            $allJm = $pdo->prepare("SELECT id, jam_ke_mulai, durasi_jp FROM jadwal_mingguan WHERE tahun_pelajaran = ? AND semester = ?");
            $allJm->execute([$tahun, $semester]);
            $uJm = $pdo->prepare("UPDATE jadwal_mingguan SET jam_mulai = ?, jam_selesai = ? WHERE id = ?");
            foreach ($allJm->fetchAll(PDO::FETCH_ASSOC) as $jmRow) {
                $c = ilmuna_calc_slot_times($bellSlots, (int)$jmRow['jam_ke_mulai'], (int)$jmRow['durasi_jp']);
                $uJm->execute([$c['jam_mulai'], $c['jam_selesai'], (int)$jmRow['id']]);
            }
            ilmuna_sync_all_weekly_schedules($pdo, $tahun, $semester);

            echo json_encode(['success' => true, 'message' => 'Pengaturan jam bel sekolah diperbarui & seluruh jadwal guru telah disesuaikan.']);
            exit;
        }

        if ($act === 'get_sync_status') {
            $tahun = trim((string)($_POST['tahun_pelajaran'] ?? $defaultTahun)) ?: $defaultTahun;
            $semester = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : $defaultSem;

            $kmpRows = $pdo->query("
                SELECT kmp.id AS id_kmp, k.nama_kelas, mp.nama_mata_pelajaran,
                       COALESCE(g.nama_guru, 'Belum Ditentukan') AS nama_guru
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $jmStmt = $pdo->prepare("SELECT * FROM jadwal_mingguan WHERE tahun_pelajaran = ? AND semester = ? ORDER BY FIELD(hari,'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'), jam_ke_mulai ASC");
            $jmStmt->execute([$tahun, $semester]);
            $jmByKmp = [];
            foreach ($jmStmt->fetchAll(PDO::FETCH_ASSOC) as $j) {
                $jmByKmp[(int)$j['id_kelas_mata_pelajaran']][] = $j;
            }

            $jpCnt = [];
            $qJp = $pdo->prepare("SELECT id_kelas_mata_pelajaran, COUNT(*) FROM jadwal_pelajaran WHERE tahun_pelajaran = ? AND semester = ? GROUP BY id_kelas_mata_pelajaran");
            $qJp->execute([$tahun, $semester]);
            foreach ($qJp->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $jpCnt[(int)$k] = (int)$v;

            $ahmJtm = [];
            $qAhm = $pdo->prepare("SELECT id_kelas_mata_pelajaran, total_jtm FROM alokasi_hari_mengajar WHERE semester = ?");
            $qAhm->execute([$semester]);
            foreach ($qAhm->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $ahmJtm[(int)$k] = (int)$v;

            $psMap = [];
            $qPs = $pdo->prepare("SELECT id_kelas_mata_pelajaran, COUNT(*) FROM prosem WHERE tahun_pelajaran = ? AND semester = ? GROUP BY id_kelas_mata_pelajaran");
            $qPs->execute([$tahun, $semester]);
            foreach ($qPs->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) $psMap[(int)$k] = (int)$v > 0;

            $maMap = [];
            foreach ($pdo->query("SELECT id_kelas_mata_pelajaran, COUNT(*) FROM modul_ajar GROUP BY id_kelas_mata_pelajaran")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                $maMap[(int)$k] = (int)$v;
            }

            $out = [];
            foreach ($kmpRows as $r) {
                $kid = (int)$r['id_kmp'];
                $out[] = [
                    'id_kmp'              => $kid,
                    'nama_kelas'          => $r['nama_kelas'],
                    'nama_mata_pelajaran' => $r['nama_mata_pelajaran'],
                    'nama_guru'           => $r['nama_guru'],
                    'weekly_slots'        => $jmByKmp[$kid] ?? [],
                    'jadwal_count'        => $jpCnt[$kid] ?? 0,
                    'ahm_total_jtm'       => $ahmJtm[$kid] ?? 0,
                    'has_prosem'          => !empty($psMap[$kid]),
                    'modul_count'         => $maMap[$kid] ?? 0,
                ];
            }

            echo json_encode(['success' => true, 'data' => ['rows' => $out]], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($act === 'sync_single_kmp') {
            $idKmp = (int)($_POST['id_kmp'] ?? 0);
            $tahun = trim((string)($_POST['tahun_pelajaran'] ?? $defaultTahun)) ?: $defaultTahun;
            $semester = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : $defaultSem;
            $res = ilmuna_sync_kmp_from_weekly($pdo, $idKmp, $tahun, $semester);
            echo json_encode([
                'success' => true,
                'message' => "Berhasil menyinkronkan {$res['synced']} pertemuan efektif Kaldik ({$res['total_jtm']} JTM) ke Jadwal Guru, PROTA/PROSEM & Modul Ajar.",
                'data'    => $res,
            ]);
            exit;
        }

        if ($act === 'sync_all_weekly') {
            $tahun = trim((string)($_POST['tahun_pelajaran'] ?? $defaultTahun)) ?: $defaultTahun;
            $semester = in_array($_POST['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $_POST['semester'] : $defaultSem;
            $res = ilmuna_sync_all_weekly_schedules($pdo, $tahun, $semester);
            echo json_encode([
                'success' => true,
                'message' => "Berhasil menyinkronkan {$res['kmp_count']} rombel mapel ({$res['total_sessions']} pertemuan efektif Kaldik) ke seluruh Guru!",
                'data'    => $res,
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi API tidak dikenali.']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Tahun pelajaran tersedia
$tahunList = [$defaultTahun, '2025/2026', '2026/2027', '2027/2028'];
$tahunList = array_values(array_unique($tahunList));
rsort($tahunList);

$pageTitle = 'Jadwal Pelajaran Mingguan (Drag & Drop)';
$pageIcon  = 'solar:calendar-bold';

$cssVer = file_exists(BASE_PATH . '/assets/css/admin-schedules.css') ? filemtime(BASE_PATH . '/assets/css/admin-schedules.css') : '1';
$jsVer  = file_exists(BASE_PATH . '/assets/js/admin-schedules.js') ? filemtime(BASE_PATH . '/assets/js/admin-schedules.js') : '1';
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-schedules.css?v=' . $cssVer . '">';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'table']);
?>

<div class="asch-app">
    <!-- SEGMENTED TABS (DATA-FIRST ZERO TITLE — STANDAR curriculum.php) -->
    <div class="curriculum-main-tabs" id="aschMainTabs" role="tablist">
        <button type="button" class="curriculum-tab-btn is-active" data-tab="board" role="tab" aria-selected="true">
            <span class="curriculum-tab-step">1</span>
            <iconify-icon icon="solar:calendar-date-bold"></iconify-icon>
            <span class="curriculum-tab-label">Papan Jadwal Mingguan (Geser &amp; Lepas)</span>
            <span class="curriculum-tab-counter" id="cntTabBoard">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="bell" role="tab" aria-selected="false">
            <span class="curriculum-tab-step">2</span>
            <iconify-icon icon="solar:clock-circle-bold"></iconify-icon>
            <span class="curriculum-tab-label">Jam Pelajaran &amp; Bel Sekolah</span>
            <span class="curriculum-tab-counter" id="cntTabBell">10</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="sync" role="tab" aria-selected="false">
            <span class="curriculum-tab-step">3</span>
            <iconify-icon icon="solar:refresh-circle-bold"></iconify-icon>
            <span class="curriculum-tab-label">Sinkron Kaldik, PROTA/PROSEM &amp; Modul</span>
        </button>
    </div>

    <!-- FILTER & ACTION TOOLBAR (LAZY LOAD TANPA RENDER ULANG) -->
    <div class="asch-toolbar">
        <div class="asch-toolbar-row">
            <div class="asch-filter-group">
                <select id="aschTahunSelect" class="asch-select" onchange="ASCH.onTermChange()">
                    <?php foreach ($tahunList as $th): ?>
                        <option value="<?= htmlspecialchars($th) ?>" <?= $th === $defaultTahun ? 'selected' : '' ?>>TP <?= htmlspecialchars($th) ?></option>
                    <?php endforeach; ?>
                </select>
                <select id="aschSemesterSelect" class="asch-select" onchange="ASCH.onTermChange()">
                    <option value="Ganjil" <?= $defaultSem === 'Ganjil' ? 'selected' : '' ?>>Semester Ganjil</option>
                    <option value="Genap" <?= $defaultSem === 'Genap' ? 'selected' : '' ?>>Semester Genap</option>
                </select>

                <div class="asch-mode-pills">
                    <button type="button" class="asch-mode-btn is-active" data-mode="kelas" onclick="ASCH.setViewMode('kelas')">
                        <iconify-icon icon="solar:buildings-2-bold"></iconify-icon> Per Kelas
                    </button>
                    <button type="button" class="asch-mode-btn" data-mode="guru" onclick="ASCH.setViewMode('guru')">
                        <iconify-icon icon="solar:user-speak-bold"></iconify-icon> Per Guru
                    </button>
                    <button type="button" class="asch-mode-btn" data-mode="all" onclick="ASCH.setViewMode('all')">
                        <iconify-icon icon="solar:widget-5-bold"></iconify-icon> Semua
                    </button>
                </div>
            </div>

            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span class="asch-stat-pill asch-stat-pill--teal"><iconify-icon icon="solar:calendar-mark-bold"></iconify-icon> <strong id="aschStatSlots">0</strong> Blok</span>
                <span class="asch-stat-pill asch-stat-pill--navy"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> <strong id="aschStatJp">0</strong> JP/Pekan</span>
                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--secondary" id="btnSyncAllWeekly" onclick="ASCH.syncAllToKaldikAndGuru()">
                    <iconify-icon icon="solar:refresh-circle-bold"></iconify-icon>
                    <span>Sinkronkan ke Kaldik &amp; Guru</span>
                </button>
                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" onclick="ASCH.openSlotModal()">
                    <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                    <span>Tambah Slot</span>
                </button>
            </div>
        </div>

        <div class="asch-toolbar-row">
            <div class="asch-filter-group">
                <select id="aschKelasSelect" class="asch-select" style="min-width:220px;" onchange="ASCH.onKelasFilterChange()">
                    <option value="0">-- Semua Rombel Kelas --</option>
                </select>
                <select id="aschGuruSelect" class="asch-select" style="min-width:240px;display:none;" onchange="ASCH.onGuruFilterChange()">
                    <option value="0">-- Semua Guru Pengampu --</option>
                </select>
                <input type="text" class="asch-input" style="flex:1;min-width:200px;" placeholder="Cari mata pelajaran, guru, kelas, atau ruangan..." oninput="ASCH.onSearchInput(this.value)">
            </div>
        </div>
    </div>

    <!-- TAB 1: PAPAN JADWAL MINGGUAN (DRAG & DROP) -->
    <section class="asch-pane is-active" id="aschPane_board">
        <!-- PALETTE CEPAT: TARIK MAPEL-KELAS-GURU KE GRID -->
        <div class="asch-palette-bar">
            <div class="asch-palette-head">
                <span class="asch-palette-title">
                    <iconify-icon icon="solar:layers-minimalistic-bold" style="color:#0D7A87;font-size:16px;"></iconify-icon>
                    Daftar Mapel &amp; Guru Pengampu (Tarik ke Kotak Hari &amp; Jam Ke- atau Klik)
                </span>
                <span class="asch-palette-hint">
                    <iconify-icon icon="solar:hand-stars-bold"></iconify-icon> Kartu di papan juga bisa langsung digeser-geser &amp; otomatis mengecek bentrok!
                </span>
            </div>
            <div class="asch-palette-chips" id="aschPaletteChips">
                <span style="font-size:12px;color:#64748B;">Memuat daftar mapel &amp; guru...</span>
            </div>
        </div>

        <!-- WEEKLY MATRIX GRID -->
        <div class="asch-grid-card" id="aschGridCard">
            <div class="asch-grid-scroll">
                <table class="asch-table">
                    <thead>
                        <tr>
                            <th>Jam Ke-</th>
                            <th>Senin</th>
                            <th>Selasa</th>
                            <th>Rabu</th>
                            <th>Kamis</th>
                            <th>Jumat</th>
                            <th>Sabtu</th>
                        </tr>
                    </thead>
                    <tbody id="aschGridBody">
                        <tr><td colspan="7" style="text-align:center;padding:42px;color:#64748B;">Memuat papan jadwal mingguan...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- TAB 2: PENGATURAN JAM PELAJARAN / BEL SEKOLAH -->
    <section class="asch-pane" id="aschPane_bell">
        <div class="asch-panel-card">
            <div class="asch-panel-head">
                <h3 class="asch-panel-title">
                    <iconify-icon icon="solar:clock-circle-bold" style="color:#0D7A87;font-size:18px;"></iconify-icon>
                    Konfigurasi Jam Pelajaran &amp; Istirahat (Bel Sekolah)
                </h3>
                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" onclick="ASCH.saveBellSlots()">
                    <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                    <span>Simpan &amp; Terapkan ke Semua Jadwal</span>
                </button>
            </div>
            <div class="table-responsive">
                <table class="lms-table">
                    <thead>
                        <tr>
                            <th style="width:55px;text-align:center;">No</th>
                            <th>Label Slot</th>
                            <th style="width:120px;text-align:center;">Jam Ke-</th>
                            <th style="width:160px;">Jam Mulai</th>
                            <th style="width:160px;">Jam Selesai</th>
                        </tr>
                    </thead>
                    <tbody id="aschBellTableBody"></tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- TAB 3: MONITORING SINKRONISASI KALDIK, PROTA/PROSEM & MODUL AJAR -->
    <section class="asch-pane" id="aschPane_sync">
        <div class="asch-panel-card">
            <div class="asch-panel-head">
                <h3 class="asch-panel-title">
                    <iconify-icon icon="solar:refresh-circle-bold" style="color:#0D7A87;font-size:18px;"></iconify-icon>
                    Status Sinkronisasi Jadwal Mingguan &rarr; Kaldik, PROTA/PROSEM, Jadwal Guru &amp; Modul Ajar
                </h3>
                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" onclick="ASCH.syncAllToKaldikAndGuru()">
                    <iconify-icon icon="solar:refresh-bold"></iconify-icon>
                    <span>Sinkronkan Seluruh Rombel</span>
                </button>
            </div>
            <div class="table-responsive">
                <table class="lms-table">
                    <thead>
                        <tr>
                            <th>Kelas &amp; Mata Pelajaran</th>
                            <th>Guru Pengampu</th>
                            <th>Blok Jadwal Mingguan</th>
                            <th style="text-align:center;">Pertemuan Kaldik</th>
                            <th style="text-align:center;">PROTA/PROSEM</th>
                            <th style="text-align:center;">Modul Ajar</th>
                            <th style="text-align:right;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="aschSyncTableBody"></tbody>
                </table>
            </div>
        </div>
    </section>
</div>

<!-- MODAL TAMBAH / EDIT / PINDAH SLOT JADWAL MINGGUAN -->
<div class="ilm-modal-backdrop" id="modalWeeklySlot" aria-hidden="true">
    <div class="ilm-modal-dialog" style="max-width:600px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:calendar-add-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="modalSlotTitle">Tambah Slot Jadwal Mingguan</h3>
                    <p class="ilm-modal-header__subtitle">Otomatis sinkron ke Jadwal Guru, PROTA/PROSEM, Kaldik &amp; Modul Ajar</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalWeeklySlot')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>

        <form onsubmit="ASCH.submitSlotForm(event)">
            <input type="hidden" id="modalSlotId" value="0">
            <input type="hidden" id="modalSlotColor" value="teal">
            <div class="ilm-modal-body" style="max-height:calc(88vh - 160px);overflow-y:auto;">
                <div class="form-group" style="margin-bottom:13px;">
                    <label class="form-label" style="font-weight:700;">Kelas, Mata Pelajaran &amp; Guru Pengampu *</label>
                    <select id="modalSlotKmp" class="form-control" required></select>
                </div>

                <div class="ilm-grid-3" style="margin-bottom:13px;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight:700;">Hari *</label>
                        <select id="modalSlotHari" class="form-control" required>
                            <option value="Senin">Senin</option>
                            <option value="Selasa">Selasa</option>
                            <option value="Rabu">Rabu</option>
                            <option value="Kamis">Kamis</option>
                            <option value="Jumat">Jumat</option>
                            <option value="Sabtu">Sabtu</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight:700;">Mulai Jam Ke- *</label>
                        <select id="modalSlotJamKe" class="form-control" required onchange="ASCH.previewModalTime()"></select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight:700;">Durasi (JP) *</label>
                        <select id="modalSlotDurasi" class="form-control" required onchange="ASCH.previewModalTime()">
                            <option value="1">1 JP</option>
                            <option value="2" selected>2 JP</option>
                            <option value="3">3 JP</option>
                            <option value="4">4 JP</option>
                            <option value="5">5 JP</option>
                            <option value="6">6 JP</option>
                        </select>
                    </div>
                </div>

                <div style="background:#F0FDFA;border:1px solid #99F6E4;border-radius:10px;padding:8px 12px;margin-bottom:13px;font-size:12.5px;color:#0F766E;font-weight:700;">
                    <iconify-icon icon="solar:clock-circle-bold" style="vertical-align:middle;"></iconify-icon>
                    Waktu Otomatis: <span id="modalTimePreview">-</span>
                </div>

                <div class="ilm-grid-2" style="margin-bottom:13px;">
                    <div class="form-group">
                        <label class="form-label" style="font-weight:700;">Ruangan / Tempat</label>
                        <input type="text" id="modalSlotRuangan" class="form-control" value="Ruang Kelas" placeholder="Ruang Kelas / Lab / Masjid">
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="font-weight:700;">Warna Kartu di Papan</label>
                        <div class="asch-color-row">
                            <button type="button" class="asch-color-dot is-active" data-color="teal" style="background:#0D7A87;" onclick="ASCH.selectCardColor('teal')"></button>
                            <button type="button" class="asch-color-dot" data-color="navy" style="background:#1D3B5E;" onclick="ASCH.selectCardColor('navy')"></button>
                            <button type="button" class="asch-color-dot" data-color="gold" style="background:#D97706;" onclick="ASCH.selectCardColor('gold')"></button>
                            <button type="button" class="asch-color-dot" data-color="purple" style="background:#7C3AED;" onclick="ASCH.selectCardColor('purple')"></button>
                            <button type="button" class="asch-color-dot" data-color="emerald" style="background:#059669;" onclick="ASCH.selectCardColor('emerald')"></button>
                            <button type="button" class="asch-color-dot" data-color="rose" style="background:#E11D48;" onclick="ASCH.selectCardColor('rose')"></button>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-weight:700;">Catatan Tambahan (Opsional)</label>
                    <input type="text" id="modalSlotCatatan" class="form-control" placeholder="Misal: Praktikum Lab / Pembiasaan Tadarus">
                </div>
            </div>

            <div class="ilm-modal-footer" style="justify-content:space-between;">
                <button type="button" class="ilm-modal-btn" id="modalSlotDeleteBtn" style="display:none;background:#FEE2E2;color:#B91C1C;border:1px solid #FECACA;" onclick="ASCH.deleteActiveSlot()">
                    <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
                    <span>Hapus</span>
                </button>
                <div style="display:flex;gap:8px;margin-left:auto;">
                    <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalWeeklySlot')">Batal</button>
                    <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary" id="modalSlotSaveBtn">
                        <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                        <span>Simpan &amp; Sinkronkan</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
window.ILMUNA_ADMIN_SCHED_CFG = {
    endpoint: <?= json_encode(BASE_URL . '/dashboard/admin/schedules') ?>,
    defaultTahun: <?= json_encode($defaultTahun) ?>,
    defaultSemester: <?= json_encode($defaultSem) ?>
};
</script>
<script src="<?= BASE_URL ?>/assets/js/admin-schedules.js?v=<?= $jsVer ?>" defer></script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
