<?php

require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/medication/medication_dispense/medication_dispense_sync.php';


$conn = new mysqli(
    "localhost",
    "root",
    "",
    "simrs_fivecare"
);


if($conn->connect_error){
    die($conn->connect_error);
}


$result = syncMedicationDispenseByResepDetailId($conn,6);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_SLASHES
);