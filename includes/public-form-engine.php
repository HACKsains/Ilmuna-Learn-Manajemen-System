<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PUBLIC HASHED FORM ENGINE (SISWA+ORANG TUA & GURU)
 * ==============================================================================
 * Mengelola:
 *   1. Tabel `public_form_links` (token hash SHA-256 32-karakter).
 *   2. Tabel `public_form_submissions` (kunci ketat 1x kirim per Nama / Email / No. Identitas).
 *   3. Deteksi email sesi aktif (jika sudah login Ilmuna) untuk auto-fill ala Google Form.
 * ==============================================================================
 */

if (!function_exists('ilmuna_ensure_public_form_links')) {
    function ilmuna_ensure_public_form_links(PDO $pdo): void {
        static $ensured = false;
        if ($ensured) return;
        $ensured = true;
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `public_form_links` (
                `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `form_type` ENUM('siswa_ortu','guru') NOT NULL,
                `token_hash` VARCHAR(64) NOT NULL,
                `title` VARCHAR(160) NOT NULL,
                `description` TEXT NULL DEFAULT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `submission_count` INT(11) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_pfl_type` (`form_type`),
                UNIQUE KEY `uq_pfl_hash` (`token_hash`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `public_form_submissions` (
                `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `form_type` ENUM('siswa_ortu','guru') NOT NULL,
                `nama_norm` VARCHAR(160) NOT NULL,
                `email_norm` VARCHAR(160) NOT NULL,
                `identitas_norm` VARCHAR(80) NOT NULL,
                `ip_address` VARCHAR(64) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_pfs_nama` (`form_type`, `nama_norm`),
                UNIQUE KEY `uq_pfs_email` (`form_type`, `email_norm`),
                UNIQUE KEY `uq_pfs_identitas` (`form_type`, `identitas_norm`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $defaults = [
            'siswa_ortu' => [
                'title' => 'Formulir Pendataan Siswa & Orang Tua / Wali',
                'desc'  => 'Silakan isi data lengkap peserta didik beserta data orang tua/wali secara akurat. Setiap 1 Nama, 1 Email, dan 1 NISN hanya dapat mengirim formulir 1 kali.',
            ],
            'guru' => [
                'title' => 'Formulir Pendataan Guru & Tenaga Pendidik',
                'desc'  => 'Silakan lengkapi biodata pendidik, email aktif, nomor identitas (NIP/NUPTK/NIK), serta mata pelajaran yang diampu. Setiap 1 Nama, 1 Email, dan 1 No. Identitas hanya dapat mengirim 1 kali.',
            ],
        ];

        $chk = $pdo->prepare("SELECT id FROM public_form_links WHERE form_type = ? LIMIT 1");
        $ins = $pdo->prepare("INSERT INTO public_form_links (form_type, token_hash, title, description, is_active) VALUES (?, ?, ?, ?, 1)");
        foreach ($defaults as $type => $meta) {
            $chk->execute([$type]);
            if (!$chk->fetchColumn()) {
                $hash = hash('sha256', $type . '|' . bin2hex(random_bytes(24)) . '|' . microtime(true));
                $shortHash = substr($hash, 0, 32);
                $ins->execute([$type, $shortHash, $meta['title'], $meta['desc']]);
            }
        }
    }
}

if (!function_exists('ilmuna_norm_identity')) {
    function ilmuna_norm_identity(string $v): string {
        return strtolower(preg_replace('/\s+/', '', trim($v)));
    }
}

if (!function_exists('ilmuna_norm_name')) {
    function ilmuna_norm_name(string $v): string {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($v)), 'UTF-8');
    }
}

if (!function_exists('ilmuna_check_public_form_duplicate')) {
    /**
     * Memastikan 1 Nama, 1 Email, dan 1 No. Identitas hanya bisa mengirim 1 kali.
     * Mengembalikan pesan error spesifik jika sudah pernah mengirim, atau null jika aman.
     */
    function ilmuna_check_public_form_duplicate(PDO $pdo, string $formType, string $nama, string $email, string $identitas): ?string {
        ilmuna_ensure_public_form_links($pdo);

        $namaNorm = ilmuna_norm_name($nama);
        $emailNorm = mb_strtolower(trim($email), 'UTF-8');
        $idNorm = ilmuna_norm_identity($identitas);

        if ($idNorm !== '') {
            $st = $pdo->prepare("SELECT id FROM public_form_submissions WHERE form_type = ? AND identitas_norm = ? LIMIT 1");
            $st->execute([$formType, $idNorm]);
            if ($st->fetchColumn()) {
                $lbl = ($formType === 'guru') ? 'No. Identitas (NIP/NUPTK/NIK)' : 'NISN / No. Identitas';
                return $lbl . ' ini sudah pernah mengirim formulir sebelumnya. Setiap identitas hanya dapat mengirim 1 kali.';
            }
        }

        if ($emailNorm !== '') {
            $st = $pdo->prepare("SELECT id FROM public_form_submissions WHERE form_type = ? AND email_norm = ? LIMIT 1");
            $st->execute([$formType, $emailNorm]);
            if ($st->fetchColumn()) {
                return 'Alamat email (' . $emailNorm . ') sudah pernah digunakan untuk mengirim formulir ini. Setiap email hanya dapat mengirim 1 kali.';
            }
        }

        if ($namaNorm !== '') {
            $st = $pdo->prepare("SELECT id FROM public_form_submissions WHERE form_type = ? AND nama_norm = ? LIMIT 1");
            $st->execute([$formType, $namaNorm]);
            if ($st->fetchColumn()) {
                return 'Nama lengkap ini sudah tercatat mengirim formulir sebelumnya. 1 nama hanya diperbolehkan mengirim 1 kali.';
            }
        }

        return null;
    }
}

if (!function_exists('ilmuna_record_public_form_submission')) {
    function ilmuna_record_public_form_submission(PDO $pdo, string $formType, string $nama, string $email, string $identitas): void {
        $namaNorm  = ilmuna_norm_name($nama);
        $emailNorm = mb_strtolower(trim($email), 'UTF-8');
        $idNorm    = ilmuna_norm_identity($identitas);
        $ip        = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);

        $pdo->prepare("
            INSERT INTO public_form_submissions (form_type, nama_norm, email_norm, identitas_norm, ip_address)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$formType, $namaNorm, $emailNorm, $idNorm, $ip ?: null]);
    }
}

if (!function_exists('ilmuna_get_session_user_email')) {
    function ilmuna_get_session_user_email(PDO $pdo): array {
        $res = ['email' => '', 'name' => ''];
        if (!empty($_SESSION['user_email'])) {
            $res['email'] = trim((string)$_SESSION['user_email']);
            $res['name']  = trim((string)($_SESSION['full_name'] ?? $_SESSION['name'] ?? ''));
            return $res;
        }
        if (!empty($_SESSION['user_id'])) {
            try {
                $st = $pdo->prepare("SELECT email, full_name FROM users WHERE id = ? LIMIT 1");
                $st->execute([(int)$_SESSION['user_id']]);
                $u = $st->fetch(PDO::FETCH_ASSOC);
                if ($u && !empty($u['email'])) {
                    $res['email'] = trim((string)$u['email']);
                    $res['name']  = trim((string)($u['full_name'] ?? ''));
                }
            } catch (Throwable $e) {}
        }
        return $res;
    }
}

if (!function_exists('ilmuna_get_public_form_link')) {
    function ilmuna_get_public_form_link(PDO $pdo, string $formType): ?array {
        ilmuna_ensure_public_form_links($pdo);
        $st = $pdo->prepare("SELECT * FROM public_form_links WHERE form_type = ? LIMIT 1");
        $st->execute([$formType]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $slug = ($formType === 'guru') ? 'form-guru' : 'form-siswa';
        $row['public_url'] = BASE_URL . '/' . $slug . '?h=' . $row['token_hash'];
        return $row;
    }
}

if (!function_exists('ilmuna_validate_public_form_hash')) {
    function ilmuna_validate_public_form_hash(PDO $pdo, string $formType, string $hash): ?array {
        ilmuna_ensure_public_form_links($pdo);
        $hash = trim($hash);
        if ($hash === '' || !preg_match('/^[a-f0-9]{16,64}$/i', $hash)) {
            return null;
        }
        $st = $pdo->prepare("SELECT * FROM public_form_links WHERE form_type = ? AND token_hash = ? LIMIT 1");
        $st->execute([$formType, $hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['is_active'] !== 1) {
            return null;
        }
        return $row;
    }
}

if (!function_exists('ilmuna_get_school_brand')) {
    function ilmuna_get_school_brand(PDO $pdo): array {
        $brand = [
            'nama_sekolah' => 'Ilmuna Learn',
            'alamat'       => '',
            'tahun_aktif'  => '2026/2027',
        ];
        try {
            $row = $pdo->query("SELECT nama_sekolah, alamat, tahun_pelajaran_aktif FROM identitas_sekolah WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (!empty($row['nama_sekolah'])) $brand['nama_sekolah'] = $row['nama_sekolah'];
                if (!empty($row['alamat'])) $brand['alamat'] = $row['alamat'];
                if (!empty($row['tahun_pelajaran_aktif'])) $brand['tahun_aktif'] = $row['tahun_pelajaran_aktif'];
            }
        } catch (Throwable $e) {}
        return $brand;
    }
}
