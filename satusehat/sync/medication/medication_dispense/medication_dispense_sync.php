<?php

$fhir_path = __DIR__ . '/../../../fhir_client.php';

if(!file_exists($fhir_path)){
    die("FILE TIDAK ADA: ".$fhir_path);
}

require_once $fhir_path;


if (!function_exists('fhirRequest')) {

    die(
        "FILE TERLOAD TAPI FUNGSI TIDAK ADA: "
        .$fhir_path
    );

}

define(
    'ORGANIZATION_ID',
    '06ff9913-88d6-4480-82da-d6631a80448e'
);



function getMedicationDispenseMapping(
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


    $row=$stmt
        ->get_result()
        ->fetch_assoc();


    return $row['satusehat_id'] ?? null;

}




function saveMedicationDispenseMapping(
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
            'MedicationDispense',
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
        $satusehat_id,
        $json
    );


    return $stmt->execute();

}





function syncMedicationDispenseByResepDetailId(
    $conn,
    $resep_detail_id
){


    /*
    |--------------------------------------------------------------------------
    | Ambil resep
    |--------------------------------------------------------------------------
    */


    $stmt=$conn->prepare("
        SELECT

            rod.id,
            rod.id_obat,
            rod.jumlah,
            rod.dosis,
            rod.frekuensi,
            rod.aturan_pakai,

            o.nama_obat,

            rm.id AS rekam_medis_id,

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


        WHERE rod.id=?

    ");


    $stmt->bind_param(
        "i",
        $resep_detail_id
    );


    $stmt->execute();


    $resep=$stmt
        ->get_result()
        ->fetch_assoc();



    if(!$resep){

        return [

            "status"=>"error",

            "message"=>"Resep detail tidak ditemukan"

        ];

    }





    /*
    |--------------------------------------------------------------------------
    | Cek existing
    |--------------------------------------------------------------------------
    */


    $existing=getMedicationDispenseMapping(
        $conn,
        "resep_obat_detail",
        $resep_detail_id,
        "MedicationDispense"
    );


    if($existing){

        return [

            "status"=>"success",

            "message"=>"MedicationDispense sudah pernah dikirim",

            "satusehat_id"=>$existing

        ];

    }





    /*
    |--------------------------------------------------------------------------
    | Ambil mapping
    |--------------------------------------------------------------------------
    */


    $patient=getMedicationDispenseMapping(
        $conn,
        "pasien",
        $resep['nik_pasien'],
        "Patient"
    );


    $encounter=getMedicationDispenseMapping(
        $conn,
        "pendaftaran",
        $resep['pendaftaran_id'],
        "Encounter"
    );


    $medication=getMedicationDispenseMapping(
        $conn,
        "obat",
        $resep['id_obat'],
        "Medication"
    );


    $medication_request=getMedicationDispenseMapping(
        $conn,
        "resep_obat_detail",
        $resep_detail_id,
        "MedicationRequest"
    );



    if(
        !$patient ||
        !$encounter ||
        !$medication ||
        !$medication_request
    ){

        return [

            "status"=>"error",

            "message"=>"Mapping SATUSEHAT belum lengkap",

            "detail"=>[

                "patient"=>$patient,

                "encounter"=>$encounter,

                "medication"=>$medication,

                "medication_request"=>$medication_request

            ]

        ];

    }





    /*
    |--------------------------------------------------------------------------
    | Payload MedicationDispense
    |--------------------------------------------------------------------------
    */


    $payload=[


        "resourceType"=>"MedicationDispense",


        "identifier"=>[

            [

                "system"=>
                "http://sys-ids.kemkes.go.id/prescription/"
                .ORGANIZATION_ID,

                "use"=>"official",

                "value"=>"DISP-".$resep_detail_id

            ]

        ],



        "status"=>"completed",



        "medicationReference"=>[

            "reference"=>
            "Medication/".$medication

        ],



        "subject"=>[

            "reference"=>
            "Patient/".$patient

        ],



        "context"=>[

            "reference"=>
            "Encounter/".$encounter

        ],



        "authorizingPrescription"=>[

            [

                "reference"=>
                "MedicationRequest/".$medication_request

            ]

        ],



    "quantity" => [
    "value" => (int)$resep['jumlah'],
    "unit" => "TAB",
    "system" => "http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm",
    "code" => "TAB"
],



        "dosageInstruction"=>[

            [

                "text"=>

                $resep['dosis']
                ." "
                .$resep['frekuensi']
                ." "
                .$resep['aturan_pakai']

            ]

        ]

    ];


    file_put_contents(
    __DIR__ . '/last_request.json',
    json_encode(
        $payload,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    )
);


    /*
    |--------------------------------------------------------------------------
    | Kirim SATUSEHAT
    |--------------------------------------------------------------------------
    */
   

    $response=fhirRequest(

        "POST",

        "MedicationDispense",

        $payload,

        []

    );




    if(
        empty($response['data']['id'])
    ){

        return [

            "status"=>"error",

            "message"=>"MedicationDispense gagal",

            "response"=>$response,

            "payload"=>$payload

        ];

    }




    $dispense_id=$response['data']['id'];



/*
|--------------------------------------------------------------------------
| SIMPAN MAPPING SATUSEHAT
|--------------------------------------------------------------------------
*/

if(!saveMedicationDispenseMapping(

    $conn,

    $resep_detail_id,

    $dispense_id,

    $response

)){

    return [

        "status"=>"error",

        "message"=>"Gagal simpan mapping MedicationDispense"

    ];

}


    return [

        "status"=>"success",

        "message"=>"MedicationDispense berhasil dibuat",

        "dispense_id"=>$dispense_id,

        "payload"=>$payload,

        "response"=>$response

    ];


}



?>