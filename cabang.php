<?php
require 'check_login.php';
requireHeadAdmin(); // Hanya Head Admin yang bisa akses halaman ini

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

// Handle success messages from redirect
if (isset($_GET['success'])) {
    $allowed_success = ['cabang_added', 'cabang_updated', 'cabang_deleted'];
    if (in_array($_GET['success'], $allowed_success)) {
        switch($_GET['success']) {
            case 'cabang_added':
                $message = 'Cabang berhasil ditambahkan!';
                $message_type = 'success';
                break;
            case 'cabang_updated':
                $message = 'Cabang berhasil diupdate!';
                $message_type = 'success';
                break;
            case 'cabang_deleted':
                $message = 'Cabang berhasil dihapus!';
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
        
        // ADD NEW CABANG
        if (isset($_POST['add_cabang'])) {
            $nama_cabang = trim($_POST['nama_cabang']);
            
            // Validasi
            if (empty($nama_cabang)) {
                $message = 'Nama cabang harus diisi!';
                $message_type = 'danger';
            } else {
                try {
                    // Cek nama cabang sudah ada
                    $stmt = $conn->prepare("SELECT cabang_id FROM cabang WHERE nama_cabang = ?");
                    $stmt->bind_param("s", $nama_cabang);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $message = 'Nama cabang sudah ada!';
                        $message_type = 'danger';
                    } else {
                        // Insert cabang
                        $stmt = $conn->prepare("INSERT INTO cabang (nama_cabang) VALUES (?)");
                        $stmt->bind_param("s", $nama_cabang);
                        
                        if ($stmt->execute()) {
                            $stmt->close();
                            header("Location: cabang.php?success=cabang_added");
                            exit();
                        } else {
                            $message = 'Gagal menambahkan cabang. Silakan coba lagi.';
                            $message_type = 'danger';
                        }
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    error_log("Database error: " . $e->getMessage());
                    $message = 'Terjadi kesalahan database. Silakan coba lagi.';
                    $message_type = 'danger';
                }
            }
        }
        
        // UPDATE CABANG
        if (isset($_POST['update_cabang'])) {
            $cabang_id = (int)$_POST['cabang_id'];
            $nama_cabang = trim($_POST['nama_cabang']);
            
            // Validasi
            if (empty($nama_cabang) || $cabang_id <= 0) {
                $message = 'Nama cabang harus diisi!';
                $message_type = 'danger';
            } else {
                try {
                    // Cek nama cabang conflict
                    $stmt = $conn->prepare("SELECT cabang_id FROM cabang WHERE nama_cabang = ? AND cabang_id != ?");
                    $stmt->bind_param("si", $nama_cabang, $cabang_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $message = 'Nama cabang sudah digunakan cabang lain!';
                        $message_type = 'danger';
                    } else {
                        // Update cabang
                        $stmt = $conn->prepare("UPDATE cabang SET nama_cabang = ? WHERE cabang_id = ?");
                        $stmt->bind_param("si", $nama_cabang, $cabang_id);
                        
                        if ($stmt->execute()) {
                            $stmt->close();
                            header("Location: cabang.php?success=cabang_updated");
                            exit();
                        } else {
                            $message = 'Gagal mengupdate cabang. Silakan coba lagi.';
                            $message_type = 'danger';
                        }
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    error_log("Database error: " . $e->getMessage());
                    $message = 'Terjadi kesalahan database. Silakan coba lagi.';
                    $message_type = 'danger';
                }
            }
        }
        
        // DELETE CABANG
        if (isset($_POST['delete_cabang'])) {
            $cabang_id = (int)$_POST['cabang_id'];
            
            if ($cabang_id <= 0) {
                $message = 'ID cabang tidak valid!';
                $message_type = 'danger';
            } else {
                try {
                    // Cek apakah cabang digunakan di berbagai tabel
                    $usage_messages = [];
                    
                    // Cek di tabel siswa
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM siswa WHERE cabang_id = ?");
                    $stmt->bind_param("i", $cabang_id);
                    $stmt->execute();
                    $siswa_count = $stmt->get_result()->fetch_assoc()['count'];
                    if ($siswa_count > 0) {
                        $usage_messages[] = "$siswa_count siswa";
                    }
                    $stmt->close();
                    
                    // Cek di tabel user_cabang
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM user_cabang WHERE cabang_id = ?");
                    $stmt->bind_param("i", $cabang_id);
                    $stmt->execute();
                    $user_count = $stmt->get_result()->fetch_assoc()['count'];
                    if ($user_count > 0) {
                        $usage_messages[] = "$user_count user";
                    }
                    $stmt->close();
                    
                    // Cek di tabel guru_cabang (BUKAN guru.cabang_id)
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM cabangguru WHERE cabang_id = ?");
                    $stmt->bind_param("i", $cabang_id);
                    $stmt->execute();
                    $guru_count = $stmt->get_result()->fetch_assoc()['count'];
                    if ($guru_count > 0) {
                        $usage_messages[] = "$guru_count guru";
                    }
                    $stmt->close();
                    
                    // Cek di tabel datales
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM datales WHERE cabang_id = ?");
                    $stmt->bind_param("i", $cabang_id);
                    $stmt->execute();
                    $datales_count = $stmt->get_result()->fetch_assoc()['count'];
                    if ($datales_count > 0) {
                        $usage_messages[] = "$datales_count harga paket";
                    }
                    $stmt->close();
                    
                    // Jika ada yang menggunakan, tampilkan error
                    if (!empty($usage_messages)) {
                        $message = 'Tidak bisa hapus cabang! Cabang ini masih digunakan oleh: ' . implode(', ', $usage_messages) . '.';
                        $message_type = 'danger';
                    } else {
                        // Delete cabang
                        $stmt = $conn->prepare("DELETE FROM cabang WHERE cabang_id = ?");
                        $stmt->bind_param("i", $cabang_id);
                        
                        if ($stmt->execute()) {
                            $stmt->close();
                            header("Location: cabang.php?success=cabang_deleted");
                            exit();
                        } else {
                            $message = 'Gagal menghapus cabang. Silakan coba lagi.';
                            $message_type = 'danger';
                        }
                    }
                } catch (Exception $e) {
                    error_log("Database error: " . $e->getMessage());
                    $message = 'Terjadi kesalahan database: ' . $e->getMessage();
                    $message_type = 'danger';
                }
            }
        }  
    }
}

// Ambil semua cabang
$cabang_data = [];
try {
    $stmt = $conn->prepare("SELECT cabang_id, nama_cabang FROM cabang ORDER BY nama_cabang ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $cabang_data[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $message = 'Gagal memuat data cabang.';
    $message_type = 'danger';
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <title>Cabang Management - Jia Jia Education</title>
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
                            <a class="nav-link active" href="cabang.php">
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
                        <h1 class="mt-4">Cabang Management</h1>
                        <br>

                        <?php if (!empty($message)): ?>
                            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                                <?php echo htmlspecialchars($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <div class="card mb-4">
                            <div class="card-header">
                                <i class="fas fa-building me-1"></i>
                                Tambah Cabang Baru
                                <button type="button" class="btn btn-dark btn-sm float-end" data-bs-toggle="modal" data-bs-target="#addCabangModal">
                                    <i class="fas fa-plus"></i> Tambah Cabang
                                </button>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header">
                                Semua Cabang
                            </div>
                            <div class="card-body">
                                <?php if (empty($cabang_data)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Belum ada cabang di database. Klik "Tambah Cabang" untuk membuat cabang pertama.
                                    </div>
                                <?php else: ?>
                                
                                <!-- Search Box -->
                                <div class="mb-3">
                                    <input type="text" id="searchInput" class="form-control" placeholder="Cari cabang...">
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered" id="cabangTable">
                                        <thead>
                                            <tr>
                                                <th width='40'>No</th>
                                                <th>Nama Cabang</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($cabang_data as $index => $cabang): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($cabang['nama_cabang']); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="editCabang(<?php echo (int)$cabang['cabang_id']; ?>, '<?php echo htmlspecialchars($cabang['nama_cabang'], ENT_QUOTES); ?>')" data-bs-toggle="modal" data-bs-target="#editCabangModal" title="Edit">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCabang(<?php echo (int)$cabang['cabang_id']; ?>)" data-bs-toggle="modal" data-bs-target="#deleteCabangModal" title="Delete">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
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
                            <div class="text-muted">Copyright &copy; Jia Jia Education 2024</div>
                        </div>
                    </div>
                </footer>
            </div>
        </div>

        <!-- Add Cabang Modal -->
        <div class="modal fade" id="addCabangModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Cabang Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Nama Cabang <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama_cabang" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="add_cabang" class="btn btn-primary">Tambah Cabang</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Cabang Modal -->
        <div class="modal fade" id="editCabangModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Cabang</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="cabang_id" id="edit_cabang_id">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Nama Cabang <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="nama_cabang" id="edit_nama_cabang" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="update_cabang" class="btn btn-primary">Update Cabang</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Cabang Modal -->
        <div class="modal fade" id="deleteCabangModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Hapus Cabang</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="cabang_id" id="delete_cabang_id">
                        <div class="modal-body">
                            <p>Apakah Anda yakin ingin menghapus cabang ini?</p>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Catatan:</strong> Cabang tidak bisa dihapus jika masih digunakan oleh siswa, user, atau harga paket.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="delete_cabang" class="btn btn-danger">Hapus Cabang</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="js/scripts.js"></script>

        <script>
            // Simple Search Function
            document.addEventListener('DOMContentLoaded', function() {
                const searchInput = document.getElementById('searchInput');
                const table = document.getElementById('cabangTable');
                
                if (searchInput && table) {
                    searchInput.addEventListener('keyup', function() {
                        const searchTerm = this.value.toLowerCase();
                        const rows = table.getElementsByTagName('tbody')[0].getElementsByTagName('tr');
                        
                        for (let i = 0; i < rows.length; i++) {
                            const row = rows[i];
                            const cells = row.getElementsByTagName('td');
                            let found = false;
                            
                            for (let j = 0; j < cells.length; j++) {
                                const cellText = cells[j].textContent.toLowerCase();
                                if (cellText.indexOf(searchTerm) > -1) {
                                    found = true;
                                    break;
                                }
                            }
                            
                            row.style.display = found ? '' : 'none';
                        }
                    });
                }
            });

            function editCabang(cabangId, namaCabang) {
                document.getElementById('edit_cabang_id').value = cabangId;
                document.getElementById('edit_nama_cabang').value = namaCabang;
            }

            function deleteCabang(cabangId) {
                document.getElementById('delete_cabang_id').value = cabangId;
            }

            // Auto hide alerts
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert:not(.alert-warning)');
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