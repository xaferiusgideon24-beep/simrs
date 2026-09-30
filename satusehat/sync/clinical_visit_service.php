<?php

require_once __DIR__.'/encounter_sync.php';
require_once __DIR__.'/condition/condition_sync.php';
require_once __DIR__.'/observation/observation_sync.php';
require_once __DIR__.'/medication/medication_request_sync.php';
require_once __DIR__.'/medication/sync_resep_detail.php';


function syncClinicalVisitService(
    $conn,
    $pendaftaranId
){

    $stmtRM = $conn->prepare("
        SELECT id
        FROM rekam_medis
        WHERE id_pendaftaran=?
        LIMIT 1
    ");


    $stmtRM->bind_param(
        "i",
        $pendaftaranId
    );


    $stmtRM->execute();


    $rm = $stmtRM
        ->get_result()
        ->fetch_assoc();



    if(!$rm){

        return [
            "status"=>"error",
            "message"=>"Rekam medis tidak ditemukan"
        ];

    }



    $rekamMedisId = $rm['id'];



    $encounter =
        syncEncounterByPendaftaranId(
            $conn,
            $pendaftaranId
        );



    $condition =
        syncConditionByRekamMedisId(
            $conn,
            $rekamMedisId
        );



    $observation = null;


    $cekVS = $conn->prepare("
        SELECT id
        FROM vital_sign
        WHERE id_pendaftaran=?
        LIMIT 1
    ");


    $cekVS->bind_param(
        "i",
        $pendaftaranId
    );


    $cekVS->execute();


    $vs = $cekVS
        ->get_result()
        ->fetch_assoc();



    if($vs){

        $observation =
            syncObservationByPendaftaranId(
                $conn,
                $pendaftaranId
            );

    }



    $resep =
        generateResepDetailFromJSON(
            $conn,
            $rekamMedisId
        );



    $medication =
        syncMedicationRequestByRekamMedisId(
            $conn,
            $rekamMedisId
        );



    return [

        "status"=>"success",

        "message"=>"Sync clinical visit selesai",

        "pendaftaran_id"=>$pendaftaranId,

        "rekam_medis_id"=>$rekamMedisId,


        "results"=>[

            "Encounter"=>$encounter,

            "Condition"=>$condition,

            "Observation"=>$observation,

            "Generate Resep Detail"=>$resep,

            "MedicationRequest"=>$medication

        ]

    ];

}

?>