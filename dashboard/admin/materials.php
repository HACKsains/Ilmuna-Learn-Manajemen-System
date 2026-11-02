<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PAI LEARNING MODULES (ADMIN / GURU PAI)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole(['admin', 'guru']);

// Redirect ke sistem materi baru terintegrasi Google Drive
header('Location: ' . BASE_URL . '/dashboard/guru/materi');
exit;
$pdo = getDBConnection();
$currentUser = getCurrentUser();
$message = null;
$error = null;

// Handle Add Material
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_material') {
    $title = trim($_POST['title'] ?? '');
    $category = $_POST['category'] ?? 'Akidah Akhlak';
    $summary = trim($_POST['summary'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $youtubeUrl = trim($_POST['youtube_url'] ?? '');
    $classId = !empty($_POST['class_id']) ? (int)$_POST['class_id'] : null;

    if (empty($title) || empty($content)) {
        $error = 'Judul materi dan isi materi wajib diisi.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO materials (title, category, summary, content, youtube_url, class_id, author_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'published')
            ");
            $stmt->execute([$title, $category, $summary, $content, $youtubeUrl, $classId, $currentUser['id']]);
            $message = 'Modul materi PAI baru berhasil diterbitkan!';
        } catch (Exception $e) {
            $error = 'Gagal menyimpan materi: ' . $e->getMessage();
        }
    }
}

// Handle Delete Material
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_material') {
    $delId = (int)$_POST['material_id'];
    $pdo->prepare("DELETE FROM materials WHERE id = ?")->execute([$delId]);
    $message = 'Materi berhasil dihapus.';
}

$classes = $pdo->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();
$materials = $pdo->query("
    SELECT m.*, u.full_name as author_name, c.name as class_name
    FROM materials m
    LEFT JOIN users u ON m.author_id = u.id
    LEFT JOIN classes c ON m.class_id = c.id
    ORDER BY m.id DESC
")->fetchAll();

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible"><iconify-icon icon="solar:check-circle-bold"></iconify-icon> <?= $message ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible"><iconify-icon icon="solar:danger-triangle-bold"></iconify-icon> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <iconify-icon icon="solar:book-bookmark-bold" style="color: var(--primary-teal);"></iconify-icon> Modul Pembelajaran Pendidikan Agama Islam
        </h3>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addMaterialModal')">
            <iconify-icon icon="solar:add-circle-bold"></iconify-icon> Tambah Materi Baru
        </button>
    </div>
    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Judul Materi</th>
                        <th>Kategori Mapel PAI</th>
                        <th>Sasaran Kelas</th>
                        <th>Tanggal Terbit</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($materials)): ?>
                        <tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 32px;">Belum ada materi pembelajaran yang diterbitkan.</td></tr>
                    <?php else: ?>
                        <?php foreach ($materials as $m): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($m['title']) ?></strong>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); max-width: 400px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                        <?= htmlspecialchars($m['summary'] ?: strip_tags($m['content'])) ?>
                                    </div>
                                </td>
                                <td><span class="badge badge-teal"><?= htmlspecialchars($m['category']) ?></span></td>
                                <td><?= !empty($m['class_name']) ? '<span class="badge badge-sky">' . htmlspecialchars($m['class_name']) . '</span>' : '<span class="badge badge-navy">Semua Kelas</span>' ?></td>
                                <td><?= date('d M Y', strtotime($m['created_at'])) ?></td>
                                <td style="text-align: right;">
                                    <form method="POST" style="display: inline;" data-confirm="Apakah Anda yakin ingin menghapus modul materi &quot;<?= htmlspecialchars($m['title'], ENT_QUOTES) ?>&quot;? Data materi yang telah dihapus tidak dapat dipulihkan." data-confirm-title="Hapus Modul Pembelajaran" data-confirm-btn="Ya, Hapus Materi" data-confirm-type="danger">
                                        <input type="hidden" name="action" value="delete_material">
                                        <input type="hidden" name="material_id" value="<?= $m['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Hapus Modul Pembelajaran"><iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Material Modal -->
<div class="ilm-modal-backdrop" id="addMaterialModal">
    <div class="ilm-modal-dialog" style="max-width: 650px;">
        <div class="ilm-modal-header">
            <div class="ilm-modal-header__brand">
                <div class="ilm-modal-header__icon">
                    <iconify-icon icon="solar:document-add-bold"></iconify-icon>
                </div>
                <div>
                    <h3 class="ilm-modal-header__title">Terbitkan Materi Pembelajaran PAI</h3>
                    <p class="ilm-modal-header__subtitle">Lengkapi formulir materi baru untuk peserta didik</p>
                </div>
            </div>
            <button type="button" class="ilm-modal-header__close" onclick="closeIlmunaModal('addMaterialModal')" title="Tutup">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
            </button>
        </div>

        <form method="POST">
            <div class="ilm-modal-body" style="max-height: calc(88vh - 160px);">
                <input type="hidden" name="action" value="add_material">

                <div class="form-group">
                    <label class="form-label">Judul Materi PAI *</label>
                    <input type="text" name="title" class="form-control" placeholder="Contoh: Kajian Tafsir QS. Al-Hujurat Ayat 10-13" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Kategori Sub-Mata Pelajaran *</label>
                        <select name="category" class="form-control">
                            <option value="Al-Qur'an & Hadits">Al-Qur'an & Hadits</option>
                            <option value="Akidah Akhlak">Akidah Akhlak</option>
                            <option value="Fiqih">Fiqih Ibadah & Muamalah</option>
                            <option value="Sejarah Kebudayaan Islam">Sejarah Kebudayaan Islam (SKI)</option>
                            <option value="Akhlak Terpuji">Akhlak & Budi Pekerti</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Target Kelas</label>
                        <select name="class_id" class="form-control">
                            <option value="">Semua Kelas (Umum)</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Ringkasan Materi (Singkat)</label>
                    <input type="text" name="summary" class="form-control" placeholder="Ringkasan poin penting pembelajaran...">
                </div>

                <div class="form-group">
                    <label class="form-label">Tautan Video Kajian / YouTube (Opsional)</label>
                    <input type="url" name="youtube_url" class="form-control" placeholder="https://www.youtube.com/watch?v=...">
                </div>

                <div class="form-group">
                    <label class="form-label">Isi / Uraian Materi Pembelajaran *</label>
                    <textarea name="content" class="form-control" style="min-height: 180px;" placeholder="Tuliskan materi pembelajaran, ayat Al-Qur'an beserta artinya, rukun/syarat, atau penjelasan fikih..." required></textarea>
                </div>
            </div>

            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" onclick="closeIlmunaModal('addMaterialModal')">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                    <span>Batal</span>
                </button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:plain-bold"></iconify-icon>
                    <span>Publikasikan Materi</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
