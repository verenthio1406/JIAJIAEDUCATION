<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'check_login.php';
require 'config.php';
require_once 'generate_jadwal_semester.php';
date_default_timezone_set('Asia/Jakarta');

// Generate CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = $_SESSION['role_id'];

// Proteksi akses
if ($current_user_role_id > 2) {
    header("Location: index.php?error=unauthorized");
    exit();
}

// Get siswa_id from URL
$siswa_id = isset($_GET['siswa_id']) ? (int)$_GET['siswa_id'] : 0;

if ($siswa_id === 0) {
    header("Location: absensi.php?error=invalid_siswa");
    exit();
}

// Get filter paket from URL
$filter_paket = isset($_GET['paket']) ? (int)$_GET['paket'] : 0;

// Get filter bulan from URL (default = bulan aktif)
$filter_bulan = isset($_GET['bulan']) ? (int)$_GET['bulan'] : 0;

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: detail_absensi.php?siswa_id=$siswa_id&error=invalid_token");
        exit();
    }

    // ✅ TAMBAH ACTION INI: Selesaikan Semester
    if ($_POST['action'] === 'selesaikan_semester') {
        $siswa_id_post = (int)$_POST['siswa_id'];
        $datales_id_post = (int)$_POST['datales_id'];
        
        try {
            // Set bulan_aktif = 6 (supaya muncul di kelola_semester)
            $stmt = $conn->prepare("
                UPDATE siswa_datales 
                SET bulan_aktif = 6 
                WHERE siswa_id = ? AND datales_id = ? AND is_history = 0
            ");
            $stmt->bind_param("ii", $siswa_id_post, $datales_id_post);
            
            if ($stmt->execute()) {
                $stmt->close();
                header("Location: detail_absensi.php?siswa_id=$siswa_id&paket=$datales_id_post&bulan=6&success=semester_selesai");
                exit();
            }
            
        } catch (Exception $e) {
            error_log("Error selesaikan semester: " . $e->getMessage());
            header("Location: detail_absensi.php?siswa_id=$siswa_id&error=selesai_semester_failed");
            exit();
        }
    }
    
    // ACTION: Update Status
    if ($_POST['action'] === 'update_status') {
        $jadwal_id = (int)$_POST['jadwal_id'];
        $status_pertemuan = $_POST['status_pertemuan'];
        $catatan = isset($_POST['catatan']) ? trim($_POST['catatan']) : '';
        
        if (!in_array($status_pertemuan, ['hadir', 'tidak_hadir'])) {
            header("Location: detail_absensi.php?siswa_id=$siswa_id&error=invalid_status");
            exit();
        }
        
        try {
            // Update status
            $stmt = $conn->prepare("UPDATE jadwal_pertemuan SET status_pertemuan = ?, catatan = ? WHERE jadwal_id = ? AND siswa_id = ?");
            $stmt->bind_param("ssii", $status_pertemuan, $catatan, $jadwal_id, $siswa_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $stmt->close();
                
                $redirect_url = "detail_absensi.php?siswa_id=$siswa_id&success=status_updated";
                if ($filter_paket > 0) $redirect_url .= "&paket=$filter_paket";
                if ($filter_bulan > 0) $redirect_url .= "&bulan=$filter_bulan";
                
                header("Location: $redirect_url");
                exit();
            }
            
            $stmt->close();
            header("Location: detail_absensi.php?siswa_id=$siswa_id&error=no_changes");
            exit();
            
        } catch (Exception $e) {
            error_log("Exception: " . $e->getMessage());
            header("Location: detail_absensi.php?siswa_id=$siswa_id&error=exception");
            exit();
        }
    }

    // ACTION: Create Invoice
if ($_POST['action'] === 'create_invoice') {
    $siswa_id_post = (int)$_POST['siswa_id'];
    $datales_id_post = (int)$_POST['datales_id'];
    $bulan_ke_post = (int)$_POST['bulan_ke'];
    
    try {
        // Get harga dari datales
        $stmt_info = $conn->prepare("
            SELECT 
                sd.semester_ke, 
                sd.tahun_ajaran, 
                sd.tanggal_mulai_semester,
                d.harga
            FROM siswa_datales sd
            INNER JOIN datales d ON sd.datales_id = d.datales_id
            WHERE sd.siswa_id = ? 
              AND sd.datales_id = ? 
              AND sd.status = 'aktif' 
              AND sd.is_history = 0
        ");
        $stmt_info->bind_param("ii", $siswa_id_post, $datales_id_post);
        $stmt_info->execute();
        $info = $stmt_info->get_result()->fetch_assoc();
        $stmt_info->close();
        
        if (!$info) {
            throw new Exception("Data paket tidak ditemukan");
        }
        
        $jumlah_bayar = $info['harga'];
        
        // Hitung periode bulan
        $tanggal_mulai_semester = $info['tanggal_mulai_semester'] ?? date('Y-m-01');
        $tanggal_invoice = date('Y-m-d', strtotime($tanggal_mulai_semester . " +" . ($bulan_ke_post - 1) . " months"));
        $periode_bulan = date('F Y', strtotime($tanggal_invoice));
        
        // Insert invoice
        $stmt_create = $conn->prepare("
            INSERT INTO pembayaran 
            (siswa_id, datales_id, bulan_ke, semester_ke, tahun_ajaran, periode_bulan, tanggal_transfer, jumlah_bayar, status_pembayaran) 
            VALUES (?, ?, ?, ?, ?, ?, '0000-00-00', ?, 'pending')
        ");
        $stmt_create->bind_param("iiiissd", 
            $siswa_id_post, 
            $datales_id_post, 
            $bulan_ke_post, 
            $info['semester_ke'], 
            $info['tahun_ajaran'], 
            $periode_bulan, 
            $jumlah_bayar
        );
        
        if ($stmt_create->execute()) {
            $stmt_create->close();
            
            $redirect_url = "detail_absensi.php?siswa_id=$siswa_id&success=invoice_created";
            if ($filter_paket > 0) $redirect_url .= "&paket=$filter_paket";
            if ($filter_bulan > 0) $redirect_url .= "&bulan=$filter_bulan";
            
            header("Location: $redirect_url");
            exit();
        }
        
    } catch (Exception $e) {
        error_log("Error create invoice: " . $e->getMessage());
        header("Location: detail_absensi.php?siswa_id=$siswa_id&error=create_invoice_failed");
        exit();
    }
}
    
    // ACTION: Reschedule
    if ($_POST['action'] === 'reschedule') {
        $jadwal_id = (int)$_POST['jadwal_id'];
        $tanggal_baru = trim($_POST['tanggal_reschedule']);
        $alasan = trim($_POST['alasan_reschedule']);
        
        if (empty($tanggal_baru)) {
            header("Location: detail_absensi.php?siswa_id=$siswa_id&error=tanggal_required");
            exit();
        }
        
        try {
            $stmt_old = $conn->prepare("SELECT tanggal_pertemuan FROM jadwal_pertemuan WHERE jadwal_id = ?");
            $stmt_old->bind_param("i", $jadwal_id);
            $stmt_old->execute();
            $result_old = $stmt_old->get_result();
            $old_data = $result_old->fetch_assoc();
            $tanggal_lama = $old_data['tanggal_pertemuan'];
            $stmt_old->close();
            
            $stmt = $conn->prepare("UPDATE jadwal_pertemuan SET tanggal_pertemuan = ?, tanggal_reschedule = ?, catatan_reschedule = ? WHERE jadwal_id = ? AND siswa_id = ?");
            $stmt->bind_param("sssii", $tanggal_baru, $tanggal_lama, $alasan, $jadwal_id, $siswa_id);
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $stmt->close();
                
                $redirect_url = "detail_absensi.php?siswa_id=$siswa_id&success=reschedule_success";
                if ($filter_paket > 0) $redirect_url .= "&paket=$filter_paket";
                if ($filter_bulan > 0) $redirect_url .= "&bulan=$filter_bulan";
                
                header("Location: $redirect_url");
                exit();
            }
        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());
            header("Location: detail_absensi.php?siswa_id=$siswa_id&error=save_failed");
            exit();
        }
    }
    
    // ACTION: Tambah Jadwal Reschedule (untuk utang)
    if ($_POST['action'] === 'tambah_reschedule') {
        $datales_id = (int)$_POST['datales_id'];
        $bulan_ke = (int)$_POST['bulan_ke'];
        $tanggal_reschedule = trim($_POST['tanggal_reschedule']);
        $jam_mulai = trim($_POST['jam_mulai']);
        $jam_selesai = trim($_POST['jam_selesai']);
        $catatan = trim($_POST['catatan_reschedule']);
        
        try {
            // Get info semester
            $stmt_info = $conn->prepare("
                SELECT semester_ke, tahun_ajaran 
                FROM siswa_datales 
                WHERE siswa_id = ? AND datales_id = ? AND status = 'aktif'
            ");
            $stmt_info->bind_param("ii", $siswa_id, $datales_id);
            $stmt_info->execute();
            $info = $stmt_info->get_result()->fetch_assoc();
            $stmt_info->close();
            
            if (!$info) {
                throw new Exception("Data paket tidak ditemukan");
            }
            
            // Insert jadwal reschedule
            $stmt_insert = $conn->prepare("
                INSERT INTO jadwal_pertemuan 
                (siswa_id, datales_id, bulan_ke, semester_ke, tahun_ajaran, pertemuan_ke, tanggal_pertemuan, jam_mulai, jam_selesai, status_pertemuan, is_reschedule, catatan)
                VALUES (?, ?, ?, ?, ?, 99, ?, ?, ?, 'scheduled', TRUE, ?)
            ");
            $stmt_insert->bind_param("iiiissss", 
                $siswa_id, 
                $datales_id, 
                $bulan_ke, 
                $info['semester_ke'], 
                $info['tahun_ajaran'], 
                $tanggal_reschedule, 
                $jam_mulai, 
                $jam_selesai,
                $catatan
            );
            
            if ($stmt_insert->execute()) {
                $stmt_insert->close();
                
                $redirect_url = "detail_absensi.php?siswa_id=$siswa_id&success=reschedule_added";
                if ($filter_paket > 0) $redirect_url .= "&paket=$filter_paket";
                if ($filter_bulan > 0) $redirect_url .= "&bulan=$filter_bulan";
                
                header("Location: $redirect_url");
                exit();
            }
            
        } catch (Exception $e) {
            error_log("Error tambah reschedule: " . $e->getMessage());
            header("Location: detail_absensi.php?siswa_id=$siswa_id&error=add_reschedule_failed");
            exit();
        }
    }
}

// Handle success/error messages
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'status_updated':
            $message = 'Status pertemuan berhasil diupdate!';
            break;
        case 'lanjut_bulan':
            $message = 'Berhasil lanjut ke bulan berikutnya!';
            break;
        case 'status_updated':
            $message = 'Status pertemuan berhasil diupdate!';
            break;
        case 'reschedule_success':
            $message = 'Jadwal pertemuan berhasil di-reschedule!';
            break;
        case 'reschedule_added':
            $message = 'Jadwal reschedule berhasil ditambahkan!';
            break;
        case 'invoice_created':
            $message = 'Invoice berhasil dibuat!';
            break;
        default:
            $message = 'Berhasil!';
            break;
        
    }
    $message_type = 'success';
}

if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'invalid_token':
            $message = 'Invalid security token!';
            break;
        case 'invalid_status':
            $message = 'Status tidak valid!';
            break;
        case 'tanggal_required':
            $message = 'Tanggal reschedule harus diisi!';
            break;
        case 'save_failed':
            $message = 'Gagal menyimpan data!';
            break;
        case 'siswa_not_found':
            $message = 'Data siswa tidak ditemukan!';
            break;
        default:
            $message = 'Terjadi kesalahan: ' . htmlspecialchars($_GET['error']);
            break;
    }
    $message_type = 'danger';
}

try {
    $query_siswa = "
        SELECT 
            s.siswa_id,
            s.name as nama_siswa,
            s.cabang_id,
            c.nama_cabang
        FROM siswa s
        INNER JOIN cabang c ON s.cabang_id = c.cabang_id
        WHERE s.siswa_id = ? AND s.status = 'aktif'
    ";
    
    $stmt = $conn->prepare($query_siswa);
    $stmt->bind_param("i", $siswa_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        header("Location: absensi.php?error=siswa_not_found");
        exit();
    }
    
    $siswa_data = $result->fetch_assoc();
    $stmt->close();
    
    // Ambil semua paket aktif yang dimiliki siswa
    $query_paket = "
        SELECT 
            sd.datales_id,
            sd.slot_id,
            sd.semester_ke,
            sd.tahun_ajaran,
            sd.bulan_aktif,
            sd.tanggal_mulai_semester,
            jl.name as nama_jenisles,
            tl.name as nama_tipe,
            jt.nama_jenistingkat,
            tl.jumlahpertemuan,
            js.hari,
            js.jam_mulai,
            js.jam_selesai,
            g.nama_guru
        FROM siswa_datales sd
        LEFT JOIN datales d ON sd.datales_id = d.datales_id
        LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
        LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        LEFT JOIN jadwal_slot js ON sd.slot_id = js.slot_id
        LEFT JOIN cabangGuru cg ON js.cabangguruID = cg.id
        LEFT JOIN guru g ON cg.guru_id = g.guru_id
        WHERE sd.siswa_id = ? AND sd.status = 'aktif' AND sd.is_history = 0
        ORDER BY sd.datales_id ASC
    ";
    
    $stmt_paket = $conn->prepare($query_paket);
    $stmt_paket->bind_param("i", $siswa_id);
    $stmt_paket->execute();
    $result_paket = $stmt_paket->get_result();
    
    $paket_list = [];
    while ($row = $result_paket->fetch_assoc()) {
        $paket_list[] = $row;
    }
    $stmt_paket->close();
    
    // Set default filter jika belum dipilih
    if ($filter_paket == 0 && count($paket_list) > 0) {
        $filter_paket = $paket_list[0]['datales_id'];
    }
    
    // Ambil detail paket yang dipilih
    $current_paket = null;
    foreach ($paket_list as $p) {
        if ($p['datales_id'] == $filter_paket) {
            $current_paket = $p;
            break;
        }
    }
    
    // Jika tidak ada filter bulan, set ke bulan aktif
    if ($filter_bulan == 0 && $current_paket) {
        $filter_bulan = $current_paket['bulan_aktif'];
    }
    
    // Ambil jadwal untuk bulan yang dipilih
    // Ambil jadwal untuk bulan yang dipilih
    $absensi_data = [];
    $summary_data = null;
    
    if ($current_paket && $filter_bulan > 0) {
        $query_absensi = "
            SELECT 
                jadwal_id,
                pertemuan_ke,
                tanggal_pertemuan,
                jam_mulai,
                jam_selesai,
                status_pertemuan,
                catatan,
                tanggal_reschedule,
                catatan_reschedule,
                is_reschedule,
                reschedule_dari_jadwal_id
            FROM jadwal_pertemuan
            WHERE siswa_id = ? 
            AND datales_id = ? 
            AND bulan_ke = ?
            AND is_history = 0
            ORDER BY is_reschedule ASC, pertemuan_ke ASC
        ";
        
        $stmt_absensi = $conn->prepare($query_absensi);
        $stmt_absensi->bind_param("iii", $siswa_id, $filter_paket, $filter_bulan);
        $stmt_absensi->execute();
        $result_absensi = $stmt_absensi->get_result();
        
        while ($row = $result_absensi->fetch_assoc()) {
            $absensi_data[] = $row;
        }
        $stmt_absensi->close();
        
        // Hitung summary bulan ini SECARA MANUAL
        $stmt_summary = $conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status_pertemuan = 'hadir' THEN 1 ELSE 0 END) as hadir,
                SUM(CASE WHEN status_pertemuan = 'tidak_hadir' THEN 1 ELSE 0 END) as tidak_hadir,
                SUM(CASE WHEN status_pertemuan = 'scheduled' THEN 1 ELSE 0 END) as belum_absen
            FROM jadwal_pertemuan
            WHERE siswa_id = ? 
            AND datales_id = ? 
            AND bulan_ke = ? 
            AND is_reschedule = FALSE
            AND is_history = 0
        ");
        $stmt_summary->bind_param("iii", $siswa_id, $filter_paket, $filter_bulan);
        $stmt_summary->execute();
        $result_summary = $stmt_summary->get_result();
        $summary_raw = $result_summary->fetch_assoc();
        $stmt_summary->close();

        // Format data summary
        $summary_data = [
            'target' => $summary_raw['total'],
            'hadir' => $summary_raw['hadir'],
            'tidak_hadir' => $summary_raw['tidak_hadir'],
            'belum_absen' => $summary_raw['belum_absen'],
            'sisa_utang' => 0
        ];
    }
    
} catch (Exception $e) {
    error_log("FATAL ERROR loading data: " . $e->getMessage());
    header("Location: absensi.php?error=" . urlencode($e->getMessage()));
    exit();
}

$months_id = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Detail Presensi - <?php echo htmlspecialchars($siswa_data['nama_siswa']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <style>
        .pertemuan-card {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            background: #fff;
            transition: all 0.3s;
        }
        .pertemuan-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .pertemuan-card.reschedule {
            border-left: 4px solid #ffc107;
            background: #fffbf0;
        }
        .pertemuan-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #dee2e6;
        }
        .pertemuan-number {
            font-size: 1.5em;
            font-weight: bold;
            color: #495057;
        }
        .bulan-tab {
            cursor: pointer;
            transition: all 0.3s;
        }
        .bulan-tab:hover {
            background-color: #f8f9fa;
        }
        .bulan-tab.active {
            background-color: #0d6efd;
            color: white;
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
                        
                        <?php if ($current_user_role_id <= 2): ?>
                        <div class="sb-sidenav-menu-heading">Management</div>
                        <a class="nav-link active" href="absensi.php">
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
                    <?php echo htmlspecialchars($current_user_name); ?>
                </div>
            </nav>
        </div>
        
        <div id="layoutSidenav_content">
            <main>
                <div class="container-fluid px-4">
                    <div class="d-flex justify-content-between align-items-center mt-4 mb-4">
                        <h1>Detail Presensi</h1>
                        <a href="absensi.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Kembali
                        </a>
                    </div>

                    <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- INFO SISWA HEADER -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h5 class="mb-2">
                                        <i class="fas fa-user-graduate me-2"></i>
                                        <?php echo htmlspecialchars($siswa_data['nama_siswa']); ?>
                                    </h5>
                                    <p class="mb-0">
                                        <i class="fas fa-building me-2"></i>
                                        <strong>Cabang:</strong> <?php echo htmlspecialchars($siswa_data['nama_cabang']); ?>
                                    </p>
                                </div>
                                
                                <!-- FILTER PAKET -->
                                <?php if (count($paket_list) > 1): ?>
                                <div class="col-md-4">
                                    <label class="form-label"><strong>Filter Paket:</strong></label>
                                    <select class="form-select" onchange="window.location.href='detail_absensi.php?siswa_id=<?php echo $siswa_id; ?>&paket=' + this.value">
                                        <?php foreach ($paket_list as $paket): ?>
                                        <option value="<?php echo $paket['datales_id']; ?>" 
                                                <?php echo ($filter_paket == $paket['datales_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($paket['nama_jenisles'] . ' - ' . $paket['nama_tipe']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if ($current_paket): ?>
                    <!-- INFO PAKET YANG DIPILIH -->
                    <div class="bg-light border rounded p-3 mb-4">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-2">
                                    <strong>Paket:</strong> 
                                    <?php echo htmlspecialchars($current_paket['nama_jenisles']); ?> - 
                                    <?php echo htmlspecialchars($current_paket['nama_tipe']); ?> - 
                                    <?php echo htmlspecialchars($current_paket['nama_jenistingkat']); ?>
                                </p>
                                <p class="mb-0">
                                    <strong>Guru:</strong> 
                                    <?php echo htmlspecialchars($current_paket['nama_guru'] ?? '-'); ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-2">
                                    <strong>Jadwal:</strong> 
                                    <?php echo $current_paket['hari'] ?? '-'; ?>, 
                                    <?php echo $current_paket['jam_mulai'] ? substr($current_paket['jam_mulai'], 0, 5) : '-'; ?> - 
                                    <?php echo $current_paket['jam_selesai'] ? substr($current_paket['jam_selesai'], 0, 5) : '-'; ?>
                                </p>
                                <p class="mb-0">
                                    <strong>Semester:</strong> 
                                    Semester <?php echo $current_paket['semester_ke']; ?> - <?php echo $current_paket['tahun_ajaran']; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- TAB BULAN (1-6) -->
                    <div class="card mb-4">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Pilih Bulan</h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <?php for ($b = 1; $b <= 6; $b++): 
                                    $is_active = ($b == $filter_bulan);
                                    $btn_class = $is_active ? 'btn-primary' : 'btn-outline-primary';
                                ?>
                                <div class="col-6 col-md-2">
                                    <a href="detail_absensi.php?siswa_id=<?php echo $siswa_id; ?>&paket=<?php echo $filter_paket; ?>&bulan=<?php echo $b; ?>" 
                                       class="btn <?php echo $btn_class; ?> w-100">
                                        <i class="fas fa-calendar me-1"></i>Bulan <?php echo $b; ?>
                                    </a>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>

                    <!-- SUMMARY BULAN INI -->
                    <?php if ($summary_data): ?>
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card border-primary">
                                <div class="card-body text-center">
                                    <h6 class="text-muted mb-2">Target Pertemuan</h6>
                                    <h3 class="mb-0 text-primary"><?php echo $summary_data['target']; ?>x</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-success">
                                <div class="card-body text-center">
                                    <h6 class="text-muted mb-2">Hadir</h6>
                                    <h3 class="mb-0 text-success"><?php echo $summary_data['hadir']; ?>x</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-danger">
                                <div class="card-body text-center">
                                    <h6 class="text-muted mb-2">Tidak Hadir</h6>
                                    <h3 class="mb-0 text-danger"><?php echo $summary_data['tidak_hadir']; ?>x</h3>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card border-warning">
                                <div class="card-body text-center">
                                    <h6 class="text-muted mb-2">Belum Absen</h6>
                                    <h3 class="mb-0 text-warning"><?php echo $summary_data['belum_absen']; ?>x</h3>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($filter_bulan == 6): ?>
                        <?php
                        // Cek apakah bulan 6 sudah bisa diselesaikan (min 80% hadir)
                        $progress_persen = ($summary_data['target'] > 0) ? ($summary_data['hadir'] / $summary_data['target']) * 100 : 0;
                        $bisa_selesai = ($progress_persen >= 80);
                        ?>
                        
                        <div class="alert alert-<?php echo $bisa_selesai ? 'success' : 'info'; ?> mb-4">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-graduation-cap me-2"></i>
                                    <strong>Semester Bulan 6</strong>
                                    <?php if ($bisa_selesai): ?>
                                        <br><small>Semester sudah bisa diselesaikan (<?php echo $summary_data['hadir']; ?>/<?php echo $summary_data['target']; ?> pertemuan hadir)</small>
                                    <?php else: ?>
                                        <br><small>Minimal 80% kehadiran untuk selesaikan semester (sekarang: <?php echo number_format($progress_persen, 0); ?>%)</small>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($bisa_selesai): ?>
                                <form method="POST" onsubmit="return confirm('Selesaikan semester untuk siswa ini?\n\nSiswa akan muncul di halaman Kelola Semester.');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="selesaikan_semester">
                                    <input type="hidden" name="siswa_id" value="<?php echo $siswa_id; ?>">
                                    <input type="hidden" name="datales_id" value="<?php echo $filter_paket; ?>">
                                    
                                    <button type="submit" class="btn btn-success">
                                        <i class="fas fa-check-circle me-1"></i>Selesaikan Semester
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- ✅ TAMBAHKAN CODE INI: INFO INVOICE BULAN INI -->
                    <?php
                    // Ambil data invoice bulan ini
                    $stmt_invoice = $conn->prepare("
                        SELECT 
                            pembayaran_id,
                            periode_bulan,
                            jumlah_bayar,
                            status_pembayaran,
                            tanggal_transfer,
                            bukti_transfer
                        FROM pembayaran
                        WHERE siswa_id = ? 
                        AND datales_id = ? 
                        AND bulan_ke = ? 
                        AND semester_ke = ?
                        AND (is_archived = 0 OR is_archived IS NULL)
                        LIMIT 1
                    ");
                    $stmt_invoice->bind_param("iiii", $siswa_id, $filter_paket, $filter_bulan, $current_paket['semester_ke']);
                    $stmt_invoice->execute();
                    $result_invoice = $stmt_invoice->get_result();
                    $invoice_data = $result_invoice->fetch_assoc();
                    $stmt_invoice->close();
                    ?>

                    <div class="card mb-4 <?php echo $invoice_data ? ($invoice_data['status_pembayaran'] == 'paid' ? 'border-success' : 'border-warning') : 'border-danger'; ?>">
                        <div class="card-header <?php echo $invoice_data ? ($invoice_data['status_pembayaran'] == 'paid' ? 'bg-success text-white' : 'bg-warning') : 'bg-danger text-white'; ?>">
                            <h5 class="mb-0">
                                <i class="fas fa-file-invoice-dollar me-2"></i>
                                Invoice Bulan <?php echo $filter_bulan; ?> - <?php echo $invoice_data ? htmlspecialchars($invoice_data['periode_bulan']) : 'Belum Ada'; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if ($invoice_data): ?>
                                <div class="row align-items-center">
                                    <div class="col-md-4">
                                        <p class="mb-2">
                                            <strong>ID Invoice:</strong> #<?php echo str_pad($invoice_data['pembayaran_id'], 5, '0', STR_PAD_LEFT); ?>
                                        </p>
                                        <p class="mb-2">
                                            <strong>Periode:</strong> <?php echo htmlspecialchars($invoice_data['periode_bulan']); ?>
                                        </p>
                                        <p class="mb-0">
                                            <strong>Jumlah:</strong> 
                                            <span class="fs-5 text-primary">
                                                Rp <?php echo number_format($invoice_data['jumlah_bayar'], 0, ',', '.'); ?>
                                            </span>
                                        </p>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="text-center">
                                            <?php if ($invoice_data['status_pembayaran'] == 'paid'): ?>
                                                <span class="badge bg-success fs-6 px-4 py-2">
                                                    <i class="fas fa-check-circle me-2"></i>LUNAS
                                                </span>
                                                <p class="mb-0 mt-2 small text-muted">
                                                    Dibayar: <?php echo date('d/m/Y', strtotime($invoice_data['tanggal_transfer'])); ?>
                                                </p>
                                            <?php elseif ($invoice_data['status_pembayaran'] == 'pending_verification'): ?>
                                                <span class="badge bg-info fs-6 px-4 py-2">
                                                    <i class="fas fa-clock me-2"></i>VERIFIKASI
                                                </span>
                                                <p class="mb-0 mt-2 small text-muted">Menunggu verifikasi admin</p>
                                            <?php else: ?>
                                                <span class="badge bg-warning fs-6 px-4 py-2">
                                                    <i class="fas fa-exclamation-triangle me-2"></i>BELUM BAYAR
                                                </span>
                                                <p class="mb-0 mt-2 small text-muted">Segera lakukan pembayaran</p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4 text-end">
                                        <?php if ($invoice_data['status_pembayaran'] == 'paid'): ?>
                                            <a href="generate_pembayaran.php?pembayaran_id=<?php echo $invoice_data['pembayaran_id']; ?>" 
                                            class="btn btn-success mb-2" target="_blank">
                                                <i class="fas fa-download me-1"></i>Download Invoice
                                            </a>
                                            <br>
                                            <?php if (!empty($invoice_data['bukti_transfer'])): ?>
                                            <a href="uploads/bukti_transfer/<?php echo htmlspecialchars($invoice_data['bukti_transfer']); ?>" 
                                            class="btn btn-sm btn-outline-secondary" target="_blank">
                                                <i class="fas fa-file-image me-1"></i>Lihat Bukti Transfer
                                            </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <a href="view_invoice.php?pembayaran_id=<?php echo $invoice_data['pembayaran_id']; ?>" 
                                            class="btn btn-primary" target="_blank">
                                                <i class="fas fa-file-invoice me-1"></i>Lihat Invoice
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- ✅ Invoice tidak ditemukan - Tampilkan button buat invoice baru -->
                                <div class="alert alert-danger mb-3">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Invoice tidak ditemukan!</strong>
                                    <p class="mb-0 small mt-1">Invoice untuk bulan ini belum ada. Klik tombol di bawah untuk membuat invoice baru.</p>
                                </div>
                                
                                <div class="text-center">
                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#createInvoiceModal">
                                        <i class="fas fa-plus me-1"></i>Buat Invoice Baru
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- ALERT UTANG -->
                    <?php if ($summary_data['sisa_utang'] > 0): ?>
                    <div class="alert alert-warning">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Perlu Reschedule:</strong> Ada <?php echo $summary_data['sisa_utang']; ?> pertemuan yang perlu di-reschedule
                            </div>
                            <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#tambahRescheduleModal">
                                <i class="fas fa-plus me-1"></i>Tambah Jadwal Reschedule
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <!-- DAFTAR JADWAL PERTEMUAN BULAN INI -->
                    <div class="card mb-5">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-list me-2"></i>
                                Daftar Pertemuan - Bulan <?php echo $filter_bulan; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($absensi_data) > 0): ?>
                                <?php foreach ($absensi_data as $jadwal): 
                                    $is_rescheduled = !empty($jadwal['tanggal_reschedule']);
                                    $is_reschedule_baru = $jadwal['is_reschedule'];
                                ?>
                                
                                <div class="pertemuan-card <?php echo $is_reschedule_baru ? 'reschedule' : ''; ?>">
                                    <div class="pertemuan-header">
                                        <div>
                                            <span class="pertemuan-number">
                                                <?php if ($is_reschedule_baru): ?>
                                                    <i class="fas fa-redo me-2 text-warning"></i>Reschedule
                                                <?php else: ?>
                                                    Pertemuan <?php echo $jadwal['pertemuan_ke']; ?>
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($is_rescheduled): ?>
                                            <span class="badge bg-warning text-dark ms-2">
                                                <i class="fas fa-calendar-alt me-1"></i>Di-reschedule
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <?php
                                            $status_badges = [
                                                'hadir' => '<span class="badge bg-success fs-6">Hadir</span>',
                                                'tidak_hadir' => '<span class="badge bg-danger fs-6">Tidak Hadir</span>',
                                                'scheduled' => '<span class="badge bg-secondary fs-6">Belum Absen</span>'
                                            ];
                                            echo $status_badges[$jadwal['status_pertemuan']] ?? $status_badges['scheduled'];
                                            ?>
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <p class="mb-2">
                                                <i class="fas fa-calendar me-2"></i><strong>Tanggal:</strong> 
                                                <?php 
                                                $date = new DateTime($jadwal['tanggal_pertemuan']);
                                                echo $date->format('d') . ' ' . $months_id[(int)$date->format('n')] . ' ' . $date->format('Y');
                                                ?>
                                            </p>
                                            
                                            <?php if ($is_rescheduled): ?>
                                            <div class="alert alert-warning py-2 mb-2">
                                                <small>
                                                    <i class="fas fa-info-circle me-1"></i>
                                                    <strong>Di-reschedule dari:</strong> 
                                                    <?php 
                                                    $old_date = new DateTime($jadwal['tanggal_reschedule']);
                                                    echo $old_date->format('d') . ' ' . $months_id[(int)$old_date->format('n')] . ' ' . $old_date->format('Y');
                                                    ?>
                                                    <?php if (!empty($jadwal['catatan_reschedule'])): ?>
                                                    <br><strong>Alasan:</strong> <?php echo htmlspecialchars($jadwal['catatan_reschedule']); ?>
                                                    <?php endif; ?>
                                                </small>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <p class="mb-2">
                                                <i class="fas fa-clock me-2"></i><strong>Jam:</strong> 
                                                <?php 
                                                echo (!empty($jadwal['jam_mulai']) && !empty($jadwal['jam_selesai'])) 
                                                    ? substr($jadwal['jam_mulai'], 0, 5) . ' - ' . substr($jadwal['jam_selesai'], 0, 5)
                                                    : '<span class="text-muted">Belum dijadwalkan</span>';
                                                ?>
                                            </p>
                                            
                                            <?php if (!empty($jadwal['catatan'])): ?>
                                            <div class="mt-2 p-2 bg-light rounded">
                                                <small>
                                                    <i class="fas fa-comment me-1"></i><strong>Catatan:</strong><br>
                                                    <?php echo nl2br(htmlspecialchars($jadwal['catatan'])); ?>
                                                </small>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label"><strong>Update Status:</strong></label>
                                            <div class="btn-group w-100 mb-2" role="group">
                                                <button type="button" class="btn btn-outline-success"
                                                        onclick="openUpdateStatusModal(<?php echo $jadwal['jadwal_id']; ?>, 'hadir', '<?php echo htmlspecialchars($siswa_data['nama_siswa']); ?>', <?php echo $jadwal['pertemuan_ke']; ?>, '<?php echo $jadwal['tanggal_pertemuan']; ?>', '<?php echo addslashes($jadwal['catatan'] ?? ''); ?>')">
                                                    <i class="fas fa-check me-1"></i>Hadir
                                                </button>
                                                <button type="button" class="btn btn-outline-danger"
                                                        onclick="openUpdateStatusModal(<?php echo $jadwal['jadwal_id']; ?>, 'tidak_hadir', '<?php echo htmlspecialchars($siswa_data['nama_siswa']); ?>', <?php echo $jadwal['pertemuan_ke']; ?>, '<?php echo $jadwal['tanggal_pertemuan']; ?>', '<?php echo addslashes($jadwal['catatan'] ?? ''); ?>')">
                                                    <i class="fas fa-times me-1"></i>Tidak Hadir
                                                </button>
                                            </div>
                                            
                                            <button type="button" class="btn btn-outline-secondary w-100"
                                                    onclick="reschedulePertemuan(<?php echo $jadwal['jadwal_id']; ?>, '<?php echo htmlspecialchars($siswa_data['nama_siswa']); ?>', <?php echo $jadwal['pertemuan_ke']; ?>, '<?php echo $jadwal['tanggal_pertemuan']; ?>')">
                                                <i class="fas fa-calendar-alt me-1"></i>Reschedule
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-calendar-times fa-4x text-muted mb-3"></i>
                                    <p class="text-muted">Belum ada jadwal pertemuan untuk bulan ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Siswa ini belum memiliki paket kelas aktif.
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
        function openUpdateStatusModal(jadwal_id, status_pertemuan, nama_siswa, pertemuan_ke, tanggal, catatan) {
        document.getElementById('update_jadwal_id').value = jadwal_id;
        document.getElementById('modal_status_pertemuan').value = status_pertemuan;
        document.getElementById('update_nama_siswa').textContent = nama_siswa;
        document.getElementById('update_pertemuan_ke').textContent = pertemuan_ke;
        
        const months_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        const dateObj = new Date(tanggal);
        const formattedDate = dateObj.getDate() + ' ' + months_id[dateObj.getMonth()] + ' ' + dateObj.getFullYear();
        document.getElementById('update_tanggal').textContent = formattedDate;
        
        document.getElementById('update_catatan').value = catatan || '';
        
        // Update modal header style
        let statusBadge = '';
        let modalHeaderClass = 'bg-primary';
        
        if (status_pertemuan === 'hadir') {
            statusBadge = '<span class="badge bg-success ms-2">✓ HADIR</span>';
            modalHeaderClass = 'bg-success';
        } else if (status_pertemuan === 'tidak_hadir') {
            statusBadge = '<span class="badge bg-danger ms-2">✗ TIDAK HADIR</span>';
            modalHeaderClass = 'bg-danger';
        }
        
        const modalHeader = document.querySelector('#updateStatusModal .modal-header');
        modalHeader.className = 'modal-header ' + modalHeaderClass + ' text-white';
        
        const modalTitle = document.querySelector('#updateStatusModal .modal-title');
        modalTitle.innerHTML = '<i class="fas fa-clipboard-check me-2"></i>Update Status Pertemuan' + statusBadge;
        
        const modal = new bootstrap.Modal(document.getElementById('updateStatusModal'));
        modal.show();
    }

        function reschedulePertemuan(jadwal_id, nama_siswa, pertemuan_ke, tanggal_lama) {
            document.getElementById('reschedule_jadwal_id').value = jadwal_id;
            document.getElementById('reschedule_nama_siswa').textContent = nama_siswa;
            document.getElementById('reschedule_pertemuan_ke').textContent = pertemuan_ke;
            
            const months_id = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
            const dateObj = new Date(tanggal_lama);
            const formattedDate = dateObj.getDate() + ' ' + months_id[dateObj.getMonth()] + ' ' + dateObj.getFullYear();
            document.getElementById('reschedule_tanggal_lama').value = formattedDate;
            
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('reschedule_tanggal_baru').setAttribute('min', today);
            document.getElementById('reschedule_tanggal_baru').value = '';
            document.querySelector('#rescheduleModal textarea[name="alasan_reschedule"]').value = '';
            
            const modal = new bootstrap.Modal(document.getElementById('rescheduleModal'));
            modal.show();
        }

        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>

    <!-- Modal Reschedule -->
<div class="modal fade" id="rescheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">
                        <i class="fas fa-calendar-alt me-2"></i>
                        Reschedule Pertemuan
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="reschedule">
                    <input type="hidden" name="jadwal_id" id="reschedule_jadwal_id">

                    <div class="alert alert-info">
                        <strong>Siswa:</strong> <span id="reschedule_nama_siswa"></span><br>
                        <strong>Pertemuan Ke-:</strong> <span id="reschedule_pertemuan_ke"></span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-calendar me-1"></i>Tanggal Sekarang</label>
                        <input type="text" class="form-control" id="reschedule_tanggal_lama" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-calendar-check me-1"></i>Tanggal Baru *</label>
                        <input type="date" class="form-control" name="tanggal_reschedule" id="reschedule_tanggal_baru" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><i class="fas fa-comment-dots me-1"></i>Alasan Reschedule *</label>
                        <textarea class="form-control" name="alasan_reschedule" rows="3" required placeholder="Contoh: Siswa sakit, Libur nasional, Guru berhalangan, dll"></textarea>
                    </div>

                    <div class="alert alert-warning">
                        <small><i class="fas fa-exclamation-triangle me-1"></i>
                        Tanggal pertemuan akan berubah. Pastikan guru dan siswa sudah dikonfirmasi.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i>Simpan Reschedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Modal Tambah Reschedule (untuk utang) -->
    <div class="modal fade" id="tambahRescheduleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title">
                            <i class="fas fa-plus me-2"></i>
                            Tambah Jadwal Reschedule
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="tambah_reschedule">
                        <input type="hidden" name="datales_id" value="<?php echo $filter_paket; ?>">
                        <input type="hidden" name="bulan_ke" value="<?php echo $filter_bulan; ?>">

                        <div class="alert alert-info">
                            <small>Tambahkan jadwal reschedule untuk pertemuan yang terlewat di bulan ini.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Tanggal Reschedule *</label>
                            <input type="date" class="form-control" name="tanggal_reschedule" required>
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Jam Mulai *</label>
                                <input type="time" class="form-control" name="jam_mulai" value="<?php echo $current_paket['jam_mulai'] ?? ''; ?>" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Jam Selesai *</label>
                                <input type="time" class="form-control" name="jam_selesai" value="<?php echo $current_paket['jam_selesai'] ?? ''; ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Catatan</label>
                            <textarea class="form-control" name="catatan_reschedule" rows="2" placeholder="Reschedule untuk mengganti pertemuan yang terlewat..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-save me-1"></i>Tambah Jadwal
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Generate Invoice PDF -->
    <div class="modal fade" id="generateInvoiceModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="GET" action="generate_pembayaran.php" target="_blank">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">
                            <i class="fas fa-file-pdf me-2"></i>
                            Generate Invoice PDF
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="pembayaran_id" value="<?php echo $invoice_data ? $invoice_data['pembayaran_id'] : 0; ?>">
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Invoice PDF akan digenerate untuk:
                        </div>
                        
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <i class="fas fa-user me-2"></i>
                                <strong>Siswa:</strong> <?php echo htmlspecialchars($siswa_data['nama_siswa']); ?>
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-book me-2"></i>
                                <strong>Paket:</strong> 
                                <?php echo htmlspecialchars($current_paket['nama_jenisles'] . ' - ' . $current_paket['nama_tipe']); ?>
                            </li>
                            <li class="mb-2">
                                <i class="fas fa-calendar me-2"></i>
                                <strong>Bulan:</strong> Bulan <?php echo $filter_bulan; ?>
                            </li>
                            <?php if ($invoice_data): ?>
                            <li class="mb-0">
                                <i class="fas fa-money-bill me-2"></i>
                                <strong>Jumlah:</strong> Rp <?php echo number_format($invoice_data['jumlah_bayar'], 0, ',', '.'); ?>
                            </li>
                            <?php endif; ?>
                        </ul>
                        
                        <div class="alert alert-warning">
                            <small>
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                PDF akan terbuka di tab baru. Pastikan pop-up tidak diblok oleh browser.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-download me-1"></i>Generate & Download PDF
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<!-- Modal Buat Invoice Baru (jika belum ada) -->
<div class="modal fade" id="createInvoiceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>
                        Buat Invoice Baru
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="create_invoice">
                    <input type="hidden" name="siswa_id" value="<?php echo $siswa_id; ?>">
                    <input type="hidden" name="datales_id" value="<?php echo $filter_paket; ?>">
                    <input type="hidden" name="bulan_ke" value="<?php echo $filter_bulan; ?>">
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Perhatian!</strong> Invoice untuk bulan ini belum ada dalam database.
                    </div>
                    
                    <p>Sistem akan membuat invoice baru dengan data:</p>
                    
                    <ul class="list-unstyled">
                        <li class="mb-2">
                            <strong>Siswa:</strong> <?php echo htmlspecialchars($siswa_data['nama_siswa']); ?>
                        </li>
                        <li class="mb-2">
                            <strong>Paket:</strong> 
                            <?php echo htmlspecialchars($current_paket['nama_jenisles'] . ' - ' . $current_paket['nama_tipe']); ?>
                        </li>
                        <li class="mb-2">
                            <strong>Bulan:</strong> Bulan <?php echo $filter_bulan; ?>
                        </li>
                        <li class="mb-0">
                            <strong>Semester:</strong> 
                            Semester <?php echo $current_paket['semester_ke']; ?> - <?php echo $current_paket['tahun_ajaran']; ?>
                        </li>
                    </ul>
                    
                    <div class="mb-3 mt-3">
                        <label class="form-label">Jumlah Pembayaran</label>
                        <?php
                        // ✅ Ambil harga dari datales (bukan tipeles)
                        $stmt_harga = $conn->prepare("
                            SELECT d.harga
                            FROM siswa_datales sd
                            INNER JOIN datales d ON sd.datales_id = d.datales_id
                            WHERE sd.siswa_id = ? AND sd.datales_id = ? AND sd.is_history = 0
                        ");
                        $stmt_harga->bind_param("ii", $siswa_id, $filter_paket);
                        $stmt_harga->execute();
                        $harga_data = $stmt_harga->get_result()->fetch_assoc();
                        $stmt_harga->close();
                        
                        $harga_default = $harga_data ? $harga_data['harga'] : 400000;
                        ?>
                        <input type="number" class="form-control" name="jumlah_bayar" value="<?php echo $harga_default; ?>" readonly>
                        <small class="text-muted">Harga otomatis dari master paket (tidak bisa diubah)</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-save me-1"></i>Buat Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Update Status -->
<div class="modal fade" id="updateStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-clipboard-check me-2"></i>
                        Update Status Pertemuan
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="jadwal_id" id="update_jadwal_id">
                    <input type="hidden" name="status_pertemuan" id="modal_status_pertemuan">

                    <div class="alert alert-info">
                        <strong>Siswa:</strong> <span id="update_nama_siswa"></span><br>
                        <strong>Pertemuan Ke-:</strong> <span id="update_pertemuan_ke"></span><br>
                        <strong>Tanggal:</strong> <span id="update_tanggal"></span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-comment-dots me-1"></i>
                            Catatan (opsional)
                        </label>
                        <textarea class="form-control" name="catatan" id="update_catatan" rows="4" placeholder="Tambahkan catatan tambahan jika diperlukan..."></textarea>
                        <small class="text-muted">Contoh: Siswa berprestasi hari ini, Perlu review materi minggu depan, dll.</small>
                    </div>

                    <div class="alert alert-warning">
                        <small>
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            Status yang sudah disimpan akan tercatat dalam sistem.
                        </small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i>Simpan Status
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>