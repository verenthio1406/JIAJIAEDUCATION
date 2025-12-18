<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'check_login.php';

require 'config.php';
require_once 'generate_jadwal_semester.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = getUserRoleId();

$message = '';
$message_type = '';

// Helper function untuk increment tahun ajaran
function incrementTahunAjaran($current_tahun) {
    list($year1, $year2) = explode('/', $current_tahun);
    return ($year1 + 1) . '/' . ($year2 + 1);
}

// ========================================
// HANDLE ACTION: LANJUT SEMESTER
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'lanjut_semester') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: kelola_semester.php?error=invalid_token");
        exit();
    }
    
    $siswa_id = (int)$_POST['siswa_id'];
    $datales_id = (int)$_POST['datales_id'];
    
    try {
        // Step 1: Ambil data current semester
        $stmt = $conn->prepare("
            SELECT semester_ke, tahun_ajaran, slot_id
            FROM siswa_datales 
            WHERE siswa_id = ? AND datales_id = ? AND is_history = FALSE AND status = 'aktif'
        ");
        $stmt->bind_param("ii", $siswa_id, $datales_id);
        $stmt->execute();
        $current_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$current_data) {
            throw new Exception("Data semester tidak ditemukan");
        }
        
        // Step 2: Cari tanggal terakhir pertemuan di bulan 6
        $stmt_last = $conn->prepare("
            SELECT MAX(tanggal_pertemuan) as tanggal_terakhir
            FROM jadwal_pertemuan
            WHERE siswa_id = ? 
              AND datales_id = ? 
              AND semester_ke = ?
              AND bulan_ke = 6
              AND is_history = 0
        ");
        $stmt_last->bind_param("iii", 
            $siswa_id, 
            $datales_id, 
            $current_data['semester_ke']
        );
        $stmt_last->execute();
        $result_last = $stmt_last->get_result();
        $last_date_data = $result_last->fetch_assoc();
        $stmt_last->close();
        
        // Hitung tanggal mulai semester baru = 1 minggu setelah pertemuan terakhir
        if ($last_date_data && $last_date_data['tanggal_terakhir']) {
            $last_date = new DateTime($last_date_data['tanggal_terakhir']);
            $last_date->modify('+1 week');
            $new_tanggal_mulai = $last_date->format('Y-m-d');
        } else {
            $new_tanggal_mulai = date('Y-m-d');
        }
        
        // Step 3: Hitung semester dan tahun ajaran baru
        $new_semester = $current_data['semester_ke'] + 1;
        $new_tahun_ajaran = incrementTahunAjaran($current_data['tahun_ajaran']);
        
        // ✅ Step 4: Archive jadwal pertemuan semester lama
        $stmt_archive_jadwal = $conn->prepare("
            UPDATE jadwal_pertemuan 
            SET is_history = 1
            WHERE siswa_id = ? 
              AND datales_id = ? 
              AND semester_ke = ?
              AND is_history = 0
        ");
        $stmt_archive_jadwal->bind_param("iii", 
            $siswa_id, 
            $datales_id, 
            $current_data['semester_ke']
        );
        $stmt_archive_jadwal->execute();
        $stmt_archive_jadwal->close();
        
        // ✅ Step 5: Archive invoice semester lama (NEW!)
        $stmt_archive_invoice = $conn->prepare("
            UPDATE pembayaran 
            SET is_archived = 1
            WHERE siswa_id = ? 
              AND datales_id = ? 
              AND semester_ke = ?
              AND (is_archived IS NULL OR is_archived = 0)
        ");
        $stmt_archive_invoice->bind_param("iii", 
            $siswa_id, 
            $datales_id, 
            $current_data['semester_ke']
        );
        $stmt_archive_invoice->execute();
        $stmt_archive_invoice->close();
        
        // Step 6: Update siswa_datales ke semester berikutnya
        $stmt = $conn->prepare("
            UPDATE siswa_datales 
            SET semester_ke = ?,
                tahun_ajaran = ?,
                bulan_aktif = 1,
                tanggal_mulai_semester = ?
            WHERE siswa_id = ? AND datales_id = ? AND is_history = FALSE
        ");
        $stmt->bind_param("issii", $new_semester, $new_tahun_ajaran, $new_tanggal_mulai, $siswa_id, $datales_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Gagal update semester");
        }
        $stmt->close();
        
        // Step 7: Generate jadwal semester baru + invoice baru
        $generate_result = generateJadwalSemester(
            $conn, 
            $siswa_id, 
            $datales_id,
            $current_data['slot_id'],
            $new_semester,
            $new_tahun_ajaran,
            $new_tanggal_mulai
        );
        
        if (!$generate_result['success']) {
            throw new Exception("Gagal generate jadwal: " . $generate_result['message']);
        }
        
        header("Location: kelola_semester.php?success=lanjut_semester");
        exit();
        
    } catch (Exception $e) {
        error_log("Error lanjut semester: " . $e->getMessage());
        header("Location: kelola_semester.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// ========================================
// HANDLE ACTION: NAIK TINGKAT
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'naik_tingkat') {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: kelola_semester.php?error=invalid_token");
        exit();
    }
    
    $siswa_id = (int)$_POST['siswa_id'];
    $old_datales_id = (int)$_POST['old_datales_id'];
    $new_datales_id = (int)$_POST['new_datales_id'];
    $new_slot_id = (int)$_POST['new_slot_id'];
    
    try {
        // Step 1: Tandai record lama sebagai history
        $catatan_history = "Naik tingkat ke paket baru (datales_id: $new_datales_id)";
        $stmt = $conn->prepare("
            UPDATE siswa_datales 
            SET is_history = 1,
                status = 'lulus',
                tanggal_archived = CURDATE(),
                archived_by = ?,
                catatan_history = ?
            WHERE siswa_id = ? AND datales_id = ? AND is_history = 0
        ");
        $stmt->bind_param("isii", $current_user_id, $catatan_history, $siswa_id, $old_datales_id);
        
        if (!$stmt->execute()) {
            throw new Exception("Gagal update status history");
        }
        $stmt->close();
        
        // Step 2: Hitung tahun ajaran baru
        $current_month = (int)date('n');
        $current_year = (int)date('Y');
        
        if ($current_month >= 7) {
            $tahun_ajaran_baru = $current_year . '/' . ($current_year + 1);
        } else {
            $tahun_ajaran_baru = ($current_year - 1) . '/' . $current_year;
        }
        
        $tanggal_mulai_baru = date('Y-m-d');
        
        // Step 3: Insert record baru dengan paket/tingkat baru
        $stmt = $conn->prepare("
            INSERT INTO siswa_datales 
            (siswa_id, datales_id, slot_id, semester_ke, tahun_ajaran, bulan_aktif, tanggal_mulai_semester, status, is_history)
            VALUES (?, ?, ?, 1, ?, 1, ?, 'aktif', FALSE)
        ");
        $stmt->bind_param("iiiss", $siswa_id, $new_datales_id, $new_slot_id, $tahun_ajaran_baru, $tanggal_mulai_baru);
        
        if (!$stmt->execute()) {
            throw new Exception("Gagal insert paket baru");
        }
        $stmt->close();
        
        // Step 4: Generate jadwal semester pertama di tingkat baru
        $generate_result = generateJadwalSemester(
            $conn, 
            $siswa_id, 
            $new_datales_id,
            $new_slot_id,        
            1,                   
            $tahun_ajaran_baru,  
            $tanggal_mulai_baru  
        );
        
        if (!$generate_result['success']) {
            throw new Exception("Gagal generate jadwal: " . $generate_result['message']);
        }
        
        header("Location: kelola_semester.php?success=naik_tingkat");
        exit();
        
    } catch (Exception $e) {
        error_log("Error naik tingkat: " . $e->getMessage());
        header("Location: kelola_semester.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}

// Handle success/error messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'lanjut_semester':
            $message = 'Berhasil! Siswa sudah dilanjutkan ke semester berikutnya.';
            $message_type = 'success';
            break;
        case 'naik_tingkat':
            $message = 'Berhasil! Siswa sudah naik ke tingkat yang lebih tinggi.';
            $message_type = 'success';
            break;
    }
}

if (isset($_GET['error'])) {
    $message = 'Error: ' . htmlspecialchars($_GET['error']);
    $message_type = 'danger';
}

$siswa_selesai = [];
try {
    $query = "
        SELECT 
            s.siswa_id,
            s.name as nama_siswa,
            sd.datales_id,
            sd.semester_ke,
            sd.tahun_ajaran,
            sd.bulan_aktif,
            CONCAT(jl.name, ' - ', tl.name, ' - ', jt.nama_jenistingkat) as nama_paket,
            c.nama_cabang,
            tl.jumlahpertemuan,
            tl.tipeles_id,              -- ✅ PAST
            jt.jenistingkat_id,         -- ✅ TAMBAH INI
            -- Hitung progress pertemuan di bulan 6
            (SELECT COUNT(*) 
            FROM jadwal_pertemuan jp 
            WHERE jp.siswa_id = s.siswa_id 
            AND jp.datales_id = sd.datales_id 
            AND jp.bulan_ke = 6
            AND jp.status_pertemuan = 'hadir'
            ) as pertemuan_hadir,
            (SELECT COUNT(*) 
            FROM jadwal_pertemuan jp 
            WHERE jp.siswa_id = s.siswa_id 
            AND jp.datales_id = sd.datales_id 
            AND jp.bulan_ke = 6
            ) as total_pertemuan_bulan6
        FROM siswa s
        INNER JOIN siswa_datales sd ON s.siswa_id = sd.siswa_id
        INNER JOIN datales d ON sd.datales_id = d.datales_id
        INNER JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
        INNER JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        INNER JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        INNER JOIN cabang c ON s.cabang_id = c.cabang_id
        WHERE s.status = 'aktif'
        AND sd.status = 'aktif'
        AND sd.is_history = 0
        AND sd.bulan_aktif = 6
        HAVING total_pertemuan_bulan6 > 0 
        AND pertemuan_hadir >= (total_pertemuan_bulan6 * 0.8)
        ORDER BY s.name ASC
    ";
    
    $result = $conn->query($query);
    
    while ($row = $result->fetch_assoc()) {
        $siswa_selesai[] = $row;
    }
    
} catch (Exception $e) {
    error_log("Error loading data: " . $e->getMessage());
    $message = 'Error loading data: ' . $e->getMessage();
    $message_type = 'danger';
}

$paket_list = [];
try {
    $query_paket = "
        SELECT 
            d.datales_id,
            jl.jenisles_id,
            tl.tipeles_id,
            jt.jenistingkat_id,
            jl.name as jenisles_name,
            tl.name as tipeles_name,
            jt.nama_jenistingkat,
            CONCAT(jl.name, ' - ', tl.name, ' - ', jt.nama_jenistingkat) as nama_paket
        FROM datales d
        INNER JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
        INNER JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        INNER JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        ORDER BY jl.name, tl.name, jt.nama_jenistingkat
    ";
    
    $stmt = $conn->query($query_paket);
    
    if (!$stmt) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    while ($row = $stmt->fetch_assoc()) {
        $paket_list[] = $row;
    }
    
    error_log("Total paket loaded: " . count($paket_list));
    
} catch (Exception $e) {
    error_log("Error loading paket: " . $e->getMessage());
}

$slot_list = [];
try {
    $stmt = $conn->query("
        SELECT 
            js.slot_id,
            js.hari,
            js.jam_mulai,
            js.jam_selesai,
            js.jenistingkat_id,
            g.guru_id,
            g.nama_guru,
            c.cabang_id,
            c.nama_cabang,
            jt.jenistingkat_id,
            jl.jenisles_id,
            tl.tipeles_id
        FROM jadwal_slot js
        INNER JOIN cabangGuru cg ON js.cabangguruID = cg.id
        INNER JOIN guru g ON cg.guru_id = g.guru_id
        INNER JOIN cabang c ON cg.cabang_id = c.cabang_id
        LEFT JOIN jenistingkat jt ON js.jenistingkat_id = jt.jenistingkat_id
        LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        WHERE js.status = 'aktif'
        ORDER BY c.nama_cabang, js.hari, js.jam_mulai
    ");
    
    while ($row = $stmt->fetch_assoc()) {
        $slot_list[] = $row;
    }
} catch (Exception $e) {
    error_log("Error loading slot: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Kelola Semester - Jia Jia Education</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
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
                        <a class="nav-link active" href="kelola_semester.php">
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
                    <?php echo htmlspecialchars($current_user_name); ?>
                </div>
            </nav>
        </div>
        
        <div id="layoutSidenav_content">
            <main>
                <div class="container-fluid px-4">
                    <h1 class="mt-4">Kelola Semester Siswa</h1>
                    <br>

                    <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <i class="fas fa-graduation-cap me-2"></i>
                            Siswa yang Selesai Bulan 6
                            <?php if (count($siswa_selesai) > 0): ?>
                            <span class="badge bg-warning text-dark ms-2"><?php echo count($siswa_selesai); ?> siswa</span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($siswa_selesai)): ?>
                            <div class="alert alert-success text-center">
                                <i class="fas fa-check-circle me-2"></i>
                                Tidak ada siswa yang perlu perpanjangan semester saat ini.
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="50">No</th>
                                            <th>Nama Siswa</th>
                                            <th>Paket Kelas</th>
                                            <th>Cabang</th>
                                            <th>Semester Saat Ini</th>
                                            <th>Progress</th>
                                            <th width="250">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($siswa_selesai as $index => $siswa): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($siswa['nama_siswa']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($siswa['nama_paket']); ?></td>
                                            <td><?php echo htmlspecialchars($siswa['nama_cabang']); ?></td>
                                            <td>
                                                Semester <?php echo $siswa['semester_ke']; ?><br>
                                                <small class="text-muted"><?php echo $siswa['tahun_ajaran']; ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <?php echo $siswa['pertemuan_hadir']; ?>/<?php echo $siswa['total_pertemuan_bulan6']; ?> pertemuan
                                                </span>
                                            </td>
                                            <td>
                                                <!-- Button Lanjut Semester -->
                                                <button class="btn btn-sm btn-primary mb-1" 
                                                        onclick="lanjutSemester(<?php echo $siswa['siswa_id']; ?>, <?php echo $siswa['datales_id']; ?>, '<?php echo htmlspecialchars($siswa['nama_siswa']); ?>', <?php echo $siswa['semester_ke']; ?>)">
                                                    <i class="fas fa-forward me-1"></i>Lanjut Semester
                                                </button>
                                                
                                                <!-- Button Naik Tingkat -->
                                                <button class="btn btn-sm btn-success mb-1" 
                                                        onclick="openNaikTingkatModal(
                                                            <?php echo $siswa['siswa_id']; ?>, 
                                                            <?php echo $siswa['datales_id']; ?>, 
                                                            '<?php echo htmlspecialchars($siswa['nama_siswa']); ?>', 
                                                            '<?php echo htmlspecialchars($siswa['nama_paket']); ?>',
                                                            '<?php echo htmlspecialchars($siswa['nama_cabang']); ?>',
                                                            <?php echo $siswa['tipeles_id']; ?>,
                                                            <?php echo $siswa['jenistingkat_id']; ?>
                                                        )">
                                                    <i class="fas fa-arrow-up me-1"></i>Naik Tingkat
                                                </button>
                                                
                                                <!-- Button Lihat Detail -->
                                                <a href="detail_absensi.php?siswa_id=<?php echo $siswa['siswa_id']; ?>&paket=<?php echo $siswa['datales_id']; ?>" 
                                                   class="btn btn-sm btn-outline-secondary mb-1">
                                                    <i class="fas fa-eye me-1"></i>Detail
                                                </a>
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

    <!-- Form Hidden untuk Lanjut Semester -->
    <form method="POST" id="lanjutSemesterForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="lanjut_semester">
        <input type="hidden" name="siswa_id" id="lanjut_siswa_id">
        <input type="hidden" name="datales_id" id="lanjut_datales_id">
    </form>

    <!-- Modal Naik Tingkat -->
    <div class="modal fade" id="naikTingkatModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-arrow-up me-2"></i>
                            Naik Tingkat
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="naik_tingkat">
                        <input type="hidden" name="siswa_id" id="naik_siswa_id">
                        <input type="hidden" name="old_datales_id" id="naik_old_datales_id">
                        <input type="hidden" id="current_cabang_siswa">

                        <div class="alert alert-info">
                            <strong>Siswa:</strong> <span id="naik_nama_siswa"></span><br>
                            <strong>Paket Saat Ini:</strong> <span id="naik_paket_lama"></span><br>
                            <strong>Cabang:</strong> <span id="naik_cabang_siswa"></span>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Pilih Paket Baru (Tingkat Lebih Tinggi) *</label>
                            <select class="form-select" name="new_datales_id" id="select_new_paket" required>
                                <option value="">-- Pilih Paket --</option>
                                <?php foreach ($paket_list as $paket): ?>
                                <option value="<?php echo $paket['datales_id']; ?>"
                                        data-jenisles="<?php echo $paket['jenisles_id']; ?>"
                                        data-tipeles="<?php echo $paket['tipeles_id']; ?>"
                                        data-jenistingkat="<?php echo $paket['jenistingkat_id']; ?>">
                                    <?php echo htmlspecialchars($paket['nama_paket']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Hanya paket dengan jenis & tipe yang sama akan ditampilkan</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Pilih Jadwal Slot Baru *</label>
                            <select class="form-select" name="new_slot_id" id="select_new_slot" required>
                                <option value="">-- Pilih paket dulu --</option>
                                <?php foreach ($slot_list as $slot): ?>
                                <option value="<?php echo $slot['slot_id']; ?>"
                                        data-cabang="<?php echo $slot['cabang_id']; ?>"
                                        data-cabang-name="<?php echo htmlspecialchars($slot['nama_cabang']); ?>"
                                        data-jenistingkat="<?php echo $slot['jenistingkat_id']; ?>"
                                        data-guru="<?php echo htmlspecialchars($slot['nama_guru']); ?>"
                                        style="display:none;">
                                    <?php echo htmlspecialchars($slot['nama_cabang']); ?> - 
                                    <?php echo $slot['hari']; ?> 
                                    <?php echo substr($slot['jam_mulai'], 0, 5); ?>-<?php echo substr($slot['jam_selesai'], 0, 5); ?> 
                                    (<?php echo htmlspecialchars($slot['nama_guru']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Hanya slot dari cabang & paket yang dipilih akan ditampilkan</small>
                        </div>

                        <div class="alert alert-warning">
                            <small>
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Dengan naik tingkat:
                                <ul class="mb-0 mt-2">
                                    <li>Record lama akan dipindah ke history</li>
                                    <li>Semester direset ke 1</li>
                                    <li>Jadwal baru akan digenerate otomatis</li>
                                </ul>
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-1"></i>Naik Tingkat
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/scripts.js"></script>
    
    <script>
    // Store current paket info globally
    let currentPaketInfo = {
        datales_id: null,
        tipeles_id: null,
        jenistingkat_id: null,
        cabang_name: null
    };

    function lanjutSemester(siswa_id, datales_id, nama_siswa, semester_ke) {
        const next_semester = semester_ke + 1;
        
        if (confirm(`Lanjutkan ${nama_siswa} ke Semester ${next_semester}?\n\nSistem akan:\n- Update semester menjadi Semester ${next_semester}\n- Reset bulan ke Bulan 1\n- Generate jadwal 6 bulan baru`)) {
            document.getElementById('lanjut_siswa_id').value = siswa_id;
            document.getElementById('lanjut_datales_id').value = datales_id;
            document.getElementById('lanjutSemesterForm').submit();
        }
    }

    function openNaikTingkatModal(siswa_id, old_datales_id, nama_siswa, paket_lama, cabang_name, tipeles_id, jenistingkat_id) {
    
    // Set basic info
    document.getElementById('naik_siswa_id').value = siswa_id;
    document.getElementById('naik_old_datales_id').value = old_datales_id;
    document.getElementById('naik_nama_siswa').textContent = nama_siswa;
    document.getElementById('naik_paket_lama').textContent = paket_lama;
    document.getElementById('naik_cabang_siswa').textContent = cabang_name;
    document.getElementById('current_cabang_siswa').value = cabang_name;
    
    // ✅ Set current paket info dari parameter
    currentPaketInfo.datales_id = old_datales_id;
    currentPaketInfo.tipeles_id = tipeles_id;
    currentPaketInfo.jenistingkat_id = jenistingkat_id;
    currentPaketInfo.cabang_name = cabang_name;
    
    console.log('Current Paket Info:', currentPaketInfo);
        
        // Get paket select
        const paketSelect = document.getElementById('select_new_paket');
        const allPaketOptions = Array.from(paketSelect.options);
        
        // ✅ Filter paket dropdown by TIPE LES yang sama, exclude tingkat sekarang
        let hasVisibleOptions = false;
        allPaketOptions.forEach(option => {
            if (option.value === '') {
                option.style.display = '';
                return;
            }
            
            const optTipeles = option.getAttribute('data-tipeles');
            const optJenistingkat = option.getAttribute('data-jenistingkat');
            
            console.log('Checking:', option.textContent.trim(), '| tipeles:', optTipeles, '| jenistingkat:', optJenistingkat);
            
            // Tampilkan hanya jika: TIPE SAMA + BUKAN tingkat sekarang
            if (optTipeles == currentPaketInfo.tipeles_id && 
                optJenistingkat != currentPaketInfo.jenistingkat_id) {
                option.style.display = '';
                hasVisibleOptions = true;
                console.log('✅ SHOW:', option.textContent.trim());
            } else {
                option.style.display = 'none';
                console.log('❌ HIDE:', option.textContent.trim(), '(tipeles match:', optTipeles == currentPaketInfo.tipeles_id, ', jenistingkat different:', optJenistingkat != currentPaketInfo.jenistingkat_id, ')');
            }
        });
        
        // Update placeholder jika tidak ada paket lain
        if (!hasVisibleOptions) {
            paketSelect.options[0].textContent = '-- Tidak ada tingkat lain untuk tipe les ini --';
            paketSelect.options[0].style.display = '';
            console.log('⚠️ No options available');
        } else {
            paketSelect.options[0].textContent = '-- Pilih Paket --';
            console.log('✅ Options available');
        }
        
        // Reset slot dropdown
        document.getElementById('select_new_slot').value = '';
        filterSlotOptions();
        
        // Show modal
        new bootstrap.Modal(document.getElementById('naikTingkatModal')).show();
    }

    // Event listener: filter slot saat paket dipilih
    document.getElementById('select_new_paket').addEventListener('change', function() {
        filterSlotOptions();
    });

    function filterSlotOptions() {
        const paketSelect = document.getElementById('select_new_paket');
        const slotSelect = document.getElementById('select_new_slot');
        const selectedPaket = paketSelect.value;
        
        // Reset slot
        slotSelect.value = '';
        
        if (!selectedPaket) {
            // Hide all slots
            Array.from(slotSelect.options).forEach(option => {
                if (option.value === '') {
                    option.textContent = '-- Pilih paket dulu --';
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
            return;
        }
        
        // Get selected paket's jenistingkat_id
        const selectedOption = paketSelect.options[paketSelect.selectedIndex];
        const selectedJenistingkat = selectedOption.getAttribute('data-jenistingkat');
        const currentCabang = document.getElementById('current_cabang_siswa').value;
        
        // Filter slot: hanya tampilkan yang sesuai jenistingkat & cabang
        let hasVisibleOptions = false;
        Array.from(slotSelect.options).forEach(option => {
            if (option.value === '') {
                option.textContent = '-- Pilih Slot --';
                option.style.display = '';
                return;
            }
            
            const slotJenistingkat = option.getAttribute('data-jenistingkat');
            const slotCabang = option.getAttribute('data-cabang-name');
            
            // Tampilkan hanya jika: jenistingkat sama & cabang sama
            if (slotJenistingkat == selectedJenistingkat && slotCabang == currentCabang) {
                option.style.display = '';
                hasVisibleOptions = true;
            } else {
                option.style.display = 'none';
            }
        });
        
        // Update placeholder jika tidak ada slot
        if (!hasVisibleOptions) {
            slotSelect.options[0].textContent = '-- Tidak ada slot tersedia untuk paket & cabang ini --';
        }
    }

    // Auto hide alerts
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
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