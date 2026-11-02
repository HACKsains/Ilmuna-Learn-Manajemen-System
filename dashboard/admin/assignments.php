<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — ASSIGNMENTS & STUDENT GRADING (ADMIN / GURU PAI)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole(['admin', 'guru']);

$pageTitle = 'Tugas & Penilaian Siswa';
$pdo = getDBConnection();
$currentUser = getCurrentUser();
$message = null;
$error = null;

// Handle Add Assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_assignment') {
    $title = trim($_POST['title'] ?? '');
    $category = $_POST['category'] ?? 'Tugas Mandiri';
    $classId = (int)$_POST['class_id'];
    $dueDate = $_POST['due_date'] ?? '';
    $maxScore = (int)($_POST['max_score'] ?? 100);
    $description = trim($_POST['description'] ?? '');

    if (empty($title) || empty($dueDate) || empty($classId)) {
        $error = 'Judul tugas, batas waktu pengumpulan, dan kelas wajib dipilih.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO assignments (title, category, class_id, author_id, description, due_date, max_score)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $category, $classId, $currentUser['id'], $description, $dueDate, $maxScore]);
            $message = 'Tugas pembelajaran baru berhasil dibuat!';
        } catch (Exception $e) {
            $error = 'Gagal membuat tugas: ' . $e->getMessage();
        }
    }
}

// Handle Grading
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'grade_submission') {
    $subId = (int)$_POST['submission_id'];
    $score = (int)$_POST['score'];
    $feedback = trim($_POST['teacher_feedback'] ?? '');

    try {
        $stmt = $pdo->prepare("UPDATE submissions SET score = ?, teacher_feedback = ?, graded_at = NOW() WHERE id = ?");
        $stmt->execute([$score, $feedback, $subId]);
        $message = 'Nilai dan umpan balik tugas berhasil disimpan!';
    } catch (Exception $e) {
        $error = 'Gagal menyimpan nilai: ' . $e->getMessage();
    }
}

$classes = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();

// Fetch Assignments with Submission Counts
$assignments = $pdo->query("
    SELECT a.*, c.name as class_name,
           (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id) as total_submitted,
           (SELECT COUNT(*) FROM submissions WHERE assignment_id = a.id AND score IS NOT NULL) as total_graded
    FROM assignments a
    LEFT JOIN classes c ON a.class_id = c.id
    ORDER BY a.id DESC
")->fetchAll();

// Fetch Submissions for Grading View
$submissions = $pdo->query("
    SELECT s.*, u.full_name as student_name, a.title as assignment_title, c.name as class_name
    FROM submissions s
    JOIN users u ON s.student_id = u.id
    JOIN assignments a ON s.assignment_id = a.id
    LEFT JOIN classes c ON a.class_id = c.id
    ORDER BY s.submitted_at DESC
")->fetchAll();

require_once BASE_PATH . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> <?= $message ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Assignments List Card -->
<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <iconify-icon icon="solar:clipboard-list-bold" style="color: var(--primary-teal);"></iconify-icon> Daftar Tugas & Ujian PAI Aktif
        </h3>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addAssignmentModal')">
            <iconify-icon icon="solar:add-circle-bold"></iconify-icon> Buat Tugas Baru
        </button>
    </div>
    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Judul Tugas</th>
                        <th>Kategori</th>
                        <th>Kelas</th>
                        <th>Batas Waktu (Deadline)</th>
                        <th>Progres Pengumpulan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">Belum ada tugas yang dibuat.</td></tr>
                    <?php else: ?>
                        <?php foreach ($assignments as $a): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($a['title']) ?></strong>
                                    <div style="font-size: 0.8rem; color: var(--text-muted);"><?= htmlspecialchars(substr($a['description'], 0, 75)) ?>...</div>
                                </td>
                                <td><span class="badge badge-navy"><?= htmlspecialchars($a['category']) ?></span></td>
                                <td><span class="badge badge-teal"><?= htmlspecialchars($a['class_name'] ?? '-') ?></span></td>
                                <td><?= date('d M Y, H:i', strtotime($a['due_date'])) ?> WIB</td>
                                <td>
                                    <span class="badge badge-sky"><iconify-icon icon="solar:plain-bold"></iconify-icon> <?= $a['total_submitted'] ?> Terkumpul</span>
                                    <span class="badge badge-success"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> <?= $a['total_graded'] ?> Dinilai</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Student Submissions Grading Card -->
<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <iconify-icon icon="solar:user-check-bold" style="color: var(--primary-sky);"></iconify-icon> Pengumpulan Tugas Siswa & Penilaian
        </h3>
    </div>
    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Siswa</th>
                        <th>Tugas</th>
                        <th>Jawaban Siswa</th>
                        <th>Nilai</th>
                        <th style="text-align: right;">Aksi Penilaian</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($submissions)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">Belum ada tugas yang dikumpulkan siswa.</td></tr>
                    <?php else: ?>
                        <?php foreach ($submissions as $sub): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($sub['student_name']) ?></strong>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?= date('d M Y, H:i', strtotime($sub['submitted_at'])) ?></div>
                                </td>
                                <td><?= htmlspecialchars($sub['assignment_title']) ?></td>
                                <td>
                                    <div style="font-size: 0.85rem; color: var(--text-body); max-width: 350px; background: #f8fafc; padding: 8px 12px; border-radius: 8px; border: 1px solid var(--border-subtle);">
                                        <?= nl2br(htmlspecialchars(substr($sub['submitted_text'] ?? 'Tidak ada teks jawaban', 0, 150))) ?>...
                                    </div>
                                </td>
                                <td>
                                    <?php if ($sub['score'] !== null): ?>
                                        <span class="badge badge-success" style="font-size: 0.95rem; font-weight: 700;"><?= $sub['score'] ?> / 100</span>
                                    <?php else: ?>
                                        <span class="badge badge-gold">Belum Dinilai</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn btn-primary btn-sm" onclick="gradeStudent(<?= $sub['id'] ?>, '<?= htmlspecialchars($sub['student_name'], ENT_QUOTES) ?>', <?= $sub['score'] ?? 'null' ?>, '<?= htmlspecialchars($sub['teacher_feedback'] ?? '', ENT_QUOTES) ?>')">
                                        <iconify-icon icon="solar:pen-bold"></iconify-icon> <?= $sub['score'] !== null ? 'Ubah Nilai' : 'Beri Nilai' ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Buat Tugas Baru -->
<div class="ilm-modal-backdrop" id="addAssignmentModal">
    <div class="ilm-modal-dialog" style="max-width: 580px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title">Buat Tugas / Ujian PAI Baru</h3>
                    <p class="ilm-modal-header__subtitle">Tentukan kelas sasaran dan tenggat waktu pengerjaan</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('addAssignmentModal')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form method="POST">
            <div class="ilm-modal-body" style="max-height: calc(88vh - 160px);">
                <input type="hidden" name="action" value="add_assignment">
                <div class="form-group">
                    <label class="form-label">Judul Tugas / Evaluasi *</label>
                    <input type="text" name="title" class="form-control" placeholder="Contoh: Tugas Mandiri: Analisis Penerapan Nilai Tawadhu" required>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Kategori Tugas</label>
                        <select name="category" class="form-control">
                            <option value="Tugas Mandiri">Tugas Mandiri</option>
                            <option value="Tugas Kelompok">Tugas Kelompok</option>
                            <option value="Kuis Harian">Kuis Harian</option>
                            <option value="Ujian Akhir KD">Ujian Akhir KD</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Kelas Sasaran *</label>
                        <select name="class_id" class="form-control" required>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Batas Waktu Pengumpulan (Deadline) *</label>
                        <input type="text" name="due_date" class="form-control" data-ilm-calendar="datetime" readonly placeholder="Pilih batas tenggat..." style="cursor: pointer; background: #FFF;" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Skor Maksimal</label>
                        <input type="number" name="max_score" class="form-control" value="100">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Petunjuk & Instruksi Tugas</label>
                    <textarea name="description" class="form-control" placeholder="Tuliskan petunjuk pengerjaan tugas secara jelas..."></textarea>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('addAssignmentModal')">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                    <span>Batal</span>
                </button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                    <span>Buat Tugas</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Beri Nilai Siswa -->
<div class="ilm-modal-backdrop" id="gradeModal">
    <div class="ilm-modal-dialog" style="max-width: 480px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:medal-star-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title">Penilaian: <span id="gradeStudentName"></span></h3>
                    <p class="ilm-modal-header__subtitle">Input skor dan catatan evaluasi guru</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('gradeModal')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form method="POST">
            <div class="ilm-modal-body" style="max-height: calc(88vh - 160px);">
                <input type="hidden" name="action" value="grade_submission">
                <input type="hidden" name="submission_id" id="gradeSubId">
                <div class="form-group">
                    <label class="form-label">Nilai Skor (0 - 100) *</label>
                    <input type="number" name="score" id="gradeScoreInput" class="form-control" min="0" max="100" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Umpan Balik / Catatan Guru PAI</label>
                    <textarea name="teacher_feedback" id="gradeFeedbackInput" class="form-control" placeholder="Contoh: Sangat baik, pemahaman rukun sudah tepat dan sistematis..."></textarea>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('gradeModal')">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                    <span>Batal</span>
                </button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:disk-bold"></iconify-icon>
                    <span>Simpan Penilaian</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function gradeStudent(subId, studentName, currentScore, currentFeedback) {
        document.getElementById('gradeSubId').value = subId;
        document.getElementById('gradeStudentName').innerText = studentName;
        document.getElementById('gradeScoreInput').value = currentScore !== null ? currentScore : 85;
        document.getElementById('gradeFeedbackInput').value = currentFeedback || '';
        openModal('gradeModal');
    }
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
