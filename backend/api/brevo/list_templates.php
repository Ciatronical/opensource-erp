<?php

function listTemplates() {
    $response = [
        'success' => true,
        'payload' => [
            'templates' => [],
        ],
    ];

    $api = new BrevoApi();
    try {
        $templates = $api->listTemplates();
        $response['payload']['templates'] = $templates;
    } catch (Exception $e) {
        throw new ApiError('BREVO_API_ERROR', 'Error fetching templates from Brevo: ' . $e->getMessage());
    }

    echo json_encode($response);
}