<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — BANK BUTIR SOAL ASESMEN & IMPORT DOCS (ADMIN & GURU)
 * ==============================================================================
 * Standar Kurikulum Merdeka (Standar 69 Tabel PDP).
 * Mengelola Butir Soal & Kunci Jawaban:
 * - asesmen_sumatif_soal & asesmen_sumatif_soal_opsi & asesmen_sumatif_soal_rubrik
 * - asesmen_formatif_soal & asesmen_formatif_soal_opsi & asesmen_formatif_soal_rubrik
 * - asesmen_diagnostik_soal & asesmen_diagnostik_soal_opsi & asesmen_diagnostik_soal_rubrik
 * - pengayaan_remedial_soal & pengayaan_remedial_soal_opsi & pengayaan_remedial_soal_rubrik
 * Fitur Utama:
 * 1. Filter Rombel Kelas Dulu -> Otomatis menampilkan paket asesmen terkait.
 * 2. Template Word (.docx) ber-KOTAK IDENTITAS (Kelas & Mapel relasi KMP) & SOAL BERKOTAK.
 * 3. Import DOCS otomatis mencari relasi KMP (kelas_mata_pelajaran) dari kotak identitas berkas.
 * 4. Kunci jawaban otomatis di-hide di web (disensor) dengan toggle khusus admin.
 * 5. Laporan hasil import: jumlah berhasil, jumlah gagal, dan rincian log per butir soal.
 * Tema Warna: Lumina Gold (Navy #1D3B5E, Teal #0D7A87, Sky #52B2DC, Gold #F59E0B)
 * ==============================================================================
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../auth.php';
require_once __DIR__ . '/../../database.php';

requireRole(['admin', 'guru']);

$pdo = getDBConnection();
$pageTitle = 'Bank Butir Soal & Import DOCS';
$pageIcon = 'solar:clipboard-list-bold';

/**
 * Helper pemetaan nama tabel master, soal, opsi, dan rubrik
 * berdasarkan kategori asesmen / tindak lanjut (sumatif, formatif, diagnostik, remedial/pengayaan).
 */
function getAssessmentSoalTables($kategori) {
    $kat = strtolower(trim((string)$kategori));
    if ($kat === 'formatif') {
        return [
            'kategori' => 'formatif',
            'master'   => 'asesmen_formatif',
            'soal'     => 'asesmen_formatif_soal',
            'fk'       => 'id_asesmen_formatif',
            'opsi'     => 'asesmen_formatif_soal_opsi',
            'rubrik'   => 'asesmen_formatif_soal_rubrik'
        ];
    }
    if ($kat === 'diagnostik') {
        return [
            'kategori' => 'diagnostik',
            'master'   => 'asesmen_diagnostik',
            'soal'     => 'asesmen_diagnostik_soal',
            'fk'       => 'id_asesmen_diagnostik',
            'opsi'     => 'asesmen_diagnostik_soal_opsi',
            'rubrik'   => 'asesmen_diagnostik_soal_rubrik'
        ];
    }
    if ($kat === 'remedial' || $kat === 'pengayaan' || $kat === 'pengayaan_remedial') {
        return [
            'kategori' => 'remedial',
            'master'   => 'pengayaan_remedial',
            'soal'     => 'pengayaan_remedial_soal',
            'fk'       => 'id_pengayaan_remedial',
            'opsi'     => 'pengayaan_remedial_soal_opsi',
            'rubrik'   => 'pengayaan_remedial_soal_rubrik'
        ];
    }
    return [
        'kategori' => 'sumatif',
        'master'   => 'asesmen_sumatif',
        'soal'     => 'asesmen_sumatif_soal',
        'fk'       => 'id_asesmen_sumatif',
        'opsi'     => 'asesmen_sumatif_soal_opsi',
        'rubrik'   => 'asesmen_sumatif_soal_rubrik'
    ];
}

/**
 * Cek apakah suatu tabel memiliki kolom tertentu (dan opsional tambahkan link_video jika belum ada).
 */
function checkOrAddLinkVideoColumn($pdo, $soalTable) {
    static $cache = [];
    if (isset($cache[$soalTable])) {
        return $cache[$soalTable];
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$soalTable}` LIKE 'link_video'");
            if ($stmt && $stmt->fetch()) {
                $cache[$soalTable] = true;
                return true;
            }
            $pdo->exec("ALTER TABLE `{$soalTable}` ADD COLUMN `link_video` VARCHAR(255) DEFAULT NULL AFTER `gambar_stimulus`");
            $cache[$soalTable] = true;
            return true;
        } else {
            $cols = $pdo->query("PRAGMA table_info(`{$soalTable}`)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($cols as $c) {
                if (($c['name'] ?? '') === 'link_video') {
                    $cache[$soalTable] = true;
                    return true;
                }
            }
            $pdo->exec("ALTER TABLE `{$soalTable}` ADD COLUMN `link_video` VARCHAR(255) DEFAULT NULL");
            $cache[$soalTable] = true;
            return true;
        }
    } catch (Throwable $e) {
        $cache[$soalTable] = false;
        return false;
    }
}

/* ==============================================================================
 * 1. BACKEND AJAX & DOWNLOAD HANDLERS
 * ============================================================================== */

// 1.1 DOWNLOAD TEMPLATE DOCS (.docx)
if (isset($_GET['action']) && $_GET['action'] === 'download_template_docs') {
    $selectedKelasId    = (int)($_GET['kelas_id'] ?? 0);
    $selectedMapelId    = (int)($_GET['mapel_id'] ?? 0);
    $selectedAsesmenId  = (int)($_GET['asesmen_id'] ?? 0);
    $selectedKategori   = trim($_GET['kategori'] ?? 'sumatif');
    $selectedKmpId      = (int)($_GET['kmp_id'] ?? 0);

    $namaKelas       = '';
    $namaMapel       = '';
    $namaGuru        = 'Guru Pengampu PAI';
    $judulAsesmen    = '';
    $kategoriAsesmen = ucfirst($selectedKategori ?: 'Sumatif');

    if ($selectedAsesmenId > 0) {
        $tblMeta = getAssessmentSoalTables($selectedKategori);
        $normKat = $tblMeta['kategori'];
        if ($normKat === 'sumatif') {
            $stmtA = $pdo->prepare("
                SELECT a.id, a.judul_asesmen, a.jenis_sumatif,
                       COALESCE(
                           (SELECT GROUP_CONCAT(DISTINCT k2.nama_kelas ORDER BY k2.nama_kelas SEPARATOR ', ')
                            FROM asesmen_sumatif_kelas ask2
                            JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id
                            JOIN kelas k2 ON kmp2.id_kelas = k2.id
                            WHERE ask2.id_asesmen_sumatif = a.id),
                           k.nama_kelas, ''
                       ) AS nama_kelas,
                       COALESCE(
                           mp.nama_mata_pelajaran,
                           (SELECT mp2.nama_mata_pelajaran 
                            FROM asesmen_sumatif_kelas ask2
                            JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id
                            JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id
                            WHERE ask2.id_asesmen_sumatif = a.id LIMIT 1),
                           ''
                       ) AS nama_mata_pelajaran,
                       COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru
                FROM asesmen_sumatif a
                LEFT JOIN kelas_mata_pelajaran kmp ON a.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru g ON (a.id_guru = g.id_user OR a.id_guru = g.id)
                WHERE a.id = ?
            ");
        } elseif ($normKat === 'remedial') {
            $stmtA = $pdo->prepare("
                SELECT a.id, a.judul_program AS judul_asesmen, a.kategori AS jenis_program, a.bentuk_kegiatan,
                       k.nama_kelas, mp.nama_mata_pelajaran,
                       COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru
                FROM pengayaan_remedial a
                LEFT JOIN kelas_mata_pelajaran kmp ON a.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru g ON (a.id_guru = g.id_user OR a.id_guru = g.id)
                WHERE a.id = ?
            ");
        } else {
            $tb = ($normKat === 'formatif') ? 'asesmen_formatif' : 'asesmen_diagnostik';
            $stmtA = $pdo->prepare("
                SELECT a.id, a.judul_asesmen,
                       k.nama_kelas, mp.nama_mata_pelajaran,
                       COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru
                FROM {$tb} a
                LEFT JOIN kelas_mata_pelajaran kmp ON a.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN kelas k ON kmp.id_kelas = k.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN guru g ON (a.id_guru = g.id_user OR a.id_guru = g.id)
                WHERE a.id = ?
            ");
        }
        $stmtA->execute([$selectedAsesmenId]);
        $rowA = $stmtA->fetch(PDO::FETCH_ASSOC);
        if ($rowA) {
            $judulAsesmen = $rowA['judul_asesmen'] ?? '';
            $namaKelas    = $rowA['nama_kelas'] ?? '';
            $namaMapel    = $rowA['nama_mata_pelajaran'] ?? '';
            $namaGuru     = $rowA['nama_guru'] ?? '';
            if (!empty($rowA['jenis_program'])) {
                $kategoriAsesmen = $rowA['jenis_program'];
            }
        }
    }

    if (empty($namaKelas) && $selectedKelasId > 0) {
        $rowK = $pdo->query("SELECT nama_kelas FROM kelas WHERE id = " . (int)$selectedKelasId)->fetch(PDO::FETCH_ASSOC);
        if ($rowK) $namaKelas = $rowK['nama_kelas'];
    }
    if (empty($namaMapel) && $selectedMapelId > 0) {
        $rowM = $pdo->query("SELECT nama_mata_pelajaran FROM mata_pelajaran WHERE id = " . (int)$selectedMapelId)->fetch(PDO::FETCH_ASSOC);
        if ($rowM) $namaMapel = $rowM['nama_mata_pelajaran'];
    }
    if (empty($namaGuru) && $selectedKelasId > 0 && $selectedMapelId > 0) {
        $stmtG = $pdo->prepare("
            SELECT COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru
            FROM kelas_mata_pelajaran kmp
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
            LEFT JOIN guru g ON gmp.id_guru = g.id
            WHERE kmp.id_kelas = ? AND kmp.id_mata_pelajaran = ?
            LIMIT 1
        ");
        $stmtG->execute([$selectedKelasId, $selectedMapelId]);
        $rowG = $stmtG->fetch(PDO::FETCH_ASSOC);
        if ($rowG) $namaGuru = $rowG['nama_guru'];
    }

    if (empty($namaKelas)) $namaKelas = 'X PAI-1';
    if (empty($namaMapel)) $namaMapel = 'Pendidikan Agama Islam & Budi Pekerti';
    if (empty($judulAsesmen)) $judulAsesmen = "Asesmen {$kategoriAsesmen} {$namaMapel}";

    $tempFile = tempnam(sys_get_temp_dir(), 'ilmuna_docs_');
    $zip = new ZipArchive();

    if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Default Extension="png" ContentType="image/png"/>
  <Default Extension="jpeg" ContentType="image/jpeg"/>
  <Default Extension="jpg" ContentType="image/jpeg"/>
  <Default Extension="gif" ContentType="image/gif"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rIdImg1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/stimulus_1.png"/>
  <Relationship Id="rIdImg6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/stimulus_6.png"/>
</Relationships>';
        $zip->addFromString('word/_rels/document.xml.rels', $docRels);

        function generateDocxSampleStimulusPng($title, $subtitle = '', $bgColorHex = 0x1D3B5E) {
            $w = 640;
            $h = 360;
            $img = imagecreatetruecolor($w, $h);

            $r = ($bgColorHex >> 16) & 0xFF;
            $g = ($bgColorHex >> 8) & 0xFF;
            $b = $bgColorHex & 0xFF;
            $bg = imagecolorallocate($img, $r, $g, $b);
            imagefilledrectangle($img, 0, 0, $w, $h, $bg);

            $gold    = imagecolorallocate($img, 245, 158, 11);
            $teal    = imagecolorallocate($img, 13, 122, 135);
            $white   = imagecolorallocate($img, 255, 255, 255);
            $slate   = imagecolorallocate($img, 203, 213, 225);
            $innerBg = imagecolorallocate($img, 15, 23, 42);

            imagefilledrectangle($img, 14, 14, $w - 14, $h - 14, $innerBg);
            imagerectangle($img, 14, 14, $w - 14, $h - 14, $teal);

            imagefilledrectangle($img, 18, 18, $w - 18, 54, $teal);
            imagestring($img, 4, 30, 26, "ILMUNA LEARN -- MEDIA STIMULUS ASESMEN", $white);

            imagestring($img, 5, 30, 78, $title, $gold);
            if (!empty($subtitle)) {
                imagestring($img, 4, 30, 110, $subtitle, $slate);
            }

            imagestring($img, 4, 35, 155, "1. Amati dan cermati informasi pada stimulus visual ini.", $white);
            imagestring($img, 4, 35, 190, "2. Hubungkan petunjuk pada stimulus dengan pertanyaan butir soal.", $white);
            imagestring($img, 4, 35, 225, "3. Guru dapat langsung menimpa atau menempelkan gambar asli di sini.", $white);

            imagestring($img, 2, 30, $h - 38, "[Contoh Gambar Stimulus Tersemat -- Anda dapat Paste/Sisipkan Gambar langsung di Word]", $slate);

            ob_start();
            imagepng($img);
            $bin = ob_get_clean();
            imagedestroy($img);
            return $bin;
        }

        $samplePng1 = generateDocxSampleStimulusPng("STIMULUS VISUAL 1: RUKUN ISLAM & FONDASI IMAN", "Pilar Keimanan dan Ketaatan Muslim", 0x1D3B5E);
        $samplePng6 = generateDocxSampleStimulusPng("STIMULUS VISUAL 2: HIKMAH ZIKIR & KETENTERAMAN", "Refleksi Qolbu & Penguatan Akhlak Mulia", 0x0D7A87);
        $zip->addFromString('word/media/stimulus_1.png', $samplePng1);
        $zip->addFromString('word/media/stimulus_6.png', $samplePng6);

        function makeWordQuestionBox($no, $pertanyaan, $opsiA, $opsiB, $opsiC, $opsiD, $opsiE, $kunci, $bobot = 10, $imageRId = '', $linkVideo = '') {
            $pertanyaanXml = htmlspecialchars($pertanyaan, ENT_XML1, 'UTF-8');
            $opsiAXml      = htmlspecialchars($opsiA, ENT_XML1, 'UTF-8');
            $opsiBXml      = htmlspecialchars($opsiB, ENT_XML1, 'UTF-8');
            $opsiCXml      = htmlspecialchars($opsiC, ENT_XML1, 'UTF-8');
            $opsiDXml      = htmlspecialchars($opsiD, ENT_XML1, 'UTF-8');
            $opsiEXml      = htmlspecialchars($opsiE, ENT_XML1, 'UTF-8');
            $linkVideoXml  = htmlspecialchars($linkVideo, ENT_XML1, 'UTF-8');

            $stimulusXml = '';
            if (!empty($imageRId)) {
                $stimulusXml .= '
          <w:p>
            <w:pPr><w:jc w:val="center"/></w:pPr>
            <w:r>
              <w:drawing>
                <wp:inline distT="0" distB="0" distL="0" distR="0">
                  <wp:extent cx="3600000" cy="2025000"/>
                  <wp:effectExtent l="0" t="0" r="0" b="0"/>
                  <wp:docPr id="' . $no . '" name="Stimulus_' . $no . '"/>
                  <wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>
                  <a:graphic>
                    <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                      <pic:pic>
                        <pic:nvPicPr>
                          <pic:cNvPr id="' . $no . '" name="stimulus_' . $no . '.png"/>
                          <pic:cNvPicPr/>
                        </pic:nvPicPr>
                        <pic:blipFill>
                          <a:blip r:embed="' . $imageRId . '"/>
                          <a:stretch><a:fillRect/></a:stretch>
                        </pic:blipFill>
                        <pic:spPr>
                          <a:xfrm><a:off x="0" y="0"/><a:ext cx="3600000" cy="2025000"/></a:xfrm>
                          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                        </pic:spPr>
                      </pic:pic>
                    </a:graphicData>
                  </a:graphic>
                </wp:inline>
              </w:drawing>
            </w:r>
          </w:p>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="0D7A87"/><w:sz w:val="18"/></w:rPr><w:t>GAMBAR STIMULUS (GAMBAR ASLI TERSEMAT DI ATAS):</w:t></w:r></w:p>
          <w:p><w:r><w:rPr><w:i/><w:color w:val="64748B"/><w:sz w:val="18"/></w:rPr><w:t>Petunjuk: Anda dapat menghapus gambar di atas atau langsung Paste / Sisipkan Gambar (Insert Picture) Anda sendiri ke dalam kotak soal ini.</w:t></w:r></w:p>';
            } else {
                $stimulusXml .= '<w:p><w:r><w:rPr><w:i/><w:color w:val="94A3B8"/><w:sz w:val="18"/></w:rPr><w:t>[Gambar Stimulus: Silakan Paste / Sisipkan Gambar langsung di sini jika butir soal ini memerlukan gambar stimulus visual]</w:t></w:r></w:p>';
            }

            if (!empty($linkVideo)) {
                $stimulusXml .= '<w:p><w:r><w:rPr><w:b/><w:color w:val="DC2626"/></w:rPr><w:t>LINK VIDEO: </w:t></w:r><w:r><w:t>' . $linkVideoXml . '</w:t></w:r></w:p>';
            } else {
                $stimulusXml .= '<w:p><w:r><w:rPr><w:b/><w:color w:val="64748B"/></w:rPr><w:t>LINK VIDEO: </w:t></w:r><w:r><w:rPr><w:i/><w:color w:val="94A3B8"/><w:sz w:val="16"/></w:rPr><w:t>(Contoh link YouTube: https://www.youtube.com/watch?v=0e3GPea1Tyg -- Kosongkan jika tidak ada video stimulus)</w:t></w:r></w:p>';
            }

            return '
    <w:tbl>
      <w:tblPr>
        <w:tblW w:w="5000" w:type="pct"/>
        <w:tblBorders>
          <w:top w:val="single" w:sz="10" w:space="0" w:color="0D7A87"/>
          <w:left w:val="single" w:sz="10" w:space="0" w:color="0D7A87"/>
          <w:bottom w:val="single" w:sz="10" w:space="0" w:color="0D7A87"/>
          <w:right w:val="single" w:sz="10" w:space="0" w:color="0D7A87"/>
          <w:insideH w:val="none"/>
          <w:insideV w:val="none"/>
        </w:tblBorders>
      </w:tblPr>
      <w:tr>
        <w:tc>
          <w:tcPr>
            <w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/>
          </w:tcPr>
          <w:p>
            <w:r><w:rPr><w:b/><w:sz w:val="22"/><w:color w:val="1D3B5E"/></w:rPr><w:t>KOTAK BUTIR SOAL NOMOR ' . $no . '</w:t></w:r>
          </w:p>
        </w:tc>
      </w:tr>
      <w:tr>
        <w:tc>
          <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . $no . '. ' . $pertanyaanXml . '</w:t></w:r></w:p>
          ' . $stimulusXml . '
          <w:p><w:r><w:t>A. ' . $opsiAXml . '</w:t></w:r></w:p>
          <w:p><w:r><w:t>B. ' . $opsiBXml . '</w:t></w:r></w:p>
          <w:p><w:r><w:t>C. ' . $opsiCXml . '</w:t></w:r></w:p>
          <w:p><w:r><w:t>D. ' . $opsiDXml . '</w:t></w:r></w:p>
          <w:p><w:r><w:t>E. ' . $opsiEXml . '</w:t></w:r></w:p>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="0D7A87"/></w:rPr><w:t>KUNCI: ' . $kunci . '</w:t></w:r></w:p>
          <w:p><w:r><w:rPr><w:color w:val="64748B"/></w:rPr><w:t>BOBOT: ' . $bobot . '</w:t></w:r></w:p>
        </w:tc>
      </w:tr>
    </w:tbl>
    <w:p><w:r><w:t></w:t></w:r></w:p>';
        }

        function makeWordEssayQuestionBox($no, $pertanyaan, $pedoman, $bobot, $aspek, $kriteriaMaks, $kriteriaSedang, $kriteriaMin, $kriteriaNol, $imageRId = '', $linkVideo = '') {
            $pertanyaanXml     = htmlspecialchars($pertanyaan, ENT_XML1, 'UTF-8');
            $pedomanXml        = htmlspecialchars($pedoman, ENT_XML1, 'UTF-8');
            $aspekXml          = htmlspecialchars($aspek, ENT_XML1, 'UTF-8');
            $kriteriaMaksXml   = htmlspecialchars($kriteriaMaks, ENT_XML1, 'UTF-8');
            $kriteriaSedangXml = htmlspecialchars($kriteriaSedang, ENT_XML1, 'UTF-8');
            $kriteriaMinXml    = htmlspecialchars($kriteriaMin, ENT_XML1, 'UTF-8');
            $kriteriaNolXml    = htmlspecialchars($kriteriaNol, ENT_XML1, 'UTF-8');
            $linkVideoXml      = htmlspecialchars($linkVideo, ENT_XML1, 'UTF-8');

            $stimulusXml = '';
            if (!empty($imageRId)) {
                $stimulusXml .= '
          <w:p>
            <w:pPr><w:jc w:val="center"/></w:pPr>
            <w:r>
              <w:drawing>
                <wp:inline distT="0" distB="0" distL="0" distR="0">
                  <wp:extent cx="3600000" cy="2025000"/>
                  <wp:effectExtent l="0" t="0" r="0" b="0"/>
                  <wp:docPr id="' . $no . '" name="Stimulus_' . $no . '"/>
                  <wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>
                  <a:graphic>
                    <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                      <pic:pic>
                        <pic:nvPicPr>
                          <pic:cNvPr id="' . $no . '" name="stimulus_' . $no . '.png"/>
                          <pic:cNvPicPr/>
                        </pic:nvPicPr>
                        <pic:blipFill>
                          <a:blip r:embed="' . $imageRId . '"/>
                          <a:stretch><a:fillRect/></a:stretch>
                        </pic:blipFill>
                        <pic:spPr>
                          <a:xfrm><a:off x="0" y="0"/><a:ext cx="3600000" cy="2025000"/></a:xfrm>
                          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                        </pic:spPr>
                      </pic:pic>
                    </a:graphicData>
                  </a:graphic>
                </wp:inline>
              </w:drawing>
            </w:r>
          </w:p>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="0D7A87"/><w:sz w:val="18"/></w:rPr><w:t>GAMBAR STIMULUS (GAMBAR ASLI TERSEMAT DI ATAS):</w:t></w:r></w:p>
          <w:p><w:r><w:rPr><w:i/><w:color w:val="64748B"/><w:sz w:val="18"/></w:rPr><w:t>Petunjuk: Anda dapat menghapus gambar di atas atau langsung Paste / Sisipkan Gambar (Insert Picture) Anda sendiri ke dalam kotak soal ini.</w:t></w:r></w:p>';
            } else {
                $stimulusXml .= '<w:p><w:r><w:rPr><w:i/><w:color w:val="94A3B8"/><w:sz w:val="18"/></w:rPr><w:t>[Gambar Stimulus: Silakan Paste / Sisipkan Gambar langsung di sini jika butir soal ini memerlukan gambar stimulus visual]</w:t></w:r></w:p>';
            }

            if (!empty($linkVideo)) {
                $stimulusXml .= '<w:p><w:r><w:rPr><w:b/><w:color w:val="DC2626"/></w:rPr><w:t>LINK VIDEO: </w:t></w:r><w:r><w:t>' . $linkVideoXml . '</w:t></w:r></w:p>';
            } else {
                $stimulusXml .= '<w:p><w:r><w:rPr><w:b/><w:color w:val="64748B"/></w:rPr><w:t>LINK VIDEO: </w:t></w:r><w:r><w:rPr><w:i/><w:color w:val="94A3B8"/><w:sz w:val="16"/></w:rPr><w:t>(Contoh link YouTube: https://www.youtube.com/watch?v=0e3GPea1Tyg -- Kosongkan jika tidak ada video stimulus)</w:t></w:r></w:p>';
            }

            return '
    <w:tbl>
      <w:tblPr>
        <w:tblW w:w="5000" w:type="pct"/>
        <w:tblBorders>
          <w:top w:val="single" w:sz="12" w:space="0" w:color="1D3B5E"/>
          <w:left w:val="single" w:sz="12" w:space="0" w:color="1D3B5E"/>
          <w:bottom w:val="single" w:sz="12" w:space="0" w:color="1D3B5E"/>
          <w:right w:val="single" w:sz="12" w:space="0" w:color="1D3B5E"/>
          <w:insideH w:val="single" w:sz="6" w:space="0" w:color="E2E8F0"/>
          <w:insideV w:val="single" w:sz="6" w:space="0" w:color="E2E8F0"/>
        </w:tblBorders>
      </w:tblPr>
      <w:tr>
        <w:tc>
          <w:tcPr>
            <w:shd w:val="clear" w:color="auto" w:fill="FEF3C7"/>
          </w:tcPr>
          <w:p>
            <w:r><w:rPr><w:b/><w:sz w:val="22"/><w:color w:val="B45309"/></w:rPr><w:t>KOTAK BUTIR SOAL ESSAY / URAIAN NOMOR ' . $no . '</w:t></w:r>
          </w:p>
        </w:tc>
      </w:tr>
      <w:tr>
        <w:tc>
          <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . $no . '. ' . $pertanyaanXml . '</w:t></w:r></w:p>
          ' . $stimulusXml . '
          <w:p><w:r><w:rPr><w:b/><w:color w:val="0D7A87"/></w:rPr><w:t>PEDOMAN PENSKORAN / KUNCI JAWABAN: </w:t></w:r><w:r><w:t>' . $pedomanXml . '</w:t></w:r></w:p>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="64748B"/></w:rPr><w:t>BOBOT: </w:t></w:r><w:r><w:t>' . $bobot . '</w:t></w:r></w:p>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="1D3B5E"/></w:rPr><w:t>RUBRIK PENILAIAN LENGKAP:</w:t></w:r></w:p>
          <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>• ASPEK KOMPETENSI: </w:t></w:r><w:r><w:t>' . $aspekXml . '</w:t></w:r></w:p>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="059669"/></w:rPr><w:t>• KRITERIA SKOR MAKSIMAL: </w:t></w:r><w:r><w:t>' . $kriteriaMaksXml . '</w:t></w:r></w:p>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="D97706"/></w:rPr><w:t>• KRITERIA SKOR SEDANG: </w:t></w:r><w:r><w:t>' . $kriteriaSedangXml . '</w:t></w:r></w:p>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="EA580C"/></w:rPr><w:t>• KRITERIA SKOR MINIMAL: </w:t></w:r><w:r><w:t>' . $kriteriaMinXml . '</w:t></w:r></w:p>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="DC2626"/></w:rPr><w:t>• KRITERIA SKOR NOL: </w:t></w:r><w:r><w:t>' . $kriteriaNolXml . '</w:t></w:r></w:p>
        </w:tc>
      </w:tr>
    </w:tbl>
    <w:p><w:r><w:t></w:t></w:r></w:p>';
        }

        $namaKelasXml = htmlspecialchars($namaKelas, ENT_XML1, 'UTF-8');
        $namaMapelXml = htmlspecialchars($namaMapel, ENT_XML1, 'UTF-8');
        $namaGuruXml  = htmlspecialchars($namaGuru, ENT_XML1, 'UTF-8');
        $judulAsesmenXml = htmlspecialchars($judulAsesmen, ENT_XML1, 'UTF-8');
        $kategoriAsesmenXml = htmlspecialchars($kategoriAsesmen, ENT_XML1, 'UTF-8');

        $docXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
            xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"
            xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
            xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
            xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">
  <w:body>
    <w:p>
      <w:pPr><w:jc w:val="center"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="32"/><w:color w:val="1D3B5E"/></w:rPr><w:t>MASTER FORMAT IMPORT BUTIR SOAL ASESMEN</w:t></w:r>
    </w:p>
    <w:p>
      <w:pPr><w:jc w:val="center"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="22"/><w:color w:val="0D7A87"/></w:rPr><w:t>ILMUNA LEARN — KURIKULUM MERDEKA (STANDAR PDP)</w:t></w:r>
    </w:p>
    <w:p><w:r><w:t></w:t></w:r></w:p>

    <w:tbl>
      <w:tblPr>
        <w:tblW w:w="5000" w:type="pct"/>
        <w:tblBorders>
          <w:top w:val="single" w:sz="12" w:space="0" w:color="0D7A87"/>
          <w:left w:val="single" w:sz="12" w:space="0" w:color="0D7A87"/>
          <w:bottom w:val="single" w:sz="12" w:space="0" w:color="0D7A87"/>
          <w:right w:val="single" w:sz="12" w:space="0" w:color="0D7A87"/>
          <w:insideH w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>
          <w:insideV w:val="single" w:sz="6" w:space="0" w:color="CBD5E1"/>
        </w:tblBorders>
      </w:tblPr>
      <w:tr>
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="1600" w:type="pct"/>
            <w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/>
          </w:tcPr>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="1D3B5E"/></w:rPr><w:t>ROMBEL KELAS:</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr><w:tcW w:w="3400" w:type="pct"/></w:tcPr>
          <w:p><w:r><w:rPr><w:b/><w:sz w:val="24"/><w:color w:val="0D7A87"/></w:rPr><w:t>' . $namaKelasXml . '</w:t></w:r></w:p>
        </w:tc>
      </w:tr>
      <w:tr>
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="1600" w:type="pct"/>
            <w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/>
          </w:tcPr>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="1D3B5E"/></w:rPr><w:t>MATA PELAJARAN:</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr><w:tcW w:w="3400" w:type="pct"/></w:tcPr>
          <w:p><w:r><w:rPr><w:b/><w:sz w:val="24"/><w:color w:val="0D7A87"/></w:rPr><w:t>' . $namaMapelXml . '</w:t></w:r></w:p>
        </w:tc>
      </w:tr>
      <w:tr>
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="1600" w:type="pct"/>
            <w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/>
          </w:tcPr>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="1D3B5E"/></w:rPr><w:t>JUDUL ASESMEN:</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr><w:tcW w:w="3400" w:type="pct"/></w:tcPr>
          <w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . $judulAsesmenXml . '</w:t></w:r></w:p>
        </w:tc>
      </w:tr>
      <w:tr>
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="1600" w:type="pct"/>
            <w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/>
          </w:tcPr>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="1D3B5E"/></w:rPr><w:t>KATEGORI ASESMEN:</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr><w:tcW w:w="3400" w:type="pct"/></w:tcPr>
          <w:p><w:r><w:t>' . $kategoriAsesmenXml . '</w:t></w:r></w:p>
        </w:tc>
      </w:tr>
      <w:tr>
        <w:tc>
          <w:tcPr>
            <w:tcW w:w="1600" w:type="pct"/>
            <w:shd w:val="clear" w:color="auto" w:fill="F1F5F9"/>
          </w:tcPr>
          <w:p><w:r><w:rPr><w:b/><w:color w:val="1D3B5E"/></w:rPr><w:t>GURU PENGAMPU:</w:t></w:r></w:p>
        </w:tc>
        <w:tc>
          <w:tcPr><w:tcW w:w="3400" w:type="pct"/></w:tcPr>
          <w:p><w:r><w:t>' . $namaGuruXml . '</w:t></w:r></w:p>
        </w:tc>
      </w:tr>
    </w:tbl>

    <w:p><w:r><w:t></w:t></w:r></w:p>
    <w:p>
      <w:r><w:rPr><w:i/><w:color w:val="64748B"/></w:rPr><w:t>Petunjuk Penting: Jangan mengubah label ROMBEL KELAS, MATA PELAJARAN, dan JUDUL ASESMEN pada kotak identitas di atas. Dokumen ini memuat format Pilihan Ganda (PG) pada Bagian I dan format Essay / Uraian lengkap dengan Rubrik Penilaian pada Bagian II.</w:t></w:r>
    </w:p>
    <w:p><w:r><w:t></w:t></w:r></w:p>

    <w:p>
      <w:pPr><w:jc w:val="left"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="26"/><w:color w:val="1D3B5E"/></w:rPr><w:t>BAGIAN I: BUTIR SOAL PILIHAN GANDA (PG)</w:t></w:r>
    </w:p>
    <w:p><w:r><w:t></w:t></w:r></w:p>
    ' . makeWordQuestionBox(
        1,
        'Perhatikan gambar stimulus terkait rukun Islam di atas! Manakah di antara pernyataan berikut yang merupakan rukun Islam yang pertama dan menjadi pondasi keimanan seorang muslim?',
        'Mengucapkan dua kalimat syahadat',
        'Menunaikan shalat fardhu lima waktu',
        'Melaksanakan ibadah puasa di bulan Ramadhan',
        'Mengeluarkan zakat bagi yang mampu',
        'Menunaikan ibadah haji ke Baitullah',
        'A',
        10,
        'rIdImg1',
        ''
    ) . '
    ' . makeWordQuestionBox(
        2,
        'Simaklah tayangan video pembelajaran mengenai keagungan Al-Qur\'an pada tautan video YouTube berikut! Berdasarkan tayangan video tersebut, Surah Al-Fatihah dijuluki sebagai Ummul Kitab karena...',
        'Diturunkan di kota Madinah Al-Munawwarah',
        'Merupakan surah terpanjang dalam susunan mushaf',
        'Memuat intisari dan pokok ajaran seluruh Al-Qur\'an',
        'Wajib dihafalkan oleh setiap nabi dan rasul',
        'Memiliki ayat yang paling sering diulang dua kali',
        'C',
        10,
        '',
        'https://www.youtube.com/watch?v=0e3GPea1Tyg'
    ) . '
    ' . makeWordQuestionBox(
        3,
        'Sikap toleransi dan saling menghormati perbedaan keyakinan dalam ajaran Islam diistilahkan dengan...',
        'Tawadhu',
        'Tasamuh',
        'Tawakal',
        'Qana\'ah',
        'Istiqamah',
        'B',
        10,
        '',
        ''
    ) . '
    ' . makeWordQuestionBox(
        4,
        'Zikir secara bahasa bermakna mengingat. Tujuan utama memperbanyak zikir kepada Allah SWT adalah...',
        'Mendapatkan pujian dari orang lain di sekitar',
        'Meraih ketenteraman hati dan mendekatkan diri kepada Allah SWT',
        'Menunjukkan keunggulan ilmu di hadapan masyarakat',
        'Mempercepat datangnya kekayaan materi duniawi',
        'Menghindari tanggung jawab tugas sehari-hari',
        'B',
        10,
        '',
        ''
    ) . '
    ' . makeWordQuestionBox(
        5,
        'Salah satu adab utama ketika membaca ayat suci Al-Qur\'an adalah...',
        'Membaca secepat mungkin tanpa memperhatikan tajwid',
        'Bersuci dari hadas dan memperhatikan makharijul huruf dengan tartil',
        'Boleh diselingi dengan bercanda dan tertawa',
        'Tidak perlu memahami makna atau tafsir ayatnya',
        'Cukup dibaca di dalam hati tanpa menggerakkan bibir',
        'B',
        10,
        '',
        ''
    ) . '

    <w:p><w:r><w:t></w:t></w:r></w:p>
    <w:p>
      <w:pPr><w:jc w:val="left"/></w:pPr>
      <w:r><w:rPr><w:b/><w:sz w:val="26"/><w:color w:val="0D7A87"/></w:rPr><w:t>BAGIAN II: BUTIR SOAL ESSAY / URAIAN &amp; RUBRIK PENILAIAN LENGKAP</w:t></w:r>
    </w:p>
    <w:p>
      <w:r><w:rPr><w:i/><w:color w:val="64748B"/></w:rPr><w:t>Petunjuk Essay: Setiap butir soal essay dilengkapi pedoman penskoran, kriteria rubrik penilaian berjenjang (Maksimal, Sedang, Minimal, dan Nol), serta dapat memuat media stimulus gambar dan video (YouTube).</w:t></w:r>
    </w:p>
    <w:p><w:r><w:t></w:t></w:r></w:p>
    ' . makeWordEssayQuestionBox(
        6,
        'Amati video inspirasi dan infografis stimulus zikir di atas. Jelaskan hikmah dan keutamaan mengamalkan zikir dalam kehidupan sehari-hari, serta bagaimana zikir dapat membentuk ketenteraman jiwa bagi seorang muslim!',
        'Jawaban memuat minimal 3 poin utama: 1) Mendekatkan diri kepada Allah SWT (taqarrub ilallah), 2) Menjauhkan hati dari kegelisahan, stres, dan prasangka buruk (QS Ar-Ra\'d: 28), 3) Menjaga lisan dari perkataan sia-sia dan menguatkan akhlak terpuji.',
        20,
        'Pemahaman Konsep & Refleksi Penerapan Zikir',
        'Menjelaskan minimal 3 hikmah dan keutamaan zikir secara komprehensif, runtut, menghubungkan dengan dalil/konsep ketenteraman jiwa secara tepat dan mendalam (Skor 20).',
        'Menjelaskan 2 hikmah zikir dengan benar dan cukup jelas, namun uraian hubungan dengan ketenteraman jiwa masih bersifat umum (Skor 14).',
        'Hanya menyebutkan 1 hikmah zikir secara singkat tanpa penjelasan atau terdapat kekeliruan konsep minor (Skor 8).',
        'Jawaban tidak relevan dengan pertanyaan atau tidak memberikan jawaban sama sekali (Skor 0).',
        'rIdImg6',
        'https://www.youtube.com/watch?v=0e3GPea1Tyg'
    ) . '
    ' . makeWordEssayQuestionBox(
        7,
        'Bagaimanakah penerapan sikap tasamuh (toleransi) yang benar menurut syariat Islam dalam pergaulan di sekolah tanpa mencampuradukkan akidah dan ibadah? Berikan contoh konkritnya!',
        'Penerapan tasamuh yang benar mencakup: menghormati perbedaan suku/agama dalam muamalah dan kemanusiaan, tolong-menolong dalam kebaikan umum, tidak memaksakan keyakinan (QS Al-Kafirun: 6 & QS Al-Baqarah: 256), serta tidak mengikuti ritual ibadah agama lain.',
        20,
        'Analisis Sikap Toleransi & Batasan Akidah',
        'Mampu menguraikan prinsip tasamuh secara tepat, memberikan contoh konkrit di sekolah, dan menjelaskan batasan akidah-ibadah dengan argumen yang jelas dan shahih (Skor 20).',
        'Menjelaskan sikap tasamuh dan memberikan contoh pergaulan, namun batasan akidah dan ibadah belum dijelaskan secara mendalam (Skor 14).',
        'Hanya memberikan contoh pergaulan umum tanpa mengaitkan dengan prinsip syariat Islam (Skor 8).',
        'Tidak menjawab atau jawaban bertentangan dengan ajaran Islam (Skor 0).',
        '',
        ''
    ) . '
  </w:body>
</w:document>';

        $zip->addFromString('word/document.xml', $docXml);
        $zip->close();

        $downloadFilename = 'Template_Soal_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $namaKelas . '_' . $namaMapel . '_' . $kategoriAsesmen) . '.docx';

        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($tempFile));
        readfile($tempFile);
        @unlink($tempFile);
        exit;
    } else {
        die('Gagal membuat berkas dokumen template Word.');
    }
}

// 1.2 AJAX POST ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    ini_set('display_errors', '0');
    error_reporting(0);

    function sendCleanJson($data) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    $act = $_POST['action'];

    if ($act === 'get_mapel_by_kelas') {
        $idKelas = (int)($_POST['kelas_id'] ?? 0);
        if ($idKelas <= 0) {
            sendCleanJson(['success' => false, 'message' => 'Pilih rombel kelas terlebih dahulu.', 'data' => []]);
        }
        try {
            $stmtM = $pdo->prepare("
                SELECT DISTINCT mp.id, mp.nama_mata_pelajaran, mp.kode_mapel
                FROM kelas_mata_pelajaran kmp
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id_kelas = ?
                ORDER BY mp.nama_mata_pelajaran ASC
            ");
            $stmtM->execute([$idKelas]);
            $listMapel = $stmtM->fetchAll(PDO::FETCH_ASSOC);

            if (empty($listMapel)) {
                $listMapel = $pdo->query("SELECT id, nama_mata_pelajaran, kode_mapel FROM mata_pelajaran ORDER BY nama_mata_pelajaran ASC")->fetchAll(PDO::FETCH_ASSOC);
            }
            sendCleanJson(['success' => true, 'data' => $listMapel]);
        } catch (PDOException $e) {
            sendCleanJson(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
        }
    }

    if ($act === 'get_assessments_by_class') {
        $idKelas = (int)($_POST['kelas_id'] ?? 0);
        $idMapel = (int)($_POST['mapel_id'] ?? 0);

        if ($idKelas <= 0) {
            sendCleanJson(['success' => false, 'message' => 'Pilih rombel kelas terlebih dahulu.', 'data' => []]);
        }

        try {
            $sqlSum = "
                SELECT DISTINCT 'sumatif' AS kategori, asum.id, asum.judul_asesmen, asum.jenis_sumatif AS tipe,
                       COALESCE(
                           (SELECT mp2.nama_mata_pelajaran 
                            FROM asesmen_sumatif_kelas ask2 
                            JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id 
                            JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id 
                            WHERE ask2.id_asesmen_sumatif = asum.id LIMIT 1),
                           mp.nama_mata_pelajaran,
                           ''
                       ) AS nama_mata_pelajaran,
                       (SELECT COUNT(*) FROM asesmen_sumatif_soal WHERE id_asesmen_sumatif = asum.id) AS total_soal
                FROM asesmen_sumatif asum
                LEFT JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id
                LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                LEFT JOIN asesmen_sumatif_kelas ask ON ask.id_asesmen_sumatif = asum.id
                LEFT JOIN kelas_mata_pelajaran kmp_rel ON ask.id_kelas_mata_pelajaran = kmp_rel.id
                WHERE (kmp.id_kelas = ? OR kmp_rel.id_kelas = ?)
            ";
            $paramsSum = [$idKelas, $idKelas];
            if ($idMapel > 0) {
                $sqlSum .= " AND (kmp.id_mata_pelajaran = ? OR kmp_rel.id_mata_pelajaran = ?)";
                $paramsSum[] = $idMapel;
                $paramsSum[] = $idMapel;
            }
            $sqlSum .= " ORDER BY asum.id DESC";

            $stmtSum = $pdo->prepare($sqlSum);
            $stmtSum->execute($paramsSum);
            $listSum = $stmtSum->fetchAll(PDO::FETCH_ASSOC);

            $sqlForm = "
                SELECT 'formatif' AS kategori, af.id, af.judul_asesmen, af.teknik_asesmen AS tipe,
                       mp.nama_mata_pelajaran,
                       (SELECT COUNT(*) FROM asesmen_formatif_soal WHERE id_asesmen_formatif = af.id) AS total_soal
                FROM asesmen_formatif af
                JOIN kelas_mata_pelajaran kmp ON af.id_kelas_mata_pelajaran = kmp.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id_kelas = ?
            ";
            $paramsForm = [$idKelas];
            if ($idMapel > 0) {
                $sqlForm .= " AND kmp.id_mata_pelajaran = ?";
                $paramsForm[] = $idMapel;
            }
            $sqlForm .= " ORDER BY af.id DESC";

            $stmtForm = $pdo->prepare($sqlForm);
            $stmtForm->execute($paramsForm);
            $listForm = $stmtForm->fetchAll(PDO::FETCH_ASSOC);

            $sqlDiag = "
                SELECT 'diagnostik' AS kategori, ad.id, ad.judul_asesmen, ad.jenis_diagnostik AS tipe,
                       mp.nama_mata_pelajaran,
                       (SELECT COUNT(*) FROM asesmen_diagnostik_soal WHERE id_asesmen_diagnostik = ad.id) AS total_soal
                FROM asesmen_diagnostik ad
                JOIN kelas_mata_pelajaran kmp ON ad.id_kelas_mata_pelajaran = kmp.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id_kelas = ?
            ";
            $paramsDiag = [$idKelas];
            if ($idMapel > 0) {
                $sqlDiag .= " AND kmp.id_mata_pelajaran = ?";
                $paramsDiag[] = $idMapel;
            }
            $sqlDiag .= " ORDER BY ad.id DESC";

            $stmtDiag = $pdo->prepare($sqlDiag);
            $stmtDiag->execute($paramsDiag);
            $listDiag = $stmtDiag->fetchAll(PDO::FETCH_ASSOC);

            $sqlRem = "
                SELECT 'remedial' AS kategori, pr.id, pr.judul_program AS judul_asesmen,
                       pr.kategori AS jenis_program, pr.bentuk_kegiatan AS tipe,
                       mp.nama_mata_pelajaran,
                       (SELECT COUNT(*) FROM pengayaan_remedial_soal WHERE id_pengayaan_remedial = pr.id) AS total_soal
                FROM pengayaan_remedial pr
                JOIN kelas_mata_pelajaran kmp ON pr.id_kelas_mata_pelajaran = kmp.id
                JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                WHERE kmp.id_kelas = ?
            ";
            $paramsRem = [$idKelas];
            if ($idMapel > 0) {
                $sqlRem .= " AND kmp.id_mata_pelajaran = ?";
                $paramsRem[] = $idMapel;
            }
            $sqlRem .= " ORDER BY pr.id DESC";

            $stmtRem = $pdo->prepare($sqlRem);
            $stmtRem->execute($paramsRem);
            $listRem = $stmtRem->fetchAll(PDO::FETCH_ASSOC);

            $allAssessments = array_merge($listSum, $listForm, $listDiag, $listRem);
            sendCleanJson(['success' => true, 'data' => $allAssessments]);
        } catch (PDOException $e) {
            sendCleanJson(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
        }
    }

    if ($act === 'get_questions') {
        $kategori   = trim($_POST['kategori'] ?? 'sumatif');
        $idAsesmen  = (int)($_POST['asesmen_id'] ?? 0);
        $search     = trim($_POST['search'] ?? '');

        if ($idAsesmen <= 0) {
            sendCleanJson(['success' => false, 'message' => 'Pilih paket asesmen terlebih dahulu.', 'data' => null]);
        }

        try {
            $tblMeta = getAssessmentSoalTables($kategori);
            $kategori = $tblMeta['kategori'];

            if ($kategori === 'sumatif') {
                $masterStmt = $pdo->prepare("
                    SELECT a.*, 
                           COALESCE(
                               (SELECT GROUP_CONCAT(DISTINCT k2.nama_kelas ORDER BY k2.nama_kelas SEPARATOR ', ')
                                FROM asesmen_sumatif_kelas ask2
                                JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id
                                JOIN kelas k2 ON kmp2.id_kelas = k2.id
                                WHERE ask2.id_asesmen_sumatif = a.id),
                               k.nama_kelas, ''
                           ) AS nama_kelas,
                           COALESCE(
                               mp.nama_mata_pelajaran,
                               (SELECT mp2.nama_mata_pelajaran 
                                FROM asesmen_sumatif_kelas ask2
                                JOIN kelas_mata_pelajaran kmp2 ON ask2.id_kelas_mata_pelajaran = kmp2.id
                                JOIN mata_pelajaran mp2 ON kmp2.id_mata_pelajaran = mp2.id
                                WHERE ask2.id_asesmen_sumatif = a.id LIMIT 1),
                               ''
                           ) AS nama_mata_pelajaran,
                           COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru
                    FROM asesmen_sumatif a
                    LEFT JOIN kelas_mata_pelajaran kmp ON a.id_kelas_mata_pelajaran = kmp.id
                    LEFT JOIN kelas k ON kmp.id_kelas = k.id
                    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    LEFT JOIN guru g ON (a.id_guru = g.id_user OR a.id_guru = g.id)
                    WHERE a.id = ?
                ");
            } elseif ($kategori === 'remedial') {
                $masterStmt = $pdo->prepare("
                    SELECT a.*, 
                           a.judul_program AS judul_asesmen,
                           a.kategori AS jenis_program,
                           a.kktp_minimal AS kktp,
                           a.petunjuk_pelaksanaan AS petunjuk_pengerjaan,
                           k.nama_kelas,
                           mp.nama_mata_pelajaran,
                           COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru
                    FROM pengayaan_remedial a
                    LEFT JOIN kelas_mata_pelajaran kmp ON a.id_kelas_mata_pelajaran = kmp.id
                    LEFT JOIN kelas k ON kmp.id_kelas = k.id
                    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    LEFT JOIN guru g ON (a.id_guru = g.id_user OR a.id_guru = g.id)
                    WHERE a.id = ?
                ");
            } else {
                $masterTable = $tblMeta['master'];
                $masterStmt = $pdo->prepare("
                    SELECT a.*, 
                           k.nama_kelas,
                           mp.nama_mata_pelajaran,
                           COALESCE(g.nama_guru, 'Guru Pengampu PAI') AS nama_guru
                    FROM {$masterTable} a
                    LEFT JOIN kelas_mata_pelajaran kmp ON a.id_kelas_mata_pelajaran = kmp.id
                    LEFT JOIN kelas k ON kmp.id_kelas = k.id
                    LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                    LEFT JOIN guru g ON (a.id_guru = g.id_user OR a.id_guru = g.id)
                    WHERE a.id = ?
                ");
            }
            $masterStmt->execute([$idAsesmen]);
            $assessInfo = $masterStmt->fetch(PDO::FETCH_ASSOC);

            if (!$assessInfo) {
                sendCleanJson(['success' => false, 'message' => 'Data asesmen tidak ditemukan.', 'data' => null]);
            }

            $soalTable   = $tblMeta['soal'];
            $fkCol       = $tblMeta['fk'];
            $opsiTable   = $tblMeta['opsi'];
            $rubrikTable = $tblMeta['rubrik'];

            $sqlSoal = "SELECT * FROM {$soalTable} WHERE {$fkCol} = ?";
            $params = [$idAsesmen];

            if ($search !== '') {
                $sqlSoal .= " AND pertanyaan LIKE ?";
                $params[] = '%' . $search . '%';
            }
            $sqlSoal .= " ORDER BY nomor_soal ASC";

            $soalStmt = $pdo->prepare($sqlSoal);
            $soalStmt->execute($params);
            $questions = $soalStmt->fetchAll(PDO::FETCH_ASSOC);

            $opsiStmt   = $pdo->prepare("SELECT * FROM {$opsiTable} WHERE id_soal = ? ORDER BY kode_opsi ASC");
            $rubrikStmt = $pdo->prepare("SELECT * FROM {$rubrikTable} WHERE id_soal = ? LIMIT 1");

            foreach ($questions as &$q) {
                $nonPgTypes = ['Essay / Uraian', 'Tugas Terbimbing', 'Studi Kasus Khusus'];
                if (in_array($q['tipe_soal'], $nonPgTypes, true)) {
                    $q['opsi'] = [];
                } else {
                    $opsiStmt->execute([$q['id']]);
                    $q['opsi'] = $opsiStmt->fetchAll(PDO::FETCH_ASSOC);
                }

                $rubrikStmt->execute([$q['id']]);
                $rubrikRow = $rubrikStmt->fetch(PDO::FETCH_ASSOC);
                $q['rubrik'] = $rubrikRow ?: null;
            }
            unset($q);

            sendCleanJson([
                'success'    => true,
                'assessment' => $assessInfo,
                'kategori'   => $kategori,
                'questions'  => $questions
            ]);
        } catch (PDOException $e) {
            sendCleanJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    function parseDocxFileQuestions($uploadedFile, $pdo, $kategoriReq = '', $asesmenIdReq = 0, $clearExisting = false) {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'message' => 'PHP ZipArchive extension tidak aktif di server ini.'];
        }

        $zip = new ZipArchive();
        if ($zip->open($uploadedFile) !== true) {
            return ['success' => false, 'message' => 'Gagal membaca berkas .docx. Pastikan berkas tidak rusak atau terenkripsi.'];
        }

        $extractedImages = [];
        $stimulusDir = defined('BASE_PATH') 
            ? (BASE_PATH . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'stimulus')
            : (dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'stimulus');
        if (!is_dir($stimulusDir)) {
            @mkdir($stimulusDir, 0777, true);
        }

        for ($z = 0; $z < $zip->numFiles; $z++) {
            $entryName = $zip->getNameIndex($z);
            if (strpos($entryName, 'word/media/') === 0) {
                $ext = strtolower(pathinfo($entryName, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
                    $baseName = basename($entryName);
                    $targetFilename = 'stimulus_' . time() . '_' . substr(md5($entryName . microtime()), 0, 8) . '.' . $ext;
                    $targetPath = $stimulusDir . DIRECTORY_SEPARATOR . $targetFilename;
                    $stream = $zip->getFromIndex($z);
                    if ($stream !== false && @file_put_contents($targetPath, $stream) !== false) {
                        $extractedImages[$baseName] = 'uploads/stimulus/' . $targetFilename;
                        $extractedImages[$entryName] = 'uploads/stimulus/' . $targetFilename;
                    }
                }
            }
        }

        $rIdToImage = [];
        $relsData = $zip->getFromName('word/_rels/document.xml.rels');
        if ($relsData) {
            $relsDom = new DOMDocument();
            libxml_use_internal_errors(true);
            if ($relsDom->loadXML($relsData)) {
                $relNodes = $relsDom->getElementsByTagName('Relationship');
                foreach ($relNodes as $rn) {
                    $rId = $rn->getAttribute('Id');
                    $rTarget = $rn->getAttribute('Target');
                    $baseTarget = basename($rTarget);
                    if (isset($extractedImages[$baseTarget])) {
                        $rIdToImage[$rId] = $extractedImages[$baseTarget];
                    }
                }
            }
            libxml_clear_errors();
        }

        $xmlData = $zip->getFromName('word/document.xml');
        $zip->close();

        if (!$xmlData) {
            return ['success' => false, 'message' => 'Struktur dokumen Word tidak memiliki word/document.xml.'];
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xmlData)) {
            libxml_clear_errors();
            return ['success' => false, 'message' => 'Gagal parsing XML dokumen Word.'];
        }
        libxml_clear_errors();

        $paragraphs = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p');
        $lines = [];

        foreach ($paragraphs as $p) {
            $embeddedImgPath = '';
            $blips = $p->getElementsByTagNameNS('*', 'blip');
            foreach ($blips as $blip) {
                $embedId = $blip->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
                if (!$embedId) $embedId = $blip->getAttribute('r:embed');
                if (!$embedId) $embedId = $blip->getAttribute('embed');
                if ($embedId && isset($rIdToImage[$embedId])) {
                    $embeddedImgPath = $rIdToImage[$embedId];
                    break;
                }
            }
            if (empty($embeddedImgPath)) {
                $vmlImgs = $p->getElementsByTagNameNS('*', 'imagedata');
                foreach ($vmlImgs as $vml) {
                    $embedId = $vml->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                    if (!$embedId) $embedId = $vml->getAttribute('r:id');
                    if (!$embedId) $embedId = $vml->getAttribute('id');
                    if ($embedId && isset($rIdToImage[$embedId])) {
                        $embeddedImgPath = $rIdToImage[$embedId];
                        break;
                    }
                }
            }

            if ($embeddedImgPath !== '') {
                $lines[] = 'GAMBAR STIMULUS: ' . $embeddedImgPath;
            }

            $tNodes = $p->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't');
            $text = '';
            foreach ($tNodes as $t) {
                $text .= $t->nodeValue;
            }
            $cleanLine = trim($text);
            if ($cleanLine !== '') {
                $lines[] = $cleanLine;
            }
        }

        if (empty($lines)) {
            return ['success' => false, 'message' => 'Dokumen Word kosong atau tidak memiliki teks yang terbaca.'];
        }

        $detectedKelas = '';
        $detectedMapel = '';
        $detectedJudul = '';
        $detectedKat   = '';

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);

            if (empty($detectedKelas) && preg_match('/^(?:ROMBEL\s*KELAS|KELAS)\s*[:=]?\s*(.*)$/iu', $line, $m)) {
                $val = trim($m[1]);
                if (!empty($val) && $val !== ':') $detectedKelas = $val;
                elseif (isset($lines[$i+1]) && !preg_match('/^(?:MATA|MAPEL|JUDUL|KATEGORI|GURU|KOTAK|\d+)/iu', trim($lines[$i+1]))) $detectedKelas = trim($lines[$i+1]);
            }

            if (empty($detectedMapel) && preg_match('/^(?:MATA\s*PELAJARAN|MAPEL)\s*[:=]?\s*(.*)$/iu', $line, $m)) {
                $val = trim($m[1]);
                if (!empty($val) && $val !== ':') $detectedMapel = $val;
                elseif (isset($lines[$i+1]) && !preg_match('/^(?:ROMBEL|KELAS|JUDUL|KATEGORI|GURU|KOTAK|\d+)/iu', trim($lines[$i+1]))) $detectedMapel = trim($lines[$i+1]);
            }

            if (empty($detectedJudul) && preg_match('/^(?:JUDUL\s*ASESMEN|JUDUL)\s*[:=]?\s*(.*)$/iu', $line, $m)) {
                $val = trim($m[1]);
                if (!empty($val) && $val !== ':') $detectedJudul = $val;
                elseif (isset($lines[$i+1]) && !preg_match('/^(?:ROMBEL|KELAS|MATA|MAPEL|KATEGORI|GURU|KOTAK|\d+)/iu', trim($lines[$i+1]))) $detectedJudul = trim($lines[$i+1]);
            }

            if (empty($detectedKat) && preg_match('/^(?:KATEGORI\s*ASESMEN|KATEGORI|JENIS)\s*[:=]?\s*(.*)$/iu', $line, $m)) {
                $val = strtolower(trim($m[1]));
                if (!empty($val) && $val !== ':') $detectedKat = $val;
                elseif (isset($lines[$i+1])) $detectedKat = strtolower(trim($lines[$i+1]));
            }
        }

        $kategori = !empty($kategoriReq) ? strtolower(trim($kategoriReq)) : 'sumatif';
        if ($kategori === 'pengayaan') {
            $kategori = 'remedial';
        }
        if ($asesmenIdReq <= 0 && !empty($detectedKat)) {
            if (strpos($detectedKat, 'formatif') !== false) $kategori = 'formatif';
            elseif (strpos($detectedKat, 'diagnostik') !== false) $kategori = 'diagnostik';
            elseif (strpos($detectedKat, 'remedial') !== false || strpos($detectedKat, 'remidi') !== false || strpos($detectedKat, 'pengayaan') !== false) $kategori = 'remedial';
            else $kategori = 'sumatif';
        }

        $matchedKmp = null;
        $allKmp = $pdo->query("
            SELECT kmp.id, kmp.id_kelas, kmp.id_mata_pelajaran,
                   k.nama_kelas, mp.nama_mata_pelajaran,
                   gmp.id_guru
            FROM kelas_mata_pelajaran kmp
            JOIN kelas k ON kmp.id_kelas = k.id
            JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
            LEFT JOIN guru_mata_pelajaran gmp ON kmp.id_guru_mata_pelajaran = gmp.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($detectedKelas) && !empty($detectedMapel)) {
            foreach ($allKmp as $kmp) {
                if (strcasecmp(trim($kmp['nama_kelas']), trim($detectedKelas)) === 0 &&
                    strcasecmp(trim($kmp['nama_mata_pelajaran']), trim($detectedMapel)) === 0) {
                    $matchedKmp = $kmp;
                    break;
                }
            }
            if (!$matchedKmp) {
                foreach ($allKmp as $kmp) {
                    $cleanK1 = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $kmp['nama_kelas']));
                    $cleanK2 = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $detectedKelas));
                    $cleanM1 = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $kmp['nama_mata_pelajaran']));
                    $cleanM2 = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $detectedMapel));
                    if ((strpos($cleanK1, $cleanK2) !== false || strpos($cleanK2, $cleanK1) !== false) &&
                        (strpos($cleanM1, $cleanM2) !== false || strpos($cleanM2, $cleanM1) !== false)) {
                        $matchedKmp = $kmp;
                        break;
                    }
                }
            }
            if (!$matchedKmp) {
                foreach ($allKmp as $kmp) {
                    $cleanK1 = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $kmp['nama_kelas']));
                    $cleanK2 = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $detectedKelas));
                    if ($cleanK1 === $cleanK2 || strpos($cleanK1, $cleanK2) !== false) {
                        $matchedKmp = $kmp;
                        break;
                    }
                }
            }
        }

        $parsedQuestions = [];
        $currentQuestion = null;
        $isEssaySection  = false;
        $readingTarget   = 'pertanyaan';
        $pendingStimulusImage = '';

        foreach ($lines as $line) {
            $lineTrim = trim($line);

            if (preg_match('/^BAGIAN\s*II/iu', $lineTrim) || preg_match('/ESSAY\s*\/\s*URAIAN/iu', $lineTrim)) {
                $isEssaySection = true;
                continue;
            }
            if (preg_match('/^BAGIAN\s*I(?:\s*:|$)/iu', $lineTrim)) {
                $isEssaySection = false;
                continue;
            }

            if (preg_match('/^KOTAK\s*BUTIR\s*SOAL/iu', $lineTrim)) {
                if (preg_match('/ESSAY|URAIAN/iu', $lineTrim)) $isEssaySection = true;
                continue;
            }
            if (preg_match('/^(?:ROMBEL\s*KELAS|MATA\s*PELAJARAN|JUDUL\s*ASESMEN|KATEGORI\s*ASESMEN|GURU\s*PENGAMPU)\s*:/iu', $lineTrim)) {
                continue;
            }
            if (preg_match('/^Petunjuk(?:\s*Essay|\s*Pilihan|\s*Penting)?\s*:/iu', $lineTrim)) {
                continue;
            }

            if (preg_match('/^(\d+)[\.\)]\s*(.+)$/u', $lineTrim, $matches)) {
                if ($currentQuestion !== null && !empty($currentQuestion['pertanyaan'])) {
                    $parsedQuestions[] = $currentQuestion;
                }
                $currentQuestion = [
                    'nomor_doc'       => (int)$matches[1],
                    'tipe_soal'       => $isEssaySection ? 'Essay / Uraian' : 'Pilihan Ganda',
                    'pertanyaan'      => trim($matches[2]),
                    'opsi'            => [],
                    'kunci'           => '',
                    'pedoman'         => '',
                    'bobot'           => $isEssaySection ? 20.00 : 10.00,
                    'gambar_stimulus' => $pendingStimulusImage,
                    'link_video'      => '',
                    'rubrik'          => [
                        'aspek_kompetensi'       => '',
                        'pedoman_penskoran'      => '',
                        'skor_maksimal'          => $isEssaySection ? 20.00 : 10.00,
                        'kriteria_skor_maksimal' => '',
                        'kriteria_skor_sedang'   => '',
                        'kriteria_skor_minimal'  => '',
                        'kriteria_skor_nol'      => ''
                    ]
                ];
                $pendingStimulusImage = '';
                $readingTarget = 'pertanyaan';
                continue;
            }

            if (preg_match('/^(?:GAMBAR\s*STIMULUS|STIMULUS\s*GAMBAR|GAMBAR)\s*[:=]\s*(.*)$/iu', $lineTrim, $gMatches)) {
                $rawVal = trim($gMatches[1]);
                $val = $rawVal;
                if (isset($extractedImages[$val])) $val = $extractedImages[$val];
                $isGuide = preg_match('/^(?:\(|\[|\<)?(?:kosongkan|contoh|silakan|petunjuk|gambar asli tersemat)/iu', $val) || (trim($val, '()[]<> :-') === '');
                if (!$isGuide) {
                    if (preg_match('/^(?:https?:\/\/|uploads\/)/i', $val) || preg_match('/\.(?:png|jpe?g|gif|webp|svg)$/i', $val)) {
                        if ($currentQuestion !== null) $currentQuestion['gambar_stimulus'] = $val;
                        else $pendingStimulusImage = $val;
                    }
                }
                $readingTarget = 'gambar';
                continue;
            }

            if ($currentQuestion === null) {
                if (preg_match('/^(?:uploads\/|https?:\/\/)[^\s]+\.(?:png|jpe?g|gif|webp|svg)/i', $lineTrim, $imgDirect)) {
                    $pendingStimulusImage = trim($imgDirect[0]);
                }
                continue;
            }

            if (preg_match('/^(?:LINK\s*VIDEO(?:\s*YT)?|VIDEO\s*STIMULUS|STIMULUS\s*VIDEO|YOUTUBE|VIDEO)\s*[:=]\s*(.*)$/iu', $lineTrim, $vMatches)) {
                $rawVal = trim($vMatches[1]);
                $isGuide = preg_match('/^(?:\(|\[|\<)?(?:kosongkan|contoh)/iu', $rawVal) || (trim($rawVal, '()[]<> :-') === '');
                if (!$isGuide && preg_match('/https?:\/\/[^\s]+/i', $rawVal, $uMatches)) {
                    $cleanUrl = trim($uMatches[0], '(),; "');
                    $currentQuestion['link_video'] = $cleanUrl;
                }
                $readingTarget = 'video';
                continue;
            }

            if (preg_match('/^PEDOMAN\s*PENSKORAN(?:\s*[\/\&]\s*KUNCI\s*JAWABAN)?\s*[:=]\s*(.*)$/iu', $lineTrim, $pMatches)) {
                $currentQuestion['tipe_soal'] = 'Essay / Uraian';
                $val = trim($pMatches[1]);
                $currentQuestion['pedoman'] = $val;
                $currentQuestion['rubrik']['pedoman_penskoran'] = $val;
                $readingTarget = 'pedoman';
                continue;
            }

            if (preg_match('/^RUBRIK\s*PENILAIAN\s*LENGKAP/iu', $lineTrim)) {
                $currentQuestion['tipe_soal'] = 'Essay / Uraian';
                continue;
            }

            if (preg_match('/^(?:[•\-\*]\s*)?ASPEK\s*KOMPETENSI\s*[:=]\s*(.*)$/iu', $lineTrim, $aMatches)) {
                $currentQuestion['tipe_soal'] = 'Essay / Uraian';
                $currentQuestion['rubrik']['aspek_kompetensi'] = trim($aMatches[1]);
                $readingTarget = 'aspek';
                continue;
            }

            if (preg_match('/^(?:[•\-\*]\s*)?KRITERIA\s*SKOR\s*MAKSIMAL\s*[:=]\s*(.*)$/iu', $lineTrim, $kmMatches)) {
                $currentQuestion['tipe_soal'] = 'Essay / Uraian';
                $currentQuestion['rubrik']['kriteria_skor_maksimal'] = trim($kmMatches[1]);
                $readingTarget = 'kriteria_maksimal';
                continue;
            }

            if (preg_match('/^(?:[•\-\*]\s*)?KRITERIA\s*SKOR\s*SEDANG\s*[:=]\s*(.*)$/iu', $lineTrim, $ksMatches)) {
                $currentQuestion['tipe_soal'] = 'Essay / Uraian';
                $currentQuestion['rubrik']['kriteria_skor_sedang'] = trim($ksMatches[1]);
                $readingTarget = 'kriteria_sedang';
                continue;
            }

            if (preg_match('/^(?:[•\-\*]\s*)?KRITERIA\s*SKOR\s*MINIMAL\s*[:=]\s*(.*)$/iu', $lineTrim, $kminMatches)) {
                $currentQuestion['tipe_soal'] = 'Essay / Uraian';
                $currentQuestion['rubrik']['kriteria_skor_minimal'] = trim($kminMatches[1]);
                $readingTarget = 'kriteria_minimal';
                continue;
            }

            if (preg_match('/^(?:[•\-\*]\s*)?KRITERIA\s*SKOR\s*NOL\s*[:=]\s*(.*)$/iu', $lineTrim, $knMatches)) {
                $currentQuestion['tipe_soal'] = 'Essay / Uraian';
                $currentQuestion['rubrik']['kriteria_skor_nol'] = trim($knMatches[1]);
                $readingTarget = 'kriteria_nol';
                continue;
            }

            if (preg_match('/^(?:BOBOT|SKOR|POINT|POIN)\s*[:=]\s*([0-9\.]+)/i', $lineTrim, $bMatches)) {
                $bobotVal = (float)$bMatches[1];
                $currentQuestion['bobot'] = $bobotVal;
                $currentQuestion['rubrik']['skor_maksimal'] = $bobotVal;
                continue;
            }

            if (preg_match('/^(?:KUNCI|KUNCI\s*JAWABAN|JAWABAN|ANSWER|KEY)\s*[:=]\s*([A-Ea-e])(?:\s|$)/i', $lineTrim, $kMatches)) {
                $currentQuestion['kunci'] = strtoupper(trim($kMatches[1]));
                continue;
            }

            if ($currentQuestion['tipe_soal'] !== 'Essay / Uraian' && preg_match('/^([A-Ea-e])[\.\)]\s*(.+)$/u', $lineTrim, $oMatches)) {
                $kodeOpsi = strtoupper($oMatches[1]);
                $teksOpsi = trim($oMatches[2]);
                $currentQuestion['opsi'][$kodeOpsi] = $teksOpsi;
                $readingTarget = 'opsi_' . $kodeOpsi;
                continue;
            }

            if ($readingTarget === 'pertanyaan') {
                $currentQuestion['pertanyaan'] .= "\n" . $lineTrim;
            } elseif ($readingTarget === 'gambar') {
                if (preg_match('/https?:\/\/[^\s]+/i', $lineTrim, $urlM)) $currentQuestion['gambar_stimulus'] = trim($urlM[0]);
            } elseif ($readingTarget === 'video') {
                if (preg_match('/https?:\/\/[^\s]+/i', $lineTrim, $urlM)) $currentQuestion['link_video'] = trim($urlM[0]);
            } elseif ($readingTarget === 'pedoman') {
                $currentQuestion['pedoman'] .= "\n" . $lineTrim;
                $currentQuestion['rubrik']['pedoman_penskoran'] .= "\n" . $lineTrim;
            } elseif ($readingTarget === 'aspek') {
                $currentQuestion['rubrik']['aspek_kompetensi'] .= " " . $lineTrim;
            } elseif ($readingTarget === 'kriteria_maksimal') {
                $currentQuestion['rubrik']['kriteria_skor_maksimal'] .= " " . $lineTrim;
            } elseif ($readingTarget === 'kriteria_sedang') {
                $currentQuestion['rubrik']['kriteria_skor_sedang'] .= " " . $lineTrim;
            } elseif ($readingTarget === 'kriteria_minimal') {
                $currentQuestion['rubrik']['kriteria_skor_minimal'] .= " " . $lineTrim;
            } elseif ($readingTarget === 'kriteria_nol') {
                $currentQuestion['rubrik']['kriteria_skor_nol'] .= " " . $lineTrim;
            } elseif (strpos($readingTarget, 'opsi_') === 0) {
                $code = substr($readingTarget, 5);
                if (isset($currentQuestion['opsi'][$code])) $currentQuestion['opsi'][$code] .= " " . $lineTrim;
            }
        }

        if ($currentQuestion !== null && !empty($currentQuestion['pertanyaan'])) {
            $parsedQuestions[] = $currentQuestion;
        }

        if (empty($parsedQuestions)) {
            return [
                'success' => false,
                'message' => 'Tidak ditemukan butir soal yang sesuai di dalam dokumen.'
            ];
        }

        return [
            'success'        => true,
            'detected_kelas' => $detectedKelas,
            'detected_mapel' => $detectedMapel,
            'detected_judul' => $detectedJudul,
            'kategori'       => $kategori,
            'id_asesmen'     => $asesmenIdReq,
            'clear_existing' => $clearExisting,
            'matched_kmp'    => $matchedKmp,
            'questions'      => $parsedQuestions
        ];
    }

    if ($act === 'preview_docs') {
        $kategoriReq  = trim($_POST['kategori'] ?? '');
        $idAsesmenReq = (int)($_POST['asesmen_id'] ?? 0);
        $clearOld     = !empty($_POST['clear_existing']);

        if (!isset($_FILES['file_docs']) || $_FILES['file_docs']['error'] !== UPLOAD_ERR_OK) {
            sendCleanJson(['success' => false, 'message' => 'Silakan pilih berkas dokumen Word (.docx) yang valid.']);
        }

        $uploadedFile = $_FILES['file_docs']['tmp_name'];
        $fileName     = $_FILES['file_docs']['name'];
        $ext          = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($ext !== 'docx') {
            sendCleanJson(['success' => false, 'message' => 'Format berkas harus berekstensi .docx (Word / Google Docs Export).']);
        }

        $parseRes = parseDocxFileQuestions($uploadedFile, $pdo, $kategoriReq, $idAsesmenReq, $clearOld);
        if (!$parseRes['success']) {
            sendCleanJson($parseRes);
        }

        $_SESSION['pending_docs_import'] = $parseRes;

        sendCleanJson([
            'success'        => true,
            'message'        => 'Berkas berhasil dipindai! Silakan periksa pratinjau soal sebelum disimpan.',
            'detected_kelas' => $parseRes['detected_kelas'],
            'detected_mapel' => $parseRes['detected_mapel'],
            'detected_judul' => $parseRes['detected_judul'],
            'kategori'       => $parseRes['kategori'],
            'matched_kmp'    => $parseRes['matched_kmp'],
            'total_found'    => count($parseRes['questions']),
            'questions'      => $parseRes['questions']
        ]);
    }

    if ($act === 'commit_import_docs') {
        try {
            $importData = $_SESSION['pending_docs_import'] ?? null;

            if (!$importData && !empty($_POST['questions_json'])) {
                $decoded = json_decode($_POST['questions_json'], true);
                if ($decoded && is_array($decoded)) $importData = $decoded;
            }

            if (!$importData || empty($importData['questions'])) {
                sendCleanJson(['success' => false, 'message' => 'Sesi pratinjau soal telah kedaluwarsa atau kosong.']);
            }

            $kategori        = $importData['kategori'] ?? 'sumatif';
            $idAsesmen       = (int)($importData['id_asesmen'] ?? 0);
            $clearOld        = !empty($importData['clear_existing']);
            $matchedKmp      = $importData['matched_kmp'] ?? null;
            $detectedJudul   = $importData['detected_judul'] ?? '';
            $parsedQuestions = $importData['questions'];

            $tblMeta     = getAssessmentSoalTables($kategori);
            $kategori    = $tblMeta['kategori'];
            $masterTable = $tblMeta['master'];
            $soalTable   = $tblMeta['soal'];
            $fkCol       = $tblMeta['fk'];
            $opsiTable   = $tblMeta['opsi'];
            $rubrikTable = $tblMeta['rubrik'];

            if ($idAsesmen <= 0) {
                if (!$matchedKmp) {
                    sendCleanJson([
                        'success' => false,
                        'message' => 'Gagal mendeteksi Kelas & Mata Pelajaran dari tabel identitas dokumen.'
                    ]);
                }

                $idKmp     = (int)$matchedKmp['id'];
                $idGuru    = !empty($matchedKmp['id_guru']) ? (int)$matchedKmp['id_guru'] : null;
                $namaKelas = $matchedKmp['nama_kelas'];
                $namaMapel = $matchedKmp['nama_mata_pelajaran'];

                $stmtFind = $pdo->prepare("SELECT id FROM {$masterTable} WHERE id_kelas_mata_pelajaran = ? ORDER BY id DESC LIMIT 1");
                $stmtFind->execute([$idKmp]);
                $existingAssessId = $stmtFind->fetchColumn();

                if ($existingAssessId) {
                    $idAsesmen = (int)$existingAssessId;
                } else {
                    $stmtMa = $pdo->prepare("
                        SELECT id, id_cp, id_tp, id_atp, id_itp, pertemuan_ke, id_guru
                        FROM modul_ajar
                        WHERE id_kelas_mata_pelajaran = ?
                        ORDER BY pertemuan_ke ASC, id ASC
                        LIMIT 1
                    ");
                    $stmtMa->execute([$idKmp]);
                    $maInfo = $stmtMa->fetch(PDO::FETCH_ASSOC);

                    $idModulAjar = $maInfo ? (int)$maInfo['id'] : null;
                    $idCp        = $maInfo && !empty($maInfo['id_cp']) ? (int)$maInfo['id_cp'] : null;
                    $idTp        = $maInfo && !empty($maInfo['id_tp']) ? (int)$maInfo['id_tp'] : null;
                    $idAtp       = $maInfo && !empty($maInfo['id_atp']) ? (int)$maInfo['id_atp'] : null;
                    $idItp       = $maInfo && !empty($maInfo['id_itp']) ? (int)$maInfo['id_itp'] : null;
                    $pertemuan   = $maInfo && !empty($maInfo['pertemuan_ke']) ? (int)$maInfo['pertemuan_ke'] : 1;
                    if (!$idGuru && $maInfo && !empty($maInfo['id_guru'])) {
                        $idGuru = (int)$maInfo['id_guru'];
                    }

                    if ($idGuru) {
                        $stmtUserCheck = $pdo->prepare("SELECT id_user FROM guru WHERE id = ? LIMIT 1");
                        $stmtUserCheck->execute([$idGuru]);
                        $resolvedUserId = $stmtUserCheck->fetchColumn();
                        if ($resolvedUserId) {
                            $idGuru = (int)$resolvedUserId;
                        }
                    }

                    $judulBaru = !empty($detectedJudul) ? $detectedJudul : (
                        $kategori === 'remedial'
                            ? ('Program Remedial / Pengayaan ' . $namaMapel)
                            : ('Asesmen ' . ucfirst($kategori) . ' ' . $namaMapel)
                    );

                    if ($kategori === 'formatif') {
                        $insStmt = $pdo->prepare("
                            INSERT INTO asesmen_formatif (
                                id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_cp, id_tp, id_atp, id_itp,
                                pertemuan_ke, judul_asesmen, teknik_asesmen, tujuan_asesmen, petunjuk_pengerjaan, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Tes Tertulis', 'Mengukur capaian pemahaman peserta didik.', 'Pilihlah salah satu jawaban yang paling tepat.', 'Aktif')
                        ");
                        $insStmt->execute([$idKmp, $idGuru, $idModulAjar, $idCp, $idTp, $idAtp, $idItp, $pertemuan, $judulBaru]);
                    } elseif ($kategori === 'diagnostik') {
                        $insStmt = $pdo->prepare("
                            INSERT INTO asesmen_diagnostik (
                                id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_cp, id_tp, id_atp, id_itp,
                                pertemuan_ke, judul_asesmen, jenis_diagnostik, tujuan_asesmen, petunjuk_pengerjaan, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Kognitif', 'Pemetaan kesiapan belajar peserta didik.', 'Pilihlah salah satu jawaban yang paling tepat.', 'Aktif')
                        ");
                        $insStmt->execute([$idKmp, $idGuru, $idModulAjar, $idCp, $idTp, $idAtp, $idItp, $pertemuan, $judulBaru]);
                    } elseif ($kategori === 'remedial') {
                        $jenisProgram = (stripos($judulBaru, 'pengayaan') !== false) ? 'Pengayaan' : 'Remedial';
                        $insStmt = $pdo->prepare("
                            INSERT INTO pengayaan_remedial (
                                id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_cp, id_tp, id_atp, id_itp,
                                pertemuan_ke, kategori, judul_program, kktp_minimal, bentuk_kegiatan, petunjuk_pelaksanaan, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 75.00, 'Tes Ulang / Tugas Terbimbing', 'Kerjakan soal tindak lanjut berikut sesuai petunjuk guru.', 'Aktif')
                        ");
                        $insStmt->execute([$idKmp, $idGuru, $idModulAjar, $idCp, $idTp, $idAtp, $idItp, $pertemuan, $jenisProgram, $judulBaru]);
                    } else {
                        $insStmt = $pdo->prepare("
                            INSERT INTO asesmen_sumatif (
                                id_kelas_mata_pelajaran, id_guru, id_modul_ajar, id_cp, id_tp, id_atp, id_itp,
                                pertemuan_ke, judul_asesmen, jenis_sumatif, kktp, durasi_menit, petunjuk_pengerjaan, status
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Sumatif Lingkup Materi', 75.00, 60, 'Pilihlah salah satu jawaban yang paling tepat.', 'Aktif')
                        ");
                        $insStmt->execute([$idKmp, $idGuru, $idModulAjar, $idCp, $idTp, $idAtp, $idItp, $pertemuan, $judulBaru]);
                    }
                    $idAsesmen = (int)$pdo->lastInsertId();

                    if ($kategori === 'sumatif' && $idKmp > 0) {
                        $pdo->prepare("INSERT IGNORE INTO asesmen_sumatif_kelas (id_asesmen_sumatif, id_kelas_mata_pelajaran) VALUES (?, ?)")
                            ->execute([$idAsesmen, $idKmp]);
                    }
                }
            } else {
                if ($kategori === 'sumatif') {
                    $stmtAssess = $pdo->prepare("
                        SELECT COALESCE(a.id_kelas_mata_pelajaran, ask.id_kelas_mata_pelajaran) AS id_kelas_mata_pelajaran,
                               k.id AS id_kelas, k.nama_kelas, mp.nama_mata_pelajaran
                        FROM asesmen_sumatif a
                        LEFT JOIN asesmen_sumatif_kelas ask ON ask.id_asesmen_sumatif = a.id
                        LEFT JOIN kelas_mata_pelajaran kmp ON COALESCE(a.id_kelas_mata_pelajaran, ask.id_kelas_mata_pelajaran) = kmp.id
                        LEFT JOIN kelas k ON kmp.id_kelas = k.id
                        LEFT JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                        WHERE a.id = ?
                        LIMIT 1
                    ");
                } else {
                    $stmtAssess = $pdo->prepare("
                        SELECT a.id_kelas_mata_pelajaran, kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran
                        FROM {$masterTable} a
                        JOIN kelas_mata_pelajaran kmp ON a.id_kelas_mata_pelajaran = kmp.id
                        JOIN kelas k ON kmp.id_kelas = k.id
                        JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
                        WHERE a.id = ?
                    ");
                }
                $stmtAssess->execute([$idAsesmen]);
                $currA = $stmtAssess->fetch(PDO::FETCH_ASSOC);
                if ($currA) {
                    $matchedKmp = ['id_kelas' => $currA['id_kelas'], 'nama_kelas' => $currA['nama_kelas'], 'nama_mata_pelajaran' => $currA['nama_mata_pelajaran']];
                    $idKmp = (int)$currA['id_kelas_mata_pelajaran'];
                }
            }

            $mStmt = $pdo->prepare("SELECT * FROM {$masterTable} WHERE id = ?");
            $mStmt->execute([$idAsesmen]);
            $master = $mStmt->fetch(PDO::FETCH_ASSOC);

            if (!$master) {
                sendCleanJson(['success' => false, 'message' => 'Paket asesmen target tidak ditemukan.']);
            }

            $idKmp = (int)($master['id_kelas_mata_pelajaran'] ?? 0);
            if ($kategori === 'sumatif' && empty($idKmp)) {
                $stmtFindKmp = $pdo->prepare("SELECT id_kelas_mata_pelajaran FROM asesmen_sumatif_kelas WHERE id_asesmen_sumatif = ? LIMIT 1");
                $stmtFindKmp->execute([$idAsesmen]);
                $idKmp = (int)$stmtFindKmp->fetchColumn();
            }
            $idModulAjar = !empty($master['id_modul_ajar']) ? (int)$master['id_modul_ajar'] : null;
            $idCp        = !empty($master['id_cp']) ? (int)$master['id_cp'] : null;
            $idTp        = !empty($master['id_tp']) ? (int)$master['id_tp'] : null;
            $idAtp       = !empty($master['id_atp']) ? (int)$master['id_atp'] : null;
            $idItp       = !empty($master['id_itp']) ? (int)$master['id_itp'] : null;
            $pertemuan   = !empty($master['pertemuan_ke']) ? (int)$master['pertemuan_ke'] : 1;

            if (!$idModulAjar) {
                $stmtMa = $pdo->prepare("SELECT id, id_cp, id_tp, id_atp, id_itp, pertemuan_ke FROM modul_ajar WHERE id_kelas_mata_pelajaran = ? LIMIT 1");
                $stmtMa->execute([$idKmp]);
                $maRow = $stmtMa->fetch(PDO::FETCH_ASSOC);
                if ($maRow) {
                    $idModulAjar = (int)$maRow['id'];
                    $idCp        = $idCp ?: (!empty($maRow['id_cp']) ? (int)$maRow['id_cp'] : null);
                    $idTp        = $idTp ?: (!empty($maRow['id_tp']) ? (int)$maRow['id_tp'] : null);
                    $idAtp       = $idAtp ?: (!empty($maRow['id_atp']) ? (int)$maRow['id_atp'] : null);
                    $idItp       = $idItp ?: (!empty($maRow['id_itp']) ? (int)$maRow['id_itp'] : null);
                    $pertemuan   = !empty($maRow['pertemuan_ke']) ? (int)$maRow['pertemuan_ke'] : $pertemuan;
                }
            }

            $hasLinkVideo = checkOrAddLinkVideoColumn($pdo, $soalTable);

            $pdo->beginTransaction();

            if ($clearOld) {
                $delStmt = $pdo->prepare("DELETE FROM {$soalTable} WHERE {$fkCol} = ?");
                $delStmt->execute([$idAsesmen]);
                $startNo = 1;
            } else {
                $maxNoStmt = $pdo->prepare("SELECT COALESCE(MAX(nomor_soal), 0) FROM {$soalTable} WHERE {$fkCol} = ?");
                $maxNoStmt->execute([$idAsesmen]);
                $startNo = (int)$maxNoStmt->fetchColumn() + 1;
            }

            $successCount = 0;
            $failedCount  = 0;
            $logs         = [];
            $currentNo    = $startNo;

            if ($hasLinkVideo) {
                $insertSoalPGStmt = $pdo->prepare("
                    INSERT INTO {$soalTable} (
                        {$fkCol}, id_kelas_mata_pelajaran, id_modul_ajar, pertemuan_ke,
                        id_cp, id_tp, id_atp, id_itp, nomor_soal, tipe_soal,
                        pertanyaan, gambar_stimulus, link_video, tingkat_kesulitan, level_kognitif, bobot_skor
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pilihan Ganda', ?, ?, ?, 'Sedang', 'C3 - Mengaplikasikan', ?)
                ");

                $insertSoalEssayStmt = $pdo->prepare("
                    INSERT INTO {$soalTable} (
                        {$fkCol}, id_kelas_mata_pelajaran, id_modul_ajar, pertemuan_ke,
                        id_cp, id_tp, id_atp, id_itp, nomor_soal, tipe_soal,
                        pertanyaan, gambar_stimulus, link_video, tingkat_kesulitan, level_kognitif, bobot_skor
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Essay / Uraian', ?, ?, ?, 'Sedang', 'C4 - Menganalisis', ?)
                ");
            } else {
                $insertSoalPGStmt = $pdo->prepare("
                    INSERT INTO {$soalTable} (
                        {$fkCol}, id_kelas_mata_pelajaran, id_modul_ajar, pertemuan_ke,
                        id_cp, id_tp, id_atp, id_itp, nomor_soal, tipe_soal,
                        pertanyaan, gambar_stimulus, tingkat_kesulitan, level_kognitif, bobot_skor
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pilihan Ganda', ?, ?, 'Sedang', 'C3 - Mengaplikasikan', ?)
                ");

                $insertSoalEssayStmt = $pdo->prepare("
                    INSERT INTO {$soalTable} (
                        {$fkCol}, id_kelas_mata_pelajaran, id_modul_ajar, pertemuan_ke,
                        id_cp, id_tp, id_atp, id_itp, nomor_soal, tipe_soal,
                        pertanyaan, gambar_stimulus, tingkat_kesulitan, level_kognitif, bobot_skor
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Essay / Uraian', ?, ?, 'Sedang', 'C4 - Menganalisis', ?)
                ");
            }

            $insertOpsiStmt = $pdo->prepare("
                INSERT INTO {$opsiTable} (
                    id_soal, kode_opsi, teks_opsi, is_kunci_jawaban, penjelasan_kunci
                ) VALUES (?, ?, ?, ?, ?)
            ");

            $insertRubrikStmt = $pdo->prepare("
                INSERT INTO {$rubrikTable} (
                    id_soal, id_itp, aspek_kompetensi, pedoman_penskoran,
                    skor_maksimal, kriteria_skor_maksimal, kriteria_skor_sedang,
                    kriteria_skor_minimal, kriteria_skor_nol
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    aspek_kompetensi = VALUES(aspek_kompetensi),
                    pedoman_penskoran = VALUES(pedoman_penskoran),
                    skor_maksimal = VALUES(skor_maksimal),
                    kriteria_skor_maksimal = VALUES(kriteria_skor_maksimal),
                    kriteria_skor_sedang = VALUES(kriteria_skor_sedang),
                    kriteria_skor_minimal = VALUES(kriteria_skor_minimal),
                    kriteria_skor_nol = VALUES(kriteria_skor_nol)
            ");

            foreach ($parsedQuestions as $q) {
                $pertanyaan     = trim($q['pertanyaan'] ?? '');
                $tipeSoal       = $q['tipe_soal'] ?? 'Pilihan Ganda';
                $bobot          = (!empty($q['bobot']) && (float)$q['bobot'] > 0) ? (float)$q['bobot'] : 10.00;
                $gambarStimulus = !empty($q['gambar_stimulus']) ? trim($q['gambar_stimulus']) : null;
                $linkVideo      = !empty($q['link_video']) ? trim($q['link_video']) : null;

                if (empty($pertanyaan)) {
                    $failedCount++;
                    $logs[] = [
                        'nomor'   => $q['nomor_doc'] ?? $currentNo,
                        'status'  => 'failed',
                        'message' => 'Soal ' . ($q['nomor_doc'] ?? $currentNo) . ': Dilewati karena teks pertanyaan kosong.'
                    ];
                    continue;
                }

                if ($tipeSoal === 'Essay / Uraian') {
                    $essayParams = $hasLinkVideo
                        ? [$idAsesmen, $idKmp, $idModulAjar, $pertemuan, $idCp, $idTp, $idAtp, $idItp, $currentNo, $pertanyaan, $gambarStimulus, $linkVideo, $bobot]
                        : [$idAsesmen, $idKmp, $idModulAjar, $pertemuan, $idCp, $idTp, $idAtp, $idItp, $currentNo, $pertanyaan, $gambarStimulus, $bobot];
                    $insertSoalEssayStmt->execute($essayParams);
                    $idSoalBaru = (int)$pdo->lastInsertId();

                    $rubrik = $q['rubrik'] ?? [];
                    $aspek = !empty($rubrik['aspek_kompetensi']) ? trim($rubrik['aspek_kompetensi']) : 'Pemahaman Konsep & Penalaran';
                    $pedoman = !empty($rubrik['pedoman_penskoran']) ? trim($rubrik['pedoman_penskoran']) : (!empty($q['pedoman']) ? trim($q['pedoman']) : 'Sesuai dengan ketepatan dan kelengkapan uraian jawaban.');
                    $skorMaks = $bobot;
                    $kriteriaMaks = !empty($rubrik['kriteria_skor_maksimal']) ? trim($rubrik['kriteria_skor_maksimal']) : 'Jawaban lengkap, mendalam, dan memenuhi seluruh kriteria kompetensi (Skor ' . $skorMaks . ').';
                    $kriteriaSedang = !empty($rubrik['kriteria_skor_sedang']) ? trim($rubrik['kriteria_skor_sedang']) : 'Jawaban cukup jelas dan benar namun uraian masih bersifat umum.';
                    $kriteriaMin = !empty($rubrik['kriteria_skor_minimal']) ? trim($rubrik['kriteria_skor_minimal']) : 'Hanya menyebutkan pokok pikiran singkat atau terdapat kekeliruan minor.';
                    $kriteriaNol = !empty($rubrik['kriteria_skor_nol']) ? trim($rubrik['kriteria_skor_nol']) : 'Jawaban tidak relevan dengan pertanyaan atau tidak menjawab sama sekali.';

                    $insertRubrikStmt->execute([
                        $idSoalBaru, $idItp, $aspek, $pedoman, $skorMaks,
                        $kriteriaMaks, $kriteriaSedang, $kriteriaMin, $kriteriaNol
                    ]);

                    $successCount++;
                    $logs[] = [
                        'nomor'   => $q['nomor_doc'] ?? $currentNo,
                        'status'  => 'success',
                        'message' => 'Soal Essay No. ' . ($q['nomor_doc'] ?? $currentNo) . ' berhasil disimpan sebagai No #' . $currentNo . ' (Bobot: ' . $bobot . ', Rubrik Lengkap tersimpan).'
                    ];
                    $currentNo++;
                } else {
                    $opsiList = $q['opsi'] ?? [];
                    $kunci    = strtoupper(trim($q['kunci'] ?? ''));

                    if (count($opsiList) < 2) {
                        $failedCount++;
                        $logs[] = [
                            'nomor'   => $q['nomor_doc'] ?? $currentNo,
                            'status'  => 'failed',
                            'message' => 'Soal PG ' . ($q['nomor_doc'] ?? $currentNo) . ': Dilewati karena opsi jawaban kurang dari 2 pilihan.'
                        ];
                        continue;
                    }

                    if (empty($kunci) || !isset($opsiList[$kunci])) {
                        $failedCount++;
                        $logs[] = [
                            'nomor'   => $q['nomor_doc'] ?? $currentNo,
                            'status'  => 'failed',
                            'message' => 'Soal PG ' . ($q['nomor_doc'] ?? $currentNo) . ': Kunci jawaban (' . ($kunci ?: 'Kosong') . ') tidak ditemukan pada daftar opsi.'
                        ];
                        continue;
                    }

                    $pgParams = $hasLinkVideo
                        ? [$idAsesmen, $idKmp, $idModulAjar, $pertemuan, $idCp, $idTp, $idAtp, $idItp, $currentNo, $pertanyaan, $gambarStimulus, $linkVideo, $bobot]
                        : [$idAsesmen, $idKmp, $idModulAjar, $pertemuan, $idCp, $idTp, $idAtp, $idItp, $currentNo, $pertanyaan, $gambarStimulus, $bobot];
                    $insertSoalPGStmt->execute($pgParams);
                    $idSoalBaru = (int)$pdo->lastInsertId();

                    foreach ($opsiList as $kode => $teks) {
                        $isKunci = ($kode === $kunci) ? 1 : 0;
                        $insertOpsiStmt->execute([
                            $idSoalBaru, $kode, $teks, $isKunci, null
                        ]);
                    }

                    $successCount++;
                    $logs[] = [
                        'nomor'   => $q['nomor_doc'] ?? $currentNo,
                        'status'  => 'success',
                        'message' => 'Soal PG No. ' . ($q['nomor_doc'] ?? $currentNo) . ' berhasil disimpan sebagai No #' . $currentNo . ' (' . count($opsiList) . ' opsi, Kunci: ' . $kunci . ').'
                    ];
                    $currentNo++;
                }
            }

            $pdo->commit();
            unset($_SESSION['pending_docs_import']);

            $detectedKelasId = $matchedKmp['id_kelas'] ?? 0;
            $namaKelasText = $matchedKmp['nama_kelas'] ?? 'Kelas';
            $namaMapelText = $matchedKmp['nama_mata_pelajaran'] ?? 'Mata Pelajaran';

            sendCleanJson([
                'success'       => true,
                'message'       => "Import berhasil disimpan! {$successCount} butir soal dimasukkan untuk {$namaKelasText} - {$namaMapelText}, {$failedCount} gagal/dilewati.",
                'total_found'   => count($parsedQuestions),
                'success_count' => $successCount,
                'failed_count'  => $failedCount,
                'kelas_id'      => $detectedKelasId,
                'asesmen_id'    => $idAsesmen,
                'kategori'      => $kategori,
                'logs'          => $logs
            ]);
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sendCleanJson(['success' => false, 'message' => 'Gagal memproses simpan soal ke database: ' . $e->getMessage()]);
        }
    }

    if ($act === 'delete_question') {
        $kategori = trim($_POST['kategori'] ?? 'sumatif');
        $idSoal   = (int)($_POST['id_soal'] ?? 0);

        if ($idSoal <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID Soal tidak valid.']);
            exit;
        }

        $tblMeta   = getAssessmentSoalTables($kategori);
        $soalTable = $tblMeta['soal'];

        try {
            $stmt = $pdo->prepare("DELETE FROM {$soalTable} WHERE id = ?");
            $stmt->execute([$idSoal]);
            echo json_encode(['success' => true, 'message' => 'Butir soal berhasil dihapus.']);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus soal: ' . $e->getMessage()]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Aksi API tidak valid.']);
    exit;
}

/* ==============================================================================
 * 2. MASTER DATA UNTUK VIEW
 * ============================================================================== */
$kelasList = $pdo->query("SELECT id, nama_kelas, tahun_pelajaran FROM kelas ORDER BY nama_kelas ASC")->fetchAll(PDO::FETCH_ASSOC);

$cssVersion = file_exists(BASE_PATH . '/assets/css/admin-assessment-questions.css') ? filemtime(BASE_PATH . '/assets/css/admin-assessment-questions.css') : '1.1';
$extraHead = '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/admin-assessment-questions.css?v=' . $cssVersion . '">';

require_once BASE_PATH . '/includes/header.php';
loadCentralTemplates(['modal', 'calendar', 'table']);
?>

<div class="ilmuna-quest-app" id="appQuestions" data-ajax="<?= BASE_URL ?>/dashboard/admin/assessment-questions.php">

    <!-- ======================================================================
         SELECTOR CARD — 2 TOMBOL AKSI DIGABUNG DI DALAM CARD INI
         (Navigasi Step 1/2/3 di atas, tombol aksi di footer card bawah)
         ====================================================================== -->
    <div class="ilmuna-quest-selector-card">

        <!-- Selector Grid: Step 1/2/3 + Search + Toggle Kunci -->
        <div class="ilmuna-quest-selector-grid">
            <div>
                <label class="ilmuna-quest-field-label" for="selectKelas">
                    <span class="badge-step">1</span>
                    <span>Pilih Rombel Kelas <span class="req">*</span></span>
                </label>
                <select class="ilmuna-quest-select" id="selectKelas">
                    <option value="">-- Pilih Rombel Kelas Terlebih Dahulu --</option>
                    <?php foreach ($kelasList as $k): ?>
                        <option value="<?= $k['id'] ?>"><?= htmlspecialchars($k['nama_kelas']) ?> (<?= htmlspecialchars($k['tahun_pelajaran']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="ilmuna-quest-field-label" for="selectMapel">
                    <span class="badge-step">2</span>
                    <span>Pilih Mata Pelajaran <span class="req">*</span></span>
                </label>
                <select class="ilmuna-quest-select" id="selectMapel" disabled>
                    <option value="">-- Pilih Kelas Terlebih Dahulu --</option>
                </select>
            </div>

            <div>
                <label class="ilmuna-quest-field-label" for="selectAsesmen">
                    <span class="badge-step">3</span>
                    <span>Pilih Paket Asesmen <span class="req">*</span></span>
                </label>
                <select class="ilmuna-quest-select" id="selectAsesmen" disabled>
                    <option value="">-- Pilih Mapel Terlebih Dahulu --</option>
                </select>
            </div>

            <div>
                <label class="ilmuna-quest-field-label" for="inputSearchQuest">
                    <iconify-icon icon="solar:magnifer-bold" width="15"></iconify-icon>
                    <span>Cari Soal</span>
                </label>
                <input type="text" class="ilmuna-quest-select" id="inputSearchQuest" placeholder="Ketik kata kunci soal..." disabled>
            </div>

            <div>
                <label class="ilmuna-quest-field-label" style="visibility: hidden;">Sensor</label>
                <button type="button" class="ilmuna-btn-toggle-key" id="btnToggleSecretKey" title="Secara default kunci jawaban disembunyikan.">
                    <iconify-icon icon="solar:eye-closed-bold" width="18" id="iconEyeKey"></iconify-icon>
                    <span id="textToggleKey">Kunci: Tersembunyi (Hide)</span>
                </button>
            </div>
        </div>

        <!-- Action Buttons Footer — Digabung dalam card yang sama -->
        <div class="ilmuna-quest-card-actions" style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; margin-top: 16px; padding-top: 14px; border-top: 1px dashed #E2E8F0;">

            <!-- Tombol Download Template DOCS — TEAL FILLED -->
            <button type="button"
                id="btnDownloadTemplate"
                title="Unduh Template Format Soal Word (.docx) Ber-Kotak Identitas"
                style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; border: none; cursor: pointer; font-size: 13.5px; font-weight: 700; background: linear-gradient(135deg, #0D7A87 0%, #0A6B75 100%); color: #FFFFFF; box-shadow: 0 4px 12px rgba(13, 122, 135, 0.28); transition: all 0.2s ease;"
                onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 16px rgba(13, 122, 135, 0.35)';"
                onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(13, 122, 135, 0.28)';">
                <iconify-icon icon="solar:document-add-bold" width="18" style="color: #FFFFFF;"></iconify-icon>
                <span style="color: #FFFFFF;">Download Template DOCS</span>
            </button>

            <!-- Tombol Import Soal (.docx) — GOLD FILLED -->
            <button type="button"
                id="btnOpenImportModal"
                title="Import Butir Soal dari Dokumen Word (.docx)"
                style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 10px; border: none; cursor: pointer; font-size: 13.5px; font-weight: 700; background: linear-gradient(135deg, #F59E0B 0%, #D97706 100%); color: #FFFFFF; box-shadow: 0 4px 12px rgba(245, 158, 11, 0.32); transition: all 0.2s ease;"
                onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 18px rgba(245, 158, 11, 0.42)';"
                onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(245, 158, 11, 0.32)';">
                <iconify-icon icon="solar:upload-track-bold" width="18" style="color: #FFFFFF;"></iconify-icon>
                <span style="color: #FFFFFF;">Import Soal (.docx)</span>
            </button>
        </div>
    </div>

    <!-- SUMMARY BANNER ASESMEN AKTIF -->
    <div class="ilmuna-active-assess-banner" id="bannerActiveAssess" style="display: none;">
        <div class="ilmuna-active-assess-info">
            <span class="ilmuna-active-assess-badge ilmuna-active-assess-badge--sumatif" id="badgeKategoriAssess">SUMATIF CBT</span>
            <div>
                <div class="ilmuna-active-assess-title" id="titleActiveAssess">Judul Asesmen</div>
                <div class="ilmuna-active-assess-meta" id="metaActiveAssess">
                    <span>Kelas: <strong>-</strong></span> &bull;
                    <span>Mapel: <strong>-</strong></span> &bull;
                    <span>Guru: <strong>-</strong></span>
                </div>
            </div>
        </div>
        <div class="ilmuna-active-assess-stats">
            <div class="ilmuna-stat-pill">
                <iconify-icon icon="solar:checklist-bold" width="16" style="color: var(--ilmuna-teal);"></iconify-icon>
                <span>Total Butir: <strong id="statTotalSoal">0 Soal</strong></span>
            </div>
            <div class="key-hidden-indicator" id="indicatorKeyStatus">
                <iconify-icon icon="solar:shield-keyhole-bold" width="14"></iconify-icon>
                <span>Kunci Jawaban Disembunyikan</span>
            </div>
        </div>
    </div>

    <!-- CONTAINER BUTIR SOAL -->
    <div id="containerQuestionContent">
        <div class="ilmuna-quest-empty-state" id="boxInitialSelectNotice">
            <iconify-icon class="ilmuna-quest-empty-state__icon" icon="solar:square-academic-cap-2-outline"></iconify-icon>
            <h3>Silakan Pilih Rombel Kelas Terlebih Dahulu</h3>
            <p>Pilih kelas pada dropdown di atas untuk memuat paket asesmen, atau langsung klik tombol <strong>Import Soal (.docx)</strong> untuk mendeteksi Kelas & Mapel secara otomatis dari berkas Word Anda.</p>
        </div>

        <div class="ilmuna-quest-empty-state" id="boxLoadingNotice" style="display: none;">
            <span class="ilmuna-spinner" style="width: 32px; height: 32px; margin: 0 auto 12px; display: block;"></span>
            <h3>Memuat Butir Soal Asesmen...</h3>
            <p>Menyelaraskan data soal dan opsi pilihan ganda.</p>
        </div>

        <div class="ilmuna-quest-list" id="listQuestionsContainer" style="display: none;">
            <!-- Dynamic Item Cards rendered by JS -->
        </div>
    </div>

    <!-- MODAL 1: IMPORT BUTIR SOAL DARI WORD DOCS (.docx) -->
    <div class="ilmuna-quest-modal" id="modalImportDocs" style="display: none;">
        <div class="ilmuna-quest-modal__backdrop" data-close="modalImportDocs"></div>
        <div class="ilmuna-quest-modal__dialog" style="max-width: 580px;">
            <div class="ilmuna-quest-modal__header">
                <div class="ilmuna-quest-modal__title-group">
                    <span class="ilmuna-quest-modal__badge" style="background: rgba(13, 122, 135, 0.1); color: var(--ilmuna-teal); border: 1px solid rgba(13, 122, 135, 0.2);">
                        <iconify-icon icon="solar:upload-track-bold" width="14"></iconify-icon> Auto-Detect Relasi KMP
                    </span>
                    <h3 class="ilmuna-quest-modal__title">Import Soal dari Dokumen Word (.docx)</h3>
                </div>
                <button type="button" class="ilmuna-quest-modal__close" data-close="modalImportDocs">
                    <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
                </button>
            </div>

            <form id="formImportDocs" enctype="multipart/form-data">
                <div class="ilmuna-quest-modal__body">
                    <div style="background: rgba(13, 122, 135, 0.08); border: 1px solid rgba(13, 122, 135, 0.22); border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; font-size: 13px;">
                        <div style="font-weight: 700; color: var(--ilmuna-teal); display: flex; align-items: center; gap: 6px; margin-bottom: 3px;" id="lblImportTargetTitle">
                            <iconify-icon icon="solar:magic-stick-3-bold" width="16"></iconify-icon>
                            <span>Deteksi Otomatis Relasi Kelas & Mapel</span>
                        </div>
                        <div style="color: var(--ilmuna-slate-600); font-size: 12px; line-height: 1.45;" id="lblImportTargetMeta">
                            Sistem otomatis membaca Kelas & Mata Pelajaran dari tabel identitas di dalam berkas Word Anda.
                        </div>
                    </div>

                    <input type="hidden" name="action" value="preview_docs">
                    <input type="hidden" name="kategori" id="importKategori" value="">
                    <input type="hidden" name="asesmen_id" id="importAsesmenId" value="">

                    <label class="ilmuna-dropzone-box" for="inputDocsFile" id="dropzoneDocs">
                        <iconify-icon class="ilmuna-dropzone-icon" icon="solar:document-add-bold"></iconify-icon>
                        <div class="ilmuna-dropzone-title" id="dropzoneFileName">Klik untuk Pilih Dokumen Word (.docx)</div>
                        <div class="ilmuna-dropzone-desc">Mendukung file .docx berformat kotak identitas & soal berkotak</div>
                        <input type="file" id="inputDocsFile" name="file_docs" accept=".docx" style="display: none;" required>
                    </label>

                    <div style="display: flex; align-items: center; gap: 8px; margin-top: 10px;">
                        <input type="checkbox" id="chkClearOld" name="clear_existing" value="1" style="width: 16px; height: 16px; accent-color: var(--ilmuna-teal);">
                        <label for="chkClearOld" style="font-size: 13px; font-weight: 600; color: var(--ilmuna-slate-700); cursor: pointer;">
                            Kosongkan butir soal lama pada asesmen ini sebelum import
                        </label>
                    </div>

                    <div style="margin-top: 12px; font-size: 11.5px; color: var(--ilmuna-slate-500); line-height: 1.45;">
                        <iconify-icon icon="solar:info-circle-bold" style="color: var(--ilmuna-teal); vertical-align: middle;"></iconify-icon>
                        Gunakan berkas Word yang diunduh dari tombol <strong>Download Template DOCS</strong> agar kotak identitas Kelas, Mapel, dan kotak soal dikenali sempurna.
                    </div>
                </div>

                <div class="ilmuna-quest-modal__footer">
                    <button type="button" class="ilmuna-btn ilmuna-btn--ghost" data-close="modalImportDocs">Batal</button>
                    <button type="submit" class="ilmuna-btn ilmuna-btn--gold" id="btnSubmitImport">
                        <iconify-icon icon="solar:eye-bold" width="18"></iconify-icon>
                        Pratinjau &amp; Cek Soal
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 2: PRATINJAU & KONFIRMASI -->
    <div class="ilmuna-quest-modal" id="modalPreviewDocs" style="display: none;">
        <div class="ilmuna-quest-modal__backdrop" data-close="modalPreviewDocs"></div>
        <div class="ilmuna-quest-modal__dialog" style="max-width: 780px;">
            <div class="ilmuna-quest-modal__header">
                <div class="ilmuna-quest-modal__title-group">
                    <span class="ilmuna-quest-modal__badge" style="background: rgba(245, 158, 11, 0.2); color: #F59E0B; border: 1px solid rgba(245, 158, 11, 0.35);">
                        <iconify-icon icon="solar:eye-bold" width="14"></iconify-icon> Konfirmasi Sebelum Simpan
                    </span>
                    <h3 class="ilmuna-quest-modal__title">Pratinjau Butir Soal Terdeteksi</h3>
                </div>
                <button type="button" class="ilmuna-quest-modal__close" data-close="modalPreviewDocs">
                    <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
                </button>
            </div>

            <div class="ilmuna-quest-modal__body">
                <div style="background: linear-gradient(135deg, rgba(13, 122, 135, 0.08) 0%, rgba(29, 59, 94, 0.08) 100%); border: 1px solid rgba(13, 122, 135, 0.25); border-radius: 12px; padding: 14px 18px; margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                        <div>
                            <div style="font-size: 14px; font-weight: 800; color: var(--ilmuna-navy, #1D3B5E);" id="previewTargetClassMapel">-</div>
                            <div style="font-size: 12px; color: var(--ilmuna-slate-600, #475569); margin-top: 2px;" id="previewTargetMeta">-</div>
                        </div>
                        <div style="text-align: right;">
                            <span id="previewTotalBadge" style="font-size: 12.5px; font-weight: 800; padding: 4px 12px; border-radius: 20px; background: rgba(13, 122, 135, 0.15); color: #0D7A87; border: 1px solid rgba(13, 122, 135, 0.3);">
                                0 Soal Siap
                            </span>
                        </div>
                    </div>
                </div>

                <div style="font-size: 12.5px; font-weight: 700; color: var(--ilmuna-slate-700); margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
                    <span>Daftar Butir Soal &amp; Kunci Jawaban:</span>
                    <span style="font-size: 11px; font-weight: 600; color: #059669; display: flex; align-items: center; gap: 4px;">
                        <iconify-icon icon="solar:check-circle-bold" width="13"></iconify-icon> Kunci bertanda hijau
                    </span>
                </div>

                <div class="ilmuna-preview-quest-list" id="previewQuestionsList"></div>
            </div>

            <div class="ilmuna-quest-modal__footer" style="justify-content: space-between;">
                <button type="button" class="ilmuna-btn ilmuna-btn--ghost" data-close="modalPreviewDocs">
                    <iconify-icon icon="solar:arrow-left-bold" width="16"></iconify-icon>
                    Batal &amp; Kembali
                </button>
                <button type="button" class="ilmuna-btn ilmuna-btn--gold" id="btnConfirmCommitImport">
                    <iconify-icon icon="solar:check-circle-bold" width="18"></iconify-icon>
                    Ya, Simpan ke Bank Soal
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL 3: LAPORAN HASIL IMPORT -->
    <div class="ilmuna-quest-modal" id="modalImportResult" style="display: none;">
        <div class="ilmuna-quest-modal__backdrop" data-close="modalImportResult"></div>
        <div class="ilmuna-quest-modal__dialog" style="max-width: 600px;">
            <div class="ilmuna-quest-modal__header">
                <div class="ilmuna-quest-modal__title-group">
                    <span class="ilmuna-quest-modal__badge" style="background: rgba(16, 185, 129, 0.1); color: #059669; border: 1px solid rgba(16, 185, 129, 0.2);">
                        <iconify-icon icon="solar:check-circle-bold" width="14"></iconify-icon> Laporan Import
                    </span>
                    <h3 class="ilmuna-quest-modal__title">Ringkasan Hasil Import Soal DOCS</h3>
                </div>
                <button type="button" class="ilmuna-quest-modal__close" data-close="modalImportResult">
                    <iconify-icon icon="solar:close-circle-bold" width="22"></iconify-icon>
                </button>
            </div>

            <div class="ilmuna-quest-modal__body">
                <div class="ilmuna-import-result-summary">
                    <div class="ilmuna-import-stat-card ilmuna-import-stat-card--success">
                        <div class="ilmuna-import-stat-num" id="resCountSuccess">0</div>
                        <div class="ilmuna-import-stat-label">Soal Berhasil</div>
                    </div>
                    <div class="ilmuna-import-stat-card ilmuna-import-stat-card--failed">
                        <div class="ilmuna-import-stat-num" id="resCountFailed">0</div>
                        <div class="ilmuna-import-stat-label">Gagal / Dilewati</div>
                    </div>
                    <div class="ilmuna-import-stat-card ilmuna-import-stat-card--total">
                        <div class="ilmuna-import-stat-num" id="resCountTotal">0</div>
                        <div class="ilmuna-import-stat-label">Total Terdeteksi</div>
                    </div>
                </div>

                <div style="font-size: 12.5px; font-weight: 700; color: var(--ilmuna-slate-700); margin-bottom: 6px;">Rincian Log Ekstraksi:</div>
                <div class="ilmuna-import-logs-box" id="resLogsList"></div>
            </div>

            <div class="ilmuna-quest-modal__footer">
                <button type="button" class="ilmuna-btn ilmuna-btn--teal" data-close="modalImportResult" id="btnCloseAndReloadQuestions">
                    <iconify-icon icon="solar:check-read-bold" width="18"></iconify-icon>
                    Selesai & Tampilkan Soal
                </button>
            </div>
        </div>
    </div>

</div>

<!-- JavaScript Logic Pure Reactive SPA -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var ajaxUrl = window.location.href.split('?')[0].split('#')[0];
    var appElement = document.getElementById('appQuestions');
    if (appElement && appElement.getAttribute('data-ajax')) {
        var definedAjax = appElement.getAttribute('data-ajax');
        if (ajaxUrl.endsWith('.php') && !definedAjax.endsWith('.php')) definedAjax += '.php';
        ajaxUrl = definedAjax;
    }

    var selectKelas = document.getElementById('selectKelas');
    var selectMapel = document.getElementById('selectMapel');
    var selectAsesmen = document.getElementById('selectAsesmen');
    var inputSearch = document.getElementById('inputSearchQuest');
    var btnToggleKey = document.getElementById('btnToggleSecretKey');
    var textToggleKey = document.getElementById('textToggleKey');
    var iconEyeKey = document.getElementById('iconEyeKey');
    var indicatorKey = document.getElementById('indicatorKeyStatus');

    var bannerActive = document.getElementById('bannerActiveAssess');
    var badgeKategori = document.getElementById('badgeKategoriAssess');
    var titleActive = document.getElementById('titleActiveAssess');
    var metaActive = document.getElementById('metaActiveAssess');
    var statTotal = document.getElementById('statTotalSoal');

    var boxInitialNotice = document.getElementById('boxInitialSelectNotice');
    var boxLoadingNotice = document.getElementById('boxLoadingNotice');
    var listContainer = document.getElementById('listQuestionsContainer');

    var state = {
        kelasId: 0, mapelId: 0, kategori: '', asesmenId: 0,
        search: '', showKeys: false, assessData: null
    };

    var searchTimer = null;

    function openModal(id) {
        var m = document.getElementById(id);
        if (m) { m.classList.add('is-open'); m.classList.add('active'); m.style.display = 'flex'; }
    }
    function closeModal(id) {
        var m = document.getElementById(id);
        if (m) { m.classList.remove('is-open'); m.classList.remove('active'); m.style.display = 'none'; }
    }
    document.querySelectorAll('[data-close]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var target = this.getAttribute('data-close');
            closeModal(target);
        });
    });

    function escapeHtml(text) {
        if (!text) return '';
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.toString().replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    function showToast(a, b) {
        var msg = a;
        var t = b || 'info';
        if (['error', 'danger', 'warning', 'success', 'info'].indexOf(a) !== -1 && b) { t = a; msg = b; }
        var normType = (t === 'danger' || t === 'delete' || t === 'err') ? 'error' : t;
        var title = (normType === 'error' ? 'Terjadi Kesalahan' : (normType === 'success' ? 'Berhasil' : (normType === 'warning' ? 'Peringatan' : 'Pemberitahuan')));
        if (window.Ilmuna && typeof window.Ilmuna.toast === 'function') {
            return Ilmuna.toast(normType, title, msg, 4000);
        }
        if (typeof window.ilmunaToast === 'function') {
            window.ilmunaToast(normType, msg, title, 3500);
            return;
        }
    }

    function safeFetchJson(url, options) {
        return fetch(url, options)
        .then(function(res) {
            return res.text().then(function(text) {
                var cleanText = text ? text.trim() : '';
                if (!cleanText) throw new Error("Respon server kosong (HTTP " + res.status + ")");
                try { return JSON.parse(cleanText); } catch (e) {
                    var start = cleanText.indexOf('{');
                    var end = cleanText.lastIndexOf('}');
                    if (start !== -1 && end !== -1 && end > start) {
                        try { return JSON.parse(cleanText.substring(start, end + 1)); } catch (e2) {}
                    }
                    throw new Error("Respon server bukan format JSON: " + cleanText.substring(0, 140));
                }
            });
        });
    }

    var btnDownloadTemplate = document.getElementById('btnDownloadTemplate');
    if (btnDownloadTemplate) {
        btnDownloadTemplate.addEventListener('click', function(e) {
            e.preventDefault();
            var kId = state.kelasId || 0;
            var mId = state.mapelId || 0;
            var aId = state.asesmenId || 0;
            var kat = state.kategori || 'sumatif';
            if (kId <= 0 || mId <= 0 || aId <= 0) {
                showToast('Silakan pilih Rombel Kelas, Mata Pelajaran, dan Paket Asesmen terlebih dahulu agar identitas template lengkap.', 'warning');
                return;
            }
            window.location.href = ajaxUrl + '?action=download_template_docs&kelas_id=' + kId + '&mapel_id=' + mId + '&asesmen_id=' + aId + '&kategori=' + encodeURIComponent(kat);
        });
    }

    selectKelas.addEventListener('change', function() {
        var kId = parseInt(this.value, 10) || 0;
        state.kelasId = kId;
        state.mapelId = 0;
        state.asesmenId = 0;
        state.kategori = '';
        state.assessData = null;
        selectMapel.innerHTML = '<option value="">-- Memuat Mata Pelajaran... --</option>';
        selectMapel.disabled = true;
        selectAsesmen.innerHTML = '<option value="">-- Pilih Mapel Terlebih Dahulu --</option>';
        selectAsesmen.disabled = true;
        inputSearch.disabled = true;
        bannerActive.style.display = 'none';
        listContainer.style.display = 'none';
        boxLoadingNotice.style.display = 'none';
        boxInitialNotice.style.display = 'block';
        if (kId <= 0) {
            selectMapel.innerHTML = '<option value="">-- Pilih Kelas Terlebih Dahulu --</option>';
            return;
        }
        loadMapelByClass(kId, 0, 0);
    });

    function loadMapelByClass(kId, selectTargetMapelId, selectTargetAssessId, selectTargetKat) {
        var fd = new FormData();
        fd.append('action', 'get_mapel_by_kelas');
        fd.append('kelas_id', kId);
        return safeFetchJson(ajaxUrl, { method: 'POST', body: fd })
        .then(function(res) {
            if (!res.success || !res.data || res.data.length === 0) {
                selectMapel.innerHTML = '<option value="">-- Belum Ada Mata Pelajaran di Kelas Ini --</option>';
                selectMapel.disabled = true;
                return;
            }
            var html = '<option value="">-- Pilih Mata Pelajaran (' + res.data.length + ' Mapel) --</option>';
            res.data.forEach(function(m) {
                var isSelected = (selectTargetMapelId && parseInt(m.id, 10) === parseInt(selectTargetMapelId, 10)) ? ' selected' : '';
                html += '<option value="' + m.id + '"' + isSelected + '>' + escapeHtml(m.nama_mata_pelajaran) + '</option>';
            });
            selectMapel.innerHTML = html;
            selectMapel.disabled = false;
            if (selectTargetMapelId > 0) {
                state.mapelId = selectTargetMapelId;
                loadAssessmentsByClass(kId, selectTargetMapelId, selectTargetAssessId, selectTargetKat);
            }
        })
        .catch(function(err) {
            selectMapel.innerHTML = '<option value="">-- Gagal memuat mapel --</option>';
            showToast(err.message, 'error');
        });
    }

    selectMapel.addEventListener('change', function() {
        var mId = parseInt(this.value, 10) || 0;
        state.mapelId = mId;
        state.asesmenId = 0;
        state.kategori = '';
        state.assessData = null;
        selectAsesmen.innerHTML = '<option value="">-- Memuat Paket Asesmen... --</option>';
        selectAsesmen.disabled = true;
        inputSearch.disabled = true;
        bannerActive.style.display = 'none';
        listContainer.style.display = 'none';
        boxLoadingNotice.style.display = 'none';
        boxInitialNotice.style.display = 'block';
        if (mId <= 0) {
            selectAsesmen.innerHTML = '<option value="">-- Pilih Mapel Terlebih Dahulu --</option>';
            return;
        }
        loadAssessmentsByClass(state.kelasId, mId, 0, '');
    });

    function loadAssessmentsByClass(kId, mId, selectTargetId, selectTargetKat) {
        var fd = new FormData();
        fd.append('action', 'get_assessments_by_class');
        fd.append('kelas_id', kId);
        fd.append('mapel_id', mId);
        return safeFetchJson(ajaxUrl, { method: 'POST', body: fd })
        .then(function(res) {
            if (!res.success || !res.data || res.data.length === 0) {
                selectAsesmen.innerHTML = '<option value="">-- Belum Ada Paket Asesmen untuk Mapel Ini --</option>';
                selectAsesmen.disabled = true;
                return;
            }
            var html = '<option value="">-- Pilih Paket Asesmen (' + res.data.length + ' Paket) --</option>';
            res.data.forEach(function(a) {
                var labelKat = '[Diagnostik]';
                if (a.kategori === 'sumatif') labelKat = '[Sumatif CBT]';
                else if (a.kategori === 'formatif') labelKat = '[Formatif]';
                else if (a.kategori === 'remedial') labelKat = '[' + (a.jenis_program || 'Remedial') + ']';
                var matchId = (selectTargetId && parseInt(a.id, 10) === parseInt(selectTargetId, 10));
                var matchKat = (!selectTargetKat || a.kategori === selectTargetKat);
                var isSelected = (matchId && matchKat) ? ' selected' : '';
                html += '<option value="' + a.id + '" data-kat="' + a.kategori + '"' + isSelected + '>' +
                        labelKat + ' ' + escapeHtml(a.judul_asesmen) + ' &bull; ' + escapeHtml(a.nama_mata_pelajaran) + ' (' + a.total_soal + ' Soal)' +
                        '</option>';
            });
            selectAsesmen.innerHTML = html;
            selectAsesmen.disabled = false;
            if (selectTargetId > 0) {
                var opt = selectAsesmen.options[selectAsesmen.selectedIndex];
                if (opt && opt.value) {
                    state.asesmenId = parseInt(opt.value, 10);
                    state.kategori = opt.getAttribute('data-kat') || 'sumatif';
                    inputSearch.disabled = false;
                    fetchQuestions();
                }
            }
        })
        .catch(function(err) {
            selectAsesmen.innerHTML = '<option value="">-- Gagal memuat asesmen --</option>';
            showToast(err.message, 'error');
        });
    }

    selectAsesmen.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        var aId = parseInt(this.value, 10) || 0;
        var kat = opt ? (opt.getAttribute('data-kat') || '') : '';
        state.asesmenId = aId;
        state.kategori = kat;
        if (aId <= 0) {
            bannerActive.style.display = 'none';
            listContainer.style.display = 'none';
            boxInitialNotice.style.display = 'block';
            inputSearch.disabled = true;
            return;
        }
        inputSearch.disabled = false;
        fetchQuestions();
    });

    inputSearch.addEventListener('input', function() {
        var val = this.value.trim();
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            state.search = val;
            fetchQuestions();
        }, 250);
    });

    btnToggleKey.addEventListener('click', function() {
        state.showKeys = !state.showKeys;
        if (state.showKeys) {
            btnToggleKey.classList.add('is-revealed');
            iconEyeKey.setAttribute('icon', 'solar:eye-bold');
            textToggleKey.textContent = 'Kunci: Ditampilkan (Visible)';
            listContainer.classList.add('show-secret-keys');
            if (indicatorKey) {
                indicatorKey.innerHTML = '<iconify-icon icon="solar:eye-bold" width="14"></iconify-icon> <span>Kunci & Pedoman Ditampilkan</span>';
                indicatorKey.style.background = 'rgba(16, 185, 129, 0.15)';
                indicatorKey.style.color = '#065F46';
            }
        } else {
            btnToggleKey.classList.remove('is-revealed');
            iconEyeKey.setAttribute('icon', 'solar:eye-closed-bold');
            textToggleKey.textContent = 'Kunci: Tersembunyi (Hide)';
            listContainer.classList.remove('show-secret-keys');
            if (indicatorKey) {
                indicatorKey.innerHTML = '<iconify-icon icon="solar:shield-keyhole-bold" width="14"></iconify-icon> <span>Kunci Jawaban Disembunyikan</span>';
                indicatorKey.style.background = 'rgba(226, 232, 240, 0.5)';
                indicatorKey.style.color = 'var(--ilmuna-slate-400)';
            }
        }
    });

    function fetchQuestions() {
        boxInitialNotice.style.display = 'none';
        boxLoadingNotice.style.display = 'block';
        listContainer.style.display = 'none';

        var fd = new FormData();
        fd.append('action', 'get_questions');
        fd.append('kategori', state.kategori);
        fd.append('asesmen_id', state.asesmenId);
        fd.append('search', state.search);

        safeFetchJson(ajaxUrl, { method: 'POST', body: fd })
        .then(function(res) {
            boxLoadingNotice.style.display = 'none';
            if (!res.success) {
                boxInitialNotice.style.display = 'block';
                boxInitialNotice.querySelector('h3').textContent = 'Gagal Memuat Soal';
                boxInitialNotice.querySelector('p').textContent = res.message;
                return;
            }
            state.assessData = res.assessment;
            var a = res.assessment;
            bannerActive.style.display = 'flex';
            var katBadgeLabel = 'DIAGNOSTIK';
            var katBadgeMod = res.kategori;
            if (res.kategori === 'sumatif') katBadgeLabel = 'SUMATIF CBT';
            else if (res.kategori === 'formatif') katBadgeLabel = 'FORMATIF';
            else if (res.kategori === 'remedial') {
                var jp = (a.jenis_program || a.kategori || 'Remedial').toString();
                katBadgeLabel = jp.toUpperCase();
                katBadgeMod = (jp.toLowerCase() === 'pengayaan') ? 'pengayaan' : 'remedial';
            }
            badgeKategori.textContent = katBadgeLabel;
            badgeKategori.className = 'ilmuna-active-assess-badge ilmuna-active-assess-badge--' + katBadgeMod;
            titleActive.textContent = a.judul_asesmen;
            metaActive.innerHTML = 
                '<span>Kelas: <strong>' + escapeHtml(a.nama_kelas) + '</strong></span> &bull; ' +
                '<span>Mapel: <strong>' + escapeHtml(a.nama_mata_pelajaran) + '</strong></span> &bull; ' +
                '<span>Guru: <strong>' + escapeHtml(a.nama_guru) + '</strong></span>' +
                (a.bentuk_kegiatan ? ' &bull; <span>Kegiatan: <strong>' + escapeHtml(a.bentuk_kegiatan) + '</strong></span>' : '') +
                (a.durasi_menit ? ' &bull; <span>Durasi: <strong>' + a.durasi_menit + ' Menit</strong></span>' : '') +
                (a.kktp ? ' &bull; <span>KKTP: <strong>' + a.kktp + '</strong></span>' : '');
            statTotal.textContent = res.questions.length + ' Soal';
            renderQuestions(res.questions);
        })
        .catch(function(err) {
            boxLoadingNotice.style.display = 'none';
            boxInitialNotice.style.display = 'block';
            showToast(err.message, 'error');
        });
    }

    function formatMediaUrl(url) {
        if (!url) return '';
        url = url.trim();
        if (url.startsWith('http://') || url.startsWith('https://') || url.startsWith('//') || url.startsWith('data:')) return url;
        if (url.startsWith('/')) return url;
        return '../../' + url.replace(/^\.\.\//, '');
    }

    function getYoutubeEmbedUrl(url) {
        if (!url) return null;
        var trimmed = url.trim();
        var regExp = /(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i;
        var match = trimmed.match(regExp);
        if (match && match[1]) return 'https://www.youtube-nocookie.com/embed/' + match[1];
        return null;
    }

    function renderStimulusMedia(q) {
        var mediaHtml = '';
        var hasImg = q.gambar_stimulus && q.gambar_stimulus.trim() !== '';
        var hasVid = q.link_video && q.link_video.trim() !== '';
        if (!hasImg && !hasVid) return '';
        mediaHtml += '<div class="ilmuna-stimulus-container">';
        if (hasImg) {
            var imgSrc = formatMediaUrl(q.gambar_stimulus);
            mediaHtml += 
                '<div class="ilmuna-stimulus-box ilmuna-stimulus-box--img">' +
                    '<div class="ilmuna-stimulus-header">' +
                        '<div class="ilmuna-stimulus-title">' +
                            '<iconify-icon icon="solar:gallery-wide-bold" width="16" style="color: #0D7A87;"></iconify-icon>' +
                            '<span>Gambar Stimulus Soal</span>' +
                        '</div>' +
                        '<a href="' + escapeHtml(imgSrc) + '" target="_blank" rel="noopener noreferrer" class="ilmuna-stimulus-link-btn">' +
                            '<iconify-icon icon="solar:maximize-square-minimalistic-bold" width="14"></iconify-icon> Buka Gambar' +
                        '</a>' +
                    '</div>' +
                    '<div class="ilmuna-stimulus-img-wrapper">' +
                        '<img src="' + escapeHtml(imgSrc) + '" alt="Stimulus" class="ilmuna-stimulus-img" loading="lazy" />' +
                    '</div>' +
                '</div>';
        }
        if (hasVid) {
            var vidUrl = q.link_video.trim();
            var embedUrl = getYoutubeEmbedUrl(vidUrl);
            mediaHtml += 
                '<div class="ilmuna-stimulus-box ilmuna-stimulus-box--video">' +
                    '<div class="ilmuna-stimulus-header">' +
                        '<div class="ilmuna-stimulus-title">' +
                            '<iconify-icon icon="solar:videocamera-record-bold" width="16" style="color: #DC2626;"></iconify-icon>' +
                            '<span>Media Video Stimulus ' + (embedUrl ? '(YouTube)' : '') + '</span>' +
                        '</div>' +
                        '<a href="' + escapeHtml(vidUrl) + '" target="_blank" rel="noopener noreferrer" class="ilmuna-stimulus-link-btn ilmuna-stimulus-link-btn--yt">' +
                            '<iconify-icon icon="solar:play-circle-bold" width="14"></iconify-icon> Buka di ' + (embedUrl ? 'YouTube' : 'Browser') +
                        '</a>' +
                    '</div>';
            if (embedUrl) {
                mediaHtml += 
                    '<div class="ilmuna-video-responsive">' +
                        '<iframe src="' + escapeHtml(embedUrl) + '" title="Video Stimulus" frameborder="0" allowfullscreen loading="lazy"></iframe>' +
                    '</div>';
            } else {
                mediaHtml += 
                    '<div class="ilmuna-stimulus-video-fallback">' +
                        '<iconify-icon icon="solar:video-frame-play-horizontal-bold" width="28" style="color: #DC2626;"></iconify-icon>' +
                        '<div><strong>Tautan Video Stimulus</strong><p><a href="' + escapeHtml(vidUrl) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(vidUrl) + '</a></p></div>' +
                    '</div>';
            }
            mediaHtml += '</div>';
        }
        mediaHtml += '</div>';
        return mediaHtml;
    }

    function renderQuestions(questions) {
        listContainer.innerHTML = '';
        if (!questions || questions.length === 0) {
            listContainer.innerHTML = 
                '<div class="ilmuna-quest-empty-state">' +
                '<iconify-icon class="ilmuna-quest-empty-state__icon" icon="solar:document-text-outline"></iconify-icon>' +
                '<h3>Belum Ada Butir Soal Terdaftar</h3>' +
                '<p>Paket asesmen ini belum memiliki butir soal. Klik tombol <strong>Import Soal (.docx)</strong> di atas untuk mengimpor soal.</p>' +
                '</div>';
            listContainer.style.display = 'block';
            return;
        }
        var pgQuestions = [];
        var essayQuestions = [];
        questions.forEach(function(q) {
            if (q.tipe_soal === 'Essay / Uraian' || q.tipe_soal === 'Tugas Terbimbing' || q.tipe_soal === 'Studi Kasus Khusus') essayQuestions.push(q);
            else pgQuestions.push(q);
        });
        var html = '';
        html += '<div class="ilmuna-quest-section-header">';
        html += '  <div class="ilmuna-quest-section-title"><iconify-icon icon="solar:list-check-bold" width="22" style="color: var(--ilmuna-teal);"></iconify-icon><span>BAGIAN I: BUTIR SOAL PILIHAN GANDA (PG)</span></div>';
        html += '  <span class="ilmuna-quest-section-count">' + pgQuestions.length + ' Butir Soal PG</span>';
        html += '</div>';
        if (pgQuestions.length === 0) {
            html += '<div style="background:#ffffff;border:1px dashed var(--ilmuna-slate-300);border-radius:12px;padding:24px;text-align:center;color:var(--ilmuna-slate-500);font-size:13.5px;margin-bottom:24px;">Belum ada butir soal pilihan ganda.</div>';
        } else {
            pgQuestions.forEach(function(q) {
                var diffClass = 'ilmuna-quest-diff-tag--' + (q.tingkat_kesulitan || 'Sedang');
                var opsiHtml = '';
                if (q.opsi && q.opsi.length > 0) {
                    opsiHtml += '<div class="ilmuna-quest-options-grid">';
                    q.opsi.forEach(function(o) {
                        var isKunci = (parseInt(o.is_kunci_jawaban, 10) === 1);
                        var itemClass = isKunci ? 'ilmuna-quest-option-item is-correct-answer' : 'ilmuna-quest-option-item';
                        opsiHtml += 
                            '<div class="' + itemClass + '" data-is-key="' + (isKunci ? '1' : '0') + '">' +
                                '<div class="ilmuna-quest-option-code">' + escapeHtml(o.kode_opsi) + '</div>' +
                                '<div class="ilmuna-quest-option-text">' + escapeHtml(o.teks_opsi) + '</div>' +
                                '<span class="ilmuna-quest-option-key-badge"><iconify-icon icon="solar:check-circle-bold" width="13"></iconify-icon> Kunci Benar</span>' +
                            '</div>';
                    });
                    opsiHtml += '</div>';
                }
                html += 
                    '<div class="ilmuna-quest-card" data-id="' + q.id + '">' +
                        '<div class="ilmuna-quest-card__head">' +
                            '<div class="ilmuna-quest-card__badge-row">' +
                                '<span class="ilmuna-badge-no">' + q.nomor_soal + '</span>' +
                                '<span class="ilmuna-quest-type-tag">' + escapeHtml(q.tipe_soal || 'Pilihan Ganda') + '</span>' +
                                '<span class="ilmuna-quest-diff-tag ' + diffClass + '">' + escapeHtml(q.tingkat_kesulitan || 'Sedang') + '</span>' +
                                '<span class="ilmuna-quest-score-tag">Bobot: ' + q.bobot_skor + ' Poin</span>' +
                            '</div>' +
                            '<div class="ilmuna-action-btns">' +
                                '<button type="button" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--ghost btn-del-question" data-id="' + q.id + '" data-no="' + q.nomor_soal + '" style="color: var(--ilmuna-rose);"><iconify-icon icon="solar:trash-bin-trash-bold" width="15"></iconify-icon></button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="ilmuna-quest-card__body">' + escapeHtml(q.pertanyaan).replace(/\n/g, '<br>') + '</div>' +
                        renderStimulusMedia(q) +
                        opsiHtml +
                    '</div>';
            });
        }
        html += '<div class="ilmuna-quest-section-header ilmuna-quest-section-header--essay" style="margin-top: 28px;">';
        html += '  <div class="ilmuna-quest-section-title"><iconify-icon icon="solar:document-text-bold" width="22" style="color: #F59E0B;"></iconify-icon><span>BAGIAN II: BUTIR SOAL ESSAY / URAIAN &amp; RUBRIK PENILAIAN</span></div>';
        html += '  <span class="ilmuna-quest-section-count" style="background:rgba(245,158,11,0.15);color:#B45309;border-color:rgba(245,158,11,0.3);">' + essayQuestions.length + ' Butir Soal Essay</span>';
        html += '</div>';
        if (essayQuestions.length === 0) {
            html += '<div style="background:#ffffff;border:1px dashed var(--ilmuna-slate-300);border-radius:12px;padding:24px;text-align:center;color:var(--ilmuna-slate-500);font-size:13.5px;">Belum ada butir soal essay.</div>';
        } else {
            essayQuestions.forEach(function(q) {
                var diffClass = 'ilmuna-quest-diff-tag--' + (q.tingkat_kesulitan || 'Sedang');
                var r = q.rubrik || {};
                var aspekText = r.aspek_kompetensi || 'Pemahaman Konsep & Penalaran';
                var pedomanText = r.pedoman_penskoran || 'Sesuai dengan ketepatan dan kelengkapan uraian jawaban.';
                var kriteriaMaks = r.kriteria_skor_maksimal || 'Jawaban komprehensif, tepat, memenuhi seluruh kriteria.';
                var kriteriaSedang = r.kriteria_skor_sedang || 'Jawaban cukup jelas, uraian masih bersifat umum.';
                var kriteriaMin = r.kriteria_skor_minimal || 'Hanya menyebutkan pokok pikiran singkat.';
                var kriteriaNol = r.kriteria_skor_nol || 'Jawaban tidak relevan atau tidak menjawab.';
                var skorMaks = r.skor_maksimal || q.bobot_skor;
                html += 
                    '<div class="ilmuna-quest-card ilmuna-quest-card--essay" data-id="' + q.id + '">' +
                        '<div class="ilmuna-quest-card__head">' +
                            '<div class="ilmuna-quest-card__badge-row">' +
                                '<span class="ilmuna-badge-no" style="background: linear-gradient(135deg, #B45309 0%, #D97706 100%);">' + q.nomor_soal + '</span>' +
                                '<span class="ilmuna-quest-type-tag" style="background: rgba(245,158,11,0.15); color: #B45309; font-weight: 800;">' + escapeHtml(q.tipe_soal || 'Essay / Uraian') + '</span>' +
                                '<span class="ilmuna-quest-diff-tag ' + diffClass + '">' + escapeHtml(q.tingkat_kesulitan || 'Sedang') + '</span>' +
                                '<span class="ilmuna-quest-score-tag" style="background: rgba(245,158,11,0.15); color: #B45309;">Bobot: ' + q.bobot_skor + ' Poin</span>' +
                            '</div>' +
                            '<div class="ilmuna-action-btns">' +
                                '<button type="button" class="ilmuna-btn ilmuna-btn--sm ilmuna-btn--ghost btn-del-question" data-id="' + q.id + '" data-no="' + q.nomor_soal + '" style="color: var(--ilmuna-rose);"><iconify-icon icon="solar:trash-bin-trash-bold" width="15"></iconify-icon></button>' +
                            '</div>' +
                        '</div>' +
                        '<div class="ilmuna-quest-card__body">' + escapeHtml(q.pertanyaan).replace(/\n/g, '<br>') + '</div>' +
                        renderStimulusMedia(q) +
                        '<div class="ilmuna-essay-pedoman-box">' +
                            '<div class="ilmuna-essay-pedoman-head">' +
                                '<div style="display: flex; align-items: center; gap: 6px;">' +
                                    '<iconify-icon icon="solar:key-minimalistic-square-bold" width="16" style="color: #0D7A87;"></iconify-icon>' +
                                    '<strong>Pedoman Penskoran / Kunci Uraian:</strong>' +
                                '</div>' +
                                '<span class="ilmuna-pedoman-sensor-badge"><iconify-icon icon="solar:shield-keyhole-bold" width="12"></iconify-icon> Terproteksi Sensor</span>' +
                            '</div>' +
                            '<div class="ilmuna-essay-pedoman-text">' + escapeHtml(pedomanText).replace(/\n/g, '<br>') + '</div>' +
                        '</div>' +
                        '<div class="ilmuna-rubrik-container">' +
                            '<div class="ilmuna-rubrik-header">' +
                                '<div style="display: flex; align-items: center; gap: 6px;"><iconify-icon icon="solar:checklist-minimalistic-bold" width="16" style="color: #1D3B5E;"></iconify-icon><strong>Rubrik Penilaian Lengkap:</strong></div>' +
                                '<span class="ilmuna-rubrik-aspek-badge">Aspek: ' + escapeHtml(aspekText) + '</span>' +
                            '</div>' +
                            '<div class="ilmuna-rubrik-levels-grid">' +
                                '<div class="ilmuna-rubrik-level-card ilmuna-rubrik-level-card--max">' +
                                    '<div class="ilmuna-rubrik-level-head"><span class="ilmuna-rubrik-level-pill">Maksimal (Skor ' + skorMaks + ')</span><iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon></div>' +
                                    '<div class="ilmuna-rubrik-level-desc">' + escapeHtml(kriteriaMaks) + '</div>' +
                                '</div>' +
                                '<div class="ilmuna-rubrik-level-card ilmuna-rubrik-level-card--sedang">' +
                                    '<div class="ilmuna-rubrik-level-head"><span class="ilmuna-rubrik-level-pill">Sedang / Sebagian</span><iconify-icon icon="solar:star-circle-bold" width="16"></iconify-icon></div>' +
                                    '<div class="ilmuna-rubrik-level-desc">' + escapeHtml(kriteriaSedang) + '</div>' +
                                '</div>' +
                                '<div class="ilmuna-rubrik-level-card ilmuna-rubrik-level-card--min">' +
                                    '<div class="ilmuna-rubrik-level-head"><span class="ilmuna-rubrik-level-pill">Minimal / Dasar</span><iconify-icon icon="solar:shield-warning-bold" width="16"></iconify-icon></div>' +
                                    '<div class="ilmuna-rubrik-level-desc">' + escapeHtml(kriteriaMin) + '</div>' +
                                '</div>' +
                                '<div class="ilmuna-rubrik-level-card ilmuna-rubrik-level-card--nol">' +
                                    '<div class="ilmuna-rubrik-level-head"><span class="ilmuna-rubrik-level-pill">Skor 0 (Nol)</span><iconify-icon icon="solar:close-circle-bold" width="16"></iconify-icon></div>' +
                                    '<div class="ilmuna-rubrik-level-desc">' + escapeHtml(kriteriaNol) + '</div>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
            });
        }
        listContainer.innerHTML = html;
        listContainer.style.display = 'flex';
        listContainer.querySelectorAll('.btn-del-question').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var qId = this.getAttribute('data-id');
                var qNo = this.getAttribute('data-no');
                Ilmuna.confirm('error', 'Hapus Butir Soal?', 'Apakah Anda yakin ingin menghapus butir soal No. ' + qNo + '?', 'Ya, Hapus!', 'Batal').then(function(ok) {
                    if (ok) deleteQuestion(qId);
                });
            });
        });
    }

    function deleteQuestion(idSoal) {
        var fd = new FormData();
        fd.append('action', 'delete_question');
        fd.append('kategori', state.kategori);
        fd.append('id_soal', idSoal);
        safeFetchJson(ajaxUrl, { method: 'POST', body: fd })
        .then(function(res) {
            if (res.success) { showToast(res.message, 'success'); fetchQuestions(); }
            else { showToast(res.message, 'error'); }
        })
        .catch(function(err) { showToast(err.message, 'error'); });
    }

    var btnOpenImport = document.getElementById('btnOpenImportModal');
    btnOpenImport.addEventListener('click', function() {
        var kId = state.kelasId || 0;
        var mId = state.mapelId || 0;
        var aId = state.asesmenId || 0;
        if (kId <= 0 || mId <= 0 || aId <= 0) {
            showToast('Silakan pilih Rombel Kelas, Mata Pelajaran, dan Paket Asesmen terlebih dahulu sebelum import soal.', 'warning');
            return;
        }
        var opt = selectAsesmen.options[selectAsesmen.selectedIndex];
        var judul = opt ? opt.textContent : 'Paket Asesmen';
        var kelasOpt = selectKelas.options[selectKelas.selectedIndex];
        var namaKelas = kelasOpt ? kelasOpt.textContent : '-';
        var mapelOpt = selectMapel.options[selectMapel.selectedIndex];
        var namaMapel = mapelOpt ? mapelOpt.textContent : '-';
        document.getElementById('lblImportTargetTitle').innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="16"></iconify-icon> Target: ' + escapeHtml(judul);
        document.getElementById('lblImportTargetMeta').innerHTML = 'Rombel: <strong>' + escapeHtml(namaKelas) + '</strong> &bull; Mapel: <strong>' + escapeHtml(namaMapel) + '</strong>';
        document.getElementById('importKategori').value = state.kategori;
        document.getElementById('importAsesmenId').value = state.asesmenId;
        document.getElementById('inputDocsFile').value = '';
        document.getElementById('dropzoneFileName').textContent = 'Klik untuk Pilih Dokumen Word (.docx)';
        document.getElementById('chkClearOld').checked = false;
        openModal('modalImportDocs');
    });

    var inputDocsFile = document.getElementById('inputDocsFile');
    var dropzoneDocs = document.getElementById('dropzoneDocs');
    var dropzoneFileName = document.getElementById('dropzoneFileName');

    inputDocsFile.addEventListener('change', function() {
        if (this.files && this.files.length > 0) {
            var file = this.files[0];
            dropzoneFileName.innerHTML = '<strong>' + escapeHtml(file.name) + '</strong> (' + (file.size / 1024).toFixed(1) + ' KB)';
        }
    });

    ['dragenter', 'dragover'].forEach(function(eventName) {
        dropzoneDocs.addEventListener(eventName, function(e) {
            e.preventDefault();
            dropzoneDocs.classList.add('is-dragover');
        });
    });
    ['dragleave', 'drop'].forEach(function(eventName) {
        dropzoneDocs.addEventListener(eventName, function(e) {
            e.preventDefault();
            dropzoneDocs.classList.remove('is-dragover');
        });
    });
    dropzoneDocs.addEventListener('drop', function(e) {
        if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
            inputDocsFile.files = e.dataTransfer.files;
            var file = e.dataTransfer.files[0];
            dropzoneFileName.innerHTML = '<strong>' + escapeHtml(file.name) + '</strong> (' + (file.size / 1024).toFixed(1) + ' KB)';
        }
    });

    var currentPreviewData = null;

    var formImport = document.getElementById('formImportDocs');
    formImport.addEventListener('submit', function(e) {
        e.preventDefault();
        if (!inputDocsFile.files || inputDocsFile.files.length === 0) {
            showToast('Silakan pilih berkas dokumen Word (.docx) terlebih dahulu.', 'error');
            return;
        }
        var btnSubmit = document.getElementById('btnSubmitImport');
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<span class="ilmuna-spinner" style="width: 14px; height: 14px; margin-right: 6px;"></span> Memindai Dokumen...';

        var formData = new FormData(formImport);
        formData.set('action', 'preview_docs');

        safeFetchJson(ajaxUrl, { method: 'POST', body: formData })
        .then(function(res) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<iconify-icon icon="solar:eye-bold" width="18"></iconify-icon> Pratinjau &amp; Cek Soal';
            if (!res.success) { showToast(res.message, 'error'); return; }
            currentPreviewData = res;
            closeModal('modalImportDocs');
            var kmpTitle = '';
            if (res.matched_kmp) {
                kmpTitle = escapeHtml(res.matched_kmp.nama_kelas) + ' &bull; ' + escapeHtml(res.matched_kmp.nama_mata_pelajaran);
            } else {
                kmpTitle = (res.detected_kelas ? escapeHtml(res.detected_kelas) : 'Kelas') + ' &bull; ' + (res.detected_mapel ? escapeHtml(res.detected_mapel) : 'Mata Pelajaran');
            }
            document.getElementById('previewTargetClassMapel').innerHTML = '<iconify-icon icon="solar:verified-check-bold" style="color: var(--ilmuna-teal); vertical-align: middle;"></iconify-icon> ' + kmpTitle;
            document.getElementById('previewTargetMeta').innerHTML = 'Judul: <strong>' + escapeHtml(res.detected_judul || 'Asesmen') + '</strong> &bull; Kategori: <span style="text-transform: uppercase; font-weight: 700;">' + escapeHtml(res.kategori) + '</span>';
            document.getElementById('previewTotalBadge').textContent = (res.total_found || 0) + ' Soal Siap';

            var listEl = document.getElementById('previewQuestionsList');
            listEl.innerHTML = '';
            if (res.questions && res.questions.length > 0) {
                res.questions.forEach(function(q, idx) {
                    var card = document.createElement('div');
                    card.className = 'ilmuna-preview-quest-card';
                    var isEssay = (q.tipe_soal === 'Essay / Uraian');
                    var previewStimulusHtml = '';
                    if (q.gambar_stimulus && q.gambar_stimulus.trim() !== '') {
                        var imgSrc = formatMediaUrl(q.gambar_stimulus);
                        previewStimulusHtml += 
                            '<div class="ilmuna-preview-stimulus-box">' +
                                '<div class="ilmuna-preview-stimulus-head"><iconify-icon icon="solar:gallery-wide-bold" width="13" style="color: #0D7A87;"></iconify-icon> <strong>Gambar:</strong> <span class="ilmuna-preview-stimulus-text">' + escapeHtml(q.gambar_stimulus) + '</span></div>' +
                                '<div class="ilmuna-preview-stimulus-thumb-wrap"><img src="' + escapeHtml(imgSrc) + '" class="ilmuna-preview-stimulus-thumb" alt="Stimulus" /></div>' +
                            '</div>';
                    }
                    if (q.link_video && q.link_video.trim() !== '') {
                        var vidUrl = q.link_video.trim();
                        var ytEmbed = getYoutubeEmbedUrl(vidUrl);
                        previewStimulusHtml += 
                            '<div class="ilmuna-preview-stimulus-box ilmuna-preview-stimulus-box--vid">' +
                                '<div class="ilmuna-preview-stimulus-head"><iconify-icon icon="solar:videocamera-record-bold" width="13" style="color: #DC2626;"></iconify-icon> <strong>Video ' + (ytEmbed ? '(YouTube)' : '') + ':</strong> <a href="' + escapeHtml(vidUrl) + '" target="_blank" rel="noopener noreferrer" style="color:#0284C7;">' + escapeHtml(vidUrl) + '</a></div>' +
                            '</div>';
                    }
                    if (isEssay) {
                        var r = q.rubrik || {};
                        var pedomanText = r.pedoman_penskoran || q.pedoman || 'Sesuai ketepatan uraian.';
                        card.innerHTML = 
                            '<div class="ilmuna-preview-quest-header">' +
                                '<div class="ilmuna-preview-quest-no">' +
                                    '<span style="background: #B45309; color: #ffffff; width: 22px; height: 22px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">' + (idx + 1) + '</span>' +
                                    '<span>Butir #' + q.nomor_doc + '</span>' +
                                    '<span style="background: rgba(245, 158, 11, 0.15); color: #B45309; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 800; margin-left: 6px;">ESSAY</span>' +
                                '</div>' +
                                '<span style="font-size: 11px; color: var(--ilmuna-slate-500); font-weight: 600;">Bobot: ' + q.bobot + ' Poin</span>' +
                            '</div>' +
                            '<div class="ilmuna-preview-quest-text">' + escapeHtml(q.pertanyaan).replace(/\n/g, '<br>') + '</div>' +
                            previewStimulusHtml +
                            '<div style="background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px 12px; margin-top: 10px; font-size: 12.5px;">' +
                                '<div style="font-weight: 700; color: #0D7A87; margin-bottom: 4px;">Pedoman:</div>' +
                                '<div style="color: #334155;">' + escapeHtml(pedomanText).replace(/\n/g, '<br>') + '</div>' +
                            '</div>';
                    } else {
                        var kunciLabel = q.kunci ? ('<span style="background: rgba(16, 185, 129, 0.15); color: #065F46; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 800;"><iconify-icon icon="solar:check-circle-bold" width="12" style="vertical-align: middle;"></iconify-icon> KUNCI: ' + escapeHtml(q.kunci) + '</span>') : '<span style="background: rgba(239, 68, 68, 0.1); color: #991B1B; padding: 2px 8px; border-radius: 6px; font-size: 11px;">Belum ada kunci</span>';
                        var html = 
                            '<div class="ilmuna-preview-quest-header">' +
                                '<div class="ilmuna-preview-quest-no">' +
                                    '<span style="background: var(--ilmuna-navy); color: #ffffff; width: 22px; height: 22px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px;">' + (idx + 1) + '</span>' +
                                    '<span>Butir #' + q.nomor_doc + '</span>' +
                                '</div>' +
                                '<div style="display: flex; align-items: center; gap: 8px;">' +
                                    '<span style="font-size: 11px; color: var(--ilmuna-slate-500); font-weight: 600;">Bobot: ' + q.bobot + ' Poin</span>' + kunciLabel +
                                '</div>' +
                            '</div>' +
                            '<div class="ilmuna-preview-quest-text">' + escapeHtml(q.pertanyaan).replace(/\n/g, '<br>') + '</div>' +
                            previewStimulusHtml +
                            '<div class="ilmuna-preview-opsi-grid">';
                        var opsiKeys = ['A', 'B', 'C', 'D', 'E'];
                        opsiKeys.forEach(function(code) {
                            if (q.opsi && q.opsi[code] !== undefined) {
                                var isKey = (q.kunci && q.kunci.toUpperCase() === code);
                                html += 
                                    '<div class="ilmuna-preview-opsi-item ' + (isKey ? 'is-correct' : '') + '">' +
                                        '<span class="ilmuna-preview-opsi-code">' + code + '</span>' +
                                        '<span>' + escapeHtml(q.opsi[code]) + (isKey ? ' <iconify-icon icon="solar:check-circle-bold" width="14" style="color: #10B981; vertical-align: middle;"></iconify-icon>' : '') + '</span>' +
                                    '</div>';
                            }
                        });
                        html += '</div>';
                        card.innerHTML = html;
                    }
                    listEl.appendChild(card);
                });
            }
            openModal('modalPreviewDocs');
        })
        .catch(function(err) {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<iconify-icon icon="solar:eye-bold" width="18"></iconify-icon> Pratinjau &amp; Cek Soal';
            showToast('Gagal memindai dokumen: ' + err.message, 'error');
        });
    });

    var btnConfirmCommit = document.getElementById('btnConfirmCommitImport');
    if (btnConfirmCommit) {
        btnConfirmCommit.addEventListener('click', function() {
            btnConfirmCommit.disabled = true;
            btnConfirmCommit.innerHTML = '<span class="ilmuna-spinner" style="width: 14px; height: 14px; margin-right: 6px;"></span> Menyimpan...';
            var fd = new FormData();
            fd.append('action', 'commit_import_docs');
            if (currentPreviewData) fd.append('questions_json', JSON.stringify(currentPreviewData));
            safeFetchJson(ajaxUrl, { method: 'POST', body: fd })
            .then(function(res) {
                btnConfirmCommit.disabled = false;
                btnConfirmCommit.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="18"></iconify-icon> Ya, Simpan ke Bank Soal';
                if (!res.success) { showToast(res.message, 'error'); return; }
                closeModal('modalPreviewDocs');
                document.getElementById('resCountSuccess').textContent = res.success_count || 0;
                document.getElementById('resCountFailed').textContent = res.failed_count || 0;
                document.getElementById('resCountTotal').textContent = res.total_found || 0;
                var logsBox = document.getElementById('resLogsList');
                logsBox.innerHTML = '';
                if (res.logs && res.logs.length > 0) {
                    res.logs.forEach(function(l) {
                        var logEl = document.createElement('div');
                        logEl.className = 'ilmuna-import-log-item ' + (l.status === 'success' ? 'is-success' : 'is-failed');
                        logEl.innerHTML = '<iconify-icon icon="' + (l.status === 'success' ? 'solar:check-circle-bold' : 'solar:danger-triangle-bold') + '" width="14"></iconify-icon><span>' + escapeHtml(l.message) + '</span>';
                        logsBox.appendChild(logEl);
                    });
                } else {
                    logsBox.innerHTML = '<div style="color: var(--ilmuna-slate-400);">Tidak ada catatan log.</div>';
                }
                openModal('modalImportResult');
                showToast(res.message, 'success');
                if (res.kelas_id && res.kelas_id > 0) {
                    state.kelasId = res.kelas_id;
                    selectKelas.value = res.kelas_id;
                    loadMapelByClass(res.kelas_id, state.mapelId, res.asesmen_id, res.kategori || state.kategori);
                } else if (state.asesmenId > 0) {
                    fetchQuestions();
                }
            })
            .catch(function(err) {
                btnConfirmCommit.disabled = false;
                btnConfirmCommit.innerHTML = '<iconify-icon icon="solar:check-circle-bold" width="18"></iconify-icon> Ya, Simpan ke Bank Soal';
                showToast('Kesalahan: ' + err.message, 'error');
            });
        });
    }

    var btnCloseResult = document.getElementById('btnCloseAndReloadQuestions');
    if (btnCloseResult) {
        btnCloseResult.addEventListener('click', function() {
            closeModal('modalImportResult');
            if (state.asesmenId > 0) fetchQuestions();
        });
    }
});
</script>

<?php require_once BASE_PATH . '/includes/footer.php'; ?>