<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — WORDPRESS API DATA BUILDER (SANITIZED PUBLIC DTOs)
 * ==============================================================================
 * Membangun payload JSON yang bersih, kaya informasi, dan 100% bebas PII sensitif
 * (TIDAK PERNAH mengekspos NISN, alamat, no HP, email, password, atau token internal).
 *
 * Resource yang didukung:
 *   1. proyek_showcase (alias: showcase)  -> Galeri Hasil Karya Proyek Siswa (PjBL / P5)
 *   2. tugas_broadcast (alias: tugas)     -> Broadcast Tugas & Deadline Tugas
 *   3. proyek_broadcast (alias: proyek)   -> Broadcast Proyek & Deadline Proyek
 *   4. all (alias: feed, broadcast)       -> Paket lengkap (3 feed sekaligus untuk cache WP)
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Resolve Base URL produksi/lokal yang bersih untuk URL publik aset & portal
 */
function ilmuna_wp_api_public_base_url()
{
    if (defined('BASE_URL') && strpos(BASE_URL, 'http') === 0 && strpos(BASE_URL, 'localhost') === false) {
        return rtrim(BASE_URL, '/');
    }
    if (php_sapi_name() === 'cli' && defined('APP_DOMAIN') && APP_DOMAIN !== '') {
        return 'https://' . APP_DOMAIN;
    }
    return defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://learn.ilmuna.my.id';
}

/**
 * Konversi path file relatif (uploads/...) menjadi URL publik penuh yang aman
 */
function ilmuna_wp_api_asset_url($relativePath)
{
    $p = trim((string)$relativePath);
    if ($p === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $p)) {
        return $p;
    }
    $p = ltrim(str_replace('\\', '/', $p), '/');
    if (strpos($p, '..') !== false) {
        return null;
    }
    return ilmuna_wp_api_public_base_url() . '/' . $p;
}

/**
 * Periksa apakah path file merupakan gambar yang bisa dirender sebagai thumbnail
 */
function ilmuna_wp_api_is_image_path($path)
{
    $ext = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
}

/**
 * Masking opsional nama belakang siswa untuk privasi bila diaktifkan Admin
 */
function ilmuna_wp_api_format_student_name($fullName, $hideLastName = false)
{
    $name = trim(preg_replace('/\s+/', ' ', (string)$fullName));
    if ($name === '') {
        return 'Siswa';
    }
    if (!$hideLastName) {
        return $name;
    }
    $parts = explode(' ', $name);
    if (count($parts) <= 1) {
        return $parts[0];
    }
    $first = array_shift($parts);
    $initials = [];
    foreach ($parts as $part) {
        $ch = function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
        if ($ch !== '') {
            $up = function_exists('mb_strtoupper') ? mb_strtoupper($ch, 'UTF-8') : strtoupper($ch);
            $initials[] = $up . '.';
        }
    }
    return trim($first . ' ' . implode(' ', $initials));
}

/**
 * Ringkas teks HTML/teks panjang menjadi plain text bersih untuk kartu broadcast WordPress
 */
function ilmuna_wp_api_excerpt($text, $maxLen = 220)
{
    $plain = html_entity_decode(strip_tags((string)$text), ENT_QUOTES, 'UTF-8');
    $plain = trim(preg_replace('/\s+/', ' ', $plain));
    if ($plain === '') {
        return '';
    }
    $len = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
    if ($len <= $maxLen) {
        return $plain;
    }
    $cut = function_exists('mb_substr') ? mb_substr($plain, 0, $maxLen - 1, 'UTF-8') : substr($plain, 0, $maxLen - 1);
    return rtrim($cut) . '…';
}

/**
 * Format tanggal/waktu Bahasa Indonesia yang ramah tampilan publik WordPress
 */
function ilmuna_wp_api_format_id_date($dateStr, $withTime = false)
{
    $ts = strtotime((string)$dateStr);
    if ($ts === false) {
        return null;
    }
    $bulan = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];
    $hari = [
        0 => 'Min', 1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab'
    ];
    $h = $hari[(int)date('w', $ts)];
    $b = $bulan[(int)date('n', $ts)];
    $base = $h . ', ' . date('d', $ts) . ' ' . $b . ' ' . date('Y', $ts);
    if ($withTime) {
        return $base . ' · ' . date('H:i', $ts) . ' WIB';
    }
    return $base;
}

/**
 * Hitung label countdown & tingkat urgensi deadline
 */
function ilmuna_wp_api_deadline_meta($deadlineStr, $isDateOnly = false)
{
    $now = time();
    if ($deadlineStr === null || trim((string)$deadlineStr) === '') {
        return [
            'sisa_waktu_detik' => null,
            'sisa_hari'        => null,
            'sisa_waktu_label' => 'Tanpa tenggat',
            'is_expired'       => false,
            'urgensi'          => 'normal',
        ];
    }

    $targetStr = $isDateOnly ? (trim((string)$deadlineStr) . ' 23:59:59') : trim((string)$deadlineStr);
    $ts = strtotime($targetStr);
    if ($ts === false) {
        return [
            'sisa_waktu_detik' => null,
            'sisa_hari'        => null,
            'sisa_waktu_label' => '-',
            'is_expired'       => false,
            'urgensi'          => 'normal',
        ];
    }

    $diff = $ts - $now;
    $daysFloat = $diff / 86400;
    $daysCeil  = (int)ceil($daysFloat);

    if ($diff <= 0) {
        $agoSec = abs($diff);
        if ($agoSec < 3600) {
            $lbl = 'Berakhir ' . max(1, (int)floor($agoSec / 60)) . ' menit lalu';
        } elseif ($agoSec < 86400) {
            $lbl = 'Berakhir ' . (int)floor($agoSec / 3600) . ' jam lalu';
        } else {
            $lbl = 'Berakhir ' . (int)floor($agoSec / 86400) . ' hari lalu';
        }
        return [
            'sisa_waktu_detik' => $diff,
            'sisa_hari'        => (int)floor($daysFloat),
            'sisa_waktu_label' => $lbl,
            'is_expired'       => true,
            'urgensi'          => 'berakhir',
        ];
    }

    $days  = (int)floor($diff / 86400);
    $hours = (int)floor(($diff % 86400) / 3600);
    $mins  = (int)floor(($diff % 3600) / 60);

    if ($days >= 1) {
        $lbl = $days . ' hari' . ($hours > 0 ? ' ' . $hours . ' jam' : '') . ' lagi';
    } elseif ($hours >= 1) {
        $lbl = $hours . ' jam' . ($mins > 0 ? ' ' . $mins . ' menit' : '') . ' lagi';
    } else {
        $lbl = max(1, $mins) . ' menit lagi';
    }

    if ($diff <= 86400) {
        $urgensi = 'kritis';
    } elseif ($diff <= 259200) {
        $urgensi = 'segera';
    } else {
        $urgensi = 'normal';
    }

    return [
        'sisa_waktu_detik' => $diff,
        'sisa_hari'        => $daysCeil,
        'sisa_waktu_label' => $lbl,
        'is_expired'       => false,
        'urgensi'          => $urgensi,
    ];
}

/**
 * Profil publik sekolah untuk header/branding di WordPress
 */
function ilmuna_wp_api_fetch_school_profile(PDO $pdo)
{
    try {
        $row = $pdo->query("
            SELECT nama_sekolah, nama_singkat, jenjang, akreditasi, kurikulum_utama,
                   website, logo_sekolah, semboyan_motto, tahun_pelajaran_aktif, semester_aktif
            FROM identitas_sekolah
            WHERE is_active = 1
            ORDER BY id ASC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $row = $pdo->query("
                SELECT nama_sekolah, nama_singkat, jenjang, akreditasi, kurikulum_utama,
                       website, logo_sekolah, semboyan_motto, tahun_pelajaran_aktif, semester_aktif
                FROM identitas_sekolah
                ORDER BY id ASC
                LIMIT 1
            ")->fetch(PDO::FETCH_ASSOC);
        }
        if ($row) {
            return [
                'nama_sekolah'          => (string)($row['nama_sekolah'] ?? 'Ilmuna Learn'),
                'nama_singkat'          => (string)($row['nama_singkat'] ?? 'Ilmuna'),
                'jenjang'               => (string)($row['jenjang'] ?? ''),
                'akreditasi'            => (string)($row['akreditasi'] ?? ''),
                'kurikulum'             => (string)($row['kurikulum_utama'] ?? 'Kurikulum Merdeka'),
                'semboyan'              => (string)($row['semboyan_motto'] ?? ''),
                'logo_url'              => ilmuna_wp_api_asset_url($row['logo_sekolah'] ?? ''),
                'tahun_pelajaran_aktif' => (string)($row['tahun_pelajaran_aktif'] ?? ''),
                'semester_aktif'        => (string)($row['semester_aktif'] ?? ''),
                'portal_lms_url'        => ilmuna_wp_api_public_base_url(),
            ];
        }
    } catch (Throwable $e) {
        // Fallback aman
    }

    return [
        'nama_sekolah'   => defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn',
        'nama_singkat'   => 'Ilmuna',
        'portal_lms_url' => ilmuna_wp_api_public_base_url(),
    ];
}

/**
 * 1. SHOWCASE HASIL KARYA PROYEK SISWA (pengumpulan_proyek + proyek)
 */
function ilmuna_wp_api_fetch_proyek_showcase(PDO $pdo, array $params = [], array $clientCfg = [])
{
    $limit = max(1, min(50, (int)($params['limit'] ?? 12)));
    $page  = max(1, (int)($params['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $kelasId  = isset($params['kelas_id']) && ctype_digit((string)$params['kelas_id']) ? (int)$params['kelas_id'] : 0;
    $mapelId  = isset($params['mapel_id']) && ctype_digit((string)$params['mapel_id']) ? (int)$params['mapel_id'] : 0;
    $proyekId = isset($params['proyek_id']) && ctype_digit((string)$params['proyek_id']) ? (int)$params['proyek_id'] : 0;
    $predikat = trim((string)($params['predikat'] ?? ''));
    $search   = trim((string)($params['q'] ?? ''));

    $minStatus = (string)($clientCfg['showcase_min_status'] ?? 'Disetujui_atau_Dipublikasikan');
    // Parameter query hanya boleh memperketat filter, tidak boleh melonggarkan kebijakan Admin!
    if (isset($params['only_published']) && (string)$params['only_published'] === '1') {
        $minStatus = 'Dipublikasikan';
    }

    $where = ["p.status_proyek <> 'Diarsipkan'"];
    $bind = [];

    if ($minStatus === 'Dipublikasikan') {
        $where[] = "pp.status_pengumpulan = 'Dipublikasikan'";
    } elseif ($minStatus === 'Disetujui_atau_Dipublikasikan') {
        $where[] = "pp.status_pengumpulan IN ('Dipublikasikan', 'Disetujui')";
    } else {
        $where[] = "pp.status_pengumpulan IN ('Dipublikasikan', 'Disetujui', 'Diserahkan')";
    }

    if ($kelasId > 0) {
        $where[] = "k.id = ?";
        $bind[] = $kelasId;
    }
    if ($mapelId > 0) {
        $where[] = "mp.id = ?";
        $bind[] = $mapelId;
    }
    if ($proyekId > 0) {
        $where[] = "p.id = ?";
        $bind[] = $proyekId;
    }
    if ($predikat !== '') {
        $where[] = "pp.predikat_proyek = ?";
        $bind[] = $predikat;
    }
    if ($search !== '') {
        $where[] = "(pp.judul_laporan_produk LIKE ? OR p.nama_proyek LIKE ? OR p.tema_proyek LIKE ?)";
        $like = '%' . $search . '%';
        $bind[] = $like;
        $bind[] = $like;
        $bind[] = $like;
    }

    $whereSql = implode(' AND ', $where);

    $countSql = "
        SELECT COUNT(*)
        FROM pengumpulan_proyek pp
        JOIN proyek p ON pp.id_proyek = p.id
        JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        WHERE {$whereSql}
    ";
    $cStmt = $pdo->prepare($countSql);
    $cStmt->execute($bind);
    $total = (int)$cStmt->fetchColumn();

    $sql = "
        SELECT pp.id, pp.id_proyek, pp.id_proyek_kelompok, pp.tipe_pengumpulan,
               pp.judul_laporan_produk, pp.deskripsi_produk,
               pp.file_laporan, pp.file_nama_asli, pp.file_dokumentasi,
               pp.link_produk, pp.link_video_presentasi,
               pp.status_pengumpulan, pp.nilai_proyek, pp.predikat_proyek,
               pp.catatan_evaluasi_guru, pp.waktu_pengumpulan,
               p.nama_proyek, p.tema_proyek, p.jenis_proyek, p.dimensi_profil_pancasila,
               p.target_luaran, p.jadwal_mulai, p.jadwal_selesai, p.status_proyek,
               k.id AS kelas_id, k.nama_kelas,
               mp.id AS mapel_id, mp.nama_mata_pelajaran, mp.kode_mapel,
               COALESCE(g2.nama_guru, g.nama_guru, u.full_name, 'Guru Pengampu') AS nama_guru,
               s.nama_lengkap AS pengumpul_nama,
               pk.nomor_kelompok, pk.nama_kelompok
        FROM pengumpulan_proyek pp
        JOIN proyek p ON pp.id_proyek = p.id
        JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g2 ON gmp.id_guru = g2.id
        LEFT JOIN guru g ON p.id_guru = g.id_user
        LEFT JOIN users u ON p.id_guru = u.id
        LEFT JOIN siswa s ON pp.id_siswa = s.id
        LEFT JOIN proyek_kelompok pk ON pp.id_proyek_kelompok = pk.id
        WHERE {$whereSql}
        ORDER BY FIELD(pp.status_pengumpulan, 'Dipublikasikan', 'Disetujui', 'Diserahkan'),
                 COALESCE(pp.nilai_proyek, 0) DESC,
                 pp.waktu_pengumpulan DESC,
                 pp.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Preload anggota kelompok dalam 1 query batch (anti N+1)
    $kelompokIds = [];
    foreach ($rows as $r) {
        if (!empty($r['id_proyek_kelompok'])) {
            $kelompokIds[] = (int)$r['id_proyek_kelompok'];
        }
    }
    $kelompokIds = array_values(array_unique($kelompokIds));
    $anggotaMap = [];
    $hideLast = !empty($clientCfg['hide_student_lastname']);

    if (!empty($kelompokIds)) {
        $inPlaceholders = implode(',', array_fill(0, count($kelompokIds), '?'));
        $aStmt = $pdo->prepare("
            SELECT pka.id_proyek_kelompok, pka.peran_anggota, s.nama_lengkap
            FROM proyek_kelompok_anggota pka
            JOIN siswa s ON pka.id_siswa = s.id
            WHERE pka.id_proyek_kelompok IN ({$inPlaceholders})
            ORDER BY FIELD(pka.peran_anggota, 'Ketua', 'Anggota'), s.nama_lengkap ASC
        ");
        $aStmt->execute($kelompokIds);
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
            $kid = (int)$ar['id_proyek_kelompok'];
            if (!isset($anggotaMap[$kid])) {
                $anggotaMap[$kid] = [];
            }
            $anggotaMap[$kid][] = [
                'nama'  => ilmuna_wp_api_format_student_name($ar['nama_lengkap'], $hideLast),
                'peran' => (string)($ar['peran_anggota'] ?? 'Anggota'),
            ];
        }
    }

    $items = [];
    foreach ($rows as $r) {
        $kid = !empty($r['id_proyek_kelompok']) ? (int)$r['id_proyek_kelompok'] : 0;
        $dokUrl = ilmuna_wp_api_asset_url($r['file_dokumentasi']);
        $lapUrl = ilmuna_wp_api_asset_url($r['file_laporan']);
        $thumbUrl = ($dokUrl && ilmuna_wp_api_is_image_path($r['file_dokumentasi']))
            ? $dokUrl
            : (($lapUrl && ilmuna_wp_api_is_image_path($r['file_laporan'])) ? $lapUrl : null);

        $dimRaw = trim((string)($r['dimensi_profil_pancasila'] ?? ''));
        $dimArr = $dimRaw !== '' ? array_values(array_filter(array_map('trim', explode(',', $dimRaw)))) : [];

        $tim = null;
        if ($kid > 0) {
            $tim = [
                'nomor_kelompok' => (int)($r['nomor_kelompok'] ?? 0),
                'nama_kelompok'  => (string)($r['nama_kelompok'] ?? ('Kelompok ' . (int)$r['nomor_kelompok'])),
                'anggota'        => isset($anggotaMap[$kid]) ? $anggotaMap[$kid] : [],
            ];
        }

        $items[] = [
            'id'                     => (int)$r['id'],
            'judul_karya'            => (string)($r['judul_laporan_produk'] ?: $r['nama_proyek']),
            'deskripsi_karya'        => ilmuna_wp_api_excerpt($r['deskripsi_produk'] ?: $r['target_luaran'], 320),
            'tipe_pengumpulan'       => (string)($r['tipe_pengumpulan'] ?? $r['jenis_proyek']),
            'status_publikasi'       => (string)$r['status_pengumpulan'],
            'nilai_proyek'           => ($r['nilai_proyek'] !== null && $r['nilai_proyek'] !== '') ? (float)$r['nilai_proyek'] : null,
            'predikat_p5'            => $r['predikat_proyek'] ?: null,
            'catatan_apresiasi_guru' => ilmuna_wp_api_excerpt($r['catatan_evaluasi_guru'] ?? '', 200) ?: null,
            'thumbnail_url'          => $thumbUrl,
            'file_dokumentasi_url'   => $dokUrl,
            'file_laporan_url'       => $lapUrl,
            'file_laporan_nama'      => $r['file_nama_asli'] ?: null,
            'link_produk'            => trim((string)($r['link_produk'] ?? '')) ?: null,
            'link_video_presentasi'  => trim((string)($r['link_video_presentasi'] ?? '')) ?: null,
            'waktu_pengumpulan'      => $r['waktu_pengumpulan'],
            'waktu_formatted'        => ilmuna_wp_api_format_id_date($r['waktu_pengumpulan'], true),
            'kreator' => [
                'tipe'            => $kid > 0 ? 'Kelompok' : 'Individu',
                'nama_utama'      => $kid > 0
                    ? (string)($r['nama_kelompok'] ?: ('Kelompok ' . (int)$r['nomor_kelompok']))
                    : ilmuna_wp_api_format_student_name($r['pengumpul_nama'], $hideLast),
                'pengumpul'       => ilmuna_wp_api_format_student_name($r['pengumpul_nama'], $hideLast),
                'tim'             => $tim,
            ],
            'proyek' => [
                'id'                       => (int)$r['id_proyek'],
                'nama_proyek'              => (string)$r['nama_proyek'],
                'tema_proyek'              => (string)($r['tema_proyek'] ?? ''),
                'jenis_proyek'             => (string)$r['jenis_proyek'],
                'dimensi_profil_pancasila' => $dimArr,
                'target_luaran'            => (string)($r['target_luaran'] ?? ''),
            ],
            'kelas' => [
                'id'   => (int)$r['kelas_id'],
                'nama' => (string)$r['nama_kelas'],
            ],
            'mata_pelajaran' => [
                'id'   => (int)$r['mapel_id'],
                'nama' => (string)$r['nama_mata_pelajaran'],
                'kode' => (string)($r['kode_mapel'] ?? ''),
            ],
            'guru_pembimbing' => (string)$r['nama_guru'],
        ];
    }

    return [
        'items' => $items,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total_items' => $total,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
        'policy' => [
            'showcase_min_status' => $minStatus,
        ],
    ];
}

/**
 * 2. BROADCAST TUGAS & DEADLINE TUGAS (tugas)
 */
function ilmuna_wp_api_fetch_tugas_broadcast(PDO $pdo, array $params = [])
{
    $limit = max(1, min(50, (int)($params['limit'] ?? 15)));
    $page  = max(1, (int)($params['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $kelasId      = isset($params['kelas_id']) && ctype_digit((string)$params['kelas_id']) ? (int)$params['kelas_id'] : 0;
    $mapelId      = isset($params['mapel_id']) && ctype_digit((string)$params['mapel_id']) ? (int)$params['mapel_id'] : 0;
    $statusFilter = trim((string)($params['status'] ?? 'Dibuka'));
    $upcomingOnly = isset($params['upcoming_only']) && (string)$params['upcoming_only'] === '1';

    $where = ["t.status_tugas <> 'Draft'"];
    $bind = [];

    if ($statusFilter === 'Dibuka' || $statusFilter === 'Ditutup') {
        $where[] = "t.status_tugas = ?";
        $bind[] = $statusFilter;
    }
    if ($upcomingOnly) {
        $where[] = "(t.tenggat_waktu IS NULL OR t.tenggat_waktu >= NOW())";
    }
    if ($kelasId > 0) {
        $where[] = "k.id = ?";
        $bind[] = $kelasId;
    }
    if ($mapelId > 0) {
        $where[] = "mp.id = ?";
        $bind[] = $mapelId;
    }

    $whereSql = implode(' AND ', $where);

    $cStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tugas t
        JOIN kelas_mata_pelajaran kmp ON t.id_kelas_mata_pelajaran = kmp.id
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        WHERE {$whereSql}
    ");
    $cStmt->execute($bind);
    $total = (int)$cStmt->fetchColumn();

    // Urutkan: deadline aktif terdekat di paling atas, lalu yang baru berakhir
    $sql = "
        SELECT t.id, t.judul_tugas, t.deskripsi_instruksi, t.jenis_penugasan,
               t.tipe_pengumpulan, t.pertemuan_ke, t.tanggal_mulai, t.tenggat_waktu,
               t.status_tugas, t.nilai_maksimal, t.created_at,
               k.id AS kelas_id, k.nama_kelas,
               mp.id AS mapel_id, mp.nama_mata_pelajaran, mp.kode_mapel,
               COALESCE(g2.nama_guru, g.nama_guru, u.full_name, 'Guru Pengampu') AS nama_guru,
               ma.judul_modul
        FROM tugas t
        JOIN kelas_mata_pelajaran kmp ON t.id_kelas_mata_pelajaran = kmp.id
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g2 ON gmp.id_guru = g2.id
        LEFT JOIN guru g ON t.id_guru = g.id_user
        LEFT JOIN users u ON t.id_guru = u.id
        LEFT JOIN modul_ajar ma ON t.id_modul_ajar = ma.id
        WHERE {$whereSql}
        ORDER BY CASE WHEN t.tenggat_waktu >= NOW() THEN 0 ELSE 1 END ASC,
                 CASE WHEN t.tenggat_waktu >= NOW() THEN UNIX_TIMESTAMP(t.tenggat_waktu) ELSE -UNIX_TIMESTAMP(t.tenggat_waktu) END ASC,
                 t.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $portalTugasUrl = ilmuna_wp_api_public_base_url() . '/dashboard/siswa/tugas';
    $items = [];
    foreach ($rows as $r) {
        $dl = ilmuna_wp_api_deadline_meta($r['tenggat_waktu'], false);
        $tsDeadline = !empty($r['tenggat_waktu']) ? strtotime($r['tenggat_waktu']) : false;

        $items[] = [
            'id'                      => (int)$r['id'],
            'judul_tugas'             => (string)$r['judul_tugas'],
            'deskripsi_ringkas'       => ilmuna_wp_api_excerpt($r['deskripsi_instruksi'] ?? '', 220),
            'jenis_penugasan'         => (string)$r['jenis_penugasan'],
            'tipe_pengumpulan'        => (string)($r['tipe_pengumpulan'] ?? ''),
            'status_tugas'            => (string)$r['status_tugas'],
            'tanggal_mulai'           => $r['tanggal_mulai'],
            'tanggal_mulai_formatted' => ilmuna_wp_api_format_id_date($r['tanggal_mulai'], true),
            'tenggat_waktu'           => $r['tenggat_waktu'],
            'tenggat_waktu_iso'       => $tsDeadline ? date('c', $tsDeadline) : null,
            'tenggat_waktu_formatted' => ilmuna_wp_api_format_id_date($r['tenggat_waktu'], true),
            'sisa_waktu_detik'        => $dl['sisa_waktu_detik'],
            'sisa_hari'               => $dl['sisa_hari'],
            'sisa_waktu_label'        => $dl['sisa_waktu_label'],
            'is_expired'              => $dl['is_expired'],
            'urgensi'                 => $dl['urgensi'],
            'modul_ajar' => [
                'pertemuan_ke' => !empty($r['pertemuan_ke']) ? (int)$r['pertemuan_ke'] : null,
                'judul_modul'  => $r['judul_modul'] ?: null,
            ],
            'kelas' => [
                'id'   => (int)$r['kelas_id'],
                'nama' => (string)$r['nama_kelas'],
            ],
            'mata_pelajaran' => [
                'id'   => (int)$r['mapel_id'],
                'nama' => (string)$r['nama_mata_pelajaran'],
                'kode' => (string)($r['kode_mapel'] ?? ''),
            ],
            'guru_pengampu' => (string)$r['nama_guru'],
            'portal_url'    => $portalTugasUrl,
        ];
    }

    return [
        'items' => $items,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total_items' => $total,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
    ];
}

/**
 * 3. BROADCAST PROYEK & DEADLINE PROYEK (proyek)
 */
function ilmuna_wp_api_fetch_proyek_broadcast(PDO $pdo, array $params = [])
{
    $limit = max(1, min(50, (int)($params['limit'] ?? 15)));
    $page  = max(1, (int)($params['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    $kelasId          = isset($params['kelas_id']) && ctype_digit((string)$params['kelas_id']) ? (int)$params['kelas_id'] : 0;
    $mapelId          = isset($params['mapel_id']) && ctype_digit((string)$params['mapel_id']) ? (int)$params['mapel_id'] : 0;
    $includeCompleted = isset($params['include_completed']) && (string)$params['include_completed'] === '1';
    $upcomingOnly     = isset($params['upcoming_only']) && (string)$params['upcoming_only'] === '1';

    $where = ["p.status_proyek <> 'Diarsipkan'"];
    $bind = [];

    if (!$includeCompleted) {
        $where[] = "p.status_proyek IN ('Perencanaan', 'Sedang Berjalan', 'Tahap Pengujian')";
    }
    if ($upcomingOnly) {
        $where[] = "(p.jadwal_selesai IS NULL OR p.jadwal_selesai >= CURDATE())";
    }
    if ($kelasId > 0) {
        $where[] = "k.id = ?";
        $bind[] = $kelasId;
    }
    if ($mapelId > 0) {
        $where[] = "mp.id = ?";
        $bind[] = $mapelId;
    }

    $whereSql = implode(' AND ', $where);

    $cStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM proyek p
        JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        WHERE {$whereSql}
    ");
    $cStmt->execute($bind);
    $total = (int)$cStmt->fetchColumn();

    $sql = "
        SELECT p.id, p.nama_proyek, p.tema_proyek, p.deskripsi_tujuan, p.jenis_proyek,
               p.dimensi_profil_pancasila, p.target_luaran, p.pertemuan_ke,
               p.jadwal_mulai, p.jadwal_selesai, p.status_proyek, p.created_at,
               k.id AS kelas_id, k.nama_kelas,
               mp.id AS mapel_id, mp.nama_mata_pelajaran, mp.kode_mapel,
               COALESCE(g2.nama_guru, g.nama_guru, u.full_name, 'Guru Pengampu') AS nama_guru,
               ma.judul_modul,
               (SELECT COUNT(*) FROM proyek_kelompok pk WHERE pk.id_proyek = p.id) AS jumlah_kelompok,
               (SELECT COUNT(*) FROM pengumpulan_proyek pp WHERE pp.id_proyek = p.id) AS jumlah_karya_terkumpul
        FROM proyek p
        JOIN kelas_mata_pelajaran kmp ON p.id_kelas_mata_pelajaran = kmp.id
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g2 ON gmp.id_guru = g2.id
        LEFT JOIN guru g ON p.id_guru = g.id_user
        LEFT JOIN users u ON p.id_guru = u.id
        LEFT JOIN modul_ajar ma ON p.id_modul_ajar = ma.id
        WHERE {$whereSql}
        ORDER BY CASE WHEN p.jadwal_selesai >= CURDATE() THEN 0 ELSE 1 END ASC,
                 CASE WHEN p.jadwal_selesai >= CURDATE() THEN UNIX_TIMESTAMP(p.jadwal_selesai) ELSE -UNIX_TIMESTAMP(p.jadwal_selesai) END ASC,
                 p.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $portalProyekUrl = ilmuna_wp_api_public_base_url() . '/dashboard/siswa/proyek';
    $items = [];
    foreach ($rows as $r) {
        $dl = ilmuna_wp_api_deadline_meta($r['jadwal_selesai'], true);
        $tsEnd = !empty($r['jadwal_selesai']) ? strtotime($r['jadwal_selesai'] . ' 23:59:59') : false;
        $dimRaw = trim((string)($r['dimensi_profil_pancasila'] ?? ''));
        $dimArr = $dimRaw !== '' ? array_values(array_filter(array_map('trim', explode(',', $dimRaw)))) : [];

        $items[] = [
            'id'                       => (int)$r['id'],
            'nama_proyek'              => (string)$r['nama_proyek'],
            'tema_proyek'              => (string)($r['tema_proyek'] ?? ''),
            'deskripsi_ringkas'        => ilmuna_wp_api_excerpt($r['deskripsi_tujuan'] ?? '', 240),
            'jenis_proyek'             => (string)$r['jenis_proyek'],
            'dimensi_profil_pancasila' => $dimArr,
            'target_luaran'            => (string)($r['target_luaran'] ?? ''),
            'status_proyek'            => (string)$r['status_proyek'],
            'jadwal_mulai'             => $r['jadwal_mulai'],
            'jadwal_mulai_formatted'   => ilmuna_wp_api_format_id_date($r['jadwal_mulai'], false),
            'jadwal_selesai'           => $r['jadwal_selesai'],
            'jadwal_selesai_iso'       => $tsEnd ? date('c', $tsEnd) : null,
            'jadwal_selesai_formatted' => ilmuna_wp_api_format_id_date($r['jadwal_selesai'], false),
            'sisa_waktu_detik'         => $dl['sisa_waktu_detik'],
            'sisa_hari'                => $dl['sisa_hari'],
            'sisa_waktu_label'         => $dl['sisa_waktu_label'],
            'is_expired'               => $dl['is_expired'],
            'urgensi'                  => $dl['urgensi'],
            'jumlah_kelompok'          => (int)$r['jumlah_kelompok'],
            'jumlah_karya_terkumpul'   => (int)$r['jumlah_karya_terkumpul'],
            'modul_ajar' => [
                'pertemuan_ke' => !empty($r['pertemuan_ke']) ? (int)$r['pertemuan_ke'] : null,
                'judul_modul'  => $r['judul_modul'] ?: null,
            ],
            'kelas' => [
                'id'   => (int)$r['kelas_id'],
                'nama' => (string)$r['nama_kelas'],
            ],
            'mata_pelajaran' => [
                'id'   => (int)$r['mapel_id'],
                'nama' => (string)$r['nama_mata_pelajaran'],
                'kode' => (string)($r['kode_mapel'] ?? ''),
            ],
            'guru_pembimbing' => (string)$r['nama_guru'],
            'portal_url'      => $portalProyekUrl,
        ];
    }

    return [
        'items' => $items,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total_items' => $total,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
    ];
}
