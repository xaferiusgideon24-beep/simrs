<?php

require_once __DIR__ . '/../fhir_client.php';

require_once __DIR__ . '/helper/mapping_helper.php';



function getEncounterMappingId(
    $conn,
    $local_table,
    $local_key,
    $resource_type
){

    $stmt=$conn->prepare("
        SELECT satusehat_id
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


    $row=$stmt->get_result()->fetch_assoc();


    return $row['satusehat_id'] ?? null;

}





function saveEncounterMapping(
    $conn,
    $pendaftaran_id,
    $encounter_id,
    $response
){

    $json=json_encode(
        $response,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );


    $local_key=(string)$pendaftaran_id;


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
            'pendaftaran',
            ?,
            'Encounter',
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
        $local_key,
        $encounter_id,
        $json
    );


    return $stmt->execute();

}





function syncEncounterByPendaftaranId(
    $conn,
    $pendaftaran_id
){

    /*
    |--------------------------------------------------------------------------
    | Ambil data pendaftaran
    |--------------------------------------------------------------------------
    */


    $stmt=$conn->prepare("
        SELECT *
        FROM pendaftaran
        WHERE id=?
        LIMIT 1
    ");


    $stmt->bind_param(
        "i",
        $pendaftaran_id
    );


    $stmt->execute();


    $pendaftaran =
        $stmt->get_result()
        ->fetch_assoc();



    if(!$pendaftaran){

        return [

            "status"=>"error",

            "message"=>"Pendaftaran tidak ditemukan"

        ];

    }





    /*
    |--------------------------------------------------------------------------
    | Patient mapping
    |--------------------------------------------------------------------------
    */


    $patient_id =
        getEncounterMappingId(
            $conn,
            "pasien",
            $pendaftaran['nik_pasien'],
            "Patient"
        );



    if(!$patient_id){

        return [

            "status"=>"error",

            "message"=>"Mapping Patient tidak ditemukan",

            "nik"=>$pendaftaran['nik_pasien']

        ];

    }





    /*
    |--------------------------------------------------------------------------
    | Practitioner verified mapping
    |--------------------------------------------------------------------------
    */


    $practitioner =
        getVerifiedPractitionerId(
            $conn,
            $pendaftaran['id_dokter']
        );



    if(
        $practitioner['status']
        !==
        "success"
    ){

        return [

            "status"=>"error",

            "message"=>"Practitioner belum valid",

            "detail"=>$practitioner

        ];

    }



    $practitioner_id =
        $practitioner['ihs'];





    /*
    |--------------------------------------------------------------------------
    | Organization & Location
    |--------------------------------------------------------------------------
    */


    $organization_id =
        "06ff9913-88d6-4480-82da-d6631a80448e";


    $location_id =
        "1e997571-dd1f-4afe-8a00-90b903270ae6";





    /*
    |--------------------------------------------------------------------------
    | Period
    |--------------------------------------------------------------------------
    */


    $period_start =
        $pendaftaran['tgl_kunjungan']
        ."T08:00:00+07:00";





    /*
    |--------------------------------------------------------------------------
    | Encounter Payload
    |--------------------------------------------------------------------------
    */


    $payload=[


        "resourceType"=>"Encounter",



        "identifier"=>[

            [

                "use"=>"official",

                "system"=>
                "http://sys-ids.kemkes.go.id/encounter/".$organization_id,

                "value"=>(string)$pendaftaran_id

            ]

        ],



        "status"=>"arrived",



        "statusHistory"=>[

            [

                "status"=>"arrived",

                "period"=>[

                    "start"=>$period_start

                ]

            ]

        ],



        "class"=>[

            "system"=>
            "http://terminology.hl7.org/CodeSystem/v3-ActCode",

            "code"=>"AMB",

            "display"=>"ambulatory"

        ],



        "subject"=>[

            "reference"=>"Patient/".$patient_id

        ],



        "participant"=>[

            [

                "individual"=>[

                    "reference"=>
                    "Practitioner/".$practitioner_id

                ]

            ]

        ],



        "location"=>[

            [

                "location"=>[

                    "reference"=>
                    "Location/".$location_id

                ],

                "status"=>"active"

            ]

        ],



        "period"=>[

            "start"=>$period_start

        ],



        "serviceProvider"=>[

            "reference"=>
            "Organization/".$organization_id

        ]

    ];



// =====================================================
// CEK ENCOUNTER SUDAH ADA
// =====================================================

$cekMapping = $conn->prepare("
    SELECT satusehat_id
    FROM satusehat_mapping
    WHERE local_table='pendaftaran'
    AND local_key=?
    AND resource_type='Encounter'
    LIMIT 1
");


$cekMapping->bind_param(
    "s",
    $pendaftaran_id
);


$cekMapping->execute();


$existing = $cekMapping
    ->get_result()
    ->fetch_assoc();



if($existing){

    return [

        "status"=>"success",

        "message"=>"Encounter sudah pernah tersync",

        "satusehat_id"=>$existing['satusehat_id']

    ];

}


    /*
    |--------------------------------------------------------------------------
    | Kirim SATUSEHAT
    |--------------------------------------------------------------------------
    */


    $result =
        fhirRequest(
            "POST",
            "Encounter",
            $payload,
            []
        );





    if(
        empty($result['data']['id'])
    ){


        return [

            "status"=>"error",

            "message"=>"Gagal membuat Encounter di SATUSEHAT",

            "response"=>$result,

            "payload"=>$payload

        ];

    }






    saveEncounterMapping(

        $conn,

        $pendaftaran_id,

        $result['data']['id'],

        $result

    );





    return [

        "status"=>"success",

        "message"=>"Encounter berhasil dibuat",

        "encounter_id"=>$result['data']['id'],

        "practitioner_id"=>$practitioner_id,

        "payload"=>$payload,

        "response"=>$result

    ];

}


?>