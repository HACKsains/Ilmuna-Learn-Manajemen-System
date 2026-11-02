<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — PUSAT MATERI & MODUL AJAR DIGITAL SISWA (FLIPBOOK 3D)
 * ==============================================================================
 * Fitur & Karakteristik:
 * 1. Filter Dinamis & Cascading: Sesuai Kelas & Mata Pelajaran terdaftar siswa.
 * 2. Penampil Dokumen & Flipbook 3D Identik Portal Guru/Admin:
 *    - Memuat dokumen via Base64 Binary Resolver (Anti-CORS & Anti-Gagal).
 *    - Flipbook 3D Interaktif (PDF.js + St.PageFlip) dengan cover depan & belakang.
 *    - Fallback otomatis ke Buku Bacaan Flipbook 3D untuk teks/catatan/dokumen.
 *    - Mode Scroll / Slide Vertikal berbasis Canvas resolusi tinggi.
 *    - Dual-Axis Drag-to-Scroll (geser horizontal & vertikal) + Floating Zoom Toolbar.
 *    - Fitur Buka Otomatis (Auto-Play), Efek Suara Kertas (Web Audio API), Fullscreen,
 *      serta tombol Download Materi.
 *    - Lightbox Penampil Gambar khusus (Zoom In/Out, Reset, Rotate 90°, Panning).
 * 3. Tampilan Detail UNUGIRI 2-Kolom & Grid Kartu Responsif.
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

// Anti-cache header agar siswa selalu memuat konten terbaru
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

if (!function_exists('hashMateriId')) {
    function hashMateriId($id) {
        return rtrim(strtr(base64_encode((string)$id), '+/', '-_'), '=');
    }
}

if (!function_exists('decodeMateriId')) {
    function decodeMateriId($id) {
        if (is_numeric($id)) return (int)$id;
        $b64 = strtr((string)$id, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad) $b64 .= str_repeat('=', 4 - $pad);
        $decoded = base64_decode($b64, true);
        if ($decoded !== false && is_numeric($decoded)) return (int)$decoded;
        return (int)preg_replace('/[^0-9]/', '', (string)$id);
    }
}

// Hak Akses: Siswa (juga izinkan preview oleh Admin & Guru)
requireRole(['siswa', 'admin', 'guru']);
$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$currentUser = getCurrentUser();

$activeRole = hasRole('siswa') ? 'siswa' : (hasRole('guru') ? 'guru' : 'admin');
$GLOBALS['activeRole'] = $activeRole;

// ==============================================================================
// 1. RESOLUSI DATA SISWA, KELAS & ROMBEL
// ==============================================================================
$currentSiswa = null;
$overrideSiswaId = (int)($_GET['siswa_id'] ?? 0);

if (($activeRole === 'admin' || $activeRole === 'guru') && $overrideSiswaId > 0) {
    $stmtS = $pdo->prepare("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
        FROM siswa s 
        LEFT JOIN kelas k ON s.id_kelas = k.id 
        WHERE s.id = ? LIMIT 1
    ");
    $stmtS->execute([$overrideSiswaId]);
    $currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);
}

if (!$currentSiswa) {
    $stmtS = $pdo->prepare("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
        FROM siswa s 
        LEFT JOIN kelas k ON s.id_kelas = k.id 
        WHERE s.nis = ? OR s.nisn = ? OR s.gmail = ? OR s.gmail = ?
        LIMIT 1
    ");
    $stmtS->execute([
        $currentUser['username'] ?? '',
        $currentUser['nisn_nip'] ?? '',
        $currentUser['email'] ?? '',
        $currentUser['email_google'] ?? ''
    ]);
    $currentSiswa = $stmtS->fetch(PDO::FETCH_ASSOC);
}

// Fallback cerdas jika login sebagai admin/demo yang belum terpaut siswa
if (!$currentSiswa) {
    $currentSiswa = $pdo->query("
        SELECT s.*, k.nama_kelas, k.kode_kelas, k.tahun_pelajaran 
        FROM siswa s 
        LEFT JOIN kelas k ON s.id_kelas = k.id 
        ORDER BY s.id ASC LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
}

$idSiswa   = (int)($currentSiswa['id'] ?? 0);
$idKelas   = (int)($currentSiswa['id_kelas'] ?? 0);
$namaKelas = $currentSiswa['nama_kelas'] ?? 'Semua Kelas';
$namaSiswa = $currentSiswa['nama_lengkap'] ?? ($currentUser['full_name'] ?: 'Siswa Ilmuna');
$nisnSiswa = $currentSiswa['nisn'] ?? ($currentSiswa['nis'] ?? '-');
$thnAjaran = $currentSiswa['tahun_pelajaran'] ?? '2026/2027';

// ==============================================================================
// 2. RESOLVER BINARY MATERI & PROXY STREAM / FLIPBOOK JSON (SAMA PERSIS GURU)
// ==============================================================================
function resolveMateriBinary($m) {
    $fileName = $m['file_materi'] ?: basename(parse_url($m['link_materi'] ?? '', PHP_URL_PATH)) ?: 'dokumen_materi.pdf';
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $mimeMap = [
        'pdf'  => 'application/pdf',
        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
        'mp4'  => 'video/mp4',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
    ];
    $contentType = $mimeMap[$ext] ?? (($m['tipe_materi'] ?? '') === 'PDF' ? 'application/pdf' : 'application/octet-stream');

    $localPath = '';
    if (!empty($m['link_materi'])) {
        if (strpos($m['link_materi'], BASE_URL) === 0) {
            $candidate = BASE_PATH . str_replace(BASE_URL, '', $m['link_materi']);
            if (file_exists($candidate) && is_file($candidate)) {
                $localPath = $candidate;
            }
        }
        if (!$localPath) {
            $parsed = parse_url($m['link_materi'], PHP_URL_PATH);
            if ($parsed && (strpos($parsed, '/uploads/') !== false || strpos($parsed, 'uploads/') !== false)) {
                $parsedRel = substr($parsed, strpos($parsed, 'uploads/'));
                if (file_exists(BASE_PATH . '/' . $parsedRel) && is_file(BASE_PATH . '/' . $parsedRel)) {
                    $localPath = BASE_PATH . '/' . $parsedRel;
                }
            }
        }
        if (!$localPath) {
            $trimmed = ltrim($m['link_materi'], '/');
            if (file_exists(BASE_PATH . '/' . $trimmed) && is_file(BASE_PATH . '/' . $trimmed)) {
                $localPath = BASE_PATH . '/' . $trimmed;
            }
        }
    }

    if ($localPath && file_exists($localPath)) {
        return [
            'success'      => true,
            'binary'       => file_get_contents($localPath),
            'content_type' => $contentType,
            'filename'     => $fileName,
            'ext'          => $ext
        ];
    }

    $gId = $m['gdrive_file_id'] ?? '';
    if (!$gId && preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $m['link_materi'] ?? '', $mMatch)) {
        $gId = $mMatch[1];
    }

    if ($gId) {
        $cookieFile = sys_get_temp_dir() . '/gdrive_cookie_' . md5($gId) . '.txt';
        $driveUrl = "https://drive.google.com/uc?export=download&id={$gId}&confirm=t";
        $ch = curl_init($driveUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_COOKIEJAR      => $cookieFile,
            CURLOPT_COOKIEFILE     => $cookieFile,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
        ]);
        $bin = curl_exec($ch);
        $fetchedMime = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        @unlink($cookieFile);

        $finalMime = $contentType;
        if (!empty($fetchedMime) && strpos($fetchedMime, 'application/octet-stream') === false && strpos($fetchedMime, 'text/html') === false) {
            $finalMime = $fetchedMime;
        }

        $isHtmlResponse = (stripos($bin, '<!DOCTYPE') !== false || stripos($bin, '<html') !== false);

        if ($isHtmlResponse && preg_match('/confirm=([0-9A-Za-z_-]+)/', $bin, $confM)) {
            $confirmCode = $confM[1];
            $driveUrlConfirm = "https://drive.google.com/uc?export=download&id={$gId}&confirm={$confirmCode}";
            $ch = curl_init($driveUrlConfirm);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
            ]);
            $bin2 = curl_exec($ch);
            curl_close($ch);
            if ($bin2 && strlen($bin2) > 0 && stripos($bin2, '<!DOCTYPE') === false && stripos($bin2, '<html') === false) {
                $bin = $bin2;
                $isHtmlResponse = false;
            }
        }

        if ($bin && strlen($bin) > 0 && !$isHtmlResponse) {
            return [
                'success'      => true,
                'binary'       => $bin,
                'content_type' => $finalMime,
                'filename'     => $fileName,
                'ext'          => $ext
            ];
        }
    }

    return ['success' => false, 'filename' => $fileName, 'ext' => $ext, 'content_type' => $contentType];
}

function isMateriAksesSiswa($m, $idKelas, $activeRole) {
    if ($activeRole !== 'siswa') return true;
    if ($idKelas <= 0) return true;
    if (empty($m['id_kelas_mata_pelajaran'])) return true;
    return ((int)($m['id_kelas'] ?? 0) === $idKelas);
}

// Download Materi
if (isset($_GET['action']) && $_GET['action'] === 'download_materi') {
    while (ob_get_level()) { ob_end_clean(); }
    $id = decodeMateriId($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT m.*, km.id_kelas 
        FROM materi m 
        LEFT JOIN kelas_mapel km ON m.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran 
        WHERE m.id = ? LIMIT 1
    ");
    $stmt->execute([$id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$m) {
        http_response_code(404);
        die('Materi tidak ditemukan.');
    }

    if (!isMateriAksesSiswa($m, $idKelas, $activeRole)) {
        http_response_code(403);
        die('Akses ditolak: Anda tidak terdaftar di kelas materi ini.');
    }

    $resolved = resolveMateriBinary($m);
    if ($resolved['success'] && !empty($resolved['binary'])) {
        $dlName = $resolved['filename'] ?: ('Materi_' . $id . '.' . ($resolved['ext'] ?: 'pdf'));
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($dlName) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . strlen($resolved['binary']));
        echo $resolved['binary'];
        exit;
    }

    if (!empty($m['gdrive_file_id'])) {
        header("Location: https://drive.google.com/uc?export=download&id=" . rawurlencode($m['gdrive_file_id']));
        exit;
    }
    if (!empty($m['link_materi'])) {
        header("Location: " . $m['link_materi']);
        exit;
    }

    http_response_code(404);
    die('Berkas unduhan tidak ditemukan.');
}

// Fallback PDF / Media stream proxy
if (isset($_GET['action']) && in_array($_GET['action'], ['pdf_stream', 'media_stream', 'stream_file'], true)) {
    while (ob_get_level()) { ob_end_clean(); }
    $materiId = decodeMateriId($_GET['id'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT m.*, km.id_kelas 
        FROM materi m 
        LEFT JOIN kelas_mapel km ON m.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran 
        WHERE m.id = ? LIMIT 1
    ");
    $stmt->execute([$materiId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$m) {
        http_response_code(404);
        die('Materi tidak ditemukan.');
    }

    if (!isMateriAksesSiswa($m, $idKelas, $activeRole)) {
        http_response_code(403);
        die('Akses ditolak.');
    }

    $resolved = resolveMateriBinary($m);
    if ($resolved['success'] && !empty($resolved['binary'])) {
        $mimeOut = ($_GET['action'] === 'pdf_stream') ? 'application/x-ilmuna-flipbook' : $resolved['content_type'];
        header("Content-Type: {$mimeOut}");
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($resolved['binary']));
        header('Cache-Control: private, max-age=86400');
        echo $resolved['binary'];
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(422);
    echo json_encode([
        'success'   => false,
        'fallback'  => true,
        'view_url'  => $m['link_materi'] ?? '',
        'gdrive_id' => $m['gdrive_file_id'] ?? '',
        'message'   => 'Berkas binary belum tersedia langsung.'
    ]);
    exit;
}

// POST AJAX HANDLER: FLIPBOOK BASE64 LOADER (SAMA PERSIS GURU VIEW)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        if ($action === 'get_flipbook_base64') {
            $rawId = $_POST['id'] ?? 0;
            $id = decodeMateriId($rawId);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID materi tidak valid.']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT m.*, km.id_kelas, km.nama_kelas, km.nama_mata_pelajaran
                FROM materi m
                LEFT JOIN kelas_mapel km ON m.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
                WHERE m.id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            $m = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$m) {
                echo json_encode(['success' => false, 'message' => 'Materi tidak ditemukan.']);
                exit;
            }

            if (!isMateriAksesSiswa($m, $idKelas, $activeRole)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda tidak terdaftar di kelas materi ini.']);
                exit;
            }

            $resolved = resolveMateriBinary($m);
            if ($resolved['success'] && !empty($resolved['binary'])) {
                echo json_encode([
                    'success'    => true,
                    'has_binary' => true,
                    'ext'        => $resolved['ext'],
                    'mime'       => $resolved['content_type'],
                    'filename'   => $resolved['filename'],
                    'base64'     => base64_encode($resolved['binary']),
                    'materi'     => $m
                ]);
                exit;
            }

            echo json_encode([
                'success'    => true,
                'has_binary' => false,
                'materi'     => $m
            ]);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ==============================================================================
// 3. DAFTAR MATA PELAJARAN KELAS SISWA (DARI KELAS_MAPEL)
// ==============================================================================
$myMapelList = [];
if ($idKelas > 0) {
    $stmtM = $pdo->prepare("
        SELECT km.id_kelas_mata_pelajaran AS id, km.nama_mata_pelajaran, km.semester, 
               km.nama_guru, km.nip_guru
        FROM kelas_mapel km
        WHERE km.id_kelas = ?
        ORDER BY km.nama_mata_pelajaran ASC
    ");
    $stmtM->execute([$idKelas]);
    $myMapelList = $stmtM->fetchAll(PDO::FETCH_ASSOC);
}

// ==============================================================================
// 4. QUERY MATERI SESUAI KELAS & FILTER
// ==============================================================================
$filterKmp  = (int)($_GET['kmp'] ?? 0);
$filterType = trim($_GET['type'] ?? 'all');
$filterQ    = trim($_GET['q'] ?? '');

$where = "WHERE (m.id_kelas_mata_pelajaran IN (SELECT id_kelas_mata_pelajaran FROM kelas_mapel WHERE id_kelas = :id_kelas) OR m.id_kelas_mata_pelajaran IS NULL OR m.id_kelas_mata_pelajaran = 0)";
$params = [':id_kelas' => $idKelas];

if ($filterKmp > 0) {
    $where .= " AND m.id_kelas_mata_pelajaran = :filter_kmp";
    $params[':filter_kmp'] = $filterKmp;
}

if ($filterType !== 'all' && in_array($filterType, ['PDF', 'Slide', 'Video', 'Teks', 'Gambar', 'Link Eksternal'], true)) {
    $where .= " AND m.tipe_materi = :filter_type";
    $params[':filter_type'] = $filterType;
}

if ($filterQ !== '') {
    $where .= " AND (m.judul_materi LIKE :kw OR m.deskripsi_singkat LIKE :kw OR km.nama_mata_pelajaran LIKE :kw)";
    $params[':kw'] = '%' . $filterQ . '%';
}

$sqlMateri = "
    SELECT m.*, 
           km.nama_kelas, km.nama_mata_pelajaran, km.semester, km.tahun_pelajaran_kelas,
           COALESCE(g.nama_guru, km.nama_guru, 'Guru Pengampu PAI') AS nama_guru_final
    FROM materi m
    LEFT JOIN kelas_mapel km ON m.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
    LEFT JOIN guru g ON m.id_guru = g.id
    {$where}
    ORDER BY m.created_at DESC, m.id DESC
";
$stmtMateri = $pdo->prepare($sqlMateri);
$stmtMateri->execute($params);
$materials = $stmtMateri->fetchAll(PDO::FETCH_ASSOC);

foreach ($materials as &$matRow) {
    $matRow['hash_id'] = hashMateriId($matRow['id']);
}
unset($matRow);

// Hitung Statistik format materi kelas ini
$statSummary = ['all' => 0, 'pdf' => 0, 'slide' => 0, 'video' => 0, 'teks' => 0, 'gambar' => 0];
$stmtStat = $pdo->prepare("
    SELECT m.tipe_materi, COUNT(*) as cnt 
    FROM materi m 
    WHERE (m.id_kelas_mata_pelajaran IN (SELECT id_kelas_mata_pelajaran FROM kelas_mapel WHERE id_kelas = ?) OR m.id_kelas_mata_pelajaran IS NULL OR m.id_kelas_mata_pelajaran = 0)
    GROUP BY m.tipe_materi
");
$stmtStat->execute([$idKelas]);
$statRows = $stmtStat->fetchAll(PDO::FETCH_ASSOC);
foreach ($statRows as $sr) {
    $t = strtolower($sr['tipe_materi']);
    $c = (int)$sr['cnt'];
    $statSummary['all'] += $c;
    if (isset($statSummary[$t])) $statSummary[$t] = $c;
}

// ==============================================================================
// 5. DETAIL SINGLE MATERI (JIKA PARAMETER ?id= TERSEDIA)
// ==============================================================================
$detailMateri = null;
$detailId = decodeMateriId($_GET['id'] ?? 0);
if ($detailId > 0) {
    $stmtD = $pdo->prepare("
        SELECT m.*, 
               km.nama_kelas, km.nama_mata_pelajaran, km.semester, km.tahun_pelajaran_kelas,
               COALESCE(g.nama_guru, km.nama_guru, 'Guru Pengampu PAI') AS nama_guru_final,
               g.nip, g.foto AS foto_guru
        FROM materi m
        LEFT JOIN kelas_mapel km ON m.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
        LEFT JOIN guru g ON m.id_guru = g.id
        WHERE m.id = ? LIMIT 1
    ");
    $stmtD->execute([$detailId]);
    $detailMateri = $stmtD->fetch(PDO::FETCH_ASSOC);
    if ($detailMateri) {
        $detailMateri['hash_id'] = hashMateriId($detailMateri['id']);
    }
}

$pageTitle = $detailMateri ? htmlspecialchars($detailMateri['judul_materi']) : 'Pusat Materi & Modul Ajar Digital';
require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<!-- LOCAL ASSETS PDF.JS & PAGE-FLIP (SAMA PERSIS PORTAL GURU & ADMIN) -->
<script src="<?= BASE_URL ?>/assets/js/pdf.min.js" onerror="window.__PDF_CDN=true;this.onerror=null;this.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';"></script>
<script src="<?= BASE_URL ?>/assets/js/page-flip.browser.js" onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.js';"></script>

<style>
/* ==============================================================================
 * STILISTIK PUSAT MATERI SISWA & PENAMPIL FLIPBOOK 3D (IDENTIK PORTAL GURU)
 * ============================================================================== */
:root {
    --mat-emerald-dark: #0A4D27;
    --mat-emerald-base: #0F6B38;
    --mat-emerald-light: #16A34A;
    --mat-gold: #E5A93B;
    --mat-navy: #1D3B5E;
    --mat-navy-dark: #111C2E;
    --mat-teal: #0D7A87;
    --mat-bg-soft: #F8FAFC;
    --mat-card-border: #E2E8F0;
    --mat-text-dark: #1E293B;
    --mat-text-muted: #64748B;

    --lumina-navy: #1D3B5E;
    --lumina-teal: #0D7A87;
    --lumina-sky: #52B2DC;
    --lumina-emerald: #10B981;
    --lumina-gold: #F59E0B;
    --lumina-rose: #F43F5E;
    --lumina-border: #E2E8F0;
    --lumina-bg-card: #FFFFFF;
}

.mat-siswa-wrapper {
    width: 100%;
    margin: 0 auto 50px;
    font-family: 'Plus Jakarta Sans', 'Outfit', 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: var(--mat-text-dark);
}

/* NOTICE BAR */
.mat-notice-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    background: #F0FDF4;
    border: 1px solid #BBF7D0;
    border-left: 5px solid var(--mat-emerald-base);
    padding: 12px 18px;
    border-radius: 12px;
    margin-bottom: 24px;
    font-size: 13.5px;
    color: #166534;
}
.mat-notice-bar strong {
    color: #14532D;
    font-weight: 700;
}

/* FILTER & TOOLBAR SECTION */
.mat-filter-box {
    background: #FFFFFF;
    border: 1px solid var(--mat-card-border);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 28px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.03);
}
.mat-filter-row-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid #F1F5F9;
}
.mat-filter-heading {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 15px;
    font-weight: 700;
    color: var(--mat-text-dark);
}
.mat-search-wrap {
    position: relative;
    min-width: 280px;
    flex: 1;
    max-width: 420px;
}
.mat-search-input {
    width: 100%;
    padding: 10px 16px 10px 40px;
    border-radius: 10px;
    border: 1px solid #CBD5E1;
    font-family: inherit;
    font-size: 14px;
    background: #F8FAFC;
    color: var(--mat-text-dark);
    transition: all 0.2s;
    box-sizing: border-box;
}
.mat-search-input:focus {
    outline: none;
    border-color: var(--mat-emerald-base);
    background: #FFFFFF;
    box-shadow: 0 0 0 3px rgba(15, 107, 56, 0.15);
}
.mat-search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: #94A3B8;
    font-size: 16px;
    pointer-events: none;
}

/* HORIZONTAL TABS FOR MAPEL */
.mat-mapel-tabs {
    display: flex;
    align-items: center;
    gap: 8px;
    overflow-x: auto;
    padding-bottom: 6px;
    margin-bottom: 14px;
    scrollbar-width: thin;
}
.mat-mapel-tab-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 30px;
    border: 1px solid #E2E8F0;
    background: #FFFFFF;
    color: var(--mat-text-muted);
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    text-decoration: none !important;
    transition: all 0.2s ease;
}
.mat-mapel-tab-btn:hover {
    border-color: var(--mat-emerald-base);
    color: var(--mat-emerald-base);
    background: #F0FDF4;
    transform: translateY(-1px);
}
.mat-mapel-tab-btn.active {
    background: var(--mat-emerald-base);
    color: #FFFFFF;
    border-color: var(--mat-emerald-base);
    box-shadow: 0 3px 10px rgba(15, 107, 56, 0.25);
}

/* TYPE FILTER PILLS */
.mat-type-pills {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.mat-type-pill-filter {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 8px;
    background: #F1F5F9;
    color: var(--mat-text-dark);
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    text-decoration: none !important;
    transition: all 0.2s;
}
.mat-type-pill-filter:hover {
    background: #E2E8F0;
}
.mat-type-pill-filter.active {
    background: #1D3B5E;
    color: #FFFFFF;
    border-color: #1D3B5E;
}

/* GRID OF MATERIAL CARDS */
.mat-card-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
    gap: 24px;
}

.mat-card {
    background: #FFFFFF;
    border-radius: 16px;
    border: 1px solid var(--mat-card-border);
    box-shadow: 0 4px 18px rgba(0, 0, 0, 0.04);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: all 0.35s cubic-bezier(0.165, 0.84, 0.44, 1);
    position: relative;
}
.mat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 14px 30px rgba(15, 107, 56, 0.12);
    border-color: #CBD5E1;
}

.mat-card__top-bar { height: 6px; width: 100%; }
.mat-card__top-bar.pdf    { background: linear-gradient(90deg, #EF4444, #DC2626); }
.mat-card__top-bar.slide  { background: linear-gradient(90deg, #F59E0B, #D97706); }
.mat-card__top-bar.video  { background: linear-gradient(90deg, #3B82F6, #2563EB); }
.mat-card__top-bar.teks   { background: linear-gradient(90deg, #10B981, #059669); }
.mat-card__top-bar.gambar { background: linear-gradient(90deg, #8B5CF6, #7C3AED); }

.mat-card__body {
    padding: 22px;
    display: flex;
    flex-direction: column;
    flex: 1;
}

.mat-card__meta-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
}
.mat-card__type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11.5px;
    font-weight: 700;
    text-transform: uppercase;
}
.mat-card__type-badge.pdf    { background: #FEE2E2; color: #DC2626; }
.mat-card__type-badge.slide  { background: #FEF3C7; color: #D97706; }
.mat-card__type-badge.video  { background: #DBEAFE; color: #2563EB; }
.mat-card__type-badge.teks   { background: #DCFCE7; color: #16A34A; }
.mat-card__type-badge.gambar { background: #EDE9FE; color: #7C3AED; }

.mat-card__mapel-chip {
    font-size: 12px;
    font-weight: 600;
    color: var(--mat-emerald-base);
    background: #F0FDF4;
    padding: 3px 10px;
    border-radius: 20px;
    max-width: 170px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.mat-card__title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.18rem;
    font-weight: 700;
    color: #1E293B;
    margin: 0 0 10px;
    line-height: 1.4;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.8em;
    cursor: pointer;
    transition: color 0.15s;
}
.mat-card__title:hover {
    color: #0D7A87;
}

.mat-card__desc {
    font-size: 0.88rem;
    color: #64748B;
    line-height: 1.6;
    margin-bottom: 18px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    flex: 1;
}

.mat-card__teacher-info {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 0;
    border-top: 1px dashed #E2E8F0;
    margin-bottom: 16px;
    font-size: 12.5px;
    color: #475569;
}
.mat-card__teacher-icon {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: #E2E8F0;
    color: #0F6B38;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
}

.mat-card__actions {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: auto;
}
.mat-btn-view-flipbook {
    flex: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: linear-gradient(135deg, var(--mat-emerald-base) 0%, var(--mat-emerald-dark) 100%);
    color: #FFFFFF !important;
    padding: 11px 16px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 13.5px;
    text-decoration: none !important;
    border: none;
    cursor: pointer;
    box-shadow: 0 3px 12px rgba(15, 107, 56, 0.25);
    transition: all 0.25s ease;
}
.mat-btn-view-flipbook:hover {
    background: linear-gradient(135deg, #16A34A 0%, #0F6B38 100%);
    box-shadow: 0 6px 18px rgba(15, 107, 56, 0.35);
    transform: translateY(-2px);
}
.mat-btn-view-flipbook.video {
    background: linear-gradient(135deg, #2563EB 0%, #1D4ED8 100%);
    box-shadow: 0 3px 12px rgba(37, 99, 235, 0.25);
}
.mat-btn-view-flipbook.video:hover {
    background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
}
.mat-btn-view-flipbook.gambar {
    background: linear-gradient(135deg, #0D7A87 0%, #1D3B5E 100%);
    box-shadow: 0 3px 12px rgba(13, 122, 135, 0.25);
}

.mat-btn-detail {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    border-radius: 10px;
    background: #F1F5F9;
    color: #334155 !important;
    border: 1px solid #E2E8F0;
    text-decoration: none !important;
    font-size: 16px;
    transition: all 0.2s;
}
.mat-btn-detail:hover {
    background: #E2E8F0;
    color: #0F172A !important;
}

/* DETAIL VIEW CONTAINER (UNUGIRI 2-COLUMN STYLE) */
.mat-detail-box { margin-bottom: 40px; }
.mat-back-bar { margin-bottom: 20px; }
.mat-back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 22px;
    background: #FFFFFF;
    color: #334155 !important;
    border: 1px solid #CBD5E1;
    border-radius: 50px;
    font-weight: 700;
    font-size: 13.5px;
    text-decoration: none !important;
    box-shadow: 0 2px 6px rgba(0,0,0,0.04);
    transition: all 0.25s;
}
.mat-back-btn:hover {
    background: #F8FAFC;
    color: var(--mat-emerald-base) !important;
    border-color: var(--mat-emerald-base);
    transform: translateX(-4px);
}

.mat-detail-grid {
    display: grid;
    grid-template-columns: 360px 1fr;
    gap: 28px;
    align-items: start;
}

.mat-detail-sidebar {
    background: #FFFFFF;
    border-radius: 18px;
    border: 1px solid var(--mat-card-border);
    padding: 26px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.04);
    position: sticky;
    top: 24px;
}
.mat-detail-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 12px;
    background: #E0F2FE;
    color: #0284C7;
}
.mat-detail-title {
    font-family: 'Outfit', sans-serif;
    font-size: 1.55rem;
    font-weight: 800;
    color: var(--mat-text-dark);
    margin: 0 0 16px;
    line-height: 1.35;
    padding-bottom: 14px;
    border-bottom: 3px solid var(--mat-gold);
}
.mat-detail-info-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 20px;
    font-size: 13.5px;
}
.mat-detail-info-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    color: #475569;
}
.mat-detail-info-item iconify-icon {
    font-size: 18px;
    color: var(--mat-emerald-base);
    margin-top: 2px;
    flex-shrink: 0;
}
.mat-detail-info-item strong { color: #0F172A; }

.mat-detail-desc-box {
    background: #F8FAFC;
    border-radius: 12px;
    padding: 16px;
    font-size: 13.5px;
    line-height: 1.7;
    color: #334155;
    border-left: 4px solid var(--mat-emerald-base);
    margin-bottom: 20px;
}

.mat-security-pill {
    background: #FEF3C7;
    border: 1px solid #FDE68A;
    color: #92400E;
    padding: 12px;
    border-radius: 10px;
    font-size: 12px;
    line-height: 1.5;
    display: flex;
    gap: 8px;
}

.mat-detail-main {
    background: #FFFFFF;
    border-radius: 18px;
    border: 1px solid var(--mat-card-border);
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.04);
}
.mat-detail-main__header {
    background: #111C2E;
    color: #FFFFFF;
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
}
.mat-detail-main__header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
}
.mat-detail-main__stage {
    min-height: 540px;
    background: #0B1320;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* ==============================================================================
 * BUTTONS, LIGHTBOX IMAGE & FLIPBOOK 3D MODAL (100% SAMA PERSIS PORTAL GURU)
 * ============================================================================== */
.mat-btn { display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px; border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 700; cursor: pointer; border: 1.5px solid transparent; transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); text-decoration: none; }
.mat-btn:hover { transform: translateY(-2px); }
.mat-btn:active { transform: translateY(0); }
.mat-btn--primary { background: linear-gradient(135deg, #0D7A87 0%, #1D3B5E 100%); color: #FFFFFF; box-shadow: 0 4px 14px -3px rgba(13, 122, 135, 0.4); }
.mat-btn--primary:hover { box-shadow: 0 8px 20px -4px rgba(13, 122, 135, 0.5); color: #FFFFFF; }
.mat-btn--download { background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #FFFFFF !important; box-shadow: 0 4px 14px -3px rgba(245, 158, 11, 0.4); }
.mat-btn--download:hover { background: linear-gradient(135deg, #D97706 0%, #B45309 100%); color: #FFFFFF !important; }
.mat-btn--outline { background: #FFFFFF; border-color: #E2E8F0; color: #475569; }
.mat-btn--outline:hover { border-color: #0D7A87; color: #0D7A87; background: #F8FAFC; }
.mat-btn--danger { background: #FFF1F2; border-color: #FECDD3; color: #E11D48; }
.mat-btn--danger:hover { background: #F43F5E; color: #FFFFFF; }
.mat-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none !important; }

.mat-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 29, 47, 0.65); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 99999; display: flex; align-items: center; justify-content: center; padding: 16px; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; }
.mat-modal.show { opacity: 1; pointer-events: auto; }
.mat-modal__box { background: #FFFFFF; border-radius: 20px; width: 100%; max-width: 640px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 60px -15px rgba(15, 29, 47, 0.35); transform: scale(0.95); transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1); overflow: hidden; }
.mat-modal.show .mat-modal__box { transform: scale(1); }
.mat-modal__close { background: none; border: none; font-size: 22px; color: #94A3B8; cursor: pointer; line-height: 1; transition: color 0.15s; }
.mat-modal__close:hover { color: #1E293B; }

.mat-modal--image { padding: 8px; }
.mat-image-box { max-width: 92vw !important; width: 92vw !important; height: 90vh !important; max-height: 90vh !important; background: #0B1320 !important; color: #F8FAFC !important; border: 1px solid rgba(255,255,255,0.12) !important; border-radius: 20px !important; box-shadow: 0 30px 80px rgba(0,0,0,0.6) !important; display: flex; flex-direction: column; overflow: hidden; }
.mat-image-header { padding: 12px 20px; background: #111C2E; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.mat-image-header__info { display: flex; align-items: center; gap: 12px; }
.mat-image-header__icon { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #10B981, #0D7A87); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-size: 20px; }
.mat-image-header h3 { font-family: 'Outfit', sans-serif; font-size: 16px; font-weight: 800; color: #FFFFFF; margin: 0; max-width: 480px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mat-image-header p { font-size: 11.5px; color: #94A3B8; margin: 2px 0 0; }
.mat-image-header__tools { display: flex; align-items: center; gap: 8px; }
.mat-image-stage { flex: 1; display: flex; align-items: center; justify-content: center; background: radial-gradient(circle at center, #1E293B 0%, #0B1320 100%); overflow: hidden; padding: 20px; position: relative; cursor: grab; }
.mat-image-stage:active { cursor: grabbing; }
.mat-image-wrap { transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1); display: flex; align-items: center; justify-content: center; max-width: 100%; max-height: 100%; }
.mat-image-wrap img { max-width: 82vw; max-height: 70vh; object-fit: contain; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); user-select: none; pointer-events: auto; }
.mat-image-footer { padding: 12px 20px; background: #111C2E; border-top: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; justify-content: space-between; }

.mat-modal--flipbook { padding: 8px; }
.mat-flipbook-box { max-width: 96vw !important; width: 96vw !important; height: 94vh !important; max-height: 94vh !important; background: #0B1320 !important; color: #F8FAFC !important; border: 1px solid rgba(255,255,255,0.12) !important; border-radius: 20px !important; box-shadow: 0 30px 80px rgba(0,0,0,0.6) !important; display: flex; flex-direction: column; overflow: hidden; }
.mat-flip-header { padding: 12px 20px; background: #111C2E; border-bottom: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.mat-flip-header__title { display: flex; align-items: center; gap: 12px; }
.mat-flip-header__icon { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #0D7A87, #1D3B5E); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-size: 20px; }
.mat-flip-header h3 { font-family: 'Outfit', sans-serif; font-size: 16px; font-weight: 800; color: #FFFFFF; margin: 0; max-width: 480px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mat-flip-header p { font-size: 11.5px; color: #94A3B8; margin: 2px 0 0; }
.mat-flip-header__actions { display: flex; align-items: center; gap: 8px; }
.mat-flip-mode-switch { display: flex; background: rgba(255,255,255,0.08); border-radius: 8px; padding: 3px; gap: 3px; }
.mat-flip-mode-btn { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 6px; background: transparent; border: none; color: #94A3B8; font-family: inherit; font-size: 11.5px; font-weight: 700; cursor: pointer; transition: all 0.2s; }
.mat-flip-mode-btn.active { background: #0D7A87; color: #FFFFFF; box-shadow: 0 2px 8px rgba(13, 122, 135, 0.4); }
.mat-flip-tool-btn { width: 34px; height: 34px; border-radius: 8px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); color: #CBD5E1; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; transition: all 0.2s; }
.mat-flip-tool-btn:hover { background: rgba(255,255,255,0.16); color: #FFFFFF; }
.mat-flip-tool-btn.active { color: #10B981; border-color: #10B981; }

.mat-flip-stage { flex: 1; position: relative; display: flex; align-items: stretch; justify-content: stretch; background: radial-gradient(circle at center, #1E293B 0%, #0B1320 100%); overflow: hidden; padding: 0; }

/* === 3D FLIPBOOK WRAPPER — DUAL AXIS SCROLL (HORIZONTAL & VERTIKAL) === */
.mat-flip-book-wrap {
    width: 100%;
    height: 100%;
    display: flex;
    overflow-x: auto !important;
    overflow-y: auto !important;
    perspective: 2000px;
    padding: 30px;
    box-sizing: border-box;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #0D7A87 #0B1320;
    cursor: grab;
}
.mat-flip-book-wrap:active {
    cursor: grabbing;
}
.mat-flip-book-wrap::-webkit-scrollbar { width: 12px; height: 12px; }
.mat-flip-book-wrap::-webkit-scrollbar-track { background: #0B1320; border-radius: 8px; border: 1px solid rgba(255,255,255,0.06); }
.mat-flip-book-wrap::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #0D7A87 0%, #14B8A6 100%); border-radius: 8px; border: 2px solid #0B1320; background-clip: padding-box; }
.mat-flip-book-wrap::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, #0F94A5 0%, #2DD4BF 100%); border: 2px solid #0B1320; }
.mat-flip-book-wrap::-webkit-scrollbar-corner { background: #0B1320; }

#flipBookContainer {
    box-shadow: 0 20px 60px rgba(0,0,0,0.55);
    margin: auto;
    flex-shrink: 0;
    transition: zoom 0.22s cubic-bezier(0.16, 1, 0.3, 1), transform 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    transform-origin: center center;
}

.mat-flip-page { background-color: #FAF8F5; background-image: linear-gradient(to right, rgba(0,0,0,0.04) 0%, transparent 8%, transparent 92%, rgba(0,0,0,0.04) 100%); overflow: hidden; position: relative; display: flex; flex-direction: column; box-shadow: inset 0 0 20px rgba(0,0,0,0.04); }
.mat-flip-page canvas { width: 100%; height: 100%; object-fit: contain; display: block; }
.mat-flip-page-num { position: absolute; bottom: 10px; font-size: 10.5px; font-weight: 700; color: #94A3B8; letter-spacing: 0.5px; }
.mat-flip-page:nth-child(odd) .mat-flip-page-num { right: 16px; }
.mat-flip-page:nth-child(even) .mat-flip-page-num { left: 16px; }

.mat-flip-cover { background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 100%); color: #FFFFFF; padding: 40px 30px; display: flex; flex-direction: column; justify-content: space-between; height: 100%; text-align: center; box-sizing: border-box; border-right: 4px solid #F59E0B; }
.mat-flip-text-page { padding: 34px 28px; font-size: 13.5px; line-height: 1.7; color: #1E293B; height: 100%; box-sizing: border-box; overflow-y: auto; }

.mat-flip-arrow { position: absolute; top: 50%; transform: translateY(-50%); width: 44px; height: 44px; border-radius: 50%; background: rgba(15, 23, 42, 0.75); border: 1px solid rgba(255,255,255,0.15); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-size: 20px; cursor: pointer; z-index: 10; backdrop-filter: blur(6px); transition: all 0.2s; }
.mat-flip-arrow:hover { background: #0D7A87; transform: translateY(-50%) scale(1.08); }
.mat-flip-arrow.prev { left: 20px; }
.mat-flip-arrow.next { right: 20px; }

.mat-flip-footer { padding: 12px 20px; background: #111C2E; border-top: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.mat-flip-footer__nav { display: flex; align-items: center; gap: 14px; }
.mat-flip-page-indicator { font-size: 13px; color: #CBD5E1; display: flex; align-items: center; gap: 5px; }
.mat-flip-page-indicator strong { color: #F59E0B; font-weight: 800; }
.mat-flip-footer__extra { display: flex; align-items: center; gap: 8px; }

.mat-flip-loader { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(11, 19, 32, 0.94); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 50; color: #FFFFFF; gap: 14px; }
.mat-flip-loader-bar { width: 240px; height: 6px; background: rgba(255,255,255,0.15); border-radius: 999px; overflow: hidden; }
.mat-flip-loader-bar > div { height: 100%; background: linear-gradient(90deg, #0D7A87, #10B981); transition: width 0.25s; }
@keyframes flipPulse { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.15); opacity: 0.7; } }

/* === SCROLL / SLIDE MODE — DUAL AXIS SCROLL (HORIZONTAL & VERTIKAL) + ZOOM === */
.mat-flip-scroll-wrap { position: relative; }

#flipScrollCanvasContainer {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 22px;
    padding: 60px 24px 90px;
    background: #0F172A;
    box-sizing: border-box;
    overflow-y: auto !important;
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
    scrollbar-color: #0D7A87 #0B1320;
    cursor: grab;
}
#flipScrollCanvasContainer:active {
    cursor: grabbing;
}
#flipScrollCanvasContainer::-webkit-scrollbar { width: 12px; height: 12px; }
#flipScrollCanvasContainer::-webkit-scrollbar-track { background: #0B1320; border-radius: 8px; border: 1px solid rgba(255,255,255,0.06); }
#flipScrollCanvasContainer::-webkit-scrollbar-thumb { background: linear-gradient(135deg, #0D7A87 0%, #14B8A6 100%); border-radius: 8px; border: 2px solid #0B1320; background-clip: padding-box; }
#flipScrollCanvasContainer::-webkit-scrollbar-thumb:hover { background: linear-gradient(135deg, #0F94A5 0%, #2DD4BF 100%); border: 2px solid #0B1320; }
#flipScrollCanvasContainer::-webkit-scrollbar-corner { background: #0B1320; }

.mat-scroll-page-box {
    margin: 0 auto;
    flex-shrink: 0;
    background: #FFF;
    box-shadow: 0 8px 28px rgba(0,0,0,0.45);
    border-radius: 8px;
    overflow: hidden;
    transition: width 0.22s cubic-bezier(0.16, 1, 0.3, 1);
    will-change: width;
    position: relative;
}
.mat-scroll-page-box canvas { display: block; width: 100%; height: auto; }

.mat-scroll-page-badge {
    position: absolute;
    bottom: 10px;
    right: 14px;
    background: rgba(0,0,0,0.62);
    color: #FFF;
    font-size: 11px;
    padding: 3px 9px;
    border-radius: 12px;
    font-weight: 700;
    letter-spacing: 0.3px;
    pointer-events: none;
    backdrop-filter: blur(4px);
}

/* FLOATING ZOOM TOOLBAR (dipakai scroll & 3D) */
.mat-flip-scroll-zoom-toolbar,
.mat-flip-3d-zoom-toolbar {
    position: absolute;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px;
    background: rgba(15, 23, 42, 0.88);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 999px;
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.45);
    z-index: 30;
}
.mat-flip-scroll-zoom-toolbar .mat-flip-tool-btn,
.mat-flip-3d-zoom-toolbar .mat-flip-tool-btn {
    width: 32px;
    height: 32px;
    font-size: 15px;
    background: rgba(255,255,255,0.08);
}
.mat-flip-scroll-zoom-toolbar .mat-flip-tool-btn:hover,
.mat-flip-3d-zoom-toolbar .mat-flip-tool-btn:hover {
    background: #0D7A87;
    color: #FFF;
}
.mat-flip-zoom-level-display {
    color: #F59E0B;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 12.5px;
    font-weight: 800;
    min-width: 54px;
    text-align: center;
    user-select: none;
    letter-spacing: 0.4px;
}

.mat-toast-container { position: fixed; top: 84px; right: 22px; z-index: 100001; display: flex; flex-direction: column; gap: 10px; max-width: 380px; pointer-events: none; }
.mat-toast-item { background: #FFFFFF; border-left: 4.5px solid #10B981; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.18); padding: 12px 16px; pointer-events: auto; animation: matToastIn 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.mat-toast-item.err { border-left-color: #F43F5E; }
.mat-toast-item.warn { border-left-color: #F59E0B; }
.mat-toast-title { font-size: 13px; font-weight: 800; color: #1D3B5E; margin-bottom: 2px; }
.mat-toast-msg { font-size: 12px; color: #475569; line-height: 1.45; }
@keyframes matToastIn { from { transform: translateX(120%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

/* ==============================================================================
 * TAMPILAN RESPONSIVE MOBILE OPTIMAL & RAPI (IDENTIK PORTAL GURU)
 * ============================================================================== */
@media (max-width: 992px) {
    .mat-detail-grid { grid-template-columns: 1fr; }
    .mat-detail-sidebar { position: static; }
}

@media (max-width: 768px) {
    .mat-siswa-wrapper { padding: 4px 0 28px; }
    .mat-card-grid { grid-template-columns: 1fr; gap: 14px; }
    .mat-card { border-radius: 14px; }

    /* Modal Flipbook Fullscreen Mobile Edge-to-Edge */
    .mat-modal--flipbook { padding: 0 !important; }
    .mat-flipbook-box {
        width: 100vw !important;
        max-width: 100vw !important;
        height: 100dvh !important;
        max-height: 100dvh !important;
        border-radius: 0 !important;
        margin: 0 !important;
        border: none !important;
    }

    .mat-flip-header {
        padding: 10px 12px;
        gap: 8px;
        flex-direction: column;
        align-items: stretch;
    }
    .mat-flip-header__title { gap: 8px; min-width: 0; width: 100%; display: flex; align-items: center; justify-content: space-between; }
    .mat-flip-header__icon { width: 32px; height: 32px; font-size: 16px; border-radius: 8px; flex-shrink: 0; }
    .mat-flip-header h3 { font-size: 13.5px; max-width: 220px; }
    .mat-flip-header p { font-size: 10.5px; }

    .mat-flip-header__actions {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
    }
    .mat-flip-mode-switch { flex: 1; }
    .mat-flip-mode-btn { flex: 1; justify-content: center; padding: 5px 8px; font-size: 11px; gap: 4px; }
    .mat-flip-tool-btn { width: 30px; height: 30px; font-size: 14px; }

    /* Side Flip Arrows on Mobile (Compact & Translucent) */
    .mat-flip-arrow {
        width: 34px;
        height: 34px;
        font-size: 16px;
        opacity: 0.75;
        background: rgba(15, 23, 42, 0.82);
    }
    .mat-flip-arrow.prev { left: 4px; }
    .mat-flip-arrow.next { right: 4px; }

    .mat-flip-book-wrap { padding: 14px 6px; }
    #flipScrollCanvasContainer { padding: 45px 8px 75px; gap: 14px; }

    /* Flipbook Footer Mobile Responsive */
    .mat-flip-footer {
        padding: 8px 12px;
        gap: 8px;
        flex-direction: column;
        align-items: stretch;
    }
    .mat-flip-footer__nav {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 6px;
    }
    .mat-flip-footer__nav .mat-btn { padding: 6px 12px; font-size: 11.5px; }
    .mat-flip-page-indicator { font-size: 11.5px; }

    .mat-flip-footer__extra {
        width: 100%;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px;
    }
    .mat-flip-footer__extra .mat-btn {
        width: 100%;
        justify-content: center;
        padding: 6px 8px;
        font-size: 11px;
    }

    /* Floating Zoom Toolbars on Mobile */
    .mat-flip-scroll-zoom-toolbar,
    .mat-flip-3d-zoom-toolbar {
        bottom: 8px;
        padding: 4px 8px;
        gap: 4px;
    }
    .mat-flip-scroll-zoom-toolbar .mat-flip-tool-btn,
    .mat-flip-3d-zoom-toolbar .mat-flip-tool-btn {
        width: 28px;
        height: 28px;
        font-size: 13px;
    }
    .mat-flip-zoom-level-display {
        font-size: 11px;
        min-width: 44px;
    }

    /* Image Viewer Modal Responsive */
    .mat-modal--image { padding: 0 !important; }
    .mat-image-box {
        width: 100vw !important;
        max-width: 100vw !important;
        height: 100dvh !important;
        max-height: 100dvh !important;
        border-radius: 0 !important;
        border: none !important;
    }
    .mat-image-header { padding: 10px 14px; }
    .mat-image-header h3 { font-size: 13.5px; max-width: 200px; }
    .mat-image-footer { padding: 8px 12px; flex-direction: column; gap: 8px; text-align: center; }
    .mat-image-wrap img { max-width: 96vw; max-height: 65vh; }
}
</style>

<div class="mat-siswa-wrapper">

    <!-- NOTICE BAR -->
    <div class="mat-notice-bar">
        <div style="display:flex; align-items:center; gap:10px;">
            <iconify-icon icon="solar:shield-check-bold" style="font-size:22px; color:var(--mat-emerald-base); flex-shrink:0;"></iconify-icon>
            <div>
                <strong>Pusat Baca Digital Flipbook 3D:</strong> Klik judul atau tombol <em>Buka Flipbook 3D</em> untuk membolak-balik lembaran modul digital secara interaktif lengkap dengan zoom, mode scroll vertikal, dan efek suara lembaran kertas.
            </div>
        </div>
        <div style="font-size:12.5px; font-weight:700; color:var(--mat-emerald-dark); display:flex; align-items:center; gap:6px;">
            <iconify-icon icon="solar:verified-check-bold"></iconify-icon>
            Total: <?= count($materials) ?> Modul Tersedia
        </div>
    </div>

    <?php if ($detailMateri): ?>
        <!-- ==============================================================================
         * TAMPILAN DETAIL MATERI (UNUGIRI 2-COLUMN VIEW ON-PAGE)
         * ============================================================================== -->
        <div class="mat-detail-box">
            <div class="mat-back-bar">
                <a href="materi.php<?= $filterKmp > 0 ? '?kmp=' . $filterKmp : '' ?>" class="mat-back-btn">
                    <iconify-icon icon="solar:arrow-left-bold" style="font-size:18px;"></iconify-icon>
                    Kembali ke Daftar Materi
                </a>
            </div>

            <div class="mat-detail-grid">
                <!-- Kolom Kiri: Meta Info Modul -->
                <div class="mat-detail-sidebar">
                    <span class="mat-detail-badge">
                        <?= htmlspecialchars($detailMateri['tipe_materi']) ?> DOKUMEN
                    </span>

                    <h2 class="mat-detail-title"><?= htmlspecialchars($detailMateri['judul_materi']) ?></h2>

                    <div class="mat-detail-info-list">
                        <div class="mat-detail-info-item">
                            <iconify-icon icon="solar:book-2-bold"></iconify-icon>
                            <div>
                                <small style="display:block; color:#94A3B8; font-size:11px;">MATA PELAJARAN</small>
                                <strong><?= htmlspecialchars($detailMateri['nama_mata_pelajaran'] ?: 'Pendidikan Agama Islam') ?></strong>
                            </div>
                        </div>

                        <div class="mat-detail-info-item">
                            <iconify-icon icon="solar:user-speak-rounded-bold"></iconify-icon>
                            <div>
                                <small style="display:block; color:#94A3B8; font-size:11px;">GURU PENGAMPU</small>
                                <strong><?= htmlspecialchars($detailMateri['nama_guru_final']) ?></strong>
                            </div>
                        </div>

                        <div class="mat-detail-info-item">
                            <iconify-icon icon="solar:users-group-two-rounded-bold"></iconify-icon>
                            <div>
                                <small style="display:block; color:#94A3B8; font-size:11px;">KELAS & SEMESTER</small>
                                <strong><?= htmlspecialchars($detailMateri['nama_kelas'] ?: $namaKelas) ?> (<?= htmlspecialchars($detailMateri['semester'] ?: 'Semester Aktif') ?>)</strong>
                            </div>
                        </div>

                        <div class="mat-detail-info-item">
                            <iconify-icon icon="solar:calendar-bold"></iconify-icon>
                            <div>
                                <small style="display:block; color:#94A3B8; font-size:11px;">DITERBITKAN</small>
                                <strong><?= date('d F Y', strtotime($detailMateri['created_at'])) ?></strong>
                            </div>
                        </div>

                        <?php if (!empty($detailMateri['durasi_menit'])): ?>
                        <div class="mat-detail-info-item">
                            <iconify-icon icon="solar:clock-circle-bold"></iconify-icon>
                            <div>
                                <small style="display:block; color:#94A3B8; font-size:11px;">ESTIMASI BACA</small>
                                <strong><?= (int)$detailMateri['durasi_menit'] ?> Menit</strong>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($detailMateri['deskripsi_singkat'])): ?>
                        <div class="mat-detail-desc-box">
                            <strong style="color:var(--mat-emerald-base); display:block; margin-bottom:4px;">Catatan Pembelajaran:</strong>
                            <?= nl2br(htmlspecialchars($detailMateri['deskripsi_singkat'])) ?>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:16px;">
                        <button type="button" class="mat-btn-view-flipbook" onclick="openMateriViewer(<?= (int)$detailMateri['id'] ?>)">
                            <iconify-icon icon="solar:book-2-bold" style="font-size:18px;"></iconify-icon>
                            Buka di Flipbook 3D Layar Penuh
                        </button>
                        <a href="?action=download_materi&id=<?= (int)$detailMateri['id'] ?>" class="mat-btn mat-btn--download" style="justify-content:center;" target="_blank">
                            <iconify-icon icon="solar:download-square-bold" style="font-size:18px;"></iconify-icon>
                            Download Materi
                        </a>
                    </div>
                </div>

                <!-- Kolom Kanan: Peluncur Langsung Flipbook / Konten -->
                <div class="mat-detail-main">
                    <div class="mat-detail-main__header">
                        <h4>
                            <iconify-icon icon="solar:book-bookmark-bold" style="color:var(--mat-gold); font-size:20px;"></iconify-icon>
                            Mode Baca: <?= htmlspecialchars($detailMateri['judul_materi']) ?>
                        </h4>
                        <div style="display:flex; gap:10px;">
                            <button type="button" class="mat-btn-view-flipbook" onclick="openMateriViewer(<?= (int)$detailMateri['id'] ?>)" style="padding:7px 14px; font-size:12.5px;">
                                <iconify-icon icon="solar:maximize-square-minimalistic-bold"></iconify-icon>
                                Buka Penampil Flipbook 3D
                            </button>
                        </div>
                    </div>

                    <div class="mat-detail-main__stage" id="inlineDetailStage">
                        <div style="width:100%; height:540px; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:40px; box-sizing:border-box;">
                            <div style="width:80px;height:80px;border-radius:24px;background:linear-gradient(135deg, rgba(13,122,135,0.2), rgba(229,169,59,0.2));display:flex;align-items:center;justify-content:center;margin-bottom:20px;border:1px solid rgba(255,255,255,0.15);">
                                <iconify-icon icon="solar:book-bookmark-bold" style="font-size:42px;color:var(--mat-gold);"></iconify-icon>
                            </div>
                            <h3 style="color:#FFF;font-family:'Outfit',sans-serif;font-size:22px;margin:0 0 10px;"><?= htmlspecialchars($detailMateri['judul_materi']) ?></h3>
                            <p style="color:#94A3B8;font-size:14px;max-width:480px;margin:0 0 24px;line-height:1.6;">Klik tombol di bawah untuk membuka lembaran modul secara interaktif dalam Flipbook 3D atau Mode Scroll Vertikal.</p>
                            <button type="button" class="mat-btn-view-flipbook" onclick="openMateriViewer(<?= (int)$detailMateri['id'] ?>)" style="flex:0; padding:12px 28px;font-size:15px;font-weight:800;border-radius:12px;">
                                <iconify-icon icon="solar:book-2-bold" style="font-size:22px;"></iconify-icon>
                                Buka Lembaran Flipbook 3D Sekarang
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ==============================================================================
     * FILTER TOOLBAR & SELEKSI MATA PELAJARAN
     * ============================================================================== -->
    <div class="mat-filter-box">
        <div class="mat-filter-row-top">
            <div class="mat-filter-heading">
                <iconify-icon icon="solar:filter-bold-duotone" style="font-size:22px; color:var(--mat-emerald-base);"></iconify-icon>
                <span>Filter Modul & Mata Pelajaran (<?= htmlspecialchars($namaKelas) ?>)</span>
            </div>

            <div class="mat-search-wrap">
                <iconify-icon icon="solar:magnifer-linear" class="mat-search-icon"></iconify-icon>
                <input type="text" id="matSearchInput" class="mat-search-input" placeholder="Cari judul materi, topik kajian, atau guru..." value="<?= htmlspecialchars($filterQ) ?>">
            </div>
        </div>

        <!-- TABS MATA PELAJARAN KELAS SISWA -->
        <div class="mat-mapel-tabs" id="matMapelTabs">
            <a href="?kmp=0<?= $filterType !== 'all' ? '&type='.$filterType : '' ?><?= $filterQ ? '&q='.urlencode($filterQ) : '' ?>" class="mat-mapel-tab-btn <?= $filterKmp === 0 ? 'active' : '' ?>">
                <iconify-icon icon="solar:widget-2-bold"></iconify-icon>
                Semua Mata Pelajaran
            </a>
            <?php foreach ($myMapelList as $mp): ?>
                <a href="?kmp=<?= (int)$mp['id'] ?><?= $filterType !== 'all' ? '&type='.$filterType : '' ?><?= $filterQ ? '&q='.urlencode($filterQ) : '' ?>" class="mat-mapel-tab-btn <?= $filterKmp === (int)$mp['id'] ? 'active' : '' ?>">
                    <iconify-icon icon="solar:book-2-bold"></iconify-icon>
                    <?= htmlspecialchars($mp['nama_mata_pelajaran']) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- TIPE FORMAT PILLS -->
        <div class="mat-type-pills">
            <a href="?type=all<?= $filterKmp > 0 ? '&kmp='.$filterKmp : '' ?><?= $filterQ ? '&q='.urlencode($filterQ) : '' ?>" class="mat-type-pill-filter <?= $filterType === 'all' ? 'active' : '' ?>">
                Semua Format (<?= $statSummary['all'] ?>)
            </a>
            <a href="?type=PDF<?= $filterKmp > 0 ? '&kmp='.$filterKmp : '' ?><?= $filterQ ? '&q='.urlencode($filterQ) : '' ?>" class="mat-type-pill-filter <?= $filterType === 'PDF' ? 'active' : '' ?>">
                📖 PDF / Flipbook 3D (<?= $statSummary['pdf'] ?>)
            </a>
            <a href="?type=Slide<?= $filterKmp > 0 ? '&kmp='.$filterKmp : '' ?><?= $filterQ ? '&q='.urlencode($filterQ) : '' ?>" class="mat-type-pill-filter <?= $filterType === 'Slide' ? 'active' : '' ?>">
                📊 Slide / PPT (<?= $statSummary['slide'] ?>)
            </a>
            <a href="?type=Gambar<?= $filterKmp > 0 ? '&kmp='.$filterKmp : '' ?><?= $filterQ ? '&q='.urlencode($filterQ) : '' ?>" class="mat-type-pill-filter <?= $filterType === 'Gambar' ? 'active' : '' ?>">
                🖼️ Gambar / Foto (<?= $statSummary['gambar'] ?>)
            </a>
            <a href="?type=Teks<?= $filterKmp > 0 ? '&kmp='.$filterKmp : '' ?><?= $filterQ ? '&q='.urlencode($filterQ) : '' ?>" class="mat-type-pill-filter <?= $filterType === 'Teks' ? 'active' : '' ?>">
                📝 Dokumen / Teks (<?= $statSummary['teks'] ?>)
            </a>
            <a href="?type=Video<?= $filterKmp > 0 ? '&kmp='.$filterKmp : '' ?><?= $filterQ ? '&q='.urlencode($filterQ) : '' ?>" class="mat-type-pill-filter <?= $filterType === 'Video' ? 'active' : '' ?>">
                🎥 Video Pembelajaran (<?= $statSummary['video'] ?>)
            </a>
        </div>
    </div>

    <!-- ==============================================================================
     * GRID KARTU MATERI SISWA
     * ============================================================================== -->
    <?php if (empty($materials)): ?>
        <div style="text-align:center; padding:60px 20px; background:#FFFFFF; border-radius:18px; border:1px solid #E2E8F0;">
            <iconify-icon icon="solar:book-minimalistic-bold-duotone" style="font-size:64px; color:#94A3B8; margin-bottom:14px;"></iconify-icon>
            <h3 style="font-family:'Outfit'; font-size:1.3rem; font-weight:700; color:#1E293B; margin:0 0 8px;">Belum Ada Materi Ditemukan</h3>
            <p style="color:#64748B; font-size:14px; max-width:500px; margin:0 auto 16px;">
                Belum ada bahan ajar yang sesuai dengan filter atau kata kunci pencarian Anda. Silakan pilih mata pelajaran lain atau hubungi guru pengampu.
            </p>
            <a href="materi.php" class="mat-btn-view-flipbook" style="display:inline-flex; width:auto; padding:8px 20px;">
                Reset Filter
            </a>
        </div>
    <?php else: ?>
        <div class="mat-card-grid" id="matCardsContainer">
            <?php foreach ($materials as $m): 
                $tipeClass = strtolower($m['tipe_materi']);
                $iconMap = [
                    'pdf'    => 'solar:document-bold',
                    'slide'  => 'solar:presentation-graph-bold',
                    'video'  => 'solar:play-circle-bold',
                    'teks'   => 'solar:notes-bold',
                    'gambar' => 'solar:gallery-bold'
                ];
                $icon = $iconMap[$tipeClass] ?? 'solar:file-bold';
                $btnLabel = 'Buka Flipbook 3D';
                if ($m['tipe_materi'] === 'Video') $btnLabel = 'Tonton Video';
                elseif ($m['tipe_materi'] === 'Gambar') $btnLabel = 'Lihat Gambar';
            ?>
                <div class="mat-card" data-title="<?= strtolower(htmlspecialchars($m['judul_materi'])) ?>" data-desc="<?= strtolower(htmlspecialchars($m['deskripsi_singkat'] ?? '')) ?>" data-mapel="<?= strtolower(htmlspecialchars($m['nama_mata_pelajaran'] ?? '')) ?>">
                    <div class="mat-card__top-bar <?= $tipeClass ?>"></div>
                    <div class="mat-card__body">

                        <div class="mat-card__meta-head">
                            <span class="mat-card__type-badge <?= $tipeClass ?>">
                                <iconify-icon icon="<?= $icon ?>"></iconify-icon>
                                <?= htmlspecialchars($m['tipe_materi']) ?>
                            </span>
                            <span class="mat-card__mapel-chip" title="<?= htmlspecialchars($m['nama_mata_pelajaran'] ?: 'Umum') ?>">
                                <?= htmlspecialchars($m['nama_mata_pelajaran'] ?: 'PAI & Budi Pekerti') ?>
                            </span>
                        </div>

                        <h3 class="mat-card__title" onclick="openMateriViewer(<?= (int)$m['id'] ?>)" title="Klik untuk Membaca di Flipbook 3D">
                            <?= htmlspecialchars($m['judul_materi']) ?>
                        </h3>

                        <p class="mat-card__desc">
                            <?= htmlspecialchars($m['deskripsi_singkat'] ?: ($m['file_materi'] ?: 'Klik untuk membuka dan mempelajari lembaran bahan kajian modul ini.')) ?>
                        </p>

                        <div class="mat-card__teacher-info">
                            <div class="mat-card__teacher-icon">
                                <iconify-icon icon="solar:user-bold"></iconify-icon>
                            </div>
                            <div style="flex:1; overflow:hidden;">
                                <div style="font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <?= htmlspecialchars($m['nama_guru_final']) ?>
                                </div>
                                <div style="font-size:11px; color:#94A3B8;">
                                    <?= date('d M Y', strtotime($m['created_at'])) ?>
                                    <?php if (!empty($m['durasi_menit'])): ?> &bull; <?= (int)$m['durasi_menit'] ?> Menit<?php endif; ?>
                                    <?php if (!empty($m['ukuran_file'])): ?> &bull; <?= htmlspecialchars($m['ukuran_file']) ?><?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="mat-card__actions">
                            <button type="button" class="mat-btn-view-flipbook <?= $tipeClass ?>" onclick="openMateriViewer(<?= (int)$m['id'] ?>)">
                                <iconify-icon icon="<?= $m['tipe_materi'] === 'Video' ? 'solar:play-circle-bold' : ($m['tipe_materi'] === 'Gambar' ? 'solar:gallery-bold' : 'solar:book-bookmark-bold') ?>" style="font-size:18px;"></iconify-icon>
                                <?= $btnLabel ?>
                            </button>
                            <a href="?action=download_materi&id=<?= (int)$m['id'] ?>" class="mat-btn-detail" title="Download Berkas Materi" target="_blank" style="background:#ECFDF5; color:#059669 !important; border-color:#A7F3D0;">
                                <iconify-icon icon="solar:download-minimalistic-bold"></iconify-icon>
                            </a>
                            <a href="?id=<?= urlencode($m['hash_id']) ?>" class="mat-btn-detail" title="Lihat Detail Materi">
                                <iconify-icon icon="solar:eye-bold"></iconify-icon>
                            </a>
                        </div>

                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- ==============================================================================
 * MODAL PENAMPIL FLIPBOOK 3D & SCROLL VERTIKAL (100% SAMA PERSIS PORTAL GURU)
 * ============================================================================== -->
<div class="mat-modal mat-modal--flipbook" id="modalFlipbook">
    <div class="mat-modal__box mat-flipbook-box">
        <div class="mat-flip-header">
            <div class="mat-flip-header__title">
                <div class="mat-flip-header__icon">
                    <iconify-icon icon="solar:book-2-bold"></iconify-icon>
                </div>
                <div>
                    <h3 id="flipTitle">Judul Materi Pembelajaran</h3>
                    <p id="flipMeta">X PAI-1 • Pendidikan Agama Islam • Mode Flipbook 3D</p>
                </div>
            </div>

            <div class="mat-flip-header__actions">
                <div class="mat-flip-mode-switch" id="flipModeSwitch">
                    <button type="button" class="mat-flip-mode-btn active" data-mode="3d" id="btnMode3d" title="Mode Buku Flipbook 3D (Buka Lembaran)">
                        <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                        <span>Flipbook 3D</span>
                    </button>
                    <button type="button" class="mat-flip-mode-btn" data-mode="scroll" id="btnModeScroll" title="Mode Lembaran Vertikal / Slide">
                        <iconify-icon icon="solar:document-text-bold"></iconify-icon>
                        <span>Scroll / Slide</span>
                    </button>
                </div>

                <button type="button" class="mat-flip-tool-btn active" id="btnFlipSound" title="Efek Suara Lembar Kertas">
                    <iconify-icon icon="solar:volume-loud-bold" id="iconFlipSound"></iconify-icon>
                </button>

                <button type="button" class="mat-flip-tool-btn" id="btnFlipFullscreen" title="Layar Penuh (Fullscreen)">
                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon>
                </button>

                <button type="button" class="mat-modal__close" onclick="closeFlipbookModal()" style="color:#FFF;font-size:26px;">&times;</button>
            </div>
        </div>

        <div class="mat-flip-stage" id="flipStage">
            <div class="mat-flip-loader" id="flipLoader">
                <div class="mat-flip-book-spinner">
                    <iconify-icon icon="solar:book-2-bold" style="font-size:44px;color:#0D7A87;animation:flipPulse 1.2s infinite ease-in-out;"></iconify-icon>
                </div>
                <div id="flipLoaderText" style="font-weight:700;font-size:13.5px;">Menyiapkan lembaran Flipbook...</div>
                <div class="mat-flip-loader-bar">
                    <div id="flipLoaderProgress" style="width: 25%;"></div>
                </div>
            </div>

            <!-- 3D StPageFlip Wrapper (scroll + zoom) -->
            <div class="mat-flip-book-wrap" id="flipBookWrap">
                <div id="flipBookContainer"></div>
            </div>

            <!-- Alternative Scroll/Embed Viewer -->
            <div class="mat-flip-scroll-wrap" id="flipScrollWrap" style="display:none;width:100%;height:100%;position:relative;overflow:hidden;border-radius:12px;">
                <div id="flipScrollCanvasContainer" style="display:none;"></div>
                <iframe id="flipScrollIframe" src="about:blank" style="width:100%;height:100%;border:none;background:#FFF;border-radius:12px;"></iframe>

                <div class="mat-flip-scroll-zoom-toolbar" id="scrollZoomToolbar" style="display:none;">
                    <button type="button" class="mat-flip-tool-btn" id="btnScrollZoomOut" title="Perkecil (Ctrl -)">
                        <iconify-icon icon="solar:magnifer-zoom-out-bold"></iconify-icon>
                    </button>
                    <span class="mat-flip-zoom-level-display" id="scrollZoomLabel">100%</span>
                    <button type="button" class="mat-flip-tool-btn" id="btnScrollZoomIn" title="Perbesar (Ctrl +)">
                        <iconify-icon icon="solar:magnifer-zoom-in-bold"></iconify-icon>
                    </button>
                    <button type="button" class="mat-flip-tool-btn" id="btnScrollZoomReset" title="Reset ke 100%">
                        <iconify-icon icon="solar:refresh-square-bold"></iconify-icon>
                    </button>
                </div>
            </div>

            <!-- Side Clickable Arrows -->
            <button type="button" class="mat-flip-arrow prev" id="btnFlipPrevArrow" title="Lembar Sebelumnya">
                <iconify-icon icon="solar:alt-arrow-left-bold"></iconify-icon>
            </button>
            <button type="button" class="mat-flip-arrow next" id="btnFlipNextArrow" title="Lembar Berikutnya">
                <iconify-icon icon="solar:alt-arrow-right-bold"></iconify-icon>
            </button>

            <!-- FLOATING ZOOM TOOLBAR — 3D FLIPBOOK -->
            <div class="mat-flip-3d-zoom-toolbar" id="flip3dZoomToolbar" style="display:none;">
                <button type="button" class="mat-flip-tool-btn" id="btn3dZoomOut" title="Perkecil (Ctrl -)">
                    <iconify-icon icon="solar:magnifer-zoom-out-bold"></iconify-icon>
                </button>
                <span class="mat-flip-zoom-level-display" id="flip3dZoomLabel">100%</span>
                <button type="button" class="mat-flip-tool-btn" id="btn3dZoomIn" title="Perbesar (Ctrl +)">
                    <iconify-icon icon="solar:magnifer-zoom-in-bold"></iconify-icon>
                </button>
                <button type="button" class="mat-flip-tool-btn" id="btn3dZoomReset" title="Reset ke 100%">
                    <iconify-icon icon="solar:refresh-square-bold"></iconify-icon>
                </button>
            </div>
        </div>

        <div class="mat-flip-footer">
            <div class="mat-flip-footer__nav">
                <button type="button" class="mat-btn mat-btn--outline" id="btnFlipPrev" style="color:#FFF;border-color:rgba(255,255,255,0.2);background:rgba(255,255,255,0.08);">
                    <iconify-icon icon="solar:alt-arrow-left-bold"></iconify-icon>
                    <span>Sebelumnya</span>
                </button>

                <div class="mat-flip-page-indicator">
                    <span>Halaman</span>
                    <strong id="flipCurPage">1</strong>
                    <span>dari</span>
                    <strong id="flipTotalPages">1</strong>
                </div>

                <button type="button" class="mat-btn mat-btn--primary" id="btnFlipNext">
                    <span>Berikutnya</span>
                    <iconify-icon icon="solar:alt-arrow-right-bold"></iconify-icon>
                </button>
            </div>

            <div class="mat-flip-footer__extra">
                <button type="button" class="mat-btn mat-btn--outline" style="padding:7px 14px;font-size:12px;color:#FFF;border-color:rgba(255,255,255,0.2);background:rgba(255,255,255,0.08);" id="btnFlipAutoPlay">
                    <iconify-icon icon="solar:play-circle-bold"></iconify-icon>
                    <span id="txtAutoPlay">Buka Otomatis</span>
                </button>

                <a href="#" class="mat-btn mat-btn--download" style="padding:7px 16px;font-size:12px;text-decoration:none;" id="btnFlipDownloadFile" title="Unduh Berkas Materi Ini" target="_blank">
                    <iconify-icon icon="solar:download-square-bold" style="font-size:16px;"></iconify-icon>
                    <span>Download Materi</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- ==============================================================================
 * MODAL PENAMPIL GAMBAR / LIGHTBOX (100% SAMA PERSIS PORTAL GURU)
 * ============================================================================== -->
<div class="mat-modal mat-modal--image" id="modalImageViewer">
    <div class="mat-modal__box mat-image-box">
        <div class="mat-image-header">
            <div class="mat-image-header__info">
                <div class="mat-image-header__icon">
                    <iconify-icon icon="solar:gallery-bold"></iconify-icon>
                </div>
                <div>
                    <h3 id="imageViewerTitle">Pratinjau Gambar</h3>
                    <p id="imageViewerMeta">PNG/JPG • Mode Tampilan Layar Penuh</p>
                </div>
            </div>
            <div class="mat-image-header__tools">
                <button type="button" class="mat-flip-tool-btn" id="btnImgZoomIn" title="Perbesar (+)">
                    <iconify-icon icon="solar:magnifer-zoom-in-bold"></iconify-icon>
                </button>
                <button type="button" class="mat-flip-tool-btn" id="btnImgZoomOut" title="Perkecil (-)">
                    <iconify-icon icon="solar:magnifer-zoom-out-bold"></iconify-icon>
                </button>
                <button type="button" class="mat-flip-tool-btn" id="btnImgReset" title="Reset Ukuran (100%)">
                    <iconify-icon icon="solar:refresh-square-bold"></iconify-icon>
                </button>
                <button type="button" class="mat-flip-tool-btn" id="btnImgRotate" title="Putar Gambar 90°">
                    <iconify-icon icon="solar:restart-bold"></iconify-icon>
                </button>
                <button type="button" class="mat-flip-tool-btn" id="btnImgFullscreen" title="Layar Penuh (Fullscreen)">
                    <iconify-icon icon="solar:maximize-square-bold"></iconify-icon>
                </button>
                <button type="button" class="mat-modal__close" onclick="closeModal('modalImageViewer')" style="color:#FFF;font-size:26px;">&times;</button>
            </div>
        </div>
        <div class="mat-image-stage" id="imageStage">
            <div class="mat-image-wrap" id="imageWrap">
                <img id="imageViewerSrc" src="" alt="Pratinjau Materi Gambar" draggable="false">
            </div>
        </div>
        <div class="mat-image-footer">
            <div id="imageViewerCaption" style="font-size:12.5px;color:#CBD5E1;">Format Gambar • Dilihat Langsung Tanpa Download</div>
            <a href="#" class="mat-btn mat-btn--download" style="padding:7px 16px;font-size:12px;text-decoration:none;" id="btnImageDownloadLink" target="_blank">
                <iconify-icon icon="solar:download-square-bold" style="font-size:16px;"></iconify-icon>
                <span>Download Gambar</span>
            </a>
        </div>
    </div>
</div>

<div class="mat-toast-container" id="matToastWrap"></div>

<script>
/**
 * ==============================================================================
 * FLIPBOOK 3D & MULTI-FORMAT DOCUMENT ENGINE (100% IDENTIK PORTAL GURU & ADMIN)
 * ==============================================================================
 */
(function(){
    const ENDPOINT = window.location.pathname;

    let pageFlipInstance  = null;
    let flipSfxEnabled    = true;
    let flipAutoPlayTimer = null;
    let currentViewerData = null;
    let currentPdfDoc     = null;
    let currentPdfUint8   = null;
    let scrollZoomLevel   = 1;
    let flip3dZoomLevel   = 1;

    let imgZoomLevel  = 1;
    let imgRotation   = 0;
    let isImgDragging = false;
    let imgStartX = 0, imgStartY = 0, imgTranslateX = 0, imgTranslateY = 0;

    function base64ToUint8Array(base64) {
        const raw = window.atob(base64);
        const rawLength = raw.length;
        const array = new Uint8Array(new ArrayBuffer(rawLength));
        for (let i = 0; i < rawLength; i++) {
            array[i] = raw.charCodeAt(i);
        }
        return array;
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    function showToast(title, message, type = 'success') {
        const _ilmType = (type === 'warn') ? 'warning' : (type || 'success');
        if (window.Ilmuna) return Ilmuna.toast(_ilmType, title, message, 4000);
        const wrap = document.getElementById('matToastWrap');
        if (!wrap) return;
        const item = document.createElement('div');
        item.className = 'mat-toast-item ' + (type === 'error' ? 'err' : (type === 'warn' ? 'warn' : ''));
        item.innerHTML = `
            <div class="mat-toast-title">${title}</div>
            <div class="mat-toast-msg">${message}</div>
        `;
        wrap.appendChild(item);
        setTimeout(() => {
            item.style.opacity = '0';
            item.style.transform = 'translateX(120%)';
            setTimeout(() => item.remove(), 250);
        }, 4000);
    }

    window.openModal = function(id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('show');
    };
    window.closeModal = function(id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('show');
    };

    document.querySelectorAll('.mat-modal').forEach(m => {
        m.addEventListener('click', function(e) {
            if (e.target === this) {
                if (this.id === 'modalFlipbook') {
                    closeFlipbookModal();
                } else {
                    this.classList.remove('show');
                }
            }
        });
    });

    function playPaperTurnSound() {
        if (!flipSfxEnabled) return;
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const bufferSize = ctx.sampleRate * 0.12;
            const buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
            const data = buffer.getChannelData(0);
            for (let i = 0; i < bufferSize; i++) {
                data[i] = (Math.random() * 2 - 1) * Math.exp(-i / (bufferSize * 0.3));
            }
            const noise = ctx.createBufferSource();
            noise.buffer = buffer;
            const filter = ctx.createBiquadFilter();
            filter.type = 'bandpass';
            filter.frequency.setValueAtTime(800, ctx.currentTime);
            filter.frequency.exponentialRampToValueAtTime(300, ctx.currentTime + 0.12);
            filter.Q.value = 1.2;
            const gain = ctx.createGain();
            gain.gain.setValueAtTime(0.2, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.12);
            noise.connect(filter);
            filter.connect(gain);
            gain.connect(ctx.destination);
            noise.start();
        } catch(e) {}
    }

    function resetFlipBookContainer() {
        if (pageFlipInstance) {
            try {
                pageFlipInstance.destroy();
            } catch(e) {}
            pageFlipInstance = null;
        }
        if (flipAutoPlayTimer) {
            clearInterval(flipAutoPlayTimer);
            flipAutoPlayTimer = null;
            const t = document.getElementById('txtAutoPlay');
            if (t) t.textContent = 'Buka Otomatis';
        }
        const wrap = document.getElementById('flipBookWrap');
        if (!wrap) return null;
        wrap.innerHTML = '<div id="flipBookContainer"></div>';
        flip3dZoomLevel = 1;
        apply3dZoom();
        return document.getElementById('flipBookContainer');
    }

    // ==============================================================================
    // 3D ZOOM & HORIZONTAL/VERTIKAL SCROLL ALIGNMENT
    // ==============================================================================
    function updateFlipBookAlignment() {
        const container = document.getElementById('flipBookContainer');
        const wrap = document.getElementById('flipBookWrap');
        if (!container || !wrap) return;

        const wrapW = wrap.clientWidth;
        const wrapH = wrap.clientHeight;
        const scale = flip3dZoomLevel || 1;
        const contW = (container.offsetWidth || 0) * scale;
        const contH = (container.offsetHeight || 0) * scale;

        if (contW >= wrapW - 60) {
            container.style.marginLeft = '0px';
            container.style.marginRight = '48px';
        } else {
            container.style.marginLeft = 'auto';
            container.style.marginRight = 'auto';
        }

        if (contH >= wrapH - 60) {
            container.style.marginTop = '0px';
            container.style.marginBottom = '48px';
        } else {
            container.style.marginTop = 'auto';
            container.style.marginBottom = 'auto';
        }
    }

    function apply3dZoom() {
        const container = document.getElementById('flipBookContainer');
        if (!container) return;
        container.style.zoom = flip3dZoomLevel;
        updateFlipBookAlignment();
        const label = document.getElementById('flip3dZoomLabel');
        if (label) label.textContent = Math.round(flip3dZoomLevel * 100) + '%';
    }
    function zoom3dIn()    { flip3dZoomLevel = Math.min(flip3dZoomLevel + 0.2, 2.6); apply3dZoom(); }
    function zoom3dOut()   { flip3dZoomLevel = Math.max(flip3dZoomLevel - 0.2, 0.5); apply3dZoom(); }
    function zoom3dReset() { flip3dZoomLevel = 1; apply3dZoom(); }

    // ==============================================================================
    // SCROLL ZOOM & HORIZONTAL/VERTIKAL SCROLL ALIGNMENT
    // ==============================================================================
    function applyScrollZoom() {
        const scrollCanvasWrap = document.getElementById('flipScrollCanvasContainer');
        if (!scrollCanvasWrap) return;

        const pages = scrollCanvasWrap.querySelectorAll('.mat-scroll-page-box');
        if (!pages.length) return;

        const cs = window.getComputedStyle(scrollCanvasWrap);
        const padL = parseFloat(cs.paddingLeft) || 0;
        const padR = parseFloat(cs.paddingRight) || 0;
        const containerWidth = scrollCanvasWrap.clientWidth - padL - padR;

        const baseWidth = Math.max(280, containerWidth);
        const targetWidth = Math.round(baseWidth * scrollZoomLevel);

        pages.forEach(p => {
            p.style.width = targetWidth + 'px';
            if (targetWidth >= containerWidth) {
                p.style.marginLeft = '0px';
                p.style.marginRight = '48px';
            } else {
                p.style.marginLeft = 'auto';
                p.style.marginRight = 'auto';
            }
        });

        const label = document.getElementById('scrollZoomLabel');
        if (label) label.textContent = Math.round(scrollZoomLevel * 100) + '%';
    }
    function scrollZoomIn()    { scrollZoomLevel = Math.min(scrollZoomLevel + 0.25, 3.2); applyScrollZoom(); }
    function scrollZoomOut()   { scrollZoomLevel = Math.max(scrollZoomLevel - 0.25, 0.5); applyScrollZoom(); }
    function scrollZoomReset() { scrollZoomLevel = 1; applyScrollZoom(); }

    // ==============================================================================
    // DUAL-AXIS DRAG TO SCROLL (PANNING MOUSE & SENTUHAN)
    // ==============================================================================
    function enableDualAxisDragScroll(element) {
        if (!element || element._dragScrollEnabled) return;
        element._dragScrollEnabled = true;

        let isDown = false;
        let startX, startY;
        let scrollLeft, scrollTop;

        element.addEventListener('mousedown', (e) => {
            if (e.target.closest('button, a, input, select, .mat-flip-tool-btn, .mat-flip-mode-btn, .mat-flip-arrow')) return;
            isDown = true;
            element.style.cursor = 'grabbing';
            startX = e.pageX - element.offsetLeft;
            startY = e.pageY - element.offsetTop;
            scrollLeft = element.scrollLeft;
            scrollTop = element.scrollTop;
        });

        window.addEventListener('mouseup', () => {
            if (isDown) {
                isDown = false;
                element.style.cursor = 'grab';
            }
        });

        element.addEventListener('mousemove', (e) => {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - element.offsetLeft;
            const y = e.pageY - element.offsetTop;
            const walkX = (x - startX) * 1.25;
            const walkY = (y - startY) * 1.25;
            element.scrollLeft = scrollLeft - walkX;
            element.scrollTop = scrollTop - walkY;
        });

        element.addEventListener('wheel', (e) => {
            if (e.shiftKey) {
                e.preventDefault();
                element.scrollLeft += e.deltaY;
            }
        }, { passive: false });
    }

    // ==============================================================================
    // MAIN VIEWER LAUNCHER (IDENTIK PORTAL GURU)
    // ==============================================================================
    window.openMateriViewer = async function(id) {
        try {
            const fd = new FormData();
            fd.append('action', 'get_flipbook_base64');
            fd.append('id', id);

            const res = await fetch(ENDPOINT, { method: 'POST', body: fd }).then(r => r.json());
            if (!res || !res.success || !res.materi) {
                showToast('Gagal', (res && res.message) ? res.message : 'Data materi tidak ditemukan.', 'error');
                return;
            }

            const d    = res.materi;
            const ext  = (res.ext || (d.file_materi || '').split('.').pop() || '').toLowerCase();
            const tipe = d.tipe_materi || 'PDF';

            currentViewerData = d;
            currentPdfUint8   = null;
            currentPdfDoc     = null;

            const dedicatedDownloadUrl = `${ENDPOINT}?action=download_materi&id=${d.id}`;
            const btnDlFlip = document.getElementById('btnFlipDownloadFile');
            if (btnDlFlip) btnDlFlip.href = dedicatedDownloadUrl;
            const btnDlImg = document.getElementById('btnImageDownloadLink');
            if (btnDlImg) btnDlImg.href = dedicatedDownloadUrl;

            if (tipe === 'Gambar' || ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp'].includes(ext)) {
                openImageViewerModal(d, res.has_binary ? `data:${res.mime};base64,${res.base64}` : null);
                return;
            }

            if (res.has_binary && (ext === 'pdf' || res.mime === 'application/pdf' || tipe === 'PDF')) {
                const uint8 = base64ToUint8Array(res.base64);
                currentPdfUint8 = uint8;
                await openPdfFlipbookFromUint8(d, uint8);
                return;
            }

            if (d.link_materi && (d.link_materi.includes('youtube.com') || d.link_materi.includes('youtu.be'))) {
                openVideoEmbedViewer(d);
                return;
            }

            let decodedText = '';
            if (res.has_binary && ext === 'txt' && res.base64) {
                try { decodedText = new TextDecoder('utf-8').decode(base64ToUint8Array(res.base64)); } catch(e) {}
            }
            openTextBookFlipbookViewer(d, decodedText);

        } catch(e) {
            showToast('Kesalahan', 'Gagal memuat pratinjau materi.', 'error');
            console.error(e);
        }
    };

    // Alias untuk kompatibilitas pemanggilan lama
    window.openModalFlipbookFromData = function(data) {
        if (data && data.id) {
            window.openMateriViewer(data.id);
        }
    };

    async function openPdfFlipbookFromUint8(d, uint8) {
        document.getElementById('flipTitle').textContent = d.judul_materi || 'Materi Pembelajaran';
        document.getElementById('flipMeta').textContent  = `${d.nama_kelas || 'Kelas'} • ${d.nama_mata_pelajaran || 'Mapel'} • Mode Buku Flipbook 3D`;

        document.getElementById('btnMode3d').classList.add('active');
        document.getElementById('btnModeScroll').classList.remove('active');
        document.getElementById('flipBookWrap').style.display = 'flex';
        document.getElementById('flipScrollWrap').style.display = 'none';
        document.getElementById('scrollZoomToolbar').style.display = 'none';

        const loader     = document.getElementById('flipLoader');
        const loaderText = document.getElementById('flipLoaderText');
        const loaderBar  = document.getElementById('flipLoaderProgress');
        loader.style.display = 'flex';
        loaderText.textContent = 'Menyiapkan lembaran Flipbook 3D...';
        loaderBar.style.width = '30%';

        openModal('modalFlipbook');

        const container = resetFlipBookContainer();
        if (!container) return;

        try {
            if (typeof pdfjsLib === 'undefined') {
                throw new Error('Library PDF.js sedang dimuat, silakan coba beberapa detik lagi.');
            }

            pdfjsLib.GlobalWorkerOptions.workerSrc = window.__PDF_CDN
                ? 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js'
                : '<?= BASE_URL ?>/assets/js/pdf.worker.min.js';

            let pdfStart = -1;
            for (let i = 0; i < Math.min(uint8.length - 4, 4096); i++) {
                if (uint8[i] === 0x25 && uint8[i+1] === 0x50 && uint8[i+2] === 0x44 && uint8[i+3] === 0x46) {
                    pdfStart = i;
                    break;
                }
            }

            if (pdfStart === -1) {
                openTextBookFlipbookViewer(d, '');
                return;
            }

            const cleanPdfData = pdfStart > 0 ? uint8.subarray(pdfStart) : uint8;
            loaderBar.style.width = '60%';
            loaderText.textContent = 'Menganalisis halaman modul...';

            const loadingTask = pdfjsLib.getDocument({
                data: cleanPdfData,
                cMapUrl: 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/cmaps/',
                cMapPacked: true
            });

            const pdfDoc = await loadingTask.promise;
            currentPdfDoc = pdfDoc;
            const numPages = pdfDoc.numPages;
            loaderBar.style.width = '80%';
            loaderText.textContent = `Merender ${numPages} halaman Flipbook...`;

            document.getElementById('flipTotalPages').textContent = numPages;
            document.getElementById('flipCurPage').textContent   = '1';

            const coverFront = document.createElement('div');
            coverFront.className = 'mat-flip-page mat-flip-cover';
            coverFront.innerHTML = `
                <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;opacity:0.85;">ILMUNA LEARN — MODUL AJAR</div>
                <div>
                    <iconify-icon icon="solar:book-bookmark-bold" style="font-size:56px;color:#F59E0B;margin-bottom:12px;"></iconify-icon>
                    <h2 style="font-family:'Outfit';font-size:24px;font-weight:800;margin:0 0 10px;line-height:1.3;">${escapeHtml(d.judul_materi)}</h2>
                    <p style="font-size:13px;opacity:0.9;margin:0;">${escapeHtml(d.nama_mata_pelajaran || 'Mata Pelajaran')}</p>
                </div>
                <div style="font-size:12px;opacity:0.8;display:flex;justify-content:space-between;border-top:1px solid rgba(255,255,255,0.2);padding-top:10px;">
                    <span>${escapeHtml(d.nama_kelas || 'Rombel')}</span>
                    <span>Buka Lembaran &raquo;</span>
                </div>
            `;
            container.appendChild(coverFront);

            for (let pageNum = 1; pageNum <= numPages; pageNum++) {
                const page = await pdfDoc.getPage(pageNum);
                const viewport = page.getViewport({ scale: 1.5 });

                const pageDiv = document.createElement('div');
                pageDiv.className = 'mat-flip-page';

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = viewport.width;
                canvas.height = viewport.height;

                await page.render({ canvasContext: ctx, viewport: viewport }).promise;

                const numBadge = document.createElement('div');
                numBadge.className = 'mat-flip-page-num';
                numBadge.textContent = `${pageNum} / ${numPages}`;

                pageDiv.appendChild(canvas);
                pageDiv.appendChild(numBadge);
                container.appendChild(pageDiv);
            }

            const coverBack = document.createElement('div');
            coverBack.className = 'mat-flip-page mat-flip-cover';
            coverBack.style.borderRight = 'none';
            coverBack.style.borderLeft = '4px solid #F59E0B';
            coverBack.innerHTML = `
                <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;opacity:0.85;">SELESAI PEMBAHASAN</div>
                <div>
                    <iconify-icon icon="solar:verified-check-bold" style="font-size:56px;color:#10B981;margin-bottom:12px;"></iconify-icon>
                    <h3 style="font-family:'Outfit';font-size:20px;font-weight:800;margin:0 0 8px;">Alhamdulillah</h3>
                    <p style="font-size:13px;opacity:0.9;margin:0;">Anda telah selesai membaca materi ini.</p>
                </div>
                <div style="font-size:11.5px;opacity:0.75;">Ilmuna Learn &bull; Semangat Belajar</div>
            `;
            container.appendChild(coverBack);

            initStPageFlip(container, numPages, loader, loaderBar);

        } catch(err) {
            console.error('Flipbook PDF render error:', err);
            openTextBookFlipbookViewer(d, '');
        }
    }

    function openTextBookFlipbookViewer(d, extraText = '') {
        document.getElementById('flipTitle').textContent = d.judul_materi || 'Materi Pembelajaran';
        document.getElementById('flipMeta').textContent  = `${d.nama_kelas || 'Kelas'} • ${d.nama_mata_pelajaran || 'Mapel'} • Mode Buku Flipbook 3D`;

        document.getElementById('btnMode3d').classList.add('active');
        document.getElementById('btnModeScroll').classList.remove('active');
        document.getElementById('flipBookWrap').style.display = 'flex';
        document.getElementById('flipScrollWrap').style.display = 'none';
        document.getElementById('scrollZoomToolbar').style.display = 'none';

        const loader    = document.getElementById('flipLoader');
        const loaderBar = document.getElementById('flipLoaderProgress');
        loader.style.display = 'flex';
        loaderBar.style.width = '75%';

        openModal('modalFlipbook');

        const container = resetFlipBookContainer();
        if (!container) return;

        const rawBody = [d.deskripsi_singkat || '', d.isi_materi || '', extraText || ''].filter(Boolean).join('\n\n').trim();
        const paragraphs = rawBody
            ? rawBody.split(/\n+/)
            : [`Materi "${d.judul_materi}" untuk kelas ${d.nama_kelas || 'Umum'} pada mata pelajaran ${d.nama_mata_pelajaran || ''}.`, `Berkas terlampir: ${d.file_materi || 'Dokumen Materi'} (${d.ukuran_file || 'Tersedia'}).`, `Silakan baca ringkasan pada lembaran ini. Apabila Anda membutuhkan berkas asli untuk disimpan di perangkat, klik tombol "Download Materi" di pojok kanan bawah.`];

        const pagesText = [];
        let currentChunk = '';
        paragraphs.forEach(p => {
            if ((currentChunk.length + p.length) > 650 && currentChunk.length > 0) {
                pagesText.push(currentChunk);
                currentChunk = p + '\n\n';
            } else {
                currentChunk += p + '\n\n';
            }
        });
        if (currentChunk.trim().length > 0) pagesText.push(currentChunk);
        if (pagesText.length === 0) pagesText.push('Tidak ada teks ringkasan pada materi ini.');

        const numPages = pagesText.length;
        document.getElementById('flipTotalPages').textContent = numPages;
        document.getElementById('flipCurPage').textContent   = '1';

        const coverFront = document.createElement('div');
        coverFront.className = 'mat-flip-page mat-flip-cover';
        coverFront.innerHTML = `
            <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;opacity:0.85;">ILMUNA LEARN — MODUL BACAAN</div>
            <div>
                <iconify-icon icon="solar:notebook-bold" style="font-size:56px;color:#F59E0B;margin-bottom:12px;"></iconify-icon>
                <h2 style="font-family:'Outfit';font-size:24px;font-weight:800;margin:0 0 10px;line-height:1.3;">${escapeHtml(d.judul_materi)}</h2>
                <p style="font-size:13px;opacity:0.9;margin:0;">${escapeHtml(d.nama_mata_pelajaran || 'Mata Pelajaran')}</p>
            </div>
            <div style="font-size:12px;opacity:0.8;display:flex;justify-content:space-between;border-top:1px solid rgba(255,255,255,0.2);padding-top:10px;">
                <span>${escapeHtml(d.nama_kelas || 'Rombel')}</span>
                <span>Buka Lembaran &raquo;</span>
            </div>
        `;
        container.appendChild(coverFront);

        pagesText.forEach((txt, idx) => {
            const pageDiv = document.createElement('div');
            pageDiv.className = 'mat-flip-page';
            pageDiv.innerHTML = `
                <div class="mat-flip-text-page">
                    <div style="font-size:11px;font-weight:800;color:#0D7A87;text-transform:uppercase;letter-spacing:1px;margin-bottom:12px;border-bottom:1.5px solid #E2E8F0;padding-bottom:6px;">
                        ${escapeHtml(d.judul_materi)} — Lembar ${idx + 1}
                    </div>
                    <div style="white-space:pre-line;font-size:13.5px;line-height:1.75;color:#1E293B;">${escapeHtml(txt.trim())}</div>
                </div>
                <div class="mat-flip-page-num">${idx + 1} / ${numPages}</div>
            `;
            container.appendChild(pageDiv);
        });

        const coverBack = document.createElement('div');
        coverBack.className = 'mat-flip-page mat-flip-cover';
        coverBack.style.borderRight = 'none';
        coverBack.style.borderLeft = '4px solid #F59E0B';
        coverBack.innerHTML = `
            <div style="font-size:12px;letter-spacing:1.5px;text-transform:uppercase;opacity:0.85;">SELESAI PEMBAHASAN</div>
            <div>
                <iconify-icon icon="solar:verified-check-bold" style="font-size:56px;color:#10B981;margin-bottom:12px;"></iconify-icon>
                <h3 style="font-family:'Outfit';font-size:20px;font-weight:800;margin:0 0 8px;">Alhamdulillah</h3>
                <p style="font-size:13px;opacity:0.9;margin:0;">Anda telah selesai membaca modul materi ini.</p>
            </div>
            <div style="font-size:11.5px;opacity:0.75;">Ilmuna Learn &bull; Semangat Belajar</div>
        `;
        container.appendChild(coverBack);

        initStPageFlip(container, numPages, loader, loaderBar);
    }

    function initStPageFlip(container, numPages, loader, loaderBar) {
        if (typeof St !== 'undefined' && St.PageFlip) {
            loaderBar.style.width = '100%';
            setTimeout(() => {
                const flipBox = document.getElementById('flipStage');
                const stageW  = flipBox.clientWidth || window.innerWidth || 900;
                const stageH  = flipBox.clientHeight || window.innerHeight || 650;
                const isMobile = stageW <= 640;

                const pageW = isMobile
                    ? Math.min(stageW - 24, 420)
                    : Math.min(Math.floor((stageW - 80) / 2), 520);
                const pageH = isMobile
                    ? Math.min(stageH - 40, Math.floor(pageW * 1.414))
                    : Math.min(stageH - 60, Math.floor(pageW * 1.414));

                pageFlipInstance = new St.PageFlip(container, {
                    width: Math.max(pageW, 160),
                    height: Math.max(pageH, 240),
                    size: 'stretch',
                    minWidth: isMobile ? 160 : 260,
                    maxWidth: 900,
                    minHeight: isMobile ? 240 : 380,
                    maxHeight: 1200,
                    maxShadowOpacity: 0.5,
                    showCover: true,
                    usePortrait: true,
                    mobileScrollSupport: true
                });

                pageFlipInstance.loadFromHTML(container.querySelectorAll('.mat-flip-page'));

                pageFlipInstance.on('flip', (e) => {
                    playPaperTurnSound();
                    const p = e.data + 1;
                    document.getElementById('flipCurPage').textContent = Math.min(p, numPages);
                    updateFlipBookAlignment();
                });

                loader.style.display = 'none';
                document.getElementById('flip3dZoomToolbar').style.display = 'flex';
                apply3dZoom();
                enableDualAxisDragScroll(document.getElementById('flipBookWrap'));
            }, 200);
        } else {
            loader.style.display = 'none';
        }
    }

    function openVideoEmbedViewer(d) {
        document.getElementById('flipTitle').textContent = d.judul_materi || 'Video Pembelajaran';
        document.getElementById('flipMeta').textContent  = `${d.nama_kelas || 'Kelas'} • ${d.nama_mata_pelajaran || 'Mapel'} • Mode Video`;

        document.getElementById('btnMode3d').classList.remove('active');
        document.getElementById('btnModeScroll').classList.add('active');
        document.getElementById('flipBookWrap').style.display = 'none';
        document.getElementById('flipScrollWrap').style.display = 'block';
        document.getElementById('flipLoader').style.display = 'none';
        document.getElementById('scrollZoomToolbar').style.display = 'none';
        document.getElementById('flip3dZoomToolbar').style.display = 'none';

        const iframe = document.getElementById('flipScrollIframe');
        const scrollCanvasWrap = document.getElementById('flipScrollCanvasContainer');
        scrollCanvasWrap.style.display = 'none';
        iframe.style.display = 'block';

        let vid = '';
        if (d.link_materi.includes('v=')) vid = d.link_materi.split('v=')[1].split('&')[0];
        else vid = d.link_materi.split('/').pop();
        iframe.src = `https://www.youtube.com/embed/${vid}`;

        openModal('modalFlipbook');
    }

    async function switchToScrollMode(d) {
        document.getElementById('btnMode3d').classList.remove('active');
        document.getElementById('btnModeScroll').classList.add('active');
        document.getElementById('flipBookWrap').style.display = 'none';
        document.getElementById('flipScrollWrap').style.display = 'block';
        document.getElementById('flip3dZoomToolbar').style.display = 'none';

        const iframe = document.getElementById('flipScrollIframe');
        const scrollCanvasWrap = document.getElementById('flipScrollCanvasContainer');
        const zoomToolbar = document.getElementById('scrollZoomToolbar');

        iframe.style.display = 'none';
        iframe.src = 'about:blank';
        scrollCanvasWrap.style.display = 'flex';
        scrollCanvasWrap.innerHTML = `
            <div style="color:#94A3B8;padding:40px 24px;font-size:13px;display:flex;align-items:center;gap:10px;margin:auto;">
                <iconify-icon icon="solar:refresh-bold" style="animation:spin 1s infinite linear;font-size:20px;"></iconify-icon>
                <span>Memuat lembaran vertikal...</span>
            </div>`;

        scrollZoomLevel = 1;

        if (currentPdfDoc) {
            scrollCanvasWrap.innerHTML = '';
            const numPages = currentPdfDoc.numPages;

            for (let pNum = 1; pNum <= numPages; pNum++) {
                const page = await currentPdfDoc.getPage(pNum);
                const viewport = page.getViewport({ scale: 2.0 });

                const pageBox = document.createElement('div');
                pageBox.className = 'mat-scroll-page-box';

                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                canvas.width = viewport.width;
                canvas.height = viewport.height;

                await page.render({ canvasContext: ctx, viewport: viewport }).promise;

                const badge = document.createElement('div');
                badge.className = 'mat-scroll-page-badge';
                badge.textContent = `${pNum} / ${numPages}`;

                pageBox.appendChild(canvas);
                pageBox.appendChild(badge);
                scrollCanvasWrap.appendChild(pageBox);
            }

            zoomToolbar.style.display = 'flex';
            requestAnimationFrame(() => requestAnimationFrame(() => applyScrollZoom()));
        } else {
            scrollCanvasWrap.innerHTML = '';
            const textBox = document.createElement('div');
            textBox.className = 'mat-scroll-page-box';
            textBox.style.cssText = 'background:#FFF;color:#1E293B;padding:32px;max-width:720px;';
            textBox.innerHTML = `
                <h3 style="margin:0 0 14px;color:#1D3B5E;font-family:'Outfit',sans-serif;font-size:20px;font-weight:800;">
                    ${escapeHtml(d.judul_materi)}
                </h3>
                <div style="font-size:14px;line-height:1.85;white-space:pre-line;color:#334155;">
                    ${escapeHtml(d.deskripsi_singkat || d.isi_materi || 'Silakan gunakan mode Flipbook 3D untuk membaca per halaman.')}
                </div>`;
            scrollCanvasWrap.appendChild(textBox);

            zoomToolbar.style.display = 'flex';
            requestAnimationFrame(() => requestAnimationFrame(() => applyScrollZoom()));
        }

        enableDualAxisDragScroll(scrollCanvasWrap);
    }

    window.closeFlipbookModal = function() {
        resetFlipBookContainer();
        currentPdfDoc   = null;
        currentPdfUint8 = null;
        scrollZoomLevel = 1;
        flip3dZoomLevel = 1;
        const scrollCanvasWrap = document.getElementById('flipScrollCanvasContainer');
        if (scrollCanvasWrap) scrollCanvasWrap.innerHTML = '';
        const iframe = document.getElementById('flipScrollIframe');
        if (iframe) iframe.src = 'about:blank';
        const zoomTb = document.getElementById('scrollZoomToolbar');
        if (zoomTb) zoomTb.style.display = 'none';
        const zoom3dTb = document.getElementById('flip3dZoomToolbar');
        if (zoom3dTb) zoom3dTb.style.display = 'none';
        closeModal('modalFlipbook');
    };

    document.getElementById('btnMode3d').addEventListener('click', function() {
        this.classList.add('active');
        document.getElementById('btnModeScroll').classList.remove('active');
        document.getElementById('flipScrollWrap').style.display = 'none';
        document.getElementById('flipBookWrap').style.display = 'flex';
        document.getElementById('scrollZoomToolbar').style.display = 'none';
        if (pageFlipInstance) {
            document.getElementById('flip3dZoomToolbar').style.display = 'flex';
            apply3dZoom();
        }
    });

    document.getElementById('btnModeScroll').addEventListener('click', function() {
        this.classList.add('active');
        document.getElementById('btnMode3d').classList.remove('active');
        if (currentViewerData) {
            switchToScrollMode(currentViewerData);
        }
    });

    document.getElementById('btnFlipPrev').addEventListener('click', () => {
        if (pageFlipInstance) pageFlipInstance.flipPrev();
    });
    document.getElementById('btnFlipNext').addEventListener('click', () => {
        if (pageFlipInstance) pageFlipInstance.flipNext();
    });
    document.getElementById('btnFlipPrevArrow').addEventListener('click', () => {
        if (pageFlipInstance) pageFlipInstance.flipPrev();
    });
    document.getElementById('btnFlipNextArrow').addEventListener('click', () => {
        if (pageFlipInstance) pageFlipInstance.flipNext();
    });

    // SCROLL ZOOM CONTROLS
    document.getElementById('btnScrollZoomIn').addEventListener('click', scrollZoomIn);
    document.getElementById('btnScrollZoomOut').addEventListener('click', scrollZoomOut);
    document.getElementById('btnScrollZoomReset').addEventListener('click', scrollZoomReset);

    // 3D ZOOM CONTROLS
    document.getElementById('btn3dZoomIn').addEventListener('click', zoom3dIn);
    document.getElementById('btn3dZoomOut').addEventListener('click', zoom3dOut);
    document.getElementById('btn3dZoomReset').addEventListener('click', zoom3dReset);

    // Keyboard shortcut
    window.addEventListener('keydown', function(e) {
        const modal = document.getElementById('modalFlipbook');
        if (!modal || !modal.classList.contains('show')) return;
        const is3D = document.getElementById('btnMode3d').classList.contains('active');
        const isScroll = document.getElementById('btnModeScroll').classList.contains('active');
        const scrollZoomVisible = document.getElementById('scrollZoomToolbar').style.display !== 'none';
        const zoom3dVisible = document.getElementById('flip3dZoomToolbar').style.display !== 'none';

        if ((e.ctrlKey || e.metaKey) && (e.key === '+' || e.key === '=')) {
            e.preventDefault();
            if (is3D && zoom3dVisible) zoom3dIn();
            else if (isScroll && scrollZoomVisible) scrollZoomIn();
        }
        if ((e.ctrlKey || e.metaKey) && e.key === '-') {
            e.preventDefault();
            if (is3D && zoom3dVisible) zoom3dOut();
            else if (isScroll && scrollZoomVisible) scrollZoomOut();
        }
        if ((e.ctrlKey || e.metaKey) && e.key === '0') {
            e.preventDefault();
            if (is3D && zoom3dVisible) zoom3dReset();
            else if (isScroll && scrollZoomVisible) scrollZoomReset();
        }
    });

    // Resize handler
    let scrollResizeTimer = null;
    window.addEventListener('resize', function() {
        const modal = document.getElementById('modalFlipbook');
        if (!modal || !modal.classList.contains('show')) return;
        if (document.getElementById('btnModeScroll').classList.contains('active')) {
            clearTimeout(scrollResizeTimer);
            scrollResizeTimer = setTimeout(applyScrollZoom, 180);
        } else if (document.getElementById('btnMode3d').classList.contains('active')) {
            clearTimeout(scrollResizeTimer);
            scrollResizeTimer = setTimeout(updateFlipBookAlignment, 180);
        }
    });

    document.getElementById('btnFlipSound').addEventListener('click', function() {
        flipSfxEnabled = !flipSfxEnabled;
        this.classList.toggle('active', flipSfxEnabled);
        document.getElementById('iconFlipSound').setAttribute('icon', flipSfxEnabled ? 'solar:volume-loud-bold' : 'solar:volume-cross-bold');
    });

    document.getElementById('btnFlipFullscreen').addEventListener('click', function() {
        const modalBox = document.getElementById('modalFlipbook').querySelector('.mat-flipbook-box');
        if (!document.fullscreenElement) {
            modalBox.requestFullscreen().catch(err => {
                Ilmuna.toast('error', 'Layar Penuh', `Gagal masuk layar penuh: ${err.message}`, 4000);
            });
        } else {
            document.exitFullscreen();
        }
    });

    document.getElementById('btnFlipAutoPlay').addEventListener('click', function() {
        if (flipAutoPlayTimer) {
            clearInterval(flipAutoPlayTimer);
            flipAutoPlayTimer = null;
            document.getElementById('txtAutoPlay').textContent = 'Buka Otomatis';
        } else {
            document.getElementById('txtAutoPlay').textContent = 'Jeda Otomatis';
            flipAutoPlayTimer = setInterval(() => {
                if (pageFlipInstance) {
                    const cur = pageFlipInstance.getCurrentPageIndex();
                    const tot = pageFlipInstance.getPageCount();
                    if (cur >= tot - 1) {
                        clearInterval(flipAutoPlayTimer);
                        flipAutoPlayTimer = null;
                        document.getElementById('txtAutoPlay').textContent = 'Buka Otomatis';
                    } else {
                        pageFlipInstance.flipNext();
                    }
                }
            }, 3500);
        }
    });

    // ==============================================================================
    // IMAGE VIEWER LIGHTBOX CONTROLLER (IDENTIK PORTAL GURU)
    // ==============================================================================
    function openImageViewerModal(d, dataUri = null) {
        document.getElementById('imageViewerTitle').textContent   = d.judul_materi || 'Berkas Gambar';
        document.getElementById('imageViewerMeta').textContent    = `${d.nama_kelas || 'Kelas'} • ${d.nama_mata_pelajaran || 'Mapel'} • ${d.ukuran_file || 'Gambar'}`;
        document.getElementById('imageViewerCaption').textContent = d.file_materi ? `Berkas: ${d.file_materi}` : d.judul_materi;

        const img = document.getElementById('imageViewerSrc');
        if (dataUri) {
            img.src = dataUri;
        } else if (d.link_materi) {
            img.src = d.link_materi;
        }

        imgZoomLevel  = 1;
        imgRotation   = 0;
        imgTranslateX = 0;
        imgTranslateY = 0;
        updateImgTransform();

        openModal('modalImageViewer');
    }

    function updateImgTransform() {
        const wrap = document.getElementById('imageWrap');
        if (wrap) {
            wrap.style.transform = `translate(${imgTranslateX}px, ${imgTranslateY}px) scale(${imgZoomLevel}) rotate(${imgRotation}deg)`;
        }
    }

    document.getElementById('btnImgZoomIn').addEventListener('click', () => {
        imgZoomLevel = Math.min(imgZoomLevel + 0.25, 4.0);
        updateImgTransform();
    });
    document.getElementById('btnImgZoomOut').addEventListener('click', () => {
        imgZoomLevel = Math.max(imgZoomLevel - 0.25, 0.5);
        updateImgTransform();
    });
    document.getElementById('btnImgReset').addEventListener('click', () => {
        imgZoomLevel  = 1;
        imgRotation   = 0;
        imgTranslateX = 0;
        imgTranslateY = 0;
        updateImgTransform();
    });
    document.getElementById('btnImgRotate').addEventListener('click', () => {
        imgRotation = (imgRotation + 90) % 360;
        updateImgTransform();
    });
    document.getElementById('btnImgFullscreen').addEventListener('click', () => {
        const box = document.getElementById('modalImageViewer').querySelector('.mat-image-box');
        if (!document.fullscreenElement) {
            box.requestFullscreen().catch(() => {});
        } else {
            document.exitFullscreen();
        }
    });

    const imgStage = document.getElementById('imageStage');
    imgStage.addEventListener('mousedown', (e) => {
        if (imgZoomLevel > 1) {
            isImgDragging = true;
            imgStartX = e.clientX - imgTranslateX;
            imgStartY = e.clientY - imgTranslateY;
        }
    });
    window.addEventListener('mousemove', (e) => {
        if (isImgDragging) {
            imgTranslateX = e.clientX - imgStartX;
            imgTranslateY = e.clientY - imgStartY;
            updateImgTransform();
        }
    });
    window.addEventListener('mouseup', () => {
        isImgDragging = false;
    });

    // Live Search Filter pada Kartu Materi Siswa
    const searchInput = document.getElementById('matSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const cards = document.querySelectorAll('.mat-card');
            cards.forEach(card => {
                const title = card.getAttribute('data-title') || '';
                const desc  = card.getAttribute('data-desc') || '';
                const mapel = card.getAttribute('data-mapel') || '';
                if (title.includes(query) || desc.includes(query) || mapel.includes(query)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>