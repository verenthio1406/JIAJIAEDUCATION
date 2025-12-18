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

// PROTEKSI: Hanya Head Admin dan Admin
if ($current_user_role_id > 2) {
    header("Location: guru.php?error=unauthorized");
    exit();
}

$message = '';
$message_type = '';

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
        
        if (isset($_POST['add_guru'])) {
            $nama_guru = trim($_POST['nama_guru']);
            $status = trim($_POST['status']);
            $cabang_ids = isset($_POST['cabang_ids']) ? $_POST['cabang_ids'] : [];
            
            // Validasi
            if (empty($nama_guru)) {
                $message = 'Nama guru wajib diisi!';
                $message_type = 'danger';
            }
            elseif (!in_array($status, ['aktif', 'nonaktif', 'cuti'])) {
                $message = 'Status tidak valid!';
                $message_type = 'danger';
            }
            elseif (empty($cabang_ids)) {
                $message = 'Pilih minimal 1 cabang!';
                $message_type = 'danger';
            }
            else {
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // 1. Insert ke tabel guru
                    $stmt = $conn->prepare("INSERT INTO guru (nama_guru, status) VALUES (?, ?)");
                    $stmt->bind_param("ss", $nama_guru, $status);
                    
                    if (!$stmt->execute()) {
                        throw new Exception('Gagal menambahkan guru.');
                    }
                    
                    $guru_id = $conn->insert_id;
                    $stmt->close();
                    
                    // 2. Insert ke tabel cabangguru untuk setiap cabang yang dipilih
                    $stmt = $conn->prepare("INSERT INTO cabangguru (guru_id, cabang_id) VALUES (?, ?)");
                    foreach ($cabang_ids as $cabang_id) {
                        $cabang_id = (int)$cabang_id;
                        $stmt->bind_param("ii", $guru_id, $cabang_id);
                        if (!$stmt->execute()) {
                            throw new Exception('Gagal menyimpan data cabang guru.');
                        }
                    }
                    $stmt->close();
                    
                    // Commit transaction
                    $conn->commit();
                    
                    header("Location: guru.php?success=guru_added");
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
        // Head Admin - semua cabang
        $stmt = $conn->prepare("SELECT cabang_id, nama_cabang FROM cabang ORDER BY nama_cabang");
        $stmt->execute();
    } else {
        // Admin - hanya cabangnya
        $stmt = $conn->prepare("SELECT cabang_id, nama_cabang FROM cabang WHERE cabang_id = ? ORDER BY nama_cabang");
        $stmt->bind_param("i", $current_user_cabang_id);
        $stmt->execute();
    }
    
    $cabang_result = $stmt->get_result();
    $cabang_options = [];
    while ($row = $cabang_result->fetch_assoc()) {
        $cabang_options[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $message = 'Gagal memuat data. Silakan refresh halaman.';
    $message_type = 'danger';
    $cabang_options = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Tambah Guru - Jia Jia Education</title>
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
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

                        <?php if ($current_user_role_id == 1): ?>
                        <div class="sb-sidenav-menu-heading">Pembayaran</div>
                        <a class="nav-link" href="verifikasi_pembayaran.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-check-circle"></i></div>
                            Verifikasi Pembayaran
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
                    <h1 class="mt-4">Tambah Guru Baru</h1>
                    <br>

                    <?php if (!empty($message)): ?>
                        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                            <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="card mb-4">
                        <div class="card-header bg-dark text-white">
                            <i class="fas fa-user-plus me-2"></i>Form Tambah Guru Baru
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label">Nama Guru <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" name="nama_guru" required maxlength="100"
                                           value="<?php echo isset($_POST['nama_guru']) ? htmlspecialchars($_POST['nama_guru']) : ''; ?>">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Cabang <span class="text-danger">*</span></label>
                                    <?php if ($current_user_role_id == 1): ?>
                                        <?php foreach ($cabang_options as $cabang): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="cabang_ids[]" 
                                                   value="<?php echo (int)$cabang['cabang_id']; ?>" 
                                                   id="cabang_<?php echo (int)$cabang['cabang_id']; ?>"
                                                   <?php echo (isset($_POST['cabang_ids']) && in_array($cabang['cabang_id'], $_POST['cabang_ids'])) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="cabang_<?php echo (int)$cabang['cabang_id']; ?>">
                                                <?php echo htmlspecialchars($cabang['nama_cabang']); ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?php foreach ($cabang_options as $cabang): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="cabang_ids[]" 
                                                   value="<?php echo (int)$cabang['cabang_id']; ?>" 
                                                   checked disabled>
                                            <label class="form-check-label">
                                                <?php echo htmlspecialchars($cabang['nama_cabang']); ?>
                                            </label>
                                            <input type="hidden" name="cabang_ids[]" value="<?php echo (int)$cabang['cabang_id']; ?>">
                                        </div>
                                        <?php endforeach; ?>
                                        <small class="text-muted">Admin hanya bisa menambahkan guru untuk cabangnya sendiri</small>
                                    <?php endif; ?>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Status <span class="text-danger">*</span></label>
                                    <select class="form-select" name="status" required>
                                        <option value="aktif" selected>Aktif</option>
                                        <option value="nonaktif">Non-aktif</option>
                                        <option value="cuti">Cuti</option>
                                    </select>
                                </div>

                                <div class="d-flex justify-content-between pt-3 border-top">
                                    <a href="guru.php" class="btn btn-secondary">
                                        <i class="fas fa-times me-1"></i>Batal
                                    </a>
                                    <button type="submit" name="add_guru" class="btn btn-primary px-4">
                                        <i class="fas fa-save me-1"></i>Simpan
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
            
            <footer class="py-4 bg-light mt-auto">
                <div class="container-fluid px-4">
                    <div class="text-muted">Copyright &copy; Jia Jia Education <?php echo date('Y'); ?></div>
                </div>
            </footer>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/scripts.js"></script>

    <script>
        // Auto hide alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert:not(.alert-info)');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            }, 5000);
        });
    </script>
</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>