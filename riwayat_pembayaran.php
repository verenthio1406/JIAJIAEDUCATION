<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'check_login.php';

// Head Admin (1) dan Orang Tua (3) bisa akses
if (!in_array(getUserRoleId(), [1, 3])) {
    header("Location: index.php?error=unauthorized");
    exit();
}

require 'config.php';
date_default_timezone_set('Asia/Jakarta');

$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = getUserRoleId();
$current_user_cabang_id = getUserCabangId();
$current_user_cabang_name = $_SESSION['cabang_name'] ?? '';

$flash_message = $_SESSION['flash_message'] ?? '';
$flash_type = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

// Ambil riwayat pembayaran
$pembayaran_list = [];
try {
    if ($current_user_role_id == 1) {
        // HEAD ADMIN: Lihat hanya transaksi yang VERIFIED (sudah diapprove)
        $stmt = $conn->prepare("
            SELECT 
                p.*,
                s.name as nama_siswa,
                COALESCE(CONCAT(jl.name, ' - ', tl.name, ' - ', jt.nama_jenistingkat), 'Paket tidak ditemukan') as nama_paket,
                COALESCE(c.nama_cabang, '-') as nama_cabang,
                COALESCE(tl.jumlahpertemuan, 0) as total_pertemuan
            FROM pembayaran p
            INNER JOIN siswa s ON p.siswa_id = s.siswa_id
            LEFT JOIN datales d ON p.datales_id = d.datales_id
            LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
            LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
            LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
            LEFT JOIN cabang c ON s.cabang_id = c.cabang_id
            WHERE p.status_pembayaran = 'verified'
            ORDER BY p.is_archived ASC, p.pembayaran_id DESC
        ");
        $stmt->execute();
        
    } else {
        // ORANG TUA: Lihat SEMUA transaksi yang dia upload
        $current_siswa_id = getSiswaId();
        
        if ($current_siswa_id) {
            $stmt = $conn->prepare("
                SELECT 
                    p.*,
                    s.name as nama_siswa,
                    COALESCE(CONCAT(jl.name, ' - ', tl.name, ' - ', jt.nama_jenistingkat), 'Paket tidak ditemukan') as nama_paket,
                    COALESCE(c.nama_cabang, '-') as nama_cabang,
                    COALESCE(tl.jumlahpertemuan, 0) as total_pertemuan
                FROM pembayaran p
                INNER JOIN siswa s ON p.siswa_id = s.siswa_id
                LEFT JOIN datales d ON p.datales_id = d.datales_id
                LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
                LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
                LEFT JOIN cabang c ON s.cabang_id = c.cabang_id
                WHERE p.siswa_id = ?
                  AND (p.is_archived = 0 OR p.is_archived IS NULL)
                  AND p.status_pembayaran IN ('waiting_verification', 'verified', 'rejected')
                ORDER BY 
                    CASE 
                        WHEN p.status_pembayaran = 'waiting_verification' THEN 1
                        WHEN p.status_pembayaran = 'verified' THEN 2
                        WHEN p.status_pembayaran = 'rejected' THEN 3
                        ELSE 4
                    END,
                    p.pembayaran_id DESC
            ");
            $stmt->bind_param("i", $current_siswa_id);
            $stmt->execute();
        }
    }
    
    if (isset($stmt)) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pembayaran_list[] = $row;
        }
        $stmt->close();
    }
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Riwayat Pembayaran - Jia Jia Education</title>
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
    <style>
        .status-badge {
            padding: 0.35rem 0.65rem;
            border-radius: 0.25rem;
            font-weight: 600;
            font-size: 0.875rem;
        }
        .status-waiting {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .status-verified {
            background-color: #d1e7dd;
            color: #0f5132;
            border: 1px solid #a3cfbb;
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
                        <a class="nav-link" href="absensi.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-clipboard-check"></i></div>
                            Presensi
                        </a>
                        <a class="nav-link" href="kelola_semester.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-sync-alt"></i></div>
                            Kelola Semester
                        </a>
                        <?php endif; ?>

                        <div class="sb-sidenav-menu-heading">Pembayaran</div>
                        <?php if ($current_user_role_id == 1): ?>
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
                        <a class="nav-link active" href="riwayat_pembayaran.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-history"></i></div>
                            Riwayat Pembayaran
                        </a>

                        <?php if ($current_user_role_id <= 2): ?>
                        <div class="sb-sidenav-menu-heading">Manage</div>
                        <a class="nav-link" href="siswa.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-user"></i></div>
                            Siswa
                        </a>
                        <a class="nav-link" href="guru.php">
                            <div class="sb-nav-link-icon"><i class="fas fa-chalkboard-teacher"></i></div>
                            Guru
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($current_user_role_id == 1): ?>
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
                    <h1 class="mt-4">Riwayat Pembayaran</h1>
                    <?php if ($current_user_role_id == 1): ?>
                    <p class="text-muted">Semua pembayaran yang sudah diverifikasi</p>
                    <?php else: ?>
                    <p class="text-muted">Riwayat pembayaran Anda</p>
                    <?php endif; ?>

                    <?php if (!empty($flash_message)): ?>
                    <div class="alert alert-<?php echo $flash_type; ?> alert-dismissible fade show">
                        <?php echo htmlspecialchars($flash_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-history me-1"></i>History Pembayaran</span>
                            <?php if ($current_user_role_id == 3): ?>
                            <a href="pembayaran.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus me-1"></i> Upload Pembayaran Baru
                            </a>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pembayaran_list)): ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle me-2"></i>
                                <?php if ($current_user_role_id == 1): ?>
                                Belum ada pembayaran yang diverifikasi.
                                <?php else: ?>
                                Belum ada riwayat pembayaran. <a href="pembayaran.php">Upload pembayaran pertama!</a>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="40">No</th>
                                            <th>Tanggal</th>
                                            <?php if ($current_user_role_id == 1): ?>
                                            <th>Siswa</th>
                                            <?php endif; ?>
                                            <th>Paket</th>
                                            <th>Cabang</th>
                                            <th>Jumlah</th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pembayaran_list as $i => $item): ?>
                                        <tr>
                                            <td><?php echo $i + 1; ?></td>
                                            <td><?php echo !empty($item['tanggal_transfer']) ? date('d M Y', strtotime($item['tanggal_transfer'])) : '-'; ?></td>
                                            <?php if ($current_user_role_id == 1): ?>
                                            <td><?php echo htmlspecialchars($item['nama_siswa']); ?></td>
                                            <?php endif; ?>
                                            <td><?php echo htmlspecialchars($item['nama_paket']); ?></td>
                                            <td><?php echo htmlspecialchars($item['nama_cabang']); ?></td>
                                            <td class="fw-bold">Rp <?php echo number_format($item['jumlah_bayar'], 0, ',', '.'); ?></td>
                                            <td>
                                                <?php if ($item['status_pembayaran'] === 'waiting_verification'): ?>
                                                <span class="status-badge status-waiting">
                                                    <i class="fas fa-clock me-1"></i>Menunggu
                                                </span>
                                                <?php elseif ($item['status_pembayaran'] === 'verified'): ?>
                                                <span class="status-badge status-verified">
                                                    <i class="fas fa-check-circle me-1"></i>Terverifikasi
                                                </span>
                                                <?php else: ?>
                                                <span class="status-badge status-rejected">
                                                    <i class="fas fa-times-circle me-1"></i>Ditolak
                                                </span>
                                                <?php endif; ?>
                                                
                                                <?php if ($item['is_archived'] == 1): ?>
                                                <br><span class="badge bg-secondary mt-1"><i class="fas fa-archive"></i> Archived</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="viewDetail(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                                    <i class="fas fa-eye"></i> Detail
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Total: <?php echo count($pembayaran_list); ?> pembayaran
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
            
            <footer class="py-4 bg-light mt-auto">
                <div class="container-fluid px-4">
                    <div class="text-muted small">Copyright &copy; Jia Jia Education <?php echo date('Y'); ?></div>
                </div>
            </footer>
        </div>
    </div>

    <!-- Modal Detail -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Detail Pembayaran</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Nama Siswa</label>
                            <p id="detail_nama_siswa"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Paket Kelas</label>
                            <p id="detail_paket"></p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Tanggal Transfer</label>
                            <p id="detail_tanggal"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Jumlah Bayar</label>
                            <p id="detail_jumlah"></p>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Total Pertemuan</label>
                            <p id="detail_pertemuan"></p>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="fw-bold">Status</label>
                            <p id="detail_status"></p>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="fw-bold">Bukti Transfer</label>
                        <div id="detail_bukti"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/scripts.js"></script>
    
    <script>
    function viewDetail(data) {
        document.getElementById('detail_nama_siswa').textContent = data.nama_siswa;
        document.getElementById('detail_paket').textContent = data.nama_paket;
        document.getElementById('detail_tanggal').textContent = new Date(data.tanggal_transfer).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
        document.getElementById('detail_jumlah').textContent = 'Rp ' + parseInt(data.jumlah_bayar).toLocaleString('id-ID');
        document.getElementById('detail_pertemuan').textContent = data.total_pertemuan + ' pertemuan';
        
        // Status
        let statusHtml = data.status_pembayaran === 'waiting_verification' 
            ? '<span class="status-badge status-waiting"><i class="fas fa-clock me-1"></i>Menunggu Verifikasi</span>'
            : '<span class="status-badge status-verified"><i class="fas fa-check-circle me-1"></i>Terverifikasi</span>';
        document.getElementById('detail_status').innerHTML = statusHtml;
        
        // Bukti
        if (data.bukti_transfer) {
            const ext = data.bukti_transfer.split('.').pop().toLowerCase();
            if (ext === 'pdf') {
                document.getElementById('detail_bukti').innerHTML = `<a href="${data.bukti_transfer}" target="_blank" class="btn btn-outline-primary"><i class="fas fa-file-pdf me-1"></i> Lihat PDF</a>`;
            } else {
                document.getElementById('detail_bukti').innerHTML = `<a href="${data.bukti_transfer}" target="_blank"><img src="${data.bukti_transfer}" class="img-fluid rounded border" style="max-height:300px;"></a>`;
            }
        } else {
            document.getElementById('detail_bukti').innerHTML = '<em class="text-muted">Tidak ada bukti</em>';
        }
        
        new bootstrap.Modal(document.getElementById('detailModal')).show();
    }
    
    setTimeout(() => {
        document.querySelectorAll('.alert-dismissible').forEach(alert => new bootstrap.Alert(alert).close());
    }, 5000);
    </script>
</body>
</html>

<?php if (isset($conn)) mysqli_close($conn); ?>