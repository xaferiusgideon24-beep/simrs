<?php

function buildPatientFHIR($pasien)
{

    $gender =
        ($pasien['jenis_kelamin'] ?? '') === 'Laki-laki'
        ? 'male'
        : 'female';


    /*
       Untuk testing SATUSEHAT wajib ada administrativeCode.
       Gunakan kode wilayah valid.
       
       Jakarta contoh:
       Provinsi DKI       31
       Kota Jakarta Pusat 3171
    */

    $province = '31';
    $city     = '3171';

    /*
       Karena data lokal belum punya kode kecamatan/kelurahan,
       gunakan contoh kode wilayah dummy yang valid di environment.
       Nanti production harus diganti sesuai alamat pasien.
    */

    $district = '317101';
    $village  = '3171011001';



    return [

        "resourceType" => "Patient",


        "identifier" => [

            [
                "use"=>"official",
                "system"=>"https://fhir.kemkes.go.id/id/nik",
                "value"=>$pasien['nik']
            ]

        ],


        "active"=>true,


        "name"=>[

            [
                "use"=>"official",
                "text"=>$pasien['nama_lengkap']
            ]

        ],


        "gender"=>$gender,


        "birthDate"=>$pasien['tgl_lahir'],


        "multipleBirthBoolean"=>false,


        "address"=>[

            [

                "use"=>"home",

                "text"=>$pasien['alamat'],

                "extension"=>[

                    [

                        "url"=>"https://fhir.kemkes.go.id/r4/StructureDefinition/administrativeCode",

                        "extension"=>[

                            [
                                "url"=>"province",
                                "valueCode"=>$province
                            ],

                            [
                                "url"=>"city",
                                "valueCode"=>$city
                            ],

                            [
                                "url"=>"district",
                                "valueCode"=>$district
                            ],

                            [
                                "url"=>"village",
                                "valueCode"=>$village
                            ]

                        ]

                    ]

                ]

            ]

        ],


        "telecom"=>array_values(

            array_filter([

                !empty($pasien['no_telp'])

                ?

                [

                    "system"=>"phone",
                    "value"=>$pasien['no_telp'],
                    "use"=>"mobile"

                ]

                :

                null,


                !empty($pasien['email'])

                ?

                [

                    "system"=>"email",
                    "value"=>$pasien['email']

                ]

                :

                null


            ])

        )

    ];

}