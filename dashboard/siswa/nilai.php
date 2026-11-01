<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — REKAP NILAI & TRANSKRIP TUGAS PAI (SISWA)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('siswa');

$pageTitle = 'Rekap Nilai & Evaluasi Pembelajaran PAI';
$pdo = getDBConnection();
$currentUser = getCurrentUser();

// Fetch all graded submissions
$stmtGrades = $pdo->prepare("
    SELECT a.title as assignment_title, a.category, a.max_score,
           s.score, s.teacher_feedback, s.submitted_at, s.graded_at
    FROM submissions s
    JOIN assignments a ON s.assignment_id = a.id
    WHERE s.student_id = ?
    ORDER BY s.submitted_at DESC
");
$stmtGrades->execute([$currentUser['id']]);
$grades = $stmtGrades->fetchAll();

// Calculate stats
$totalTaken = count($grades);
$totalScore = 0;
$gradedCount = 0;
$highestScore = 0;

foreach ($grades as $g) {
    if ($g['score'] !== null) {
        $totalScore += $g['score'];
        $gradedCount++;
        if ($g['score'] > $highestScore) {
            $highestScore = $g['score'];
        }
    }
}

$average = $gradedCount > 0 ? round($totalScore / $gradedCount, 1) : 0;

require_once BASE_PATH . '/includes/header.php';
?>

<!-- Score Overview Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-teal">
            <i class="fas fa-chart-line"></i>
        </div>
        <div>
            <div class="stat-number"><?= $average > 0 ? $average : '-' ?></div>
            <div class="stat-label">Rata-rata Nilai Tugas</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon stat-icon-gold">
            <i class="fas fa-trophy"></i>
        </div>
        <div>
            <div class="stat-number"><?= $highestScore > 0 ? $highestScore : '-' ?></div>
            <div class="stat-label">Nilai Tertinggi Dicapai</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon stat-icon-sky">
            <i class="fas fa-check-double"></i>
        </div>
        <div>
            <div class="stat-number"><?= $gradedCount ?> / <?= $totalTaken ?></div>
            <div class="stat-label">Tugas Telah Dinilai</div>
        </div>
    </div>
</div>

<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <i class="fas fa-award" style="color: var(--primary-teal);"></i> Transkrip Penilaian Tugas & Ujian Mandiri
        </h3>
    </div>
    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Tugas PAI</th>
                        <th>Kategori</th>
                        <th>Tanggal Pengumpulan</th>
                        <th>Nilai Perolehan</th>
                        <th>Catatan / Evaluasi Guru</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grades)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">Belum ada riwayat tugas yang dikumpulkan.</td></tr>
                    <?php else: ?>
                        <?php foreach ($grades as $gr): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($gr['assignment_title']) ?></strong></td>
                                <td><span class="badge badge-navy"><?= htmlspecialchars($gr['category']) ?></span></td>
                                <td><?= date('d M Y, H:i', strtotime($gr['submitted_at'])) ?></td>
                                <td>
                                    <?php if ($gr['score'] !== null): ?>
                                        <span class="badge badge-success" style="font-size: 0.95rem; font-weight: 700;"><?= $gr['score'] ?> / <?= $gr['max_score'] ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-gold">Sedang Diperiksa</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($gr['teacher_feedback'])): ?>
                                        <div style="font-size: 0.85rem; color: #065f46; background: rgba(16, 185, 129, 0.1); padding: 8px 12px; border-radius: 8px; border: 1px solid rgba(16, 185, 129, 0.25);">
                                            <i class="fas fa-comment-dots"></i> <?= htmlspecialchars($gr['teacher_feedback']) ?>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.8rem;">-</span>
                                    <?php endif; ?>
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
