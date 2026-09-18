<?php

function published_sheet_url($url) {
    $parts = parse_url(trim((string)$url));
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== 'docs.google.com'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || !preg_match('~^/spreadsheets/d/e/([A-Za-z0-9_-]+)/pub(?:html)?$~', $parts['path'] ?? '', $match)) {
        throw new InvalidArgumentException('Use o link do Google Sheets em Arquivo > Compartilhar > Publicar na Web.');
    }
    parse_str($parts['query'] ?? '', $query);
    $params = array('output' => 'csv');
    if (isset($query['gid'])) {
        if (!is_string($query['gid']) || !ctype_digit($query['gid'])) throw new InvalidArgumentException('A aba indicada no link é inválida.');
        $params['gid'] = $query['gid'];
        $params['single'] = 'true';
    }
    return 'https://docs.google.com/spreadsheets/d/e/' . $match[1] . '/pub?' . http_build_query($params);
}

function published_workbook_url($url) {
    return explode('?', published_sheet_url($url))[0] . '?output=csv';
}

function discover_published_tabs($html, $source) {
    $base = explode('?', published_workbook_url($source))[0];
    preg_match_all('/items\.push\(\{name:\s*("(?:\\\\.|[^"\\\\])*").*?\bgid:\s*"(\d+)"/s', $html, $matches, PREG_SET_ORDER);
    $tabs = array();
    foreach ($matches as $match) {
        $name = json_decode($match[1], true);
        $gid = $match[2];
        $tabs[$gid] = array('name' => is_string($name) ? $name : 'Aba ' . $gid, 'url' => $base . '?output=csv&gid=' . $gid . '&single=true');
    }
    // Algumas publicações usam um menu HTML em vez de items.push.
    if (!$tabs) {
        preg_match_all('/<li\b[^>]*id=["\']sheet-button-(\d+)["\'][^>]*>(.*?)<\/li>/s', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) $tabs[$match[1]] = array('name' => trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES, 'UTF-8')), 'url' => $base . '?output=csv&gid=' . $match[1] . '&single=true');
    }
    if (!$tabs) throw new InvalidArgumentException('Não foi possível identificar as abas publicadas. Publique o documento inteiro em “Publicar na Web” e tente novamente.');
    if (count($tabs) > 20) throw new InvalidArgumentException('Limite de 20 abas publicadas por planilha.');
    return array_values($tabs);
}

function merge_published_tabs($tabs) {
    $rows = array(); $headers = array(); $duplicates = 0;
    foreach ($tabs as $tab) {
        $parsed = $tab['parsed'];
        $duplicates += $parsed['duplicates'];
        foreach ($parsed['headers'] as $header) $headers[$header] = true;
        foreach ($parsed['rows'] as $row) {
            $id = $row['Lead_ID'];
            if (!isset($rows[$id])) $rows[$id] = array('Lead_ID' => $id);
            foreach ($row as $field => $value) {
                if ($field === 'Lead_ID') continue;
                if (!isset($rows[$id][$field]) || trim($rows[$id][$field]) === '') {
                    $rows[$id][$field] = $value;
                } elseif (trim($value) !== '' && $rows[$id][$field] !== $value) {
                    // Não apaga respostas diferentes que usam o mesmo título em outras abas.
                    $alias = $tab['name'] . ' — ' . $field;
                    $rows[$id][$alias] = $value;
                    $headers[$alias] = true;
                }
            }
            if (count($rows) > 5000) throw new InvalidArgumentException('Limite de 5.000 leads por planilha.');
        }
    }
    if (!$rows) throw new InvalidArgumentException('As abas publicadas ainda não possuem leads.');
    return array('headers' => array_keys($headers), 'rows' => array_values($rows), 'duplicates' => $duplicates);
}

function load_published_workbook($url) {
    $source = published_workbook_url($url);
    $html = http_request_body(explode('?', $source)[0] . 'html', 'GET', array(), null, true, 5 * 1024 * 1024);
    $tabs = discover_published_tabs($html, $source);
    $totalBytes = 0;
    foreach ($tabs as &$tab) {
        $csv = http_request_body($tab['url'], 'GET', array(), null, true, 5 * 1024 * 1024);
        $totalBytes += strlen($csv);
        if ($totalBytes > 20 * 1024 * 1024) throw new InvalidArgumentException('O conjunto de abas excede 20 MB.');
        try { $tab['parsed'] = parse_new_sheet($csv, true); }
        catch (InvalidArgumentException $e) { throw new InvalidArgumentException('Aba “' . $tab['name'] . '”: ' . $e->getMessage()); }
    }
    unset($tab);
    $parsed = merge_published_tabs($tabs);
    $parsed['sheet_urls'] = array_column($tabs, 'url');
    $parsed['sheet_names'] = array_column($tabs, 'name');
    $parsed['workbook_url'] = $source;
    return $parsed;
}

function parse_new_sheet($csv, $allowEmpty = false) {
    if (strlen($csv) > 5 * 1024 * 1024) throw new InvalidArgumentException('A planilha excede o limite de 5 MB.');
    $handle = open_csv_string($csv);
    try {
        $headers = read_csv_headers($handle);
        if (!$headers || !in_array('Lead_ID', $headers, true)) {
            throw new InvalidArgumentException('A primeira linha precisa conter a coluna Lead_ID. Publique a aba correta como CSV separado por vírgulas.');
        }
        if (count(array_unique($headers)) !== count($headers) || in_array('', $headers, true)) {
            throw new InvalidArgumentException('As colunas precisam ter nomes preenchidos e sem repetição.');
        }
        $rows = array();
        $duplicates = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (!array_filter($row, fn($value) => trim((string)$value) !== '')) continue;
            if (count($row) !== count($headers)) throw new InvalidArgumentException('Há uma linha com quantidade de campos diferente do cabeçalho.');
            $data = row_to_assoc($headers, $row);
            $id = trim($data['Lead_ID']);
            if ($id === '') throw new InvalidArgumentException('Cada linha com dados precisa de um Lead_ID preenchido.');
            if (isset($rows[$id])) $duplicates++;
            $data['Lead_ID'] = $id;
            $rows[$id] = $data;
            if (count($rows) > 5000) throw new InvalidArgumentException('Limite de 5.000 leads por cadastro.');
        }
        if (!$rows && !$allowEmpty) throw new InvalidArgumentException('A planilha ainda não possui leads para importar.');
        return array('headers' => $headers, 'rows' => array_values($rows), 'duplicates' => $duplicates);
    } finally {
        fclose($handle);
    }
}

