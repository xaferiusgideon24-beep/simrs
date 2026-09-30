<?php

$host='localhost';
$user='root';
$pass='';
$db='simrs_fivecare';


$conn=new mysqli(
$host,
$user,$pass,$db
);


require_once "practitioner_role_mapping.php";


$result =
mappingPractitionerRole(

$conn,

101,

"c5036fac-5fe4-44b7-a681-97518bc1af06",

[
"id"=>"c5036fac-5fe4-44b7-a681-97518bc1af06"
]

);


echo json_encode($result);

?>