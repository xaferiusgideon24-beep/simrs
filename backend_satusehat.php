<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {

    http_response_code(200);
    exit;
}


// =====================================================
// DATABASE
// =====================================================

require_once __DIR__ . '/config/database.php';


// =====================================================
// SATUSEHAT CORE
// =====================================================

require_once __DIR__ . '/satusehat/auth.php';

require_once __DIR__ . '/satusehat/fhir_client.php';


// =====================================================
// SATUSEHAT SYNC MODULE
// =====================================================

require_once __DIR__ . '/satusehat/sync/patient/patient_sync.php';

require_once __DIR__ . '/satusehat/sync/organization_sync.php';

require_once __DIR__ . '/satusehat/sync/practitioner_sync.php';

require_once __DIR__ . '/satusehat/sync/practitioner_role_sync.php';



// =====================================================
// RESPONSE JSON
// =====================================================

function responseJSON(
    $data,
    $httpCode = 200
) {

    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT |
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// =====================================================
// ACTION
// =====================================================

$action = $_POST['action'] ?? $_GET['action'] ?? null;


// =====================================================
// STATUS
// =====================================================

if ($action === 'status') {

    responseJSON([

        'status' => 'success',

        'message' =>
            'Backend integrasi SATUSEHAT aktif.',

        'version' =>
            'SATUSEHAT Integration v3',

        'database' =>
            'simrs_fivecare',

        'modules' => [

            'Patient Integration v2',

            'Organization Integration v1',

            'Practitioner Integration v2'

        ]

    ]);
}


// =====================================================
// TOKEN
// =====================================================

if ($action === 'token') {

    try {

        $token = getSatusehatToken();

        responseJSON([

            'status' =>
                'success',

            'message' =>
                'Access Token SATUSEHAT tersedia.',

            'token_length' =>
                strlen($token)

        ]);

    } catch (Throwable $e) {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                $e->getMessage()

        ], 500);
    }
}


// =====================================================
// PATIENT - SEARCH
// =====================================================
//
// Tidak harus terdaftar di database lokal.
//
// Contoh:
//
// ?action=search_patient
// &nik=0092000000000032
//
// =====================================================

if ($action === 'search_patient') {

    $nik = trim(
        $_GET['nik'] ?? ''
    );


    if ($nik === '') {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Parameter nik wajib diisi.'

        ], 400);
    }


    if (
        !function_exists(
            'searchPatientSatusehatByNik'
        )
    ) {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Fungsi searchPatientSatusehatByNik tidak ditemukan.',

            'file' =>
                'satusehat/sync/patient_sync.php'

        ], 500);
    }


    $result =
        searchPatientSatusehatByNik(
            $nik
        );


    if (
        ($result['status'] ?? '') ===
        'error'
    ) {

        responseJSON(
            $result,
            502
        );
    }


    responseJSON(
        $result,
        200
    );
}


// =====================================================
// PATIENT - PREVIEW
// =====================================================

if ($action === 'preview_patient') {

    $nik = trim(
        $_GET['nik'] ?? ''
    );


    if ($nik === '') {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Parameter nik wajib diisi.'

        ], 400);
    }


    if (
        !function_exists(
            'previewPatientByNik'
        )
    ) {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Fungsi previewPatientByNik tidak ditemukan.',

            'file' =>
                'satusehat/sync/patient_sync.php'

        ], 500);
    }


    $result =
        previewPatientByNik(
            $conn,
            $nik
        );


    if (
        ($result['status'] ?? '') ===
        'error'
    ) {

        responseJSON(
            $result,
            404
        );
    }


    responseJSON(
        $result,
        200
    );
}


// =====================================================
// PATIENT - SYNC / MAPPING
// =====================================================

if ($action === 'sync_patient') {

    $nik = trim(
        $_GET['nik'] ?? ''
    );


    if ($nik === '') {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Parameter nik wajib diisi.'

        ], 400);
    }


    if (
        !function_exists(
            'syncPatientByNik'
        )
    ) {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Fungsi syncPatientByNik tidak ditemukan.',

            'file' =>
                'satusehat/sync/patient_sync.php'

        ], 500);
    }


    $result =
        syncPatientByNik(
            $conn,
            $nik
        );


    $status =
        $result['status'] ??
        'error';


    if (
        $status === 'already_sync' ||
        $status === 'existing_patient'
    ) {

        responseJSON(
            $result,
            200
        );
    }


    if (
        $status === 'local_patient_not_found' ||
        $status === 'not_found_satusehat'
    ) {

        responseJSON(
            $result,
            404
        );
    }


    responseJSON(
        $result,
        500
    );
}


// =====================================================
// PATIENT - CHECK MAPPING
// =====================================================

if ($action === 'check_patient_mapping') {

    $nik = trim(
        $_GET['nik'] ?? ''
    );


    if ($nik === '') {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Parameter nik wajib diisi.'

        ], 400);
    }


    if (
        !function_exists(
            'getPatientMapping'
        )
    ) {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Fungsi getPatientMapping tidak ditemukan.',

            'file' =>
                'satusehat/sync/patient_sync.php'

        ], 500);
    }


    $mapping =
        getPatientMapping(
            $conn,
            $nik
        );


    if (!$mapping) {

        responseJSON([

            'status' =>
                'not_mapped',

            'message' =>
                'Patient belum mempunyai mapping SATUSEHAT.',

            'nik' =>
                $nik

        ], 200);
    }


    responseJSON([

        'status' =>
            'mapped',

        'message' =>
            'Mapping Patient ditemukan.',

        'data' =>
            $mapping

    ], 200);
}


// =====================================================
// ORGANIZATION - GET BY ID
// =====================================================
//
// Contoh:
//
// ?action=get_organization
// &id=10000001
//
// =====================================================

if ($action === 'get_organization') {

    $organizationId = trim(
        $_GET['id'] ?? ''
    );


    if ($organizationId === '') {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Parameter id Organization wajib diisi.'

        ], 400);
    }


    if (
        !function_exists(
            'getOrganizationSatusehatById'
        )
    ) {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Fungsi getOrganizationSatusehatById tidak ditemukan.',

            'file' =>
                'satusehat/sync/organization_sync.php'

        ], 500);
    }


    $result =
        getOrganizationSatusehatById(
            $organizationId
        );


    $status =
        $result['status'] ??
        'error';


    if ($status === 'found') {

        responseJSON(
            $result,
            200
        );
    }


    if ($status === 'not_found') {

        responseJSON(
            $result,
            404
        );
    }


    responseJSON(
        $result,
        502
    );
}


// =====================================================
// PRACTITIONER - SEARCH BY NIK
// =====================================================
//
// Tidak membutuhkan dokter lokal.
//
// Bisa dipakai untuk data dummy SATUSEHAT.
//
// Contoh:
//
// ?action=search_practitioner
// &nik=7209061211900001
//
// =====================================================

if ($action === 'search_practitioner') {

    $nik = trim(
        $_GET['nik'] ?? ''
    );


    if ($nik === '') {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Parameter nik Practitioner wajib diisi.'

        ], 400);
    }


    if (
        !function_exists(
            'searchPractitionerSatusehatByNik'
        )
    ) {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Fungsi searchPractitionerSatusehatByNik tidak ditemukan.',

            'file' =>
                'satusehat/sync/practitioner_sync.php'

        ], 500);
    }


    $result =
        searchPractitionerSatusehatByNik(
            $nik
        );


    if (
        ($result['status'] ?? '') ===
        'error'
    ) {

        responseJSON(
            $result,
            502
        );
    }


    responseJSON(
        $result,
        200
    );
}


// =====================================================
// PRACTITIONER - SYNC DOKTER LOKAL
// =====================================================
//
// dokter.id -> nik_dokter
// -> GET Practitioner
// -> satusehat_mapping
//
// Contoh:
//
// ?action=sync_practitioner&id=1
//
// =====================================================

if ($action === 'sync_practitioner') {

    $dokterId =
        intval(
            $_GET['id'] ?? 0
        );


    if ($dokterId <= 0) {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Parameter id dokter wajib diisi.'

        ], 400);
    }


    if (
        !function_exists(
            'syncPractitionerByDokterId'
        )
    ) {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Fungsi syncPractitionerByDokterId tidak ditemukan.',

            'file' =>
                'satusehat/sync/practitioner_sync.php'

        ], 500);
    }


    $result =
        syncPractitionerByDokterId(
            $conn,
            $dokterId
        );


    $status =
        $result['status'] ??
        'error';


    if (
        $status === 'already_sync' ||
        $status === 'existing_practitioner'
    ) {

        responseJSON(
            $result,
            200
        );
    }


    if (
        $status === 'local_doctor_not_found' ||
        $status === 'not_found_satusehat'
    ) {

        responseJSON(
            $result,
            404
        );
    }


    if (
        $status === 'nik_not_set' ||
        $status === 'invalid_nik'
    ) {

        responseJSON(
            $result,
            422
        );
    }


    responseJSON(
        $result,
        500
    );
}


// =====================================================
// PRACTITIONER - CHECK MAPPING
// =====================================================
//
// Contoh:
//
// ?action=check_practitioner_mapping&id=1
//
// =====================================================

if ($action === 'check_practitioner_mapping') {

    $dokterId =
        intval(
            $_GET['id'] ?? 0
        );


    if ($dokterId <= 0) {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Parameter id dokter wajib diisi.'

        ], 400);
    }


    if (
        !function_exists(
            'getPractitionerMapping'
        )
    ) {

        responseJSON([

            'status' =>
                'error',

            'message' =>
                'Fungsi getPractitionerMapping tidak ditemukan.',

            'file' =>
                'satusehat/sync/practitioner_sync.php'

        ], 500);
    }


    $mapping =
        getPractitionerMapping(
            $conn,
            $dokterId
        );


    if (!$mapping) {

        responseJSON([

            'status' =>
                'not_mapped',

            'message' =>
                'Dokter belum mempunyai mapping Practitioner SATUSEHAT.',

            'dokter_id' =>
                $dokterId

        ], 200);
    }


    responseJSON([

        'status' =>
            'mapped',

        'message' =>
            'Mapping Practitioner ditemukan.',

        'data' =>
            $mapping

    ], 200);
}

// =====================================================
// PRACTITIONER - SEARCH CANDIDATES BY NIK
// =====================================================

if ($action === 'search_practitioner_candidates') {

    $nik = trim($_GET['nik'] ?? '');

    if ($nik === '') {
        responseJSON([
            'status' => 'error',
            'message' => 'Parameter nik wajib diisi.'
        ], 400);
    }

    if (!function_exists('searchPractitionerCandidatesByNik')) {
        responseJSON([
            'status' => 'error',
            'message' => 'Fungsi searchPractitionerCandidatesByNik tidak ditemukan.'
        ], 500);
    }

    responseJSON(
        searchPractitionerCandidatesByNik($nik)
    );
}


// =====================================================
// PRACTITIONER - SAVE MAPPING VIA BROWSER
// =====================================================

if ($action === 'save_practitioner_mapping') {

    $dokterId = intval($_GET['dokter_id'] ?? 0);
    $satusehatId = trim($_GET['satusehat_id'] ?? '');
    $nik = trim($_GET['nik'] ?? '');
    $nama = trim($_GET['nama_satusehat'] ?? '');

    if (!$dokterId || !$satusehatId) {

        responseJSON([
            "status" => "error",
            "message" => "dokter_id dan satusehat_id wajib."
        ], 400);

    }

    if (!function_exists('savePractitionerMapping')) {

        responseJSON([
            "status" => "error",
            "message" => "Fungsi savePractitionerMapping tidak ditemukan."
        ], 500);

    }

    $save = savePractitionerMapping(
        $conn,
        $dokterId,
        $satusehatId,
        $nik,
        $nama
    );

    if ($save) {

        responseJSON([
            "status" => "success",
            "message" => "Mapping Practitioner berhasil disimpan."
        ]);

    }

    responseJSON([
        "status" => "error",
        "message" => "Gagal menyimpan mapping."
    ], 500);
}

if ($action === 'sync_practitioner_role') {

    $dokterId =
    intval($_GET['id'] ?? 0);


    if (!$dokterId) {

        responseJSON([
            "status"=>"error",
            "message"=>"Parameter id dokter wajib."
        ],400);

    }


    $result =
    syncPractitionerRoleByDokterId(
        $conn,
        $dokterId
    );


    responseJSON($result);

}
// =====================================================
// CLINICAL SYNC (Encounter, Condition, Observation, MedicationRequest)
// =====================================================
//
// Contoh:
// ?action=sync_clinical_visit&pendaftaran_id=110
// =====================================================

require_once __DIR__ . '/satusehat/sync/encounter_sync.php';
require_once __DIR__ . '/satusehat/sync/condition/condition_sync.php';
require_once __DIR__ . '/satusehat/sync/observation/observation_sync.php';
require_once __DIR__ . '/satusehat/sync/medication/medication_request_sync.php';
require_once __DIR__ . '/satusehat/sync/medication/sync_resep_detail.php';

if ($action === 'sync_clinical_visit') {


    $pendaftaranId = intval(
        $_POST['pendaftaran_id']
        ?? 
         $_GET['pendaftaran_id']
         ?? 
         0
        );



    if ($pendaftaranId <= 0) {

        responseJSON([

            'status'=>'error',

            'message'=>'Parameter pendaftaran_id wajib diisi.'

        ],400);

    }



    /*
    |--------------------------------------------------------------------------
    | Ambil Rekam Medis
    |--------------------------------------------------------------------------
    */


    $stmtRM=$conn->prepare("
        SELECT id
        FROM rekam_medis
        WHERE id_pendaftaran=?
        LIMIT 1
    ");


    $stmtRM->bind_param(
        "i",
        $pendaftaranId
    );


    $stmtRM->execute();


    $rekamMedis=$stmtRM
        ->get_result()
        ->fetch_assoc();



    if(!$rekamMedis){

        responseJSON([

            "status"=>"error",

            "message"=>"Rekam medis tidak ditemukan"

        ],404);

    }



    $rekamMedisId =
        $rekamMedis['id'];





    /*
    |--------------------------------------------------------------------------
    | 1. Encounter
    |--------------------------------------------------------------------------
    */


    $resEncounter =
        syncEncounterByPendaftaranId(
            $conn,
            $pendaftaranId
        );





    /*
    |--------------------------------------------------------------------------
    | 2. Condition
    |--------------------------------------------------------------------------
    */


    $resCondition =
        syncConditionByRekamMedisId(
            $conn,
            $rekamMedisId
        );






    /*
    |--------------------------------------------------------------------------
    | 3. Observation
    |--------------------------------------------------------------------------
    */


    $stmtVS=$conn->prepare("
        SELECT id
        FROM vital_sign
        WHERE id_pendaftaran=?
        LIMIT 1
    ");


    $stmtVS->bind_param(
        "i",
        $pendaftaranId
    );


    $stmtVS->execute();


    $vital=$stmtVS
        ->get_result()
        ->fetch_assoc();



    $resObservation=null;


    if($vital){

        $resObservation =
            syncObservationByPendaftaranId(
                $conn,
                $pendaftaranId
            );

    }





    /*
    |--------------------------------------------------------------------------
    | 4. Generate resep_obat_detail
    |--------------------------------------------------------------------------
    */


    $resResepDetail =
        generateResepDetailFromJSON(
            $conn,
            $rekamMedisId
        );







    /*
    |--------------------------------------------------------------------------
    | 5. MedicationRequest
    |--------------------------------------------------------------------------
    */


    $resMedicationRequest =
        syncMedicationRequestByRekamMedisId(
            $conn,
            $rekamMedisId
        );







    responseJSON([

        "status"=>"success",

        "message"=>

            "Sync clinical visit selesai",

        "pendaftaran_id"=>

            $pendaftaranId,


        "rekam_medis_id"=>

            $rekamMedisId,


        "results"=>[


            "Encounter"=>

                $resEncounter,


            "Condition"=>

                $resCondition,


            "Observation"=>

                $resObservation,


            "Generate Resep Detail"=>

                $resResepDetail,


            "MedicationRequest"=>

                $resMedicationRequest


        ]

    ]);

}

// =====================================================
// ACTION TIDAK DITEMUKAN
// =====================================================

responseJSON([

    'status' =>
        'error',

    'message' =>
        'Action SATUSEHAT tidak ditemukan.',

    'version' =>
        'SATUSEHAT Integration v3',

    'available_actions' => [

        'status',

        'token',

        'search_patient',

        'preview_patient',

        'sync_patient',

        'check_patient_mapping',

        'get_organization',

        'search_practitioner',

        'sync_practitioner',

        'check_practitioner_mapping',

        'search_practitioner_candidates',

        'save_practitioner_mapping',

        'sync_clinical_visit'

    ]

], 400);



?>