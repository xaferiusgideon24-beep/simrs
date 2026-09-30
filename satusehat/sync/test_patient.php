<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

$conn = new mysqli(
    'localhost',
    'root',
    '',
    'simrs_fivecare'
);

if ($conn->connect_error) {
    echo json_encode(
        [
            'status' => 'error',
            'message' =>
                'Database connection gagal: ' . $conn->connect_error
        ],
        JSON_PRETTY_PRINT
    );
    exit;
}

$conn->set_charset('utf8mb4');

require_once __DIR__ . '/patient/patient_sync.php';

$nik = isset($_GET['nik'])
    ? trim($_GET['nik'])
    : '3276010101900001';

$result = syncPatientByNik(
    $conn,
    $nik
);

echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
);

$conn->close();

?>
