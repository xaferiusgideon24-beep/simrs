<?php


function generateResepDetailFromJSON(
    $conn,
    $id_rekam_medis
){

    // Ambil resep JSON lama

    $stmt = $conn->prepare("
        SELECT resep_obat
        FROM rekam_medis
        WHERE id=?
        LIMIT 1
    ");

    $stmt->bind_param(
        "i",
        $id_rekam_medis
    );

    $stmt->execute();


    $data = $stmt
        ->get_result()
        ->fetch_assoc();



    if(!$data){

        return [
            "status"=>"error",
            "message"=>"Rekam medis tidak ditemukan"
        ];

    }



    if(empty($data['resep_obat'])){

        return [
            "status"=>"empty",
            "message"=>"Tidak ada resep"
        ];

    }



    $resep=json_decode(
        $data['resep_obat'],
        true
    );



    if(!is_array($resep)){

        return [
            "status"=>"error",
            "message"=>"JSON resep tidak valid"
        ];

    }



    $result=[];



    foreach($resep as $item){


        $id_obat =
            intval($item['id_obat']);



        /*
        Cek apakah sudah pernah dibuat
        */


        $cek=$conn->prepare("
            SELECT id
            FROM resep_obat_detail
            WHERE id_rekam_medis=?
            AND id_obat=?
            LIMIT 1
        ");


        $cek->bind_param(
            "ii",
            $id_rekam_medis,
            $id_obat
        );


        $cek->execute();


        $existing=$cek
            ->get_result()
            ->fetch_assoc();



        if($existing){


            $result[]=[

                "status"=>"skip",

                "id"=>$existing['id'],

                "id_obat"=>$id_obat

            ];


            continue;

        }





        /*
        Pecah aturan:
        contoh:
        3x1 sesudah makan

        frekuensi:
        3x1

        aturan:
        sesudah makan
        */


        $aturan =
            trim(
                $item['aturan'] ?? ''
            );



        $parts =
            explode(
                " ",
                $aturan,
                2
            );



        $frekuensi =
            $parts[0] ?? '';



        $aturan_pakai =
            $parts[1] ?? '';





        $jumlah =
            intval(
                $item['jumlah'] ?? 0
            );



        $dosis =
            ($item['dosis'] ?? '')
            ." mg";





        $insert=$conn->prepare("

            INSERT INTO resep_obat_detail

            (
                id_rekam_medis,
                id_obat,
                jumlah,
                dosis,
                frekuensi,
                aturan_pakai,
                status_resep
            )

            VALUES
            (?,?,?,?,?,?,?)

        ");




        $status="Menunggu";



        $insert->bind_param(

            "iiissss",

            $id_rekam_medis,

            $id_obat,

            $jumlah,

            $dosis,

            $frekuensi,

            $aturan_pakai,

            $status

        );



        if($insert->execute()){


            $result[]=[

                "status"=>"insert",

                "id"=>$insert->insert_id,

                "id_obat"=>$id_obat

            ];

        }


    }



    return [

        "status"=>"success",

        "message"=>"Resep detail berhasil diproses",

        "rekam_medis_id"=>$id_rekam_medis,

        "results"=>$result

    ];


}

?>