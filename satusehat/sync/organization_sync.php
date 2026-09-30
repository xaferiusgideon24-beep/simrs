<?php

require_once __DIR__ . '/../fhir_client.php';


/**
 * Mengambil Organization SATUSEHAT berdasarkan ID.
 *
 * Contoh ID:
 * 10000001
 *
 * Request:
 * GET /Organization/{id}
 */
function getOrganizationSatusehatById($organizationId)
{
    $organizationId = trim($organizationId);

    if ($organizationId === '') {

        return [
            'status' => 'error',
            'message' => 'Organization ID wajib diisi.'
        ];
    }


    $result = fhirRequest(
        'GET',
        'Organization/' . rawurlencode($organizationId)
    );


    $httpCode =
        $result['http_code'] ?? 0;


    // =========================================
    // BERHASIL
    // =========================================

    if ($httpCode === 200) {

        $resource =
            $result['data'] ?? null;


        if (
            is_array($resource) &&
            isset($resource['resourceType']) &&
            $resource['resourceType'] === 'Organization'
        ) {

            return [
                'status' => 'found',
                'message' => 'Organization ditemukan di SATUSEHAT.',
                'satusehat_id' => $resource['id'] ?? $organizationId,
                'resource' => $resource
            ];
        }


        return [
            'status' => 'error',
            'message' => 'Response SATUSEHAT bukan resource Organization.',
            'satusehat_response' => $result
        ];
    }


    // =========================================
    // TIDAK DITEMUKAN
    // =========================================

    if ($httpCode === 404) {

        return [
            'status' => 'not_found',
            'message' => 'Organization tidak ditemukan di SATUSEHAT.',
            'organization_id' => $organizationId
        ];
    }


    // =========================================
    // ERROR API
    // =========================================

    return [
        'status' => 'error',
        'message' => 'Gagal mengambil Organization dari SATUSEHAT.',
        'http_code' => $httpCode,
        'satusehat_response' => $result
    ];
}

?>
