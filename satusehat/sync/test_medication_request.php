<?php

error_reporting(E_ALL);
ini_set('display_errors',1);

header('Content-Type: application/json');


$conn = new mysqli(
    "localhost",
    "root",
    "",
    "simrs_fivecare"
);


$conn->set_charset("utf8mb4");


require_once __DIR__ .
"/medication/medication_request_sync.php";


$result = syncMedicationRequestByResepId(
    $conn,
    3
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
);


$conn->close();

?>