<?php
ob_start();

require 'check_login.php';

require 'config.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['active_cabang'])) {
    $token = $_POST['csrf_token'] ?? '';
    if (!empty($token) && hash_equals($_SESSION['csrf_token'], $token)) {
        $requestedCabang = (int) $_POST['active_cabang'];
        $allowed = false;

        // Head admin boleh akses semua cabang
        if (getUserRoleId() == 1) {
            $allowed = true;
        } else if (getUserRoleId() == 2) {
            $sessionCabangs = $_SESSION['cabangs'] ?? [];
            if (is_array($sessionCabangs)) {
                foreach ($sessionCabangs as $c) {
                    if ((int)$c['cabang_id'] === $requestedCabang) {
                        $allowed = true;
                        $cabangNameForSession = $c['nama_cabang'];
                        break;
                    }
                }
            }
            if (!$allowed && isset($conn)) {
                $stmt_check = $conn->prepare("SELECT 1 FROM user_cabang WHERE user_id = ? AND cabang_id = ? LIMIT 1");
                $stmt_check->bind_param("ii", getUserId(), $requestedCabang);
                $stmt_check->execute();
                $res_check = $stmt_check->get_result();
                if ($res_check && $res_check->num_rows > 0) {
                    $allowed = true;
                    $stmt_c = $conn->prepare("SELECT nama_cabang FROM cabang WHERE cabang_id = ? LIMIT 1");
                    $stmt_c->bind_param("i", $requestedCabang);
                    $stmt_c->execute();
                    $r = $stmt_c->get_result();
                    if ($r && $row = $r->fetch_assoc()) $cabangNameForSession = $row['nama_cabang'];
                    $stmt_c->close();
                }
                if ($stmt_check) $stmt_check->close();
            }
        }

        if ($allowed) {
            $_SESSION['cabang_id'] = $requestedCabang;
            if (isset($cabangNameForSession)) {
                $_SESSION['cabang_name'] = $cabangNameForSession;
            } else {
                // fallback ambil dari DB jika perlu
                if (isset($conn)) {
                    $stmt_n = $conn->prepare("SELECT nama_cabang FROM cabang WHERE cabang_id = ? LIMIT 1");
                    $stmt_n->bind_param("i", $requestedCabang);
                    $stmt_n->execute();
                    $res_n = $stmt_n->get_result();
                    if ($res_n && $row_n = $res_n->fetch_assoc()) $_SESSION['cabang_name'] = $row_n['nama_cabang'];
                    $stmt_n->close();
                }
            }
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit();
        } else {
            $message = 'Anda tidak memiliki akses untuk memilih cabang tersebut.';
            $message_type = 'warning';
        }
    }
}

// Ambil data user dari session
$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = $_SESSION['role_id'];
$current_user_cabang_id = getUserCabangId();
$current_user_cabang_name = $_SESSION['cabang_name'] ?? 'Semua Cabang';

$user_cabang_ids = [];
if ($current_user_role_id == 2) { 
    try {
        $stmt = $conn->prepare("SELECT cabang_id FROM user_cabang WHERE user_id = ?");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $user_cabang_ids[] = $row['cabang_id'];
        }
        $stmt->close();
        
        error_log("=== INDEX.PHP USER CABANG ===");
        error_log("User ID: $current_user_id");
        error_log("User Name: $current_user_name");
        error_log("Cabang IDs: " . implode(', ', $user_cabang_ids));
        error_log("=============================");
        
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());
    }
}

$role_names = [
    1 => 'Head Admin',
    2 => 'Admin',
    3 => 'Orang Tua'
];
$current_user_role = $role_names[$current_user_role_id] ?? 'User';

$message = '';
$message_type = '';

if (!isset($_SESSION['login_time'])) {
    $_SESSION['login_time'] = date('Y-m-d H:i:s');
}
$login_time = $_SESSION['login_time'];

$dashboard_stats = [
    'total_users' => 0,
    'total_courses' => 0,
    'active_sessions' => 0,
    'total_packages' => 0
];

if (isset($conn)) {
    try {
        if ($current_user_role_id == 1) {
            $res = $conn->query("SELECT COUNT(*) AS cnt FROM users");
            if ($res && $row = $res->fetch_assoc()) {
                $dashboard_stats['total_users'] = (int)$row['cnt'];
            }
        }

        $res = $conn->query("SELECT COUNT(*) AS cnt FROM datales");
        if ($res && $row = $res->fetch_assoc()) {
            $dashboard_stats['total_courses'] = (int)$row['cnt'];
        }

        $res = $conn->query("SELECT COUNT(*) AS cnt FROM siswa WHERE status = 'aktif'");
        if ($res && $row = $res->fetch_assoc()) {
            $dashboard_stats['active_sessions'] = (int)$row['cnt'];
        }

        $res = $conn->query("SELECT COUNT(*) AS cnt FROM paketkelas");
        if ($res && $row = $res->fetch_assoc()) {
            $dashboard_stats['total_packages'] = (int)$row['cnt'];
        }

    } catch (Exception $e) {
        error_log("Dashboard stats error: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
        <meta name="description" content="Jia Jia Education" />
        <meta name="author" content="Jia Jia Education" />
        <title>Landing Page - Jia Jia Education</title>
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
                            <a class="nav-link active" href="index.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                                Landing Page
                            </a>

                            <div class="sb-sidenav-menu-heading">Management</div>
                            <?php if ($current_user_role_id <= 2): ?>
                            <a class="nav-link" href="absensi.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-clipboard-check"></i></div>
                                Presensi
                            </a>
                            <a class="nav-link" href="kelola_semester.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-sync-alt"></i></div>
                            Kelola Semester
                            </a>
                            <?php endif; ?>

                            <?php if ($current_user_role_id == 1): ?>
                            <div class="sb-sidenav-menu-heading">Pembayaran</div>
                            <a class="nav-link" href="verifikasi_pembayaran.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-check-circle"></i></div>
                                Verifikasi Pembayaran
                            </a>
                            <?php endif; ?>

                            <?php if ($current_user_role_id == 3): ?>
                            <a class="nav-link" href="pembayaran.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-file-upload"></i></div>
                                Upload Pembayaran
                            </a>
                            <?php endif; ?>

                            <?php if ($current_user_role_id == 1 || $current_user_role_id == 3): ?>
                            <a class="nav-link" href="riwayat_pembayaran.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-history"></i></div>
                                Riwayat Pembayaran
                            </a>
                            <?php endif; ?>

                            <?php if ($current_user_role_id <= 2): ?>
                            <div class="sb-sidenav-menu-heading">Manage</div>
                            <a class="nav-link" href="siswa.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-user"></i></div>
                                Siswa
                            </a>
                            <?php endif; ?>
                            <?php if ($current_user_role_id == 1): ?>
                            <a class="nav-link" href="guru.php">
                                <div class="sb-nav-link-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                                Guru
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($current_user_role_id == 1): ?>
                            <!-- User Management di Setting - Hanya Head Admin -->
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
                        <?php echo htmlspecialchars(ucfirst($current_user_role)); ?> - <?php echo htmlspecialchars($current_user_name); ?><br>
                        <small class="text-muted"><?php echo htmlspecialchars($current_user_cabang_name); ?></small>
                    </div>
                </nav>
            </div>
            
            <div id="layoutSidenav_content">
                <main>
                    <div class="container-fluid px-4">
                        <div class="d-flex justify-content-between align-items-center mt-4 mb-4">
                            <div>
                                <h1 class="mt-0">Welcome, <?php echo htmlspecialchars($current_user_name); ?>!</h1>
                                <p class="lead text-muted">Jia Jia Education</p>
                            </div>
                            <div>
                                <span class="badge bg-<?php echo ($current_user_role_id == 1) ? 'danger' : ($current_user_role_id == 2 ? 'warning' : 'info'); ?> fs-6 px-3 py-2">
                                    <i class="fas fa-user-tag me-2"></i><?php echo htmlspecialchars(ucfirst($current_user_role)); ?>
                                </span>
                            </div>
                        </div>

                        <!-- TAGIHAN PENDING - Untuk Admin & Head Admin -->
                        <?php if ($current_user_role_id <= 2): ?>
                        <div class="mb-4">
                            <h4 class="mb-3">
                                <i class="fas fa-exclamation-circle text-danger me-2"></i>
                                TAGIHAN PENDING (BELUM DIBAYAR)
                            </h4>
                            
                            <?php
                            try {
                                $query_pending = "
                                    SELECT 
                                        p.siswa_id,
                                        s.name as nama_siswa,
                                        c.nama_cabang,
                                        p.bulan_ke,
                                        p.semester_ke,
                                        MIN(p.pembayaran_id) as pembayaran_id_utama,
                                        SUM(p.jumlah_bayar) as total_tagihan,
                                        GROUP_CONCAT(
                                            DISTINCT CONCAT(jl.name, ' - ', tl.name, ' - ', jt.nama_jenistingkat)
                                            ORDER BY jl.name 
                                            SEPARATOR ', '
                                        ) as daftar_paket,
                                        COUNT(DISTINCT p.datales_id) as jumlah_paket,
                                        (SELECT MIN(jp2.tanggal_pertemuan) 
                                        FROM jadwal_pertemuan jp2 
                                        WHERE jp2.siswa_id = p.siswa_id 
                                        AND jp2.bulan_ke = p.bulan_ke
                                        AND jp2.semester_ke = p.semester_ke
                                        AND jp2.is_history = 0
                                        ) as tanggal_pertemuan_pertama,
                                        (SELECT MAX(jp3.tanggal_pertemuan) 
                                        FROM jadwal_pertemuan jp3 
                                        WHERE jp3.siswa_id = p.siswa_id 
                                        AND jp3.bulan_ke = (p.bulan_ke - 1)
                                        AND jp3.semester_ke = p.semester_ke
                                        AND jp3.is_history = 0
                                        ) as tanggal_pertemuan_terakhir_bulan_sebelumnya
                                    FROM pembayaran p
                                    INNER JOIN siswa s ON p.siswa_id = s.siswa_id
                                    INNER JOIN cabang c ON s.cabang_id = c.cabang_id
                                    INNER JOIN siswa_datales sd ON p.siswa_id = sd.siswa_id AND p.datales_id = sd.datales_id
                                    LEFT JOIN datales d ON sd.datales_id = d.datales_id
                                    LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
                                    LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                                    LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
                                    WHERE (p.status_pembayaran IS NULL OR p.status_pembayaran = '')
                                    AND (p.is_archived IS NULL OR p.is_archived = 0)
                                    AND sd.is_history = 0
                                ";

                                $params = [];
                                $types = '';

                                // Filter berdasarkan role
                                if ($current_user_role_id == 2) {
                                    if (!empty($user_cabang_ids)) {
                                        $placeholders = implode(',', array_fill(0, count($user_cabang_ids), '?'));
                                        $query_pending .= " AND s.cabang_id IN ($placeholders)";
                                        foreach ($user_cabang_ids as $cabang_id) {
                                            $params[] = $cabang_id;
                                            $types .= 'i';
                                        }
                                    }
                                }

                                $query_pending .= " 
                                    GROUP BY 
                                        p.siswa_id, 
                                        s.name, 
                                        c.nama_cabang, 
                                        p.bulan_ke, 
                                        p.semester_ke
                                    HAVING 
                                        tanggal_pertemuan_pertama IS NOT NULL 
                                            AND DATE_SUB(tanggal_pertemuan_pertama, INTERVAL 7 DAY) <= CURDATE()
                                    ORDER BY 
                                        c.nama_cabang ASC, 
                                        s.name ASC,
                                        p.bulan_ke ASC
                                ";

                                $stmt_pending = $conn->prepare($query_pending);

                                if (!empty($params)) {
                                    $stmt_pending->bind_param($types, ...$params);
                                }

                                $stmt_pending->execute();
                                $result_pending = $stmt_pending->get_result();
                                
                                if ($result_pending->num_rows > 0) {
                                    $total_pending = 0;
                                    $pending_data = [];
                                    
                                    while ($row = $result_pending->fetch_assoc()) {
                                        $total_pending += $row['total_tagihan'];
                                        $pending_data[] = $row;
                                    }
                                    
                                    // Table
                                    echo '<div class="table-responsive">';
                                    echo '<table class="table align-middle">';
                                    echo '<thead class="table-dark">';
                                    echo '<tr>';
                                    echo '<th>No</th>';
                                    echo '<th>Siswa</th>';
                                    echo '<th>Cabang</th>';
                                    echo '<th>Bulan</th>';
                                    echo '<th>Paket</th>';
                                    echo '<th class="text-end">Jumlah</th>';
                                    echo '<th class="text-center">Status</th>';
                                    echo '</tr>';
                                    echo '</thead>';
                                    echo '<tbody>';
                                    
                                    $no = 1;
                                    foreach ($pending_data as $item) {
                                        echo '<tr>';
                                        echo '<td>' . $no++ . '</td>';
                                        echo '<td><strong>' . htmlspecialchars($item['nama_siswa']) . '</strong></td>';
                                        echo '<td>' . htmlspecialchars($item['nama_cabang']) . '</td>';
                                        echo '<td><span>Bulan ' . $item['bulan_ke'] . '</span></td>'; // ← TAMBAH INI
                                        echo '<td>';
                                        echo '<div class="small">' . htmlspecialchars($item['daftar_paket']) . '</div>';
                                        if ($item['jumlah_paket'] > 1) {
                                            echo '<span class="badge bg-info mt-1">' . $item['jumlah_paket'] . ' Paket</span>';
                                        }
                                        echo '</td>';
                                        echo '<td class="text-end"><strong>Rp' . number_format($item['total_tagihan'], 0, ',', '.') . '</strong></td>';
                                        echo '<td class="text-center">';
                                        echo '<span class="badge bg-danger text-white">BELUM DIBAYAR</span>';
                                        echo '</td>';
                                        echo '</tr>';
                                    }
                                    
                                    echo '</tbody>';
                                    echo '</table>';
                                    echo '</div>';
                                } else {
                                    echo '<div class="alert alert-success text-center py-4">';
                                    echo '<i class="fas fa-check-circle fa-3x mb-3"></i>';
                                    echo '<h5>Tidak Ada Tagihan Pending</h5>';
                                    echo '<p class="text-muted">Semua tagihan sudah dibayar atau belum ada tagihan yang dibuat.</p>';
                                    echo '</div>';
                                }
                                
                                $stmt_pending->close();
                                
                            } catch (Exception $e) {
                                error_log("Error loading pending payments: " . $e->getMessage());
                                echo '<div class="alert alert-danger">';
                                echo '<i class="fas fa-exclamation-triangle me-2"></i>Terjadi kesalahan saat memuat data tagihan.';
                                echo '</div>';
                            }
                            ?>
                        </div>
                        <?php endif; ?>

                        <?php if ($current_user_role_id > 2): ?>
                        <!-- TAGIHAN UNTUK ORANG TUA -->
                        <div class="mb-4">
                            <h4 class="mb-3">
                                <i class="fas fa-file-invoice-dollar text-warning me-2"></i>
                                TAGIHAN YANG HARUS DIBAYAR
                            </h4>
                            
                            <?php
                            try {
                                $current_username = $_SESSION['username'] ?? '';
                                
                                if (empty($current_username)) {
                                    throw new Exception("Username tidak ditemukan di session");
                                }
                                
                                $stmt_siswa = $conn->prepare("SELECT siswa_id, name FROM siswa WHERE username = ?");
                                if (!$stmt_siswa) {
                                    throw new Exception("Prepare siswa error: " . $conn->error);
                                }
                                
                                $stmt_siswa->bind_param("s", $current_username);
                                $stmt_siswa->execute();
                                $result_siswa = $stmt_siswa->get_result();
                                
                                if ($result_siswa->num_rows > 0) {
                                    $siswa_data = $result_siswa->fetch_assoc();
                                    $siswa_id = $siswa_data['siswa_id'];
                                    $nama_siswa = $siswa_data['name'];
                                    $stmt_siswa->close();
                                    
                                    $query_pending = "
                                        SELECT 
                                            p.siswa_id,
                                            s.name as nama_siswa,
                                            c.nama_cabang,
                                            p.bulan_ke,
                                            p.semester_ke,
                                            MIN(p.pembayaran_id) as pembayaran_id_utama,
                                            SUM(p.jumlah_bayar) as total_tagihan,
                                            GROUP_CONCAT(
                                                DISTINCT CONCAT(jl.name, ' - ', tl.name, ' - ', jt.nama_jenistingkat)
                                                ORDER BY jl.name 
                                                SEPARATOR ', '
                                            ) as daftar_paket,
                                            COUNT(DISTINCT p.datales_id) as jumlah_paket,
                                            (SELECT MIN(jp2.tanggal_pertemuan) 
                                            FROM jadwal_pertemuan jp2 
                                            WHERE jp2.siswa_id = p.siswa_id 
                                            AND jp2.bulan_ke = p.bulan_ke
                                            AND jp2.semester_ke = p.semester_ke
                                            AND jp2.is_history = 0
                                            ) as tanggal_pertemuan_pertama,
                                            (SELECT MAX(jp3.tanggal_pertemuan) 
                                            FROM jadwal_pertemuan jp3 
                                            WHERE jp3.siswa_id = p.siswa_id 
                                            AND jp3.bulan_ke = (p.bulan_ke - 1)
                                            AND jp3.semester_ke = p.semester_ke
                                            AND jp3.is_history = 0
                                            ) as tanggal_pertemuan_terakhir_bulan_sebelumnya
                                        FROM pembayaran p
                                        INNER JOIN siswa s ON p.siswa_id = s.siswa_id
                                        INNER JOIN cabang c ON s.cabang_id = c.cabang_id
                                        INNER JOIN siswa_datales sd ON p.siswa_id = sd.siswa_id AND p.datales_id = sd.datales_id
                                        LEFT JOIN datales d ON sd.datales_id = d.datales_id
                                        LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
                                        LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                                        LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
                                        WHERE (p.status_pembayaran IS NULL OR p.status_pembayaran = '')
                                        AND (p.is_archived IS NULL OR p.is_archived = 0)
                                        AND sd.is_history = 0
                                        AND s.siswa_id = ?
                                        GROUP BY 
                                            p.siswa_id, 
                                            s.name, 
                                            c.nama_cabang, 
                                            p.bulan_ke, 
                                            p.semester_ke
                                        HAVING 
                                            tanggal_pertemuan_pertama IS NOT NULL 
                                                AND DATE_SUB(tanggal_pertemuan_pertama, INTERVAL 7 DAY) <= CURDATE()
                                        ORDER BY p.bulan_ke ASC
                                    ";

                                    $stmt_tag = $conn->prepare($query_pending);
                                    if (!$stmt_tag) {
                                        throw new Exception("Prepare tagihan error: " . $conn->error);
                                    }
                                    
                                    $stmt_tag->bind_param("i", $siswa_id);
                                    
                                    if (!$stmt_tag->execute()) {
                                        throw new Exception("Execute error: " . $stmt_tag->error);
                                    }
                                    
                                    $result_tag = $stmt_tag->get_result();
                                    
                                    if ($result_tag->num_rows > 0) {
                                        $total_tagihan = 0;
                                        $tagihan_data = [];
                                        
                                        while ($row = $result_tag->fetch_assoc()) {
                                            $total_tagihan += $row['total_tagihan'];
                                            $tagihan_data[] = $row;
                                        }
                                        
                                        // Table
                                        echo '<div class="table-responsive">';
                                        echo '<table class="table table-hover table-striped align-middle">';
                                        echo '<thead class="table-dark">';
                                        echo '<tr>';
                                        echo '<th>No</th>';
                                        echo '<th>Bulan</th>';
                                        echo '<th>Paket Les</th>';
                                        echo '<th>Cabang</th>';
                                        echo '<th class="text-end">Jumlah</th>';
                                        echo '<th class="text-center">Status</th>';
                                        echo '<th class="text-center">Action</th>';
                                        echo '</tr>';
                                        echo '</thead>';
                                        echo '<tbody>';

                                        $no = 1;
                                        foreach ($tagihan_data as $item) {
                                            echo '<tr>';
                                            echo '<td>' . $no++ . '</td>';
                                            echo '<td><span>Bulan ' . $item['bulan_ke'] . '</span></td>'; // ← TAMBAH INI
                                            echo '<td>';
                                            echo '<div class="small">' . htmlspecialchars($item['daftar_paket']) . '</div>';
                                            if ($item['jumlah_paket'] > 1) {
                                                echo '<span class="badge bg-info mt-1">' . $item['jumlah_paket'] . ' Paket</span>';
                                            }
                                            echo '</td>';
                                            echo '<td>' . htmlspecialchars($item['nama_cabang']) . '</td>';
                                            echo '<td class="text-end"><strong>Rp' . number_format($item['total_tagihan'], 0, ',', '.') . '</strong></td>';
                                            echo '<td class="text-center">';
                                            echo '<span class="badge bg-danger text-white">BELUM DIBAYAR</span>';
                                            echo '</td>';
                                            echo '<td class="text-center">';
                                            echo '<a href="pembayaran.php?pembayaran_id=' . $item['pembayaran_id_utama'] . '" class="btn btn-warning btn-sm">';
                                            echo '<i class="fas fa-upload me-1"></i>Upload Bukti';
                                            echo '</a>';
                                            echo '</td>';
                                            echo '</tr>';
                                        }

                                        echo '</tbody>';
                                        echo '</table>';
                                        echo '</div>';
                                        } else {
                                            echo '<div class="alert alert-success text-center py-4">';
                                            echo '<i class="fas fa-check-circle fa-3x mb-3"></i>';
                                            echo '<h5>Tidak Ada Tagihan</h5>';
                                            echo '<p class="text-muted">Semua tagihan sudah lunas atau belum ada tagihan baru.</p>';
                                            echo '</div>';
                                        }
                                    
                                    $stmt_tag->close();
                                    
                                } else {
                                    echo '<div class="alert alert-info text-center py-4">';
                                    echo '<i class="fas fa-info-circle fa-3x mb-3"></i>';
                                    echo '<h5>Data Siswa Tidak Ditemukan</h5>';
                                    echo '<p class="text-muted">Akun Anda belum terhubung dengan data siswa.</p>';
                                    echo '</div>';
                                    $stmt_siswa->close();
                                }
                                
                            } catch (Exception $e) {
                                error_log("Error loading parent payments: " . $e->getMessage());
                                echo '<div class="alert alert-danger">';
                                echo '<i class="fas fa-exclamation-triangle me-2"></i>Terjadi kesalahan saat memuat data tagihan.';
                                echo '<br><small>Error: ' . htmlspecialchars($e->getMessage()) . '</small>';
                                echo '</div>';
                            }
                            ?>
                        </div>

                        <div class="mb-4 mt-5">
                            <h4 class="mb-3">
                                <i class="fas fa-calendar-check text-info me-2"></i>
                                Riwayat Absensi
                            </h4>

                            <?php
                            try {
                                $query_absensi = "
                                    SELECT
                                        tanggal_pertemuan,
                                        tanggal_reschedule,
                                        jam_mulai,
                                        jam_selesai,
                                        pertemuan_ke
                                    FROM jadwal_pertemuan
                                    WHERE siswa_id = ?
                                    ORDER BY tanggal_pertemuan DESC
                                ";

                                $stmt_abs = $conn->prepare($query_absensi);
                                $stmt_abs->bind_param("i", $siswa_id);
                                $stmt_abs->execute();
                                $result_abs = $stmt_abs->get_result();

                                if ($result_abs->num_rows > 0) {
                                    echo '<div class="table-responsive">';
                                    echo '<table class="table table-bordered table-hover">';
                                    echo '<thead class="table-light">
                                            <tr>
                                                <th>Pertemuan</th>
                                                <th>Tanggal</th>
                                                <th>Jam</th>
                                                <th>Reschedule</th>
                                            </tr>
                                        </thead>
                                        <tbody>';

                                    while ($row = $result_abs->fetch_assoc()) {
                                        echo '<tr>';
                                        echo '<td class="text-center">Ke-' . $row['pertemuan_ke'] . '</td>';
                                        echo '<td>' . date('d M Y', strtotime($row['tanggal_pertemuan'])) . '</td>';
                                        echo '<td>' . substr($row['jam_mulai'], 0, 5) . ' - ' . substr($row['jam_selesai'], 0, 5) . '</td>';

                                        if (!empty($row['tanggal_reschedule'])) {
                                            echo '<td>' . date('d M Y', strtotime($row['tanggal_reschedule'])) . '</td>';
                                        } else {
                                            echo '<td class="text-muted">-</td>';
                                        }

                                        echo '</tr>';
                                    }

                                    echo '</tbody></table></div>';


                                } else {
                                    echo '<div class="alert alert-info text-center">
                                            Belum ada data absensi.
                                        </div>';
                                }

                                $stmt_abs->close();

                            } catch (Exception $e) {
                                echo '<div class="alert alert-danger">';
    echo 'Error Absensi: ' . $e->getMessage();
    echo '</div>';
                            }
                            ?>
                        </div>

                        <?php endif; ?>
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
        
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script src="js/datatables-simple-demo.js"></script>

        <script>
            setTimeout(function() {
                const alerts = document.querySelectorAll('.alert:not(.alert-success)');
                alerts.forEach(function(alert) {
                    alert.style.transition = 'opacity 0.5s';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.style.display = 'none', 500);
                });
            }, 5000);

            setTimeout(function() {
                const welcomeAlert = document.querySelector('.alert-success');
                if (welcomeAlert) {
                    welcomeAlert.style.transition = 'opacity 0.5s';
                    welcomeAlert.style.opacity = '0';
                    setTimeout(() => welcomeAlert.style.display = 'none', 500);
                }
            }, 10000);

            window.addEventListener('pageshow', function(event) {
                if (event.persisted) {
                    window.location.reload();
                }
            });

            function updateTime() {
                const now = new Date();
                const timeString = now.toLocaleString('id-ID', {
                    timeZone: 'Asia/Jakarta',
                    year: 'numeric',
                    month: 'short', 
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
                
                const timeElement = document.querySelector('.current-time');
                if (timeElement) {
                    timeElement.textContent = timeString;
                }
            }

            setInterval(updateTime, 1000);
            updateTime();
        </script>
    </body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>