<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — TENTANG PLATFORM (ABOUT)
 * ==============================================================================
 * Profil resmi Sistem Informasi Akademik (SIAKAD) & Learning Management System (LMS)
 * Terpadu Kurikulum Merdeka & Pendidikan Agama Islam (PAI) Berkarakter Luhur Nusantara.
 * 
 * Standar Desain (Sesuai README.md):
 * - Tema Lumina Gold: Navy (#1D3B5E), Teal (#0D7A87), Sky (#52B2DC), Gold (#F59E0B)
 * - Ornamen Nusantara: Watermark Gunungan Wayang & Pola Batik Kawung
 * - Tipografi: Plus Jakarta Sans & JetBrains Mono
 * - Responsif Mobile Ultra (360px+)
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';

$baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
if (empty($baseUrl)) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $baseUrl = $protocol . $host . (trim($scriptDir, '/') !== '' ? '/' . trim($scriptDir, '/') : '');
}

$pageTitle = 'Tentang Ilmuna Learn — SIAKAD & LMS Nusantara';
$activeNav  = 'about';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <?= renderFavicon() ?>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Iconify Solar Icons -->
    <script src="https://code.iconify.design/iconify-icon/1.0.8/iconify-icon.min.js"></script>

    <style>
        :root {
            --navy: #1D3B5E;
            --navy-dark: #13273F;
            --navy-light: #2A5384;
            --teal: #0D7A87;
            --teal-dark: #095963;
            --teal-light: #129EAE;
            --sky: #52B2DC;
            --sky-fade: rgba(82, 178, 220, 0.12);
            --gold: #F59E0B;
            --gold-dark: #D97706;
            --gold-fade: rgba(245, 158, 11, 0.12);
            --emerald: #10B981;
            --rose: #EF4444;

            --bg-page: #F8FAFC;
            --card-bg: #FFFFFF;
            --border-color: #E2E8F0;
            --border-hover: #CBD5E1;

            --text-main: #0F172A;
            --text-muted: #64748B;
            --text-subtle: #94A3B8;

            --font-main: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --font-mono: 'JetBrains Mono', 'Fira Code', monospace;

            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-xl: 26px;

            --shadow-sm: 0 2px 6px rgba(15, 23, 42, 0.04);
            --shadow-md: 0 8px 24px rgba(29, 59, 94, 0.08);
            --shadow-lg: 0 16px 36px rgba(29, 59, 94, 0.12);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--bg-page);
            color: var(--text-main);
            line-height: 1.65;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            overflow-x: hidden;
            position: relative;
        }

        /* ORNAMEN NUSANTARA (WATERMARK GUNUNGAN WAYANG) */
        .nusantara-watermark {
            position: fixed;
            top: 0;
            right: 0;
            width: 480px;
            height: 480px;
            pointer-events: none;
            opacity: 0.035;
            z-index: 0;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><path fill="%231D3B5E" d="M50 0 L100 50 L50 100 L0 50 Z M50 15 L85 50 L50 85 L15 50 Z"/></svg>');
            background-repeat: no-repeat;
            background-size: contain;
        }

        /* HEADER / NAVBAR */
        .header-topbar {
            background: rgba(255, 255, 255, 0.94);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(12px);
        }

        .header-container {
            max-width: 1140px;
            margin: 0 auto;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .brand-link {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--navy);
        }

        .brand-logo {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            object-fit: cover;
            box-shadow: 0 4px 10px rgba(13, 122, 135, 0.15);
        }

        .brand-text-wrap {
            display: flex;
            flex-direction: column;
        }

        .brand-title {
            font-size: 17px;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1.2;
            color: var(--navy);
        }

        .brand-tagline {
            font-size: 11px;
            font-weight: 600;
            color: var(--teal);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .nav-item:hover {
            color: var(--navy);
            background: var(--sky-fade);
        }

        .nav-item.active {
            color: var(--teal-dark);
            background: var(--sky-fade);
            font-weight: 700;
        }

        .btn-nav-login {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            color: #FFFFFF;
            background: linear-gradient(135deg, var(--navy) 0%, var(--teal) 100%);
            box-shadow: 0 4px 12px rgba(29, 59, 94, 0.2);
            transition: all 0.2s ease;
        }

        .btn-nav-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(29, 59, 94, 0.3);
        }

        /* MAIN CONTENT */
        .main-wrapper {
            flex: 1;
            max-width: 1140px;
            width: 100%;
            margin: 0 auto;
            padding: 32px 20px 60px;
            position: relative;
            z-index: 1;
        }

        /* HERO CARD */
        .hero-card {
            background: linear-gradient(135deg, var(--navy) 0%, #17385c 60%, var(--teal-dark) 100%);
            border-radius: var(--radius-xl);
            padding: 44px 40px;
            color: #FFFFFF;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            margin-bottom: 32px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .hero-card::after {
            content: '';
            position: absolute;
            bottom: -30px;
            right: -30px;
            width: 260px;
            height: 260px;
            background: radial-gradient(circle, rgba(245, 158, 11, 0.25) 0%, transparent 70%);
            pointer-events: none;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
            color: #FEF3C7;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 16px;
        }

        .hero-title {
            font-size: 32px;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -0.02em;
            margin-bottom: 14px;
        }

        .hero-subtitle {
            font-size: 15px;
            color: #E2E8F0;
            max-width: 820px;
            line-height: 1.7;
            margin-bottom: 24px;
        }

        .hero-meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            font-size: 12.5px;
            color: #CBD5E1;
            padding-top: 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.14);
        }

        .hero-meta-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .hero-meta-item iconify-icon, .hero-meta-item i {
            color: var(--gold);
        }

        /* GRID DUA KOLOM DOKUMENTASI */
        .content-grid {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 28px;
            align-items: start;
        }

        /* SIDEBAR STICKY NAV */
        .sidebar-nav-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 20px;
            position: sticky;
            top: 80px;
            box-shadow: var(--shadow-sm);
        }

        .sidebar-title {
            font-size: 13px;
            font-weight: 800;
            color: var(--navy);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .sidebar-links {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .sidebar-link:hover, .sidebar-link.active {
            color: var(--teal-dark);
            background: var(--sky-fade);
        }

        .sidebar-link iconify-icon, .sidebar-link i {
            font-size: 16px;
            color: var(--teal);
        }

        /* CONTENT BODY CARDS */
        .doc-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 32px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 24px;
            scroll-margin-top: 90px;
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border-color);
        }

        .section-icon-box {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--sky-fade) 0%, var(--gold-fade) 100%);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--teal-dark);
            font-size: 20px;
        }

        .section-title {
            font-size: 20px;
            font-weight: 800;
            color: var(--navy);
            letter-spacing: -0.01em;
        }

        .lead-text {
            font-size: 15px;
            color: var(--text-main);
            line-height: 1.75;
            margin-bottom: 20px;
        }

        /* 4 PILAR FITUR */
        .pillar-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-top: 20px;
        }

        .pillar-card {
            background: #F8FAFC;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 20px;
            transition: all 0.2s ease;
        }

        .pillar-card:hover {
            border-color: var(--sky);
            box-shadow: var(--shadow-sm);
            transform: translateY(-2px);
        }

        .pillar-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .pillar-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: #FFFFFF;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--teal);
            font-size: 18px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }

        .pillar-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--navy);
        }

        .pillar-desc {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        /* STATS BAR */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin: 24px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, #F8FAFC 0%, #FFFFFF 100%);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 18px;
            text-align: center;
        }

        .stat-num {
            font-family: var(--font-mono);
            font-size: 26px;
            font-weight: 800;
            color: var(--teal-dark);
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        /* TECH BADGES */
        .badge-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
        }

        .tech-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            background: #FFFFFF;
            border: 1px solid var(--border-color);
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: var(--navy);
        }

        .tech-pill i, .tech-pill iconify-icon {
            color: var(--teal);
        }

        /* CALLOUT BOX */
        .callout-box {
            background: var(--sky-fade);
            border-left: 4px solid var(--sky);
            border-radius: 0 var(--radius-md) var(--radius-md) 0;
            padding: 16px 20px;
            margin: 20px 0;
            font-size: 14px;
            color: var(--navy-dark);
        }

        .callout-box strong {
            color: var(--navy);
        }

        /* FOOTER */
        .footer-bottom {
            background: #FFFFFF;
            border-top: 1px solid var(--border-color);
            padding: 24px 20px;
            text-align: center;
            font-size: 13px;
            color: var(--text-muted);
            margin-top: auto;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 16px;
            margin-top: 8px;
        }

        .footer-link {
            color: var(--teal);
            text-decoration: none;
            font-weight: 600;
        }

        .footer-link:hover {
            color: var(--navy);
            text-decoration: underline;
        }

        /* RESPONSIVE */
        @media (max-width: 900px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            .sidebar-nav-card {
                display: none;
            }
            .hero-card {
                padding: 30px 24px;
            }
            .hero-title {
                font-size: 24px;
            }
            .pillar-grid {
                grid-template-columns: 1fr;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .nav-links {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="nusantara-watermark" aria-hidden="true"></div>

    <!-- NAVBAR TOP -->
    <header class="header-topbar">
        <div class="header-container">
            <a href="<?= $baseUrl ?>/login.php" class="brand-link" title="Beranda Ilmuna Learn">
                <img src="<?= $baseUrl ?>/assets/images/logo-learn.png" alt="Ilmuna Learn" class="brand-logo" onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 40 40\'><rect width=\'40\' height=\'40\' rx=\'10\' fill=\'%231D3B5E\'/><text x=\'50%25\' y=\'55%25\' dominant-baseline=\'middle\' text-anchor=\'middle\' fill=\'%23F59E0B\' font-size=\'18\' font-weight=\'bold\'>IL</text></svg>'">
                <div class="brand-text-wrap">
                    <span class="brand-title">Ilmuna Learn</span>
                    <span class="brand-tagline">SIAKAD &amp; LMS Nusantara</span>
                </div>
            </a>

            <nav class="nav-links" aria-label="Navigasi Utama">
                <a href="<?= $baseUrl ?>/about" class="nav-item active">Tentang</a>
                <a href="<?= $baseUrl ?>/terms" class="nav-item">Syarat</a>
                <a href="<?= $baseUrl ?>/privacy" class="nav-item">Privasi</a>
                <a href="<?= $baseUrl ?>/download-exam" class="nav-item">Exam Browser</a>
            </nav>

            <div style="display:flex; align-items:center; gap:8px;">
                <a href="<?= $baseUrl ?>/login.php" class="btn-nav-login">
                    <i class="fas fa-arrow-right-to-bracket"></i>
                    <span>Masuk Portal</span>
                </a>
            </div>
        </div>
    </header>

    <!-- MAIN BODY -->
    <main class="main-wrapper">
        <!-- HERO BANNER -->
        <section class="hero-card">
            <div class="hero-badge">
                <iconify-icon icon="solar:diploma-verified-bold"></iconify-icon>
                <span>Profil Resmi Platform</span>
            </div>
            <h1 class="hero-title">Sistem Informasi Akademik &amp; Pembelajaran Terpadu Nusantara</h1>
            <p class="hero-subtitle">
                <strong>Ilmuna Learn</strong> adalah platform digital terintegrasi yang mengharmonisasikan standar Kurikulum Merdeka Kemendikbudristek dan Pendidikan Agama Islam (PAI) Kemenag dengan nilai luhur adab Nusantara, menghadirkan ekosistem belajar madrasah yang modern, aman, dan berintegritas tinggi.
            </p>

            <div class="hero-meta-row">
                <div class="hero-meta-item">
                    <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
                    <span>Standar Mutu Pendidikan Nasional</span>
                </div>
                <div class="hero-meta-item">
                    <iconify-icon icon="solar:bolt-circle-bold"></iconify-icon>
                    <span>Performa Mikrodetik PHP 8.2 Native</span>
                </div>
                <div class="hero-meta-item">
                    <iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon>
                    <span>Guardian Dome WAF Secured</span>
                </div>
            </div>
        </section>

        <!-- STATISTIK EKOSISTEM -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-num">51</div>
                <div class="stat-label">Modul Terintegrasi</div>
            </div>
            <div class="stat-card">
                <div class="stat-num">4</div>
                <div class="stat-label">Peran Pengguna (RBAC)</div>
            </div>
            <div class="stat-card">
                <div class="stat-num">100%</div>
                <div class="stat-label">Kurikulum Merdeka</div>
            </div>
            <div class="stat-card">
                <div class="stat-num">&lt; 0.2s</div>
                <div class="stat-label">Waktu Respon Rata-Rata</div>
            </div>
        </div>

        <div class="content-grid">
            <!-- SIDEBAR NAVIGASI CEPAT -->
            <aside class="sidebar-nav-card" aria-label="Daftar Isi Profil">
                <div class="sidebar-title">
                    <iconify-icon icon="solar:list-check-bold"></iconify-icon>
                    <span>Daftar Isi</span>
                </div>
                <ul class="sidebar-links">
                    <li><a href="#visi-misi" class="sidebar-link"><iconify-icon icon="solar:compass-bold"></iconify-icon> Visi &amp; Misi</a></li>
                    <li><a href="#pilar-utama" class="sidebar-link"><iconify-icon icon="solar:widget-5-bold"></iconify-icon> 4 Pilar Keunggulan</a></li>
                    <li><a href="#arsitektur" class="sidebar-link"><iconify-icon icon="solar:server-square-bold"></iconify-icon> Arsitektur &amp; Performa</a></li>
                    <li><a href="#keamanan" class="sidebar-link"><iconify-icon icon="solar:shield-warning-bold"></iconify-icon> Keamanan Sistem</a></li>
                    <li><a href="#filosofi-desain" class="sidebar-link"><iconify-icon icon="solar:palette-bold"></iconify-icon> Filosofi Desain</a></li>
                </ul>
            </aside>

            <!-- KONTEN UTAMA -->
            <div class="content-body">
                <!-- 1. VISI & MISI -->
                <section class="doc-section" id="visi-misi">
                    <div class="section-header">
                        <div class="section-icon-box">
                            <iconify-icon icon="solar:compass-bold"></iconify-icon>
                        </div>
                        <h2 class="section-title">Visi &amp; Misi</h2>
                    </div>
                    <p class="lead-text">
                        Pendidikan di era digital membutuhkan platform yang tidak sekadar mengelola angka nilai kognitif, melainkan membimbing adab, kebiasaan ibadah, dan integritas moral peserta didik.
                    </p>
                    <div class="callout-box">
                        <strong>Visi Luhur:</strong> Terwujudnya ekosistem pendidikan digital madrasah dan sekolah Indonesia yang unggul dalam sains teknologi, kokoh dalam adab Islami, dan berakar kuat pada nilai kearifan budaya Nusantara.
                    </div>
                    <h3 style="font-size: 16px; font-weight: 700; color: var(--navy); margin: 20px 0 10px;">Misi Strategis:</h3>
                    <ul style="padding-left: 20px; color: var(--text-muted); font-size: 14px; line-height: 1.8;">
                        <li><strong>Digitalisasi Terbuka &amp; Ringan:</strong> Menghadirkan infrastruktur SIAKAD &amp; LMS yang hemat sumber daya server, dapat berjalan lancar di shared hosting hingga VPS tanpa dependensi runtime berat.</li>
                        <li><strong>Integritas Asesmen Tanpa Kompromi:</strong> Mengamankan evaluasi hasil belajar melalui CBT Kiosk Exam Browser berproteksi hardware anti-screenshot dan anti-curang.</li>
                        <li><strong>Penguatan Karakter &amp; Akhlak:</strong> Mengintegrasikan Mutaba'ah Yaumiyah (sholat fardhu, dhuha, tahajud, tilawah Qur'an) langsung ke dalam instrumen E-Rapor sikap spiritual.</li>
                        <li><strong>Transparansi Pembelajaran:</strong> Memberikan akses real-time bagi guru, siswa, pengawas, dan orang tua terhadap seluruh rekam jejak akademik.</li>
                    </ul>
                </section>

                <!-- 2. 4 PILAR KEUNGGULAN -->
                <section class="doc-section" id="pilar-utama">
                    <div class="section-header">
                        <div class="section-icon-box">
                            <iconify-icon icon="solar:widget-5-bold"></iconify-icon>
                        </div>
                        <h2 class="section-title">4 Pilar Keunggulan Ilmuna Learn</h2>
                    </div>
                    <p class="lead-text">
                        Dirancang secara holistik untuk menjawab tantangan operasional madrasah modern dengan kehandalan tanpa celah:
                    </p>

                    <div class="pillar-grid">
                        <div class="pillar-card">
                            <div class="pillar-head">
                                <div class="pillar-icon"><i class="fas fa-bolt"></i></div>
                                <h4 class="pillar-title">Performa Mikrodetik</h4>
                            </div>
                            <p class="pillar-desc">
                                Dibangun dengan arsitektur murni <strong>PHP 8.2 Native &amp; PDO Singleton</strong>. Eksekusi query teroptimasi, waktu muat mikrodetik, dan hemat RAM di server hosting cPanel &amp; VPS.
                            </p>
                        </div>

                        <div class="pillar-card">
                            <div class="pillar-head">
                                <div class="pillar-icon"><i class="fas fa-shield-halved"></i></div>
                                <h4 class="pillar-title">CBT Anti-Curang Kiosk</h4>
                            </div>
                            <p class="pillar-desc">
                                Mesin ujian CBT berpasangan dengan <strong>Ilmuna Exam Browser Android</strong>. Menutup celah split-screen, blokir screenshot hardware (FLAG_SECURE), dan auto-freeze saat berpindah tab.
                            </p>
                        </div>

                        <div class="pillar-card">
                            <div class="pillar-head">
                                <div class="pillar-icon"><i class="fas fa-location-crosshairs"></i></div>
                                <h4 class="pillar-title">Presensi Geofencing GPS</h4>
                            </div>
                            <p class="pillar-desc">
                                Validasi kehadiran satelit dengan <strong>Formula Haversine</strong> mengukur radius gerbang sekolah secara presisi, dipadukan dengan QR Code token harian anti-titip absen.
                            </p>
                        </div>

                        <div class="pillar-card">
                            <div class="pillar-head">
                                <div class="pillar-icon"><i class="fas fa-book-quran"></i></div>
                                <h4 class="pillar-title">Mutaba'ah &amp; E-Rapor</h4>
                            </div>
                            <p class="pillar-desc">
                                Jurnal amalan ibadah harian siswa yang otomatis dikonversi menjadi capaian nilai sikap spiritual E-Rapor Kurikulum Merdeka (Tujuan Pembelajaran &amp; Capaian Pembelajaran).
                            </p>
                        </div>
                    </div>
                </section>

                <!-- 3. ARSITEKTUR TEKNOLOGI -->
                <section class="doc-section" id="arsitektur">
                    <div class="section-header">
                        <div class="section-icon-box">
                            <iconify-icon icon="solar:server-square-bold"></iconify-icon>
                        </div>
                        <h2 class="section-title">Arsitektur &amp; Spesifikasi Sistem</h2>
                    </div>
                    <p class="lead-text">
                        Kemandirian teknologi menjadi prinsip fundamental Ilmuna Learn agar tidak rentan terhadap pembaruan breaking changes eksternal:
                    </p>
                    <table style="width:100%; border-collapse:collapse; font-size:13.5px; margin-top:10px;">
                        <tr style="border-bottom: 1px solid var(--border-color); background: #F8FAFC;">
                            <th style="padding:10px 14px; text-align:left; color:var(--navy);">Komponen</th>
                            <th style="padding:10px 14px; text-align:left; color:var(--navy);">Spesifikasi</th>
                            <th style="padding:10px 14px; text-align:left; color:var(--navy);">Keterangan</th>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding:10px 14px; font-weight:700;">Backend Core</td>
                            <td style="padding:10px 14px;"><span class="tech-pill"><i class="fab fa-php"></i> PHP 8.2 / 8.3 Native</span></td>
                            <td style="padding:10px 14px; color:var(--text-muted);">Tanpa framework bloatware, eksekusi native tercepat.</td>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding:10px 14px; font-weight:700;">Database Engine</td>
                            <td style="padding:10px 14px;"><span class="tech-pill"><i class="fas fa-database"></i> MySQL / MariaDB InnoDB</span></td>
                            <td style="padding:10px 14px; color:var(--text-muted);">91 Tabel &amp; 13 Views, Transaksi ACID, charset utf8mb4.</td>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding:10px 14px; font-weight:700;">Frontend UI</td>
                            <td style="padding:10px 14px;"><span class="tech-pill"><i class="fab fa-js"></i> Vanilla ES6+ &amp; CSS3 Variables</span></td>
                            <td style="padding:10px 14px; color:var(--text-muted);">Fetch API async, zero compilation build runtime.</td>
                        </tr>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding:10px 14px; font-weight:700;">Ikon Sentral</td>
                            <td style="padding:10px 14px;"><span class="tech-pill"><i class="fas fa-icons"></i> Iconify Solar Icons 2.1</span></td>
                            <td style="padding:10px 14px; color:var(--text-muted);">Deferred web components berpadu FontAwesome 6 fallback.</td>
                        </tr>
                        <tr>
                            <td style="padding:10px 14px; font-weight:700;">Aplikasi Ujian</td>
                            <td style="padding:10px 14px;"><span class="tech-pill"><i class="fab fa-android"></i> Kotlin Native Android APK</span></td>
                            <td style="padding:10px 14px; color:var(--text-muted);">Kiosk Mode Hardware Lock, anti bypass, ukuran ringkas 5.8 MB.</td>
                        </tr>
                    </table>
                </section>

                <!-- 4. KEAMANAN SISTEM -->
                <section class="doc-section" id="keamanan">
                    <div class="section-header">
                        <div class="section-icon-box">
                            <iconify-icon icon="solar:shield-warning-bold"></iconify-icon>
                        </div>
                        <h2 class="section-title">Keamanan Berlapis (Guardian Dome)</h2>
                    </div>
                    <p class="lead-text">
                        Keamanan data akademik dan integritas penilaian diproteksi melalui 5 lapis pertahanan siber aktif:
                    </p>
                    <ul style="padding-left: 20px; color: var(--text-muted); font-size: 14px; line-height: 1.8;">
                        <li><strong>Web Application Firewall (Guardian Dome WAF):</strong> Proteksi aktif dari serangan SQL Injection, Cross-Site Scripting (XSS), Brute Force, dan manipulasi parameter HTTP.</li>
                        <li><strong>100% Prepared Statements:</strong> Seluruh interaksi database wajib menggunakan parameterisasi PDO untuk memitigasi celah injeksi SQL.</li>
                        <li><strong>Enkripsi Password Searah:</strong> Menggunakan standar industri <code>password_hash()</code> dengan algoritma BCRYPT / ARGON2.</li>
                        <li><strong>Strict Session &amp; CSRF Defense:</strong> Setiap form dan transaksi AJAX diverifikasi menggunakan token CSRF kriptografis unik berbasis sesi.</li>
                        <li><strong>Role-Based Access Control (RBAC):</strong> Pembatasan hak akses yang kaku antara Administrator, Guru Pengajar, Siswa, dan Ketua Kelas.</li>
                    </ul>
                </section>

                <!-- 5. FILOSOFI DESAIN -->
                <section class="doc-section" id="filosofi-desain">
                    <div class="section-header">
                        <div class="section-icon-box">
                            <iconify-icon icon="solar:palette-bold"></iconify-icon>
                        </div>
                        <h2 class="section-title">Filosofi Desain Lumina Gold &amp; Nusantara</h2>
                    </div>
                    <p class="lead-text">
                        Antarmuka Ilmuna Learn dirancang dengan prinsip estetika tinggi, konsistensi warna yang ramah mata, dan filosofi <em>"Data-First"</em>:
                    </p>
                    <div class="badge-list" style="margin-bottom: 20px;">
                        <span class="tech-pill" style="border-color:#1D3B5E; color:#1D3B5E;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#1D3B5E;"></span> Deep Navy (#1D3B5E) — Ketegasan &amp; Wibawa Akademik</span>
                        <span class="tech-pill" style="border-color:#0D7A87; color:#0D7A87;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#0D7A87;"></span> Nusantara Teal (#0D7A87) — Kesejukan &amp; Kedalaman Ilmu</span>
                        <span class="tech-pill" style="border-color:#52B2DC; color:#52B2DC;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#52B2DC;"></span> Sky Blue (#52B2DC) — Kecerdasan &amp; Inovasi Digital</span>
                        <span class="tech-pill" style="border-color:#F59E0B; color:#D97706;"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#F59E0B;"></span> Lumina Gold (#F59E0B) — Kemuliaan Adab &amp; Prestasi Luhur</span>
                    </div>
                    <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.7;">
                        Dengan filosofi <em>Zero Title / Zero Subtitle</em> pada halaman data, pengguna langsung dihadapkan pada tabel, kartu, dan formulir utama tanpa pemborosan ruang layar (tanpa banner hero yang memakan tempat), memastikan produktivitas maksimal di layar monitor maupun smartphone 360px.
                    </p>
                </section>
            </div>
        </div>
    </main>

    <!-- FOOTER RESMI -->
    <footer class="footer-bottom">
        <p>&copy; <?= date('Y') ?> <strong><?= htmlspecialchars(APP_NAME) ?></strong> — Sistem Informasi Akademik &amp; Manajemen Pembelajaran Nusantara.</p>
        <div class="footer-links">
            <a href="<?= $baseUrl ?>/about" class="footer-link">Tentang</a>
            <span>•</span>
            <a href="<?= $baseUrl ?>/terms" class="footer-link">Syarat &amp; Ketentuan</a>
            <span>•</span>
            <a href="<?= $baseUrl ?>/privacy" class="footer-link">Kebijakan Privasi</a>
            <span>•</span>
            <a href="<?= $baseUrl ?>/download-exam" class="footer-link">Exam Browser APK</a>
        </div>
    </footer>
</body>
</html>

