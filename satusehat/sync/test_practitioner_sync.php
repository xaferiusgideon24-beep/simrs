<?php

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/practitioner_sync.php';


header('Content-Type: application/json; charset=utf-8');


$result = syncPractitionerByDokterId(
    $conn,
    145
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);