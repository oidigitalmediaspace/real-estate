<?php

function real_estate_statuses() {
    return array('Novo', 'Em andamento', 'Fechado');
}

function labels_from_statuses($statuses) {
    $labels = array();
    foreach ($statuses as $status) {
        $labels[$status] = $status;
    }
    return $labels;
}

function json_response($payload, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function generate_uuid_v4() {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function generate_manual_lead_id() {
    return 'manual-' . generate_uuid_v4();
}

