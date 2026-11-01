<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BANK & MASTER ASESMEN PEMBELAJARAN (ADMIN & GURU)
 * ==============================================================================
 * Standar Kurikulum Merdeka (Standar 69 Tabel PDP).
 * Mengelola Master Tabel:
 * - asesmen_sumatif   : Uji Ketercapaian Kompetensi Akhir (LM, STS, ASAS)
 * - asesmen_formatif  : Evaluasi Proses & Umpan Balik KBM (Tes, Kuis, Observasi)
 * - asesmen_diagnostik: Pemetaan Awal Kesiapan Belajar (Kognitif, Non-Kognitif)
 * Arsitektur: Pure Reactive SPA via AJAX, Live Search 1 Karakter ala WhatsApp,
 *             Tab In-Page tanpa perubahan URL, Form Tambah/Edit Asesmen Terintegrasi.
 * Tema Warna: Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Gold #F59E0B)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole(['admin', 'guru']);

$pdo = getDBConnection();
$pageTitle = 'Bank & Master Asesmen';
$pageIcon = 'solar:document-text-bold';

if (!function_exists('resolveValidUserIdForGuru')) {
    /**
     * Resolusi ID guru menjadi valid users.id
     * Foreign key fk_pr_guru / fk_as_guru / fk_af_guru / fk_ad_guru mereferensikan users(id).
     * Fungsi ini memastikan nilai yang dimasukkan ke id_guru selalu merupakan users.id yang valid,
     * atau NULL jika tidak ditemukan / tidak terdaftar di users.
     */
    function resolveValidUserIdForGuru($pdo, $candidateGuruId) {
        if (!empty($candidateGuruId)) {
            // 1. Cek apakah candidateGuruId langsung ada di tabel users(id)
            $st = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $st->execute([(int)$candidateGuruId]);
            $uid = $st->fetchColumn();
            if ($uid) {
                return (int)$uid;
            }

            // 2. Cek apakah candidateGuruId adalah guru.id, ambil id_user miliknya
            $st = $pdo->prepare("SELECT id_user FROM guru WHERE id = ? LIMIT 1");
            $st->execute([(int)$candidateGuruId]);
            $guruUserId = $st->fetchColumn();
            if ($guruUserId) {
                $st = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
                $st->execute([(int)$guruUserId]);
                if ($st->fetchColumn()) {
                    return (int)$guruUserId;
                }
            }
        }

        // 3. Fallback ke session user_id jika ada dan valid di users
        if (!empty($_SESSION['user_id'])) {
            $st = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $st->execute([(int)$_SESSION['user_id']]);
            if ($st->fetchColumn()) {
                return (int)$_SESSION['user_id'];
            }
        }

        return null;
    }
}

if (!function_exists('checkRemedialEligibilityForKmp')) {
    /**
     * Memeriksa kelayakan penambahan Program Remedial untuk suatu Rombel Kelas & Mapel (KMP).
     * Aturan paten: Remedial HANYA bisa ditambahkan apabila terdapat siswa di rombel kelas tersebut
     * yang TIDAK LULUS (nilai < KKTP atau status belum tuntas/perlu perbaikan) pada salah satu asesmen:
     * - Asesmen Formatif (nilai_asesmen_formatif: nilai_angka < nilai_kktp ATAU status_tuntas IN ('Belum Tuntas', 'Perlu Penguatan'))
     * - Asesmen Diagnostik (nilai_asesmen_diagnostik: nilai_angka < nilai_kktp ATAU status_tuntas IN ('Perlu Pendampingan Khusus', 'Perlu Penyesuaian Materi'))
     * - Asesmen Sumatif (nilai_asesmen_sumatif: nilai_angka < nilai_kktp ATAU status_tuntas IN ('Belum Tuntas', 'Perlu Remedial'))
     */
    function checkRemedialEligibilityForKmp($pdo, $kmpId, $pertemuan = null) {
        $stK = $pdo->prepare("
            SELECT kmp.id, kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran, COALESCE(mp.kktp, 75.00) AS kktp
            FROM kelas_mata_pelajaran kmp
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            WHERE kmp.id = ?
        ");
        $stK->execute([(int)$kmpId]);
        $kmpInfo = $stK->fetch(PDO::FETCH_ASSOC);
        if (!$kmpInfo) {
            return [
                'eligible'            => false,
                'kmp_id'              => (int)$kmpId,
                'id_kelas'            => 0,
                'nama_kelas'          => 'Rombel Kelas Tidak Ditemukan',
                'nama_mata_pelajaran' => '',
                'kktp'                => 75.00,
                'total_failed'        => 0,
                'sumatif_count'       => 0,
                'formatif_count'      => 0,
                'diagnostik_count'    => 0,
                'message'             => 'Rombel kelas tidak ditemukan.',
                'students'            => []
            ];
        }

        $idKelas = (int)$kmpInfo['id_kelas'];
        $failedStudents = [];

        // 1. Cek Asesmen Sumatif
        $sqlSum = "
            SELECT DISTINCT s.id AS id_siswa, s.nama_lengkap, s.nis, s.nisn,
                   asum.id AS id_asesmen, asum.judul_asesmen, asum.pertemuan_ke, asum.id_modul_ajar, asum.id_cp, asum.id_tp, asum.id_atp, asum.id_itp,
                   nas.nilai_angka, nas.nilai_kktp, nas.status_tuntas, 'Sumatif' AS sumber
            FROM nilai_asesmen_sumatif nas
            JOIN siswa s ON nas.id_siswa = s.id
            JOIN asesmen_sumatif asum ON nas.id_asesmen_sumatif = asum.id
            WHERE (nas.id_kelas_mata_pelajaran = ? OR s.id_kelas = ?)
              AND (nas.nilai_angka < nas.nilai_kktp OR nas.status_tuntas IN ('Belum Tuntas', 'Perlu Remedial'))
        ";
        $paramsSum = [$kmpId, $idKelas];
        if ($pertemuan !== null && (int)$pertemuan > 0) {
            $sqlSum .= " AND asum.pertemuan_ke = ?";
            $paramsSum[] = (int)$pertemuan;
        }
        $stSum = $pdo->prepare($sqlSum);
        $stSum->execute($paramsSum);
        $sumatifFailed = $stSum->fetchAll(PDO::FETCH_ASSOC);

        // 2. Cek Asesmen Formatif
        $sqlFmt = "
            SELECT DISTINCT s.id AS id_siswa, s.nama_lengkap, s.nis, s.nisn,
                   af.id AS id_asesmen, af.judul_asesmen, af.pertemuan_ke, af.id_modul_ajar, af.id_cp, af.id_tp, af.id_atp, af.id_itp,
                   naf.nilai_angka, naf.nilai_kktp, naf.status_tuntas, 'Formatif' AS sumber
            FROM nilai_asesmen_formatif naf
            JOIN siswa s ON naf.id_siswa = s.id
            JOIN asesmen_formatif af ON naf.id_asesmen_formatif = af.id
            WHERE (naf.id_kelas_mata_pelajaran = ? OR s.id_kelas = ?)
              AND (naf.nilai_angka < naf.nilai_kktp OR naf.status_tuntas IN ('Belum Tuntas', 'Perlu Penguatan'))
        ";
        $paramsFmt = [$kmpId, $idKelas];
        if ($pertemuan !== null && (int)$pertemuan > 0) {
            $sqlFmt .= " AND af.pertemuan_ke = ?";
            $paramsFmt[] = (int)$pertemuan;
        }
        $stFmt = $pdo->prepare($sqlFmt);
        $stFmt->execute($paramsFmt);
        $formatifFailed = $stFmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Cek Asesmen Diagnostik
        $sqlDiag = "
            SELECT DISTINCT s.id AS id_siswa, s.nama_lengkap, s.nis, s.nisn,
                   ad.id AS id_asesmen, ad.judul_asesmen, ad.pertemuan_ke, ad.id_modul_ajar, ad.id_cp, ad.id_tp, ad.id_atp, ad.id_itp,
                   nad.nilai_angka, nad.nilai_kktp, nad.status_tuntas, 'Diagnostik' AS sumber
            FROM nilai_asesmen_diagnostik nad
            JOIN siswa s ON nad.id_siswa = s.id
            JOIN asesmen_diagnostik ad ON nad.id_asesmen_diagnostik = ad.id
            WHERE (nad.id_kelas_mata_pelajaran = ? OR s.id_kelas = ?)
              AND (nad.nilai_angka < nad.nilai_kktp OR nad.status_tuntas IN ('Perlu Pendampingan Khusus', 'Perlu Penyesuaian Materi'))
        ";
        $paramsDiag = [$kmpId, $idKelas];
        if ($pertemuan !== null && (int)$pertemuan > 0) {
            $sqlDiag .= " AND ad.pertemuan_ke = ?";
            $paramsDiag[] = (int)$pertemuan;
        }
        $stDiag = $pdo->prepare($sqlDiag);
        $stDiag->execute($paramsDiag);
        $diagnostikFailed = $stDiag->fetchAll(PDO::FETCH_ASSOC);

        // Agregasikan siswa tidak lulus secara unik & kelompokkan per asesmen
        $failedAssessments = [];
        foreach (array_merge($sumatifFailed, $formatifFailed, $diagnostikFailed) as $row) {
            $sid = (int)$row['id_siswa'];
            if (!isset($failedStudents[$sid])) {
                $failedStudents[$sid] = [
                    'id_siswa'     => $sid,
                    'nama_lengkap' => $row['nama_lengkap'],
                    'nis'          => $row['nis'] ?? '',
                    'nisn'         => $row['nisn'] ?? '',
                    'details'      => []
                ];
            }
            $failedStudents[$sid]['details'][] = [
                'sumber'        => $row['sumber'],
                'id_asesmen'    => (int)($row['id_asesmen'] ?? 0),
                'judul_asesmen' => $row['judul_asesmen'],
                'nilai_angka'   => (float)$row['nilai_angka'],
                'nilai_kktp'    => (float)$row['nilai_kktp'],
                'status_tuntas' => $row['status_tuntas']
            ];

            // Kelompokkan asesmen yang perlu diremidi
            $assessId = (int)($row['id_asesmen'] ?? 0);
            $key = $row['sumber'] . '_' . ($assessId > 0 ? $assessId : md5($row['judul_asesmen']));
            if (!isset($failedAssessments[$key])) {
                $failedAssessments[$key] = [
                    'key'           => $key,
                    'sumber'        => $row['sumber'],
                    'id_asesmen'    => $assessId,
                    'judul_asesmen' => $row['judul_asesmen'],
                    'pertemuan_ke'  => (int)($row['pertemuan_ke'] ?? 1),
                    'id_modul_ajar' => !empty($row['id_modul_ajar']) ? (int)$row['id_modul_ajar'] : null,
                    'id_cp'         => !empty($row['id_cp']) ? (int)$row['id_cp'] : null,
                    'id_tp'         => !empty($row['id_tp']) ? (int)$row['id_tp'] : null,
                    'id_atp'        => !empty($row['id_atp']) ? (int)$row['id_atp'] : null,
                    'id_itp'        => !empty($row['id_itp']) ? (int)$row['id_itp'] : null,
                    'kktp'          => (float)($row['nilai_kktp'] ?? $kmpInfo['kktp']),
                    'failed_count'  => 0,
                    'students'      => []
                ];
            }
            $failedAssessments[$key]['failed_count']++;
            $failedAssessments[$key]['students'][] = [
                'id_siswa'      => $sid,
                'nama_lengkap'  => $row['nama_lengkap'],
                'nis'           => $row['nis'] ?? '',
                'nisn'          => $row['nisn'] ?? '',
                'nilai_angka'   => (float)$row['nilai_angka'],
                'nilai_kktp'    => (float)$row['nilai_kktp'],
                'status_tuntas' => $row['status_tuntas']
            ];
        }

        $totalUnique = count($failedStudents);
        $isEligible = ($totalUnique > 0);

        return [
            'eligible'            => $isEligible,
            'kmp_id'              => (int)$kmpId,
            'id_kelas'            => $idKelas,
            'nama_kelas'          => $kmpInfo['nama_kelas'],
            'nama_mata_pelajaran' => $kmpInfo['nama_mata_pelajaran'],
            'kktp'                => (float)$kmpInfo['kktp'],
            'total_failed'        => $totalUnique,
            'sumatif_count'       => count($sumatifFailed),
            'formatif_count'      => count($formatifFailed),
            'diagnostik_count'    => count($diagnostikFailed),
            'assessments'         => array_values($failedAssessments),
            'students'            => array_values($failedStudents)
        ];
    }
}



// Delegasi Action Excel (Download Template & Import Excel) ke assessments-excel.php
if (isset($_GET['action']) || isset($_POST['action'])) {
    $reqAction = $_GET['action'] ?? $_POST['action'] ?? '';
    if (in_array($reqAction, ['download_template', 'import_excel'], true)) {
        require __DIR__ . '/assessments-excel.php';
        exit;
    }
}

/* ==============================================================================
 * 1. BACKEND AJAX API HANDLERS (JSON ONLY)
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'];

    // 1.1 FETCH LIST ASESMEN (REALTIME & LAZY LOAD)
    if ($act === 'list') {
        $kategori  = $_POST['kategori'] ?? 'all';
        $status    = $_POST['status'] ?? 'all';
        $idKelas   = (int)($_POST['kelas_id'] ?? 0);
        $search    = trim($_POST['search'] ?? '');
        $page      = max(1, (int)($_POST['page'] ?? 1));
        $perPage   = max(5, min(100, (int)($_POST['per_page'] ?? 15)));
        $offset    = ($page - 1) * $perPage;

        $sqlSumatif = "
            SELECT 
                'sumatif' AS kategori,
                asum.id,
                asum.judul_asesmen,
                asum.jenis_sumatif AS tipe_khusus,
                COALESCE(
                    mp.kktp,
                    (SELECT mp2.kktp 
                     FROM asesmen_sumatif_kelas ask3 
                     JOIN kelas_mata_pelajaran kmp3 ON ask3.id_kelas_mata_pelajaran = kmp3.id 
                     JOIN mata_pelajaran mp2 ON kmp3.id_mata_pelajaran = mp2.id 
                     WHERE ask3.id_asesmen_sumatif = asum.id LIMIT 1),
                    asum.kktp,
                    75.00
                ) AS kktp,
                asum.durasi_menit,
                asum.tanggal_pelaksanaan,
                asum.jam_mulai,
                asum.jam_selesai,
                asum.pertemuan_ke,
                asum.status,
                asum.petunjuk_pengerjaan,
                '' AS tujuan_asesmen,
                asum.id_modul_ajar,
                asum.id_cp,
                asum.id_tp,
                asum.id_atp,
                asum.id_itp,
                COALESCE(ma.judul_modul, '') AS judul_modul,
                COALESCE(tp.kode_tp, '') AS kode_tp,
                asum.created_at,
                COALESCE(asum.id_kelas_mata_pelajaran, (SELECT ask4.id_kelas_mata_pelajaran FROM asesmen_sumatif_kelas ask4 WHERE ask4.id_asesmen_sumatif = asum.id LIMIT 1), 0) AS id_kelas_mata_pelajaran,
                k.id AS id_kelas,
                COALESCE(
                    NULLIF((SELECT GROUP_CONCAT(DISTINCT k2.nama_kelas ORDER BY k2.nama_kelas SEPARATOR ', ') 
                     FROM asesmen_sumatif_kelas ask2 
                     JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id 
                     JOIN kelas k2 ON kmp2.id_kelas = k2.id 
                     WHERE ask2.id_asesmen_sumatif = asum.id), ''),
                    k.nama_kelas, 
                    'Semua Rombel Terpilih'
                ) AS nama_kelas,
                COALESCE(mp.nama_mata_pelajaran, (
                    SELECT mp2.nama_mata_pelajaran 
                    FROM asesmen_sumatif_kelas ask3 
                    JOIN kelas_mata_pelajaran kmp3 ON ask3.id_kelas_mata_pelajaran = kmp3.id 
                    JOIN mata_pelajaran mp2 ON kmp3.id_mata_pelajaran = mp2.id 
                    WHERE ask3.id_asesmen_sumatif = asum.id LIMIT 1
                ), '') AS nama_mata_pelajaran,
                COALESCE(g.nama_guru, 'Guru PAI') AS nama_guru,
                (SELECT COUNT(*) FROM asesmen_sumatif_soal ass WHERE ass.id_asesmen_sumatif = asum.id) AS total_soal,
                (SELECT COUNT(*) FROM akses_asesmen_sumatif aas WHERE aas.id_asesmen_sumatif = asum.id) AS total_tiket
            FROM asesmen_sumatif asum
            LEFT JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN kelas k ON kmp.id_kelas = k.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru g ON (asum.id_guru = g.id_user OR asum.id_guru = g.id)
            LEFT JOIN modul_ajar ma ON asum.id_modul_ajar = ma.id
            LEFT JOIN tp ON asum.id_tp = tp.id
        ";

        $sqlFormatif = "
            SELECT 
                'formatif' AS kategori,
                af.id,
                af.judul_asesmen,
                af.teknik_asesmen AS tipe_khusus,
                COALESCE(mp.kktp, 75.00) AS kktp,
                af.durasi_menit,
                af.tanggal_pelaksanaan,
                af.jam_mulai,
                af.jam_selesai,
                af.pertemuan_ke,
                af.status,
                af.petunjuk_pengerjaan,
                af.tujuan_asesmen,
                af.id_modul_ajar,
                af.id_cp,
                af.id_tp,
                af.id_atp,
                af.id_itp,
                COALESCE(ma.judul_modul, '') AS judul_modul,
                COALESCE(tp.kode_tp, '') AS kode_tp,
                af.created_at,
                kmp.id AS id_kelas_mata_pelajaran,
                k.id AS id_kelas,
                k.nama_kelas,
                mp.nama_mata_pelajaran,
                COALESCE(g.nama_guru, 'Guru PAI') AS nama_guru,
                (SELECT COUNT(*) FROM asesmen_formatif_soal afs WHERE afs.id_asesmen_formatif = af.id) AS total_soal,
                (SELECT COUNT(*) FROM akses_asesmen_formatif aaf WHERE aaf.id_asesmen_formatif = af.id) AS total_tiket
            FROM asesmen_formatif af
            LEFT JOIN kelas_mata_pelajaran kmp ON af.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN kelas k ON kmp.id_kelas = k.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru g ON (af.id_guru = g.id_user OR af.id_guru = g.id)
            LEFT JOIN modul_ajar ma ON af.id_modul_ajar = ma.id
            LEFT JOIN tp ON af.id_tp = tp.id
        ";

        $sqlDiagnostik = "
            SELECT 
                'diagnostik' AS kategori,
                ad.id,
                ad.judul_asesmen,
                ad.jenis_diagnostik AS tipe_khusus,
                COALESCE(mp.kktp, 75.00) AS kktp,
                ad.durasi_menit,
                ad.tanggal_pelaksanaan,
                ad.jam_mulai,
                ad.jam_selesai,
                ad.pertemuan_ke,
                ad.status,
                ad.petunjuk_pengerjaan,
                ad.tujuan_asesmen,
                ad.id_modul_ajar,
                ad.id_cp,
                ad.id_tp,
                ad.id_atp,
                ad.id_itp,
                COALESCE(ma.judul_modul, '') AS judul_modul,
                COALESCE(tp.kode_tp, '') AS kode_tp,
                ad.created_at,
                kmp.id AS id_kelas_mata_pelajaran,
                k.id AS id_kelas,
                k.nama_kelas,
                mp.nama_mata_pelajaran,
                COALESCE(g.nama_guru, 'Guru PAI') AS nama_guru,
                (SELECT COUNT(*) FROM asesmen_diagnostik_soal ads WHERE ads.id_asesmen_diagnostik = ad.id) AS total_soal,
                0 AS total_tiket
            FROM asesmen_diagnostik ad
            LEFT JOIN kelas_mata_pelajaran kmp ON ad.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN kelas k ON kmp.id_kelas = k.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru g ON (ad.id_guru = g.id_user OR ad.id_guru = g.id)
            LEFT JOIN modul_ajar ma ON ad.id_modul_ajar = ma.id
            LEFT JOIN tp ON ad.id_tp = tp.id
        ";

        $sqlRemedial = "
            SELECT 
                'remedial' AS kategori,
                pr.id,
                pr.judul_program AS judul_asesmen,
                pr.kategori AS tipe_khusus,
                COALESCE(mp.kktp, pr.kktp_minimal, 75.00) AS kktp,
                pr.durasi_menit,
                pr.tanggal_pelaksanaan,
                pr.jam_mulai,
                pr.jam_selesai,
                pr.pertemuan_ke,
                pr.status,
                pr.petunjuk_pelaksanaan AS petunjuk_pengerjaan,
                '' AS tujuan_asesmen,
                pr.id_modul_ajar,
                pr.id_cp,
                pr.id_tp,
                pr.id_atp,
                pr.id_itp,
                COALESCE(ma.judul_modul, '') AS judul_modul,
                COALESCE(tp.kode_tp, '') AS kode_tp,
                pr.created_at,
                kmp.id AS id_kelas_mata_pelajaran,
                k.id AS id_kelas,
                k.nama_kelas,
                mp.nama_mata_pelajaran,
                COALESCE(g.nama_guru, 'Guru PAI') AS nama_guru,
                (SELECT COUNT(*) FROM pengayaan_remedial_soal prs WHERE prs.id_pengayaan_remedial = pr.id) AS total_soal,
                (SELECT COUNT(*) FROM akses_pengayaan_remedial apr WHERE apr.id_pengayaan_remedial = pr.id) AS total_tiket
            FROM pengayaan_remedial pr
            LEFT JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN kelas k ON kmp.id_kelas = k.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru g ON (pr.id_guru = g.id_user OR pr.id_guru = g.id)
            LEFT JOIN modul_ajar ma ON pr.id_modul_ajar = ma.id
            LEFT JOIN tp ON pr.id_tp = tp.id
        ";

        if ($kategori === 'sumatif') {
            $baseSql = "($sqlSumatif)";
        } elseif ($kategori === 'formatif') {
            $baseSql = "($sqlFormatif)";
        } elseif ($kategori === 'diagnostik') {
            $baseSql = "($sqlDiagnostik)";
        } elseif ($kategori === 'remedial') {
            $baseSql = "($sqlRemedial)";
        } else {
            $baseSql = "(($sqlSumatif) UNION ALL ($sqlFormatif) UNION ALL ($sqlDiagnostik) UNION ALL ($sqlRemedial))";
        }

        $where = " WHERE 1=1 ";
        $params = [];

        if ($status !== 'all' && $status !== '') {
            $where .= " AND t.status = ? ";
            $params[] = $status;
        }

        if ($idKelas > 0) {
            $where .= " AND (t.id_kelas = ? OR (t.kategori = 'sumatif' AND EXISTS (SELECT 1 FROM asesmen_sumatif_kelas ask_f JOIN kelas_mata_pelajaran kmp_f ON ask_f.id_kelas_mata_pelajaran = kmp_f.id WHERE ask_f.id_asesmen_sumatif = t.id AND kmp_f.id_kelas = ?))) ";
            $params[] = $idKelas;
            $params[] = $idKelas;
        }

        if ($search !== '') {
            $where .= " AND (
                t.judul_asesmen LIKE ? OR 
                t.nama_mata_pelajaran LIKE ? OR 
                t.nama_kelas LIKE ? OR 
                t.nama_guru LIKE ? OR 
                t.tipe_khusus LIKE ?
            ) ";
            $kw = "%{$search}%";
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }

        $countSql = "SELECT COUNT(*) FROM {$baseSql} AS t {$where}";
        $stmtCount = $pdo->prepare($countSql);
        $stmtCount->execute($params);
        $totalRows = (int)$stmtCount->fetchColumn();

        $fetchSql = "SELECT t.* FROM {$baseSql} AS t {$where} ORDER BY t.id DESC LIMIT {$perPage} OFFSET {$offset}";
        $stmtFetch = $pdo->prepare($fetchSql);
        $stmtFetch->execute($params);
        $rows = $stmtFetch->fetchAll(PDO::FETCH_ASSOC);

        $statSumatif = (int)$pdo->query("SELECT COUNT(*) FROM asesmen_sumatif")->fetchColumn();
        $statFormatif = (int)$pdo->query("SELECT COUNT(*) FROM asesmen_formatif")->fetchColumn();
        $statDiagnostik = (int)$pdo->query("SELECT COUNT(*) FROM asesmen_diagnostik")->fetchColumn();
        $statRemedial = (int)$pdo->query("SELECT COUNT(*) FROM pengayaan_remedial")->fetchColumn();
        $statAktif = (int)$pdo->query("
            SELECT (
                (SELECT COUNT(*) FROM asesmen_sumatif WHERE status = 'Aktif') +
                (SELECT COUNT(*) FROM asesmen_formatif WHERE status = 'Aktif') +
                (SELECT COUNT(*) FROM asesmen_diagnostik WHERE status = 'Aktif') +
                (SELECT COUNT(*) FROM pengayaan_remedial WHERE status = 'Aktif')
            )
        ")->fetchColumn();

        echo json_encode([
            'success'  => true,
            'data'     => [
                'rows'     => $rows,
                'total'    => $totalRows,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => ceil($totalRows / $perPage),
                'stats'    => [
                    'total_all'        => ($statSumatif + $statFormatif + $statDiagnostik + $statRemedial),
                    'total_sumatif'    => $statSumatif,
                    'total_formatif'   => $statFormatif,
                    'total_diagnostik' => $statDiagnostik,
                    'total_remedial'   => $statRemedial,
                    'total_aktif'      => $statAktif
                ]
            ]
        ]);
        exit;
    }

    // 1.1b CEK KELAYAKAN REMEDIAL BERDASARKAN KELULUSAN ASESMEN FORMATIF / DIAGNOSTIK / SUMATIF
    if ($act === 'check_remedial_eligibility') {
        $kmpIds = [];
        if (!empty($_POST['kmp_ids'])) {
            if (is_array($_POST['kmp_ids'])) {
                $kmpIds = array_map('intval', $_POST['kmp_ids']);
            } else {
                $parts = explode(',', (string)$_POST['kmp_ids']);
                foreach ($parts as $p) {
                    $val = (int)trim($p);
                    if ($val > 0) $kmpIds[] = $val;
                }
            }
        }
        $kmpIds = array_values(array_unique(array_filter($kmpIds)));
        $pertemuan = !empty($_POST['pertemuan_ke']) ? (int)$_POST['pertemuan_ke'] : null;

        if (empty($kmpIds)) {
            echo json_encode([
                'success'            => true,
                'eligible'           => false,
                'all_eligible'       => false,
                'total_failed'       => 0,
                'message'            => 'Belum ada rombel kelas yang dipilih.',
                'classes'            => [],
                'ineligible_classes' => []
            ]);
            exit;
        }

        $classes = [];
        $ineligibleClasses = [];
        $totalFailedAll = 0;
        $allEligible = true;
        $allAssessments = [];

        foreach ($kmpIds as $cKmp) {
            $res = checkRemedialEligibilityForKmp($pdo, $cKmp, $pertemuan);
            $classes[] = $res;
            $totalFailedAll += $res['total_failed'];
            if (!$res['eligible']) {
                $allEligible = false;
                $ineligibleClasses[] = $res['nama_kelas'];
            }
            if (!empty($res['assessments'])) {
                foreach ($res['assessments'] as $asItem) {
                    $allAssessments[] = $asItem;
                }
            }
        }

        echo json_encode([
            'success'            => true,
            'eligible'           => $allEligible,
            'all_eligible'       => $allEligible,
            'total_failed'       => $totalFailedAll,
            'classes'            => $classes,
            'assessments'        => $allAssessments,
            'ineligible_classes' => $ineligibleClasses
        ]);
        exit;
    }

    // 1.2 SIMPAN ASESMEN BARU
    if ($act === 'save_asesmen') {
        $kategori   = trim($_POST['kategori'] ?? 'sumatif');
        $judul      = trim($_POST['judul_asesmen'] ?? '');
        $idGuru     = !empty($_POST['id_guru']) ? (int)$_POST['id_guru'] : null;
        $idModulAjar= !empty($_POST['id_modul_ajar']) ? (int)$_POST['id_modul_ajar'] : null;
        $idCp       = !empty($_POST['id_cp']) ? (int)$_POST['id_cp'] : null;
        $idTp       = !empty($_POST['id_tp']) ? (int)$_POST['id_tp'] : null;
        $idAtp      = !empty($_POST['id_atp']) ? (int)$_POST['id_atp'] : null;
        $idItp      = !empty($_POST['id_itp']) ? (int)$_POST['id_itp'] : null;
        $pertemuan  = max(1, (int)($_POST['pertemuan_ke'] ?? 1));
        $status     = 'Draft'; // Pembuatan asesmen selalu dimulai dari status Draft
        $petunjuk   = trim($_POST['petunjuk_pengerjaan'] ?? '');

        $kmpIds = [];
        if (!empty($_POST['id_kelas_mata_pelajaran'])) {
            if (is_array($_POST['id_kelas_mata_pelajaran'])) {
                $kmpIds = array_map('intval', $_POST['id_kelas_mata_pelajaran']);
            } else {
                $parts = explode(',', (string)$_POST['id_kelas_mata_pelajaran']);
                foreach ($parts as $p) {
                    $val = (int)trim($p);
                    if ($val > 0) $kmpIds[] = $val;
                }
            }
        }
        $kmpIds = array_values(array_unique(array_filter($kmpIds)));

        if ($idModulAjar && (!$idCp || !$idTp || empty($kmpIds))) {
            $maQ = $pdo->prepare("SELECT id_cp, id_tp, id_atp, id_itp, pertemuan_ke, id_kelas_mata_pelajaran, id_guru FROM modul_ajar WHERE id = ?");
            $maQ->execute([$idModulAjar]);
            $maData = $maQ->fetch(PDO::FETCH_ASSOC);
            if ($maData) {
                if (!$idCp) $idCp = $maData['id_cp'] ? (int)$maData['id_cp'] : null;
                if (!$idTp) $idTp = $maData['id_tp'] ? (int)$maData['id_tp'] : null;
                if (!$idAtp) $idAtp = $maData['id_atp'] ? (int)$maData['id_atp'] : null;
                if (!$idItp) $idItp = $maData['id_itp'] ? (int)$maData['id_itp'] : null;
                if (empty($_POST['pertemuan_ke']) || (int)$_POST['pertemuan_ke'] === 1) $pertemuan = (int)$maData['pertemuan_ke'];
                if (empty($kmpIds) && !empty($maData['id_kelas_mata_pelajaran'])) {
                    $kmpIds[] = (int)$maData['id_kelas_mata_pelajaran'];
                }
                if (!$idGuru && $maData['id_guru']) $idGuru = (int)$maData['id_guru'];
            }
        }

        $tanggalPelaksanaan = trim($_POST['tanggal_pelaksanaan'] ?? '');
        $jamMulai           = trim($_POST['jam_mulai'] ?? '');
        $durasiFinal        = max(5, (int)($_POST['durasi_menit'] ?? 60));

        if (empty($judul)) {
            echo json_encode(['success' => false, 'message' => 'Judul asesmen wajib diisi.']);
            exit;
        }
        if ($durasiFinal < 5) {
            echo json_encode(['success' => false, 'message' => 'Durasi pengerjaan ujian wajib diisi minimal 5 menit.']);
            exit;
        }
        if (empty($tanggalPelaksanaan) || empty($jamMulai)) {
            echo json_encode(['success' => false, 'message' => 'Jadwal pelaksanaan wajib diisi: Tanggal pelaksanaan dan Jam Mulai harus ditentukan terlebih dahulu.']);
            exit;
        }
        if (strlen($jamMulai) === 5) $jamMulai .= ':00';

        $jamSelesai = trim($_POST['jam_selesai'] ?? '');
        if (empty($jamSelesai) || $jamSelesai <= $jamMulai) {
            $jamSelesai = date('H:i:s', strtotime($jamMulai) + ($durasiFinal * 60));
        } elseif (strlen($jamSelesai) === 5) {
            $jamSelesai .= ':00';
        }

        if (empty($kmpIds)) {
            echo json_encode(['success' => false, 'message' => 'Silakan pilih minimal satu Rombel Kelas & Mata Pelajaran.']);
            exit;
        }

        // VALIDASI KELAYAKAN REMEDIAL: HANYA BOLEH DITAMBAHKAN JIKA ADA SISWA YANG TIDAK LULUS
        if ($kategori === 'remedial') {
            $jenisPr = in_array($_POST['jenis_remedial'] ?? '', ['Remedial', 'Pengayaan']) ? $_POST['jenis_remedial'] : 'Remedial';
            if ($jenisPr === 'Remedial') {
                $ineligibleClasses = [];
                foreach ($kmpIds as $cKmp) {
                    $chk = checkRemedialEligibilityForKmp($pdo, $cKmp, $pertemuan);
                    if (!$chk['eligible']) {
                        $ineligibleClasses[] = $chk['nama_kelas'];
                    }
                }
                if (!empty($ineligibleClasses)) {
                    $classListStr = implode(', ', $ineligibleClasses);
                    echo json_encode([
                        'success' => false,
                        'message' => "Program Remedial tidak dapat ditambahkan untuk kelas: {$classListStr}. Syarat remedial: wajib terdapat siswa yang TIDAK LULUS (nilai di bawah KKTP) pada asesmen formatif, diagnostik, maupun sumatif di kelas tersebut."
                    ]);
                    exit;
                }
            }
        }

        try {
            $insertedCount = 0;
            $firstId = 0;

            if ($kategori === 'sumatif') {
                $jenisSumatif = $_POST['jenis_sumatif'] ?? 'Sumatif Lingkup Materi';
                $validJenis = ['Sumatif Lingkup Materi', 'Sumatif Tengah Semester', 'Sumatif Akhir Semester'];
                if (!in_array($jenisSumatif, $validJenis)) {
                    $jenisSumatif = 'Sumatif Lingkup Materi';
                }

                // Untuk STS dan SAS: Ujian semester terpadu menguji semua materi, tidak terikat modul ajar tertentu
                if ($jenisSumatif === 'Sumatif Tengah Semester' || $jenisSumatif === 'Sumatif Akhir Semester') {
                    $idModulAjar = null;
                    $idCp = null;
                    $idTp = null;
                    $idAtp = null;
                    $idItp = null;
                }

                $primaryKmp = $kmpIds[0] ?? null;
                $kktp = 75.00;

                $semesterVal = 'Ganjil';
                $tahunPelVal = '2026/2027';
                if ($primaryKmp) {
                    $kmpInfoStmt = $pdo->prepare("
                        SELECT gmp.id_guru AS gmp_id_guru, g.id_user AS guru_id_user, COALESCE(mp.kktp, 75.00) AS kktp, COALESCE(mp.semester, 'Ganjil') AS semester, COALESCE(mp.tahun_pelajaran, k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran
                        FROM kelas_mata_pelajaran kmp
                        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                        LEFT JOIN guru g ON gmp.id_guru = g.id
                        LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                        LEFT JOIN kelas k ON kmp.id_kelas = k.id
                        WHERE kmp.id = ?
                    ");
                    $kmpInfoStmt->execute([$primaryKmp]);
                    $kmpInfoRow = $kmpInfoStmt->fetch(PDO::FETCH_ASSOC);
                    if ($kmpInfoRow) {
                        $targetGuruCand = !empty($kmpInfoRow['guru_id_user']) ? $kmpInfoRow['guru_id_user'] : (!empty($kmpInfoRow['gmp_id_guru']) ? $kmpInfoRow['gmp_id_guru'] : $idGuru);
                        $idGuru = resolveValidUserIdForGuru($pdo, $targetGuruCand);
                        if (!empty($kmpInfoRow['semester'])) $semesterVal = $kmpInfoRow['semester'];
                        if (!empty($kmpInfoRow['tahun_pelajaran'])) $tahunPelVal = $kmpInfoRow['tahun_pelajaran'];
                        $kktp = (float)($kmpInfoRow['kktp'] ?? 75.00);
                    }
                }
                $idGuru = resolveValidUserIdForGuru($pdo, $idGuru);

                $stmt = $pdo->prepare("
                    INSERT INTO asesmen_sumatif (
                        id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_cp, id_tp, id_atp, id_itp,
                        pertemuan_ke, judul_asesmen, jenis_sumatif, semester, tahun_pelajaran, kktp, durasi_menit, tanggal_pelaksanaan, jam_mulai, jam_selesai, petunjuk_pengerjaan, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $primaryKmp, $idGuru, $idModulAjar, $idCp, $idTp, $idAtp, $idItp,
                    $pertemuan, $judul, $jenisSumatif, $semesterVal, $tahunPelVal, $kktp, $durasiFinal, $tanggalPelaksanaan, $jamMulai, $jamSelesai, $petunjuk, $status
                ]);
                $firstId = (int)$pdo->lastInsertId();
                $insertedCount = 1;

                $askStmt = $pdo->prepare("
                    INSERT INTO asesmen_sumatif_kelas (id_asesmen_sumatif, id_kelas_mata_pelajaran)
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE id_kelas_mata_pelajaran = VALUES(id_kelas_mata_pelajaran)
                ");
                foreach ($kmpIds as $cKmp) {
                    $askStmt->execute([$firstId, $cKmp]);
                }

            } else {
                foreach ($kmpIds as $currKmpId) {
                    $kmpInfoStmt = $pdo->prepare("
                        SELECT gmp.id_guru AS gmp_id_guru, g.id_user AS guru_id_user, COALESCE(mp.kktp, 75.00) AS kktp
                        FROM kelas_mata_pelajaran kmp
                        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                        LEFT JOIN guru g ON gmp.id_guru = g.id
                        LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                        WHERE kmp.id = ?
                    ");
                    $kmpInfoStmt->execute([$currKmpId]);
                    $kmpInfoRow = $kmpInfoStmt->fetch(PDO::FETCH_ASSOC);
                    $candidateGuru = null;
                    if ($kmpInfoRow) {
                        if (!empty($kmpInfoRow['guru_id_user'])) {
                            $candidateGuru = $kmpInfoRow['guru_id_user'];
                        } elseif (!empty($kmpInfoRow['gmp_id_guru'])) {
                            $candidateGuru = $kmpInfoRow['gmp_id_guru'];
                        }
                    }
                    if (!$candidateGuru) {
                        $candidateGuru = $idGuru;
                    }
                    $currGuruId = resolveValidUserIdForGuru($pdo, $candidateGuru);
                    $mapelKktpVal = (float)($kmpInfoRow['kktp'] ?? 75.00);

                    if ($kategori === 'formatif') {
                        $teknik = $_POST['teknik_asesmen'] ?? 'Tes Tertulis';
                        $validTeknik = ['Tes Tertulis', 'Kuis Lisan', 'Observasi Kinerja', 'Penilaian Diri', 'Penilaian Antarteman'];
                        if (!in_array($teknik, $validTeknik)) $teknik = 'Tes Tertulis';
                        $tujuan = trim($_POST['tujuan_asesmen'] ?? 'Mengevaluasi proses pemahaman materi pembelajaran.');

                        $stmt = $pdo->prepare("
                            INSERT INTO asesmen_formatif (
                                id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_cp, id_tp, id_atp, id_itp,
                                pertemuan_ke, judul_asesmen, teknik_asesmen, tujuan_asesmen, durasi_menit, tanggal_pelaksanaan, jam_mulai, jam_selesai, petunjuk_pengerjaan, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $currKmpId, $currGuruId, $idModulAjar, $idCp, $idTp, $idAtp, $idItp,
                            $pertemuan, $judul, $teknik, $tujuan, $durasiFinal, $tanggalPelaksanaan, $jamMulai, $jamSelesai, $petunjuk, $status
                        ]);
                    } elseif ($kategori === 'remedial') {
                        $jenisPr = in_array($_POST['jenis_remedial'] ?? '', ['Remedial', 'Pengayaan']) ? $_POST['jenis_remedial'] : 'Remedial';
                        $bentuk = in_array($_POST['bentuk_kegiatan'] ?? '', ['Bimbingan Perorangan', 'Tutor Sebaya', 'Penugasan Khusus Terbimbing', 'Pembelajaran Ulang Materi Tertentu', 'Proyek Mini Pendalaman']) ? $_POST['bentuk_kegiatan'] : 'Bimbingan Perorangan';
                        $kktpPr = $mapelKktpVal;
                        $statusPr = ($status === 'Ditutup') ? 'Ditutup' : $status;
                        $petunjukPr = ($petunjuk !== '') ? $petunjuk : 'Kerjakan soal tindak lanjut dengan teliti.';

                        $stmt = $pdo->prepare("
                            INSERT INTO pengayaan_remedial (
                                id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_cp, id_tp, id_atp, id_itp,
                                pertemuan_ke, kategori, judul_program, kktp_minimal, bentuk_kegiatan, petunjuk_pelaksanaan,
                                durasi_menit, tanggal_pelaksanaan, jam_mulai, jam_selesai, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $currKmpId, $currGuruId, $idModulAjar, $idCp, $idTp, $idAtp, $idItp,
                            $pertemuan, $jenisPr, $judul, $kktpPr, $bentuk, $petunjukPr,
                            $durasiFinal, $tanggalPelaksanaan, $jamMulai, $jamSelesai, $statusPr
                        ]);
                    } elseif ($kategori === 'diagnostik') {
                        $jenisDiag = in_array($_POST['jenis_diagnostik'] ?? '', ['Kognitif', 'Non-Kognitif']) ? $_POST['jenis_diagnostik'] : 'Kognitif';
                        $tujuan = trim($_POST['tujuan_asesmen'] ?? 'Pemetaan kesiapan awal peserta didik.');

                        $stmt = $pdo->prepare("
                            INSERT INTO asesmen_diagnostik (
                                id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_cp, id_tp, id_atp, id_itp,
                                pertemuan_ke, judul_asesmen, jenis_diagnostik, tujuan_asesmen, durasi_menit, tanggal_pelaksanaan, jam_mulai, jam_selesai, petunjuk_pengerjaan, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $currKmpId, $currGuruId, $idModulAjar, $idCp, $idTp, $idAtp, $idItp,
                            $pertemuan, $judul, $jenisDiag, $tujuan, $durasiFinal, $tanggalPelaksanaan, $jamMulai, $jamSelesai, $petunjuk, $status
                        ]);
                    }

                    $lastId = (int)$pdo->lastInsertId();
                    if (!$firstId) $firstId = $lastId;
                    $insertedCount++;
                }
            }

            $successMsg = ($kategori === 'sumatif' && count($kmpIds) > 1) 
                ? "Paket asesmen sumatif '{$judul}' berhasil dibuat untuk " . count($kmpIds) . " rombel kelas!"
                : (($insertedCount > 1) 
                    ? "Berhasil membuat {$insertedCount} paket asesmen untuk rombel kelas terpilih!"
                    : "Paket asesmen '{$judul}' berhasil ditambahkan!");

            echo json_encode([
                'success'  => true,
                'message'  => $successMsg,
                'id'       => $firstId,
                'count'    => $insertedCount,
                'kategori' => $kategori
            ]);
            exit;

        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan asesmen: ' . $e->getMessage()]);
            exit;
        }
    }

    // 1.3 GET DETAIL ASESMEN UNTUK FORM EDIT
    if ($act === 'get_detail') {
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');

        $kategori = trim($_POST['kategori'] ?? 'sumatif');
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID Asesmen tidak valid.']);
            exit;
        }

        try {
            if ($kategori === 'sumatif') {
                $stmt = $pdo->prepare("
                    SELECT a.*, 
                           COALESCE(
                               mp.kktp,
                               (SELECT mp2.kktp 
                                FROM asesmen_sumatif_kelas ask2
                                JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id
                                JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id
                                WHERE ask2.id_asesmen_sumatif = a.id LIMIT 1),
                               a.kktp,
                               75.00
                           ) AS kktp,
                           COALESCE(g.nama_guru, '') AS nama_guru,
                           COALESCE(
                               (SELECT GROUP_CONCAT(DISTINCT k2.nama_kelas ORDER BY k2.nama_kelas SEPARATOR ', ')
                                FROM asesmen_sumatif_kelas ask2
                                JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id
                                JOIN kelas k2 ON kmp2.id_kelas = k2.id
                                WHERE ask2.id_asesmen_sumatif = a.id),
                               k.nama_kelas, ''
                           ) AS nama_kelas,
                           COALESCE(
                               mp.nama_mata_pelajaran,
                               (SELECT mp2.nama_mata_pelajaran 
                                FROM asesmen_sumatif_kelas ask2
                                JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id
                                JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id
                                WHERE ask2.id_asesmen_sumatif = a.id LIMIT 1),
                               ''
                           ) AS nama_mata_pelajaran
                    FROM asesmen_sumatif a
                    LEFT JOIN guru g ON (a.id_guru = g.id_user OR a.id_guru = g.id)
                    LEFT JOIN kelas_mata_pelajaran kmp ON a.id_kelas_mata_pelajaran = kmp.id
                    LEFT JOIN kelas k ON kmp.id_kelas = k.id
                    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    WHERE a.id = ?
                ");
            } elseif ($kategori === 'remedial') {
                $stmt = $pdo->prepare("
                    SELECT a.*,
                           a.judul_program AS judul_asesmen,
                           a.kategori AS jenis_remedial,
                           COALESCE(mp.kktp, a.kktp_minimal, 75.00) AS kktp,
                           a.petunjuk_pelaksanaan AS petunjuk_pengerjaan,
                           COALESCE(g.nama_guru, '') AS nama_guru,
                           COALESCE(k.nama_kelas, '') AS nama_kelas,
                           COALESCE(mp.nama_mata_pelajaran, '') AS nama_mata_pelajaran
                    FROM pengayaan_remedial a
                    LEFT JOIN guru g ON (a.id_guru = g.id_user OR a.id_guru = g.id)
                    LEFT JOIN kelas_mata_pelajaran kmp ON a.id_kelas_mata_pelajaran = kmp.id
                    LEFT JOIN kelas k ON kmp.id_kelas = k.id
                    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    WHERE a.id = ?
                ");
            } else {
                $tableName = ($kategori === 'formatif') ? 'asesmen_formatif' : 'asesmen_diagnostik';
                $stmt = $pdo->prepare("
                    SELECT a.*, 
                           COALESCE(mp.kktp, 75.00) AS kktp,
                           COALESCE(g.nama_guru, '') AS nama_guru,
                           COALESCE(k.nama_kelas, '') AS nama_kelas,
                           COALESCE(mp.nama_mata_pelajaran, '') AS nama_mata_pelajaran
                    FROM {$tableName} a
                    LEFT JOIN guru g ON (a.id_guru = g.id_user OR a.id_guru = g.id)
                    LEFT JOIN kelas_mata_pelajaran kmp ON a.id_kelas_mata_pelajaran = kmp.id
                    LEFT JOIN kelas k ON kmp.id_kelas = k.id
                    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    WHERE a.id = ?
                ");
            }

            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'Data asesmen tidak ditemukan.']);
                exit;
            }

            $row['kategori'] = $kategori;

            if ($kategori === 'sumatif') {
                $stmtKmp = $pdo->prepare("SELECT id_kelas_mata_pelajaran FROM asesmen_sumatif_kelas WHERE id_asesmen_sumatif = ?");
                $stmtKmp->execute([$id]);
                $assignedKmp = $stmtKmp->fetchAll(PDO::FETCH_COLUMN);
                if (empty($assignedKmp) && !empty($row['id_kelas_mata_pelajaran'])) {
                    $assignedKmp = [(int)$row['id_kelas_mata_pelajaran']];
                }
                $row['kmp_ids'] = array_map('intval', $assignedKmp);
            } else {
                $row['kmp_ids'] = !empty($row['id_kelas_mata_pelajaran']) ? [(int)$row['id_kelas_mata_pelajaran']] : [];
            }

            echo json_encode(['success' => true, 'data' => $row]);
            exit;
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengambil detail asesmen: ' . $e->getMessage()]);
            exit;
        }
    }

    // 1.4 UPDATE ASESMEN
    if ($act === 'update_asesmen') {
        $kategori   = trim($_POST['kategori'] ?? 'sumatif');
        $id         = (int)($_POST['id'] ?? 0);
        $judul      = trim($_POST['judul_asesmen'] ?? '');
        $idKmp      = (int)($_POST['id_kelas_mata_pelajaran'] ?? 0);
        $idGuru     = resolveValidUserIdForGuru($pdo, !empty($_POST['id_guru']) ? (int)$_POST['id_guru'] : null);
        $idModulAjar= !empty($_POST['id_modul_ajar']) ? (int)$_POST['id_modul_ajar'] : null;
        $idCp       = !empty($_POST['id_cp']) ? (int)$_POST['id_cp'] : null;
        $idTp       = !empty($_POST['id_tp']) ? (int)$_POST['id_tp'] : null;
        $idAtp      = !empty($_POST['id_atp']) ? (int)$_POST['id_atp'] : null;
        $idItp      = !empty($_POST['id_itp']) ? (int)$_POST['id_itp'] : null;
        $pertemuan  = max(1, (int)($_POST['pertemuan_ke'] ?? 1));
        $petunjuk   = trim($_POST['petunjuk_pengerjaan'] ?? '');

        // Validasi status asesmen sebelum diedit: Hanya berstatus Draft yang dapat diedit
        $table = ($kategori === 'formatif') ? 'asesmen_formatif' : (($kategori === 'diagnostik') ? 'asesmen_diagnostik' : (($kategori === 'remedial') ? 'pengayaan_remedial' : 'asesmen_sumatif'));
        $checkStmt = $pdo->prepare("SELECT status FROM {$table} WHERE id = ? LIMIT 1");
        $checkStmt->execute([$id]);
        $currStatus = $checkStmt->fetchColumn();

        if (!$currStatus) {
            echo json_encode(['success' => false, 'message' => 'Data asesmen tidak ditemukan.']);
            exit;
        }

        if ($currStatus !== 'Draft') {
            echo json_encode(['success' => false, 'message' => "Asesmen berstatus '{$currStatus}' tidak dapat diedit! Hanya asesmen dengan status Draft yang dapat diubah datanya."]);
            exit;
        }

        $status = 'Draft';

        if ($idModulAjar && (!$idCp || !$idTp)) {
            $maQ = $pdo->prepare("SELECT id_cp, id_tp, id_atp, id_itp, pertemuan_ke, id_kelas_mata_pelajaran, id_guru FROM modul_ajar WHERE id = ?");
            $maQ->execute([$idModulAjar]);
            $maData = $maQ->fetch(PDO::FETCH_ASSOC);
            if ($maData) {
                if (!$idCp) $idCp = $maData['id_cp'] ? (int)$maData['id_cp'] : null;
                if (!$idTp) $idTp = $maData['id_tp'] ? (int)$maData['id_tp'] : null;
                if (!$idAtp) $idAtp = $maData['id_atp'] ? (int)$maData['id_atp'] : null;
                if (!$idItp) $idItp = $maData['id_itp'] ? (int)$maData['id_itp'] : null;
                if (!$idGuru && !empty($maData['id_guru'])) $idGuru = resolveValidUserIdForGuru($pdo, $maData['id_guru']);
            }
        }

        $tanggalPelaksanaan = trim($_POST['tanggal_pelaksanaan'] ?? '');
        $jamMulai           = trim($_POST['jam_mulai'] ?? '');
        $durasiFinal        = max(5, (int)($_POST['durasi_menit'] ?? 60));

        if ($idKmp <= 0 && $kategori === 'sumatif') {
            $stmtFindKmp = $pdo->prepare("SELECT id_kelas_mata_pelajaran FROM asesmen_sumatif_kelas WHERE id_asesmen_sumatif = ? LIMIT 1");
            $stmtFindKmp->execute([$id]);
            $idKmp = (int)$stmtFindKmp->fetchColumn();
        }

        if ($id <= 0 || empty($judul) || $idKmp <= 0) {
            echo json_encode(['success' => false, 'message' => 'Parameter data tidak lengkap atau judul kosong.']);
            exit;
        }
        if ($durasiFinal < 5) {
            echo json_encode(['success' => false, 'message' => 'Durasi pengerjaan ujian wajib diisi minimal 5 menit.']);
            exit;
        }
        if (empty($tanggalPelaksanaan) || empty($jamMulai)) {
            echo json_encode(['success' => false, 'message' => 'Jadwal pelaksanaan wajib diisi: Tanggal pelaksanaan dan Jam Mulai harus ditentukan terlebih dahulu.']);
            exit;
        }
        if (strlen($jamMulai) === 5) $jamMulai .= ':00';

        $jamSelesai = trim($_POST['jam_selesai'] ?? '');
        if (empty($jamSelesai) || $jamSelesai <= $jamMulai) {
            $jamSelesai = date('H:i:s', strtotime($jamMulai) + ($durasiFinal * 60));
        } elseif (strlen($jamSelesai) === 5) {
            $jamSelesai .= ':00';
        }

        // VALIDASI KELAYAKAN REMEDIAL PADA SAAT EDIT: HANYA BOLEH DISIMPAN JIKA ADA SISWA YANG TIDAK LULUS
        if ($kategori === 'remedial') {
            $jenisPr = in_array($_POST['jenis_remedial'] ?? '', ['Remedial', 'Pengayaan']) ? $_POST['jenis_remedial'] : 'Remedial';
            if ($jenisPr === 'Remedial') {
                $chk = checkRemedialEligibilityForKmp($pdo, $idKmp, $pertemuan);
                if (!$chk['eligible']) {
                    echo json_encode([
                        'success' => false,
                        'message' => "Program Remedial tidak dapat disimpan untuk kelas '{$chk['nama_kelas']}' karena tidak ditemukan siswa yang TIDAK LULUS (nilai di bawah KKTP) pada asesmen formatif, diagnostik, maupun sumatif di kelas ini."
                    ]);
                    exit;
                }
            }
        }

        try {
            // Seluruh KKTP paten otomatis mengikuti Mata Pelajaran terkait
            $stmtKmpKktp = $pdo->prepare("
                SELECT COALESCE(mp.kktp, 75.00), gmp.id_guru AS gmp_id_guru, g.id_user AS guru_id_user
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id = ?
            ");
            $stmtKmpKktp->execute([$idKmp]);
            $kmpRow = $stmtKmpKktp->fetch(PDO::FETCH_ASSOC);
            $mapelKktpVal = $kmpRow['kktp'] ?? 75.00;
            $resolvedKktp = ($mapelKktpVal !== false && $mapelKktpVal !== null) ? (float)$mapelKktpVal : 75.00;

            if (!$idGuru && $kmpRow) {
                $candGuru = !empty($kmpRow['guru_id_user']) ? $kmpRow['guru_id_user'] : $kmpRow['gmp_id_guru'];
                $idGuru = resolveValidUserIdForGuru($pdo, $candGuru);
            }

            if ($kategori === 'sumatif') {
                $jenisSumatif = $_POST['jenis_sumatif'] ?? 'Sumatif Lingkup Materi';
                if ($jenisSumatif === 'Sumatif Tengah Semester' || $jenisSumatif === 'Sumatif Akhir Semester') {
                    $idModulAjar = null;
                    $idCp = null;
                    $idTp = null;
                    $idAtp = null;
                    $idItp = null;
                }
                $kktp = $resolvedKktp;
                $stmt = $pdo->prepare("
                    UPDATE asesmen_sumatif SET
                        id_kelas_mata_pelajaran = ?, id_guru = ?, id_modul_ajar = ?,
                        id_cp = ?, id_tp = ?, id_atp = ?, id_itp = ?, pertemuan_ke = ?,
                        judul_asesmen = ?, jenis_sumatif = ?, kktp = ?, durasi_menit = ?,
                        tanggal_pelaksanaan = ?, jam_mulai = ?, jam_selesai = ?,
                        petunjuk_pengerjaan = ?, status = 'Draft'
                    WHERE id = ?
                ");
                $stmt->execute([
                    $idKmp, $idGuru, $idModulAjar, $idCp, $idTp, $idAtp, $idItp, $pertemuan,
                    $judul, $jenisSumatif, $kktp, $durasiFinal, $tanggalPelaksanaan, $jamMulai, $jamSelesai,
                    $petunjuk, $id
                ]);
            } elseif ($kategori === 'formatif') {
                $teknik = $_POST['teknik_asesmen'] ?? 'Tes Tertulis';
                $tujuan = trim($_POST['tujuan_asesmen'] ?? '');
                $stmt = $pdo->prepare("
                    UPDATE asesmen_formatif SET
                        id_kelas_mata_pelajaran = ?, id_guru = ?, id_modul_ajar = ?,
                        id_cp = ?, id_tp = ?, id_atp = ?, id_itp = ?, pertemuan_ke = ?,
                        judul_asesmen = ?, teknik_asesmen = ?, tujuan_asesmen = ?,
                        durasi_menit = ?, tanggal_pelaksanaan = ?, jam_mulai = ?, jam_selesai = ?,
                        petunjuk_pengerjaan = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $idKmp, $idGuru, $idModulAjar, $idCp, $idTp, $idAtp, $idItp, $pertemuan,
                    $judul, $teknik, $tujuan, $durasiFinal, $tanggalPelaksanaan, $jamMulai, $jamSelesai,
                    $petunjuk, $status, $id
                ]);
            } elseif ($kategori === 'remedial') {
                $jenisPr = in_array($_POST['jenis_remedial'] ?? '', ['Remedial', 'Pengayaan']) ? $_POST['jenis_remedial'] : 'Remedial';
                $bentuk = in_array($_POST['bentuk_kegiatan'] ?? '', ['Bimbingan Perorangan', 'Tutor Sebaya', 'Penugasan Khusus Terbimbing', 'Pembelajaran Ulang Materi Tertentu', 'Proyek Mini Pendalaman']) ? $_POST['bentuk_kegiatan'] : 'Bimbingan Perorangan';
                $kktpPr = $resolvedKktp;
                $petunjukPr = ($petunjuk !== '') ? $petunjuk : 'Kerjakan soal tindak lanjut dengan teliti.';
                $stmt = $pdo->prepare("
                    UPDATE pengayaan_remedial SET
                        id_kelas_mata_pelajaran = ?, id_guru = ?, id_modul_ajar = ?,
                        id_cp = ?, id_tp = ?, id_atp = ?, id_itp = ?, pertemuan_ke = ?,
                        judul_program = ?, kategori = ?, kktp_minimal = ?, bentuk_kegiatan = ?,
                        durasi_menit = ?, tanggal_pelaksanaan = ?, jam_mulai = ?, jam_selesai = ?,
                        petunjuk_pelaksanaan = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $idKmp, $idGuru, $idModulAjar, $idCp, $idTp, $idAtp, $idItp, $pertemuan,
                    $judul, $jenisPr, $kktpPr, $bentuk, $durasiFinal, $tanggalPelaksanaan, $jamMulai, $jamSelesai,
                    $petunjukPr, $status, $id
                ]);
            } elseif ($kategori === 'diagnostik') {
                $jenisDiag = $_POST['jenis_diagnostik'] ?? 'Kognitif';
                $tujuan = trim($_POST['tujuan_asesmen'] ?? '');
                $stmt = $pdo->prepare("
                    UPDATE asesmen_diagnostik SET
                        id_kelas_mata_pelajaran = ?, id_guru = ?, id_modul_ajar = ?,
                        id_cp = ?, id_tp = ?, id_atp = ?, id_itp = ?, pertemuan_ke = ?,
                        judul_asesmen = ?, jenis_diagnostik = ?, tujuan_asesmen = ?,
                        durasi_menit = ?, tanggal_pelaksanaan = ?, jam_mulai = ?, jam_selesai = ?,
                        petunjuk_pengerjaan = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $idKmp, $idGuru, $idModulAjar, $idCp, $idTp, $idAtp, $idItp, $pertemuan,
                    $judul, $jenisDiag, $tujuan, $durasiFinal, $tanggalPelaksanaan, $jamMulai, $jamSelesai,
                    $petunjuk, $status, $id
                ]);
            }

            echo json_encode(['success' => true, 'message' => "Asesmen '{$judul}' berhasil diperbarui!"]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal memperbarui: ' . $e->getMessage()]);
            exit;
        }
    }

    // 1.5 TOGGLE STATUS CEPAT
    if ($act === 'toggle_status') {
        $kategori = trim($_POST['kategori'] ?? 'sumatif');
        $id = (int)($_POST['id'] ?? 0);
        $newStatus = in_array($_POST['status'] ?? '', ['Aktif', 'Draft', 'Ditutup']) ? $_POST['status'] : 'Aktif';

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID Asesmen tidak valid.']);
            exit;
        }

        $table = ($kategori === 'formatif') ? 'asesmen_formatif' : (($kategori === 'diagnostik') ? 'asesmen_diagnostik' : (($kategori === 'remedial') ? 'pengayaan_remedial' : 'asesmen_sumatif'));
        $stmt = $pdo->prepare("UPDATE {$table} SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $id]);

        echo json_encode(['success' => true, 'message' => "Status asesmen berhasil diubah menjadi {$newStatus}."]);
        exit;
    }

    if (!function_exists('deleteSingleAsesmenInternal')) {
        function deleteSingleAsesmenInternal($pdo, $kategori, $id, $force = false) {
            $table = ($kategori === 'formatif') ? 'asesmen_formatif' : (($kategori === 'diagnostik') ? 'asesmen_diagnostik' : (($kategori === 'remedial') ? 'pengayaan_remedial' : 'asesmen_sumatif'));
            $titleCol = ($kategori === 'remedial') ? 'judul_program' : 'judul_asesmen';

            $currStmt = $pdo->prepare("SELECT status, {$titleCol} AS judul_asesmen FROM {$table} WHERE id = ? LIMIT 1");
            $currStmt->execute([$id]);
            $currItem = $currStmt->fetch(PDO::FETCH_ASSOC);

            if (!$currItem) {
                return ['success' => false, 'message' => "Data asesmen ID #{$id} tidak ditemukan."];
            }

            // Aturan Hak Akses: Asesmen yang sedang AKTIF tidak dapat dihapus jika bukan force
            if ($currItem['status'] === 'Aktif' && !$force) {
                return [
                    'success' => false,
                    'is_active' => true,
                    'judul_asesmen' => $currItem['judul_asesmen'],
                    'message' => "Asesmen '{$currItem['judul_asesmen']}' sedang AKTIF dan tidak dapat dihapus! Silakan tutup asesmen terlebih dahulu."
                ];
            }

            if ($kategori === 'sumatif') {
                // 1. CBT Logs, Jawaban, Sesi Ujian
                $pdo->prepare("DELETE FROM cbt_log_pelanggaran_exambro WHERE tipe_asesmen = 'sumatif' AND id_asesmen = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM cbt_jawaban_siswa WHERE tipe_asesmen = 'sumatif' AND id_asesmen = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM cbt_sesi_ujian WHERE tipe_asesmen = 'sumatif' AND id_asesmen = ?")->execute([$id]);

                // 2. Absensi & Akses Ujian
                $pdo->prepare("DELETE FROM absensi_asesmen_sumatif WHERE id_asesmen_sumatif = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM akses_asesmen_sumatif WHERE id_asesmen_sumatif = ?")->execute([$id]);

                // 3. Nilai Sumatif Siswa
                $pdo->prepare("DELETE FROM nilai_asesmen_sumatif WHERE id_asesmen_sumatif = ?")->execute([$id]);

                // 4. Soal, Opsi, Rubrik Penskoran
                $soalIds = $pdo->query("SELECT id FROM asesmen_sumatif_soal WHERE id_asesmen_sumatif = " . (int)$id)->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($soalIds)) {
                    $inSoal = implode(',', array_map('intval', $soalIds));
                    $pdo->exec("DELETE FROM asesmen_sumatif_soal_opsi WHERE id_soal IN ($inSoal)");
                    $pdo->exec("DELETE FROM asesmen_sumatif_soal_rubrik WHERE id_soal IN ($inSoal)");
                    $pdo->exec("DELETE FROM asesmen_sumatif_soal WHERE id IN ($inSoal)");
                }

                // 5. Relasi Rombel Kelas & Master Sumatif
                $pdo->prepare("DELETE FROM asesmen_sumatif_kelas WHERE id_asesmen_sumatif = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM asesmen_sumatif WHERE id = ?")->execute([$id]);

            } elseif ($kategori === 'formatif') {
                // 1. CBT Logs, Jawaban, Sesi
                $pdo->prepare("DELETE FROM cbt_log_pelanggaran_exambro WHERE tipe_asesmen = 'formatif' AND id_asesmen = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM cbt_jawaban_siswa WHERE tipe_asesmen = 'formatif' AND id_asesmen = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM cbt_sesi_ujian WHERE tipe_asesmen = 'formatif' AND id_asesmen = ?")->execute([$id]);

                // 2. Akses & Nilai Formatif
                $pdo->prepare("DELETE FROM akses_asesmen_formatif WHERE id_asesmen_formatif = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM nilai_asesmen_formatif WHERE id_asesmen_formatif = ?")->execute([$id]);

                // 3. Soal, Opsi, Rubrik
                $soalIds = $pdo->query("SELECT id FROM asesmen_formatif_soal WHERE id_asesmen_formatif = " . (int)$id)->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($soalIds)) {
                    $inSoal = implode(',', array_map('intval', $soalIds));
                    $pdo->exec("DELETE FROM asesmen_formatif_soal_opsi WHERE id_soal IN ($inSoal)");
                    $pdo->exec("DELETE FROM asesmen_formatif_soal_rubrik WHERE id_soal IN ($inSoal)");
                    $pdo->exec("DELETE FROM asesmen_formatif_soal WHERE id IN ($inSoal)");
                }

                // 4. Master Formatif
                $pdo->prepare("DELETE FROM asesmen_formatif WHERE id = ?")->execute([$id]);

            } elseif ($kategori === 'remedial') {
                // 1. CBT Logs, Jawaban, Sesi
                $pdo->prepare("DELETE FROM cbt_log_pelanggaran_exambro WHERE tipe_asesmen = 'remedial' AND id_asesmen = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM cbt_jawaban_siswa WHERE tipe_asesmen = 'remedial' AND id_asesmen = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM cbt_sesi_ujian WHERE tipe_asesmen = 'remedial' AND id_asesmen = ?")->execute([$id]);

                // 2. Akses & Nilai Pengayaan/Remedial
                $pdo->prepare("DELETE FROM akses_pengayaan_remedial WHERE id_pengayaan_remedial = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM nilai_pengayaan_remedial WHERE id_pengayaan_remedial = ?")->execute([$id]);

                // 3. Soal, Opsi, Rubrik
                $soalIds = $pdo->query("SELECT id FROM pengayaan_remedial_soal WHERE id_pengayaan_remedial = " . (int)$id)->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($soalIds)) {
                    $inSoal = implode(',', array_map('intval', $soalIds));
                    $pdo->exec("DELETE FROM pengayaan_remedial_soal_opsi WHERE id_soal IN ($inSoal)");
                    $pdo->exec("DELETE FROM pengayaan_remedial_soal_rubrik WHERE id_soal IN ($inSoal)");
                    $pdo->exec("DELETE FROM pengayaan_remedial_soal WHERE id IN ($inSoal)");
                }

                // 4. Master Pengayaan Remedial
                $pdo->prepare("DELETE FROM pengayaan_remedial WHERE id = ?")->execute([$id]);

            } elseif ($kategori === 'diagnostik') {
                // 1. CBT Logs, Jawaban, Sesi
                $pdo->prepare("DELETE FROM cbt_log_pelanggaran_exambro WHERE tipe_asesmen = 'diagnostik' AND id_asesmen = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM cbt_jawaban_siswa WHERE tipe_asesmen = 'diagnostik' AND id_asesmen = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM cbt_sesi_ujian WHERE tipe_asesmen = 'diagnostik' AND id_asesmen = ?")->execute([$id]);

                // 2. Akses & Nilai Diagnostik
                $pdo->prepare("DELETE FROM akses_asesmen_diagnostik WHERE id_asesmen_diagnostik = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM nilai_asesmen_diagnostik WHERE id_asesmen_diagnostik = ?")->execute([$id]);

                // 3. Master Diagnostik
                $pdo->prepare("DELETE FROM asesmen_diagnostik WHERE id = ?")->execute([$id]);
            }

            return [
                'success' => true,
                'judul_asesmen' => $currItem['judul_asesmen']
            ];
        }
    }

    // 1.6 HAPUS ASESMEN BESERTA SELURUH DATA TERKAIT (CASCADING DELETE)
    if ($act === 'delete_asesmen') {
        $kategori = trim($_POST['kategori'] ?? 'sumatif');
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID Asesmen tidak valid.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $res = deleteSingleAsesmenInternal($pdo, $kategori, $id, false);
            if (!$res['success']) {
                $pdo->rollBack();
                echo json_encode($res);
                exit;
            }
            $pdo->commit();
            echo json_encode([
                'success' => true,
                'message' => "Asesmen '{$res['judul_asesmen']}' beserta seluruh butir soal, tiket akses, sesi ujian, absensi, dan nilainya berhasil dihapus permanen."
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus data asesmen: ' . $e->getMessage()]);
            exit;
        }
    }

    // 1.7 BULK DELETE ASESMEN
    if ($act === 'bulk_delete_asesmen') {
        $items = $_POST['items'] ?? [];
        if (!is_array($items)) {
            $decoded = json_decode((string)$items, true);
            if (is_array($decoded)) $items = $decoded;
            else $items = [];
        }
        $force = !empty($_POST['force']) && ($_POST['force'] === '1' || $_POST['force'] === 'true' || $_POST['force'] === true);

        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => 'Tidak ada paket asesmen yang dipilih.']);
            exit;
        }

        $deletedCount = 0;
        $skippedCount = 0;
        $skipNames = [];

        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                if (is_string($item) && strpos($item, ':') !== false) {
                    list($cKat, $cId) = explode(':', $item, 2);
                } elseif (is_array($item)) {
                    $cKat = $item['kategori'] ?? '';
                    $cId  = $item['id'] ?? 0;
                } else {
                    continue;
                }

                $cKat = trim($cKat);
                $cId  = (int)$cId;
                if ($cId <= 0 || !in_array($cKat, ['sumatif', 'formatif', 'diagnostik', 'remedial'], true)) {
                    continue;
                }

                $res = deleteSingleAsesmenInternal($pdo, $cKat, $cId, $force);
                if ($res['success']) {
                    $deletedCount++;
                } else {
                    $skippedCount++;
                    $skipNames[] = !empty($res['judul_asesmen']) ? $res['judul_asesmen'] : "ID #{$cId}";
                }
            }

            $pdo->commit();

            $msg = "Berhasil menghapus {$deletedCount} paket asesmen.";
            if ($skippedCount > 0) {
                $msg .= " ({$skippedCount} asesmen dilewati karena sedang Aktif: " . implode(', ', array_slice($skipNames, 0, 3)) . ($skippedCount > 3 ? '...' : '') . ")";
            }

            echo json_encode([
                'success' => true,
                'message' => $msg,
                'data' => [
                    'deleted' => $deletedCount,
                    'skipped' => $skippedCount
                ]
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus masal: ' . $e->getMessage()]);
            exit;
        }
    }

    // 1.8 BULK UPDATE STATUS ASESMEN
    if ($act === 'bulk_update_status') {
        $items = $_POST['items'] ?? [];
        if (!is_array($items)) {
            $decoded = json_decode((string)$items, true);
            if (is_array($decoded)) $items = $decoded;
            else $items = [];
        }
        $newStatus = in_array($_POST['status'] ?? '', ['Aktif', 'Draft', 'Ditutup']) ? $_POST['status'] : '';

        if (empty($newStatus)) {
            echo json_encode(['success' => false, 'message' => 'Status baru tidak valid. Pilih Aktif, Draft, atau Ditutup.']);
            exit;
        }

        if (empty($items)) {
            echo json_encode(['success' => false, 'message' => 'Tidak ada paket asesmen yang dipilih.']);
            exit;
        }

        $tableMap = [
            'sumatif'    => 'asesmen_sumatif',
            'formatif'   => 'asesmen_formatif',
            'diagnostik' => 'asesmen_diagnostik',
            'remedial'   => 'pengayaan_remedial'
        ];

        $updatedCount = 0;
        try {
            foreach ($items as $item) {
                if (is_string($item) && strpos($item, ':') !== false) {
                    list($cKat, $cId) = explode(':', $item, 2);
                } elseif (is_array($item)) {
                    $cKat = $item['kategori'] ?? '';
                    $cId  = $item['id'] ?? 0;
                } else {
                    continue;
                }

                $cKat = trim($cKat);
                $cId  = (int)$cId;
                if ($cId <= 0 || !isset($tableMap[$cKat])) {
                    continue;
                }

                $tbl = $tableMap[$cKat];
                $stmt = $pdo->prepare("UPDATE {$tbl} SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $cId]);
                $updatedCount++;
            }

            echo json_encode([
                'success' => true,
                'message' => "Berhasil memperbarui status {$updatedCount} paket asesmen menjadi '{$newStatus}'.",
                'data' => [
                    'updated' => $updatedCount,
                    'status'  => $newStatus
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal mengubah status masal: ' . $e->getMessage()]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenali.']);
    exit;
}

/* ==============================================================================
 * 2. MASTER OPTIONS UNTUK DROPDOWN MODAL FORM
 * ============================================================================== */
$kelasList = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);

$kmpList = $pdo->query("
    SELECT 
        kmp.id, 
        kmp.id_kelas, 
        kmp.id_mata_pelajaran,
        kmp.kode_kelas_mapel,
        k.nama_kelas, 
        mp.nama_mata_pelajaran,
        COALESCE(mp.kktp, 75.00) AS kktp,
        COALESCE(g.id_user, g.id) AS id_guru,
        g.nama_guru
    FROM kelas_mata_pelajaran kmp
    JOIN kelas k ON kmp.id_kelas = k.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
    LEFT JOIN guru g ON gmp.id_guru = g.id
    ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Tandai status kelayakan remedial per rombel kelas (hanya kelas dengan siswa tidak lulus yang eligible)
foreach ($kmpList as &$kmpItem) {
    $eligData = checkRemedialEligibilityForKmp($pdo, $kmpItem['id']);
    $kmpItem['remedial_eligible'] = $eligData['eligible'] ? 1 : 0;
    $kmpItem['failed_count'] = (int)$eligData['total_failed'];
}
unset($kmpItem);

$guruList = $pdo->query("SELECT id, nama_guru FROM guru ORDER BY nama_guru ASC")->fetchAll(PDO::FETCH_ASSOC);

$modulAjarList = $pdo->query("
    SELECT 
        ma.id, 
        ma.judul_modul, 
        ma.pertemuan_ke, 
        ma.id_kelas_mata_pelajaran,
        kmp.id_mata_pelajaran,
        COALESCE(mp.kktp, 75.00) AS kktp,
        ma.id_cp, 
        ma.id_tp, 
        ma.id_atp, 
        ma.id_itp, 
        ma.id_guru,
        k.nama_kelas, 
        mp.nama_mata_pelajaran, 
        COALESCE(g.nama_guru, '') AS nama_guru,
        COALESCE(tp.kode_tp, '') AS kode_tp, 
        COALESCE(tp.tp, '') AS tp_text
    FROM modul_ajar ma
    LEFT JOIN kelas_mata_pelajaran kmp ON ma.id_kelas_mata_pelajaran = kmp.id
    LEFT JOIN kelas k ON kmp.id_kelas = k.id
    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    LEFT JOIN guru g ON ma.id_guru = g.id
    LEFT JOIN tp ON ma.id_tp = tp.id
    ORDER BY ma.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-assessments.css') ? filemtime(BASE_PATH . '/assets/css/admin-assessments.css') : '1.0';
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-assessments.css?v=' . $cssVersion . '">';

require_once BASE_PATH . '/includes/header.php';
?>

<!-- ==============================================================================
 * LUMINA GOLD THEMED MASTER ASESMEN ENGINE
 * ============================================================================== -->
<div class="ilmuna-assess-app" id="appAssess" data-ajax="<?= BASE_URL ?>/dashboard/admin/assessments.php">

    <!-- TOOLBAR: 3 TOMBOL AKSI UTAMA + PILLS + SEARCH -->
    <div class="ilmuna-toolbar">

        <!-- Action Buttons Row — 3 tombol wajib dengan inline style eksplisit agar SELALU terlihat -->
        <div class="ilmuna-toolbar__actions-row" style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; padding-bottom: 14px; margin-bottom: 14px; border-bottom: 1px dashed #E2E8F0;">

            <!-- 1. Import Excel (.xlsx) — TEAL FILLED (selalu terlihat) -->
            <button type="button"
                id="btnOpenImportAssess"
                title="Import Paket Asesmen dari Excel (.xlsx)"
                style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; border: none; cursor: pointer; font-size: 13.5px; font-weight: 700; background: linear-gradient(135deg, #0D7A87 0%, #0A6B75 100%); color: #FFFFFF; box-shadow: 0 4px 12px rgba(13, 122, 135, 0.28); transition: all 0.2s ease;"
                onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 16px rgba(13, 122, 135, 0.35)';"
                onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(13, 122, 135, 0.28)';">
                <iconify-icon icon="solar:cloud-upload-bold" width="18" style="color: #FFFFFF;"></iconify-icon>
                <span style="color: #FFFFFF;">Import Excel (.xlsx)</span>
            </button>

            <!-- 2. Template Excel — WHITE BG + NAVY TEXT + BORDER (eksplisit, tidak putih lagi) -->
            <button type="button"
                id="btnDownloadTemplateAssess"
                title="Unduh Format Template Excel Beserta Referensi Database"
                style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; border: 1.5px solid #CBD5E1; cursor: pointer; font-size: 13.5px; font-weight: 700; background: #FFFFFF; color: #1D3B5E; box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06); transition: all 0.2s ease;"
                onmouseover="this.style.background='#F8FAFC'; this.style.borderColor='#0D7A87'; this.style.color='#0D7A87'; this.style.transform='translateY(-1px)';"
                onmouseout="this.style.background='#FFFFFF'; this.style.borderColor='#CBD5E1'; this.style.color='#1D3B5E'; this.style.transform='translateY(0)';">
                <iconify-icon icon="solar:file-download-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                <span style="color: #1D3B5E;">Template Excel</span>
            </button>

            <!-- 3. Tambah Asesmen Baru — GOLD FILLED -->
            <button type="button"
                id="btnOpenAddModal"
                title="Buat Paket Asesmen Baru Secara Manual"
                style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 10px; border: none; cursor: pointer; font-size: 13.5px; font-weight: 700; background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #FFFFFF; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.32); transition: all 0.2s ease;"
                onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 18px rgba(245, 158, 11, 0.42)';"
                onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(245, 158, 11, 0.32)';">
                <iconify-icon icon="solar:add-circle-bold" width="20" style="color: #FFFFFF;"></iconify-icon>
                <span style="color: #FFFFFF;">Tambah Asesmen Baru</span>
            </button>
        </div>

        <div class="ilmuna-toolbar__top">
            <!-- In-Page Tab Pills -->
            <div class="ilmuna-pills-wrap" id="categoryPills">
                <button type="button" class="ilmuna-pill-btn is-active" data-val="all">
                    <iconify-icon icon="solar:widget-5-bold" width="16"></iconify-icon>
                    Semua Asesmen
                    <span class="pill-count" id="pillCountAll">0</span>
                </button>
                <button type="button" class="ilmuna-pill-btn" data-val="sumatif">
                    <iconify-icon icon="solar:laptop-minimalistic-bold" width="16"></iconify-icon>
                    Sumatif (CBT)
                    <span class="pill-count" id="pillCountSumatif">0</span>
                </button>
                <button type="button" class="ilmuna-pill-btn" data-val="formatif">
                    <iconify-icon icon="solar:checklist-minimalistic-bold" width="16"></iconify-icon>
                    Formatif
                    <span class="pill-count" id="pillCountFormatif">0</span>
                </button>
                <button type="button" class="ilmuna-pill-btn" data-val="diagnostik">
                    <iconify-icon icon="solar:tuning-square-2-bold" width="16"></iconify-icon>
                    Diagnostik
                    <span class="pill-count" id="pillCountDiagnostik">0</span>
                </button>
                <button type="button" class="ilmuna-pill-btn" data-val="remedial">
                    <iconify-icon icon="solar:medal-ribbon-star-bold" width="16"></iconify-icon>
                    Remedial / Pengayaan
                    <span class="pill-count" id="pillCountRemedial">0</span>
                </button>
            </div>

            <!-- Live Search -->
            <div class="ilmuna-search-wrap">
                <span class="ilmuna-search-icon">
                    <iconify-icon icon="solar:magnifer-bold" width="18"></iconify-icon>
                </span>
                <input type="text" id="filterSearch" class="ilmuna-search-input" placeholder="Cari judul asesmen, mapel, kelas, atau guru..." autocomplete="off">
                <button type="button" class="ilmuna-search-clear" id="btnSearchClear" title="Hapus pencarian">
                    <iconify-icon icon="solar:close-circle-bold" width="18"></iconify-icon>
                </button>
            </div>
        </div>

        <div class="ilmuna-toolbar__bottom">
            <div class="ilmuna-filters-group">
                <select id="filterStatus" class="ilmuna-select-sm" title="Filter Status Asesmen">
                    <option value="all">Semua Status (Aktif / Draft / Ditutup)</option>
                    <option value="Aktif">Status: Aktif</option>
                    <option value="Draft">Status: Draft</option>
                    <option value="Ditutup">Status: Ditutup</option>
                </select>
                <select id="filterKelas" class="ilmuna-select-sm" title="Filter Rombel Kelas">
                    <option value="0">Semua Rombel Kelas</option>
                    <?php foreach ($kelasList as $k): ?>
                        <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="font-size: 12.5px; color: #64748B;">
                Ditemukan: <strong id="lblTotalData" style="color: #1E293B;">0</strong> Asesmen
            </div>
        </div>
    </div>

    <!-- MAIN DATA TABLE -->
    <div class="ilmuna-table-card">
        <div class="ilmuna-table-responsive">
            <table class="ilmuna-table" id="tableAssessments">
                <thead>
                    <tr>
                        <th style="width: 40px; text-align: center;">
                            <input type="checkbox" id="checkAllAssessments" class="ilmuna-bulk-cb" title="Pilih Semua di Halaman Ini">
                        </th>
                        <th style="width: 45px;">No</th>
                        <th>Judul & Informasi Asesmen</th>
                        <th>Kategori & Tipe</th>
                        <th>Kelas & Mata Pelajaran</th>
                        <th>Pengampu & Pertemuan</th>
                        <th>Parameter Ujian</th>
                        <th>Status</th>
                        <th style="text-align: right; width: 150px;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="assessmentsTableBody">
                    <tr>
                        <td colspan="9" class="ilmuna-loading-row">
                            <span class="ilmuna-spinner"></span> Memuat paket asesmen...
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="ilmuna-table-footer" id="tableFooter">
            <div id="paginationInfo">Menampilkan data...</div>
            <div class="ilmuna-pagination" id="paginationControls"></div>
        </div>
    </div>

</div>

<!-- ==============================================================================
 * MODAL 1: TAMBAH ASESMEN BARU
 * ============================================================================== -->
<div class="ilmuna-assess-modal" id="modalAddAssess">
    <div class="ilmuna-assess-modal__backdrop" data-close="modalAddAssess"></div>
    <div class="ilmuna-assess-modal__dialog ilmuna-assess-modal__dialog--lg">
        <div class="ilmuna-assess-modal__head">
            <h3>
                <iconify-icon icon="solar:document-add-bold" width="22"></iconify-icon>
                Buat Paket Asesmen Baru
            </h3>
            <button type="button" class="ilmuna-assess-modal__close" data-close="modalAddAssess">
                <iconify-icon icon="solar:close-square-bold" width="20"></iconify-icon>
            </button>
        </div>
        <form id="formAddAssess">
            <div class="ilmuna-assess-modal__body">
                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label">Kategori Asesmen <span class="req">*</span></label>
                    <div class="ilmuna-type-choices">
                        <div class="ilmuna-type-choice is-selected" data-type="sumatif">
                            <div class="ilmuna-type-choice__title">
                                <iconify-icon icon="solar:laptop-minimalistic-bold" width="16" style="color: #D97706;"></iconify-icon>
                                Sumatif (CBT)
                            </div>
                            <div class="ilmuna-type-choice__desc">Uji ketercapaian akhir (LM, STS, ASAS) dengan durasi & PIN ujian.</div>
                        </div>
                        <div class="ilmuna-type-choice" data-type="formatif">
                            <div class="ilmuna-type-choice__title">
                                <iconify-icon icon="solar:checklist-minimalistic-bold" width="16" style="color: #0D7A87;"></iconify-icon>
                                Formatif
                            </div>
                            <div class="ilmuna-type-choice__desc">Evaluasi proses KBM, kuis, dan lembar kerja peserta didik (LKPD).</div>
                        </div>
                        <div class="ilmuna-type-choice" data-type="diagnostik">
                            <div class="ilmuna-type-choice__title">
                                <iconify-icon icon="solar:tuning-square-2-bold" width="16" style="color: #8B5CF6;"></iconify-icon>
                                Diagnostik
                            </div>
                            <div class="ilmuna-type-choice__desc">Pemetaan awal kesiapan belajar kognitif & non-kognitif siswa.</div>
                        </div>
                        <div class="ilmuna-type-choice" data-type="remedial">
                            <div class="ilmuna-type-choice__title">
                                <iconify-icon icon="solar:medal-ribbon-star-bold" width="16" style="color: #EF4444;"></iconify-icon>
                                Remedial / Pengayaan
                            </div>
                            <div class="ilmuna-type-choice__desc">Tindak lanjut hasil asesmen. Akun hanya untuk siswa yang tidak lulus diagnostik, formatif &amp; sumatif.</div>
                        </div>
                    </div>
                    <input type="hidden" name="kategori" id="addKategori" value="sumatif">
                </div>

                <!-- Parameter Khusus Sesuai Kategori Asesmen (Reaktif Otomatis) -->
                <div id="boxFieldSumatif" class="ilmuna-form-grid-2">
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="addJenisSumatif">Jenis Sumatif</label>
                        <select class="ilmuna-form-select" id="addJenisSumatif" name="jenis_sumatif">
                            <option value="Sumatif Lingkup Materi">Sumatif Lingkup Materi (LM)</option>
                            <option value="Sumatif Tengah Semester">Sumatif Tengah Semester (STS)</option>
                            <option value="Sumatif Akhir Semester">Sumatif Akhir Semester (ASAS)</option>
                        </select>
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="addKktp">Kriteria Ketuntasan (KKTP) <span style="font-size: 11px; font-weight: 500; color: #0D7A87;">(Otomatis Mengikuti Mapel)</span></label>
                        <input type="number" class="ilmuna-form-input" id="addKktp" name="kktp" value="75.00" step="0.5" min="0" max="100" readonly style="background: #F1F5F9; color: #1D3B5E; font-weight: 700; cursor: not-allowed;">
                    </div>
                </div>

                <div id="boxFieldFormatif" class="ilmuna-form-grid-2" style="display: none;">
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="addTeknikFormatif">Teknik Asesmen</label>
                        <select class="ilmuna-form-select" id="addTeknikFormatif" name="teknik_asesmen">
                            <option value="Tes Tertulis">Tes Tertulis / CBT Mini</option>
                            <option value="Kuis Lisan">Kuis Lisan</option>
                            <option value="Observasi Kinerja / Unjuk Kerja">Observasi Kinerja / Unjuk Kerja</option>
                            <option value="Penilaian Diri">Penilaian Diri (Self-Assessment)</option>
                            <option value="Penilaian Antarteman">Penilaian Antarteman</option>
                        </select>
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="addTujuanFormatif">Tujuan Asesmen</label>
                        <input type="text" class="ilmuna-form-input" id="addTujuanFormatif" name="tujuan_asesmen" placeholder="Contoh: Mengukur pemahaman bacaan tajwid surat Al-Hujurat">
                    </div>
                </div>

                <div id="boxFieldRemedial" class="ilmuna-form-grid-2" style="display: none;">
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="addJenisRemedial">Jenis Program <span class="req">*</span></label>
                        <select class="ilmuna-form-select" id="addJenisRemedial" name="jenis_remedial">
                            <option value="Remedial">Remedial (khusus siswa tidak lulus / belum tuntas)</option>
                            <option value="Pengayaan">Pengayaan (pendalaman materi bagi yang tuntas)</option>
                        </select>
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="addBentukKegiatan">Bentuk Kegiatan</label>
                        <select class="ilmuna-form-select" id="addBentukKegiatan" name="bentuk_kegiatan">
                            <option value="Bimbingan Perorangan">Bimbingan Perorangan</option>
                            <option value="Tutor Sebaya">Tutor Sebaya</option>
                            <option value="Penugasan Khusus Terbimbing">Penugasan Khusus Terbimbing</option>
                            <option value="Pembelajaran Ulang Materi Tertentu">Pembelajaran Ulang Materi Tertentu</option>
                            <option value="Proyek Mini Pendalaman">Proyek Mini Pendalaman</option>
                        </select>
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="addKktpRemedial">Kriteria Ketuntasan (KKTP) <span style="font-size: 11px; font-weight: 500; color: #0D7A87;">(Otomatis Mengikuti Mapel)</span></label>
                        <input type="number" class="ilmuna-form-input" id="addKktpRemedial" name="kktp_remedial" value="75.00" step="0.5" min="0" max="100" readonly style="background: #F1F5F9; color: #1D3B5E; font-weight: 700; cursor: not-allowed;">
                    </div>
                </div>

                <div id="boxFieldDiagnostik" class="ilmuna-form-grid-2" style="display: none;">
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="addJenisDiag">Jenis Diagnostik</label>
                        <select class="ilmuna-form-select" id="addJenisDiag" name="jenis_diagnostik">
                            <option value="Kognitif">Diagnostik Kognitif (Kesiapan Pengetahuan)</option>
                            <option value="Non-Kognitif">Diagnostik Non-Kognitif (Gaya Belajar & Minat)</option>
                        </select>
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="addTujuanDiag">Tujuan Pemetaan</label>
                        <input type="text" class="ilmuna-form-input" id="addTujuanDiag" name="tujuan_asesmen" placeholder="Contoh: Pemetaan gaya belajar auditori, visual & kinestetik">
                    </div>
                </div>

                <!-- 1. PILIHAN ROMBEL KELAS (Single Class untuk Formatif/Diagnostik/Remedial, Paralel untuk Sumatif) -->
                <div class="ilmuna-form-group" style="margin-top: 6px;">
                    <div class="ilmuna-multi-kmp-wrap">
                        <div class="ilmuna-multi-kmp-header">
                            <div>
                                <iconify-icon icon="solar:widget-add-bold" width="16" style="vertical-align: middle; color: #0D7A87;" id="iconKmpHeader"></iconify-icon>
                                <span id="lblKmpHeaderTitle" style="font-weight: 700;">Pilihan Rombel Kelas (Multiple Choice / Paralel):</span>
                                <span id="lblKmpHeaderSub" style="font-weight: 400; font-size: 11px; color: #64748B; margin-left: 4px;">Asesmen otomatis dibuatkan untuk setiap kelas terpilih</span>
                            </div>
                            <div class="ilmuna-multi-kmp-actions">
                                <button type="button" class="ilmuna-btn-xs" id="btnSelectAllKmp">Pilih Semua Kelas Terkait</button>
                                <button type="button" class="ilmuna-btn-xs" id="btnResetKmp">Hanya Kelas Modul</button>
                            </div>
                        </div>

                        <!-- Alert jika dalam mode remedial tidak ada kelas yang memiliki siswa tidak lulus -->
                        <div id="remedialNoClassAlert" style="display: none; padding: 14px 16px; margin-bottom: 12px; background: #FEF2F2; border: 1.5px dashed #F87171; border-radius: 10px; color: #991B1B; font-size: 12.5px; line-height: 1.4;">
                            <div style="font-weight: 700; display: flex; align-items: center; gap: 6px; margin-bottom: 4px;">
                                <iconify-icon icon="solar:danger-triangle-bold" width="18" style="color: #DC2626;"></iconify-icon>
                                Tidak Ada Rombel Kelas yang Memenuhi Syarat Remedial
                            </div>
                            <span>Seluruh rombel kelas telah tuntas (lulus &ge; KKTP) pada asesmen formatif, diagnostik, maupun sumatif. Remedial hanya dapat ditambahkan pada kelas yang memiliki siswa tidak lulus.</span>
                        </div>

                        <div class="ilmuna-multi-kmp-grid" id="boxKmpChecklist">
                            <?php foreach ($kmpList as $kmp): ?>
                                <label class="ilmuna-kmp-checkbox-item" data-kmp-id="<?= $kmp['id'] ?>" data-mapel="<?= $kmp['id_mata_pelajaran'] ?? '' ?>" data-kktp="<?= htmlspecialchars((string)($kmp['kktp'] ?? 75.00)) ?>" data-guru="<?= $kmp['id_guru'] ?? '' ?>" data-namaguru="<?= htmlspecialchars($kmp['nama_guru'] ?? 'Guru Pengampu PAI') ?>" data-namakelas="<?= htmlspecialchars($kmp['nama_kelas']) ?>" data-namamapel="<?= htmlspecialchars($kmp['nama_mata_pelajaran']) ?>" data-kodekmp="<?= htmlspecialchars($kmp['kode_kelas_mapel'] ?? '') ?>" data-remedial-eligible="<?= $kmp['remedial_eligible'] ?? 0 ?>" data-failed-count="<?= $kmp['failed_count'] ?? 0 ?>">
                                    <input type="checkbox" name="id_kelas_mata_pelajaran[]" value="<?= $kmp['id'] ?>" class="chk-kmp-item">
                                    <div class="ilmuna-kmp-checkbox-text">
                                        <div class="ilmuna-kmp-checkbox-title">
                                            <?= htmlspecialchars($kmp['nama_kelas']) ?>
                                            <?php if (!empty($kmp['kode_kelas_mapel'])): ?>
                                                <span style="display: inline-flex; align-items: center; gap: 3px; font-size: 10.5px; font-weight: 700; background: #E0F2FE; color: #0284C7; padding: 1px 7px; border-radius: 5px; margin-left: 6px; border: 1px solid #BAE6FD;" title="Kode Rombel Mapel (Paten Database)">
                                                    <iconify-icon icon="solar:hashtag-bold" width="10"></iconify-icon>
                                                    <?= htmlspecialchars($kmp['kode_kelas_mapel']) ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if (!empty($kmp['remedial_eligible'])): ?>
                                                <span class="badge-remedial-eligible" style="display: none; font-size: 10px; font-weight: 700; background: #FEE2E2; color: #DC2626; padding: 1px 6px; border-radius: 4px; border: 1px solid #FECACA; margin-left: 6px;" title="Jumlah siswa yang belum tuntas di kelas ini">
                                                    <iconify-icon icon="solar:danger-triangle-bold" width="10"></iconify-icon>
                                                    <?= $kmp['failed_count'] ?> Belum Tuntas
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ilmuna-kmp-checkbox-sub"><?= htmlspecialchars($kmp['nama_mata_pelajaran']) ?> &bull; <?= htmlspecialchars($kmp['nama_guru'] ?? 'Guru PAI') ?></div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Kotak Informasi Siswa Remedial -->
                        <div id="remedialEligibilityInfo" style="display: none; margin-top: 10px;"></div>
                    </div>
                    <span class="ilmuna-form-hint" id="kmpSelectionSummary" style="font-weight: 600; color: #0D7A87; margin-top: 6px; display: inline-flex; align-items: center; gap: 4px;">
                        <iconify-icon icon="solar:check-circle-bold" width="14"></iconify-icon>
                        <span id="kmpSelectionCountText">Pilih satu atau beberapa kelas di atas</span>
                    </span>
                </div>

                <!-- 2. PILIHAN ASESMEN SUMBER YANG PERLU DIREMIDI (Langkah 2 Khusus Kategori Remedial) -->
                <div id="boxRemedialSelectAsesmen" class="ilmuna-form-group" style="display: none; background: #FFF5F5; border: 1.5px solid #FECACA; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                        <label class="ilmuna-form-label" for="addAsesmenSumber" style="margin-bottom: 0; color: #991B1B; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                            <iconify-icon icon="solar:clipboard-list-bold" width="18" style="color: #DC2626;"></iconify-icon>
                            Langkah 2: Pilih Asesmen yang Perlu Diremidi <span class="req">*</span>
                        </label>
                        <span style="font-size: 11px; font-weight: 600; color: #DC2626; background: #FEE2E2; padding: 2px 8px; border-radius: 6px;" id="lblRemedialAsesmenCount">0 Asesmen</span>
                    </div>
                    <select class="ilmuna-form-select" id="addAsesmenSumber" style="border-color: #F87171; font-weight: 600; background: #FFFFFF;">
                        <option value="">-- Pilih Asesmen yang Perlu Diremidi --</option>
                    </select>
                    <div style="font-size: 11.5px; color: #B91C1C; margin-top: 6px; display: flex; align-items: center; gap: 5px;">
                        <iconify-icon icon="solar:info-circle-bold" width="14"></iconify-icon>
                        <span>Memilih asesmen di atas akan otomatis mengisi <strong>Judul Asesmen</strong>, <strong>Pertemuan Ke-</strong>, dan <strong>Acuan Modul Ajar</strong> di bawah.</span>
                    </div>
                </div>

                <!-- 3. ACUAN MODUL AJAR (Opsional / Non-Aktif untuk STS & SAS) -->
                <!-- Banner Khusus Sumatif Tengah Semester (STS) & Akhir Semester (SAS / ASAS) -->
                <div id="boxSumatifSemesterInfoAdd" style="display: none; background: #F0FDF4; border: 1.5px solid #86EFAC; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px;">
                    <div style="font-weight: 700; color: #166534; display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                        <div style="display: flex; align-items: center; gap: 7px;">
                            <iconify-icon icon="solar:diploma-verified-bold" width="20" style="color: #16A34A;"></iconify-icon>
                            <span id="lblSumatifSemesterTitleAdd">Asesmen Sumatif Tengah Semester (STS)</span>
                        </div>
                        <span style="font-size: 11px; font-weight: 700; background: #DCFCE7; color: #15803D; padding: 2px 8px; border-radius: 6px; border: 1px solid #BBF7D0;">Non-Modul Ajar</span>
                    </div>
                    <div style="font-size: 12px; color: #15803D; line-height: 1.45;" id="lblSumatifSemesterDescAdd">
                        STS menguji seluruh materi pembelajaran yang telah dipelajari selama setengah semester (tidak terikat pada satu modul ajar spesifik). Paket ujian dibuat langsung berdasarkan rombel kelas terpilih di atas.
                    </div>
                </div>

                <div class="ilmuna-form-group" id="boxModulAjarAdd">
                    <label class="ilmuna-form-label" for="addModulAjar">
                        <iconify-icon icon="solar:book-bookmark-bold" width="16" style="color: #0D7A87; vertical-align: middle;"></iconify-icon>
                        Acuan Modul Ajar <span style="font-weight: 400; color: #64748B;">(Otomatis menarik CP, TP, ATP, ITP, Pertemuan, Kelas & Guru)</span>
                    </label>
                    <select class="ilmuna-form-select" id="addModulAjar" name="id_modul_ajar">
                        <option value="">-- Pilih Modul Ajar (Opsional / Sinkronisasi Otomatis) --</option>
                        <?php foreach ($modulAjarList as $ma): ?>
                            <option value="<?= $ma['id'] ?>"
                                data-cp="<?= $ma['id_cp'] ?? '' ?>"
                                data-tp="<?= $ma['id_tp'] ?? '' ?>"
                                data-atp="<?= $ma['id_atp'] ?? '' ?>"
                                data-itp="<?= $ma['id_itp'] ?? '' ?>"
                                data-kmp="<?= $ma['id_kelas_mata_pelajaran'] ?? '' ?>"
                                data-mapel="<?= $ma['id_mata_pelajaran'] ?? '' ?>"
                                data-kktp="<?= htmlspecialchars((string)($ma['kktp'] ?? 75.00)) ?>"
                                data-guru="<?= $ma['id_guru'] ?? '' ?>"
                                data-namaguru="<?= htmlspecialchars($ma['nama_guru'] ?? '') ?>"
                                data-namakelas="<?= htmlspecialchars($ma['nama_kelas'] ?? '') ?>"
                                data-namamapel="<?= htmlspecialchars($ma['nama_mata_pelajaran'] ?? '') ?>"
                                data-pertemuan="<?= $ma['pertemuan_ke'] ?? 1 ?>"
                                data-kodetp="<?= htmlspecialchars($ma['kode_tp'] ?? '') ?>"
                                data-tptext="<?= htmlspecialchars($ma['tp_text'] ?? '') ?>"
                                data-judul="<?= htmlspecialchars($ma['judul_modul']) ?>">
                                <?= htmlspecialchars($ma['judul_modul']) ?> &bull; P-<?= $ma['pertemuan_ke'] ?> (<?= htmlspecialchars($ma['nama_kelas'] ?? '') ?> - <?= htmlspecialchars($ma['nama_mata_pelajaran'] ?? '') ?><?= !empty($ma['kode_tp']) ? ' &bull; TP: ' . htmlspecialchars($ma['kode_tp']) : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="id_cp" id="addCp" value="">
                    <input type="hidden" name="id_tp" id="addTp" value="">
                    <input type="hidden" name="id_atp" id="addAtp" value="">
                    <input type="hidden" name="id_itp" id="addItp" value="">

                    <div class="ilmuna-modul-box" id="addModulInfo">
                        <div class="ilmuna-modul-box__head">
                            <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                            <span id="addModulInfoTitle">Modul Ajar Terpilih</span>
                        </div>
                        <div id="addModulInfoDesc">Data CP, TP, ATP, ITP, Kelas, Mapel, Guru Pengampu, dan Pertemuan Ke- otomatis diselaraskan.</div>
                        <div class="ilmuna-modul-box__tags" id="addModulInfoTags"></div>
                    </div>
                </div>

                <!-- 4. JUDUL ASESMEN -->
                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label" for="addJudul">Judul Asesmen <span class="req">*</span></label>
                    <input type="text" class="ilmuna-form-input" id="addJudul" name="judul_asesmen" placeholder="Contoh: Asesmen Sumatif Bab 1: Meraih Ketenteraman Hati dengan Zikir" required>
                    <span class="ilmuna-form-hint">Gunakan judul yang representatif terhadap materi atau capaian pembelajaran.</span>
                </div>

                <!-- 5. KELAS & GURU DISPLAY -->
                <div class="ilmuna-form-grid-2">
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="addKmpDisplay">Kelas & Mata Pelajaran Terpilih <span style="font-size: 11px; font-weight: 500; color: #64748B;">(Read Only)</span></label>
                        <div style="position: relative;">
                            <input type="text" class="ilmuna-form-input" id="addKmpDisplay" readonly style="background: #F1F5F9; color: #334155; font-weight: 600; cursor: not-allowed; padding-left: 36px;" placeholder="Otomatis mengikuti pilihan kelas di atas">
                            <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #0D7A87; display: flex;">
                                <iconify-icon icon="solar:users-group-two-rounded-bold" width="16"></iconify-icon>
                            </span>
                        </div>
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="addGuruDisplay">Guru Pengampu <span style="font-size: 11px; font-weight: 500; color: #64748B;">(Read Only)</span></label>
                        <div style="position: relative;">
                            <input type="text" class="ilmuna-form-input" id="addGuruDisplay" readonly style="background: #F1F5F9; color: #334155; font-weight: 600; cursor: not-allowed; padding-left: 36px;" placeholder="Otomatis mengikuti Modul / Kelas">
                            <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #0D7A87; display: flex;">
                                <iconify-icon icon="solar:user-bold" width="16"></iconify-icon>
                            </span>
                        </div>
                        <input type="hidden" name="id_guru" id="addGuru" value="">
                    </div>
                </div>

                <div class="ilmuna-schedule-box" style="background: linear-gradient(135deg, rgba(13, 122, 135, 0.05), rgba(217, 119, 6, 0.06)); border: 1.5px solid rgba(13, 122, 135, 0.25); border-radius: 12px; padding: 16px 18px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px dashed rgba(13, 122, 135, 0.2);">
                        <label class="ilmuna-form-label" style="margin-bottom: 0; display: inline-flex; align-items: center; gap: 7px; font-weight: 700; color: #1E293B; font-size: 13.5px;">
                            <iconify-icon icon="solar:clock-circle-bold" width="20" style="color: #0D7A87;"></iconify-icon>
                            Durasi & Jadwal Pelaksanaan Ujian <span class="req" style="color: #EF4444; font-size: 11px;">* Wajib Diisi Terlebih Dahulu</span>
                        </label>
                        <span style="font-size: 11px; font-weight: 600; color: #0D7A87; background: rgba(13, 122, 135, 0.1); padding: 3px 9px; border-radius: 6px;">
                            Jam Selesai Otomatis (Read Only)
                        </span>
                    </div>
                    <div class="ilmuna-form-grid-4" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px;">
                        <div class="ilmuna-form-group" style="margin-bottom: 0;">
                            <label class="ilmuna-form-label" for="addDurasi" style="font-size: 11.5px; font-weight: 700; color: #0D7A87;">
                                <iconify-icon icon="solar:stopwatch-bold" width="13" style="vertical-align: middle;"></iconify-icon>
                                Durasi Ujian <span class="req">*</span>
                            </label>
                            <div style="position: relative;">
                                <input type="number" class="ilmuna-form-input" id="addDurasi" name="durasi_menit" value="60" min="5" max="360" required style="font-weight: 700; padding-right: 48px;">
                                <span style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 11px; font-weight: 600; color: #64748B;">Mnt</span>
                            </div>
                        </div>
                        <div class="ilmuna-form-group" style="margin-bottom: 0;">
                            <label class="ilmuna-form-label" for="addTanggalPelaksanaan" style="font-size: 11.5px; font-weight: 700;">
                                <iconify-icon icon="solar:calendar-date-bold" width="13" style="vertical-align: middle; color: #D97706;"></iconify-icon>
                                Tgl Pelaksanaan <span class="req">*</span>
                            </label>
                            <input type="date" class="ilmuna-form-input" id="addTanggalPelaksanaan" name="tanggal_pelaksanaan" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="ilmuna-form-group" style="margin-bottom: 0;">
                            <label class="ilmuna-form-label" for="addJamMulai" style="font-size: 11.5px; font-weight: 700;">
                                <iconify-icon icon="solar:play-circle-bold" width="13" style="vertical-align: middle; color: #10B981;"></iconify-icon>
                                Jam Mulai (WIB) <span class="req">*</span>
                            </label>
                            <input type="time" class="ilmuna-form-input" id="addJamMulai" name="jam_mulai" required value="07:30">
                        </div>
                        <div class="ilmuna-form-group" style="margin-bottom: 0;">
                            <label class="ilmuna-form-label" for="addJamSelesai" style="font-size: 11.5px; font-weight: 700; color: #475569;">
                                <iconify-icon icon="solar:stop-circle-bold" width="13" style="vertical-align: middle; color: #EF4444;"></iconify-icon>
                                Jam Selesai <span style="font-size: 10px; font-weight: 500; color: #64748B;">(Otomatis)</span>
                            </label>
                            <input type="time" class="ilmuna-form-input" id="addJamSelesai" name="jam_selesai" readonly required value="08:30" style="background: #F1F5F9; color: #0D7A87; font-weight: 700; cursor: not-allowed;">
                        </div>
                    </div>
                    <div id="addScheduleDurationHint" style="margin-top: 10px; font-size: 11.5px; color: #0D7A87; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 5px;">
                            <iconify-icon icon="solar:info-circle-bold" width="14"></iconify-icon>
                            <span>Jam selesai otomatis: <strong id="addRentangText">07:30 + 60 Menit ➔ Selesai: 08:30 WIB</strong></span>
                        </div>
                        <span style="font-size: 11px; color: #64748B; font-weight: 500;">(Read Only &bull; Akumulatif)</span>
                    </div>
                </div>

                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label" for="addPertemuan">Pertemuan Ke-</label>
                    <input type="number" class="ilmuna-form-input" id="addPertemuan" name="pertemuan_ke" value="1" min="1" max="40">
                </div>

                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label" for="addPetunjuk">Petunjuk & Tata Tertib Pengerjaan</label>
                    <textarea class="ilmuna-form-textarea" id="addPetunjuk" name="petunjuk_pengerjaan" rows="3" placeholder="Tulis instruksi ujian bagi peserta didik..."></textarea>
                </div>
            </div>
            <div class="ilmuna-assess-modal__foot">
                <button type="button" class="ilmuna-btn ilmuna-btn--ghost" data-close="modalAddAssess">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--gold" id="btnSubmitAdd">
                    <iconify-icon icon="solar:check-read-bold" width="18"></iconify-icon>
                    Simpan Paket Asesmen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 2: EDIT ASESMEN
 * ============================================================================== -->
<div class="ilmuna-assess-modal" id="modalEditAssess">
    <div class="ilmuna-assess-modal__backdrop" data-close="modalEditAssess"></div>
    <div class="ilmuna-assess-modal__dialog ilmuna-assess-modal__dialog--lg">
        <div class="ilmuna-assess-modal__head">
            <h3>
                <iconify-icon icon="solar:pen-new-square-bold" width="22"></iconify-icon>
                Edit Paket Asesmen
            </h3>
            <button type="button" class="ilmuna-assess-modal__close" data-close="modalEditAssess">
                <iconify-icon icon="solar:close-square-bold" width="20"></iconify-icon>
            </button>
        </div>
        <form id="formEditAssess">
            <input type="hidden" name="kategori" id="editKategori" value="">
            <input type="hidden" name="id" id="editId" value="">

            <div class="ilmuna-assess-modal__body">
                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label">Kategori</label>
                    <input type="text" class="ilmuna-form-input" id="editKategoriLabel" readonly style="background: #E2E8F0; font-weight: 700;">
                </div>

                <!-- Banner Khusus Sumatif Tengah Semester (STS) & Akhir Semester (SAS / ASAS) -->
                <div id="boxSumatifSemesterInfoEdit" style="display: none; background: #F0FDF4; border: 1.5px solid #86EFAC; border-radius: 12px; padding: 14px 16px; margin-bottom: 16px;">
                    <div style="font-weight: 700; color: #166534; display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
                        <div style="display: flex; align-items: center; gap: 7px;">
                            <iconify-icon icon="solar:diploma-verified-bold" width="20" style="color: #16A34A;"></iconify-icon>
                            <span id="lblSumatifSemesterTitleEdit">Asesmen Sumatif Tengah Semester (STS)</span>
                        </div>
                        <span style="font-size: 11px; font-weight: 700; background: #DCFCE7; color: #15803D; padding: 2px 8px; border-radius: 6px; border: 1px solid #BBF7D0;">Non-Modul Ajar</span>
                    </div>
                    <div style="font-size: 12px; color: #15803D; line-height: 1.45;" id="lblSumatifSemesterDescEdit">
                        Asesmen terpadu semester ini menguji seluruh kompetensi yang telah dipelajari dalam satu semester (tidak terikat pada satu modul ajar spesifik).
                    </div>
                </div>

                <div class="ilmuna-form-group" id="boxModulAjarEdit">
                    <label class="ilmuna-form-label" for="editModulAjar">
                        <iconify-icon icon="solar:book-bookmark-bold" width="16" style="color: #0D7A87; vertical-align: middle;"></iconify-icon>
                        Acuan Modul Ajar <span style="font-weight: 400; color: #64748B;">(Menentukan CP, TP, ATP, ITP, Pertemuan, Kelas & Guru)</span>
                    </label>
                    <select class="ilmuna-form-select" id="editModulAjar" name="id_modul_ajar">
                        <option value="">-- Tanpa Acuan Modul Ajar (Mandiri) --</option>
                        <?php foreach ($modulAjarList as $ma): ?>
                            <option value="<?= $ma['id'] ?>"
                                data-cp="<?= $ma['id_cp'] ?? '' ?>"
                                data-tp="<?= $ma['id_tp'] ?? '' ?>"
                                data-atp="<?= $ma['id_atp'] ?? '' ?>"
                                data-itp="<?= $ma['id_itp'] ?? '' ?>"
                                data-kmp="<?= $ma['id_kelas_mata_pelajaran'] ?? '' ?>"
                                data-mapel="<?= $ma['id_mata_pelajaran'] ?? '' ?>"
                                data-kktp="<?= htmlspecialchars((string)($ma['kktp'] ?? 75.00)) ?>"
                                data-guru="<?= $ma['id_guru'] ?? '' ?>"
                                data-namaguru="<?= htmlspecialchars($ma['nama_guru'] ?? '') ?>"
                                data-namakelas="<?= htmlspecialchars($ma['nama_kelas'] ?? '') ?>"
                                data-namamapel="<?= htmlspecialchars($ma['nama_mata_pelajaran'] ?? '') ?>"
                                data-pertemuan="<?= $ma['pertemuan_ke'] ?? 1 ?>"
                                data-kodetp="<?= htmlspecialchars($ma['kode_tp'] ?? '') ?>"
                                data-tptext="<?= htmlspecialchars($ma['tp_text'] ?? '') ?>"
                                data-judul="<?= htmlspecialchars($ma['judul_modul']) ?>">
                                <?= htmlspecialchars($ma['judul_modul']) ?> &bull; P-<?= $ma['pertemuan_ke'] ?> (<?= htmlspecialchars($ma['nama_kelas'] ?? '') ?> - <?= htmlspecialchars($ma['nama_mata_pelajaran'] ?? '') ?><?= !empty($ma['kode_tp']) ? ' &bull; TP: ' . htmlspecialchars($ma['kode_tp']) : '' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="id_cp" id="editCp" value="">
                    <input type="hidden" name="id_tp" id="editTp" value="">
                    <input type="hidden" name="id_atp" id="editAtp" value="">
                    <input type="hidden" name="id_itp" id="editItp" value="">

                    <div class="ilmuna-modul-box" id="editModulInfo">
                        <div class="ilmuna-modul-box__head">
                            <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                            <span id="editModulInfoTitle">Modul Ajar Tersinkronisasi</span>
                        </div>
                        <div id="editModulInfoDesc">Data CP, TP, ATP, ITP, dan Pertemuan Ke- otomatis diselaraskan dengan modul ajar ini.</div>
                        <div class="ilmuna-modul-box__tags" id="editModulInfoTags"></div>
                    </div>
                </div>

                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label" for="editJudul">Judul Asesmen <span class="req">*</span></label>
                    <input type="text" class="ilmuna-form-input" id="editJudul" name="judul_asesmen" required>
                </div>

                <div class="ilmuna-form-grid-2">
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="editKmpDisplay">Kelas & Mata Pelajaran <span style="font-size: 11px; font-weight: 500; color: #64748B;">(Read Only)</span></label>
                        <div style="position: relative;">
                            <input type="text" class="ilmuna-form-input" id="editKmpDisplay" readonly style="background: #F1F5F9; color: #334155; font-weight: 600; cursor: not-allowed; padding-left: 36px;" placeholder="Memuat rombel kelas...">
                            <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #0D7A87; display: flex;">
                                <iconify-icon icon="solar:users-group-two-rounded-bold" width="16"></iconify-icon>
                            </span>
                        </div>
                        <input type="hidden" name="id_kelas_mata_pelajaran" id="editKmp" value="">
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="editGuruDisplay">Guru Pengampu <span style="font-size: 11px; font-weight: 500; color: #64748B;">(Read Only)</span></label>
                        <div style="position: relative;">
                            <input type="text" class="ilmuna-form-input" id="editGuruDisplay" readonly style="background: #F1F5F9; color: #334155; font-weight: 600; cursor: not-allowed; padding-left: 36px;" placeholder="Otomatis mengikuti Kelas & Mapel / Modul">
                            <span style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #0D7A87; display: flex;">
                                <iconify-icon icon="solar:user-bold" width="16"></iconify-icon>
                            </span>
                        </div>
                        <input type="hidden" name="id_guru" id="editGuru" value="">
                    </div>
                </div>

                <div class="ilmuna-schedule-box" style="background: linear-gradient(135deg, rgba(13, 122, 135, 0.05), rgba(217, 119, 6, 0.06)); border: 1.5px solid rgba(13, 122, 135, 0.25); border-radius: 12px; padding: 16px 18px; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px dashed rgba(13, 122, 135, 0.2);">
                        <label class="ilmuna-form-label" style="margin-bottom: 0; display: inline-flex; align-items: center; gap: 7px; font-weight: 700; color: #1E293B; font-size: 13.5px;">
                            <iconify-icon icon="solar:clock-circle-bold" width="20" style="color: #0D7A87;"></iconify-icon>
                            Durasi & Jadwal Pelaksanaan Ujian <span class="req" style="color: #EF4444; font-size: 11px;">* Wajib Diisi</span>
                        </label>
                        <span style="font-size: 11px; font-weight: 600; color: #0D7A87; background: rgba(13, 122, 135, 0.1); padding: 3px 9px; border-radius: 6px;">
                            Jam Selesai Otomatis (Read Only)
                        </span>
                    </div>
                    <div class="ilmuna-form-grid-4" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px;">
                        <div class="ilmuna-form-group" style="margin-bottom: 0;">
                            <label class="ilmuna-form-label" for="editDurasi" style="font-size: 11.5px; font-weight: 700; color: #0D7A87;">
                                <iconify-icon icon="solar:stopwatch-bold" width="13" style="vertical-align: middle;"></iconify-icon>
                                Durasi Ujian <span class="req">*</span>
                            </label>
                            <div style="position: relative;">
                                <input type="number" class="ilmuna-form-input" id="editDurasi" name="durasi_menit" value="60" min="5" max="360" required style="font-weight: 700; padding-right: 48px;">
                                <span style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 11px; font-weight: 600; color: #64748B;">Mnt</span>
                            </div>
                        </div>
                        <div class="ilmuna-form-group" style="margin-bottom: 0;">
                            <label class="ilmuna-form-label" for="editTanggalPelaksanaan" style="font-size: 11.5px; font-weight: 700;">
                                <iconify-icon icon="solar:calendar-date-bold" width="13" style="vertical-align: middle; color: #D97706;"></iconify-icon>
                                Tgl Pelaksanaan <span class="req">*</span>
                            </label>
                            <input type="date" class="ilmuna-form-input" id="editTanggalPelaksanaan" name="tanggal_pelaksanaan" required>
                        </div>
                        <div class="ilmuna-form-group" style="margin-bottom: 0;">
                            <label class="ilmuna-form-label" for="editJamMulai" style="font-size: 11.5px; font-weight: 700;">
                                <iconify-icon icon="solar:play-circle-bold" width="13" style="vertical-align: middle; color: #10B981;"></iconify-icon>
                                Jam Mulai (WIB) <span class="req">*</span>
                            </label>
                            <input type="time" class="ilmuna-form-input" id="editJamMulai" name="jam_mulai" required>
                        </div>
                        <div class="ilmuna-form-group" style="margin-bottom: 0;">
                            <label class="ilmuna-form-label" for="editJamSelesai" style="font-size: 11.5px; font-weight: 700; color: #475569;">
                                <iconify-icon icon="solar:stop-circle-bold" width="13" style="vertical-align: middle; color: #EF4444;"></iconify-icon>
                                Jam Selesai <span style="font-size: 10px; font-weight: 500; color: #64748B;">(Otomatis)</span>
                            </label>
                            <input type="time" class="ilmuna-form-input" id="editJamSelesai" name="jam_selesai" readonly required style="background: #F1F5F9; color: #0D7A87; font-weight: 700; cursor: not-allowed;">
                        </div>
                    </div>
                    <div id="editScheduleDurationHint" style="margin-top: 10px; font-size: 11.5px; color: #0D7A87; font-weight: 600; display: flex; align-items: center; justify-content: space-between;">
                        <div style="display: flex; align-items: center; gap: 5px;">
                            <iconify-icon icon="solar:info-circle-bold" width="14"></iconify-icon>
                            <span>Jam selesai otomatis: <strong id="editRentangText">- WIB</strong></span>
                        </div>
                        <span style="font-size: 11px; color: #64748B; font-weight: 500;">(Read Only &bull; Akumulatif)</span>
                    </div>
                </div>

                <div id="editBoxSumatif" class="ilmuna-form-grid-2">
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="editJenisSumatif">Jenis Sumatif</label>
                        <select class="ilmuna-form-select" id="editJenisSumatif" name="jenis_sumatif">
                            <option value="Sumatif Lingkup Materi">Sumatif Lingkup Materi</option>
                            <option value="Sumatif Tengah Semester">Sumatif Tengah Semester</option>
                            <option value="Sumatif Akhir Semester">Sumatif Akhir Semester</option>
                        </select>
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="editKktp">KKTP <span style="font-size: 11px; font-weight: 500; color: #0D7A87;">(Otomatis Mengikuti Mapel)</span></label>
                        <input type="number" class="ilmuna-form-input" id="editKktp" name="kktp" step="0.5" readonly style="background: #F1F5F9; color: #1D3B5E; font-weight: 700; cursor: not-allowed;">
                    </div>
                </div>

                <div id="editBoxFormatif" class="ilmuna-form-grid-2" style="display: none;">
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="editTeknikFormatif">Teknik Asesmen</label>
                        <select class="ilmuna-form-select" id="editTeknikFormatif" name="teknik_asesmen">
                            <option value="Tes Tertulis">Tes Tertulis / CBT</option>
                            <option value="Kuis Lisan">Kuis Lisan</option>
                            <option value="Observasi Kinerja">Observasi Kinerja</option>
                            <option value="Penilaian Diri">Penilaian Diri</option>
                            <option value="Penilaian Antarteman">Penilaian Antarteman</option>
                        </select>
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="editTujuanFormatif">Tujuan Asesmen</label>
                        <input type="text" class="ilmuna-form-input" id="editTujuanFormatif" name="tujuan_asesmen">
                    </div>
                </div>

                <div id="editBoxRemedial" class="ilmuna-form-grid-2" style="display: none;">
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="editJenisRemedial">Jenis Program</label>
                        <select class="ilmuna-form-select" id="editJenisRemedial" name="jenis_remedial">
                            <option value="Remedial">Remedial</option>
                            <option value="Pengayaan">Pengayaan</option>
                        </select>
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="editBentukKegiatan">Bentuk Kegiatan</label>
                        <select class="ilmuna-form-select" id="editBentukKegiatan" name="bentuk_kegiatan">
                            <option value="Bimbingan Perorangan">Bimbingan Perorangan</option>
                            <option value="Tutor Sebaya">Tutor Sebaya</option>
                            <option value="Penugasan Khusus Terbimbing">Penugasan Khusus Terbimbing</option>
                            <option value="Pembelajaran Ulang Materi Tertentu">Pembelajaran Ulang Materi Tertentu</option>
                            <option value="Proyek Mini Pendalaman">Proyek Mini Pendalaman</option>
                        </select>
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="editKktpRemedial">KKTP <span style="font-size: 11px; font-weight: 500; color: #0D7A87;">(Otomatis Mengikuti Mapel)</span></label>
                        <input type="number" class="ilmuna-form-input" id="editKktpRemedial" name="kktp_remedial" step="0.5" readonly style="background: #F1F5F9; color: #1D3B5E; font-weight: 700; cursor: not-allowed;">
                    </div>
                    <div id="editRemedialEligibilityInfo" style="grid-column: 1 / -1; margin-top: 4px;"></div>
                </div>

                <div id="editBoxDiagnostik" class="ilmuna-form-grid-2" style="display: none;">
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="editJenisDiag">Jenis Diagnostik</label>
                        <select class="ilmuna-form-select" id="editJenisDiag" name="jenis_diagnostik">
                            <option value="Kognitif">Kognitif</option>
                            <option value="Non-Kognitif">Non-Kognitif</option>
                        </select>
                    </div>
                    <div class="ilmuna-form-group">
                        <label class="ilmuna-form-label" for="editTujuanDiag">Tujuan Pemetaan</label>
                        <input type="text" class="ilmuna-form-input" id="editTujuanDiag" name="tujuan_asesmen">
                    </div>
                </div>

                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label" for="editPertemuan">Pertemuan Ke-</label>
                    <input type="number" class="ilmuna-form-input" id="editPertemuan" name="pertemuan_ke">
                </div>

                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label" for="editPetunjuk">Petunjuk Pengerjaan</label>
                    <textarea class="ilmuna-form-textarea" id="editPetunjuk" name="petunjuk_pengerjaan" rows="3"></textarea>
                </div>
            </div>
            <div class="ilmuna-assess-modal__foot">
                <button type="button" class="ilmuna-btn ilmuna-btn--ghost" data-close="modalEditAssess">Batal</button>
                <button type="submit" class="ilmuna-btn ilmuna-btn--teal" id="btnSubmitEdit">
                    <iconify-icon icon="solar:check-read-bold" width="18"></iconify-icon>
                    Perbarui Asesmen
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 3: KONFIRMASI HAPUS
 * ============================================================================== -->
<div class="ilmuna-assess-modal" id="modalDeleteAssess">
    <div class="ilmuna-assess-modal__backdrop" data-close="modalDeleteAssess"></div>
    <div class="ilmuna-assess-modal__dialog" style="max-width: 440px;">
        <div class="ilmuna-assess-modal__head" style="background: linear-gradient(135deg, #EF4444 0%, #B91C1C 100%);">
            <h3>
                <iconify-icon icon="solar:trash-bin-trash-bold" width="22"></iconify-icon>
                Hapus Asesmen
            </h3>
            <button type="button" class="ilmuna-assess-modal__close" data-close="modalDeleteAssess">
                <iconify-icon icon="solar:close-square-bold" width="20"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-assess-modal__body" style="text-align: center; padding: 28px 20px;">
            <div style="width: 60px; height: 60px; border-radius: 50%; background: rgba(239, 68, 68, 0.12); color: #EF4444; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                <iconify-icon icon="solar:danger-triangle-bold" width="34"></iconify-icon>
            </div>
            <h4 style="margin: 0 0 8px; font-size: 16px; font-weight: 800; color: #0F172A;">Apakah Anda yakin?</h4>
            <p style="font-size: 13px; color: #475569; margin: 0;" id="deleteConfirmText">
                Asesmen ini dan seluruh data tiket ujian serta nilainya akan dihapus secara permanen.
            </p>
        </div>
        <div class="ilmuna-assess-modal__foot" style="justify-content: center;">
            <button type="button" class="ilmuna-btn ilmuna-btn--ghost" data-close="modalDeleteAssess">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--danger" id="btnConfirmDelete">
                <iconify-icon icon="solar:trash-bin-trash-bold" width="18"></iconify-icon>
                Ya, Hapus Sekarang
            </button>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 4: IMPORT EXCEL ASESMEN
 * ============================================================================== -->
<div class="ilmuna-assess-modal" id="modalImportAssess">
    <div class="ilmuna-assess-modal__backdrop" data-close="modalImportAssess"></div>
    <div class="ilmuna-assess-modal__dialog" style="max-width: 600px;">
        <div class="ilmuna-assess-modal__head" style="background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);">
            <h3>
                <iconify-icon icon="solar:cloud-upload-bold" width="22"></iconify-icon>
                Import Paket Asesmen dari Excel (.xlsx)
            </h3>
            <button type="button" class="ilmuna-assess-modal__close" data-close="modalImportAssess">
                <iconify-icon icon="solar:close-square-bold" width="20"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-assess-modal__body" style="padding: 24px;">
            <div style="margin-bottom: 16px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                <div>
                    <strong style="font-size: 13px; color: #1D3B5E; display: block;">Gunakan Format Template Resmi</strong>
                    <span style="font-size: 11.5px; color: #64748B;">Termasuk referensi Modul Ajar (Sheet 2), Guru Mapel (Sheet 3), dan Panduan (Sheet 4).</span>
                </div>
                <button type="button"
                    class="ilmuna-btn ilmuna-btn--sm"
                    id="btnModalDownloadTemplate"
                    style="white-space: nowrap; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; border: 1.5px solid #CBD5E1; background: #FFFFFF; color: #1D3B5E; font-weight: 700; cursor: pointer;"
                    onmouseover="this.style.background='#F8FAFC'; this.style.borderColor='#0D7A87'; this.style.color='#0D7A87';"
                    onmouseout="this.style.background='#FFFFFF'; this.style.borderColor='#CBD5E1'; this.style.color='#1D3B5E';">
                    <iconify-icon icon="solar:file-download-bold" width="15" style="color: #0D7A87;"></iconify-icon>
                    <span style="color: #1D3B5E;">Unduh Template</span>
                </button>
            </div>

            <div class="ilmuna-file-dropzone" id="assessFileDropzone">
                <iconify-icon icon="solar:cloud-upload-bold" width="42"></iconify-icon>
                <label for="assessImportFile" style="cursor: pointer; font-weight: 700; color: #1D3B5E; margin-top: 8px; display: block; font-size: 14px;">
                    Pilih File Excel (.xlsx) atau Tarik ke Sini
                </label>
                <span style="font-size: 12px; color: #64748B;">Hanya format berkas OpenXML Spreadsheet .xlsx (Maks. 10MB)</span>
                <div id="assessSelectedFileName" style="display: none; margin-top: 12px; font-weight: 600; color: #0D7A87; font-size: 12.5px; background: rgba(13,122,135,0.08); padding: 8px 14px; border-radius: 8px;"></div>
                <input type="file" id="assessImportFile" accept=".xlsx" class="ilmuna-file-input">
            </div>

            <div id="assessImportLog" class="ilmuna-import-log" style="display: none;"></div>
        </div>
        <div class="ilmuna-assess-modal__foot" style="justify-content: space-between;">
            <button type="button" class="ilmuna-btn ilmuna-btn--ghost" data-close="modalImportAssess">Tutup</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnDoImportAssess">
                <iconify-icon icon="solar:cloud-upload-bold" width="18"></iconify-icon>
                Mulai Import Asesmen
            </button>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 5: BULK DELETE ASESMEN
 * ============================================================================== -->
<div class="ilmuna-assess-modal" id="modalBulkDeleteAssess">
    <div class="ilmuna-assess-modal__backdrop" data-close="modalBulkDeleteAssess"></div>
    <div class="ilmuna-assess-modal__dialog" style="max-width: 460px;">
        <div class="ilmuna-assess-modal__head" style="background: linear-gradient(135deg, #EF4444 0%, #B91C1C 100%);">
            <h3>
                <iconify-icon icon="solar:trash-bin-trash-bold" width="22"></iconify-icon>
                Hapus Masal Asesmen
            </h3>
            <button type="button" class="ilmuna-assess-modal__close" data-close="modalBulkDeleteAssess">
                <iconify-icon icon="solar:close-square-bold" width="20"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-assess-modal__body" style="text-align: center; padding: 26px 20px;">
            <div style="width: 60px; height: 60px; border-radius: 50%; background: rgba(239, 68, 68, 0.12); color: #EF4444; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
                <iconify-icon icon="solar:danger-triangle-bold" width="34"></iconify-icon>
            </div>
            <h4 style="margin: 0 0 8px; font-size: 16px; font-weight: 800; color: #0F172A;">Konfirmasi Hapus Masal</h4>
            <p style="font-size: 13px; color: #475569; margin: 0;" id="bulkDeleteConfirmText">
                Anda akan menghapus <strong id="bulkDeleteCountText" style="color: #EF4444;">0</strong> paket asesmen terpilih beserta seluruh butir soal, tiket akses, rekaman sesi CBT, absensi, dan nilai siswa terkait secara permanen.
            </p>
            <div style="margin-top: 14px; text-align: left; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; padding: 10px 14px; font-size: 12px; color: #92400E;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600; margin: 0;">
                    <input type="checkbox" id="chkBulkForceDelete" class="ilmuna-bulk-cb">
                    <span>Tetap hapus asesmen meskipun statusnya sedang <strong>Aktif</strong></span>
                </label>
            </div>
        </div>
        <div class="ilmuna-assess-modal__foot" style="justify-content: center; gap: 10px;">
            <button type="button" class="ilmuna-btn ilmuna-btn--ghost" data-close="modalBulkDeleteAssess">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--danger" id="btnConfirmBulkDelete">
                <iconify-icon icon="solar:trash-bin-trash-bold" width="18"></iconify-icon>
                Ya, Hapus Semua Terpilih
            </button>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 6: BULK STATUS ASESMEN
 * ============================================================================== -->
<div class="ilmuna-assess-modal" id="modalBulkStatusAssess">
    <div class="ilmuna-assess-modal__backdrop" data-close="modalBulkStatusAssess"></div>
    <div class="ilmuna-assess-modal__dialog" style="max-width: 440px;">
        <div class="ilmuna-assess-modal__head" style="background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%);">
            <h3>
                <iconify-icon icon="solar:tag-bold" width="22"></iconify-icon>
                Ubah Status Masal
            </h3>
            <button type="button" class="ilmuna-assess-modal__close" data-close="modalBulkStatusAssess">
                <iconify-icon icon="solar:close-square-bold" width="20"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-assess-modal__body" style="padding: 22px 20px;">
            <p style="font-size: 13px; color: #475569; margin: 0 0 16px;">
                Pilih status pelaksanaan baru untuk <strong id="bulkStatusCountText" style="color: #0D7A87;">0</strong> paket asesmen terpilih:
            </p>
            <div class="ilmuna-form-group">
                <label class="ilmuna-form-label">Status Baru <span class="req">*</span></label>
                <select id="selectBulkStatus" class="ilmuna-input" style="width: 100%;">
                    <option value="Aktif">Aktif — Peserta didik dapat mengerjakan ujian</option>
                    <option value="Draft">Draft — Dalam proses perancangan / pengeditan</option>
                    <option value="Ditutup">Ditutup — Pelaksanaan asesmen selesai / dihentikan</option>
                </select>
            </div>
        </div>
        <div class="ilmuna-assess-modal__foot" style="justify-content: flex-end; gap: 10px;">
            <button type="button" class="ilmuna-btn ilmuna-btn--ghost" data-close="modalBulkStatusAssess">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnConfirmBulkStatus" style="background: linear-gradient(135deg, #0D7A87 0%, #095963 100%); color: #fff;">
                <iconify-icon icon="solar:check-circle-bold" width="18"></iconify-icon>
                Terapkan Status
            </button>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * FLOATING BULK ACTION BAR
 * ============================================================================== -->
<div class="ilmuna-bulk-bar" id="bulkBarAssessments">
    <div class="ilmuna-bulk-bar__counter">
        <span class="ilmuna-bulk-pill" id="bulkCountAssess">0</span>
        <span class="ilmuna-bulk-label">Asesmen Terpilih</span>
    </div>
    <div class="ilmuna-bulk-bar__divider"></div>
    <div class="ilmuna-bulk-bar__actions">
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--emerald" id="btnBulkQuickActivate" title="Aktifkan seluruh asesmen terpilih">
            <iconify-icon icon="solar:play-circle-bold" width="16"></iconify-icon>
            <span>Aktifkan</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--gold" id="btnBulkQuickClose" title="Tutup seluruh asesmen terpilih">
            <iconify-icon icon="solar:stop-circle-bold" width="16"></iconify-icon>
            <span>Tutup Pelaksanaan</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--teal" id="btnBulkOpenStatusModal" title="Ubah status ke Aktif, Draft, atau Ditutup">
            <iconify-icon icon="solar:tag-bold" width="16"></iconify-icon>
            <span>Ubah Status...</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--danger" id="btnBulkOpenDeleteModal" title="Hapus seluruh asesmen terpilih">
            <iconify-icon icon="solar:trash-bin-trash-bold" width="16"></iconify-icon>
            <span>Hapus Masal</span>
        </button>
    </div>
    <button type="button" class="ilmuna-bulk-bar__close" id="btnBulkCloseBar" title="Batalkan Pilihan">
        <iconify-icon icon="solar:close-circle-bold" width="20"></iconify-icon>
    </button>
</div>

<!-- ==============================================================================
 * JAVASCRIPT: PURE REACTIVE SPA ENGINE
 * ============================================================================== -->
<script>
(function() {
    'use strict';

    var app = document.getElementById('appAssess');
    var ajaxUrl = window.location.href.split('?')[0].split('#')[0];
    if (app && app.getAttribute('data-ajax')) {
        var definedAjax = app.getAttribute('data-ajax');
        if (ajaxUrl.endsWith('.php') && !definedAjax.endsWith('.php')) {
            definedAjax += '.php';
        }
        ajaxUrl = definedAjax;
    }

    var state = {
        kategori: 'all',
        status: 'all',
        kelasId: 0,
        search: '',
        page: 1,
        perPage: 15
    };

    var deleteTarget = { kategori: '', id: 0 };
    var searchTimer = null;

    var tbody = document.getElementById('assessmentsTableBody');
    var searchInput = document.getElementById('filterSearch');
    var btnSearchClear = document.getElementById('btnSearchClear');
    var filterStatus = document.getElementById('filterStatus');
    var filterKelas = document.getElementById('filterKelas');
    var categoryPills = document.querySelectorAll('#categoryPills .ilmuna-pill-btn');

    // Bulk Actions State & Elements
    var selectedItems = new Set();
    var checkAllAssess = document.getElementById('checkAllAssessments');
    var bulkBar = document.getElementById('bulkBarAssessments');
    var bulkCountEl = document.getElementById('bulkCountAssess');

    function updateBulkBar() {
        var count = selectedItems.size;
        if (bulkCountEl) bulkCountEl.textContent = count;
        if (bulkBar) {
            if (count > 0) bulkBar.classList.add('is-active');
            else bulkBar.classList.remove('is-active');
        }

        if (checkAllAssess) {
            var rowCbs = tbody.querySelectorAll('.row-assess-cb');
            if (rowCbs.length === 0) {
                checkAllAssess.checked = false;
                checkAllAssess.indeterminate = false;
            } else {
                var checkedCount = 0;
                rowCbs.forEach(function(cb) {
                    if (cb.checked) checkedCount++;
                });
                if (checkedCount === rowCbs.length) {
                    checkAllAssess.checked = true;
                    checkAllAssess.indeterminate = false;
                } else if (checkedCount > 0) {
                    checkAllAssess.checked = false;
                    checkAllAssess.indeterminate = true;
                } else {
                    checkAllAssess.checked = false;
                    checkAllAssess.indeterminate = false;
                }
            }
        }
    }

    function clearBulkSelection() {
        selectedItems.clear();
        if (checkAllAssess) {
            checkAllAssess.checked = false;
            checkAllAssess.indeterminate = false;
        }
        var rowCbs = tbody.querySelectorAll('.row-assess-cb');
        rowCbs.forEach(function(cb) {
            cb.checked = false;
            var r = cb.closest('tr');
            if (r) r.classList.remove('is-selected');
        });
        updateBulkBar();
    }

    function showToast(message, type) {
        type = type || 'success';
        var normType = (type === 'error' || type === 'danger' || type === 'delete') ? 'error' : (type || 'info');
        var title = (normType === 'error' ? 'Gagal' : (normType === 'success' ? 'Berhasil' : (normType === 'warning' ? 'Peringatan' : 'Pemberitahuan')));
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, title, message, 4000);
        }
        if (typeof window.ilmunaToast === 'function') {
            window.ilmunaToast(normType, message, title, 2500);
            return;
        }
        if (typeof window.showToast === 'function') {
            window.showToast(normType, message, null, 2500);
            return;
        }
        alert(message);
    }

    function syncKmpSelectionState() {
        var checkedBoxes = document.querySelectorAll('#boxKmpChecklist .chk-kmp-item:checked');
        var count = checkedBoxes.length;
        var countText = document.getElementById('kmpSelectionCountText');
        var addGuruDisplay = document.getElementById('addGuruDisplay');
        var addGuru = document.getElementById('addGuru');

        if (count === 0) {
            if (countText) {
                countText.innerHTML = '<span style="color: #EF4444; font-weight: 700;"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> Belum ada rombel kelas dipilih! Silakan centang minimal satu kelas.</span>';
            }
            if (addGuruDisplay) addGuruDisplay.value = '';
            if (addGuru) addGuru.value = '';
        } else if (count === 1) {
            var label = checkedBoxes[0].closest('.ilmuna-kmp-checkbox-item');
            var namaKelas = label ? (label.getAttribute('data-namakelas') || '') : '';
            var namaMapel = label ? (label.getAttribute('data-namamapel') || '') : '';
            var namaGuru = label ? (label.getAttribute('data-namaguru') || '') : '';
            var guruId = label ? (label.getAttribute('data-guru') || '') : '';

            if (countText) {
                countText.innerHTML = '<span style="color: #0D7A87; font-weight: 700;"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> 1 Rombel Kelas Terpilih:</span> <strong>' + escapeHtml(namaKelas) + '</strong> (' + escapeHtml(namaMapel) + ')';
            }
            if (addGuruDisplay) addGuruDisplay.value = namaGuru || 'Guru Pengampu PAI';
            if (addGuru) addGuru.value = guruId || '';
        } else {
            var names = [];
            checkedBoxes.forEach(function(cb) {
                var l = cb.closest('.ilmuna-kmp-checkbox-item');
                if (l) names.push(l.getAttribute('data-namakelas'));
            });
            if (countText) {
                countText.innerHTML = '<span style="color: #0D7A87; font-weight: 700;"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> ' + count + ' Rombel Kelas Terpilih:</span> <strong>' + escapeHtml(names.join(', ')) + '</strong> <span style="font-weight: 400; color: #64748B;">(Otomatis membuat ' + count + ' baris asesmen)</span>';
            }
            if (addGuruDisplay) addGuruDisplay.value = 'Otomatis per Rombel Kelas (' + count + ' Guru Pengampu)';
            var firstLabel = checkedBoxes[0].closest('.ilmuna-kmp-checkbox-item');
            if (addGuru) addGuru.value = firstLabel ? (firstLabel.getAttribute('data-guru') || '') : '';
        }

        if (count > 0) {
            var firstChkLabel = checkedBoxes[0].closest('.ilmuna-kmp-checkbox-item');
            var mapelKktp = firstChkLabel ? (firstChkLabel.getAttribute('data-kktp') || '75.00') : '75.00';
            var addKktpEl = document.getElementById('addKktp');
            var addKktpRemedialEl = document.getElementById('addKktpRemedial');
            if (addKktpEl) addKktpEl.value = mapelKktp;
            if (addKktpRemedialEl) addKktpRemedialEl.value = mapelKktp;
        }

        var currentAddKat = document.getElementById('addKategori') ? document.getElementById('addKategori').value : '';

        // Auto-suggest Judul Asesmen untuk STS / SAS berdasarkan kelasnya
        var currentJenisSumatif = document.getElementById('addJenisSumatif') ? document.getElementById('addJenisSumatif').value : '';
        var judulInput = document.getElementById('addJudul');

        if (currentAddKat === 'sumatif' && (currentJenisSumatif === 'Sumatif Tengah Semester' || currentJenisSumatif === 'Sumatif Akhir Semester')) {
            var prefix = (currentJenisSumatif === 'Sumatif Tengah Semester') ? 'Sumatif Tengah Semester (STS) PAI' : 'Sumatif Akhir Semester (SAS) PAI';
            var curVal = judulInput ? judulInput.value.trim() : '';
            var isAutoOrEmpty = !curVal || 
                                curVal.startsWith('Sumatif Tengah Semester') || 
                                curVal.startsWith('Sumatif Akhir Semester') || 
                                curVal.startsWith('STS') || 
                                curVal.startsWith('SAS') || 
                                curVal.startsWith('Asesmen -') || 
                                curVal.startsWith('Sumatif -');

            if (judulInput && isAutoOrEmpty) {
                if (count === 1) {
                    var firstLabel = checkedBoxes[0].closest('.ilmuna-kmp-checkbox-item');
                    var kName = firstLabel ? (firstLabel.getAttribute('data-namakelas') || '') : '';
                    judulInput.value = prefix + (kName ? ' - ' + kName : '');
                } else if (count > 1) {
                    var classNames = [];
                    checkedBoxes.forEach(function(cb) {
                        var l = cb.closest('.ilmuna-kmp-checkbox-item');
                        if (l) classNames.push(l.getAttribute('data-namakelas'));
                    });
                    if (classNames.length <= 3) {
                        judulInput.value = prefix + ' - ' + classNames.join(', ');
                    } else {
                        judulInput.value = prefix + ' - ' + classNames.slice(0, 2).join(', ') + ' (+' + (classNames.length - 2) + ' Kelas)';
                    }
                } else {
                    judulInput.value = prefix;
                }
            }
        }

        if (currentAddKat === 'remedial' && typeof checkRemedialEligibilityUI === 'function') {
            checkRemedialEligibilityUI('add');
        }
    }

    document.querySelectorAll('#boxKmpChecklist .chk-kmp-item').forEach(function(chk) {
        chk.addEventListener('change', function() {
            var currentAddKat = document.getElementById('addKategori') ? document.getElementById('addKategori').value : 'sumatif';
            var jenisRem = document.getElementById('addJenisRemedial') ? document.getElementById('addJenisRemedial').value : 'Remedial';
            var isSingleMode = (currentAddKat === 'formatif' || currentAddKat === 'diagnostik' || (currentAddKat === 'remedial' && jenisRem === 'Remedial'));

            if (isSingleMode) {
                if (!this.checked) {
                    this.checked = true;
                } else {
                    var currentChk = this;
                    document.querySelectorAll('#boxKmpChecklist .chk-kmp-item').forEach(function(other) {
                        if (other !== currentChk) {
                            other.checked = false;
                            var p = other.closest('.ilmuna-kmp-checkbox-item');
                            if (p) p.classList.remove('is-checked');
                        }
                    });
                }
            }

            var parent = this.closest('.ilmuna-kmp-checkbox-item');
            if (parent) {
                if (this.checked) parent.classList.add('is-checked');
                else parent.classList.remove('is-checked');
            }
            syncKmpSelectionState();
        });
    });

    var btnSelectAllKmp = document.getElementById('btnSelectAllKmp');
    if (btnSelectAllKmp) {
        btnSelectAllKmp.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelectorAll('#boxKmpChecklist .ilmuna-kmp-checkbox-item').forEach(function(item) {
                if (item.style.display !== 'none') {
                    var chk = item.querySelector('.chk-kmp-item');
                    if (chk) chk.checked = true;
                    item.classList.add('is-checked');
                }
            });
            syncKmpSelectionState();
        });
    }

    var btnResetKmp = document.getElementById('btnResetKmp');
    if (btnResetKmp) {
        btnResetKmp.addEventListener('click', function(e) {
            e.preventDefault();
            var selModul = document.getElementById('addModulAjar');
            var opt = selModul ? selModul.options[selModul.selectedIndex] : null;
            var targetKmpId = opt ? (opt.getAttribute('data-kmp') || '') : '';

            document.querySelectorAll('#boxKmpChecklist .ilmuna-kmp-checkbox-item').forEach(function(item) {
                var chk = item.querySelector('.chk-kmp-item');
                var itemKmpId = item.getAttribute('data-kmp-id');
                if (item.style.display !== 'none' && targetKmpId && itemKmpId === targetKmpId) {
                    if (chk) chk.checked = true;
                    item.classList.add('is-checked');
                } else {
                    if (chk) chk.checked = false;
                    item.classList.remove('is-checked');
                }
            });
            syncKmpSelectionState();
        });
    }

    /**
     * Saring daftar checkbox rombel kelas (boxKmpChecklist) secara reaktif:
     * - Formatif & Diagnostik: Memilih 1 kelas target (Single selection)
     * - Remedial: Memilih 1 kelas yang memiliki siswa tidak lulus (Single selection)
     * - Sumatif: Multiple choice / paralel class selection
     */
    function filterKmpChecklist() {
        var addKat = document.getElementById('addKategori') ? document.getElementById('addKategori').value : 'sumatif';
        var jenisRemSelect = document.getElementById('addJenisRemedial');
        var jenisRem = jenisRemSelect ? jenisRemSelect.value : 'Remedial';
        var isRemedialMode = (addKat === 'remedial' && jenisRem === 'Remedial');
        var isPengayaanMode = (addKat === 'remedial' && jenisRem === 'Pengayaan');
        var isSingleMode = (addKat === 'formatif' || addKat === 'diagnostik' || isRemedialMode);

        var grid = document.getElementById('boxKmpChecklist');
        if (grid) {
            grid.setAttribute('data-mode', isSingleMode ? 'single' : 'multi');
        }

        var selModul = document.getElementById('addModulAjar');
        var opt = (selModul && selModul.selectedIndex >= 0) ? selModul.options[selModul.selectedIndex] : null;
        var selectedMapel = (opt && opt.value) ? (opt.getAttribute('data-mapel') || '') : '';

        var items = document.querySelectorAll('#boxKmpChecklist .ilmuna-kmp-checkbox-item');
        var visibleCount = 0;
        var firstCheckedItem = null;

        items.forEach(function(item) {
            var itemMapel = item.getAttribute('data-mapel') || '';
            var isEligible = item.getAttribute('data-remedial-eligible') === '1';
            var chk = item.querySelector('.chk-kmp-item');
            var badge = item.querySelector('.badge-remedial-eligible');

            var shouldShow = true;

            // Filter berdasarkan mata pelajaran jika Modul Ajar dipilih
            if (selectedMapel && itemMapel && itemMapel !== selectedMapel) {
                shouldShow = false;
            }

            // Filter Remedial: hanya tampilkan kelas yang memiliki siswa belum tuntas / tidak lulus
            if (isRemedialMode) {
                if (!isEligible) {
                    shouldShow = false;
                }
                if (badge) badge.style.display = 'inline-flex';
            } else {
                if (badge) badge.style.display = 'none';
            }

            if (shouldShow) {
                item.style.display = 'flex';
                visibleCount++;
                if (chk && chk.checked) {
                    if (isSingleMode) {
                        if (!firstCheckedItem) {
                            firstCheckedItem = item;
                        } else {
                            chk.checked = false;
                            item.classList.remove('is-checked');
                        }
                    }
                }
            } else {
                item.style.display = 'none';
                if (chk && chk.checked) {
                    chk.checked = false;
                    item.classList.remove('is-checked');
                }
            }
        });

        // Auto-check kelas pertama jika dalam mode single belum ada yang dicentang
        if (isSingleMode && !firstCheckedItem && visibleCount > 0) {
            var firstEligible = Array.from(items).find(function(i) { return i.style.display !== 'none'; });
            if (firstEligible) {
                var fChk = firstEligible.querySelector('.chk-kmp-item');
                if (fChk) {
                    fChk.checked = true;
                    firstEligible.classList.add('is-checked');
                }
            }
        }

        var headerTitle = document.getElementById('lblKmpHeaderTitle');
        var headerSub = document.getElementById('lblKmpHeaderSub');
        var iconHeader = document.getElementById('iconKmpHeader');
        var noClassAlert = document.getElementById('remedialNoClassAlert');
        var kmpActions = document.querySelector('.ilmuna-multi-kmp-actions');
        var boxRemedialAses = document.getElementById('boxRemedialSelectAsesmen');

        if (kmpActions) {
            kmpActions.style.display = isSingleMode ? 'none' : 'flex';
        }

        if (isRemedialMode) {
            if (headerTitle) {
                headerTitle.textContent = 'Langkah 1: Pilih 1 Rombel Kelas Remedial (Hanya Kelas Belum Tuntas):';
                headerTitle.style.color = '#B91C1C';
            }
            if (headerSub) {
                headerSub.textContent = 'Pilih 1 kelas yang memiliki peserta didik tidak lulus / nilai < KKTP untuk diremediasi';
                headerSub.style.color = '#DC2626';
            }
            if (iconHeader) iconHeader.style.color = '#DC2626';
            if (noClassAlert) noClassAlert.style.display = (visibleCount === 0) ? 'block' : 'none';
            if (boxRemedialAses) boxRemedialAses.style.display = 'block';
        } else if (addKat === 'formatif') {
            if (headerTitle) {
                headerTitle.textContent = 'Pilih 1 Rombel Kelas Target Formatif:';
                headerTitle.style.color = '#0D7A87';
            }
            if (headerSub) {
                headerSub.textContent = 'Formatif / LKPD / kuis ditujukan spesifik untuk 1 rombel kelas per asesmen';
                headerSub.style.color = '#64748B';
            }
            if (iconHeader) iconHeader.style.color = '#0D7A87';
            if (noClassAlert) noClassAlert.style.display = 'none';
            if (boxRemedialAses) boxRemedialAses.style.display = 'none';
        } else if (addKat === 'diagnostik') {
            if (headerTitle) {
                headerTitle.textContent = 'Pilih 1 Rombel Kelas Target Diagnostik:';
                headerTitle.style.color = '#7C3AED';
            }
            if (headerSub) {
                headerSub.textContent = 'Pemetaan kesiapan belajar kognitif/non-kognitif dilakukan spesifik per rombel kelas';
                headerSub.style.color = '#64748B';
            }
            if (iconHeader) iconHeader.style.color = '#7C3AED';
            if (noClassAlert) noClassAlert.style.display = 'none';
            if (boxRemedialAses) boxRemedialAses.style.display = 'none';
        } else if (isPengayaanMode) {
            if (headerTitle) {
                headerTitle.textContent = 'Pilihan Rombel Kelas Pengayaan:';
                headerTitle.style.color = '#D97706';
            }
            if (headerSub) {
                headerSub.textContent = 'Terbuka untuk rombel kelas yang telah tuntas materi';
                headerSub.style.color = '#B45309';
            }
            if (iconHeader) iconHeader.style.color = '#D97706';
            if (noClassAlert) noClassAlert.style.display = 'none';
            if (boxRemedialAses) boxRemedialAses.style.display = 'none';
        } else {
            var jSum = document.getElementById('addJenisSumatif') ? document.getElementById('addJenisSumatif').value : 'Sumatif Lingkup Materi';
            if (jSum === 'Sumatif Tengah Semester' || jSum === 'Sumatif Akhir Semester') {
                var tag = (jSum === 'Sumatif Tengah Semester') ? 'STS' : 'SAS / ASAS';
                if (headerTitle) {
                    headerTitle.textContent = 'Pilihan Rombel Kelas ' + tag + ' (Multiple Choice / Paralel):';
                    headerTitle.style.color = '#0D7A87';
                }
                if (headerSub) {
                    headerSub.textContent = 'Ujian semester terpadu akan diselenggarakan untuk setiap rombel kelas terpilih';
                    headerSub.style.color = '#0F766E';
                }
            } else {
                if (headerTitle) {
                    headerTitle.textContent = 'Pilihan Rombel Kelas (Multiple Choice / Paralel):';
                    headerTitle.style.color = '#1E293B';
                }
                if (headerSub) {
                    headerSub.textContent = 'Asesmen otomatis dibuatkan untuk setiap kelas terpilih';
                    headerSub.style.color = '#64748B';
                }
            }
            if (iconHeader) iconHeader.style.color = '#0D7A87';
            if (noClassAlert) noClassAlert.style.display = 'none';
            if (boxRemedialAses) boxRemedialAses.style.display = 'none';
        }

        syncKmpSelectionState();
    }

    function updateModulInfoUI(mode, fromUserInteraction) {
        var sel = document.getElementById(mode + 'ModulAjar');
        var cpInput = document.getElementById(mode + 'Cp');
        var tpInput = document.getElementById(mode + 'Tp');
        var atpInput = document.getElementById(mode + 'Atp');
        var itpInput = document.getElementById(mode + 'Itp');
        var pertemuanInput = document.getElementById(mode + 'Pertemuan');
        var judulInput = document.getElementById(mode + 'Judul');
        var infoBox = document.getElementById(mode + 'ModulInfo');
        var infoTitle = document.getElementById(mode + 'ModulInfoTitle');
        var infoDesc = document.getElementById(mode + 'ModulInfoDesc');
        var infoTags = document.getElementById(mode + 'ModulInfoTags');

        if (!sel || !infoBox) return;

        var opt = sel.options[sel.selectedIndex];
        if (opt && opt.value) {
            var cp = opt.getAttribute('data-cp') || '';
            var tp = opt.getAttribute('data-tp') || '';
            var atp = opt.getAttribute('data-atp') || '';
            var itp = opt.getAttribute('data-itp') || '';
            var kmp = opt.getAttribute('data-kmp') || '';
            var mapelId = opt.getAttribute('data-mapel') || '';
            var kktp = opt.getAttribute('data-kktp') || '75.00';
            var guru = opt.getAttribute('data-guru') || '';
            var namaGuru = opt.getAttribute('data-namaguru') || '';
            var namaKelas = opt.getAttribute('data-namakelas') || '';
            var namaMapel = opt.getAttribute('data-namamapel') || '';
            var pertemuan = opt.getAttribute('data-pertemuan') || '1';
            var kodetp = opt.getAttribute('data-kodetp') || '';
            var tptext = opt.getAttribute('data-tptext') || '';
            var judul = opt.getAttribute('data-judul') || '';

            if (cpInput) cpInput.value = cp;
            if (tpInput) tpInput.value = tp;
            if (atpInput) atpInput.value = atp;
            if (itpInput) itpInput.value = itp;

            if (mode === 'add') {
                var addKmpDisplay = document.getElementById('addKmpDisplay');
                if (addKmpDisplay) {
                    addKmpDisplay.value = (namaKelas && namaMapel) ? (namaKelas + ' • ' + namaMapel) : (namaKelas || namaMapel || '');
                }
                var addKktpEl = document.getElementById('addKktp');
                var addKktpRemedialEl = document.getElementById('addKktpRemedial');
                if (addKktpEl && kktp) addKktpEl.value = kktp;
                if (addKktpRemedialEl && kktp) addKktpRemedialEl.value = kktp;

                if (fromUserInteraction) {
                    if (pertemuanInput) pertemuanInput.value = pertemuan;
                    if (judulInput && (!judulInput.value || judulInput.value.startsWith('Asesmen -') || judulInput.value.startsWith('Sumatif -') || judulInput.value.startsWith('Formatif -') || judulInput.value.startsWith('Diagnostik -') || judulInput.value.startsWith('Remedial -'))) {
                        var currentKat = document.getElementById('addKategori') ? document.getElementById('addKategori').value : 'sumatif';
                        var prefixKat = 'Sumatif - ';
                        if (currentKat === 'formatif') prefixKat = 'Formatif - ';
                        else if (currentKat === 'diagnostik') prefixKat = 'Diagnostik - ';
                        else if (currentKat === 'remedial') prefixKat = 'Remedial - ';
                        judulInput.value = prefixKat + judul;
                    }
                    filterKmpChecklist();
                    if (kmp) {
                        var targetItem = document.querySelector('#boxKmpChecklist .ilmuna-kmp-checkbox-item[data-kmp-id="' + kmp + '"]');
                        if (targetItem && targetItem.style.display !== 'none') {
                            var chk = targetItem.querySelector('.chk-kmp-item');
                            if (chk) chk.checked = true;
                            targetItem.classList.add('is-checked');
                        }
                    }
                    syncKmpSelectionState();
                }
            } else if (mode === 'edit') {
                var editKmpDisplay = document.getElementById('editKmpDisplay');
                var editKmp = document.getElementById('editKmp');
                var editGuruDisplay = document.getElementById('editGuruDisplay');
                var editGuru = document.getElementById('editGuru');
                var editKktpEl = document.getElementById('editKktp');
                var editKktpRemedialEl = document.getElementById('editKktpRemedial');
                if (editKktpEl && kktp) editKktpEl.value = kktp;
                if (editKktpRemedialEl && kktp) editKktpRemedialEl.value = kktp;

                if (fromUserInteraction) {
                    if (pertemuanInput) pertemuanInput.value = pertemuan;
                    if (namaKelas && namaMapel && editKmpDisplay) {
                        editKmpDisplay.value = namaKelas + ' • ' + namaMapel;
                    }
                    if (kmp && editKmp) editKmp.value = kmp;
                    if (namaGuru && editGuruDisplay) editGuruDisplay.value = namaGuru;
                    if (guru && editGuru) editGuru.value = guru;
                }
            }

            var currPertemuan = (pertemuanInput && pertemuanInput.value) ? pertemuanInput.value : pertemuan;
            infoTitle.textContent = 'Modul: ' + judul;
            infoDesc.innerHTML = 'Pertemuan Ke-<strong>' + currPertemuan + '</strong>' + (kodetp ? ' &bull; TP: <strong>' + escapeHtml(kodetp) + '</strong>' : '') + (tptext ? ' &bull; <em>' + escapeHtml(tptext) + '</em>' : '');
            infoTags.innerHTML = 
                '<span class="ilmuna-modul-box__tag"><iconify-icon icon="solar:hashtag-bold"></iconify-icon> CP #' + (cp || '-') + '</span>' +
                '<span class="ilmuna-modul-box__tag"><iconify-icon icon="solar:hashtag-bold"></iconify-icon> TP #' + (tp || '-') + '</span>' +
                '<span class="ilmuna-modul-box__tag"><iconify-icon icon="solar:hashtag-bold"></iconify-icon> ATP #' + (atp || '-') + '</span>' +
                '<span class="ilmuna-modul-box__tag"><iconify-icon icon="solar:hashtag-bold"></iconify-icon> ITP #' + (itp || '-') + '</span>' +
                '<span class="ilmuna-modul-box__tag"><iconify-icon icon="solar:calendar-bold"></iconify-icon> Pertemuan Ke-' + currPertemuan + '</span>';
            infoBox.classList.add('is-visible');
        } else {
            if (cpInput) cpInput.value = '';
            if (tpInput) tpInput.value = '';
            if (atpInput) atpInput.value = '';
            if (itpInput) itpInput.value = '';
            infoBox.classList.remove('is-visible');
        }
    }

    var addModulAjar = document.getElementById('addModulAjar');
    if (addModulAjar) {
        addModulAjar.addEventListener('change', function() { updateModulInfoUI('add', true); });
    }
    var editModulAjar = document.getElementById('editModulAjar');
    if (editModulAjar) {
        editModulAjar.addEventListener('change', function() { updateModulInfoUI('edit', true); });
    }

    function openModal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.add('is-open');
    }
    function closeModal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.remove('is-open');
    }

    document.querySelectorAll('[data-close]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var target = this.getAttribute('data-close');
            closeModal(target);
        });
    });

    var btnOpenAdd = document.getElementById('btnOpenAddModal');
    if (btnOpenAdd) {
        btnOpenAdd.addEventListener('click', function() {
            document.getElementById('formAddAssess').reset();
            document.getElementById('addCp').value = '';
            document.getElementById('addTp').value = '';
            document.getElementById('addAtp').value = '';
            document.getElementById('addItp').value = '';

            var addKmpDisp = document.getElementById('addKmpDisplay');
            if (addKmpDisp) addKmpDisp.value = '';
            var addGDisplay = document.getElementById('addGuruDisplay');
            if (addGDisplay) addGDisplay.value = '';
            var addG = document.getElementById('addGuru');
            if (addG) addG.value = '';
            var addInfo = document.getElementById('addModulInfo');
            if (addInfo) addInfo.classList.remove('is-visible');

            var boxRem = document.getElementById('boxRemedialSelectAsesmen');
            if (boxRem) boxRem.style.display = 'none';
            var selAses = document.getElementById('addAsesmenSumber');
            if (selAses) selAses.innerHTML = '<option value="">-- Pilih Asesmen yang Perlu Diremidi --</option>';

            document.querySelectorAll('#boxKmpChecklist .ilmuna-kmp-checkbox-item').forEach(function(item) {
                item.style.display = 'flex';
                var chk = item.querySelector('.chk-kmp-item');
                if (chk) chk.checked = false;
                item.classList.remove('is-checked');
            });
            syncKmpSelectionState();

            setCategoryChoice('sumatif');
            openModal('modalAddAssess');
        });
    }

    function checkRemedialEligibilityUI(mode) {
        mode = mode || 'add';
        var isAdd = (mode === 'add');
        var kat = isAdd ? (document.getElementById('addKategori') ? document.getElementById('addKategori').value : 'sumatif') : (document.getElementById('editKategori') ? document.getElementById('editKategori').value : '');
        var container = document.getElementById(isAdd ? 'remedialEligibilityInfo' : 'editRemedialEligibilityInfo');
        var submitBtn = document.getElementById(isAdd ? 'btnSubmitAdd' : 'btnSubmitEdit');

        if (!container) return;

        if (kat !== 'remedial') {
            container.style.display = 'none';
            container.innerHTML = '';
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.removeAttribute('title');
            }
            return;
        }

        container.style.display = 'block';

        var jenisSelect = document.getElementById(isAdd ? 'addJenisRemedial' : 'editJenisRemedial');
        var jenisVal = jenisSelect ? jenisSelect.value : 'Remedial';

        if (jenisVal === 'Pengayaan') {
            container.innerHTML =
                '<div class="ilmuna-remedial-box ilmuna-remedial-box--info">' +
                    '<div class="ilmuna-remedial-head">' +
                        '<div class="ilmuna-remedial-title">' +
                            '<iconify-icon icon="solar:stars-minimalistic-bold" width="18"></iconify-icon>' +
                            'Program Pengayaan Materi' +
                        '</div>' +
                        '<span class="ilmuna-remedial-badge ilmuna-remedial-badge--info">Pengayaan</span>' +
                    '</div>' +
                    '<div class="ilmuna-remedial-desc">' +
                        'Program Pengayaan difokuskan untuk pendalaman materi siswa yang telah mencapai ketuntasan (KKTP). Terbuka untuk seluruh siswa di rombel kelas terpilih tanpa batasan ketidaktuntasan.' +
                    '</div>' +
                '</div>';
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.removeAttribute('title');
            }
            return;
        }

        // Ambil ID KMP terpilih
        var kmpIds = [];
        if (isAdd) {
            document.querySelectorAll('#boxKmpChecklist .chk-kmp-item:checked').forEach(function(chk) {
                var v = parseInt(chk.value, 10);
                if (v > 0) kmpIds.push(v);
            });
        } else {
            var editKmp = document.getElementById('editKmp');
            if (editKmp && parseInt(editKmp.value, 10) > 0) {
                kmpIds.push(parseInt(editKmp.value, 10));
            }
        }

        if (kmpIds.length === 0) {
            container.innerHTML =
                '<div class="ilmuna-remedial-box ilmuna-remedial-box--info">' +
                    '<div class="ilmuna-remedial-head">' +
                        '<div class="ilmuna-remedial-title">' +
                            '<iconify-icon icon="solar:info-circle-bold" width="18"></iconify-icon>' +
                            'Pilih Rombel Kelas Terlebih Dahulu' +
                        '</div>' +
                        '<span class="ilmuna-remedial-badge ilmuna-remedial-badge--info">Menunggu Kelas</span>' +
                    '</div>' +
                    '<div class="ilmuna-remedial-desc">' +
                        'Silakan centang minimal satu rombel kelas di atas untuk memverifikasi apakah ada siswa yang tidak lulus pada asesmen formatif, diagnostik, atau sumatif.' +
                    '</div>' +
                '</div>';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.setAttribute('title', 'Pilih minimal satu kelas rombel');
            }
            return;
        }

        container.innerHTML =
            '<div class="ilmuna-remedial-box ilmuna-remedial-box--info" style="display:flex;align-items:center;gap:10px;">' +
                '<span class="ilmuna-spinner" style="width:16px;height:16px;border-width:2px;"></span>' +
                '<span>Memeriksa rekam jejak kelulusan asesmen formatif, diagnostik & sumatif di kelas terpilih...</span>' +
            '</div>';

        var fd = new FormData();
        fd.append('action', 'check_remedial_eligibility');
        kmpIds.forEach(function(kid) {
            fd.append('kmp_ids[]', kid);
        });

        var pertemuanEl = document.getElementById(isAdd ? 'addPertemuan' : 'editPertemuan');
        if (pertemuanEl && pertemuanEl.value) {
            fd.append('pertemuan_ke', pertemuanEl.value);
        }

        fetch(ajaxUrl, { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (!res.success) {
                container.innerHTML =
                    '<div class="ilmuna-remedial-box ilmuna-remedial-box--ineligible">' +
                        '<div class="ilmuna-remedial-title"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> Gagal memeriksa kelayakan: ' + escapeHtml(res.message || 'Error') + '</div>' +
                    '</div>';
                if (submitBtn) submitBtn.disabled = true;
                return;
            }

            if (res.all_eligible) {
                var totalFailed = res.total_failed;
                var detailsHtml = '';

                // Isi dropdown Langkah 2: Pilih Asesmen yang Perlu Diremidi jika dalam mode Remedial
                if (isAdd && kat === 'remedial' && jenisVal === 'Remedial') {
                    var selAses = document.getElementById('addAsesmenSumber');
                    var boxSelAses = document.getElementById('boxRemedialSelectAsesmen');
                    var badgeCount = document.getElementById('lblRemedialAsesmenCount');
                    var assessments = res.assessments || [];

                    if (boxSelAses) boxSelAses.style.display = 'block';

                    if (selAses) {
                        selAses.innerHTML = '<option value="">-- Pilih Asesmen yang Perlu Diremidi --</option>';
                        if (assessments.length > 0) {
                            if (badgeCount) badgeCount.textContent = assessments.length + ' Asesmen Membutuhkan Remedial';
                            assessments.forEach(function(asItem) {
                                var opt = document.createElement('option');
                                opt.value = asItem.key;
                                opt.textContent = '[' + asItem.sumber + '] ' + asItem.judul_asesmen + ' (P-' + asItem.pertemuan_ke + ' • ' + asItem.failed_count + ' Siswa Belum Tuntas)';
                                opt.setAttribute('data-judul', asItem.judul_asesmen);
                                opt.setAttribute('data-sumber', asItem.sumber);
                                opt.setAttribute('data-pertemuan', asItem.pertemuan_ke);
                                opt.setAttribute('data-kktp', asItem.kktp);
                                opt.setAttribute('data-modul', asItem.id_modul_ajar || '');
                                opt.setAttribute('data-cp', asItem.id_cp || '');
                                opt.setAttribute('data-tp', asItem.id_tp || '');
                                opt.setAttribute('data-atp', asItem.id_atp || '');
                                opt.setAttribute('data-itp', asItem.id_itp || '');
                                opt.setAttribute('data-failed-count', asItem.failed_count);
                                selAses.appendChild(opt);
                            });

                            // Otomatis pilih asesmen pertama dan sinkronkan judul asesmen
                            selAses.selectedIndex = 1;
                            applySelectedRemedialAssessment(selAses.options[1]);
                        } else {
                            if (badgeCount) badgeCount.textContent = '0 Asesmen';
                            var optNone = document.createElement('option');
                            optNone.value = '';
                            optNone.textContent = '(Tidak ditemukan asesmen dengan siswa tidak lulus di kelas ini)';
                            selAses.appendChild(optNone);
                        }
                    }
                } else if (isAdd) {
                    var boxSelAses = document.getElementById('boxRemedialSelectAsesmen');
                    if (boxSelAses) boxSelAses.style.display = 'none';
                }

                res.classes.forEach(function(cls) {
                    if (cls.students && cls.students.length > 0) {
                        detailsHtml += '<div style="margin-top:8px;">';
                        detailsHtml += '<div style="font-weight:700;color:#065F46;margin-bottom:4px;display:flex;align-items:center;gap:5px;"><iconify-icon icon="solar:users-group-two-rounded-bold" width="14"></iconify-icon> ' + escapeHtml(cls.nama_kelas) + ' (' + cls.students.length + ' siswa tidak lulus)</div>';
                        detailsHtml += '<table class="ilmuna-remedial-student-table"><thead><tr><th>Nama Siswa</th><th>NIS/NISN</th><th>Asesmen Terkait</th><th>Nilai & KKTP</th><th>Status</th></tr></thead><tbody>';
                        cls.students.forEach(function(s) {
                            var testTitles = [];
                            var testScores = [];
                            var testStatuses = [];
                            s.details.forEach(function(dt) {
                                testTitles.push(escapeHtml(dt.sumber) + ': ' + escapeHtml(dt.judul_asesmen));
                                testScores.push('<strong>' + dt.nilai_angka + '</strong> (KKTP: ' + dt.nilai_kktp + ')');
                                testStatuses.push('<span style="color:#DC2626;font-weight:600;">' + escapeHtml(dt.status_tuntas) + '</span>');
                            });
                            detailsHtml += '<tr>' +
                                '<td><strong>' + escapeHtml(s.nama_lengkap) + '</strong></td>' +
                                '<td style="color:#64748B;">' + escapeHtml(s.nis || s.nisn || '-') + '</td>' +
                                '<td>' + testTitles.join('<br>') + '</td>' +
                                '<td>' + testScores.join('<br>') + '</td>' +
                                '<td>' + testStatuses.join('<br>') + '</td>' +
                            '</tr>';
                        });
                        detailsHtml += '</tbody></table></div>';
                    }
                });

                container.innerHTML =
                    '<div class="ilmuna-remedial-box ilmuna-remedial-box--eligible">' +
                        '<div class="ilmuna-remedial-head">' +
                            '<div class="ilmuna-remedial-title">' +
                                '<iconify-icon icon="solar:check-circle-bold" width="18" style="color:#059669;"></iconify-icon>' +
                                'Syarat Remedial Terpenuhi: Layak Ditambahkan' +
                            '</div>' +
                            '<span class="ilmuna-remedial-badge ilmuna-remedial-badge--success">' + totalFailed + ' Siswa Tidak Lulus</span>' +
                        '</div>' +
                        '<div class="ilmuna-remedial-desc">' +
                            'Ditemukan <strong>' + totalFailed + ' siswa</strong> yang tidak lulus (nilai di bawah KKTP) pada asesmen formatif, diagnostik, maupun sumatif di kelas terpilih. Program Remedial diizinkan untuk dibuat.' +
                        '</div>' +
                        '<button type="button" class="ilmuna-remedial-toggle-btn" id="' + mode + 'ToggleFailedList">' +
                            '<iconify-icon icon="solar:eye-bold" width="14"></iconify-icon>' +
                            '<span>Lihat Rincian Siswa Tidak Lulus</span>' +
                        '</button>' +
                        '<div id="' + mode + 'FailedListWrap" style="display:none;margin-top:6px;">' +
                            detailsHtml +
                        '</div>' +
                    '</div>';

                var toggleBtn = document.getElementById(mode + 'ToggleFailedList');
                var wrapList = document.getElementById(mode + 'FailedListWrap');
                if (toggleBtn && wrapList) {
                    toggleBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (wrapList.style.display === 'none') {
                            wrapList.style.display = 'block';
                            toggleBtn.querySelector('span').textContent = 'Sembunyikan Rincian Siswa';
                        } else {
                            wrapList.style.display = 'none';
                            toggleBtn.querySelector('span').textContent = 'Lihat Rincian Siswa Tidak Lulus';
                        }
                    });
                }

                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.removeAttribute('title');
                }
            } else {
                var inelText = res.ineligible_classes.length > 0 ? res.ineligible_classes.join(', ') : 'Kelas terpilih';
                if (isAdd) {
                    var boxSelAses = document.getElementById('boxRemedialSelectAsesmen');
                    if (boxSelAses) boxSelAses.style.display = 'none';
                }
                container.innerHTML =
                    '<div class="ilmuna-remedial-box ilmuna-remedial-box--ineligible">' +
                        '<div class="ilmuna-remedial-head">' +
                            '<div class="ilmuna-remedial-title">' +
                                '<iconify-icon icon="solar:danger-triangle-bold" width="18" style="color:#DC2626;"></iconify-icon>' +
                                'Tidak Memenuhi Syarat Remedial' +
                            '</div>' +
                            '<span class="ilmuna-remedial-badge ilmuna-remedial-badge--danger">Semua Siswa Lulus</span>' +
                        '</div>' +
                        '<div class="ilmuna-remedial-desc">' +
                            'Kelas <strong>' + escapeHtml(inelText) + '</strong> tidak dapat ditambahkan program remedial karena <strong>tidak ditemukan siswa yang tidak lulus</strong> (seluruh siswa tuntas &ge; KKTP) pada asesmen formatif, diagnostik, maupun sumatif.<br>' +
                            '<strong style="color:#B91C1C;">Patokan Remedial:</strong> Remedial hanya dapat ditambahkan apabila terdapat siswa yang tidak lulus.' +
                        '</div>' +
                    '</div>';

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.setAttribute('title', 'Remedial tidak dapat dibuat karena tidak ada siswa yang tidak lulus di kelas ini');
                }
            }
        })
        .catch(function(err) {
            container.innerHTML =
                '<div class="ilmuna-remedial-box ilmuna-remedial-box--ineligible">' +
                    '<div class="ilmuna-remedial-title"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> Terjadi kesalahan saat memeriksa kelayakan remedial.</div>' +
                '</div>';
            if (submitBtn) submitBtn.disabled = true;
        });
    }

    /**
     * Terapkan asesmen yang dipilih dari Langkah 2 Remedial ke kolom input modal:
     * - Judul Asesmen otomatis menjadi "Remedial - [Judul Asesmen]"
     * - Pertemuan Ke- otomatis diselaraskan
     * - Acuan Modul Ajar otomatis diselaraskan
     */
    function applySelectedRemedialAssessment(opt) {
        if (!opt || !opt.value) return;
        var judul = opt.getAttribute('data-judul') || '';
        var pertemuan = opt.getAttribute('data-pertemuan') || '1';
        var modulId = opt.getAttribute('data-modul') || '';
        var cp = opt.getAttribute('data-cp') || '';
        var tp = opt.getAttribute('data-tp') || '';
        var atp = opt.getAttribute('data-atp') || '';
        var itp = opt.getAttribute('data-itp') || '';
        var kktp = opt.getAttribute('data-kktp') || '';

        var judulInput = document.getElementById('addJudul');
        if (judulInput && judul) {
            judulInput.value = 'Remedial - ' + judul;
        }

        var pertemuanInput = document.getElementById('addPertemuan');
        if (pertemuanInput && pertemuan) {
            pertemuanInput.value = pertemuan;
        }

        var cpInput = document.getElementById('addCp');
        var tpInput = document.getElementById('addTp');
        var atpInput = document.getElementById('addAtp');
        var itpInput = document.getElementById('addItp');
        if (cpInput && cp) cpInput.value = cp;
        if (tpInput && tp) tpInput.value = tp;
        if (atpInput && atp) atpInput.value = atp;
        if (itpInput && itp) itpInput.value = itp;

        var kktpRemEl = document.getElementById('addKktpRemedial');
        if (kktpRemEl && kktp) kktpRemEl.value = kktp;

        if (modulId) {
            var selModul = document.getElementById('addModulAjar');
            if (selModul) {
                selModul.value = modulId;
                updateModulInfoUI('add', false);
            }
        }
    }

    var selAsesSumberEl = document.getElementById('addAsesmenSumber');
    if (selAsesSumberEl) {
        selAsesSumberEl.addEventListener('change', function() {
            var opt = this.options[this.selectedIndex];
            applySelectedRemedialAssessment(opt);
        });
    }

    /**
     * Reaktif menyesuaikan UI Asesmen Sumatif:
     * - Sumatif Tengah Semester (STS) & Akhir Semester (SAS / ASAS): Ujian terpadu menyeluruh, TIDAK berdasarkan modul ajar (sembunyikan Acuan Modul Ajar, tampilkan banner info semester, buat judul otomatis berbasis kelas).
     * - Sumatif Lingkup Materi (LM): Tampilkan Acuan Modul Ajar untuk memilih lingkup materi per bab / TP.
     */
    function updateSumatifTypeUI(mode) {
        var isAdd = (mode === 'add');
        var katEl = document.getElementById(isAdd ? 'addKategori' : 'editKategori');
        var kat = katEl ? katEl.value : 'sumatif';

        var boxSumInfo = document.getElementById(isAdd ? 'boxSumatifSemesterInfoAdd' : 'boxSumatifSemesterInfoEdit');
        var boxMA = document.getElementById(isAdd ? 'boxModulAjarAdd' : 'boxModulAjarEdit');

        if (kat !== 'sumatif') {
            if (boxSumInfo) boxSumInfo.style.display = 'none';
            if (boxMA) boxMA.style.display = 'block';
            return;
        }

        var jenisEl = document.getElementById(isAdd ? 'addJenisSumatif' : 'editJenisSumatif');
        var jenis = jenisEl ? jenisEl.value : 'Sumatif Lingkup Materi';
        var lblTitle = document.getElementById(isAdd ? 'lblSumatifSemesterTitleAdd' : 'lblSumatifSemesterTitleEdit');
        var lblDesc = document.getElementById(isAdd ? 'lblSumatifSemesterDescAdd' : 'lblSumatifSemesterDescEdit');
        var selMA = document.getElementById(isAdd ? 'addModulAjar' : 'editModulAjar');
        var cpInput = document.getElementById(isAdd ? 'addCp' : 'editCp');
        var tpInput = document.getElementById(isAdd ? 'addTp' : 'editTp');
        var atpInput = document.getElementById(isAdd ? 'addAtp' : 'editAtp');
        var itpInput = document.getElementById(isAdd ? 'addItp' : 'editItp');
        var pertemuanInput = document.getElementById(isAdd ? 'addPertemuan' : 'editPertemuan');
        var judulInput = document.getElementById(isAdd ? 'addJudul' : 'editJudul');

        var isSemesterExam = (jenis === 'Sumatif Tengah Semester' || jenis === 'Sumatif Akhir Semester');

        if (isSemesterExam) {
            if (boxMA) boxMA.style.display = 'none';
            if (boxSumInfo) boxSumInfo.style.display = 'block';

            if (selMA) selMA.value = '';
            if (cpInput) cpInput.value = '';
            if (tpInput) tpInput.value = '';
            if (atpInput) atpInput.value = '';
            if (itpInput) itpInput.value = '';

            var maInfoBox = document.getElementById(isAdd ? 'addModulInfo' : 'editModulInfo');
            if (maInfoBox) maInfoBox.classList.remove('is-active');

            if (jenis === 'Sumatif Tengah Semester') {
                if (lblTitle) lblTitle.textContent = 'Asesmen Sumatif Tengah Semester (STS)';
                if (lblDesc) lblDesc.textContent = 'STS menguji seluruh capaian pembelajaran yang telah dipelajari selama setengah semester (tidak terikat pada satu modul ajar tertentu). Paket ujian dibuat langsung berdasarkan rombel kelas terpilih di atas.';
                if (pertemuanInput && isAdd && (!pertemuanInput.value || pertemuanInput.value === '1' || pertemuanInput.value === '16')) {
                    pertemuanInput.value = 8;
                }
                if (judulInput) {
                    judulInput.placeholder = 'Contoh: Sumatif Tengah Semester (STS) PAI - Kelas X';
                }
            } else {
                if (lblTitle) lblTitle.textContent = 'Asesmen Sumatif Akhir Semester (SAS / ASAS)';
                if (lblDesc) lblDesc.textContent = 'SAS / ASAS menguji seluruh kompetensi dan materi pembelajaran dalam satu semester penuh (tidak terikat pada satu modul ajar tertentu). Paket ujian dibuat langsung berdasarkan rombel kelas terpilih di atas.';
                if (pertemuanInput && isAdd && (!pertemuanInput.value || pertemuanInput.value === '1' || pertemuanInput.value === '8')) {
                    pertemuanInput.value = 16;
                }
                if (judulInput) {
                    judulInput.placeholder = 'Contoh: Sumatif Akhir Semester (SAS) PAI - Kelas X';
                }
            }
        } else {
            if (boxMA) boxMA.style.display = 'block';
            if (boxSumInfo) boxSumInfo.style.display = 'none';
            if (pertemuanInput && isAdd && (pertemuanInput.value === '8' || pertemuanInput.value === '16')) {
                pertemuanInput.value = 1;
            }
            if (judulInput) {
                judulInput.placeholder = 'Contoh: Asesmen Sumatif Bab 1: Meraih Ketenteraman Hati dengan Zikir';
            }
        }
    }

    function setCategoryChoice(type) {
        document.getElementById('addKategori').value = type;
        document.querySelectorAll('.ilmuna-type-choice').forEach(function(c) {
            if (c.getAttribute('data-type') === type) c.classList.add('is-selected');
            else c.classList.remove('is-selected');
        });

        // 1. Tampilkan box parameter khusus sesuai kategori
        document.getElementById('boxFieldSumatif').style.display = (type === 'sumatif') ? 'grid' : 'none';
        document.getElementById('boxFieldFormatif').style.display = (type === 'formatif') ? 'grid' : 'none';
        document.getElementById('boxFieldDiagnostik').style.display = (type === 'diagnostik') ? 'grid' : 'none';
        document.getElementById('boxFieldRemedial').style.display = (type === 'remedial') ? 'grid' : 'none';

        // 2. Reaktif sesuaikan komponen Sumatif (STS/SAS non-modul ajar vs LM modul ajar)
        updateSumatifTypeUI('add');

        // 3. Sesuaikan placeholder Judul Asesmen
        var judulInput = document.getElementById('addJudul');
        if (judulInput) {
            if (type === 'sumatif') {
                var jSum = document.getElementById('addJenisSumatif') ? document.getElementById('addJenisSumatif').value : 'Sumatif Lingkup Materi';
                if (jSum === 'Sumatif Tengah Semester') {
                    judulInput.placeholder = 'Contoh: Sumatif Tengah Semester (STS) PAI - Kelas X';
                } else if (jSum === 'Sumatif Akhir Semester') {
                    judulInput.placeholder = 'Contoh: Sumatif Akhir Semester (SAS) PAI - Kelas X';
                } else {
                    judulInput.placeholder = 'Contoh: Asesmen Sumatif Bab 1: Meraih Ketenteraman Hati dengan Zikir';
                }
            } else if (type === 'formatif') {
                judulInput.placeholder = 'Contoh: Formatif KBM / LKPD 1: Membaca & Memahami QS Al-Hujurat';
            } else if (type === 'diagnostik') {
                judulInput.placeholder = 'Contoh: Asesmen Diagnostik Awal: Pemetaan Kesiapan Belajar PAI';
            } else if (type === 'remedial') {
                var jRem = document.getElementById('addJenisRemedial') ? document.getElementById('addJenisRemedial').value : 'Remedial';
                judulInput.placeholder = (jRem === 'Pengayaan') 
                    ? 'Contoh: Program Pengayaan: Eksplorasi Lanjutan Studi PAI' 
                    : 'Contoh: Asesmen Remedial: Pemantapan Kompetensi Siswa Belum Tuntas';
            }
        }

        // 4. Sesuaikan placeholder Petunjuk Pengerjaan
        var petunjukInput = document.getElementById('addPetunjuk');
        if (petunjukInput) {
            if (type === 'sumatif') {
                petunjukInput.placeholder = 'Tulis instruksi ujian CBT bagi peserta didik, ketentuan durasi, dan tata tertib kejujuran...';
            } else if (type === 'formatif') {
                petunjukInput.placeholder = 'Instruksi pengerjaan lembar kerja / kuis formatif untuk memantau proses belajar...';
            } else if (type === 'diagnostik') {
                petunjukInput.placeholder = 'Jawablah asesmen diagnostik ini apa adanya sesuai kondisi kesiapan awal Anda...';
            } else if (type === 'remedial') {
                petunjukInput.placeholder = 'Petunjuk pengerjaan remedial / pengayaan bagi peserta didik terpilih...';
            }
        }

        // 5. Saring rombel kelas secara reaktif (Remedial hanya menampilkan kelas dengan siswa tidak lulus)
        filterKmpChecklist();

        // 6. Cek kelayakan remedial jika memilih tab Remedial
        if (type === 'remedial') {
            checkRemedialEligibilityUI('add');
        } else {
            var boxRemAs = document.getElementById('boxRemedialSelectAsesmen');
            if (boxRemAs) boxRemAs.style.display = 'none';
            var remBox = document.getElementById('remedialEligibilityInfo');
            if (remBox) { remBox.style.display = 'none'; remBox.innerHTML = ''; }
            var btnAdd = document.getElementById('btnSubmitAdd');
            if (btnAdd) { btnAdd.disabled = false; btnAdd.removeAttribute('title'); }
        }
    }

    var addJenisSumEl = document.getElementById('addJenisSumatif');
    if (addJenisSumEl) {
        addJenisSumEl.addEventListener('change', function() {
            updateSumatifTypeUI('add');
            var jSum = this.value;
            var judulInput = document.getElementById('addJudul');
            if (jSum === 'Sumatif Tengah Semester' || jSum === 'Sumatif Akhir Semester') {
                // Bersihkan judul lama jika berupa judul default agar di-generate otomatis sesuai rombel kelas
                if (judulInput && (judulInput.value.startsWith('Sumatif') || judulInput.value.startsWith('Asesmen') || !judulInput.value)) {
                    judulInput.value = '';
                }
            }
            filterKmpChecklist();
            syncKmpSelectionState();
        });
    }
    var editJenisSumEl = document.getElementById('editJenisSumatif');
    if (editJenisSumEl) {
        editJenisSumEl.addEventListener('change', function() {
            updateSumatifTypeUI('edit');
        });
    }

    var addJenisRemEl = document.getElementById('addJenisRemedial');
    if (addJenisRemEl) {
        addJenisRemEl.addEventListener('change', function() {
            var jRem = this.value;
            var judulInput = document.getElementById('addJudul');
            if (judulInput) {
                judulInput.placeholder = (jRem === 'Pengayaan') 
                    ? 'Contoh: Program Pengayaan: Eksplorasi Lanjutan Studi PAI' 
                    : 'Contoh: Asesmen Remedial: Pemantapan Kompetensi Siswa Belum Tuntas';
            }
            filterKmpChecklist();
            checkRemedialEligibilityUI('add');
        });
    }
    var editJenisRemEl = document.getElementById('editJenisRemedial');
    if (editJenisRemEl) {
        editJenisRemEl.addEventListener('change', function() {
            checkRemedialEligibilityUI('edit');
        });
    }

    var addPertemuanEl = document.getElementById('addPertemuan');
    if (addPertemuanEl) {
        addPertemuanEl.addEventListener('change', function() {
            var currKat = document.getElementById('addKategori') ? document.getElementById('addKategori').value : '';
            if (currKat === 'remedial') {
                checkRemedialEligibilityUI('add');
            }
        });
    }

    document.querySelectorAll('.ilmuna-type-choice').forEach(function(c) {
        c.addEventListener('click', function(e) {
            e.preventDefault();
            var type = this.getAttribute('data-type');
            if (type) {
                setCategoryChoice(type);
            }
        });
    });

    categoryPills.forEach(function(btn) {
        btn.addEventListener('click', function() {
            categoryPills.forEach(function(b) { b.classList.remove('is-active'); });
            this.classList.add('is-active');
            state.kategori = this.getAttribute('data-val');
            state.page = 1;
            fetchList();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var val = this.value;
            if (val.length > 0) btnSearchClear.classList.add('has-val');
            else btnSearchClear.classList.remove('has-val');
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() {
                state.search = val.trim();
                state.page = 1;
                fetchList();
            }, 220);
        });
    }

    if (btnSearchClear) {
        btnSearchClear.addEventListener('click', function() {
            searchInput.value = '';
            btnSearchClear.classList.remove('has-val');
            state.search = '';
            state.page = 1;
            fetchList();
            searchInput.focus();
        });
    }

    if (filterStatus) {
        filterStatus.addEventListener('change', function() {
            state.status = this.value;
            state.page = 1;
            fetchList();
        });
    }
    if (filterKelas) {
        filterKelas.addEventListener('change', function() {
            state.kelasId = parseInt(this.value, 10) || 0;
            state.page = 1;
            fetchList();
        });
    }

    function fetchList() {
        tbody.innerHTML = '<tr><td colspan="9" class="ilmuna-loading-row"><span class="ilmuna-spinner"></span> Memuat paket asesmen...</td></tr>';

        var formData = new FormData();
        formData.append('action', 'list');
        formData.append('kategori', state.kategori);
        formData.append('status', state.status);
        formData.append('kelas_id', state.kelasId);
        formData.append('search', state.search);
        formData.append('page', state.page);
        formData.append('per_page', state.perPage);

        fetch(ajaxUrl, { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (!res.success) {
                tbody.innerHTML = '<tr><td colspan="9" class="ilmuna-empty-state"><iconify-icon class="ilmuna-empty-state__icon" icon="solar:danger-triangle-bold"></iconify-icon><h4>Gagal Memuat</h4><p>' + escapeHtml(res.message) + '</p></td></tr>';
                return;
            }
            var d = res.data;
            updateKpi(d.stats);
            renderTable(d.rows, d.page, d.per_page, d.total);
            renderPagination(d.page, d.pages, d.total, d.per_page);
        })
        .catch(function(err) {
            tbody.innerHTML = '<tr><td colspan="9" class="ilmuna-empty-state"><iconify-icon class="ilmuna-empty-state__icon" icon="solar:danger-triangle-bold"></iconify-icon><h4>Koneksi Terganggu</h4><p>' + escapeHtml(err.message) + '</p></td></tr>';
        });
    }

    function updateKpi(stats) {
        if (!stats) return;

        var elKpiAll        = document.getElementById('kpiTotalAll');
        var elKpiSumatif    = document.getElementById('kpiTotalSumatif');
        var elKpiFormatif   = document.getElementById('kpiTotalFormatif');
        var elKpiDiagnostik = document.getElementById('kpiTotalDiagnostik');
        if (elKpiAll)        elKpiAll.textContent        = stats.total_all;
        if (elKpiSumatif)    elKpiSumatif.textContent    = stats.total_sumatif;
        if (elKpiFormatif)   elKpiFormatif.textContent   = stats.total_formatif;
        if (elKpiDiagnostik) elKpiDiagnostik.textContent = stats.total_diagnostik;

        var pAll  = document.getElementById('pillCountAll');
        var pSum  = document.getElementById('pillCountSumatif');
        var pFmt  = document.getElementById('pillCountFormatif');
        var pDiag = document.getElementById('pillCountDiagnostik');
        var pRem  = document.getElementById('pillCountRemedial');
        if (pAll)  pAll.textContent  = stats.total_all;
        if (pSum)  pSum.textContent  = stats.total_sumatif;
        if (pFmt)  pFmt.textContent  = stats.total_formatif;
        if (pDiag) pDiag.textContent = stats.total_diagnostik;
        if (pRem)  pRem.textContent  = stats.total_remedial || 0;
    }

    function renderTable(rows, page, perPage, total) {
        document.getElementById('lblTotalData').textContent = total;

        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="ilmuna-empty-state"><iconify-icon class="ilmuna-empty-state__icon" icon="solar:document-text-outline"></iconify-icon><h4>Tidak Ada Paket Asesmen</h4><p>Belum ada asesmen yang sesuai dengan filter atau pencarian Anda. Klik tombol Tambah Asesmen Baru untuk membuat paket evaluasi.</p></td></tr>';
            updateBulkBar();
            return;
        }

        var html = '';
        var startNo = (page - 1) * perPage + 1;

        rows.forEach(function(r, idx) {
            var no = startNo + idx;
            var itemKey = r.kategori + ':' + r.id;
            var isChecked = selectedItems.has(itemKey);
            var rowClass = isChecked ? ' class="is-selected"' : '';

            var badgeKat = '';
            if (r.kategori === 'sumatif') {
                badgeKat = '<span class="ilmuna-badge ilmuna-badge--sumatif"><iconify-icon icon="solar:laptop-minimalistic-bold" width="13"></iconify-icon> Sumatif CBT</span>';
            } else if (r.kategori === 'formatif') {
                badgeKat = '<span class="ilmuna-badge ilmuna-badge--formatif"><iconify-icon icon="solar:checklist-minimalistic-bold" width="13"></iconify-icon> Formatif</span>';
            } else if (r.kategori === 'remedial') {
                var isPengayaan = (r.tipe_khusus === 'Pengayaan');
                badgeKat = '<span class="ilmuna-badge" style="background: ' + (isPengayaan ? 'rgba(16,185,129,0.12); color:#047857;' : 'rgba(239,68,68,0.12); color:#B91C1C;') + '"><iconify-icon icon="solar:medal-ribbon-star-bold" width="13"></iconify-icon> ' + (isPengayaan ? 'Pengayaan' : 'Remedial') + '</span>';
            } else {
                badgeKat = '<span class="ilmuna-badge ilmuna-badge--diagnostik"><iconify-icon icon="solar:tuning-square-2-bold" width="13"></iconify-icon> Diagnostik</span>';
            }

            var badgeStatus = '';
            if (r.status === 'Aktif') {
                badgeStatus = '<span class="ilmuna-badge ilmuna-badge--aktif"><span class="ilmuna-status-dot"></span> Aktif</span>';
            } else if (r.status === 'Draft') {
                badgeStatus = '<span class="ilmuna-badge ilmuna-badge--draft"><span class="ilmuna-status-dot"></span> Draft</span>';
            } else {
                badgeStatus = '<span class="ilmuna-badge ilmuna-badge--ditutup"><span class="ilmuna-status-dot"></span> Ditutup</span>';
            }

            var paramHtml = '';
            var durasiDisplay = (r.durasi_menit || 60) + ' Menit';
            if (r.kategori === 'sumatif') {
                paramHtml = '<div style="font-size: 11.5px; line-height: 1.4;">' +
                            '<div>Durasi: <strong>' + durasiDisplay + '</strong></div>' +
                            '<div>KKTP: <strong>' + r.kktp + '</strong></div>' +
                            '<div style="color: #64748B;">' + r.total_soal + ' Soal &bull; ' + r.total_tiket + ' Tiket</div>' +
                            '</div>';
            } else if (r.kategori === 'formatif') {
                paramHtml = '<div style="font-size: 11.5px; line-height: 1.4;">' +
                            '<div>Durasi: <strong>' + durasiDisplay + '</strong></div>' +
                            '<div>Teknik: <strong>' + escapeHtml(r.tipe_khusus) + '</strong></div>' +
                            '<div style="color: #64748B;">' + r.total_soal + ' Soal &bull; ' + r.total_tiket + ' Tiket</div>' +
                            '</div>';
            } else if (r.kategori === 'remedial') {
                paramHtml = '<div style="font-size: 11.5px; line-height: 1.4;">' +
                            '<div>Durasi: <strong>' + durasiDisplay + '</strong></div>' +
                            '<div>KKTP: <strong>' + r.kktp + '</strong></div>' +
                            '<div style="color: #64748B;">' + r.total_soal + ' Soal &bull; ' + r.total_tiket + ' Akun</div>' +
                            '</div>';
            } else {
                paramHtml = '<div style="font-size: 11.5px; line-height: 1.4;">' +
                            '<div>Durasi: <strong>' + durasiDisplay + '</strong></div>' +
                            '<div>Tipe: <strong>' + escapeHtml(r.tipe_khusus) + '</strong></div>' +
                            '<div style="color: #64748B;">' + r.total_soal + ' Butir Soal</div>' +
                            '</div>';
            }

            var cbtAccessBtn = '';
            if (r.kategori !== 'diagnostik') {
                cbtAccessBtn = '<a href="<?= BASE_URL ?>/dashboard/admin/assessment-access" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--outline" style="background: rgba(13, 122, 135, 0.1); color: #0D7A87; border: 1px solid rgba(13, 122, 135, 0.25);" title="Kelola Tiket & Kredensial Siswa">' +
                               '<iconify-icon icon="solar:key-bold" width="14"></iconify-icon>' +
                               '</a>';
            }

            var modulBadge = '';
            if (r.judul_modul) {
                modulBadge = '<div style="margin-top: 5px; display: inline-flex; align-items: center; gap: 5px; background: rgba(13, 122, 135, 0.08); border: 1px solid rgba(13, 122, 135, 0.22); padding: 2px 8px; border-radius: 6px; font-size: 11px; color: #0D7A87; font-weight: 600;">' +
                             '<iconify-icon icon="solar:book-bookmark-bold" width="13"></iconify-icon>' +
                             '<span>Modul: ' + escapeHtml(r.judul_modul) + ' &bull; P-' + r.pertemuan_ke + (r.kode_tp ? ' &bull; TP: ' + escapeHtml(r.kode_tp) : '') + '</span>' +
                             '</div>';
            }

            var scheduleBadge = '';
            if (r.tanggal_pelaksanaan) {
                var tglParts = r.tanggal_pelaksanaan.split('-');
                var tglFmt = r.tanggal_pelaksanaan;
                if (tglParts.length === 3) {
                    var blnNames = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
                    var bIdx = parseInt(tglParts[1], 10);
                    tglFmt = tglParts[2] + ' ' + (blnNames[bIdx] || tglParts[1]) + ' ' + tglParts[0];
                }
                var jmMulai = r.jam_mulai ? r.jam_mulai.substring(0, 5) : '07:30';
                var jmSelesai = r.jam_selesai ? r.jam_selesai.substring(0, 5) : '09:00';
                scheduleBadge = '<div style="margin-top: 5px; display: inline-flex; align-items: center; gap: 5px; background: rgba(217, 119, 6, 0.08); border: 1px solid rgba(217, 119, 6, 0.25); padding: 2px 8px; border-radius: 6px; font-size: 11px; color: #B45309; font-weight: 700;">' +
                                '<iconify-icon icon="solar:calendar-date-bold" width="13"></iconify-icon>' +
                                '<span>' + tglFmt + ' &bull; ' + jmMulai + ' - ' + jmSelesai + ' WIB</span>' +
                                '</div>';
            }

            var statusActionBtn = '';
            if (r.status === 'Draft') {
                statusActionBtn = '<button type="button" class="btn-change-status" data-kat="' + r.kategori + '" data-id="' + r.id + '" data-target="Aktif" title="Aktifkan asesmen ini agar dapat diikuti peserta didik" style="display:inline-flex;align-items:center;gap:4px;padding:3.5px 10px;border-radius:6px;border:1px solid #10B981;background:rgba(16,185,129,0.12);color:#047857;font-size:11px;font-weight:700;cursor:pointer;transition:all 0.15s ease;">' +
                                  '<iconify-icon icon="solar:play-circle-bold" width="13"></iconify-icon> Aktifkan' +
                                  '</button>';
            } else if (r.status === 'Aktif') {
                statusActionBtn = '<button type="button" class="btn-change-status" data-kat="' + r.kategori + '" data-id="' + r.id + '" data-target="Ditutup" title="Tutup pelaksanaan asesmen ini" style="display:inline-flex;align-items:center;gap:4px;padding:3.5px 10px;border-radius:6px;border:1px solid #EF4444;background:rgba(239,68,68,0.12);color:#B91C1C;font-size:11px;font-weight:700;cursor:pointer;transition:all 0.15s ease;">' +
                                  '<iconify-icon icon="solar:stop-circle-bold" width="13"></iconify-icon> Tutup' +
                                  '</button>';
            } else { // Ditutup
                statusActionBtn = '<div style="display:inline-flex;gap:4px;flex-wrap:wrap;">' +
                                  '<button type="button" class="btn-change-status" data-kat="' + r.kategori + '" data-id="' + r.id + '" data-target="Aktif" title="Aktifkan kembali asesmen ini" style="display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:6px;border:1px solid #10B981;background:rgba(16,185,129,0.1);color:#047857;font-size:10.5px;font-weight:700;cursor:pointer;">' +
                                  '<iconify-icon icon="solar:play-circle-bold" width="12"></iconify-icon> Aktifkan' +
                                  '</button>' +
                                  '<button type="button" class="btn-change-status" data-kat="' + r.kategori + '" data-id="' + r.id + '" data-target="Draft" title="Kembalikan ke status Draft untuk diedit" style="display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:6px;border:1px solid #64748B;background:rgba(100,116,139,0.1);color:#475569;font-size:10.5px;font-weight:700;cursor:pointer;">' +
                                  '<iconify-icon icon="solar:restart-bold" width="12"></iconify-icon> Draft' +
                                  '</button>' +
                                  '</div>';
            }

            var editBtn = '';
            if (r.status === 'Draft') {
                editBtn = '<button type="button" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--ghost btn-edit-assess" data-kat="' + r.kategori + '" data-id="' + r.id + '" title="Edit Data Asesmen (Hanya status Draft)">' +
                          '<iconify-icon icon="solar:pen-new-square-bold" width="14"></iconify-icon>' +
                          '</button>';
            } else {
                var editMsg = (r.status === 'Aktif') ? 'Asesmen sedang aktif tidak dapat diedit. Ubah status ke Draft terlebih dahulu.' : 'Asesmen yang sudah ditutup tidak dapat diedit. Buka ke Draft terlebih dahulu jika ingin mengedit.';
                editBtn = '<button type="button" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--ghost" disabled title="' + editMsg + '" style="opacity: 0.35; cursor: not-allowed; color: #94A3B8;">' +
                          '<iconify-icon icon="solar:pen-new-square-bold" width="14"></iconify-icon>' +
                          '</button>';
            }

            var deleteBtn = '';
            if (r.status === 'Aktif') {
                deleteBtn = '<button type="button" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--ghost" disabled title="Asesmen sedang AKTIF tidak dapat dihapus! Tutup asesmen terlebih dahulu." style="color: #EF4444; opacity: 0.35; cursor: not-allowed;">' +
                            '<iconify-icon icon="solar:trash-bin-trash-bold" width="14"></iconify-icon>' +
                            '</button>';
            } else {
                deleteBtn = '<button type="button" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--ghost btn-del-assess" data-kat="' + r.kategori + '" data-id="' + r.id + '" data-judul="' + escapeHtml(r.judul_asesmen) + '" style="color: #EF4444;" title="Hapus Asesmen Beserta Seluruh Data Terkait">' +
                            '<iconify-icon icon="solar:trash-bin-trash-bold" width="14"></iconify-icon>' +
                            '</button>';
            }

            html += '<tr' + rowClass + '>' +
                '<td style="text-align: center;">' +
                    '<input type="checkbox" class="ilmuna-bulk-cb row-assess-cb" data-kat="' + r.kategori + '" data-id="' + r.id + '" value="' + itemKey + '"' + (isChecked ? ' checked' : '') + '>' +
                '</td>' +
                '<td style="font-weight: 700; color: #94A3B8;">' + no + '</td>' +
                '<td>' +
                    '<div class="ilmuna-assess-title-cell">' +
                        '<span class="ilmuna-assess-title">' + escapeHtml(r.judul_asesmen) + '</span>' +
                        '<div class="ilmuna-assess-meta">' +
                            '<span>ID: #' + r.id + '</span> &bull; ' +
                            '<span>' + (r.tipe_khusus ? escapeHtml(r.tipe_khusus) : 'Umum') + '</span>' +
                        '</div>' +
                        modulBadge +
                        (scheduleBadge ? '<div>' + scheduleBadge + '</div>' : '') +
                    '</div>' +
                '</td>' +
                '<td>' + badgeKat + '</td>' +
                '<td>' +
                    '<div style="font-weight: 700; color: #1E293B;">' + escapeHtml(r.nama_kelas || '-') + '</div>' +
                    '<div style="font-size: 11.5px; color: #64748B;">' + escapeHtml(r.nama_mata_pelajaran || '-') + '</div>' +
                '</td>' +
                '<td>' +
                    '<div style="font-size: 12.5px; font-weight: 600;">' + escapeHtml(r.nama_guru || '-') + '</div>' +
                    '<div style="font-size: 11.5px; color: #64748B;">Pertemuan Ke-' + r.pertemuan_ke + '</div>' +
                '</td>' +
                '<td>' + paramHtml + '</td>' +
                '<td>' +
                    '<div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-start;">' +
                        badgeStatus +
                        statusActionBtn +
                    '</div>' +
                '</td>' +
                '<td style="text-align: right;">' +
                    '<div class="ilmuna-action-btns" style="justify-content: flex-end;">' +
                        '<a href="<?= BASE_URL ?>/dashboard/admin/assessment-questions" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--ghost" style="color: #D97706; background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.25);" title="Kelola Butir Soal (Import DOCS)">' +
                            '<iconify-icon icon="solar:clipboard-list-bold" width="14"></iconify-icon>' +
                        '</a>' +
                        cbtAccessBtn +
                        editBtn +
                        deleteBtn +
                    '</div>' +
                '</td>' +
            '</tr>';
        });

        tbody.innerHTML = html;

        tbody.querySelectorAll('.row-assess-cb').forEach(function(cb) {
            cb.addEventListener('change', function() {
                var row = this.closest('tr');
                if (this.checked) {
                    selectedItems.add(this.value);
                    if (row) row.classList.add('is-selected');
                } else {
                    selectedItems.delete(this.value);
                    if (row) row.classList.remove('is-selected');
                }
                updateBulkBar();
            });
        });

        updateBulkBar();

        document.querySelectorAll('.btn-change-status').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                var kat = this.getAttribute('data-kat');
                var id = this.getAttribute('data-id');
                var newStat = this.getAttribute('data-target');
                var selfBtn = this;

                selfBtn.disabled = true;
                var fd = new FormData();
                fd.append('action', 'toggle_status');
                fd.append('kategori', kat);
                fd.append('id', id);
                fd.append('status', newStat);

                fetch(ajaxUrl, { method: 'POST', body: fd })
                .then(function(res) { return res.json(); })
                .then(function(res) {
                    selfBtn.disabled = false;
                    if (res.success) {
                        showToast(res.message, 'success');
                        fetchList();
                    } else {
                        showToast(res.message, 'error');
                    }
                })
                .catch(function(err) {
                    selfBtn.disabled = false;
                    showToast(err.message, 'error');
                });
            });
        });

        document.querySelectorAll('.btn-edit-assess').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var kat = this.getAttribute('data-kat');
                var id = this.getAttribute('data-id');
                loadEditForm(kat, id);
            });
        });

        document.querySelectorAll('.btn-del-assess').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var kat = this.getAttribute('data-kat');
                var id = this.getAttribute('data-id');
                var judul = this.getAttribute('data-judul');
                deleteTarget = { kategori: kat, id: id };
                document.getElementById('deleteConfirmText').innerHTML = 'Apakah Anda yakin ingin menghapus paket asesmen <strong>"' + escapeHtml(judul) + '"</strong>?<br><br><span style="color:#DC2626;font-size:12px;font-weight:600;"><iconify-icon icon=\"solar:danger-circle-bold\" width=\"13\" style=\"vertical-align:middle;\"></iconify-icon> Seluruh butir soal, opsi jawaban, rubrik penilaian, tiket akses, rekaman sesi CBT, absensi, dan nilai siswa terkait akan dihapus permanen secara menyeluruh.</span>';
                openModal('modalDeleteAssess');
            });
        });
    }

    function renderPagination(page, pages, total, perPage) {
        var info = document.getElementById('paginationInfo');
        var ctrl = document.getElementById('paginationControls');

        if (total === 0) {
            info.textContent = 'Tidak ada data asesmen.';
            ctrl.innerHTML = '';
            return;
        }

        var start = (page - 1) * perPage + 1;
        var end = Math.min(page * perPage, total);
        info.innerHTML = 'Menampilkan <strong>' + start + ' - ' + end + '</strong> dari <strong>' + total + '</strong> asesmen';

        if (pages <= 1) {
            ctrl.innerHTML = '';
            return;
        }

        var html = '';
        html += '<button type="button" class="ilmuna-page-btn" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '><iconify-icon icon="solar:alt-arrow-left-bold" width="14"></iconify-icon></button>';
        for (var p = 1; p <= pages; p++) {
            if (p === 1 || p === pages || (p >= page - 2 && p <= page + 2)) {
                html += '<button type="button" class="ilmuna-page-btn' + (p === page ? ' is-active' : '') + '" data-page="' + p + '">' + p + '</button>';
            } else if (p === page - 3 || p === page + 3) {
                html += '<span style="color: #94A3B8; padding: 0 4px;">...</span>';
            }
        }
        html += '<button type="button" class="ilmuna-page-btn" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '><iconify-icon icon="solar:alt-arrow-right-bold" width="14"></iconify-icon></button>';

        ctrl.innerHTML = html;

        ctrl.querySelectorAll('.ilmuna-page-btn').forEach(function(b) {
            b.addEventListener('click', function() {
                var pg = parseInt(this.getAttribute('data-page'), 10);
                if (pg >= 1 && pg <= pages && pg !== state.page) {
                    state.page = pg;
                    fetchList();
                }
            });
        });
    }

    var formAdd = document.getElementById('formAddAssess');
    if (formAdd) {
        formAdd.addEventListener('submit', function(e) {
            e.preventDefault();

            var checkedKmp = document.querySelectorAll('#boxKmpChecklist .chk-kmp-item:checked');
            if (checkedKmp.length === 0) {
                showToast('Silakan centang minimal satu rombel kelas untuk asesmen ini.', 'error');
                return;
            }

            var addKatVal = document.getElementById('addKategori') ? document.getElementById('addKategori').value : '';
            var addJenisRemVal = document.getElementById('addJenisRemedial') ? document.getElementById('addJenisRemedial').value : '';
            var btn = document.getElementById('btnSubmitAdd');
            if (addKatVal === 'remedial' && addJenisRemVal === 'Remedial' && btn && btn.disabled) {
                showToast('Program Remedial tidak dapat disimpan karena tidak ada siswa yang tidak lulus di kelas terpilih.', 'error');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="ilmuna-spinner" style="width: 14px; height: 14px; margin-right: 6px;"></span> Menyimpan...';

            var formData = new FormData(formAdd);
            formData.append('action', 'save_asesmen');

            fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                btn.disabled = false;
                btn.innerHTML = '<iconify-icon icon="solar:check-read-bold" width="18"></iconify-icon> Simpan Paket Asesmen';

                if (res.success) {
                    closeModal('modalAddAssess');
                    formAdd.reset();
                    showToast(res.message, 'success');
                    fetchList();
                } else {
                    showToast(res.message, 'error');
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.innerHTML = '<iconify-icon icon="solar:check-read-bold" width="18"></iconify-icon> Simpan Paket Asesmen';
                showToast(err.message, 'error');
            });
        });
    }

    function loadEditForm(kategori, id) {
        var fd = new FormData();
        fd.append('action', 'get_detail');
        fd.append('kategori', kategori);
        fd.append('id', id);

        fetch(ajaxUrl, { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (!res.success) {
                showToast(res.message, 'error');
                return;
            }

            var d = res.data;
            document.getElementById('editKategori').value = d.kategori;
            document.getElementById('editId').value = d.id;
            document.getElementById('editKategoriLabel').value = (d.kategori === 'sumatif' ? 'Asesmen Sumatif (CBT)' : (d.kategori === 'formatif' ? 'Asesmen Formatif' : (d.kategori === 'remedial' ? 'Remedial / Pengayaan' : 'Asesmen Diagnostik')));
            document.getElementById('editJudul').value = d.judul_asesmen || '';

            var editModulEl = document.getElementById('editModulAjar');
            if (editModulEl) editModulEl.value = d.id_modul_ajar || '';
            document.getElementById('editCp').value = d.id_cp || '';
            document.getElementById('editTp').value = d.id_tp || '';
            document.getElementById('editAtp').value = d.id_atp || '';
            document.getElementById('editItp').value = d.id_itp || '';

            var editKmpEl = document.getElementById('editKmp');
            var editKmpDisplayEl = document.getElementById('editKmpDisplay');
            if (editKmpEl) editKmpEl.value = d.id_kelas_mata_pelajaran || (d.kmp_ids && d.kmp_ids.length > 0 ? d.kmp_ids[0] : '');
            if (editKmpDisplayEl) {
                editKmpDisplayEl.value = (d.nama_kelas && d.nama_mata_pelajaran) ? (d.nama_kelas + ' • ' + d.nama_mata_pelajaran) : (d.nama_kelas || 'Kelas Terkunci');
            }

            var editGuruEl = document.getElementById('editGuru');
            var editGuruDisplayEl = document.getElementById('editGuruDisplay');
            if (editGuruEl) editGuruEl.value = d.id_guru || '';
            if (editGuruDisplayEl) editGuruDisplayEl.value = d.nama_guru || 'Guru Pengampu PAI';

            document.getElementById('editPertemuan').value = d.pertemuan_ke || 1;
            document.getElementById('editPetunjuk').value = d.petunjuk_pengerjaan || '';

            updateModulInfoUI('edit', false);

            document.getElementById('editBoxSumatif').style.display = (d.kategori === 'sumatif') ? 'grid' : 'none';
            document.getElementById('editBoxFormatif').style.display = (d.kategori === 'formatif') ? 'grid' : 'none';
            document.getElementById('editBoxDiagnostik').style.display = (d.kategori === 'diagnostik') ? 'grid' : 'none';
            document.getElementById('editBoxRemedial').style.display = (d.kategori === 'remedial') ? 'grid' : 'none';
            if (d.kategori === 'remedial') {
                document.getElementById('editJenisRemedial').value = d.jenis_remedial || 'Remedial';
                document.getElementById('editBentukKegiatan').value = d.bentuk_kegiatan || 'Bimbingan Perorangan';
                document.getElementById('editKktpRemedial').value = d.kktp || '75.00';
            }
            if (d.kategori === 'sumatif') {
                document.getElementById('editJenisSumatif').value = d.jenis_sumatif || 'Sumatif Lingkup Materi';
                document.getElementById('editKktp').value = d.kktp || '75.00';
                document.getElementById('editDurasi').value = d.durasi_menit || 60;
                updateSumatifTypeUI('edit');
            } else if (d.kategori === 'formatif') {
                document.getElementById('editTeknikFormatif').value = d.teknik_asesmen || 'Tes Tertulis';
                document.getElementById('editTujuanFormatif').value = d.tujuan_asesmen || '';
                updateSumatifTypeUI('edit');
            } else if (d.kategori === 'diagnostik') {
                document.getElementById('editJenisDiag').value = d.jenis_diagnostik || 'Kognitif';
                document.getElementById('editTujuanDiag').value = d.tujuan_asesmen || '';
                updateSumatifTypeUI('edit');
            } else {
                updateSumatifTypeUI('edit');
            }

            var editDur = document.getElementById('editDurasi');
            var editTgl = document.getElementById('editTanggalPelaksanaan');
            var editJamM = document.getElementById('editJamMulai');
            if (editDur) editDur.value = d.durasi_menit || 60;
            if (editTgl) editTgl.value = d.tanggal_pelaksanaan || '';
            if (editJamM) editJamM.value = d.jam_mulai ? d.jam_mulai.substring(0, 5) : '07:30';
            syncScheduleAndEndTime('edit');

            if (d.kategori === 'remedial') {
                checkRemedialEligibilityUI('edit');
            } else {
                var editRemBox = document.getElementById('editRemedialEligibilityInfo');
                if (editRemBox) { editRemBox.style.display = 'none'; editRemBox.innerHTML = ''; }
                var btnEditInit = document.getElementById('btnSubmitEdit');
                if (btnEditInit) { btnEditInit.disabled = false; btnEditInit.removeAttribute('title'); }
            }

            openModal('modalEditAssess');
        })
        .catch(function(err) {
            showToast(err.message, 'error');
        });
    }

    var formEdit = document.getElementById('formEditAssess');
    if (formEdit) {
        formEdit.addEventListener('submit', function(e) {
            e.preventDefault();

            var editKatVal = document.getElementById('editKategori') ? document.getElementById('editKategori').value : '';
            var editJenisRemVal = document.getElementById('editJenisRemedial') ? document.getElementById('editJenisRemedial').value : '';
            var btn = document.getElementById('btnSubmitEdit');
            if (editKatVal === 'remedial' && editJenisRemVal === 'Remedial' && btn && btn.disabled) {
                showToast('Program Remedial tidak dapat disimpan karena tidak ada siswa yang tidak lulus di kelas ini.', 'error');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="ilmuna-spinner" style="width: 14px; height: 14px; margin-right: 6px;"></span> Menyimpan...';

            var formData = new FormData(formEdit);
            formData.append('action', 'update_asesmen');

            fetch(ajaxUrl, { method: 'POST', body: formData })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                btn.disabled = false;
                btn.innerHTML = '<iconify-icon icon="solar:check-read-bold" width="18"></iconify-icon> Perbarui Asesmen';

                if (res.success) {
                    closeModal('modalEditAssess');
                    showToast(res.message, 'success');
                    fetchList();
                } else {
                    showToast(res.message, 'error');
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.innerHTML = '<iconify-icon icon="solar:check-read-bold" width="18"></iconify-icon> Perbarui Asesmen';
                showToast(err.message, 'error');
            });
        });
    }

    var btnConfirmDel = document.getElementById('btnConfirmDelete');
    if (btnConfirmDel) {
        btnConfirmDel.addEventListener('click', function() {
            if (deleteTarget.id <= 0) return;
            btnConfirmDel.disabled = true;
            btnConfirmDel.innerHTML = '<span class="ilmuna-spinner" style="width: 14px; height: 14px; margin-right: 6px;"></span> Menghapus...';

            var fd = new FormData();
            fd.append('action', 'delete_asesmen');
            fd.append('kategori', deleteTarget.kategori);
            fd.append('id', deleteTarget.id);

            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                btnConfirmDel.disabled = false;
                btnConfirmDel.innerHTML = '<iconify-icon icon="solar:trash-bin-trash-bold" width="18"></iconify-icon> Ya, Hapus Sekarang';
                closeModal('modalDeleteAssess');
                if (res.success) { showToast(res.message, 'success'); fetchList(); }
                else { showToast(res.message, 'error'); }
            })
            .catch(function(err) {
                btnConfirmDel.disabled = false;
                btnConfirmDel.innerHTML = '<iconify-icon icon="solar:trash-bin-trash-bold" width="18"></iconify-icon> Ya, Hapus Sekarang';
                showToast(err.message, 'error');
            });
        });
    }

    /* ==============================================================================
     * BULK ACTIONS EVENT LISTENERS & AJAX DISPATCHERS
     * ============================================================================== */
    if (checkAllAssess) {
        checkAllAssess.addEventListener('change', function() {
            var isChecked = this.checked;
            var rowCbs = tbody.querySelectorAll('.row-assess-cb');
            rowCbs.forEach(function(cb) {
                cb.checked = isChecked;
                var row = cb.closest('tr');
                if (isChecked) {
                    selectedItems.add(cb.value);
                    if (row) row.classList.add('is-selected');
                } else {
                    selectedItems.delete(cb.value);
                    if (row) row.classList.remove('is-selected');
                }
            });
            updateBulkBar();
        });
    }

    var btnBulkClose = document.getElementById('btnBulkCloseBar');
    if (btnBulkClose) {
        btnBulkClose.addEventListener('click', function(e) {
            e.preventDefault();
            clearBulkSelection();
        });
    }

    function executeBulkStatus(newStatus, actionLabel) {
        if (selectedItems.size === 0) {
            showToast('Tidak ada paket asesmen yang dipilih.', 'warning');
            return;
        }

        var fd = new FormData();
        fd.append('action', 'bulk_update_status');
        fd.append('status', newStatus);
        selectedItems.forEach(function(item) {
            fd.append('items[]', item);
        });

        fetch(ajaxUrl, { method: 'POST', body: fd })
        .then(function(res) { return res.json(); })
        .then(function(res) {
            if (res.success) {
                showToast(res.message, 'success');
                clearBulkSelection();
                fetchList();
            } else {
                showToast(res.message, 'error');
            }
        })
        .catch(function(err) {
            showToast('Terjadi kesalahan: ' + err.message, 'error');
        });
    }

    var btnBulkQuickActivate = document.getElementById('btnBulkQuickActivate');
    if (btnBulkQuickActivate) {
        btnBulkQuickActivate.addEventListener('click', function(e) {
            e.preventDefault();
            executeBulkStatus('Aktif', 'Mengaktifkan...');
        });
    }

    var btnBulkQuickClose = document.getElementById('btnBulkQuickClose');
    if (btnBulkQuickClose) {
        btnBulkQuickClose.addEventListener('click', function(e) {
            e.preventDefault();
            executeBulkStatus('Ditutup', 'Menutup...');
        });
    }

    var btnBulkOpenStatus = document.getElementById('btnBulkOpenStatusModal');
    if (btnBulkOpenStatus) {
        btnBulkOpenStatus.addEventListener('click', function(e) {
            e.preventDefault();
            if (selectedItems.size === 0) {
                showToast('Pilih minimal satu asesmen terlebih dahulu.', 'warning');
                return;
            }
            var cnt = document.getElementById('bulkStatusCountText');
            if (cnt) cnt.textContent = selectedItems.size;
            openModal('modalBulkStatusAssess');
        });
    }

    var btnConfirmBulkStatus = document.getElementById('btnConfirmBulkStatus');
    if (btnConfirmBulkStatus) {
        btnConfirmBulkStatus.addEventListener('click', function(e) {
            e.preventDefault();
            var sel = document.getElementById('selectBulkStatus');
            var statusVal = sel ? sel.value : 'Aktif';
            closeModal('modalBulkStatusAssess');
            executeBulkStatus(statusVal, 'Menerapkan Status...');
        });
    }

    var btnBulkOpenDelete = document.getElementById('btnBulkOpenDeleteModal');
    if (btnBulkOpenDelete) {
        btnBulkOpenDelete.addEventListener('click', function(e) {
            e.preventDefault();
            if (selectedItems.size === 0) {
                showToast('Pilih minimal satu asesmen terlebih dahulu.', 'warning');
                return;
            }
            var cnt = document.getElementById('bulkDeleteCountText');
            if (cnt) cnt.textContent = selectedItems.size;
            var chkForce = document.getElementById('chkBulkForceDelete');
            if (chkForce) chkForce.checked = false;
            openModal('modalBulkDeleteAssess');
        });
    }

    var btnConfirmBulkDelete = document.getElementById('btnConfirmBulkDelete');
    if (btnConfirmBulkDelete) {
        btnConfirmBulkDelete.addEventListener('click', function(e) {
            e.preventDefault();
            if (selectedItems.size === 0) return;

            var btn = this;
            var origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="ilmuna-spinner" style="width: 14px; height: 14px; margin-right: 6px;"></span> Menghapus...';

            var chkForce = document.getElementById('chkBulkForceDelete');
            var isForce = chkForce && chkForce.checked ? 1 : 0;

            var fd = new FormData();
            fd.append('action', 'bulk_delete_asesmen');
            fd.append('force', isForce);
            selectedItems.forEach(function(item) {
                fd.append('items[]', item);
            });

            fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
                closeModal('modalBulkDeleteAssess');
                if (res.success) {
                    showToast(res.message, 'success');
                    clearBulkSelection();
                    fetchList();
                } else {
                    showToast(res.message, 'error');
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
                showToast('Terjadi kesalahan: ' + err.message, 'error');
            });
        });
    }

    function escapeHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function syncScheduleAndEndTime(prefix) {
        var durasiEl = document.getElementById(prefix + 'Durasi');
        var startEl  = document.getElementById(prefix + 'JamMulai');
        var endEl    = document.getElementById(prefix + 'JamSelesai');
        var textEl   = document.getElementById(prefix + 'RentangText');

        if (!durasiEl || !startEl || !endEl) return;

        var durasi = parseInt(durasiEl.value, 10);
        if (isNaN(durasi) || durasi < 5) durasi = 60;

        var sVal = startEl.value;
        if (!sVal) return;

        var parts = sVal.split(':');
        if (parts.length >= 2) {
            var startHour = parseInt(parts[0], 10);
            var startMin  = parseInt(parts[1], 10);
            var totalStartMins = startHour * 60 + startMin;
            var totalEndMins   = totalStartMins + durasi;
            var endHour = Math.floor(totalEndMins / 60) % 24;
            var endMin  = totalEndMins % 60;
            var endHourStr = (endHour < 10 ? '0' : '') + endHour;
            var endMinStr  = (endMin < 10 ? '0' : '') + endMin;
            var formattedEndTime = endHourStr + ':' + endMinStr;
            endEl.value = formattedEndTime;
            if (textEl) {
                textEl.textContent = sVal + ' + ' + durasi + ' Menit ➔ Selesai: ' + formattedEndTime + ' WIB';
                textEl.style.color = '#0D7A87';
            }
        }
    }

    ['addDurasi', 'addJamMulai'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function() { syncScheduleAndEndTime('add'); });
            el.addEventListener('change', function() { syncScheduleAndEndTime('add'); });
        }
    });
    ['editDurasi', 'editJamMulai'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function() { syncScheduleAndEndTime('edit'); });
            el.addEventListener('change', function() { syncScheduleAndEndTime('edit'); });
        }
    });
    syncScheduleAndEndTime('add');

    /* ==============================================================================
     * EXCEL TEMPLATE & IMPORT ASESMEN HANDLER
     * ============================================================================== */
    var excelUrl = '<?= BASE_URL ?>/dashboard/admin/assessments-excel.php';

    function downloadBlob(url, defaultFilename, btnElement, loadingText) {
        var origHtml = btnElement ? btnElement.innerHTML : '';
        if (btnElement) {
            btnElement.disabled = true;
            btnElement.innerHTML = '<span class="ilmuna-spinner" style="width: 14px; height: 14px; margin-right: 6px;"></span> ' + (loadingText || 'Mengunduh...');
        }

        fetch(url, { method: 'GET' })
        .then(function(res) {
            if (!res.ok) throw new Error('Gagal mengunduh berkas Excel dari server.');
            var disp = res.headers.get('Content-Disposition') || '';
            var filename = defaultFilename;
            var match = disp.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
            if (match && match[1]) filename = match[1].replace(/['"]/g, '').trim();
            return res.blob().then(function(b) { return { blob: b, filename: filename }; });
        })
        .then(function(obj) {
            var blobUrl = window.URL.createObjectURL(obj.blob);
            var a = document.createElement('a');
            a.style.display = 'none';
            a.href = blobUrl;
            a.download = obj.filename;
            document.body.appendChild(a);
            a.click();
            setTimeout(function() {
                window.URL.revokeObjectURL(blobUrl);
                document.body.removeChild(a);
            }, 300);
            if (btnElement) {
                btnElement.disabled = false;
                btnElement.innerHTML = origHtml;
            }
            showToast('Format template Excel berhasil diunduh.', 'success');
        })
        .catch(function(err) {
            if (btnElement) {
                btnElement.disabled = false;
                btnElement.innerHTML = origHtml;
            }
            showToast(err.message, 'error');
        });
    }

    var btnTplAssess = document.getElementById('btnDownloadTemplateAssess');
    if (btnTplAssess) {
        btnTplAssess.addEventListener('click', function(e) {
            e.preventDefault();
            downloadBlob(excelUrl + '?action=download_template', 'template-import-asesmen-ilmuna.xlsx', this, 'Mengunduh Template...');
        });
    }

    var btnModalTplAssess = document.getElementById('btnModalDownloadTemplate');
    if (btnModalTplAssess) {
        btnModalTplAssess.addEventListener('click', function(e) {
            e.preventDefault();
            downloadBlob(excelUrl + '?action=download_template', 'template-import-asesmen-ilmuna.xlsx', this, 'Mengunduh...');
        });
    }

    var btnOpenImport = document.getElementById('btnOpenImportAssess');
    var fileInput = document.getElementById('assessImportFile');
    var fileDropzone = document.getElementById('assessFileDropzone');
    var fileSelectedName = document.getElementById('assessSelectedFileName');
    var importLog = document.getElementById('assessImportLog');

    function updateFilePreview(file) {
        if (!fileSelectedName) return;
        if (file) {
            var sizeKb = Math.round(file.size / 1024);
            fileSelectedName.innerHTML = '<iconify-icon icon="solar:file-check-bold" width="16" style="vertical-align:middle;"></iconify-icon> Terpilih: <strong>' + escapeHtml(file.name) + '</strong> (' + sizeKb + ' KB)';
            fileSelectedName.style.display = 'block';
        } else {
            fileSelectedName.textContent = '';
            fileSelectedName.style.display = 'none';
        }
    }

    if (btnOpenImport) {
        btnOpenImport.addEventListener('click', function() {
            if (fileInput) fileInput.value = '';
            updateFilePreview(null);
            if (importLog) {
                importLog.style.display = 'none';
                importLog.innerHTML = '';
            }
            openModal('modalImportAssess');
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            updateFilePreview(this.files ? this.files[0] : null);
        });
    }

    if (fileDropzone) {
        ['dragenter', 'dragover'].forEach(function(evt) {
            fileDropzone.addEventListener(evt, function(e) {
                e.preventDefault();
                e.stopPropagation();
                fileDropzone.classList.add('dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function(evt) {
            fileDropzone.addEventListener(evt, function(e) {
                e.preventDefault();
                e.stopPropagation();
                fileDropzone.classList.remove('dragover');
            });
        });
        fileDropzone.addEventListener('drop', function(e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                updateFilePreview(e.dataTransfer.files[0]);
            }
        });
    }

    var btnDoImport = document.getElementById('btnDoImportAssess');
    if (btnDoImport) {
        btnDoImport.addEventListener('click', function(e) {
            e.preventDefault();
            if (!fileInput.files || !fileInput.files[0]) {
                showToast('Silakan pilih berkas Excel (.xlsx) terlebih dahulu.', 'warning');
                return;
            }
            var btn = this;
            btn.disabled = true;
            btn.innerHTML = '<span class="ilmuna-spinner" style="width: 14px; height: 14px; margin-right: 6px;"></span> Memproses Import...';

            var fd = new FormData();
            fd.append('action', 'import_excel');
            fd.append('file', fileInput.files[0]);

            fetch(excelUrl, { method: 'POST', body: fd })
            .then(function(res) { return res.json(); })
            .then(function(res) {
                btn.disabled = false;
                btn.innerHTML = '<iconify-icon icon="solar:cloud-upload-bold" width="18"></iconify-icon> Mulai Import Asesmen';

                if (importLog) {
                    importLog.style.display = 'block';
                    if (!res || !res.success) {
                        importLog.innerHTML = '<div style="padding: 14px; color: #DC2626; font-size: 13px;">' +
                            escapeHtml((res && res.data && res.data.message) || 'Gagal import Excel.') + '</div>';
                        showToast((res && res.data && res.data.message) || 'Gagal import Excel.', 'error');
                        return;
                    }
                    var d = res.data;
                    var html = '<div class="ilmuna-import-stat">';
                    html += '<span style="color: #059669;">✔ ' + d.stat.ok + ' Berhasil</span>';
                    html += '<span style="color: #D97706;">⚠ ' + d.stat.skip + ' Dilewati</span>';
                    html += '<span style="color: #DC2626;">✘ ' + d.stat.error + ' Gagal</span>';
                    html += '</div>';
                    (d.log || []).forEach(function(item) {
                        var num = item[0]; var st = item[1]; var msg = item[2];
                        html += '<div class="ilmuna-import-row">';
                        html += '<span style="font-family: monospace; color: #64748B;">#' + num + '</span>';
                        html += '<span class="badge ' + st + '">' + (st === 'ok' ? '✔ OK' : st === 'skip' ? '⚠ SKIP' : '✘ ERROR') + '</span>';
                        html += '<span style="color: #334155; flex: 1;">' + escapeHtml(msg) + '</span>';
                        html += '</div>';
                    });
                    importLog.innerHTML = html;
                    showToast(d.message, 'success');
                    fetchList();
                }
            })
            .catch(function(err) {
                btn.disabled = false;
                btn.innerHTML = '<iconify-icon icon="solar:cloud-upload-bold" width="18"></iconify-icon> Mulai Import Asesmen';
                showToast('Koneksi bermasalah: ' + err.message, 'error');
            });
        });
    }

    fetchList();

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>