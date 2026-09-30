<?php

require_once __DIR__ . '/../../fhir_client.php';
require_once __DIR__ . '/../../resources/patient.php';


function patientValidNik($nik)
{
    return preg_match('/^[0-9]{16}$/', trim((string)$nik)) === 1;
}



function getLocalPatientByNik($conn, $nik)
{
    $stmt = $conn->prepare("
        SELECT *
        FROM pasien
        WHERE nik=?
        LIMIT 1
    ");

    $stmt->bind_param(
        "s",
        $nik
    );

    $stmt->execute();

    return $stmt
        ->get_result()
        ->fetch_assoc();
}




function getPatientMapping($conn,$nik)
{
    $stmt=$conn->prepare("
        SELECT *
        FROM satusehat_mapping
        WHERE local_table='pasien'
        AND resource_type='Patient'
        AND local_key=?
        LIMIT 1
    ");

    $stmt->bind_param(
        "s",
        $nik
    );

    $stmt->execute();

    return $stmt
        ->get_result()
        ->fetch_assoc();
}




function savePatientMapping(
    $conn,
    $nik,
    $ihs,
    $response
){

    $json=json_encode(
        $response,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );


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
            'pasien',
            ?,
            'Patient',
            ?,
            'sent',
            NOW(),
            ?
        )

        ON DUPLICATE KEY UPDATE

        satusehat_id=VALUES(satusehat_id),
        status='sent',
        last_sync=NOW(),
        response=VALUES(response)
    ");


    $stmt->bind_param(
        "sss",
        $nik,
        $ihs,
        $json
    );


    return $stmt->execute();
}





function searchPatientByIdentity($pasien)
{

    $name=urlencode(
        $pasien['nama_lengkap']
    );


    $birth=$pasien['tgl_lahir'];


    $gender =
        $pasien['jenis_kelamin']=="Laki-laki"
        ? "male"
        : "female";


    $endpoint =
        "Patient?name={$name}"
        ."&birthdate={$birth}"
        ."&gender={$gender}";


    return fhirRequest(
        "GET",
        $endpoint,
        [],
        []
    );
}





function syncPatientByNik($conn,$nik)
{

    $nik=trim($nik);


    if(!patientValidNik($nik)){

        return [
            "status"=>"error",
            "message"=>"NIK tidak valid"
        ];

    }



    $pasien=
        getLocalPatientByNik(
            $conn,
            $nik
        );


    if(!$pasien){

        return [
            "status"=>"error",
            "message"=>"Pasien lokal tidak ditemukan"
        ];

    }




    $mapping=
        getPatientMapping(
            $conn,
            $nik
        );


    if($mapping){

        return [
            "status"=>"already_sync",
            "satusehat_id"=>$mapping['satusehat_id']
        ];

    }




    /*
       1. Cari berdasarkan NIK
    */

    $searchNik=
        searchPatientByNIK($nik);



    if(
        ($searchNik['http_code'] ?? 0)==200
        &&
        !empty($searchNik['data']['entry'])
    ){

        $id=
        $searchNik['data']['entry'][0]['resource']['id'];


        savePatientMapping(
            $conn,
            $nik,
            $id,
            $searchNik
        );


        return [
            "status"=>"existing_patient",
            "message"=>"Patient ditemukan berdasarkan NIK",
            "satusehat_id"=>$id
        ];

    }




    /*
       2. Create Patient
    */


    $payload=
        buildPatientFHIR($pasien);



    $response=
        fhirRequest(
            "POST",
            "Patient",
            $payload,
            []
        );



    $code=
        $response['http_code'] ?? 0;


    $id=
        $response['data']['id'] ?? null;



    if(
        in_array($code,[200,201])
        &&
        $id
    ){

        savePatientMapping(
            $conn,
            $nik,
            $id,
            $response
        );


        return [
            "status"=>"created",
            "satusehat_id"=>$id
        ];

    }





    /*
       3. Duplicate Patient
       Search identity
    */


    $errorText =
        $response['data']['issue'][0]['details']['text']
        ??
        '';



    if(
    str_contains(
        strtolower($errorText),
        "patient data exists"
    )
){

    $identity =
        searchPatientByIdentity($pasien);



    if(
        ($identity['http_code'] ?? 0) == 200
    ){

        $entries =
            $identity['data']['entry'] ?? [];



        foreach($entries as $entry){

            $resource =
                $entry['resource'] ?? [];



            if(
                ($resource['resourceType'] ?? '')
                === 'Patient'
                &&
                !empty($resource['id'])
            ){

                savePatientMapping(
                    $conn,
                    $nik,
                    $resource['id'],
                    $identity
                );


                return [

                    "status"=>"existing_patient",

                    "message"=>
                    "Patient ditemukan melalui pencarian identitas.",

                    "satusehat_id"=>
                    $resource['id'],

                    "search_response"=>
                    $identity

                ];

            }

        }

    }



    return [

        "status"=>"duplicate_patient",

        "message"=>
        "Patient sudah ada di SATUSEHAT tetapi pencarian identitas tidak mengembalikan data.",

        "search_response"=>
        $identity

    ];

}
    return [

        "status"=>"error",

        "message"=>"Gagal membuat Patient di SATUSEHAT",

        "http_code"=>$code,

        "response"=>$response

    ];

}


?>