<?php
session_start();
require 'config.php';

$message = '';
$message_type = '';
$step = 1; // Step 1: Verifikasi, Step 2: Set password baru

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    if (isset($_POST['verify'])) {
        // STEP 1: Verifikasi data user
        $username = trim($_POST['username']);
        $full_name = trim($_POST['full_name']);
        
        // Validasi input
        if (empty($username) || empty($full_name)) {
            $message = 'Username dan nama lengkap harus diisi!';
            $message_type = 'danger';
        } else {
            try {
                // 1) Coba cari di tabel users (case-insensitive)
                $query = "SELECT user_id, username, full_name, role_id
                        FROM users
                        WHERE username = ? 
                        AND LOWER(TRIM(full_name)) = LOWER(TRIM(?))
                        LIMIT 1";
                $stmt = mysqli_prepare($conn, $query);
                if (!$stmt) {
                    throw new Exception('Error pada query database: ' . mysqli_error($conn));
                }
                mysqli_stmt_bind_param($stmt, "ss", $username, $full_name);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                
                if ($result && mysqli_num_rows($result) > 0) {
                    $user = mysqli_fetch_assoc($result);
                    // Data cocok di tabel users
                    $_SESSION['reset_user_id'] = $user['user_id'];
                    $_SESSION['reset_username'] = $user['username'];
                    $_SESSION['reset_table'] = 'users'; // tandai sumber
                    $step = 2;
                    $message = 'Data terverifikasi! Silakan masukkan password baru.';
                    $message_type = 'success';
                    
                    mysqli_stmt_close($stmt);
                } else {
                    // 2) Jika tidak ditemukan di users, coba di tabel siswa (orangtua)
                    if ($stmt) mysqli_stmt_close($stmt);
                    
                    $query2 = "SELECT siswa_id, username, name, nama_orangtua
                            FROM siswa
                            WHERE username = ?
                            AND (LOWER(TRIM(nama_orangtua)) = LOWER(TRIM(?))
                                    OR LOWER(TRIM(name)) = LOWER(TRIM(?)))
                            LIMIT 1";
                    $stmt2 = mysqli_prepare($conn, $query2);
                    if (!$stmt2) {
                        throw new Exception('Error pada query database (siswa): ' . mysqli_error($conn));
                    }
                    mysqli_stmt_bind_param($stmt2, "sss", $username, $full_name, $full_name);
                    mysqli_stmt_execute($stmt2);
                    $result2 = mysqli_stmt_get_result($stmt2);
                    
                    if ($result2 && mysqli_num_rows($result2) > 0) {
                        $siswa = mysqli_fetch_assoc($result2);
                        // Data cocok di tabel siswa -> ini akun orangtua
                        $_SESSION['reset_user_id'] = $siswa['siswa_id'];
                        $_SESSION['reset_username'] = $siswa['username'];
                        $_SESSION['reset_table'] = 'siswa'; // tandai sumber
                        $step = 2;
                        $message = 'Data terverifikasi! Silakan masukkan password baru.';
                        $message_type = 'success';
                    } else {
                        $message = 'Username atau nama lengkap tidak cocok. Silakan coba lagi atau hubungi admin.';
                        $message_type = 'danger';
                    }
                    mysqli_stmt_close($stmt2);
                }
            } catch (Exception $e) {
                error_log("Forgot password error: " . $e->getMessage());
                $message = 'Terjadi kesalahan sistem. Silakan coba lagi.';
                $message_type = 'danger';
            }
        }
    }

    
    if (isset($_POST['reset_password'])) {
        // STEP 2: Reset password
        if (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_table'])) {
            header("Location: forgot_password.php");
            exit();
        }
        
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Validasi
        if (empty($new_password) || empty($confirm_password)) {
            $message = 'Password tidak boleh kosong!';
            $message_type = 'danger';
            $step = 2;
        } elseif ($new_password !== $confirm_password) {
            $message = 'Password dan konfirmasi password tidak cocok!';
            $message_type = 'danger';
            $step = 2;
        } elseif (strlen($new_password) < 6) {
            $message = 'Password minimal 6 karakter!';
            $message_type = 'danger';
            $step = 2;
        } else {
            try {
                $user_id = $_SESSION['reset_user_id'];
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Pilih tabel berdasarkan sumber yang disimpan di session
                if ($_SESSION['reset_table'] === 'siswa') {
                    $query = "UPDATE siswa SET password = ? WHERE siswa_id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    if (!$stmt) throw new Exception('Error pada query database: ' . mysqli_error($conn));
                    mysqli_stmt_bind_param($stmt, "si", $password_hash, $user_id);
                } else {
                    // default -> users
                    $query = "UPDATE users SET password = ? WHERE user_id = ?";
                    $stmt = mysqli_prepare($conn, $query);
                    if (!$stmt) throw new Exception('Error pada query database: ' . mysqli_error($conn));
                    mysqli_stmt_bind_param($stmt, "si", $password_hash, $user_id);
                }
                
                if (mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    
                    // Hapus session reset
                    unset($_SESSION['reset_user_id']);
                    unset($_SESSION['reset_username']);
                    unset($_SESSION['reset_table']);
                    
                    // Redirect ke login dengan success message
                    header("Location: login.php?success=password_reset");
                    exit();
                } else {
                    throw new Exception('Gagal mengupdate password');
                }
            } catch (Exception $e) {
                error_log("Reset password error: " . $e->getMessage());
                $message = 'Gagal mereset password. Silakan coba lagi.';
                $message_type = 'danger';
                $step = 2;
            }
        }
    }
}

// Jika sudah di step 2, ambil username dari session
if (isset($_SESSION['reset_user_id'])) {
    $step = 2;
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <title>Lupa Password - Jia Jia Education</title>
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
                                            <i class="fas fa-key me-2"></i>Lupa Password
                                        </h3>
                                    </div>
                                    <div class="card-body">
                                        <!-- Step Indicator -->
                                        <div class="d-flex justify-content-between mb-4">
                                            <div class="flex-fill text-center <?php echo ($step >= 1) ? 'text-primary fw-bold' : 'text-muted'; ?>">
                                                <i class="fas fa-check-circle me-1"></i>
                                                <small>Verifikasi</small>
                                            </div>
                                            <div class="flex-fill text-center <?php echo ($step == 2) ? 'text-primary fw-bold' : 'text-muted'; ?>">
                                                <i class="fas fa-lock me-1"></i>
                                                <small>Password Baru</small>
                                            </div>
                                        </div>

                                        <?php if(!empty($message)): ?>
                                            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                                                <?php if($message_type == 'danger'): ?>
                                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                                <?php elseif($message_type == 'success'): ?>
                                                    <i class="fas fa-check-circle me-2"></i>
                                                <?php elseif($message_type == 'info'): ?>
                                                    <i class="fas fa-info-circle me-2"></i>
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($message); ?>
                                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($step == 1): ?>
                                        <!-- STEP 1: Verifikasi Data -->
                                        <form method="POST" action="" id="verifyForm" autocomplete="off">
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
                                                    readonly
                                                    onfocus="this.removeAttribute('readonly');"
                                                    value=""
                                                    required 
                                                />
                                                <label for="inputUsername">Username</label>
                                            </div>

                                            <div class="form-floating mb-3">
                                                <input 
                                                    class="form-control" 
                                                    id="inputFullName" 
                                                    name="full_name" 
                                                    type="text" 
                                                    placeholder="Nama Lengkap" 
                                                    autocomplete="off"
                                                    readonly
                                                    onfocus="this.removeAttribute('readonly');"
                                                    value=""
                                                    required 
                                                />
                                                <label for="inputFullName">Nama Lengkap</label>
                                            </div>

                                            <div class="d-grid gap-2 mt-4 mb-0">
                                                <button type="submit" name="verify" class="btn btn-primary btn-block">
                                                    <i class="fas fa-check-circle me-2"></i>Verifikasi Data
                                                </button>
                                            </div>
                                        </form>

                                        <?php elseif ($step == 2): ?>
                                        <!-- STEP 2: Set Password Baru -->
                                        <div class="alert alert-info mb-3">
                                            <small>
                                                <strong>Username:</strong> <?php echo htmlspecialchars($_SESSION['reset_username']); ?>
                                            </small>
                                        </div>

                                        <form method="POST" action="" id="resetForm" autocomplete="off">
                                            <div class="form-floating mb-3">
                                                <input 
                                                    class="form-control" 
                                                    id="inputNewPassword" 
                                                    name="new_password" 
                                                    type="password" 
                                                    placeholder="Password Baru" 
                                                    autocomplete="new-password"
                                                    readonly
                                                    onfocus="this.removeAttribute('readonly');"
                                                    value=""
                                                    minlength="6"
                                                    required 
                                                />
                                                <label for="inputNewPassword">Password Baru</label>
                                            </div>

                                            <div class="form-floating mb-3">
                                                <input 
                                                    class="form-control" 
                                                    id="inputConfirmPassword" 
                                                    name="confirm_password" 
                                                    type="password" 
                                                    placeholder="Konfirmasi Password" 
                                                    autocomplete="new-password"
                                                    readonly
                                                    onfocus="this.removeAttribute('readonly');"
                                                    value=""
                                                    minlength="6"
                                                    required 
                                                />
                                                <label for="inputConfirmPassword">Konfirmasi Password</label>
                                            </div>

                                            <div class="d-grid gap-2 mt-4 mb-0">
                                                <button type="submit" name="reset_password" class="btn btn-primary btn-block">
                                                    <i class="fas fa-key me-2"></i>Reset Password
                                                </button>
                                            </div>
                                        </form>
                                        <?php endif; ?>

                                    </div>
                                    <div class="card-footer text-center py-3">
                                        <div class="small">
                                            <a href="<?php echo ($step == 2) ? 'forgot_password.php' : 'login.php'; ?>">
                                                <i class="fas fa-arrow-left me-1"></i>
                                                <?php echo ($step == 2) ? 'Kembali ke Verifikasi' : 'Kembali ke Login'; ?>
                                            </a>
                                        </div>
                                        <?php if ($step == 1): ?>
                                        <div class="small text-muted mt-2">
                                            <i class="fas fa-info-circle me-1"></i>Masukkan data sesuai yang terdaftar di sistem
                                        </div>
                                        <?php endif; ?>
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
            // Aggressive autofill prevention
            (function() {
                'use strict';
                <?php if ($step == 1): ?>
                const usernameInput = document.getElementById('inputUsername');
                const fullNameInput = document.getElementById('inputFullName');
                
                if (usernameInput) usernameInput.value = '';
                if (fullNameInput) fullNameInput.value = '';
                <?php else: ?>
                const newPasswordInput = document.getElementById('inputNewPassword');
                const confirmPasswordInput = document.getElementById('inputConfirmPassword');
                
                if (newPasswordInput) newPasswordInput.value = '';
                if (confirmPasswordInput) confirmPasswordInput.value = '';
                <?php endif; ?>
            })();

            window.addEventListener('pageshow', function(event) {
                if (event.persisted) {
                    window.location.reload();
                } else {
                    clearForm();
                }
            });

            function clearForm() {
                <?php if ($step == 1): ?>
                const form = document.getElementById('verifyForm');
                const usernameInput = document.getElementById('inputUsername');
                const fullNameInput = document.getElementById('inputFullName');
                
                if (usernameInput) {
                    usernameInput.value = '';
                    usernameInput.style.backgroundColor = 'white';
                }
                if (fullNameInput) {
                    fullNameInput.value = '';
                    fullNameInput.style.backgroundColor = 'white';
                }
                if (form) form.reset();
                if (usernameInput) usernameInput.focus();
                <?php else: ?>
                const form = document.getElementById('resetForm');
                const newPasswordInput = document.getElementById('inputNewPassword');
                const confirmPasswordInput = document.getElementById('inputConfirmPassword');
                
                if (newPasswordInput) {
                    newPasswordInput.value = '';
                    newPasswordInput.style.backgroundColor = 'white';
                }
                if (confirmPasswordInput) {
                    confirmPasswordInput.value = '';
                    confirmPasswordInput.style.backgroundColor = 'white';
                }
                if (form) form.reset();
                if (newPasswordInput) newPasswordInput.focus();
                <?php endif; ?>
            }

            window.addEventListener('load', function() {
                setTimeout(clearForm, 100);
            });

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', clearForm);
            } else {
                clearForm();
            }

            <?php if(!empty($message) && $message_type == 'danger'): ?>
                setTimeout(clearForm, 1500);
            <?php endif; ?>
            
            <?php if(!empty($message)): ?>
                setTimeout(function() {
                    const alert = document.querySelector('.alert');
                    if(alert) {
                        alert.style.transition = 'opacity 0.5s';
                        alert.style.opacity = '0';
                        setTimeout(() => alert.style.display = 'none', 500);
                    }
                }, 5000);
            <?php endif; ?>

            window.addEventListener('beforeunload', clearForm);

            if (window.history.replaceState) {
                window.history.replaceState(null, null, window.location.href);
            }
        </script>
    </body>
</html>

<?php
if(isset($conn) && $conn) {
    mysqli_close($conn);
}
?>