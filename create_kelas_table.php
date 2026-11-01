<?php
require_once __DIR__ . '/config.php';

$pdo = getDBConnection();
echo "Connected to database: " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Existing tables:\n";
print_r($tables);

// Create `kelas` table with the requested columns: id, nama_kelas, tahun_pelajaran
$sql = "CREATE TABLE IF NOT EXISTS `kelas` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `nama_kelas` VARCHAR(100) NOT NULL,
    `tahun_pelajaran` VARCHAR(30) NOT NULL DEFAULT '2026/2027',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

$pdo->exec($sql);
echo "\nTable `kelas` created successfully!\n";

// Also check structure of `kelas`
$cols = $pdo->query("DESCRIBE `kelas`")->fetchAll(PDO::FETCH_ASSOC);
echo "\nColumns in `kelas`:\n";
foreach ($cols as $c) {
    echo "- {$c['Field']} ({$c['Type']})\n";
}
