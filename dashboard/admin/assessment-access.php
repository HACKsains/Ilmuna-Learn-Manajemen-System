<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MANAJEMEN AKSES & KREDENSIAL UJIAN SISWA (CBT & ASESMEN)
 * ==============================================================================
 * Sesuai Skema Database: skema-tabel-kelas.sql
 * Tabel: akses_asesmen_formatif, akses_asesmen_sumatif, asesmen_sumatif, asesmen_formatif, siswa, kelas
 * Arsitektur: Pure Reactive SPA via AJAX (Tanpa reload URL / render ulang halaman),
 *              Live Search 1 Karakter ala WhatsApp, Tab In-Page, Kartu Peserta Ujian
 *              (Kredensial Username & Password tanpa membocorkan Token Sesi).
 * Tema Warna: Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Gold #F59E0B)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';
require_once __DIR__ . '/../../includes/email-kartu-ujian-template.php';
require_once __DIR__ . '/../../includes/kartu-ujian-sumatif-pdf.php';
require_once __DIR__ . '/../../includes/daftar-token-asesmen-pdf.php';

if (php_sapi_name() !== 'cli') {
    requireRole(['admin', 'guru']);
}

$pdo = getDBConnection();
$pageTitle = 'Akses & Kredensial Ujian Siswa (CBT)';
$pageIcon = 'solar:key-minimalistic-square-3-bold';

if (!function_exists('generateCbtToken')) {
    function generateCbtToken($length = 6) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $res = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $res .= $chars[random_int(0, $max)];
        }
        return $res;
    }
}

if (!function_exists('aksesTableName')) {
    function aksesTableName($tipe) {
        switch ($tipe) {
            case 'sumatif':    return 'akses_asesmen_sumatif';
            case 'diagnostik': return 'akses_asesmen_diagnostik';
            case 'remedial':   return 'akses_pengayaan_remedial';
            default:           return 'akses_asesmen_formatif';
        }
    }
    function aksesFkName($tipe) {
        switch ($tipe) {
            case 'sumatif':    return 'id_asesmen_sumatif';
            case 'diagnostik': return 'id_asesmen_diagnostik';
            case 'remedial':   return 'id_pengayaan_remedial';
            default:           return 'id_asesmen_formatif';
        }
    }
}

if (!function_exists('getCardDataForTicket')) {
    function getCardDataForTicket($pdo, $tipe, $idAkses) {
        if ($tipe === 'diagnostik') {
            $stmt = $pdo->prepare("
                SELECT 
                    aad.id, aad.nomor_peserta, aad.username_ujian, aad.password_ujian,
                    s.id AS id_siswa, s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.gmail, s.foto,
                    k.nama_kelas, COALESCE(k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
                    COALESCE(mp.semester, 'Ganjil') AS semester,
                    COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran,
                    ad.judul_asesmen, ad.pertemuan_ke, ad.durasi_menit, ad.tanggal_pelaksanaan, ad.jam_mulai, ad.jam_selesai
                FROM akses_asesmen_diagnostik aad
                JOIN siswa s ON aad.id_siswa = s.id
                JOIN kelas k ON s.id_kelas = k.id
                JOIN asesmen_diagnostik ad ON aad.id_asesmen_diagnostik = ad.id
                JOIN kelas_mata_pelajaran kmp ON aad.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE aad.id = ?
            ");
            $stmt->execute([$idAkses]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $row['tipe_kategori'] = 'diagnostik';
            return $row;
        } elseif ($tipe === 'formatif') {
            $stmt = $pdo->prepare("
                SELECT 
                    aaf.id, aaf.nomor_peserta, aaf.username_ujian, aaf.password_ujian,
                    s.id AS id_siswa, s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.gmail, s.foto,
                    k.nama_kelas, COALESCE(k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
                    COALESCE(mp.semester, 'Ganjil') AS semester,
                    COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran,
                    af.judul_asesmen, af.pertemuan_ke, af.durasi_menit, af.tanggal_pelaksanaan, af.jam_mulai, af.jam_selesai
                FROM akses_asesmen_formatif aaf
                JOIN siswa s ON aaf.id_siswa = s.id
                JOIN kelas k ON s.id_kelas = k.id
                JOIN asesmen_formatif af ON aaf.id_asesmen_formatif = af.id
                JOIN kelas_mata_pelajaran kmp ON aaf.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE aaf.id = ?
            ");
            $stmt->execute([$idAkses]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $row['tipe_kategori'] = 'formatif';
            return $row;
        } elseif ($tipe === 'sumatif') {
            $stmt = $pdo->prepare("
                SELECT 
                    aas.id, aas.nomor_peserta, aas.username_ujian, aas.password_ujian,
                    s.id AS id_siswa, s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.gmail, s.foto,
                    k.nama_kelas, COALESCE(aas.tahun_pelajaran, asum.tahun_pelajaran, k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
                    COALESCE(aas.semester, asum.semester, mp.semester, 'Ganjil') AS semester,
                    COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran,
                    asum.judul_asesmen, asum.pertemuan_ke, asum.durasi_menit, asum.tanggal_pelaksanaan, asum.jam_mulai, asum.jam_selesai
                FROM akses_asesmen_sumatif aas
                JOIN siswa s ON aas.id_siswa = s.id
                JOIN kelas k ON s.id_kelas = k.id
                JOIN asesmen_sumatif asum ON aas.id_asesmen_sumatif = asum.id
                JOIN kelas_mata_pelajaran kmp ON aas.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE aas.id = ?
            ");
            $stmt->execute([$idAkses]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $row['tipe_kategori'] = 'sumatif';
            return $row;
        } elseif ($tipe === 'remedial') {
            $stmt = $pdo->prepare("
                SELECT 
                    apr.id, apr.nomor_peserta, apr.username_ujian, apr.password_ujian,
                    s.id AS id_siswa, s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.gmail, s.foto,
                    k.nama_kelas, COALESCE(k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
                    COALESCE(mp.semester, 'Ganjil') AS semester,
                    COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran,
                    pr.judul_program AS judul_asesmen, pr.pertemuan_ke, pr.durasi_menit, pr.tanggal_pelaksanaan, pr.jam_mulai, pr.jam_selesai
                FROM akses_pengayaan_remedial apr
                JOIN siswa s ON apr.id_siswa = s.id
                JOIN kelas k ON s.id_kelas = k.id
                JOIN pengayaan_remedial pr ON apr.id_pengayaan_remedial = pr.id
                JOIN kelas_mata_pelajaran kmp ON apr.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE apr.id = ?
            ");
            $stmt->execute([$idAkses]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) $row['tipe_kategori'] = 'remedial';
            return $row;
        }
        return null;
    }
}

/* ==============================================================================
 * 1. BACKEND AJAX API HANDLERS (JSON ONLY)
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];

    // 0. DOWNLOAD KARTU SUMATIF PDF (A4 DIBAGI 4, METODE DIRECT BLOB DOWNLOAD)
    if ($act === 'download_sumatif_pdf') {
        @set_time_limit(0);
        @ignore_user_abort(true);
        while (ob_get_level()) ob_end_clean();

        $rowIdsRaw      = $_POST['row_ids'] ?? '';
        $idAsesmen      = (int)($_POST['asesmen_id'] ?? 0);
        $idKelas        = (int)($_POST['kelas_id'] ?? 0);
        $idMapel        = (int)($_POST['mapel_id'] ?? 0);
        $tahunPelajaran = trim($_POST['tahun_pelajaran'] ?? '');
        $semester       = trim($_POST['semester'] ?? '');
        $jenisSumatif   = trim($_POST['jenis_sumatif'] ?? '');

        $targetIds = [];
        if (!empty($rowIdsRaw)) {
            $parsed = is_array($rowIdsRaw) ? $rowIdsRaw : json_decode($rowIdsRaw, true);
            if (is_array($parsed)) {
                foreach ($parsed as $item) {
                    $itemStr = (string)$item;
                    if (strpos($itemStr, 'sumatif_') === 0) {
                        $targetIds[] = (int)substr($itemStr, 8);
                    } elseif (is_numeric($itemStr)) {
                        $targetIds[] = (int)$itemStr;
                    }
                }
            }
        }

        $cardsData = [];
        if (!empty($targetIds)) {
            $inPlaceholders = implode(',', array_fill(0, count($targetIds), '?'));
            $stmt = $pdo->prepare("
                SELECT 
                    aas.id, aas.nomor_peserta, aas.username_ujian, aas.password_ujian,
                    s.id AS id_siswa, s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.gmail, s.foto,
                    k.id AS id_kelas, k.nama_kelas, COALESCE(aas.tahun_pelajaran, asum.tahun_pelajaran, k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
                    COALESCE(aas.semester, asum.semester, mp.semester, 'Ganjil') AS semester,
                    COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran,
                    asum.id AS id_asesmen, asum.judul_asesmen, asum.pertemuan_ke, asum.durasi_menit, asum.tanggal_pelaksanaan, asum.jam_mulai, asum.jam_selesai,
                    asum.jenis_sumatif
                FROM akses_asesmen_sumatif aas
                JOIN siswa s ON aas.id_siswa = s.id
                JOIN kelas k ON s.id_kelas = k.id
                JOIN asesmen_sumatif asum ON aas.id_asesmen_sumatif = asum.id
                JOIN kelas_mata_pelajaran kmp ON aas.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE aas.id IN ($inPlaceholders)
                ORDER BY k.nama_kelas ASC, s.nama_lengkap ASC
            ");
            $stmt->execute($targetIds);
            $cardsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($idAsesmen > 0 || $idKelas > 0 || $idMapel > 0 || ($tahunPelajaran !== '' && $tahunPelajaran !== 'all') || ($semester !== '' && $semester !== 'all') || ($jenisSumatif !== '' && $jenisSumatif !== 'all')) {
            $conditions = [];
            $params = [];
            if ($idAsesmen > 0) {
                $conditions[] = "aas.id_asesmen_sumatif = ?";
                $params[] = $idAsesmen;
            }
            if ($idKelas > 0) {
                $conditions[] = "kmp.id_kelas = ?";
                $params[] = $idKelas;
            }
            if ($idMapel > 0) {
                $conditions[] = "kmp.id_mata_pelajaran = ?";
                $params[] = $idMapel;
            }
            if ($tahunPelajaran !== '' && $tahunPelajaran !== 'all') {
                $conditions[] = "COALESCE(aas.tahun_pelajaran, asum.tahun_pelajaran, k.tahun_pelajaran) = ?";
                $params[] = $tahunPelajaran;
            }
            if ($semester !== '' && $semester !== 'all') {
                $conditions[] = "COALESCE(aas.semester, asum.semester) = ?";
                $params[] = $semester;
            }
            if ($jenisSumatif !== '' && $jenisSumatif !== 'all') {
                $conditions[] = "asum.jenis_sumatif = ?";
                $params[] = $jenisSumatif;
            }
            $whereClause = !empty($conditions) ? implode(" AND ", $conditions) : "1=1";
            $stmt = $pdo->prepare("
                SELECT 
                    aas.id, aas.nomor_peserta, aas.username_ujian, aas.password_ujian,
                    s.id AS id_siswa, s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.gmail, s.foto,
                    k.id AS id_kelas, k.nama_kelas, COALESCE(aas.tahun_pelajaran, asum.tahun_pelajaran, k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
                    COALESCE(aas.semester, asum.semester, mp.semester, 'Ganjil') AS semester,
                    COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran,
                    asum.id AS id_asesmen, asum.judul_asesmen, asum.pertemuan_ke, asum.durasi_menit, asum.tanggal_pelaksanaan, asum.jam_mulai, asum.jam_selesai,
                    asum.jenis_sumatif
                FROM akses_asesmen_sumatif aas
                JOIN siswa s ON aas.id_siswa = s.id
                JOIN kelas k ON s.id_kelas = k.id
                JOIN asesmen_sumatif asum ON aas.id_asesmen_sumatif = asum.id
                JOIN kelas_mata_pelajaran kmp ON aas.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE $whereClause
                ORDER BY k.nama_kelas ASC, s.nama_lengkap ASC
            ");
            $stmt->execute($params);
            $cardsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($cardsData)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Tidak ada data peserta asesmen sumatif yang dapat dicetak.']);
            exit;
        }

        // Ambil jadwal asesmen sumatif yang sebenarnya diikuti oleh masing-masing siswa dari tabel asesmen_sumatif
        $schedulesByStudent = [];
        $stmtJadwalSiswa = $pdo->prepare("
            SELECT 
                asum.id,
                asum.judul_asesmen,
                asum.jenis_sumatif,
                asum.tanggal_pelaksanaan,
                asum.jam_mulai,
                asum.jam_selesai,
                asum.durasi_menit,
                COALESCE(MAX(mp.nama_mata_pelajaran), '') AS nama_mata_pelajaran,
                MAX(mp.kode_mapel) AS kode_mapel
            FROM akses_asesmen_sumatif aas
            JOIN asesmen_sumatif asum ON aas.id_asesmen_sumatif = asum.id
            LEFT JOIN kelas_mata_pelajaran kmp ON aas.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            WHERE aas.id_siswa = ?
            GROUP BY asum.id, asum.judul_asesmen, asum.jenis_sumatif, asum.tanggal_pelaksanaan, asum.jam_mulai, asum.jam_selesai, asum.durasi_menit
            ORDER BY asum.tanggal_pelaksanaan ASC, asum.jam_mulai ASC
        ");

        foreach ($cardsData as &$cItem) {
            $sId = (int)($cItem['id_siswa'] ?? 0);
            if ($sId > 0 && !isset($schedulesByStudent[$sId])) {
                $stmtJadwalSiswa->execute([$sId]);
                $schedulesByStudent[$sId] = $stmtJadwalSiswa->fetchAll(PDO::FETCH_ASSOC);
            }
            $cItem['jadwal'] = $schedulesByStudent[$sId] ?? [];
            if (empty($cItem['jadwal']) && !empty($cItem['judul_asesmen'])) {
                $cItem['jadwal'] = [[
                    'id'                  => $cItem['id_asesmen'] ?? 0,
                    'tanggal_pelaksanaan' => $cItem['tanggal_pelaksanaan'] ?? date('Y-m-d'),
                    'jam_mulai'           => $cItem['jam_mulai'] ?? '07:30',
                    'jam_selesai'         => $cItem['jam_selesai'] ?? '09:00',
                    'durasi_menit'        => $cItem['durasi_menit'] ?? 60,
                    'nama_mata_pelajaran' => $cItem['nama_mata_pelajaran'] ?? '',
                    'jenis_sumatif'       => $cItem['jenis_sumatif'] ?? 'Sumatif',
                    'judul_asesmen'       => $cItem['judul_asesmen'] ?? 'Asesmen Sumatif (CBT)'
                ]];
            }
        }
        unset($cItem);

        // 1 Siswa HANYA memiliki 1 kartu ujian sumatif: jika ada lebih dari 1 asesmen, gabungkan ke jadwal
        $cardsData = ilmuna_group_sumatif_cards_by_student($cardsData);

        $pdfResult = ilmuna_generate_kartu_ujian_sumatif_pdf($cardsData);
        if (!is_array($pdfResult) || empty($pdfResult['ok']) || empty($pdfResult['path']) || !file_exists($pdfResult['path'])) {
            $errMsg = (is_array($pdfResult) && !empty($pdfResult['error']))
                ? $pdfResult['error']
                : 'Gagal membuat berkas PDF kartu ujian sumatif.';
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $errMsg]);
            exit;
        }
        $pdfPath = $pdfResult['path'];

        $filename = 'Kartu_Ujian_SUMATIF_A4_' . date('Ymd_His') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($pdfPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($pdfPath);
        @unlink($pdfPath);
        exit;
    }

    // 0.1 DOWNLOAD DAFTAR TOKEN ASESMEN PDF (METODE DIRECT BLOB DOWNLOAD)
    if ($act === 'download_token_pdf') {
        @set_time_limit(0);
        @ignore_user_abort(true);
        while (ob_get_level()) ob_end_clean();

        $tipe           = in_array($_POST['tipe'] ?? '', ['diagnostik', 'formatif', 'sumatif', 'remedial', 'all'], true) ? $_POST['tipe'] : 'sumatif';
        $idAsesmen      = (int)($_POST['asesmen_id'] ?? 0);
        $idKelas        = (int)($_POST['kelas_id'] ?? 0);
        $idMapel        = (int)($_POST['mapel_id'] ?? 0);
        $tahunPelajaran = trim($_POST['tahun_pelajaran'] ?? '');
        $semester       = trim($_POST['semester'] ?? '');
        $jenisSumatif   = trim($_POST['jenis_sumatif'] ?? '');

        $tokensData = [];

        // 1. QUERY ASESMEN SUMATIF
        if ($tipe === 'sumatif' || $tipe === 'all') {
            $conds = [];
            $params = [];
            if ($idAsesmen > 0) {
                $conds[] = "asum.id = ?";
                $params[] = $idAsesmen;
            }
            if ($idKelas > 0) {
                $conds[] = "(kmp.id_kelas = ? OR ask.id_kelas = ?)";
                $params[] = $idKelas;
                $params[] = $idKelas;
            }
            if ($idMapel > 0) {
                $conds[] = "(kmp.id_mata_pelajaran = ? OR ask_mp.id_mata_pelajaran = ?)";
                $params[] = $idMapel;
                $params[] = $idMapel;
            }
            if ($tahunPelajaran !== '' && $tahunPelajaran !== 'all') {
                $conds[] = "COALESCE(asum.tahun_pelajaran, mp.tahun_pelajaran, k.tahun_pelajaran) = ?";
                $params[] = $tahunPelajaran;
            }
            if ($semester !== '' && $semester !== 'all') {
                $conds[] = "COALESCE(asum.semester, mp.semester) = ?";
                $params[] = $semester;
            }
            if ($jenisSumatif !== '' && $jenisSumatif !== 'all') {
                $conds[] = "asum.jenis_sumatif = ?";
                $params[] = $jenisSumatif;
            }
            $whereSql = !empty($conds) ? ("WHERE " . implode(" AND ", $conds)) : "";

            $stmt = $pdo->prepare("
                SELECT 
                    'sumatif' AS tipe_kategori,
                    asum.id AS id_asesmen,
                    asum.judul_asesmen,
                    asum.jenis_sumatif,
                    asum.durasi_menit,
                    asum.pertemuan_ke,
                    asum.tanggal_pelaksanaan,
                    asum.jam_mulai,
                    asum.jam_selesai,
                    COALESCE(asum.semester, mp.semester, 'Ganjil') AS semester,
                    COALESCE(asum.tahun_pelajaran, mp.tahun_pelajaran, k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
                    COALESCE((SELECT aas_tok.token_sesi FROM akses_asesmen_sumatif aas_tok WHERE aas_tok.id_asesmen_sumatif = asum.id AND aas_tok.token_sesi IS NOT NULL AND aas_tok.token_sesi != '' LIMIT 1), '-') AS token_sesi,
                    COALESCE((SELECT COUNT(*) FROM akses_asesmen_sumatif aas WHERE aas.id_asesmen_sumatif = asum.id), 0) AS total_peserta,
                    COALESCE((SELECT GROUP_CONCAT(DISTINCT k2.nama_kelas ORDER BY k2.nama_kelas SEPARATOR ', ') FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id JOIN kelas k2 ON kmp2.id_kelas = k2.id WHERE ask2.id_asesmen_sumatif = asum.id), k.nama_kelas, '') AS nama_kelas,
                    COALESCE(mp.nama_mata_pelajaran, (SELECT mp2.nama_mata_pelajaran FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1), 'Umum') AS nama_mata_pelajaran
                FROM asesmen_sumatif asum
                LEFT JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN asesmen_sumatif_kelas ask ON (ask.id_asesmen_sumatif = asum.id)
                LEFT JOIN kelas_mata_pelajaran ask_mp ON (ask.id_kelas_mata_pelajaran = ask_mp.id)
                $whereSql
                GROUP BY asum.id
                ORDER BY asum.tanggal_pelaksanaan ASC, asum.jam_mulai ASC, asum.id DESC
            ");
            $stmt->execute($params);
            $tokensData = array_merge($tokensData, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        // 2. QUERY ASESMEN FORMATIF
        if ($tipe === 'formatif' || $tipe === 'all') {
            $conds = [];
            $params = [];
            if ($idAsesmen > 0) {
                $conds[] = "af.id = ?";
                $params[] = $idAsesmen;
            }
            if ($idKelas > 0) {
                $conds[] = "kmp.id_kelas = ?";
                $params[] = $idKelas;
            }
            if ($idMapel > 0) {
                $conds[] = "kmp.id_mata_pelajaran = ?";
                $params[] = $idMapel;
            }
            $whereSql = !empty($conds) ? ("WHERE " . implode(" AND ", $conds)) : "";

            $stmt = $pdo->prepare("
                SELECT 
                    'formatif' AS tipe_kategori,
                    af.id AS id_asesmen,
                    af.judul_asesmen,
                    af.teknik_asesmen AS jenis_sumatif,
                    af.durasi_menit,
                    af.pertemuan_ke,
                    af.tanggal_pelaksanaan,
                    af.jam_mulai,
                    af.jam_selesai,
                    COALESCE(mp.semester, 'Ganjil') AS semester,
                    COALESCE(k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
                    COALESCE((SELECT aaf_tok.token_sesi FROM akses_asesmen_formatif aaf_tok WHERE aaf_tok.id_asesmen_formatif = af.id AND aaf_tok.token_sesi IS NOT NULL AND aaf_tok.token_sesi != '' LIMIT 1), '-') AS token_sesi,
                    COALESCE((SELECT COUNT(*) FROM akses_asesmen_formatif aaf WHERE aaf.id_asesmen_formatif = af.id), 0) AS total_peserta,
                    k.nama_kelas,
                    COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran
                FROM asesmen_formatif af
                JOIN kelas_mata_pelajaran kmp ON af.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                $whereSql
                GROUP BY af.id
                ORDER BY af.tanggal_pelaksanaan ASC, af.jam_mulai ASC, af.id DESC
            ");
            $stmt->execute($params);
            $tokensData = array_merge($tokensData, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        // 3. QUERY ASESMEN DIAGNOSTIK
        if ($tipe === 'diagnostik' || $tipe === 'all') {
            $conds = [];
            $params = [];
            if ($idAsesmen > 0) {
                $conds[] = "ad.id = ?";
                $params[] = $idAsesmen;
            }
            if ($idKelas > 0) {
                $conds[] = "kmp.id_kelas = ?";
                $params[] = $idKelas;
            }
            if ($idMapel > 0) {
                $conds[] = "kmp.id_mata_pelajaran = ?";
                $params[] = $idMapel;
            }
            $whereSql = !empty($conds) ? ("WHERE " . implode(" AND ", $conds)) : "";

            $stmt = $pdo->prepare("
                SELECT 
                    'diagnostik' AS tipe_kategori,
                    ad.id AS id_asesmen,
                    ad.judul_asesmen,
                    ad.jenis_diagnostik AS jenis_sumatif,
                    ad.durasi_menit,
                    ad.pertemuan_ke,
                    ad.tanggal_pelaksanaan,
                    ad.jam_mulai,
                    ad.jam_selesai,
                    COALESCE(mp.semester, 'Ganjil') AS semester,
                    COALESCE(k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
                    COALESCE((SELECT aad_tok.token_sesi FROM akses_asesmen_diagnostik aad_tok WHERE aad_tok.id_asesmen_diagnostik = ad.id AND aad_tok.token_sesi IS NOT NULL AND aad_tok.token_sesi != '' LIMIT 1), '-') AS token_sesi,
                    COALESCE((SELECT COUNT(*) FROM akses_asesmen_diagnostik aad WHERE aad.id_asesmen_diagnostik = ad.id), 0) AS total_peserta,
                    k.nama_kelas,
                    COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran
                FROM asesmen_diagnostik ad
                JOIN kelas_mata_pelajaran kmp ON ad.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                $whereSql
                GROUP BY ad.id
                ORDER BY ad.tanggal_pelaksanaan ASC, ad.jam_mulai ASC, ad.id DESC
            ");
            $stmt->execute($params);
            $tokensData = array_merge($tokensData, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        // 4. QUERY REMEDIAL / PENGAYAAN
        if ($tipe === 'remedial' || $tipe === 'all') {
            $conds = [];
            $params = [];
            if ($idAsesmen > 0) {
                $conds[] = "pr.id = ?";
                $params[] = $idAsesmen;
            }
            if ($idKelas > 0) {
                $conds[] = "kmp.id_kelas = ?";
                $params[] = $idKelas;
            }
            if ($idMapel > 0) {
                $conds[] = "kmp.id_mata_pelajaran = ?";
                $params[] = $idMapel;
            }
            $whereSql = !empty($conds) ? ("WHERE " . implode(" AND ", $conds)) : "";

            $stmt = $pdo->prepare("
                SELECT 
                    'remedial' AS tipe_kategori,
                    pr.id AS id_asesmen,
                    pr.judul_program AS judul_asesmen,
                    pr.kategori AS jenis_sumatif,
                    pr.durasi_menit,
                    pr.pertemuan_ke,
                    pr.tanggal_pelaksanaan,
                    pr.jam_mulai,
                    pr.jam_selesai,
                    COALESCE(mp.semester, 'Ganjil') AS semester,
                    COALESCE(k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
                    COALESCE((SELECT apr_t.token_sesi FROM akses_pengayaan_remedial apr_t WHERE apr_t.id_pengayaan_remedial = pr.id AND apr_t.token_sesi IS NOT NULL AND apr_t.token_sesi != '' LIMIT 1), '-') AS token_sesi,
                    COALESCE((SELECT COUNT(*) FROM akses_pengayaan_remedial apr WHERE apr.id_pengayaan_remedial = pr.id), 0) AS total_peserta,
                    k.nama_kelas,
                    COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran
                FROM pengayaan_remedial pr
                JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id
                JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                $whereSql
                GROUP BY pr.id
                ORDER BY pr.tanggal_pelaksanaan ASC, pr.jam_mulai ASC, pr.id DESC
            ");
            $stmt->execute($params);
            $tokensData = array_merge($tokensData, $stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if (empty($tokensData)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Tidak ada data asesmen/token yang sesuai dengan filter yang dipilih.']);
            exit;
        }

        $namaKelasTxt = 'Semua Kelas';
        if ($idKelas > 0) {
            $stmtKl = $pdo->prepare("SELECT nama_kelas FROM kelas WHERE id = ?");
            $stmtKl->execute([$idKelas]);
            $namaKelasTxt = $stmtKl->fetchColumn() ?: 'Kelas #' . $idKelas;
        }

        $metaInfo = [
            'tipe'            => $tipe,
            'tahun_pelajaran' => $tahunPelajaran ?: '2026/2027',
            'semester'        => $semester ?: 'Ganjil',
            'jenis_sumatif'   => $jenisSumatif,
            'nama_kelas'      => $namaKelasTxt
        ];

        $pdfResult = ilmuna_generate_daftar_token_pdf($tokensData, $metaInfo);
        if (!is_array($pdfResult) || empty($pdfResult['ok']) || empty($pdfResult['path']) || !file_exists($pdfResult['path'])) {
            $errMsg = (is_array($pdfResult) && !empty($pdfResult['error']))
                ? $pdfResult['error']
                : 'Gagal membuat berkas PDF daftar token asesmen.';
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $errMsg]);
            exit;
        }
        $pdfPath = $pdfResult['path'];

        $filename = 'Daftar_Token_CBT_' . strtoupper($tipe) . '_' . date('Ymd_His') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($pdfPath));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        readfile($pdfPath);
        @unlink($pdfPath);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');

    // 1.0 CHECK EVENT VERSION
    if ($act === 'check_event') {
        $clientVer = (string)($_POST['v'] ?? '');
        $flagVer = getCbtEventVersion();

        // Deteksi perubahan real-time melalui MAX(updated_at) dari cbt_sesi_ujian dan seluruh tabel akses
        $stmtMax = $pdo->query("
            SELECT GREATEST(
                COALESCE((SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM cbt_sesi_ujian), 0),
                COALESCE((SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM akses_asesmen_sumatif), 0),
                COALESCE((SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM akses_asesmen_formatif), 0),
                COALESCE((SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM akses_asesmen_diagnostik), 0),
                COALESCE((SELECT UNIX_TIMESTAMP(MAX(updated_at)) FROM akses_pengayaan_remedial), 0)
            ) AS latest_ts
        ");
        $maxTs = (int)($stmtMax ? $stmtMax->fetchColumn() : 0);
        $currentVer = $flagVer . '_' . $maxTs;

        echo json_encode([
            'success'     => true,
            'has_changes' => ($clientVer !== $currentVer),
            'v'           => $currentVer
        ]);
        exit;
    }

    // 1.1 FETCH LIST
    if ($act === 'list') {
        $tipe         = $_POST['tipe'] ?? 'diagnostik';
        $status       = $_POST['status'] ?? 'all';
        $idKelas        = (int)($_POST['kelas_id'] ?? 0);
        $idMapel        = (int)($_POST['mapel_id'] ?? 0);
        $idAsesmen      = (int)($_POST['asesmen_id'] ?? 0);
        $pertemuanKe    = (int)($_POST['pertemuan_ke'] ?? 0);
        $jenisSumatif   = trim($_POST['jenis_sumatif'] ?? '');
        $tahunPelajaran = trim($_POST['tahun_pelajaran'] ?? '');
        $semester       = trim($_POST['semester'] ?? '');
        $search         = trim($_POST['search'] ?? '');
        $page           = max(1, (int)($_POST['page'] ?? 1));
        $perPage        = max(5, min(100, (int)($_POST['per_page'] ?? 20)));
        $offset         = ($page - 1) * $perPage;
        $clientHash     = trim($_POST['last_hash'] ?? '');

        // AUTO-SYNC STATUS: Jika di tabel cbt_sesi_ujian sudah 'Selesai', otomatis perbarui tabel akses
        ensureRemedialDatabaseTriggers($pdo);
        try {
            $pdo->exec("
                UPDATE akses_asesmen_sumatif aas
                JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'sumatif' AND csu.id_asesmen = aas.id_asesmen_sumatif AND csu.id_siswa = aas.id_siswa)
                SET aas.status_akses = 'Selesai',
                    aas.sisa_waktu_detik = 0,
                    aas.waktu_selesai = COALESCE(aas.waktu_selesai, csu.waktu_selesai, NOW()),
                    aas.updated_at = NOW()
                WHERE csu.status_sesi = 'Selesai' AND aas.status_akses != 'Selesai'
            ");
            $pdo->exec("
                UPDATE akses_asesmen_formatif aaf
                JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'formatif' AND csu.id_asesmen = aaf.id_asesmen_formatif AND csu.id_siswa = aaf.id_siswa)
                SET aaf.status_akses = 'Selesai',
                    aaf.sisa_waktu_detik = 0,
                    aaf.waktu_selesai = COALESCE(aaf.waktu_selesai, csu.waktu_selesai, NOW()),
                    aaf.updated_at = NOW()
                WHERE csu.status_sesi = 'Selesai' AND aaf.status_akses != 'Selesai'
            ");
            $pdo->exec("
                UPDATE akses_asesmen_diagnostik aad
                JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'diagnostik' AND csu.id_asesmen = aad.id_asesmen_diagnostik AND csu.id_siswa = aad.id_siswa)
                SET aad.status_akses = 'Selesai',
                    aad.sisa_waktu_detik = 0,
                    aad.waktu_selesai = COALESCE(aad.waktu_selesai, csu.waktu_selesai, NOW()),
                    aad.updated_at = NOW()
                WHERE csu.status_sesi = 'Selesai' AND aad.status_akses != 'Selesai'
            ");
            $pdo->exec("
                UPDATE akses_pengayaan_remedial apr
                JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'remedial' AND csu.id_asesmen = apr.id_pengayaan_remedial AND csu.id_siswa = apr.id_siswa)
                SET apr.status_akses = 'Selesai',
                    apr.sisa_waktu_detik = 0,
                    apr.waktu_selesai = COALESCE(apr.waktu_selesai, csu.waktu_selesai, NOW()),
                    apr.updated_at = NOW()
                WHERE csu.status_sesi = 'Selesai' AND apr.status_akses != 'Selesai'
            ");
        } catch (Exception $e) {
            // Abaikan error background sync
        }

        // WHERE BUILDERS
        $whereSumatif  = " WHERE 1=1 ";
        $paramsSumatif = [];
        if ($status !== 'all' && $status !== '') { $whereSumatif .= " AND (aas.status_akses = ? OR csu.status_sesi = ?) "; $paramsSumatif[] = $status; $paramsSumatif[] = $status; }
        if ($idKelas > 0) { $whereSumatif .= " AND s.id_kelas = ? "; $paramsSumatif[] = $idKelas; }
        if ($idMapel > 0) { $whereSumatif .= " AND kmp.id_mata_pelajaran = ? "; $paramsSumatif[] = $idMapel; }
        if ($idAsesmen > 0) { $whereSumatif .= " AND aas.id_asesmen_sumatif = ? "; $paramsSumatif[] = $idAsesmen; }
        if ($pertemuanKe > 0) { $whereSumatif .= " AND asum.pertemuan_ke = ? "; $paramsSumatif[] = $pertemuanKe; }
        if ($jenisSumatif !== '' && $jenisSumatif !== 'all') { $whereSumatif .= " AND asum.jenis_sumatif = ? "; $paramsSumatif[] = $jenisSumatif; }
        if ($tahunPelajaran !== '' && $tahunPelajaran !== 'all') { $whereSumatif .= " AND COALESCE(aas.tahun_pelajaran, asum.tahun_pelajaran, k.tahun_pelajaran) = ? "; $paramsSumatif[] = $tahunPelajaran; }
        if ($semester !== '' && $semester !== 'all') { $whereSumatif .= " AND COALESCE(aas.semester, asum.semester) = ? "; $paramsSumatif[] = $semester; }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $whereSumatif .= " AND (s.nama_lengkap LIKE ? OR aas.nomor_peserta LIKE ? OR aas.username_ujian LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ? OR asum.judul_asesmen LIKE ? OR k.nama_kelas LIKE ? OR mp.nama_mata_pelajaran LIKE ?) ";
            $paramsSumatif = array_merge($paramsSumatif, [$like, $like, $like, $like, $like, $like, $like, $like]);
        }

        $whereFormatif  = " WHERE 1=1 ";
        $paramsFormatif = [];
        if ($status !== 'all' && $status !== '') { $whereFormatif .= " AND (aaf.status_akses = ? OR csu.status_sesi = ?) "; $paramsFormatif[] = $status; $paramsFormatif[] = $status; }
        if ($idKelas > 0) { $whereFormatif .= " AND s.id_kelas = ? "; $paramsFormatif[] = $idKelas; }
        if ($idMapel > 0) { $whereFormatif .= " AND kmp.id_mata_pelajaran = ? "; $paramsFormatif[] = $idMapel; }
        if ($idAsesmen > 0) { $whereFormatif .= " AND aaf.id_asesmen_formatif = ? "; $paramsFormatif[] = $idAsesmen; }
        if ($pertemuanKe > 0) { $whereFormatif .= " AND af.pertemuan_ke = ? "; $paramsFormatif[] = $pertemuanKe; }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $whereFormatif .= " AND (s.nama_lengkap LIKE ? OR aaf.nomor_peserta LIKE ? OR aaf.username_ujian LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ? OR af.judul_asesmen LIKE ? OR k.nama_kelas LIKE ? OR mp.nama_mata_pelajaran LIKE ?) ";
            $paramsFormatif = array_merge($paramsFormatif, [$like, $like, $like, $like, $like, $like, $like, $like]);
        }

        $whereDiagnostik  = " WHERE 1=1 ";
        $paramsDiagnostik = [];
        if ($status !== 'all' && $status !== '') { $whereDiagnostik .= " AND (aad.status_akses = ? OR csu.status_sesi = ?) "; $paramsDiagnostik[] = $status; $paramsDiagnostik[] = $status; }
        if ($idKelas > 0) { $whereDiagnostik .= " AND s.id_kelas = ? "; $paramsDiagnostik[] = $idKelas; }
        if ($idMapel > 0) { $whereDiagnostik .= " AND kmp.id_mata_pelajaran = ? "; $paramsDiagnostik[] = $idMapel; }
        if ($idAsesmen > 0) { $whereDiagnostik .= " AND aad.id_asesmen_diagnostik = ? "; $paramsDiagnostik[] = $idAsesmen; }
        if ($pertemuanKe > 0) { $whereDiagnostik .= " AND ad.pertemuan_ke = ? "; $paramsDiagnostik[] = $pertemuanKe; }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $whereDiagnostik .= " AND (s.nama_lengkap LIKE ? OR aad.nomor_peserta LIKE ? OR aad.username_ujian LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ? OR ad.judul_asesmen LIKE ? OR k.nama_kelas LIKE ? OR mp.nama_mata_pelajaran LIKE ?) ";
            $paramsDiagnostik = array_merge($paramsDiagnostik, [$like, $like, $like, $like, $like, $like, $like, $like]);
        }

        $whereRemedial  = " WHERE 1=1 ";
        $paramsRemedial = [];
        if ($status !== 'all' && $status !== '') { $whereRemedial .= " AND (apr.status_akses = ? OR csu.status_sesi = ?) "; $paramsRemedial[] = $status; $paramsRemedial[] = $status; }
        if ($idKelas > 0) { $whereRemedial .= " AND s.id_kelas = ? "; $paramsRemedial[] = $idKelas; }
        if ($idMapel > 0) { $whereRemedial .= " AND kmp.id_mata_pelajaran = ? "; $paramsRemedial[] = $idMapel; }
        if ($idAsesmen > 0) { $whereRemedial .= " AND apr.id_pengayaan_remedial = ? "; $paramsRemedial[] = $idAsesmen; }
        if ($pertemuanKe > 0) { $whereRemedial .= " AND pr.pertemuan_ke = ? "; $paramsRemedial[] = $pertemuanKe; }
        if ($search !== '') {
            $like = '%' . $search . '%';
            $whereRemedial .= " AND (s.nama_lengkap LIKE ? OR apr.nomor_peserta LIKE ? OR apr.username_ujian LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ? OR pr.judul_program LIKE ? OR k.nama_kelas LIKE ? OR mp.nama_mata_pelajaran LIKE ?) ";
            $paramsRemedial = array_merge($paramsRemedial, [$like, $like, $like, $like, $like, $like, $like, $like]);
        }

        $remedialCountFrom = "FROM akses_pengayaan_remedial apr JOIN siswa s ON apr.id_siswa = s.id JOIN kelas k ON s.id_kelas = k.id JOIN pengayaan_remedial pr ON apr.id_pengayaan_remedial = pr.id JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id AND s.id_kelas = kmp.id_kelas LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id LEFT JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'remedial' AND csu.id_asesmen = apr.id_pengayaan_remedial AND csu.id_siswa = apr.id_siswa)";

        if ($tipe === 'sumatif') {
            if ($tahunPelajaran === '' || $tahunPelajaran === 'all' || $semester === '' || $semester === 'all' || $jenisSumatif === '' || $jenisSumatif === 'all') {
                echo json_encode([
                    'success' => true,
                    'hash'    => '',
                    'data'    => [
                        'rows'                => [],
                        'total'               => 0,
                        'page'                => 1,
                        'per_page'            => $perPage,
                        'pages'               => 1,
                        'need_sumatif_filter' => true,
                        'stats'               => [
                            'active_now'       => 0,
                            'locked'           => 0,
                            'completed'        => 0,
                            'total_diagnostik' => 0,
                            'total_formatif'   => 0,
                            'total_sumatif'    => 0,
                            'total_remedial'   => 0
                        ]
                    ]
                ]);
                exit;
            }
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM akses_asesmen_sumatif aas JOIN siswa s ON aas.id_siswa = s.id JOIN kelas k ON s.id_kelas = k.id JOIN asesmen_sumatif asum ON aas.id_asesmen_sumatif = asum.id JOIN kelas_mata_pelajaran kmp ON aas.id_kelas_mata_pelajaran = kmp.id LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id LEFT JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'sumatif' AND csu.id_asesmen = aas.id_asesmen_sumatif AND csu.id_siswa = aas.id_siswa) $whereSumatif");
            $cStmt->execute($paramsSumatif);
            $total = (int)$cStmt->fetchColumn();
        } elseif ($tipe === 'formatif') {
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM akses_asesmen_formatif aaf JOIN siswa s ON aaf.id_siswa = s.id JOIN kelas k ON s.id_kelas = k.id JOIN asesmen_formatif af ON aaf.id_asesmen_formatif = af.id JOIN kelas_mata_pelajaran kmp ON aaf.id_kelas_mata_pelajaran = kmp.id LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id LEFT JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'formatif' AND csu.id_asesmen = aaf.id_asesmen_formatif AND csu.id_siswa = aaf.id_siswa) $whereFormatif");
            $cStmt->execute($paramsFormatif);
            $total = (int)$cStmt->fetchColumn();
        } elseif ($tipe === 'remedial') {
            $cStmt = $pdo->prepare("SELECT COUNT(*) $remedialCountFrom $whereRemedial");
            $cStmt->execute($paramsRemedial);
            $total = (int)$cStmt->fetchColumn();
        } elseif ($tipe === 'diagnostik') {
            $cStmt = $pdo->prepare("SELECT COUNT(*) FROM akses_asesmen_diagnostik aad JOIN siswa s ON aad.id_siswa = s.id JOIN kelas k ON s.id_kelas = k.id JOIN asesmen_diagnostik ad ON aad.id_asesmen_diagnostik = ad.id JOIN kelas_mata_pelajaran kmp ON aad.id_kelas_mata_pelajaran = kmp.id LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id LEFT JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'diagnostik' AND csu.id_asesmen = aad.id_asesmen_diagnostik AND csu.id_siswa = aad.id_siswa) $whereDiagnostik");
            $cStmt->execute($paramsDiagnostik);
            $total = (int)$cStmt->fetchColumn();
        } else {
            $c1 = $pdo->prepare("SELECT COUNT(*) FROM akses_asesmen_sumatif aas JOIN siswa s ON aas.id_siswa = s.id JOIN kelas k ON s.id_kelas = k.id JOIN asesmen_sumatif asum ON aas.id_asesmen_sumatif = asum.id JOIN kelas_mata_pelajaran kmp ON aas.id_kelas_mata_pelajaran = kmp.id LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id LEFT JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'sumatif' AND csu.id_asesmen = aas.id_asesmen_sumatif AND csu.id_siswa = aas.id_siswa) $whereSumatif");
            $c1->execute($paramsSumatif);
            $totSumatif = (int)$c1->fetchColumn();
            $c2 = $pdo->prepare("SELECT COUNT(*) FROM akses_asesmen_formatif aaf JOIN siswa s ON aaf.id_siswa = s.id JOIN kelas k ON s.id_kelas = k.id JOIN asesmen_formatif af ON aaf.id_asesmen_formatif = af.id JOIN kelas_mata_pelajaran kmp ON aaf.id_kelas_mata_pelajaran = kmp.id LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id LEFT JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'formatif' AND csu.id_asesmen = aaf.id_asesmen_formatif AND csu.id_siswa = aaf.id_siswa) $whereFormatif");
            $c2->execute($paramsFormatif);
            $totFormatif = (int)$c2->fetchColumn();
            $c3 = $pdo->prepare("SELECT COUNT(*) FROM akses_asesmen_diagnostik aad JOIN siswa s ON aad.id_siswa = s.id JOIN kelas k ON s.id_kelas = k.id JOIN asesmen_diagnostik ad ON aad.id_asesmen_diagnostik = ad.id JOIN kelas_mata_pelajaran kmp ON aad.id_kelas_mata_pelajaran = kmp.id LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id LEFT JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'diagnostik' AND csu.id_asesmen = aad.id_asesmen_diagnostik AND csu.id_siswa = aad.id_siswa) $whereDiagnostik");
            $c3->execute($paramsDiagnostik);
            $totDiagnostik = (int)$c3->fetchColumn();
            $c4 = $pdo->prepare("SELECT COUNT(*) $remedialCountFrom $whereRemedial");
            $c4->execute($paramsRemedial);
            $totRemedial = (int)$c4->fetchColumn();
            $total = $totSumatif + $totFormatif + $totDiagnostik + $totRemedial;
        }

        $sumatifSelect = "
            SELECT 
                'sumatif' AS tipe_kategori,
                aas.id, aas.id_asesmen_sumatif AS id_asesmen,
                aas.nomor_peserta, aas.username_ujian, aas.password_ujian, aas.token_sesi,
                CASE
                    WHEN COALESCE(csu.status_sesi, '') = 'Selesai' OR aas.status_akses = 'Selesai' THEN 'Selesai'
                    WHEN COALESCE(csu.is_frozen, 0) = 1 OR COALESCE(csu.is_locked_admin, 0) = 1 OR COALESCE(csu.status_sesi, '') = 'Terkunci / Freeze' THEN 'Terkunci'
                    WHEN COALESCE(csu.status_sesi, '') = 'Sedang Mengerjakan' THEN 'Sedang Mengerjakan'
                    ELSE aas.status_akses
                END AS status_akses,
                COALESCE(csu.status_sesi, aas.status_akses) AS status_sesi,
                aas.waktu_mulai,
                COALESCE(aas.waktu_selesai, csu.waktu_selesai) AS waktu_selesai,
                CASE
                    WHEN COALESCE(csu.status_sesi, '') = 'Selesai' OR aas.status_akses = 'Selesai' THEN 0
                    WHEN (aas.status_akses IN ('Sedang Mengerjakan', 'Terkunci') OR COALESCE(csu.status_sesi, '') IN ('Sedang Mengerjakan', 'Terkunci / Freeze') OR COALESCE(csu.is_frozen, 0) = 1 OR COALESCE(csu.is_locked_admin, 0) = 1) 
                         AND COALESCE(csu.sisa_waktu_detik, aas.sisa_waktu_detik) > 0 
                         AND COALESCE(csu.updated_at, csu.waktu_freeze, aas.updated_at, csu.waktu_mulai, aas.waktu_mulai) IS NOT NULL THEN
                        GREATEST(0, CAST(COALESCE(csu.sisa_waktu_detik, aas.sisa_waktu_detik) AS SIGNED) - TIMESTAMPDIFF(SECOND, COALESCE(csu.updated_at, csu.waktu_freeze, aas.updated_at, csu.waktu_mulai, aas.waktu_mulai), NOW()))
                    ELSE
                        COALESCE(csu.sisa_waktu_detik, aas.sisa_waktu_detik)
                END AS sisa_waktu_detik,
                aas.ip_address,
                s.id AS id_siswa, s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.gmail, s.foto, s.id_kelas,
                k.nama_kelas,
                COALESCE(aas.tahun_pelajaran, asum.tahun_pelajaran, k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
                COALESCE(aas.semester, asum.semester, mp.semester, 'Ganjil') AS semester,
                COALESCE(aas.jenis_sumatif, asum.jenis_sumatif, 'Sumatif Lingkup Materi') AS jenis_sumatif,
                asum.judul_asesmen, asum.jenis_sumatif AS info_tipe, asum.durasi_menit, asum.pertemuan_ke,
                COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran,
                kmp.id_mata_pelajaran,
                asum.tanggal_pelaksanaan, asum.jam_mulai, asum.jam_selesai,
                csu.id AS id_cbt_sesi,
                COALESCE(csu.is_frozen, 0) AS is_frozen,
                COALESCE(csu.is_locked_admin, 0) AS is_locked_admin,
                COALESCE(csu.total_pelanggaran, 0) AS total_pelanggaran,
                csu.alasan_freeze,
                csu.waktu_freeze,
                csu.device_model
            FROM akses_asesmen_sumatif aas
            JOIN siswa s ON aas.id_siswa = s.id
            JOIN kelas k ON s.id_kelas = k.id
            JOIN asesmen_sumatif asum ON aas.id_asesmen_sumatif = asum.id
            JOIN kelas_mata_pelajaran kmp ON aas.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'sumatif' AND csu.id_asesmen = aas.id_asesmen_sumatif AND csu.id_siswa = aas.id_siswa)
            $whereSumatif
        ";

        $formatifSelect = "
            SELECT 
                'formatif' AS tipe_kategori,
                aaf.id, aaf.id_asesmen_formatif AS id_asesmen,
                aaf.nomor_peserta, aaf.username_ujian, aaf.password_ujian, aaf.token_sesi,
                CASE
                    WHEN COALESCE(csu.status_sesi, '') = 'Selesai' OR aaf.status_akses = 'Selesai' THEN 'Selesai'
                    WHEN COALESCE(csu.is_frozen, 0) = 1 OR COALESCE(csu.is_locked_admin, 0) = 1 OR COALESCE(csu.status_sesi, '') = 'Terkunci / Freeze' THEN 'Terkunci'
                    WHEN COALESCE(csu.status_sesi, '') = 'Sedang Mengerjakan' THEN 'Sedang Mengerjakan'
                    ELSE aaf.status_akses
                END AS status_akses,
                COALESCE(csu.status_sesi, aaf.status_akses) AS status_sesi,
                aaf.waktu_mulai,
                COALESCE(aaf.waktu_selesai, csu.waktu_selesai) AS waktu_selesai,
                CASE
                    WHEN COALESCE(csu.status_sesi, '') = 'Selesai' OR aaf.status_akses = 'Selesai' THEN 0
                    WHEN (aaf.status_akses IN ('Sedang Mengerjakan', 'Terkunci') OR COALESCE(csu.status_sesi, '') IN ('Sedang Mengerjakan', 'Terkunci / Freeze') OR COALESCE(csu.is_frozen, 0) = 1 OR COALESCE(csu.is_locked_admin, 0) = 1) 
                         AND COALESCE(csu.sisa_waktu_detik, aaf.sisa_waktu_detik) > 0 
                         AND COALESCE(csu.updated_at, csu.waktu_freeze, aaf.updated_at, csu.waktu_mulai, aaf.waktu_mulai) IS NOT NULL THEN
                        GREATEST(0, CAST(COALESCE(csu.sisa_waktu_detik, aaf.sisa_waktu_detik) AS SIGNED) - TIMESTAMPDIFF(SECOND, COALESCE(csu.updated_at, csu.waktu_freeze, aaf.updated_at, csu.waktu_mulai, aaf.waktu_mulai), NOW()))
                    ELSE
                        COALESCE(csu.sisa_waktu_detik, aaf.sisa_waktu_detik)
                END AS sisa_waktu_detik,
                aaf.ip_address,
                s.id AS id_siswa, s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.gmail, s.foto, s.id_kelas,
                k.nama_kelas, k.tahun_pelajaran,
                COALESCE(mp.semester, 'Ganjil') AS semester,
                'Formatif' AS jenis_sumatif,
                af.judul_asesmen, af.teknik_asesmen AS info_tipe, af.durasi_menit, af.pertemuan_ke,
                COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran,
                kmp.id_mata_pelajaran,
                af.tanggal_pelaksanaan, af.jam_mulai, af.jam_selesai,
                csu.id AS id_cbt_sesi,
                COALESCE(csu.is_frozen, 0) AS is_frozen,
                COALESCE(csu.is_locked_admin, 0) AS is_locked_admin,
                COALESCE(csu.total_pelanggaran, 0) AS total_pelanggaran,
                csu.alasan_freeze,
                csu.waktu_freeze,
                csu.device_model
            FROM akses_asesmen_formatif aaf
            JOIN siswa s ON aaf.id_siswa = s.id
            JOIN kelas k ON s.id_kelas = k.id
            JOIN asesmen_formatif af ON aaf.id_asesmen_formatif = af.id
            JOIN kelas_mata_pelajaran kmp ON aaf.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'formatif' AND csu.id_asesmen = aaf.id_asesmen_formatif AND csu.id_siswa = aaf.id_siswa)
            $whereFormatif
        ";

        $diagnostikSelect = "
            SELECT 
                'diagnostik' AS tipe_kategori,
                aad.id, aad.id_asesmen_diagnostik AS id_asesmen,
                aad.nomor_peserta, aad.username_ujian, aad.password_ujian, aad.token_sesi,
                CASE
                    WHEN COALESCE(csu.status_sesi, '') = 'Selesai' OR aad.status_akses = 'Selesai' THEN 'Selesai'
                    WHEN COALESCE(csu.is_frozen, 0) = 1 OR COALESCE(csu.is_locked_admin, 0) = 1 OR COALESCE(csu.status_sesi, '') = 'Terkunci / Freeze' THEN 'Terkunci'
                    WHEN COALESCE(csu.status_sesi, '') = 'Sedang Mengerjakan' THEN 'Sedang Mengerjakan'
                    ELSE aad.status_akses
                END AS status_akses,
                COALESCE(csu.status_sesi, aad.status_akses) AS status_sesi,
                aad.waktu_mulai,
                COALESCE(aad.waktu_selesai, csu.waktu_selesai) AS waktu_selesai,
                CASE
                    WHEN COALESCE(csu.status_sesi, '') = 'Selesai' OR aad.status_akses = 'Selesai' THEN 0
                    WHEN (aad.status_akses IN ('Sedang Mengerjakan', 'Terkunci') OR COALESCE(csu.status_sesi, '') IN ('Sedang Mengerjakan', 'Terkunci / Freeze') OR COALESCE(csu.is_frozen, 0) = 1 OR COALESCE(csu.is_locked_admin, 0) = 1) 
                         AND COALESCE(csu.sisa_waktu_detik, aad.sisa_waktu_detik) > 0 
                         AND COALESCE(csu.updated_at, csu.waktu_freeze, aad.updated_at, csu.waktu_mulai, aad.waktu_mulai) IS NOT NULL THEN
                        GREATEST(0, CAST(COALESCE(csu.sisa_waktu_detik, aad.sisa_waktu_detik) AS SIGNED) - TIMESTAMPDIFF(SECOND, COALESCE(csu.updated_at, csu.waktu_freeze, aad.updated_at, csu.waktu_mulai, aad.waktu_mulai), NOW()))
                    ELSE
                        COALESCE(csu.sisa_waktu_detik, aad.sisa_waktu_detik)
                END AS sisa_waktu_detik,
                aad.ip_address,
                s.id AS id_siswa, s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.gmail, s.foto, s.id_kelas,
                k.nama_kelas, k.tahun_pelajaran,
                COALESCE(mp.semester, 'Ganjil') AS semester,
                'Diagnostik' AS jenis_sumatif,
                ad.judul_asesmen, ad.jenis_diagnostik AS info_tipe, ad.durasi_menit, ad.pertemuan_ke,
                COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran,
                kmp.id_mata_pelajaran,
                ad.tanggal_pelaksanaan, ad.jam_mulai, ad.jam_selesai,
                csu.id AS id_cbt_sesi,
                COALESCE(csu.is_frozen, 0) AS is_frozen,
                COALESCE(csu.is_locked_admin, 0) AS is_locked_admin,
                COALESCE(csu.total_pelanggaran, 0) AS total_pelanggaran,
                csu.alasan_freeze,
                csu.waktu_freeze,
                csu.device_model
            FROM akses_asesmen_diagnostik aad
            JOIN siswa s ON aad.id_siswa = s.id
            JOIN kelas k ON s.id_kelas = k.id
            JOIN asesmen_diagnostik ad ON aad.id_asesmen_diagnostik = ad.id
            JOIN kelas_mata_pelajaran kmp ON aad.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN cbt_sesi_ujian csu ON (csu.tipe_asesmen = 'diagnostik' AND csu.id_asesmen = aad.id_asesmen_diagnostik AND csu.id_siswa = aad.id_siswa)
            $whereDiagnostik
        ";

        $remedialSelect = "
            SELECT 
                'remedial' AS tipe_kategori,
                apr.id, apr.id_pengayaan_remedial AS id_asesmen,
                apr.nomor_peserta, apr.username_ujian, apr.password_ujian, apr.token_sesi,
                CASE
                    WHEN COALESCE(csu.status_sesi, '') = 'Selesai' OR apr.status_akses = 'Selesai' THEN 'Selesai'
                    WHEN COALESCE(csu.is_frozen, 0) = 1 OR COALESCE(csu.is_locked_admin, 0) = 1 OR COALESCE(csu.status_sesi, '') = 'Terkunci / Freeze' THEN 'Terkunci'
                    WHEN COALESCE(csu.status_sesi, '') = 'Sedang Mengerjakan' THEN 'Sedang Mengerjakan'
                    ELSE apr.status_akses
                END AS status_akses,
                COALESCE(csu.status_sesi, apr.status_akses) AS status_sesi,
                apr.waktu_mulai,
                COALESCE(apr.waktu_selesai, csu.waktu_selesai) AS waktu_selesai,
                CASE
                    WHEN COALESCE(csu.status_sesi, '') = 'Selesai' OR apr.status_akses = 'Selesai' THEN 0
                    WHEN (apr.status_akses IN ('Sedang Mengerjakan', 'Terkunci') OR COALESCE(csu.status_sesi, '') IN ('Sedang Mengerjakan', 'Terkunci / Freeze') OR COALESCE(csu.is_frozen, 0) = 1 OR COALESCE(csu.is_locked_admin, 0) = 1) 
                         AND COALESCE(csu.sisa_waktu_detik, apr.sisa_waktu_detik) > 0 
                         AND COALESCE(csu.updated_at, csu.waktu_freeze, apr.updated_at, csu.waktu_mulai, apr.waktu_mulai) IS NOT NULL THEN
                        GREATEST(0, CAST(COALESCE(csu.sisa_waktu_detik, apr.sisa_waktu_detik) AS SIGNED) - TIMESTAMPDIFF(SECOND, COALESCE(csu.updated_at, csu.waktu_freeze, apr.updated_at, csu.waktu_mulai, apr.waktu_mulai), NOW()))
                    ELSE
                        COALESCE(csu.sisa_waktu_detik, apr.sisa_waktu_detik)
                END AS sisa_waktu_detik,
                apr.ip_address,
                s.id AS id_siswa, s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.gmail, s.foto, s.id_kelas,
                k.nama_kelas, k.tahun_pelajaran,
                COALESCE(mp.semester, 'Ganjil') AS semester,
                pr.kategori AS jenis_sumatif,
                pr.judul_program AS judul_asesmen, pr.bentuk_kegiatan AS info_tipe, pr.durasi_menit, pr.pertemuan_ke,
                COALESCE(mp.nama_mata_pelajaran, 'Umum') AS nama_mata_pelajaran,
                kmp.id_mata_pelajaran,
                pr.tanggal_pelaksanaan, pr.jam_mulai, pr.jam_selesai,
                csu.id AS id_cbt_sesi,
                COALESCE(csu.is_frozen, 0) AS is_frozen,
                COALESCE(csu.is_locked_admin, 0) AS is_locked_admin,
                COALESCE(csu.total_pelanggaran, 0) AS total_pelanggaran,
                csu.alasan_freeze,
                csu.waktu_freeze,
                csu.device_model
            $remedialCountFrom
            $whereRemedial
        ";

        if ($tipe === 'sumatif') {
            $sql = "$sumatifSelect ORDER BY s.nama_lengkap ASC LIMIT $perPage OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($paramsSumatif);
        } elseif ($tipe === 'formatif') {
            $sql = "$formatifSelect ORDER BY s.nama_lengkap ASC LIMIT $perPage OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($paramsFormatif);
        } elseif ($tipe === 'remedial') {
            $sql = "$remedialSelect ORDER BY s.nama_lengkap ASC LIMIT $perPage OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($paramsRemedial);
        } elseif ($tipe === 'diagnostik') {
            $sql = "$diagnostikSelect ORDER BY s.nama_lengkap ASC LIMIT $perPage OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($paramsDiagnostik);
        } else {
            $sql = "SELECT * FROM (($sumatifSelect) UNION ALL ($formatifSelect) UNION ALL ($diagnostikSelect) UNION ALL ($remedialSelect)) t ORDER BY t.nama_siswa ASC LIMIT $perPage OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($paramsSumatif, $paramsFormatif, $paramsDiagnostik, $paramsRemedial));
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Jika ada sesi yang sedang berjalan atau Terkunci (Freeze) namun sisa waktu berjalannya sudah habis (<= 0),
        // otomatis ubah status menjadi Selesai agar tidak tertahan di status Freeze selamanya
        foreach ($rows as &$rItem) {
            $stAkses = $rItem['status_akses'] ?? '';
            $sisaSec = isset($rItem['sisa_waktu_detik']) ? (int)$rItem['sisa_waktu_detik'] : null;
            if (in_array($stAkses, ['Sedang Mengerjakan', 'Terkunci'], true) && $sisaSec !== null && $sisaSec <= 0 && !empty($rItem['waktu_mulai'])) {
                $tKat = $rItem['tipe_kategori'] ?? 'sumatif';
                $tbAk = aksesTableName($tKat);
                $fkAk = aksesFkName($tKat);
                $idAs = (int)($rItem['id_asesmen'] ?? 0);
                $idSw = (int)($rItem['id_siswa'] ?? 0);
                if ($idAs > 0 && $idSw > 0) {
                    $pdo->prepare("UPDATE `cbt_sesi_ujian` SET `status_sesi` = 'Selesai', `is_frozen` = 0, `is_locked_admin` = 0, `sisa_waktu_detik` = 0, `waktu_selesai` = COALESCE(`waktu_selesai`, NOW()), `updated_at` = NOW() WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tKat, $idAs, $idSw]);
                    $pdo->prepare("UPDATE `$tbAk` SET `status_akses` = 'Selesai', `sisa_waktu_detik` = 0, `waktu_selesai` = COALESCE(`waktu_selesai`, NOW()), `updated_at` = NOW() WHERE `$fkAk` = ? AND `id_siswa` = ?")->execute([$idAs, $idSw]);
                    $rItem['status_akses'] = 'Selesai';
                    $rItem['status_sesi'] = 'Selesai';
                    $rItem['is_frozen'] = 0;
                    $rItem['is_locked_admin'] = 0;
                    $rItem['sisa_waktu_detik'] = 0;
                }
            }
        }
        unset($rItem);

        // STATS
        $statsSumatif = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(status_akses = 'Sedang Mengerjakan'), 0) AS active_now, COALESCE(SUM(status_akses = 'Selesai'), 0) AS completed, COALESCE(SUM(status_akses = 'Terkunci'), 0) AS locked FROM akses_asesmen_sumatif")->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'active_now'=>0,'completed'=>0,'locked'=>0];
        $statsFormatif = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(status_akses = 'Sedang Mengerjakan'), 0) AS active_now, COALESCE(SUM(status_akses = 'Selesai'), 0) AS completed, COALESCE(SUM(status_akses = 'Terkunci'), 0) AS locked FROM akses_asesmen_formatif")->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'active_now'=>0,'completed'=>0,'locked'=>0];
        $statsDiagnostik = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(status_akses = 'Sedang Mengerjakan'), 0) AS active_now, COALESCE(SUM(status_akses = 'Selesai'), 0) AS completed, COALESCE(SUM(status_akses = 'Terkunci'), 0) AS locked FROM akses_asesmen_diagnostik")->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'active_now'=>0,'completed'=>0,'locked'=>0];
        $statsRemedial = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(apr.status_akses = 'Sedang Mengerjakan'), 0) AS active_now, COALESCE(SUM(apr.status_akses = 'Selesai'), 0) AS completed, COALESCE(SUM(apr.status_akses = 'Terkunci'), 0) AS locked FROM akses_pengayaan_remedial apr JOIN siswa s ON apr.id_siswa = s.id JOIN pengayaan_remedial pr ON apr.id_pengayaan_remedial = pr.id JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id AND s.id_kelas = kmp.id_kelas")->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'active_now'=>0,'completed'=>0,'locked'=>0];

        $statTotalSumatif    = (int)($statsSumatif['total'] ?? 0);
        $statTotalFormatif   = (int)($statsFormatif['total'] ?? 0);
        $statTotalDiagnostik = (int)($statsDiagnostik['total'] ?? 0);
        $statActiveNow       = (int)($statsSumatif['active_now'] ?? 0) + (int)($statsFormatif['active_now'] ?? 0) + (int)($statsDiagnostik['active_now'] ?? 0) + (int)($statsRemedial['active_now'] ?? 0);
        $statCompleted       = (int)($statsSumatif['completed'] ?? 0) + (int)($statsFormatif['completed'] ?? 0) + (int)($statsDiagnostik['completed'] ?? 0) + (int)($statsRemedial['completed'] ?? 0);
        $statLocked          = (int)($statsSumatif['locked'] ?? 0) + (int)($statsFormatif['locked'] ?? 0) + (int)($statsDiagnostik['locked'] ?? 0) + (int)($statsRemedial['locked'] ?? 0);

        $statsPayload = [
            'total'            => $statTotalFormatif + $statTotalSumatif + $statTotalDiagnostik + (int)($statsRemedial['total'] ?? 0),
            'total_diagnostik' => $statTotalDiagnostik,
            'total_remedial'   => (int)($statsRemedial['total'] ?? 0),
            'total_formatif'   => $statTotalFormatif,
            'total_sumatif'    => $statTotalSumatif,
            'active_now'       => $statActiveNow,
            'locked'           => $statLocked,
            'completed'        => $statCompleted
        ];

        $hashData = [
            't' => $total,
            's' => $statsPayload,
            'r' => array_map(function($r) {
                $quantizedSec = (int)(((int)($r['sisa_waktu_detik'] ?? 0)) / 5);
                return $r['tipe_kategori'] . '_' . $r['id'] . ':' . $r['status_akses'] . ':' . $r['is_frozen'] . ':' . $r['total_pelanggaran'] . ':' . $quantizedSec . ':' . $r['token_sesi'];
            }, $rows)
        ];
        $currentHash = md5(json_encode($hashData));

        if ($clientHash && $clientHash === $currentHash) {
            echo json_encode([
                'success'   => true,
                'unchanged' => true,
                'hash'      => $currentHash,
                'data'      => ['stats' => $statsPayload]
            ]);
            exit;
        }

        echo json_encode([
            'success'   => true,
            'unchanged' => false,
            'hash'      => $currentHash,
            'data'      => [
                'rows'     => $rows,
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => ceil($total / $perPage) ?: 1,
                'stats'    => $statsPayload
            ]
        ]);
        exit;
    }

    // 1.2 GENERATE MASSAL
    if ($act === 'generate_massal') {
        $tipeAsesmen   = in_array($_POST['tipe_asesmen'] ?? '', ['diagnostik', 'formatif', 'sumatif', 'remedial'], true) ? $_POST['tipe_asesmen'] : 'diagnostik';
        $tokenSesi     = strtoupper(trim($_POST['token_sesi'] ?? ''));
        $passOption    = $_POST['pass_option'] ?? 'pin6';
        $prefixPass    = trim($_POST['prefix_pass'] ?? 'cbt');
        $userOption    = $_POST['user_option'] ?? 'nis';

        $rawIds = $_POST['ids_asesmen'] ?? $_POST['id_asesmen'] ?? '';
        if (is_array($rawIds)) {
            $asmIdList = array_values(array_filter(array_map('intval', $rawIds)));
        } else {
            $asmIdList = array_values(array_filter(array_map('intval', explode(',', (string)$rawIds))));
        }

        if (empty($asmIdList)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Silakan pilih minimal 1 Asesmen sasaran.']]);
            exit;
        }

        // REMEDIAL
        if ($tipeAsesmen === 'remedial') {
            try {
                $created = 0; $already = 0; $skippedTotal = 0; $summary = []; $usedTokens = [];
                foreach ($asmIdList as $idPr) {
                    $stmtPr = $pdo->prepare("SELECT pr.*, kmp.id_kelas, COALESCE(k.tahun_pelajaran, '2026/2027') AS tahun_final FROM pengayaan_remedial pr JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id LEFT JOIN kelas k ON kmp.id_kelas = k.id WHERE pr.id = ?");
                    $stmtPr->execute([$idPr]);
                    $pr = $stmtPr->fetch(PDO::FETCH_ASSOC);
                    if (!$pr) continue;

                    $stmtTok = $pdo->prepare("SELECT token_sesi FROM akses_pengayaan_remedial WHERE id_pengayaan_remedial = ? AND token_sesi IS NOT NULL AND token_sesi != '' LIMIT 1");
                    $stmtTok->execute([$idPr]);
                    $existingTok = $stmtTok->fetchColumn();
                    if (count($asmIdList) === 1 && !empty($tokenSesi) && $tokenSesi !== '[OTOMATIS DI-MAPPING SISTEM BERBEDA TIAP ASESMEN]') {
                        $thisToken = $tokenSesi;
                    } elseif (!empty($existingTok)) {
                        $thisToken = $existingTok;
                    } else {
                        do { $thisToken = generateCbtToken(); } while (in_array($thisToken, $usedTokens, true));
                    }
                    $usedTokens[] = $thisToken;

                    $eligible = getEligibleRemedialStudents($pdo, (int)$idPr);
                    $eligibleIds = array_map(function($item) { return (int)$item['id']; }, $eligible);

                    // Bersihkan tiket akses & sesi ujian remedial yang tidak memenuhi syarat (misal beda kelas atau nilai >= KKTP)
                    if (empty($eligibleIds)) {
                        $pdo->prepare("DELETE FROM cbt_jawaban_siswa WHERE tipe_asesmen = 'remedial' AND id_asesmen = ?")->execute([$idPr]);
                        $pdo->prepare("DELETE FROM cbt_sesi_ujian WHERE tipe_asesmen = 'remedial' AND id_asesmen = ? AND status_sesi != 'Selesai'")->execute([$idPr]);
                        $pdo->prepare("DELETE FROM akses_pengayaan_remedial WHERE id_pengayaan_remedial = ? AND status_akses != 'Selesai'")->execute([$idPr]);
                    } else {
                        $inPlaceholders = implode(',', array_fill(0, count($eligibleIds), '?'));
                        $delParams = array_merge([(int)$idPr], $eligibleIds);
                        $pdo->prepare("DELETE FROM cbt_jawaban_siswa WHERE tipe_asesmen = 'remedial' AND id_asesmen = ? AND id_siswa NOT IN ($inPlaceholders)")->execute($delParams);
                        $pdo->prepare("DELETE FROM cbt_sesi_ujian WHERE tipe_asesmen = 'remedial' AND id_asesmen = ? AND id_siswa NOT IN ($inPlaceholders)")->execute($delParams);
                        $pdo->prepare("DELETE FROM akses_pengayaan_remedial WHERE id_pengayaan_remedial = ? AND id_siswa NOT IN ($inPlaceholders)")->execute($delParams);
                    }

                    $stmtAll = $pdo->prepare("SELECT COUNT(*) FROM siswa WHERE id_kelas = ?");
                    $stmtAll->execute([$pr['id_kelas']]);
                    $totalKelas = (int)$stmtAll->fetchColumn();
                    $skippedTotal += max(0, $totalKelas - count($eligible));

                    $tahunClean = substr(explode('/', (string)$pr['tahun_final'])[0] ?? date('Y'), -4);
                    $prefixRmd = ($pr['kategori'] === 'Pengayaan') ? 'PGY' : 'RMD';

                    foreach ($eligible as $idx => $sw) {
                        $stmtEx = $pdo->prepare("SELECT id FROM akses_pengayaan_remedial WHERE id_pengayaan_remedial = ? AND id_siswa = ?");
                        $stmtEx->execute([$idPr, $sw['id']]);
                        if ($stmtEx->fetchColumn()) {
                            $pdo->prepare("UPDATE akses_pengayaan_remedial SET token_sesi = ? WHERE id_pengayaan_remedial = ? AND id_siswa = ?")->execute([$thisToken, $idPr, $sw['id']]);
                            $already++;
                            continue;
                        }
                        $nomorUrut = str_pad($idx + 1, 3, '0', STR_PAD_LEFT);
                        $nomorPeserta = "{$prefixRmd}{$idPr}-{$tahunClean}-K{$pr['id_kelas']}-{$nomorUrut}";

                        if ($userOption === 'nisn' && !empty($sw['nisn'])) $baseUser = trim($sw['nisn']);
                        elseif ($userOption === 'nis' && !empty($sw['nis'])) $baseUser = trim($sw['nis']);
                        else {
                            $cleanName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode(' ', $sw['nama_lengkap'])[0]));
                            $baseUser = $cleanName . (!empty($sw['nis']) ? substr($sw['nis'], -3) : (string)rand(100, 999));
                        }
                        $username = $baseUser . '_' . strtolower($prefixRmd) . $idPr;

                        if ($passOption === 'pin6') $password = (string)rand(100000, 999999);
                        elseif ($passOption === 'prefix_nisn') { $last4 = !empty($sw['nisn']) ? substr($sw['nisn'], -4) : substr($sw['nis'] ?? '1234', -4); $password = $prefixPass . $last4; }
                        else $password = !empty($sw['nis']) ? $sw['nis'] : (string)rand(100000, 999999);

                        $res = buatAksesRemedial($pdo, $idPr, $sw['id'], $nomorPeserta, $username, $password, $thisToken);
                        if (!empty($res['success'])) $created++;
                    }
                    $summary[] = ['id_asesmen' => $idPr, 'judul' => $pr['judul_program'], 'token' => $thisToken, 'total' => count($eligible)];
                }
                if (empty($summary)) { echo json_encode(['success' => false, 'data' => ['message' => 'Program remedial/pengayaan tidak ditemukan.']]); exit; }

                recordCbtEvent();
                $msg = "Akun remedial/pengayaan dibuat: <strong>$created</strong> baru" . ($already ? ", $already sudah ada (token diperbarui)" : "") . ". <strong>$skippedTotal</strong> siswa dilewati karena belum memenuhi syarat.";
                if ($created === 0 && $already === 0) { echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada siswa yang memenuhi syarat.']]); exit; }
                echo json_encode(['success' => true, 'data' => ['message' => $msg, 'total' => $created, 'count_asesmen' => count($summary), 'summary' => $summary, 'token_sesi' => (count($summary) === 1) ? $summary[0]['token'] : null]]);
                exit;
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'data' => ['message' => 'Gagal generate remedial: ' . $e->getMessage()]]);
                exit;
            }
        }

        try {
            if ($tipeAsesmen === 'sumatif') { $tabelMaster = 'asesmen_sumatif'; $prefixType = 'SMT'; }
            elseif ($tipeAsesmen === 'diagnostik') { $tabelMaster = 'asesmen_diagnostik'; $prefixType = 'DGN'; }
            else { $tabelMaster = 'asesmen_formatif'; $prefixType = 'FMT'; }

            $totalGenerated = 0; $processedSummary = []; $usedTokens = [];
            $emailSentTotal = 0; $emailFailTotal = 0; $emailSkippedTotal = 0;

            foreach ($asmIdList as $idAsesmen) {
                if ($tipeAsesmen === 'sumatif') {
                    $stmtAsmInfo = $pdo->prepare("SELECT asum.*, COALESCE(asum.semester, mp.semester, 'Ganjil') AS semester_final, COALESCE(asum.tahun_pelajaran, mp.tahun_pelajaran, k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran_final FROM asesmen_sumatif asum LEFT JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id LEFT JOIN kelas k ON kmp.id_kelas = k.id WHERE asum.id = ?");
                    $stmtAsmInfo->execute([$idAsesmen]);
                    $asmInfo = $stmtAsmInfo->fetch(PDO::FETCH_ASSOC);
                } else {
                    $asmInfo = $pdo->query("SELECT * FROM `$tabelMaster` WHERE id = $idAsesmen")->fetch(PDO::FETCH_ASSOC);
                }
                if (!$asmInfo) continue;

                $pertemuanKe = (int)($asmInfo['pertemuan_ke'] ?? 1);
                $jenisSumatifVal = (string)($asmInfo['jenis_sumatif'] ?? 'Sumatif Lingkup Materi');
                $semesterVal = (string)($asmInfo['semester_final'] ?? $asmInfo['semester'] ?? 'Ganjil');
                $tahunPelajaranVal = (string)($asmInfo['tahun_pelajaran_final'] ?? $asmInfo['tahun_pelajaran'] ?? '2026/2027');

                $currPrefix = $prefixType;
                if ($tipeAsesmen === 'sumatif') {
                    if ($jenisSumatifVal === 'Sumatif Tengah Semester') $currPrefix = ($semesterVal === 'Genap') ? 'STS2' : 'STS1';
                    elseif ($jenisSumatifVal === 'Sumatif Akhir Semester') $currPrefix = ($semesterVal === 'Genap') ? 'SAS2' : 'SAS1';
                    else $currPrefix = ($semesterVal === 'Genap') ? 'SLM2' : 'SLM1';
                }

                $classesToProcess = [];
                if ($tipeAsesmen === 'sumatif') {
                    $stmtMulti = $pdo->prepare("SELECT DISTINCT kmp.id_kelas, ask.id_kelas_mata_pelajaran FROM asesmen_sumatif_kelas ask JOIN kelas_mata_pelajaran kmp ON ask.id_kelas_mata_pelajaran = kmp.id WHERE ask.id_asesmen_sumatif = ?");
                    $stmtMulti->execute([$idAsesmen]);
                    $classesToProcess = $stmtMulti->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($classesToProcess) && !empty($asmInfo['id_kelas_mata_pelajaran'])) {
                        $kRow = $pdo->query("SELECT id_kelas FROM kelas_mata_pelajaran WHERE id = " . (int)$asmInfo['id_kelas_mata_pelajaran'])->fetch(PDO::FETCH_ASSOC);
                        if ($kRow && !empty($kRow['id_kelas'])) $classesToProcess[] = ['id_kelas' => (int)$kRow['id_kelas'], 'id_kelas_mata_pelajaran' => (int)$asmInfo['id_kelas_mata_pelajaran']];
                    }
                } else {
                    $kmpId = (int)($asmInfo['id_kelas_mata_pelajaran'] ?? 0);
                    if ($kmpId > 0) {
                        $kRow = $pdo->query("SELECT id_kelas FROM kelas_mata_pelajaran WHERE id = $kmpId")->fetch(PDO::FETCH_ASSOC);
                        if ($kRow && !empty($kRow['id_kelas'])) $classesToProcess[] = ['id_kelas' => (int)$kRow['id_kelas'], 'id_kelas_mata_pelajaran' => $kmpId];
                    }
                }
                if (empty($classesToProcess)) continue;

                if ($tipeAsesmen === 'sumatif') {
                    $stmtTok = $pdo->prepare("SELECT token_sesi FROM akses_asesmen_sumatif WHERE id_asesmen_sumatif = ? AND token_sesi IS NOT NULL AND token_sesi != '' LIMIT 1");
                    $stmtTok->execute([$idAsesmen]);
                    $existingSumatifToken = $stmtTok->fetchColumn();
                    if (count($asmIdList) === 1 && !empty($tokenSesi) && $tokenSesi !== '[OTOMATIS DI-MAPPING SISTEM BERBEDA TIAP ASESMEN]') $thisToken = $tokenSesi;
                    elseif (!empty($existingSumatifToken)) $thisToken = $existingSumatifToken;
                    else {
                        do { $thisToken = generateCbtToken(); } while (in_array($thisToken, $usedTokens, true));
                    }
                    $usedTokens[] = $thisToken;
                } else {
                    $thisToken = !empty($tokenSesi) ? $tokenSesi : generateCbtToken();
                }

                $subGenerated = 0;
                foreach ($classesToProcess as $cls) {
                    $currKelasId = (int)$cls['id_kelas'];
                    $currKmpId = (int)$cls['id_kelas_mata_pelajaran'];

                    $stmtKmpRow = $pdo->prepare("
                        SELECT k.nama_kelas, mp.nama_mata_pelajaran 
                        FROM kelas_mata_pelajaran kmp 
                        JOIN kelas k ON kmp.id_kelas = k.id 
                        LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id 
                        WHERE kmp.id = ?
                    ");
                    $stmtKmpRow->execute([$currKmpId]);
                    $kmpRow = $stmtKmpRow->fetch(PDO::FETCH_ASSOC);
                    $namaKelasRow = $kmpRow['nama_kelas'] ?? ('Kelas #' . $currKelasId);
                    $namaMapelRow = $kmpRow['nama_mata_pelajaran'] ?? 'Umum';

                    $stmtSiswa = $pdo->prepare("SELECT id, nis, nisn, nama_lengkap, gmail, foto FROM siswa WHERE id_kelas = ? ORDER BY nama_lengkap ASC");
                    $stmtSiswa->execute([$currKelasId]);
                    $listSiswa = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);
                    if (empty($listSiswa)) continue;

                    foreach ($listSiswa as $idx => $s) {
                        $nomorUrut = str_pad($idx + 1, 3, '0', STR_PAD_LEFT);
                        $tahunClean = substr(explode('/', $tahunPelajaranVal)[0] ?? date('Y'), -4);
                        $nomorPeserta = "{$currPrefix}-{$tahunClean}-K{$currKelasId}-{$nomorUrut}";

                        if ($userOption === 'nis' && !empty($s['nis'])) $username = trim($s['nis']);
                        elseif ($userOption === 'nisn' && !empty($s['nisn'])) $username = trim($s['nisn']);
                        else {
                            $cleanName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', explode(' ', $s['nama_lengkap'])[0]));
                            $suffix = !empty($s['nis']) ? substr($s['nis'], -3) : substr($s['nisn'] ?? (string)rand(100, 999), -3);
                            $username = strtolower($currPrefix) . '_' . $cleanName . $suffix;
                        }
                        if ($tipeAsesmen === 'sumatif') $username = $username . '_' . strtolower($currPrefix) . '_' . substr($tahunClean, -2);

                        if ($tipeAsesmen === 'sumatif') {
                            $stmtCheckPass = $pdo->prepare("SELECT aas.username_ujian, aas.password_ujian, aas.nomor_peserta FROM akses_asesmen_sumatif aas WHERE aas.id_siswa = ? AND aas.jenis_sumatif = ? AND aas.semester = ? AND aas.tahun_pelajaran = ? AND aas.password_ujian IS NOT NULL AND aas.password_ujian != '' ORDER BY aas.id DESC LIMIT 1");
                            $stmtCheckPass->execute([$s['id'], $jenisSumatifVal, $semesterVal, $tahunPelajaranVal]);
                            $rowExistingPass = $stmtCheckPass->fetch(PDO::FETCH_ASSOC);
                            if ($rowExistingPass && !empty($rowExistingPass['password_ujian'])) {
                                $password = $rowExistingPass['password_ujian'];
                                if (!empty($rowExistingPass['username_ujian'])) $username = $rowExistingPass['username_ujian'];
                                if (!empty($rowExistingPass['nomor_peserta'])) $nomorPeserta = $rowExistingPass['nomor_peserta'];
                            } else {
                                if ($passOption === 'pin6') $password = (string)rand(100000, 999999);
                                elseif ($passOption === 'prefix_nisn') { $last4 = !empty($s['nisn']) ? substr($s['nisn'], -4) : substr($s['nis'] ?? '1234', -4); $password = $prefixPass . $last4; }
                                else $password = !empty($s['nis']) ? $s['nis'] : (string)rand(100000, 999999);
                            }
                            $tokenToSave = $thisToken;
                        } else {
                            if ($passOption === 'pin6') $password = (string)rand(100000, 999999);
                            elseif ($passOption === 'prefix_nisn') { $last4 = !empty($s['nisn']) ? substr($s['nisn'], -4) : substr($s['nis'] ?? '1234', -4); $password = $prefixPass . $last4; }
                            else $password = !empty($s['nis']) ? $s['nis'] : (string)rand(100000, 999999);
                            $tokenToSave = $thisToken;
                        }

                        buatAksesUjianSiswa($pdo, $tipeAsesmen, $idAsesmen, $currKmpId, $s['id'], $nomorPeserta, $username, $password, !empty($tokenToSave) ? $tokenToSave : null, ['jenis_sumatif' => $jenisSumatifVal, 'semester' => $semesterVal, 'tahun_pelajaran' => $tahunPelajaranVal]);
                        $subGenerated++;

                        // Khusus Diagnostik dan Formatif: kirimkan kartu ujian PDF A5 langsung ke Gmail siswa (tanpa menyertakan token sesi)
                        if (($tipeAsesmen === 'diagnostik' || $tipeAsesmen === 'formatif') && !empty($s['gmail'])) {
                            $studentGmail = trim($s['gmail']);
                            if (filter_var($studentGmail, FILTER_VALIDATE_EMAIL)) {
                                $cardData = [
                                    'nama_siswa'          => $s['nama_lengkap'],
                                    'nomor_peserta'       => $nomorPeserta,
                                    'nis'                 => $s['nis'] ?? '-',
                                    'nisn'                => $s['nisn'] ?? '-',
                                    'nama_kelas'          => $namaKelasRow,
                                    'nama_mata_pelajaran' => $namaMapelRow,
                                    'judul_asesmen'       => $asmInfo['judul_asesmen'] ?? 'Asesmen CBT',
                                    'tipe_kategori'       => $tipeAsesmen,
                                    'pertemuan_ke'        => $pertemuanKe,
                                    'tahun_pelajaran'     => $tahunPelajaranVal,
                                    'semester'            => $semesterVal,
                                    'username_ujian'      => $username,
                                    'password_ujian'      => $password,
                                    'durasi_menit'        => (int)($asmInfo['durasi_menit'] ?? 60),
                                    'tanggal_pelaksanaan' => $asmInfo['tanggal_pelaksanaan'] ?? date('Y-m-d'),
                                    'jam_mulai'           => $asmInfo['jam_mulai'] ?? '08:00',
                                    'jam_selesai'         => $asmInfo['jam_selesai'] ?? '09:30',
                                    'foto'                => $s['foto'] ?? ''
                                ];
                                $mailRes = ilmuna_send_kartu_ujian_email($studentGmail, $cardData);
                                if (!empty($mailRes['success'])) {
                                    $emailSentTotal++;
                                } else {
                                    $emailFailTotal++;
                                }
                            } else {
                                $emailSkippedTotal++;
                            }
                        }
                    }
                }

                if ($tipeAsesmen === 'sumatif' && !empty($thisToken)) {
                    $updTokenStmt = $pdo->prepare("UPDATE akses_asesmen_sumatif SET token_sesi = ? WHERE id_asesmen_sumatif = ?");
                    $updTokenStmt->execute([$thisToken, $idAsesmen]);
                }

                $totalGenerated += $subGenerated;
                $processedSummary[] = ['id_asesmen' => $idAsesmen, 'judul' => $asmInfo['judul_asesmen'] ?? "#$idAsesmen", 'token' => $thisToken, 'total' => $subGenerated];
            }

            if (empty($processedSummary)) {
                echo json_encode(['success' => false, 'data' => ['message' => 'Belum ada rombel kelas yang terdaftar pada asesmen yang dipilih.']]);
                exit;
            }

            recordCbtEvent();

            $countAsm = count($processedSummary);
            if ($tipeAsesmen === 'sumatif') {
                if ($countAsm === 1) {
                    $first = $processedSummary[0];
                    $msg = "Berhasil men-generate {$first['total']} tiket akses untuk asesmen sumatif \"{$first['judul']}\"! Token Ujian: <strong>{$first['token']}</strong>";
                } else {
                    $tokenListStr = implode(', ', array_map(function($p) { return "{$p['judul']} (Token: <strong>{$p['token']}</strong>)"; }, $processedSummary));
                    $msg = "Berhasil men-generate $totalGenerated tiket akses untuk $countAsm asesmen sumatif sekaligus! $tokenListStr.";
                }
            } else {
                $msg = "Berhasil men-generate $totalGenerated tiket akses ujian untuk rombel kelas sasaran!";
                if ($emailSentTotal > 0) {
                    $msg .= " Kartu ujian PDF A5 telah otomatis dikirimkan ke <strong>$emailSentTotal</strong> Gmail mahasiswa.";
                }
            }

            echo json_encode(['success' => true, 'data' => ['message' => $msg, 'total' => $totalGenerated, 'count_asesmen' => $countAsm, 'summary' => $processedSummary, 'token_sesi' => ($countAsm === 1) ? $processedSummary[0]['token'] : null]]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal generate: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 1.3 RESET STATUS
    if ($act === 'reset_status') {
        $tipeAsesmen = in_array($_POST['tipe_asesmen'] ?? '', ['diagnostik', 'formatif', 'sumatif', 'remedial'], true) ? $_POST['tipe_asesmen'] : 'diagnostik';
        $tabelAkses = aksesTableName($tipeAsesmen);
        $fkAsesmen = aksesFkName($tipeAsesmen);
        $idAkses = (int)($_POST['id_akses'] ?? 0);
        $newStatus = $_POST['status_baru'] ?? 'Belum Mulai';
        if (!$idAkses) { echo json_encode(['success' => false, 'data' => ['message' => 'ID akses tidak valid.']]); exit; }
        try {
            $pdo->beginTransaction();

            $stmtInfo = $pdo->prepare("SELECT id_siswa, `$fkAsesmen` AS id_asesmen FROM `$tabelAkses` WHERE id = ?");
            $stmtInfo->execute([$idAkses]);
            $rowInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            if ($newStatus === 'Belum Mulai') {
                $stmt = $pdo->prepare("UPDATE `$tabelAkses` SET `status_akses` = 'Belum Mulai', `sisa_waktu_detik` = NULL, `waktu_mulai` = NULL, `waktu_selesai` = NULL, `ip_address` = NULL, `user_agent` = NULL, `updated_at` = NOW() WHERE id = ?");
                $stmt->execute([$idAkses]);

                if ($rowInfo) {
                    $idSiswa = (int)$rowInfo['id_siswa'];
                    $idAsesmen = (int)$rowInfo['id_asesmen'];

                    // 1. Hapus jawaban siswa di cbt_jawaban_siswa
                    $pdo->prepare("DELETE FROM `cbt_jawaban_siswa` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")
                        ->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);

                    // 2. Hapus log pelanggaran exambro
                    $pdo->prepare("DELETE FROM `cbt_log_pelanggaran_exambro` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")
                        ->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);

                    // 3. Hapus sesi ujian di cbt_sesi_ujian agar kembali ke state awal
                    $pdo->prepare("DELETE FROM `cbt_sesi_ujian` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")
                        ->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);

                    // 4. Hapus nilai ujian jika sudah ada agar bisa dinilai ulang dari awal
                    if ($tipeAsesmen === 'sumatif') {
                        $pdo->prepare("DELETE FROM `nilai_asesmen_sumatif` WHERE `id_asesmen_sumatif` = ? AND `id_siswa` = ?")
                            ->execute([$idAsesmen, $idSiswa]);
                    } elseif ($tipeAsesmen === 'formatif') {
                        $pdo->prepare("DELETE FROM `nilai_asesmen_formatif` WHERE `id_asesmen_formatif` = ? AND `id_siswa` = ?")
                            ->execute([$idAsesmen, $idSiswa]);
                    } elseif ($tipeAsesmen === 'diagnostik') {
                        $pdo->prepare("DELETE FROM `nilai_asesmen_diagnostik` WHERE `id_asesmen_diagnostik` = ? AND `id_siswa` = ?")
                            ->execute([$idAsesmen, $idSiswa]);
                    } elseif ($tipeAsesmen === 'remedial') {
                        $pdo->prepare("UPDATE `nilai_pengayaan_remedial` SET `nilai_akhir` = 0.00, `predikat` = 'E', `status_tuntas` = 'Belum Tuntas', `catatan_evaluasi` = 'Sesi ujian remedial di-reset ke Belum Mulai.' WHERE `id_pengayaan_remedial` = ? AND `id_siswa` = ?")
                            ->execute([$idAsesmen, $idSiswa]);
                    }
                }
            } else {
                $stmt = $pdo->prepare("UPDATE `$tabelAkses` SET `status_akses` = ?, `updated_at` = NOW() WHERE id = ?");
                $stmt->execute([$newStatus, $idAkses]);

                if ($rowInfo) {
                    $idSiswa = (int)$rowInfo['id_siswa'];
                    $idAsesmen = (int)$rowInfo['id_asesmen'];

                    if ($newStatus === 'Selesai') {
                        $pdo->prepare("UPDATE `cbt_sesi_ujian` SET `status_sesi` = 'Selesai', `is_frozen` = 0, `is_locked_admin` = 0, `sisa_waktu_detik` = 0, `waktu_selesai` = NOW(), `updated_at` = NOW() WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")
                            ->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
                    } elseif ($newStatus === 'Sedang Mengerjakan') {
                        $pdo->prepare("UPDATE `cbt_sesi_ujian` SET `status_sesi` = 'Sedang Mengerjakan', `is_frozen` = 0, `is_locked_admin` = 0, `updated_at` = NOW() WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")
                            ->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
                    } elseif ($newStatus === 'Terkunci') {
                        $pdo->prepare("UPDATE `cbt_sesi_ujian` SET `status_sesi` = 'Terkunci / Freeze', `is_frozen` = 1, `is_locked_admin` = 1, `updated_at` = NOW() WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")
                            ->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
                    }
                }
            }

            recordCbtEvent();
            $pdo->commit();
            echo json_encode(['success' => true, 'data' => ['message' => "Status akses & data sesi CBT siswa berhasil di-reset menjadi '$newStatus'."]]);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal reset status: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 1.3B UNFREEZE
    if ($act === 'unfreeze') {
        $tipeAsesmen = in_array($_POST['tipe_asesmen'] ?? '', ['diagnostik', 'formatif', 'sumatif', 'remedial'], true) ? $_POST['tipe_asesmen'] : 'diagnostik';
        $tabelAkses = aksesTableName($tipeAsesmen);
        $fkAsesmen = aksesFkName($tipeAsesmen);
        $idAkses = (int)($_POST['id_akses'] ?? 0);
        $currentUser = getCurrentUser();
        $adminId = (int)($currentUser['id'] ?? 1);
        if (!$idAkses) { echo json_encode(['success' => false, 'data' => ['message' => 'ID akses tidak valid.']]); exit; }
        try {
            $pdo->beginTransaction();
            $rowAkses = $pdo->query("SELECT id_siswa, `$fkAsesmen` AS id_asesmen FROM `$tabelAkses` WHERE id = $idAkses")->fetch(PDO::FETCH_ASSOC);
            if ($rowAkses) {
                $idSiswa = (int)$rowAkses['id_siswa'];
                $idAsesmen = (int)$rowAkses['id_asesmen'];
                // Potong sisa_waktu_detik sesuai waktu yang terus berjalan selama Freeze
                $updSesi = $pdo->prepare("
                    UPDATE `cbt_sesi_ujian`
                    SET `sisa_waktu_detik` = CASE
                            WHEN `sisa_waktu_detik` > 0 AND COALESCE(`updated_at`, `waktu_freeze`, `waktu_mulai`) IS NOT NULL THEN
                                GREATEST(0, CAST(`sisa_waktu_detik` AS SIGNED) - TIMESTAMPDIFF(SECOND, COALESCE(`updated_at`, `waktu_freeze`, `waktu_mulai`), NOW()))
                            ELSE `sisa_waktu_detik`
                        END,
                        `status_sesi` = 'Sedang Mengerjakan',
                        `is_frozen` = 0,
                        `is_locked_admin` = 0,
                        `dibuka_oleh` = ?,
                        `waktu_buka_kunci` = NOW(),
                        `updated_at` = NOW(),
                        `catatan_proktor` = 'Dibuka kunci manual oleh Administrator/Pengawas'
                    WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?
                ");
                $updSesi->execute([$adminId, $tipeAsesmen, $idAsesmen, $idSiswa]);

                $stmtNewSec = $pdo->prepare("SELECT sisa_waktu_detik FROM `cbt_sesi_ujian` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ? LIMIT 1");
                $stmtNewSec->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
                $newSec = $stmtNewSec->fetchColumn();
                if ($newSec !== false) {
                    $pdo->prepare("UPDATE `$tabelAkses` SET `status_akses` = 'Sedang Mengerjakan', `sisa_waktu_detik` = ?, `updated_at` = NOW() WHERE id = ?")->execute([(int)$newSec, $idAkses]);
                } else {
                    $pdo->prepare("UPDATE `$tabelAkses` SET `status_akses` = 'Sedang Mengerjakan', `updated_at` = NOW() WHERE id = ?")->execute([$idAkses]);
                }
            } else {
                $pdo->prepare("UPDATE `$tabelAkses` SET `status_akses` = 'Sedang Mengerjakan', `updated_at` = NOW() WHERE id = ?")->execute([$idAkses]);
            }
            recordCbtEvent();
            $pdo->commit();
            echo json_encode(['success' => true, 'data' => ['message' => 'Sesi ujian berhasil dibuka kunci (Unfreeze).']]);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal membuka kunci: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 1.3C GET VIOLATIONS
    if ($act === 'get_violations') {
        $idSiswa = (int)($_POST['id_siswa'] ?? 0);
        $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);
        $tipeAsesmen = in_array($_POST['tipe_asesmen'] ?? '', ['diagnostik', 'formatif', 'sumatif', 'remedial'], true) ? $_POST['tipe_asesmen'] : 'diagnostik';
        try {
            $stmt = $pdo->prepare("SELECT clp.*, s.nama_lengkap, s.nis FROM `cbt_log_pelanggaran_exambro` clp JOIN `siswa` s ON clp.id_siswa = s.id WHERE clp.id_siswa = ? AND clp.id_asesmen = ? AND clp.tipe_asesmen = ? ORDER BY clp.waktu_kejadian DESC");
            $stmt->execute([$idSiswa, $idAsesmen, $tipeAsesmen]);
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => ['logs' => $logs, 'total' => count($logs)]]);
            exit;
        } catch (Exception $e) { echo json_encode(['success' => false, 'data' => ['message' => 'Gagal mengambil log: ' . $e->getMessage()]]); exit; }
    }

    // 1.3D EXAM BRO SETTINGS
    if ($act === 'get_exambro_settings') {
        try {
            $row = $pdo->query("SELECT * FROM `cbt_pengaturan_exambro` WHERE `kode_pengaturan` = 'default_kiosk' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if (!$row) $row = ['wajib_exambro'=>1,'blokir_screenshot'=>1,'blokir_copy_paste'=>1,'auto_freeze_on_leave'=>1,'auto_logout_on_leave'=>1,'butuh_buka_manual_admin'=>1,'maks_toleransi_pelanggaran'=>3,'pin_pengawas_kiosk'=>'123456'];
            echo json_encode(['success' => true, 'data' => $row]);
            exit;
        } catch (Exception $e) { echo json_encode(['success' => false, 'data' => ['message' => $e->getMessage()]]); exit; }
    }

    if ($act === 'save_exambro_settings') {
        $wajibExambro = !empty($_POST['wajib_exambro']) ? 1 : 0;
        $blokirScreenshot = !empty($_POST['blokir_screenshot']) ? 1 : 0;
        $blokirCopyPaste = !empty($_POST['blokir_copy_paste']) ? 1 : 0;
        $autoFreeze = !empty($_POST['auto_freeze_on_leave']) ? 1 : 0;
        $autoLogout = !empty($_POST['auto_logout_on_leave']) ? 1 : 0;
        $butuhBukaManual = !empty($_POST['butuh_buka_manual_admin']) ? 1 : 0;
        $toleransi = max(0, (int)($_POST['maks_toleransi_pelanggaran'] ?? 3));
        $pinPengawas = trim($_POST['pin_pengawas_kiosk'] ?? '123456');
        try {
            $stmt = $pdo->prepare("INSERT INTO `cbt_pengaturan_exambro` (`kode_pengaturan`, `nama_pengaturan`, `wajib_exambro`, `blokir_screenshot`, `blokir_copy_paste`, `auto_freeze_on_leave`, `auto_logout_on_leave`, `butuh_buka_manual_admin`, `maks_toleransi_pelanggaran`, `pin_pengawas_kiosk`) VALUES ('default_kiosk', 'Standar Keamanan Ilmuna Exam Browser', ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `wajib_exambro` = VALUES(`wajib_exambro`), `blokir_screenshot` = VALUES(`blokir_screenshot`), `blokir_copy_paste` = VALUES(`blokir_copy_paste`), `auto_freeze_on_leave` = VALUES(`auto_freeze_on_leave`), `auto_logout_on_leave` = VALUES(`auto_logout_on_leave`), `butuh_buka_manual_admin` = VALUES(`butuh_buka_manual_admin`), `maks_toleransi_pelanggaran` = VALUES(`maks_toleransi_pelanggaran`), `pin_pengawas_kiosk` = VALUES(`pin_pengawas_kiosk`), `updated_at` = NOW()");
            $stmt->execute([$wajibExambro, $blokirScreenshot, $blokirCopyPaste, $autoFreeze, $autoLogout, $butuhBukaManual, $toleransi, $pinPengawas]);
            recordCbtEvent();
            echo json_encode(['success' => true, 'data' => ['message' => 'Konfigurasi keamanan Exam Browser berhasil disimpan!']]);
            exit;
        } catch (Exception $e) { echo json_encode(['success' => false, 'data' => ['message' => 'Gagal simpan konfigurasi: ' . $e->getMessage()]]); exit; }
    }

    // 1.4 UPDATE TOKEN
    if ($act === 'update_token') {
        $tipeAsesmen = in_array($_POST['tipe_asesmen'] ?? '', ['diagnostik', 'formatif', 'sumatif', 'remedial'], true) ? $_POST['tipe_asesmen'] : 'diagnostik';
        $idAsesmen = (int)($_POST['id_asesmen'] ?? 0);
        $tokenBaru = strtoupper(trim($_POST['token_baru'] ?? ''));
        try {
            $affected = 0;
            if ($tipeAsesmen === 'sumatif') {
                if (!$idAsesmen) { echo json_encode(['success' => false, 'data' => ['message' => 'Pilih asesmen sumatif sasaran.']]); exit; }
                $stmt = $pdo->prepare("UPDATE `akses_asesmen_sumatif` SET `token_sesi` = ? WHERE `id_asesmen_sumatif` = ?");
                $stmt->execute([$tokenBaru ?: null, $idAsesmen]);
                $affected = $stmt->rowCount();
            } elseif ($tipeAsesmen === 'remedial') {
                if (!$idAsesmen) { echo json_encode(['success' => false, 'data' => ['message' => 'Pilih program remedial sasaran.']]); exit; }
                $stmt = $pdo->prepare("UPDATE `akses_pengayaan_remedial` SET `token_sesi` = ? WHERE `id_pengayaan_remedial` = ?");
                $stmt->execute([$tokenBaru ?: null, $idAsesmen]);
                $affected = $stmt->rowCount();
            } elseif ($tipeAsesmen === 'diagnostik') {
                if (!$idAsesmen) { echo json_encode(['success' => false, 'data' => ['message' => 'Pilih asesmen diagnostik sasaran.']]); exit; }
                $stmt = $pdo->prepare("UPDATE `akses_asesmen_diagnostik` SET `token_sesi` = ? WHERE `id_asesmen_diagnostik` = ?");
                $stmt->execute([$tokenBaru ?: null, $idAsesmen]);
                $affected = $stmt->rowCount();
            } else {
                if (!$idAsesmen) { echo json_encode(['success' => false, 'data' => ['message' => 'Pilih asesmen formatif sasaran.']]); exit; }
                $stmt = $pdo->prepare("UPDATE `akses_asesmen_formatif` SET `token_sesi` = ? WHERE `id_asesmen_formatif` = ?");
                $stmt->execute([$tokenBaru ?: null, $idAsesmen]);
                $affected = $stmt->rowCount();
            }
            recordCbtEvent();
            $tokenText = $tokenBaru ? "menjadi '$tokenBaru'" : "dikosongkan";
            echo json_encode(['success' => true, 'data' => ['message' => "Token sesi ujian berhasil diperbarui $tokenText ($affected tiket peserta terupdate)!"]]);
            exit;
        } catch (Exception $e) { echo json_encode(['success' => false, 'data' => ['message' => 'Gagal update token: ' . $e->getMessage()]]); exit; }
    }

    // 1.5 HAPUS AKSES
    if ($act === 'hapus_akses') {
        $tipeAsesmen = in_array($_POST['tipe_asesmen'] ?? '', ['diagnostik', 'formatif', 'sumatif', 'remedial'], true) ? $_POST['tipe_asesmen'] : 'diagnostik';
        $tabelAkses = aksesTableName($tipeAsesmen);
        $fkAsesmen = aksesFkName($tipeAsesmen);
        $idAkses = (int)($_POST['id_akses'] ?? 0);
        if (!$idAkses) { echo json_encode(['success' => false, 'data' => ['message' => 'ID tiket tidak valid.']]); exit; }
        try {
            $pdo->beginTransaction();
            $stmtInfo = $pdo->prepare("SELECT id_siswa, `$fkAsesmen` AS id_asesmen FROM `$tabelAkses` WHERE id = ?");
            $stmtInfo->execute([$idAkses]);
            $rowInfo = $stmtInfo->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("DELETE FROM `$tabelAkses` WHERE id = ?");
            $stmt->execute([$idAkses]);

            if ($rowInfo) {
                $idSiswa = (int)$rowInfo['id_siswa'];
                $idAsesmen = (int)$rowInfo['id_asesmen'];
                $pdo->prepare("DELETE FROM `cbt_jawaban_siswa` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
                $pdo->prepare("DELETE FROM `cbt_log_pelanggaran_exambro` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
                $pdo->prepare("DELETE FROM `cbt_sesi_ujian` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipeAsesmen, $idAsesmen, $idSiswa]);
            }

            recordCbtEvent();
            $pdo->commit();
            echo json_encode(['success' => true, 'data' => ['message' => 'Tiket akses & sesi ujian CBT berhasil dihapus.']]);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menghapus tiket: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 1.6 BULK ACTION
    if ($act === 'bulk_action') {
        $bulkAct = $_POST['bulk_action_type'] ?? '';
        $targetIds = json_decode($_POST['selected_ids'] ?? '[]', true);
        if (empty($targetIds) || !is_array($targetIds)) { echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada data terpilih.']]); exit; }
        try {
            $countUpdated = 0;
            if ($bulkAct === 'unfreeze') {
                $currentUser = getCurrentUser();
                $adminId = (int)($currentUser['id'] ?? 1);
                $pdo->beginTransaction();
                foreach ($targetIds as $raw) {
                    $parts = explode('_', $raw);
                    if (count($parts) === 2) {
                        $tipe = $parts[0]; $tb = aksesTableName($tipe); $fk = aksesFkName($tipe); $uId = (int)$parts[1];
                        $row = $pdo->query("SELECT id_siswa, `$fk` AS id_asesmen FROM `$tb` WHERE id = $uId")->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $pdo->prepare("
                                UPDATE `cbt_sesi_ujian`
                                SET `sisa_waktu_detik` = CASE
                                        WHEN `sisa_waktu_detik` > 0 AND COALESCE(`updated_at`, `waktu_freeze`, `waktu_mulai`) IS NOT NULL THEN
                                            GREATEST(0, CAST(`sisa_waktu_detik` AS SIGNED) - TIMESTAMPDIFF(SECOND, COALESCE(`updated_at`, `waktu_freeze`, `waktu_mulai`), NOW()))
                                        ELSE `sisa_waktu_detik`
                                    END,
                                    `status_sesi` = 'Sedang Mengerjakan',
                                    `is_frozen` = 0,
                                    `is_locked_admin` = 0,
                                    `dibuka_oleh` = ?,
                                    `waktu_buka_kunci` = NOW(),
                                    `updated_at` = NOW()
                                WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?
                            ")->execute([$adminId, $tipe, (int)$row['id_asesmen'], (int)$row['id_siswa']]);
                            $stmtNewSec = $pdo->prepare("SELECT sisa_waktu_detik FROM `cbt_sesi_ujian` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ? LIMIT 1");
                            $stmtNewSec->execute([$tipe, (int)$row['id_asesmen'], (int)$row['id_siswa']]);
                            $newSec = $stmtNewSec->fetchColumn();
                            if ($newSec !== false) {
                                $pdo->prepare("UPDATE `$tb` SET `status_akses` = 'Sedang Mengerjakan', `sisa_waktu_detik` = ?, `updated_at` = NOW() WHERE id = ?")->execute([(int)$newSec, $uId]);
                            } else {
                                $pdo->prepare("UPDATE `$tb` SET `status_akses` = 'Sedang Mengerjakan', `updated_at` = NOW() WHERE id = ?")->execute([$uId]);
                            }
                        } else {
                            $pdo->prepare("UPDATE `$tb` SET `status_akses` = 'Sedang Mengerjakan', `updated_at` = NOW() WHERE id = ?")->execute([$uId]);
                        }
                        $countUpdated++;
                    }
                }
                recordCbtEvent();
                $pdo->commit();
                echo json_encode(['success' => true, 'data' => ['message' => "$countUpdated siswa berhasil dibuka kuncinya (Unfreeze)."]]);
                exit;
            } elseif ($bulkAct === 'reset_status') {
                $statusVal = $_POST['status_baru'] ?? 'Belum Mulai';
                $pdo->beginTransaction();
                foreach ($targetIds as $raw) {
                    $parts = explode('_', $raw);
                    if (count($parts) === 2) {
                        $tipe = $parts[0]; $tb = aksesTableName($tipe); $fk = aksesFkName($tipe); $uId = (int)$parts[1];
                        $rowInfo = $pdo->query("SELECT id_siswa, `$fk` AS id_asesmen FROM `$tb` WHERE id = $uId")->fetch(PDO::FETCH_ASSOC);

                        if ($statusVal === 'Belum Mulai') {
                            $pdo->prepare("UPDATE `$tb` SET `status_akses` = 'Belum Mulai', `sisa_waktu_detik` = NULL, `waktu_mulai` = NULL, `waktu_selesai` = NULL, `ip_address` = NULL, `user_agent` = NULL, `updated_at` = NOW() WHERE id = ?")->execute([$uId]);
                            if ($rowInfo) {
                                $idSiswa = (int)$rowInfo['id_siswa'];
                                $idAsesmen = (int)$rowInfo['id_asesmen'];
                                $pdo->prepare("DELETE FROM `cbt_jawaban_siswa` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipe, $idAsesmen, $idSiswa]);
                                $pdo->prepare("DELETE FROM `cbt_log_pelanggaran_exambro` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipe, $idAsesmen, $idSiswa]);
                                $pdo->prepare("DELETE FROM `cbt_sesi_ujian` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipe, $idAsesmen, $idSiswa]);
                                if ($tipe === 'sumatif') {
                                    $pdo->prepare("DELETE FROM `nilai_asesmen_sumatif` WHERE `id_asesmen_sumatif` = ? AND `id_siswa` = ?")->execute([$idAsesmen, $idSiswa]);
                                } elseif ($tipe === 'formatif') {
                                    $pdo->prepare("DELETE FROM `nilai_asesmen_formatif` WHERE `id_asesmen_formatif` = ? AND `id_siswa` = ?")->execute([$idAsesmen, $idSiswa]);
                                } elseif ($tipe === 'diagnostik') {
                                    $pdo->prepare("DELETE FROM `nilai_asesmen_diagnostik` WHERE `id_asesmen_diagnostik` = ? AND `id_siswa` = ?")->execute([$idAsesmen, $idSiswa]);
                                } elseif ($tipe === 'remedial') {
                                    $pdo->prepare("UPDATE `nilai_pengayaan_remedial` SET `nilai_akhir` = 0.00, `predikat` = 'E', `status_tuntas` = 'Belum Tuntas', `catatan_evaluasi` = 'Sesi ujian remedial di-reset ke Belum Mulai.' WHERE `id_pengayaan_remedial` = ? AND `id_siswa` = ?")->execute([$idAsesmen, $idSiswa]);
                                }
                            }
                        } else {
                            $pdo->prepare("UPDATE `$tb` SET `status_akses` = ?, `updated_at` = NOW() WHERE id = ?")->execute([$statusVal, $uId]);
                            if ($rowInfo) {
                                $idSiswa = (int)$rowInfo['id_siswa'];
                                $idAsesmen = (int)$rowInfo['id_asesmen'];
                                if ($statusVal === 'Selesai') {
                                    $pdo->prepare("UPDATE `cbt_sesi_ujian` SET `status_sesi` = 'Selesai', `is_frozen` = 0, `sisa_waktu_detik` = 0, `waktu_selesai` = NOW(), `updated_at` = NOW() WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipe, $idAsesmen, $idSiswa]);
                                } elseif ($statusVal === 'Sedang Mengerjakan') {
                                    $pdo->prepare("UPDATE `cbt_sesi_ujian` SET `status_sesi` = 'Sedang Mengerjakan', `is_frozen` = 0, `is_locked_admin` = 0, `updated_at` = NOW() WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipe, $idAsesmen, $idSiswa]);
                                } elseif ($statusVal === 'Terkunci') {
                                    $pdo->prepare("UPDATE `cbt_sesi_ujian` SET `status_sesi` = 'Terkunci / Freeze', `is_frozen` = 1, `is_locked_admin` = 1, `updated_at` = NOW() WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipe, $idAsesmen, $idSiswa]);
                                }
                            }
                        }
                        $countUpdated++;
                    }
                }
                recordCbtEvent();
                $pdo->commit();
                echo json_encode(['success' => true, 'data' => ['message' => "$countUpdated tiket & sesi CBT berhasil diubah statusnya menjadi '$statusVal'."]]);
                exit;
            } elseif ($bulkAct === 'delete') {
                $pdo->beginTransaction();
                foreach ($targetIds as $raw) {
                    $parts = explode('_', $raw);
                    if (count($parts) === 2) {
                        $tipe = $parts[0]; $tb = aksesTableName($tipe); $fk = aksesFkName($tipe); $uId = (int)$parts[1];
                        $rowInfo = $pdo->query("SELECT id_siswa, `$fk` AS id_asesmen FROM `$tb` WHERE id = $uId")->fetch(PDO::FETCH_ASSOC);
                        $pdo->prepare("DELETE FROM `$tb` WHERE id = ?")->execute([$uId]);
                        if ($rowInfo) {
                            $idSiswa = (int)$rowInfo['id_siswa'];
                            $idAsesmen = (int)$rowInfo['id_asesmen'];
                            $pdo->prepare("DELETE FROM `cbt_jawaban_siswa` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipe, $idAsesmen, $idSiswa]);
                            $pdo->prepare("DELETE FROM `cbt_log_pelanggaran_exambro` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipe, $idAsesmen, $idSiswa]);
                            $pdo->prepare("DELETE FROM `cbt_sesi_ujian` WHERE `tipe_asesmen` = ? AND `id_asesmen` = ? AND `id_siswa` = ?")->execute([$tipe, $idAsesmen, $idSiswa]);
                        }
                        $countUpdated++;
                    }
                }
                recordCbtEvent();
                $pdo->commit();
                echo json_encode(['success' => true, 'data' => ['message' => "$countUpdated tiket berhasil dihapus permanen."]]);
                exit;
            }
            echo json_encode(['success' => false, 'data' => ['message' => 'Aksi massal tidak dikenali.']]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal eksekusi aksi massal: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 1.7 SEND KARTU EMAIL (SINGLE)
    if ($act === 'send_kartu_email') {
        $tipeAsesmen = in_array($_POST['tipe_asesmen'] ?? '', ['diagnostik', 'formatif', 'sumatif', 'remedial'], true) ? $_POST['tipe_asesmen'] : 'diagnostik';
        if ($tipeAsesmen === 'sumatif') {
            echo json_encode(['success' => false, 'data' => ['message' => 'Kartu peserta asesmen sumatif tidak dikirimkan ke Gmail (wajib dicetak / diunduh format A4).']]);
            exit;
        }
        $idAkses = (int)($_POST['id_akses'] ?? 0);
        if (!$idAkses) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID akses tidak valid.']]);
            exit;
        }

        $cardData = getCardDataForTicket($pdo, $tipeAsesmen, $idAkses);
        if (!$cardData) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Data tiket kartu ujian tidak ditemukan.']]);
            exit;
        }

        $gmail = trim($cardData['gmail'] ?? '');
        if (empty($gmail) || !filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Mahasiswa ini belum memiliki alamat Gmail yang valid di data profil siswa.']]);
            exit;
        }

        $res = ilmuna_send_kartu_ujian_email($gmail, $cardData);
        if (!empty($res['success'])) {
            echo json_encode(['success' => true, 'data' => ['message' => 'Kartu ujian berhasil dikirimkan ke Gmail mahasiswa!']]);
        } else {
            echo json_encode(['success' => false, 'data' => ['message' => $res['message'] ?? 'Gagal mengirimkan email kartu ujian.']]);
        }
        exit;
    }

    // 1.8 BULK SEND KARTU EMAIL
    if ($act === 'bulk_send_kartu_email') {
        $targetIds = json_decode($_POST['selected_ids'] ?? '[]', true);
        if (empty($targetIds) || !is_array($targetIds)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada tiket yang dipilih.']]);
            exit;
        }

        $sentCount = 0;
        $failCount = 0;
        $noEmailCount = 0;

        foreach ($targetIds as $raw) {
            $parts = explode('_', $raw);
            if (count($parts) === 2) {
                $tipe = $parts[0];
                if ($tipe === 'sumatif') {
                    continue; // Sumatif tidak dikirimkan ke Gmail
                }
                $idAkses = (int)$parts[1];
                $cardData = getCardDataForTicket($pdo, $tipe, $idAkses);
                if (!$cardData) continue;

                $gmail = trim($cardData['gmail'] ?? '');
                if (!empty($gmail) && filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
                    $sendRes = ilmuna_send_kartu_ujian_email($gmail, $cardData);
                    if (!empty($sendRes['success'])) {
                        $sentCount++;
                    } else {
                        $failCount++;
                    }
                } else {
                    $noEmailCount++;
                }
            }
        }

        $msg = "Kartu ujian berhasil dikirimkan ke Gmail mahasiswa!";
        if ($sentCount > 0) {
            $msg = "Berhasil mengirimkan kartu ujian PDF A5 ke $sentCount Gmail mahasiswa!";
            if ($noEmailCount > 0) $msg .= " ($noEmailCount siswa dilewati karena belum isi Gmail).";
        } elseif ($noEmailCount > 0) {
            $msg = "Seluruh siswa terpilih belum memiliki alamat Gmail terdaftar.";
        } else {
            $msg = "Pengiriman gagal diproses.";
        }

        echo json_encode(['success' => ($sentCount > 0), 'data' => ['message' => $msg, 'sent' => $sentCount, 'failed' => $failCount, 'no_email' => $noEmailCount]]);
        exit;
    }

    echo json_encode(['success' => false, 'data' => ['message' => 'Aksi tidak valid.']]);
    exit;
}

/* ==============================================================================
 * 2. MASTER OPTIONS FOR DROPDOWNS & FILTER TABS
 * ============================================================================== */
$kelasList = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
$mapelList = $pdo->query("SELECT id, nama_mata_pelajaran FROM mata_pelajaran ORDER BY nama_mata_pelajaran ASC")->fetchAll(PDO::FETCH_ASSOC);

$asesmenDiagnostikList = $pdo->query("
    SELECT ad.id, ad.judul_asesmen, ad.jenis_diagnostik, ad.pertemuan_ke, ad.durasi_menit,
           kmp.id_kelas, kmp.id_mata_pelajaran, k.nama_kelas, mp.nama_mata_pelajaran
    FROM asesmen_diagnostik ad
    JOIN kelas_mata_pelajaran kmp ON ad.id_kelas_mata_pelajaran = kmp.id
    JOIN kelas k ON kmp.id_kelas = k.id
    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    ORDER BY ad.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$asesmenRemedialList = $pdo->query("
    SELECT pr.id, pr.judul_program AS judul_asesmen, pr.kategori AS jenis_remedial, pr.pertemuan_ke, pr.durasi_menit,
           COALESCE(NULLIF(pr.kktp_minimal, 0), 75.00) AS kktp_minimal,
           kmp.id_kelas, kmp.id_mata_pelajaran, k.nama_kelas, mp.nama_mata_pelajaran,
           (SELECT apr_t.token_sesi FROM akses_pengayaan_remedial apr_t WHERE apr_t.id_pengayaan_remedial = pr.id AND apr_t.token_sesi IS NOT NULL AND apr_t.token_sesi != '' LIMIT 1) AS token_sesi
    FROM pengayaan_remedial pr
    JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id
    JOIN kelas k ON kmp.id_kelas = k.id
    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    ORDER BY pr.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($asesmenRemedialList as &$arItem) {
    $elRows = getEligibleRemedialStudents($pdo, (int)$arItem['id']);
    $arItem['jumlah_layak'] = count($elRows);
    $namesArr = [];
    foreach ($elRows as $elSw) {
        $nAwal = isset($elSw['nilai_awal']) ? rtrim(rtrim(number_format((float)$elSw['nilai_awal'], 2, '.', ''), '0'), '.') : '0';
        $namesArr[] = $elSw['nama_lengkap'] . ' (Nilai: ' . $nAwal . ')';
    }
    $arItem['daftar_siswa_layak'] = implode(', ', $namesArr);
}
unset($arItem);

$asesmenFormatifList = $pdo->query("
    SELECT af.id, af.judul_asesmen, af.teknik_asesmen, af.pertemuan_ke, af.durasi_menit,
           kmp.id_kelas, kmp.id_mata_pelajaran, k.nama_kelas, mp.nama_mata_pelajaran
    FROM asesmen_formatif af
    JOIN kelas_mata_pelajaran kmp ON af.id_kelas_mata_pelajaran = kmp.id
    JOIN kelas k ON kmp.id_kelas = k.id
    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    ORDER BY af.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$asesmenSumatifList = $pdo->query("
    SELECT asum.id, asum.judul_asesmen, asum.jenis_sumatif, asum.durasi_menit, asum.pertemuan_ke,
           COALESCE(asum.semester, mp.semester, 'Ganjil') AS semester,
           COALESCE(asum.tahun_pelajaran, mp.tahun_pelajaran, k.tahun_pelajaran, '2026/2027') AS tahun_pelajaran,
           (SELECT aas_tok.token_sesi FROM akses_asesmen_sumatif aas_tok WHERE aas_tok.id_asesmen_sumatif = asum.id AND aas_tok.token_sesi IS NOT NULL AND aas_tok.token_sesi != '' LIMIT 1) AS token_sesi,
           COALESCE(kmp.id_kelas, (SELECT kmp2.id_kelas FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1)) AS id_kelas,
           COALESCE(kmp.id_mata_pelajaran, (SELECT kmp2.id_mata_pelajaran FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1)) AS id_mata_pelajaran,
           COALESCE((SELECT GROUP_CONCAT(DISTINCT k2.nama_kelas ORDER BY k2.nama_kelas SEPARATOR ', ') FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id JOIN kelas k2 ON kmp2.id_kelas = k2.id WHERE ask2.id_asesmen_sumatif = asum.id), k.nama_kelas, '') AS nama_kelas,
           COALESCE((SELECT GROUP_CONCAT(DISTINCT kmp2.id_kelas SEPARATOR ',') FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id WHERE ask2.id_asesmen_sumatif = asum.id), CAST(kmp.id_kelas AS CHAR), '') AS multi_kelas_ids,
           COALESCE(mp.nama_mata_pelajaran, (SELECT mp2.nama_mata_pelajaran FROM asesmen_sumatif_kelas ask2 JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1), '') AS nama_mata_pelajaran
    FROM asesmen_sumatif asum
    LEFT JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id
    LEFT JOIN kelas k ON kmp.id_kelas = k.id
    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    ORDER BY asum.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$jenisSumatifOptions = ['Sumatif Lingkup Materi', 'Sumatif Tengah Semester', 'Sumatif Akhir Semester'];
$semesterOptions = ['Ganjil', 'Genap'];
$tahunPelajaranOptions = array_values(array_unique(array_filter(array_merge(
    array_column($kelasList, 'tahun_pelajaran'),
    array_column($asesmenSumatifList, 'tahun_pelajaran')
))));
if (empty($tahunPelajaranOptions)) {
    $tahunPelajaranOptions = ['2026/2027'];
}
rsort($tahunPelajaranOptions);

// CSS OVERRIDES — NEW LAYOUT (Tabs text strip + Filter actions inline)
$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-assessment-access.css') ? filemtime(BASE_PATH . '/assets/css/admin-assessment-access.css') : '1.1';
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-assessment-access.css?v=' . $cssVersion . '">
<style>
/* ===== TABS STRIP (TEXT, LEFT ALIGNED) ===== */
.ilmuna-main-tabs-strip {
    display: flex;
    align-items: center;
    gap: 0;
    border-bottom: 1px solid #E2E8F0;
    margin-bottom: 12px;
    padding: 0;
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
}
.ilmuna-main-tabs-strip::-webkit-scrollbar { display: none; }

.ilmuna-main-tabs-strip .ilmuna-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 11px 14px;
    background: transparent;
    border: none;
    border-bottom: 2.5px solid transparent;
    font-size: 13px;
    font-weight: 600;
    color: #64748B;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.18s ease;
    flex-shrink: 0;
    margin-bottom: -1px;
    line-height: 1;
    border-radius: 0;
}
.ilmuna-main-tabs-strip .ilmuna-tab-btn:hover {
    color: #0D7A87;
    background: rgba(13, 122, 135, 0.05);
}
.ilmuna-main-tabs-strip .ilmuna-tab-btn.is-active {
    color: #0D7A87;
    border-bottom-color: #0D7A87;
    font-weight: 800;
}
.ilmuna-main-tabs-strip .ilmuna-tab-btn--sumatif.is-active {
    color: #B45309;
    border-bottom-color: #F59E0B;
}
.ilmuna-main-tabs-strip .ilmuna-tab-btn iconify-icon {
    flex-shrink: 0;
    color: currentColor;
}
.ilmuna-main-tabs-strip .ilmuna-tab-btn__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 18px;
    padding: 0 6px;
    border-radius: 9px;
    background: #F1F5F9;
    color: #64748B;
    font-size: 10.5px;
    font-weight: 800;
    margin-left: 2px;
}
.ilmuna-main-tabs-strip .ilmuna-tab-btn.is-active .ilmuna-tab-btn__badge {
    background: #0D7A87;
    color: #FFFFFF;
}
.ilmuna-main-tabs-strip .ilmuna-tab-btn--sumatif.is-active .ilmuna-tab-btn__badge {
    background: #F59E0B;
    color: #FFFFFF;
}

/* ===== FILTER BOX + ACTIONS INLINE ===== */
.ilmuna-filter-box {
    padding: 10px 12px !important;
    margin-bottom: 12px !important;
}
.ilmuna-filter-topline {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
    flex-wrap: wrap;
}
.ilmuna-filter-topline .ilmuna-pill-group {
    display: flex;
    gap: 5px;
    flex-wrap: nowrap;
    flex-shrink: 0;
    overflow-x: auto;
    scrollbar-width: none;
    -ms-overflow-style: none;
    padding: 1px;
}
.ilmuna-filter-topline .ilmuna-pill-group::-webkit-scrollbar { display: none; }
.ilmuna-filter-topline .ilmuna-pill-btn {
    padding: 5px 10px !important;
    font-size: 11.5px !important;
    height: 30px !important;
    border-radius: 8px !important;
    white-space: nowrap;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    line-height: 1;
}
.ilmuna-filter-topline .ilmuna-search-wrap {
    flex: 1;
    min-width: 160px;
    max-width: 340px;
    height: 32px !important;
    border-radius: 8px !important;
}
.ilmuna-filter-topline .ilmuna-search-input {
    height: 30px !important;
    font-size: 12px !important;
    border-radius: 8px !important;
}
.ilmuna-filter-actions {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-left: auto;
    flex-shrink: 0;
    flex-wrap: nowrap;
}
.ilmuna-nav-stat-inline {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 0 10px;
    background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
    border: 1px solid #FCD34D;
    border-radius: 8px;
    font-size: 11.5px;
    font-weight: 600;
    color: #92400E;
    white-space: nowrap;
    height: 32px;
    cursor: help;
}
.ilmuna-nav-stat-inline strong {
    font-size: 13px;
    font-weight: 800;
    color: #B45309;
}
.ilmuna-nav-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 32px;
    padding: 0 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    white-space: nowrap;
    transition: all 0.18s ease;
    line-height: 1;
}
.ilmuna-nav-btn--primary {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.28);
}
.ilmuna-nav-btn--primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 15px rgba(245, 158, 11, 0.38);
}
.ilmuna-nav-btn--ghost {
    background: #FFFFFF;
    color: #1D3B5E;
    border: 1.5px solid #CBD5E1;
}
.ilmuna-nav-btn--ghost:hover {
    background: #F8FAFC;
    border-color: #0D7A87;
    color: #0D7A87;
}
.ilmuna-nav-menu-wrap { position: relative; }
.ilmuna-nav-dropdown {
    position: absolute;
    top: calc(100% + 8px);
    right: 0;
    width: 240px;
    background: #FFFFFF;
    border-radius: 12px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.18);
    border: 1px solid #E2E8F0;
    padding: 6px;
    display: none;
    flex-direction: column;
    gap: 3px;
    z-index: 999;
}
.ilmuna-nav-dropdown.is-open { display: flex; }
.ilmuna-nav-drop-item {
    width: 100%;
    text-align: left;
    background: transparent;
    border: none;
    padding: 9px 11px;
    border-radius: 8px;
    font-size: 12.5px;
    font-weight: 600;
    color: #1E293B;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: background 0.15s ease;
}
.ilmuna-nav-drop-item:hover {
    background: #F1F5F9;
    color: #0D7A87;
}

/* ===== BOTTOM FILTER LINE ===== */
.ilmuna-filter-bottomline {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: nowrap;
    overflow-x: auto;
    scrollbar-width: thin;
    padding-bottom: 2px;
    -webkit-overflow-scrolling: touch;
}
.ilmuna-filter-bottomline::-webkit-scrollbar { height: 4px; }
.ilmuna-filter-bottomline::-webkit-scrollbar-thumb { background: #CBD5E1; border-radius: 4px; }
.ilmuna-filter-bottomline .ilmuna-select {
    height: 32px !important;
    font-size: 12px !important;
    padding: 0 26px 0 10px !important;
    border-radius: 8px !important;
    width: auto !important;
    min-width: 105px;
    max-width: 175px;
    flex-shrink: 0;
}
.ilmuna-filter-bottomline .ilmuna-btn {
    height: 32px !important;
    padding: 0 10px !important;
    font-size: 12px !important;
    flex-shrink: 0;
    white-space: nowrap;
    border-radius: 8px !important;
}

@media (max-width: 900px) {
    .ilmuna-filter-actions {
        width: 100%;
        justify-content: flex-end;
        margin-left: 0;
    }
}
</style>';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<!-- ==============================================================================
 * LUMINA GOLD — ASSESSMENT ACCESS & CBT CREDENTIAL ENGINE
 * ============================================================================== -->
<div class="ilmuna-access-app" id="appAccess" data-ajax="<?= BASE_URL ?>/dashboard/admin/assessment-access">

    <!-- TABS STRIP (TEXT-BASED, LEFT ALIGNED) -->
    <div class="ilmuna-main-tabs-strip" id="assessmentMainTabs" role="tablist">
        <button type="button" class="ilmuna-tab-btn is-active" data-tab="diagnostik">
            <iconify-icon icon="solar:clipboard-check-bold" width="16"></iconify-icon>
            <span>Asesmen Diagnostik</span>
            <span class="ilmuna-tab-btn__badge" id="tabBadgeDiagnostik">0</span>
        </button>
        <button type="button" class="ilmuna-tab-btn" data-tab="formatif">
            <iconify-icon icon="solar:pen-new-square-bold" width="16"></iconify-icon>
            <span>Asesmen Formatif</span>
            <span class="ilmuna-tab-btn__badge" id="tabBadgeFormatif">0</span>
        </button>
        <button type="button" class="ilmuna-tab-btn ilmuna-tab-btn--sumatif" data-tab="sumatif">
            <iconify-icon icon="solar:laptop-minimalistic-bold" width="16"></iconify-icon>
            <span>Asesmen Sumatif (CBT)</span>
            <span class="ilmuna-tab-btn__badge" id="tabBadgeSumatif">0</span>
        </button>
        <button type="button" class="ilmuna-tab-btn" data-tab="remedial">
            <iconify-icon icon="solar:medal-ribbon-star-bold" width="16"></iconify-icon>
            <span>Remedial / Pengayaan</span>
            <span class="ilmuna-tab-btn__badge" id="tabBadgeRemedial">0</span>
        </button>
    </div>

    <!-- FILTER & SEARCH TOOLBAR + ACTION BUTTONS INLINE -->
    <div class="ilmuna-filter-box">
        <div class="ilmuna-filter-topline">
            <div class="ilmuna-pill-group" id="filterStatusPills">
                <button type="button" class="ilmuna-pill-btn is-active" data-status="all">
                    <iconify-icon icon="solar:layers-minimalistic-bold" width="13"></iconify-icon> Semua
                </button>
                <button type="button" class="ilmuna-pill-btn" data-status="Sedang Mengerjakan" style="color: #D97706;">
                    <iconify-icon icon="solar:clock-circle-bold" width="13"></iconify-icon> Mengerjakan
                </button>
                <button type="button" class="ilmuna-pill-btn" data-status="Terkunci" style="color: #EF4444;">
                    <iconify-icon icon="solar:lock-bold" width="13"></iconify-icon> Terkunci
                </button>
                <button type="button" class="ilmuna-pill-btn" data-status="Selesai" style="color: #10B981;">
                    <iconify-icon icon="solar:check-circle-bold" width="13"></iconify-icon> Selesai
                </button>
                <button type="button" class="ilmuna-pill-btn" data-status="Belum Mulai">
                    <iconify-icon icon="solar:play-circle-bold" width="13"></iconify-icon> Belum
                </button>
            </div>

            <div class="ilmuna-search-wrap">
                <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                <input type="text" id="filterSearch" class="ilmuna-search-input" placeholder="Cari siswa, NIS, username..." autocomplete="off">
            </div>

            <div class="ilmuna-filter-actions">
                <div class="ilmuna-nav-stat-inline" id="navStatInline" title="Status ujian saat ini">
                    <iconify-icon icon="solar:user-clock-bold" width="14"></iconify-icon>
                    <strong id="kpiActiveNow">0</strong>
                    <span>Mengerjakan</span>
                </div>

                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" id="btnOpenGenModal" title="Generate Otomatis Tiket & Akun Ujian Per Rombel Kelas">
                    <iconify-icon icon="solar:magic-stick-3-bold" width="16"></iconify-icon>
                    <span>Generate Akses</span>
                </button>

                <div class="ilmuna-nav-menu-wrap">
                    <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" id="btnToggleNavMenu" title="Menu Navigasi & Pengaturan">
                        <iconify-icon icon="solar:widget-5-bold" width="15"></iconify-icon>
                        <span>Menu</span>
                        <iconify-icon icon="solar:alt-arrow-down-bold" width="12"></iconify-icon>
                    </button>
                    <div class="ilmuna-nav-dropdown" id="navQuickDropdown">
                        <button type="button" class="ilmuna-nav-drop-item" id="btnOpenUpdateToken">
                            <iconify-icon icon="solar:shield-keyhole-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                            <span>Update Token Sesi</span>
                        </button>
                        <button type="button" class="ilmuna-nav-drop-item" id="btnTriggerPrintAll">
                            <iconify-icon icon="solar:printer-bold" width="18" style="color: #0284C7;"></iconify-icon>
                            <span>Cetak Kartu Ujian</span>
                        </button>
                        <button type="button" class="ilmuna-nav-drop-item" id="btnDownloadTokenPdf">
                            <iconify-icon icon="solar:key-square-bold" width="18" style="color: #10B981;"></iconify-icon>
                            <span>Download Daftar Token (PDF)</span>
                        </button>
                        <button type="button" class="ilmuna-nav-drop-item" id="btnOpenExambroSettings">
                            <iconify-icon icon="solar:shield-warning-bold" width="18" style="color: #D97706;"></iconify-icon>
                            <span>Keamanan Exam Browser</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="ilmuna-filter-bottomline">
            <!-- Filter bertahap khusus Sumatif: 1. Tahun Pelajaran -> 2. Semester -> 3. Jenis Sumatif -> 4. Kelas -> 5. Asesmen -->
            <select id="filterTahunPelajaran" class="ilmuna-select" style="display: none;" title="Pilih Tahun Pelajaran">
                <option value="">-- Pilih Tahun Pelajaran --</option>
                <?php foreach ($tahunPelajaranOptions as $tp): ?>
                    <option value="<?= htmlspecialchars($tp) ?>"><?= htmlspecialchars($tp) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="filterSemester" class="ilmuna-select" style="display: none;" title="Pilih Semester">
                <option value="">-- Pilih Semester --</option>
                <?php foreach ($semesterOptions as $sem): ?>
                    <option value="<?= htmlspecialchars($sem) ?>">Semester <?= htmlspecialchars($sem) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="filterJenisSumatif" class="ilmuna-select" style="display: none;" title="Pilih Jenis Asesmen Sumatif">
                <option value="">-- Pilih Jenis Sumatif --</option>
                <?php foreach ($jenisSumatifOptions as $js): ?>
                    <option value="<?= htmlspecialchars($js) ?>"><?= htmlspecialchars($js) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="filterKelas" class="ilmuna-select">
                <option value="0">Semua Kelas</option>
                <?php foreach ($kelasList as $k): ?>
                    <option value="<?= $k['id'] ?>" data-tahun="<?= htmlspecialchars($k['tahun_pelajaran'] ?? '') ?>"><?= htmlspecialchars($k['nama_kelas']) ?> (<?= htmlspecialchars($k['tahun_pelajaran']) ?>)</option>
                <?php endforeach; ?>
            </select>

            <select id="filterMapel" class="ilmuna-select">
                <option value="0">Semua Mapel</option>
                <?php foreach ($mapelList as $mp): ?>
                    <option value="<?= $mp['id'] ?>"><?= htmlspecialchars($mp['nama_mata_pelajaran']) ?></option>
                <?php endforeach; ?>
            </select>

            <select id="filterPertemuan" class="ilmuna-select">
                <option value="0">Semua Pertemuan</option>
                <?php for ($p = 1; $p <= 16; $p++): ?>
                    <option value="<?= $p ?>">Ke-<?= $p ?></option>
                <?php endfor; ?>
            </select>

            <select id="filterAsesmen" class="ilmuna-select">
                <option value="0">Semua Asesmen</option>
            </select>

            <select id="filterPerPage" class="ilmuna-select">
                <option value="20" selected>20/Hal</option>
                <option value="50">50/Hal</option>
                <option value="100">100/Hal</option>
            </select>

            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" id="btnResetFilter" title="Reset Semua Filter">
                <iconify-icon icon="solar:restart-bold"></iconify-icon>
            </button>
        </div>
    </div>

    <!-- MAIN DATA TABLE CARD -->
    <div class="ilmuna-table-card">
        <div class="ilmuna-table-wrap">
            <table class="ilmuna-table" id="tableAccess">
                <thead>
                    <tr>
                        <th style="width: 44px; text-align: center;">
                            <input type="checkbox" id="checkAllRows" style="cursor: pointer; width: 16px; height: 16px;">
                        </th>
                        <th style="width: 50px; text-align: center;">No</th>
                        <th style="width: 150px;">No. Peserta</th>
                        <th style="min-width: 180px;">Siswa & Rombel</th>
                        <th style="min-width: 250px;">Asesmen, Mapel & Urutan</th>
                        <th style="width: 140px;">Username Login</th>
                        <th style="width: 130px;">Password / PIN</th>
                        <th style="width: 130px; text-align: center;">Token Sesi</th>
                        <th style="width: 130px; text-align: center;">Status Akses</th>
                        <th style="width: 140px; text-align: center;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="tableAccessBody">
                    <?php for ($sk = 0; $sk < 5; $sk++): ?>
                    <tr class="ilmuna-skeleton-row">
                        <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 16px; height: 16px;"></div></td>
                        <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 22px;"></div></td>
                        <td><div class="ilmuna-skeleton-box" style="width: 110px;"></div></td>
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
                            <div class="ilmuna-skeleton-box" style="width: 120px; margin-bottom: 4px;"></div><br>
                            <div class="ilmuna-skeleton-box" style="width: 80px; height: 10px;"></div>
                        </td>
                        <td><div class="ilmuna-skeleton-box" style="width: 85px;"></div></td>
                        <td><div class="ilmuna-skeleton-box" style="width: 75px;"></div></td>
                        <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 65px;"></div></td>
                        <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 90px;"></div></td>
                        <td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 95px;"></div></td>
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

    <!-- FLOATING BULK ACTIONS BAR -->
    <div class="ilmuna-bulk-bar" id="bulkActionBar">
        <div class="ilmuna-bulk-bar__count">
            <span id="bulkSelectedCount">0</span> Tiket Terpilih
        </div>
        <div class="ilmuna-bulk-bar__actions">
            <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" id="btnBulkSendEmail" style="background:#0D7A87; color:#FFFFFF;">
                <iconify-icon icon="solar:letter-bold" width="16"></iconify-icon> Kirim Kartu ke Gmail
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--teal ilmuna-btn--sm" id="btnBulkReset">
                <iconify-icon icon="solar:lock-unlocked-bold" width="16"></iconify-icon> Buka Kunci Terpilih
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--sky ilmuna-btn--sm" id="btnBulkPrint">
                <iconify-icon icon="solar:printer-bold" width="16"></iconify-icon> Cetak Kartu Terpilih
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--danger ilmuna-btn--sm" id="btnBulkDelete">
                <iconify-icon icon="solar:trash-bin-trash-bold" width="16"></iconify-icon> Hapus Terpilih
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" id="btnBulkCancel">
                Batal
            </button>
        </div>
    </div>

</div>

<!-- ==============================================================================
 * MODAL 1: GENERATE AKSES UJIAN MASSAL
 * ============================================================================== -->
<div class="ilmuna-access-modal" id="modalGenerateMassal">
    <div class="ilmuna-access-modal__overlay" data-close="1"></div>
    <div class="ilmuna-access-modal__box" style="max-width: 580px;">
        <div class="ilmuna-access-modal__head">
            <h3>
                <iconify-icon icon="solar:magic-stick-3-bold" width="22" style="color: #D97706;"></iconify-icon>
                <span>Generate Tiket & Akses Ujian Rombel</span>
            </h3>
            <button type="button" class="ilmuna-access-modal__close" data-close="1">&times;</button>
        </div>
        <form id="formGenerateMassal" class="ilmuna-access-modal__body" onsubmit="return false;">
            <p style="margin: 0 0 16px; font-size: 13px; color: #64748B; line-height: 1.5;">
                Sistem akan secara otomatis membuatkan Nomor Peserta, Username, dan Password/PIN unik bagi seluruh siswa di rombel kelas sasaran.
            </p>

            <div class="ilmuna-form-field">
                <label>Kategori Asesmen <span class="req">*</span></label>
                <select id="genTipeAsesmen" class="ilmuna-input">
                    <option value="diagnostik">Asesmen Diagnostik (Awal KBM & Pemetaan Karakteristik)</option>
                    <option value="formatif">Asesmen Formatif (Kuis Proses Pembelajaran KBM)</option>
                    <option value="sumatif" selected>Asesmen Sumatif (Lingkup Materi / STS / SAS / CBT)</option>
                    <option value="remedial">Remedial / Pengayaan (Siswa Belum Tuntas / Peserta Pengayaan)</option>
                </select>
            </div>

            <div class="ilmuna-form-field" id="boxGenDiagnostik" style="display: none;">
                <label>Pilih Judul Asesmen Diagnostik <span class="req">*</span></label>
                <select id="genIdDiagnostik" class="ilmuna-input">
                    <?php if (empty($asesmenDiagnostikList)): ?>
                        <option value="">Belum ada master asesmen diagnostik dibuat</option>
                    <?php else: ?>
                        <?php foreach ($asesmenDiagnostikList as $ad): ?>
                            <option value="<?= $ad['id'] ?>" data-kelas="<?= $ad['id_kelas'] ?>" data-kelas-nama="<?= htmlspecialchars($ad['nama_kelas']) ?>" data-mapel="<?= $ad['id_mata_pelajaran'] ?>" data-pertemuan="<?= $ad['pertemuan_ke'] ?>">
                                #<?= $ad['id'] ?> - <?= htmlspecialchars($ad['judul_asesmen']) ?> (<?= htmlspecialchars($ad['nama_mata_pelajaran'] ?? 'Umum') ?> &bull; Ke-<?= $ad['pertemuan_ke'] ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="ilmuna-form-field" id="boxGenFormatif" style="display: none;">
                <label>Pilih Judul Asesmen Formatif <span class="req">*</span></label>
                <select id="genIdFormatif" class="ilmuna-input">
                    <?php if (empty($asesmenFormatifList)): ?>
                        <option value="">Belum ada master asesmen formatif dibuat</option>
                    <?php else: ?>
                        <?php foreach ($asesmenFormatifList as $af): ?>
                            <option value="<?= $af['id'] ?>" data-kelas="<?= $af['id_kelas'] ?>" data-kelas-nama="<?= htmlspecialchars($af['nama_kelas']) ?>" data-mapel="<?= $af['id_mata_pelajaran'] ?>" data-pertemuan="<?= $af['pertemuan_ke'] ?>">
                                #<?= $af['id'] ?> - <?= htmlspecialchars($af['judul_asesmen']) ?> (<?= htmlspecialchars($af['nama_mata_pelajaran'] ?? 'Umum') ?> &bull; Ke-<?= $af['pertemuan_ke'] ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="ilmuna-form-field" id="boxGenRemedial" style="display: none;">
                <div style="background: #F0FDFA; border: 1px solid #99F6E4; border-radius: 10px; padding: 10px 14px; margin-bottom: 10px;">
                    <label style="font-size: 11px; font-weight: 700; color: #0D7A87; margin-bottom: 4px; display: block;">Filter Kelas Program Remedial / Pengayaan</label>
                    <select id="genRemedialFilterKelas" class="ilmuna-input" style="height: 34px; font-size: 12.5px; padding: 4px 10px;">
                        <option value="all">Semua Kelas</option>
                        <?php foreach ($kelasList as $k): ?>
                            <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label>Pilih Program Remedial / Pengayaan <span class="req">*</span></label>
                <select id="genIdRemedial" class="ilmuna-input">
                    <?php if (empty($asesmenRemedialList)): ?>
                        <option value="">Belum ada program remedial/pengayaan dibuat</option>
                    <?php else: ?>
                        <?php foreach ($asesmenRemedialList as $ar): ?>
                            <option value="<?= $ar['id'] ?>" data-jenis="<?= htmlspecialchars($ar['jenis_remedial'] ?? 'Remedial') ?>" data-kelas="<?= $ar['id_kelas'] ?>" data-kelas-nama="<?= htmlspecialchars($ar['nama_kelas']) ?>" data-mapel="<?= $ar['id_mata_pelajaran'] ?>" data-pertemuan="<?= $ar['pertemuan_ke'] ?>" data-token="<?= htmlspecialchars($ar['token_sesi'] ?? '') ?>" data-layak="<?= (int)$ar['jumlah_layak'] ?>" data-kktp="<?= htmlspecialchars(rtrim(rtrim(number_format((float)($ar['kktp_minimal'] ?? 75), 2, '.', ''), '0'), '.')) ?>" data-siswa-layak="<?= htmlspecialchars($ar['daftar_siswa_layak'] ?? '') ?>">
                                #<?= $ar['id'] ?> - [<?= htmlspecialchars($ar['jenis_remedial']) ?>] <?= htmlspecialchars($ar['judul_asesmen']) ?> (<?= htmlspecialchars($ar['nama_mata_pelajaran'] ?? 'Umum') ?> &bull; <?= htmlspecialchars($ar['nama_kelas']) ?> &bull; Ke-<?= $ar['pertemuan_ke'] ?>) &bull; <?= (int)$ar['jumlah_layak'] ?> siswa layak
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                <div id="genRemedialEligibleInfo" style="margin-top: 8px; font-size: 12px; color: #B91C1C; background: #FEF2F2; padding: 8px 12px; border-radius: 8px; border: 1px solid #FECACA; line-height: 1.5;"></div>
            </div>

            <div class="ilmuna-form-field" id="boxGenSumatif">
                <div style="background: #F0FDFA; border: 1px solid #99F6E4; border-radius: 10px; padding: 12px 14px; margin-bottom: 12px;">
                    <div style="font-size: 12px; font-weight: 700; color: #0D7A87; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
                        <iconify-icon icon="solar:filter-bold" width="15"></iconify-icon>
                        <span>Pengelompokan Asesmen Sumatif (Pilih Bertahap)</span>
                    </div>
                    <div class="ilmuna-form-row" style="margin-bottom: 8px;">
                        <div class="ilmuna-form-field" style="margin-bottom: 0;">
                            <label style="font-size: 11px; font-weight: 600; color: #475569;">1. Tahun Pelajaran</label>
                            <select id="genSumatifTahun" class="ilmuna-input" style="height: 32px; font-size: 12px; padding: 4px 8px;">
                                <option value="all">Semua T.P.</option>
                                <?php foreach ($tahunPelajaranOptions as $tp): ?>
                                    <option value="<?= htmlspecialchars($tp) ?>" <?= ($tp === '2026/2027') ? 'selected' : '' ?>><?= htmlspecialchars($tp) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ilmuna-form-field" style="margin-bottom: 0;">
                            <label style="font-size: 11px; font-weight: 600; color: #475569;">2. Semester</label>
                            <select id="genSumatifSemester" class="ilmuna-input" style="height: 32px; font-size: 12px; padding: 4px 8px;">
                                <option value="all">Semua Semester</option>
                                <?php foreach ($semesterOptions as $sem): ?>
                                    <option value="<?= htmlspecialchars($sem) ?>" <?= ($sem === 'Ganjil') ? 'selected' : '' ?>>Semester <?= htmlspecialchars($sem) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="ilmuna-form-row" style="margin-bottom: 0;">
                        <div class="ilmuna-form-field" style="margin-bottom: 0;">
                            <label style="font-size: 11px; font-weight: 600; color: #475569;">3. Jenis Sumatif</label>
                            <select id="genSumatifJenis" class="ilmuna-input" style="height: 32px; font-size: 12px; padding: 4px 8px;">
                                <option value="all">Semua Jenis</option>
                                <?php foreach ($jenisSumatifOptions as $js): ?>
                                    <option value="<?= htmlspecialchars($js) ?>"><?= htmlspecialchars($js) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ilmuna-form-field" style="margin-bottom: 0;">
                            <label style="font-size: 11px; font-weight: 600; color: #475569;">4. Rombel / Kelas Sasaran</label>
                            <select id="genSumatifKelas" class="ilmuna-input" style="height: 32px; font-size: 12px; padding: 4px 8px;">
                                <option value="all">Semua Kelas</option>
                                <?php foreach ($kelasList as $k): ?>
                                    <option value="<?= $k['id'] ?>" data-tahun="<?= htmlspecialchars($k['tahun_pelajaran'] ?? '') ?>"><?= htmlspecialchars($k['nama_kelas']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="margin: 0; font-weight: 700;">
                        Pilih Asesmen Sumatif Target <span class="req">*</span>
                        <span id="genSumatifBadgeCount" class="ilmuna-badge ilmuna-badge--teal" style="font-size: 11px; margin-left: 6px;">0 Terpilih</span>
                    </label>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" id="btnSelectAllSumatif" style="padding: 2px 8px; font-size: 11px; height: 26px;">Pilih Semua</button>
                        <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" id="btnDeselectAllSumatif" style="padding: 2px 8px; font-size: 11px; height: 26px;">Batal Semua</button>
                    </div>
                </div>

                <?php if (empty($asesmenSumatifList)): ?>
                    <div style="padding: 12px; background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 8px; font-size: 12px; color: #B45309;">
                        Belum ada master asesmen sumatif dibuat.
                    </div>
                <?php else: ?>
                    <input type="text" id="genSumatifSearch" placeholder="Cari judul sumatif / kelas / mapel..." class="ilmuna-input" style="height: 32px; font-size: 12px; margin-bottom: 6px; padding: 4px 10px;">
                    <div id="genSumatifListContainer" style="max-height: 220px; overflow-y: auto; border: 1px solid #CBD5E1; border-radius: 10px; padding: 6px; background: #FFFFFF; display: flex; flex-direction: column; gap: 5px;">
                        <?php foreach ($asesmenSumatifList as $idx => $as): ?>
                            <label class="ilmuna-sumatif-item" style="display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; border-radius: 8px; border: 1px solid #E2E8F0; background: #F8FAFC; cursor: pointer; transition: all 0.15s ease;">
                                <input type="checkbox" class="cb-gen-sumatif" value="<?= $as['id'] ?>"
                                       data-id="<?= $as['id'] ?>"
                                       data-judul="<?= htmlspecialchars($as['judul_asesmen']) ?>"
                                       data-kelas="<?= $as['id_kelas'] ?>"
                                       data-kelas-nama="<?= htmlspecialchars($as['nama_kelas'] ?: 'Semua Kelas Terdaftar') ?>"
                                       data-multi-kelas="<?= htmlspecialchars($as['multi_kelas_ids'] ?? '') ?>"
                                       data-token="<?= htmlspecialchars($as['token_sesi'] ?? '') ?>"
                                       data-mapel="<?= htmlspecialchars($as['nama_mata_pelajaran'] ?: 'Semua Mapel') ?>"
                                       data-jenis="<?= htmlspecialchars($as['jenis_sumatif']) ?>"
                                       data-semester="<?= htmlspecialchars($as['semester'] ?? 'Ganjil') ?>"
                                       data-tahun="<?= htmlspecialchars($as['tahun_pelajaran'] ?? '2026/2027') ?>"
                                       style="margin-top: 3px; cursor: pointer; accent-color: #0D7A87; width: 16px; height: 16px;">
                                <div style="flex: 1; min-width: 0;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 6px;">
                                        <span style="font-weight: 700; font-size: 13px; color: #1E293B; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                            #<?= $as['id'] ?> - <?= htmlspecialchars($as['judul_asesmen']) ?>
                                        </span>
                                        <?php if (!empty($as['token_sesi'])): ?>
                                            <span class="ilmuna-badge ilmuna-badge--teal" style="font-size: 10px; font-family: monospace; font-weight: 800; flex-shrink: 0;">Token: <?= htmlspecialchars($as['token_sesi']) ?></span>
                                        <?php else: ?>
                                            <span class="ilmuna-badge ilmuna-badge--gold" style="font-size: 10px; flex-shrink: 0;">Auto-Token</span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size: 11.5px; color: #64748B; margin-top: 2px;">
                                        <?= htmlspecialchars($as['nama_kelas'] ?: 'Semua Kelas') ?> &bull; <?= htmlspecialchars($as['nama_mata_pelajaran'] ?: 'Semua Mapel') ?> &bull; <strong style="color: #0D7A87;"><?= htmlspecialchars($as['jenis_sumatif']) ?> (<?= htmlspecialchars($as['semester'] ?? 'Ganjil') ?>)</strong>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <small style="color: #64748B; font-size: 11px; margin-top: 4px; display: block;">
                    Anda dapat mencentang lebih dari 1 asesmen sumatif sekaligus. Sistem otomatis memetakan token berbeda untuk tiap asesmen.
                </small>
            </div>

            <div class="ilmuna-form-field">
                <label>Rombel / Kelas Sasaran</label>
                <div id="genAutoKelasBox" style="background: #F8FAFC; border: 1px solid #CBD5E1; border-radius: 8px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <iconify-icon icon="solar:users-group-two-rounded-bold" width="18" style="color: #0D7A87;"></iconify-icon>
                        <span id="genDisplayKelas" style="font-size: 13px; font-weight: 700; color: #1E293B;">Memuat kelas sasaran...</span>
                    </div>
                    <span class="ilmuna-badge ilmuna-badge--teal" style="font-size: 10.5px; flex-shrink: 0;">
                        <iconify-icon icon="solar:check-circle-bold" width="12"></iconify-icon> Otomatis
                    </span>
                </div>
                <input type="hidden" id="genIdKelas" value="auto">
            </div>

            <div id="genPolicyNotice" style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; padding: 10px 14px; font-size: 12px; line-height: 1.5; color: #475569; margin-bottom: 14px;">
                <span id="genPolicyText">
                    <strong>Aturan Akun Sumatif:</strong> Akun siswa berlaku menyeluruh untuk semua mapel dalam periode yang sama. Token sesi tetap otomatis unik & berbeda untuk setiap asesmen.
                </span>
            </div>

            <div class="ilmuna-form-row">
                <div class="ilmuna-form-field">
                    <label>Formula Username Ujian</label>
                    <select id="genUserOption" class="ilmuna-input">
                        <option value="nis" selected>Nomor Induk Siswa (NIS)</option>
                        <option value="custom">Format [smt/fmt/dgn]_[nama]_[3digit]</option>
                        <option value="nisn">Nomor Induk Siswa Nasional (NISN)</option>
                    </select>
                </div>
                <div class="ilmuna-form-field">
                    <label>Formula Password / PIN Ujian</label>
                    <select id="genPassOption" class="ilmuna-input">
                        <option value="pin6" selected>PIN 6 Angka Acak Unik (CBT Resmi)</option>
                        <option value="prefix_nisn">Prefix + 4 Digit Akhir NISN</option>
                        <option value="nis">Sama dengan NIS Siswa</option>
                    </select>
                </div>
            </div>

            <div class="ilmuna-form-field">
                <label id="labelGenToken">Token Sesi Ujian (Otomatis Ditentukan Sistem per Asesmen Sumatif)</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="genTokenSesi" class="ilmuna-input" placeholder="Otomatis ditentukan oleh sistem" style="text-transform: uppercase; font-family: monospace; font-weight: 700;">
                    <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" id="btnGenRandomToken" title="Generate Token Acak 6 Karakter">
                        <iconify-icon icon="solar:refresh-bold"></iconify-icon> Acak
                    </button>
                </div>
                <small style="color: #94A3B8; font-size: 11px;">Sistem otomatis menentukan token yang sama untuk semua kelas & mapel pada asesmen sumatif ini (berbeda dengan asesmen lain). <strong>Token sesi tidak akan dicetak di kartu ujian siswa.</strong></small>
            </div>
        </form>
        <div class="ilmuna-access-modal__foot">
            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnSubmitGenerate">
                <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                Generate Akses Sekarang
            </button>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 2: UPDATE TOKEN SESI MASSAL
 * ============================================================================== -->
<div class="ilmuna-access-modal" id="modalUpdateToken">
    <div class="ilmuna-access-modal__overlay" data-close="1"></div>
    <div class="ilmuna-access-modal__box" style="max-width: 500px;">
        <div class="ilmuna-access-modal__head">
            <h3>
                <iconify-icon icon="solar:shield-keyhole-bold" width="22" style="color: #0D7A87;"></iconify-icon>
                <span>Perbarui Token Sesi Massal</span>
            </h3>
            <button type="button" class="ilmuna-access-modal__close" data-close="1">&times;</button>
        </div>
        <form class="ilmuna-access-modal__body" onsubmit="return false;">
            <p style="margin: 0 0 16px; font-size: 13px; color: #64748B;">
                Ganti atau pasang token sesi dinamis baru untuk seluruh siswa peserta asesmen secara serentak.
            </p>

            <div class="ilmuna-form-field">
                <label>Kategori Asesmen</label>
                <select id="tokenTipeAsesmen" class="ilmuna-input">
                    <option value="diagnostik">Asesmen Diagnostik</option>
                    <option value="formatif">Asesmen Formatif</option>
                    <option value="sumatif" selected>Asesmen Sumatif</option>
                    <option value="remedial">Remedial / Pengayaan</option>
                </select>
            </div>

            <div class="ilmuna-form-field" id="boxTokenDiagnostik" style="display: none;">
                <label>Pilih Asesmen Diagnostik Target <span class="req">*</span></label>
                <select id="tokenIdDiagnostik" class="ilmuna-input">
                    <?php foreach ($asesmenDiagnostikList as $ad): ?>
                        <option value="<?= $ad['id'] ?>">#<?= $ad['id'] ?> - <?= htmlspecialchars($ad['judul_asesmen']) ?> (<?= htmlspecialchars($ad['nama_mata_pelajaran'] ?? 'Umum') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ilmuna-form-field" id="boxTokenFormatif" style="display: none;">
                <label>Pilih Asesmen Formatif Target <span class="req">*</span></label>
                <select id="tokenIdFormatif" class="ilmuna-input">
                    <?php foreach ($asesmenFormatifList as $af): ?>
                        <option value="<?= $af['id'] ?>">#<?= $af['id'] ?> - <?= htmlspecialchars($af['judul_asesmen']) ?> (<?= htmlspecialchars($af['nama_mata_pelajaran'] ?? 'Umum') ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ilmuna-form-field" id="boxTokenRemedial" style="display: none;">
                <label>Pilih Program Remedial Target <span class="req">*</span></label>
                <select id="tokenIdRemedial" class="ilmuna-input">
                    <?php foreach ($asesmenRemedialList as $ar): ?>
                        <option value="<?= $ar['id'] ?>">#<?= $ar['id'] ?> - [<?= htmlspecialchars($ar['jenis_remedial']) ?>] <?= htmlspecialchars($ar['judul_asesmen']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ilmuna-form-field" id="boxTokenSumatif">
                <label>Pilih Asesmen Sumatif Target <span class="req">*</span></label>
                <select id="tokenIdSumatif" class="ilmuna-input">
                    <?php foreach ($asesmenSumatifList as $as): ?>
                        <option value="<?= $as['id'] ?>" data-token="<?= htmlspecialchars($as['token_sesi'] ?? '') ?>" data-kelas="<?= $as['id_kelas'] ?>" data-multi-kelas="<?= htmlspecialchars($as['multi_kelas_ids'] ?? '') ?>">
                            #<?= $as['id'] ?> - <?= htmlspecialchars($as['judul_asesmen']) ?> (<?= htmlspecialchars($as['nama_kelas'] ?: 'Semua Kelas') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ilmuna-form-field" id="boxTokenSumatifSync" style="margin-bottom: 14px;">
                <div style="font-size: 12px; color: #0D7A87; background: #F0FDFA; padding: 10px 12px; border-radius: 8px; border: 1px solid #99F6E4; line-height: 1.5;">
                    <strong>Aturan Token Sumatif:</strong> Token ditentukan per 1 asesmen sumatif dan akan diterapkan serentak ke seluruh rombel kelas & siswa peserta asesmen sumatif ini.
                </div>
            </div>

            <div class="ilmuna-form-field">
                <label>Token Sesi Baru</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="tokenInputBaru" class="ilmuna-input" placeholder="Ketik token atau klik acak (kosongkan jika tanpa token)" style="text-transform: uppercase; font-family: monospace; font-weight: 700;">
                    <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" id="btnAcakTokenMassal">
                        <iconify-icon icon="solar:refresh-bold"></iconify-icon> Acak
                    </button>
                </div>
            </div>
        </form>
        <div class="ilmuna-access-modal__foot" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" id="btnDownloadTokenFromModal" style="color: #0D7A87; border-color: #99F6E4; background: #F0FDFA;">
                <iconify-icon icon="solar:download-square-bold" width="16"></iconify-icon>
                <span>Download PDF Token Asesmen Ini</span>
            </button>
            <div style="display: flex; gap: 8px;">
                <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" data-close="1">Batal</button>
                <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="btnSaveUpdateToken">
                    <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                    Simpan & Terapkan Token
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL 3: CETAK KARTU PESERTA UJIAN
 * ============================================================================== -->
<div class="ilmuna-access-modal" id="modalPrintCards">
    <div class="ilmuna-access-modal__overlay" data-close="1"></div>
    <div class="ilmuna-access-modal__box" style="max-width: 880px;">
        <div class="ilmuna-access-modal__head">
            <h3>
                <iconify-icon icon="solar:printer-bold" width="22" style="color: #0284C7;"></iconify-icon>
                <span>Pratinjau Kartu Peserta Ujian Siswa</span>
            </h3>
            <button type="button" class="ilmuna-access-modal__close" data-close="1">&times;</button>
        </div>
        <div class="ilmuna-access-modal__body" style="background: #F8FAFC; max-height: 70vh;">
            <div style="background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 12px; padding: 12px 16px; margin-bottom: 16px; font-size: 12.5px; color: #1E40AF; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                <div>
                    <strong>Petunjuk Cetak:</strong> Kartu ditata 2 kolom per halaman A4. Token Sesi tidak dicantumkan pada kartu.
                </div>
                <button type="button" class="ilmuna-btn ilmuna-btn--sky" id="btnExecPrint" style="flex-shrink: 0;">
                    <iconify-icon icon="solar:printer-bold" width="16"></iconify-icon> Cetak Sekarang (Print / PDF)
                </button>
            </div>
            <div id="printPreviewWrap" class="ilmuna-cards-grid"></div>
        </div>
        <div class="ilmuna-access-modal__foot">
            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" data-close="1">Tutup</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--sky" id="btnExecPrintFoot">
                <iconify-icon icon="solar:printer-bold" width="16"></iconify-icon> Cetak Sekarang
            </button>
        </div>
    </div>
</div>

<!-- CONFIRM OVERLAY DIALOG & TOAST CONTAINER -->
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

<div class="ilmuna-toast-wrap" id="toastWrap"></div>
<div class="ilmuna-print-container" id="printContainer">
    <div class="ilmuna-cards-grid" id="printCardsList"></div>
</div>

<!-- MODAL 4: PENGATURAN KEAMANAN EXAM BROWSER -->
<div class="ilmuna-access-modal" id="modalExambroSettings">
    <div class="ilmuna-access-modal__overlay" data-close="1"></div>
    <div class="ilmuna-access-modal__box" style="max-width: 640px;">
        <div class="ilmuna-access-modal__head">
            <h3>
                <iconify-icon icon="solar:shield-warning-bold" width="22" style="color: #0284C7;"></iconify-icon>
                <span>Kebijakan Keamanan Ilmuna Exam Browser</span>
            </h3>
            <button type="button" class="ilmuna-access-modal__close" data-close="1">&times;</button>
        </div>
        <form id="formExambroSettings" class="ilmuna-access-modal__body" onsubmit="return false;">
            <p style="margin: 0 0 16px; font-size: 13px; color: #64748B; line-height: 1.5;">
                Konfigurasikan aturan ketat pengawasan Kiosk untuk aplikasi Android <strong>Ilmuna Exam Browser</strong>.
            </p>

            <div style="display: flex; flex-direction: column; gap: 14px;">
                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; padding: 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px;">
                    <input type="checkbox" id="setWajibExambro" value="1" style="width: 18px; height: 18px; margin-top: 2px;">
                    <div>
                        <strong style="color: #1D3B5E; font-size: 13.5px;">Wajib Menggunakan Exam Browser Resmi</strong>
                        <div style="font-size: 12px; color: #64748B;">Hanya izinkan pengerjaan dari aplikasi Android resmi Ilmuna Exam Browser.</div>
                    </div>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; padding: 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px;">
                    <input type="checkbox" id="setBlokirScreenshot" value="1" style="width: 18px; height: 18px; margin-top: 2px;">
                    <div>
                        <strong style="color: #1D3B5E; font-size: 13.5px;">Anti-Screenshot & Screen Recording (FLAG_SECURE)</strong>
                        <div style="font-size: 12px; color: #64748B;">Blokir tangkapan layar dan perekam video layar.</div>
                    </div>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; padding: 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px;">
                    <input type="checkbox" id="setBlokirCopyPaste" value="1" style="width: 18px; height: 18px; margin-top: 2px;">
                    <div>
                        <strong style="color: #1D3B5E; font-size: 13.5px;">Anti Copy-Paste & Seleksi Teks</strong>
                        <div style="font-size: 12px; color: #64748B;">Matikan menu seleksi teks Android dan blokir klik kanan.</div>
                    </div>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; padding: 12px; background: #FEF3C7; border: 1px solid #FDE68A; border-radius: 10px;">
                    <input type="checkbox" id="setAutoFreeze" value="1" style="width: 18px; height: 18px; margin-top: 2px;">
                    <div>
                        <strong style="color: #92400E; font-size: 13.5px;">Bekukan Waktu (Auto Freeze) Saat Siswa Keluar Aplikasi</strong>
                        <div style="font-size: 12px; color: #B45309;">Sisa waktu detik dibekukan & jawaban tersimpan aman.</div>
                    </div>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; padding: 12px; background: #FEE2E2; border: 1px solid #FECACA; border-radius: 10px;">
                    <input type="checkbox" id="setAutoLogout" value="1" style="width: 18px; height: 18px; margin-top: 2px;">
                    <div>
                        <strong style="color: #991B1B; font-size: 13.5px;">Otomatis Logout Akun Saat Keluar</strong>
                        <div style="font-size: 12px; color: #B91C1C;">Sesi cookies di HP dihapus otomatis.</div>
                    </div>
                </label>

                <label style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; padding: 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px;">
                    <input type="checkbox" id="setButuhBukaManual" value="1" style="width: 18px; height: 18px; margin-top: 2px;">
                    <div>
                        <strong style="color: #1D3B5E; font-size: 13.5px;">Wajib Dibuka Manual oleh Admin/Proktor</strong>
                        <div style="font-size: 12px; color: #64748B;">Siswa ter-freeze tidak bisa login sebelum proktor unfreeze.</div>
                    </div>
                </label>

                <div class="ilmuna-form-row">
                    <div class="ilmuna-form-field">
                        <label>Batas Toleransi Pelanggaran</label>
                        <input type="number" id="setMaksToleransi" class="ilmuna-input" min="0" max="10" placeholder="Contoh: 3">
                        <small style="color:#64748B;">0 = Tanpa toleransi, langsung terkunci.</small>
                    </div>
                    <div class="ilmuna-form-field">
                        <label>PIN Pengawas Kiosk</label>
                        <input type="password" id="setPinPengawas" class="ilmuna-input" maxlength="10" placeholder="Default: 123456">
                        <small style="color:#64748B;">Untuk menutup mode Kiosk di HP siswa.</small>
                    </div>
                </div>
            </div>
        </form>
        <div class="ilmuna-access-modal__foot">
            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--sky" id="btnSaveExambroSettings">
                <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon> Simpan Kebijakan
            </button>
        </div>
    </div>
</div>

<!-- MODAL 5: AUDIT TRAIL LOG PELANGGARAN -->
<div class="ilmuna-access-modal" id="modalViolationLogs">
    <div class="ilmuna-access-modal__overlay" data-close="1"></div>
    <div class="ilmuna-access-modal__box" style="max-width: 780px;">
        <div class="ilmuna-access-modal__head">
            <h3>
                <iconify-icon icon="solar:history-bold" width="22" style="color: #EF4444;"></iconify-icon>
                <span>Riwayat Audit Pelanggaran Exam Browser</span>
            </h3>
            <button type="button" class="ilmuna-access-modal__close" data-close="1">&times;</button>
        </div>
        <div class="ilmuna-access-modal__body">
            <div id="violationStudentHeader" style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;">
                <div style="font-size: 15px; font-weight: 800; color: #1D3B5E;" id="violStudentName">-</div>
                <div style="font-size: 12px; color: #64748B; margin-top: 2px;" id="violStudentSub">-</div>
            </div>

            <div style="max-height: 50vh; overflow-y: auto;">
                <table class="ilmuna-table" style="font-size: 12.5px;">
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;">Ke</th>
                            <th style="width: 140px;">Waktu</th>
                            <th>Jenis Pelanggaran</th>
                            <th>Keterangan</th>
                            <th style="width: 110px; text-align: center;">Sisa Waktu</th>
                            <th style="width: 120px;">IP / Info</th>
                        </tr>
                    </thead>
                    <tbody id="violationLogsBody">
                        <tr><td colspan="6" style="text-align: center; padding: 24px; color: #64748B;">Memuat riwayat...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="ilmuna-access-modal__foot">
            <button type="button" class="ilmuna-btn ilmuna-btn--light-outline" data-close="1">Tutup</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnViolUnfreezeNow">
                <iconify-icon icon="solar:lock-unlocked-bold" width="16"></iconify-icon> Buka Kunci Siswa Ini Sekarang
            </button>
        </div>
    </div>
</div>

<!-- JAVASCRIPT APPLICATION ENGINE (PURE REACTIVE SPA) -->
<script>
(function() {
    'use strict';

    var app = document.getElementById('appAccess');
    if (!app) return;

    var AJAX_URL = app.dataset.ajax;

    ['modalGenerateMassal', 'modalUpdateToken', 'modalPrintCards', 'modalExambroSettings', 'modalViolationLogs', 'confirmModal', 'toastWrap', 'bulkActionBar'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentNode && el.parentNode !== document.body) {
            document.body.appendChild(el);
        }
    });

    // Dropdown menu navigasi (updated class)
    var btnToggleNavMenu = document.getElementById('btnToggleNavMenu');
    var navQuickDropdown = document.getElementById('navQuickDropdown');
    if (btnToggleNavMenu && navQuickDropdown) {
        btnToggleNavMenu.addEventListener('click', function(e) {
            e.stopPropagation();
            navQuickDropdown.classList.toggle('is-open');
        });
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#navQuickDropdown') && !e.target.closest('#btnToggleNavMenu')) {
                navQuickDropdown.classList.remove('is-open');
            }
        });
        navQuickDropdown.querySelectorAll('.ilmuna-nav-drop-item').forEach(function(item) {
            item.addEventListener('click', function() {
                navQuickDropdown.classList.remove('is-open');
            });
        });
    }

    var MASTER_DIAGNOSTIK = <?= json_encode($asesmenDiagnostikList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || [];
    var MASTER_FORMATIF   = <?= json_encode($asesmenFormatifList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || [];
    var MASTER_REMEDIAL   = <?= json_encode($asesmenRemedialList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || [];
    var MASTER_SUMATIF    = <?= json_encode($asesmenSumatifList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || [];

    var state = {
        tipe: 'diagnostik', status: 'all', kelas_id: 0, mapel_id: 0, pertemuan_ke: 0,
        jenis_sumatif: '', tahun_pelajaran: '', semester: '', asesmen_id: 0, search: '', page: 1, per_page: 20,
        total: 0, pages: 1, lastHash: '', isLiveSyncActive: true, isFetching: false
    };

    function isSumatifFilterComplete() {
        if (state.tipe !== 'sumatif') return true;
        return Boolean(state.tahun_pelajaran && state.tahun_pelajaran !== 'all' && state.tahun_pelajaran !== '' &&
                       state.semester && state.semester !== 'all' && state.semester !== '' &&
                       state.jenis_sumatif && state.jenis_sumatif !== 'all' && state.jenis_sumatif !== '');
    }

    var currentLoadedRows = [];
    var selectedRows = new Set();
    var searchTimer = null;
    var liveSyncTimer = null;
    var activeAbortCtrl = null;

    function toast(type, msg) {
        var normType = (type === 'danger' || type === 'err') ? 'error' : (type || 'info');
        var titleMap = { success: 'Berhasil', error: 'Gagal', warning: 'Peringatan', info: 'Informasi' };
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, titleMap[normType] || 'Pemberitahuan', msg, 4000);
        }
        var wrap = document.getElementById('toastWrap');
        if (!wrap) return;
        var el = document.createElement('div');
        el.className = 'ilmuna-toast ilmuna-toast--' + type;
        var icon = (type === 'success') ? 'solar:check-circle-bold' : ((type === 'error') ? 'solar:danger-circle-bold' : 'solar:info-circle-bold');
        el.innerHTML = '<iconify-icon icon="' + icon + '" class="ilmuna-toast__icon" width="22"></iconify-icon>' +
                       '<div class="ilmuna-toast__msg">' + escapeHtml(msg) + '</div>' +
                       '<button type="button" class="ilmuna-toast__close">&times;</button>';
        wrap.appendChild(el);
        requestAnimationFrame(function(){ el.classList.add('show'); });
        var t = setTimeout(function(){ closeToast(el); }, 4000);
        el.querySelector('.ilmuna-toast__close').addEventListener('click', function(){
            clearTimeout(t); closeToast(el);
        });
    }
    function closeToast(el) {
        el.classList.remove('show');
        setTimeout(function(){ el.remove(); }, 300);
    }

    function confirmDialog(msg, opts) {
        opts = opts || {};
        var type = (opts.type === 'danger' || opts.type === 'delete') ? 'error' : (opts.type || 'warning');
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            return Ilmuna.confirm(type, opts.title || 'Konfirmasi Tindakan', msg, opts.okText || 'Ya, Lanjutkan', opts.cancelText || 'Batal');
        }
        return new Promise(function(resolve) {
            var titleEl = document.getElementById('confirmTitle');
            var msgEl   = document.getElementById('confirmMsg');
            var okBtn   = document.getElementById('btnConfirmOk');
            var ccBtn   = document.getElementById('btnConfirmCancel');
            var iconWrap = document.getElementById('confirmIconWrap');
            titleEl.textContent = opts.title || 'Konfirmasi Tindakan';
            msgEl.textContent   = msg;
            okBtn.textContent   = opts.okText || 'Ya, Lanjutkan';
            if (opts.type === 'danger') {
                iconWrap.style.background = '#FEE2E2'; iconWrap.style.color = '#EF4444';
                okBtn.className = 'ilmuna-btn ilmuna-btn--danger';
            } else {
                iconWrap.style.background = '#FEF3C7'; iconWrap.style.color = '#D97706';
                okBtn.className = 'ilmuna-btn ilmuna-btn--gold';
            }
            openModal('confirmModal');
            function cleanup(result) {
                closeModal('confirmModal');
                okBtn.removeEventListener('click', onOk);
                ccBtn.removeEventListener('click', onCc);
                resolve(result);
            }
            function onOk() { cleanup(true); }
            function onCc() { cleanup(false); }
            okBtn.addEventListener('click', onOk);
            ccBtn.addEventListener('click', onCc);
        });
    }

    function openModal(id) {
        var m = document.getElementById(id);
        if (m) { m.classList.add('is-open'); document.body.classList.add('ilmuna-modal-open'); }
    }
    function closeModal(id) {
        var m = document.getElementById(id);
        if (m) {
            m.classList.remove('is-open');
            if (!document.querySelector('.ilmuna-access-modal.is-open')) document.body.classList.remove('ilmuna-modal-open');
        }
    }
    window.openAccessModal = openModal;
    window.closeAccessModal = closeModal;

    document.addEventListener('click', function(e) {
        var closeTrigger = e.target.closest('[data-close="1"]');
        if (closeTrigger) {
            var modal = closeTrigger.closest('.ilmuna-access-modal');
            if (modal) closeModal(modal.id);
        }
    });

    function formatSec(sec) {
        sec = parseInt(sec, 10);
        if (isNaN(sec) || sec <= 0) return '0s';
        var m = Math.floor(sec / 60);
        var s = sec % 60;
        return m > 0 ? (m + 'm ' + s + 's') : (s + 's');
    }

    function renderSkeletonRows() {
        var tbody = document.getElementById('tableAccessBody');
        if (!tbody) return;
        var html = '';
        for (var i = 0; i < 5; i++) {
            html += '<tr class="ilmuna-skeleton-row">' +
                '<td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 16px; height: 16px;"></div></td>' +
                '<td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 22px;"></div></td>' +
                '<td><div class="ilmuna-skeleton-box" style="width: 110px;"></div></td>' +
                '<td><div style="display: flex; align-items: center; gap: 10px;"><div class="ilmuna-skeleton-circle"></div><div><div class="ilmuna-skeleton-box" style="width: 130px; margin-bottom: 4px;"></div><br><div class="ilmuna-skeleton-box" style="width: 80px; height: 10px;"></div></div></div></td>' +
                '<td><div class="ilmuna-skeleton-box" style="width: 120px; margin-bottom: 4px;"></div><br><div class="ilmuna-skeleton-box" style="width: 70px; height: 10px;"></div></td>' +
                '<td><div class="ilmuna-skeleton-box" style="width: 85px;"></div></td>' +
                '<td><div class="ilmuna-skeleton-box" style="width: 75px;"></div></td>' +
                '<td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 65px;"></div></td>' +
                '<td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 85px;"></div></td>' +
                '<td style="text-align: center;"><div class="ilmuna-skeleton-box" style="width: 90px;"></div></td>' +
            '</tr>';
        }
        tbody.innerHTML = html;
    }

    var colors = ['navy', 'teal', 'gold', 'sky'];
    function buildRowContent(r, rowNum, uniqueRowId, isChecked) {
        var avColor = colors[(r.id_siswa || rowNum) % colors.length];
        var initial = r.nama_siswa ? r.nama_siswa.trim().charAt(0).toUpperCase() : 'S';
        var isLockedOrFrozen = (r.status_akses === 'Terkunci' || r.is_frozen == 1 || r.is_locked_admin == 1);

        var avatarHtml = '';
        if (r.foto) {
            avatarHtml = '<div class="ilmuna-avatar"><img src="../../' + escapeHtml(r.foto) + '" alt="' + escapeHtml(r.nama_siswa) + '" loading="lazy" onerror="this.onerror=null; this.parentElement.innerHTML=\'' + escapeHtml(initial) + '\';"></div>';
        } else {
            avatarHtml = '<div class="ilmuna-avatar ilmuna-avatar--' + avColor + '">' + escapeHtml(initial) + '</div>';
        }

        var asmBadge = '';
        if (r.tipe_kategori === 'diagnostik') {
            asmBadge = '<span class="ilmuna-badge ilmuna-badge--emerald"><iconify-icon icon="solar:clipboard-check-bold" width="11"></iconify-icon> DIAGNOSTIK KE-' + (r.pertemuan_ke || 1) + '</span>';
        } else if (r.tipe_kategori === 'formatif') {
            asmBadge = '<span class="ilmuna-badge ilmuna-badge--sky"><iconify-icon icon="solar:pen-new-square-bold" width="11"></iconify-icon> FORMATIF KE-' + (r.pertemuan_ke || 1) + '</span>';
        } else if (r.tipe_kategori === 'remedial') {
            var isPgy = (r.jenis_sumatif === 'Pengayaan');
            asmBadge = '<span class="ilmuna-badge" style="background:' + (isPgy ? '#D1FAE5' : '#FEE2E2') + '; color:' + (isPgy ? '#047857' : '#B91C1C') + ';"><iconify-icon icon="solar:medal-ribbon-star-bold" width="11"></iconify-icon> ' + (isPgy ? 'PENGAYAAN' : 'REMEDIAL') + ' KE-' + (r.pertemuan_ke || 1) + '</span>';
        } else {
            var jCode = (r.jenis_sumatif === 'Sumatif Tengah Semester') ? 'STS' : ((r.jenis_sumatif === 'Sumatif Akhir Semester') ? 'SAS' : 'SLM');
            var sCode = (r.semester === 'Genap') ? 'Genap' : 'Ganjil';
            asmBadge = '<span class="ilmuna-badge ilmuna-badge--teal" title="' + escapeHtml(r.jenis_sumatif || 'Sumatif') + ' ' + sCode + '"><iconify-icon icon="solar:laptop-minimalistic-bold" width="11"></iconify-icon> ' + escapeHtml(jCode + ' ' + sCode) + '</span>';
        }

        var statusBadge = '';
        if (isLockedOrFrozen) {
            statusBadge = '<span class="ilmuna-badge ilmuna-badge--rose" title="Sesi Ujian Dibekukan"><iconify-icon icon="solar:lock-bold"></iconify-icon> Terkunci (Freeze)</span>';
            if (r.total_pelanggaran && parseInt(r.total_pelanggaran, 10) > 0) {
                statusBadge += '<div class="btn-show-violations" data-siswa="' + r.id_siswa + '" data-asesmen="' + r.id_asesmen + '" data-tipe="' + r.tipe_kategori + '" data-name="' + escapeHtml(r.nama_siswa) + '" style="font-size: 11px; color: #DC2626; font-weight: 700; margin-top: 3px; cursor: pointer; display: flex; align-items: center; gap: 4px; justify-content: center;" title="Klik untuk lihat riwayat insiden"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> Keluar ' + r.total_pelanggaran + 'x</div>';
            }
            if (r.sisa_waktu_detik && parseInt(r.sisa_waktu_detik, 10) > 0) {
                var secFrozen = parseInt(r.sisa_waktu_detik, 10);
                var targetEndFrozenMs = Date.now() + (secFrozen * 1000);
                statusBadge += '<div class="ilmuna-live-timer-container is-frozen is-running" data-sec="' + secFrozen + '" data-target-end="' + targetEndFrozenMs + '" data-active="1" style="font-size: 10.5px; color: #B45309; margin-top: 2px; font-weight: 600;">⏳ Sisa: <span class="timer-display">' + formatSec(secFrozen) + '</span></div>';
            }
        } else if (r.status_akses === 'Sedang Mengerjakan') {
            statusBadge = '<span class="ilmuna-badge ilmuna-badge--gold"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> Mengerjakan</span>';
            if (r.sisa_waktu_detik && parseInt(r.sisa_waktu_detik, 10) > 0) {
                var secNum = parseInt(r.sisa_waktu_detik, 10);
                var targetEndMs = Date.now() + (secNum * 1000);
                statusBadge += '<div class="ilmuna-live-timer-container is-running" data-target-end="' + targetEndMs + '" data-active="1" style="font-size: 10.5px; color: #D97706; margin-top: 2px; font-weight: 600;">⏳ Sisa: <span class="timer-display">' + formatSec(secNum) + '</span></div>';
            }
        } else if (r.status_akses === 'Selesai') {
            statusBadge = '<span class="ilmuna-badge ilmuna-badge--emerald"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Selesai</span>';
        } else if (r.status_akses === 'Izin Keluar') {
            statusBadge = '<span class="ilmuna-badge ilmuna-badge--purple"><iconify-icon icon="solar:logout-2-bold"></iconify-icon> Izin Keluar</span>';
        } else {
            statusBadge = '<span class="ilmuna-badge ilmuna-badge--gray"><iconify-icon icon="solar:play-circle-bold"></iconify-icon> Belum Mulai</span>';
        }

        var tokenHtml = '';
        if (r.token_sesi) {
            var tokenNote = (r.tipe_kategori === 'sumatif') ? '<span style="font-size: 10px; color: #059669; font-weight: 700; background: #ECFDF5; padding: 2px 6px; border-radius: 4px; border: 1px solid #A7F3D0; white-space: nowrap; margin-top: 3px;">Per Asesmen Ini</span>' : '<span style="font-size: 10px; color: #0D7A87; font-weight: 700; background: #F0FDFA; padding: 2px 6px; border-radius: 4px; border: 1px solid #99F6E4; white-space: nowrap; margin-top: 3px;">Khusus Mapel Ini</span>';
            tokenHtml = '<div style="display: flex; flex-direction: column; align-items: center;"><span class="ilmuna-badge ilmuna-badge--emerald" style="font-family: monospace; font-size: 12px; font-weight: 800; padding: 3px 8px;">' + escapeHtml(r.token_sesi) + '</span>' + tokenNote + '</div>';
        } else {
            tokenHtml = '<span style="color: #94A3B8; font-size: 11.5px; font-style: italic;">(Bebas Token)</span>';
        }

        var actionBtns = '';
        if (isLockedOrFrozen) {
            actionBtns += '<button type="button" class="ilmuna-btn-icon btn-unfreeze-row" data-tipe="' + r.tipe_kategori + '" data-id="' + r.id + '" data-name="' + escapeHtml(r.nama_siswa) + '" title="Buka Kunci (Unfreeze)" style="background: #FEF3C7; color: #B45309; border: 1px solid #F59E0B; width: 28px; height: 28px;"><iconify-icon icon="solar:lock-unlocked-bold" width="16"></iconify-icon></button>';
        }
        if (r.total_pelanggaran && parseInt(r.total_pelanggaran, 10) > 0) {
            actionBtns += '<button type="button" class="ilmuna-btn-icon btn-show-violations" data-siswa="' + r.id_siswa + '" data-asesmen="' + r.id_asesmen + '" data-tipe="' + r.tipe_kategori + '" data-name="' + escapeHtml(r.nama_siswa) + '" title="Riwayat Pelanggaran" style="background: #FEE2E2; color: #DC2626; border: 1px solid #FCA5A5; width: 28px; height: 28px;"><iconify-icon icon="solar:history-bold" width="15"></iconify-icon></button>';
        }
        actionBtns += '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--reset btn-reset-row" data-tipe="' + r.tipe_kategori + '" data-id="' + r.id + '" data-name="' + escapeHtml(r.nama_siswa) + '" title="Reset Status"><iconify-icon icon="solar:restart-bold" width="15"></iconify-icon></button>';
        actionBtns += '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--print btn-print-single" data-row="' + uniqueRowId + '" title="Cetak Kartu"><iconify-icon icon="solar:printer-bold" width="16"></iconify-icon></button>';
        if (r.tipe_kategori !== 'sumatif') {
            actionBtns += '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--mail btn-send-card-email" data-tipe="' + r.tipe_kategori + '" data-id="' + r.id + '" data-name="' + escapeHtml(r.nama_siswa) + '" data-email="' + escapeHtml(r.gmail || '') + '" title="Kirim Kartu ke Gmail"><iconify-icon icon="solar:letter-bold" width="15"></iconify-icon></button>';
        }
        actionBtns += '<button type="button" class="ilmuna-btn-icon ilmuna-btn-icon--del btn-delete-row" data-tipe="' + r.tipe_kategori + '" data-id="' + r.id + '" data-name="' + escapeHtml(r.nama_siswa) + '" title="Hapus Tiket"><iconify-icon icon="solar:trash-bin-trash-bold" width="16"></iconify-icon></button>';

        return '<td style="text-align: center;"><input type="checkbox" class="access-cb" value="' + uniqueRowId + '" ' + (isChecked ? 'checked' : '') + ' style="cursor: pointer; width: 16px; height: 16px;"></td>' +
            '<td style="text-align: center; color: #94A3B8; font-weight: 700; font-size: 12px;" class="row-index-num">' + rowNum + '</td>' +
            '<td><span class="ilmuna-code-pill ilmuna-code-pill--gold">' + escapeHtml(r.nomor_peserta) + '</span></td>' +
            '<td><div style="display: flex; align-items: center; gap: 12px;">' + avatarHtml + '<div class="ilmuna-user-meta"><strong>' + escapeHtml(r.nama_siswa) + '</strong><div style="font-size: 11.5px; color: #64748B;"><span>NIS: ' + escapeHtml(r.nis || '-') + '</span> &bull; <span class="ilmuna-badge ilmuna-badge--teal" style="padding: 1px 6px; font-size: 10px;">' + escapeHtml(r.nama_kelas) + '</span></div></div></div></td>' +
            '<td><div style="display: flex; align-items: center; gap: 6px; margin-bottom: 2px;">' + asmBadge + '<span style="font-size: 11px; color: #64748B;">(' + (r.durasi_menit || 60) + ' Menit)</span></div><div style="font-weight: 700; color: #1E293B; font-size: 13px; max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="' + escapeHtml(r.judul_asesmen) + '">' + escapeHtml(r.judul_asesmen) + '</div><div style="font-size: 11.5px; color: #475569; margin-top: 2px;"><iconify-icon icon="solar:book-bookmark-bold" width="12" style="color:#0D7A87; vertical-align:-1px;"></iconify-icon> Mapel: <strong>' + escapeHtml(r.nama_mata_pelajaran || 'Umum') + '</strong></div></td>' +
            '<td><div style="display: flex; align-items: center; gap: 6px;"><span class="ilmuna-code-pill">@' + escapeHtml(r.username_ujian) + '</span><button type="button" class="ilmuna-btn-icon btn-copy" data-text="' + escapeHtml(r.username_ujian) + '" title="Salin" style="width: 26px; height: 26px; flex-shrink: 0;"><iconify-icon icon="solar:copy-bold" width="14"></iconify-icon></button></div></td>' +
            '<td><div style="display: flex; align-items: center; gap: 6px;"><span class="ilmuna-code-pill ilmuna-code-pill--gold">' + escapeHtml(r.password_ujian) + '</span><button type="button" class="ilmuna-btn-icon btn-copy" data-text="' + escapeHtml(r.password_ujian) + '" title="Salin" style="width: 26px; height: 26px; flex-shrink: 0;"><iconify-icon icon="solar:copy-bold" width="14"></iconify-icon></button></div></td>' +
            '<td style="text-align: center;">' + tokenHtml + '</td>' +
            '<td style="text-align: center;">' + statusBadge + '</td>' +
            '<td style="text-align: center;"><div style="display: flex; gap: 4px; justify-content: center; align-items: center;">' + actionBtns + '</div></td>';
    }

    function patchTableRows(rows, page, perPage) {
        var tbody = document.getElementById('tableAccessBody');
        if (!tbody) return;

        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr class="ilmuna-empty-row"><td colspan="10" style="text-align: center; padding: 48px 20px; color: #64748B;"><iconify-icon icon="solar:shield-cross-bold" width="46" style="color:#CBD5E1; margin-bottom: 8px; display:inline-block;"></iconify-icon><h4 style="margin: 0 0 4px; color:#1D3B5E; font-size:15px;">Tidak Ditemukan Tiket Ujian</h4><p style="margin:0; font-size:12.5px;">Coba ubah filter atau generate tiket akses ujian baru.</p></td></tr>';
            var checkAll = document.getElementById('checkAllRows');
            if (checkAll) checkAll.checked = false;
            return;
        }

        var placeholders = tbody.querySelectorAll('.ilmuna-empty-row, .ilmuna-skeleton-row, tr:not([data-row-id])');
        if (placeholders.length > 0) placeholders.forEach(function(el) { el.remove(); });

        var existingMap = new Map();
        tbody.querySelectorAll('tr[data-row-id]').forEach(function(tr) { existingMap.set(tr.dataset.rowId, tr); });

        var newKeySet = new Set();
        var fragment = document.createDocumentFragment();

        rows.forEach(function(r, idx) {
            var rowNum = (page - 1) * perPage + idx + 1;
            var uniqueRowId = r.tipe_kategori + '_' + r.id;
            newKeySet.add(uniqueRowId);
            var isChecked = selectedRows.has(uniqueRowId);
            var rowSig = [r.status_akses, r.is_frozen, r.is_locked_admin, r.total_pelanggaran, r.token_sesi, r.password_ujian, r.username_ujian, r.judul_asesmen, r.nama_kelas, r.nama_mata_pelajaran].join('|');

            var tr = existingMap.get(uniqueRowId);
            if (tr) {
                if (tr.dataset.sig !== rowSig) {
                    tr.innerHTML = buildRowContent(r, rowNum, uniqueRowId, isChecked);
                    tr.dataset.sig = rowSig;
                    tr.classList.add('row-pulse-update');
                    setTimeout(function(){ tr.classList.remove('row-pulse-update'); }, 1800);
                } else {
                    var timerEl = tr.querySelector('.ilmuna-live-timer-container');
                    if (timerEl) {
                        var serverSec = parseInt(r.sisa_waktu_detik, 10) || 0;
                        var isActive = ((r.status_akses === 'Sedang Mengerjakan' || r.status_akses === 'Terkunci' || r.is_frozen == 1 || r.is_locked_admin == 1) && serverSec > 0) ? '1' : '0';
                        timerEl.setAttribute('data-active', isActive);
                        if (isActive === '1') {
                            var curTarget = parseInt(timerEl.getAttribute('data-target-end'), 10) || 0;
                            var clientSec = curTarget ? Math.max(0, Math.ceil((curTarget - Date.now()) / 1000)) : 0;
                            if (Math.abs(clientSec - serverSec) >= 2 || serverSec <= 0) {
                                timerEl.setAttribute('data-target-end', Date.now() + (serverSec * 1000));
                                var disp = timerEl.querySelector('.timer-display');
                                if (disp) disp.textContent = formatSec(serverSec);
                            }
                        }
                    }
                    var numEl = tr.querySelector('.row-index-num');
                    if (numEl && numEl.textContent != rowNum) numEl.textContent = rowNum;
                    var cb = tr.querySelector('.access-cb');
                    if (cb && cb.checked !== isChecked) {
                        cb.checked = isChecked;
                        tr.classList.toggle('is-selected', isChecked);
                    }
                }
            } else {
                tr = document.createElement('tr');
                tr.dataset.rowId = uniqueRowId;
                tr.dataset.sig = rowSig;
                tr.className = (isChecked ? 'is-selected ' : '') + 'row-pulse-new';
                tr.innerHTML = buildRowContent(r, rowNum, uniqueRowId, isChecked);
                setTimeout(function(){ tr.classList.remove('row-pulse-new'); }, 2000);
            }
            fragment.appendChild(tr);
        });

        existingMap.forEach(function(oldTr, k) { if (!newKeySet.has(k)) oldTr.remove(); });
        tbody.appendChild(fragment);

        var checkAll = document.getElementById('checkAllRows');
        if (checkAll) {
            var allCbs = tbody.querySelectorAll('.access-cb');
            checkAll.checked = allCbs.length > 0 && Array.from(allCbs).every(function(cb){ return cb.checked; });
        }
    }

    function fetchList(opts) {
        opts = opts || {};
        var isBg = !!opts.isBackground;
        var tbody = document.getElementById('tableAccessBody');

        if (state.tipe === 'sumatif' && !isSumatifFilterComplete()) {
            state.isFetching = false;
            var missing = [];
            if (!state.tahun_pelajaran || state.tahun_pelajaran === 'all') missing.push('Tahun Pelajaran');
            if (!state.semester || state.semester === 'all') missing.push('Semester');
            if (!state.jenis_sumatif || state.jenis_sumatif === 'all') missing.push('Jenis Sumatif');

            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="10" style="text-align: center; padding: 56px 20px; color: #64748B;">' +
                    '<div style="display: inline-flex; align-items: center; justify-content: center; width: 60px; height: 60px; border-radius: 50%; background: #F0FDFA; color: #0D7A87; margin-bottom: 14px; border: 1.5px solid #99F6E4; box-shadow: 0 4px 12px rgba(13,122,135,0.08);">' +
                        '<iconify-icon icon="solar:filter-bold" width="30"></iconify-icon>' +
                    '</div>' +
                    '<div style="font-size: 16px; font-weight: 800; color: #1D3B5E; margin-bottom: 6px;">Pilih Filter Asesmen Sumatif</div>' +
                    '<div style="font-size: 13.5px; color: #64748B; max-width: 500px; margin: 0 auto; line-height: 1.6;">' +
                        'Untuk menampilkan data peserta dan kartu ujian sumatif, silakan pilih <strong>' + missing.join('</strong>, <strong>') + '</strong> terlebih dahulu pada bilah filter di atas.' +
                    '</div>' +
                '</td></tr>';
            }
            var pInfo = document.getElementById('paginationInfo');
            if (pInfo) pInfo.textContent = 'Menunggu pemilihan filter: ' + missing.join(', ') + '...';
            var pBtns = document.getElementById('paginationBtns');
            if (pBtns) pBtns.innerHTML = '';
            currentLoadedRows = [];
            selectedRows.clear();
            if (typeof updateBulkBar === 'function') updateBulkBar();
            return;
        }

        if (!isBg && (!tbody || tbody.children.length === 0)) renderSkeletonRows();
        if (activeAbortCtrl && !isBg) activeAbortCtrl.abort();
        activeAbortCtrl = new AbortController();
        state.isFetching = true;

        var fd = new FormData();
        fd.append('action', 'list');
        fd.append('tipe', state.tipe);
        fd.append('status', state.status);
        fd.append('kelas_id', state.kelas_id);
        fd.append('mapel_id', state.mapel_id);
        fd.append('asesmen_id', state.asesmen_id);
        fd.append('pertemuan_ke', state.pertemuan_ke);
        fd.append('jenis_sumatif', state.jenis_sumatif);
        fd.append('tahun_pelajaran', state.tahun_pelajaran || 'all');
        fd.append('semester', state.semester || 'all');
        fd.append('search', state.search);
        fd.append('page', state.page);
        fd.append('per_page', state.per_page);
        if (isBg && state.lastHash) fd.append('last_hash', state.lastHash);

        fetch(AJAX_URL, { method: 'POST', body: fd, signal: activeAbortCtrl.signal })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            state.isFetching = false;
            if (!res || !res.success) {
                if (!isBg && tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:30px; color:#ef4444;">Gagal memuat data tiket.</td></tr>';
                return;
            }
            if (res.unchanged) {
                if (res.data && res.data.stats) updateKpis(res.data.stats);
                return;
            }
            var d = res.data;
            state.total = d.total;
            state.pages = d.pages;
            state.lastHash = res.hash || '';
            currentLoadedRows = d.rows || [];
            if (d.stats) updateKpis(d.stats);
            patchTableRows(d.rows, d.page, d.per_page);
            renderPagination(d.total, d.page, d.per_page, d.pages);
        })
        .catch(function(err) {
            state.isFetching = false;
            if (err && err.name === 'AbortError') return;
            if (!isBg && tbody) tbody.innerHTML = '<tr><td colspan="10" style="text-align:center; padding:30px; color:#ef4444;">Terjadi kendala jaringan.</td></tr>';
        });
    }

    function updateKpis(s) {
        if (!s) return;

        var elActive = document.getElementById('kpiActiveNow');
        if (elActive) elActive.textContent = Number(s.active_now || 0).toLocaleString('id-ID');

        var elStatInline = document.getElementById('navStatInline');
        if (elStatInline) {
            elStatInline.title = (s.locked || 0) + ' Terkunci • ' + (s.completed || 0) + ' Selesai';
        }

        var badgeDiag = document.getElementById('tabBadgeDiagnostik');
        if (badgeDiag) badgeDiag.textContent = Number(s.total_diagnostik || 0).toLocaleString('id-ID');

        var badgeFormatif = document.getElementById('tabBadgeFormatif');
        if (badgeFormatif) badgeFormatif.textContent = Number(s.total_formatif || 0).toLocaleString('id-ID');

        var badgeSumatif = document.getElementById('tabBadgeSumatif');
        if (badgeSumatif) badgeSumatif.textContent = Number(s.total_sumatif || 0).toLocaleString('id-ID');

        var badgeRemedial = document.getElementById('tabBadgeRemedial');
        if (badgeRemedial) badgeRemedial.textContent = Number(s.total_remedial || 0).toLocaleString('id-ID');
    }

    function renderPagination(total, page, per, pages) {
        var info = document.getElementById('paginationInfo');
        var btns = document.getElementById('paginationBtns');
        if (total === 0) { info.textContent = 'Menampilkan 0 tiket'; btns.innerHTML = ''; return; }
        var start = (page - 1) * per + 1;
        var end   = Math.min(total, page * per);
        info.innerHTML = 'Menampilkan <strong>' + start + ' – ' + end + '</strong> dari total <strong>' + total + '</strong> tiket akses ujian';
        var bHtml = '';
        bHtml += '<button type="button" class="ilmuna-page-btn" data-page="' + (page - 1) + '" ' + (page <= 1 ? 'disabled' : '') + '><iconify-icon icon="solar:alt-arrow-left-bold"></iconify-icon></button>';
        for (var p = 1; p <= pages; p++) {
            if (p === 1 || p === pages || (p >= page - 2 && p <= page + 2)) {
                bHtml += '<button type="button" class="ilmuna-page-btn ' + (p === page ? 'is-active' : '') + '" data-page="' + p + '">' + p + '</button>';
            } else if (p === page - 3 || p === page + 3) {
                bHtml += '<span style="padding: 0 4px; color:#94A3B8;">…</span>';
            }
        }
        bHtml += '<button type="button" class="ilmuna-page-btn" data-page="' + (page + 1) + '" ' + (page >= pages ? 'disabled' : '') + '><iconify-icon icon="solar:alt-arrow-right-bold"></iconify-icon></button>';
        btns.innerHTML = bHtml;
    }

    document.getElementById('paginationBtns').addEventListener('click', function(e) {
        var btn = e.target.closest('.ilmuna-page-btn');
        if (btn) {
            var targetP = parseInt(btn.dataset.page, 10);
            if (targetP && targetP !== state.page && targetP >= 1 && targetP <= state.pages) {
                state.page = targetP;
                fetchList();
            }
        }
    });

    function populateFilterAsesmen() {
        var select = document.getElementById('filterAsesmen');
        if (!select) return;

        if (state.tipe === 'sumatif' && !isSumatifFilterComplete()) {
            select.innerHTML = '<option value="0">-- Pilih T.P., Semester & Jenis Dulu --</option>';
            return;
        }

        var source = [];
        if (state.tipe === 'diagnostik') source = MASTER_DIAGNOSTIK;
        else if (state.tipe === 'formatif') source = MASTER_FORMATIF;
        else if (state.tipe === 'sumatif') source = MASTER_SUMATIF;
        else if (state.tipe === 'remedial') source = MASTER_REMEDIAL;

        var filtered = source.filter(function(item) {
            if (state.tipe === 'sumatif') {
                if (state.tahun_pelajaran && state.tahun_pelajaran !== 'all' && item.tahun_pelajaran && item.tahun_pelajaran !== state.tahun_pelajaran) return false;
                if (state.semester && state.semester !== 'all' && item.semester && item.semester.toLowerCase() !== state.semester.toLowerCase()) return false;
                if (state.jenis_sumatif && state.jenis_sumatif !== 'all' && state.jenis_sumatif !== '' && item.jenis_sumatif !== state.jenis_sumatif) return false;
                if (state.kelas_id > 0) {
                    if (item.multi_kelas_ids) {
                        var ids = String(item.multi_kelas_ids).split(',').map(function(x){ return parseInt(x.trim(), 10); });
                        if (ids.indexOf(parseInt(state.kelas_id, 10)) === -1 && item.id_kelas != state.kelas_id) return false;
                    } else if (item.id_kelas != state.kelas_id) {
                        return false;
                    }
                }
            } else {
                if (state.kelas_id > 0 && item.id_kelas != state.kelas_id) return false;
                if (state.mapel_id > 0 && item.id_mata_pelajaran != state.mapel_id) return false;
                if (state.pertemuan_ke > 0 && item.pertemuan_ke != state.pertemuan_ke) return false;
            }
            return true;
        });

        var html = '<option value="0">Semua Asesmen</option>';
        filtered.forEach(function(item) {
            var subInfo = item.nama_mata_pelajaran ? ' (' + item.nama_mata_pelajaran + ')' : '';
            var isSel = (state.asesmen_id == item.id) ? 'selected' : '';
            html += '<option value="' + item.id + '" ' + isSel + '>#' + item.id + ' - ' + escapeHtml(item.judul_asesmen) + escapeHtml(subInfo) + '</option>';
        });
        select.innerHTML = html;
    }

    function switchTab(tabName, shouldFetch) {
        if (['diagnostik', 'formatif', 'sumatif', 'remedial'].indexOf(tabName) === -1) tabName = 'diagnostik';

        state.tipe = tabName;
        state.page = 1;
        state.asesmen_id = 0;
        state.lastHash = '';
        selectedRows.clear();
        if (typeof updateBulkBar === 'function') updateBulkBar();

        if (window.location.hash !== '#' + tabName) history.replaceState(null, '', '#' + tabName);
        try { localStorage.setItem('ilmuna_access_active_tab', tabName); } catch(e) {}

        document.querySelectorAll('#assessmentMainTabs .ilmuna-tab-btn').forEach(function(btn) {
            btn.classList.toggle('is-active', btn.dataset.tab === tabName);
        });

        var fPertemuan    = document.getElementById('filterPertemuan');
        var fMapel        = document.getElementById('filterMapel');
        var fTahun        = document.getElementById('filterTahunPelajaran');
        var fSemester     = document.getElementById('filterSemester');
        var fJenisSumatif = document.getElementById('filterJenisSumatif');

        if (tabName === 'sumatif') {
            if (fPertemuan) fPertemuan.style.display = 'none';
            if (fMapel) fMapel.style.display = 'none';
            if (fTahun) {
                fTahun.style.display = 'inline-block';
                fTahun.value = state.tahun_pelajaran || '';
            }
            if (fSemester) {
                fSemester.style.display = 'inline-block';
                fSemester.value = state.semester || '';
            }
            if (fJenisSumatif) {
                fJenisSumatif.style.display = 'inline-block';
                fJenisSumatif.value = state.jenis_sumatif || '';
            }
        } else {
            if (fPertemuan) fPertemuan.style.display = 'inline-block';
            if (fMapel) fMapel.style.display = 'inline-block';
            if (fTahun) fTahun.style.display = 'none';
            if (fSemester) fSemester.style.display = 'none';
            if (fJenisSumatif) fJenisSumatif.style.display = 'none';
        }

        var genTipe = document.getElementById('genTipeAsesmen');
        if (genTipe && genTipe.value !== tabName) { genTipe.value = tabName; genTipe.dispatchEvent(new Event('change')); }
        var tokenTipe = document.getElementById('tokenTipeAsesmen');
        if (tokenTipe && tokenTipe.value !== tabName) { tokenTipe.value = tabName; tokenTipe.dispatchEvent(new Event('change')); }

        populateFilterAsesmen();
        if (shouldFetch !== false) {
            if (tabName === 'sumatif' && !isSumatifFilterComplete()) {
                fetchList();
            } else {
                renderSkeletonRows();
                fetchList();
            }
        }
    }

    document.querySelectorAll('#assessmentMainTabs .ilmuna-tab-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var tab = btn.dataset.tab;
            if (tab && tab !== state.tipe) switchTab(tab, true);
        });
    });

    window.addEventListener('hashchange', function() {
        var h = window.location.hash.replace('#', '').trim();
        if (h && ['diagnostik', 'formatif', 'sumatif', 'remedial'].indexOf(h) !== -1 && h !== state.tipe) switchTab(h, true);
    });

    document.querySelectorAll('#filterStatusPills .ilmuna-pill-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('#filterStatusPills .ilmuna-pill-btn').forEach(function(b){ b.classList.remove('is-active'); });
            btn.classList.add('is-active');
            state.status = btn.dataset.status;
            state.page = 1;
            fetchList();
        });
    });

    var fTahunEl = document.getElementById('filterTahunPelajaran');
    if (fTahunEl) {
        fTahunEl.addEventListener('change', function() {
            state.tahun_pelajaran = this.value;
            state.page = 1;
            var fk = document.getElementById('filterKelas');
            if (fk) {
                Array.from(fk.options).forEach(function(opt) {
                    if (opt.value === '0') return;
                    var t = opt.dataset.tahun;
                    if (state.tahun_pelajaran === 'all' || !state.tahun_pelajaran || !t || t === state.tahun_pelajaran) {
                        opt.style.display = '';
                    } else {
                        opt.style.display = 'none';
                        if (opt.selected) {
                            fk.value = '0';
                            state.kelas_id = 0;
                        }
                    }
                });
            }
            populateFilterAsesmen();
            fetchList();
        });
    }

    var fSemEl = document.getElementById('filterSemester');
    if (fSemEl) {
        fSemEl.addEventListener('change', function() {
            state.semester = this.value;
            state.page = 1;
            populateFilterAsesmen();
            fetchList();
        });
    }

    document.getElementById('filterKelas').addEventListener('change', function() { state.kelas_id = parseInt(this.value, 10) || 0; state.page = 1; populateFilterAsesmen(); fetchList(); });
    document.getElementById('filterMapel').addEventListener('change', function() { state.mapel_id = parseInt(this.value, 10) || 0; state.page = 1; populateFilterAsesmen(); fetchList(); });
    document.getElementById('filterPertemuan').addEventListener('change', function() { state.pertemuan_ke = parseInt(this.value, 10) || 0; state.page = 1; populateFilterAsesmen(); fetchList(); });
    document.getElementById('filterJenisSumatif').addEventListener('change', function() { state.jenis_sumatif = this.value; state.page = 1; populateFilterAsesmen(); fetchList(); });
    document.getElementById('filterAsesmen').addEventListener('change', function() { state.asesmen_id = parseInt(this.value, 10) || 0; state.page = 1; fetchList(); });
    document.getElementById('filterPerPage').addEventListener('change', function() { state.per_page = parseInt(this.value, 10) || 20; state.page = 1; fetchList(); });

    document.getElementById('btnResetFilter').addEventListener('click', function() {
        state.status = 'all'; state.kelas_id = 0; state.mapel_id = 0; state.pertemuan_ke = 0;
        if (state.tipe === 'sumatif') {
            state.jenis_sumatif = ''; state.tahun_pelajaran = ''; state.semester = '';
            if (fTahunEl) fTahunEl.value = '';
            if (fSemEl) fSemEl.value = '';
            var fj = document.getElementById('filterJenisSumatif');
            if (fj) fj.value = '';
        } else {
            state.jenis_sumatif = 'all'; state.tahun_pelajaran = 'all'; state.semester = 'all';
            if (fTahunEl) fTahunEl.value = 'all';
            if (fSemEl) fSemEl.value = 'all';
            var fj = document.getElementById('filterJenisSumatif');
            if (fj) fj.value = 'all';
        }
        state.asesmen_id = 0; state.search = ''; state.page = 1;
        document.getElementById('filterSearch').value = '';
        document.getElementById('filterKelas').value = '0';
        document.getElementById('filterMapel').value = '0';
        document.getElementById('filterPertemuan').value = '0';
        var fk = document.getElementById('filterKelas');
        if (fk) {
            Array.from(fk.options).forEach(function(opt) { opt.style.display = ''; });
        }
        document.querySelectorAll('#filterStatusPills .ilmuna-pill-btn').forEach(function(b){ b.classList.remove('is-active'); });
        document.querySelector('#filterStatusPills [data-status="all"]').classList.add('is-active');
        populateFilterAsesmen();
        fetchList();
    });

    document.getElementById('filterSearch').addEventListener('input', function(e) {
        clearTimeout(searchTimer);
        var q = e.target.value.trim();
        searchTimer = setTimeout(function() { state.search = q; state.page = 1; fetchList(); }, 220);
    });

    var btnOpenGen = document.getElementById('btnOpenGenModal');
    if (btnOpenGen) {
        btnOpenGen.addEventListener('click', function() {
            var genTipe = document.getElementById('genTipeAsesmen');
            if (genTipe) { genTipe.value = state.tipe; genTipe.dispatchEvent(new Event('change')); }
            if (state.tipe === 'sumatif') {
                var elTahun = document.getElementById('genSumatifTahun');
                var elSem   = document.getElementById('genSumatifSemester');
                var elJen   = document.getElementById('genSumatifJenis');
                var elKel   = document.getElementById('genSumatifKelas');
                if (elTahun && state.tahun_pelajaran !== 'all') elTahun.value = state.tahun_pelajaran;
                if (elSem && state.semester !== 'all') elSem.value = state.semester;
                if (elJen && state.jenis_sumatif !== 'all') elJen.value = state.jenis_sumatif;
                if (elKel && state.kelas_id > 0) elKel.value = String(state.kelas_id);
                if (typeof filterGenSumatifItems === 'function') filterGenSumatifItems();

                var checkedSumatifs = document.querySelectorAll('.cb-gen-sumatif:checked');
                if (checkedSumatifs.length === 0) {
                    var targetCb = null;
                    if (state.asesmen_id > 0) targetCb = document.querySelector('.cb-gen-sumatif[value="' + state.asesmen_id + '"]');
                    if (!targetCb) {
                        targetCb = document.querySelector('.ilmuna-sumatif-item:not([style*="display: none"]) .cb-gen-sumatif') || document.querySelector('.cb-gen-sumatif');
                    }
                    if (targetCb) targetCb.checked = true;
                }
                syncSumatifTokenAndClasses();
            } else {
                updateTargetClassDisplay();
            }
            openModal('modalGenerateMassal');
        });
    }

    var genTipeSelect       = document.getElementById('genTipeAsesmen');
    var genIdDiagSelect     = document.getElementById('genIdDiagnostik');
    var genIdFormatifSelect = document.getElementById('genIdFormatif');

    function updateTargetClassDisplay() {
        var tipe = genTipeSelect ? genTipeSelect.value : 'sumatif';
        if (tipe === 'sumatif') { syncSumatifTokenAndClasses(); return; }
        var sel = (tipe === 'diagnostik') ? genIdDiagSelect : ((tipe === 'remedial') ? document.getElementById('genIdRemedial') : genIdFormatifSelect);
        if (tipe === 'remedial') syncRemedialInfo();
        var display = document.getElementById('genDisplayKelas');
        if (!display) return;
        if (sel && sel.selectedIndex >= 0 && sel.options[sel.selectedIndex]) {
            var opt = sel.options[sel.selectedIndex];
            var kelasNama = (opt.dataset.kelasNama || '').trim();
            if (kelasNama) display.innerHTML = '<span style="color: #0D7A87; font-weight: 800;">' + escapeHtml(kelasNama) + '</span>';
            else display.textContent = 'Otomatis sesuai kelas pada master asesmen';
        } else display.textContent = 'Pilih asesmen di atas';
    }

    function syncSumatifTokenAndClasses() {
        var checkedBoxes = document.querySelectorAll('.cb-gen-sumatif:checked');
        var badgeCount = document.getElementById('genSumatifBadgeCount');
        if (badgeCount) badgeCount.textContent = checkedBoxes.length + ' Terpilih';

        var display = document.getElementById('genDisplayKelas');
        var tokenInput = document.getElementById('genTokenSesi');
        var btnAcak = document.getElementById('btnGenRandomToken');
        var labelTok = document.getElementById('labelGenToken');

        document.querySelectorAll('.ilmuna-sumatif-item').forEach(function(item) {
            var cb = item.querySelector('.cb-gen-sumatif');
            if (cb && cb.checked) { item.style.borderColor = '#0D7A87'; item.style.background = '#F0FDFA'; }
            else { item.style.borderColor = '#E2E8F0'; item.style.background = '#F8FAFC'; }
        });

        if (checkedBoxes.length === 0) {
            if (display) display.innerHTML = '<span style="color: #EF4444; font-weight: 600;">Silakan centang minimal 1 asesmen sumatif</span>';
            if (tokenInput) { tokenInput.value = ''; tokenInput.placeholder = 'Pilih asesmen sumatif terlebih dahulu'; tokenInput.readOnly = false; }
            if (btnAcak) btnAcak.disabled = false;
            if (labelTok) labelTok.textContent = 'Token Sesi Ujian (Otomatis Ditentukan Sistem per Asesmen Sumatif)';
            return;
        }

        var classNames = [];
        checkedBoxes.forEach(function(cb) {
            var raw = (cb.dataset.kelasNama || '').split(',');
            raw.forEach(function(c) {
                var trimmed = c.trim();
                if (trimmed && classNames.indexOf(trimmed) === -1) classNames.push(trimmed);
            });
        });

        if (checkedBoxes.length === 1) {
            var single = checkedBoxes[0];
            if (display) display.innerHTML = '<span style="color: #0D7A87; font-weight: 800;">' + escapeHtml(classNames.join(', ') || single.dataset.kelasNama || 'Semua Kelas Terdaftar') + '</span>';
            var existingTok = single.dataset.token ? single.dataset.token.trim() : '';
            if (tokenInput) { tokenInput.value = existingTok ? existingTok : randomToken(); tokenInput.readOnly = false; }
            if (btnAcak) btnAcak.disabled = false;
            if (labelTok) labelTok.textContent = 'Token Sesi Ujian (Otomatis Ditentukan Sistem per Asesmen Sumatif)';
        } else {
            if (display) display.innerHTML = '<span style="color: #0D7A87; font-weight: 800;">' + escapeHtml(classNames.join(', ')) + '</span> <span class="ilmuna-badge ilmuna-badge--teal" style="font-size: 10px; margin-left: 6px;">' + checkedBoxes.length + ' Asesmen Sumatif Terpilih</span>';
            if (tokenInput) { tokenInput.value = '[OTOMATIS DI-MAPPING SISTEM BERBEDA TIAP ASESMEN]'; tokenInput.readOnly = true; }
            if (btnAcak) btnAcak.disabled = true;
            if (labelTok) labelTok.textContent = 'Token Sesi Ujian (Otomatis Dimapping Berbeda & Unik Tiap Asesmen)';
        }
    }

    var genSumatifContainer = document.getElementById('genSumatifListContainer');
    if (genSumatifContainer) {
        genSumatifContainer.addEventListener('change', function(e) {
            if (e.target && e.target.classList.contains('cb-gen-sumatif')) syncSumatifTokenAndClasses();
        });
    }

    var btnSelectAllSumatif = document.getElementById('btnSelectAllSumatif');
    if (btnSelectAllSumatif) {
        btnSelectAllSumatif.addEventListener('click', function() {
            document.querySelectorAll('.ilmuna-sumatif-item').forEach(function(item) {
                if (item.style.display !== 'none') {
                    var cb = item.querySelector('.cb-gen-sumatif');
                    if (cb) cb.checked = true;
                }
            });
            syncSumatifTokenAndClasses();
        });
    }

    var btnDeselectAllSumatif = document.getElementById('btnDeselectAllSumatif');
    if (btnDeselectAllSumatif) {
        btnDeselectAllSumatif.addEventListener('click', function() {
            document.querySelectorAll('.cb-gen-sumatif').forEach(function(cb) { cb.checked = false; });
            syncSumatifTokenAndClasses();
        });
    }

    var genSumatifSearch   = document.getElementById('genSumatifSearch');
    var genSumatifTahun    = document.getElementById('genSumatifTahun');
    var genSumatifSemester = document.getElementById('genSumatifSemester');
    var genSumatifJenis    = document.getElementById('genSumatifJenis');
    var genSumatifKelas    = document.getElementById('genSumatifKelas');

    function filterGenSumatifItems() {
        var selTahun    = genSumatifTahun ? genSumatifTahun.value : 'all';
        var selSemester = genSumatifSemester ? genSumatifSemester.value : 'all';
        var selJenis    = genSumatifJenis ? genSumatifJenis.value : 'all';
        var selKelas    = genSumatifKelas ? genSumatifKelas.value : 'all';
        var q           = genSumatifSearch ? genSumatifSearch.value.toLowerCase().trim() : '';

        // Filter dropdown kelas di modal sesuai tahun pelajaran terpilih
        if (genSumatifKelas) {
            Array.from(genSumatifKelas.options).forEach(function(opt) {
                if (opt.value === 'all') return;
                var t = opt.dataset.tahun;
                if (selTahun === 'all' || !t || t === selTahun) {
                    opt.style.display = '';
                } else {
                    opt.style.display = 'none';
                    if (opt.selected) {
                        genSumatifKelas.value = 'all';
                        selKelas = 'all';
                    }
                }
            });
        }

        document.querySelectorAll('.ilmuna-sumatif-item').forEach(function(item) {
            var cb = item.querySelector('.cb-gen-sumatif');
            if (!cb) return;

            var itemTahun    = cb.dataset.tahun || '';
            var itemSemester = cb.dataset.semester || '';
            var itemJenis    = cb.dataset.jenis || '';
            var itemKelas    = cb.dataset.kelas || '';
            var multiKelas   = cb.dataset.multiKelas || '';

            var matchTahun    = (selTahun === 'all' || !itemTahun || itemTahun === selTahun);
            var matchSemester = (selSemester === 'all' || !itemSemester || itemSemester.toLowerCase() === selSemester.toLowerCase());
            var matchJenis    = (selJenis === 'all' || !itemJenis || itemJenis === selJenis);

            var matchKelas = true;
            if (selKelas !== 'all') {
                var targetKId = parseInt(selKelas, 10);
                if (multiKelas) {
                    var ids = String(multiKelas).split(',').map(function(x){ return parseInt(x.trim(), 10); });
                    matchKelas = (ids.indexOf(targetKId) !== -1 || parseInt(itemKelas, 10) === targetKId);
                } else {
                    matchKelas = (parseInt(itemKelas, 10) === targetKId);
                }
            }

            var text = (item.textContent || '').toLowerCase();
            var matchSearch = (!q || text.indexOf(q) !== -1);

            var isVisible = (matchTahun && matchSemester && matchJenis && matchKelas && matchSearch);
            item.style.display = isVisible ? 'flex' : 'none';
        });

        syncSumatifTokenAndClasses();
    }

    if (genSumatifTahun)    genSumatifTahun.addEventListener('change', filterGenSumatifItems);
    if (genSumatifSemester) genSumatifSemester.addEventListener('change', filterGenSumatifItems);
    if (genSumatifJenis)    genSumatifJenis.addEventListener('change', filterGenSumatifItems);
    if (genSumatifKelas)    genSumatifKelas.addEventListener('change', filterGenSumatifItems);
    if (genSumatifSearch)   genSumatifSearch.addEventListener('input', filterGenSumatifItems);

    if (genIdDiagSelect) genIdDiagSelect.addEventListener('change', updateTargetClassDisplay);
    if (genIdFormatifSelect) genIdFormatifSelect.addEventListener('change', updateTargetClassDisplay);

    var genIdRemedialSelect = document.getElementById('genIdRemedial');
    var genRemedialFilterKelas = document.getElementById('genRemedialFilterKelas');
    function filterGenRemedialByKelas() {
        if (!genIdRemedialSelect || !genRemedialFilterKelas) return;
        var selKelas = genRemedialFilterKelas.value;
        var firstVisibleIdx = -1;
        var currentSelectedVisible = false;
        Array.from(genIdRemedialSelect.options).forEach(function(opt, idx) {
            if (!opt.value) return;
            var match = (selKelas === 'all' || String(opt.dataset.kelas || '') === String(selKelas));
            opt.style.display = match ? '' : 'none';
            opt.disabled = !match;
            if (match && firstVisibleIdx === -1) firstVisibleIdx = idx;
            if (match && idx === genIdRemedialSelect.selectedIndex) currentSelectedVisible = true;
        });
        if (!currentSelectedVisible) {
            genIdRemedialSelect.selectedIndex = (firstVisibleIdx !== -1) ? firstVisibleIdx : -1;
        }
        updateTargetClassDisplay();
    }
    if (genRemedialFilterKelas) genRemedialFilterKelas.addEventListener('change', filterGenRemedialByKelas);

    function syncRemedialInfo() {
        var info = document.getElementById('genRemedialEligibleInfo');
        var tokenInput = document.getElementById('genTokenSesi');
        if (!genIdRemedialSelect || !info) return;
        var opt = genIdRemedialSelect.options[genIdRemedialSelect.selectedIndex];
        if (!opt || !opt.value || opt.disabled) {
            info.style.display = 'block';
            info.style.cssText += 'color:#B91C1C;background:#FEF2F2;border-color:#FECACA;';
            info.innerHTML = '<strong>Tidak ada program remedial/pengayaan</strong> pada kelas yang dipilih.';
            return;
        }
        info.style.display = 'block';
        var layak = parseInt(opt.dataset.layak || '0', 10);
        var jenisRem = (opt.dataset.jenis || 'Remedial').toLowerCase();
        var kelasNama = opt.dataset.kelasNama || '-';
        var kktpVal = opt.dataset.kktp || '75';
        var daftarSiswa = opt.dataset.siswaLayak || '';
        if (layak > 0) {
            info.style.cssText += 'color:#047857;background:#ECFDF5;border-color:#A7F3D0;';
            if (jenisRem === 'pengayaan') {
                info.innerHTML = '<strong>' + layak + ' siswa</strong> di kelas <strong>' + escapeHtml(kelasNama) + '</strong> memenuhi syarat pengayaan (nilai &ge; Ambang Batas / KKTP ' + escapeHtml(kktpVal) + ')' + (daftarSiswa ? ':<br><span style="font-size:11.5px;color:#065F46;">&bull; ' + escapeHtml(daftarSiswa) + '</span>' : '.');
            } else {
                info.innerHTML = '<strong>' + layak + ' siswa</strong> di kelas <strong>' + escapeHtml(kelasNama) + '</strong> berhak mengikuti remedial sesuai kriteria asesmen ini (nilai &lt; KKTP / Batas Norma Kelas ' + escapeHtml(kktpVal) + ')' + (daftarSiswa ? ':<br><span style="font-size:11.5px;color:#065F46;">&bull; ' + escapeHtml(daftarSiswa) + '</span>' : '.');
            }
        } else {
            info.style.cssText += 'color:#B91C1C;background:#FEF2F2;border-color:#FECACA;';
            info.innerHTML = '<strong>Belum ada siswa yang memenuhi syarat</strong> di kelas <strong>' + escapeHtml(kelasNama) + '</strong> pada asesmen ini (syarat nilai belum tuntas &lt; KKTP / Batas Norma Kelas).';
        }
        if (tokenInput && genTipeSelect && genTipeSelect.value === 'remedial') {
            tokenInput.readOnly = false;
            tokenInput.value = (opt.dataset.token || '').trim() || randomToken();
        }
    }
    if (genIdRemedialSelect) genIdRemedialSelect.addEventListener('change', updateTargetClassDisplay);

    if (genTipeSelect) {
        genTipeSelect.addEventListener('change', function() {
            var val = this.value;
            var boxDiag = document.getElementById('boxGenDiagnostik');
            var boxFmt  = document.getElementById('boxGenFormatif');
            var boxSum  = document.getElementById('boxGenSumatif');
            var pText   = document.getElementById('genPolicyText');
            var labelTok = document.getElementById('labelGenToken');

            if (boxDiag) boxDiag.style.display = (val === 'diagnostik') ? 'block' : 'none';
            if (boxFmt)  boxFmt.style.display  = (val === 'formatif') ? 'block' : 'none';
            if (boxSum)  boxSum.style.display  = (val === 'sumatif') ? 'block' : 'none';
            var boxRem = document.getElementById('boxGenRemedial');
            if (boxRem)  boxRem.style.display  = (val === 'remedial') ? 'block' : 'none';

            if (pText) {
                if (val === 'remedial') {
                    pText.innerHTML = '<strong>Aturan Remedial / Pengayaan:</strong> Akun ujian dibuat secara otomatis untuk siswa yang belum tuntas (nilai &lt; KKTP pada Formatif/Sumatif LM berbasis PAP, atau &lt; Rata-rata Kelas pada STS/SAS berbasis PAN) pada asesmen di mata pelajaran terkait (atau siswa tuntas untuk program Pengayaan).';
                    if (labelTok) labelTok.textContent = 'Token Sesi Ujian (Opsional)';
                } else if (val === 'diagnostik') {
                    pText.innerHTML = '<strong>Aturan Diagnostik:</strong> Akun dipisahkan berdasarkan Kelas, Mapel, dan Asesmen. <strong style="color: #0D7A87;">Kartu ujian PDF A5 otomatis dikirimkan ke Gmail masing-masing mahasiswa (tanpa menyertakan token sesi)</strong> untuk dicetak mandiri menggunakan kertas A5.';
                    if (labelTok) labelTok.textContent = 'Token Sesi Ujian (Opsional)';
                } else if (val === 'formatif') {
                    pText.innerHTML = '<strong>Aturan Formatif:</strong> Akun dipisahkan berdasarkan Kelas, Mapel, dan Pertemuan. <strong style="color: #0D7A87;">Kartu ujian PDF A5 otomatis dikirimkan ke Gmail masing-masing mahasiswa (tanpa menyertakan token sesi)</strong> untuk dicetak mandiri menggunakan kertas A5.';
                    if (labelTok) labelTok.textContent = 'Token Sesi Ujian (Opsional)';
                } else {
                    pText.innerHTML = '<strong>Aturan Sumatif:</strong> Akun (Username & Password) siswa berlaku menyeluruh untuk semua mapel dalam periode yang sama (STS / SAS per semester & tahun ajaran). Anda dapat memilih lebih dari 1 asesmen sumatif sekaligus, sistem otomatis memetakan token berbeda untuk masing-masing asesmen.';
                    if (labelTok) labelTok.textContent = 'Token Sesi Ujian (Otomatis Ditentukan Sistem per Asesmen Sumatif)';
                    syncSumatifTokenAndClasses();
                }
            }
            updateTargetClassDisplay();
        });
    }

    function randomToken() {
        var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        var res = '';
        for (var i = 0; i < 6; i++) res += chars.charAt(Math.floor(Math.random() * chars.length));
        return res;
    }

    var btnGenRandomToken = document.getElementById('btnGenRandomToken');
    if (btnGenRandomToken) {
        btnGenRandomToken.addEventListener('click', function() {
            var tokenInput = document.getElementById('genTokenSesi');
            if (tokenInput && !tokenInput.readOnly) tokenInput.value = randomToken();
        });
    }

    var btnSubmitGenerate = document.getElementById('btnSubmitGenerate');
    if (btnSubmitGenerate) {
        btnSubmitGenerate.addEventListener('click', function() {
            var tipe = document.getElementById('genTipeAsesmen').value;
            var idsAsesmen = [];
            if (tipe === 'diagnostik') { var diagId = document.getElementById('genIdDiagnostik').value; if (diagId) idsAsesmen.push(diagId); }
            else if (tipe === 'formatif') { var fmtId = document.getElementById('genIdFormatif').value; if (fmtId) idsAsesmen.push(fmtId); }
            else if (tipe === 'remedial') { var remId = document.getElementById('genIdRemedial').value; if (remId) idsAsesmen.push(remId); }
            else {
                document.querySelectorAll('.cb-gen-sumatif:checked').forEach(function(cb) { if (cb.value) idsAsesmen.push(cb.value); });
            }

            var tokenSesi = document.getElementById('genTokenSesi').value.trim();
            var userOpt   = document.getElementById('genUserOption').value;
            var passOpt   = document.getElementById('genPassOption').value;

            if (idsAsesmen.length === 0) {
                toast('warning', (tipe === 'sumatif') ? 'Silakan centang minimal 1 judul asesmen sumatif.' : 'Pilih judul asesmen terlebih dahulu.');
                return;
            }

            var confirmText = (idsAsesmen.length > 1) ? ('Generate kredensial ujian untuk ' + idsAsesmen.length + ' asesmen sumatif sekaligus?') : 'Generate kredensial ujian untuk seluruh siswa di rombel sasaran asesmen ini?';

            confirmDialog(confirmText, { title: 'Generate Akses Ujian', type: 'gold', okText: 'Generate Sekarang' }).then(function(confirmed) {
                if (!confirmed) return;
                btnSubmitGenerate.disabled = true;
                btnSubmitGenerate.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="16"></iconify-icon> Memproses...';

                var fd = new FormData();
                fd.append('action', 'generate_massal');
                fd.append('tipe_asesmen', tipe);
                fd.append('ids_asesmen', idsAsesmen.join(','));
                fd.append('id_asesmen', idsAsesmen[0]);
                fd.append('token_sesi', (idsAsesmen.length === 1) ? tokenSesi : '');
                fd.append('user_option', userOpt);
                fd.append('pass_option', passOpt);

                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        btnSubmitGenerate.disabled = false;
                        btnSubmitGenerate.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon> Generate Akses Sekarang';
                        if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal generate tiket.'); return; }
                        toast('success', res.data.message);
                        closeModal('modalGenerateMassal');
                        switchTab(tipe, false);
                        if (idsAsesmen.length === 1) state.asesmen_id = parseInt(idsAsesmen[0], 10) || 0;
                        else state.asesmen_id = 0;
                        state.page = 1;
                        populateFilterAsesmen();
                        fetchList();
                    })
                    .catch(function() {
                        btnSubmitGenerate.disabled = false;
                        btnSubmitGenerate.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon> Generate Akses Sekarang';
                        toast('error', 'Koneksi bermasalah ke server.');
                    });
            });
        });
    }

    var btnOpenUpdateToken = document.getElementById('btnOpenUpdateToken');
    if (btnOpenUpdateToken) {
        btnOpenUpdateToken.addEventListener('click', function() {
            var tokenTipe = document.getElementById('tokenTipeAsesmen');
            if (tokenTipe) { tokenTipe.value = state.tipe; tokenTipe.dispatchEvent(new Event('change')); }
            if (state.tipe === 'sumatif') syncUpdateTokenSumatif();
            openModal('modalUpdateToken');
        });
    }

    var tokenIdSumatifSelect = document.getElementById('tokenIdSumatif');
    function syncUpdateTokenSumatif() {
        if (!tokenIdSumatifSelect) return;
        var opt = tokenIdSumatifSelect.options[tokenIdSumatifSelect.selectedIndex];
        if (!opt) return;
        var existingTok = opt.dataset.token ? opt.dataset.token.trim() : '';
        var inp = document.getElementById('tokenInputBaru');
        if (inp) inp.value = existingTok ? existingTok : randomToken();
    }
    if (tokenIdSumatifSelect) tokenIdSumatifSelect.addEventListener('change', syncUpdateTokenSumatif);

    var tokenTipeSelect = document.getElementById('tokenTipeAsesmen');
    if (tokenTipeSelect) {
        tokenTipeSelect.addEventListener('change', function() {
            var val = this.value;
            var boxDiag = document.getElementById('boxTokenDiagnostik');
            var boxFmt  = document.getElementById('boxTokenFormatif');
            var boxSum  = document.getElementById('boxTokenSumatif');
            var boxSync = document.getElementById('boxTokenSumatifSync');
            if (boxDiag) boxDiag.style.display = (val === 'diagnostik') ? 'block' : 'none';
            if (boxFmt)  boxFmt.style.display  = (val === 'formatif') ? 'block' : 'none';
            if (boxSum)  boxSum.style.display  = (val === 'sumatif') ? 'block' : 'none';
            var boxTokRem = document.getElementById('boxTokenRemedial');
            if (boxTokRem) boxTokRem.style.display = (val === 'remedial') ? 'block' : 'none';
            if (boxSync) boxSync.style.display = (val === 'sumatif') ? 'block' : 'none';
            if (val === 'sumatif') syncUpdateTokenSumatif();
        });
    }

    var btnAcakTokenMassal = document.getElementById('btnAcakTokenMassal');
    if (btnAcakTokenMassal) {
        btnAcakTokenMassal.addEventListener('click', function() {
            document.getElementById('tokenInputBaru').value = randomToken();
        });
    }

    var btnSaveUpdateToken = document.getElementById('btnSaveUpdateToken');
    if (btnSaveUpdateToken) {
        btnSaveUpdateToken.addEventListener('click', function() {
            var tipe = document.getElementById('tokenTipeAsesmen').value;
            var idAsesmen = 0;
            if (tipe === 'diagnostik') idAsesmen = document.getElementById('tokenIdDiagnostik').value;
            else if (tipe === 'formatif') idAsesmen = document.getElementById('tokenIdFormatif').value;
            else if (tipe === 'remedial') idAsesmen = document.getElementById('tokenIdRemedial').value;
            else idAsesmen = document.getElementById('tokenIdSumatif').value;
            var tokenBaru = document.getElementById('tokenInputBaru').value.trim();
            if (!idAsesmen) { toast('warning', 'Pilih asesmen sasaran.'); return; }

            btnSaveUpdateToken.disabled = true;
            var fd = new FormData();
            fd.append('action', 'update_token');
            fd.append('tipe_asesmen', tipe);
            fd.append('id_asesmen', idAsesmen);
            fd.append('token_baru', tokenBaru);

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    btnSaveUpdateToken.disabled = false;
                    if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal update token.'); return; }
                    toast('success', res.data.message);
                    closeModal('modalUpdateToken');
                    fetchList();
                })
                .catch(function() { btnSaveUpdateToken.disabled = false; toast('error', 'Koneksi bermasalah.'); });
        });
    }

    document.addEventListener('click', function(e) {
        var copyBtn = e.target.closest('.btn-copy');
        if (copyBtn) {
            e.preventDefault();
            var text = copyBtn.dataset.text || '';
            if (text) { navigator.clipboard.writeText(text); toast('info', 'Tersalin: ' + text); }
            return;
        }

        var unfreezeBtn = e.target.closest('.btn-unfreeze-row');
        if (unfreezeBtn) {
            e.preventDefault();
            var uTipe = unfreezeBtn.dataset.tipe, uId = unfreezeBtn.dataset.id, uName = unfreezeBtn.dataset.name;
            confirmDialog('Buka kunci ujian siswa "' + uName + '"? Siswa akan dapat login kembali dan langsung melanjutkan pengerjaan soal dengan jawaban yang tetap utuh.', { title: 'Buka Kunci (Unfreeze)', type: 'gold', okText: 'Buka Kunci & Izinkan Lanjut' }).then(function(confirmed) {
                if (!confirmed) return;
                var fd = new FormData();
                fd.append('action', 'unfreeze'); fd.append('tipe_asesmen', uTipe); fd.append('id_akses', uId);
                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal membuka kunci.'); return; }
                        toast('success', res.data.message);
                        fetchList({ isBackground: true });
                    })
                    .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
            });
            return;
        }

        var violBtn = e.target.closest('.btn-show-violations');
        if (violBtn) {
            e.preventDefault();
            var vSiswaId = violBtn.dataset.siswa, vAsesmenId = violBtn.dataset.asesmen, vTipe = violBtn.dataset.tipe, vName = violBtn.dataset.name;
            document.getElementById('violStudentName').textContent = vName;
            document.getElementById('violStudentSub').textContent  = 'Memuat rekaman log audit keamanan Kiosk...';
            var vTbody = document.getElementById('violationLogsBody');
            vTbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 24px; color: #64748B;"><iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="22"></iconify-icon> Memuat riwayat...</td></tr>';
            var btnViolUnfreeze = document.getElementById('btnViolUnfreezeNow');
            btnViolUnfreeze.dataset.siswa = vSiswaId;
            btnViolUnfreeze.dataset.asesmen = vAsesmenId;
            btnViolUnfreeze.dataset.tipe = vTipe;
            openModal('modalViolationLogs');

            var fd = new FormData();
            fd.append('action', 'get_violations'); fd.append('id_siswa', vSiswaId); fd.append('id_asesmen', vAsesmenId); fd.append('tipe_asesmen', vTipe);
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    if (!res || !res.success || !res.data) { vTbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#ef4444;">Gagal memuat log.</td></tr>'; return; }
                    var logs = res.data.logs || [];
                    document.getElementById('violStudentSub').textContent = 'Total Insiden Terdeteksi: ' + logs.length + ' kali.';
                    if (logs.length === 0) { vTbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#64748B;">Tidak ada catatan pelanggaran.</td></tr>'; return; }
                    var h = '';
                    logs.forEach(function(l) {
                        h += '<tr><td style="text-align: center; font-weight: 700; color: #EF4444;">#' + l.pelanggaran_ke + '</td><td><strong style="color: #1E293B;">' + l.waktu_kejadian + '</strong></td><td><span class="ilmuna-badge ilmuna-badge--rose" style="font-size: 11px;"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> ' + escapeHtml(l.jenis_pelanggaran) + '</span></td><td style="color: #475569;">' + escapeHtml(l.keterangan || '-') + '</td><td style="text-align: center; font-weight: 600; color: #D97706;">' + (l.sisa_waktu_detik ? l.sisa_waktu_detik + 's' : '-') + '</td><td style="font-size: 11px; color: #64748B;">' + escapeHtml(l.ip_address || '-') + '</td></tr>';
                    });
                    vTbody.innerHTML = h;
                })
                .catch(function() { vTbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:20px; color:#ef4444;">Kesalahan jaringan.</td></tr>'; });
            return;
        }

        var resetBtn = e.target.closest('.btn-reset-row');
        if (resetBtn) {
            e.preventDefault();
            var sTipe = resetBtn.dataset.tipe, sId = resetBtn.dataset.id, sName = resetBtn.dataset.name;
            confirmDialog('Reset total status ujian siswa "' + sName + '" ke status Belum Mulai?', { title: 'Reset Total Status Siswa', type: 'danger', okText: 'Reset Total ke Awal' }).then(function(confirmed) {
                if (!confirmed) return;
                var fd = new FormData();
                fd.append('action', 'reset_status'); fd.append('tipe_asesmen', sTipe); fd.append('id_akses', sId); fd.append('status_baru', 'Belum Mulai');
                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal reset status.'); return; }
                        toast('success', res.data.message);
                        fetchList({ isBackground: true });
                    })
                    .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
            });
            return;
        }

        var delBtn = e.target.closest('.btn-delete-row');
        if (delBtn) {
            e.preventDefault();
            var dTipe = delBtn.dataset.tipe, dId = delBtn.dataset.id, dName = delBtn.dataset.name;
            confirmDialog('Hapus tiket akses ujian untuk siswa "' + dName + '"?', { title: 'Hapus Tiket Akses', type: 'danger', okText: 'Hapus Tiket' }).then(function(confirmed) {
                if (!confirmed) return;
                var fd = new FormData();
                fd.append('action', 'hapus_akses'); fd.append('tipe_asesmen', dTipe); fd.append('id_akses', dId);
                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal menghapus tiket.'); return; }
                        toast('success', res.data.message);
                        var delTr = document.querySelector('tr[data-row-id="' + dTipe + '_' + dId + '"]');
                        if (delTr) { delTr.classList.add('row-pulse-delete'); setTimeout(function(){ delTr.remove(); }, 350); }
                        fetchList({ isBackground: true });
                    })
                    .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
            });
            return;
        }

        var printSingleBtn = e.target.closest('.btn-print-single');
        if (printSingleBtn) {
            e.preventDefault();
            var rowId = printSingleBtn.dataset.row;
            if (rowId && (rowId.indexOf('sumatif_') === 0 || state.activeTab === 'sumatif')) {
                downloadSumatifCardsBlob([rowId]);
            } else {
                previewAndPrintCards([rowId]);
            }
            return;
        }

        var sendEmailSingleBtn = e.target.closest('.btn-send-card-email');
        if (sendEmailSingleBtn) {
            e.preventDefault();
            var sTipe = sendEmailSingleBtn.dataset.tipe, sId = sendEmailSingleBtn.dataset.id, sName = sendEmailSingleBtn.dataset.name, sEmail = sendEmailSingleBtn.dataset.email;
            if (sTipe === 'sumatif') {
                toast('warning', 'Kartu peserta asesmen sumatif tidak dikirimkan ke Gmail (wajib dicetak / diunduh format A4).');
                return;
            }
            if (!sEmail) {
                toast('warning', 'Mahasiswa "' + sName + '" belum memiliki alamat Gmail terdaftar di profil siswa.');
                return;
            }
            confirmDialog('Kirimkan berkas PDF Kartu Peserta Ujian (A5) ke Gmail "' + sEmail + '" (' + sName + ')?', { title: 'Kirim Kartu ke Gmail', type: 'teal', okText: 'Kirim Sekarang' }).then(function(confirmed) {
                if (!confirmed) return;
                toast('info', 'Sedang memproses & mengirim kartu ujian ke ' + sEmail + '...');
                var fd = new FormData();
                fd.append('action', 'send_kartu_email');
                fd.append('tipe_asesmen', sTipe);
                fd.append('id_akses', sId);
                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal mengirim email.'); return; }
                        toast('success', res.data.message || 'Kartu ujian berhasil dikirimkan ke Gmail mahasiswa!');
                    })
                    .catch(function(){ toast('error', 'Koneksi bermasalah ke server.'); });
            });
            return;
        }
    });

    var checkAll = document.getElementById('checkAllRows');
    if (checkAll) {
        checkAll.addEventListener('change', function(e) {
            var checked = e.target.checked;
            document.querySelectorAll('#tableAccessBody .access-cb').forEach(function(cb) {
                cb.checked = checked;
                var tr = cb.closest('tr');
                if (checked) { selectedRows.add(cb.value); if (tr) tr.classList.add('is-selected'); }
                else { selectedRows.delete(cb.value); if (tr) tr.classList.remove('is-selected'); }
            });
            updateBulkBar();
        });
    }

    document.addEventListener('change', function(e) {
        var rowCb = e.target.closest('.access-cb');
        if (rowCb) {
            var tr = rowCb.closest('tr');
            if (rowCb.checked) { selectedRows.add(rowCb.value); if (tr) tr.classList.add('is-selected'); }
            else { selectedRows.delete(rowCb.value); if (tr) tr.classList.remove('is-selected'); }
            updateBulkBar();
        }
    });

    function updateBulkBar() {
        var bar = document.getElementById('bulkActionBar');
        var cnt = document.getElementById('bulkSelectedCount');
        cnt.textContent = selectedRows.size;
        if (selectedRows.size > 0) bar.classList.add('is-open');
        else bar.classList.remove('is-open');

        // Sembunyikan tombol Kirim Kartu ke Gmail jika di Tab Sumatif
        var btnMail = document.getElementById('btnBulkSendEmail');
        if (btnMail) {
            btnMail.style.display = (state.activeTab === 'sumatif') ? 'none' : 'inline-flex';
        }
    }

    document.getElementById('btnBulkCancel').addEventListener('click', function() {
        selectedRows.clear();
        if (checkAll) checkAll.checked = false;
        document.querySelectorAll('#tableAccessBody .access-cb').forEach(function(cb) {
            cb.checked = false;
            var tr = cb.closest('tr');
            if (tr) tr.classList.remove('is-selected');
        });
        updateBulkBar();
    });

    var btnBulkSendEmail = document.getElementById('btnBulkSendEmail');
    if (btnBulkSendEmail) {
        btnBulkSendEmail.addEventListener('click', function() {
            if (selectedRows.size === 0) return;
            confirmDialog('Kirimkan berkas PDF Kartu Ujian (A5) ke Gmail untuk ' + selectedRows.size + ' mahasiswa terpilih?', { title: 'Kirim Kartu Massal ke Gmail', type: 'teal', okText: 'Kirim ke Gmail Semua' }).then(function(confirmed) {
                if (!confirmed) return;
                toast('info', 'Sedang memproses & mengirim kartu ujian massal ke Gmail...');
                var fd = new FormData();
                fd.append('action', 'bulk_send_kartu_email');
                fd.append('selected_ids', JSON.stringify(Array.from(selectedRows)));
                fetch(AJAX_URL, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(res) {
                        if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal mengirim massal.'); return; }
                        toast('success', res.data.message || 'Kartu ujian berhasil dikirimkan ke Gmail mahasiswa!');
                        selectedRows.clear();
                        updateBulkBar();
                    })
                    .catch(function(){ toast('error', 'Koneksi bermasalah ke server.'); });
            });
        });
    }

    document.getElementById('btnBulkReset').addEventListener('click', function() {
        if (selectedRows.size === 0) return;
        confirmDialog('Buka kunci (Unfreeze) untuk ' + selectedRows.size + ' siswa terpilih?', { title: 'Buka Kunci Massal', type: 'gold', okText: 'Buka Kunci & Izinkan Lanjut' }).then(function(confirmed) {
            if (!confirmed) return;
            var fd = new FormData();
            fd.append('action', 'bulk_action'); fd.append('bulk_action_type', 'unfreeze'); fd.append('selected_ids', JSON.stringify(Array.from(selectedRows)));
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal unfreeze massal.'); return; }
                    toast('success', res.data.message);
                    selectedRows.clear();
                    updateBulkBar();
                    fetchList({ isBackground: true });
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    });

    var btnViolUnfreezeNow = document.getElementById('btnViolUnfreezeNow');
    if (btnViolUnfreezeNow) {
        btnViolUnfreezeNow.addEventListener('click', function() {
            var sId = this.dataset.siswa, aId = this.dataset.asesmen, t = this.dataset.tipe;
            if (!sId || !aId) return;
            btnViolUnfreezeNow.disabled = true;
            var fd = new FormData();
            fd.append('action', 'unfreeze'); fd.append('tipe_asesmen', t);
            var targetRow = currentLoadedRows.find(function(r) { return r.id_siswa == sId && r.id_asesmen == aId; });
            fd.append('id_akses', targetRow ? targetRow.id : 0);
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    btnViolUnfreezeNow.disabled = false;
                    if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal membuka kunci.'); return; }
                    toast('success', res.data.message);
                    closeModal('modalViolationLogs');
                    fetchList({ isBackground: true });
                })
                .catch(function() { btnViolUnfreezeNow.disabled = false; toast('error', 'Koneksi bermasalah.'); });
        });
    }

    var btnOpenExambro = document.getElementById('btnOpenExambroSettings');
    if (btnOpenExambro) {
        btnOpenExambro.addEventListener('click', function() {
            openModal('modalExambroSettings');
            var fd = new FormData();
            fd.append('action', 'get_exambro_settings');
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    if (res && res.success && res.data) {
                        var d = res.data;
                        document.getElementById('setWajibExambro').checked     = (d.wajib_exambro == 1);
                        document.getElementById('setBlokirScreenshot').checked = (d.blokir_screenshot == 1);
                        document.getElementById('setBlokirCopyPaste').checked  = (d.blokir_copy_paste == 1);
                        document.getElementById('setAutoFreeze').checked       = (d.auto_freeze_on_leave == 1);
                        document.getElementById('setAutoLogout').checked       = (d.auto_logout_on_leave == 1);
                        document.getElementById('setButuhBukaManual').checked  = (d.butuh_buka_manual_admin == 1);
                        document.getElementById('setMaksToleransi').value      = d.maks_toleransi_pelanggaran || 3;
                        document.getElementById('setPinPengawas').value        = d.pin_pengawas_kiosk || '123456';
                    }
                })
                .catch(function(){});
        });
    }

    var btnSaveExambro = document.getElementById('btnSaveExambroSettings');
    if (btnSaveExambro) {
        btnSaveExambro.addEventListener('click', function() {
            btnSaveExambro.disabled = true;
            btnSaveExambro.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="16"></iconify-icon> Menyimpan...';
            var fd = new FormData();
            fd.append('action', 'save_exambro_settings');
            fd.append('wajib_exambro', document.getElementById('setWajibExambro').checked ? 1 : 0);
            fd.append('blokir_screenshot', document.getElementById('setBlokirScreenshot').checked ? 1 : 0);
            fd.append('blokir_copy_paste', document.getElementById('setBlokirCopyPaste').checked ? 1 : 0);
            fd.append('auto_freeze_on_leave', document.getElementById('setAutoFreeze').checked ? 1 : 0);
            fd.append('auto_logout_on_leave', document.getElementById('setAutoLogout').checked ? 1 : 0);
            fd.append('butuh_buka_manual_admin', document.getElementById('setButuhBukaManual').checked ? 1 : 0);
            fd.append('maks_toleransi_pelanggaran', document.getElementById('setMaksToleransi').value);
            fd.append('pin_pengawas_kiosk', document.getElementById('setPinPengawas').value);
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    btnSaveExambro.disabled = false;
                    btnSaveExambro.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon> Simpan Kebijakan';
                    if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal menyimpan.'); return; }
                    toast('success', res.data.message);
                    closeModal('modalExambroSettings');
                })
                .catch(function() { btnSaveExambro.disabled = false; btnSaveExambro.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon> Simpan Kebijakan'; toast('error', 'Koneksi bermasalah.'); });
        });
    }

    document.getElementById('btnBulkDelete').addEventListener('click', function() {
        if (selectedRows.size === 0) return;
        confirmDialog('Hapus tiket akses ujian untuk ' + selectedRows.size + ' siswa terpilih?', { title: 'Hapus Tiket Massal', type: 'danger', okText: 'Hapus Permanen' }).then(function(confirmed) {
            if (!confirmed) return;
            var fd = new FormData();
            fd.append('action', 'bulk_action'); fd.append('bulk_action_type', 'delete'); fd.append('selected_ids', JSON.stringify(Array.from(selectedRows)));
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res) {
                    if (!res || !res.success) { toast('error', (res && res.data && res.data.message) || 'Gagal menghapus massal.'); return; }
                    toast('success', res.data.message);
                    selectedRows.clear();
                    updateBulkBar();
                    fetchList({ isBackground: true });
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    });

    function buildCardHtml(r) {
        if (!r) return '';
        var photoHtml = '';
        if (r.foto) photoHtml = '<img src="../../' + escapeHtml(r.foto) + '" alt="' + escapeHtml(r.nama_siswa) + '">';
        else photoHtml = '<div style="font-size: 28px; color: #CBD5E1; margin-top: 15px;"><iconify-icon icon="solar:user-bold"></iconify-icon></div><span style="font-size: 10px; color: #94A3B8;">3x4 Foto</span>';

        var tipeAsm = 'ASESMEN SUMATIF (CBT)';
        if (r.tipe_kategori === 'diagnostik') tipeAsm = 'ASESMEN DIAGNOSTIK' + (r.pertemuan_ke ? ' (KE-' + r.pertemuan_ke + ')' : '');
        else if (r.tipe_kategori === 'formatif') tipeAsm = 'ASESMEN FORMATIF' + (r.pertemuan_ke ? ' (KE-' + r.pertemuan_ke + ')' : '');
        else {
            var jName = (r.jenis_sumatif === 'Sumatif Tengah Semester') ? 'SUMATIF TENGAH SEMESTER (STS)' : ((r.jenis_sumatif === 'Sumatif Akhir Semester') ? 'SUMATIF AKHIR SEMESTER (SAS)' : (r.info_tipe || 'ASESMEN SUMATIF'));
            var semTxt = (r.semester ? r.semester.toUpperCase() : 'GANJIL');
            tipeAsm = jName.toUpperCase() + ' ' + semTxt + ' (CBT)';
        }

        return '<div class="ilmuna-exam-card">' +
            '<div class="ilmuna-exam-card__header"><div class="ilmuna-exam-card__logo"><iconify-icon icon="solar:diploma-bold"></iconify-icon></div><div class="ilmuna-exam-card__titles"><h4 class="ilmuna-exam-card__inst">ILMUNA LEARN — KARTU PESERTA CBT</h4><div class="ilmuna-exam-card__type">' + escapeHtml(tipeAsm) + ' &bull; TAHUN ' + escapeHtml(r.tahun_pelajaran || '2026/2027') + '</div></div></div>' +
            '<div class="ilmuna-exam-card__main"><div class="ilmuna-exam-card__photo">' + photoHtml + '</div><div class="ilmuna-exam-card__details">' +
                '<div class="ilmuna-exam-card__row"><span class="ilmuna-exam-card__label">No. Peserta:</span><span class="ilmuna-exam-card__val" style="color: #0D7A87; font-family: monospace;">' + escapeHtml(r.nomor_peserta) + '</span></div>' +
                '<div class="ilmuna-exam-card__row"><span class="ilmuna-exam-card__label">Nama Siswa:</span><span class="ilmuna-exam-card__val">' + escapeHtml(r.nama_siswa) + '</span></div>' +
                '<div class="ilmuna-exam-card__row"><span class="ilmuna-exam-card__label">NIS / NISN:</span><span class="ilmuna-exam-card__val">' + escapeHtml((r.nis || '-') + ' / ' + (r.nisn || '-')) + '</span></div>' +
                '<div class="ilmuna-exam-card__row"><span class="ilmuna-exam-card__label">Rombel Kelas:</span><span class="ilmuna-exam-card__val">' + escapeHtml(r.nama_kelas) + '</span></div>' +
                '<div class="ilmuna-exam-card__row"><span class="ilmuna-exam-card__label">Mata Pelajaran:</span><span class="ilmuna-exam-card__val">' + escapeHtml(r.nama_mata_pelajaran || 'Umum') + '</span></div>' +
                '<div class="ilmuna-exam-card__row"><span class="ilmuna-exam-card__label">Asesmen:</span><span class="ilmuna-exam-card__val">' + escapeHtml(r.judul_asesmen) + '</span></div>' +
            '</div></div>' +
            '<div class="ilmuna-exam-card__cred-box"><div class="ilmuna-exam-card__cred-item" style="flex: 1;"><small>Username Login Ujian</small><span>@' + escapeHtml(r.username_ujian) + '</span></div><div class="ilmuna-exam-card__cred-item" style="flex: 1; border-left: 1.5px dashed #52B2DC; padding-left: 12px;"><small>Password / PIN Ujian</small><span style="color: #0D7A87;">' + escapeHtml(r.password_ujian) + '</span></div></div>' +
            '<div class="ilmuna-exam-card__footer"><div>* Harap simpan kartu ini & jangan bagikan kredensial ke siswa lain. (Dicetak mandiri dengan kertas A5)</div><div class="ilmuna-exam-card__sign"><span>Proktor / Pengawas</span><div class="ilmuna-exam-card__sign-line"></div></div></div>' +
        '</div>';
    }

    /**
     * Download Berkas PDF Kartu Peserta Asesmen Sumatif (A4 dibagi 4)
     * Menggunakan metode Blob Download langsung ke peramban tanpa dialog window.print().
     */
    function downloadSumatifCardsBlob(rowIds) {
        if (state.tipe === 'sumatif' && !isSumatifFilterComplete()) {
            toast('warning', 'Silakan pilih Tahun Pelajaran, Semester, dan Jenis Sumatif terlebih dahulu.');
            return;
        }

        var targets = [];
        if (rowIds && rowIds.length > 0) {
            targets = currentLoadedRows.filter(function(r) { 
                return rowIds.indexOf(r.tipe_kategori + '_' + r.id) !== -1 || rowIds.indexOf(r.id) !== -1 || rowIds.indexOf(String(r.id)) !== -1; 
            });
        } else {
            targets = currentLoadedRows.filter(function(r) { return r.tipe_kategori === 'sumatif'; });
        }

        var targetIds = targets.map(function(r) { return r.id; });
        if (rowIds && rowIds.length > 0 && targetIds.length === 0) {
            targetIds = rowIds.map(function(id) {
                var s = String(id);
                return s.indexOf('sumatif_') === 0 ? parseInt(s.substring(8), 10) : parseInt(s, 10);
            }).filter(function(n) { return !isNaN(n) && n > 0; });
        }

        var count = targetIds.length;
        if (count === 0 && (!state.asesmen_id || state.asesmen_id <= 0) && (!state.kelas_id || state.kelas_id <= 0)) {
            toast('warning', 'Tidak ada data peserta ujian sumatif yang dipilih.');
            return;
        }

        var labelCount = count > 0 ? count + ' peserta' : 'seluruh peserta';
        toast('info', 'Sedang memproses berkas PDF Kartu Ujian Sumatif (A4 dibagi 4) untuk ' + labelCount + '... Mohon tunggu, proses ini membutuhkan beberapa detik.');

        var fd = new FormData();
        fd.append('action', 'download_sumatif_pdf');
        if (targetIds.length > 0) {
            fd.append('row_ids', JSON.stringify(targetIds));
        }
        if (state.asesmen_id && state.asesmen_id > 0) fd.append('asesmen_id', state.asesmen_id);
        if (state.kelas_id && state.kelas_id > 0)     fd.append('kelas_id', state.kelas_id);
        if (state.tahun_pelajaran && state.tahun_pelajaran !== 'all') fd.append('tahun_pelajaran', state.tahun_pelajaran);
        if (state.semester && state.semester !== 'all')               fd.append('semester', state.semester);
        if (state.jenis_sumatif && state.jenis_sumatif !== 'all')     fd.append('jenis_sumatif', state.jenis_sumatif);

        var controller = new AbortController();
        var timeoutId = setTimeout(function() { controller.abort(); }, 120000); // 120 detik

        fetch(AJAX_URL, {
            method: 'POST',
            body: fd,
            signal: controller.signal
        })
        .then(function(res) {
            clearTimeout(timeoutId);
            var contentType = res.headers.get('content-type') || '';
            if (contentType.indexOf('application/json') !== -1) {
                return res.json().then(function(err) {
                    throw new Error(err.message || 'Gagal memproses berkas PDF.');
                });
            }
            if (!res.ok) {
                throw new Error('Gagal mengunduh berkas PDF (HTTP ' + res.status + ').');
            }
            return res.blob();
        })
        .then(function(blob) {
            var blobUrl = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.style.display = 'none';
            a.href = blobUrl;
            var timeTag = new Date().toISOString().slice(0, 10);
            var dlName = (targets.length === 1 && targets[0].nama_siswa)
                ? 'Kartu_Ujian_SUMATIF_' + targets[0].nama_siswa.replace(/[^a-zA-Z0-9]/g, '_') + '_A4.pdf'
                : 'Kartu_Ujian_SUMATIF_A4_' + (count > 0 ? count + 'Siswa_' : '') + timeTag + '.pdf';
            a.download = dlName;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function() {
                window.URL.revokeObjectURL(blobUrl);
            }, 2500);
            toast('success', '✓ Berhasil mengunduh berkas PDF Kartu Ujian Sumatif (A4 dibagi 4)!');
        })
        .catch(function(err) {
            clearTimeout(timeoutId);
            if (err && err.name === 'AbortError') {
                toast('error', 'Waktu habis (120 detik). PDF terlalu besar atau server sibuk. Coba unduh per kelas yang lebih kecil.');
            } else {
                toast('error', err.message || 'Terjadi masalah saat membuat berkas PDF.');
            }
        });
    }

    /**
     * Download Berkas PDF Daftar Token Sesi Asesmen (CBT)
     * Menggunakan metode Blob Download langsung ke peramban tanpa dialog window.print().
     */
    function downloadTokenListBlob(customAsmId, customTipe) {
        var tipe = customTipe || state.tipe;
        var asmId = (customAsmId !== undefined && customAsmId !== null) ? parseInt(customAsmId, 10) : (state.asesmen_id || 0);

        if (tipe === 'sumatif' && !isSumatifFilterComplete() && (!asmId || asmId <= 0)) {
            toast('warning', 'Silakan pilih Tahun Pelajaran, Semester, dan Jenis Sumatif terlebih dahulu.');
            return;
        }

        var labelTipe = (tipe === 'sumatif') ? 'Asesmen Sumatif' : ((tipe === 'formatif') ? 'Asesmen Formatif' : ((tipe === 'diagnostik') ? 'Asesmen Diagnostik' : 'Remedial/Pengayaan'));
        var labelTarget = (asmId > 0) ? 'asesmen terpilih' : 'seluruh paket ' + labelTipe;
        toast('info', 'Sedang menyiapkan berkas PDF Daftar Token Sesi CBT untuk ' + labelTarget + '...');

        var fd = new FormData();
        fd.append('action', 'download_token_pdf');
        fd.append('tipe', tipe);
        if (asmId > 0) fd.append('asesmen_id', asmId);
        if (state.kelas_id && state.kelas_id > 0) fd.append('kelas_id', state.kelas_id);
        if (state.mapel_id && state.mapel_id > 0) fd.append('mapel_id', state.mapel_id);
        if (state.tahun_pelajaran && state.tahun_pelajaran !== 'all') fd.append('tahun_pelajaran', state.tahun_pelajaran);
        if (state.semester && state.semester !== 'all')               fd.append('semester', state.semester);
        if (state.jenis_sumatif && state.jenis_sumatif !== 'all')     fd.append('jenis_sumatif', state.jenis_sumatif);

        var controller = new AbortController();
        var timeoutId = setTimeout(function() { controller.abort(); }, 120000);

        fetch(AJAX_URL, {
            method: 'POST',
            body: fd,
            signal: controller.signal
        })
        .then(function(res) {
            clearTimeout(timeoutId);
            var contentType = res.headers.get('content-type') || '';
            if (contentType.indexOf('application/json') !== -1) {
                return res.json().then(function(err) {
                    throw new Error(err.message || 'Gagal memproses berkas PDF.');
                });
            }
            if (!res.ok) {
                throw new Error('Gagal mengunduh berkas PDF token (HTTP ' + res.status + ').');
            }
            return res.blob();
        })
        .then(function(blob) {
            var blobUrl = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.style.display = 'none';
            a.href = blobUrl;
            var timeTag = new Date().toISOString().slice(0, 10);
            var dlName = 'Daftar_Token_CBT_' + tipe.toUpperCase() + '_' + (asmId > 0 ? 'Asm_' + asmId + '_' : '') + timeTag + '.pdf';
            a.download = dlName;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function() {
                window.URL.revokeObjectURL(blobUrl);
            }, 2500);
            toast('success', '✓ Berhasil mengunduh berkas PDF Daftar Token Sesi CBT!');
        })
        .catch(function(err) {
            clearTimeout(timeoutId);
            if (err && err.name === 'AbortError') {
                toast('error', 'Waktu pembuatan PDF token habis (120 detik). Coba filter berdasarkan kelas tertentu.');
            } else {
                toast('error', err.message || 'Terjadi masalah saat membuat berkas PDF token.');
            }
        });
    }

    var btnDownloadTokenPdf = document.getElementById('btnDownloadTokenPdf');
    if (btnDownloadTokenPdf) {
        btnDownloadTokenPdf.addEventListener('click', function() {
            downloadTokenListBlob(state.asesmen_id || 0, state.tipe);
        });
    }

    var btnDownloadTokenFromModal = document.getElementById('btnDownloadTokenFromModal');
    if (btnDownloadTokenFromModal) {
        btnDownloadTokenFromModal.addEventListener('click', function() {
            var t = document.getElementById('tokenTipeAsesmen') ? document.getElementById('tokenTipeAsesmen').value : state.tipe;
            var selId = 0;
            if (t === 'sumatif') {
                var el = document.getElementById('tokenIdSumatif');
                selId = el ? parseInt(el.value, 10) : 0;
            } else if (t === 'formatif') {
                var el = document.getElementById('tokenIdFormatif');
                selId = el ? parseInt(el.value, 10) : 0;
            } else if (t === 'diagnostik') {
                var el = document.getElementById('tokenIdDiagnostik');
                selId = el ? parseInt(el.value, 10) : 0;
            } else if (t === 'remedial') {
                var el = document.getElementById('tokenIdRemedial');
                selId = el ? parseInt(el.value, 10) : 0;
            }
            downloadTokenListBlob(selId, t);
        });
    }

    function previewAndPrintCards(rowIds) {
        // Khusus Asesmen Sumatif: Otomatis gunakan metode blob download PDF Kartu Resmi (1 siswa 1 kartu)
        if (state.tipe === 'sumatif') {
            downloadSumatifCardsBlob(rowIds);
            return;
        }

        var targets = [];
        if (rowIds && rowIds.length > 0) targets = currentLoadedRows.filter(function(r) { return rowIds.indexOf(r.tipe_kategori + '_' + r.id) !== -1; });
        else targets = currentLoadedRows;
        if (!targets || targets.length === 0) { toast('warning', 'Tidak ada data kartu yang dapat dicetak.'); return; }

        var allSumatif = targets.every(function(r) { return r.tipe_kategori === 'sumatif'; });
        if (allSumatif) {
            downloadSumatifCardsBlob(targets.map(function(r) { return r.tipe_kategori + '_' + r.id; }));
            return;
        }

        var cardsHtml = '';
        targets.forEach(function(r) { cardsHtml += buildCardHtml(r); });
        document.getElementById('printPreviewWrap').innerHTML = cardsHtml;
        document.getElementById('printCardsList').innerHTML = cardsHtml;
        openModal('modalPrintCards');
    }

    var btnTriggerPrintAll = document.getElementById('btnTriggerPrintAll');
    if (btnTriggerPrintAll) btnTriggerPrintAll.addEventListener('click', function() { previewAndPrintCards(null); });

    var btnBulkPrint = document.getElementById('btnBulkPrint');
    if (btnBulkPrint) btnBulkPrint.addEventListener('click', function() { if (selectedRows.size === 0) return; previewAndPrintCards(Array.from(selectedRows)); });

    function doPrint() {
        if (state.tipe === 'sumatif') {
            closeModal('modalPrintCards');
            downloadSumatifCardsBlob(null);
            return;
        }
        window.print();
    }
    var btnExecPrint = document.getElementById('btnExecPrint');
    if (btnExecPrint) btnExecPrint.addEventListener('click', doPrint);
    var btnExecPrintFoot = document.getElementById('btnExecPrintFoot');
    if (btnExecPrintFoot) btnExecPrintFoot.addEventListener('click', doPrint);

    function escapeHtml(s) {
        if (!s) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    var timerIntervalId = null;
    function startLocalTimerEngine() {
        if (timerIntervalId) clearInterval(timerIntervalId);
        timerIntervalId = setInterval(function() {
            var activeTimers = document.querySelectorAll('.ilmuna-live-timer-container[data-active="1"]');
            if (!activeTimers || activeTimers.length === 0) return;
            var now = Date.now();
            activeTimers.forEach(function(el) {
                var targetEnd = parseInt(el.getAttribute('data-target-end'), 10);
                if (!targetEnd || isNaN(targetEnd)) return;
                var diffMs = targetEnd - now;
                var sec = Math.max(0, Math.ceil(diffMs / 1000));
                var disp = el.querySelector('.timer-display');
                if (sec <= 0) {
                    el.setAttribute('data-active', '0');
                    if (disp) disp.textContent = '0s (Habis)';
                    setTimeout(function() { checkEventChanges(); }, 1200);
                    return;
                }
                if (disp) disp.textContent = formatSec(sec);
            });
        }, 1000);
    }

    var eventWatchIntervalId = null;
    var lastEventVersion = '';

    function checkEventChanges() {
        if (document.hidden || state.isFetching) return;
        var fd = new FormData();
        fd.append('action', 'check_event');
        fd.append('v', lastEventVersion);
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                if (res && res.success && res.has_changes) {
                    lastEventVersion = res.v || '';
                    fetchList({ isBackground: true });
                }
            })
            .catch(function(){});
    }

    function startEventWatcher() {
        if (eventWatchIntervalId) clearInterval(eventWatchIntervalId);
        eventWatchIntervalId = setInterval(checkEventChanges, 4000);
    }

    window.addEventListener('focus', checkEventChanges);
    document.addEventListener('visibilitychange', function() { if (!document.hidden) checkEventChanges(); });

    var initialTab = (window.location.hash || '').replace('#', '').trim();
    if (['diagnostik', 'formatif', 'sumatif', 'remedial'].indexOf(initialTab) === -1) {
        try { initialTab = localStorage.getItem('ilmuna_access_active_tab') || ''; } catch(e) { initialTab = ''; }
    }
    if (['diagnostik', 'formatif', 'sumatif', 'remedial'].indexOf(initialTab) === -1) initialTab = 'diagnostik';

    switchTab(initialTab, true);
    startLocalTimerEngine();
    startEventWatcher();

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>