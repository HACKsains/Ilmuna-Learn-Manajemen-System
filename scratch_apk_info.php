<?php
$apk = 'assets/apk/ilmuna-exam-browser-release.apk';
echo "File size: " . filesize($apk) . " bytes (" . round(filesize($apk) / (1024*1024), 2) . " MB)\n";
echo "SHA256: " . strtoupper(hash_file('sha256', $apk)) . "\n";

// Let's inspect AndroidManifest.xml inside the APK using tar
exec("tar -xf assets/apk/ilmuna-exam-browser-release.apk AndroidManifest.xml classes.dex");

if (file_exists('AndroidManifest.xml')) {
    $manifest = file_get_contents('AndroidManifest.xml');
    // search for versionCode / versionName
    // In Android binary XML, strings are in the string pool
    preg_match_all('/[\x20-\x7e]{3,50}/', $manifest, $m);
    echo "Manifest strings:\n";
    foreach ($m[0] as $s) {
        if (preg_match('/(version|1\.|id\.ilmuna)/i', $s)) {
            echo "  $s\n";
        }
    }
}

if (file_exists('classes.dex')) {
    $dex = file_get_contents('classes.dex');
    if (preg_match('/(1\.[0-9]+\.[0-9]+)/', $dex, $m)) {
        echo "DEX version match: " . $m[1] . "\n";
    }
}

