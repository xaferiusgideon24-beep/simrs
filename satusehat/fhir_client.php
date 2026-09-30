<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';


function fhirRequest(
    $method,
    $resource,
    $body = null,
    $query = []
){

    $token = getSatusehatToken();

    if(empty($token)){
        return [
            "http_code"=>0,
            "error"=>"Token kosong"
        ];
    }


    $url =
        rtrim(SATUSEHAT_FHIR_URL,'/')
        .
        '/'
        .
        ltrim($resource,'/');


    if(!empty($query)){

        $url .= '?'.http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

    }



    $curl=curl_init();


    curl_setopt_array(
        $curl,
        [

            CURLOPT_URL=>$url,

            CURLOPT_RETURNTRANSFER=>true,

            CURLOPT_CUSTOMREQUEST=>strtoupper($method),

            CURLOPT_HTTPHEADER=>[

                "Authorization: Bearer ".$token,

                "Content-Type: application/json",

                "Accept: application/json"

            ],

            CURLOPT_SSL_VERIFYPEER=>false,

            CURLOPT_SSL_VERIFYHOST=>false,

            CURLOPT_TIMEOUT=>60

        ]
    );



    $final_json=null;


    if($body!==null){

        $final_json=json_encode(
            $body,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );


        file_put_contents(
            __DIR__.'/REAL_REQUEST_SENT.json',
            $final_json
        );


        curl_setopt(
            $curl,
            CURLOPT_POSTFIELDS,
            $final_json
        );

    }



    $response=curl_exec($curl);


    $httpCode=curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );


    $curlError=curl_error($curl);


    curl_close($curl);



    file_put_contents(
        __DIR__.'/REAL_RESPONSE.json',
        $response
    );



    return [

        "http_code"=>$httpCode,

        "data"=>json_decode(
            $response,
            true
        ),

        "raw"=>$response,

        "curl_error"=>$curlError,

        "request_url"=>$url,

        "sent"=>$final_json

    ];

}