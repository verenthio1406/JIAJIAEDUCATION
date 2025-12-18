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
    $allowed_success = ['user_added', 'user_updated', 'user_deleted'];
    if (in_array($_GET['success'], $allowed_success)) {
        switch($_GET['success']) {
            case 'user_added':
                $message = 'User berhasil ditambahkan!';
                $message_type = 'success';
                break;
            case 'user_updated':
                $message = 'User berhasil diupdate!';
                $message_type = 'success';
                break;
            case 'user_deleted':
                $message = 'User berhasil dihapus!';
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
        
        // ADD NEW USER
        if (isset($_POST['add_user'])) {
            $full_name = trim($_POST['full_name']);
            $username = trim($_POST['username']);
            $role_id = !empty($_POST['role_id']) ? (int)$_POST['role_id'] : null;
            $cabang_ids = $_POST['cabang_ids'] ?? []; // ✅ Array untuk multiple cabang
            
            // Generate password otomatis atau default
            $default_password = 'password123'; // Password default
            $password = password_hash($default_password, PASSWORD_DEFAULT);
            
            // Validasi
            if (empty($full_name) || empty($username)) {
                $message = 'Nama dan username harus diisi!';
                $message_type = 'danger';
            } elseif ($role_id !== null && $role_id != 1 && empty($cabang_ids)) {
                $message = 'Cabang harus dipilih untuk role selain Head Admin!';
                $message_type = 'danger';
            } else {
                try {
                    // Cek username sudah ada
                    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                    $stmt->bind_param("s", $username);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $message = 'Username sudah terdaftar!';
                        $message_type = 'danger';
                    } else {
                        // Start transaction
                        $conn->begin_transaction();
                        
                        // Insert user - FIXED: Removed NOW() from VALUES
                        if ($role_id === null) {
                            $stmt = $conn->prepare("INSERT INTO users (username, full_name, password, role_id) VALUES (?, ?, ?, NULL)");
                            $stmt->bind_param("sss", $username, $full_name, $password);
                        } else {
                            $stmt = $conn->prepare("INSERT INTO users (username, full_name, password, role_id) VALUES (?, ?, ?, ?)");
                            $stmt->bind_param("sssi", $username, $full_name, $password, $role_id);
                        }
                        
                        if ($stmt->execute()) {
                            $user_id = $conn->insert_id;
                            $stmt->close();
                            
                            // Insert ke tabel user_cabang (multiple cabang)
                            if (!empty($cabang_ids) && $role_id != 1) {
                                $stmt_cabang = $conn->prepare("INSERT INTO user_cabang (user_id, cabang_id) VALUES (?, ?)");
                                foreach ($cabang_ids as $cabang_id) {
                                    $cabang_id = (int)$cabang_id;
                                    $stmt_cabang->bind_param("ii", $user_id, $cabang_id);
                                    $stmt_cabang->execute();
                                }
                                $stmt_cabang->close();
                            }
                            
                            $conn->commit();
                            header("Location: user.php?success=user_added");
                            exit();
                        } else {
                            $conn->rollback();
                            $message = 'Gagal menambahkan user. Silakan coba lagi.';
                            $message_type = 'danger';
                        }
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Database error: " . $e->getMessage());
                    $message = 'Terjadi kesalahan database: ' . $e->getMessage();
                    $message_type = 'danger';
                }
            }
        }
        
        // UPDATE USER
        if (isset($_POST['update_user'])) {
            $user_id = (int)$_POST['user_id'];
            $full_name = trim($_POST['full_name']);
            $username = trim($_POST['username']);
            $role_id = !empty($_POST['role_id']) ? (int)$_POST['role_id'] : null;
            $cabang_ids = $_POST['cabang_ids'] ?? [];
            
            // Validasi
            if (empty($full_name) || empty($username) || $user_id <= 0) {
                $message = 'Nama dan username harus diisi!';
                $message_type = 'danger';
            } elseif ($role_id !== null && $role_id != 1 && empty($cabang_ids)) {
                $message = 'Cabang harus dipilih untuk role selain Head Admin!';
                $message_type = 'danger';
            } else {
                try {
                    // Cek username conflict
                    $stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ? AND user_id != ?");
                    $stmt->bind_param("si", $username, $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $message = 'Username sudah digunakan user lain!';
                        $message_type = 'danger';
                    } else {
                        // Start transaction
                        $conn->begin_transaction();
                        
                        // Update user
                        if ($role_id === null) {
                            $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, role_id = NULL WHERE user_id = ?");
                            $stmt->bind_param("ssi", $username, $full_name, $user_id);
                        } else {
                            $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, role_id = ? WHERE user_id = ?");
                            $stmt->bind_param("ssii", $username, $full_name, $role_id, $user_id);
                        }
                        
                        if ($stmt->execute()) {
                            $stmt->close();
                            
                            // Update user_cabang (hapus lama, insert baru)
                            $stmt_delete = $conn->prepare("DELETE FROM user_cabang WHERE user_id = ?");
                            $stmt_delete->bind_param("i", $user_id);
                            $stmt_delete->execute();
                            $stmt_delete->close();
                            
                            // Insert cabang baru (jika bukan head admin)
                            if (!empty($cabang_ids) && $role_id != 1) {
                                $stmt_cabang = $conn->prepare("INSERT INTO user_cabang (user_id, cabang_id) VALUES (?, ?)");
                                foreach ($cabang_ids as $cabang_id) {
                                    $cabang_id = (int)$cabang_id;
                                    $stmt_cabang->bind_param("ii", $user_id, $cabang_id);
                                    $stmt_cabang->execute();
                                }
                                $stmt_cabang->close();
                            }
                            
                            // Update session jika user edit profile sendiri
                            if ($user_id == $current_user_id) {
                                $_SESSION['name'] = $full_name;
                                $_SESSION['username'] = $username;
                                $_SESSION['role_id'] = $role_id;
                            }
                            
                            $conn->commit();
                            header("Location: user.php?success=user_updated");
                            exit();
                        } else {
                            $conn->rollback();
                            $message = 'Gagal mengupdate user. Silakan coba lagi.';
                            $message_type = 'danger';
                        }
                    }
                    $stmt->close();
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log("Database error: " . $e->getMessage());
                    $message = 'Terjadi kesalahan database. Silakan coba lagi.';
                    $message_type = 'danger';
                }
            }
        }
        
        // DELETE USER
        if (isset($_POST['delete_user'])) {
            $user_id = (int)$_POST['user_id'];
            
            // Prevent self-deletion
            if ($user_id == $current_user_id) {
                $message = 'Anda tidak bisa menghapus akun sendiri!';
                $message_type = 'danger';
            } elseif ($user_id <= 0) {
                $message = 'ID user tidak valid!';
                $message_type = 'danger';
            } else {
                try {
                    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                    $stmt->bind_param("i", $user_id);
                    
                    if ($stmt->execute()) {
                        header("Location: user.php?success=user_deleted");
                        exit();
                    } else {
                        $message = 'Gagal menghapus user. Silakan coba lagi.';
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

// Ambil data cabang untuk dropdown
$cabang_query = "SELECT cabang_id, nama_cabang FROM cabang ORDER BY nama_cabang";
$cabang_result = mysqli_query($conn, $cabang_query);
$cabang_options = [];
if ($cabang_result) {
    while ($row = mysqli_fetch_assoc($cabang_result)) {
        $cabang_options[] = $row;
    }
}

// Ambil semua users dengan cabang
$users_query = "SELECT u.user_id, u.full_name, u.username, u.role_id,
                GROUP_CONCAT(c.nama_cabang SEPARATOR ', ') as cabang_names,
                GROUP_CONCAT(c.cabang_id) as cabang_ids
                FROM users u 
                LEFT JOIN user_cabang uc ON u.user_id = uc.user_id
                LEFT JOIN cabang c ON uc.cabang_id = c.cabang_id 
                GROUP BY u.user_id
                ORDER BY u.user_id DESC";
$users_result = mysqli_query($conn, $users_query);
$users_data = [];
if ($users_result) {
    while ($row = mysqli_fetch_assoc($users_result)) {
        $users_data[] = $row;
    }
}

// Ambil nama role dari database
$role_query = "SELECT role_id, role_name FROM role ORDER BY role_id";
$role_result = mysqli_query($conn, $role_query);
$role_names = [];
if ($role_result) {
    while ($row = mysqli_fetch_assoc($role_result)) {
        $role_names[$row['role_id']] = $row['role_name'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <title>User Management - Jia Jia Education</title>
        <link href="css/styles.css" rel="stylesheet" />
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <style>
        /* Hilangkan hover effect pada tabel */
        #userTable tbody tr:hover,
        #userTable tbody tr:hover td {
            background-color: #ffffff !important;
            cursor: default !important;
        }
        
        /* Pastikan semua baris berwarna putih */
        #userTable tbody tr,
        #userTable tbody tr td {
            background-color: #ffffff !important;
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
                            <a class="nav-link" href="siswa.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-user"></i></div>
                                Siswa
                            </a>
                            <a class="nav-link" href="guru.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                                Guru
                            </a>
                            
                            <?php if ($current_user_role_id == 1): ?>
                            <div class="sb-sidenav-menu-heading">Setting</div>
                            <a class="nav-link" href="role.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-user-tag"></i></div>
                                Role Management
                            </a>
                            <a class="nav-link active" href="user.php">
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
                        <h1 class="mt-4">User Management</h1>
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
                                Tambah User Baru
                                <button type="button" class="btn btn-dark btn-sm float-end" data-bs-toggle="modal" data-bs-target="#addUserModal">
                                    <i class="fas fa-plus"></i> Tambah User
                                </button>
                            </div>
                        </div>

                        <div class="card mb-4">
                            <div class="card-header">
                                Semua User
                            </div>
                            <div class="card-body">
                                <?php if (empty($users_data)): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Belum ada user di database. Klik "Tambah User" untuk membuat user pertama.
                                    </div>
                                <?php else: ?>
                                
                                <!-- Search Box -->
                                <div class="mb-3">
                                    <input type="text" id="searchInput" class="form-control" placeholder="Cari user...">
                                </div>
                                
                                <div class="table-responsive">
                                    <table class="table table-bordered" id="userTable">
                                        <thead>
                                            <tr>
                                                <th width='40'>No</th>
                                                <th>Nama</th>
                                                <th>Username</th>
                                                <th>Role</th>
                                                <th>Cabang</th>
                                                <th>Aksi</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($users_data as $index => $user): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                                <td>
                                                    <?php if ($user['role_id'] === null): ?>
                                                        <span class="badge bg-secondary">Pending</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-<?php echo ($user['role_id'] == 1) ? 'danger' : ($user['role_id'] == 2 ? 'warning' : 'info'); ?>">
                                                            <?php echo htmlspecialchars($role_names[$user['role_id']] ?? 'Unknown'); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                    if ($user['role_id'] == 1) {
                                                        echo '<span class="text-dark">Semua Cabang</span>';
                                                    } elseif (!empty($user['cabang_names'])) {
                                                        echo htmlspecialchars($user['cabang_names']); // Multiple cabang
                                                    } else {
                                                        echo '<em class="text-muted">Belum diset</em>';
                                                    }
                                                    ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="editUser(<?php echo (int)$user['user_id']; ?>)" data-bs-toggle="modal" data-bs-target="#editUserModal" title="Edit">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </button>
                                                    <?php if ($user['user_id'] != $current_user_id): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteUser(<?php echo (int)$user['user_id']; ?>)" data-bs-toggle="modal" data-bs-target="#deleteUserModal" title="Delete">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
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
                            <div class="text-muted">Copyright &copy; Jia Jia Education 2024</div>
                        </div>
                    </div>
                </footer>
            </div>
        </div>

        <!-- Add User Modal -->
        <div class="modal fade" id="addUserModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Tambah User Baru</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="full_name" autocomplete="off" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" autocomplete="off" required>
                                <small class="text-muted">Password default: <strong>password123</strong> (bisa diubah di profile)</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Role <span class="text-danger">*</span></label>
                                <select class="form-control" name="role_id" id="add_role_id" required>
                                    <option value="">-- Pilih Role --</option>
                                    <?php foreach ($role_names as $rid => $rname): ?>
                                    <option value="<?php echo $rid; ?>"><?php echo htmlspecialchars($rname); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Cabang Section (Checkbox untuk Multiple) -->
                            <div class="mb-3" id="add_cabang_section" style="display: none;">
                                <label class="form-label">Cabang <span class="text-danger">*</span></label>
                                <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                                    <?php foreach ($cabang_options as $cabang): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" 
                                            name="cabang_ids[]" 
                                            value="<?php echo (int)$cabang['cabang_id']; ?>" 
                                            id="add_cabang_<?php echo (int)$cabang['cabang_id']; ?>">
                                        <label class="form-check-label" for="add_cabang_<?php echo (int)$cabang['cabang_id']; ?>">
                                            <?php echo htmlspecialchars($cabang['nama_cabang']); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted">Pilih minimal 1 cabang</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="add_user" class="btn btn-primary">Tambah User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit User Modal -->
        <div class="modal fade" id="editUserModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="user_id" id="edit_user_id">
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="full_name" id="edit_full_name" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="username" id="edit_username" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Role</label>
                                <select class="form-control" name="role_id" id="edit_role_id">
                                    <option value="">-- Belum Diset (Pending) --</option>
                                    <?php foreach ($role_names as $rid => $rname): ?>
                                    <option value="<?php echo $rid; ?>"><?php echo htmlspecialchars($rname); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Cabang Section (Checkbox untuk Multiple) -->
                            <div class="mb-3" id="edit_cabang_section" style="display: none;">
                                <label class="form-label">Cabang <span class="text-danger">*</span></label>
                                <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                                    <?php foreach ($cabang_options as $cabang): ?>
                                    <div class="form-check">
                                        <input class="form-check-input edit-cabang-checkbox" type="checkbox" 
                                            name="cabang_ids[]" 
                                            value="<?php echo (int)$cabang['cabang_id']; ?>" 
                                            id="edit_cabang_<?php echo (int)$cabang['cabang_id']; ?>">
                                        <label class="form-check-label" for="edit_cabang_<?php echo (int)$cabang['cabang_id']; ?>">
                                            <?php echo htmlspecialchars($cabang['nama_cabang']); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <small class="text-muted">Pilih minimal 1 cabang</small>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="update_user" class="btn btn-primary">Update User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Delete User Modal -->
        <div class="modal fade" id="deleteUserModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Hapus User</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="user_id" id="delete_user_id">
                        <div class="modal-body">
                            <p>Apakah Anda yakin ingin menghapus user ini? Tindakan ini tidak dapat dibatalkan.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                            <button type="submit" name="delete_user" class="btn btn-danger">Hapus User</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
        <script src="js/scripts.js"></script>

        <script>
            // Get user data
            const usersData = <?php 
                $users_array = [];
                foreach($users_data as $user) {
                    $cabang_ids_array = !empty($user['cabang_ids']) ? array_map('intval', explode(',', $user['cabang_ids'])) : [];
                    
                    $users_array[$user['user_id']] = [
                        'user_id' => (int)$user['user_id'],
                        'full_name' => htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'),
                        'username' => htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'),
                        'role_id' => $user['role_id'] ? (int)$user['role_id'] : null,
                        'cabang_ids' => $cabang_ids_array
                    ];
                }
                echo json_encode($users_array);
            ?>;

            // Simple Search Function
            document.addEventListener('DOMContentLoaded', function() {
                const searchInput = document.getElementById('searchInput');
                const table = document.getElementById('userTable');
                
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
                
                setupCabangHandlers();
            });

            function setupCabangHandlers() {
                // Add User
                const addRoleSelect = document.getElementById('add_role_id');
                if (addRoleSelect) {
                    addRoleSelect.addEventListener('change', function() {
                        const cabangSection = document.getElementById('add_cabang_section');
                        
                        if (this.value == '1' || this.value == '') {
                            cabangSection.style.display = 'none';
                        } else {
                            cabangSection.style.display = 'block';
                        }
                    });
                }

                // Edit User
                const editRoleSelect = document.getElementById('edit_role_id');
                if (editRoleSelect) {
                    editRoleSelect.addEventListener('change', function() {
                        const cabangSection = document.getElementById('edit_cabang_section');
                        
                        if (this.value == '1' || this.value == '') {
                            cabangSection.style.display = 'none';
                        } else {
                            cabangSection.style.display = 'block';
                        }
                    });
                }
            }

            function editUser(userId) {
                const user = usersData[userId];
                if (!user) {
                    console.error('User not found:', userId);
                    return;
                }
                
                document.getElementById('edit_user_id').value = user.user_id;
                document.getElementById('edit_full_name').value = user.full_name;
                document.getElementById('edit_username').value = user.username;
                document.getElementById('edit_role_id').value = user.role_id || '';
                
                // Uncheck semua checkbox dulu
                document.querySelectorAll('.edit-cabang-checkbox').forEach(cb => cb.checked = false);
                
                // Check checkbox sesuai cabang user
                if (user.cabang_ids && user.cabang_ids.length > 0) {
                    user.cabang_ids.forEach(cabangId => {
                        const checkbox = document.getElementById('edit_cabang_' + cabangId);
                        if (checkbox) checkbox.checked = true;
                    });
                }
                
                // Trigger change
                const editRoleSelect = document.getElementById('edit_role_id');
                if (editRoleSelect) {
                    editRoleSelect.dispatchEvent(new Event('change'));
                }
            }

            function deleteUser(userId) {
                document.getElementById('delete_user_id').value = userId;
            }

            // Reset form Add User saat modal ditutup
            const addUserModal = document.getElementById('addUserModal');
            addUserModal.addEventListener('hidden.bs.modal', function () {
                const form = this.querySelector('form');
                form.reset();
                document.getElementById('add_cabang_section').style.display = 'none';
            });

            // Reset form Add User saat modal dibuka
            addUserModal.addEventListener('show.bs.modal', function () {
                const form = this.querySelector('form');
                form.reset();
                document.getElementById('add_cabang_section').style.display = 'none';
                setTimeout(function() {
                    document.querySelector('#addUserModal input[name="full_name"]').focus();
                }, 500);
            });

            // Auto hide alerts
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert');
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