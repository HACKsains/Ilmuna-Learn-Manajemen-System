<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DASHBOARD DEWAN GURU
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole(['guru', 'admin']);
$activeRole = hasRole('guru') ? 'guru' : 'admin';
$GLOBALS['activeRole'] = $activeRole;

$pageTitle = 'Dashboard Guru';
$pdo = getDBConnection();
$currentUser = getCurrentUser();

// Lookup data guru
$currentGuru = null;
$idGuruLogged = 0;
try {
    $gStmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
    $gStmt->execute([$currentUser['id']]);
    $currentGuru = $gStmt->fetch();
    if ($currentGuru) {
        $idGuruLogged = (int)$currentGuru['id'];
    }
} catch (Exception $e) { }

// Query statistik guru
$totalAsesmenGuru = 0;
$totalPesertaUjian = 0;
$totalHadirUjian   = 0;
$totalMapelDiampu  = 0;

try {
    if ($idGuruLogged > 0) {
        $stmtStat = $pdo->prepare("SELECT COUNT(*) FROM asesmen_sumatif WHERE id_guru = ?");
        $stmtStat->execute([$idGuruLogged]);
        $totalAsesmenGuru = (int)$stmtStat->fetchColumn();

        $stmtMapel = $pdo->prepare("SELECT COUNT(*) FROM guru_mata_pelajaran WHERE id_guru = ?");
        $stmtMapel->execute([$idGuruLogged]);
        $totalMapelDiampu = (int)$stmtMapel->fetchColumn();

        $stmtPeserta = $pdo->prepare("
            SELECT COUNT(DISTINCT aas.id) as total_p, COUNT(DISTINCT abs.id) as total_h
            FROM asesmen_sumatif asum
            JOIN akses_asesmen_sumatif aas ON aas.id_asesmen_sumatif = asum.id
            LEFT JOIN absensi_asesmen_sumatif abs ON abs.id_asesmen_sumatif = asum.id
            WHERE asum.id_guru = ?
        ");
        $stmtPeserta->execute([$idGuruLogged]);
        $statRow = $stmtPeserta->fetch();
        if ($statRow) {
            $totalPesertaUjian = (int)$statRow['total_p'];
            $totalHadirUjian   = (int)$statRow['total_h'];
        }
    } else {
        $totalAsesmenGuru = (int)$pdo->query("SELECT COUNT(*) FROM asesmen_sumatif WHERE status = 'Aktif'")->fetchColumn();
        $totalMapelDiampu = (int)$pdo->query("SELECT COUNT(*) FROM mata_pelajaran")->fetchColumn();
        $totalPesertaUjian = (int)$pdo->query("SELECT COUNT(*) FROM akses_asesmen_sumatif")->fetchColumn();
        $totalHadirUjian   = (int)$pdo->query("SELECT COUNT(*) FROM absensi_asesmen_sumatif")->fetchColumn();
    }
} catch (Exception $e) { }

// Asesmen terkini / hari ini
$todayAsesmen = [];
try {
    $whereGuru = ($idGuruLogged > 0) ? " AND (asum.id_guru = {$idGuruLogged} OR kmp.id_guru_mata_pelajaran IN (SELECT id FROM guru_mata_pelajaran WHERE id_guru = {$idGuruLogged}))" : "";
    $stmtToday = $pdo->query("
        SELECT asum.id, asum.judul_asesmen, asum.jenis_sumatif,
               asum.tanggal_pelaksanaan, asum.jam_mulai, asum.jam_selesai,
               asum.durasi_menit, asum.status,
               mp.nama_mata_pelajaran, mp.kode_mapel,
               k.nama_kelas,
               COUNT(DISTINCT aas.id) AS total_peserta,
               COUNT(DISTINCT absensi.id) AS total_hadir
        FROM asesmen_sumatif asum
        JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        JOIN kelas k ON kmp.id_kelas = k.id
        LEFT JOIN akses_asesmen_sumatif aas ON aas.id_asesmen_sumatif = asum.id
        LEFT JOIN absensi_asesmen_sumatif absensi ON absensi.id_asesmen_sumatif = asum.id
        WHERE asum.status IN ('Aktif', 'Draft') {$whereGuru}
        GROUP BY asum.id, asum.judul_asesmen, asum.jenis_sumatif,
                 asum.tanggal_pelaksanaan, asum.jam_mulai, asum.jam_selesai,
                 asum.durasi_menit, asum.status,
                 mp.nama_mata_pelajaran, mp.kode_mapel, k.nama_kelas
        ORDER BY asum.tanggal_pelaksanaan DESC, asum.jam_mulai DESC
        LIMIT 6
    ");
    $todayAsesmen = $stmtToday->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }

require_once BASE_PATH . '/includes/header.php';
?>

<!-- Data-First Compact Toolbar & Inline KPI Metrics (Zero Hero Banner) -->
<div class="ilmuna-filter-box">
    <div class="ilmuna-filter-topline">
        <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--teal">
            <iconify-icon icon="solar:document-text-bold"></iconify-icon>
            Asesmen: <strong><?= (int)$totalAsesmenGuru ?></strong>
        </span>
        <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--emerald">
            <iconify-icon icon="solar:user-check-bold"></iconify-icon>
            Hadir: <strong><?= (int)$totalHadirUjian ?></strong>
        </span>
        <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--navy">
            <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
            Peserta: <strong><?= (int)$totalPesertaUjian ?></strong>
        </span>
        <span class="ilmuna-nav-stat-inline">
            <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
            Mapel: <strong><?= (int)$totalMapelDiampu ?></strong>
        </span>

        <div class="ilmuna-filter-actions">
            <a href="<?= BASE_URL ?>/dashboard/guru/absensi-sumatif" class="ilmuna-nav-btn ilmuna-nav-btn--primary">
                <iconify-icon icon="solar:qr-code-bold"></iconify-icon>
                <span>Scan Presensi Sumatif</span>
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/assessment-access" class="ilmuna-nav-btn ilmuna-nav-btn--teal">
                <iconify-icon icon="solar:key-bold"></iconify-icon>
                <span>Kredensial CBT</span>
            </a>
        </div>
    </div>
    <div class="ilmuna-filter-bottomline" style="display: flex; flex-wrap: wrap;">
        <a href="<?= BASE_URL ?>/dashboard/admin/assessments" class="ilmuna-nav-btn ilmuna-nav-btn--ghost">
            <iconify-icon icon="solar:document-text-bold"></iconify-icon>
            <span>Bank Asesmen</span>
        </a>
        <a href="<?= BASE_URL ?>/dashboard/admin/assessment-questions" class="ilmuna-nav-btn ilmuna-nav-btn--ghost">
            <iconify-icon icon="solar:clipboard-list-bold"></iconify-icon>
            <span>Butir Soal</span>
        </a>
        <a href="<?= BASE_URL ?>/dashboard/admin/assessment-results" class="ilmuna-nav-btn ilmuna-nav-btn--ghost">
            <iconify-icon icon="solar:chart-2-bold"></iconify-icon>
            <span>Rekap Nilai</span>
        </a>
        <a href="<?= BASE_URL ?>/dashboard/guru/materi" class="ilmuna-nav-btn ilmuna-nav-btn--ghost">
            <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
            <span>Modul Ajar</span>
        </a>
    </div>
</div>

<!-- Daftar Asesmen Sumatif Aktif -->
<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <iconify-icon icon="solar:clipboard-list-bold" style="color: var(--lumina-teal);"></iconify-icon>
            Asesmen Sumatif Aktif &amp; Jadwal Pelaksanaan
        </h3>
        <a href="<?= BASE_URL ?>/dashboard/guru/absensi-sumatif" class="ilmuna-nav-btn ilmuna-nav-btn--teal" style="height: 36px; font-size: 12.5px;">
            <iconify-icon icon="solar:arrow-right-up-linear"></iconify-icon>
            <span>Semua Presensi</span>
        </a>
    </div>

    <div class="content-card-body">
        <?php if (empty($todayAsesmen)): ?>
            <div style="text-align: center; padding: 44px 20px; color: #94A3B8;">
                <iconify-icon icon="solar:folder-with-files-bold-duotone" style="font-size: 2.5rem; margin-bottom: 10px; display: block; margin-inline: auto;"></iconify-icon>
                <p style="margin: 0; font-size: 0.88rem;">Belum ada asesmen sumatif yang dijadwalkan.</p>
            </div>
        <?php else: ?>
            <div class="ilm-grid-auto-lg">
                <?php foreach ($todayAsesmen as $a):
                    $total = (int)$a['total_peserta'];
                    $hadir = (int)$a['total_hadir'];
                    $pct = $total > 0 ? round(($hadir / $total) * 100) : 0;
                ?>
                    <div style="background: #F8FAFC; border: 1.5px solid #E2E8F0; border-radius: 14px; padding: 16px; display: flex; flex-direction: column; justify-content: space-between; min-width: 0;">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 8px;">
                                <span style="font-size: 0.72rem; font-weight: 800; color: var(--lumina-teal); text-transform: uppercase; letter-spacing: 0.04em;">
                                    <?= htmlspecialchars($a['kode_mapel']) ?> — <?= htmlspecialchars($a['nama_kelas']) ?>
                                </span>
                                <span class="ilmuna-badge ilmuna-badge--success">
                                    <?= htmlspecialchars($a['status']) ?>
                                </span>
                            </div>
                            <h3 style="font-family: var(--font-heading); font-size: 0.98rem; font-weight: 800; color: var(--lumina-navy); margin: 0 0 10px; line-height: 1.35;">
                                <?= htmlspecialchars($a['judul_asesmen']) ?>
                            </h3>
                            <div style="display: flex; flex-wrap: wrap; gap: 6px; font-size: 0.74rem; color: #475569; margin-bottom: 14px;">
                                <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 7px; background: #E2E8F0; font-weight: 600;">
                                    <iconify-icon icon="solar:calendar-date-bold" style="color: #64748B;"></iconify-icon> <?= htmlspecialchars($a['tanggal_pelaksanaan'] ?: '—') ?>
                                </span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 7px; background: #E2E8F0; font-weight: 600;">
                                    <iconify-icon icon="solar:clock-circle-bold" style="color: #64748B;"></iconify-icon> <?= htmlspecialchars($a['jam_mulai']) ?> - <?= htmlspecialchars($a['jam_selesai']) ?>
                                </span>
                                <span style="display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 7px; background: #E2E8F0; font-weight: 600;">
                                    <iconify-icon icon="solar:hourglass-line-bold" style="color: #64748B;"></iconify-icon> <?= htmlspecialchars($a['durasi_menit']) ?> mnt
                                </span>
                            </div>
                        </div>

                        <div>
                            <div style="display: flex; justify-content: space-between; font-size: 0.74rem; color: #64748B; margin-bottom: 5px; font-weight: 600;">
                                <span>Kehadiran Peserta</span>
                                <span><?= $hadir ?> / <?= $total ?> (<?= $pct ?>%)</span>
                            </div>
                            <div style="height: 6px; background: #E2E8F0; border-radius: 999px; overflow: hidden; margin-bottom: 14px;">
                                <div style="height: 100%; width: <?= $pct ?>%; background: linear-gradient(90deg, #0D7A87, #52B2DC); border-radius: 999px;"></div>
                            </div>
                            <a href="<?= BASE_URL ?>/dashboard/guru/absensi-sumatif" class="ilmuna-nav-btn ilmuna-nav-btn--teal" style="width: 100%; height: 42px;">
                                <iconify-icon icon="solar:qr-code-bold"></iconify-icon>
                                <span>Buka Presensi Asesmen</span>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
