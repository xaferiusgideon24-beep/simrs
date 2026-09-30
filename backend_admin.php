<?php
// Pengaturan Error Reporting
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Konfigurasi Database
$host = 'localhost';
$user = 'root';
$pass = ''; 
$db   = 'simrs_fivecare';

// Koneksi Database
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode(['status'=>'error', 'message'=>'Koneksi DB Gagal: ' . $conn->connect_error]));
}

// Fungsi Pembantu untuk Respon JSON
function jsonResponse($status, $message, $data = null) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

// Fungsi Pembantu untuk Sanitasi String (Dasar)
function cleanInput($conn, $data) {
    return mysqli_real_escape_string($conn, $data);
}

// Catatan: Fungsi logAdminActivity telah dihapus sesuai permintaan.
// Logika yang terkait dengan logging juga telah dihapus dari semua case di bawah.

$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // --- DASHBOARD STATS ---
    case 'get_dashboard_stats':
        $today = date('Y-m-d');
        
        $pasien = $conn->query("SELECT COUNT(*) as c FROM pasien")->fetch_assoc()['c'];
        $dokter = $conn->query("SELECT COUNT(*) as c FROM dokter")->fetch_assoc()['c'];
        
        $stmt_kunjungan = $conn->prepare("SELECT COUNT(*) as c FROM pendaftaran WHERE tgl_kunjungan=?");
        $stmt_kunjungan->bind_param("s", $today);
        $stmt_kunjungan->execute();
        $kunjungan = $stmt_kunjungan->get_result()->fetch_assoc()['c'];
        $stmt_kunjungan->close();

        $laporan = $conn->query("SELECT COUNT(*) as c FROM laporan WHERE status='Baru'")->fetch_assoc()['c'];
        
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $stmt_trend = $conn->prepare("SELECT COUNT(*) as c FROM pendaftaran WHERE tgl_kunjungan=?");
            $stmt_trend->bind_param("s", $date);
            $stmt_trend->execute();
            $count = $stmt_trend->get_result()->fetch_assoc()['c'];
            $stmt_trend->close();
            
            $trend[] = ['date' => date('d/m', strtotime($date)), 'count' => (int)$count];
        }

        echo json_encode([
            'pasien' => (int)$pasien,
            'dokter' => (int)$dokter, 
            'kunjungan_hari_ini' => (int)$kunjungan,
            'laporan_baru' => (int)$laporan,
            'trend' => $trend
        ]);
        break;

    // --- MANAJEMEN USER ---
    case 'get_users_by_role':
        $role = cleanInput($conn, $_GET['role'] ?? '');
        // FIX: Tambahkan id_dokter ke select query
        $stmt = $conn->prepare("SELECT id, username, nama_lengkap, role, id_dokter FROM users WHERE role = ? ORDER BY id DESC");
        $stmt->bind_param("s", $role); 
        $stmt->execute(); 
        $result = $stmt->get_result();
        $data = []; 
        while($row = $result->fetch_assoc()) { unset($row['password']); $data[] = $row; }
        echo json_encode($data);
        break;
    
    case 'get_role_counts':
        $a = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='Admin'")->fetch_assoc()['c'];
        $d = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='Dokter'")->fetch_assoc()['c'];
        $f = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='Farmasi'")->fetch_assoc()['c'];
        $k = $conn->query("SELECT COUNT(*) as c FROM users WHERE role='Kasir'")->fetch_assoc()['c']; 
        echo json_encode(['Admin'=>(int)$a, 'Dokter'=>(int)$d, 'Farmasi'=>(int)$f, 'Kasir'=>(int)$k]); 
        break;

    // --- SAVE USER (DIPERBAIKI UNTUK DOKTER) ---
    case 'save_user':
        $id = $_POST['id'] ?? ''; 
        $nama = cleanInput($conn, $_POST['nama_lengkap'] ?? ''); 
        $role = cleanInput($conn, $_POST['role'] ?? '');
        $id_dokter = NULL; 

        if(!empty($id)) {
            // Edit User: Hanya update nama dan role 
            $stmt = $conn->prepare("UPDATE users SET nama_lengkap=?, role=? WHERE id=?");
            $stmt->bind_param("ssi", $nama, $role, $id);
            if($stmt->execute()) {
                 jsonResponse('success', 'User diperbarui');
            } else {
                 jsonResponse('error', 'Gagal update user');
            }
            $stmt->close();
        } else {
            // --- Tambah User Baru ---
            $conn->begin_transaction();
            try {
                // 1. Logika Pembuatan Username dan Password Otomatis
                $code = '';
                if ($role == 'Admin') $code = 'ADM';
                else if ($role == 'Dokter') $code = 'DR';
                else if ($role == 'Farmasi') $code = 'FRM';
                else if ($role == 'Kasir') $code = 'KSR'; 
                
                $year = date('y'); 
                $prefix = $code . $year . '-';
                
                $qLast = $conn->query("SELECT username FROM users WHERE username LIKE '$prefix%' ORDER BY id DESC LIMIT 1");
                $nextNo = 1;
                if ($qLast && $qLast->num_rows > 0) {
                    $lastUser = $qLast->fetch_assoc()['username'];
                    $lastNo = (int)substr($lastUser, -3);
                    $nextNo = $lastNo + 1;
                }
                $username = $prefix . str_pad($nextNo, 3, '0', STR_PAD_LEFT);
                
                $plain = $code . '-' . rand(1000, 9999);
                $hash = password_hash($plain, PASSWORD_DEFAULT);
                
                // 2. Jika Role Dokter, buat entri di tabel dokter terlebih dahulu
                if ($role === 'Dokter') {
                    $id_poli = (int)($_POST['id_poli'] ?? 1); 
                    $spesialis = cleanInput($conn, $_POST['spesialis'] ?? 'Umum');
                    $jadwal = cleanInput($conn, $_POST['jadwal_praktik'] ?? 'Belum Diatur');
                    $kuota = (int)($_POST['kuota'] ?? 20);

                    if (empty($id_poli) || empty($spesialis) || empty($jadwal)) {
                        throw new Exception('Data Poli, Spesialis, atau Jadwal Dokter tidak lengkap.');
                    }
                    
                    // Insert ke tabel dokter
                    $stmt_d = $conn->prepare("INSERT INTO dokter (nama_dokter, id_poli, spesialis, jadwal_praktik, kuota) VALUES (?, ?, ?, ?, ?)");
                    $stmt_d->bind_param("sissi", $nama, $id_poli, $spesialis, $jadwal, $kuota); 
                    
                    if (!$stmt_d->execute()) {
                        throw new Exception('Gagal membuat data Dokter: ' . $stmt_d->error);
                    }
                    
                    $id_dokter = $conn->insert_id; // Ambil ID Dokter yang baru dibuat
                    $stmt_d->close();
                }
                
                // 3. Insert ke tabel users (Sertakan id_dokter jika ada)
                $stmt = $conn->prepare("INSERT INTO users (username, password, nama_lengkap, role, id_dokter, must_change_pwd) VALUES (?, ?, ?, ?, ?, 1)");
                // id_dokter di set NULL jika bukan Dokter
                $stmt->bind_param("ssssi", $username, $hash, $nama, $role, $id_dokter); 
                
                if(!$stmt->execute()) {
                    throw new Exception('Gagal membuat user: ' . $stmt->error);
                }
                $stmt->close();
                
                $conn->commit();
                
                echo json_encode([
                    'status'=>'success',
                    'message'=>'Akun berhasil dibuat!',
                    'credentials'=>['username'=>$username,'password'=>$plain]
                ]);

            } catch (Exception $e) {
                $conn->rollback();
                jsonResponse('error', 'Gagal membuat user: ' . $e->getMessage());
            }
        }
        break;
        
    // --- DELETE USER (DIPERBAIKI UNTUK MENGHAPUS DOKTER DARI users DAHULU) ---
    case 'delete_user': 
        $id = $_POST['id'] ?? ''; 
        $id_dokter_to_delete = null;
        
        $stmt_get_user = $conn->prepare("SELECT id_dokter, username, role FROM users WHERE id = ?");
        $stmt_get_user->bind_param("i", $id);
        $stmt_get_user->execute();
        $result = $stmt_get_user->get_result();
        $user_data = $result->fetch_assoc();
        $stmt_get_user->close();
        
        $conn->begin_transaction();
        try {
            if ($user_data && $user_data['role'] === 'Dokter') {
                $id_dokter_to_delete = $user_data['id_dokter'];
            }
            
            // 1. Hapus entri dari tabel users
            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                throw new Exception("Gagal menghapus entri users.");
            }
            $stmt->close();
            
            // 2. Hapus entri dari tabel dokter jika ada
            if ($id_dokter_to_delete) {
                 $stmt_del_dokter = $conn->prepare("DELETE FROM dokter WHERE id=?");
                 $stmt_del_dokter->bind_param("i", $id_dokter_to_delete);
                 if (!$stmt_del_dokter->execute()) {
                    throw new Exception("Gagal menghapus entri dokter.");
                 }
                 $stmt_del_dokter->close();
            }
            
            $conn->commit();
            jsonResponse('success','User berhasil dihapus');
        } catch (Exception $e) {
            $conn->rollback();
            jsonResponse('error', $e->getMessage());
        }
        break;

    // --- SAVE DOKTER (Hanya untuk EDIT) ---
    case 'save_dokter': 
        $id=$_POST['id']??''; 
        $pl=(int)$_POST['id_poli']; 
        $sp=cleanInput($conn,$_POST['spesialis']); 
        $jd=cleanInput($conn,$_POST['jadwal_praktik']); 
        $kt=(int)$_POST['kuota']; 
        
        if(!empty($id)) {
            // Update tabel dokter
            $stmt = $conn->prepare("UPDATE dokter SET id_poli=?, spesialis=?, jadwal_praktik=?, kuota=? WHERE id=?");
            $stmt->bind_param("issii", $pl, $sp, $jd, $kt, $id);
            if(!$stmt->execute()) {
                 jsonResponse('error','Gagal memperbarui data Dokter: ' . $stmt->error);
            }
            
            // Update nama dokter di tabel users juga (nama_dokter di tabel dokter harus sama dengan nama_lengkap di tabel users)
            $nama_dokter = cleanInput($conn, $_POST['nama_dokter'] ?? '');
            if(!empty($nama_dokter)) {
                $conn->query("UPDATE users SET nama_lengkap='$nama_dokter' WHERE id_dokter='$id' AND role='Dokter'");
            }
            
            jsonResponse('success','Data Dokter berhasil diperbarui.'); 
            $stmt->close();
        } else {
             jsonResponse('error', 'Operasi penambahan Dokter hanya diizinkan melalui menu Tambah User.');
        }
        break;
    
    // --- CRUD POLI ---
    case 'save_poli': 
        $id=$_POST['id'] ?? ''; 
        $nm=cleanInput($conn,$_POST['nama_poli']); 
        $rg=cleanInput($conn,$_POST['ruangan']); 
        $st=cleanInput($conn,$_POST['status']);
        $biaya=floatval($_POST['biaya_medis_standar'] ?? 150000.00); 

        if(!empty($id)) {
            $stmt = $conn->prepare("UPDATE poli SET nama_poli=?, ruangan=?, status=?, biaya_medis_standar=? WHERE id=?");
            $stmt->bind_param("ssdsi", $nm, $rg, $st, $biaya, $id); 
        } else {
            $stmt = $conn->prepare("INSERT INTO poli (nama_poli, ruangan, status, biaya_medis_standar) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("sssd", $nm, $rg, $st, $biaya);
        }
        
        if($stmt->execute()) {
             jsonResponse('success','Disimpan'); 
        }
        else jsonResponse('error','Gagal: ' . $stmt->error);
        $stmt->close();
        break;
    
    case 'delete_poli': 
        $id=$_POST['id'] ?? ''; 
        $stmt = $conn->prepare("DELETE FROM poli WHERE id=?");
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
             jsonResponse('success','Terhapus');
        }
        else jsonResponse('error', 'Gagal hapus poli.');
        $stmt->close();
        break;
    
    case 'update_status_poli': 
        $id = $_POST['id'] ?? '';
        $st = cleanInput($conn, $_POST['status'] ?? '');

        if (empty($id) || empty($st)) {
            jsonResponse('error', 'ID Poli atau Status tidak valid.');
        }

        $stmt = $conn->prepare("UPDATE poli SET status=? WHERE id=?");
        $stmt->bind_param("si", $st, $id);
        
        if ($stmt->execute()) {
            jsonResponse('success', 'Status Poli berhasil diperbarui menjadi ' . $st);
        } else {
            jsonResponse('error', 'Gagal memperbarui status Poli: ' . $stmt->error);
        }
        $stmt->close();
        break;

    // --- MANAJEMEN PASIEN ---
    case 'update_status_pasien': 
        // LOGIKA INI DINONAKTIFKAN KARENA KOLOM 'status' PADA TABEL 'pasien' DIHAPUS
        jsonResponse('error', 'Fungsi update status pasien dinonaktifkan.');
        break;
        
    // --- MANAJEMEN PROFIL ADMIN ---
    case 'admin_get_user_profile':
        $id_user = $_GET['id_user'] ?? null;
        $sql = "SELECT id, username, nama_lengkap, role FROM users WHERE id = ? AND role='Admin'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $id_user);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        if ($data) {
             jsonResponse('success', 'Data Profil Ditemukan', $data);
        } else {
             jsonResponse('error', 'Profil Admin tidak ditemukan.');
        }
        break;
        
    case 'admin_check_password': 
        if ($method !== 'POST') jsonResponse('error', 'Metode tidak valid.', 405);
        $id_user = (int)$_POST['id_user']; 
        $old_password = $_POST['old_password'];
        
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role='Admin'");
        $stmt->bind_param('i', $id_user);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if (!$user || !password_verify($old_password, $user['password'])) {
             jsonResponse('error', 'Password lama tidak cocok.', 401);
        }
        jsonResponse('success', 'Password cocok.');
        break;
        
    case 'admin_update_username_only': 
        if ($method !== 'POST') jsonResponse('error', 'Metode tidak valid.', 405);
        $id_user = (int)$_POST['id_user']; 
        $username = cleanInput($conn, $_POST['username']);
        $nama_lengkap = cleanInput($conn, $_POST['nama_lengkap']);

        $stmt = $conn->prepare("UPDATE users SET username = ?, nama_lengkap = ? WHERE id = ? AND role='Admin'");
        $stmt->bind_param('ssi', $username, $nama_lengkap, $id_user);
        
        if ($stmt->execute()) {
            $res = $conn->query("SELECT id, username, nama_lengkap, role, id_dokter, must_change_pwd FROM users WHERE id = '$id_user'");
            $updated_user = $res->fetch_assoc();

            if ($stmt->affected_rows > 0) {
                 jsonResponse('success', 'Username dan Nama berhasil diperbarui.', $updated_user);
            } else {
                 jsonResponse('warning', 'Username dan Nama tidak ada perubahan.', $updated_user);
            }
        } else {
            $msg = (strpos($conn->error, 'Duplicate entry') !== false) ? 'Username sudah digunakan.' : $conn->error;
            jsonResponse('error', 'Gagal memperbarui username: ' . $msg, 500);
        }
        break;

    case 'admin_change_password':
        if ($method !== 'POST') jsonResponse('error', 'Metode tidak valid.', 405);
        $id_user = (int)$_POST['id_user']; 
        $old_password = $_POST['old_password'];
        $new_password = $_POST['new_password'];
        
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND role='Admin'");
        $stmt->bind_param('i', $id_user);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();
        
        if (!$user || !password_verify($old_password, $user['password'])) {
             jsonResponse('error', 'Password lama tidak cocok.', 401);
        }
        
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = ?, must_change_pwd = 0 WHERE id = ? AND role='Admin'");
        $stmt->bind_param('si', $hashed, $id_user);
        
        if ($stmt->execute()) {
            jsonResponse('success', 'Password berhasil diperbarui.');
        } else {
             jsonResponse('error', 'Gagal memperbarui password: ' . $stmt->error, 500);
        }
        break;
        
    // --- LOG AKTIVITAS ADMIN (DIHAPUS) ---

    // --- SISA CRUD (TETAP SAMA) ---
    case 'get_all_kunjungan':
        $sql = "SELECT p.*, pas.nama_lengkap as nama_pasien, pas.no_rm, d.nama_dokter, pol.nama_poli 
                FROM pendaftaran p 
                JOIN pasien pas ON p.nik_pasien = pas.nik 
                JOIN dokter d ON p.id_dokter = d.id 
                JOIN poli pol ON p.id_poli = pol.id 
                ORDER BY p.tgl_kunjungan DESC 
                LIMIT 100";
        $res=$conn->query($sql); $d=[]; if($res)while($r=$res->fetch_assoc())$d[]=$r; echo json_encode($d); break;

    case 'get_all_laporan':
        $s=cleanInput($conn,$_GET['search']??''); $f=cleanInput($conn,$_GET['filter']??''); 
        $sql="SELECT l.*, p.nama_lengkap, p.no_rm FROM laporan l LEFT JOIN pasien p ON l.nik_pelapor=p.nik WHERE 1=1"; 
        if($f)$sql.=" AND l.status='$f'"; if($s)$sql.=" AND (l.nik_pelapor LIKE '%$s%' OR l.tgl_lapor LIKE '%$s%')"; 
        $sql.=" ORDER BY l.tgl_lapor DESC"; 
        $res=$conn->query($sql); $d=[]; if($res)while($r=$res->fetch_assoc()){if(!$r['nama_lengkap'])$r['nama_lengkap']='Staff';$d[]=$r;} echo json_encode($d); break;
    
    case 'update_status_laporan': 
        $id=$_POST['id']; $st=$_POST['status']; $tg=cleanInput($conn,$_POST['tanggapan']??''); 
        $sql="UPDATE laporan SET status='$st'"; if($tg)$sql.=", tanggapan='$tg'"; $sql.=" WHERE id='$id'"; 
        if($conn->query($sql)) {
             jsonResponse('success','Updated');
        }
        else jsonResponse('error','Gagal'); break;

    case 'get_poli_counts': $res=$conn->query("SELECT p.id, p.nama_poli, COUNT(d.id) as total_dokter FROM poli p LEFT JOIN dokter d ON p.id=d.id_poli GROUP BY p.id, p.nama_poli"); $d=[]; while($r=$res->fetch_assoc())$d[]=$r; echo json_encode($d); break;
    
    case 'get_all_poli': 
        $res=$conn->query("SELECT * FROM poli"); 
        $d=[]; while($r=$res->fetch_assoc())$d[]=$r; echo json_encode($d); break;

    case 'get_doctors_by_poli':
        $id_poli = (int)$_GET['id_poli'];
        // FIX: Gabungkan users dan dokter untuk mendapatkan nama_dokter yang benar dan semua data dokter
        $sql = "SELECT d.*, u.nama_lengkap AS nama_dokter, p.nama_poli 
                FROM dokter d 
                JOIN users u ON d.id = u.id_dokter
                JOIN poli p ON d.id_poli = p.id
                WHERE d.id_poli = ? 
                ORDER BY u.nama_lengkap ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id_poli);
        $stmt->execute();
        $res = $stmt->get_result();
        $d=[]; 
        while($r=$res->fetch_assoc()){
            $d[]=$r;
        } 
        echo json_encode($d);
        $stmt->close();
        break;


    case 'get_all_pasien': 
        $s = cleanInput($conn, $_GET['search'] ?? ''); 
        // Mengambil semua kolom kecuali 'status' dan 'password' (password tidak diambil di production)
        $sql="SELECT nik, no_rm, nama_lengkap, jenis_kelamin, tgl_lahir, tempat_lahir, alamat, no_telp, email FROM pasien WHERE nama_lengkap LIKE ? LIMIT 50"; 
        $search_term = "%{$s}%";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $search_term);
        $stmt->execute();
        $res = $stmt->get_result();
        $d=[]; while($r=$res->fetch_assoc())$d[]=$r; echo json_encode($d); 
        $stmt->close();
        break;

    default: jsonResponse('error', 'Action not found'); break;
}
?>