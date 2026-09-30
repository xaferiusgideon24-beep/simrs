<?php

require_once __DIR__ . '/../../fhir_client.php';
require_once __DIR__ . '/../helper/mapping_helper.php';

define(
    "ORGANIZATION_ID",
    "06ff9913-88d6-4480-82da-d6631a80448e"
);


function syncMedicationByObatId($conn, $id_obat)
{

    $stmt = $conn->prepare("
        SELECT
            o.id,
            o.nama_obat,
            m.kfa_code,
            m.kfa_display
        FROM obat o
        JOIN obat_satusehat_mapping m
            ON o.id = m.id_obat
        WHERE o.id = ?
        LIMIT 1
    ");


    $stmt->bind_param(
        "i",
        $id_obat
    );

    $stmt->execute();

    $data = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();


    if (!$data) {

        return [
            "status" => "error",
            "message" => "Data obat atau mapping KFA tidak ditemukan"
        ];

    }


    if (
        empty($data['kfa_code']) ||
        $data['kfa_code'] == 'placeholder'
    ) {

        return [
            "status" => "error",
            "message" => "KFA obat belum valid",
            "data" => $data
        ];

    }


    $payload = [

        "resourceType" => "Medication",

        "identifier" => [
            [
                "use" => "official",
                "system" =>
                    "http://sys-ids.kemkes.go.id/medication/"
                    . ORGANIZATION_ID,
                "value" =>
                    "OBAT-" . $data['id']
            ]
        ],


        "extension" => [
            [
                "url" =>
                    "https://fhir.kemkes.go.id/r4/StructureDefinition/MedicationType",

                "valueCodeableConcept" => [
                    "coding" => [
                        [
                            "system" =>
                                "http://terminology.kemkes.go.id/CodeSystem/medication-type",
                            "code" => "NC",
                            "display" => "Non Compounded"
                        ]
                    ]
                ]
            ]
        ],


        "code" => [
            "coding" => [
                [
                    "system" =>
                        "http://sys-ids.kemkes.go.id/kfa",

                    "code" =>
                        $data['kfa_code'],

                    "display" =>
                        $data['kfa_display']
                ]
            ]
        ]

    ];


    $response = fhirRequest(
        "POST",
        "Medication",
        $payload,
        []
    );


    // Jika berhasil simpan mapping otomatis
    if (
        isset($response['http_code']) &&
        $response['http_code'] == 201 &&
        isset($response['data']['id'])
    ) {

        saveSatusehatMapping(
            $conn,
            "obat",
            $data['id'],
            "Medication",
            $response['data']['id'],
            $response['data']
        );

    }


    return [

        "status" =>
            ($response['http_code'] ?? 0) == 201
                ? "success"
                : "error",

        "payload" => $payload,

        "response" => $response

    ];

}

?>
