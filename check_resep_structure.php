<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');


/*
|--------------------------------------------------------------------------
| DATABASE
|--------------------------------------------------------------------------
*/

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

    echo json_encode([
        "status"=>"error",
        "message"=>"Database gagal koneksi",
        "error"=>$conn->connect_error
    ]);

    exit;

}


/*
|--------------------------------------------------------------------------
| HELPER CEK TABLE
|--------------------------------------------------------------------------
*/

function checkTable($conn,$table){

    $result=$conn->query("
        SHOW TABLES LIKE '$table'
    ");


    if($result->num_rows==0){

        return [
            "exists"=>false
        ];

    }


    $columns=[];


    $col=$conn->query("
        SHOW COLUMNS FROM `$table`
    ");


    while($row=$col->fetch_assoc()){

        $columns[]=[
            "field"=>$row['Field'],
            "type"=>$row['Type'],
            "null"=>$row['Null'],
            "key"=>$row['Key']
        ];

    }


    return [
        "exists"=>true,
        "columns"=>$columns
    ];

}



/*
|--------------------------------------------------------------------------
| CEK DATA SAMPLE
|--------------------------------------------------------------------------
*/

function sampleData($conn,$table,$limit=5){

    $result=$conn->query("
        SELECT *
        FROM `$table`
        LIMIT $limit
    ");


    if(!$result){

        return [
            "error"=>$conn->error
        ];

    }


    $data=[];


    while($row=$result->fetch_assoc()){

        $data[]=$row;

    }


    return $data;

}



/*
|--------------------------------------------------------------------------
| CEK RELASI RESEP
|--------------------------------------------------------------------------
*/


$tables=[

    "rekam_medis",

    "resep",

    "resep_obat_detail",

    "obat",

    "obat_satusehat_mapping",

    "satusehat_mapping"

];



$response=[

    "status"=>"success",

    "database"=>$db,

    "checked_tables"=>[]

];



foreach($tables as $table){

    $response['checked_tables'][$table]=checkTable(
        $conn,
        $table
    );

}



/*
|--------------------------------------------------------------------------
| SAMPLE DATA
|--------------------------------------------------------------------------
*/


foreach($tables as $table){

    if(
        $response['checked_tables'][$table]['exists']
    ){

        $response['checked_tables'][$table]['sample']=sampleData(
            $conn,
            $table,
            3
        );

    }

}



/*
|--------------------------------------------------------------------------
| CEK RELASI REKAM MEDIS
|--------------------------------------------------------------------------
*/


$sql="

SELECT

rm.id AS rekam_medis_id,

rm.id_pendaftaran,

rm.resep_obat,

COUNT(rod.id) AS jumlah_detail_resep


FROM rekam_medis rm


LEFT JOIN resep_obat_detail rod

ON rod.id_rekam_medis = rm.id


GROUP BY rm.id


ORDER BY rm.id DESC


LIMIT 10

";



$result=$conn->query($sql);



$relasi=[];


if($result){

    while($row=$result->fetch_assoc()){

        $relasi[]=$row;

    }

}



$response['rekam_medis_vs_detail_resep']=$relasi;




/*
|--------------------------------------------------------------------------
| CEK RESEP YANG BELUM PUNYA DETAIL
|--------------------------------------------------------------------------
*/


$sql="

SELECT

rm.id,

rm.id_pendaftaran,

rm.resep_obat


FROM rekam_medis rm


LEFT JOIN resep_obat_detail rod

ON rod.id_rekam_medis = rm.id


WHERE 

rm.resep_obat IS NOT NULL

AND rm.resep_obat <> ''

AND rod.id IS NULL


LIMIT 20

";



$result=$conn->query($sql);



$belum_detail=[];


if($result){

    while($row=$result->fetch_assoc()){

        $belum_detail[]=$row;

    }

}



$response['resep_json_tanpa_detail']=$belum_detail;




/*
|--------------------------------------------------------------------------
| CEK MAPPING MEDICATION
|--------------------------------------------------------------------------
*/


$sql="

SELECT

o.id,

o.nama_obat,

osm.kfa_code,

osm.kfa_display


FROM obat o


LEFT JOIN obat_satusehat_mapping osm

ON osm.id_obat=o.id


LIMIT 20

";



$result=$conn->query($sql);



$obatMapping=[];


if($result){

    while($row=$result->fetch_assoc()){

        $obatMapping[]=$row;

    }

}



$response['obat_kfa_mapping']=$obatMapping;



echo json_encode(
    $response,
    JSON_PRETTY_PRINT |
    JSON_UNESCAPED_UNICODE
);
