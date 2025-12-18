<?php
require 'check_login.php';
requireHeadAdmin();

require 'config.php';
date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$current_user_id = getUserId();
$current_user_name = getUserFullName();
$current_user_role_id = getUserRoleId();
$current_user_cabang_id = getUserCabangId();
$current_user_cabang_name = $_SESSION['cabang_name'] ?? 'Semua Cabang';

$message = '';
$message_type = '';

// Handle Approve/Reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = 'Invalid security token!';
        $message_type = 'danger';
    } else {
        $action = $_POST['action'];
        $pembayaran_id = (int)$_POST['pembayaran_id'];
        
        try {
            if ($action === 'approve') {
                // ✅ Update status pembayaran jadi verified (JANGAN HAPUS BUKTI!)
                $stmt = $conn->prepare("UPDATE pembayaran 
                    SET status_pembayaran = 'verified'
                    WHERE pembayaran_id = ? 
                    AND status_pembayaran = 'waiting_verification'");
                $stmt->bind_param("i", $pembayaran_id);
                
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $message = 'Pembayaran berhasil diverifikasi!';
                    $message_type = 'success';
                } else {
                    $message = 'Gagal verifikasi pembayaran! Pastikan status masih waiting_verification.';
                    $message_type = 'danger';
                }
                $stmt->close();
                
            } elseif ($action === 'reject') {
                // ✅ Update status pembayaran jadi pending (BIAR BISA UPLOAD ULANG)
                $stmt = $conn->prepare("UPDATE pembayaran 
                    SET status_pembayaran = 'waiting_verification',
                        bukti_transfer = NULL,
                        tanggal_transfer = NULL
                    WHERE pembayaran_id = ? 
                    AND status_pembayaran = 'waiting_verification'");
                $stmt->bind_param("i", $pembayaran_id);
                
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    $message = 'Pembayaran telah ditolak. Orang tua bisa upload ulang.';
                    $message_type = 'warning';
                } else {
                    $message = 'Gagal menolak pembayaran!';
                    $message_type = 'danger';
                }
                $stmt->close();
            }
            
        } catch (Exception $e) {
            error_log("Database error: " . $e->getMessage());
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'danger';
        }
    }
}

// Ambil daftar pembayaran pending
$pembayaran_pending = [];
try {
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            s.name as nama_siswa,
            s.no_telp,
            CONCAT(jl.name, ' - ', tl.name, ' - ', jt.nama_jenistingkat) as nama_paket,
            c.nama_cabang,
            tl.jumlahpertemuan
        FROM pembayaran p
        INNER JOIN siswa s ON p.siswa_id = s.siswa_id
        INNER JOIN datales d ON p.datales_id = d.datales_id
        INNER JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
        INNER JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        INNER JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        INNER JOIN cabang c ON d.cabang_id = c.cabang_id
        WHERE p.status_pembayaran = 'waiting_verification'
          AND p.bukti_transfer IS NOT NULL
        ORDER BY p.tanggal_transfer DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $pembayaran_pending[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $message = 'Error loading data: ' . $e->getMessage();
    $message_type = 'danger';
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Verifikasi Pembayaran - Jia Jia Education</title>
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
    <style>
        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
            padding: 0.35rem 0.65rem;
            border-radius: 0.25rem;
            font-weight: 600;
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

                        <div class="sb-sidenav-menu-heading">Pembayaran</div>
                        <a class="nav-link active" href="verifikasi_pembayaran.php">
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
                    <h1 class="mt-4">Verifikasi Pembayaran</h1>
                    <br>

                    <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="fas fa-clock me-1"></i>
                            Pembayaran Menunggu Verifikasi
                            <?php if (count($pembayaran_pending) > 0): ?>
                            <span class="badge bg-warning ms-2"><?php echo count($pembayaran_pending); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($pembayaran_pending)): ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-check-circle me-2"></i>
                                Tidak ada pembayaran yang menunggu verifikasi.
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="50">No</th>
                                            <th>Tanggal Upload</th>
                                            <th>Nama Siswa</th>
                                            <th>Paket Kelas</th>
                                            <th>Cabang</th>
                                            <th>Jumlah Bayar</th>
                                            <th>Status</th>
                                            <th width="200">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pembayaran_pending as $index => $item): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td>
                                                <?php 
                                                $date = new DateTime($item['tanggal_transfer']);
                                                echo $date->format('d M Y'); 
                                                ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['nama_siswa']); ?></strong>
                                                <?php if ($item['no_telp']): ?>
                                                <br><small class="text-muted"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($item['no_telp']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($item['nama_paket']); ?></td>
                                            <td><?php echo htmlspecialchars($item['nama_cabang']); ?></td>
                                            <td class="fw-bold">Rp <?php echo number_format($item['jumlah_bayar'], 0, ',', '.'); ?></td>
                                            <td>
                                                <span class="badge-pending">
                                                    <i class="fas fa-clock me-1"></i>Pending
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary mb-1" 
                                                        onclick="viewBukti(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                                    <i class="fas fa-eye"></i> Bukti
                                                </button>
                                                <button class="btn btn-sm btn-success mb-1" 
                                                        onclick="approvePayment(<?php echo $item['pembayaran_id']; ?>, '<?php echo htmlspecialchars($item['nama_siswa']); ?>')">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                                <button class="btn btn-sm btn-danger mb-1" 
                                                        onclick="rejectPayment(<?php echo $item['pembayaran_id']; ?>, '<?php echo htmlspecialchars($item['nama_siswa']); ?>')">
                                                    <i class="fas fa-times"></i> Reject
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
                        <div class="text-muted">Copyright &copy; Jia Jia Education <?php echo date('Y'); ?></div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <!-- Modal Bukti Transfer -->
    <div class="modal fade" id="buktiModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-invoice me-2"></i>
                        Bukti Transfer
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Nama Siswa</label>
                            <p class="form-control-plaintext" id="bukti_nama_siswa"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Tanggal Transfer</label>
                            <p class="form-control-plaintext" id="bukti_tanggal"></p>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Paket Kelas</label>
                            <p class="form-control-plaintext" id="bukti_paket"></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Jumlah Bayar</label>
                            <p class="form-control-plaintext" id="bukti_jumlah"></p>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Bukti Transfer</label>
                        <div id="bukti_transfer_content"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Approve -->
    <form method="POST" id="approveForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="pembayaran_id" id="approve_pembayaran_id">
    </form>

    <!-- Form Reject -->
    <form method="POST" id="rejectForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="pembayaran_id" id="reject_pembayaran_id">
    </form>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/scripts.js"></script>
    
    <script>
    function viewBukti(data) {
        document.getElementById('bukti_nama_siswa').textContent = data.nama_siswa;
        
        const transferDate = new Date(data.tanggal_transfer);
        document.getElementById('bukti_tanggal').textContent = 
            transferDate.toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
        
        document.getElementById('bukti_paket').textContent = data.nama_paket;
        document.getElementById('bukti_jumlah').textContent = 
            'Rp ' + parseInt(data.jumlah_bayar).toLocaleString('id-ID');
        
        const fileExt = data.bukti_transfer.split('.').pop().toLowerCase();
        let buktiHtml = '';
        
        if (fileExt === 'pdf') {
            buktiHtml = `
                <a href="${data.bukti_transfer}" target="_blank" class="btn btn-outline-primary">
                    <i class="fas fa-file-pdf me-1"></i> Buka PDF di Tab Baru
                </a>
                <iframe src="${data.bukti_transfer}" style="width:100%; height:500px; border:1px solid #ddd; margin-top:10px;"></iframe>
            `;
        } else {
            buktiHtml = `
                <a href="${data.bukti_transfer}" target="_blank">
                    <img src="${data.bukti_transfer}" class="img-fluid rounded border" style="max-width:100%;">
                </a>
            `;
        }
        document.getElementById('bukti_transfer_content').innerHTML = buktiHtml;
        
        new bootstrap.Modal(document.getElementById('buktiModal')).show();
    }
    
    function approvePayment(id, nama) {
        if (confirm('Approve pembayaran untuk siswa "' + nama + '"?')) {
            document.getElementById('approve_pembayaran_id').value = id;
            document.getElementById('approveForm').submit();
        }
    }
    
    function rejectPayment(id, nama) {
        if (confirm('Reject pembayaran untuk siswa "' + nama + '"?\n\nOrang tua harus upload ulang bukti transfer.')) {
            document.getElementById('reject_pembayaran_id').value = id;
            document.getElementById('rejectForm').submit();
        }
    }
    
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