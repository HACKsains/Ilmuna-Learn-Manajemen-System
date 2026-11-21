# Panduan Integrasi Zero-Downtime `learn.ilmuna.my.id` ↔ Margo One SSO

Integrasi ini dirancang **zero downtime** dengan *feature flag* `ILMUNA_SSO_ENABLED` (default `false`) serta jalur darurat login Admin lokal (`?local_admin=1`).

---

## 1. Salin Berkas Integrasi ke `learn`
1. Salin `sdk/ilmuna-sso-client.php` ke `learn/sdk/ilmuna-sso-client.php`.
2. Salin `sdk/learn-integration/sso-callback.php` ke `learn/sso-callback.php`.
3. Salin `sdk/learn-integration/sso-logout-webhook.php` ke `learn/sso-logout-webhook.php`.

---

## 2. Tambahkan Feature Flag di `learn/config.php`
Tambahkan blok berikut di `learn/config.php` (biarkan `false` hingga kunci selesai diuji):

```php
// ==============================================================================
// MARGO ONE — ILMUNA SSO INTEGRATION (FEATURE FLAG)
// ==============================================================================
define('ILMUNA_SSO_ENABLED', false); // Ubah ke true setelah uji coba sukses
define('ILMUNA_SSO_ENFORCED', false); // Jika true, login non-admin otomatis diarahkan ke Margo One
define('ILMUNA_SSO_URL', 'https://margo-one.ilmuna.my.id');
define('ILMUNA_SSO_PUBLIC_KEY', 'pk_sso_...');  // Salin dari Panel Admin Margo One
define('ILMUNA_SSO_PRIVATE_KEY', 'sk_sso_...'); // Salin dari Panel Admin Margo One
define('ILMUNA_SSO_SIG_MODE', 'HMAC-SHA256');   // HMAC-SHA256 | RSA-SHA256 | DUAL
define('ILMUNA_SSO_RSA_PRIVATE_PEM', '');
```

---

## 3. Tambahkan Tombol "Masuk dengan Margo One" & Jalur Darurat Admin di `learn/login.php`

### A. Handler Redirect OAuth2 (sebelum render HTML di `learn/login.php`):
```php
$isLocalAdminBypass = isset($_GET['local_admin']) && $_GET['local_admin'] === '1';

if (defined('ILMUNA_SSO_ENABLED') && ILMUNA_SSO_ENABLED) {
    if (isset($_GET['sso_login']) || (defined('ILMUNA_SSO_ENFORCED') && ILMUNA_SSO_ENFORCED && !$isLocalAdminBypass && $_SERVER['REQUEST_METHOD'] === 'GET')) {
        require_once __DIR__ . '/sdk/ilmuna-sso-client.php';
        $ssoClient = new MargoOneSsoClient([
            'sso_url'      => ILMUNA_SSO_URL,
            'public_key'   => ILMUNA_SSO_PUBLIC_KEY,
            'private_key'  => ILMUNA_SSO_PRIVATE_KEY,
            'redirect_uri' => BASE_URL . '/sso-callback',
        ]);
        header('Location: ' . $ssoClient->buildAuthorizeUrl());
        exit;
    }
}
```

### B. Tombol UI Lumina Gold di Form `learn/login.php`:
```html
<?php if (defined('ILMUNA_SSO_ENABLED') && ILMUNA_SSO_ENABLED): ?>
    <a href="<?= htmlspecialchars(BASE_URL . '/login.php?sso_login=1', ENT_QUOTES, 'UTF-8') ?>"
       class="btn-submit" style="margin-bottom: 12px; text-decoration: none;">
        <iconify-icon icon="solar:shield-keyhole-bold" width="18"></iconify-icon>
        <span>Masuk dengan Margo One</span>
    </a>
<?php endif; ?>
```

### C. Jalur Login Darurat Admin Lokal:
Buka `https://learn.ilmuna.my.id/login.php?local_admin=1` kapan pun untuk login lokal apabila subdomain SSO sedang pemeliharaan.

---

## 4. Sinkronisasi Logout (`learn/logout.php`)
Saat user keluar di `learn/logout.php`, arahkan ke Global Single Logout Margo One:
```php
if (defined('ILMUNA_SSO_ENABLED') && ILMUNA_SSO_ENABLED) {
    $sloUrl = rtrim(ILMUNA_SSO_URL, '/') . '/logout?post_logout_redirect_uri=' . urlencode(BASE_URL . '/login.php');
    logoutUser();
    header('Location: ' . $sloUrl);
    exit;
}
```
