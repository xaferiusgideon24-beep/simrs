<?php
/**
 * test_practitioner_mapping.php
 *
 * Test mapping:
 *
 * FiveCare Dokter
 *        |
 *        |
 * satusehat_practitioner_reference
 *
 */


header('Content-Type: application/json; charset=utf-8');


require_once __DIR__ . '/../../config/database.php';



/**
 * Ambil dokter lokal
 */
function getDokter($conn, $dokter_id)
{
    $stmt = $conn->prepare("
        SELECT
            id,
            nik_dokter,
            nama_dokter,
            tanggal_lahir,
            gender
        FROM dokter
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $dokter_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    return $result->fetch_assoc();
}



/**
 * Ambil mapping practitioner reference
 */
function getPractitionerReference($conn, $dokter_id)
{
    $stmt = $conn->prepare("
        SELECT
            id,
            ihs_number,
            status,
            source
        FROM satusehat_practitioner_reference
        WHERE dokter_id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $dokter_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    return $result->fetch_assoc();
}



/*
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
*/


if (!isset($_GET['dokter_id'])) {

    echo json_encode([
        "status" => "error",
        "message" => "Parameter dokter_id wajib diisi"
    ], JSON_PRETTY_PRINT);

    exit;
}



$dokter_id = intval($_GET['dokter_id']);



$dokter = getDokter(
    $conn,
    $dokter_id
);



if (!$dokter) {

    echo json_encode([
        "status" => "error",
        "message" => "Dokter tidak ditemukan",
        "dokter_id" => $dokter_id
    ], JSON_PRETTY_PRINT);

    exit;

}



$mapping = getPractitionerReference(
    $conn,
    $dokter_id
);



$response = [

    "status" => "success",

    "dokter_local" => [

        "id" => $dokter['id'],

        "nik" => $dokter['nik_dokter'],

        "nama" => $dokter['nama_dokter'],

        "tanggal_lahir" => $dokter['tanggal_lahir'],

        "gender" => $dokter['gender']

    ],

    "satusehat_mapping" => null

];



if ($mapping) {


    $response["satusehat_mapping"] = [

        "reference_id" => $mapping['id'],

        "ihs_number" => $mapping['ihs_number'],

        "status" => $mapping['status'],

        "source" => $mapping['source']

    ];


} else {


    $response["satusehat_mapping"] = [

        "status" => "not_registered",

        "message" => "Dokter belum memiliki mapping SATUSEHAT"

    ];

}



echo json_encode(

    $response,

    JSON_PRETTY_PRINT |

    JSON_UNESCAPED_UNICODE |

    JSON_UNESCAPED_SLASHES

);