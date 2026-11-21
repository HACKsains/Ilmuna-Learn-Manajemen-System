<?php
/**
 * Plugin Name: Ilmuna Learn Connector (Secure API · Lumina Gold Edition)
 * Plugin URI:  https://learn.ilmuna.my.id
 * Description: Menghubungkan WordPress (ilmuna.my.id) ke Ilmuna Learn (learn.ilmuna.my.id) dengan proteksi kriptografis Public Key + Private Key (HMAC-SHA256 / RSA-2048 / DUAL), Anti-Replay Nonce, dan Verifikasi Signature Respon. Tampilan 1 tema serasi dengan Lumina Gold (Light & Dark Mode, Z-Index terisolasi di atas ornamen).
 * Version:     1.1.0
 * Author:      Ilmuna Digital Ecosystem
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ILMUNA_WP_CONN_VERSION', '1.1.0');
define('ILMUNA_WP_CONN_OPTION', 'ilmuna_learn_api_settings');

/**
 * Ambil pengaturan plugin (mendukung override konstan di wp-config.php untuk keamanan maksimal)
 */
function ilmuna_conn_get_settings()
{
    $defaults = [
        'api_url'         => 'https://learn.ilmuna.my.id/api/wp/wp-broadcast.php',
        'public_key'      => '',
        'private_key'     => '',
        'sig_mode'        => 'HMAC-SHA256',
        'rsa_private_pem' => '',
        'cache_ttl'       => 180,
        'verify_response' => 1,
    ];
    $saved = get_option(ILMUNA_WP_CONN_OPTION, []);
    if (!is_array($saved)) {
        $saved = [];
    }
    $cfg = array_merge($defaults, $saved);

    // Override opsional dari wp-config.php agar Private Key bahkan tidak tersimpan di DB WordPress
    if (defined('ILMUNA_API_URL') && ILMUNA_API_URL !== '') {
        $cfg['api_url'] = ILMUNA_API_URL;
    }
    if (defined('ILMUNA_API_PUBLIC_KEY') && ILMUNA_API_PUBLIC_KEY !== '') {
        $cfg['public_key'] = ILMUNA_API_PUBLIC_KEY;
    }
    if (defined('ILMUNA_API_PRIVATE_KEY') && ILMUNA_API_PRIVATE_KEY !== '') {
        $cfg['private_key'] = ILMUNA_API_PRIVATE_KEY;
    }
    if (defined('ILMUNA_API_RSA_PRIVATE_PEM') && ILMUNA_API_RSA_PRIVATE_PEM !== '') {
        $cfg['rsa_private_pem'] = ILMUNA_API_RSA_PRIVATE_PEM;
    }

    return $cfg;
}

/**
 * Bangun Canonical Path & Query persis sesuai standar server learn.ilmuna.my.id
 */
function ilmuna_conn_canonical_path($url)
{
    $path = parse_url((string)$url, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/api/wp-broadcast';
    }
    $base = preg_replace('/\.php$/i', '', basename($path));
    if ($base === '' || $base === '/' || $base === '.') {
        $base = 'wp-broadcast';
    }
    return '/api/' . strtolower($base);
}

function ilmuna_conn_canonical_query(array $params)
{
    $norm = [];
    foreach ($params as $k => $v) {
        if ($v === '' || $v === null) {
            continue;
        }
        $norm[(string)$k] = is_array($v) ? implode(',', array_map('strval', $v)) : (string)$v;
    }
    ksort($norm, SORT_STRING);
    return http_build_query($norm, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Panggil API Ilmuna Learn secara Server-to-Server dengan tanda tangan kriptografis + caching Transient
 */
function ilmuna_conn_request($resource, array $extraParams = [], $forceRefresh = false)
{
    $cfg = ilmuna_conn_get_settings();
    $apiUrl     = trim((string)$cfg['api_url']);
    $publicKey  = trim((string)$cfg['public_key']);
    $privateKey = trim((string)$cfg['private_key']);
    $sigMode    = (string)$cfg['sig_mode'];
    $rsaPem     = trim((string)$cfg['rsa_private_pem']);
    $cacheTtl   = max(0, (int)$cfg['cache_ttl']);

    if ($apiUrl === '' || $publicKey === '' || $privateKey === '') {
        return new WP_Error('ilmuna_not_configured', 'Konfigurasi API Ilmuna Learn (URL, Public Key, Private Key) belum lengkap.');
    }

    $params = ['resource' => (string)$resource];
    foreach ($extraParams as $k => $v) {
        if ($v !== '' && $v !== null) {
            $params[(string)$k] = (string)$v;
        }
    }
    ksort($params, SORT_STRING);

    $cacheKey = 'ilmuna_api_' . md5($apiUrl . '|' . $publicKey . '|' . wp_json_encode($params));
    if (!$forceRefresh && $cacheTtl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    // Generate timestamp & nonce kriptografis sekali pakai
    $timestamp = (string)time();
    try {
        $nonce = bin2hex(random_bytes(16));
    } catch (Exception $e) {
        $nonce = wp_generate_password(32, false, false);
    }

    $canonicalPath  = ilmuna_conn_canonical_path($apiUrl);
    $canonicalQuery = ilmuna_conn_canonical_query($params);
    $bodyHash       = hash('sha256', '');
    $canonical      = implode("\n", ['GET', $canonicalPath, $canonicalQuery, $timestamp, $nonce, $bodyHash]);

    $siteHost = parse_url(home_url(), PHP_URL_HOST);
    if (!is_string($siteHost) || $siteHost === '') {
        $siteHost = 'ilmuna.my.id';
    }

    $headers = [
        'Accept'              => 'application/json',
        'X-Ilmuna-Public-Key' => $publicKey,
        'X-Ilmuna-Timestamp'  => $timestamp,
        'X-Ilmuna-Nonce'      => $nonce,
        'X-Ilmuna-Site'       => $siteHost,
    ];

    if ($sigMode === 'HMAC-SHA256' || $sigMode === 'DUAL') {
        $headers['X-Ilmuna-Signature'] = hash_hmac('sha256', $canonical, $privateKey);
    }

    if ($sigMode === 'RSA-SHA256' || $sigMode === 'DUAL') {
        if ($rsaPem === '' || !function_exists('openssl_sign')) {
            return new WP_Error('ilmuna_rsa_missing', 'Mode ' . $sigMode . ' memerlukan RSA Private Key PEM dan ekstensi OpenSSL aktif.');
        }
        $rawSig = '';
        if (!@openssl_sign($canonical, $rawSig, $rsaPem, OPENSSL_ALGO_SHA256)) {
            return new WP_Error('ilmuna_rsa_sign_failed', 'Gagal menandatangani request dengan RSA Private Key PEM.');
        }
        $headers['X-Ilmuna-RSA-Signature'] = base64_encode($rawSig);
    }

    $targetUrl = $apiUrl . (strpos($apiUrl, '?') === false ? '?' : '&') . $canonicalQuery;

    $response = wp_remote_get($targetUrl, [
        'timeout'     => 15,
        'redirection' => 2,
        'httpversion' => '1.1',
        'user-agent'  => 'Ilmuna-WP-Connector/' . ILMUNA_WP_CONN_VERSION . ' (+https://' . $siteHost . ')',
        'headers'     => $headers,
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $httpCode = (int)wp_remote_retrieve_response_code($response);
    $rawBody  = (string)wp_remote_retrieve_body($response);
    $decoded  = json_decode($rawBody, true);

    if ($httpCode !== 200 || !is_array($decoded) || empty($decoded['success'])) {
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            $errMsg = $decoded['error']['message'] . ' [' . ($decoded['error']['code'] ?? $httpCode) . ']';
        } else {
            $ct = (string)wp_remote_retrieve_header($response, 'content-type');
            $titleHint = '';
            if (preg_match('#<title[^>]*>(.*?)</title>#is', $rawBody, $tm)) {
                $titleHint = ' · Judul HTML: "' . trim(wp_strip_all_tags($tm[1])) . '"';
            }
            $errMsg = 'Respon HTTP ' . $httpCode . ' bukan JSON valid dari server Ilmuna Learn'
                . ($ct !== '' ? ' (Content-Type: ' . $ct . ')' : '')
                . $titleHint
                . '. Pastikan endpoint sudah memakai /api/wp/wp-broadcast.php dan deploy terbaru sudah dijalankan.';
        }
        return new WP_Error('ilmuna_api_http_error', $errMsg);
    }

    // Verifikasi Signature Respon dari Server (Anti-MITM / Anti-Tamper)
    if (!empty($cfg['verify_response'])) {
        $respTs  = (string)wp_remote_retrieve_header($response, 'x-ilmuna-response-timestamp');
        $respSig = strtolower(trim((string)wp_remote_retrieve_header($response, 'x-ilmuna-response-signature')));
        if ($respTs === '' || $respSig === '') {
            return new WP_Error('ilmuna_resp_sig_missing', 'Header verifikasi respon (X-Ilmuna-Response-Signature) tidak ditemukan.');
        }
        if (abs(time() - (int)$respTs) > 300) {
            return new WP_Error('ilmuna_resp_ts_skew', 'Timestamp respon server melampaui batas toleransi 5 menit.');
        }
        $expectedRespSig = hash_hmac('sha256', $respTs . "\n" . $rawBody, $privateKey);
        if (!hash_equals($expectedRespSig, $respSig)) {
            return new WP_Error('ilmuna_resp_sig_invalid', 'Tanda tangan respon server Ilmuna Learn tidak valid (potensi manipulasi data).');
        }
    }

    if ($cacheTtl > 0) {
        set_transient($cacheKey, $decoded, $cacheTtl);
    }

    return $decoded;
}

/**
 * Bersihkan seluruh cache transient milik plugin ini
 */
function ilmuna_conn_clear_cache()
{
    global $wpdb;
    $like1 = $wpdb->esc_like('_transient_ilmuna_api_') . '%';
    $like2 = $wpdb->esc_like('_transient_timeout_ilmuna_api_') . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like1, $like2));
}

/* ==============================================================================
 * MENU & HALAMAN PENGATURAN DI WORDPRESS ADMIN
 * ============================================================================== */
add_action('admin_menu', function () {
    add_options_page(
        'Ilmuna Learn API Connector',
        'Ilmuna Learn API',
        'manage_options',
        'ilmuna-learn-api',
        'ilmuna_conn_render_settings_page'
    );
});

function ilmuna_conn_render_settings_page()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = '';
    $noticeType = 'updated';
    $testResult = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ilmuna_conn_action'])) {
        check_admin_referer('ilmuna_conn_settings_nonce');
        $act = sanitize_text_field(wp_unslash($_POST['ilmuna_conn_action']));

        if ($act === 'save') {
            $old = ilmuna_conn_get_settings();
            $newPrivate = isset($_POST['private_key']) ? trim(wp_unslash($_POST['private_key'])) : '';
            $newRsaPem  = isset($_POST['rsa_private_pem']) ? trim(wp_unslash($_POST['rsa_private_pem'])) : '';
            $sigMode    = sanitize_text_field(wp_unslash($_POST['sig_mode'] ?? 'HMAC-SHA256'));
            if (!in_array($sigMode, ['HMAC-SHA256', 'RSA-SHA256', 'DUAL'], true)) {
                $sigMode = 'HMAC-SHA256';
            }

            $save = [
                'api_url'         => esc_url_raw(trim(wp_unslash($_POST['api_url'] ?? 'https://learn.ilmuna.my.id/api/wp/wp-broadcast.php'))),
                'public_key'      => sanitize_text_field(wp_unslash($_POST['public_key'] ?? '')),
                'private_key'     => ($newPrivate !== '') ? $newPrivate : ($old['private_key'] ?? ''),
                'sig_mode'        => $sigMode,
                'rsa_private_pem' => ($newRsaPem !== '') ? $newRsaPem : ($old['rsa_private_pem'] ?? ''),
                'cache_ttl'       => max(0, min(3600, (int)($_POST['cache_ttl'] ?? 180))),
                'verify_response' => !empty($_POST['verify_response']) ? 1 : 0,
            ];
            update_option(ILMUNA_WP_CONN_OPTION, $save, false);
            ilmuna_conn_clear_cache();
            $notice = 'Konfigurasi API Ilmuna Learn berhasil disimpan dan cache telah disegarkan.';
        } elseif ($act === 'test') {
            ilmuna_conn_clear_cache();
            $res = ilmuna_conn_request('health', [], true);
            if (is_wp_error($res)) {
                $noticeType = 'error';
                $notice = 'Koneksi Gagal: ' . $res->get_error_message();
            } else {
                $testResult = $res;
                $notice = 'Koneksi Terverifikasi Kriptografis! Berhasil terhubung ke ' . ($res['data']['school']['nama_sekolah'] ?? 'Ilmuna Learn') . '.';
            }
        } elseif ($act === 'clear_cache') {
            ilmuna_conn_clear_cache();
            $notice = 'Seluruh cache Transient Ilmuna Learn berhasil dibersihkan.';
        }
    }

    $cfg = ilmuna_conn_get_settings();
    $privLocked = defined('ILMUNA_API_PRIVATE_KEY') && ILMUNA_API_PRIVATE_KEY !== '';
    ?>
    <div class="wrap">
        <h1>Ilmuna Learn API Connector v<?php echo esc_html(ILMUNA_WP_CONN_VERSION); ?> (Lumina Gold Edition)</h1>
        <p>Hubungkan WordPress (<code><?php echo esc_html(parse_url(home_url(), PHP_URL_HOST)); ?></code>) secara aman ke <code>learn.ilmuna.my.id</code>.</p>

        <?php if ($notice !== ''): ?>
            <div class="notice notice-<?php echo esc_attr($noticeType); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('ilmuna_conn_settings_nonce'); ?>
            <input type="hidden" name="ilmuna_conn_action" value="save">
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ilmuna_api_url">Endpoint API URL</label></th>
                    <td>
                        <input name="api_url" id="ilmuna_api_url" type="url" class="regular-text code" style="width:480px;"
                               value="<?php echo esc_attr($cfg['api_url']); ?>" required>
                        <p class="description">Default: <code>https://learn.ilmuna.my.id/api/wp/wp-broadcast.php</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ilmuna_public_key">Public Key</label></th>
                    <td>
                        <input name="public_key" id="ilmuna_public_key" type="text" class="regular-text code" style="width:480px;"
                               value="<?php echo esc_attr($cfg['public_key']); ?>" placeholder="pk_ilmuna_..." required>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ilmuna_private_key">Private Key</label></th>
                    <td>
                        <?php if ($privLocked): ?>
                            <input type="text" class="regular-text code" style="width:480px;" value="Dikonfigurasi lewat wp-config.php (ILMUNA_API_PRIVATE_KEY)" disabled>
                        <?php else: ?>
                            <input name="private_key" id="ilmuna_private_key" type="password" class="regular-text code" style="width:480px;"
                                   value="" autocomplete="new-password"
                                   placeholder="<?php echo !empty($cfg['private_key']) ? 'Tersimpan (kosongkan jika tidak mengubah)' : 'sk_ilmuna_...'; ?>">
                            <p class="description">Private Key <strong>tidak pernah dikirim</strong> ke jaringan — hanya dipakai menghitung tanda tangan HMAC-SHA256 secara lokal di server WordPress.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ilmuna_sig_mode">Mode Tanda Tangan</label></th>
                    <td>
                        <select name="sig_mode" id="ilmuna_sig_mode">
                            <option value="HMAC-SHA256" <?php selected($cfg['sig_mode'], 'HMAC-SHA256'); ?>>HMAC-SHA256 (Public + Private Key — Direkomendasikan)</option>
                            <option value="RSA-SHA256" <?php selected($cfg['sig_mode'], 'RSA-SHA256'); ?>>RSA-2048 Asimetris (RSA Private Key PEM)</option>
                            <option value="DUAL" <?php selected($cfg['sig_mode'], 'DUAL'); ?>>DUAL (HMAC-SHA256 + RSA-2048 Sekaligus — Proteksi Maksimal)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ilmuna_rsa_pem">RSA-2048 Private Key PEM (Opsional)</label></th>
                    <td>
                        <textarea name="rsa_private_pem" id="ilmuna_rsa_pem" rows="4" class="large-text code"
                                  placeholder="<?php echo !empty($cfg['rsa_private_pem']) ? 'Tersimpan (kosongkan jika tidak mengubah)' : '-----BEGIN PRIVATE KEY----- (hanya diisi jika mode RSA-SHA256 atau DUAL)'; ?>"></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ilmuna_cache_ttl">Durasi Cache (detik)</label></th>
                    <td>
                        <input name="cache_ttl" id="ilmuna_cache_ttl" type="number" min="0" max="3600"
                               value="<?php echo (int)$cfg['cache_ttl']; ?>" class="small-text"> detik
                        <p class="description">Rekomendasi: <code>180</code> (3 menit) agar halaman WordPress tetap sangat cepat.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Verifikasi Respon Server</th>
                    <td>
                        <label>
                            <input type="checkbox" name="verify_response" value="1" <?php checked((int)$cfg['verify_response'], 1); ?>>
                            Wajib verifikasi <code>X-Ilmuna-Response-Signature</code> dari <code>learn.ilmuna.my.id</code>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button('Simpan Pengaturan API'); ?>
        </form>

        <hr>
        <h2>Uji Koneksi &amp; Manajemen Cache</h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
            <form method="post" action="">
                <?php wp_nonce_field('ilmuna_conn_settings_nonce'); ?>
                <input type="hidden" name="ilmuna_conn_action" value="test">
                <button type="submit" class="button button-primary">Uji Koneksi API (Signed Ping)</button>
            </form>
            <form method="post" action="">
                <?php wp_nonce_field('ilmuna_conn_settings_nonce'); ?>
                <input type="hidden" name="ilmuna_conn_action" value="clear_cache">
                <button type="submit" class="button">Bersihkan Cache Transient</button>
            </form>
        </div>

        <?php if (is_array($testResult)): ?>
            <pre style="background:#0F172A;color:#E2E8F0;padding:14px;border-radius:8px;overflow:auto;max-height:260px;"><?php echo esc_html(wp_json_encode($testResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></pre>
        <?php endif; ?>

        <hr>
        <h2>Daftar Shortcode Siap Pakai</h2>
        <table class="widefat striped" style="max-width:960px;">
            <thead>
                <tr><th>Shortcode</th><th>Fungsi Tampilan</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>[ilmuna_broadcast_all limit_showcase="6" limit_tugas="8" limit_proyek="6"]</code></td>
                    <td>Widget Terpadu 3 Tab bertema Lumina Gold (Karya Proyek Siswa, Broadcast Deadline Tugas, Broadcast Deadline Proyek).</td>
                </tr>
                <tr>
                    <td><code>[ilmuna_proyek_showcase limit="6" columns="3"]</code></td>
                    <td>Galeri Hasil Karya Proyek Siswa (thumbnail dokumentasi, sudut batik Lumina, predikat P5, tim/kelompok, tombol produk &amp; video).</td>
                </tr>
                <tr>
                    <td><code>[ilmuna_broadcast_tugas limit="10" upcoming_only="0"]</code></td>
                    <td>Broadcast Daftar Tugas &amp; Countdown Deadline Tugas terbaru (urut tenggat terdekat).</td>
                </tr>
                <tr>
                    <td><code>[ilmuna_broadcast_proyek limit="6" upcoming_only="0"]</code></td>
                    <td>Broadcast Daftar Proyek PjBL/P5 &amp; Countdown Deadline Proyek lengkap dengan dimensi P5 &amp; target luaran.</td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}

/* ==============================================================================
 * STYLING 100% SERASI TEMA WORDPRESS "LUMINA GOLD" (LIGHT + DARK MODE)
 * ==============================================================================
 * Token Resmi Tema lumina-gold:
 *   Navy: #1D3B5E | Teal: #0D7A87 | Sky: #52B2DC | Gold: #F59E0B
 *   Font Heading: 'Outfit' | Font Body: 'Plus Jakarta Sans'
 *   Z-Index: 60 + isolation: isolate (TEGAS DI ATAS #ilmuna-ornaments z-index:0
 *   & .ilmuna-posts-section z-index:50, tetap di bawah Navbar sticky z-index:1000)
 *   Surface 100% Solid Opaque (zero bleed-through / tidak transparan!)
 * ============================================================================== */
function ilmuna_conn_enqueue_styles_once()
{
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;
    return '<style id="ilmuna-wp-connector-css">
/* ===== LAYER ISOLASI & TOKEN LUMINA GOLD ===== */
.ilmw-wrap{
    --ilmw-navy:#1D3B5E;
    --ilmw-teal:#0D7A87;
    --ilmw-sky:#52B2DC;
    --ilmw-sky-soft:#7CC5E5;
    --ilmw-gold:#F59E0B;
    --ilmw-gold-deep:#D97706;
    --ilmw-grad:linear-gradient(135deg,#1D3B5E 0%,#0D7A87 52%,#52B2DC 100%);
    --ilmw-grad-hover:linear-gradient(135deg,#254B77 0%,#1197A7 52%,#68C2E7 100%);
    --ilmw-surface:#FFFFFF;
    --ilmw-surface-soft:#F4F8FA;
    --ilmw-surface-panel:#F8FBFD;
    --ilmw-border:#DEE7EE;
    --ilmw-ink:#0F1D2F;
    --ilmw-body:#3E5168;
    --ilmw-muted:#647B94;
    --ilmw-shadow:0 12px 32px -12px rgba(29,59,94,.16),0 2px 8px rgba(29,59,94,.06);
    --ilmw-shadow-hover:0 22px 46px -16px rgba(13,122,135,.28),0 0 0 1px rgba(82,178,220,.45);
    --ilmw-font-h:"Outfit",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    --ilmw-font-b:"Plus Jakarta Sans",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;

    position:relative !important;
    z-index:60 !important;
    isolation:isolate !important;
    display:block !important;
    width:100% !important;
    margin:24px auto !important;
    padding:0 !important;
    font-family:var(--ilmw-font-b) !important;
    color:var(--ilmw-body) !important;
    line-height:1.6 !important;
    opacity:1 !important;
    -webkit-font-smoothing:antialiased;
}
.ilmw-wrap *,.ilmw-wrap *::before,.ilmw-wrap *::after{box-sizing:border-box}

/* ===== SHELL KONTAINER SOLID (ANTI TEMBUS ORNAMEN) ===== */
.ilmw-shell{
    position:relative;
    z-index:2;
    background:var(--ilmw-surface-panel) !important;
    border:1px solid var(--ilmw-border) !important;
    border-radius:24px !important;
    padding:26px 24px 28px !important;
    box-shadow:0 16px 42px -18px rgba(29,59,94,.18),0 2px 10px rgba(29,59,94,.05) !important;
    overflow:hidden;
    opacity:1 !important;
}
.ilmw-shell::before{
    content:"";
    position:absolute;
    top:0;left:0;right:0;height:3px;
    background:var(--ilmw-grad);
    z-index:3;
}
@media(max-width:640px){.ilmw-shell{padding:18px 14px 20px !important;border-radius:18px !important}}

/* ===== SIGNATURE LUMINA SECTION HEADER ===== */
.ilmw-sec-head{
    position:relative;
    text-align:center;
    max-width:640px;
    margin:0 auto 22px !important;
    padding:0 12px;
}
.ilmw-eyebrow{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:10px;
    margin-bottom:6px;
}
.ilmw-eyebrow-line{
    width:28px;height:1.5px;
    background:linear-gradient(90deg,transparent,#52B2DC);
}
.ilmw-eyebrow-line:last-child{
    background:linear-gradient(90deg,#52B2DC,transparent);
}
.ilmw-eyebrow-txt{
    font-family:var(--ilmw-font-h);
    font-size:10.5px;
    font-weight:800;
    letter-spacing:2.4px;
    text-transform:uppercase;
    color:var(--ilmw-teal);
}
.ilmw-sec-title{
    font-family:var(--ilmw-font-h) !important;
    font-size:clamp(21px,2.8vw,28px) !important;
    font-weight:800 !important;
    line-height:1.22 !important;
    margin:0 0 8px 0 !important;
    letter-spacing:-.015em;
    background:linear-gradient(120deg,#1D3B5E 0%,#0D7A87 52%,#52B2DC 100%);
    -webkit-background-clip:text;
    background-clip:text;
    -webkit-text-fill-color:transparent;
    color:#1D3B5E;
}
.ilmw-divider{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    margin:0 auto 8px;
}
.ilmw-divider-line{
    width:42px;height:1.5px;
    background:linear-gradient(90deg,transparent,rgba(82,178,220,.85));
}
.ilmw-divider-line:last-child{
    background:linear-gradient(90deg,rgba(82,178,220,.85),transparent);
}
.ilmw-divider-gem{
    width:10px;height:10px;
    background:var(--ilmw-grad);
    transform:rotate(45deg);
    border-radius:2px;
    box-shadow:0 0 10px rgba(82,178,220,.55);
}
.ilmw-sec-sub{
    margin:0 !important;
    font-size:13.5px;
    color:var(--ilmw-muted);
    line-height:1.55;
}

/* ===== SEGMENTED TABS LUMINA ===== */
.ilmw-tabs{
    display:flex !important;
    flex-wrap:wrap;
    justify-content:center;
    gap:8px;
    padding:7px !important;
    background:var(--ilmw-surface) !important;
    border:1px solid var(--ilmw-border) !important;
    border-radius:16px !important;
    margin:0 0 22px 0 !important;
    box-shadow:0 6px 20px -8px rgba(29,59,94,.12) !important;
}
.ilmw-tab-btn{
    appearance:none !important;
    border:1px solid transparent !important;
    background:transparent !important;
    padding:10px 18px !important;
    border-radius:11px !important;
    font-family:var(--ilmw-font-h) !important;
    font-weight:700 !important;
    font-size:13.5px !important;
    color:var(--ilmw-body) !important;
    cursor:pointer;
    display:inline-flex !important;
    align-items:center;
    gap:8px;
    transition:all .28s cubic-bezier(.16,1,.3,1);
    line-height:1.2 !important;
}
.ilmw-tab-btn svg{width:16px;height:16px;flex-shrink:0}
.ilmw-tab-btn:hover{
    color:var(--ilmw-teal) !important;
    background:var(--ilmw-surface-soft) !important;
    border-color:rgba(82,178,220,.35) !important;
}
.ilmw-tab-btn.is-active{
    background:var(--ilmw-grad) !important;
    color:#FFFFFF !important;
    border-color:transparent !important;
    box-shadow:0 8px 20px -4px rgba(13,122,135,.42) !important;
}
.ilmw-tab-cnt{
    background:rgba(29,59,94,.09);
    color:var(--ilmw-navy);
    padding:2px 8px;
    border-radius:9999px;
    font-size:11.5px;
    font-weight:800;
    min-width:22px;
    text-align:center;
}
.ilmw-tab-btn.is-active .ilmw-tab-cnt{
    background:rgba(255,255,255,.24);
    color:#FFFFFF;
}
.ilmw-pane{display:none !important}
.ilmw-pane.is-active{display:block !important;animation:ilmwFadeUp .4s cubic-bezier(.16,1,.3,1) both}
@keyframes ilmwFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* ===== SHOWCASE GRID CARDS (100% SOLID OPAQUE) ===== */
.ilmw-grid{
    display:grid !important;
    grid-template-columns:repeat(var(--ilmw-cols,3),minmax(0,1fr));
    gap:20px;
}
@media(max-width:960px){.ilmw-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.ilmw-grid{grid-template-columns:1fr}}

.ilmw-card{
    position:relative;
    background:var(--ilmw-surface) !important;
    border:1px solid var(--ilmw-border) !important;
    border-radius:18px !important;
    overflow:hidden;
    display:flex !important;
    flex-direction:column;
    box-shadow:var(--ilmw-shadow) !important;
    transition:transform .38s cubic-bezier(.16,1,.3,1),box-shadow .38s ease,border-color .3s ease;
    opacity:1 !important;
    isolation:isolate;
}
.ilmw-card:hover{
    transform:translateY(-5px);
    box-shadow:var(--ilmw-shadow-hover) !important;
    border-color:rgba(82,178,220,.55) !important;
}
.ilmw-thumb{
    position:relative;
    width:100%;
    aspect-ratio:16/10;
    background:linear-gradient(135deg,#0D1A2A 0%,#1D3B5E 48%,#0D7A87 100%) !important;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}
.ilmw-thumb::after{
    content:"";
    position:absolute;inset:0;
    background:linear-gradient(180deg,rgba(7,14,23,.52) 0%,rgba(7,14,23,.08) 48%,rgba(13,26,42,.42) 100%);
    pointer-events:none;
    z-index:2;
}
.ilmw-thumb img{
    width:100% !important;
    height:100% !important;
    object-fit:cover !important;
    display:block;
    transition:transform .8s cubic-bezier(.16,1,.3,1);
    position:relative;
    z-index:1;
}
.ilmw-card:hover .ilmw-thumb img{transform:scale(1.06)}
.ilmw-thumb-ph{
    position:relative;z-index:3;
    padding:20px;
    text-align:center;
    color:#FFFFFF;
    font-family:var(--ilmw-font-h);
    font-weight:800;
    font-size:15px;
    letter-spacing:.02em;
    text-shadow:0 2px 10px rgba(0,0,0,.35);
}
/* Ornamen sudut batik khas Lumina Gold */
.ilmw-corner{
    position:absolute;width:24px;height:24px;z-index:4;pointer-events:none;
    color:#52B2DC;opacity:.65;transition:opacity .35s,transform .4s;
    filter:drop-shadow(0 0 5px rgba(82,178,220,.65));
}
.ilmw-corner.tl{top:6px;left:6px}
.ilmw-corner.tr{top:6px;right:6px;transform:scaleX(-1)}
.ilmw-corner.bl{bottom:6px;left:6px;transform:scaleY(-1)}
.ilmw-corner.br{bottom:6px;right:6px;transform:scale(-1,-1)}
.ilmw-card:hover .ilmw-corner{opacity:1;color:#B9E2F5}

.ilmw-badge-float{
    position:absolute;top:12px;left:12px;z-index:5;
    background:rgba(11,21,35,.86) !important;
    border:1px solid rgba(82,178,220,.45);
    color:#FFFFFF !important;
    padding:4px 10px;border-radius:8px;
    font-family:var(--ilmw-font-h);
    font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
    box-shadow:0 4px 12px rgba(0,0,0,.28);
    max-width:calc(100% - 105px);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
}
.ilmw-score-float{
    position:absolute;top:12px;right:12px;z-index:5;
    background:linear-gradient(135deg,#D97706,#F59E0B) !important;
    color:#FFFFFF !important;
    padding:4px 10px;border-radius:9999px;
    font-family:var(--ilmw-font-h);
    font-size:11px;font-weight:800;
    box-shadow:0 4px 14px rgba(245,158,11,.45);
}

.ilmw-body{
    position:relative;
    padding:18px 18px 16px !important;
    display:flex !important;
    flex-direction:column;
    gap:10px;
    flex:1;
    background:var(--ilmw-surface) !important;
}
.ilmw-body::before{
    content:"";
    position:absolute;top:0;left:0;right:0;height:2px;
    background:linear-gradient(90deg,transparent,rgba(82,178,220,.65),transparent);
}

.ilmw-meta-row{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.ilmw-pill{
    display:inline-flex;align-items:center;gap:5px;
    padding:3.5px 10px;border-radius:9999px;
    font-family:var(--ilmw-font-h);
    font-size:11px;font-weight:700;letter-spacing:.02em;
    background:#EEF4F8;color:#28425E;
    border:1px solid #D8E4EE;
    line-height:1.3;
}
.ilmw-pill--teal{background:#E6F7F8;color:#0A636E;border-color:#B5E8EC}
.ilmw-pill--navy{background:#E8EFF7;color:#1D3B5E;border-color:#C7D8EB}
.ilmw-pill--sky{background:#E8F6FC;color:#0E6B91;border-color:#BBE4F5}
.ilmw-pill--gold{background:#FEF6DC;color:#A16207;border-color:#FDE08D}
.ilmw-pill--danger{background:#FEE2E2;color:#B91C1C;border-color:#FCA5A5}
.ilmw-pill--muted{background:#EEF2F6;color:#475569;border-color:#CBD5E1}

.ilmw-pulse-dot{
    width:7px;height:7px;border-radius:50%;display:inline-block;background:currentColor;
    box-shadow:0 0 0 0 currentColor;
    animation:ilmwPulse 1.8s infinite;
}
@keyframes ilmwPulse{
    0%{box-shadow:0 0 0 0 rgba(13,122,135,.55)}
    70%{box-shadow:0 0 0 7px rgba(13,122,135,0)}
    100%{box-shadow:0 0 0 0 rgba(13,122,135,0)}
}

.ilmw-title{
    margin:0 !important;
    font-family:var(--ilmw-font-h) !important;
    font-size:16.5px !important;
    font-weight:800 !important;
    color:var(--ilmw-ink) !important;
    line-height:1.35 !important;
    letter-spacing:-.01em;
}
.ilmw-desc{
    margin:0 !important;
    font-size:13px !important;
    color:var(--ilmw-body) !important;
    line-height:1.58 !important;
}
.ilmw-creator{
    font-size:12.5px;
    color:var(--ilmw-ink);
    background:var(--ilmw-surface-soft) !important;
    border:1px solid var(--ilmw-border);
    border-radius:12px;
    padding:10px 12px;
    display:flex;
    flex-direction:column;
    gap:3px;
}
.ilmw-creator-sub{font-size:11.5px;color:var(--ilmw-muted);line-height:1.45}

.ilmw-actions{
    margin-top:auto;
    padding-top:12px;
    border-top:1px solid var(--ilmw-border);
    display:flex;
    flex-wrap:wrap;
    gap:8px;
}
.ilmw-btn{
    display:inline-flex !important;
    align-items:center;
    justify-content:center;
    gap:6px;
    padding:8px 15px !important;
    border-radius:10px !important;
    font-family:var(--ilmw-font-h) !important;
    font-size:12.5px !important;
    font-weight:700 !important;
    text-decoration:none !important;
    transition:all .25s cubic-bezier(.16,1,.3,1);
    line-height:1.2 !important;
    cursor:pointer;
}
.ilmw-btn svg{width:14px;height:14px;flex-shrink:0}
.ilmw-btn--primary{
    background:var(--ilmw-grad) !important;
    color:#FFFFFF !important;
    border:none !important;
    box-shadow:0 6px 16px -4px rgba(13,122,135,.38);
}
.ilmw-btn--primary:hover{
    background:var(--ilmw-grad-hover) !important;
    color:#FFFFFF !important;
    transform:translateY(-2px);
    box-shadow:0 10px 24px -6px rgba(13,122,135,.52);
}
.ilmw-btn--ghost{
    background:var(--ilmw-surface) !important;
    color:var(--ilmw-navy) !important;
    border:1px solid var(--ilmw-border) !important;
}
.ilmw-btn--ghost:hover{
    border-color:var(--ilmw-sky) !important;
    color:var(--ilmw-teal) !important;
    background:var(--ilmw-surface-soft) !important;
}

/* ===== BROADCAST LIST ROWS (100% SOLID OPAQUE) ===== */
.ilmw-list{display:flex !important;flex-direction:column;gap:14px}
.ilmw-row{
    position:relative;
    background:var(--ilmw-surface) !important;
    border:1px solid var(--ilmw-border) !important;
    border-left:5px solid var(--ilmw-teal) !important;
    border-radius:16px !important;
    padding:18px 20px !important;
    display:flex !important;
    justify-content:space-between;
    align-items:center;
    gap:18px;
    flex-wrap:wrap;
    box-shadow:var(--ilmw-shadow) !important;
    transition:transform .28s cubic-bezier(.16,1,.3,1),box-shadow .28s ease,border-color .28s ease;
    opacity:1 !important;
}
.ilmw-row:hover{
    transform:translateY(-2px);
    box-shadow:var(--ilmw-shadow-hover) !important;
}
.ilmw-row--kritis{border-left-color:#DC2626 !important;background:linear-gradient(90deg,#FFF5F5 0%,#FFFFFF 22%) !important}
.ilmw-row--segera{border-left-color:#F59E0B !important;background:linear-gradient(90deg,#FFFBEB 0%,#FFFFFF 22%) !important}
.ilmw-row--normal{border-left-color:#0D7A87 !important}
.ilmw-row--berakhir{border-left-color:#94A3B8 !important;background:#F8FAFC !important}

.ilmw-row-main{flex:1;min-width:250px;display:flex;flex-direction:column;gap:6px}
.ilmw-row-side{
    display:flex;flex-direction:column;align-items:flex-end;gap:8px;min-width:195px;
    padding-left:16px;border-left:1px dashed var(--ilmw-border);
}
@media(max-width:720px){
    .ilmw-row{padding:15px 14px !important}
    .ilmw-row-side{align-items:flex-start;width:100%;padding-left:0;padding-top:10px;border-left:none;border-top:1px dashed var(--ilmw-border)}
}
.ilmw-deadline-box{
    display:inline-flex;align-items:center;gap:6px;
    font-size:12.5px;font-weight:700;color:var(--ilmw-ink);
    background:var(--ilmw-surface-soft);
    border:1px solid var(--ilmw-border);
    padding:5px 10px;border-radius:9px;
}
.ilmw-deadline-box svg{width:14px;height:14px;color:var(--ilmw-teal);flex-shrink:0}
.ilmw-tema-line{
    font-family:var(--ilmw-font-h);font-size:12.5px;font-weight:700;color:var(--ilmw-teal);
}
.ilmw-foot-meta{
    display:flex;flex-wrap:wrap;gap:12px;align-items:center;
    font-size:12px;color:var(--ilmw-muted);margin-top:2px;
}
.ilmw-foot-meta strong{color:var(--ilmw-ink)}

.ilmw-empty{
    padding:36px 24px;text-align:center;
    background:var(--ilmw-surface) !important;
    border:1.5px dashed var(--ilmw-border);
    border-radius:16px;color:var(--ilmw-muted);font-size:14px;font-weight:600;
}
.ilmw-err{
    padding:16px 18px;background:#FEF2F2 !important;
    border:1px solid #FECACA;border-radius:14px;
    color:#991B1B;font-size:13.5px;font-weight:600;
}

/* ===== AUTOMATIC DARK MODE (SERASI DENGAN LUMINA GOLD A11Y DARK THEME) ===== */
html[data-ilmuna-theme="dark"] .ilmw-wrap,
body.dark-mode .ilmw-wrap{
    --ilmw-surface:#102035;
    --ilmw-surface-soft:#0B1626;
    --ilmw-surface-panel:#0D1A2B;
    --ilmw-border:rgba(82,178,220,.24);
    --ilmw-ink:#F1F5F9;
    --ilmw-body:#CBD5E1;
    --ilmw-muted:#94A3B8;
    --ilmw-shadow:0 16px 38px -14px rgba(0,0,0,.55);
}
html[data-ilmuna-theme="dark"] .ilmw-sec-title,
body.dark-mode .ilmw-sec-title{
    background:linear-gradient(120deg,#FFFFFF 0%,#52B2DC 52%,#7CC5E5 100%);
    -webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;
}
html[data-ilmuna-theme="dark"] .ilmw-eyebrow-txt,
body.dark-mode .ilmw-eyebrow-txt{color:#52B2DC}
html[data-ilmuna-theme="dark"] .ilmw-pill,
body.dark-mode .ilmw-pill{background:#162B45;color:#E2E8F0;border-color:rgba(82,178,220,.25)}
html[data-ilmuna-theme="dark"] .ilmw-pill--teal,
body.dark-mode .ilmw-pill--teal{background:rgba(13,122,135,.28);color:#7DE2ED;border-color:rgba(82,178,220,.38)}
html[data-ilmuna-theme="dark"] .ilmw-pill--navy,
body.dark-mode .ilmw-pill--navy{background:rgba(82,178,220,.18);color:#B9E2F5;border-color:rgba(82,178,220,.35)}
html[data-ilmuna-theme="dark"] .ilmw-pill--gold,
body.dark-mode .ilmw-pill--gold{background:rgba(245,158,11,.2);color:#FDE68A;border-color:rgba(245,158,11,.4)}
html[data-ilmuna-theme="dark"] .ilmw-pill--danger,
body.dark-mode .ilmw-pill--danger{background:rgba(220,38,38,.25);color:#FCA5A5;border-color:rgba(248,113,113,.42)}
html[data-ilmuna-theme="dark"] .ilmw-row--kritis,
body.dark-mode .ilmw-row--kritis{background:linear-gradient(90deg,rgba(220,38,38,.16) 0%,#102035 28%) !important}
html[data-ilmuna-theme="dark"] .ilmw-row--segera,
body.dark-mode .ilmw-row--segera{background:linear-gradient(90deg,rgba(245,158,11,.15) 0%,#102035 28%) !important}
html[data-ilmuna-theme="dark"] .ilmw-row--berakhir,
body.dark-mode .ilmw-row--berakhir{background:#0D1A2B !important}
html[data-ilmuna-theme="dark"] .ilmw-tab-cnt,
body.dark-mode .ilmw-tab-cnt{background:rgba(82,178,220,.18);color:#B9E2F5}
html[data-ilmuna-theme="dark"] .ilmw-btn--ghost,
body.dark-mode .ilmw-btn--ghost{background:#152A44 !important;color:#E2E8F0 !important;border-color:rgba(82,178,220,.32) !important}
</style>';
}

function ilmuna_conn_svg_icon($name)
{
    $icons = [
        'sparkle'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l1.9 5.8a2 2 0 0 0 1.3 1.3L21 12l-5.8 1.9a2 2 0 0 0-1.3 1.3L12 21l-1.9-5.8a2 2 0 0 0-1.3-1.3L3 12l5.8-1.9a2 2 0 0 0 1.3-1.3z"/></svg>',
        'task'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>',
        'rocket'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        'external' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>',
        'play'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>',
        'file'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        'corner'   => '<svg viewBox="0 0 40 40" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M4 34 Q 8 12, 30 6 Q 36 5, 36 11 Q 36 16, 29 16 Q 24 16, 24 11"/><circle cx="35" cy="11" r="1.8" fill="currentColor" stroke="none"/></svg>',
    ];
    return isset($icons[$name]) ? $icons[$name] : '';
}

function ilmuna_conn_render_section_header($eyebrow, $title, $subtitle = '')
{
    if ($title === '' && $eyebrow === '') {
        return '';
    }
    $out = '<div class="ilmw-sec-head">';
    if ($eyebrow !== '') {
        $out .= '<div class="ilmw-eyebrow"><span class="ilmw-eyebrow-line"></span><span class="ilmw-eyebrow-txt">' . esc_html($eyebrow) . '</span><span class="ilmw-eyebrow-line"></span></div>';
    }
    if ($title !== '') {
        $out .= '<h3 class="ilmw-sec-title">' . esc_html($title) . '</h3>';
    }
    $out .= '<div class="ilmw-divider"><span class="ilmw-divider-line"></span><span class="ilmw-divider-gem"></span><span class="ilmw-divider-line"></span></div>';
    if ($subtitle !== '') {
        $out .= '<p class="ilmw-sec-sub">' . esc_html($subtitle) . '</p>';
    }
    $out .= '</div>';
    return $out;
}

function ilmuna_conn_urgensi_badge($urgensi, $label)
{
    $map = [
        'kritis'   => 'ilmw-pill--danger',
        'segera'   => 'ilmw-pill--gold',
        'normal'   => 'ilmw-pill--teal',
        'berakhir' => 'ilmw-pill--muted',
    ];
    $cls = isset($map[$urgensi]) ? $map[$urgensi] : 'ilmw-pill--teal';
    $dot = ($urgensi !== 'berakhir') ? '<span class="ilmw-pulse-dot"></span>' : '';
    return '<span class="ilmw-pill ' . esc_attr($cls) . '">' . $dot . esc_html($label) . '</span>';
}

function ilmuna_conn_render_showcase_html(array $items, $columns = 3)
{
    if (empty($items)) {
        return '<div class="ilmw-empty">Belum ada hasil karya proyek siswa yang dipublikasikan.</div>';
    }
    $cols = max(1, min(4, (int)$columns));
    $cornerSvg = ilmuna_conn_svg_icon('corner');
    $out = '<div class="ilmw-grid" style="--ilmw-cols:' . $cols . ';">';
    foreach ($items as $it) {
        $thumb = !empty($it['thumbnail_url'])
            ? '<img src="' . esc_url($it['thumbnail_url']) . '" alt="' . esc_attr($it['judul_karya']) . '" loading="lazy">'
            : '<div class="ilmw-thumb-ph">' . esc_html($it['proyek']['tema_proyek'] ?: $it['mata_pelajaran']['nama']) . '</div>';

        $badgeFloat = esc_html(($it['kelas']['nama'] ?? '') . ' · ' . ($it['mata_pelajaran']['nama'] ?? ''));
        $scoreHtml  = ($it['nilai_proyek'] !== null) ? '<span class="ilmw-score-float">★ ' . esc_html(number_format((float)$it['nilai_proyek'], 0)) . '</span>' : '';

        $creatorLabel = esc_html($it['kreator']['nama_utama'] ?? 'Siswa');
        $anggotaNames = [];
        if (!empty($it['kreator']['tim']['anggota']) && is_array($it['kreator']['tim']['anggota'])) {
            foreach ($it['kreator']['tim']['anggota'] as $ag) {
                $anggotaNames[] = $ag['nama'] . (($ag['peran'] ?? '') === 'Ketua' ? ' (Ketua)' : '');
            }
        }

        $out .= '<article class="ilmw-card">';
        $out .= '<div class="ilmw-thumb">'
            . $thumb
            . '<span class="ilmw-corner tl" aria-hidden="true">' . $cornerSvg . '</span>'
            . '<span class="ilmw-corner tr" aria-hidden="true">' . $cornerSvg . '</span>'
            . '<span class="ilmw-corner bl" aria-hidden="true">' . $cornerSvg . '</span>'
            . '<span class="ilmw-corner br" aria-hidden="true">' . $cornerSvg . '</span>'
            . '<span class="ilmw-badge-float">' . $badgeFloat . '</span>'
            . $scoreHtml
            . '</div>';

        $out .= '<div class="ilmw-body">';
        $out .= '<div class="ilmw-meta-row">';
        if (!empty($it['predikat_p5'])) {
            $out .= '<span class="ilmw-pill ilmw-pill--gold">' . esc_html($it['predikat_p5']) . '</span>';
        }
        if (!empty($it['proyek']['tema_proyek'])) {
            $out .= '<span class="ilmw-pill ilmw-pill--teal">' . esc_html($it['proyek']['tema_proyek']) . '</span>';
        }
        $out .= '</div>';

        $out .= '<h4 class="ilmw-title">' . esc_html($it['judul_karya']) . '</h4>';
        if (!empty($it['deskripsi_karya'])) {
            $out .= '<p class="ilmw-desc">' . esc_html($it['deskripsi_karya']) . '</p>';
        }

        $out .= '<div class="ilmw-creator">';
        $out .= '<div><strong>' . esc_html($it['kreator']['tipe'] === 'Kelompok' ? 'Tim: ' : 'Karya: ') . '</strong>' . $creatorLabel . '</div>';
        if (!empty($anggotaNames)) {
            $out .= '<div class="ilmw-creator-sub">Anggota: ' . esc_html(implode(', ', $anggotaNames)) . '</div>';
        }
        $out .= '<div class="ilmw-creator-sub">Pembimbing: ' . esc_html($it['guru_pembimbing'] ?? '-') . '</div>';
        $out .= '</div>';

        $actions = '';
        if (!empty($it['link_produk'])) {
            $actions .= '<a class="ilmw-btn ilmw-btn--primary" href="' . esc_url($it['link_produk']) . '" target="_blank" rel="noopener noreferrer">' . ilmuna_conn_svg_icon('external') . '<span>Lihat Produk</span></a>';
        }
        if (!empty($it['link_video_presentasi'])) {
            $actions .= '<a class="ilmw-btn ilmw-btn--ghost" href="' . esc_url($it['link_video_presentasi']) . '" target="_blank" rel="noopener noreferrer">' . ilmuna_conn_svg_icon('play') . '<span>Video Presentasi</span></a>';
        }
        if (!empty($it['file_laporan_url'])) {
            $actions .= '<a class="ilmw-btn ilmw-btn--ghost" href="' . esc_url($it['file_laporan_url']) . '" target="_blank" rel="noopener noreferrer">' . ilmuna_conn_svg_icon('file') . '<span>Unduh Laporan</span></a>';
        } elseif (!empty($it['file_dokumentasi_url']) && $actions === '') {
            $actions .= '<a class="ilmw-btn ilmw-btn--ghost" href="' . esc_url($it['file_dokumentasi_url']) . '" target="_blank" rel="noopener noreferrer">' . ilmuna_conn_svg_icon('external') . '<span>Lihat Dokumentasi</span></a>';
        }
        if ($actions !== '') {
            $out .= '<div class="ilmw-actions">' . $actions . '</div>';
        }
        $out .= '</div></article>';
    }
    $out .= '</div>';
    return $out;
}

function ilmuna_conn_render_tugas_html(array $items)
{
    if (empty($items)) {
        return '<div class="ilmw-empty">Tidak ada broadcast tugas aktif saat ini.</div>';
    }
    $calIcon = ilmuna_conn_svg_icon('calendar');
    $extIcon = ilmuna_conn_svg_icon('external');
    $out = '<div class="ilmw-list">';
    foreach ($items as $it) {
        $urg = (string)($it['urgensi'] ?? 'normal');
        $out .= '<div class="ilmw-row ilmw-row--' . esc_attr($urg) . '">';
        $out .= '<div class="ilmw-row-main">';
        $out .= '<div class="ilmw-meta-row">';
        $out .= '<span class="ilmw-pill ilmw-pill--navy">' . esc_html($it['kelas']['nama'] ?? '-') . '</span>';
        $out .= '<span class="ilmw-pill ilmw-pill--teal">' . esc_html($it['mata_pelajaran']['nama'] ?? '-') . '</span>';
        $out .= '<span class="ilmw-pill ilmw-pill--sky">' . esc_html($it['jenis_penugasan'] ?? 'Individu') . '</span>';
        if (!empty($it['modul_ajar']['pertemuan_ke'])) {
            $out .= '<span class="ilmw-pill">Pertemuan ke-' . (int)$it['modul_ajar']['pertemuan_ke'] . '</span>';
        }
        $out .= '</div>';
        $out .= '<h4 class="ilmw-title">' . esc_html($it['judul_tugas']) . '</h4>';
        if (!empty($it['deskripsi_ringkas'])) {
            $out .= '<p class="ilmw-desc">' . esc_html($it['deskripsi_ringkas']) . '</p>';
        }
        $out .= '<div class="ilmw-foot-meta"><span>Guru Pengampu: <strong>' . esc_html($it['guru_pengampu'] ?? '-') . '</strong></span>';
        if (!empty($it['tipe_pengumpulan'])) {
            $out .= '<span>Format: <strong>' . esc_html($it['tipe_pengumpulan']) . '</strong></span>';
        }
        $out .= '</div></div>';

        $out .= '<div class="ilmw-row-side">';
        $out .= ilmuna_conn_urgensi_badge($urg, $it['sisa_waktu_label'] ?? '-');
        if (!empty($it['tenggat_waktu_formatted'])) {
            $out .= '<div class="ilmw-deadline-box">' . $calIcon . '<span>' . esc_html($it['tenggat_waktu_formatted']) . '</span></div>';
        }
        if (!empty($it['portal_url'])) {
            $out .= '<a class="ilmw-btn ilmw-btn--primary" href="' . esc_url($it['portal_url']) . '" target="_blank" rel="noopener noreferrer"><span>Kerjakan di LMS</span>' . $extIcon . '</a>';
        }
        $out .= '</div></div>';
    }
    $out .= '</div>';
    return $out;
}

function ilmuna_conn_render_proyek_html(array $items)
{
    if (empty($items)) {
        return '<div class="ilmw-empty">Tidak ada broadcast proyek aktif saat ini.</div>';
    }
    $calIcon = ilmuna_conn_svg_icon('calendar');
    $extIcon = ilmuna_conn_svg_icon('external');
    $out = '<div class="ilmw-list">';
    foreach ($items as $it) {
        $urg = (string)($it['urgensi'] ?? 'normal');
        $out .= '<div class="ilmw-row ilmw-row--' . esc_attr($urg) . '">';
        $out .= '<div class="ilmw-row-main">';
        $out .= '<div class="ilmw-meta-row">';
        $out .= '<span class="ilmw-pill ilmw-pill--navy">' . esc_html($it['kelas']['nama'] ?? '-') . '</span>';
        $out .= '<span class="ilmw-pill ilmw-pill--teal">' . esc_html($it['mata_pelajaran']['nama'] ?? '-') . '</span>';
        $out .= '<span class="ilmw-pill ilmw-pill--gold">' . esc_html($it['status_proyek'] ?? 'Aktif') . '</span>';
        $out .= '<span class="ilmw-pill ilmw-pill--sky">' . esc_html($it['jenis_proyek'] ?? 'Kelompok') . '</span>';
        $out .= '</div>';
        $out .= '<h4 class="ilmw-title">' . esc_html($it['nama_proyek']) . '</h4>';
        if (!empty($it['tema_proyek'])) {
            $out .= '<div class="ilmw-tema-line">Tema P5 / PjBL: ' . esc_html($it['tema_proyek']) . '</div>';
        }
        if (!empty($it['deskripsi_ringkas'])) {
            $out .= '<p class="ilmw-desc">' . esc_html($it['deskripsi_ringkas']) . '</p>';
        }
        if (!empty($it['dimensi_profil_pancasila']) && is_array($it['dimensi_profil_pancasila'])) {
            $out .= '<div class="ilmw-meta-row" style="margin-top:2px;">';
            foreach ($it['dimensi_profil_pancasila'] as $dim) {
                $out .= '<span class="ilmw-pill">' . esc_html($dim) . '</span>';
            }
            $out .= '</div>';
        }
        $out .= '<div class="ilmw-foot-meta"><span>Pembimbing: <strong>' . esc_html($it['guru_pembimbing'] ?? '-') . '</strong></span>';
        if (!empty($it['target_luaran'])) {
            $out .= '<span>Target Luaran: <strong>' . esc_html($it['target_luaran']) . '</strong></span>';
        }
        $out .= '</div></div>';

        $out .= '<div class="ilmw-row-side">';
        $out .= ilmuna_conn_urgensi_badge($urg, $it['sisa_waktu_label'] ?? '-');
        if (!empty($it['jadwal_selesai_formatted'])) {
            $out .= '<div class="ilmw-deadline-box">' . $calIcon . '<span>Deadline: ' . esc_html($it['jadwal_selesai_formatted']) . '</span></div>';
        }
        if (!empty($it['portal_url'])) {
            $out .= '<a class="ilmw-btn ilmw-btn--primary" href="' . esc_url($it['portal_url']) . '" target="_blank" rel="noopener noreferrer"><span>Buka Portal Proyek</span>' . $extIcon . '</a>';
        }
        $out .= '</div></div>';
    }
    $out .= '</div>';
    return $out;
}

/* ==============================================================================
 * SHORTCODES
 * ============================================================================== */
add_shortcode('ilmuna_proyek_showcase', function ($atts) {
    $a = shortcode_atts([
        'limit'          => 6,
        'columns'        => 3,
        'kelas_id'       => '',
        'mapel_id'       => '',
        'only_published' => '',
        'show_header'    => '1',
        'eyebrow'        => 'Etalase Karya Siswa',
        'title'          => 'Hasil Karya Proyek & P5',
        'subtitle'       => 'Karya pilihan peserta didik dari pembelajaran berbasis proyek Ilmuna Learn.',
    ], $atts);

    $res = ilmuna_conn_request('proyek_showcase', [
        'limit'          => (int)$a['limit'],
        'kelas_id'       => $a['kelas_id'],
        'mapel_id'       => $a['mapel_id'],
        'only_published' => $a['only_published'],
    ]);

    $html = ilmuna_conn_enqueue_styles_once() . '<section class="ilmw-wrap"><div class="ilmw-shell">';
    if ((string)$a['show_header'] === '1') {
        $html .= ilmuna_conn_render_section_header($a['eyebrow'], $a['title'], $a['subtitle']);
    }
    if (is_wp_error($res)) {
        return $html . '<div class="ilmw-err">Gagal memuat Showcase Proyek: ' . esc_html($res->get_error_message()) . '</div></div></section>';
    }
    $html .= ilmuna_conn_render_showcase_html($res['data']['showcase'] ?? [], (int)$a['columns']);
    return $html . '</div></section>';
});

add_shortcode('ilmuna_broadcast_tugas', function ($atts) {
    $a = shortcode_atts([
        'limit'         => 10,
        'upcoming_only' => '0',
        'kelas_id'      => '',
        'mapel_id'      => '',
        'show_header'   => '1',
        'eyebrow'       => 'Broadcast Akademik',
        'title'         => 'Daftar Tugas & Tenggat Waktu',
        'subtitle'      => 'Pantau jadwal penugasan terbaru lengkap dengan hitungan mundur deadline dari Ilmuna Learn.',
    ], $atts);

    $res = ilmuna_conn_request('tugas_broadcast', [
        'limit'         => (int)$a['limit'],
        'upcoming_only' => $a['upcoming_only'],
        'kelas_id'      => $a['kelas_id'],
        'mapel_id'      => $a['mapel_id'],
    ]);

    $html = ilmuna_conn_enqueue_styles_once() . '<section class="ilmw-wrap"><div class="ilmw-shell">';
    if ((string)$a['show_header'] === '1') {
        $html .= ilmuna_conn_render_section_header($a['eyebrow'], $a['title'], $a['subtitle']);
    }
    if (is_wp_error($res)) {
        return $html . '<div class="ilmw-err">Gagal memuat Broadcast Tugas: ' . esc_html($res->get_error_message()) . '</div></div></section>';
    }
    $html .= ilmuna_conn_render_tugas_html($res['data']['tugas'] ?? []);
    return $html . '</div></section>';
});

add_shortcode('ilmuna_broadcast_proyek', function ($atts) {
    $a = shortcode_atts([
        'limit'         => 6,
        'upcoming_only' => '0',
        'kelas_id'      => '',
        'mapel_id'      => '',
        'show_header'   => '1',
        'eyebrow'       => 'Broadcast PjBL & P5',
        'title'         => 'Agenda Proyek & Tenggat Pengumpulan',
        'subtitle'      => 'Informasi proyek kolaboratif siswa, target luaran, serta jadwal penyelesaian terkini.',
    ], $atts);

    $res = ilmuna_conn_request('proyek_broadcast', [
        'limit'         => (int)$a['limit'],
        'upcoming_only' => $a['upcoming_only'],
        'kelas_id'      => $a['kelas_id'],
        'mapel_id'      => $a['mapel_id'],
    ]);

    $html = ilmuna_conn_enqueue_styles_once() . '<section class="ilmw-wrap"><div class="ilmw-shell">';
    if ((string)$a['show_header'] === '1') {
        $html .= ilmuna_conn_render_section_header($a['eyebrow'], $a['title'], $a['subtitle']);
    }
    if (is_wp_error($res)) {
        return $html . '<div class="ilmw-err">Gagal memuat Broadcast Proyek: ' . esc_html($res->get_error_message()) . '</div></div></section>';
    }
    $html .= ilmuna_conn_render_proyek_html($res['data']['proyek'] ?? []);
    return $html . '</div></section>';
});

add_shortcode('ilmuna_broadcast_all', function ($atts) {
    $a = shortcode_atts([
        'limit_showcase' => 6,
        'limit_tugas'    => 8,
        'limit_proyek'   => 6,
        'columns'        => 3,
        'show_header'    => '1',
        'eyebrow'        => 'Portal Akademik Ilmuna Learn',
        'title'          => 'Karya Proyek & Broadcast Deadline',
        'subtitle'       => 'Etalase karya siswa, agenda tugas, dan proyek P5 terkini yang tersinkron langsung dari LMS.',
    ], $atts);

    $res = ilmuna_conn_request('all', [
        'limit_showcase' => (int)$a['limit_showcase'],
        'limit_tugas'    => (int)$a['limit_tugas'],
        'limit_proyek'   => (int)$a['limit_proyek'],
    ]);

    $html = ilmuna_conn_enqueue_styles_once() . '<section class="ilmw-wrap"><div class="ilmw-shell">';
    if ((string)$a['show_header'] === '1') {
        $html .= ilmuna_conn_render_section_header($a['eyebrow'], $a['title'], $a['subtitle']);
    }
    if (is_wp_error($res)) {
        return $html . '<div class="ilmw-err">Gagal terhubung ke API Ilmuna Learn: ' . esc_html($res->get_error_message()) . '</div></div></section>';
    }

    $showcase = $res['data']['proyek_showcase'] ?? [];
    $tugas    = $res['data']['tugas_broadcast'] ?? [];
    $proyek   = $res['data']['proyek_broadcast'] ?? [];
    $uid      = 'ilmw_' . substr(md5(uniqid('', true)), 0, 8);

    $html .= '<div id="' . esc_attr($uid) . '">';
    $html .= '<div class="ilmw-tabs" role="tablist">';
    $html .= '<button type="button" class="ilmw-tab-btn is-active" data-ilmw-tab="showcase">' . ilmuna_conn_svg_icon('sparkle') . '<span>Hasil Karya Proyek</span><span class="ilmw-tab-cnt">' . count($showcase) . '</span></button>';
    $html .= '<button type="button" class="ilmw-tab-btn" data-ilmw-tab="tugas">' . ilmuna_conn_svg_icon('task') . '<span>Broadcast Tugas &amp; Deadline</span><span class="ilmw-tab-cnt">' . count($tugas) . '</span></button>';
    $html .= '<button type="button" class="ilmw-tab-btn" data-ilmw-tab="proyek">' . ilmuna_conn_svg_icon('rocket') . '<span>Broadcast Proyek &amp; Deadline</span><span class="ilmw-tab-cnt">' . count($proyek) . '</span></button>';
    $html .= '</div>';

    $html .= '<div class="ilmw-pane is-active" data-ilmw-pane="showcase">' . ilmuna_conn_render_showcase_html($showcase, (int)$a['columns']) . '</div>';
    $html .= '<div class="ilmw-pane" data-ilmw-pane="tugas">' . ilmuna_conn_render_tugas_html($tugas) . '</div>';
    $html .= '<div class="ilmw-pane" data-ilmw-pane="proyek">' . ilmuna_conn_render_proyek_html($proyek) . '</div>';
    $html .= '</div>';

    $html .= '<script>(function(){var r=document.getElementById(' . wp_json_encode($uid) . ');if(!r)return;r.querySelectorAll(".ilmw-tab-btn").forEach(function(b){b.addEventListener("click",function(){var t=b.getAttribute("data-ilmw-tab");r.querySelectorAll(".ilmw-tab-btn").forEach(function(x){x.classList.toggle("is-active",x===b);});r.querySelectorAll(".ilmw-pane").forEach(function(p){p.classList.toggle("is-active",p.getAttribute("data-ilmw-pane")===t);});});});})();</script>';
    return $html . '</div></section>';
});
