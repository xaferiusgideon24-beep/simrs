<?php

/*
=========================================================
TEST ENCOUNTER
File:
C:\xampp\htdocs\simrs\satusehat\sync\test_encounter.php
=========================================================
*/

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');


/*
=========================================================
DATABASE
=========================================================
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


if ($conn->connect_error) {

    http_response_code(500);

    echo json_encode(
        [
            'status' => 'error',
            'message' =>
                'Koneksi database gagal: '
                . $conn->connect_error
        ],
        JSON_PRETTY_PRINT
    );

    exit;
}


$conn->set_charset('utf8mb4');


/*
=========================================================
LOAD ENCOUNTER SYNC
encounter_sync.php akan otomatis load ../fhir_client.php
=========================================================
*/

require_once __DIR__ . '/encounter_sync.php';


/*
=========================================================
ID PENDAFTARAN YANG AKAN DITES
=========================================================
*/

$pendaftaran_id = isset($_GET['id'])
    ? (int)$_GET['id']
    : 109;


/*
=========================================================
SYNC
=========================================================
*/

$result = syncEncounterByPendaftaranId(
    $conn,
    ID_PENDAFTARAN
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
);

$conn->close();

?>
