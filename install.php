<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — AUTOMATIC DATABASE INSTALLER & MIGRATION TOOL
 * ==============================================================================
 * URL: learn.ilmuna.my.id/install.php
 * Lumina Gold Styled Visual Database Installer & Status Checker
 * ==============================================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

$message = null;
$error = null;
$tableStats = [];

$pdo = null;
try {
    $pdo = getDBConnection();
    
    // Check if user clicked force re-run migration
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'run_migration') {
        runDatabaseMigration($pdo);
        $message = "Database berhasil diinisialisasi dan disinkronisasi dengan sukses!";
    }

    // Inspect existing 69 tables
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $tables = getOfficialTableList();
    
    foreach ($tables as $t) {
        try {
            $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $tableStats[$t] = $count;
        } catch (Exception $e) {
            $tableStats[$t] = 'Belum dibuat';
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Installer — Ilmuna Learn</title>
<?= renderFavicon() ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/lumina-lms.css">
    <style>
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at 50% 0%, #1D3B5E 0%, #0B1523 70%, #070D16 100%);
            padding: 2rem 1rem;
            color: #ffffff;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .installer-card {
            background: rgba(16, 32, 53, 0.85);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(82, 178, 220, 0.25);
            border-radius: 24px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.6), 0 0 40px rgba(13, 122, 135, 0.25);
            width: 100%;
            max-width: 680px;
            padding: 2.5rem;
        }
        .badge-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-success { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.4); }
        .badge-danger { background: rgba(239, 68, 68, 0.2); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.4); }
        .table-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 1.5rem 0;
        }
        .table-item {
            background: rgba(29, 59, 94, 0.4);
            border: 1px solid rgba(82, 178, 220, 0.15);
            padding: 10px 14px;
            border-radius: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.88rem;
        }
    </style>
</head>
<body>
    <div class="installer-card">
        <div style="text-align: center; margin-bottom: 2rem;">
            <div style="display: inline-flex; align-items: center; justify-content: center; width: 64px; height: 64px; border-radius: 18px; background: linear-gradient(135deg, #1D3B5E, #0D7A87); box-shadow: 0 10px 25px rgba(13, 122, 135, 0.4); margin-bottom: 1rem;">
                <i class="fas fa-database fa-2x" style="color: #52B2DC;"></i>
            </div>
            <h1 style="font-family: 'Outfit', sans-serif; font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem; background: linear-gradient(135deg, #ffffff 40%, #52B2DC 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                Database Auto-Installer
            </h1>
            <p style="color: #94a3b8; font-size: 0.95rem;">
                SIAKAD & LMS Media Pembelajaran Pendidikan Agama Islam (<a href="https://learn.ilmuna.my.id" style="color: #52B2DC; text-decoration: none;">learn.ilmuna.my.id</a>)
            </p>
        </div>

        <?php if ($message): ?>
            <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 12px; color: #a7f3d0;">
                <i class="fas fa-check-circle fa-lg"></i>
                <span><?= htmlspecialchars($message) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); padding: 1rem 1.25rem; border-radius: 12px; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 12px; color: #fca5a5;">
                <i class="fas fa-exclamation-triangle fa-lg"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <div style="background: rgba(11, 21, 35, 0.6); border: 1px solid rgba(82, 178, 220, 0.2); border-radius: 14px; padding: 1.25rem; margin-bottom: 1.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem;">
                <span style="color: #94a3b8; font-size: 0.9rem;">Status Koneksi Database:</span>
                <?php if ($pdo): ?>
                    <span class="badge-status badge-success"><i class="fas fa-circle-check"></i> Terhubung (<?= strtoupper($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) ?>)</span>
                <?php else: ?>
                    <span class="badge-status badge-danger"><i class="fas fa-circle-xmark"></i> Terputus</span>
                <?php endif; ?>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.88rem; color: #cbd5e1;">
                <span>Database Target:</span>
                <code style="background: rgba(255,255,255,0.08); padding: 2px 8px; border-radius: 6px; color: #52B2DC;"><?= DB_NAME ?></code>
            </div>
        </div>

        <h3 style="font-family: 'Outfit', sans-serif; font-size: 1.1rem; font-weight: 600; color: #e2e8f0; display: flex; align-items: center; gap: 8px;">
            <i class="fas fa-table-cells text-teal" style="color: #0D7A87;"></i> Status Tabel & Skema
        </h3>

        <div class="table-grid">
            <?php foreach ($tableStats as $table => $rows): ?>
                <div class="table-item">
                    <span><i class="fas fa-folder-tree" style="color: #52B2DC; margin-right: 6px;"></i> <?= $table ?></span>
                    <strong style="color: #f59e0b;"><?= is_numeric($rows) ? $rows . ' baris' : $rows ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="POST" style="margin-top: 2rem; display: flex; gap: 12px; flex-wrap: wrap;">
            <input type="hidden" name="action" value="run_migration">
            <button type="submit" class="btn-primary" style="flex: 1; padding: 14px 24px; border-radius: 12px; background: linear-gradient(135deg, #1D3B5E 0%, #0D7A87 50%, #52B2DC 100%); color: #ffffff; border: none; font-weight: 600; font-size: 0.95rem; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 10px 25px rgba(13, 122, 135, 0.4); transition: transform 0.2s;">
                <i class="fas fa-sync-alt"></i> Jalankan Migrasi & Generate Data
            </button>
            <a href="<?= BASE_URL ?>/login.php" style="padding: 14px 20px; border-radius: 12px; background: rgba(255,255,255,0.08); border: 1px solid rgba(82, 178, 220, 0.3); color: #ffffff; text-decoration: none; font-weight: 600; font-size: 0.95rem; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                <i class="fas fa-arrow-right"></i> Masuk Login
            </a>
        </form>
    </div>
</body>
</html>
