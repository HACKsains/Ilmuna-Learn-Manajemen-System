<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DASHBOARD NILAI & HASIL ASESMEN PEMBELAJARAN (ADMIN & GURU)
 * ==============================================================================
 * Arsitektur: Pure Reactive SPA via AJAX (Tanpa reload URL / render ulang halaman),
 *             Live Search 1 Karakter ala WhatsApp, Tab In-Page, KPI Rekapitulasi Realtime,
 *             Modal Breakdown Jawaban CBT, & Ekspor Nilai Resmi (Excel .xlsx & PDF via BLOB).
 * Tema Warna: Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Gold #F59E0B)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';

// Redirect akses langsung URL berekstensi .php ke Clean URL
if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'assessment-results.php') !== false) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['action'])) {
        $cleanUrl = str_replace('assessment-results.php', 'assessment-results', $_SERVER['REQUEST_URI']);
        header('Location: ' . $cleanUrl, true, 301);
        exit;
    }
}

require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole(['admin', 'guru']);

$pdo = getDBConnection();

$pageTitle = 'Dashboard Nilai & Hasil Asesmen';
$pageIcon  = 'solar:chart-square-bold';

/* ==============================================================================
 * 1. BACKEND AJAX API HANDLERS (JSON ONLY)
 * ============================================================================== */
if (isset($_REQUEST['action'])) {
    if (ob_get_level()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');

    function sendResponse($success, $data = [], $message = '') {
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data'    => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $req = array_merge($_GET, $_POST, $_REQUEST);
    $act = $req['action'] ?? '';

    // 1.0 AMBIL DAFTAR MATA PELAJARAN BERDASARKAN KELAS (LANGKAH 2)
    if ($act === 'get_mapel_by_kelas') {
        $idKelas = (int)($req['id_kelas'] ?? $req['kelas_id'] ?? 0);
        if (!$idKelas) {
            sendResponse(false, [], 'Pilih kelas terlebih dahulu.');
        }

        $stmt = $pdo->prepare("
            SELECT kmp.id AS id_kmp, kmp.id_mata_pelajaran, mp.nama_mata_pelajaran, mp.kode_mapel AS kode_mata_pelajaran
            FROM kelas_mata_pelajaran kmp
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            WHERE kmp.id_kelas = ?
            ORDER BY mp.nama_mata_pelajaran ASC
        ");
        $stmt->execute([$idKelas]);
        $mapelList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        sendResponse(true, $mapelList, 'Daftar mata pelajaran berhasil dimuat.');
    }

    // 1.0.1 AMBIL DAFTAR ASESMEN BERDASARKAN KELAS MATA PELAJARAN (LANGKAH 3)
    if ($act === 'get_asesmen_by_kmp') {
        $idKmp = (int)($req['id_kmp'] ?? $req['kmp_id'] ?? 0);
        if (!$idKmp) {
            sendResponse(false, [], 'Pilih mata pelajaran terlebih dahulu.');
        }

        $stmtSum = $pdo->prepare("
            SELECT DISTINCT asum.id, 'sumatif' AS kategori, asum.judul_asesmen, asum.jenis_sumatif AS tipe_khusus, asum.kktp 
            FROM asesmen_sumatif asum 
            LEFT JOIN asesmen_sumatif_kelas ask ON ask.id_asesmen_sumatif = asum.id
            WHERE (asum.id_kelas_mata_pelajaran = ? OR ask.id_kelas_mata_pelajaran = ?) 
            ORDER BY asum.id DESC
        ");
        $stmtSum->execute([$idKmp, $idKmp]);
        $sumatif = $stmtSum->fetchAll(PDO::FETCH_ASSOC);

        $stmtForm = $pdo->prepare("
            SELECT id, 'formatif' AS kategori, judul_asesmen, teknik_asesmen AS tipe_khusus, 75.00 AS kktp 
            FROM asesmen_formatif 
            WHERE id_kelas_mata_pelajaran = ? 
            ORDER BY id DESC
        ");
        $stmtForm->execute([$idKmp]);
        $formatif = $stmtForm->fetchAll(PDO::FETCH_ASSOC);

        $stmtDiag = $pdo->prepare("
            SELECT id, 'diagnostik' AS kategori, judul_asesmen, jenis_diagnostik AS tipe_khusus, 75.00 AS kktp 
            FROM asesmen_diagnostik 
            WHERE id_kelas_mata_pelajaran = ? 
            ORDER BY id DESC
        ");
        $stmtDiag->execute([$idKmp]);
        $diagnostik = $stmtDiag->fetchAll(PDO::FETCH_ASSOC);

        $stmtRem = $pdo->prepare("
            SELECT id, 'remedial' AS kategori, judul_program AS judul_asesmen, kategori AS tipe_khusus, kktp_minimal AS kktp 
            FROM pengayaan_remedial 
            WHERE id_kelas_mata_pelajaran = ? 
            ORDER BY id DESC
        ");
        $stmtRem->execute([$idKmp]);
        $remedial = $stmtRem->fetchAll(PDO::FETCH_ASSOC);

        $asesmenList = array_merge($sumatif, $formatif, $diagnostik, $remedial);
        sendResponse(true, $asesmenList, 'Daftar paket asesmen berhasil dimuat.');
    }

    // 1.1 FETCH LIST REKAP NILAI SECARA REAKTIF & LAZY LOAD
    if ($act === 'list') {
        $kategori     = $req['kategori'] ?? 'all';
        if ($kategori === 'semua') $kategori = 'all';
        $statusTuntas = $req['status_tuntas'] ?? $req['status'] ?? 'all';
        if ($statusTuntas === 'semua') $statusTuntas = 'all';
        $idKelas      = (int)($req['kelas_id'] ?? $req['id_kelas'] ?? 0);
        $idKmp        = (int)($req['kmp_id'] ?? $req['id_kmp'] ?? 0);
        $idAsesmen    = (int)($req['asesmen_id'] ?? $req['id_asesmen'] ?? 0);
        $search       = trim($req['search'] ?? '');
        $page         = max(1, (int)($req['page'] ?? 1));
        $perPage      = max(5, min(1000, (int)($req['per_page'] ?? 20)));
        $offset       = ($page - 1) * $perPage;

        if ($idKelas <= 0 || $idAsesmen <= 0) {
            sendResponse(true, [
                'rows'       => [],
                'items'      => [],
                'stats'      => [
                    'total_siswa'     => 0, 'total_dinilai'   => 0,
                    'rata_rata'       => '0.00', 'nilai_tertinggi' => '0.00', 'nilai_terendah'  => '0.00',
                    'total_tuntas'    => 0, 'total_remedial'  => 0,
                    'cbt_selesai'     => 0, 'cbt_sedang'      => 0, 'cbt_belum'       => 0, 'persen_tuntas'   => 0
                ],
                'pagination' => ['total' => 0, 'page' => 1, 'per_page' => $perPage, 'pages' => 0],
                'meta'       => null
            ], 'Silakan pilih Rombel Kelas, Mata Pelajaran, dan Paket Asesmen terlebih dahulu.');
        }

        $sqlSumatif = "
            SELECT 
                'sumatif' AS kategori, 'sumatif' AS kategori_asesmen,
                COALESCE(nas.id, 0) AS id_nilai,
                asum.id AS id_asesmen, s.id AS id_siswa,
                s.nama_lengkap, s.nis, s.nisn, s.foto,
                k.id AS id_kelas, k.nama_kelas, k.tahun_pelajaran,
                asum.judul_asesmen, asum.jenis_sumatif AS tipe_khusus, asum.kktp,
                CASE 
                    WHEN asum.jenis_sumatif IN ('Sumatif Tengah Semester', 'Sumatif Akhir Semester') THEN 'PAN'
                    ELSE 'PAP'
                END AS metode_penilaian,
                CASE 
                    WHEN asum.jenis_sumatif IN ('Sumatif Tengah Semester', 'Sumatif Akhir Semester') THEN
                        COALESCE(
                            (SELECT ROUND(AVG(nas_sub.nilai_angka), 2) FROM nilai_asesmen_sumatif nas_sub JOIN siswa s_sub ON nas_sub.id_siswa = s_sub.id WHERE nas_sub.id_asesmen_sumatif = asum.id AND s_sub.id_kelas = s.id_kelas AND nas_sub.nilai_angka IS NOT NULL),
                            asum.kktp,
                            75.00
                        )
                    ELSE COALESCE(asum.kktp, 75.00)
                END AS nilai_patokan,
                COALESCE(mp.nama_mata_pelajaran,
                    (SELECT mp2.nama_mata_pelajaran FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1),
                    'Pendidikan Agama Islam') AS nama_mata_pelajaran,
                COALESCE(mp.nama_mata_pelajaran,
                    (SELECT mp2.nama_mata_pelajaran FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1),
                    'Pendidikan Agama Islam') AS nama_mapel,
                COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru,
                nas.nilai_angka, nas.nilai_kktp, nas.predikat, nas.status_tuntas, nas.catatan_evaluasi, nas.waktu_penilaian,
                COALESCE(aas.status_akses, 'Belum Mulai') AS status_cbt,
                COALESCE(aas.status_akses, 'Belum Mulai') AS cbt_status,
                aas.waktu_mulai, aas.waktu_selesai
            FROM (
                SELECT asum.id AS id_asesmen, s.id AS id_siswa 
                FROM asesmen_sumatif asum 
                JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id 
                JOIN siswa s ON s.id_kelas = kmp.id_kelas
                UNION
                SELECT ask.id_asesmen_sumatif AS id_asesmen, s.id AS id_siswa
                FROM asesmen_sumatif_kelas ask
                JOIN kelas_mata_pelajaran kmp_rel ON ask.id_kelas_mata_pelajaran = kmp_rel.id
                JOIN siswa s ON s.id_kelas = kmp_rel.id_kelas
                UNION
                SELECT id_asesmen_sumatif AS id_asesmen, id_siswa FROM akses_asesmen_sumatif
                UNION
                SELECT id_asesmen_sumatif AS id_asesmen, id_siswa FROM nilai_asesmen_sumatif
            ) pair
            JOIN siswa s ON pair.id_siswa = s.id
            JOIN kelas k ON s.id_kelas = k.id
            JOIN asesmen_sumatif asum ON pair.id_asesmen = asum.id
            LEFT JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru g ON asum.id_guru = g.id
            LEFT JOIN nilai_asesmen_sumatif nas ON (nas.id_asesmen_sumatif = pair.id_asesmen AND nas.id_siswa = pair.id_siswa)
            LEFT JOIN akses_asesmen_sumatif aas ON (aas.id_asesmen_sumatif = pair.id_asesmen AND aas.id_siswa = pair.id_siswa)
        ";

        $sqlFormatif = "
            SELECT 
                'formatif' AS kategori, 'formatif' AS kategori_asesmen,
                COALESCE(naf.id, 0) AS id_nilai,
                af.id AS id_asesmen, s.id AS id_siswa,
                s.nama_lengkap, s.nis, s.nisn, s.foto,
                k.id AS id_kelas, k.nama_kelas, k.tahun_pelajaran,
                af.judul_asesmen, af.teknik_asesmen AS tipe_khusus, 75.00 AS kktp,
                'PAP' AS metode_penilaian,
                75.00 AS nilai_patokan,
                COALESCE(mp.nama_mata_pelajaran, 'Pendidikan Agama Islam') AS nama_mata_pelajaran,
                COALESCE(mp.nama_mata_pelajaran, 'Pendidikan Agama Islam') AS nama_mapel,
                COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru,
                naf.nilai_angka, naf.nilai_kktp, naf.predikat, naf.status_tuntas, naf.catatan_evaluasi, naf.waktu_penilaian,
                COALESCE(aaf.status_akses, 'Belum Mulai') AS status_cbt,
                COALESCE(aaf.status_akses, 'Belum Mulai') AS cbt_status,
                aaf.waktu_mulai, aaf.waktu_selesai
            FROM (
                SELECT af.id AS id_asesmen, s.id AS id_siswa 
                FROM asesmen_formatif af 
                JOIN kelas_mata_pelajaran kmp ON af.id_kelas_mata_pelajaran = kmp.id 
                JOIN siswa s ON s.id_kelas = kmp.id_kelas
                UNION
                SELECT id_asesmen_formatif AS id_asesmen, id_siswa FROM akses_asesmen_formatif
                UNION
                SELECT id_asesmen_formatif AS id_asesmen, id_siswa FROM nilai_asesmen_formatif
            ) pair
            JOIN siswa s ON pair.id_siswa = s.id
            JOIN kelas k ON s.id_kelas = k.id
            JOIN asesmen_formatif af ON pair.id_asesmen = af.id
            LEFT JOIN kelas_mata_pelajaran kmp ON af.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru g ON af.id_guru = g.id
            LEFT JOIN nilai_asesmen_formatif naf ON (naf.id_asesmen_formatif = pair.id_asesmen AND naf.id_siswa = pair.id_siswa)
            LEFT JOIN akses_asesmen_formatif aaf ON (aaf.id_asesmen_formatif = pair.id_asesmen AND aaf.id_siswa = pair.id_siswa)
        ";

        $sqlDiagnostik = "
            SELECT 
                'diagnostik' AS kategori, 'diagnostik' AS kategori_asesmen,
                COALESCE(nad.id, 0) AS id_nilai,
                ad.id AS id_asesmen, s.id AS id_siswa,
                s.nama_lengkap, s.nis, s.nisn, s.foto,
                k.id AS id_kelas, k.nama_kelas, k.tahun_pelajaran,
                ad.judul_asesmen, ad.jenis_diagnostik AS tipe_khusus, COALESCE(nad.nilai_kktp, 75.00) AS kktp,
                'PAP' AS metode_penilaian,
                COALESCE(nad.nilai_kktp, 75.00) AS nilai_patokan,
                COALESCE(mp.nama_mata_pelajaran, 'Pendidikan Agama Islam') AS nama_mata_pelajaran,
                COALESCE(mp.nama_mata_pelajaran, 'Pendidikan Agama Islam') AS nama_mapel,
                COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru,
                nad.nilai_angka, nad.nilai_kktp, nad.predikat, nad.status_tuntas, nad.catatan_evaluasi, nad.waktu_penilaian,
                COALESCE(aad.status_akses, 'Belum Mulai') AS status_cbt,
                COALESCE(aad.status_akses, 'Belum Mulai') AS cbt_status,
                aad.waktu_mulai, aad.waktu_selesai
            FROM (
                SELECT ad.id AS id_asesmen, s.id AS id_siswa 
                FROM asesmen_diagnostik ad 
                JOIN kelas_mata_pelajaran kmp ON ad.id_kelas_mata_pelajaran = kmp.id 
                JOIN siswa s ON s.id_kelas = kmp.id_kelas
                UNION
                SELECT id_asesmen_diagnostik AS id_asesmen, id_siswa FROM akses_asesmen_diagnostik
                UNION
                SELECT id_asesmen_diagnostik AS id_asesmen, id_siswa FROM nilai_asesmen_diagnostik
            ) pair
            JOIN siswa s ON pair.id_siswa = s.id
            JOIN kelas k ON s.id_kelas = k.id
            JOIN asesmen_diagnostik ad ON pair.id_asesmen = ad.id
            LEFT JOIN kelas_mata_pelajaran kmp ON ad.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru g ON ad.id_guru = g.id
            LEFT JOIN nilai_asesmen_diagnostik nad ON (nad.id_asesmen_diagnostik = pair.id_asesmen AND nad.id_siswa = pair.id_siswa)
            LEFT JOIN akses_asesmen_diagnostik aad ON (aad.id_asesmen_diagnostik = pair.id_asesmen AND aad.id_siswa = pair.id_siswa)
        ";

        $sqlRemedial = "
            SELECT 
                'remedial' AS kategori, 'remedial' AS kategori_asesmen,
                COALESCE(npr.id, 0) AS id_nilai,
                pr.id AS id_asesmen, s.id AS id_siswa,
                s.nama_lengkap, s.nis, s.nisn, s.foto,
                k.id AS id_kelas, k.nama_kelas, k.tahun_pelajaran,
                pr.judul_program AS judul_asesmen, pr.kategori AS tipe_khusus, COALESCE(npr.nilai_kktp, pr.kktp_minimal, 75.00) AS kktp,
                'PAP' AS metode_penilaian,
                COALESCE(npr.nilai_kktp, pr.kktp_minimal, 75.00) AS nilai_patokan,
                COALESCE(mp.nama_mata_pelajaran, 'Pendidikan Agama Islam') AS nama_mata_pelajaran,
                COALESCE(mp.nama_mata_pelajaran, 'Pendidikan Agama Islam') AS nama_mapel,
                COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru,
                npr.nilai_akhir AS nilai_angka, npr.nilai_kktp, npr.predikat, npr.status_tuntas, npr.catatan_evaluasi, npr.waktu_penilaian,
                COALESCE(apr.status_akses, 'Belum Mulai') AS status_cbt,
                COALESCE(apr.status_akses, 'Belum Mulai') AS cbt_status,
                apr.waktu_mulai, apr.waktu_selesai
            FROM (
                SELECT pr.id AS id_asesmen, s.id AS id_siswa 
                FROM pengayaan_remedial pr 
                JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id 
                JOIN siswa s ON s.id_kelas = kmp.id_kelas
                UNION
                SELECT id_pengayaan_remedial AS id_asesmen, id_siswa FROM akses_pengayaan_remedial
                UNION
                SELECT id_pengayaan_remedial AS id_asesmen, id_siswa FROM nilai_pengayaan_remedial
            ) pair
            JOIN siswa s ON pair.id_siswa = s.id
            JOIN kelas k ON s.id_kelas = k.id
            JOIN pengayaan_remedial pr ON pair.id_asesmen = pr.id
            LEFT JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru g ON pr.id_guru = g.id
            LEFT JOIN nilai_pengayaan_remedial npr ON (npr.id_pengayaan_remedial = pair.id_asesmen AND npr.id_siswa = pair.id_siswa)
            LEFT JOIN akses_pengayaan_remedial apr ON (apr.id_pengayaan_remedial = pair.id_asesmen AND apr.id_siswa = pair.id_siswa)
        ";

        if ($kategori === 'sumatif') {
            $baseSql = "($sqlSumatif)";
        } elseif ($kategori === 'formatif') {
            $baseSql = "($sqlFormatif)";
        } elseif ($kategori === 'diagnostik') {
            $baseSql = "($sqlDiagnostik)";
        } elseif ($kategori === 'remedial' || $kategori === 'pengayaan') {
            $baseSql = "($sqlRemedial)";
        } else {
            $baseSql = "(($sqlSumatif) UNION ALL ($sqlFormatif) UNION ALL ($sqlDiagnostik) UNION ALL ($sqlRemedial))";
        }

        $where = " WHERE 1=1 ";
        $params = [];

        if ($idKelas > 0) { $where .= " AND t.id_kelas = ? "; $params[] = $idKelas; }
        if ($idAsesmen > 0) { $where .= " AND t.id_asesmen = ? "; $params[] = $idAsesmen; }

        if ($statusTuntas === 'tuntas') { $where .= " AND (t.nilai_angka IS NOT NULL AND t.nilai_angka >= t.nilai_patokan) "; }
        elseif ($statusTuntas === 'remedial') { $where .= " AND (t.nilai_angka IS NOT NULL AND t.nilai_angka < t.nilai_patokan) "; }
        elseif ($statusTuntas === 'unrated') { $where .= " AND (t.nilai_angka IS NULL OR t.nilai_angka = '') "; }
        elseif ($statusTuntas === 'cbt_selesai') { $where .= " AND t.status_cbt = 'Selesai' "; }
        elseif ($statusTuntas === 'cbt_sedang') { $where .= " AND t.status_cbt = 'Sedang Mengerjakan' "; }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= " AND (t.nama_lengkap LIKE ? OR t.nis LIKE ? OR t.nisn LIKE ? OR t.judul_asesmen LIKE ? OR t.nama_kelas LIKE ? OR t.nama_mata_pelajaran LIKE ?) ";
            $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
        }

        try {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM $baseSql t $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $querySql = "SELECT t.* FROM $baseSql t $where ORDER BY t.waktu_penilaian DESC, t.id_asesmen DESC, t.nama_lengkap ASC LIMIT $perPage OFFSET $offset";
            $dataStmt = $pdo->prepare($querySql);
            $dataStmt->execute($params);
            $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

            $statsSql = "
                SELECT 
                    COUNT(*) AS total_siswa,
                    SUM(CASE WHEN t.nilai_angka IS NOT NULL THEN 1 ELSE 0 END) AS total_dinilai,
                    AVG(t.nilai_angka) AS rata_rata,
                    MAX(t.nilai_angka) AS nilai_tertinggi,
                    MIN(t.nilai_angka) AS nilai_terendah,
                    SUM(CASE WHEN t.nilai_angka IS NOT NULL AND t.nilai_angka >= t.nilai_patokan THEN 1 ELSE 0 END) AS total_tuntas,
                    SUM(CASE WHEN t.nilai_angka IS NOT NULL AND t.nilai_angka < t.nilai_patokan THEN 1 ELSE 0 END) AS total_remedial,
                    SUM(CASE WHEN t.status_cbt = 'Selesai' THEN 1 ELSE 0 END) AS cbt_selesai,
                    SUM(CASE WHEN t.status_cbt = 'Sedang Mengerjakan' THEN 1 ELSE 0 END) AS cbt_sedang,
                    SUM(CASE WHEN t.status_cbt = 'Belum Mulai' THEN 1 ELSE 0 END) AS cbt_belum
                FROM $baseSql t $where
            ";
            $statStmt = $pdo->prepare($statsSql);
            $statStmt->execute($params);
            $statsData = $statStmt->fetch(PDO::FETCH_ASSOC);

            $stats = [
                'total_siswa'     => (int)($statsData['total_siswa'] ?? 0),
                'total_dinilai'   => (int)($statsData['total_dinilai'] ?? 0),
                'rata_rata'       => round((float)($statsData['rata_rata'] ?? 0), 2),
                'nilai_tertinggi' => round((float)($statsData['nilai_tertinggi'] ?? 0), 2),
                'nilai_terendah'  => round((float)($statsData['nilai_terendah'] ?? 0), 2),
                'total_tuntas'    => (int)($statsData['total_tuntas'] ?? 0),
                'total_remedial'  => (int)($statsData['total_remedial'] ?? 0),
                'cbt_selesai'     => (int)($statsData['cbt_selesai'] ?? 0),
                'cbt_sedang'      => (int)($statsData['cbt_sedang'] ?? 0),
                'cbt_belum'       => (int)($statsData['cbt_belum'] ?? 0)
            ];
            $stats['persen_tuntas'] = ($stats['total_dinilai'] > 0) ? round(($stats['total_tuntas'] / $stats['total_dinilai']) * 100, 1) : 0;

            $stmtMeta = $pdo->prepare("
                SELECT 
                    COALESCE((SELECT GROUP_CONCAT(DISTINCT k2.nama_kelas ORDER BY k2.nama_kelas SEPARATOR ', ') FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id JOIN kelas k2 ON kmp2.id_kelas = k2.id WHERE ask2.id_asesmen_sumatif = asum.id), k.nama_kelas, '') AS nama_kelas,
                    COALESCE(k.tahun_pelajaran, (SELECT k2.tahun_pelajaran FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id JOIN kelas k2 ON kmp2.id_kelas = k2.id WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1), '') AS tahun_pelajaran,
                    COALESCE(mp.nama_mata_pelajaran, (SELECT mp2.nama_mata_pelajaran FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1), '') AS nama_mata_pelajaran,
                    asum.judul_asesmen, asum.kktp, asum.jenis_sumatif,
                    COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru
                FROM asesmen_sumatif asum
                LEFT JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru g ON asum.id_guru = g.id
                WHERE asum.id = ?
            ");
            $stmtMeta->execute([$idAsesmen]);
            $metaInfo = $stmtMeta->fetch(PDO::FETCH_ASSOC);

            if (!$metaInfo) {
                $stmtMetaF = $pdo->prepare("
                    SELECT k.nama_kelas, k.tahun_pelajaran, mp.nama_mata_pelajaran,
                        af.judul_asesmen, 75.00 AS kktp, af.teknik_asesmen AS jenis_sumatif,
                        COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru
                    FROM asesmen_formatif af
                    JOIN kelas_mata_pelajaran kmp ON af.id_kelas_mata_pelajaran = kmp.id
                    JOIN kelas k ON kmp.id_kelas = k.id
                    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    LEFT JOIN guru g ON af.id_guru = g.id
                    WHERE af.id = ?
                ");
                $stmtMetaF->execute([$idAsesmen]);
                $metaInfo = $stmtMetaF->fetch(PDO::FETCH_ASSOC);
            }

            if (!$metaInfo) {
                $stmtMetaD = $pdo->prepare("
                    SELECT k.nama_kelas, k.tahun_pelajaran, mp.nama_mata_pelajaran,
                        ad.judul_asesmen, 75.00 AS kktp, ad.jenis_diagnostik AS jenis_sumatif,
                        COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru
                    FROM asesmen_diagnostik ad
                    JOIN kelas_mata_pelajaran kmp ON ad.id_kelas_mata_pelajaran = kmp.id
                    JOIN kelas k ON kmp.id_kelas = k.id
                    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    LEFT JOIN guru g ON ad.id_guru = g.id
                    WHERE ad.id = ?
                ");
                $stmtMetaD->execute([$idAsesmen]);
                $metaInfo = $stmtMetaD->fetch(PDO::FETCH_ASSOC);
            }

            if (!$metaInfo) {
                $stmtMetaR = $pdo->prepare("
                    SELECT k.nama_kelas, k.tahun_pelajaran, mp.nama_mata_pelajaran,
                        pr.judul_program AS judul_asesmen, pr.kktp_minimal AS kktp, pr.kategori AS jenis_sumatif,
                        COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru
                    FROM pengayaan_remedial pr
                    JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id
                    JOIN kelas k ON kmp.id_kelas = k.id
                    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    LEFT JOIN guru g ON pr.id_guru = g.id
                    WHERE pr.id = ?
                ");
                $stmtMetaR->execute([$idAsesmen]);
                $metaInfo = $stmtMetaR->fetch(PDO::FETCH_ASSOC);
            }

            if ($metaInfo) {
                $isPanMeta = isAssessmentPAN('sumatif', $metaInfo['jenis_sumatif'] ?? '');
                $metaInfo['metode_penilaian'] = $isPanMeta ? 'PAN' : 'PAP';
                if ($isPanMeta) {
                    $metaInfo['nilai_norma'] = $stats['rata_rata'] > 0 ? $stats['rata_rata'] : (float)($metaInfo['kktp'] ?? 75.00);
                    $metaInfo['label_acuan'] = 'PAN (Norma Kelas: ' . number_format($metaInfo['nilai_norma'], 1) . ')';
                } else {
                    $metaInfo['nilai_norma'] = (float)($metaInfo['kktp'] ?? 75.00);
                    $metaInfo['label_acuan'] = 'PAP (KKTP: ' . number_format($metaInfo['nilai_norma'], 0) . ')';
                }
            }

            sendResponse(true, [
                'rows'       => $rows,
                'items'      => $rows,
                'stats'      => $stats,
                'meta'       => $metaInfo,
                'pagination' => [
                    'total'    => $total,
                    'page'     => $page,
                    'per_page' => $perPage,
                    'pages'    => ceil($total / $perPage)
                ]
            ], 'Data rekap nilai berhasil dimuat.');

        } catch (Throwable $e) {
            sendResponse(false, [], 'Gagal memuat rekap nilai: ' . $e->getMessage());
        }
    }

    // 1.2 AMBIL RINCIAN JAWABAN CBT PER BUTIR SOAL
    if ($act === 'get_cbt_answers') {
        $kategori  = $req['kategori'] ?? 'sumatif';
        $idAsesmen = (int)($req['id_asesmen'] ?? $req['asesmen'] ?? 0);
        $idSiswa   = (int)($req['id_siswa'] ?? $req['siswa'] ?? 0);

        if (!$idAsesmen || !$idSiswa) {
            sendResponse(false, [], 'Parameter asesmen atau siswa tidak valid.');
        }

        try {
            if ($kategori === 'formatif') {
                $tabelSoal = 'asesmen_formatif_soal';
                $tabelOpsi = 'asesmen_formatif_soal_opsi';
                $tipeCbt   = 'formatif';
            } elseif ($kategori === 'diagnostik') {
                $tabelSoal = 'asesmen_diagnostik_soal';
                $tabelOpsi = 'asesmen_diagnostik_soal_opsi';
                $tipeCbt   = 'diagnostik';
            } elseif ($kategori === 'remedial' || $kategori === 'pengayaan') {
                $tabelSoal = 'pengayaan_remedial_soal';
                $tabelOpsi = 'pengayaan_remedial_soal_opsi';
                $tipeCbt   = 'remedial';
            } else {
                $tabelSoal = 'asesmen_sumatif_soal';
                $tabelOpsi = 'asesmen_sumatif_soal_opsi';
                $tipeCbt   = 'sumatif';
            }

            $sql = "
                SELECT 
                    j.id AS id_jawaban, j.nomor_urut_tampil, j.tipe_soal, j.jawaban_teks,
                    j.is_ragu, j.is_benar, j.is_benar AS is_correct,
                    j.skor_bobot_maks, j.skor_diperoleh, j.skor_diperoleh AS poin_diperoleh,
                    j.catatan_koreksi_guru, j.waktu_simpan,
                    s.id AS id_soal, s.nomor_soal, s.pertanyaan, s.gambar_stimulus,
                    o.kode_opsi AS opsi_dipilih_kode, o.teks_opsi AS opsi_dipilih_teks,
                    COALESCE(j.kode_opsi_dipilih, o.kode_opsi, j.jawaban_teks) AS jawaban_siswa,
                    COALESCE(kunci.kode_opsi, kunci.teks_opsi) AS kunci_jawaban,
                    kunci.kode_opsi AS kunci_kode, kunci.teks_opsi AS kunci_teks
                FROM cbt_jawaban_siswa j
                LEFT JOIN `$tabelSoal` s ON j.id_soal = s.id
                LEFT JOIN `$tabelOpsi` o ON j.id_opsi_dipilih = o.id
                LEFT JOIN `$tabelOpsi` kunci ON (kunci.id_soal = s.id AND kunci.is_kunci_jawaban = 1)
                WHERE j.tipe_asesmen = ? AND j.id_asesmen = ? AND j.id_siswa = ?
                ORDER BY s.nomor_soal ASC, j.nomor_urut_tampil ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$tipeCbt, $idAsesmen, $idSiswa]);
            $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmtSiswa = $pdo->prepare("SELECT s.nama_lengkap, s.nis, s.nisn, k.nama_kelas FROM siswa s JOIN kelas k ON s.id_kelas = k.id WHERE s.id = ?");
            $stmtSiswa->execute([$idSiswa]);
            $siswaInfo = $stmtSiswa->fetch(PDO::FETCH_ASSOC);

            sendResponse(true, [
                'answers'   => $answers,
                'questions' => $answers,
                'siswa'     => $siswaInfo
            ], 'Rincian butir jawaban berhasil dimuat.');

        } catch (Throwable $e) {
            sendResponse(false, [], 'Gagal memuat jawaban CBT: ' . $e->getMessage());
        }
    }

    // 1.3 SIMPAN / KOREKSI NILAI MANUAL (DINONAKTIFKAN — NILAI HANYA VIEW-ONLY)
    if ($act === 'save_score') {
        sendResponse(false, [], 'Fitur edit nilai telah dinonaktifkan. Nilai bersifat resmi dan permanen.');
    }

    // 1.4 HAPUS NILAI (DINONAKTIFKAN — NILAI HANYA VIEW-ONLY)
    if ($act === 'delete_score') {
        sendResponse(false, [], 'Fitur hapus nilai telah dinonaktifkan.');
    }

    sendResponse(false, [], 'Aksi tidak dikenali.');
}

/* ==============================================================================
 * 2. MASTER DATA UNTUK FILTER DROPDOWN
 * ============================================================================== */
$kelasList = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
$asesmenSumatifList = $pdo->query("SELECT id, judul_asesmen, jenis_sumatif FROM asesmen_sumatif ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$asesmenFormatifList = $pdo->query("SELECT id, judul_asesmen, teknik_asesmen FROM asesmen_formatif ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$asesmenDiagnostikList = $pdo->query("SELECT id, judul_asesmen, jenis_diagnostik FROM asesmen_diagnostik ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$asesmenRemedialList = $pdo->query("SELECT id, judul_program AS judul_asesmen, kategori FROM pengayaan_remedial ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-assessment-results.css') ? filemtime(BASE_PATH . '/assets/css/admin-assessment-results.css') : '1.0';
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-assessment-access.css?v=' . $cssVersion . '">'
           . '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-assessment-results.css?v=' . $cssVersion . '">'
           . '<style>
/* Compact Action Buttons Row inside filter card */
.ilmuna-results-actions-top {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: flex-end;
    padding-bottom: 14px;
    margin-bottom: 14px;
    border-bottom: 1px dashed #E2E8F0;
}
.ilmuna-results-actions-top .ilmuna-btn {
    height: 34px;
    padding: 0 14px;
    font-size: 12.5px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
@media (max-width: 768px) {
    .ilmuna-results-actions-top {
        justify-content: flex-start;
    }
}
</style>';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<!-- ==============================================================================
 * LUMINA GOLD THEMED ASSESSMENT RESULTS DASHBOARD (SPA MODE)
 * ============================================================================== -->
<div class="ilmuna-results-app" id="appResults" data-ajax="<?= BASE_URL ?>/dashboard/admin/assessment-results">

    <!-- HIDDEN KPI PLACEHOLDERS — untuk kompatibilitas external admin-assessment-results.js -->
    <div style="display:none !important;" aria-hidden="true">
        <span id="kpiTotalSiswa"></span>
        <span id="kpiDinilaiSub"></span>
        <span id="kpiRataRata"></span>
        <span id="kpiTertinggiSub"></span>
        <span id="kpiTuntas"></span>
        <span id="kpiTuntasPersen"></span>
        <span id="kpiRemedial"></span>
        <span id="kpiCbtSelesai"></span>
        <span id="kpiCbtSub"></span>
    </div>

    <!-- FILTER TOOLBAR CARD + 2 ACTION BUTTONS (EXPORT EXCEL & EXPORT PDF) -->
    <div class="ilmuna-filter-card">

        <!-- Action Buttons Row (Top-right) — Only 2 buttons -->
        <div class="ilmuna-results-actions-top">
            <button type="button" class="ilmuna-btn ilmuna-btn--emerald" id="btnExportExcel" title="Ekspor Rekap Nilai ke format Excel (.xlsx) via Blob">
                <iconify-icon icon="solar:file-spreadsheet-bold" width="16"></iconify-icon>
                <span>Export Excel (.xlsx)</span>
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnExportPdf" title="Cetak / Unduh Laporan Rekap Nilai Resmi via PDF">
                <iconify-icon icon="solar:printer-bold" width="16"></iconify-icon>
                <span>Export / Cetak PDF</span>
            </button>
        </div>

        <!-- Pills row: Kategori + Status -->
        <div class="ilmuna-filter-row">
            <div class="ilmuna-pill-group" id="filterKategoriPills">
                <button type="button" class="ilmuna-pill-btn is-active" data-kategori="all">Semua Kategori</button>
                <button type="button" class="ilmuna-pill-btn" data-kategori="sumatif" style="color: #D97706;">
                    <iconify-icon icon="solar:laptop-minimalistic-bold" width="14"></iconify-icon> Sumatif (CBT)
                </button>
                <button type="button" class="ilmuna-pill-btn" data-kategori="formatif" style="color: #0D7A87;">
                    <iconify-icon icon="solar:checklist-minimalistic-bold" width="14"></iconify-icon> Formatif
                </button>
                <button type="button" class="ilmuna-pill-btn" data-kategori="diagnostik" style="color: #4F46E5;">
                    <iconify-icon icon="solar:clipboard-check-bold" width="14"></iconify-icon> Diagnostik
                </button>
                <button type="button" class="ilmuna-pill-btn" data-kategori="remedial" style="color: #DB2777;">
                    <iconify-icon icon="solar:medal-ribbon-star-bold" width="14"></iconify-icon> Pengayaan / Remedial
                </button>
            </div>

            <div class="ilmuna-pill-group" id="filterStatusPills">
                <button type="button" class="ilmuna-pill-btn is-active" data-status="all">Semua Status</button>
                <button type="button" class="ilmuna-pill-btn" data-status="tuntas" style="color: #10B981;">
                    <iconify-icon icon="solar:check-circle-bold" width="14"></iconify-icon> Tuntas
                </button>
                <button type="button" class="ilmuna-pill-btn" data-status="remedial" style="color: #EF4444;">
                    <iconify-icon icon="solar:close-circle-bold" width="14"></iconify-icon> Remedial
                </button>
                <button type="button" class="ilmuna-pill-btn" data-status="unrated" style="color: #64748B;">
                    <iconify-icon icon="solar:clock-circle-bold" width="14"></iconify-icon> Belum Dinilai
                </button>
                <button type="button" class="ilmuna-pill-btn" data-status="cbt_sedang" style="color: #D97706;">
                    <iconify-icon icon="solar:play-circle-bold" width="14"></iconify-icon> Sedang CBT
                </button>
            </div>
        </div>

        <!-- TAHAPAN FILTER BERURUTAN (LANGKAH 1 -> LANGKAH 2 -> LANGKAH 3) -->
        <div class="ilmuna-filter-steps-wrap" style="margin-top: 16px;">
            <div class="ilmuna-step-item" id="stepBoxKelas">
                <div class="ilmuna-step-label">
                    <span class="ilmuna-step-badge" id="badgeStep1">1</span>
                    <span>Pilih Rombel Kelas <strong style="color: #EF4444;">*</strong></span>
                </div>
                <select id="filterKelas" class="ilmuna-select">
                    <option value="">— 1. Pilih Rombel Kelas —</option>
                    <?php foreach ($kelasList as $k): ?>
                        <option value="<?= $k['id'] ?>">
                            <?= htmlspecialchars($k['nama_kelas']) ?> (<?= htmlspecialchars($k['tahun_pelajaran']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ilmuna-step-item" id="stepBoxMapel">
                <div class="ilmuna-step-label">
                    <span class="ilmuna-step-badge" id="badgeStep2">2</span>
                    <span>Pilih Mata Pelajaran <strong style="color: #EF4444;">*</strong></span>
                </div>
                <select id="filterMapel" class="ilmuna-select" disabled>
                    <option value="">— 2. Pilih Kelas Dahulu —</option>
                </select>
            </div>

            <div class="ilmuna-step-item" id="stepBoxAsesmen">
                <div class="ilmuna-step-label">
                    <span class="ilmuna-step-badge" id="badgeStep3">3</span>
                    <span>Pilih Paket Asesmen <strong style="color: #EF4444;">*</strong></span>
                </div>
                <select id="filterAsesmen" class="ilmuna-select" disabled>
                    <option value="">— 3. Pilih Mapel Dahulu —</option>
                </select>
            </div>

            <div class="ilmuna-step-controls">
                <select id="filterPerPage" class="ilmuna-select" style="min-width: 125px;">
                    <option value="20" selected>20 Baris / Hal</option>
                    <option value="50">50 Baris / Hal</option>
                    <option value="100">100 Baris / Hal</option>
                </select>
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline ilmuna-btn--sm" id="btnResetFilter" title="Reset Semua Filter">
                    <iconify-icon icon="solar:restart-bold"></iconify-icon> Reset
                </button>
            </div>
        </div>

        <div class="ilmuna-filter-row" style="margin-top: 10px;">
            <div class="ilmuna-search-wrap" style="flex: 1;">
                <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                <input type="text" id="filterSearch" class="ilmuna-search-input" placeholder="Ketik 1 huruf langsung mencari nama siswa, NISN, catatan..." autocomplete="off">
            </div>
        </div>
    </div>

    <!-- MAIN DATA TABLE CARD -->
    <div class="ilmuna-table-card">
        <div class="ilmuna-table-wrap">
            <table class="ilmuna-table" id="tableResults">
                <thead>
                    <tr>
                        <th style="width: 45px; text-align: center;">No</th>
                        <th>Identitas Siswa &amp; Rombel</th>
                        <th>Paket Asesmen &amp; Mapel</th>
                        <th style="text-align: center;">Status Ujian CBT</th>
                        <th style="text-align: center;">Nilai Angka</th>
                        <th style="text-align: center;">Predikat</th>
                        <th style="text-align: center;">Ketuntasan</th>
                        <th>Catatan Evaluasi Guru</th>
                        <th style="width: 130px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableResultsBody">
                    <?php for ($sk = 0; $sk < 5; $sk++): ?>
                    <tr class="ilmuna-skeleton-row">
                        <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 22px;"></div></td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div class="ilmuna-skeleton-circle"></div>
                                <div>
                                    <div class="ilmuna-skeleton-box" style="width: 140px; margin-bottom: 4px;"></div><br>
                                    <div class="ilmuna-skeleton-box" style="width: 90px; height: 10px;"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="ilmuna-skeleton-box" style="width: 160px; margin-bottom: 4px;"></div><br>
                            <div class="ilmuna-skeleton-box" style="width: 100px; height: 10px;"></div>
                        </td>
                        <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 90px;"></div></td>
                        <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 60px;"></div></td>
                        <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 45px;"></div></td>
                        <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 80px;"></div></td>
                        <td><div class="ilmuna-skeleton-box" style="width: 120px;"></div></td>
                        <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 85px;"></div></td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <div class="ilmuna-pagination-bar">
            <div class="ilmuna-pagination-info" id="paginationInfo">Memuat data...</div>
            <div class="ilmuna-pagination-btns" id="paginationBtns"></div>
        </div>
    </div>

</div>

<!-- ==============================================================================
 * MODAL 1: RINCIAN BUTIR JAWABAN CBT SISWA
 * ============================================================================== -->
<div class="ilmuna-custom-modal" id="modalCbtDetail">
    <div class="ilmuna-modal-overlay" data-close="modalCbtDetail"></div>
    <div class="ilmuna-modal-container" style="max-width: 820px;">
        <div class="ilmuna-modal-header" style="background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%); color: #ffffff;">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:document-text-bold" width="22"></iconify-icon>
                <h3 style="color: #ffffff; margin: 0;">Lembar Jawaban CBT Siswa</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="modalCbtDetail" style="color: #ffffff;">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body" style="padding: 20px; max-height: 75vh; overflow-y: auto;">
            <div id="cbtDetailSiswaHeader" style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 14px 18px; margin-bottom: 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <div>
                    <h4 id="cbtSiswaNama" style="margin: 0; font-size: 16px; font-weight: 800; color: #1E293B;">Nama Siswa</h4>
                    <span id="cbtSiswaMeta" style="font-size: 12.5px; color: #64748B;">NIS / NISN • Kelas</span>
                </div>
                <div id="cbtScoreSummary" style="display: flex; gap: 10px; align-items: center;"></div>
            </div>

            <div id="cbtQuestionsContainer" style="display: flex; flex-direction: column; gap: 14px;">
                <div style="text-align: center; padding: 40px; color: #94A3B8;">Memuat butir jawaban...</div>
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="modalCbtDetail">Tutup</button>
        </div>
    </div>
</div>

<!-- MODAL KONFIRMASI TINDAKAN -->
<div class="ilmuna-access-modal" id="confirmModal">
    <div class="ilmuna-access-modal__overlay" data-close="1"></div>
    <div class="ilmuna-access-modal__box" style="max-width: 440px; text-align: center;">
        <div class="ilmuna-access-modal__body" style="padding: 28px 24px 20px;">
            <div id="confirmIconWrap" style="width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; background: #FEF3C7; color: #D97706; font-size: 32px;">
                <iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>
            </div>
            <h4 id="confirmTitle" style="font-size: 17px; font-weight: 800; color: #1D3B5E; margin: 0 0 8px;">Konfirmasi Tindakan</h4>
            <p id="confirmMsg" style="font-size: 13.5px; color: #64748B; margin: 0 0 20px; line-height: 1.5;"></p>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" id="btnConfirmCancel" style="flex: 1; justify-content: center;">Batal</button>
                <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnConfirmOk" style="flex: 1; justify-content: center;">Ya, Lanjutkan</button>
            </div>
        </div>
    </div>
</div>

<!-- CONTAINER IFRAME PRINT BLOB TERSEMBUNYI -->
<iframe id="printBlobIframe" style="position: absolute; width: 0; height: 0; border: 0; left: -9999px;"></iframe>

<?php
$jsVersion = file_exists(BASE_PATH . '/assets/js/admin-assessment-results.js') ? filemtime(BASE_PATH . '/assets/js/admin-assessment-results.js') : '1.0';
?>
<script src="<?= BASE_URL ?>/assets/js/admin-assessment-results.js?v=<?= $jsVersion ?>"></script>

<?php
require_once BASE_PATH . '/includes/footer.php';
?>