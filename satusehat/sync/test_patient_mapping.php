<?php

$host='localhost';
$user='root';
$pass='';
$db='simrs_fivecare';


$conn = new mysqli(
    $host,
    $user,
    $pass,
    $db
);


require_once "patient_mapping.php";


$result =
mappingPatientManual(
    $conn,
    1,
    "PATIENT_ID_SATUSEHAT"
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT
);

?>