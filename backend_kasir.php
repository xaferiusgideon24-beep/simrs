<?php
// backend_kasir.php
// API khusus modul Kasir SIMRS FiveCare

error_reporting(E_ALL);
ini_set('display_errors', 0); 

// PERBAIKAN: Set Timezone ke Asia/Jakarta (WIB) agar waktu sinkron dengan waktu nyata di Indonesia
date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$host = 'localhost'; $user = 'root'; $pass = ''; $db = 'simrs_fivecare';

try {
    $conn = new mysqli($host, $user, $pass, $db);
    if ($conn->connect_error) throw new Exception("DB Error");
} catch (Exception $e) { 
    http_response_code(500); 
    echo json_encode(['status'=>'error', 'message'=>'Koneksi database gagal: ' . $e->getMessage()]); 
    exit; 
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

function get_single_count($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    if ($types && $params) {
        // Safe binding check
        if (!call_user_func_array(array($stmt, 'bind_param'), array_merge([$types], $params))) {
            return 0; 
        }
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return (int)($row ? array_values($row)[0] : 0);
}

function get_single_sum($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0.00;
    if ($types && $params) {
         if (!call_user_func_array(array($stmt, 'bind_param'), array_merge([$types], $params))) {
            return 0.00;
        }
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return (float)($row ? array_values($row)[0] : 0.00);
}

/**
 * Fungsi untuk mengambil detail pendaftaran lengkap termasuk resep obat,
 * dan data pembayaran (jumlah_bayar_input & kembalian) dari tabel pendaftaran.
 */
function get_detail_pembayaran_for_struk($conn, $id_pendaftaran) {
    $sql = "SELECT p.id, pas.nama_lengkap AS nama_pasien, pas.no_rm, 
            COALESCE(d.nama_dokter, '-') as nama_dokter, 
            COALESCE(pol.nama_poli, '-') as nama_poli,
            p.biaya_medis_dasar, p.total_harga_resep, p.total_biaya_final, p.tgl_kunjungan,
            rm.resep_obat, p.tgl_selesai, p.status_pembayaran,
            p.jumlah_bayar_input, p.kembalian, p.id_kasir
            FROM pendaftaran p 
            JOIN pasien pas ON p.nik_pasien = pas.nik 
            LEFT JOIN dokter d ON p.id_dokter = d.id 
            LEFT JOIN poli pol ON p.id_poli = pol.id 
            LEFT JOIN rekam_medis rm ON rm.id_pendaftaran = p.id
            WHERE p.id = ?";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) return null;
    $stmt->bind_param('i', $id_pendaftaran);
    $stmt->execute();
    $res = $stmt->get_result();
    $data = $res->fetch_assoc();
    $stmt->close();

    if ($data) {
        // Decode resep_obat (JSON String di DB)
        $data['resep_detail'] = [];
        if (!empty($data['resep_obat'])) {
            $resep_decoded = json_decode($data['resep_obat'], true);
            if (is_array($resep_decoded)) {
                $data['resep_detail'] = $resep_decoded;
            }
        }
        unset($data['resep_obat']); // Hapus raw JSON
    }
    return $data;
}


$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        
        // --- DASHBOARD KASIR ---
        case 'kasir_dashboard_stats':
            $today=date('Y-m-d');
            
            // 1. Tagihan Menunggu (Total Pasien yang sudah 'Selesai' tapi 'Belum Lunas' dan punya tagihan > 0)
            $tagihan_menunggu = get_single_count($conn, "SELECT COUNT(*) FROM pendaftaran WHERE status = 'Selesai' AND status_pembayaran = 'Belum Lunas' AND total_biaya_final > 0");
            
            // 2. Transaksi Lunas Hari Ini (Count)
            $transaksi_lunas_count  = get_single_count($conn, "SELECT COUNT(*) FROM pendaftaran WHERE status_pembayaran = 'Lunas' AND DATE(tgl_selesai) = ?", 's', [&$today]);
            
            // 3. Total Pemasukan Hari Ini (Sum of total_biaya_final for Lunas Today)
            $total_pemasukan_hari_ini = get_single_sum($conn, "SELECT SUM(total_biaya_final) FROM pendaftaran WHERE status_pembayaran = 'Lunas' AND DATE(tgl_selesai) = ?", 's', [&$today]);

            // 4. Pasien Selesai Layanan Hari Ini (Count of status = 'Selesai' Today)
            $selesai_layanan_count = get_single_count($conn, "SELECT COUNT(*) FROM pendaftaran WHERE status = 'Selesai' AND DATE(tgl_selesai) = ?", 's', [&$today]);

            // 5. Trend Pemasukan Harian
            $trend = [];
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $pemasukan = get_single_sum($conn, "SELECT SUM(total_biaya_final) FROM pendaftaran WHERE status_pembayaran = 'Lunas' AND DATE(tgl_selesai) = ?", 's', [&$date]);
                $trend[] = ['date' => date('d/m', strtotime($date)), 'pemasukan' => $pemasukan];
            }

            json_response([
                'tagihan_menunggu'  => $tagihan_menunggu,
                'transaksi_lunas_count' => $transaksi_lunas_count,
                'total_pemasukan_hari_ini'   => $total_pemasukan_hari_ini,
                'selesai_layanan_count'      => $selesai_layanan_count,
                'trend' => $trend
            ]);
            break;
        
        // --- ANTRIAN TAGIHAN ---
        case 'kasir_get_antrian_tagihan':
            $q = '%' . ($_GET['search'] ?? '') . '%';
            $sql = "SELECT p.id, pas.nama_lengkap AS nama_pasien, pas.no_rm, 
                    COALESCE(d.nama_dokter, '-') as nama_dokter, 
                    COALESCE(pol.nama_poli, '-') as nama_poli,
                    p.biaya_medis_dasar, p.total_harga_resep, p.total_biaya_final, p.tgl_kunjungan
                    FROM pendaftaran p 
                    JOIN pasien pas ON p.nik_pasien = pas.nik 
                    LEFT JOIN dokter d ON p.id_dokter = d.id 
                    LEFT JOIN poli pol ON p.id_poli = pol.id 
                    WHERE p.status = 'Selesai' AND p.status_pembayaran = 'Belum Lunas' AND p.total_biaya_final > 0
                    AND (pas.nama_lengkap LIKE ? OR pas.no_rm LIKE ?) 
                    ORDER BY p.tgl_kunjungan DESC";
            
            $stmt = $conn->prepare($sql); 
            $stmt->bind_param('ss', $q, $q); 
            $stmt->execute();
            $res = $stmt->get_result(); 
            $data = []; 
            while($r = $res->fetch_assoc()) {
                $data[] = $r;
            }
            json_response($data);
            break;

        // --- RIWAYAT TRANSAKSI LUNAS HARI INI ---
        case 'kasir_get_riwayat_lunas_hari_ini':
            $today=date('Y-m-d');
            $sql = "SELECT p.id, pas.nama_lengkap AS nama_pasien, pas.no_rm, 
                    COALESCE(d.nama_dokter, '-') as nama_dokter, 
                    COALESCE(pol.nama_poli, '-') as nama_poli,
                    p.total_biaya_final, p.tgl_selesai AS tgl_lunas
                    FROM pendaftaran p 
                    JOIN pasien pas ON p.nik_pasien = pas.nik 
                    LEFT JOIN dokter d ON p.id_dokter = d.id 
                    LEFT JOIN poli pol ON p.id_poli = pol.id 
                    WHERE p.status_pembayaran = 'Lunas' AND DATE(p.tgl_selesai) = ?
                    ORDER BY p.tgl_selesai DESC";
            
            $stmt = $conn->prepare($sql); 
            $stmt->bind_param('s', $today); 
            $stmt->execute();
            $res = $stmt->get_result(); 
            $data = []; 
            while($r = $res->fetch_assoc()) {
                $data[] = $r;
            }
            json_response($data);
            break;
        
        // --- ENDPOINT: DETAIL RESEP UNTUK MODAL KONFIRMASI (Digunakan oleh Kasir Portal) ---
        case 'kasir_get_resep_detail':
            $id_pendaftaran = (int)($_GET['id'] ?? 0);
            if (!$id_pendaftaran) json_response(['status' => 'error', 'message' => 'ID Pendaftaran tidak valid.'], 400);

            // Kita hanya perlu mengambil resep_obat dari rekam_medis
            $sql = "SELECT rm.resep_obat
                    FROM rekam_medis rm
                    WHERE rm.id_pendaftaran = ?";
            
            $stmt = $conn->prepare($sql);
            if (!$stmt) json_response(['status' => 'error', 'message' => 'DB statement error.'], 500);
            $stmt->bind_param('i', $id_pendaftaran);
            $stmt->execute();
            $res = $stmt->get_result();
            $data = $res->fetch_assoc();
            $stmt->close();

            $resep_detail = [];
            if ($data && !empty($data['resep_obat'])) {
                $resep_decoded = json_decode($data['resep_obat'], true);
                if (is_array($resep_decoded)) {
                    $resep_detail = $resep_decoded;
                }
            }

            json_response(['status' => 'success', 'resep_detail' => $resep_detail]);
            break;
            
        // --- DETAIL STRUK PEMBAYARAN BY ID (DIKEMBALIKAN UNTUK KASIR RIWAYAT) ---
        case 'kasir_get_detail_struk':
            $id_pendaftaran = (int)($_GET['id'] ?? 0);
            if (!$id_pendaftaran) json_response(['status' => 'error', 'message' => 'ID Pendaftaran tidak valid.'], 400);

            $struk_data = get_detail_pembayaran_for_struk($conn, $id_pendaftaran);
            
            if (!$struk_data) {
                json_response(['status' => 'error', 'message' => 'Data pendaftaran tidak ditemukan.'], 404);
            }

            if ($struk_data['status_pembayaran'] !== 'Lunas') {
                 json_response(['status' => 'error', 'message' => 'Tagihan belum lunas.'], 403);
            }

            // Data struk kini mencakup jumlah_bayar_input dan kembalian dari DB
            
            json_response(['status' => 'success', 'data' => $struk_data]);
            break;


        // --- PROSES PEMBAYARAN (CRITICAL) ---
        case 'kasir_proses_pembayaran':
            if ($method !== 'POST') json_response(['status'=>'error','message'=>'Invalid Method'], 405);
            $id_pendaftaran = $_POST['id_pendaftaran'] ?? null; 
            $id_kasir = $_POST['id_kasir'] ?? null;
            $jumlah_bayar = (float)($_POST['jumlah_bayar'] ?? 0);

            if (!$id_pendaftaran || !$id_kasir) json_response(['status' => 'error', 'message' => 'Parameter kurang'], 400);

            $conn->begin_transaction();
            try {
                // 1. Ambil Tagihan Final & Data untuk Struk
                $tagihan_data = get_detail_pembayaran_for_struk($conn, $id_pendaftaran);
                
                if (!$tagihan_data) {
                    throw new Exception("Tagihan tidak valid.");
                }

                if (isset($tagihan_data['status_pembayaran']) && $tagihan_data['status_pembayaran'] == 'Lunas') {
                     throw new Exception("Tagihan sudah lunas.");
                }

                $total_tagihan = (float)$tagihan_data['total_biaya_final'];
                
                if ($jumlah_bayar < $total_tagihan) {
                    throw new Exception("Jumlah pembayaran kurang dari total tagihan.");
                }
                
                $kembalian = $jumlah_bayar - $total_tagihan;
                $now = date('Y-m-d H:i:s');
                
                // 2. Update status pembayaran DAN SIMPAN JUMLAH BAYAR & KEMBALIAN
                $stmt_update = $conn->prepare("
                    UPDATE pendaftaran 
                    SET 
                        status_pembayaran='Lunas', 
                        tgl_selesai=?, 
                        jumlah_bayar_input=?, 
                        kembalian=?,
                        id_kasir=?
                    WHERE id=?
                ");
                // Perhatikan urutan dan tipe parameter: s(string), d(double), d(double), i(int), i(int)
                $stmt_update->bind_param("sddii", $now, $jumlah_bayar, $kembalian, $id_kasir, $id_pendaftaran);
                $stmt_update->execute();
                
                $conn->commit();
                
                
                // 3. Ambil ulang data struk yang sudah lunas (data jumlah bayar dan kembalian sudah ada di DB)
                $struk_data = get_detail_pembayaran_for_struk($conn, $id_pendaftaran);
                
                
                json_response([
                    'status' => 'success', 
                    'message' => "Pembayaran Lunas. Kembalian: Rp " . number_format($kembalian, 0, ',', '.'),
                    'struk_data' => $struk_data
                ]);

            } catch (Exception $e) {
                $conn->rollback();
                json_response(['status' => 'error', 'message' => $e->getMessage()], 500);
            }
            break;
            
        // --- PROFILE MANAGEMENT (Copy dari Dokter/Farmasi, disesuaikan untuk Kasir) ---
        case 'kasir_check_password': 
            if ($method !== 'POST') json_response(['status' => 'error', 'message' => 'Metode tidak valid.'], 405);
            $id_user = (int)$_POST['id_user']; 
            $old_password = $_POST['password'] ?? $_POST['old_password']; 
            
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role='Kasir'");
            $stmt->bind_param('i', $id_user);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if (!$user || !password_verify($old_password, $user['password'])) {
                 json_response(['status' => 'error', 'message' => 'Password lama/verifikasi tidak cocok.'], 401);
            }
            json_response(['status' => 'success', 'message' => 'Password cocok.']);
            break;
            
        case 'kasir_update_username_only': 
            if ($method !== 'POST') json_response(['status' => 'error', 'message' => 'Metode tidak valid.'], 405);
            $id_user = (int)$_POST['id_user']; 
            $username = $_POST['username'];
            $old_password = $_POST['old_password']; 
            
            // 1. Check Password
            $check_res = $conn->query("SELECT password, nama_lengkap FROM users WHERE id = '$id_user' AND role='Kasir'");
            $user_data = $check_res->fetch_assoc();
            
            if (!$user_data || !password_verify($old_password, $user_data['password'])) {
                 json_response(['status' => 'error', 'message' => 'Verifikasi password gagal. Password lama salah.'], 401);
            }
            
            // 2. Update Username
            $stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ? AND role='Kasir'");
            $stmt->bind_param('si', $username, $id_user);
            
            if ($stmt->execute()) {
                $updated_user = [
                    'id' => $id_user,
                    'username' => $username,
                    'nama_lengkap' => $user_data['nama_lengkap'],
                    'role' => 'Kasir',
                ];

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

        case 'kasir_change_password':
            if ($method !== 'POST') json_response(['status' => 'error', 'message' => 'Metode tidak valid.'], 405);
            $id_user = (int)$_POST['id_user']; 
            $old_password = $_POST['old_password'];
            $new_password = $_POST['new_password'];
            
            // 1. Ambil password hash lama
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role='Kasir'");
            $stmt->bind_param('i', $id_user);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if (!$user || !password_verify($old_password, $user['password'])) {
                 json_response(['status' => 'error', 'message' => 'Password lama tidak cocok.'], 401);
            }
            
            // 2. Update password
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ?, must_change_pwd = 0 WHERE id = ? AND role='Kasir'");
            $stmt->bind_param('si', $hashed, $id_user);
            
            if ($stmt->execute()) {
                json_response(['status' => 'success', 'message' => 'Password berhasil diperbarui.']);
            } else {
                 json_response(['status' => 'error', 'message' => 'Gagal memperbarui password: ' . $stmt->error], 500);
            }
            break;

        default: json_response(['status' => 'error', 'message' => 'Action tidak dikenal'], 400);
    }
} catch (Exception $e) { json_response(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()], 500); }
?>