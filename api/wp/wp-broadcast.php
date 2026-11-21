<?php
/**
 * ==============================================================================
 * ILMUNA LEARN — DEDICATED SERVER-TO-SERVER WORDPRESS API ENTRYPOINT
 * ==============================================================================
 * Endpoint Resmi: https://learn.ilmuna.my.id/api/wp/wp-broadcast.php
 * Berada di folder terisolasi api/wp/ agar bebas dari HTML JS-interstitial WAF
 * pada LiteSpeed LSAPI / Apache cPanel, namun tetap 100% dilindungi oleh
 * Zero-Trust Public Key + Private Key (HMAC-SHA256 / RSA-2048 / DUAL).
 * ==============================================================================
 */

if (!defined('ILMUNA_SKIP_GD_WAF_INTERSTITIAL')) {
    define('ILMUNA_SKIP_GD_WAF_INTERSTITIAL', true);
}

require_once __DIR__ . '/../wp-broadcast.php';
