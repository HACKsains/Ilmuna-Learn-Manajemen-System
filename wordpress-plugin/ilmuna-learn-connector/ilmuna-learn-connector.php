<?php
/**
 * Plugin Name: Ilmuna Learn Connector (Secure API)
 * Plugin URI:  https://learn.ilmuna.my.id
 * Description: Menghubungkan WordPress (ilmuna.my.id) ke Ilmuna Learn (learn.ilmuna.my.id) dengan proteksi kriptografis Public Key + Private Key (HMAC-SHA256 / RSA-2048 / DUAL), Anti-Replay Nonce, dan Verifikasi Signature Respon. Menampilkan Showcase Proyek Siswa, Broadcast Tugas & Deadline, serta Broadcast Proyek & Deadline.
 * Version:     1.0.0
 * Author:      Ilmuna Digital Ecosystem
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ILMUNA_WP_CONN_VERSION', '1.0.0');
define('ILMUNA_WP_CONN_OPTION', 'ilmuna_learn_api_settings');

/**
 * Ambil pengaturan plugin (mendukung override konstan di wp-config.php untuk keamanan maksimal)
 */
function ilmuna_conn_get_settings()
{
    $defaults = [
        'api_url'         => 'https://learn.ilmuna.my.id/api/wp-broadcast.php',
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
        $errMsg = (is_array($decoded) && isset($decoded['error']['message']))
            ? $decoded['error']['message'] . ' [' . ($decoded['error']['code'] ?? $httpCode) . ']'
            : 'Respon HTTP ' . $httpCode . ' dari server Ilmuna Learn.';
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
                'api_url'         => esc_url_raw(trim(wp_unslash($_POST['api_url'] ?? 'https://learn.ilmuna.my.id/api/wp-broadcast.php'))),
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
        <h1>Ilmuna Learn API Connector (Zero-Trust Public &amp; Private Key)</h1>
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
                        <input name="api_url" id="ilmuna_api_url" type="url" class="regular-text code" style="width:460px;"
                               value="<?php echo esc_attr($cfg['api_url']); ?>" required>
                        <p class="description">Default: <code>https://learn.ilmuna.my.id/api/wp-broadcast.php</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ilmuna_public_key">Public Key</label></th>
                    <td>
                        <input name="public_key" id="ilmuna_public_key" type="text" class="regular-text code" style="width:460px;"
                               value="<?php echo esc_attr($cfg['public_key']); ?>" placeholder="pk_ilmuna_..." required>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ilmuna_private_key">Private Key</label></th>
                    <td>
                        <?php if ($privLocked): ?>
                            <input type="text" class="regular-text code" style="width:460px;" value="Dikonfigurasi lewat wp-config.php (ILMUNA_API_PRIVATE_KEY)" disabled>
                        <?php else: ?>
                            <input name="private_key" id="ilmuna_private_key" type="password" class="regular-text code" style="width:460px;"
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
        <table class="widefat striped" style="max-width:920px;">
            <thead>
                <tr><th>Shortcode</th><th>Fungsi Tampilan</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>[ilmuna_broadcast_all limit_showcase="6" limit_tugas="8" limit_proyek="6"]</code></td>
                    <td>Widget Terpadu 3 Tab (Galeri Karya Proyek Siswa, Broadcast Deadline Tugas, Broadcast Deadline Proyek) dalam 1 request cache.</td>
                </tr>
                <tr>
                    <td><code>[ilmuna_proyek_showcase limit="6" columns="3"]</code></td>
                    <td>Galeri Hasil Karya Proyek Siswa (thumbnail dokumentasi, predikat P5, tim/kelompok, link produk &amp; video).</td>
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
 * STYLING LUMINA GOLD TERISOLASI UNTUK FRONTEND WORDPRESS
 * ============================================================================== */
function ilmuna_conn_enqueue_styles_once()
{
    static $printed = false;
    if ($printed) {
        return '';
    }
    $printed = true;
    return '<style id="ilmuna-wp-connector-css">
.ilmw-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1E293B;line-height:1.5;margin:16px 0}
.ilmw-wrap *{box-sizing:border-box}
.ilmw-tabs{display:flex;flex-wrap:wrap;gap:8px;padding:6px;background:#F1F5F9;border-radius:14px;margin-bottom:18px;border:1px solid #E2E8F0}
.ilmw-tab-btn{appearance:none;border:0;background:transparent;padding:10px 16px;border-radius:10px;font-weight:700;font-size:13.5px;color:#475569;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all .2s}
.ilmw-tab-btn:hover{color:#1D3B5E;background:rgba(255,255,255,.7)}
.ilmw-tab-btn.is-active{background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#fff;box-shadow:0 4px 12px rgba(13,122,135,.22)}
.ilmw-tab-cnt{background:rgba(15,23,42,.12);padding:2px 8px;border-radius:999px;font-size:11.5px;font-weight:800}
.ilmw-tab-btn.is-active .ilmw-tab-cnt{background:rgba(255,255,255,.22);color:#fff}
.ilmw-pane{display:none}.ilmw-pane.is-active{display:block}
.ilmw-grid{display:grid;grid-template-columns:repeat(var(--ilmw-cols,3),minmax(0,1fr));gap:18px}
@media(max-width:960px){.ilmw-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.ilmw-grid{grid-template-columns:1fr}}
.ilmw-card{background:#fff;border:1px solid #E2E8F0;border-radius:16px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 4px 18px rgba(15,23,42,.05);transition:transform .2s,box-shadow .2s}
.ilmw-card:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(29,59,94,.11)}
.ilmw-thumb{position:relative;height:185px;background:linear-gradient(135deg,#1D3B5E 0%,#0D7A87 65%,#52B2DC 100%);overflow:hidden;display:flex;align-items:center;justify-content:center;color:#fff}
.ilmw-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.ilmw-thumb-ph{padding:16px;text-align:center;font-weight:800;font-size:14px;opacity:.92}
.ilmw-badge-float{position:absolute;top:12px;left:12px;background:rgba(15,23,42,.78);backdrop-filter:blur(6px);color:#fff;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700}
.ilmw-score-float{position:absolute;top:12px;right:12px;background:#F59E0B;color:#fff;padding:4px 10px;border-radius:999px;font-size:11.5px;font-weight:800;box-shadow:0 2px 8px rgba(245,158,11,.35)}
.ilmw-body{padding:16px;display:flex;flex-direction:column;gap:8px;flex:1}
.ilmw-meta-row{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.ilmw-pill{display:inline-flex;align-items:center;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;background:#F1F5F9;color:#334155}
.ilmw-pill--teal{background:#CCFBF1;color:#0F766E}
.ilmw-pill--navy{background:#E0E7FF;color:#1D3B5E}
.ilmw-pill--gold{background:#FEF3C7;color:#B45309}
.ilmw-pill--danger{background:#FEE2E2;color:#B91C1C}
.ilmw-pill--muted{background:#E2E8F0;color:#475569}
.ilmw-title{margin:0;font-size:16px;font-weight:800;color:#0F172A;line-height:1.35}
.ilmw-desc{margin:0;font-size:13px;color:#475569;line-height:1.55}
.ilmw-creator{font-size:12.5px;color:#334155;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:8px 10px}
.ilmw-actions{margin-top:auto;padding-top:10px;border-top:1px solid #F1F5F9;display:flex;flex-wrap:wrap;gap:8px}
.ilmw-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:7px 13px;border-radius:9px;font-size:12.5px;font-weight:700;text-decoration:none !important;transition:all .18s}
.ilmw-btn--primary{background:linear-gradient(135deg,#1D3B5E,#0D7A87);color:#fff !important}
.ilmw-btn--primary:hover{opacity:.92}
.ilmw-btn--ghost{background:#F8FAFC;color:#1D3B5E !important;border:1px solid #CBD5E1}
.ilmw-btn--ghost:hover{background:#F1F5F9}
.ilmw-list{display:flex;flex-direction:column;gap:12px}
.ilmw-row{background:#fff;border:1px solid #E2E8F0;border-left:4px solid #0D7A87;border-radius:14px;padding:15px 16px;display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;box-shadow:0 3px 12px rgba(15,23,42,.04)}
.ilmw-row--kritis{border-left-color:#DC2626;background:linear-gradient(90deg,#FEF2F2 0%,#fff 18%)}
.ilmw-row--segera{border-left-color:#F59E0B;background:linear-gradient(90deg,#FFFBEB 0%,#fff 18%)}
.ilmw-row--berakhir{border-left-color:#94A3B8;opacity:.85}
.ilmw-row-main{flex:1;min-width:240px}
.ilmw-row-side{display:flex;flex-direction:column;align-items:flex-end;gap:6px;min-width:170px}
@media(max-width:640px){.ilmw-row-side{align-items:flex-start;width:100%}}
.ilmw-empty{padding:28px;text-align:center;background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:14px;color:#64748B;font-size:13.5px}
.ilmw-err{padding:14px 16px;background:#FEF2F2;border:1px solid #FECACA;border-radius:12px;color:#991B1B;font-size:13px}
</style>';
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
    return '<span class="ilmw-pill ' . esc_attr($cls) . '">' . esc_html($label) . '</span>';
}

function ilmuna_conn_render_showcase_html(array $items, $columns = 3)
{
    if (empty($items)) {
        return '<div class="ilmw-empty">Belum ada hasil karya proyek siswa yang dipublikasikan.</div>';
    }
    $cols = max(1, min(4, (int)$columns));
    $out = '<div class="ilmw-grid" style="--ilmw-cols:' . $cols . ';">';
    foreach ($items as $it) {
        $thumb = !empty($it['thumbnail_url'])
            ? '<img src="' . esc_url($it['thumbnail_url']) . '" alt="' . esc_attr($it['judul_karya']) . '" loading="lazy">'
            : '<div class="ilmw-thumb-ph">' . esc_html($it['proyek']['tema_proyek'] ?: $it['mata_pelajaran']['nama']) . '</div>';

        $badgeFloat = esc_html(($it['kelas']['nama'] ?? '') . ' · ' . ($it['mata_pelajaran']['nama'] ?? ''));
        $scoreHtml  = ($it['nilai_proyek'] !== null) ? '<span class="ilmw-score-float">Nilai ' . esc_html(number_format((float)$it['nilai_proyek'], 0)) . '</span>' : '';

        $creatorLabel = esc_html($it['kreator']['nama_utama'] ?? 'Siswa');
        $anggotaNames = [];
        if (!empty($it['kreator']['tim']['anggota']) && is_array($it['kreator']['tim']['anggota'])) {
            foreach ($it['kreator']['tim']['anggota'] as $ag) {
                $anggotaNames[] = $ag['nama'] . (($ag['peran'] ?? '') === 'Ketua' ? ' (Ketua)' : '');
            }
        }

        $out .= '<article class="ilmw-card">';
        $out .= '<div class="ilmw-thumb">' . $thumb . '<span class="ilmw-badge-float">' . $badgeFloat . '</span>' . $scoreHtml . '</div>';
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
        $out .= '<div class="ilmw-creator"><strong>' . esc_html($it['kreator']['tipe'] === 'Kelompok' ? 'Tim: ' : 'Karya: ') . '</strong>' . $creatorLabel;
        if (!empty($anggotaNames)) {
            $out .= '<div style="font-size:11.5px;color:#64748B;margin-top:2px;">Anggota: ' . esc_html(implode(', ', $anggotaNames)) . '</div>';
        }
        $out .= '<div style="font-size:11.5px;color:#64748B;margin-top:2px;">Pembimbing: ' . esc_html($it['guru_pembimbing'] ?? '-') . '</div>';
        $out .= '</div>';

        $actions = '';
        if (!empty($it['link_produk'])) {
            $actions .= '<a class="ilmw-btn ilmw-btn--primary" href="' . esc_url($it['link_produk']) . '" target="_blank" rel="noopener noreferrer">Lihat Produk</a>';
        }
        if (!empty($it['link_video_presentasi'])) {
            $actions .= '<a class="ilmw-btn ilmw-btn--ghost" href="' . esc_url($it['link_video_presentasi']) . '" target="_blank" rel="noopener noreferrer">Video Presentasi</a>';
        }
        if (!empty($it['file_laporan_url'])) {
            $actions .= '<a class="ilmw-btn ilmw-btn--ghost" href="' . esc_url($it['file_laporan_url']) . '" target="_blank" rel="noopener noreferrer">Unduh Laporan</a>';
        } elseif (!empty($it['file_dokumentasi_url']) && $actions === '') {
            $actions .= '<a class="ilmw-btn ilmw-btn--ghost" href="' . esc_url($it['file_dokumentasi_url']) . '" target="_blank" rel="noopener noreferrer">Lihat Dokumentasi</a>';
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
    $out = '<div class="ilmw-list">';
    foreach ($items as $it) {
        $urg = (string)($it['urgensi'] ?? 'normal');
        $out .= '<div class="ilmw-row ilmw-row--' . esc_attr($urg) . '">';
        $out .= '<div class="ilmw-row-main">';
        $out .= '<div class="ilmw-meta-row" style="margin-bottom:6px;">';
        $out .= '<span class="ilmw-pill ilmw-pill--navy">' . esc_html($it['kelas']['nama'] ?? '-') . '</span>';
        $out .= '<span class="ilmw-pill ilmw-pill--teal">' . esc_html($it['mata_pelajaran']['nama'] ?? '-') . '</span>';
        $out .= '<span class="ilmw-pill">' . esc_html($it['jenis_penugasan'] ?? 'Individu') . '</span>';
        if (!empty($it['modul_ajar']['pertemuan_ke'])) {
            $out .= '<span class="ilmw-pill">Pertemuan ke-' . (int)$it['modul_ajar']['pertemuan_ke'] . '</span>';
        }
        $out .= '</div>';
        $out .= '<h4 class="ilmw-title" style="margin-bottom:4px;">' . esc_html($it['judul_tugas']) . '</h4>';
        if (!empty($it['deskripsi_ringkas'])) {
            $out .= '<p class="ilmw-desc">' . esc_html($it['deskripsi_ringkas']) . '</p>';
        }
        $out .= '<div style="font-size:12px;color:#64748B;margin-top:6px;">Guru: <strong>' . esc_html($it['guru_pengampu'] ?? '-') . '</strong></div>';
        $out .= '</div>';

        $out .= '<div class="ilmw-row-side">';
        $out .= ilmuna_conn_urgensi_badge($urg, $it['sisa_waktu_label'] ?? '-');
        if (!empty($it['tenggat_waktu_formatted'])) {
            $out .= '<div style="font-size:12px;font-weight:700;color:#334155;">Deadline: ' . esc_html($it['tenggat_waktu_formatted']) . '</div>';
        }
        if (!empty($it['portal_url'])) {
            $out .= '<a class="ilmw-btn ilmw-btn--primary" href="' . esc_url($it['portal_url']) . '" target="_blank" rel="noopener noreferrer">Kerjakan di LMS</a>';
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
    $out = '<div class="ilmw-list">';
    foreach ($items as $it) {
        $urg = (string)($it['urgensi'] ?? 'normal');
        $out .= '<div class="ilmw-row ilmw-row--' . esc_attr($urg) . '">';
        $out .= '<div class="ilmw-row-main">';
        $out .= '<div class="ilmw-meta-row" style="margin-bottom:6px;">';
        $out .= '<span class="ilmw-pill ilmw-pill--navy">' . esc_html($it['kelas']['nama'] ?? '-') . '</span>';
        $out .= '<span class="ilmw-pill ilmw-pill--teal">' . esc_html($it['mata_pelajaran']['nama'] ?? '-') . '</span>';
        $out .= '<span class="ilmw-pill ilmw-pill--gold">' . esc_html($it['status_proyek'] ?? 'Aktif') . '</span>';
        $out .= '<span class="ilmw-pill">' . esc_html($it['jenis_proyek'] ?? 'Kelompok') . '</span>';
        $out .= '</div>';
        $out .= '<h4 class="ilmw-title" style="margin-bottom:4px;">' . esc_html($it['nama_proyek']) . '</h4>';
        if (!empty($it['tema_proyek'])) {
            $out .= '<div style="font-size:12.5px;font-weight:700;color:#0D7A87;margin-bottom:4px;">Tema: ' . esc_html($it['tema_proyek']) . '</div>';
        }
        if (!empty($it['deskripsi_ringkas'])) {
            $out .= '<p class="ilmw-desc">' . esc_html($it['deskripsi_ringkas']) . '</p>';
        }
        $metaLine = 'Pembimbing: <strong>' . esc_html($it['guru_pembimbing'] ?? '-') . '</strong>';
        if (!empty($it['target_luaran'])) {
            $metaLine .= ' · Target: ' . esc_html($it['target_luaran']);
        }
        $out .= '<div style="font-size:12px;color:#64748B;margin-top:6px;">' . $metaLine . '</div>';
        $out .= '</div>';

        $out .= '<div class="ilmw-row-side">';
        $out .= ilmuna_conn_urgensi_badge($urg, $it['sisa_waktu_label'] ?? '-');
        if (!empty($it['jadwal_selesai_formatted'])) {
            $out .= '<div style="font-size:12px;font-weight:700;color:#334155;">Deadline: ' . esc_html($it['jadwal_selesai_formatted']) . '</div>';
        }
        if (!empty($it['portal_url'])) {
            $out .= '<a class="ilmw-btn ilmw-btn--primary" href="' . esc_url($it['portal_url']) . '" target="_blank" rel="noopener noreferrer">Buka Portal Proyek</a>';
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
    ], $atts);

    $res = ilmuna_conn_request('proyek_showcase', [
        'limit'          => (int)$a['limit'],
        'kelas_id'       => $a['kelas_id'],
        'mapel_id'       => $a['mapel_id'],
        'only_published' => $a['only_published'],
    ]);

    $html = ilmuna_conn_enqueue_styles_once() . '<div class="ilmw-wrap">';
    if (is_wp_error($res)) {
        return $html . '<div class="ilmw-err">Gagal memuat Showcase Proyek: ' . esc_html($res->get_error_message()) . '</div></div>';
    }
    $html .= ilmuna_conn_render_showcase_html($res['data']['showcase'] ?? [], (int)$a['columns']);
    return $html . '</div>';
});

add_shortcode('ilmuna_broadcast_tugas', function ($atts) {
    $a = shortcode_atts([
        'limit'         => 10,
        'upcoming_only' => '0',
        'kelas_id'      => '',
        'mapel_id'      => '',
    ], $atts);

    $res = ilmuna_conn_request('tugas_broadcast', [
        'limit'         => (int)$a['limit'],
        'upcoming_only' => $a['upcoming_only'],
        'kelas_id'      => $a['kelas_id'],
        'mapel_id'      => $a['mapel_id'],
    ]);

    $html = ilmuna_conn_enqueue_styles_once() . '<div class="ilmw-wrap">';
    if (is_wp_error($res)) {
        return $html . '<div class="ilmw-err">Gagal memuat Broadcast Tugas: ' . esc_html($res->get_error_message()) . '</div></div>';
    }
    $html .= ilmuna_conn_render_tugas_html($res['data']['tugas'] ?? []);
    return $html . '</div>';
});

add_shortcode('ilmuna_broadcast_proyek', function ($atts) {
    $a = shortcode_atts([
        'limit'         => 6,
        'upcoming_only' => '0',
        'kelas_id'      => '',
        'mapel_id'      => '',
    ], $atts);

    $res = ilmuna_conn_request('proyek_broadcast', [
        'limit'         => (int)$a['limit'],
        'upcoming_only' => $a['upcoming_only'],
        'kelas_id'      => $a['kelas_id'],
        'mapel_id'      => $a['mapel_id'],
    ]);

    $html = ilmuna_conn_enqueue_styles_once() . '<div class="ilmw-wrap">';
    if (is_wp_error($res)) {
        return $html . '<div class="ilmw-err">Gagal memuat Broadcast Proyek: ' . esc_html($res->get_error_message()) . '</div></div>';
    }
    $html .= ilmuna_conn_render_proyek_html($res['data']['proyek'] ?? []);
    return $html . '</div>';
});

add_shortcode('ilmuna_broadcast_all', function ($atts) {
    $a = shortcode_atts([
        'limit_showcase' => 6,
        'limit_tugas'    => 8,
        'limit_proyek'   => 6,
        'columns'        => 3,
    ], $atts);

    $res = ilmuna_conn_request('all', [
        'limit_showcase' => (int)$a['limit_showcase'],
        'limit_tugas'    => (int)$a['limit_tugas'],
        'limit_proyek'   => (int)$a['limit_proyek'],
    ]);

    $html = ilmuna_conn_enqueue_styles_once() . '<div class="ilmw-wrap">';
    if (is_wp_error($res)) {
        return $html . '<div class="ilmw-err">Gagal terhubung ke API Ilmuna Learn: ' . esc_html($res->get_error_message()) . '</div></div>';
    }

    $showcase = $res['data']['proyek_showcase'] ?? [];
    $tugas    = $res['data']['tugas_broadcast'] ?? [];
    $proyek   = $res['data']['proyek_broadcast'] ?? [];
    $uid      = 'ilmw_' . substr(md5(uniqid('', true)), 0, 8);

    $html .= '<div id="' . esc_attr($uid) . '">';
    $html .= '<div class="ilmw-tabs" role="tablist">';
    $html .= '<button type="button" class="ilmw-tab-btn is-active" data-ilmw-tab="showcase">Hasil Karya Proyek <span class="ilmw-tab-cnt">' . count($showcase) . '</span></button>';
    $html .= '<button type="button" class="ilmw-tab-btn" data-ilmw-tab="tugas">Broadcast Tugas &amp; Deadline <span class="ilmw-tab-cnt">' . count($tugas) . '</span></button>';
    $html .= '<button type="button" class="ilmw-tab-btn" data-ilmw-tab="proyek">Broadcast Proyek &amp; Deadline <span class="ilmw-tab-cnt">' . count($proyek) . '</span></button>';
    $html .= '</div>';

    $html .= '<div class="ilmw-pane is-active" data-ilmw-pane="showcase">' . ilmuna_conn_render_showcase_html($showcase, (int)$a['columns']) . '</div>';
    $html .= '<div class="ilmw-pane" data-ilmw-pane="tugas">' . ilmuna_conn_render_tugas_html($tugas) . '</div>';
    $html .= '<div class="ilmw-pane" data-ilmw-pane="proyek">' . ilmuna_conn_render_proyek_html($proyek) . '</div>';
    $html .= '</div>';

    $html .= '<script>(function(){var r=document.getElementById(' . wp_json_encode($uid) . ');if(!r)return;r.querySelectorAll(".ilmw-tab-btn").forEach(function(b){b.addEventListener("click",function(){var t=b.getAttribute("data-ilmw-tab");r.querySelectorAll(".ilmw-tab-btn").forEach(function(x){x.classList.toggle("is-active",x===b);});r.querySelectorAll(".ilmw-pane").forEach(function(p){p.classList.toggle("is-active",p.getAttribute("data-ilmw-pane")===t);});});});})();</script>';
    return $html . '</div>';
});
