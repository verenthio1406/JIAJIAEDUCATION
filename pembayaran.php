<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'check_login.php';

// Hanya Orang Tua yang bisa akses
if (getUserRoleId() != 3) {
    header("Location: index.php?error=unauthorized");
    exit();
}

require 'config.php';
date_default_timezone_set('Asia/Jakarta');

// Generate CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = getUserRoleId();
$current_user_cabang_id = getUserCabangId();
$current_user_cabang_name = $_SESSION['cabang_name'] ?? '';

$message = '';
$message_type = '';

// Ambil pembayaran_id dari URL
$pembayaran_id = isset($_GET['pembayaran_id']) ? (int)$_GET['pembayaran_id'] : 0;

// Ambil data pembayaran yang akan dibayar
$pembayaran_data = null;
try {
    $current_siswa_id = getSiswaId();
    
    if ($pembayaran_id > 0 && $current_siswa_id) {
    // Cek apakah pembayaran ini milik siswa yang login
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            s.name as nama_siswa,
            CONCAT(jl.name, ' - ', tl.name, ' - ', jt.nama_jenistingkat) as nama_paket,
            c.nama_cabang
        FROM pembayaran p
        INNER JOIN siswa s ON p.siswa_id = s.siswa_id
        INNER JOIN datales d ON p.datales_id = d.datales_id
        INNER JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
        INNER JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        INNER JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        INNER JOIN cabang c ON d.cabang_id = c.cabang_id
        WHERE p.pembayaran_id = ? 
        AND p.siswa_id = ?
    ");
        $stmt->bind_param("ii", $pembayaran_id, $current_siswa_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $pembayaran_data = $result->fetch_assoc();
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validasi CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = 'Invalid security token. Please try again.';
        $message_type = 'danger';
    } else {
        $pembayaran_id = (int)$_POST['pembayaran_id'];
        $tanggal_transfer = $_POST['tanggal_transfer'];
        
        // Validasi pembayaran milik siswa yang login
        $current_siswa_id = getSiswaId();
        
        // Upload bukti transfer
        $bukti_transfer = null;
        if (isset($_FILES['bukti_transfer']) && $_FILES['bukti_transfer']['error'] == 0) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $file_type = $_FILES['bukti_transfer']['type'];
            
            if (!in_array($file_type, $allowed_types)) {
                $message = 'Format file tidak valid! Hanya JPG, PNG, atau PDF yang diperbolehkan.';
                $message_type = 'danger';
            } elseif ($_FILES['bukti_transfer']['size'] > 5 * 1024 * 1024) { // Max 5MB
                $message = 'Ukuran file terlalu besar! Maksimal 5MB.';
                $message_type = 'danger';
            } else {
                $target_dir = "uploads/bukti_transfer/";
                if (!file_exists($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }
                
                $file_extension = pathinfo($_FILES['bukti_transfer']['name'], PATHINFO_EXTENSION);
                $new_filename = 'bukti_' . $pembayaran_id . '_' . time() . '.' . $file_extension;
                $target_file = $target_dir . $new_filename;
                
                if (move_uploaded_file($_FILES['bukti_transfer']['tmp_name'], $target_file)) {
                    $bukti_transfer = $target_file;
                } else {
                    $message = 'Gagal upload file!';
                    $message_type = 'danger';
                }
            }
        } else {
            $message = 'Bukti transfer wajib diupload!';
            $message_type = 'danger';
        }
        
        if (empty($message)) {
            try {
                // Update pembayaran dengan bukti transfer dan tanggal transfer
                $stmt = $conn->prepare("UPDATE pembayaran 
                    SET bukti_transfer = ?, 
                        tanggal_transfer = ?,
                        status_pembayaran = 'waiting_verification'
                    WHERE pembayaran_id = ? 
                    AND siswa_id = ?");  // ✅ Tanpa kondisi status
                
                $stmt->bind_param("ssii", 
                    $bukti_transfer, 
                    $tanggal_transfer,
                    $pembayaran_id,
                    $current_siswa_id
                );
                
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $stmt->close();
                    
                    $_SESSION['flash_message'] = 'Bukti transfer berhasil diupload! Status: Menunggu Verifikasi Head Admin.';
                    $_SESSION['flash_type'] = 'success';
                    
                    header("Location: index.php");
                    exit();
                } else {
                    $message = 'Gagal menyimpan pembayaran. Pastikan tagihan masih dalam status pending.';
                    $message_type = 'danger';
                }
                
                if (isset($stmt)) $stmt->close();
                
            } catch (Exception $e) {
                error_log("Database error: " . $e->getMessage());
                $message = 'Gagal menyimpan pembayaran: ' . $e->getMessage();
                $message_type = 'danger';
            }
        }
    }
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Upload Pembayaran - Jia Jia Education</title>
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
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

                        <?php if ($current_user_role_id == 3): ?>
                        <div class="sb-sidenav-menu-heading">Pembayaran</div>
                        <a class="nav-link active" href="pembayaran.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-file-upload"></i></div>
                            Upload Pembayaran
                        </a>
                        <a class="nav-link" href="riwayat_pembayaran.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-history"></i></div>
                            Riwayat Pembayaran
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
                    <h1 class="mt-4">Upload Bukti Transfer</h1>
                    <br>

                    <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($pembayaran_id == 0): ?>
                    <!-- Tampilkan list tagihan ketika tidak ada parameter -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <i class="fas fa-list me-2"></i>
                            <strong>Pilih Tagihan yang Akan Dibayar</strong>
                        </div>
                        <div class="card-body">
                            <?php
                            try {
                                $current_siswa_id = getSiswaId();
                                
                                if ($current_siswa_id) {
                                    // Query tagihan pending untuk siswa ini
                                    $query_tagihan = "
                                        SELECT 
                                            p.pembayaran_id,
                                            p.jumlah_bayar,
                                            p.total_pertemuan,
                                            p.bulan_ke,
                                            p.semester_ke,
                                            s.name as nama_siswa,
                                            c.nama_cabang,
                                            jl.name as nama_jenisles,
                                            tl.name as nama_tipe,
                                            jt.nama_jenistingkat,
                                            (SELECT MIN(jp.tanggal_pertemuan) 
                                            FROM jadwal_pertemuan jp 
                                            WHERE jp.siswa_id = p.siswa_id 
                                            AND jp.datales_id = p.datales_id 
                                            AND jp.bulan_ke = p.bulan_ke
                                            AND jp.semester_ke = p.semester_ke
                                            AND jp.is_history = 0
                                            ) as tanggal_pertemuan_pertama
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
                                        HAVING tanggal_pertemuan_pertama IS NOT NULL 
                                        AND DATE_SUB(tanggal_pertemuan_pertama, INTERVAL 7 DAY) <= CURDATE()
                                        ORDER BY p.pembayaran_id ASC
                                    ";
                                    
                                    $stmt_list = $conn->prepare($query_tagihan);
                                    $stmt_list->bind_param("i", $current_siswa_id);
                                    $stmt_list->execute();
                                    $result_list = $stmt_list->get_result();
                                    
                                    if ($result_list->num_rows > 0) {
                                        echo '<div class="alert alert-info mb-3">';
                                        echo '<i class="fas fa-info-circle me-2"></i>';
                                        echo 'Silakan pilih tagihan yang akan dibayar, lalu upload bukti transfer.';
                                        echo '</div>';
                                        
                                        echo '<div class="table-responsive">';
                                        echo '<table class="table table-hover table-striped align-middle">';
                                        echo '<thead class="table-dark">';
                                        echo '<tr>';
                                        echo '<th>No</th>';
                                        echo '<th>Paket</th>';
                                        echo '<th>Cabang</th>';
                                        echo '<th>Pertemuan</th>';
                                        echo '<th class="text-end">Jumlah</th>';
                                        echo '<th class="text-center">Action</th>';
                                        echo '</tr>';
                                        echo '</thead>';
                                        echo '<tbody>';
                                        
                                        $no = 1;
                                        while ($row = $result_list->fetch_assoc()) {
                                            echo '<tr>';
                                            echo '<td>' . $no++ . '</td>';
                                            echo '<td>';
                                            echo '<span class="badge bg-info">' . htmlspecialchars($row['nama_jenisles']) . ' ' . htmlspecialchars($row['nama_tipe']) . '</span><br>';
                                            echo '<small class="text-muted">' . htmlspecialchars($row['nama_jenistingkat']) . '</small>';
                                            echo '</td>';
                                            echo '<td>' . htmlspecialchars($row['nama_cabang']) . '</td>';
                                            echo '<td class="text-center">' . $row['total_pertemuan'] . 'x</td>';
                                            echo '<td class="text-end"><strong>Rp' . number_format($row['jumlah_bayar'], 0, ',', '.') . '</strong></td>';
                                            echo '<td class="text-center">';
                                            echo '<a href="pembayaran.php?pembayaran_id=' . $row['pembayaran_id'] . '" class="btn btn-warning btn-sm">';
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
                                    
                                    $stmt_list->close();
                                } else {
                                    echo '<div class="alert alert-warning">';
                                    echo '<i class="fas fa-exclamation-triangle me-2"></i>';
                                    echo 'Data siswa tidak ditemukan.';
                                    echo '</div>';
                                }
                            } catch (Exception $e) {
                                error_log("Error: " . $e->getMessage());
                                echo '<div class="alert alert-danger">';
                                echo '<i class="fas fa-exclamation-triangle me-2"></i>';
                                echo 'Terjadi kesalahan saat memuat data tagihan.';
                                echo '</div>';
                            }
                            ?>
                            
                            <div class="mt-3">
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-1"></i> Kembali ke Halaman Utama
                                </a>
                            </div>
                        </div>
                    </div>

                <?php elseif (!$pembayaran_data): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Tagihan tidak ditemukan atau sudah dibayar.</strong>
                        <p class="mb-0 mt-2">Silakan <a href="pembayaran.php">klik di sini</a> untuk melihat daftar tagihan.</p>
                    </div>
                <?php else: ?>
                    
                    <!-- Form Upload -->
                    <div class="card">
                        <div class="card-header bg-dark text-white">
                            <i class="fas fa-file-upload me-1"></i>
                            Form Upload Bukti Transfer
                        </div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data" id="formUpload">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="pembayaran_id" value="<?php echo $pembayaran_data['pembayaran_id']; ?>">
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Tanggal Transfer *</label>
                                        <input type="date" name="tanggal_transfer" class="form-control" 
                                            value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                                        <small class="text-muted">Tanggal saat Anda melakukan transfer</small>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Bukti Transfer (Foto/Screenshot) *</label>
                                        <input type="file" name="bukti_transfer" id="bukti_transfer" 
                                            class="form-control" accept="image/*,.pdf" required>
                                        <small class="text-muted">Format: JPG, PNG, atau PDF (Max 5MB)</small>
                                    </div>
                                </div>
                                
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Penting:</strong> Pastikan bukti transfer yang Anda upload jelas dan dapat dibaca (terlihat nominal, tanggal, dan nama penerima).
                                </div>

                                <div class="d-flex justify-content-between align-items-center pt-3">
                                    <a href="index.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left me-1"></i> Kembali
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-upload me-1"></i> Upload Bukti Transfer
                                    </button>
                                </div>
                            </form>
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
    // Validasi ukuran file
    document.getElementById('bukti_transfer').addEventListener('change', function() {
        const file = this.files[0];
        if (file) {
            const fileSize = file.size / 1024 / 1024; // in MB
            if (fileSize > 5) {
                alert('Ukuran file terlalu besar! Maksimal 5MB.');
                this.value = '';
            }
        }
    });
    
    // Auto hide alerts
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert-dismissible');
        alerts.forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.style.display = 'none', 500);
        }, 5000);
    });
    </script>
</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>