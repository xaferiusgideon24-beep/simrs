<?php
// backend_dokter.php - Simpan ke tabel rekam_medis + INTEGRASI SATUSEHAT CLOUD
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__.'/satusehat/sync/clinical_visit_service.php';



session_start();
date_default_timezone_set('Asia/Jakarta');
$today = date('Y-m-d');

if (!isset($_GET['id_dokter']) && isset($_SESSION['id_dokter'])) {
    $_GET['id_dokter'] = $_SESSION['id_dokter'];
}

if (!isset($_POST['id_dokter']) && isset($_SESSION['id_dokter'])) {
    $_POST['id_dokter'] = $_SESSION['id_dokter'];
}

$host = 'localhost'; $user = 'root'; $pass = ''; $db = 'simrs_fivecare';

try {
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) throw new Exception("DB Error");
} catch (Exception $e) { http_response_code(500); echo json_encode(['status'=>'error']); exit; }

function jsonResponse($status, $message, $data = null) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]); exit;
}

function get_poli_blm($conn, $id_poli) {
    $stmt = $conn->prepare("SELECT biaya_medis_standar FROM poli WHERE id = ?");
    $stmt->bind_param("i", $id_poli);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data ? (float)$data['biaya_medis_standar'] : 150000.00; 
}

$method = $_SERVER['REQUEST_METHOD'];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        
        case 'get_dashboard':
            $id = $_GET['id_dokter'] ?? ($_SESSION['id_dokter'] ?? 0); $today=date('Y-m-d');
            $qTotal=$conn->query("SELECT count(*) as c FROM pendaftaran WHERE id_dokter='$id' AND tgl_kunjungan='$today'");
            $qWait=$conn->query("SELECT count(*) as c FROM pendaftaran WHERE id_dokter='$id' AND tgl_kunjungan<='$today' AND status IN ('Menunggu Pemeriksaan', 'Sedang Pemeriksaan')");
            $qDone=$conn->query("SELECT count(*) as c FROM pendaftaran WHERE id_dokter='$id' AND tgl_kunjungan='$today' AND status NOT IN ('Menunggu Pemeriksaan','Sedang Pemeriksaan')");
            echo json_encode(['total'=>$qTotal->fetch_assoc()['c'],'menunggu'=>$qWait->fetch_assoc()['c'],'selesai'=>$qDone->fetch_assoc()['c']]);
            break;

        case 'get_antrian':
            $id = $_GET['id_dokter'] ?? ($_SESSION['id_dokter'] ?? 0); 
        
            $today = date('Y-m-d');
            $sql="SELECT p.*, pas.nama_lengkap, pas.no_rm, pas.tgl_lahir, pas.jenis_kelamin, d.id_poli
                  FROM pendaftaran p 
                  JOIN pasien pas ON p.nik_pasien=pas.nik 
                  JOIN dokter d ON p.id_dokter=d.id
                  WHERE p.id_dokter='$id' AND p.tgl_kunjungan<='$today' AND p.status IN ('Menunggu Pemeriksaan','Sedang Pemeriksaan') 
                  ORDER BY p.status DESC, p.tgl_kunjungan ASC, p.no_antrian ASC";
            $res=$conn->query($sql); $d=[]; 
            while($r=$res->fetch_assoc()){ 
                $r['umur']=(new DateTime($r['tgl_lahir']))->diff(new DateTime())->y; 
                
                if (empty($r['biaya_medis_dasar']) || $r['biaya_medis_dasar'] == 0) {
                    $r['biaya_medis_dasar'] = get_poli_blm($conn, $r['id_poli']);
                    $conn->query("UPDATE pendaftaran SET biaya_medis_dasar = {$r['biaya_medis_dasar']} WHERE id = {$r['id']}");
                }
                $d[]=$r; 
            }
            echo json_encode($d);
            break;

        case 'start_examination':

    $id = $_POST['id_pendaftaran'];

    // Ambil poli untuk biaya dasar
    $p_data = $conn->query("
        SELECT id_poli 
        FROM pendaftaran 
        WHERE id='$id'
    ")->fetch_assoc();

    $id_poli = $p_data['id_poli'];

    $biaya_medis_dasar = get_poli_blm($conn, $id_poli);


    // Update status pemeriksaan
    $stmt = $conn->prepare("
        UPDATE pendaftaran 
        SET 
            status='Sedang Pemeriksaan',
            biaya_medis_dasar=?
        WHERE id=?
    ");

    $stmt->bind_param(
        "di",
        $biaya_medis_dasar,
        $id
    );

    $stmt->execute();



    // Cek apakah rekam medis sudah pernah dibuat
    $cek = $conn->prepare("
        SELECT id
        FROM rekam_medis
        WHERE id_pendaftaran=?
    ");

    $cek->bind_param(
        "i",
        $id
    );

    $cek->execute();

    $hasil = $cek->get_result();


    // Jika belum ada, buat rekam medis baru
    if($hasil->num_rows == 0){

        $insert = $conn->prepare("
            INSERT INTO rekam_medis
            (
                id_pendaftaran,
                tgl_periksa,
                keluhan_utama,
                pemeriksaan_fisik,
                diagnosa_utama,
                diagnosa_sekunder,
                rencana_terapi,
                resep_obat,
                instruksi_pasien
            )
            VALUES
            (
                ?,
                NOW(),
                '',
                '',
                '',
                '',
                '',
                '[]',
                ''
            )
        ");

        $insert->bind_param(
            "i",
            $id
        );

        $insert->execute();
    }


    jsonResponse(
        'success',
        'Status Updated, Biaya Dasar Tercatat, dan Rekam Medis Dibuat'
    );

break;

        case 'cancel_examination':
            $id = $_POST['id_pendaftaran'];
            $conn->query("UPDATE pendaftaran SET status='Menunggu Pemeriksaan' WHERE id='$id'");
            jsonResponse('success', 'Status dikembalikan ke Menunggu');
            break;

        case 'get_existing_rm':
            $id_pendaftaran = $_GET['id_pendaftaran'] ?? 0;
            $sql = "SELECT 
rm.keluhan_utama,
rm.pemeriksaan_fisik,
rm.diagnosa_utama,
rm.diagnosa_sekunder,
rm.rencana_terapi,
rm.resep_obat,
rm.instruksi_pasien,
p.biaya_medis_dasar,

vs.suhu,
vs.sistolik,
vs.diastolik,
vs.nadi,
vs.respiratory_rate,
vs.berat_badan,
vs.tinggi_badan

FROM rekam_medis rm

JOIN pendaftaran p 
ON rm.id_pendaftaran = p.id

LEFT JOIN vital_sign vs
ON vs.id_pendaftaran = p.id
                    WHERE rm.id_pendaftaran = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id_pendaftaran);
            $stmt->execute();
            $result = $stmt->get_result();
            $rm_data = $result->fetch_assoc();
            $stmt->close();

            if ($rm_data) {
                $rm_data['diagnosa_utama_display'] = '';
                if ($rm_data['diagnosa_utama']) {
                    $d = $conn->query("SELECT display FROM icd_10 WHERE code='{$rm_data['diagnosa_utama']}'")->fetch_assoc();
                    $rm_data['diagnosa_utama_display'] = $d ? "{$rm_data['diagnosa_utama']} - {$d['display']}" : $rm_data['diagnosa_utama'];
                }

                $rm_data['diagnosa_sekunder_display'] = '';
                if ($rm_data['diagnosa_sekunder']) {
                    $d = $conn->query("SELECT display FROM icd_10 WHERE code='{$rm_data['diagnosa_sekunder']}'")->fetch_assoc();
                    $rm_data['diagnosa_sekunder_display'] = $d ? "{$rm_data['diagnosa_sekunder']} - {$d['display']}" : $rm_data['diagnosa_sekunder'];
                }

                jsonResponse('success', 'Data RM ditemukan', $rm_data);
            } else {
                jsonResponse('error', 'RM belum dimulai');
            }
            break;

        // --- SIMPAN REKAM MEDIS + REVISI PELEMPARAN DATA OTOMATIS KE SATUSEHAT ---
        case 'periksa_pasien':

    $id = $_POST['id_pendaftaran'] ?? null;

    $kel = $_POST['keluhan_utama'] ?? '';
    $fis = $_POST['pemeriksaan_fisik'] ?? '';

    $d1 = $_POST['diagnosa_utama_kode'] ?? '';
    $d2 = $_POST['diagnosa_sekunder_kode'] ?? '';

    $ter = $_POST['rencana_terapi'] ?? '';
    $ins = $_POST['instruksi_pasien'] ?? '';
    $res = $_POST['resep'] ?? '[]';

    if(empty($res)){
    $res = '[]';
    }

    if(!$id){
        jsonResponse(
            'error',
            'ID pendaftaran tidak ditemukan'
        );
        break;
    }


    // Ambil data pendaftaran
    $stmt = $conn->prepare("
        SELECT 
            biaya_medis_dasar,
            nik_pasien
        FROM pendaftaran
        WHERE id=?
    ");

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    $pendaftaran_data = $stmt
        ->get_result()
        ->fetch_assoc();


    if(!$pendaftaran_data){

        jsonResponse(
            'error',
            'Data pendaftaran tidak ditemukan'
        );

        break;
    }



    $conn->begin_transaction();


    try {


        // Cek rekam medis
        $cek = $conn->prepare("
            SELECT id
            FROM rekam_medis
            WHERE id_pendaftaran=?
        ");

        $cek->bind_param(
            "i",
            $id
        );

        $cek->execute();

        $rm = $cek->get_result();



        if($rm->num_rows > 0){


            // UPDATE rekam medis

            $stmt = $conn->prepare("
                UPDATE rekam_medis SET

                    keluhan_utama=?,
                    pemeriksaan_fisik=?,
                    diagnosa_utama=?,
                    diagnosa_sekunder=?,
                    rencana_terapi=?,
                    resep_obat=?,
                    instruksi_pasien=?

                WHERE id_pendaftaran=?
            ");


            $stmt->bind_param(
                "sssssssi",
                $kel,
                $fis,
                $d1,
                $d2,
                $ter,
                $res,
                $ins,
                $id
            );


            $message =
            "Rekam Medis berhasil diperbarui";


        } else {


            // INSERT rekam medis baru

            $stmt = $conn->prepare("
                INSERT INTO rekam_medis
                (
                    id_pendaftaran,
                    keluhan_utama,
                    pemeriksaan_fisik,
                    diagnosa_utama,
                    diagnosa_sekunder,
                    rencana_terapi,
                    resep_obat,
                    instruksi_pasien
                )
                VALUES
                (?,?,?,?,?,?,?,?)
            ");


            $stmt->bind_param(
                "isssssss",
                $id,
                $kel,
                $fis,
                $d1,
                $d2,
                $ter,
                $res,
                $ins
            );


            $message =
            "Rekam Medis berhasil dibuat";

        }



        if(!$stmt->execute()){

            throw new Exception(
                "Gagal menyimpan rekam medis : ".$stmt->error
            );

        }




        // Update status pasien

        $update = $conn->prepare("
            UPDATE pendaftaran
            SET status='Menunggu Obat'
            WHERE id=?
        ");


        $update->bind_param(
            "i",
            $id
        );


        if(!$update->execute()){

            throw new Exception(
                "Gagal update status pasien"
            );

        }




        $conn->commit();



// ===============================
// SIMPAN VITAL SIGN
// ===============================

$suhu = $_POST['suhu'] ?? null;
$sistolik = $_POST['sistolik'] ?? null;
$diastolik = $_POST['diastolik'] ?? null;
$nadi = $_POST['nadi'] ?? null;
$respiratory_rate = $_POST['respiratory_rate'] ?? null;
$berat_badan = $_POST['berat_badan'] ?? null;
$tinggi_badan = $_POST['tinggi_badan'] ?? null;

if (
    $suhu !== null ||
    $sistolik !== null ||
    $diastolik !== null ||
    $nadi !== null ||
    $respiratory_rate !== null ||
    $berat_badan !== null ||
    $tinggi_badan !== null
) {
    $cekVital=$conn->prepare("
    SELECT id
    FROM vital_sign
    WHERE id_pendaftaran=?
    LIMIT 1
");

$cekVital->bind_param(
    "i",
    $id
);

$cekVital->execute();

$vitalExist =
$cekVital
->get_result()
->fetch_assoc();


if($vitalExist){

    $stmtVital=$conn->prepare("
        UPDATE vital_sign SET

        suhu=?,
        sistolik=?,
        diastolik=?,
        nadi=?,
        respiratory_rate=?,
        berat_badan=?,
        tinggi_badan=?

        WHERE id_pendaftaran=?

    ");

    $stmtVital->bind_param(
        "diiiiddi",
        $suhu,
        $sistolik,
        $diastolik,
        $nadi,
        $respiratory_rate,
        $berat_badan,
        $tinggi_badan,
        $id
    );


}else{

    $stmtVital=$conn->prepare("
        INSERT INTO vital_sign
        (
        id_pendaftaran,
        suhu,
        sistolik,
        diastolik,
        nadi,
        respiratory_rate,
        berat_badan,
        tinggi_badan
        )

        VALUES(?,?,?,?,?,?,?,?)
    ");


    $stmtVital->bind_param(
        "idiiiidd",
        $id,
        $suhu,
        $sistolik,
        $diastolik,
        $nadi,
        $respiratory_rate,
        $berat_badan,
        $tinggi_badan
    );

}

    $stmtVital->execute();
}

// ===============================
// SYNC SATUSEHAT OTOMATIS
// ===============================

$satusehat_result = [
    "status"=>"pending",
    "message"=>"Sync belum dijalankan"
];


try {


    $satusehat_result =
    syncClinicalVisitService(
        $conn,
        $id
    );


}
catch(Exception $e){

    $satusehat_result = [

        "status"=>"error",

        "message"=>$e->getMessage()

    ];

}



jsonResponse(

    'success',

    $message,

    [

        "rekam_medis"=>[

            "id_pendaftaran"=>$id

        ],

        "satusehat"=>$satusehat_result

    ]

);



    } catch(Exception $e){


        $conn->rollback();


        jsonResponse(
            'error',
            $e->getMessage()
        );


    }


break;

        case 'search_icd':
            $k=$conn->real_escape_string($_GET['keyword']); if(!$k){echo json_encode([]); break;}
            $r=$conn->query("SELECT code, display FROM icd_10 WHERE code LIKE '$k%' OR display LIKE '%$k%' LIMIT 10");
            $d=[]; while($row=$r->fetch_assoc())$d[]=$row; echo json_encode($d); break;

        case 'search_obat':
            $k=$conn->real_escape_string($_GET['keyword']); if(!$k){echo json_encode([]); break;}
            $sql = "SELECT o.id, o.nama_obat, o.satuan, 
                    COALESCE(SUM(ob.stok_saat_ini), 0) AS total_stok
                    FROM obat o
                    LEFT JOIN obat_batch ob ON o.id = ob.id_obat AND ob.stok_saat_ini > 0
                    WHERE o.nama_obat LIKE ?
                    GROUP BY o.id
                    LIMIT 10";
            
            $stmt = $conn->prepare($sql);
            $keyword_param = "%{$k}%";
            $stmt->bind_param('s', $keyword_param);
            $stmt->execute();
            $res = $stmt->get_result();
            
            $d=[]; 
            while($row=$res->fetch_assoc()){ 
                $row['stok'] = (int)$row['total_stok'];
                $row['display']="{$row['nama_obat']} ({$row['stok']})"; 
                $d[]=$row;
            } 
            $stmt->close();
            echo json_encode($d); 
            break;
        
        case 'get_poli_name':
            $id = $_GET['id_poli'] ?? 0;
            $res = $conn->query("SELECT nama_poli FROM poli WHERE id='$id'");
            echo json_encode($res->fetch_assoc() ?: ['nama_poli' => 'Tidak Dikenal']);
            break;

        case 'get_jadwal_booking':
            $id = $_GET['id_dokter'] ?? 0;
            $filter = $_GET['filter'] ?? 'all';
            $today = date('Y-m-d');
            
            $sql = "SELECT p.tgl_kunjungan, p.jam_kunjungan, p.status, pas.nama_lengkap, pas.no_rm, rm.id as id_rm
                    FROM pendaftaran p
                    JOIN pasien pas ON p.nik_pasien = pas.nik
                    LEFT JOIN rekam_medis rm ON p.id = rm.id_pendaftaran
                    WHERE p.id_dokter=? AND p.tgl_kunjungan >= ?";
            
            $params = [&$id, &$today];
            $types = 'is';
            
            if ($filter === 'minggu') {
                $next_week = date('Y-m-d', strtotime('+7 days'));
                $sql .= " AND p.tgl_kunjungan <= ?";
                $params[] = &$next_week;
                $types .= 's';
            }
            
            $sql .= " ORDER BY p.tgl_kunjungan ASC, p.jam_kunjungan ASC";
            
            $stmt = $conn->prepare($sql);
            $bind_names = [$types];
            for ($i = 0; $i < count($params); $i++) {
                $bind_name = 'bind' . $i;
                $$bind_name = $params[$i];
                $bind_names[] = &$$bind_name;
            }
            if (!empty($params)) {
                 call_user_func_array(array($stmt, 'bind_param'), $bind_names);
            }
            
            $stmt->execute();
            $res = $stmt->get_result();
            $d = []; 
            while($r = $res->fetch_assoc()) { 
                $d[] = $r; 
            }
            $stmt->close();

            jsonResponse('success', 'Jadwal ditemukan', $d);
            break;

        case 'get_rekam_medis':
            $id = $_GET['id_dokter']??0; 
            $search = $conn->real_escape_string($_GET['search'] ?? '');
            $sql = "SELECT rm.id, p.tgl_kunjungan, pas.nama_lengkap, pas.no_rm, rm.diagnosa_utama, rm.resep_obat
                    FROM rekam_medis rm
                    JOIN pendaftaran p ON rm.id_pendaftaran = p.id
                    JOIN pasien pas ON p.nik_pasien = pas.nik
                    WHERE p.id_dokter='$id'";
            if (!empty($search)) {
                $sql .= " AND (pas.nama_lengkap LIKE '%$search%' OR pas.no_rm LIKE '%$search%')";
            }
            $sql .= " ORDER BY p.tgl_kunjungan DESC";
            $res = $conn->query($sql); $d=[]; 
            while($r=$res->fetch_assoc()){ $d[]=$r; }
            echo json_encode($d);
            break;

        case 'get_rekam_medis_detail':
            $id_rm = $_GET['id_rm'] ?? 0;
            $sql = "SELECT rm.*, p.tgl_kunjungan, pas.nama_lengkap, pas.no_rm, d.nama_dokter, pol.nama_poli, p.status
                    FROM rekam_medis rm
                    JOIN pendaftaran p ON rm.id_pendaftaran = p.id
                    JOIN dokter d ON p.id_dokter = d.id
                    JOIN poli pol ON p.id_poli = pol.id
                    JOIN pasien pas ON p.nik_pasien = pas.nik
                    WHERE rm.id = '$id_rm'";

            $rm = $conn->query($sql)->fetch_assoc();
            if (!$rm) { jsonResponse('error', 'Data Rekam Medis tidak ditemukan.'); }

            $diag_utama_display = '';
            if ($rm['diagnosa_utama']) {
                $d = $conn->query("SELECT display FROM icd_10 WHERE code='{$rm['diagnosa_utama']}'")->fetch_assoc();
                $diag_utama_display = $d ? "{$rm['diagnosa_utama']} - {$d['display']}" : $rm['diagnosa_utama'];
            }

            $diag_sekunder_display = '';
            if ($rm['diagnosa_sekunder']) {
                $d = $conn->query("SELECT display FROM icd_10 WHERE code='{$rm['diagnosa_sekunder']}'")->fetch_assoc();
                $diag_sekunder_display = $d ? "{$rm['diagnosa_sekunder']} - {$d['display']}" : $rm['diagnosa_sekunder'];
            }
            
            $responseData = [
                'nama_lengkap' => $rm['nama_lengkap'],
                'no_rm' => $rm['no_rm'],
                'tgl_kunjungan' => $rm['tgl_kunjungan'],
                'nama_poli' => $rm['nama_poli'],
                'dokter_nama' => $rm['nama_dokter'],
                'status' => $rm['status'],
                'diagnosa_detail' => [
                    'keluhan_utama' => $rm['keluhan_utama'],
                    'pemeriksaan_fisik' => $rm['pemeriksaan_fisik'],
                    'diagnosa_utama_display' => $diag_utama_display,
                    'diagnosa_sekunder_display' => $diag_sekunder_display,
                    'rencana_terapi' => $rm['rencana_terapi'],
                    'instruksi_pasien' => $rm['instruksi_pasien'],
                ],
                'resep_data' => json_decode($rm['resep_obat'], true) ?? [],
            ];
            jsonResponse('success', 'Detail RM Ditemukan', $responseData);
            break;
            
        case 'lapor_masalah':
            $id_user = $_POST['id_user'] ?? '';
            $jenis = $_POST['jenis_masalah'] ?? '';
            $ket = $_POST['keterangan'] ?? '';
            $user_res = $conn->query("SELECT username FROM users WHERE id='$id_user'");
            $nik_pelapor = $user_res->num_rows > 0 ? $user_res->fetch_assoc()['username'] : "USER_ID_$id_user"; 
            
            $stmt = $conn->prepare("INSERT INTO laporan (nik_pelapor, role_pelapor, jenis_masalah, keterangan, tgl_lapor, status) VALUES (?, 'Dokter', ?, ?, NOW(), 'Baru')");
            $stmt->bind_param("sss", $nik_pelapor, $jenis, $ket);
            
            if ($stmt->execute()) {
                jsonResponse('success', 'Laporan Dokter Berhasil Terkirim ke Administrator.');
            } else {
                jsonResponse('error', 'Gagal menyimpan laporan Dokter: ' . $stmt->error);
            }
            break;

        case 'get_doctor_profile_detail':
            $id = $_GET['id_dokter'] ?? 0;
            $sql = "SELECT d.nama_dokter, d.spesialis, d.jadwal_praktik, d.kuota, p.nama_poli
                    FROM dokter d
                    LEFT JOIN poli p ON d.id_poli = p.id
                    WHERE d.id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $doc_data = $result->fetch_assoc();
            $stmt->close();

            if ($doc_data) {
                $doc_data['nama_dokter'] = $doc_data['nama_dokter'] ?? 'N/A';
                $doc_data['spesialis'] = $doc_data['spesialis'] ?? 'N/A';
                $doc_data['nama_poli'] = $doc_data['nama_poli'] ?? 'N/A (Poli tidak terdaftar)';
                $doc_data['jadwal_praktik'] = $doc_data['jadwal_praktik'] ?? 'N/A (Atur di Admin)';
                $doc_data['kuota'] = $doc_data['kuota'] ?? 'N/A (Atur di Admin)';
                jsonResponse('success', 'Detail Dokter Ditemukan', $doc_data);
            } else {
                jsonResponse('error', 'Dokter tidak ditemukan.');
            }
            break;
        
        case 'dokter_get_laporan_saya':
            $id_user = $_GET['id_user'] ?? 0;
            $user_res = $conn->query("SELECT username FROM users WHERE id='$id_user'");
            $nik_pelapor = $user_res->num_rows > 0 ? $user_res->fetch_assoc()['username'] : null;

            if (!$nik_pelapor) { jsonResponse('error', 'User tidak terdaftar.'); }
            
            $sql = "SELECT id, jenis_masalah, keterangan, tgl_lapor, status, tanggapan 
                    FROM laporan 
                    WHERE nik_pelapor = ? AND role_pelapor = 'Dokter'
                    ORDER BY tgl_lapor DESC";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $nik_pelapor);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $laporan = [];
            while ($row = $result->fetch_assoc()) { $laporan[] = $row; }
            $stmt->close();
            jsonResponse('success', 'Riwayat laporan berhasil dimuat', $laporan);
            break;
        
        case 'dokter_check_password': 
            if ($method !== 'POST') { http_response_code(405); jsonResponse('error', 'Metode tidak valid.'); }
            $id_user = (int)$_POST['id_user']; 
            $old_password = $_POST['password'] ?? $_POST['old_password'];
            
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role='Dokter'");
            $stmt->bind_param('i', $id_user);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if (!$user || !password_verify($old_password, $user['password'])) {
                 http_response_code(401); jsonResponse('error', 'Password lama/verifikasi tidak cocok.');
            }
            jsonResponse('success', 'Password cocok.');
            break;
            
        case 'dokter_update_username_only': 
            if ($method !== 'POST') { http_response_code(405); jsonResponse('error', 'Metode tidak valid.'); }
            $id_user = (int)$_POST['id_user']; $username = $_POST['new_username'];
            $old_data = $conn->query("SELECT nama_lengkap, id_dokter FROM users WHERE id = '$id_user'")->fetch_assoc();

            $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ? AND role='Dokter'");
            $stmt->bind_param('si', $username, $id_user);
            
            if ($stmt->execute()) {
                $updated_user = [
                    'id' => $id_user,
                    'username' => $username,
                    'nama_lengkap' => $old_data['nama_lengkap'],
                    'role' => 'Dokter',
                    'id_dokter' => $old_data['id_dokter'],
                ];
                if ($stmt->affected_rows > 0) { jsonResponse('success', 'Username berhasil diperbarui.', $updated_user); } 
                else { jsonResponse('warning', 'Username tidak ada perubahan.', $updated_user); }
            } else {
                $msg = (strpos($conn->error, 'Duplicate entry') !== false) ? 'Username sudah digunakan.' : $conn->error;
                http_response_code(500); jsonResponse('error', 'Gagal memperbarui username: ' . $msg);
            }
            break;

        case 'dokter_change_password':
            if ($method !== 'POST') { http_response_code(405); jsonResponse('error', 'Metode tidak valid.'); }
            $id_user = (int)$_POST['id_user']; $old_password = $_POST['old_password']; $new_password = $_POST['new_password'];
            
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role='Dokter'");
            $stmt->bind_param('i', $id_user);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if (!$user || !password_verify($old_password, $user['password'])) {
                 http_response_code(401); jsonResponse('error', 'Password lama tidak cocok.');
            }
            
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ?, must_change_pwd = 0 WHERE id = ? AND role='Dokter'");
            $stmt->bind_param('si', $hashed, $id_user);
            
            if ($stmt->execute()) { jsonResponse('success', 'Password berhasil diperbarui.'); } 
            else { http_response_code(500); jsonResponse('error', 'Gagal memperbarui password: ' . $stmt->error); }
            break;

        default: jsonResponse('error','Action tidak dikenal');
    }
} catch(Exception $e){ echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }
?>