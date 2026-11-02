<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — CLASS AGENDA & TADARUS QUR'AN (KETUA KELAS)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('ketua_kelas');

$pageTitle = 'Agenda Kelas & Tadarus Al-Qur\'an';
$pdo = getDBConnection();
$currentUser = getCurrentUser();
$message = null;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_agenda') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');

    if (!empty($title) && !empty($content)) {
        $stmtAnn = $pdo->prepare("INSERT INTO announcements (title, content, class_id, target_role, author_id) VALUES (?, ?, ?, 'siswa', ?)");
        $stmtAnn->execute([$title, $content, $classId, $currentUser['id']]);
        $message = "Agenda kelas / pengumuman tadarus berhasil dibagikan ke seluruh teman sekelas!";
    }
}

// Fetch class announcements
$announcements = [];
if ($classId) {
    $stmtAnnList = $pdo->prepare("
        SELECT a.*, u.full_name as author_name 
        FROM announcements a 
        JOIN users u ON a.author_id = u.id 
        WHERE a.class_id = ? OR a.class_id IS NULL
        ORDER BY a.created_at DESC
    ");
    $stmtAnnList->execute([$classId]);
    $announcements = $stmtAnnList->fetchAll();
}

require_once BASE_PATH . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> <?= $message ?></div>
<?php endif; ?>

<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <iconify-icon icon="solar:book-bookmark-bold" style="color: var(--primary-teal);"></iconify-icon> Catatan Agenda & Tadarus Kelas: <?= htmlspecialchars($classLed['name'] ?? 'Kelas PAI') ?>
        </h3>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addAgendaModal')">
            <iconify-icon icon="solar:add-circle-bold"></iconify-icon> Tambah Agenda / Pengumuman
        </button>
    </div>
    <div class="content-card-body">
        <div style="display: grid; gap: 16px;">
            <?php if (empty($announcements)): ?>
                <div style="text-align: center; color: var(--text-muted); padding: 32px;">
                    Belum ada agenda kelas yang dicatat. Buat catatan tadarus atau pengumuman piket kelas untuk teman-teman.
                </div>
            <?php else: ?>
                <?php foreach ($announcements as $ann): ?>
                    <div style="background: #f8fafc; border: 1px solid var(--border-subtle); border-radius: 14px; padding: 20px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                            <h4 style="margin: 0; font-size: 1.05rem; color: var(--text-heading);"><?= htmlspecialchars($ann['title']) ?></h4>
                            <span style="font-size: 0.78rem; color: var(--text-muted);"><?= date('d M Y, H:i', strtotime($ann['created_at'])) ?> WIB</span>
                        </div>
                        <p style="font-size: 0.92rem; color: var(--text-body); margin-bottom: 12px; line-height: 1.6;">
                            <?= nl2br(htmlspecialchars($ann['content'])) ?>
                        </p>
                        <div style="font-size: 0.78rem; color: var(--primary-teal); font-weight: 600;">
                            <iconify-icon icon="solar:pen-new-square-bold"></iconify-icon> Diumumkan oleh: <?= htmlspecialchars($ann['author_name']) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Tambah Agenda -->
<div id="addAgendaModal" style="display: none; position: fixed; inset: 0; background: rgba(8, 16, 26, 0.7); backdrop-filter: blur(8px); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #ffffff; width: 100%; max-width: 500px; border-radius: 20px; box-shadow: 0 25px 60px rgba(0,0,0,0.3); overflow: hidden;">
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h3 style="margin: 0; font-size: 1.15rem; color: var(--text-heading);"><iconify-icon icon="solar:megaphone-bold" style="color: var(--primary-teal);"></iconify-icon> Tambah Agenda / Pengumuman</h3>
            <button type="button" onclick="closeModal('addAgendaModal')" style="background: none; border: none; font-size: 1.2rem; color: var(--text-muted); cursor: pointer;">&times;</button>
        </div>
        <form method="POST" style="padding: 24px;">
            <input type="hidden" name="action" value="save_agenda">
            <div class="form-group">
                <label class="form-label">Judul Agenda / Tadarus *</label>
                <input type="text" name="title" class="form-control" placeholder="Contoh: Jadwal Tadarus QS. Ar-Rahman & Piket Kelas" required>
            </div>
            <div class="form-group">
                <label class="form-label">Isi Pengumuman / Target Tadarus *</label>
                <textarea name="content" class="form-control" style="min-height: 120px;" placeholder="Tuliskan ayat yang akan dibaca bersama, pembagian kelompok, atau informasi penting untuk kelas..." required></textarea>
            </div>
            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addAgendaModal')">Batal</button>
                <button type="submit" class="btn btn-primary"><iconify-icon icon="solar:plain-bold"></iconify-icon> Kirim ke Kelas</button>
            </div>
        </form>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
