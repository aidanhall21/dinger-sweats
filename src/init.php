<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Rate limiting
$timeWindow = 60; // 1 minute
$maxRequests = 60; // 60 requests per minute

if (!isset($_SESSION['request_count'])) {
    $_SESSION['request_count'] = 0;
    $_SESSION['window_start'] = time();
}

if (time() - $_SESSION['window_start'] > $timeWindow) {
    $_SESSION['request_count'] = 0;
    $_SESSION['window_start'] = time();
}

$_SESSION['request_count']++;
if ($_SESSION['request_count'] > $maxRequests) {
    http_response_code(429);
    die('Too many requests. Please try again later.');
}

// Bot detection
function isBot() {
    $botPatterns = array(
        'bot', 'crawler', 'spider', 'slurp', 'baidu', 'yandex', 'bingbot',
        'googlebot', 'yahoo', 'duckduckbot', 'baiduspider'
    );
    
    $userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
    foreach ($botPatterns as $pattern) {
        if (strpos($userAgent, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

if (isBot()) {
    // Either block the request or serve a simplified version
    header('X-Robots-Tag: noindex, nofollow');
}