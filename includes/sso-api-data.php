<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MARGO ONE SSO CLASS-AWARE DATA BUILDER (DTOs)
 * ==============================================================================
 * Menyusun data terstruktur sesuai rombel kelas (`kelas`) & identitas pengguna SSO:
 *   1. tugas_broadcast    : Deadline & Broadcast Tugas + Kelompok Tugas per Kelas
 *   2. proyek_broadcast   : Deadline & Broadcast Proyek (P5/PjBL) + Kelompok Proyek per Kelas
 *   3. kelompok_broadcast : Pembagian Kelompok Tugas & Proyek lengkap per Kelas / Siswa
 *   4. jadwal_asesmen     : Jadwal Asesmen dari Bank & Master Asesmen
 *                           (Sumatif + Multi-Kelas, Formatif, Diagnostik, Pengayaan/Remedial)
 *   5. unified_feed       : Gabungan terpadu + Timeline Deadline Terdekat + Riwayat Notifikasi
 *
 * 100% kompatibel PHP 7.4 (ea-php74) hingga PHP 8.3+.
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/includes/wp-api-data.php';

/**
 * Resolusi konteks Kelas & Pengguna (Siswa / Guru) secara otomatis berdasarkan
 * parameter query / klaim SSO (kelas_id, kode_kelas, nama_kelas, nis, nisn, nip, email, username).
 */
function ilmuna_sso_resolve_identity_context(PDO $pdo, array $params = [])
{
    $ctx = [
        'filtered_by_class' => false,
        'kelas_ids'         => [],
        'kelas_list'        => [],
        'role'              => 'all',
        'siswa'             => null,
        'guru'              => null,
        'siswa_id'          => null,
        'guru_id'           => null,
    ];

    // 1. Cek filter kelas eksplisit (kelas_id, kode_kelas, nama_kelas)
    $rawKelasIds = [];
    $paramKelas = $params['kelas_id'] ?? ($params['id_kelas'] ?? '');
    if (is_array($paramKelas)) {
        $paramKelas = implode(',', $paramKelas);
    }
    if (trim((string)$paramKelas) !== '') {
        foreach (explode(',', (string)$paramKelas) as $part) {
            $kid = (int)trim($part);
            if ($kid > 0) {
                $rawKelasIds[] = $kid;
            }
        }
    }

    $kodeKelas = trim((string)($params['kode_kelas'] ?? ''));
    $namaKelas = trim((string)($params['nama_kelas'] ?? ($params['kelas'] ?? '')));

    if ($kodeKelas !== '') {
        $st = $pdo->prepare("SELECT id FROM kelas WHERE LOWER(kode_kelas) = LOWER(?)");
        $st->execute([$kodeKelas]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $idCol) {
            $rawKelasIds[] = (int)$idCol;
        }
        $ctx['filtered_by_class'] = true;
    }

    if ($namaKelas !== '') {
        $st = $pdo->prepare("SELECT id FROM kelas WHERE LOWER(nama_kelas) = LOWER(?)");
        $st->execute([$namaKelas]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $idCol) {
            $rawKelasIds[] = (int)$idCol;
        }
        $ctx['filtered_by_class'] = true;
    }

    if (!empty($rawKelasIds)) {
        $ctx['filtered_by_class'] = true;
    }

    // 2. Cek identitas pengguna SSO (nis, nisn, nip, nisn_nip, email, username)
    $nis     = trim((string)($params['nis'] ?? ''));
    $nisn    = trim((string)($params['nisn'] ?? ''));
    $nip     = trim((string)($params['nip'] ?? ''));
    $nisnNip = trim((string)($params['nisn_nip'] ?? ''));
    $email   = strtolower(trim((string)($params['email'] ?? '')));
    $uname   = trim((string)($params['username'] ?? ''));

    // 2a. Cari kecocokan Siswa
    $siswaRow = null;
    if ($nis !== '' || $nisn !== '' || $nisnNip !== '' || $email !== '' || $uname !== '') {
        $conds = [];
        $binds = [];
        if ($nis !== '') {
            $conds[] = 's.nis = ?';
            $binds[] = $nis;
        }
        if ($nisn !== '') {
            $conds[] = 's.nisn = ?';
            $binds[] = $nisn;
        }
        if ($nisnNip !== '') {
            $conds[] = '(s.nisn = ? OR s.nis = ?)';
            $binds[] = $nisnNip;
            $binds[] = $nisnNip;
        }
        if ($uname !== '') {
            $conds[] = '(s.nis = ? OR s.nisn = ?)';
            $binds[] = $uname;
            $binds[] = $uname;
        }
        if ($email !== '') {
            $conds[] = 'LOWER(s.gmail) = ?';
            $binds[] = $email;
        }

        if (!empty($conds)) {
            $stS = $pdo->prepare("
                SELECT s.id, s.id_kelas, s.nis, s.nisn, s.nama_lengkap,
                       k.nama_kelas, k.kode_kelas, k.tahun_pelajaran
                FROM siswa s
                LEFT JOIN kelas k ON s.id_kelas = k.id
                WHERE " . implode(' OR ', $conds) . "
                LIMIT 1
            ");
            $stS->execute($binds);
            $siswaRow = $stS->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Fallback via users -> siswa
        if (!$siswaRow && ($email !== '' || $uname !== '')) {
            $uConds = [];
            $uBinds = [];
            if ($email !== '') {
                $uConds[] = '(LOWER(u.email) = ? OR LOWER(u.email_google) = ?)';
                $uBinds[] = $email;
                $uBinds[] = $email;
            }
            if ($uname !== '') {
                $uConds[] = 'u.username = ?';
                $uBinds[] = $uname;
            }
            $stU = $pdo->prepare("SELECT id, username, nisn_nip FROM users WHERE " . implode(' OR ', $uConds) . " LIMIT 1");
            $stU->execute($uBinds);
            $uRow = $stU->fetch(PDO::FETCH_ASSOC);
            if ($uRow && (!empty($uRow['nisn_nip']) || !empty($uRow['username']))) {
                $stS2 = $pdo->prepare("
                    SELECT s.id, s.id_kelas, s.nis, s.nisn, s.nama_lengkap,
                           k.nama_kelas, k.kode_kelas, k.tahun_pelajaran
                    FROM siswa s
                    LEFT JOIN kelas k ON s.id_kelas = k.id
                    WHERE s.nis = ? OR s.nisn = ? OR s.nis = ?
                    LIMIT 1
                ");
                $stS2->execute([(string)$uRow['username'], (string)$uRow['nisn_nip'], (string)$uRow['nisn_nip']]);
                $siswaRow = $stS2->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        }
    }

    if ($siswaRow) {
        $ctx['role'] = 'siswa';
        $ctx['siswa_id'] = (int)$siswaRow['id'];
        $ctx['siswa'] = [
            'id'           => (int)$siswaRow['id'],
            'nis'          => (string)($siswaRow['nis'] ?? ''),
            'nisn'         => (string)($siswaRow['nisn'] ?? ''),
            'nama_lengkap' => (string)$siswaRow['nama_lengkap'],
            'id_kelas'     => !empty($siswaRow['id_kelas']) ? (int)$siswaRow['id_kelas'] : null,
            'nama_kelas'   => (string)($siswaRow['nama_kelas'] ?? ''),
            'kode_kelas'   => (string)($siswaRow['kode_kelas'] ?? ''),
        ];
        if (empty($rawKelasIds) && !empty($siswaRow['id_kelas'])) {
            $rawKelasIds[] = (int)$siswaRow['id_kelas'];
            $ctx['filtered_by_class'] = true;
        }
    }

    // 2b. Jika bukan siswa, cek kecocokan Guru
    if (!$siswaRow && ($nip !== '' || $nisnNip !== '' || $email !== '' || $uname !== '')) {
        $gConds = [];
        $gBinds = [];
        if ($nip !== '') {
            $gConds[] = 'g.nip = ?';
            $gBinds[] = $nip;
        }
        if ($nisnNip !== '') {
            $gConds[] = '(g.nip = ? OR g.nuptk = ?)';
            $gBinds[] = $nisnNip;
            $gBinds[] = $nisnNip;
        }
        if ($uname !== '') {
            $gConds[] = 'g.nip = ?';
            $gBinds[] = $uname;
        }
        if ($email !== '') {
            $gConds[] = 'LOWER(g.email) = ?';
            $gBinds[] = $email;
        }

        $guruRow = null;
        if (!empty($gConds)) {
            $stG = $pdo->prepare("SELECT id, id_user, nip, kode_guru, nama_guru FROM guru WHERE " . implode(' OR ', $gConds) . " LIMIT 1");
            $stG->execute($gBinds);
            $guruRow = $stG->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$guruRow && ($email !== '' || $uname !== '')) {
            $uConds = [];
            $uBinds = [];
            if ($email !== '') {
                $uConds[] = '(LOWER(u.email) = ? OR LOWER(u.email_google) = ?)';
                $uBinds[] = $email;
                $uBinds[] = $email;
            }
            if ($uname !== '') {
                $uConds[] = 'u.username = ?';
                $uBinds[] = $uname;
            }
            $stUg = $pdo->prepare("
                SELECT g.id, g.id_user, g.nip, g.kode_guru, g.nama_guru
                FROM guru g
                JOIN users u ON g.id_user = u.id
                WHERE " . implode(' OR ', $uConds) . "
                LIMIT 1
            ");
            $stUg->execute($uBinds);
            $guruRow = $stUg->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($guruRow) {
            $ctx['role'] = 'guru';
            $ctx['guru_id'] = (int)$guruRow['id'];
            $ctx['guru'] = [
                'id'        => (int)$guruRow['id'],
                'nip'       => (string)($guruRow['nip'] ?? ''),
                'kode_guru' => (string)($guruRow['kode_guru'] ?? ''),
                'nama_guru' => (string)$guruRow['nama_guru'],
            ];

            if (empty($rawKelasIds)) {
                $stGk = $pdo->prepare("
                    SELECT DISTINCT kmp.id_kelas
                    FROM kelas_mata_pelajaran kmp
                    JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                    WHERE gmp.id_guru = ?
                ");
                $stGk->execute([(int)$guruRow['id']]);
                foreach ($stGk->fetchAll(PDO::FETCH_COLUMN) as $gkId) {
                    if ((int)$gkId > 0) {
                        $rawKelasIds[] = (int)$gkId;
                    }
                }
                if (!empty($rawKelasIds)) {
                    $ctx['filtered_by_class'] = true;
                }
            }
        }
    }

    $rawKelasIds = array_values(array_unique(array_filter(array_map('intval', $rawKelasIds))));
    $ctx['kelas_ids'] = $rawKelasIds;

    if (!empty($rawKelasIds)) {
        $in = implode(',', array_fill(0, count($rawKelasIds), '?'));
        $stKl = $pdo->prepare("SELECT id, nama_kelas, kode_kelas, tahun_pelajaran FROM kelas WHERE id IN ({$in}) ORDER BY nama_kelas ASC");
        $stKl->execute($rawKelasIds);
        foreach ($stKl->fetchAll(PDO::FETCH_ASSOC) as $kr) {
            $ctx['kelas_list'][] = [
                'id'              => (int)$kr['id'],
                'nama_kelas'      => (string)$kr['nama_kelas'],
                'kode_kelas'      => (string)($kr['kode_kelas'] ?? ''),
                'tahun_pelajaran' => (string)($kr['tahun_pelajaran'] ?? ''),
            ];
        }
    }

    return $ctx;
}

/**
 * Daftar master seluruh Rombel Kelas yang tersedia di Ilmuna Learn (untuk sinkronisasi dropdown/mapping di SSO)
 */
function ilmuna_sso_fetch_master_classes(PDO $pdo)
{
    $rows = $pdo->query("
        SELECT k.id, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran,
               (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS jumlah_siswa
        FROM kelas k
        ORDER BY k.nama_kelas ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'              => (int)$r['id'],
            'nama_kelas'      => (string)$r['nama_kelas'],
            'kode_kelas'      => (string)($r['kode_kelas'] ?? ''),
            'tahun_pelajaran' => (string)($r['tahun_pelajaran'] ?? ''),
            'jumlah_siswa'    => (int)$r['jumlah_siswa'],
        ];
    }
    return $items;
}

/**
 * 1. BROADCAST TUGAS & DEADLINE + KELOMPOK TUGAS SESUAI KELAS
 */
function ilmuna_sso_fetch_tugas_broadcast(PDO $pdo, array $params = [], array $ctx = [])
{
    $limit  = max(1, min(100, (int)($params['limit'] ?? 25)));
    $page   = max(1, (int)($params['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    if (!empty($ctx['filtered_by_class']) && empty($ctx['kelas_ids'])) {
        return [
            'items'      => [],
            'pagination' => ['page' => $page, 'limit' => $limit, 'total_items' => 0, 'total_pages' => 0],
        ];
    }

    $mapelId      = isset($params['mapel_id']) && ctype_digit((string)$params['mapel_id']) ? (int)$params['mapel_id'] : 0;
    $statusFilter = trim((string)($params['status'] ?? 'Dibuka'));
    $upcomingOnly = isset($params['upcoming_only']) && (string)$params['upcoming_only'] === '1';

    $where = ["t.status_tugas <> 'Draft'"];
    $bind  = [];

    if ($statusFilter === 'Dibuka' || $statusFilter === 'Ditutup') {
        $where[] = 't.status_tugas = ?';
        $bind[]  = $statusFilter;
    }
    if ($upcomingOnly) {
        $where[] = '(t.tenggat_waktu IS NULL OR t.tenggat_waktu >= NOW())';
    }
    if (!empty($ctx['kelas_ids'])) {
        $in = implode(',', array_fill(0, count($ctx['kelas_ids']), '?'));
        $where[] = "k.id IN ({$in})";
        $bind = array_merge($bind, $ctx['kelas_ids']);
    }
    if ($mapelId > 0) {
        $where[] = 'mp.id = ?';
        $bind[]  = $mapelId;
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

    $sql = "
        SELECT t.id, t.judul_tugas, t.deskripsi_instruksi, t.jenis_penugasan,
               t.tipe_pengumpulan, t.format_file_diizinkan, t.pertemuan_ke,
               t.tanggal_mulai, t.tenggat_waktu, t.status_tugas, t.nilai_maksimal,
               t.bobot_nilai, t.link_sumber_tugas, t.created_at, t.updated_at,
               k.id AS kelas_id, k.nama_kelas, k.kode_kelas,
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

    // Batch-load seluruh kelompok tugas & anggota untuk tugas-tugas di halaman ini (anti N+1)
    $tugasIds = [];
    foreach ($rows as $r) {
        $tugasIds[] = (int)$r['id'];
    }

    $teamsByTugas = [];
    $siswaSubmissionMap = [];
    $siswaIdCtx = !empty($ctx['siswa_id']) ? (int)$ctx['siswa_id'] : 0;

    if (!empty($tugasIds)) {
        $inT = implode(',', array_fill(0, count($tugasIds), '?'));
        $tkStmt = $pdo->prepare("
            SELECT tk.id, tk.id_tugas, tk.nomor_kelompok, tk.nama_kelompok, tk.id_ketua_kelompok,
                   sk.nama_lengkap AS nama_ketua, sk.nis AS nis_ketua
            FROM tugas_kelompok tk
            LEFT JOIN siswa sk ON tk.id_ketua_kelompok = sk.id
            WHERE tk.id_tugas IN ({$inT})
            ORDER BY tk.nomor_kelompok ASC, tk.id ASC
        ");
        $tkStmt->execute($tugasIds);
        $tkRows = $tkStmt->fetchAll(PDO::FETCH_ASSOC);

        $kelIds = [];
        foreach ($tkRows as $tk) {
            $kelIds[] = (int)$tk['id'];
        }

        $membersByKel = [];
        if (!empty($kelIds)) {
            $inK = implode(',', array_fill(0, count($kelIds), '?'));
            $mStmt = $pdo->prepare("
                SELECT tka.id_tugas_kelompok, s.id AS id_siswa, s.nis, s.nama_lengkap
                FROM tugas_kelompok_anggota tka
                JOIN siswa s ON tka.id_siswa = s.id
                WHERE tka.id_tugas_kelompok IN ({$inK})
                ORDER BY s.nama_lengkap ASC
            ");
            $mStmt->execute($kelIds);
            foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $mr) {
                $kid = (int)$mr['id_tugas_kelompok'];
                $membersByKel[$kid][] = [
                    'id_siswa' => (int)$mr['id_siswa'],
                    'nis'      => (string)($mr['nis'] ?? ''),
                    'nama'     => (string)$mr['nama_lengkap'],
                ];
            }
        }

        foreach ($tkRows as $tk) {
            $kid = (int)$tk['id'];
            $tid = (int)$tk['id_tugas'];
            $ketuaId = !empty($tk['id_ketua_kelompok']) ? (int)$tk['id_ketua_kelompok'] : null;
            $members = $membersByKel[$kid] ?? [];
            foreach ($members as &$mem) {
                $mem['peran'] = ($ketuaId !== null && $mem['id_siswa'] === $ketuaId) ? 'Ketua Kelompok' : 'Anggota';
            }
            unset($mem);

            $teamsByTugas[$tid][] = [
                'id_kelompok'    => $kid,
                'nomor_kelompok' => (int)$tk['nomor_kelompok'],
                'nama_kelompok'  => (string)($tk['nama_kelompok'] ?: ('Kelompok ' . (int)$tk['nomor_kelompok'])),
                'ketua'          => $ketuaId ? [
                    'id_siswa' => $ketuaId,
                    'nis'      => (string)($tk['nis_ketua'] ?? ''),
                    'nama'     => (string)($tk['nama_ketua'] ?? ''),
                ] : null,
                'jumlah_anggota' => count($members),
                'anggota'        => $members,
            ];
        }

        if ($siswaIdCtx > 0) {
            $subStmt = $pdo->prepare("
                SELECT pt.id_tugas, pt.status_pengumpulan, pt.waktu_pengumpulan
                FROM pengumpulan_tugas pt
                WHERE pt.id_tugas IN ({$inT})
                  AND (pt.id_siswa = ? OR pt.id_tugas_kelompok IN (
                      SELECT id_tugas_kelompok FROM tugas_kelompok_anggota WHERE id_siswa = ?
                  ))
            ");
            $subStmt->execute(array_merge($tugasIds, [$siswaIdCtx, $siswaIdCtx]));
            foreach ($subStmt->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                $siswaSubmissionMap[(int)$sr['id_tugas']] = [
                    'sudah_mengumpulkan' => true,
                    'status_pengumpulan' => (string)($sr['status_pengumpulan'] ?? 'Terkumpul'),
                    'waktu_pengumpulan'  => $sr['waktu_pengumpulan'],
                ];
            }
        }
    }

    $portalTugasUrl = ilmuna_wp_api_public_base_url() . '/dashboard/siswa/tugas';
    $items = [];

    foreach ($rows as $r) {
        $tid = (int)$r['id'];
        $dl  = ilmuna_wp_api_deadline_meta($r['tenggat_waktu'], false);
        $tsDeadline = !empty($r['tenggat_waktu']) ? strtotime($r['tenggat_waktu']) : false;
        $kelompokList = $teamsByTugas[$tid] ?? [];

        $kelompokSaya = null;
        if ($siswaIdCtx > 0 && !empty($kelompokList)) {
            foreach ($kelompokList as $kl) {
                foreach ($kl['anggota'] as $ag) {
                    if ((int)$ag['id_siswa'] === $siswaIdCtx) {
                        $kelompokSaya = [
                            'id_kelompok'    => $kl['id_kelompok'],
                            'nomor_kelompok' => $kl['nomor_kelompok'],
                            'nama_kelompok'  => $kl['nama_kelompok'],
                            'peran_saya'     => $ag['peran'],
                            'ketua'          => $kl['ketua'],
                            'anggota'        => $kl['anggota'],
                        ];
                        break 2;
                    }
                }
            }
        }

        $item = [
            'id'                      => $tid,
            'kategori_item'           => 'tugas',
            'judul_tugas'             => (string)$r['judul_tugas'],
            'deskripsi_ringkas'       => ilmuna_wp_api_excerpt($r['deskripsi_instruksi'] ?? '', 260),
            'jenis_penugasan'         => (string)$r['jenis_penugasan'],
            'tipe_pengumpulan'        => (string)($r['tipe_pengumpulan'] ?? ''),
            'format_file_diizinkan'   => (string)($r['format_file_diizinkan'] ?? ''),
            'status_tugas'            => (string)$r['status_tugas'],
            'nilai_maksimal'          => (float)($r['nilai_maksimal'] ?? 100),
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
            'jumlah_kelompok'         => count($kelompokList),
            'daftar_kelompok'         => $kelompokList,
            'modul_ajar'              => [
                'pertemuan_ke' => !empty($r['pertemuan_ke']) ? (int)$r['pertemuan_ke'] : null,
                'judul_modul'  => $r['judul_modul'] ?: null,
            ],
            'kelas'                   => [
                'id'   => (int)$r['kelas_id'],
                'nama' => (string)$r['nama_kelas'],
                'kode' => (string)($r['kode_kelas'] ?? ''),
            ],
            'mata_pelajaran'          => [
                'id'   => (int)$r['mapel_id'],
                'nama' => (string)$r['nama_mata_pelajaran'],
                'kode' => (string)($r['kode_mapel'] ?? ''),
            ],
            'guru_pengampu'           => (string)$r['nama_guru'],
            'link_referensi'          => trim((string)($r['link_sumber_tugas'] ?? '')) ?: null,
            'portal_url'              => $portalTugasUrl,
        ];

        if ($siswaIdCtx > 0) {
            $item['kelompok_saya'] = $kelompokSaya;
            $item['status_pengumpulan_saya'] = $siswaSubmissionMap[$tid] ?? [
                'sudah_mengumpulkan' => false,
                'status_pengumpulan' => 'Belum Mengumpulkan',
                'waktu_pengumpulan'  => null,
            ];
        }

        $items[] = $item;
    }

    return [
        'items'      => $items,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total_items' => $total,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
    ];
}

/**
 * 2. BROADCAST PROYEK (P5 / PjBL) & DEADLINE + KELOMPOK PROYEK SESUAI KELAS
 */
function ilmuna_sso_fetch_proyek_broadcast(PDO $pdo, array $params = [], array $ctx = [])
{
    $limit  = max(1, min(100, (int)($params['limit'] ?? 25)));
    $page   = max(1, (int)($params['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    if (!empty($ctx['filtered_by_class']) && empty($ctx['kelas_ids'])) {
        return [
            'items'      => [],
            'pagination' => ['page' => $page, 'limit' => $limit, 'total_items' => 0, 'total_pages' => 0],
        ];
    }

    $mapelId          = isset($params['mapel_id']) && ctype_digit((string)$params['mapel_id']) ? (int)$params['mapel_id'] : 0;
    $includeCompleted = isset($params['include_completed']) && (string)$params['include_completed'] === '1';
    $upcomingOnly     = isset($params['upcoming_only']) && (string)$params['upcoming_only'] === '1';

    $where = ["p.status_proyek <> 'Diarsipkan'"];
    $bind  = [];

    if (!$includeCompleted) {
        $where[] = "p.status_proyek IN ('Perencanaan', 'Sedang Berjalan', 'Tahap Pengujian')";
    }
    if ($upcomingOnly) {
        $where[] = '(p.jadwal_selesai IS NULL OR p.jadwal_selesai >= CURDATE())';
    }
    if (!empty($ctx['kelas_ids'])) {
        $in = implode(',', array_fill(0, count($ctx['kelas_ids']), '?'));
        $where[] = "k.id IN ({$in})";
        $bind = array_merge($bind, $ctx['kelas_ids']);
    }
    if ($mapelId > 0) {
        $where[] = 'mp.id = ?';
        $bind[]  = $mapelId;
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
               p.jadwal_mulai, p.jadwal_selesai, p.status_proyek, p.nilai_maksimal,
               p.link_referensi, p.created_at, p.updated_at,
               k.id AS kelas_id, k.nama_kelas, k.kode_kelas,
               mp.id AS mapel_id, mp.nama_mata_pelajaran, mp.kode_mapel,
               COALESCE(g2.nama_guru, g.nama_guru, u.full_name, 'Guru Pembimbing') AS nama_guru,
               ma.judul_modul,
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

    $proyekIds = [];
    foreach ($rows as $r) {
        $proyekIds[] = (int)$r['id'];
    }

    $teamsByProyek = [];
    $siswaIdCtx = !empty($ctx['siswa_id']) ? (int)$ctx['siswa_id'] : 0;

    if (!empty($proyekIds)) {
        $inP = implode(',', array_fill(0, count($proyekIds), '?'));
        $pkStmt = $pdo->prepare("
            SELECT pk.id, pk.id_proyek, pk.nomor_kelompok, pk.nama_kelompok, pk.id_ketua_kelompok,
                   sk.nama_lengkap AS nama_ketua, sk.nis AS nis_ketua
            FROM proyek_kelompok pk
            LEFT JOIN siswa sk ON pk.id_ketua_kelompok = sk.id
            WHERE pk.id_proyek IN ({$inP})
            ORDER BY pk.nomor_kelompok ASC, pk.id ASC
        ");
        $pkStmt->execute($proyekIds);
        $pkRows = $pkStmt->fetchAll(PDO::FETCH_ASSOC);

        $kelIds = [];
        foreach ($pkRows as $pk) {
            $kelIds[] = (int)$pk['id'];
        }

        $membersByKel = [];
        if (!empty($kelIds)) {
            $inK = implode(',', array_fill(0, count($kelIds), '?'));
            $mStmt = $pdo->prepare("
                SELECT pka.id_proyek_kelompok, pka.peran_anggota, s.id AS id_siswa, s.nis, s.nama_lengkap
                FROM proyek_kelompok_anggota pka
                JOIN siswa s ON pka.id_siswa = s.id
                WHERE pka.id_proyek_kelompok IN ({$inK})
                ORDER BY FIELD(pka.peran_anggota, 'Ketua Tim', 'Ketua'), s.nama_lengkap ASC
            ");
            $mStmt->execute($kelIds);
            foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $mr) {
                $kid = (int)$mr['id_proyek_kelompok'];
                $membersByKel[$kid][] = [
                    'id_siswa' => (int)$mr['id_siswa'],
                    'nis'      => (string)($mr['nis'] ?? ''),
                    'nama'     => (string)$mr['nama_lengkap'],
                    'peran'    => (string)($mr['peran_anggota'] ?: 'Anggota'),
                ];
            }
        }

        foreach ($pkRows as $pk) {
            $kid = (int)$pk['id'];
            $pid = (int)$pk['id_proyek'];
            $ketuaId = !empty($pk['id_ketua_kelompok']) ? (int)$pk['id_ketua_kelompok'] : null;
            $members = $membersByKel[$kid] ?? [];

            $teamsByProyek[$pid][] = [
                'id_kelompok'    => $kid,
                'nomor_kelompok' => (int)$pk['nomor_kelompok'],
                'nama_kelompok'  => (string)($pk['nama_kelompok'] ?: ('Kelompok ' . (int)$pk['nomor_kelompok'])),
                'ketua'          => $ketuaId ? [
                    'id_siswa' => $ketuaId,
                    'nis'      => (string)($pk['nis_ketua'] ?? ''),
                    'nama'     => (string)($pk['nama_ketua'] ?? ''),
                ] : null,
                'jumlah_anggota' => count($members),
                'anggota'        => $members,
            ];
        }
    }

    $portalProyekUrl = ilmuna_wp_api_public_base_url() . '/dashboard/siswa/proyek';
    $items = [];

    foreach ($rows as $r) {
        $pid = (int)$r['id'];
        $dl  = ilmuna_wp_api_deadline_meta($r['jadwal_selesai'], true);
        $tsEnd = !empty($r['jadwal_selesai']) ? strtotime($r['jadwal_selesai'] . ' 23:59:59') : false;
        $dimRaw = trim((string)($r['dimensi_profil_pancasila'] ?? ''));
        $dimArr = $dimRaw !== '' ? array_values(array_filter(array_map('trim', explode(',', $dimRaw)))) : [];
        $kelompokList = $teamsByProyek[$pid] ?? [];

        $kelompokSaya = null;
        if ($siswaIdCtx > 0 && !empty($kelompokList)) {
            foreach ($kelompokList as $kl) {
                foreach ($kl['anggota'] as $ag) {
                    if ((int)$ag['id_siswa'] === $siswaIdCtx) {
                        $kelompokSaya = [
                            'id_kelompok'    => $kl['id_kelompok'],
                            'nomor_kelompok' => $kl['nomor_kelompok'],
                            'nama_kelompok'  => $kl['nama_kelompok'],
                            'peran_saya'     => $ag['peran'],
                            'ketua'          => $kl['ketua'],
                            'anggota'        => $kl['anggota'],
                        ];
                        break 2;
                    }
                }
            }
        }

        $item = [
            'id'                       => $pid,
            'kategori_item'            => 'proyek',
            'nama_proyek'              => (string)$r['nama_proyek'],
            'tema_proyek'              => (string)($r['tema_proyek'] ?? ''),
            'deskripsi_ringkas'        => ilmuna_wp_api_excerpt($r['deskripsi_tujuan'] ?? '', 260),
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
            'jumlah_kelompok'          => count($kelompokList),
            'jumlah_karya_terkumpul'   => (int)$r['jumlah_karya_terkumpul'],
            'daftar_kelompok'          => $kelompokList,
            'modul_ajar'               => [
                'pertemuan_ke' => !empty($r['pertemuan_ke']) ? (int)$r['pertemuan_ke'] : null,
                'judul_modul'  => $r['judul_modul'] ?: null,
            ],
            'kelas'                    => [
                'id'   => (int)$r['kelas_id'],
                'nama' => (string)$r['nama_kelas'],
                'kode' => (string)($r['kode_kelas'] ?? ''),
            ],
            'mata_pelajaran'           => [
                'id'   => (int)$r['mapel_id'],
                'nama' => (string)$r['nama_mata_pelajaran'],
                'kode' => (string)($r['kode_mapel'] ?? ''),
            ],
            'guru_pembimbing'          => (string)$r['nama_guru'],
            'link_referensi'           => trim((string)($r['link_referensi'] ?? '')) ?: null,
            'portal_url'               => $portalProyekUrl,
        ];

        if ($siswaIdCtx > 0) {
            $item['kelompok_saya'] = $kelompokSaya;
        }

        $items[] = $item;
    }

    return [
        'items'      => $items,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total_items' => $total,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
    ];
}

/**
 * 3. BROADCAST PEMBAGIAN KELOMPOK TERPADU (TUGAS & PROYEK) SESUAI KELAS / SISWA
 */
function ilmuna_sso_fetch_kelompok_broadcast(PDO $pdo, array $params = [], array $ctx = [])
{
    $onlyMine   = isset($params['only_mine']) && (string)$params['only_mine'] === '1';
    $siswaIdCtx = !empty($ctx['siswa_id']) ? (int)$ctx['siswa_id'] : 0;

    $tugasRes  = ilmuna_sso_fetch_tugas_broadcast($pdo, array_merge($params, ['limit' => 50, 'page' => 1]), $ctx);
    $proyekRes = ilmuna_sso_fetch_proyek_broadcast($pdo, array_merge($params, ['limit' => 50, 'page' => 1]), $ctx);

    $groups = [];

    foreach ($tugasRes['items'] as $t) {
        if (empty($t['daftar_kelompok'])) {
            continue;
        }
        foreach ($t['daftar_kelompok'] as $kl) {
            $isMember = false;
            $myRole   = null;
            if ($siswaIdCtx > 0) {
                foreach ($kl['anggota'] as $ag) {
                    if ((int)$ag['id_siswa'] === $siswaIdCtx) {
                        $isMember = true;
                        $myRole   = $ag['peran'];
                        break;
                    }
                }
                if ($onlyMine && !$isMember) {
                    continue;
                }
            }

            $groups[] = [
                'sumber'            => 'tugas',
                'id_kelompok'       => $kl['id_kelompok'],
                'nomor_kelompok'    => $kl['nomor_kelompok'],
                'nama_kelompok'     => $kl['nama_kelompok'],
                'ketua'             => $kl['ketua'],
                'jumlah_anggota'    => $kl['jumlah_anggota'],
                'anggota'           => $kl['anggota'],
                'is_anggota_saya'   => $isMember,
                'peran_saya'        => $myRole,
                'induk'             => [
                    'id'                => $t['id'],
                    'judul'             => $t['judul_tugas'],
                    'tenggat_waktu'     => $t['tenggat_waktu'],
                    'tenggat_formatted' => $t['tenggat_waktu_formatted'],
                    'sisa_waktu_label'  => $t['sisa_waktu_label'],
                    'urgensi'           => $t['urgensi'],
                ],
                'kelas'             => $t['kelas'],
                'mata_pelajaran'    => $t['mata_pelajaran'],
                'guru_pengampu'     => $t['guru_pengampu'],
                'portal_url'        => $t['portal_url'],
            ];
        }
    }

    foreach ($proyekRes['items'] as $p) {
        if (empty($p['daftar_kelompok'])) {
            continue;
        }
        foreach ($p['daftar_kelompok'] as $kl) {
            $isMember = false;
            $myRole   = null;
            if ($siswaIdCtx > 0) {
                foreach ($kl['anggota'] as $ag) {
                    if ((int)$ag['id_siswa'] === $siswaIdCtx) {
                        $isMember = true;
                        $myRole   = $ag['peran'];
                        break;
                    }
                }
                if ($onlyMine && !$isMember) {
                    continue;
                }
            }

            $groups[] = [
                'sumber'            => 'proyek',
                'id_kelompok'       => $kl['id_kelompok'],
                'nomor_kelompok'    => $kl['nomor_kelompok'],
                'nama_kelompok'     => $kl['nama_kelompok'],
                'ketua'             => $kl['ketua'],
                'jumlah_anggota'    => $kl['jumlah_anggota'],
                'anggota'           => $kl['anggota'],
                'is_anggota_saya'   => $isMember,
                'peran_saya'        => $myRole,
                'induk'             => [
                    'id'                => $p['id'],
                    'judul'             => $p['nama_proyek'],
                    'tema'              => $p['tema_proyek'],
                    'tenggat_waktu'     => $p['jadwal_selesai'],
                    'tenggat_formatted' => $p['jadwal_selesai_formatted'],
                    'sisa_waktu_label'  => $p['sisa_waktu_label'],
                    'urgensi'           => $p['urgensi'],
                ],
                'kelas'             => $p['kelas'],
                'mata_pelajaran'    => $p['mata_pelajaran'],
                'guru_pengampu'     => $p['guru_pembimbing'],
                'portal_url'        => $p['portal_url'],
            ];
        }
    }

    return [
        'items'       => $groups,
        'total_items' => count($groups),
    ];
}

/**
 * Hitung status waktu pelaksanaan ujian / asesmen secara presisi
 */
function ilmuna_sso_asesmen_schedule_meta($tanggal, $jamMulai, $jamSelesai)
{
    $tgl = trim((string)$tanggal);
    if ($tgl === '') {
        return [
            'mulai_iso'         => null,
            'selesai_iso'       => null,
            'jadwal_formatted'  => '-',
            'status_pelaksanaan'=> 'belum_terjadwal',
            'sisa_detik_mulai'  => null,
            'sisa_waktu_label'  => '-',
            'urgensi'           => 'normal',
        ];
    }

    $jm = trim((string)$jamMulai) ?: '07:30:00';
    if (strlen($jm) === 5) {
        $jm .= ':00';
    }
    $js = trim((string)$jamSelesai) ?: '09:00:00';
    if (strlen($js) === 5) {
        $js .= ':00';
    }

    $startTs = strtotime($tgl . ' ' . $jm);
    $endTs   = strtotime($tgl . ' ' . $js);
    if ($startTs === false) {
        return [
            'mulai_iso'         => null,
            'selesai_iso'       => null,
            'jadwal_formatted'  => $tgl,
            'status_pelaksanaan'=> 'belum_terjadwal',
            'sisa_detik_mulai'  => null,
            'sisa_waktu_label'  => '-',
            'urgensi'           => 'normal',
        ];
    }
    if ($endTs === false || $endTs <= $startTs) {
        $endTs = $startTs + 3600;
    }

    $now = time();
    $tglIndo = ilmuna_wp_api_format_id_date($tgl, false);
    $jadwalFmt = $tglIndo . ' · ' . date('H:i', $startTs) . '–' . date('H:i', $endTs) . ' WIB';

    if ($now >= $startTs && $now <= $endTs) {
        $remMin = max(1, (int)ceil(($endTs - $now) / 60));
        return [
            'mulai_iso'          => date('c', $startTs),
            'selesai_iso'        => date('c', $endTs),
            'jadwal_formatted'   => $jadwalFmt,
            'status_pelaksanaan' => 'sedang_berlangsung',
            'sisa_detik_mulai'   => 0,
            'sisa_waktu_label'   => 'Sedang Berlangsung (' . $remMin . ' menit tersisa)',
            'urgensi'            => 'kritis',
        ];
    }

    if ($now < $startTs) {
        $diff  = $startTs - $now;
        $days  = (int)floor($diff / 86400);
        $hours = (int)floor(($diff % 86400) / 3600);
        $mins  = (int)floor(($diff % 3600) / 60);

        if ($days >= 1) {
            $lbl = 'Mulai ' . $days . ' hari' . ($hours > 0 ? ' ' . $hours . ' jam' : '') . ' lagi';
        } elseif ($hours >= 1) {
            $lbl = 'Mulai ' . $hours . ' jam' . ($mins > 0 ? ' ' . $mins . ' menit' : '') . ' lagi';
        } else {
            $lbl = 'Mulai ' . max(1, $mins) . ' menit lagi';
        }

        $urgensi = ($diff <= 86400) ? 'kritis' : (($diff <= 259200) ? 'segera' : 'normal');
        return [
            'mulai_iso'          => date('c', $startTs),
            'selesai_iso'        => date('c', $endTs),
            'jadwal_formatted'   => $jadwalFmt,
            'status_pelaksanaan' => 'akan_datang',
            'sisa_detik_mulai'   => $diff,
            'sisa_waktu_label'   => $lbl,
            'urgensi'            => $urgensi,
        ];
    }

    return [
        'mulai_iso'          => date('c', $startTs),
        'selesai_iso'        => date('c', $endTs),
        'jadwal_formatted'   => $jadwalFmt,
        'status_pelaksanaan' => 'selesai',
        'sisa_detik_mulai'   => $startTs - $now,
        'sisa_waktu_label'   => 'Telah Selesai',
        'urgensi'            => 'berakhir',
    ];
}

/**
 * 4. JADWAL ASESMEN DARI BANK & MASTER ASESMEN SESUAI KELAS
 *    Mencakup: asesmen_sumatif (+ asesmen_sumatif_kelas multi-rombel),
 *              asesmen_formatif, asesmen_diagnostik, dan pengayaan_remedial.
 */
function ilmuna_sso_fetch_jadwal_asesmen(PDO $pdo, array $params = [], array $ctx = [])
{
    $limit  = max(1, min(100, (int)($params['limit'] ?? 30)));
    $page   = max(1, (int)($params['page'] ?? 1));
    $offset = ($page - 1) * $limit;

    if (!empty($ctx['filtered_by_class']) && empty($ctx['kelas_ids'])) {
        return [
            'items'      => [],
            'pagination' => ['page' => $page, 'limit' => $limit, 'total_items' => 0, 'total_pages' => 0],
        ];
    }

    $kategoriFilter = strtolower(trim((string)($params['kategori'] ?? 'all')));
    $includeDraft   = isset($params['include_draft']) && (string)$params['include_draft'] === '1';
    $includeClosed  = isset($params['include_closed']) && (string)$params['include_closed'] === '1';
    $upcomingOnly   = isset($params['upcoming_only']) && (string)$params['upcoming_only'] === '1';
    $mapelId        = isset($params['mapel_id']) && ctype_digit((string)$params['mapel_id']) ? (int)$params['mapel_id'] : 0;

    $allowedStats = ["'Aktif'"];
    if ($includeDraft) {
        $allowedStats[] = "'Draft'";
    }
    if ($includeClosed) {
        $allowedStats[] = "'Ditutup'";
        $allowedStats[] = "'Selesai'";
    }
    $statusInSql = implode(',', $allowedStats);

    $kelasIds = $ctx['kelas_ids'] ?? [];
    $hasKelasFilter = !empty($kelasIds);
    $inKelasPlaceholders = $hasKelasFilter ? implode(',', array_fill(0, count($kelasIds), '?')) : '';

    $unions = [];
    $binds  = [];

    // 4a. Asesmen Sumatif (mendukung multi-kelas via asesmen_sumatif_kelas!)
    if ($kategoriFilter === 'all' || $kategoriFilter === 'sumatif') {
        $w = ["asum.status IN ({$statusInSql})"];
        $b = [];
        if ($upcomingOnly) {
            $w[] = 'asum.tanggal_pelaksanaan >= CURDATE()';
        }
        if ($hasKelasFilter) {
            $w[] = "(k.id IN ({$inKelasPlaceholders}) OR EXISTS (
                SELECT 1 FROM asesmen_sumatif_kelas askf
                JOIN kelas_mata_pelajaran kmpf ON askf.id_kelas_mata_pelajaran = kmpf.id
                WHERE askf.id_asesmen_sumatif = asum.id AND kmpf.id_kelas IN ({$inKelasPlaceholders})
            ))";
            $b = array_merge($b, $kelasIds, $kelasIds);
        }
        if ($mapelId > 0) {
            $w[] = "(mp.id = ? OR EXISTS (
                SELECT 1 FROM asesmen_sumatif_kelas askm
                JOIN kelas_mata_pelajaran kmpm ON askm.id_kelas_mata_pelajaran = kmpm.id
                WHERE askm.id_asesmen_sumatif = asum.id AND kmpm.id_mata_pelajaran = ?
            ))";
            $b[] = $mapelId;
            $b[] = $mapelId;
        }

        $unions[] = "
            SELECT
                'sumatif' AS kategori,
                asum.id,
                asum.judul_asesmen,
                asum.jenis_sumatif AS sub_jenis,
                asum.pertemuan_ke,
                asum.durasi_menit,
                asum.tanggal_pelaksanaan,
                asum.jam_mulai,
                asum.jam_selesai,
                asum.status,
                asum.petunjuk_pengerjaan AS petunjuk,
                COALESCE(asum.semester, mp.semester, 'Ganjil') AS semester,
                COALESCE(asum.tahun_pelajaran, mp.tahun_pelajaran, k.tahun_pelajaran, '') AS tahun_pelajaran,
                k.id AS primary_kelas_id,
                COALESCE(k.nama_kelas, '') AS primary_nama_kelas,
                COALESCE(k.kode_kelas, '') AS primary_kode_kelas,
                COALESCE(mp.id, (
                    SELECT mp2.id FROM asesmen_sumatif_kelas ask2
                    JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id
                    JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id
                    WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1
                ), 0) AS mapel_id,
                COALESCE(mp.nama_mata_pelajaran, (
                    SELECT mp2.nama_mata_pelajaran FROM asesmen_sumatif_kelas ask2
                    JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id
                    JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id
                    WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1
                ), 'Mata Pelajaran') AS nama_mata_pelajaran,
                COALESCE(mp.kode_mapel, '') AS kode_mapel,
                COALESCE(g.nama_guru, g2.nama_guru, 'Guru Pengampu') AS nama_guru,
                (SELECT COUNT(*) FROM asesmen_sumatif_soal ass WHERE ass.id_asesmen_sumatif = asum.id) AS total_soal
            FROM asesmen_sumatif asum
            LEFT JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN kelas k ON kmp.id_kelas = k.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN guru g2 ON gmp.id_guru = g2.id
            LEFT JOIN guru g ON (asum.id_guru = g.id_user OR asum.id_guru = g.id)
            WHERE " . implode(' AND ', $w);
        $binds = array_merge($binds, $b);
    }

    // 4b. Asesmen Formatif
    if ($kategoriFilter === 'all' || $kategoriFilter === 'formatif') {
        $w = ["af.status IN ({$statusInSql})"];
        $b = [];
        if ($upcomingOnly) {
            $w[] = 'af.tanggal_pelaksanaan >= CURDATE()';
        }
        if ($hasKelasFilter) {
            $w[] = "k.id IN ({$inKelasPlaceholders})";
            $b = array_merge($b, $kelasIds);
        }
        if ($mapelId > 0) {
            $w[] = 'mp.id = ?';
            $b[] = $mapelId;
        }

        $unions[] = "
            SELECT
                'formatif' AS kategori,
                af.id,
                af.judul_asesmen,
                af.teknik_asesmen AS sub_jenis,
                af.pertemuan_ke,
                af.durasi_menit,
                af.tanggal_pelaksanaan,
                af.jam_mulai,
                af.jam_selesai,
                af.status,
                af.petunjuk_pengerjaan AS petunjuk,
                COALESCE(mp.semester, 'Ganjil') AS semester,
                COALESCE(mp.tahun_pelajaran, k.tahun_pelajaran, '') AS tahun_pelajaran,
                k.id AS primary_kelas_id,
                COALESCE(k.nama_kelas, '') AS primary_nama_kelas,
                COALESCE(k.kode_kelas, '') AS primary_kode_kelas,
                COALESCE(mp.id, 0) AS mapel_id,
                COALESCE(mp.nama_mata_pelajaran, 'Mata Pelajaran') AS nama_mata_pelajaran,
                COALESCE(mp.kode_mapel, '') AS kode_mapel,
                COALESCE(g.nama_guru, g2.nama_guru, 'Guru Pengampu') AS nama_guru,
                (SELECT COUNT(*) FROM asesmen_formatif_soal afs WHERE afs.id_asesmen_formatif = af.id) AS total_soal
            FROM asesmen_formatif af
            JOIN kelas_mata_pelajaran kmp ON af.id_kelas_mata_pelajaran = kmp.id
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN guru g2 ON gmp.id_guru = g2.id
            LEFT JOIN guru g ON (af.id_guru = g.id_user OR af.id_guru = g.id)
            WHERE " . implode(' AND ', $w);
        $binds = array_merge($binds, $b);
    }

    // 4c. Asesmen Diagnostik
    if ($kategoriFilter === 'all' || $kategoriFilter === 'diagnostik') {
        $w = ["ad.status IN ({$statusInSql})"];
        $b = [];
        if ($upcomingOnly) {
            $w[] = 'ad.tanggal_pelaksanaan >= CURDATE()';
        }
        if ($hasKelasFilter) {
            $w[] = "k.id IN ({$inKelasPlaceholders})";
            $b = array_merge($b, $kelasIds);
        }
        if ($mapelId > 0) {
            $w[] = 'mp.id = ?';
            $b[] = $mapelId;
        }

        $unions[] = "
            SELECT
                'diagnostik' AS kategori,
                ad.id,
                ad.judul_asesmen,
                ad.jenis_diagnostik AS sub_jenis,
                ad.pertemuan_ke,
                ad.durasi_menit,
                ad.tanggal_pelaksanaan,
                ad.jam_mulai,
                ad.jam_selesai,
                ad.status,
                ad.petunjuk_pengerjaan AS petunjuk,
                COALESCE(mp.semester, 'Ganjil') AS semester,
                COALESCE(mp.tahun_pelajaran, k.tahun_pelajaran, '') AS tahun_pelajaran,
                k.id AS primary_kelas_id,
                COALESCE(k.nama_kelas, '') AS primary_nama_kelas,
                COALESCE(k.kode_kelas, '') AS primary_kode_kelas,
                COALESCE(mp.id, 0) AS mapel_id,
                COALESCE(mp.nama_mata_pelajaran, 'Mata Pelajaran') AS nama_mata_pelajaran,
                COALESCE(mp.kode_mapel, '') AS kode_mapel,
                COALESCE(g.nama_guru, g2.nama_guru, 'Guru Pengampu') AS nama_guru,
                (SELECT COUNT(*) FROM asesmen_diagnostik_soal ads WHERE ads.id_asesmen_diagnostik = ad.id) AS total_soal
            FROM asesmen_diagnostik ad
            JOIN kelas_mata_pelajaran kmp ON ad.id_kelas_mata_pelajaran = kmp.id
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN guru g2 ON gmp.id_guru = g2.id
            LEFT JOIN guru g ON (ad.id_guru = g.id_user OR ad.id_guru = g.id)
            WHERE " . implode(' AND ', $w);
        $binds = array_merge($binds, $b);
    }

    // 4d. Pengayaan & Remedial
    if ($kategoriFilter === 'all' || $kategoriFilter === 'remedial' || $kategoriFilter === 'pengayaan') {
        $w = ["pr.status IN ({$statusInSql})"];
        $b = [];
        if ($upcomingOnly) {
            $w[] = 'pr.tanggal_pelaksanaan >= CURDATE()';
        }
        if ($hasKelasFilter) {
            $w[] = "k.id IN ({$inKelasPlaceholders})";
            $b = array_merge($b, $kelasIds);
        }
        if ($mapelId > 0) {
            $w[] = 'mp.id = ?';
            $b[] = $mapelId;
        }

        $unions[] = "
            SELECT
                'remedial' AS kategori,
                pr.id,
                pr.judul_program AS judul_asesmen,
                CONCAT(pr.kategori, ' — ', pr.bentuk_kegiatan) AS sub_jenis,
                pr.pertemuan_ke,
                pr.durasi_menit,
                pr.tanggal_pelaksanaan,
                pr.jam_mulai,
                pr.jam_selesai,
                pr.status,
                pr.petunjuk_pelaksanaan AS petunjuk,
                COALESCE(mp.semester, 'Ganjil') AS semester,
                COALESCE(mp.tahun_pelajaran, k.tahun_pelajaran, '') AS tahun_pelajaran,
                k.id AS primary_kelas_id,
                COALESCE(k.nama_kelas, '') AS primary_nama_kelas,
                COALESCE(k.kode_kelas, '') AS primary_kode_kelas,
                COALESCE(mp.id, 0) AS mapel_id,
                COALESCE(mp.nama_mata_pelajaran, 'Mata Pelajaran') AS nama_mata_pelajaran,
                COALESCE(mp.kode_mapel, '') AS kode_mapel,
                COALESCE(g.nama_guru, g2.nama_guru, 'Guru Pengampu') AS nama_guru,
                (SELECT COUNT(*) FROM pengayaan_remedial_soal prs WHERE prs.id_pengayaan_remedial = pr.id) AS total_soal
            FROM pengayaan_remedial pr
            JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN guru g2 ON gmp.id_guru = g2.id
            LEFT JOIN guru g ON (pr.id_guru = g.id_user OR pr.id_guru = g.id)
            WHERE " . implode(' AND ', $w);
        $binds = array_merge($binds, $b);
    }

    if (empty($unions)) {
        return [
            'items'      => [],
            'pagination' => ['page' => $page, 'limit' => $limit, 'total_items' => 0, 'total_pages' => 0],
        ];
    }

    $unionSql = implode("\nUNION ALL\n", $unions);

    $cStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$unionSql}) AS u_cnt");
    $cStmt->execute($binds);
    $total = (int)$cStmt->fetchColumn();

    $listSql = "
        SELECT * FROM ({$unionSql}) AS u_all
        ORDER BY CASE WHEN u_all.tanggal_pelaksanaan >= CURDATE() THEN 0 ELSE 1 END ASC,
                 CASE WHEN u_all.tanggal_pelaksanaan >= CURDATE() THEN UNIX_TIMESTAMP(CONCAT(u_all.tanggal_pelaksanaan, ' ', COALESCE(u_all.jam_mulai, '00:00:00')))
                      ELSE -UNIX_TIMESTAMP(CONCAT(u_all.tanggal_pelaksanaan, ' ', COALESCE(u_all.jam_mulai, '00:00:00'))) END ASC,
                 u_all.id DESC
        LIMIT {$limit} OFFSET {$offset}
    ";
    $lStmt = $pdo->prepare($listSql);
    $lStmt->execute($binds);
    $rows = $lStmt->fetchAll(PDO::FETCH_ASSOC);

    // Preload daftar multi-rombel untuk asesmen sumatif
    $sumatifIds = [];
    foreach ($rows as $r) {
        if ($r['kategori'] === 'sumatif') {
            $sumatifIds[] = (int)$r['id'];
        }
    }
    $sumatifKelasMap = [];
    if (!empty($sumatifIds)) {
        $inS = implode(',', array_fill(0, count($sumatifIds), '?'));
        $skStmt = $pdo->prepare("
            SELECT DISTINCT ask.id_asesmen_sumatif, k.id AS id_kelas, k.nama_kelas, k.kode_kelas
            FROM asesmen_sumatif_kelas ask
            JOIN kelas_mata_pelajaran kmp ON ask.id_kelas_mata_pelajaran = kmp.id
            JOIN kelas k ON kmp.id_kelas = k.id
            WHERE ask.id_asesmen_sumatif IN ({$inS})
            ORDER BY k.nama_kelas ASC
        ");
        $skStmt->execute($sumatifIds);
        foreach ($skStmt->fetchAll(PDO::FETCH_ASSOC) as $skr) {
            $sid = (int)$skr['id_asesmen_sumatif'];
            $sumatifKelasMap[$sid][] = [
                'id'   => (int)$skr['id_kelas'],
                'nama' => (string)$skr['nama_kelas'],
                'kode' => (string)($skr['kode_kelas'] ?? ''),
            ];
        }
    }

    $portalUjianUrl = ilmuna_wp_api_public_base_url() . '/dashboard/siswa/ujian';
    $items = [];

    foreach ($rows as $r) {
        $aid = (int)$r['id'];
        $kat = (string)$r['kategori'];
        $meta = ilmuna_sso_asesmen_schedule_meta($r['tanggal_pelaksanaan'], $r['jam_mulai'], $r['jam_selesai']);

        $daftarKelas = [];
        if ($kat === 'sumatif' && !empty($sumatifKelasMap[$aid])) {
            $daftarKelas = $sumatifKelasMap[$aid];
        } elseif (!empty($r['primary_kelas_id'])) {
            $daftarKelas[] = [
                'id'   => (int)$r['primary_kelas_id'],
                'nama' => (string)$r['primary_nama_kelas'],
                'kode' => (string)$r['primary_kode_kelas'],
            ];
        }

        // Bila difilter berdasarkan kelas siswa/tertentu, tampilkan kelas yang cocok sebagai kelas utama kartu
        $activeKelasObj = !empty($daftarKelas) ? $daftarKelas[0] : ['id' => 0, 'nama' => 'Semua Rombel', 'kode' => ''];
        if ($hasKelasFilter && !empty($daftarKelas)) {
            foreach ($daftarKelas as $dk) {
                if (in_array((int)$dk['id'], $kelasIds, true)) {
                    $activeKelasObj = $dk;
                    break;
                }
            }
        }

        $items[] = [
            'id'                  => $aid,
            'kategori_item'       => 'asesmen',
            'kategori_asesmen'    => $kat,
            'judul_asesmen'       => (string)$r['judul_asesmen'],
            'jenis_asesmen'       => (string)($r['sub_jenis'] ?? ucfirst($kat)),
            'status'              => (string)$r['status'],
            'tanggal_pelaksanaan' => $r['tanggal_pelaksanaan'],
            'jam_mulai'           => $r['jam_mulai'] ? substr((string)$r['jam_mulai'], 0, 5) : null,
            'jam_selesai'         => $r['jam_selesai'] ? substr((string)$r['jam_selesai'], 0, 5) : null,
            'durasi_menit'        => (int)($r['durasi_menit'] ?? 60),
            'mulai_iso'           => $meta['mulai_iso'],
            'selesai_iso'         => $meta['selesai_iso'],
            'jadwal_formatted'    => $meta['jadwal_formatted'],
            'status_pelaksanaan'  => $meta['status_pelaksanaan'],
            'sisa_detik_mulai'    => $meta['sisa_detik_mulai'],
            'sisa_waktu_label'    => $meta['sisa_waktu_label'],
            'urgensi'             => $meta['urgensi'],
            'pertemuan_ke'        => !empty($r['pertemuan_ke']) ? (int)$r['pertemuan_ke'] : null,
            'semester'            => (string)($r['semester'] ?? ''),
            'tahun_pelajaran'     => (string)($r['tahun_pelajaran'] ?? ''),
            'total_soal'          => (int)($r['total_soal'] ?? 0),
            'petunjuk_ringkas'    => ilmuna_wp_api_excerpt($r['petunjuk'] ?? '', 220),
            'kelas'               => $activeKelasObj,
            'daftar_kelas'        => $daftarKelas,
            'mata_pelajaran'      => [
                'id'   => (int)$r['mapel_id'],
                'nama' => (string)$r['nama_mata_pelajaran'],
                'kode' => (string)($r['kode_mapel'] ?? ''),
            ],
            'guru_pengampu'       => (string)$r['nama_guru'],
            'portal_url'          => $portalUjianUrl,
        ];
    }

    return [
        'items'      => $items,
        'pagination' => [
            'page'        => $page,
            'limit'       => $limit,
            'total_items' => $total,
            'total_pages' => (int)ceil($total / max(1, $limit)),
        ],
    ];
}

/**
 * Ambil riwayat event notifikasi real-time terbaru (untuk inisialisasi lonceng notifikasi SSO & replay missed events)
 */
function ilmuna_sso_fetch_recent_events(PDO $pdo, array $ctx = [], $sinceId = 0, $limit = 25)
{
    $limit   = max(1, min(100, (int)$limit));
    $sinceId = max(0, (int)$sinceId);

    if (!empty($ctx['filtered_by_class']) && empty($ctx['kelas_ids'])) {
        return [];
    }

    $where = ['1=1'];
    $binds = [];

    if ($sinceId > 0) {
        $where[] = 'e.id > ?';
        $binds[] = $sinceId;
    }

    if (!empty($ctx['kelas_ids'])) {
        $in = implode(',', array_fill(0, count($ctx['kelas_ids']), '?'));
        $where[] = "(e.kelas_id IS NULL OR e.kelas_id = 0 OR e.kelas_id IN ({$in}))";
        $binds = array_merge($binds, $ctx['kelas_ids']);
    }

    $orderSql = ($sinceId > 0) ? 'ASC' : 'DESC';
    $stmt = $pdo->prepare("
        SELECT e.id, e.event_uuid, e.event_type, e.category, e.kelas_id, e.nama_kelas, e.kode_kelas,
               e.reference_id, e.title, e.summary, e.deadline_at, e.urgency, e.portal_url,
               e.payload_json, e.signature_hmac, e.created_at
        FROM sso_realtime_events e
        WHERE " . implode(' AND ', $where) . "
        ORDER BY e.id {$orderSql}
        LIMIT {$limit}
    ");
    $stmt->execute($binds);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $events = [];
    foreach ($rows as $r) {
        $decodedPayload = json_decode((string)$r['payload_json'], true);
        $events[] = [
            'event_id'       => (int)$r['id'],
            'event_uuid'     => (string)$r['event_uuid'],
            'event_type'     => (string)$r['event_type'],
            'category'       => (string)$r['category'],
            'kelas'          => [
                'id'   => $r['kelas_id'] !== null ? (int)$r['kelas_id'] : null,
                'nama' => (string)($r['nama_kelas'] ?? 'Seluruh Kelas'),
                'kode' => (string)($r['kode_kelas'] ?? ''),
            ],
            'reference_id'   => $r['reference_id'] !== null ? (int)$r['reference_id'] : null,
            'title'          => (string)$r['title'],
            'summary'        => (string)($r['summary'] ?? ''),
            'deadline_at'    => $r['deadline_at'],
            'urgency'        => (string)$r['urgency'],
            'portal_url'     => $r['portal_url'],
            'data'           => is_array($decodedPayload) ? $decodedPayload : null,
            'signature_hmac' => (string)$r['signature_hmac'],
            'created_at'     => $r['created_at'],
            'created_at_iso' => date('c', strtotime((string)$r['created_at'])),
        ];
    }

    return $events;
}

/**
 * 5. PAKET FEED TERPADU SSO (TUGAS + PROYEK + KELOMPOK + JADWAL ASESMEN + TIMELINE DEADLINE + NOTIFIKASI)
 */
function ilmuna_sso_build_unified_feed(PDO $pdo, array $params = [], array $ctx = [])
{
    $limitTugas   = max(1, min(50, (int)($params['limit_tugas'] ?? ($params['limit'] ?? 15))));
    $limitProyek  = max(1, min(50, (int)($params['limit_proyek'] ?? ($params['limit'] ?? 15))));
    $limitAsesmen = max(1, min(50, (int)($params['limit_asesmen'] ?? ($params['limit'] ?? 20))));

    $tugasRes    = ilmuna_sso_fetch_tugas_broadcast($pdo, array_merge($params, ['limit' => $limitTugas, 'page' => 1]), $ctx);
    $proyekRes   = ilmuna_sso_fetch_proyek_broadcast($pdo, array_merge($params, ['limit' => $limitProyek, 'page' => 1]), $ctx);
    $kelompokRes = ilmuna_sso_fetch_kelompok_broadcast($pdo, $params, $ctx);
    $asesmenRes  = ilmuna_sso_fetch_jadwal_asesmen($pdo, array_merge($params, ['limit' => $limitAsesmen, 'page' => 1]), $ctx);

    // Susun timeline "Upcoming Deadlines & Schedules" yang sudah diurutkan waktu terdekat untuk widget SSO
    $timeline = [];

    foreach ($tugasRes['items'] as $t) {
        if (!$t['is_expired'] && !empty($t['tenggat_waktu_iso'])) {
            $timeline[] = [
                'tipe'             => 'tugas',
                'id'               => $t['id'],
                'judul'            => $t['judul_tugas'],
                'subjudul'         => $t['mata_pelajaran']['nama'] . ' · ' . $t['jenis_penugasan'],
                'kelas'            => $t['kelas'],
                'target_waktu_iso' => $t['tenggat_waktu_iso'],
                'target_formatted' => $t['tenggat_waktu_formatted'],
                'sisa_waktu_detik' => (int)$t['sisa_waktu_detik'],
                'sisa_waktu_label' => $t['sisa_waktu_label'],
                'urgensi'          => $t['urgensi'],
                'portal_url'       => $t['portal_url'],
            ];
        }
    }

    foreach ($proyekRes['items'] as $p) {
        if (!$p['is_expired'] && !empty($p['jadwal_selesai_iso'])) {
            $timeline[] = [
                'tipe'             => 'proyek',
                'id'               => $p['id'],
                'judul'            => $p['nama_proyek'],
                'subjudul'         => $p['mata_pelajaran']['nama'] . ' · P5/PjBL (' . $p['jenis_proyek'] . ')',
                'kelas'            => $p['kelas'],
                'target_waktu_iso' => $p['jadwal_selesai_iso'],
                'target_formatted' => $p['jadwal_selesai_formatted'],
                'sisa_waktu_detik' => (int)$p['sisa_waktu_detik'],
                'sisa_waktu_label' => $p['sisa_waktu_label'],
                'urgensi'          => $p['urgensi'],
                'portal_url'       => $p['portal_url'],
            ];
        }
    }

    foreach ($asesmenRes['items'] as $a) {
        if ($a['status_pelaksanaan'] !== 'selesai' && !empty($a['mulai_iso'])) {
            $timeline[] = [
                'tipe'             => 'asesmen',
                'id'               => $a['id'],
                'judul'            => $a['judul_asesmen'],
                'subjudul'         => $a['mata_pelajaran']['nama'] . ' · ' . $a['jenis_asesmen'],
                'kelas'            => $a['kelas'],
                'target_waktu_iso' => $a['mulai_iso'],
                'target_formatted' => $a['jadwal_formatted'],
                'sisa_waktu_detik' => (int)($a['sisa_detik_mulai'] ?? 0),
                'sisa_waktu_label' => $a['sisa_waktu_label'],
                'urgensi'          => $a['urgensi'],
                'portal_url'       => $a['portal_url'],
            ];
        }
    }

    usort($timeline, function ($a, $b) {
        return ($a['sisa_waktu_detik'] ?? PHP_INT_MAX) <=> ($b['sisa_waktu_detik'] ?? PHP_INT_MAX);
    });

    $sinceEventId = isset($params['since_event_id']) ? (int)$params['since_event_id'] : 0;
    $recentEvents = ilmuna_sso_fetch_recent_events($pdo, $ctx, $sinceEventId, 20);

    return [
        'identity_context'     => [
            'role'              => $ctx['role'],
            'filtered_by_class' => $ctx['filtered_by_class'],
            'kelas_list'        => $ctx['kelas_list'],
            'siswa'             => $ctx['siswa'],
            'guru'              => $ctx['guru'],
        ],
        'upcoming_deadlines'   => array_slice($timeline, 0, 25),
        'tugas_broadcast'      => $tugasRes['items'],
        'proyek_broadcast'     => $proyekRes['items'],
        'kelompok_broadcast'   => $kelompokRes['items'],
        'jadwal_asesmen'       => $asesmenRes['items'],
        'recent_notifications' => $recentEvents,
        'counts'               => [
            'tugas_broadcast'    => $tugasRes['pagination']['total_items'],
            'proyek_broadcast'   => $proyekRes['pagination']['total_items'],
            'kelompok_broadcast' => $kelompokRes['total_items'],
            'jadwal_asesmen'     => $asesmenRes['pagination']['total_items'],
            'upcoming_deadlines' => count($timeline),
        ],
    ];
}
