<?php

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ .
'/medication/medication_dispense/medication_dispense_sync.php';


header('Content-Type: application/json');


$id_resep_detail = $_GET['id'] ?? null;


if(!$id_resep_detail){

    echo json_encode([

        "status"=>"error",

        "message"=>"Parameter id wajib"

    ]);

    exit;

}



$result =
syncMedicationDispenseByResepDetailId(

    $conn,

    $id_resep_detail

);



echo json_encode(

    $result,

    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE

);