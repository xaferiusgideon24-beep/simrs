<?php

require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/patient/patient_sync.php';

$conn = new mysqli(
    "localhost",
    "root",
    "",
    "simrs_fivecare"
);

$result = syncPatientByNik(
    $conn,
    '3276010101900001'
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
);

?>