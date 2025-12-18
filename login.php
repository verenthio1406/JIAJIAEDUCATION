<?php
ob_start(); // Untuk handle header redirect
session_start();
require 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Username dan password wajib diisi!';
    } else {
        // 1. Cek di tabel users (Admin & Head Admin)
        // Gunakan LEFT JOIN agar user tetap muncul meskipun mapping role bermasalah
        $stmt = $conn->prepare("SELECT u.user_id, u.username, u.full_name, u.password, u.role_id, r.role_name 
                                FROM users u 
                                LEFT JOIN role r ON u.role_id = r.role_id 
                                WHERE u.username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows == 1) {
            // Login sebagai Admin/Head Admin
            $user = $result->fetch_assoc();

            $passwordMatch = false;
            // Cek menggunakan password_verify (jika hash)
            if (!empty($user['password']) && password_verify($password, $user['password'])) {
                $passwordMatch = true;
                // Optional: rehash jika perlu
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                    $upd->bind_param("si", $newHash, $user['user_id']);
                    $upd->execute();
                    $upd->close();
                }
            } elseif ($user['password'] === $password) {
                // Jika password disimpan plain text (sementara), terima dan migrasi ke hash
                $passwordMatch = true;
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $upd->bind_param("si", $newHash, $user['user_id']);
                $upd->execute();
                $upd->close();
            }

            if ($passwordMatch) {
                // Set session untuk Admin/Head Admin
                $_SESSION['user_id']    = $user['user_id'];
                $_SESSION['username']   = $user['username'];
                $_SESSION['full_name']  = $user['full_name'];
                $_SESSION['role_id']    = $user['role_id'];
                $_SESSION['role_name']  = $user['role_name'] ?? 'Role tidak tersedia';
                $_SESSION['login_time'] = date('Y-m-d H:i:s');

                // Set cabang untuk Admin
                if ($user['role_id'] == 2) {
                    // Ambil semua cabang yang di-handle oleh admin
                    $stmt_cabang = $conn->prepare("SELECT c.cabang_id, c.nama_cabang 
                                                    FROM user_cabang uc
                                                    INNER JOIN cabang c ON uc.cabang_id = c.cabang_id
                                                    WHERE uc.user_id = ?");
                    $stmt_cabang->bind_param("i", $user['user_id']);
                    $stmt_cabang->execute();
                    $result_cabang = $stmt_cabang->get_result();

                    $cabangs = [];
                    if ($result_cabang && $result_cabang->num_rows > 0) {
                        while ($row = $result_cabang->fetch_assoc()) {
                            $cabangs[] = [
                                'cabang_id' => $row['cabang_id'],
                                'nama_cabang' => $row['nama_cabang']
                            ];
                        }
                        // Simpan daftar cabang dan default (pertama)
                        $_SESSION['cabangs'] = $cabangs;
                        $_SESSION['cabang_id'] = $cabangs[0]['cabang_id'];
                        $_SESSION['cabang_name'] = $cabangs[0]['nama_cabang'];
                    } else {
                        $_SESSION['cabangs'] = [];
                        $_SESSION['cabang_id'] = null;
                        $_SESSION['cabang_name'] = 'Tidak ada cabang';
                    }
                    $stmt_cabang->close();
                } else {
                    // Head Admin (role selain 2)
                    $_SESSION['cabang_id'] = null;
                    $_SESSION['cabang_name'] = 'Semua Cabang';
                }

                $stmt->close();
                header("Location: index.php");
                exit();
            } else {
                $error = 'Password salah!';
            }
        } else {
            // 2. Username tidak ada di tabel users, cek di tabel siswa (Orang Tua)
            $stmt_siswa = $conn->prepare("SELECT s.siswa_id, s.username, s.name, s.password, s.nama_orangtua, s.cabang_id, c.nama_cabang
                                          FROM siswa s
                                          LEFT JOIN cabang c ON s.cabang_id = c.cabang_id
                                          WHERE s.username = ?");
            $stmt_siswa->bind_param("s", $username);
            $stmt_siswa->execute();
            $result_siswa = $stmt_siswa->get_result();

            if ($result_siswa && $result_siswa->num_rows == 1) {
                $siswa = $result_siswa->fetch_assoc();

                if (is_null($siswa['password']) || $siswa['password'] === '') {
                    $error = 'Akun siswa ini belum memiliki password. Silakan hubungi administrator.';
                } else {
                    $passwordMatch = false;
                    // Coba hash verify dulu
                    if (password_verify($password, $siswa['password'])) {
                        $passwordMatch = true;
                        if (password_needs_rehash($siswa['password'], PASSWORD_DEFAULT)) {
                            $newHash = password_hash($password, PASSWORD_DEFAULT);
                            $upd = $conn->prepare("UPDATE siswa SET password = ? WHERE siswa_id = ?");
                            $upd->bind_param("si", $newHash, $siswa['siswa_id']);
                            $upd->execute();
                            $upd->close();
                        }
                    } elseif ($siswa['password'] === $password) {
                        // plain text match -> rehash ke DB siswa
                        $passwordMatch = true;
                        $newHash = password_hash($password, PASSWORD_DEFAULT);
                        $upd = $conn->prepare("UPDATE siswa SET password = ? WHERE siswa_id = ?");
                        $upd->bind_param("si", $newHash, $siswa['siswa_id']);
                        $upd->execute();
                        $upd->close();
                    }

                    if ($passwordMatch) {
                        // Set session untuk Orang Tua
                        $_SESSION['siswa_id']   = $siswa['siswa_id'];
                        // supaya check_login.php yang cek user_id tetap sukses, set juga user_id
                        $_SESSION['user_id']    = $siswa['siswa_id'];
                        $_SESSION['username']   = $siswa['username'];
                        $_SESSION['full_name']  = $siswa['name']; 
                        $_SESSION['role_id']    = 3; // Role orang tua
                        $_SESSION['role_name']  = 'Orang Tua';
                        $_SESSION['cabang_id']  = $siswa['cabang_id'];
                        $_SESSION['cabang_name']= $siswa['nama_cabang'] ?? 'Tidak ada cabang';
                        $_SESSION['login_time'] = date('Y-m-d H:i:s');

                        $stmt_siswa->close();
                        header("Location: index.php");
                        exit();
                    } else {
                        $error = 'Password salah!';
                    }
                }
            } else {
                $error = 'Username tidak ditemukan!';
            }
            if ($stmt_siswa) $stmt_siswa->close();
        }
        if ($stmt) $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <title>Login - Jia Jia Education</title>
        <link href="css/styles.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        
        <!-- Prevent caching -->
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
        <meta http-equiv="Pragma" content="no-cache">
        <meta http-equiv="Expires" content="0">
    </head>
    <body class="bg-primary">
        <div id="layoutAuthentication">
            <div id="layoutAuthentication_content">
                <main>
                    <div class="container">
                        <div class="row justify-content-center">
                            <div class="col-lg-5">
                                <div class="card shadow-lg border-0 rounded-lg mt-5">
                                    <div class="card-header">
                                        <h3 class="text-center font-weight-light my-4">
                                            <i class="fas fa-sign-in-alt me-2"></i>Login
                                        </h3>
                                    </div>
                                    <div class="card-body">
                                        <?php if(isset($_GET['success']) && $_GET['success'] == 'password_reset'): ?>
                                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                                <i class="fas fa-check-circle me-2"></i>
                                                Password berhasil direset! Silakan login dengan password baru Anda.
                                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                            </div>
                                        <?php endif; ?>

                                        <?php if(!empty($error)): ?>
                                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                                <i class="fas fa-exclamation-triangle me-2"></i>
                                                <?php echo htmlspecialchars($error); ?>
                                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                            </div>
                                        <?php endif; ?>

                                        <form method="POST" action="" id="loginForm" autocomplete="off">
                                            <div class="form-floating mb-3">
                                                <input 
                                                    class="form-control" 
                                                    id="inputUsername" 
                                                    name="username" 
                                                    type="text" 
                                                    placeholder="username" 
                                                    autocomplete="off"
                                                    autocorrect="off"
                                                    autocapitalize="off"
                                                    spellcheck="false"
                                                    required 
                                                />
                                                <label for="inputUsername">Username</label>
                                            </div>
                                            <div class="form-floating mb-3">
                                                <input 
                                                    class="form-control" 
                                                    id="inputPassword" 
                                                    name="password" 
                                                    type="password" 
                                                    placeholder="Password" 
                                                    autocomplete="new-password"
                                                    required 
                                                />
                                                <label for="inputPassword">Password</label>
                                            </div>
                                            <div class="d-grid gap-2 mt-4 mb-0">
                                                <button type="submit" class="btn btn-primary btn-block">
                                                    <i class="fas fa-sign-in-alt me-2"></i>Login
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="card-footer text-center py-3">
                                        <div class="small mb-2">
                                            <a href="forgot_password.php">
                                                <i class="fas fa-key me-1"></i>Lupa Password?
                                            </a>
                                        </div>
                                        <div class="small text-muted">
                                            <i class="fas fa-info-circle me-1"></i>Hubungi Head Admin untuk pembuatan akun
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>

        <script>
            // Clear form on page load
            window.addEventListener('load', function() {
                document.getElementById('inputUsername').value = '';
                document.getElementById('inputPassword').value = '';
            });

            // Auto hide alerts
            <?php if(!empty($error)): ?>
                setTimeout(function() {
                    const alert = document.querySelector('.alert');
                    if(alert) {
                        alert.style.transition = 'opacity 0.5s';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.style.display = 'none', 500);
                    }
                }, 5000);
            <?php endif; ?>
        </script>
    </body>
</html>

<?php
if(isset($conn) && $conn) {
    mysqli_close($conn);
}
?>
