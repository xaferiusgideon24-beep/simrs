<?php

$conn = new mysqli(
    "localhost",
    "root",
    "",
    "simrs_fivecare"
);

// Sesuaikan jalur direktori ke subfolder observation
require_once __DIR__ . "/observation/observation_sync.php";

$result = syncObservationByVitalSignId(
    $conn,
    2
);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT
);

?>