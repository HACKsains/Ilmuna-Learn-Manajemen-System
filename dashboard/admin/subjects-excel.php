<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BACKEND EXCEL HANDLER (SUBJECTS / MATA PELAJARAN)
 * ==============================================================================
 * Fitur:
 * 1. Download Template Import Excel (.xlsx) dengan tema Lumina Gold
 * 2. Export Master Data Mata Pelajaran (.xlsx) (Seluruhnya atau Masal Berdasarkan ID Terpilih)
 * 3. Import Data Mata Pelajaran dari Excel (.xlsx) dengan validasi & anti-duplikasi
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
 * HELPER: OPENXML XLSX UTILITIES & THEME ENGINE
 * ============================================================================== */

if (!function_exists('ilmuna_abbreviate_subject')) {
    function ilmuna_abbreviate_subject(string $nama): string {
        $namaClean = trim(preg_replace('/\(.*?\)/', '', $nama));
        $lower = strtolower($namaClean);

        $dict = [
            'pendidikan agama islam' => 'PAI',
            'agama islam'            => 'PAI',
            'pancasila'              => 'PPKN',
            'pendidikan pancasila'   => 'PPKN',
            'ppkn'                   => 'PPKN',
            'pkn'                    => 'PKN',
            'bahasa indonesia'       => 'BIND',
            'b. indonesia'           => 'BIND',
            'bahasa inggris'         => 'BING',
            'b. inggris'             => 'BING',
            'bahasa arab'            => 'BAR',
            'b. arab'                => 'BAR',
            'bahasa jepang'          => 'BJEP',
            'bahasa mandarin'        => 'BMAN',
            'bahasa jerman'          => 'BJER',
            'bahasa perancis'        => 'BPER',
            'bahasa sunda'           => 'BSUN',
            'bahasa jawa'            => 'BJAW',
            'matematika peminatan'   => 'MTKP',
            'matematika'             => 'MTK',
            'al-qur\'an hadits'      => 'QHD',
            'al-qur\'an hadis'       => 'QHD',
            'al-quran hadis'         => 'QHD',
            'qur\'an hadits'         => 'QHD',
            'al-qur\'an'             => 'QUR',
            'tajwid'                 => 'TJW',
            'akidah akhlak'          => 'AAK',
            'akidah'                 => 'AKD',
            'akhlak'                 => 'AKH',
            'fiqih'                  => 'FQH',
            'fikih'                  => 'FQH',
            'ushul fiqih'            => 'UFQ',
            'sejarah kebudayaan islam'=> 'SKI',
            'ski'                    => 'SKI',
            'tarikh'                 => 'TRK',
            'ilmu pengetahuan alam'  => 'IPA',
            'ilmu pengetahuan sosial' => 'IPS',
            'fisika'                 => 'FIS',
            'kimia'                  => 'KIM',
            'biologi'                => 'BIO',
            'ekonomi'                => 'EKO',
            'geografi'               => 'GEO',
            'sosiologi'              => 'SOS',
            'sejarah indonesia'      => 'SJRI',
            'sejarah peminatan'      => 'SJRP',
            'sejarah'                => 'SJR',
            'antropologi'            => 'ANT',
            'informatika'            => 'INF',
            'teknologi informasi'    => 'TIK',
            'seni budaya'            => 'SBD',
            'seni rupa'              => 'SRUP',
            'seni musik'             => 'SMUS',
            'seni tari'              => 'STAR',
            'seni teater'            => 'STEA',
            'pendidikan jasmani'     => 'PJOK',
            'pjok'                   => 'PJOK',
            'penjas'                 => 'PJOK',
            'bimbingan konseling'    => 'BK',
            'bimbingan dan konseling'=> 'BK',
            'prakarya dan kewirausahaan' => 'PKWU',
            'prakarya'               => 'PKR',
            'kewirausahaan'          => 'KWU',
            'muatan lokal'           => 'MULOK',
            'tasawuf'                => 'TSW',
            'ilmu kalam'             => 'KLM',
            'nahwu'                  => 'NHW',
            'shorof'                 => 'SHR',
            'balaghah'               => 'BLG',
        ];

        foreach ($dict as $key => $abbr) {
            if (strpos($lower, $key) !== false) {
                return $abbr;
            }
        }

        $words = preg_split('/[\s\-_,\.\/&]+/', $namaClean, -1, PREG_SPLIT_NO_EMPTY);
        $stopwords = ['dan', 'atau', 'pada', 'di', 'ke', 'dari', 'untuk', 'yang', 'dengan', '&'];
        $filteredWords = [];
        foreach ($words as $w) {
            $wClean = preg_replace('/[^a-zA-Z0-9]/', '', $w);
            if ($wClean !== '' && !in_array(strtolower($wClean), $stopwords, true)) {
                $filteredWords[] = $wClean;
            }
        }

        if (count($filteredWords) >= 2) {
            $abbr = '';
            foreach ($filteredWords as $w) {
                $abbr .= strtoupper(substr($w, 0, 1));
            }
            if (strlen($abbr) === 2 && strlen($filteredWords[0]) >= 2) {
                $abbr = strtoupper(substr($filteredWords[0], 0, 2) . substr($filteredWords[1], 0, 1));
            }
            return substr($abbr, 0, 4);
        } elseif (count($filteredWords) === 1) {
            $word = strtoupper($filteredWords[0]);
            if (strlen($word) <= 4) {
                return $word;
            }
            $vowelsRemoved = $word[0] . preg_replace('/[AEIOU]/', '', substr($word, 1));
            if (strlen($vowelsRemoved) >= 3) {
                return substr($vowelsRemoved, 0, 3);
            }
            return substr($word, 0, 3);
        }

        return 'MPL';
    }
}

if (!function_exists('ilmuna_generate_subject_code')) {
    function ilmuna_generate_subject_code(PDO $pdo, string $namaMapel, int $idMapel = 0): string {
        $singkatan = ilmuna_abbreviate_subject($namaMapel);

        if ($idMapel <= 0) {
            try {
                $st = $pdo->query("SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mapel'");
                $nextId = (int)$st->fetchColumn();
                if ($nextId <= 0) {
                    $st = $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM mapel");
                    $nextId = (int)$st->fetchColumn();
                }
                $idMapel = $nextId > 0 ? $nextId : 1;
            } catch (Throwable $e) {
                $idMapel = 1;
            }
        }

        $chk = $pdo->prepare("SELECT id FROM mapel WHERE kode_mapel = ? AND id != ? LIMIT 1");

        for ($i = 0; $i < 10; $i++) {
            $rand2 = str_pad((string)random_int(10, 99), 2, '0', STR_PAD_LEFT);
            $rand3 = str_pad((string)random_int(100, 999), 3, '0', STR_PAD_LEFT);
            $kode = "{$singkatan}-{$rand2}{$idMapel}{$rand3}";

            $chk->execute([$kode, $idMapel]);
            if (!$chk->fetchColumn()) {
                return $kode;
            }
        }

        return "{$singkatan}-" . random_int(10, 99) . $idMapel . random_int(100, 999);
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
 * Helper: XML Escaping
 */
if (!function_exists('ilmuna_xml_esc')) {
    function ilmuna_xml_esc($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

/**
 * Helper: Simple Reader XLSX
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
            $rIndex = (int)$row['r'];
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
 * Generator File XLSX Terstruktur (Lumina Gold Theme)
 */
function ilmuna_generate_subjects_excel($sheetsData) {
    $tempFile = tempnam(sys_get_temp_dir(), 'ilmuna_subj_');
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
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"><alignment vertical="center"/></xf>' // 2: Data Normal
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>' // 3: Data Center
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" applyFont="1"><alignment vertical="center"/></xf>' // 4: Big Title
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

        // Custom rows (Title, Subtitle, etc.)
        if (!empty($s['title_text'])) {
            $sheetXml .= '<row r="' . $rNum . '" ht="30" customHeight="1">'
                . '<c r="A' . $rNum . '" s="4" t="inlineStr"><is><t>' . ilmuna_xml_esc($s['title_text']) . '</t></is></c>'
                . '</row>';
            $rNum++;
            if (!empty($s['subtitle_text'])) {
                $sheetXml .= '<row r="' . $rNum . '" ht="20" customHeight="1">'
                    . '<c r="A' . $rNum . '" s="0" t="inlineStr"><is><t>' . ilmuna_xml_esc($s['subtitle_text']) . '</t></is></c>'
                    . '</row>';
                $rNum++;
            }
            $rNum++; // spacer
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
                    $styleId = (is_numeric($val) || $cIdx === 0 || $cIdx === 1 || $cIdx === 4 || $cIdx === 5 || $cIdx === 6) ? '3' : '2';
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
 * ROUTER AKSI EXCEL
 * ============================================================================== */

$action = $_REQUEST['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '';

// 1. DOWNLOAD TEMPLATE EXCEL
if ($action === 'download_template') {
    $headers = ['nama_mapel', 'kategori', 'kkm', 'tingkat', 'deskripsi'];
    $sampleRows = [
        ['Pendidikan Agama Islam & Budi Pekerti (Kelas X)', 'PAI & Budi Pekerti', '75', 'X', 'Kurikulum Merdeka Fase E'],
        ['Al-Qur\'an Hadits & Tajwid', 'Al-Qur\'an & Hadits', '78', 'Semua', 'Kajian tafsir tematik dan ilmu tajwid aplikatif'],
        ['Akidah Akhlak & Pembinaan Karakter', 'Akidah Akhlak', '75', 'Semua', 'Fondasi tauhid dan akhlak karimah peserta didik'],
        ['Fiqih Ibadah & Muamalah Kontemporer', 'Fiqih', '75', 'Semua', 'Praktik ibadah fardhu, sunnah, dan muamalah modern'],
        ['Sejarah Kebudayaan Islam (SKI)', 'Tarikh / SKI', '75', 'Semua', 'Peradaban Islam era Rasulullah SAW hingga Nusantara'],
        ['Bahasa Arab Dasar & Percakapan (Muhadatsah)', 'Bahasa Arab', '72', 'Semua', 'Penguasaan kosakata bahasa Al-Qur\'an & kaidah nahwu shorof']
    ];

    $sheets = [
        [
            'title'         => 'Template Mapel',
            'title_text'    => 'FORMAT TEMPLATE IMPORT MATA PELAJARAN — ILMUNA LEARN',
            'subtitle_text' => 'Petunjuk: Isi kolom nama_mapel, kategori, standar KKM (misal: 75), tingkat (X, XI, XII, Semua), dan deskripsi.',
            'widths'        => [45, 25, 12, 14, 45],
            'headers'       => $headers,
            'rows'          => $sampleRows
        ]
    ];

    $filePath = ilmuna_generate_subjects_excel($sheets);
    $filename = 'template-import-mapel-ilmuna.xlsx';

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

// 2. EXPORT EXCEL DATA MAPEL (Mendukung ID terpilih atau Filter)
if ($action === 'export_excel') {
    $ids = trim($_GET['ids'] ?? '');
    $search = trim($_GET['search'] ?? '');
    $f_kategori = trim($_GET['f_kategori'] ?? '');
    $f_tingkat = trim($_GET['f_tingkat'] ?? '');

    $where = '1=1';
    $params = [];

    if ($ids !== '') {
        $idArr = array_filter(array_map('intval', explode(',', $ids)));
        if (!empty($idArr)) {
            $inClause = implode(',', $idArr);
            $where .= " AND m.id IN ($inClause)";
        }
    } else {
        if ($search !== '') {
            $like = '%' . $search . '%';
            $where .= " AND (m.kode_mapel LIKE ? OR m.nama_mapel LIKE ? OR m.kategori LIKE ? OR m.deskripsi LIKE ?)";
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ($f_kategori !== '') {
            $where .= " AND m.kategori = ?";
            $params[] = $f_kategori;
        }
        if ($f_tingkat !== '') {
            $where .= " AND m.tingkat = ?";
            $params[] = $f_tingkat;
        }
    }

    $sql = "
        SELECT m.id, m.kode_mapel, m.nama_mapel, m.kategori, m.kkm, m.tingkat, m.deskripsi,
            (
                SELECT COUNT(DISTINCT km.id_kelas)
                FROM kelas_mata_pelajaran km
                JOIN mata_pelajaran mp ON km.id_mata_pelajaran = mp.id
                WHERE mp.kode_mapel = m.kode_mapel 
                   OR mp.nama_mata_pelajaran LIKE CONCAT('%', m.nama_mapel, '%')
                   OR m.nama_mapel LIKE CONCAT('%', mp.nama_mata_pelajaran, '%')
            ) AS total_kelas
        FROM mapel m
        WHERE {$where}
        ORDER BY m.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $exportRows = [];
    $no = 1;
    foreach ($rows as $r) {
        $exportRows[] = [
            $no++,
            $r['kode_mapel'],
            $r['nama_mapel'],
            $r['kategori'] ?: 'Umum',
            $r['tingkat'] ?: 'Semua',
            (int)$r['kkm'],
            (int)$r['total_kelas'] . ' Rombel',
            $r['deskripsi'] ?: '-'
        ];
    }

    $sheets = [
        [
            'title'         => 'Data Mata Pelajaran',
            'title_text'    => 'MASTER DATA MATA PELAJARAN & KURIKULUM — ILMUNA LEARN',
            'subtitle_text' => 'Diekspor pada: ' . date('d/m/Y H:i:s') . ' • Total: ' . count($exportRows) . ' Mata Pelajaran',
            'widths'        => [6, 16, 45, 24, 14, 12, 18, 45],
            'headers'       => ['#', 'Kode Mapel', 'Nama Mata Pelajaran', 'Kategori Rumpun', 'Tingkat / Fase', 'Standar KKM', 'Rombel Terkait', 'Deskripsi Capaian'],
            'rows'          => $exportRows
        ]
    ];

    $filePath = ilmuna_generate_subjects_excel($sheets);
    $filename = 'data-mata-pelajaran-' . date('Ymd_His') . '.xlsx';

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

// 3. IMPORT EXCEL (.xlsx)
if ($action === 'import_excel') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_FILES['file']['tmp_name'])) {
        echo json_encode(['success' => false, 'data' => ['message' => 'File Excel belum dipilih.']]);
        exit;
    }

    $file = $_FILES['file']['tmp_name'];
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        echo json_encode(['success' => false, 'data' => ['message' => 'Hanya file berkas Excel format .xlsx yang didukung.']]);
        exit;
    }

    $rows = ilmuna_xlsx_read($file);
    if ($rows === false || empty($rows)) {
        echo json_encode(['success' => false, 'data' => ['message' => 'Gagal membaca isi file Excel.']]);
        exit;
    }

    $headers = array_map(function($h){ return strtolower(trim((string)$h)); }, $rows[0]);
    $expected = ['nama_mapel'];
    $missing = [];
    foreach ($expected as $h) {
        if (!in_array($h, $headers, true)) $missing[] = $h;
    }
    if ($missing) {
        echo json_encode(['success' => false, 'data' => ['message' => 'Format file tidak sesuai skema. Kolom wajib: nama_mapel (dan opsional: kode_mapel, kategori, kkm, tingkat, deskripsi)']]);
        exit;
    }

    $col = [
        'nama_mapel' => array_search('nama_mapel', $headers, true),
        'kode_mapel' => array_search('kode_mapel', $headers, true),
        'kategori'   => array_search('kategori', $headers, true),
        'kkm'        => array_search('kkm', $headers, true),
        'tingkat'    => array_search('tingkat', $headers, true),
        'deskripsi'  => array_search('deskripsi', $headers, true)
    ];

    $log = [];
    $stat = ['ok' => 0, 'skip' => 0, 'error' => 0];

    $stmtCheck = $pdo->prepare("SELECT id FROM mapel WHERE kode_mapel = ? OR LOWER(TRIM(nama_mapel)) = ? LIMIT 1");
    $stmtInsert = $pdo->prepare("INSERT INTO mapel (kode_mapel, nama_mapel, kategori, kkm, tingkat, deskripsi) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtUpdate = $pdo->prepare("UPDATE mapel SET nama_mapel = ?, kategori = ?, kkm = ?, tingkat = ?, deskripsi = ? WHERE id = ?");

    // Sync to mata_pelajaran
    $stmtSyncMp = $pdo->prepare("
        INSERT INTO mata_pelajaran (nama_mata_pelajaran, kode_mapel, kktp, tahun_pelajaran, semester)
        VALUES (?, ?, ?, '2026/2027', 'Ganjil')
        ON DUPLICATE KEY UPDATE kktp = VALUES(kktp), nama_mata_pelajaran = VALUES(nama_mata_pelajaran)
    ");

    foreach (array_slice($rows, 1) as $i => $row) {
        $rowNum = $i + 2;
        $name = trim((string)($row[$col['nama_mapel']] ?? ''));
        $code = $col['kode_mapel'] !== false ? strtoupper(trim((string)($row[$col['kode_mapel']] ?? ''))) : '';
        $kategori = $col['kategori'] !== false ? trim((string)($row[$col['kategori']] ?? 'PAI & Budi Pekerti')) : 'PAI & Budi Pekerti';
        $kkm = $col['kkm'] !== false ? (int)($row[$col['kkm']] ?? 75) : 75;
        if ($kkm <= 0) $kkm = 75;
        $tingkat = $col['tingkat'] !== false ? trim((string)($row[$col['tingkat']] ?? 'Semua')) : 'Semua';
        $desc = $col['deskripsi'] !== false ? trim((string)($row[$col['deskripsi']] ?? '')) : '';

        if ($name === '' && $code === '') continue;
        if ($name === '') {
            $log[] = [$rowNum, 'error', 'Nama mata pelajaran tidak boleh kosong.'];
            $stat['error']++;
            continue;
        }

        // Cek apakah mata pelajaran sudah ada berdasarkan nama_mapel atau kode_mapel
        $stmtCheck->execute([$code !== '' ? $code : '---', strtolower($name)]);
        $existId = $stmtCheck->fetchColumn();

        if ($existId) {
            $stmtUpdate->execute([$name, $kategori, $kkm, $tingkat, $desc, $existId]);
            $stEx = $pdo->prepare("SELECT kode_mapel FROM mapel WHERE id = ?");
            $stEx->execute([$existId]);
            $realCode = $stEx->fetchColumn();
            if ($realCode) $code = $realCode;
            $log[] = [$rowNum, 'ok', "Mata pelajaran \"{$name}\" ({$code}) berhasil diperbarui."];
            $stat['ok']++;
        } else {
            try {
                // Generate kode unik otomatis: (singkatan)-(2random)(id)(3random)
                $tempCode = ilmuna_generate_subject_code($pdo, $name, 0);
                $stmtInsert->execute([$tempCode, $name, $kategori, $kkm, $tingkat, $desc]);
                $newId = (int)$pdo->lastInsertId();

                // Generate kode definitif dengan ID riil mapel
                $finalCode = ilmuna_generate_subject_code($pdo, $name, $newId);
                $pdo->prepare("UPDATE mapel SET kode_mapel = ? WHERE id = ?")->execute([$finalCode, $newId]);
                $code = $finalCode;

                $log[] = [$rowNum, 'ok', "Mata pelajaran baru \"{$name}\" ({$code}) berhasil ditambahkan."];
                $stat['ok']++;
            } catch (Exception $e) {
                $log[] = [$rowNum, 'error', "Gagal menambahkan mapel: " . $e->getMessage()];
                $stat['error']++;
                continue;
            }
        }

        // Sync ke mata_pelajaran
        try {
            $stmtSyncMp->execute([$name, $code, (float)$kkm]);
        } catch (Exception $eSync) {}
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

echo json_encode(['success' => false, 'data' => ['message' => 'Aksi Excel tidak dikenali.']]);
exit;
