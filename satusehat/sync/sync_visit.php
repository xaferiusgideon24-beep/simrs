<?php

require_once __DIR__ . "/encounter/encounter_sync.php";
require_once __DIR__ . "/condition/condition_sync.php";
require_once __DIR__ . "/observation/observation_sync.php";
require_once __DIR__ . "/medication/medication_request_sync.php";


function syncVisitSATUSEHAT($conn, $id_pendaftaran)
{
    $result = [
        "pendaftaran" => $id_pendaftaran,
        "sync" => []
    ];

    // Encounter
    if (function_exists("syncEncounterByPendaftaranId")) {
        $result["sync"]["Encounter"] =
            syncEncounterByPendaftaranId(
                $conn,
                $id_pendaftaran
            );
    }

    // Condition
    if (function_exists("syncConditionByPendaftaranId")) {
        $result["sync"]["Condition"] =
            syncConditionByPendaftaranId(
                $conn,
                $id_pendaftaran
            );
    }

    // Observation
    if (function_exists("syncObservationByPendaftaranId")) {
        $result["sync"]["Observation"] =
            syncObservationByPendaftaranId(
                $conn,
                $id_pendaftaran
            );
    }

    // MedicationRequest
    // resep diambil dari rekam medis yang terkait
    if (function_exists("syncMedicationRequestByPendaftaranId")) {
        $result["sync"]["MedicationRequest"] =
            syncMedicationRequestByPendaftaranId(
                $conn,
                $id_pendaftaran
            );
    }

    return $result;
}

?>
