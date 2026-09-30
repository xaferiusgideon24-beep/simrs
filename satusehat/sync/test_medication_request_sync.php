<?php

require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/medication/medication_request_sync.php';


$conn = new mysqli(
    "localhost",
    "root",
    "",
    "simrs_fivecare"
);


if ($conn->connect_error) {
    die("Database gagal: " . $conn->connect_error);
}


$result = syncMedicationRequestByRekamMedisId(
    $conn,
    10
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
);

?>