<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — TEMPLATE EMAIL KREDENSIAL PENGGUNA (GMAIL & SMTP)
 * ==============================================================================
 * Lokasi: includes/email-credential-template.php
 * Palet Warna Resmi Ilmuna:
 * 1. Biru Tua (Navy)      : #1D3B5E  rgb(29, 59, 94)
 * 2. Teal / Hijau Toska   : #0D7A87  rgb(13, 122, 135)
 * 3. Biru Muda (Sky Blue) : #52B2DC  rgb(82, 178, 220)
 * ==============================================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/**
 * Render HTML Template Email Kredensial Resmi Ilmuna
 *
 * @param array $data [
 *    'nama'      => string,
 *    'username'  => string,
 *    'password'  => string,
 *    'role_name' => string,
 *    'login_url' => string (optional),
 *    'logo_url'  => string (optional),
 *    'logo_cid'  => string (optional, default 'ilmuna_logo')
 * ]
 * @return array ['subject' => string, 'body' => string, 'attachments' => array]
 */
function ilmuna_get_credential_email_template($data) {
    // 3 Warna Resmi Brand Ilmuna
    $c_navy  = '#1D3B5E'; // Biru Tua
    $c_teal  = '#0D7A87'; // Teal / Hijau Toska
    $c_sky   = '#52B2DC'; // Biru Muda (Sky Blue)

    $nama      = htmlspecialchars($data['nama'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8');
    $username  = htmlspecialchars($data['username'] ?? '', ENT_QUOTES, 'UTF-8');
    $password  = htmlspecialchars($data['password'] ?? '', ENT_QUOTES, 'UTF-8');
    $roleName  = htmlspecialchars($data['role_name'] ?? 'Pengguna Sistem', ENT_QUOTES, 'UTF-8');
    
    $siteName  = defined('APP_NAME') ? APP_NAME : 'Ilmuna Learn';
    $siteTag   = defined('APP_TAGLINE') ? APP_TAGLINE : 'Media Pembelajaran Digital Pendidikan Agama Islam';
    $baseUrl   = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'https://learn.ilmuna.my.id';
    $loginUrl  = htmlspecialchars($data['login_url'] ?? ($baseUrl . '/login'), ENT_QUOTES, 'UTF-8');
    $tahun     = date('Y');

    // Penanganan Logo Resmi (assets/images/logo.png)
    $logoFile = BASE_PATH . '/assets/images/logo.png';
    $attachments = [];
    $logoSrc = $baseUrl . '/assets/images/logo.png';

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

    $subject = "Kredensial Akun Pembelajaran {$siteName} — {$nama}";

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
        /* Responsif Sempurna di Mobile / Smartphone */
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
            .ilmuna-cred-box {
                padding: 16px 14px !important;
            }
            .ilmuna-cred-val {
                font-size: 16px !important;
                word-break: break-all !important;
            }
            .ilmuna-btn-cta {
                display: block !important;
                width: 100% !important;
                box-sizing: border-box !important;
                text-align: center !important;
                padding: 14px 16px !important;
                font-size: 15px !important;
            }
            .ilmuna-ftr-pad {
                padding: 20px 16px !important;
            }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#F8FAFC;-webkit-font-smoothing:antialiased;">

    <!-- Wrapper -->
    <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color:#F8FAFC;padding:26px 8px;">
        <tr>
            <td align="center" style="vertical-align:top;">

                <!-- Preheader Text -->
                <div style="display:none;font-size:1px;color:#F8FAFC;line-height:1px;max-height:0px;max-width:0px;opacity:0;overflow:hidden;">
                    Halo {$nama}, Akun Pembelajaran {$siteName} Anda telah aktif. Username & Password resmi Anda tercantum di sini.
                </div>

                <!-- CARD CONTAINER -->
                <table role="presentation" class="ilmuna-container" cellspacing="0" cellpadding="0" border="0" width="100%" style="max-width:580px;background-color:#FFFFFF;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(29,59,94,0.08);border:1px solid #E2E8F0;">
                    
                    <!-- ============================================================== -->
                    <!-- HEADER RESMI: BIRU TUA #1D3B5E -> TEAL #0D7A87 + BORDER #52B2DC -->
                    <!-- ============================================================== -->
                    <tr>
                        <td class="ilmuna-hdr-pad" style="background: linear-gradient(135deg, {$c_navy} 0%, {$c_teal} 100%); padding: 34px 30px 28px; text-align: center; border-bottom: 3.5px solid {$c_sky};">
                            
                            <!-- Logo Resmi Ilmuna Badge -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto 14px;">
                                <tr>
                                    <td align="center" style="width: 78px; height: 78px; background-color: #FFFFFF; border-radius: 18px; box-shadow: 0 6px 20px rgba(0,0,0,0.18); border: 2px solid {$c_sky}; text-align: center; vertical-align: middle;">
                                        <img src="{$logoSrc}" alt="{$siteName}" width="56" height="56" style="width:56px;height:56px;max-width:56px;display:block;margin:0 auto;object-fit:contain;" />
                                    </td>
                                </tr>
                            </table>

                            <!-- Header Typography -->
                            <h1 style="margin: 0 0 6px; color: #FFFFFF; font-size: 22px; font-weight: 800; letter-spacing: -0.2px; line-height: 1.3;">
                                Akun Portal Pembelajaran
                            </h1>
                            <p style="margin: 0; color: {$c_sky}; font-size: 13.5px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase;">
                                {$siteName}
                            </p>
                            <p style="margin: 4px 0 0; color: #E2E8F0; font-size: 12px; font-weight: 400;">
                                {$siteTag}
                            </p>
                        </td>
                    </tr>

                    <!-- ============================================================== -->
                    <!-- BODY SECTION                                                   -->
                    <!-- ============================================================== -->
                    <tr>
                        <td class="ilmuna-bdy-pad" style="padding: 32px 32px 18px;">
                            
                            <p style="margin: 0 0 12px; font-size: 15px; color: {$c_navy}; line-height: 1.6;">
                                <strong>Assalamu'alaikum Warahmatullahi Wabarakatuh,</strong><br>
                                Yth. <strong style="color: {$c_teal};">{$nama}</strong>
                            </p>

                            <p style="margin: 0 0 20px; font-size: 13.5px; color: #475569; line-height: 1.7;">
                                Akun resmi Anda pada portal digital <strong>{$siteName}</strong> telah berhasil dibuat secara otomatis. Kredensial di bawah ini digunakan untuk login dan mengakses seluruh modul, materi, presensi, serta asesmen ujian (CBT):
                            </p>

                            <!-- Role Badge Pill -->
                            <div style="margin-bottom: 16px;">
                                <span style="display: inline-block; padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 700; background: #F0F9FF; color: {$c_teal}; border: 1px solid {$c_sky};">
                                    Peran Pengguna: {$roleName}
                                </span>
                            </div>

                            <!-- ============================================================== -->
                            <!-- BOX KREDENSIAL: TEMA TEAL & SKY BLUE (NO GOLD!)                -->
                            <!-- ============================================================== -->
                            <table role="presentation" class="ilmuna-cred-box" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F8FAFC; border: 1.5px solid #CBD5E1; border-top: 3.5px solid {$c_teal}; border-radius: 12px; margin-bottom: 24px;">
                                <tr>
                                    <td style="padding: 20px 22px;">
                                        
                                        <!-- Field Username -->
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: {$c_teal}; padding-bottom: 4px;">
                                                    👤 Username Login
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="ilmuna-cred-val" style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 19px; font-weight: 800; color: {$c_navy}; letter-spacing: 0.8px; word-break: break-all;">
                                                    {$username}
                                                </td>
                                            </tr>
                                        </table>

                                        <!-- Divider Garis Sky Blue -->
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin: 12px 0;">
                                            <tr>
                                                <td style="border-top: 1px dashed #CBD5E1; font-size: 1px; line-height: 1px;">&nbsp;</td>
                                            </tr>
                                        </table>

                                        <!-- Field Password -->
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%">
                                            <tr>
                                                <td style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: {$c_teal}; padding-bottom: 4px;">
                                                    🔑 Kata Sandi (Password)
                                                </td>
                                            </tr>
                                            <tr>
                                                <td class="ilmuna-cred-val" style="font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size: 19px; font-weight: 800; color: {$c_navy}; letter-spacing: 0.8px; word-break: break-all;">
                                                    {$password}
                                                </td>
                                            </tr>
                                        </table>

                                    </td>
                                </tr>
                            </table>

                            <!-- ============================================================== -->
                            <!-- TOMBOL LOGIN (CTA): GRADIENT TEAL #0D7A87 KE NAVY #1D3B5E     -->
                            <!-- ============================================================== -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto 24px;">
                                <tr>
                                    <td align="center" style="border-radius: 10px; background: linear-gradient(135deg, {$c_teal} 0%, {$c_navy} 100%); box-shadow: 0 6px 18px rgba(13, 122, 135, 0.35);">
                                        <a href="{$loginUrl}" target="_blank" class="ilmuna-btn-cta" style="display: inline-block; padding: 14px 34px; font-size: 15px; font-weight: 800; color: #FFFFFF; text-decoration: none; border-radius: 10px; letter-spacing: 0.3px;">
                                            🚀 Buka Portal & Masuk
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <!-- Petunjuk Keamanan Akun (Border Sky Blue) -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="background-color: #F0F9FF; border-left: 4px solid {$c_sky}; border-radius: 8px; margin-bottom: 20px;">
                                <tr>
                                    <td style="padding: 13px 16px; font-size: 12.5px; color: {$c_navy}; line-height: 1.6;">
                                        <strong>🛡️ Panduan Keamanan Akun:</strong><br>
                                        &bull; Segera ubah kata sandi ini setelah Anda pertama kali berhasil login.<br>
                                        &bull; Jangan membagikan informasi login ini kepada siapa pun.<br>
                                        &bull; Selalu klik <em>Keluar / Logout</em> setelah selesai menggunakan perangkat umum.
                                    </td>
                                </tr>
                            </table>

                            <!-- Link Cadangan Teks -->
                            <p style="margin: 0 0 5px; font-size: 11.5px; color: #64748B; line-height: 1.5;">
                                Jika tombol tidak dapat diklik, salin URL login berikut ke peramban (browser) Anda:
                            </p>
                            <p style="margin: 0 0 10px; font-size: 12px; color: {$c_teal}; word-break: break-all;">
                                <a href="{$loginUrl}" target="_blank" style="color: {$c_teal}; text-decoration: underline; font-weight: 600;">{$loginUrl}</a>
                            </p>

                        </td>
                    </tr>

                    <!-- ============================================================== -->
                    <!-- FOOTER SECTION (TEMA NAVY & SLATE)                             -->
                    <!-- ============================================================== -->
                    <tr>
                        <td class="ilmuna-ftr-pad" style="padding: 22px 30px 24px; background-color: #F8FAFC; border-top: 1px solid #E2E8F0; text-align: center;">
                            <p style="margin: 0 0 4px; font-size: 13px; font-weight: 700; color: {$c_navy};">
                                {$siteName}
                            </p>
                            <p style="margin: 0 0 8px; font-size: 11.5px; color: #64748B;">
                                {$siteTag} &bull; Portal Pembelajaran Terpadu
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #94A3B8; line-height: 1.6;">
                                Email ini dikirimkan secara otomatis oleh sistem server.<br>
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
