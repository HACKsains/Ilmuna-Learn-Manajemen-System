<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — LUPA KATA SANDI (FORGOT PASSWORD)
 * ==============================================================================
 * Lokasi: forgot-password.php
 * Memungkinkan siswa, guru, dan admin meminta tautan pemulihan kata sandi via email.
 * Tema: Navy (#1D3B5E), Teal (#0D7A87), Sky Blue (#52B2DC).
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email-reset-password-template.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDBConnection();
ilmuna_ensure_password_resets_table($pdo);

$errorMsg   = '';
$successMsg = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');

    if (empty($identifier)) {
        $errorMsg = 'Silakan masukkan Email, Username, NISN, atau NIP Anda.';
    } else {
        // Cari pengguna berdasarkan email, email_google, username, atau nisn_nip
        $sql = "SELECT u.id, u.username, u.full_name, u.email, u.email_google, u.nisn_nip,
                       s.gmail AS siswa_gmail, g.email AS guru_email
                FROM users u
                LEFT JOIN siswa s ON (s.id_user = u.id OR s.nis = u.username OR s.nisn = u.username)
                LEFT JOIN guru g ON (g.id_user = u.id OR g.nip = u.username)
                WHERE u.email = ? 
                   OR u.email_google = ? 
                   OR u.username = ? 
                   OR u.nisn_nip = ? 
                   OR s.gmail = ? 
                   OR g.email = ?
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$identifier, $identifier, $identifier, $identifier, $identifier, $identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Demi keamanan dan privasi, tampilkan pesan informatif tanpa membocorkan eksistensi data
            $errorMsg = 'Akun dengan identitas tersebut tidak ditemukan dalam sistem. Pastikan data yang dimasukkan sudah benar.';
        } else {
            // Cari alamat email aktif yang valid
            $targetEmail = '';

            $candidates = [
                $user['email_google'],
                $user['siswa_gmail'],
                $user['guru_email'],
                $user['email']
            ];

            foreach ($candidates as $cand) {
                $cand = trim((string)$cand);
                if (!empty($cand) && filter_var($cand, FILTER_VALIDATE_EMAIL)) {
                    // Hindari email dummy default
                    if (substr($cand, -14) !== '@ilmuna.sch.id') {
                        $targetEmail = $cand;
                        break;
                    }
                }
            }

            if (empty($targetEmail)) {
                $errorMsg = 'Akun Anda (' . htmlspecialchars($user['username']) . ') belum memiliki alamat Gmail atau email pribadi terdaftar. Silakan hubungi Administrator sekolah atau Wali Kelas untuk mengatur ulang kata sandi Anda secara langsung.';
            } else {
                // Hapus token reset lama yang belum dipakai untuk user ini
                $pdo->prepare("DELETE FROM `password_resets` WHERE `user_id` = ? AND `used_at` IS NULL")->execute([$user['id']]);

                // Buat token reset kriptografis baru
                $token     = bin2hex(random_bytes(32));
                $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 60 menit
                $createdAt = date('Y-m-d H:i:s');
                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';

                $ins = $pdo->prepare("INSERT INTO `password_resets` (`user_id`, `email`, `token`, `expires_at`, `created_at`, `ip_address`) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$user['id'], $targetEmail, $token, $expiresAt, $createdAt, $ipAddress]);

                $baseUrl   = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://learn.ilmuna.my.id';
                $resetUrl  = $baseUrl . '/reset-password.php?token=' . $token;
                $userName  = !empty($user['full_name']) ? $user['full_name'] : $user['username'];

                $mailRes = ilmuna_send_password_reset_email($targetEmail, $userName, $resetUrl, 60);

                if ($mailRes['success']) {
                    // Sensor email (contoh: sy***@gmail.com)
                    $parts = explode('@', $targetEmail);
                    $maskedUser = substr($parts[0], 0, 2) . str_repeat('*', max(3, strlen($parts[0]) - 2));
                    $maskedEmail = $maskedUser . '@' . ($parts[1] ?? 'gmail.com');

                    $successMsg = 'Tautan pengaturan ulang kata sandi telah berhasil dikirimkan ke <strong>' . htmlspecialchars($maskedEmail) . '</strong>. Silakan periksa Kotak Masuk atau folder Spam di Gmail Anda.';
                } else {
                    $errorMsg = 'Gagal mengirimkan email pemulihan: ' . htmlspecialchars($mailRes['message']) . '. Silakan coba beberapa saat lagi atau hubungi administrator.';
                }
            }
        }
    }
}

$siteName = defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn';
$siteTag  = defined('APP_TAGLINE') ? APP_TAGLINE : 'Media Pembelajaran Digital Pendidikan Agama Islam';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Kata Sandi — <?= htmlspecialchars($siteName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js"></script>
    <?= function_exists('renderFavicon') ? renderFavicon() : '' ?>

    <style>
        :root {
            --navy: #1D3B5E;
            --navy-dark: #152C47;
            --teal: #0D7A87;
            --sky: #52B2DC;
            --font-head: 'Outfit', sans-serif;
            --font-body: 'Plus Jakarta Sans', sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: var(--font-body);
        }

        body {
            background: linear-gradient(135deg, #F8FAFC 0%, #E2E8F0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #0F1D2F;
        }

        .forgot-card {
            background: #FFFFFF;
            width: 100%;
            max-width: 460px;
            border-radius: 24px;
            box-shadow: 0 20px 40px -15px rgba(29, 59, 94, 0.15);
            border: 1px solid #E2E8F0;
            overflow: hidden;
            animation: slideUp 0.3s ease-out;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .card-header {
            background: linear-gradient(135deg, var(--navy) 0%, var(--teal) 100%);
            padding: 32px 28px 28px;
            text-align: center;
            color: #FFFFFF;
            position: relative;
        }

        .card-logo {
            width: 58px;
            height: 58px;
            margin-bottom: 12px;
            border-radius: 12px;
            object-fit: contain;
        }

        .card-title {
            font-family: var(--font-head);
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 4px;
            letter-spacing: -0.3px;
        }

        .card-subtitle {
            font-size: 13.5px;
            color: #E2E8F0;
            line-height: 1.4;
        }

        .card-body {
            padding: 32px 28px;
        }

        .alert-box {
            padding: 14px 16px;
            border-radius: 12px;
            font-size: 13.5px;
            line-height: 1.5;
            margin-bottom: 20px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .alert-error {
            background: #FEF2F2;
            border: 1px solid #FECACA;
            color: #991B1B;
        }

        .alert-success {
            background: #ECFDF5;
            border: 1px solid #A7F3D0;
            color: #065F46;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-label {
            display: block;
            font-size: 13.5px;
            font-weight: 600;
            color: #1E293B;
            margin-bottom: 8px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            color: #64748B;
            font-size: 20px;
            pointer-events: none;
        }

        .form-input {
            width: 100%;
            padding: 13px 14px 13px 44px;
            font-size: 14px;
            border: 1.5px solid #CBD5E1;
            border-radius: 12px;
            outline: none;
            transition: all 0.2s ease;
            color: #0F1D2F;
            background: #F8FAFC;
        }

        .form-input:focus {
            border-color: var(--teal);
            background: #FFFFFF;
            box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.15);
        }

        .btn-submit {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, var(--teal) 0%, var(--navy) 100%);
            border: none;
            border-radius: 12px;
            color: #FFFFFF;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(13, 122, 135, 0.25);
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-submit:hover {
            opacity: 0.95;
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(13, 122, 135, 0.35);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .card-footer {
            padding: 18px 28px 24px;
            background: #F8FAFC;
            border-top: 1px solid #E2E8F0;
            text-align: center;
            font-size: 13.5px;
        }

        .back-link {
            color: var(--teal);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s ease;
        }

        .back-link:hover {
            color: var(--navy);
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <div class="forgot-card">
        <div class="card-header">
            <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="Logo" class="card-logo" onerror="this.style.display='none'">
            <h1 class="card-title"><?= htmlspecialchars($siteName) ?></h1>
            <p class="card-subtitle">Pemulihan & Reset Kata Sandi Akun</p>
        </div>

        <div class="card-body">
            <?php if (!empty($errorMsg)): ?>
                <div class="alert-box alert-error">
                    <iconify-icon icon="solar:danger-triangle-bold" width="22" style="flex-shrink:0; margin-top:1px;"></iconify-icon>
                    <div><?= $errorMsg ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($successMsg)): ?>
                <div class="alert-box alert-success">
                    <iconify-icon icon="solar:check-circle-bold" width="22" style="flex-shrink:0; margin-top:1px;"></iconify-icon>
                    <div><?= $successMsg ?></div>
                </div>
            <?php endif; ?>

            <p style="font-size: 13.5px; color: #64748B; margin-bottom: 20px; line-height: 1.5;">
                Masukkan Email terdaftar, Username, NISN (Siswa), atau NIP (Guru). Kami akan mengirimkan tautan reset kata sandi ke Gmail Anda.
            </p>

            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label" for="identifier">Identitas Akun</label>
                    <div class="input-wrapper">
                        <iconify-icon icon="solar:user-bold" class="input-icon"></iconify-icon>
                        <input type="text" id="identifier" name="identifier" class="form-input" required 
                               placeholder="Email, Username, NISN, atau NIP" 
                               value="<?= htmlspecialchars($identifier) ?>" autofocus>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="btnSubmit">
                    <iconify-icon icon="solar:plain-bold" width="18"></iconify-icon>
                    <span>Kirim Tautan Pemulihan</span>
                </button>
            </form>
        </div>

        <div class="card-footer">
            <a href="<?= BASE_URL ?>/login" class="back-link">
                <iconify-icon icon="solar:arrow-left-bold" width="16"></iconify-icon>
                <span>Kembali ke Halaman Masuk</span>
            </a>
        </div>
    </div>

</body>
</html>
