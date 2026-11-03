<?php
$_SERVER['REQUEST_METHOD'] = 'CLI';
require_once __DIR__ . '/../config.php';
$pdo = getDBConnection();
$cols = $pdo->query("SHOW COLUMNS FROM kelas_mata_pelajaran")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . "\n";
}

