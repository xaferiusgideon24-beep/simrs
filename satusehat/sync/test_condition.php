<?php

$conn=new mysqli(
"localhost",
"root",
"",
"simrs_fivecare"
);


require_once __DIR__."/condition/condition_sync.php";


$result=syncConditionByRekamMedisId(
    $conn,
    7
);


echo json_encode(
    $result,
    JSON_PRETTY_PRINT
);

?>