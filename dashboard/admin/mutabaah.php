<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — STUDENT WORSHIP MONITORING / MUTABA'AH (ADMIN / GURU PAI)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('admin');

$pageTitle = 'Monitoring Mutaba\'ah Ibadah Siswa';
$pdo = getDBConnection();

// Fetch Recent Mutabaah Logs
$logs = $pdo->query("
    SELECT m.*, u.full_name as student_name, c.name as class_name
    FROM mutabaah_logs m
    JOIN users u ON m.student_id = u.id
    LEFT JOIN class_members cm ON u.id = cm.user_id
    LEFT JOIN classes c ON cm.class_id = c.id
    ORDER BY m.log_date DESC, m.id DESC
    LIMIT 30
")->fetchAll();

require_once BASE_PATH . '/includes/header.php';
?>

<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <iconify-icon icon="solar:hand-heart-bold" style="color: var(--primary-teal);"></iconify-icon> Rekap Catatan Ibadah Yaumiyah Peserta Didik
        </h3>
    </div>
    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Siswa & Kelas</th>
                        <th>Tanggal Ibadah</th>
                        <th>Sholat 5 Waktu</th>
                        <th>Ibadah Sunnah</th>
                        <th>Tilawah Al-Qur'an</th>
                        <th>Catatan Siswa</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 32px;">Belum ada checklist mutaba'ah ibadah dari siswa untuk hari ini.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($l['student_name']) ?></strong>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);"><?= htmlspecialchars($l['class_name'] ?? 'Umum') ?></div>
                                </td>
                                <td><?= date('d M Y', strtotime($l['log_date'])) ?></td>
                                <td>
                                    <div style="display: flex; gap: 4px; font-size: 0.75rem; font-weight: 600;">
                                        <span class="badge <?= $l['subuh'] ? 'badge-success' : 'badge-navy' ?>" title="Subuh">Sub</span>
                                        <span class="badge <?= $l['dzuhur'] ? 'badge-success' : 'badge-navy' ?>" title="Dzuhur">Dzu</span>
                                        <span class="badge <?= $l['ashar'] ? 'badge-success' : 'badge-navy' ?>" title="Ashar">Ash</span>
                                        <span class="badge <?= $l['maghrib'] ? 'badge-success' : 'badge-navy' ?>" title="Maghrib">Mag</span>
                                        <span class="badge <?= $l['isya'] ? 'badge-success' : 'badge-navy' ?>" title="Isya">Isy</span>
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 4px; font-size: 0.75rem;">
                                        <?= $l['dhuha'] ? '<span class="badge badge-gold">Dhuha</span>' : '' ?>
                                        <?= $l['tahajud'] ? '<span class="badge badge-sky">Tahajud</span>' : '' ?>
                                        <?= $l['puasa_sunnah'] ? '<span class="badge badge-teal">Puasa</span>' : '' ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($l['tilawah_surah'])): ?>
                                        <span style="font-weight: 600; color: var(--primary-teal);"><iconify-icon icon="solar:book-bookmark-bold"></iconify-icon> <?= htmlspecialchars($l['tilawah_surah']) ?></span>
                                        <span style="font-size: 0.75rem; color: var(--text-muted);">Ayat <?= htmlspecialchars($l['tilawah_ayat']) ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted); font-size: 0.8rem;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size: 0.82rem; color: var(--text-muted);"><?= htmlspecialchars($l['catatan'] ?? '-') ?></span>
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
