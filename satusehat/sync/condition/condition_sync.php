<?php

require_once __DIR__ . '/../../fhir_client.php';

require_once __DIR__ . '/../helper/mapping_helper.php';



function getConditionMapping(
    $conn,
    $table,
    $key,
    $type
){

    $stmt=$conn->prepare("
        SELECT satusehat_id
        FROM satusehat_mapping
        WHERE local_table=?
        AND local_key=?
        AND resource_type=?
        LIMIT 1
    ");


    $key=(string)$key;


    $stmt->bind_param(
        "sss",
        $table,
        $key,
        $type
    );


    $stmt->execute();


    $row=$stmt
        ->get_result()
        ->fetch_assoc();


    return $row['satusehat_id'] ?? null;

}





function saveConditionMapping(
    $conn,
    $local_key,
    $condition_id,
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
            'rekam_medis',
            ?,
            'Condition',
            ?,
            'sent',
            NOW(),
            ?
        )
    ");


    $local_key=(string)$local_key;


    $stmt->bind_param(
        "sss",
        $local_key,
        $condition_id,
        $json
    );


    return $stmt->execute();

}





function syncConditionByRekamMedisId(
    $conn,
    $rekam_medis_id
){


    /*
    |--------------------------------------------------------------------------
    | Ambil Rekam Medis
    |--------------------------------------------------------------------------
    */


    $stmt=$conn->prepare("
        SELECT
            rm.*,
            p.nik_pasien,
            p.id_dokter
        FROM rekam_medis rm
        JOIN pendaftaran p
        ON rm.id_pendaftaran=p.id
        WHERE rm.id=?
        LIMIT 1
    ");


    $stmt->bind_param(
        "i",
        $rekam_medis_id
    );


    $stmt->execute();


    $rm=$stmt
        ->get_result()
        ->fetch_assoc();



    if(!$rm){

        return [

            "status"=>"error",

            "message"=>"Rekam medis tidak ditemukan"

        ];

    }





    /*
    |--------------------------------------------------------------------------
    | Ambil ICD
    |--------------------------------------------------------------------------
    */


    $stmt=$conn->prepare("
        SELECT *
        FROM icd_10
        WHERE code=?
        LIMIT 1
    ");


    $stmt->bind_param(
        "s",
        $rm['diagnosa_utama']
    );


    $stmt->execute();


    $icd=$stmt
        ->get_result()
        ->fetch_assoc();



    if(!$icd){

        return [

            "status"=>"error",

            "message"=>"ICD tidak ditemukan",

            "kode"=>$rm['diagnosa_utama']

        ];

    }





    /*
    |--------------------------------------------------------------------------
    | Mapping Patient
    |--------------------------------------------------------------------------
    */


    $patient_id=getConditionMapping(
        $conn,
        "pasien",
        $rm['nik_pasien'],
        "Patient"
    );



    if(!$patient_id){

        return [

            "status"=>"error",

            "message"=>"Mapping Patient tidak ditemukan"

        ];

    }





    /*
    |--------------------------------------------------------------------------
    | Mapping Encounter
    |--------------------------------------------------------------------------
    */


    $encounter_id=getConditionMapping(
        $conn,
        "pendaftaran",
        $rm['id_pendaftaran'],
        "Encounter"
    );



    if(!$encounter_id){

        return [

            "status"=>"error",

            "message"=>"Mapping Encounter tidak ditemukan"

        ];

    }





    /*
    |--------------------------------------------------------------------------
    | Mapping Practitioner VERIFIED
    |--------------------------------------------------------------------------
    */


    $practitioner=getVerifiedPractitionerId(
        $conn,
        $rm['id_dokter']
    );



    if(
        $practitioner['status']
        !==
        "success"
    ){

        return [

            "status"=>"error",

            "message"=>"Practitioner belum verified",

            "detail"=>$practitioner

        ];

    }



    $practitioner_id=
        $practitioner['ihs'];







    /*
    |--------------------------------------------------------------------------
    | Payload Condition
    |--------------------------------------------------------------------------
    */


    $payload=[


        "resourceType"=>"Condition",



        "clinicalStatus"=>[

            "coding"=>[

                [

                    "system"=>
                    "http://terminology.hl7.org/CodeSystem/condition-clinical",

                    "code"=>"active",

                    "display"=>"Active"

                ]

            ]

        ],



        "category"=>[

            [

                "coding"=>[

                    [

                        "system"=>
                        "http://terminology.hl7.org/CodeSystem/condition-category",

                        "code"=>"encounter-diagnosis",

                        "display"=>"Encounter Diagnosis"

                    ]

                ]

            ]

        ],



        "code"=>[

            "coding"=>[

                [

                    "system"=>
                    "http://hl7.org/fhir/sid/icd-10",

                    "code"=>$icd['code'],

                    "display"=>$icd['display']

                ]

            ]

        ],



        "subject"=>[

            "reference"=>
            "Patient/".$patient_id

        ],



        "encounter"=>[

            "reference"=>
            "Encounter/".$encounter_id

        ],



        "recorder"=>[

            "reference"=>
            "Practitioner/".$practitioner_id

        ]

    ];



// =====================================================
// CEK CONDITION SUDAH ADA
// =====================================================

$cekMapping = $conn->prepare("
    SELECT satusehat_id
    FROM satusehat_mapping
    WHERE local_table='rekam_medis'
    AND local_key=?
    AND resource_type='Condition'
    LIMIT 1
");


$cekMapping->bind_param(
    "s",
    $rekam_medis_id
);


$cekMapping->execute();


$existing=$cekMapping
    ->get_result()
    ->fetch_assoc();



if($existing){

    return [

        "status"=>"success",

        "message"=>"Condition sudah pernah tersync",

        "satusehat_id"=>$existing['satusehat_id']

    ];

}


    /*
    |--------------------------------------------------------------------------
    | Kirim SATUSEHAT
    |--------------------------------------------------------------------------
    */


    $result=fhirRequest(
        "POST",
        "Condition",
        $payload,
        []
    );





    if(
        empty($result['data']['id'])
    ){

        return [

            "status"=>"error",

            "message"=>"Gagal membuat Condition",

            "response"=>$result,

            "payload"=>$payload

        ];

    }





    saveConditionMapping(
        $conn,
        $rekam_medis_id,
        $result['data']['id'],
        $result
    );





    return [

        "status"=>"success",

        "message"=>"Condition berhasil dibuat",

        "condition_id"=>$result['data']['id'],

        "payload"=>$payload,

        "response"=>$result

    ];

}


?>