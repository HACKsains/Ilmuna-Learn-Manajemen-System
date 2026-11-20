<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DASHBOARD ADMIN (GURU MAPEL PAI)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole('admin');

$pageTitle = 'Dashboard Guru & Admin';
$pdo = getDBConnection();
$currentUser = getCurrentUser();

// Fetch real-time statistics from 69-table Kurikulum Merdeka database
$totalStudents = $pdo->query("SELECT COUNT(*) FROM siswa")->fetchColumn();
$totalClasses  = $pdo->query("SELECT COUNT(*) FROM kelas")->fetchColumn();
$totalUsers    = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalExamPass = (int)$pdo->query("SELECT COUNT(*) FROM akses_asesmen_sumatif")->fetchColumn() 
               + (int)$pdo->query("SELECT COUNT(*) FROM akses_asesmen_formatif")->fetchColumn();
$totalPresensi = $pdo->query("SELECT COUNT(*) FROM presensi")->fetchColumn();
$totalGeofence = $pdo->query("SELECT COUNT(*) FROM titik_koordinat_sekolah WHERE is_active = 1")->fetchColumn();
$totalKmp      = $pdo->query("SELECT COUNT(*) FROM kelas_mata_pelajaran")->fetchColumn();
$totalModul    = $pdo->query("SELECT COUNT(*) FROM modul_ajar")->fetchColumn();

// Fetch Recent Submissions
$stmtRecentSub = $pdo->query("
    SELECT s.id, s.score, s.submitted_at, u.full_name as student_name, a.title as assignment_title, c.name as class_name
    FROM submissions s
    JOIN users u ON s.student_id = u.id
    JOIN assignments a ON s.assignment_id = a.id
    LEFT JOIN classes c ON a.class_id = c.id
    ORDER BY s.submitted_at DESC
    LIMIT 5
");
$recentSubmissions = $stmtRecentSub->fetchAll();

// Fetch Classes
$classes = $pdo->query("
    SELECT c.*, u.full_name as ketua_nama, (SELECT COUNT(*) FROM class_members WHERE class_id = c.id) as total_member
    FROM classes c
    LEFT JOIN users u ON c.ketua_kelas_id = u.id
    ORDER BY c.grade_level ASC
")->fetchAll();

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<!-- Data-First Compact Toolbar & Inline KPI Metrics -->
<div class="ilmuna-filter-box">
    <div class="ilmuna-filter-topline">
        <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--teal">
            <iconify-icon icon="solar:user-bold"></iconify-icon>
            Siswa: <strong><?= (int)$totalStudents ?></strong>
        </span>
        <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--navy">
            <iconify-icon icon="solar:buildings-2-bold"></iconify-icon>
            Rombel: <strong><?= (int)$totalClasses ?></strong>
        </span>
        <span class="ilmuna-nav-stat-inline">
            <iconify-icon icon="solar:key-bold"></iconify-icon>
            Tiket CBT: <strong><?= (int)$totalExamPass ?></strong>
        </span>
        <span class="ilmuna-nav-stat-inline ilmuna-nav-stat-inline--emerald">
            <iconify-icon icon="solar:qr-code-bold"></iconify-icon>
            Presensi QR: <strong><?= (int)$totalPresensi ?></strong>
        </span>

        <div class="ilmuna-filter-actions">
            <a href="<?= BASE_URL ?>/dashboard/admin/assessments.php" class="ilmuna-nav-btn ilmuna-nav-btn--primary">
                <iconify-icon icon="solar:document-text-bold"></iconify-icon>
                <span>Bank Asesmen</span>
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/assessment-access.php" class="ilmuna-nav-btn ilmuna-nav-btn--teal">
                <iconify-icon icon="solar:key-bold"></iconify-icon>
                <span>Kredensial CBT</span>
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/presensi-master.php" class="ilmuna-nav-btn ilmuna-nav-btn--ghost">
                <iconify-icon icon="solar:qr-code-bold"></iconify-icon>
                <span>Presensi QR</span>
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/geofencing.php" class="ilmuna-nav-btn ilmuna-nav-btn--ghost">
                <iconify-icon icon="solar:point-on-map-bold"></iconify-icon>
                <span>Geofencing</span>
            </a>
        </div>
    </div>
</div>

<!-- Quick Access Menu Grid (13 Modul Kurikulum Merdeka) -->
<div class="content-card" style="margin-bottom: 22px;">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <iconify-icon icon="solar:widget-5-bold" style="color: var(--primary-teal);"></iconify-icon> Modul Administrasi Sekolah &amp; SIAKAD
        </h3>
        <span class="ilmuna-badge ilmuna-badge--teal">13 Modul Terintegrasi</span>
    </div>
    <div class="content-card-body">
        <div class="ilm-grid-auto-md">
            <!-- Bank & Master Asesmen -->
            <a href="<?= BASE_URL ?>/dashboard/admin/assessments.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px; border: 1px solid rgba(13, 122, 135, 0.35);">
                <div class="stat-icon stat-icon-teal" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:document-text-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: #0D7A87; font-size: 0.95rem;">Bank & Master Asesmen</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Sumatif, Formatif & Diagnostik</div>
                </div>
            </a>

            <!-- Akses Asesmen CBT -->
            <a href="<?= BASE_URL ?>/dashboard/admin/assessment-access.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px; border: 1px solid rgba(245, 158, 11, 0.3);">
                <div class="stat-icon stat-icon-gold" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:key-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: #F59E0B; font-size: 0.95rem;">Akses Asesmen (CBT)</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Username & Password Ujian</div>
                </div>
            </a>

            <!-- Master Presensi QR -->
            <a href="<?= BASE_URL ?>/dashboard/admin/presensi-master.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px; border: 1px solid rgba(13, 122, 135, 0.3);">
                <div class="stat-icon stat-icon-teal" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:qr-code-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Master Presensi QR</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Kartu ID Presensi Siswa</div>
                </div>
            </a>

            <!-- Titik Geofencing GPS -->
            <a href="<?= BASE_URL ?>/dashboard/admin/geofencing.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-sky" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:point-on-map-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Titik Geofencing GPS</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Radius Titik Sekolah</div>
                </div>
            </a>

            <!-- Kurikulum & PDP -->
            <a href="<?= BASE_URL ?>/dashboard/admin/curriculum.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-navy" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:diploma-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Kurikulum & PDP</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">CP, TP, ATP, ITP & PROTA</div>
                </div>
            </a>

            <!-- Rekap Rapor & Proyek -->
            <a href="<?= BASE_URL ?>/dashboard/admin/reports.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-teal" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:bill-list-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Rekap Rapor & P5</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Nilai Akhir & Proyek P5</div>
                </div>
            </a>

            <!-- Data Kelas -->
            <a href="<?= BASE_URL ?>/dashboard/admin/classes.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-teal" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:blackboard-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Data Kelas</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Rombel & Tingkat</div>
                </div>
            </a>

            <!-- Data Siswa -->
            <a href="<?= BASE_URL ?>/dashboard/admin/students.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-sky" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:user-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Data Siswa</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Peserta Didik</div>
                </div>
            </a>

            <!-- User -->
            <a href="<?= BASE_URL ?>/dashboard/admin/users.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-navy" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Manajemen User</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Multi-Role RBAC</div>
                </div>
            </a>

            <!-- Mata Pelajaran -->
            <a href="<?= BASE_URL ?>/dashboard/admin/subjects.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-sky" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:book-bookmark-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Mata Pelajaran</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Plotting Kelas Mapel</div>
                </div>
            </a>

            <!-- Jadwal -->
            <a href="<?= BASE_URL ?>/dashboard/admin/schedules.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-gold" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:clock-circle-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Jadwal Pelajaran</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Waktu & Ruangan KBM</div>
                </div>
            </a>

            <!-- Tugas -->
            <a href="<?= BASE_URL ?>/dashboard/admin/assignments.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-gold" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:clipboard-list-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Tugas & Portofolio</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Penilaian Berbasis ITP</div>
                </div>
            </a>

            <!-- Monitoring Absensi -->
            <a href="<?= BASE_URL ?>/dashboard/admin/attendance.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-teal" style="width: 44px; height: 44px; font-size: 1.15rem;"><iconify-icon icon="solar:calendar-check-bold"></iconify-icon></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Monitoring Absensi</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Log GPS & Perizinan</div>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Main Row: Recent Submissions & Class List -->
<div class="ilm-grid-2">
    <!-- Recent Submissions Card -->
    <div class="content-card" style="margin-bottom: 0;">
        <div class="content-card-header">
            <h3 class="content-card-title">
                <iconify-icon icon="solar:history-bold" style="color: var(--primary-teal);"></iconify-icon> Tugas Siswa Terbaru
            </h3>
            <a href="<?= BASE_URL ?>/dashboard/admin/assignments.php" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" style="height: 34px; font-size: 12px;">
                <span>Semua Tugas</span> <iconify-icon icon="solar:arrow-right-linear"></iconify-icon>
            </a>
        </div>
        <div class="content-card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th>Siswa</th>
                            <th>Tugas</th>
                            <th>Kelas</th>
                            <th>Status Nilai</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSubmissions)): ?>
                            <tr class="ilmuna-empty-row">
                                <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                    Belum ada pengumpulan tugas dari siswa.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentSubmissions as $sub): ?>
                                <tr>
                                    <td data-label="Siswa">
                                        <div>
                                            <strong><?= htmlspecialchars($sub['student_name']) ?></strong>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                <?= date('d M Y, H:i', strtotime($sub['submitted_at'])) ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Tugas"><?= htmlspecialchars($sub['assignment_title']) ?></td>
                                    <td data-label="Kelas"><span class="badge badge-teal"><?= htmlspecialchars($sub['class_name'] ?? 'Umum') ?></span></td>
                                    <td data-label="Status Nilai">
                                        <?php if ($sub['score'] !== null): ?>
                                            <span class="badge badge-success"><?= $sub['score'] ?> / 100</span>
                                        <?php else: ?>
                                            <span class="badge badge-gold">Belum Dinilai</span>
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

    <!-- Active Classes Card -->
    <div class="content-card" style="margin-bottom: 0;">
        <div class="content-card-header">
            <h3 class="content-card-title">
                <iconify-icon icon="solar:blackboard-bold" style="color: var(--primary-sky);"></iconify-icon> Rombel &amp; Kelas PAI
            </h3>
            <a href="<?= BASE_URL ?>/dashboard/admin/classes.php" class="ilmuna-nav-btn ilmuna-nav-btn--ghost" style="height: 34px; font-size: 12px;">
                <span>Kelola Kelas</span> <iconify-icon icon="solar:settings-linear"></iconify-icon>
            </a>
        </div>
        <div class="content-card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="lms-table ilmuna-table-stack">
                    <thead>
                        <tr>
                            <th>Nama Rombel</th>
                            <th>Ketua Kelas</th>
                            <th>Jumlah Siswa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($classes)): ?>
                            <tr class="ilmuna-empty-row">
                                <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                    Belum ada data kelas yang didaftarkan.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($classes as $c): ?>
                                <tr>
                                    <td data-label="Nama Rombel">
                                        <div>
                                            <strong><?= htmlspecialchars($c['name']) ?></strong>
                                            <div style="font-size: 0.75rem; color: var(--text-muted);">Tahun Ajaran <?= htmlspecialchars($c['academic_year']) ?></div>
                                        </div>
                                    </td>
                                    <td data-label="Ketua Kelas">
                                        <?php if (!empty($c['ketua_nama'])): ?>
                                            <span class="badge badge-teal"><iconify-icon icon="solar:user-bold"></iconify-icon> <?= htmlspecialchars($c['ketua_nama']) ?></span>
                                        <?php else: ?>
                                            <span style="font-size: 0.8rem; color: var(--text-muted);">Belum ditentukan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Jumlah Siswa">
                                        <strong><?= $c['total_member'] ?></strong> Siswa
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>