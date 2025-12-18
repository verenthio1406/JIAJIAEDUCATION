<?php
require_once 'check_login.php';
requireHeadAdmin(); // Hanya Head Admin yang bisa akses halaman ini

require_once 'config.php';
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
    $allowed_success = ['role_added', 'role_updated', 'role_deleted'];
    if (in_array($_GET['success'], $allowed_success)) {
        switch($_GET['success']) {
            case 'role_added':
                $message = 'Role berhasil ditambahkan!';
                $message_type = 'success';
                break;
            case 'role_updated':
                $message = 'Role berhasil diupdate!';
                $message_type = 'success';
                break;
            case 'role_deleted':
                $message = 'Role berhasil dihapus!';
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
        
        // ADD NEW ROLE
        if (isset($_POST['add_role'])) {
            $role_name = trim($_POST['role_name']);
            
            // Validasi
            if (empty($role_name)) {
                $message = 'Nama role harus diisi!';
                $message_type = 'danger';
            } else {
                try {
                    // Cek nama role sudah ada
                    $stmt = $conn->prepare("SELECT role_id FROM role WHERE role_name = ?");
                    $stmt->bind_param("s", $role_name);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $message = 'Nama role sudah ada!';
                        $message_type = 'danger';
                    } else {
                        // Insert role
                        $stmt = $conn->prepare("INSERT INTO role (role_name) VALUES (?)");
                        $stmt->bind_param("s", $role_name);
                        
                        if ($stmt->execute()) {
                            $stmt->close();
                            header("Location: role.php?success=role_added");
                            exit();
                        } else {
                            $message = 'Gagal menambahkan role. Silakan coba lagi.';
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
        
        // UPDATE ROLE
        if (isset($_POST['update_role'])) {
            $role_id = (int)$_POST['role_id'];
            $role_name = trim($_POST['role_name']);
            
            // Validasi
            if (empty($role_name) || $role_id <= 0) {
                $message = 'Nama role harus diisi!';
                $message_type = 'danger';
            } else {
                try {
                    // Cek nama role conflict
                    $stmt = $conn->prepare("SELECT role_id FROM role WHERE role_name = ? AND role_id != ?");
                    $stmt->bind_param("si", $role_name, $role_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $message = 'Nama role sudah digunakan role lain!';
                        $message_type = 'danger';
                    } else {
                        // Update role
                        $stmt = $conn->prepare("UPDATE role SET role_name = ? WHERE role_id = ?");
                        $stmt->bind_param("si", $role_name, $role_id);
                        
                        if ($stmt->execute()) {
                            $stmt->close();
                            header("Location: role.php?success=role_updated");
                            exit();
                        } else {
                            $message = 'Gagal mengupdate role. Silakan coba lagi.';
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
        
        // DELETE ROLE
        if (isset($_POST['delete_role'])) {
            $role_id = (int)$_POST['role_id'];
            
            if ($role_id <= 0) {
                $message = 'ID role tidak valid!';
                $message_type = 'danger';
            } else {
                try {
                    // Cek apakah role digunakan oleh user
                    $stmt = $conn->prepare("SELECT COUNT(*) as user_count FROM users WHERE role_id = ?");
                    $stmt->bind_param("i", $role_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $row = $result->fetch_assoc();
                    
                    if ($row['user_count'] > 0) {
                        $message = 'Tidak bisa hapus role! Role ini masih digunakan oleh ' . $row['user_count'] . ' user.';
                        $message_type = 'danger';
                    } else {
                        // Delete role
                        $stmt = $conn->prepare("DELETE FROM role WHERE role_id = ?");
                        $stmt->bind_param("i", $role_id);
                        
                        if ($stmt->execute()) {
                            $stmt->close();
                            header("Location: role.php?success=role_deleted");
                            exit();
                        } else {
                            $message = 'Gagal menghapus role. Silakan coba lagi.';
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
    }
}

try {
    // Ambil semua roles
    $stmt = $conn->prepare("SELECT role_id, role_name FROM role ORDER BY role_id ASC");
    $stmt->execute();
    $roles_result = $stmt->get_result();
    $roles_data = [];
    while ($row = $roles_result->fetch_assoc()) {
        $roles_data[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $message = 'Gagal memuat data. Silakan refresh halaman.';
    $message_type = 'danger';
    $roles_data = [];
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <title>Role Management - Jia Jia Education</title>
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
                            <a class="nav-link" href="siswa.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-user"></i></div>
                                Siswa
                            </a>
                            <a class="nav-link" href="guru.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                                Guru
                            </a>
                            
                            <div class="sb-sidenav-menu-heading">Setting</div>
                            <a class="nav-link active" href="role.php">
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
                        <h1 class="mt-4">Role Management</h1>
                        <br>

                        <?php if (!empty($message)): ?>
                            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                                <?php echo htmlspecialchars($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <div class="card mb-4">
                            <div class="card-header">
                                <i class="fas fa-user-tag me-1"></i>
                                Tambah Role Baru
                                <button type="button" class="btn btn-dark btn-sm float-end" data-bs-toggle="modal" data-bs-target="#addRoleModal">
                                    <i class="fas fa-plus"></i> Tambah Role
                                </button>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header">
                                Semua Role
                            </div>
                            <div class="card-body">
                                <?php if (empty($roles_data)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Belum ada role di database. Klik "Tambah Role" untuk membuat role pertama.
                                    </div>
                                <?php else: ?>
                                
                                <!-- Search Box -->
                                <div class="mb-3">
                                    <input type="text" id="searchInput" class="form-control" placeholder="Cari role...">
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered" id="roleTable">
                                        <thead>
                                            <tr>
                                                <th width='40'>No</th>
                                                <th>Nama Role</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($roles_data as $index => $role): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($role['role_name']); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="editRole(<?php echo (int)$role['role_id']; ?>, '<?php echo htmlspecialchars($role['role_name'], ENT_QUOTES); ?>')" data-bs-toggle="modal" data-bs-target="#editRoleModal" title="Edit">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteRole(<?php echo (int)$role['role_id']; ?>)" data-bs-toggle="modal" data-bs-target="#deleteRoleModal" title="Delete">
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

        <!-- Add Role Modal -->
        <div class="modal fade" id="addRoleModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah Role Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Nama Role <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="role_name" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="add_role" class="btn btn-primary">Tambah Role</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Role Modal -->
        <div class="modal fade" id="editRoleModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Role</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="role_id" id="edit_role_id">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Nama Role <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="role_name" id="edit_role_name" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="update_role" class="btn btn-primary">Update Role</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete Role Modal -->
        <div class="modal fade" id="deleteRoleModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Hapus Role</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="role_id" id="delete_role_id">
                        <div class="modal-body">
                            <p>Apakah Anda yakin ingin menghapus role ini?</p>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Catatan:</strong> Role tidak bisa dihapus jika masih digunakan oleh user.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="delete_role" class="btn btn-danger">Hapus Role</button>
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
                const table = document.getElementById('roleTable');
                
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

            function editRole(roleId, roleName) {
                document.getElementById('edit_role_id').value = roleId;
                document.getElementById('edit_role_name').value = roleName;
            }

            function deleteRole(roleId) {
                document.getElementById('delete_role_id').value = roleId;
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