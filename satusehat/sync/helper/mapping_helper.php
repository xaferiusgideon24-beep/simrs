<?php


/**
 * ==========================================================
 * GET VERIFIED PRACTITIONER
 * ==========================================================
 *
 * Mengambil IHS Practitioner yang sudah diverifikasi.
 *
 * Sumber utama:
 * satusehat_practitioner_reference
 *
 * Status yang diterima:
 * verified
 *
 */
function getVerifiedPractitionerId(
    $conn,
    $dokter_id
){

    $stmt = $conn->prepare("
        SELECT
            ihs_number,
            status
        FROM satusehat_practitioner_reference
        WHERE dokter_id=?
        LIMIT 1
    ");


    $stmt->bind_param(
        "i",
        $dokter_id
    );


    $stmt->execute();


    $result =
        $stmt->get_result();


    $row =
        $result->fetch_assoc();



    if(!$row){

        return [

            "status"=>"error",

            "ihs"=>null,

            "message"=>
            "Mapping Practitioner tidak ditemukan"

        ];

    }



    if(
        $row['status']
        !==
        'verified'
    ){

        return [

            "status"=>"error",

            "ihs"=>null,

            "message"=>
            "Mapping Practitioner belum verified. Status: "
            .$row['status']

        ];

    }



    if(
        empty($row['ihs_number'])
    ){

        return [

            "status"=>"error",

            "ihs"=>null,

            "message"=>
            "IHS Practitioner kosong"

        ];

    }



    return [

        "status"=>"success",

        "ihs"=>$row['ihs_number'],

        "message"=>
        "Practitioner verified"

    ];

}





/**
 * ==========================================================
 * GET SATUSEHAT MAPPING
 * ==========================================================
 *
 * General mapping helper.
 *
 */
function getSatusehatMapping(
    $conn,
    $local_table,
    $local_key,
    $resource_type
){

    $stmt=$conn->prepare("
        SELECT
            satusehat_id
        FROM satusehat_mapping
        WHERE local_table=?
        AND local_key=?
        AND resource_type=?
        LIMIT 1
    ");



    $local_key=(string)$local_key;



    $stmt->bind_param(
        "sss",
        $local_table,
        $local_key,
        $resource_type
    );



    $stmt->execute();



    $row =
        $stmt
        ->get_result()
        ->fetch_assoc();



    return $row['satusehat_id'] ?? null;

}





/**
 * ==========================================================
 * SAVE SATUSEHAT MAPPING
 * ==========================================================
 */
function saveSatusehatMapping(
    $conn,
    $local_table,
    $local_key,
    $resource_type,
    $satusehat_id,
    $response,
    $status='sent'
){

    $json =
        json_encode(
            $response,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );



    $local_key=(string)$local_key;



    $stmt=$conn->prepare("
        INSERT INTO satusehat_mapping
        (
            local_table,
            local_key,
            resource_type,
            satusehat_id,
            status,
            last_sync,
            response
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW(),
            ?
        )

        ON DUPLICATE KEY UPDATE

            satusehat_id=VALUES(satusehat_id),
            status=VALUES(status),
            last_sync=NOW(),
            response=VALUES(response)
    ");



    $stmt->bind_param(
        "ssssss",
        $local_table,
        $local_key,
        $resource_type,
        $satusehat_id,
        $status,
        $json
    );



    return $stmt->execute();

}

?>