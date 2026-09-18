<?php

function auth_current_user() {
    static $cached = false;                 // false = ainda não resolvido; null = anônimo
    if ($cached !== false) return $cached;
    $token = isset($_COOKIE[SESSION_COOKIE]) ? $_COOKIE[SESSION_COOKIE] : '';
    if ($token === '') return $cached = null;
    $r = neon_query(
        'SELECT u.id, u.email, u.role, u.allowed_pipelines, s.expires_at
           FROM sessions s JOIN users u ON u.id = s.user_id
          WHERE s.token = $1', array($token));
    if (empty($r['rows'])) return $cached = null;
    $row = $r['rows'][0];
    if (strtotime($row['expires_at']) < time()) {              // expirada → limpa
        neon_query('DELETE FROM sessions WHERE token = $1', array($token));
        return $cached = null;
    }
    return $cached = $row;
}

function require_auth() {
    if (auth_current_user() === null) {
        json_response(array('success' => false, 'error' => 'Não autenticado',
                            'code' => 'unauthenticated'), 401);
    }
}

function auth_set_cookie($token, $remember) {
    setcookie(SESSION_COOKIE, $token, array(
        'expires'  => $remember ? time() + REMEMBER_TTL : 0,   // 0 = cookie de sessão
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
}

function auth_needs_setup() {
    $r = neon_query('SELECT password_hash FROM users WHERE email = $1', array(ADMIN_EMAIL));
    if (empty($r['rows'])) return false;                        // migração não rodou
    return $r['rows'][0]['password_hash'] === null;
}

