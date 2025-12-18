<?php
require 'check_login.php';
require 'config.php';
date_default_timezone_set('Asia/Jakarta');

// Generate CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ambil data user dari session
$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = $_SESSION['role_id'];
$current_user_cabang_id = getUserCabangId();
$current_user_cabang_name = $_SESSION['cabang_name'] ?? 'Semua Cabang';

$message = '';
$message_type = '';
$siswa_data = null;

// Get siswa ID from URL
$siswa_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($siswa_id <= 0) {
    header("Location: siswa.php?error=invalid_id");
    exit();
}

// Function to validate CSRF token
function validateCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    if (!isset($_POST['csrf_token']) || !validateCSRF($_POST['csrf_token'])) {
        $message = 'Invalid security token. Please try again.';
        $message_type = 'danger';
    } else {
        
        // UPDATE SISWA
        if (isset($_POST['update_siswa'])) {
            // Data Siswa
            $name = trim($_POST['name']);
            $username = trim($_POST['username']);
            $password = !empty($_POST['password']) ? $_POST['password'] : null;
            $jenis_kelamin = trim($_POST['jenis_kelamin']);
            $cabang_id = (int)$_POST['cabang_id'];
            $asal_sekolah = trim($_POST['asal_sekolah']);
            $status = trim($_POST['status']);
            $datales_ids = $_POST['datales_ids'] ?? [];
            
            // Data Orang Tua
            $nama_orangtua = trim($_POST['nama_orangtua']);
            $no_telp = trim($_POST['no_telp']);
            
            // PROTEKSI: Admin hanya bisa edit siswa di cabang yang di-handle
            if ($current_user_role_id == 2) {
                $stmt_check = $conn->prepare("SELECT cabang_id FROM user_cabang WHERE user_id = ? AND cabang_id = ?");
                $stmt_check->bind_param("ii", $current_user_id, $cabang_id);
                $stmt_check->execute();
                $result_check = $stmt_check->get_result();
                
                if ($result_check->num_rows == 0) {
                    $message = 'Anda tidak memiliki akses untuk mengedit siswa di cabang ini!';
                    $message_type = 'danger';
                }
                $stmt_check->close();
            }
            
            // Validasi Data Siswa
            if (empty($message) && empty($name)) {
                $message = 'Nama wajib diisi!';
                $message_type = 'danger';
            }
            elseif (empty($message) && empty($username)) {
                $message = 'Username wajib diisi!';
                $message_type = 'danger';
            }
            elseif (empty($message) && !preg_match('/^[a-zA-Z0-9_]{4,50}$/', $username)) {
                $message = 'Username harus 4-50 karakter, hanya huruf, angka, dan underscore!';
                $message_type = 'danger';
            }
            elseif (empty($message) && !empty($password) && strlen($password) < 6) {
                $message = 'Password minimal 6 karakter!';
                $message_type = 'danger';
            }
            elseif (empty($message) && empty($datales_ids)) {
                $message = 'Paket kelas wajib dipilih minimal 1!';
                $message_type = 'danger';
            }
            elseif (empty($message) && empty($nama_orangtua)) {
                $message = 'Nama orang tua wajib diisi!';
                $message_type = 'danger';
            } 
            elseif (empty($message) && empty($no_telp)) {
                $message = 'No. telp orang tua wajib diisi!';
                $message_type = 'danger';
            } 
            elseif (empty($message) && !preg_match('/^[0-9]{8,15}$/', preg_replace('/[^0-9]/', '', $no_telp))) {
                $message = 'Format no. telp tidak valid! Gunakan 8-15 digit angka.';
                $message_type = 'danger';
            }
            
            // Jika semua validasi lolos
            if (empty($message)) {
                $conn->begin_transaction();
                
                try {
                    // Cek username duplikat
                    $stmt_check_siswa = $conn->prepare("SELECT siswa_id FROM siswa WHERE username = ? AND siswa_id != ?");
                    $stmt_check_siswa->bind_param("si", $username, $siswa_id);
                    $stmt_check_siswa->execute();
                    $result_siswa = $stmt_check_siswa->get_result();
                    
                    $stmt_check_users = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                    $stmt_check_users->bind_param("s", $username);
                    $stmt_check_users->execute();
                    $result_users = $stmt_check_users->get_result();
                    
                    if ($result_siswa->num_rows > 0 || $result_users->num_rows > 0) {
                        throw new Exception('Username sudah digunakan! Gunakan username lain.');
                    }
                    
                    $stmt_check_siswa->close();
                    $stmt_check_users->close();
                    
                    // Update data siswa
                    if (!empty($password)) {
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("UPDATE siswa SET name = ?, username = ?, password = ?, jenis_kelamin = ?, cabang_id = ?, asal_sekolah = ?, status = ?, nama_orangtua = ?, no_telp = ? WHERE siswa_id = ?");
                        $stmt->bind_param("ssssissssi", $name, $username, $hashed_password, $jenis_kelamin, $cabang_id, $asal_sekolah, $status, $nama_orangtua, $no_telp, $siswa_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE siswa SET name = ?, username = ?, jenis_kelamin = ?, cabang_id = ?, asal_sekolah = ?, status = ?, nama_orangtua = ?, no_telp = ? WHERE siswa_id = ?");
                        $stmt->bind_param("ssisssssi", $name, $username, $jenis_kelamin, $cabang_id, $asal_sekolah, $status, $nama_orangtua, $no_telp, $siswa_id);
                    }
                  
                    if (!$stmt->execute()) {
                        throw new Exception('Gagal mengupdate data siswa.');
                    }
                    $stmt->close();
                    
                    // Hapus dan insert ulang paket kelas
                    $stmt_delete = $conn->prepare("DELETE FROM siswa_datales WHERE siswa_id = ?");
                    $stmt_delete->bind_param("i", $siswa_id);
                    $stmt_delete->execute();
                    $stmt_delete->close();
                    
                    $stmt_insert = $conn->prepare("INSERT INTO siswa_datales (siswa_id, datales_id) VALUES (?, ?)");
                    foreach ($datales_ids as $datales_id) {
                        $datales_id = (int)$datales_id;
                        $stmt_insert->bind_param("ii", $siswa_id, $datales_id);
                        
                        if (!$stmt_insert->execute()) {
                            throw new Exception('Gagal mengupdate paket kelas.');
                        }
                    }
                    $stmt_insert->close();
                    
                    $conn->commit();
                    
                    header("Location: siswa.php?success=siswa_updated");
                    exit();
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Database error: " . $e->getMessage());
                    $message = $e->getMessage();
                    $message_type = 'danger';
                }
            }
        }
    }
}

try {
    // Load data cabang
    if ($current_user_role_id == 1) {
        $stmt = $conn->prepare("SELECT cabang_id, nama_cabang FROM cabang ORDER BY nama_cabang");
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("SELECT c.cabang_id, c.nama_cabang 
                                FROM cabang c
                                INNER JOIN user_cabang uc ON c.cabang_id = uc.cabang_id
                                WHERE uc.user_id = ?
                                ORDER BY c.nama_cabang");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
    }
    $cabang_result = $stmt->get_result();
    $cabang_options = [];
    while ($row = $cabang_result->fetch_assoc()) {
        $cabang_options[] = $row;
    }
    $stmt->close();

    // Load data paket kelas
    if ($current_user_role_id == 1) {
        $stmt = $conn->prepare("SELECT d.datales_id, jt.nama_jenistingkat, d.harga, d.cabang_id, c.nama_cabang,
                                jl.name as nama_jenisles, tl.name as nama_tipe
                                FROM datales d
                                LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
                                LEFT JOIN cabang c ON d.cabang_id = c.cabang_id
                                LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                                LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
                                ORDER BY c.nama_cabang, jl.name, tl.name, jt.nama_jenistingkat");
        $stmt->execute();
    } else {
        $stmt = $conn->prepare("SELECT d.datales_id, jt.nama_jenistingkat, d.harga, d.cabang_id, c.nama_cabang,
                                jl.name as nama_jenisles, tl.name as nama_tipe
                                FROM datales d
                                LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
                                LEFT JOIN cabang c ON d.cabang_id = c.cabang_id
                                LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                                LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
                                WHERE d.cabang_id IN (SELECT cabang_id FROM user_cabang WHERE user_id = ?)
                                ORDER BY c.nama_cabang, jl.name, tl.name, jt.nama_jenistingkat");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
    }
    $datales_result = $stmt->get_result();
    $datales_options = [];
    while ($row = $datales_result->fetch_assoc()) {
        $datales_options[] = $row;
    }
    $stmt->close();

    // Load data siswa
    if ($current_user_role_id == 1) {
        $stmt = $conn->prepare("SELECT s.*, c.nama_cabang
                                FROM siswa s 
                                LEFT JOIN cabang c ON s.cabang_id = c.cabang_id 
                                WHERE s.siswa_id = ?");
        $stmt->bind_param("i", $siswa_id);
    } else {
        $stmt = $conn->prepare("SELECT s.*, c.nama_cabang
                                FROM siswa s
                                LEFT JOIN cabang c ON s.cabang_id = c.cabang_id
                                WHERE s.siswa_id = ?
                                AND s.cabang_id IN (SELECT cabang_id FROM user_cabang WHERE user_id = ?)");
        $stmt->bind_param("ii", $siswa_id, $current_user_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        header("Location: siswa.php?error=not_found");
        exit();
    }
    
    $siswa_data = $result->fetch_assoc();
    $stmt->close();
        
    // Load paket kelas yang sudah dipilih (HANYA YANG AKTIF)
    $stmt = $conn->prepare("
        SELECT datales_id 
        FROM siswa_datales 
        WHERE siswa_id = ? 
        AND status = 'aktif' 
        AND is_history = 0
    ");
    $stmt->bind_param("i", $siswa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $selected_datales = [];
    while ($row = $result->fetch_assoc()) {
        $selected_datales[] = $row['datales_id'];
    }
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $message = 'Gagal memuat data. Silakan refresh halaman.';
    $message_type = 'danger';
    $cabang_options = [];
    $datales_options = [];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Edit Siswa - Jia Jia Education</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <style>
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .paket-checkbox-item {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            transition: all 0.2s;
        }
        .paket-checkbox-item:hover {
            background-color: #f8f9fa;
            border-color: #dee2e6;
        }
        .paket-checkbox-item input:checked + label {
            font-weight: 600;
            color: #0d6efd;
        }
        .readonly-field {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
    </style>
</head>
<body class="sb-nav-fixed">
    <nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
        <a class="navbar-brand ps-3" href="index.php">Jia Jia Education</a>
        <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle"><i class="fas fa-bars"></i></button>
        <div class="d-none d-md-inline-block form-inline ms-auto me-0 me-md-3 my-2 my-md-0"></div>
        <ul class="navbar-nav ms-auto ms-md-0 me-3 me-lg-4">
            <li class="nav-item d-none d-md-flex align-items-center me-3">
                <span class="text-white">
                    <?php
                    $days_id = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thrusday', 'Friday', 'Saturday'];
                    $months_id = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
                    
                    $day = $days_id[date('w')];
                    $date = date('d');
                    $month = $months_id[date('n')];
                    $year = date('Y');
                    
                    echo "$day, $date $month $year";
                    ?>
                </span>
            </li>
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
        <div id="layoutSidenav_nav">
            <nav class="sb-sidenav accordion sb-sidenav-dark">
                <div class="sb-sidenav-menu">
                    <div class="nav">
                        <div class="sb-sidenav-menu-heading">Main</div>
                        <a class="nav-link" href="index.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                            Landing Page
                        </a>
                        
                        <div class="sb-sidenav-menu-heading">Management</div>
                        <a class="nav-link" href="absensi.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-clipboard-check"></i></div>
                            Presensi
                        </a>
                        <a class="nav-link" href="kelola_semester.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-sync-alt"></i></div>
                            Kelola Semester
                        </a>

                        <?php if ($current_user_role_id == 1): ?>
                        <div class="sb-sidenav-menu-heading">Pembayaran</div>
                        <a class="nav-link" href="verifikasi_pembayaran.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-check-circle"></i></div>
                            Verifikasi Pembayaran
                        </a>
                        <a class="nav-link" href="riwayat_pembayaran.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-history"></i></div>
                            Riwayat Pembayaran
                        </a>
                        <?php endif; ?>

                        <div class="sb-sidenav-menu-heading">Manage</div>
                        <a class="nav-link active" href="siswa.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-user"></i></div>
                            Siswa
                        </a>
                        <?php if ($current_user_role_id == 1): ?>
                        <a class="nav-link" href="guru.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                            Guru
                        </a>
                        <?php endif; ?>

                        <div class="sb-sidenav-menu-heading">Setting</div>
                        <?php if ($current_user_role_id == 1): ?>
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
                        <?php endif; ?>
                        <a class="nav-link" href="kelola_slot.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-calendar-plus"></i></div>
                            Kelola Slot Jadwal
                        </a>
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
                    <h1 class="mt-4">Edit Siswa</h1>
                    <br>

                    <?php if (!empty($message)): ?>
                        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($siswa_data): ?>
                    <form method="POST" action="editsiswa.php?id=<?php echo $siswa_id; ?>" id="formEditSiswa">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <!-- Data Pribadi -->
                        <div class="section-title">
                            <i class="fas fa-user me-2"></i>Data Pribadi
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" required maxlength="100" 
                                    value="<?php echo htmlspecialchars($siswa_data['name']); ?>"
                                    placeholder="Masukkan nama lengkap siswa">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Jenis Kelamin</label>
                                <input type="text" class="form-control readonly-field" 
                                    value="<?php echo ($siswa_data['jenis_kelamin'] == 'L') ? 'Laki-laki' : 'Perempuan'; ?>"
                                    readonly>
                                <input type="hidden" name="jenis_kelamin" value="<?php echo htmlspecialchars($siswa_data['jenis_kelamin']); ?>">
                                <small class="text-muted"><i class="fas fa-lock me-1"></i>Tidak dapat diubah</small>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Tanggal Lahir</label>
                                <input type="text" class="form-control readonly-field" 
                                    value="<?php 
                                        if (!empty($siswa_data['tanggal_lahir']) && $siswa_data['tanggal_lahir'] != '0000-00-00') {
                                            echo date('d/m/Y', strtotime($siswa_data['tanggal_lahir']));
                                        } else {
                                            echo '-';
                                        }
                                    ?>"
                                    readonly>
                                <small class="text-muted"><i class="fas fa-lock me-1"></i>Tidak dapat diubah</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Asal Sekolah</label>
                                <input type="text" class="form-control readonly-field" 
                                    value="<?php echo htmlspecialchars($siswa_data['asal_sekolah'] ?? '-'); ?>"
                                    readonly>
                                <input type="hidden" name="asal_sekolah" value="<?php echo htmlspecialchars($siswa_data['asal_sekolah'] ?? ''); ?>">
                                <small class="text-muted"><i class="fas fa-lock me-1"></i>Tidak dapat diubah</small>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Cabang</label>
                                <input type="text" class="form-control readonly-field" 
                                    value="<?php echo htmlspecialchars($siswa_data['nama_cabang']); ?>"
                                    readonly>
                                <input type="hidden" name="cabang_id" value="<?php echo $siswa_data['cabang_id']; ?>">
                                <small class="text-muted"><i class="fas fa-lock me-1"></i>Tidak dapat diubah</small>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" required>
                                    <option value="aktif" <?php echo ($siswa_data['status'] == 'aktif') ? 'selected' : ''; ?>>Aktif</option>
                                    <option value="nonaktif" <?php echo ($siswa_data['status'] == 'nonaktif') ? 'selected' : ''; ?>>Non-aktif</option>
                                    <option value="cuti" <?php echo ($siswa_data['status'] == 'cuti') ? 'selected' : ''; ?>>Cuti</option>
                                </select>
                            </div>
                        </div>

                        <!-- Paket Kelas (Read-only) -->
                        <div class="section-title">
                            <i class="fas fa-book me-2"></i>Paket Kelas yang Diambil
                        </div>

                        <div class="mb-4">
                            <?php if (!empty($selected_datales)): ?>
                                <div class="row">
                                    <?php 
                                    foreach ($datales_options as $datales): 
                                        if (in_array($datales['datales_id'], $selected_datales)):
                                    ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="paket-checkbox-item" style="background-color: #f8f9fa;">
                                            <strong><?php echo htmlspecialchars($datales['nama_jenisles']); ?></strong> - 
                                            <?php echo htmlspecialchars($datales['nama_tipe']); ?>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($datales['nama_jenistingkat']); ?><br>
                                                Rp <?php echo number_format($datales['harga'], 0, ',', '.'); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </div>
                                                        
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Belum ada paket kelas yang diambil.
                                </div>
                            <?php endif; ?>
                            
                            <!-- Hidden inputs untuk keep paket yang sudah ada -->
                            <?php foreach ($selected_datales as $datales_id): ?>
                                <input type="hidden" name="datales_ids[]" value="<?php echo (int)$datales_id; ?>">
                            <?php endforeach; ?>
                        </div>

                        <!-- Data Login -->
                        <div class="section-title">
                            <i class="fas fa-lock me-2"></i>Data Login
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" required maxlength="50" 
                                    pattern="[a-zA-Z0-9_]{4,50}"
                                    autocomplete="off"
                                    value="<?php echo htmlspecialchars($siswa_data['username'] ?? ''); ?>">
                                <small class="text-muted">4-50 karakter (huruf, angka, underscore)</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password</label>
                                <div class="input-group">
                                    <input type="password" class="form-control" name="password" id="password_siswa" 
                                        minlength="6" 
                                        autocomplete="new-password"
                                        placeholder="Kosongkan jika tidak ingin mengubah">
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Minimal 6 karakter (kosongkan jika tidak ingin mengubah)</small>
                            </div>
                        </div>

                        <!-- Data Orang Tua -->
                        <div class="section-title">
                            <i class="fas fa-users me-2"></i>Data Orang Tua / Wali
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Orang Tua <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama_orangtua" required maxlength="100" 
                                    value="<?php echo htmlspecialchars($siswa_data['nama_orangtua']); ?>">
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">No. Telepon <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" name="no_telp" required maxlength="15" 
                                    pattern="[0-9]{8,15}"
                                    placeholder="08123456789"
                                    value="<?php echo htmlspecialchars($siswa_data['no_telp']); ?>">
                                <small class="text-muted">8-15 digit angka</small>
                            </div>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="d-flex justify-content-between pt-3 border-top">
                            <a href="siswa.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Kembali
                            </a>
                            <button type="submit" name="update_siswa" class="btn btn-primary px-4">
                                <i class="fas fa-save me-1"></i>Simpan Perubahan
                            </button>
                        </div>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Data siswa tidak ditemukan!
                    </div>
                    <?php endif; ?>
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
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password_siswa');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        });

        // Auto hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);
    </script>
</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>