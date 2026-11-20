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

$totalSudah = 0;
$totalBelum = 0;
foreach ($submissionReport as $rItem) {
    if (!empty($rItem['submitted_at'])) {
        $totalSudah++;
    } else {
        $totalBelum++;
    }
}

require_once BASE_PATH . '/includes/header.php';
?>

<!-- Data-First Compact Filter & Monitoring Toolbar -->
<div class="ilmuna-filter-box">
    <div class="ilmuna-filter-topline">
        <div class="ilmuna-pill-group">
            <button type="button" class="ilmuna-pill-btn is-active" data-mon-status="all" onclick="setMonStatus('all', this)">Semua</button>
            <button type="button" class="ilmuna-pill-btn" data-mon-status="done" onclick="setMonStatus('done', this)">Sudah</button>
            <button type="button" class="ilmuna-pill-btn" data-mon-status="pending" onclick="setMonStatus('pending', this)">Belum</button>
        </div>

        <div class="ilmuna-search-wrap">
            <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
            <input type="text" id="monSearchInput" class="ilmuna-search-input" placeholder="Cari nama teman atau NISN..." oninput="filterMonRows()">
        </div>

        <div class="ilmuna-filter-actions">
            <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--emerald">
                <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                Sudah: <strong><?= $totalSudah ?></strong>
            </span>
            <span class="ilmuna-nav-stat-inline">
                <iconify-icon icon="solar:clock-circle-bold"></iconify-icon>
                Belum: <strong><?= $totalBelum ?></strong>
            </span>
        </div>
    </div>

    <?php if (!empty($assignments)): ?>
        <form method="GET" class="ilmuna-filter-bottomline">
            <select name="assignment_id" class="ilmuna-select" style="flex: 1; min-width: 240px;" onchange="this.form.submit()">
                <?php foreach ($assignments as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= $selectedAssignmentId === (int)$a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['title']) ?> (Deadline: <?= date('d M', strtotime($a['due_date'])) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>

<div class="content-card">
    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table ilmuna-table-stack" id="monTable">
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
                        <tr class="ilmuna-empty-row"><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">Belum ada data tugas untuk ditampilkan.</td></tr>
                    <?php else: ?>
                        <?php foreach ($submissionReport as $rep):
                            $isDone = !empty($rep['submitted_at']);
                        ?>
                            <tr data-status="<?= $isDone ? 'done' : 'pending' ?>" data-search="<?= htmlspecialchars(strtolower(($rep['full_name'] ?? '') . ' ' . ($rep['nisn_nip'] ?? ''))) ?>">
                                <td data-label="Nama Siswa"><strong><?= htmlspecialchars($rep['full_name']) ?></strong></td>
                                <td data-label="NISN"><?= htmlspecialchars($rep['nisn_nip'] ?? '-') ?></td>
                                <td data-label="Status">
                                    <?php if ($isDone): ?>
                                        <span class="ilmuna-badge ilmuna-badge--success"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> Sudah Mengumpulkan</span>
                                    <?php else: ?>
                                        <span class="ilmuna-badge ilmuna-badge--danger">
                                            <iconify-icon icon="solar:close-circle-bold"></iconify-icon> Belum Mengumpulkan
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Waktu Kumpul">
                                    <?= $isDone ? date('d M Y, H:i', strtotime($rep['submitted_at'])) : '-' ?>
                                </td>
                                <td data-label="Nilai Guru">
                                    <?php if ($rep['score'] !== null): ?>
                                        <span class="badge badge-sky"><?= $rep['score'] ?> / 100</span>
                                    <?php elseif ($isDone): ?>
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

<script>
let currentMonStatus = 'all';
function setMonStatus(st, btn) {
    currentMonStatus = st;
    document.querySelectorAll('[data-mon-status]').forEach(b => b.classList.remove('is-active'));
    if (btn) btn.classList.add('is-active');
    filterMonRows();
}
function filterMonRows() {
    const q = (document.getElementById('monSearchInput')?.value || '').trim().toLowerCase();
    document.querySelectorAll('#monTable tbody tr[data-status]').forEach(tr => {
        const stOk = currentMonStatus === 'all' || tr.getAttribute('data-status') === currentMonStatus;
        const qOk = !q || (tr.getAttribute('data-search') || '').includes(q);
        tr.style.display = (stOk && qOk) ? '' : 'none';
    });
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
