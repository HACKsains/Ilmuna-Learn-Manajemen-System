<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — APPROVAL PERIZINAN SISWA OLEH KETUA KELAS (DUAL-APPROVAL TAHAP 1)
 * ==============================================================================
 * Desain & Tema: Lumina Gold & Emerald (Satu tema dengan dashboard/siswa/absensi.php)
 *   - Palette: Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Emerald #10B981, Gold #F59E0B, Rose #F43F5E
 *   - Terintegrasi penuh dengan Base Template Header, Sidebar & Footer
 * 
 * Arsitektur Database (Mengikuti skema-tabel-kelas.sql):
 *   - Master Presensi Siswa (`presensi`)
 *   - Transaksi Absensi Kehadiran (`absensi`)
 *   - Alur Dual-Approval Perizinan (`perizinan`):
 *       Siswa Ajukan Izin/Sakit -> Disetujui Ketua Kelas (Tahap 1)
 *       -> Disetujui Guru Pengampu (Tahap 2) -> Sinkron Otomatis ke Absensi (Status I/S)
 *   - Peran Ketua Kelas: Khusus memverifikasi & menyetujui perizinan rekan sekelas
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

// Hak Akses Ketat: Khusus role Ketua Kelas
requireRole('ketua_kelas');

// Kunci konteks role aktif tab ini ke ketua_kelas
$activeRole = 'ketua_kelas';
$GLOBALS['activeRole'] = 'ketua_kelas';

$pdo = getDBConnection();
$currentUser = getCurrentUser();
$userRoles = getUserRoles();

// ------------------------------------------------------------------------------
// RESOLUSI DATA IDENTITAS KETUA KELAS & ROMBEL KELAS
// ------------------------------------------------------------------------------
$currentSiswa = null;
$stmtS = $pdo->prepare("
    SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
    FROM siswa s 
    LEFT JOIN kelas k ON s.id_kelas = k.id 
    WHERE s.nis = ? OR s.nisn = ? OR s.gmail = ? OR s.gmail = ? OR s.nama_lengkap = ?
    LIMIT 1
");
$stmtS->execute([
    $currentUser['username'] ?? '',
    $currentUser['nisn_nip'] ?? '',
    $currentUser['email'] ?? '',
    $currentUser['email_google'] ?? '',
    $currentUser['full_name'] ?? ''
]);
$currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);

// Penentuan Rombel Kelas yang Dipimpin / Dipilih
$selectedClassId = isset($_GET['kelas_id']) ? (int)$_GET['kelas_id'] : 0;
if (!$selectedClassId) {
    if ($currentSiswa && !empty($currentSiswa['id_kelas'])) {
        $selectedClassId = (int)$currentSiswa['id_kelas'];
    } else {
        // Fallback cerdas: cari kelas yang memiliki pengajuan izin atau ambil kelas pertama
        $firstClassWithIzin = $pdo->query("SELECT s.id_kelas FROM perizinan pz JOIN siswa s ON pz.id_siswa = s.id LIMIT 1")->fetchColumn();
        if ($firstClassWithIzin) {
            $selectedClassId = (int)$firstClassWithIzin;
        } else {
            $selectedClassId = (int)$pdo->query("SELECT id FROM kelas ORDER BY id ASC LIMIT 1")->fetchColumn();
        }
    }
}

// Data Detail Kelas Terpilih
$stmtClass = $pdo->prepare("SELECT * FROM kelas WHERE id = ? LIMIT 1");
$stmtClass->execute([$selectedClassId]);
$currentClass = $stmtClass->fetch(PDO::FETCH_ASSOC);

// Daftar Seluruh Kelas (untuk filter multi-kelas oleh admin/guru)
$allClasses = $pdo->query("SELECT id, nama_kelas, kode_kelas, tahun_pelajaran FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);

// Helper URL Bukti Dokumen / Foto
function resolveBuktiUrl($path) {
    if (empty($path)) return '';
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        return $path;
    }
    if (strpos($path, '/') === 0) {
        return BASE_URL . $path;
    }
    return BASE_URL . '/' . ltrim($path, '/');
}

// Helper URL Foto Profil Siswa
function resolveFotoSiswa($path) {
    if (empty($path)) return '';
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        return $path;
    }
    if (strpos($path, '/') === 0) {
        return BASE_URL . $path;
    }
    return BASE_URL . '/uploads/siswa/' . ltrim($path, '/');
}

// Helper Inisial Nama
function getInitials($name) {
    $parts = preg_split('/\s+/', trim((string)$name));
    $ini = '';
    if (!empty($parts)) {
        $ini .= mb_substr($parts[0], 0, 1);
        if (isset($parts[1])) $ini .= mb_substr($parts[1], 0, 1);
    }
    return mb_strtoupper($ini ?: mb_substr((string)$name, 0, 2));
}

// ==============================================================================
// HANDLER AJAX BACKEND (LIVE API PERIZINAN KETUA KELAS)
// ==============================================================================
if (isset($_GET['action']) || isset($_POST['action'])) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    header('Content-Type: application/json; charset=utf-8');

    // 1. AJAX: HITUNG JUMLAH STATUS PERIZINAN (LIVE STATS & TABS)
    if ($action === 'get_counts' || $action === 'dosen_ketua_izin_counts') {
        $classFilter = $selectedClassId > 0 ? "AND s.id_kelas = :id_kelas" : "";
        $sql = "
            SELECT 
                COUNT(*) AS total_all,
                SUM(CASE WHEN pz.approval_ketua_kelas = 'Pending' THEN 1 ELSE 0 END) AS total_pending,
                SUM(CASE WHEN pz.approval_ketua_kelas = 'Approved' AND pz.approval_guru = 'Pending' THEN 1 ELSE 0 END) AS total_progress,
                SUM(CASE WHEN pz.status_perizinan = 'Disetujui' OR pz.approval_guru = 'Approved' THEN 1 ELSE 0 END) AS total_approved,
                SUM(CASE WHEN pz.approval_ketua_kelas = 'Rejected' OR pz.approval_guru = 'Rejected' OR pz.status_perizinan = 'Ditolak' THEN 1 ELSE 0 END) AS total_rejected
            FROM perizinan pz
            INNER JOIN siswa s ON pz.id_siswa = s.id
            WHERE 1=1 {$classFilter}
        ";
        $stmtCnt = $pdo->prepare($sql);
        if ($selectedClassId > 0) {
            $stmtCnt->bindValue(':id_kelas', $selectedClassId, PDO::PARAM_INT);
        }
        $stmtCnt->execute();
        $row = $stmtCnt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'all'      => (int)($row['total_all'] ?? 0),
                'pending'  => (int)($row['total_pending'] ?? 0),
                'progress' => (int)($row['total_progress'] ?? 0),
                'approved' => (int)($row['total_approved'] ?? 0),
                'rejected' => (int)($row['total_rejected'] ?? 0),
            ]
        ]);
        exit;
    }

    // 2. AJAX: DAFTAR PERIZINAN SISWA DENGAN FILTER & PENCARIAN
    if ($action === 'get_list' || $action === 'dosen_ketua_izin_list') {
        $status = trim($_POST['status'] ?? $_GET['status'] ?? 'all');
        $search = trim($_POST['search'] ?? $_GET['search'] ?? '');
        $page   = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
        $per    = max(5, min(100, (int)($_POST['per'] ?? $_GET['per'] ?? 15)));
        $offset = ($page - 1) * $per;

        $where = "WHERE 1=1";
        $params = [];

        if ($selectedClassId > 0) {
            $where .= " AND s.id_kelas = :id_kelas";
            $params[':id_kelas'] = $selectedClassId;
        }

        switch ($status) {
            case 'pending':
                $where .= " AND pz.approval_ketua_kelas = 'Pending'";
                break;
            case 'progress':
                $where .= " AND pz.approval_ketua_kelas = 'Approved' AND pz.approval_guru = 'Pending'";
                break;
            case 'approved':
                $where .= " AND (pz.status_perizinan = 'Disetujui' OR pz.approval_guru = 'Approved')";
                break;
            case 'rejected':
                $where .= " AND (pz.approval_ketua_kelas = 'Rejected' OR pz.approval_guru = 'Rejected' OR pz.status_perizinan = 'Ditolak')";
                break;
            case 'all':
            default:
                break;
        }

        if ($search !== '') {
            $where .= " AND (s.nama_lengkap LIKE :q OR s.nis LIKE :q OR s.nisn LIKE :q OR pz.alasan LIKE :q OR mp.nama_mata_pelajaran LIKE :q)";
            $params[':q'] = '%' . $search . '%';
        }

        // Hitung total baris
        $countSql = "
            SELECT COUNT(*) 
            FROM perizinan pz
            INNER JOIN siswa s ON pz.id_siswa = s.id
            LEFT JOIN kelas_mata_pelajaran kmp ON pz.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            {$where}
        ";
        $stmtTotal = $pdo->prepare($countSql);
        foreach ($params as $k => $v) {
            $stmtTotal->bindValue($k, $v);
        }
        $stmtTotal->execute();
        $totalRows = (int)$stmtTotal->fetchColumn();

        // Ambil data perizinan berelasi sesuai skema-tabel-kelas.sql
        $dataSql = "
            SELECT 
                pz.*,
                s.nama_lengkap AS nama_siswa, s.nis, s.nisn, s.foto AS foto_siswa,
                k.nama_kelas,
                mp.nama_mata_pelajaran, mp.kode_mapel,
                jp.pertemuan_ke AS jadwal_pertemuan_ke, jp.tanggal AS tgl_pertemuan,
                jp.jam_mulai, jp.jam_selesai,
                uk.full_name AS nama_ketua, uk.username AS uname_ketua,
                ug.full_name AS nama_guru, ug.username AS uname_guru
            FROM perizinan pz
            INNER JOIN siswa s ON pz.id_siswa = s.id
            LEFT JOIN kelas k ON s.id_kelas = k.id
            LEFT JOIN kelas_mata_pelajaran kmp ON pz.id_kelas_mata_pelajaran = kmp.id
            LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN jadwal_pelajaran jp ON pz.id_jadwal = jp.id
            LEFT JOIN users uk ON pz.id_ketua_kelas = uk.id
            LEFT JOIN users ug ON pz.id_guru = ug.id
            {$where}
            ORDER BY 
                CASE 
                    WHEN pz.approval_ketua_kelas = 'Pending' THEN 1 
                    WHEN pz.approval_ketua_kelas = 'Approved' AND pz.approval_guru = 'Pending' THEN 2
                    WHEN pz.status_perizinan = 'Disetujui' THEN 3
                    ELSE 4 
                END,
                pz.created_at DESC
            LIMIT :lim OFFSET :off
        ";
        $stmtData = $pdo->prepare($dataSql);
        foreach ($params as $k => $v) {
            $stmtData->bindValue($k, $v);
        }
        $stmtData->bindValue(':lim', $per, PDO::PARAM_INT);
        $stmtData->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmtData->execute();
        $rows = $stmtData->fetchAll(PDO::FETCH_ASSOC);

        ob_start();
        if (empty($rows)):
        ?>
            <div class="kkiz-empty">
                <div class="kkiz-empty-icon">
                    <iconify-icon icon="solar:inbox-in-bold" width="56"></iconify-icon>
                </div>
                <h4 class="kkiz-empty-title">Tidak Ada Data Perizinan</h4>
                <p class="kkiz-empty-desc">
                    <?= $search ? 'Tidak ada permohonan yang cocok dengan kata kunci "' . htmlspecialchars($search) . '".' : 'Belum ada permohonan izin atau sakit pada kategori ini.' ?>
                </p>
            </div>
        <?php
        else:
            foreach ($rows as $iz):
                $isPending = ($iz['approval_ketua_kelas'] === 'Pending');
                $isKetuaApproved = ($iz['approval_ketua_kelas'] === 'Approved');
                $isKetuaRejected = ($iz['approval_ketua_kelas'] === 'Rejected');

                // Resolusi Status Badge
                if ($isPending) {
                    $statusCls = 'pending';
                    $statusLbl = 'Menunggu Ketua';
                    $statusIco = 'solar:hourglass-bold';
                } elseif ($isKetuaRejected) {
                    $statusCls = 'rejected';
                    $statusLbl = 'Ditolak Ketua';
                    $statusIco = 'solar:close-circle-bold';
                } elseif ($iz['approval_guru'] === 'Pending') {
                    $statusCls = 'progress';
                    $statusLbl = 'Menunggu Guru';
                    $statusIco = 'solar:refresh-circle-bold';
                } elseif ($iz['status_perizinan'] === 'Disetujui' || $iz['approval_guru'] === 'Approved') {
                    $statusCls = 'approved';
                    $statusLbl = 'Disetujui Guru';
                    $statusIco = 'solar:check-circle-bold';
                } else {
                    $statusCls = 'rejected';
                    $statusLbl = 'Ditolak';
                    $statusIco = 'solar:close-circle-bold';
                }

                $namaSiswa = $iz['nama_siswa'] ?: 'Siswa';
                $initials  = getInitials($namaSiswa);
                $fotoUrl   = resolveFotoSiswa($iz['foto_siswa']);
                $buktiUrl  = resolveBuktiUrl($iz['bukti_perizinan']);
                $tglIzin   = !empty($iz['tanggal_izin']) ? date('d M Y', strtotime($iz['tanggal_izin'])) : '-';
                $tglKirim  = !empty($iz['created_at']) ? date('d M Y H:i', strtotime($iz['created_at'])) : '-';
                $tglKetua  = !empty($iz['waktu_approval_ketua']) ? date('d M Y H:i', strtotime($iz['waktu_approval_ketua'])) : '';
                $namaKetua = $iz['nama_ketua'] ?: ($iz['uname_ketua'] ?: 'Ketua Kelas');
                $lat       = !empty($iz['latitude']) ? (float)$iz['latitude'] : '';
                $lng       = !empty($iz['longitude']) ? (float)$iz['longitude'] : '';
                $isSakit   = ($iz['jenis_izin'] === 'Sakit');
                $pertemuanNum = (int)($iz['pertemuan_ke'] ?: ($iz['jadwal_pertemuan_ke'] ?: 1));
        ?>
                <div class="kkiz-item" data-id="<?= (int)$iz['id'] ?>" data-status="<?= htmlspecialchars($iz['approval_ketua_kelas']) ?>">
                    <!-- HEADER KARTU SISWA -->
                    <div class="kkiz-item__user">
                        <div class="kkiz-item__avatar">
                            <?php if (!empty($fotoUrl)): ?>
                                <img src="<?= htmlspecialchars($fotoUrl) ?>" alt="<?= htmlspecialchars($namaSiswa) ?>" loading="lazy" onerror="this.style.display='none'; this.parentNode.innerHTML='<span class=\'kkiz-item__avatar__initials\'><?= htmlspecialchars($initials) ?></span>';">
                            <?php else: ?>
                                <span class="kkiz-item__avatar__initials"><?= htmlspecialchars($initials) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="kkiz-item__user-body">
                            <div class="kkiz-item__title">
                                <strong><?= htmlspecialchars($namaSiswa) ?></strong>
                                <?php if (!empty($iz['nis'])): ?>
                                    <span class="kkiz-item__nim">NIS: <?= htmlspecialchars($iz['nis']) ?></span>
                                <?php elseif (!empty($iz['nisn'])): ?>
                                    <span class="kkiz-item__nim">NISN: <?= htmlspecialchars($iz['nisn']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="kkiz-item__meta">
                                <span><iconify-icon icon="solar:notebook-bold"></iconify-icon> <?= htmlspecialchars($iz['nama_mata_pelajaran'] ?: 'Pendidikan Agama Islam') ?></span>
                                <span><iconify-icon icon="solar:calendar-bold"></iconify-icon> Pertemuan <?= $pertemuanNum ?></span>
                                <span><iconify-icon icon="solar:clock-circle-bold"></iconify-icon> Diajukan <?= htmlspecialchars($tglKirim) ?></span>
                            </div>
                        </div>

                        <span class="kkiz-pill <?= $statusCls ?>">
                            <iconify-icon icon="<?= $statusIco ?>"></iconify-icon> <?= $statusLbl ?>
                        </span>
                    </div>

                    <!-- KONTEN RINCIAN PERIZINAN -->
                    <div class="kkiz-item__content">
                        <div class="kkiz-item__type-box <?= $isSakit ? 'sakit' : 'izin' ?>">
                            <div class="kkiz-item__type-icon">
                                <iconify-icon icon="<?= $isSakit ? 'solar:health-bold' : 'solar:calendar-date-bold' ?>" width="18"></iconify-icon>
                            </div>
                            <div>
                                <div class="kkiz-item__type-title"><?= $isSakit ? 'Surat Sakit' : 'Permohonan Izin' ?></div>
                                <div class="kkiz-item__type-date">Berlaku: <?= htmlspecialchars($tglIzin) ?></div>
                            </div>
                        </div>

                        <div class="kkiz-alasan">
                            <span class="kkiz-alasan-label">Alasan:</span>
                            <span class="kkiz-alasan-text">“<?= nl2br(htmlspecialchars($iz['alasan'])) ?>”</span>
                        </div>

                        <?php if (!empty($iz['alamat_izin']) || (!empty($lat) && !empty($lng))): ?>
                            <div class="kkiz-location">
                                <iconify-icon icon="solar:map-point-bold"></iconify-icon>
                                <span><?= htmlspecialchars($iz['alamat_izin'] ?: ($lat . ', ' . $lng)) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- TOMBOL AKSI & AUDIT TRAIL -->
                    <div class="kkiz-actions">
                        <?php if (!empty($buktiUrl)): ?>
                            <button type="button" class="kkiz-btn kkiz-btn--ghost" data-act="view-evidence"
                                data-foto="<?= htmlspecialchars($buktiUrl) ?>"
                                data-nama="<?= htmlspecialchars($namaSiswa) ?>"
                                data-nim="<?= htmlspecialchars($iz['nis'] ?: ($iz['nisn'] ?: '-')) ?>"
                                data-avatar="<?= htmlspecialchars($fotoUrl) ?>"
                                data-initials="<?= htmlspecialchars($initials) ?>"
                                data-lat="<?= htmlspecialchars((string)$lat) ?>"
                                data-lng="<?= htmlspecialchars((string)$lng) ?>"
                                data-alamat="<?= htmlspecialchars($iz['alamat_izin'] ?: '') ?>"
                                data-time="<?= htmlspecialchars($tglKirim) ?>"
                                data-mapel="<?= htmlspecialchars($iz['nama_mata_pelajaran'] ?: 'PAI') ?> (P<?= $pertemuanNum ?>)">
                                <iconify-icon icon="solar:gallery-bold"></iconify-icon>
                                Bukti Surat / Foto
                            </button>
                        <?php endif; ?>

                        <?php if ($isPending): ?>
                            <button type="button" class="kkiz-btn kkiz-btn--approve" data-act="approve"
                                data-id="<?= (int)$iz['id'] ?>"
                                data-nama="<?= htmlspecialchars($namaSiswa) ?>">
                                <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                                Setujui Izin
                            </button>
                            <button type="button" class="kkiz-btn kkiz-btn--reject" data-act="reject"
                                data-id="<?= (int)$iz['id'] ?>"
                                data-nama="<?= htmlspecialchars($namaSiswa) ?>">
                                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                                Tolak
                            </button>
                        <?php else: ?>
                            <div class="kkiz-handled">
                                <?php if ($isKetuaApproved): ?>
                                    <div class="kkiz-handled-item success">
                                        <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                                        <span>Disetujui Ketua Kelas (<?= htmlspecialchars($namaKetua) ?>) <?= $tglKetua ? '• ' . htmlspecialchars($tglKetua) : '' ?></span>
                                    </div>
                                    <?php if (!empty($iz['catatan_ketua_kelas']) && $iz['catatan_ketua_kelas'] !== 'Disetujui oleh Ketua Kelas'): ?>
                                        <div class="kkiz-handled-note">Catatan: <?= htmlspecialchars($iz['catatan_ketua_kelas']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="kkiz-handled-item danger">
                                        <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                                        <span>Ditolak Ketua Kelas (<?= htmlspecialchars($namaKetua) ?>) <?= $tglKetua ? '• ' . htmlspecialchars($tglKetua) : '' ?></span>
                                    </div>
                                    <?php if (!empty($iz['catatan_ketua_kelas'])): ?>
                                        <div class="kkiz-handled-note">Alasan: <?= htmlspecialchars($iz['catatan_ketua_kelas']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if ($iz['approval_guru'] === 'Approved'): ?>
                                    <div class="kkiz-handled-sub approved">
                                        <iconify-icon icon="solar:verified-check-bold"></iconify-icon> Telah disahkan Guru Pengampu & otomatis sinkron ke Presensi KBM.
                                    </div>
                                <?php elseif ($iz['approval_guru'] === 'Rejected'): ?>
                                    <div class="kkiz-handled-sub rejected">
                                        <iconify-icon icon="solar:close-circle-bold"></iconify-icon> Ditolak oleh Guru Pengampu.
                                    </div>
                                <?php elseif ($isKetuaApproved): ?>
                                    <div class="kkiz-handled-sub pending">
                                        <iconify-icon icon="solar:hourglass-bold"></iconify-icon> Menunggu verifikasi akhir Guru Pengampu.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
        <?php
            endforeach;
        endif;

        $html = ob_get_clean();
        echo json_encode([
            'success'   => true,
            'list_html' => $html,
            'total'     => $totalRows,
            'page'      => $page,
            'per'       => $per,
            'pages'     => max(1, (int)ceil($totalRows / $per)),
        ]);
        exit;
    }

    // 3. AJAX: APPROVAL / PENOLAKAN PERIZINAN OLEH KETUA KELAS (DUAL-APPROVAL TAHAP 1)
    if ($action === 'dosen_ketua_izin_action' || $action === 'approval_ketua_kelas') {
        $idIzin   = (int)($_POST['id_izin'] ?? 0);
        $decision = trim($_POST['decision'] ?? $_POST['keputusan'] ?? '');
        $reason   = trim($_POST['reason'] ?? $_POST['catatan'] ?? '');

        if (!$idIzin || !in_array($decision, ['approve', 'reject'], true)) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Parameter keputusan tidak lengkap atau tidak valid.']]);
            exit;
        }

        $stmtFind = $pdo->prepare("SELECT pz.*, s.nama_lengkap FROM perizinan pz JOIN siswa s ON pz.id_siswa = s.id WHERE pz.id = ? LIMIT 1");
        $stmtFind->execute([$idIzin]);
        $rowIzin = $stmtFind->fetch(PDO::FETCH_ASSOC);

        if (!$rowIzin) {
            echo json_encode(['success' => false, 'data' => ['message' => 'Data perizinan tidak ditemukan di database.']]);
            exit;
        }

        if ($decision === 'approve') {
            $catatanText = !empty($reason) ? $reason : 'Disetujui oleh Ketua Kelas';
            $stmtUp = $pdo->prepare("
                UPDATE perizinan 
                SET approval_ketua_kelas = 'Approved',
                    status_perizinan = 'Menunggu Persetujuan Guru',
                    id_ketua_kelas = ?,
                    waktu_approval_ketua = NOW(),
                    catatan_ketua_kelas = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUp->execute([$currentUser['id'], $catatanText, $idIzin]);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => 'Alhamdulillah! Izin atas nama ' . htmlspecialchars($rowIzin['nama_lengkap']) . ' disetujui Ketua Kelas dan diteruskan ke Guru Pengampu.'
                ]
            ]);
            exit;
        } else {
            $catatanText = !empty($reason) ? $reason : 'Ditolak oleh Ketua Kelas';
            $stmtUp = $pdo->prepare("
                UPDATE perizinan 
                SET approval_ketua_kelas = 'Rejected',
                    status_perizinan = 'Ditolak',
                    id_ketua_kelas = ?,
                    waktu_approval_ketua = NOW(),
                    catatan_ketua_kelas = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmtUp->execute([$currentUser['id'], $catatanText, $idIzin]);

            echo json_encode([
                'success' => true,
                'data' => [
                    'message' => 'Pengajuan izin atas nama ' . htmlspecialchars($rowIzin['nama_lengkap']) . ' telah ditolak oleh Ketua Kelas.'
                ]
            ]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
    exit;
}

// ------------------------------------------------------------------------------
// RENDER VIEW HALAMAN KETUA KELAS
// ------------------------------------------------------------------------------
$pageTitle = 'Approval Perizinan Siswa';
require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<style>
/* ==============================================================================
 * LUMINA GOLD & EMERALD — STYLE APPROVAL PERIZINAN KETUA KELAS
 * Serasi dengan dashboard/siswa/absensi.php & base template Ilmuna Learn
 * ============================================================================== */
.kkiz-wrap {
    padding: 6px 0 40px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: var(--lumina-text-body, #334155);
}

/* BANNER HEADER KETUA KELAS */
.kkiz-banner {
    background: linear-gradient(135deg, #0D7A87 0%, #1D3B5E 100%);
    border-radius: 18px;
    padding: 24px 28px;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
    margin-bottom: 22px;
    box-shadow: 0 10px 30px -8px rgba(13, 122, 135, 0.35);
}
.kkiz-banner::after {
    content: "";
    position: absolute;
    top: -40px; right: -40px;
    width: 220px; height: 220px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(82, 178, 220, 0.25) 0%, transparent 70%);
    pointer-events: none;
}
.kkiz-banner__arabic {
    font-size: 15px;
    color: #F59E0B;
    font-weight: 700;
    margin-bottom: 6px;
    letter-spacing: 0.5px;
}
.kkiz-banner__title {
    font-family: 'Outfit', sans-serif;
    font-size: 22px;
    font-weight: 800;
    color: #FFFFFF;
    margin: 0 0 6px;
    letter-spacing: -0.3px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.kkiz-banner__desc {
    font-size: 13.5px;
    color: rgba(255, 255, 255, 0.88);
    margin: 0 0 16px;
    max-width: 720px;
    line-height: 1.55;
}
.kkiz-banner__pills {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.kkiz-banner__pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.25);
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    color: #FFFFFF;
    backdrop-filter: blur(8px);
}
.kkiz-banner__pill iconify-icon {
    color: #52B2DC;
}

/* STATS COUNTER GRID */
.kkiz-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
}
.kkiz-stat-card {
    background: #FFFFFF;
    border: 1.5px solid var(--lumina-border, #E2E8F0);
    border-radius: 14px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    box-shadow: 0 4px 16px -6px rgba(29, 59, 94, 0.06);
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
    overflow: hidden;
}
.kkiz-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px -6px rgba(29, 59, 94, 0.10);
    border-color: #CBD5E1;
}
.kkiz-stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.kkiz-stat-icon.all { background: rgba(29, 59, 94, 0.08); color: #1D3B5E; }
.kkiz-stat-icon.pending { background: #FEF3C7; color: #D97706; }
.kkiz-stat-icon.progress { background: #E0F2FE; color: #0284C7; }
.kkiz-stat-icon.approved { background: #ECFDF5; color: #059669; }
.kkiz-stat-icon.rejected { background: #FFF1F2; color: #E11D48; }

.kkiz-stat-num {
    font-family: 'Outfit', sans-serif;
    font-size: 24px;
    font-weight: 800;
    line-height: 1.1;
    color: var(--lumina-text-title, #0F1D2F);
}
.kkiz-stat-lbl {
    font-size: 11.5px;
    font-weight: 700;
    color: var(--lumina-text-muted, #64748B);
    text-transform: uppercase;
    letter-spacing: 0.4px;
    margin-top: 3px;
}

/* TOOLBAR & FILTER TABS */
.kkiz-toolbar {
    background: #FFFFFF;
    border: 1.5px solid var(--lumina-border, #E2E8F0);
    border-radius: 16px;
    padding: 14px 18px;
    margin-bottom: 20px;
    box-shadow: 0 4px 18px -6px rgba(29, 59, 94, 0.05);
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.kkiz-tabs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    scrollbar-width: none;
    padding-bottom: 2px;
}
.kkiz-tabs::-webkit-scrollbar { display: none; }

.kkiz-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 16px;
    background: #F8FAFC;
    border: 1.5px solid var(--lumina-border, #E2E8F0);
    border-radius: 10px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.kkiz-tab-btn:hover {
    background: #FFFFFF;
    border-color: #0D7A87;
    color: #0D7A87;
}
.kkiz-tab-btn.active {
    background: linear-gradient(135deg, #0D7A87, #1D3B5E);
    border-color: transparent;
    color: #FFFFFF;
    box-shadow: 0 4px 14px -3px rgba(13, 122, 135, 0.45);
}
.kkiz-tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    background: #E2E8F0;
    color: #334155;
    transition: all 0.2s;
}
.kkiz-tab-btn.active .kkiz-tab-badge {
    background: rgba(255, 255, 255, 0.25);
    color: #FFFFFF;
}

/* SEARCH & CLASS SELECTOR */
.kkiz-filter-row {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.kkiz-search-box {
    flex: 1;
    min-width: 240px;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    background: #F8FAFC;
    border: 1.5px solid var(--lumina-border, #E2E8F0);
    border-radius: 10px;
    transition: all 0.2s;
}
.kkiz-search-box:focus-within {
    border-color: #0D7A87;
    background: #FFFFFF;
    box-shadow: 0 0 0 4px rgba(13, 122, 135, 0.12);
}
.kkiz-search-box iconify-icon {
    color: #94A3B8;
    font-size: 16px;
    flex-shrink: 0;
}
.kkiz-search-box input {
    border: none;
    background: transparent;
    outline: none;
    font-family: inherit;
    font-size: 13px;
    color: var(--lumina-text-body, #334155);
    width: 100%;
}
.kkiz-class-select {
    padding: 8.5px 14px;
    background: #F8FAFC;
    border: 1.5px solid var(--lumina-border, #E2E8F0);
    border-radius: 10px;
    font-family: inherit;
    font-size: 12.5px;
    font-weight: 600;
    color: #1D3B5E;
    outline: none;
    cursor: pointer;
    transition: all 0.2s;
}
.kkiz-class-select:focus {
    border-color: #0D7A87;
    background: #FFFFFF;
}
.kkiz-btn-refresh {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 9px 16px;
    background: #F8FAFC;
    border: 1.5px solid var(--lumina-border, #E2E8F0);
    border-radius: 10px;
    font-family: inherit;
    font-size: 12.5px;
    font-weight: 700;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s;
}
.kkiz-btn-refresh:hover {
    background: #FFFFFF;
    border-color: #0D7A87;
    color: #0D7A87;
}

/* LIST CARDS PERIZINAN */
.kkiz-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.kkiz-item {
    background: #FFFFFF;
    border: 1.5px solid var(--lumina-border, #E2E8F0);
    border-radius: 16px;
    padding: 18px 20px;
    box-shadow: 0 4px 18px -6px rgba(29, 59, 94, 0.06);
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    position: relative;
    overflow: hidden;
}
.kkiz-item:hover {
    border-color: #CBD5E1;
    transform: translateY(-2px);
    box-shadow: 0 8px 24px -6px rgba(29, 59, 94, 0.10);
}
.kkiz-item::before {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3.5px;
    background: linear-gradient(90deg, #0D7A87, #52B2DC);
    opacity: 0;
    transition: opacity 0.2s;
}
.kkiz-item:hover::before {
    opacity: 1;
}

.kkiz-item__user {
    display: flex;
    gap: 14px;
    align-items: center;
    margin-bottom: 14px;
    padding-bottom: 14px;
    border-bottom: 1px dashed #E2E8F0;
    flex-wrap: wrap;
}
.kkiz-item__avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    overflow: hidden;
    flex-shrink: 0;
    background: linear-gradient(135deg, #0D7A87, #1D3B5E);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FFFFFF;
    font-weight: 800;
    font-size: 15px;
    box-shadow: 0 3px 10px rgba(13, 122, 135, 0.25);
    border: 2px solid #FFFFFF;
}
.kkiz-item__avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.kkiz-item__avatar__initials {
    letter-spacing: 0.5px;
}

.kkiz-item__user-body {
    flex: 1;
    min-width: 200px;
}
.kkiz-item__title {
    font-family: 'Outfit', sans-serif;
    font-size: 15.5px;
    font-weight: 800;
    color: var(--lumina-text-title, #0F1D2F);
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.kkiz-item__nim {
    font-size: 11px;
    font-weight: 700;
    color: #64748B;
    background: #F1F5F9;
    padding: 2px 8px;
    border-radius: 6px;
}
.kkiz-item__meta {
    display: flex;
    gap: 12px;
    font-size: 12px;
    color: #64748B;
    margin-top: 4px;
    flex-wrap: wrap;
}
.kkiz-item__meta span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
.kkiz-item__meta iconify-icon {
    color: #0D7A87;
    font-size: 13px;
}

/* PILL BADGE STATUS */
.kkiz-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.3px;
    text-transform: uppercase;
    flex-shrink: 0;
}
.kkiz-pill.pending { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; }
.kkiz-pill.progress { background: #E0F2FE; color: #0369A1; border: 1px solid #BAE6FD; }
.kkiz-pill.approved { background: #ECFDF5; color: #065F46; border: 1px solid #A7F3D0; }
.kkiz-pill.rejected { background: #FFF1F2; color: #BE123C; border: 1px solid #FECDD3; }

/* KONTEN DETAIL */
.kkiz-item__content {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 16px;
}
.kkiz-item__type-box {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 10px;
    width: fit-content;
}
.kkiz-item__type-box.sakit {
    background: #FFFBEB;
    border: 1px solid #FDE68A;
    color: #B45309;
}
.kkiz-item__type-box.izin {
    background: #F0F9FF;
    border: 1px solid #BAE6FD;
    color: #0369A1;
}
.kkiz-item__type-icon {
    font-size: 16px;
}
.kkiz-item__type-title {
    font-weight: 800;
    font-size: 12.5px;
    line-height: 1.2;
}
.kkiz-item__type-date {
    font-size: 11px;
    opacity: 0.85;
}

.kkiz-alasan {
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-left: 3.5px solid #0D7A87;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 13px;
    color: #334155;
    line-height: 1.5;
}
.kkiz-alasan-label {
    font-weight: 800;
    color: #1D3B5E;
    margin-right: 4px;
}
.kkiz-alasan-text {
    font-style: italic;
}

.kkiz-location {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #64748B;
    padding: 4px 0;
}
.kkiz-location iconify-icon {
    color: #F59E0B;
    font-size: 14px;
    flex-shrink: 0;
}

/* TOMBOL AKSI & AUDIT */
.kkiz-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}
.kkiz-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: 10px;
    font-family: inherit;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    border: 1.5px solid transparent;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
}
.kkiz-btn iconify-icon {
    font-size: 15px;
}
.kkiz-btn:hover {
    transform: translateY(-2px);
}
.kkiz-btn--ghost {
    background: #F8FAFC;
    border-color: #CBD5E1;
    color: #334155;
}
.kkiz-btn--ghost:hover {
    background: #FFFFFF;
    border-color: #0D7A87;
    color: #0D7A87;
    box-shadow: 0 4px 12px rgba(13, 122, 135, 0.15);
}
.kkiz-btn--approve {
    background: linear-gradient(135deg, #10B981, #059669);
    color: #FFFFFF;
    box-shadow: 0 4px 14px -3px rgba(16, 185, 129, 0.45);
}
.kkiz-btn--approve:hover {
    box-shadow: 0 6px 18px -3px rgba(16, 185, 129, 0.55);
}
.kkiz-btn--reject {
    background: linear-gradient(135deg, #F43F5E, #E11D48);
    color: #FFFFFF;
    box-shadow: 0 4px 14px -3px rgba(244, 63, 94, 0.45);
}
.kkiz-btn--reject:hover {
    box-shadow: 0 6px 18px -3px rgba(244, 63, 94, 0.55);
}

.kkiz-handled {
    display: flex;
    flex-direction: column;
    gap: 4px;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 10px;
    padding: 8px 14px;
    font-size: 11.5px;
    width: 100%;
}
.kkiz-handled-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-weight: 700;
}
.kkiz-handled-item.success { color: #059669; }
.kkiz-handled-item.danger { color: #E11D48; }
.kkiz-handled-note {
    font-size: 11px;
    color: #64748B;
    font-style: italic;
    margin-left: 20px;
}
.kkiz-handled-sub {
    margin-top: 3px;
    padding-top: 4px;
    border-top: 1px dashed #E2E8F0;
    font-size: 11px;
    display: flex;
    align-items: center;
    gap: 5px;
}
.kkiz-handled-sub.approved { color: #059669; font-weight: 600; }
.kkiz-handled-sub.rejected { color: #E11D48; }
.kkiz-handled-sub.pending { color: #0284C7; }

/* EMPTY STATE */
.kkiz-empty {
    background: #FFFFFF;
    border: 1.5px dashed #CBD5E1;
    border-radius: 18px;
    padding: 48px 24px;
    text-align: center;
}
.kkiz-empty-icon {
    width: 72px;
    height: 72px;
    margin: 0 auto 14px;
    border-radius: 50%;
    background: #F0FDF4;
    color: #10B981;
    display: flex;
    align-items: center;
    justify-content: center;
}
.kkiz-empty-title {
    font-family: 'Outfit', sans-serif;
    font-size: 17px;
    font-weight: 800;
    color: #1D3B5E;
    margin: 0 0 6px;
}
.kkiz-empty-desc {
    font-size: 13px;
    color: #64748B;
    margin: 0;
    max-width: 420px;
    margin-left: auto;
    margin-right: auto;
    line-height: 1.5;
}

/* SKELETON LOADER */
.kkiz-skel {
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.kkiz-skel-card {
    background: #FFFFFF;
    border: 1.5px solid #E2E8F0;
    border-radius: 16px;
    padding: 20px;
    display: flex;
    gap: 16px;
    animation: skelPulse 1.5s infinite;
}
@keyframes skelPulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
.kkiz-skel-avatar {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    background: #E2E8F0;
    flex-shrink: 0;
}
.kkiz-skel-lines {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.kkiz-skel-line {
    height: 14px;
    background: #E2E8F0;
    border-radius: 4px;
}

/* ==============================================================================
 * MODAL EVIDENCE (BUKTI SURAT / FOTO & GPS MAPS)
 * ============================================================================== */
.kkiz-modal {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 29, 47, 0.65);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
}
.kkiz-modal.show {
    opacity: 1;
    pointer-events: auto;
}
.kkiz-modal__box {
    background: #FFFFFF;
    border-radius: 20px;
    width: 100%;
    max-width: 540px;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 60px -15px rgba(15, 29, 47, 0.35);
    transform: scale(0.95);
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    overflow: hidden;
}
.kkiz-modal.show .kkiz-modal__box {
    transform: scale(1);
}
.kkiz-modal__head {
    padding: 18px 22px;
    border-bottom: 1px solid #E2E8F0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #F8FAFC;
}
.kkiz-modal__user {
    display: flex;
    align-items: center;
    gap: 12px;
}
.kkiz-modal__avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0D7A87, #1D3B5E);
    color: #FFFFFF;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    overflow: hidden;
}
.kkiz-modal__avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.kkiz-modal__user-info strong {
    font-family: 'Outfit', sans-serif;
    font-size: 15px;
    color: #1D3B5E;
    display: block;
}
.kkiz-modal__user-info small {
    font-size: 11.5px;
    color: #64748B;
}
.kkiz-modal__close {
    background: transparent;
    border: none;
    color: #64748B;
    font-size: 24px;
    cursor: pointer;
    line-height: 1;
    border-radius: 8px;
    padding: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}
.kkiz-modal__close:hover {
    color: #E11D48;
    background: #FFF1F2;
}

.kkiz-modal__body {
    padding: 20px 22px;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.kkiz-modal__img-holder {
    background: #F1F5F9;
    border-radius: 12px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 220px;
    max-height: 380px;
    border: 1px solid #E2E8F0;
}
.kkiz-modal__img-holder img {
    max-width: 100%;
    max-height: 380px;
    object-fit: contain;
    display: block;
}
.kkiz-modal__meta {
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    padding: 12px 16px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 12px;
    color: #475569;
}
.kkiz-modal__meta strong {
    color: #1D3B5E;
}
.kkiz-modal__map-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 10px 18px;
    border-radius: 10px;
    background: linear-gradient(135deg, #0D7A87, #1D3B5E);
    color: #FFFFFF !important;
    text-decoration: none !important;
    font-size: 12.5px;
    font-weight: 700;
    box-shadow: 0 4px 14px rgba(13, 122, 135, 0.3);
    transition: all 0.2s;
}
.kkiz-modal__map-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(13, 122, 135, 0.4);
}
.kkiz-modal__foot {
    padding: 14px 22px;
    border-top: 1px solid #E2E8F0;
    display: flex;
    justify-content: flex-end;
    background: #F8FAFC;
}

/* ==============================================================================
 * CUSTOM LUMINA DIALOG (CONFIRM & PROMPT MODAL)
 * ============================================================================== */
.kkiz-dialog {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 29, 47, 0.7);
    backdrop-filter: blur(8px);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
}
.kkiz-dialog.show {
    opacity: 1;
    pointer-events: auto;
}
.kkiz-dialog__box {
    background: #FFFFFF;
    border-radius: 18px;
    width: 100%;
    max-width: 440px;
    box-shadow: 0 25px 60px rgba(0,0,0,0.3);
    transform: scale(0.92);
    transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    overflow: hidden;
}
.kkiz-dialog.show .kkiz-dialog__box {
    transform: scale(1);
}
.kkiz-dialog__body {
    padding: 24px 24px 14px;
    text-align: center;
}
.kkiz-dialog__icon {
    width: 58px;
    height: 58px;
    border-radius: 50%;
    margin: 0 auto 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
}
.kkiz-dialog__icon.success { background: #ECFDF5; color: #10B981; }
.kkiz-dialog__icon.danger { background: #FFF1F2; color: #F43F5E; }
.kkiz-dialog__icon.prompt { background: #FEF3C7; color: #D97706; }

.kkiz-dialog__title {
    font-family: 'Outfit', sans-serif;
    font-size: 17.5px;
    font-weight: 800;
    color: #1D3B5E;
    margin: 0 0 8px;
}
.kkiz-dialog__msg {
    font-size: 13.5px;
    color: #475569;
    line-height: 1.55;
    margin: 0 0 12px;
}
.kkiz-dialog__input {
    width: 100%;
    padding: 10px 14px;
    background: #F8FAFC;
    border: 1.5px solid #CBD5E1;
    border-radius: 10px;
    font-family: inherit;
    font-size: 13px;
    color: #1E293B;
    outline: none;
    box-sizing: border-box;
    transition: all 0.2s;
    min-height: 72px;
    resize: vertical;
}
.kkiz-dialog__input:focus {
    border-color: #0D7A87;
    background: #FFFFFF;
    box-shadow: 0 0 0 4px rgba(13, 122, 135, 0.12);
}
.kkiz-dialog__actions {
    display: flex;
    gap: 10px;
    padding: 16px 24px 20px;
}
.kkiz-dialog__btn {
    flex: 1;
    padding: 10px 16px;
    border-radius: 10px;
    font-family: inherit;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    border: none;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}
.kkiz-dialog__btn--cancel {
    background: #F1F5F9;
    color: #475569;
}
.kkiz-dialog__btn--cancel:hover {
    background: #E2E8F0;
    color: #1E293B;
}
.kkiz-dialog__btn--ok {
    background: linear-gradient(135deg, #10B981, #059669);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(16, 185, 129, 0.4);
}
.kkiz-dialog__btn--ok:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(16, 185, 129, 0.5);
}
.kkiz-dialog__btn--danger {
    background: linear-gradient(135deg, #F43F5E, #E11D48);
    color: #FFFFFF;
    box-shadow: 0 4px 14px rgba(244, 63, 94, 0.4);
}
.kkiz-dialog__btn--danger:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(244, 63, 94, 0.5);
}

/* ==============================================================================
 * TOAST NOTIFICATION CONTAINER
 * ============================================================================== */
.kkiz-toast-container {
    position: fixed;
    top: 84px;
    right: 22px;
    z-index: 100001;
    display: flex;
    flex-direction: column;
    gap: 10px;
    max-width: 360px;
    pointer-events: none;
}
.kkiz-toast-item {
    background: #FFFFFF;
    border-left: 4.5px solid #10B981;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.18);
    padding: 12px 16px;
    pointer-events: auto;
    font-family: 'Plus Jakarta Sans', sans-serif;
    animation: toastSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    transition: opacity 0.25s, transform 0.25s;
}
.kkiz-toast-item.err { border-left-color: #F43F5E; }
.kkiz-toast-item.warn { border-left-color: #F59E0B; }
.kkiz-toast-title {
    font-size: 13px;
    font-weight: 800;
    color: #1D3B5E;
    margin-bottom: 2px;
}
.kkiz-toast-msg {
    font-size: 12px;
    color: #475569;
    line-height: 1.45;
}
@keyframes toastSlideIn {
    from { transform: translateX(120%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* PAGINATION */
.kkiz-pagination {
    margin-top: 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding: 12px 18px;
    background: #FFFFFF;
    border: 1.5px solid var(--lumina-border, #E2E8F0);
    border-radius: 14px;
    font-size: 12.5px;
    color: #64748B;
}
.kkiz-pagination-btns {
    display: flex;
    gap: 6px;
}
.kkiz-page-btn {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid #CBD5E1;
    background: #FFFFFF;
    font-family: inherit;
    font-size: 12px;
    font-weight: 700;
    color: #334155;
    cursor: pointer;
    transition: all 0.2s;
}
.kkiz-page-btn:hover:not(:disabled) {
    border-color: #0D7A87;
    color: #0D7A87;
}
.kkiz-page-btn.active {
    background: #0D7A87;
    border-color: #0D7A87;
    color: #FFFFFF;
}
.kkiz-page-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>

<div class="kkiz-wrap" id="kkizRoot">

    <!-- TOOLBAR & FILTER TABS -->
    <div class="kkiz-toolbar">
        <div class="kkiz-tabs" id="kkizTabs">
            <button type="button" class="kkiz-tab-btn active" data-status="all">
                <iconify-icon icon="solar:inbox-bold"></iconify-icon>
                <span>Semua</span>
                <span class="kkiz-tab-badge" id="tabCntAll">0</span>
            </button>
            <button type="button" class="kkiz-tab-btn" data-status="pending">
                <iconify-icon icon="solar:hourglass-bold"></iconify-icon>
                <span>Menunggu Verifikasi</span>
                <span class="kkiz-tab-badge" id="tabCntPending">0</span>
            </button>
            <button type="button" class="kkiz-tab-btn" data-status="progress">
                <iconify-icon icon="solar:refresh-circle-bold"></iconify-icon>
                <span>Diproses Guru</span>
                <span class="kkiz-tab-badge" id="tabCntProgress">0</span>
            </button>
            <button type="button" class="kkiz-tab-btn" data-status="approved">
                <iconify-icon icon="solar:check-circle-bold"></iconify-icon>
                <span>Disetujui</span>
                <span class="kkiz-tab-badge" id="tabCntApproved">0</span>
            </button>
            <button type="button" class="kkiz-tab-btn" data-status="rejected">
                <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                <span>Ditolak</span>
                <span class="kkiz-tab-badge" id="tabCntRejected">0</span>
            </button>
        </div>

        <div class="kkiz-filter-row">
            <div class="kkiz-search-box">
                <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                <input type="text" id="kkizSearch" placeholder="Cari nama siswa, NIS/NISN, atau alasan izin...">
            </div>

            <?php if (count($allClasses) > 1 || $isAdminOrGuru): ?>
                <select class="kkiz-class-select" id="kkizClassSelect" title="Pilih Rombel Kelas">
                    <?php foreach ($allClasses as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id'] == $selectedClassId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['nama_kelas']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <button type="button" class="kkiz-btn-refresh" id="kkizBtnRefresh" title="Muat Ulang Data">
                <iconify-icon icon="solar:refresh-bold"></iconify-icon>
                <span>Segarkan</span>
            </button>
        </div>
    </div>

    <!-- LIST CONTAINER -->
    <div class="kkiz-list" id="kkizListContainer">
        <!-- Skeleton Loading Awal -->
        <div class="kkiz-skel">
            <div class="kkiz-skel-card">
                <div class="kkiz-skel-avatar"></div>
                <div class="kkiz-skel-lines">
                    <div class="kkiz-skel-line" style="width: 40%;"></div>
                    <div class="kkiz-skel-line" style="width: 70%;"></div>
                    <div class="kkiz-skel-line" style="width: 50%;"></div>
                </div>
            </div>
            <div class="kkiz-skel-card">
                <div class="kkiz-skel-avatar"></div>
                <div class="kkiz-skel-lines">
                    <div class="kkiz-skel-line" style="width: 35%;"></div>
                    <div class="kkiz-skel-line" style="width: 65%;"></div>
                    <div class="kkiz-skel-line" style="width: 45%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- PAGINATION -->
    <div class="kkiz-pagination" id="kkizPagination" style="display: none;">
        <div id="kkizPagInfo">Menampilkan 0 dari 0 permohonan</div>
        <div class="kkiz-pagination-btns" id="kkizPagBtns"></div>
    </div>

</div>

<!-- ==============================================================================
 * MODAL EVIDENCE (PREVIEW BUKTI DOKUMEN & KOORDINAT GPS)
 * ============================================================================== -->
<div class="kkiz-modal" id="kkizModalEvidence">
    <div class="kkiz-modal__box">
        <div class="kkiz-modal__head">
            <div class="kkiz-modal__user">
                <div class="kkiz-modal__avatar" id="modalAvatar">
                    <span id="modalInitials">??</span>
                </div>
                <div class="kkiz-modal__user-info">
                    <strong id="modalNama">Nama Siswa</strong>
                    <small id="modalSub">NIS: -</small>
                </div>
            </div>
            <button type="button" class="kkiz-modal__close" id="btnModalClose">&times;</button>
        </div>
        <div class="kkiz-modal__body">
            <div class="kkiz-modal__img-holder" id="modalImgWrap">
                <img id="modalImg" src="" alt="Bukti Surat Izin">
            </div>
            <div class="kkiz-modal__meta">
                <div><strong>Mata Pelajaran:</strong> <span id="modalMapel">-</span></div>
                <div><strong>Waktu Pengajuan:</strong> <span id="modalTime">-</span></div>
                <div><strong>Titik Lokasi Siswa:</strong> <span id="modalLoc">-</span></div>
            </div>
            <a href="#" target="_blank" rel="noopener" class="kkiz-modal__map-btn" id="modalMapBtn" style="display: none;">
                <iconify-icon icon="solar:map-point-bold" width="16"></iconify-icon>
                <span>Buka Posisi di Google Maps</span>
            </a>
        </div>
        <div class="kkiz-modal__foot">
            <button type="button" class="kkiz-btn kkiz-btn--ghost" id="btnModalCloseFoot">Tutup</button>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL DIALOG CUSTOM (CONFIRM & PROMPT REASON)
 * ============================================================================== -->
<div class="kkiz-dialog" id="kkizCustomDialog">
    <div class="kkiz-dialog__box">
        <div class="kkiz-dialog__body">
            <div class="kkiz-dialog__icon" id="dialogIcon">
                <iconify-icon icon="solar:question-circle-bold"></iconify-icon>
            </div>
            <h3 class="kkiz-dialog__title" id="dialogTitle">Konfirmasi</h3>
            <div class="kkiz-dialog__msg" id="dialogMsg">Apakah Anda yakin?</div>
            <div id="dialogInputWrap" style="display: none; margin-top: 10px;">
                <textarea class="kkiz-dialog__input" id="dialogInput" placeholder="Tuliskan catatan atau alasan penolakan..."></textarea>
            </div>
        </div>
        <div class="kkiz-dialog__actions">
            <button type="button" class="kkiz-dialog__btn kkiz-dialog__btn--cancel" id="dialogBtnCancel">Batal</button>
            <button type="button" class="kkiz-dialog__btn kkiz-dialog__btn--ok" id="dialogBtnOk">Lanjutkan</button>
        </div>
    </div>
</div>

<!-- TOAST CONTAINER -->
<div class="kkiz-toast-container" id="kkizToastContainer"></div>

<!-- ==============================================================================
 * CLIENT JAVASCRIPT ENGINE (AJAX, REAL-TIME COUNTS & DIALOGS)
 * ============================================================================== -->
<script>
(function(){
    'use strict';

    const ENDPOINT = window.location.pathname;
    let currentStatus = 'all';
    let currentSearch = '';
    let currentPage = 1;
    let selectedClassId = parseInt('<?= $selectedClassId ?>', 10) || 0;

    // --------------------------------------------------------------------------
    // TOAST NOTIFICATION SYSTEM
    // --------------------------------------------------------------------------
    function toast(type, title, msg) {
        const normType = (type === 'err' || type === 'error' || type === 'danger') ? 'error' : ((type === 'warn' || type === 'warning') ? 'warning' : 'success');
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, title, msg, 4500);
        }
        const container = document.getElementById('kkizToastContainer');
        if (!container) return;
        const item = document.createElement('div');
        item.className = 'kkiz-toast-item ' + (type === 'err' ? 'err' : (type === 'warn' ? 'warn' : ''));
        item.innerHTML = `
            <div class="kkiz-toast-title">${escapeHtml(title)}</div>
            <div class="kkiz-toast-msg">${escapeHtml(msg)}</div>
        `;
        container.appendChild(item);
        setTimeout(() => {
            item.style.opacity = '0';
            item.style.transform = 'translateX(50px)';
            setTimeout(() => item.remove(), 250);
        }, 4500);
    }

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // --------------------------------------------------------------------------
    // CUSTOM DIALOG PROMISE (CONFIRM & PROMPT)
    // --------------------------------------------------------------------------
    const dialogModal = document.getElementById('kkizCustomDialog');
    const dialogTitle = document.getElementById('dialogTitle');
    const dialogMsg   = document.getElementById('dialogMsg');
    const dialogIcon  = document.getElementById('dialogIcon');
    const dialogInputWrap = document.getElementById('dialogInputWrap');
    const dialogInput = document.getElementById('dialogInput');
    const dialogBtnOk = document.getElementById('dialogBtnOk');
    const dialogBtnCancel = document.getElementById('dialogBtnCancel');

    const dialog = {
        confirm: function(msgHtml, options = {}) {
            const t = (options.type === 'danger' || options.type === 'error') ? 'error' : (options.type === 'warn' ? 'warning' : 'success');
            if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
                const textOnly = String(msgHtml || '').replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                return Ilmuna.confirm(t, options.title || 'Konfirmasi Tindakan', textOnly, options.okText || 'Ya, Lanjutkan', 'Batal');
            }
            return new Promise((resolve) => {
                dialogTitle.textContent = options.title || 'Konfirmasi Tindakan';
                dialogMsg.innerHTML = msgHtml;
                dialogInputWrap.style.display = 'none';

                dialogIcon.className = 'kkiz-dialog__icon ' + (options.type === 'danger' ? 'danger' : 'success');
                dialogIcon.innerHTML = options.type === 'danger' 
                    ? '<iconify-icon icon="solar:danger-triangle-bold"></iconify-icon>'
                    : '<iconify-icon icon="solar:check-circle-bold"></iconify-icon>';

                dialogBtnOk.className = 'kkiz-dialog__btn ' + (options.okType === 'danger' ? 'kkiz-dialog__btn--danger' : 'kkiz-dialog__btn--ok');
                dialogBtnOk.innerHTML = options.okText || 'Ya, Lanjutkan';

                dialogModal.classList.add('show');

                const cleanup = () => {
                    dialogModal.classList.remove('show');
                    dialogBtnOk.onclick = null;
                    dialogBtnCancel.onclick = null;
                };

                dialogBtnOk.onclick = () => { cleanup(); resolve(true); };
                dialogBtnCancel.onclick = () => { cleanup(); resolve(false); };
            });
        },
        prompt: function(msgHtml, options = {}) {
            return new Promise((resolve) => {
                dialogTitle.textContent = options.title || 'Alasan Penolakan';
                dialogMsg.innerHTML = msgHtml;
                dialogInputWrap.style.display = 'block';
                dialogInput.value = '';
                dialogInput.placeholder = options.placeholder || 'Tuliskan alasan penolakan izin...';

                dialogIcon.className = 'kkiz-dialog__icon prompt';
                dialogIcon.innerHTML = '<iconify-icon icon="solar:shield-cross-bold"></iconify-icon>';

                dialogBtnOk.className = 'kkiz-dialog__btn kkiz-dialog__btn--danger';
                dialogBtnOk.innerHTML = options.okText || 'Tolak Izin';

                dialogModal.classList.add('show');
                setTimeout(() => dialogInput.focus(), 150);

                const cleanup = () => {
                    dialogModal.classList.remove('show');
                    dialogBtnOk.onclick = null;
                    dialogBtnCancel.onclick = null;
                };

                dialogBtnOk.onclick = () => {
                    const val = dialogInput.value.trim();
                    cleanup();
                    resolve(val);
                };
                dialogBtnCancel.onclick = () => { cleanup(); resolve(null); };
            });
        }
    };

    // --------------------------------------------------------------------------
    // LOAD STAT COUNTS (AJAX)
    // --------------------------------------------------------------------------
    async function loadCounts() {
        try {
            const fd = new FormData();
            fd.append('action', 'get_counts');
            if (selectedClassId) fd.append('kelas_id', selectedClassId);

            const res = await fetch(ENDPOINT + '?kelas_id=' + selectedClassId, {
                method: 'POST',
                body: fd
            }).then(r => r.json());

            if (res && res.success && res.data) {
                const d = res.data;
                const updateBadge = (id, val) => {
                    const el = document.getElementById(id);
                    if (el) el.textContent = val;
                };
                updateBadge('tabCntAll', d.all || 0);
                updateBadge('tabCntPending', d.pending || 0);
                updateBadge('tabCntProgress', d.progress || 0);
                updateBadge('tabCntApproved', d.approved || 0);
                updateBadge('tabCntRejected', d.rejected || 0);
            }
        } catch(e) {
            console.error('Gagal memuat count stats:', e);
        }
    }

    // --------------------------------------------------------------------------
    // LOAD LIST DATA (AJAX)
    // --------------------------------------------------------------------------
    async function loadList(page = 1) {
        currentPage = page;
        const listContainer = document.getElementById('kkizListContainer');
        const paginationWrap = document.getElementById('kkizPagination');

        listContainer.innerHTML = `
            <div class="kkiz-skel">
                <div class="kkiz-skel-card">
                    <div class="kkiz-skel-avatar"></div>
                    <div class="kkiz-skel-lines">
                        <div class="kkiz-skel-line" style="width: 40%;"></div>
                        <div class="kkiz-skel-line" style="width: 70%;"></div>
                    </div>
                </div>
            </div>
        `;

        try {
            const fd = new FormData();
            fd.append('action', 'get_list');
            fd.append('status', currentStatus);
            fd.append('search', currentSearch);
            fd.append('page', page);
            fd.append('per', 15);
            if (selectedClassId) fd.append('kelas_id', selectedClassId);

            const res = await fetch(ENDPOINT + '?kelas_id=' + selectedClassId, {
                method: 'POST',
                body: fd
            }).then(r => r.json());

            if (res && res.success) {
                listContainer.innerHTML = res.list_html;

                // Render Pagination jika baris > 0
                if (res.total > 0 && res.pages > 1) {
                    paginationWrap.style.display = 'flex';
                    document.getElementById('kkizPagInfo').textContent = `Menampilkan halaman ${res.page} dari total ${res.pages} (${res.total} permohonan)`;
                    renderPagination(res.page, res.pages);
                } else {
                    paginationWrap.style.display = 'none';
                }
            } else {
                listContainer.innerHTML = '<div class="kkiz-empty"><p>Gagal memuat data.</p></div>';
            }
        } catch(err) {
            listContainer.innerHTML = '<div class="kkiz-empty"><p>Terjadi gangguan koneksi internet.</p></div>';
            console.error(err);
        }
    }

    function renderPagination(cur, total) {
        const wrap = document.getElementById('kkizPagBtns');
        wrap.innerHTML = '';

        const prevBtn = document.createElement('button');
        prevBtn.className = 'kkiz-page-btn';
        prevBtn.textContent = '« Sebelumnya';
        prevBtn.disabled = (cur <= 1);
        prevBtn.onclick = () => loadList(cur - 1);
        wrap.appendChild(prevBtn);

        for (let i = 1; i <= total; i++) {
            if (i === 1 || i === total || (i >= cur - 1 && i <= cur + 1)) {
                const pBtn = document.createElement('button');
                pBtn.className = 'kkiz-page-btn' + (i === cur ? ' active' : '');
                pBtn.textContent = i;
                pBtn.onclick = () => loadList(i);
                wrap.appendChild(pBtn);
            }
        }

        const nextBtn = document.createElement('button');
        nextBtn.className = 'kkiz-page-btn';
        nextBtn.textContent = 'Berikutnya »';
        nextBtn.disabled = (cur >= total);
        nextBtn.onclick = () => loadList(cur + 1);
        wrap.appendChild(nextBtn);
    }

    // --------------------------------------------------------------------------
    // ACTION HANDLER (APPROVE / REJECT / VIEW EVIDENCE)
    // --------------------------------------------------------------------------
    const listWrap = document.getElementById('kkizListContainer');
    listWrap.addEventListener('click', async function(e){
        const btn = e.target.closest('[data-act]');
        if (!btn) return;

        const act = btn.dataset.act;

        // BUKA MODAL EVIDENCE
        if (act === 'view-evidence') {
            openEvidenceModal({
                foto:     btn.dataset.foto,
                nama:     btn.dataset.nama,
                nim:      btn.dataset.nim,
                avatar:   btn.dataset.avatar,
                initials: btn.dataset.initials,
                lat:      parseFloat(btn.dataset.lat),
                lng:      parseFloat(btn.dataset.lng),
                alamat:   btn.dataset.alamat,
                time:     btn.dataset.time,
                mapel:    btn.dataset.mapel
            });
            return;
        }

        const id = parseInt(btn.dataset.id, 10) || 0;
        if (!id) return;
        const nama = btn.dataset.nama || 'siswa ini';

        // SETUJUI PERIZINAN
        if (act === 'approve') {
            const ok = await dialog.confirm(
                `Apakah Anda yakin menyetujui permohonan izin dari <strong>${escapeHtml(nama)}</strong>?<br><small style="color: #64748B;">Permohonan akan otomatis diteruskan ke Guru Pengampu untuk pengesahan tahap akhir.</small>`,
                {
                    title: 'Setujui Izin Rekan Sekelas?',
                    okText: 'Ya, Setujui Permohonan',
                    type: 'success',
                    okType: 'success'
                }
            );
            if (!ok) return;
            await doProcessDecision(id, 'approve', '', btn);
            return;
        }

        // TOLAK PERIZINAN
        if (act === 'reject') {
            const reason = await dialog.prompt(
                `Berikan alasan penolakan untuk permohonan dari <strong>${escapeHtml(nama)}</strong>:`,
                {
                    title: 'Tolak Permohonan Izin',
                    okText: 'Tolak Permohonan',
                    placeholder: 'Contoh: Bukti surat dokter tidak jelas / tidak terverifikasi wali santri...'
                }
            );
            if (reason === null) return;
            await doProcessDecision(id, 'reject', reason, btn);
            return;
        }
    });

    async function doProcessDecision(id, decision, reason, btn) {
        const item = btn.closest('.kkiz-item');
        const actions = btn.closest('.kkiz-actions');
        if (actions) actions.querySelectorAll('button').forEach(b => b.disabled = true);
        const oldHtml = btn.innerHTML;
        btn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="fa-spin"></iconify-icon> Memproses...';

        try {
            const fd = new FormData();
            fd.append('action', 'dosen_ketua_izin_action');
            fd.append('id_izin', id);
            fd.append('decision', decision);
            fd.append('reason', reason || '');
            if (selectedClassId) fd.append('kelas_id', selectedClassId);

            const res = await fetch(ENDPOINT, {
                method: 'POST',
                body: fd
            }).then(r => r.json());

            if (!res || !res.success) {
                toast('err', 'Gagal Memproses', (res && res.data && res.data.message) || 'Terjadi kesalahan sistem.');
                if (actions) actions.querySelectorAll('button').forEach(b => b.disabled = false);
                btn.innerHTML = oldHtml;
                return;
            }

            toast('ok', decision === 'approve' ? 'Izin Disetujui' : 'Izin Ditolak', res.data.message);

            // Efek transisi halus kartu
            if (item) {
                item.style.transition = 'all 0.35s ease';
                item.style.opacity = '0';
                item.style.transform = 'translateX(25px)';
                setTimeout(() => {
                    item.remove();
                    loadCounts();
                    if (listWrap.querySelectorAll('.kkiz-item').length === 0) {
                        loadList(currentPage);
                    }
                }, 350);
            } else {
                loadCounts();
                loadList(currentPage);
            }
        } catch(err) {
            toast('err', 'Koneksi Terputus', err.message || 'Silakan coba lagi.');
            if (actions) actions.querySelectorAll('button').forEach(b => b.disabled = false);
            btn.innerHTML = oldHtml;
        }
    }

    // --------------------------------------------------------------------------
    // MODAL EVIDENCE PREVIEW
    // --------------------------------------------------------------------------
    const evidenceModal = document.getElementById('kkizModalEvidence');
    function openEvidenceModal(data) {
        const avatarWrap = document.getElementById('modalAvatar');
        avatarWrap.innerHTML = '';
        if (data.avatar && data.avatar !== '') {
            const img = document.createElement('img');
            img.src = data.avatar;
            img.onerror = () => {
                avatarWrap.innerHTML = `<span>${escapeHtml(data.initials || '??')}</span>`;
            };
            avatarWrap.appendChild(img);
        } else {
            avatarWrap.innerHTML = `<span>${escapeHtml(data.initials || '??')}</span>`;
        }

        document.getElementById('modalNama').textContent = data.nama || '-';
        document.getElementById('modalSub').textContent  = 'NIS: ' + (data.nim || '-');
        document.getElementById('modalMapel').textContent = data.mapel || '-';
        document.getElementById('modalTime').textContent = data.time || '-';

        const imgEl = document.getElementById('modalImg');
        const imgWrap = document.getElementById('modalImgWrap');
        if (data.foto && data.foto.toLowerCase().endsWith('.pdf')) {
            imgWrap.innerHTML = `
                <div style="padding: 30px; text-align: center; color: #475569;">
                    <iconify-icon icon="solar:document-text-bold" width="56" style="color: #E11D48; margin-bottom: 8px;"></iconify-icon>
                    <div style="font-weight: 700; margin-bottom: 8px;">Dokumen Bukti Berformat PDF</div>
                    <a href="${data.foto}" target="_blank" class="kkiz-btn kkiz-btn--ghost" style="margin: 0 auto;">
                        <iconify-icon icon="solar:file-download-bold"></iconify-icon> Buka & Unduh PDF
                    </a>
                </div>
            `;
        } else {
            imgWrap.innerHTML = `<img src="${data.foto}" alt="Bukti Surat Izin" style="max-width: 100%; max-height: 380px; object-fit: contain;">`;
        }

        const mapBtn = document.getElementById('modalMapBtn');
        const locText = document.getElementById('modalLoc');

        if (!isNaN(data.lat) && !isNaN(data.lng) && data.lat !== 0 && data.lng !== 0) {
            locText.textContent = `${data.lat.toFixed(6)}, ${data.lng.toFixed(6)}` + (data.alamat ? ` (${data.alamat})` : '');
            mapBtn.href = `https://www.google.com/maps?q=${data.lat},${data.lng}`;
            mapBtn.style.display = 'inline-flex';
        } else if (data.alamat) {
            locText.textContent = data.alamat;
            mapBtn.style.display = 'none';
        } else {
            locText.textContent = 'Lokasi GPS tidak disertakan';
            mapBtn.style.display = 'none';
        }

        evidenceModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function closeEvidenceModal() {
        evidenceModal.classList.remove('show');
        document.body.style.overflow = '';
    }

    document.getElementById('btnModalClose').onclick = closeEvidenceModal;
    document.getElementById('btnModalCloseFoot').onclick = closeEvidenceModal;
    evidenceModal.onclick = (e) => { if (e.target === evidenceModal) closeEvidenceModal(); };
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && evidenceModal.classList.contains('show')) closeEvidenceModal();
    });

    // --------------------------------------------------------------------------
    // EVENT LISTENERS (TABS, SEARCH, CLASS SELECTOR, REFRESH)
    // --------------------------------------------------------------------------
    const tabs = document.querySelectorAll('.kkiz-tab-btn');
    tabs.forEach(tab => {
        tab.addEventListener('click', function(){
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            currentStatus = this.dataset.status;
            loadList(1);
        });
    });

    const searchInput = document.getElementById('kkizSearch');
    let searchDebounce = null;
    searchInput.addEventListener('input', function(){
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => {
            currentSearch = searchInput.value.trim();
            loadList(1);
        }, 300);
    });

    const classSelect = document.getElementById('kkizClassSelect');
    if (classSelect) {
        classSelect.addEventListener('change', function(){
            selectedClassId = parseInt(this.value, 10) || 0;
            loadCounts();
            loadList(1);
        });
    }

    document.getElementById('kkizBtnRefresh').addEventListener('click', function(){
        loadCounts();
        loadList(currentPage);
        toast('ok', 'Data Diperbarui', 'Data perizinan kelas berhasil disegarkan.');
    });

    // --------------------------------------------------------------------------
    // AUTO HEARTBEAT (30s) UNTUK SINKRONISASI REAL-TIME
    // --------------------------------------------------------------------------
    loadCounts();
    loadList(1);

    setInterval(() => {
        if (document.visibilityState === 'visible') {
            loadCounts();
        }
    }, 30000);

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>
