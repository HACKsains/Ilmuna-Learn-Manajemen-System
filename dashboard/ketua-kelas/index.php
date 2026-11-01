<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DASHBOARD KETUA KELAS
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('ketua_kelas');

$pageTitle = 'Dashboard Ketua Kelas';
$pdo = getDBConnection();
$currentUser = getCurrentUser();

// Find class led by this user
$stmtClass = $pdo->prepare("SELECT * FROM classes WHERE ketua_kelas_id = ? LIMIT 1");
$stmtClass->execute([$currentUser['id']]);
$classLed = $stmtClass->fetch();

// Fallback: If not explicitly set in ketua_kelas_id, find the class they belong to
if (!$classLed) {
    $stmtFallback = $pdo->prepare("
        SELECT c.* FROM classes c 
        JOIN class_members cm ON c.id = cm.class_id 
        WHERE cm.user_id = ? LIMIT 1
    ");
    $stmtFallback->execute([$currentUser['id']]);
    $classLed = $stmtFallback->fetch();
}

$classId = $classLed ? $classLed['id'] : 0;
$totalMembers = 0;
$classmates = [];
$assignments = [];

if ($classId) {
    // Total members in class
    $totalMembers = $pdo->query("SELECT COUNT(*) FROM class_members WHERE class_id = $classId")->fetchColumn();

    // Fetch class assignments
    $stmtA = $pdo->prepare("
        SELECT a.*, (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id) as submitted_count
        FROM assignments a
        WHERE a.class_id = ?
        ORDER BY a.due_date ASC
    ");
    $stmtA->execute([$classId]);
    $assignments = $stmtA->fetchAll();
}

require_once BASE_PATH . '/includes/header.php';
?>

<!-- Hero Banner Ketua Kelas -->
<div class="welcome-hero" style="background: linear-gradient(135deg, #0D7A87 0%, #1D3B5E 100%);">
    <div class="welcome-hero-content">
        <div class="welcome-hero-arabic">السَّلَامُ عَلَيْكُمْ وَرَحْمَةُ اللهِ وَبَرَكَاتُهُ</div>
        <h1 class="welcome-hero-title">Semangat Memimpin, <?= htmlspecialchars($currentUser['full_name']) ?>!</h1>
        <p class="welcome-hero-desc">
            Sebagai Ketua Kelas <strong><?= htmlspecialchars($classLed['nama_kelas'] ?? ($classLed['name'] ?? 'PAI')) ?></strong>, Anda memegang amanah untuk mendampingi rekan sekelas, memverifikasi permohonan perizinan kehadiran (Tahap 1), dan memantau kedisiplinan belajar.
        </p>
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="<?= BASE_URL ?>/dashboard/ketua-kelas/presensi.php" class="btn btn-gold btn-sm">
                <i class="fas fa-clipboard-check"></i> Verifikasi Perizinan Siswa
            </a>
            <a href="<?= BASE_URL ?>/dashboard/ketua-kelas/monitoring.php" class="btn btn-secondary btn-sm" style="background: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.3); color: #ffffff !important;">
                <i class="fas fa-list-check"></i> Monitor Tugas Teman
            </a>
            <?php if (hasRole('siswa')): ?>
                <a href="<?= BASE_URL ?>/switch-role.php?role=siswa" class="btn btn-secondary btn-sm" style="background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.25); color: #52B2DC !important;">
                    <i class="fas fa-graduation-cap"></i> Masuk Sebagai Siswa
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Stats Ketua Kelas -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-teal">
            <i class="fas fa-users"></i>
        </div>
        <div>
            <div class="stat-number"><?= $totalMembers ?></div>
            <div class="stat-label">Siswa di Kelas Anda</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon stat-icon-sky">
            <i class="fas fa-clipboard-list"></i>
        </div>
        <div>
            <div class="stat-number"><?= count($assignments) ?></div>
            <div class="stat-label">Tugas PAI Aktif</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon stat-icon-gold">
            <i class="fas fa-school"></i>
        </div>
        <div>
            <div class="stat-number" style="font-size: 1.25rem;"><?= htmlspecialchars($classLed['name'] ?? 'Belum terhubung') ?></div>
            <div class="stat-label">Rombel Belajar</div>
        </div>
    </div>
</div>

<!-- Tugas PAI Kelas -->
<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <i class="fas fa-bullhorn" style="color: var(--primary-teal);"></i> Status Tugas PAI yang Perlu Dikumpulkan Teman Sekelas
        </h3>
        <a href="<?= BASE_URL ?>/dashboard/ketua-kelas/monitoring.php" class="btn btn-secondary btn-sm">
            Lihat Rincian <i class="fas fa-arrow-right"></i>
        </a>
    </div>
    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Tugas</th>
                        <th>Kategori</th>
                        <th>Batas Waktu</th>
                        <th>Yang Sudah Kumpul</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">Tidak ada tugas yang sedang aktif di kelas ini.</td></tr>
                    <?php else: ?>
                        <?php foreach ($assignments as $asg): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($asg['title']) ?></strong></td>
                                <td><span class="badge badge-teal"><?= htmlspecialchars($asg['category']) ?></span></td>
                                <td><?= date('d M Y, H:i', strtotime($asg['due_date'])) ?> WIB</td>
                                <td>
                                    <span class="badge badge-sky"><?= $asg['submitted_count'] ?> / <?= $totalMembers ?> Siswa</span>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/dashboard/ketua-kelas/monitoring.php" class="btn btn-secondary btn-sm">
                                        <i class="fas fa-eye"></i> Cek Nama Teman
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
