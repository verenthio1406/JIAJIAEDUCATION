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

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_type'] ?? 'info';
    
    // Clear flash message setelah ditampilkan
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

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
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    header("Location: guru.php?error=database");
    exit();
}

// Ambil daftar cabang tempat guru ini mengajar
$guru_cabang_list = [];
try {
    $stmt = $conn->prepare("SELECT c.cabang_id, c.nama_cabang 
                            FROM cabangguru cg 
                            JOIN cabang c ON cg.cabang_id = c.cabang_id 
                            WHERE cg.guru_id = ?
                            ORDER BY c.nama_cabang ASC");
    $stmt->bind_param("i", $guru_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $guru_cabang_list[] = $row;
    }
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Error loading cabang: " . $e->getMessage());
}

// Cek apakah guru punya cabang
if (empty($guru_cabang_list)) {
    $message = 'Guru ini belum di-assign ke cabang manapun. Silakan edit guru terlebih dahulu untuk menambahkan cabang.';
    $message_type = 'warning';
}

// Ambil cabang yang dipilih (dari GET atau default ke cabang pertama)
$selected_cabang_id = null;
if (isset($_GET['selected_cabang_id']) && !empty($_GET['selected_cabang_id'])) {
    $selected_cabang_id = (int)$_GET['selected_cabang_id'];  // ✅ BENAR - ambil dari GET
} elseif (!empty($guru_cabang_list)) {
    $selected_cabang_id = $guru_cabang_list[0]['cabang_id'];
}

// Ambil paket kelas yang tersedia di cabang yang dipilih
$available_paket = [];
if ($selected_cabang_id) {
    try {
        $query = "SELECT d.datales_id, 
                         jl.name as nama_jenisles,
                         tl.name as nama_tipe,
                         jt.nama_jenistingkat,
                         d.harga
                  FROM datales d
                  JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
                  JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                  JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
                  WHERE d.cabang_id = ?
                  ORDER BY jl.name ASC, tl.name ASC, jt.nama_jenistingkat ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $selected_cabang_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $available_paket[] = $row;
        }
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Error loading paket: " . $e->getMessage());
    }
}

// Ambil paket yang sudah di-assign ke guru ini di cabang yang dipilih
$assigned_paket_ids = [];
if ($selected_cabang_id) {
    try {
        $stmt = $conn->prepare("SELECT datales_id FROM guru_datales 
                                WHERE guru_id = ? AND cabang_id = ?");
        $stmt->bind_param("ii", $guru_id, $selected_cabang_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $assigned_paket_ids[] = $row['datales_id'];
        }
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Error loading assigned paket: " . $e->getMessage());
    }
}

// Function to validate CSRF token
function validateCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_paket'])) {
    
    if (!isset($_POST['csrf_token']) || !validateCSRF($_POST['csrf_token'])) {
        $message = 'Invalid security token. Please try again.';
        $message_type = 'danger';
    } else {
        
        $cabang_id_submit = (int)($_POST['cabang_id_submit'] ?? 0);
        $selected_paket = isset($_POST['paket_ids']) ? $_POST['paket_ids'] : [];

        if ($cabang_id_submit <= 0) {
            $message = 'Cabang tidak valid!';
            $message_type = 'danger';
        }
        elseif (empty($selected_paket)) {
            $message = 'Wajib memilih minimal 1 paket kelas! Guru harus mengajar minimal satu paket.';
            $message_type = 'danger';
        } 
        else {
            try {
                // Start transaction
                $conn->begin_transaction();
                
                // 1. Hapus semua paket lama di cabang ini untuk guru ini
                $stmt_delete = $conn->prepare("DELETE FROM guru_datales 
                                               WHERE guru_id = ? AND cabang_id = ?");
                $stmt_delete->bind_param("ii", $guru_id, $cabang_id_submit);
                
                if (!$stmt_delete->execute()) {
                    throw new Exception("Delete failed: " . $stmt_delete->error);
                }
                
                $deleted_rows = $stmt_delete->affected_rows;
                $stmt_delete->close();
                
                // 2. Insert paket baru yang dipilih
                $inserted_count = 0;
                if (!empty($selected_paket)) {
                    $stmt_insert = $conn->prepare("INSERT INTO guru_datales (guru_id, datales_id, cabang_id) 
                                                   VALUES (?, ?, ?)");
                    
                    foreach ($selected_paket as $datales_id) {
                        $datales_id = (int)$datales_id;
                        $stmt_insert->bind_param("iii", $guru_id, $datales_id, $cabang_id_submit);
                        
                        if (!$stmt_insert->execute()) {
                            throw new Exception("Insert failed: " . $stmt_insert->error);
                        }
                        $inserted_count++;
                    }
                    $stmt_insert->close();
                }
                
                // Commit transaction
                $conn->commit();

                if ($inserted_count > 0) {
                    $_SESSION['flash_message'] = "Berhasil! {$inserted_count} paket kelas telah di-assign ke guru {$guru_data['nama_guru']}.";
                    $_SESSION['flash_type'] = 'success';
                } else {
                    $_SESSION['flash_message'] = "Semua paket kelas telah dihapus dari guru {$guru_data['nama_guru']}.";
                    $_SESSION['flash_type'] = 'success';
                }

                // ⭐ Redirect ke guru.php dengan success message
                header("Location: guru.php?success=paket_assigned");
                exit();  

                // Update selected cabang
                $selected_cabang_id = $cabang_id_submit;
                
            } catch (Exception $e) {
                // Rollback jika ada error
                $conn->rollback();
                error_log("Database error: " . $e->getMessage());
                $message = 'Terjadi kesalahan saat menyimpan data: ' . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }
}

// Group paket berdasarkan jenis les untuk tampilan yang lebih rapi
$grouped_paket = [];
foreach ($available_paket as $paket) {
    $jenisles = $paket['nama_jenisles'];
    if (!isset($grouped_paket[$jenisles])) {
        $grouped_paket[$jenisles] = [];
    }
    $grouped_paket[$jenisles][] = $paket;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Assign Paket Kelas - Jia Jia Education</title>
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <style>
        .paket-group {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
            background-color: #f8f9fa;
        }
        
        .paket-group-title {
            font-weight: 600;
            color: #0d6efd;
            margin-bottom: 0.75rem;
            font-size: 1.1rem;
        }
        
        .paket-item {
            background-color: white;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
        }
    
        
        .paket-details {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        
        .counter-badge {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 15px 25px;
            background: #0d6efd;
            color: white;
            border-radius: 50px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            font-size: 1rem;
            font-weight: 600;
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

                        <div class="sb-sidenav-menu-heading">Pembayaran</div>
                        <a class="nav-link" href="verifikasi_pembayaran.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-check-circle"></i></div>
                            Verifikasi Pembayaran
                        </a>
                        <a class="nav-link" href="riwayat_pembayaran.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-history"></i></div>
                            Riwayat Pembayaran
                        </a>

                        <div class="sb-sidenav-menu-heading">Manage</div>
                        <a class="nav-link" href="siswa.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-user"></i></div>
                            Siswa
                        </a>
                        <a class="nav-link active" href="guru.php">
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
                        <a class="nav-link" href="paketkelas.php">
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
                    <h1 class="mt-4">Assign Paket Kelas</h1>
                    <br>

                    <?php if (!empty($message)): ?>
                        <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                            <i class="fas fa-<?php echo ($message_type === 'success') ? 'check-circle' : (($message_type === 'warning') ? 'exclamation-triangle' : 'exclamation-circle'); ?> me-2"></i>
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Info Guru -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h5 class="mb-2">
                                        <i class="fas fa-user-tie text-primary me-2"></i>
                                        <?php echo htmlspecialchars($guru_data['nama_guru']); ?>
                                    </h5>
                                    <p class="mb-0 text-muted">
                                        <span class="badge bg-<?php echo ($guru_data['status'] == 'aktif') ? 'success' : ($guru_data['status'] == 'cuti' ? 'warning' : 'secondary'); ?>">
                                            <?php echo htmlspecialchars(ucfirst($guru_data['status'])); ?>
                                        </span>
                                        <span class="ms-3">
                                            <i class="fas fa-building me-1"></i>
                                            Mengajar di <?php echo count($guru_cabang_list); ?> cabang
                                        </span>
                                    </p>
                                </div>
                                <div class="col-md-4 text-end">
                                    <a href="editguru.php?id=<?php echo $guru_id; ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-edit me-1"></i> Edit Data Guru
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($guru_cabang_list)): ?>
                    
                    <!-- Form Pilih Cabang -->
                    <div class="card mb-4">
                        <div class="card-header bg-dark text-white">
                            Pilih Cabang
                        </div>
                        <div class="card-body">
                            <form method="GET" id="formSelectCabang">
                                <input type="hidden" name="id" value="<?php echo $guru_id; ?>">
                                <div class="row align-items-end">
                                    <div class="col-md-8">
                                        <label class="form-label">Cabang</label>
                                        <select class="form-select" name="selected_cabang_id" id="selectCabang" onchange="this.form.submit()">
                                            <?php foreach ($guru_cabang_list as $cabang): ?>
                                            <option value="<?php echo $cabang['cabang_id']; ?>" 
                                                    <?php echo ($selected_cabang_id == $cabang['cabang_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cabang['nama_cabang']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Pilih cabang untuk melihat paket kelas yang tersedia</small>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Form Assign Paket -->
                    <?php if ($selected_cabang_id && !empty($available_paket)): ?>
                    <form method="POST" id="formAssignPaket">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="cabang_id_submit" value="<?php echo $selected_cabang_id; ?>">
                        <input type="hidden" name="submit_paket" value="1">
                        
                        <div class="card mb-4">
                            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                                <span>
                                    Paket Kelas yang Tersedia
                                </span>
                                <span class="badge bg-light text-dark" id="selectedCount">
                                    <?php echo count($assigned_paket_ids); ?> Paket Dipilih
                                </span>
                            </div>
                            <div class="card-body">
                                
                                <?php if (!empty($grouped_paket)): ?>
                                    
                                    <!-- Group Paket berdasarkan Jenis Les -->
                                    <?php foreach ($grouped_paket as $jenisles => $paket_list): ?>
                                    <div class="paket-group">
                                        <div class="paket-group-title">
                                            <?php echo htmlspecialchars($jenisles); ?>
                                            <span class="badge bg-secondary ms-2"><?php echo count($paket_list); ?></span>
                                        </div>
                                        
                                        <div class="row">
                                            <?php foreach ($paket_list as $paket): ?>
                                            <div class="col-md-6 col-lg-4">
                                                <div class="paket-item <?php echo in_array($paket['datales_id'], $assigned_paket_ids) ? 'selected' : ''; ?>" 
                                                     data-paket-id="<?php echo $paket['datales_id']; ?>">
                                                    <div class="form-check">
                                                        <input class="form-check-input paket-checkbox" 
                                                               type="checkbox" 
                                                               name="paket_ids[]" 
                                                               value="<?php echo $paket['datales_id']; ?>" 
                                                               id="paket_<?php echo $paket['datales_id']; ?>"
                                                               <?php echo in_array($paket['datales_id'], $assigned_paket_ids) ? 'checked' : ''; ?>
                                                               onchange="updateCounter()">
                                                        <label class="form-check-label w-100" for="paket_<?php echo $paket['datales_id']; ?>">
                                                            <strong><?php echo htmlspecialchars($paket['nama_tipe']); ?></strong>
                                                            <div class="paket-details">
                                                                <i class="fas fa-layer-group me-1"></i>
                                                                <?php echo htmlspecialchars($paket['nama_jenistingkat']); ?>
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
                                    
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Belum ada paket kelas yang tersedia di cabang ini.
                                    </div>
                                <?php endif; ?>
                                
                            </div>
                            <div class="card-footer">
                                <div class="d-flex justify-content-between">
                                    <a href="guru.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-1"></i> Kembali
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-1"></i> Simpan Paket Kelas
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                    
                    <?php elseif ($selected_cabang_id): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Belum ada paket kelas yang tersedia di cabang ini. Silakan tambahkan paket kelas terlebih dahulu.
                        </div>
                    <?php endif; ?>
                    
                    <?php else: ?>
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-exclamation-circle fa-3x text-warning mb-3"></i>
                                <h5>Guru Belum Di-assign ke Cabang</h5>
                                <p class="text-muted">
                                    Guru ini belum di-assign ke cabang manapun. 
                                    Silakan edit data guru untuk menambahkan cabang terlebih dahulu.
                                </p>
                                <a href="editguru.php?id=<?php echo $guru_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-edit me-1"></i> Edit Guru
                                </a>
                                <a href="guru.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-1"></i> Kembali
                                </a>
                            </div>
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
        // Auto hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 500);
            });
        }, 5000);

        // Update counter saat checkbox berubah
        function updateCounter() {
            const checkboxes = document.querySelectorAll('.paket-checkbox:checked');
            const count = checkboxes.length;
            
            // Update badge di header
            document.getElementById('selectedCount').textContent = count + ' Paket Dipilih';
            
            // Update floating badge
            const floatingCounter = document.getElementById('floatingCounter');
            const floatingCount = document.getElementById('floatingCount');
            
            if (count > 0) {
                floatingCount.textContent = count;
                floatingCounter.style.display = 'block';
            } else {
                floatingCounter.style.display = 'none';
            }
            
            // Update visual paket-item
            document.querySelectorAll('.paket-item').forEach(function(item) {
                const checkbox = item.querySelector('.paket-checkbox');
                if (checkbox.checked) {
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
            });
        }


        // Initialize counter on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateCounter();
        });

        // Konfirmasi sebelum submit
        document.getElementById('formAssignPaket')?.addEventListener('submit', function(e) {
            const count = document.querySelectorAll('.paket-checkbox:checked').length;
            
            if (count === 0) {
                e.preventDefault();
                
                // Tampilkan alert error
                alert('⚠️ Wajib memilih minimal 1 paket kelas!\n\nGuru harus mengajar minimal satu paket kelas.');
                
                // Scroll ke paket pertama untuk memudahkan user
                const firstPaket = document.querySelector('.paket-group');
                if (firstPaket) {
                    firstPaket.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
                
                // Highlight paket groups dengan animasi
                document.querySelectorAll('.paket-group').forEach(function(group) {
                    group.style.animation = 'shake 0.5s';
                    setTimeout(() => group.style.animation = '', 500);
                });
                
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