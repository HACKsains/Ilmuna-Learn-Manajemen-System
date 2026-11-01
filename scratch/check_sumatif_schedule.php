<?php
require_once __DIR__ . '/../config.php';
$pdo = getDBConnection();

$rows = $pdo->query("
    SELECT asum.id, asum.judul_asesmen, asum.jenis_sumatif, asum.tanggal_pelaksanaan, 
           asum.jam_mulai, asum.jam_selesai, asum.durasi_menit,
           kmp.id_kelas, k.nama_kelas, mp.nama_mata_pelajaran
    FROM asesmen_sumatif asum
    JOIN kelas_mata_pelajaran kmp ON asum.id_kelas_mata_pelajaran = kmp.id
    JOIN kelas k ON kmp.id_kelas = k.id
    JOIN mata_pelajaran mp ON kmp.id_mata_pelajaran = mp.id
    ORDER BY kmp.id_kelas, asum.tanggal_pelaksanaan ASC, asum.jam_mulai ASC
")->fetchAll(PDO::FETCH_ASSOC);

echo "Total sumatif rows in DB: " . count($rows) . "\n";
print_r($rows);
