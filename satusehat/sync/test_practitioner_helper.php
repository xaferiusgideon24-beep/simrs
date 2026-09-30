<?php

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/helper/mapping_helper.php';


header('Content-Type: application/json');


$result = getVerifiedPractitionerId(
    $conn,
    145
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT
);
