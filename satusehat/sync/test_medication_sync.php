<?php

require_once __DIR__.'/../config.php';
require_once __DIR__.'/medication/medication_sync.php';


$conn=new mysqli(
"localhost",
"root",
"",
"simrs_fivecare"
);


echo json_encode(
syncMedicationByObatId($conn,10),
JSON_PRETTY_PRINT
);

?>