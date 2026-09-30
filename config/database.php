<?php


$host = "localhost";
$user = "root";
$pass = "";
$db   = "simrs_fivecare";



$conn = new mysqli(
    $host,
    $user,
    $pass,
    $db
);



if($conn->connect_error){

    die(
        json_encode([
            "status"=>"error",
            "message"=>"Database connection gagal"
        ])
    );

}


$conn->set_charset("utf8mb4");


?>