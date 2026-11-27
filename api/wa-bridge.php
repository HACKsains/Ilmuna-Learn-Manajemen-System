<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — WA BOT TWO-WAY INTEGRATION BRIDGE API (HMAC-SHA256)
 * ==============================================================================
 * Endpoint : POST /api/wa-bridge.php
 * Consumer : Ilmuna WA Bot (`C:\laragon\www\wa bot\core\LearnBridgeClient.php`)
 * Security : X-Ilmuna-Key + X-Ilmuna-Timestamp (±300s) + X-Ilmuna-Signature (HMAC-SHA256)
 *
 * Actions (API_INTEGRATION.md §3):
 *   1. lookup_contact         : Deteksi role & daftar anak multi-anak dari phone_e164
 *   2. student_summary        : Nilai, Tugas, Proyek, Absensi, Perizinan, Mutaba'ah & BK
 *   3. upcoming_academic      : Tugas, Proyek, Agenda/Kaldik, Asesmen CBT mendatang
 *   4. approve_perizinan      : Approval 1-klik izin siswa oleh Guru + sync absensi
 *   5. record_group_violation : Input pelanggaran grup WA ke `catatan_pelanggaran_siswa`
 *   6. audience_list          : Daftar nomor WA target broadcast per role / kelas
 *   7. public_forms           : Tautan hash aktif `form-siswa?h=...` & `form-guru?h=...`
 *   8. teacher_overview       : Rekap absensi hari ini & daftar perizinan pending guru
 *   9. student_parents        : Daftar nomor WA orang tua/wali dari siswa_id
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

// Lewati HTML JS-interstitial Guardian Dome WAF agar WA Bot menerima JSON murni
if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/../config.php';
require_once BASE_PATH . '/includes/wa-notifier.php';

/**
 * Verifikasi header HMAC-SHA256 + Anti-Replay (±300 detik)
 */
function ilmuna_wa_bridge_verify_request(array $headers, $rawBody)
{
    $norm = [];
    foreach ($headers as $k => $v) {
        $norm[strtolower((string)$k)] = is_array($v) ? implode(', ', $v) : (string)$v;
    }

    $key = trim($norm['x-ilmuna-key'] ?? '');
    $ts  = trim($norm['x-ilmuna-timestamp'] ?? '');
    $sig = strtolower(trim($norm['x-ilmuna-signature'] ?? ''));

    if ($key === '' || !hash_equals(WA_BRIDGE_PUBLIC_KEY, $key)) {
        return false;
    }
    if ($ts === '' || !ctype_digit($ts)) {
        return false;
    }
    if (abs(time() - (int)$ts) > 300) {
        return false;
    }
    $expected = hash_hmac('sha256', $ts . '.' . $rawBody, WA_BRIDGE_HMAC_SECRET);
    return $sig !== '' && hash_equals($expected, $sig);
}

/**
 * Ambil ekspresi SQL normalisasi nomor (digit-only suffix match 9-11 digit terakhir
 * lalu verifikasi presisi di PHP dengan ilmuna_wa_normalize_phone agar 100% akurat
 * terhadap format 08xx / +62 8xx / 628xx / tanda hubung)
 */
function ilmuna_wa_phone_matches($rawDbPhone, $targetE164)
{
    $norm = ilmuna_wa_normalize_phone($rawDbPhone);
    return $norm !== '' && $norm === $targetE164;
}

/**
 * Dispatcher inti seluruh action WA Bot Bridge (dapat dipanggil via HTTP maupun CLI test)
 */
function ilmuna_wa_bridge_dispatch(PDO $pdo, array $req)
{
    $action = strtolower(trim((string)($req['action'] ?? '')));

    switch ($action) {
        case 'lookup_contact':
            return ilmuna_wa_action_lookup_contact($pdo, $req);

        case 'student_summary':
            return ilmuna_wa_action_student_summary($pdo, $req);

        case 'upcoming_academic':
            return ilmuna_wa_action_upcoming_academic($pdo, $req);

        case 'approve_perizinan':
            return ilmuna_wa_action_approve_perizinan($pdo, $req);

        case 'record_group_violation':
            return ilmuna_wa_action_record_group_violation($pdo, $req);

        case 'audience_list':
            return ilmuna_wa_action_audience_list($pdo, $req);

        case 'public_forms':
            return ilmuna_wa_action_public_forms($pdo);

        case 'teacher_overview':
            return ilmuna_wa_action_teacher_overview($pdo, $req);

        case 'student_parents':
            return ilmuna_wa_action_student_parents($pdo, $req);

        default:
            return ['http' => 400, 'success' => false, 'message' => "Action '{$action}' tidak dikenali.", 'data' => []];
    }
}

function ilmuna_wa_action_lookup_contact(PDO $pdo, array $req)
{
    $phone = ilmuna_wa_normalize_phone($req['phone_e164'] ?? '');
    if ($phone === '') {
        return ['http' => 422, 'success' => false, 'message' => 'phone_e164 tidak valid.', 'data' => []];
    }
    $tail = '%' . substr($phone, -8) . '%';

    // 1. Cek Admin / Guru BK / Staf di `users` + `user_roles` + `roles`
    $stmtU = $pdo->prepare(
        "SELECT u.id, u.full_name, u.phone, GROUP_CONCAT(DISTINCT LOWER(r.code)) AS role_codes
         FROM users u
         LEFT JOIN user_roles ur ON ur.user_id = u.id
         LEFT JOIN roles r ON r.id = ur.role_id
         WHERE u.is_active = 1 AND u.phone IS NOT NULL AND REPLACE(REPLACE(REPLACE(u.phone, '-', ''), ' ', ''), '+', '') LIKE :tail
         GROUP BY u.id"
    );
    $stmtU->execute([':tail' => $tail]);
    foreach ($stmtU->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (!ilmuna_wa_phone_matches($u['phone'], $phone)) {
            continue;
        }
        $codes = array_filter(explode(',', (string)$u['role_codes']));
        if (in_array('admin', $codes, true) || in_array('super_admin', $codes, true)) {
            return [
                'http' => 200, 'success' => true, 'message' => 'Kontak Admin ditemukan.',
                'data' => ['role' => 'admin', 'learn_ref_id' => (int)$u['id'], 'display_name' => (string)$u['full_name'], 'extra' => []],
            ];
        }
        if (in_array('guru_bk', $codes, true) || in_array('bk', $codes, true)) {
            return [
                'http' => 200, 'success' => true, 'message' => 'Kontak Guru BK ditemukan.',
                'data' => ['role' => 'guru_bk', 'learn_ref_id' => (int)$u['id'], 'display_name' => (string)$u['full_name'], 'extra' => []],
            ];
        }
    }

    // 2. Cek Guru di tabel `guru`
    $stmtG = $pdo->prepare(
        "SELECT g.id, g.id_user, g.nama_guru, g.no_hp,
                (SELECT GROUP_CONCAT(DISTINCT LOWER(r.code))
                 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = g.id_user) AS role_codes
         FROM guru g
         WHERE g.no_hp IS NOT NULL AND REPLACE(REPLACE(REPLACE(g.no_hp, '-', ''), ' ', ''), '+', '') LIKE :tail"
    );
    $stmtG->execute([':tail' => $tail]);
    foreach ($stmtG->fetchAll(PDO::FETCH_ASSOC) as $g) {
        if (!ilmuna_wa_phone_matches($g['no_hp'], $phone)) {
            continue;
        }
        $codes = array_filter(explode(',', (string)($g['role_codes'] ?? '')));
        $role = (in_array('guru_bk', $codes, true) || in_array('bk', $codes, true)) ? 'guru_bk' : 'guru';
        return [
            'http' => 200, 'success' => true, 'message' => 'Kontak Guru ditemukan.',
            'data' => [
                'role'         => $role,
                'learn_ref_id' => (int)$g['id'],
                'display_name' => (string)$g['nama_guru'],
                'extra'        => ['id_user' => $g['id_user'] ? (int)$g['id_user'] : null],
            ],
        ];
    }

    // 3. Cek Orang Tua (mendukung Multi-Anak melalui `orang_tua` + `orang_tua_siswa`)
    $stmtOt = $pdo->prepare(
        "SELECT id, nama_ayah, no_hp_ayah, nama_ibu, no_hp_ibu, nama_wali, no_hp_wali
         FROM orang_tua
         WHERE status = 'aktif' AND (
            REPLACE(REPLACE(REPLACE(COALESCE(no_hp_ayah,''), '-', ''), ' ', ''), '+', '') LIKE :t1 OR
            REPLACE(REPLACE(REPLACE(COALESCE(no_hp_ibu,''),  '-', ''), ' ', ''), '+', '') LIKE :t2 OR
            REPLACE(REPLACE(REPLACE(COALESCE(no_hp_wali,''), '-', ''), ' ', ''), '+', '') LIKE :t3
         )"
    );
    $stmtOt->execute([':t1' => $tail, ':t2' => $tail, ':t3' => $tail]);
    $matchedParentIds = [];
    $parentDisplayName = '';

    foreach ($stmtOt->fetchAll(PDO::FETCH_ASSOC) as $ot) {
        if (ilmuna_wa_phone_matches($ot['no_hp_ayah'] ?? '', $phone)) {
            $matchedParentIds[] = (int)$ot['id'];
            if ($parentDisplayName === '' && !empty($ot['nama_ayah'])) {
                $parentDisplayName = (string)$ot['nama_ayah'];
            }
        } elseif (ilmuna_wa_phone_matches($ot['no_hp_ibu'] ?? '', $phone)) {
            $matchedParentIds[] = (int)$ot['id'];
            if ($parentDisplayName === '' && !empty($ot['nama_ibu'])) {
                $parentDisplayName = (string)$ot['nama_ibu'];
            }
        } elseif (ilmuna_wa_phone_matches($ot['no_hp_wali'] ?? '', $phone)) {
            $matchedParentIds[] = (int)$ot['id'];
            if ($parentDisplayName === '' && !empty($ot['nama_wali'])) {
                $parentDisplayName = (string)$ot['nama_wali'];
            }
        }
    }

    if (!empty($matchedParentIds)) {
        $in = implode(',', array_fill(0, count($matchedParentIds), '?'));
        $cStmt = $pdo->prepare(
            "SELECT DISTINCT s.id, s.nama_lengkap AS nama, s.nisn, s.id_kelas AS kelas_id, COALESCE(k.nama_kelas, '-') AS kelas
             FROM orang_tua_siswa ots
             JOIN siswa s ON s.id = ots.id_siswa
             LEFT JOIN kelas k ON k.id = s.id_kelas
             WHERE ots.id_orang_tua IN ({$in})
             ORDER BY s.nama_lengkap ASC"
        );
        $cStmt->execute($matchedParentIds);
        $children = [];
        foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $children[] = [
                'id'       => (int)$c['id'],
                'nama'     => (string)$c['nama'],
                'nisn'     => (string)$c['nisn'],
                'kelas_id' => (int)$c['kelas_id'],
                'kelas'    => (string)$c['kelas'],
            ];
        }

        return [
            'http' => 200, 'success' => true, 'message' => 'Kontak Orang Tua ditemukan.',
            'data' => [
                'role'         => 'orang_tua',
                'learn_ref_id' => $matchedParentIds[0],
                'display_name' => $parentDisplayName !== '' ? $parentDisplayName : 'Wali Murid',
                'children'     => $children,
                'extra'        => ['children' => $children],
            ],
        ];
    }

    // 4. Cek Siswa di tabel `siswa`
    $stmtS = $pdo->prepare(
        "SELECT s.id, s.nama_lengkap, s.nisn, s.no_hp, s.id_kelas, COALESCE(k.nama_kelas, '-') AS nama_kelas
         FROM siswa s
         LEFT JOIN kelas k ON k.id = s.id_kelas
         WHERE s.no_hp IS NOT NULL AND REPLACE(REPLACE(REPLACE(s.no_hp, '-', ''), ' ', ''), '+', '') LIKE :tail"
    );
    $stmtS->execute([':tail' => $tail]);
    foreach ($stmtS->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if (!ilmuna_wa_phone_matches($s['no_hp'], $phone)) {
            continue;
        }
        $parentPhones = ilmuna_wa_get_parent_phones($pdo, (int)$s['id']);
        return [
            'http' => 200, 'success' => true, 'message' => 'Kontak Siswa ditemukan.',
            'data' => [
                'role'          => 'siswa',
                'learn_ref_id'  => (int)$s['id'],
                'display_name'  => (string)$s['nama_lengkap'],
                'kelas'         => (string)$s['nama_kelas'],
                'kelas_id'      => (int)$s['id_kelas'],
                'parent_phones' => $parentPhones,
                'extra'         => [
                    'kelas'         => (string)$s['nama_kelas'],
                    'kelas_id'      => (int)$s['id_kelas'],
                    'nisn'          => (string)$s['nisn'],
                    'parent_phones' => $parentPhones,
                ],
            ],
        ];
    }

    return [
        'http' => 200, 'success' => true, 'message' => 'Nomor belum terdaftar (Tamu).',
        'data' => ['role' => 'guest', 'learn_ref_id' => null, 'display_name' => 'Tamu', 'extra' => []],
    ];
}

function ilmuna_wa_action_student_summary(PDO $pdo, array $req)
{
    $studentId = (int)($req['student_id'] ?? 0);
    if ($studentId <= 0) {
        return ['http' => 422, 'success' => false, 'message' => 'student_id wajib diisi.', 'data' => []];
    }

    $stStmt = $pdo->prepare(
        'SELECT s.id, s.nama_lengkap, s.id_kelas, COALESCE(k.nama_kelas, "-") AS nama_kelas
         FROM siswa s LEFT JOIN kelas k ON k.id = s.id_kelas WHERE s.id = :id LIMIT 1'
    );
    $stStmt->execute([':id' => $studentId]);
    $siswa = $stStmt->fetch(PDO::FETCH_ASSOC);
    if (!$siswa) {
        return ['http' => 404, 'success' => false, 'message' => 'Siswa tidak ditemukan.', 'data' => []];
    }

    $kelasId = (int)$siswa['id_kelas'];

    // Rata-rata nilai tugas
    $avgStmt = $pdo->prepare('SELECT ROUND(AVG(nilai), 1) FROM nilai_tugas WHERE id_siswa = :sid');
    $avgStmt->execute([':sid' => $studentId]);
    $avg = $avgStmt->fetchColumn();

    // Tugas aktif di kelas siswa
    $tStmt = $pdo->prepare(
        "SELECT t.id, t.judul_tugas AS judul, DATE_FORMAT(t.tenggat_waktu, '%d %b %Y %H:%i') AS tenggat,
                EXISTS(SELECT 1 FROM pengumpulan_tugas pt WHERE pt.id_tugas = t.id AND pt.id_siswa = :sid) AS sudah_kumpul
         FROM tugas t
         JOIN kelas_mata_pelajaran kmp ON kmp.id = t.id_kelas_mata_pelajaran
         WHERE kmp.id_kelas = :kid AND t.status_tugas = 'Dibuka'
         ORDER BY t.tenggat_waktu ASC LIMIT 6"
    );
    $tStmt->execute([':sid' => $studentId, ':kid' => $kelasId]);
    $tugasAktif = $tStmt->fetchAll(PDO::FETCH_ASSOC);

    // Proyek P5/PPRA aktif di kelas siswa
    $pStmt = $pdo->prepare(
        "SELECT p.id, p.nama_proyek AS judul, DATE_FORMAT(p.jadwal_selesai, '%d %b %Y') AS tenggat
         FROM proyek p
         JOIN kelas_mata_pelajaran kmp ON kmp.id = p.id_kelas_mata_pelajaran
         WHERE kmp.id_kelas = :kid AND p.status_proyek = 'Sedang Berjalan'
         ORDER BY p.jadwal_selesai ASC LIMIT 5"
    );
    $pStmt->execute([':kid' => $kelasId]);
    $proyekAktif = $pStmt->fetchAll(PDO::FETCH_ASSOC);

    // Rekap Absensi
    $aStmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM absensi WHERE id_siswa = :sid GROUP BY status');
    $aStmt->execute([':sid' => $studentId]);
    $absMap = $aStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Perizinan terbaru
    $izStmt = $pdo->prepare(
        'SELECT id, tanggal_izin, jenis_izin, status_perizinan
         FROM perizinan WHERE id_siswa = :sid ORDER BY id DESC LIMIT 5'
    );
    $izStmt->execute([':sid' => $studentId]);
    $perizinan = $izStmt->fetchAll(PDO::FETCH_ASSOC);

    // Mutaba'ah Yaumiyah (30 hari terakhir)
    $mStmt = $pdo->prepare(
        'SELECT COUNT(*) AS hari,
                COALESCE(SUM(subuh + dzuhur + ashar + maghrib + isya), 0) AS sholat_tercatat
         FROM mutabaah_logs
         WHERE student_id = :sid AND log_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
    );
    $mStmt->execute([':sid' => $studentId]);
    $mut = $mStmt->fetch(PDO::FETCH_ASSOC);
    $mutDays = (int)($mut['hari'] ?? 0);
    $mutPct = $mutDays > 0 ? (int)round(((int)$mut['sholat_tercatat'] / ($mutDays * 5)) * 100) : 0;

    // Catatan Pelanggaran BK
    $bkStmt = $pdo->prepare(
        'SELECT tanggal_kejadian AS tanggal, kategori_pelanggaran AS pelanggaran, poin, lokasi_kejadian
         FROM catatan_pelanggaran_siswa WHERE id_siswa = :sid ORDER BY id DESC LIMIT 5'
    );
    $bkStmt->execute([':sid' => $studentId]);
    $bkList = $bkStmt->fetchAll(PDO::FETCH_ASSOC);

    $bkSumStmt = $pdo->prepare('SELECT COALESCE(SUM(poin), 0) FROM catatan_pelanggaran_siswa WHERE id_siswa = :sid');
    $bkSumStmt->execute([':sid' => $studentId]);
    $totalPoin = (int)$bkSumStmt->fetchColumn();

    return [
        'http'    => 200,
        'success' => true,
        'message' => 'Ringkasan akademik siswa berhasil dimuat.',
        'data'    => [
            'student_name'      => (string)$siswa['nama_lengkap'],
            'kelas'             => (string)$siswa['nama_kelas'],
            'rata_rata_nilai'   => $avg !== null ? (float)$avg : '-',
            'tugas_aktif'       => $tugasAktif,
            'proyek_aktif'      => $proyekAktif,
            'absensi'           => [
                'hadir' => (int)($absMap['H'] ?? 0) + (int)($absMap['T'] ?? 0),
                'sakit' => (int)($absMap['S'] ?? 0),
                'izin'  => (int)($absMap['I'] ?? 0),
                'alpa'  => (int)($absMap['A'] ?? 0),
            ],
            'perizinan_terbaru' => $perizinan,
            'mutabaah_persen'   => min(100, $mutPct),
            'bk'                => [
                'total_poin'      => $totalPoin,
                'indeks_disiplin' => max(0, 100 - $totalPoin),
                'riwayat'         => $bkList,
            ],
        ],
    ];
}

function ilmuna_wa_action_upcoming_academic(PDO $pdo, array $req)
{
    $kelasId   = !empty($req['kelas_id']) ? (int)$req['kelas_id'] : null;
    $daysAhead = max(1, min(30, (int)($req['days_ahead'] ?? 7)));
    $baseUrl   = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://learn.ilmuna.my.id';
    $items     = [];

    // 1. Tugas mendekati tenggat
    $sqlT = "SELECT t.id, t.judul_tugas, t.tenggat_waktu, t.deskripsi_instruksi, kmp.id_kelas
             FROM tugas t
             JOIN kelas_mata_pelajaran kmp ON kmp.id = t.id_kelas_mata_pelajaran
             WHERE t.status_tugas = 'Dibuka'
               AND t.tenggat_waktu BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :days DAY)"
        . ($kelasId ? ' AND kmp.id_kelas = :kid' : '')
        . ' ORDER BY t.tenggat_waktu ASC LIMIT 10';
    $stT = $pdo->prepare($sqlT);
    $stT->bindValue(':days', $daysAhead, PDO::PARAM_INT);
    if ($kelasId) {
        $stT->bindValue(':kid', $kelasId, PDO::PARAM_INT);
    }
    $stT->execute();
    foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'module'       => 'tugas',
            'ref_id'       => (int)$r['id'],
            'title'        => (string)$r['judul_tugas'],
            'kelas_ids'    => [(int)$r['id_kelas']],
            'target_roles' => ['siswa', 'orang_tua', 'group_kelas'],
            'deadline_at'  => (string)$r['tenggat_waktu'],
            'date_label'   => date('d M Y H:i', strtotime((string)$r['tenggat_waktu'])) . ' WIB',
            'summary'      => mb_substr(strip_tags((string)$r['deskripsi_instruksi']), 0, 160, 'UTF-8'),
            'action_url'   => $baseUrl . '/dashboard/siswa/tugas',
            'reminder_tag' => 'Pengingat H-1',
        ];
    }

    // 2. Proyek P5/PPRA mendekati selesai
    $sqlP = "SELECT p.id, p.nama_proyek, p.jadwal_selesai, p.deskripsi_tujuan, kmp.id_kelas
             FROM proyek p
             JOIN kelas_mata_pelajaran kmp ON kmp.id = p.id_kelas_mata_pelajaran
             WHERE p.status_proyek = 'Sedang Berjalan'
               AND p.jadwal_selesai BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)"
        . ($kelasId ? ' AND kmp.id_kelas = :kid' : '')
        . ' ORDER BY p.jadwal_selesai ASC LIMIT 8';
    $stP = $pdo->prepare($sqlP);
    $stP->bindValue(':days', $daysAhead, PDO::PARAM_INT);
    if ($kelasId) {
        $stP->bindValue(':kid', $kelasId, PDO::PARAM_INT);
    }
    $stP->execute();
    foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'module'       => 'proyek',
            'ref_id'       => (int)$r['id'],
            'title'        => (string)$r['nama_proyek'],
            'kelas_ids'    => [(int)$r['id_kelas']],
            'target_roles' => ['siswa', 'orang_tua', 'group_kelas'],
            'deadline_at'  => (string)$r['jadwal_selesai'] . ' 23:59:00',
            'date_label'   => date('d M Y', strtotime((string)$r['jadwal_selesai'])),
            'summary'      => mb_substr(strip_tags((string)$r['deskripsi_tujuan']), 0, 160, 'UTF-8'),
            'action_url'   => $baseUrl . '/dashboard/siswa',
            'reminder_tag' => 'Pengingat Proyek',
        ];
    }

    // 3. Asesmen Sumatif / CBT aktif mendatang
    $sqlA = "SELECT a.id, a.judul_asesmen, a.tanggal_pelaksanaan, a.jam_mulai, kmp.id_kelas
             FROM asesmen_sumatif a
             JOIN kelas_mata_pelajaran kmp ON kmp.id = a.id_kelas_mata_pelajaran
             WHERE a.status = 'Aktif'
               AND a.tanggal_pelaksanaan BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)"
        . ($kelasId ? ' AND kmp.id_kelas = :kid' : '')
        . ' ORDER BY a.tanggal_pelaksanaan ASC LIMIT 8';
    $stA = $pdo->prepare($sqlA);
    $stA->bindValue(':days', $daysAhead, PDO::PARAM_INT);
    if ($kelasId) {
        $stA->bindValue(':kid', $kelasId, PDO::PARAM_INT);
    }
    $stA->execute();
    foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'module'       => 'asesmen',
            'ref_id'       => (int)$r['id'],
            'title'        => (string)$r['judul_asesmen'],
            'kelas_ids'    => [(int)$r['id_kelas']],
            'target_roles' => ['siswa', 'orang_tua', 'group_kelas'],
            'deadline_at'  => (string)$r['tanggal_pelaksanaan'] . ' ' . (string)$r['jam_mulai'],
            'date_label'   => date('d M Y', strtotime((string)$r['tanggal_pelaksanaan'])) . ' ' . substr((string)$r['jam_mulai'], 0, 5) . ' WIB',
            'summary'      => 'Pastikan perangkat Exambro & kartu peserta ujian sudah siap.',
            'action_url'   => $baseUrl . '/exam.php',
            'reminder_tag' => 'Pengingat CBT',
        ];
    }

    // 4. Agenda & Kalender Pendidikan non-reguler (kegiatan sekolah / libur)
    $stK = $pdo->prepare(
        "SELECT id, tanggal, status_hari, keterangan
         FROM kalender_pendidikan
         WHERE status_hari <> 'Efektif KBM'
           AND tanggal BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
         ORDER BY tanggal ASC LIMIT 8"
    );
    $stK->bindValue(':days', $daysAhead, PDO::PARAM_INT);
    $stK->execute();
    foreach ($stK->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = [
            'module'       => 'agenda',
            'ref_id'       => (int)$r['id'],
            'title'        => (string)($r['keterangan'] ?: $r['status_hari']),
            'kelas_ids'    => [],
            'target_roles' => ['siswa', 'orang_tua', 'guru', 'group_kelas'],
            'deadline_at'  => (string)$r['tanggal'] . ' 07:00:00',
            'date_label'   => date('d M Y', strtotime((string)$r['tanggal'])) . ' (' . $r['status_hari'] . ')',
            'summary'      => (string)$r['status_hari'] . ' — ' . (string)$r['keterangan'],
            'action_url'   => $baseUrl,
            'reminder_tag' => 'Agenda Kaldik',
        ];
    }

    return [
        'http'    => 200,
        'success' => true,
        'message' => 'Agenda akademik mendatang berhasil dimuat.',
        'data'    => ['items' => $items],
    ];
}

function ilmuna_wa_action_approve_perizinan(PDO $pdo, array $req)
{
    $izinId    = (int)($req['izin_id'] ?? 0);
    $decision  = strtolower(trim((string)($req['decision'] ?? '')));
    $guruPhone = ilmuna_wa_normalize_phone($req['guru_phone'] ?? '');
    $catatan   = trim((string)($req['catatan'] ?? 'Diproses via tombol WA Bot'));

    if ($izinId <= 0 || !in_array($decision, ['disetujui', 'ditolak'], true) || $guruPhone === '') {
        return ['http' => 422, 'success' => false, 'message' => 'Parameter izin_id, decision, atau guru_phone tidak valid.', 'data' => []];
    }

    // Validasi nomor WA adalah guru sah (atau admin)
    $lookup = ilmuna_wa_action_lookup_contact($pdo, ['phone_e164' => $guruPhone]);
    $role   = (string)($lookup['data']['role'] ?? 'guest');
    if (!in_array($role, ['guru', 'guru_bk', 'admin'], true)) {
        return ['http' => 403, 'success' => false, 'message' => 'Nomor WhatsApp bukan Guru/Admin terdaftar.', 'data' => []];
    }
    $guruId   = ($role === 'guru' || $role === 'guru_bk') ? (int)$lookup['data']['learn_ref_id'] : null;
    $guruName = (string)($lookup['data']['display_name'] ?? 'Guru');

    $izStmt = $pdo->prepare(
        'SELECT p.*, s.nama_lengkap AS student_name
         FROM perizinan p JOIN siswa s ON s.id = p.id_siswa
         WHERE p.id = :id LIMIT 1'
    );
    $izStmt->execute([':id' => $izinId]);
    $izin = $izStmt->fetch(PDO::FETCH_ASSOC);
    if (!$izin) {
        return ['http' => 404, 'success' => false, 'message' => "Pengajuan izin #{$izinId} tidak ditemukan.", 'data' => []];
    }

    $approvalGuru = $decision === 'disetujui' ? 'Approved' : 'Rejected';
    $statusFinal  = $decision === 'disetujui' ? 'Disetujui' : 'Ditolak';

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare(
            'UPDATE perizinan
             SET approval_guru = :ag,
                 id_guru = COALESCE(:gid, id_guru),
                 waktu_approval_guru = NOW(),
                 catatan_guru = :cat,
                 status_perizinan = :st
             WHERE id = :id'
        );
        $upd->execute([
            ':ag'  => $approvalGuru,
            ':gid' => $guruId,
            ':cat' => $catatan,
            ':st'  => $statusFinal,
            ':id'  => $izinId,
        ]);

        // Jika disetujui dan belum tersinkron ke absensi, catat otomatis ke tabel `absensi`
        if ($decision === 'disetujui' && (int)$izin['is_sync_to_absensi'] === 0) {
            $absCode = ($izin['jenis_izin'] === 'Sakit') ? 'S' : 'I';
            $insAbs = $pdo->prepare(
                "INSERT INTO absensi
                    (id_siswa, id_kelas_mata_pelajaran, id_jadwal, id_modul_ajar, pertemuan_ke,
                     status, latitude, longitude, waktu_absen, id_perizinan, metode_absen, keterangan)
                 VALUES
                    (:sid, :kmp, :jad, :mod, :pert, :st, :lat, :lng, NOW(), :pid, 'Sistem Perizinan', :ket)"
            );
            $insAbs->execute([
                ':sid'  => (int)$izin['id_siswa'],
                ':kmp'  => (int)$izin['id_kelas_mata_pelajaran'],
                ':jad'  => $izin['id_jadwal'] ? (int)$izin['id_jadwal'] : null,
                ':mod'  => $izin['id_modul_ajar'] ? (int)$izin['id_modul_ajar'] : null,
                ':pert' => (int)$izin['pertemuan_ke'],
                ':st'   => $absCode,
                ':lat'  => $izin['latitude'],
                ':lng'  => $izin['longitude'],
                ':pid'  => $izinId,
                ':ket'  => 'Disetujui WA oleh ' . $guruName . ': ' . mb_substr((string)$izin['alasan'], 0, 200, 'UTF-8'),
            ]);
            $absId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE perizinan SET is_sync_to_absensi = 1, id_absensi = :aid WHERE id = :id')
                ->execute([':aid' => $absId, ':id' => $izinId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['http' => 500, 'success' => false, 'message' => 'Gagal memperbarui perizinan: ' . $e->getMessage(), 'data' => []];
    }

    // Kirim notifikasi balik non-blocking ke siswa & orang tua (kecuali saat mode CLI test)
    if (empty($req['skip_callback_notify'])) {
        wa_notify_perizinan_status($pdo, $izinId, $statusFinal, $guruName);
    }

    return [
        'http'    => 200,
        'success' => true,
        'message' => "Pengajuan izin #{$izinId} berhasil {$statusFinal}.",
        'data'    => [
            'izin_id'          => $izinId,
            'student_name'     => (string)$izin['student_name'],
            'status_perizinan' => $statusFinal,
            'approved_by'      => $guruName,
        ],
    ];
}

function ilmuna_wa_action_record_group_violation(PDO $pdo, array $req)
{
    $siswaId   = (int)($req['siswa_id'] ?? 0);
    $groupName = trim((string)($req['group_name'] ?? 'Grup WA Kelas'));
    $vType     = trim((string)($req['violation_type'] ?? 'badword'));
    $desc      = trim((string)($req['description'] ?? 'Pelanggaran etika di Grup WhatsApp'));
    $poin      = max(0, (int)($req['poin'] ?? 10));

    if ($siswaId <= 0) {
        return ['http' => 422, 'success' => false, 'message' => 'siswa_id wajib diisi.', 'data' => []];
    }

    $st = $pdo->prepare('SELECT id, id_kelas, nama_lengkap FROM siswa WHERE id = :id LIMIT 1');
    $st->execute([':id' => $siswaId]);
    $siswa = $st->fetch(PDO::FETCH_ASSOC);
    if (!$siswa) {
        return ['http' => 404, 'success' => false, 'message' => 'Siswa tidak ditemukan.', 'data' => []];
    }

    $tingkat = $poin >= 25 ? 'Berat' : ($poin >= 15 ? 'Sedang' : 'Ringan');
    $kategori = 'Group Guard WA: ' . ucfirst(str_replace('_', ' ', $vType));
    $semester = ((int)date('n') >= 7) ? 'Ganjil' : 'Genap';

    $ins = $pdo->prepare(
        "INSERT INTO catatan_pelanggaran_siswa
            (id_siswa, id_kelas, recorded_by_role, lokasi_kejadian, tanggal_kejadian, waktu_kejadian,
             kategori_pelanggaran, tingkat_pelanggaran, poin, kronologi, bukti_keterangan,
             status_penanganan, perlu_panggilan_ortu, semester)
         VALUES
            (:sid, :kid, 'admin', 'Grup WA', CURDATE(), CURTIME(),
             :kat, :tkt, :poin, :kro, :bukti,
             'Baru', :panggil, :sem)"
    );
    $ins->execute([
        ':sid'     => $siswaId,
        ':kid'     => (int)$siswa['id_kelas'],
        ':kat'     => mb_substr($kategori, 0, 120, 'UTF-8'),
        ':tkt'     => $tingkat,
        ':poin'    => $poin,
        ':kro'     => $desc,
        ':bukti'   => mb_substr('Auto-sync dari ' . $groupName, 0, 255, 'UTF-8'),
        ':panggil' => $poin >= 25 ? 1 : 0,
        ':sem'     => $semester,
    ]);

    return [
        'http'    => 200,
        'success' => true,
        'message' => 'Pelanggaran grup WA berhasil dicatat ke BK Ilmuna Learn.',
        'data'    => ['bk_id' => (int)$pdo->lastInsertId(), 'tingkat' => $tingkat, 'poin' => $poin],
    ];
}

function ilmuna_wa_action_audience_list(PDO $pdo, array $req)
{
    $scope    = strtolower(trim((string)($req['scope'] ?? '')));
    $filter   = is_array($req['filter'] ?? null) ? $req['filter'] : [];
    $kelasIds = array_values(array_filter(array_map('intval', (array)($filter['kelas_ids'] ?? []))));
    $list     = [];

    if ($scope === 'all_siswa' || $scope === 'siswa_kelas') {
        $sql = 'SELECT s.nama_lengkap AS nama, s.no_hp, COALESCE(k.nama_kelas, "-") AS kelas
                FROM siswa s LEFT JOIN kelas k ON k.id = s.id_kelas WHERE s.no_hp IS NOT NULL AND s.no_hp <> ""';
        $params = [];
        if ($scope === 'siswa_kelas' && !empty($kelasIds)) {
            $ph = implode(',', array_fill(0, count($kelasIds), '?'));
            $sql .= " AND s.id_kelas IN ({$ph})";
            $params = $kelasIds;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = ilmuna_wa_normalize_phone($r['no_hp']);
            if ($p !== '' && !isset($list[$p])) {
                $list[$p] = ['phone' => $p, 'nama' => (string)$r['nama'], 'kelas' => (string)$r['kelas'], 'nama_anak' => ''];
            }
        }
    } elseif ($scope === 'all_ortu' || $scope === 'ortu_kelas') {
        $sql = "SELECT ot.nama_ayah, ot.no_hp_ayah, ot.nama_ibu, ot.no_hp_ibu, ot.nama_wali, ot.no_hp_wali,
                       s.nama_lengkap AS nama_anak, COALESCE(k.nama_kelas, '-') AS kelas
                FROM orang_tua ot
                JOIN orang_tua_siswa ots ON ots.id_orang_tua = ot.id
                JOIN siswa s ON s.id = ots.id_siswa
                LEFT JOIN kelas k ON k.id = s.id_kelas
                WHERE ot.status = 'aktif'";
        $params = [];
        if ($scope === 'ortu_kelas' && !empty($kelasIds)) {
            $ph = implode(',', array_fill(0, count($kelasIds), '?'));
            $sql .= " AND s.id_kelas IN ({$ph})";
            $params = $kelasIds;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pairs = [
                [$r['no_hp_ayah'] ?? '', $r['nama_ayah'] ?: 'Ayah ' . $r['nama_anak']],
                [$r['no_hp_ibu'] ?? '',  $r['nama_ibu']  ?: 'Ibu ' . $r['nama_anak']],
                [$r['no_hp_wali'] ?? '', $r['nama_wali'] ?: 'Wali ' . $r['nama_anak']],
            ];
            foreach ($pairs as $pair) {
                $p = ilmuna_wa_normalize_phone($pair[0]);
                if ($p !== '' && !isset($list[$p])) {
                    $list[$p] = [
                        'phone'     => $p,
                        'nama'      => (string)$pair[1],
                        'kelas'     => (string)$r['kelas'],
                        'nama_anak' => (string)$r['nama_anak'],
                    ];
                }
            }
        }
    } elseif ($scope === 'all_guru') {
        $st = $pdo->query('SELECT nama_guru AS nama, no_hp FROM guru WHERE no_hp IS NOT NULL AND no_hp <> ""');
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $p = ilmuna_wa_normalize_phone($r['no_hp']);
            if ($p !== '' && !isset($list[$p])) {
                $list[$p] = ['phone' => $p, 'nama' => (string)$r['nama'], 'kelas' => 'Dewan Guru', 'nama_anak' => ''];
            }
        }
    }

    return [
        'http'    => 200,
        'success' => true,
        'message' => 'Daftar penerima broadcast berhasil dimuat.',
        'data'    => ['recipients' => array_values($list), 'total' => count($list)],
    ];
}

function ilmuna_wa_action_public_forms(PDO $pdo)
{
    $basePublic = 'https://' . (defined('APP_DOMAIN') ? APP_DOMAIN : 'learn.ilmuna.my.id');
    $forms = [
        'form_siswa_url' => $basePublic . '/form-siswa',
        'form_guru_url'  => $basePublic . '/form-guru',
    ];

    $rows = $pdo->query("SELECT form_type, token_hash FROM public_form_links WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        if ($r['form_type'] === 'siswa_ortu' && !empty($r['token_hash'])) {
            $forms['form_siswa_url'] = $basePublic . '/form-siswa?h=' . urlencode((string)$r['token_hash']);
        } elseif ($r['form_type'] === 'guru' && !empty($r['token_hash'])) {
            $forms['form_guru_url'] = $basePublic . '/form-guru?h=' . urlencode((string)$r['token_hash']);
        }
    }

    return [
        'http'    => 200,
        'success' => true,
        'message' => 'Tautan hash form publik aktif berhasil dimuat.',
        'data'    => $forms,
    ];
}

function ilmuna_wa_action_teacher_overview(PDO $pdo, array $req)
{
    $section = strtolower(trim((string)($req['section'] ?? 'absensi_hari_ini')));

    if ($section === 'pending_izin') {
        $rows = $pdo->query(
            "SELECT p.id, p.tanggal_izin, p.jenis_izin, p.alasan, p.latitude, p.longitude,
                    s.nama_lengkap AS student_name, COALESCE(k.nama_kelas, '-') AS kelas
             FROM perizinan p
             JOIN siswa s ON s.id = p.id_siswa
             LEFT JOIN kelas k ON k.id = s.id_kelas
             WHERE p.approval_guru = 'Pending'
             ORDER BY p.id DESC LIMIT 10"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['gps_link'] = (!empty($r['latitude']) && !empty($r['longitude']))
                ? ('https://maps.google.com/?q=' . $r['latitude'] . ',' . $r['longitude']) : '';
        }

        return [
            'http' => 200, 'success' => true, 'message' => 'Daftar perizinan pending dimuat.',
            'data' => ['pending_izin' => $rows],
        ];
    }

    $counts = $pdo->query(
        'SELECT status, COUNT(*) FROM absensi WHERE DATE(waktu_absen) = CURDATE() GROUP BY status'
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $alpaRows = $pdo->query(
        "SELECT s.nama_lengkap AS nama, COALESCE(k.nama_kelas, '-') AS kelas
         FROM absensi a
         JOIN siswa s ON s.id = a.id_siswa
         LEFT JOIN kelas k ON k.id = s.id_kelas
         WHERE DATE(a.waktu_absen) = CURDATE() AND a.status = 'A'
         LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);

    return [
        'http' => 200, 'success' => true, 'message' => 'Rekap absensi hari ini dimuat.',
        'data' => [
            'hadir'     => (int)($counts['H'] ?? 0) + (int)($counts['T'] ?? 0),
            'sakit'     => (int)($counts['S'] ?? 0),
            'izin'      => (int)($counts['I'] ?? 0),
            'alpa'      => (int)($counts['A'] ?? 0),
            'alpa_list' => $alpaRows,
        ],
    ];
}

function ilmuna_wa_action_student_parents(PDO $pdo, array $req)
{
    $siswaId = (int)($req['siswa_id'] ?? 0);
    if ($siswaId <= 0) {
        return ['http' => 422, 'success' => false, 'message' => 'siswa_id wajib diisi.', 'data' => []];
    }
    $phones = ilmuna_wa_get_parent_phones($pdo, $siswaId);
    return [
        'http'    => 200,
        'success' => true,
        'message' => 'Nomor WA orang tua berhasil diambil.',
        'data'    => ['phones' => $phones],
    ];
}

// ==============================================================================
// HTTP ENTRYPOINT (hanya dieksekusi jika dipanggil langsung sebagai endpoint web)
// ==============================================================================
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Gunakan metode POST.', 'data' => new stdClass()]);
        exit;
    }

    $rawBody = (string)file_get_contents('php://input');
    $headers = function_exists('getallheaders') ? (array)getallheaders() : [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $hName = strtolower(str_replace('_', '-', substr($k, 5)));
            if (!isset($headers[$hName])) {
                $headers[$hName] = (string)$v;
            }
        }
    }

    if (!ilmuna_wa_bridge_verify_request($headers, $rawBody)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Signature HMAC-SHA256 tidak valid atau kedaluwarsa.', 'data' => new stdClass()]);
        exit;
    }

    $req = json_decode($rawBody, true);
    if (!is_array($req)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Body JSON tidak valid.', 'data' => new stdClass()]);
        exit;
    }

    try {
        $pdo = getDBConnection();
        $res = ilmuna_wa_bridge_dispatch($pdo, $req);
        http_response_code((int)($res['http'] ?? 200));
        echo json_encode([
            'success' => (bool)$res['success'],
            'message' => (string)$res['message'],
            'data'    => $res['data'] ?? new stdClass(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Galat internal wa-bridge: ' . $e->getMessage(), 'data' => new stdClass()]);
    }
    exit;
}
