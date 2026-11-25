<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — CENTRAL ACCOUNT CREDENTIALS, TTL SCHEMA & PHOTO ISOLATION HELPER
 * ==============================================================================
 * Standar Resmi:
 *   1. Auto-Migrasi Kolom `tempat_lahir`, `tanggal_lahir`, dan `foto_locked`
 *      pada tabel `siswa` dan `guru`, serta `foto_profil` pada tabel `users`.
 *   2. Rumus Pembuatan Akun Resmi:
 *      - Siswa : Username = NIS  | Password = NISN + Tanggal Lahir (DDMMYY)
 *      - Guru  : Username = NIP  | Password = NIP  + Tanggal Lahir (DDMMYY)
 *      Contoh: NISN/NIP = 3405220, Tanggal Lahir = 8 Februari 2006 (2006-02-08)
 *              -> Password = 3405220080206
 *   3. Pemisahan Ketat (Zero Sync) antara:
 *      - Foto Akun Portal (`users.foto_profil`) -> bebas diganti kapan saja
 *      - Pas Foto Informasi Pribadi (`siswa.foto` / `guru.foto`) -> 1x upload,
 *        langsung dikunci (`foto_locked = 1`), ubah selanjutnya wajib via Admin.
 *   4. Kompatibel 100% dengan PHP 7.4 (ea-php74) s/d PHP 8.3+.
 * ==============================================================================
 */

if (!function_exists('ilmuna_get_table_columns_fresh')) {
    function ilmuna_get_table_columns_fresh(PDO $pdo, $table)
    {
        $cols = [];
        try {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $st = $pdo->query("PRAGMA table_info(`{$table}`)");
                if ($st) {
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if (!empty($r['name'])) {
                            $cols[] = (string)$r['name'];
                        }
                    }
                }
            } else {
                $st = $pdo->query("SHOW COLUMNS FROM `{$table}`");
                if ($st) {
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        if (!empty($r['Field'])) {
                            $cols[] = (string)$r['Field'];
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $cols;
    }
}

if (!function_exists('ilmuna_ensure_account_profile_schema')) {
    function ilmuna_ensure_account_profile_schema(PDO $pdo, $force = false)
    {
        static $migrated = false;
        if ($migrated && !$force) {
            return;
        }
        $migrated = true;

        $driver = '';
        try {
            $driver = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $e) {
            return;
        }

        // 1. Pastikan kolom pada tabel `users` lengkap (`foto_profil`, `nisn_nip`)
        $userCols = ilmuna_get_table_columns_fresh($pdo, 'users');
        if (!empty($userCols)) {
            if (!in_array('foto_profil', $userCols, true)) {
                try {
                    if ($driver === 'sqlite') {
                        $pdo->exec("ALTER TABLE `users` ADD COLUMN `foto_profil` VARCHAR(255) DEFAULT NULL");
                    } else {
                        $afterCol = in_array('phone', $userCols, true) ? ' AFTER `phone`' : '';
                        $pdo->exec("ALTER TABLE `users` ADD COLUMN `foto_profil` VARCHAR(255) DEFAULT NULL{$afterCol}");
                    }
                    $userCols[] = 'foto_profil';
                } catch (Throwable $e) {}
            }
            if (!in_array('nisn_nip', $userCols, true)) {
                try {
                    if ($driver === 'sqlite') {
                        $pdo->exec("ALTER TABLE `users` ADD COLUMN `nisn_nip` VARCHAR(50) DEFAULT NULL");
                    } else {
                        $afterCol = in_array('full_name', $userCols, true) ? ' AFTER `full_name`' : '';
                        $pdo->exec("ALTER TABLE `users` ADD COLUMN `nisn_nip` VARCHAR(50) DEFAULT NULL{$afterCol}");
                    }
                } catch (Throwable $e) {}
            }
            // Self-heal internal di dalam tabel `users` sendiri bila `avatar` terisi tapi `foto_profil` kosong
            if (in_array('avatar', $userCols, true) && in_array('foto_profil', $userCols, true)) {
                try {
                    $pdo->exec("UPDATE `users` SET `foto_profil` = `avatar` WHERE (`foto_profil` IS NULL OR TRIM(`foto_profil`) = '') AND `avatar` IS NOT NULL AND TRIM(`avatar`) != ''");
                } catch (Throwable $e) {}
            }
        }

        // 2. Pastikan kolom `tempat_lahir`, `tanggal_lahir`, dan `foto_locked` ada pada tabel `siswa`
        $siswaCols = ilmuna_get_table_columns_fresh($pdo, 'siswa');
        if (!empty($siswaCols)) {
            if (!in_array('tempat_lahir', $siswaCols, true)) {
                try {
                    if ($driver === 'sqlite') {
                        $pdo->exec("ALTER TABLE `siswa` ADD COLUMN `tempat_lahir` VARCHAR(100) DEFAULT NULL");
                    } else {
                        $after = in_array('nama_lengkap', $siswaCols, true) ? ' AFTER `nama_lengkap`' : '';
                        $pdo->exec("ALTER TABLE `siswa` ADD COLUMN `tempat_lahir` VARCHAR(100) DEFAULT NULL{$after}");
                    }
                    $siswaCols[] = 'tempat_lahir';
                } catch (Throwable $e) {}
            }
            if (!in_array('tanggal_lahir', $siswaCols, true)) {
                try {
                    if ($driver === 'sqlite') {
                        $pdo->exec("ALTER TABLE `siswa` ADD COLUMN `tanggal_lahir` DATE DEFAULT NULL");
                    } else {
                        $after = in_array('tempat_lahir', $siswaCols, true) ? ' AFTER `tempat_lahir`' : '';
                        $pdo->exec("ALTER TABLE `siswa` ADD COLUMN `tanggal_lahir` DATE DEFAULT NULL{$after}");
                    }
                    $siswaCols[] = 'tanggal_lahir';
                } catch (Throwable $e) {}
            }
            if (!in_array('foto_locked', $siswaCols, true)) {
                try {
                    if ($driver === 'sqlite') {
                        $pdo->exec("ALTER TABLE `siswa` ADD COLUMN `foto_locked` INTEGER NOT NULL DEFAULT 0");
                    } else {
                        $after = in_array('foto', $siswaCols, true) ? ' AFTER `foto`' : '';
                        $pdo->exec("ALTER TABLE `siswa` ADD COLUMN `foto_locked` TINYINT(1) NOT NULL DEFAULT 0{$after}");
                    }
                    $siswaCols[] = 'foto_locked';
                    // Putuskan sinkronisasi lama dari users (`uploads/profiles/...`) agar pas foto pribadi siswa mandiri
                    $pdo->exec("UPDATE `siswa` SET `foto` = NULL, `foto_locked` = 0 WHERE `foto` LIKE 'uploads/profiles/%'");
                    // Kunci foto siswa resmi yang memang sudah diunggah di folder siswa
                    $pdo->exec("UPDATE `siswa` SET `foto_locked` = 1 WHERE `foto` IS NOT NULL AND TRIM(`foto`) != '' AND `foto` LIKE 'uploads/students/%'");
                } catch (Throwable $e) {}
            } else {
                // Pastikan tidak ada foto siswa yang masih menunjuk ke avatar akun `uploads/profiles/`
                try {
                    $pdo->exec("UPDATE `siswa` SET `foto` = NULL, `foto_locked` = 0 WHERE `foto` LIKE 'uploads/profiles/%'");
                } catch (Throwable $e) {}
            }
        }

        // 3. Pastikan kolom `tempat_lahir`, `tanggal_lahir`, dan `foto_locked` ada pada tabel `guru`
        $guruCols = ilmuna_get_table_columns_fresh($pdo, 'guru');
        if (!empty($guruCols)) {
            if (!in_array('tempat_lahir', $guruCols, true)) {
                try {
                    if ($driver === 'sqlite') {
                        $pdo->exec("ALTER TABLE `guru` ADD COLUMN `tempat_lahir` VARCHAR(100) DEFAULT NULL");
                    } else {
                        $after = in_array('nama_guru', $guruCols, true) ? ' AFTER `nama_guru`' : '';
                        $pdo->exec("ALTER TABLE `guru` ADD COLUMN `tempat_lahir` VARCHAR(100) DEFAULT NULL{$after}");
                    }
                    $guruCols[] = 'tempat_lahir';
                } catch (Throwable $e) {}
            }
            if (!in_array('tanggal_lahir', $guruCols, true)) {
                try {
                    if ($driver === 'sqlite') {
                        $pdo->exec("ALTER TABLE `guru` ADD COLUMN `tanggal_lahir` DATE DEFAULT NULL");
                    } else {
                        $after = in_array('tempat_lahir', $guruCols, true) ? ' AFTER `tempat_lahir`' : '';
                        $pdo->exec("ALTER TABLE `guru` ADD COLUMN `tanggal_lahir` DATE DEFAULT NULL{$after}");
                    }
                    $guruCols[] = 'tanggal_lahir';
                } catch (Throwable $e) {}
            }
            if (!in_array('foto_locked', $guruCols, true)) {
                try {
                    if ($driver === 'sqlite') {
                        $pdo->exec("ALTER TABLE `guru` ADD COLUMN `foto_locked` INTEGER NOT NULL DEFAULT 0");
                    } else {
                        $after = in_array('foto', $guruCols, true) ? ' AFTER `foto`' : '';
                        $pdo->exec("ALTER TABLE `guru` ADD COLUMN `foto_locked` TINYINT(1) NOT NULL DEFAULT 0{$after}");
                    }
                    $guruCols[] = 'foto_locked';
                    // Putuskan sinkronisasi lama dari users (`uploads/profiles/...`) agar pas foto pribadi guru mandiri
                    $pdo->exec("UPDATE `guru` SET `foto` = NULL, `foto_locked` = 0 WHERE `foto` LIKE 'uploads/profiles/%'");
                    // Kunci foto guru resmi yang memang sudah diunggah di folder guru
                    $pdo->exec("UPDATE `guru` SET `foto_locked` = 1 WHERE `foto` IS NOT NULL AND TRIM(`foto`) != '' AND `foto` LIKE 'uploads/teachers/%'");
                } catch (Throwable $e) {}
            } else {
                try {
                    $pdo->exec("UPDATE `guru` SET `foto` = NULL, `foto_locked` = 0 WHERE `foto` LIKE 'uploads/profiles/%'");
                } catch (Throwable $e) {}
            }
        }
    }
}

/**
 * Normalisasi path foto (relatif maupun URL lama) ke URL absolut berdasarkan BASE_URL aktif.
 * Menjamin foto profil tidak pernah hilang/404 akibat perbedaan domain/subfolder setelah re-login.
 */
if (!function_exists('ilmuna_resolve_media_url')) {
    function ilmuna_resolve_media_url($rawPath)
    {
        $s = trim((string)$rawPath);
        if ($s === '') {
            return '';
        }
        if (strpos($s, 'data:image/') === 0) {
            return $s;
        }
        $baseUrl = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';

        // Jika tersimpan sebagai URL penuh namun mengarah ke folder lokal /uploads/..., normalisasi ke BASE_URL saat ini
        if (preg_match('#^https?://#i', $s)) {
            if (preg_match('#/(uploads/[A-Za-z0-9_./\-]+)$#i', $s, $m)) {
                $rel = ltrim($m[1], '/');
                if (defined('BASE_PATH') && file_exists(BASE_PATH . '/' . $rel)) {
                    return $baseUrl . '/' . $rel;
                }
            }
            return $s;
        }

        $relClean = ltrim(str_replace('\\', '/', $s), '/');
        if ($relClean === '') {
            return '';
        }
        // Jika file tidak punya slash (contoh legacy 'farhan.jpg'), cek apakah file benar-benar ada
        if (strpos($relClean, '/') === false && defined('BASE_PATH')) {
            if (file_exists(BASE_PATH . '/uploads/students/' . $relClean)) {
                return $baseUrl . '/uploads/students/' . $relClean;
            }
            if (file_exists(BASE_PATH . '/uploads/teachers/' . $relClean)) {
                return $baseUrl . '/uploads/teachers/' . $relClean;
            }
            if (!file_exists(BASE_PATH . '/' . $relClean)) {
                return '';
            }
        }

        return $baseUrl . '/' . $relClean;
    }
}

/**
 * Normalisasi path foto untuk disimpan di kolom DB (selalu relatif `uploads/...` bila file lokal)
 */
if (!function_exists('ilmuna_normalize_media_db_path')) {
    function ilmuna_normalize_media_db_path($rawPath)
    {
        $s = trim((string)$rawPath);
        if ($s === '') {
            return null;
        }
        if (preg_match('#(uploads/[A-Za-z0-9_./\-]+)$#i', str_replace('\\', '/', $s), $m)) {
            return $m[1];
        }
        return ltrim(str_replace('\\', '/', $s), '/');
    }
}

/**
 * Parser Tanggal Fleksibel -> YYYY-MM-DD
 * Mendukung:
 *   - 2006-02-08
 *   - 08/02/2006, 08-02-2006, 8/2/2006
 *   - 8 Februari 2006, 08 Feb 2006, 8 February 2006
 *   - Serial Number Tanggal Excel (misal: 38756)
 */
if (!function_exists('ilmuna_parse_date_to_ymd')) {
    function ilmuna_parse_date_to_ymd($raw)
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string)$raw);
        if ($s === '' || $s === '-' || $s === '0000-00-00') {
            return null;
        }

        // Jika ada "Tempat, Tanggal" (misal "Semarang, 8 Februari 2006"), ambil bagian tanggal setelah koma
        if (strpos($s, ',') !== false) {
            $partsComma = explode(',', $s);
            $candidateAfterComma = trim((string)end($partsComma));
            if ($candidateAfterComma !== '') {
                $s = $candidateAfterComma;
            }
        }

        // 1. Excel serial date number (contoh: 38756 -> 2006-02-08)
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $s)) {
            $serial = (int)floor((float)$s);
            if ($serial >= 15000 && $serial <= 60000) {
                $unix = ($serial - 25569) * 86400;
                $ymd  = gmdate('Y-m-d', $unix);
                if ($ymd && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $em)) {
                    if (checkdate((int)$em[2], (int)$em[3], (int)$em[1])) {
                        return $ymd;
                    }
                }
            }
        }

        // 2. Format ISO YYYY-MM-DD atau YYYY/MM/DD
        if (preg_match('/^(\d{4})[\-\/.](\d{1,2})[\-\/.](\d{1,2})/', $s, $m)) {
            $y = (int)$m[1];
            $mo = (int)$m[2];
            $d = (int)$m[3];
            if (checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        // 3. Format DD-MM-YYYY atau DD/MM/YYYY atau DD.MM.YYYY
        if (preg_match('/^(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{4})$/', $s, $m)) {
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = (int)$m[3];
            if (checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        // 4. Format DD-MM-YY (2 digit tahun)
        if (preg_match('/^(\d{1,2})[\-\/.](\d{1,2})[\-\/.](\d{2})$/', $s, $m)) {
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $yy = (int)$m[3];
            $y = ($yy <= ((int)date('y') + 5)) ? (2000 + $yy) : (1900 + $yy);
            if (checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        // 5. Format Teks Indonesia / Inggris: "8 Februari 2006", "08 Feb 2006", dll.
        $monthMap = [
            'januari' => 1, 'january' => 1, 'jan' => 1,
            'februari' => 2, 'pebruari' => 2, 'february' => 2, 'feb' => 2, 'peb' => 2,
            'maret' => 3, 'march' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4,
            'mei' => 5, 'may' => 5,
            'juni' => 6, 'june' => 6, 'jun' => 6,
            'juli' => 7, 'july' => 7, 'jul' => 7,
            'agustus' => 8, 'august' => 8, 'agu' => 8, 'ags' => 8, 'aug' => 8,
            'september' => 9, 'sep' => 9, 'sept' => 9,
            'oktober' => 10, 'october' => 10, 'okt' => 10, 'oct' => 10,
            'november' => 11, 'nopember' => 11, 'nov' => 11, 'nop' => 11,
            'desember' => 12, 'december' => 12, 'des' => 12, 'dec' => 12,
        ];
        if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/', $s, $m)) {
            $d = (int)$m[1];
            $mKey = strtolower($m[2]);
            $y = (int)$m[3];
            if (isset($monthMap[$mKey]) && checkdate($monthMap[$mKey], $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $monthMap[$mKey], $d);
            }
        }

        return null;
    }
}

/**
 * Konversi tanggal lahir ke format 6 digit DDMMYY untuk rumus password resmi.
 * Contoh: '2006-02-08' (8 Februari 2006) -> '080206'
 */
if (!function_exists('ilmuna_format_dob_ddmmyy')) {
    function ilmuna_format_dob_ddmmyy($rawDate)
    {
        $ymd = ilmuna_parse_date_to_ymd($rawDate);
        if (!$ymd) {
            return '';
        }
        $parts = explode('-', $ymd);
        if (count($parts) !== 3) {
            return '';
        }
        $yy = substr($parts[0], -2);
        $mm = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $dd = str_pad($parts[2], 2, '0', STR_PAD_LEFT);
        return $dd . $mm . $yy;
    }
}

/**
 * Format Tempat, Tanggal Lahir Bahasa Indonesia (contoh: "Semarang, 08 Februari 2006")
 */
if (!function_exists('ilmuna_format_ttl_indo')) {
    function ilmuna_format_ttl_indo($tempatLahir, $tanggalLahir)
    {
        $tempat = trim((string)$tempatLahir);
        $ymd = ilmuna_parse_date_to_ymd($tanggalLahir);
        $bulanIndo = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $tglIndo = '';
        if ($ymd) {
            $p = explode('-', $ymd);
            if (count($p) === 3) {
                $mIdx = (int)$p[1];
                $tglIndo = str_pad($p[2], 2, '0', STR_PAD_LEFT) . ' ' . ($bulanIndo[$mIdx] ?? $p[1]) . ' ' . $p[0];
            }
        }
        if ($tempat !== '' && $tglIndo !== '') {
            return $tempat . ', ' . $tglIndo;
        }
        if ($tempat !== '') {
            return $tempat;
        }
        if ($tglIndo !== '') {
            return $tglIndo;
        }
        return '-';
    }
}

/**
 * Generator Kredensial Resmi Siswa:
 *   - Username = NIS
 *   - Password = NISN + Tanggal Lahir (DDMMYY)
 *   Contoh: NISN 3405220 + 8 Februari 2006 -> Password = 3405220080206
 */
if (!function_exists('ilmuna_build_student_credentials')) {
    function ilmuna_build_student_credentials($nis, $nisn, $tanggalLahir)
    {
        $cleanNis  = preg_replace('/[^0-9A-Za-z._\-]/', '', trim((string)$nis));
        $cleanNisn = preg_replace('/[^0-9A-Za-z]/', '', trim((string)$nisn));
        $ymd       = ilmuna_parse_date_to_ymd($tanggalLahir);
        $ddmmyy    = ilmuna_format_dob_ddmmyy($ymd);

        if ($cleanNis === '') {
            return [
                'valid'    => false,
                'error'    => 'NIS siswa belum diisi (Username akun siswa wajib menggunakan NIS).',
                'username' => '',
                'password' => '',
                'dob_ymd'  => $ymd,
            ];
        }
        if ($cleanNisn === '') {
            return [
                'valid'    => false,
                'error'    => 'NISN siswa belum diisi (Password akun siswa wajib menggunakan NISN + Tanggal Lahir).',
                'username' => $cleanNis,
                'password' => '',
                'dob_ymd'  => $ymd,
            ];
        }
        if ($ddmmyy === '') {
            return [
                'valid'    => false,
                'error'    => 'Tanggal lahir siswa belum diisi atau tidak valid (Password wajib format NISN + DDMMYY).',
                'username' => $cleanNis,
                'password' => '',
                'dob_ymd'  => null,
            ];
        }

        return [
            'valid'      => true,
            'error'      => '',
            'username'   => $cleanNis,
            'password'   => $cleanNisn . $ddmmyy,
            'nisn_nip'   => $cleanNisn,
            'dob_ymd'    => $ymd,
            'dob_ddmmyy' => $ddmmyy,
        ];
    }
}

/**
 * Generator Kredensial Resmi Guru:
 *   - Username = NIP
 *   - Password = NIP + Tanggal Lahir (DDMMYY)
 *   Contoh: NIP 3405220 + 8 Februari 2006 -> Password = 3405220080206
 */
if (!function_exists('ilmuna_build_teacher_credentials')) {
    function ilmuna_build_teacher_credentials($nip, $tanggalLahir)
    {
        $cleanNip = preg_replace('/[^0-9A-Za-z._\-]/', '', trim((string)$nip));
        $passNip  = preg_replace('/[^0-9A-Za-z]/', '', trim((string)$nip));
        $ymd      = ilmuna_parse_date_to_ymd($tanggalLahir);
        $ddmmyy   = ilmuna_format_dob_ddmmyy($ymd);

        if ($cleanNip === '' || $cleanNip === '-') {
            return [
                'valid'    => false,
                'error'    => 'NIP guru belum diisi (Username & Password akun guru wajib menggunakan NIP).',
                'username' => '',
                'password' => '',
                'dob_ymd'  => $ymd,
            ];
        }
        if ($ddmmyy === '') {
            return [
                'valid'    => false,
                'error'    => 'Tanggal lahir guru belum diisi atau tidak valid (Password wajib format NIP + DDMMYY).',
                'username' => $cleanNip,
                'password' => '',
                'dob_ymd'  => null,
            ];
        }

        return [
            'valid'      => true,
            'error'      => '',
            'username'   => $cleanNip,
            'password'   => $passNip . $ddmmyy,
            'nisn_nip'   => $cleanNip,
            'dob_ymd'    => $ymd,
            'dob_ddmmyy' => $ddmmyy,
        ];
    }
}

/**
 * Cek apakah Pas Foto Resmi Informasi Pribadi (`siswa` / `guru`) sudah dikunci (1x upload)
 */
if (!function_exists('ilmuna_is_personal_photo_locked')) {
    function ilmuna_is_personal_photo_locked($record)
    {
        if (!is_array($record) || empty($record)) {
            return false;
        }
        if (!empty($record['foto_locked']) && (int)$record['foto_locked'] === 1) {
            $foto = trim((string)($record['foto'] ?? ''));
            if ($foto !== '' && strpos($foto, 'uploads/profiles/') !== 0) {
                return true;
            }
        }
        $foto = trim((string)($record['foto'] ?? ''));
        if ($foto !== '' && strpos($foto, 'uploads/profiles/') !== 0) {
            return true;
        }
        return false;
    }
}
