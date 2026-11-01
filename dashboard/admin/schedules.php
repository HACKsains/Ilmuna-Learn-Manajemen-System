<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — JADWAL PELAJARAN & KBM SEKOLAH (ADMIN / GURU PAI)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

requireRole(['admin', 'guru']);

$pageTitle = 'Jadwal Pelajaran & KBM';
$pdo = getDBConnection();
$message = null;
$error = null;

// Fetch classes
$classes = $pdo->query("SELECT id, nama_kelas AS name FROM kelas ORDER BY nama_kelas ASC")->fetchAll();

// Fetch subjects
$subjects = [];
try {
    $subjects = $pdo->query("SELECT id, nama_mata_pelajaran AS nama_mapel FROM mata_pelajaran ORDER BY nama_mata_pelajaran ASC")->fetchAll();
} catch (Exception $e) {}

// Seed default schedules if empty
$countJadwal = (int)$pdo->query("SELECT COUNT(*) FROM jadwal_pelajaran")->fetchColumn();
if ($countJadwal == 0) {
    // Find first kmp
    $firstKmp = $pdo->query("SELECT id FROM kelas_mata_pelajaran LIMIT 1")->fetchColumn();
    if (!$firstKmp) {
        $firstKelas = $pdo->query("SELECT id FROM kelas LIMIT 1")->fetchColumn() ?: 1;
        $firstMapel = $pdo->query("SELECT id FROM mata_pelajaran LIMIT 1")->fetchColumn() ?: 1;
        $pdo->prepare("INSERT INTO kelas_mata_pelajaran (id_kelas, id_mata_pelajaran) VALUES (?, ?)")->execute([$firstKelas, $firstMapel]);
        $firstKmp = $pdo->lastInsertId();
    }
    
    $defaultSchedules = [
        [$firstKmp, 1, 1, 'Senin', '07:30:00', '09:00:00', 2, 'Pendidikan Agama Islam (Tadarus & Akidah)', 'Ruang Kelas', 'Terjadwal'],
        [$firstKmp, 1, 2, 'Selasa', '09:30:00', '11:00:00', 2, 'Al-Qur\'an Hadits & Tajwid Terapan', 'Lab Keagamaan', 'Terjadwal'],
        [$firstKmp, 1, 3, 'Rabu', '07:30:00', '09:00:00', 2, 'Fiqih Ibadah & Praktik Sholat Jenazah', 'Masjid Sekolah', 'Terjadwal'],
        [$firstKmp, 1, 4, 'Kamis', '11:00:00', '12:30:00', 2, 'Sejarah Kebudayaan Islam (SKI Nusantara)', 'Ruang Multimedia', 'Terjadwal'],
        [$firstKmp, 1, 5, 'Jumat', '07:00:00', '08:30:00', 2, 'Khotmil Qur\'an & Pembiasaan Sholat Dhuha', 'Masjid Utama', 'Terjadwal']
    ];
    $stmtIns = $pdo->prepare("
        INSERT INTO jadwal_pelajaran (id_kelas_mata_pelajaran, id_guru, pertemuan_ke, hari, jam_mulai, jam_selesai, alokasi_jp, materi_pembahasan, ruangan, status_jadwal, tahun_pelajaran, semester)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2026/2027', 'Ganjil')
    ");
    foreach ($defaultSchedules as $js) {
        $stmtIns->execute($js);
    }
}

// Handle Add Schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_schedule') {
    $classId = (int)($_POST['class_id'] ?? 0);
    $subjectName = trim($_POST['subject_name'] ?? '');
    $hari = $_POST['hari'] ?? 'Senin';
    $jamKe = trim($_POST['jam_ke'] ?? '1-2');
    $jamMulai = $_POST['jam_mulai'] ?? '07:30';
    $jamSelesai = $_POST['jam_selesai'] ?? '09:00';
    $ruang = trim($_POST['ruang'] ?? 'Ruang Kelas');
    $guruNama = trim($_POST['guru_nama'] ?? 'Guru Pengampu');

    if (empty($classId) || empty($subjectName) || empty($jamMulai)) {
        $error = 'Kelas, mata pelajaran, dan jam mulai wajib diisi.';
    } else {
        try {
            $stmtKmp = $pdo->prepare("SELECT id FROM kelas_mata_pelajaran WHERE id_kelas = ? LIMIT 1");
            $stmtKmp->execute([$classId]);
            $kmpId = $stmtKmp->fetchColumn();
            if (!$kmpId) {
                $stmtInsKmp = $pdo->prepare("INSERT INTO kelas_mata_pelajaran (id_kelas, id_mata_pelajaran) VALUES (?, 1)");
                $stmtInsKmp->execute([$classId]);
                $kmpId = $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("
                INSERT INTO jadwal_pelajaran (id_kelas_mata_pelajaran, id_guru, hari, jam_mulai, jam_selesai, alokasi_jp, materi_pembahasan, ruangan, status_jadwal, tahun_pelajaran, semester)
                VALUES (?, 1, ?, ?, ?, ?, ?, ?, 'Terjadwal', '2026/2027', 'Ganjil')
            ");
            $jpVal = (int)filter_var($jamKe, FILTER_SANITIZE_NUMBER_INT) ?: 2;
            $stmt->execute([$kmpId, $hari, $jamMulai, $jamSelesai, $jpVal, $subjectName, $ruang]);
            $message = 'Jadwal pelajaran baru berhasil ditambahkan!';
        } catch (Exception $e) {
            $error = 'Gagal menyimpan jadwal: ' . $e->getMessage();
        }
    }
}

// Handle Delete Schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_schedule') {
    $delId = (int)$_POST['schedule_id'];
    try {
        $stmt = $pdo->prepare("DELETE FROM jadwal_pelajaran WHERE id = ?");
        $stmt->execute([$delId]);
        $message = 'Jadwal pelajaran berhasil dihapus.';
    } catch (Exception $e) {
        $error = 'Gagal menghapus jadwal: ' . $e->getMessage();
    }
}

// Filter parameters
$selectedClass = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$selectedHari = $_GET['hari'] ?? 'Semua';

// Fetch schedules
$query = "
    SELECT 
        j.id,
        j.id_kelas_mata_pelajaran,
        j.pertemuan_ke,
        j.hari,
        j.jam_mulai,
        j.jam_selesai,
        COALESCE(j.alokasi_jp, 2) as alokasi_jp,
        COALESCE(j.alokasi_jp, '1-2') as jam_ke,
        COALESCE(j.ruangan, 'Ruang Kelas') as ruang,
        COALESCE(mp.nama_mata_pelajaran, j.materi_pembahasan, 'Pendidikan Agama Islam') as subject_name,
        COALESCE(k.nama_kelas, 'Semua Kelas') as class_name,
        COALESCE(u.full_name, 'Ustadz Ahmad Fauzi, S.Pd.I') as guru_nama
    FROM jadwal_pelajaran j
    LEFT JOIN kelas_mata_pelajaran kmp ON j.id_kelas_mata_pelajaran = kmp.id
    LEFT JOIN kelas k ON kmp.id_kelas = k.id
    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    LEFT JOIN users u ON j.id_guru = u.id
    WHERE 1=1
";
$params = [];

if ($selectedClass > 0) {
    $query .= " AND kmp.id_kelas = ?";
    $params[] = $selectedClass;
}

if ($selectedHari !== 'Semua') {
    $query .= " AND j.hari = ?";
    $params[] = $selectedHari;
}

// Custom sort by day
$query .= " ORDER BY FIELD(j.hari, 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'), j.jam_mulai ASC";
$stmtJ = $pdo->prepare($query);
$stmtJ->execute($params);
$schedules = $stmtJ->fetchAll();

$days = ['Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

require_once BASE_PATH . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible"><i class="fas fa-check-circle"></i> <?= $message ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Quick Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon stat-icon-teal"><i class="fas fa-calendar-check"></i></div>
        <div>
            <div class="stat-number"><?= count($schedules) ?></div>
            <div class="stat-label">Sesi Jadwal Aktif</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon-navy"><i class="fas fa-school"></i></div>
        <div>
            <div class="stat-number"><?= count($classes) ?></div>
            <div class="stat-label">Total Rombel Kelas</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon-gold"><i class="fas fa-clock"></i></div>
        <div>
            <div class="stat-number">6 Hari</div>
            <div class="stat-label">KBM Mingguan</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon stat-icon-sky"><i class="fas fa-chalkboard-user"></i></div>
        <div>
            <div class="stat-number">Terjadwal</div>
            <div class="stat-label">Status Sinkronisasi</div>
        </div>
    </div>
</div>

<!-- Day Quick Filter Tabs -->
<div style="display: flex; gap: 8px; margin-bottom: 24px; overflow-x: auto; padding-bottom: 6px;">
    <a href="?class_id=<?= $selectedClass ?>&hari=Semua" class="btn <?= $selectedHari === 'Semua' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
        Semua Hari
    </a>
    <?php foreach ($days as $d): ?>
        <a href="?class_id=<?= $selectedClass ?>&hari=<?= $d ?>" class="btn <?= $selectedHari === $d ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            <?= $d ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Main Card -->
<div class="content-card">
    <div class="content-card-header">
        <h3 class="content-card-title">
            <i class="fas fa-calendar-days" style="color: var(--primary-teal);"></i> Matriks Jadwal Pelajaran Sekolah
        </h3>
        <button type="button" class="btn btn-primary btn-sm" onclick="openModal('addScheduleModal')">
            <i class="fas fa-plus"></i> Tambah Jadwal Baru
        </button>
    </div>

    <!-- Filter by Class Toolbar -->
    <div style="padding: 16px 24px; border-bottom: 1px solid var(--border-subtle); background: #f8fafc; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; justify-content: space-between;">
        <form method="GET" style="display: flex; gap: 10px; flex: 1; max-width: 480px;">
            <input type="hidden" name="hari" value="<?= htmlspecialchars($selectedHari) ?>">
            <select name="class_id" class="form-control" style="max-width: 280px;" onchange="this.form.submit()">
                <option value="0">-- Tampilkan Semua Kelas --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $selectedClass === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <div style="font-size: 0.85rem; color: var(--text-muted);">
            Menampilkan <strong><?= count($schedules) ?></strong> sesi pembelajaran
        </div>
    </div>

    <div class="content-card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="lms-table">
                <thead>
                    <tr>
                        <th>Hari</th>
                        <th>Waktu (Jam)</th>
                        <th>Kelas / Rombel</th>
                        <th>Mata Pelajaran</th>
                        <th>Guru Pengampu</th>
                        <th>Ruangan</th>
                        <th style="text-align: right;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($schedules)): ?>
                        <tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 36px;">Belum ada jadwal pelajaran untuk kriteria yang dipilih.</td></tr>
                    <?php else: ?>
                        <?php foreach ($schedules as $sc): ?>
                            <tr>
                                <td>
                                    <span class="badge badge-navy" style="font-weight: 700;"><?= htmlspecialchars($sc['hari']) ?></span>
                                </td>
                                <td>
                                    <div style="font-weight: 600; color: var(--primary-teal);">
                                        <?= substr($sc['jam_mulai'], 0, 5) ?> - <?= !empty($sc['jam_selesai']) ? substr($sc['jam_selesai'], 0, 5) : 'Selesai' ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--text-muted);">Jam Ke: <?= htmlspecialchars($sc['jam_ke']) ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-teal"><?= htmlspecialchars($sc['class_name'] ?? 'Umum') ?></span>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($sc['subject_name']) ?></strong>
                                </td>
                                <td>
                                    <div style="font-size: 0.85rem; color: var(--text-body);"><i class="fas fa-user-tie" style="color: var(--primary-teal); margin-right: 4px;"></i> <?= htmlspecialchars($sc['guru_nama']) ?></div>
                                </td>
                                <td>
                                    <span class="badge badge-sky"><i class="fas fa-door-open"></i> <?= htmlspecialchars($sc['ruang']) ?></span>
                                </td>
                                <td style="text-align: right;">
                                    <form method="POST" style="display: inline;" data-confirm="Apakah Anda yakin ingin menghapus jadwal &quot;<?= htmlspecialchars($sc['subject_name'], ENT_QUOTES) ?>&quot; hari <?= htmlspecialchars($sc['hari']) ?>?" data-confirm-title="Hapus Jadwal KBM" data-confirm-btn="Ya, Hapus Jadwal" data-confirm-type="danger">
                                        <input type="hidden" name="action" value="delete_schedule">
                                        <input type="hidden" name="schedule_id" value="<?= $sc['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" title="Hapus Jadwal"><i class="fas fa-trash-can"></i></button>
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

<!-- Modal Tambah Jadwal -->
<div id="addScheduleModal" style="display: none; position: fixed; inset: 0; background: rgba(8, 16, 26, 0.7); backdrop-filter: blur(8px); z-index: 999; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #ffffff; width: 100%; max-width: 580px; border-radius: 20px; box-shadow: 0 25px 60px rgba(0,0,0,0.3); overflow: hidden;">
        <div style="padding: 20px 24px; border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center; background: #f8fafc;">
            <h3 style="margin: 0; font-size: 1.15rem; color: var(--text-heading);"><i class="fas fa-calendar-plus" style="color: var(--primary-teal);"></i> Buat Jadwal Pelajaran Baru</h3>
            <button type="button" onclick="closeModal('addScheduleModal')" style="background: none; border: none; font-size: 1.2rem; color: var(--text-muted); cursor: pointer;">&times;</button>
        </div>
        <form method="POST" style="padding: 24px;">
            <input type="hidden" name="action" value="add_schedule">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Rombel / Kelas *</label>
                    <select name="class_id" class="form-control" required>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Hari Pembelajaran *</label>
                    <select name="hari" class="form-control" required>
                        <?php foreach ($days as $d): ?>
                            <option value="<?= $d ?>"><?= $d ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Mata Pelajaran *</label>
                <?php if (!empty($subjects)): ?>
                    <select name="subject_name" class="form-control" required>
                        <?php foreach ($subjects as $sb): ?>
                            <option value="<?= htmlspecialchars($sb['nama_mapel']) ?>">
                                [<?= htmlspecialchars($sb['kode_mapel']) ?>] <?= htmlspecialchars($sb['nama_mapel']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="text" name="subject_name" class="form-control" placeholder="Contoh: Pendidikan Agama Islam & Budi Pekerti" required>
                <?php endif; ?>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px;">
                <div class="form-group">
                    <label class="form-label">Jam Ke</label>
                    <input type="text" name="jam_ke" class="form-control" placeholder="1-2" value="1-2">
                </div>
                <div class="form-group">
                    <label class="form-label">Jam Mulai *</label>
                    <input type="time" name="jam_mulai" class="form-control" value="07:30" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Jam Selesai *</label>
                    <input type="time" name="jam_selesai" class="form-control" value="09:00" required>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label class="form-label">Ruang Kelas / Tempat</label>
                    <input type="text" name="ruang" class="form-control" placeholder="Contoh: Ruang Kelas / Lab / Masjid" value="Ruang Kelas">
                </div>
                <div class="form-group">
                    <label class="form-label">Guru Pengampu</label>
                    <input type="text" name="guru_nama" class="form-control" placeholder="Nama Guru" value="Ustadz Ahmad Fauzi, S.Pd.I">
                </div>
            </div>

            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal('addScheduleModal')">Batal</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Simpan Jadwal</button>
            </div>
        </form>
    </div>
</div>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
