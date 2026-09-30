<?php

$conn=new mysqli(
    "localhost",
    "root",
    "",
    "simrs_fivecare"
);


require_once __DIR__."/medication/medication_request_sync.php";

$result=syncMedicationRequestByResepId(
    $conn,
    2
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT
);

?>