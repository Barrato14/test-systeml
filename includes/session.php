<?php
// Shared Zentrix session settings. Keep these consistent across all application routes.
if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 60 * 60 * 24 * 30;

    ini_set('session.gc_maxlifetime', (string)$sessionLifetime);
    ini_set('session.cookie_lifetime', (string)$sessionLifetime);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_trans_sid', '0');

    $isSecure = (
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
    );

    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

// Defense-in-depth CSRF check for browser POSTs. This is applied centrally so
// existing forms and same-origin fetch calls do not need their payloads changed.
if (
    session_status() === PHP_SESSION_ACTIVE
    && strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
    && isset($_SESSION['user_id'])
) {
    $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    $foreignRequest = false;

    if ($origin !== '') {
        $originHost = strtolower((string)(parse_url($origin, PHP_URL_HOST) ?? ''));
        $requestHostOnly = strtolower((string)(parse_url('http://' . $requestHost, PHP_URL_HOST) ?? ''));
        $foreignRequest = $originHost === '' || $requestHostOnly === '' || !hash_equals($requestHostOnly, $originHost);
    } elseif ($fetchSite === 'cross-site') {
        $foreignRequest = true;
    } elseif ($referer !== '') {
        $refererHost = strtolower((string)(parse_url($referer, PHP_URL_HOST) ?? ''));
        $requestHostOnly = strtolower((string)(parse_url('http://' . $requestHost, PHP_URL_HOST) ?? ''));
        $foreignRequest = $refererHost === '' || $requestHostOnly === '' || !hash_equals($requestHostOnly, $refererHost);
    }

    if ($foreignRequest) {
        http_response_code(403);
        if (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'This request was blocked by Zentrix security. Refresh the page and try again.']);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'This request was blocked by Zentrix security. Please return to Zentrix and try again.';
        }
        exit;
    }
}
