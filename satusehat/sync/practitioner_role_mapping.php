<?php

require_once __DIR__ . '/../fhir_client.php';


function savePractitionerRoleMapping(
    $conn,
    $dokter_id,
    $practitioner_role_id,
    $response
){

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
            'dokter',
            ?,
            'PractitionerRole',
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
        $dokter_id,
        $practitioner_role_id,
        $json
    );


    return $stmt->execute();

}



function mappingPractitionerRole(
    $conn,
    $dokter_id,
    $practitioner_role_id,
    $response
){

    $save =
    savePractitionerRoleMapping(
        $conn,
        $dokter_id,
        $practitioner_role_id,
        $response
    );


    if(!$save){

        return [
            "status"=>"error",
            "message"=>"Gagal simpan PractitionerRole"
        ];

    }


    return [

        "status"=>"success",

        "message"=>"Mapping PractitionerRole berhasil",

        "dokter_id"=>$dokter_id,

        "practitioner_role_id"=>$practitioner_role_id

    ];

}


?>
