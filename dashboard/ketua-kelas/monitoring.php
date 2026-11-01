<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — TASK MONITORING FOR CLASSMATES (KETUA KELAS)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('ketua_kelas');

$pageTitle = 'Monitoring Tugas & Kedisiplinan Kelas';
$pdo = getDBConnection();
$currentUser = getCurrentUser();

// Find class led by this user
$stmtClass = $pdo->prepare("SELECT * FROM classes WHERE ketua_kelas_id = ? LIMIT 1");
$stmtClass->execute([$currentUser['id']]);
$classLed = $stmtClass->fetch();

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

// Fetch Assignments for this class
$assignments = [];
if ($classId) {
    $stmtA = $pdo->prepare("SELECT * FROM assignments WHERE class_id = ? ORDER BY due_date DESC");
    $stmtA->execute([$classId]);
    $assignments = $stmtA->fetchAll();
}

$selectedAssignmentId = isset($_GET['assignment_id']) ? (int)$_GET['assignment_id'] : ($assignments[0]['id'] ?? 0);

// Fetch students submission status for chosen assignment
$submissionReport = [];
if ($classId && $selectedAssignmentId) {
    $stmtR = $pdo->prepare("
        SELECT u.id, u.full_name, u.nisn_nip,
               s.submitted_at, s.score
        FROM users u
        JOIN class_members cm ON u.id = cm.user_id
        LEFT JOIN submissions s ON (s.student_id = u.id AND s.assignment_id = ?)
        WHERE cm.class_id = ?
        ORDER BY s.submitted_at DESC, u.full_name ASC
    ");
    $stmtR->execute([$selectedAssignmentId, $classId]);
    $submissionReport = $stmtR->fetchAll();
}

require_once BASE_PATH . '/includes/header.php';
?>

<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <i class="fas fa-list-check" style="color: var(--primary-teal);"></i> Pemantau Tugas PAI: <?= htmlspecialchars($classLed['name'] ?? 'Kelas PAI') ?>
        </h3>
        <div>
            <!-- Assignment Selector Filter -->
            <form method="GET" style="display: flex; gap: 8px;">
                <select name="assignment_id" class="form-control" style="padding: 6px 12px; font-size: 0.85rem;" onchange="this.form.submit()">
                    <?php foreach ($assignments as $a): ?>
                        <option value="<?= $a['id'] ?>" <?= $selectedAssignmentId === (int)$a['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['title']) ?> (Deadline: <?= date('d M', strtotime($a['due_date'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Nama Rekan Sekelas</th>
                        <th>NISN</th>
                        <th>Status Pengumpulan</th>
                        <th>Waktu Kumpul</th>
                        <th>Status Nilai Guru</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissionReport)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">Belum ada data tugas untuk ditampilkan.</td></tr>
                    <?php else: ?>
                        <?php foreach ($submissionReport as $rep): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($rep['full_name']) ?></strong></td>
                                <td><?= htmlspecialchars($rep['nisn_nip'] ?? '-') ?></td>
                                <td>
                                    <?php if (!empty($rep['submitted_at'])): ?>
                                        <span class="badge badge-success"><i class="fas fa-circle-check"></i> Sudah Mengumpulkan</span>
                                    <?php else: ?>
                                        <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3);">
                                            <i class="fas fa-circle-xmark"></i> Belum Mengumpulkan
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= !empty($rep['submitted_at']) ? date('d M Y, H:i', strtotime($rep['submitted_at'])) : '-' ?>
                                </td>
                                <td>
                                    <?php if ($rep['score'] !== null): ?>
                                        <span class="badge badge-sky"><?= $rep['score'] ?> / 100</span>
                                    <?php elseif (!empty($rep['submitted_at'])): ?>
                                        <span class="badge badge-gold">Sedang Diperiksa</span>
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
