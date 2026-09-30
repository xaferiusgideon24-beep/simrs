<?php

require_once __DIR__ . '/../config.php';

require_once __DIR__ . '/encounter_sync.php';


$conn = new mysqli(
    "localhost",
    "root",
    "",
    "simrs_fivecare"
);


if($conn->connect_error){

    die(
        json_encode([
            "status"=>"error",
            "message"=>$conn->connect_error
        ])
    );

}


$result = syncEncounterByPendaftaranId(
    $conn,
    117
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_SLASHES |
    JSON_UNESCAPED_UNICODE
);

?>