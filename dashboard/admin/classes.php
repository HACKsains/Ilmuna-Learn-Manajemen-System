<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DATA KELAS (ROMBEL) MANAGEMENT
 * ==============================================================================
 * Sesuai Skema Database: skema-tabel-kelas.sql (Tabel: kelas, siswa, kelas_mata_pelajaran, mata_pelajaran)
 * Fitur: Lazy Load AJAX, Pencarian, Filter Tahun Pelajaran, Kelola Relasi Mapel,
 *        Lihat Daftar Siswa, Tambah/Edit Manual, Unduh Template, Import/Export Excel (.xlsx)
 * Tema Warna: Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Gold #F59E0B)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('admin');

$pdo = getDBConnection();
$pageTitle = 'Data Kelas & Rombongan Belajar';
$pageIcon = 'solar:notebook-bookmark-bold';

/* ==============================================================================
 * EXCEL HANDLER MODULAR SEPARATION:
 * Operasi Download Template, Export Excel, dan Import Excel telah dipisahkan ke modul
 * backend terpisah: dashboard/admin/classes-excel.php demi kemudahan pemeliharaan
 * (Separation of Concerns), performa, dan kesesuaian tema visual Lumina Gold.
 * ============================================================================== */

// Fallback delegator: Jika ada request yang memuat action excel, teruskan ke classes-excel.php
if ((isset($_GET['action']) && in_array($_GET['action'], ['export_excel', 'download_template'], true)) ||
    (isset($_POST['action']) && $_POST['action'] === 'import_excel')) {
    require __DIR__ . '/classes-excel.php';
    exit;
}

/* ==============================================================================
 * 4. AJAX API ENDPOINTS
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $act = $_POST['action'];

    // Helper: Detect Jenjang / Fase Kurikulum Merdeka
    $getFase = function($namaKelas) {
        if (preg_match('/XII/i', $namaKelas) || preg_match('/\b12\b/', $namaKelas)) {
            return ['fase' => 'Fase F', 'tingkat' => 'Kelas XII', 'badge' => 'navy'];
        } elseif (preg_match('/XI/i', $namaKelas) || preg_match('/\b11\b/', $namaKelas)) {
            return ['fase' => 'Fase F', 'tingkat' => 'Kelas XI', 'badge' => 'teal'];
        } elseif (preg_match('/X/i', $namaKelas) || preg_match('/\b10\b/', $namaKelas)) {
            return ['fase' => 'Fase E', 'tingkat' => 'Kelas X', 'badge' => 'sky'];
        }
        return ['fase' => 'Umum', 'tingkat' => 'Rombel', 'badge' => 'gold'];
    };

    // Helper: Ambil Data 1 Baris Kelas Lengkap beserta Hitungan Mapel, Siswa, dan Badge
    $getClassRow = function($pdo, $idKelas) use ($getFase) {
        $stmt = $pdo->prepare("
            SELECT k.id, k.nama_kelas, k.tahun_pelajaran,
                (SELECT COUNT(*) FROM kelas_mata_pelajaran km WHERE km.id_kelas = k.id) AS total_mapel,
                (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa
            FROM kelas k
            WHERE k.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$idKelas]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $meta = $getFase($row['nama_kelas']);
            $row['fase'] = $meta['fase'];
            $row['tingkat'] = $meta['tingkat'];
            $row['badge'] = $meta['badge'];
        }
        return $row;
    };

    // 4.1 LIST KELAS (LAZY LOAD + SEARCH + FILTER TAHUN + PAGINATION)
    if ($act === 'list') {
        $search  = trim($_POST['search'] ?? '');
        $f_tahun = trim($_POST['f_tahun'] ?? '');
        $page    = max(1, (int)($_POST['page'] ?? 1));
        $per     = max(5, min(100, (int)($_POST['per_page'] ?? 20)));
        $offset  = ($page - 1) * $per;

        $where = '1=1';
        $params = [];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= ' AND (k.nama_kelas LIKE ? OR k.tahun_pelajaran LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        if ($f_tahun !== '') {
            $where .= ' AND k.tahun_pelajaran = ?';
            $params[] = $f_tahun;
        }

        // Count total
        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM kelas k WHERE {$where}");
        $cntStmt->execute($params);
        $total = (int)$cntStmt->fetchColumn();

        $sql = "SELECT k.id, k.nama_kelas, k.tahun_pelajaran,
                    (SELECT COUNT(*) FROM kelas_mata_pelajaran km WHERE km.id_kelas = k.id) AS total_mapel,
                    (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa
                FROM kelas k
                WHERE {$where}
                ORDER BY k.id DESC
                LIMIT {$per} OFFSET {$offset}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Inject fase & tingkat meta
        foreach ($rows as &$r) {
            $meta = $getFase($r['nama_kelas']);
            $r['fase'] = $meta['fase'];
            $r['tingkat'] = $meta['tingkat'];
            $r['badge'] = $meta['badge'];
        }
        unset($r);

        echo json_encode([
            'success' => true,
            'data' => [
                'rows'  => $rows ?: [],
                'total' => $total,
                'page'  => $page,
                'per'   => $per,
                'pages' => max(1, (int)ceil($total / $per))
            ]
        ]);
        exit;
    }

    // 4.2 FILTER OPTIONS
    if ($act === 'get_filters') {
        $tahun_list = $pdo->query("SELECT DISTINCT tahun_pelajaran FROM kelas WHERE tahun_pelajaran != '' ORDER BY tahun_pelajaran DESC")->fetchAll(PDO::FETCH_COLUMN);

        echo json_encode([
            'success' => true,
            'data' => [
                'tahun' => $tahun_list ?: ['2026/2027']
            ]
        ]);
        exit;
    }

    // 4.3 GET SINGLE KELAS (FOR EDIT)
    if ($act === 'get_single') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID rombel tidak valid.']]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, nama_kelas, tahun_pelajaran FROM kelas WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Data rombel kelas tidak ditemukan.']]);
            exit;
        }

        echo json_encode(['success' => true, 'data' => ['row' => $row]]);
        exit;
    }

    // 4.4 SAVE / ADD MANUAL / EDIT KELAS
    if ($act === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $nama_kelas = trim($_POST['nama_kelas'] ?? '');
        $tahun      = trim($_POST['tahun_pelajaran'] ?? '2026/2027');

        if ($nama_kelas === '') {
            echo json_encode(['success' => false, 'data' => ['message' => 'Nama rombel kelas wajib diisi.']]);
            exit;
        }
        if ($tahun === '') {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tahun pelajaran wajib diisi.']]);
            exit;
        }

        // Cek duplikasi
        $dupStmt = $pdo->prepare("SELECT id FROM kelas WHERE nama_kelas = ? AND tahun_pelajaran = ? AND id != ? LIMIT 1");
        $dupStmt->execute([$nama_kelas, $tahun, $id]);
        if ($dupStmt->fetchColumn()) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Kelas dengan nama dan tahun pelajaran yang sama sudah ada di sistem.']]);
            exit;
        }

        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE kelas SET nama_kelas = ?, tahun_pelajaran = ? WHERE id = ?");
                $stmt->execute([$nama_kelas, $tahun, $id]);
                $msg = 'Data rombel kelas berhasil diperbarui.';
                $mode = 'update';
            } else {
                $stmt = $pdo->prepare("INSERT INTO kelas (nama_kelas, tahun_pelajaran) VALUES (?, ?)");
                $stmt->execute([$nama_kelas, $tahun]);
                $id = (int)$pdo->lastInsertId();

                // Generate dan simpan kode_kelas permanen
                try {
                    require_once __DIR__ . '/students-excel.php';
                    if (function_exists('ilmuna_generate_masked_kelas_code')) {
                        $newCode = ilmuna_generate_masked_kelas_code($id, $nama_kelas, $tahun);
                        $pdo->prepare("UPDATE kelas SET kode_kelas = ? WHERE id = ?")->execute([$newCode, $id]);
                    }
                } catch (Exception $eCode) {}

                // Hubungkan secara otomatis ke mata pelajaran pertama (PAI) jika belum ada relasi
                try {
                    $firstMapelId = $pdo->query("SELECT id FROM mata_pelajaran LIMIT 1")->fetchColumn();
                    if ($firstMapelId) {
                        $pdo->prepare("INSERT IGNORE INTO kelas_mata_pelajaran (id_kelas, id_mata_pelajaran) VALUES (?, ?)")->execute([$id, $firstMapelId]);
                    }
                } catch (Exception $eRel) {}

                $msg = 'Rombel kelas baru berhasil ditambahkan.';
                $mode = 'insert';
            }

            $savedRow = $getClassRow($pdo, $id);

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

    // 4.5 DELETE KELAS
    if ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID kelas tidak valid.']]);
            exit;
        }

        // Periksa apakah masih ada siswa di tabel `siswa`
        $siswaCount = (int)$pdo->query("SELECT COUNT(*) FROM siswa WHERE id_kelas = " . $id)->fetchColumn();
        if ($siswaCount > 0) {
            echo json_encode(['success' => false, 'data' => ['message' => "Tidak bisa dihapus. Masih terdapat {$siswaCount} siswa terdaftar di rombel kelas ini. Pindahkan siswa terlebih dahulu."]]);
            exit;
        }

        try {
            $pdo->prepare("DELETE FROM kelas_mata_pelajaran WHERE id_kelas = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM kelas WHERE id = ?")->execute([$id]);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => 'Rombel kelas berhasil dihapus.',
                    'id'      => $id
                ]
            ]);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Gagal menghapus: ' . $e->getMessage()]]);
            exit;
        }
    }

    // 4.5.1 BULK DELETE KELAS
    if ($act === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_unique(array_filter(array_map('intval', $ids)));

        if (empty($ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada rombel kelas yang dipilih.']]);
            exit;
        }

        $deleted = 0;
        $skipped = 0;
        $skipNames = [];
        $deletedIds = [];

        foreach ($ids as $cid) {
            $siswaCount = (int)$pdo->query("SELECT COUNT(*) FROM siswa WHERE id_kelas = " . $cid)->fetchColumn();
            if ($siswaCount > 0) {
                $nama = $pdo->query("SELECT nama_kelas FROM kelas WHERE id = " . $cid)->fetchColumn();
                $skipNames[] = $nama ?: "ID #$cid";
                $skipped++;
                continue;
            }
            $pdo->prepare("DELETE FROM kelas_mata_pelajaran WHERE id_kelas = ?")->execute([$cid]);
            $pdo->prepare("DELETE FROM kelas WHERE id = ?")->execute([$cid]);
            $deleted++;
            $deletedIds[] = $cid;
        }

        $msg = "Berhasil menghapus {$deleted} rombel kelas.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} kelas dilewati karena masih memiliki siswa: " . implode(', ', array_slice($skipNames, 0, 3)) . ($skipped > 3 ? '...' : '') . ")";
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

    // 4.5.2 BULK UPDATE TAHUN PELAJARAN KELAS
    if ($act === 'bulk_update_tahun') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        $tahun = trim($_POST['tahun_pelajaran'] ?? '');

        if (empty($ids)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tidak ada rombel kelas yang dipilih.']]);
            exit;
        }
        if ($tahun === '') {
            echo json_encode(['success' => false, 'data' => ['message' => 'Tahun pelajaran baru wajib diisi.']]);
            exit;
        }

        $in = implode(',', $ids);
        $stmt = $pdo->prepare("UPDATE kelas SET tahun_pelajaran = ? WHERE id IN ($in)");
        $stmt->execute([$tahun]);

        echo json_encode([
            'success' => true,
            'data' => [
                'message'     => "Berhasil memperbarui tahun pelajaran menjadi '{$tahun}' pada " . count($ids) . " rombel kelas.",
                'updated'     => count($ids),
                'updated_ids' => $ids,
                'tahun'       => $tahun
            ]
        ]);
        exit;
    }

    // 4.6 GET RELASI MATA PELAJARAN (TABLE: kelas_mata_pelajaran & mata_pelajaran & guru_mata_pelajaran)
    if ($act === 'get_mapel') {
        $id_kelas = (int)($_POST['id_kelas'] ?? 0);
        if (!$id_kelas) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID kelas tidak valid.']]);
            exit;
        }

        $all_mapel = $pdo->query("
            SELECT id, nama_mata_pelajaran, semester, tahun_pelajaran 
            FROM mata_pelajaran 
            ORDER BY nama_mata_pelajaran ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $kmp_rows = $pdo->query("
            SELECT id_mata_pelajaran, id_guru_mata_pelajaran, kode_kelas_mapel 
            FROM kelas_mata_pelajaran 
            WHERE id_kelas = " . $id_kelas
        )->fetchAll(PDO::FETCH_ASSOC);

        $linked = [];
        $guru_map = []; // id_mata_pelajaran => id_guru_mata_pelajaran
        $kode_map = []; // id_mata_pelajaran => kode_kelas_mapel
        foreach ($kmp_rows as $row) {
            $idm = (int)$row['id_mata_pelajaran'];
            $linked[] = $idm;
            $guru_map[$idm] = $row['id_guru_mata_pelajaran'] ? (int)$row['id_guru_mata_pelajaran'] : null;
            $kode_map[$idm] = $row['kode_kelas_mapel'] ?? null;
        }

        // List guru yang terpetakan per mata pelajaran dari tabel guru_mata_pelajaran
        $gmp_rows = $pdo->query("
            SELECT 
                gmp.id AS id_guru_mata_pelajaran,
                gmp.id_guru,
                gmp.id_mata_pelajaran,
                g.nama_guru,
                g.nip,
                g.status_kepegawaian
            FROM guru_mata_pelajaran gmp
            JOIN guru g ON gmp.id_guru = g.id
            ORDER BY g.nama_guru ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $gmp_by_mapel = [];
        foreach ($gmp_rows as $row) {
            $mid = (int)$row['id_mata_pelajaran'];
            if (!isset($gmp_by_mapel[$mid])) {
                $gmp_by_mapel[$mid] = [];
            }
            $gmp_by_mapel[$mid][] = [
                'id_guru_mata_pelajaran' => (int)$row['id_guru_mata_pelajaran'],
                'id_guru'               => (int)$row['id_guru'],
                'nama_guru'             => $row['nama_guru'],
                'nip'                   => $row['nip'],
                'status_kepegawaian'    => $row['status_kepegawaian']
            ];
        }

        $kelas = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran FROM kelas WHERE id = " . $id_kelas)->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'mapel'        => $all_mapel ?: [],
                'linked'       => $linked,
                'guru_map'     => $guru_map,
                'kode_map'     => $kode_map,
                'gmp_by_mapel' => $gmp_by_mapel,
                'kelas'        => $kelas
            ]
        ]);
        exit;
    }

    // 4.7 SAVE RELASI MATA PELAJARAN & GURU PENGAMPU (TABLE: kelas_mata_pelajaran)
    if ($act === 'save_mapel') {
        $id_kelas = (int)($_POST['id_kelas'] ?? 0);
        if (!$id_kelas) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID kelas tidak valid.']]);
            exit;
        }

        $selected = $_POST['mapel'] ?? [];
        if (!is_array($selected)) $selected = [];
        $selected = array_unique(array_filter(array_map('intval', $selected)));

        $guru_mapel = $_POST['guru_mapel'] ?? []; // Map of mapel_id => id_guru_mata_pelajaran

        $existing = $pdo->query("SELECT id_mata_pelajaran FROM kelas_mata_pelajaran WHERE id_kelas = " . $id_kelas)->fetchAll(PDO::FETCH_COLUMN);
        $existing = array_map('intval', $existing ?: []);

        $to_delete = array_diff($existing, $selected);
        foreach ($to_delete as $idm) {
            $pdo->prepare("DELETE FROM kelas_mata_pelajaran WHERE id_kelas = ? AND id_mata_pelajaran = ?")->execute([$id_kelas, $idm]);
        }

        foreach ($selected as $idm) {
            $id_gmp = !empty($guru_mapel[$idm]) ? (int)$guru_mapel[$idm] : null;

            if (in_array($idm, $existing, true)) {
                $pdo->prepare("
                    UPDATE kelas_mata_pelajaran 
                    SET id_guru_mata_pelajaran = ? 
                    WHERE id_kelas = ? AND id_mata_pelajaran = ?
                ")->execute([$id_gmp, $id_kelas, $idm]);
            } else {
                $pdo->prepare("
                    INSERT INTO kelas_mata_pelajaran (id_kelas, id_mata_pelajaran, id_guru_mata_pelajaran) 
                    VALUES (?, ?, ?)
                ")->execute([$id_kelas, $idm, $id_gmp]);
            }
        }

        $savedRow = $getClassRow($pdo, $id_kelas);

        echo json_encode([
            'success' => true,
            'data' => [
                'message' => 'Relasi mata pelajaran dan guru pengampu rombel berhasil disimpan.',
                'total'   => count($selected),
                'row'     => $savedRow
            ]
        ]);
        exit;
    }

    // 4.8 PREVIEW DAFTAR SISWA KELAS (TABLE: siswa)
    if ($act === 'get_siswa') {
        $id_kelas = (int)($_POST['id_kelas'] ?? 0);
        if (!$id_kelas) {
            echo json_encode(['success' => false, 'data' => ['message' => 'ID kelas tidak valid.']]);
            exit;
        }

        $kelas = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran FROM kelas WHERE id = " . $id_kelas)->fetch(PDO::FETCH_ASSOC);

        $siswaList = $pdo->query("
            SELECT id, nisn, nis, nama_lengkap, no_hp, gmail, alamat
            FROM siswa
            WHERE id_kelas = {$id_kelas}
            ORDER BY nama_lengkap ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'kelas' => $kelas,
                'siswa' => $siswaList ?: []
            ]
        ]);
        exit;
    }

    // 4.9 IMPORT EXCEL (.xlsx) — Delegated to classes-excel.php
    if ($act === 'import_excel') {
        require __DIR__ . '/classes-excel.php';
        exit;
    }

    echo json_encode(['success' => false, 'data' => ['message' => 'Aksi tidak dikenali.']]);
    exit;
}

$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-classes.css') ? filemtime(BASE_PATH . '/assets/css/admin-classes.css') : '1.0';
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-classes.css?v=' . $cssVersion . '">';

/**
 * Helper: Generate Prefix Singkatan Mata Pelajaran (Contoh: Sejarah -> SKI, Fiqih -> FQH)
 */
if (!function_exists('ilmuna_get_mapel_prefix')) {
    function ilmuna_get_mapel_prefix($namaMapel) {
        $clean = trim((string)$namaMapel);
        if (preg_match('/\(([A-Za-z0-9]+)\)/', $clean, $m)) {
            return strtoupper($m[1]);
        }
        $lower = strtolower($clean);
        if (strpos($lower, 'sejarah') !== false || strpos($lower, 'ski') !== false) return 'SKI';
        if (strpos($lower, 'qur') !== false || strpos($lower, 'hadits') !== false || strpos($lower, 'tajwid') !== false) return 'QHD';
        if (strpos($lower, 'akidah') !== false || strpos($lower, 'akhlak') !== false) return 'AAK';
        if (strpos($lower, 'fiqih') !== false || strpos($lower, 'fikih') !== false) return 'FQH';
        if (strpos($lower, 'arab') !== false) return 'BAR';
        if (strpos($lower, 'agama') !== false || strpos($lower, 'pai') !== false) return 'PAI';

        $words = preg_split('/[\s\-_&,]+/', preg_replace('/[^a-zA-Z0-9\s]/', '', $clean));
        $initials = '';
        foreach ($words as $w) {
            if ($w !== '') $initials .= strtoupper($w[0]);
        }
        if (strlen($initials) >= 2) return substr($initials, 0, 4);
        return strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $clean), 0, 3) ?: 'MPL');
    }
}

/**
 * Helper: Buat Kode Relasi Terproteksi (PREFIX-(ID)(2 digit acak))
 * Contoh: Sejarah (ID 5) -> SKI-583
 */
if (!function_exists('ilmuna_generate_masked_relasi_code')) {
    function ilmuna_generate_masked_relasi_code($idRelasi, $namaMapel) {
        $prefix = ilmuna_get_mapel_prefix($namaMapel);
        $rand2 = rand(10, 99);
        return $prefix . '-' . (int)$idRelasi . $rand2;
    }
}

/**
 * Helper: Ekstrak ID Asli dari Kode Terproteksi (PREFIX-(ID)(2 digit acak))
 */
if (!function_exists('ilmuna_parse_guru_mapel_code')) {
    function ilmuna_parse_guru_mapel_code($token, $validGmpMap = null) {
        $token = trim((string)$token);
        if ($token === '') return null;

        if (preg_match('/^[A-Za-z]+-(\d+)$/', $token, $m)) {
            $numStr = $m[1];
            if (strlen($numStr) >= 3) {
                $extractedId = (int)substr($numStr, 0, -2);
                if ($validGmpMap === null || isset($validGmpMap[$extractedId])) {
                    return $extractedId;
                }
            }
            $directId = (int)$numStr;
            if ($validGmpMap === null || isset($validGmpMap[$directId])) {
                return $directId;
            }
        }

        if (preg_match('/^\d+$/', $token)) {
            if (strlen($token) >= 3) {
                $extractedId = (int)substr($token, 0, -2);
                if ($validGmpMap !== null && isset($validGmpMap[$extractedId])) {
                    return $extractedId;
                }
            }
            $directId = (int)$token;
            if ($validGmpMap === null || isset($validGmpMap[$directId])) {
                return $directId;
            }
        }

        if (preg_match('/[A-Za-z]+.*?(\d+)/', $token, $m)) {
            $numStr = $m[1];
            if (strlen($numStr) >= 3) {
                $extractedId = (int)substr($numStr, 0, -2);
                if ($validGmpMap === null || isset($validGmpMap[$extractedId])) {
                    return $extractedId;
                }
            }
            $directId = (int)$numStr;
            if ($validGmpMap === null || isset($validGmpMap[$directId])) {
                return $directId;
            }
        }

        return null;
    }
}

/**
 * Helper: Ekstrak Daftar ID Relasi dari String Input Multi-Kode
 */
if (!function_exists('ilmuna_parse_multi_relasi_codes')) {
    function ilmuna_parse_multi_relasi_codes($inputStr, $validGmpMap = null) {
        $tokens = preg_split('/[,;\s]+/', trim((string)$inputStr));
        $ids = [];
        foreach ($tokens as $t) {
            $id = ilmuna_parse_guru_mapel_code($t, $validGmpMap);
            if ($id !== null && $id > 0) {
                $ids[] = $id;
            }
        }
        return array_unique($ids);
    }
}

require_once BASE_PATH . '/includes/header.php';
?>

<!-- ==========================================================================
 * LUMINA GOLD THEMED CRUD DATA KELAS (KURIKULUM MERDEKA 69 TABEL)
 * ========================================================================== -->
<div class="ilmuna-kelas-app" id="dosenCrudKelas" data-ajax="<?= BASE_URL ?>/dashboard/admin/classes" data-excel="<?= BASE_URL ?>/dashboard/admin/classes-excel.php">

    <!-- TOOLBAR & FILTERS (Tambah Rombel Kelas di paling kiri baris aksi) -->
    <div class="ilmuna-crud-toolbar">
        <div class="ilmuna-crud-toolbar__row">
            <div class="ilmuna-search-box">
                <iconify-icon icon="solar:magnifer-bold" width="18"></iconify-icon>
                <input type="text" id="dosenSearchKelas" placeholder="Cari nama rombel kelas atau tahun pelajaran...">
            </div>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="dosenBtnSearch">
                <iconify-icon icon="solar:magnifer-bold" width="16"></iconify-icon>
                Cari
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="dosenBtnReset">
                <iconify-icon icon="solar:refresh-bold" width="16"></iconify-icon>
                Reset
            </button>
        </div>
        <div class="ilmuna-crud-toolbar__row ilmuna-crud-toolbar__row--filters">
            <div class="ilmuna-filter-group">
                <label for="dosenFilterTahun">
                    <iconify-icon icon="solar:calendar-bold" width="15"></iconify-icon>
                    Tahun Pelajaran:
                </label>
                <select id="dosenFilterTahun" class="ilmuna-select-sm">
                    <option value="">Semua Tahun</option>
                </select>
            </div>
            <div class="ilmuna-filter-group">
                <label for="dosenPerPage">
                    <iconify-icon icon="solar:list-bold" width="15"></iconify-icon>
                    Tampilkan:
                </label>
                <select id="dosenPerPage" class="ilmuna-select-sm">
                    <option value="20">20 Data</option>
                    <option value="50">50 Data</option>
                    <option value="100">100 Data</option>
                </select>
            </div>
        </div>
        <div class="ilmuna-crud-toolbar__row ilmuna-crud-toolbar__row--actions">
            <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="dosenBtnAddKelas">
                <iconify-icon icon="solar:add-circle-bold" width="16"></iconify-icon>
                Tambah Rombel Kelas
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="dosenBtnDownloadTemplate">
                <iconify-icon icon="solar:file-download-bold" width="16"></iconify-icon>
                Unduh Format Excel
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="dosenBtnImport">
                <iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon>
                Import Excel (.xlsx)
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--emerald" id="dosenBtnExport">
                <iconify-icon icon="solar:file-send-bold" width="16"></iconify-icon>
                Export Excel (.xlsx)
            </button>
        </div>
    </div>

    <!-- CHIPS COUNTER -->
    <div class="ilmuna-crud-info" id="dosenCrudInfo">
        <span class="ilmuna-info-chip"><iconify-icon icon="solar:notebook-bookmark-bold" width="14"></iconify-icon> Memuat data kelas...</span>
    </div>

    <!-- TABEL DATA KELAS -->
    <div class="ilmuna-crud-tablewrap">
        <table class="ilmuna-crud-table" id="dosenTableKelas">
            <thead>
                <tr>
                    <th style="width: 42px; text-align: center;">
                        <input type="checkbox" id="checkAllClasses" class="ilmuna-bulk-cb" title="Pilih Semua di Halaman Ini">
                    </th>
                    <th style="width: 50px;">#</th>
                    <th>Nama Rombel / Kelas</th>
                    <th style="width: 140px;">Jenjang / Fase</th>
                    <th style="width: 140px;">Tahun Pelajaran</th>
                    <th style="width: 130px;">Mata Pelajaran</th>
                    <th style="width: 130px;">Jumlah Siswa</th>
                    <th style="width: 195px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody id="dosenTableKelasBody">
                <tr>
                    <td colspan="8" class="ilmuna-table-loading">
                        <iconify-icon icon="svg-spinners:ring-resize" width="28"></iconify-icon>
                        <span>Memuat data rombel kelas...</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <div class="ilmuna-pagination" id="dosenPagination">
        <div class="ilmuna-pagination__info" id="dosenPagInfo">Menampilkan data...</div>
        <div class="ilmuna-pagination__btns" id="dosenPagBtns"></div>
    </div>

</div>

<!-- ==========================================================================
 * MODALS & DIALOGS (Moved to body dynamically by JS for optimal z-index)
 * ========================================================================== -->

<!-- MODAL 1: FORM TAMBAH / EDIT KELAS (MANUAL) -->
<div class="ilmuna-custom-modal" id="dosenModalKelas">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:notebook-bookmark-bold" width="22"></iconify-icon>
                <h3 id="dosenModalKelasTitle">Tambah Rombel Kelas</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <form id="dosenFormKelas" class="ilmuna-modal-body" onsubmit="return false;">
            <input type="hidden" name="id" value="0">
            <div class="ilmuna-form-field">
                <label>Nama Rombel / Kelas <span class="req">*</span></label>
                <input type="text" name="nama_kelas" required placeholder="Contoh: X PAI-1 (Imam Al-Bukhari)" class="ilmuna-input">
                <small>Format: Jenjang Nama Mapel - Nomor (Nama Rombel/Tokoh)</small>
            </div>
            <div class="ilmuna-form-field">
                <label>Tahun Pelajaran <span class="req">*</span></label>
                <input type="text" name="tahun_pelajaran" required placeholder="Contoh: 2026/2027" value="2026/2027" class="ilmuna-input">
                <small>Sesuai kalender akademik aktif Kurikulum Merdeka</small>
            </div>
        </form>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="dosenBtnSaveKelas">
                <iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon>
                Simpan Rombel
            </button>
        </div>
    </div>
</div>

<!-- MODAL 2: KELOLA RELASI MATA PELAJARAN (TABLE: kelas_mata_pelajaran) -->
<div class="ilmuna-custom-modal" id="dosenModalMatkul">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 640px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:notebook-bold" width="22"></iconify-icon>
                <h3>Kelola Mata Pelajaran — <span id="dosenMatkulKelasName" style="color: var(--lumina-teal);">—</span></h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <input type="hidden" id="dosenMatkulKelasId" value="0">
            <p style="margin: 0 0 14px; font-size: 13px; color: var(--lumina-text-muted);">
                Tentukan mata pelajaran yang diajarkan pada kelas ini untuk mengaktifkan modul ajar, tugas, dan presensi.
            </p>
            <div class="ilmuna-search-box" style="margin-bottom: 14px;">
                <iconify-icon icon="solar:magnifer-bold" width="16"></iconify-icon>
                <input type="text" id="dosenSearchMatkul" placeholder="Cari nama mata pelajaran...">
            </div>
            <div class="ilmuna-matkul-list" id="dosenMatkulList">
                <div style="text-align: center; padding: 24px; color: var(--lumina-text-muted);">
                    <iconify-icon icon="svg-spinners:ring-resize" width="24"></iconify-icon>
                </div>
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="dosenBtnSaveMatkul">
                <iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon>
                Simpan Relasi Mapel
            </button>
        </div>
    </div>
</div>

<!-- MODAL 3: PREVIEW DAFTAR SISWA KELAS (TABLE: siswa) -->
<div class="ilmuna-custom-modal" id="dosenModalSiswa">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 700px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:square-academic-cap-2-bold" width="22"></iconify-icon>
                <h3>Daftar Siswa — <span id="dosenSiswaKelasName" style="color: var(--lumina-teal);">—</span></h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <span id="dosenSiswaCount" style="font-size: 13px; font-weight: 700; color: #1D3B5E;">Total Siswa: 0</span>
                <a id="dosenBtnGoToStudents" href="<?= BASE_URL ?>/dashboard/admin/students.php" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm">
                    <iconify-icon icon="solar:arrow-right-up-bold" width="14"></iconify-icon>
                    Buka Halaman Siswa Lengkap
                </a>
            </div>
            <div class="ilmuna-crud-tablewrap" style="max-height: 340px; overflow-y: auto;">
                <table class="ilmuna-crud-table" style="font-size: 13px;">
                    <thead>
                        <tr>
                            <th style="width: 45px;">#</th>
                            <th>NISN</th>
                            <th>Nama Lengkap</th>
                            <th>Kontak</th>
                        </tr>
                    </thead>
                    <tbody id="dosenSiswaTableBody">
                        <tr><td colspan="4" class="ilmuna-table-loading"><span>Memuat siswa...</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Tutup</button>
        </div>
    </div>
</div>

<!-- MODAL 4: IMPORT EXCEL (.xlsx) -->
<div class="ilmuna-custom-modal" id="dosenModalImport">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 580px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:cloud-upload-bold" width="22"></iconify-icon>
                <h3>Import Data Kelas dari Excel (.xlsx)</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <div style="margin-bottom: 16px;">
                <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="dosenBtnModalDownloadTemplate" style="font-size: 12.5px;">
                    <iconify-icon icon="solar:file-download-bold" width="14"></iconify-icon>
                    Unduh Format Template Excel
                </button>
            </div>
            <div class="ilmuna-file-dropzone" id="dosenFileDropzone">
                <iconify-icon icon="solar:cloud-upload-bold" width="38"></iconify-icon>
                <label for="dosenImportFile" style="cursor: pointer; font-weight: 700; color: #1D3B5E; margin-top: 8px; display: block;">
                    Pilih File Excel (.xlsx) atau Tarik ke Sini
                </label>
                <span style="font-size: 12px; color: #64748B;">Hanya format berkas .xlsx (Maks. 10MB)</span>
                <div id="dosenSelectedFileName" style="display: none; margin-top: 10px; font-weight: 600; color: #0D7A87; font-size: 12.5px; background: rgba(13,122,135,0.08); padding: 6px 12px; border-radius: 8px;"></div>
                <input type="file" id="dosenImportFile" accept=".xlsx" class="ilmuna-file-input">
            </div>
            <div style="margin-top: 12px; padding: 10px 14px; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; font-size: 12px; color: #475569; display: flex; gap: 10px; align-items: flex-start;">
                <iconify-icon icon="solar:info-circle-bold" width="18" style="color: #0D7A87; flex-shrink: 0; margin-top: 2px;"></iconify-icon>
                <div>
                    <strong>Relasi Mata Pelajaran:</strong> Anda dapat langsung mengisi kode mata pelajaran di kolom <code>kode_mapel</code> pada template (contoh: <code>PAI-151728, QHD-462932</code>). Buka Sheet 2 ("Referensi Mapel") pada template Excel untuk melihat daftar kode mapel resmi yang aktif.
                </div>
            </div>
            <div id="dosenImportLog" class="ilmuna-import-log" style="display: none;"></div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Tutup</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="dosenBtnDoImport">
                <iconify-icon icon="solar:cloud-upload-bold" width="16"></iconify-icon>
                Mulai Import
            </button>
        </div>
    </div>
</div>

<!-- MODAL 5: BULK UPDATE TAHUN PELAJARAN -->
<div class="ilmuna-custom-modal" id="modalBulkUpdateTahun">
    <div class="ilmuna-modal-overlay" data-close="1"></div>
    <div class="ilmuna-modal-container" style="max-width: 460px;">
        <div class="ilmuna-modal-header">
            <div class="ilmuna-modal-header__title">
                <iconify-icon icon="solar:calendar-bold" width="22"></iconify-icon>
                <h3>Ubah Tahun Pelajaran Masal</h3>
            </div>
            <button type="button" class="ilmuna-modal-close" data-close="1">
                <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
            </button>
        </div>
        <div class="ilmuna-modal-body">
            <p style="margin: 0; font-size: 13px; color: #64748B;">
                Anda akan memperbarui tahun pelajaran untuk <strong id="bulkTahunCountText" style="color: #1D3B5E;">0</strong> rombel kelas terpilih.
            </p>
            <div class="ilmuna-form-field">
                <label>Tahun Pelajaran Baru <span class="req">*</span></label>
                <input type="text" id="inputBulkTahun" placeholder="Contoh: 2026/2027" value="2026/2027" class="ilmuna-input">
                <small>Format: YYYY/YYYY (contoh: 2026/2027)</small>
            </div>
        </div>
        <div class="ilmuna-modal-footer">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" data-close="1">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--primary" id="btnSubmitBulkTahun">
                <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                Terapkan Tahun Pelajaran
            </button>
        </div>
    </div>
</div>

<!-- FLOATING BULK ACTION BAR -->
<div class="ilmuna-bulk-bar" id="bulkBarClasses">
    <div class="ilmuna-bulk-bar__counter">
        <span class="ilmuna-bulk-pill" id="bulkCountClasses">0</span>
        <span class="ilmuna-bulk-label">Kelas Terpilih</span>
    </div>
    <div class="ilmuna-bulk-bar__divider"></div>
    <div class="ilmuna-bulk-bar__actions">
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--gold" id="btnBulkUpdateTahun">
            <iconify-icon icon="solar:calendar-bold" width="16"></iconify-icon>
            <span>Ubah Tahun Pelajaran</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--emerald" id="btnBulkExportClasses">
            <iconify-icon icon="solar:file-send-bold" width="16"></iconify-icon>
            <span>Ekspor Excel (.xlsx)</span>
        </button>
        <button type="button" class="ilmuna-bulk-btn ilmuna-bulk-btn--danger" id="btnBulkDeleteClasses">
            <iconify-icon icon="solar:trash-bin-trash-bold" width="16"></iconify-icon>
            <span>Hapus Masal</span>
        </button>
    </div>
    <button type="button" class="ilmuna-bulk-bar__close" id="btnBulkCloseClasses" title="Batalkan Pilihan">
        <iconify-icon icon="solar:close-circle-bold" width="20"></iconify-icon>
    </button>
</div>

<!-- TOAST & CONFIRM DIALOGS -->
<div id="dosenCrudToast" class="ilmuna-toast-stack"></div>
<div id="dosenCrudConfirm" class="ilmuna-confirm-overlay">
    <div class="ilmuna-confirm-dialog">
        <div class="ilmuna-confirm-icon" id="dosenConfirmIcon">
            <iconify-icon icon="solar:danger-triangle-bold" width="30"></iconify-icon>
        </div>
        <h3 class="ilmuna-confirm-title" id="dosenConfirmTitle">Konfirmasi</h3>
        <p class="ilmuna-confirm-message" id="dosenConfirmMessage"></p>
        <div class="ilmuna-confirm-actions">
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="dosenConfirmCancel" style="flex: 1;">Batal</button>
            <button type="button" class="ilmuna-btn ilmuna-btn--danger" id="dosenConfirmOk" style="flex: 1;">Ya, Hapus</button>
        </div>
    </div>
</div>

<!-- Styles linked in <head>: assets/css/admin-classes.css -->

<!-- ==========================================================================
 * SCRIPT CONTROLLER (AJAX LAZY LOAD & MODAL DISPATCHER)
 * ========================================================================== -->
<script>
(function() {
    'use strict';

    var root = document.getElementById('dosenCrudKelas');
    if (!root) return;

    var AJAX_URL = root.dataset.ajax;
    var EXCEL_URL = root.dataset.excel || (AJAX_URL + '-excel.php');

    // Bersihkan parameter sisa '?action=export_excel' di address bar jika sebelumnya sempat terisi
    if (window.location.search && window.location.search.indexOf('action=') !== -1) {
        try {
            window.history.replaceState({}, document.title, window.location.pathname);
        } catch(e) {}
    }

    // =========================================================================
    // JAVASCRIPT BLOB DOWNLOAD HELPER (Native Fetch -> Blob -> Object URL)
    // Murni unduh background tanpa merubah URL browser (Zero Address Bar Navigation)
    // =========================================================================
    // Download Blob Utility (Native & Responsive, sama seperti subjects)
    function downloadBlob(url, fallbackFilename, btnElement, loadingText) {
        var origHtml = btnElement ? btnElement.innerHTML : '';
        if (btnElement) {
            btnElement.disabled = true;
            btnElement.innerHTML = '<iconify-icon icon="svg-spinners:ring-resize" width="16"></iconify-icon> ' + (loadingText || 'Mengunduh...');
        }

        fetch(url)
            .then(function(res) {
                if (!res.ok) throw new Error('Gagal mengunduh berkas (Kode ' + res.status + ')');
                var disposition = res.headers.get('Content-Disposition') || '';
                var filename = fallbackFilename;
                var match = disposition.match(/filename="?([^"]+)"?/);
                if (match && match[1]) filename = match[1];
                return res.blob().then(function(b){ return { blob: b, filename: filename }; });
            })
            .then(function(data) {
                var blobUrl = window.URL.createObjectURL(data.blob);
                var a = document.createElement('a');
                a.href = blobUrl;
                a.download = data.filename;
                document.body.appendChild(a);
                a.click();
                setTimeout(function() {
                    if (a.parentNode) document.body.removeChild(a);
                    window.URL.revokeObjectURL(blobUrl);
                }, 400);
            })
            .catch(function(err) {
                toast('error', err.message || 'Terjadi kesalahan saat mengunduh berkas.');
            })
            .finally(function() {
                if (btnElement) {
                    btnElement.disabled = false;
                    btnElement.innerHTML = origHtml;
                }
            });
    }

    // Relocate overlays to <body>
    (function moveOverlaysToBody() {
        var ids = [
            'dosenCrudToast',
            'dosenCrudConfirm',
            'dosenModalKelas',
            'dosenModalMatkul',
            'dosenModalSiswa',
            'dosenModalImport',
            'modalBulkUpdateTahun',
            'bulkBarClasses'
        ];
        ids.forEach(function(id) {
            var el = document.getElementById(id);
            if (el && el.parentNode && el.parentNode !== document.body) {
                document.body.appendChild(el);
            }
        });
    })();

    // State
    var state = {
        page: 1,
        per_page: 20,
        search: '',
        f_tahun: '',
        total: 0,
        pages: 1,
        loading: false
    };

    var selectedClasses = new Set();

    function updateBulkBar() {
        var bar = document.getElementById('bulkBarClasses');
        var cnt = document.getElementById('bulkCountClasses');
        var txt = document.getElementById('bulkTahunCountText');
        var totalSel = selectedClasses.size;

        if (cnt) cnt.textContent = totalSel;
        if (txt) txt.textContent = totalSel;

        if (totalSel > 0) {
            bar.classList.add('is-active');
        } else {
            bar.classList.remove('is-active');
        }

        // Sync check all box
        var chkAll = document.getElementById('checkAllClasses');
        var visibleCbs = document.querySelectorAll('.ilmuna-class-cb');
        if (chkAll && visibleCbs.length > 0) {
            var checkedCount = 0;
            visibleCbs.forEach(function(cb){
                if (cb.checked) checkedCount++;
            });
            chkAll.checked = (checkedCount === visibleCbs.length);
            chkAll.indeterminate = (checkedCount > 0 && checkedCount < visibleCbs.length);
        } else if (chkAll) {
            chkAll.checked = false;
            chkAll.indeterminate = false;
        }
    }

    // Toast Notification
    function toast(type, message) {
        var normType = (type === 'danger' || type === 'err' || type === 'error') ? 'error' : ((type === 'warn' || type === 'warning') ? 'warning' : (type === 'info' ? 'info' : 'success'));
        var titleMap = { success: 'Berhasil', error: 'Gagal', warning: 'Peringatan', info: 'Informasi' };
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, titleMap[normType] || 'Pemberitahuan', message, 4500);
        }
        var wrap = document.getElementById('dosenCrudToast');
        if (!wrap) return;
        var icons = {
            success: 'solar:check-circle-bold',
            error:   'solar:danger-circle-bold',
            warning: 'solar:danger-triangle-bold',
            info:    'solar:info-circle-bold'
        };
        var colors = {
            success: '#10B981',
            error:   '#EF4444',
            warning: '#F59E0B',
            info:    '#0D7A87'
        };
        var el = document.createElement('div');
        el.className = 'ilmuna-toast-item ilmuna-toast-item--' + type;
        el.innerHTML =
            '<div style="color:' + (colors[type] || '#0D7A87') + ';display:flex;"><iconify-icon icon="' + (icons[type] || icons.info) + '" width="22"></iconify-icon></div>' +
            '<div class="ilmuna-toast-item__msg"></div>' +
            '<button type="button" class="ilmuna-toast-item__close">&times;</button>';
        el.querySelector('.ilmuna-toast-item__msg').textContent = message;
        wrap.appendChild(el);

        requestAnimationFrame(function(){ el.classList.add('show'); });
        var t = setTimeout(function(){ closeToast(el); }, 4500);

        el.querySelector('.ilmuna-toast-item__close').addEventListener('click', function(){
            clearTimeout(t); closeToast(el);
        });
        el.addEventListener('mouseenter', function(){ clearTimeout(t); });
        el.addEventListener('mouseleave', function(){
            t = setTimeout(function(){ closeToast(el); }, 2000);
        });
    }

    function closeToast(el) {
        el.classList.remove('show');
        el.classList.add('hide');
        setTimeout(function(){ el.remove(); }, 350);
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
            var overlay = document.getElementById('dosenCrudConfirm');
            var msgEl   = document.getElementById('dosenConfirmMessage');
            var titleEl = document.getElementById('dosenConfirmTitle');
            var okBtn   = document.getElementById('dosenConfirmOk');
            var ccBtn   = document.getElementById('dosenConfirmCancel');

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

    // Helper: Build Table Row Cells HTML
    function buildClassRowCellsHtml(r, no, isChecked) {
        var html = '';
        html += '<td style="text-align: center;"><input type="checkbox" class="ilmuna-class-cb" value="' + r.id + '" ' + (isChecked ? 'checked' : '') + '></td>';
        html += '<td class="cell-num">' + no + '</td>';
        html += '<td class="cell-nama"><strong>' + escHtml(r.nama_kelas || '-') + '</strong></td>';
        html += '<td><span class="cell-pill cell-pill--' + (r.badge || 'navy') + '">' + escHtml(r.tingkat || r.fase || '-') + '</span></td>';
        html += '<td class="cell-tahun"><span class="cell-pill cell-pill--tahun">' + escHtml(r.tahun_pelajaran || '-') + '</span></td>';
        html += '<td class="cell-mapel-count"><span class="cell-count cell-count--matkul"><iconify-icon icon="solar:notebook-bold" width="12"></iconify-icon> ' + (r.total_mapel || 0) + ' Mapel</span></td>';
        html += '<td class="cell-siswa-count"><span class="cell-count cell-count--siswa"><iconify-icon icon="solar:square-academic-cap-2-bold" width="12"></iconify-icon> ' + (r.total_siswa || 0) + ' Siswa</span></td>';
        html += '<td><div class="row-actions">';
        html += '<button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" data-act="mapel" data-id="' + r.id + '"><iconify-icon icon="solar:notebook-bold" width="13"></iconify-icon> Mapel</button>';
        html += '<button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" data-act="siswa" data-id="' + r.id + '"><iconify-icon icon="solar:square-academic-cap-2-bold" width="13"></iconify-icon> Siswa</button>';
        html += '<button type="button" class="ilmuna-btn ilmuna-btn--outline ilmuna-btn--sm" data-act="edit" data-id="' + r.id + '"><iconify-icon icon="solar:pen-bold" width="13"></iconify-icon> Edit</button>';
        html += '<button type="button" class="ilmuna-btn ilmuna-btn--danger ilmuna-btn--sm" data-act="delete" data-id="' + r.id + '"><iconify-icon icon="solar:trash-bin-trash-bold" width="13"></iconify-icon> Hapus</button>';
        html += '</div></td>';
        return html;
    }

    // In-Place Realtime Manipulation Helpers
    function insertRowInPlace(r) {
        var tbody = document.getElementById('dosenTableKelasBody');
        var emptyRow = tbody.querySelector('.ilmuna-empty-row');
        if (emptyRow) tbody.innerHTML = '';

        var tr = document.createElement('tr');
        tr.setAttribute('data-id', r.id);
        tr.className = 'ilmuna-row-slide-in ilmuna-row-highlight-new';
        tr.innerHTML = buildClassRowCellsHtml(r, 1, false);

        var firstTr = tbody.querySelector('tr[data-id]');
        if (firstTr) {
            tbody.insertBefore(tr, firstTr);
        } else {
            var sentinel = document.getElementById('lazySentinelKelas');
            if (sentinel) tbody.insertBefore(tr, sentinel);
            else tbody.appendChild(tr);
        }

        renumberClassRows();
        state.total++;
        renderInfo();
        renderPagination();
        try { tr.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); } catch(e) {}
    }

    function updateRowInPlace(r) {
        var tbody = document.getElementById('dosenTableKelasBody');
        var tr = tbody.querySelector('tr[data-id="' + r.id + '"]');
        if (tr) {
            var curNum = tr.querySelector('.cell-num') ? tr.querySelector('.cell-num').textContent : '1';
            var isChecked = selectedClasses.has(parseInt(r.id, 10));
            tr.innerHTML = buildClassRowCellsHtml(r, curNum, isChecked);
            tr.classList.remove('ilmuna-row-highlight-updated');
            void tr.offsetWidth;
            tr.classList.add('ilmuna-row-highlight-updated');
            updateBulkBar();
        } else {
            insertRowInPlace(r);
        }
    }

    function removeRowInPlace(id) {
        var tbody = document.getElementById('dosenTableKelasBody');
        var tr = tbody.querySelector('tr[data-id="' + id + '"]');
        if (tr) {
            tr.style.transition = 'all 0.35s ease';
            tr.style.opacity = '0';
            tr.style.transform = 'translateX(35px)';
            setTimeout(function(){
                if (tr.parentNode) tr.parentNode.removeChild(tr);
                renumberClassRows();
                var remaining = tbody.querySelectorAll('tr[data-id]');
                if (remaining.length === 0) renderRows([]);
            }, 350);
            selectedClasses.delete(parseInt(id, 10));
            updateBulkBar();
            state.total = Math.max(0, state.total - 1);
            renderInfo();
            renderPagination();
        }
    }

    function renumberClassRows() {
        var tbody = document.getElementById('dosenTableKelasBody');
        var rows = tbody.querySelectorAll('tr[data-id]');
        rows.forEach(function(row, idx){
            var numCell = row.querySelector('.cell-num');
            if (numCell) numCell.textContent = idx + 1;
        });
    }

    // Lazy Load Observer (Infinite Scroll on Sentinel)
    var lazyObserverKelas = null;
    function initLazyLoad() {
        if (!('IntersectionObserver' in window)) return;
        var sentinel = document.getElementById('lazySentinelKelas');
        if (!sentinel) return;
        if (lazyObserverKelas) lazyObserverKelas.disconnect();
        lazyObserverKelas = new IntersectionObserver(function(entries){
            entries.forEach(function(entry){
                if (entry.isIntersecting && !state.loading && state.page < state.pages) {
                    state.page++;
                    fetchList({ append: true });
                }
            });
        }, { rootMargin: '250px' });
        lazyObserverKelas.observe(sentinel);
    }

    // Fetch Table List (Supports Initial, Live Search, Silent Refresh, and Lazy Append)
    function fetchList(opts) {
        opts = opts || {};
        var tbody = document.getElementById('dosenTableKelasBody');
        if (state.loading) return;
        state.loading = true;

        var sentinel = document.getElementById('lazySentinelKelas');

        if (opts.append) {
            if (sentinel) sentinel.style.display = '';
        } else if (opts.isLiveSearch) {
            tbody.style.opacity = '0.7';
            tbody.style.transition = 'opacity 0.15s ease';
        } else if (!opts.silent) {
            tbody.innerHTML =
                '<tr><td colspan="8" class="ilmuna-table-loading">' +
                '<iconify-icon icon="svg-spinners:ring-resize" width="28"></iconify-icon>' +
                '<span>Memuat data rombel kelas...</span></td></tr>';
        }

        var fd = new FormData();
        fd.append('action', 'list');
        fd.append('search', state.search);
        fd.append('f_tahun', state.f_tahun);
        fd.append('page', state.page);
        fd.append('per_page', state.per_page);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                state.loading = false;
                tbody.style.opacity = '1';
                if (!res || !res.success) {
                    if (!opts.append) {
                        tbody.innerHTML = '<tr><td colspan="8" class="ilmuna-table-loading" style="color:#DC2626;">Gagal memuat data kelas.</td></tr>';
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
                    tbody.innerHTML = '<tr><td colspan="8" class="ilmuna-table-loading" style="color:#DC2626;">Koneksi bermasalah.</td></tr>';
                }
                toast('error', 'Koneksi ke server terputus.');
            });
    }

    // Render Table Rows (Supports Progressive Lazy Loading Append)
    function renderRows(rows, opts) {
        opts = opts || {};
        var tbody = document.getElementById('dosenTableKelasBody');
        if (!opts.append && (!rows || !rows.length)) {
            tbody.innerHTML =
                '<tr><td colspan="8"><div class="ilmuna-empty-row">' +
                '<iconify-icon icon="solar:notebook-bookmark-bold" width="46"></iconify-icon>' +
                '<p>Belum ada data rombel kelas</p>' +
                '<small>Tambahkan kelas baru atau ubah kata kunci pencarian.</small>' +
                '</div></td></tr>';
            updateBulkBar();
            return;
        }

        var html = '';
        var startNum = opts.append ? tbody.querySelectorAll('tr[data-id]').length : (state.page - 1) * state.per_page;
        rows.forEach(function(r, i){
            var no = startNum + i + 1;
            var isChecked = selectedClasses.has(parseInt(r.id, 10));
            html += '<tr data-id="' + r.id + '" class="ilmuna-row-slide-in ' + (isChecked ? 'is-selected' : '') + '">';
            html += buildClassRowCellsHtml(r, no, isChecked);
            html += '</tr>';
        });

        var sentinelHtml = '<tr id="lazySentinelKelas" class="ilmuna-table-sentinel" style="display:' + (state.page < state.pages ? '' : 'none') + ';"><td colspan="8"><iconify-icon icon="svg-spinners:ring-resize" width="18" style="vertical-align: middle; margin-right: 6px;"></iconify-icon> Memuat data rombel kelas berikutnya...</td></tr>';

        if (opts.append) {
            var oldSentinel = document.getElementById('lazySentinelKelas');
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
        var infoEl = document.getElementById('dosenPagInfo');
        var btnsEl = document.getElementById('dosenPagBtns');
        var currentLoaded = document.querySelectorAll('#dosenTableKelasBody tr[data-id]').length;
        var end = Math.min(Math.max(currentLoaded, state.page * state.per_page), state.total);
        var start = state.total === 0 ? 0 : 1;

        infoEl.innerHTML = 'Menampilkan <strong>' + start + '</strong>–<strong>' + end + '</strong> dari <strong>' + state.total + '</strong> kelas';

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
        var info = document.getElementById('dosenCrudInfo');
        var chips = '';
        chips += '<span class="ilmuna-info-chip"><iconify-icon icon="solar:notebook-bookmark-bold" width="14"></iconify-icon> Total: ' + state.total + ' Rombel</span>';
        if (state.f_tahun) chips += '<span class="ilmuna-info-chip"><iconify-icon icon="solar:calendar-bold" width="14"></iconify-icon> Tahun: ' + escHtml(state.f_tahun) + '</span>';
        if (state.search)  chips += '<span class="ilmuna-info-chip"><iconify-icon icon="solar:magnifer-bold" width="14"></iconify-icon> Kata Kunci: "' + escHtml(state.search) + '"</span>';
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
                var selTahun = document.getElementById('dosenFilterTahun');
                var curT = selTahun.value;

                var optT = '<option value="">Semua Tahun</option>';
                (res.data.tahun || []).forEach(function(t){
                    optT += '<option value="' + escHtml(t) + '"' + (t === curT ? ' selected' : '') + '>' + escHtml(t) + '</option>';
                });
                selTahun.innerHTML = optT;
            })
            .catch(function(){});
    }

    // WhatsApp-Style Reactive Live Search (Even 1 character triggers instant search)
    var searchInputKelas = document.getElementById('dosenSearchKelas');
    var searchTimerKelas = null;
    var searchBoxKelas = searchInputKelas ? searchInputKelas.closest('.ilmuna-search-box') : null;

    if (searchInputKelas) {
        searchInputKelas.addEventListener('input', function(){
            var val = this.value;
            if (searchBoxKelas) searchBoxKelas.classList.add('is-searching');
            clearTimeout(searchTimerKelas);
            searchTimerKelas = setTimeout(function(){
                state.search = val.trim();
                state.page = 1;
                fetchList({ isLiveSearch: true, reset: true });
                if (searchBoxKelas) searchBoxKelas.classList.remove('is-searching');
            }, 180);
        });

        searchInputKelas.addEventListener('keydown', function(e){
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchTimerKelas);
                state.search = this.value.trim();
                state.page = 1;
                fetchList({ isLiveSearch: true, reset: true });
                if (searchBoxKelas) searchBoxKelas.classList.remove('is-searching');
            }
        });
    }

    // Events: Search & Filters
    document.getElementById('dosenBtnSearch').addEventListener('click', function(){
        clearTimeout(searchTimerKelas);
        state.search = document.getElementById('dosenSearchKelas').value.trim();
        state.page = 1;
        fetchList({ isLiveSearch: true, reset: true });
    });
    document.getElementById('dosenBtnReset').addEventListener('click', function(){
        clearTimeout(searchTimerKelas);
        document.getElementById('dosenSearchKelas').value = '';
        document.getElementById('dosenFilterTahun').value = '';
        state.search = ''; state.f_tahun = '';
        state.page = 1;
        fetchList({ isLiveSearch: true, reset: true });
    });
    document.getElementById('dosenFilterTahun').addEventListener('change', function(){
        state.f_tahun = this.value; state.page = 1; fetchList({ isLiveSearch: true, reset: true });
    });
    document.getElementById('dosenPerPage').addEventListener('change', function(){
        state.per_page = parseInt(this.value) || 20; state.page = 1; fetchList({ isLiveSearch: true, reset: true });
    });
    document.getElementById('dosenPagBtns').addEventListener('click', function(e){
        var btn = e.target.closest('.ilmuna-pag-btn');
        if (!btn || btn.disabled) return;
        var p = parseInt(btn.dataset.page) || 1;
        if (p === state.page) return;
        state.page = p;
        fetchList();
    });

    // Add Manual Class Button (now inside toolbar actions row)
    document.getElementById('dosenBtnAddKelas').addEventListener('click', function(){
        document.getElementById('dosenModalKelasTitle').textContent = 'Tambah Rombel Kelas Baru';
        var form = document.getElementById('dosenFormKelas');
        form.reset();
        form.querySelector('[name="id"]').value = '0';
        form.querySelector('[name="tahun_pelajaran"]').value = '2026/2027';
        openModal('dosenModalKelas');
    });

    // Table Action Delegator (Edit, Delete, Mapel, Siswa)
    document.getElementById('dosenTableKelasBody').addEventListener('click', function(e){
        var btn = e.target.closest('[data-act]');
        if (!btn) return;
        var act = btn.dataset.act;
        var id  = parseInt(btn.dataset.id) || 0;
        if (!id) return;
        if (act === 'edit')   openEdit(id);
        if (act === 'delete') doDelete(id);
        if (act === 'mapel')  openMapel(id);
        if (act === 'siswa')  openSiswa(id);
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
                    toast('error', (res && res.data && res.data.message) || 'Gagal memuat data kelas.');
                    return;
                }
                var row = res.data.row;
                document.getElementById('dosenModalKelasTitle').textContent = 'Edit Data Rombel Kelas';
                var form = document.getElementById('dosenFormKelas');
                form.querySelector('[name="id"]').value = row.id;
                form.querySelector('[name="nama_kelas"]').value = row.nama_kelas || '';
                form.querySelector('[name="tahun_pelajaran"]').value = row.tahun_pelajaran || '2026/2027';
                openModal('dosenModalKelas');
            })
            .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
    }

    // Save Class (Add or Edit)
    document.getElementById('dosenBtnSaveKelas').addEventListener('click', function(){
        var btn = this;
        var form = document.getElementById('dosenFormKelas');
        var id = parseInt(form.querySelector('[name="id"]').value) || 0;
        var nama = form.querySelector('[name="nama_kelas"]').value.trim();
        var thn  = form.querySelector('[name="tahun_pelajaran"]').value.trim();

        if (!nama) { toast('warning', 'Nama rombel kelas wajib diisi.'); return; }
        if (!thn)  { toast('warning', 'Tahun pelajaran wajib diisi.'); return; }

        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'save');
        fd.append('id', id);
        fd.append('nama_kelas', nama);
        fd.append('tahun_pelajaran', thn);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal menyimpan.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('dosenModalKelas');

                // In-Place Dynamic DOM Update without Reload
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

    // Delete Class
    function doDelete(id) {
        confirmDialog('Yakin ingin menghapus rombel kelas ini? Data relasi mata pelajaran pada kelas ini juga akan dihapus.', {
            title: 'Hapus Rombel Kelas?',
            okText: 'Ya, Hapus Kelas'
        }).then(function(ok){
            if (!ok) return;
            var fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', id);
            fetch(AJAX_URL, { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(res){
                    if (!res || !res.success) {
                        toast('error', (res && res.data && res.data.message) || 'Gagal menghapus kelas.');
                        return;
                    }
                    toast('success', res.data.message);
                    removeRowInPlace(id);
                    loadFilterOptions();
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    }

    // Mata Pelajaran Modal
    function openMapel(id) {
        document.getElementById('dosenMatkulKelasId').value = id;
        document.getElementById('dosenMatkulKelasName').textContent = '—';
        document.getElementById('dosenMatkulList').innerHTML =
            '<div style="text-align:center;padding:24px;color:#94A3B8;">' +
            '<iconify-icon icon="svg-spinners:ring-resize" width="28"></iconify-icon></div>';
        openModal('dosenModalMatkul');

        var fd = new FormData();
        fd.append('action', 'get_mapel');
        fd.append('id_kelas', id);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal memuat mata pelajaran.');
                    return;
                }
                var kelas      = res.data.kelas || {};
                var mapel      = res.data.mapel || [];
                var linked     = res.data.linked || [];
                var gmpByMapel = res.data.gmp_by_mapel || {};
                var guruMap    = res.data.guru_map || {};
                var kodeMap    = res.data.kode_map || {};

                document.getElementById('dosenMatkulKelasName').textContent = kelas.nama_kelas || '—';

                if (!mapel.length) {
                    document.getElementById('dosenMatkulList').innerHTML =
                        '<div class="ilmuna-empty-row">' +
                        '<iconify-icon icon="solar:notebook-bookmark-bold" width="38"></iconify-icon>' +
                        '<p>Belum ada mata pelajaran terdaftar pada sistem</p>' +
                        '</div>';
                    return;
                }

                var html = '';
                mapel.forEach(function(m){
                    var idm = parseInt(m.id);
                    var checked = linked.indexOf(idm) !== -1;
                    var selectedGmp = guruMap[idm] ? parseInt(guruMap[idm]) : 0;
                    var teachers = gmpByMapel[idm] || [];
                    var kodeKmp = (checked && kodeMap[idm]) ? kodeMap[idm] : '';
                    var kodeBadge = kodeKmp ? ' <span style="display:inline-flex;align-items:center;gap:3px;background:#E0F2FE;color:#0369A1;font-size:11px;font-weight:700;padding:2px 7px;border-radius:5px;border:1px solid #BAE6FD;margin-left:6px;" title="Kode Rombel Mapel Permanen"><iconify-icon icon=\"solar:hashtag-bold\" width=\"11\"></iconify-icon>' + escHtml(kodeKmp) + '</span>' : '';

                    var guruOpts = '';
                    var hintHtml = '';

                    if (!teachers.length) {
                        guruOpts = '<option value="">-- Belum ada guru untuk mapel ini (Atur di Master Data Guru) --</option>';
                        hintHtml = '<div class="ilmuna-guru-notice">' +
                            '<iconify-icon icon="solar:info-circle-bold" width="14"></iconify-icon>' +
                            '<span>Guru pengampu belum dipetakan. Atur di menu <a href="<?= BASE_URL ?>/dashboard/admin/teachers" target="_blank" style="color:#B45309;font-weight:700;text-decoration:underline;">Data Guru</a> terlebih dahulu.</span>' +
                            '</div>';
                    } else {
                        guruOpts = '<option value="">-- Pilih Guru Pengampu (' + teachers.length + ' Guru Terpetakan) --</option>';
                        teachers.forEach(function(g){
                            var gmpId = parseInt(g.id_guru_mata_pelajaran);
                            var isSel = (gmpId === selectedGmp) ? ' selected' : '';
                            var nipText = g.nip ? ' (' + g.nip + ')' : '';
                            var statusText = g.status_kepegawaian ? ' [' + g.status_kepegawaian + ']' : '';
                            guruOpts += '<option value="' + gmpId + '"' + isSel + '>' + escHtml(g.nama_guru) + nipText + statusText + '</option>';
                        });
                        hintHtml = '<div class="ilmuna-guru-hint">' +
                            '<iconify-icon icon="solar:check-circle-bold" width="13"></iconify-icon>' +
                            '<span>' + teachers.length + ' guru terpetakan untuk mata pelajaran ini</span>' +
                            '</div>';
                    }

                    html += '<div class="ilmuna-matkul-item ' + (checked ? 'is-checked' : '') + '" data-search="' + escHtml((m.nama_mata_pelajaran + ' ' + (m.semester||'') + ' ' + (m.tahun_pelajaran||'')).toLowerCase()) + '">';
                    html += '  <div class="ilmuna-matkul-item__head">';
                    html += '    <input type="checkbox" class="ilmuna-mapel-cb" value="' + m.id + '"' + (checked ? ' checked' : '') + '>';
                    html += '    <div class="ilmuna-matkul-item__info">';
                    html += '      <span class="ilmuna-matkul-item__title">' + escHtml(m.nama_mata_pelajaran || '-') + kodeBadge + '</span>';
                    html += '      <span class="ilmuna-matkul-item__meta">';
                    if (m.semester)        html += '<span><iconify-icon icon="solar:notebook-bold" width="12"></iconify-icon> Semester ' + escHtml(m.semester) + '</span>';
                    if (m.tahun_pelajaran) html += '<span><iconify-icon icon="solar:calendar-bold" width="12"></iconify-icon> TP ' + escHtml(m.tahun_pelajaran) + '</span>';
                    html += '      </span>';
                    html += '    </div>';
                    html += '  </div>';
                    html += '  <div class="ilmuna-matkul-item__guru" style="' + (checked ? '' : 'display:none;') + '">';
                    html += '    <label><iconify-icon icon="solar:user-speak-bold" width="13"></iconify-icon> Guru Pengampu Mapel (Berdasarkan Relasi Guru Mapel):</label>';
                    html += '    <select class="ilmuna-select-sm ilmuna-guru-select' + (!teachers.length ? ' is-empty' : '') + '" data-mapel="' + m.id + '"' + (!teachers.length ? ' disabled' : '') + '>' + guruOpts + '</select>';
                    html += hintHtml;
                    html += '  </div>';
                    html += '</div>';
                });
                document.getElementById('dosenMatkulList').innerHTML = html;

                document.querySelectorAll('#dosenMatkulList .ilmuna-mapel-cb').forEach(function(cb){
                    cb.addEventListener('change', function(){
                        var item = cb.closest('.ilmuna-matkul-item');
                        if (item) {
                            item.classList.toggle('is-checked', cb.checked);
                            var gDiv = item.querySelector('.ilmuna-matkul-item__guru');
                            if (gDiv) gDiv.style.display = cb.checked ? '' : 'none';
                        }
                    });
                });
            })
            .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
    }

    // Filter Mapel in Modal
    document.getElementById('dosenSearchMatkul').addEventListener('input', function(){
        var q = this.value.toLowerCase();
        document.querySelectorAll('#dosenMatkulList .ilmuna-matkul-item').forEach(function(item){
            var s = item.dataset.search || '';
            item.style.display = (!q || s.indexOf(q) !== -1) ? '' : 'none';
        });
    });

    // Save Mapel Relations & Assigned Teachers
    document.getElementById('dosenBtnSaveMatkul').addEventListener('click', function(){
        var btn = this;
        var idKelas = parseInt(document.getElementById('dosenMatkulKelasId').value) || 0;
        if (!idKelas) return;

        var selected = [];
        var guruMapel = {};
        document.querySelectorAll('#dosenMatkulList .ilmuna-mapel-cb:checked').forEach(function(cb){
            var idm = cb.value;
            selected.push(idm);
            var item = cb.closest('.ilmuna-matkul-item');
            var gSel = item ? item.querySelector('.ilmuna-guru-select') : null;
            if (gSel && gSel.value) {
                guruMapel[idm] = gSel.value;
            }
        });

        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'save_mapel');
        fd.append('id_kelas', idKelas);
        selected.forEach(function(v){
            fd.append('mapel[]', v);
            if (guruMapel[v]) {
                fd.append('guru_mapel[' + v + ']', guruMapel[v]);
            }
        });

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal menyimpan relasi.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('dosenModalMatkul');
                if (res.data && res.data.row) {
                    updateRowInPlace(res.data.row);
                } else {
                    fetchList({ silent: true });
                }
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

    // Preview Siswa Modal
    function openSiswa(id) {
        document.getElementById('dosenSiswaKelasName').textContent = '—';
        document.getElementById('dosenSiswaCount').textContent = 'Memuat...';
        document.getElementById('dosenBtnGoToStudents').href = '<?= BASE_URL ?>/dashboard/admin/students?class_id=' + id;
        document.getElementById('dosenSiswaTableBody').innerHTML =
            '<tr><td colspan="4" class="ilmuna-table-loading">' +
            '<iconify-icon icon="svg-spinners:ring-resize" width="24"></iconify-icon>' +
            '<span>Memuat daftar siswa...</span></td></tr>';
        openModal('dosenModalSiswa');

        var fd = new FormData();
        fd.append('action', 'get_siswa');
        fd.append('id_kelas', id);

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal memuat siswa.');
                    return;
                }
                var kelas = res.data.kelas || {};
                var siswa = res.data.siswa || [];

                document.getElementById('dosenSiswaKelasName').textContent = kelas.nama_kelas || '—';
                document.getElementById('dosenSiswaCount').textContent = 'Total: ' + siswa.length + ' Peserta Didik';

                if (!siswa.length) {
                    document.getElementById('dosenSiswaTableBody').innerHTML =
                        '<tr><td colspan="4" class="ilmuna-empty-row">' +
                        '<iconify-icon icon="solar:square-academic-cap-2-bold" width="34"></iconify-icon>' +
                        '<p>Belum ada siswa terdaftar pada rombel ini</p>' +
                        '</td></tr>';
                    return;
                }

                var html = '';
                siswa.forEach(function(s, idx){
                    html += '<tr>';
                    html += '<td style="font-family:monospace;font-weight:700;color:#64748B;">' + (idx + 1) + '</td>';
                    html += '<td><span class="cell-pill cell-pill--navy" style="font-family:monospace;">' + escHtml(s.nisn || s.nis || '-') + '</span></td>';
                    html += '<td><strong>' + escHtml(s.nama_lengkap || '-') + '</strong></td>';
                    html += '<td style="color:#64748B;font-size:12px;">' + escHtml(s.no_hp || s.gmail || '-') + '</td>';
                    html += '</tr>';
                });
                document.getElementById('dosenSiswaTableBody').innerHTML = html;
            })
            .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
    }

    // =========================================================================
    // EXCEL IMPORT & EXPORT CONTROLLERS (BLOB DOWNLOAD & MODULAR BACKEND)
    // =========================================================================

    // Drag-and-drop & File Selection Preview
    var fileDropzone = document.getElementById('dosenFileDropzone');
    var fileInput = document.getElementById('dosenImportFile');
    var fileSelectedName = document.getElementById('dosenSelectedFileName');

    function updateFilePreview(file) {
        if (!fileSelectedName) return;
        if (file) {
            var sizeKb = Math.round(file.size / 1024);
            fileSelectedName.innerHTML = '<iconify-icon icon="solar:file-check-bold" width="16" style="vertical-align:middle;"></iconify-icon> Terpilih: <strong>' + escHtml(file.name) + '</strong> (' + sizeKb + ' KB)';
            fileSelectedName.style.display = 'block';
        } else {
            fileSelectedName.textContent = '';
            fileSelectedName.style.display = 'none';
        }
    }

    if (fileInput) {
        fileInput.addEventListener('change', function(){
            updateFilePreview(this.files ? this.files[0] : null);
        });
    }

    if (fileDropzone) {
        ['dragenter', 'dragover'].forEach(function(evtName){
            fileDropzone.addEventListener(evtName, function(e){
                e.preventDefault();
                e.stopPropagation();
                fileDropzone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(function(evtName){
            fileDropzone.addEventListener(evtName, function(e){
                e.preventDefault();
                e.stopPropagation();
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

    // Modal Import Open
    document.getElementById('dosenBtnImport').addEventListener('click', function(){
        if (fileInput) fileInput.value = '';
        updateFilePreview(null);
        var logWrap = document.getElementById('dosenImportLog');
        if (logWrap) {
            logWrap.style.display = 'none';
            logWrap.innerHTML = '';
        }
        openModal('dosenModalImport');
    });

    // Download Template Buttons (Blob Method)
    var btnTpl = document.getElementById('dosenBtnDownloadTemplate');
    if (btnTpl) {
        btnTpl.addEventListener('click', function(e){
            e.preventDefault();
            e.stopPropagation();
            downloadBlob(EXCEL_URL + '?action=download_template', 'template-import-kelas-ilmuna.xlsx', this, 'Mengunduh Template...');
        });
    }

    var btnModalTpl = document.getElementById('dosenBtnModalDownloadTemplate');
    if (btnModalTpl) {
        btnModalTpl.addEventListener('click', function(e){
            e.preventDefault();
            e.stopPropagation();
            downloadBlob(EXCEL_URL + '?action=download_template', 'template-import-kelas-ilmuna.xlsx', this, 'Mengunduh...');
        });
    }

    // Eksekusi Import Excel via classes-excel.php
    document.getElementById('dosenBtnDoImport').addEventListener('click', function(e){
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
                var logWrap = document.getElementById('dosenImportLog');
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

    // Export Excel Toolbar Button (Blob Method)
    document.getElementById('dosenBtnExport').addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        var exportUrl = EXCEL_URL + '?action=export_excel' +
            '&search=' + encodeURIComponent(state.search) +
            '&f_tahun=' + encodeURIComponent(state.f_tahun);
        downloadBlob(exportUrl, 'data-kelas-ilmuna.xlsx', this, 'Mengekspor...');
    });

    // Bulk Actions: Select/Deselect handlers
    document.getElementById('checkAllClasses').addEventListener('change', function(){
        var isChecked = this.checked;
        document.querySelectorAll('.ilmuna-class-cb').forEach(function(cb){
            var id = parseInt(cb.value);
            cb.checked = isChecked;
            var row = cb.closest('tr');
            if (isChecked) {
                selectedClasses.add(id);
                if (row) row.classList.add('is-selected');
            } else {
                selectedClasses.delete(id);
                if (row) row.classList.remove('is-selected');
            }
        });
        updateBulkBar();
    });

    document.getElementById('dosenTableKelasBody').addEventListener('change', function(e){
        if (e.target && e.target.classList.contains('ilmuna-class-cb')) {
            var cb = e.target;
            var id = parseInt(cb.value);
            var row = cb.closest('tr');
            if (cb.checked) {
                selectedClasses.add(id);
                if (row) row.classList.add('is-selected');
            } else {
                selectedClasses.delete(id);
                if (row) row.classList.remove('is-selected');
            }
            updateBulkBar();
        }
    });

    // Close / Deselect Bulk Bar
    document.getElementById('btnBulkCloseClasses').addEventListener('click', function(){
        selectedClasses.clear();
        document.querySelectorAll('.ilmuna-class-cb').forEach(function(cb){
            cb.checked = false;
            var row = cb.closest('tr');
            if (row) row.classList.remove('is-selected');
        });
        updateBulkBar();
    });

    // Bulk Export to Excel (Blob Method)
    document.getElementById('btnBulkExportClasses').addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        if (!selectedClasses.size) return;
        var ids = Array.from(selectedClasses).join(',');
        var exportUrl = EXCEL_URL + '?action=export_excel&ids=' + encodeURIComponent(ids);
        downloadBlob(exportUrl, 'data-kelas-terpilih-ilmuna.xlsx', this, 'Mengekspor ' + selectedClasses.size + ' Kelas...');
    });

    // Bulk Delete
    document.getElementById('btnBulkDeleteClasses').addEventListener('click', function(){
        if (!selectedClasses.size) return;
        var count = selectedClasses.size;
        confirmDialog('Yakin ingin menghapus ' + count + ' rombel kelas terpilih? Kelas yang masih memiliki siswa tidak akan terhapus demi keselamatan data.', {
            title: 'Hapus Masal (' + count + ' Kelas)?',
            okText: 'Ya, Hapus Terpilih'
        }).then(function(ok){
            if (!ok) return;
            var fd = new FormData();
            fd.append('action', 'bulk_delete');
            selectedClasses.forEach(function(id){
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
                    var deletedIds = (res.data && res.data.deleted_ids) || Array.from(selectedClasses);
                    deletedIds.forEach(function(cid){
                        removeRowInPlace(cid);
                    });
                    selectedClasses.clear();
                    updateBulkBar();
                    loadFilterOptions();
                })
                .catch(function(){ toast('error', 'Koneksi bermasalah.'); });
        });
    });

    // Bulk Update Tahun Pelajaran Modal
    document.getElementById('btnBulkUpdateTahun').addEventListener('click', function(){
        if (!selectedClasses.size) return;
        document.getElementById('bulkTahunCountText').textContent = selectedClasses.size;
        openModal('modalBulkUpdateTahun');
    });

    document.getElementById('btnSubmitBulkTahun').addEventListener('click', function(){
        var btn = this;
        var tahun = document.getElementById('inputBulkTahun').value.trim();
        if (!tahun) {
            toast('warning', 'Tahun pelajaran tidak boleh kosong.');
            return;
        }

        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'bulk_update_tahun');
        fd.append('tahun_pelajaran', tahun);
        selectedClasses.forEach(function(id){
            fd.append('ids[]', id);
        });

        fetch(AJAX_URL, { method: 'POST', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (!res || !res.success) {
                    toast('error', (res && res.data && res.data.message) || 'Gagal memperbarui tahun pelajaran.');
                    return;
                }
                toast('success', res.data.message);
                closeModal('modalBulkUpdateTahun');
                var updatedIds = (res.data && res.data.updated_ids) || Array.from(selectedClasses);
                updatedIds.forEach(function(cid){
                    var tr = document.querySelector('#dosenTableKelasBody tr[data-id="' + cid + '"]');
                    if (tr) {
                        var thnCell = tr.querySelector('.cell-tahun');
                        if (thnCell) {
                            thnCell.innerHTML = '<span class="cell-pill cell-pill--tahun">' + escHtml(tahun) + '</span>';
                        }
                        tr.classList.remove('ilmuna-row-highlight-updated');
                        void tr.offsetWidth;
                        tr.classList.add('ilmuna-row-highlight-updated');
                    }
                });
                selectedClasses.clear();
                updateBulkBar();
                loadFilterOptions();
            })
            .catch(function(){
                btn.disabled = false;
                toast('error', 'Koneksi bermasalah.');
            });
    });

    // Initial Load
    fetchList();
    loadFilterOptions();

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>