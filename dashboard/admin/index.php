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
?>

<!-- Action Buttons -->
<div class="content-card" style="margin-bottom: 20px;">
    <div class="content-card-body" style="padding: 16px 20px;">
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <a href="<?= BASE_URL ?>/dashboard/admin/assessments.php" class="btn btn-gold btn-sm">
                <i class="fas fa-file-alt"></i> Bank & Master Asesmen
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/assessment-access.php" class="btn btn-primary btn-sm">
                <i class="fas fa-key"></i> Kredensial Ujian (CBT)
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/presensi-master.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-qrcode"></i> Master Presensi QR
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/geofencing.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-crosshairs"></i> Titik Geofencing GPS
            </a>
            <a href="<?= BASE_URL ?>/dashboard/admin/students.php" class="btn btn-secondary btn-sm">
                <i class="fas fa-user-graduate"></i> Data Siswa
            </a>
        </div>
    </div>
</div>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-teal">
            <i class="fas fa-user-graduate"></i>
        </div>
        <div>
            <div class="stat-number"><?= $totalStudents ?></div>
            <div class="stat-label">Siswa Terdaftar</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon stat-icon-navy">
            <i class="fas fa-school"></i>
        </div>
        <div>
            <div class="stat-number"><?= $totalClasses ?></div>
            <div class="stat-label">Rombel Kelas</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon stat-icon-gold">
            <i class="fas fa-key"></i>
        </div>
        <div>
            <div class="stat-number"><?= $totalExamPass ?></div>
            <div class="stat-label">Tiket Akses CBT</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon stat-icon-sky">
            <i class="fas fa-qrcode"></i>
        </div>
        <div>
            <div class="stat-number"><?= $totalPresensi ?></div>
            <div class="stat-label">Master Presensi QR</div>
        </div>
    </div>
</div>

<!-- Quick Access Menu Grid (12 Modul Kurikulum Merdeka) -->
<div class="content-card" style="margin-bottom: 28px;">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <i class="fas fa-grid-2" style="color: var(--primary-teal);"></i> Modul Administrasi Sekolah & SIAKAD Kurikulum Merdeka
        </h3>
        <span style="font-size: 0.8rem; color: var(--text-muted);">12 Modul Inti Terintegrasi</span>
    </div>
    <div class="content-card-body" style="padding: 20px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px;">
            <!-- Bank & Master Asesmen -->
            <a href="<?= BASE_URL ?>/dashboard/admin/assessments.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px; border: 1px solid rgba(13, 122, 135, 0.35);">
                <div class="stat-icon stat-icon-teal" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-file-signature"></i></div>
                <div>
                    <div style="font-weight: 700; color: #0D7A87; font-size: 0.95rem;">Bank & Master Asesmen</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Sumatif, Formatif & Diagnostik</div>
                </div>
            </a>

            <!-- Akses Asesmen CBT -->
            <a href="<?= BASE_URL ?>/dashboard/admin/assessment-access.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px; border: 1px solid rgba(245, 158, 11, 0.3);">
                <div class="stat-icon stat-icon-gold" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-key"></i></div>
                <div>
                    <div style="font-weight: 700; color: #F59E0B; font-size: 0.95rem;">Akses Asesmen (CBT)</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Username & Password Ujian</div>
                </div>
            </a>

            <!-- Master Presensi QR -->
            <a href="<?= BASE_URL ?>/dashboard/admin/presensi-master.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px; border: 1px solid rgba(13, 122, 135, 0.3);">
                <div class="stat-icon stat-icon-teal" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-qrcode"></i></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Master Presensi QR</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Kartu ID Presensi Siswa</div>
                </div>
            </a>

            <!-- Titik Geofencing GPS -->
            <a href="<?= BASE_URL ?>/dashboard/admin/geofencing.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-sky" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-crosshairs"></i></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Titik Geofencing GPS</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Radius Titik Sekolah</div>
                </div>
            </a>

            <!-- Kurikulum & PDP -->
            <a href="<?= BASE_URL ?>/dashboard/admin/curriculum.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-navy" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-graduation-cap"></i></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Kurikulum & PDP</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">CP, TP, ATP, ITP & PROTA</div>
                </div>
            </a>

            <!-- Rekap Rapor & Proyek -->
            <a href="<?= BASE_URL ?>/dashboard/admin/reports.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-teal" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-file-invoice"></i></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Rekap Rapor & P5</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Nilai Akhir & Proyek P5</div>
                </div>
            </a>

            <!-- Data Kelas -->
            <a href="<?= BASE_URL ?>/dashboard/admin/classes.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-teal" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-chalkboard"></i></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Data Kelas</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Rombel & Tingkat</div>
                </div>
            </a>

            <!-- Data Siswa -->
            <a href="<?= BASE_URL ?>/dashboard/admin/students.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-sky" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-user-graduate"></i></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Data Siswa</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Peserta Didik</div>
                </div>
            </a>

            <!-- User -->
            <a href="<?= BASE_URL ?>/dashboard/admin/users.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-navy" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-users-gear"></i></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Manajemen User</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Multi-Role RBAC</div>
                </div>
            </a>

            <!-- Mata Pelajaran -->
            <a href="<?= BASE_URL ?>/dashboard/admin/subjects.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-sky" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-book-bookmark"></i></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Mata Pelajaran</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Plotting Kelas Mapel</div>
                </div>
            </a>

            <!-- Jadwal -->
            <a href="<?= BASE_URL ?>/dashboard/admin/schedules.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-gold" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-clock"></i></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Jadwal Pelajaran</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Waktu & Ruangan KBM</div>
                </div>
            </a>

            <!-- Tugas -->
            <a href="<?= BASE_URL ?>/dashboard/admin/assignments.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-gold" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-clipboard-list"></i></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Tugas & Portofolio</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Penilaian Berbasis ITP</div>
                </div>
            </a>

            <!-- Monitoring Absensi -->
            <a href="<?= BASE_URL ?>/dashboard/admin/attendance.php" class="stat-card" style="margin-bottom: 0; text-decoration: none; padding: 16px 18px;">
                <div class="stat-icon stat-icon-teal" style="width: 44px; height: 44px; font-size: 1.15rem;"><i class="fas fa-calendar-check"></i></div>
                <div>
                    <div style="font-weight: 700; color: var(--text-heading); font-size: 0.95rem;">Monitoring Absensi</div>
                    <div style="font-size: 0.75rem; color: var(--text-muted);">Log GPS & Perizinan</div>
                </div>
            </a>
        </div>
    </div>
</div>

<!-- Main Row: Recent Submissions & Class List -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 24px;">
    <!-- Recent Submissions Card -->
    <div class="content-card">
        <div class="content-card-header">
            <h3 class="content-card-title">
                <i class="fas fa-clock-rotate-left" style="color: var(--primary-teal);"></i> Tugas Siswa Terbaru
            </h3>
            <a href="<?= BASE_URL ?>/dashboard/admin/assignments.php" class="btn btn-secondary btn-sm">
                Semua Tugas <i class="fas fa-arrow-right"></i>
            </a>
        </div>
        <div class="content-card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="lms-table">
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
                            <tr>
                                <td colspan="4" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                    Belum ada pengumpulan tugas dari siswa.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentSubmissions as $sub): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($sub['student_name']) ?></strong>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">
                                            <?= date('d M Y, H:i', strtotime($sub['submitted_at'])) ?>
                                        </div>
                                    </td>
                                    <td><?= htmlspecialchars($sub['assignment_title']) ?></td>
                                    <td><span class="badge badge-teal"><?= htmlspecialchars($sub['class_name'] ?? 'Umum') ?></span></td>
                                    <td>
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
    <div class="content-card">
        <div class="content-card-header">
            <h3 class="content-card-title">
                <i class="fas fa-chalkboard" style="color: var(--primary-sky);"></i> Rombel & Kelas PAI
            </h3>
            <a href="<?= BASE_URL ?>/dashboard/admin/classes.php" class="btn btn-secondary btn-sm">
                Kelola Kelas <i class="fas fa-gear"></i>
            </a>
        </div>
        <div class="content-card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="lms-table">
                    <thead>
                        <tr>
                            <th>Nama Rombel</th>
                            <th>Ketua Kelas</th>
                            <th>Jumlah Siswa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($classes)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                    Belum ada data kelas yang didaftarkan.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($classes as $c): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($c['name']) ?></strong>
                                        <div style="font-size: 0.75rem; color: var(--text-muted);">Tahun Ajaran <?= htmlspecialchars($c['academic_year']) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!empty($c['ketua_nama'])): ?>
                                            <span class="badge badge-teal"><i class="fas fa-user-tie"></i> <?= htmlspecialchars($c['ketua_nama']) ?></span>
                                        <?php else: ?>
                                            <span style="font-size: 0.8rem; color: var(--text-muted);">Belum ditentukan</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
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