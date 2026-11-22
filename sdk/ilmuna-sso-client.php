<?php
/**
 * ==============================================================================
 * MARGO ONE — ILMUNA SSO · OFFICIAL DROP-IN PHP CLIENT SDK (v1.0.0)
 * ==============================================================================
 * Zero-dependency PHP 7.4 (ea-php74) hingga PHP 8.3 SDK untuk menghubungkan
 * aplikasi PHP (learn.ilmuna.my.id, PPDB, Perpus, dsb.) ke Margo One SSO
 * (https://margo-one.ilmuna.my.id).
 *
 * Fitur Keamanan:
 *   - OAuth 2.0 Authorization Code + PKCE (S256) + State + Nonce
 *   - Signed Server-to-Server Request (HMAC-SHA256 | RSA-SHA256 | DUAL)
 *   - Verifikasi Signed Response (X-Ilmuna-Response-Timestamp & Signature)
 *   - Verifikasi JWT RS256 lokal via cache JWKS (/api/sso/jwks.php)
 *   - Refresh Token Rotation, UserInfo, Introspect & Signed SLO Webhook Handler
 * ==============================================================================
 */

if (!class_exists('MargoOneSsoClient')) {
    class MargoOneSsoClient
    {
        /** @var string */
        private $ssoUrl;
        /** @var string */
        private $publicKey;
        /** @var string */
        private $privateKey;
        /** @var string */
        private $redirectUri;
        /** @var string */
        private $sigMode;
        /** @var string */
        private $rsaPrivatePem;
        /** @var string */
        private $siteHost;
        /** @var bool */
        private $verifyResponse;
        /** @var bool */
        private $sslVerify;
        /** @var int */
        private $timeout;
        /** @var string */
        private $jwksCacheFile;
        /** @var int */
        private $jwksCacheTtl;

        public function __construct(array $config)
        {
            $this->ssoUrl         = rtrim((string)($config['sso_url'] ?? 'https://margo-one.ilmuna.my.id'), '/');
            $this->publicKey      = trim((string)($config['public_key'] ?? ''));
            $this->privateKey     = trim((string)($config['private_key'] ?? ''));
            $this->redirectUri    = trim((string)($config['redirect_uri'] ?? ''));
            $this->sigMode        = strtoupper(trim((string)($config['sig_mode'] ?? 'HMAC-SHA256')));
            $this->rsaPrivatePem  = trim((string)($config['rsa_private_pem'] ?? ''));
            $this->siteHost       = trim((string)($config['site_host'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost')));
            $this->verifyResponse = isset($config['verify_response']) ? (bool)$config['verify_response'] : true;
            $this->sslVerify      = isset($config['ssl_verify']) ? (bool)$config['ssl_verify'] : true;
            $this->timeout        = max(3, (int)($config['timeout'] ?? 15));
            $this->jwksCacheTtl   = max(60, (int)($config['jwks_cache_ttl'] ?? 3600));
            $this->jwksCacheFile  = isset($config['jwks_cache_file']) && $config['jwks_cache_file'] !== ''
                ? (string)$config['jwks_cache_file']
                : (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'margo_sso_jwks_' . md5($this->ssoUrl) . '.json');
        }

        public static function base64UrlEncode($data)
        {
            return rtrim(strtr(base64_encode((string)$data), '+/', '-_'), '=');
        }

        public static function base64UrlDecode($data)
        {
            $s = strtr((string)$data, '-_', '+/');
            $rem = strlen($s) % 4;
            if ($rem > 0) {
                $s .= str_repeat('=', 4 - $rem);
            }
            return base64_decode($s, true);
        }

        public static function pkceChallengeS256($codeVerifier)
        {
            return self::base64UrlEncode(hash('sha256', (string)$codeVerifier, true));
        }

        /**
         * Bangun URL otorisasi /authorize lengkap dengan state, nonce, & PKCE S256
         * dan simpan otomatis ke $_SESSION['_margo_sso_flow']
         */
        public function buildAuthorizeUrl(array $opts = [])
        {
            if (session_status() === PHP_SESSION_NONE && !headers_sent() && php_sapi_name() !== 'cli') {
                session_start();
            }

            $state        = bin2hex(random_bytes(24));
            $nonce        = bin2hex(random_bytes(16));
            $codeVerifier = self::base64UrlEncode(random_bytes(48)); // 64 chars RFC 7636
            $challenge    = self::pkceChallengeS256($codeVerifier);
            $scope        = isset($opts['scope']) ? (string)$opts['scope'] : 'openid profile email roles';
            $redirectUri  = isset($opts['redirect_uri']) && $opts['redirect_uri'] !== ''
                ? (string)$opts['redirect_uri']
                : $this->redirectUri;

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['_margo_sso_flow'] = [
                    'state'         => $state,
                    'nonce'         => $nonce,
                    'code_verifier' => $codeVerifier,
                    'redirect_uri'  => $redirectUri,
                    'created_at'    => time(),
                ];
            }

            $params = [
                'client_id'             => $this->publicKey,
                'redirect_uri'          => $redirectUri,
                'response_type'         => 'code',
                'scope'                 => $scope,
                'state'                 => $state,
                'nonce'                 => $nonce,
                'code_challenge'        => $challenge,
                'code_challenge_method' => 'S256',
            ];
            if (!empty($opts['prompt'])) {
                $params['prompt'] = (string)$opts['prompt'];
            }

            return $this->ssoUrl . '/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        /**
         * Buat parameter OAuth2 + PKCE tanpa menyentuh $_SESSION (berguna untuk CLI / API)
         */
        public function createAuthorizationRequest(array $opts = [])
        {
            $state        = bin2hex(random_bytes(24));
            $nonce        = bin2hex(random_bytes(16));
            $codeVerifier = self::base64UrlEncode(random_bytes(48));
            $challenge    = self::pkceChallengeS256($codeVerifier);
            $scope        = isset($opts['scope']) ? (string)$opts['scope'] : 'openid profile email roles';
            $redirectUri  = isset($opts['redirect_uri']) && $opts['redirect_uri'] !== ''
                ? (string)$opts['redirect_uri']
                : $this->redirectUri;

            $params = [
                'client_id'             => $this->publicKey,
                'redirect_uri'          => $redirectUri,
                'response_type'         => 'code',
                'scope'                 => $scope,
                'state'                 => $state,
                'nonce'                 => $nonce,
                'code_challenge'        => $challenge,
                'code_challenge_method' => 'S256',
            ];

            return [
                'url'            => $this->ssoUrl . '/authorize?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986),
                'state'          => $state,
                'nonce'          => $nonce,
                'code_verifier'  => $codeVerifier,
                'code_challenge' => $challenge,
                'redirect_uri'   => $redirectUri,
            ];
        }

        /**
         * Tukar authorization_code menjadi token bundle + verifikasi signed response + verifikasi JWT RS256
         */
        public function exchangeCode($code, $state, $codeVerifierOverride = null, $expectedNonceOverride = null, $redirectUriOverride = null)
        {
            if (session_status() === PHP_SESSION_NONE && !headers_sent() && php_sapi_name() !== 'cli') {
                session_start();
            }

            $flow = (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['_margo_sso_flow']))
                ? $_SESSION['_margo_sso_flow']
                : null;

            if ($codeVerifierOverride === null) {
                if (!is_array($flow) || empty($flow['state']) || empty($flow['code_verifier'])) {
                    throw new RuntimeException('Sesi OAuth PKCE tidak ditemukan. Silakan ulangi proses login.');
                }
                if (!hash_equals((string)$flow['state'], (string)$state)) {
                    unset($_SESSION['_margo_sso_flow']);
                    throw new RuntimeException('Parameter state CSRF tidak cocok.');
                }
                if ((time() - (int)($flow['created_at'] ?? 0)) > 900) {
                    unset($_SESSION['_margo_sso_flow']);
                    throw new RuntimeException('Sesi login OAuth telah kedaluwarsa.');
                }
                $codeVerifier  = (string)$flow['code_verifier'];
                $expectedNonce = isset($flow['nonce']) ? (string)$flow['nonce'] : null;
                $redirectUri   = !empty($flow['redirect_uri']) ? (string)$flow['redirect_uri'] : $this->redirectUri;
                unset($_SESSION['_margo_sso_flow']);
            } else {
                $codeVerifier  = (string)$codeVerifierOverride;
                $expectedNonce = $expectedNonceOverride;
                $redirectUri   = $redirectUriOverride !== null ? (string)$redirectUriOverride : $this->redirectUri;
            }

            $payload = json_encode([
                'grant_type'    => 'authorization_code',
                'code'          => (string)$code,
                'redirect_uri'  => $redirectUri,
                'code_verifier' => $codeVerifier,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $resp = $this->signedRequest('POST', '/api/sso/token.php', [], $payload);
            if ($resp['http_code'] !== 200 || empty($resp['data']['success'])) {
                $err = isset($resp['data']['error']['message'])
                    ? $resp['data']['error']['message'] . ' [' . ($resp['data']['error']['code'] ?? $resp['http_code']) . ']'
                    : ('Gagal menukar token (HTTP ' . $resp['http_code'] . ')');
                throw new RuntimeException($err);
            }

            $data = $resp['data'];
            $idToken = (string)($data['id_token'] ?? '');
            $claims = $this->verifyJwtRs256($idToken);

            if ($expectedNonce !== null && $expectedNonce !== '') {
                if (!isset($claims['nonce']) || !hash_equals((string)$expectedNonce, (string)$claims['nonce'])) {
                    throw new RuntimeException('Klaim nonce pada id_token tidak cocok.');
                }
            }

            $data['id_token_claims'] = $claims;
            return $data;
        }

        /**
         * Rotasi Refresh Token untuk memperbarui access_token & id_token
         */
        public function refreshAccessToken($refreshToken)
        {
            $payload = json_encode([
                'grant_type'    => 'refresh_token',
                'refresh_token' => (string)$refreshToken,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $resp = $this->signedRequest('POST', '/api/sso/token.php', [], $payload);
            if ($resp['http_code'] !== 200 || empty($resp['data']['success'])) {
                $err = isset($resp['data']['error']['message'])
                    ? $resp['data']['error']['message'] . ' [' . ($resp['data']['error']['code'] ?? $resp['http_code']) . ']'
                    : ('Refresh token gagal (HTTP ' . $resp['http_code'] . ')');
                throw new RuntimeException($err);
            }

            if (!empty($resp['data']['id_token'])) {
                $resp['data']['id_token_claims'] = $this->verifyJwtRs256((string)$resp['data']['id_token']);
            }
            return $resp['data'];
        }

        /**
         * Ambil profil pengguna terkini dari /api/sso/userinfo.php
         */
        public function fetchUserInfo($accessToken)
        {
            $resp = $this->signedRequest('GET', '/api/sso/userinfo.php', [], '', [
                'Authorization' => 'Bearer ' . trim((string)$accessToken),
            ]);
            if ($resp['http_code'] !== 200 || empty($resp['data']['success'])) {
                $err = isset($resp['data']['error']['message'])
                    ? $resp['data']['error']['message']
                    : ('Gagal mengambil userinfo (HTTP ' . $resp['http_code'] . ')');
                throw new RuntimeException($err);
            }
            return $resp['data'];
        }

        /**
         * Cek status aktif token / session_id real-time via /api/sso/introspect.php
         */
        public function introspect($tokenOrSid, $isSid = false)
        {
            $bodyArr = $isSid ? ['sid' => (int)$tokenOrSid] : ['token' => (string)$tokenOrSid];
            $payload = json_encode($bodyArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $resp = $this->signedRequest('POST', '/api/sso/introspect.php', [], $payload);
            if ($resp['http_code'] !== 200 || !is_array($resp['data'])) {
                return ['active' => false, 'http_code' => $resp['http_code']];
            }
            return $resp['data'];
        }

        /**
         * Bangun URL browser Global Single Logout (/logout) lengkap dengan tanda tangan HMAC
         * agar seluruh sesi pengguna di Margo One SSO & aplikasi terhubung otomatis dicabut.
         */
        public function buildLogoutUrl($postLogoutRedirectUri = '', $revokeAll = true, $userId = 0, $sid = 0)
        {
            $params = [
                'all' => $revokeAll ? '1' : '0',
            ];
            if ($postLogoutRedirectUri !== '') {
                $params['post_logout_redirect_uri'] = (string)$postLogoutRedirectUri;
            }
            $uid = (int)$userId;
            $sId = (int)$sid;
            if ($this->publicKey !== '' && $this->privateKey !== '' && ($uid > 0 || $sId > 0)) {
                $ts    = (string)time();
                $canon = 'SLO|' . $this->publicKey . '|' . $uid . '|' . $sId . '|' . $ts;
                $params['client_id'] = $this->publicKey;
                $params['uid']       = (string)$uid;
                $params['sid']       = (string)$sId;
                $params['ts']        = $ts;
                $params['sig']       = hash_hmac('sha256', $canon, $this->privateKey);
            }
            return $this->ssoUrl . '/logout' . (!empty($params) ? ('?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986)) : '');
        }

        /**
         * Kirim notifikasi back-channel logout ke Margo One saat user logout di aplikasi klien
         */
        public function triggerBackchannelLogout($sid, $userId = null, $revokeAll = false)
        {
            $payload = json_encode([
                'sid'        => (int)$sid,
                'user_id'    => $userId !== null ? (int)$userId : null,
                'revoke_all' => (bool)$revokeAll,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $this->signedRequest('POST', '/api/sso/logout-callback.php', [], $payload);
        }

        /**
         * Verifikasi incoming Back-Channel SLO Webhook dari Margo One SSO
         */
        public function handleLogoutWebhook($rawBody = null, array $headers = null)
        {
            if ($rawBody === null) {
                $rawBody = (string)file_get_contents('php://input');
            }
            $hdrs = [];
            if (is_array($headers)) {
                foreach ($headers as $k => $v) {
                    $hdrs[strtolower(trim((string)$k))] = trim((string)$v);
                }
            } else {
                foreach ($_SERVER as $k => $v) {
                    if (strpos($k, 'HTTP_') === 0) {
                        $hdrs[strtolower(str_replace('_', '-', substr($k, 5)))] = trim((string)$v);
                    }
                }
            }

            $pubKey = $hdrs['x-ilmuna-public-key'] ?? '';
            $ts     = $hdrs['x-ilmuna-timestamp'] ?? '';
            $nonce  = $hdrs['x-ilmuna-nonce'] ?? '';
            $sig    = strtolower($hdrs['x-ilmuna-signature'] ?? '');

            if ($pubKey === '' || !hash_equals($this->publicKey, $pubKey)) {
                return ['ok' => false, 'code' => 'INVALID_PUBLIC_KEY', 'message' => 'Public Key webhook tidak cocok.'];
            }
            if (!ctype_digit($ts) || abs(time() - (int)$ts) > 300) {
                return ['ok' => false, 'code' => 'TIMESTAMP_EXPIRED', 'message' => 'Timestamp webhook kedaluwarsa.'];
            }
            if ($nonce === '' || $sig === '') {
                return ['ok' => false, 'code' => 'MISSING_SIGNATURE', 'message' => 'Header signature webhook tidak lengkap.'];
            }

            $expected = hash_hmac('sha256', $ts . "\n" . $nonce . "\n" . $rawBody, $this->privateKey);
            if (!hash_equals($expected, $sig)) {
                return ['ok' => false, 'code' => 'INVALID_SIGNATURE', 'message' => 'Signature HMAC webhook SLO tidak valid.'];
            }

            $data = json_decode($rawBody, true);
            if (!is_array($data)) {
                return ['ok' => false, 'code' => 'INVALID_JSON', 'message' => 'Body webhook bukan JSON valid.'];
            }

            if (!empty($data['logout_token'])) {
                try {
                    $this->verifyJwtRs256((string)$data['logout_token']);
                } catch (Throwable $e) {
                    return ['ok' => false, 'code' => 'INVALID_LOGOUT_TOKEN', 'message' => $e->getMessage()];
                }
            }

            return [
                'ok'      => true,
                'event'   => (string)($data['event'] ?? 'single_logout'),
                'user_id' => isset($data['user_id']) ? (int)$data['user_id'] : 0,
                'sid'     => isset($data['sid']) ? (int)$data['sid'] : 0,
            ];
        }

        /**
         * Ambil JWK Set dari /api/sso/jwks.php dengan caching lokal
         */
        public function getJwks($forceRefresh = false)
        {
            if (!$forceRefresh && file_exists($this->jwksCacheFile)) {
                $age = time() - (int)@filemtime($this->jwksCacheFile);
                if ($age >= 0 && $age < $this->jwksCacheTtl) {
                    $cached = json_decode((string)@file_get_contents($this->jwksCacheFile), true);
                    if (is_array($cached) && !empty($cached['keys'])) {
                        return $cached;
                    }
                }
            }

            $res = $this->httpRequest('GET', $this->ssoUrl . '/api/sso/jwks.php', ['Accept' => 'application/json'], '');
            $jwks = json_decode($res['body'], true);
            if ($res['http_code'] !== 200 || !is_array($jwks) || empty($jwks['keys'])) {
                throw new RuntimeException('Gagal memuat JWKS publik dari ' . $this->ssoUrl . '/api/sso/jwks.php');
            }

            @file_put_contents($this->jwksCacheFile, json_encode($jwks), LOCK_EX);
            return $jwks;
        }

        /**
         * Verifikasi JWT RS256 secara lokal menggunakan JWKS (otomatis refresh 1x jika kid baru dirotasi)
         */
        public function verifyJwtRs256($jwt, $leeway = 60)
        {
            $parts = explode('.', trim((string)$jwt));
            if (count($parts) !== 3) {
                throw new RuntimeException('Format JWT tidak valid.');
            }
            $header  = json_decode((string)self::base64UrlDecode($parts[0]), true);
            $payload = json_decode((string)self::base64UrlDecode($parts[1]), true);
            $sig     = self::base64UrlDecode($parts[2]);

            if (!is_array($header) || !is_array($payload) || $sig === false) {
                throw new RuntimeException('Segmen JWT korup.');
            }
            if (($header['alg'] ?? '') !== 'RS256') {
                throw new RuntimeException('Algoritma JWT harus RS256.');
            }

            $kid = (string)($header['kid'] ?? '');
            $jwks = $this->getJwks(false);
            $pem = $this->findPemByKid($jwks, $kid);
            if ($pem === '') {
                $jwks = $this->getJwks(true);
                $pem = $this->findPemByKid($jwks, $kid);
            }
            if ($pem === '') {
                throw new RuntimeException('Kunci publik JWKS untuk kid "' . $kid . '" tidak ditemukan.');
            }

            $ok = @openssl_verify($parts[0] . '.' . $parts[1], $sig, $pem, OPENSSL_ALGO_SHA256);
            if ($ok !== 1) {
                throw new RuntimeException('Verifikasi tanda tangan JWT RS256 gagal.');
            }

            $now = time();
            if (isset($payload['exp']) && ($now - $leeway) >= (int)$payload['exp']) {
                throw new RuntimeException('JWT telah kedaluwarsa.');
            }
            if (isset($payload['nbf']) && ($now + $leeway) < (int)$payload['nbf']) {
                throw new RuntimeException('JWT belum aktif (nbf).');
            }
            $iss = rtrim((string)($payload['iss'] ?? ''), '/');
            if ($iss !== rtrim($this->ssoUrl, '/')) {
                throw new RuntimeException('Issuer JWT tidak cocok (' . $iss . ').');
            }
            $aud = $payload['aud'] ?? '';
            $audOk = is_array($aud) ? in_array($this->publicKey, $aud, true) : ((string)$aud === $this->publicKey);
            if (!$audOk) {
                throw new RuntimeException('Audience JWT tidak cocok dengan Public Key aplikasi.');
            }

            return $payload;
        }

        /**
         * Kirim request server-to-server bertanda tangan Zero-Trust + verifikasi Signed Response
         */
        public function signedRequest($method, $endpointPath, array $queryParams = [], $rawBody = '', array $extraHeaders = [])
        {
            $method = strtoupper((string)$method);
            $ts     = (string)time();
            $nonce  = bin2hex(random_bytes(16));

            $normQuery = [];
            foreach ($queryParams as $k => $v) {
                $normQuery[(string)$k] = is_array($v) ? implode(',', array_map('strval', $v)) : (string)$v;
            }
            ksort($normQuery, SORT_STRING);
            $canonicalQuery = http_build_query($normQuery, '', '&', PHP_QUERY_RFC3986);

            $baseName = preg_replace('/\.php$/i', '', basename((string)parse_url($endpointPath, PHP_URL_PATH)));
            $canonicalPath = '/api/sso/' . strtolower($baseName);
            $bodyHash = hash('sha256', (string)$rawBody);

            $canonical = implode("\n", [$method, $canonicalPath, $canonicalQuery, $ts, $nonce, $bodyHash]);

            $headers = array_merge([
                'Accept'              => 'application/json',
                'Content-Type'        => 'application/json',
                'X-Ilmuna-Public-Key' => $this->publicKey,
                'X-Ilmuna-Timestamp'  => $ts,
                'X-Ilmuna-Nonce'      => $nonce,
                'X-Ilmuna-Site'       => $this->siteHost,
            ], $extraHeaders);

            if ($this->sigMode === 'HMAC-SHA256' || $this->sigMode === 'DUAL') {
                $headers['X-Ilmuna-Signature'] = hash_hmac('sha256', $canonical, $this->privateKey);
            }
            if ($this->sigMode === 'RSA-SHA256' || $this->sigMode === 'DUAL') {
                if ($this->rsaPrivatePem === '') {
                    throw new RuntimeException('Mode ' . $this->sigMode . ' membutuhkan rsa_private_pem.');
                }
                $rawSig = '';
                if (!@openssl_sign($canonical, $rawSig, $this->rsaPrivatePem, OPENSSL_ALGO_SHA256)) {
                    throw new RuntimeException('Gagal menandatangani request dengan RSA Private Key PEM.');
                }
                $headers['X-Ilmuna-RSA-Signature'] = base64_encode($rawSig);
            }

            $url = $this->ssoUrl . '/' . ltrim($endpointPath, '/');
            if ($canonicalQuery !== '') {
                $url .= '?' . $canonicalQuery;
            }

            $resp = $this->httpRequest($method, $url, $headers, (string)$rawBody);

            if ($this->verifyResponse && $resp['http_code'] === 200) {
                $rTs  = $resp['headers']['x-ilmuna-response-timestamp'] ?? '';
                $rSig = strtolower($resp['headers']['x-ilmuna-response-signature'] ?? '');
                if ($rTs === '' || $rSig === '') {
                    throw new RuntimeException('Header X-Ilmuna-Response-Signature tidak ditemukan pada respon server SSO.');
                }
                if (abs(time() - (int)$rTs) > 300) {
                    throw new RuntimeException('Timestamp respon server SSO di luar jendela 5 menit.');
                }
                $expSig = hash_hmac('sha256', $rTs . "\n" . $resp['body'], $this->privateKey);
                if (!hash_equals($expSig, $rSig)) {
                    throw new RuntimeException('Verifikasi X-Ilmuna-Response-Signature gagal (potensi manipulasi MITM).');
                }
            }

            $decoded = json_decode($resp['body'], true);
            return [
                'http_code' => $resp['http_code'],
                'headers'   => $resp['headers'],
                'body'      => $resp['body'],
                'data'      => is_array($decoded) ? $decoded : null,
            ];
        }

        private function findPemByKid(array $jwks, $kid)
        {
            if (empty($jwks['keys']) || !is_array($jwks['keys'])) {
                return '';
            }
            foreach ($jwks['keys'] as $jwk) {
                if (!is_array($jwk)) {
                    continue;
                }
                if ($kid === '' || (isset($jwk['kid']) && (string)$jwk['kid'] === $kid)) {
                    return self::jwkToPem($jwk);
                }
            }
            return '';
        }

        private static function asn1Length($len)
        {
            $len = (int)$len;
            if ($len <= 0x7F) {
                return chr($len);
            }
            $tmp = ltrim(pack('N', $len), "\x00");
            return chr(0x80 | strlen($tmp)) . $tmp;
        }

        private static function asn1Int($bytes)
        {
            $bytes = ltrim((string)$bytes, "\x00");
            if ($bytes === '') {
                $bytes = "\x00";
            }
            if ((ord($bytes[0]) & 0x80) !== 0) {
                $bytes = "\x00" . $bytes;
            }
            return "\x02" . self::asn1Length(strlen($bytes)) . $bytes;
        }

        public static function jwkToPem(array $jwk)
        {
            if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
                return '';
            }
            $mod = self::base64UrlDecode($jwk['n']);
            $exp = self::base64UrlDecode($jwk['e']);
            if ($mod === false || $exp === false) {
                return '';
            }
            $rsaSeq = self::asn1Int($mod) . self::asn1Int($exp);
            $rsaPub = "\x30" . self::asn1Length(strlen($rsaSeq)) . $rsaSeq;
            $bitStr = "\x03" . self::asn1Length(strlen($rsaPub) + 1) . "\x00" . $rsaPub;
            $algoId = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
            $spki   = "\x30" . self::asn1Length(strlen($algoId . $bitStr)) . $algoId . $bitStr;
            return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
        }

        private function httpRequest($method, $url, array $headers, $body)
        {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('Ekstensi PHP cURL wajib aktif untuk MargoOneSsoClient.');
            }
            $respHeaders = [];
            $hdrLines = [];
            foreach ($headers as $k => $v) {
                $hdrLines[] = $k . ': ' . $v;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $hdrLines,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => min(8, $this->timeout),
                CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
                CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
                CURLOPT_HEADERFUNCTION => function ($chObj, $line) use (&$respHeaders) {
                    $len = strlen($line);
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return $len;
                },
            ]);
            if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $respBody = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($respBody === false) {
                throw new RuntimeException('Koneksi HTTP ke Margo One SSO gagal: ' . $curlErr);
            }

            return [
                'http_code' => $httpCode,
                'headers'   => $respHeaders,
                'body'      => (string)$respBody,
            ];
        }
    }
}
