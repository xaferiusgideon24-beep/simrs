<?php

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/observation/observation_sync.php';


header('Content-Type: application/json');


$result =
syncObservationByPendaftaranId(
    $conn,
    116
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);