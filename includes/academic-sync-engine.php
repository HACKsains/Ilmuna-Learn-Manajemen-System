<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — CENTRAL ACADEMIC SCHEDULE & KBM SYNC ENGINE
 * ==============================================================================
 * File    : includes/academic-sync-engine.php
 * Fungsi  : Menyelaraskan secara terpusat & otomatis (Single Source of Truth):
 *   1. Master Slot Jam Pelajaran (`jam_pelajaran_slot`) & Jadwal Mingguan Admin (`jadwal_mingguan`)
 *   2. Kalender Pendidikan (`kalender_pendidikan`) & Hari Efektif (`hari_efektif`)
 *      -> Hari Libur / Non-KBM di Kaldik otomatis menjadi LIBUR di jadwal Guru
 *   3. Alokasi Hari Mengajar (`alokasi_hari_mengajar`) -> sinkron ke PROTA & PROSEM
 *   4. Jadwal Pertemuan Bertanggal (`jadwal_pelajaran`) -> sinkron ke Modul Ajar,
 *      Presensi KBM (`absensi`), Tugas (`tugas`), dan Proyek (`proyek`)
 *   5. 100% kompatibel PHP 7.4+ & PDO Prepared Statements
 * ==============================================================================
 */

/**
 * 0. Smart Central Guru Profile Resolver (Anti-Orphan id_user di Hosting)
 *    Mencocokkan akun `users` yang sedang login ke profil `guru` melalui:
 *    1) `guru.id_user = users.id`
 *    2) `guru.email` == `users.email` / `users.email_google`
 *    3) `guru.no_hp` == `users.phone` (normalisasi 08... / 628...)
 *    4) `guru.nip` == `users.nisn_nip`
 *    5) `guru.nama_guru` == `users.full_name` (normalisasi huruf kecil & hapus teks dalam kurung/gelar)
 *    Memprioritaskan profil `guru` yang memiliki relasi `kelas_mata_pelajaran` aktif,
 *    serta otomatis menyembuhkan `guru.id_user` yang masih NULL.
 */
if (!function_exists('ilmuna_norm_phone_id')) {
    function ilmuna_norm_phone_id($raw) {
        $d = preg_replace('/\D+/', '', (string)$raw);
        if (strlen($d) < 9) return '';
        if (substr($d, 0, 2) === '62') $d = '0' . substr($d, 2);
        return $d;
    }
}

if (!function_exists('ilmuna_norm_person_name')) {
    function ilmuna_norm_person_name($raw) {
        $s = (string)$raw;
        $s = preg_replace('/\(.*?\)/', ' ', $s);
        $s = preg_replace('/,.*$/', '', $s);
        $s = preg_replace('/\b(ustadzah|ustadz|ustz|ust|kh|dr|drs|dra|prof|ir|hj|h)\b\.?/i', ' ', $s);
        $s = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $s)));
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    }
}

if (!function_exists('ilmuna_resolve_logged_guru')) {
    function ilmuna_resolve_logged_guru($pdo, $currentUser) {
        $empty = ['guru' => null, 'id_guru' => 0, 'guru_ids' => []];
        if (empty($currentUser) || empty($currentUser['id'])) return $empty;

        $userId = (int)$currentUser['id'];
        $uRow = null;
        try {
            $stU = $pdo->prepare("SELECT id, username, email, email_google, full_name, phone, nisn_nip FROM users WHERE id = ? LIMIT 1");
            $stU->execute([$userId]);
            $uRow = $stU->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $eU) {}

        $uEmails = [];
        foreach ([$uRow['email'] ?? '', $uRow['email_google'] ?? '', $currentUser['email'] ?? ''] as $em) {
            $emNorm = strtolower(trim((string)$em));
            if ($emNorm !== '' && strpos($emNorm, '@') !== false && !in_array($emNorm, $uEmails, true)) {
                $uEmails[] = $emNorm;
            }
        }
        $uPhone = ilmuna_norm_phone_id($uRow['phone'] ?? '');
        $uNip   = trim((string)($uRow['nisn_nip'] ?? ''));
        if ($uNip === '-' || strlen($uNip) < 5) $uNip = '';
        $uNameRaw  = strtolower(trim((string)($uRow['full_name'] ?? ($currentUser['full_name'] ?? ''))));
        $uNameNorm = ilmuna_norm_person_name($uRow['full_name'] ?? ($currentUser['full_name'] ?? ''));
        $uUserNorm = strtolower(trim((string)($uRow['username'] ?? ($currentUser['username'] ?? ''))));

        try {
            $allGuru = $pdo->query("
                SELECT g.*,
                       (SELECT COUNT(*) FROM kelas_mata_pelajaran kmp JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id WHERE gmp.id_guru = g.id) AS kmp_count,
                       (SELECT COUNT(*) FROM guru_mata_pelajaran gmp2 WHERE gmp2.id_guru = g.id) AS gmp_count
                FROM guru g
                ORDER BY g.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $eG) {
            return $empty;
        }

        $candidates = [];
        foreach ($allGuru as $g) {
            $score = 0;
            if ((int)($g['id_user'] ?? 0) === $userId) {
                $score = max($score, 100);
            }
            $gEmail = strtolower(trim((string)($g['email'] ?? '')));
            if ($gEmail !== '' && in_array($gEmail, $uEmails, true)) {
                $score = max($score, 90);
            }
            $gPhone = ilmuna_norm_phone_id($g['no_hp'] ?? '');
            if ($uPhone !== '' && $gPhone !== '' && $gPhone === $uPhone) {
                $score = max($score, 85);
            }
            $gNip = trim((string)($g['nip'] ?? ''));
            if ($uNip !== '' && $gNip !== '' && $gNip !== '-' && $gNip === $uNip) {
                $score = max($score, 80);
            }
            $gNameRaw  = strtolower(trim((string)($g['nama_guru'] ?? '')));
            $gNameNorm = ilmuna_norm_person_name($g['nama_guru'] ?? '');
            if ($gNameRaw !== '' && $uNameRaw !== '' && $gNameRaw === $uNameRaw) {
                $score = max($score, 78);
            } elseif ($gNameNorm !== '' && $uNameNorm !== '' && strlen($gNameNorm) >= 4 && $gNameNorm === $uNameNorm) {
                $score = max($score, 72);
            } elseif ($gNameNorm !== '' && $uUserNorm !== '' && strlen($uUserNorm) >= 4 && str_replace(' ', '', $gNameNorm) === str_replace([' ', '_', '.'], '', $uUserNorm)) {
                $score = max($score, 68);
            }

            if ($score > 0) {
                $g['_match_score'] = $score;
                $g['_kmp_count']   = (int)$g['kmp_count'];
                $g['_gmp_count']   = (int)$g['gmp_count'];
                $candidates[] = $g;
            }
        }

        if (empty($candidates)) {
            return $empty;
        }

        // Prioritaskan kandidat yang benar-benar memiliki KMP aktif, lalu GMP aktif, lalu skor kecocokan tertinggi
        usort($candidates, function ($a, $b) {
            $aHasKmp = $a['_kmp_count'] > 0 ? 1 : 0;
            $bHasKmp = $b['_kmp_count'] > 0 ? 1 : 0;
            if ($aHasKmp !== $bHasKmp) return $bHasKmp <=> $aHasKmp;
            $aHasGmp = $a['_gmp_count'] > 0 ? 1 : 0;
            $bHasGmp = $b['_gmp_count'] > 0 ? 1 : 0;
            if ($aHasGmp !== $bHasGmp) return $bHasGmp <=> $aHasGmp;
            if ($a['_match_score'] !== $b['_match_score']) return $b['_match_score'] <=> $a['_match_score'];
            return ((int)$a['id']) <=> ((int)$b['id']);
        });

        $primary = $candidates[0];
        $guruIds = [];
        foreach ($candidates as $c) {
            $guruIds[] = (int)$c['id'];
        }

        // Auto-heal: bila profil guru utama belum punya id_user (NULL), tautkan permanen ke user ini
        if (empty($primary['id_user']) && $userId > 0) {
            try {
                $pdo->prepare("UPDATE guru SET id_user = ? WHERE id = ? AND id_user IS NULL")
                    ->execute([$userId, (int)$primary['id']]);
                $primary['id_user'] = $userId;
            } catch (Exception $eHeal) {}
        }

        return [
            'guru'     => $primary,
            'id_guru'  => (int)$primary['id'],
            'guru_ids' => $guruIds,
        ];
    }
}

if (!function_exists('ilmuna_acad_indo_day')) {
    function ilmuna_acad_indo_day($ymd) {
        $map = [
            'Sunday'    => 'Minggu',
            'Monday'    => 'Senin',
            'Tuesday'   => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday'  => 'Kamis',
            'Friday'    => 'Jumat',
            'Saturday'  => 'Sabtu',
        ];
        $ts = strtotime((string)$ymd);
        return $ts ? ($map[date('l', $ts)] ?? 'Senin') : 'Senin';
    }
}

if (!function_exists('ilmuna_acad_active_term')) {
    function ilmuna_acad_active_term($pdo) {
        static $cached = null;
        if ($cached !== null) return $cached;
        $tahun = '2026/2027';
        $semester = 'Ganjil';
        try {
            $row = $pdo->query("SELECT tahun_pelajaran_aktif, semester_aktif FROM identitas_sekolah LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!empty($row['tahun_pelajaran_aktif'])) $tahun = trim((string)$row['tahun_pelajaran_aktif']);
                if (!empty($row['semester_aktif']) && in_array($row['semester_aktif'], ['Ganjil', 'Genap'], true)) {
                    $semester = $row['semester_aktif'];
                }
            }
        } catch (Exception $e) {}
        $cached = ['tahun' => $tahun, 'semester' => $semester];
        return $cached;
    }
}

/**
 * 1. Idempotent Schema Migration (PHP 7.4 safe, non-destructive)
 */
if (!function_exists('ilmuna_ensure_academic_sync_schema')) {
    function ilmuna_ensure_academic_sync_schema($pdo) {
        static $done = false;
        if ($done) return;
        $done = true;

        try {
            // A. Master Slot Jam Pelajaran (Bel Sekolah)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `jam_pelajaran_slot` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `jam_ke` INT NOT NULL,
                    `label` VARCHAR(50) NOT NULL,
                    `jam_mulai` TIME NOT NULL,
                    `jam_selesai` TIME NOT NULL,
                    `is_istirahat` TINYINT(1) NOT NULL DEFAULT 0,
                    `urutan` INT NOT NULL DEFAULT 1,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_jam_ke_urutan` (`urutan`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            $cntSlot = (int)$pdo->query("SELECT COUNT(*) FROM jam_pelajaran_slot")->fetchColumn();
            if ($cntSlot === 0) {
                $defaults = [
                    [1,  'Jam ke-1',    '07:00:00', '07:45:00', 0, 1],
                    [2,  'Jam ke-2',    '07:45:00', '08:30:00', 0, 2],
                    [3,  'Jam ke-3',    '08:30:00', '09:15:00', 0, 3],
                    [4,  'Jam ke-4',    '09:15:00', '10:00:00', 0, 4],
                    [0,  'Istirahat 1', '10:00:00', '10:15:00', 1, 5],
                    [5,  'Jam ke-5',    '10:15:00', '11:00:00', 0, 6],
                    [6,  'Jam ke-6',    '11:00:00', '11:45:00', 0, 7],
                    [0,  'Istirahat 2 / Dzuhur', '11:45:00', '12:30:00', 1, 8],
                    [7,  'Jam ke-7',    '12:30:00', '13:15:00', 0, 9],
                    [8,  'Jam ke-8',    '13:15:00', '14:00:00', 0, 10],
                    [9,  'Jam ke-9',    '14:00:00', '14:45:00', 0, 11],
                    [10, 'Jam ke-10',   '14:45:00', '15:30:00', 0, 12],
                ];
                $ins = $pdo->prepare("INSERT INTO jam_pelajaran_slot (jam_ke, label, jam_mulai, jam_selesai, is_istirahat, urutan) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($defaults as $d) $ins->execute($d);
            }

            // B. Master Jadwal Mingguan Sekolah (Admin Drag & Drop Grid)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `jadwal_mingguan` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `tahun_pelajaran` VARCHAR(30) NOT NULL DEFAULT '2026/2027',
                    `semester` ENUM('Ganjil','Genap') NOT NULL DEFAULT 'Ganjil',
                    `id_kelas_mata_pelajaran` INT UNSIGNED NOT NULL,
                    `id_guru` INT UNSIGNED DEFAULT NULL,
                    `hari` ENUM('Senin','Selasa','Rabu','Kamis','Jumat','Sabtu') NOT NULL DEFAULT 'Senin',
                    `jam_ke_mulai` INT NOT NULL DEFAULT 1,
                    `durasi_jp` INT NOT NULL DEFAULT 2,
                    `jam_mulai` TIME NOT NULL DEFAULT '07:00:00',
                    `jam_selesai` TIME NOT NULL DEFAULT '08:30:00',
                    `ruangan` VARCHAR(100) DEFAULT 'Ruang Kelas',
                    `warna_kartu` VARCHAR(25) DEFAULT 'teal',
                    `catatan` VARCHAR(255) DEFAULT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_jm_term_hari` (`tahun_pelajaran`, `semester`, `hari`, `jam_ke_mulai`),
                    KEY `idx_jm_kmp` (`id_kelas_mata_pelajaran`),
                    KEY `idx_jm_guru` (`id_guru`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");

            // C. Kolom tautan id_jadwal_mingguan pada jadwal_pelajaran
            $jpCols = $pdo->query("SHOW COLUMNS FROM jadwal_pelajaran")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('id_jadwal_mingguan', $jpCols, true)) {
                try {
                    $pdo->exec("ALTER TABLE `jadwal_pelajaran` ADD COLUMN `id_jadwal_mingguan` INT UNSIGNED DEFAULT NULL AFTER `id_kelas_mata_pelajaran`");
                } catch (Exception $eCol) {}
            }

            // D. Kolom tambahan opsional pada proyek (agar sinkron 100% dengan pertemuan & lampiran)
            $prCols = $pdo->query("SHOW COLUMNS FROM proyek")->fetchAll(PDO::FETCH_COLUMN);
            $prAdds = [
                'pertemuan_ke'   => "INT DEFAULT NULL AFTER `id_modul_ajar`",
                'id_jadwal'      => "INT UNSIGNED DEFAULT NULL AFTER `pertemuan_ke`",
                'file_panduan'   => "VARCHAR(255) DEFAULT NULL AFTER `pedoman_penilaian_rubrik`",
                'link_referensi' => "VARCHAR(255) DEFAULT NULL AFTER `file_panduan`",
            ];
            foreach ($prAdds as $col => $def) {
                if (!in_array($col, $prCols, true)) {
                    try { $pdo->exec("ALTER TABLE `proyek` ADD COLUMN `{$col}` {$def}"); } catch (Exception $eP) {}
                }
            }

            // E. Kolom tambahan pengumpulan_proyek agar 100% setara pengumpulan_tugas
            $ppCols = $pdo->query("SHOW COLUMNS FROM pengumpulan_proyek")->fetchAll(PDO::FETCH_COLUMN);
            $ppAdds = [
                'tipe_pengumpulan' => "ENUM('File Upload','Link URL','File dan Link','Teks / Essay') NOT NULL DEFAULT 'File Upload' AFTER `id_proyek_kelompok`",
                'file_nama_asli'   => "VARCHAR(255) DEFAULT NULL AFTER `file_laporan`",
                'file_ukuran_kb'   => "INT DEFAULT NULL AFTER `file_nama_asli`",
                'catatan_siswa'    => "TEXT DEFAULT NULL AFTER `link_video_presentasi`",
                'is_terlambat'     => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `status_pengumpulan`",
                'revisi_ke'        => "INT NOT NULL DEFAULT 1 AFTER `is_terlambat`",
            ];
            foreach ($ppAdds as $col => $def) {
                if (!in_array($col, $ppCols, true)) {
                    try { $pdo->exec("ALTER TABLE `pengumpulan_proyek` ADD COLUMN `{$col}` {$def}"); } catch (Exception $ePp) {}
                }
            }

            // F. Tabel nilai_proyek per-siswa (100% setara nilai_tugas: Nilai Kelompok + Nilai Individu / 2)
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `nilai_proyek` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `id_proyek` INT UNSIGNED NOT NULL,
                    `id_pengumpulan_proyek` INT UNSIGNED NOT NULL,
                    `id_siswa` INT UNSIGNED NOT NULL,
                    `id_kelas_mata_pelajaran` INT UNSIGNED NOT NULL,
                    `id_guru` INT DEFAULT NULL,
                    `nilai` DECIMAL(5,2) NOT NULL DEFAULT '0.00',
                    `nilai_kelompok` DECIMAL(5,2) DEFAULT NULL,
                    `nilai_bagi_kelompok` DECIMAL(5,2) DEFAULT NULL,
                    `nilai_individu` DECIMAL(5,2) DEFAULT NULL,
                    `jumlah_anggota_kelompok` INT DEFAULT NULL,
                    `nilai_remedial` DECIMAL(5,2) DEFAULT NULL,
                    `nilai_akhir_remedial` DECIMAL(5,2) DEFAULT NULL,
                    `predikat` ENUM('A','B','C','D','E') DEFAULT NULL,
                    `predikat_p5` ENUM('Sangat Berkembang (SB)','Berkembang Sesuai Harapan (BSH)','Mulai Berkembang (MB)','Belum Berkembang (BB)') DEFAULT NULL,
                    `aspek_itp_ketercapaian` ENUM('Tercapai Sangat Baik','Tercapai','Cukup Tercapai','Perlu Bimbingan') NOT NULL DEFAULT 'Tercapai',
                    `catatan_guru` TEXT DEFAULT NULL,
                    `status_penilaian` ENUM('Dinilai','Perlu Revisi','Tuntas') NOT NULL DEFAULT 'Dinilai',
                    `waktu_penilaian` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_np_proyek_siswa` (`id_proyek`, `id_siswa`),
                    KEY `idx_np_pengumpulan` (`id_pengumpulan_proyek`),
                    KEY `idx_np_kmp` (`id_kelas_mata_pelajaran`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Exception $e) {
            error_log('ilmuna_ensure_academic_sync_schema: ' . $e->getMessage());
        }
    }
}

/**
 * 2. Ambil Daftar Jam Pelajaran (Bel Sekolah) & Hitung Waktu Otomatis
 */
if (!function_exists('ilmuna_get_bell_slots')) {
    function ilmuna_get_bell_slots($pdo) {
        ilmuna_ensure_academic_sync_schema($pdo);
        $rows = $pdo->query("SELECT * FROM jam_pelajaran_slot ORDER BY urutan ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['jam_ke'] = (int)$r['jam_ke'];
            $r['is_istirahat'] = (int)$r['is_istirahat'];
            $r['urutan'] = (int)$r['urutan'];
            $r['jam_mulai_hm'] = substr((string)$r['jam_mulai'], 0, 5);
            $r['jam_selesai_hm'] = substr((string)$r['jam_selesai'], 0, 5);
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('ilmuna_calc_slot_times')) {
    function ilmuna_calc_slot_times($bellSlots, $jamKeMulai, $durasiJp) {
        $startKe = max(1, (int)$jamKeMulai);
        $dur = max(1, min(10, (int)$durasiJp));
        $endKe = $startKe + $dur - 1;

        $kbmMap = [];
        foreach ($bellSlots as $s) {
            if ((int)$s['is_istirahat'] === 0 && (int)$s['jam_ke'] > 0) {
                $kbmMap[(int)$s['jam_ke']] = $s;
            }
        }

        if (isset($kbmMap[$startKe])) {
            $jamMulai = $kbmMap[$startKe]['jam_mulai'];
        } else {
            $tsStart = strtotime('07:00:00') + (($startKe - 1) * 45 * 60);
            $jamMulai = date('H:i:s', $tsStart);
        }

        if (isset($kbmMap[$endKe])) {
            $jamSelesai = $kbmMap[$endKe]['jam_selesai'];
        } else {
            $tsEnd = strtotime($jamMulai) + ($dur * 45 * 60);
            $jamSelesai = date('H:i:s', $tsEnd);
        }

        $jamKeLabel = ($dur === 1) ? (string)$startKe : ($startKe . '-' . $endKe);
        return [
            'jam_ke_mulai' => $startKe,
            'jam_ke_akhir' => $endKe,
            'jam_ke_label' => $jamKeLabel,
            'jam_mulai'    => $jamMulai,
            'jam_selesai'  => $jamSelesai,
        ];
    }
}

/**
 * 3. Deteksi Bentrok Jadwal Mingguan (Anti-Bentrok Guru, Kelas, Ruangan Khusus)
 */
if (!function_exists('ilmuna_check_weekly_conflict')) {
    function ilmuna_check_weekly_conflict($pdo, $tahun, $semester, $hari, $jamKeMulai, $durasiJp, $idKelas, $idGuru, $ruangan = '', $excludeId = 0) {
        $newStart = max(1, (int)$jamKeMulai);
        $newEnd   = $newStart + max(1, (int)$durasiJp) - 1;

        $stmt = $pdo->prepare("
            SELECT jm.id, jm.id_kelas_mata_pelajaran, jm.id_guru, jm.jam_ke_mulai, jm.durasi_jp, jm.ruangan,
                   kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran,
                   COALESCE(g.nama_guru, g2.nama_guru, 'Guru') AS nama_guru
            FROM jadwal_mingguan jm
            JOIN kelas_mata_pelajaran kmp ON jm.id_kelas_mata_pelajaran = kmp.id
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru g ON jm.id_guru = g.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN guru g2 ON gmp.id_guru = g2.id
            WHERE jm.tahun_pelajaran = ? AND jm.semester = ? AND jm.hari = ? AND jm.id != ?
        ");
        $stmt->execute([$tahun, $semester, $hari, (int)$excludeId]);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ruangNorm = strtolower(trim((string)$ruangan));
        $genericRooms = ['', 'ruang kelas', 'kelas', 'ruang kbm', '-'];

        foreach ($existing as $ex) {
            $exStart = (int)$ex['jam_ke_mulai'];
            $exEnd   = $exStart + max(1, (int)$ex['durasi_jp']) - 1;
            $overlap = !($newEnd < $exStart || $newStart > $exEnd);
            if (!$overlap) continue;

            if ($idKelas > 0 && (int)$ex['id_kelas'] === (int)$idKelas) {
                return "Bentrok Kelas: {$ex['nama_kelas']} sudah dijadwalkan {$ex['nama_mata_pelajaran']} pada hari {$hari} Jam ke-{$exStart}" . ($exEnd > $exStart ? "-{$exEnd}" : "") . ".";
            }
            if ($idGuru > 0 && (int)$ex['id_guru'] === (int)$idGuru) {
                return "Bentrok Guru: {$ex['nama_guru']} sedang mengajar {$ex['nama_mata_pelajaran']} di {$ex['nama_kelas']} pada hari {$hari} Jam ke-{$exStart}" . ($exEnd > $exStart ? "-{$exEnd}" : "") . ".";
            }
            $exRuangNorm = strtolower(trim((string)($ex['ruangan'] ?? '')));
            if ($ruangNorm !== '' && !in_array($ruangNorm, $genericRooms, true) && $ruangNorm === $exRuangNorm) {
                return "Bentrok Ruangan: {$ex['ruangan']} sedang dipakai {$ex['nama_kelas']} ({$ex['nama_mata_pelajaran']}) pada hari {$hari} Jam ke-{$exStart}" . ($exEnd > $exStart ? "-{$exEnd}" : "") . ".";
            }
        }
        return null;
    }
}

/**
 * 4. Ambil Daftar Tanggal Efektif KBM Semester dari Kalender Pendidikan (Skip Hari Libur!)
 */
if (!function_exists('ilmuna_get_semester_effective_dates')) {
    function ilmuna_get_semester_effective_dates($pdo, $tahun, $semester) {
        $stmt = $pdo->prepare("
            SELECT tanggal, hari, status_hari, keterangan
            FROM kalender_pendidikan
            WHERE tahun_pelajaran = ? AND semester = ?
            ORDER BY tanggal ASC
        ");
        $stmt->execute([$tahun, $semester]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fallback jika tahun belum terisi di kalender_pendidikan: generate tanggal semester tanpa Minggu
        if (empty($rows)) {
            $parts = explode('/', (string)$tahun);
            $y1 = (int)($parts[0] ?? date('Y'));
            $y2 = (int)($parts[1] ?? ($y1 + 1));
            $start = ($semester === 'Ganjil') ? strtotime(sprintf('%04d-07-14', $y1)) : strtotime(sprintf('%04d-01-05', $y2));
            $end   = ($semester === 'Ganjil') ? strtotime(sprintf('%04d-12-19', $y1)) : strtotime(sprintf('%04d-06-20', $y2));
            $rows = [];
            for ($cur = $start; $cur <= $end; $cur = strtotime('+1 day', $cur)) {
                $ymd = date('Y-m-d', $cur);
                $hari = ilmuna_acad_indo_day($ymd);
                $rows[] = [
                    'tanggal'     => $ymd,
                    'hari'        => $hari,
                    'status_hari' => ($hari === 'Minggu') ? 'Libur Semester' : 'Efektif KBM',
                    'keterangan'  => ($hari === 'Minggu') ? 'Libur Akhir Pekan (Minggu)' : 'KBM Reguler',
                ];
            }
        }

        $effective = [];
        $nonEffectiveMap = [];
        foreach ($rows as $r) {
            if ($r['status_hari'] === 'Efektif KBM' && $r['hari'] !== 'Minggu') {
                $effective[] = $r;
            } else {
                $nonEffectiveMap[$r['tanggal']] = $r;
            }
        }
        return [
            'effective'     => $effective,
            'non_effective' => $nonEffectiveMap,
        ];
    }
}

/**
 * 5. SINKRONISASI UTAMA: JADWAL MINGGUAN ADMIN -> ALOKASI HARI MENGAJAR + PROTA/PROSEM + JADWAL GURU + MODUL AJAR
 *    - Menghormati 100% Kalender Pendidikan (hari libur Kaldik = LIBUR, dilewati dari sesi KBM)
 *    - Menjaga relasi Modul Ajar, Presensi KBM, dan status pelaksanaan yang sudah ada!
 */
if (!function_exists('ilmuna_sync_kmp_from_weekly')) {
    function ilmuna_sync_kmp_from_weekly($pdo, $idKmp, $tahun, $semester) {
        ilmuna_ensure_academic_sync_schema($pdo);
        $idKmp = (int)$idKmp;
        if ($idKmp <= 0) return ['synced' => 0, 'skipped_holidays' => 0];

        // A. Ambil informasi KMP + Guru Pengampu
        $kmpStmt = $pdo->prepare("
            SELECT kmp.id, kmp.id_kelas, kmp.id_mata_pelajaran,
                   k.nama_kelas, mp.nama_mata_pelajaran,
                   COALESCE(gmp.id_guru, 0) AS guru_id,
                   COALESCE(g.id_user, 0) AS guru_user_id
            FROM kelas_mata_pelajaran kmp
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN guru g ON gmp.id_guru = g.id
            WHERE kmp.id = ?
            LIMIT 1
        ");
        $kmpStmt->execute([$idKmp]);
        $kmpInfo = $kmpStmt->fetch(PDO::FETCH_ASSOC);
        if (!$kmpInfo) return ['synced' => 0, 'skipped_holidays' => 0];

        // B. Ambil semua blok jadwal mingguan milik KMP pada semester ini
        $jmStmt = $pdo->prepare("
            SELECT * FROM jadwal_mingguan
            WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ?
            ORDER BY FIELD(hari,'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'), jam_ke_mulai ASC
        ");
        $jmStmt->execute([$idKmp, $tahun, $semester]);
        $weeklySlots = $jmStmt->fetchAll(PDO::FETCH_ASSOC);

        // Jika tidak ada slot mingguan sama sekali, bersihkan hanya sesi otomatis yang belum terlaksana & belum ada absensi/modul
        if (empty($weeklySlots)) {
            $pdo->prepare("
                DELETE FROM jadwal_pelajaran
                WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ?
                  AND id_jadwal_mingguan IS NOT NULL
                  AND status_pelaksanaan = 'Belum Terlaksana'
                  AND id_modul_ajar IS NULL
                  AND id NOT IN (SELECT DISTINCT id_jadwal FROM absensi WHERE id_jadwal IS NOT NULL)
            ")->execute([$idKmp, $tahun, $semester]);
            return ['synced' => 0, 'skipped_holidays' => 0];
        }

        // C. Ambil Kalender Pendidikan semester ini
        $calData = ilmuna_get_semester_effective_dates($pdo, $tahun, $semester);
        $effectiveDates = $calData['effective'];
        $nonEffectiveMap = $calData['non_effective'];

        // Kelompokkan slot mingguan per hari + petakan guru.id -> users.id (FK aman untuk jadwal_pelajaran & alokasi_hari_mengajar)
        $guruUserMap = [];
        try {
            $gRows = $pdo->query("SELECT id, id_user FROM guru WHERE id_user IS NOT NULL AND id_user > 0")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($gRows as $gr) {
                $guruUserMap[(int)$gr['id']] = (int)$gr['id_user'];
            }
        } catch (Exception $eGm) {}

        $slotsByDay = [];
        $totalJpPerWeek = 0;
        $daySummaryLabels = [];
        $primaryGuruId = (int)$kmpInfo['guru_id'];
        $primaryGuruUserId = (int)$kmpInfo['guru_user_id'];
        foreach ($weeklySlots as $ws) {
            $slotsByDay[$ws['hari']][] = $ws;
            $totalJpPerWeek += max(1, (int)$ws['durasi_jp']);
            $endKe = (int)$ws['jam_ke_mulai'] + max(1, (int)$ws['durasi_jp']) - 1;
            $jLabel = ((int)$ws['durasi_jp'] === 1) ? ('Jam ' . $ws['jam_ke_mulai']) : ('Jam ' . $ws['jam_ke_mulai'] . '-' . $endKe);
            $daySummaryLabels[] = $ws['hari'] . ' (' . $jLabel . ' • ' . substr($ws['jam_mulai'], 0, 5) . '-' . substr($ws['jam_selesai'], 0, 5) . ')';
            if ((int)$ws['id_guru'] > 0 && $primaryGuruId === 0) {
                $primaryGuruId = (int)$ws['id_guru'];
                if ($primaryGuruUserId === 0 && isset($guruUserMap[$primaryGuruId])) {
                    $primaryGuruUserId = $guruUserMap[$primaryGuruId];
                }
            }
        }
        $primaryHari = $weeklySlots[0]['hari'];

        // D. Susun daftar sesi pertemuan efektif KBM sepanjang semester (OTOMATIS LEWATI HARI LIBUR KALDIK!)
        $plannedSessions = [];
        $effectiveDayHits = 0;
        $totalJtmSemester = 0;
        foreach ($effectiveDates as $ed) {
            $dHari = $ed['hari'];
            if (empty($slotsByDay[$dHari])) continue;
            $effectiveDayHits++;
            foreach ($slotsByDay[$dHari] as $ws) {
                $dur = max(1, (int)$ws['durasi_jp']);
                $endKe = (int)$ws['jam_ke_mulai'] + $dur - 1;
                $jamKeStr = ($dur === 1) ? (string)$ws['jam_ke_mulai'] : ($ws['jam_ke_mulai'] . '-' . $endKe);
                $totalJtmSemester += $dur;
                $wsGuruId = (int)($ws['id_guru'] ?: $primaryGuruId);
                $wsUserId = ($wsGuruId > 0 && isset($guruUserMap[$wsGuruId])) ? $guruUserMap[$wsGuruId] : $primaryGuruUserId;
                $plannedSessions[] = [
                    'id_jadwal_mingguan' => (int)$ws['id'],
                    'id_guru_user'       => ($wsUserId > 0 ? $wsUserId : null),
                    'hari'               => $dHari,
                    'tanggal'            => $ed['tanggal'],
                    'jam_ke'             => $jamKeStr,
                    'jam_mulai'          => $ws['jam_mulai'],
                    'jam_selesai'        => $ws['jam_selesai'],
                    'alokasi_jp'         => $dur,
                    'ruangan'            => $ws['ruangan'] ?: ('Ruang ' . $kmpInfo['nama_kelas']),
                ];
            }
        }

        // E. Sinkronkan `alokasi_hari_mengajar` agar PROTA & PROSEM guru langsung mengikuti jadwal Admin!
        $polaRingkas = implode(', ', $daySummaryLabels);
        $ahmGuruVal = ($primaryGuruUserId > 0) ? $primaryGuruUserId : null;
        $chkAhm = $pdo->prepare("SELECT id FROM alokasi_hari_mengajar WHERE id_kelas_mata_pelajaran = ? AND semester = ? ORDER BY id ASC LIMIT 1");
        $chkAhm->execute([$idKmp, $semester]);
        $ahmId = $chkAhm->fetchColumn();
        if ($ahmId) {
            $pdo->prepare("
                UPDATE alokasi_hari_mengajar
                SET hari = ?, banyaknya_hari_efektif = ?, jp_per_minggu = ?, total_jtm = ?, pola_pekan_mengajar = ?, id_guru = COALESCE(?, id_guru)
                WHERE id = ?
            ")->execute([$primaryHari, max(1, $effectiveDayHits), $totalJpPerWeek, max($totalJpPerWeek, $totalJtmSemester), $polaRingkas, $ahmGuruVal, $ahmId]);
        } else {
            $pdo->prepare("
                INSERT INTO alokasi_hari_mengajar (id_kelas_mata_pelajaran, id_guru, semester, hari, banyaknya_hari_efektif, jp_per_minggu, total_jtm, pola_pekan_mengajar)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$idKmp, $ahmGuruVal, $semester, $primaryHari, max(1, $effectiveDayHits), $totalJpPerWeek, max($totalJpPerWeek, $totalJtmSemester), $polaRingkas]);
        }

        // F. Ambil agenda PROSEM (bila guru sudah menyusun PROSEM) untuk memetakan CP, TP & Materi ke tiap pertemuan!
        $prosemQueue = [];
        $psStmt = $pdo->prepare("SELECT id FROM prosem WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ? LIMIT 1");
        $psStmt->execute([$idKmp, $tahun, $semester]);
        $idProsem = $psStmt->fetchColumn();
        if ($idProsem) {
            $pdStmt = $pdo->prepare("
                SELECT psd.id, psd.id_tp, psd.kategori, psd.nama_agenda, psd.alokasi_waktu, psd.id_modul_ajar,
                       tp.id_cp, tp.kode_tp, tp.materi_pokok, tp.tp AS uraian_tp
                FROM prosem_detail psd
                LEFT JOIN tp ON psd.id_tp = tp.id
                WHERE psd.id_prosem = ?
                ORDER BY psd.nomor_urut ASC, psd.id ASC
            ");
            $pdStmt->execute([$idProsem]);
            $pdRows = $pdStmt->fetchAll(PDO::FETCH_ASSOC);

            $avgSlotJp = max(1, (int)round($totalJpPerWeek / max(1, count($weeklySlots))));
            foreach ($pdRows as $pd) {
                $jpAgenda = max(1, (int)$pd['alokasi_waktu']);
                $needMeetings = max(1, (int)ceil($jpAgenda / $avgSlotJp));
                $materiTxt = !empty($pd['nama_agenda'])
                    ? $pd['nama_agenda']
                    : (!empty($pd['materi_pokok']) ? $pd['materi_pokok'] : (!empty($pd['uraian_tp']) ? substr((string)$pd['uraian_tp'], 0, 140) : $kmpInfo['nama_mata_pelajaran']));
                for ($m = 0; $m < $needMeetings; $m++) {
                    $prosemQueue[] = [
                        'id_cp'        => $pd['id_cp'] ?: null,
                        'id_tp'        => $pd['id_tp'] ?: null,
                        'jenis_agenda' => $pd['kategori'] ?: 'Materi KBM',
                        'materi'       => $materiTxt,
                    ];
                }
            }
        }

        // G. Ambil modul_ajar yang sudah ada pada KMP ini (index by pertemuan_ke)
        $maMap = [];
        $maStmt = $pdo->prepare("SELECT id, pertemuan_ke, id_cp, id_tp, judul_modul FROM modul_ajar WHERE id_kelas_mata_pelajaran = ?");
        $maStmt->execute([$idKmp]);
        foreach ($maStmt->fetchAll(PDO::FETCH_ASSOC) as $mr) {
            $maMap[(int)$mr['pertemuan_ke']] = $mr;
        }

        // H. Ambil jadwal_pelajaran eksisting pada KMP & semester ini (index by pertemuan_ke)
        $exStmt = $pdo->prepare("
            SELECT id, pertemuan_ke, tanggal, id_modul_ajar, status_pelaksanaan, catatan_pelaksanaan, id_cp, id_tp, materi_pembahasan, jenis_agenda
            FROM jadwal_pelajaran
            WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ?
            ORDER BY pertemuan_ke ASC, id ASC
        ");
        $exStmt->execute([$idKmp, $tahun, $semester]);
        $existingByPert = [];
        $existingAllIds = [];
        foreach ($exStmt->fetchAll(PDO::FETCH_ASSOC) as $er) {
            $pKey = (int)$er['pertemuan_ke'];
            if (!isset($existingByPert[$pKey])) {
                $existingByPert[$pKey] = $er;
            }
            $existingAllIds[] = (int)$er['id'];
        }

        // I. Upsert setiap sesi efektif berurutan (Pertemuan 1..N) dalam transaksi
        $keptIds = [];
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();

        try {
            $updStmt = $pdo->prepare("
                UPDATE jadwal_pelajaran
                SET id_jadwal_mingguan = ?,
                    id_guru = ?,
                    id_modul_ajar = ?,
                    id_cp = ?,
                    id_tp = ?,
                    hari = ?,
                    tanggal = ?,
                    jam_ke = ?,
                    jam_mulai = ?,
                    jam_selesai = ?,
                    alokasi_jp = ?,
                    ruangan = ?,
                    jenis_agenda = ?,
                    materi_pembahasan = ?
                WHERE id = ?
            ");

            $insStmt = $pdo->prepare("
                INSERT INTO jadwal_pelajaran (
                    id_kelas_mata_pelajaran, id_jadwal_mingguan, id_guru, id_modul_ajar,
                    id_cp, id_tp, pertemuan_ke, tahun_pelajaran, semester,
                    hari, tanggal, jam_ke, jam_mulai, jam_selesai, alokasi_jp,
                    materi_pembahasan, jenis_agenda, ruangan,
                    status_jadwal, status_pelaksanaan
                ) VALUES (
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?,
                    'Terjadwal', 'Belum Terlaksana'
                )
            ");

            $updModulSync = $pdo->prepare("
                UPDATE modul_ajar
                SET id_jadwal_pelajaran = ?, tanggal = ?, jam_mulai = ?, jam_selesai = ?, jumlah_jp = ?, tahun_pelajaran = ?, semester = ?
                WHERE id = ?
            ");

            foreach ($plannedSessions as $idx => $sess) {
                $pertKe = $idx + 1;
                $exRow  = $existingByPert[$pertKe] ?? null;
                $maRow  = $maMap[$pertKe] ?? null;
                $psItem = $prosemQueue[$idx] ?? null;

                $modulId = $maRow ? (int)$maRow['id'] : (!empty($exRow['id_modul_ajar']) ? (int)$exRow['id_modul_ajar'] : null);
                $cpId    = ($maRow && !empty($maRow['id_cp'])) ? (int)$maRow['id_cp'] : ($psItem && $psItem['id_cp'] ? (int)$psItem['id_cp'] : (!empty($exRow['id_cp']) ? (int)$exRow['id_cp'] : null));
                $tpId    = ($maRow && !empty($maRow['id_tp'])) ? (int)$maRow['id_tp'] : ($psItem && $psItem['id_tp'] ? (int)$psItem['id_tp'] : (!empty($exRow['id_tp']) ? (int)$exRow['id_tp'] : null));
                $jenisAg = $psItem ? $psItem['jenis_agenda'] : (!empty($exRow['jenis_agenda']) ? $exRow['jenis_agenda'] : 'Materi KBM');
                $materi  = $maRow
                    ? $maRow['judul_modul']
                    : ($psItem ? $psItem['materi'] : (!empty($exRow['materi_pembahasan']) ? $exRow['materi_pembahasan'] : ("Pertemuan {$pertKe} — " . $kmpInfo['nama_mata_pelajaran'])));

                $saveGuruId = !empty($sess['id_guru_user']) ? (int)$sess['id_guru_user'] : ($primaryGuruUserId > 0 ? $primaryGuruUserId : null);

                if ($exRow) {
                    $jadwalId = (int)$exRow['id'];
                    $updStmt->execute([
                        $sess['id_jadwal_mingguan'],
                        $saveGuruId,
                        $modulId,
                        $cpId,
                        $tpId,
                        $sess['hari'],
                        $sess['tanggal'],
                        $sess['jam_ke'],
                        $sess['jam_mulai'],
                        $sess['jam_selesai'],
                        $sess['alokasi_jp'],
                        $sess['ruangan'],
                        $jenisAg,
                        $materi,
                        $jadwalId,
                    ]);
                } else {
                    $insStmt->execute([
                        $idKmp,
                        $sess['id_jadwal_mingguan'],
                        $saveGuruId,
                        $modulId,
                        $cpId,
                        $tpId,
                        $pertKe,
                        $tahun,
                        $semester,
                        $sess['hari'],
                        $sess['tanggal'],
                        $sess['jam_ke'],
                        $sess['jam_mulai'],
                        $sess['jam_selesai'],
                        $sess['alokasi_jp'],
                        $materi,
                        $jenisAg,
                        $sess['ruangan'],
                    ]);
                    $jadwalId = (int)$pdo->lastInsertId();
                }

                $keptIds[] = $jadwalId;

                // Sinkron balik ke modul_ajar agar tanggal/jam di Modul Ajar selalu sama persis dengan Jadwal & Kaldik!
                if ($modulId) {
                    try {
                        $updModulSync->execute([
                            $jadwalId,
                            $sess['tanggal'],
                            substr((string)$sess['jam_mulai'], 0, 5),
                            substr((string)$sess['jam_selesai'], 0, 5),
                            $sess['alokasi_jp'],
                            $tahun,
                            $semester,
                            $modulId,
                        ]);
                    } catch (Exception $eMa) {}
                }
            }

            // Hapus sesi kelebihan yang belum terlaksana & belum memiliki absensi (misal tanggal berubah jadi libur Kaldik)
            $obsoleteIds = array_values(array_diff($existingAllIds, $keptIds));
            if (!empty($obsoleteIds)) {
                $inObs = implode(',', array_map('intval', $obsoleteIds));
                $pdo->exec("
                    DELETE FROM jadwal_pelajaran
                    WHERE id IN ($inObs)
                      AND status_pelaksanaan = 'Belum Terlaksana'
                      AND id NOT IN (SELECT DISTINCT id_jadwal FROM absensi WHERE id_jadwal IS NOT NULL)
                ");
            }

            // Hapus juga jika ada sesi non-terlaksana yang terlanjur jatuh pada tanggal LIBUR di kalender_pendidikan
            if (!empty($nonEffectiveMap)) {
                $holDates = array_keys($nonEffectiveMap);
                $inHol = implode(',', array_fill(0, count($holDates), '?'));
                $delHolParams = array_merge([$idKmp, $tahun, $semester], $holDates);
                $pdo->prepare("
                    DELETE FROM jadwal_pelajaran
                    WHERE id_kelas_mata_pelajaran = ? AND tahun_pelajaran = ? AND semester = ?
                      AND tanggal IN ($inHol)
                      AND status_pelaksanaan = 'Belum Terlaksana'
                      AND id NOT IN (SELECT DISTINCT id_jadwal FROM absensi WHERE id_jadwal IS NOT NULL)
                ")->execute($delHolParams);
            }

            if ($ownTx) $pdo->commit();
        } catch (Exception $eTx) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $eTx;
        }

        return [
            'synced'           => count($plannedSessions),
            'effective_days'   => $effectiveDayHits,
            'total_jtm'        => $totalJtmSemester,
            'skipped_holidays' => count($nonEffectiveMap),
        ];
    }
}

/**
 * 6. Sinkronkan seluruh KMP yang punya Jadwal Mingguan pada tahun/semester aktif
 */
if (!function_exists('ilmuna_sync_all_weekly_schedules')) {
    function ilmuna_sync_all_weekly_schedules($pdo, $tahun, $semester) {
        ilmuna_ensure_academic_sync_schema($pdo);
        $stmt = $pdo->prepare("SELECT DISTINCT id_kelas_mata_pelajaran FROM jadwal_mingguan WHERE tahun_pelajaran = ? AND semester = ?");
        $stmt->execute([$tahun, $semester]);
        $kmpIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $totalSessions = 0;
        foreach ($kmpIds as $kid) {
            $res = ilmuna_sync_kmp_from_weekly($pdo, (int)$kid, $tahun, $semester);
            $totalSessions += (int)($res['synced'] ?? 0);
        }
        return [
            'kmp_count'      => count($kmpIds),
            'total_sessions' => $totalSessions,
        ];
    }
}

/**
 * 6b. Auto-Bootstrap Jadwal Mingguan Anti-Bentrok dari Data KMP / Legacy (Sekali jalan saat kosong, atau lengkapi KMP kosong)
 *     - Menghormati hari Efektif KBM di `kalender_pendidikan` (tidak menempatkan KBM di hari Sabtu jika Sabtu libur di Kaldik)
 *     - Mempertahankan hari preferensi dari `alokasi_hari_mengajar` / `jadwal_pelajaran` bila bebas bentrok
 *     - Menjamin ZERO bentrok Kelas & Guru, lalu otomatis sinkron penuh ke Juli-Desember / Januari-Juni!
 */
if (!function_exists('ilmuna_bootstrap_weekly_from_legacy')) {
    function ilmuna_bootstrap_weekly_from_legacy($pdo, $tahun, $semester, $onlyMissing = false) {
        ilmuna_ensure_academic_sync_schema($pdo);

        $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM jadwal_mingguan WHERE tahun_pelajaran = ? AND semester = ?");
        $cntStmt->execute([$tahun, $semester]);
        $existingTotal = (int)$cntStmt->fetchColumn();

        if (!$onlyMissing && $existingTotal > 0) {
            return ['created' => 0, 'synced' => 0];
        }

        // 1. Deteksi hari aktif KBM reguler dari kalender_pendidikan (minimal 5 hari efektif dalam semester agar hari libur akhir pekan seperti Sabtu 5-hari sekolah tidak terpilih)
        $dayStmt = $pdo->prepare("
            SELECT hari
            FROM kalender_pendidikan
            WHERE tahun_pelajaran = ? AND semester = ?
              AND status_hari = 'Efektif KBM'
              AND hari IN ('Senin','Selasa','Rabu','Kamis','Jumat','Sabtu')
            GROUP BY hari
            HAVING COUNT(*) >= 5
            ORDER BY FIELD(hari,'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu')
        ");
        $dayStmt->execute([$tahun, $semester]);
        $activeDays = $dayStmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($activeDays)) {
            $activeDays = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat'];
        }

        // 2. Ambil daftar KMP (bila $onlyMissing, hanya KMP yang belum ada di jadwal_mingguan)
        $alreadyKmp = [];
        if ($existingTotal > 0) {
            $exK = $pdo->prepare("SELECT DISTINCT id_kelas_mata_pelajaran FROM jadwal_mingguan WHERE tahun_pelajaran = ? AND semester = ?");
            $exK->execute([$tahun, $semester]);
            foreach ($exK->fetchAll(PDO::FETCH_COLUMN) as $kid) {
                $alreadyKmp[(int)$kid] = true;
            }
        }

        $kmpRows = $pdo->query("
            SELECT kmp.id AS id_kmp, kmp.id_kelas, kmp.id_mata_pelajaran,
                   k.nama_kelas, mp.nama_mata_pelajaran,
                   COALESCE(gmp.id_guru, 0) AS id_guru
            FROM kelas_mata_pelajaran kmp
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($kmpRows)) {
            return ['created' => 0, 'synced' => 0];
        }

        // 3. Kumpulkan preferensi hari & JP dari alokasi_hari_mengajar & jadwal_pelajaran eksisting
        $prefByKmp = [];
        try {
            $aStmt = $pdo->prepare("SELECT id_kelas_mata_pelajaran, hari, jp_per_minggu FROM alokasi_hari_mengajar WHERE semester = ? ORDER BY id ASC");
            $aStmt->execute([$semester]);
            foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                $kid = (int)$ar['id_kelas_mata_pelajaran'];
                if (!isset($prefByKmp[$kid]) && in_array($ar['hari'], $activeDays, true)) {
                    $prefByKmp[$kid] = [
                        'hari'   => $ar['hari'],
                        'durasi' => max(1, min(4, (int)($ar['jp_per_minggu'] ?: 2))),
                    ];
                }
            }
        } catch (Exception $eA) {}

        try {
            $jStmt = $pdo->prepare("
                SELECT id_kelas_mata_pelajaran, hari, MAX(alokasi_jp) AS jp
                FROM jadwal_pelajaran
                WHERE tahun_pelajaran = ? AND semester = ?
                GROUP BY id_kelas_mata_pelajaran, hari
            ");
            $jStmt->execute([$tahun, $semester]);
            foreach ($jStmt->fetchAll(PDO::FETCH_ASSOC) as $jr) {
                $kid = (int)$jr['id_kelas_mata_pelajaran'];
                if (!isset($prefByKmp[$kid]) && in_array($jr['hari'], $activeDays, true)) {
                    $prefByKmp[$kid] = [
                        'hari'   => $jr['hari'],
                        'durasi' => max(1, min(4, (int)($jr['jp'] ?: 2))),
                    ];
                }
            }
        } catch (Exception $eJ) {}

        $bellSlots = ilmuna_get_bell_slots($pdo);
        $colors = ['teal', 'navy', 'gold', 'purple', 'emerald', 'rose'];
        $candidateStarts = [1, 3, 5, 7, 9, 2, 4, 6, 8];

        // Track beban per hari agar jadwal tersebar merata dari Senin sampai Jumat
        $dayLoad = [];
        foreach ($activeDays as $d) $dayLoad[$d] = 0;

        $insJm = $pdo->prepare("
            INSERT INTO jadwal_mingguan
                (tahun_pelajaran, semester, id_kelas_mata_pelajaran, id_guru, hari, jam_ke_mulai, durasi_jp, jam_mulai, jam_selesai, ruangan, warna_kartu, catatan)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $created = 0;
        foreach ($kmpRows as $idx => $kr) {
            $idKmp = (int)$kr['id_kmp'];
            if (isset($alreadyKmp[$idKmp])) continue;

            $idKelas = (int)$kr['id_kelas'];
            $idGuru  = (int)$kr['id_guru'];
            $pref    = $prefByKmp[$idKmp] ?? null;
            $durasi  = $pref ? (int)$pref['durasi'] : 2;
            if ($durasi < 1 || $durasi > 4) $durasi = 2;

            // Urutan hari kandidat: hari preferensi dulu (bila ada), lalu hari dengan beban paling sedikit
            $sortedDays = $activeDays;
            usort($sortedDays, function ($a, $b) use ($dayLoad) {
                return ($dayLoad[$a] ?? 0) <=> ($dayLoad[$b] ?? 0);
            });
            $orderedDays = [];
            if ($pref && in_array($pref['hari'], $activeDays, true)) {
                $orderedDays[] = $pref['hari'];
            }
            foreach ($sortedDays as $sd) {
                if (!in_array($sd, $orderedDays, true)) $orderedDays[] = $sd;
            }

            $placed = false;
            foreach ($orderedDays as $hari) {
                foreach ($candidateStarts as $jamKe) {
                    if ($jamKe + $durasi - 1 > 10) continue;
                    $conflict = ilmuna_check_weekly_conflict($pdo, $tahun, $semester, $hari, $jamKe, $durasi, $idKelas, $idGuru, '', 0);
                    if ($conflict === null) {
                        $calc = ilmuna_calc_slot_times($bellSlots, $jamKe, $durasi);
                        $warna = $colors[($kr['id_mata_pelajaran'] + $idx) % count($colors)];
                        $ruang = 'Ruang ' . $kr['nama_kelas'];
                        $insJm->execute([
                            $tahun, $semester, $idKmp, ($idGuru > 0 ? $idGuru : null),
                            $hari, $calc['jam_ke_mulai'], $durasi, $calc['jam_mulai'], $calc['jam_selesai'],
                            $ruang, $warna, 'Auto-disusun bebas bentrok (dapat digeser Admin)'
                        ]);
                        $dayLoad[$hari] = ($dayLoad[$hari] ?? 0) + 1;
                        $created++;
                        $placed = true;
                        break;
                    }
                }
                if ($placed) break;
            }
        }

        $syncAll = ['kmp_count' => 0, 'total_sessions' => 0];
        if ($created > 0) {
            $syncAll = ilmuna_sync_all_weekly_schedules($pdo, $tahun, $semester);
        }

        return [
            'created'        => $created,
            'kmp_count'      => $syncAll['kmp_count'] ?? 0,
            'total_sessions' => $syncAll['total_sessions'] ?? 0,
        ];
    }
}

/**
 * 7. Auto-Ensure Sync untuk KMP Guru (menerima int tunggal atau array KMP; otomatis bootstrap jika jadwal_mingguan masih kosong!)
 */
if (!function_exists('ilmuna_auto_ensure_kmp_synced')) {
    function ilmuna_auto_ensure_kmp_synced($pdo, $kmpIds, $tahun = null, $semester = null) {
        ilmuna_ensure_academic_sync_schema($pdo);
        if (empty($tahun) || empty($semester)) {
            $term = ilmuna_acad_active_term($pdo);
            if (empty($tahun)) $tahun = $term['tahun'];
            if (empty($semester)) $semester = $term['semester'];
        }

        $idArr = is_array($kmpIds) ? $kmpIds : [$kmpIds];
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $idArr))));
        if (empty($cleanIds)) return 0;

        // Jika papan jadwal_mingguan semester ini masih 0 baris, auto-bootstrap bebas bentrok + sinkron penuh ke Kaldik!
        try {
            $chkEmpty = $pdo->prepare("SELECT COUNT(*) FROM jadwal_mingguan WHERE tahun_pelajaran = ? AND semester = ?");
            $chkEmpty->execute([$tahun, $semester]);
            if ((int)$chkEmpty->fetchColumn() === 0) {
                $boot = ilmuna_bootstrap_weekly_from_legacy($pdo, $tahun, $semester, false);
                if (!empty($boot['created'])) {
                    return (int)$boot['kmp_count'];
                }
            }
        } catch (Exception $eBoot) {}

        $in = implode(',', $cleanIds);
        // Cek KMP mana yang memiliki jadwal_mingguan namun jadwal_pelajaran-nya belum terhubung/ketinggalan
        $rows = $pdo->prepare("
            SELECT jm.id_kelas_mata_pelajaran,
                   MAX(jm.updated_at) AS jm_upd,
                   (SELECT MAX(jp.updated_at) FROM jadwal_pelajaran jp WHERE jp.id_kelas_mata_pelajaran = jm.id_kelas_mata_pelajaran AND jp.tahun_pelajaran = ? AND jp.semester = ?) AS jp_upd,
                   (SELECT COUNT(*) FROM jadwal_pelajaran jp2 WHERE jp2.id_kelas_mata_pelajaran = jm.id_kelas_mata_pelajaran AND jp2.tahun_pelajaran = ? AND jp2.semester = ? AND jp2.id_jadwal_mingguan IS NOT NULL) AS jp_cnt
            FROM jadwal_mingguan jm
            WHERE jm.id_kelas_mata_pelajaran IN ($in) AND jm.tahun_pelajaran = ? AND jm.semester = ?
            GROUP BY jm.id_kelas_mata_pelajaran
        ");
        $rows->execute([$tahun, $semester, $tahun, $semester, $tahun, $semester]);
        $list = $rows->fetchAll(PDO::FETCH_ASSOC);

        // Juga cek update terakhir kaldik
        $kaldikUpd = null;
        try {
            $ks = $pdo->prepare("SELECT MAX(updated_at) FROM kalender_pendidikan WHERE tahun_pelajaran = ? AND semester = ?");
            $ks->execute([$tahun, $semester]);
            $kaldikUpd = $ks->fetchColumn();
        } catch (Exception $eK) {}

        $syncedKmps = 0;
        foreach ($list as $r) {
            $needsSync = ((int)$r['jp_cnt'] === 0)
                || empty($r['jp_upd'])
                || ($r['jm_upd'] && $r['jp_upd'] < $r['jm_upd'])
                || ($kaldikUpd && $r['jp_upd'] < $kaldikUpd);
            if ($needsSync) {
                ilmuna_sync_kmp_from_weekly($pdo, (int)$r['id_kelas_mata_pelajaran'], $tahun, $semester);
                $syncedKmps++;
            }
        }
        return $syncedKmps;
    }
}

/**
 * 8. Ambil Jadwal Mingguan per KMP (untuk ditampilkan di PROTA/PROSEM, Modul Ajar, Guru Jadwal)
 */
if (!function_exists('ilmuna_get_weekly_slots_for_kmp')) {
    function ilmuna_get_weekly_slots_for_kmp($pdo, $idKmp, $tahun, $semester) {
        ilmuna_ensure_academic_sync_schema($pdo);
        $stmt = $pdo->prepare("
            SELECT jm.*, k.nama_kelas, mp.nama_mata_pelajaran, mp.kode_mapel,
                   COALESCE(g.nama_guru, g2.nama_guru, 'Guru Pengampu') AS nama_guru
            FROM jadwal_mingguan jm
            JOIN kelas_mata_pelajaran kmp ON jm.id_kelas_mata_pelajaran = kmp.id
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru g ON jm.id_guru = g.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN guru g2 ON gmp.id_guru = g2.id
            WHERE jm.id_kelas_mata_pelajaran = ? AND jm.tahun_pelajaran = ? AND jm.semester = ?
            ORDER BY FIELD(jm.hari,'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'), jm.jam_ke_mulai ASC
        ");
        $stmt->execute([(int)$idKmp, $tahun, $semester]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/**
 * Ambil peta total skor pelanggaran (yang sudah ditetapkan Guru BK, poin > 0) per siswa
 * untuk suatu kelas_mata_pelajaran (termasuk pelanggaran umum / non-mapel).
 */
if (!function_exists('ilmuna_get_pelanggaran_penalty_map')) {
    function ilmuna_get_pelanggaran_penalty_map(PDO $pdo, int $idKmp = 0, array $siswaIds = []): array {
        $map = [];
        try {
            $where = ['poin > 0'];
            $bind  = [];
            if ($idKmp > 0) {
                $where[] = '(id_kelas_mata_pelajaran = ? OR id_kelas_mata_pelajaran IS NULL)';
                $bind[]  = $idKmp;
            }
            if (!empty($siswaIds)) {
                $cleanIds = array_values(array_unique(array_filter(array_map('intval', $siswaIds))));
                if (!empty($cleanIds)) {
                    $ph = implode(',', array_fill(0, count($cleanIds), '?'));
                    $where[] = "id_siswa IN ({$ph})";
                    $bind = array_merge($bind, $cleanIds);
                }
            }
            $st = $pdo->prepare("
                SELECT id_siswa, COALESCE(SUM(poin), 0) AS total_poin, COUNT(id) AS total_kasus
                FROM catatan_pelanggaran_siswa
                WHERE " . implode(' AND ', $where) . "
                GROUP BY id_siswa
            ");
            $st->execute($bind);
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $map[(int)$r['id_siswa']] = [
                    'total_poin'  => (int)$r['total_poin'],
                    'total_kasus' => (int)$r['total_kasus'],
                ];
            }
        } catch (Throwable $e) {
            // Abaikan jika tabel catatan_pelanggaran_siswa belum tersedia
        }
        return $map;
    }
}

/**
 * Kalkulasi Nilai Akhir Afektif Gabungan:
 *   70% Skor Observasi Sikap Guru (0-100) + 30% Indeks Kedisiplinan BK (max(0, 100 - totalPoinPelanggaran)).
 * Jika observasi sempurna (100) dan tidak ada pelanggaran (0 poin) -> Nilai = 100 (Sempurna).
 */
if (!function_exists('ilmuna_calc_afektif_composite')) {
    function ilmuna_calc_afektif_composite(float $skorObservasi, int $totalPoinPelanggaran): float {
        $obs = max(0.0, min(100.0, $skorObservasi));
        $disiplin = max(0.0, min(100.0, 100.0 - max(0, $totalPoinPelanggaran)));
        return round((0.70 * $obs) + (0.30 * $disiplin), 2);
    }
}

/**
 * Terapkan perubahan Indeks Kedisiplinan BK (bobot 30%) ke nilai_asesmen_afektif yang sudah tersimpan
 * serta sinkronkan ulang rekap_nilai_siswa_mapel.rata_afektif & predikat_afektif.
 */
if (!function_exists('ilmuna_apply_pelanggaran_delta_to_afektif')) {
    function ilmuna_apply_pelanggaran_delta_to_afektif(PDO $pdo, int $idSiswa, ?int $idKmp, int $oldTotalPoin, int $newTotalPoin): void {
        if ($idSiswa <= 0 || $oldTotalPoin === $newTotalPoin) {
            return;
        }
        $oldDisiplin = max(0.0, min(100.0, 100.0 - max(0, $oldTotalPoin)));
        $newDisiplin = max(0.0, min(100.0, 100.0 - max(0, $newTotalPoin)));
        $deltaAfektif = round(0.30 * ($newDisiplin - $oldDisiplin), 2);
        if ($deltaAfektif == 0.0) {
            return;
        }
        try {
            if ($idKmp && $idKmp > 0) {
                $stRows = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, nilai_angka FROM nilai_asesmen_afektif WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ?");
                $stRows->execute([$idSiswa, $idKmp]);
            } else {
                $stRows = $pdo->prepare("SELECT id, id_kelas_mata_pelajaran, nilai_angka FROM nilai_asesmen_afektif WHERE id_siswa = ?");
                $stRows->execute([$idSiswa]);
            }
            $afektifRows = $stRows->fetchAll(PDO::FETCH_ASSOC);
            if (empty($afektifRows)) {
                return;
            }

            $touchedKmps = [];
            $stUpd = $pdo->prepare("
                UPDATE nilai_asesmen_afektif
                SET nilai_angka = ?, skor_rata_skala = ?, predikat = ?, status_tuntas = ?, waktu_penilaian = NOW()
                WHERE id = ?
            ");
            foreach ($afektifRows as $ar) {
                $newScore = max(0.0, min(100.0, round(((float)$ar['nilai_angka']) + $deltaAfektif, 2)));
                $newSkala = round($newScore / 25, 2);
                $newPred  = ($newScore >= 88) ? 'SB' : (($newScore >= 75) ? 'B' : (($newScore >= 60) ? 'C' : 'K'));
                $newStat  = in_array($newPred, ['SB', 'B'], true) ? 'Tuntas' : 'Perlu Pembinaan';
                $stUpd->execute([$newScore, $newSkala, $newPred, $newStat, (int)$ar['id']]);
                $touchedKmps[(int)$ar['id_kelas_mata_pelajaran']] = true;
            }

            $stAvg = $pdo->prepare("SELECT AVG(nilai_angka) FROM nilai_asesmen_afektif WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ?");
            $stRek = $pdo->prepare("UPDATE rekap_nilai_siswa_mapel SET rata_afektif = ?, predikat_afektif = ? WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ?");
            foreach (array_keys($touchedKmps) as $kmpId) {
                $stAvg->execute([$idSiswa, $kmpId]);
                $rata = round((float)($stAvg->fetchColumn() ?: 0), 2);
                $pred = ($rata >= 88) ? 'SB' : (($rata >= 75) ? 'B' : (($rata >= 60) ? 'C' : 'K'));
                $stRek->execute([$rata, $pred, $idSiswa, $kmpId]);
            }
        } catch (Throwable $e) {
            // Jangan gagalkan penyimpanan skor BK bila tabel penilaian afektif belum terisi
        }
    }
}
