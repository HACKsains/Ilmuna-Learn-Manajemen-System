<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DATA MATA PELAJARAN & KURIKULUM MANAGEMENT
 * ==============================================================================
 * Sesuai Skema Database: Tabel mapel & mata_pelajaran & kelas_mata_pelajaran
 * Fitur: Lazy Load AJAX, WhatsApp-Style Live Search, Filter Kategori & Tingkat/Fase,
 *        Detail Rombel Terkait, Tambah/Edit Manual, Unduh Template, Import/Export Excel (.xlsx),
 *        Floating Bulk Action Bar (Hapus Masal, Ubah Kategori Masal, Ubah KKM Masal, Ubah Tingkat Masal, Ekspor Masal)
 * Tema Warna: Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Gold #F59E0B)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

if (php_sapi_name() !== 'cli') {
    requireRole('admin');
}

$pdo = getDBConnection();
$pageTitle = 'Data Mata Pelajaran & Kurikulum';
$pageIcon = 'solar:book-bookmark-bold';

// Fallback delegator: Jika ada request direct URL yang memuat action excel, teruskan ke subjects-excel.php
if (isset($_GET['action']) && in_array($_GET['action'], ['export_excel', 'download_template'], true)) {
    require __DIR__ . '/subjects-excel.php';
    exit;
}

// Pastikan tabel mapel tersedia
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `mapel` (
        `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `kode_mapel` varchar(50) NOT NULL UNIQUE,
        `nama_mapel` varchar(150) NOT NULL,
        `kategori` varchar(100) DEFAULT 'PAI & Budi Pekerti',
        `kkm` tinyint(3) NOT NULL DEFAULT 75,
        `tingkat` varchar(20) DEFAULT 'Semua',
        `deskripsi` text DEFAULT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

/**
 * Singkatan Resmi/Standar Mata Pelajaran
 */
function ilmuna_abbreviate_subject(string $nama): string {
    $namaClean = trim(preg_replace('/\(.*?\)/', '', $nama));
    $lower = strtolower($namaClean);

    $dict = [
        'pendidikan agama islam' => 'PAI',
        'agama islam'            => 'PAI',
        'pancasila'              => 'PPKN',
        'pendidikan pancasila'   => 'PPKN',
        'ppkn'                   => 'PPKN',
        'pkn'                    => 'PKN',
        'bahasa indonesia'       => 'BIND',
        'b. indonesia'           => 'BIND',
        'bahasa inggris'         => 'BING',
        'b. inggris'             => 'BING',
        'bahasa arab'            => 'BAR',
        'b. arab'                => 'BAR',
        'bahasa jepang'          => 'BJEP',
        'bahasa mandarin'        => 'BMAN',
        'bahasa jerman'          => 'BJER',
        'bahasa perancis'        => 'BPER',
        'bahasa sunda'           => 'BSUN',
        'bahasa jawa'            => 'BJAW',
        'matematika peminatan'   => 'MTKP',
        'matematika'             => 'MTK',
        'al-qur\'an hadits'      => 'QHD',
        'al-qur\'an hadis'       => 'QHD',
        'al-quran hadis'         => 'QHD',
        'qur\'an hadits'         => 'QHD',
        'al-qur\'an'             => 'QUR',
        'tajwid'                 => 'TJW',
        'akidah akhlak'          => 'AAK',
        'akidah'                 => 'AKD',
        'akhlak'                 => 'AKH',
        'fiqih'                  => 'FQH',
        'fikih'                  => 'FQH',
        'ushul fiqih'            => 'UFQ',
        'sejarah kebudayaan islam'=> 'SKI',
        'ski'                    => 'SKI',
        'tarikh'                 => 'TRK',
        'ilmu pengetahuan alam'  => 'IPA',
        'ilmu pengetahuan sosial' => 'IPS',
        'fisika'                 => 'FIS',
        'kimia'                  => 'KIM',
        'biologi'                => 'BIO',
        'ekonomi'                => 'EKO',
        'geografi'               => 'GEO',
        'sosiologi'              => 'SOS',
        'sejarah indonesia'      => 'SJRI',
        'sejarah peminatan'      => 'SJRP',
        'sejarah'                => 'SJR',
        'antropologi'            => 'ANT',
        'informatika'            => 'INF',
        'teknologi informasi'    => 'TIK',
        'seni budaya'            => 'SBD',
        'seni rupa'              => 'SRUP',
        'seni musik'             => 'SMUS',
        'seni tari'              => 'STAR',
        'seni teater'            => 'STEA',
        'pendidikan jasmani'     => 'PJOK',
        'pjok'                   => 'PJOK',
        'penjas'                 => 'PJOK',
        'bimbingan konseling'    => 'BK',
        'bimbingan dan konseling'=> 'BK',
        'prakarya dan kewirausahaan' => 'PKWU',
        'prakarya'               => 'PKR',
        'kewirausahaan'          => 'KWU',
        'muatan lokal'           => 'MULOK',
        'tasawuf'                => 'TSW',
        'ilmu kalam'             => 'KLM',
        'nahwu'                  => 'NHW',
        'shorof'                 => 'SHR',
        'balaghah'               => 'BLG',
    ];

    foreach ($dict as $key => $abbr) {
        if (str_contains($lower, $key)) {
            return $abbr;
        }
    }

    $words = preg_split('/[\s\-_,\.\/&]+/', $namaClean, -1, PREG_SPLIT_NO_EMPTY);
    $stopwords = ['dan', 'atau', 'pada', 'di', 'ke', 'dari', 'untuk', 'yang', 'dengan', '&'];
    $filteredWords = [];
    foreach ($words as $w) {
        $wClean = preg_replace('/[^a-zA-Z0-9]/', '', $w);
        if ($wClean !== '' && !in_array(strtolower($wClean), $stopwords, true)) {
            $filteredWords[] = $wClean;
        }
    }

    if (count($filteredWords) >= 2) {
        $abbr = '';
        foreach ($filteredWords as $w) {
            $abbr .= strtoupper(substr($w, 0, 1));
        }
        if (strlen($abbr) === 2 && strlen($filteredWords[0]) >= 2) {
            $abbr = strtoupper(substr($filteredWords[0], 0, 2) . substr($filteredWords[1], 0, 1));
        }
        return substr($abbr, 0, 4);
    } elseif (count($filteredWords) === 1) {
        $word = strtoupper($filteredWords[0]);
        if (strlen($word) <= 4) {
            return $word;
        }
        $vowelsRemoved = $word[0] . preg_replace('/[AEIOU]/', '', substr($word, 1));
        if (strlen($vowelsRemoved) >= 3) {
            return substr($vowelsRemoved, 0, 3);
        }
        return substr($word, 0, 3);
    }

    return 'MPL';
}

/**
 * Generator Kode Mapel Otomatis
 * Format: (singkatan mapel)-(2angkarandom)(idmapel)(3angkarandom)
 */
function ilmuna_generate_subject_code(PDO $pdo, string $namaMapel, int $idMapel = 0): string {
    $singkatan = ilmuna_abbreviate_subject($namaMapel);

    if ($idMapel <= 0) {
        try {
            $st = $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mapel'");
            $nextId = (int)$st->fetchColumn();
            if ($nextId <= 0) {
                $st = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM mapel");
                $nextId = (int)$st->fetchColumn();
            }
            $idMapel = $nextId > 0 ? $nextId : 1;
        } catch (Throwable $e) {
            $idMapel = 1;
        }
    }

    $chk = $pdo->prepare("SELECT id FROM mapel WHERE kode_mapel = ? AND id != ? LIMIT 1");

    for ($i = 0; $i < 10; $i++) {
        $rand2 = str_pad((string)random_int(10, 99), 2, '0', STR_PAD_LEFT);
        $rand3 = str_pad((string)random_int(100, 999), 3, '0', STR_PAD_LEFT);
        $kode = "{$singkatan}-{$rand2}{$idMapel}{$rand3}";

        $chk->execute([$kode, $idMapel]);
        if (!$chk->fetchColumn()) {
            return $kode;
        }
    }

    return "{$singkatan}-" . random_int(10, 99) . $idMapel . random_int(100, 999);
}

/* ==============================================================================
 * AJAX API ENDPOINTS
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'];

    // 0. GENERATE KODE MAPEL OTOMATIS: (singkatan)-(2random)(id)(3random)
    if ($act === 'generate_code') {
        $nama_mapel = trim($_POST['nama_mapel'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        $kode = ilmuna_generate_subject_code($pdo, $nama_mapel, $id);
        echo json_encode(['success' => true, 'data' => ['kode_mapel' => $kode]]);
        exit;
    }

    // Helper: Detect Badge Color by Kategori
    $getKategoriBadge = function($kategori) {
        $k = strtolower((string)$kategori);
        if (strpos($k, 'qur') !== false || strpos($k, 'hadits') !== false) return 'teal';
        if (strpos($k, 'akidah') !== false || strpos($k, 'akhlak') !== false) return 'navy';
        if (strpos($k, 'fiqih') !== false || strpos($k, 'fikih') !== false) return 'sky';
        if (strpos($k, 'sejarah') !== false || strpos($k, 'tarikh') !== false || strpos($k, 'ski') !== false) return 'gold';
        if (strpos($k, 'arab') !== false) return 'emerald';
        if (strpos($k, 'pai') !== false) return 'navy';
        return 'teal';
    };

    // Helper: Ambil Data 1 Baris Mapel Lengkap
    $getSubjectRow = function($pdo, $idMapel) use ($getKategoriBadge) {
        $stmt = $pdo->prepare("
            SELECT m.id, m.kode_mapel, m.nama_mapel, m.kategori, m.kkm, m.tingkat, m.deskripsi,
                (
                    SELECT COUNT(DISTINCT km.id_kelas)
                    FROM kelas_mata_pelajaran km
                    JOIN mata_pelajaran mp ON km.id_mata_pelajaran = mp.id
                    WHERE mp.kode_mapel = m.kode_mapel 
                       OR mp.nama_mata_pelajaran LIKE CONCAT('%', m.nama_mapel, '%')
                       OR m.nama_mapel LIKE CONCAT('%', mp.nama_mata_pelajaran, '%')
                ) AS total_kelas
            FROM mapel m
            WHERE m.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$idMapel]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['kategori_badge'] = $getKategoriBadge($row['kategori']);
        }
        return $row;
    };

    // 1. LIST MATA PELAJARAN (LAZY LOAD + SEARCH + FILTER + PAGINATION)
    if ($act === 'list') {
        $search     = trim($_POST['search'] ?? '');
        $f_kategori = trim($_POST['f_kategori'] ?? '');
        $f_tingkat  = trim($_POST['f_tingkat'] ?? '');
        $page       = max(1, (int)($_POST['page'] ?? 1));
        $per        = max(5, min(100, (int)($_POST['per_page'] ?? 20)));
        $offset     = ($page - 1) * $per;

        $where = '1=1';
        $params = [];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (m.kode_mapel LIKE ? OR m.nama_mapel LIKE ? OR m.kategori LIKE ? OR m.deskripsi LIKE ?)';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        if ($f_kategori !== '') {
            $where .= ' AND m.kategori = ?';
            $params[] = $f_kategori;
        }

        if ($f_tingkat !== '') {
            $where .= ' AND m.tingkat = ?';
            $params[] = $f_tingkat;
        }

        // Count total
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM mapel m WHERE {$where}");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $sql = "
            SELECT m.id, m.kode_mapel, m.nama_mapel, m.kategori, m.kkm, m.tingkat, m.deskripsi,
                (
                    SELECT COUNT(DISTINCT km.id_kelas)
                    FROM kelas_mata_pelajaran km
                    JOIN mata_pelajaran mp ON km.id_mata_pelajaran = mp.id
                    WHERE mp.kode_mapel = m.kode_mapel 
                       OR mp.nama_mata_pelajaran LIKE CONCAT('%', m.nama_mapel, '%')
                       OR m.nama_mapel LIKE CONCAT('%', mp.nama_mata_pelajaran, '%')
                ) AS total_kelas
            FROM mapel m
            WHERE {$where}
            ORDER BY m.id ASC
            LIMIT {$per} OFFSET {$offset}
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['kategori_badge'] = $getKategoriBadge($r['kategori']);
        }
        unset($r);

        // KPI Statistics Live
        $kpiTotal = (int)$pdo->query("SELECT COUNT(*) FROM mapel")->fetchColumn();
        $kpiAvgKkm = (float)$pdo->query("SELECT ROUND(AVG(kkm), 1) FROM mapel")->fetchColumn() ?: 75.0;
        $kpiPai = (int)$pdo->query("SELECT COUNT(*) FROM mapel WHERE kategori LIKE '%PAI%' OR kategori LIKE '%Agama%' OR kategori LIKE '%Qur%' OR kategori LIKE '%Hadits%' OR kategori LIKE '%Akidah%' OR kategori LIKE '%Fiqih%' OR kategori LIKE '%Tarikh%' OR kategori LIKE '%SKI%' OR kategori LIKE '%Arab%'")->fetchColumn();
        $kpiUmum = max(0, $kpiTotal - $kpiPai);

        echo json_encode([
            'success' => true,
            'data' => [
                'rows'  => $rows ?: [],
                'total' => $total,
                'page'  => $page,
                'per'   => $per,
                'pages' => max(1, (int)ceil($total / $per)),
                'kpis'  => [
                    'total'   => $kpiTotal,
                    'avg_kkm' => number_format($kpiAvgKkm, 1),
                    'pai'     => $kpiPai,
                    'umum'    => $kpiUmum
                ]
            ]
        ]);
        exit;
    }

    // 2. FILTER OPTIONS
    if ($act === 'get_filters') {
        $kategori_list = $pdo->query("SELECT DISTINCT kategori FROM mapel WHERE kategori IS NOT NULL AND kategori != '' ORDER BY kategori ASC")->fetchAll(PDO::FETCH_COLUMN);
        $tingkat_list  = $pdo->query("SELECT DISTINCT tingkat FROM mapel WHERE tingkat IS NOT NULL AND tingkat != '' ORDER BY tingkat ASC")->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode([
            'success' => true,
            'data' => [
                'kategori' => $kategori_list ?: ['PAI & Budi Pekerti', 'Al-Qur\'an & Hadits', 'Akidah Akhlak', 'Fiqih', 'Tarikh / SKI', 'Bahasa Arab'],
                'tingkat'  => $tingkat_list ?: ['Semua', 'X', 'XI', 'XII']
            ]
        ]);
        exit;
    }

    // 3. GET SINGLE SUBJECT (FOR EDIT)
    if ($act === 'get_single') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID mata pelajaran tidak valid.']]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, kode_mapel, nama_mapel, kategori, kkm, tingkat, deskripsi FROM mapel WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Data mata pelajaran tidak ditemukan.']]);
            exit;
        }

        echo json_encode(['success' => true, 'data' => ['row' => $row]]);
        exit;
    }

    // 4. SAVE / ADD MANUAL / EDIT SUBJECT
    if ($act === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $kode_mapel = strtoupper(trim($_POST['kode_mapel'] ?? ''));
        $nama_mapel = trim($_POST['nama_mapel'] ?? '');
        $kategori   = trim($_POST['kategori'] ?? 'PAI & Budi Pekerti');
        $kkm        = (int)($_POST['kkm'] ?? 75);
        if ($kkm <= 0) $kkm = 75;
        $tingkat    = trim($_POST['tingkat'] ?? 'Semua');
        $deskripsi  = trim($_POST['deskripsi'] ?? '');

        if ($nama_mapel === '') {
            echo json_encode(['success' => false, 'data' => ['message' => 'Nama mata pelajaran wajib diisi.']]);
            exit;
        }

        // Jika tambah baru atau kode otomatis
        if ($id <= 0 || $kode_mapel === '' || strpos($kode_mapel, 'OTOMATIS') !== false) {
            $kode_mapel = ilmuna_generate_subject_code($pdo, $nama_mapel, $id);
        }

        // Cek duplikasi kode_mapel jika manual/update
        $dupStmt = $pdo->prepare("SELECT id FROM mapel WHERE kode_mapel = ? AND id != ? LIMIT 1");
        $dupStmt->execute([$kode_mapel, $id]);
        if ($dupStmt->fetchColumn()) {
            // Auto regenerate jika duplikat
            $kode_mapel = ilmuna_generate_subject_code($pdo, $nama_mapel, $id);
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE mapel SET kode_mapel = ?, nama_mapel = ?, kategori = ?, kkm = ?, tingkat = ?, deskripsi = ? WHERE id = ?");
                $stmt->execute([$kode_mapel, $nama_mapel, $kategori, $kkm, $tingkat, $deskripsi, $id]);
                $msg = "Mata pelajaran '{$nama_mapel}' ({$kode_mapel}) berhasil diperbarui.";
                $mode = 'update';
            } else {
                $stmt = $pdo->prepare("INSERT INTO mapel (kode_mapel, nama_mapel, kategori, kkm, tingkat, deskripsi) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$kode_mapel, $nama_mapel, $kategori, $kkm, $tingkat, $deskripsi]);
                $id = (int)$pdo->lastInsertId();

                // Pastikan (idmapel) di kode_mapel persis menggunakan ID mapel yang baru saja digenerate database
                $kode_mapel = ilmuna_generate_subject_code($pdo, $nama_mapel, $id);
                $pdo->prepare("UPDATE mapel SET kode_mapel = ? WHERE id = ?")->execute([$kode_mapel, $id]);

                $msg = "Mata pelajaran baru '{$nama_mapel}' ({$kode_mapel}) berhasil ditambahkan.";
                $mode = 'insert';
            }

            // Sinkronisasi otomatis ke tabel `mata_pelajaran`
            try {
                $stmtSync = $pdo->prepare("
                    INSERT INTO mata_pelajaran (nama_mata_pelajaran, kode_mapel, kktp, tahun_pelajaran, semester)
                    VALUES (?, ?, ?, '2026/2027', 'Ganjil')
                    ON DUPLICATE KEY UPDATE kktp = VALUES(kktp), nama_mata_pelajaran = VALUES(nama_mata_pelajaran)
                ");
                $stmtSync->execute([$nama_mapel, $kode_mapel, (float)$kkm]);
            } catch (Exception $eSync) {}

            $savedRow = $getSubjectRow($pdo, $id);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => $msg,
                    'id'      => $id,
                    'mode'    => $mode,
                    'row'     => $savedRow
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menyimpan data: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 5. DELETE SINGLE SUBJECT
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID mata pelajaran tidak valid.']]);
            exit;
        }

        // Ambil info mapel
        $mapelInfo = $pdo->query("SELECT kode_mapel, nama_mapel FROM mapel WHERE id = " . $id)->fetch(PDO::FETCH_ASSOC);
        if (!$mapelInfo) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Mata pelajaran tidak ditemukan.']]);
            exit;
        }

        // Cek keterkaitan dengan rombel kelas
        $stmtCek = $pdo->prepare("
            SELECT COUNT(*) 
            FROM kelas_mata_pelajaran km
            JOIN mata_pelajaran mp ON km.id_mata_pelajaran = mp.id
            WHERE mp.kode_mapel = ? OR mp.nama_mata_pelajaran LIKE CONCAT('%', ?, '%')
        ");
        $stmtCek->execute([$mapelInfo['kode_mapel'], $mapelInfo['nama_mapel']]);
        $relCount = (int)$stmtCek->fetchColumn();

        if ($relCount > 0) {
            echo json_encode(['success' => false, 'data' => ['message' => "Mata pelajaran ini tidak bisa dihapus karena sedang diajarkan pada {$relCount} rombel kelas. Lepaskan relasi kelas terlebih dahulu."]]);
            exit;
        }

        try {
            $pdo->prepare("DELETE FROM mapel WHERE id = ?")->execute([$id]);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => "Mata pelajaran '{$mapelInfo['nama_mapel']}' berhasil dihapus.",
                    'id'      => $id
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menghapus: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 6. BULK DELETE SUBJECTS
    if ($act === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_unique(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada mata pelajaran yang dipilih.']]);
            exit;
        }

        $deleted = 0;
        $skipped = 0;
        $skipNames = [];
        $deletedIds = [];

        $stmtCek = $pdo->prepare("
            SELECT COUNT(*) 
            FROM kelas_mata_pelajaran km
            JOIN mata_pelajaran mp ON km.id_mata_pelajaran = mp.id
            WHERE mp.kode_mapel = ? OR mp.nama_mata_pelajaran LIKE CONCAT('%', ?, '%')
        ");

        foreach ($ids as $mid) {
            $mRow = $pdo->query("SELECT kode_mapel, nama_mapel FROM mapel WHERE id = " . $mid)->fetch(PDO::FETCH_ASSOC);
            if (!$mRow) continue;

            $stmtCek->execute([$mRow['kode_mapel'], $mRow['nama_mapel']]);
            $relCount = (int)$stmtCek->fetchColumn();

            if ($relCount > 0) {
                $skipNames[] = $mRow['nama_mapel'];
                $skipped++;
                continue;
            }

            $pdo->prepare("DELETE FROM mapel WHERE id = ?")->execute([$mid]);
            $deleted++;
            $deletedIds[] = $mid;
        }

        $msg = "Berhasil menghapus {$deleted} mata pelajaran.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} mapel dilewati karena masih tertaut di rombel kelas: " . implode(', ', array_slice($skipNames, 0, 3)) . ($skipped > 3 ? '...' : '') . ")";
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'message'     => $msg,
                'deleted'     => $deleted,
                'skipped'     => $skipped,
                'deleted_ids' => $deletedIds
            ]
        ]);
        exit;
    }

    // 7. BULK UPDATE KATEGORI
    if ($act === 'bulk_update_kategori') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        $kategori = trim($_POST['kategori'] ?? '');

        if (empty($ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada mata pelajaran yang dipilih.']]);
            exit;
        }
        if ($kategori === '') {
            echo json_encode(['success' => false, 'data' => ['message' => 'Kategori baru wajib diisi.']]);
            exit;
        }

        $in = implode(',', $ids);
        $stmt = $pdo->prepare("UPDATE mapel SET kategori = ? WHERE id IN ($in)");
        $stmt->execute([$kategori]);

        echo json_encode([
            'success' => true,
            'data' => [
                'message'     => "Berhasil memperbarui kategori menjadi '{$kategori}' pada " . count($ids) . " mata pelajaran.",
                'updated'     => count($ids),
                'updated_ids' => $ids
            ]
        ]);
        exit;
    }

    // 8. BULK UPDATE KKM / KKTP
    if ($act === 'bulk_update_kkm') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        $kkm = (int)($_POST['kkm'] ?? 75);
        if ($kkm <= 0 || $kkm > 100) $kkm = 75;

        if (empty($ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada mata pelajaran yang dipilih.']]);
            exit;
        }

        $in = implode(',', $ids);
        $stmt = $pdo->prepare("UPDATE mapel SET kkm = ? WHERE id IN ($in)");
        $stmt->execute([$kkm]);

        // Sinkronisasi ke mata_pelajaran
        try {
            $pdo->prepare("
                UPDATE mata_pelajaran mp
                JOIN mapel m ON (mp.kode_mapel = m.kode_mapel OR mp.nama_mata_pelajaran LIKE CONCAT('%', m.nama_mapel, '%'))
                SET mp.kktp = ?
                WHERE m.id IN ($in)
            ")->execute([(float)$kkm]);
        } catch (Exception $eSync) {}

        echo json_encode([
            'success' => true,
            'data' => [
                'message'     => "Berhasil memperbarui standar KKTP/KKM menjadi '{$kkm}' pada " . count($ids) . " mata pelajaran.",
                'updated'     => count($ids),
                'updated_ids' => $ids
            ]
        ]);
        exit;
    }

    // 9. BULK UPDATE TINGKAT / FASE
    if ($act === 'bulk_update_tingkat') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        $tingkat = trim($_POST['tingkat'] ?? 'Semua');

        if (empty($ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada mata pelajaran yang dipilih.']]);
            exit;
        }

        $in = implode(',', $ids);
        $stmt = $pdo->prepare("UPDATE mapel SET tingkat = ? WHERE id IN ($in)");
        $stmt->execute([$tingkat]);

        echo json_encode([
            'success' => true,
            'data' => [
                'message'     => "Berhasil memperbarui tingkat / fase menjadi '{$tingkat}' pada " . count($ids) . " mata pelajaran.",
                'updated'     => count($ids),
                'updated_ids' => $ids
            ]
        ]);
        exit;
    }

    // 10. GET ROMBEL KELAS TERKAIT
    if ($act === 'get_classes_by_subject') {
        $id = (int)($_POST['id'] ?? 0);
        $mRow = $pdo->query("SELECT kode_mapel, nama_mapel FROM mapel WHERE id = " . $id)->fetch(PDO::FETCH_ASSOC);

        if (!$mRow) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Mata pelajaran tidak ditemukan.']]);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT k.id, k.nama_kelas, k.tahun_pelajaran,
                COALESCE(u.nama, 'Belum Ditentukan') AS nama_guru,
                (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa
            FROM kelas_mata_pelajaran km
            JOIN kelas k ON km.id_kelas = k.id
            JOIN mata_pelajaran mp ON km.id_mata_pelajaran = mp.id
            LEFT JOIN guru_mata_pelajaran gmp ON km.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN users u ON gmp.id_user = u.id
            WHERE mp.kode_mapel = ? 
               OR mp.nama_mata_pelajaran LIKE CONCAT('%', ?, '%')
               OR ? LIKE CONCAT('%', mp.nama_mata_pelajaran, '%')
            ORDER BY k.nama_kelas ASC
        ");
        $stmt->execute([$mRow['kode_mapel'], $mRow['nama_mapel'], $mRow['nama_mapel']]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'mapel'   => $mRow,
                'classes' => $classes ?: []
            ]
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'data' => ['message' => 'Aksi tidak dikenali.']]);
    exit;
}

$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-classes.css') ? filemtime(BASE_PATH . '/assets/css/admin-classes.css') : '1.0';
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-classes.css?v=' . $cssVersion . '">';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<!-- ==========================================================================
 * LUMINA GOLD THEMED CRUD DATA MATA PELAJARAN (SESUAI PERSIS CLASSES.PHP)
 * ========================================================================== -->
<div class="ilmuna-kelas-app" id="appSubjects" data-ajax="<?= BASE_URL ?>/dashboard/admin/subjects" data-excel="<?= BASE_URL ?>/dashboard/admin/subjects-excel">

    <!-- TOOLBAR & FILTERS -->
    <div class="ilmuna-crud-toolbar">
        <div class="ilmuna-crud-toolbar__row">
            <div class="ilmuna-search-box">
                <iconify-icon icon="solar:magnifer-bold" width="18"></iconify-icon>
                <input type="text" id="inputSearchSubject" placeholder="Cari kode mapel, nama pelajaran, kategori, deskripsi...">
            </div>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSearchSubject">
                <iconify-icon icon="solar:magnifer-bold" width="16"></iconify-icon>
                Cari
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnResetSubject">
                <iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon>
                Reset
            </button>
        </div>

        <div class="ilmuna-crud-toolbar__row ilmuna-crud-toolbar__row--filters">
            <div class="ilmuna-filter-group">
                <label for="filterKategori">
                    <iconify-icon icon="solar:folder-with-files-bold" width="15"></iconify-icon>
                    Kategori Rumpun:
                </label>
                <select id="filterKategori" class="ilmuna-select-sm">
                    <option value="">Semua Kategori</option>
                </select>
            </div>
            <div class="ilmuna-filter-group">
                <label for="filterTingkat">
                    <iconify-icon icon="solar:diploma-bold" width="15"></iconify-icon>
                    Tingkat / Fase:
                </label>
                <select id="filterTingkat" class="ilmuna-select-sm">
                    <option value="">Semua Tingkat</option>
                    <option value="X">Kelas X (Fase E)</option>
                    <option value="XI">Kelas XI (Fase F)</option>
                    <option value="XII">Kelas XII (Fase F)</option>
                    <option value="Semua">Semua Tingkat (Umum)</option>
                </select>
            </div>
            <div class="ilmuna-filter-group">
                <label for="perPageSubject">
                    <iconify-icon icon="solar:list-bold" width="15"></iconify-icon>
                    Tampilkan:
                </label>
                <select id="perPageSubject" class="ilmuna-select-sm">
                    <option value="20">20 Data</option>
                    <option value="50">50 Data</option>
                    <option value="100">100 Data</option>
                </select>
            </div>
        </div>

        <div class="ilmuna-crud-toolbar__row ilmuna-crud-toolbar__row--actions">
            <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnAddSubject">
                <iconify-icon icon="solar:add-circle-bold" width="16"></iconify-icon>
                Tambah Mata Pelajaran
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnDownloadTemplateSubject">
                <iconify-icon icon="solar:file-download-bold" width="16"></iconify-icon>
                Unduh Format Excel
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnImportSubject">
                <iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon>
                Import Excel (.xlsx)
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--emerald" id="btnExportSubject">
                <iconify-icon icon="solar:file-send-bold" width="16"></iconify-icon>
                Export Excel (.xlsx)
            </button>
        </div>
    </div>

    <!-- CHIPS COUNTER -->
    <div class="ilmuna-crud-info" id="infoChipsSubject">
        <span class="ilmuna-info-chip"><iconify-icon icon="solar:book-bookmark-bold" width="14"></iconify-icon> Memuat data mata pelajaran...</span>
    </div>

    <!-- TABEL DATA MATA PELAJARAN -->
    <div class="ilmuna-crud-tablewrap">
        <table class="ilmuna-crud-table" id="tableSubjects">
            <thead>
                <tr>
                    <th style="width: 42px; text-align: center;">
                        <input type="checkbox" id="checkAllSubjects" class="ilmuna-bulk-cb" title="Pilih Semua di Halaman Ini">
                    </th>
                    <th style="width: 45px;">#</th>
                    <th style="width: 120px;">Kode Mapel</th>
                    <th>Nama Mata Pelajaran</th>
                    <th style="width: 170px;">Kategori Rumpun</th>
                    <th style="width: 120px;">Tingkat / Fase</th>
                    <th style="width: 110px; text-align: center;">KKTP / KKM</th>
                    <th style="width: 130px; text-align: center;">Rombel Terkait</th>
                    <th style="width: 180px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody id="tbodySubjects">
                <tr>
                    <td colspan="9" class="ilmuna-table-loading">
                        <iconify-icon icon="svg-spinners:ring-resize" width="28"></iconify-icon>
                        <span>Memuat data mata pelajaran...</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <div class="ilmuna-pagination" id="pagSubjects">
        <div class="ilmuna-pagination__info" id="pagInfoSubject">Menampilkan data...</div>
        <div class="ilmuna-pagination__btns" id="pagBtnsSubject"></div>
    </div>

</div>

<!-- ==========================================================================
 * MODALS & DIALOGS
 * ========================================================================== -->

<!-- MODAL 1: FORM TAMBAH / EDIT MATA PELAJARAN (MANUAL) -->
<div class="ilmuna-custom-modal" id="modalSubjectForm">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 600px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:book-bookmark-bold" width="22"></iconify-icon>
                <h3 id="modalSubjectFormTitle">Tambah Mata Pelajaran Baru</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <form id="formSubject" class="ilmuna-modal-body" onsubmit="return false;">
            <input type="hidden" name="id" value="0">

            <div style="display: grid; grid-template-columns: 1.8fr 1.2fr; gap: 14px;">
                <div class="ilmuna-form-field">
                    <label>Nama Mata Pelajaran <span class="req">*</span></label>
                    <input type="text" name="nama_mapel" id="inputNamaMapel" required placeholder="Contoh: Pendidikan Agama Islam & Budi Pekerti" class="ilmuna-input">
                    <small>Nama resmi kurikulum sekolah</small>
                </div>
                <div class="ilmuna-form-field">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                        <label style="margin-bottom: 0;">Kode Mapel</label>
                        <span class="ilmuna-badge ilmuna-badge--teal" style="font-size: 10px; padding: 2px 7px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                            <iconify-icon icon="solar:magic-stick-3-bold" width="12"></iconify-icon> Otomatis
                        </span>
                    </div>
                    <div style="display: flex; gap: 6px;">
                        <input type="text" name="kode_mapel" id="inputKodeMapel" readonly placeholder="[Otomatis dari Sistem]" class="ilmuna-input" style="background-color: #F8FAFC; color: #0D7A87; font-weight: 700; letter-spacing: 0.5px; cursor: default;">
                        <button type="button" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--outline" id="btnRegenerateCode" title="Generate ulang kode acak baru" style="padding: 0 10px; height: 38px; border-color: #CBD5E1; flex-shrink: 0;">
                            <iconify-icon icon="solar:restart-bold" width="16" style="color: #0D7A87;"></iconify-icon>
                        </button>
                    </div>
                    <small style="color: #64748B;">Format: (singkatan)-(2acak)(id)(3acak)</small>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1.5fr 1fr 1fr; gap: 14px; margin-top: 10px;">
                <div class="ilmuna-form-field">
                    <label>Kategori Rumpun <span class="req">*</span></label>
                    <input type="text" name="kategori" list="listKategoriDatalist" required placeholder="Pilih/Ketik..." class="ilmuna-input" value="PAI & Budi Pekerti">
                    <datalist id="listKategoriDatalist">
                        <option value="PAI & Budi Pekerti">
                        <option value="Al-Qur'an & Hadits">
                        <option value="Akidah Akhlak">
                        <option value="Fiqih">
                        <option value="Tarikh / SKI">
                        <option value="Bahasa Arab">
                        <option value="Umum & Sains">
                        <option value="Muatan Lokal">
                    </datalist>
                </div>
                <div class="ilmuna-form-field">
                    <label>Tingkat / Fase <span class="req">*</span></label>
                    <select name="tingkat" class="ilmuna-input">
                        <option value="Semua">Semua</option>
                        <option value="X">Kelas X (Fase E)</option>
                        <option value="XI">Kelas XI (Fase F)</option>
                        <option value="XII">Kelas XII (Fase F)</option>
                    </select>
                </div>
                <div class="ilmuna-form-field">
                    <label>KKTP / KKM <span class="req">*</span></label>
                    <input type="number" name="kkm" required min="50" max="100" value="75" class="ilmuna-input" style="font-weight: 800; color: #D97706;">
                </div>
            </div>

            <div class="ilmuna-form-field" style="margin-top: 10px;">
                <label>Deskripsi Capaian / Catatan</label>
                <textarea name="deskripsi" rows="3" class="ilmuna-input" placeholder="Tuliskan lingkup materi, capaian pembelajaran (CP), atau catatan pengampu..." style="resize: vertical;"></textarea>
            </div>
        </form>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSaveSubject">
                <iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon>
                Simpan Mata Pelajaran
            </button>
        </div>
    </div>
</div>

<!-- MODAL 2: DETAIL DAFTAR ROMBEL KELAS TERKAIT -->
<div class="ilmuna-custom-modal" id="modalSubjectClasses">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 650px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:notebook-bookmark-bold" width="22"></iconify-icon>
                <h3>Rombel Kelas — <span id="modalSubjectClassesName" style="color: var(--lumina-teal);">—</span></h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <p style="margin: 0 0 12px; font-size: 13px; color: #64748B;">
                Daftar rombel belajar yang menautkan mata pelajaran ini beserta guru pengampu aktif:
            </p>
            <div class="ilmuna-crud-tablewrap" style="max-height: 320px; overflow-y: auto;">
                <table class="ilmuna-crud-table" style="font-size: 13px;">
                    <thead>
                        <tr>
                            <th style="width: 45px;">#</th>
                            <th>Nama Rombel / Kelas</th>
                            <th>Tahun Ajaran</th>
                            <th>Guru Pengampu</th>
                            <th style="text-align: center;">Siswa</th>
                        </tr>
                    </thead>
                    <tbody id="tbodySubjectClasses">
                        <tr><td colspan="5" class="ilmuna-table-loading"><span>Memuat rombel kelas...</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Tutup</button>
        </div>
    </div>
</div>

<!-- MODAL 3: IMPORT EXCEL (.xlsx) -->
<div class="ilmuna-custom-modal" id="modalSubjectImport">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 580px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:cloud-upload-bold" width="22"></iconify-icon>
                <h3>Import Data Mapel dari Excel (.xlsx)</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <div style="margin-bottom: 16px;">
                <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnModalDownloadTemplateSubject" style="font-size: 12.5px;">
                    <iconify-icon icon="solar:file-download-bold" width="14"></iconify-icon>
                    Unduh Format Template Excel
                </button>
            </div>
            <div class="ilmuna-file-dropzone" id="subjectFileDropzone">
                <iconify-icon icon="solar:cloud-upload-bold" width="38"></iconify-icon>
                <label for="subjectImportFile" style="cursor: pointer; font-weight: 700; color: #1D3B5E; margin-top: 8px; display: block;">
                    Pilih File Excel (.xlsx) atau Tarik ke Sini
                </label>
                <span style="font-size: 12px; color: #64748B;">Hanya format berkas .xlsx (Maks. 10MB)</span>
                <div id="subjectSelectedFileName" style="display: none; margin-top: 10px; font-weight: 600; color: #0D7A87; font-size: 12.5px; background: rgba(13,122,135,0.08); padding: 6px 12px; border-radius: 8px;"></div>
                <input type="file" id="subjectImportFile" accept=".xlsx" class="ilmuna-file-input">
            </div>
            <div id="subjectImportLog" class="ilmuna-import-log" style="display: none;"></div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Tutup</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnDoImportSubject">
                <iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon>
                Mulai Import
            </button>
        </div>
    </div>
</div>

<!-- MODAL 4: BULK UPDATE KATEGORI -->
<div class="ilmuna-custom-modal" id="modalBulkKategori">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 460px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:folder-with-files-bold" width="22"></iconify-icon>
                <h3>Ubah Kategori Rumpun Masal</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <p style="margin: 0 0 12px; font-size: 13px; color: #64748B;">
                Anda akan memperbarui kategori untuk <strong id="bulkKategoriCountText" style="color: #1D3B5E;">0</strong> mapel terpilih.
            </p>
            <div class="ilmuna-form-field">
                <label>Kategori Rumpun Baru <span class="req">*</span></label>
                <input type="text" id="inputBulkKategori" list="listKategoriDatalist" placeholder="Pilih atau ketik kategori baru..." class="ilmuna-input" value="PAI & Budi Pekerti">
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSubmitBulkKategori">
                <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                Terapkan Kategori
            </button>
        </div>
    </div>
</div>

<!-- MODAL 5: BULK UPDATE KKM / KKTP -->
<div class="ilmuna-custom-modal" id="modalBulkKkm">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 460px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:medal-ribbons-star-bold" width="22"></iconify-icon>
                <h3>Ubah Standar KKM / KKTP Masal</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <p style="margin: 0 0 12px; font-size: 13px; color: #64748B;">
                Anda akan memperbarui standar KKM / KKTP untuk <strong id="bulkKkmCountText" style="color: #1D3B5E;">0</strong> mapel terpilih.
            </p>
            <div class="ilmuna-form-field">
                <label>Nilai Standar KKTP/KKM Baru <span class="req">*</span></label>
                <input type="number" id="inputBulkKkm" min="50" max="100" value="75" class="ilmuna-input" style="font-size: 18px; font-weight: 800; color: #D97706;">
                <small>Nilai batas ketuntasan belajar Kurikulum Merdeka (0 - 100)</small>
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSubmitBulkKkm">
                <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                Terapkan Standar KKTP
            </button>
        </div>
    </div>
</div>

<!-- MODAL 6: BULK UPDATE TINGKAT / FASE -->
<div class="ilmuna-custom-modal" id="modalBulkTingkat">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 460px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:diploma-bold" width="22"></iconify-icon>
                <h3>Ubah Tingkat / Fase Masal</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <p style="margin: 0 0 12px; font-size: 13px; color: #64748B;">
                Anda akan memperbarui tingkat / fase untuk <strong id="bulkTingkatCountText" style="color: #1D3B5E;">0</strong> mapel terpilih.
            </p>
            <div class="ilmuna-form-field">
                <label>Tingkat / Fase Baru <span class="req">*</span></label>
                <select id="inputBulkTingkat" class="ilmuna-input">
                    <option value="Semua">Semua Tingkat</option>
                    <option value="X">Kelas X (Fase E)</option>
                    <option value="XI">Kelas XI (Fase F)</option>
                    <option value="XII">Kelas XII (Fase F)</option>
                </select>
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSubmitBulkTingkat">
                <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                Terapkan Tingkat
            </button>
        </div>
    </div>
</div>

<!-- FLOATING BULK ACTION BAR -->
<div class="ilmuna-bulk-bar" id="bulkBarSubjects">
    <div class="ilmuna-bulk-bar__counter">
        <span class="ilmuna-bulk-pill" id="bulkCountSubjects">0</span>
        <span class="ilmuna-bulk-label">Mapel Terpilih</span>
    </div>
    <div class="ilmuna-bulk-bar__divider"></div>
    <div class="ilmuna-bulk-bar__actions">
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--gold" id="btnBulkActionKategori">
            <iconify-icon icon="solar:folder-with-files-bold" width="16"></iconify-icon>
            <span>Ubah Kategori</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--gold" id="btnBulkActionKkm">
            <iconify-icon icon="solar:medal-ribbons-star-bold" width="16"></iconify-icon>
            <span>Ubah KKTP</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--gold" id="btnBulkActionTingkat">
            <iconify-icon icon="solar:diploma-bold" width="16"></iconify-icon>
            <span>Ubah Tingkat</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--emerald" id="btnBulkExportSubjects">
            <iconify-icon icon="solar:file-send-bold" width="16"></iconify-icon>
            <span>Ekspor Excel</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--danger" id="btnBulkDeleteSubjects">
            <iconify-icon icon="solar:trash-bin-trash-bold" width="16"></iconify-icon>
            <span>Hapus Masal</span>
        </button>
    </div>
    <button type="button" class="ilmuna-bulk-bar__close" id="btnBulkCloseSubjects" title="Batalkan Pilihan">
        <iconify-icon icon="solar:close-circle-bold" width="20"></iconify-icon>
    </button>
</div>

<!-- TOAST & CONFIRM DIALOGS -->
<div id="subjectCrudToast" class="ilmuna-toast-stack"></div>
<div id="subjectCrudConfirm" class="ilmuna-confirm-overlay">
    <div class="ilmuna-confirm-dialog">
        <div class="ilmuna-confirm-icon" id="subjectConfirmIcon">
            <iconify-icon icon="solar:danger-triangle-bold" width="30"></iconify-icon>
        </div>
        <h3 class="ilmuna-confirm-title" id="subjectConfirmTitle">Konfirmasi</h3>
        <p class="ilmuna-confirm-message" id="subjectConfirmMessage"></p>
        <div class="ilmuna-confirm-actions">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="subjectConfirmCancel">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="subjectConfirmOk">Ya, Lanjutkan</button>
        </div>
    </div>
</div>

<!-- ==========================================================================
 * CLIENT-SIDE LOGIC (PURE AJAX SPA — SESUAI PERSIS CLASSES.PHP)
 * ========================================================================== -->
<script>
(function(){
    'use strict';

    var appEl = document.getElementById('appSubjects');
    var AJAX_URL = appEl.getAttribute('data-ajax') || 'subjects.php';
    var EXCEL_URL = appEl.getAttribute('data-excel') || 'subjects-excel.php';

    // State Application
    var state = {
        search: '',
        f_kategori: '',
        f_tingkat: '',
        page: 1,
        per_page: 20,
        total: 0,
        pages: 1,
        loading: false
    };

    var selectedSubjects = new Set();

    // Floating Bulk Bar Update
    function updateBulkBar() {
        var bar = document.getElementById('bulkBarSubjects');
        var counter = document.getElementById('bulkCountSubjects');
        var checkAll = document.getElementById('checkAllSubjects');

        var size = selectedSubjects.size;
        counter.textContent = size;

        if (size > 0) {
            bar.classList.add('show');
        } else {
            bar.classList.remove('show');
        }

        var pageCheckboxes = document.querySelectorAll('.ilmuna-subject-cb');
        if (pageCheckboxes.length > 0) {
            var allChecked = true;
            pageCheckboxes.forEach(function(cb){
                if (!cb.checked) allChecked = false;
            });
            checkAll.checked = allChecked;
            checkAll.indeterminate = (!allChecked && size > 0);
        } else {
            checkAll.checked = false;
            checkAll.indeterminate = false;
        }
    }

    // Toast Notification
    function toast(type, msg) {
        var normType = (type === 'danger' || type === 'err' || type === 'error') ? 'error' : ((type === 'warn' || type === 'warning') ? 'warning' : (type === 'info' ? 'info' : 'success'));
        var titleMap = { success: 'Berhasil', error: 'Gagal', warning: 'Peringatan', info: 'Informasi' };
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, titleMap[normType] || 'Pemberitahuan', msg, 4500);
        }
        var stack = document.getElementById('subjectCrudToast');
        var item = document.createElement('div');
        item.className = 'ilmuna-toast ilmuna-toast--' + type;

        var icons = {
            success: 'solar:check-circle-bold',
            error:   'solar:danger-triangle-bold',
            warning: 'solar:shield-warning-bold',
            info:    'solar:info-circle-bold'
        };
        item.innerHTML = '<iconify-icon icon="' + (icons[type] || icons.info) + '" width="20"></iconify-icon>' +
                         '<span>' + escHtml(msg) + '</span>';
        stack.appendChild(item);

        setTimeout(function(){
            item.classList.add('is-leaving');
            setTimeout(function(){ item.remove(); }, 300);
        }, 3600);
    }

    // Confirm Dialog
    function confirmDialog(message, options) {
        options = options || {};
        var type = (options.type === 'danger' || options.type === 'delete' || options.type === 'error') ? 'error' : (options.type || 'warning');
        if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
            return Ilmuna.confirm(type, options.title || 'Konfirmasi Tindakan', message || 'Apakah Anda yakin?', options.okText || 'Ya, Lanjutkan', options.cancelText || 'Batal');
        }
        return new Promise(function(resolve) {
            options = options || {};
            var overlay = document.getElementById('subjectCrudConfirm');
            var msgEl   = document.getElementById('subjectConfirmMessage');
            var titleEl = document.getElementById('subjectConfirmTitle');
            var okBtn   = document.getElementById('subjectConfirmOk');
            var ccBtn   = document.getElementById('subjectConfirmCancel');

            msgEl.textContent   = message || 'Apakah Anda yakin?';
            titleEl.textContent = options.title  || 'Konfirmasi Tindakan';
            okBtn.textContent   = options.okText || 'Ya, Lanjutkan';
            ccBtn.textContent   = options.cancelText || 'Batal';

            overlay.classList.add('show');

            function cleanup() {
                okBtn.removeEventListener('click', onOk);
                ccBtn.removeEventListener('click', onCancel);
                overlay.removeEventListener('click', onOv);
                document.removeEventListener('keydown', onKey);
            }
            function onOk()    { cleanup(); overlay.classList.remove('show'); resolve(true); }
            function onCancel(){ cleanup(); overlay.classList.remove('show'); resolve(false); }
            function onOv(e)   { if (e.target === overlay) onCancel(); }
            function onKey(e)  { if (e.key === 'Escape') onCancel(); if (e.key === 'Enter') onOk(); }

            okBtn.addEventListener('click', onOk);
            ccBtn.addEventListener('click', onCancel);
            overlay.addEventListener('click', onOv);
            document.addEventListener('keydown', onKey);
        });
    }

    // Modal Helpers
    function openModal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.add('is-open');
    }
    function closeModal(id) {
        var m = document.getElementById(id);
        if (m) m.classList.remove('is-open');
    }
    document.querySelectorAll('.ilmuna-custom-modal').forEach(function(m){
        m.addEventListener('click', function(e){
            if (e.target.dataset.close === '1' || e.target.closest('[data-close="1"]') || e.target.closest('.ilmuna-modal-close')) {
                m.classList.remove('is-open');
            }
        });
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            document.querySelectorAll('.ilmuna-custom-modal.is-open').forEach(function(m){
                m.classList.remove('is-open');
            });
        }
    });

    // Escape HTML
    function escHtml(s) {
        return String(s || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Download Blob Utility (Native & Responsive)
    function downloadBlob(url, defaultFilename, btn, loadingText) {
        var origHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<iconify-icon icon="svg-spinners:ring-resize" width="16"></iconify-icon> ' + (loadingText || 'Mengunduh...');
        }

        fetch(url)
            .then(function(res){
                if (!res.ok) throw new Error('Gagal mengunduh berkas (Kode ' + res.status + ')');
                var disposition = res.headers.get('Content-Disposition') || '';
                var filename = defaultFilename;
                var match = disposition.match(/filename="?([^"]+)"?/);
                if (match && match[1]) filename = match[1];
                return res.blob().then(function(b){ return { blob: b, filename: filename }; });
            })
            .then(function(data){
                var blobUrl = window.URL.createObjectURL(data.blob);
                var a = document.createElement('a');
                a.href = blobUrl;
                a.download = data.filename;
                document.body.appendChild(a);
                a.click();
                setTimeout(function(){
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(blobUrl);
                }, 400);
            })
            .catch(function(err){
                toast('error', err.message || 'Terjadi kesalahan saat mengunduh berkas.');
            })
            .finally(function(){
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                }
            });
    }

    // Build Table Row Cells HTML
    function buildSubjectRowCellsHtml(r, no, isChecked) {
        var html = '';
        html += '<td style="text-align: center;"><input type="checkbox" class="ilmuna-subject-cb" value="' + r.id + '" ' + (isChecked ? 'checked' : '') + '></td>';
        html += '<td class="cell-num">' + no + '</td>';
        html += '<td><span class="cell-pill cell-pill--tahun" style="font-family: monospace; font-weight: 800;">' + escHtml(r.kode_mapel || '-') + '</span></td>';
        
        html += '<td class="cell-nama">' +
                '<strong>' + escHtml(r.nama_mapel || '-') + '</strong>' +
                (r.deskripsi ? '<div style="font-size: 11.5px; color: #64748B; margin-top: 2px; max-width: 360px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="' + escHtml(r.deskripsi) + '">' + escHtml(r.deskripsi) + '</div>' : '') +
                '</td>';

        html += '<td><span class="cell-pill cell-pill--' + (r.kategori_badge || 'teal') + '">' + escHtml(r.kategori || 'Umum') + '</span></td>';

        var t = r.tingkat || 'Semua';
        var tBadge = (t === 'X') ? 'sky' : ((t === 'XI') ? 'teal' : ((t === 'XII') ? 'navy' : 'gold'));
        var tLabel = (t === 'X') ? 'Kelas X (Fase E)' : ((t === 'XI') ? 'Kelas XI (Fase F)' : ((t === 'XII') ? 'Kelas XII (Fase F)' : 'Semua (Umum)'));
        html += '<td><span class="cell-pill cell-pill--' + tBadge + '">' + escHtml(tLabel) + '</span></td>';

        html += '<td style="text-align: center;"><span class="cell-pill cell-pill--gold" style="font-weight: 800; font-size: 13px;">' + (r.kkm || 75) + '</span></td>';

        html += '<td style="text-align: center;"><button type="button" class="cell-count cell-count--matkul" data-act="classes" data-id="' + r.id + '" title="Klik untuk melihat daftar rombel kelas" style="border:none; cursor:pointer;"><iconify-icon icon="solar:notebook-bookmark-bold" width="13"></iconify-icon> ' + (r.total_kelas || 0) + ' Rombel</button></td>';

        html += '<td><div class="row-actions">';
        html += '<button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" data-act="classes" data-id="' + r.id + '" title="Lihat Rombel Terkait"><iconify-icon icon="solar:eye-bold" width="13"></iconify-icon> Rombel</button>';
        html += '<button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" data-act="edit" data-id="' + r.id + '" title="Edit Mata Pelajaran"><iconify-icon icon="solar:pen-bold" width="13"></iconify-icon> Edit</button>';
        html += '<button type="button" class="ilmuna-btn ilmuna-btn--danger ilmuna-btn--sm" data-act="delete" data-id="' + r.id + '" title="Hapus Mata Pelajaran"><iconify-icon icon="solar:trash-bin-trash-bold" width="13"></iconify-icon> Hapus</button>';
        html += '</div></td>';

        return html;
    }

    // In-Place Realtime Manipulation Helpers
    function insertRowInPlace(r) {
        var tbody = document.getElementById('tbodySubjects');
        var emptyRow = tbody.querySelector('.ilmuna-empty-row');
        if (emptyRow) tbody.innerHTML = '';

        var tr = document.createElement('tr');
        tr.setAttribute('data-id', r.id);
        tr.className = 'ilmuna-row-slide-in ilmuna-row-highlight-new';
        tr.innerHTML = buildSubjectRowCellsHtml(r, 1, false);

        var firstTr = tbody.querySelector('tr[data-id]');
        if (firstTr) {
            tbody.insertBefore(tr, firstTr);
        } else {
            var sentinel = document.getElementById('lazySentinelSubjects');
            if (sentinel) tbody.insertBefore(tr, sentinel);
            else tbody.appendChild(tr);
        }

        renumberSubjectRows();
        state.total++;
        renderInfo();
        renderPagination();
        try { tr.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch(e) {}
    }

    function updateRowInPlace(r) {
        var tbody = document.getElementById('tbodySubjects');
        var tr = tbody.querySelector('tr[data-id="' + r.id + '"]');
        if (tr) {
            var curNum = tr.querySelector('.cell-num') ? tr.querySelector('.cell-num').textContent : '1';
            var isChecked = selectedSubjects.has(parseInt(r.id, 10));
            tr.innerHTML = buildSubjectRowCellsHtml(r, curNum, isChecked);
            tr.classList.remove('ilmuna-row-highlight-updated');
            void tr.offsetWidth;
            tr.classList.add('ilmuna-row-highlight-updated');
            updateBulkBar();
        } else {
            insertRowInPlace(r);
        }
    }

    function removeRowInPlace(id) {
        var tbody = document.getElementById('tbodySubjects');
        var tr = tbody.querySelector('tr[data-id="' + id + '"]');
        if (tr) {
            tr.style.transition = 'all 0.35s ease';
            tr.style.opacity = '0';
            tr.style.transform = 'translateX(35px)';
            setTimeout(function(){
                if (tr.parentNode) tr.parentNode.removeChild(tr);
                renumberSubjectRows();
                var remaining = tbody.querySelectorAll('tr[data-id]');
                if (remaining.length === 0) renderRows([]);
            }, 350);
            selectedSubjects.delete(parseInt(id, 10));
            updateBulkBar();
            state.total = Math.max(0, state.total - 1);
            renderInfo();
            renderPagination();
        }
    }

    function renumberSubjectRows() {
        var tbody = document.getElementById('tbodySubjects');
        var rows = tbody.querySelectorAll('tr[data-id]');
        rows.forEach(function(row, idx){
            var numCell = row.querySelector('.cell-num');
            if (numCell) numCell.textContent = idx + 1;
        });
    }

    // Lazy Load Observer
    var lazyObserver = null;
    function initLazyLoad() {
        if (!('IntersectionObserver' in window)) return;
        var sentinel = document.getElementById('lazySentinelSubjects');
        if (!sentinel) return;
        if (lazyObserver) lazyObserver.disconnect();
        lazyObserver = new IntersectionObserver(function(entries){
            entries.forEach(function(entry){
                if (entry.isIntersecting && !state.loading && state.page < state.pages) {
                    state.page++;
                    fetchList({ append: true });
                }
            });
        }, { rootMargin: '250px' });
        lazyObserver.observe(sentinel);
    }

    // Fetch Table List
    function fetchList(opts) {
        opts = opts || {};
        var tbody = document.getElementById('tbodySubjects');
        if (state.loading) return;
        state.loading = true;

        var sentinel = document.getElementById('lazySentinelSubjects');

        if (opts.append) {
            if (sentinel) sentinel.style.display = '';
        } else if (opts.isLiveSearch) {
            tbody.style.opacity = '0.7';
            tbody.style.transition = 'opacity 0.15s ease';
        } else if (!opts.silent) {
            tbody.innerHTML =
                '<tr><td colspan="9" class="ilmuna-table-loading">' +
                '<iconify-icon icon="svg-spinners:ring-resize" width="28"></iconify-icon>' +
                '<span>Memuat data mata pelajaran...</span></td></tr>';
        }

        var fd = new FormData();
        fd.append('action', 'list');
        fd.append('search', state.search);
        fd.append('f_kategori', state.f_kategori);
        fd.append('f_tingkat', state.f_tingkat);
        fd.append('page', state.page);
        fd.append('per_page', state.per_page);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                state.loading = false;
                tbody.style.opacity = '1';
                if (!res || !res.success) {
                    if (!opts.append) {
                        tbody.innerHTML = '<tr><td colspan="9" class="ilmuna-table-loading" style="color:#DC2626;">Gagal memuat data mapel.</td></tr>';
                    }
                    toast('error', (res && res.data && res.data.message) || 'Gagal memuat data.');
                    return;
                }
                var d = res.data;
                state.total = d.total;
                state.pages = d.pages;
                renderRows(d.rows, opts);
                renderPagination();
                renderInfo();


            })
            .catch(function(){
                state.loading = false;
                tbody.style.opacity = '1';
                if (!opts.append) {
                    tbody.innerHTML = '<tr><td colspan="9" class="ilmuna-table-loading" style="color:#DC2626;">Koneksi bermasalah.</td></tr>';
                }
                toast('error', 'Koneksi ke server terputus.');
            });
    }

    // Render Table Rows
    function renderRows(rows, opts) {
        opts = opts || {};
        var tbody = document.getElementById('tbodySubjects');
        if (!opts.append && (!rows || !rows.length)) {
            tbody.innerHTML =
                '<tr><td colspan="9"><div class="ilmuna-empty-row">' +
                '<iconify-icon icon="solar:book-bookmark-bold" width="46"></iconify-icon>' +
                '<p>Belum ada data mata pelajaran</p>' +
                '<small>Tambahkan mata pelajaran baru atau sesuaikan filter pencarian.</small>' +
                '</div></td></tr>';
            updateBulkBar();
            return;
        }

        var html = '';
        var startNum = opts.append ? tbody.querySelectorAll('tr[data-id]').length : (state.page - 1) * state.per_page;
        rows.forEach(function(r, i){
            var no = startNum + i + 1;
            var isChecked = selectedSubjects.has(parseInt(r.id, 10));
            html += '<tr data-id="' + r.id + '" class="ilmuna-row-slide-in ' + (isChecked ? 'is-selected' : '') + '">';
            html += buildSubjectRowCellsHtml(r, no, isChecked);
            html += '</tr>';
        });

        var sentinelHtml = '<tr id="lazySentinelSubjects" class="ilmuna-table-sentinel" style="display:' + (state.page < state.pages ? '' : 'none') + ';"><td colspan="9"><iconify-icon icon="svg-spinners:ring-resize" width="18" style="vertical-align: middle; margin-right: 6px;"></iconify-icon> Memuat mata pelajaran berikutnya...</td></tr>';

        if (opts.append) {
            var oldSentinel = document.getElementById('lazySentinelSubjects');
            if (oldSentinel) oldSentinel.remove();
            tbody.insertAdjacentHTML('beforeend', html + sentinelHtml);
        } else {
            tbody.innerHTML = html + sentinelHtml;
        }

        updateBulkBar();
        initLazyLoad();
    }

    // Render Pagination
    function renderPagination() {
        var infoEl = document.getElementById('pagInfoSubject');
        var btnsEl = document.getElementById('pagBtnsSubject');
        var currentLoaded = document.querySelectorAll('#tbodySubjects tr[data-id]').length;
        var end = Math.min(Math.max(currentLoaded, state.page * state.per_page), state.total);
        var start = state.total === 0 ? 0 : 1;

        infoEl.innerHTML = 'Menampilkan <strong>' + start + '</strong>–<strong>' + end + '</strong> dari <strong>' + state.total + '</strong> mata pelajaran';

        var html = '';
        html += '<button type="button" class="ilmuna-pag-btn" data-page="1" ' + (state.page <= 1 ? 'disabled' : '') + ' title="Halaman Pertama">«</button>';
        html += '<button type="button" class="ilmuna-pag-btn" data-page="' + (state.page - 1) + '" ' + (state.page <= 1 ? 'disabled' : '') + ' title="Sebelumnya">‹</button>';

        var totalPages = state.pages;
        var from = Math.max(1, state.page - 2);
        var to   = Math.min(totalPages, state.page + 2);

        if (from > 1) html += '<span style="padding:0 4px;color:#94A3B8;">…</span>';
        for (var p = from; p <= to; p++) {
            html += '<button type="button" class="ilmuna-pag-btn ' + (p === state.page ? 'is-active' : '') + '" data-page="' + p + '">' + p + '</button>';
        }
        if (to < totalPages) html += '<span style="padding:0 4px;color:#94A3B8;">…</span>';

        html += '<button type="button" class="ilmuna-pag-btn" data-page="' + (state.page + 1) + '" ' + (state.page >= totalPages ? 'disabled' : '') + ' title="Berikutnya">›</button>';
        html += '<button type="button" class="ilmuna-pag-btn" data-page="' + totalPages + '" ' + (state.page >= totalPages ? 'disabled' : '') + ' title="Halaman Terakhir">»</button>';
        btnsEl.innerHTML = html;
    }

    // Render Info Chips
    function renderInfo() {
        var info = document.getElementById('infoChipsSubject');
        var chips = '';
        chips += '<span class="ilmuna-info-chip"><iconify-icon icon="solar:book-bookmark-bold" width="14"></iconify-icon> Total: ' + state.total + ' Mata Pelajaran</span>';
        if (state.f_kategori) chips += '<span class="ilmuna-info-chip"><iconify-icon icon="solar:folder-with-files-bold" width="14"></iconify-icon> Rumpun: ' + escHtml(state.f_kategori) + '</span>';
        if (state.f_tingkat)  chips += '<span class="ilmuna-info-chip"><iconify-icon icon="solar:diploma-bold" width="14"></iconify-icon> Tingkat: ' + escHtml(state.f_tingkat) + '</span>';
        if (state.search)     chips += '<span class="ilmuna-info-chip"><iconify-icon icon="solar:magnifer-bold" width="14"></iconify-icon> Cari: "' + escHtml(state.search) + '"</span>';
        info.innerHTML = chips;
    }

    // Load Filter Options
    function loadFilterOptions() {
        var fd = new FormData();
        fd.append('action', 'get_filters');
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success) return;
                var selKat = document.getElementById('filterKategori');
                var curK = selKat.value;

                var optK = '<option value="">Semua Kategori</option>';
                (res.data.kategori || []).forEach(function(k){
                    optK += '<option value="' + escHtml(k) + '"' + (k === curK ? ' selected' : '') + '>' + escHtml(k) + '</option>';
                });
                selKat.innerHTML = optK;
            })
            .catch(function(){});
    }

    // WhatsApp-Style Reactive Live Search
    var searchInput = document.getElementById('inputSearchSubject');
    var searchTimer = null;
    var searchBox = searchInput ? searchInput.closest('.ilmuna-search-box') : null;

    if (searchInput) {
        searchInput.addEventListener('input', function(){
            var val = this.value;
            if (searchBox) searchBox.classList.add('is-searching');
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function(){
                state.search = val.trim();
                state.page = 1;
                fetchList({ isLiveSearch: true, reset: true });
                if (searchBox) searchBox.classList.remove('is-searching');
            }, 180);
        });

        searchInput.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchTimer);
                state.search = this.value.trim();
                state.page = 1;
                fetchList({ isLiveSearch: true, reset: true });
                if (searchBox) searchBox.classList.remove('is-searching');
            }
        });
    }

    // Toolbar Event Listeners
    document.getElementById('btnSearchSubject').addEventListener('click', function(){
        clearTimeout(searchTimer);
        state.search = searchInput.value.trim();
        state.page = 1;
        fetchList({ isLiveSearch: true, reset: true });
    });

    document.getElementById('btnResetSubject').addEventListener('click', function(){
        clearTimeout(searchTimer);
        searchInput.value = '';
        document.getElementById('filterKategori').value = '';
        document.getElementById('filterTingkat').value = '';
        state.search = ''; state.f_kategori = ''; state.f_tingkat = '';
        state.page = 1;
        fetchList({ isLiveSearch: true, reset: true });
    });

    document.getElementById('filterKategori').addEventListener('change', function(){
        state.f_kategori = this.value; state.page = 1; fetchList({ isLiveSearch: true, reset: true });
    });

    document.getElementById('filterTingkat').addEventListener('change', function(){
        state.f_tingkat = this.value; state.page = 1; fetchList({ isLiveSearch: true, reset: true });
    });

    document.getElementById('perPageSubject').addEventListener('change', function(){
        state.per_page = parseInt(this.value) || 20; state.page = 1; fetchList({ isLiveSearch: true, reset: true });
    });

    document.getElementById('pagBtnsSubject').addEventListener('click', function(e){
        var btn = e.target.closest('.ilmuna-pag-btn');
        if (!btn || btn.disabled) return;
        var p = parseInt(btn.dataset.page) || 1;
        if (p === state.page) return;
        state.page = p;
        fetchList();
    });

    // Add Manual Subject Button
    document.getElementById('btnAddSubject').addEventListener('click', function(){
        document.getElementById('modalSubjectFormTitle').textContent = 'Tambah Mata Pelajaran Baru';
        var form = document.getElementById('formSubject');
        form.reset();
        form.querySelector('[name="id"]').value = '0';
        form.querySelector('[name="kode_mapel"]').value = '';
        form.querySelector('[name="kode_mapel"]').placeholder = '[Otomatis dari Sistem]';
        form.querySelector('[name="kkm"]').value = '75';
        form.querySelector('[name="tingkat"]').value = 'Semua';
        form.querySelector('[name="kategori"]').value = 'PAI & Budi Pekerti';
        openModal('modalSubjectForm');
    });

    // Auto-Generate Kode Mapel Realtime Event Listeners
    var codeTimer = null;
    function triggerAutoGenerateCode(force) {
        var form = document.getElementById('formSubject');
        var nama = form.querySelector('[name="nama_mapel"]').value.trim();
        var id   = parseInt(form.querySelector('[name="id"]').value) || 0;
        var kodeInput = form.querySelector('[name="kode_mapel"]');

        if (!nama) {
            if (id === 0) kodeInput.value = '';
            return;
        }

        if (id === 0 || force || !kodeInput.value.trim()) {
            var fd = new FormData();
            fd.append('action', 'generate_code');
            fd.append('nama_mapel', nama);
            fd.append('id', id);
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (res && res.success && res.data && res.data.kode_mapel) {
                        kodeInput.value = res.data.kode_mapel;
                    }
                })
                .catch(function(){});
        }
    }

    document.getElementById('inputNamaMapel').addEventListener('input', function(){
        clearTimeout(codeTimer);
        codeTimer = setTimeout(function(){
            triggerAutoGenerateCode(false);
        }, 300);
    });

    document.getElementById('btnRegenerateCode').addEventListener('click', function(){
        var btn = this;
        var icon = btn.querySelector('iconify-icon');
        if (icon) {
            icon.style.transform = 'rotate(180deg)';
            icon.style.transition = 'transform 0.3s ease';
            setTimeout(function(){ icon.style.transform = 'none'; }, 300);
        }
        triggerAutoGenerateCode(true);
    });

    // Table Action Delegator (Edit, Delete, Classes)
    document.getElementById('tbodySubjects').addEventListener('click', function(e){
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        var act = btn.dataset.act;
        var id  = parseInt(btn.dataset.id) || 0;
        if (!id) return;
        if (act === 'edit')    openEdit(id);
        if (act === 'delete')  doDelete(id);
        if (act === 'classes') openClasses(id);
    });

    // Edit Modal Open
    function openEdit(id) {
        var fd = new FormData();
        fd.append('action', 'get_single');
        fd.append('id', id);
        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal memuat data mapel.');
                    return;
                }
                var row = res.data.row;
                document.getElementById('modalSubjectFormTitle').textContent = 'Edit Data Mata Pelajaran';
                var form = document.getElementById('formSubject');
                form.querySelector('[name="id"]').value = row.id;
                form.querySelector('[name="kode_mapel"]').value = row.kode_mapel || '';
                form.querySelector('[name="nama_mapel"]').value = row.nama_mapel || '';
                form.querySelector('[name="kategori"]').value = row.kategori || 'PAI & Budi Pekerti';
                form.querySelector('[name="kkm"]').value = row.kkm || 75;
                form.querySelector('[name="tingkat"]').value = row.tingkat || 'Semua';
                form.querySelector('[name="deskripsi"]').value = row.deskripsi || '';
                openModal('modalSubjectForm');
            })
            .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
    }

    // Save Subject Form Handler
    document.getElementById('btnSaveSubject').addEventListener('click', function(){
        var btn = this;
        var form = document.getElementById('formSubject');
        var id = parseInt(form.querySelector('[name="id"]').value) || 0;
        var kode = form.querySelector('[name="kode_mapel"]').value.trim();
        var nama = form.querySelector('[name="nama_mapel"]').value.trim();
        var kat  = form.querySelector('[name="kategori"]').value.trim();
        var kkm  = parseInt(form.querySelector('[name="kkm"]').value) || 75;
        var tkt  = form.querySelector('[name="tingkat"]').value;
        var desc = form.querySelector('[name="deskripsi"]').value.trim();

        if (!nama) { toast('warning', 'Nama mata pelajaran wajib diisi.'); return; }

        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'save');
        fd.append('id', id);
        fd.append('kode_mapel', kode);
        fd.append('nama_mapel', nama);
        fd.append('kategori', kat);
        fd.append('kkm', kkm);
        fd.append('tingkat', tkt);
        fd.append('deskripsi', desc);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal menyimpan.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('modalSubjectForm');

                // In-Place Dynamic DOM Update
                if (res.data && res.data.row) {
                    if (res.data.mode === 'insert') {
                        insertRowInPlace(res.data.row);
                    } else {
                        updateRowInPlace(res.data.row);
                    }
                } else {
                    fetchList({ silent: true });
                }
                loadFilterOptions();
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

    // Delete Subject Handler
    function doDelete(id) {
        confirmDialog('Yakin ingin menghapus mata pelajaran ini? Mata pelajaran yang masih ditautkan pada rombel kelas tidak akan dapat dihapus demi keselamatan kurikulum.', {
            title: 'Hapus Mata Pelajaran?',
            okText: 'Ya, Hapus Mapel'
        }).then(function(ok){
            if (!ok) return;
            var fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal menghapus mata pelajaran.');
                        return;
                    }
                    toast('success', res.data.message);
                    removeRowInPlace(id);
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    }

    // Modal Classes Open
    function openClasses(id) {
        var tbody = document.getElementById('tbodySubjectClasses');
        tbody.innerHTML = '<tr><td colspan="5" class="ilmuna-table-loading"><iconify-icon icon="svg-spinners:ring-resize" width="20"></iconify-icon><span>Memuat rombel kelas...</span></td></tr>';
        openModal('modalSubjectClasses');

        var fd = new FormData();
        fd.append('action', 'get_classes_by_subject');
        fd.append('id', id);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#DC2626;padding:24px;">' + escHtml((res && res.data && res.data.message) || 'Gagal memuat rombel.') + '</td></tr>';
                    return;
                }
                var mapel = res.data.mapel || {};
                var classes = res.data.classes || [];
                document.getElementById('modalSubjectClassesName').textContent = mapel.nama_mapel + ' (' + mapel.kode_mapel + ')';

                if (classes.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#64748B;padding:32px;">Mata pelajaran ini belum ditautkan ke rombel kelas mana pun.</td></tr>';
                    return;
                }

                var html = '';
                classes.forEach(function(c, i){
                    html += '<tr>';
                    html += '<td style="text-align:center;">' + (i + 1) + '</td>';
                    html += '<td><strong>' + escHtml(c.nama_kelas) + '</strong></td>';
                    html += '<td><span class="cell-pill cell-pill--tahun">' + escHtml(c.tahun_pelajaran) + '</span></td>';
                    html += '<td>' + escHtml(c.nama_guru) + '</td>';
                    html += '<td style="text-align:center;"><span class="cell-count cell-count--siswa">' + (c.total_siswa || 0) + ' Siswa</span></td>';
                    html += '</tr>';
                });
                tbody.innerHTML = html;
            })
            .catch(function(){
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#DC2626;padding:24px;">Koneksi bermasalah.</td></tr>';
            });
    }

    // =========================================================================
    // BULK ACTIONS HANDLER
    // =========================================================================

    // Check All Checkbox
    document.getElementById('checkAllSubjects').addEventListener('change', function(){
        var isChecked = this.checked;
        document.querySelectorAll('.ilmuna-subject-cb').forEach(function(cb){
            var id = parseInt(cb.value);
            cb.checked = isChecked;
            var row = cb.closest('tr');
            if (isChecked) {
                selectedSubjects.add(id);
                if (row) row.classList.add('is-selected');
            } else {
                selectedSubjects.delete(id);
                if (row) row.classList.remove('is-selected');
            }
        });
        updateBulkBar();
    });

    // Individual Row Checkbox Delegator
    document.getElementById('tbodySubjects').addEventListener('change', function(e){
        if (e.target && e.target.classList.contains('ilmuna-subject-cb')) {
            var cb = e.target;
            var id = parseInt(cb.value);
            var row = cb.closest('tr');
            if (cb.checked) {
                selectedSubjects.add(id);
                if (row) row.classList.add('is-selected');
            } else {
                selectedSubjects.delete(id);
                if (row) row.classList.remove('is-selected');
            }
            updateBulkBar();
        }
    });

    // Close / Deselect Bulk Bar
    document.getElementById('btnBulkCloseSubjects').addEventListener('click', function(){
        selectedSubjects.clear();
        document.querySelectorAll('.ilmuna-subject-cb').forEach(function(cb){
            cb.checked = false;
            var row = cb.closest('tr');
            if (row) row.classList.remove('is-selected');
        });
        updateBulkBar();
    });

    // Bulk Delete
    document.getElementById('btnBulkDeleteSubjects').addEventListener('click', function(){
        if (!selectedSubjects.size) return;
        var count = selectedSubjects.size;
        confirmDialog('Yakin ingin menghapus ' + count + ' mata pelajaran terpilih? Mapel yang masih ditautkan ke kelas tidak akan terhapus demi keselamatan data.', {
            title: 'Hapus Masal (' + count + ' Mapel)?',
            okText: 'Ya, Hapus Terpilih'
        }).then(function(ok){
            if (!ok) return;
            var fd = new FormData();
            fd.append('action', 'bulk_delete');
            selectedSubjects.forEach(function(id){
                fd.append('ids[]', id);
            });

            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal menghapus masal.');
                        return;
                    }
                    toast('success', res.data.message);
                    var deletedIds = (res.data && res.data.deleted_ids) || Array.from(selectedSubjects);
                    deletedIds.forEach(function(did){
                        removeRowInPlace(did);
                    });
                    selectedSubjects.clear();
                    updateBulkBar();
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah saat hapus masal.'); });
        });
    });

    // Bulk Update Kategori
    document.getElementById('btnBulkActionKategori').addEventListener('click', function(){
        if (!selectedSubjects.size) return;
        document.getElementById('bulkKategoriCountText').textContent = selectedSubjects.size;
        openModal('modalBulkKategori');
    });

    document.getElementById('btnSubmitBulkKategori').addEventListener('click', function(){
        var kat = document.getElementById('inputBulkKategori').value.trim();
        if (!kat) { toast('warning', 'Kategori baru wajib diisi.'); return; }

        var btn = this;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'bulk_update_kategori');
        fd.append('kategori', kat);
        selectedSubjects.forEach(function(id){ fd.append('ids[]', id); });

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal memperbarui kategori.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('modalBulkKategori');
                selectedSubjects.clear();
                updateBulkBar();
                fetchList({ silent: true });
                loadFilterOptions();
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

    // Bulk Update KKM / KKTP
    document.getElementById('btnBulkActionKkm').addEventListener('click', function(){
        if (!selectedSubjects.size) return;
        document.getElementById('bulkKkmCountText').textContent = selectedSubjects.size;
        openModal('modalBulkKkm');
    });

    document.getElementById('btnSubmitBulkKkm').addEventListener('click', function(){
        var kkm = parseInt(document.getElementById('inputBulkKkm').value) || 75;
        if (kkm < 50 || kkm > 100) { toast('warning', 'Nilai KKTP/KKM harus antara 50 dan 100.'); return; }

        var btn = this;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'bulk_update_kkm');
        fd.append('kkm', kkm);
        selectedSubjects.forEach(function(id){ fd.append('ids[]', id); });

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal memperbarui KKM.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('modalBulkKkm');
                selectedSubjects.clear();
                updateBulkBar();
                fetchList({ silent: true });
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

    // Bulk Update Tingkat / Fase
    document.getElementById('btnBulkActionTingkat').addEventListener('click', function(){
        if (!selectedSubjects.size) return;
        document.getElementById('bulkTingkatCountText').textContent = selectedSubjects.size;
        openModal('modalBulkTingkat');
    });

    document.getElementById('btnSubmitBulkTingkat').addEventListener('click', function(){
        var tkt = document.getElementById('inputBulkTingkat').value;
        var btn = this;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'bulk_update_tingkat');
        fd.append('tingkat', tkt);
        selectedSubjects.forEach(function(id){ fd.append('ids[]', id); });

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal memperbarui tingkat.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('modalBulkTingkat');
                selectedSubjects.clear();
                updateBulkBar();
                fetchList({ silent: true });
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

    // Bulk Export to Excel (Blob Method)
    document.getElementById('btnBulkExportSubjects').addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        if (!selectedSubjects.size) return;
        var ids = Array.from(selectedSubjects).join(',');
        var exportUrl = EXCEL_URL + '?action=export_excel&ids=' + encodeURIComponent(ids);
        downloadBlob(exportUrl, 'data-mapel-terpilih-ilmuna.xlsx', this, 'Mengekspor ' + selectedSubjects.size + ' Mapel...');
    });

    // Export All Filtered Excel Toolbar Button (Blob Method)
    document.getElementById('btnExportSubject').addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        var exportUrl = EXCEL_URL + '?action=export_excel' +
            '&search=' + encodeURIComponent(state.search) +
            '&f_kategori=' + encodeURIComponent(state.f_kategori) +
            '&f_tingkat=' + encodeURIComponent(state.f_tingkat);
        downloadBlob(exportUrl, 'data-mata-pelajaran-ilmuna.xlsx', this, 'Mengekspor Excel...');
    });

    // Download Template Buttons (Blob Method)
    var btnTpl = document.getElementById('btnDownloadTemplateSubject');
    if (btnTpl) {
        btnTpl.addEventListener('click', function(e){
            e.preventDefault();
            e.stopPropagation();
            downloadBlob(EXCEL_URL + '?action=download_template', 'template-import-mapel-ilmuna.xlsx', this, 'Mengunduh Template...');
        });
    }

    var btnModalTpl = document.getElementById('btnModalDownloadTemplateSubject');
    if (btnModalTpl) {
        btnModalTpl.addEventListener('click', function(e){
            e.preventDefault();
            e.stopPropagation();
            downloadBlob(EXCEL_URL + '?action=download_template', 'template-import-mapel-ilmuna.xlsx', this, 'Mengunduh...');
        });
    }

    // Modal Import Drag & Drop
    var fileDropzone = document.getElementById('subjectFileDropzone');
    var fileInput = document.getElementById('subjectImportFile');
    var fileNamePreview = document.getElementById('subjectSelectedFileName');

    function updateFilePreview(file) {
        if (!fileNamePreview) return;
        if (file) {
            fileNamePreview.style.display = 'block';
            fileNamePreview.innerHTML = '<iconify-icon icon="solar:file-check-bold" width="16" style="vertical-align:middle;margin-right:4px;"></iconify-icon>' +
                '<strong>' + escHtml(file.name) + '</strong> (' + (file.size / 1024).toFixed(1) + ' KB)';
        } else {
            fileNamePreview.style.display = 'none';
            fileNamePreview.innerHTML = '';
        }
    }

    if (fileInput) {
        fileInput.addEventListener('change', function(){
            updateFilePreview(this.files && this.files[0] ? this.files[0] : null);
        });
    }

    if (fileDropzone) {
        ['dragenter', 'dragover'].forEach(function(evtName){
            fileDropzone.addEventListener(evtName, function(e){
                e.preventDefault(); e.stopPropagation();
                fileDropzone.classList.add('dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function(evtName){
            fileDropzone.addEventListener(evtName, function(e){
                e.preventDefault(); e.stopPropagation();
                fileDropzone.classList.remove('dragover');
            });
        });
        fileDropzone.addEventListener('drop', function(e){
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
                fileInput.files = e.dataTransfer.files;
                updateFilePreview(e.dataTransfer.files[0]);
            }
        });
    }

    document.getElementById('btnImportSubject').addEventListener('click', function(){
        if (fileInput) fileInput.value = '';
        updateFilePreview(null);
        var logWrap = document.getElementById('subjectImportLog');
        if (logWrap) {
            logWrap.style.display = 'none';
            logWrap.innerHTML = '';
        }
        openModal('modalSubjectImport');
    });

    // Do Import Excel via subjects-excel.php
    document.getElementById('btnDoImportSubject').addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        var btn = this;
        if (!fileInput.files || !fileInput.files[0]) {
            toast('warning', 'Pilih berkas Excel (.xlsx) terlebih dahulu.');
            return;
        }

        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'import_excel');
        fd.append('file', fileInput.files[0]);

        fetch(EXCEL_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                var logWrap = document.getElementById('subjectImportLog');
                logWrap.style.display = 'block';

                if (!res || !res.success) {
                    logWrap.innerHTML = '<div style="padding:14px;color:#DC2626;font-size:13px;">' +
                        escHtml((res && res.data && res.data.message) || 'Gagal import Excel.') + '</div>';
                    return;
                }

                var d = res.data;
                var html = '<div class="ilmuna-import-stat">';
                html += '<span style="color:#059669;">✔ ' + d.stat.ok + ' Berhasil</span>';
                html += '<span style="color:#D97706;">⚠ ' + d.stat.skip + ' Dilewati</span>';
                html += '<span style="color:#DC2626;">✘ ' + d.stat.error + ' Gagal</span>';
                html += '</div>';

                (d.log || []).forEach(function(item){
                    var num = item[0]; var st = item[1]; var msg = item[2];
                    html += '<div class="ilmuna-import-row">';
                    html += '<span style="font-family:monospace;color:#64748B;">#' + num + '</span>';
                    html += '<span class="badge ' + st + '">' + (st === 'ok' ? '✔ OK' : st === 'skip' ? '⚠ SKIP' : '✘ ERROR') + '</span>';
                    html += '<span style="color:#334155;flex:1;">' + escHtml(msg) + '</span>';
                    html += '</div>';
                });

                logWrap.innerHTML = html;
                toast('success', d.message);
                fetchList({ isLiveSearch: true, reset: true });
                loadFilterOptions();
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah saat import Excel.');
            });
    });

    // Inisialisasi awal saat halaman dibuka
    loadFilterOptions();
    fetchList();

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
