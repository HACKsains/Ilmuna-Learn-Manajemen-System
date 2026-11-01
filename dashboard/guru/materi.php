<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — MANAJEMEN MATERI & MODUL AJAR (PORTAL GURU & ADMIN)
 * ==============================================================================
 */

date_default_timezone_set('Asia/Jakarta');

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

if (!function_exists('decodeMateriId')) {
    function decodeMateriId($id) {
        if (is_numeric($id)) return (int)$id;
        $decoded = base64_decode((string)$id, true);
        if ($decoded !== false && is_numeric($decoded)) return (int)$decoded;
        return (int)preg_replace('/[^0-9]/', '', (string)$id);
    }
}

requireRole(['guru', 'admin']);
$activeRole = hasRole('guru') ? 'guru' : 'admin';
$GLOBALS['activeRole'] = $activeRole;

$pdo = getDBConnection();
$pdo->exec("SET time_zone = '+07:00'");
$currentUser = getCurrentUser();
$isAdmin = hasRole('admin');
$GLOBALS['isAdmin'] = $isAdmin;

$pdo->exec("
    CREATE TABLE IF NOT EXISTS `app_settings` (
        `setting_key` VARCHAR(100) NOT NULL PRIMARY KEY,
        `setting_value` TEXT DEFAULT NULL,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

try {
    $cols = $pdo->query("SHOW COLUMNS FROM materi")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('id_kelas_mata_pelajaran', $cols)) {
        $pdo->exec("ALTER TABLE materi ADD COLUMN `id_kelas_mata_pelajaran` INT(11) UNSIGNED DEFAULT NULL AFTER `id`");
    }
    if (!in_array('id_guru', $cols)) {
        $pdo->exec("ALTER TABLE materi ADD COLUMN `id_guru` INT(11) DEFAULT NULL AFTER `id_kelas_mata_pelajaran`");
    }
    if (!in_array('gdrive_file_id', $cols)) {
        $pdo->exec("ALTER TABLE materi ADD COLUMN `gdrive_file_id` VARCHAR(150) DEFAULT NULL AFTER `link_materi`");
    }
    if (!in_array('ukuran_file', $cols)) {
        $pdo->exec("ALTER TABLE materi ADD COLUMN `ukuran_file` VARCHAR(50) DEFAULT NULL AFTER `file_materi`");
    }
    try {
        $pdo->exec("ALTER TABLE materi MODIFY COLUMN `tipe_materi` VARCHAR(50) NOT NULL DEFAULT 'Teks'");
    } catch (Exception $eTipe) {}
} catch (Exception $e) {}

$currentGuru = null;
$idGuruLogged = 0;
try {
    $gStmt = $pdo->prepare("SELECT * FROM guru WHERE id_user = ? LIMIT 1");
    $gStmt->execute([$currentUser['id']]);
    $currentGuru = $gStmt->fetch(PDO::FETCH_ASSOC);
    if ($currentGuru) {
        $idGuruLogged = (int)$currentGuru['id'];
    }
    if (!$idGuruLogged && !empty($currentUser['email'])) {
        $gStmt2 = $pdo->prepare("SELECT * FROM guru WHERE email = ? LIMIT 1");
        $gStmt2->execute([$currentUser['email']]);
        $cg2 = $gStmt2->fetch(PDO::FETCH_ASSOC);
        if ($cg2) {
            $currentGuru = $cg2;
            $idGuruLogged = (int)$cg2['id'];
        }
    }
} catch (Exception $e) {}

$isStrictGuru = ($activeRole === 'guru' || !$isAdmin);

function isKmpDiampuGuru($pdo, $idKmp, $idGuruLogged, $isBypass = false) {
    if ($isBypass) return true;
    if ($idKmp <= 0 || $idGuruLogged <= 0) return false;
    $stmt = $pdo->prepare("SELECT 1 FROM kelas_mapel WHERE id_kelas_mata_pelajaran = ? AND id_guru = ? LIMIT 1");
    $stmt->execute([$idKmp, $idGuruLogged]);
    return (bool)$stmt->fetchColumn();
}

function isMateriDiampuGuru($pdo, $idMateri, $idGuruLogged, $isBypass = false) {
    if ($isBypass) return true;
    if (!empty($GLOBALS['isAdmin'])) return true;
    if ($idMateri <= 0) return false;
    if ($idGuruLogged <= 0) return false;
    $stmt = $pdo->prepare("
        SELECT 1 
        FROM materi m
        LEFT JOIN kelas_mapel km ON m.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
        WHERE m.id = :id AND (
            m.id_guru = :g1 
            OR km.id_guru = :g2 
            OR km.id_kelas IN (SELECT id_kelas FROM kelas_mapel WHERE id_guru = :g3)
        )
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $idMateri,
        ':g1' => $idGuruLogged,
        ':g2' => $idGuruLogged,
        ':g3' => $idGuruLogged,
    ]);
    return (bool)$stmt->fetchColumn();
}

function getGasMateriUrl($pdo) {
    if (defined('GAS_MATERI_URL') && !empty(GAS_MATERI_URL)) {
        return trim(GAS_MATERI_URL);
    }
    $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'gas_materi_url' LIMIT 1");
    $stmt->execute();
    return trim((string)$stmt->fetchColumn());
}

function getGasFolderId($pdo) {
    if (defined('GAS_FOLDER_ID') && !empty(GAS_FOLDER_ID)) {
        return trim(GAS_FOLDER_ID);
    }
    $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'gas_folder_id' LIMIT 1");
    $stmt->execute();
    return trim((string)$stmt->fetchColumn());
}

function formatFileSize($bytes) {
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int)floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 2) . ' ' . ($units[$i] ?? 'MB');
}

function detectMediaType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (in_array($ext, ['pdf'])) return 'PDF';
    if (in_array($ext, ['ppt', 'pptx'])) return 'Slide';
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'])) return 'Gambar';
    if (in_array($ext, ['doc', 'docx', 'txt', 'rtf', 'odt'])) return 'Teks';
    if (in_array($ext, ['mp4', 'mkv', 'avi', 'mov', 'webm'])) return 'Video';
    return 'Link Eksternal';
}

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

if (!function_exists('materi_xlsx_col_letter')) {
    function materi_xlsx_col_letter($idx) {
        $letter = ''; $idx = (int)$idx + 1;
        while ($idx > 0) {
            $mod = ($idx - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $idx = intdiv($idx - $mod, 26);
        }
        return $letter;
    }
}
if (!function_exists('materi_xlsx_col_index')) {
    function materi_xlsx_col_index($letter) {
        $letter = strtoupper($letter);
        $num = 0;
        for ($i = 0; $i < strlen($letter); $i++) {
            $num = $num * 26 + (ord($letter[$i]) - 64);
        }
        return $num - 1;
    }
}
if (!function_exists('materi_xlsx_write')) {
    function materi_xlsx_write($headers, $rows) {
        if (!class_exists('ZipArchive')) return false;
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_materi_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return false;

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            .'</Relationships>');

        $allRows = array_merge([$headers], $rows);
        $strings = []; $map = []; $sheetRows = '';
        foreach ($allRows as $rIdx => $row) {
            $rowNum = $rIdx + 1; $cells = '';
            foreach ($row as $cIdx => $val) {
                $val = (string)$val;
                $ref = materi_xlsx_col_letter($cIdx) . $rowNum;
                if (ctype_digit($val) && strlen($val) > 0 && $val[0] !== '0') {
                    $cells .= '<c r="'.$ref.'"><v>'.$val.'</v></c>';
                } else {
                    if (!isset($map[$val])) { $map[$val] = count($strings); $strings[] = $val; }
                    $cells .= '<c r="'.$ref.'" t="s"><v>'.$map[$val].'</v></c>';
                }
            }
            $sheetRows .= '<row r="'.$rowNum.'">'.$cells.'</row>';
        }
        $ssXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
        foreach ($strings as $s) {
            $ssXml .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1|ENT_QUOTES, 'UTF-8').'</t></si>';
        }
        $ssXml .= '</sst>';
        $zip->addFromString('xl/sharedStrings.xml', $ssXml);
        $zip->addFromString('xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();
        return $tmp;
    }
}
if (!function_exists('materi_xlsx_read')) {
    function materi_xlsx_read($file) {
        if (!class_exists('ZipArchive')) return false;
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) return false;
        $strings = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml) {
            $xml = @simplexml_load_string($ssXml);
            if ($xml) {
                foreach ($xml->si as $si) {
                    if (isset($si->t)) $strings[] = (string)$si->t;
                    elseif (isset($si->r)) {
                        $txt = '';
                        foreach ($si->r as $r) $txt .= (string)$r->t;
                        $strings[] = $txt;
                    } else $strings[] = '';
                }
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if (!$sheetXml) return false;
        $xml = @simplexml_load_string($sheetXml);
        if (!$xml) return false;
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowData = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                $colL = preg_replace('/[0-9]/', '', $ref);
                $colI = materi_xlsx_col_index($colL);
                $type = (string)$c['t']; $v = (string)$c->v;
                if ($type === 's') $v = $strings[(int)$v] ?? '';
                elseif ($type === 'inlineStr') $v = (string)$c->is->t;
                $rowData[$colI] = $v;
            }
            if ($rowData) {
                $maxIdx = max(array_keys($rowData));
                $full = [];
                for ($i = 0; $i <= $maxIdx; $i++) $full[] = $rowData[$i] ?? '';
                $rows[] = $full;
            } else $rows[] = [];
        }
        return $rows;
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'download_template') {
    while (ob_get_level()) { ob_end_clean(); }
    $headers = ['Judul Materi', 'Link Materi / Google Drive', 'Deskripsi Singkat', 'Tipe Media', 'Durasi Menit'];
    $examples = [
        [
            'Memahami Makna 7 Asmaul Husna',
            'https://drive.google.com/file/d/xxxxx/view',
            'Materi bab 1 mengenai dalil dan penerapan sifat-sifat Allah dalam kehidupan sehari-hari.',
            'PDF',
            '20'
        ],
        [
            'Hukum Nun Sukun dan Tanwin',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'Video penjelasan tajwid idzhar, idgham, iqlab, dan ikhfa.',
            'Video',
            '15'
        ]
    ];
    $tmpFile = materi_xlsx_write($headers, $examples);
    if (!$tmpFile) {
        die('Gagal membuat template Excel.');
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Template_Import_Materi.xlsx"');
    header('Content-Length: ' . filesize($tmpFile));
    readfile($tmpFile);
    @unlink($tmpFile);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'download_materi') {
    while (ob_get_level()) { ob_end_clean(); }
    $id = decodeMateriId($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM materi WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$m) {
        http_response_code(404);
        die('Materi tidak ditemukan.');
    }

    if ($isStrictGuru && !isMateriDiampuGuru($pdo, $id, $idGuruLogged)) {
        http_response_code(403);
        die('Akses ditolak: Anda tidak mengampu kelas/mapel materi ini.');
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

if (isset($_GET['action']) && in_array($_GET['action'], ['pdf_stream', 'media_stream', 'stream_file'], true)) {
    while (ob_get_level()) { ob_end_clean(); }
    $id = decodeMateriId($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM materi WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$m) {
        http_response_code(404);
        die('Materi tidak ditemukan');
    }

    if ($isStrictGuru && !isMateriDiampuGuru($pdo, $id, $idGuruLogged)) {
        http_response_code(403);
        die('Akses ditolak');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];

    try {
        if ($action === 'get_flipbook_base64') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID materi tidak valid.']);
                exit;
            }
            if ($isStrictGuru && !isMateriDiampuGuru($pdo, $id, $idGuruLogged)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda tidak mengampu materi ini.']);
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

            $resolved = resolveMateriBinary($m);
            if ($resolved['success'] && !empty($resolved['binary'])) {
                echo json_encode([
                    'success'      => true,
                    'has_binary'   => true,
                    'ext'          => $resolved['ext'],
                    'mime'         => $resolved['content_type'],
                    'filename'     => $resolved['filename'],
                    'base64'       => base64_encode($resolved['binary']),
                    'materi'       => $m
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

        if ($action === 'list_kelas') {
            if (!$isStrictGuru) {
                $stmt = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran FROM kelas ORDER BY nama_kelas ASC");
            } else {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT k.id, k.nama_kelas, k.tahun_pelajaran 
                    FROM kelas_mapel km
                    JOIN kelas k ON km.id_kelas = k.id
                    WHERE km.id_guru = ?
                    ORDER BY k.nama_kelas ASC
                ");
                $stmt->execute([$idGuruLogged]);
            }
            $kelas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $kelas]);
            exit;
        }

        if ($action === 'list_mapel') {
            $idKelas = (int)($_POST['id_kelas'] ?? 0);
            if ($idKelas <= 0) {
                echo json_encode(['success' => true, 'data' => []]);
                exit;
            }

            if (!$isStrictGuru) {
                $stmt = $pdo->prepare("
                    SELECT km.id_kelas_mata_pelajaran AS id, km.nama_mata_pelajaran, km.semester, km.nama_guru
                    FROM kelas_mapel km
                    WHERE km.id_kelas = ?
                    ORDER BY km.nama_mata_pelajaran ASC
                ");
                $stmt->execute([$idKelas]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT km.id_kelas_mata_pelajaran AS id, km.nama_mata_pelajaran, km.semester, km.nama_guru
                    FROM kelas_mapel km
                    WHERE km.id_kelas = ? AND km.id_guru = ?
                    ORDER BY km.nama_mata_pelajaran ASC
                ");
                $stmt->execute([$idKelas, $idGuruLogged]);
            }
            $mapel = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $mapel]);
            exit;
        }

        if ($action === 'list_materi') {
            $idKmp  = (int)($_POST['id_kelas_mata_pelajaran'] ?? 0);
            $type   = trim($_POST['media_type'] ?? 'all');
            $search = trim($_POST['search'] ?? '');
            $page   = max(1, (int)($_POST['page'] ?? 1));
            $per    = max(5, min(100, (int)($_POST['per'] ?? 12)));
            $offset = ($page - 1) * $per;

            $where = "WHERE 1=1";
            $params = [];

            if ($idKmp > 0) {
                $where .= " AND m.id_kelas_mata_pelajaran = :id_kmp";
                $params[':id_kmp'] = $idKmp;
                if ($isStrictGuru) {
                    $where .= " AND m.id_kelas_mata_pelajaran IN (SELECT id_kelas_mata_pelajaran FROM kelas_mapel WHERE id_guru = :id_guru_kmp)";
                    $params[':id_guru_kmp'] = $idGuruLogged;
                }
            } elseif ($isStrictGuru && $idGuruLogged > 0) {
                $where .= " AND m.id_kelas_mata_pelajaran IN (SELECT id_kelas_mata_pelajaran FROM kelas_mapel WHERE id_guru = :id_guru2)";
                $params[':id_guru2'] = $idGuruLogged;
            }

            if ($type !== 'all' && in_array($type, ['PDF', 'Slide', 'Gambar', 'Teks', 'Video', 'Link Eksternal'], true)) {
                $where .= " AND m.tipe_materi = :tipe";
                $params[':tipe'] = $type;
            }

            if ($search !== '') {
                $where .= " AND (m.judul_materi LIKE :q OR m.deskripsi_singkat LIKE :q OR m.link_materi LIKE :q OR m.file_materi LIKE :q)";
                $params[':q'] = '%' . $search . '%';
            }

            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM materi m {$where}");
            foreach ($params as $k => $v) $stmtCount->bindValue($k, $v);
            $stmtCount->execute();
            $totalRows = (int)$stmtCount->fetchColumn();

            $sql = "
                SELECT m.*, 
                       km.nama_kelas, km.nama_mata_pelajaran, km.semester, km.tahun_pelajaran_kelas,
                       g.nama_guru
                FROM materi m
                LEFT JOIN kelas_mapel km ON m.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
                LEFT JOIN guru g ON m.id_guru = g.id
                {$where}
                ORDER BY m.created_at DESC, m.id DESC
                LIMIT :lim OFFSET :off
            ";
            $stmt = $pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':lim', $per, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stats = ['all'=>0,'pdf'=>0,'slide'=>0,'gambar'=>0,'doc'=>0,'video'=>0,'gdrive'=>0];
            $baseWhereStat = ($idKmp > 0) ? "WHERE id_kelas_mata_pelajaran = " . (int)$idKmp : "WHERE 1=1";
            if ($isStrictGuru && $idGuruLogged > 0) {
                $baseWhereStat .= " AND id_kelas_mata_pelajaran IN (SELECT id_kelas_mata_pelajaran FROM kelas_mapel WHERE id_guru = {$idGuruLogged})";
            }
            $statQuery = $pdo->query("
                SELECT 
                    COUNT(*) as total_all,
                    SUM(CASE WHEN tipe_materi = 'PDF' THEN 1 ELSE 0 END) as total_pdf,
                    SUM(CASE WHEN tipe_materi = 'Slide' THEN 1 ELSE 0 END) as total_slide,
                    SUM(CASE WHEN tipe_materi = 'Gambar' THEN 1 ELSE 0 END) as total_gambar,
                    SUM(CASE WHEN tipe_materi = 'Teks' THEN 1 ELSE 0 END) as total_doc,
                    SUM(CASE WHEN tipe_materi = 'Video' THEN 1 ELSE 0 END) as total_video,
                    SUM(CASE WHEN link_materi LIKE '%drive.google.com%' OR gdrive_file_id IS NOT NULL THEN 1 ELSE 0 END) as total_gdrive
                FROM materi
                {$baseWhereStat}
            ")->fetch(PDO::FETCH_ASSOC);

            if ($statQuery) {
                $stats['all']    = (int)($statQuery['total_all'] ?? 0);
                $stats['pdf']    = (int)($statQuery['total_pdf'] ?? 0);
                $stats['slide']  = (int)($statQuery['total_slide'] ?? 0);
                $stats['gambar'] = (int)($statQuery['total_gambar'] ?? 0);
                $stats['doc']    = (int)($statQuery['total_doc'] ?? 0);
                $stats['video']  = (int)($statQuery['total_video'] ?? 0);
                $stats['gdrive'] = (int)($statQuery['total_gdrive'] ?? 0);
            }

            echo json_encode([
                'success' => true,
                'data'    => $rows,
                'stats'   => $stats,
                'total'   => $totalRows,
                'page'    => $page,
                'pages'   => max(1, (int)ceil($totalRows / $per))
            ]);
            exit;
        }

        if ($action === 'get_materi') {
            $id = (int)($_POST['id'] ?? 0);
            if ($isStrictGuru && !isMateriDiampuGuru($pdo, $id, $idGuruLogged)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat melihat atau mengedit materi pada kelas dan mapel yang Anda ampu.']);
                exit;
            }
            $stmt = $pdo->prepare("
                SELECT m.*, km.id_kelas, km.nama_kelas, km.nama_mata_pelajaran
                FROM materi m
                LEFT JOIN kelas_mapel km ON m.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
                WHERE m.id = ? LIMIT 1
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'message' => 'Data materi tidak ditemukan.']);
                exit;
            }
            echo json_encode(['success' => true, 'data' => $row]);
            exit;
        }

        if ($action === 'save_materi') {
            $id         = (int)($_POST['id'] ?? 0);
            $idKmp      = (int)($_POST['id_kelas_mata_pelajaran'] ?? 0);
            $judul      = trim($_POST['judul_materi'] ?? '');
            $tipeMateri = trim($_POST['tipe_materi'] ?? 'PDF');
            $linkInput  = trim($_POST['link_materi'] ?? '');
            $deskripsi  = trim($_POST['deskripsi_singkat'] ?? '');
            $isiMateri  = trim($_POST['isi_materi'] ?? '');
            $durasi     = max(1, (int)($_POST['durasi_menit'] ?? 15));

            if ($idKmp <= 0) {
                echo json_encode(['success' => false, 'message' => 'Rombel Kelas & Mata Pelajaran wajib dipilih.']);
                exit;
            }

            if ($isStrictGuru && !isKmpDiampuGuru($pdo, $idKmp, $idGuruLogged)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat menambahkan materi pada kelas dan mata pelajaran yang Anda ampu saja.']);
                exit;
            }

            if ($id > 0 && $isStrictGuru && !isMateriDiampuGuru($pdo, $id, $idGuruLogged)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda tidak memiliki hak akses untuk mengubah materi ini.']);
                exit;
            }

            if ($judul === '') {
                echo json_encode(['success' => false, 'message' => 'Judul materi wajib diisi.']);
                exit;
            }

            $stmtKm = $pdo->prepare("
                SELECT km.id_kelas, km.nama_kelas, km.id_mata_pelajaran, km.nama_mata_pelajaran 
                FROM kelas_mapel km 
                WHERE km.id_kelas_mata_pelajaran = ? LIMIT 1
            ");
            $stmtKm->execute([$idKmp]);
            $kmInfo = $stmtKm->fetch(PDO::FETCH_ASSOC);
            $namaKelas = $kmInfo['nama_kelas'] ?? 'Kelas Umum';
            $namaMapel = $kmInfo['nama_mata_pelajaran'] ?? 'Mata Pelajaran';

            $baseSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $judul), '-'));
            if ($baseSlug === '') $baseSlug = 'materi-' . time();
            $slug = $baseSlug;
            $cnt = 1;
            while (true) {
                $stmtSlug = $pdo->prepare("SELECT id FROM materi WHERE slug = ? AND id != ? LIMIT 1");
                $stmtSlug->execute([$slug, $id]);
                if (!$stmtSlug->fetchColumn()) break;
                $slug = $baseSlug . '-' . (++$cnt);
            }

            $finalLinkMateri = $linkInput;
            $finalFileMateri = null;
            $finalGdriveId   = null;
            $finalUkuranFile = null;
            $uploadNotice    = '';

            if (isset($_FILES['file_materi']) && $_FILES['file_materi']['error'] === UPLOAD_ERR_OK) {
                $tmpPath      = $_FILES['file_materi']['tmp_name'];
                $originalName = $_FILES['file_materi']['name'];
                $fileSize     = (int)$_FILES['file_materi']['size'];

                $maxBytes = 1 * 1024 * 1024;
                if ($fileSize > $maxBytes) {
                    $sizeMb = round($fileSize / (1024 * 1024), 2);
                    echo json_encode(['success' => false, 'message' => "Ukuran berkas materi ({$sizeMb} MB) melebihi batas maksimal 1 MB untuk penyimpanan server lokal."]);
                    exit;
                }

                $finalUkuranFile = formatFileSize($fileSize);
                $finalFileMateri = $originalName;
                $tipeMateri      = detectMediaType($originalName);

                $subDir = 'uploads/materi/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $namaKelas) . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $namaMapel);
                $targetDir = BASE_PATH . '/' . $subDir;
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0777, true);
                }
                $safeFileName = time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $originalName);
                $targetFilePath = $targetDir . '/' . $safeFileName;

                if (move_uploaded_file($tmpPath, $targetFilePath)) {
                    $finalLinkMateri = BASE_URL . '/' . $subDir . '/' . $safeFileName;
                    $finalFileMateri = $originalName;
                    $finalGdriveId   = null;
                    $uploadNotice    = 'Berkas materi berhasil disimpan di server lokal (Maks 1 MB).';
                } else {
                    echo json_encode(['success' => false, 'message' => 'Gagal memindahkan berkas materi ke penyimpanan server lokal.']);
                    exit;
                }
            } else {
                if ($id > 0) {
                    $stmtOld = $pdo->prepare("SELECT file_materi, link_materi, gdrive_file_id, ukuran_file FROM materi WHERE id = ?");
                    $stmtOld->execute([$id]);
                    $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
                    if ($old) {
                        if (empty($finalLinkMateri)) $finalLinkMateri = $old['link_materi'];
                        $finalFileMateri = $old['file_materi'];
                        $finalGdriveId   = $old['gdrive_file_id'];
                        $finalUkuranFile = $old['ukuran_file'];
                    }
                }
            }

            if (empty($finalLinkMateri) && empty($isiMateri) && empty($deskripsi)) {
                echo json_encode(['success' => false, 'message' => 'Harap unggah berkas materi, masukkan tautan link, atau tuliskan deskripsi/isi materi.']);
                exit;
            }

            if (!in_array($tipeMateri, ['Teks', 'Video', 'PDF', 'Slide', 'Gambar', 'Link Eksternal'], true)) {
                $tipeMateri = 'PDF';
            }

            if ($id > 0) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE materi SET
                        id_kelas_mata_pelajaran = :id_kmp,
                        id_guru                 = :id_guru,
                        judul_materi            = :judul,
                        slug                    = :slug,
                        deskripsi_singkat       = :deskripsi,
                        isi_materi              = :isi,
                        tipe_materi             = :tipe,
                        link_materi             = :link,
                        gdrive_file_id          = :gdrive_id,
                        file_materi             = :file,
                        ukuran_file             = :ukuran,
                        durasi_menit            = :durasi,
                        updated_at              = NOW()
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    ':id_kmp'    => $idKmp,
                    ':id_guru'   => $idGuruLogged ?: $currentUser['id'],
                    ':judul'     => $judul,
                    ':slug'      => $slug,
                    ':deskripsi' => $deskripsi,
                    ':isi'       => $isiMateri,
                    ':tipe'      => $tipeMateri,
                    ':link'      => $finalLinkMateri,
                    ':gdrive_id' => $finalGdriveId,
                    ':file'      => $finalFileMateri,
                    ':ukuran'    => $finalUkuranFile,
                    ':durasi'    => $durasi,
                    ':id'        => $id
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Materi berhasil diperbarui! ' . $uploadNotice,
                    'id'      => $id
                ]);
                exit;
            } else {
                $stmtInsert = $pdo->prepare("
                    INSERT INTO materi (
                        id_kelas_mata_pelajaran, id_guru, judul_materi, slug,
                        deskripsi_singkat, isi_materi, tipe_materi, link_materi,
                        gdrive_file_id, file_materi, ukuran_file, durasi_menit, created_at, updated_at
                    ) VALUES (
                        :id_kmp, :id_guru, :judul, :slug,
                        :deskripsi, :isi, :tipe, :link,
                        :gdrive_id, :file, :ukuran, :durasi, NOW(), NOW()
                    )
                ");
                $stmtInsert->execute([
                    ':id_kmp'    => $idKmp,
                    ':id_guru'   => $idGuruLogged ?: $currentUser['id'],
                    ':judul'     => $judul,
                    ':slug'      => $slug,
                    ':deskripsi' => $deskripsi,
                    ':isi'       => $isiMateri,
                    ':tipe'      => $tipeMateri,
                    ':link'      => $finalLinkMateri,
                    ':gdrive_id' => $finalGdriveId,
                    ':file'      => $finalFileMateri,
                    ':ukuran'    => $finalUkuranFile,
                    ':durasi'    => $durasi
                ]);

                $newId = (int)$pdo->lastInsertId();
                echo json_encode([
                    'success' => true,
                    'message' => 'Alhamdulillah! Materi baru berhasil ditambahkan! ' . $uploadNotice,
                    'id'      => $newId
                ]);
                exit;
            }
        }

        if ($action === 'delete_materi') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID materi tidak valid.']);
                exit;
            }
            if ($isStrictGuru && !isMateriDiampuGuru($pdo, $id, $idGuruLogged)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat menghapus materi pada kelas dan mata pelajaran yang Anda ampu saja.']);
                exit;
            }
            $stmtDel = $pdo->prepare("DELETE FROM materi WHERE id = ?");
            $stmtDel->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Materi berhasil dihapus dari sistem.']);
            exit;
        }

        if ($action === 'bulk_delete_materi') {
            $ids = $_POST['ids'] ?? [];
            if (!is_array($ids) || empty($ids)) {
                echo json_encode(['success' => false, 'message' => 'Tidak ada materi yang dipilih.']);
                exit;
            }
            $cleanIds = array_values(array_filter(array_map('intval', $ids)));
            if (empty($cleanIds)) {
                echo json_encode(['success' => false, 'message' => 'Daftar ID tidak valid.']);
                exit;
            }

            if ($isStrictGuru) {
                $placeholdersTmp = implode(',', array_fill(0, count($cleanIds), '?'));
                $stmtAllowed = $pdo->prepare("
                    SELECT m.id 
                    FROM materi m
                    JOIN kelas_mapel km ON m.id_kelas_mata_pelajaran = km.id_kelas_mata_pelajaran
                    WHERE m.id IN ({$placeholdersTmp}) AND km.id_guru = ?
                ");
                $stmtAllowed->execute(array_merge($cleanIds, [$idGuruLogged]));
                $cleanIds = $stmtAllowed->fetchAll(PDO::FETCH_COLUMN);
                if (empty($cleanIds)) {
                    echo json_encode(['success' => false, 'message' => 'Akses ditolak: Tidak ada materi yang dapat dihapus sesuai hak ampu Anda.']);
                    exit;
                }
            }

            $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
            $stmtBulk = $pdo->prepare("DELETE FROM materi WHERE id IN ({$placeholders})");
            $stmtBulk->execute($cleanIds);
            echo json_encode(['success' => true, 'message' => count($cleanIds) . ' materi berhasil dihapus.']);
            exit;
        }

        if ($action === 'save_gas_setting') {
            $gasUrl = trim($_POST['gas_url'] ?? '');
            $gasFolder = trim($_POST['gas_folder_id'] ?? '');

            $stmtSave = $pdo->prepare("
                INSERT INTO app_settings (setting_key, setting_value, updated_at) 
                VALUES ('gas_materi_url', :val, NOW())
                ON DUPLICATE KEY UPDATE setting_value = :val2, updated_at = NOW()
            ");
            $stmtSave->execute([':val' => $gasUrl, ':val2' => $gasUrl]);

            $stmtFolder = $pdo->prepare("
                INSERT INTO app_settings (setting_key, setting_value, updated_at) 
                VALUES ('gas_folder_id', :val, NOW())
                ON DUPLICATE KEY UPDATE setting_value = :val2, updated_at = NOW()
            ");
            $stmtFolder->execute([':val' => $gasFolder, ':val2' => $gasFolder]);

            echo json_encode(['success' => true, 'message' => 'Konfigurasi Google Drive & Apps Script berhasil disimpan!']);
            exit;
        }

        if ($action === 'import_excel') {
            $idKmp = (int)($_POST['id_kelas_mata_pelajaran'] ?? 0);
            if ($idKmp <= 0) {
                echo json_encode(['success' => false, 'message' => 'Silakan pilih Rombel Kelas & Mapel terlebih dahulu.']);
                exit;
            }
            if ($isStrictGuru && !isKmpDiampuGuru($pdo, $idKmp, $idGuruLogged)) {
                echo json_encode(['success' => false, 'message' => 'Akses ditolak: Anda hanya dapat mengimpor materi pada kelas dan mata pelajaran yang Anda ampu saja.']);
                exit;
            }
            if (empty($_FILES['file_excel']['tmp_name'])) {
                echo json_encode(['success' => false, 'message' => 'File Excel belum dipilih.']);
                exit;
            }
            if ((int)$_FILES['file_excel']['size'] > 1 * 1024 * 1024) {
                echo json_encode(['success' => false, 'message' => 'Ukuran berkas Excel melebihi batas maksimal 1 MB.']);
                exit;
            }

            $fileTmp = $_FILES['file_excel']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['file_excel']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') {
                echo json_encode(['success' => false, 'message' => 'Hanya format .xlsx yang didukung.']);
                exit;
            }

            $rows = materi_xlsx_read($fileTmp);
            if (!$rows || count($rows) < 2) {
                echo json_encode(['success' => false, 'message' => 'File Excel kosong atau format tidak sesuai.']);
                exit;
            }

            $imported = 0;
            $skipped = 0;
            foreach (array_slice($rows, 1) as $r) {
                $judul  = trim((string)($r[0] ?? ''));
                $link   = trim((string)($r[1] ?? ''));
                $desc   = trim((string)($r[2] ?? ''));
                $tipe   = trim((string)($r[3] ?? 'PDF'));
                $durasi = max(5, (int)($r[4] ?? 15));

                if ($judul === '') continue;

                $stmtCek = $pdo->prepare("SELECT id FROM materi WHERE id_kelas_mata_pelajaran = ? AND judul_materi = ? LIMIT 1");
                $stmtCek->execute([$idKmp, $judul]);
                if ($stmtCek->fetchColumn()) {
                    $skipped++;
                    continue;
                }

                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $judul), '-')) . '-' . time() . '-' . rand(10, 99);
                if (!in_array($tipe, ['Teks', 'Video', 'PDF', 'Slide', 'Link Eksternal'], true)) {
                    $tipe = 'PDF';
                }

                $stmtIns = $pdo->prepare("
                    INSERT INTO materi (
                        id_kelas_mata_pelajaran, id_guru, judul_materi, slug,
                        deskripsi_singkat, tipe_materi, link_materi, durasi_menit, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmtIns->execute([$idKmp, $idGuruLogged ?: $currentUser['id'], $judul, $slug, $desc, $tipe, $link, $durasi]);
                $imported++;
            }

            echo json_encode([
                'success' => true,
                'message' => "Import selesai! {$imported} materi berhasil ditambahkan, {$skipped} dilewati karena sudah ada."
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Aksi tidak dikenal.']);
        exit;

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan server: ' . $e->getMessage()]);
        exit;
    }
}

$pageTitle = 'Materi & Modul Pembelajaran';
$activeGasUrl = getGasMateriUrl($pdo);

require_once BASE_PATH . '/includes/header.php';
?>

<script src="<?= BASE_URL ?>/assets/js/pdf.min.js" onerror="window.__PDF_CDN=true;this.onerror=null;this.src='https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';"></script>
<script src="<?= BASE_URL ?>/assets/js/page-flip.browser.js" onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.js';"></script>

<style>
:root {
    --lumina-navy: #1D3B5E;
    --lumina-teal: #0D7A87;
    --lumina-sky: #52B2DC;
    --lumina-emerald: #10B981;
    --lumina-gold: #F59E0B;
    --lumina-rose: #F43F5E;
    --lumina-border: #E2E8F0;
    --lumina-bg-card: #FFFFFF;
}

.mat-container { padding: 6px 0 40px; font-family: 'Plus Jakarta Sans', sans-serif; color: #334155; }

.mat-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; }
.mat-header__title { display: flex; align-items: center; gap: 12px; }
.mat-header__icon { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #0D7A87 0%, #1D3B5E 100%); color: #FFFFFF; display: flex; align-items: center; justify-content: center; font-size: 24px; box-shadow: 0 8px 20px -6px rgba(13, 122, 135, 0.4); }
.mat-header h2 { font-family: 'Outfit', sans-serif; font-size: 23px; font-weight: 800; color: #1D3B5E; margin: 0; line-height: 1.2; }
.mat-header p { font-size: 13px; color: #64748B; margin: 3px 0 0; }
.mat-header__actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

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

.mat-toolbar-card { background: #FFFFFF; border: 1.5px solid var(--lumina-border); border-radius: 16px; padding: 18px 20px; margin-bottom: 22px; box-shadow: 0 4px 16px -6px rgba(29, 59, 94, 0.05); }
.mat-selectors-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 16px; }
.mat-select-group { display: flex; flex-direction: column; gap: 6px; }
.mat-select-group label { font-size: 12px; font-weight: 700; color: #1D3B5E; display: flex; align-items: center; gap: 6px; }
.mat-select { width: 100%; padding: 10px 14px; border-radius: 10px; border: 1.5px solid #CBD5E1; background: #FFFFFF; font-family: inherit; font-size: 13px; font-weight: 600; color: #1E293B; outline: none; transition: all 0.2s; }
.mat-select:focus { border-color: #0D7A87; box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12); }
.mat-select:disabled { background: #F8FAFC; color: #94A3B8; cursor: not-allowed; }

.mat-filter-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; padding-top: 14px; border-top: 1px solid #F1F5F9; }
.mat-search-box { position: relative; flex: 1; min-width: 260px; }
.mat-search-box iconify-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); font-size: 17px; color: #94A3B8; pointer-events: none; }
.mat-search-input { width: 100%; padding: 9px 14px 9px 40px; border-radius: 10px; border: 1.5px solid #E2E8F0; font-family: inherit; font-size: 13px; outline: none; transition: all 0.2s; }
.mat-search-input:focus { border-color: #0D7A87; box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.1); }

.mat-tabs { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.mat-tab-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 8px; border: 1px solid #E2E8F0; background: #F8FAFC; font-family: inherit; font-size: 12px; font-weight: 700; color: #64748B; cursor: pointer; transition: all 0.2s; }
.mat-tab-btn:hover { background: #FFFFFF; color: #0D7A87; border-color: #CBD5E1; }
.mat-tab-btn.active { background: #0D7A87; color: #FFFFFF; border-color: #0D7A87; box-shadow: 0 3px 10px rgba(13, 122, 135, 0.25); }
.mat-tab-badge { padding: 2px 6px; border-radius: 999px; background: rgba(0,0,0,0.08); font-size: 11px; }
.mat-tab-btn.active .mat-tab-badge { background: rgba(255,255,255,0.22); color: #FFFFFF; }

.ilmuna-crud-tablewrap { background: #ffffff; border: 1px solid rgba(226, 232, 240, 0.9); border-radius: 18px; overflow-x: auto; box-shadow: 0 4px 18px rgba(29, 59, 94, 0.03); margin-bottom: 24px; }
.ilmuna-crud-table { width: 100%; border-collapse: collapse; text-align: left; }
.ilmuna-crud-table thead th { background: linear-gradient(180deg, #F8FAFC 0%, #F1F5F9 100%); padding: 14px 18px; font-size: 12px; font-weight: 800; color: #1D3B5E; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1.5px solid #E2E8F0; white-space: nowrap; }
.ilmuna-crud-table tbody tr { border-bottom: 1px solid #F1F5F9; transition: background 0.15s ease; }
.ilmuna-crud-table tbody tr:last-child { border-bottom: none; }
.ilmuna-crud-table tbody tr:hover { background: rgba(13, 122, 135, 0.04); }
.ilmuna-crud-table td { padding: 14px 18px; font-size: 13px; color: #1E293B; vertical-align: middle; }
.ilmuna-table-loading { text-align: center; padding: 48px 20px; color: #64748B; }
.ilmuna-table-loading iconify-icon { color: #0D7A87; margin-bottom: 8px; }
.ilmuna-table-loading span { display: block; font-size: 13px; font-weight: 600; }

.mat-row-num { font-family: monospace; font-weight: 700; color: #94A3B8; text-align: center; }
.mat-title-box { display: flex; align-items: center; gap: 12px; }
.mat-ext-badge { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.mat-ext-badge.pdf    { background: #FEE2E2; color: #DC2626; }
.mat-ext-badge.slide  { background: #FEF3C7; color: #D97706; }
.mat-ext-badge.gambar { background: #D1FAE5; color: #059669; }
.mat-ext-badge.doc    { background: #E0E7FF; color: #4F46E5; }
.mat-ext-badge.video  { background: #FCE7F3; color: #DB2777; }
.mat-ext-badge.link   { background: #E0F2FE; color: #0284C7; }

.mat-title-text { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.mat-title-main { font-family: 'Outfit', sans-serif; font-size: 14.5px; font-weight: 700; color: #1D3B5E; line-height: 1.35; cursor: pointer; transition: color 0.15s; }
.mat-title-main:hover { color: #0D7A87; }
.mat-title-sub { font-size: 11.5px; color: #64748B; display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden; }

.cell-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3.5px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; }
.cell-pill--navy { background: #E2E8F0; color: #1D3B5E; }
.cell-pill--teal { background: #CCFBF1; color: #0D7A87; }
.cell-pill--amber { background: #FEF3C7; color: #B45309; }

.mat-type-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; }
.mat-type-pill.pdf    { background: #FEE2E2; color: #DC2626; border: 1px solid #FECDD3; }
.mat-type-pill.slide  { background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A; }
.mat-type-pill.gambar { background: #D1FAE5; color: #059669; border: 1px solid #A7F3D0; }
.mat-type-pill.doc    { background: #E0E7FF; color: #4F46E5; border: 1px solid #C7D2FE; }
.mat-type-pill.video  { background: #FCE7F3; color: #DB2777; border: 1px solid #FBCFE8; }
.mat-type-pill.link   { background: #E0F2FE; color: #0284C7; border: 1px solid #BAE6FD; }

.mat-storage-badge { display: inline-flex; align-items: center; gap: 5px; padding: 3.5px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 700; text-decoration: none; }
.mat-storage-badge.gdrive { background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; }
.mat-storage-badge.gdrive:hover { background: #D1FAE5; }
.mat-storage-badge.local { background: #F1F5F9; color: #475569; border: 1px solid #CBD5E1; }

.mat-tbl-actions { display: flex; align-items: center; justify-content: center; gap: 6px; }
.mat-tbl-btn { width: 32px; height: 32px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid transparent; cursor: pointer; font-size: 16px; transition: all 0.15s; text-decoration: none; }
.mat-tbl-btn--view { background: #E0F2FE; color: #0284C7; border-color: #BAE6FD; }
.mat-tbl-btn--view:hover { background: #0284C7; color: #FFFFFF; transform: translateY(-1px); }
.mat-tbl-btn--dl { background: #ECFDF5; color: #059669; border-color: #A7F3D0; }
.mat-tbl-btn--dl:hover { background: #059669; color: #FFFFFF; transform: translateY(-1px); }
.mat-tbl-btn--edit { background: #FEF3C7; color: #D97706; border-color: #FDE68A; }
.mat-tbl-btn--edit:hover { background: #D97706; color: #FFFFFF; transform: translateY(-1px); }
.mat-tbl-btn--del { background: #FEE2E2; color: #DC2626; border-color: #FECDD3; }
.mat-tbl-btn--del:hover { background: #DC2626; color: #FFFFFF; transform: translateY(-1px); }

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
    margin: auto;                      /* Terpusat jika cukup ruang, disesuaikan via JS jika overflow */
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

.mat-empty { background: #FFFFFF; border: 2px dashed #CBD5E1; border-radius: 16px; padding: 48px 24px; text-align: center; grid-column: 1 / -1; }
.mat-empty-icon { width: 64px; height: 64px; border-radius: 50%; background: rgba(13, 122, 135, 0.08); color: #0D7A87; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 12px; }
.mat-empty h4 { font-family: 'Outfit', sans-serif; font-size: 18px; font-weight: 800; color: #1D3B5E; margin: 0 0 6px; }
.mat-empty p { font-size: 13px; color: #64748B; margin: 0 0 16px; max-width: 420px; margin-left: auto; margin-right: auto; }

.mat-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(15, 29, 47, 0.65); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 99999; display: flex; align-items: center; justify-content: center; padding: 16px; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; }
.mat-modal.show { opacity: 1; pointer-events: auto; }
.mat-modal__box { background: #FFFFFF; border-radius: 20px; width: 100%; max-width: 640px; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 25px 60px -15px rgba(15, 29, 47, 0.35); transform: scale(0.95); transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1); overflow: hidden; }
.mat-modal.show .mat-modal__box { transform: scale(1); }
.mat-modal__head { padding: 18px 22px; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between; background: #F8FAFC; }
.mat-modal__head h3 { font-family: 'Outfit', sans-serif; font-size: 18px; font-weight: 800; color: #1D3B5E; margin: 0; display: flex; align-items: center; gap: 8px; }
.mat-modal__close { background: none; border: none; font-size: 22px; color: #94A3B8; cursor: pointer; line-height: 1; transition: color 0.15s; }
.mat-modal__close:hover { color: #1E293B; }
.mat-modal__body { padding: 22px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }
.mat-modal__foot { padding: 16px 22px; border-top: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: flex-end; gap: 10px; background: #F8FAFC; }

.mat-form-field { display: flex; flex-direction: column; gap: 6px; }
.mat-form-field label { font-size: 12.5px; font-weight: 700; color: #1D3B5E; }
.mat-form-field .req { color: #F43F5E; }
.mat-input, .mat-textarea { width: 100%; padding: 10px 14px; border-radius: 10px; border: 1.5px solid #CBD5E1; font-family: inherit; font-size: 13px; color: #1E293B; outline: none; transition: all 0.2s; background: #FFFFFF; }
.mat-input:focus, .mat-textarea:focus { border-color: #0D7A87; box-shadow: 0 0 0 3px rgba(13, 122, 135, 0.12); }

.mat-dropzone { border: 2px dashed #93C5FD; background: #F0F9FF; border-radius: 12px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.2s; position: relative; }
.mat-dropzone:hover, .mat-dropzone.dragover { border-color: #0D7A87; background: #E0F2FE; }
.mat-dropzone iconify-icon { font-size: 36px; color: #0D7A87; margin-bottom: 6px; }
.mat-dropzone p { font-size: 13px; font-weight: 700; color: #1D3B5E; margin: 0 0 4px; }
.mat-dropzone small { font-size: 11.5px; color: #64748B; }
.mat-dropzone input[type="file"] { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; }
.mat-file-preview { display: none; align-items: center; justify-content: space-between; background: #FFFFFF; border: 1px solid #CBD5E1; border-radius: 10px; padding: 10px 14px; margin-top: 8px; }
.mat-file-preview__info { display: flex; align-items: center; gap: 10px; overflow: hidden; }
.mat-file-preview__info iconify-icon { font-size: 24px; color: #0D7A87; flex-shrink: 0; }
.mat-file-preview__name { font-size: 12.5px; font-weight: 700; color: #1D3B5E; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mat-file-preview__size { font-size: 11px; color: #64748B; }

.mat-upload-progress { display: none; margin-top: 8px; }
.mat-progress-bar { width: 100%; height: 8px; background: #E2E8F0; border-radius: 999px; overflow: hidden; }
.mat-progress-fill { height: 100%; width: 0%; background: linear-gradient(90deg, #0D7A87, #10B981); transition: width 0.3s; }
.mat-progress-label { font-size: 11.5px; color: #64748B; display: flex; justify-content: space-between; margin-top: 4px; }

/* ==============================================================================
 * SCROLL / SLIDE MODE — DUAL AXIS SCROLL (HORIZONTAL & VERTIKAL) + ZOOM
 * ============================================================================== */
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
    margin: 0 auto;                   /* center stabil jika muat; auto→0 jika overflow */
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

/* ==============================================================================
 * TAMPILAN RESPONSIVE MOBILE OPTIMAL & RAPI (BEBAS OVERFLOW & TERPOTONG)
 * ============================================================================== */
@media (max-width: 992px) {
    .mat-header { flex-direction: column; align-items: stretch; gap: 14px; }
    .mat-header__actions { width: 100%; display: flex; flex-wrap: wrap; gap: 8px; }
    .mat-header__actions .mat-btn { flex: 1; min-width: 130px; justify-content: center; }
    #btnTambahMateri { flex: 2; min-width: 160px; }
}

@media (max-width: 768px) {
    .mat-container { padding: 4px 0 28px; }
    .mat-header h2 { font-size: 20px; }
    .mat-header p { font-size: 12px; }
    .mat-header__icon { width: 42px; height: 42px; font-size: 22px; border-radius: 12px; }

    .mat-toolbar-card { padding: 14px 12px; border-radius: 14px; margin-bottom: 16px; }
    .mat-selectors-row { grid-template-columns: 1fr; gap: 10px; margin-bottom: 12px; }

    .mat-filter-row { flex-direction: column; align-items: stretch; gap: 10px; padding-top: 10px; }
    .mat-search-box { width: 100%; min-width: 100%; }
    .mat-search-input { font-size: 12.5px; padding: 8px 12px 8px 36px; }

    /* Category Chips Swipeable Horizontal Scroll on Mobile */
    .mat-tabs {
        display: flex;
        flex-wrap: nowrap;
        overflow-x: auto;
        gap: 6px;
        padding-bottom: 6px;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }
    .mat-tabs::-webkit-scrollbar { display: none; }
    .mat-tab-btn {
        white-space: nowrap;
        flex-shrink: 0;
        padding: 6px 11px;
        font-size: 11.5px;
        border-radius: 8px;
    }

    /* Table Scroll & Sizing on Mobile */
    .ilmuna-crud-tablewrap { border-radius: 14px; margin-bottom: 18px; }
    .ilmuna-crud-table { min-width: 680px; }
    .ilmuna-crud-table th, .ilmuna-crud-table td { padding: 10px 12px; font-size: 12px; }
    .mat-tbl-btn { width: 32px; height: 32px; font-size: 15px; }

    /* Pagination */
    .mat-pagination { flex-direction: column; gap: 10px; align-items: center; text-align: center; padding: 12px; }
    #matPagBtns { flex-wrap: wrap; justify-content: center; }

    /* Form Modal Responsive */
    .mat-modal { padding: 8px; }
    .mat-modal__box { width: 100% !important; max-width: 100% !important; max-height: 94dvh; border-radius: 16px; margin: auto; }
    .mat-modal__head { padding: 14px 16px; }
    .mat-modal__head h3 { font-size: 15.5px; }
    .mat-modal__body { padding: 16px 14px; gap: 12px; }
    .mat-modal__foot { padding: 12px 14px; flex-wrap: wrap; gap: 8px; }
    .mat-modal__foot .mat-btn { flex: 1; justify-content: center; }

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

@media (max-width: 480px) {
    .mat-header__actions { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
    #btnTambahMateri { grid-column: 1 / -1; width: 100%; }
    .mat-header__actions .mat-btn { font-size: 11.5px; padding: 7px 10px; }
    .mat-header h2 { font-size: 18px; }

    .mat-title-box { gap: 8px; }
    .mat-ext-badge { width: 30px; height: 30px; font-size: 15px; border-radius: 8px; }
    .mat-title-main { font-size: 12.5px; }

    .mat-dropzone { padding: 14px 10px; }
    .mat-dropzone iconify-icon { font-size: 28px; }
    .mat-dropzone p { font-size: 12px; }
}
</style>

<div class="mat-container" id="matRoot">

    <div class="mat-header">
        <div class="mat-header__title">
            <div class="mat-header__icon">
                <iconify-icon icon="solar:notebook-bold"></iconify-icon>
            </div>
            <div>
                <h2>Materi & Modul Pembelajaran</h2>
                <p>Kelola bahan ajar, modul digital, e-book Flipbook 3D, dan bahan bacaan siswa</p>
            </div>
        </div>
        <div class="mat-header__actions">
            <button type="button" onclick="downloadTemplateExcel(event)" class="mat-btn mat-btn--outline" title="Download Template Format Excel">
                <iconify-icon icon="solar:file-download-bold"></iconify-icon>
                <span>Template Excel</span>
            </button>
            <button type="button" class="mat-btn mat-btn--outline" id="btnImportExcelModal" title="Import Materi dari Excel">
                <iconify-icon icon="solar:file-upload-bold"></iconify-icon>
                <span>Import Excel</span>
            </button>
            <button type="button" class="mat-btn mat-btn--primary" id="btnTambahMateri">
                <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                <span>Tambah Materi</span>
            </button>
        </div>
    </div>

    <div class="mat-toolbar-card">
        <div class="mat-selectors-row">
            <div class="mat-select-group">
                <label for="selectKelas">
                    <iconify-icon icon="solar:buildings-2-bold"></iconify-icon>
                    <span>Pilih Rombel Kelas</span>
                </label>
                <select class="mat-select" id="selectKelas">
                    <option value="0">Semua Kelas</option>
                </select>
            </div>
            <div class="mat-select-group">
                <label for="selectMapel">
                    <iconify-icon icon="solar:book-bookmark-bold"></iconify-icon>
                    <span>Pilih Mata Pelajaran</span>
                </label>
                <select class="mat-select" id="selectMapel" disabled>
                    <option value="0">— Pilih Rombel Kelas Terlebih Dahulu —</option>
                </select>
            </div>
        </div>

        <div class="mat-filter-row">
            <div class="mat-search-box">
                <iconify-icon icon="solar:magnifer-bold"></iconify-icon>
                <input type="text" class="mat-search-input" id="matSearch" placeholder="Cari judul materi, deskripsi, atau nama berkas...">
            </div>

            <div class="mat-tabs" id="matTabs">
                <button type="button" class="mat-tab-btn active" data-type="all">
                    <span>Semua</span>
                    <span class="mat-tab-badge" id="tabCntAll">0</span>
                </button>
                <button type="button" class="mat-tab-btn" data-type="PDF">
                    <iconify-icon icon="solar:document-bold" style="color:#DC2626;"></iconify-icon>
                    <span>PDF</span>
                    <span class="mat-tab-badge" id="tabCntPdf">0</span>
                </button>
                <button type="button" class="mat-tab-btn" data-type="Slide">
                    <iconify-icon icon="solar:presentation-graph-bold" style="color:#D97706;"></iconify-icon>
                    <span>Slide / PPT</span>
                    <span class="mat-tab-badge" id="tabCntSlide">0</span>
                </button>
                <button type="button" class="mat-tab-btn" data-type="Gambar">
                    <iconify-icon icon="solar:gallery-bold" style="color:#059669;"></iconify-icon>
                    <span>Gambar / Foto</span>
                    <span class="mat-tab-badge" id="tabCntGambar">0</span>
                </button>
                <button type="button" class="mat-tab-btn" data-type="Teks">
                    <iconify-icon icon="solar:notes-bold" style="color:#4F46E5;"></iconify-icon>
                    <span>Dokumen / Teks</span>
                    <span class="mat-tab-badge" id="tabCntDoc">0</span>
                </button>
                <button type="button" class="mat-tab-btn" data-type="Video">
                    <iconify-icon icon="solar:video-library-bold" style="color:#DB2777;"></iconify-icon>
                    <span>Video</span>
                    <span class="mat-tab-badge" id="tabCntVideo">0</span>
                </button>
            </div>
        </div>
    </div>

    <div class="ilmuna-crud-tablewrap">
        <table class="ilmuna-crud-table" id="matTable">
            <thead>
                <tr>
                    <th style="width: 48px; text-align: center;">#</th>
                    <th>Materi Pembelajaran</th>
                    <th style="width: 190px;">Rombel & Mapel</th>
                    <th style="width: 140px;">Format & File</th>
                    <th style="width: 140px;">Penyimpanan</th>
                    <th style="width: 140px;">Waktu & Durasi</th>
                    <th style="width: 170px; text-align: center;">Aksi</th>
                </tr>
            </thead>
            <tbody id="matTableBody">
                <tr>
                    <td colspan="7" class="ilmuna-table-loading">
                        <iconify-icon icon="svg-spinners:ring-resize" width="30"></iconify-icon>
                        <span>Memuat data materi pembelajaran...</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="mat-pagination" id="matPagination" style="display:none;align-items:center;justify-content:space-between;background:#FFF;padding:12px 18px;border-radius:12px;border:1.5px solid var(--lumina-border);box-shadow:0 2px 10px rgba(0,0,0,0.03);">
        <div style="font-size:12.5px;color:#64748B;" id="matPagInfo">Menampilkan data materi</div>
        <div style="display:flex;gap:6px;" id="matPagBtns"></div>
    </div>

</div>

<div class="mat-modal" id="modalMateriForm">
    <div class="mat-modal__box">
        <div class="mat-modal__head">
            <h3 id="modalMateriTitle">
                <iconify-icon icon="solar:notebook-bold"></iconify-icon>
                <span>Tambah Materi Pembelajaran</span>
            </h3>
            <button type="button" class="mat-modal__close" onclick="closeModal('modalMateriForm')">&times;</button>
        </div>
        <form id="formMateri" class="mat-modal__body" onsubmit="return false;" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_materi">
            <input type="hidden" name="id" id="formMateriId" value="0">

            <div class="mat-selectors-row" style="margin-bottom:0;">
                <div class="mat-form-field">
                    <label>Rombel Kelas <span class="req">*</span></label>
                    <select class="mat-select" id="formMateriKelas" required>
                        <option value="">— Pilih Kelas —</option>
                    </select>
                </div>
                <div class="mat-form-field">
                    <label>Mata Pelajaran <span class="req">*</span></label>
                    <select class="mat-select" name="id_kelas_mata_pelajaran" id="formMateriMapel" required disabled>
                        <option value="">— Pilih Mapel —</option>
                    </select>
                </div>
            </div>

            <div class="mat-form-field">
                <label>Judul Materi Pembelajaran <span class="req">*</span></label>
                <input type="text" name="judul_materi" id="formJudul" required class="mat-input" placeholder="Contoh: Memahami Makna 7 Asmaul Husna" maxlength="250">
            </div>

            <div class="mat-form-field">
                <label>Unggah Berkas Materi (Penyimpanan Server Lokal)</label>
                <div class="mat-dropzone" id="dropzoneFile">
                    <iconify-icon icon="solar:cloud-upload-bold"></iconify-icon>
                    <p>Tarik & Lepas berkas ke sini, atau klik untuk memilih</p>
                    <small>Mendukung PDF, Word (.docx), PowerPoint (.pptx), Excel (.xlsx), Gambar, Video, Teks (Maksimal 1 MB - Server Lokal)</small>
                    <input type="file" id="formFileInput" name="file_materi">
                </div>
                <div class="mat-file-preview" id="filePreviewWrap">
                    <div class="mat-file-preview__info">
                        <iconify-icon icon="solar:document-bold" id="filePreviewIcon"></iconify-icon>
                        <div>
                            <div class="mat-file-preview__name" id="filePreviewName">nama_file.pdf</div>
                            <div class="mat-file-preview__size" id="filePreviewBytes">0 KB</div>
                        </div>
                    </div>
                    <button type="button" class="mat-btn mat-btn--danger" style="padding:4px 8px;font-size:11px;" id="btnRemoveFile">Batal</button>
                </div>
                <div class="mat-upload-progress" id="uploadProgressWrap">
                    <div class="mat-progress-bar">
                        <div class="mat-progress-fill" id="progressFill"></div>
                    </div>
                    <div class="mat-progress-label">
                        <span id="progressStatus">Menyimpan berkas ke server lokal...</span>
                        <span id="progressPercent">0%</span>
                    </div>
                </div>
            </div>

            <div class="mat-selectors-row" style="margin-bottom:0;">
                <div class="mat-form-field">
                    <label>Tipe Media Materi</label>
                    <select class="mat-select" name="tipe_materi" id="formTipeMateri">
                        <option value="PDF">PDF Dokumen (Flipbook 3D)</option>
                        <option value="Slide">Slide Presentasi (PPT/PPTX)</option>
                        <option value="Gambar">Gambar / Foto (PNG, JPG, WEBP, GIF)</option>
                        <option value="Teks">Dokumen Word / Teks Bacaan</option>
                        <option value="Video">Video Pembelajaran</option>
                        <option value="Link Eksternal">Tautan / Link Eksternal</option>
                    </select>
                </div>
                <div class="mat-form-field">
                    <label>Estimasi Durasi Belajar (Menit)</label>
                    <input type="number" name="durasi_menit" id="formDurasi" class="mat-input" value="15" min="1" max="300">
                </div>
            </div>

            <div class="mat-form-field">
                <label>Tautan Link Materi (Opsional jika mengunggah file)</label>
                <input type="url" name="link_materi" id="formLink" class="mat-input" placeholder="https://drive.google.com/file/d/... atau https://youtube.com/...">
                <small style="font-size:11px;color:#64748B;">Bisa dikosongkan jika mengunggah berkas. Tautan materi akan otomatis terisi alamat berkas lokal server.</small>
            </div>

            <div class="mat-form-field">
                <label>Deskripsi / Ringkasan Materi</label>
                <textarea name="deskripsi_singkat" id="formDeskripsi" class="mat-textarea" rows="3" placeholder="Tuliskan ringkasan atau isi pokok bahasan materi ini..."></textarea>
            </div>
        </form>
        <div class="mat-modal__foot">
            <button type="button" class="mat-btn mat-btn--outline" onclick="closeModal('modalMateriForm')">Batal</button>
            <button type="button" class="mat-btn mat-btn--primary" id="btnSimpanMateri">
                <iconify-icon icon="solar:diskette-bold"></iconify-icon>
                <span>Simpan Materi</span>
            </button>
        </div>
    </div>
</div>

<div class="mat-modal" id="modalImportExcel">
    <div class="mat-modal__box" style="max-width:540px;">
        <div class="mat-modal__head">
            <h3>
                <iconify-icon icon="solar:file-upload-bold"></iconify-icon>
                <span>Import Materi dari File Excel (.xlsx)</span>
            </h3>
            <button type="button" class="mat-modal__close" onclick="closeModal('modalImportExcel')">&times;</button>
        </div>
        <form id="formImportExcel" class="mat-modal__body" onsubmit="return false;" enctype="multipart/form-data">
            <p style="margin:0;font-size:12.5px;color:#64748B;">
                Anda dapat menambahkan banyak materi sekaligus menggunakan berkas Excel <code>.xlsx</code>. Pastikan format kolom sesuai dengan template.
            </p>

            <div class="mat-selectors-row" style="margin-bottom:0;">
                <div class="mat-form-field">
                    <label>Rombel Kelas Sasaran <span class="req">*</span></label>
                    <select class="mat-select" id="importKelas" required>
                        <option value="">— Pilih Kelas —</option>
                    </select>
                </div>
                <div class="mat-form-field">
                    <label>Mata Pelajaran <span class="req">*</span></label>
                    <select class="mat-select" name="id_kelas_mata_pelajaran" id="importMapel" required disabled>
                        <option value="">— Pilih Mapel —</option>
                    </select>
                </div>
            </div>

            <div class="mat-form-field">
                <label>Pilih File Excel (.xlsx) <span class="req">*</span></label>
                <input type="file" name="file_excel" id="fileExcelInput" accept=".xlsx" class="mat-input" required>
            </div>

            <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px;font-size:12px;color:#475569;">
                Belum memiliki template? <a href="?action=download_template" style="color:#0D7A87;font-weight:700;">Unduh Template Excel di sini</a>.
            </div>
        </form>
        <div class="mat-modal__foot">
            <button type="button" class="mat-btn mat-btn--outline" onclick="closeModal('modalImportExcel')">Batal</button>
            <button type="button" class="mat-btn mat-btn--primary" id="btnDoImportExcel">
                <iconify-icon icon="solar:file-upload-bold"></iconify-icon>
                <span>Mulai Import</span>
            </button>
        </div>
    </div>
</div>

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

<script>
(function(){
    const ENDPOINT = window.location.pathname;

    let currentKelasId = 0;
    let currentKmpId   = 0;
    let currentType    = 'all';
    let currentSearch  = '';
    let currentPage    = 1;
    let isSubmitting   = false;

    const selectKelas    = document.getElementById('selectKelas');
    const selectMapel    = document.getElementById('selectMapel');
    const matSearch      = document.getElementById('matSearch');
    const matTabs        = document.getElementById('matTabs');
    const matTableBody   = document.getElementById('matTableBody');
    const paginationWrap = document.getElementById('matPagination');

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

    function showToast(title, message, type = 'success') {
        if (window.Ilmuna) return Ilmuna.toast(dlgType(type), title, message, 4000);
        console.warn(title + ': ' + message);
    }

    window.openModal = function(id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('show');
    };
    window.closeModal = function(id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('show');
    };

    // =====================================================================
    // TEMPLATE ALERT GLOBAL (assets/js/alert.js -> window.Ilmuna)
    // luminaAlert / luminaConfirm dipertahankan sebagai pembungkus agar
    // pemanggilan lama tetap berfungsi.
    // =====================================================================
    function dlgText(s) {
        return escapeHtml(String(s == null ? '' : s)).replace(/\n/g, '<br>');
    }
    function dlgType(type) {
        return (type === 'warn') ? 'warning' : (type || 'info');
    }

    window.luminaAlert = function(title, message, type = 'info') {
        return new Promise((resolve) => {
            if (!window.Ilmuna) {
                (window._nativeAlert || console.log)(message || title);
                return resolve(true);
            }
            Ilmuna.alert(dlgType(type), dlgText(title || 'Pemberitahuan'), dlgText(message), 'Tutup', false, () => resolve(true));
        });
    };

    window.luminaConfirm = function(title, message, confirmText = 'Lanjutkan', cancelText = 'Batal', type = 'warning') {
        if (!window.Ilmuna) return Promise.resolve(window.confirm(message || title));
        return Ilmuna.confirm(dlgType(type), dlgText(title || 'Konfirmasi'), dlgText(message), confirmText, cancelText);
    };

    // Override window.alert bawaan browser agar memakai template Ilmuna
    // (dipasang saat DOMContentLoaded supaya tidak ditimpa script global lain)
    if (!window._nativeAlert) {
        window._nativeAlert = window.alert;
    }
    document.addEventListener('DOMContentLoaded', function() {
        window.alert = function(message) {
            return window.luminaAlert('Pemberitahuan', message, 'info');
        };
    });
    window.downloadTemplateExcel = async function(e) {
        if (e) e.preventDefault();
        window.location.href = `${ENDPOINT}?action=download_template`;
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

    async function loadKelasOptions() {
        try {
            const fd = new FormData();
            fd.append('action', 'list_kelas');
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd }).then(r => r.json());
            if (res && res.success) {
                let html = '<option value="0">Semua Rombel Kelas</option>';
                let htmlForm = '<option value="">— Pilih Kelas —</option>';
                if (!res.data || res.data.length === 0) {
                    html = '<option value="0">Belum ada kelas yang Anda ampu</option>';
                    htmlForm = '<option value="">Belum ada kelas yang Anda ampu</option>';
                } else {
                    res.data.forEach(c => {
                        html += `<option value="${c.id}">${c.nama_kelas} (${c.tahun_pelajaran})</option>`;
                        htmlForm += `<option value="${c.id}">${c.nama_kelas}</option>`;
                    });
                }
                selectKelas.innerHTML = html;
                document.getElementById('formMateriKelas').innerHTML = htmlForm;
                document.getElementById('importKelas').innerHTML = htmlForm;
            }
        } catch(e) {
            console.error('Gagal memuat kelas:', e);
        }
    }

    async function loadMapelOptions(kelasId, targetSelectId, defaultKmpId = 0) {
        const sel = document.getElementById(targetSelectId);
        if (!kelasId || kelasId <= 0) {
            sel.innerHTML = '<option value="0">— Pilih Rombel Kelas Terlebih Dahulu —</option>';
            sel.disabled = true;
            return;
        }

        sel.disabled = true;
        sel.innerHTML = '<option value="0">Memuat mata pelajaran...</option>';

        try {
            const fd = new FormData();
            fd.append('action', 'list_mapel');
            fd.append('id_kelas', kelasId);
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd }).then(r => r.json());
            if (res && res.success) {
                let html = (targetSelectId === 'selectMapel') ? '<option value="0">Semua Mata Pelajaran</option>' : '<option value="">— Pilih Mata Pelajaran —</option>';
                if (!res.data || res.data.length === 0) {
                    sel.innerHTML = '<option value="0">Tidak ada mapel yang Anda ampu di kelas ini</option>';
                    sel.disabled = true;
                    return;
                }
                res.data.forEach(m => {
                    const selAttr = (defaultKmpId && defaultKmpId == m.id) ? 'selected' : '';
                    html += `<option value="${m.id}" ${selAttr}>${m.nama_mata_pelajaran} (${m.semester})</option>`;
                });
                sel.innerHTML = html;
                sel.disabled = false;
            } else {
                sel.innerHTML = '<option value="0">Tidak ada mapel yang Anda ampu</option>';
            }
        } catch(e) {
            sel.innerHTML = '<option value="0">Gagal memuat mata pelajaran</option>';
        }
    }

    selectKelas.addEventListener('change', function() {
        currentKelasId = parseInt(this.value, 10) || 0;
        currentKmpId = 0;
        loadMapelOptions(currentKelasId, 'selectMapel');
        loadMateri(1);
    });

    selectMapel.addEventListener('change', function() {
        currentKmpId = parseInt(this.value, 10) || 0;
        loadMateri(1);
    });

    document.getElementById('formMateriKelas').addEventListener('change', function() {
        loadMapelOptions(this.value, 'formMateriMapel');
    });
    document.getElementById('importKelas').addEventListener('change', function() {
        loadMapelOptions(this.value, 'importMapel');
    });

    async function loadMateri(page = 1) {
        currentPage = page;
        matTableBody.innerHTML = `
            <tr>
                <td colspan="7" class="ilmuna-table-loading">
                    <iconify-icon icon="svg-spinners:ring-resize" width="30"></iconify-icon>
                    <span>Memuat daftar materi pembelajaran...</span>
                </td>
            </tr>
        `;

        try {
            const fd = new FormData();
            fd.append('action', 'list_materi');
            fd.append('id_kelas_mata_pelajaran', currentKmpId);
            fd.append('media_type', currentType);
            fd.append('search', currentSearch);
            fd.append('page', page);
            fd.append('per', 10);

            const res = await fetch(ENDPOINT, { method: 'POST', body: fd }).then(r => r.json());

            if (res && res.success) {
                const st = res.stats || {};
                document.getElementById('tabCntAll').textContent    = st.all || 0;
                document.getElementById('tabCntPdf').textContent    = st.pdf || 0;
                document.getElementById('tabCntSlide').textContent  = st.slide || 0;
                const tabGambar = document.getElementById('tabCntGambar');
                if (tabGambar) tabGambar.textContent = st.gambar || 0;
                document.getElementById('tabCntDoc').textContent    = st.doc || 0;
                document.getElementById('tabCntVideo').textContent  = st.video || 0;

                renderMateriTable(res.data || []);
                renderPagination(res.page, res.pages, res.total);
            } else {
                matTableBody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:36px;color:#EF4444;">${res.message || 'Gagal memuat materi.'}</td></tr>`;
            }
        } catch(e) {
            matTableBody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:36px;color:#EF4444;">Terjadi gangguan koneksi jaringan.</td></tr>`;
            console.error(e);
        }
    }

    function renderMateriTable(rows) {
        if (!rows || rows.length === 0) {
            matTableBody.innerHTML = `
                <tr>
                    <td colspan="7" style="padding:0;">
                        <div class="mat-empty">
                            <div class="mat-empty-icon">
                                <iconify-icon icon="solar:notebook-bold"></iconify-icon>
                            </div>
                            <h4>Belum Ada Materi Pembelajaran</h4>
                            <p>Materi pembelajaran belum ditambahkan untuk kategori atau filter ini. Klik tombol di bawah untuk menambahkan materi pertama Anda.</p>
                            <button type="button" class="mat-btn mat-btn--primary" onclick="document.getElementById('btnTambahMateri').click()">
                                <iconify-icon icon="solar:add-circle-bold"></iconify-icon>
                                <span>Tambah Materi Baru</span>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        let html = '';
        rows.forEach((m, idx) => {
            const tipe = m.tipe_materi || 'PDF';
            let iconClass  = 'pdf';
            let iconName   = 'solar:document-bold';
            let badgeClass = 'pdf';
            let badgeLabel = 'PDF';
            let viewTooltip = 'Baca di Flipbook 3D (Tanpa Download)';

            if (tipe === 'Slide') {
                iconClass   = 'slide';
                iconName    = 'solar:presentation-graph-bold';
                badgeClass  = 'slide';
                badgeLabel  = 'Slide PPT';
                viewTooltip = 'Buka Flipbook / Pratinjau Slide';
            } else if (tipe === 'Gambar') {
                iconClass   = 'gambar';
                iconName    = 'solar:gallery-bold';
                badgeClass  = 'gambar';
                badgeLabel  = 'Gambar';
                viewTooltip = 'Lihat Gambar (Tanpa Download)';
            } else if (tipe === 'Teks') {
                iconClass   = 'doc';
                iconName    = 'solar:notes-bold';
                badgeClass  = 'doc';
                badgeLabel  = 'Dokumen';
                viewTooltip = 'Baca di Flipbook 3D';
            } else if (tipe === 'Video') {
                iconClass   = 'video';
                iconName    = 'solar:video-library-bold';
                badgeClass  = 'video';
                badgeLabel  = 'Video';
                viewTooltip = 'Tonton Video';
            } else if (tipe === 'Link Eksternal') {
                iconClass   = 'link';
                iconName    = 'solar:link-bold';
                badgeClass  = 'link';
                badgeLabel  = 'Tautan';
                viewTooltip = 'Lihat Materi';
            }

            const isGDrive  = (m.link_materi && m.link_materi.includes('drive.google.com')) || m.gdrive_file_id;
            const fileSize  = m.ukuran_file ? m.ukuran_file : '';
            const durasi    = m.durasi_menit ? `${m.durasi_menit} mnt` : '15 mnt';
            const dateStr   = m.created_at ? new Date(m.created_at).toLocaleDateString('id-ID', {day: 'numeric', month: 'short', year: 'numeric'}) : '';
            const rowNumber = String((currentPage - 1) * 10 + idx + 1).padStart(2, '0');
            const dlUrl     = `${ENDPOINT}?action=download_materi&id=${m.id}`;

            html += `
                <tr data-id="${m.id}">
                    <td class="mat-row-num">${rowNumber}</td>
                    <td>
                        <div class="mat-title-box">
                            <div class="mat-ext-badge ${iconClass}">
                                <iconify-icon icon="${iconName}"></iconify-icon>
                            </div>
                            <div class="mat-title-text">
                                <div class="mat-title-main" data-act="preview" data-id="${m.id}" title="Klik untuk Membaca di Flipbook (Tanpa Download)">
                                    ${escapeHtml(m.judul_materi)}
                                </div>
                                <div class="mat-title-sub">
                                    ${escapeHtml(m.deskripsi_singkat || m.file_materi || 'Tidak ada ringkasan materi.')}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            <span class="cell-pill cell-pill--navy" title="Rombel Kelas">
                                <iconify-icon icon="solar:buildings-2-bold" style="font-size:12px;"></iconify-icon>
                                <span>${escapeHtml(m.nama_kelas || 'Umum')}</span>
                            </span>
                            <span class="cell-pill cell-pill--teal" title="Mata Pelajaran">
                                <iconify-icon icon="solar:book-bookmark-bold" style="font-size:12px;"></iconify-icon>
                                <span>${escapeHtml(m.nama_mata_pelajaran || 'Mapel')}</span>
                            </span>
                        </div>
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:4px;">
                            <span class="mat-type-pill ${badgeClass}">
                                <iconify-icon icon="${iconName}"></iconify-icon>
                                <span>${badgeLabel}</span>
                            </span>
                            ${fileSize ? `<span style="font-size:11px;color:#64748B;font-family:monospace;">${fileSize}</span>` : ''}
                        </div>
                    </td>
                    <td>
                        ${isGDrive ? `
                            <span class="mat-storage-badge gdrive" style="cursor:pointer;" data-act="preview" data-id="${m.id}" title="Klik untuk Membaca di Flipbook">
                                <iconify-icon icon="logos:google-drive" style="font-size:13px;"></iconify-icon>
                                <span>Google Drive</span>
                            </span>
                        ` : `
                            <span class="mat-storage-badge local" style="cursor:pointer;" data-act="preview" data-id="${m.id}" title="Klik untuk Membaca di Flipbook">
                                <iconify-icon icon="solar:server-square-bold" style="font-size:13px;color:#64748B;"></iconify-icon>
                                <span>Server Lokal</span>
                            </span>
                        `}
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:3px;font-size:12px;">
                            <span class="cell-pill cell-pill--amber" style="padding:2px 8px;font-size:11px;width:max-content;">
                                <iconify-icon icon="solar:clock-circle-bold"></iconify-icon> ${durasi}
                            </span>
                            <span style="color:#64748B;font-size:11.5px;">${dateStr}</span>
                        </div>
                    </td>
                    <td>
                        <div class="mat-tbl-actions">
                            <button type="button" class="mat-tbl-btn mat-tbl-btn--view" data-act="preview" data-id="${m.id}" title="${viewTooltip}">
                                <iconify-icon icon="solar:eye-bold"></iconify-icon>
                            </button>
                            <a href="${dlUrl}" class="mat-tbl-btn mat-tbl-btn--dl" title="Download Berkas Materi" target="_blank">
                                <iconify-icon icon="solar:download-minimalistic-bold"></iconify-icon>
                            </a>
                            <button type="button" class="mat-tbl-btn mat-tbl-btn--edit" data-act="edit" data-id="${m.id}" title="Edit Data Materi">
                                <iconify-icon icon="solar:pen-bold"></iconify-icon>
                            </button>
                            <button type="button" class="mat-tbl-btn mat-tbl-btn--del" data-act="delete" data-id="${m.id}" data-title="${escapeHtml(m.judul_materi)}" title="Hapus Materi">
                                <iconify-icon icon="solar:trash-bin-trash-bold"></iconify-icon>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        });
        matTableBody.innerHTML = html;
    }

    function renderPagination(cur, totalPages, totalRows) {
        if (totalPages <= 1) {
            paginationWrap.style.display = 'none';
            return;
        }
        paginationWrap.style.display = 'flex';
        document.getElementById('matPagInfo').textContent = `Menampilkan halaman ${cur} dari total ${totalPages} (${totalRows} materi pembelajaran)`;

        const btnWrap = document.getElementById('matPagBtns');
        btnWrap.innerHTML = '';

        const prev = document.createElement('button');
        prev.className = 'mat-btn mat-btn--outline';
        prev.style.padding = '5px 10px';
        prev.style.fontSize = '12px';
        prev.textContent = '« Prev';
        prev.disabled = (cur <= 1);
        prev.onclick = () => loadMateri(cur - 1);
        btnWrap.appendChild(prev);

        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= cur - 1 && i <= cur + 1)) {
                const b = document.createElement('button');
                b.className = 'mat-btn ' + (i === cur ? 'mat-btn--primary' : 'mat-btn--outline');
                b.style.padding = '5px 12px';
                b.style.fontSize = '12px';
                b.textContent = i;
                b.onclick = () => loadMateri(i);
                btnWrap.appendChild(b);
            }
        }

        const next = document.createElement('button');
        next.className = 'mat-btn mat-btn--outline';
        next.style.padding = '5px 10px';
        next.style.fontSize = '12px';
        next.textContent = 'Next »';
        next.disabled = (cur >= totalPages);
        next.onclick = () => loadMateri(cur + 1);
        btnWrap.appendChild(next);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

    matTabs.addEventListener('click', function(e) {
        const btn = e.target.closest('[data-type]');
        if (!btn) return;
        matTabs.querySelectorAll('.mat-tab-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentType = btn.dataset.type;
        loadMateri(1);
    });

    let searchTimer = null;
    matSearch.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            currentSearch = this.value.trim();
            loadMateri(1);
        }, 350);
    });

    matTableBody.addEventListener('click', async function(e) {
        const btn = e.target.closest('[data-act]');
        if (!btn) return;

        e.preventDefault();
        const act = btn.dataset.act;
        const id  = parseInt(btn.dataset.id, 10);

        if (act === 'preview') {
            openMateriViewer(id);
            return;
        }

        if (act === 'edit') {
            openEditForm(id);
            return;
        }

        if (act === 'delete') {
            const title = btn.dataset.title || 'Materi ini';
            const ok = await luminaConfirm(
                'Hapus Materi?',
                `Apakah Anda yakin ingin menghapus materi:\n"${title}"?`,
                'Hapus Materi',
                'Batal',
                'error'
            );
            if (ok) {
                try {
                    const fd = new FormData();
                    fd.append('action', 'delete_materi');
                    fd.append('id', id);
                    const res = await fetch(ENDPOINT, { method: 'POST', body: fd }).then(r => r.json());
                    if (res && res.success) {
                        showToast('Materi Dihapus', res.message, 'success');
                        loadMateri(currentPage);
                    } else {
                        showToast('Gagal Menghapus', res.message || 'Terjadi kesalahan.', 'error');
                    }
                } catch(err) {
                    showToast('Gagal Menghapus', 'Koneksi terputus.', 'error');
                }
            }
            return;
        }
    });

    const dropzone      = document.getElementById('dropzoneFile');
    const fileInput     = document.getElementById('formFileInput');
    const filePreview   = document.getElementById('filePreviewWrap');
    const fileNameSpan  = document.getElementById('filePreviewName');
    const fileSizeSpan  = document.getElementById('filePreviewBytes');
    const btnRemoveFile = document.getElementById('btnRemoveFile');

    fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            handleFileSelect(this.files[0]);
        }
    });

    ['dragenter', 'dragover'].forEach(evt => {
        dropzone.addEventListener(evt, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.add('dragover');
        });
    });
    ['dragleave', 'drop'].forEach(evt => {
        dropzone.addEventListener(evt, (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropzone.classList.remove('dragover');
        });
    });
    dropzone.addEventListener('drop', (e) => {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            handleFileSelect(e.dataTransfer.files[0]);
        }
    });

    function handleFileSelect(file) {
        if (!file) return;

        if (file.size > 1024 * 1024) {
            showToast('Ukuran Berkas Terlalu Besar', `Berkas "${escapeHtml(file.name)}" (${formatBytes(file.size)}) melebihi batas maksimal 1 MB untuk penyimpanan server lokal.`, 'error');
            fileInput.value = '';
            btnRemoveFile.click();
            return;
        }

        fileNameSpan.textContent = file.name;
        fileSizeSpan.textContent = formatBytes(file.size);
        filePreview.style.display = 'flex';
        dropzone.style.display = 'none';

        const ext = file.name.split('.').pop().toLowerCase();
        const selTipe = document.getElementById('formTipeMateri');
        if (ext === 'pdf') {
            selTipe.value = 'PDF';
        } else if (['ppt', 'pptx'].includes(ext)) {
            selTipe.value = 'Slide';
        } else if (['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'bmp'].includes(ext)) {
            selTipe.value = 'Gambar';
        } else if (['doc', 'docx', 'txt', 'rtf'].includes(ext)) {
            selTipe.value = 'Teks';
        } else if (['mp4', 'mkv', 'avi', 'mov', 'webm'].includes(ext)) {
            selTipe.value = 'Video';
        }

        const judulInput = document.getElementById('formJudul');
        if (!judulInput.value) {
            judulInput.value = file.name.substring(0, file.name.lastIndexOf('.')) || file.name;
        }
    }

    btnRemoveFile.addEventListener('click', function() {
        fileInput.value = '';
        filePreview.style.display = 'none';
        dropzone.style.display = 'block';
    });

    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    document.getElementById('btnTambahMateri').addEventListener('click', function() {
        document.getElementById('formMateri').reset();
        document.getElementById('formMateriId').value = '0';
        document.getElementById('modalMateriTitle').querySelector('span').textContent = 'Tambah Materi Pembelajaran';
        btnRemoveFile.click();

        if (currentKelasId > 0) {
            document.getElementById('formMateriKelas').value = currentKelasId;
            loadMapelOptions(currentKelasId, 'formMateriMapel', currentKmpId);
        } else {
            document.getElementById('formMateriMapel').disabled = true;
            document.getElementById('formMateriMapel').innerHTML = '<option value="">— Pilih Mapel —</option>';
        }

        openModal('modalMateriForm');
    });

    async function openEditForm(id) {
        try {
            const fd = new FormData();
            fd.append('action', 'get_materi');
            fd.append('id', id);
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd }).then(r => r.json());
            if (res && res.success && res.data) {
                const d = res.data;
                document.getElementById('formMateri').reset();
                document.getElementById('formMateriId').value = d.id;
                document.getElementById('modalMateriTitle').querySelector('span').textContent = 'Edit Materi Pembelajaran';
                document.getElementById('formJudul').value = d.judul_materi || '';
                document.getElementById('formTipeMateri').value = d.tipe_materi || 'PDF';
                document.getElementById('formLink').value = d.link_materi || '';
                document.getElementById('formDeskripsi').value = d.deskripsi_singkat || '';
                document.getElementById('formDurasi').value = d.durasi_menit || 15;

                btnRemoveFile.click();
                if (d.file_materi) {
                    fileNameSpan.textContent = d.file_materi;
                    fileSizeSpan.textContent = d.ukuran_file || 'Berkas Terlampir';
                    filePreview.style.display = 'flex';
                    dropzone.style.display = 'none';
                }

                if (d.id_kelas) {
                    document.getElementById('formMateriKelas').value = d.id_kelas;
                    loadMapelOptions(d.id_kelas, 'formMateriMapel', d.id_kelas_mata_pelajaran);
                }

                openModal('modalMateriForm');
            } else {
                showToast('Gagal', res.message || 'Materi tidak ditemukan.', 'error');
            }
        } catch(e) {
            showToast('Kesalahan', 'Gagal memuat data materi.', 'error');
        }
    }

    document.getElementById('btnSimpanMateri').addEventListener('click', async function() {
        if (isSubmitting) return;

        const form     = document.getElementById('formMateri');
        const kelasVal = document.getElementById('formMateriKelas').value;
        const idKmp    = document.getElementById('formMateriMapel').value;
        const judul    = document.getElementById('formJudul').value.trim();
        const materiId = document.getElementById('formMateriId').value;
        const linkVal  = document.getElementById('formLink').value.trim();
        const descVal  = document.getElementById('formDeskripsi').value.trim();
        const hasFile  = fileInput.files && fileInput.files.length > 0;

        if (!kelasVal || kelasVal === '0') {
            showToast('Peringatan', 'Silakan pilih Rombel Kelas terlebih dahulu.', 'warn');
            document.getElementById('formMateriKelas').focus();
            return;
        }

        if (!idKmp || idKmp === '0' || idKmp === '') {
            showToast('Peringatan', 'Silakan pilih Mata Pelajaran.', 'warn');
            document.getElementById('formMateriMapel').focus();
            return;
        }

        if (!judul) {
            showToast('Peringatan', 'Judul materi wajib diisi.', 'warn');
            document.getElementById('formJudul').focus();
            return;
        }

        if (materiId === '0' && !hasFile && !linkVal && !descVal) {
            showToast('Peringatan', 'Silakan pilih berkas materi untuk diunggah, masukkan tautan link, atau isi ringkasan materi.', 'warn');
            return;
        }

        const btn = this;
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = `<iconify-icon icon="solar:refresh-bold" style="animation:spin 1s infinite linear;"></iconify-icon> <span>Menyimpan berkas materi...</span>`;

        const progressWrap = document.getElementById('uploadProgressWrap');
        const progressFill = document.getElementById('progressFill');
        const progressPercent = document.getElementById('progressPercent');
        const progressStatus = document.getElementById('progressStatus');

        if (hasFile) {
            progressWrap.style.display = 'block';
            progressFill.style.width = '25%';
            progressPercent.textContent = '25%';
            progressStatus.textContent = 'Membaca berkas & menyiapkan penyimpanan...';
        }

        isSubmitting = true;
        const formData = new FormData(form);
        formData.set('action', 'save_materi');
        formData.set('id_kelas_mata_pelajaran', idKmp);

        try {
            if (hasFile) {
                progressFill.style.width = '65%';
                progressPercent.textContent = '65%';
                progressStatus.textContent = 'Menyimpan berkas materi ke server lokal...';
            }

            const response = await fetch(ENDPOINT, {
                method: 'POST',
                body: formData
            });

            const rawText = await response.text();
            let res;
            try {
                res = JSON.parse(rawText);
            } catch(parseErr) {
                throw new Error("Respon server tidak valid: " + rawText.replace(/<[^>]*>?/gm, '').substring(0, 100));
            }

            if (hasFile) {
                progressFill.style.width = '100%';
                progressPercent.textContent = '100%';
            }

            if (res && res.success) {
                showToast('Alhamdulillah!', res.message, 'success');
                closeModal('modalMateriForm');
                loadMateri(currentPage);
            } else {
                showToast('Gagal Menyimpan', res.message || 'Terjadi kesalahan.', 'error');
            }
        } catch(err) {
            showToast('Kesalahan Jaringan', err.message || 'Gagal menghubungi server.', 'error');
        } finally {
            isSubmitting = false;
            btn.disabled = false;
            btn.innerHTML = origText;
            progressWrap.style.display = 'none';
        }
    });

    document.getElementById('btnImportExcelModal').addEventListener('click', function() {
        document.getElementById('formImportExcel').reset();
        if (currentKelasId > 0) {
            document.getElementById('importKelas').value = currentKelasId;
            loadMapelOptions(currentKelasId, 'importMapel', currentKmpId);
        }
        openModal('modalImportExcel');
    });

    document.getElementById('btnDoImportExcel').addEventListener('click', async function() {
        const form = document.getElementById('formImportExcel');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const btn = this;
        btn.disabled = true;
        btn.innerHTML = `<iconify-icon icon="solar:refresh-bold" style="animation:spin 1s infinite linear;"></iconify-icon> <span>Mengimpor...</span>`;

        try {
            const fd = new FormData(form);
            fd.append('action', 'import_excel');
            const res = await fetch(ENDPOINT, { method: 'POST', body: fd }).then(r => r.json());
            if (res && res.success) {
                showToast('Import Selesai', res.message, 'success');
                closeModal('modalImportExcel');
                loadMateri(1);
            } else {
                showToast('Gagal Import', res.message || 'Terjadi kesalahan.', 'error');
            }
        } catch(e) {
            showToast('Kesalahan', 'Gagal memproses import Excel.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = `<iconify-icon icon="solar:file-upload-bold"></iconify-icon> <span>Mulai Import</span>`;
        }
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
        // Reset zoom 3D
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

        // Horisontal: jika buku lebih lebar dari wrap, ratakan ke start (0px)
        // agar lembar kiri tidak terpotong saat discroll
        if (contW >= wrapW - 60) {
            container.style.marginLeft = '0px';
            container.style.marginRight = '48px';
        } else {
            container.style.marginLeft = 'auto';
            container.style.marginRight = 'auto';
        }

        // Vertikal: jika buku lebih tinggi dari wrap, ratakan ke start (0px)
        // agar header/bagian atas tidak terpotong saat discroll
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

        // Dukungan scroll roda mouse horizontal dengan Shift
        element.addEventListener('wheel', (e) => {
            if (e.shiftKey) {
                e.preventDefault();
                element.scrollLeft += e.deltaY;
            }
        }, { passive: false });
    }

    async function openMateriViewer(id) {
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
    }

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

                // Pada mobile (portrait), 1 lembar halaman harus cukup besar dan terbaca jelas
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
                // Tampilkan toolbar zoom 3D
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
        // Tampilkan toolbar zoom 3D jika flipbook sudah ada
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

    // Resize handler (memperbarui layout scroll horizontal & vertikal kedua mode)
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
                luminaAlert('Layar Penuh Gagal', `Gagal masuk layar penuh: ${err.message}`, 'error');
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

    loadKelasOptions();
    loadMateri(1);

})();
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>