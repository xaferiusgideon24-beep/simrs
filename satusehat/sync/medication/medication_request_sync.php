<?php

require_once __DIR__ . '/../../fhir_client.php';


define(
    'ORGANIZATION_ID',
    '06ff9913-88d6-4480-82da-d6631a80448e'
);



function getMapping(
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


    $stmt->bind_param(
        "sss",
        $local_table,
        $local_key,
        $resource_type
    );


    $stmt->execute();


    $result=$stmt
        ->get_result()
        ->fetch_assoc();


    return $result['satusehat_id'] ?? null;

}




function getPractitioner(
    $conn,
    $dokter_id
){

    $stmt=$conn->prepare("
        SELECT
            ihs_number,
            status
        FROM satusehat_practitioner_reference
        WHERE dokter_id=?
        AND status='verified'
        LIMIT 1
    ");


    $stmt->bind_param(
        "i",
        $dokter_id
    );


    $stmt->execute();


    return $stmt
        ->get_result()
        ->fetch_assoc();

}




function saveMedicationRequestMapping(
    $conn,
    $local_key,
    $satusehat_id,
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
            'resep_obat_detail',
            ?,
            'MedicationRequest',
            ?,
            'sent',
            NOW(),
            ?
        )
    ");


    $stmt->bind_param(
        "sss",
        $local_key,
        $satusehat_id,
        $json
    );


    return $stmt->execute();

}







function syncMedicationRequestByRekamMedisId(
    $conn,
    $rekam_medis_id
){



    $stmt=$conn->prepare("
        SELECT
            rod.id,
            rod.id_obat,
            rod.jumlah,
            rod.dosis,
            rod.frekuensi,
            rod.aturan_pakai,
            o.nama_obat,
            p.id AS pendaftaran_id,
            p.nik_pasien,
            p.id_dokter

        FROM resep_obat_detail rod

        JOIN obat o
        ON o.id=rod.id_obat

        JOIN rekam_medis rm
        ON rm.id=rod.id_rekam_medis

        JOIN pendaftaran p
        ON p.id=rm.id_pendaftaran

        WHERE rod.id_rekam_medis=?
    ");


    $stmt->bind_param(
        "i",
        $rekam_medis_id
    );


    $stmt->execute();


    $data=$stmt
        ->get_result();


    $results=[];



    while($row=$data->fetch_assoc()){



        $local_key=$row['id'];



        $existing=getMapping(
            $conn,
            'resep_obat_detail',
            $local_key,
            'MedicationRequest'
        );


        if($existing){


            $results[]=[
                "status"=>"skip",
                "message"=>"MedicationRequest sudah tersync",
                "satusehat_id"=>$existing
            ];


            continue;

        }






        $patient=getMapping(
            $conn,
            'pasien',
            $row['nik_pasien'],
            'Patient'
        );



        $encounter=getMapping(
            $conn,
            'pendaftaran',
            $row['pendaftaran_id'],
            'Encounter'
        );



        $medication=getMapping(
            $conn,
            'obat',
            $row['id_obat'],
            'Medication'
        );



        $practitioner=getPractitioner(
            $conn,
            $row['id_dokter']
        );






        if(
            !$patient ||
            !$encounter ||
            !$medication ||
            !$practitioner
        ){


            $results[]=[

                "status"=>"error",

                "message"=>"Mapping Patient/Encounter/Medication/Practitioner belum lengkap",

                "detail"=>[
                    "patient"=>$patient,
                    "encounter"=>$encounter,
                    "medication"=>$medication,
                    "practitioner"=>$practitioner
                ]

            ];


            continue;

        }








        $payload=[


            "resourceType"=>"MedicationRequest",


            "identifier"=>[

                [

                    "use"=>"official",

                    "system"=>
                    "http://sys-ids.kemkes.go.id/prescription/"
                    .ORGANIZATION_ID,


                    "value"=>
                    "RX-".$local_key

                ]

            ],



            "status"=>"active",



            "intent"=>"order",



            "authoredOn"=>
            date("Y-m-d\TH:i:sP"),



            "medicationReference"=>[

                "reference"=>
                "Medication/".$medication

            ],



            "subject"=>[

                "reference"=>
                "Patient/".$patient

            ],



            "encounter"=>[

                "reference"=>
                "Encounter/".$encounter

            ],



            "requester"=>[

                "reference"=>
                "Practitioner/".$practitioner['ihs_number']

            ],



            "dosageInstruction"=>[

                [

                    "text"=>
                    $row['dosis']
                    ." "
                    .$row['frekuensi']
                    ." "
                    .$row['aturan_pakai']

                ]

            ]

        ];






        $response=fhirRequest(
            "POST",
            "MedicationRequest",
            $payload,
            []
        );





        if(
            isset($response['data']['id'])
        ){


            saveMedicationRequestMapping(
                $conn,
                $local_key,
                $response['data']['id'],
                $response
            );


        }



        $results[]=$response;


    }





    return [

        "status"=>"success",

        "message"=>"MedicationRequest selesai diproses",

        "total"=>count($results),

        "results"=>$results

    ];

}



?>