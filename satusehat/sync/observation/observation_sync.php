<?php

require_once __DIR__ . '/../../fhir_client.php';
require_once __DIR__ . '/../helper/mapping_helper.php';



function getObservationMapping(
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





function saveObservationMapping(
    $conn,
    $local_key,
    $observation_id,
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
            'vital_sign',
            ?,
            'Observation',
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
        $observation_id,
        $json
    );


    return $stmt->execute();

}





function createObservation(
    $code,
    $display,
    $value,
    $unit,
    $unitCode,
    $patient_id,
    $encounter_id,
    $practitioner_id,
    $effectiveDateTime
){

    return [

        "resourceType"=>"Observation",

        "status"=>"final",

        "category"=>[
            [
                "coding"=>[
                    [
                        "system"=>"http://terminology.hl7.org/CodeSystem/observation-category",
                        "code"=>"vital-signs",
                        "display"=>"Vital Signs"
                    ]
                ]
            ]
        ],


        "code"=>[
            "coding"=>[
                [
                    "system"=>"http://loinc.org",
                    "code"=>$code,
                    "display"=>$display
                ]
            ]
        ],


        "subject"=>[
            "reference"=>"Patient/".$patient_id
        ],


        "encounter"=>[
            "reference"=>"Encounter/".$encounter_id
        ],


        "performer"=>[
            [
                "reference"=>"Practitioner/".$practitioner_id
            ]
        ],


        "effectiveDateTime"=>$effectiveDateTime,


        "issued"=>$effectiveDateTime,


        "valueQuantity"=>[
            "value"=>floatval($value),
            "unit"=>$unit,
            "system"=>"http://unitsofmeasure.org",
            "code"=>$unitCode
        ]

    ];

}


function createBloodPressureObservation(
    $patient_id,
    $encounter_id,
    $practitioner_id,
    $sistolik,
    $diastolik,
    $effectiveDateTime
){

return [

"resourceType"=>"Observation",

"status"=>"final",

"category"=>[
    [
        "coding"=>[
            [
                "system"=>"http://terminology.hl7.org/CodeSystem/observation-category",
                "code"=>"vital-signs",
                "display"=>"Vital Signs"
            ]
        ]
    ]
],


"code"=>[
    "coding"=>[
        [
            "system"=>"http://loinc.org",
            "code"=>"85354-9",
            "display"=>"Blood pressure panel"
        ]
    ]
],


"subject"=>[
    "reference"=>"Patient/".$patient_id
],


"encounter"=>[
    "reference"=>"Encounter/".$encounter_id
],


"performer"=>[
    [
        "reference"=>"Practitioner/".$practitioner_id
    ]
],


"effectiveDateTime"=>$effectiveDateTime,

"issued" => $effectiveDateTime,

"component"=>[

    [
        "code"=>[
            "coding"=>[
                [
                    "system"=>"http://loinc.org",
                    "code"=>"8480-6",
                    "display"=>"Systolic blood pressure"
                ]
            ]
        ],

        "valueQuantity"=>[
            "value"=>intval($sistolik),
            "unit"=>"mmHg",
            "system"=>"http://unitsofmeasure.org",
            "code"=>"mm[Hg]"
        ]
    ],


    [
        "code"=>[
            "coding"=>[
                [
                    "system"=>"http://loinc.org",
                    "code"=>"8462-4",
                    "display"=>"Diastolic blood pressure"
                ]
            ]
        ],

        "valueQuantity"=>[
            "value"=>intval($diastolik),
            "unit"=>"mmHg",
            "system"=>"http://unitsofmeasure.org",
            "code"=>"mm[Hg]"
        ]
    ]

]

];

}


function syncObservationByPendaftaranId(
    $conn,
    $pendaftaran_id
){


    $stmt=$conn->prepare("
        SELECT *
        FROM vital_sign
        WHERE id_pendaftaran=?
        LIMIT 1
    ");


    $stmt->bind_param(
        "i",
        $pendaftaran_id
    );


    $stmt->execute();


    $vital=$stmt
        ->get_result()
        ->fetch_assoc();



    if(!$vital){

        return [
            "status"=>"error",
            "message"=>"Vital sign tidak ditemukan"
        ];

    }





    $stmt=$conn->prepare("
        SELECT
            nik_pasien,
            id_dokter
        FROM pendaftaran
        WHERE id=?
        LIMIT 1
    ");


    $stmt->bind_param(
        "i",
        $pendaftaran_id
    );


    $stmt->execute();


    $pendaftaran=$stmt
        ->get_result()
        ->fetch_assoc();





    $patient_id=getObservationMapping(
        $conn,
        "pasien",
        $pendaftaran['nik_pasien'],
        "Patient"
    );


    if(!$patient_id){

        return [
            "status"=>"error",
            "message"=>"Patient mapping tidak ditemukan"
        ];

    }





    $encounter_id=getObservationMapping(
        $conn,
        "pendaftaran",
        $pendaftaran_id,
        "Encounter"
    );


    if(!$encounter_id){

        return [
            "status"=>"error",
            "message"=>"Encounter mapping tidak ditemukan"
        ];

    }





    $practitioner=getVerifiedPractitionerId(
        $conn,
        $pendaftaran['id_dokter']
    );


    if(
        $practitioner['status']!="success"
    ){

        return [
            "status"=>"error",
            "message"=>"Practitioner belum verified"
        ];

    }


    $practitioner_id=$practitioner['ihs'];





    $effectiveDateTime=date(
        "Y-m-d\TH:i:sP",
        strtotime($vital['created_at'])
    );





    $observations=[];
if(
    $vital['sistolik']!==null &&
    $vital['diastolik']!==null
){

    $observations[]=[

        "key"=>"Blood Pressure",

        "payload"=>createBloodPressureObservation(
            $patient_id,
            $encounter_id,
            $practitioner_id,
            $vital['sistolik'],
            $vital['diastolik'],
            $effectiveDateTime
        )

    ];

}




    if($vital['suhu']!==null){

        $observations[]=[
            "key"=>"Suhu",
            "payload"=>createObservation(
                "8310-5",
                "Body temperature",
                $vital['suhu'],
                "Cel",
                "Cel",
                $patient_id,
                $encounter_id,
                $practitioner_id,
                $effectiveDateTime
            )
        ];

    }





    if($vital['nadi']!==null){

        $observations[]=[
            "key"=>"Nadi",
            "payload"=>createObservation(
                "8867-4",
                "Heart rate",
                $vital['nadi'],
                "beats/minute",
                "/min",
                $patient_id,
                $encounter_id,
                $practitioner_id,
                $effectiveDateTime
            )
        ];

    }





    if($vital['respiratory_rate']!==null){

        $observations[]=[
            "key"=>"Respiratory Rate",
            "payload"=>createObservation(
                "9279-1",
                "Respiratory rate",
                $vital['respiratory_rate'],
                "breaths/minute",
                "/min",
                $patient_id,
                $encounter_id,
                $practitioner_id,
                $effectiveDateTime
            )
        ];

    }





    if($vital['berat_badan']!==null){

        $observations[]=[
            "key"=>"Berat Badan",
            "payload"=>createObservation(
                "29463-7",
                "Body weight",
                $vital['berat_badan'],
                "kg",
                "kg",
                $patient_id,
                $encounter_id,
                $practitioner_id,
                $effectiveDateTime
            )
        ];

    }





    if($vital['tinggi_badan']!==null){

        $observations[]=[
            "key"=>"Tinggi Badan",
            "payload"=>createObservation(
                "8302-2",
                "Body height",
                $vital['tinggi_badan'],
                "cm",
                "cm",
                $patient_id,
                $encounter_id,
                $practitioner_id,
                $effectiveDateTime
            )
        ];

    }





    $results=[];


    foreach($observations as $obs){


        $local_key =
            $vital['id']."_".$obs['key'];



        /*
            CEK DUPLICATE
        */

        $existing =
        getObservationMapping(
            $conn,
            "vital_sign",
            $local_key,
            "Observation"
        );



        if($existing){

            $results[]=[

                "status"=>"skip",

                "message"=>"Observation sudah tersync",

                "local_key"=>$local_key,

                "satusehat_id"=>$existing

            ];


            continue;

        }





        $result=fhirRequest(
            "POST",
            "Observation",
            $obs['payload'],
            []
        );



        $results[]=$result;



        if(
            !empty($result['data']['id'])
        ){

            saveObservationMapping(
                $conn,
                $local_key,
                $result['data']['id'],
                $result
            );

        }

    }





    return [

        "status"=>"success",

        "message"=>"Observation selesai diproses",

        "total"=>count($results),

        "results"=>$results

    ];

}

?>