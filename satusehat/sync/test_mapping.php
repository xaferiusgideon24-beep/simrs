<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');


/*
=========================
KONEKSI DATABASE
=========================
*/

$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'simrs_fivecare';


$conn = new mysqli(
    $host,
    $user,
    $pass,
    $db
);


if($conn->connect_error){

    die(json_encode([
        "status"=>"error",
        "message"=>"Database gagal: ".$conn->connect_error
    ]));

}


/*
=========================
LOAD FILE MAPPING
=========================
*/

require_once __DIR__ . "/practitioner_mapping.php";



/*
=========================
TEST MAPPING
=========================
*/


$result = mappingDokterPractitioner(

    $conn,

    101,

    "100009880728"

);



echo json_encode(
    $result,
    JSON_PRETTY_PRINT
);

?>