<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) || isset($_SESSION['siswa_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
}

function requireRole($allowed_roles = []) {
    requireLogin();

    // Jika allowed_roles kosong dianggap semua role diizinkan
    if (empty($allowed_roles) || !is_array($allowed_roles)) {
        return;
    }

    $current_role = getUserRoleId();

    if (!in_array($current_role, $allowed_roles, true)) {
        header("Location: index.php?error=unauthorized");
        exit();
    }
}

function requireHeadAdmin() {
    requireRole([1]);
}

function requireAdmin() {
    requireRole([1, 2]);
}

function getUserId() {
    if (isset($_SESSION['user_id'])) {
        return $_SESSION['user_id'];
    }
    if (isset($_SESSION['siswa_id'])) {
        return $_SESSION['siswa_id'];
    }
    return null;
}

function getUsername() {
    return $_SESSION['username'] ?? null;
}

function getUserFullName() {
    return $_SESSION['full_name'] ?? 'User';
}

/**
 * Ambil role id; default 0 jika tidak ada
 */
function getUserRoleId() {
    return isset($_SESSION['role_id']) ? (int) $_SESSION['role_id'] : 0;
}

function getUserCabangId() {
    return isset($_SESSION['cabang_id']) ? $_SESSION['cabang_id'] : null;
}

function getSiswaId() {
    return $_SESSION['siswa_id'] ?? null;
}

function getSiswaName() {
    if (!empty($_SESSION['full_name'])) {
        return $_SESSION['full_name'];
    }
    // jika kamu menyimpan nama_orangtua terpisah di session
    return $_SESSION['nama_orangtua'] ?? null;
}

/* ----------------- Cek role ----------------- */

function isHeadAdmin() {
    return getUserRoleId() === 1;
}

function isAdmin() {
    return getUserRoleId() === 2;
}

function isOrangtua() {
    return getUserRoleId() === 3;
}

function canAccessCabang($cabang_id) {
    // Head Admin bisa akses semua cabang
    if (isHeadAdmin()) {
        return true;
    }

    // Admin biasa hanya bisa akses cabang yang dia handle
    if (isset($_SESSION['cabangs']) && is_array($_SESSION['cabangs'])) {
        foreach ($_SESSION['cabangs'] as $cabang) {
            if ($cabang['cabang_id'] == $cabang_id) {
                return true;
            }
        }
    }

    // Jika tidak ada daftar cabang, fallback ke cabang tunggal
    if (isset($_SESSION['cabang_id']) && $_SESSION['cabang_id'] == $cabang_id) {
        return true;
    }

    return false;
}

function getRoleName($role_id = null) {
    if ($role_id === null) {
        $role_id = getUserRoleId();
    }

    $roles = [
        1 => 'Head Admin',
        2 => 'Admin',
        3 => 'Orang Tua'
    ];

    return $roles[$role_id] ?? 'Unknown';
}

/* ----------------- CSRF helpers ----------------- */

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/* ----------------- Auto-require login untuk halaman internal ----------------- */

// PERBAIKAN: Cek apakah sudah di halaman yang memerlukan login
$current_page = basename($_SERVER['PHP_SELF']);
$public_pages = ['login.php', 'forgot_password.php', 'reset_password.php'];

// Hanya cek login jika bukan halaman public
if (!in_array($current_page, $public_pages, true)) {
    // Cek apakah ada session yang valid
    if (!isLoggedIn()) {
        // PERBAIKAN: Hanya redirect jika benar-benar tidak ada session
        header("Location: login.php?redirect=" . urlencode($current_page));
        exit();
    }
}
?>