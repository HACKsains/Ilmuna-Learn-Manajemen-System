<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PORTAL PRESENSI & ABSENSI SISWA (KBM LIVE QR & GPS)
 * ==============================================================================
 * Desain: Terintegrasi dengan Header, Sidebar & Footer Dashboard Siswa
 * Tema: Lumina Gold & Emerald (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Emerald #10B981)
 * Arsitektur Database:
 * 1. Master Presensi Siswa (`presensi`) — Kartu QR Code dinamis berbasis titik GPS
 * 2. Transaksi Kehadiran Siswa (`absensi`) — Rekap log kehadiran KBM P1 s.d P16
 * 3. Alur Dual-Approval Perizinan (`perizinan`) — Siswa -> Ketua Kelas -> Guru Pengampu -> Auto Sync Absensi
 * 4. Persetujuan Izin Teman Sekelas — Khusus akun dengan peran Ketua Kelas
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireAuth();

$pdo = getDBConnection();
$currentUser = getCurrentUser();
$userRoles = getUserRoles();
$activeRole = getActiveRole();

// Hak Akses: Siswa, Ketua Kelas, atau Pratinjau oleh Guru / Admin
$isSiswa = in_array('siswa', $userRoles, true) || $activeRole === 'siswa';
$isKetuaKelasRole = in_array('ketua_kelas', $userRoles, true) || $activeRole === 'ketua_kelas' || (strpos($currentUser['username'] ?? '', 'ketua_') === 0);
$isAdminOrGuru = in_array('admin', $userRoles, true) || in_array('guru', $userRoles, true);

if (!$isSiswa && !$isKetuaKelasRole && !$isAdminOrGuru) {
    header("Location: " . BASE_URL . "/login?msg=unauthorized");
    exit;
}

// ------------------------------------------------------------------------------
// RESOLUSI DATA SISWA
// ------------------------------------------------------------------------------
$currentSiswa = null;
$overrideSiswaId = (int)($_GET['siswa_id'] ?? 0);

if ($isAdminOrGuru && $overrideSiswaId > 0) {
    $stmtS = $pdo->prepare("SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran FROM siswa s LEFT JOIN kelas k ON s.id_kelas = k.id WHERE s.id = ? LIMIT 1");
    $stmtS->execute([$overrideSiswaId]);
    $currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);
}

if (!$currentSiswa) {
    $stmtS = $pdo->prepare("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
        FROM siswa s 
        LEFT JOIN kelas k ON s.id_kelas = k.id 
        WHERE s.nis = ? OR s.nisn = ? OR s.gmail = ? OR s.gmail = ?
        LIMIT 1
    ");
    $stmtS->execute([
        $currentUser['username'] ?? '',
        $currentUser['nisn_nip'] ?? '',
        $currentUser['email'] ?? '',
        $currentUser['email_google'] ?? ''
    ]);
    $currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);
}

// Fallback cerdas jika login sebagai admin/demo akun yang belum terpaut siswa
if (!$currentSiswa) {
    $currentSiswa = $pdo->query("SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran FROM siswa s LEFT JOIN kelas k ON s.id_kelas = k.id ORDER BY s.id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

if (!$currentSiswa) {
    die("Data master siswa tidak ditemukan di database.");
}

$idSiswa = (int)$currentSiswa['id'];
$idKelas = (int)$currentSiswa['id_kelas'];

// Deteksi apakah siswa ini adalah Ketua Kelas
$isKetuaKelas = $isKetuaKelasRole;
if (!$isKetuaKelas) {
    $checkKetua = $pdo->prepare("SELECT COUNT(*) FROM perizinan WHERE id_ketua_kelas = ?");
    $checkKetua->execute([$currentUser['id']]);
    if ($checkKetua->fetchColumn() > 0) {
        $isKetuaKelas = true;
    }
}

// ------------------------------------------------------------------------------
// MASTER TITIK KOORDINAT SEKOLAH
// ------------------------------------------------------------------------------
$activeGeofences = $pdo->query("SELECT id, nama_lokasi, latitude, longitude, radius_meter, alamat FROM titik_koordinat_sekolah WHERE is_active = 1 LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
if (empty($activeGeofences)) {
    $activeGeofences = $pdo->query("SELECT id, nama_lokasi, latitude, longitude, radius_meter, alamat FROM titik_koordinat_sekolah LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
}
$masterLoc = !empty($activeGeofences) ? $activeGeofences[0] : null;

// ==============================================================================
// HANDLER AJAX BACKEND (LIVE API)
// ==============================================================================
if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    header('Content-Type: application/json; charset=utf-8');

    // 1. AJAX: SUBMIT PENGAJUAN IZIN / SAKIT
    if ($action === 'submit_izin') {
        $idKmp       = (int)($_POST['id_km'] ?? 0);
        $jenisIzin   = trim($_POST['jenis'] ?? '');
        $alasan      = trim($_POST['alasan'] ?? '');
        $lat         = trim($_POST['lat'] ?? '');
        $lng         = trim($_POST['lng'] ?? '');
        $akurasi     = trim($_POST['akurasi'] ?? '');
        $alamatIzin  = trim($_POST['alamat_izin'] ?? 'Lokasi Siswa (GPS)');
        $fotoBukti   = $_POST['foto'] ?? '';

        if (!$idKmp || !in_array($jenisIzin, ['Izin', 'Sakit'], true)) {
            echo json_encode(['success' => false, 'message' => 'Parameter perizinan tidak lengkap atau tidak valid.']);
            exit;
        }

        if (empty($alasan)) {
            echo json_encode(['success' => false, 'message' => 'Alasan perizinan wajib diisi dengan jelas.']);
            exit;
        }

        // Cek permohonan aktif yang belum selesai
        $stmtCheckActive = $pdo->prepare("
            SELECT id FROM perizinan 
            WHERE id_siswa = ? AND status_perizinan NOT IN ('Disetujui', 'Ditolak')
            LIMIT 1
        ");
        $stmtCheckActive->execute([$idSiswa]);
        if ($stmtCheckActive->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Anda masih memiliki permohonan izin aktif yang sedang dalam proses persetujuan.']);
            exit;
        }

        // Cari jadwal pertemuan hari ini atau jadwal terdekat
        $stmtJadwal = $pdo->prepare("
            SELECT id, pertemuan_ke, id_modul_ajar 
            FROM jadwal_pelajaran 
            WHERE id_kelas_mata_pelajaran = ? 
              AND (tanggal = CURRENT_DATE OR status_jadwal IN ('Terjadwal', 'Berlangsung'))
            ORDER BY CASE WHEN tanggal = CURRENT_DATE THEN 0 ELSE 1 END, pertemuan_ke ASC 
            LIMIT 1
        ");
        $stmtJadwal->execute([$idKmp]);
        $jadwalRow = $stmtJadwal->fetch(PDO::FETCH_ASSOC);

        $idJadwal      = $jadwalRow ? (int)$jadwalRow['id'] : null;
        $idModulAjar   = $jadwalRow ? $jadwalRow['id_modul_ajar'] : null;
        $pertemuanKe   = $jadwalRow ? (int)$jadwalRow['pertemuan_ke'] : 1;

        if ($idJadwal) {
            $stmtDup = $pdo->prepare("SELECT id FROM perizinan WHERE id_siswa = ? AND id_jadwal = ? LIMIT 1");
            $stmtDup->execute([$idSiswa, $idJadwal]);
            if ($stmtDup->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Anda sudah pernah mengajukan perizinan untuk pertemuan ini.']);
                exit;
            }
        }

        // Penanganan foto bukti
        $buktiPath = '';
        if (!empty($fotoBukti) && strpos($fotoBukti, 'data:image') === 0) {
            $uploadDir = BASE_PATH . '/uploads/perizinan';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $dataParts = explode(',', $fotoBukti);
            $imgData = base64_decode(end($dataParts));
            $filename = 'izin_' . $idSiswa . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.jpg';
            $filePath = $uploadDir . '/' . $filename;
            if (file_put_contents($filePath, $imgData)) {
                $buktiPath = BASE_URL . '/uploads/perizinan/' . $filename;
            }
        } elseif (isset($_FILES['file_bukti']) && $_FILES['file_bukti']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = BASE_PATH . '/uploads/perizinan';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $ext = strtolower(pathinfo($_FILES['file_bukti']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'])) {
                $filename = 'izin_' . $idSiswa . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['file_bukti']['tmp_name'], $uploadDir . '/' . $filename)) {
                    $buktiPath = BASE_URL . '/uploads/perizinan/' . $filename;
                }
            }
        }

        if (empty($buktiPath)) {
            $buktiPath = 'https://images.unsplash.com/photo-1584515979956-d9f6e5d09982?w=600&auto=format&fit=crop&q=80';
        }

        // Simpan ke tabel perizinan (Menyesuaikan skema tabel @skema-tabel-kelas.sql)
        $stmtInsert = $pdo->prepare("
            INSERT INTO perizinan (
                id_siswa, id_kelas_mata_pelajaran, id_jadwal, id_modul_ajar,
                pertemuan_ke, tanggal_izin, jenis_izin, alasan, bukti_perizinan,
                latitude, longitude, alamat_izin,
                approval_ketua_kelas, approval_guru, status_perizinan,
                is_sync_to_absensi, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?,
                ?, CURRENT_DATE, ?, ?, ?,
                ?, ?, ?,
                'Pending', 'Pending', 'Menunggu Persetujuan Ketua Kelas',
                0, NOW(), NOW()
            )
        ");

        $latVal = (!empty($lat) && is_numeric($lat)) ? (float)$lat : null;
        $lngVal = (!empty($lng) && is_numeric($lng)) ? (float)$lng : null;

        $stmtInsert->execute([
            $idSiswa, $idKmp, $idJadwal, $idModulAjar,
            $pertemuanKe, $jenisIzin, $alasan, $buktiPath,
            $latVal, $lngVal, $alamatIzin
        ]);

        $newIzinId = (int)$pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => 'Permohonan izin berhasil dikirimkan. Menunggu verifikasi dari Ketua Kelas dan Guru Pengampu.',
            'data'    => ['id_izin' => $newIzinId]
        ]);
        exit;
    }

    // 2. AJAX: POLLING STATUS PERIZINAN AKTIF & TIMELINE
    if ($action === 'get_izin_status') {
        $stmtActive = $pdo->prepare("
            SELECT pz.*, mp.nama_mata_pelajaran, k.nama_kelas, jp.pertemuan_ke, jp.tanggal AS tgl_pertemuan,
                   u_ketua.full_name AS nama_ketua, u_guru.full_name AS nama_guru
            FROM perizinan pz
            JOIN kelas_mata_pelajaran kmp ON pz.id_kelas_mata_pelajaran = kmp.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN kelas k ON kmp.id_kelas = k.id
            LEFT JOIN jadwal_pelajaran jp ON pz.id_jadwal = jp.id
            LEFT JOIN users u_ketua ON pz.id_ketua_kelas = u_ketua.id
            LEFT JOIN users u_guru ON pz.id_guru = u_guru.id
            WHERE pz.id_siswa = ? AND pz.status_perizinan NOT IN ('Disetujui', 'Ditolak')
            ORDER BY pz.created_at DESC LIMIT 1
        ");
        $stmtActive->execute([$idSiswa]);
        $activeRow = $stmtActive->fetch(PDO::FETCH_ASSOC);

        $hasActive = !empty($activeRow);
        $steps = [];

        if ($hasActive) {
            $isKetuaDone = ($activeRow['approval_ketua_kelas'] === 'Approved');
            $isKetuaRejected = ($activeRow['approval_ketua_kelas'] === 'Rejected');
            $isGuruDone = ($activeRow['approval_guru'] === 'Approved');
            $isGuruRejected = ($activeRow['approval_guru'] === 'Rejected');

            $ketuaState = $isKetuaDone ? 'done' : ($isKetuaRejected ? 'rejected' : 'active');
            $guruState  = $isGuruDone ? 'done' : ($isGuruRejected ? 'rejected' : ($isKetuaDone ? 'active' : 'pending'));

            $steps = [
                [
                    'key'   => 'ketua',
                    'label' => 'Ketua Kelas',
                    'state' => $ketuaState,
                    'name'  => $activeRow['nama_ketua'] ?: 'Ketua Kelas Rombel',
                    'time'  => !empty($activeRow['waktu_approval_ketua']) ? date('d M Y H:i', strtotime($activeRow['waktu_approval_ketua'])) : '',
                    'note'  => $activeRow['catatan_ketua_kelas'] ?? ''
                ],
                [
                    'key'   => 'guru',
                    'label' => 'Guru Pengampu',
                    'state' => $guruState,
                    'name'  => $activeRow['nama_guru'] ?: 'Guru Mata Pelajaran',
                    'time'  => !empty($activeRow['waktu_approval_guru']) ? date('d M Y H:i', strtotime($activeRow['waktu_approval_guru'])) : '',
                    'note'  => $activeRow['catatan_guru'] ?? ''
                ]
            ];
        }

        // Ambil riwayat izin lampau (maks 10)
        $stmtHistory = $pdo->prepare("
            SELECT pz.*, mp.nama_mata_pelajaran, k.nama_kelas,
                   u_ketua.full_name AS nama_ketua, u_guru.full_name AS nama_guru
            FROM perizinan pz
            JOIN kelas_mata_pelajaran kmp ON pz.id_kelas_mata_pelajaran = kmp.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN kelas k ON kmp.id_kelas = k.id
            LEFT JOIN users u_ketua ON pz.id_ketua_kelas = u_ketua.id
            LEFT JOIN users u_guru ON pz.id_guru = u_guru.id
            WHERE pz.id_siswa = ? AND pz.status_perizinan IN ('Disetujui', 'Ditolak')
            ORDER BY pz.created_at DESC LIMIT 10
        ");
        $stmtHistory->execute([$idSiswa]);
        $historyList = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'    => true,
            'has_active' => $hasActive,
            'active'     => $activeRow ? [
                'id'               => (int)$activeRow['id'],
                'jenis_izin'       => $activeRow['jenis_izin'],
                'nama_mapel'       => $activeRow['nama_mata_pelajaran'],
                'pertemuan_ke'     => (int)($activeRow['pertemuan_ke'] ?: 1),
                'tanggal_izin'     => date('d M Y', strtotime($activeRow['tanggal_izin'])),
                'status_perizinan' => $activeRow['status_perizinan'],
                'alasan'           => $activeRow['alasan'],
                'bukti_perizinan'  => $activeRow['bukti_perizinan'],
                'steps'            => $steps
            ] : null,
            'history'    => $historyList
        ]);
        exit;
    }

    // 3. AJAX: APPROVAL OLEH KETUA KELAS (DUAL-APPROVAL TAHAP 1)
    if ($action === 'approval_ketua_kelas') {
        if (!$isKetuaKelas) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak. Fitur ini hanya untuk Ketua Kelas.']);
            exit;
        }

        $idIzin    = (int)($_POST['id_izin'] ?? 0);
        $keputusan = trim($_POST['keputusan'] ?? ''); // 'approve' atau 'reject'
        $catatan   = trim($_POST['catatan'] ?? '');

        if (!$idIzin || !in_array($keputusan, ['approve', 'reject'], true)) {
            echo json_encode(['success' => false, 'message' => 'Parameter persetujuan tidak valid.']);
            exit;
        }

        $stmtIzin = $pdo->prepare("SELECT * FROM perizinan WHERE id = ? LIMIT 1");
        $stmtIzin->execute([$idIzin]);
        $rowIzin = $stmtIzin->fetch(PDO::FETCH_ASSOC);

        if (!$rowIzin) {
            echo json_encode(['success' => false, 'message' => 'Data perizinan tidak ditemukan.']);
            exit;
        }

        if ($keputusan === 'reject') {
            $stmtUp = $pdo->prepare("
                UPDATE perizinan 
                SET approval_ketua_kelas = 'Rejected', status_perizinan = 'Ditolak',
                    id_ketua_kelas = ?, waktu_approval_ketua = NOW(), catatan_ketua_kelas = ?
                WHERE id = ?
            ");
            $stmtUp->execute([$currentUser['id'], $catatan ?: 'Ditolak oleh Ketua Kelas', $idIzin]);
            echo json_encode(['success' => true, 'message' => 'Permohonan izin telah ditolak oleh Ketua Kelas.']);
            exit;
        }

        // Approve Ketua Kelas -> Lanjut ke Guru Pengampu
        $stmtUp = $pdo->prepare("
            UPDATE perizinan 
            SET approval_ketua_kelas = 'Approved', status_perizinan = 'Menunggu Persetujuan Guru',
                id_ketua_kelas = ?, waktu_approval_ketua = NOW(), catatan_ketua_kelas = ?
                WHERE id = ?
        ");
        $stmtUp->execute([$currentUser['id'], $catatan ?: 'Disetujui oleh Ketua Kelas', $idIzin]);
        echo json_encode(['success' => true, 'message' => 'Permohonan izin disetujui Ketua Kelas dan diteruskan ke Guru Pengampu.']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
    exit;
}

// ------------------------------------------------------------------------------
// DATA MATA PELAJARAN YANG DIIKUTI SISWA (KELAS MATA PELAJARAN)
// ------------------------------------------------------------------------------
$stmtMapel = $pdo->prepare("
    SELECT kmp.id AS id_kmp, kmp.id_kelas, kmp.id_mata_pelajaran,
           mp.nama_mata_pelajaran, mp.kode_mapel, mp.semester, mp.tahun_pelajaran,
           g.nama_guru, g.nip, g.foto AS foto_guru
    FROM kelas_mata_pelajaran kmp
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
    LEFT JOIN guru g ON gmp.id_guru = g.id
    WHERE kmp.id_kelas = ?
    ORDER BY mp.nama_mata_pelajaran ASC
");
$stmtMapel->execute([$idKelas]);
$enrolledMapel = $stmtMapel->fetchAll(PDO::FETCH_ASSOC);

// Siapkan Kartu Presensi QR & Jadwal Aktif per Mapel
$cardList = [];

foreach ($enrolledMapel as $m) {
    $idKmp = (int)$m['id_kmp'];

    // 1. Cek / Buat Token Presensi Siswa di tabel `presensi`
    $stmtP = $pdo->prepare("
        SELECT * FROM presensi 
        WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ? AND status = 'aktif' AND (expired_at IS NULL OR expired_at > NOW())
        ORDER BY id DESC LIMIT 1
    ");
    $stmtP->execute([$idSiswa, $idKmp]);
    $presensiRow = $stmtP->fetch(PDO::FETCH_ASSOC);

    if (!$presensiRow) {
        $newToken = rand(100, 999) . ($currentSiswa['nis'] ?: '26271000') . rand(1000, 9999) . ($currentSiswa['nisn'] ?: '0081234500') . rand(10000, 99999);
        $stmtInsP = $pdo->prepare("
            INSERT INTO presensi (id_presensi, id_siswa, id_kelas_mata_pelajaran, tanggal_created, expired_at, status)
            VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 6 MONTH), 'aktif')
        ");
        $stmtInsP->execute([$newToken, $idSiswa, $idKmp]);
        $newPId = $pdo->lastInsertId();

        $presensiRow = [
            'id'                      => $newPId,
            'id_presensi'             => $newToken,
            'id_siswa'                => $idSiswa,
            'id_kelas_mata_pelajaran' => $idKmp,
            'status'                  => 'aktif'
        ];
    }

    // 2. Cari Jadwal Pelajaran Hari Ini atau Pertemuan Berlangsung
    $stmtJadwal = $pdo->prepare("
        SELECT jp.*
        FROM jadwal_pelajaran jp
        WHERE jp.id_kelas_mata_pelajaran = ?
        ORDER BY CASE WHEN jp.tanggal = CURRENT_DATE THEN 0 ELSE 1 END, jp.pertemuan_ke ASC
        LIMIT 1
    ");
    $stmtJadwal->execute([$idKmp]);
    $jadwalActive = $stmtJadwal->fetch(PDO::FETCH_ASSOC);

    // 3. Cek apakah sudah absen hari ini di tabel `absensi`
    $stmtAbs = $pdo->prepare("
        SELECT a.*, pz.jenis_izin
        FROM absensi a
        LEFT JOIN perizinan pz ON a.id_perizinan = pz.id
        WHERE a.id_siswa = ? AND a.id_kelas_mata_pelajaran = ? 
          AND (DATE(a.waktu_absen) = CURRENT_DATE OR a.pertemuan_ke = ?)
        LIMIT 1
    ");
    $stmtAbs->execute([$idSiswa, $idKmp, $jadwalActive ? $jadwalActive['pertemuan_ke'] : 0]);
    $absensiToday = $stmtAbs->fetch(PDO::FETCH_ASSOC);

    $isAttended = !empty($absensiToday);

    $cardList[] = [
        'mapel'        => $m,
        'presensi'     => $presensiRow,
        'jadwal'       => $jadwalActive,
        'is_attended'  => $isAttended,
        'absen_record' => $absensiToday
    ];
}

// ------------------------------------------------------------------------------
// STATISTIK REKAP KEHADIRAN SISWA
// ------------------------------------------------------------------------------
$stmtRekap = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN status = 'H' THEN 1 ELSE 0 END) AS hadir,
        SUM(CASE WHEN status = 'I' THEN 1 ELSE 0 END) AS izin,
        SUM(CASE WHEN status = 'S' THEN 1 ELSE 0 END) AS sakit,
        SUM(CASE WHEN status = 'A' THEN 1 ELSE 0 END) AS alpa,
        SUM(CASE WHEN status = 'T' THEN 1 ELSE 0 END) AS terlambat,
        COUNT(*) AS total_pertemuan
    FROM absensi 
    WHERE id_siswa = ?
");
$stmtRekap->execute([$idSiswa]);
$rekapData = $stmtRekap->fetch(PDO::FETCH_ASSOC);

$totalHadir = (int)($rekapData['hadir'] ?? 0);
$totalIzin  = (int)($rekapData['izin'] ?? 0);
$totalSakit = (int)($rekapData['sakit'] ?? 0);
$totalAlpa  = (int)($rekapData['alpa'] ?? 0);
$totalTerlambat = (int)($rekapData['terlambat'] ?? 0);
$totalAll   = (int)($rekapData['total_pertemuan'] ?? 0);

$persenKehadiran = ($totalAll > 0) ? round((($totalHadir + $totalTerlambat) / $totalAll) * 100, 1) : 100;

// Matriks Presensi P1 - P16 per Mapel
$matrixData = [];
foreach ($enrolledMapel as $m) {
    $idKmp = (int)$m['id_kmp'];
    $stmtMtx = $pdo->prepare("
        SELECT pertemuan_ke, status, waktu_absen, metode_absen, jarak_meter
        FROM absensi
        WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ?
    ");
    $stmtMtx->execute([$idSiswa, $idKmp]);
    $rawP = $stmtMtx->fetchAll(PDO::FETCH_ASSOC);
    $byP = [];
    foreach ($rawP as $rp) {
        $byP[(int)$rp['pertemuan_ke']] = $rp;
    }
    $matrixData[$idKmp] = [
        'mapel' => $m,
        'grid'  => $byP
    ];
}

// ------------------------------------------------------------------------------
// PERIZINAN AKTIF & DAFTAR APPROVAL KETUA KELAS (JIKA KETUA KELAS)
// ------------------------------------------------------------------------------
$stmtActiveIzin = $pdo->prepare("
    SELECT pz.*, mp.nama_mata_pelajaran, k.nama_kelas, jp.pertemuan_ke,
           u_ketua.full_name AS nama_ketua, u_guru.full_name AS nama_guru
    FROM perizinan pz
    JOIN kelas_mata_pelajaran kmp ON pz.id_kelas_mata_pelajaran = kmp.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    LEFT JOIN kelas k ON kmp.id_kelas = k.id
    LEFT JOIN jadwal_pelajaran jp ON pz.id_jadwal = jp.id
    LEFT JOIN users u_ketua ON pz.id_ketua_kelas = u_ketua.id
    LEFT JOIN users u_guru ON pz.id_guru = u_guru.id
    WHERE pz.id_siswa = ? AND pz.status_perizinan NOT IN ('Disetujui', 'Ditolak')
    ORDER BY pz.created_at DESC LIMIT 1
");
$stmtActiveIzin->execute([$idSiswa]);
$activeIzin = $stmtActiveIzin->fetch(PDO::FETCH_ASSOC);

// Antrean Izin Teman Sekelas untuk Ketua Kelas
$pendingClassmateIzin = [];
if ($isKetuaKelas) {
    $stmtPendingK = $pdo->prepare("
        SELECT pz.*, s.nama_lengkap AS nama_siswa, s.nis, s.foto AS foto_siswa,
               mp.nama_mata_pelajaran, jp.pertemuan_ke, jp.tanggal AS tgl_pertemuan
        FROM perizinan pz
        JOIN siswa s ON pz.id_siswa = s.id
        JOIN kelas_mata_pelajaran kmp ON pz.id_kelas_mata_pelajaran = kmp.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN jadwal_pelajaran jp ON pz.id_jadwal = jp.id
        WHERE s.id_kelas = ? AND pz.approval_ketua_kelas = 'Pending' AND pz.status_perizinan = 'Menunggu Persetujuan Ketua Kelas'
        ORDER BY pz.created_at ASC
    ");
    $stmtPendingK->execute([$idKelas]);
    $pendingClassmateIzin = $stmtPendingK->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Presensi & Absensi KBM';

// MEMUAT HEADER & SIDEBAR STANDAR DASHBOARD SISWA
require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<!-- LIBRARY CLIENT-SIDE QRCODE -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<style>
    /* PROTEKSI DOWNLOAD GAMBAR & DRAGGING */
    img {
        -webkit-user-drag: none !important;
        user-drag: none !important;
        user-select: none !important;
        -webkit-user-select: none !important;
        -webkit-touch-callout: none !important;
    }

    /* KONTEN UTAMA PRESENSI */
    .abs-page-wrap {
        padding: 4px 0 40px;
    }

    /* NAVIGATION TABS */
    .tabs-header {
        display: flex; gap: 8px; margin-bottom: 20px;
        border-bottom: 2px solid #E2E8F0; padding-bottom: 4px;
        overflow-x: auto; scrollbar-width: none;
    }
    .tabs-header::-webkit-scrollbar { display: none; }
    .tab-btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 18px; border: none; background: transparent;
        font-family: inherit; font-size: 13px; font-weight: 700;
        color: #64748B; cursor: pointer; border-radius: 10px 10px 0 0;
        transition: all .2s; position: relative; white-space: nowrap;
    }
    .tab-btn:hover { color: #1D3B5E; background: rgba(255, 255, 255, 0.6); }
    .tab-btn.active {
        color: #0D7A87; background: #fff;
        box-shadow: 0 -2px 10px rgba(0,0,0,0.03);
        border: 1.5px solid #E2E8F0; border-bottom: 2px solid #fff;
        margin-bottom: -6px; padding-bottom: 12px;
    }
    .tab-btn.active::after {
        content: ""; position: absolute; bottom: -2px; left: 0; right: 0;
        height: 3px; background: #0D7A87; border-radius: 3px 3px 0 0;
    }
    .tab-badge {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 18px; height: 18px; padding: 0 5px;
        background: #F43F5E; color: #fff; border-radius: 20px;
        font-size: 10px; font-weight: 800;
    }
    .tab-badge[data-count="0"] { display: none; }

    .tab-panel { animation: fadeIn .22s cubic-bezier(0.16, 1, 0.3, 1); }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(4px); } to { opacity: 1; transform: translateY(0); } }

    /* ================= TAB 1: KARTU PRESENSI QR ================= */
    .qr-grid {
        display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr));
        gap: 16px; margin-bottom: 24px;
    }
    .qr-card {
        background: #fff; border: 1.5px solid #E2E8F0; border-radius: 16px;
        padding: 18px; box-shadow: 0 4px 18px -6px rgba(29, 59, 94, 0.08);
        position: relative; overflow: hidden; display: flex; flex-direction: column;
        transition: transform .2s, box-shadow .2s;
    }
    .qr-card:hover { transform: translateY(-3px); box-shadow: 0 10px 24px -8px rgba(29, 59, 94, 0.12); }
    .qr-card::before {
        content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px;
        background: linear-gradient(90deg, #0D7A87, #52B2DC);
    }
    .qr-card-badge {
        position: absolute; top: 12px; right: 14px;
        font-size: 10px; font-weight: 800; padding: 3px 9px;
        border-radius: 20px; text-transform: uppercase; letter-spacing: 0.3px;
    }
    .qr-card-badge.aktif { background: #D1FAE5; color: #065F46; }
    .qr-card-badge.hadir { background: #E0F2FE; color: #0369A1; }

    .qr-card-head { margin-bottom: 12px; padding-right: 70px; }
    .qr-card-title {
        font-family: 'Outfit', sans-serif; font-size: 14.5px; font-weight: 800;
        color: #1D3B5E; line-height: 1.3; margin-bottom: 3px;
    }
    .qr-card-guru { font-size: 11.5px; color: #64748B; font-weight: 600; display: flex; align-items: center; gap: 5px; }

    .qr-card-schedule {
        background: #F8FAFC; border: 1px dashed #CBD5E1; border-radius: 10px;
        padding: 8px 12px; margin-bottom: 14px; font-size: 11px; color: #475569;
        line-height: 1.45;
    }
    .qr-card-schedule strong { color: #1D3B5E; }

    .gps-slot {
        flex: 1; display: flex; flex-direction: column;
        justify-content: center; align-items: center;
    }

    .gps-box {
        background: #F8FAFC; border: 1.5px dashed #CBD5E1; border-radius: 14px;
        padding: 22px 14px; width: 100%; text-align: center;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 8px; min-height: 240px; position: relative;
    }
    .gps-box.loading { background: #F0F9FF; border-color: #BAE6FD; }
    .gps-box.ok { background: #ECFDF5; border-color: #A7F3D0; }
    .gps-box.err { background: #FFF1F2; border-color: #FECDD3; }

    .gps-icon { font-size: 38px; line-height: 1; color: #0D7A87; }
    .gps-box.loading .gps-icon { color: #52B2DC; animation: spinRadar 1.5s linear infinite; }
    @keyframes spinRadar { to { transform: rotate(360deg); } }
    .gps-box.ok .gps-icon { color: #10B981; }
    .gps-box.err .gps-icon { color: #F43F5E; }

    .gps-title { font-size: 13.5px; font-weight: 800; color: #1D3B5E; }
    .gps-desc { font-size: 11.5px; color: #64748B; line-height: 1.5; max-width: 250px; }

    .btn-capture {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 10px 20px; border-radius: 10px;
        background: linear-gradient(135deg, #0D7A87, #1D3B5E);
        color: #fff; font-family: inherit; font-size: 12.5px; font-weight: 800;
        border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(13, 122, 135, 0.3);
        transition: all .2s; margin-top: 4px;
    }
    .btn-capture:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(13, 122, 135, 0.4); }
    .btn-capture:disabled { opacity: .6; cursor: not-allowed; }

    /* QR DISPLAY WRAP */
    .qr-display-wrap {
        position: relative; width: 100%; display: flex; flex-direction: column; align-items: center;
    }
    .qr-canvas-holder {
        background: #fff; border: 1.5px solid #E2E8F0; border-radius: 12px;
        padding: 12px; margin-bottom: 10px; position: relative; overflow: hidden;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 4px 14px rgba(0,0,0,0.05);
        user-select: none; -webkit-user-select: none;
    }
    .qr-canvas-holder canvas, .qr-canvas-holder img {
        width: 190px !important; height: 190px !important; display: block;
        border-radius: 6px; pointer-events: none !important;
    }
    .qr-shield { position: absolute; inset: 0; z-index: 5; background: transparent; cursor: default; }
    .qr-watermark {
        position: absolute; inset: 0; z-index: 6; display: flex; align-items: center; justify-content: center;
        pointer-events: none; color: rgba(29, 59, 94, 0.12); font-size: 12px; font-weight: 900;
        letter-spacing: 2px; text-transform: uppercase; transform: rotate(-25deg);
        white-space: nowrap;
    }

    .qr-meta-info {
        width: 100%; background: #F8FAFC; border: 1px solid #E2E8F0;
        border-radius: 10px; padding: 9px 12px; font-size: 11px;
        margin-bottom: 10px;
    }
    .qr-meta-row { display: flex; justify-content: space-between; gap: 8px; padding: 2px 0; }
    .qr-meta-row span:first-child { color: #64748B; font-weight: 600; }
    .qr-meta-row span:last-child { font-family: monospace; font-weight: 700; color: #1D3B5E; }

    .acc-pill {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 9px; border-radius: 12px; font-size: 10.5px; font-weight: 800;
        margin-bottom: 8px;
    }
    .acc-pill.good { background: #D1FAE5; color: #065F46; }
    .acc-pill.warn { background: #FEF3C7; color: #B45309; }
    .acc-pill.bad  { background: #FFE4E6; color: #9F1239; }

    .qr-actions-row { display: flex; gap: 8px; width: 100%; }
    .btn-qr-action {
        flex: 1; padding: 7px 10px; border-radius: 8px; font-family: inherit;
        font-size: 11.5px; font-weight: 700; cursor: pointer; transition: all .2s;
        display: inline-flex; align-items: center; justify-content: center; gap: 5px;
        border: 1px solid transparent;
    }
    .btn-qr-refresh { background: #F1F5F9; color: #1D3B5E; border-color: #E2E8F0; }
    .btn-qr-refresh:hover { background: #E2E8F0; color: #0D7A87; }
    .btn-qr-clear { background: #FFF1F2; color: #F43F5E; border-color: #FECDD3; }
    .btn-qr-clear:hover { background: #FFE4E6; }

    /* ATTENDED STATE */
    .attended-box {
        background: #ECFDF5; border: 1.5px solid #A7F3D0; border-radius: 14px;
        padding: 20px 14px; text-align: center; width: 100%;
    }
    .attended-icon { font-size: 42px; color: #10B981; margin-bottom: 6px; }
    .attended-title { font-size: 14px; font-weight: 800; color: #065F46; margin-bottom: 4px; }
    .attended-desc { font-size: 11.5px; color: #047857; line-height: 1.5; margin-bottom: 10px; }
    .attended-meta {
        background: #fff; border: 1px solid #D1FAE5; border-radius: 8px;
        padding: 8px 10px; font-size: 11px; text-align: left;
    }

    /* ================= TAB 2: RIWAYAT & REKAP ================= */
    .rekap-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 12px; margin-bottom: 22px;
    }
    .rekap-card {
        background: #fff; border: 1.5px solid #E2E8F0; border-radius: 14px;
        padding: 14px 12px; text-align: center; position: relative;
    }
    .rekap-card__num {
        font-family: 'Outfit', sans-serif; font-size: 24px; font-weight: 900; line-height: 1;
        margin-bottom: 4px;
    }
    .rekap-card__label {
        font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.3px;
    }
    .rekap-card.hadir { border-color: #A7F3D0; }
    .rekap-card.hadir .rekap-card__num { color: #10B981; }
    .rekap-card.hadir .rekap-card__label { color: #065F46; }

    .rekap-card.izin { border-color: #FCD34D; }
    .rekap-card.izin .rekap-card__num { color: #F59E0B; }
    .rekap-card.izin .rekap-card__label { color: #B45309; }

    .rekap-card.sakit { border-color: #BAE6FD; }
    .rekap-card.sakit .rekap-card__num { color: #52B2DC; }
    .rekap-card.sakit .rekap-card__label { color: #0369A1; }

    .rekap-card.alpa { border-color: #FECDD3; }
    .rekap-card.alpa .rekap-card__num { color: #F43F5E; }
    .rekap-card.alpa .rekap-card__label { color: #9F1239; }

    .rekap-card.persen { border-color: #99F6E4; background: linear-gradient(135deg, #F0FDFA, #CCFBF1); }
    .rekap-card.persen .rekap-card__num { color: #0D7A87; }
    .rekap-card.persen .rekap-card__label { color: #115E59; }

    .table-card {
        background: #fff; border: 1.5px solid #E2E8F0; border-radius: 16px;
        overflow: hidden; box-shadow: 0 4px 16px -6px rgba(29, 59, 94, 0.06);
        margin-bottom: 22px;
    }
    .table-card-head {
        padding: 14px 18px; border-bottom: 1px solid #E2E8F0;
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        background: #F8FAFC;
    }
    .table-card-title {
        font-family: 'Outfit', sans-serif; font-size: 14.5px; font-weight: 800; color: #1D3B5E;
        display: flex; align-items: center; gap: 8px;
    }
    .table-responsive { overflow-x: auto; }
    .mtx-table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
    .mtx-table th {
        background: #F1F5F9; color: #1D3B5E; font-weight: 800;
        padding: 11px 8px; border-bottom: 1.5px solid #CBD5E1; text-align: center;
        white-space: nowrap;
    }
    .mtx-table th:first-child { text-align: left; padding-left: 16px; }
    .mtx-table td {
        padding: 11px 8px; border-bottom: 1px solid #E2E8F0; text-align: center;
        vertical-align: middle;
    }
    .mtx-table td:first-child { text-align: left; padding-left: 16px; font-weight: 700; color: #1D3B5E; }
    .mtx-table tr:hover td { background: #F8FAFC; }

    .p-badge {
        display: inline-flex; align-items: center; justify-content: center;
        width: 24px; height: 24px; border-radius: 6px; font-weight: 800; font-size: 10.5px;
        color: #fff;
    }
    .p-badge.H { background: #10B981; }
    .p-badge.I { background: #F59E0B; }
    .p-badge.S { background: #52B2DC; }
    .p-badge.A { background: #F43F5E; }
    .p-badge.T { background: #8B5CF6; }
    .p-badge.empty { background: #F1F5F9; color: #94A3B8; font-weight: 600; font-size: 9px; }

    /* ================= TAB 3: PENGAJUAN IZIN ================= */
    .izin-active-box {
        background: #fff; border: 1.5px solid #FCD34D; border-radius: 16px;
        padding: 20px; box-shadow: 0 6px 20px -8px rgba(245, 158, 11, 0.16);
        margin-bottom: 22px; position: relative; overflow: hidden;
    }
    .izin-active-box::before {
        content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px;
        background: linear-gradient(90deg, #F59E0B, #D97706);
    }
    .izin-active-head {
        display: flex; align-items: flex-start; justify-content: space-between; gap: 14px;
        padding-bottom: 14px; border-bottom: 1px dashed #E2E8F0; margin-bottom: 16px;
    }
    .izin-active-icon {
        width: 40px; height: 40px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 18px; font-weight: 900; color: #fff; flex-shrink: 0;
    }
    .izin-active-icon.Izin { background: linear-gradient(135deg, #F59E0B, #D97706); }
    .izin-active-icon.Sakit { background: linear-gradient(135deg, #52B2DC, #0284C7); }

    .izin-active-title { font-size: 14.5px; font-weight: 800; color: #1D3B5E; line-height: 1.3; }
    .izin-active-meta { font-size: 11.5px; color: #64748B; margin-top: 3px; }

    /* DUAL-APPROVAL TIMELINE */
    .stepper-timeline {
        display: flex; gap: 14px; margin: 16px 0 6px;
    }
    .step-item {
        flex: 1; display: flex; gap: 10px; position: relative;
    }
    .step-item:not(:last-child)::after {
        content: ""; position: absolute; left: 17px; top: 38px; width: 2px;
        height: calc(100% - 28px); background: #E2E8F0;
    }
    .step-item.done:not(:last-child)::after { background: #10B981; }
    .step-dot {
        width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: 15px; font-weight: 800; border: 3px solid #fff;
        position: relative; z-index: 2; transition: all .3s;
    }
    .step-dot.pending { background: #F1F5F9; color: #94A3B8; box-shadow: 0 0 0 2px #CBD5E1; }
    .step-dot.active  { background: #FEF3C7; color: #B45309; box-shadow: 0 0 0 2px #F59E0B; }
    .step-dot.done    { background: #10B981; color: #fff; box-shadow: 0 0 0 2px #A7F3D0; }
    .step-dot.rejected{ background: #F43F5E; color: #fff; box-shadow: 0 0 0 2px #FECDD3; }

    .step-body { padding-top: 2px; }
    .step-label { font-size: 10.5px; font-weight: 800; text-transform: uppercase; color: #64748B; letter-spacing: 0.3px; }
    .step-name { font-size: 12.5px; font-weight: 700; color: #1D3B5E; margin-top: 2px; }
    .step-time { font-size: 10.5px; color: #94A3B8; font-variant-numeric: tabular-nums; margin-top: 2px; }

    /* CTA BUTTON */
    .cta-izin-box {
        background: #fff; border: 1.5px solid #E2E8F0; border-radius: 16px;
        padding: 24px 18px; text-align: center; margin-bottom: 22px;
        box-shadow: 0 4px 16px -6px rgba(29, 59, 94, 0.06);
    }
    .cta-izin-btn {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 12px 24px; border-radius: 12px;
        background: linear-gradient(135deg, #F59E0B, #D97706);
        color: #fff; font-family: inherit; font-size: 13.5px; font-weight: 800;
        border: none; cursor: pointer; box-shadow: 0 4px 14px rgba(245, 158, 11, 0.35);
        transition: all .2s;
    }
    .cta-izin-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(245, 158, 11, 0.45); }
    .cta-izin-btn:disabled { background: #CBD5E1; color: #94A3B8; cursor: not-allowed; box-shadow: none; transform: none; }

    /* MODAL */
    .custom-modal {
        position: fixed; inset: 0; z-index: 99999;
        background: rgba(16, 36, 59, 0.65); backdrop-filter: blur(6px);
        display: flex; align-items: center; justify-content: center;
        padding: 16px; opacity: 0; pointer-events: none; transition: opacity .22s ease;
    }
    .custom-modal.show { opacity: 1; pointer-events: auto; }
    .modal-box {
        background: #fff; border-radius: 18px; overflow: hidden;
        max-width: 520px; width: 100%; box-shadow: 0 24px 60px -20px rgba(0,0,0,0.4);
        transform: scale(0.96); transition: transform .22s cubic-bezier(0.16,1,0.3,1);
        max-height: 90vh; display: flex; flex-direction: column;
    }
    .custom-modal.show .modal-box { transform: scale(1); }
    .modal-head {
        padding: 14px 18px; border-bottom: 1px solid #E2E8F0;
        background: #F8FAFC; display: flex; align-items: center; justify-content: space-between;
    }
    .modal-title { font-family: 'Outfit', sans-serif; font-size: 15px; font-weight: 800; color: #1D3B5E; display: flex; align-items: center; gap: 8px; }
    .modal-close { background: none; border: none; font-size: 20px; color: #94A3B8; cursor: pointer; }
    .modal-body { padding: 18px; overflow-y: auto; flex: 1; }
    .modal-foot { padding: 12px 18px; border-top: 1px solid #E2E8F0; background: #F8FAFC; display: flex; justify-content: flex-end; gap: 10px; }

    .form-group { margin-bottom: 14px; }
    .form-label { display: block; font-size: 11.5px; font-weight: 800; color: #1D3B5E; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 5px; }
    .form-control {
        width: 100%; padding: 9px 12px; border: 1.5px solid #CBD5E1; border-radius: 8px;
        font-family: inherit; font-size: 12.5px; font-weight: 600; color: #1D3B5E;
        background: #fff; outline: none; transition: all .2s;
    }
    .form-control:focus { border-color: #0D7A87; box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.15); }
    textarea.form-control { min-height: 75px; resize: vertical; }

    /* CAMERA VIEWFINDER */
    .cam-preview-box {
        background: #0F172A; border-radius: 10px; aspect-ratio: 4/3;
        max-width: 100%; overflow: hidden; position: relative;
        display: flex; align-items: center; justify-content: center;
        color: #94A3B8; font-size: 11.5px; margin-bottom: 8px;
    }
    .cam-preview-box video, .cam-preview-box img {
        width: 100%; height: 100%; object-fit: cover; display: block;
    }
    .cam-controls { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
    .btn-cam {
        padding: 7px 12px; border-radius: 8px; font-size: 11.5px; font-weight: 700;
        border: 1px solid #CBD5E1; background: #F8FAFC; color: #1D3B5E; cursor: pointer;
        display: inline-flex; align-items: center; gap: 5px;
    }
    .btn-cam:hover { background: #E2E8F0; }

    /* TOAST */
    .toast-container {
        position: fixed; top: 80px; right: 20px; z-index: 100000;
        display: flex; flex-direction: column; gap: 8px; max-width: 340px; pointer-events: none;
    }
    .toast-item {
        background: #fff; border-left: 4px solid #10B981; border-radius: 10px;
        box-shadow: 0 8px 24px rgba(0,0,0,0.14); padding: 11px 14px;
        animation: slideIn .22s ease-out; font-family: inherit; pointer-events: auto;
    }
    .toast-item.err { border-left-color: #F43F5E; }
    .toast-item.warn { border-left-color: #F59E0B; }
    .toast-title { font-size: 12.5px; font-weight: 800; color: #1D3B5E; margin-bottom: 2px; }
    .toast-msg { font-size: 11px; color: #475569; line-height: 1.4; }
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

    .role-badge-ketua {
        font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 12px;
        background: #FEF3C7; color: #B45309; border: 1px solid #FCD34D;
        text-transform: uppercase;
    }
</style>

<div class="abs-page-wrap">

    <!-- TABS NAV -->
    <nav class="tabs-header" role="tablist">
        <button type="button" class="tab-btn active" data-tab="qr" role="tab">
            <iconify-icon icon="solar:qr-code-bold" width="16"></iconify-icon>
            <span>Kartu QR Presensi</span>
        </button>
        <button type="button" class="tab-btn" data-tab="rekap" role="tab">
            <iconify-icon icon="solar:calendar-bold" width="16"></iconify-icon>
            <span>Riwayat & Rekap Kehadiran</span>
        </button>
        <button type="button" class="tab-btn" data-tab="izin" role="tab">
            <iconify-icon icon="solar:letter-bold" width="16"></iconify-icon>
            <span>Pengajuan Izin / Sakit</span>
            <span class="tab-badge" id="tabIzinBadge" data-count="<?= $activeIzin ? 1 : 0 ?>"><?= $activeIzin ? 1 : 0 ?></span>
        </button>
        <?php if ($isKetuaKelas): ?>
            <button type="button" class="tab-btn" data-tab="ketua" role="tab">
                <iconify-icon icon="solar:shield-check-bold" width="16"></iconify-icon>
                <span>Persetujuan Kelas</span>
                <span class="tab-badge" id="tabKetuaBadge" data-count="<?= count($pendingClassmateIzin) ?>"><?= count($pendingClassmateIzin) ?></span>
            </button>
        <?php endif; ?>
    </nav>

    <!-- ================= TAB 1: KARTU QR PRESENSI ================= -->
    <div class="tab-panel" id="panel-qr">
        <div class="qr-grid">
            <?php foreach ($cardList as $card): 
                $m = $card['mapel'];
                $prs = $card['presensi'];
                $jadwal = $card['jadwal'];
                $isAttended = $card['is_attended'];
                $absenRec = $card['absen_record'];
                $idPresensi = $prs ? (string)$prs['id_presensi'] : '';
                $idKmp = (int)$m['id_kmp'];
            ?>
                <div class="qr-card" data-id-kmp="<?= $idKmp ?>" data-id-presensi="<?= htmlspecialchars($idPresensi) ?>">
                    <span class="qr-card-badge <?= $isAttended ? 'hadir' : 'aktif' ?>">
                        <?= $isAttended ? 'Sudah Hadir' : 'Siap Presensi' ?>
                    </span>

                    <div class="qr-card-head">
                        <h3 class="qr-card-title"><?= htmlspecialchars($m['nama_mata_pelajaran']) ?></h3>
                        <div class="qr-card-guru">
                            <iconify-icon icon="solar:user-bold"></iconify-icon>
                            <span><?= htmlspecialchars($m['nama_guru'] ?: 'Guru Pengampu PAI') ?></span>
                        </div>
                    </div>

                    <?php if ($jadwal): ?>
                        <div class="qr-card-schedule">
                            <div><strong>P<?= (int)$jadwal['pertemuan_ke'] ?>:</strong> <?= htmlspecialchars($jadwal['materi_pembahasan'] ?: 'KBM Pertemuan ' . $jadwal['pertemuan_ke']) ?></div>
                            <div style="margin-top: 3px; font-size: 10.5px; color: #64748B;">
                                <iconify-icon icon="solar:clock-circle-bold"></iconify-icon> <?= substr($jadwal['jam_mulai'] ?? '07:00', 0, 5) ?> - <?= substr($jadwal['jam_selesai'] ?? '08:30', 0, 5) ?> WIB
                                • Ruang <?= htmlspecialchars($jadwal['ruangan'] ?? 'Kelas') ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($isAttended): 
                        $statusLabel = [
                            'H' => 'Hadir',
                            'I' => 'Izin',
                            'S' => 'Sakit',
                            'A' => 'Alpa',
                            'T' => 'Terlambat'
                        ][$absenRec['status']] ?? 'Tercatat';
                    ?>
                        <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 10px; padding: 8px 12px; margin-bottom: 12px; font-size: 11px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <iconify-icon icon="solar:check-circle-bold" width="16" style="color: #10B981;"></iconify-icon>
                                <span><strong>Presensi Terakhir:</strong> <span style="font-weight: 800; color: #166534;"><?= $statusLabel ?></span> (<?= date('H:i', strtotime($absenRec['waktu_absen'])) ?> WIB)</span>
                            </div>
                            <span style="font-size: 10px; color: #64748B; font-weight: 600;"><?= htmlspecialchars($absenRec['metode_absen'] ?? 'Scan QR') ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="gps-slot">
                        <div class="gps-box" data-gps-box data-id-kmp="<?= $idKmp ?>" data-id-presensi="<?= htmlspecialchars($idPresensi) ?>">
                            <div class="gps-icon">
                                <iconify-icon icon="solar:gps-bold"></iconify-icon>
                            </div>
                            <div class="gps-title">GPS Lokasi Diperlukan</div>
                            <p class="gps-desc">
                                Klik tombol untuk mengambil koordinat GPS dan memunculkan QR Presensi Anda.
                            </p>
                            <button type="button" class="btn-capture" data-action="capture">
                                <iconify-icon icon="solar:gps-bold" width="15"></iconify-icon>
                                <span>Ambil QR Presensi</span>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ================= TAB 2: RIWAYAT & REKAP KEHADIRAN ================= -->
    <div class="tab-panel" id="panel-rekap" style="display: none;">
        <div class="rekap-grid">
            <div class="rekap-card hadir">
                <div class="rekap-card__num"><?= $totalHadir ?></div>
                <div class="rekap-card__label">Hadir (H)</div>
            </div>
            <div class="rekap-card izin">
                <div class="rekap-card__num"><?= $totalIzin ?></div>
                <div class="rekap-card__label">Izin (I)</div>
            </div>
            <div class="rekap-card sakit">
                <div class="rekap-card__num"><?= $totalSakit ?></div>
                <div class="rekap-card__label">Sakit (S)</div>
            </div>
            <div class="rekap-card alpa">
                <div class="rekap-card__num"><?= $totalAlpa ?></div>
                <div class="rekap-card__label">Alpa (A)</div>
            </div>
            <div class="rekap-card persen">
                <div class="rekap-card__num"><?= $persenKehadiran ?>%</div>
                <div class="rekap-card__label">Kehadiran Total</div>
            </div>
        </div>

        <!-- TABEL MATRIKS P1 - P16 -->
        <div class="table-card">
            <div class="table-card-head">
                <h3 class="table-card-title">
                    <iconify-icon icon="solar:checklist-minimalistic-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                    <span>Matriks Kehadiran Siswa (Pertemuan 1 s.d 16)</span>
                </h3>
            </div>
            <div class="table-responsive">
                <table class="mtx-table">
                    <thead>
                        <tr>
                            <th>Mata Pelajaran</th>
                            <?php for ($p = 1; $p <= 16; $p++): ?>
                                <th>P<?= $p ?></th>
                            <?php endfor; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($matrixData as $idKmp => $item): 
                            $m = $item['mapel'];
                            $grid = $item['grid'];
                        ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 800; color: #1D3B5E;"><?= htmlspecialchars($m['nama_mata_pelajaran']) ?></div>
                                    <div style="font-size: 11px; color: #64748B;"><?= htmlspecialchars($m['nama_guru'] ?: 'Guru Pengampu') ?></div>
                                </td>
                                <?php for ($p = 1; $p <= 16; $p++): 
                                    $record = $grid[$p] ?? null;
                                    $st = $record ? $record['status'] : null;
                                ?>
                                    <td>
                                        <?php if ($st): ?>
                                            <span class="p-badge <?= $st ?>" title="P<?= $p ?>: <?= $st ?> (<?= htmlspecialchars($record['waktu_absen']) ?>)"><?= $st ?></span>
                                        <?php else: ?>
                                            <span class="p-badge empty">•</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endfor; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ================= TAB 3: PENGAJUAN IZIN & SAKIT ================= -->
    <div class="tab-panel" id="panel-izin" style="display: none;">
        
        <!-- PERIZINAN AKTIF (JIKA ADA) -->
        <div class="izin-active-box" id="activeIzinWrap" style="<?= $activeIzin ? '' : 'display: none;' ?>">
            <div class="izin-active-head">
                <div style="display: flex; gap: 12px; align-items: center;">
                    <div class="izin-active-icon <?= htmlspecialchars($activeIzin['jenis_izin'] ?? 'Izin') ?>" id="activeIzinIcon">
                        <?= ($activeIzin['jenis_izin'] ?? 'Izin') === 'Sakit' ? 'S' : 'I' ?>
                    </div>
                    <div>
                        <div class="izin-active-title" id="activeIzinTitle">
                            <?= htmlspecialchars($activeIzin['jenis_izin'] ?? 'Izin') ?> — <?= htmlspecialchars($activeIzin['nama_mata_pelajaran'] ?? '') ?>
                        </div>
                        <div class="izin-active-meta" id="activeIzinMeta">
                            Pertemuan <?= (int)($activeIzin['pertemuan_ke'] ?? 1) ?> • <?= date('d M Y', strtotime($activeIzin['tanggal_izin'] ?? 'now')) ?>
                        </div>
                    </div>
                </div>
                <span class="role-badge-ketua" id="activeIzinPill"><?= htmlspecialchars($activeIzin['status_perizinan'] ?? 'Menunggu Verifikasi') ?></span>
            </div>

            <!-- DUAL-APPROVAL TIMELINE (KETUA KELAS -> GURU PENGAMPU) -->
            <div class="stepper-timeline" id="stepperTimeline">
                <div class="step-item <?= ($activeIzin && $activeIzin['approval_ketua_kelas'] === 'Approved') ? 'done' : (($activeIzin && $activeIzin['approval_ketua_kelas'] === 'Rejected') ? 'rejected' : 'active') ?>" id="stepKetua">
                    <div class="step-dot <?= ($activeIzin && $activeIzin['approval_ketua_kelas'] === 'Approved') ? 'done' : (($activeIzin && $activeIzin['approval_ketua_kelas'] === 'Rejected') ? 'rejected' : 'active') ?>">
                        <iconify-icon icon="solar:user-bold" width="16"></iconify-icon>
                    </div>
                    <div class="step-body">
                        <div class="step-label">Tahap 1: Ketua Kelas</div>
                        <div class="step-name"><?= htmlspecialchars($activeIzin['nama_ketua'] ?? 'Menunggu Ketua Kelas') ?></div>
                        <div class="step-time"><?= !empty($activeIzin['waktu_approval_ketua']) ? date('d M Y H:i', strtotime($activeIzin['waktu_approval_ketua'])) : 'Dalam proses verifikasi' ?></div>
                    </div>
                </div>

                <div class="step-item <?= ($activeIzin && $activeIzin['approval_guru'] === 'Approved') ? 'done' : (($activeIzin && $activeIzin['approval_guru'] === 'Rejected') ? 'rejected' : (($activeIzin && $activeIzin['approval_ketua_kelas'] === 'Approved') ? 'active' : 'pending')) ?>" id="stepGuru">
                    <div class="step-dot <?= ($activeIzin && $activeIzin['approval_guru'] === 'Approved') ? 'done' : (($activeIzin && $activeIzin['approval_guru'] === 'Rejected') ? 'rejected' : (($activeIzin && $activeIzin['approval_ketua_kelas'] === 'Approved') ? 'active' : 'pending')) ?>">
                        <iconify-icon icon="solar:diploma-bold" width="16"></iconify-icon>
                    </div>
                    <div class="step-body">
                        <div class="step-label">Tahap 2: Guru Pengampu</div>
                        <div class="step-name"><?= htmlspecialchars($activeIzin['nama_guru'] ?? 'Guru Pengampu PAI') ?></div>
                        <div class="step-time"><?= !empty($activeIzin['waktu_approval_guru']) ? date('d M Y H:i', strtotime($activeIzin['waktu_approval_guru'])) : 'Menunggu tahap 1 selesai' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CTA AJUKAN IZIN -->
        <div class="cta-izin-box">
            <h3 style="font-family: 'Outfit', sans-serif; font-size: 15.5px; font-weight: 800; color: #1D3B5E; margin-bottom: 5px;">
                Berhalangan Hadir KBM Hari Ini?
            </h3>
            <p style="font-size: 12px; color: #64748B; max-width: 480px; margin: 0 auto 16px; line-height: 1.5;">
                Kirimkan permohonan izin atau sakit disertai foto bukti dan titik koordinat GPS Anda. Permohonan akan diverifikasi oleh Ketua Kelas dan Guru Pengampu.
            </p>
            <button type="button" class="cta-izin-btn" id="btnOpenIzinModal" <?= $activeIzin ? 'disabled' : '' ?>>
                <iconify-icon icon="solar:letter-bold" width="16"></iconify-icon>
                <span><?= $activeIzin ? 'Selesaikan Permohonan Aktif Terlebih Dahulu' : 'Ajukan Permohonan Izin / Sakit' ?></span>
            </button>
        </div>

        <!-- RIWAYAT PERIZINAN -->
        <div class="table-card">
            <div class="table-card-head">
                <h3 class="table-card-title">
                    <iconify-icon icon="solar:history-bold" width="17" style="color: #0D7A87;"></iconify-icon>
                    <span>Riwayat Permohonan Izin Selesai</span>
                </h3>
            </div>
            <div id="izinHistoryWrap" style="padding: 14px 18px;">
                <p style="font-size: 12px; color: #94A3B8; text-align: center; padding: 18px 0;">Memuat riwayat perizinan…</p>
            </div>
        </div>
    </div>

    <!-- ================= TAB 4: PERSETUJUAN KETUA KELAS ================= -->
    <?php if ($isKetuaKelas): ?>
        <div class="tab-panel" id="panel-ketua" style="display: none;">
            <div class="table-card">
                <div class="table-card-head">
                    <h3 class="table-card-title">
                        <iconify-icon icon="solar:shield-check-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                        <span>Daftar Permohonan Izin Teman Sekelas (Tahap 1: Verifikasi Ketua Kelas)</span>
                    </h3>
                    <span class="role-badge-ketua"><?= count($pendingClassmateIzin) ?> Menunggu</span>
                </div>

                <?php if (empty($pendingClassmateIzin)): ?>
                    <div style="padding: 36px 20px; text-align: center; color: #64748B;">
                        <iconify-icon icon="solar:check-circle-bold" width="44" style="color: #10B981; margin-bottom: 6px;"></iconify-icon>
                        <h4 style="font-family: 'Outfit', sans-serif; font-size: 14.5px; font-weight: 800; color: #1D3B5E; margin-bottom: 4px;">Semua Permohonan Selesai</h4>
                        <p style="font-size: 11.5px; color: #94A3B8;">Tidak ada permohonan izin teman sekelas yang menunggu verifikasi saat ini.</p>
                    </div>
                <?php else: ?>
                    <div style="padding: 14px 18px; display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($pendingClassmateIzin as $pk): ?>
                            <div style="background: #F8FAFC; border: 1.5px solid #E2E8F0; border-radius: 12px; padding: 14px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;" id="classmateCard-<?= $pk['id'] ?>">
                                <div style="display: flex; gap: 12px; align-items: center;">
                                    <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #0D7A87, #52B2DC); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; overflow: hidden;">
                                        <?php if (!empty($pk['foto_siswa'])): ?>
                                            <img src="<?= htmlspecialchars($pk['foto_siswa']) ?>" alt="Foto" style="width: 100%; height: 100%; object-fit: cover;">
                                        <?php else: ?>
                                            <?= mb_strtoupper(mb_substr($pk['nama_siswa'], 0, 2)) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div style="font-size: 13px; font-weight: 800; color: #1D3B5E;">
                                            <?= htmlspecialchars($pk['nama_siswa']) ?> 
                                            <span class="role-badge-ketua" style="margin-left: 6px;"><?= strtoupper($pk['jenis_izin']) ?></span>
                                        </div>
                                        <div style="font-size: 11px; color: #64748B; margin-top: 2px;">
                                            <?= htmlspecialchars($pk['nama_mata_pelajaran']) ?> • P<?= (int)$pk['pertemuan_ke'] ?> • "<?= htmlspecialchars($pk['alasan']) ?>"
                                        </div>
                                    </div>
                                </div>

                                <div style="display: flex; gap: 8px;">
                                    <button type="button" class="btn-cam" onclick="viewBuktiIzin('<?= htmlspecialchars($pk['bukti_perizinan']) ?>', '<?= addslashes($pk['nama_siswa']) ?>')">
                                        <iconify-icon icon="solar:gallery-bold"></iconify-icon> Bukti
                                    </button>
                                    <button type="button" class="btn-qr-action" style="background: #10B981; color: #fff;" onclick="prosesKetuaKelas(<?= $pk['id'] ?>, 'approve')">
                                        <iconify-icon icon="solar:check-circle-bold"></iconify-icon> Setujui
                                    </button>
                                    <button type="button" class="btn-qr-action btn-qr-clear" onclick="prosesKetuaKelas(<?= $pk['id'] ?>, 'reject')">
                                        <iconify-icon icon="solar:close-circle-bold"></iconify-icon> Tolak
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- ================= MODAL PENGAJUAN IZIN ================= -->
<div class="custom-modal" id="modalIzin">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title">
                <iconify-icon icon="solar:letter-bold" width="18" style="color: #F59E0B;"></iconify-icon>
                <span>Form Permohonan Izin / Sakit KBM</span>
            </div>
            <button type="button" class="modal-close" id="btnModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <form id="formIzin" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="form-label">Mata Pelajaran KBM</label>
                    <select class="form-control" name="id_km" id="selectMapel" required>
                        <option value="">-- Pilih Mata Pelajaran --</option>
                        <?php foreach ($enrolledMapel as $m): ?>
                            <option value="<?= $m['id_kmp'] ?>">
                                <?= htmlspecialchars($m['nama_mata_pelajaran']) ?> (<?= htmlspecialchars($m['nama_guru'] ?: 'Guru Pengampu') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Kategori Perizinan</label>
                    <select class="form-control" name="jenis" id="selectJenis" required>
                        <option value="Izin">Izin (Ada keperluan penting / keluarga / madrasah)</option>
                        <option value="Sakit">Sakit (Kondisi kesehatan tidak memungkinkan)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Alasan Detail</label>
                    <textarea class="form-control" name="alasan" id="textareaAlasan" placeholder="Tuliskan keterangan lengkap alasan Anda berhalangan..." required></textarea>
                </div>

                <!-- CAMERA & FOTO BUKTI -->
                <div class="form-group">
                    <label class="form-label">Foto Bukti / Surat Izin (Wajib)</label>
                    <div class="cam-preview-box" id="camPreviewBox">
                        <div id="camPlaceholder">
                            <iconify-icon icon="solar:camera-bold" width="32" style="margin-bottom: 5px;"></iconify-icon>
                            <div>Kamera belum aktif</div>
                        </div>
                    </div>
                    <div class="cam-controls">
                        <button type="button" class="btn-cam" id="btnStartCam">
                            <iconify-icon icon="solar:videocamera-bold"></iconify-icon> Buka Kamera
                        </button>
                        <button type="button" class="btn-cam" id="btnSnapCam" style="background: #0D7A87; color: #fff; display: none;">
                            <iconify-icon icon="solar:camera-bold"></iconify-icon> Ambil Foto
                        </button>
                        <button type="button" class="btn-cam" id="btnResetCam" style="color: #F43F5E; display: none;">
                            <iconify-icon icon="solar:restart-bold"></iconify-icon> Foto Ulang
                        </button>
                    </div>
                    <div style="font-size: 11px; color: #64748B; margin-top: 4px;">
                        Atau unggah file: <input type="file" id="fileBuktiInput" accept="image/*,application/pdf" style="font-size: 11px;">
                    </div>
                </div>

                <!-- GPS KOORDINAT -->
                <div class="form-group">
                    <label class="form-label">Titik Koordinat Lokasi Anda (GPS)</label>
                    <div id="gpsLocationStatus" style="background: #F8FAFC; border: 1.5px dashed #CBD5E1; border-radius: 8px; padding: 9px 12px; font-size: 11.5px; color: #64748B; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <span id="gpsLocationText">Titik koordinat belum diambil</span>
                        <button type="button" class="btn-cam" id="btnGetLocationModal">
                            <iconify-icon icon="solar:gps-bold"></iconify-icon> Ambil Lokasi
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn-cam" id="btnModalCancel">Batal</button>
            <button type="button" class="btn-capture" id="btnSubmitIzinModal" style="margin: 0;">
                <iconify-icon icon="solar:letter-bold" width="15"></iconify-icon>
                <span>Kirimkan Permohonan</span>
            </button>
        </div>
    </div>
</div>

<!-- TOAST BOX -->
<div class="toast-container" id="toastContainer"></div>

<script>
(function(){
    'use strict';

    // ----------------------------------------------------------------------
    // KONFIGURASI GPS & CACHE LOKAL
    // ----------------------------------------------------------------------
    const GPS_CONFIG = {
        ACCURACY_HIGH: 25,
        ACCURACY_WARN: 100,
        ACCURACY_MAX: 500,
        TIMEOUT: 6000,
        CACHE_MAX_AGE: 120000,
        STORAGE_PREFIX: 'ilmuna_qr_'
    };

    function showToast(type, title, msg) {
        const normType = (type === 'err' || type === 'error' || type === 'danger') ? 'error' : ((type === 'warn' || type === 'warning') ? 'warning' : 'success');
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, title, msg, 5000);
        }
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const item = document.createElement('div');
        item.className = 'toast-item ' + (type === 'err' ? 'err' : (type === 'warn' ? 'warn' : ''));
        item.innerHTML = `<div class="toast-title">${esc(title)}</div><div class="toast-msg">${esc(msg)}</div>`;
        container.appendChild(item);
        setTimeout(() => { item.style.opacity = '0'; setTimeout(() => item.remove(), 250); }, 5000);
    }

    function esc(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function lsKey(token) { return GPS_CONFIG.STORAGE_PREFIX + token; }
    function getCachedGps(token) {
        try {
            const raw = localStorage.getItem(lsKey(token));
            if (!raw) return null;
            const d = JSON.parse(raw);
            if (d && d.lat && d.lng) return d;
            return null;
        } catch(e) { return null; }
    }
    function setCachedGps(token, data) {
        try { localStorage.setItem(lsKey(token), JSON.stringify(data)); } catch(e){}
    }
    function clearCachedGps(token) {
        try { localStorage.removeItem(lsKey(token)); } catch(e){}
    }

    // GPS CAPTURE ULTRA-FAST
    function acquireGps(token, onTick, onSuccess, onError) {
        if (!navigator.geolocation) {
            onError('Browser Anda tidak mendukung layanan Geolocation.');
            return;
        }

        const startTime = Date.now();
        let isSettled = false;
        let timerTicker = setInterval(() => {
            const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
            onTick(elapsed);
        }, 150);

        function handleSuccess(pos, mode) {
            if (isSettled) return;
            isSettled = true;
            clearInterval(timerTicker);

            const lat = parseFloat(pos.coords.latitude.toFixed(7));
            const lng = parseFloat(pos.coords.longitude.toFixed(7));
            const acc = Math.round(pos.coords.accuracy || 999);

            if (acc > GPS_CONFIG.ACCURACY_MAX) {
                onError('Akurasi GPS terlalu rendah (' + acc + 'm). Mohon bergeser ke area terbuka.');
                return;
            }

            const payload = {
                id: token,
                lat: lat,
                lng: lng,
                a: acc,
                t: Math.floor(Date.now() / 1000),
                mode: mode
            };
            onSuccess(payload);
        }

        function handleFail(err) {
            if (isSettled) return;
            isSettled = true;
            clearInterval(timerTicker);
            let msg = 'Gagal mendeteksi lokasi.';
            if (err.code === 1) msg = 'Akses lokasi DITOLAK oleh browser. Mohon izinkan lokasi di pengaturan situs.';
            else if (err.code === 2) msg = 'Lokasi tidak tersedia atau sinyal GPS lemah.';
            else if (err.code === 3) msg = 'Waktu permintaan GPS habis. Coba lagi.';
            onError(msg);
        }

        navigator.geolocation.getCurrentPosition(
            (p) => handleSuccess(p, 'cache'),
            (err1) => {
                navigator.geolocation.getCurrentPosition(
                    (p) => handleSuccess(p, 'fresh'),
                    (err2) => handleFail(err2),
                    { enableHighAccuracy: true, timeout: GPS_CONFIG.TIMEOUT, maximumAge: 0 }
                );
            },
            { enableHighAccuracy: true, timeout: GPS_CONFIG.TIMEOUT, maximumAge: GPS_CONFIG.CACHE_MAX_AGE }
        );
    }

    // RENDER QR BOX
    function renderQrBox(boxEl) {
        const token = boxEl.dataset.idPresensi;
        const idKmp = boxEl.dataset.idKmp;
        if (!token) return;

        const cached = getCachedGps(token);
        if (!cached) {
            boxEl.className = 'gps-box';
            boxEl.innerHTML = `
                <div class="gps-icon"><iconify-icon icon="solar:gps-bold"></iconify-icon></div>
                <div class="gps-title">GPS Lokasi Diperlukan</div>
                <p class="gps-desc">Klik tombol untuk mengambil koordinat GPS dan memunculkan QR Presensi Anda.</p>
                <button type="button" class="btn-capture" data-action="capture">
                    <iconify-icon icon="solar:gps-bold" width="15"></iconify-icon>
                    <span>Ambil QR Presensi</span>
                </button>
            `;
            bindBoxEvents(boxEl, token, idKmp);
            return;
        }

        renderActiveQr(boxEl, token, idKmp, cached);
    }

    function renderActiveQr(boxEl, token, idKmp, data) {
        const cardEl = boxEl.closest('.qr-card');
        const title = cardEl ? cardEl.querySelector('.qr-card-title').textContent.trim() : 'Presensi KBM';
        
        const payload = JSON.stringify({
            id: token,
            id_kmp: parseInt(idKmp, 10),
            lat: data.lat,
            lng: data.lng,
            a: data.a,
            t: data.t
        });

        const accClass = data.a <= GPS_CONFIG.ACCURACY_HIGH ? 'good' : (data.a <= GPS_CONFIG.ACCURACY_WARN ? 'warn' : 'bad');
        const accLabel = data.a <= GPS_CONFIG.ACCURACY_HIGH ? 'Akurasi Tinggi' : (data.a <= GPS_CONFIG.ACCURACY_WARN ? 'Akurasi Sedang' : 'Akurasi Rendah');
        const timeDate = new Date(data.t * 1000).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });

        boxEl.className = 'qr-display-wrap';
        boxEl.innerHTML = `
            <div class="qr-canvas-holder" id="qrHolder-${token}">
                <div class="qr-shield" aria-hidden="true"></div>
                <div class="qr-watermark">${esc(title)}</div>
            </div>
            <div class="acc-pill ${accClass}">
                <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
                <span>${accLabel} ±${data.a}m</span>
            </div>
            <div class="qr-meta-info">
                <div class="qr-meta-row"><span>Latitude:</span><span>${data.lat}</span></div>
                <div class="qr-meta-row"><span>Longitude:</span><span>${data.lng}</span></div>
                <div class="qr-meta-row"><span>Diambil:</span><span>${timeDate} WIB</span></div>
            </div>
            <div class="qr-actions-row">
                <button type="button" class="btn-qr-action btn-qr-refresh" data-action="refresh">
                    <iconify-icon icon="solar:restart-bold"></iconify-icon> Refresh GPS
                </button>
                <button type="button" class="btn-qr-action btn-qr-clear" data-action="clear">
                    <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon> Hapus
                </button>
            </div>
        `;

        const holder = document.getElementById(`qrHolder-${token}`);
        if (holder) {
            if (window.QRCode) {
                new QRCode(holder, {
                    text: payload,
                    width: 190,
                    height: 190,
                    colorDark: "#1D3B5E",
                    colorLight: "#FFFFFF",
                    correctLevel: QRCode.CorrectLevel.H
                });
            } else {
                const img = document.createElement('img');
                img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&data=' + encodeURIComponent(payload);
                img.alt = 'QR Presensi';
                holder.appendChild(img);
            }
        }

        bindBoxEvents(boxEl, token, idKmp);
    }

    function bindBoxEvents(boxEl, token, idKmp) {
        const captureBtn = boxEl.querySelector('[data-action="capture"]');
        const refreshBtn = boxEl.querySelector('[data-action="refresh"]');
        const clearBtn   = boxEl.querySelector('[data-action="clear"]');

        if (captureBtn) captureBtn.addEventListener('click', () => triggerCapture(boxEl, token, idKmp));
        if (refreshBtn) refreshBtn.addEventListener('click', () => triggerCapture(boxEl, token, idKmp));
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                clearCachedGps(token);
                renderQrBox(boxEl);
                showToast('warn', 'Cache Dihapus', 'Koordinat GPS untuk mata pelajaran ini telah dibersihkan.');
            });
        }
    }

    function triggerCapture(boxEl, token, idKmp) {
        boxEl.className = 'gps-box loading';
        boxEl.innerHTML = `
            <div class="gps-icon"><iconify-icon icon="solar:radar-bold"></iconify-icon></div>
            <div class="gps-title">Mendeteksi Titik GPS…</div>
            <p class="gps-desc">Pastikan GPS ponsel / perangkat Anda aktif.</p>
            <div style="font-family: monospace; font-size: 13px; font-weight: 800; color: #0D7A87;" id="gpsTicker-${token}">0.0s</div>
        `;

        acquireGps(
            token,
            (sec) => {
                const tickEl = document.getElementById(`gpsTicker-${token}`);
                if (tickEl) tickEl.textContent = sec + 's';
            },
            (payload) => {
                setCachedGps(token, payload);
                renderActiveQr(boxEl, token, idKmp, payload);
                showToast('ok', 'GPS Terdeteksi', 'QR Code presensi aktif dan terikat koordinat GPS Anda.');
            },
            (errMsg) => {
                boxEl.className = 'gps-box err';
                boxEl.innerHTML = `
                    <div class="gps-icon"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon></div>
                    <div class="gps-title">GPS Belum Aktif</div>
                    <p class="gps-desc">${esc(errMsg)}</p>
                    <button type="button" class="btn-capture" data-action="capture">
                        <iconify-icon icon="solar:restart-bold" width="15"></iconify-icon>
                        <span>Coba Lagi</span>
                    </button>
                `;
                bindBoxEvents(boxEl, token, idKmp);
                showToast('err', 'Gagal Mendeteksi GPS', errMsg);
            }
        );
    }

    // Inisialisasi Box
    document.querySelectorAll('[data-gps-box]').forEach(box => renderQrBox(box));

    // TAB SWITCHING
    const tabButtons = document.querySelectorAll('.tab-btn[data-tab]');
    const tabPanels  = document.querySelectorAll('.tab-panel');

    tabButtons.forEach(btn => {
        btn.addEventListener('click', function(){
            const target = this.dataset.tab;
            tabButtons.forEach(b => b.classList.toggle('active', b === this));
            tabPanels.forEach(p => p.style.display = (p.id === 'panel-' + target) ? 'block' : 'none');
            if (target === 'izin') {
                pollIzinStatus();
            }
        });
    });

    // POLLING STATUS PERIZINAN & TIMELINE
    async function pollIzinStatus() {
        try {
            const res = await fetch('absensi.php?action=get_izin_status').then(r => r.json());
            if (!res || !res.success) return;

            const badge = document.getElementById('tabIzinBadge');
            if (badge) {
                const cnt = res.has_active ? 1 : 0;
                badge.textContent = cnt;
                badge.setAttribute('data-count', String(cnt));
            }

            const activeWrap = document.getElementById('activeIzinWrap');
            const btnOpenModal = document.getElementById('btnOpenIzinModal');

            if (res.has_active && res.active) {
                if (activeWrap) activeWrap.style.display = 'block';
                if (btnOpenModal) {
                    btnOpenModal.disabled = true;
                    btnOpenModal.querySelector('span').textContent = 'Selesaikan Permohonan Aktif Terlebih Dahulu';
                }

                const act = res.active;
                const titleEl = document.getElementById('activeIzinTitle');
                const metaEl  = document.getElementById('activeIzinMeta');
                const pillEl  = document.getElementById('activeIzinPill');
                const iconEl  = document.getElementById('activeIzinIcon');

                if (titleEl) titleEl.textContent = `${act.jenis_izin} — ${act.nama_mapel}`;
                if (metaEl)  metaEl.textContent = `Pertemuan ${act.pertemuan_ke} • ${act.tanggal_izin}`;
                if (pillEl)  pillEl.textContent = act.status_perizinan;
                if (iconEl) {
                    iconEl.className = 'izin-active-icon ' + act.jenis_izin;
                    iconEl.textContent = act.jenis_izin === 'Sakit' ? 'S' : 'I';
                }

                if (act.steps && act.steps.length === 2) {
                    const s1 = act.steps[0];
                    const s2 = act.steps[1];

                    const stepK = document.getElementById('stepKetua');
                    if (stepK) {
                        stepK.className = 'step-item ' + s1.state;
                        stepK.querySelector('.step-dot').className = 'step-dot ' + s1.state;
                        stepK.querySelector('.step-name').textContent = s1.name || 'Ketua Kelas';
                        stepK.querySelector('.step-time').textContent = s1.time || 'Dalam proses verifikasi';
                    }

                    const stepG = document.getElementById('stepGuru');
                    if (stepG) {
                        stepG.className = 'step-item ' + s2.state;
                        stepG.querySelector('.step-dot').className = 'step-dot ' + s2.state;
                        stepG.querySelector('.step-name').textContent = s2.name || 'Guru Pengampu PAI';
                        stepG.querySelector('.step-time').textContent = s2.time || (s1.state === 'done' ? 'Menunggu verifikasi guru' : 'Menunggu tahap 1');
                    }
                }
            } else {
                if (activeWrap) activeWrap.style.display = 'none';
                if (btnOpenModal) {
                    btnOpenModal.disabled = false;
                    btnOpenModal.querySelector('span').textContent = 'Ajukan Permohonan Izin / Sakit';
                }
            }

            const histWrap = document.getElementById('izinHistoryWrap');
            if (histWrap && res.history) {
                if (res.history.length === 0) {
                    histWrap.innerHTML = '<p style="font-size: 11.5px; color: #94A3B8; text-align: center; padding: 18px 0;">Belum ada riwayat perizinan lampau.</p>';
                } else {
                    histWrap.innerHTML = res.history.map(h => `
                        <div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 11px 14px; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                            <div>
                                <div style="font-size: 12.5px; font-weight: 800; color: #1D3B5E;">
                                    ${esc(h.jenis_izin)} — ${esc(h.nama_mata_pelajaran)} (P${h.pertemuan_ke || 1})
                                </div>
                                <div style="font-size: 10.5px; color: #64748B; margin-top: 2px;">
                                    ${new Date(h.tanggal_izin).toLocaleDateString('id-ID', {day:'numeric', month:'short', year:'numeric'})} • Alasan: "${esc(h.alasan)}"
                                </div>
                            </div>
                            <span class="role-badge-ketua" style="${h.status_perizinan === 'Disetujui' ? 'background:#D1FAE5;color:#065F46;border-color:#A7F3D0;' : 'background:#FFE4E6;color:#9F1239;border-color:#FECDD3;'}">
                                ${esc(h.status_perizinan)}
                            </span>
                        </div>
                    `).join('');
                }
            }
        } catch(e) { console.warn('[pollIzin]', e); }
    }

    // MODAL & KAMERA
    const modalIzin      = document.getElementById('modalIzin');
    const btnOpenModal   = document.getElementById('btnOpenIzinModal');
    const btnModalClose  = document.getElementById('btnModalClose');
    const btnModalCancel = document.getElementById('btnModalCancel');
    const btnSubmitIzin  = document.getElementById('btnSubmitIzinModal');

    let camStream = null;
    let camPhotoData = null;
    let izinCoordinates = null;

    if (btnOpenModal) {
        btnOpenModal.addEventListener('click', () => {
            if (btnOpenModal.disabled) return;
            modalIzin.classList.add('show');
            document.body.style.overflow = 'hidden';
        });
    }

    function closeModal() {
        modalIzin.classList.remove('show');
        document.body.style.overflow = '';
        stopCamera();
    }

    if (btnModalClose) btnModalClose.addEventListener('click', closeModal);
    if (btnModalCancel) btnModalCancel.addEventListener('click', closeModal);

    const btnStartCam = document.getElementById('btnStartCam');
    const btnSnapCam  = document.getElementById('btnSnapCam');
    const btnResetCam = document.getElementById('btnResetCam');
    const camBox      = document.getElementById('camPreviewBox');
    let videoEl       = null;

    if (btnStartCam) {
        btnStartCam.addEventListener('click', async () => {
            try {
                camStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: false
                });
                camBox.innerHTML = '';
                videoEl = document.createElement('video');
                videoEl.autoplay = true;
                videoEl.playsInline = true;
                videoEl.srcObject = camStream;
                camBox.appendChild(videoEl);

                btnStartCam.style.display = 'none';
                btnSnapCam.style.display = 'inline-flex';
                btnResetCam.style.display = 'none';
            } catch(err) {
                showToast('err', 'Kamera Gagal', 'Tidak dapat mengakses kamera: ' + err.message);
            }
        });
    }

    if (btnSnapCam) {
        btnSnapCam.addEventListener('click', () => {
            if (!videoEl) return;
            const canvas = document.createElement('canvas');
            canvas.width = videoEl.videoWidth || 640;
            canvas.height = videoEl.videoHeight || 480;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
            camPhotoData = canvas.toDataURL('image/jpeg', 0.85);

            camBox.innerHTML = `<img src="${camPhotoData}" alt="Bukti Foto" style="width:100%; height:100%; object-fit:cover;">`;
            stopCamera();

            btnSnapCam.style.display = 'none';
            btnResetCam.style.display = 'inline-flex';
        });
    }

    if (btnResetCam) {
        btnResetCam.addEventListener('click', () => {
            camPhotoData = null;
            camBox.innerHTML = '<div id="camPlaceholder"><iconify-icon icon="solar:camera-bold" width="32"></iconify-icon><div>Kamera belum aktif</div></div>';
            btnResetCam.style.display = 'none';
            btnStartCam.style.display = 'inline-flex';
        });
    }

    function stopCamera() {
        if (camStream) {
            camStream.getTracks().forEach(t => t.stop());
            camStream = null;
        }
    }

    const fileInput = document.getElementById('fileBuktiInput');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    camPhotoData = e.target.result;
                    camBox.innerHTML = `<img src="${camPhotoData}" alt="Bukti Foto" style="width:100%; height:100%; object-fit:cover;">`;
                    btnStartCam.style.display = 'none';
                    btnSnapCam.style.display = 'none';
                    btnResetCam.style.display = 'inline-flex';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }

    const btnGetLoc = document.getElementById('btnGetLocationModal');
    const locText   = document.getElementById('gpsLocationText');

    if (btnGetLoc) {
        btnGetLoc.addEventListener('click', () => {
            locText.textContent = 'Mendeteksi titik koordinat…';
            btnGetLoc.disabled = true;

            if (!navigator.geolocation) {
                locText.textContent = 'Geolocation tidak didukung browser.';
                btnGetLoc.disabled = false;
                return;
            }

            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    izinCoordinates = {
                        lat: parseFloat(pos.coords.latitude.toFixed(7)),
                        lng: parseFloat(pos.coords.longitude.toFixed(7)),
                        akurasi: Math.round(pos.coords.accuracy || 999)
                    };
                    locText.innerHTML = `<strong style="color:#10B981;">✓ Lokasi Terekam:</strong> ${izinCoordinates.lat}, ${izinCoordinates.lng} (±${izinCoordinates.akurasi}m)`;
                    btnGetLoc.disabled = false;
                    showToast('ok', 'Lokasi Siap', 'Titik koordinat berhasil terekam.');
                },
                (err) => {
                    locText.innerHTML = `<span style="color:#F43F5E;">✗ Gagal: ${esc(err.message)}</span>`;
                    btnGetLoc.disabled = false;
                    showToast('err', 'GPS Gagal', err.message);
                },
                { enableHighAccuracy: true, timeout: 7000 }
            );
        });
    }

    if (btnSubmitIzin) {
        btnSubmitIzin.addEventListener('click', async () => {
            const mapelSel = document.getElementById('selectMapel').value;
            const jenisSel = document.getElementById('selectJenis').value;
            const alasan   = document.getElementById('textareaAlasan').value.trim();

            if (!mapelSel) { showToast('warn', 'Belum Lengkap', 'Pilih mata pelajaran terlebih dahulu.'); return; }
            if (!alasan)   { showToast('warn', 'Belum Lengkap', 'Tuliskan alasan perizinan Anda.'); return; }
            if (!camPhotoData) { showToast('warn', 'Foto Bukti Wajib', 'Silakan ambil foto bukti surat atau kondisi.'); return; }
            if (!izinCoordinates) { showToast('warn', 'Lokasi Wajib', 'Klik Ambil Lokasi untuk menyertakan titik GPS.'); return; }

            btnSubmitIzin.disabled = true;
            btnSubmitIzin.innerHTML = '<iconify-icon icon="solar:restart-bold" class="spin"></iconify-icon> Mengirimkan…';

            try {
                const fd = new FormData();
                fd.append('action', 'submit_izin');
                fd.append('id_km', mapelSel);
                fd.append('jenis', jenisSel);
                fd.append('alasan', alasan);
                fd.append('foto', camPhotoData);
                fd.append('lat', izinCoordinates.lat);
                fd.append('lng', izinCoordinates.lng);
                fd.append('akurasi', izinCoordinates.akurasi);

                const res = await fetch('absensi.php', { method: 'POST', body: fd }).then(r => r.json());
                if (res && res.success) {
                    showToast('ok', 'Permohonan Terkirim', res.message);
                    closeModal();
                    pollIzinStatus();
                } else {
                    showToast('err', 'Gagal Mengirim', res.message || 'Terjadi kesalahan sistem.');
                }
            } catch(err) {
                showToast('err', 'Koneksi Bermasalah', err.message);
            } finally {
                btnSubmitIzin.disabled = false;
                btnSubmitIzin.innerHTML = '<iconify-icon icon="solar:letter-bold" width="15"></iconify-icon> Kirimkan Permohonan';
            }
        });
    }

    // PERSENTUJUAN KETUA KELAS
    window.prosesKetuaKelas = async function(idIzin, keputusan) {
        const promptText = keputusan === 'approve' 
            ? 'Setujui permohonan izin teman sekelas dan teruskan ke Guru Pengampu?'
            : 'Tolak permohonan izin ini? Berikan catatan alasan penolakan jika perlu.';
        const confirmTitle = keputusan === 'approve' ? 'Setujui Izin' : 'Tolak Izin';
        const confirmType  = keputusan === 'approve' ? 'success' : 'warning';
        const confirmLabel = keputusan === 'approve' ? 'Ya, Setujui' : 'Ya, Tolak';

        const ok = await Ilmuna.confirm(confirmType, confirmTitle, promptText, confirmLabel, 'Batal');
        if (!ok) return;

        let catatan = '';
        if (keputusan === 'reject') {
            catatan = 'Ditolak oleh ketua kelas';
        }

        try {
            const fd = new FormData();
            fd.append('action', 'approval_ketua_kelas');
            fd.append('id_izin', idIzin);
            fd.append('keputusan', keputusan);
            fd.append('catatan', catatan);

            const res = await fetch('absensi.php', { method: 'POST', body: fd }).then(r => r.json());
            if (res && res.success) {
                showToast('ok', 'Selesai Diproses', res.message);
                const card = document.getElementById('classmateCard-' + idIzin);
                if (card) {
                    card.style.transition = 'all .3s';
                    card.style.opacity = '0';
                    card.style.transform = 'scale(0.95)';
                    setTimeout(() => card.remove(), 300);
                }
            } else {
                showToast('err', 'Gagal', res.message || 'Terjadi kesalahan.');
            }
        } catch(e) { showToast('err', 'Error', e.message); }
    };

    window.viewBuktiIzin = function(url, nama) {
        if (!url) { showToast('warn', 'Tidak Ada Bukti', 'Berkas bukti belum dilampirkan.'); return; }
        window.open(url, '_blank');
    };

    // Anti Right-Click and Anti-Save on Protected Images
    document.addEventListener('contextmenu', function(e) {
        if (e.target.closest('.qr-canvas-holder') || e.target.closest('.qr-shield') || e.target.tagName === 'IMG') {
            e.preventDefault();
            return false;
        }
    });
    document.addEventListener('dragstart', function(e) {
        if (e.target.closest('.qr-canvas-holder') || e.target.tagName === 'IMG') {
            e.preventDefault();
            return false;
        }
    });

    pollIzinStatus();
    setInterval(pollIzinStatus, 15000);

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
