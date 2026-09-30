<?php

header('Content-Type: application/json');

$host='localhost';
$user='root';
$pass='';
$db='simrs_fivecare';

$conn=new mysqli(
    $host,
    $user,
    $pass,
    $db
);


if($conn->connect_error){

    echo json_encode([
        "status"=>"error",
        "message"=>"Database gagal"
    ]);

    exit;
}



$action=$_GET['action'] ?? '';



switch($action){


case 'summary':

    $sql="
    SELECT 
        resource_type,
        status,
        COUNT(*) jumlah
    FROM satusehat_mapping
    GROUP BY resource_type,status
    ORDER BY resource_type
    ";


    $result=$conn->query($sql);


    $data=[];


    while($row=$result->fetch_assoc()){

        $data[]=$row;

    }


    echo json_encode([
        "status"=>"success",
        "data"=>$data
    ]);

break;




case 'recent':

    $sql="
    SELECT
        id,
        local_table,
        local_key,
        resource_type,
        satusehat_id,
        status,
        last_sync
    FROM satusehat_mapping
    ORDER BY id DESC
    LIMIT 20
    ";


    $result=$conn->query($sql);


    $data=[];


    while($row=$result->fetch_assoc()){

        $data[]=$row;

    }


    echo json_encode([
        "status"=>"success",
        "data"=>$data
    ]);

break;





case 'failed':

    $sql="
    SELECT *
    FROM satusehat_mapping
    WHERE status='failed'
    ORDER BY last_sync DESC
    ";


    $result=$conn->query($sql);


    $data=[];


    while($row=$result->fetch_assoc()){

        $data[]=$row;

    }


    echo json_encode([
        "status"=>"success",
        "total"=>count($data),
        "data"=>$data
    ]);

break;



case 'detail':

    $id=$_GET['id'] ?? 0;


    $stmt=$conn->prepare("
        SELECT
            *
        FROM satusehat_mapping
        WHERE id=?
        LIMIT 1
    ");


    $stmt->bind_param(
        "i",
        $id
    );


    $stmt->execute();


    $data=$stmt
        ->get_result()
        ->fetch_assoc();



    if(!$data){

        echo json_encode([
            "status"=>"error",
            "message"=>"Data tidak ditemukan"
        ]);

        exit;

    }



    $data['response_decode']=
        json_decode(
            $data['response'],
            true
        );



    echo json_encode([
        "status"=>"success",
        "data"=>$data
    ]);


break;

case 'statistik':

$total =
$conn->query("
SELECT COUNT(*) jumlah
FROM satusehat_mapping
")
->fetch_assoc()['jumlah'];



$sent =
$conn->query("
SELECT COUNT(*) jumlah
FROM satusehat_mapping
WHERE status='sent'
")
->fetch_assoc()['jumlah'];



$failed =
$conn->query("
SELECT COUNT(*) jumlah
FROM satusehat_mapping
WHERE status='failed'
")
->fetch_assoc()['jumlah'];



$resource =
$conn->query("
SELECT 
resource_type,
COUNT(*) jumlah
FROM satusehat_mapping
GROUP BY resource_type
ORDER BY jumlah DESC
");



$list=[];

while($r=$resource->fetch_assoc()){

$list[]=$r;

}



echo json_encode([

"status"=>"success",

"data"=>[

"total"=>$total,

"sent"=>$sent,

"failed"=>$failed,

"resource"=>$list

]

]);


break;

default:


echo json_encode([
    "status"=>"error",
    "message"=>"Action tidak ditemukan"
]);


}

?>