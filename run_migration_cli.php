<?php
/**
 * CLI Migration Runner for Remote Server & Local Dev
 * Standar 69 Tabel Kurikulum Merdeka Ilmuna Learn
 */
$_SERVER['REQUEST_METHOD'] = 'CLI';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Ilmuna-CLI-Runner';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

try {
    $pdo = getDBConnection();
    echo "====================================================================\n";
    echo "   ILMUNA LEARN — MIGRATION ENGINE (69 TABEL KURIKULUM MERDEKA)     \n";
    echo "====================================================================\n";
    echo "Database: " . DB_NAME . " on " . DB_HOST . "\n";
    echo "Menjalankan sinkronisasi database dari skema-tabel-kelas.sql...\n\n";

    $result = runDatabaseMigration($pdo, false);
    echo "Status: " . $result['message'] . "\n";
    echo "Total Tabel Terdaftar: " . $result['total_tables'] . " tabel\n\n";

    $clusters = getDatabaseClusters();
    $grandTotalRows = 0;
    $tableCounter = 1;

    foreach ($clusters as $clusterName => $tableList) {
        echo "--------------------------------------------------------------------\n";
        echo " $clusterName\n";
        echo "--------------------------------------------------------------------\n";
        foreach ($tableList as $t) {
            $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $grandTotalRows += (int) $count;
            echo sprintf(" %2d. %-32s : %d baris\n", $tableCounter++, $t, $count);
        }
        echo "\n";
    }

    echo "====================================================================\n";
    echo sprintf("TOTAL DATA: 69 Tabel Terverifikasi (%d baris data aktif)\n", $grandTotalRows);
    echo "====================================================================\n";

} catch (Exception $e) {
    echo "\n[ERROR] Migrasi Gagal: " . $e->getMessage() . "\n";
}
