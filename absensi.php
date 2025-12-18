<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'check_login.php';
require 'config.php';
date_default_timezone_set('Asia/Jakarta');

// Generate CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = $_SESSION['role_id'];
$current_user_cabang_id = getUserCabangId();
$current_user_cabang_name = $_SESSION['cabang_name'] ?? 'Semua Cabang';

// Proteksi akses
if ($current_user_role_id > 2) {
    header("Location: index.php?error=unauthorized");
    exit();
}

$message = '';
$message_type = '';

// Handle success/error messages
if (isset($_GET['success'])) {
    $message = 'Presensi berhasil disimpan!';
    $message_type = 'success';
}
if (isset($_GET['error'])) {
    $message = 'Gagal menyimpan presensi!';
    $message_type = 'danger';
}

// Filter parameters
$filter_cabang = isset($_GET['cabang_id']) ? (int)$_GET['cabang_id'] : 0;
$filter_search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Load cabang options
try {
    if ($current_user_role_id == 1) {
        $stmt_cabang = $conn->prepare("SELECT cabang_id, nama_cabang FROM cabang ORDER BY nama_cabang");
        $stmt_cabang->execute();
    } else {
        $stmt_cabang = $conn->prepare("
            SELECT c.cabang_id, c.nama_cabang 
            FROM cabang c
            INNER JOIN user_cabang uc ON c.cabang_id = uc.cabang_id
            WHERE uc.user_id = ?
            ORDER BY c.nama_cabang
        ");
        $stmt_cabang->bind_param("i", $current_user_id);
        $stmt_cabang->execute();
    }
    $cabang_options = $stmt_cabang->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_cabang->close();
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $cabang_options = [];
}

// ✅ PERBAIKAN: Query untuk menampilkan siswa dengan info semester
try {
    $query_siswa = "
        SELECT DISTINCT
            s.siswa_id,
            s.name as nama_siswa,
            s.cabang_id,
            c.nama_cabang,
            COUNT(DISTINCT sd.datales_id) as jumlah_paket,
            GROUP_CONCAT(DISTINCT CONCAT(jl.name, ' - ', tl.name) SEPARATOR ' | ') as nama_paket_list
        FROM siswa s
        INNER JOIN cabang c ON s.cabang_id = c.cabang_id
        LEFT JOIN siswa_datales sd ON s.siswa_id = sd.siswa_id AND sd.status = 'aktif' AND sd.is_history = 0
        LEFT JOIN datales d ON sd.datales_id = d.datales_id
        LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
        LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        WHERE s.status = 'aktif'
    ";
    
    $params_siswa = [];
    $types_siswa = "";

    // Filter by role
    if ($current_user_role_id == 2) {
        $query_siswa .= " AND s.cabang_id IN (SELECT uc.cabang_id FROM user_cabang uc WHERE uc.user_id = ?)";
        $params_siswa[] = $current_user_id;
        $types_siswa .= "i";
    }

    // Filter by cabang
    if ($filter_cabang > 0) {
        $query_siswa .= " AND s.cabang_id = ?";
        $params_siswa[] = $filter_cabang;
        $types_siswa .= "i";
    }

    // Filter by search
    if (!empty($filter_search)) {
        $query_siswa .= " AND s.name LIKE ?";
        $params_siswa[] = '%' . $filter_search . '%';
        $types_siswa .= "s";
    }

    $query_siswa .= " 
        GROUP BY s.siswa_id, s.name, s.cabang_id, c.nama_cabang
        ORDER BY c.nama_cabang ASC, s.name ASC
    ";

    $stmt_siswa = $conn->prepare($query_siswa);

    if (!$stmt_siswa) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    if (!empty($params_siswa)) {
        $stmt_siswa->bind_param($types_siswa, ...$params_siswa);
    }

    if (!$stmt_siswa->execute()) {
        throw new Exception("Execute failed: " . $stmt_siswa->error);
    }

    $siswa_temp = $stmt_siswa->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_siswa->close();
    
    // ✅ Hitung progress per siswa (SEMUA paket aktif)
    $siswa_data = [];
    foreach ($siswa_temp as $siswa) {
        // Hitung total pertemuan dan hadir dari SEMUA paket aktif
        $stmt_progress = $conn->prepare("
            SELECT 
                COUNT(*) as total_pertemuan,
                SUM(CASE WHEN status_pertemuan IN ('hadir', 'tidak_hadir', 'sakit') THEN 1 ELSE 0 END) as total_diabsen
            FROM jadwal_pertemuan jp
            INNER JOIN siswa_datales sd ON jp.siswa_id = sd.siswa_id AND jp.datales_id = sd.datales_id
            WHERE jp.siswa_id = ? AND sd.status = 'aktif' AND sd.is_history = FALSE
        ");
        $stmt_progress->bind_param("i", $siswa['siswa_id']);
        $stmt_progress->execute();
        $progress = $stmt_progress->get_result()->fetch_assoc();
        $stmt_progress->close();
        
        $siswa['total_pertemuan'] = $progress['total_pertemuan'];
        $siswa['total_diabsen'] = $progress['total_diabsen'];
        
        $siswa_data[] = $siswa;
    }
    
} catch (Exception $e) {
    error_log("Error loading siswa: " . $e->getMessage());
    echo "<div class='container mt-3'><div class='alert alert-danger'>";
    echo "<strong>Error:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div></div>";
    $siswa_data = [];
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Presensi - Jia Jia Education</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <style>
        .siswa-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            background: #fff;
            transition: all 0.3s;
        }
        .siswa-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
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
                    $days_id = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
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
                        <a class="nav-link active" href="absensi.php">
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
                        <?php if ($current_user_role_id <= 2): ?>
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
                    <h1 class="mt-4">Presensi Siswa</h1>
                    <br>

                    <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- FILTER -->
                    <div class="card mb-4">
                        <div class="card-body py-3">
                            <form method="GET" class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label small mb-1">
                                        <i class="fas fa-search me-1"></i>Cari Nama Siswa
                                    </label>
                                    <input type="text" 
                                        class="form-control form-control-sm" 
                                        name="search" 
                                        value="<?php echo htmlspecialchars($filter_search); ?>" 
                                        placeholder="Ketik nama siswa...">
                                </div>
                                
                                <?php if ($current_user_role_id == 1): ?>
                                <div class="col-md-3">
                                    <label class="form-label small mb-1">Cabang</label>
                                    <select class="form-select form-select-sm" name="cabang_id">
                                        <option value="">Semua Cabang</option>
                                        <?php foreach ($cabang_options as $cabang): ?>
                                        <option value="<?php echo $cabang['cabang_id']; ?>" <?php echo $filter_cabang == $cabang['cabang_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cabang['nama_cabang']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <div class="col-md-auto">
                                    <button type="submit" class="btn btn-primary btn-sm px-3">
                                        <i class="fas fa-search me-1"></i>Cari
                                    </button>
                                    <a href="absensi.php" class="btn btn-secondary btn-sm px-3">
                                        <i class="fas fa-redo me-1"></i>Reset
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- SISWA CARDS -->
                    <div class="row">
                        <?php if (count($siswa_data) > 0): ?>
                            <?php foreach ($siswa_data as $siswa): 
                                $jumlah_paket = (int)$siswa['jumlah_paket'];
                            ?>
                            <div class="col-md-6">
                                <div class="siswa-card">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h5 class="mb-1"><?php echo htmlspecialchars($siswa['nama_siswa']); ?></h5>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($siswa['nama_cabang']); ?></span>
                                            <?php if ($jumlah_paket > 1): ?>
                                            <span class="badge bg-info"><?php echo $jumlah_paket; ?> Paket</span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge bg-primary fs-6">
                                            <?php echo $siswa['total_diabsen']; ?> / <?php echo $siswa['total_pertemuan']; ?>
                                        </span>
                                    </div>

                                    <div class="text-muted small mb-3">
                                        <i class="fas fa-book me-1"></i>
                                        <?php echo htmlspecialchars($siswa['nama_paket_list'] ?? '-'); ?>
                                    </div>

                                    <div class="d-grid">
                                        <a href="detail_absensi.php?siswa_id=<?php echo $siswa['siswa_id']; ?>" 
                                           class="btn btn-primary btn-sm">
                                            <i class="fas fa-clipboard-check me-1"></i>Detail Presensi
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <div class="col-12">
                            <div class="text-center py-5">
                                <i class="fas fa-user-check fa-4x text-muted mb-3"></i>
                                <h5 class="text-muted">Tidak Ada Siswa</h5>
                                <p class="text-muted">Tidak ada siswa aktif.</p>
                            </div>
                        </div>
                        <?php endif; ?>
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
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
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