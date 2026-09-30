<?php

require_once __DIR__ . '/../fhir_client.php';


/*
====================================================
PRACTITIONER MAPPING
Menggunakan tabel:
satusehat_mapping

Alur:
dokter_id lokal
        |
        |
cek Practitioner SATUSEHAT berdasarkan IHS
        |
        |
validasi
        |
        |
simpan mapping
====================================================
*/


/**
 * Ambil Practitioner berdasarkan ID SATUSEHAT
 */
function getPractitionerBySatusehatId($satusehat_id)
{

    return fhirRequest(
        "GET",
        "Practitioner/" . trim($satusehat_id),
        null,
        []
    );

}



/**
 * Simpan ke satusehat_mapping
 */
function saveSatusehatMapping(
    $conn,
    $local_table,
    $local_key,
    $resource_type,
    $satusehat_id,
    $status,
    $response
)
{

    $json_response =
    json_encode($response);



    $stmt = $conn->prepare(
        "
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
        (?,?,?,?,?,NOW(),?)

        ON DUPLICATE KEY UPDATE

            satusehat_id=VALUES(satusehat_id),
            status=VALUES(status),
            last_sync=NOW(),
            response=VALUES(response)

        "
    );


    $stmt->bind_param(
        "sissss",
        $local_table,
        $local_key,
        $resource_type,
        $satusehat_id,
        $status,
        $json_response
    );


    return $stmt->execute();

}



/**
 * Mapping manual dokter ke Practitioner SATUSEHAT
 */
function mappingDokterPractitioner(
    $conn,
    $dokter_id,
    $satusehat_id
)
{


    // ambil dokter lokal
    $stmt=$conn->prepare(
        "
        SELECT
            id,
            nama_dokter,
            nik_dokter
        FROM dokter
        WHERE id=?
        LIMIT 1
        "
    );


    $stmt->bind_param(
        "i",
        $dokter_id
    );


    $stmt->execute();


    $dokter =
    $stmt->get_result()->fetch_assoc();



    if(!$dokter){

        return [

            "status"=>"error",

            "message"=>"Dokter tidak ditemukan."

        ];

    }



    // cek practitioner SATUSEHAT

    $result =
    getPractitionerBySatusehatId(
        $satusehat_id
    );



    if(
        empty($result['data']['id'])
    ){

        return [

            "status"=>"error",

            "message"=>"Practitioner SATUSEHAT tidak ditemukan.",

            "response"=>$result

        ];

    }



    $resource =
    $result['data'];



    $nama_satusehat =
    $resource['name'][0]['text']
    ??
    '';



    // simpan mapping
    saveSatusehatMapping(

        $conn,

        "dokter",

        $dokter_id,

        "Practitioner",

        $satusehat_id,

        "verified",

        $resource

    );



    return [

        "status"=>"success",

        "message"=>"Mapping Practitioner berhasil.",

        "dokter_id"=>$dokter_id,

        "satusehat_id"=>$satusehat_id,

        "nama_satusehat"=>$nama_satusehat

    ];

}



/**
 * Ambil mapping
 */
function getSatusehatMapping(
    $conn,
    $local_table,
    $local_key,
    $resource_type
)
{

    $stmt=$conn->prepare(
        "
        SELECT *
        FROM satusehat_mapping
        WHERE
            local_table=?
            AND local_key=?
            AND resource_type=?
        LIMIT 1
        "
    );


    $stmt->bind_param(
        "sis",
        $local_table,
        $local_key,
        $resource_type
    );


    $stmt->execute();


    return $stmt
        ->get_result()
        ->fetch_assoc();

}


?>
