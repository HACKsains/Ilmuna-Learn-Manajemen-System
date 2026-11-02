<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MASTER TITIK KOORDINAT GEOFENCING SEKOLAH (ADMIN)
 * ==============================================================================
 * Tabel: titik_koordinat_sekolah
 *
 * ATURAN:
 *  - Edit & Hapus HANYA bisa saat status Nonaktif (UI + server guard)
 *  - Pagination: 10/25/50/100 per halaman
 *  - Setelah toggle/edit/delete → tetap di halaman yang sama
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole('admin');

if (session_status() === PHP_SESSION_NONE) session_start();

$pdo = getDBConnection();
$pageTitle = 'Master Titik Geofencing Presensi';
$pageIcon  = 'solar:map-point-wave-bold';

/* ==============================================================================
 * 1. HANDLE POST (CRUD)
 * ============================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $self   = strtok($_SERVER['REQUEST_URI'] ?? '', '?');

    /* Sanitize return query — hanya ?page=N&per=N */
    $ret = $_POST['_ret'] ?? '';
    if (!is_string($ret) || !preg_match('/^\?page=\d+&per=\d+$/', $ret)) $ret = '';

    if ($action === 'add_lokasi') {
        $nama   = trim($_POST['nama_lokasi'] ?? '');
        $lat    = (float)($_POST['latitude']  ?? 0);
        $lng    = (float)($_POST['longitude'] ?? 0);
        $radius = (int)($_POST['radius_meter'] ?? 50);
        $alamat = trim($_POST['alamat'] ?? '');

        if (empty($nama) || !$lat || !$lng) {
            $_SESSION['flash'] = ['type'=>'error','title'=>'Gagal','msg'=>'Nama lokasi, latitude, dan longitude wajib diisi.'];
        } elseif ($radius < 5 || $radius > 5000) {
            $_SESSION['flash'] = ['type'=>'error','title'=>'Gagal','msg'=>'Radius harus antara 5 - 5000 meter.'];
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO titik_koordinat_sekolah
                        (nama_lokasi, latitude, longitude, radius_meter, alamat, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$nama, $lat, $lng, $radius, $alamat]);
                $_SESSION['flash'] = ['type'=>'success','title'=>'Berhasil','msg'=>"Titik lokasi \"$nama\" berhasil ditambahkan."];
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['type'=>'error','title'=>'Gagal','msg'=>'Gagal menambah lokasi: '.$e->getMessage()];
            }
        }
        header('Location: ' . $self . $ret); exit;
    }

    if ($action === 'update_lokasi') {
        $id     = (int)($_POST['id'] ?? 0);
        $nama   = trim($_POST['nama_lokasi'] ?? '');
        $lat    = (float)($_POST['latitude']  ?? 0);
        $lng    = (float)($_POST['longitude'] ?? 0);
        $radius = (int)($_POST['radius_meter'] ?? 50);
        $alamat = trim($_POST['alamat'] ?? '');

        if (!$id || empty($nama) || !$lat || !$lng) {
            $_SESSION['flash'] = ['type'=>'error','title'=>'Gagal','msg'=>'Data tidak lengkap.'];
        } elseif ($radius < 5 || $radius > 5000) {
            $_SESSION['flash'] = ['type'=>'error','title'=>'Gagal','msg'=>'Radius harus antara 5 - 5000 meter.'];
        } else {
            /* SERVER GUARD: tidak boleh edit titik yang masih aktif */
            $chk = $pdo->prepare("SELECT is_active FROM titik_koordinat_sekolah WHERE id = ? LIMIT 1");
            $chk->execute([$id]);
            $isActive = $chk->fetchColumn();
            if ($isActive === false) {
                $_SESSION['flash'] = ['type'=>'error','title'=>'Gagal','msg'=>'Titik lokasi tidak ditemukan.'];
            } elseif ((int)$isActive === 1) {
                $_SESSION['flash'] = ['type'=>'error','title'=>'Tidak Bisa Edit','msg'=>'Nonaktifkan titik lokasi terlebih dahulu sebelum mengedit.'];
            } else {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE titik_koordinat_sekolah
                           SET nama_lokasi = ?, latitude = ?, longitude = ?,
                               radius_meter = ?, alamat = ?
                         WHERE id = ?
                    ");
                    $stmt->execute([$nama, $lat, $lng, $radius, $alamat, $id]);
                    $_SESSION['flash'] = ['type'=>'success','title'=>'Berhasil','msg'=>"Titik lokasi \"$nama\" berhasil diperbarui."];
                } catch (Throwable $e) {
                    $_SESSION['flash'] = ['type'=>'error','title'=>'Gagal','msg'=>'Gagal update: '.$e->getMessage()];
                }
            }
        }
        header('Location: ' . $self . $ret); exit;
    }

    if ($action === 'toggle_aktif') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            try {
                $pdo->prepare("UPDATE titik_koordinat_sekolah SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
                $_SESSION['flash'] = ['type'=>'success','title'=>'Berhasil','msg'=>'Status aktif titik lokasi diperbarui.'];
            } catch (Throwable $e) {
                $_SESSION['flash'] = ['type'=>'error','title'=>'Gagal','msg'=>'Gagal toggle status: '.$e->getMessage()];
            }
        }
        header('Location: ' . $self . $ret); exit;
    }

    if ($action === 'delete_lokasi') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            /* SERVER GUARD: tidak boleh hapus titik yang masih aktif */
            $chk = $pdo->prepare("SELECT is_active FROM titik_koordinat_sekolah WHERE id = ? LIMIT 1");
            $chk->execute([$id]);
            $isActive = $chk->fetchColumn();
            if ($isActive === false) {
                $_SESSION['flash'] = ['type'=>'error','title'=>'Gagal','msg'=>'Titik lokasi tidak ditemukan.'];
            } elseif ((int)$isActive === 1) {
                $_SESSION['flash'] = ['type'=>'error','title'=>'Tidak Bisa Hapus','msg'=>'Nonaktifkan titik lokasi terlebih dahulu sebelum menghapus.'];
            } else {
                try {
                    $pdo->prepare("DELETE FROM titik_koordinat_sekolah WHERE id = ?")->execute([$id]);
                    $_SESSION['flash'] = ['type'=>'success','title'=>'Berhasil','msg'=>'Titik lokasi berhasil dihapus.'];
                } catch (Throwable $e) {
                    $_SESSION['flash'] = ['type'=>'error','title'=>'Gagal','msg'=>'Gagal hapus: '.$e->getMessage()];
                }
            }
        }
        header('Location: ' . $self . $ret); exit;
    }
}

/* ==============================================================================
 * 2. DATA + PAGINATION
 * ============================================================================== */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* --- Pagination params --- */
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = (int)($_GET['per'] ?? 10);
$perAllowed = [10, 25, 50, 100];
if (!in_array($per, $perAllowed, true)) $per = 10;

$totalRows  = (int)$pdo->query("SELECT COUNT(*) FROM titik_koordinat_sekolah")->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $per));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $per;

$stmt = $pdo->prepare("
    SELECT * FROM titik_koordinat_sekolah
    ORDER BY is_active DESC, nama_lokasi ASC
    LIMIT :lim OFFSET :off
");
$stmt->bindValue(':lim', $per, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$lokasiList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$startRow = $totalRows > 0 ? $offset + 1 : 0;
$endRow   = $offset + count($lokasiList);

/* Query suffix untuk preserve pagination di form */
$retQuery = '?page=' . $page . '&per=' . $per;

/* Build page numbers dengan ellipsis */
$pageNumbers = [];
if ($totalPages > 1) {
    $window = 2;
    $start  = max(2, $page - $window);
    $end    = min($totalPages - 1, $page + $window);

    $pageNumbers[] = 1;
    if ($start > 2) $pageNumbers[] = '...';
    for ($i = $start; $i <= $end; $i++) $pageNumbers[] = $i;
    if ($end < $totalPages - 1) $pageNumbers[] = '...';
    if ($totalPages > 1) $pageNumbers[] = $totalPages;
}

/* ==============================================================================
 * 3. EXTRA CSS
 * ============================================================================== */
$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-assessment-access.css')
    ? filemtime(BASE_PATH . '/assets/css/admin-assessment-access.css') : '1.0';

$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-assessment-access.css?v=' . $cssVersion . '">
<style>
/* ============ FILTER CARD ============ */
.ilmuna-abs-filter-card { background:#FFF; border:1px solid #E2E8F0; border-radius:14px;
    padding:18px 20px; margin-bottom:20px; box-shadow:0 2px 8px -3px rgba(15,23,42,.06); }
.ilmuna-abs-actions { display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end;
    padding-bottom:14px; margin-bottom:14px; border-bottom:1px dashed #E2E8F0; }

/* ============ TABLE ============ */
.ilmuna-abs-table-wrap { overflow-x:auto; background:#FFF; border:1px solid #E2E8F0;
    border-radius:14px; box-shadow:0 2px 8px -3px rgba(15,23,42,.06); }
.ilmuna-geo-table { width:100%; border-collapse:separate; border-spacing:0; font-size:13px; }
.ilmuna-geo-table thead th { background:#F8FAFC; padding:12px 10px; text-align:center;
    font-weight:800; color:#1D3B5E; font-size:11px; text-transform:uppercase;
    letter-spacing:.04em; border-bottom:2px solid #E2E8F0;
    position:sticky; top:0; z-index:2; }
.ilmuna-geo-table thead th.col-no { width:44px; }
.ilmuna-geo-table thead th.col-lokasi { text-align:left; padding-left:20px; min-width:220px;
    position:sticky; left:0; z-index:3; background:#F8FAFC; border-right:2px solid #E2E8F0; }
.ilmuna-geo-table thead th.col-peta { min-width:180px; }
.ilmuna-geo-table thead th.col-aksi { min-width:170px; }

.ilmuna-geo-table tbody td { padding:12px 10px; text-align:center;
    border-bottom:1px solid #F1F5F9; vertical-align:middle; }
.ilmuna-geo-table tbody td.col-no { color:#94A3B8; font-weight:700; font-size:12px; }
.ilmuna-geo-table tbody td.col-lokasi { text-align:left; padding-left:20px;
    position:sticky; left:0; z-index:1; background:#FFF; border-right:2px solid #E2E8F0; }
.ilmuna-geo-table tbody tr:hover td.col-lokasi { background:#F8FAFC; }
.ilmuna-geo-table tbody tr:last-child td { border-bottom:none; }

.lokasi-mini { display:flex; flex-direction:column; gap:3px; min-width:0; }
.lokasi-mini__name { font-size:13px; font-weight:800; color:#1E293B;
    display:flex; align-items:center; gap:6px; }
.lokasi-mini__addr { font-size:11px; color:#64748B; line-height:1.4;
    display:flex; align-items:flex-start; gap:5px; margin-top:2px;
    max-width:280px; }
.lokasi-mini__addr iconify-icon { color:#EF4444; flex-shrink:0; margin-top:1px; }

.ilmuna-geo-map { width:160px; height:100px; border-radius:10px; overflow:hidden;
    border:1.5px solid #E2E8F0; background:#F1F5F9; display:inline-block;
    position:relative; box-shadow:0 2px 6px -2px rgba(15,23,42,.10);
    transition:transform .18s ease, box-shadow .18s ease; }
.ilmuna-geo-map:hover { transform:translateY(-1px); box-shadow:0 6px 14px -4px rgba(15,23,42,.20); }
.ilmuna-geo-map iframe { width:100%; height:100%; border:0; display:block; }
.ilmuna-geo-map__badge { position:absolute; bottom:4px; right:4px;
    background:rgba(15,23,42,.75); color:#FFF; font-size:8.5px; font-weight:800;
    padding:2px 6px; border-radius:5px; letter-spacing:.05em; text-transform:uppercase;
    pointer-events:none; backdrop-filter:blur(4px); }

.ilmuna-koord { display:flex; flex-direction:column; gap:2px; align-items:center;
    font-family:ui-monospace, "SF Mono", Menlo, monospace; }
.ilmuna-koord__lat, .ilmuna-koord__lng { font-size:11.5px; font-weight:700; color:#1E293B; letter-spacing:.02em; }
.ilmuna-koord__label { font-size:9px; color:#94A3B8; font-weight:700;
    text-transform:uppercase; letter-spacing:.06em; margin-right:4px; }

.ilmuna-radius-badge { display:inline-flex; align-items:center; gap:4px;
    padding:5px 12px; border-radius:20px; font-size:11.5px; font-weight:800;
    background:#FEF3C7; color:#92400E; border:1px solid #FDE68A; }
.ilmuna-radius-badge iconify-icon { color:#D97706; }

.ilmuna-token-badge { display:inline-flex; align-items:center; gap:5px;
    font-size:10px; font-weight:800; padding:5px 10px; border-radius:20px;
    text-transform:uppercase; letter-spacing:.04em; }
.ilmuna-token-badge--aktif    { background:#DCFCE7; color:#166534; border:1px solid #A7F3D0; }
.ilmuna-token-badge--nonaktif { background:#FEE2E2; color:#991B1B; border:1px solid #FECACA; }
.ilmuna-token-badge--aktif::before {
    content:""; width:6px; height:6px; background:#10B981; border-radius:50%;
    box-shadow:0 0 0 2px rgba(16,185,129,.25);
    animation: geoPulse 1.6s ease-in-out infinite;
}
@keyframes geoPulse {
    0%,100% { box-shadow:0 0 0 2px rgba(16,185,129,.25); }
    50%     { box-shadow:0 0 0 5px rgba(16,185,129,.10); }
}

.ilmuna-geo-actions { display:grid; grid-template-columns:1fr 1fr; grid-template-rows:1fr 1fr;
    gap:4px; max-width:180px; margin:0 auto; }
.ilmuna-token-btn { display:inline-flex; align-items:center; justify-content:center;
    gap:4px; padding:6px 8px; font-size:11px; font-weight:700; border-radius:7px;
    font-family:inherit; cursor:pointer; border:1.5px solid transparent;
    transition:all .15s ease; text-decoration:none; white-space:nowrap;
    min-width:0; overflow:hidden; }
.ilmuna-token-btn iconify-icon { width:12px; height:12px; flex-shrink:0; }
.ilmuna-token-btn span { overflow:hidden; text-overflow:ellipsis; }

.ilmuna-token-btn--map { background:#EFF6FF; color:#1E40AF; border-color:#BFDBFE; }
.ilmuna-token-btn--map:hover { background:#3B82F6; color:#FFF; border-color:#3B82F6; }
.ilmuna-token-btn--edit { background:#FFFBEB; color:#92400E; border-color:#FDE68A; }
.ilmuna-token-btn--edit:hover { background:#F59E0B; color:#FFF; border-color:#F59E0B; }
.ilmuna-token-btn--toggle { background:#F1F5F9; color:#475569; border-color:#E2E8F0; }
.ilmuna-token-btn--toggle:hover { background:#0D7A87; color:#FFF; border-color:#0D7A87; }
.ilmuna-token-btn--danger { background:#FEF2F2; color:#DC2626; border-color:#FECACA; }
.ilmuna-token-btn--danger:hover { background:#EF4444; color:#FFF; border-color:#EF4444; }

.ilmuna-token-btn.is-locked { opacity:.40; cursor:not-allowed; pointer-events:auto; filter:grayscale(.3); }
.ilmuna-token-btn--edit.is-locked:hover { background:#FFFBEB; color:#92400E; border-color:#FDE68A; transform:none; }
.ilmuna-token-btn--danger.is-locked:hover { background:#FEF2F2; color:#DC2626; border-color:#FECACA; transform:none; }

/* Empty state */
.ilmuna-izin-empty { text-align:center; padding:44px 20px; background:#F8FAFC;
    border:1.5px dashed #E2E8F0; border-radius:14px; color:#64748B; }
.ilmuna-izin-empty iconify-icon { color:#CBD5E1; display:block; margin:0 auto 10px; }
.ilmuna-izin-empty p { margin:0 0 4px; font-size:14px; font-weight:700; color:#1E293B; }
.ilmuna-izin-empty small { font-size:12px; color:#94A3B8; }

/* ============ PAGINATION ============ */
.ilmuna-pagination { display:flex; justify-content:space-between; align-items:center;
    flex-wrap:wrap; gap:12px; padding:14px 18px; background:#F8FAFC;
    border:1px solid #E2E8F0; border-radius:12px; margin-top:12px;
    font-size:12.5px; color:#475569; }
.ilmuna-pagination-info strong { color:#1D3B5E; font-weight:800; }
.ilmuna-pagination-controls { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.ilmuna-pagination-controls label { font-size:11.5px; color:#64748B; font-weight:700;
    text-transform:uppercase; letter-spacing:.04em; margin-right:2px; }
.ilmuna-page-select { padding:6px 10px; border-radius:8px; border:1.5px solid #E2E8F0;
    background:#FFF; font-family:inherit; font-size:12px; font-weight:700;
    color:#1E293B; cursor:pointer; outline:none; transition:all .15s ease; }
.ilmuna-page-select:focus { border-color:#0D7A87; box-shadow:0 0 0 3px rgba(13,122,135,.12); }
.ilmuna-page-btn { display:inline-flex; align-items:center; justify-content:center;
    min-width:34px; height:34px; padding:0 10px; border-radius:8px;
    background:#FFF; color:#475569; border:1.5px solid #E2E8F0;
    font-family:inherit; font-size:12.5px; font-weight:700;
    cursor:pointer; text-decoration:none; transition:all .15s ease; user-select:none; }
.ilmuna-page-btn:hover:not(.is-disabled):not(.is-active):not(.is-ellipsis) {
    background:#F1F5F9; border-color:#CBD5E1; color:#1D3B5E; transform:translateY(-1px); }
.ilmuna-page-btn.is-active { background:linear-gradient(135deg,#0D7A87 0%,#1D3B5E 100%);
    color:#FFF; border-color:transparent;
    box-shadow:0 3px 8px -2px rgba(13,122,135,.4); cursor:default; pointer-events:none; }
.ilmuna-page-btn.is-disabled { opacity:.35; cursor:not-allowed; pointer-events:none; }
.ilmuna-page-btn.is-ellipsis { background:transparent; border-color:transparent;
    color:#94A3B8; cursor:default; pointer-events:none; letter-spacing:1px; }

/* ============ MODAL ============ */
.ilmuna-evidence-modal { position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,.7);
    backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center;
    padding:20px; opacity:0; pointer-events:none; transition:opacity .22s ease; overflow-y:auto; }
.ilmuna-evidence-modal.is-open { opacity:1; pointer-events:auto; }
.ilmuna-evidence-box { background:#FFF; border-radius:18px; overflow:hidden;
    max-width:560px; width:100%; margin:auto; max-height:calc(100vh - 40px);
    display:flex; flex-direction:column; box-shadow:0 24px 60px -20px rgba(0,0,0,.5);
    transform:scale(.96); transition:transform .22s cubic-bezier(.16,1,.3,1); }
.ilmuna-evidence-modal.is-open .ilmuna-evidence-box { transform:scale(1); }
.ilmuna-evidence-head { padding:16px 20px; border-bottom:1px solid #E2E8F0;
    background:linear-gradient(135deg,#1D3B5E 0%,#0D7A87 100%); color:#FFF;
    display:flex; align-items:center; justify-content:space-between; }
.ilmuna-evidence-head h3 { margin:0; font-size:15px; font-weight:800; color:#FFF;
    display:flex; align-items:center; gap:8px; }
.ilmuna-evidence-close { background:rgba(255,255,255,.15); border:none; color:#FFF;
    width:30px; height:30px; border-radius:8px; cursor:pointer;
    display:flex; align-items:center; justify-content:center; }
.ilmuna-evidence-close:hover { background:rgba(255,255,255,.25); }
.ilmuna-evidence-body { padding:22px 20px; overflow-y:auto; flex:1; }
.ilmuna-evidence-foot { padding:14px 20px; border-top:1px solid #E2E8F0; background:#F8FAFC;
    display:flex; justify-content:flex-end; gap:10px; }

.ilmuna-form-group { margin-bottom:16px; }
.ilmuna-form-group:last-child { margin-bottom:0; }
.ilmuna-form-label { display:block; font-size:11.5px; font-weight:700; color:#1D3B5E;
    margin-bottom:7px; text-transform:uppercase; letter-spacing:.04em; }
.ilmuna-form-label .req { color:#EF4444; margin-left:2px; }
.ilmuna-form-label .hint { display:block; text-transform:none; letter-spacing:0;
    color:#94A3B8; font-weight:500; font-size:11px; margin-top:2px; }
.ilmuna-form-input { width:100%; padding:10px 14px; border:1.5px solid #CBD5E1;
    border-radius:10px; background:#FFF; font-family:inherit; font-size:13.5px;
    font-weight:500; color:#1E293B; outline:none; transition:all .18s ease;
    box-sizing:border-box; }
.ilmuna-form-input:focus { border-color:#0D7A87; box-shadow:0 0 0 3px rgba(13,122,135,.12); }
.ilmuna-form-input::placeholder { color:#94A3B8; font-weight:400; }
textarea.ilmuna-form-input { resize:vertical; min-height:70px; }
.ilmuna-input-with-btn { display:flex; gap:6px; }
.ilmuna-input-with-btn .ilmuna-form-input { flex:1; }
.ilmuna-input-with-btn .btn-gps { padding:0 12px; border:1.5px solid #BFDBFE;
    background:#EFF6FF; color:#1E40AF; border-radius:10px; font-family:inherit;
    font-size:11.5px; font-weight:700; cursor:pointer;
    display:inline-flex; align-items:center; gap:4px;
    transition:all .18s ease; white-space:nowrap; }
.ilmuna-input-with-btn .btn-gps:hover { background:#3B82F6; color:#FFF; border-color:#3B82F6; }
.ilmuna-input-with-btn .btn-gps:disabled { opacity:.55; cursor:not-allowed; }

.ilmuna-btn { padding:10px 18px; border-radius:10px; font-family:inherit;
    font-size:13px; font-weight:700; cursor:pointer;
    display:inline-flex; align-items:center; justify-content:center;
    gap:6px; border:1.5px solid transparent;
    transition:all .18s ease; white-space:nowrap; }
.ilmuna-btn--outline { background:#FFF; color:#475569; border-color:#E2E8F0; }
.ilmuna-btn--outline:hover { background:#F8FAFC; border-color:#CBD5E1; }
.ilmuna-btn--save { background:linear-gradient(135deg,#10B981,#059669); color:#FFF;
    box-shadow:0 4px 14px -4px rgba(16,185,129,.5); }
.ilmuna-btn--save:hover { transform:translateY(-1px); box-shadow:0 8px 20px -4px rgba(16,185,129,.55); }

/* ============ DIALOG ============ */
.ilmuna-dialog { position:fixed; inset:0; z-index:100000; background:rgba(15,23,42,.68);
    backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center;
    padding:20px; opacity:0; pointer-events:none; transition:opacity .22s ease; }
.ilmuna-dialog.is-open { opacity:1; pointer-events:auto; }
.ilmuna-dialog-box { background:#FFF; border-radius:16px; overflow:hidden; max-width:440px;
    width:100%; box-shadow:0 24px 60px -20px rgba(0,0,0,.55);
    transform:scale(.94); transition:transform .28s cubic-bezier(0.16, 1, 0.3, 1); }
.ilmuna-dialog.is-open .ilmuna-dialog-box { transform:scale(1); }
.ilmuna-dialog-body { padding:26px 24px 12px; text-align:center; }
.ilmuna-dialog-icon { width:64px; height:64px; border-radius:50%; margin:0 auto 16px;
    display:flex; align-items:center; justify-content:center; }
.ilmuna-dialog-icon--info    { background:linear-gradient(135deg,#DBEAFE,#BFDBFE); color:#1E40AF; }
.ilmuna-dialog-icon--warn    { background:linear-gradient(135deg,#FEF3C7,#FDE68A); color:#B45309; }
.ilmuna-dialog-icon--danger  { background:linear-gradient(135deg,#FEE2E2,#FECACA); color:#DC2626; }
.ilmuna-dialog-icon--success { background:linear-gradient(135deg,#DCFCE7,#BBF7D0); color:#15803D; }
.ilmuna-dialog-title { font-size:17px; font-weight:800; color:#1E293B; margin:0 0 8px; line-height:1.3; }
.ilmuna-dialog-msg { font-size:13.5px; color:#475569; line-height:1.55; margin:0 0 12px; }
.ilmuna-dialog-msg strong { color:#1E293B; font-weight:700; }
.ilmuna-dialog-actions { display:flex; gap:10px; padding:18px 24px 22px; }
.ilmuna-dialog-btn { flex:1; padding:11px 16px; border-radius:10px; font-family:inherit;
    font-size:13.5px; font-weight:700; cursor:pointer;
    border:1.5px solid transparent; transition:all .18s ease;
    display:inline-flex; align-items:center; justify-content:center; gap:6px; }
.ilmuna-dialog-btn--cancel { background:#F1F5F9; color:#475569; border-color:#E2E8F0; }
.ilmuna-dialog-btn--cancel:hover { background:#E2E8F0; color:#1E293B; }
.ilmuna-dialog-btn--ok { background:linear-gradient(135deg,#F59E0B,#D97706); color:#FFF;
    box-shadow:0 4px 14px -4px rgba(245,158,11,.5); }
.ilmuna-dialog-btn--ok:hover { transform:translateY(-1px); }
.ilmuna-dialog-btn--danger { background:linear-gradient(135deg,#EF4444,#DC2626); color:#FFF;
    box-shadow:0 4px 14px -4px rgba(239,68,68,.5); }
.ilmuna-dialog-btn--danger:hover { transform:translateY(-1px); }

/* ============ TOAST ============ */
.ilmuna-toast-wrap { position:fixed; top:80px; right:20px; z-index:999998;
    display:flex; flex-direction:column; gap:10px; max-width:340px; pointer-events:none; }
.ilmuna-toast-item { background:#FFF; border-left:4px solid #10B981; border-radius:12px;
    box-shadow:0 12px 32px -8px rgba(0,0,0,.22); padding:12px 16px;
    font-family:inherit; pointer-events:auto;
    opacity:0; transform:translateY(-8px) scale(.96);
    transition: opacity .35s cubic-bezier(.16,1,.3,1),
                transform .35s cubic-bezier(.16,1,.3,1);
    will-change: opacity, transform;
}
.ilmuna-toast-item.is-show { opacity:1; transform:translateY(0) scale(1); }
.ilmuna-toast-item.is-hiding {
    opacity:0; transform:translateY(-12px) scale(.94);
    transition: opacity .4s ease, transform .4s ease;
}
.ilmuna-toast-item.is-error { border-left-color:#EF4444; }
.ilmuna-toast-item.is-warn  { border-left-color:#F59E0B; }
.ilmuna-toast-title { font-size:13px; font-weight:800; color:#1E293B; margin-bottom:3px; }
.ilmuna-toast-msg   { font-size:12px; color:#475569; line-height:1.4; }

.ilmuna-spin { animation: ilmunaSpin 1s linear infinite; }
@keyframes ilmunaSpin { to { transform: rotate(360deg); } }

@media (max-width:640px) {
    .ilmuna-geo-table thead th.col-lokasi,
    .ilmuna-geo-table tbody td.col-lokasi { min-width:160px; }
    .ilmuna-geo-map { width:120px; height:80px; }
    .ilmuna-geo-actions { max-width:140px; }
    .ilmuna-token-btn { padding:5px 6px; font-size:10px; }
    .ilmuna-pagination { flex-direction:column; align-items:stretch; }
    .ilmuna-pagination-controls { justify-content:center; }
}
</style>';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<div class="ilmuna-results-app" id="appGeo">

    <!-- FILTER CARD -->
    <div class="ilmuna-abs-filter-card">
        <div class="ilmuna-abs-actions">
            <button type="button" class="ilmuna-btn ilmuna-btn--teal" id="btnTambah">
                <iconify-icon icon="solar:add-circle-bold" width="16"></iconify-icon>
                <span>Tambah Titik Lokasi</span>
            </button>
        </div>
        <div style="padding:10px 14px; background:#F8FAFC; border-radius:10px;
                    font-size:12.5px; color:#475569; line-height:1.6;
                    display:flex; align-items:flex-start; gap:8px;">
            <iconify-icon icon="solar:info-circle-bold" width="18"
                          style="color:#0D7A87; flex-shrink:0; margin-top:1px;"></iconify-icon>
            <span>Titik aktif menjadi acuan validasi lokasi saat siswa melakukan absensi.
                  <strong>Edit &amp; Hapus hanya bisa dilakukan saat titik nonaktif</strong> —
                  nonaktifkan terlebih dahulu untuk mengubah atau menghapus.</span>
        </div>
    </div>

    <!-- TABLE -->
    <?php if (empty($lokasiList)): ?>
        <div class="ilmuna-izin-empty">
            <iconify-icon icon="solar:map-point-remove-bold" width="52"></iconify-icon>
            <p>Belum Ada Titik Lokasi</p>
            <small>Tambahkan titik koordinat sekolah pertama dengan mengklik tombol
                   <strong>Tambah Titik Lokasi</strong> di atas.</small>
        </div>
    <?php else: ?>
        <div class="ilmuna-abs-table-wrap">
            <table class="ilmuna-geo-table">
                <thead>
                    <tr>
                        <th class="col-no">#</th>
                        <th class="col-lokasi">Lokasi</th>
                        <th class="col-peta">Peta Satelit</th>
                        <th>Koordinat</th>
                        <th>Radius</th>
                        <th>Status</th>
                        <th class="col-aksi">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = $offset + 1; foreach ($lokasiList as $loc):
                        $isActive = ((int)$loc['is_active']) === 1;
                        $mapSrc = 'https://maps.google.com/maps?q='
                                . urlencode($loc['latitude'] . ',' . $loc['longitude'])
                                . '&t=k&z=17&hl=id&output=embed';
                    ?>
                        <tr data-id="<?= (int)$loc['id'] ?>">
                            <td class="col-no"><?= $no++ ?></td>
                            <td class="col-lokasi">
                                <div class="lokasi-mini">
                                    <div class="lokasi-mini__name">
                                        <?= htmlspecialchars($loc['nama_lokasi']) ?>
                                    </div>
                                    <?php if (!empty($loc['alamat'])): ?>
                                        <div class="lokasi-mini__addr">
                                            <iconify-icon icon="solar:map-point-bold" width="11"></iconify-icon>
                                            <span><?= htmlspecialchars($loc['alamat']) ?></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <a href="https://www.google.com/maps?q=<?= urlencode($loc['latitude']) ?>,<?= urlencode($loc['longitude']) ?>&t=k&z=18"
                                   target="_blank" rel="noopener"
                                   class="ilmuna-geo-map"
                                   title="Klik untuk buka Google Maps">
                                    <iframe
                                        src="<?= htmlspecialchars($mapSrc) ?>"
                                        loading="lazy"
                                        referrerpolicy="no-referrer-when-downgrade"
                                        allowfullscreen
                                        style="pointer-events:none;"></iframe>
                                    <span class="ilmuna-geo-map__badge">Satelit</span>
                                </a>
                            </td>
                            <td>
                                <div class="ilmuna-koord">
                                    <div><span class="ilmuna-koord__label">Lat</span>
                                        <span class="ilmuna-koord__lat"><?= htmlspecialchars($loc['latitude']) ?></span></div>
                                    <div><span class="ilmuna-koord__label">Lng</span>
                                        <span class="ilmuna-koord__lng"><?= htmlspecialchars($loc['longitude']) ?></span></div>
                                </div>
                            </td>
                            <td>
                                <span class="ilmuna-radius-badge">
                                    <iconify-icon icon="solar:target-bold" width="13"></iconify-icon>
                                    <?= (int)$loc['radius_meter'] ?> m
                                </span>
                            </td>
                            <td>
                                <span class="ilmuna-token-badge ilmuna-token-badge--<?= $isActive ? 'aktif' : 'nonaktif' ?>">
                                    <?= $isActive ? 'Aktif' : 'Nonaktif' ?>
                                </span>
                            </td>
                            <td class="col-aksi">
                                <div class="ilmuna-geo-actions">
                                    <a href="https://www.google.com/maps?q=<?= urlencode($loc['latitude']) ?>,<?= urlencode($loc['longitude']) ?>"
                                       target="_blank" rel="noopener"
                                       class="ilmuna-token-btn ilmuna-token-btn--map" title="Buka Google Maps">
                                        <iconify-icon icon="solar:map-point-bold" width="12"></iconify-icon>
                                        <span>Maps</span>
                                    </a>

                                    <?php if ($isActive): ?>
                                        <button type="button"
                                                class="ilmuna-token-btn ilmuna-token-btn--edit is-locked"
                                                data-locked="edit"
                                                title="Nonaktifkan titik lokasi terlebih dahulu untuk mengedit">
                                            <iconify-icon icon="solar:lock-keyhole-minimalistic-bold" width="12"></iconify-icon>
                                            <span>Edit</span>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="ilmuna-token-btn ilmuna-token-btn--edit"
                                                data-edit
                                                data-id="<?= (int)$loc['id'] ?>"
                                                data-nama="<?= htmlspecialchars($loc['nama_lokasi']) ?>"
                                                data-lat="<?= htmlspecialchars($loc['latitude']) ?>"
                                                data-lng="<?= htmlspecialchars($loc['longitude']) ?>"
                                                data-radius="<?= (int)$loc['radius_meter'] ?>"
                                                data-alamat="<?= htmlspecialchars($loc['alamat'] ?? '') ?>">
                                            <iconify-icon icon="solar:pen-bold" width="12"></iconify-icon>
                                            <span>Edit</span>
                                        </button>
                                    <?php endif; ?>

                                    <form method="POST" style="margin:0;display:contents;">
                                        <input type="hidden" name="action" value="toggle_aktif">
                                        <input type="hidden" name="id" value="<?= (int)$loc['id'] ?>">
                                        <input type="hidden" name="_ret" value="<?= htmlspecialchars($retQuery) ?>">
                                        <button type="submit" class="ilmuna-token-btn ilmuna-token-btn--toggle">
                                            <iconify-icon icon="<?= $isActive ? 'solar:pause-circle-bold' : 'solar:play-circle-bold' ?>" width="12"></iconify-icon>
                                            <span><?= $isActive ? 'Off' : 'On' ?></span>
                                        </button>
                                    </form>

                                    <?php if ($isActive): ?>
                                        <button type="button"
                                                class="ilmuna-token-btn ilmuna-token-btn--danger is-locked"
                                                data-locked="hapus"
                                                title="Nonaktifkan titik lokasi terlebih dahulu untuk menghapus">
                                            <iconify-icon icon="solar:lock-keyhole-minimalistic-bold" width="12"></iconify-icon>
                                            <span>Hapus</span>
                                        </button>
                                    <?php else: ?>
                                        <form method="POST" style="margin:0;display:contents;"
                                              data-delete-form data-nama="<?= htmlspecialchars($loc['nama_lokasi']) ?>">
                                            <input type="hidden" name="action" value="delete_lokasi">
                                            <input type="hidden" name="id" value="<?= (int)$loc['id'] ?>">
                                            <input type="hidden" name="_ret" value="<?= htmlspecialchars($retQuery) ?>">
                                            <button type="button" class="ilmuna-token-btn ilmuna-token-btn--danger"
                                                    data-delete-btn title="Hapus">
                                                <iconify-icon icon="solar:trash-bin-trash-bold" width="12"></iconify-icon>
                                                <span>Hapus</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <div class="ilmuna-pagination">
            <div class="ilmuna-pagination-info">
                Menampilkan <strong><?= $startRow ?>–<?= $endRow ?></strong>
                dari <strong><?= $totalRows ?></strong> lokasi
            </div>
            <div class="ilmuna-pagination-controls">
                <label for="perPageSelect">Per halaman</label>
                <select id="perPageSelect" class="ilmuna-page-select"
                        data-baseurl="<?= htmlspecialchars($self) ?>">
                    <?php foreach ($perAllowed as $p): ?>
                        <option value="<?= $p ?>" <?= $per === $p ? 'selected' : '' ?>><?= $p ?></option>
                    <?php endforeach; ?>
                </select>

                <?php if ($totalPages > 1): ?>
                    <?php if ($page > 1): ?>
                        <a href="<?= htmlspecialchars($self . '?page=' . ($page - 1) . '&per=' . $per) ?>"
                           class="ilmuna-page-btn" title="Sebelumnya">‹</a>
                    <?php else: ?>
                        <span class="ilmuna-page-btn is-disabled">‹</span>
                    <?php endif; ?>

                    <?php foreach ($pageNumbers as $n): ?>
                        <?php if ($n === '...'): ?>
                            <span class="ilmuna-page-btn is-ellipsis">…</span>
                        <?php elseif ((int)$n === $page): ?>
                            <span class="ilmuna-page-btn is-active"><?= (int)$n ?></span>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($self . '?page=' . (int)$n . '&per=' . $per) ?>"
                               class="ilmuna-page-btn"><?= (int)$n ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($page < $totalPages): ?>
                        <a href="<?= htmlspecialchars($self . '?page=' . ($page + 1) . '&per=' . $per) ?>"
                           class="ilmuna-page-btn" title="Berikutnya">›</a>
                    <?php else: ?>
                        <span class="ilmuna-page-btn is-disabled">›</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- MODAL ADD / EDIT -->
<div class="ilm-modal-backdrop ilmuna-evidence-modal" id="modalLokasi">
    <div class="ilm-modal-dialog ilmuna-evidence-box" style="max-width: 600px;">
        <form method="POST" id="formLokasi">
            <input type="hidden" name="action" id="formAction" value="add_lokasi">
            <input type="hidden" name="id" id="formId" value="">
            <input type="hidden" name="_ret" value="<?= htmlspecialchars($retQuery) ?>">

            <div class="ilm-modal-header">
                <div class="ilm-modal-header__brand">
                    <div class="ilm-modal-header__icon">
                        <iconify-icon icon="solar:map-point-bold"></iconify-icon>
                    </div>
                    <div>
                        <h3 class="ilm-modal-header__title" id="formTitle">Tambah Titik Lokasi</h3>
                        <p class="ilm-modal-header__subtitle">Konfigurasi koordinat GPS gerbang & radius toleransi presensi</p>
                    </div>
                </div>
                <button type="button" class="ilm-modal-header__close" data-close-modal title="Tutup">
                    <iconify-icon icon="solar:close-circle-bold"></iconify-icon>
                </button>
            </div>

            <div class="ilm-modal-body" style="max-height: calc(88vh - 160px);">
                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label">Nama Lokasi <span class="req">*</span></label>
                    <input type="text" name="nama_lokasi" class="ilmuna-form-input" required
                           placeholder="Contoh: Gedung Kampus Utama Ilmuna" maxlength="150">
                </div>
                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label">
                        Latitude (GPS) <span class="req">*</span>
                        <span class="hint">Contoh: -6.2088000 (rentang -90 sampai 90)</span>
                    </label>
                    <div class="ilmuna-input-with-btn">
                        <input type="number" step="0.00000001" min="-90" max="90"
                               name="latitude" id="inputLatitude" class="ilmuna-form-input" required
                               placeholder="-6.2088000">
                        <button type="button" class="btn-gps" id="btnDeteksiGPS" title="Deteksi lokasi saya">
                            <iconify-icon icon="solar:gps-bold" width="13"></iconify-icon>
                            GPS
                        </button>
                    </div>
                </div>
                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label">
                        Longitude (GPS) <span class="req">*</span>
                        <span class="hint">Contoh: 106.8456000 (rentang -180 sampai 180)</span>
                    </label>
                    <input type="number" step="0.00000001" min="-180" max="180"
                           name="longitude" id="inputLongitude" class="ilmuna-form-input" required
                           placeholder="106.8456000">
                </div>
                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label">
                        Radius Toleransi <span class="req">*</span>
                        <span class="hint">Jarak maksimum (meter) siswa dari titik agar presensi sah</span>
                    </label>
                    <input type="number" name="radius_meter" class="ilmuna-form-input" required
                           min="5" max="5000" value="50" placeholder="50">
                </div>
                <div class="ilmuna-form-group">
                    <label class="ilmuna-form-label">
                        Alamat Lengkap
                        <span class="hint">Opsional — deskripsi fisik lokasi</span>
                    </label>
                    <textarea name="alamat" class="ilmuna-form-input" rows="3"
                              placeholder="Jl. Pendidikan No. 1, Kota ..."></textarea>
                </div>
            </div>

            <div class="ilm-modal-footer">
                <button type="button" class="ilm-modal-btn ilm-modal-btn--ghost" data-close-modal>
                    Batal
                </button>
                <button type="submit" class="ilm-modal-btn ilm-modal-btn--primary">
                    <iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon>
                    Simpan
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DIALOG KONFIRMASI -->
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

<div class="ilmuna-toast-wrap" id="toastWrap"></div>

<script>
(function () {
    'use strict';

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    /* TOAST */
    var toastWrap = document.getElementById('toastWrap');
    function toast(type, title, msg) {
        var normType = (type === 'warn' || type === 'warning') ? 'warning' : ((type === 'danger' || type === 'error' || type === 'err') ? 'error' : (type || 'info'));
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, title, msg, 4500);
        }
        var el = document.createElement('div');
        el.className = 'ilmuna-toast-item'
                    + (type === 'error' ? ' is-error' : type === 'warn' ? ' is-warn' : '');
        el.innerHTML = '<div class="ilmuna-toast-title">' + escHtml(title) + '</div>'
                     + '<div class="ilmuna-toast-msg">' + escHtml(msg) + '</div>';
        toastWrap.appendChild(el);
        requestAnimationFrame(function() {
            requestAnimationFrame(function() { el.classList.add('is-show'); });
        });
        setTimeout(function() {
            el.classList.add('is-hiding');
            el.classList.remove('is-show');
            setTimeout(function() { el.remove(); }, 450);
        }, 4500);
    }

    /* DIALOG */
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
                type === 'danger' ? 'ilmuna-dialog-btn--danger' : 'ilmuna-dialog-btn--ok'
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
    dialogModal.addEventListener('click', function(e) { if (e.target === dialogModal) closeDialog(false); });
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
                type: opts.type || 'info', title: opts.title || 'Informasi',
                message: msg, okText: opts.okText || 'OK', showCancel: false
            });
        },
        confirm: function(msg, opts) {
            opts = opts || {};
            var t = (opts.type === 'danger' || opts.type === 'error' || opts.type === 'delete') ? 'error' : ((opts.type === 'warn' || opts.type === 'warning') ? 'warning' : 'warning');
            if (window.Ilmuna && typeof window.Ilmuna.confirm === 'function') {
                return Ilmuna.confirm(t, opts.title || 'Konfirmasi', msg, opts.okText || 'Ya, Lanjutkan', opts.cancelText || 'Batal');
            }
            return openDialog({
                type: opts.type || 'warn', title: opts.title || 'Konfirmasi',
                message: msg, okText: opts.okText || 'Ya, Lanjutkan',
                cancelText: opts.cancelText || 'Batal', showCancel: true
            });
        }
    };

    <?php if ($flash): ?>
    toast(<?= json_encode($flash['type'] === 'success' ? 'success' : 'error') ?>,
          <?= json_encode($flash['title']) ?>,
          <?= json_encode($flash['msg']) ?>);
    <?php endif; ?>

    /* PER-PAGE SELECTOR */
    var perPageSel = document.getElementById('perPageSelect');
    if (perPageSel) {
        perPageSel.addEventListener('change', function() {
            var base = this.dataset.baseurl;
            window.location.href = base + '?page=1&per=' + encodeURIComponent(this.value);
        });
    }

    /* MODAL ADD/EDIT */
    var modalLokasi = document.getElementById('modalLokasi');
    var formLokasi  = document.getElementById('formLokasi');
    var formTitle   = document.getElementById('formTitle');
    var formAction  = document.getElementById('formAction');
    var formId      = document.getElementById('formId');
    var inputNama   = formLokasi.querySelector('[name="nama_lokasi"]');
    var inputLat    = formLokasi.querySelector('[name="latitude"]');
    var inputLng    = formLokasi.querySelector('[name="longitude"]');
    var inputRadius = formLokasi.querySelector('[name="radius_meter"]');
    var inputAlamat = formLokasi.querySelector('[name="alamat"]');

    function openLokasiModal(mode, data) {
        if (mode === 'edit' && data) {
            formTitle.innerHTML = '<iconify-icon icon="solar:pen-bold" width="18"></iconify-icon> Edit Titik Lokasi';
            formAction.value = 'update_lokasi';
            formId.value     = data.id || '';
            inputNama.value  = data.nama || '';
            inputLat.value   = data.lat || '';
            inputLng.value   = data.lng || '';
            inputRadius.value = data.radius || 50;
            inputAlamat.value = data.alamat || '';
        } else {
            formTitle.innerHTML = '<iconify-icon icon="solar:add-circle-bold" width="18"></iconify-icon> Tambah Titik Lokasi';
            formAction.value = 'add_lokasi';
            formId.value     = '';
            formLokasi.reset();
            inputRadius.value = 50;
        }
        modalLokasi.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function(){ inputNama.focus(); }, 250);
    }
    function closeLokasiModal() {
        modalLokasi.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    document.getElementById('btnTambah').addEventListener('click', function() { openLokasiModal('add'); });

    document.querySelectorAll('[data-edit]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openLokasiModal('edit', {
                id: btn.dataset.id, nama: btn.dataset.nama,
                lat: btn.dataset.lat, lng: btn.dataset.lng,
                radius: btn.dataset.radius, alamat: btn.dataset.alamat
            });
        });
    });

    document.querySelectorAll('[data-locked]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var which = btn.dataset.locked;
            var msg = which === 'edit'
                ? 'Titik lokasi masih <strong>aktif</strong>. Nonaktifkan terlebih dahulu (klik <strong>Off</strong>) sebelum mengedit.'
                : 'Titik lokasi masih <strong>aktif</strong>. Nonaktifkan terlebih dahulu (klik <strong>Off</strong>) sebelum menghapus.';
            dlg.alert(msg, {
                type: 'warn',
                title: which === 'edit' ? 'Tidak Bisa Edit' : 'Tidak Bisa Hapus',
                okText: 'Mengerti'
            });
        });
    });

    document.querySelectorAll('[data-close-modal]').forEach(function(btn) {
        btn.addEventListener('click', closeLokasiModal);
    });
    modalLokasi.addEventListener('click', function(e) {
        if (e.target === modalLokasi) closeLokasiModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && modalLokasi.classList.contains('is-open')) closeLokasiModal();
    });

    /* GPS DETECT */
    var btnGPS = document.getElementById('btnDeteksiGPS');
    btnGPS.addEventListener('click', function() {
        if (!navigator.geolocation) {
            dlg.alert('Browser tidak mendukung Geolocation API.',
                { type:'warn', title:'GPS Tidak Tersedia', okText:'Mengerti' });
            return;
        }
        var orig = btnGPS.innerHTML;
        btnGPS.disabled = true;
        btnGPS.innerHTML = '<iconify-icon icon="solar:restart-bold" class="ilmuna-spin" width="13"></iconify-icon> ...';

        navigator.geolocation.getCurrentPosition(
            function(pos) {
                inputLat.value = pos.coords.latitude.toFixed(8);
                inputLng.value = pos.coords.longitude.toFixed(8);
                btnGPS.disabled = false;
                btnGPS.innerHTML = orig;
                toast('success', 'GPS Terdeteksi',
                    'Akurasi: ±' + Math.round(pos.coords.accuracy) + ' meter.');
            },
            function(err) {
                btnGPS.disabled = false;
                btnGPS.innerHTML = orig;
                var msgMap = {
                    1: 'Izin lokasi ditolak. Aktifkan izin lokasi di browser.',
                    2: 'Lokasi tidak tersedia. Coba di area terbuka.',
                    3: 'Waktu habis. Coba lagi.'
                };
                dlg.alert(msgMap[err.code] || ('Gagal: ' + err.message),
                    { type:'danger', title:'GPS Gagal', okText:'Tutup' });
            },
            { enableHighAccuracy:true, timeout:15000, maximumAge:0 }
        );
    });

    /* DELETE CONFIRM */
    document.querySelectorAll('[data-delete-form]').forEach(function(form) {
        var btn = form.querySelector('[data-delete-btn]');
        if (!btn) return;
        btn.addEventListener('click', function() {
            var nama = form.dataset.nama || 'lokasi ini';
            dlg.confirm(
                'Hapus titik lokasi <strong>' + escHtml(nama) + '</strong>?<br><br>'
              + '<small style="color:#64748B;">Tindakan ini tidak bisa dibatalkan.</small>',
                { title:'Hapus Titik Lokasi?', okText:'Ya, Hapus', type:'danger' }
            ).then(function(ok) { if (ok) form.submit(); });
        });
    });

    /* VALIDATION */
    formLokasi.addEventListener('submit', function(e) {
        var lat = parseFloat(inputLat.value);
        var lng = parseFloat(inputLng.value);
        var r   = parseInt(inputRadius.value, 10);

        if (!inputNama.value.trim()) {
            e.preventDefault();
            dlg.alert('Nama lokasi wajib diisi.', { type:'warn', title:'Data Belum Lengkap' });
            inputNama.focus(); return false;
        }
        if (isNaN(lat) || lat < -90 || lat > 90) {
            e.preventDefault();
            dlg.alert('Latitude harus angka antara -90 sampai 90.', { type:'warn', title:'Latitude Tidak Valid' });
            inputLat.focus(); return false;
        }
        if (isNaN(lng) || lng < -180 || lng > 180) {
            e.preventDefault();
            dlg.alert('Longitude harus angka antara -180 sampai 180.', { type:'warn', title:'Longitude Tidak Valid' });
            inputLng.focus(); return false;
        }
        if (isNaN(r) || r < 5 || r > 5000) {
            e.preventDefault();
            dlg.alert('Radius harus antara 5 sampai 5000 meter.', { type:'warn', title:'Radius Tidak Valid' });
            inputRadius.focus(); return false;
        }
    });

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>