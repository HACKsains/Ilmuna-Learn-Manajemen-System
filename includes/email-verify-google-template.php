<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — TEMPLATE EMAIL VERIFIKASI PENAUTAN AKUN GOOGLE (GMAIL & SMTP)
 * ==============================================================================
 * Lokasi: includes/email-verify-google-template.php
 * Standar Desain:
 * - Brand Theme Resmi Ilmuna: Navy (#1D3B5E), Teal (#0D7A87), Sky Blue (#52B2DC)
 * - Kompatibel dengan semua email client (Gmail, Apple Mail, Outlook, Webmail)
 * - Menggunakan Socket SMTP mandiri via dashboard/admin/users-email-template.php
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/dashboard/admin/users-email-template.php';

/**
 * Helper HTTP request ke Google OAuth API (mendukung cURL & stream fallback)
 */
if (!function_exists('fetchGoogleApi')) {
    function fetchGoogleApi($url, $postFields = null) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            if (is_array($postFields)) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            }
            $body = curl_exec($ch);
            curl_close($ch);
            if (is_string($body) && $body !== '') {
                return json_decode($body, true);
            }
        }
        $opts = [
            'http' => [
                'method'        => is_array($postFields) ? 'POST' : 'GET',
                'timeout'       => 8,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ];
        if (is_array($postFields)) {
            $opts['http']['header']  = "Content-Type: application/x-www-form-urlencoded\r\n";
            $opts['http']['content'] = http_build_query($postFields);
        }
        $resp = @file_get_contents($url, false, stream_context_create($opts));
        return $resp ? json_decode($resp, true) : null;
    }
}

/**
 * Pastikan tabel google_link_verifications tersedia di database
 *
 * @param PDO $pdo
 * @return void
 */
function ilmuna_ensure_google_verification_table($pdo) {
    static $ensured = false;
    if ($ensured || !$pdo) {
        return;
    }
    try {
        $sql = "CREATE TABLE IF NOT EXISTS `google_link_verifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `email_google` VARCHAR(150) NOT NULL,
            `google_id` VARCHAR(100) NULL,
            `token` VARCHAR(64) NOT NULL UNIQUE,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL,
            `verified_at` DATETIME NULL,
            `ip_address` VARCHAR(45) NULL,
            INDEX `idx_token` (`token`),
            INDEX `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($sql);
        $ensured = true;
    } catch (Exception $e) {
        // Abaikan jika sudah ada atau error non-fatal
    }
}

/**
 * Render template HTML email verifikasi penautan Google SSO
 *
 * @param array $data [
 *    'nama'            => string,
 *    'email_google'    => string,
 *    'verify_url'      => string,
 *    'expires_minutes' => int (optional, default 60),
 *    'logo_cid'        => string (optional, default 'ilmuna_logo')
 * ]
 * @return array ['subject' => string, 'body' => string, 'attachments' => array]
 */
function ilmuna_get_google_verify_email_template($data) {
    // 3 Warna Utama Brand Ilmuna
    $c_navy  = '#1D3B5E'; // Biru Tua
    $c_teal  = '#0D7A87'; // Teal / Hijau Toska
    $c_sky   = '#52B2DC'; // Biru Muda (Sky Blue)

    $nama           = htmlspecialchars($data['nama'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8');
    $emailGoogle    = htmlspecialchars($data['email_google'] ?? '', ENT_QUOTES, 'UTF-8');
    $verifyUrl      = htmlspecialchars($data['verify_url'] ?? '#', ENT_QUOTES, 'UTF-8');
    $expiresMinutes = (int)($data['expires_minutes'] ?? 60);

    $siteName  = defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn';
    $siteTag   = defined('APP_TAGLINE') ? APP_TAGLINE : 'Media Pembelajaran Digital Pendidikan Agama Islam';
    $baseUrl   = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://learn.ilmuna.my.id';
    $tahun     = date('Y');

    // Penanganan Logo Resmi (assets/images/logo.png)
    $logoFile    = BASE_PATH . '/assets/images/logo.png';
    $attachments = [];
    $logoSrc     = $baseUrl . '/assets/images/logo.png';

    if (file_exists($logoFile)) {
        $cidName = !empty($data['logo_cid']) ? $data['logo_cid'] : 'ilmuna_logo';
        $attachments[] = [
            'path' => $logoFile,
            'name' => 'logo.png',
            'mime' => 'image/png',
            'cid'  => $cidName
        ];
        $logoSrc = 'cid:' . $cidName;
    }

    $subject = "Verifikasi Penautan Akun Google — {$siteName}";

    $body = <<<HTML
<!DOCTYPE html>
<html lang="id" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>{$subject}</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        html, body {
            margin: 0 auto !important;
            padding: 0 !important;
            height: 100% !important;
            width: 100% !important;
            background-color: #F1F5F9;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            color: #1D3B5E;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        table, td {
            mso-table-lspace: 0pt !important;
            mso-table-rspace: 0pt !important;
        }
        table {
            border-spacing: 0 !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
            margin: 0 auto !important;
        }
        img {
            -ms-interpolation-mode: bicubic;
        }
        a {
            text-decoration: none;
        }
        @media only screen and (max-width: 600px) {
            .ilmuna-email-container {
                width: 100% !important;
                max-width: 100% !important;
                border-radius: 0 !important;
            }
            .ilmuna-hdr-pad {
                padding: 24px 20px 20px !important;
            }
            .ilmuna-bdy-pad {
                padding: 24px 20px 18px !important;
            }
            .ilmuna-ftr-pad {
                padding: 18px 20px 20px !important;
            }
            .ilmuna-btn-cta {
                display: block !important;
                width: 100% !important;
                text-align: center !important;
                box-sizing: border-box !important;
                padding: 14px 18px !important;
            }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #F1F5F9;">

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F1F5F9; padding: 28px 0 36px;">
        <tr>
            <td align="center" style="padding: 0 12px;">

                <!-- CARD CONTAINER -->
                <table role="presentation" class="ilmuna-email-container" cellspacing="0" cellpadding="0" border="0" width="600" style="max-width: 600px; width: 100%; background-color: #FFFFFF; border-radius: 16px; overflow: hidden; box-shadow: 0 8px 30px rgba(29, 59, 94, 0.08); border: 1px solid #E2E8F0;">
                    
                    <!-- ============================================================== -->
                    <!-- HEADER SECTION (NAVY & TEAL GRADIENT)                          -->
                    <!-- ============================================================== -->
                    <tr>
                        <td class="ilmuna-hdr-pad" style="background: linear-gradient(135deg, {$c_navy} 0%, #152C47 50%, {$c_teal} 100%); padding: 32px 30px 26px; text-align: center;">
                            
                            <!-- Logo Resmi Ilmuna Badge -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto 14px;">
                                <tr>
                                    <td align="center" style="width: 74px; height: 74px; background-color: #FFFFFF; border-radius: 18px; box-shadow: 0 6px 20px rgba(0,0,0,0.18); border: 2px solid {$c_sky}; text-align: center; vertical-align: middle;">
                                        <img src="{$logoSrc}" alt="{$siteName}" width="52" height="52" style="width:52px;height:52px;max-width:52px;display:block;margin:0 auto;object-fit:contain;" />
                                    </td>
                                </tr>
                            </table>

                            <!-- Header Typography -->
                            <h1 style="margin: 0 0 6px; color: #FFFFFF; font-size: 21px; font-weight: 800; letter-spacing: -0.2px; line-height: 1.3;">
                                Verifikasi Penautan Akun Google
                            </h1>
                            <p style="margin: 0; color: {$c_sky}; font-size: 13px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase;">
                                {$siteName} &bull; Single Sign-On (SSO)
                            </p>
                            <p style="margin: 4px 0 0; color: #E2E8F0; font-size: 11.5px; font-weight: 400;">
                                {$siteTag}
                            </p>
                        </td>
                    </tr>

                    <!-- ============================================================== -->
                    <!-- BODY SECTION                                                   -->
                    <!-- ============================================================== -->
                    <tr>
                        <td class="ilmuna-bdy-pad" style="padding: 30px 32px 20px;">
                            
                            <p style="margin: 0 0 12px; font-size: 14.5px; color: {$c_navy}; line-height: 1.6;">
                                <strong>Assalamu'alaikum Warahmatullahi Wabarakatuh,</strong><br>
                                Yth. <strong style="color: {$c_teal};">{$nama}</strong>
                            </p>

                            <p style="margin: 0 0 18px; font-size: 13.5px; color: #475569; line-height: 1.7;">
                                Kami menerima permintaan untuk menautkan akun Google Anda ke profil portal pembelajaran digital <strong>{$siteName}</strong>. 
                                Agar penautan akun sah dan resmi tersimpan pada sistem database kami, mohon lakukan konfirmasi kepemilikan akun Google ini.
                            </p>

                            <!-- BOX DETAIL EMAIL GOOGLE -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F8FAFC; border: 1.5px solid #CBD5E1; border-left: 4.5px solid {$c_teal}; border-radius: 12px; margin-bottom: 22px;">
                                <tr>
                                    <td style="padding: 16px 20px;">
                                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: {$c_teal}; margin-bottom: 5px;">
                                            🔗 Alamat Akun Google yang Ditautkan
                                        </div>
                                        <div style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 17px; font-weight: 800; color: {$c_navy}; word-break: break-all;">
                                            {$emailGoogle}
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0 0 22px; font-size: 13.5px; color: #475569; line-height: 1.7;">
                                Silakan klik tombol verifikasi di bawah ini untuk mengonfirmasi dan mengaktifkan penautan akun Google Anda:
                            </p>

                            <!-- ============================================================== -->
                            <!-- TOMBOL VERIFIKASI (CTA)                                        -->
                            <!-- ============================================================== -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto 24px;">
                                <tr>
                                    <td align="center" style="border-radius: 10px; background: linear-gradient(135deg, {$c_teal} 0%, {$c_navy} 100%); box-shadow: 0 6px 18px rgba(13, 122, 135, 0.35);">
                                        <a href="{$verifyUrl}" target="_blank" class="ilmuna-btn-cta" style="display: inline-block; padding: 14px 32px; font-size: 15px; font-weight: 800; color: #FFFFFF; text-decoration: none; border-radius: 10px; letter-spacing: 0.3px;">
                                            ✅ Konfirmasi & Tautkan Akun Google
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Informasi Batas Waktu & Keamanan -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F0F9FF; border-left: 4px solid {$c_sky}; border-radius: 8px; margin-bottom: 20px;">
                                <tr>
                                    <td style="padding: 14px 16px; font-size: 12.5px; color: {$c_navy}; line-height: 1.6;">
                                        <strong>⏱️ Batas Waktu Tautan:</strong><br>
                                        &bull; Tautan verifikasi ini hanya berlaku selama <strong>{$expiresMinutes} menit</strong>.<br>
                                        &bull; Setelah diklik dan berhasil diverifikasi, Anda dapat langsung masuk ke portal menggunakan tombol <em>Masuk dengan Google</em>.<br>
                                        &bull; Jika Anda tidak pernah meminta penautan akun ini, abaikan email ini. Akun Google Anda tidak akan ditautkan dan profil Anda tetap aman.
                                    </td>
                                </tr>
                            </table>

                            <!-- Fallback Link Manual -->
                            <p style="margin: 0 0 5px; font-size: 11.5px; color: #64748B; line-height: 1.5;">
                                Jika tombol di atas tidak dapat diklik, salin dan tempel URL verifikasi berikut ke peramban (browser) Anda:
                            </p>
                            <p style="margin: 0 0 10px; font-size: 12px; color: {$c_teal}; word-break: break-all;">
                                <a href="{$verifyUrl}" target="_blank" style="color: {$c_teal}; text-decoration: underline; font-weight: 600;">{$verifyUrl}</a>
                            </p>

                        </td>
                    </tr>

                    <!-- ============================================================== -->
                    <!-- FOOTER SECTION                                                 -->
                    <!-- ============================================================== -->
                    <tr>
                        <td class="ilmuna-ftr-pad" style="padding: 20px 30px 22px; background-color: #F8FAFC; border-top: 1px solid #E2E8F0; text-align: center;">
                            <p style="margin: 0 0 4px; font-size: 13px; font-weight: 700; color: {$c_navy};">
                                {$siteName}
                            </p>
                            <p style="margin: 0 0 8px; font-size: 11.5px; color: #64748B;">
                                {$siteTag} &bull; Portal Pembelajaran Terpadu
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #94A3B8; line-height: 1.6;">
                                Email verifikasi ini dikirimkan otomatis oleh server sistem.<br>
                                &copy; {$tahun} {$siteName}. Hak Cipta Dilindungi.
                            </p>
                        </td>
                    </tr>

                </table>
                <!-- /CARD CONTAINER -->

            </td>
        </tr>
    </table>

</body>
</html>
HTML;

    return [
        'subject'     => $subject,
        'body'        => $body,
        'attachments' => $attachments
    ];
}

/**
 * Kirim email verifikasi Google SSO ke alamat Gmail target via SMTP
 *
 * @param string $toEmail Alamat Gmail yang ditautkan
 * @param string $userName Nama pengguna
 * @param string $verifyUrl URL lengkap verifikasi token
 * @param int $expiresInMinutes Batas waktu kedaluwarsa link (menit)
 * @return array ['success' => bool, 'message' => string]
 */
function ilmuna_send_google_verify_email($toEmail, $userName, $verifyUrl, $expiresInMinutes = 60) {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Format alamat email tidak valid: ' . $toEmail
        ];
    }

    $cfg = ilmuna_smtp_config();
    if (empty($cfg['is_enabled'])) {
        return [
            'success' => false,
            'message' => 'Pengiriman email dinonaktifkan di pengaturan SMTP server.'
        ];
    }

    $host     = $cfg['host'] ?: 'smtp.gmail.com';
    $port     = (int)($cfg['port'] ?: 587);
    $secure   = strtolower($cfg['secure'] ?: 'tls');
    $user     = $cfg['user'] ?: '';
    $pass     = $cfg['pass'] ?: '';
    $from     = $cfg['from'] ?: $user;
    $fromName = $cfg['from_name'] ?: 'Ilmuna Learn';

    $tplData = [
        'nama'            => $userName,
        'email_google'    => $toEmail,
        'verify_url'      => $verifyUrl,
        'expires_minutes' => $expiresInMinutes
    ];

    $tpl = ilmuna_get_google_verify_email_template($tplData);

    // Kirim via low-level Socket SMTP Engine Ilmuna
    $res = ilmuna_raw_smtp_send([
        'host'        => $host,
        'port'        => $port,
        'secure'      => $secure,
        'user'        => $user,
        'pass'        => $pass,
        'from'        => $from,
        'from_name'   => $fromName,
        'to'          => $toEmail,
        'subject'     => $tpl['subject'],
        'body'        => $tpl['body'],
        'attachments' => $tpl['attachments'] ?? []
    ]);

    // Catat log
    ilmuna_log_email_send([
        'timestamp' => date('Y-m-d H:i:s'),
        'to'        => $toEmail,
        'nama'      => $userName,
        'username'  => 'google_verify',
        'success'   => $res['success'],
        'detail'    => $res['message']
    ]);

    return $res;
}
