<?php

require_once __DIR__ . '/../fhir_client.php';



/**
 * ==========================================================
 * NORMALISASI NAMA
 * ==========================================================
 *
 * Digunakan untuk membandingkan nama lokal
 * dengan nama SATUSEHAT.
 *
 */
function normalizeName($name)
{

    $name = strtolower($name);

    $name = preg_replace(
        '/[^a-z0-9 ]/',
        '',
        $name
    );

    $name = preg_replace(
        '/\s+/',
        ' ',
        $name
    );

    return trim($name);

}




/**
 * ==========================================================
 * VALIDASI PRACTITIONER
 * ==========================================================
 *
 * Mengecek apakah resource SATUSEHAT
 * sesuai dengan dokter lokal.
 *
 */
function validatePractitioner(
    $dokter,
    $resource
){

    $result = [

        "valid"=>true,

        "reason"=>[]

    ];



    /*
    |--------------------------------------------------------------------------
    | Validasi NIK
    |--------------------------------------------------------------------------
    */

    $nikLokal =
        trim($dokter['nik_dokter'] ?? '');



    if(!empty($resource['identifier'])){


        $nikFound=false;



        foreach($resource['identifier'] as $identifier){


            if(
                isset($identifier['system']) &&
                $identifier['system']
                ==
                "https://fhir.kemkes.go.id/id/nik"
            ){

                $nikSatusehat =
                    $identifier['value']
                    ??
                    '';



                /*
                 * SATUSEHAT bisa melakukan masking NIK
                 * sehingga tidak selalu bisa dibandingkan penuh
                 */

                if(
                    strlen($nikSatusehat) > 0 &&
                    str_contains(
                        $nikSatusehat,
                        substr($nikLokal,-3)
                    )
                ){

                    $nikFound=true;

                }

            }


        }



        if(!$nikFound){

            $result['valid']=false;

            $result['reason'][]=
            "NIK tidak cocok";

        }

    }



    /*
    |--------------------------------------------------------------------------
    | Validasi Nama
    |--------------------------------------------------------------------------
    */

    $namaLokal =
        normalizeName(
            $dokter['nama_dokter'] ?? ''
        );


    $namaSATUSEHAT =
        normalizeName(
            $resource['name'][0]['text']
            ??
            ''
        );



    if(
        !empty($namaSATUSEHAT) &&
        strpos(
            $namaSATUSEHAT,
            explode(
                ' ',
                $namaLokal
            )[0]
        ) === false
    ){

        $result['valid']=false;

        $result['reason'][]=
        "Nama tidak cocok";

    }



    /*
    |--------------------------------------------------------------------------
    | Birth Date
    |--------------------------------------------------------------------------
    */

    if(
        !empty($dokter['tanggal_lahir']) &&
        isset($resource['birthDate'])
    ){

        if(
            $dokter['tanggal_lahir']
            !=
            $resource['birthDate']
        ){

            $result['valid']=false;

            $result['reason'][]=
            "Tanggal lahir tidak cocok";

        }

    }



    /*
    |--------------------------------------------------------------------------
    | Gender
    |--------------------------------------------------------------------------
    */

    if(
        !empty($dokter['gender']) &&
        isset($resource['gender'])
    ){

        if(
            strtolower($dokter['gender'])
            !=
            strtolower($resource['gender'])
        ){

            $result['valid']=false;

            $result['reason'][]=
            "Gender tidak cocok";

        }

    }



    return $result;

}





/**
 * ==========================================================
 * SEARCH PRACTITIONER
 * ==========================================================
 *
 * Tidak mengambil entry pertama.
 *
 */
function searchPractitionerSmart(
    $dokter
){

    $nik =
        trim($dokter['nik_dokter'] ?? '');



    if(empty($nik)){

        return [

            "status"=>"error",

            "message"=>"NIK kosong"

        ];

    }



    $response =
    searchPractitionerByNIK($nik);



    if(
        ($response['http_code'] ?? 0)
        != 200
    ){

        return [

            "status"=>"error",

            "message"=>"Request Practitioner gagal",

            "response"=>$response

        ];

    }



    $bundle =
        $response['data']
        ??
        [];



    $total =
        $bundle['total']
        ??
        0;



    if($total == 0){

        return [

            "status"=>"not_found",

            "message"=>"Practitioner tidak ditemukan"

        ];

    }



    if($total > 1){

        return [

            "status"=>"ambiguous",

            "message"=>"Ditemukan lebih dari satu Practitioner",

            "total"=>$total

        ];

    }



    return [

        "status"=>"found",

        "resource"=>$bundle['entry'][0]['resource']

    ];

}





/**
 * ==========================================================
 * SIMPAN HASIL VERIFIKASI
 * ==========================================================
 */
function savePractitionerReference(
    $conn,
    $dokter,
    $resource,
    $status
){

    $ihs =
        $resource['id']
        ??
        null;


    $nama =
        $resource['name'][0]['text']
        ??
        null;



    $stmt=$conn->prepare("
        UPDATE satusehat_practitioner_reference
        SET
            ihs_number=?,
            nama=?,
            status=?
        WHERE dokter_id=?
    ");



    $stmt->bind_param(
        "sssi",
        $ihs,
        $nama,
        $status,
        $dokter['id']
    );



    return $stmt->execute();

}





/**
 * ==========================================================
 * SYNC PRACTITIONER BY DOKTER ID
 * ==========================================================
 */
function syncPractitionerByDokterId(
    $conn,
    $dokter_id
){

    $stmt=$conn->prepare("
        SELECT
            id,
            nik_dokter,
            nama_dokter,
            tanggal_lahir,
            gender
        FROM dokter
        WHERE id=?
        LIMIT 1
    ");



    $stmt->bind_param(
        "i",
        $dokter_id
    );


    $stmt->execute();



    $dokter =
        $stmt->get_result()
        ->fetch_assoc();



    if(!$dokter){

        return [

            "status"=>"error",

            "message"=>"Dokter tidak ditemukan"

        ];

    }




    $search =
        searchPractitionerSmart($dokter);




    if(
        $search['status']
        !=
        "found"
    ){


        $status =
            $search['status']
            ==
            "not_found"
            ?
            "not_found"
            :
            "mismatch";



        savePractitionerReference(
            $conn,
            $dokter,
            [],
            $status
        );



        return $search;

    }





    $resource =
        $search['resource'];



    $validation =
        validatePractitioner(
            $dokter,
            $resource
        );



    if(!$validation['valid']){


        savePractitionerReference(
            $conn,
            $dokter,
            $resource,
            "mismatch"
        );



        return [

            "status"=>"mismatch",

            "message"=>"Identitas Practitioner tidak cocok",

            "detail"=>$validation['reason']

        ];

    }





    savePractitionerReference(
        $conn,
        $dokter,
        $resource,
        "verified"
    );




    return [

        "status"=>"verified",

        "ihs"=>$resource['id'],

        "nama"=>$resource['name'][0]['text'] ?? null

    ];

}



?>