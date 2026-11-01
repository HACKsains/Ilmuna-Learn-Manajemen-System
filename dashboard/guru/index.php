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

<!-- Hero Banner Dewan Guru -->
<div class="welcome-hero" style="background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%); border-radius: 20px; padding: 28px 32px; color: #fff; margin-bottom: 24px; box-shadow: 0 8px 24px rgba(29,59,94,.12);">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="font-size: 0.82rem; letter-spacing: 0.08em; text-transform: uppercase; color: #52B2DC; font-weight: 700; margin-bottom: 4px;">
                <i class="fas fa-chalkboard-teacher"></i> Portal Dewan Guru &amp; Pengawas CBT
            </div>
            <h1 style="font-family: var(--font-h); font-size: 1.8rem; font-weight: 900; margin: 0 0 6px; line-height: 1.2;">
                Selamat Bertugas, <?= htmlspecialchars($currentGuru['nama_guru'] ?? $currentUser['full_name'] ?? 'Ustadz/Ustadzah') ?>!
            </h1>
            <p style="margin: 0; font-size: 0.88rem; color: rgba(255,255,255,.85);">
                Kelola presensi asesmen sumatif tepat waktu, bank butir soal, kuis CBT, dan evaluasi hasil belajar siswa.
            </p>
        </div>
        <div>
            <a href="<?= BASE_URL ?>/dashboard/guru/absensi-sumatif" class="btn btn-gold" style="padding: 12px 24px; font-weight: 800; font-family: var(--font-h); font-size: 0.95rem; border-radius: 12px; box-shadow: 0 6px 18px rgba(245,158,11,.35); text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                <i class="fas fa-qrcode"></i> Buka Presensi Sumatif
            </a>
        </div>
    </div>
</div>

<!-- Quick Action Shortcuts -->
<div class="content-card" style="margin-bottom: 24px;">
    <div class="content-card-body" style="padding: 16px 20px;">
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <a href="<?= BASE_URL ?>/dashboard/guru/absensi-sumatif" class="btn btn-primary btn-sm" style="background: linear-gradient(135deg, #1D3B5E, #0D7A87);">
                <i class="fas fa-scanner"></i> Absensi Sumatif (Scan QR)
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/assessment-access" class="btn btn-secondary btn-sm">
                <i class="fas fa-key"></i> Kredensial Ujian (CBT)
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/assessments" class="btn btn-secondary btn-sm">
                <i class="fas fa-file-alt"></i> Bank &amp; Master Asesmen
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/assessment-questions" class="btn btn-secondary btn-sm">
                <i class="fas fa-clipboard-list"></i> Butir Soal
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/assessment-results" class="btn btn-secondary btn-sm">
                <i class="fas fa-chart-line"></i> Rekap Nilai Ujian
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/materials" class="btn btn-secondary btn-sm">
                <i class="fas fa-book-open"></i> Modul Ajar
            </a>
        </div>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="stat-card" style="background: #fff; border-radius: 16px; padding: 18px 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 4px 16px rgba(29,59,94,.06); border: 1px solid rgba(29,59,94,.06);">
        <div class="stat-icon" style="width: 48px; height: 48px; border-radius: 12px; background: rgba(13,122,135,.12); color: #0D7A87; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
            <i class="fas fa-file-signature"></i>
        </div>
        <div>
            <div class="stat-number" style="font-family: var(--font-h); font-size: 1.6rem; font-weight: 900; color: #1D3B5E;"><?= $totalAsesmenGuru ?></div>
            <div class="stat-label" style="font-size: 0.72rem; color: #64748B; font-weight: 700; text-transform: uppercase;">Asesmen Aktif</div>
        </div>
    </div>

    <div class="stat-card" style="background: #fff; border-radius: 16px; padding: 18px 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 4px 16px rgba(29,59,94,.06); border: 1px solid rgba(29,59,94,.06);">
        <div class="stat-icon" style="width: 48px; height: 48px; border-radius: 12px; background: rgba(16,185,129,.12); color: #059669; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
            <i class="fas fa-user-check"></i>
        </div>
        <div>
            <div class="stat-number" style="font-family: var(--font-h); font-size: 1.6rem; font-weight: 900; color: #059669;"><?= $totalHadirUjian ?></div>
            <div class="stat-label" style="font-size: 0.72rem; color: #64748B; font-weight: 700; text-transform: uppercase;">Siswa Hadir Absen</div>
        </div>
    </div>

    <div class="stat-card" style="background: #fff; border-radius: 16px; padding: 18px 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 4px 16px rgba(29,59,94,.06); border: 1px solid rgba(29,59,94,.06);">
        <div class="stat-icon" style="width: 48px; height: 48px; border-radius: 12px; background: rgba(82,178,220,.12); color: #0284C7; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
            <i class="fas fa-users"></i>
        </div>
        <div>
            <div class="stat-number" style="font-family: var(--font-h); font-size: 1.6rem; font-weight: 900; color: #1D3B5E;"><?= $totalPesertaUjian ?></div>
            <div class="stat-label" style="font-size: 0.72rem; color: #64748B; font-weight: 700; text-transform: uppercase;">Total Peserta Asesmen</div>
        </div>
    </div>

    <div class="stat-card" style="background: #fff; border-radius: 16px; padding: 18px 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 4px 16px rgba(29,59,94,.06); border: 1px solid rgba(29,59,94,.06);">
        <div class="stat-icon" style="width: 48px; height: 48px; border-radius: 12px; background: rgba(245,158,11,.12); color: #D97706; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
            <i class="fas fa-book-reader"></i>
        </div>
        <div>
            <div class="stat-number" style="font-family: var(--font-h); font-size: 1.6rem; font-weight: 900; color: #1D3B5E;"><?= $totalMapelDiampu ?></div>
            <div class="stat-label" style="font-size: 0.72rem; color: #64748B; font-weight: 700; text-transform: uppercase;">Mapel Diampu</div>
        </div>
    </div>
</div>

<!-- Daftar Asesmen Sumatif Aktif -->
<div class="content-card" style="background: #fff; border-radius: 20px; padding: 24px; box-shadow: 0 4px 20px rgba(29,59,94,.06); border: 1px solid rgba(29,59,94,.08);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h2 style="font-family: var(--font-h); font-size: 1.2rem; font-weight: 800; color: #1D3B5E; margin: 0 0 2px;">
                <i class="fas fa-clipboard-list" style="color: #0D7A87;"></i> Asesmen Sumatif Aktif &amp; Jadwal Pelaksanaan
            </h2>
            <p style="margin: 0; font-size: 0.78rem; color: #64748B;">Klik tombol "Buka Presensi" untuk melakukan absensi barcode / QR scanner di kelas.</p>
        </div>
        <a href="<?= BASE_URL ?>/dashboard/guru/absensi-sumatif" class="btn btn-primary btn-sm" style="text-decoration: none;">
            <i class="fas fa-external-link-alt"></i> Semua Presensi
        </a>
    </div>

    <?php if (empty($todayAsesmen)): ?>
        <div style="text-align: center; padding: 48px 24px; color: #94A3B8;">
            <i class="fas fa-folder-open" style="font-size: 2.5rem; margin-bottom: 12px; display: block;"></i>
            <p style="margin: 0; font-size: 0.88rem;">Belum ada asesmen sumatif yang dijadwalkan.</p>
        </div>
    <?php else: ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px;">
            <?php foreach ($todayAsesmen as $a): 
                $total = (int)$a['total_peserta'];
                $hadir = (int)$a['total_hadir'];
                $pct = $total > 0 ? round(($hadir / $total) * 100) : 0;
            ?>
                <div style="background: #F8FAFC; border: 1.5px solid #E2E8F0; border-radius: 14px; padding: 18px; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 0.7rem; font-weight: 800; color: #0D7A87; text-transform: uppercase; letter-spacing: 0.05em;">
                                <?= htmlspecialchars($a['kode_mapel']) ?> — <?= htmlspecialchars($a['nama_kelas']) ?>
                            </span>
                            <span style="font-size: 0.65rem; font-weight: 700; padding: 2px 8px; border-radius: 6px; background: rgba(16,185,129,.12); color: #059669;">
                                <?= htmlspecialchars($a['status']) ?>
                            </span>
                        </div>
                        <h3 style="font-family: var(--font-h); font-size: 0.98rem; font-weight: 800; color: #1D3B5E; margin: 0 0 10px; line-height: 1.35;">
                            <?= htmlspecialchars($a['judul_asesmen']) ?>
                        </h3>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px; font-size: 0.72rem; color: #475569; margin-bottom: 14px;">
                            <span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; background: #E2E8F0; font-weight: 600;">
                                <i class="fas fa-calendar-day" style="color: #94A3B8;"></i> <?= htmlspecialchars($a['tanggal_pelaksanaan'] ?: '—') ?>
                            </span>
                            <span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; background: #E2E8F0; font-weight: 600;">
                                <i class="fas fa-clock" style="color: #94A3B8;"></i> <?= htmlspecialchars($a['jam_mulai']) ?> - <?= htmlspecialchars($a['jam_selesai']) ?>
                            </span>
                            <span style="display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; background: #E2E8F0; font-weight: 600;">
                                <i class="fas fa-hourglass-half" style="color: #94A3B8;"></i> <?= htmlspecialchars($a['durasi_menit']) ?> mnt
                            </span>
                        </div>
                    </div>

                    <div>
                        <div style="display: flex; justify-content: space-between; font-size: 0.72rem; color: #64748B; margin-bottom: 4px; font-weight: 600;">
                            <span>Kehadiran Peserta</span>
                            <span><?= $hadir ?> / <?= $total ?> (<?= $pct ?>%)</span>
                        </div>
                        <div style="height: 6px; background: #E2E8F0; border-radius: 999px; overflow: hidden; margin-bottom: 14px;">
                            <div style="height: 100%; width: <?= $pct ?>%; background: linear-gradient(90deg, #0D7A87, #52B2DC); border-radius: 999px;"></div>
                        </div>
                        <a href="<?= BASE_URL ?>/dashboard/guru/absensi-sumatif" class="btn btn-primary" style="width: 100%; padding: 8px 12px; font-size: 0.82rem; font-weight: 700; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 6px;">
                            <i class="fas fa-qrcode"></i> Buka Presensi Asesmen
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
