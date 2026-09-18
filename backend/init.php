<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (file_exists(__DIR__ . '/../config.php')) {
    require_once __DIR__ . '/../config.php';
}

define('ADMIN_EMAIL',    'comercial@oidigitalmedia.com');
define('SESSION_COOKIE', 'oi_session');
define('REMEMBER_TTL',   30 * 24 * 60 * 60);  // 30 dias
define('SHORT_TTL',      12 * 60 * 60);        // 12h (sessão curta / "não lembrar")
define('MAX_FAILED',     5);                   // tentativas antes de bloquear
define('LOCK_MINUTES',   15);                  // duração do bloqueio

