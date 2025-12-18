<?php
require 'check_login.php';
requireHeadAdmin(); // Hanya Head Admin yang bisa akses halaman ini

require 'config.php';
date_default_timezone_set('Asia/Jakarta');

// Generate CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ambil data user dari session menggunakan helper functions
$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = getUserRoleId();
$current_user_cabang_id = getUserCabangId();
$current_user_cabang_name = $_SESSION['cabang_name'] ?? 'Semua Cabang';
$current_user_role = strtolower(str_replace(' ', '', getRoleName()));

$current_page = basename($_SERVER['PHP_SELF']);

// Ambil data cabang untuk filter
$cabang_options = [];
try {
    $stmt = $conn->prepare("SELECT cabang_id, nama_cabang FROM cabang ORDER BY nama_cabang");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $cabang_options[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
}

// Filter cabang
$filter_cabang_id = null;
if (isset($_GET['cabang_id']) && !empty($_GET['cabang_id'])) {
    $filter_cabang_id = (int)$_GET['cabang_id'];
} elseif ($current_user_role !== 'headadmin' && $current_user_cabang_id) {
    $filter_cabang_id = $current_user_cabang_id;
}

// Active tab
$active_tab = $_GET['tab'] ?? 'jenisles';

$message = '';
$message_type = '';

// JENIS LES OPERATIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_jenisles'])) {
    $action = $_POST['action_jenisles'];
    
    if ($action === 'add') {
        $name = trim($_POST['name']);
        try {
            $stmt = $conn->prepare("INSERT INTO jenisles (name) VALUES (?)");
            $stmt->bind_param("s", $name);
            if ($stmt->execute()) {
                $message = "Jenis Les berhasil ditambahkan!";
                $message_type = "success";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    } elseif ($action === 'edit') {
        $jenisles_id = (int)$_POST['jenisles_id'];
        $name = trim($_POST['name']);
        try {
            $stmt = $conn->prepare("UPDATE jenisles SET name = ? WHERE jenisles_id = ?");
            $stmt->bind_param("si", $name, $jenisles_id);
            if ($stmt->execute()) {
                $message = "Jenis Les berhasil diupdate!";
                $message_type = "success";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    } elseif ($action === 'delete') {
        $jenisles_id = (int)$_POST['jenisles_id'];
        try {
            $stmt = $conn->prepare("DELETE FROM jenisles WHERE jenisles_id = ?");
            $stmt->bind_param("i", $jenisles_id);
            if ($stmt->execute()) {
                $message = "Jenis Les berhasil dihapus!";
                $message_type = "success";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// TIPE LES OPERATIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_tipeles'])) {
    $action = $_POST['action_tipeles'];
    
    if ($action === 'add') {
        $jenisles_id = (int)$_POST['jenisles_id'];
        $name = trim($_POST['name']);
        $jumlahpertemuan = (int)$_POST['jumlahpertemuan'];
        try {
            $stmt = $conn->prepare("INSERT INTO tipeles (jenisles_id, name, jumlahpertemuan) VALUES (?, ?, ?)");
            $stmt->bind_param("isi", $jenisles_id, $name, $jumlahpertemuan);
            if ($stmt->execute()) {
                $message = "Tipe Les berhasil ditambahkan!";
                $message_type = "success";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    } elseif ($action === 'edit') {
        $tipeles_id = (int)$_POST['tipeles_id'];
        $jenisles_id = (int)$_POST['jenisles_id'];
        $name = trim($_POST['name']);
        $jumlahpertemuan = (int)$_POST['jumlahpertemuan'];
        try {
            $stmt = $conn->prepare("UPDATE tipeles SET jenisles_id = ?, name = ?, jumlahpertemuan = ? WHERE tipeles_id = ?");
            $stmt->bind_param("isii", $jenisles_id, $name, $jumlahpertemuan, $tipeles_id);
            if ($stmt->execute()) {
                $message = "Tipe Les berhasil diupdate!";
                $message_type = "success";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    } elseif ($action === 'delete') {
        $tipeles_id = (int)$_POST['tipeles_id'];
        try {
            $stmt = $conn->prepare("DELETE FROM tipeles WHERE tipeles_id = ?");
            $stmt->bind_param("i", $tipeles_id);
            if ($stmt->execute()) {
                $message = "Tipe Les berhasil dihapus!";
                $message_type = "success";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// TINGKATAN OPERATIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_tingkatan'])) {
    $action = $_POST['action_tingkatan'];
    
    if ($action === 'add') {
        $tipeles_id = (int)$_POST['tipeles_id'];
        $nama_jenistingkat = trim($_POST['nama_jenistingkat']);
        try {
            $stmt = $conn->prepare("INSERT INTO jenistingkat (tipeles_id, nama_jenistingkat) VALUES (?, ?)");
            $stmt->bind_param("is", $tipeles_id, $nama_jenistingkat);
            if ($stmt->execute()) {
                $message = "Tingkatan berhasil ditambahkan!";
                $message_type = "success";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    } elseif ($action === 'edit') {
        $jenistingkat_id = (int)$_POST['jenistingkat_id'];
        $tipeles_id = (int)$_POST['tipeles_id'];
        $nama_jenistingkat = trim($_POST['nama_jenistingkat']);
        try {
            $stmt = $conn->prepare("UPDATE jenistingkat SET tipeles_id = ?, nama_jenistingkat = ? WHERE jenistingkat_id = ?");
            $stmt->bind_param("isi", $tipeles_id, $nama_jenistingkat, $jenistingkat_id);
            if ($stmt->execute()) {
                $message = "Tingkatan berhasil diupdate!";
                $message_type = "success";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    } elseif ($action === 'delete') {
        $jenistingkat_id = (int)$_POST['jenistingkat_id'];
        try {
            $stmt = $conn->prepare("DELETE FROM jenistingkat WHERE jenistingkat_id = ?");
            $stmt->bind_param("i", $jenistingkat_id);
            if ($stmt->execute()) {
                $message = "Tingkatan berhasil dihapus!";
                $message_type = "success";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// HARGA OPERATIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_harga'])) {
    $action = $_POST['action_harga'];
    
    if ($action === 'add') {
        $jenistingkat_id = (int)$_POST['jenistingkat_id'];
        $cabang_id = (int)$_POST['cabang_id'];
        $harga = (int)$_POST['harga'];
        try {
            // Check if already exists
            $check = $conn->prepare("SELECT datales_id FROM datales WHERE jenistingkat_id = ? AND cabang_id = ?");
            $check->bind_param("ii", $jenistingkat_id, $cabang_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $message = "Harga untuk tingkatan dan cabang ini sudah ada!";
                $message_type = "warning";
            } else {
                $stmt = $conn->prepare("INSERT INTO datales (jenistingkat_id, cabang_id, harga) VALUES (?, ?, ?)");
                $stmt->bind_param("iii", $jenistingkat_id, $cabang_id, $harga);
                if ($stmt->execute()) {
                    $message = "Harga berhasil ditambahkan!";
                    $message_type = "success";
                }
                $stmt->close();
            }
            $check->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    } elseif ($action === 'edit') {
        $datales_id = (int)$_POST['datales_id'];
        $cabang_id = (int)$_POST['cabang_id'];
        $harga = (int)$_POST['harga'];
        try {
        $stmt = $conn->prepare("UPDATE datales SET cabang_id = ?, harga = ? WHERE datales_id = ?");
        $stmt->bind_param("idi", $cabang_id, $harga, $datales_id);
            if ($stmt->execute()) {
                $message = "Harga berhasil diupdate!";
                $message_type = "success";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    } elseif ($action === 'delete') {
        $datales_id = (int)$_POST['datales_id'];
        try {
            $stmt = $conn->prepare("DELETE FROM datales WHERE datales_id = ?");
            $stmt->bind_param("i", $datales_id);
            if ($stmt->execute()) {
                $message = "Harga berhasil dihapus!";
                $message_type = "success";
            }
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $message_type = "danger";
        }
    }
}

// Fetch Jenis Les
$jenisles_list = [];
try {
    $result = $conn->query("SELECT * FROM jenisles ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $jenisles_list[] = $row;
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
}

// Fetch Tipe Les with Jenis Les name
$tipeles_list = [];
try {
    $result = $conn->query("SELECT t.*, j.name as jenisles_name FROM tipeles t LEFT JOIN jenisles j ON t.jenisles_id = j.jenisles_id ORDER BY j.name, t.name");
    while ($row = $result->fetch_assoc()) {
        $tipeles_list[] = $row;
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
}

// Fetch Tingkatan
$tingkatan_list = [];
try {
    $result = $conn->query("SELECT jt.*, t.name as tipeles_name, j.name as jenisles_name FROM jenistingkat jt LEFT JOIN tipeles t ON jt.tipeles_id = t.tipeles_id LEFT JOIN jenisles j ON t.jenisles_id = j.jenisles_id ORDER BY j.name, t.name, jt.nama_jenistingkat");
    while ($row = $result->fetch_assoc()) {
        $tingkatan_list[] = $row;
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
}

// Fetch Harga
$harga_list = [];
try {
    $result = $conn->query("SELECT d.*, jt.nama_jenistingkat, t.name as tipeles_name, j.name as jenisles_name, c.nama_cabang FROM datales d LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id LEFT JOIN tipeles t ON jt.tipeles_id = t.tipeles_id LEFT JOIN jenisles j ON t.jenisles_id = j.jenisles_id LEFT JOIN cabang c ON d.cabang_id = c.cabang_id ORDER BY j.name, t.name, jt.nama_jenistingkat, c.nama_cabang");
    while ($row = $result->fetch_assoc()) {
        $harga_list[] = $row;
    }
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Paket Kelas - Jia Jia Education</title>
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

                        <div class="sb-sidenav-menu-heading">Pembayaran</div>
                        <a class="nav-link" href="verifikasi_pembayaran.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-check-circle"></i></div>
                            Verifikasi Pembayaran
                        </a>
                        <a class="nav-link" href="kelola_semester.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-sync-alt"></i></div>
                            Kelola Semester
                            </a>

                        <div class="sb-sidenav-menu-heading">Manage</div>
                        <a class="nav-link" href="siswa.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-user"></i></div>
                            Siswa
                        </a>
                        <a class="nav-link" href="guru.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                            Guru
                        </a>

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
                        
                        <a class="nav-link active" href="paketkelas.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-graduation-cap"></i></div>
                            Paket Kelas
                        </a>
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
                    <h1 class="mt-4">Paket Kelas</h1>
                    <br>

                    <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Tab Navigation -->
                    <ul class="nav nav-tabs mb-4" role="tablist">
                        <?php if ($current_user_role === 'headadmin'): ?>
                        <li class="nav-item">
                            <a class="nav-link text-dark <?php echo $active_tab === 'jenisles' ? 'active fw-bold' : ''; ?>" href="?tab=jenisles">                                <i class="#"></i>Jenis Les
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-dark <?php echo $active_tab === 'tipeles' ? 'active fw-bold' : ''; ?>" href="?tab=tipeles">
                                <i class="#"></i>Tipe Les
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-dark <?php echo $active_tab === 'tingkatan' ? 'active fw-bold' : ''; ?>" href="?tab=tingkatan">
                                <i class="#"></i>Tingkatan
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-dark <?php echo $active_tab === 'harga' ? 'active fw-bold' : ''; ?>" href="?tab=harga">                                <i class="#"></i>Data Paket Les
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <!-- Tab Content -->
                    <?php if ($active_tab === 'jenisles' && $current_user_role === 'headadmin'): ?>
                    <!-- JENIS LES TAB -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class=""></i>Kelola Jenis Les</span>
                            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#addJenisLesModal">
                                <i class="fas fa-plus me-1"></i>Tambah Jenis Les
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="40">No</th>
                                            <th>Nama Jenis Les</th>
                                            <th width="180">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($jenisles_list)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted">Belum ada data</td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($jenisles_list as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="editJenisLes(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteJenisLes(<?php echo $item['jenisles_id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <?php elseif ($active_tab === 'tipeles' && $current_user_role === 'headadmin'): ?>
                    <!-- TIPE LES TAB -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class=""></i>Kelola Tipe Les</span>
                            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#addTipeLesModal">
                                <i class="fas fa-plus me-1"></i>Tambah Tipe Les
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="40">No</th>
                                            <th>Jenis Les</th>
                                            <th>Nama Tipe Les</th>
                                            <th>Jumlah Pertemuan</th>
                                            <th width="180">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($tipeles_list)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">Belum ada data</td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($tipeles_list as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($item['jenisles_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td><?php echo $item['jumlahpertemuan']; ?> pertemuan</td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="editTipeLes(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteTipeLes(<?php echo $item['tipeles_id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <?php elseif ($active_tab === 'tingkatan' && $current_user_role === 'headadmin'): ?>
                    <!-- TINGKATAN TAB -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class=""></i>Kelola Tingkatan</span>
                            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#addTingkatanModal">
                                <i class="fas fa-plus me-1"></i>Tambah Tingkatan
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="40">No</th>
                                            <th>Jenis Les</th>
                                            <th>Tipe Les</th>
                                            <th>Nama Tingkatan</th>
                                            <th width="180">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($tingkatan_list)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted">Belum ada data</td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($tingkatan_list as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($item['jenisles_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($item['tipeles_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($item['nama_jenistingkat']); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="editTingkatan(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteTingkatan(<?php echo $item['jenistingkat_id']; ?>, '<?php echo htmlspecialchars($item['nama_jenistingkat']); ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <?php elseif ($active_tab === 'harga' && $current_user_role === 'headadmin'): ?>
                    <!-- HARGA TAB -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class=""></i>Kelola Data Paket Les</span>
                            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#addHargaModal">
                                <i class="fas fa-plus me-1"></i>Tambah Harga
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($harga_list)): ?>
                            <!-- Filter Section -->
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <label class="form-label"><i class="fas fa-filter me-1"></i>Filter Jenis Les</label>
                                    <select class="form-select" id="filterJenisLes">
                                        <option value="">Semua Jenis Les</option>
                                        <?php
                                        // Get unique Jenis Les dari harga_list
                                        $unique_jenisles = array_unique(array_column($harga_list, 'jenisles_name'));
                                        sort($unique_jenisles);
                                        foreach ($unique_jenisles as $jenisles_name):
                                            if (!empty($jenisles_name)):
                                        ?>
                                        <option value="<?php echo htmlspecialchars($jenisles_name); ?>">
                                            <?php echo htmlspecialchars($jenisles_name); ?>
                                        </option>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label"><i class="fas fa-building me-1"></i>Filter Cabang</label>
                                    <select class="form-select" id="filterCabangHarga">
                                        <option value="">Semua Cabang</option>
                                        <?php
                                        // Get unique Cabang dari harga_list
                                        $unique_cabang = array_unique(array_column($harga_list, 'nama_cabang'));
                                        sort($unique_cabang);
                                        foreach ($unique_cabang as $cabang_name):
                                            if (!empty($cabang_name)):
                                        ?>
                                        <option value="<?php echo htmlspecialchars($cabang_name); ?>">
                                            <?php echo htmlspecialchars($cabang_name); ?>
                                        </option>
                                        <?php 
                                            endif;
                                        endforeach; 
                                        ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="form-label"><i class="fas fa-search me-1"></i>Search</label>
                                    <input type="text" id="searchHarga" class="form-control" placeholder="Cari tipe/tingkatan...">
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="form-label">&nbsp;</label>
                                    <button class="btn btn-secondary w-100" onclick="resetHargaFilter()">
                                        <i class="fas fa-redo me-1"></i>Reset
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="table-responsive">
                                <table class="table table-bordered" id="hargaTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="40">No</th>
                                            <th>Jenis Les</th>
                                            <th>Tipe Les</th>
                                            <th>Tingkatan</th>
                                            <th>Cabang</th>
                                            <th>Harga</th>
                                            <th width="180">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($harga_list)): ?>
                                        <tr id="emptyRow">
                                            <td colspan="7" class="text-center text-muted">Belum ada data</td>
                                        </tr>
                                        <?php else: ?>
                                        <?php foreach ($harga_list as $index => $item): ?>
                                        <tr data-jenisles="<?php echo htmlspecialchars($item['jenisles_name'] ?? ''); ?>" 
                                            data-cabang="<?php echo htmlspecialchars($item['nama_cabang'] ?? ''); ?>">
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($item['jenisles_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($item['tipeles_name'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($item['nama_jenistingkat'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($item['nama_cabang'] ?? '-'); ?></td>
                                            <td class="fw-bold">Rp <?php echo number_format($item['harga'], 0, ',', '.'); ?></td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="editHarga(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteHarga(<?php echo $item['datales_id']; ?>, '<?php echo htmlspecialchars($item['nama_jenistingkat']); ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </main>

            <footer class="py-4 bg-light mt-auto">
                    <div class="container-fluid px-4">
                        <div class="d-flex align-items-center justify-content-between small">
                            <div class="text-muted">Copyright &copy; Jia Jia Education 2024</div>
                        </div>
                    </div>
                </footer>
        </div>
    </div>

    <!-- MODALS FOR JENIS LES -->
    <div class="modal fade" id="addJenisLesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Jenis Les</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action_jenisles" value="add">
                        <div class="mb-3">
                            <label class="form-label">Nama Jenis Les</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editJenisLesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Jenis Les</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action_jenisles" value="edit">
                        <input type="hidden" name="jenisles_id" id="edit_jenisles_id">
                        <div class="mb-3">
                            <label class="form-label">Nama Jenis Les</label>
                            <input type="text" name="name" id="edit_jenisles_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form method="POST" id="deleteJenisLesForm" style="display:none;">
        <input type="hidden" name="action_jenisles" value="delete">
        <input type="hidden" name="jenisles_id" id="delete_jenisles_id">
    </form>

    <!-- MODALS FOR TIPE LES -->
    <div class="modal fade" id="addTipeLesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Tipe Les</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action_tipeles" value="add">
                        <div class="mb-3">
                            <label class="form-label">Jenis Les</label>
                            <select name="jenisles_id" class="form-select" required>
                                <option value="">Pilih Jenis Les</option>
                                <?php foreach ($jenisles_list as $jenis): ?>
                                <option value="<?php echo $jenis['jenisles_id']; ?>"><?php echo htmlspecialchars($jenis['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Tipe Les</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Jumlah Pertemuan</label>
                            <input type="number" name="jumlahpertemuan" class="form-control" required min="1">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editTipeLesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Tipe Les</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action_tipeles" value="edit">
                        <input type="hidden" name="tipeles_id" id="edit_tipeles_id">
                        <div class="mb-3">
                            <label class="form-label">Jenis Les</label>
                            <select name="jenisles_id" id="edit_tipeles_jenisles_id" class="form-select" required>
                                <option value="">Pilih Jenis Les</option>
                                <?php foreach ($jenisles_list as $jenis): ?>
                                <option value="<?php echo $jenis['jenisles_id']; ?>"><?php echo htmlspecialchars($jenis['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Tipe Les</label>
                            <input type="text" name="name" id="edit_tipeles_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Jumlah Pertemuan</label>
                            <input type="number" name="jumlahpertemuan" id="edit_tipeles_jumlahpertemuan" class="form-control" required min="1">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form method="POST" id="deleteTipeLesForm" style="display:none;">
        <input type="hidden" name="action_tipeles" value="delete">
        <input type="hidden" name="tipeles_id" id="delete_tipeles_id">
    </form>

    <!-- MODALS FOR TINGKATAN -->
    <div class="modal fade" id="addTingkatanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Tingkatan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action_tingkatan" value="add">
                        <div class="mb-3">
                            <label class="form-label">Tipe Les</label>
                            <select name="tipeles_id" class="form-select" required>
                                <option value="">Pilih Tipe Les</option>
                                <?php foreach ($tipeles_list as $tipe): ?>
                                <option value="<?php echo $tipe['tipeles_id']; ?>">
                                    <?php echo htmlspecialchars($tipe['jenisles_name'] . ' - ' . $tipe['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Tingkatan</label>
                            <input type="text" name="nama_jenistingkat" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editTingkatanModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Tingkatan</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action_tingkatan" value="edit">
                        <input type="hidden" name="jenistingkat_id" id="edit_tingkatan_id">
                        <div class="mb-3">
                            <label class="form-label">Tipe Les</label>
                            <select name="tipeles_id" id="edit_tingkatan_tipeles_id" class="form-select" required>
                                <option value="">Pilih Tipe Les</option>
                                <?php foreach ($tipeles_list as $tipe): ?>
                                <option value="<?php echo $tipe['tipeles_id']; ?>">
                                    <?php echo htmlspecialchars($tipe['jenisles_name'] . ' - ' . $tipe['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nama Tingkatan</label>
                            <input type="text" name="nama_jenistingkat" id="edit_tingkatan_name" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form method="POST" id="deleteTingkatanForm" style="display:none;">
        <input type="hidden" name="action_tingkatan" value="delete">
        <input type="hidden" name="jenistingkat_id" id="delete_tingkatan_id">
    </form>

    <!-- MODALS FOR HARGA -->
    <div class="modal fade" id="addHargaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Harga</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action_harga" value="add">
                        <div class="mb-3">
                            <label class="form-label">Tingkatan</label>
                            <select name="jenistingkat_id" class="form-select" required>
                                <option value="">Pilih Tingkatan</option>
                                <?php foreach ($tingkatan_list as $tingkat): ?>
                                <option value="<?php echo $tingkat['jenistingkat_id']; ?>">
                                    <?php echo htmlspecialchars($tingkat['jenisles_name'] . ' - ' . $tingkat['tipeles_name'] . ' - ' . $tingkat['nama_jenistingkat']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cabang</label>
                            <select name="cabang_id" class="form-select" required>
                                <option value="">Pilih Cabang</option>
                                <?php foreach ($cabang_options as $cabang): ?>
                                <option value="<?php echo $cabang['cabang_id']; ?>"><?php echo htmlspecialchars($cabang['nama_cabang']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Harga (Rp)</label>
                            <input type="number" name="harga" class="form-control" required min="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editHargaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Harga</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action_harga" value="edit">
                        <input type="hidden" name="datales_id" id="edit_harga_id">
                        <div class="mb-3">
                            <label class="form-label">Info Paket</label>
                            <input type="text" id="edit_harga_info" class="form-control" readonly>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Cabang</label>
                            <select name="cabang_id" id="edit_harga_cabang" class="form-select" required>
                                <option value="">Pilih Cabang</option>
                                <?php foreach ($cabang_options as $cabang): ?>
                                <option value="<?php echo $cabang['cabang_id']; ?>"><?php echo htmlspecialchars($cabang['nama_cabang']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Harga (Rp)</label>
                            <input type="number" name="harga" id="edit_harga_value" class="form-control" required min="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <form method="POST" id="deleteHargaForm" style="display:none;">
        <input type="hidden" name="action_harga" value="delete">
        <input type="hidden" name="datales_id" id="delete_harga_id">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/scripts.js"></script>
    
    <script>
    // Jenis Les Functions
    function editJenisLes(data) {
        document.getElementById('edit_jenisles_id').value = data.jenisles_id;
        document.getElementById('edit_jenisles_name').value = data.name;
        new bootstrap.Modal(document.getElementById('editJenisLesModal')).show();
    }

    function deleteJenisLes(id, name) {
        if (confirm('Yakin ingin menghapus jenis les "' + name + '"?\nSemua tipe les terkait juga akan terhapus!')) {
            document.getElementById('delete_jenisles_id').value = id;
            document.getElementById('deleteJenisLesForm').submit();
        }
    }

    // Tipe Les Functions
    function editTipeLes(data) {
        document.getElementById('edit_tipeles_id').value = data.tipeles_id;
        document.getElementById('edit_tipeles_jenisles_id').value = data.jenisles_id;
        document.getElementById('edit_tipeles_name').value = data.name;
        document.getElementById('edit_tipeles_jumlahpertemuan').value = data.jumlahpertemuan;
        new bootstrap.Modal(document.getElementById('editTipeLesModal')).show();
    }

    function deleteTipeLes(id, name) {
        if (confirm('Yakin ingin menghapus tipe les "' + name + '"?\nSemua tingkatan terkait juga akan terhapus!')) {
            document.getElementById('delete_tipeles_id').value = id;
            document.getElementById('deleteTipeLesForm').submit();
        }
    }

    // Tingkatan Functions
    function editTingkatan(data) {
        document.getElementById('edit_tingkatan_id').value = data.jenistingkat_id;
        document.getElementById('edit_tingkatan_tipeles_id').value = data.tipeles_id;
        document.getElementById('edit_tingkatan_name').value = data.nama_jenistingkat;
        new bootstrap.Modal(document.getElementById('editTingkatanModal')).show();
    }

    function deleteTingkatan(id, name) {
        if (confirm('Yakin ingin menghapus tingkatan "' + name + '"?\nSemua harga terkait juga akan terhapus!')) {
            document.getElementById('delete_tingkatan_id').value = id;
            document.getElementById('deleteTingkatanForm').submit();
        }
    }

    // Harga Functions
    function editHarga(data) {
        document.getElementById('edit_harga_id').value = data.datales_id;
        document.getElementById('edit_harga_info').value = data.jenisles_name + ' - ' + data.tipeles_name + ' - ' + data.nama_jenistingkat + ' (' + data.nama_cabang + ')';
        document.getElementById('edit_harga_cabang').value = data.cabang_id;
        document.getElementById('edit_harga_value').value = data.harga;
        new bootstrap.Modal(document.getElementById('editHargaModal')).show();
    }

    function deleteHarga(id, name) {
        if (confirm('Yakin ingin menghapus harga untuk "' + name + '"?')) {
            document.getElementById('delete_harga_id').value = id;
            document.getElementById('deleteHargaForm').submit();
        }
    }

    // Generic filter function - PINDAHKAN KE LUAR DOMContentLoaded
    function filterTable(tableId, searchTerm) {
        const table = document.getElementById(tableId);
        if (!table) return;
        
        searchTerm = searchTerm.toLowerCase();
        const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
        
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            const cells = row.getElementsByTagName('td');
            let found = false;
            
            // Skip jika row kosong (pesan "Belum ada data")
            if (cells.length === 1 && cells[0].colSpan > 1) {
                continue;
            }
            
            for (let j = 0; j < cells.length; j++) {
                const cellText = cells[j].textContent.toLowerCase();
                if (cellText.indexOf(searchTerm) > -1) {
                    found = true;
                    break;
                }
            }
            
            row.style.display = found ? '' : 'none';
        }
    }

    // Filter untuk tab Harga (kombinasi Jenis Les + Cabang + Search)
    document.addEventListener('DOMContentLoaded', function() {
        const filterJenisLes = document.getElementById('filterJenisLes');
        const filterCabangHarga = document.getElementById('filterCabangHarga');
        const searchHarga = document.getElementById('searchHarga');
        const hargaTable = document.getElementById('hargaTable');
        
        function applyHargaFilter() {
            if (!hargaTable) return;
            
            const selectedJenisLes = filterJenisLes ? filterJenisLes.value.toLowerCase() : '';
            const selectedCabang = filterCabangHarga ? filterCabangHarga.value.toLowerCase() : '';
            const searchTerm = searchHarga ? searchHarga.value.toLowerCase() : '';
            
            const rows = hargaTable.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            let visibleCount = 0;
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                
                // Skip empty row
                if (row.id === 'emptyRow') {
                    continue;
                }
                
                const jenisles = (row.getAttribute('data-jenisles') || '').toLowerCase();
                const cabang = (row.getAttribute('data-cabang') || '').toLowerCase();
                const cells = row.getElementsByTagName('td');
                
                // Check filter Jenis Les
                let matchJenisLes = !selectedJenisLes || jenisles.includes(selectedJenisLes);
                
                // Check filter Cabang
                let matchCabang = !selectedCabang || cabang.includes(selectedCabang);
                
                // Check search term (search di semua kolom)
                let matchSearch = !searchTerm;
                if (searchTerm) {
                    for (let j = 0; j < cells.length; j++) {
                        const cellText = cells[j].textContent.toLowerCase();
                        if (cellText.includes(searchTerm)) {
                            matchSearch = true;
                            break;
                        }
                    }
                }
                
                // Show/hide row based on all filters
                if (matchJenisLes && matchCabang && matchSearch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }
            
            // Update result counter
            const resultCount = document.getElementById('resultCount');
            if (resultCount) {
                resultCount.textContent = visibleCount;
            }
        }
        
        // Attach event listeners
        if (filterJenisLes) {
            filterJenisLes.addEventListener('change', applyHargaFilter);
        }
        if (filterCabangHarga) {
            filterCabangHarga.addEventListener('change', applyHargaFilter);
        }
        if (searchHarga) {
            searchHarga.addEventListener('keyup', applyHargaFilter);
        }
    });

    // Reset filter function
    function resetHargaFilter() {
        const filterJenisLes = document.getElementById('filterJenisLes');
        const filterCabangHarga = document.getElementById('filterCabangHarga');
        const searchHarga = document.getElementById('searchHarga');
        
        if (filterJenisLes) filterJenisLes.value = '';
        if (filterCabangHarga) filterCabangHarga.value = '';
        if (searchHarga) searchHarga.value = '';
        
        // Show all rows
        const hargaTable = document.getElementById('hargaTable');
        if (hargaTable) {
            const rows = hargaTable.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            for (let i = 0; i < rows.length; i++) {
                rows[i].style.display = '';
            }
        }
        
        // Reset counter
        const resultCount = document.getElementById('resultCount');
        if (resultCount) {
            const totalRows = hargaTable.getElementsByTagName('tbody')[0].getElementsByTagName('tr').length;
            resultCount.textContent = totalRows;
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