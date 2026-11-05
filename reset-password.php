<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — ATUR ULANG KATA SANDI (RESET PASSWORD FORM)
 * ==============================================================================
 * Lokasi: reset-password.php
 * Halaman tujuan saat pengguna mengklik tautan dari email Gmail.
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/email-reset-password-template.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDBConnection();
ilmuna_ensure_password_resets_table($pdo);

$token   = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$state   = 'form'; // 'form' | 'success' | 'invalid'
$errorMsg = '';
$user     = null;
$resetRow = null;

if (empty($token) || strlen($token) < 20) {
    $state    = 'invalid';
    $errorMsg = 'Tautan reset kata sandi tidak valid atau tidak lengkap.';
} else {
    // Cari token di database
    $stmt = $pdo->prepare("SELECT r.*, u.id as uid, u.username, u.full_name 
                           FROM password_resets r 
                           JOIN users u ON r.user_id = u.id 
                           WHERE r.token = ? 
                           LIMIT 1");
    $stmt->execute([$token]);
    $resetRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$resetRow) {
        $state    = 'invalid';
        $errorMsg = 'Tautan reset kata sandi tidak ditemukan atau sudah tidak berlaku.';
    } elseif (!empty($resetRow['used_at'])) {
        $state    = 'invalid';
        $errorMsg = 'Tautan reset kata sandi ini sudah pernah digunakan sebelumnya.';
    } elseif (strtotime($resetRow['expires_at']) < time()) {
        $state    = 'invalid';
        $errorMsg = 'Tautan reset kata sandi telah kedaluwarsa (berlaku selama 60 menit). Silakan minta tautan baru.';
    } else {
        $user = $resetRow;

        // Proses submit kata sandi baru
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $newPassword     = trim($_POST['new_password'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');

            if (empty($newPassword) || strlen($newPassword) < 6) {
                $errorMsg = 'Kata sandi baru minimal harus terdiri dari 6 karakter.';
            } elseif ($newPassword !== $confirmPassword) {
                $errorMsg = 'Konfirmasi kata sandi tidak cocok. Harap periksa kembali.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $updUser = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $updUser->execute([$hash, (int)$user['uid']]);

                    $updToken = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
                    $updToken->execute([(int)$resetRow['id']]);

                    $pdo->commit();
                    $state = 'success';
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errorMsg = 'Terjadi kesalahan sistem saat memperbarui kata sandi: ' . $e->getMessage();
                }
            }
        }
    }
}

$siteName = defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Ulang Kata Sandi — <?= htmlspecialchars($siteName) ?></title>
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

        .reset-card {
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
            margin-bottom: 20px;
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
            padding: 13px 44px 13px 44px;
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

        .toggle-pwd {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            cursor: pointer;
            color: #64748B;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-pwd:hover {
            color: var(--navy);
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
            text-decoration: none;
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
        }

        .back-link:hover {
            color: var(--navy);
            text-decoration: underline;
        }
    </style>
</head>
<body>

    <div class="reset-card">
        <div class="card-header">
            <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="Logo" class="card-logo" onerror="this.style.display='none'">
            <h1 class="card-title"><?= htmlspecialchars($siteName) ?></h1>
            <p class="card-subtitle">Buat Kata Sandi Baru</p>
        </div>

        <div class="card-body">
            <?php if ($state === 'success'): ?>
                <div class="alert-box alert-success">
                    <iconify-icon icon="solar:check-circle-bold" width="24" style="flex-shrink:0; margin-top:2px;"></iconify-icon>
                    <div>
                        <strong>Kata Sandi Berhasil Diperbarui!</strong><br>
                        Kata sandi akun Anda telah sukses diubah. Silakan masuk kembali dengan kata sandi baru Anda.
                    </div>
                </div>

                <a href="<?= BASE_URL ?>/login" class="btn-submit">
                    <iconify-icon icon="solar:login-2-bold" width="18"></iconify-icon>
                    <span>Masuk ke Akun Sekarang</span>
                </a>

            <?php elseif ($state === 'invalid'): ?>
                <div class="alert-box alert-error">
                    <iconify-icon icon="solar:danger-triangle-bold" width="24" style="flex-shrink:0; margin-top:2px;"></iconify-icon>
                    <div>
                        <strong>Tautan Tidak Berlaku</strong><br>
                        <?= htmlspecialchars($errorMsg) ?>
                    </div>
                </div>

                <a href="<?= BASE_URL ?>/forgot-password.php" class="btn-submit">
                    <iconify-icon icon="solar:restart-bold" width="18"></iconify-icon>
                    <span>Minta Tautan Baru</span>
                </a>

            <?php else: ?>

                <?php if (!empty($errorMsg)): ?>
                    <div class="alert-box alert-error">
                        <iconify-icon icon="solar:danger-triangle-bold" width="22" style="flex-shrink:0; margin-top:1px;"></iconify-icon>
                        <div><?= htmlspecialchars($errorMsg) ?></div>
                    </div>
                <?php endif; ?>

                <div style="margin-bottom: 20px;">
                    <p style="font-size: 14px; color: #475569;">
                        Akun: <strong style="color: var(--navy);"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></strong> (@<?= htmlspecialchars($user['username']) ?>)
                    </p>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

                    <div class="form-group">
                        <label class="form-label" for="new_password">Kata Sandi Baru</label>
                        <div class="input-wrapper">
                            <iconify-icon icon="solar:lock-bold" class="input-icon"></iconify-icon>
                            <input type="password" id="new_password" name="new_password" class="form-input" required minlength="6" placeholder="Minimal 6 karakter" autofocus>
                            <button type="button" class="toggle-pwd" onclick="togglePwd('new_password', this)">
                                <iconify-icon icon="solar:eye-bold" width="18"></iconify-icon>
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Konfirmasi Kata Sandi Baru</label>
                        <div class="input-wrapper">
                            <iconify-icon icon="solar:lock-keyhole-bold" class="input-icon"></iconify-icon>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-input" required minlength="6" placeholder="Ulangi kata sandi baru">
                            <button type="button" class="toggle-pwd" onclick="togglePwd('confirm_password', this)">
                                <iconify-icon icon="solar:eye-bold" width="18"></iconify-icon>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-submit">
                        <iconify-icon icon="solar:shield-check-bold" width="18"></iconify-icon>
                        <span>Simpan Kata Sandi Baru</span>
                    </button>
                </form>

            <?php endif; ?>
        </div>

        <div class="card-footer">
            <a href="<?= BASE_URL ?>/login" class="back-link">
                <iconify-icon icon="solar:arrow-left-bold" width="16"></iconify-icon>
                <span>Kembali ke Halaman Masuk</span>
            </a>
        </div>
    </div>

    <script>
        function togglePwd(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('iconify-icon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.setAttribute('icon', 'solar:eye-closed-bold');
            } else {
                input.type = 'password';
                icon.setAttribute('icon', 'solar:eye-bold');
            }
        }
    </script>

</body>
</html>
