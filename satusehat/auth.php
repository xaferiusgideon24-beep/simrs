<?php

require_once __DIR__ . '/config.php';


/**
 * =========================================================
 * SATUSEHAT TOKEN CACHE FILE
 * =========================================================
 */
function getSatusehatTokenCacheFile()
{
    return __DIR__ . '/token_cache.json';
}


/**
 * =========================================================
 * CLIENT ID FINGERPRINT
 * =========================================================
 *
 * Digunakan supaya token cache otomatis dianggap tidak valid
 * jika CLIENT_ID di config.php berubah.
 *
 * Client ID asli tidak ditulis ke log/cache fingerprint.
 */
function getSatusehatClientFingerprint()
{
    return hash(
        'sha256',
        trim(SATUSEHAT_CLIENT_ID)
    );
}


/**
 * =========================================================
 * BACA TOKEN CACHE
 * =========================================================
 */
function readSatusehatTokenCache()
{
    $file =
        getSatusehatTokenCacheFile();


    if (!file_exists($file)) {

        return null;
    }


    $content =
        file_get_contents($file);


    if ($content === false) {

        return null;
    }


    $data =
        json_decode(
            $content,
            true
        );


    if (!is_array($data)) {

        return null;
    }


    return $data;
}


/**
 * =========================================================
 * SIMPAN TOKEN CACHE
 * =========================================================
 */
function saveSatusehatTokenCache(
    $accessToken,
    $expiresIn
) {

    $file =
        getSatusehatTokenCacheFile();


    $expiresIn =
        intval($expiresIn);


    if ($expiresIn <= 0) {

        $expiresIn = 300;
    }


    /**
     * Dikurangi 60 detik supaya token tidak dipakai
     * terlalu dekat dengan waktu expired.
     */
    $expiresAt =
        time() +
        $expiresIn -
        60;


    $data = [

        'access_token' =>
            $accessToken,

        'expires_at' =>
            $expiresAt,

        'client_id_hash' =>
            getSatusehatClientFingerprint(),

        'created_at' =>
            date('Y-m-d H:i:s')
    ];


    file_put_contents(
        $file,
        json_encode(
            $data,
            JSON_PRETTY_PRINT |
            JSON_UNESCAPED_SLASHES
        ),
        LOCK_EX
    );
}


/**
 * =========================================================
 * HAPUS TOKEN CACHE
 * =========================================================
 */
function clearSatusehatTokenCache()
{
    $file =
        getSatusehatTokenCacheFile();


    file_put_contents(
        $file,
        '{}',
        LOCK_EX
    );
}


/**
 * =========================================================
 * CEK APAKAH TOKEN CACHE MASIH VALID
 * =========================================================
 */
function isSatusehatTokenCacheValid($cache)
{

    if (!is_array($cache)) {

        return false;
    }


    if (
        empty(
            $cache['access_token']
        )
    ) {

        return false;
    }


    if (
        empty(
            $cache['expires_at']
        )
    ) {

        return false;
    }


    /**
     * Token expired.
     */
    if (
        time() >=
        intval(
            $cache['expires_at']
        )
    ) {

        return false;
    }


    /**
     * Sangat penting:
     *
     * Token cache hanya boleh dipakai jika berasal
     * dari CLIENT_ID yang sedang aktif.
     */
    $currentFingerprint =
        getSatusehatClientFingerprint();


    $cachedFingerprint =
        $cache['client_id_hash'] ?? '';


    if (
        !hash_equals(
            $currentFingerprint,
            $cachedFingerprint
        )
    ) {

        return false;
    }


    return true;
}


/**
 * =========================================================
 * REQUEST TOKEN BARU KE SATUSEHAT
 * =========================================================
 */
function requestNewSatusehatToken()
{

    $clientId =
        trim(
            SATUSEHAT_CLIENT_ID
        );


    $clientSecret =
        trim(
            SATUSEHAT_CLIENT_SECRET
        );


    if (
        $clientId === '' ||
        $clientSecret === ''
    ) {

        throw new Exception(
            'Client ID atau Client Secret SATUSEHAT kosong.'
        );
    }


    /**
     * Sesuai Generate Token SATUSEHAT:
     *
     * POST:
     *
     * /accesstoken?grant_type=client_credentials
     *
     * Body:
     *
     * client_id
     * client_secret
     */
    $url =
        rtrim(
            SATUSEHAT_AUTH_URL,
            '/'
        )
        .
        '?grant_type=client_credentials';


    $postData =
        http_build_query(
            [
                'client_id' =>
                    $clientId,

                'client_secret' =>
                    $clientSecret
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );


    $curl =
        curl_init();


    curl_setopt_array(
        $curl,
        [

            CURLOPT_URL =>
                $url,

            CURLOPT_POST =>
                true,

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_HTTPHEADER => [

                'Content-Type: application/x-www-form-urlencoded',

                'Accept: application/json'

            ],

            CURLOPT_POSTFIELDS =>
                $postData,

            CURLOPT_SSL_VERIFYPEER =>
                false,

            CURLOPT_SSL_VERIFYHOST =>
                false,

            CURLOPT_CONNECTTIMEOUT =>
                20,

            CURLOPT_TIMEOUT =>
                60
        ]
    );


    $response =
        curl_exec($curl);


    $httpCode =
        curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );


    $curlError =
        curl_error($curl);


    curl_close($curl);


    if ($response === false) {

        throw new Exception(
            'cURL SATUSEHAT gagal: ' .
            $curlError
        );
    }


    $data =
        json_decode(
            $response,
            true
        );


    if ($httpCode !== 200) {

        throw new Exception(
            'Generate token SATUSEHAT gagal. HTTP ' .
            $httpCode .
            '. Response: ' .
            $response
        );
    }


    if (
        !is_array($data) ||
        empty(
            $data['access_token']
        )
    ) {

        throw new Exception(
            'SATUSEHAT tidak mengembalikan access_token.'
        );
    }


    $accessToken =
        $data['access_token'];


    $expiresIn =
        $data['expires_in'] ??
        300;


    saveSatusehatTokenCache(
        $accessToken,
        $expiresIn
    );


    return $accessToken;
}


/**
 * =========================================================
 * GET SATUSEHAT TOKEN
 * =========================================================
 */
function getSatusehatToken()
{

    $cache =
        readSatusehatTokenCache();


    if (
        isSatusehatTokenCacheValid(
            $cache
        )
    ) {

        return
            $cache['access_token'];
    }


    return
        requestNewSatusehatToken();
}

?>