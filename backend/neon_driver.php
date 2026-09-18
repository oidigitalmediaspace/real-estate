<?php

function neon_http_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $dsn = defined('NEON_DATABASE_URL') ? NEON_DATABASE_URL : getenv('NEON_DATABASE_URL');
    if (!$dsn) throw new Exception('NEON_DATABASE_URL não configurada no servidor');
    $p = parse_url($dsn);
    // HTTP SQL API usa endpoint sem -pooler
    $host = str_replace('-pooler.', '.', $p['host']);
    // Neon-Connection-String header usa a connection string sem params de TLS
    $conn = 'postgresql://' . $p['user'] . ':' . $p['pass'] . '@' . $host . $p['path'];
    $cfg = array(
        'endpoint'    => 'https://' . $host . '/sql',
        'conn_string' => $conn,
    );
    return $cfg;
}

function http_request_body($url, $method, $headers, $body = null, $followRedirects = false, $maxBytes = 0) {
    $handle = curl_init($url);
    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => $followRedirects,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    );
    // Usa os certificados confiáveis do Windows sem desativar a validação TLS.
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
        $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    if ($body !== null) $options[CURLOPT_POSTFIELDS] = $body;
    $buffer = '';
    $tooLarge = false;
    if ($maxBytes > 0) {
        $options[CURLOPT_WRITEFUNCTION] = function ($handle, $chunk) use (&$buffer, &$tooLarge, $maxBytes) {
            if (strlen($buffer) + strlen($chunk) > $maxBytes) { $tooLarge = true; return 0; }
            $buffer .= $chunk;
            return strlen($chunk);
        };
    }
    curl_setopt_array($handle, $options);
    $raw = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    if ($tooLarge) throw new InvalidArgumentException('A planilha excede o limite de 5 MB para cadastro.');
    if ($raw === false) {
        throw new Exception('Falha de conexão com o serviço. Não foi possível confirmar a operação; recarregue os dados antes de repetir uma alteração.');
    }
    if ($status < 200 || $status >= 300) {
        throw new Exception('O serviço respondeu com erro HTTP ' . $status . '.');
    }
    return $maxBytes > 0 ? $buffer : $raw;
}

function neon_http($body) {
    $c = neon_http_config();
    if (function_exists('curl_init')) {
        $raw = http_request_body($c['endpoint'], 'POST', array(
            'Content-Type: application/json',
            'Neon-Connection-String: ' . $c['conn_string']
        ), json_encode($body, JSON_UNESCAPED_UNICODE));
    } else {
        $ctx = stream_context_create(array('http' => array(
            'method'        => 'POST',
            'header'        =>
                "Content-Type: application/json\r\n" .
                "Connection: close\r\n" .
                "Neon-Connection-String: " . $c['conn_string'] . "\r\n",
            'content'       => json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout'       => 20,
            'ignore_errors' => true,
        )));
        $raw = @file_get_contents($c['endpoint'], false, $ctx);
        if ($raw === false) throw new Exception('Neon HTTP: sem resposta do servidor');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new Exception('Neon HTTP: resposta inválida');
    if (isset($data['message'])) throw new Exception('Neon: ' . $data['message']);
    return $data;
}

function neon_query($sql, $params = array()) {
    return neon_http(array('query' => $sql, 'params' => array_values($params)));
}

function neon_transaction($queries) {
    return neon_http(array('queries' => $queries));
}

