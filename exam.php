<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — CBT EXAM BROWSER WEB PORTAL
 * ==============================================================================
 * Redirect / Gerbang Web Ujian Terintegrasi API cbt-exam.php
 * Sesuai Skema 73 Tabel Kurikulum Merdeka
 * ==============================================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

$pdo = getDBConnection();
$isExamLoggedIn = isset($_SESSION['cbt_session']);
$cbtData = $isExamLoggedIn ? $_SESSION['cbt_session'] : null;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= $isExamLoggedIn ? htmlspecialchars($cbtData['judul_asesmen']) . ' — Ilmuna CBT' : 'Login Ujian CBT — Ilmuna Exam Browser' ?></title>
<?= renderFavicon() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.iconify.design/iconify-icon/1.0.7/iconify-icon.min.js"></script>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/lumina-lms.css">
    <style>
        :root {
            --primary-navy: #1D3B5E;
            --primary-navy-dark: #122338;
            --primary-teal: #0D7A87;
            --primary-gold: #F59E0B;
            --bg-canvas: #F8FAFC;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: linear-gradient(135deg, #122338 0%, #1D3B5E 40%, #0D7A87 100%);
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            user-select: none;
        }
        .portal-card {
            width: 100%;
            max-width: 440px;
            background: #FFFFFF;
            border-radius: 24px;
            padding: 36px 30px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.4);
            text-align: center;
        }
        .portal-logo {
            width: 72px;
            height: 72px;
            object-fit: contain;
            margin-bottom: 16px;
        }
        .portal-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.45rem;
            font-weight: 800;
            color: var(--primary-navy);
            margin-bottom: 4px;
        }
        .portal-sub {
            font-size: 0.85rem;
            color: var(--primary-teal);
            font-weight: 600;
            margin-bottom: 24px;
        }
        .portal-info {
            background: #F1F5F9;
            border-radius: 14px;
            padding: 16px;
            font-size: 0.85rem;
            color: #334155;
            line-height: 1.6;
            margin-bottom: 24px;
            text-align: left;
        }
        .portal-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
            color: #FFFFFF;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.95rem;
            text-decoration: none;
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.3);
        }
    </style>
</head>
<body>
    <div class="portal-card">
        <img src="<?= BASE_URL ?>/assets/images/icon.png" alt="Logo" class="portal-logo">
        <div class="portal-title">ILMUNA EXAM CBT</div>
        <div class="portal-sub">Aplikasi Khusus Ujian &amp; Asesmen Mandiri</div>

        <div class="portal-info">
            <strong><i class="fas fa-shield-halved" style="color: #0D7A87;"></i> Mode Ujian Aman:</strong><br>
            Sistem CBT saat ini telah ditingkatkan menjadi <strong>Aplikasi Native Android Murni (Ilmuna Exam Browser)</strong> dengan perlindungan anti-screenshot, anti-keluar aplikasi, dan freeze otomatis.
        </div>

        <a href="<?= BASE_URL ?>/download-exam" class="portal-btn">
            <iconify-icon icon="solar:download-square-bold" width="20"></iconify-icon>
            <span>Unduh Aplikasi Ilmuna Exam Browser</span>
        </a>
        <div style="margin-top: 14px; font-size: 13px;">
            <a href="<?= BASE_URL ?>/login.php" style="color: #64748B; text-decoration: none; font-weight: 600;">
                &larr; Kembali ke Portal Login
            </a>
        </div>
    </div>
</body>
</html>
