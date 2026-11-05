<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — HALAMAN PROFIL PENGGUNA TERINTEGRASI
 * ==============================================================================
 * Single Source of Truth: skema-tabel-kelas.sql & skema-sekolah-ilmuna.sql
 * 
 * Fitur Utama:
 * 1. Mode Tampilan WhatsApp iOS di Mobile (Fixed Edit & Bright UI)
 * 2. Mode Desktop Premium (SaaS Light Theme Cohesive)
 * 3. Dua Tab Pengaturan Sesuai Role
 * 4. Sinkronisasi Foto & Akun Google SSO
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/base-template.php';

// Pastikan pengguna sudah login
requireAuth();

$currentUser = getCurrentUser();
$userId      = (int)$currentUser['id'];
$activeRole  = getActiveRole() ?? 'siswa';
$pdo         = getDBConnection();
$backUrl     = function_exists('getRoleDashboardUrl') ? getRoleDashboardUrl($activeRole) : BASE_URL . '/';

// Direct AJAX fallback: jika request ke profile.php membawa parameter action, teruskan ke API handler
if (isset($_POST['action']) || isset($_GET['action'])) {
    require_once __DIR__ . '/api/profile-action.php';
    exit;
}

// -----------------------------------------------------------------------------
// 1. AMBIL DATA USER LENGKAP DARI TABEL `users`
// -----------------------------------------------------------------------------
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$userId]);
$userData = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$username    = $userData['username'] ?? $currentUser['username'] ?? '';
$fullName    = $userData['full_name'] ?? $userData['nama_lengkap'] ?? $currentUser['full_name'] ?? $username;
$email       = $userData['email'] ?? $currentUser['email'] ?? '';
$emailGoogle = $userData['email_google'] ?? null;
$googleId    = $userData['google_id'] ?? null;
$phone       = $userData['phone'] ?? $userData['no_hp'] ?? '';
$userPhoto   = $userData['foto_profil'] ?? $userData['avatar'] ?? $currentUser['avatar'] ?? '';
$createdAt   = $userData['created_at'] ?? '';
$isActive    = isset($userData['is_active']) ? (int)$userData['is_active'] : (isset($userData['status']) && $userData['status'] === 'active' ? 1 : 1);

// Cek apakah ada permintaan penautan Google yang sedang menunggu verifikasi email
$pendingGoogleLink = null;
try {
    $pStmt = $pdo->prepare("SELECT email_google, expires_at, created_at FROM google_link_verifications WHERE user_id = ? AND verified_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1");
    $pStmt->execute([$userId]);
    $pendingGoogleLink = $pStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pendingGoogleLink = null;
}

// Cek format foto
$displayPhoto = '';
if (!empty($userPhoto)) {
    if (strpos($userPhoto, 'http://') === 0 || strpos($userPhoto, 'https://') === 0) {
        $displayPhoto = $userPhoto;
    } else {
        $displayPhoto = BASE_URL . '/' . ltrim($userPhoto, '/');
    }
}

// Inisial untuk fallback avatar
$inisial = '';
$parts = preg_split('/\s+/', trim($fullName));
if (!empty($parts)) {
    $inisial .= mb_substr($parts[0], 0, 1);
    if (isset($parts[1])) {
        $inisial .= mb_substr($parts[1], 0, 1);
    }
}
$inisial = mb_strtoupper($inisial ?: mb_substr($username, 0, 2));

// -----------------------------------------------------------------------------
// 2. AMBIL DATA PRIBADI BERDASARKAN ROLE AKTIF (`siswa` / `guru`)
// -----------------------------------------------------------------------------
$personalData = null;
$personalType = null; // 'siswa' | 'guru' | null
$kelasList    = [];

// Cek apakah tabel siswa ada
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM siswa");
    $siswaCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    $hasSiswaTable = !empty($siswaCols);
} catch (Exception $e) {
    $hasSiswaTable = false;
    $siswaCols = [];
}

// Cek apakah tabel guru ada
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM guru");
    $guruCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    $hasGuruTable = !empty($guruCols);
} catch (Exception $e) {
    $hasGuruTable = false;
    $guruCols = [];
}

// Ambil daftar kelas
try {
    $kelasList = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $kelasList = [];
}

if ($activeRole === 'siswa' || (!hasRole('admin') && !hasRole('guru') && $hasSiswaTable)) {
    $personalType = 'siswa';
    $siswaRow = null;
    if (in_array('id_user', $siswaCols, true)) {
        $sStmt = $pdo->prepare("SELECT s.*, k.nama_kelas, k.tahun_pelajaran FROM siswa s LEFT JOIN kelas k ON s.id_kelas = k.id WHERE s.id_user = ? LIMIT 1");
        $sStmt->execute([$userId]);
        $siswaRow = $sStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$siswaRow && in_array('nisn', $siswaCols, true)) {
        $sStmt = $pdo->prepare("SELECT s.*, k.nama_kelas, k.tahun_pelajaran FROM siswa s LEFT JOIN kelas k ON s.id_kelas = k.id WHERE s.nisn = ? OR s.nis = ? LIMIT 1");
        $sStmt->execute([$username, $username]);
        $siswaRow = $sStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$siswaRow && in_array('gmail', $siswaCols, true) && !empty($email)) {
        $sStmt = $pdo->prepare("SELECT s.*, k.nama_kelas, k.tahun_pelajaran FROM siswa s LEFT JOIN kelas k ON s.id_kelas = k.id WHERE s.gmail = ? LIMIT 1");
        $sStmt->execute([$email]);
        $siswaRow = $sStmt->fetch(PDO::FETCH_ASSOC);
    }
    $personalData = $siswaRow;
} elseif ($activeRole === 'guru' || $activeRole === 'admin' || hasRole('guru') || hasRole('admin')) {
    $personalType = 'guru';
    $guruRow = null;
    if (in_array('id_user', $guruCols, true)) {
        $gStmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
        $gStmt->execute([$userId]);
        $guruRow = $gStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$guruRow && in_array('nip', $guruCols, true)) {
        $gStmt = $pdo->prepare("SELECT * FROM guru WHERE nip = ? OR nuptk = ? LIMIT 1");
        $gStmt->execute([$username, $username]);
        $guruRow = $gStmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$guruRow && in_array('email', $guruCols, true) && !empty($email)) {
        $gStmt = $pdo->prepare("SELECT * FROM guru WHERE email = ? LIMIT 1");
        $gStmt->execute([$email]);
        $guruRow = $gStmt->fetch(PDO::FETCH_ASSOC);
    }
    $personalData = $guruRow;
}

// -----------------------------------------------------------------------------
// RENDER KONTEN HTML HALAMAN PROFIL
// -----------------------------------------------------------------------------
ob_start();
?>

<!-- NATIVE iOS NAVIGATION BAR (HANYA MUNCUL DI MOBILE) -->
<header class="ios-navbar" id="iosNavbar">
    <div class="ios-navbar-inner">
        <a href="<?= htmlspecialchars($backUrl) ?>" class="ios-back-btn" onclick="handleIosBack(event, '<?= htmlspecialchars($backUrl) ?>')" aria-label="Kembali">
            <iconify-icon icon="solar:alt-arrow-left-outline" width="24"></iconify-icon>
            <span>Kembali</span>
        </a>
        <h1 class="ios-nav-title">Profil</h1>
        <div class="ios-nav-action">
            <!-- Space for layout balance -->
        </div>
    </div>
</header>

<div class="ilmuna-profile-container">

    <!-- HERO HEADER CARD / iOS PROFILE HERO -->
    <div class="profile-hero-card">
        <div class="hero-bg-shapes">
            <div class="shape shape-1"></div>
            <div class="shape shape-2"></div>
            <div class="shape shape-3"></div>
        </div>

        <div class="hero-content">
            <div class="hero-avatar-wrapper">
                <div class="hero-avatar-ring">
                    <div class="hero-avatar" id="heroAvatarContainer" onclick="handleHeroAvatarClick(event)" title="Ketuk untuk mengatur foto profil">
                        <?php if (!empty($displayPhoto)): ?>
                            <img src="<?= htmlspecialchars($displayPhoto) ?>" alt="Foto Profil" id="heroAvatarImg" loading="lazy">
                        <?php else: ?>
                            <div class="avatar-fallback" id="heroAvatarFallback"><?= htmlspecialchars($inisial) ?></div>
                        <?php endif; ?>
                        
                        <label for="quickPhotoInput" class="avatar-edit-badge" title="Ganti Foto Profil" onclick="event.stopPropagation()">
                            <iconify-icon icon="solar:camera-minimalistic-bold" width="24"></iconify-icon>
                        </label>
                        <input type="file" id="quickPhotoInput" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="handlePhotoUpload(this)">
                    </div>
                </div>
            </div>

            <div class="hero-info">
                <div class="hero-name-row">
                    <h1 class="hero-name" id="heroFullNameDisplay"><?= htmlspecialchars($fullName) ?></h1>
                    <span class="hero-role-badge role-<?= htmlspecialchars($activeRole) ?>">
                        <?= strtoupper($activeRole) ?>
                    </span>
                </div>

                <p class="hero-username">@<?= htmlspecialchars($username) ?></p>

                <div class="hero-meta-pills">
                    <span class="meta-pill">
                        <iconify-icon icon="solar:letter-bold" width="16"></iconify-icon>
                        <span id="heroEmailDisplay"><?= htmlspecialchars($email ?: 'Belum ada email') ?></span>
                    </span>

                    <?php if (!empty($emailGoogle)): ?>
                        <span class="meta-pill pill-google-connected" title="Terhubung dengan Google SSO">
                            <iconify-icon icon="logos:google-icon" width="14"></iconify-icon>
                            <span>SSO Aktif</span>
                        </span>
                    <?php endif; ?>

                    <?php if ($personalType === 'siswa' && !empty($personalData['nama_kelas'])): ?>
                        <span class="meta-pill pill-class">
                            <iconify-icon icon="solar:notebook-bookmark-bold" width="14"></iconify-icon>
                            <span><?= htmlspecialchars($personalData['nama_kelas']) ?></span>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- TAB NAVIGATION BAR / iOS SEGMENTED CONTROL -->
        <div class="profile-tabs-nav ios-segmented-control">
            <button type="button" class="tab-btn active" id="tabBtnAccount" onclick="switchProfileTab('account')">
                <iconify-icon icon="solar:shield-user-bold" width="18"></iconify-icon>
                <span>Akun</span>
            </button>
            <button type="button" class="tab-btn" id="tabBtnPersonal" onclick="switchProfileTab('personal')">
                <iconify-icon icon="<?= $personalType === 'siswa' ? 'solar:square-academic-cap-2-bold' : 'solar:user-id-bold' ?>" width="18"></iconify-icon>
                <span>Data Pribadi</span>
            </button>
        </div>
    </div>

    <!-- ======================================================================= -->
    <!-- TAB 1: DATA AKUN (users table)                                         -->
    <!-- ======================================================================= -->
    <div class="profile-tab-content active" id="tabContentAccount">
        <div class="profile-grid">
            
            <!-- KARTU 1: FOTO & SINKRONISASI -->
            <div class="profile-card card-avatar-sync ios-card">
                <div class="card-header">
                    <div class="card-header-icon theme-icon-bg">
                        <iconify-icon icon="solar:gallery-round-bold" width="24"></iconify-icon>
                    </div>
                    <div class="card-header-text">
                        <h3 class="card-title">Foto Profil & Sinkronisasi</h3>
                        <p class="card-subtitle">Otomatis sinkron ke akun portal dan data pokok</p>
                    </div>
                </div>

                <div class="avatar-sync-box">
                    <div class="avatar-sync-preview">
                        <div class="sync-photo-container" id="syncPhotoPreview">
                            <?php if (!empty($displayPhoto)): ?>
                                <img src="<?= htmlspecialchars($displayPhoto) ?>" alt="Foto Akun" loading="lazy">
                            <?php else: ?>
                                <div class="avatar-fallback-lg"><?= htmlspecialchars($inisial) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="photo-loading-spinner" id="photoSpinner">
                            <div class="spinner-ring"></div>
                        </div>
                    </div>

                    <div class="avatar-sync-actions">
                        <div class="sync-info-badge">
                            <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                            <span>Tersinkronisasi</span>
                        </div>
                        <div class="action-buttons-group">
                            <label for="profilePhotoInput" class="btn btn-primary btn-sm">
                                <iconify-icon icon="solar:upload-bold" width="16"></iconify-icon>
                                <span>Unggah Baru</span>
                            </label>
                            <input type="file" id="profilePhotoInput" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="handlePhotoUpload(this)">

                            <?php if (!empty($displayPhoto)): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmDeletePhoto()">
                                    <iconify-icon icon="solar:trash-bin-trash-bold" width="16"></iconify-icon>
                                    <span>Hapus</span>
                                </button>
                            <?php endif; ?>
                        </div>
                        <p class="upload-hint">Format JPG/PNG/WebP maks 3MB. Rasio 1:1.</p>
                    </div>
                </div>
            </div>

            <!-- KARTU 2: INTEGRASI GOOGLE SSO -->
            <div class="profile-card card-google-integration ios-card">
                <div class="card-header">
                    <div class="card-header-icon google-icon-wrap">
                        <iconify-icon icon="logos:google-icon" width="22"></iconify-icon>
                    </div>
                    <div class="card-header-text">
                        <h3 class="card-title">Google SSO</h3>
                        <p class="card-subtitle">Login instan tanpa kata sandi</p>
                    </div>
                </div>

                <?php if (!empty($emailGoogle)): ?>
                    <!-- KONDISI 1: SUDAH TERHUBUNG -->
                    <div class="google-status-box status-connected">
                        <div class="google-status-info">
                            <div class="google-logo-badge">
                                <iconify-icon icon="logos:google-icon" width="24"></iconify-icon>
                            </div>
                            <div class="google-details">
                                <div class="google-status-pill">
                                    <span class="status-dot"></span>
                                    <span>Terhubung</span>
                                </div>
                                <div class="google-email-text"><?= htmlspecialchars($emailGoogle) ?></div>
                            </div>
                        </div>
                        <div class="google-status-action">
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="openUnlinkGoogleModal()">
                                <iconify-icon icon="solar:link-broken-bold" width="16" class="hide-mobile-icon"></iconify-icon>
                                <span>Putuskan</span>
                            </button>
                        </div>
                    </div>

                <?php elseif (!empty($pendingGoogleLink)): ?>
                    <!-- KONDISI 2: MENUNGGU VERIFIKASI GMAIL -->
                    <div class="google-status-box status-pending">
                        <div class="google-status-info">
                            <div class="google-logo-badge">
                                <iconify-icon icon="logos:google-icon" width="24"></iconify-icon>
                            </div>
                            <div class="google-details">
                                <div class="google-status-pill status-pill-amber">
                                    <span class="status-dot status-dot-amber"></span>
                                    <span>Menunggu Verifikasi Gmail</span>
                                </div>
                                <div class="google-email-text"><?= htmlspecialchars($pendingGoogleLink['email_google']) ?></div>
                                <div class="google-help-text">
                                    <iconify-icon icon="solar:letter-unread-bold" width="14"></iconify-icon>
                                    <span>Tautan verifikasi telah dikirim ke Gmail. Klik tautan untuk mengaktifkan.</span>
                                </div>
                            </div>
                        </div>
                        <div class="google-status-action flex-action-buttons">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="startGoogleLink()" id="btnResendGoogleLink" title="Tautkan Ulang / Ganti Akun Google">
                                <iconify-icon icon="solar:restart-bold" width="15"></iconify-icon>
                                <span>Kirim Ulang</span>
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="cancelPendingGoogleLink()" title="Batalkan Permintaan">
                                <span>Batal</span>
                            </button>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- KONDISI 3: BELUM DITAUTKAN (Tinggal 1 Klik) -->
                    <div class="google-status-box status-disconnected">
                        <div class="google-status-info">
                            <div class="google-logo-badge">
                                <iconify-icon icon="logos:google-icon" width="24"></iconify-icon>
                            </div>
                            <div class="google-details">
                                <div class="google-status-pill">
                                    <span class="status-dot"></span>
                                    <span>Belum Ditautkan</span>
                                </div>
                                <div class="google-email-text">Tautkan Akun Google</div>
                                <div class="google-sub-hint">Klik tombol untuk menghubungkan akun Google Anda secara instan.</div>
                            </div>
                        </div>
                        <div class="google-status-action">
                            <button type="button" class="btn btn-primary btn-sm" id="btnConnectGoogle" onclick="startGoogleLink()">
                                <iconify-icon icon="logos:google-icon" width="16"></iconify-icon>
                                <span>Hubungkan</span>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- KARTU 3: INFORMASI AKUN PORTAL -->
            <div class="profile-card card-account-info ios-card">
                <div class="card-header flex-between">
                    <div class="flex-align-center gap-3">
                        <div class="card-header-icon theme-icon-bg">
                            <iconify-icon icon="solar:user-bold" width="22"></iconify-icon>
                        </div>
                        <div class="card-header-text">
                            <h3 class="card-title">Informasi Akun</h3>
                            <p class="card-subtitle">Kredensial login portal</p>
                        </div>
                    </div>
                    <div class="card-header-action">
                        <!-- Perbaikan tombol edit khusus mobile -->
                        <button type="button" class="btn btn-action-edit btn-sm" id="btnToggleEditAccount" onclick="toggleEditAccount()">
                            <iconify-icon icon="solar:pen-new-square-bold" width="18"></iconify-icon>
                            <span id="btnEditAccountText" class="btn-text-label">Edit</span>
                        </button>
                    </div>
                </div>

                <form id="formAccount" onsubmit="handleAccountSubmit(event)">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Username <span class="badge-locked"><iconify-icon icon="solar:lock-bold" width="12"></iconify-icon></span></label>
                            <div class="input-wrapper disabled">
                                <iconify-icon icon="solar:user-check-bold" width="18" class="input-icon"></iconify-icon>
                                <input type="text" value="<?= htmlspecialchars($username) ?>" readonly class="form-input">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="accFullName">Nama Lengkap <span class="required">*</span></label>
                            <div class="input-wrapper">
                                <iconify-icon icon="solar:user-bold" width="18" class="input-icon"></iconify-icon>
                                <input type="text" id="accFullName" name="full_name" value="<?= htmlspecialchars($fullName) ?>" required class="form-input" disabled>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="accEmail">Email Utama</label>
                            <div class="input-wrapper">
                                <iconify-icon icon="solar:letter-bold" width="18" class="input-icon"></iconify-icon>
                                <input type="email" id="accEmail" name="email" value="<?= htmlspecialchars($email) ?>" class="form-input" disabled placeholder="nama@domain.com">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="accPhone">Nomor WhatsApp</label>
                            <div class="input-wrapper">
                                <iconify-icon icon="solar:phone-bold" width="18" class="input-icon"></iconify-icon>
                                <input type="tel" id="accPhone" name="phone" value="<?= htmlspecialchars($phone) ?>" class="form-input" disabled placeholder="08xxxxxxxxxx">
                            </div>
                        </div>
                    </div>

                    <div class="form-actions" id="accountFormActions" style="display: none;">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="cancelEditAccount()">Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm" id="btnSaveAccount">
                            <iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon>
                            <span>Simpan</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- KARTU 4: KEAMANAN & KATA SANDI -->
            <div class="profile-card card-security ios-card">
                <div class="security-box">
                    <div class="security-status">
                        <div class="security-badge-icon theme-icon-bg">
                            <iconify-icon icon="solar:shield-keyhole-bold" width="24"></iconify-icon>
                        </div>
                        <div>
                            <div class="security-title">Kata Sandi Akun</div>
                            <div class="security-desc">Terakhir diubah: -</div>
                        </div>
                    </div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="openChangePasswordModal()">
                        <span>Ubah Sandi</span>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- ======================================================================= -->
    <!-- TAB 2: DATA PRIBADI (Role-based: siswa / guru table)                   -->
    <!-- ======================================================================= -->
    <div class="profile-tab-content" id="tabContentPersonal">
        <div class="profile-grid">
            
            <?php if ($personalType === 'siswa'): ?>
                <!-- SISWA PERSONAL DATA -->
                <div class="profile-card card-personal-main ios-card">
                    <div class="card-header flex-between">
                        <div class="flex-align-center gap-3">
                            <div class="card-header-icon theme-icon-bg">
                                <iconify-icon icon="solar:square-academic-cap-2-bold" width="24"></iconify-icon>
                            </div>
                            <div class="card-header-text">
                                <h3 class="card-title">Data Siswa</h3>
                                <p class="card-subtitle">Data akademik master</p>
                            </div>
                        </div>
                        <div class="card-header-action">
                            <?php if ($personalData): ?>
                                <!-- Perbaikan tombol edit khusus mobile -->
                                <button type="button" class="btn btn-action-edit btn-sm" id="btnToggleEditSiswa" onclick="toggleEditPersonal('siswa')">
                                    <iconify-icon icon="solar:pen-new-square-bold" width="18"></iconify-icon>
                                    <span id="btnEditSiswaText" class="btn-text-label">Edit</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($personalData): ?>
                        <form id="formSiswa" onsubmit="handlePersonalSubmit(event, 'siswa')">
                            <div class="form-grid">
                                
                                <div class="form-group">
                                    <label class="form-label">NISN <span class="badge-locked"><iconify-icon icon="solar:lock-bold" width="12"></iconify-icon> Dapodik</span></label>
                                    <div class="input-wrapper disabled">
                                        <iconify-icon icon="solar:diploma-bold" width="18" class="input-icon"></iconify-icon>
                                        <input type="text" value="<?= htmlspecialchars($personalData['nisn'] ?? '-') ?>" readonly class="form-input">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">NIS <span class="badge-locked"><iconify-icon icon="solar:lock-bold" width="12"></iconify-icon> Sekolah</span></label>
                                    <div class="input-wrapper disabled">
                                        <iconify-icon icon="solar:card-2-bold" width="18" class="input-icon"></iconify-icon>
                                        <input type="text" value="<?= htmlspecialchars($personalData['nis'] ?? '-') ?>" readonly class="form-input">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="swNamaLengkap">Nama Lengkap <span class="required">*</span></label>
                                    <div class="input-wrapper">
                                        <iconify-icon icon="solar:user-bold" width="18" class="input-icon"></iconify-icon>
                                        <input type="text" id="swNamaLengkap" name="nama_lengkap" value="<?= htmlspecialchars($personalData['nama_lengkap'] ?? '') ?>" required class="form-input" disabled>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="swIdKelas">Kelas</label>
                                    <div class="input-wrapper select-wrapper">
                                        <iconify-icon icon="solar:notebook-bookmark-bold" width="18" class="input-icon"></iconify-icon>
                                        <select id="swIdKelas" name="id_kelas" class="form-select" disabled>
                                            <option value="">-- Pilih --</option>
                                            <?php foreach ($kelasList as $k): ?>
                                                <option value="<?= (int)$k['id'] ?>" <?= (isset($personalData['id_kelas']) && (int)$personalData['id_kelas'] === (int)$k['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($k['nama_kelas']) ?> (<?= htmlspecialchars($k['tahun_pelajaran'] ?? '') ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="swNoHp">No HP / WhatsApp</label>
                                    <div class="input-wrapper">
                                        <iconify-icon icon="solar:phone-bold" width="18" class="input-icon"></iconify-icon>
                                        <input type="tel" id="swNoHp" name="no_hp" value="<?= htmlspecialchars($personalData['no_hp'] ?? '') ?>" class="form-input" disabled placeholder="08xxxxxxxxxx">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="swGmail">Gmail Pribadi</label>
                                    <div class="input-wrapper">
                                        <iconify-icon icon="logos:google-icon" width="18" class="input-icon"></iconify-icon>
                                        <input type="email" id="swGmail" name="gmail" value="<?= htmlspecialchars($personalData['gmail'] ?? '') ?>" class="form-input" disabled placeholder="siswa@gmail.com">
                                    </div>
                                </div>

                                <div class="form-group full-width">
                                    <label class="form-label" for="swAlamat">Alamat Lengkap</label>
                                    <div class="input-wrapper textarea-wrap">
                                        <iconify-icon icon="solar:map-point-bold" width="18" class="input-icon textarea-icon"></iconify-icon>
                                        <textarea id="swAlamat" name="alamat" class="form-textarea" rows="2" disabled placeholder="RT/RW, Desa, Kec, Kab..."><?= htmlspecialchars($personalData['alamat'] ?? '') ?></textarea>
                                    </div>
                                </div>

                            </div>

                            <div class="form-actions" id="siswaFormActions" style="display: none;">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="cancelEditPersonal('siswa')">Batal</button>
                                <button type="submit" class="btn btn-primary btn-sm" id="btnSaveSiswa">
                                    <iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon>
                                    <span>Simpan</span>
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="personal-empty-state">
                            <div class="empty-state-icon">
                                <iconify-icon icon="solar:folder-error-bold" width="42"></iconify-icon>
                            </div>
                            <h4 class="empty-state-title">Data Siswa Tidak Ditemukan</h4>
                            <p class="empty-state-desc">Akun ini belum tertaut dengan data master siswa. Hubungi admin untuk verifikasi NISN.</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($personalType === 'guru'): ?>
                <!-- GURU PERSONAL DATA -->
                <div class="profile-card card-personal-main ios-card">
                    <div class="card-header flex-between">
                        <div class="flex-align-center gap-3">
                            <div class="card-header-icon theme-icon-bg">
                                <iconify-icon icon="solar:user-id-bold" width="24"></iconify-icon>
                            </div>
                            <div class="card-header-text">
                                <h3 class="card-title">Data Pendidik</h3>
                                <p class="card-subtitle">Profil master guru pengampu</p>
                            </div>
                        </div>
                        <div class="card-header-action">
                            <?php if ($personalData): ?>
                                <!-- Perbaikan tombol edit khusus mobile -->
                                <button type="button" class="btn btn-action-edit btn-sm" id="btnToggleEditGuru" onclick="toggleEditPersonal('guru')">
                                    <iconify-icon icon="solar:pen-new-square-bold" width="18"></iconify-icon>
                                    <span id="btnEditGuruText" class="btn-text-label">Edit</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if ($personalData): ?>
                        <form id="formGuru" onsubmit="handlePersonalSubmit(event, 'guru')">
                            <div class="form-grid">
                                
                                <div class="form-group">
                                    <label class="form-label" for="grNamaGuru">Nama & Gelar <span class="required">*</span></label>
                                    <div class="input-wrapper">
                                        <iconify-icon icon="solar:user-bold" width="18" class="input-icon"></iconify-icon>
                                        <input type="text" id="grNamaGuru" name="nama_guru" value="<?= htmlspecialchars($personalData['nama_guru'] ?? '') ?>" required class="form-input" disabled>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="grNip">NIP</label>
                                    <div class="input-wrapper">
                                        <iconify-icon icon="solar:diploma-bold" width="18" class="input-icon"></iconify-icon>
                                        <input type="text" id="grNip" name="nip" value="<?= htmlspecialchars($personalData['nip'] ?? '') ?>" class="form-input" disabled placeholder="19xxxxxxxxxxxxxx">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="grNuptk">NUPTK</label>
                                    <div class="input-wrapper">
                                        <iconify-icon icon="solar:card-2-bold" width="18" class="input-icon"></iconify-icon>
                                        <input type="text" id="grNuptk" name="nuptk" value="<?= htmlspecialchars($personalData['nuptk'] ?? '') ?>" class="form-input" disabled placeholder="16 digit NUPTK">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label">Kode Unik <span class="badge-locked"><iconify-icon icon="solar:lock-bold" width="12"></iconify-icon> Sistem</span></label>
                                    <div class="input-wrapper disabled">
                                        <iconify-icon icon="solar:code-bold" width="18" class="input-icon"></iconify-icon>
                                        <input type="text" value="<?= htmlspecialchars($personalData['kode_guru'] ?? '-') ?>" readonly class="form-input">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="grJenisKelamin">Jenis Kelamin</label>
                                    <div class="input-wrapper select-wrapper">
                                        <iconify-icon icon="solar:users-group-two-rounded-bold" width="18" class="input-icon"></iconify-icon>
                                        <select id="grJenisKelamin" name="jenis_kelamin" class="form-select" disabled>
                                            <option value="L" <?= (($personalData['jenis_kelamin'] ?? 'L') === 'L') ? 'selected' : '' ?>>Laki-laki (L)</option>
                                            <option value="P" <?= (($personalData['jenis_kelamin'] ?? '') === 'P') ? 'selected' : '' ?>>Perempuan (P)</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="grStatusKepegawaian">Status Pegawai</label>
                                    <div class="input-wrapper select-wrapper">
                                        <iconify-icon icon="solar:case-round-bold" width="18" class="input-icon"></iconify-icon>
                                        <select id="grStatusKepegawaian" name="status_kepegawaian" class="form-select" disabled>
                                            <option value="PNS" <?= (($personalData['status_kepegawaian'] ?? '') === 'PNS') ? 'selected' : '' ?>>PNS</option>
                                            <option value="PPPK" <?= (($personalData['status_kepegawaian'] ?? '') === 'PPPK') ? 'selected' : '' ?>>PPPK</option>
                                            <option value="GTT" <?= (($personalData['status_kepegawaian'] ?? '') === 'GTT') ? 'selected' : '' ?>>GTT</option>
                                            <option value="Tetap" <?= (($personalData['status_kepegawaian'] ?? 'Tetap') === 'Tetap') ? 'selected' : '' ?>>Tetap Yayasan</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="grNoHp">No HP / WhatsApp</label>
                                    <div class="input-wrapper">
                                        <iconify-icon icon="solar:phone-bold" width="18" class="input-icon"></iconify-icon>
                                        <input type="tel" id="grNoHp" name="no_hp" value="<?= htmlspecialchars($personalData['no_hp'] ?? '') ?>" class="form-input" disabled placeholder="08xxxxxxxxxx">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label class="form-label" for="grEmail">Email Guru</label>
                                    <div class="input-wrapper">
                                        <iconify-icon icon="solar:letter-bold" width="18" class="input-icon"></iconify-icon>
                                        <input type="email" id="grEmail" name="email" value="<?= htmlspecialchars($personalData['email'] ?? '') ?>" class="form-input" disabled placeholder="guru@sekolah.sch.id">
                                    </div>
                                </div>

                                <div class="form-group full-width">
                                    <label class="form-label" for="grAlamat">Alamat Domisili</label>
                                    <div class="input-wrapper textarea-wrap">
                                        <iconify-icon icon="solar:map-point-bold" width="18" class="input-icon textarea-icon"></iconify-icon>
                                        <textarea id="grAlamat" name="alamat" class="form-textarea" rows="2" disabled placeholder="Alamat lengkap..."><?= htmlspecialchars($personalData['alamat'] ?? '') ?></textarea>
                                    </div>
                                </div>

                            </div>

                            <div class="form-actions" id="guruFormActions" style="display: none;">
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="cancelEditPersonal('guru')">Batal</button>
                                <button type="submit" class="btn btn-primary btn-sm" id="btnSaveGuru">
                                    <iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon>
                                    <span>Simpan</span>
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="personal-empty-state">
                            <div class="empty-state-icon">
                                <iconify-icon icon="solar:folder-error-bold" width="42"></iconify-icon>
                            </div>
                            <h4 class="empty-state-title">Data Master Belum Terhubung</h4>
                            <p class="empty-state-desc">Akun login Anda belum terhubung dengan baris data guru. Hubungi admin sistem.</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <!-- FALLBACK JIKA ROLE UMUM -->
                <div class="profile-card card-personal-main ios-card">
                    <div class="personal-empty-state">
                        <div class="empty-state-icon">
                            <iconify-icon icon="solar:shield-user-bold" width="42"></iconify-icon>
                        </div>
                        <h4 class="empty-state-title">Data Dikelola Terpusat</h4>
                        <p class="empty-state-desc">Sebagai <strong><?= htmlspecialchars(strtoupper($activeRole)) ?></strong>, profil Anda cukup dikelola melalui Tab Akun.</p>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<!-- =========================================================================== -->
<!-- MODALS -->
<!-- =========================================================================== -->
<div class="ilmuna-modal-backdrop" id="modalAlert" style="display: none;">
    <div class="ilmuna-modal-box alert-modal-box">
        <div class="alert-modal-content">
            <div class="alert-modal-icon-wrap" id="alertModalIcon">
                <iconify-icon icon="solar:check-circle-bold" width="38"></iconify-icon>
            </div>
            <h3 class="alert-modal-title" id="alertModalTitle">Pemberitahuan</h3>
            <p class="alert-modal-message" id="alertModalMessage">Aksi berhasil dilakukan.</p>
            <div class="alert-modal-action">
                <button type="button" class="btn btn-primary btn-full" id="alertModalCloseBtn" onclick="closeAlertModal()">
                    <span>Mengerti</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL KONFIRMASI AKSI (PREMIUM DANGER/WARNING CONFIRMATION) -->
<div class="ilmuna-modal-backdrop" id="modalConfirm" style="display: none;">
    <div class="ilmuna-modal-box confirm-modal-box">
        <div class="confirm-modal-content">
            <div class="confirm-modal-icon-wrap" id="confirmModalIconWrap">
                <iconify-icon id="confirmModalIcon" icon="solar:trash-bin-trash-bold" width="38"></iconify-icon>
            </div>
            <h3 class="confirm-modal-title" id="confirmModalTitle">Hapus Foto Profil?</h3>
            <p class="confirm-modal-message" id="confirmModalMessage">Foto profil Anda akan dihapus permanen dan diganti dengan inisial nama.</p>
            <div class="confirm-modal-actions">
                <button type="button" class="btn btn-outline-secondary btn-full" id="btnConfirmCancel" onclick="closeConfirmModal()">
                    <span id="btnConfirmCancelText">Batal</span>
                </button>
                <button type="button" class="btn btn-danger btn-full" id="btnConfirmOk" onclick="executeConfirmAction()">
                    <span id="btnConfirmOkText">Hapus Foto</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL ACTION SHEET PILIHAN FOTO PROFIL -->
<div class="ilmuna-modal-backdrop" id="modalPhotoActions" style="display: none;" onclick="closePhotoActionsModal(event)">
    <div class="ilmuna-modal-box photo-action-sheet" onclick="event.stopPropagation()">
        <div class="action-sheet-header">
            <h4 class="action-sheet-title">Kelola Foto Profil</h4>
            <p class="action-sheet-subtitle">Pilih tindakan untuk foto akun Anda</p>
        </div>
        <div class="action-sheet-body">
            <button type="button" class="action-sheet-btn" onclick="triggerPhotoPick()">
                <div class="action-sheet-icon theme-bg">
                    <iconify-icon icon="solar:camera-bold" width="22"></iconify-icon>
                </div>
                <div class="action-sheet-label">
                    <span class="action-title">Unggah Foto Baru</span>
                    <span class="action-sub">Format JPG, PNG, atau WebP maks 3MB</span>
                </div>
            </button>
            <button type="button" class="action-sheet-btn danger" onclick="closePhotoActionsModal(); confirmDeletePhoto();">
                <div class="action-sheet-icon danger-bg">
                    <iconify-icon icon="solar:trash-bin-trash-bold" width="22"></iconify-icon>
                </div>
                <div class="action-sheet-label">
                    <span class="action-title">Hapus Foto Profil</span>
                    <span class="action-sub">Kembalikan ke inisial nama akun</span>
                </div>
            </button>
        </div>
        <div class="action-sheet-footer">
            <button type="button" class="btn btn-outline-secondary btn-full" onclick="closePhotoActionsModal()">Batal</button>
        </div>
    </div>
</div>

<div class="ilmuna-modal-backdrop" id="modalChangePassword" style="display: none;">
    <div class="ilmuna-modal-box">
        <div class="modal-header">
            <div class="modal-title-wrap">
                <div class="modal-icon-badge theme-icon-bg">
                    <iconify-icon icon="solar:key-bold" width="22"></iconify-icon>
                </div>
                <div>
                    <h3 class="modal-title">Ubah Kata Sandi</h3>
                    <p class="modal-subtitle">Pastikan menggunakan kombinasi aman</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" onclick="closeChangePasswordModal()">
                <iconify-icon icon="solar:close-circle-bold" width="24"></iconify-icon>
            </button>
        </div>

        <form id="formChangePassword" onsubmit="handleChangePasswordSubmit(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="pwdOld">Sandi Saat Ini</label>
                    <div class="input-wrapper">
                        <iconify-icon icon="solar:lock-bold" width="18" class="input-icon"></iconify-icon>
                        <input type="password" id="pwdOld" name="old_password" required class="form-input" placeholder="Masukkan sandi lama">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePwdVisibility('pwdOld', this)">
                            <iconify-icon icon="solar:eye-bold" width="18"></iconify-icon>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="pwdNew">Sandi Baru</label>
                    <div class="input-wrapper">
                        <iconify-icon icon="solar:lock-keyhole-bold" width="18" class="input-icon"></iconify-icon>
                        <input type="password" id="pwdNew" name="new_password" required minlength="6" class="form-input" placeholder="Minimal 6 karakter">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePwdVisibility('pwdNew', this)">
                            <iconify-icon icon="solar:eye-bold" width="18"></iconify-icon>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="pwdConfirm">Konfirmasi Sandi Baru</label>
                    <div class="input-wrapper">
                        <iconify-icon icon="solar:shield-check-bold" width="18" class="input-icon"></iconify-icon>
                        <input type="password" id="pwdConfirm" name="confirm_password" required minlength="6" class="form-input" placeholder="Ulangi sandi baru">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePwdVisibility('pwdConfirm', this)">
                            <iconify-icon icon="solar:eye-bold" width="18"></iconify-icon>
                        </button>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closeChangePasswordModal()">Batal</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitPassword">Simpan</button>
            </div>
        </form>
    </div>
</div>



<!-- =========================================================================== -->
<!-- CSS STYLESHEET PROFILE (DESKTOP PREMIUM SaaS + WHATSAPP iOS FULL MOBILE)    -->
<!-- =========================================================================== -->
<style id="ilmuna-profile-styles">
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');

:root {
    /* Ilmuna Learn — Navy Teal Theme (sesuai login.php) */
    --navy: #1D3B5E;
    --navy-dark: #152C47;
    --teal: #0D7A87;
    --teal-dark: #096470;
    --sky: #52B2DC;
    --mint: #14B8A6;
    --emerald: #10B981;

    --col-brand: #0D7A87;        /* → teal (pengganti ocean blue) */
    --col-brand-light: #52B2DC;  /* → sky blue */
    --col-brand-fade: #E6F4F6;   /* teal fade */

    --col-text-main: #0F1D2F;
    --col-text-muted: #64748B;

    --prf-bg-desktop: #F8FAFC;
    --prf-bg-mobile: #F2F2F7; /* iOS Gray */
    --prf-card-bg: #FFFFFF;

    --prf-border: #E2E8F0;
    --prf-border-ios: #C6C6C8;

    --prf-shadow-sm: 0 4px 6px -1px rgb(0 0 0 / 0.05);
    --prf-shadow-md: 0 10px 25px -5px rgb(13 122 135 / 0.12);
    --prf-shadow-lg: 0 25px 50px -12px rgb(13 122 135 / 0.20);
    --prf-radius: 20px;

    --font-head: 'Outfit', sans-serif;
    --font-body: 'Plus Jakarta Sans', sans-serif;
}

/* Base resets & utilities */
.ilmuna-profile-container * {
    box-sizing: border-box;
    font-family: var(--font-body);
}
.ios-navbar { display: none; }
.ios-avatar-edit-text { display: none; }
.flex-align-center { display: flex; align-items: center; }
.flex-between { display: flex; align-items: center; justify-content: space-between; }
.gap-3 { gap: 12px; }

.ilmuna-profile-container {
    max-width: 1040px;
    margin: 0 auto;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 24px;
    background: transparent;
}

/* =============================================================================
   HERO HEADER CARD (NAVY TEAL PREMIUM — Sesuai Login Page)
   ============================================================================= */
.profile-hero-card {
    position: relative;
    background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 50%, #152C47 100%);
    border-radius: var(--prf-radius);
    padding: 40px;
    color: #FFFFFF;
    overflow: hidden;
    box-shadow: 0 10px 40px rgba(13, 122, 135, 0.35);
}

/* Pixel dot pattern overlay — sama seperti login slide-panel */
.profile-hero-card::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background-image: radial-gradient(circle, rgba(82, 178, 220, 0.28) 1.5px, transparent 1.5px);
    background-size: 20px 20px;
    animation: heroPixelPulse 8s ease-in-out infinite;
    opacity: 0.5;
}
@keyframes heroPixelPulse {
    0%, 100% { opacity: 0.35; transform: scale(1); }
    50%       { opacity: 0.65; transform: scale(1.02); }
}

.hero-bg-shapes {
    position: absolute; inset: 0; pointer-events: none; overflow: hidden; z-index: 1;
}
.hero-bg-shapes .shape {
    position: absolute; border-radius: 50%; filter: blur(80px); background: rgba(82, 178, 220, 0.35);
}
.hero-bg-shapes .shape-1 { width: 350px; height: 350px; top: -120px; right: -60px; animation: floatShape1 9s ease-in-out infinite; }
.hero-bg-shapes .shape-2 { width: 300px; height: 300px; bottom: -100px; left: -60px; animation: floatShape2 11s ease-in-out infinite; }
.hero-bg-shapes .shape-3 { width: 180px; height: 180px; top: 30%; right: 25%; background: rgba(20, 184, 166, 0.25); animation: floatShape3 7s ease-in-out infinite; }
@keyframes floatShape1 { 0%,100%{transform:translate(0,0);} 50%{transform:translate(-20px,15px);} }
@keyframes floatShape2 { 0%,100%{transform:translate(0,0);} 50%{transform:translate(15px,-20px);} }
@keyframes floatShape3 { 0%,100%{transform:translate(0,0) scale(1);} 50%{transform:translate(-10px,10px) scale(1.1);} }

.hero-content {
    position: relative; z-index: 2; display: flex; align-items: center; gap: 32px; margin-bottom: 32px;
}

.hero-avatar-wrapper { position: relative; flex-shrink: 0; }
.hero-avatar-ring {
    width: 130px; height: 130px; border-radius: 50%; padding: 5px;
    background: rgba(255,255,255,0.4);
    box-shadow: 0 12px 30px rgba(0,0,0,0.15);
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.hero-avatar-ring:hover { transform: scale(1.05); }

.hero-avatar {
    position: relative; width: 100%; height: 100%; border-radius: 50%; overflow: hidden;
    background: #FFFFFF; display: flex; align-items: center; justify-content: center;
    border: 3px solid #FFFFFF;
}
.hero-avatar img { width: 100%; height: 100%; object-fit: cover; }
.avatar-fallback { font-family: var(--font-head); font-size: 42px; font-weight: 800; color: var(--col-brand); }

.avatar-edit-badge {
    position: absolute; inset: 0; background: rgba(13, 122, 135, 0.55); backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center; color: #FFF;
    opacity: 0; cursor: pointer; transition: opacity 0.25s ease;
}
.hero-avatar:hover .avatar-edit-badge { opacity: 1; }

.hero-info { flex: 1; }
/* Nama & role badge — selalu vertikal agar simetris */
.hero-name-row {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 6px;
    min-width: 0;
}
.hero-name {
    font-family: var(--font-head); font-size: 32px; font-weight: 700;
    color: #FFFFFF;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
    margin: 0; letter-spacing: -0.5px; min-width: 0; word-break: break-word; line-height: 1.2;
}

.hero-role-badge {
    padding: 4px 14px; border-radius: 99px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;
    background: rgba(255, 255, 255, 0.25); border: 1px solid rgba(255, 255, 255, 0.4); backdrop-filter: blur(12px); color: #FFF;
}

.hero-username { font-size: 15px; color: rgba(255,255,255,0.85); margin: 0 0 16px 0; font-weight: 500; }

.hero-meta-pills { display: flex; flex-wrap: wrap; gap: 12px; }
.meta-pill {
    display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 99px; font-size: 13px; font-weight: 500;
    background: rgba(255, 255, 255, 0.15); border: 1px solid rgba(255, 255, 255, 0.25); backdrop-filter: blur(8px);
}
.meta-pill.pill-google-connected { background: #FFFFFF; color: var(--col-brand); font-weight: 700; border: none; }

/* SEGMENTED CONTROL TABS */
.profile-tabs-nav {
    position: relative; z-index: 2; display: inline-flex; background: rgba(255, 255, 255, 0.2);
    padding: 6px; border-radius: 14px; backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.3);
}
.tab-btn {
    display: flex; align-items: center; gap: 8px; padding: 10px 24px; border: none; background: transparent;
    color: #FFFFFF; font-family: var(--font-head); font-size: 15px; font-weight: 600; border-radius: 10px;
    cursor: pointer; transition: all 0.2s ease;
}
.tab-btn:hover { background: rgba(255,255,255,0.15); }
.tab-btn.active { background: #FFFFFF; color: var(--col-brand); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }

/* =============================================================================
   CARDS & GRIDS (DESKTOP)
   ============================================================================= */
.profile-tab-content { display: none; opacity: 0; transform: translateY(10px); transition: all 0.3s ease; }
.profile-tab-content.active { display: block; opacity: 1; transform: translateY(0); }

.profile-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 24px; }
.card-avatar-sync, .card-google-integration { grid-column: span 6; }
.card-account-info, .card-security, .card-personal-main { grid-column: span 12; }

.profile-card {
    background: var(--prf-card-bg); border: 1px solid var(--prf-border); border-radius: var(--prf-radius);
    padding: 32px; box-shadow: var(--prf-shadow-sm); transition: box-shadow 0.3s ease;
}
.profile-card:hover { box-shadow: var(--prf-shadow-md); }

.card-header { margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid #F1F5F9; }
.card-header-icon {
    width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center;
}
.theme-icon-bg { background: var(--col-brand-fade); color: var(--col-brand); }
.google-icon-wrap { background: #FFFFFF; border: 1px solid #E2E8F0; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }

.card-title { font-family: var(--font-head); font-size: 18px; font-weight: 700; color: var(--col-text-main); margin: 0 0 4px 0; }
.card-subtitle { font-size: 13.5px; color: var(--col-text-muted); margin: 0; }

/* BUTTONS */
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 10px 20px; font-family: var(--font-head); font-size: 14px; font-weight: 600;
    border-radius: 12px; border: 1px solid transparent; cursor: pointer; transition: all 0.2s ease; outline: none;
}
.btn-sm { padding: 8px 16px; font-size: 13.5px; border-radius: 10px; }
.btn-full { width: 100%; padding: 12px; font-size: 15px; border-radius: 14px; }

.btn-primary { background: linear-gradient(135deg, var(--navy) 0%, var(--teal) 100%); color: #FFF; box-shadow: 0 4px 10px rgba(13, 122, 135, 0.25); }
.btn-primary:hover { background: linear-gradient(135deg, var(--navy-dark) 0%, var(--teal-dark) 100%); transform: translateY(-1px); box-shadow: 0 6px 15px rgba(13, 122, 135, 0.40); }

/* Tombol Edit Khusus */
.btn-action-edit { background: var(--col-brand-fade); color: var(--col-brand); border: 1px solid rgba(13, 122, 135, 0.25); }
.btn-action-edit:hover { background: rgba(13, 122, 135, 0.15); }

.btn-outline-primary { background: transparent; border-color: #E2E8F0; color: var(--col-brand); border: 1.5px solid #E2E8F0; }
.btn-outline-primary:hover { border-color: var(--col-brand); background: var(--col-brand-fade); }

.btn-outline-secondary { background: transparent; border-color: #E2E8F0; color: var(--col-text-muted); }
.btn-outline-secondary:hover { background: #F8FAFC; color: var(--col-text-main); }

.btn-outline-danger { background: #FEF2F2; color: #DC2626; border: 1px solid #FECACA; }
.btn-outline-danger:hover { background: #FEE2E2; }

/* FORM CONTROLS */
.form-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 20px; }
.form-group { grid-column: span 12; }
@media (min-width: 680px) { .form-group { grid-column: span 6; } .form-group.full-width { grid-column: span 12; } }

.form-label { display: flex; align-items: center; justify-content: space-between; font-size: 13.5px; font-weight: 600; color: var(--col-text-main); margin-bottom: 8px; }
.form-label .required { color: #EF4444; }
.badge-locked { font-size: 12px; color: var(--col-text-muted); font-weight: 500; display: inline-flex; align-items: center; gap: 4px; }

.input-wrapper { position: relative; display: flex; align-items: center; }
.input-wrapper.textarea-wrap { align-items: flex-start; }
.input-icon { position: absolute; left: 16px; color: #94A3B8; z-index: 2; transition: color 0.2s ease; }
.textarea-icon { top: 16px; }

.form-input, .form-textarea, .form-select {
    width: 100%; padding: 12px 16px 12px 46px; font-size: 14.5px; color: var(--col-text-main);
    background: #FFF; border: 1.5px solid #E2E8F0; border-radius: 14px; transition: all 0.2s ease;
}
.form-select {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2364748B' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 16px center; background-size: 16px;
}
.form-input:not(:disabled):hover, .form-textarea:not(:disabled):hover, .form-select:not(:disabled):hover {
    border-color: #CBD5E1; background: #F8FAFC;
}
.form-input:focus, .form-textarea:focus, .form-select:focus {
    outline: none; border-color: var(--col-brand); box-shadow: 0 0 0 4px rgba(13, 122, 135, 0.10); background: #FFF;
}
.input-wrapper:focus-within .input-icon { color: var(--col-brand); }

.input-wrapper.disabled .form-input, .form-input:disabled, .form-select:disabled, .form-textarea:disabled {
    background: #F8FAFC; color: #64748B; cursor: not-allowed; border-color: #E2E8F0; box-shadow: none !important;
}

.form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; padding-top: 24px; border-top: 1px solid #F1F5F9; }
.pwd-toggle-btn { position: absolute; right: 14px; background: transparent; border: none; color: #94A3B8; cursor: pointer; padding: 4px; }
.pwd-toggle-btn:hover { color: var(--col-brand); }

/* AVATAR & GOOGLE SSO BOXES */
.avatar-sync-box { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
.avatar-sync-preview {
    position: relative; width: 100px; height: 100px; border-radius: 50%; overflow: hidden;
    box-shadow: 0 8px 20px rgba(0,0,0,0.08); border: 3px solid #FFF; flex-shrink: 0;
}
.sync-photo-container img { width: 100%; height: 100%; object-fit: cover; }
.avatar-fallback-lg {
    width: 100%; height: 100%; background: var(--col-brand-fade); display: flex; align-items: center; justify-content: center;
    font-family: var(--font-head); font-size: 32px; font-weight: 800; color: var(--col-brand);
}
.avatar-sync-actions { flex: 1; }
.sync-info-badge {
    display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 8px;
    background: #ECFDF5; color: #10B981; font-size: 12.5px; font-weight: 700; margin-bottom: 12px;
}
.action-buttons-group { display: flex; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
.upload-hint { font-size: 12.5px; color: var(--col-text-muted); margin: 0; }

.google-status-box {
    display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 20px;
    border-radius: 16px; border: 1px solid #E2E8F0; background: #F8FAFC; flex-wrap: wrap;
}
.google-status-box.status-connected { background: var(--col-brand-fade); border-color: rgba(13, 122, 135, 0.30); }
.google-status-info { display: flex; align-items: center; gap: 16px; }
.google-logo-badge {
    width: 50px; height: 50px; border-radius: 14px; background: #FFF; border: 1px solid #E2E8F0;
    display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(0,0,0,0.03);
}
.google-status-pill {
    display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 800;
    text-transform: uppercase; color: var(--col-text-muted); margin-bottom: 4px;
}
.status-connected .google-status-pill { color: var(--col-brand); }
.status-dot { width: 8px; height: 8px; border-radius: 50%; background: #94A3B8; }
.status-connected .status-dot { background: var(--col-brand); }
.google-email-text { font-size: 15px; font-weight: 700; color: var(--col-text-main); }
.google-status-box.status-pending { background: #FFFBEB; border-color: rgba(245, 158, 11, 0.40); }
.status-pill-amber { color: #D97706 !important; }
.status-dot-amber { background: #F59E0B !important; animation: pulseAmber 1.8s infinite; }
@keyframes pulseAmber { 0%, 100% { opacity: 1; transform: scale(1); } 50% { opacity: 0.4; transform: scale(1.25); } }
.google-help-text { font-size: 12.5px; color: #B45309; margin-top: 4px; display: flex; align-items: center; gap: 6px; }
.google-sub-hint { font-size: 12px; color: var(--col-text-muted); margin-top: 2px; }
.flex-action-buttons { display: flex; gap: 8px; align-items: center; }

/* SECURITY BOX */
.security-box { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.security-status { display: flex; align-items: center; gap: 16px; }
.security-badge-icon {
    width: 48px; height: 48px; border-radius: 14px; border: 1px solid #E2E8F0;
    display: flex; align-items: center; justify-content: center;
}
.security-title { font-size: 15px; font-weight: 700; color: var(--col-text-main); margin-bottom: 2px; }
.security-desc { font-size: 13.5px; color: var(--col-text-muted); }

/* EMPTY STATES */
.personal-empty-state { text-align: center; padding: 48px 24px; }
.empty-state-icon {
    width: 80px; height: 80px; border-radius: 50%; background: #F1F5F9; color: #94A3B8;
    display: flex; align-items: center; justify-content: center; margin: 0 auto 20px auto;
}
.empty-state-title { font-family: var(--font-head); font-size: 20px; font-weight: 700; color: var(--col-text-main); margin: 0 0 10px 0; }
.empty-state-desc { font-size: 14.5px; color: var(--col-text-muted); max-width: 480px; margin: 0 auto; line-height: 1.6; }

/* MODALS */
.ilmuna-modal-backdrop {
    position: fixed; inset: 0; z-index: 10050; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px);
    display: flex; align-items: center; justify-content: center; padding: 16px; animation: fadeIn 0.2s ease;
}
.ilmuna-modal-box {
    background: #FFF; border-radius: 24px; max-width: 460px; width: 100%;
    box-shadow: var(--prf-shadow-lg); overflow: hidden; animation: scaleIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 24px; border-bottom: 1px solid #F1F5F9; }
.modal-title-wrap { display: flex; align-items: center; gap: 16px; }
.modal-icon-badge {
    width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center;
}
.modal-title { font-family: var(--font-head); font-size: 18px; font-weight: 700; color: var(--col-text-main); margin: 0; }
.modal-subtitle { font-size: 13px; color: var(--col-text-muted); margin: 4px 0 0 0; }
.modal-close-btn { background: none; border: none; color: #94A3B8; cursor: pointer; border-radius: 8px; display: flex; transition: all 0.2s ease; padding: 4px; }
.modal-close-btn:hover { color: #0F172A; background: #F1F5F9; }
.modal-body { padding: 24px; display: flex; flex-direction: column; gap: 20px; }
.modal-desc { font-size: 14.5px; color: var(--col-text-muted); margin: 0; line-height: 1.6; }
.modal-footer { display: flex; justify-content: flex-end; gap: 12px; padding: 20px 24px; background: #F8FAFC; border-top: 1px solid #F1F5F9; }

/* ALERT & CONFIRM MODALS (PREMIUM SaaS & iOS STYLE) */
.alert-modal-box, .confirm-modal-box {
    max-width: 420px;
    padding: 36px 28px 28px;
    text-align: center;
    border-radius: 24px;
    box-shadow: 0 25px 60px -15px rgba(15, 29, 47, 0.30);
    border: 1px solid rgba(226, 232, 240, 0.8);
    position: relative;
}
.alert-modal-content, .confirm-modal-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    width: 100%;
}
.alert-modal-icon-wrap, .confirm-modal-icon-wrap {
    width: 72px;
    height: 72px;
    border-radius: 22px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px auto;
    position: relative;
    transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.alert-modal-icon-wrap {
    background: #ECFDF5;
    color: #10B981;
    border: 2px solid #D1FAE5;
    box-shadow: 0 8px 25px -4px rgba(16, 185, 129, 0.25);
}
.alert-error .alert-modal-icon-wrap, .confirm-type-danger {
    background: #FEF2F2 !important;
    color: #DC2626 !important;
    border: 2px solid #FEE2E2 !important;
    box-shadow: 0 8px 25px -4px rgba(220, 38, 38, 0.28) !important;
    animation: confirmIconPulse 2.5s infinite ease-in-out;
}
.alert-warning .alert-modal-icon-wrap, .confirm-type-warning {
    background: #FFFBEB !important;
    color: #D97706 !important;
    border: 2px solid #FEF08A !important;
    box-shadow: 0 8px 25px -4px rgba(217, 119, 6, 0.25) !important;
}
.alert-info .alert-modal-icon-wrap, .confirm-type-info {
    background: var(--col-brand-fade) !important;
    color: var(--teal) !important;
    border: 2px solid rgba(13, 122, 135, 0.25) !important;
    box-shadow: 0 8px 25px -4px rgba(13, 122, 135, 0.25) !important;
}
@keyframes confirmIconPulse {
    0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.25); }
    50%      { transform: scale(1.04); box-shadow: 0 0 0 10px rgba(220, 38, 38, 0); }
}
.alert-modal-title, .confirm-modal-title {
    font-family: var(--font-head);
    font-size: 20px;
    font-weight: 700;
    color: var(--col-text-main);
    margin: 0 0 8px 0;
    letter-spacing: -0.3px;
}
.alert-modal-message, .confirm-modal-message {
    font-size: 14px;
    line-height: 1.6;
    color: var(--col-text-muted);
    margin: 0 0 24px 0;
    max-width: 340px;
}
.alert-modal-action, .confirm-modal-actions {
    width: 100%;
}
.confirm-modal-actions {
    display: flex;
    gap: 12px;
}
.btn-danger {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF !important;
    border: none;
    font-weight: 700;
    box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
    transition: all 0.2s ease;
}
.btn-danger:hover {
    background: linear-gradient(135deg, #B91C1C 0%, #991B1B 100%);
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(220, 38, 38, 0.45);
}
.btn-warning {
    background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%);
    color: #FFFFFF !important;
    border: none;
    font-weight: 700;
    box-shadow: 0 4px 14px rgba(217, 119, 6, 0.35);
}

/* Photo Action Sheet Modal */
.photo-action-sheet {
    max-width: 380px;
    padding: 24px 20px 20px;
    border-radius: 24px;
    box-shadow: 0 25px 60px -15px rgba(15, 29, 47, 0.30);
}
.action-sheet-header {
    text-align: center;
    margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid #F1F5F9;
}
.action-sheet-title {
    font-family: var(--font-head);
    font-size: 17px;
    font-weight: 700;
    color: var(--col-text-main);
    margin: 0 0 4px 0;
}
.action-sheet-subtitle {
    font-size: 12.5px;
    color: var(--col-text-muted);
    margin: 0;
}
.action-sheet-body {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 16px;
}
.action-sheet-btn {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 14px;
    border-radius: 14px;
    border: 1.5px solid #F1F5F9;
    background: #F8FAFC;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
    width: 100%;
    text-align: left;
}
.action-sheet-btn:hover {
    background: #FFFFFF;
    border-color: #CBD5E1;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
}
.action-sheet-btn.danger:hover {
    background: #FEF2F2;
    border-color: #FECACA;
}
.action-sheet-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.action-sheet-icon.theme-bg {
    background: var(--col-brand-fade);
    color: var(--teal);
}
.action-sheet-icon.danger-bg {
    background: #FEF2F2;
    color: #DC2626;
}
.action-sheet-label {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.action-sheet-label .action-title {
    font-family: var(--font-head);
    font-size: 14.5px;
    font-weight: 700;
    color: var(--col-text-main);
}
.action-sheet-btn.danger .action-title {
    color: #DC2626;
}
.action-sheet-label .action-sub {
    font-size: 12px;
    color: var(--col-text-muted);
}
.action-sheet-footer {
    width: 100%;
}

/* =============================================================================
   WHATSAPP iOS MOBILE VIEW MODE (NATIVE LOOK & FIXED EDIT BUTTON)
   ============================================================================= */
@media (max-width: 900px) {
    /* Hide all desktop wrappers */
    .ilmuna-topnav, .ilmuna-bottomnav, .ilmuna-sidebar, .ilmuna-footer-nav, .ilmuna-dock { display: none !important; }
    html, body, .ilmuna-mhs, .ilmuna-content-area {
        background: var(--prf-bg-mobile) !important; margin: 0 !important; padding: 0 !important; min-height: 100vh !important; box-shadow: none !important;
    }
    
    /* Native iOS Navbar — diselaraskan dengan hero gelap */
    .ios-navbar {
        display: block !important; position: sticky; top: 0; z-index: 1050;
        background: rgba(21, 44, 71, 0.80); backdrop-filter: blur(25px); -webkit-backdrop-filter: blur(25px);
        border-bottom: 0.5px solid rgba(82, 178, 220, 0.25); padding-top: env(safe-area-inset-top, 0px);
    }
    .ios-navbar-inner { height: 44px; display: flex; align-items: center; justify-content: space-between; padding: 0 8px; }
    .ios-back-btn { display: inline-flex; align-items: center; color: #52B2DC; text-decoration: none; font-size: 17px; font-weight: 400; padding: 0 8px; }
    .ios-nav-title { font-size: 17px; font-weight: 600; color: #FFFFFF; position: absolute; left: 50%; transform: translateX(-50%); margin: 0; }

    .ilmuna-profile-container { padding: 0 0 50px 0; gap: 0; max-width: 100%; }

    /* Hero Section — Full Gradient + Animasi di Mobile */
    .profile-hero-card {
        background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 50%, #152C47 100%);
        border-radius: 0 0 28px 28px;
        padding: 20px 16px 28px 16px;
        box-shadow: 0 8px 32px rgba(13, 122, 135, 0.40);
        border: none;
        text-align: center;
    }
    .hero-bg-shapes { display: block; z-index: 1; }
    .hero-bg-shapes .shape-1 { width: 200px; height: 200px; top: -80px; right: -50px; }
    .hero-bg-shapes .shape-2 { width: 160px; height: 160px; bottom: -60px; left: -50px; }
    .hero-bg-shapes .shape-3 { width: 100px; height: 100px; top: 40%; right: 10%; }
    .hero-content { flex-direction: column; gap: 12px; margin-bottom: 20px; z-index: 2; }
    .hero-info { text-align: center; min-width: 0; width: 100%; }
    .hero-avatar-wrapper { margin: 0 auto; position: relative; }
    /* Avatar ring dengan glow teal */
    .hero-avatar-ring {
        width: 110px; height: 110px; padding: 4px;
        background: rgba(255,255,255,0.25);
        box-shadow: 0 0 0 3px rgba(82,178,220,0.50), 0 8px 24px rgba(0,0,0,0.25);
        animation: avatarRingPulse 3s ease-in-out infinite;
    }
    @keyframes avatarRingPulse {
        0%,100% { box-shadow: 0 0 0 3px rgba(82,178,220,0.40), 0 8px 24px rgba(0,0,0,0.20); }
        50%      { box-shadow: 0 0 0 6px rgba(82,178,220,0.70), 0 8px 32px rgba(0,0,0,0.30); }
    }
    .hero-avatar { border: 2px solid rgba(255,255,255,0.60); background: rgba(255,255,255,0.15); }
    .avatar-fallback { color: #FFFFFF; font-size: 34px; text-shadow: 0 2px 8px rgba(0,0,0,0.25); }
    /* Camera badge — selalu tampil di pojok bawah kanan avatar */
    .avatar-edit-badge {
        display: flex !important;
        inset: auto;
        width: 32px; height: 32px;
        border-radius: 50%;
        bottom: 2px; right: 2px;
        top: auto; left: auto;
        background: var(--teal);
        border: 2.5px solid #FFFFFF;
        box-shadow: 0 2px 8px rgba(0,0,0,0.30);
        opacity: 1 !important;
        z-index: 10;
    }
    .avatar-edit-badge iconify-icon { width: 16px; height: 16px; }

    /* Sembunyikan label "Edit" di bawah avatar */
    .ios-avatar-edit-text { display: none !important; }

    
    /* Nama — susun vertikal dan center di mobile */
    .hero-name-row {
        flex-direction: column;
        align-items: center;
        gap: 6px;
        margin-bottom: 4px;
        width: 100%;
    }
    .hero-name {
        color: #FFFFFF;
        font-size: clamp(16px, 4.5vw, 20px);
        font-weight: 700;
        font-family: var(--font-head);
        letter-spacing: -0.3px;
        text-shadow: 0 2px 8px rgba(0,0,0,0.25);
        line-height: 1.25;
        word-break: break-word;
        text-align: center;
        width: 100%;
    }
    /* Tampilkan role badge di mobile */
    .hero-role-badge {
        display: inline-flex;
        font-size: 10px;
        padding: 3px 10px;
        background: rgba(255,255,255,0.20);
        border: 1px solid rgba(255,255,255,0.35);
        backdrop-filter: blur(8px);
        animation: badgeFadeIn 0.6s ease both;
    }
    @keyframes badgeFadeIn { from{opacity:0;transform:scale(0.8)} to{opacity:1;transform:scale(1)} }
    .hero-username { color: rgba(255,255,255,0.80); font-size: 13px; margin-bottom: 12px; font-weight: 500; }
    
    .hero-meta-pills { justify-content: center; gap: 8px; }
    .meta-pill {
        background: rgba(255,255,255,0.14);
        color: rgba(255,255,255,0.90);
        border: 1px solid rgba(255,255,255,0.22);
        backdrop-filter: blur(8px);
        font-size: 11px;
        font-weight: 500;
        padding: 5px 10px;
    }
    .meta-pill.pill-google-connected { background: rgba(255,255,255,0.92); color: var(--teal); font-weight: 700; border: none; }


    /* Segmented Control — di atas background gelap */
    .profile-tabs-nav.ios-segmented-control {
        background: rgba(255,255,255,0.15);
        border-radius: 14px;
        padding: 5px;
        border: 1px solid rgba(255,255,255,0.25);
        backdrop-filter: blur(12px);
        width: 92%;
        margin: 0 auto;
        display: flex;
        gap: 4px;
    }
    .tab-btn {
        padding: 9px 0;
        font-size: 13px;
        font-weight: 600;
        color: rgba(255,255,255,0.75);
        border-radius: 10px;
        flex: 1;
        font-family: var(--font-head);
        box-shadow: none;
        justify-content: center;
        transition: all 0.2s ease;
    }
    .tab-btn:hover { background: rgba(255,255,255,0.10); color: #FFF; }
    .tab-btn.active {
        background: #FFFFFF;
        color: var(--teal);
        box-shadow: 0 4px 12px rgba(0,0,0,0.18);
        font-weight: 700;
    }
    .tab-btn iconify-icon { display: inline-flex; }

    /* Cards as iOS Grouped Lists */
    .profile-tab-content { padding-top: 24px; }
    .profile-grid {
        gap: 16px;
        padding: 0;
        grid-template-columns: 1fr; /* single column — semua card full width */
    }
    /* Semua card span penuh di mobile */
    .card-avatar-sync,
    .card-google-integration,
    .card-account-info,
    .card-security,
    .card-personal-main { grid-column: span 1; }

    .profile-card.ios-card {
        background: #FFF; border-radius: 10px; padding: 0 16px 0 16px; border: none; box-shadow: none; margin: 0 16px;
    }

    
    .card-header { margin: 0; padding: 16px 0 12px 0; border: none; }
    .card-header-icon { display: none; }
    .card-title { font-size: 17px; font-family: -apple-system, sans-serif; font-weight: 600; color: #000; }
    .card-subtitle { display: none; }
    
    /* FORM INPUTS iOS Style */
    .form-grid { display: flex; flex-direction: column; gap: 0; }
    .form-group {
        border-bottom: 0.5px solid var(--prf-border-ios);
        padding: 12px 0; margin: 0; display: flex; flex-direction: column; gap: 4px;
    }
    .form-group:last-child { border-bottom: none; }
    
    .form-label { font-size: 15px; font-weight: 400; color: #000; margin: 0; font-family: -apple-system, sans-serif; display: block; }
    .badge-locked { float: right; font-size: 13px; color: #8E8E93; }
    
    .input-wrapper { background: transparent; padding: 0; }
    .input-icon { display: none; }
    .form-input, .form-select, .form-textarea {
        padding: 4px 0; border: none; border-radius: 0; background: transparent;
        font-size: 16px; color: var(--teal); font-family: -apple-system, sans-serif;
    }
    .form-input:disabled, .form-select:disabled, .form-textarea:disabled { background: transparent; color: #8E8E93; }
    .form-input:focus, .form-select:focus, .form-textarea:focus { box-shadow: none; border: none; background: transparent; }
    .form-select { padding-right: 0; background-image: none; }
    
    /* PERBAIKAN TOMBOL EDIT MOBILE */
    .btn-action-edit {
        background: transparent; border: none; padding: 0; margin: 0;
        justify-content: flex-end; width: auto; color: var(--teal); box-shadow: none;
    }
    .btn-action-edit iconify-icon { display: none; }
    .btn-action-edit .btn-text-label { 
        display: inline-block; font-size: 16px; font-family: -apple-system, sans-serif; font-weight: 400; 
    }
    .btn-action-edit:active { opacity: 0.6; background: transparent; }
    
    .form-actions { background: transparent; border: none; padding: 16px 0; margin: 0; flex-direction: column; gap: 8px; }
    .btn { border-radius: 10px; font-family: -apple-system, sans-serif; font-size: 17px; font-weight: 600; padding: 14px; width: 100%; }

    /* Custom Cards Adjustments */
    .card-avatar-sync .avatar-sync-box { display: none; } 
    .card-avatar-sync { display: none; } 

    .google-status-box { padding: 12px 0; border: none; background: transparent; gap: 8px; flex-direction: column; align-items: flex-start; }
    .google-status-info { gap: 12px; }
    .google-logo-badge { width: 36px; height: 36px; border-radius: 8px; }
    .google-email-text { font-size: 15px; color: #000; font-weight: 400; }
    .google-status-pill { display: none; }
    .google-status-action { width: 100%; margin-top: 8px; }
    .hide-mobile-icon { display: none; }

    .security-box { padding: 12px 0; border: none; background: transparent; flex-direction: column; align-items: flex-start; gap: 16px; }
    .security-status { gap: 12px; }
    .security-badge-icon { width: 36px; height: 36px; background: linear-gradient(135deg, var(--navy) 0%, var(--teal) 100%); color: #FFF; border-radius: 8px; border: none; }
    .security-title { font-size: 16px; font-weight: 400; color: #000; }
    .security-box .btn { width: 100%; }

    .empty-state-icon { width: 60px; height: 60px; }
    .empty-state-title { font-size: 17px; }
    .empty-state-desc { font-size: 14px; }
    
    /* MODALS iOS Style */
    .ilmuna-modal-backdrop { align-items: flex-end; padding: 0; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(8px); }
    .ilmuna-modal-box { border-radius: 28px 28px 0 0; max-width: 100%; animation: slideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
    .modal-header { padding: 20px 24px 16px 24px; border: none; }
    .modal-icon-badge { display: none; }
    .modal-title { font-family: -apple-system, sans-serif; font-size: 20px; font-weight: 600; }
    .modal-body { padding: 0 24px 24px 24px; gap: 12px; }
    .modal-body .form-group { padding: 8px 0; }
    .modal-footer { flex-direction: column-reverse; background: #FFF; padding: 16px 24px calc(24px + env(safe-area-inset-bottom)); border: none; }
    .alert-modal-box, .confirm-modal-box {
        align-items: center;
        border-radius: 24px;
        margin: 16px auto;
        padding: 28px 20px 24px;
        max-width: calc(100% - 32px);
        width: 100%;
        align-self: center;
        animation: scaleIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    .photo-action-sheet {
        border-radius: 28px 28px 0 0;
        margin: 0;
        max-width: 100%;
        padding: 24px 20px calc(24px + env(safe-area-inset-bottom));
        animation: slideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
}

/* Animations */
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes scaleIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
@keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
@keyframes spin { 100% { transform: rotate(360deg); } }
.spin-icon { animation: spin 1s linear infinite; }

/* ==============================================================================
 * ILMUNA UI — SMOOTH IN-PLACE DOM TRANSITIONS (ANTI-RELOAD & ANTI-FLICKER)
 * Sesuai Standar Resmi README.md Bagian 4.4
 * ============================================================================== */
@keyframes rowSmoothIn {
    0% {
        opacity: 0;
        transform: translateY(-10px) scale(0.98);
        background-color: rgba(13, 122, 135, 0.15);
    }
    100% {
        opacity: 1;
        transform: translateY(0) scale(1);
        background-color: transparent;
    }
}

@keyframes rowSmoothOut {
    0% {
        opacity: 1;
        transform: scale(1);
        max-height: 60px;
    }
    100% {
        opacity: 0;
        transform: scale(0.95);
        max-height: 0;
        padding-top: 0;
        padding-bottom: 0;
        margin: 0;
    }
}

@keyframes rowFlash {
    0%   { background-color: rgba(245, 158, 11, 0.28); }
    100% { background-color: transparent; }
}

/* Kelas Trigger Animasi */
.row-smooth-in {
    animation: rowSmoothIn 0.38s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
.row-smooth-out {
    animation: rowSmoothOut 0.32s cubic-bezier(0.4, 0, 0.2, 1) forwards;
    overflow: hidden;
    pointer-events: none;
}
.row-flash {
    animation: rowFlash 1.5s ease-out forwards;
}
</style>

<?php if (defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID !== ''): ?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
<?php endif; ?>

<!-- =========================================================================== -->
<!-- JAVASCRIPT CONTROLLER                                                       -->
<!-- Sesuai Ketentuan README.md: Lazy Loads On-Demand & In-Place DOM Mutation     -->
<!-- (Zero Page Reload Policy, window.Ilmuna UI, Native loading="lazy")          -->
<!-- =========================================================================== -->
<script id="ilmuna-profile-js">
// BASE CONFIG & STATE
const API_URL = '<?= BASE_URL ?>/api/profile-action.php';
const GOOGLE_CLIENT_ID = <?= json_encode(defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : '') ?>;
const PERSONAL_TYPE = <?= json_encode($personalType) ?>;
let userHasPhoto = <?= !empty($displayPhoto) ? 'true' : 'false' ?>;
let personalDataLoaded = false;

// HTML ESCAPING HELPER
function escapeHtml(str) {
    if (window.Ilmuna && typeof window.Ilmuna.escape === 'function') {
        return window.Ilmuna.escape(str);
    }
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

// COMPUTE INITIALS HELPER
function computeInitials(fullName) {
    if (!fullName) return 'U';
    const parts = fullName.trim().split(/\s+/);
    let inisial = parts[0] ? parts[0].substring(0, 1) : '';
    if (parts[1]) {
        inisial += parts[1].substring(0, 1);
    }
    return (inisial || fullName.substring(0, 2)).toUpperCase();
}

// HANDLE iOS BACK BUTTON
function handleIosBack(e, fallbackUrl) {
    if (window.history.length > 1) {
        e.preventDefault();
        window.history.back();
    } else {
        window.location.href = fallbackUrl;
    }
}

// -----------------------------------------------------------------------------
// TAB SWITCHER & LAZY LOAD ON-DEMAND (SESUAI README.MD BAGIAN 4.4)
// -----------------------------------------------------------------------------
async function switchProfileTab(tabName) {
    const btnAccount = document.getElementById('tabBtnAccount');
    const btnPersonal = document.getElementById('tabBtnPersonal');
    const contentAccount = document.getElementById('tabContentAccount');
    const contentPersonal = document.getElementById('tabContentPersonal');

    if (tabName === 'account') {
        btnAccount.classList.add('active');
        btnPersonal.classList.remove('active');
        contentAccount.classList.add('active');
        contentPersonal.classList.remove('active');
    } else {
        btnPersonal.classList.add('active');
        btnAccount.classList.remove('active');
        contentPersonal.classList.add('active');
        contentAccount.classList.remove('active');

        // Lazy load data pribadi on-demand saat tab dibuka (tanpa reload halaman)
        if (!personalDataLoaded && PERSONAL_TYPE) {
            await lazyLoadPersonalData();
        }
    }
}

// LAZY LOAD DATA PRIBADI ON-DEMAND
async function lazyLoadPersonalData() {
    try {
        const resp = await fetch(`${API_URL}?action=get_personal_data&target_role=${encodeURIComponent(PERSONAL_TYPE)}`);
        const res = await resp.json();
        if (res.success && res.data) {
            const d = res.data;
            if (PERSONAL_TYPE === 'siswa') {
                const elNama = document.getElementById('swNamaLengkap');
                if (elNama && !elNama.value && d.nama_lengkap) elNama.value = d.nama_lengkap;
                const elHp = document.getElementById('swNoHp');
                if (elHp && !elHp.value && d.no_hp) elHp.value = d.no_hp;
                const elGm = document.getElementById('swGmail');
                if (elGm && !elGm.value && d.gmail) elGm.value = d.gmail;
                const elAl = document.getElementById('swAlamat');
                if (elAl && !elAl.value && d.alamat) elAl.value = d.alamat;
                const elKl = document.getElementById('swIdKelas');
                if (elKl && d.id_kelas) elKl.value = d.id_kelas;
            } else if (PERSONAL_TYPE === 'guru') {
                const elNama = document.getElementById('grNamaGuru');
                if (elNama && !elNama.value && d.nama_guru) elNama.value = d.nama_guru;
                const elNip = document.getElementById('grNip');
                if (elNip && !elNip.value && d.nip) elNip.value = d.nip;
                const elNup = document.getElementById('grNuptk');
                if (elNup && !elNup.value && d.nuptk) elNup.value = d.nuptk;
                const elHp = document.getElementById('grNoHp');
                if (elHp && !elHp.value && d.no_hp) elHp.value = d.no_hp;
                const elEm = document.getElementById('grEmail');
                if (elEm && !elEm.value && d.email) elEm.value = d.email;
                const elAl = document.getElementById('grAlamat');
                if (elAl && !elAl.value && d.alamat) elAl.value = d.alamat;
                const elJk = document.getElementById('grJenisKelamin');
                if (elJk && d.jenis_kelamin) elJk.value = d.jenis_kelamin;
                const elSt = document.getElementById('grStatusKepegawaian');
                if (elSt && d.status_kepegawaian) elSt.value = d.status_kepegawaian;
            }
            personalDataLoaded = true;
        }
    } catch (e) {
        personalDataLoaded = true;
    }
}

// -----------------------------------------------------------------------------
// STANDAR DIALOG & NOTIFIKASI ILMUNA UI (SESUAI README.MD BAGIAN 2 & 9.1)
// -----------------------------------------------------------------------------
function notifyToast(type, title, message) {
    if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
        window.Ilmuna.toast(type, title, message);
    } else {
        alert(`${title}: ${message}`);
    }
}

function showAlertModal(type, title, message, onClose = null) {
    if (window.Ilmuna) {
        if (type === 'success' || type === 'error') {
            window.Ilmuna.toast(type, title, message);
            if (typeof onClose === 'function') setTimeout(onClose, 800);
        } else {
            window.Ilmuna.alert(type, title, message, 'Mengerti', false, onClose);
        }
    } else {
        alert(`${title}\n${message}`);
        if (typeof onClose === 'function') onClose();
    }
}

async function showConfirmModal({
    title = 'Konfirmasi Tindakan',
    message = 'Apakah Anda yakin ingin melanjutkan tindakan ini?',
    icon = 'solar:trash-bin-trash-bold',
    type = 'danger',
    confirmText = 'Lanjutkan',
    cancelText = 'Batal',
    onConfirm = null
}) {
    let ok = false;
    if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
        const ilmType = type === 'danger' ? 'error' : type;
        ok = await window.Ilmuna.confirm(ilmType, title, message, confirmText, cancelText);
    } else {
        ok = confirm(`${title}\n${message}`);
    }
    if (ok && typeof onConfirm === 'function') {
        await onConfirm();
    }
}

// -----------------------------------------------------------------------------
// AVATAR CLICK & ACTION SHEET CONTROLLER
// -----------------------------------------------------------------------------
function handleHeroAvatarClick(event) {
    if (userHasPhoto) {
        openPhotoActionsModal();
    } else {
        document.getElementById('quickPhotoInput').click();
    }
}

function openPhotoActionsModal() {
    const el = document.getElementById('modalPhotoActions');
    if (el) el.style.display = 'flex';
}

function closePhotoActionsModal(event) {
    if (event && event.target && event.target !== event.currentTarget) return;
    const el = document.getElementById('modalPhotoActions');
    if (el) el.style.display = 'none';
}

function triggerPhotoPick() {
    closePhotoActionsModal();
    document.getElementById('quickPhotoInput').click();
}

// TOGGLE SHOW/HIDE PASSWORD
function togglePwdVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        btn.innerHTML = '<iconify-icon icon="solar:eye-closed-bold" width="18"></iconify-icon>';
    } else {
        input.type = 'password';
        btn.innerHTML = '<iconify-icon icon="solar:eye-bold" width="18"></iconify-icon>';
    }
}

// -----------------------------------------------------------------------------
// FOTO PROFIL UPLOAD (MUTASI IN-PLACE & LAZY LOAD, ZERO RELOAD)
// -----------------------------------------------------------------------------
async function handlePhotoUpload(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    if (file.size > 3 * 1024 * 1024) {
        notifyToast('error', 'Ukuran Terlalu Besar', 'Maksimal ukuran foto adalah 3MB.');
        input.value = '';
        return;
    }

    const spinner = document.getElementById('photoSpinner');
    if (spinner) spinner.classList.add('active');

    const formData = new FormData();
    formData.append('action', 'upload_photo');
    formData.append('photo', file);

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (result.success) {
            userHasPhoto = true;

            // MUTASI IN-PLACE SECARA MULUS DENGAN NATIVE LOADING="LAZY"
            const heroAvatarContainer = document.getElementById('heroAvatarContainer');
            if (heroAvatarContainer) {
                heroAvatarContainer.innerHTML = `
                    <img src="${escapeHtml(result.photo_url)}" alt="Foto Profil" id="heroAvatarImg" loading="lazy" class="row-smooth-in">
                    <label for="quickPhotoInput" class="avatar-edit-badge" title="Ganti Foto Profil" onclick="event.stopPropagation()">
                        <iconify-icon icon="solar:camera-minimalistic-bold" width="24"></iconify-icon>
                    </label>
                    <input type="file" id="quickPhotoInput" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="handlePhotoUpload(this)">
                `;
            }

            const syncPreview = document.getElementById('syncPhotoPreview');
            if (syncPreview) {
                syncPreview.innerHTML = `<img src="${escapeHtml(result.photo_url)}" alt="Foto Akun" loading="lazy" class="row-smooth-in">`;
            }

            // Pastikan tombol hapus foto tampil di panel avatar sync
            const actionButtonsGroup = document.querySelector('.card-avatar-sync .action-buttons-group');
            if (actionButtonsGroup && !actionButtonsGroup.querySelector('.btn-outline-danger')) {
                const btnDel = document.createElement('button');
                btnDel.type = 'button';
                btnDel.className = 'btn btn-outline-danger btn-sm row-smooth-in';
                btnDel.onclick = confirmDeletePhoto;
                btnDel.innerHTML = '<iconify-icon icon="solar:trash-bin-trash-bold" width="16"></iconify-icon> <span>Hapus</span>';
                actionButtonsGroup.appendChild(btnDel);
            }

            // Sinkronkan avatar topbar secara instan
            const topbarAvatar = document.querySelector('.ilmuna-user-avatar');
            if (topbarAvatar) {
                topbarAvatar.innerHTML = `<img src="${escapeHtml(result.photo_url)}" alt="Avatar" loading="lazy" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
            }

            // Kilau lembut sementara .row-flash (1.5 detik sesuai README.md 4.4)
            const cardAvatar = document.querySelector('.card-avatar-sync');
            if (cardAvatar) {
                cardAvatar.classList.remove('row-flash');
                void cardAvatar.offsetWidth;
                cardAvatar.classList.add('row-flash');
            }

            notifyToast('success', 'Berhasil Disimpan', result.message || 'Foto profil berhasil disinkronkan!');
        } else {
            notifyToast('error', 'Gagal Mengunggah', result.message || 'Gagal mengunggah foto profil.');
        }
    } catch (err) {
        notifyToast('error', 'Kesalahan Jaringan', 'Terjadi kesalahan jaringan saat mengunggah foto.');
    } finally {
        if (spinner) spinner.classList.remove('active');
        input.value = '';
    }
}

// -----------------------------------------------------------------------------
// HAPUS FOTO PROFIL (IN-PLACE MUTATION & ZERO RELOAD POLICY)
// -----------------------------------------------------------------------------
async function confirmDeletePhoto() {
    let yakin = false;
    if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
        yakin = await window.Ilmuna.confirm(
            'error',
            'Hapus Foto Profil?',
            'Foto profil Anda akan dihapus permanen dan diganti dengan inisial nama akun Anda.',
            'Ya, Hapus Foto',
            'Batal'
        );
    } else {
        yakin = confirm('Hapus foto profil Anda?');
    }
    if (!yakin) return;

    try {
        const response = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=delete_photo'
        });
        const result = await response.json();

        if (result.success) {
            userHasPhoto = false;

            // MUTASI IN-PLACE DOM TANPA RELOAD (ZERO RELOAD POLICY)
            const heroName = document.getElementById('heroFullNameDisplay')?.textContent || 'Pengguna';
            const inisial = computeInitials(heroName);

            // 1. Reset Hero Avatar ke Fallback Inisial
            const heroAvatarContainer = document.getElementById('heroAvatarContainer');
            if (heroAvatarContainer) {
                heroAvatarContainer.innerHTML = `
                    <div class="avatar-fallback row-smooth-in" id="heroAvatarFallback">${escapeHtml(inisial)}</div>
                    <label for="quickPhotoInput" class="avatar-edit-badge" title="Ganti Foto Profil" onclick="event.stopPropagation()">
                        <iconify-icon icon="solar:camera-minimalistic-bold" width="24"></iconify-icon>
                    </label>
                    <input type="file" id="quickPhotoInput" accept="image/jpeg,image/png,image/webp" style="display: none;" onchange="handlePhotoUpload(this)">
                `;
            }

            // 2. Reset Preview di Card Avatar Sync
            const syncPreview = document.getElementById('syncPhotoPreview');
            if (syncPreview) {
                syncPreview.innerHTML = `<div class="avatar-fallback-lg row-smooth-in">${escapeHtml(inisial)}</div>`;
            }

            // 3. Hapus tombol hapus foto di card dengan animasi row-smooth-out
            const deleteBtn = document.querySelector('.card-avatar-sync .btn-outline-danger');
            if (deleteBtn) {
                deleteBtn.classList.add('row-smooth-out');
                setTimeout(() => deleteBtn.remove(), 320);
            }

            // 4. Update avatar topbar
            const topbarAvatar = document.querySelector('.ilmuna-user-avatar');
            if (topbarAvatar) {
                topbarAvatar.innerHTML = `<span class="ilmuna-topbar-initials" style="font-weight:700;font-size:14px;color:var(--lumina-teal, #0D7A87);">${escapeHtml(inisial)}</span>`;
            }

            // 5. Animasi kilau in-place
            const avatarBox = document.querySelector('.card-avatar-sync');
            if (avatarBox) {
                avatarBox.classList.remove('row-flash');
                void avatarBox.offsetWidth;
                avatarBox.classList.add('row-flash');
            }

            notifyToast('success', 'Berhasil Dihapus', result.message);
        } else {
            notifyToast('error', 'Gagal Menghapus', result.message);
        }
    } catch (err) {
        notifyToast('error', 'Kesalahan Sistem', 'Gagal menghubungi server untuk menghapus foto.');
    }
}

// -----------------------------------------------------------------------------
// EDIT DATA AKUN (IN-PLACE MUTATION & ZERO RELOAD)
// -----------------------------------------------------------------------------
let isEditingAccount = false;
function toggleEditAccount() {
    isEditingAccount = !isEditingAccount;
    const inputs = ['accFullName', 'accEmail', 'accPhone'];
    inputs.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = !isEditingAccount;
    });

    const actions = document.getElementById('accountFormActions');
    if (actions) actions.style.display = isEditingAccount ? 'flex' : 'none';

    const btnText = document.getElementById('btnEditAccountText');
    if (btnText) btnText.textContent = isEditingAccount ? 'Sedang Edit' : 'Edit';
}

function cancelEditAccount() {
    toggleEditAccount();
    const form = document.getElementById('formAccount');
    if (form) form.reset();
}

async function handleAccountSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveAccount');
    btn.disabled = true;
    btn.innerHTML = '<iconify-icon icon="solar:restart-bold" width="16" class="spin-icon"></iconify-icon> Menyimpan...';

    const formData = new FormData(document.getElementById('formAccount'));
    formData.append('action', 'update_account');

    try {
        const res = await fetch(API_URL, { method: 'POST', body: formData });
        const result = await res.json();

        if (result.success) {
            // MUTASI IN-PLACE SECARA MULUS TANPA RELOAD
            const heroName = document.getElementById('heroFullNameDisplay');
            if (heroName) heroName.textContent = result.full_name;

            const heroEmail = document.getElementById('heroEmailDisplay');
            if (heroEmail) heroEmail.textContent = result.email || 'Belum ada email';

            // Jika belum ada foto, perbarui inisial fallback secara instan
            if (!userHasPhoto) {
                const newInit = computeInitials(result.full_name);
                const heroFallback = document.getElementById('heroAvatarFallback');
                if (heroFallback) heroFallback.textContent = newInit;
                const syncFallback = document.querySelector('.avatar-fallback-lg');
                if (syncFallback) syncFallback.textContent = newInit;
                const topbarInit = document.querySelector('.ilmuna-user-avatar .ilmuna-topbar-initials');
                if (topbarInit) topbarInit.textContent = newInit;
            }

            toggleEditAccount();

            // Beri efek kilau lembut sementara .row-flash (1.5 detik)
            const accCard = document.querySelector('.card-account-info');
            if (accCard) {
                accCard.classList.remove('row-flash');
                void accCard.offsetWidth;
                accCard.classList.add('row-flash');
            }

            notifyToast('success', 'Berhasil Disimpan', result.message);
        } else {
            notifyToast('error', 'Gagal', result.message);
        }
    } catch (err) {
        notifyToast('error', 'Kesalahan', 'Gagal memperbarui data akun.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon> <span>Simpan</span>';
    }
}

// -----------------------------------------------------------------------------
// EDIT DATA PRIBADI (IN-PLACE MUTATION & ZERO RELOAD)
// -----------------------------------------------------------------------------
let isEditingPersonal = false;
function toggleEditPersonal(role) {
    isEditingPersonal = !isEditingPersonal;
    if (role === 'siswa') {
        ['swNamaLengkap', 'swIdKelas', 'swNoHp', 'swGmail', 'swAlamat'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = !isEditingPersonal;
        });
        const actions = document.getElementById('siswaFormActions');
        if (actions) actions.style.display = isEditingPersonal ? 'flex' : 'none';
        const txt = document.getElementById('btnEditSiswaText');
        if (txt) txt.textContent = isEditingPersonal ? 'Sedang Edit' : 'Edit';
    } else {
        ['grNamaGuru', 'grNip', 'grNuptk', 'grJenisKelamin', 'grStatusKepegawaian', 'grNoHp', 'grEmail', 'grAlamat'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = !isEditingPersonal;
        });
        const actions = document.getElementById('guruFormActions');
        if (actions) actions.style.display = isEditingPersonal ? 'flex' : 'none';
        const txt = document.getElementById('btnEditGuruText');
        if (txt) txt.textContent = isEditingPersonal ? 'Sedang Edit' : 'Edit';
    }
}

function cancelEditPersonal(role) {
    toggleEditPersonal(role);
    const form = role === 'siswa' ? document.getElementById('formSiswa') : document.getElementById('formGuru');
    if (form) form.reset();
}

async function handlePersonalSubmit(e, role) {
    e.preventDefault();
    const btn = role === 'siswa' ? document.getElementById('btnSaveSiswa') : document.getElementById('btnSaveGuru');
    btn.disabled = true;
    btn.innerHTML = '<iconify-icon icon="solar:restart-bold" width="16" class="spin-icon"></iconify-icon> Menyimpan...';

    const form = role === 'siswa' ? document.getElementById('formSiswa') : document.getElementById('formGuru');
    const formData = new FormData(form);
    formData.append('action', 'update_personal');
    formData.append('target_role', role);

    try {
        const res = await fetch(API_URL, { method: 'POST', body: formData });
        const result = await res.json();

        if (result.success) {
            // MUTASI IN-PLACE SECARA MULUS (ZERO PAGE RELOAD POLICY)
            toggleEditPersonal(role);

            // Perbarui data tampilan di form & hero secara instan
            if (role === 'siswa' && result.data) {
                const d = result.data;
                if (d.nama_lengkap) {
                    const heroName = document.getElementById('heroFullNameDisplay');
                    if (heroName) heroName.textContent = d.nama_lengkap;
                    const accName = document.getElementById('accFullName');
                    if (accName) accName.value = d.nama_lengkap;
                    if (!userHasPhoto) {
                        const newInit = computeInitials(d.nama_lengkap);
                        const hFb = document.getElementById('heroAvatarFallback');
                        if (hFb) hFb.textContent = newInit;
                        const sFb = document.querySelector('.avatar-fallback-lg');
                        if (sFb) sFb.textContent = newInit;
                    }
                }
                if (d.nama_kelas) {
                    let classPill = document.querySelector('.hero-meta-pills .pill-class');
                    if (!classPill) {
                        const metaPills = document.querySelector('.hero-meta-pills');
                        if (metaPills) {
                            classPill = document.createElement('span');
                            classPill.className = 'meta-pill pill-class row-smooth-in';
                            classPill.innerHTML = `<iconify-icon icon="solar:notebook-bookmark-bold" width="14"></iconify-icon> <span>${escapeHtml(d.nama_kelas)}</span>`;
                            metaPills.appendChild(classPill);
                        }
                    } else {
                        const span = classPill.querySelector('span');
                        if (span) span.textContent = d.nama_kelas;
                    }
                }
                if (d.no_hp) {
                    const accPhone = document.getElementById('accPhone');
                    if (accPhone) accPhone.value = d.no_hp;
                }
            } else if (role === 'guru' && result.data) {
                const d = result.data;
                if (d.nama_guru) {
                    const heroName = document.getElementById('heroFullNameDisplay');
                    if (heroName) heroName.textContent = d.nama_guru;
                    const accName = document.getElementById('accFullName');
                    if (accName) accName.value = d.nama_guru;
                    if (!userHasPhoto) {
                        const newInit = computeInitials(d.nama_guru);
                        const hFb = document.getElementById('heroAvatarFallback');
                        if (hFb) hFb.textContent = newInit;
                        const sFb = document.querySelector('.avatar-fallback-lg');
                        if (sFb) sFb.textContent = newInit;
                    }
                }
                if (d.email) {
                    const heroEmail = document.getElementById('heroEmailDisplay');
                    if (heroEmail) heroEmail.textContent = d.email;
                    const accEmail = document.getElementById('accEmail');
                    if (accEmail) accEmail.value = d.email;
                }
                if (d.no_hp) {
                    const accPhone = document.getElementById('accPhone');
                    if (accPhone) accPhone.value = d.no_hp;
                }
            }

            // Efek kilau lembut .row-flash (1.5 detik)
            const personalCard = document.querySelector('.card-personal-main');
            if (personalCard) {
                personalCard.classList.remove('row-flash');
                void personalCard.offsetWidth;
                personalCard.classList.add('row-flash');
            }

            notifyToast('success', 'Berhasil Disimpan', result.message);
        } else {
            notifyToast('error', 'Gagal', result.message);
        }
    } catch (err) {
        notifyToast('error', 'Kesalahan', 'Gagal menyimpan data pribadi.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<iconify-icon icon="solar:diskette-bold" width="16"></iconify-icon> <span>Simpan</span>';
    }
}

// -----------------------------------------------------------------------------
// KATA SANDI (IN-PLACE MUTATION & ZERO RELOAD)
// -----------------------------------------------------------------------------
function openChangePasswordModal() {
    const el = document.getElementById('modalChangePassword');
    if (el) el.style.display = 'flex';
}

function closeChangePasswordModal() { 
    const el = document.getElementById('modalChangePassword');
    if (el) el.style.display = 'none'; 
    const form = document.getElementById('formChangePassword');
    if (form) form.reset(); 
}

async function handleChangePasswordSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmitPassword');
    btn.disabled = true;
    btn.innerHTML = '<iconify-icon icon="solar:restart-bold" width="16" class="spin-icon"></iconify-icon> Memproses...';

    const formData = new FormData(document.getElementById('formChangePassword'));
    formData.append('action', 'change_password');

    try {
        const res = await fetch(API_URL, { method: 'POST', body: formData });
        const result = await res.json();

        if (result.success) {
            closeChangePasswordModal();

            const secCard = document.querySelector('.card-security');
            if (secCard) {
                secCard.classList.remove('row-flash');
                void secCard.offsetWidth;
                secCard.classList.add('row-flash');
            }

            notifyToast('success', 'Berhasil', result.message);
        } else {
            notifyToast('error', 'Gagal', result.message);
        }
    } catch (err) {
        notifyToast('error', 'Kesalahan', 'Terjadi kesalahan sistem.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = 'Simpan';
    }
}

// -----------------------------------------------------------------------------
// GOOGLE SSO DOM RENDERERS (IN-PLACE TRANSITIONS & ZERO RELOAD)
// -----------------------------------------------------------------------------
function renderGoogleCardConnected(emailGoogle) {
    const cardGoogle = document.querySelector('.card-google-integration');
    if (!cardGoogle) return;
    const oldBox = cardGoogle.querySelector('.google-status-box');
    if (oldBox) oldBox.remove();

    const box = document.createElement('div');
    box.className = 'google-status-box status-connected row-smooth-in';
    box.innerHTML = `
        <div class="google-status-info">
            <div class="google-logo-badge">
                <iconify-icon icon="logos:google-icon" width="24"></iconify-icon>
            </div>
            <div class="google-details">
                <div class="google-status-pill">
                    <span class="status-dot"></span>
                    <span>Terhubung</span>
                </div>
                <div class="google-email-text">${escapeHtml(emailGoogle)}</div>
            </div>
        </div>
        <div class="google-status-action">
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="openUnlinkGoogleModal()">
                <iconify-icon icon="solar:link-broken-bold" width="16" class="hide-mobile-icon"></iconify-icon>
                <span>Putuskan</span>
            </button>
        </div>
    `;
    cardGoogle.appendChild(box);

    // Tambahkan lencana SSO Aktif di Hero jika belum ada (secara in-place)
    let googlePill = document.querySelector('.hero-meta-pills .pill-google-connected');
    if (!googlePill) {
        const metaPills = document.querySelector('.hero-meta-pills');
        if (metaPills) {
            googlePill = document.createElement('span');
            googlePill.className = 'meta-pill pill-google-connected row-smooth-in';
            googlePill.title = 'Terhubung dengan Google SSO';
            googlePill.innerHTML = `
                <iconify-icon icon="logos:google-icon" width="14"></iconify-icon>
                <span>SSO Aktif</span>
            `;
            metaPills.appendChild(googlePill);
        }
    }

    // Efek kilau in-place
    cardGoogle.classList.remove('row-flash');
    void cardGoogle.offsetWidth;
    cardGoogle.classList.add('row-flash');
}

function renderGoogleCardPending(emailGoogle) {
    const cardGoogle = document.querySelector('.card-google-integration');
    if (!cardGoogle) return;
    const oldBox = cardGoogle.querySelector('.google-status-box');
    if (oldBox) oldBox.remove();

    const box = document.createElement('div');
    box.className = 'google-status-box status-pending row-smooth-in';
    box.innerHTML = `
        <div class="google-status-info">
            <div class="google-logo-badge">
                <iconify-icon icon="logos:google-icon" width="24"></iconify-icon>
            </div>
            <div class="google-details">
                <div class="google-status-pill status-pill-amber">
                    <span class="status-dot status-dot-amber"></span>
                    <span>Menunggu Verifikasi Gmail</span>
                </div>
                <div class="google-email-text">${escapeHtml(emailGoogle)}</div>
                <div class="google-help-text">
                    <iconify-icon icon="solar:letter-unread-bold" width="14"></iconify-icon>
                    <span>Tautan verifikasi telah dikirim ke Gmail. Klik tautan untuk mengaktifkan.</span>
                </div>
            </div>
        </div>
        <div class="google-status-action flex-action-buttons">
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="startGoogleLink()" id="btnResendGoogleLink" title="Tautkan Ulang / Ganti Akun Google">
                <iconify-icon icon="solar:restart-bold" width="15"></iconify-icon>
                <span>Kirim Ulang</span>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="cancelPendingGoogleLink()" title="Batalkan Permintaan">
                <span>Batal</span>
            </button>
        </div>
    `;
    cardGoogle.appendChild(box);
}

function renderGoogleCardDisconnected() {
    const cardGoogle = document.querySelector('.card-google-integration');
    if (!cardGoogle) return;
    const oldBox = cardGoogle.querySelector('.google-status-box');
    if (oldBox) oldBox.remove();

    const box = document.createElement('div');
    box.className = 'google-status-box status-disconnected row-smooth-in';
    box.innerHTML = `
        <div class="google-status-info">
            <div class="google-logo-badge">
                <iconify-icon icon="logos:google-icon" width="24"></iconify-icon>
            </div>
            <div class="google-details">
                <div class="google-status-pill">
                    <span class="status-dot"></span>
                    <span>Belum Ditautkan</span>
                </div>
                <div class="google-email-text">Tautkan Akun Google</div>
                <div class="google-sub-hint">Klik tombol untuk menghubungkan akun Google Anda secara instan.</div>
            </div>
        </div>
        <div class="google-status-action">
            <button type="button" class="btn btn-primary btn-sm" id="btnConnectGoogle" onclick="startGoogleLink()">
                <iconify-icon icon="logos:google-icon" width="16"></iconify-icon>
                <span>Hubungkan</span>
            </button>
        </div>
    `;
    cardGoogle.appendChild(box);
}

// -----------------------------------------------------------------------------
// GOOGLE SSO (ONE-CLICK GIS + GMAIL VERIFICATION)
// -----------------------------------------------------------------------------
function startGoogleLink() {
    if (!GOOGLE_CLIENT_ID) {
        if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
            window.Ilmuna.alert('error', 'Konfigurasi Belum Lengkap', 'Google Client ID belum diatur pada config.php sistem.');
        } else {
            notifyToast('error', 'Konfigurasi Belum Lengkap', 'Google Client ID belum diatur.');
        }
        return;
    }

    if (window.google && window.google.accounts && window.google.accounts.oauth2) {
        const btn = document.getElementById('btnConnectGoogle') || document.getElementById('btnResendGoogleLink');
        const origHtml = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="spin-icon" width="16"></iconify-icon> <span>Menghubungkan...</span>';
        }

        const tokenClient = window.google.accounts.oauth2.initTokenClient({
            client_id: GOOGLE_CLIENT_ID,
            scope: 'openid email profile',
            prompt: 'select_account',
            callback: async function(tokenResponse) {
                if (tokenResponse && tokenResponse.access_token) {
                    await sendGoogleLinkRequest(tokenResponse.access_token, btn, origHtml);
                } else {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                    }
                }
            },
            error_callback: function(err) {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                }
            }
        });
        tokenClient.requestAccessToken();
    } else {
        notifyToast('info', 'Memuat Google SSO', 'Sedang memuat pustaka Google. Silakan coba beberapa saat lagi.');
    }
}

async function sendGoogleLinkRequest(accessToken, btn, origHtml) {
    try {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="spin-icon" width="16"></iconify-icon> <span>Menghubungkan...</span>';
        }

        const formData = new FormData();
        formData.append('action', 'link_google');
        formData.append('google_access_token', accessToken);

        const res = await fetch(API_URL, { method: 'POST', body: formData });
        const result = await res.json();

        if (result.success) {
            if (result.pending) {
                // TAMPILKAN STATUS MENUNGGU VERIFIKASI SECARA IN-PLACE (ZERO RELOAD)
                if (result.email_google) {
                    renderGoogleCardPending(result.email_google);
                }
                startGooglePendingWatcher();

                if (result.mail_failed && result.action_url) {
                    if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
                        window.Ilmuna.alert('info', 'Verifikasi Email Diperlukan', result.message, {
                            confirmButtonText: 'Buka Link Verifikasi',
                            showCancelButton: true,
                            cancelButtonText: 'Tutup'
                        }).then((choice) => {
                            if (choice && (choice.isConfirmed || choice === true)) {
                                window.open(result.action_url, '_blank');
                            }
                        });
                    } else {
                        notifyToast('info', 'Verifikasi Email Diperlukan', result.message);
                    }
                } else {
                    if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
                        window.Ilmuna.alert('info', 'Tautan Verifikasi Dikirim', result.message || 'Silakan periksa kotak masuk atau spam di Gmail Anda dan klik tautan untuk menyelesaikan penautan.');
                    } else {
                        notifyToast('info', 'Verifikasi Dikirim', result.message);
                    }
                }
            } else if (result.linked) {
                renderGoogleCardConnected(result.email_google);
                notifyToast('success', 'Berhasil Ditautkan', result.message || 'Akun Google berhasil ditautkan ke akun Anda!');
            } else {
                renderGoogleCardConnected(result.email_google);
                notifyToast('success', 'Berhasil Ditautkan', result.message || 'Akun Google berhasil ditautkan!');
            }
        } else {
            if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
                window.Ilmuna.alert('error', 'Gagal Menautkan', result.message);
            } else {
                notifyToast('error', 'Gagal', result.message);
            }
        }
    } catch (err) {
        notifyToast('error', 'Kesalahan Sistem', 'Gagal memproses penautan akun Google.');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}

async function cancelPendingGoogleLink() {
    let yakin = false;
    if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
        yakin = await window.Ilmuna.confirm(
            'warning',
            'Batalkan Verifikasi Google?',
            'Permintaan penautan akun Google yang belum diverifikasi akan dibatalkan.',
            'Ya, Batalkan',
            'Kembali'
        );
    } else {
        yakin = confirm('Batalkan verifikasi Google?');
    }
    if (!yakin) return;

    try {
        const formData = new FormData();
        formData.append('action', 'cancel_link_google');
        const res = await fetch(API_URL, { method: 'POST', body: formData });
        const result = await res.json();
        if (result.success) {
            stopGooglePendingWatcher();
            // MUTASI IN-PLACE DOM TANPA RELOAD
            renderGoogleCardDisconnected();
            notifyToast('success', 'Dibatalkan', result.message);
        } else {
            notifyToast('error', 'Gagal', result.message);
        }
    } catch (err) {
        notifyToast('error', 'Kesalahan', 'Gagal membatalkan permintaan.');
    }
}

async function openUnlinkGoogleModal() {
    let yakin = false;
    if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
        yakin = await window.Ilmuna.confirm(
            'error',
            'Putuskan Tautan Google?',
            'Anda tidak akan dapat masuk menggunakan Google SSO dengan email ini sampai ditautkan kembali.',
            'Ya, Putuskan',
            'Batal'
        );
    } else {
        yakin = confirm('Putuskan tautan Google?');
    }
    if (!yakin) return;

    try {
        const res = await fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=unlink_google'
        });
        const result = await res.json();
        if (result.success) {
            stopGooglePendingWatcher();
            // MUTASI IN-PLACE DOM TANPA RELOAD (ZERO RELOAD POLICY)
            renderGoogleCardDisconnected();

            // Hapus pill badge SSO Aktif di Hero jika ada
            const googlePill = document.querySelector('.hero-meta-pills .pill-google-connected');
            if (googlePill) {
                googlePill.classList.add('row-smooth-out');
                setTimeout(() => googlePill.remove(), 320);
            }

            notifyToast('success', 'Berhasil Diputuskan', result.message);
        } else {
            notifyToast('error', 'Gagal', result.message);
        }
    } catch (err) {
        notifyToast('error', 'Kesalahan Sistem', 'Gagal memutuskan tautan Google.');
    }
}

// -----------------------------------------------------------------------------
// LAZY STATUS WATCHER (POLL & ON-FOCUS CHECK UNTUK ZERO PAGE RELOAD)
// -----------------------------------------------------------------------------
let googlePendingInterval = null;

async function checkGoogleLinkStatusLazily(notifyOnSuccess = true) {
    const cardGoogle = document.querySelector('.card-google-integration');
    if (!cardGoogle) return;

    try {
        const res = await fetch(`${API_URL}?action=check_google_link_status`);
        const data = await res.json();
        if (data && data.success) {
            if (data.connected) {
                stopGooglePendingWatcher();
                renderGoogleCardConnected(data.email_google);
                if (notifyOnSuccess) {
                    if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
                        window.Ilmuna.alert('success', 'Akun Google Terhubung', 'Selamat! Verifikasi Gmail berhasil diselesaikan. Akun Google Anda sekarang aktif untuk masuk instan.');
                    } else {
                        notifyToast('success', 'Akun Google Terhubung', 'Verifikasi Gmail berhasil diselesaikan!');
                    }
                }
            } else if (!data.pending) {
                // Jika tidak connected dan tidak pending lagi (misal dibatalkan di tab lain)
                stopGooglePendingWatcher();
                if (cardGoogle.querySelector('.status-pending')) {
                    renderGoogleCardDisconnected();
                }
            }
        }
    } catch (e) {
        // Silent fail on background poll
    }
}

function startGooglePendingWatcher() {
    stopGooglePendingWatcher();
    googlePendingInterval = setInterval(() => {
        checkGoogleLinkStatusLazily(true);
    }, 5000);
}

function stopGooglePendingWatcher() {
    if (googlePendingInterval) {
        clearInterval(googlePendingInterval);
        googlePendingInterval = null;
    }
}

// Cek otomatis saat user kembali fokus ke tab profile.php
window.addEventListener('focus', () => {
    const cardGoogle = document.querySelector('.card-google-integration');
    if (cardGoogle && cardGoogle.querySelector('.status-pending')) {
        checkGoogleLinkStatusLazily(true);
    }
});

// Jika pada load awal statusnya sedang pending, jalankan watcher
document.addEventListener('DOMContentLoaded', () => {
    const cardGoogle = document.querySelector('.card-google-integration');
    if (cardGoogle && cardGoogle.querySelector('.status-pending')) {
        startGooglePendingWatcher();
    }
});
</script>

<?php
$content = ob_get_clean();
// Render dashboard terpadu dengan base template
render_ilmuna_dashboard($content, 'profile', 'Profil Saya', 'solar:user-circle-bold');
?>