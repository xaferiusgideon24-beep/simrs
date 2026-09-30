<?php
// backend_farmasi.php
// API khusus modul Farmasi SIMRS FiveCare

error_reporting(E_ALL);
ini_set('display_errors', 0); 

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$host = 'localhost'; $user = 'root'; $pass = ''; $db = 'simrs_fivecare';
$MIN_STOCK_THRESHOLD = 10; 

try {
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) throw new Exception("DB Error");
} catch (Exception $e) { 
    http_response_code(500); 
    echo json_encode(['status'=>'error', 'message'=>'Koneksi database gagal: ' . $e->getMessage()]); 
    exit; 
}

require_once __DIR__ . '/satusehat/sync/medication/medication_dispense/medication_dispense_sync.php';

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function get_single_count($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    if ($types && $params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return (int)($row ? array_values($row)[0] : 0);
}

// Fungsi baru untuk mendapatkan harga obat
function get_obat_price($conn, $id_obat) {
    $stmt = $conn->prepare("SELECT harga FROM obat WHERE id = ?");
    $stmt->bind_param('i', $id_obat);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row['harga'] : 0.00;
}


$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        
        // --- DASHBOARD ---
        case 'farmasi_dashboard_stats':
            global $MIN_STOCK_THRESHOLD;
            $today=date('Y-m-d');
            
            $resep_menunggu = get_single_count($conn, "SELECT COUNT(*) FROM pendaftaran WHERE status = 'Menunggu Obat'");
            // Hitung resep selesai hari ini berdasarkan tgl_selesai
            $resep_selesai  = get_single_count($conn, "SELECT COUNT(*) FROM pendaftaran WHERE status = 'Selesai' AND DATE(tgl_selesai) = ?", 's', [$today]);
            
            $jenis_obat = get_single_count($conn, "SELECT COUNT(id) FROM obat WHERE status='Aktif'");
            
            $cek_tabel = $conn->query("SHOW TABLES LIKE 'obat_batch'");
            if($cek_tabel->num_rows > 0) {
                $obat_hampir_ed = get_single_count($conn, "SELECT COUNT(*) FROM obat_batch WHERE tgl_kadaluwarsa BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND stok_saat_ini > 0");
                $obat_kedaluwarsa = get_single_count($conn, "SELECT COUNT(*) FROM obat_batch WHERE tgl_kadaluwarsa < CURDATE() AND stok_saat_ini > 0");
            } else {
                $obat_hampir_ed = 0; $obat_kedaluwarsa = 0;
            }
            
            $obat_stok_menipis = get_single_count($conn, "SELECT COUNT(*) FROM obat WHERE stok > 0 AND stok <= $MIN_STOCK_THRESHOLD");

            json_response([
                'resep_hari_ini'  => $resep_menunggu,
                'resep_selesai'   => $resep_selesai,
                'resep_menunggu'  => $resep_menunggu,
                'jenis_obat'      => $jenis_obat,
                'obat_hampir_ed'  => $obat_hampir_ed,
                'obat_kedaluwarsa'=> $obat_kedaluwarsa,
                'obat_stok_menipis' => $obat_stok_menipis,
            ]);
            break;
            
        // --- RESEP & RIWAYAT ---
        case 'farmasi_get_resep_menunggu':
            $data = [];
            $sql = "SELECT p.id AS id_pendaftaran, DATE_FORMAT(p.tgl_kunjungan,'%d-%m-%Y') AS tgl_resep, p.status, 
                    pas.nama_lengkap AS nama_pasien, pas.no_rm, 
                    COALESCE(d.nama_dokter, 'Dokter Tidak Dikenal') as nama_dokter, 
                    COALESCE(pol.nama_poli, '-') as nama_poli, 
                    rm.resep_obat AS resep_json,
                    p.biaya_medis_dasar -- ADDED BLM
                    FROM pendaftaran p 
                    JOIN pasien pas ON p.nik_pasien = pas.nik 
                    LEFT JOIN dokter d ON p.id_dokter = d.id 
                    LEFT JOIN poli pol ON p.id_poli = pol.id 
                    LEFT JOIN rekam_medis rm ON p.id = rm.id_pendaftaran 
                    WHERE p.status IN ('Menunggu Obat') 
                    ORDER BY p.tgl_kunjungan ASC, p.id ASC";
            
            if ($result = $conn->query($sql)) { 
                while ($row = $result->fetch_assoc()) { 
                    $resep = json_decode($row['resep_json'] ?? '[]', true);
                    $total_harga_obat = 0;
                    
                    // Hitung total harga resep
                    foreach ($resep as &$item) {
                        $harga_satuan = get_obat_price($conn, $item['id_obat']);
                        $item['harga_satuan'] = $harga_satuan;
                        $item['harga_total'] = $harga_satuan * $item['jumlah'];
                        $total_harga_obat += $item['harga_total'];
                    }
                    
                    $row['resep_json_with_prices'] = json_encode($resep);
                    $row['total_harga_resep'] = $total_harga_obat; // Total Harga Obat saja
                    $row['total_biaya_final'] = (float)$row['biaya_medis_dasar'] + $total_harga_obat; // Total Final
                    
                    $data[] = $row; 
                } 
            }
            json_response($data);
            break;
            
        case 'farmasi_get_resep_detail':
            $id = $_GET['id_pendaftaran'] ?? null;
            $sql = "SELECT rm.resep_obat, p.tgl_kunjungan, pas.nama_lengkap, pas.no_rm, 
                    COALESCE(d.nama_dokter, '-') as nama_dokter, 
                    COALESCE(pol.nama_poli, '-') as nama_poli,
                    p.biaya_medis_dasar -- ADDED BLM
                    FROM pendaftaran p 
                    JOIN pasien pas ON p.nik_pasien = pas.nik 
                    LEFT JOIN dokter d ON p.id_dokter = d.id 
                    LEFT JOIN poli pol ON p.id_poli = pol.id 
                    LEFT JOIN rekam_medis rm ON p.id = rm.id_pendaftaran 
                    WHERE p.id = ?";
            
            $stmt = $conn->prepare($sql); $stmt->bind_param('i', $id); $stmt->execute();
            $data = $stmt->get_result()->fetch_assoc();
            
            if ($data) { 
                $resep_data = !empty($data['resep_obat']) ? json_decode($data['resep_obat'], true) : []; 
                $total_harga_obat = 0;

                // Hitung harga satuan dan total harga untuk detail resep
                foreach ($resep_data as &$item) {
                    $harga_satuan = get_obat_price($conn, $item['id_obat']);
                    $item['harga_satuan'] = $harga_satuan;
                    $item['harga_total'] = $harga_satuan * $item['jumlah'];
                    $total_harga_obat += $item['harga_total'];
                }

                $data['resep_data'] = $resep_data; 
                $data['total_harga_resep'] = $total_harga_obat; // Total Harga Obat saja
                $data['total_biaya_final'] = (float)$data['biaya_medis_dasar'] + $total_harga_obat; // Total Final
                
                unset($data['resep_obat']); 
                json_response(['status' => 'success', 'data' => $data]); 
            }
            json_response(['status' => 'error', 'message' => 'Detail tidak ditemukan.'], 404);
            break;

        case 'farmasi_selesaikan_resep':
            $id_pendaftaran = $_POST['id_resep'] ?? null; 
            $id_petugas = $_POST['id_petugas'] ?? null;
            if (!$id_pendaftaran) json_response(['status' => 'error', 'message' => 'Parameter kurang'], 400);

            // --- AMBIL INFO ANTRIAN & POLI UNTUK CATATAN LOG & BLM ---
            $info_res = $conn->query("
                SELECT p.no_antrian, p.biaya_medis_dasar, COALESCE(pol.nama_poli, 'Poli') as nama_poli, pas.nama_lengkap
                FROM pendaftaran p
                LEFT JOIN poli pol ON p.id_poli = pol.id
                JOIN pasien pas ON p.nik_pasien = pas.nik
                WHERE p.id = '$id_pendaftaran'
            ");
            $info = $info_res->fetch_assoc();
            $no_antrian = $info['no_antrian'] ?? '?';
            $nama_poli = $info['nama_poli'] ?? 'Poli';
            $biaya_medis_dasar = (float)($info['biaya_medis_dasar'] ?? 0.00);
            $nama_pasien = $info['nama_lengkap'] ?? 'Pasien';
            // ---------------------------------------------

            // Ambil rekam medis berdasarkan pendaftaran
$rm_res = $conn->query("
    SELECT id
    FROM rekam_medis
    WHERE id_pendaftaran = '$id_pendaftaran'
");


if ($rm_res->num_rows == 0) {

    // Walaupun tidak ada resep, tetap catat tagihan BLM

    $total_biaya_final = $biaya_medis_dasar;
    $now = date('Y-m-d H:i:s');

    $stmt_update = $conn->prepare("
        UPDATE pendaftaran 
        SET 
            status='Selesai',
            tgl_selesai=?,
            total_biaya_final=?,
            total_harga_resep=0.00
        WHERE id=?
    ");

    $stmt_update->bind_param(
        "sdi",
        $now,
        $total_biaya_final,
        $id_pendaftaran
    );

    $stmt_update->execute();


    json_response([
        'status'=>'success',
        'message'=>"Resep selesai (Tanpa obat). Tagihan Pasien $nama_pasien: Rp " 
        . number_format($total_biaya_final,0,',','.')
        . " (BLM saja)."
    ]);

    return;
}


$rm = $rm_res->fetch_assoc();

$id_rekam_medis = $rm['id'];

$cek_sudah_serah = $conn->prepare("
    SELECT status_resep
    FROM resep_obat_detail
    WHERE id_rekam_medis=?
    LIMIT 1
");

$cek_sudah_serah->bind_param(
    "i",
    $id_rekam_medis
);

$cek_sudah_serah->execute();

$status_cek = $cek_sudah_serah
    ->get_result()
    ->fetch_assoc();


if(
    isset($status_cek['status_resep']) &&
    $status_cek['status_resep'] == 'Sudah Diserahkan'
){

    json_response([
        'status'=>'error',
        'message'=>'Resep sudah pernah diserahkan, stok tidak diproses ulang.'
    ]);

}

// Ambil resep dari tabel detail
$resep_res = $conn->query("
    SELECT
        rod.id,
        rod.id_obat,
        rod.jumlah,
        rod.dosis,
        rod.frekuensi,
        rod.aturan_pakai,
        o.nama_obat

    FROM resep_obat_detail rod

    JOIN obat o
    ON o.id = rod.id_obat

    WHERE rod.id_rekam_medis='$id_rekam_medis'
");
            $conn->begin_transaction();
            try {
                $total_harga_resep = 0; // Inisialisasi total harga obat
                
                while ($obat = $resep_res->fetch_assoc()) {

    $id_obat   = $obat['id_obat'];
    $butuh     = (int)$obat['jumlah'];
    $nama_obat = $obat['nama_obat'];

    $harga_satuan = get_obat_price($conn, $id_obat);
    $total_harga_resep += $harga_satuan * $butuh;


    $sqlBatch = "SELECT id, stok_saat_ini, nomor_batch 
                 FROM obat_batch 
                 WHERE id_obat = ? 
                 AND stok_saat_ini > 0 
                 ORDER BY tgl_kadaluwarsa ASC";

    $stmtB = $conn->prepare($sqlBatch);
    $stmtB->bind_param('i', $id_obat);
    $stmtB->execute();

    $resB = $stmtB->get_result();

    $sisa_butuh = $butuh;


    while ($batch = $resB->fetch_assoc()) {

    if ($sisa_butuh <= 0) break;

    $ambil = min(
        $sisa_butuh,
        $batch['stok_saat_ini']
    );


    // KURANGI STOK BATCH
    $stmtBatchUpdate = $conn->prepare("
        UPDATE obat_batch
        SET stok_saat_ini = stok_saat_ini - ?
        WHERE id = ?
    ");

    $stmtBatchUpdate->bind_param(
        "ii",
        $ambil,
        $batch['id']
    );

    if(!$stmtBatchUpdate->execute()){
        throw new Exception(
            "Gagal update stok batch"
        );
    }


    $sisa_butuh -= $ambil;
}


    $stmtB->close();


// ================================
// SINKRONISASI STOK MASTER OBAT
// dari total stok batch
// ================================

$syncStok = $conn->query("
    UPDATE obat
    SET stok = (
        SELECT COALESCE(SUM(stok_saat_ini),0)
        FROM obat_batch
        WHERE id_obat='$id_obat'
    )
    WHERE id='$id_obat'
");

if(!$syncStok){
    throw new Exception(
        "Gagal sinkron stok master obat: ".$conn->error
    );
}


    $petugas = $conn->query("
        SELECT nama_lengkap 
        FROM users 
        WHERE id='$id_petugas'
    ")->fetch_assoc()['nama_lengkap'] ?? 'Sys';


    $stmtL = $conn->prepare("
        INSERT INTO log_stok
        (
            id_obat,
            nama_obat,
            id_petugas,
            nama_petugas,
            jenis_aktivitas,
            jumlah_perubahan,
            catatan
        )
        VALUES
        (?, ?, ?, ?, 'Keluar Resep', ?, ?)
    ");


    $catatan = "Resep Antrian #$no_antrian ($nama_poli)";


    $stmtL->bind_param(
        'isisis',
        $id_obat,
        $nama_obat,
        $id_petugas,
        $petugas,
        $butuh,
        $catatan
    );


    $stmtL->execute();

}
                
// UPDATE STATUS RESEP SETELAH OBAT BERHASIL KELUAR
$update_resep = $conn->prepare("
    UPDATE resep_obat_detail
    SET status_resep='Sudah Diserahkan'
    WHERE id_rekam_medis=?
");

$update_resep->bind_param(
    "i",
    $id_rekam_medis
);

if(!$update_resep->execute()){
    throw new Exception(
        "Gagal update status resep: ".$update_resep->error
    );
}


$total_harga_resep = $total_harga_resep;

$now = date('Y-m-d H:i:s');

$total_biaya_final =
    $biaya_medis_dasar + $total_harga_resep;


$stmtBill = $conn->prepare("
    UPDATE pendaftaran
    SET 
        status='Selesai',
        tgl_selesai=?,
        total_harga_resep=?,
        total_biaya_final=?
    WHERE id=?
");

$stmtBill->bind_param(
    "sddi",
    $now,
    $total_harga_resep,
    $total_biaya_final,
    $id_pendaftaran
);

$stmtBill->execute();
$conn->commit();

// =====================================================
// SATUSEHAT MEDICATION DISPENSE
// =====================================================

$dispense_result = [];



$sqlDispense = "

SELECT 
    rod.id

FROM rekam_medis rm

JOIN resep_obat_detail rod

ON rod.id_rekam_medis = rm.id

WHERE rm.id_pendaftaran = ?

";



$stmtDispense = $conn->prepare($sqlDispense);


$stmtDispense->bind_param(
    "i",
    $id_pendaftaran
);


$stmtDispense->execute();


$resDispense =
$stmtDispense->get_result();



while($row = $resDispense->fetch_assoc()){


    $dispense_result[] =
    syncMedicationDispenseByResepDetailId(

        $conn,

        $row['id']

    );


}




json_response([

    'status'=>'success',

    'message'=>
        "Resep selesai, stok update, MedicationDispense diproses.",


    'satusehat_dispense'=>
        $dispense_result,


    'billing'=>[

        'pasien'=>$nama_pasien,

        'total'=>
        number_format(
            $total_biaya_final,
            0,
            ',',
            '.'
        )

    ]

]);
            } catch (Exception $e) {
                $conn->rollback();
                json_response(['status' => 'error', 'message' => $e->getMessage()], 500);
            }
            break;

        case 'farmasi_get_riwayat_resep':
            $q = '%' . ($_GET['search'] ?? '') . '%';
            // UPDATE: Memastikan no_rm, biaya_medis_dasar, total_biaya_final terambil
            $sql = "SELECT p.id AS id_resep, DATE_FORMAT(p.tgl_kunjungan,'%Y-%m-%d') AS tgl_resep, p.tgl_selesai, 
                    pas.nama_lengkap AS nama_pasien, pas.no_rm, 
                    COALESCE(d.nama_dokter, 'Dokter Tidak Dikenal') as nama_dokter, 
                    COALESCE(pol.nama_poli, '-') as nama_poli, 
                    rm.resep_obat AS resep_json,
                    p.biaya_medis_dasar, p.total_harga_resep, p.total_biaya_final -- NEW COLUMNS
                    FROM pendaftaran p 
                    JOIN pasien pas ON p.nik_pasien = pas.nik 
                    LEFT JOIN dokter d ON p.id_dokter = d.id 
                    LEFT JOIN poli pol ON p.id_poli = pol.id 
                    LEFT JOIN rekam_medis rm ON p.id = rm.id_pendaftaran 
                    WHERE p.status = 'Selesai' 
                    AND (pas.nama_lengkap LIKE ? OR COALESCE(d.nama_dokter, '') LIKE ? OR pas.no_rm LIKE ?) 
                    ORDER BY p.tgl_selesai DESC LIMIT 50";
            
            $stmt = $conn->prepare($sql); $stmt->bind_param('sss', $q, $q, $q); $stmt->execute();
            $res = $stmt->get_result(); 
            $data = []; 
            while($r = $res->fetch_assoc()) {
                // Note: Perhitungan harga tidak lagi diperlukan di sini, cukup ambil dari kolom pendaftaran
                $data[] = $r;
            }
            json_response($data);
            break;

        // --- STOK & BATCH (SISANYA TETAP SAMA) ---
        case 'farmasi_get_stok_obat':
            $cek = $conn->query("SHOW TABLES LIKE 'obat_batch'");
            if($cek->num_rows > 0){
                $sql = "SELECT o.id, o.kode_obat, o.nama_obat, o.kategori, o.stok, o.satuan, o.harga, o.status, 
                        MIN(NULLIF(b.tgl_kadaluwarsa, '0000-00-00')) as nearest_ed,
                        SUM(CASE WHEN b.tgl_kadaluwarsa < CURDATE() AND b.stok_saat_ini > 0 THEN 1 ELSE 0 END) as has_expired_batch
                        FROM obat o 
                        LEFT JOIN obat_batch b ON o.id = b.id_obat AND b.stok_saat_ini > 0 
                        GROUP BY o.id 
                        ORDER BY o.nama_obat ASC";
            } else {
                $sql = "SELECT *, tgl_kadaluwarsa as nearest_ed, 0 as has_expired_batch FROM obat ORDER BY nama_obat ASC";
            }
            $result = $conn->query($sql);
            $data = []; 
            while($row = $result->fetch_assoc()) { 
                if(!$row['nearest_ed']) { 
                   $row['nearest_ed'] = $conn->query("SELECT tgl_kadaluwarsa FROM obat WHERE id={$row['id']}")->fetch_assoc()['tgl_kadaluwarsa'] ?? null;
                }
                $data[] = $row; 
            }
            json_response($data);
            break;

        case 'farmasi_get_batch_detail':
            $id_obat = $_GET['id_obat'] ?? 0;
            $sql = "SELECT * FROM obat_batch WHERE id_obat = ? ORDER BY tgl_kadaluwarsa ASC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $id_obat);
            $stmt->execute();
            $res = $stmt->get_result();
            $data = []; while($r = $res->fetch_assoc()) $data[] = $r;
            
            // Tambahkan harga satuan obat
            $harga_satuan = get_obat_price($conn, $id_obat);
            json_response(['batches' => $data, 'harga_satuan' => $harga_satuan]);
            break;
            
        case 'farmasi_musnahkan_batch':
            if ($method !== 'POST') json_response(['status'=>'error','message'=>'Invalid Method'], 405);
            $id_batch = $_POST['id_batch'];
            $id_petugas = $_POST['id_petugas'];
            $alasan = $_POST['alasan'] ?? 'Pemusnahan Batch Expired';

            $conn->begin_transaction();
            try {
                $b = $conn->query("SELECT * FROM obat_batch WHERE id = '$id_batch'")->fetch_assoc();
                if(!$b) throw new Exception("Batch tidak ditemukan");
                
                $qty = $b['stok_saat_ini'];
                $id_obat = $b['id_obat'];
                $no_batch = $b['nomor_batch'];

                if($qty <= 0) throw new Exception("Stok batch sudah kosong.");

                // 1. Kosongkan stok batch
                $conn->query("UPDATE obat_batch SET stok_saat_ini = 0, status_batch='Habis' WHERE id = '$id_batch'");

                // 2. Kurangi stok master
                $conn->query("UPDATE obat SET stok = stok - $qty WHERE id = '$id_obat'");

                // 3. Log
                $petugas = $conn->query("SELECT nama_lengkap FROM users WHERE id='$id_petugas'")->fetch_assoc()['nama_lengkap'] ?? 'Sys';
                $nm_obat = $conn->query("SELECT nama_obat FROM obat WHERE id='$id_obat'")->fetch_assoc()['nama_obat'];
                
                $catatan = "Pemusnahan Batch $no_batch. Alasan: $alasan";
                $stmtL = $conn->prepare("INSERT INTO log_stok (id_obat, nama_obat, id_petugas, nama_petugas, jenis_aktivitas, jumlah_perubahan, catatan) VALUES (?, ?, ?, ?, 'Keluar Musnah', ?, ?)");
                $stmtL->bind_param('isisis', $id_obat, $nm_obat, $id_petugas, $petugas, $qty, $catatan);
                $stmtL->execute();

                $conn->commit();
                json_response(['status'=>'success', 'message'=>"Batch $no_batch berhasil dimusnahkan ($qty unit)."]);
            } catch(Exception $e) {
                $conn->rollback();
                json_response(['status'=>'error', 'message'=>$e->getMessage()], 500);
            }
            break;
            
        case 'farmasi_add_obat_baru': 
            if ($method !== 'POST') json_response(['status'=>'error','message'=>'Invalid Method'], 405);
             $kd = $_POST['kode_obat']; $nm = $_POST['nama_obat']; $kat = $_POST['kategori'];
             // FIX: Menggunakan nama field yang baru
             $stok = (int)$_POST['stok']; $sat = $_POST['satuan']; $ed = $_POST['tgl_kadaluwarsa'];
             $hrg = (float)$_POST['harga']; $id_petugas = $_POST['id_petugas'];
             $vendor = $_POST['vendor'] ?: 'Vendor Umum';

             $conn->begin_transaction();
             try {
                 $stmt = $conn->prepare("INSERT INTO obat (kode_obat, nama_obat, kategori, stok, satuan, tgl_kadaluwarsa, harga, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktif')");
                 $stmt->bind_param('ssisssd', $kd, $nm, $kat, $stok, $sat, $ed, $hrg);
                 if (!$stmt->execute()) throw new Exception($stmt->error);
                 $id_obat = $stmt->insert_id;

                 $batch_no = !empty($_POST['nomor_batch']) ? $_POST['nomor_batch'] : ("BATCH-" . date('ymd') . "-" . rand(100,999));
                 $stmtB = $conn->prepare("INSERT INTO obat_batch (id_obat, nomor_batch, stok_masuk, stok_saat_ini, tgl_masuk, tgl_kadaluwarsa, vendor) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
                 $stmtB->bind_param('isiiss', $id_obat, $batch_no, $stok, $stok, $ed, $vendor);
                 $stmtB->execute();
                 
                 $petugas = $conn->query("SELECT nama_lengkap FROM users WHERE id='$id_petugas'")->fetch_assoc()['nama_lengkap'] ?? 'Sys';
                 $catatan = "Obat Baru (Batch: $batch_no)";
                 $stmtL = $conn->prepare("INSERT INTO log_stok (id_obat, nama_obat, id_petugas, nama_petugas, jenis_aktivitas, jumlah_perubahan, catatan) VALUES (?, ?, ?, ?, 'Masuk Awal', ?, ?)");
                 $stmtL->bind_param('isisis', $id_obat, $nm, $id_petugas, $petugas, $stok, $catatan);
                 $stmtL->execute();
$conn->query("
    UPDATE resep_obat_detail
    SET status_resep='Sudah Diserahkan'
    WHERE id_rekam_medis='$id_rekam_medis'
");

$conn->commit();

json_response([
    'status'=>'success',
    'message'=>'Resep selesai, stok dan status resep diperbarui.'
]);
             } catch(Exception $e) {
                 $conn->rollback();
                 json_response(['status'=>'error', 'message'=>$e->getMessage()], 500);
             }
            break;

        case 'farmasi_add_stok_obat': 
            if ($method !== 'POST') json_response(['status'=>'error','message'=>'Invalid Method'], 405);
            $id = (int)$_POST['obat_id_stok']; 
            // FIX: Menggunakan nama field yang benar dari HTML: name="stok"
            $jml = (int)$_POST['stok'];
            $id_petugas = $_POST['id_petugas'];
            
            $no_batch = !empty($_POST['nomor_batch']) ? $_POST['nomor_batch'] : ("BATCH-ADD-" . date('ymd') . "-" . rand(10,99));
            // FIX: Menggunakan nama field yang benar dari HTML: name="tgl_kadaluwarsa"
            $tgl_ed = $_POST['tgl_kadaluwarsa']; 
            $vendor = !empty($_POST['vendor']) ? $_POST['vendor'] : 'Vendor Restock';

            // Validasi tambahan untuk tgl_kadaluwarsa
            if (empty($tgl_ed)) {
                 json_response(['status'=>'error', 'message'=>'Tanggal kedaluwarsa (tgl_kadaluwarsa) wajib diisi.'], 400);
            }

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO obat_batch (id_obat, nomor_batch, stok_masuk, stok_saat_ini, tgl_masuk, tgl_kadaluwarsa, vendor) VALUES (?, ?, ?, ?, NOW(), ?, ?)");
                $stmt->bind_param('isiiss', $id, $no_batch, $jml, $jml, $tgl_ed, $vendor);
                if (!$stmt->execute()) throw new Exception("Gagal buat batch: " . $conn->error);

                $conn->query("UPDATE obat SET stok = stok + $jml WHERE id = $id");

                $petugas = $conn->query("SELECT nama_lengkap FROM users WHERE id='$id_petugas'")->fetch_assoc()['nama_lengkap'] ?? 'Farmasi';
                $catatan = "Restock Batch: $no_batch";
                
                $stmtL = $conn->prepare("INSERT INTO log_stok (id_obat, nama_obat, id_petugas, nama_petugas, jenis_aktivitas, jumlah_perubahan, catatan) VALUES (?, (SELECT nama_obat FROM obat WHERE id=?), ?, ?, 'Masuk', ?, ?)");
                $stmtL->bind_param('iiisis', $id, $id, $id_petugas, $petugas, $jml, $catatan);
                
                if (!$stmtL->execute()) throw new Exception("Gagal log stok: " . $stmtL->error);

                $conn->commit();
                json_response(['status'=>'success', 'message'=>'Stok berhasil ditambah ke Batch baru.']);
            } catch (Exception $e) {
                $conn->rollback();
                json_response(['status'=>'error', 'message'=>$e->getMessage()], 500);
            }
            break;
        
        // --- LAPORAN MASALAH (SISANYA TETAP SAMA) ---
        case 'farmasi_lapor_masalah':
            $id_user = $_POST['id_user'] ?? '';
            $jenis = $_POST['jenis_masalah'] ?? '';
            $ket = $_POST['keterangan'] ?? '';
            
            // Prioritas: Username -> Nama Lengkap -> Fallback ID
            $u = $conn->query("SELECT username, nama_lengkap FROM users WHERE id='$id_user'")->fetch_assoc();
            $nik_pelapor = !empty($u['username']) ? $u['username'] : (!empty($u['nama_lengkap']) ? $u['nama_lengkap'] : "USER_ID_$id_user");
            
            $stmt = $conn->prepare("INSERT INTO laporan (nik_pelapor, role_pelapor, jenis_masalah, keterangan, tgl_lapor, status) VALUES (?, 'Farmasi', ?, ?, NOW(), 'Baru')");
            $stmt->bind_param("sss", $nik_pelapor, $jenis, $ket);
            
            if($stmt->execute()) json_response(['status'=>'success', 'message'=>'Laporan terkirim ke Admin.']);
            else json_response(['status'=>'error', 'message'=>'Gagal kirim laporan: '.$stmt->error], 500);
            break;

        case 'farmasi_get_laporan_saya':
            $id_user = $_GET['id_user'] ?? 0;
            // Ambil data user
            $u = $conn->query("SELECT username, nama_lengkap FROM users WHERE id='$id_user'")->fetch_assoc();
            
            $username = $u['username'] ?? '';
            $nama = $u['nama_lengkap'] ?? '';
            $fallbackId = "USER_ID_$id_user";
            
            $stmt = $conn->prepare("SELECT * FROM laporan WHERE nik_pelapor IN (?, ?, ?) ORDER BY tgl_lapor DESC");
            $stmt->bind_param("sss", $username, $nama, $fallbackId);
            $stmt->execute();
            $res = $stmt->get_result();
            $data = []; while($r = $res->fetch_assoc()) $data[] = $r;
            json_response($data);
            break;

        // --- PROFIL USER ---
        case 'farmasi_get_user_profile':
            $id_user = $_GET['id_user'] ?? null;
            $sql = "SELECT id, username, nama_lengkap, role FROM users WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $id_user);
            $stmt->execute();
            $result = $stmt->get_result();
            $data = $result->fetch_assoc();
            $stmt->close();
            
            if ($data) {
                 json_response(['status' => 'success', 'data' => $data]);
            }
            json_response(['status' => 'error', 'message' => 'Profil tidak ditemukan.'], 404);
            break;
            
        case 'farmasi_check_password': 
            if ($method !== 'POST') json_response(['status' => 'error', 'message' => 'Metode tidak valid.'], 405);
            $id_user = (int)$_POST['id_user']; 
            $old_password = $_POST['old_password'];
            
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role='Farmasi'");
            $stmt->bind_param('i', $id_user);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if (!$user || !password_verify($old_password, $user['password'])) {
                 json_response(['status' => 'error', 'message' => 'Password lama tidak cocok.'], 401);
            }
            json_response(['status' => 'success', 'message' => 'Password cocok.']);
            break;
            
        case 'farmasi_update_username_only': 
            if ($method !== 'POST') json_response(['status' => 'error', 'message' => 'Metode tidak valid.'], 405);
            $id_user = (int)$_POST['id_user']; 
            $username = $_POST['username'];
            $nama_lengkap = $_POST['nama_lengkap'];

            $stmt = $conn->prepare("UPDATE users SET username = ?, nama_lengkap = ? WHERE id = ? AND role='Farmasi'");
            $stmt->bind_param('ssi', $username, $nama_lengkap, $id_user);
            
            if ($stmt->execute()) {
                $res = $conn->query("SELECT id, username, nama_lengkap, role, id_dokter, must_change_pwd FROM users WHERE id = '$id_user'");
                $updated_user = $res->fetch_assoc();

                if ($stmt->affected_rows > 0) {
                     json_response(['status' => 'success', 'message' => 'Username berhasil diperbarui.', 'user_data' => $updated_user]);
                } else {
                     json_response(['status' => 'warning', 'message' => 'Username tidak ada perubahan.', 'user_data' => $updated_user]);
                }
            } else {
                $msg = (strpos($conn->error, 'Duplicate entry') !== false) ? 'Username sudah digunakan.' : $conn->error;
                json_response(['status' => 'error', 'message' => 'Gagal memperbarui username: ' . $msg], 500);
            }
            break;

        case 'farmasi_change_password':
            if ($method !== 'POST') json_response(['status' => 'error', 'message' => 'Metode tidak valid.'], 405);
            $id_user = (int)$_POST['id_user']; 
            $old_password = $_POST['old_password'];
            $new_password = $_POST['new_password'];
            
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role='Farmasi'");
            $stmt->bind_param('i', $id_user);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if (!$user || !password_verify($old_password, $user['password'])) {
                 json_response(['status' => 'error', 'message' => 'Password lama tidak cocok.'], 401);
            }
            
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ?, must_change_pwd = 0 WHERE id = ? AND role='Farmasi'");
            $stmt->bind_param('si', $hashed, $id_user);
            
            if ($stmt->execute()) {
                json_response(['status' => 'success', 'message' => 'Password berhasil diperbarui.']);
            } else {
                 json_response(['status' => 'error', 'message' => 'Gagal memperbarui password: ' . $stmt->error], 500);
            }
            break;

        // --- 10. NEW FEATURE: LOG AKTIVITAS OBAT ---
        case 'farmasi_get_log_stok':
            // UPDATE: Menggunakan COALESCE untuk memprioritaskan nama lengkap petugas
            $sql = "SELECT ls.*, COALESCE(u.nama_lengkap, ls.nama_petugas, 'Petugas Farmasi') as nama_petugas_real 
                    FROM log_stok ls 
                    LEFT JOIN users u ON ls.id_petugas = u.id 
                    ORDER BY ls.tgl_aktivitas DESC LIMIT 100";
            $res = $conn->query($sql);
            $data = [];
            while($r = $res->fetch_assoc()) {
                $data[] = $r;
            }
            json_response($data);
            break;

        default: json_response(['status' => 'error', 'message' => 'Action tidak dikenal'], 400);
    }
} catch (Exception $e) { json_response(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()], 500); }
?>