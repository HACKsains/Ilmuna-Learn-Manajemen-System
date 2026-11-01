<?php
require 'c:/laragon/www/learn/config.php';
$pdo = getDBConnection();
foreach(['kelas', 'kelas_mata_pelajaran', 'mata_pelajaran'] as $t) {
    echo "=== TABLE $t ===\n";
    $cols = $pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) {
        echo "  " . $c['Field'] . " (" . $c['Type'] . ")\n";
    }
}
