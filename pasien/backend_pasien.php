<?php
// backend_pasien.php - Fixed Queue Logic & Full Features + SATUSEHAT INTEGRATION
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// =====================================================================
// KONFIGURASI KREDENSIAL SATUSEHAT SANDBOX KAMU
// =====================================================================
define('SATUSEHAT_CLIENT_ID', 'm3jSqG6njYHXFrfQVAD1hlEb8Evmzr4nyH6u8h4b0bFGh1ug');
define('SATUSEHAT_CLIENT_SECRET', 'tShERPco0tlhSR1HAEvUyUACqfhuXG3EJF7PHAysHS9v73nmDM8ef1Dln4UQOyVC');
define('SATUSEHAT_BASE_URL', 'https://api-satusehat-stg.dto.kemkes.go.id/fhir-r4/v1');
define('SATUSEHAT_AUTH_URL', 'https://api-satusehat-stg.dto.kemkes.go.id/oauth2/v1/accesstoken');

// =====================================================================
// TRIK SUNTIK TOKEN DARURAT (HANYA SATU FUNGSI DI SINI)
// =====================================================================
function getSatuSehatToken() {
    // Kodingan akan langsung memakai Token Akses Sukses dari Postman kamu di sini
    return "eGDZ9sEvv1rUqVxAlxzh8oCpyYJx";
}
// =====================================================================

$host = 'localhost'; $user = 'root'; $pass = ''; $db = 'simrs_fivecare';
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) { http_response_code(500); die(json_encode(['status' => 'error', 'message' => 'Database Error'])); }

function jsonResponse($status, $message, $data = null) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]); exit;
}

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
        $data['resep_detail'] = [];
        if (!empty($data['resep_obat'])) {
            $resep_decoded = json_decode($data['resep_obat'], true);
            if (is_array($resep_decoded)) {
                $data['resep_detail'] = $resep_decoded;
            }
        }
        unset($data['resep_obat']);
    }
    return $data;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {

    // --- 1. REGISTER ---
    case 'register':
        $nik = $_POST['nik'] ?? ''; $nama = $_POST['nama'] ?? ''; $pass = $_POST['password'] ?? '';
        $check = $conn->query("SELECT nik FROM pasien WHERE nik = '$nik'");
        if($check->num_rows > 0) jsonResponse('error', 'NIK sudah terdaftar!');
        
        $hashed = password_hash($pass, PASSWORD_DEFAULT);
        
        $tahun_bulan = date('ym');
        $prefix_like = 'RM-' . $tahun_bulan . '-%';
        
        $q_max_rm = $conn->query("
            SELECT MAX(CAST(SUBSTRING_INDEX(no_rm, '-', -1) AS UNSIGNED)) AS max_no 
            FROM pasien 
            WHERE no_rm LIKE '$prefix_like'
        ");
        
        $max_no = 0;
        if ($q_max_rm && $q_max_rm->num_rows > 0) {
            $result_max = $q_max_rm->fetch_assoc();
            $max_no = (int)$result_max['max_no'];
        }
        
        $next_no = $max_no + 1;
        $no_rm = 'RM-' . $tahun_bulan . '-' . str_pad($next_no, 3, '0', STR_PAD_LEFT);

        $sql = "INSERT INTO pasien (nik, no_rm, nama_lengkap, jenis_kelamin, tgl_lahir, tempat_lahir, alamat, no_telp, email, password) VALUES ('$nik', '$no_rm', '$nama', '{$_POST['jk']}', '{$_POST['tgl_lahir']}', '{$_POST['tempat_lahir']}', '{$_POST['alamat']}', '{$_POST['no_telp']}', '{$_POST['email']}', '$hashed')";
        
        if($conn->query($sql)) {
            jsonResponse('success', 'Registrasi berhasil. No. RM Anda: ' . $no_rm); 
        } else {
             jsonResponse('error', 'Gagal Register. Error: ' . $conn->error);
        }
        break;

    // --- 2. LOGIN ---
    case 'login':
        $nik = $_POST['nik'] ?? ''; $password = $_POST['password'] ?? '';
        $res = $conn->query("SELECT * FROM pasien WHERE nik = '$nik'");
        if($res && $res->num_rows > 0) {
            $u = $res->fetch_assoc();
            if(password_verify($password, $u['password'])) { unset($u['password']); jsonResponse('success', 'OK', $u); }
        }
        jsonResponse('error', 'Login gagal'); break;

    // --- 3. DASHBOARD INFO ---
    case 'get_dashboard_info':
        $nik = $_GET['nik'] ?? '';
        $antrian_query = $conn->query("SELECT p.*, d.nama_dokter, pol.nama_poli, d.spesialis 
            FROM pendaftaran p 
            JOIN dokter d ON p.id_dokter=d.id 
            JOIN poli pol ON p.id_poli=pol.id 
            WHERE p.nik_pasien='$nik' AND p.status!='Selesai' 
            ORDER BY p.tgl_kunjungan ASC, p.no_antrian ASC");
        $antrian_list = [];
        if ($antrian_query) { while ($row = $antrian_query->fetch_assoc()) { $antrian_list[] = $row; } }
        echo json_encode(['antrian_aktif' => $antrian_list]); break;
        
    case 'get_all_active_queues':
        $nik = $_GET['nik'] ?? ''; $date = $_GET['date'] ?? ''; $status = $_GET['status'] ?? '';
        $sql = "SELECT p.*, d.nama_dokter, pol.nama_poli, d.spesialis FROM pendaftaran p JOIN dokter d ON p.id_dokter=d.id JOIN poli pol ON p.id_poli=pol.id WHERE p.nik_pasien='$nik' AND p.status!='Selesai'";
        if (!empty($date)) { $date = $conn->real_escape_string($date); $sql .= " AND p.tgl_kunjungan = '$date'"; } else { $today = date('Y-m-d'); $sql .= " AND p.tgl_kunjungan >= '$today'"; }
        if (!empty($status)) { $status = $conn->real_escape_string($status); $sql .= " AND p.status = '$status'"; }
        $sql .= " ORDER BY p.tgl_kunjungan ASC, p.no_antrian ASC";
        $res = $conn->query($sql); $list = []; if ($res) { while ($row = $res->fetch_assoc()) { $list[] = $row; } }
        echo json_encode($list); break;

    // --- 4. DATA MASTER (Poli, Dokter, Profil) ---
    case 'get_profile': 
        $nik = $_GET['nik'] ?? ''; 
        $res = $conn->query("SELECT nik, no_rm, nama_lengkap, jenis_kelamin, tgl_lahir, tempat_lahir, alamat, no_telp, email FROM pasien WHERE nik='$nik'");
        if ($res && $res->num_rows > 0) {
            $pasien_lokal = $res->fetch_assoc();
            
            $pasien_lokal['satusehat_status'] = 'Tidak Terhubung';
            $pasien_lokal['satusehat_ihs_id'] = '-';
            
            $token = getSatuSehatToken();
            if ($token) {
                $url_search = SATUSEHAT_BASE_URL . '/Patient?identifier=https://fhir.kemkes.go.id/id/nik|' . $nik;
                $ch = curl_init($url_search);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json'
                ]);
                $fhir_response = curl_exec($ch);
                curl_close($ch);
                
                $fhir_data = json_decode($fhir_response, true);
                if (isset($fhir_data['total']) && $fhir_data['total'] > 0) {
                    $pasien_lokal['satusehat_status'] = 'Terintegrasi Resmi';
                    $pasien_lokal['satusehat_ihs_id'] = $fhir_data['entry'][0]['resource']['id'] ?? '-';
                }
            }
            
            echo json_encode($pasien_lokal);
        } else {
            jsonResponse('error', 'Profil tidak ditemukan');
        }
        break;
        
    case 'update_profile': 
        $nik=$_POST['nik']; $al=$_POST['alamat']; $tlp=$_POST['no_telp']; $em=$_POST['email']; 
        $stmt = $conn->prepare("UPDATE pasien SET alamat=?, no_telp=?, email=? WHERE nik=?");
        $stmt->bind_param("ssss", $al, $tlp, $em, $nik);
        if($stmt->execute()) {
            $res_update = $conn->query("SELECT nik, no_rm, nama_lengkap FROM pasien WHERE nik='$nik'");
            $updated_user = $res_update->fetch_assoc();
            jsonResponse('success', 'Profil berhasil diupdate', $updated_user);
        } else {
             jsonResponse('error', 'Gagal update profil');
        }
        break;
        
    case 'get_poli': $r=$conn->query("SELECT * FROM poli WHERE status='Aktif'"); $d=[]; while($row=$r->fetch_assoc()) $d[]=$row; echo json_encode($d); break;
    case 'get_dokter': $ip=$_GET['id_poli']; $r=$conn->query("SELECT id, nama_dokter, jadwal_praktik, kuota FROM dokter WHERE id_poli='$ip'"); $d=[]; while($row=$r->fetch_assoc()) $d[]=$row; echo json_encode($d); break;
    case 'get_quota_info':
        $id=$_GET['id_dokter']; $booked=[]; $r=$conn->query("SELECT tgl_kunjungan, count(*) as c FROM pendaftaran WHERE id_dokter='$id' GROUP BY tgl_kunjungan");
        while($row=$r->fetch_assoc()) $booked[$row['tgl_kunjungan']] = $row['c'];
        $max=$conn->query("SELECT kuota FROM dokter WHERE id='$id'")->fetch_assoc()['kuota'];
        echo json_encode(['max_quota'=>$max, 'booked'=>$booked]); break;
        
    // --- 8. LUPA PASSWORD ---
    case 'forgot_password':
        $nik = $_POST['nik'] ?? '';
        $tgl_lahir = $_POST['tgl_lahir'] ?? '';
        $no_telp = $_POST['no_telp'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        
        if (strlen($new_pass) < 8) {
             jsonResponse('error', 'Password baru minimal 8 karakter.');
        }

        $stmt_check = $conn->prepare("SELECT * FROM pasien WHERE nik = ? AND tgl_lahir = ? AND no_telp = ?");
        $stmt_check->bind_param("sss", $nik, $tgl_lahir, $no_telp);
        $stmt_check->execute();
        $res = $stmt_check->get_result();
        
        if($res && $res->num_rows > 0) {
            $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE pasien SET password = ? WHERE nik = ?");
            $stmt->bind_param("ss", $hashed, $nik);
            if($stmt->execute()) {
                jsonResponse('success', 'Password berhasil diubah. Silakan login dengan password baru.');
            } else {
                jsonResponse('error', 'Gagal mengubah password di database.');
            }
        } else {
            jsonResponse('error', 'Verifikasi gagal! NIK, Tgl Lahir, atau No. HP tidak cocok.');
        }
        break;

    // --- 9. UBAH PASSWORD ---
    case 'change_password':
        $nik = $_POST['nik'] ?? '';
        $old_pass = $_POST['old_password'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        
        if (strlen($new_pass) < 8) {
             jsonResponse('error', 'Password baru minimal 8 karakter.');
        }
        
        $res = $conn->query("SELECT password FROM pasien WHERE nik = '$nik'");
        
        if($res && $res->num_rows > 0) {
            $u = $res->fetch_assoc();
            if(password_verify($old_pass, $u['password'])) {
                $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE pasien SET password = ? WHERE nik = ?");
                $stmt->bind_param("ss", $hashed, $nik);
                if($stmt->execute()) {
                    jsonResponse('success', 'Password berhasil diubah.');
                } else {
                    jsonResponse('error', 'Gagal mengubah password di database.');
                }
            } else {
                jsonResponse('error', 'Password lama salah.');
            }
        } else {
            jsonResponse('error', 'Pasien tidak ditemukan.');
        }
        break;

    // --- 5. PENDAFTARAN ---
    case 'daftar_poli':
        $nik=$_POST['nik']; $id_doc=$_POST['id_dokter']; $id_pol=$_POST['id_poli']; $tgl=$_POST['tgl_kunjungan'];
        
        $jadwal_res = $conn->query("SELECT jadwal_praktik FROM dokter WHERE id='$id_doc'")->fetch_assoc();
        $jadwal_praktik = $jadwal_res['jadwal_praktik'];
        $day_names = ['minggu','senin','selasa','rabu','kamis','jumat','sabtu'];
        $day_index = date('w', strtotime($tgl));
        $day_name = $day_names[$day_index];
       $jam_kunjungan = 'Tidak tersedia';

if (preg_match('/' . $day_name . '\s*\(([^)]+)\)/i', $jadwal_praktik, $matches)) { 
    $jam_kunjungan = $matches[1]; 
}

if ($jam_kunjungan == 'Tidak tersedia') {
    jsonResponse(
        'error',
        'Dokter tidak memiliki jadwal pada tanggal yang dipilih.'
    );
}
        $conn->begin_transaction();
        try {
            $q_cek = $conn->query("SELECT COUNT(*) as c FROM pendaftaran WHERE id_dokter='$id_doc' AND tgl_kunjungan='$tgl'")->fetch_assoc()['c'];
            $q_max = $conn->query("SELECT kuota FROM dokter WHERE id='$id_doc'")->fetch_assoc()['kuota'];
            
            if ($q_cek >= $q_max) {
                throw new Exception("Kuota Dokter Penuh untuk tanggal ini.");
            }

            $res_antri = $conn->query("SELECT COALESCE(MAX(no_antrian), 0) + 1 AS next_antrian FROM pendaftaran WHERE id_dokter='$id_doc' AND tgl_kunjungan='$tgl' FOR UPDATE");
            $antri = $res_antri->fetch_assoc()['next_antrian'];

            $stmt = $conn->prepare("INSERT INTO pendaftaran (nik_pasien, id_poli, id_dokter, tgl_kunjungan, jam_kunjungan, no_antrian, status) VALUES (?, ?, ?, ?, ?, ?, 'Menunggu Pemeriksaan')");
            $stmt->bind_param("ssissi", $nik, $id_pol, $id_doc, $tgl, $jam_kunjungan, $antri);
            
            if ($stmt->execute()) {
                $conn->commit();
                jsonResponse('success', 'Pendaftaran berhasil! Silakan tunggu antrian Anda.', ['no_antrian'=>$antri]); 
            } else {
                throw new Exception("Gagal menyimpan pendaftaran.");
            }
        } catch (Exception $e) {
            $conn->rollback();
            jsonResponse('error', $e->getMessage());
        }
        break;

    // --- 6. RIWAYAT & RM ---
    case 'get_riwayat':
        $nik = $_GET['nik'] ?? ''; $search = $_GET['search'] ?? ''; $date = $_GET['date'] ?? '';
        $sql = "SELECT p.id as id_pendaftaran, p.status_pembayaran, p.tgl_kunjungan, p.status, 
                pol.nama_poli, d.nama_dokter, rm.id as id_rm, 
                COALESCE(rm.diagnosa_utama, '-') as diagnosa_utama_code,
                COALESCE(i.display, 'Diagnosa tidak dicatat') as diagnosa_utama
                FROM pendaftaran p 
                JOIN poli pol ON p.id_poli = pol.id 
                JOIN dokter d ON p.id_dokter = d.id 
                LEFT JOIN rekam_medis rm ON p.id = rm.id_pendaftaran 
                LEFT JOIN icd_10 i ON rm.diagnosa_utama = i.code
                WHERE p.nik_pasien = '$nik'";
        
        if (!empty($search)) { 
            $s = $conn->real_escape_string($search); 
            $sql .= " AND (d.nama_dokter LIKE '%$s%' OR pol.nama_poli LIKE '%$s%' OR i.display LIKE '%$s%')"; 
        }
        if (!empty($date)) { 
            $d = $conn->real_escape_string($date); 
            $sql .= " AND p.tgl_kunjungan = '$d'"; 
        }
        $sql .= " AND p.status='Selesai' ORDER BY p.tgl_kunjungan DESC, p.id DESC";
        
        $result = $conn->query($sql); $data = []; 
        if($result) while($row = $result->fetch_assoc()) {
            $row['diagnosa_utama'] = !empty($row['diagnosa_utama_code']) ? "{$row['diagnosa_utama_code']} - {$row['diagnosa_utama']}" : $row['diagnosa_utama'];
            unset($row['diagnosa_utama_code']);
            $data[] = $row; 
        }
        echo json_encode($data); 
        break;

    case 'get_rm_detail_pasien':
        $id_rm = $_GET['id_rm'] ?? 0; $nik = $_GET['nik'] ?? ''; 
        $sql = "SELECT rm.*, p.tgl_kunjungan, d.nama_dokter, pol.nama_poli, pas.no_rm FROM rekam_medis rm JOIN pendaftaran p ON rm.id_pendaftaran = p.id JOIN dokter d ON p.id_dokter = d.id JOIN poli pol ON p.id_poli = pol.id JOIN pasien pas ON p.nik_pasien = pas.nik WHERE rm.id = '$id_rm' AND p.nik_pasien = '$nik'";
        $result = $conn->query($sql);
        if($result && $result->num_rows > 0) {
            $data = $result->fetch_assoc();
            foreach(['diagnosa_utama', 'diagnosa_sekunder'] as $field) {
                $data[$field.'_display'] = '';
                if ($data[$field]) {
                    $d = $conn->query("SELECT display FROM icd_10 WHERE code='{$data[$field]}'")->fetch_assoc();
                    $data[$field.'_display'] = $d ? "{$data[$field]} - {$d['display']}" : $data[$field];
                }
            }
            echo json_encode(['status'=>'success', 'data'=>$data]);
        } else { echo json_encode(['status'=>'error', 'message'=>'Data Rekam Medis Pasien tidak ditemukan']); }
        break;
        
    // --- NEW ENDPOINT: GET STRUK DETAIL PASIEN ---
    case 'get_struk_detail_pasien':
        $id_pendaftaran = (int)($_GET['id'] ?? 0);
        if (!$id_pendaftaran) jsonResponse('error', 'ID Pendaftaran tidak valid.', 400);

        $struk_data = get_detail_pembayaran_for_struk($conn, $id_pendaftaran);
        
        if (!$struk_data) {
            jsonResponse('error', 'Data pendaftaran tidak ditemukan.', 404);
        }

        if ($struk_data['status_pembayaran'] !== 'Lunas') {
             jsonResponse('error', 'Tagihan belum lunas.', 403);
        }

        jsonResponse('success', 'Detail struk berhasil dimuat.', $struk_data);
        break;

    // --- 7. LAPOR ---
    case 'lapor':
        $nik=$_POST['nik']; $jen=$_POST['jenis_masalah']; $ket=$_POST['keterangan'];
        $stmt = $conn->prepare("INSERT INTO laporan (nik_pelapor, role_pelapor, jenis_masalah, keterangan, tgl_lapor, status) VALUES (?, 'Pasien', ?, ?, NOW(), 'Baru')");
        $stmt->bind_param("sss", $nik, $jen, $ket);
        if($stmt->execute()) jsonResponse('success', 'Laporan Pasien Berhersil Terkirim'); else jsonResponse('error', 'Gagal menyimpan laporan Pasien.'); break;
    case 'get_laporan_saya': $nik=$_GET['nik']; $r=$conn->query("SELECT * FROM laporan WHERE nik_pelapor='$nik' ORDER BY tgl_lapor DESC"); $d=[]; while($row=$r->fetch_assoc()) $d[]=$row; echo json_encode($d); break;
        
    default: jsonResponse('error', 'Action not found');
}
?>