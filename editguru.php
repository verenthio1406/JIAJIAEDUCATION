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

// PROTEKSI: Hanya Head Admin dan Admin yang bisa akses
if ($current_user_role_id > 2) {
    header("Location: index.php?error=unauthorized");
    exit();
}

$message = '';
$message_type = '';

// Validasi ID guru dari URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: guru.php?error=invalid_id");
    exit();
}

$guru_id = (int)$_GET['id'];

if ($guru_id <= 0) {
    header("Location: guru.php?error=invalid_id");
    exit();
}

// Ambil data guru dari database
try {
    $stmt = $conn->prepare("SELECT guru_id, nama_guru, status FROM guru WHERE guru_id = ?");
    $stmt->bind_param("i", $guru_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        header("Location: guru.php?error=guru_not_found");
        exit();
    }
    
    $guru_data = $result->fetch_assoc();
    $stmt->close();
    
    // Ambil cabang yang sudah di-assign ke guru ini
    $stmt_cabang_assigned = $conn->prepare("SELECT cabang_id FROM cabangguru WHERE guru_id = ?");
    $stmt_cabang_assigned->bind_param("i", $guru_id);
    $stmt_cabang_assigned->execute();
    $result_cabang_assigned = $stmt_cabang_assigned->get_result();
    
    $assigned_cabang_ids = [];
    while ($row = $result_cabang_assigned->fetch_assoc()) {
        $assigned_cabang_ids[] = $row['cabang_id'];
    }
    $stmt_cabang_assigned->close();
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    header("Location: guru.php?error=database");
    exit();
}

// Ambil daftar semua cabang untuk checkbox
$cabang_list = [];
try {
    // Ambil cabang sesuai role user
    if ($current_user_role_id == 1) {
        // Head Admin - semua cabang
        $stmt_cabang = $conn->prepare("SELECT cabang_id, nama_cabang FROM cabang ORDER BY nama_cabang ASC");
        $stmt_cabang->execute();
    } else {
        // Admin - hanya cabangnya
        $stmt_cabang = $conn->prepare("SELECT c.cabang_id, c.nama_cabang 
                                       FROM cabang c 
                                       INNER JOIN user_cabang uc ON c.cabang_id = uc.cabang_id 
                                       WHERE uc.user_id = ? 
                                       ORDER BY c.nama_cabang ASC");
        $stmt_cabang->bind_param("i", $current_user_id);
        $stmt_cabang->execute();
    }
    
    $result_cabang = $stmt_cabang->get_result();
    
    while ($row = $result_cabang->fetch_assoc()) {
        $cabang_list[] = $row;
    }
    $stmt_cabang->close();
} catch (Exception $e) {
    error_log("Error loading cabang: " . $e->getMessage());
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
        
        // Ambil dan validasi input
        $nama_guru = trim($_POST['nama_guru'] ?? '');
        $status = $_POST['status'] ?? 'aktif';
        $cabang_ids = $_POST['cabang_ids'] ?? [];
        
        // Validasi
        $errors = [];
        
        if (empty($nama_guru)) {
            $errors[] = 'Nama guru wajib diisi!';
        }
        
        if (!in_array($status, ['aktif', 'nonaktif', 'cuti'])) {
            $errors[] = 'Status tidak valid!';
        }
        
        if (empty($cabang_ids)) {
            $errors[] = 'Pilih minimal 1 cabang!';
        }
        
        if (empty($errors)) {
            try {
                // Start transaction
                $conn->begin_transaction();
                
                // 1. Update data guru
                $stmt = $conn->prepare("UPDATE guru SET nama_guru = ?, status = ? WHERE guru_id = ?");
                $stmt->bind_param("ssi", $nama_guru, $status, $guru_id);
                
                if (!$stmt->execute()) {
                    throw new Exception("Gagal update data guru: " . $stmt->error);
                }
                $stmt->close();
                
                // 2. Ambil cabang yang sudah ada
                $stmt_existing = $conn->prepare("SELECT cabang_id FROM cabangguru WHERE guru_id = ?");
                $stmt_existing->bind_param("i", $guru_id);
                $stmt_existing->execute();
                $result_existing = $stmt_existing->get_result();
                
                $existing_cabang_ids = [];
                while ($row = $result_existing->fetch_assoc()) {
                    $existing_cabang_ids[] = $row['cabang_id'];
                }
                $stmt_existing->close();
                
                // Convert input ke integer
                $cabang_ids = array_map('intval', $cabang_ids);
                
                // 3. Tentukan cabang yang perlu ditambah dan dihapus
                $cabang_to_add = array_diff($cabang_ids, $existing_cabang_ids);
                $cabang_to_remove = array_diff($existing_cabang_ids, $cabang_ids);
                
                // 4. Tambah cabang baru
                if (!empty($cabang_to_add)) {
                    $stmt_insert = $conn->prepare("INSERT INTO cabangguru (guru_id, cabang_id) VALUES (?, ?)");
                    foreach ($cabang_to_add as $cabang_id) {
                        $stmt_insert->bind_param("ii", $guru_id, $cabang_id);
                        if (!$stmt_insert->execute()) {
                            throw new Exception("Gagal menambah cabang: " . $stmt_insert->error);
                        }
                    }
                    $stmt_insert->close();
                }
                
                // 5. Hapus cabang yang tidak dipilih (hanya yang tidak ada jadwal)
                if (!empty($cabang_to_remove)) {
                    foreach ($cabang_to_remove as $cabang_id) {
                        // Cek apakah cabang ini punya jadwal
                        $stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM jadwal_slot WHERE cabangguruID IN (SELECT id FROM cabangguru WHERE guru_id = ? AND cabang_id = ?)");
                        $stmt_check->bind_param("ii", $guru_id, $cabang_id);
                        $stmt_check->execute();
                        $result_check = $stmt_check->get_result();
                        $row_check = $result_check->fetch_assoc();
                        $stmt_check->close();
                        
                        if ($row_check['count'] > 0) {
                            // Ada jadwal, tidak bisa dihapus
                            throw new Exception("Tidak dapat menghapus cabang yang sudah memiliki jadwal. Silakan hapus jadwal terlebih dahulu.");
                        } else {
                            // Tidak ada jadwal, boleh dihapus
                            $stmt_delete = $conn->prepare("DELETE FROM cabangguru WHERE guru_id = ? AND cabang_id = ?");
                            $stmt_delete->bind_param("ii", $guru_id, $cabang_id);
                            if (!$stmt_delete->execute()) {
                                throw new Exception("Gagal menghapus cabang: " . $stmt_delete->error);
                            }
                            $stmt_delete->close();
                        }
                    }
                }
                
                // Commit transaction
                $conn->commit();
                
                // Redirect ke halaman guru dengan success message
                header("Location: guru.php?success=guru_updated");
                exit();
                
            } catch (Exception $e) {
                // Rollback jika ada error
                $conn->rollback();
                
                error_log("Edit Guru Error: " . $e->getMessage());
                
                $message = $e->getMessage();
                $message_type = 'danger';
            }
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'danger';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Edit Guru - Jia Jia Education</title>
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
        .cabang-checkbox-item {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            transition: all 0.2s;
        }
        .cabang-checkbox-item:hover {
            background-color: #f8f9fa;
            border-color: #dee2e6;
        }
        .cabang-checkbox-item input:checked + label {
            font-weight: 600;
            color: #0d6efd;
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
                        <a class="nav-link" href="siswa.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-user"></i></div>
                            Siswa
                        </a>
                        <a class="nav-link active" href="guru.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                            Guru
                        </a>

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
                    <h1 class="mt-4">Edit Guru</h1>
                    <br>

                    <?php if (!empty($message)): ?>
                        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="editguru.php?id=<?php echo $guru_id; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <!-- Data Guru -->
                        <div class="section-title">
                            <i class="fas fa-chalkboard-teacher me-2"></i>Data Guru
                        </div>
                        
                        <div class="row mb-4">
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Nama Guru <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama_guru" required maxlength="100"
                                       value="<?php echo htmlspecialchars($guru_data['nama_guru']); ?>"
                                       placeholder="Masukkan nama lengkap guru">
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" required>
                                    <option value="aktif" <?php echo ($guru_data['status'] == 'aktif') ? 'selected' : ''; ?>>Aktif</option>
                                    <option value="nonaktif" <?php echo ($guru_data['status'] == 'nonaktif') ? 'selected' : ''; ?>>Non-aktif</option>
                                    <option value="cuti" <?php echo ($guru_data['status'] == 'cuti') ? 'selected' : ''; ?>>Cuti</option>
                                </select>
                            </div>
                        </div>

                        <!-- Pilih Cabang -->
                        <div class="section-title">
                            <i class="fas fa-building me-2"></i>Cabang Mengajar
                        </div>

                        <div class="mb-4">
                            <?php if (!empty($cabang_list)): ?>
                                <p class="text-muted mb-3">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Pilih minimal 1 cabang tempat guru mengajar
                                </p>
                                <div class="row">
                                    <?php foreach ($cabang_list as $cabang): ?>
                                    <div class="col-md-6 col-lg-4">
                                        <div class="cabang-checkbox-item">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" 
                                                       name="cabang_ids[]" 
                                                       value="<?php echo (int)$cabang['cabang_id']; ?>" 
                                                       id="cabang_<?php echo (int)$cabang['cabang_id']; ?>"
                                                       <?php echo in_array($cabang['cabang_id'], $assigned_cabang_ids) ? 'checked' : ''; ?>>
                                                <label class="form-check-label" for="cabang_<?php echo (int)$cabang['cabang_id']; ?>">
                                                    <?php echo htmlspecialchars($cabang['nama_cabang']); ?>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Belum ada cabang yang tersedia. Silakan tambahkan cabang terlebih dahulu.
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Button Submit -->
                        <div class="d-flex justify-content-between pt-3 border-top">
                            <a href="guru.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Kembali
                            </a>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-save me-1"></i>Simpan Perubahan
                            </button>
                        </div>
                    </form>
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
        // Auto hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const checkboxes = document.querySelectorAll('input[name="cabang_ids[]"]:checked');
            
            if (checkboxes.length === 0) {
                e.preventDefault();
                alert('Pilih minimal 1 cabang!');
                return false;
            }
        });
    </script>
</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>