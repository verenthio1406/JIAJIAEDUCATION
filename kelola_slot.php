<?php
require 'check_login.php';
requireAdmin(); // Admin & Head Admin bisa akses

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
$current_user_cabang_name = $_SESSION['cabang_name'] ?? 'Semua Cabang';

// Ambil SEMUA cabang user (untuk Admin yang punya multiple cabang)
$user_cabang_ids = [];
if ($current_user_role_id == 2) { // Admin
    try {
        $stmt = $conn->prepare("SELECT cabang_id FROM user_cabang WHERE user_id = ?");
        $stmt->bind_param("i", $current_user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $user_cabang_ids[] = $row['cabang_id'];
        }
        $stmt->close();
        
        // ✅ DEBUG
        error_log("=== DEBUG ADMIN CABANG ===");
        error_log("User ID: $current_user_id");
        error_log("User Name: $current_user_name");
        error_log("Cabang IDs: " . implode(', ', $user_cabang_ids));
        error_log("========================");
        
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());
    }
}

$message = '';
$message_type = '';

// Handle success messages from redirect
if (isset($_GET['success'])) {
    $allowed_success = ['slot_added', 'slot_updated', 'slot_deleted', 'status_changed'];
    if (in_array($_GET['success'], $allowed_success)) {
        switch($_GET['success']) {
            case 'slot_added':
                $message = 'Slot jadwal berhasil ditambahkan!';
                $message_type = 'success';
                break;
            case 'slot_updated':
                $message = 'Slot jadwal berhasil diupdate!';
                $message_type = 'success';
                break;
            case 'slot_deleted':
                $message = 'Slot jadwal berhasil dihapus!';
                $message_type = 'success';
                break;
            case 'status_changed':
                $message = 'Status slot berhasil diubah!';
                $message_type = 'success';
                break;
        }
    }
}

if (isset($_GET['error'])) {
    $allowed_errors = ['invalid_token', 'add_failed', 'update_failed', 'delete_failed', 'status_failed', 'slot_in_use', 'database_error'];
    if (in_array($_GET['error'], $allowed_errors)) {
        switch($_GET['error']) {
            case 'invalid_token':
                $message = 'Invalid security token!';
                $message_type = 'danger';
                break;
            case 'slot_in_use':
                $message = 'Slot tidak bisa dihapus karena sudah digunakan di jadwal pertemuan!';
                $message_type = 'warning';
                break;
            case 'database_error':
                $message = 'Terjadi kesalahan database!';
                $message_type = 'danger';
                break;
            default:
                $message = 'Jadwal sudah terdaftar! Silakan coba lagi.';
                $message_type = 'danger';
                break;
        }
    }
}

// Fetch Jenis Les
$jenisles_list = [];
try {
    $result = $conn->query("SELECT jenisles_id, name FROM jenisles ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $jenisles_list[] = $row;
    }
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

// Fetch Tipe Les dengan Jenis Les ID
$tipeles_list = [];
try {
    $result = $conn->query("SELECT tipeles_id, jenisles_id, name, jumlahpertemuan FROM tipeles ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $tipeles_list[] = $row;
    }
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

// Fetch Tingkatan dengan Tipe Les ID
$tingkatan_list = [];
try {
    $result = $conn->query("SELECT jenistingkat_id, tipeles_id, nama_jenistingkat FROM jenistingkat ORDER BY nama_jenistingkat");
    while ($row = $result->fetch_assoc()) {
        $tingkatan_list[] = $row;
    }
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

// Handle CRUD Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        header("Location: kelola_slot.php?error=invalid_token");
        exit();
    } else {
        $action = $_POST['action'];
        
        if ($action === 'add') {

            $guru_paket = $_POST['guru_paket']; // "cabangguruID|jenistingkat_id"
            list($cabangguruID, $jenistingkat_id) = explode('|', $guru_paket);

            $cabangguruID = (int)$cabangguruID;
            $jenistingkat_id = (int)$jenistingkat_id;
            $hari = $_POST['hari'];
            $jam_mulai = $_POST['jam_mulai'];
            $jam_selesai = $_POST['jam_selesai'];
            $kapasitas_maksimal = (int)$_POST['kapasitas_maksimal'];

            try {
                $check_stmt = $conn->prepare("
                    SELECT slot_id
                    FROM jadwal_slot
                    WHERE cabangguruID = ?
                    AND jenistingkat_id = ?
                    AND hari = ?
                    AND jam_mulai = ?
                    AND jam_selesai = ?
                    AND status = 'aktif'
                    LIMIT 1
                ");
                $check_stmt->bind_param(
                    "iisss",
                    $cabangguruID,
                    $jenistingkat_id,
                    $hari,
                    $jam_mulai,
                    $jam_selesai
                );
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();

                if ($check_result->num_rows > 0) {
                    $check_stmt->close();
                    header("Location: kelola_slot.php?error=add_failed");
                    exit();
                }
                $check_stmt->close();

                $stmt_tipe = $conn->prepare("
                    SELECT 
                        CASE 
                            WHEN LOWER(tl.name) LIKE '%private%' THEN 'private'
                            WHEN LOWER(tl.name) LIKE '%group%' THEN 'group'
                            ELSE 'private'
                        END as tipe_kelas
                    FROM jenistingkat jt
                    JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                    WHERE jt.jenistingkat_id = ?
                ");
                $stmt_tipe->bind_param("i", $jenistingkat_id);
                $stmt_tipe->execute();
                $result_tipe = $stmt_tipe->get_result();
                $tipe_row = $result_tipe->fetch_assoc();
                $stmt_tipe->close();

                $tipe_kelas = $tipe_row['tipe_kelas'] ?? 'private';

                $stmt = $conn->prepare("
                    INSERT INTO jadwal_slot 
                    (cabangguruID, jenistingkat_id, hari, jam_mulai, jam_selesai, tipe_kelas, kapasitas_maksimal, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'aktif')
                ");
                $stmt->bind_param(
                    "iissssi",
                    $cabangguruID,
                    $jenistingkat_id,
                    $hari,
                    $jam_mulai,
                    $jam_selesai,
                    $tipe_kelas,
                    $kapasitas_maksimal
                );

                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: kelola_slot.php?success=slot_added");
                    exit();
                } else {
                    $stmt->close();
                    header("Location: kelola_slot.php?error=add_failed");
                    exit();
                }

            } catch (Exception $e) {
                error_log("Error add slot: " . $e->getMessage());
                header("Location: kelola_slot.php?error=database_error");
                exit();
            }
            
        } elseif ($action === 'edit') {
            $slot_id = (int)$_POST['slot_id'];
            $guru_paket = $_POST['guru_paket'];
            list($cabangguruID, $jenistingkat_id) = explode('|', $guru_paket);
            
            $cabangguruID = (int)$cabangguruID;
            $jenistingkat_id = (int)$jenistingkat_id;
            $hari = $_POST['hari'];
            $jam_mulai = $_POST['jam_mulai'];
            $jam_selesai = $_POST['jam_selesai'];
            $kapasitas_maksimal = (int)$_POST['kapasitas_maksimal'];
            $change_reason = isset($_POST['change_reason']) && !empty($_POST['change_reason']) 
                ? trim($_POST['change_reason']) 
                : 'Perubahan jadwal slot';
            
            try {
                // ✅ BACKUP DATA LAMA KE HISTORY SEBELUM UPDATE
                $stmt_backup = $conn->prepare("
                    INSERT INTO jadwal_slot_history 
                    (slot_id, cabangguruID, jenistingkat_id, hari, jam_mulai, jam_selesai, 
                     tipe_kelas, kapasitas_maksimal, status, changed_by, change_reason)
                    SELECT 
                        slot_id, cabangguruID, jenistingkat_id, hari, jam_mulai, jam_selesai, 
                        tipe_kelas, kapasitas_maksimal, status, ?, ?
                    FROM jadwal_slot
                    WHERE slot_id = ?
                ");
                $stmt_backup->bind_param("isi", $current_user_id, $change_reason, $slot_id);
                $stmt_backup->execute();
                $stmt_backup->close();
                
                // Get tipe_kelas
                $stmt_tipe = $conn->prepare("
                    SELECT 
                        CASE 
                            WHEN LOWER(tl.name) LIKE '%private%' THEN 'private'
                            WHEN LOWER(tl.name) LIKE '%group%' THEN 'group'
                            ELSE 'private'
                        END as tipe_kelas
                    FROM jenistingkat jt
                    JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
                    WHERE jt.jenistingkat_id = ?
                ");
                $stmt_tipe->bind_param("i", $jenistingkat_id);
                $stmt_tipe->execute();
                $result_tipe = $stmt_tipe->get_result();
                $tipe_row = $result_tipe->fetch_assoc();
                $stmt_tipe->close();
                
                $tipe_kelas = $tipe_row['tipe_kelas'] ?? 'private';
                
                $stmt = $conn->prepare("
                    UPDATE jadwal_slot 
                    SET cabangguruID = ?, jenistingkat_id = ?, hari = ?, jam_mulai = ?, jam_selesai = ?, 
                        tipe_kelas = ?, kapasitas_maksimal = ? 
                    WHERE slot_id = ?
                ");
                $stmt->bind_param("iissssii", $cabangguruID, $jenistingkat_id, $hari, $jam_mulai, $jam_selesai, 
                    $tipe_kelas, $kapasitas_maksimal, $slot_id);
                
                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: kelola_slot.php?success=slot_updated");
                    exit();
                } else {
                    $stmt->close();
                    header("Location: kelola_slot.php?error=update_failed");
                    exit();
                }
            } catch (Exception $e) {
                error_log("Error: " . $e->getMessage());
                header("Location: kelola_slot.php?error=database_error");
                exit();
            }            
        } elseif ($action === 'toggle_status') {
            $slot_id = (int)$_POST['slot_id'];
            $new_status = $_POST['new_status'];
            
            try {
                $stmt = $conn->prepare("UPDATE jadwal_slot SET status = ? WHERE slot_id = ?");
                $stmt->bind_param("si", $new_status, $slot_id);
                
                if ($stmt->execute()) {
                    $stmt->close();
                    header("Location: kelola_slot.php?success=status_changed");
                    exit();
                } else {
                    $stmt->close();
                    header("Location: kelola_slot.php?error=status_failed");
                    exit();
                }
            } catch (Exception $e) {
                error_log("Error: " . $e->getMessage());
                header("Location: kelola_slot.php?error=database_error");
                exit();
            }
            
        } elseif ($action === 'delete') {
            $slot_id = (int)$_POST['slot_id'];
            
            try {
                // ✅ BACKUP DATA LAMA KE HISTORY SEBELUM DELETE
                $change_reason = 'Slot dihapus oleh ' . $current_user_name;
                $stmt_backup = $conn->prepare("
                    INSERT INTO jadwal_slot_history 
                    (slot_id, cabangguruID, jenistingkat_id, hari, jam_mulai, jam_selesai, 
                     tipe_kelas, kapasitas_maksimal, status, changed_by, change_reason)
                    SELECT 
                        slot_id, cabangguruID, jenistingkat_id, hari, jam_mulai, jam_selesai, 
                        tipe_kelas, kapasitas_maksimal, status, ?, ?
                    FROM jadwal_slot
                    WHERE slot_id = ?
                ");
                $stmt_backup->bind_param("isi", $current_user_id, $change_reason, $slot_id);
                $stmt_backup->execute();
                $stmt_backup->close();
                
                // Check apakah slot sudah digunakan di jadwal_pertemuan
                $stmt_check = $conn->prepare("SELECT COUNT(*) as count FROM jadwal_pertemuan WHERE slot_id = ?");
                $stmt_check->bind_param("i", $slot_id);
                $stmt_check->execute();
                $result = $stmt_check->get_result()->fetch_assoc();
                $stmt_check->close();
                
                if ($result['count'] > 0) {
                    header("Location: kelola_slot.php?error=slot_in_use");
                    exit();
                } else {
                    $stmt = $conn->prepare("DELETE FROM jadwal_slot WHERE slot_id = ?");
                    $stmt->bind_param("i", $slot_id);
                    
                    if ($stmt->execute()) {
                        $stmt->close();
                        header("Location: kelola_slot.php?success=slot_deleted");
                        exit();
                    } else {
                        $stmt->close();
                        header("Location: kelola_slot.php?error=delete_failed");
                        exit();
                    }
                }
            } catch (Exception $e) {
                error_log("Error: " . $e->getMessage());
                header("Location: kelola_slot.php?error=database_error");
                exit();
            }
        }
    }
}

// Filter
$filter_guru = isset($_GET['guru_id']) ? (int)$_GET['guru_id'] : null;
$filter_cabang = isset($_GET['cabang_id']) ? (int)$_GET['cabang_id'] : null;
$filter_hari = isset($_GET['hari']) ? $_GET['hari'] : '';

// Query slot dengan filter
$slot_list = [];
$slot_siswa_data = []; 

try {
    $query = "
        SELECT 
            js.*,
            g.nama_guru,
            c.nama_cabang,
            cg.guru_id,
            cg.cabang_id,
            jl.name as jenisles_name,
            tl.name as tipeles_name,
            jt.nama_jenistingkat,
            tl.jumlahpertemuan
        FROM jadwal_slot js
        INNER JOIN cabangGuru cg ON js.cabangguruID = cg.id
        INNER JOIN guru g ON cg.guru_id = g.guru_id
        INNER JOIN cabang c ON cg.cabang_id = c.cabang_id
        LEFT JOIN jenistingkat jt ON js.jenistingkat_id = jt.jenistingkat_id
        LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        WHERE 1=1
    ";
    
    $params = [];
    $types = '';

    // ✅ Filter by cabang untuk Admin (MULTIPLE CABANG)
    if ($current_user_role_id == 2 && !empty($user_cabang_ids)) {
        $placeholders = implode(',', array_fill(0, count($user_cabang_ids), '?'));
        $query .= " AND cg.cabang_id IN ($placeholders)";
        foreach ($user_cabang_ids as $cabang_id) {
            $params[] = $cabang_id;
            $types .= 'i';
        }
    }
    
    // ✅ Filter by guru (dari dropdown)
    if ($filter_guru) {
        $query .= " AND cg.guru_id = ?";
        $params[] = $filter_guru;
        $types .= 'i';
    }
    
    // ✅ Filter by cabang (dari dropdown) - INI YANG PENTING!
    if ($filter_cabang) {
        $query .= " AND cg.cabang_id = ?";
        $params[] = $filter_cabang;
        $types .= 'i';
    }
    
    // ✅ Filter by hari (dari dropdown)
    if ($filter_hari) {
        $query .= " AND js.hari = ?";
        $params[] = $filter_hari;
        $types .= 's';
    }
    
    $query .= " ORDER BY FIELD(js.hari, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), js.jam_mulai";
    
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $slot_list[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

// Ambil daftar cabang untuk dropdown (FILTER BY ROLE)
$cabang_list = [];
try {
    if ($current_user_role_id == 1) {
        // Head Admin: Lihat semua cabang
        $stmt = $conn->prepare("SELECT cabang_id, nama_cabang FROM cabang ORDER BY nama_cabang");
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $cabang_list[] = $row;
        }
        $stmt->close();
        
    } else {
        // Admin: Hanya cabang yang dia handle
        if (!empty($user_cabang_ids)) {
            $placeholders = implode(',', array_fill(0, count($user_cabang_ids), '?'));
            $query = "SELECT cabang_id, nama_cabang FROM cabang WHERE cabang_id IN ($placeholders) ORDER BY nama_cabang";
            $stmt = $conn->prepare($query);
            $types = str_repeat('i', count($user_cabang_ids));
            $stmt->bind_param($types, ...$user_cabang_ids);
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $cabang_list[] = $row;
            }
            $stmt->close();
        }
    }
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

// Ambil kombinasi guru-cabang untuk dropdown (FILTER BY CABANG UNTUK ADMIN)
$cabangguru_list = [];
try {
    $query_cg = "
        SELECT 
            cg.id as cabangguruID,
            g.nama_guru,
            c.nama_cabang,
            cg.cabang_id
        FROM cabangGuru cg
        INNER JOIN guru g ON cg.guru_id = g.guru_id
        INNER JOIN cabang c ON cg.cabang_id = c.cabang_id
        WHERE g.status = 'aktif'
    ";
    
    // Filter by cabang untuk Admin (MULTIPLE CABANG)
    if ($current_user_role_id == 2 && !empty($user_cabang_ids)) {
        $placeholders = implode(',', array_fill(0, count($user_cabang_ids), '?'));
        $query_cg .= " AND cg.cabang_id IN ($placeholders)";
        
        $query_cg .= " ORDER BY g.nama_guru, c.nama_cabang";
        
        $stmt_cg = $conn->prepare($query_cg);
        $types = str_repeat('i', count($user_cabang_ids));
        $stmt_cg->bind_param($types, ...$user_cabang_ids);
        $stmt_cg->execute();
        $result = $stmt_cg->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $cabangguru_list[] = $row;
        }
        
        $stmt_cg->close();
    } else {
        $query_cg .= " ORDER BY g.nama_guru, c.nama_cabang";
        $result = $conn->query($query_cg);
        
        while ($row = $result->fetch_assoc()) {
            $cabangguru_list[] = $row;
        }
    }
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

// Ambil kombinasi guru-cabang-paket yang sudah diassign
$guru_paket_list = [];
try {
    // ✅ QUERY SIMPLE - TANPA FILTER DULU
    $query_gp = "
        SELECT 
            cg.id as cabangguruID,
            g.nama_guru,
            c.nama_cabang,
            cg.cabang_id,
            gd.id as guru_datales_id,
            d.datales_id,
            d.jenistingkat_id,
            CONCAT(jl.name, ' - ', tl.name, ' - ', jt.nama_jenistingkat) as nama_paket,
            CASE 
                WHEN LOWER(tl.name) LIKE '%private%' THEN 'private'
                WHEN LOWER(tl.name) LIKE '%group%' THEN 'group'
                ELSE 'private'
            END as tipe_kelas,
            tl.jumlahpertemuan
        FROM guru_datales gd
        INNER JOIN guru g ON gd.guru_id = g.guru_id
        INNER JOIN cabang c ON gd.cabang_id = c.cabang_id
        INNER JOIN cabangGuru cg ON (cg.guru_id = gd.guru_id AND cg.cabang_id = gd.cabang_id)
        INNER JOIN datales d ON gd.datales_id = d.datales_id
        INNER JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
        INNER JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        INNER JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        WHERE g.status = 'aktif'
        ORDER BY g.nama_guru, c.nama_cabang, jl.name
    ";
    
    $result = $conn->query($query_gp);
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $guru_paket_list[] = $row;
        }
    }
    
    // ✅ DEBUG
    error_log("=== DEBUG GURU PAKET LIST ===");
    error_log("Total data: " . count($guru_paket_list));
    if (count($guru_paket_list) > 0) {
        error_log("Sample data:");
        error_log(print_r($guru_paket_list[0], true));
    }
    error_log("============================");
    
} catch (Exception $e) {
    error_log("Error guru_paket_list: " . $e->getMessage());
}

// FIXED: Query slot dengan COUNT DISTINCT siswa_id
$slot_list = [];
$slot_siswa_data = []; 

try {
    $query = "
        SELECT 
            js.*,
            g.nama_guru,
            c.nama_cabang,
            cg.guru_id,
            cg.cabang_id,
            jl.name as jenisles_name,
            tl.name as tipeles_name,
            jt.nama_jenistingkat,
            tl.jumlahpertemuan
        FROM jadwal_slot js
        INNER JOIN cabangGuru cg ON js.cabangguruID = cg.id
        INNER JOIN guru g ON cg.guru_id = g.guru_id
        INNER JOIN cabang c ON cg.cabang_id = c.cabang_id
        LEFT JOIN jenistingkat jt ON js.jenistingkat_id = jt.jenistingkat_id
        LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        WHERE 1=1
    ";
    
    $params = [];
    $types = '';

    // Filter by cabang untuk Admin (MULTIPLE CABANG)
    if ($current_user_role_id == 2 && !empty($user_cabang_ids)) {
        $placeholders = implode(',', array_fill(0, count($user_cabang_ids), '?'));
        $query .= " AND cg.cabang_id IN ($placeholders)";
        foreach ($user_cabang_ids as $cabang_id) {
            $params[] = $cabang_id;
            $types .= 'i';
        }
    }
    
    if ($filter_guru) {
        $query .= " AND cg.guru_id = ?";
        $params[] = $filter_guru;
        $types .= 'i';
    }
    
    if ($filter_cabang) {
        $query .= " AND cg.cabang_id = ?";
        $params[] = $filter_cabang;
        $types .= 'i';
    }
    
    if ($filter_hari) {
        $query .= " AND js.hari = ?";
        $params[] = $filter_hari;
        $types .= 's';
    }
    
    $query .= " ORDER BY FIELD(js.hari, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'), js.jam_mulai";
    
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $slot_list[] = $row;
    }
    $stmt->close();
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Kelola Slot Jadwal - Jia Jia Education</title>
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js"></script>
    <style>
        .badge-aktif { background-color: #198754; color: #fff; }
        .badge-nonaktif { background-color: #6c757d; color: #fff; }
        .badge-private { background-color: #0dcaf0; color: #000; }
        .badge-group { background-color: #ffc107; color: #000; }
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
                        <a class="nav-link active" href="kelola_slot.php">
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
                    <h1 class="mt-4">Kelola Slot Jadwal</h1>
                    <br>

                    <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Filter Section -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <i class="fas fa-filter me-1"></i>
                            Filter Slot Jadwal
                        </div>
                        <div class="card-body">
                            <form method="GET" action="kelola_slot.php">
                                <div class="row">
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Guru</label>
                                        <select name="guru_id" class="form-select">
                                            <option value="">Semua Guru</option>
                                            <?php foreach ($guru_list as $guru): ?>
                                            <option value="<?php echo $guru['guru_id']; ?>" 
                                                <?php echo $filter_guru == $guru['guru_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($guru['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Cabang</label>
                                        <?php if ($current_user_role_id == 1 || count($cabang_list) > 1): ?>
                                            <!-- Head Admin ATAU Admin dengan 2+ cabang: Bisa filter -->
                                            <select name="cabang_id" class="form-select">
                                                <?php if ($current_user_role_id == 1): ?>
                                                    <option value="">Semua Cabang</option>
                                                <?php else: ?>
                                                    <option value="">Semua (<?php echo count($cabang_list); ?> Cabang)</option>
                                                <?php endif; ?>
                                                
                                                <?php foreach ($cabang_list as $cabang): ?>
                                                <option value="<?php echo $cabang['cabang_id']; ?>" 
                                                    <?php echo $filter_cabang == $cabang['cabang_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cabang['nama_cabang']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <!-- Admin dengan 1 cabang: Disabled -->
                                            <select class="form-select" disabled>
                                                <option><?php echo htmlspecialchars($cabang_list[0]['nama_cabang']); ?></option>
                                            </select>
                                            <small class="text-muted">Anda hanya mengelola 1 cabang</small>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">Hari</label>
                                        <select name="hari" class="form-select">
                                            <option value="">Semua Hari</option>
                                            <?php 
                                            $hari_list = ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'];
                                            foreach ($hari_list as $hari): 
                                            ?>
                                            <option value="<?php echo $hari; ?>" 
                                                <?php echo $filter_hari === $hari ? 'selected' : ''; ?>>
                                                <?php echo $hari; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label">&nbsp;</label>
                                        <div>
                                            <button type="submit" class="btn btn-primary w-100">
                                                <i class="fas fa-search me-1"></i> Filter
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12">
                                        <a href="kelola_slot.php" class="btn btn-secondary btn-sm">
                                            <i class="fas fa-redo me-1"></i> Reset Filter
                                        </a>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Slot Table -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>
                                <i class="fas fa-calendar-plus me-1"></i>
                                Daftar Slot Jadwal
                                <?php if (count($slot_list) > 0): ?>
                                <span class="badge bg-dark ms-2"><?php echo count($slot_list); ?></span>
                                <?php endif; ?>
                            </span>
                            <button class="btn btn-dark btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                                <i class="fas fa-plus me-1"></i> Tambah Slot
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if (empty($slot_list)): ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle me-2"></i>
                                Belum ada slot jadwal. Silakan tambah slot baru.
                            </div>
                            <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th width="40">No</th>
                                            <th>Guru</th>
                                            <th>Cabang</th>
                                            <th>Paket</th>
                                            <th>Hari</th>
                                            <th>Jam</th>
                                            <th>Kapasitas</th>
                                            <th>Status</th>
                                            <th>Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($slot_list as $index => $item): ?>
                                        <?php
                                        // Hitung jumlah siswa di slot ini
                                        $stmt_count = $conn->prepare("
                                            SELECT COUNT(DISTINCT siswa_id) as total
                                            FROM siswa_datales
                                            WHERE slot_id = ? AND status = 'aktif' AND is_history = 0
                                        ");
                                        $stmt_count->bind_param("i", $item['slot_id']);
                                        $stmt_count->execute();
                                        $count_data = $stmt_count->get_result()->fetch_assoc();
                                        $stmt_count->close();
                                        
                                        $siswa_count = $count_data['total'];
                                        $kapasitas = $item['kapasitas_maksimal'];
                                        
                                        // Color based on capacity
                                        if ($siswa_count >= $kapasitas) {
                                            $badge_color = 'bg-danger';
                                        } elseif ($siswa_count > 0) {
                                            $badge_color = 'bg-warning text-dark';
                                        } else {
                                            $badge_color = 'bg-info text-dark';
                                        }
                                        
                                        // Simpan data siswa untuk digunakan di modal nanti
                                        $slot_siswa_data[$item['slot_id']] = [];
                                        
                                        $stmt_siswa = $conn->prepare("
                                            SELECT 
                                                s.name as nama_siswa,
                                                sd.semester_ke,
                                                sd.tahun_ajaran,
                                                sd.status
                                            FROM siswa_datales sd
                                            INNER JOIN siswa s ON sd.siswa_id = s.siswa_id
                                            WHERE sd.slot_id = ? AND sd.is_history = 0
                                            ORDER BY s.name ASC
                                        ");
                                        $stmt_siswa->bind_param("i", $item['slot_id']);
                                        $stmt_siswa->execute();
                                        $result_siswa = $stmt_siswa->get_result();
                                        
                                        while ($row = $result_siswa->fetch_assoc()) {
                                            $slot_siswa_data[$item['slot_id']][] = $row;
                                        }
                                        $stmt_siswa->close();
                                        ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($item['nama_guru']); ?></td>
                                            <td><?php echo htmlspecialchars($item['nama_cabang']); ?></td>
                                            <td>
                                                <?php if (!empty($item['jenisles_name'])): ?>
                                                    <strong><?php echo htmlspecialchars($item['jenisles_name']); ?></strong><br>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($item['tipeles_name'] ?? '-'); ?> - 
                                                        <?php echo htmlspecialchars($item['nama_jenistingkat'] ?? '-'); ?>
                                                        <?php if (!empty($item['jumlahpertemuan'])): ?>
                                                            (<?php echo $item['jumlahpertemuan']; ?>x)
                                                        <?php endif; ?>
                                                    </small>
                                                <?php else: ?>
                                                    <em class="text-muted">Belum diset</em>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $item['hari']; ?></td>
                                            <td>
                                                <?php echo substr($item['jam_mulai'], 0, 5); ?> - 
                                                <?php echo substr($item['jam_selesai'], 0, 5); ?>
                                            </td>
                                            <td class="text-center">
                                                <span>
                                                    <?php echo $siswa_count; ?>/<?php echo $kapasitas; ?> siswa
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $item['status']; ?>">
                                                    <?php echo ucfirst($item['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-info mb-1" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#detailModal<?php echo $item['slot_id']; ?>">
                                                    <i class="fas fa-info-circle"></i> Detail
                                                </button>
                                                <button class="btn btn-sm btn-outline-primary mb-1" 
                                                        onclick="editSlot(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                                    <i class="fas fa-edit"></i> Edit
                                                </button>
                                                <button class="btn btn-sm btn-outline-danger mb-1" 
                                                        onclick="deleteSlot(<?php echo $item['slot_id']; ?>)">
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
            
            <!-- ✅ MODAL DETAIL - LETAKKAN DI SINI (SETELAH TABLE, MASIH DALAM MAIN) -->
            <?php foreach ($slot_list as $item): ?>
            <div class="modal fade" id="detailModal<?php echo $item['slot_id']; ?>" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-info text-white">
                            <h5 class="modal-title">
                                <i class="fas fa-info-circle me-2"></i>
                                Detail Slot Jadwal
                            </h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <!-- Slot Info -->
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="row mb-2">
                                        <div class="col-md-6">
                                            <strong><i class="fas fa-chalkboard-teacher me-2"></i>Guru:</strong> 
                                            <?php echo htmlspecialchars($item['nama_guru']); ?>
                                        </div>
                                        <div class="col-md-6">
                                            <strong><i class="fas fa-building me-2"></i>Cabang:</strong> 
                                            <?php echo htmlspecialchars($item['nama_cabang']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="row mb-2">
                                        <div class="col-md-6">
                                            <strong><i class="fas fa-book me-2"></i>Paket:</strong> 
                                            <?php if (!empty($item['jenisles_name'])): ?>
                                                <?php echo htmlspecialchars($item['jenisles_name']); ?> - 
                                                <?php echo htmlspecialchars($item['tipeles_name']); ?> - 
                                                <?php echo htmlspecialchars($item['nama_jenistingkat']); ?>
                                            <?php else: ?>
                                                <em class="text-muted">Belum diset</em>
                                            <?php endif; ?>
                                        </div>
                                        <div class="col-md-6">
                                            <strong><i class="fas fa-calendar me-2"></i>Jadwal:</strong> 
                                            <?php echo $item['hari']; ?>, 
                                            <?php echo substr($item['jam_mulai'], 0, 5); ?> - 
                                            <?php echo substr($item['jam_selesai'], 0, 5); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong><i class="fas fa-users me-2"></i>Kapasitas:</strong> 
                                            <?php
                                            $siswa_count_modal = count($slot_siswa_data[$item['slot_id']] ?? []);
                                            ?>
                                            <span class="badge bg-primary"><?php echo $siswa_count_modal; ?>/<?php echo $item['kapasitas_maksimal']; ?> siswa</span>
                                        </div>
                                        <div class="col-md-6">
                                            <strong><i class="fas fa-toggle-on me-2"></i>Status:</strong> 
                                            <span class="badge badge-<?php echo $item['status']; ?>">
                                                <?php echo ucfirst($item['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <!-- Daftar Siswa -->
                            <h6 class="mb-3">
                                <i class="fas fa-user-graduate me-2"></i>
                                Siswa Terdaftar
                            </h6>
                            
                            <?php if (!empty($slot_siswa_data[$item['slot_id']])): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="40">No</th>
                                                <th>Nama Siswa</th>
                                                <th width="">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($slot_siswa_data[$item['slot_id']] as $idx => $siswa): ?>
                                            <tr>
                                                <td><?php echo $idx + 1; ?></td>
                                                <td><strong><?php echo htmlspecialchars($siswa['nama_siswa']); ?></strong></td>
                                                <td>
                                                    <span class="badge badge-<?php echo $siswa['status']; ?>">
                                                        <?php echo ucfirst($siswa['status']); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    Belum ada siswa terdaftar di slot ini.
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-1"></i>Tutup
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
             
            <!-- Modal Tambah Slot -->
            <div class="modal fade" id="addModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-plus me-2"></i>Tambah Slot Jadwal
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="add">
                                
                                <div class="mb-3">
                                    <label class="form-label">Guru, Cabang & Paket *</label>
                                    <select name="guru_paket" id="add_guru_paket" class="form-select" required>
                                        <option value="">Pilih Guru & Paket yang Sudah Diassign</option>
                                        <?php 
                                        // ✅ CEK INI - HARUS $guru_paket_list
                                        if (!empty($guru_paket_list)): 
                                            foreach ($guru_paket_list as $gp): 
                                        ?>
                                        <option value="<?php echo $gp['cabangguruID']; ?>|<?php echo $gp['jenistingkat_id']; ?>" 
                                                data-tipe="<?php echo $gp['tipe_kelas']; ?>">
                                            <?php echo htmlspecialchars($gp['nama_guru']); ?> - 
                                            <?php echo htmlspecialchars($gp['nama_cabang']); ?> | 
                                            <?php echo htmlspecialchars($gp['nama_paket']); ?> 
                                            (<?php echo $gp['jumlahpertemuan']; ?>x)
                                        </option>
                                        <?php 
                                            endforeach;
                                        else:
                                        ?>
                                        <option value="" disabled>Belum ada guru yang diassign paket</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Hari *</label>
                                    <select name="hari" class="form-select" required>
                                        <option value="">Pilih Hari</option>
                                        <option value="Senin">Senin</option>
                                        <option value="Selasa">Selasa</option>
                                        <option value="Rabu">Rabu</option>
                                        <option value="Kamis">Kamis</option>
                                        <option value="Jumat">Jumat</option>
                                        <option value="Sabtu">Sabtu</option>
                                        <option value="Minggu">Minggu</option>
                                    </select>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Jam Mulai *</label>
                                        <input type="time" name="jam_mulai" class="form-control" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Jam Selesai *</label>
                                        <input type="time" name="jam_selesai" class="form-control" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Kapasitas Maksimal *</label>
                                    <input type="number" name="kapasitas_maksimal" id="add_kapasitas" 
                                        class="form-control" value="1" min="1" max="10" required>
                                    <small class="text-muted" id="add_kapasitas_hint">
                                        Private = 1 siswa, Group = 2-10 siswa
                                    </small>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Simpan
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Modal Edit Slot -->
            <div class="modal fade" id="editModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST">
                            <div class="modal-header">
                                <h5 class="modal-title">
                                    <i class="fas fa-edit me-2"></i>Edit Slot Jadwal
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="edit">
                                <input type="hidden" name="slot_id" id="edit_slot_id">
                                
                                <div class="mb-3">
                                    <label class="form-label">Guru, Cabang & Paket *</label>
                                    <select name="guru_paket" id="edit_guru_paket" class="form-select" required>
                                        <option value="">Pilih Guru & Paket</option>
                                        <?php foreach ($guru_paket_list as $gp): ?>
                                        <option value="<?php echo $gp['cabangguruID']; ?>|<?php echo $gp['jenistingkat_id']; ?>" 
                                                data-tipe="<?php echo $gp['tipe_kelas']; ?>">
                                            <?php echo htmlspecialchars($gp['nama_guru']); ?> - 
                                            <?php echo htmlspecialchars($gp['nama_cabang']); ?> | 
                                            <?php echo htmlspecialchars($gp['nama_paket']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Hari *</label>
                                    <select name="hari" id="edit_hari" class="form-select" required>
                                        <option value="">Pilih Hari</option>
                                        <option value="Senin">Senin</option>
                                        <option value="Selasa">Selasa</option>
                                        <option value="Rabu">Rabu</option>
                                        <option value="Kamis">Kamis</option>
                                        <option value="Jumat">Jumat</option>
                                        <option value="Sabtu">Sabtu</option>
                                        <option value="Minggu">Minggu</option>
                                    </select>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Jam Mulai *</label>
                                        <input type="time" name="jam_mulai" id="edit_jam_mulai" class="form-control" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Jam Selesai *</label>
                                        <input type="time" name="jam_selesai" id="edit_jam_selesai" class="form-control" required>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Kapasitas Maksimal *</label>
                                    <input type="number" name="kapasitas_maksimal" id="edit_kapasitas" 
                                        class="form-control" min="1" max="10" required>
                                    <small class="text-muted" id="edit_kapasitas_hint">Private = 1, Group = 2-10</small>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i> Update
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <footer class="py-4 bg-light mt-auto">
                <div class="container-fluid px-4">
                    <div class="d-flex align-items-center justify-content-between small">
                        <div class="text-muted">Copyright &copy; Jia Jia Education <?php echo date('Y'); ?></div>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <!-- Form Delete -->
    <form method="POST" id="deleteForm" style="display:none;">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="slot_id" id="delete_slot_id">
    </form>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="js/scripts.js"></script>
    
    <script>
    // Auto set kapasitas berdasarkan tipe (private/group)
    document.getElementById('add_guru_paket').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const tipe = selectedOption.getAttribute('data-tipe');
        const kapasitasInput = document.getElementById('add_kapasitas');
        const hint = document.getElementById('add_kapasitas_hint');
        
        if (tipe === 'private') {
            kapasitasInput.value = 1;
            kapasitasInput.max = 1;
            kapasitasInput.readOnly = true;
            hint.innerHTML = '<i class="fas fa-info-circle"></i> Private: Kapasitas otomatis = 1 siswa';
        } else if (tipe === 'group') {
            kapasitasInput.value = 5;
            kapasitasInput.max = 10;
            kapasitasInput.readOnly = false;
            hint.innerHTML = '<i class="fas fa-info-circle"></i> Group: Kapasitas 2-10 siswa';
        }
    });

    // Edit Slot Function
    function editSlot(data) {
        document.getElementById('edit_slot_id').value = data.slot_id;
        document.getElementById('edit_guru_paket').value = data.cabangguruID + '|' + data.jenistingkat_id;
        document.getElementById('edit_hari').value = data.hari;
        document.getElementById('edit_jam_mulai').value = data.jam_mulai;
        document.getElementById('edit_jam_selesai').value = data.jam_selesai;
        document.getElementById('edit_kapasitas').value = data.kapasitas_maksimal;
        
        // Trigger change untuk set kapasitas hint
        const selectedOption = document.getElementById('edit_guru_paket').selectedOptions[0];
        const tipe = selectedOption ? selectedOption.getAttribute('data-tipe') : null;
        const kapasitasInput = document.getElementById('edit_kapasitas');
        const hint = document.getElementById('edit_kapasitas_hint');
        
        if (tipe === 'private') {
            kapasitasInput.max = 1;
            kapasitasInput.readOnly = true;
            hint.innerHTML = '<i class="fas fa-info-circle"></i> Private: Kapasitas = 1 siswa';
        } else if (tipe === 'group') {
            kapasitasInput.max = 10;
            kapasitasInput.readOnly = false;
            hint.innerHTML = '<i class="fas fa-info-circle"></i> Group: Kapasitas 2-10 siswa';
        }
        
        new bootstrap.Modal(document.getElementById('editModal')).show();
    }

    // Auto set kapasitas saat edit guru_paket berubah
    document.getElementById('edit_guru_paket')?.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        const tipe = selectedOption.getAttribute('data-tipe');
        const kapasitasInput = document.getElementById('edit_kapasitas');
        const hint = document.getElementById('edit_kapasitas_hint');
        
        if (tipe === 'private') {
            kapasitasInput.value = 1;
            kapasitasInput.max = 1;
            kapasitasInput.readOnly = true;
            hint.innerHTML = '<i class="fas fa-info-circle"></i> Private: Kapasitas = 1 siswa';
        } else if (tipe === 'group') {
            if (kapasitasInput.value < 2) kapasitasInput.value = 5;
            kapasitasInput.max = 10;
            kapasitasInput.readOnly = false;
            hint.innerHTML = '<i class="fas fa-info-circle"></i> Group: Kapasitas 2-10 siswa';
        }
    });

    // Delete Slot
    function deleteSlot(slot_id) {
        if (confirm('Yakin ingin menghapus slot ini?\n\nSlot yang sudah digunakan tidak bisa dihapus.')) {
            document.getElementById('delete_slot_id').value = slot_id;
            document.getElementById('deleteForm').submit();
        }
    }

    // Auto hide alert
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