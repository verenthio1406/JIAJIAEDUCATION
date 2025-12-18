<?php
require 'check_login.php';
require 'config.php';
date_default_timezone_set('Asia/Jakarta');

// Ambil data user dari session
$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = getUserRoleId();
$current_user_cabang_id = getUserCabangId();
$current_user_cabang_name = $_SESSION['cabang_name'] ?? 'Semua Cabang';

// Validasi session
if (empty($current_user_id) || empty($current_user_role_id)) {
    session_destroy();
    header("Location: login.php?error=session_invalid");
    exit();
}

$message = '';
$message_type = '';

// Handle success messages dari GET parameter
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'profile_updated':
            $message = 'Profile berhasil diupdate!';
            $message_type = 'success';
            break;
        case 'password_changed':
            $message = 'Password berhasil diubah!';
            $message_type = 'success';
            break;
    }
}

// Handle error messages dari GET parameter
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'username_exists':
            $message = 'Username sudah digunakan user lain!';
            $message_type = 'danger';
            break;
        case 'update_failed':
            $message = 'Gagal mengupdate profile. Silakan coba lagi.';
            $message_type = 'danger';
            break;
        case 'password_wrong':
            $message = 'Password saat ini salah!';
            $message_type = 'danger';
            break;
        case 'password_failed':
            $message = 'Gagal mengubah password. Silakan coba lagi.';
            $message_type = 'danger';
            break;
    }
}

// UPDATE PROFILE INFO - HANYA UNTUK ADMIN/HEAD ADMIN
if (isset($_POST['update_profile']) && $current_user_role_id != 3) {
    $new_name = trim($_POST['name']);
    $new_username = trim($_POST['username']); 
    
    if (empty($new_name) || empty($new_username)) {
        header("Location: profile.php?error=empty_fields");
        exit();
    }
    
    try {
        // Cek apakah username sudah digunakan user lain
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");  
        $stmt->bind_param("si", $new_username, $current_user_id); 
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $stmt->close();
            header("Location: profile.php?error=username_exists");
            exit();
        }
        
        // Update database
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, username = ? WHERE user_id = ?");  
        $stmt->bind_param("ssi", $new_name, $new_username, $current_user_id);  
        
        if ($stmt->execute()) {
            // Update session
            $_SESSION['full_name'] = $new_name;
            $_SESSION['username'] = $new_username;  
            
            $stmt->close();
            
            // REDIRECT untuk mencegah form resubmit
            header("Location: profile.php?success=profile_updated");
            exit();
        } else {
            $stmt->close();
            header("Location: profile.php?error=update_failed");
            exit();
        }
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        header("Location: profile.php?error=update_failed");
        exit();
    }
}
    
// CHANGE PASSWORD - HANYA UNTUK ADMIN/HEAD ADMIN
if (isset($_POST['change_password']) && $current_user_role_id != 3) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        header("Location: profile.php?error=empty_fields");
        exit();
    }
    
    if ($new_password !== $confirm_password) {
        header("Location: profile.php?error=password_mismatch");
        exit();
    }
    
    if (strlen($new_password) < 6) {
        header("Location: profile.php?error=password_short");
        exit();
    }
    
    try {
        // Verifikasi current password
        $stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data_temp = $result->fetch_assoc();
        $stmt->close();
        
        if ($user_data_temp && password_verify($current_password, $user_data_temp['password'])) {
            // Hash new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Update password
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
            $stmt->bind_param("si", $hashed_password, $current_user_id);
            
            if ($stmt->execute()) {
                $stmt->close();
                
                // REDIRECT untuk mencegah form resubmit
                header("Location: profile.php?success=password_changed");
                exit();
            } else {
                $stmt->close();
                header("Location: profile.php?error=password_failed");
                exit();
            }
        } else {
            header("Location: profile.php?error=password_wrong");
            exit();
        }
    } catch (Exception $e) {
        error_log("Database error: " . $e->getMessage());
        header("Location: profile.php?error=password_failed");
        exit();
    }
}

// Ambil data user dari database
try {
    if ($current_user_role_id == 3) {
        // Orang Tua - query dari tabel siswa
        $stmt = $conn->prepare("SELECT s.siswa_id as user_id, s.username, s.name as full_name, 
                                s.nama_orangtua, s.no_telp, s.jenis_kelamin,
                                s.tanggal_lahir, s.asal_sekolah, s.status, s.cabang_id,
                                c.nama_cabang, 'Orang Tua' as role_name
                                FROM siswa s
                                LEFT JOIN cabang c ON s.cabang_id = c.cabang_id
                                WHERE s.siswa_id = ?");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
        $stmt->close();
    } else {
        // Admin/Head Admin - query dari tabel users
        $stmt = $conn->prepare("SELECT u.*, r.role_name
                                FROM users u 
                                LEFT JOIN role r ON u.role_id = r.role_id
                                WHERE u.user_id = ?");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();
        $stmt->close();
    }
    
    // Jika tidak ada data user
    if (!$user_data) {
        error_log("Profile.php: User data tidak ditemukan untuk user_id: " . $current_user_id);
        session_destroy();
        header("Location: login.php?error=user_not_found");
        exit();
    }
} catch (Exception $e) {
    error_log("Profile.php error: " . $e->getMessage());
    
    // Fallback: gunakan data dari session
    $user_data = [
        'user_id' => $current_user_id,
        'username' => $_SESSION['username'] ?? 'Unknown',
        'full_name' => $current_user_name,
        'role_name' => getRoleName($current_user_role_id),
        'nama_cabang' => $current_user_cabang_name
    ];
    
    if (empty($message)) {
        $message = 'Beberapa data mungkin tidak lengkap. Silakan logout dan login kembali.';
        $message_type = 'warning';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <title>Profile - Jia Jia Education</title>
        <link href="css/styles.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    </head>
    <body class="sb-nav-fixed">
        <!-- NAVBAR -->
        <nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
            <a class="navbar-brand ps-3" href="index.php">Jia Jia Education</a>
            <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            <div class="d-none d-md-inline-block form-inline ms-auto me-0 me-md-3 my-2 my-md-0"></div>
            <ul class="navbar-nav ms-auto ms-md-0 me-3 me-lg-4">
                <li class="nav-item d-none d-md-flex align-items-center me-3">
                    <span class="text-white">
                        <?php
                        date_default_timezone_set("Asia/Jakarta");
                        echo strftime("%A, %d %B %Y"); 
                        ?>
                    </span>
                </li>
                    
                <!-- User Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" id="navbarDropdown" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user fa-fw"></i> <?php echo htmlspecialchars($current_user_name); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdown">
                        <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                        <li><hr class="dropdown-divider" /></li>
                        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                    </ul>
                </li>
            </ul>
        </nav>
        
        <div id="layoutSidenav">
            <!-- SIDEBAR -->
            <div id="layoutSidenav_nav">
                <nav class="sb-sidenav accordion sb-sidenav-dark">
                    <div class="sb-sidenav-menu">
                        <div class="nav">
                            <div class="sb-sidenav-menu-heading">Main</div>
                            <a class="nav-link" href="index.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                                Landing Page
                            </a>

                            <?php if ($current_user_role_id <= 2): ?>
                            <div class="sb-sidenav-menu-heading">Management</div>
                            <a class="nav-link" href="absensi.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-clipboard-check"></i></div>
                                Presensi
                            </a>
                            <a class="nav-link active" href="kelola_semester.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-sync-alt"></i></div>
                                Kelola Semester
                            </a>
                            <?php endif; ?>
                            
                            <div class="sb-sidenav-menu-heading">Pembayaran</div>
                            <?php if ($current_user_role_id == 1): ?>
                            <a class="nav-link" href="verifikasi_pembayaran.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-check-circle"></i></div>
                                Verifikasi Pembayaran
                            </a>
                            <?php endif; ?>

                            <?php if ($current_user_role_id == 3): ?>
                            <a class="nav-link" href="pembayaran.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-file-upload"></i></div>
                                Upload Pembayaran
                            </a>
                            <?php endif; ?>

                            <?php if ($current_user_role_id == 1 || $current_user_role_id == 3): ?>
                            <a class="nav-link" href="riwayat_pembayaran.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-history"></i></div>
                                Riwayat Pembayaran
                            </a>
                            <?php endif; ?>

                            <?php if ($current_user_role_id <= 2): ?>
                            <div class="sb-sidenav-menu-heading">Manage</div>
                            <a class="nav-link" href="siswa.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-user"></i></div>
                                Siswa
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($current_user_role_id == 1): ?>
                            <a class="nav-link" href="guru.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                                Guru
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($current_user_role_id == 1): ?>
                            <div class="sb-sidenav-menu-heading">Setting</div>
                            <a class="nav-link" href="role.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-user-tag"></i></div>
                                Role Management
                            </a>
                            <a class="nav-link" href="user.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-users-cog"></i></div>
                                User Management
                            </a>
                            <a class="nav-link" href="cabang.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-building"></i></div>
                                Cabang Management
                            </a>
                            <a class="nav-link" href="paketkelas.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-graduation-cap"></i></div>
                                Paket Kelas
                            </a>
                            <a class="nav-link" href="kelola_slot.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-calendar-plus"></i></div>
                                Kelola Slot Jadwal
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="sb-sidenav-footer">
                        <div class="small">Logged in as:</div>
                        <?php echo htmlspecialchars($current_user_name); ?><br>
                        <small class="text-muted"><?php echo htmlspecialchars($current_user_cabang_name); ?></small>
                    </div>
                </nav>
            </div>
            
            <div id="layoutSidenav_content">
                <main>
                    <div class="container-fluid px-4">
                        <h1 class="mt-4">Profile</h1>
                        <br>

                        <?php if (!empty($message)): ?>
                            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                                <?php echo htmlspecialchars($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <div class="row">
                            <?php if ($current_user_role_id != 3): ?>
                            <!-- Profile Information - ADMIN/HEAD ADMIN -->
                            <div class="col-xl-6">
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <i class="fas fa-user me-1"></i>
                                        Informasi Pengguna
                                    </div>
                                    <div class="card-body">
                                        <form method="POST">
                                            <div class="mb-3">
                                                <label for="name" class="form-label">Nama <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="name" name="name" 
                                                    value="<?php echo htmlspecialchars($user_data['full_name']); ?>" required>
                                            </div>
                                            <div class="mb-3">
                                                <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="username" name="username" 
                                                    value="<?php echo htmlspecialchars($user_data['username']); ?>" required>
                                                <small class="text-muted">Username untuk login</small>
                                            </div>
                                            <div class="mb-3">
                                                <label for="role" class="form-label">Role</label>
                                                <input type="text" class="form-control" id="role" 
                                                    value="<?php echo htmlspecialchars($user_data['role_name'] ?? 'N/A'); ?>" 
                                                    disabled readonly>
                                            </div>
                                            <button type="submit" name="update_profile" class="btn btn-primary">
                                                <i class="fas fa-save"></i> Update Profile
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- Change Password - ADMIN/HEAD ADMIN -->
                            <div class="col-xl-6">
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <i class="fas fa-lock me-1"></i>
                                        Ubah Password
                                    </div>
                                    <div class="card-body">
                                        <form method="POST" id="changePasswordForm">
                                            <div class="mb-3">
                                                <label for="current_password" class="form-label">Password Saat Ini <span class="text-danger">*</span></label>
                                                <input type="password" class="form-control" id="current_password" 
                                                    name="current_password" required autocomplete="current-password">
                                            </div>
                                            <div class="mb-3">
                                                <label for="new_password" class="form-label">Password Baru <span class="text-danger">*</span></label>
                                                <input type="password" class="form-control" id="new_password" 
                                                    name="new_password" minlength="6" required autocomplete="new-password">
                                                <div class="form-text">Password harus minimal 6 karakter.</div>
                                            </div>
                                            <div class="mb-3">
                                                <label for="confirm_password" class="form-label">Konfirmasi Password Baru <span class="text-danger">*</span></label>
                                                <input type="password" class="form-control" id="confirm_password" 
                                                    name="confirm_password" minlength="6" required autocomplete="new-password">
                                            </div>
                                            <button type="submit" name="change_password" class="btn btn-warning">
                                                <i class="fas fa-key"></i> Ubah Password
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            
                            <?php else: ?>
                            <!-- Profile View - ORANG TUA (READ ONLY) -->
                            <div class="col-xl-12">
                                <div class="card mb-4">
                                    <div class="card-header bg-dark text-white">
                                        <i class="fas fa-user me-1"></i>
                                        Informasi Profil
                                    </div>
                                    <div class="card-body">
                                        <table class="table table-borderless">
                                            <tr>
                                                <th width="200">Username</th>
                                                <td><?php echo htmlspecialchars($user_data['username']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Nama Siswa</th>
                                                <td><?php echo htmlspecialchars($user_data['full_name']); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Nama Orang Tua</th>
                                                <td><?php echo htmlspecialchars($user_data['nama_orangtua'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>No. Telepon</th>
                                                <td><?php echo htmlspecialchars($user_data['no_telp'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Jenis Kelamin</th>
                                                <td>
                                                    <?php 
                                                    if (!empty($user_data['jenis_kelamin'])) {
                                                        echo ($user_data['jenis_kelamin'] == 'L') ? 'Laki-laki' : 'Perempuan';
                                                    } else {
                                                        echo '-';
                                                    }
                                                    ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Tanggal Lahir</th>
                                                <td><?php echo !empty($user_data['tanggal_lahir']) ? date('d F Y', strtotime($user_data['tanggal_lahir'])) : '-'; ?></td>
                                            </tr>
                                            <tr>
                                                <th>Asal Sekolah</th>
                                                <td><?php echo htmlspecialchars($user_data['asal_sekolah'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Cabang</th>
                                                <td><?php echo htmlspecialchars($user_data['nama_cabang'] ?? '-'); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Status</th>
                                                <td>
                                                    <span class="badge bg-<?php echo ($user_data['status'] == 'aktif') ? 'success' : 'secondary'; ?>">
                                                        <?php echo htmlspecialchars(ucfirst($user_data['status'] ?? 'aktif')); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Role</th>
                                                <td><span class="badge bg-info"><?php echo htmlspecialchars($user_data['role_name']); ?></span></td>
                                            </tr>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Account Information -->
                        <div class="row">
                            <div class="col-12">
                                <div class="card mb-4">
                                    <div class="card-header bg-dark text-white">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Informasi Akun
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p><strong>User ID:</strong> <?php echo htmlspecialchars($user_data['user_id']); ?></p>
                                                <p><strong>Status Akun:</strong> 
                                                    <span class="badge bg-success">Aktif</span>
                                                </p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Sesi Saat Ini:</strong> 
                                                    <?php echo htmlspecialchars($_SESSION['login_time'] ?? date('d M Y H:i:s')); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </main>
                
                <footer class="py-4 bg-light mt-auto">
                    <div class="container-fluid px-4">
                        <div class="d-flex align-items-center justify-content-between small">
                            <div class="text-muted">Copyright &copy; Jia Jia Education <?php echo date('Y'); ?></div>
                        </div>
                    </div>
                </footer>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="js/scripts.js"></script>

        <script>
            // Validasi password confirmation
            document.getElementById('changePasswordForm')?.addEventListener('submit', function(e) {
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                
                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    alert('Password baru dan konfirmasi tidak cocok!');
                    document.getElementById('confirm_password').focus();
                    return false;
                }
                
                if (newPassword.length < 6) {
                    e.preventDefault();
                    alert('Password harus minimal 6 karakter!');
                    document.getElementById('new_password').focus();
                    return false;
                }
            });

            // Auto hide alerts
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                });
            }, 5000);

            <?php if ($message_type == 'success' && isset($_POST['change_password'])): ?>
                // Clear password fields after successful password change
                document.getElementById('current_password').value = '';
                document.getElementById('new_password').value = '';
                document.getElementById('confirm_password').value = '';
            <?php endif; ?>
        </script>
    </body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>