<?php

require_once __DIR__ . '/../fhir_client.php';


function getPractitionerRolePayload($conn, $dokter_id)
{

    $stmt = $conn->prepare(
        "
        SELECT
            satusehat_id
        FROM dokter_satusehat_mapping
        WHERE dokter_id = ?
        LIMIT 1
        "
    );

    $stmt->bind_param("i", $dokter_id);
    $stmt->execute();

    $data = $stmt->get_result()->fetch_assoc();


    if (!$data) {

        return [
            "status" => "error",
            "message" => "Mapping Practitioner tidak ditemukan."
        ];

    }


    if (!defined('SATUSEHAT_ORGANIZATION_ID')) {

        return [
            "status" => "error",
            "message" => "Organization ID belum dikonfigurasi."
        ];

    }


    return [

        "resourceType" => "PractitionerRole",

        "active" => true,

        "practitioner" => [

            "reference" =>
            "Practitioner/" . $data['satusehat_id']

        ],

        "organization" => [

            "reference" =>
            "Organization/" . SATUSEHAT_ORGANIZATION_ID

        ],

        "code" => [

            [

                "coding" => [

                    [

                        "system" =>
                        "http://snomed.info/sct",

                        "code" =>
                        "309343006",

                        "display" =>
                        "Physician"

                    ]

                ]

            ]

        ]

    ];

}



function syncPractitionerRoleByDokterId($conn, $dokter_id)
{

    $payload =
    getPractitionerRolePayload(
        $conn,
        $dokter_id
    );


    if (($payload['status'] ?? '') === 'error') {

        return $payload;

    }


    $response = fhirRequest(

        "POST",

        "PractitionerRole",

        $payload

    );


    return [

        "status" => "success",

        "message" =>
        "PractitionerRole berhasil dikirim.",

        "payload" =>
        $payload,

        "satusehat_response" =>
        $response

    ];

}

?>
