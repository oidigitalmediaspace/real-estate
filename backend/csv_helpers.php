<?php

function normalize_header($value) {
    return trim((string)$value);
}

function get_existing_map($leads) {
    $map = array();
    foreach ($leads as $index => $lead) {
        if (isset($lead['Lead_ID']) && $lead['Lead_ID'] !== '') {
            $map[$lead['Lead_ID']] = $index;
        }
    }
    return $map;
}

function read_csv_headers($handle) {
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    if ($headers === false) {
        return false;
    }

    $headers = array_map('normalize_header', $headers);
    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
    }

    return $headers;
}

function row_to_assoc($headers, $row) {
    $lead = array();
    foreach ($headers as $index => $header) {
        if ($header === '') {
            continue;
        }
        $lead[$header] = isset($row[$index]) ? (string)$row[$index] : '';
    }
    return $lead;
}

function fetch_csv($url) {
    if (function_exists('curl_init')) {
        $csv = http_request_body($url, 'GET', array('User-Agent: OiDigitalMediaCRM/1.0'), null, true);
        if (trim($csv) === '') {
            json_response(array('success' => false, 'error' => 'A planilha publicada está vazia'), 502);
        }
        return $csv;
    }
    $context = stream_context_create(array(
        'http' => array(
            'timeout' => 20,
            'header'  => "User-Agent: OiDigitalMediaCRM/1.0\r\nConnection: close\r\n"
        )
    ));

    $csv = @file_get_contents($url, false, $context);
    if ($csv === false || trim($csv) === '') {
        json_response(array('success' => false, 'error' => 'Não foi possível baixar o CSV publicado'), 502);
    }

    return $csv;
}

function open_csv_string($csv) {
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        json_response(array('success' => false, 'error' => 'Não foi possível preparar o CSV'), 500);
    }

    fwrite($handle, $csv);
    rewind($handle);
    return $handle;
}

function import_real_estate($pipeline) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(array('success' => false, 'error' => 'Método inválido'), 405);
    }

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        json_response(array('success' => false, 'error' => 'Arquivo CSV obrigatório'), 400);
    }

    $expectedFields = array(
        'Lead_ID', 'DateTime', 'Data', 'Data de Entrada', 'Carimbo de data/hora', 'Name', 'Type', 'Phone', 'Email', 'Website', 'Instagram', 'Linkedin',
        'Observação', 'Score_Fase1', 'Tier_Fase1', 'Recommended_Angle', 'IG_Followers',
        'IG_PostCount', 'IG_Posts_30d', 'IG_Last_Post_Days', 'IG_Activity', 'LI_Followers',
        'LI_Connections', 'LI_Headline', 'LI_Company', 'Website_Active', 'Website_Summary',
        'Gender', 'Brand_Score', 'Approach_Type', 'New_Tier', 'Website_Match',
        'Short_Note', 'First_Message'
    );

    $handle = fopen($_FILES['csv']['tmp_name'], 'r');
    if ($handle === false) {
        json_response(array('success' => false, 'error' => 'Não foi possível ler o CSV'), 400);
    }

    $headers = read_csv_headers($handle);
    if ($headers === false) {
        fclose($handle);
        json_response(array('success' => false, 'error' => 'CSV vazio ou sem headers'), 400);
    }

    $data     = read_data($pipeline);
    $existingMap = get_existing_map($data['leads']);
    $imported = 0;
    $updated  = 0;
    $new      = 0;
    $skipped  = 0;

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (count($row) === 1 && trim($row[0]) === '') {
            continue;
        }

        $csvLead = array();
        foreach ($expectedFields as $field) {
            $index = array_search($field, $headers, true);
            $csvLead[$field] = ($index !== false && isset($row[$index])) ? (string)$row[$index] : '';
        }

        if ($csvLead['Lead_ID'] === '') {
            $skipped++;
            continue;
        }

        $imported++;
        if (isset($existingMap[$csvLead['Lead_ID']])) {
            $leadIndex  = $existingMap[$csvLead['Lead_ID']];
            $existingLead = $data['leads'][$leadIndex];
            $csvLead['Status']        = isset($existingLead['Status'])        ? $existingLead['Status']        : 'Novo';
            $csvLead['Observação']    = isset($existingLead['Observação'])    ? $existingLead['Observação']    : $csvLead['Observação'];
            $csvLead['Internal_Notes']= isset($existingLead['Internal_Notes'])? $existingLead['Internal_Notes']: '';
            $data['leads'][$leadIndex] = $csvLead;
            $updated++;
        } else {
            $csvLead['Status']         = 'Novo';
            $csvLead['Internal_Notes'] = '';
            $data['leads'][]           = $csvLead;
            $existingMap[$csvLead['Lead_ID']] = count($data['leads']) - 1;
            $new++;
        }
    }
    fclose($handle);

    write_data($pipeline, $data);
    json_response(array(
        'success'  => true,
        'imported' => $imported,
        'updated'  => $updated,
        'new'      => $new,
        'skipped'  => $skipped
    ));
}

