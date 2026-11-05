<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — VERIFIKASI PENAUTAN AKUN GOOGLE (NUSANTARA TOUCH)
 * ==============================================================================
 * Lokasi: verify-google.php
 * Endpoint yang diakses pengguna saat mengklik tombol/link verifikasi di Gmail.
 * Setelah diverifikasi, data email_google & google_id resmi disimpan ke database (tabel users).
 * 
 * Standar Desain:
 * - Sesuai dengan universal template Ilmuna (includes/modal-template.php & alert.js)
 * - Ornamen Nusantara: Watermark Gunungan Wayang, Tekstur Batik Kawung, Batik Sudut Klasik,
 *   dan Divider Ketupat Batik.
 * - Sudut Card Melingkar Konsisten: border-radius: 28px (ultra-smooth).
 * - Palet Warna Resmi Ilmuna: Navy (#1D3B5E), Teal (#0D7A87), Sky Blue (#52B2DC).
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = getDBConnection();

$token = trim($_GET['token'] ?? '');
$status = 'error'; // 'success' | 'already_verified' | 'expired' | 'error'
$message = '';
$emailGoogle = '';
$userName = '';
$userId = 0;

if (empty($token) || strlen($token) < 20) {
    $status = 'error';
    $message = 'Token verifikasi tidak valid atau tidak lengkap. Silakan periksa kembali tautan yang dikirimkan ke kotak masuk Gmail Anda.';
} else {
    // Ambil data token dari tabel google_link_verifications
    $stmt = $pdo->prepare("SELECT v.*, u.username, u.full_name, u.email as user_email FROM google_link_verifications v JOIN users u ON v.user_id = u.id WHERE v.token = ? LIMIT 1");
    $stmt->execute([$token]);
    $verif = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$verif) {
        $status = 'error';
        $message = 'Tautan verifikasi tidak ditemukan atau sudah kedaluwarsa. Silakan lakukan proses penautan ulang dari menu Profil.';
    } elseif (!empty($verif['verified_at'])) {
        $status = 'already_verified';
        $emailGoogle = $verif['email_google'];
        $userName = $verif['full_name'] ?: $verif['username'];
        $message = 'Akun Google ini sudah berhasil diverifikasi sebelumnya. Anda sudah dapat menggunakannya untuk masuk secara instan.';
    } elseif (strtotime($verif['expires_at']) < time()) {
        $status = 'expired';
        $emailGoogle = $verif['email_google'];
        $userName = $verif['full_name'] ?: $verif['username'];
        $message = 'Tautan verifikasi telah kedaluwarsa (berlaku selama 60 menit). Silakan lakukan penautan ulang dari menu Profil portal Anda.';
    } else {
        // Token valid! Cek apakah akun Google ini sudah ditautkan ke pengguna lain
        $userId = (int)$verif['user_id'];
        $emailGoogle = $verif['email_google'];
        $googleId = $verif['google_id'] ?: ('goog_' . md5(strtolower($emailGoogle) . '_ilmuna_sso'));
        $userName = $verif['full_name'] ?: $verif['username'];

        $chk = $pdo->prepare("SELECT id, username FROM users WHERE (google_id = ? OR email_google = ?) AND id != ? LIMIT 1");
        $chk->execute([$googleId, $emailGoogle, $userId]);
        $claimed = $chk->fetch(PDO::FETCH_ASSOC);

        if ($claimed) {
            $status = 'error';
            $message = 'Akun Google ini telah terhubung ke pengguna lain (@' . htmlspecialchars($claimed['username']) . '). Tidak dapat ditautkan ke akun Anda.';
        } else {
            // Update tabel users
            try {
                $pdo->beginTransaction();

                $updUser = $pdo->prepare("UPDATE users SET email_google = ?, google_id = ? WHERE id = ?");
                $updUser->execute([$emailGoogle, $googleId, $userId]);

                // Sinkronkan ke siswa.gmail jika tabel siswa ada dan siswa terkait ditemukan
                try {
                    $sStmt = $pdo->prepare("UPDATE siswa SET gmail = ? WHERE id_user = ? OR nisn = ? OR nis = ?");
                    $sStmt->execute([$emailGoogle, $userId, $verif['username'], $verif['username']]);
                } catch (Exception $e) {
                    // Abaikan jika kolom atau tabel siswa belum siap
                }

                // Tandai token sebagai telah diverifikasi
                $updToken = $pdo->prepare("UPDATE google_link_verifications SET verified_at = NOW() WHERE id = ?");
                $updToken->execute([(int)$verif['id']]);

                $pdo->commit();

                $status = 'success';
                $message = 'Akun Google Anda berhasil diverifikasi dan tersimpan! Sekarang Anda dapat masuk ke Ilmuna Learn cukup dengan satu klik tombol Google.';
                
                // Jika user saat ini sedang login dengan ID yang sama, update session
                if (!empty($_SESSION['user']['id']) && (int)$_SESSION['user']['id'] === $userId) {
                    $_SESSION['user']['email_google'] = $emailGoogle;
                    $_SESSION['user']['google_id'] = $googleId;
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $status = 'error';
                $message = 'Terjadi kesalahan sistem saat menyimpan data: ' . $e->getMessage();
            }
        }
    }
}

$siteName = defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn';
$siteTag  = defined('APP_TAGLINE') ? APP_TAGLINE : 'Media Pembelajaran Digital Pendidikan Agama Islam';
$baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
$profileUrl = $baseUrl ? ($baseUrl . '/profile.php') : '/profile.php';
$loginUrl   = $baseUrl ? ($baseUrl . '/login.php') : '/login.php';
$isLoggedIn = !empty($_SESSION['user']['id']);
$destUrl    = $isLoggedIn ? $profileUrl : $loginUrl;
$destLabel  = $isLoggedIn ? 'Buka Profil Saya' : 'Masuk ke Portal';

// Data URI Ornamen Vektor Nusantara (Konsisten 100% dengan modal-template.php & alert.js)
$gununganPngUrl = $baseUrl . '/assets/img/gunungan.png';
$logoPngUrl     = $baseUrl . '/assets/images/logo.png';

$ornamentKawung = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='0.6' opacity='0.05'%3E%3Cellipse cx='10' cy='10' rx='6' ry='6'/%3E%3Cellipse cx='30' cy='10' rx='6' ry='6'/%3E%3Cellipse cx='10' cy='30' rx='6' ry='6'/%3E%3Cellipse cx='30' cy='30' rx='6' ry='6'/%3E%3C/g%3E%3C/svg%3E";

$ornamentSudutTL = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 200 200'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.5' stroke-linecap='round' stroke-linejoin='round' opacity='0.16'%3E%3Cpath d='M0,60 Q60,60 60,0'/%3E%3Cpath d='M0,90 Q90,90 90,0'/%3E%3Cpath d='M0,120 Q120,120 120,0'/%3E%3Cpath d='M0,150 Q150,150 150,0'/%3E%3Cpath d='M0,180 Q180,180 180,0'/%3E%3Cpath d='M20,20 Q35,35 20,50'/%3E%3Cpath d='M20,20 Q35,5 50,20'/%3E%3Cpath d='M60,0 C72,15 62,30 48,24 C38,20 42,6 52,8 C58,10 58,18 52,20'/%3E%3Cpath d='M0,60 C15,72 30,62 24,48 C20,38 6,42 8,52 C10,58 18,58 20,52'/%3E%3Ccircle cx='30' cy='30' r='2' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='55' cy='55' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='80' cy='80' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='105' cy='105' r='2' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='130' cy='130' r='2' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='155' cy='155' r='2' fill='%231D3B5E' stroke='none'/%3E%3Cpath d='M100,10 L106,16 M106,10 L100,16'/%3E%3Cpath d='M10,100 L16,106 M16,100 L10,106'/%3E%3C/g%3E%3C/svg%3E";

$ornamentSudutTR = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 120'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.3' stroke-linecap='round' stroke-linejoin='round' opacity='0.12'%3E%3Cpath d='M120,40 Q80,40 80,0'/%3E%3Cpath d='M120,60 Q60,60 60,0'/%3E%3Cpath d='M120,80 Q40,80 40,0'/%3E%3Cpath d='M120,100 Q20,100 20,0'/%3E%3Cpath d='M80,0 C70,15 80,28 92,22 C100,18 96,8 88,10 C82,12 82,18 86,20'/%3E%3Cpath d='M120,40 C108,50 100,42 105,32 C108,26 116,28 114,35'/%3E%3Ccircle cx='60' cy='20' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='40' cy='40' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='20' cy='60' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Cpath d='M100,10 L105,15 M105,10 L100,15'/%3E%3C/g%3E%3C/svg%3E";

$ornamentSudutBL = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 120'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.3' stroke-linecap='round' stroke-linejoin='round' opacity='0.12'%3E%3Cpath d='M0,80 Q40,80 40,120'/%3E%3Cpath d='M0,60 Q60,60 60,120'/%3E%3Cpath d='M0,40 Q80,40 80,120'/%3E%3Cpath d='M0,20 Q100,20 100,120'/%3E%3Cpath d='M40,120 C50,105 40,92 28,98 C20,102 24,112 32,110 C38,108 38,102 34,100'/%3E%3Cpath d='M0,80 C12,70 20,78 15,88 C12,94 4,92 6,85'/%3E%3Ccircle cx='60' cy='100' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='80' cy='80' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='100' cy='60' r='1.8' fill='%231D3B5E' stroke='none'/%3E%3Cpath d='M10,100 L15,105 M15,100 L10,105'/%3E%3C/g%3E%3C/svg%3E";

$ornamentDivider = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 120 20'%3E%3Cg fill='none' stroke='%231D3B5E' stroke-width='1.2' stroke-linecap='round' opacity='0.3'%3E%3Cpath d='M0,10 L35,10'/%3E%3Cpath d='M85,10 L120,10'/%3E%3Cpath d='M60,3 L68,10 L60,17 L52,10 Z'/%3E%3Ccircle cx='45' cy='10' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='75' cy='10' r='1.5' fill='%231D3B5E' stroke='none'/%3E%3Ccircle cx='60' cy='10' r='1.2' fill='%231D3B5E' stroke='none'/%3E%3C/g%3E%3C/svg%3E";
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Akun Google — <?= htmlspecialchars($siteName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <script src="https://code.iconify.design/iconify-icon/2.1.0/iconify-icon.min.js"></script>
    <style>
        :root {
            --navy: #1D3B5E;
            --navy-dark: #152C47;
            --teal: #0D7A87;
            --sky: #52B2DC;
            --green: #10B981;
            --amber: #F59E0B;
            --red: #EF4444;
            --bg: #F4F7FB;
            --card-bg: #FFFFFF;
            --border: rgba(226, 232, 240, 0.95);
            --text-dark: #1D3B5E;
            --text-muted: #64748B;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        body {
            background-color: var(--bg);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 24px 16px;
            background-image: 
                radial-gradient(at 0% 0%, rgba(13, 122, 135, 0.10) 0px, transparent 55%),
                radial-gradient(at 100% 100%, rgba(29, 59, 94, 0.12) 0px, transparent 55%);
            position: relative;
            overflow-x: hidden;
        }

        /* CARD MODAL NUSANTARA (Border radius: 28px) */
        .verify-card {
            position: relative;
            max-width: 500px;
            width: 100%;
            background-color: var(--card-bg);
            background-image: url("<?= $ornamentKawung ?>");
            background-size: 36px 36px;
            background-repeat: repeat;
            border-radius: 28px;
            border: 1px solid var(--border);
            box-shadow: 0 25px 65px -12px rgba(29, 59, 94, 0.22), 0 0 25px rgba(13, 122, 135, 0.08);
            padding: 42px 36px 34px;
            text-align: center;
            overflow: hidden;
            animation: cardAppear 0.38s cubic-bezier(0.34, 1.56, 0.64, 1);
            z-index: 10;
        }

        @keyframes cardAppear {
            from {
                opacity: 0;
                transform: scale(0.94) translateY(14px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        /* ORNAMEN WATERMARK GUNUNGAN NUSANTARA */
        .gunungan-watermark {
            position: absolute;
            right: -75px;
            bottom: -35px;
            width: 220px;
            height: 275px;
            background-image: url("<?= $gununganPngUrl ?>");
            background-size: contain;
            background-repeat: no-repeat;
            background-position: bottom right;
            opacity: 0.08;
            pointer-events: none;
            z-index: 1;
        }

        /* ORNAMEN BATIK SUDUT */
        .ornament-tl {
            position: absolute;
            top: 0;
            left: 0;
            width: 130px;
            height: 130px;
            background-image: url("<?= $ornamentSudutTL ?>");
            background-size: contain;
            background-repeat: no-repeat;
            opacity: 0.85;
            pointer-events: none;
            z-index: 1;
        }

        .ornament-tr {
            position: absolute;
            top: 0;
            right: 0;
            width: 90px;
            height: 90px;
            background-image: url("<?= $ornamentSudutTR ?>");
            background-size: contain;
            background-repeat: no-repeat;
            opacity: 0.75;
            pointer-events: none;
            z-index: 1;
        }

        .ornament-bl {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 90px;
            height: 90px;
            background-image: url("<?= $ornamentSudutBL ?>");
            background-size: contain;
            background-repeat: no-repeat;
            opacity: 0.75;
            pointer-events: none;
            z-index: 1;
        }

        .card-content {
            position: relative;
            z-index: 2;
        }

        /* LOGO & BRAND HEADER */
        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: rgba(248, 250, 252, 0.95);
            border: 1px solid #CBD5E1;
            padding: 6px 16px;
            border-radius: 40px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(29, 59, 94, 0.05);
        }

        .brand-logo {
            width: 24px;
            height: 24px;
            object-fit: contain;
        }

        .brand-text {
            font-size: 13px;
            font-weight: 700;
            color: var(--navy);
            letter-spacing: 0.3px;
        }

        /* ICON STATUS BADGE (64px, 20px radius) */
        .icon-wrapper {
            width: 78px;
            height: 78px;
            border-radius: 24px;
            margin: 0 auto 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            box-shadow: 0 10px 25px -8px rgba(0,0,0,0.12);
        }

        .icon-success {
            background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
            color: var(--green);
            border: 2px solid #A7F3D0;
        }

        .icon-already {
            background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 100%);
            color: var(--teal);
            border: 2px solid #BAE6FD;
        }

        .icon-expired {
            background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
            color: var(--amber);
            border: 2px solid #FDE68A;
        }

        .icon-error {
            background: linear-gradient(135deg, #FEF2F2 0%, #FEE2E2 100%);
            color: var(--red);
            border: 2px solid #FECACA;
        }

        /* GOOGLE EMAIL PILL */
        .google-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #FFFFFF;
            border: 1.5px solid #CBD5E1;
            padding: 7px 18px;
            border-radius: 30px;
            font-size: 13.5px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 16px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.04);
        }

        .title {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 800;
            color: var(--navy);
            margin-bottom: 8px;
            line-height: 1.25;
            letter-spacing: -0.3px;
        }

        /* ORNAMEN DIVIDER BATIK KETUPAT */
        .divider-ketupat {
            width: 100px;
            height: 14px;
            margin: 0 auto 16px;
            background-image: url("<?= $ornamentDivider ?>");
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            opacity: 0.6;
        }

        .desc {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.65;
            margin-bottom: 26px;
        }

        /* BUTTON GRADIENT NUSANTARA */
        .btn-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px 24px;
            background: linear-gradient(135deg, var(--teal) 0%, var(--navy) 100%);
            color: #FFFFFF;
            text-decoration: none;
            border-radius: 16px;
            font-weight: 700;
            font-size: 14.5px;
            letter-spacing: 0.3px;
            box-shadow: 0 10px 24px -6px rgba(13, 122, 135, 0.42);
            transition: all 0.24s cubic-bezier(0.16, 1, 0.3, 1);
            cursor: pointer;
        }

        .btn-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 30px -6px rgba(13, 122, 135, 0.55);
            color: #FFFFFF;
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            margin-top: 10px;
            padding: 12px 20px;
            background: transparent;
            color: var(--text-muted);
            text-decoration: none;
            border-radius: 14px;
            font-size: 13.5px;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background: #F1F5F9;
            color: var(--navy);
        }

        .footer-note {
            margin-top: 24px;
            font-size: 11.5px;
            color: #94A3B8;
            line-height: 1.5;
        }

        @media (max-width: 480px) {
            .verify-card {
                padding: 34px 22px 26px;
                border-radius: 24px;
            }
            .title {
                font-size: 21px;
            }
        }
    </style>
</head>
<body>

    <div class="verify-card">
        <!-- ORNAMEN NUSANTARA KONSISTEN DENGAN MODAL & ALERT ILMUNA -->
        <div class="gunungan-watermark" aria-hidden="true"></div>
        <div class="ornament-tl" aria-hidden="true"></div>
        <div class="ornament-tr" aria-hidden="true"></div>
        <div class="ornament-bl" aria-hidden="true"></div>

        <div class="card-content">
            <!-- Brand Badge Resmi -->
            <div class="brand-badge">
                <img src="<?= htmlspecialchars($logoPngUrl) ?>" alt="Ilmuna" class="brand-logo" onerror="this.style.display='none'">
                <span class="brand-text"><?= htmlspecialchars($siteName) ?></span>
            </div>

            <?php if ($status === 'success'): ?>
                <div class="icon-wrapper icon-success">
                    <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                </div>

                <?php if (!empty($emailGoogle)): ?>
                    <div class="google-pill">
                        <iconify-icon icon="logos:google-icon" width="18"></iconify-icon>
                        <span><?= htmlspecialchars($emailGoogle) ?></span>
                    </div>
                <?php endif; ?>

                <h1 class="title">Verifikasi Berhasil!</h1>
                <div class="divider-ketupat" aria-hidden="true"></div>
                <p class="desc"><?= htmlspecialchars($message) ?></p>

                <a href="<?= htmlspecialchars($destUrl) ?>" class="btn-cta">
                    <iconify-icon icon="solar:user-circle-bold" width="19"></iconify-icon>
                    <span><?= htmlspecialchars($destLabel) ?></span>
                </a>

            <?php elseif ($status === 'already_verified'): ?>
                <div class="icon-wrapper icon-already">
                    <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
                </div>

                <?php if (!empty($emailGoogle)): ?>
                    <div class="google-pill">
                        <iconify-icon icon="logos:google-icon" width="18"></iconify-icon>
                        <span><?= htmlspecialchars($emailGoogle) ?></span>
                    </div>
                <?php endif; ?>

                <h1 class="title">Sudah Terverifikasi</h1>
                <div class="divider-ketupat" aria-hidden="true"></div>
                <p class="desc"><?= htmlspecialchars($message) ?></p>

                <a href="<?= htmlspecialchars($destUrl) ?>" class="btn-cta">
                    <iconify-icon icon="solar:arrow-right-bold" width="19"></iconify-icon>
                    <span><?= htmlspecialchars($destLabel) ?></span>
                </a>

            <?php elseif ($status === 'expired'): ?>
                <div class="icon-wrapper icon-expired">
                    <iconify-icon icon="solar:clock-circle-bold"></iconify-icon>
                </div>

                <h1 class="title">Tautan Kedaluwarsa</h1>
                <div class="divider-ketupat" aria-hidden="true"></div>
                <p class="desc"><?= htmlspecialchars($message) ?></p>

                <a href="<?= htmlspecialchars($profileUrl) ?>" class="btn-cta">
                    <iconify-icon icon="solar:restart-bold" width="19"></iconify-icon>
                    <span>Tautkan Ulang di Profil</span>
                </a>

            <?php else: ?>
                <div class="icon-wrapper icon-error">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                </div>

                <h1 class="title">Verifikasi Gagal</h1>
                <div class="divider-ketupat" aria-hidden="true"></div>
                <p class="desc"><?= htmlspecialchars($message) ?></p>

                <a href="<?= htmlspecialchars($profileUrl) ?>" class="btn-cta">
                    <iconify-icon icon="solar:arrow-left-bold" width="19"></iconify-icon>
                    <span>Kembali ke Profil</span>
                </a>
            <?php endif; ?>

            <?php if ($isLoggedIn): ?>
                <a href="<?= htmlspecialchars($baseUrl ? ($baseUrl . '/') : '/') ?>" class="btn-secondary">
                    <iconify-icon icon="solar:home-2-bold" width="16"></iconify-icon>
                    <span>Halaman Utama</span>
                </a>
            <?php else: ?>
                <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn-secondary">
                    <iconify-icon icon="solar:login-2-bold" width="16"></iconify-icon>
                    <span>Masuk ke Akun</span>
                </a>
            <?php endif; ?>

            <div class="footer-note">
                &copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?> &bull; <?= htmlspecialchars($siteTag) ?>
            </div>
        </div>
    </div>

</body>
</html>
