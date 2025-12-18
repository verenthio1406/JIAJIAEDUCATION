<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'check_login.php';
require 'config.php';
require_once 'generate_jadwal_semester.php';
date_default_timezone_set('Asia/Jakarta');

// ========================================
// GENERATE CSRF TOKEN
// ========================================
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ========================================
// GET CURRENT USER INFO
// ========================================
$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = $_SESSION['role_id'];
$current_user_cabang_id = getUserCabangId();
$current_user_cabang_name = $_SESSION['cabang_name'] ?? 'Semua Cabang';

// Check authorization (only admin and manager)
if ($current_user_role_id > 2) {
    header("Location: siswa.php?error=unauthorized");
    exit();
}

// ========================================
// HELPER FUNCTIONS
// ========================================
function validateCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function validatePhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    return preg_match('/^[0-9]{8,15}$/', $phone);
}

// ========================================
// PROCESS FORM SUBMISSION
// ========================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_siswa'])) {
    
    // Validate CSRF token
    if (!validateCSRF($_POST['csrf_token'])) {
        die("ERROR: Invalid CSRF token");
    }
    
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $jenis_kelamin = trim($_POST['jenis_kelamin'] ?? '');
    $tanggal_lahir = trim($_POST['tanggal_lahir'] ?? '');
    $cabang_id = (int)($_POST['cabang_id'] ?? 0);
    $asal_sekolah = trim($_POST['asal_sekolah'] ?? '');
    $status = trim($_POST['status'] ?? 'aktif');
    $nama_orangtua = trim($_POST['nama_orangtua'] ?? '');
    $no_telp = trim($_POST['no_telp'] ?? '');
    
    // Parse paket data
    $paket_data_raw = $_POST['paket_data'] ?? [];
    $paket_data = [];
    
    foreach ($paket_data_raw as $json_str) {
        $decoded = json_decode($json_str, true);
        if ($decoded) {
            $paket_data[] = $decoded;
        }
    }
    
    // ========================================
    // VALIDATION
    // ========================================
    if (empty($name) || empty($jenis_kelamin) || empty($tanggal_lahir) || empty($cabang_id)) {
        die("ERROR: Required fields missing");
    }
    
    if (empty($username) || !preg_match('/^[a-zA-Z0-9_]{4,50}$/', $username)) {
        die("ERROR: Invalid username");
    }
    
    if (empty($password) || strlen($password) < 6) {
        die("ERROR: Invalid password");
    }
    
    if (empty($paket_data)) {
        die("ERROR: No package selected");
    }
    
    if (!in_array($jenis_kelamin, ['L', 'P'])) {
        die("ERROR: Invalid gender");
    }
    
    if (!in_array($status, ['aktif', 'nonaktif', 'cuti'])) {
        die("ERROR: Invalid status");
    }
    
    if (empty($nama_orangtua) || empty($no_telp) || !validatePhone($no_telp)) {
        die("ERROR: Invalid parent data");
    }
    
    // ========================================
    // START TRANSACTION
    // ========================================
    $conn->begin_transaction();
    
    try {
        // Check username
        $stmt_check = $conn->prepare("SELECT siswa_id FROM siswa WHERE username = ?");
        $stmt_check->bind_param("s", $username);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        
        if ($result_check->num_rows > 0) {
            $stmt_check->close();
            $conn->rollback();
            die("ERROR: Username already exists");
        }
        $stmt_check->close();
        
        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // ========================================
        // INSERT SISWA
        // ========================================
        $stmt_siswa = $conn->prepare("
            INSERT INTO siswa 
            (name, username, password, jenis_kelamin, tanggal_lahir, cabang_id, asal_sekolah, status, nama_orangtua, no_telp, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt_siswa->bind_param("sssssissss", $name, $username, $hashed_password, $jenis_kelamin, $tanggal_lahir, $cabang_id, $asal_sekolah, $status, $nama_orangtua, $no_telp);
        
        if (!$stmt_siswa->execute()) {
            throw new Exception('Failed to insert siswa: ' . $stmt_siswa->error);
        }
        
        $siswa_id = $conn->insert_id;
        $stmt_siswa->close();
        
        // ========================================
        // PROCESS EACH PACKAGE
        // ========================================
        foreach ($paket_data as $paket_info) {
            $datales_id = (int)$paket_info['datales_id'];
            $slot_id = (int)$paket_info['slot_id'];
            $tanggal_mulai = $paket_info['tanggal_mulai'];
            
            // Get package info
            $stmt_paket = $conn->prepare("
                SELECT 
                    tl.jumlahpertemuan, 
                    d.harga, 
                    d.jenistingkat_id
                FROM datales d
                JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
                JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                WHERE d.datales_id = ?
            ");
            $stmt_paket->bind_param("i", $datales_id);
            $stmt_paket->execute();
            $result_paket = $stmt_paket->get_result();
            $paket = $result_paket->fetch_assoc();
            $stmt_paket->close();
            
            if (!$paket) {
                throw new Exception('Package not found: datales_id=' . $datales_id);
            }
            
            $jumlahpertemuan = (int)$paket['jumlahpertemuan'];
            $harga = (float)$paket['harga'];
            
            // ========================================
            // INSERT SISWA_DATALES
            // ========================================
            $stmt_siswa_datales = $conn->prepare("
                INSERT INTO siswa_datales 
                (siswa_id, datales_id, slot_id, status, tanggal_mulai) 
                VALUES (?, ?, ?, 'aktif', ?)
            ");
            $stmt_siswa_datales->bind_param("iiis", $siswa_id, $datales_id, $slot_id, $tanggal_mulai);

            if (!$stmt_siswa_datales->execute()) {
                throw new Exception('Failed to insert siswa_datales: ' . $stmt_siswa_datales->error);
            }
            $stmt_siswa_datales->close();
            
            // ========================================
            // GENERATE JADWAL SEMESTER (6 BULAN)
            // ========================================
            $result_generate = generateJadwalSemester(
                $conn, 
                $siswa_id, 
                $datales_id, 
                $slot_id, 
                1, // semester_ke
                '2024/2025', // tahun_ajaran
                $tanggal_mulai
            );

            if (!$result_generate['success']) {
                throw new Exception('Generate jadwal failed: ' . $result_generate['message']);
            }

            // ========================================
            // UPDATE SISWA_DATALES DENGAN INFO SEMESTER
            // ========================================
            $total_pertemuan_semester = $jumlahpertemuan * 6; // 6 bulan
            
            $stmt_update = $conn->prepare("
                UPDATE siswa_datales 
                SET 
                    semester_ke = 1,
                    tahun_ajaran = '2024/2025',
                    bulan_aktif = 1,
                    tanggal_mulai_semester = ?,
                    tanggal_selesai_semester = DATE_ADD(?, INTERVAL 6 MONTH),
                    total_pertemuan_semester = ?,
                    is_history = FALSE
                WHERE siswa_id = ? AND datales_id = ?
            ");
            $stmt_update->bind_param("ssiii", $tanggal_mulai, $tanggal_mulai, $total_pertemuan_semester, $siswa_id, $datales_id);

            if (!$stmt_update->execute()) {
                throw new Exception('Failed to update siswa_datales: ' . $stmt_update->error);
            }
            $stmt_update->close();
        }
        
        // ========================================
        // COMMIT TRANSACTION
        // ========================================
        $conn->commit();
        
        header("Location: siswa.php?success=siswa_added");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("ERROR ADD SISWA: " . $e->getMessage());
        die("ERROR: " . $e->getMessage());
    }
}

// ========================================
// LOAD DATA FOR FORM
// ========================================

// Get cabang list
$cabang_list = [];
if ($current_user_role_id == 1) {
    $sql_cabang = "SELECT cabang_id, nama_cabang FROM cabang ORDER BY nama_cabang";
    $result_cabang = $conn->query($sql_cabang);
    while ($row = $result_cabang->fetch_assoc()) {
        $cabang_list[] = $row;
    }
} else if ($current_user_role_id == 2) {
    $sql_cabang = "
        SELECT c.cabang_id, c.nama_cabang 
        FROM cabang c
        INNER JOIN user_cabang uc ON c.cabang_id = uc.cabang_id
        WHERE uc.user_id = ?
        ORDER BY c.nama_cabang
    ";
    $stmt_cabang = $conn->prepare($sql_cabang);
    $stmt_cabang->bind_param("i", $current_user_id);
    $stmt_cabang->execute();
    $result_cabang = $stmt_cabang->get_result();
    while ($row = $result_cabang->fetch_assoc()) {
        $cabang_list[] = $row;
    }
    $stmt_cabang->close();
}

// Get paket list
$paket_list = [];
$sql_paket = "
    SELECT 
        d.datales_id,
        d.cabang_id,
        c.nama_cabang,
        d.jenistingkat_id,
        jt.nama_jenistingkat,
        tl.tipeles_id,
        tl.name as nama_tipeles,
        tl.jumlahpertemuan,
        d.harga,
        CONCAT(tl.name, ' - ', jt.nama_jenistingkat) as nama_paket
    FROM datales d
    JOIN cabang c ON d.cabang_id = c.cabang_id
    JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
    JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
    ORDER BY c.nama_cabang, tl.name, jt.nama_jenistingkat
";

$result_paket = $conn->query($sql_paket);
while ($row = $result_paket->fetch_assoc()) {
    $paket_list[] = $row;
}

// Get available slots
$slot_options = [];
$sql_slots = "
    SELECT 
        js.slot_id,
        js.cabangguruID,
        js.jenistingkat_id,
        js.hari,
        js.jam_mulai,
        js.jam_selesai,
        js.tipe_kelas,
        js.kapasitas_maksimal,
        js.status,
        g.nama_guru,
        c.nama_cabang,
        cg.cabang_id,
        COALESCE(COUNT(DISTINCT sd.siswa_id), 0) as jumlah_siswa
    FROM jadwal_slot js
    INNER JOIN cabangguru cg ON js.cabangguruID = cg.id
    INNER JOIN guru g ON cg.guru_id = g.guru_id
    INNER JOIN cabang c ON cg.cabang_id = c.cabang_id
    LEFT JOIN siswa_datales sd ON js.slot_id = sd.slot_id 
        AND sd.status = 'aktif' 
        AND sd.is_history = FALSE
    WHERE js.status = 'aktif'
    GROUP BY js.slot_id, js.cabangguruID, js.jenistingkat_id, js.hari, 
             js.jam_mulai, js.jam_selesai, js.tipe_kelas, js.kapasitas_maksimal, 
             js.status, g.nama_guru, c.nama_cabang, cg.cabang_id
    HAVING COALESCE(COUNT(DISTINCT sd.siswa_id), 0) < js.kapasitas_maksimal
    ORDER BY c.nama_cabang, js.hari, js.jam_mulai
";

$result_slots = $conn->query($sql_slots);
while ($row = $result_slots->fetch_assoc()) {
    $slot_options[] = $row;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Tambah Siswa - Jia Jia Education</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <style>
        .paket-group { display: none; }
        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .paket-item {
            border: 2px solid #e9ecef;
            border-radius: 6px;
            padding: 1rem;
            transition: all 0.2s;
        }
        .paket-item label {
            cursor: pointer;
            user-select: none;
        }
        .paket-item:hover {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        .paket-item.selected {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }
        .slot-detail-box {
            background-color: #f8f9fa;
            border-left: 3px solid #0d6efd;
            padding: 1.25rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }
        .slot-detail-box h6 {
            color: #0d6efd;
            margin-bottom: 1rem;
        }
        .paket-group-box {
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
        padding: 1.5rem;
        background-color: #f8f9fa;
    }

    .paket-group-title {
        font-weight: 600;
        color: #0d6efd;
        margin-bottom: 1rem;
        font-size: 1.1rem;
    }

    .paket-item {
        background-color: white;
        border: 1px solid #dee2e6;
        border-radius: 0.25rem;
        padding: 0.75rem;
        transition: all 0.2s;
        cursor: pointer;
    }

    .paket-item:hover {
        border-color: #0d6efd;
        background-color: #f8f9fa;
    }

    .paket-item.selected {
        border-color: #0d6efd;
        background-color: #e7f1ff;
    }

    .paket-details {
        font-size: 0.875rem;
        color: #6c757d;
        margin-top: 0.25rem;
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
                    $hari = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                    $bulan = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 
                            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    $date = new DateTime();
                    echo $hari[$date->format('w')] . ', ' . 
                        $date->format('d') . ' ' . 
                        $bulan[(int)$date->format('n')] . ' ' . 
                        $date->format('Y');
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
            <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
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
                    <h1 class="mt-4">Tambah Siswa Baru</h1>
                    <br>
                    
                    <form method="POST" id="formTambahSiswa">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        
                        <!-- Informasi Pribadi -->
                        <div class="section-title">
                            <i class="fas fa-user me-2"></i>Informasi Pribadi
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" required>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Jenis Kelamin <span class="text-danger">*</span></label>
                                <select class="form-select" name="jenis_kelamin" required>
                                    <option value="">Pilih...</option>
                                    <option value="L">Laki-laki</option>
                                    <option value="P">Perempuan</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Tanggal Lahir <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="tanggal_lahir" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Asal Sekolah</label>
                                <input type="text" class="form-control" name="asal_sekolah">
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Cabang <span class="text-danger">*</span></label>
                                <select class="form-select" id="cabang_select" name="cabang_id" required>
                                    <option value="">Pilih Cabang</option>
                                    <?php foreach ($cabang_list as $cabang): ?>
                                        <option value="<?php echo $cabang['cabang_id']; ?>">
                                            <?php echo htmlspecialchars($cabang['nama_cabang']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select class="form-select" name="status" required>
                                    <option value="aktif">Aktif</option>
                                    <option value="nonaktif">Non-Aktif</option>
                                    <option value="cuti">Cuti</option>
                                </select>
                            </div>
                        </div>

                        <!-- Pilih Paket Kelas -->
                        <div class="section-title">
                            <i class="fas fa-book me-2"></i>Pilih Paket Kelas <span class="text-danger">*</span>
                        </div>

                        <div class="alert alert-info mb-3" id="alert-pilih-cabang">
                            <i class="fas fa-info-circle me-2"></i>Silakan pilih cabang terlebih dahulu
                        </div>

                        <div id="paket-container" style="display: none;" class="mb-4">
                            <?php 
                            // Group paket by cabang dan jenis les
                            $grouped_paket = [];
                            foreach ($paket_list as $paket) {
                                $cabang_id = $paket['cabang_id'];
                                
                                // Ambil jenis les dari tipeles
                                $stmt_jenis = $conn->prepare("
                                    SELECT jl.name as nama_jenisles
                                    FROM tipeles tl
                                    JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
                                    WHERE tl.tipeles_id = ?
                                ");
                                $stmt_jenis->bind_param("i", $paket['tipeles_id']);
                                $stmt_jenis->execute();
                                $result_jenis = $stmt_jenis->get_result();
                                $jenis_data = $result_jenis->fetch_assoc();
                                $stmt_jenis->close();
                                
                                $jenis_les = $jenis_data['nama_jenisles'] ?? 'Lainnya';
                                
                                if (!isset($grouped_paket[$cabang_id])) {
                                    $grouped_paket[$cabang_id] = [];
                                }
                                if (!isset($grouped_paket[$cabang_id][$jenis_les])) {
                                    $grouped_paket[$cabang_id][$jenis_les] = [];
                                }
                                
                                $grouped_paket[$cabang_id][$jenis_les][] = $paket;
                            }
                            
                            foreach ($grouped_paket as $cabang_id => $jenis_groups): 
                            ?>
                                <div class="paket-group" data-cabang-id="<?php echo $cabang_id; ?>">
                                    <?php foreach ($jenis_groups as $jenis_les => $paket_list_jenis): ?>
                                    <!-- Group Header -->
                                    <div class="paket-group-box mb-4">
                                        <div class="paket-group-title">
                                            <i class="<?php echo (strtolower($jenis_les) == 'musik') ? 'music' : 'language'; ?>"></i>
                                            <?php echo htmlspecialchars($jenis_les); ?>
                                            <span class="badge bg-secondary ms-2"><?php echo count($paket_list_jenis); ?></span>
                                        </div>
                                        
                                        <div class="row">
                                            <?php foreach ($paket_list_jenis as $paket): ?>
                                            <div class="col-md-6 col-lg-4 mb-3">
                                                <div class="paket-item">
                                                    <div class="form-check">
                                                        <input 
                                                            class="form-check-input paket-checkbox" 
                                                            type="checkbox" 
                                                            value="<?php echo $paket['datales_id']; ?>"
                                                            id="paket_<?php echo $paket['datales_id']; ?>"
                                                            data-cabang="<?php echo $paket['cabang_id']; ?>"
                                                            data-jenistingkat="<?php echo $paket['jenistingkat_id']; ?>"
                                                            data-jumlah="<?php echo $paket['jumlahpertemuan']; ?>"
                                                            data-harga="<?php echo $paket['harga']; ?>"
                                                            data-nama="<?php echo htmlspecialchars($paket['nama_paket']); ?>"
                                                        >
                                                        <label class="form-check-label w-100" for="paket_<?php echo $paket['datales_id']; ?>">
                                                            <strong><?php echo htmlspecialchars($paket['nama_tipeles']); ?></strong>
                                                            <div class="paket-details">
                                                                <i class="fas fa-layer-group me-1"></i>
                                                                <?php echo htmlspecialchars($paket['nama_jenistingkat']); ?>
                                                            </div>
                                                            <div class="paket-details">
                                                                <i class="fas fa-calendar me-1"></i>
                                                                <?php echo $paket['jumlahpertemuan']; ?>x pertemuan/bulan
                                                            </div>
                                                            <div class="paket-details">
                                                                <i class="fas fa-tag me-1"></i>
                                                                Rp <?php echo number_format($paket['harga'], 0, ',', '.'); ?>
                                                            </div>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Detail Slot Jadwal (Muncul setelah pilih paket) -->
                        <div id="paket-details-container"></div>

                        <!-- Data Login -->
                        <div class="section-title">
                            <i class="fas fa-lock me-2"></i>Data Login
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" pattern="[a-zA-Z0-9_]{4,50}" autocomplete="off" required>
                                <small class="text-muted">4-50 karakter (huruf, angka, underscore)</small>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="password_siswa" name="password" minlength="6" autocomplete="new-password" required>
                                    <button class="btn btn-outline-secondary" type="button" id="togglePassword">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Minimal 6 karakter</small>
                            </div>
                        </div>

                        <!-- Data Orang Tua -->
                        <div class="section-title">
                            <i class="fas fa-users me-2"></i>Data Orang Tua / Wali
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Orang Tua <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama_orangtua" required>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">No. Telepon <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" name="no_telp" pattern="[0-9]{8,15}" required>
                                <small class="text-muted">8-15 digit angka</small>
                            </div>
                        </div>
                        
                        <!-- Button Submit -->
                        <div class="d-flex justify-content-between mb-4">
                            <a href="siswa.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Kembali
                            </a>
                            <button type="submit" name="add_siswa" class="btn btn-primary px-4">
                                <i class="fas fa-save me-1"></i>Simpan Data Siswa
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
    // Toggle password visibility
    document.getElementById('togglePassword').addEventListener('click', function() {
        const passwordField = document.getElementById('password_siswa');
        const icon = this.querySelector('i');
        
        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            passwordField.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });

    const slots = <?php echo json_encode($slot_options); ?>;
    let selectedPackages = {};
    
    const cabangSelect = document.getElementById('cabang_select');
    const alertPilihCabang = document.getElementById('alert-pilih-cabang');
    const paketContainer = document.getElementById('paket-container');
    const paketDetailsContainer = document.getElementById('paket-details-container');
    
    // Cabang change handler
    cabangSelect.addEventListener('change', function() {
        const selectedCabangId = this.value;
        
        if (!selectedCabangId) {
            alertPilihCabang.style.display = 'block';
            paketContainer.style.display = 'none';
            paketDetailsContainer.innerHTML = '';
            selectedPackages = {};
            
            document.querySelectorAll('.paket-checkbox').forEach(checkbox => {
                checkbox.checked = false;
                checkbox.closest('.paket-item').classList.remove('selected');
            });
            return;
        }
        
        alertPilihCabang.style.display = 'none';
        paketContainer.style.display = 'block';
        
        document.querySelectorAll('.paket-group').forEach(group => {
            if (group.getAttribute('data-cabang-id') === selectedCabangId) {
                group.style.display = 'block';
            } else {
                group.style.display = 'none';
            }
        });
        
        paketDetailsContainer.innerHTML = '';
        selectedPackages = {};
        
        document.querySelectorAll('.paket-checkbox').forEach(checkbox => {
            checkbox.checked = false;
            checkbox.closest('.paket-item').classList.remove('selected');
        });
    });
    
    // Paket checkbox handler - UPDATE EXISTING
    document.querySelectorAll('.paket-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const paketId = this.value;
            const paketItem = this.closest('.paket-item');
            
            if (this.checked) {
                paketItem.classList.add('selected');
                selectedPackages[paketId] = {
                    id: paketId,
                    cabang_id: this.getAttribute('data-cabang'),
                    jenistingkat_id: this.getAttribute('data-jenistingkat'),
                    jumlahpertemuan: this.getAttribute('data-jumlah'),
                    harga: this.getAttribute('data-harga'),
                    nama: this.getAttribute('data-nama')
                };
                renderPaketDetail(paketId);
            } else {
                paketItem.classList.remove('selected');
                delete selectedPackages[paketId];
                removePaketDetail(paketId);
            }
        });
    });

    function renderPaketDetail(paketId) {
        const paket = selectedPackages[paketId];
        
        const availableSlots = slots.filter(slot => 
            slot.cabang_id == paket.cabang_id && 
            slot.jenistingkat_id == paket.jenistingkat_id &&
            parseInt(slot.jumlah_siswa) < parseInt(slot.kapasitas_maksimal)
        );
        
        let slotOptions = '<option value="">Pilih Slot Waktu</option>';
        
        if (availableSlots.length > 0) {
            availableSlots.forEach(slot => {
                slotOptions += `
                    <option value="${slot.slot_id}" data-hari="${slot.hari}">
                        ${slot.nama_guru} - ${slot.hari} ${slot.jam_mulai.substring(0,5)}-${slot.jam_selesai.substring(0,5)} 
                        (${slot.jumlah_siswa}/${slot.kapasitas_maksimal} siswa)
                    </option>
                `;
            });
        } else {
            slotOptions += '<option value="">Tidak ada slot tersedia</option>';
        }
        
        const detailHtml = `
            <div class="slot-detail-box" id="detail-${paketId}">
                <h6>${paket.nama}</h6>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Slot Waktu <span class="text-danger">*</span></label>
                        <select class="form-select slot-select" name="slot_${paketId}" data-paket-id="${paketId}" required>
                            ${slotOptions}
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Tanggal Mulai <span class="text-danger">*</span></label>
                        <input type="date" class="form-control tanggal-mulai" name="tanggal_${paketId}" data-paket-id="${paketId}" min="<?php echo date('Y-m-d'); ?>" required>
                        <small class="text-muted slot-info" id="info-${paketId}">Pilih slot waktu terlebih dahulu</small>
                    </div>
                </div>
                <input type="hidden" name="paket_data[]" value='{"datales_id":"${paketId}","slot_id":"","tanggal_mulai":""}' class="paket-hidden-${paketId}">
            </div>
        `;
        
        paketDetailsContainer.insertAdjacentHTML('beforeend', detailHtml);
        
        const slotSelect = document.querySelector(`.slot-select[data-paket-id="${paketId}"]`);
        const tanggalMulai = document.querySelector(`.tanggal-mulai[data-paket-id="${paketId}"]`);
        const hiddenInput = document.querySelector(`.paket-hidden-${paketId}`);
        
        function updateHiddenInput() {
            const data = {
                datales_id: paketId,
                slot_id: slotSelect.value,
                tanggal_mulai: tanggalMulai.value
            };
            hiddenInput.value = JSON.stringify(data);
        }
        
        slotSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const hari = selectedOption.getAttribute('data-hari');
            
            if (this.value) {
                document.getElementById(`info-${paketId}`).innerHTML = `Kelas dimulai setiap hari <strong>${hari}</strong>`;
            } else {
                document.getElementById(`info-${paketId}`).innerHTML = 'Pilih slot waktu terlebih dahulu';
            }
            updateHiddenInput();
        });
        
        tanggalMulai.addEventListener('change', updateHiddenInput);
    }

    function removePaketDetail(paketId) {
        const detailBox = document.getElementById(`detail-${paketId}`);
        if (detailBox) {
            detailBox.remove();
        }
    }
    </script>
</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>