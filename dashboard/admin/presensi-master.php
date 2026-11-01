<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — TOKEN ABSENSI SISWA (ADMIN)
 * ==============================================================================
 * Halaman khusus kelola `tabel presensi` — TANPA QR CODE.
 *
 * FORMAT TOKEN (v2 — 30 digit):
 *   [3 random][NIS 8 digit][4 random][NISN 10 digit][5 random]
 *
 * EXPORT: Excel (.xlsx) via backend `presensi-token-excel.php`.
 * PRINT : @media print + window.print() — tanpa window.open / document.write.
 * ALERT : semua konfirmasi via dialog bertema (bukan window.confirm/alert).
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole('admin');

$pdo = getDBConnection();
$pageTitle = 'Token Absensi Siswa';
$pageIcon  = 'solar:key-minimalistic-bold';

/* ==============================================================================
 * 1. AJAX HANDLERS
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');

    set_error_handler(function ($no, $str, $file, $line) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false, 'data' => [],
            'message' => 'PHP Error: ' . $str . ' @ ' . basename($file) . ':' . $line,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false, 'data' => [],
                'message' => 'Fatal: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line'],
            ], JSON_UNESCAPED_UNICODE);
        }
    });

    function sendRes($success, $data = [], $message = '') {
        echo json_encode(['success' => $success, 'data' => $data, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ==========================================================
     * GENERATOR TOKEN — Format A (30 digit)
     * ========================================================== */
    if (!function_exists('ilmuna_rand_digits')) {
        function ilmuna_rand_digits($count) {
            $out = '';
            for ($i = 0; $i < $count; $i++) $out .= random_int(0, 9);
            return $out;
        }
    }
    if (!function_exists('ilmuna_make_token')) {
        function ilmuna_make_token($nis, $nisn) {
            $nisP  = str_pad(substr(preg_replace('/\D/', '', (string)$nis),  0, 8),  8,  '0', STR_PAD_LEFT);
            $nisnP = str_pad(substr(preg_replace('/\D/', '', (string)$nisn), 0, 10), 10, '0', STR_PAD_LEFT);
            return ilmuna_rand_digits(3)
                 . $nisP
                 . ilmuna_rand_digits(4)
                 . $nisnP
                 . ilmuna_rand_digits(5);
        }
    }
    if (!function_exists('ilmuna_generate_unique_token')) {
        function ilmuna_generate_unique_token($pdo, $nis, $nisn) {
            for ($i = 0; $i < 10; $i++) {
                $t = ilmuna_make_token($nis, $nisn);
                $chk = $pdo->prepare("SELECT 1 FROM presensi WHERE id_presensi = ? LIMIT 1");
                $chk->execute([$t]);
                if (!$chk->fetchColumn()) return $t;
            }
            $nisP  = str_pad(substr(preg_replace('/\D/', '', (string)$nis),  0, 8),  8,  '0', STR_PAD_LEFT);
            $nisnP = str_pad(substr(preg_replace('/\D/', '', (string)$nisn), 0, 10), 10, '0', STR_PAD_LEFT);
            return ilmuna_rand_digits(3)
                 . $nisP
                 . ilmuna_rand_digits(4)
                 . $nisnP
                 . substr((string)time(), -5);
        }
    }

    $act = $_POST['action'];

    /* ---------- 1.1 LIST MAPEL BY KELAS ---------- */
    if ($act === 'get_mapel_by_kelas') {
        $idKelas = (int)($_POST['kelas_id'] ?? 0);
        if (!$idKelas) sendRes(false, [], 'Pilih kelas terlebih dahulu.');
        try {
            $stmt = $pdo->prepare("
                SELECT kmp.id AS id_kmp, kmp.id_mata_pelajaran,
                       mp.nama_mata_pelajaran, mp.kode_mapel, mp.semester
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id_kelas = ?
                ORDER BY mp.nama_mata_pelajaran ASC
            ");
            $stmt->execute([$idKelas]);
            sendRes(true, $stmt->fetchAll(PDO::FETCH_ASSOC), 'OK');
        } catch (Throwable $e) {
            sendRes(false, [], 'Gagal memuat mapel: ' . $e->getMessage());
        }
    }

    /* ---------- 1.2 LOAD TOKEN LIST ---------- */
    if ($act === 'load_token_list') {
        $idKmp = (int)($_POST['kmp_id'] ?? 0);
        if (!$idKmp) sendRes(false, [], 'Pilih mata pelajaran terlebih dahulu.');

        try {
            $stmtInfo = $pdo->prepare("
                SELECT kmp.id, kmp.id_kelas, k.nama_kelas, k.tahun_pelajaran,
                       mp.nama_mata_pelajaran, mp.semester,
                       COALESCE(g.nama_guru, 'Guru Pengampu') AS nama_guru
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
                LEFT JOIN guru g ON gmp.id_guru = g.id
                WHERE kmp.id = ?
            ");
            $stmtInfo->execute([$idKmp]);
            $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
            if (!$info) sendRes(false, [], 'Data kelas-mapel tidak ditemukan.');

            $stmt = $pdo->prepare("
                SELECT s.id AS siswa_id, s.nis, s.nisn, s.nama_lengkap, s.foto,
                       pr.id AS pr_id, pr.id_presensi, pr.status,
                       pr.tanggal_created, pr.expired_at
                FROM siswa s
                LEFT JOIN presensi pr
                       ON pr.id_siswa = s.id
                      AND pr.id_kelas_mata_pelajaran = ?
                WHERE s.id_kelas = ?
                ORDER BY s.nama_lengkap ASC
            ");
            $stmt->execute([$idKmp, $info['id_kelas']]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $now = time();
            $stats = ['total' => count($rows), 'aktif' => 0, 'expired' => 0, 'nonaktif' => 0, 'missing' => 0, 'legacy' => 0];
            foreach ($rows as &$r) {
                if (empty($r['pr_id'])) {
                    $r['state'] = 'missing'; $r['state_label'] = 'Belum Ada';
                    $r['state_cls'] = 'missing'; $stats['missing']++;
                    $r['is_legacy'] = false;
                } else {
                    $r['is_legacy'] = !preg_match('/^\d{30}$/', $r['id_presensi']);
                    if ($r['is_legacy']) $stats['legacy']++;

                    $isActive  = $r['status'] === 'aktif';
                    $isExpired = !empty($r['expired_at']) && strtotime($r['expired_at']) < $now;
                    if (!$isActive) {
                        $r['state'] = 'nonaktif'; $r['state_label'] = 'Nonaktif';
                        $r['state_cls'] = 'nonaktif'; $stats['nonaktif']++;
                    } elseif ($isExpired) {
                        $r['state'] = 'expired'; $r['state_label'] = 'Kedaluwarsa';
                        $r['state_cls'] = 'expired'; $stats['expired']++;
                    } else {
                        $r['state'] = 'aktif'; $r['state_label'] = 'Aktif';
                        $r['state_cls'] = 'aktif'; $stats['aktif']++;
                    }
                }
            }
            unset($r);

            ob_start();
            ?>
            <?php if ($stats['legacy'] > 0): ?>
                <div class="ilmuna-izin-empty" style="background:#FFFBEB; border-color:#FDE68A; margin-bottom:12px;">
                    <iconify-icon icon="solar:danger-triangle-bold" width="36" style="color:#D97706; margin-bottom:6px;"></iconify-icon>
                    <p style="color:#92400E;"><?= $stats['legacy'] ?> token masih dalam format lama</p>
                    <small style="color:#B45309;">Klik <strong>Regenerate Semua</strong> untuk migrasi ke format 30 digit.</small>
                </div>
            <?php endif; ?>

            <div class="ilmuna-token-stats">
                <div class="ilmuna-token-stat"><span class="num"><?= $stats['total'] ?></span><span class="lbl">Total Siswa</span></div>
                <div class="ilmuna-token-stat is-aktif"><span class="num"><?= $stats['aktif'] ?></span><span class="lbl">Token Aktif</span></div>
                <div class="ilmuna-token-stat is-expired"><span class="num"><?= $stats['expired'] ?></span><span class="lbl">Kedaluwarsa</span></div>
                <div class="ilmuna-token-stat is-nonaktif"><span class="num"><?= $stats['nonaktif'] ?></span><span class="lbl">Nonaktif</span></div>
                <div class="ilmuna-token-stat is-missing"><span class="num"><?= $stats['missing'] ?></span><span class="lbl">Belum Ada</span></div>
            </div>
            <div class="ilmuna-abs-table-wrap">
                <table class="ilmuna-token-table">
                    <thead>
                        <tr>
                            <th class="col-no">#</th>
                            <th class="col-siswa">Siswa</th>
                            <th>Token Absensi</th>
                            <th>Status</th>
                            <th>Dibuat</th>
                            <th>Berlaku Sampai</th>
                            <th class="col-aksi">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7" class="empty-state">
                                <iconify-icon icon="solar:users-group-rounded-bold" width="42"></iconify-icon>
                                <p>Belum ada siswa di rombel ini</p>
                            </td></tr>
                        <?php else: $no = 1; foreach ($rows as $r): ?>
                            <tr data-siswa-id="<?= (int)$r['siswa_id'] ?>"
                                data-presensi-id="<?= (int)($r['pr_id'] ?? 0) ?>"
                                data-nama="<?= htmlspecialchars($r['nama_lengkap']) ?>"
                                data-nisn="<?= htmlspecialchars($r['nisn'] ?: $r['nis']) ?>"
                                data-token="<?= htmlspecialchars($r['id_presensi'] ?? '') ?>"
                                data-status="<?= htmlspecialchars($r['state']) ?>">
                                <td class="col-no"><?= $no++ ?></td>
                                <td class="col-siswa">
                                    <div class="siswa-mini">
                                        <div class="siswa-mini__avatar">
                                            <?php if (!empty($r['foto'])): ?>
                                                <img src="<?= htmlspecialchars(BASE_URL . '/' . $r['foto']) ?>" alt="">
                                            <?php else: ?>
                                                <?= strtoupper(mb_substr($r['nama_lengkap'], 0, 1)) ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="siswa-mini__info">
                                            <strong><?= htmlspecialchars($r['nama_lengkap']) ?></strong>
                                            <span><?= htmlspecialchars($r['nisn'] ?: $r['nis']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($r['id_presensi'])): ?>
                                        <span class="ilmuna-token-code"><?= htmlspecialchars($r['id_presensi']) ?></span>
                                        <button type="button" class="ilmuna-token-copy" data-act="copy-token"
                                                title="Salin token" data-token="<?= htmlspecialchars($r['id_presensi']) ?>">
                                            <iconify-icon icon="solar:copy-bold" width="13"></iconify-icon>
                                        </button>
                                        <?php if (!empty($r['is_legacy'])): ?>
                                            <span style="display:inline-block; margin-left:6px; font-size:9.5px;
                                                         font-weight:800; color:#92400E; background:#FEF3C7;
                                                         padding:3px 7px; border-radius:5px;
                                                         text-transform:uppercase; letter-spacing:.04em;"
                                                  title="Format lama — perlu Regenerate untuk migrasi">
                                                Lama
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="ilmuna-token-empty">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="ilmuna-token-badge ilmuna-token-badge--<?= $r['state_cls'] ?>">
                                        <?= htmlspecialchars($r['state_label']) ?>
                                    </span>
                                </td>
                                <td class="col-time">
                                    <?= !empty($r['tanggal_created']) ? date('d M Y H:i', strtotime($r['tanggal_created'])) : '—' ?>
                                </td>
                                <td class="col-time">
                                    <?= !empty($r['expired_at']) ? date('d M Y H:i', strtotime($r['expired_at'])) : '—' ?>
                                </td>
                                <td class="col-aksi">
                                    <?php if (empty($r['id_presensi'])): ?>
                                        <button type="button" class="ilmuna-token-btn ilmuna-token-btn--gen" data-act="generate-one">
                                            <iconify-icon icon="solar:key-minimalistic-bold" width="12"></iconify-icon>
                                            Generate
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="ilmuna-token-btn ilmuna-token-btn--regen" data-act="regenerate-one">
                                            <iconify-icon icon="solar:refresh-bold" width="12"></iconify-icon>
                                            Regenerate
                                        </button>
                                        <button type="button" class="ilmuna-token-btn ilmuna-token-btn--toggle" data-act="toggle-one">
                                            <?php if ($r['status'] === 'aktif'): ?>
                                                <iconify-icon icon="solar:pause-circle-bold" width="12"></iconify-icon>
                                                Nonaktifkan
                                            <?php else: ?>
                                                <iconify-icon icon="solar:play-circle-bold" width="12"></iconify-icon>
                                                Aktifkan
                                            <?php endif; ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $html = ob_get_clean();
            sendRes(true, ['html' => $html, 'stats' => $stats, 'kmp' => $info], 'OK');
        } catch (Throwable $e) {
            sendRes(false, [], 'Gagal memuat token: ' . $e->getMessage());
        }
    }

    /* ---------- 1.3 GENERATE BULK (per mapel) ---------- */
    if ($act === 'generate_tokens') {
        $idKmp    = (int)($_POST['kmp_id'] ?? 0);
        $mode     = $_POST['mode'] ?? 'missing';
        $duration = (int)($_POST['duration_days'] ?? 30);
        if (!$idKmp) sendRes(false, [], 'Pilih mata pelajaran dulu.');
        if (!in_array($mode, ['missing', 'all'], true)) $mode = 'missing';
        if ($duration < 1 || $duration > 365) $duration = 30;

        try {
            $stmtInfo = $pdo->prepare("SELECT id, id_kelas FROM kelas_mata_pelajaran WHERE id = ?");
            $stmtInfo->execute([$idKmp]);
            $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
            if (!$info) sendRes(false, [], 'Data kelas-mapel tidak ditemukan.');

            $stmtSiswa = $pdo->prepare("
                SELECT id, nisn, nis, nama_lengkap FROM siswa WHERE id_kelas = ?
                ORDER BY nama_lengkap ASC
            ");
            $stmtSiswa->execute([$info['id_kelas']]);
            $siswaList = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);
            if (empty($siswaList)) sendRes(false, [], 'Tidak ada siswa di rombel ini.');

            $expiredAt = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
            $inserted = 0; $regenerated = 0; $skipped = 0;

            $pdo->beginTransaction();
            try {
                foreach ($siswaList as $s) {
                    $chk = $pdo->prepare("SELECT id FROM presensi WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ? LIMIT 1");
                    $chk->execute([(int)$s['id'], $idKmp]);
                    $existing = $chk->fetchColumn();

                    if ($existing) {
                        if ($mode === 'all') {
                            $newToken = ilmuna_generate_unique_token($pdo, $s['nis'], $s['nisn']);
                            $upd = $pdo->prepare("
                                UPDATE presensi
                                   SET id_presensi = ?, tanggal_created = NOW(),
                                       expired_at = ?, status = 'aktif'
                                 WHERE id = ?
                            ");
                            $upd->execute([$newToken, $expiredAt, (int)$existing]);
                            $regenerated++;
                        } else {
                            $skipped++;
                        }
                    } else {
                        $newToken = ilmuna_generate_unique_token($pdo, $s['nis'], $s['nisn']);
                        $ins = $pdo->prepare("
                            INSERT INTO presensi
                                (id_presensi, id_siswa, id_kelas_mata_pelajaran,
                                 tanggal_created, expired_at, status)
                            VALUES (?, ?, ?, NOW(), ?, 'aktif')
                        ");
                        $ins->execute([$newToken, (int)$s['id'], $idKmp, $expiredAt]);
                        $inserted++;
                    }
                }
                $pdo->commit();
            } catch (Throwable $txe) {
                $pdo->rollBack();
                throw $txe;
            }

            $msg = "Berhasil: {$inserted} token baru";
            if ($regenerated > 0) $msg .= ", {$regenerated} di-regenerate";
            if ($skipped > 0)     $msg .= ", {$skipped} dilewati";
            $msg .= " dari " . count($siswaList) . " siswa.";

            sendRes(true, [
                'inserted' => $inserted, 'regenerated' => $regenerated, 'skipped' => $skipped,
            ], $msg);
        } catch (Throwable $e) {
            sendRes(false, [], 'Gagal generate: ' . $e->getMessage());
        }
    }

    /* ---------- 1.3b GENERATE MASSAL — SEMUA KELAS & MAPEL ---------- */
    if ($act === 'generate_all_kmp') {
        $mode     = $_POST['mode'] ?? 'missing';
        $duration = (int)($_POST['duration_days'] ?? 30);
        if (!in_array($mode, ['missing', 'all'], true)) $mode = 'missing';
        if ($duration < 1 || $duration > 365) $duration = 30;

        try {
            $kmpList = $pdo->query("
                SELECT id, id_kelas FROM kelas_mata_pelajaran ORDER BY id ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            if (empty($kmpList)) {
                sendRes(false, [], 'Belum ada kelas-mapel yang terdaftar.');
            }

            $expiredAt   = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
            $totalSiswa  = 0;
            $inserted    = 0;
            $regenerated = 0;
            $skipped     = 0;
            $processedKmp = 0;
            $emptyKmp     = 0;

            $pdo->beginTransaction();
            try {
                $stmtSiswa = $pdo->prepare("
                    SELECT id, nisn, nis, nama_lengkap
                      FROM siswa WHERE id_kelas = ?
                     ORDER BY nama_lengkap ASC
                ");
                $chk = $pdo->prepare("
                    SELECT id FROM presensi
                     WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ?
                     LIMIT 1
                ");
                $ins = $pdo->prepare("
                    INSERT INTO presensi
                        (id_presensi, id_siswa, id_kelas_mata_pelajaran,
                         tanggal_created, expired_at, status)
                    VALUES (?, ?, ?, NOW(), ?, 'aktif')
                ");
                $upd = $pdo->prepare("
                    UPDATE presensi
                       SET id_presensi = ?, tanggal_created = NOW(),
                           expired_at = ?, status = 'aktif'
                     WHERE id = ?
                ");

                foreach ($kmpList as $kmp) {
                    $stmtSiswa->execute([(int)$kmp['id_kelas']]);
                    $siswaList = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);

                    if (empty($siswaList)) {
                        $emptyKmp++;
                        continue;
                    }
                    $processedKmp++;

                    foreach ($siswaList as $s) {
                        $totalSiswa++;
                        $chk->execute([(int)$s['id'], (int)$kmp['id']]);
                        $existing = $chk->fetchColumn();

                        if ($existing) {
                            if ($mode === 'all') {
                                $newToken = ilmuna_generate_unique_token($pdo, $s['nis'], $s['nisn']);
                                $upd->execute([$newToken, $expiredAt, (int)$existing]);
                                $regenerated++;
                            } else {
                                $skipped++;
                            }
                        } else {
                            $newToken = ilmuna_generate_unique_token($pdo, $s['nis'], $s['nisn']);
                            $ins->execute([$newToken, (int)$s['id'], (int)$kmp['id'], $expiredAt]);
                            $inserted++;
                        }
                    }
                }
                $pdo->commit();
            } catch (Throwable $txe) {
                $pdo->rollBack();
                throw $txe;
            }

            $msg = "Massal selesai — {$processedKmp} kelas-mapel, {$totalSiswa} siswa diproses: "
                 . "{$inserted} token baru";
            if ($regenerated > 0) $msg .= ", {$regenerated} di-regenerate";
            if ($skipped > 0)     $msg .= ", {$skipped} dilewati";
            if ($emptyKmp > 0)    $msg .= ", {$emptyKmp} kelas-mapel tanpa siswa";
            $msg .= '.';

            sendRes(true, [
                'inserted'      => $inserted,
                'regenerated'   => $regenerated,
                'skipped'       => $skipped,
                'total_siswa'   => $totalSiswa,
                'processed_kmp' => $processedKmp,
                'empty_kmp'     => $emptyKmp,
                'total_kmp'     => count($kmpList),
            ], $msg);
        } catch (Throwable $e) {
            sendRes(false, [], 'Gagal generate massal: ' . $e->getMessage());
        }
    }

    /* ---------- 1.4 SINGLE ACTION ---------- */
    if ($act === 'single_token_action') {
        $sub      = $_POST['sub'] ?? '';
        $siswaId  = (int)($_POST['siswa_id'] ?? 0);
        $idKmp    = (int)($_POST['kmp_id'] ?? 0);
        $duration = (int)($_POST['duration_days'] ?? 30);
        if ($duration < 1 || $duration > 365) $duration = 30;
        if (!$siswaId || !$idKmp || !in_array($sub, ['generate','regenerate','toggle'], true)) {
            sendRes(false, [], 'Parameter tidak lengkap.');
        }

        try {
            $stmtSiswa = $pdo->prepare("SELECT id, nisn, nis, nama_lengkap FROM siswa WHERE id = ? LIMIT 1");
            $stmtSiswa->execute([$siswaId]);
            $siswa = $stmtSiswa->fetch(PDO::FETCH_ASSOC);
            if (!$siswa) sendRes(false, [], 'Siswa tidak ditemukan.');

            $chk = $pdo->prepare("SELECT id, status FROM presensi WHERE id_siswa = ? AND id_kelas_mata_pelajaran = ? LIMIT 1");
            $chk->execute([$siswaId, $idKmp]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($sub === 'generate' && !$existing) {
                $newToken = ilmuna_generate_unique_token($pdo, $siswa['nis'], $siswa['nisn']);
                $expiredAt = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
                $ins = $pdo->prepare("
                    INSERT INTO presensi
                        (id_presensi, id_siswa, id_kelas_mata_pelajaran,
                         tanggal_created, expired_at, status)
                    VALUES (?, ?, ?, NOW(), ?, 'aktif')
                ");
                $ins->execute([$newToken, $siswaId, $idKmp, $expiredAt]);
                sendRes(true, ['token' => $newToken], 'Token berhasil di-generate.');
            }

            if ($sub === 'regenerate') {
                if (!$existing) sendRes(false, [], 'Token belum ada. Generate dulu.');
                $newToken = ilmuna_generate_unique_token($pdo, $siswa['nis'], $siswa['nisn']);
                $expiredAt = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
                $upd = $pdo->prepare("
                    UPDATE presensi
                       SET id_presensi = ?, tanggal_created = NOW(),
                           expired_at = ?, status = 'aktif'
                     WHERE id = ?
                ");
                $upd->execute([$newToken, $expiredAt, (int)$existing['id']]);
                sendRes(true, ['token' => $newToken], 'Token berhasil di-regenerate.');
            }

            if ($sub === 'toggle') {
                if (!$existing) sendRes(false, [], 'Token belum ada.');
                $newStatus = $existing['status'] === 'aktif' ? 'nonaktif' : 'aktif';
                $upd = $pdo->prepare("UPDATE presensi SET status = ? WHERE id = ?");
                $upd->execute([$newStatus, (int)$existing['id']]);
                sendRes(true, ['status' => $newStatus], 'Status token diubah menjadi ' . $newStatus . '.');
            }

            sendRes(false, [], 'Aksi tidak valid.');
        } catch (Throwable $e) {
            sendRes(false, [], 'Gagal memproses: ' . $e->getMessage());
        }
    }

    sendRes(false, [], 'Aksi tidak dikenali: ' . $act);
}

/* ==============================================================================
 * 2. MASTER DATA
 * ============================================================================== */
$kelasList = $pdo->query("
    SELECT id, nama_kelas, tahun_pelajaran
    FROM kelas ORDER BY nama_kelas ASC
")->fetchAll(PDO::FETCH_ASSOC);

$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-attendance-monitor.css')
    ? filemtime(BASE_PATH . '/assets/css/admin-attendance-monitor.css') : '1.0';

$selfUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
if (!$selfUrl) $selfUrl = BASE_URL . '/dashboard/admin/presensi-token';

/* URL absolut ke backend Excel terpisah */
$excelUrl = BASE_URL . '/dashboard/admin/presensi-token-excel.php';

$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-assessment-access.css?v=' . $cssVersion . '">
<style>
.ilmuna-abs-filter-card { background:#FFF; border:1px solid #E2E8F0; border-radius:14px; padding:18px 20px; margin-bottom:20px;
    box-shadow:0 2px 8px -3px rgba(15,23,42,.06); }
.ilmuna-abs-filter-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.ilmuna-abs-filter-label { display:block; font-size:11.5px; font-weight:700; color:#1D3B5E; margin-bottom:6px;
    text-transform:uppercase; letter-spacing:.05em; }
.ilmuna-abs-filter-label .req { color:#EF4444; }
.ilmuna-abs-select { width:100%; padding:10px 14px; border:1.5px solid #CBD5E1; border-radius:10px; background:#FFF;
    font-family:inherit; font-size:13.5px; font-weight:600; color:#1E293B; outline:none; transition:all .18s ease; }
.ilmuna-abs-select:focus { border-color:#0D7A87; box-shadow:0 0 0 3px rgba(13,122,135,.12); }
.ilmuna-abs-select:disabled { background:#F8FAFC; color:#94A3B8; cursor:not-allowed; }
.ilmuna-abs-actions { display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;
    padding-bottom:14px; margin-bottom:14px; border-bottom:1px dashed #E2E8F0; }

/* ---- BANNER AKSI MASSAL ---- */
.ilmuna-mass-banner { display:flex; gap:14px; align-items:center; justify-content:space-between;
    flex-wrap:wrap; padding:14px 16px; margin-bottom:14px; border-radius:12px;
    background:linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%);
    border:1.5px solid #F59E0B; box-shadow:0 3px 10px -4px rgba(245,158,11,.35); }
.ilmuna-mass-banner-text { display:flex; flex-direction:column; gap:2px; min-width:220px; }
.ilmuna-mass-banner-text strong { font-size:13.5px; font-weight:800; color:#78350F;
    display:flex; align-items:center; gap:6px; }
.ilmuna-mass-banner-text strong iconify-icon { color:#B45309; }
.ilmuna-mass-banner-text span { font-size:12px; color:#92400E; font-weight:600; }
.ilmuna-mass-banner-actions { display:flex; gap:8px; flex-wrap:wrap; }

.ilmuna-token-stats { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px; }
.ilmuna-token-stat { display:flex; flex-direction:column; align-items:center; padding:10px 18px;
    background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; min-width:100px; }
.ilmuna-token-stat .num { font-size:20px; font-weight:800; color:#1D3B5E; line-height:1; }
.ilmuna-token-stat .lbl { font-size:10.5px; font-weight:700; color:#64748B;
    margin-top:4px; text-transform:uppercase; letter-spacing:.04em; }
.ilmuna-token-stat.is-aktif    { background:#ECFDF5; border-color:#A7F3D0; }
.ilmuna-token-stat.is-aktif .num    { color:#065F46; }
.ilmuna-token-stat.is-expired  { background:#FEF2F2; border-color:#FECACA; }
.ilmuna-token-stat.is-expired .num  { color:#991B1B; }
.ilmuna-token-stat.is-nonaktif { background:#FEF3C7; border-color:#FDE68A; }
.ilmuna-token-stat.is-nonaktif .num { color:#92400E; }
.ilmuna-token-stat.is-missing  { background:#F1F5F9; border-color:#CBD5E1; }
.ilmuna-token-stat.is-missing .num  { color:#475569; }

.ilmuna-abs-table-wrap { overflow-x:auto; background:#FFF; border:1px solid #E2E8F0; border-radius:14px;
    box-shadow:0 2px 8px -3px rgba(15,23,42,.06); }
.ilmuna-token-table { width:100%; border-collapse:separate; border-spacing:0; font-size:13px; }
.ilmuna-token-table thead th { background:#F8FAFC; padding:12px 10px; text-align:center; font-weight:800;
    color:#1D3B5E; font-size:11px; text-transform:uppercase; letter-spacing:.04em;
    border-bottom:2px solid #E2E8F0; position:sticky; top:0; z-index:2; }
.ilmuna-token-table thead th.col-siswa { text-align:left; padding-left:20px; min-width:220px;
    position:sticky; left:0; z-index:3; background:#F8FAFC; border-right:2px solid #E2E8F0; }
.ilmuna-token-table thead th.col-no { width:36px; }
.ilmuna-token-table thead th.col-aksi { min-width:180px; }
.ilmuna-token-table tbody td { padding:10px; text-align:center; border-bottom:1px solid #F1F5F9; vertical-align:middle; }
.ilmuna-token-table tbody td.col-siswa { text-align:left; padding-left:20px;
    position:sticky; left:0; z-index:1; background:#FFF; border-right:2px solid #E2E8F0; }
.ilmuna-token-table tbody tr:hover td.col-siswa { background:#F8FAFC; }
.ilmuna-token-table tbody td.col-no { color:#94A3B8; font-weight:700; font-size:12px; }
.ilmuna-token-table tbody td.col-time { font-size:11.5px; color:#64748B; }

.siswa-mini { display:flex; align-items:center; gap:10px; }
.siswa-mini__avatar { width:34px; height:34px; border-radius:10px;
    background:linear-gradient(135deg,#1D3B5E 0%,#0D7A87 100%); color:#FFF;
    display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px;
    flex-shrink:0; overflow:hidden; }
.siswa-mini__avatar img { width:100%; height:100%; object-fit:cover; }
.siswa-mini__info { display:flex; flex-direction:column; min-width:0; }
.siswa-mini__info strong { font-size:12.5px; color:#1E293B; font-weight:700;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.siswa-mini__info span { font-size:10.5px; color:#64748B; font-family:monospace; }

.empty-state { padding:48px 20px; text-align:center; color:#94A3B8; }
.empty-state iconify-icon { color:#CBD5E1; display:block; margin:0 auto 8px; }
.empty-state p { margin:0; font-size:13px; font-weight:600; }

.ilmuna-izin-empty { text-align:center; padding:40px 20px; background:#ECFDF5; border:1.5px dashed #A7F3D0;
    border-radius:12px; color:#64748B; }
.ilmuna-izin-empty iconify-icon { color:#10B981; display:block; margin:0 auto 10px; }
.ilmuna-izin-empty p { margin:0 0 4px; font-size:14px; font-weight:700; color:#065F46; }
.ilmuna-izin-empty small { font-size:12px; color:#10B981; }

.ilmuna-token-code { display:inline-flex; align-items:center; gap:6px;
    font-family:ui-monospace, "SF Mono", Menlo, monospace;
    font-size:12px; font-weight:800; letter-spacing:.06em;
    padding:6px 12px; background:#0F172A; color:#FACC15; border-radius:8px; }
.ilmuna-token-empty { color:#CBD5E1; font-size:14px; font-weight:700; }
.ilmuna-token-copy { display:inline-flex; align-items:center; justify-content:center;
    width:26px; height:26px; margin-left:6px; border-radius:6px;
    background:#F1F5F9; border:1px solid #E2E8F0; color:#475569; cursor:pointer;
    transition:all .15s ease; vertical-align:middle; }
.ilmuna-token-copy:hover { background:#0D7A87; color:#FFF; border-color:#0D7A87; }
.ilmuna-token-copy.is-copied { background:#10B981; color:#FFF; border-color:#10B981; }

.ilmuna-token-badge { display:inline-block; font-size:10px; font-weight:800;
    padding:4px 10px; border-radius:6px; text-transform:uppercase; letter-spacing:.04em; }
.ilmuna-token-badge--aktif    { background:#DCFCE7; color:#166534; }
.ilmuna-token-badge--expired  { background:#FEE2E2; color:#991B1B; }
.ilmuna-token-badge--nonaktif { background:#FEF3C7; color:#92400E; }
.ilmuna-token-badge--missing  { background:#E2E8F0; color:#475569; }

.ilmuna-token-btn { display:inline-flex; align-items:center; gap:4px;
    padding:5px 10px; font-size:11px; font-weight:700; border-radius:7px;
    font-family:inherit; cursor:pointer; border:1.5px solid transparent;
    transition:all .15s ease; margin:2px; }
.ilmuna-token-btn:disabled { opacity:.55; pointer-events:none; }
.ilmuna-token-btn--gen { background:#ECFDF5; color:#065F46; border-color:#A7F3D0; }
.ilmuna-token-btn--gen:hover { background:#10B981; color:#FFF; }
.ilmuna-token-btn--regen { background:#EFF6FF; color:#1E40AF; border-color:#BFDBFE; }
.ilmuna-token-btn--regen:hover { background:#3B82F6; color:#FFF; }
.ilmuna-token-btn--toggle { background:#FEF3C7; color:#92400E; border-color:#FDE68A; }
.ilmuna-token-btn--toggle:hover { background:#F59E0B; color:#FFF; }

/* ========== TOAST ========== */
.ilmuna-toast-wrap { position:fixed; top:80px; right:20px; z-index:999998; display:flex;
    flex-direction:column; gap:10px; max-width:340px; pointer-events:none; }
.ilmuna-toast-item { background:#FFF; border-left:4px solid #10B981; border-radius:12px;
    box-shadow:0 12px 32px -8px rgba(0,0,0,.22); padding:12px 16px; font-family:inherit;
    pointer-events:auto; opacity:0; transform:translateX(20px); transition:all .3s ease; }
.ilmuna-toast-item.show { opacity:1; transform:translateX(0); }
.ilmuna-toast-item.is-error { border-left-color:#EF4444; }
.ilmuna-toast-item.is-warn { border-left-color:#F59E0B; }
.ilmuna-toast-item.is-info { border-left-color:#3B82F6; }
.ilmuna-toast-title { font-size:13px; font-weight:800; color:#1E293B; margin-bottom:3px; }
.ilmuna-toast-msg { font-size:12px; color:#475569; line-height:1.4; }

/* ========== DIALOG BERTEMA ========== */
.ilmuna-dialog { position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,.68);
    backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center;
    padding:20px; opacity:0; pointer-events:none; transition:opacity .22s ease; }
.ilmuna-dialog.is-open { opacity:1; pointer-events:auto; }
.ilmuna-dialog-box { background:#FFF; border-radius:16px; overflow:hidden; max-width:440px; width:100%;
    box-shadow:0 24px 60px -20px rgba(0,0,0,.55); transform:scale(.94);
    transition:transform .28s cubic-bezier(0.16, 1, 0.3, 1); }
.ilmuna-dialog.is-open .ilmuna-dialog-box { transform:scale(1); }
.ilmuna-dialog-body { padding:26px 24px 12px; text-align:center; }
.ilmuna-dialog-icon { width:64px; height:64px; border-radius:50%; margin:0 auto 16px;
    display:flex; align-items:center; justify-content:center; }
.ilmuna-dialog-icon--info { background:linear-gradient(135deg,#DBEAFE,#BFDBFE); color:#1E40AF; }
.ilmuna-dialog-icon--warn { background:linear-gradient(135deg,#FEF3C7,#FDE68A); color:#B45309; }
.ilmuna-dialog-icon--danger { background:linear-gradient(135deg,#FEE2E2,#FECACA); color:#DC2626; }
.ilmuna-dialog-icon--success { background:linear-gradient(135deg,#DCFCE7,#BBF7D0); color:#15803D; }
.ilmuna-dialog-title { font-size:17px; font-weight:800; color:#1E293B; margin:0 0 8px; line-height:1.3; }
.ilmuna-dialog-msg { font-size:13.5px; color:#475569; line-height:1.55; margin:0 0 12px; }
.ilmuna-dialog-msg strong { color:#1E293B; font-weight:700; }
.ilmuna-dialog-actions { display:flex; gap:10px; padding:18px 24px 22px; }
.ilmuna-dialog-btn { flex:1; padding:11px 16px; border-radius:10px; font-family:inherit;
    font-size:13.5px; font-weight:700; cursor:pointer; border:1.5px solid transparent;
    transition:all .18s ease; display:inline-flex; align-items:center; justify-content:center; gap:6px; }
.ilmuna-dialog-btn--cancel { background:#F1F5F9; color:#475569; border-color:#E2E8F0; }
.ilmuna-dialog-btn--cancel:hover { background:#E2E8F0; color:#1E293B; }
.ilmuna-dialog-btn--ok { background:linear-gradient(135deg,#F59E0B,#D97706); color:#FFF;
    box-shadow:0 4px 14px -4px rgba(245,158,11,.5); }
.ilmuna-dialog-btn--ok:hover { transform:translateY(-1px); box-shadow:0 8px 20px -4px rgba(245,158,11,.55); }
.ilmuna-dialog-btn--success { background:linear-gradient(135deg,#10B981,#059669); color:#FFF;
    box-shadow:0 4px 14px -4px rgba(16,185,129,.5); }
.ilmuna-dialog-btn--success:hover { transform:translateY(-1px); box-shadow:0 8px 20px -4px rgba(16,185,129,.55); }
.ilmuna-dialog-btn--danger { background:linear-gradient(135deg,#EF4444,#DC2626); color:#FFF;
    box-shadow:0 4px 14px -4px rgba(239,68,68,.5); }
.ilmuna-dialog-btn--danger:hover { transform:translateY(-1px); box-shadow:0 8px 20px -4px rgba(239,68,68,.55); }

/* ========== PRINT STYLES ========== */
#ilmunaPrintContainer { display:none; }
@media print {
    @page { size: A4; margin: 12mm; }
    body > *:not(#ilmunaPrintContainer) { display: none !important; }
    #ilmunaPrintContainer { display: block !important; }

    .print-header {
        text-align: center; font-size: 14px; font-weight: 900;
        color: #1D3B5E; margin-bottom: 10px; padding-bottom: 6px;
        border-bottom: 2px solid #0D7A87;
    }
    .print-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8mm; }
    .print-card { border: 2px dashed #1D3B5E; border-radius: 8px;
        padding: 10px 12px; page-break-inside: avoid;
        font-family: "Segoe UI", Arial, sans-serif; }
    .print-card .card-head { font-size: 11px; font-weight: 900; letter-spacing: 2px;
        color: #0D7A87; text-align: center; border-bottom: 1px solid #CBD5E1;
        padding-bottom: 4px; margin-bottom: 6px; }
    .print-card .lbl { font-size: 8px; text-transform: uppercase; letter-spacing: .08em;
        color: #64748B; font-weight: 700; margin-top: 6px; }
    .print-card .val { font-size: 12px; font-weight: 800; color: #1E293B; }
    .print-card .val.nama { font-size: 13px; }
    .print-card .val.small { font-size: 10px; font-weight: 600; }
    .print-card .token { margin-top: 8px; padding: 8px 6px; text-align: center;
        background: #0F172A; color: #FACC15; border-radius: 6px;
        font-family: "Courier New", monospace;
        font-size: 13px; font-weight: 900; letter-spacing: 1px;
        word-break: break-all; }
    .print-card .hint { font-size: 8.5px; color: #64748B; margin-top: 6px; line-height: 1.3; }
}
</style>';

require_once BASE_PATH . '/includes/header.php';
?>

<div class="ilmuna-results-app" id="appToken"
     data-ajax="<?= htmlspecialchars($selfUrl) ?>"
     data-excel="<?= htmlspecialchars($excelUrl) ?>">

    <!-- FILTER CARD -->
    <div class="ilmuna-abs-filter-card">

        <!-- BANNER AKSI MASSAL -->
        <div class="ilmuna-mass-banner">
            <div class="ilmuna-mass-banner-text">
                <strong>
                    <iconify-icon icon="solar:bolt-circle-bold" width="18"></iconify-icon>
                    Aksi Massal — Semua Kelas &amp; Mapel
                </strong>
                <span>Proses token untuk seluruh kombinasi kelas × mata pelajaran sekaligus.</span>
            </div>
            <div class="ilmuna-mass-banner-actions">
                <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="btnGenerateAllKmp">
                    <iconify-icon icon="solar:key-minimalistic-bold" width="16"></iconify-icon>
                    <span>Generate (Belum Ada)</span>
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnRegenerateAllKmp">
                    <iconify-icon icon="solar:refresh-square-bold" width="16"></iconify-icon>
                    <span>Regenerate Semua</span>
                </button>
            </div>
        </div>

        <!-- AKSI PER MAPEL -->
        <div class="ilmuna-abs-actions">
            <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="btnGenerateMissing" disabled>
                <iconify-icon icon="solar:key-minimalistic-bold" width="16"></iconify-icon>
                <span>Generate (Belum Ada)</span>
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnGenerateAll" disabled>
                <iconify-icon icon="solar:refresh-square-bold" width="16"></iconify-icon>
                <span>Regenerate Semua (Mapel Ini)</span>
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--emerald" id="btnExportTokens" disabled>
                <iconify-icon icon="solar:file-spreadsheet-bold" width="16"></iconify-icon>
                <span>Export Excel</span>
            </button>
            <button type="button" class="ilmuna-btn ilmuna-btn--outline" id="btnPrintTokens" disabled>
                <iconify-icon icon="solar:printer-bold" width="16"></iconify-icon>
                <span>Print Kartu</span>
            </button>
        </div>

        <div class="ilmuna-abs-filter-row">
            <div style="flex: 1; min-width: 220px;">
                <label class="ilmuna-abs-filter-label">1. Rombel Kelas <span class="req">*</span></label>
                <select id="selectKelas" class="ilmuna-abs-select">
                    <option value="">— Pilih Rombel Kelas —</option>
                    <?php foreach ($kelasList as $k): ?>
                        <option value="<?= (int)$k['id'] ?>">
                            <?= htmlspecialchars($k['nama_kelas']) ?> (<?= htmlspecialchars($k['tahun_pelajaran']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="flex: 1; min-width: 220px;">
                <label class="ilmuna-abs-filter-label">2. Mata Pelajaran <span class="req">*</span></label>
                <select id="selectMapel" class="ilmuna-abs-select" disabled>
                    <option value="">— Pilih Kelas Dahulu —</option>
                </select>
            </div>
            <div style="flex: 0 0 180px;">
                <label class="ilmuna-abs-filter-label">Durasi Berlaku</label>
                <select id="selectDuration" class="ilmuna-abs-select">
                    <option value="7">7 hari</option>
                    <option value="30" selected>30 hari</option>
                    <option value="90">3 bulan</option>
                    <option value="180">6 bulan</option>
                    <option value="365">1 tahun</option>
                </select>
            </div>
        </div>
    </div>

    <!-- TOKEN LIST -->
    <div id="tokenListContainer">
        <div class="ilmuna-izin-empty">
            <iconify-icon icon="solar:key-minimalistic-bold" width="44"></iconify-icon>
            <p>Pilih Kelas &amp; Mata Pelajaran</p>
            <small>Generate token per siswa (30 digit), lalu cetak/bagikan ke siswa untuk absen mandiri.</small>
        </div>
    </div>

</div>

<!-- DIALOG BERTEMA -->
<div class="ilmuna-dialog" id="dialogModal">
    <div class="ilmuna-dialog-box">
        <div class="ilmuna-dialog-body">
            <div class="ilmuna-dialog-icon ilmuna-dialog-icon--info" id="dialogIcon">
                <iconify-icon icon="solar:info-circle-bold" width="32"></iconify-icon>
            </div>
            <h3 class="ilmuna-dialog-title" id="dialogTitle">Konfirmasi</h3>
            <p class="ilmuna-dialog-msg" id="dialogMsg">—</p>
        </div>
        <div class="ilmuna-dialog-actions">
            <button type="button" class="ilmuna-dialog-btn ilmuna-dialog-btn--cancel" id="dialogCancel">Batal</button>
            <button type="button" class="ilmuna-dialog-btn ilmuna-dialog-btn--ok" id="dialogOk">OK</button>
        </div>
    </div>
</div>

<!-- PRINT CONTAINER -->
<div id="ilmunaPrintContainer">
    <div class="print-header" id="printHeader">Kartu Token Absensi</div>
    <div class="print-grid" id="printGrid"></div>
</div>

<div class="ilmuna-toast-wrap" id="toastWrap"></div>

<script>
(function () {
    'use strict';
    var app = document.getElementById('appToken');
    if (!app) return;
    var AJAX_URL  = app.dataset.ajax;
    var EXCEL_URL = app.dataset.excel || AJAX_URL.replace(/[^\/]*$/, 'presensi-token-excel.php');

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function postFD(fd) {
        return fetch(AJAX_URL, {
            method: 'POST', credentials: 'same-origin', body: fd
        }).then(function(r) {
            return r.text().then(function(txt) {
                try { return JSON.parse(txt); }
                catch (err) {
                    console.error('Invalid JSON. AJAX_URL =', AJAX_URL, '| Respons:', txt.slice(0, 500));
                    throw new Error('Server mengembalikan HTML, bukan JSON. Cek AJAX_URL di Network tab.');
                }
            });
        });
    }

    /* ============ TOAST ============ */
    var toastWrap = document.getElementById('toastWrap');
    function toast(type, title, msg) {
        var normType = (type === 'warn' || type === 'warning') ? 'warning' : ((type === 'danger' || type === 'error' || type === 'err') ? 'error' : (type || 'info'));
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, title, msg, 5000);
        }
        var cls = 'ilmuna-toast-item';
        if (type === 'error') cls += ' is-error';
        else if (type === 'warn') cls += ' is-warn';
        else if (type === 'info') cls += ' is-info';
        var el = document.createElement('div');
        el.className = cls;
        el.innerHTML = '<div class="ilmuna-toast-title">' + escHtml(title) + '</div>'
                     + '<div class="ilmuna-toast-msg">' + escHtml(msg) + '</div>';
        toastWrap.appendChild(el);
        requestAnimationFrame(function(){ el.classList.add('show'); });
        setTimeout(function(){
            el.classList.remove('show');
            setTimeout(function(){ el.remove(); }, 350);
        }, 5500);
    }

    /* ============ DIALOG BERTEMA ============ */
    var dialogModal  = document.getElementById('dialogModal');
    var dialogIcon   = document.getElementById('dialogIcon');
    var dialogTitle  = document.getElementById('dialogTitle');
    var dialogMsg    = document.getElementById('dialogMsg');
    var dialogOk     = document.getElementById('dialogOk');
    var dialogCancel = document.getElementById('dialogCancel');
    var dlgResolve   = null;

    var dlgIcons = {
        info:    'solar:info-circle-bold',
        warn:    'solar:danger-triangle-bold',
        danger:  'solar:danger-triangle-bold',
        success: 'solar:check-circle-bold'
    };

    function openDialog(opts) {
        return new Promise(function(resolve) {
            dlgResolve = resolve;
            var type = opts.type || 'info';
            dialogIcon.className = 'ilmuna-dialog-icon ilmuna-dialog-icon--' + type;
            dialogIcon.innerHTML = '<iconify-icon icon="' + (dlgIcons[type] || dlgIcons.info) + '" width="32"></iconify-icon>';
            dialogTitle.textContent = opts.title || 'Konfirmasi';
            dialogMsg.innerHTML = opts.message || '';
            dialogOk.textContent = opts.okText || 'OK';
            dialogOk.className = 'ilmuna-dialog-btn ' + (
                type === 'danger'  ? 'ilmuna-dialog-btn--danger'  :
                type === 'success' ? 'ilmuna-dialog-btn--success' :
                                     'ilmuna-dialog-btn--ok'
            );
            dialogCancel.textContent = opts.cancelText || 'Batal';
            dialogCancel.style.display = opts.showCancel === false ? 'none' : '';
            dialogModal.classList.add('is-open');
            document.body.style.overflow = 'hidden';
            setTimeout(function(){ dialogOk.focus(); }, 200);
        });
    }
    function closeDialog(result) {
        dialogModal.classList.remove('is-open');
        document.body.style.overflow = '';
        var cb = dlgResolve; dlgResolve = null;
        if (cb) cb(result);
    }
    dialogOk.addEventListener('click', function() { closeDialog(true); });
    dialogCancel.addEventListener('click', function() { closeDialog(false); });
    dialogModal.addEventListener('click', function(e) {
        if (e.target === dialogModal) closeDialog(false);
    });
    document.addEventListener('keydown', function(e) {
        if (!dialogModal.classList.contains('is-open')) return;
        if (e.key === 'Escape') { e.preventDefault(); closeDialog(false); }
        if (e.key === 'Enter')  { e.preventDefault(); dialogOk.click(); }
    });

    var dlg = {
        alert: function(msg, opts) {
            opts = opts || {};
            var t = (opts.type === 'warn' || opts.type === 'warning') ? 'warning' : ((opts.type === 'danger' || opts.type === 'error') ? 'error' : (opts.type || 'info'));
            if (window.Ilmuna && typeof window.Ilmuna.alert === 'function') {
                return new Promise(function(resolve) {
                    Ilmuna.alert(t, opts.title || 'Informasi', msg, opts.okText || 'OK', false, function() { resolve(true); });
                });
            }
            return openDialog({
                type:    opts.type || 'info',
                title:   opts.title || 'Informasi',
                message: msg,
                okText:  opts.okText || 'OK',
                showCancel: false
            });
        },
        confirm: function(msg, opts) {
            opts = opts || {};
            var t = (opts.type === 'danger' || opts.type === 'error' || opts.type === 'delete') ? 'error' : ((opts.type === 'warn' || opts.type === 'warning') ? 'warning' : 'warning');
            if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
                return Ilmuna.confirm(t, opts.title || 'Konfirmasi', msg, opts.okText || 'Ya, Lanjutkan', opts.cancelText || 'Batal');
            }
            return openDialog({
                type:     opts.type || 'warn',
                title:    opts.title || 'Konfirmasi',
                message:  msg,
                okText:   opts.okText || 'Ya, Lanjutkan',
                cancelText: opts.cancelText || 'Batal',
                showCancel: true
            });
        }
    };

    /* ============ ELEMENTS ============ */
    var selectKelas        = document.getElementById('selectKelas');
    var selectMapel        = document.getElementById('selectMapel');
    var selectDuration     = document.getElementById('selectDuration');
    var tokenListContainer = document.getElementById('tokenListContainer');
    var btnGenerateMissing = document.getElementById('btnGenerateMissing');
    var btnGenerateAll     = document.getElementById('btnGenerateAll');
    var btnExportTokens    = document.getElementById('btnExportTokens');
    var btnPrintTokens     = document.getElementById('btnPrintTokens');
    var btnGenerateAllKmp  = document.getElementById('btnGenerateAllKmp');
    var btnRegenerateAllKmp = document.getElementById('btnRegenerateAllKmp');
    var currentKmp = 0;

    function setButtons(on) {
        [btnGenerateMissing, btnGenerateAll, btnExportTokens, btnPrintTokens].forEach(function(b) {
            b.disabled = !on;
        });
    }

    function loadMapelOptions(kelasId, targetSelect, onLoaded) {
        targetSelect.innerHTML = '<option value="">— Memuat... —</option>';
        targetSelect.disabled = true;
        var fd = new FormData();
        fd.append('action', 'get_mapel_by_kelas');
        fd.append('kelas_id', kelasId);
        postFD(fd)
            .then(function(res) {
                if (!res.success || !res.data.length) {
                    targetSelect.innerHTML = '<option value="">— Belum Ada Mapel —</option>';
                    return;
                }
                var html = '<option value="">— Pilih Mata Pelajaran —</option>';
                res.data.forEach(function(m) {
                    html += '<option value="' + m.id_kmp + '">' + escHtml(m.nama_mata_pelajaran) + '</option>';
                });
                targetSelect.innerHTML = html;
                targetSelect.disabled = false;
                if (onLoaded) onLoaded();
            })
            .catch(function(err) {
                console.error('[loadMapel]', err);
                targetSelect.innerHTML = '<option value="">— Gagal Memuat —</option>';
                toast('error', 'Gagal Memuat Mapel', err.message || 'Cek console/Network.');
            });
    }

    selectKelas.addEventListener('change', function() {
        var kId = this.value;
        currentKmp = 0;
        setButtons(false);
        if (!kId) {
            selectMapel.innerHTML = '<option value="">— Pilih Kelas Dahulu —</option>';
            selectMapel.disabled = true;
            tokenListContainer.innerHTML = '<div class="ilmuna-izin-empty">'
                + '<iconify-icon icon="solar:key-minimalistic-bold" width="44"></iconify-icon>'
                + '<p>Pilih Kelas Terlebih Dahulu</p>'
                + '<small>Pilih rombel, lalu mapel, untuk mengelola token.</small></div>';
            return;
        }
        loadMapelOptions(kId, selectMapel);
        tokenListContainer.innerHTML = '<div class="ilmuna-izin-empty">'
            + '<iconify-icon icon="solar:key-minimalistic-bold" width="44"></iconify-icon>'
            + '<p>Pilih Mata Pelajaran</p>'
            + '<small>Pilih salah satu mapel untuk melihat daftar token siswa.</small></div>';
    });

    selectMapel.addEventListener('change', function() {
        currentKmp = parseInt(this.value, 10) || 0;
        setButtons(!!currentKmp);
        if (currentKmp) loadTokenList();
    });

    function loadTokenList() {
        if (!currentKmp) return;
        tokenListContainer.innerHTML = '<div style="text-align:center;padding:40px;color:#64748B;">'
            + '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="28"></iconify-icon>'
            + '<p style="margin-top:8px;">Memuat token absensi...</p></div>';
        var fd = new FormData();
        fd.append('action', 'load_token_list');
        fd.append('kmp_id', currentKmp);
        postFD(fd)
            .then(function(res) {
                if (!res.success) {
                    tokenListContainer.innerHTML = '<div class="ilmuna-izin-empty" style="background:#FEF2F2;border-color:#FECACA;">'
                        + '<p style="color:#B91C1C;">Gagal memuat: ' + escHtml(res.message) + '</p></div>';
                    return;
                }
                tokenListContainer.innerHTML = res.data.html;
            })
            .catch(function(err) {
                tokenListContainer.innerHTML = '<div class="ilmuna-izin-empty" style="background:#FEF2F2;border-color:#FECACA;">'
                    + '<p style="color:#B91C1C;">' + escHtml(err.message || 'Koneksi bermasalah') + '</p></div>';
            });
    }

    /* ============================================================
     * AKSI MASSAL — SEMUA KELAS & MAPEL
     * ============================================================ */
    function runMassAction(mode, sourceBtn, originalHTML) {
        sourceBtn.disabled = true;
        sourceBtn.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="16"></iconify-icon>'
                            + '<span>Memproses...</span>';
        btnGenerateAllKmp.disabled = true;
        btnRegenerateAllKmp.disabled = true;

        var fd = new FormData();
        fd.append('action', 'generate_all_kmp');
        fd.append('mode', mode);
        fd.append('duration_days', selectDuration.value);

        postFD(fd)
            .then(function(res) {
                if (!res.success) {
                    toast('error', 'Gagal Massal', res.message || 'Generate massal gagal');
                    return;
                }
                toast('success', 'Generate Massal Selesai', res.message);
                if (currentKmp) loadTokenList();
            })
            .catch(function(err) {
                toast('error', 'Gagal Massal', err.message || 'Koneksi bermasalah');
            })
            .finally(function() {
                btnGenerateAllKmp.disabled = false;
                btnRegenerateAllKmp.disabled = false;
                sourceBtn.innerHTML = originalHTML;
                sourceBtn.disabled = false;
            });
    }

    btnGenerateAllKmp.addEventListener('click', function() {
        var originalHTML = btnGenerateAllKmp.innerHTML;
        dlg.confirm(
            'Generate token untuk <strong>SEMUA KELAS &amp; SEMUA MATA PELAJARAN</strong>?<br><br>'
          + '<small style="color:#64748B;">Sistem akan memproses seluruh kombinasi kelas × mapel. '
          + 'Hanya siswa yang <strong>belum memiliki token</strong> yang akan dibuatkan.</small>',
            { title: 'Generate Massal — Belum Ada', okText: 'Ya, Generate Semua', type: 'warn' }
        ).then(function(ok) {
            if (!ok) return;
            runMassAction('missing', btnGenerateAllKmp, originalHTML);
        });
    });

    btnRegenerateAllKmp.addEventListener('click', function() {
        var originalHTML = btnRegenerateAllKmp.innerHTML;
        dlg.confirm(
            'Regenerate <strong>SEMUA TOKEN</strong> di <strong>SEMUA KELAS &amp; MAPEL</strong> ke format 30 digit?<br><br>'
          + '<small style="color:#991B1B;font-weight:700;">⚠ Semua token lama (di seluruh kelas &amp; mapel) akan HANGUS dan diganti token baru.</small><br>'
          + '<small style="color:#64748B;">Pastikan Anda yakin. Proses mungkin memakan waktu.</small>',
            { title: 'Regenerate Massal — Konfirmasi', okText: 'Ya, Regenerate Semua', type: 'danger' }
        ).then(function(ok) {
            if (!ok) return;
            runMassAction('all', btnRegenerateAllKmp, originalHTML);
        });
    });

    /* ============================================================
     * AKSI PER MAPEL
     * ============================================================ */
    btnGenerateMissing.addEventListener('click', function() { doGenerateTokens('missing'); });

    btnGenerateAll.addEventListener('click', function() {
        dlg.confirm(
            'Regenerate <strong>SEMUA</strong> token untuk mapel ini ke format 30 digit?<br><br>'
          + '<small style="color:#64748B;">Token lama (format apapun) akan hangus dan diganti token baru.</small>',
            { title: 'Regenerate Semua Token?', okText: 'Ya, Regenerate', type: 'warn' }
        ).then(function(ok) {
            if (ok) doGenerateTokens('all');
        });
    });

    function doGenerateTokens(mode) {
        if (!currentKmp) return;
        btnGenerateMissing.disabled = true;
        btnGenerateAll.disabled = true;
        var fd = new FormData();
        fd.append('action', 'generate_tokens');
        fd.append('kmp_id', currentKmp);
        fd.append('mode', mode);
        fd.append('duration_days', selectDuration.value);
        postFD(fd)
            .then(function(res) {
                if (!res.success) { toast('error', 'Gagal', res.message || 'Generate gagal'); return; }
                toast('success', 'Generate Selesai', res.message);
                loadTokenList();
            })
            .catch(function(err) { toast('error', 'Gagal', err.message || 'Koneksi bermasalah'); })
            .finally(function() {
                btnGenerateMissing.disabled = false;
                btnGenerateAll.disabled = false;
            });
    }

    tokenListContainer.addEventListener('click', function(e) {
        var copyBtn = e.target.closest('[data-act="copy-token"]');
        if (copyBtn) {
            var token = copyBtn.dataset.token || '';
            if (!token) return;
            var done = function() {
                copyBtn.classList.add('is-copied');
                setTimeout(function(){ copyBtn.classList.remove('is-copied'); }, 1200);
                toast('success', 'Tersalin', 'Token: ' + token);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(token).then(done).catch(function() { fallbackCopy(token); done(); });
            } else { fallbackCopy(token); done(); }
            return;
        }

        var btn = e.target.closest('.ilmuna-token-btn[data-act]');
        if (!btn) return;
        var act = btn.dataset.act;
        var tr = btn.closest('tr');
        if (!tr) return;
        var siswaId = parseInt(tr.dataset.siswaId, 10) || 0;
        var namaSiswa = tr.dataset.nama || 'siswa';

        var sub = null;
        if (act === 'generate-one')        sub = 'generate';
        else if (act === 'regenerate-one') sub = 'regenerate';
        else if (act === 'toggle-one')     sub = 'toggle';
        if (!sub) return;

        var proceed = function() {
            btn.disabled = true;
            var fd = new FormData();
            fd.append('action', 'single_token_action');
            fd.append('sub', sub);
            fd.append('siswa_id', siswaId);
            fd.append('kmp_id', currentKmp);
            fd.append('duration_days', selectDuration.value);
            postFD(fd)
                .then(function(res) {
                    if (!res.success) { toast('error', 'Gagal', res.message || 'Aksi gagal'); btn.disabled = false; return; }
                    toast('success', 'Berhasil', res.message);
                    loadTokenList();
                })
                .catch(function(err) { toast('error', 'Gagal', err.message || 'Koneksi bermasalah'); btn.disabled = false; });
        };

        if (sub === 'regenerate') {
            dlg.confirm(
                'Regenerate token untuk <strong>' + escHtml(namaSiswa) + '</strong>?<br><br>'
              + '<small style="color:#64748B;">Token lama akan hangus dan diganti token baru.</small>',
                { title: 'Regenerate Token?', okText: 'Ya, Regenerate', type: 'warn' }
            ).then(function(ok) {
                if (ok) proceed();
            });
            return;
        }

        proceed();
    });

    /* ============================================================
     * EXPORT EXCEL (via backend terpisah)
     * ============================================================ */
    btnExportTokens.addEventListener('click', function() {
        if (!currentKmp) return;
        toast('info', 'Menyiapkan Excel', 'File Excel akan segera diunduh...');
        window.location.href = EXCEL_URL
                + '?action=export_excel'
                + '&kmp_id=' + encodeURIComponent(currentKmp);
    });

    /* ============ PRINT KARTU ============ */
    btnPrintTokens.addEventListener('click', function() {
        if (!currentKmp) return;
        var rows = tokenListContainer.querySelectorAll('tr[data-token]');
        var cards = [];
        rows.forEach(function(tr) {
            var token = tr.dataset.token || '';
            if (!token) return;
            cards.push({
                nama:  tr.dataset.nama || '—',
                nisn:  tr.dataset.nisn || '—',
                token: token,
            });
        });
        if (!cards.length) {
            dlg.alert(
                'Belum ada token untuk dicetak.<br>Generate token terlebih dahulu sebelum print kartu.',
                { title: 'Tidak Ada Token', type: 'warn', okText: 'Mengerti' }
            );
            return;
        }

        var kelasText = selectKelas.options[selectKelas.selectedIndex].text;
        var mapelText = selectMapel.options[selectMapel.selectedIndex].text;

        var header = document.getElementById('printHeader');
        header.textContent = 'Kartu Token Absensi — ' + kelasText + ' — ' + mapelText;

        var grid = document.getElementById('printGrid');
        var html = '';
        for (var i = 0; i < cards.length; i++) {
            var c = cards[i];
            html += '<div class="print-card">'
                 +  '<div class="card-head">ILMUNA LEARN</div>'
                 +  '<div class="lbl">Nama Siswa</div>'
                 +  '<div class="val nama">' + escHtml(c.nama) + '</div>'
                 +  '<div class="lbl">NISN / NIS</div>'
                 +  '<div class="val">' + escHtml(c.nisn) + '</div>'
                 +  '<div class="lbl">Kelas / Mapel</div>'
                 +  '<div class="val small">' + escHtml(kelasText) + '<br>' + escHtml(mapelText) + '</div>'
                 +  '<div class="lbl">Token Absensi</div>'
                 +  '<div class="token">' + escHtml(c.token) + '</div>'
                 +  '<div class="hint">Simpan kartu ini. Masukkan token pada halaman absensi untuk mencatat kehadiran.</div>'
                 +  '</div>';
        }
        grid.innerHTML = html;

        toast('success', 'Print Siap', 'Dialog print akan terbuka.');
        setTimeout(function() { window.print(); }, 250);
    });

    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        ta.remove();
    }

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>