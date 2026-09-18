<?php

function all_pipeline_configs() {
    $configs = array('real_estate' => file_pipeline_config());
    foreach (neon_list_pipelines() as $key => $config) {
        $configs[$key] = $config;
    }
    return $configs;
}

function current_pipeline() {
    $pipelineKey = isset($_GET['pipeline']) && $_GET['pipeline'] !== '' ? $_GET['pipeline'] : 'real_estate';

    if ($pipelineKey === 'real_estate') {
        return file_pipeline_config();
    }

    try {
        $config = neon_get_pipeline($pipelineKey);
        if ($config) return $config;
    } catch (Throwable $e) {
        json_response(array('success' => false, 'error' => 'Erro ao carregar pipeline: ' . $e->getMessage()), 500);
    }

    json_response(array('success' => false, 'error' => 'Pipeline inválido'), 400);
}

