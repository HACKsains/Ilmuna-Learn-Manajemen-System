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

<!-- Data-First Compact Toolbar & Inline KPI Metrics (Zero Hero Banner) -->
<div class="ilmuna-filter-box">
    <div class="ilmuna-filter-topline">
        <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--navy">
            <iconify-icon icon="solar:buildings-2-bold"></iconify-icon>
            Rombel: <strong><?= htmlspecialchars($classLed['nama_kelas'] ?? ($classLed['name'] ?? 'Belum terhubung')) ?></strong>
        </span>
        <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--teal">
            <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
            Siswa: <strong><?= (int)$totalMembers ?></strong>
        </span>
        <span class="ilmuna-nav-stat-inline">
            <iconify-icon icon="solar:clipboard-list-bold"></iconify-icon>
            Tugas Aktif: <strong><?= count($assignments) ?></strong>
        </span>

        <div class="ilmuna-filter-actions">
            <a href="<?= BASE_URL ?>/dashboard/ketua-kelas/presensi.php" class="ilmuna-nav-btn ilmuna-nav-btn--primary">
                <iconify-icon icon="solar:clipboard-check-bold"></iconify-icon>
                <span>Verifikasi Perizinan</span>
            </a>
            <a href="<?= BASE_URL ?>/dashboard/ketua-kelas/monitoring.php" class="ilmuna-nav-btn ilmuna-nav-btn--teal">
                <iconify-icon icon="solar:checklist-minimalistic-bold"></iconify-icon>
                <span>Monitor Tugas</span>
            </a>
            <a href="<?= BASE_URL ?>/dashboard/ketua-kelas/agenda.php" class="ilmuna-nav-btn ilmuna-nav-btn--ghost">
                <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                <span>Agenda Kelas</span>
            </a>
            <?php if (hasRole('siswa')): ?>
                <a href="<?= BASE_URL ?>/switch-role.php?role=siswa" class="ilmuna-nav-btn ilmuna-nav-btn--ghost">
                    <iconify-icon icon="solar:diploma-bold"></iconify-icon>
                    <span>Mode Siswa</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Tugas PAI Kelas -->
<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <iconify-icon icon="solar:megaphone-bold" style="color: var(--primary-teal);"></iconify-icon> Status Tugas PAI Kelas
        </h3>
        <a href="<?= BASE_URL ?>/dashboard/ketua-kelas/monitoring.php" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" style="height: 34px; font-size: 12px;">
            <span>Lihat Rincian</span> <iconify-icon icon="solar:arrow-right-linear"></iconify-icon>
        </a>
    </div>
    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table ilmuna-table-stack">
                <thead>
                    <tr>
                        <th>Tugas</th>
                        <th>Kategori</th>
                        <th>Batas Waktu</th>
                        <th>Yang Sudah Kumpul</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments)): ?>
                        <tr class="ilmuna-empty-row"><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">Tidak ada tugas yang sedang aktif di kelas ini.</td></tr>
                    <?php else: ?>
                        <?php foreach ($assignments as $asg): ?>
                            <tr>
                                <td data-label="Tugas"><strong><?= htmlspecialchars($asg['title']) ?></strong></td>
                                <td data-label="Kategori"><span class="badge badge-teal"><?= htmlspecialchars($asg['category']) ?></span></td>
                                <td data-label="Batas Waktu"><?= date('d M Y, H:i', strtotime($asg['due_date'])) ?> WIB</td>
                                <td data-label="Sudah Kumpul">
                                    <span class="badge badge-sky"><?= $asg['submitted_count'] ?> / <?= $totalMembers ?> Siswa</span>
                                </td>
                                <td data-label="Aksi" style="text-align: right;">
                                    <a href="<?= BASE_URL ?>/dashboard/ketua-kelas/monitoring.php?assignment_id=<?= (int)$asg['id'] ?>" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" style="height: 34px; font-size: 12px;">
                                        <iconify-icon icon="solar:eye-linear"></iconify-icon>
                                        <span>Cek Teman</span>
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
