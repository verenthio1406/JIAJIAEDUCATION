<?php
$host = 'localhost';        
$username = 'root';         
$password = '';             
$database = 'jiajiaeducation_new';

// Membuat koneksi
$conn = mysqli_connect($host, $username, $password, $database);

// Cek koneksi
if(!$conn) {
    die("Koneksi database gagal: " . mysqli_connect_error());
}

// Set charset ke UTF-8 untuk support karakter Indonesia
mysqli_set_charset($conn, "utf8mb4");

// Optional: Set timezone
date_default_timezone_set('Asia/Jakarta');
?>