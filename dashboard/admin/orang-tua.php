<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MANAJEMEN DATA ORANG TUA / WALI & RELASI MULTI-ANAK (ADMIN)
 * ==============================================================================
 * Fitur:
 *   1. CRUD Data Orang Tua (Nama Ayah, No HP Ayah, Pekerjaan Ayah, Nama Ibu,
 *      No HP Ibu, Pekerjaan Ibu, Wali, Gmail, Alamat Lengkap).
 *   2. Relasi Multi-Anak (`orang_tua_siswa`): 1 Orang Tua dapat terhubung ke
 *      1, 2, atau lebih siswa di sekolah yang sama.
 *   3. Manajemen Akun Portal Orang Tua (`users` + role `orang_tua`):
 *      Buat Akun Otomatis, Reset Password, Aktif/Nonaktif Akun.
 *   4. Tema Lumina Gold, Data-First, 100% AJAX (Zero Page Reload), window.Ilmuna.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole('admin');

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");

// Pastikan tabel & role tersedia
$pdo->exec("INSERT IGNORE INTO `roles` (`code`, `name`, `description`, `badge_color`, `icon`) VALUES ('orang_tua', 'Orang Tua / Wali', 'Portal monitoring orang tua/wali untuk memantau nilai, tugas, proyek, absensi, dan catatan pelanggaran anak.', 'emerald', 'fas fa-users')");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `orang_tua` (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_user` INT(11) UNSIGNED NULL DEFAULT NULL,
        `nama_ayah` VARCHAR(150) NULL DEFAULT NULL,
        `no_hp_ayah` VARCHAR(30) NULL DEFAULT NULL,
        `pekerjaan_ayah` VARCHAR(100) NULL DEFAULT NULL,
        `nama_ibu` VARCHAR(150) NULL DEFAULT NULL,
        `no_hp_ibu` VARCHAR(30) NULL DEFAULT NULL,
        `pekerjaan_ibu` VARCHAR(100) NULL DEFAULT NULL,
        `nama_wali` VARCHAR(150) NULL DEFAULT NULL,
        `no_hp_wali` VARCHAR(30) NULL DEFAULT NULL,
        `email_gmail` VARCHAR(150) NULL DEFAULT NULL,
        `alamat` TEXT NULL DEFAULT NULL,
        `rt_rw` VARCHAR(20) NULL DEFAULT NULL,
        `kelurahan` VARCHAR(100) NULL DEFAULT NULL,
        `kecamatan` VARCHAR(100) NULL DEFAULT NULL,
        `kota_kabupaten` VARCHAR(100) NULL DEFAULT NULL,
        `catatan` TEXT NULL DEFAULT NULL,
        `status` ENUM('aktif','nonaktif') NOT NULL DEFAULT 'aktif',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        INDEX `idx_ortu_user` (`id_user`),
        INDEX `idx_ortu_email` (`email_gmail`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `orang_tua_siswa` (
        `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_orang_tua` INT(11) UNSIGNED NOT NULL,
        `id_siswa` INT(11) UNSIGNED NOT NULL,
        `hubungan` VARCHAR(50) NOT NULL DEFAULT 'Orang Tua Kandung',
        `is_primary` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_ortu_siswa` (`id_orang_tua`, `id_siswa`),
        INDEX `idx_ots_siswa` (`id_siswa`),
        INDEX `idx_ots_ortu` (`id_orang_tua`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$allSiswa = $pdo->query("
    SELECT s.id, s.nis, s.nama_lengkap, COALESCE(k.nama_kelas, '-') AS nama_kelas
    FROM siswa s
    LEFT JOIN kelas k ON k.id = s.id_kelas
    ORDER BY k.nama_kelas ASC, s.nama_lengkap ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ==============================================================================
 * AJAX JSON API (?api=1)
 * ============================================================================== */
if (isset($_GET['api']) && $_GET['api'] === '1') {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $act = trim((string)($_POST['api_action'] ?? $_GET['api_action'] ?? 'list'));

    try {
        if ($act === 'list') {
            $rows = $pdo->query("
                SELECT o.*, u.username AS akun_username, u.status AS akun_status
                FROM orang_tua o
                LEFT JOIN users u ON u.id = o.id_user
                ORDER BY o.id DESC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $relRows = $pdo->query("
                SELECT ots.id_orang_tua, ots.id_siswa, ots.hubungan,
                       s.nis, s.nama_lengkap, COALESCE(k.nama_kelas, '-') AS nama_kelas
                FROM orang_tua_siswa ots
                JOIN siswa s ON s.id = ots.id_siswa
                LEFT JOIN kelas k ON k.id = s.id_kelas
                ORDER BY k.nama_kelas ASC, s.nama_lengkap ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            $relMap = [];
            foreach ($relRows as $rr) {
                $oid = (int)$rr['id_orang_tua'];
                $relMap[$oid][] = [
                    'id_siswa'     => (int)$rr['id_siswa'],
                    'nis'          => $rr['nis'] ?: '-',
                    'nama_lengkap' => $rr['nama_lengkap'],
                    'nama_kelas'   => $rr['nama_kelas'],
                    'hubungan'     => $rr['hubungan'],
                ];
            }

            $cAkun = 0; $cMulti = 0;
            foreach ($rows as &$r) {
                $oid = (int)$r['id'];
                $r['anak_list'] = $relMap[$oid] ?? [];
                if (!empty($r['id_user'])) $cAkun++;
                if (count($r['anak_list']) >= 2) $cMulti++;
            }
            unset($r);

            echo json_encode([
                'success' => true,
                'data'    => $rows,
                'counts'  => [
                    'all'   => count($rows),
                    'akun'  => $cAkun,
                    'noakun'=> count($rows) - $cAkun,
                    'multi' => $cMulti,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($act === 'save') {
            $id             = (int)($_POST['id'] ?? 0);
            $namaAyah       = trim((string)($_POST['nama_ayah'] ?? ''));
            $noHpAyah       = trim((string)($_POST['no_hp_ayah'] ?? ''));
            $pekerjaanAyah  = trim((string)($_POST['pekerjaan_ayah'] ?? ''));
            $namaIbu        = trim((string)($_POST['nama_ibu'] ?? ''));
            $noHpIbu        = trim((string)($_POST['no_hp_ibu'] ?? ''));
            $pekerjaanIbu   = trim((string)($_POST['pekerjaan_ibu'] ?? ''));
            $namaWali       = trim((string)($_POST['nama_wali'] ?? ''));
            $noHpWali       = trim((string)($_POST['no_hp_wali'] ?? ''));
            $emailGmail     = trim((string)($_POST['email_gmail'] ?? ''));
            $alamat         = trim((string)($_POST['alamat'] ?? ''));
            $rtRw           = trim((string)($_POST['rt_rw'] ?? ''));
            $kelurahan      = trim((string)($_POST['kelurahan'] ?? ''));
            $kecamatan      = trim((string)($_POST['kecamatan'] ?? ''));
            $kotaKab        = trim((string)($_POST['kota_kabupaten'] ?? ''));
            $catatan        = trim((string)($_POST['catatan'] ?? ''));
            $hubungan       = trim((string)($_POST['hubungan'] ?? 'Orang Tua Kandung'));
            $siswaIdsRaw    = $_POST['siswa_ids'] ?? [];
            $siswaIds       = is_array($siswaIdsRaw) ? array_values(array_unique(array_filter(array_map('intval', $siswaIdsRaw)))) : [];

            if ($namaAyah === '' && $namaIbu === '' && $namaWali === '') {
                echo json_encode(['success' => false, 'message' => 'Minimal isi Nama Ayah, Nama Ibu, atau Nama Wali.']);
                exit;
            }
            if (empty($siswaIds)) {
                echo json_encode(['success' => false, 'message' => 'Pilih minimal 1 siswa (anak) yang terhubung dengan orang tua ini.']);
                exit;
            }

            $pdo->beginTransaction();
            if ($id > 0) {
                $pdo->prepare("
                    UPDATE orang_tua
                    SET nama_ayah = ?, no_hp_ayah = ?, pekerjaan_ayah = ?,
                        nama_ibu = ?, no_hp_ibu = ?, pekerjaan_ibu = ?,
                        nama_wali = ?, no_hp_wali = ?, email_gmail = ?,
                        alamat = ?, rt_rw = ?, kelurahan = ?, kecamatan = ?, kota_kabupaten = ?, catatan = ?
                    WHERE id = ?
                ")->execute([
                    $namaAyah ?: null, $noHpAyah ?: null, $pekerjaanAyah ?: null,
                    $namaIbu ?: null, $noHpIbu ?: null, $pekerjaanIbu ?: null,
                    $namaWali ?: null, $noHpWali ?: null, $emailGmail ?: null,
                    $alamat ?: null, $rtRw ?: null, $kelurahan ?: null, $kecamatan ?: null, $kotaKab ?: null, $catatan ?: null,
                    $id
                ]);
                $ortuId = $id;
            } else {
                $pdo->prepare("
                    INSERT INTO orang_tua
                        (nama_ayah, no_hp_ayah, pekerjaan_ayah, nama_ibu, no_hp_ibu, pekerjaan_ibu,
                         nama_wali, no_hp_wali, email_gmail, alamat, rt_rw, kelurahan, kecamatan, kota_kabupaten, catatan)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $namaAyah ?: null, $noHpAyah ?: null, $pekerjaanAyah ?: null,
                    $namaIbu ?: null, $noHpIbu ?: null, $pekerjaanIbu ?: null,
                    $namaWali ?: null, $noHpWali ?: null, $emailGmail ?: null,
                    $alamat ?: null, $rtRw ?: null, $kelurahan ?: null, $kecamatan ?: null, $kotaKab ?: null, $catatan ?: null
                ]);
                $ortuId = (int)$pdo->lastInsertId();
            }

            // Sinkronisasi relasi multi-anak (orang_tua_siswa)
            $pdo->prepare("DELETE FROM orang_tua_siswa WHERE id_orang_tua = ?")->execute([$ortuId]);
            $stRel = $pdo->prepare("INSERT IGNORE INTO orang_tua_siswa (id_orang_tua, id_siswa, hubungan, is_primary) VALUES (?, ?, ?, ?)");
            foreach ($siswaIds as $idx => $sid) {
                $stRel->execute([$ortuId, $sid, $hubungan ?: 'Orang Tua Kandung', $idx === 0 ? 1 : 0]);
            }

            // Jika sudah punya akun user, sinkronkan full_name & email
            $stChkU = $pdo->prepare("SELECT id_user FROM orang_tua WHERE id = ? LIMIT 1");
            $stChkU->execute([$ortuId]);
            $linkedUid = (int)$stChkU->fetchColumn();
            if ($linkedUid > 0) {
                $displayNama = $namaAyah ?: ($namaIbu ?: $namaWali);
                try {
                    $pdo->prepare("UPDATE users SET full_name = ? WHERE id = ?")->execute([$displayNama, $linkedUid]);
                    if ($emailGmail !== '') {
                        $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$emailGmail, $linkedUid]);
                    }
                } catch (Throwable $eU) {}
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Data orang tua & relasi ' . count($siswaIds) . ' anak berhasil disimpan.']);
            exit;
        }

        if ($act === 'create_account') {
            $id = (int)($_POST['id'] ?? 0);
            $stO = $pdo->prepare("SELECT * FROM orang_tua WHERE id = ? LIMIT 1");
            $stO->execute([$id]);
            $o = $stO->fetch(PDO::FETCH_ASSOC);
            if (!$o) {
                echo json_encode(['success' => false, 'message' => 'Data orang tua tidak ditemukan.']);
                exit;
            }
            if (!empty($o['id_user'])) {
                echo json_encode(['success' => false, 'message' => 'Orang tua ini sudah memiliki akun portal.']);
                exit;
            }

            $displayName = trim((string)($o['nama_ayah'] ?: ($o['nama_ibu'] ?: ($o['nama_wali'] ?: 'Orang Tua'))));
            $email = trim((string)($o['email_gmail'] ?? ''));

            // Buat username bersih dari gmail / nama
            $baseUser = '';
            if ($email !== '' && strpos($email, '@') !== false) {
                $baseUser = strtolower(preg_replace('/[^a-z0-9]/', '', strstr($email, '@', true)));
            }
            if ($baseUser === '') {
                $baseUser = 'ortu_' . strtolower(preg_replace('/[^a-z0-9]/', '', $displayName));
            }
            $baseUser = substr($baseUser, 0, 20);
            if ($baseUser === '') $baseUser = 'ortu' . $id;

            $username = $baseUser;
            $chkU = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $suffix = 1;
            while (true) {
                $chkU->execute([$username]);
                if (!$chkU->fetchColumn()) break;
                $username = $baseUser . $suffix++;
            }

            $finalEmail = $email !== '' ? $email : ($username . '@ortu.ilmuna.my.id');
            $chkE = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $chkE->execute([$finalEmail]);
            if ($chkE->fetchColumn()) {
                $finalEmail = $username . '_' . random_int(100, 999) . '@ortu.ilmuna.my.id';
            }

            $plainPass = 'Ortu' . random_int(100000, 999999);
            $passHash  = password_hash($plainPass, PASSWORD_BCRYPT);

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO users (username, email, password_hash, full_name, status) VALUES (?, ?, ?, ?, 'active')")
                ->execute([$username, $finalEmail, $passHash, $displayName]);
            $newUid = (int)$pdo->lastInsertId();

            $roleId = (int)$pdo->query("SELECT id FROM roles WHERE code = 'orang_tua' LIMIT 1")->fetchColumn();
            if ($roleId > 0) {
                $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)")->execute([$newUid, $roleId]);
            }
            $pdo->prepare("UPDATE orang_tua SET id_user = ? WHERE id = ?")->execute([$newUid, $id]);
            $pdo->commit();

            echo json_encode([
                'success'  => true,
                'message'  => "Akun Orang Tua berhasil dibuat!\nUsername: {$username}\nPassword: {$plainPass}",
                'username' => $username,
                'password' => $plainPass,
            ]);
            exit;
        }

        if ($act === 'reset_password') {
            $id = (int)($_POST['id'] ?? 0);
            $stO = $pdo->prepare("SELECT o.id_user, u.username FROM orang_tua o JOIN users u ON u.id = o.id_user WHERE o.id = ? LIMIT 1");
            $stO->execute([$id]);
            $o = $stO->fetch(PDO::FETCH_ASSOC);
            if (!$o) {
                echo json_encode(['success' => false, 'message' => 'Akun portal belum tersedia untuk orang tua ini.']);
                exit;
            }
            $newPass = 'Ortu' . random_int(100000, 999999);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($newPass, PASSWORD_BCRYPT), (int)$o['id_user']]);
            echo json_encode([
                'success' => true,
                'message' => "Password berhasil direset!\nUsername: {$o['username']}\nPassword Baru: {$newPass}",
            ]);
            exit;
        }

        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
                exit;
            }
            $pdo->prepare("DELETE FROM orang_tua_siswa WHERE id_orang_tua = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM orang_tua WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Data orang tua & relasi anak berhasil dihapus.']);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Kesalahan server: ' . $e->getMessage()]);
    }
    exit;
}

$pageTitle = 'Data Orang Tua / Wali';
$pageIcon  = 'solar:users-group-two-rounded-bold';
require_once BASE_PATH . '/includes/header.php';
require_once BASE_PATH . '/includes/table-template.php';
require_once BASE_PATH . '/includes/modal-template.php';
?>
<style>
.ortu-wrap { width: 100%; max-width: 100%; margin: 0 auto; }
.curriculum-main-tabs { display: flex; align-items: center; gap: 8px; background: #F1F5F9; border: 1px solid #E2E8F0; border-radius: 14px; padding: 6px; margin-bottom: 14px; overflow-x: auto; scrollbar-width: none; }
.curriculum-main-tabs::-webkit-scrollbar { display: none; }
.curriculum-tab-btn { display: inline-flex; align-items: center; gap: 8px; padding: 9px 15px; border-radius: 10px; border: 1px solid transparent; background: transparent; font-size: 13px; font-weight: 700; color: #475569; cursor: pointer; white-space: nowrap; transition: all .18s ease; font-family: inherit; }
.curriculum-tab-btn:hover { color: #1D3B5E; background: rgba(255, 255, 255, 0.65); }
.curriculum-tab-btn.is-active { background: #FFFFFF; color: #0D7A87; border-color: #CBD5E1; box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08); }
.curriculum-tab-counter { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 20px; padding: 0 7px; border-radius: 10px; font-size: 11px; font-weight: 800; background: #E2E8F0; color: #475569; }
.curriculum-tab-btn.is-active .curriculum-tab-counter { background: #CCFBF1; color: #0F766E; }
.ilmuna-filter-box { background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 16px; padding: 12px 14px; margin-bottom: 14px; box-shadow: 0 4px 16px -4px rgba(29, 59, 94, 0.05); }
.ilmuna-filter-topline { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
.ilmuna-search-wrap { position: relative; flex: 1; min-width: 220px; }
.ilmuna-search-wrap iconify-icon { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); color: #64748B; font-size: 16px; pointer-events: none; }
.ilmuna-search-input { width: 100%; height: 36px; border: 1.5px solid #CBD5E1; border-radius: 10px; padding: 0 12px 0 34px; font-size: 13px; color: #0F172A; background: #FFFFFF; font-family: inherit; box-sizing: border-box; }
.ilmuna-search-input:focus { outline: none; border-color: #0D7A87; box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12); }
.ilmuna-filter-actions { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ilmuna-nav-stat-inline { display: inline-flex; align-items: center; gap: 5px; height: 34px; padding: 0 11px; border-radius: 10px; background: #F8FAFC; border: 1px solid #E2E8F0; font-size: 12px; color: #475569; white-space: nowrap; }
.ilmuna-nav-stat-inline strong { color: #1D3B5E; font-weight: 800; }
.ilmuna-nav-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; height: 36px; padding: 0 14px; border-radius: 10px; font-size: 12.5px; font-weight: 700; cursor: pointer; border: none; transition: all .18s ease; white-space: nowrap; font-family: inherit; }
.ilmuna-nav-btn--primary { background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #FFFFFF; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.28); }
.ilmuna-nav-btn--primary:hover { transform: translateY(-1px); box-shadow: 0 6px 15px rgba(245, 158, 11, 0.38); }
.ilmuna-nav-btn--ghost { background: #FFFFFF; color: #1D3B5E; border: 1.5px solid #CBD5E1; }
.ilmuna-nav-btn--ghost:hover { background: #F8FAFC; border-color: #0D7A87; color: #0D7A87; }
.ortu-child-chip { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; background: rgba(13, 122, 135, 0.10); color: #0D7A87; border: 1px solid rgba(13, 122, 135, 0.22); margin: 2px 3px 2px 0; }
.ortu-acct-ok { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; background: rgba(22, 163, 74, 0.12); color: #15803D; border: 1px solid rgba(22, 163, 74, 0.25); }
.ortu-acct-no { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 700; background: #F1F5F9; color: #64748B; border: 1px solid #E2E8F0; }
.ortu-btn-sm { display: inline-flex; align-items: center; gap: 4px; height: 30px; padding: 0 10px; border-radius: 8px; font-size: 11.5px; font-weight: 700; cursor: pointer; border: none; font-family: inherit; transition: all .15s; }
.ortu-btn-acc { background: linear-gradient(135deg, #1D3B5E, #0D7A87); color: #fff; }
.ortu-btn-acc:hover { transform: translateY(-1px); }
.ortu-btn-reset { background: #fff; color: #1D3B5E; border: 1.5px solid #CBD5E1; }
.ortu-btn-reset:hover { border-color: #0D7A87; color: #0D7A87; }
.ilm-act-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; border: 1.5px solid #CBD5E1; background: #FFFFFF; color: #1D3B5E; cursor: pointer; transition: all .15s ease; }
.ilm-act-btn--edit:hover { border-color: #0D7A87; color: #0D7A87; background: #F0FDFA; }
.ilm-act-btn--delete:hover { border-color: #DC2626; color: #DC2626; background: #FEF2F2; }
.ortu-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.ortu-field { margin-bottom: 12px; }
.ortu-field label { display: block; font-size: 11.5px; font-weight: 700; color: #334155; margin-bottom: 5px; }
.ortu-input, .ortu-textarea { width: 100%; border: 1.5px solid #CBD5E1; border-radius: 10px; padding: 9px 11px; font-size: 13px; color: #0F172A; background: #fff; font-family: inherit; box-sizing: border-box; }
.ortu-input:focus, .ortu-textarea:focus { outline: none; border-color: #0D7A87; box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12); }
.ortu-siswa-picker { border: 1.5px solid #CBD5E1; border-radius: 12px; padding: 10px; background: #F8FAFC; max-height: 200px; overflow-y: auto; }
.ortu-siswa-opt { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 8px; cursor: pointer; font-size: 12.5px; color: #0F172A; }
.ortu-siswa-opt:hover { background: #E2E8F0; }
.ortu-siswa-opt input { accent-color: #0D7A87; width: 15px; height: 15px; cursor: pointer; }
@media (max-width: 768px) { .ortu-form-grid { grid-template-columns: 1fr; } }
</style>

<div class="ortu-wrap">
    <!-- 1. SEGMENTED TABS RESMI (ALA CURRICULUM.PHP) -->
    <div class="curriculum-main-tabs" id="ortuTabs" role="tablist">
        <button type="button" class="curriculum-tab-btn is-active" data-tab="all">
            <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
            <span class="curriculum-tab-label">Semua Orang Tua</span>
            <span class="curriculum-tab-counter" id="ortuCntAll">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="akun">
            <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
            <span class="curriculum-tab-label">Sudah Punya Akun</span>
            <span class="curriculum-tab-counter" id="ortuCntAkun">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="noakun">
            <iconify-icon icon="solar:user-plus-bold"></iconify-icon>
            <span class="curriculum-tab-label">Belum Punya Akun</span>
            <span class="curriculum-tab-counter" id="ortuCntNoAkun">0</span>
        </button>
        <button type="button" class="curriculum-tab-btn" data-tab="multi">
            <iconify-icon icon="solar:users-group-rounded-bold"></iconify-icon>
            <span class="curriculum-tab-label">Multi-Anak (2+)</span>
            <span class="curriculum-tab-counter" id="ortuCntMulti">0</span>
        </button>
    </div>

    <!-- 2. TOOLBAR SEARCH & TAMBAH -->
    <div class="ilmuna-filter-box">
        <div class="ilmuna-filter-topline">
            <div class="ilmuna-search-wrap" style="flex:1;">
                <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                <input type="text" id="ortuSearchInput" class="ilmuna-search-input" placeholder="Cari nama ayah/ibu, Gmail, nomor HP, nama anak, kelas, alamat..." autocomplete="off">
            </div>
            <div class="ilmuna-filter-actions">
                <div class="ilmuna-nav-stat-inline">
                    <iconify-icon icon="solar:users-group-two-rounded-bold" width="14"></iconify-icon>
                    <strong id="ortuKpiCount">0</strong>
                    <span>Keluarga</span>
                </div>
                <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" onclick="ORTU_ADM.openModal(null)">
                    <iconify-icon icon="solar:add-circle-bold" width="16"></iconify-icon>
                    <span>Tambah Orang Tua</span>
                </button>
            </div>
        </div>
    </div>

    <!-- 3. DATA TABLE -->
    <div class="ilm-data-container">
        <div class="ilm-data-gunungan" aria-hidden="true"></div>
        <div class="ilm-table-responsive">
            <table class="ilm-table">
                <thead>
                    <tr>
                        <th>Orang Tua / Wali</th>
                        <th>Kontak &amp; Gmail</th>
                        <th>Anak Terhubung (Multi-Anak)</th>
                        <th>Alamat</th>
                        <th>Akun Portal</th>
                        <th style="text-align:right;">Aksi</th>
                    </tr>
                </thead>
                <tbody id="ortuTbody">
                    <tr><td colspan="6" style="text-align:center;padding:34px;color:#64748B;">Memuat data orang tua...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL TAMBAH / EDIT ORANG TUA & RELASI MULTI-ANAK -->
<div class="ilm-modal-backdrop" id="modalOrtuAdm">
    <div class="ilm-modal-dialog" style="max-width:720px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon" style="background:linear-gradient(135deg,#1D3B5E,#0D7A87);">
                    <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title" id="ortuModalTitle">Tambah Data Orang Tua / Wali</h3>
                    <p class="ilm-modal-header__subtitle">Hubungkan ke 1, 2, atau lebih anak di sekolah yang sama</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('modalOrtuAdm')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>

        <form id="formOrtuAdm" onsubmit="ORTU_ADM.save(event)">
            <input type="hidden" id="ortuId" value="0">
            <div class="ilm-modal-body" style="max-height:calc(84vh - 150px);overflow-y:auto;">
                <div class="ortu-form-grid">
                    <div class="ortu-field">
                        <label for="ortuNamaAyah">Nama Ayah</label>
                        <input type="text" id="ortuNamaAyah" class="ortu-input" placeholder="Nama lengkap ayah">
                    </div>
                    <div class="ortu-field">
                        <label for="ortuHpAyah">No. HP / WA Ayah</label>
                        <input type="text" id="ortuHpAyah" class="ortu-input" placeholder="08xxxxxxxxxx">
                    </div>
                </div>
                <div class="ortu-form-grid">
                    <div class="ortu-field">
                        <label for="ortuNamaIbu">Nama Ibu</label>
                        <input type="text" id="ortuNamaIbu" class="ortu-input" placeholder="Nama lengkap ibu">
                    </div>
                    <div class="ortu-field">
                        <label for="ortuHpIbu">No. HP / WA Ibu</label>
                        <input type="text" id="ortuHpIbu" class="ortu-input" placeholder="08xxxxxxxxxx">
                    </div>
                </div>
                <div class="ortu-form-grid">
                    <div class="ortu-field">
                        <label for="ortuPekerjaanAyah">Pekerjaan Ayah (opsional)</label>
                        <input type="text" id="ortuPekerjaanAyah" class="ortu-input" placeholder="Wiraswasta / PNS / dsb">
                    </div>
                    <div class="ortu-field">
                        <label for="ortuPekerjaanIbu">Pekerjaan Ibu (opsional)</label>
                        <input type="text" id="ortuPekerjaanIbu" class="ortu-input" placeholder="Ibu Rumah Tangga / Guru / dsb">
                    </div>
                </div>
                <div class="ortu-form-grid">
                    <div class="ortu-field">
                        <label for="ortuGmail">Email Gmail Orang Tua</label>
                        <input type="email" id="ortuGmail" class="ortu-input" placeholder="orangtua@gmail.com">
                    </div>
                    <div class="ortu-field">
                        <label for="ortuHubungan">Hubungan Keluarga</label>
                        <select id="ortuHubungan" class="ortu-input">
                            <option value="Orang Tua Kandung">Orang Tua Kandung</option>
                            <option value="Wali">Wali Siswa</option>
                            <option value="Orang Tua Angkat">Orang Tua Angkat</option>
                        </select>
                    </div>
                </div>
                <div class="ortu-form-grid">
                    <div class="ortu-field">
                        <label for="ortuNamaWali">Nama Wali (opsional)</label>
                        <input type="text" id="ortuNamaWali" class="ortu-input" placeholder="Diisi jika diwakilkan wali">
                    </div>
                    <div class="ortu-field">
                        <label for="ortuHpWali">No. HP Wali (opsional)</label>
                        <input type="text" id="ortuHpWali" class="ortu-input" placeholder="08xxxxxxxxxx">
                    </div>
                </div>

                <div class="ortu-field">
                    <label for="ortuAlamat">Alamat Lengkap</label>
                    <textarea id="ortuAlamat" class="ortu-textarea" style="min-height:60px;" placeholder="Jalan, Dusun, RT/RW, Desa/Kelurahan, Kecamatan, Kabupaten/Kota"></textarea>
                </div>

                <div class="ortu-field" style="margin-bottom:0;">
                    <label>Hubungkan ke Siswa / Anak (Centang 1, 2, atau lebih anak)</label>
                    <input type="text" id="ortuSiswaSearch" class="ortu-input" placeholder="Ketik nama siswa, NIS, atau kelas untuk mencari..." style="margin-bottom:8px;" oninput="ORTU_ADM.filterSiswaPicker(this.value)">
                    <div class="ortu-siswa-picker" id="ortuSiswaPicker">
                        <?php foreach ($allSiswa as $s): ?>
                            <label class="ortu-siswa-opt" data-search="<?= htmlspecialchars(strtolower($s['nama_lengkap'] . ' ' . $s['nis'] . ' ' . $s['nama_kelas']), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="checkbox" name="siswa_ids[]" value="<?= (int)$s['id'] ?>">
                                <span><strong><?= htmlspecialchars($s['nama_lengkap'], ENT_QUOTES, 'UTF-8') ?></strong> <small style="color:#64748B;">(NIS: <?= htmlspecialchars($s['nis'] ?: '-', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($s['nama_kelas'], ENT_QUOTES, 'UTF-8') ?>)</small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('modalOrtuAdm')">Batal</button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:disk-bold"></iconify-icon>
                    <span>Simpan Data Orang Tua</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';
    var API_URL = <?= json_encode(BASE_URL . '/dashboard/admin/orang-tua?api=1') ?>;
    var STATE = { items: [], tab: 'all' };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    async function fetchJsonSafe(url, opts) {
        var reqOpts = Object.assign({ credentials: 'same-origin' }, opts || {});
        reqOpts.headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, reqOpts.headers || {});
        var r = await fetch(url, reqOpts);
        return await r.json();
    }

    window.ORTU_ADM = {
        loadData: async function () {
            try {
                var res = await fetchJsonSafe(API_URL + '&api_action=list');
                if (res && res.success) {
                    STATE.items = res.data || [];
                    var c = res.counts || { all: 0, akun: 0, noakun: 0, multi: 0 };
                    document.getElementById('ortuCntAll').textContent = c.all;
                    document.getElementById('ortuCntAkun').textContent = c.akun;
                    document.getElementById('ortuCntNoAkun').textContent = c.noakun;
                    document.getElementById('ortuCntMulti').textContent = c.multi;
                    ORTU_ADM.render();
                } else {
                    Ilmuna.error('Gagal Memuat', (res && res.message) || 'Tidak dapat mengambil data orang tua.');
                }
            } catch (e) {
                Ilmuna.error('Gagal Memuat', 'Koneksi ke server terganggu.');
            }
        },
        render: function () {
            var q = (document.getElementById('ortuSearchInput').value || '').toLowerCase().trim();
            var list = STATE.items.filter(function (x) {
                if (STATE.tab === 'akun' && !x.id_user) return false;
                if (STATE.tab === 'noakun' && x.id_user) return false;
                if (STATE.tab === 'multi' && (!x.anak_list || x.anak_list.length < 2)) return false;
                if (q) {
                    var anakStr = (x.anak_list || []).map(function (a) { return a.nama_lengkap + ' ' + a.nis + ' ' + a.nama_kelas; }).join(' ');
                    var hay = ((x.nama_ayah || '') + ' ' + (x.nama_ibu || '') + ' ' + (x.nama_wali || '') + ' ' + (x.email_gmail || '') + ' ' + (x.no_hp_ayah || '') + ' ' + (x.no_hp_ibu || '') + ' ' + (x.alamat || '') + ' ' + anakStr).toLowerCase();
                    if (hay.indexOf(q) === -1) return false;
                }
                return true;
            });
            document.getElementById('ortuKpiCount').textContent = list.length;
            var tb = document.getElementById('ortuTbody');
            if (!list.length) {
                tb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:34px;color:#64748B;">Belum ada data orang tua / wali. Klik <strong>Tambah Orang Tua</strong> untuk menambahkan.</td></tr>';
                return;
            }
            tb.innerHTML = list.map(function (r) {
                var ortuNames = [];
                if (r.nama_ayah) ortuNames.push('<strong>Ayah:</strong> ' + esc(r.nama_ayah));
                if (r.nama_ibu) ortuNames.push('<strong>Ibu:</strong> ' + esc(r.nama_ibu));
                if (r.nama_wali) ortuNames.push('<strong>Wali:</strong> ' + esc(r.nama_wali));

                var kontak = [];
                if (r.email_gmail) kontak.push('<div style="color:#0D7A87;font-weight:600;">' + esc(r.email_gmail) + '</div>');
                if (r.no_hp_ayah) kontak.push('<div style="font-size:11.5px;color:#475569;">WA Ayah: ' + esc(r.no_hp_ayah) + '</div>');
                if (r.no_hp_ibu) kontak.push('<div style="font-size:11.5px;color:#475569;">WA Ibu: ' + esc(r.no_hp_ibu) + '</div>');

                var anakHtml = (r.anak_list || []).length
                    ? r.anak_list.map(function (a) {
                        return '<span class="ortu-child-chip">' + esc(a.nama_lengkap) + ' · ' + esc(a.nama_kelas) + '</span>';
                    }).join('')
                    : '<span style="font-size:12px;color:#94A3B8;">Belum terhubung</span>';

                var akunHtml = r.id_user
                    ? '<span class="ortu-acct-ok">@' + esc(r.akun_username || 'ortu') + '</span>'
                    : '<span class="ortu-acct-no">Belum Ada Akun</span>';

                var akunBtn = r.id_user
                    ? '<button type="button" class="ortu-btn-sm ortu-btn-reset" title="Reset Password" onclick="ORTU_ADM.resetPassword(' + r.id + ')"><iconify-icon icon="solar:key-bold" width="13"></iconify-icon><span>Reset PW</span></button>'
                    : '<button type="button" class="ortu-btn-sm ortu-btn-acc" title="Buat Akun Portal Orang Tua" onclick="ORTU_ADM.createAccount(' + r.id + ')"><iconify-icon icon="solar:user-plus-bold" width="13"></iconify-icon><span>Buat Akun</span></button>';

                return '<tr>'
                    + '<td data-label="Orang Tua">' + ortuNames.join('<br>') + '</td>'
                    + '<td data-label="Kontak">' + (kontak.length ? kontak.join('') : '-') + '</td>'
                    + '<td data-label="Anak Terhubung">' + anakHtml + '</td>'
                    + '<td data-label="Alamat"><div style="font-size:12px;color:#475569;max-width:220px;">' + esc(r.alamat || '-') + '</div></td>'
                    + '<td data-label="Akun">' + akunHtml + '</td>'
                    + '<td data-label="Aksi" style="text-align:right;"><div style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">'
                        + akunBtn
                        + '<button type="button" class="ilm-act-btn ilm-act-btn--edit" title="Edit" onclick="ORTU_ADM.openModal(' + r.id + ')"><iconify-icon icon="solar:pen-bold" width="14"></iconify-icon></button>'
                        + '<button type="button" class="ilm-act-btn ilm-act-btn--delete" title="Hapus" onclick="ORTU_ADM.remove(' + r.id + ')"><iconify-icon icon="solar:trash-bin-trash-bold" width="14"></iconify-icon></button>'
                    + '</div></td>'
                    + '</tr>';
            }).join('');
        },
        filterSiswaPicker: function (q) {
            var ql = (q || '').toLowerCase().trim();
            document.querySelectorAll('#ortuSiswaPicker .ortu-siswa-opt').forEach(function (el) {
                var hay = el.getAttribute('data-search') || '';
                el.style.display = (!ql || hay.indexOf(ql) !== -1) ? 'flex' : 'none';
            });
        },
        openModal: function (id) {
            document.getElementById('formOrtuAdm').reset();
            document.getElementById('ortuSiswaSearch').value = '';
            ORTU_ADM.filterSiswaPicker('');
            document.querySelectorAll('#ortuSiswaPicker input[type="checkbox"]').forEach(function (cb) { cb.checked = false; });

            if (id) {
                var r = STATE.items.find(function (x) { return parseInt(x.id, 10) === parseInt(id, 10); });
                if (!r) return;
                document.getElementById('ortuModalTitle').textContent = 'Edit Data Orang Tua / Wali';
                document.getElementById('ortuId').value = String(r.id);
                document.getElementById('ortuNamaAyah').value = r.nama_ayah || '';
                document.getElementById('ortuHpAyah').value = r.no_hp_ayah || '';
                document.getElementById('ortuPekerjaanAyah').value = r.pekerjaan_ayah || '';
                document.getElementById('ortuNamaIbu').value = r.nama_ibu || '';
                document.getElementById('ortuHpIbu').value = r.no_hp_ibu || '';
                document.getElementById('ortuPekerjaanIbu').value = r.pekerjaan_ibu || '';
                document.getElementById('ortuNamaWali').value = r.nama_wali || '';
                document.getElementById('ortuHpWali').value = r.no_hp_wali || '';
                document.getElementById('ortuGmail').value = r.email_gmail || '';
                document.getElementById('ortuAlamat').value = r.alamat || '';
                var anakIds = (r.anak_list || []).map(function (a) { return String(a.id_siswa); });
                if (r.anak_list && r.anak_list[0] && r.anak_list[0].hubungan) {
                    document.getElementById('ortuHubungan').value = r.anak_list[0].hubungan;
                }
                document.querySelectorAll('#ortuSiswaPicker input[type="checkbox"]').forEach(function (cb) {
                    cb.checked = anakIds.indexOf(cb.value) !== -1;
                });
            } else {
                document.getElementById('ortuModalTitle').textContent = 'Tambah Data Orang Tua / Wali';
                document.getElementById('ortuId').value = '0';
            }
            openIlmunaModal('modalOrtuAdm');
        },
        save: async function (e) {
            e.preventDefault();
            var fd = new FormData();
            fd.append('api_action', 'save');
            fd.append('id', document.getElementById('ortuId').value);
            fd.append('nama_ayah', document.getElementById('ortuNamaAyah').value.trim());
            fd.append('no_hp_ayah', document.getElementById('ortuHpAyah').value.trim());
            fd.append('pekerjaan_ayah', document.getElementById('ortuPekerjaanAyah').value.trim());
            fd.append('nama_ibu', document.getElementById('ortuNamaIbu').value.trim());
            fd.append('no_hp_ibu', document.getElementById('ortuHpIbu').value.trim());
            fd.append('pekerjaan_ibu', document.getElementById('ortuPekerjaanIbu').value.trim());
            fd.append('nama_wali', document.getElementById('ortuNamaWali').value.trim());
            fd.append('no_hp_wali', document.getElementById('ortuHpWali').value.trim());
            fd.append('email_gmail', document.getElementById('ortuGmail').value.trim());
            fd.append('hubungan', document.getElementById('ortuHubungan').value);
            fd.append('alamat', document.getElementById('ortuAlamat').value.trim());
            document.querySelectorAll('#ortuSiswaPicker input[type="checkbox"]:checked').forEach(function (cb) {
                fd.append('siswa_ids[]', cb.value);
            });

            try {
                var res = await fetchJsonSafe(API_URL, { method: 'POST', body: fd });
                if (res && res.success) {
                    closeIlmunaModal('modalOrtuAdm');
                    Ilmuna.success('Tersimpan', res.message);
                    ORTU_ADM.loadData();
                } else {
                    Ilmuna.error('Gagal', (res && res.message) || 'Gagal menyimpan data orang tua.');
                }
            } catch (err) {
                Ilmuna.error('Kesalahan', 'Gagal menghubungi server.');
            }
        },
        createAccount: async function (id) {
            var ok = await Ilmuna.confirm('info', 'Buat Akun Portal Orang Tua?', 'Sistem akan membuatkan username dan password awal dengan role Orang Tua / Wali.', 'Ya, Buat Akun', 'Batal');
            if (!ok) return;
            var fd = new FormData();
            fd.append('api_action', 'create_account');
            fd.append('id', id);
            try {
                var res = await fetchJsonSafe(API_URL, { method: 'POST', body: fd });
                if (res && res.success) {
                    Ilmuna.success('Akun Dibuat', res.message);
                    ORTU_ADM.loadData();
                } else {
                    Ilmuna.error('Gagal', (res && res.message) || 'Gagal membuat akun.');
                }
            } catch (err) {
                Ilmuna.error('Kesalahan', 'Gagal menghubungi server.');
            }
        },
        resetPassword: async function (id) {
            var ok = await Ilmuna.confirm('warning', 'Reset Password Akun Orang Tua?', 'Password baru akan digenerate secara otomatis untuk akun ini.', 'Ya, Reset', 'Batal');
            if (!ok) return;
            var fd = new FormData();
            fd.append('api_action', 'reset_password');
            fd.append('id', id);
            try {
                var res = await fetchJsonSafe(API_URL, { method: 'POST', body: fd });
                if (res && res.success) {
                    Ilmuna.success('Password Direset', res.message);
                } else {
                    Ilmuna.error('Gagal', (res && res.message) || 'Gagal mereset password.');
                }
            } catch (err) {
                Ilmuna.error('Kesalahan', 'Gagal menghubungi server.');
            }
        },
        remove: async function (id) {
            var ok = await Ilmuna.confirm('error', 'Hapus Data Orang Tua?', 'Data orang tua dan relasi anaknya akan dihapus dari database.', 'Ya, Hapus', 'Batal');
            if (!ok) return;
            var fd = new FormData();
            fd.append('api_action', 'delete');
            fd.append('id', id);
            try {
                var res = await fetchJsonSafe(API_URL, { method: 'POST', body: fd });
                if (res && res.success) {
                    Ilmuna.success('Terhapus', res.message);
                    ORTU_ADM.loadData();
                } else {
                    Ilmuna.error('Gagal', (res && res.message) || 'Gagal menghapus data.');
                }
            } catch (err) {
                Ilmuna.error('Kesalahan', 'Gagal menghubungi server.');
            }
        }
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('#ortuTabs .curriculum-tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                STATE.tab = btn.getAttribute('data-tab');
                document.querySelectorAll('#ortuTabs .curriculum-tab-btn').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
                ORTU_ADM.render();
            });
        });
        document.getElementById('ortuSearchInput').addEventListener('input', ORTU_ADM.render);
        ORTU_ADM.loadData();
    });
})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
