<?php
/*
====================================================
SATUSEHAT PATIENT MAPPING
Menggunakan tabel:
satusehat_mapping

Alur:
pasien SIMRS
    |
    |
cek Patient SATUSEHAT
    |
    |
simpan ID SATUSEHAT
====================================================
*/


function getPatientBySatusehatId($satusehat_id)
{
    return fhirRequest(
        "GET",
        "Patient/" . trim($satusehat_id),
        null,
        []
    );
}


/*
Cari Patient berdasarkan NIK
*/
function searchPatientByNik($nik)
{
    $identifier =
        "https://fhir.kemkes.go.id/id/nik|" . $nik;

    return fhirRequest(
        "GET",
        "Patient?identifier=" . urlencode($identifier),
        null,
        []
    );
}


/*
Simpan mapping Patient ke tabel umum
*/
function savePatientMapping(
    $conn,
    $pasien_id,
    $satusehat_id,
    $response
)
{
    $json = json_encode($response);

    $stmt = $conn->prepare("
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
            'pasien',
            ?,
            'Patient',
            ?,
            'verified',
            NOW(),
            ?
        )

        ON DUPLICATE KEY UPDATE
            satusehat_id=VALUES(satusehat_id),
            status='verified',
            last_sync=NOW(),
            response=VALUES(response)
    ");

    $stmt->bind_param(
        "iss",
        $pasien_id,
        $satusehat_id,
        $json
    );

    return $stmt->execute();
}


/*
Mapping manual menggunakan ID Patient SATUSEHAT
*/
function mappingPatientManual(
    $conn,
    $pasien_id,
    $satusehat_id
)
{

    $stmt = $conn->prepare("
        SELECT
            id,
            nik,
            nama_lengkap
        FROM pasien
        WHERE id=?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $pasien_id
    );

    $stmt->execute();

    $pasien =
        $stmt->get_result()->fetch_assoc();


    if(!$pasien){

        return [
            "status"=>"error",
            "message"=>"Data pasien SIMRS tidak ditemukan"
        ];

    }


    $result =
        getPatientBySatusehatId($satusehat_id);


    if(empty($result['data']['id'])){

        return [
            "status"=>"error",
            "message"=>"Patient SATUSEHAT tidak ditemukan",
            "response"=>$result
        ];

    }


    $patient =
        $result['data'];


    savePatientMapping(
        $conn,
        $pasien_id,
        $satusehat_id,
        $patient
    );


    return [

        "status"=>"success",
        "message"=>"Mapping Patient berhasil",

        "pasien_id"=>$pasien_id,

        "satusehat_id"=>$satusehat_id,

        "nama_satusehat"=>
            $patient['name'][0]['text'] ?? ''

    ];

}


?>
