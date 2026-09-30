<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');


$conn = new mysqli(
    "localhost",
    "root",
    "",
    "simrs_fivecare"
);


if ($conn->connect_error) {

    echo json_encode([
        "status"=>"error",
        "message"=>$conn->connect_error
    ]);

    exit;

}


$conn->set_charset("utf8mb4");


// panggil file medication
require_once __DIR__ . "/medication/medication_sync.php";


// obat id 10 = Paracetamol
$result = syncMedicationByObatId(
    $conn,
    10
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
);


$conn->close();

?>
