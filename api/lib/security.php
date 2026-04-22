<?php
function getClientIP() {
    // Vorsicht: HTTP_X_FORWARDED_FOR kann gespooft werden, wenn kein Trusted Proxy davor steht
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function normalizeIP($ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $parts = explode(':', $ip);
        // Kürzt auf /64 Präfix für stabilere Signaturen bei Privacy Extensions
        return implode(':', array_slice($parts, 0, 4));
    }
    return $ip;
}
