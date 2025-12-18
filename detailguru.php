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

// Get guru_id dari URL
$guru_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($guru_id <= 0) {
    header("Location: guru.php?error=invalid_id");
    exit();
}

try {
    // Ambil data guru
    $stmt_guru = $conn->prepare("
        SELECT 
            g.guru_id,
            g.nama_guru,
            g.status
        FROM guru g
        WHERE g.guru_id = ?
    ");
    $stmt_guru->bind_param("i", $guru_id);
    $stmt_guru->execute();
    $result_guru = $stmt_guru->get_result();

    if ($result_guru->num_rows == 0) {
        header("Location: guru.php?error=not_found");
        exit();
    }

    $guru = $result_guru->fetch_assoc();
    $stmt_guru->close();

    // Ambil cabang yang diajar guru ini
    $stmt_cabang = $conn->prepare("
        SELECT DISTINCT c.nama_cabang
        FROM cabangguru cg
        INNER JOIN cabang c ON cg.cabang_id = c.cabang_id
        WHERE cg.guru_id = ?
        ORDER BY c.nama_cabang
    ");
    $stmt_cabang->bind_param("i", $guru_id);
    $stmt_cabang->execute();
    $result_cabang = $stmt_cabang->get_result();
    $cabang_list = [];
    while ($row = $result_cabang->fetch_assoc()) {
        $cabang_list[] = $row['nama_cabang'];
    }
    $stmt_cabang->close();

    // Ambil jadwal mengajar (AKTIF + HISTORY)
    $stmt_jadwal = $conn->prepare("
        SELECT 
            js.slot_id,
            c.nama_cabang,
            jl.name as jenis_les,
            tl.name as tipe_les,
            jt.nama_jenistingkat,
            d.harga,
            js.hari,
            js.jam_mulai,
            js.jam_selesai,
            js.tipe_kelas,
            js.kapasitas_maksimal,
            js.status,
            COUNT(DISTINCT sd.siswa_id) as jumlah_siswa,
            CASE 
                WHEN js.status = 'aktif' THEN 'Aktif'
                ELSE 'Non-aktif'
            END as status_label
        FROM jadwal_slot js
        INNER JOIN cabangguru cg ON js.cabangguruID = cg.id
        INNER JOIN cabang c ON cg.cabang_id = c.cabang_id
        INNER JOIN datales d ON js.jenistingkat_id = d.jenistingkat_id AND d.cabang_id = c.cabang_id
        INNER JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
        INNER JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        INNER JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        LEFT JOIN siswa_datales sd ON js.slot_id = sd.slot_id AND sd.status = 'aktif' AND sd.is_history = 0
        WHERE cg.guru_id = ?
        GROUP BY js.slot_id, c.nama_cabang, jl.name, tl.name, jt.nama_jenistingkat, d.harga, js.hari, js.jam_mulai, js.jam_selesai, js.tipe_kelas, js.kapasitas_maksimal, js.status
        ORDER BY 
            CASE WHEN js.status = 'aktif' THEN 0 ELSE 1 END,
            c.nama_cabang, 
            jl.name, 
            js.hari, 
            js.jam_mulai
    ");
    $stmt_jadwal->bind_param("i", $guru_id);
    $stmt_jadwal->execute();
    $result_jadwal = $stmt_jadwal->get_result();
    $jadwal_list = [];
    while ($row = $result_jadwal->fetch_assoc()) {
        $jadwal_list[] = $row;
    }
    $stmt_jadwal->close();

    // Ambil history perubahan jadwal
    $stmt_history_changes = $conn->prepare("
        SELECT 
            jsh.history_id,
            jsh.slot_id,
            jsh.hari,
            jsh.jam_mulai,
            jsh.jam_selesai,
            jsh.tipe_kelas,
            jsh.status,
            jsh.changed_at,
            jsh.change_reason,
            c.nama_cabang,
            jl.name as jenis_les,
            tl.name as tipe_les,
            jt.nama_jenistingkat,
            u.full_name as changed_by_name
        FROM jadwal_slot_history jsh
        INNER JOIN cabangguru cg ON jsh.cabangguruID = cg.id
        INNER JOIN cabang c ON cg.cabang_id = c.cabang_id
        INNER JOIN datales d ON jsh.jenistingkat_id = d.jenistingkat_id AND d.cabang_id = c.cabang_id
        INNER JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
        INNER JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        INNER JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        LEFT JOIN users u ON jsh.changed_by = u.user_id
        WHERE cg.guru_id = ?
        ORDER BY jsh.changed_at DESC
        LIMIT 20
    ");
    $stmt_history_changes->bind_param("i", $guru_id);
    $stmt_history_changes->execute();
    $result_history_changes = $stmt_history_changes->get_result();
    $history_changes = [];
    while ($row = $result_history_changes->fetch_assoc()) {
        $history_changes[] = $row;
    }
    $stmt_history_changes->close();

} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    header("Location: guru.php?error=database");
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Detail Guru - Jia Jia Education</title>
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
        .jadwal-item {
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
                        <?php if ($current_user_role_id == 1): ?>
                        <a class="nav-link active" href="guru.php">
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
                    <h1 class="mt-4">Detail Guru</h1>
                    <br>

                    <!-- Data Guru -->
                    <div class="section-title">
                        <i class="fas fa-user-tie me-2"></i>Data Guru
                    </div>
                    
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Nama Guru</div>
                                <div><?php echo htmlspecialchars($guru['nama_guru']); ?></div>
                            </div>
                            <div class="info-row">
                                <div class="info-label">Status</div>
                                <div><?php echo ucfirst($guru['status']); ?></div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-label">Cabang</div>
                                <div>
                                    <?php if (!empty($cabang_list)): ?>
                                        <?php echo htmlspecialchars(implode(', ', $cabang_list)); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Belum ada cabang</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Jadwal Mengajar -->
                    <div class="section-title">
                        <i class="fas fa-calendar-alt me-2"></i>Jadwal Mengajar
                    </div>

                    <div class="mb-4">
                        <?php if (count($jadwal_list) > 0): ?>
                            <div class="row">
                                <?php foreach ($jadwal_list as $jadwal): ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="jadwal-item <?php echo ($jadwal['status'] != 'aktif') ? 'opacity-75' : ''; ?>">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <strong><?php echo htmlspecialchars($jadwal['jenis_les']); ?></strong> - 
                                                <?php echo htmlspecialchars($jadwal['tipe_les']); ?>
                                            </div>
                                            <span class="badge bg-<?php echo ($jadwal['status'] == 'aktif') ? 'success' : 'secondary'; ?>">
                                                <?php echo ucfirst($jadwal['status']); ?>
                                            </span>
                                        </div>
                                        
                                        <small class="text-muted d-block mb-1">
                                            <?php echo htmlspecialchars($jadwal['nama_jenistingkat']); ?>
                                        </small>
                                        
                                        <small class="text-muted d-block mb-2">
                                            <?php echo htmlspecialchars($jadwal['nama_cabang']); ?>
                                        </small>
                                        
                                        <div class="mb-2">
                                            <strong><?php echo htmlspecialchars($jadwal['hari']); ?></strong>
                                            <br>
                                            <?php echo substr($jadwal['jam_mulai'], 0, 5) . ' - ' . substr($jadwal['jam_selesai'], 0, 5); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Guru belum memiliki jadwal mengajar.
                            </div>
                        <?php endif; ?>
                    </div>

                     <!-- History Perubahan Jadwal -->
                    <?php if (!empty($history_changes) && count($history_changes) > 0): ?>
                    <div class="section-title mt-4">
                        <i class="fas fa-history me-2"></i>History Perubahan Jadwal
                    </div>

                    <div class="mb-4">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Tanggal</th>
                                        <th>Cabang</th>
                                        <th>Paket</th>
                                        <th>Jadwal Lama</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history_changes as $history): ?>
                                    <tr>
                                        <td><?php echo date('d M Y H:i', strtotime($history['changed_at'])); ?></td>
                                        <td><?php echo htmlspecialchars($history['nama_cabang']); ?></td>
                                        <td>
                                            <small>
                                                <strong><?php echo htmlspecialchars($history['jenis_les']); ?></strong><br>
                                                <?php echo htmlspecialchars($history['tipe_les']); ?> - 
                                                <?php echo htmlspecialchars($history['nama_jenistingkat']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($history['hari']); ?></strong><br>
                                            <small><?php echo substr($history['jam_mulai'], 0, 5) . ' - ' . substr($history['jam_selesai'], 0, 5); ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>


                    <!-- Action Buttons -->
                    <div class="d-flex justify-content-between pt-3 border-top">
                        <a href="guru.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Kembali
                        </a>
                        
                        <?php if ($current_user_role_id <= 2): ?>
                        <a href="editguru.php?id=<?php echo $guru_id; ?>" class="btn btn-primary">
                            <i class="fas fa-edit me-1"></i>Edit Data Guru
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