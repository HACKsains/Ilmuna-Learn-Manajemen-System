<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BACKEND EXCEL HANDLER (CLASSES / ROMBEL)
 * ==============================================================================
 * Sistem handler Excel Data Kelas & Rombel mandiri mengadopsi standar subjects:
 * 1. Download Template Import Excel (.xlsx) dengan tema Lumina Gold
 * 2. Export Master Data Kelas (.xlsx) (Seluruhnya / Filter / ID Terpilih)
 * 3. Import Data Kelas dari Excel (.xlsx) dengan validasi & anti-duplikasi
 * 
 * Standar Output: OpenXML Spreadsheet (.xlsx) Native ZipArchive / IlmunaZipArchive
 * Dukungan Download: JavaScript Blob Method (Expose Content-Disposition Header)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';

if (php_sapi_name() !== 'cli') {
    requireRole('admin');
}

$pdo = getDBConnection();

/* ==============================================================================
 * 1. OPENXML XLSX UTILITIES & THEME ENGINE
 * ============================================================================== */

if (!function_exists('ilmuna_xml_esc')) {
    function ilmuna_xml_esc($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

if (!function_exists('ilmuna_xlsx_col_letter')) {
    function ilmuna_xlsx_col_letter($idx) {
        $letter = '';
        $idx = (int)$idx + 1;
        while ($idx > 0) {
            $mod = ($idx - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $idx = intdiv($idx - $mod, 26);
        }
        return $letter;
    }
}

if (!function_exists('ilmuna_generate_masked_kelas_code')) {
    function ilmuna_generate_masked_kelas_code($idKelas, $namaKelas, $tahunPelajaran) {
        $tingkat = 'X';
        if (preg_match('/XII/i', $namaKelas) || preg_match('/\b12\b/', $namaKelas)) {
            $tingkat = 'XII';
        } elseif (preg_match('/XI/i', $namaKelas) || preg_match('/\b11\b/', $namaKelas)) {
            $tingkat = 'XI';
        } elseif (preg_match('/X/i', $namaKelas) || preg_match('/\b10\b/', $namaKelas)) {
            $tingkat = 'X';
        }
        $th = preg_replace('/[^0-9]/', '', $tahunPelajaran);
        $thShort = strlen($th) >= 4 ? substr($th, 2, 2) : '26';
        $rand2 = mt_rand(10, 99);
        return $tingkat . $thShort . '-ID' . $idKelas . $rand2;
    }
}

/**
 * ==============================================================================
 * ENGINE: HYBRID ZIPARCHIVE & PURE-PHP ZIP ADAPTER (ZERO-FAILSAFE)
 * ==============================================================================
 */
if (!class_exists('IlmunaZipArchive')) {
class IlmunaZipArchive {
    public const CREATE = 1;
    public const OVERWRITE = 8;

    private $nativeZip = null;
    private $filePath = '';
    private $files = [];
    private $zipData = null;

    public function __construct() {
        if (class_exists('ZipArchive')) {
            $this->nativeZip = new ZipArchive();
        }
    }

    public function open($filename, $flags = 0) {
        $this->filePath = $filename;
        if ($this->nativeZip) {
            $res = $this->nativeZip->open($filename, $flags);
            if ($res === true) {
                return true;
            }
        }

        if ($flags & (self::CREATE | self::OVERWRITE)) {
            $this->files = [];
            return true;
        }

        if (!file_exists($filename)) {
            return false;
        }
        $this->zipData = @file_get_contents($filename);
        return ($this->zipData !== false);
    }

    public function addFromString($localname, $contents) {
        if ($this->nativeZip) {
            return $this->nativeZip->addFromString($localname, $contents);
        }
        $this->files[$localname] = $contents;
        return true;
    }

    public function getFromName($localname) {
        if ($this->nativeZip) {
            $content = $this->nativeZip->getFromName($localname);
            if ($content !== false) {
                return $content;
            }
        }

        if ($this->zipData === null && file_exists($this->filePath)) {
            $this->zipData = @file_get_contents($this->filePath);
        }

        if ($this->zipData) {
            return self::extractEntryFromPureZip($this->zipData, $localname);
        }

        return false;
    }

    public function close() {
        if ($this->nativeZip) {
            $closed = $this->nativeZip->close();
            if ($closed) {
                return true;
            }
        }

        if (!empty($this->files)) {
            $binary = self::buildPureZipData($this->files);
            $bytes = @file_put_contents($this->filePath, $binary);
            return ($bytes !== false && $bytes > 0);
        }

        return true;
    }

    private static function buildPureZipData($files) {
        $time = time();
        $dostime = ((date('Y', $time) - 1980) << 25)
                 | (date('m', $time) << 21)
                 | (date('d', $time) << 16)
                 | (date('H', $time) << 11)
                 | (date('i', $time) << 5)
                 | (date('s', $time) >> 1);

        $body = '';
        $cd = '';
        $count = 0;

        foreach ($files as $name => $data) {
            $name = str_replace('\\', '/', $name);
            $uncLen = strlen($data);
            $crc = crc32($data);

            if (function_exists('gzdeflate')) {
                $cData = gzdeflate($data);
                $cLen = strlen($cData);
                $method = 8;
            } else {
                $cData = $data;
                $cLen = $uncLen;
                $method = 0;
            }

            $offset = strlen($body);

            // Local File Header
            $body .= "\x50\x4b\x03\x04"
                . pack('v', 20)
                . pack('v', 0)
                . pack('v', $method)
                . pack('V', $dostime)
                . pack('V', $crc)
                . pack('V', $cLen)
                . pack('V', $uncLen)
                . pack('v', strlen($name))
                . pack('v', 0)
                . $name
                . $cData;

            // Central Directory Record
            $cd .= "\x50\x4b\x01\x02"
                . pack('v', 0x0314)
                . pack('v', 20)
                . pack('v', 0)
                . pack('v', $method)
                . pack('V', $dostime)
                . pack('V', $crc)
                . pack('V', $cLen)
                . pack('V', $uncLen)
                . pack('v', strlen($name))
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('v', 0)
                . pack('V', 0x00000020)
                . pack('V', $offset)
                . $name;

            $count++;
        }

        $cdOffset = strlen($body);
        $cdLen = strlen($cd);

        // End of Central Directory Record
        $eocd = "\x50\x4b\x05\x06"
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', $count)
            . pack('v', $count)
            . pack('V', $cdLen)
            . pack('V', $cdOffset)
            . pack('v', 0);

        return $body . $cd . $eocd;
    }

    private static function extractEntryFromPureZip($zipData, $localname) {
        $localname = str_replace('\\', '/', $localname);
        $eocdPos = strrpos($zipData, "\x50\x4b\x05\x06");
        if ($eocdPos === false) return false;

        $eocd = substr($zipData, $eocdPos);
        $totalEntries = unpack('v', substr($eocd, 10, 2))[1];
        $cdOffset = unpack('V', substr($eocd, 16, 4))[1];

        $pos = $cdOffset;
        for ($i = 0; $i < $totalEntries; $i++) {
            if (substr($zipData, $pos, 4) !== "\x50\x4b\x01\x02") break;

            $method = unpack('v', substr($zipData, $pos + 10, 2))[1];
            $cLen   = unpack('V', substr($zipData, $pos + 20, 4))[1];
            $uncLen = unpack('V', substr($zipData, $pos + 24, 4))[1];
            $fnLen  = unpack('v', substr($zipData, $pos + 28, 2))[1];
            $exLen  = unpack('v', substr($zipData, $pos + 30, 2))[1];
            $commLen= unpack('v', substr($zipData, $pos + 32, 2))[1];
            $offset = unpack('V', substr($zipData, $pos + 42, 4))[1];

            $entryName = substr($zipData, $pos + 46, $fnLen);
            $pos += 46 + $fnLen + $exLen + $commLen;

            if ($entryName === $localname) {
                $lfh = substr($zipData, $offset, 30);
                $lfnLen = unpack('v', substr($lfh, 26, 2))[1];
                $lexLen = unpack('v', substr($lfh, 28, 2))[1];
                $dataPos = $offset + 30 + $lfnLen + $lexLen;
                $compressed = substr($zipData, $dataPos, $cLen);

                if ($method === 8 && function_exists('gzinflate')) {
                    return gzinflate($compressed);
                } elseif ($method === 0) {
                    return $compressed;
                }
                return false;
            }
        }
        return false;
    }
}
}

/**
 * Helper: Simple Reader XLSX (Sama persis seperti subjects-excel)
 */
if (!function_exists('ilmuna_xlsx_read')) {
    function ilmuna_xlsx_read($filePath) {
        $zip = new IlmunaZipArchive();
        if ($zip->open($filePath) !== true) return false;

        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        $strings = [];
        if ($sharedStringsXml) {
            $sxml = @simplexml_load_string($sharedStringsXml);
            if ($sxml) {
                foreach ($sxml->si as $si) {
                    if (isset($si->t)) {
                        $strings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $txt = '';
                        foreach ($si->r as $r) { $txt .= (string)$r->t; }
                        $strings[] = $txt;
                    } else {
                        $strings[] = '';
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if (!$sheetXml) { $zip->close(); return false; }

        $xml = @simplexml_load_string($sheetXml);
        if (!$xml || !isset($xml->sheetData)) { $zip->close(); return false; }

        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $rowCells = [];
            foreach ($row->c as $c) {
                $ref = (string)$c['r'];
                preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
                $colLetters = $m[1] ?? 'A';
                $colIdx = 0;
                for ($ci = 0; $ci < strlen($colLetters); $ci++) {
                    $colIdx = $colIdx * 26 + (ord($colLetters[$ci]) - 64);
                }
                $colIdx -= 1;

                $val = '';
                $type = (string)$c['t'];
                if ($type === 's') {
                    $sIdx = (int)$c->v;
                    $val = $strings[$sIdx] ?? '';
                } elseif (isset($c->v)) {
                    $val = (string)$c->v;
                }
                $rowCells[$colIdx] = trim($val);
            }
            if (!empty($rowCells)) {
                $maxKey = max(array_keys($rowCells));
                $filled = [];
                for ($k = 0; $k <= $maxKey; $k++) {
                    $filled[$k] = $rowCells[$k] ?? '';
                }
                $rows[] = $filled;
            }
        }

        $zip->close();
        return $rows;
    }
}

/**
 * Generator File XLSX Terstruktur Bertema Lumina Gold (Sama persis seperti subjects-excel)
 */
function ilmuna_generate_classes_excel($sheetsData) {
    $tempFile = tempnam(sys_get_temp_dir(), 'ilmuna_cls_');
    $zip = new IlmunaZipArchive();
    $zip->open($tempFile, IlmunaZipArchive::CREATE | IlmunaZipArchive::OVERWRITE);

    // [Content_Types].xml
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

    foreach ($sheetsData as $idx => $s) {
        $sheetNum = $idx + 1;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $sheetNum . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $ct .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $ct);

    // _rels/.rels
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    $zip->addFromString('_rels/.rels', $rels);

    // xl/_rels/workbook.xml.rels
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    foreach ($sheetsData as $idx => $s) {
        $sheetNum = $idx + 1;
        $wbRels .= '<Relationship Id="rIdSheet' . $sheetNum . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetNum . '.xml"/>';
    }
    $wbRels .= '</Relationships>';
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

    // xl/workbook.xml
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>';
    foreach ($sheetsData as $idx => $s) {
        $sheetNum = $idx + 1;
        $wb .= '<sheet name="' . ilmuna_xml_esc($s['title']) . '" sheetId="' . $sheetNum . '" r:id="rIdSheet' . $sheetNum . '"/>';
    }
    $wb .= '</sheets></workbook>';
    $zip->addFromString('xl/workbook.xml', $wb);

    // xl/styles.xml (Lumina Gold Theme: Navy #1D3B5E, Teal #0D7A87, Gold #F59E0B)
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4">'
        . '<font><sz val="10"/><name val="Segoe UI"/></font>'
        . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Segoe UI"/></font>' // 1: Header bold white
        . '<font><b/><sz val="14"/><color rgb="FF1D3B5E"/><name val="Segoe UI"/></font>' // 2: Title bold navy
        . '<font><sz val="9.5"/><i/><color rgb="FF64748B"/><name val="Segoe UI"/></font>'  // 3: Subtitle italic gray
        . '</fonts>'
        . '<fills count="5">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1D3B5E"/></patternFill></fill>' // 2: Navy Header
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF0D7A87"/></patternFill></fill>' // 3: Teal Accent
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/></patternFill></fill>' // 4: Soft Gray Alt
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/></border>'
        . '<border><left style="thin"><color rgb="FFE2E8F0"/></left><right style="thin"><color rgb="FFE2E8F0"/></right><top style="thin"><color rgb="FFE2E8F0"/></top><bottom style="thin"><color rgb="FFE2E8F0"/></bottom></border>'
        . '</borders>'
        . '<cellXfs count="5">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' // 0: Normal
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 1: Header
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"><alignment vertical="center"/></xf>' // 2: Data Left
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>' // 3: Data Center
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" applyFont="1"><alignment vertical="center"/></xf>' // 4: Title Banner
        . '</cellXfs>'
        . '</styleSheet>';
    $zip->addFromString('xl/styles.xml', $styles);

    // Worksheets
    foreach ($sheetsData as $idx => $s) {
        $sheetNum = $idx + 1;
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView tabSelected="' . ($idx === 0 ? '1' : '0') . '" workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="20"/>';

        // Column widths
        if (!empty($s['widths'])) {
            $sheetXml .= '<cols>';
            foreach ($s['widths'] as $cIdx => $w) {
                $colNumber = $cIdx + 1;
                $sheetXml .= '<col min="' . $colNumber . '" max="' . $colNumber . '" width="' . $w . '" customWidth="1"/>';
            }
            $sheetXml .= '</cols>';
        }

        $sheetXml .= '<sheetData>';
        $rNum = 1;

        // Title row
        if (!empty($s['title_text'])) {
            $sheetXml .= '<row r="' . $rNum . '" ht="30" customHeight="1">'
                . '<c r="A' . $rNum . '" s="4" t="inlineStr"><is><t>' . ilmuna_xml_esc($s['title_text']) . '</t></is></c>'
                . '</row>';
            $rNum++;
            // Subtitle row
            if (!empty($s['subtitle_text'])) {
                $sheetXml .= '<row r="' . $rNum . '" ht="20" customHeight="1">'
                    . '<c r="A' . $rNum . '" s="0" t="inlineStr"><is><t>' . ilmuna_xml_esc($s['subtitle_text']) . '</t></is></c>'
                    . '</row>';
                $rNum++;
            }
            $rNum++; // spacer row without empty element
        }

        // Header Row
        if (!empty($s['headers'])) {
            $sheetXml .= '<row r="' . $rNum . '" ht="26" customHeight="1">';
            foreach ($s['headers'] as $cIdx => $hText) {
                $colLetter = ilmuna_xlsx_col_letter($cIdx);
                $cellRef = $colLetter . $rNum;
                $sheetXml .= '<c r="' . $cellRef . '" s="1" t="inlineStr"><is><t>' . ilmuna_xml_esc($hText) . '</t></is></c>';
            }
            $sheetXml .= '</row>';
            $rNum++;
        }

        // Data Rows
        if (!empty($s['rows'])) {
            foreach ($s['rows'] as $rowItem) {
                $sheetXml .= '<row r="' . $rNum . '" ht="22" customHeight="1">';
                foreach ($rowItem as $cIdx => $val) {
                    $colLetter = ilmuna_xlsx_col_letter($cIdx);
                    $cellRef = $colLetter . $rNum;
                    $styleId = (is_numeric($val) || $cIdx === 0 || $cIdx === 2 || $cIdx === 3 || $cIdx === 4 || $cIdx === 5) ? '3' : '2';
                    $sheetXml .= '<c r="' . $cellRef . '" s="' . $styleId . '" t="inlineStr"><is><t>' . ilmuna_xml_esc((string)$val) . '</t></is></c>';
                }
                $sheetXml .= '</row>';
                $rNum++;
            }
        }

        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . $sheetNum . '.xml', $sheetXml);
    }

    $zip->close();
    return $tempFile;
}

/* ==============================================================================
 * HELPER: KATALOG & PARSER RELASI MATA PELAJARAN KELAS
 * ============================================================================== */
if (!function_exists('ilmuna_get_mapel_catalog')) {
    function ilmuna_get_mapel_catalog(PDO $pdo): array {
        $sql = "
            SELECT mp.id, mp.kode_mapel, mp.nama_mata_pelajaran, mp.kktp, mp.semester, mp.tahun_pelajaran,
                   GROUP_CONCAT(DISTINCT g.nama_guru ORDER BY g.nama_guru SEPARATOR ', ') AS guru_pengampu,
                   MIN(gmp.id) AS default_id_guru_mata_pelajaran
            FROM mata_pelajaran mp
            LEFT JOIN guru_mata_pelajaran gmp ON gmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru g ON gmp.id_guru = g.id
            GROUP BY mp.id
            ORDER BY mp.id ASC
        ";
        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        // Pastikan setiap mata pelajaran memiliki kode_mapel resmi
        foreach ($rows as &$item) {
            $id = (int)$item['id'];
            $kode = trim((string)($item['kode_mapel'] ?? ''));
            if ($kode === '' || !preg_match('/^[A-Z0-9]+-[0-9]{2}' . $id . '[0-9]{3}$/', $kode)) {
                if (function_exists('ilmuna_generate_subject_code')) {
                    $kode = ilmuna_generate_subject_code($pdo, $item['nama_mata_pelajaran'], $id);
                } else {
                    $singkat = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $item['nama_mata_pelajaran']), 0, 3) ?: 'MPL');
                    $kode = $singkat . '-' . mt_rand(10, 99) . $id . mt_rand(100, 999);
                }
                $pdo->prepare("UPDATE mata_pelajaran SET kode_mapel = ? WHERE id = ?")->execute([$kode, $id]);
                $item['kode_mapel'] = $kode;
            }
        }
        unset($item);

        return $rows;
    }
}

if (!function_exists('ilmuna_parse_mapel_ids')) {
    function ilmuna_parse_mapel_ids(PDO $pdo, string $inputStr, array $catalog): array {
        $tokens = preg_split('/[,\r\n;|]+/', trim($inputStr));
        $mapByCode = [];
        $mapByName = [];
        $mapById = [];

        foreach ($catalog as $item) {
            $id = (int)$item['id'];
            $mapById[$id] = $id;
            if (!empty($item['kode_mapel'])) {
                $mapByCode[strtoupper(trim($item['kode_mapel']))] = $id;
            }
            $cleanName = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $item['nama_mata_pelajaran'])));
            if ($cleanName !== '') {
                $mapByName[$cleanName] = $id;
            }
        }

        $resolvedIds = [];
        foreach ($tokens as $rawToken) {
            $t = trim($rawToken);
            if ($t === '') continue;

            $uToken = strtoupper($t);
            // 1. Exact match kode_mapel
            if (isset($mapByCode[$uToken])) {
                $resolvedIds[] = $mapByCode[$uToken];
                continue;
            }

            // 2. Regex extract ID dari pola resmi (singkatan)-(2acak)(id)(3acak), misal: PAI-151728 -> id 1
            if (preg_match('/^[A-Z0-9]+-[0-9]{2}(\d+)[0-9]{3}$/', $uToken, $m)) {
                $extractedId = (int)$m[1];
                if (isset($mapById[$extractedId])) {
                    $resolvedIds[] = $extractedId;
                    continue;
                }
            }

            // 3. Integer ID langsung
            if (ctype_digit($t)) {
                $intId = (int)$t;
                if (isset($mapById[$intId])) {
                    $resolvedIds[] = $intId;
                    continue;
                }
            }

            // 4. Clean match nama mata pelajaran
            $cleanT = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $t));
            if ($cleanT !== '' && isset($mapByName[$cleanT])) {
                $resolvedIds[] = $mapByName[$cleanT];
                continue;
            }

            // 5. Substring / partial name match
            foreach ($catalog as $item) {
                if (stripos($item['nama_mata_pelajaran'], $t) !== false || stripos($t, $item['nama_mata_pelajaran']) !== false) {
                    $resolvedIds[] = (int)$item['id'];
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($resolvedIds)));
    }
}

/* ==============================================================================
 * ROUTER AKSI EXCEL DATA KELAS (ROMBEL)
 * ============================================================================== */

$action = $_REQUEST['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';

// 1. DOWNLOAD TEMPLATE EXCEL IMPORT DATA KELAS (2 SHEET DENGAN RELASI MAPEL)
if ($action === 'download_template') {
    $catalog = ilmuna_get_mapel_catalog($pdo);

    // Ambil contoh kode mapel dari database
    $sampleCodes1 = [];
    $sampleCodes2 = [];
    $sampleCodes3 = [];
    foreach ($catalog as $idx => $m) {
        if ($idx < 3) $sampleCodes1[] = $m['kode_mapel'];
        if ($idx >= 1 && $idx < 4) $sampleCodes2[] = $m['kode_mapel'];
        if ($idx >= 2 && $idx < 5) $sampleCodes3[] = $m['kode_mapel'];
    }
    $strSample1 = implode(', ', $sampleCodes1);
    $strSample2 = implode(', ', $sampleCodes2);
    $strSample3 = implode(', ', $sampleCodes3);

    $headers = ['nama_kelas', 'tahun_pelajaran', 'kode_mapel'];
    $sampleRows = [
        ['X PAI-1 (Imam Al-Bukhari)', '2026/2027', $strSample1],
        ['X PAI-2 (Imam Muslim)', '2026/2027', $strSample2],
        ['XI PAI-1 (Imam Asy-Syafi\'i)', '2026/2027', $strSample3],
        ['XI PAI-2 (Imam Abu Hanifah)', '2026/2027', $strSample1],
        ['XII PAI-1 (Imam Malik)', '2026/2027', $strSample2],
        ['XII PAI-2 (Imam Ahmad bin Hambal)', '2026/2027', $strSample3]
    ];

    // Data Sheet 2: Referensi Seluruh Mata Pelajaran
    $refRows = [];
    foreach ($catalog as $c) {
        $refRows[] = [
            $c['kode_mapel'],
            $c['nama_mata_pelajaran'],
            number_format((float)($c['kktp'] ?: 75), 1),
            $c['semester'] ?: 'Ganjil',
            $c['tahun_pelajaran'] ?: '2026/2027',
            $c['guru_pengampu'] ?: 'Belum Terpetakan'
        ];
    }

    $sheets = [
        [
            'title'         => 'Template Kelas',
            'title_text'    => 'FORMAT TEMPLATE IMPORT DATA KELAS & ROMBEL — ILMUNA LEARN',
            'subtitle_text' => 'Petunjuk: Kolom nama_kelas wajib diisi. Kolom kode_mapel dapat diisi satu atau lebih kode mapel resmi (pisahkan dengan koma). Salin kode dari Sheet "Referensi Mapel".',
            'widths'        => [32, 18, 45],
            'headers'       => $headers,
            'rows'          => $sampleRows
        ],
        [
            'title'         => 'Referensi Mapel',
            'title_text'    => 'DAFTAR REFERENSI KODE MATA PELAJARAN — ILMUNA LEARN',
            'subtitle_text' => 'Petunjuk: Salin kode pada kolom "KODE MAPEL" ke Sheet 1 kolom "kode_mapel" (misal: PAI-151728, QHD-462932).',
            'widths'        => [18, 38, 14, 14, 16, 32],
            'headers'       => ['KODE MAPEL', 'NAMA MATA PELAJARAN', 'KKTP / KKM', 'SEMESTER', 'TAHUN AJARAN', 'GURU PENGAMPU'],
            'rows'          => $refRows
        ]
    ];

    $filePath = ilmuna_generate_classes_excel($sheets);
    $filename = 'template-import-kelas-ilmuna.xlsx';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: public');
    header('Access-Control-Expose-Headers: Content-Disposition');

    readfile($filePath);
    @unlink($filePath);
    exit;
}

// 2. EXPORT EXCEL DATA KELAS BESERTA RELASI MAPEL (Mendukung ID Terpilih atau Filter Toolbar)
if ($action === 'export_excel') {
    $catalog = ilmuna_get_mapel_catalog($pdo);

    $ids     = trim($_GET['ids'] ?? '');
    $search  = trim($_GET['search'] ?? '');
    $f_tahun = trim($_GET['f_tahun'] ?? '');

    $where = '1=1';
    $params = [];

    if ($ids !== '') {
        $idArr = array_filter(array_map('intval', explode(',', $ids)));
        if (!empty($idArr)) {
            $inClause = implode(',', $idArr);
            $where .= " AND k.id IN ($inClause)";
        }
    } else {
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= " AND (k.nama_kelas LIKE ? OR k.tahun_pelajaran LIKE ?)";
            $params[] = $like; $params[] = $like;
        }
        if ($f_tahun !== '') {
            $where .= " AND k.tahun_pelajaran = ?";
            $params[] = $f_tahun;
        }
    }

    $sql = "SELECT k.id, k.kode_kelas, k.nama_kelas, k.tahun_pelajaran,
                (SELECT COUNT(*) FROM kelas_mata_pelajaran km WHERE km.id_kelas = k.id) AS total_mapel,
                (SELECT COUNT(*) FROM siswa s WHERE s.id_kelas = k.id) AS total_siswa
            FROM kelas k
            WHERE {$where}
            ORDER BY k.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Siapkan statement relasi mapel per kelas
    $stmtMapel = $pdo->prepare("
        SELECT km.kode_kelas_mapel, mp.kode_mapel, mp.nama_mata_pelajaran, g.nama_guru
        FROM kelas_mata_pelajaran km
        JOIN mata_pelajaran mp ON km.id_mata_pelajaran = mp.id
        LEFT JOIN guru_mata_pelajaran gmp ON km.id_guru_mata_pelajaran = gmp.id
        LEFT JOIN guru g ON gmp.id_guru = g.id
        WHERE km.id_kelas = ?
        ORDER BY mp.id ASC
    ");

    $exportRows = [];
    $no = 1;
    foreach ($rows as $r) {
        // Deteksi Fase / Tingkat
        $tingkat = 'Rombel Umum';
        if (preg_match('/XII/i', $r['nama_kelas']) || preg_match('/\b12\b/', $r['nama_kelas'])) {
            $tingkat = 'Kelas XII (Fase F)';
        } elseif (preg_match('/XI/i', $r['nama_kelas']) || preg_match('/\b11\b/', $r['nama_kelas'])) {
            $tingkat = 'Kelas XI (Fase F)';
        } elseif (preg_match('/X/i', $r['nama_kelas']) || preg_match('/\b10\b/', $r['nama_kelas'])) {
            $tingkat = 'Kelas X (Fase E)';
        }

        // Ambil daftar mapel & guru pengampu kelas ini
        $stmtMapel->execute([$r['id']]);
        $classMapels = $stmtMapel->fetchAll(PDO::FETCH_ASSOC);

        $codesList = [];
        $kmaCodesList = [];
        $detailsList = [];
        foreach ($classMapels as $cm) {
            if (!empty($cm['kode_mapel'])) {
                $codesList[] = $cm['kode_mapel'];
            }
            if (!empty($cm['kode_kelas_mapel'])) {
                $kmaCodesList[] = $cm['kode_kelas_mapel'];
            }
            $guruName = !empty($cm['nama_guru']) ? $cm['nama_guru'] : 'Belum Ditentukan';
            $kmaTag = !empty($cm['kode_kelas_mapel']) ? (' [' . $cm['kode_kelas_mapel'] . ']') : '';
            $detailsList[] = $cm['nama_mata_pelajaran'] . $kmaTag . ' (' . $guruName . ')';
        }

        $strCodes = implode(', ', $codesList);
        $strKmaCodes = implode(', ', $kmaCodesList);
        $strDetails = implode(', ', $detailsList);

        $exportRows[] = [
            $no++,
            $r['kode_kelas'] ?: ('KLS-' . $r['id']),
            $r['nama_kelas'],
            $tingkat,
            $r['tahun_pelajaran'],
            count($classMapels) . ' Mapel',
            (int)$r['total_siswa'] . ' Siswa',
            $strCodes ?: '-',
            $strKmaCodes ?: '-',
            $strDetails ?: '-'
        ];
    }

    // Data Sheet 2: Referensi Seluruh Mata Pelajaran
    $refRows = [];
    foreach ($catalog as $c) {
        $refRows[] = [
            $c['kode_mapel'],
            $c['nama_mata_pelajaran'],
            number_format((float)($c['kktp'] ?: 75), 1),
            $c['semester'] ?: 'Ganjil',
            $c['tahun_pelajaran'] ?: '2026/2027',
            $c['guru_pengampu'] ?: 'Belum Terpetakan'
        ];
    }

    $sheets = [
        [
            'title'         => 'Data Kelas',
            'title_text'    => 'MASTER DATA KELAS & ROMBONGAN BELAJAR — ILMUNA LEARN',
            'subtitle_text' => 'Diekspor pada: ' . date('d/m/Y H:i:s') . ' • Total: ' . count($exportRows) . ' Rombel Kelas',
            'widths'        => [6, 16, 28, 20, 15, 14, 14, 32, 28, 48],
            'headers'       => ['#', 'Kode Kelas', 'Nama Rombel / Kelas', 'Tingkat / Fase', 'Tahun Ajaran', 'Total Mapel', 'Total Siswa', 'Kode Mapel Terhubung', 'Kode Rombel Mapel (KMA)', 'Daftar Mata Pelajaran & Pengampu'],
            'rows'          => $exportRows
        ],
        [
            'title'         => 'Referensi Mapel',
            'title_text'    => 'DAFTAR REFERENSI KODE MATA PELAJARAN — ILMUNA LEARN',
            'subtitle_text' => 'Daftar katalog mata pelajaran aktif yang terhubung dengan rombongan belajar.',
            'widths'        => [18, 38, 14, 14, 16, 32],
            'headers'       => ['KODE MAPEL', 'NAMA MATA PELAJARAN', 'KKTP / KKM', 'SEMESTER', 'TAHUN AJARAN', 'GURU PENGAMPU'],
            'rows'          => $refRows
        ]
    ];

    $filePath = ilmuna_generate_classes_excel($sheets);
    $filename = 'data-kelas-ilmuna-' . date('Ymd_His') . '.xlsx';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: public');
    header('Access-Control-Expose-Headers: Content-Disposition');

    readfile($filePath);
    @unlink($filePath);
    exit;
}

// 3. IMPORT DATA KELAS BESERTA RELASI MAPEL DARI EXCEL (.xlsx)
if ($action === 'import_excel') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_FILES['file']['tmp_name'])) {
        echo json_encode(['success' => false, 'data' => ['message' => 'Berkas Excel belum dipilih.']]);
        exit;
    }

    $file = $_FILES['file']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        echo json_encode(['success' => false, 'data' => ['message' => 'Hanya berkas Excel format .xlsx yang didukung.']]);
        exit;
    }

    $rows = ilmuna_xlsx_read($file);
    if ($rows === false || empty($rows)) {
        echo json_encode(['success' => false, 'data' => ['message' => 'Gagal membaca isi berkas Excel (file rusak atau tidak valid).']]);
        exit;
    }

    // Ambil katalog mata pelajaran aktif
    $catalog = ilmuna_get_mapel_catalog($pdo);
    $catalogMapGmp = [];
    $catalogNameMap = [];
    foreach ($catalog as $item) {
        $catalogMapGmp[(int)$item['id']] = $item['default_id_guru_mata_pelajaran'] ? (int)$item['default_id_guru_mata_pelajaran'] : null;
        $catalogNameMap[(int)$item['id']] = $item['nama_mata_pelajaran'];
    }

    // Temukan baris header
    $headerRowIdx = -1;
    $colNama = false;
    $colTahun = false;
    $colMapel = false;

    foreach ($rows as $idx => $r) {
        $clean = array_map(function($v){ return strtolower(trim((string)$v)); }, $r);
        foreach ($clean as $cIdx => $h) {
            if (in_array($h, ['nama_kelas', 'nama kelas', 'nama rombel', 'rombel', 'kelas'], true)) {
                $colNama = $cIdx;
                $headerRowIdx = $idx;
            }
            if (in_array($h, ['tahun_pelajaran', 'tahun pelajaran', 'tahun ajaran', 'tahun', 'ta'], true)) {
                $colTahun = $cIdx;
            }
            if (in_array($h, ['kode_mapel', 'kode mapel', 'mapel', 'mata_pelajaran', 'mata pelajaran', 'relasi_mapel', 'relasi mapel', 'kode_guru_mapel', 'kode mapel terhubung'], true)) {
                $colMapel = $cIdx;
            }
        }
        if ($colNama !== false) break;
    }

    if ($colNama === false || $headerRowIdx === -1) {
        echo json_encode([
            'success' => false,
            'data' => ['message' => 'Format file tidak sesuai skema. Kolom wajib: nama_kelas (opsional: tahun_pelajaran, kode_mapel).']
        ]);
        exit;
    }

    $log = [];
    $stat = ['ok' => 0, 'skip' => 0, 'error' => 0];

    $stmtCheck = $pdo->prepare("SELECT id FROM kelas WHERE LOWER(TRIM(nama_kelas)) = ? AND tahun_pelajaran = ? LIMIT 1");
    $stmtInsert = $pdo->prepare("INSERT INTO kelas (nama_kelas, tahun_pelajaran) VALUES (?, ?)");
    $stmtUpdate = $pdo->prepare("UPDATE kelas SET nama_kelas = ?, tahun_pelajaran = ? WHERE id = ?");

    // Statement relasi mapel
    $stmtCheckKmp = $pdo->prepare("SELECT id FROM kelas_mata_pelajaran WHERE id_kelas = ? AND id_mata_pelajaran = ? LIMIT 1");
    $stmtInsertKmp = $pdo->prepare("INSERT INTO kelas_mata_pelajaran (id_kelas, id_mata_pelajaran, id_guru_mata_pelajaran) VALUES (?, ?, ?)");
    $stmtUpdateKmp = $pdo->prepare("UPDATE kelas_mata_pelajaran SET id_guru_mata_pelajaran = ? WHERE id_kelas = ? AND id_mata_pelajaran = ?");

    // Ambil default id mapel pertama jika tidak ada mapel diisi sama sekali
    $firstMapelId = null;
    try {
        $firstMapelId = $pdo->query("SELECT id FROM mata_pelajaran LIMIT 1")->fetchColumn();
    } catch (Throwable $eFm) {}

    $dataRows = array_slice($rows, $headerRowIdx + 1);
    foreach ($dataRows as $i => $row) {
        $rowNum = $headerRowIdx + 1 + $i + 1;
        $nama = trim((string)($row[$colNama] ?? ''));
        $tahun = ($colTahun !== false) ? trim((string)($row[$colTahun] ?? '2026/2027')) : '2026/2027';
        if ($tahun === '' || $tahun === '-') $tahun = '2026/2027';

        $rawMapel = ($colMapel !== false) ? trim((string)($row[$colMapel] ?? '')) : '';

        if ($nama === '' || stripos($nama, 'total:') !== false) {
            continue;
        }

        // Cek duplikasi nama_kelas & tahun_pelajaran
        $stmtCheck->execute([strtolower($nama), $tahun]);
        $targetId = $stmtCheck->fetchColumn();
        $isNew = false;

        if ($targetId) {
            $stmtUpdate->execute([$nama, $tahun, $targetId]);
            $targetId = (int)$targetId;
        } else {
            try {
                $stmtInsert->execute([$nama, $tahun]);
                $targetId = (int)$pdo->lastInsertId();
                $isNew = true;

                // Simpan kode_kelas
                try {
                    $newCode = ilmuna_generate_masked_kelas_code($targetId, $nama, $tahun);
                    $pdo->prepare("UPDATE kelas SET kode_kelas = ? WHERE id = ?")->execute([$newCode, $targetId]);
                } catch (Throwable $eCode) {}
            } catch (Throwable $eIns) {
                $log[] = [$rowNum, 'error', "Baris {$rowNum} gagal menambahkan kelas: " . $eIns->getMessage()];
                $stat['error']++;
                continue;
            }
        }

        // Hubungkan mata pelajaran dari kolom kode_mapel
        $linkedMapels = [];
        if ($rawMapel !== '') {
            $resolvedIds = ilmuna_parse_mapel_ids($pdo, $rawMapel, $catalog);
            foreach ($resolvedIds as $idMapel) {
                $idGmp = $catalogMapGmp[$idMapel] ?? null;

                $stmtCheckKmp->execute([$targetId, $idMapel]);
                $existKmp = $stmtCheckKmp->fetchColumn();

                if ($existKmp) {
                    if ($idGmp) {
                        $stmtUpdateKmp->execute([$idGmp, $targetId, $idMapel]);
                    }
                } else {
                    $stmtInsertKmp->execute([$targetId, $idMapel, $idGmp]);
                }

                $linkedMapels[] = $catalogNameMap[$idMapel] ?? "Mapel #$idMapel";
            }
        } elseif ($isNew && $firstMapelId) {
            // Jika kelas baru dan tanpa input mapel, hubungkan ke default mapel pertama
            try {
                $idGmp = $catalogMapGmp[(int)$firstMapelId] ?? null;
                $stmtInsertKmp->execute([$targetId, (int)$firstMapelId, $idGmp]);
                $linkedMapels[] = $catalogNameMap[(int)$firstMapelId] ?? "Mapel Default";
            } catch (Throwable $eRel) {}
        }

        $mapelInfoText = '';
        if (!empty($linkedMapels)) {
            $mapelInfoText = ' dengan ' . count($linkedMapels) . ' mata pelajaran (' . implode(', ', array_slice($linkedMapels, 0, 3)) . (count($linkedMapels) > 3 ? '...' : '') . ')';
        }

        if ($isNew) {
            $log[] = [$rowNum, 'ok', "Rombel baru '{$nama}' [{$tahun}] berhasil ditambahkan{$mapelInfoText}."];
            $stat['ok']++;
        } else {
            $log[] = [$rowNum, 'ok', "Rombel '{$nama}' [{$tahun}] (ID: {$targetId}) berhasil diperbarui{$mapelInfoText}."];
            $stat['ok']++;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'stat'    => $stat,
            'log'     => $log,
            'message' => "Import selesai: {$stat['ok']} berhasil, {$stat['skip']} dilewati, {$stat['error']} gagal."
        ]
    ]);
    exit;
}

if ($action !== '') {
    echo json_encode(['success' => false, 'data' => ['message' => 'Aksi Excel kelas tidak dikenali.']]);
    exit;
}


