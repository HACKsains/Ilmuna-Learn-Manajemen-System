<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — KEBIJAKAN PRIVASI & PERLINDUNGAN DATA (PRIVACY POLICY)
 * ==============================================================================
 * Kebijakan resmi perlindungan data pribadi civitas akademika Ilmuna Learn SIAKAD & LMS.
 * Disusun berdasarkan Undang-Undang Republik Indonesia Nomor 27 Tahun 2022 tentang
 * Perlindungan Data Pribadi (UU PDP) dan standar keamanan siber pendidikan.
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

$pageTitle = 'Kebijakan Privasi — Ilmuna Learn';
$activeNav  = 'privacy';
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
            background: linear-gradient(135deg, var(--navy) 0%, #153352 60%, var(--teal-dark) 100%);
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

        .callout-shield {
            background: #ECFDF5;
            border-left: 4px solid var(--emerald);
            border-radius: 0 var(--radius-md) var(--radius-md) 0;
            padding: 16px 20px;
            margin: 18px 0;
            font-size: 13.5px;
            color: #065F46;
        }

        .callout-shield strong {
            color: #047857;
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

        /* DATA ITEM BOX */
        .data-card {
            background: #F8FAFC;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 16px;
            margin-bottom: 12px;
        }

        .data-card-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--navy);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .data-card-desc {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
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
                <a href="<?= $baseUrl ?>/terms" class="nav-item">Syarat</a>
                <a href="<?= $baseUrl ?>/privacy" class="nav-item active">Privasi</a>
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
                <iconify-icon icon="solar:shield-check-bold"></iconify-icon>
                <span>Standar Perlindungan Data Pribadi (UU No. 27/2022)</span>
            </div>
            <h1 class="hero-title">Kebijakan Privasi (Privacy Policy)</h1>
            <p class="hero-subtitle">
                Komitmen perlindungan, transparansi pengelolaan data pribadi, dan jaminan keamanan rekam jejak akademik bagi peserta didik, guru, serta tenaga kependidikan di Ilmuna Learn.
            </p>

            <div class="hero-meta-row">
                <div class="hero-meta-item">
                    <iconify-icon icon="solar:calendar-date-bold"></iconify-icon>
                    <span>Terakhir Diperbarui: 06 November 2026</span>
                </div>
                <div class="hero-meta-item">
                    <iconify-icon icon="solar:lock-bold"></iconify-icon>
                    <span>Enkripsi Database: BCRYPT &amp; TLS 1.3</span>
                </div>
                <div class="hero-meta-item">
                    <iconify-icon icon="solar:user-block-rounded-bold"></iconify-icon>
                    <span>Zero Commercialization: Data Tidak Diperjualbelikan</span>
                </div>
            </div>
        </section>

        <div class="content-grid">
            <!-- SIDEBAR STICKY -->
            <aside class="sidebar-nav-card" aria-label="Daftar Isi Privasi">
                <div class="sidebar-title">
                    <iconify-icon icon="solar:list-check-bold"></iconify-icon>
                    <span>Daftar Bab Privasi</span>
                </div>
                <ul class="sidebar-links">
                    <li><a href="#komitmen" class="sidebar-link"><iconify-icon icon="solar:shield-star-bold"></iconify-icon> 1. Komitmen Privasi</a></li>
                    <li><a href="#data-dikumpulkan" class="sidebar-link"><iconify-icon icon="solar:database-bold"></iconify-icon> 2. Data yang Dikumpulkan</a></li>
                    <li><a href="#tujuan-penggunaan" class="sidebar-link"><iconify-icon icon="solar:target-bold"></iconify-icon> 3. Tujuan Pemrosesan</a></li>
                    <li><a href="#lokasi-presensi" class="sidebar-link"><iconify-icon icon="solar:gps-bold"></iconify-icon> 4. Lokasi &amp; Presensi GPS</a></li>
                    <li><a href="#keamanan-enkripsi" class="sidebar-link"><iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon> 5. Keamanan Data</a></li>
                    <li><a href="#hak-subjek-data" class="sidebar-link"><iconify-icon icon="solar:user-check-bold"></iconify-icon> 6. Hak Pengguna Data</a></li>
                </ul>
            </aside>

            <!-- KONTEN PRIVASI -->
            <div class="content-body">
                <!-- 1. KOMITMEN PRIVASI -->
                <section class="doc-section" id="komitmen">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:shield-star-bold"></iconify-icon></div>
                        <h2 class="section-title">1. Komitmen Privasi Pendidikan</h2>
                    </div>
                    <p class="doc-p">
                        Ilmuna Learn memegang teguh prinsip bahwa data akademik, data karakter siswa, serta rekam pembiasaan ibadah adalah amanah luhur yang wajib dilindungi kerahasiaannya.
                    </p>
                    <div class="callout-shield">
                        <strong>Prinsip Bebas Komersialisasi:</strong> Kami <strong>TIDAK PERNAH</strong> dan <strong>TIDAK AKAN PERNAH</strong> menjual, menyewakan, atau membagikan data siswa, guru, maupun nilai sekolah kepada perusahaan iklan, data broker, atau pihak ketiga mana pun untuk tujuan monetisasi komersial.
                    </div>
                </section>

                <!-- 2. DATA YANG DIKUMPULKAN -->
                <section class="doc-section" id="data-dikumpulkan">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:database-bold"></iconify-icon></div>
                        <h2 class="section-title">2. Kategori Data yang Dikumpulkan</h2>
                    </div>
                    <p class="doc-p">
                        Sistem hanya mengumpulkan informasi yang esensial untuk kebutuhan administrasi akademik sekolah/madrasah:
                    </p>

                    <div class="data-card">
                        <div class="data-card-title">
                            <iconify-icon icon="solar:user-id-bold" style="color:var(--teal);"></iconify-icon>
                            <span>Data Identitas Akademik</span>
                        </div>
                        <p class="data-card-desc">
                            Nama lengkap, Nomor Induk Siswa Nasional (NISN) / NIP Guru, jenis kelamin, kelas/rombel, email resmi madrasah, dan foto profil.
                        </p>
                    </div>

                    <div class="data-card">
                        <div class="data-card-title">
                            <iconify-icon icon="solar:diploma-verified-bold" style="color:var(--teal);"></iconify-icon>
                            <span>Data Evaluasi, CBT &amp; Mutaba'ah Ibadah</span>
                        </div>
                        <p class="data-card-desc">
                            Nilai tugas formatif &amp; sumatif, catatan butir jawaban asesmen CBT, jurnal pembiasaan ibadah yaumiyah (sholat fardhu 5 waktu, dhuha, tahajud, tilawah), serta rekapitulasi capaian E-Rapor Kurikulum Merdeka.
                        </p>
                    </div>

                    <div class="data-card">
                        <div class="data-card-title">
                            <iconify-icon icon="solar:server-square-bold" style="color:var(--teal);"></iconify-icon>
                            <span>Data Teknis &amp; Integritas Akses</span>
                        </div>
                        <p class="data-card-desc">
                            Alamat IP, tipe perangkat &amp; User-Agent, status browser (deteksi Kiosk Mode aman), serta log keamanan Web Application Firewall (WAF) untuk mencegah upaya pembobolan sistem.
                        </p>
                    </div>
                </section>

                <!-- 3. TUJUAN PENGGUNAAN -->
                <section class="doc-section" id="tujuan-penggunaan">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:target-bold"></iconify-icon></div>
                        <h2 class="section-title">3. Tujuan Pemrosesan Data</h2>
                    </div>
                    <p class="doc-p">
                        Seluruh data yang diproses oleh Ilmuna Learn digunakan semata-mata untuk:
                    </p>
                    <ul class="doc-list">
                        <li>Mengelola administrasi kesiswaan, rombongan belajar, dan penugasan mata pelajaran oleh guru.</li>
                        <li>Memfasilitasi pengerjaan asesmen daring (CBT) dan penghitungan nilai otomatis yang transparan.</li>
                        <li>Mencatat rekap presensi kehadiran harian siswa secara akurat dan anti-kecurangan.</li>
                        <li>Menyajikan buku rapor digital (E-Rapor) lengkap dengan deskripsi capaian pembelajaran Kurikulum Merdeka.</li>
                        <li>Menjaga keamanan akun dan mencegah serangan siber terhadap basis data sekolah.</li>
                    </ul>
                </section>

                <!-- 4. LOKASI PRESENSI GPS -->
                <section class="doc-section" id="lokasi-presensi">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:gps-bold"></iconify-icon></div>
                        <h2 class="section-title">4. Kebijakan Khusus Data Geolokasi (GPS)</h2>
                    </div>
                    <p class="doc-p">
                        Sistem presensi mandiri Ilmuna Learn menggunakan koordinat satelit GPS demi menegakkan kedisiplinan kehadiran di lingkungan madrasah:
                    </p>
                    <div class="info-box">
                        <strong>Transparansi Akses Lokasi:</strong>
                        <ul style="padding-left: 18px; margin-top: 6px; line-height: 1.7;">
                            <li>Koordinat GPS (Latitude &amp; Longitude) <strong>HANYA diakses pada detik ketika pengguna menekan tombol "Kirim Presensi"</strong>.</li>
                            <li>Sistem <strong>TIDAK MELAKUKAN pelacakan lokasi kontinu (background tracking)</strong> setelah tombol ditekan atau ketika aplikasi ditutup.</li>
                            <li>Koordinat digunakan seketika oleh algoritma Formula Haversine untuk mengukur jarak terhadap titik koordinat gerbang sekolah.</li>
                        </ul>
                    </div>
                </section>

                <!-- 5. KEAMANAN & ENKRIPSI -->
                <section class="doc-section" id="keamanan-enkripsi">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:lock-keyhole-bold"></iconify-icon></div>
                        <h2 class="section-title">5. Standar Keamanan &amp; Penyimpanan Data</h2>
                    </div>
                    <p class="doc-p">
                        Kami menerapkan standar keamanan teknis dan organisasi setara industri:
                    </p>
                    <ul class="doc-list">
                        <li><strong>Enkripsi Password Kriptografis:</strong> Password seluruh pengguna di-hash secara permanen dengan algoritma <code>BCRYPT / ARGON2</code> bergaram acak (*salted*). Pengelola sistem maupun staf madrasah tidak dapat membaca password asli Anda.</li>
                        <li><strong>Enkripsi Transportasi Data (TLS/HTTPS):</strong> Seluruh lalu lintas transmisi antara browser pengguna dan server diamankan dengan sertifikat enkripsi SSL/TLS 256-bit.</li>
                        <li><strong>Guardian Dome WAF:</strong> Lapis pelindung terdepan yang menepis serangan injeksi, brute force, dan manipulasi session token.</li>
                    </ul>
                </section>

                <!-- 6. HAK SUBJEK DATA -->
                <section class="doc-section" id="hak-subjek-data">
                    <div class="section-header">
                        <div class="section-icon-box"><iconify-icon icon="solar:user-check-bold"></iconify-icon></div>
                        <h2 class="section-title">6. Hak-Hak Pengguna Data Pribadi</h2>
                    </div>
                    <p class="doc-p">
                        Sesuai dengan ketentuan UU PDP No. 27 Tahun 2022, setiap siswa, guru, dan wali murid memiliki hak:
                    </p>
                    <ul class="doc-list">
                        <li><strong>Hak Akses &amp; Transparansi:</strong> Mengetahui seluruh data nilai, presensi, dan riwayat mutaba'ah yang tersimpan di sistem melalui portal masing-masing.</li>
                        <li><strong>Hak Koreksi (Pembetulan):</strong> Mengajukan koreksi terhadap kesalahan penulisan nama, NISN, atau kelas melalui Wali Kelas atau Operator TU Madrasah.</li>
                        <li><strong>Hak Keamanan:</strong> Mendapatkan jaminan bahwa rekam jejak akademik tersimpan utuh dan tidak disalahgunakan oleh pihak tidak berwenang.</li>
                    </ul>
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

