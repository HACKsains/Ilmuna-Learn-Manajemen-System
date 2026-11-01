<?php
/**
 * =============================================================================
 * ILMUNA UI - Helper PHP untuk Toast & Alert Modal (assets/js/alert.js)
 * =============================================================================
 * Pemakaian di controller/halaman (sebelum redirect):
 *
 *   require_once BASE_PATH . '/includes/alert.php';
 *   ilmuna_toast('success', 'Berhasil', 'Data berhasil disimpan.');
 *   ilmuna_alert('error', 'Gagal', 'File terlalu besar.', 'Mengerti');
 *   header('Location: ...'); exit;
 *
 * Lalu panggil ilmuna_render_alerts() satu kali di layout (sudah dipasang di footer.php).
 * =============================================================================
 */

if (!function_exists('ilmuna_flash_push')) {

    function ilmuna_flash_push(array $item): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION['_ilmuna_flash'][] = $item;
    }

    /** Antrekan toast untuk ditampilkan pada request berikutnya / halaman saat ini. */
    function ilmuna_toast(string $type, string $title, string $message, int $duration = 5000): void
    {
        ilmuna_flash_push([
            'mode'     => 'toast',
            'type'     => $type,
            'title'    => $title,
            'message'  => $message,
            'duration' => $duration,
        ]);
    }

    /** Antrekan alert modal (tanpa tombol batal). */
    function ilmuna_alert(string $type, string $title, string $message, string $confirmText = 'OK'): void
    {
        ilmuna_flash_push([
            'mode'        => 'alert',
            'type'        => $type,
            'title'       => $title,
            'message'     => $message,
            'confirmText' => $confirmText,
        ]);
    }

    /** Cetak <script> yang menampilkan semua flash dalam antrean, lalu kosongkan antrean. */
    function ilmuna_render_alerts(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['_ilmuna_flash'])) {
            return;
        }

        $items = $_SESSION['_ilmuna_flash'];
        unset($_SESSION['_ilmuna_flash']);

        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        echo "<script>\n(function(){\n  if (!window.Ilmuna) return;\n  var E = Ilmuna.escape;\n";
        foreach ($items as $it) {
            $type  = json_encode((string)($it['type'] ?? 'info'), $flags);
            $title = json_encode((string)($it['title'] ?? ''), $flags);
            $msg   = json_encode((string)($it['message'] ?? ''), $flags);

            if (($it['mode'] ?? 'toast') === 'alert') {
                $btn = json_encode((string)($it['confirmText'] ?? 'OK'), $flags);
                echo "  Ilmuna.alert({$type}, E({$title}), E({$msg}), {$btn});\n";
            } else {
                $dur = (int)($it['duration'] ?? 5000);
                echo "  Ilmuna.toast({$type}, E({$title}), E({$msg}), {$dur});\n";
            }
        }
        echo "})();\n</script>\n";
    }
}

