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

$message = '';
$message_type = '';

// Handle success messages
if (isset($_GET['success'])) {
    $allowed_success = ['guru_added', 'guru_updated', 'guru_deleted', 'paket_assigned'];  // ⭐ Tambah ini
    if (in_array($_GET['success'], $allowed_success)) {
        switch($_GET['success']) {
            case 'guru_added':
                $message = 'Guru berhasil ditambahkan!';
                $message_type = 'success';
                break;
            case 'guru_updated':
                $message = 'Data guru berhasil diupdate!';
                $message_type = 'success';
                break;
            case 'guru_deleted':
                $message = 'Guru berhasil dihapus!';
                $message_type = 'success';
                break;
            case 'paket_assigned':
                $message = $_SESSION['flash_message'] ?? 'Paket kelas berhasil di-assign!';
                $message_type = 'success';
                
                // Clear flash message
                if (isset($_SESSION['flash_message'])) {
                    unset($_SESSION['flash_message']);
                    unset($_SESSION['flash_type']);
                }
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
    
    if (!isset($_POST['csrf_token']) || !validateCSRF($_POST['csrf_token'])) {
        $message = 'Invalid security token. Please try again.';
        $message_type = 'danger';
    } else {
        
        // DELETE GURU (Head Admin only sudah dipastikan di requireHeadAdmin())
        if (isset($_POST['delete_guru'])) {
            $guru_id = (int)$_POST['guru_id'];
            
            if ($guru_id <= 0) {
                $message = 'ID guru tidak valid!';
                $message_type = 'danger';
            } else {
                try {
                    $stmt = $conn->prepare("DELETE FROM guru WHERE guru_id = ?");
                    $stmt->bind_param("i", $guru_id);
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        header("Location: guru.php?success=guru_deleted");
                        exit();
                    } else {
                        $message = 'Gagal menghapus guru. Silakan coba lagi.';
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
    // Query untuk Head Admin (semua guru)
    $query = "SELECT g.guru_id, g.nama_guru, g.status,
                     GROUP_CONCAT(DISTINCT c.nama_cabang ORDER BY c.nama_cabang SEPARATOR ', ') as cabang_list,
                     GROUP_CONCAT(DISTINCT cg.cabang_id ORDER BY c.nama_cabang SEPARATOR ',') as cabang_ids,
                     GROUP_CONCAT(DISTINCT 
                         CONCAT(jl.name, ' - ', tl.name, ' (', cb.nama_cabang, ')') 
                         ORDER BY cb.nama_cabang, jl.name, tl.name 
                         SEPARATOR ' | ') as paket_list
              FROM guru g
              LEFT JOIN cabangguru cg ON g.guru_id = cg.guru_id
              LEFT JOIN cabang c ON cg.cabang_id = c.cabang_id
              LEFT JOIN guru_datales gd ON g.guru_id = gd.guru_id
              LEFT JOIN datales d ON gd.datales_id = d.datales_id
              LEFT JOIN cabang cb ON gd.cabang_id = cb.cabang_id
              LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
              LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
              LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
              GROUP BY g.guru_id
              ORDER BY g.nama_guru ASC";
    
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $guru_result = $stmt->get_result();
    
    if (!$guru_result) {
        throw new Exception("Get result failed: " . $stmt->error);
    }
    
    $guru_data = [];
    while ($row = $guru_result->fetch_assoc()) {
        $guru_data[] = $row;
    }
    
    $stmt->close();
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $message = 'Gagal memuat data: ' . $e->getMessage();
    $message_type = 'danger';
    $guru_data = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Manajemen Guru - Jia Jia Education</title>
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
                    <h1 class="mt-4">Daftar Guru</h1>
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
                            Tambah Guru Baru
                            <a href="tambahguru.php" class="btn btn-dark btn-sm float-end">
                                <i class="fas fa-plus"></i> Tambah Guru
                            </a>
                        </div>
                    </div>

                    <!-- FILTER CARD - HANYA UNTUK HEAD ADMIN -->
                    <?php if ($current_user_role_id == 1): ?>
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row align-items-end">
                                <div class="col-md-4">
                                    <label class="form-label"><i class="fas fa-filter me-1"></i>Filter Berdasarkan Cabang</label>
                                    <select class="form-select" id="filterCabang">
                                        <option value="">Semua Cabang</option>
                                        <?php
                                        // Load semua cabang untuk filter
                                        $stmt_cabang = $conn->prepare("SELECT cabang_id, nama_cabang FROM cabang ORDER BY nama_cabang");
                                        $stmt_cabang->execute();
                                        $result_cabang = $stmt_cabang->get_result();
                                        while ($row = $result_cabang->fetch_assoc()) {
                                            echo '<option value="' . (int)$row['cabang_id'] . '">' . htmlspecialchars($row['nama_cabang']) . '</option>';
                                        }
                                        $stmt_cabang->close();
                                        ?>
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
                            Data Guru
                        </div>
                        <div class="card-body">
                            <?php if (empty($guru_data)): ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Belum ada data guru. Klik "Tambah Guru" untuk menambahkan guru baru.
                                </div>
                            <?php else: ?>
                            
                            <!-- Search Box -->
                            <div class="mb-3">
                                <input type="text" id="searchInput" class="form-control" placeholder="Cari guru...">
                            </div>
                            
                            <div class="table-responsive">
                                <table class="table table-bordered" id="guruTable">
                                    <thead>
                                        <tr>
                                            <th width='40'>No</th>
                                            <th>Nama Guru</th>
                                            <th>Cabang</th>
                                            <th>Paket Kelas</th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        if (!empty($guru_data)) {
                                            foreach ($guru_data as $index => $guru): 
                                        ?>
                                        <tr data-cabang-ids="<?php echo htmlspecialchars($guru['cabang_ids'] ?? ''); ?>">
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo isset($guru['nama_guru']) ? htmlspecialchars($guru['nama_guru']) : 'N/A'; ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($guru['cabang_list'])) {
                                                    $cabang_arr = explode(', ', $guru['cabang_list']);
                                                    $total = count($cabang_arr);
                                                    
                                                    foreach ($cabang_arr as $index => $cabang) {
                                                        echo '<span class="text-dark">' . htmlspecialchars(trim($cabang)) . '</span>';
                                                        
                                                        // Tambah koma kecuali di item terakhir
                                                        if ($index < $total - 1) {
                                                            echo ', ';
                                                        }
                                                    }
                                                } else {
                                                    echo '<span class="text-muted">Belum ada cabang</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (!empty($guru['paket_list'])) {
                                                    $paket_arr = explode(' | ', $guru['paket_list']);
                                                    
                                                    echo '<div style="max-height: 100px; overflow-y: auto;">';
                                                    foreach ($paket_arr as $paket) {
                                                        echo '<span class="badge bg-success text-white me-1 mb-1 d-inline-block" style="font-size: 0.7rem;">' . htmlspecialchars($paket) . '</span>';
                                                    }
                                                    echo '</div>';
                                                } else {
                                                    echo '<span class="text-muted"><i class="fas fa-minus-circle me-1"></i>Belum ada paket</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php if (isset($guru['status']) && !empty($guru['status'])): ?>
                                                    <span class="badge bg-<?php echo ($guru['status'] == 'aktif') ? 'success' : ($guru['status'] == 'cuti' ? 'warning' : 'secondary'); ?>">
                                                        <?php echo htmlspecialchars(ucfirst($guru['status'])); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Tidak Ada Status</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($current_user_role_id <= 2): ?>
                                                <a href="detailguru.php?id=<?php echo (int)$guru['guru_id']; ?>" class="btn btn-sm btn-outline-info mb-1" title="Detail">
                                                    <i class="fas fa-info-circle"></i> Detail
                                                </a>
                                                <a href="editguru.php?id=<?php echo (int)$guru['guru_id']; ?>" class="btn btn-sm btn-outline-primary mb-1" title="Edit">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                                <a href="assignpaket.php?id=<?php echo (int)$guru['guru_id']; ?>" class="btn btn-sm btn-outline-success mb-1" title="Assign Paket">
                                                    <i class="fas fa-book"></i> Paket
                                                </a>
                                                <button class="btn btn-sm btn-outline-danger" onclick="deleteGuru(<?php echo (int)$guru['guru_id']; ?>)" data-bs-toggle="modal" data-bs-target="#deleteGuruModal" title="Hapus">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                                <?php else: ?>
                                                <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php 
                                            endforeach;
                                        } else {
                                            echo '<tr><td colspan="6" class="text-center">Tidak ada data guru</td></tr>';
                                        }
                                        ?>
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

    <!-- Delete Guru Modal -->
    <div class="modal fade" id="deleteGuruModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Hapus Guru</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="guru_id" id="delete_guru_id">
                    <div class="modal-body">
                        <p>Apakah Anda yakin ingin menghapus guru ini? Semua data relasi (cabang dan paket kelas) juga akan terhapus.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" name="delete_guru" class="btn btn-danger">Hapus Guru</button>
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
            const table = document.getElementById('guruTable');
            
            if (searchInput && table) {
                searchInput.addEventListener('keyup', function() {
                    applyFilters();
                });
            }
        });

        function applyFilters() {
            const searchInput = document.getElementById('searchInput');
            const table = document.getElementById('guruTable');
            
            if (!table) return;
            
            const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
            const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
            
            for (let i = 0; i < rows.length; i++) {
                const row = rows[i];
                const rowCabangIds = row.getAttribute('data-cabang-ids');
                const cells = row.getElementsByTagName('td');
                
                // Check 1: Cabang filter
                let passedCabangFilter = true;
                if (currentCabangFilter !== '') {
                    const cabangIdsArray = rowCabangIds ? rowCabangIds.split(',') : [];
                    passedCabangFilter = cabangIdsArray.includes(currentCabangFilter);
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

        function deleteGuru(guruId) {
            document.getElementById('delete_guru_id').value = guruId;
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

        <?php if ($current_user_role_id == 1): ?>
        // Filter guru berdasarkan cabang - UPDATED
        document.addEventListener('DOMContentLoaded', function() {
            const filterCabang = document.getElementById('filterCabang');
            
            if (filterCabang) {
                filterCabang.addEventListener('change', function() {
                    currentCabangFilter = this.value;
                    applyFilters();
                });
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>