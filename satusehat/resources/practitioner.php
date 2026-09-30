<?php


function buildPractitionerFHIR($dokter)
{


    return [

        "resourceType"=>"Practitioner",


        "active"=>true,


        "name"=>[

            [

                "use"=>"official",

                "text"=>$dokter['nama_dokter']

            ]

        ],



        "qualification"=>[

            [

                "code"=>[

                    "text"=>$dokter['spesialis'] ?? "Dokter"

                ]

            ]

        ]

    ];

}

?>