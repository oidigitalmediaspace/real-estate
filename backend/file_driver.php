<?php

function file_pipeline_config() {
    $statuses = real_estate_statuses();
    return array(
        'key'            => 'real_estate',
        'name'           => 'Real Estate',
        'driver'         => 'file',
        'file'           => __DIR__ . '/../data.json',
        'statuses'       => $statuses,
        'column_labels'  => labels_from_statuses($statuses),
        'editable_fields'=> array('Status', 'Observação', 'Internal_Notes'),
        'supports_import'=> true,
        'supports_sync'  => false,
        'supports_delete'=> false,
    );
}

function empty_data($pipeline) {
    return array(
        'leads'        => array(),
        'last_updated' => '',
        'last_synced'  => '',
        'statuses'     => $pipeline['statuses'],
        'column_labels'=> $pipeline['column_labels']
    );
}

function read_data($pipeline) {
    $dataFile = $pipeline['file'];

    if (!file_exists($dataFile)) {
        return empty_data($pipeline);
    }

    $contents = file_get_contents($dataFile);
    if ($contents === false || trim($contents) === '') {
        return empty_data($pipeline);
    }

    $data = json_decode($contents, true);
    if (!is_array($data)) {
        json_response(array('success' => false, 'error' => basename($dataFile) . ' inválido'), 500);
    }

    if (!isset($data['leads']) || !is_array($data['leads'])) {
        $data['leads'] = array();
    }

    if (!isset($data['last_updated'])) {
        $data['last_updated'] = '';
    }

    if (!isset($data['last_synced'])) {
        $data['last_synced'] = '';
    }

    if (!isset($data['statuses']) || !is_array($data['statuses']) || empty($data['statuses'])) {
        $data['statuses'] = $pipeline['statuses'];
    }

    $defaultLabels = labels_from_statuses($data['statuses']);
    if (!isset($data['column_labels']) || !is_array($data['column_labels'])) {
        $data['column_labels'] = $defaultLabels;
    } else {
        $storedLabels = $data['column_labels'];
        $data['column_labels'] = $defaultLabels;
        foreach ($data['statuses'] as $status) {
            if (isset($storedLabels[$status]) && trim((string)$storedLabels[$status]) !== '') {
                $data['column_labels'][$status] = trim((string)$storedLabels[$status]);
            }
        }
    }

    return $data;
}

function write_data($pipeline, &$data) {
    $data['last_updated'] = gmdate('Y-m-d\TH:i:s\Z');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if ($json === false || file_put_contents($pipeline['file'], $json, LOCK_EX) === false) {
        json_response(array('success' => false, 'error' => 'Não foi possível escrever ' . basename($pipeline['file'])), 500);
    }
}

function file_add_lead($pipeline, $input, $idGenerator = 'generate_manual_lead_id') {
    $name = trim((string)($input['Name'] ?? $input['Nome'] ?? ''));
    if ($name === '') {
        json_response(array('success' => false, 'error' => 'Nome é obrigatório'), 400);
    }

    $data = read_data($pipeline);
    $existingMap = get_existing_map($data['leads']);

    $leadId = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = call_user_func($idGenerator);
        if (!isset($existingMap[$candidate])) {
            $leadId = $candidate;
            break;
        }
    }

    if ($leadId === null) {
        json_response(array('success' => false, 'error' => 'Não foi possível gerar um Lead_ID único após 5 tentativas'), 500);
    }

    $expectedFields = array(
        'Lead_ID', 'DateTime', 'Data', 'Data de Entrada', 'Carimbo de data/hora', 'Name', 'Type', 'Phone', 'Email', 'Website', 'Instagram', 'Linkedin',
        'Observação', 'Score_Fase1', 'Tier_Fase1', 'Recommended_Angle', 'IG_Followers',
        'IG_PostCount', 'IG_Posts_30d', 'IG_Last_Post_Days', 'IG_Activity', 'LI_Followers',
        'LI_Connections', 'LI_Headline', 'LI_Company', 'Website_Active', 'Website_Summary',
        'Gender', 'Brand_Score', 'Approach_Type', 'New_Tier', 'Website_Match',
        'Short_Note', 'First_Message'
    );

    $newLead = array();
    foreach ($expectedFields as $field) {
        $newLead[$field] = '';
    }

    foreach ($expectedFields as $field) {
        if (array_key_exists($field, $input)) {
            $newLead[$field] = (string)$input[$field];
        }
    }

    $newLead['Lead_ID'] = $leadId;
    $newLead['Name'] = $name;

    if (isset($input['Phone'])) {
        $newLead['Phone'] = (string)$input['Phone'];
    } elseif (isset($input['Numero'])) {
        $newLead['Phone'] = (string)$input['Numero'];
    } elseif (isset($input['Number'])) {
        $newLead['Phone'] = (string)$input['Number'];
    } elseif (isset($input['Telefone'])) {
        $newLead['Phone'] = (string)$input['Telefone'];
    }

    if (isset($input['Email'])) {
        $newLead['Email'] = (string)$input['Email'];
    }

    $newLead['Status'] = 'Novo';
    $newLead['Internal_Notes'] = '';

    $data['leads'][] = $newLead;
    write_data($pipeline, $data);

    return $newLead;
}

