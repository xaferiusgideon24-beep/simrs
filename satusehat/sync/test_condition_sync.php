<?php

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/condition/condition_sync.php';


header('Content-Type: application/json; charset=utf-8');


$result = syncConditionByRekamMedisId(
    $conn,
    10
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);