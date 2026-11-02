<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DASHBOARD SISWA (PESERTA DIDIK)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';   

requireRole('siswa');

$pageTitle = 'Dashboard Siswa';
$pdo = getDBConnection();
$currentUser = getCurrentUser();

// Find student class
$stmtClass = $pdo->prepare("
    SELECT c.* FROM classes c 
    JOIN class_members cm ON c.id = cm.class_id 
    WHERE cm.user_id = ? LIMIT 1
");
$stmtClass->execute([$currentUser['id']]);
$myClass = $stmtClass->fetch();
$classId = $myClass ? $myClass['id'] : 0;

// Fetch Active Assignments for this student
$assignments = [];
if ($classId) {
    $stmtA = $pdo->prepare("
        SELECT a.*, s.id as submission_id, s.score, s.submitted_at
        FROM assignments a
        LEFT JOIN submissions s ON (a.id = s.assignment_id AND s.student_id = ?)
        WHERE a.class_id = ?
        ORDER BY a.due_date ASC
        LIMIT 5
    ");
    $stmtA->execute([$currentUser['id'], $classId]);
    $assignments = $stmtA->fetchAll();
}

// Fetch Today's Mutabaah Status
$stmtM = $pdo->prepare("SELECT * FROM mutabaah_logs WHERE student_id = ? AND log_date = CURRENT_DATE LIMIT 1");
$stmtM->execute([$currentUser['id']]);
$todayMutabaah = $stmtM->fetch();

// Calculate total score average
$stmtScore = $pdo->prepare("SELECT AVG(score) FROM submissions WHERE student_id = ? AND score IS NOT NULL");
$stmtScore->execute([$currentUser['id']]);
$avgScore = round($stmtScore->fetchColumn() ?: 0, 1);

// Count pending tasks
$pendingTasks = 0;
foreach ($assignments as $a) {
    if (empty($a['submission_id'])) {
        $pendingTasks++;
    }
}

require_once BASE_PATH . '/includes/header.php';
?>

<!-- Hero Banner Siswa -->
<div class="welcome-hero" style="background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 50%, #52B2DC 100%);">
    <div class="welcome-hero-content">
        <div class="welcome-hero-arabic">بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ</div>
        <h1 class="welcome-hero-title">Ahlan wa Sahlan, <?= htmlspecialchars($currentUser['full_name']) ?>!</h1>
        <p class="welcome-hero-desc">
            Kelas <strong><?= htmlspecialchars($myClass['name'] ?? 'Pendidikan Agama Islam') ?></strong> &bull; Terus tingkatkan semangat tholabul 'ilmi (menuntut ilmu) dan jaga istiqomah dalam menjalankan ibadah yaumiyah setiap hari.
        </p>
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="<?= BASE_URL ?>/dashboard/siswa/absensi.php" class="btn btn-sm" style="background: #10B981; border: 1px solid #059669; color: #ffffff !important; font-weight: 700; box-shadow: 0 4px 12px rgba(16,185,129,0.35);">
                <iconify-icon icon="solar:qr-code-bold"></iconify-icon> Presensi KBM & Live QR
            </a>
            <a href="<?= BASE_URL ?>/dashboard/siswa/mutabaah.php" class="btn btn-gold btn-sm">
                <iconify-icon icon="solar:hand-heart-bold"></iconify-icon> Isi Jurnal Ibadah Hari Ini
            </a>
            <a href="<?= BASE_URL ?>/dashboard/siswa/materi.php" class="btn btn-secondary btn-sm" style="background: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.3); color: #ffffff !important;">
                <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon> Pelajari Materi PAI
            </a>
            <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="btn btn-secondary btn-sm" style="background: rgba(255,255,255,0.15); border-color: rgba(255,255,255,0.3); color: #ffffff !important;">
                <iconify-icon icon="solar:pen-new-square-bold"></iconify-icon> Tugas Saya (<?= $pendingTasks ?> Menunggu)
            </a>
        </div>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-sky">
            <iconify-icon icon="solar:hourglass-line-bold"></iconify-icon>
        </div>
        <div>
            <div class="stat-number"><?= $pendingTasks ?></div>
            <div class="stat-label">Tugas Belum Dikumpulkan</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon stat-icon-teal">
            <iconify-icon icon="solar:cup-star-bold"></iconify-icon>
        </div>
        <div>
            <div class="stat-number"><?= $avgScore > 0 ? $avgScore : '-' ?></div>
            <div class="stat-label">Rata-rata Nilai PAI</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon stat-icon-gold">
            <iconify-icon icon="solar:buildings-2-bold"></iconify-icon>
        </div>
        <div>
            <div class="stat-number" style="font-size: 1.15rem;">
                <?= $todayMutabaah ? '<span style="color: #059669;"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Sudah Diisi</span>' : '<span style="color: #d97706;"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> Belum Diisi</span>' ?>
            </div>
            <div class="stat-label">Mutaba'ah Ibadah Hari Ini</div>
        </div>
    </div>
</div>

<!-- Tugas PAI Menunggu -->
<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <iconify-icon icon="solar:clipboard-check-bold" style="color: var(--primary-teal);"></iconify-icon> Tugas & Evaluasi PAI Terbaru
        </h3>
        <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="btn btn-secondary btn-sm">
            Semua Tugas <iconify-icon icon="solar:arrow-right-linear"></iconify-icon>
        </a>
    </div>
    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Mata Uji / Tugas</th>
                        <th>Kategori</th>
                        <th>Batas Waktu</th>
                        <th>Status Saya</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">Alhamdulillah, tidak ada tugas yang sedang aktif.</td></tr>
                    <?php else: ?>
                        <?php foreach ($assignments as $a): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($a['title']) ?></strong>
                                </td>
                                <td><span class="badge badge-navy"><?= htmlspecialchars($a['category']) ?></span></td>
                                <td><?= date('d M Y, H:i', strtotime($a['due_date'])) ?> WIB</td>
                                <td>
                                    <?php if ($a['score'] !== null): ?>
                                        <span class="badge badge-success"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Dinilai: <?= $a['score'] ?>/100</span>
                                    <?php elseif (!empty($a['submission_id'])): ?>
                                        <span class="badge badge-sky"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> Menunggu Penilaian</span>
                                    <?php else: ?>
                                        <span class="badge badge-gold"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> Belum Dikerjakan</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/dashboard/siswa/tugas.php" class="btn btn-secondary btn-sm">
                                        <?= empty($a['submission_id']) ? '<iconify-icon icon="solar:cloud-upload-bold"></iconify-icon> Kumpulkan' : '<iconify-icon icon="solar:eye-linear"></iconify-icon> Detail' ?>
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
