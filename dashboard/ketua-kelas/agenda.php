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
loadCentralTemplates(['modal']);
?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> <?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- Data-First Compact Filter & Action Toolbar -->
<div class="ilmuna-filter-box">
    <div class="ilmuna-filter-topline">
        <div class="ilmuna-search-wrap">
            <iconify-icon icon="solar:magnifer-linear"></iconify-icon>
            <input type="text" id="agendaSearchInput" class="ilmuna-search-input" placeholder="Cari judul atau isi agenda tadarus..." oninput="filterAgendaItems()">
        </div>

        <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--navy">
            <iconify-icon icon="solar:buildings-2-bold"></iconify-icon>
            Kelas: <strong><?= htmlspecialchars($classLed['name'] ?? 'PAI') ?></strong>
        </span>
        <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--teal">
            <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
            Agenda: <strong><?= count($announcements) ?></strong>
        </span>

        <div class="ilmuna-filter-actions">
            <button type="button" class="ilmuna-nav-btn ilmuna-nav-btn--primary" onclick="openIlmunaModal('addAgendaModal')">
                <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                <span>Tambah Agenda</span>
            </button>
        </div>
    </div>
</div>

<div class="content-card">
    <div class="content-card-body">
        <div id="agendaListContainer" style="display: grid; gap: 14px;">
            <?php if (empty($announcements)): ?>
                <div style="text-align: center; color: var(--text-muted); padding: 36px 16px;">
                    <iconify-icon icon="solar:notebook-bold-duotone" style="font-size: 2.4rem; display: block; margin: 0 auto 10px; color: #94A3B8;"></iconify-icon>
                    Belum ada agenda kelas yang dicatat. Buat catatan tadarus atau pengumuman piket kelas untuk teman-teman.
                </div>
            <?php else: ?>
                <?php foreach ($announcements as $ann): ?>
                    <div class="agenda-item-card" data-search="<?= htmlspecialchars(strtolower($ann['title'] . ' ' . $ann['content'] . ' ' . $ann['author_name'])) ?>" style="background: #F8FAFC; border: 1px solid var(--lumina-border); border-radius: 14px; padding: 18px;">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;">
                            <h4 style="margin: 0; font-size: 1.02rem; font-weight: 800; color: var(--lumina-navy);"><?= htmlspecialchars($ann['title']) ?></h4>
                            <span class="ilmuna-badge ilmuna-badge--navy" style="font-size: 11px;">
                                <iconify-icon icon="solar:clock-circle-bold"></iconify-icon>
                                <?= date('d M Y, H:i', strtotime($ann['created_at'])) ?> WIB
                            </span>
                        </div>
                        <p style="font-size: 0.9rem; color: var(--lumina-text-body); margin: 0 0 12px; line-height: 1.6; word-break: break-word;">
                            <?= nl2br(htmlspecialchars($ann['content'])) ?>
                        </p>
                        <div style="font-size: 0.78rem; color: var(--lumina-teal); font-weight: 700; display: flex; align-items: center; gap: 6px;">
                            <iconify-icon icon="solar:pen-new-square-bold"></iconify-icon>
                            <span>Diumumkan oleh: <?= htmlspecialchars($ann['author_name']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Official Universal Modal: Tambah Agenda -->
<div class="ilm-modal-backdrop" id="addAgendaModal">
    <div class="ilm-modal-dialog" style="max-width: 540px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:megaphone-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title">Tambah Agenda / Pengumuman</h3>
                    <p class="ilm-modal-header__subtitle">Bagikan jadwal tadarus atau pengumuman ke teman sekelas</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('addAgendaModal')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>
        <form method="POST">
            <div class="ilm-modal-body" style="max-height: calc(88vh - 160px);">
                <input type="hidden" name="action" value="save_agenda">
                <div class="form-group">
                    <label class="form-label">Judul Agenda / Tadarus *</label>
                    <input type="text" name="title" class="form-control" placeholder="Contoh: Jadwal Tadarus QS. Ar-Rahman &amp; Piket Kelas" required>
                </div>
                <div class="form-group" style="margin-bottom: 0;">
                    <label class="form-label">Isi Pengumuman / Target Tadarus *</label>
                    <textarea name="content" class="form-control" style="min-height: 120px;" placeholder="Tuliskan ayat yang akan dibaca bersama, pembagian kelompok, atau informasi penting untuk kelas..." required></textarea>
                </div>
            </div>
            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('addAgendaModal')">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                    <span>Batal</span>
                </button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:plain-bold"></iconify-icon>
                    <span>Kirim ke Kelas</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function filterAgendaItems() {
    const q = (document.getElementById('agendaSearchInput')?.value || '').trim().toLowerCase();
    document.querySelectorAll('#agendaListContainer .agenda-item-card').forEach(card => {
        const hay = card.getAttribute('data-search') || '';
        card.style.display = (!q || hay.includes(q)) ? '' : 'none';
    });
}
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
