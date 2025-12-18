<?php
require 'check_login.php';
require 'config.php';
date_default_timezone_set('Asia/Jakarta');

// Ambil data user dari session
$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = $_SESSION['role_id'];
$current_user_cabang_id = getUserCabangId();
$current_user_cabang_name = $_SESSION['cabang_name'] ?? 'Semua Cabang';

// Get siswa ID from URL
$siswa_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($siswa_id <= 0) {
    header("Location: siswa.php?error=invalid_id");
    exit();
}

try {
    // Load data siswa dengan JOIN ke cabang dan paket kelas
    if ($current_user_role_id == 1) {
        // Head Admin - bisa lihat semua siswa
        $stmt = $conn->prepare("SELECT s.*, c.nama_cabang
                                FROM siswa s 
                                LEFT JOIN cabang c ON s.cabang_id = c.cabang_id 
                                WHERE s.siswa_id = ?");
        $stmt->bind_param("i", $siswa_id);
    } elseif ($current_user_role_id == 2) {
        // Admin - hanya siswa dari cabang yang di-handle
        $stmt = $conn->prepare("SELECT s.*, c.nama_cabang
                                FROM siswa s
                                LEFT JOIN cabang c ON s.cabang_id = c.cabang_id
                                WHERE s.siswa_id = ?
                                AND s.cabang_id IN (SELECT cabang_id FROM user_cabang WHERE user_id = ?)");
        $stmt->bind_param("ii", $siswa_id, $current_user_id);
    } else {
        // Orang Tua - hanya bisa lihat data anaknya sendiri
        $stmt = $conn->prepare("SELECT s.*, c.nama_cabang
                                FROM siswa s
                                LEFT JOIN cabang c ON s.cabang_id = c.cabang_id
                                WHERE s.siswa_id = ? AND s.siswa_id = ?");
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
    
    // Load paket kelas yang diambil siswa (AKTIF + HISTORY)
    $stmt = $conn->prepare("
        SELECT 
            sd.id as siswa_datales_id,
            sd.status,
            sd.is_history,
            sd.semester_ke,
            sd.tahun_ajaran,
            d.datales_id, 
            jt.jenistingkat_id,     
            jt.nama_jenistingkat, 
            d.harga, 
            c.nama_cabang,
            jl.name as nama_jenisles, 
            tl.name as nama_tipe,
            CASE 
                WHEN sd.is_history = 1 THEN 'History'
                WHEN sd.status = 'aktif' THEN 'Aktif'
                ELSE 'Non-aktif'
            END as status_label
        FROM siswa_datales sd
        INNER JOIN datales d ON sd.datales_id = d.datales_id
        LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
        LEFT JOIN cabang c ON d.cabang_id = c.cabang_id
        LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        WHERE sd.siswa_id = ?
        ORDER BY 
            sd.is_history ASC,
            sd.semester_ke DESC,
            jl.name ASC
    ");
    $stmt->bind_param("i", $siswa_id);
    $stmt->execute();
    $result_kelas = $stmt->get_result();
    $paket_kelas = [];
    while ($row = $result_kelas->fetch_assoc()) {
        $paket_kelas[] = $row;
    }
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    header("Location: siswa.php?error=database");
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Detail Siswa - Jia Jia Education</title>
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
        .info-row {
            padding: 0.75rem 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            font-weight: 600;
            color: #495057;
        }
        .paket-item {
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 1rem;
            margin-bottom: 0.75rem;
            background-color: #f8f9fa;
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
                    <h1 class="mt-4">Detail Siswa</h1>
                    <br>

                    <!-- Data Pribadi -->
                    <div class="section-title">
                        <i class="fas fa-user me-2"></i>Data Pribadi
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Nama Lengkap</div>
                                <div><?php echo htmlspecialchars($siswa_data['name']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Username</div>
                                <div><?php echo htmlspecialchars($siswa_data['username'] ?? '-'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Jenis Kelamin</div>
                                <div><?php echo $siswa_data['jenis_kelamin'] == 'L' ? 'Laki-laki' : 'Perempuan'; ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Tanggal Lahir</div>
                                <div>
                                    <?php 
                                    if (!empty($siswa_data['tanggal_lahir']) && $siswa_data['tanggal_lahir'] != '0000-00-00') {
                                        echo date('d F Y', strtotime($siswa_data['tanggal_lahir']));
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Cabang</div>
                                <div><?php echo htmlspecialchars($siswa_data['nama_cabang']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Asal Sekolah</div>
                                <div><?php echo htmlspecialchars($siswa_data['asal_sekolah'] ?? '-'); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Status</div>
                                <div>
                                    <span class="badge bg-<?php echo $siswa_data['status'] == 'aktif' ? 'success' : ($siswa_data['status'] == 'cuti' ? 'warning' : 'secondary'); ?>">
                                        <?php echo ucfirst($siswa_data['status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Tanggal Terdaftar</div>
                                <div><?php echo date('d F Y, H:i', strtotime($siswa_data['created_at'])); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Data Orang Tua -->
                    <div class="section-title">
                        <i class="fas fa-users me-2"></i>Data Orang Tua / Wali
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Nama Orang Tua</div>
                                <div><?php echo htmlspecialchars($siswa_data['nama_orangtua']); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">No. Telepon</div>
                                <div>
                                    <?php if (!empty($siswa_data['no_telp'])): ?>
                                    <a href="https://wa.me/62<?php echo ltrim($siswa_data['no_telp'], '0'); ?>" 
                                       target="_blank" class="text-decoration-none">
                                        <i class="fab fa-whatsapp text-success me-1"></i>
                                        <?php echo htmlspecialchars($siswa_data['no_telp']); ?>
                                    </a>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Paket Kelas -->
                    <div class="section-title">
                        <i class="fas fa-book me-2"></i>Paket Kelas yang Diambil
                    </div>

                    <div class="mb-4">
                        <?php if (count($paket_kelas) > 0): ?>
                            <div class="row">
                                <?php foreach ($paket_kelas as $kelas): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="paket-item <?php echo ($kelas['is_history'] == 1) ? 'opacity-75' : ''; ?>">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <strong><?php echo htmlspecialchars($kelas['nama_jenisles']); ?></strong> - 
                                                <?php echo htmlspecialchars($kelas['nama_tipe']); ?>
                                            </div>
                                            <?php if ($kelas['is_history'] == 1): ?>
                                                <span class="badge bg-secondary">History</span>
                                            <?php else: ?>
                                                <span class="badge bg-<?php echo ($kelas['status'] == 'aktif') ? 'success' : 'warning'; ?>">
                                                    <?php echo ucfirst($kelas['status']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <small class="text-muted d-block">
                                            <?php echo htmlspecialchars($kelas['nama_jenistingkat']); ?>
                                        </small>
                                        
                                        <div class="mt-2">
                                            <span class="badge bg-success">
                                                Rp <?php echo number_format($kelas['harga'], 0, ',', '.'); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Siswa belum terdaftar di paket kelas manapun.
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex justify-content-between pt-3 border-top">
                        <a href="siswa.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Kembali
                        </a>
                        
                        <?php if ($current_user_role_id <= 2): ?>
                        <a href="editsiswa.php?id=<?php echo $siswa_id; ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-1"></i>Edit Data Siswa
                        </a>
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
</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>