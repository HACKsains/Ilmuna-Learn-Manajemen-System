<?php
/**
 * Ilmuna LMS - Web Deployment & Git Sync Tool
 * Digunakan untuk melakukan git pull / reset tanpa akses SSH terminal.
 * 
 * Penggunaan:
 * Buka di browser: https://learn.ilmuna.my.id/deploy.php?secret=ilmuna_deploy_2026
 */

// Ganti atau sesuaikan secret token keamanan di bawah ini
define('DEPLOY_SECRET', 'ilmuna_deploy_2026');

// Validasi otentikasi
$secret = $_GET['secret'] ?? '';
if (!hash_equals(DEPLOY_SECRET, (string)$secret)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;text-align:center;">';
    echo '<h2 style="color:#e11d48;">Akses Ditolak (403 Forbidden)</h2>';
    echo '<p>Gunakan parameter secret yang valid: <code>?secret=...</code></p>';
    echo '</body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Ilmuna LMS - Auto Deployer</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 30px; }
        .card { max-width: 800px; margin: 0 auto; background: #1e293b; border-radius: 12px; padding: 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5); }
        h1 { margin-top: 0; font-size: 20px; color: #38bdf8; display: flex; align-items: center; gap: 10px; }
        pre { background: #020617; color: #a5f3fc; padding: 16px; border-radius: 8px; overflow-x: auto; font-size: 13px; line-height: 1.5; border: 1px solid #334155; }
        .btn { display: inline-block; padding: 10px 18px; background: #2563eb; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; margin-top: 15px; border: none; cursor: pointer; }
        .btn:hover { background: #1d4ed8; }
        .tag { display: inline-block; padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .tag-success { background: #065f46; color: #34d399; }
        .tag-warning { background: #854d0e; color: #fef08a; }
    </style>
</head>
<body>
<div class="card">
    <h1>🚀 Ilmuna LMS - Git Deployer</h1>
    <p style="color:#94a3b8; font-size: 14px;">Memperbarui file aplikasi langsung dari GitHub tanpa perlu SSH.</p>

    <h3>Hasil Eksekusi:</h3>
    <pre><?php
    $repoPath = __DIR__;
    
    // Cek ketersediaan fungsi eksekusi sistem
    $disabled = explode(',', ini_get('disable_functions'));
    $disabled = array_map('trim', $disabled);

    if (in_array('shell_exec', $disabled) && in_array('exec', $disabled) && in_array('passthru', $disabled)) {
        echo "<span style='color:#f87171;'>[ERROR] Server hosting Anda mendisable fungsi shell_exec, exec, dan passthru di PHP.\n";
        echo "Solusi: Gunakan fitur 'Cron Jobs' di cPanel untuk menjalankan perintah git pull.\n</span>";
    } else {
        $token = $_GET['token'] ?? '';
        $setRemoteCmd = '';
        if (!empty($token)) {
            $repoUrl = "https://" . rawurlencode($token) . "@github.com/HACKsains/Ilmuna-Learn-Manajemen-System.git";
            $setRemoteCmd = "git remote set-url origin {$repoUrl} 2>&1";
        }

        $commands = array_filter([
            'Set Remote URL with Token' => $setRemoteCmd,
            'Reset Uncommitted Changes' => 'git reset --hard HEAD 2>&1',
            'Clean Untracked Files' => 'git clean -fd 2>&1',
            'Pull Latest Commit' => 'git pull origin main 2>&1',
            'Git Status Akhir' => 'git status 2>&1',
            'Last Commit Info' => 'git log -1 --stat 2>&1'
        ]);

        foreach ($commands as $label => $cmd) {
            echo "========================================\n";
            echo ">> $label\n";
            echo "Command: $cmd\n";
            echo "========================================\n";
            
            $fullCmd = "cd " . escapeshellarg($repoPath) . " && " . $cmd;
            $output = shell_exec($fullCmd);
            
            echo htmlspecialchars($output ?: "(Tidak ada output / selesai)") . "\n\n";
        }
    }
    ?></pre>

    <a href="?secret=<?= htmlspecialchars($secret) ?>" class="btn">🔄 Jalankan Ulang / Refresh</a>
</div>
</body>
</html>

