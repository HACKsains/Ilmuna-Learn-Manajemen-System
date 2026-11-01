<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BACKEND EXCEL HANDLER (TOKEN ABSENSI SISWA)
 * ==============================================================================
 * Export Master Data Token Absensi (.xlsx) — Tema Lumina Gold
 *
 * Standar Output: OpenXML Spreadsheet (.xlsx)
 * Engine: Hybrid ZipArchive Native / Pure-PHP ZIP Adapter (Zero-Failsafe)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

if (php_sapi_name() !== 'cli') {
    requireRole('admin');
}

$pdo = getDBConnection();

/* ==============================================================================
 * HELPER: OPENXML XLSX UTILITIES
 * ============================================================================== */

if (!function_exists('ilmuna_xlsx_col_letter')) {
    function ilmuna_xlsx_col_letter($idx) {
        $letter = ''; $idx = (int)$idx + 1;
        while ($idx > 0) {
            $mod = ($idx - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $idx = intdiv($idx - $mod, 26);
        }
        return $letter;
    }
}

if (!function_exists('ilmuna_xlsx_col_index')) {
    function ilmuna_xlsx_col_index($letter) {
        $letter = strtoupper($letter); $num = 0;
        for ($i = 0; $i < strlen($letter); $i++) {
            $num = $num * 26 + (ord($letter[$i]) - 64);
        }
        return $num - 1;
    }
}

if (!function_exists('ilmuna_get_mapel_prefix')) {
    function ilmuna_get_mapel_prefix($namaMapel) {
        $clean = trim((string)$namaMapel);
        if (preg_match('/\(([A-Za-z0-9]+)\)/', $clean, $m)) return strtoupper($m[1]);
        $lower = strtolower($clean);
        if (strpos($lower, 'sejarah') !== false || strpos($lower, 'ski') !== false) return 'SKI';
        if (strpos($lower, 'qur') !== false || strpos($lower, 'hadits') !== false || strpos($lower, 'tajwid') !== false) return 'QHD';
        if (strpos($lower, 'akidah') !== false || strpos($lower, 'akhlak') !== false) return 'AAK';
        if (strpos($lower, 'fiqih') !== false || strpos($lower, 'fikih') !== false) return 'FQH';
        if (strpos($lower, 'arab') !== false) return 'BAR';
        if (strpos($lower, 'agama') !== false || strpos($lower, 'pai') !== false) return 'PAI';
        if (strpos($lower, 'matematika') !== false || strpos($lower, 'mtk') !== false) return 'MTK';
        if (strpos($lower, 'indonesia') !== false) return 'BIN';
        if (strpos($lower, 'inggris') !== false) return 'BIG';
        if (strpos($lower, 'ipa') !== false || strpos($lower, 'fisika') !== false || strpos($lower, 'biologi') !== false) return 'IPA';
        if (strpos($lower, 'ips') !== false || strpos($lower, 'geografi') !== false || strpos($lower, 'sosiologi') !== false) return 'IPS';
        if (strpos($lower, 'pkn') !== false || strpos($lower, 'pancasila') !== false) return 'PKN';
        if (strpos($lower, 'seni') !== false) return 'SBD';
        if (strpos($lower, 'penjas') !== false || strpos($lower, 'pjok') !== false || strpos($lower, 'olahraga') !== false) return 'PJOK';
        if (strpos($lower, 'informatika') !== false || strpos($lower, 'tik') !== false) return 'INF';

        $words = preg_split('/[\s\-_&,]+/', preg_replace('/[^a-zA-Z0-9\s]/', '', $clean));
        $initials = '';
        foreach ($words as $w) if ($w !== '') $initials .= strtoupper($w[0]);
        if (strlen($initials) >= 2) return substr($initials, 0, 4);
        return strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $clean), 0, 3) ?: 'MPL');
    }
}

if (!function_exists('ilmuna_generate_kmp_code')) {
    function ilmuna_generate_kmp_code($idKmp, $namaMapel) {
        return ilmuna_get_mapel_prefix($namaMapel) . '-' . (int)$idKmp;
    }
}

/* ==============================================================================
 * ENGINE: HYBRID ZIPARCHIVE & PURE-PHP ZIP ADAPTER
 * ============================================================================== */
if (!class_exists('IlmunaZipArchive')) {
class IlmunaZipArchive {
    public const CREATE = 1;
    public const OVERWRITE = 8;

    private $nativeZip = null;
    private $filePath = '';
    private $files = [];
    private $zipData = null;

    public function __construct() {
        if (class_exists('ZipArchive')) $this->nativeZip = new ZipArchive();
    }

    public function open($filename, $flags = 0) {
        $this->filePath = $filename;
        if ($this->nativeZip) {
            $res = $this->nativeZip->open($filename, $flags);
            if ($res === true) return true;
        }
        if ($flags & (self::CREATE | self::OVERWRITE)) {
            $this->files = [];
            return true;
        }
        if (!file_exists($filename)) return false;
        $this->zipData = @file_get_contents($filename);
        return ($this->zipData !== false);
    }

    public function addFromString($localname, $contents) {
        if ($this->nativeZip) return $this->nativeZip->addFromString($localname, $contents);
        $this->files[$localname] = $contents;
        return true;
    }

    public function getFromName($localname) {
        if ($this->nativeZip) {
            $content = $this->nativeZip->getFromName($localname);
            if ($content !== false) return $content;
        }
        if ($this->zipData === null && file_exists($this->filePath)) {
            $this->zipData = @file_get_contents($this->filePath);
        }
        if ($this->zipData) return self::extractEntryFromPureZip($this->zipData, $localname);
        return false;
    }

    public function close() {
        if ($this->nativeZip) {
            $closed = $this->nativeZip->close();
            if ($closed) return true;
        }
        if (!empty($this->files)) {
            $binary = self::buildPureZipData($this->files);
            $bytes = @file_put_contents($this->filePath, $binary);
            return ($bytes !== false && $bytes > 0);
        }
        return true;
    }

    public function getStatusString() {
        if ($this->nativeZip && method_exists($this->nativeZip, 'getStatusString')) {
            return $this->nativeZip->getStatusString();
        }
        return 'OK';
    }

    private static function buildPureZipData($files) {
        $time = time();
        $dostime = ((date('Y', $time) - 1980) << 25)
                 | (date('m', $time) << 21) | (date('d', $time) << 16)
                 | (date('H', $time) << 11) | (date('i', $time) << 5) | (date('s', $time) >> 1);

        $body = ''; $cd = ''; $count = 0;
        foreach ($files as $name => $data) {
            $name = str_replace('\\', '/', $name);
            $uncLen = strlen($data); $crc = crc32($data);
            if (function_exists('gzdeflate')) {
                $cData = gzdeflate($data); $cLen = strlen($cData); $method = 8;
            } else {
                $cData = $data; $cLen = $uncLen; $method = 0;
            }
            $offset = strlen($body);
            $body .= "\x50\x4b\x03\x04" . pack('v',20) . pack('v',0) . pack('v',$method)
                . pack('V',$dostime) . pack('V',$crc) . pack('V',$cLen) . pack('V',$uncLen)
                . pack('v',strlen($name)) . pack('v',0) . $name . $cData;
            $cd .= "\x50\x4b\x01\x02" . pack('v',0x0314) . pack('v',20) . pack('v',0)
                . pack('v',$method) . pack('V',$dostime) . pack('V',$crc) . pack('V',$cLen)
                . pack('V',$uncLen) . pack('v',strlen($name)) . pack('v',0) . pack('v',0)
                . pack('v',0) . pack('v',0) . pack('V',32) . pack('V',$offset) . $name;
            $count++;
        }
        $cdOffset = strlen($body); $cdLen = strlen($cd);
        $eocd = "\x50\x4b\x05\x06" . pack('v',0) . pack('v',0) . pack('v',$count)
            . pack('v',$count) . pack('V',$cdLen) . pack('V',$cdOffset) . pack('v',0);
        return $body . $cd . $eocd;
    }

    private static function extractEntryFromPureZip($zipData, $targetName) {
        $targetName = str_replace('\\', '/', $targetName);
        $eocdPos = strrpos($zipData, "\x50\x4b\x05\x06");
        if ($eocdPos === false) return false;
        $eocd = substr($zipData, $eocdPos);
        if (strlen($eocd) < 22) return false;
        $count = unpack('v', substr($eocd, 10, 2))[1];
        $cdOffset = unpack('V', substr($eocd, 16, 4))[1];
        $pos = $cdOffset;
        for ($i = 0; $i < $count; $i++) {
            if (substr($zipData, $pos, 4) !== "\x50\x4b\x01\x02") break;
            $method = unpack('v', substr($zipData, $pos + 10, 2))[1];
            $cSize = unpack('V', substr($zipData, $pos + 20, 4))[1];
            $uSize = unpack('V', substr($zipData, $pos + 24, 4))[1];
            $fnLen = unpack('v', substr($zipData, $pos + 28, 2))[1];
            $extraLen = unpack('v', substr($zipData, $pos + 30, 2))[1];
            $commLen = unpack('v', substr($zipData, $pos + 32, 2))[1];
            $localOffset = unpack('V', substr($zipData, $pos + 42, 4))[1];
            $fn = substr($zipData, $pos + 46, $fnLen);
            if (str_replace('\\', '/', $fn) === $targetName) {
                $lh = substr($zipData, $localOffset, 30);
                $lhFnLen = unpack('v', substr($lh, 26, 2))[1];
                $lhExtraLen = unpack('v', substr($lh, 28, 2))[1];
                $dataStart = $localOffset + 30 + $lhFnLen + $lhExtraLen;
                $raw = substr($zipData, $dataStart, $cSize);
                if ($method === 8 && function_exists('gzinflate')) return @gzinflate($raw);
                return $raw;
            }
            $pos += 46 + $fnLen + $extraLen + $commLen;
        }
        return false;
    }
}
}

if (!function_exists('ilmuna_create_temp_xlsx')) {
    function ilmuna_create_temp_xlsx($prefix = 'ilmuna_') {
        $randomSuffix = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.xlsx';
        $uploadsDir = __DIR__ . '/../../uploads';
        if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0777, true);
        if (is_dir($uploadsDir) && is_writable($uploadsDir)) {
            $path = $uploadsDir . '/' . $prefix . $randomSuffix;
            if (file_exists($path)) @unlink($path);
            return $path;
        }
        $laragonTmp = 'C:/laragon/tmp';
        if (is_dir($laragonTmp) && is_writable($laragonTmp)) {
            $path = $laragonTmp . '/' . $prefix . $randomSuffix;
            if (file_exists($path)) @unlink($path);
            return $path;
        }
        $sysTmp = sys_get_temp_dir();
        $path = $sysTmp . DIRECTORY_SEPARATOR . $prefix . $randomSuffix;
        if (file_exists($path)) @unlink($path);
        return $path;
    }
}

if (!function_exists('ilmuna_get_xlsx_theme_styles')) {
    function ilmuna_get_xlsx_theme_styles() {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="9">'
            .'<font><sz val="10"/><name val="Segoe UI"/></font>'
            .'<font><b/><sz val="15"/><color rgb="FF1D3B5E"/><name val="Segoe UI"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FF0D7A87"/><name val="Segoe UI"/></font>'
            .'<font><i/><sz val="9.5"/><color rgb="FF64748B"/><name val="Segoe UI"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>'
            .'<font><sz val="10"/><color rgb="FF1E293B"/><name val="Segoe UI"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FF1D3B5E"/><name val="Segoe UI"/></font>'
            .'<font><sz val="9.5"/><color rgb="FF64748B"/><name val="Segoe UI"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FF0F172A"/><name val="Segoe UI"/></font>'
            .'</fonts>'
            .'<fills count="5">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1D3B5E"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="4">'
            .'<border><left/><right/><top/><bottom/></border>'
            .'<border><left style="thin"><color rgb="FFCBD5E1"/></left><right style="thin"><color rgb="FFCBD5E1"/></right><top style="thin"><color rgb="FFCBD5E1"/></top><bottom style="thin"><color rgb="FFCBD5E1"/></bottom></border>'
            .'<border><left style="thin"><color rgb="FF334155"/></left><right style="thin"><color rgb="FF334155"/></right><top style="thin"><color rgb="FF334155"/></top><bottom style="medium"><color rgb="FF0D7A87"/></bottom></border>'
            .'<border><left style="thin"><color rgb="FF94A3B8"/></left><right style="thin"><color rgb="FF94A3B8"/></right><top style="thin"><color rgb="FF94A3B8"/></top><bottom style="double"><color rgb="FF1D3B5E"/></bottom></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="14">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0"><alignment vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="2" borderId="2" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="6" fillId="0" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="5" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="6" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="8" fillId="4" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="8" fillId="4" borderId="3" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
            .'</cellXfs>'
            .'</styleSheet>';
    }
}

/* ==============================================================================
 * QUERY: AMBIL DATA TOKEN ABSENSI (dengan filter)
 * ============================================================================== */
function ilmuna_query_tokens($pdo, $filter = []) {
    $kmpId    = (int)($filter['kmp_id'] ?? 0);
    $kelasId  = (int)($filter['kelas_id'] ?? 0);
    $idsParam = trim($filter['ids'] ?? '');
    $search   = trim($filter['search'] ?? '');
    $status   = trim($filter['status'] ?? '');

    $where = '1=1';
    $params = [];

    if ($idsParam !== '') {
        $idList = array_filter(array_map('intval', explode(',', $idsParam)));
        if (!empty($idList)) $where .= " AND kmp.id IN (" . implode(',', $idList) . ")";
    } elseif ($kmpId > 0) {
        $where .= " AND kmp.id = ?"; $params[] = $kmpId;
    } elseif ($kelasId > 0) {
        $where .= " AND kmp.id_kelas = ?"; $params[] = $kelasId;
    }

    if ($search !== '') {
        $where .= " AND (s.nama_lengkap LIKE ? OR s.nis LIKE ? OR s.nisn LIKE ? OR pr.id_presensi LIKE ?)";
        $like = "%{$search}%";
        $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
    }

    $sql = "
        SELECT
            kmp.id AS id_kmp,
            kmp.id_kelas,
            kmp.id_mata_pelajaran,
            k.nama_kelas, k.tahun_pelajaran,
            mp.nama_mata_pelajaran, mp.semester, mp.kode_mapel,
            COALESCE(g.nama_guru, 'Belum Ditentukan') AS nama_guru,
            COALESCE(g.nip, '-') AS nip,
            gmp.kode_relasi,
            s.id AS siswa_id, s.nis, s.nisn, s.nama_lengkap,
            pr.id AS pr_id, pr.id_presensi, pr.status,
            pr.tanggal_created, pr.expired_at
        FROM kelas_mata_pelajaran kmp
        JOIN kelas k ON kmp.id_kelas = k.id
        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g ON gmp.id_guru = g.id
        JOIN siswa s ON s.id_kelas = k.id
        LEFT JOIN presensi pr ON pr.id_siswa = s.id AND pr.id_kelas_mata_pelajaran = kmp.id
        WHERE {$where}
        ORDER BY k.nama_kelas ASC, mp.nama_mata_pelajaran ASC, s.nama_lengkap ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter status di PHP
    if ($status !== '') {
        $now = time();
        $rows = array_filter($rows, function ($r) use ($status, $now) {
            $displayStatus = 'Belum Ada';
            if (!empty($r['pr_id'])) {
                if ($r['status'] !== 'aktif') $displayStatus = 'Nonaktif';
                elseif (!empty($r['expired_at']) && strtotime($r['expired_at']) < $now) $displayStatus = 'Kedaluwarsa';
                else $displayStatus = 'Aktif';
            }
            return strtolower($displayStatus) === strtolower($status);
        });
        $rows = array_values($rows);
    }
    return $rows;
}

/* ==============================================================================
 * HELPERS: BUILD SHARED XML
 * ============================================================================== */

if (!function_exists('ilmuna_build_common_xlsx_parts')) {
    function ilmuna_build_common_xlsx_parts($sheet1Name, $sheet2Name) {
        return [
            '[Content_Types].xml' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',

            '_rels/.rels' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',

            'xl/workbook.xml' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets>'
                .'<sheet name="'.htmlspecialchars($sheet1Name, ENT_XML1|ENT_QUOTES, 'UTF-8').'" sheetId="1" r:id="rId1"/>'
                .'<sheet name="'.htmlspecialchars($sheet2Name, ENT_XML1|ENT_QUOTES, 'UTF-8').'" sheetId="2" r:id="rId2"/>'
                .'</sheets></workbook>',

            'xl/_rels/workbook.xml.rels' =>
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
                .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                .'<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
        ];
    }
}

if (!function_exists('ilmuna_build_shared_strings_xml')) {
    function ilmuna_build_shared_strings_xml($strings) {
        $ssXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($strings).'" uniqueCount="'.count($strings).'">';
        foreach ($strings as $s) {
            $ssXml .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1|ENT_QUOTES, 'UTF-8').'</t></si>';
        }
        $ssXml .= '</sst>';
        return $ssXml;
    }
}

/* ==============================================================================
 * SHEET 2: REFERENSI KELAS-MAPEL-GURU (KMP)
 * ============================================================================== */
function ilmuna_build_kmp_reference_sheet2_xml(&$strings, &$map, $addStr, $kmpList) {
    $rowsXml = '';

    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('DAFTAR REFERENSI KELAS × MATA PELAJARAN × GURU — ILMUNA LEARN').'</v></c></row>';

    $petunjuk = 'Petunjuk: Setiap baris mewakili kombinasi Rombongan Belajar + Mata Pelajaran + Guru Pengampu. Kolom KODE menampilkan identitas unik KMP (format PREFIX-ID) yang dapat dipakai sebagai referensi internal.';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr($petunjuk).'</v></c></row>';
    $rowsXml .= '<row r="3" ht="10" customHeight="1"></row>';

    $headers = [
        ['col'=>'A','title'=>'KODE KMP','style'=>4],
        ['col'=>'B','title'=>'KELAS / ROMBEL','style'=>5],
        ['col'=>'C','title'=>'MATA PELAJARAN','style'=>5],
        ['col'=>'D','title'=>'GURU PENGAMPU','style'=>5],
        ['col'=>'E','title'=>'NIP','style'=>4],
        ['col'=>'F','title'=>'SEMESTER & TAHUN','style'=>4],
        ['col'=>'G','title'=>'DESKRIPSI PENUGASAN','style'=>5],
    ];
    $hCells = '';
    foreach ($headers as $h) {
        $hCells .= '<c r="'.$h['col'].'4" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="4" ht="28" customHeight="1">'.$hCells.'</row>';

    $rIdx = 5;
    foreach ($kmpList as $kmp) {
        $isZebra = ($rIdx % 2 === 1);
        $sL = $isZebra ? 9  : 6;
        $sC = $isZebra ? 10 : 7;
        $sB = $isZebra ? 11 : 8;

        $idKmp   = (int)($kmp['id_kmp'] ?? 0);
        $mapel   = (string)($kmp['nama_mata_pelajaran'] ?? '-');
        $kelas   = (string)($kmp['nama_kelas'] ?? '-');
        $guru    = (string)($kmp['nama_guru'] ?? '-');
        $nip     = (string)($kmp['nip'] ?? '-');
        $semTp   = trim(($kmp['semester'] ?? '-') . ' ' . ($kmp['tahun_pelajaran'] ?? ''));
        $kode    = ilmuna_generate_kmp_code($idKmp, $mapel);
        $desc    = 'Kelas ' . $kelas . ' • Mapel ' . $mapel . ' • Guru ' . $guru;

        $cRow = '';
        $cRow .= '<c r="A'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($kode).'</v></c>';
        $cRow .= '<c r="B'.$rIdx.'" s="'.$sB.'" t="s"><v>'.$addStr($kelas).'</v></c>';
        $cRow .= '<c r="C'.$rIdx.'" s="'.$sB.'" t="s"><v>'.$addStr($mapel).'</v></c>';
        $cRow .= '<c r="D'.$rIdx.'" s="'.$sL.'" t="s"><v>'.$addStr($guru).'</v></c>';
        $cRow .= '<c r="E'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($nip).'</v></c>';
        $cRow .= '<c r="F'.$rIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($semTp).'</v></c>';
        $cRow .= '<c r="G'.$rIdx.'" s="'.$sL.'" t="s"><v>'.$addStr($desc).'</v></c>';

        $rowsXml .= '<row r="'.$rIdx.'" ht="23" customHeight="1">'.$cRow.'</row>';
        $rIdx++;
    }

    $sumRow = '<c r="A'.$rIdx.'" s="12" t="s"><v>'.$addStr('TOTAL:').'</v></c>';
    $sumRow .= '<c r="B'.$rIdx.'" s="13"><v>'.count($kmpList).'</v></c>';
    $sumRow .= '<c r="C'.$rIdx.'" s="12" t="s"><v>'.$addStr('Kombinasi KMP Aktif').'</v></c>';
    for ($i=3; $i<7; $i++) {
        $col = chr(65 + $i);
        $sumRow .= '<c r="'.$col.$rIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    }
    $rowsXml .= '<row r="'.$rIdx.'" ht="25" customHeight="1">'.$sumRow.'</row>';

    $colsXml = '<cols>'
        .'<col min="1" max="1" width="18" customWidth="1"/>'
        .'<col min="2" max="2" width="26" customWidth="1"/>'
        .'<col min="3" max="3" width="28" customWidth="1"/>'
        .'<col min="4" max="4" width="30" customWidth="1"/>'
        .'<col min="5" max="5" width="18" customWidth="1"/>'
        .'<col min="6" max="6" width="22" customWidth="1"/>'
        .'<col min="7" max="7" width="55" customWidth="1"/>'
        .'</cols>';

    $sheetXml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A4:G4"/>'
        .'</worksheet>';

    return $sheetXml;
}

/* ==============================================================================
 * BUILD EXPORT: DATA TOKEN + REFERENSI KMP
 * ============================================================================== */
function ilmuna_generate_styled_tokens_export($rows, $metaInfo = []) {
    $tmp = ilmuna_create_temp_xlsx('exp_token_');
    if (!$tmp) throw new Exception('Gagal membuat lokasi berkas sementara.');

    $zip = new IlmunaZipArchive();
    if ($zip->open($tmp, IlmunaZipArchive::CREATE) !== true) {
        throw new Exception('Gagal menginisialisasi arsip Excel.');
    }

    $parts = ilmuna_build_common_xlsx_parts('Data Token Absensi', 'Referensi Kelas & Mapel');
    foreach ($parts as $name => $xml) $zip->addFromString($name, $xml);
    $zip->addFromString('xl/styles.xml', ilmuna_get_xlsx_theme_styles());

    $strings = []; $map = [];
    $addStr = function($s) use (&$strings, &$map) {
        $s = (string)$s;
        if (!isset($map[$s])) { $map[$s] = count($strings); $strings[] = $s; }
        return $map[$s];
    };

    $now = time();
    $rowsXml = '';

    // Header block
    $rowsXml .= '<row r="1" ht="26" customHeight="1"><c r="A1" s="1" t="s"><v>'.$addStr('SISTEM INFORMASI AKADEMIK ILMUNA LEARN').'</v></c></row>';
    $rowsXml .= '<row r="2" ht="20" customHeight="1"><c r="A2" s="2" t="s"><v>'.$addStr('Laporan Master Data Token Absensi Siswa').'</v></c></row>';

    $filterText = !empty($metaInfo['filter_text']) ? $metaInfo['filter_text'] : 'Semua Kelas & Mapel';
    $metaText = 'Diekspor pada: ' . date('d F Y, H:i') . ' WIB | Filter: ' . $filterText . ' | Total Baris: ' . count($rows);
    $rowsXml .= '<row r="3" ht="18" customHeight="1"><c r="A3" s="3" t="s"><v>'.$addStr($metaText).'</v></c></row>';
    $rowsXml .= '<row r="4" ht="10" customHeight="1"></row>';

    // Table headers
    $headers = [
        ['col'=>'A','title'=>'NO','style'=>4],
        ['col'=>'B','title'=>'NISN','style'=>4],
        ['col'=>'C','title'=>'NIS','style'=>4],
        ['col'=>'D','title'=>'NAMA SISWA','style'=>5],
        ['col'=>'E','title'=>'KELAS','style'=>4],
        ['col'=>'F','title'=>'MATA PELAJARAN','style'=>5],
        ['col'=>'G','title'=>'GURU PENGAMPU','style'=>5],
        ['col'=>'H','title'=>'TOKEN ABSENSI','style'=>4],
        ['col'=>'I','title'=>'STATUS','style'=>4],
        ['col'=>'J','title'=>'DIBUAT','style'=>4],
        ['col'=>'K','title'=>'BERLAKU SAMPAI','style'=>4],
    ];
    $hCells = '';
    foreach ($headers as $h) {
        $hCells .= '<c r="'.$h['col'].'5" s="'.$h['style'].'" t="s"><v>'.$addStr($h['title']).'</v></c>';
    }
    $rowsXml .= '<row r="5" ht="28" customHeight="1">'.$hCells.'</row>';

    // Data rows
    $rowIdx = 6;
    $kmpMap = [];
    $statAktif = 0; $statNonaktif = 0; $statExpired = 0; $statMissing = 0;

    foreach ($rows as $r) {
        $isZebra = ($rowIdx % 2 === 1);
        $sL = $isZebra ? 9  : 6;
        $sC = $isZebra ? 10 : 7;
        $sB = $isZebra ? 11 : 8;

        // Status computation
        if (empty($r['pr_id'])) {
            $displayStatus = 'Belum Ada'; $statMissing++;
        } elseif ($r['status'] !== 'aktif') {
            $displayStatus = 'Nonaktif'; $statNonaktif++;
        } elseif (!empty($r['expired_at']) && strtotime($r['expired_at']) < $now) {
            $displayStatus = 'Kedaluwarsa'; $statExpired++;
        } else {
            $displayStatus = 'Aktif'; $statAktif++;
        }

        $token = (string)($r['id_presensi'] ?? '');
        $tokenDisplay = $token !== '' ? $token : '—';

        $tglCreated = !empty($r['tanggal_created']) ? date('d M Y H:i', strtotime($r['tanggal_created'])) : '—';
        $tglExpired = !empty($r['expired_at']) ? date('d M Y H:i', strtotime($r['expired_at'])) : '—';

        $cRow = '';
        $cRow .= '<c r="A'.$rowIdx.'" s="'.$sC.'"><v>'.($rowIdx - 5).'</v></c>';
        $cRow .= '<c r="B'.$rowIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($r['nisn'] ?: '-').'</v></c>';
        $cRow .= '<c r="C'.$rowIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($r['nis'] ?: '-').'</v></c>';
        $cRow .= '<c r="D'.$rowIdx.'" s="'.$sB.'" t="s"><v>'.$addStr($r['nama_lengkap']).'</v></c>';
        $cRow .= '<c r="E'.$rowIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($r['nama_kelas']).'</v></c>';
        $cRow .= '<c r="F'.$rowIdx.'" s="'.$sL.'" t="s"><v>'.$addStr($r['nama_mata_pelajaran']).'</v></c>';
        $cRow .= '<c r="G'.$rowIdx.'" s="'.$sL.'" t="s"><v>'.$addStr($r['nama_guru']).'</v></c>';
        $cRow .= '<c r="H'.$rowIdx.'" s="'.$sB.'" t="s"><v>'.$addStr($tokenDisplay).'</v></c>';
        $cRow .= '<c r="I'.$rowIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($displayStatus).'</v></c>';
        $cRow .= '<c r="J'.$rowIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($tglCreated).'</v></c>';
        $cRow .= '<c r="K'.$rowIdx.'" s="'.$sC.'" t="s"><v>'.$addStr($tglExpired).'</v></c>';

        $rowsXml .= '<row r="'.$rowIdx.'" ht="23" customHeight="1">'.$cRow.'</row>';

        // Kumpulkan KMP unik
        $kmpId = (int)$r['id_kmp'];
        if (!isset($kmpMap[$kmpId])) {
            $kmpMap[$kmpId] = [
                'id_kmp' => $kmpId,
                'nama_kelas' => $r['nama_kelas'],
                'nama_mata_pelajaran' => $r['nama_mata_pelajaran'],
                'nama_guru' => $r['nama_guru'],
                'nip' => $r['nip'],
                'semester' => $r['semester'],
                'tahun_pelajaran' => $r['tahun_pelajaran'],
            ];
        }
        $rowIdx++;
    }

    // Summary footer
    $sumRow = '<c r="A'.$rowIdx.'" s="12" t="s"><v>'.$addStr('TOTAL:').'</v></c>';
    $sumRow .= '<c r="B'.$rowIdx.'" s="13"><v>'.count($rows).'</v></c>';
    $sumRow .= '<c r="C'.$rowIdx.'" s="12" t="s"><v>'.$addStr('Baris Data').'</v></c>';
    $sumRow .= '<c r="D'.$rowIdx.'" s="13" t="s"><v>'.$addStr('Aktif: '.$statAktif).'</v></c>';
    $sumRow .= '<c r="E'.$rowIdx.'" s="13" t="s"><v>'.$addStr('Nonaktif: '.$statNonaktif).'</v></c>';
    $sumRow .= '<c r="F'.$rowIdx.'" s="13" t="s"><v>'.$addStr('Kedaluwarsa: '.$statExpired).'</v></c>';
    $sumRow .= '<c r="G'.$rowIdx.'" s="13" t="s"><v>'.$addStr('Belum Ada: '.$statMissing).'</v></c>';
    for ($i=7; $i<11; $i++) {
        $col = chr(65 + $i);
        $sumRow .= '<c r="'.$col.$rowIdx.'" s="13" t="s"><v>'.$addStr('-').'</v></c>';
    }
    $rowsXml .= '<row r="'.$rowIdx.'" ht="25" customHeight="1">'.$sumRow.'</row>';

    $colsXml = '<cols>'
        .'<col min="1" max="1" width="7" customWidth="1"/>'
        .'<col min="2" max="2" width="16" customWidth="1"/>'
        .'<col min="3" max="3" width="16" customWidth="1"/>'
        .'<col min="4" max="4" width="32" customWidth="1"/>'
        .'<col min="5" max="5" width="18" customWidth="1"/>'
        .'<col min="6" max="6" width="26" customWidth="1"/>'
        .'<col min="7" max="7" width="28" customWidth="1"/>'
        .'<col min="8" max="8" width="34" customWidth="1"/>'
        .'<col min="9" max="9" width="14" customWidth="1"/>'
        .'<col min="10" max="10" width="18" customWidth="1"/>'
        .'<col min="11" max="11" width="18" customWidth="1"/>'
        .'</cols>';

    $sheet1Xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="5" topLeftCell="A6" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        .$colsXml
        .'<sheetData>'.$rowsXml.'</sheetData>'
        .'<autoFilter ref="A5:K5"/>'
        .'</worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1Xml);

    // Sheet 2: Reference
    $kmpList = array_values($kmpMap);
    usort($kmpList, function($a, $b) {
        return strcmp($a['nama_kelas'].$a['nama_mata_pelajaran'], $b['nama_kelas'].$b['nama_mata_pelajaran']);
    });
    $sheet2Xml = ilmuna_build_kmp_reference_sheet2_xml($strings, $map, $addStr, $kmpList);
    $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2Xml);

    $zip->addFromString('xl/sharedStrings.xml', ilmuna_build_shared_strings_xml($strings));

    if (!$zip->close()) {
        $status = method_exists($zip, 'getStatusString') ? $zip->getStatusString() : 'unknown';
        @unlink($tmp);
        throw new Exception('Gagal menyimpan arsip Excel: ' . $status);
    }
    if (!file_exists($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        throw new Exception('Berkas Excel hasil ekspor kosong.');
    }
    return $tmp;
}

/* ==============================================================================
 * ROUTER: EXPORT EXCEL
 * ============================================================================== */
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'export_excel') {
    try {
        $filter = [
            'kmp_id'    => (int)($_GET['kmp_id'] ?? 0),
            'kelas_id'  => (int)($_GET['kelas_id'] ?? 0),
            'ids'       => trim($_GET['ids'] ?? ''),
            'search'    => trim($_GET['search'] ?? ''),
            'status'    => trim($_GET['status'] ?? ''),
        ];

        $rows = ilmuna_query_tokens($pdo, $filter);
        if (empty($rows)) {
            throw new Exception('Tidak ada data token yang cocok dengan filter yang dipilih.');
        }

        // Label filter
        $filterParts = [];
        $inf = null;
        if ($filter['kmp_id'] > 0) {
            $info = $pdo->prepare("
                SELECT k.nama_kelas, mp.nama_mata_pelajaran
                FROM kelas_mata_pelajaran kmp
                JOIN kelas k ON kmp.id_kelas = k.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id = ? LIMIT 1
            ");
            $info->execute([$filter['kmp_id']]);
            $inf = $info->fetch(PDO::FETCH_ASSOC);
            if ($inf) $filterParts[] = $inf['nama_kelas'] . ' - ' . $inf['nama_mata_pelajaran'];
        } elseif ($filter['kelas_id'] > 0) {
            $filterParts[] = 'Kelas ID #' . $filter['kelas_id'];
        }
        if ($filter['search'] !== '')  $filterParts[] = 'Pencarian: "' . $filter['search'] . '"';
        if ($filter['status'] !== '')  $filterParts[] = 'Status: ' . $filter['status'];

        $filterText = !empty($filterParts) ? implode(' | ', $filterParts) : 'Semua Kelas & Mapel';
        $metaInfo = ['filter_text' => $filterText];

        $tmpFile = ilmuna_generate_styled_tokens_export($rows, $metaInfo);
        if (!$tmpFile || !file_exists($tmpFile)) throw new Exception('Gagal membuat Excel sementara.');

        $baseName = 'token-absensi-ilmuna-' . date('Ymd-His');
        if ($filter['kmp_id'] > 0 && $inf) {
            $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $inf['nama_kelas'].'-'.$inf['nama_mata_pelajaran']);
            $slug = trim($slug, '-');
            $baseName = 'token-absensi-' . $slug . '-' . date('Ymd-His');
        }
        $filename = $baseName . '.xlsx';

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Access-Control-Expose-Headers: Content-Disposition');
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tmpFile));
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    } catch (Throwable $e) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false, 'data'=>['message'=>'Export error: '.$e->getMessage()]]);
        exit;
    }
}

http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success'=>false, 'data'=>['message'=>'Aksi tidak dikenali: '.$action]]);