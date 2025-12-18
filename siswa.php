<?php
require 'check_login.php';
// Siswa bisa diakses semua role yang sudah login

require_once 'generate_jadwal_semester.php';

require 'config.php';
date_default_timezone_set('Asia/Jakarta');

// Generate CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function hitungUsia($tanggal_lahir) {
    if (empty($tanggal_lahir) || $tanggal_lahir == '0000-00-00') {
        return '-';
    }
    
    $birthDate = new DateTime($tanggal_lahir);
    $today = new DateTime('today');
    $usia = $birthDate->diff($today)->y;
    
    return $usia . ' tahun';
}

// Ambil data user dari session
$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = $_SESSION['role_id'];
$current_user_cabang_id = getUserCabangId();
$current_user_cabang_name = $_SESSION['cabang_name'] ?? 'Semua Cabang';

$message = '';
$message_type = '';

// Handle success messages from redirect
if (isset($_GET['success'])) {
    $allowed_success = ['siswa_added', 'siswa_updated', 'siswa_deleted'];
    if (in_array($_GET['success'], $allowed_success)) {
        switch($_GET['success']) {
            case 'siswa_added':
                $message = 'Siswa berhasil ditambahkan!';
                $message_type = 'success';
                break;
            case 'siswa_updated':
                $message = 'Data siswa berhasil diupdate!';
                $message_type = 'success';
                break;
            case 'siswa_deleted':
                $message = 'Siswa berhasil dihapus!';
                $message_type = 'success';
                break;
        }
    }
}

// Function to validate CSRF token
function validateCSRF($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRF($_POST['csrf_token'])) {
        $message = 'Invalid security token. Please try again.';
        $message_type = 'danger';
    } else {
        
        // DELETE SISWA - hanya untuk Head Admin dan Admin
        if (isset($_POST['delete_siswa']) && $current_user_role_id <= 2) {
            $siswa_id = (int)$_POST['siswa_id'];
            
            if ($siswa_id <= 0) {
                $message = 'ID siswa tidak valid!';
                $message_type = 'danger';
            } else {
                try {
                    $stmt = $conn->prepare("DELETE FROM siswa WHERE siswa_id = ?");
                    $stmt->bind_param("i", $siswa_id);
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        header("Location: siswa.php?success=siswa_deleted");
                        exit();
                    } else {
                        $message = 'Gagal menghapus siswa. Silakan coba lagi.';
                        $message_type = 'danger';
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    error_log("Database error: " . $e->getMessage());
                    $message = 'Terjadi kesalahan database. Silakan coba lagi.';
                    $message_type = 'danger';
                }
            }
        }
    }
}

try {
    // Query berbeda berdasarkan role_id
    if ($current_user_role_id == 1) {
        $stmt = $conn->prepare("SELECT s.siswa_id, s.name, s.username, s.jenis_kelamin, s.tanggal_lahir, 
                        s.cabang_id, s.asal_sekolah, s.status, 
                        s.nama_orangtua, s.no_telp, s.created_at,
                        c.nama_cabang as cabang_name,
                        GROUP_CONCAT(DISTINCT jl.name SEPARATOR ', ') as nama_jenisles,
                        GROUP_CONCAT(DISTINCT tl.name SEPARATOR ', ') as nama_tipe,
                        GROUP_CONCAT(DISTINCT jt.nama_jenistingkat SEPARATOR ', ') as nama_jenistingkat,
                        GROUP_CONCAT(DISTINCT d.datales_id) as datales_ids
                        FROM siswa s 
                        LEFT JOIN cabang c ON s.cabang_id = c.cabang_id 
                        LEFT JOIN siswa_datales sd ON s.siswa_id = sd.siswa_id 
                            AND sd.status = 'aktif' 
                            AND sd.is_history = 0
                        LEFT JOIN datales d ON sd.datales_id = d.datales_id
                        LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
                        LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                        LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
                        GROUP BY s.siswa_id
                        ORDER BY s.created_at DESC");
        $stmt->execute();
    }
    // Query untuk Admin (role_id = 2)
    elseif ($current_user_role_id == 2) {
        $stmt = $conn->prepare("SELECT s.siswa_id, s.name, s.username, s.jenis_kelamin, s.tanggal_lahir, 
                        s.cabang_id, s.asal_sekolah, s.status, 
                        s.nama_orangtua, s.no_telp, s.created_at,
                        c.nama_cabang as cabang_name,
                        GROUP_CONCAT(DISTINCT jl.name SEPARATOR ', ') as nama_jenisles,
                        GROUP_CONCAT(DISTINCT tl.name SEPARATOR ', ') as nama_tipe,
                        GROUP_CONCAT(DISTINCT jt.nama_jenistingkat SEPARATOR ', ') as nama_jenistingkat,
                        GROUP_CONCAT(DISTINCT d.datales_id) as datales_ids
                        FROM siswa s 
                        LEFT JOIN cabang c ON s.cabang_id = c.cabang_id 
                        LEFT JOIN siswa_datales sd ON s.siswa_id = sd.siswa_id 
                            AND sd.status = 'aktif' 
                            AND sd.is_history = 0
                        LEFT JOIN datales d ON sd.datales_id = d.datales_id
                        LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
                        LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                        LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
                        WHERE s.cabang_id IN (SELECT cabang_id FROM user_cabang WHERE user_id = ?)
                        GROUP BY s.siswa_id
                        ORDER BY s.created_at DESC");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
    }
    else {
        // Orang Tua (role_id = 3) - lihat siswa yang nama_orangtua = full_name mereka
        $current_user_full_name = getUserFullName(); // Nama lengkap dari users.full_name
        
        $stmt = $conn->prepare("SELECT s.siswa_id, s.name, s.jenis_kelamin, s.tanggal_lahir, 
                        s.cabang_id, s.asal_sekolah, s.status, 
                        s.nama_orangtua, s.no_telp, s.created_at,
                        c.nama_cabang as cabang_name,
                        GROUP_CONCAT(DISTINCT jl.name SEPARATOR ', ') as nama_jenisles,
                        GROUP_CONCAT(DISTINCT tl.name SEPARATOR ', ') as nama_tipe,
                        GROUP_CONCAT(DISTINCT jt.nama_jenistingkat SEPARATOR ', ') as nama_jenistingkat,
                        GROUP_CONCAT(DISTINCT d.datales_id) as datales_ids
                        FROM siswa s 
                        LEFT JOIN cabang c ON s.cabang_id = c.cabang_id 
                        LEFT JOIN siswa_datales sd ON s.siswa_id = sd.siswa_id 
                            AND sd.status = 'aktif' 
                            AND sd.is_history = 0
                        LEFT JOIN datales d ON sd.datales_id = d.datales_id
                        LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
                        LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                        LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
                        WHERE s.nama_orangtua = ?
                        GROUP BY s.siswa_id
                        ORDER BY s.created_at DESC");
        $stmt->bind_param("s", $current_user_full_name);
        $stmt->execute();
    }
    
    $siswa_result = $stmt->get_result();
    $siswa_data = [];
    while ($row = $siswa_result->fetch_assoc()) {
        $siswa_data[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $message = 'Gagal memuat data. Silakan refresh halaman.';
    $message_type = 'danger';
    $siswa_data = [];
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <title>Manajemen Siswa - Jia Jia Education</title>
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
                        <h1 class="mt-4">Daftar Siswa</h1>
                        <br>

                        <?php if (!empty($message)): ?>
                            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                                <?php echo htmlspecialchars($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <div class="card mb-4">
                            <div class="card-header">
                                <i class="fas fa-user-plus me-1"></i>
                                Tambah Siswa Baru
                                <?php if ($current_user_role_id <= 2): ?>
                                <a href="tambahsiswa.php" class="btn btn-dark btn-sm float-end">
                                    <i class="fas fa-plus"></i> Tambah Siswa
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php 

                        $show_filter = false;
                        $filter_cabang_options = [];

                        if ($current_user_role_id == 1) {
                            // Head Admin - filter semua cabang
                            $show_filter = true;
                            $stmt_cabang = $conn->prepare("SELECT cabang_id, nama_cabang FROM cabang ORDER BY nama_cabang");
                            $stmt_cabang->execute();
                            $result_cabang = $stmt_cabang->get_result();
                            while ($row = $result_cabang->fetch_assoc()) {
                                $filter_cabang_options[] = $row;
                            }
                            $stmt_cabang->close();
                        } elseif ($current_user_role_id == 2) {
                            // Admin - filter hanya cabang yang di-handle
                            $stmt_cabang = $conn->prepare("SELECT c.cabang_id, c.nama_cabang 
                                                            FROM cabang c
                                                            INNER JOIN user_cabang uc ON c.cabang_id = uc.cabang_id
                                                            WHERE uc.user_id = ?
                                                            ORDER BY c.nama_cabang");
                            $stmt_cabang->bind_param("i", $current_user_id);
                            $stmt_cabang->execute();
                            $result_cabang = $stmt_cabang->get_result();
                            while ($row = $result_cabang->fetch_assoc()) {
                                $filter_cabang_options[] = $row;
                            }
                            $stmt_cabang->close();
                            
                            // Tampilkan filter HANYA jika admin handle > 1 cabang
                            if (count($filter_cabang_options) > 1) {
                                $show_filter = true;
                            }
                        }
                        ?>

                        <?php if ($show_filter): ?>
                        <div class="card mb-4">
                            <div class="card-body">
                                <div class="row align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label"><i class="fas fa-filter me-1"></i>Filter Berdasarkan Cabang</label>
                                        <select class="form-select" id="filterCabang">
                                            <option value="">
                                                <?php echo $current_user_role_id == 1 ? 'Semua Cabang' : 'Semua Cabang Saya'; ?>
                                            </option>
                                            <?php foreach ($filter_cabang_options as $cabang): ?>
                                            <option value="<?php echo (int)$cabang['cabang_id']; ?>">
                                                <?php echo htmlspecialchars($cabang['nama_cabang']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <button class="btn btn-secondary w-100" onclick="resetFilter()">
                                            <i class="fas fa-redo me-1"></i>Reset
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="card mb-4">
                            <div class="card-header">
                                Data Siswa
                            </div>
                            <div class="card-body">
                                <?php if (empty($siswa_data)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Belum ada data siswa. Klik "Tambah Siswa" untuk menambahkan siswa baru.
                                    </div>
                                <?php else: ?>
                                
                                <!-- Search Box -->
                                <div class="mb-3">
                                    <input type="text" id="searchInput" class="form-control" placeholder="Cari siswa...">
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered" id="siswaTable">
                                        <thead>
                                            <tr>
                                                <th width="40">No</th>
                                                <th>Nama</th>
                                                <th>Username</th>
                                                <th>Cabang</th>
                                                <th>Paket yang Diambil</th>
                                                <th>Status</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($siswa_data as $index => $siswa): ?>
                                            <tr data-cabang-id="<?php echo (int)$siswa['cabang_id']; ?>">
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($siswa['name']); ?></td>
                                                <td><?php echo htmlspecialchars($siswa['username'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($siswa['cabang_name']); ?></td>
                                                <td>
                                                    <?php if (!empty($siswa['nama_jenisles'])): ?>
                                                        <div style="line-height: 1.4;">
                                                            <strong><?php echo htmlspecialchars($siswa['nama_jenisles']); ?></strong><br>
                                                            <small class="text-muted"><?php echo htmlspecialchars($siswa['nama_tipe']); ?></small><br>
                                                            <small class="text-muted">- <?php echo htmlspecialchars($siswa['nama_jenistingkat']); ?></small>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted"><em>Belum ada paket</em></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo ($siswa['status'] == 'aktif') ? 'success' : ($siswa['status'] == 'cuti' ? 'warning' : 'secondary'); ?>">
                                                        <?php echo htmlspecialchars(ucfirst($siswa['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($current_user_role_id <= 2): ?>
                                                    <!-- Detail Button -->
                                                    <a href="detailsiswa.php?id=<?php echo (int)$siswa['siswa_id']; ?>" class="btn btn-sm btn-outline-info mb-1" title="Detail">
                                                        <i class="fas fa-info-circle"></i> Detail
                                                    </a>
                                                    <!-- Edit Button -->
                                                    <a href="editsiswa.php?id=<?php echo (int)$siswa['siswa_id']; ?>" class="btn btn-sm btn-outline-primary mb-1" title="Edit">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <!-- Delete Button -->
                                                    <button class="btn btn-sm btn-outline-danger mb-1" onclick="deleteSiswa(<?php echo (int)$siswa['siswa_id']; ?>)" data-bs-toggle="modal" data-bs-target="#deleteSiswaModal" title="Hapus">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                    <?php else: ?>
                                                    <!-- Orang Tua hanya bisa lihat detail -->
                                                    <a href="detailsiswa.php?id=<?php echo (int)$siswa['siswa_id']; ?>" class="btn btn-sm btn-outline-info" title="Detail">
                                                        <i class="fas fa-info-circle"></i> Detail
                                                    </a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php endif; ?>
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

        <!-- Delete Siswa Modal -->
        <div class="modal fade" id="deleteSiswaModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Hapus Siswa</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="siswa_id" id="delete_siswa_id">
                        <div class="modal-body">
                            <p>Apakah Anda yakin ingin menghapus siswa ini?</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="delete_siswa" class="btn btn-danger">Hapus Siswa</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="js/scripts.js"></script>

        <script>
            // Global variable untuk menyimpan filter state
            let currentCabangFilter = '';

            // Simple Search Function - UPDATED untuk respect cabang filter
            document.addEventListener('DOMContentLoaded', function() {
                const searchInput = document.getElementById('searchInput');
                const table = document.getElementById('siswaTable');
                
                if (searchInput && table) {
                    searchInput.addEventListener('keyup', function() {
                        applyFilters();
                    });
                }
            });

            function applyFilters() {
                const searchInput = document.getElementById('searchInput');
                const table = document.getElementById('siswaTable');
                
                if (!table) return;
                
                const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
                const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                
                for (let i = 0; i < rows.length; i++) {
                    const row = rows[i];
                    const rowCabangId = row.getAttribute('data-cabang-id');
                    const cells = row.getElementsByTagName('td');
                    
                    // Check 1: Cabang filter
                    let passedCabangFilter = true;
                    if (currentCabangFilter !== '') {
                        passedCabangFilter = (rowCabangId === currentCabangFilter);
                    }
                    
                    // Check 2: Search filter
                    let passedSearchFilter = true;
                    if (searchTerm !== '') {
                        passedSearchFilter = false;
                        for (let j = 0; j < cells.length; j++) {
                            const cellText = cells[j].textContent.toLowerCase();
                            if (cellText.indexOf(searchTerm) > -1) {
                                passedSearchFilter = true;
                                break;
                            }
                        }
                    }
                    
                    // Show row only if passed BOTH filters
                    row.style.display = (passedCabangFilter && passedSearchFilter) ? '' : 'none';
                }
            }

            function deleteSiswa(siswaId) {
                document.getElementById('delete_siswa_id').value = siswaId;
            }

            // Auto hide alerts
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function(alert) {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.style.display = 'none', 500);
                });
            }, 5000);

            <?php if ($show_filter): ?>
            // Filter siswa berdasarkan cabang - UPDATED
            document.addEventListener('DOMContentLoaded', function() {
                const filterCabang = document.getElementById('filterCabang');
                
                if (filterCabang) {
                    filterCabang.addEventListener('change', function() {
                        currentCabangFilter = this.value;
                        applyFilters();
                    });
                }
            });

            function resetFilter() {
                const filterCabang = document.getElementById('filterCabang');
                const searchInput = document.getElementById('searchInput');
                
                if (filterCabang) {
                    filterCabang.value = '';
                    currentCabangFilter = '';
                }
                if (searchInput) searchInput.value = '';
                
                // Tampilkan semua baris
                applyFilters();
            }
            <?php endif; ?>
        </script>
    </body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>