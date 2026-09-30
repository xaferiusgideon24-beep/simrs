<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$host = 'localhost';
$user = 'root';
$pass = ''; 
$db   = 'simrs_fivecare';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die(json_encode(['status'=>'error', 'message'=>'DB Connection Failed']));

function jsonResponse($status, $message, $data = null) {
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

switch ($action) {
    
    // --- AUTH STAFF ---
    case 'login':
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        $sql = "SELECT * FROM users WHERE username = '$username'";
        $result = $conn->query($sql);

        if($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if(password_verify($password, $user['password'])) {
                
                // CEK: WAJIB GANTI PASSWORD?
                if($user['must_change_pwd'] == 1) {
                    echo json_encode([
                        'status' => 'force_change_password', 
                        'message' => 'Login pertama kali. Harap ganti password.',
                        'data' => $user
                    ]);
                    exit;
                }

                unset($user['password']);
                jsonResponse('success', 'Login Berhasil', $user);
            } else {
                jsonResponse('error', 'Password salah!');
            }
        } else {
            jsonResponse('error', 'Username tidak ditemukan!');
        }
        break;

    case 'force_change_password':
        $id = $_POST['id'] ?? '';
        $new_pass = $_POST['new_password'] ?? '';
        
        if(strlen($new_pass) < 8) jsonResponse('error', 'Password minimal 8 karakter!');

        $hash = password_hash($new_pass, PASSWORD_DEFAULT);
        $sql = "UPDATE users SET password='$hash', must_change_pwd=0 WHERE id='$id'";
        
        if($conn->query($sql)) jsonResponse('success', 'Password berhasil diubah. Silahkan login kembali.');
        else jsonResponse('error', 'Gagal update password.');
        break;
}
?>