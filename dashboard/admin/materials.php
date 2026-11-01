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
?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible"><i class="fas fa-check-circle"></i> <?= $message ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <i class="fas fa-book-quran" style="color: var(--primary-teal);"></i> Modul Pembelajaran Pendidikan Agama Islam
        </h3>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addMaterialModal')">
            <i class="fas fa-plus"></i> Tambah Materi Baru
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
                                        <button type="submit" class="btn btn-danger btn-sm" title="Hapus Modul Pembelajaran"><i class="fas fa-trash-can"></i></button>
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
<div id="addMaterialModal" style="display: none; position: fixed; inset: 0; background: rgba(8, 16, 26, 0.7); backdrop-filter: blur(8px); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #ffffff; width: 100%; max-width: 650px; border-radius: 20px; box-shadow: 0 25px 60px rgba(0,0,0,0.3); overflow: hidden; max-height: 90vh; display: flex; flex-direction: column;">
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h3 style="margin: 0; font-size: 1.2rem; color: var(--text-heading); display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-file-circle-plus" style="color: var(--primary-teal);"></i> Terbitkan Materi Pembelajaran PAI
            </h3>
            <button type="button" onclick="closeModal('addMaterialModal')" style="background: none; border: none; font-size: 1.2rem; color: var(--text-muted); cursor: pointer;">&times;</button>
        </div>

        <form method="POST" style="padding: 24px; overflow-y: auto; flex: 1;">
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

            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 16px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addMaterialModal')">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Publikasikan Materi</button>
            </div>
        </form>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
