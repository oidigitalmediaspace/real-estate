<?php

function sheet_display_config($parsed) {
    $display = array('last_synced' => gmdate('Y-m-d\TH:i:s\Z'));
    if (isset($parsed['workbook_url'])) {
        $display['published_workbook_url'] = $parsed['workbook_url'];
        $display['sheet_names'] = $parsed['sheet_names'];
    }
    foreach (array('Number' => 'Número', 'Padronized Number' => 'Número Padronizado', 'DateTime' => 'Data de Entrada') as $target => $field) {
        if (!in_array($target, $parsed['headers'], true) && in_array($field, $parsed['headers'], true)) $display['field_map'][$target] = $field;
    }
    if (!in_array('Score AI', $parsed['headers'], true)) {
        foreach (array('Tier', 'Score', 'New_Tier') as $field) {
            if (in_array($field, $parsed['headers'], true)) { $display['field_map']['Score AI'] = $field; break; }
        }
    }
    return $display;
}

function new_sheet_queries($key, $name, $url, $parsed) {
    $statuses = real_estate_statuses();
    $display = sheet_display_config($parsed);
    return array(
        array('query' => 'INSERT INTO pipelines (key, name, statuses, board_statuses, column_labels, sheet_urls, display) VALUES ($1,$2,$3::jsonb,$3::jsonb,$4::jsonb,$5::jsonb,$6::jsonb)',
            'params' => array($key, $name, json_encode($statuses), json_encode(labels_from_statuses($statuses)), json_encode($parsed['sheet_urls'] ?? array($url)), json_encode($display))),
        array('query' => "INSERT INTO leads (pipeline_key, lead_id, data, status, internal_notes) SELECT \$1, item->>'Lead_ID', item - 'Lead_ID', 'Novo', '' FROM jsonb_array_elements(\$2::jsonb) AS item",
            'params' => array($key, json_encode($parsed['rows'], JSON_UNESCAPED_UNICODE)))
    );
}

function neon_row_to_pipeline($row) {
    // HTTP API retorna JSONB como array nativo; PDO retornaria string — aceita os dois
    $d = function($v) { return is_string($v) ? json_decode($v, true) : $v; };
    $cl = $d($row['column_labels']);
    return array(
        'key'             => $row['key'],
        'name'            => $row['name'],
        'driver'          => 'neon',
        'statuses'        => $d($row['statuses'])        ?: array(),
        'board_statuses'  => isset($row['board_statuses']) && $row['board_statuses'] !== null ? $d($row['board_statuses']) : null,
        'editable_fields' => $d($row['editable_fields']) ?: array('Status', 'Internal_Notes'),
        'column_labels'   => is_array($cl) && !empty($cl) ? $cl : array(),
        'sheet_urls'      => $d($row['sheet_urls'])      ?: array(),
        'display'         => $d($row['display'])         ?: array(),
        'supports_import' => false,
        'supports_sync'   => (bool)$row['supports_sync'],
        'supports_delete' => (bool)$row['supports_delete'],
    );
}

function neon_list_pipelines() {
    $result = neon_query('SELECT * FROM pipelines ORDER BY created_at');
    $out = array();
    foreach ($result['rows'] as $row) {
        $out[$row['key']] = neon_row_to_pipeline($row);
    }
    return $out;
}

function neon_get_pipeline($key) {
    $result = neon_query('SELECT * FROM pipelines WHERE key = $1', array($key));
    if (empty($result['rows'])) return null;
    return neon_row_to_pipeline($result['rows'][0]);
}

function neon_flatten_lead($row) {
    $data = is_string($row['data']) ? json_decode($row['data'], true) : $row['data'];
    if (!is_array($data)) $data = array();
    return array_merge(
        array('Lead_ID' => $row['lead_id']),
        $data,
        array('Status' => $row['status'], 'Internal_Notes' => $row['internal_notes'])
    );
}

function neon_read_leads($pipeline) {
    $result = neon_query(
        'SELECT lead_id, data, status, internal_notes FROM leads WHERE pipeline_key = $1 ORDER BY created_at',
        array($pipeline['key'])
    );
    $leads = array();
    foreach ($result['rows'] as $row) {
        $leads[] = neon_flatten_lead($row);
    }
    return $leads;
}

function neon_update_lead($pipeline, $input) {
    $result = neon_query(
        'SELECT status, internal_notes FROM leads WHERE pipeline_key = $1 AND lead_id = $2',
        array($pipeline['key'], $input['Lead_ID'])
    );
    if (empty($result['rows'])) {
        json_response(array('success' => false, 'error' => 'Lead não encontrado'), 404);
    }
    $existing  = $result['rows'][0];
    $newStatus = array_key_exists('Status', $input)         ? (string)$input['Status']        : $existing['status'];
    $newNotes  = array_key_exists('Internal_Notes', $input) ? (string)$input['Internal_Notes'] : $existing['internal_notes'];
    neon_query(
        'UPDATE leads SET status = $1, internal_notes = $2 WHERE pipeline_key = $3 AND lead_id = $4',
        array($newStatus, $newNotes, $pipeline['key'], $input['Lead_ID'])
    );
}

function neon_delete_pipeline($key) {
    if ($key === '' || $key === 'real_estate') throw new InvalidArgumentException('O pipeline CSV manual é protegido.');
    return neon_transaction(array(
        array('query' => 'DELETE FROM leads WHERE pipeline_key = $1', 'params' => array($key)),
        array('query' => 'DELETE FROM pipelines WHERE key = $1', 'params' => array($key))
    ));
}

function neon_delete_lead($pipeline, $leadId) {
    $result = neon_query(
        'DELETE FROM leads WHERE pipeline_key = $1 AND lead_id = $2',
        array($pipeline['key'], $leadId)
    );
    if (($result['rowCount'] ?? 0) === 0) {
        json_response(array('success' => false, 'error' => 'Lead não encontrado'), 404);
    }
}

function neon_update_pipeline_columns($pipeline, $statuses, $labels, $moveFrom = array(), $moveTo = null) {
    $queries = array();
    if (!empty($moveFrom) && $moveTo !== null) {
        $queries[] = array(
            'query' => 'UPDATE leads
                           SET status = $1, updated_at = now()
                         WHERE pipeline_key = $2
                           AND status IN (SELECT jsonb_array_elements_text($3::jsonb))',
            'params' => array(
                $moveTo,
                $pipeline['key'],
                json_encode(array_values($moveFrom), JSON_UNESCAPED_UNICODE)
            )
        );
    }
    $queries[] = array(
        'query' => 'UPDATE pipelines
                      SET statuses = $1::jsonb,
                          board_statuses = CASE WHEN board_statuses IS NULL THEN NULL ELSE $1::jsonb END,
                          column_labels = $2::jsonb,
                          updated_at = now()
                    WHERE key = $3',
        'params' => array(
            json_encode(array_values($statuses), JSON_UNESCAPED_UNICODE),
            json_encode($labels, JSON_UNESCAPED_UNICODE),
            $pipeline['key']
        )
    );
    neon_transaction($queries);
    return array('statuses' => array_values($statuses), 'column_labels' => $labels);
}

function neon_sync_pipeline($pipeline, $loaded = null, $returnResult = false) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(array('success' => false, 'error' => 'Método inválido'), 405);
    }

    $sheetUrls = $pipeline['sheet_urls'];
    if (empty($sheetUrls)) {
        json_response(array('success' => false, 'error' => 'Nenhuma sheet_url configurada para este pipeline'), 400);
    }

    if ($loaded === null) {
        $workbooks = array();
        foreach ($sheetUrls as $sheetUrl) {
            try { $workbooks[published_workbook_url($sheetUrl)] = true; }
            catch (InvalidArgumentException $e) { $workbooks = array(); break; }
        }
        if (count($workbooks) === 1) $loaded = load_published_workbook(array_key_first($workbooks));
    }
    if ($loaded !== null) {
        $mergedByLeadId = array_column($loaded['rows'], null, 'Lead_ID');
        $sheetUrls = $loaded['sheet_urls'];
    } else {
        // Merge todas as abas por Lead_ID (LEFT JOIN: aba contato = base)
        $mergedByLeadId = array();
        foreach ($sheetUrls as $urlIndex => $url) {
            $csv    = fetch_csv($url);
            $handle = open_csv_string($csv);
            $headers = read_csv_headers($handle);
            if ($headers === false) {
                fclose($handle);
                json_response(array('success' => false, 'error' => 'CSV da aba ' . ($urlIndex + 1) . ' vazio ou sem headers'), 400);
            }

            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                if (count($row) === 1 && trim($row[0]) === '') continue;

                $csvRow = row_to_assoc($headers, $row);
                $leadId = isset($csvRow['Lead_ID']) ? trim((string)$csvRow['Lead_ID']) : '';
                if ($leadId === '') continue;

                if (!isset($mergedByLeadId[$leadId])) {
                    $mergedByLeadId[$leadId] = array('Lead_ID' => $leadId);
                }
                foreach ($csvRow as $k => $v) {
                    if ($k !== 'Lead_ID') $mergedByLeadId[$leadId][$k] = $v;
                }
            }
            fclose($handle);
        }

    }

    $pipelineKey = $pipeline['key'];

    $existingResult = neon_query('SELECT lead_id FROM leads WHERE pipeline_key = $1', array($pipelineKey));
    $existingIds = array();
    foreach ($existingResult['rows'] as $row) {
        $existingIds[$row['lead_id']] = true;
    }

    $queries  = array();
    $imported = 0; $new = 0; $updated = 0;

    foreach ($mergedByLeadId as $leadId => $mergedRow) {
        $data = $mergedRow;
        unset($data['Lead_ID']);
        $queries[] = array(
            'query'  => "INSERT INTO leads (pipeline_key, lead_id, data, status, internal_notes)
                         VALUES (\$1, \$2, \$3::jsonb, 'Novo', '')
                         ON CONFLICT (pipeline_key, lead_id)
                         DO UPDATE SET data = excluded.data",
            'params' => array($pipelineKey, $leadId, json_encode($data, JSON_UNESCAPED_UNICODE)),
        );
        $imported++;
        if (isset($existingIds[$leadId])) { $updated++; } else { $new++; }
    }

    $display = $pipeline['display'] ?? array();
    if ($loaded !== null) {
        $detected = sheet_display_config($loaded);
        $detected['field_map'] = array_replace($detected['field_map'] ?? array(), $display['field_map'] ?? array());
        $display = array_replace($display, $detected);
    }
    $display['last_synced'] = gmdate('Y-m-d\\TH:i:s\\Z');
    $queries[] = array(
        'query' => 'UPDATE pipelines SET display = COALESCE(display, \'{}\'::jsonb) || $2::jsonb, sheet_urls = $3::jsonb, updated_at = now() WHERE key = $1',
        'params' => array($pipelineKey, json_encode($display, JSON_UNESCAPED_UNICODE), json_encode($sheetUrls))
    );
    neon_transaction($queries);

    $leads        = neon_read_leads($pipeline);
    $columnLabels = !empty($pipeline['column_labels'])
        ? $pipeline['column_labels']
        : labels_from_statuses($pipeline['statuses']);

    $payload = array(
        'display' => $display,
        'sheet_count' => count($sheetUrls),
        'success'      => true,
        'imported'     => $imported,
        'updated'      => $updated,
        'new'          => $new,
        'skipped'      => 0,
        'leads'        => $leads,
        'column_labels'=> $columnLabels,
        'last_updated' => gmdate('Y-m-d\TH:i:s\Z'),
        'last_synced'  => gmdate('Y-m-d\TH:i:s\Z'),
        'statuses'     => $pipeline['statuses'],
    );
    if ($returnResult) return $payload;
    json_response($payload);
}

function neon_add_lead($pipeline, $input, $idGenerator = 'generate_manual_lead_id') {
    $name = trim((string)($input['Name'] ?? $input['Nome'] ?? ''));
    if ($name === '') {
        json_response(array('success' => false, 'error' => 'Nome é obrigatório'), 400);
    }

    $leadData = $input;
    unset($leadData['Lead_ID'], $leadData['Status'], $leadData['Internal_Notes']);

    if (!isset($leadData['Nome']) && !isset($leadData['Name'])) {
        $leadData['Nome'] = $name;
    }

    $pipelineKey = $pipeline['key'];
    $leadId = null;

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = call_user_func($idGenerator);

        $check = neon_query(
            'SELECT 1 FROM leads WHERE pipeline_key = $1 AND lead_id = $2',
            array($pipelineKey, $candidate)
        );

        if (empty($check['rows'])) {
            $insert = neon_query(
                'INSERT INTO leads (pipeline_key, lead_id, data, status, internal_notes)
                 VALUES ($1, $2, $3::jsonb, \'Novo\', \'\')
                 ON CONFLICT (pipeline_key, lead_id) DO NOTHING',
                array($pipelineKey, $candidate, json_encode($leadData, JSON_UNESCAPED_UNICODE))
            );

            if (!isset($insert['rowCount']) || (int)$insert['rowCount'] > 0) {
                $leadId = $candidate;
                break;
            }
        }
    }

    if ($leadId === null) {
        json_response(array('success' => false, 'error' => 'Não foi possível gerar um Lead_ID único após 5 tentativas'), 500);
    }

    $row = array(
        'lead_id'        => $leadId,
        'data'           => $leadData,
        'status'         => 'Novo',
        'internal_notes' => ''
    );

    return neon_flatten_lead($row);
}

