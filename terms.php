<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — SYARAT & KETENTUAN PENGGUNAAN (TERMS OF SERVICE)
 * ==============================================================================
 * Ketentuan hukum dan tata tertib penggunaan platform Ilmuna Learn SIAKAD & LMS.
 * Mengacu pada UU ITE No. 1 Tahun 2024 / UU No. 11 Tahun 2008 serta regulasi
 * akademik madrasah/sekolah dan standar CBT & Presensi Geofencing.
 * 
 * Standar Desain (Sesuai README.md):
 * - Tema Lumina Gold: Navy (#1D3B5E), Teal (#0D7A87), Sky (#52B2DC), Gold (#F59E0B)
 * - Ornamen Nusantara: Watermark Gunungan Wayang & Pola Batik Kawung
 * - Tipografi: Plus Jakarta Sans & JetBrains Mono
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

$pageTitle = 'Syarat & Ketentuan Layanan — Ilmuna Learn';
$activeNav  = 'terms';
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

    <!-- FontAwesome 6 & Iconify Solar Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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

        /* WATERMARK ORNAMEN NUSANTARA */
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
            background: linear-gradient(135deg, var(--navy) 0%, #1c3653 60%, var(--teal-dark) 100%);
            border-radius: var(--radius-xl);
            padding: 40px;
            color: #FFFFFF;
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
            margin-bottom: 32px;
            border: 1px solid rgba(255, 255, 255, 0.1);
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
            font-size: 30px;
            font-weight: 800;
            line-height: 1.25;
            letter-spacing: -0.02em;
            margin-bottom: 12px;
        }

        .hero-subtitle {
            font-size: 15px;
            color: #E2E8F0;
            max-width: 820px;
            line-height: 1.7;
            margin-bottom: 20px;
        }

        .hero-meta-row {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: center;
            font-size: 12.5px;
            color: #CBD5E1;
            padding-top: 16px;
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

        /* GRID DOKUMEN */
        .content-grid {
            display: grid;
            grid-template-columns: 280px 1fr;
            gap: 28px;
            align-items: start;
        }

        /* SIDEBAR STICKY */
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

        /* SECTIONS */
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
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .section-icon-box {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--sky-fade) 0%, var(--gold-fade) 100%);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--teal-dark);
            font-size: 18px;
        }

        .section-title {
            font-size: 19px;
            font-weight: 800;
            color: var(--navy);
            letter-spacing: -0.01em;
        }

        .doc-p {
            font-size: 14.5px;
            color: var(--text-main);
            line-height: 1.75;
            margin-bottom: 14px;
        }

        .doc-list {
            padding-left: 20px;
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.8;
            margin-bottom: 16px;
        }

        .doc-list li {
            margin-bottom: 6px;
        }

        .doc-list strong {
            color: var(--text-main);
        }

        /* NOTICE BOX */
        .notice-box {
            background: #FEF2F2;
            border-left: 4px solid var(--rose);
            border-radius: 0 var(--radius-md) var(--radius-md) 0;
            padding: 16px 20px;
            margin: 18px 0;
            font-size: 13.5px;
            color: #991B1B;
        }

        .notice-box strong {
            color: #7F1D1D;
        }

        .info-box {
            background: var(--sky-fade);
            border-left: 4px solid var(--sky);
            border-radius: 0 var(--radius-md) var(--radius-md) 0;
            padding: 16px 20px;
            margin: 18px 0;
            font-size: 13.5px;
            color: var(--navy-dark);
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

        @media (max-width: 900px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
            .sidebar-nav-card {
                display: none;
            }
            .hero-card {
                padding: 28px 20px;
            }
            .hero-title {
                font-size: 23px;
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
                <a href="<?= $baseUrl ?>/about" class="nav-item">Tentang</a>
                <a href="<?= $baseUrl ?>/terms" class="nav-item active">Syarat</a>
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
        <section class="hero-card">
            <div class="hero-badge">
                <iconify-icon icon="solar:document-text-bold"></iconify-icon>
                <span>Dokumen Resmi Ketentuan Penggunaan</span>
            </div>
            <h1 class="hero-title">Syarat &amp; Ketentuan Layanan (Terms of Service)</h1>
            <p class="hero-subtitle">
                Aturan, hak, kewajiban, dan tata tertib resmi bagi seluruh pengguna (Administrator, Guru Pengajar, Siswa, dan Pengawas) dalam mengakses platform Ilmuna Learn SIAKAD &amp; LMS.
            </p>

            <div class="hero-meta-row">
                <div class="hero-meta-item">
                    <iconify-icon icon="solar:calendar-date-bold"></iconify-icon>
                    <span>Terakhir Diperbarui: 06 November 2026</span>
                </div>
                <div class="hero-meta-item">
                    <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
                    <span>Yurisdiksi: Hukum Republik Indonesia</span>
                </div>
                <div class="hero-meta-item">
                    <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                    <span>Berlaku Efektif: Seluruh Ekosistem Sekolah</span>
                </div>
            </div>
        </section>

        <div class="content-grid">
            <!-- SIDEBAR STICKY -->
            <aside class="sidebar-nav-card" aria-label="Daftar Isi Ketentuan">
                <div class="sidebar-title">
                    <iconify-icon icon="solar:list-check-bold"></iconify-icon>
                    <span>Pasal Ketentuan</span>
                </div>
                <ul class="sidebar-links">
                    <li><a href="#pasal-1" class="sidebar-link"><iconify-icon icon="solar:notes-bold"></iconify-icon> 1. Ketentuan Umum</a></li>
                    <li><a href="#pasal-2" class="sidebar-link"><iconify-icon icon="solar:user-id-bold"></iconify-icon> 2. Akun &amp; Kredensial</a></li>
                    <li><a href="#pasal-3" class="sidebar-link"><iconify-icon icon="solar:shield-warning-bold"></iconify-icon> 3. Tata Tertib CBT Kiosk</a></li>
                    <li><a href="#pasal-4" class="sidebar-link"><iconify-icon icon="solar:gps-bold"></iconify-icon> 4. Presensi Geofencing</a></li>
                    <li><a href="#pasal-5" class="sidebar-link"><iconify-icon icon="solar:copyright-bold"></iconify-icon> 5. HAKI &amp; Konten</a></li>
                    <li><a href="#pasal-6" class="sidebar-link"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> 6. Sanksi Pelanggaran</a></li>
                    <li><a href="#pasal-7" class="sidebar-link"><iconify-icon icon="solar:restart-bold"></iconify-icon> 7. Perubahan Aturan</a></li>
                </ul>
            </aside>

            <!-- KONTEN PASAL -->
            <div class="content-body">
                <!-- PASAL 1 -->
                <section class="doc-section" id="pasal-1">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:notes-bold"></iconify-icon></div>
                        <h2 class="section-title">Pasal 1: Ketentuan Umum &amp; Penerimaan Layanan</h2>
                    </div>
                    <p class="doc-p">
                        1.1. Dengan mengakses atau menggunakan aplikasi Ilmuna Learn (berbasis web browser maupun aplikasi Android Ilmuna Exam Browser), Anda menyatakan telah membaca, memahami, dan menyetujui untuk terikat secara hukum pada Syarat dan Ketentuan ini.
                    </p>
                    <p class="doc-p">
                        1.2. Layanan Ilmuna Learn disediakan secara eksklusif untuk civitas akademika lembaga sekolah/madrasah yang terdaftar resmi. Penggunaan di luar peruntukan pendidikan tanpa izin tertulis dari pihak sekolah merupakan pelanggaran hak penggunaan.
                    </p>
                </section>

                <!-- PASAL 2 -->
                <section class="doc-section" id="pasal-2">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:user-id-bold"></iconify-icon></div>
                        <h2 class="section-title">Pasal 2: Akun Pengguna, Keamanan &amp; Kredensial</h2>
                    </div>
                    <p class="doc-p">
                        2.1. Setiap pengguna (Admin, Guru, Siswa, Ketua Kelas) bertanggung jawab penuh menjaga kerahasiaan username, password, dan token sesi akun masing-masing.
                    </p>
                    <p class="doc-p">
                        2.2. Dilarang keras meminjamkan, memindahtangankan, atau memperjualbelikan akun Ilmuna Learn kepada pihak mana pun. Segala aktivitas yang terjadi melalui akun Anda dianggap sah sebagai tindakan pemilik akun terdaftar.
                    </p>
                    <div class="info-box">
                        <strong>Kewajiban Pengguna:</strong> Apabila Anda menduga adanya kebocoran kredensial atau akses tanpa hak, segera lakukan pergantian password atau laporkan ke Tim IT Administrator Sekolah.
                    </div>
                </section>

                <!-- PASAL 3 -->
                <section class="doc-section" id="pasal-3">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:shield-warning-bold"></iconify-icon></div>
                        <h2 class="section-title">Pasal 3: Tata Tertib Integritas CBT &amp; Kiosk Mode</h2>
                    </div>
                    <p class="doc-p">
                        3.1. Ujian berbasis komputer (CBT) wajib dikerjakan secara jujur, mandiri, dan beradab. Peserta didik diwajibkan menggunakan aplikasi resmi <strong>Ilmuna Exam Browser (Android Kiosk)</strong> sesuai instruksi pengawas ruang.
                    </p>
                    <p class="doc-p">
                        3.2. Tindakan-tindakan berikut diklasifikasikan sebagai <strong>Pelanggaran Berat Integritas Ujian</strong>:
                    </p>
                    <ul class="doc-list">
                        <li>Membuka aplikasi lain, tab browser ganda, pop-up floating apps, atau membagi layar (*split screen*).</li>
                        <li>Mengambil tangkapan layar (*screenshot*), merekam layar (*screen record*), atau menyalin teks butir soal.</li>
                        <li>Menggunakan kecerdasan buatan (AI) ilegal, search engine, atau bantuan pihak ketiga saat ujian berlangsung.</li>
                        <li>Mencoba membypass proteksi *Kiosk Mode* atau memodifikasi biner APK ujian.</li>
                    </ul>
                    <div class="notice-box">
                        <strong>Konsekuensi Teknis:</strong> Sistem otomatis mendeteksi perpindahan fokus layar dan akan <strong>Membekukan Sesi Ujian (Session Frozen)</strong> secara seketika. Siswa yang terkunci wajib melapor kepada Pengawas Ujian dengan PIN Reset khusus (123456) dan riwayat pelanggaran tercatat permanen di log server.
                    </div>
                </section>

                <!-- PASAL 4 -->
                <section class="doc-section" id="pasal-4">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:gps-bold"></iconify-icon></div>
                        <h2 class="section-title">Pasal 4: Ketentuan Presensi Satelit GPS &amp; QR Code</h2>
                    </div>
                    <p class="doc-p">
                        4.1. Presensi digital kehadiran siswa dan guru menggunakan validasi satelit koordinat GPS (Formula Haversine) dengan batas radius gerbang sekolah yang telah dikonfigurasi.
                    </p>
                    <p class="doc-p">
                        4.2. Dilarang keras memalsukan lokasi menggunakan aplikasi <em>Fake GPS, Mock Location Provider, Location Spoofer,</em> atau menjalankan sistem di dalam emulator/perangkat yang di-root.
                    </p>
                    <p class="doc-p">
                        4.3. Presensi yang terdeteksi dilakukan menggunakan lokasi tiruan atau manipulasi jam lokal perangkat akan ditandai otomatis sebagai <strong>Presensi Tidak Sah / Alpa</strong>.
                    </p>
                </section>

                <!-- PASAL 5 -->
                <section class="doc-section" id="pasal-5">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:copyright-bold"></iconify-icon></div>
                        <h2 class="section-title">Pasal 5: Hak Kekayaan Intelektual (HAKI)</h2>
                    </div>
                    <p class="doc-p">
                        5.1. Seluruh kode sumber, basis data, arsitektur sistem, desain grafis Lumina Gold, ikonografi, dan identitas merek Ilmuna Learn dilindungi oleh Undang-Undang Hak Cipta Republik Indonesia.
                    </p>
                    <p class="doc-p">
                        5.2. Seluruh materi bahan ajar, modul ajar, perangkat evaluasi, dan butir soal ujian yang diunggah oleh guru pengajar adalah milik sah lembaga madrasah/guru bersangkutan dan dilindungi kerahasiaannya.
                    </p>
                </section>

                <!-- PASAL 6 -->
                <section class="doc-section" id="pasal-6">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon></div>
                        <h2 class="section-title">Pasal 6: Sanksi Pelanggaran &amp; Penangguhan Akun</h2>
                    </div>
                    <p class="doc-p">
                        Pihak sekolah berhak memberikan sanksi administratif dan akademik terhadap pengguna yang terbukti melanggar Syarat &amp; Ketentuan ini, meliputi:
                    </p>
                    <ul class="doc-list">
                        <li>Pemberian peringatan tertulis dan pembekuan sementara akun Ilmuna Learn.</li>
                        <li>Pembatalan nilai asesmen CBT bersangkutan (diberikan nilai 0) dan kewajiban mengikuti ujian susulan dengan pengawasan ketat.</li>
                        <li>Pencabutan permanen hak akses terhadap sistem pembelajaran digital madrasah.</li>
                    </ul>
                </section>

                <!-- PASAL 7 -->
                <section class="doc-section" id="pasal-7">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:restart-bold"></iconify-icon></div>
                        <h2 class="section-title">Pasal 7: Pembaruan Ketentuan &amp; Hukum yang Berlaku</h2>
                    </div>
                    <p class="doc-p">
                        7.1. Pengelola madrasah berhak memperbarui dokumen Syarat &amp; Ketentuan ini sewaktu-waktu sesuai dengan penyesuaian kurikulum dan regulasi kementerian terkait.
                    </p>
                    <p class="doc-p">
                        7.2. Syarat dan Ketentuan ini tunduk pada dan ditafsirkan sesuai dengan hukum Negara Kesatuan Republik Indonesia.
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

