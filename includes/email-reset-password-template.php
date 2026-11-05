<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — TEMPLATE EMAIL RESET KATA SANDI (GMAIL & SMTP)
 * ==============================================================================
 * Lokasi: includes/email-reset-password-template.php
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
 * Pastikan tabel password_resets tersedia di database
 *
 * @param PDO $pdo
 * @return void
 */
function ilmuna_ensure_password_resets_table($pdo) {
    static $ensured = false;
    if ($ensured || !$pdo) {
        return;
    }
    try {
        $sql = "CREATE TABLE IF NOT EXISTS `password_resets` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `email` VARCHAR(150) NOT NULL,
            `token` VARCHAR(64) NOT NULL UNIQUE,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL,
            `used_at` DATETIME NULL,
            `ip_address` VARCHAR(45) NULL,
            INDEX `idx_token` (`token`),
            INDEX `idx_user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $pdo->exec($sql);
        $ensured = true;
    } catch (Exception $e) {
        // Abaikan jika sudah ada
    }
}

/**
 * Render template HTML email Reset Kata Sandi
 *
 * @param array $data [
 *    'nama'            => string,
 *    'email'           => string,
 *    'reset_url'       => string,
 *    'expires_minutes' => int (optional, default 60),
 *    'logo_cid'        => string (optional, default 'ilmuna_logo')
 * ]
 * @return array ['subject' => string, 'body' => string, 'attachments' => array]
 */
function ilmuna_get_reset_password_email_template($data) {
    // 3 Warna Utama Brand Ilmuna
    $c_navy  = '#1D3B5E'; // Biru Tua
    $c_teal  = '#0D7A87'; // Teal / Hijau Toska
    $c_sky   = '#52B2DC'; // Biru Muda (Sky Blue)

    $nama           = htmlspecialchars($data['nama'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8');
    $email          = htmlspecialchars($data['email'] ?? '', ENT_QUOTES, 'UTF-8');
    $resetUrl       = htmlspecialchars($data['reset_url'] ?? '#', ENT_QUOTES, 'UTF-8');
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

    $subject = "Permintaan Atur Ulang Kata Sandi — {$siteName}";

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
            background-color: #F8FAFC;
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
            border: 0;
            outline: none;
            text-decoration: none;
        }
        @media only screen and (max-width: 600px) {
            .ilmuna-container {
                width: 100% !important;
                max-width: 100% !important;
                border-radius: 0 !important;
                border-left: none !important;
                border-right: none !important;
            }
            .ilmuna-hdr-pad {
                padding: 26px 18px 22px !important;
            }
            .ilmuna-bdy-pad {
                padding: 24px 18px 20px !important;
            }
            .ilmuna-btn-cta {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
                text-align: center !important;
                padding: 14px 16px !important;
                font-size: 15px !important;
            }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#F8FAFC;-webkit-font-smoothing:antialiased;">
    <div style="display:none;font-size:1px;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;mso-hide:all;">
        Permintaan atur ulang kata sandi akun {$siteName}. Tautan ini berlaku selama {$expiresMinutes} menit.
    </div>

    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#F8FAFC;">
        <tr>
            <td align="center" style="padding: 30px 12px 40px;">

                <!-- CARD CONTAINER -->
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" class="ilmuna-container" style="max-width: 600px; background-color: #FFFFFF; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(29, 59, 94, 0.08); border: 1px solid #E2E8F0;">
                    
                    <!-- HEADER SECTION -->
                    <tr>
                        <td class="ilmuna-hdr-pad" style="background: linear-gradient(135deg, {$c_navy} 0%, {$c_teal} 100%); padding: 34px 30px 30px; text-align: center;">
                            <img src="{$logoSrc}" alt="{$siteName}" width="64" height="64" style="width: 64px; height: 64px; margin-bottom: 14px; border-radius: 12px; display: inline-block;">
                            <h1 style="margin: 0 0 6px; font-size: 24px; font-weight: 800; color: #FFFFFF; letter-spacing: -0.3px;">
                                {$siteName}
                            </h1>
                            <p style="margin: 0; font-size: 13.5px; color: #E6F4F6; line-height: 1.4;">
                                Permintaan Atur Ulang Kata Sandi
                            </p>
                        </td>
                    </tr>

                    <!-- BODY SECTION -->
                    <tr>
                        <td class="ilmuna-bdy-pad" style="padding: 32px 30px 24px;">
                            <p style="margin: 0 0 14px; font-size: 15px; color: #0F1D2F; line-height: 1.6;">
                                Halo, <strong>{$nama}</strong>!
                            </p>
                            <p style="margin: 0 0 20px; font-size: 14px; color: #475569; line-height: 1.6;">
                                Kami menerima permintaan untuk mengatur ulang kata sandi akun <strong>{$siteName}</strong> yang terhubung ke alamat email: <strong style="color: {$c_teal};">{$email}</strong>.
                            </p>

                            <!-- TOMBOL CTA -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto 24px;">
                                <tr>
                                    <td align="center" style="border-radius: 12px; background: linear-gradient(135deg, {$c_teal} 0%, {$c_navy} 100%); box-shadow: 0 8px 20px rgba(13, 122, 135, 0.35);">
                                        <a href="{$resetUrl}" target="_blank" class="ilmuna-btn-cta" style="display: inline-block; padding: 15px 36px; font-size: 15px; font-weight: 800; color: #FFFFFF; text-decoration: none; border-radius: 12px; letter-spacing: 0.3px;">
                                            🔑 Atur Ulang Kata Sandi
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- PERINGATAN MASA BERLAKU -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #FEF3C7; border-left: 4px solid #F59E0B; border-radius: 8px; margin-bottom: 20px;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 12.5px; color: #92400E; line-height: 1.6;">
                                        <strong>⏰ Masa Berlaku:</strong> Tautan ini hanya berlaku selama <strong>{$expiresMinutes} menit</strong> ke depan. Jika melewati batas waktu, Anda perlu meminta tautan baru.
                                    </td>
                                </tr>
                            </table>

                            <!-- LINK CADANGAN TEKS -->
                            <p style="margin: 0 0 6px; font-size: 12px; color: #64748B; line-height: 1.5;">
                                Jika tombol di atas tidak dapat diklik, salin dan tempel tautan berikut ke peramban (browser) Anda:
                            </p>
                            <p style="margin: 0 0 20px; font-size: 12px; color: {$c_teal}; word-break: break-all;">
                                <a href="{$resetUrl}" target="_blank" style="color: {$c_teal}; text-decoration: underline; font-weight: 600;">{$resetUrl}</a>
                            </p>

                            <!-- KEAMANAN DISCLAMER -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 12px; color: #64748B; line-height: 1.6;">
                                        🛡️ <strong>Bukan Anda yang meminta?</strong> Jangan khawatir, akun Anda tetap aman. Abaikan email ini dan kata sandi Anda tidak akan berubah.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- FOOTER SECTION -->
                    <tr>
                        <td style="padding: 22px 30px 24px; background-color: #F8FAFC; border-top: 1px solid #E2E8F0; text-align: center;">
                            <p style="margin: 0 0 4px; font-size: 13px; font-weight: 700; color: {$c_navy};">
                                {$siteName}
                            </p>
                            <p style="margin: 0 0 8px; font-size: 11.5px; color: #64748B;">
                                {$siteTag} &bull; Portal Pembelajaran Terpadu
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #94A3B8; line-height: 1.6;">
                                Email ini dibuat secara otomatis oleh server portal.<br>
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
 * Kirim email Reset Kata Sandi via Socket SMTP
 *
 * @param string $toEmail
 * @param string $userName
 * @param string $resetUrl
 * @param int $expiresInMinutes
 * @return array ['success' => bool, 'message' => string]
 */
function ilmuna_send_password_reset_email($toEmail, $userName, $resetUrl, $expiresInMinutes = 60) {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return [
            'success' => false,
            'message' => 'Alamat email penerima tidak valid: ' . $toEmail
        ];
    }

    $cfg = ilmuna_smtp_config();
    if (empty($cfg['is_enabled'])) {
        return [
            'success' => false,
            'message' => 'Layanan pengiriman email (SMTP) saat ini sedang dinonaktifkan oleh administrator.'
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
        'email'           => $toEmail,
        'reset_url'       => $resetUrl,
        'expires_minutes' => $expiresInMinutes
    ];

    $tpl = ilmuna_get_reset_password_email_template($tplData);

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
    if (function_exists('ilmuna_log_email_send')) {
        ilmuna_log_email_send([
            'timestamp' => date('Y-m-d H:i:s'),
            'to'        => $toEmail,
            'nama'      => $userName,
            'username'  => 'password_reset',
            'success'   => $res['success'],
            'detail'    => $res['message']
        ]);
    }

    return $res;
}
