<?php

require_once __DIR__ . '/satusehat/sync/encounter_sync.php';
require_once __DIR__ . '/satusehat/sync/condition/condition_sync.php';
require_once __DIR__ . '/satusehat/sync/observation/observation_sync.php';
require_once __DIR__ . '/satusehat/sync/medication/medication_request_sync.php';
require_once __DIR__ . '/satusehat/sync/medication/sync_resep_detail.php';


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


    $rekamMedis =
        $stmtRM
        ->get_result()
        ->fetch_assoc();


    if(!$rekamMedis){

        return [

            "status"=>"error",

            "message"=>"Rekam medis tidak ditemukan"

        ];

    }


    $rekamMedisId =
        $rekamMedis['id'];



    // Encounter

    $encounter =
        syncEncounterByPendaftaranId(
            $conn,
            $pendaftaranId
        );



    // Condition

    $condition =
        syncConditionByRekamMedisId(
            $conn,
            $rekamMedisId
        );



    // Observation

    $observation=null;


    $stmtVS=$conn->prepare("
        SELECT id
        FROM vital_sign
        WHERE id_pendaftaran=?
        LIMIT 1
    ");


    $stmtVS->bind_param(
        "i",
        $pendaftaranId
    );


    $stmtVS->execute();


    $vital =
        $stmtVS
        ->get_result()
        ->fetch_assoc();



    if($vital){

        $observation =
            syncObservationByPendaftaranId(
                $conn,
                $pendaftaranId
            );

    }



    // Resep detail

    $resep =
        generateResepDetailFromJSON(
            $conn,
            $rekamMedisId
        );



    // Medication Request

    $medication =
        syncMedicationRequestByRekamMedisId(
            $conn,
            $rekamMedisId
        );



    return [

        "status"=>"success",

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