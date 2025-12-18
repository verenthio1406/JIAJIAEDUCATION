<?php
function generateJadwalSemester($conn, $siswa_id, $datales_id, $slot_id, $semester_ke = 1, $tahun_ajaran = null, $tanggal_mulai = null) {
    
    error_log("=== GENERATE JADWAL SEMESTER ===");
    error_log("Input: siswa_id=$siswa_id, datales_id=$datales_id, semester_ke=$semester_ke");

    // Default tahun ajaran
    if (!$tahun_ajaran) {
        $tahun_sekarang = date('Y');
        $bulan_sekarang = (int)date('m');
        if ($bulan_sekarang >= 7) {
            $tahun_ajaran = $tahun_sekarang . '/' . ($tahun_sekarang + 1);
        } else {
            $tahun_ajaran = ($tahun_sekarang - 1) . '/' . $tahun_sekarang;
        }
    }
    
    // Default tanggal mulai = bulan depan
    if (!$tanggal_mulai) {
        $tanggal_mulai = date('Y-m-01', strtotime('+1 month'));
    }
    
    try {
        // ✅ Ambil info paket & slot + harga dari datales
        $stmt = $conn->prepare("
            SELECT 
                tl.jumlahpertemuan,
                d.harga,
                js.hari,
                js.jam_mulai,
                js.jam_selesai
            FROM datales d
            INNER JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
            INNER JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
            INNER JOIN jadwal_slot js ON js.slot_id = ?
            WHERE d.datales_id = ?
        ");
        $stmt->bind_param("ii", $slot_id, $datales_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $paket = $result->fetch_assoc();
        $stmt->close();
        
        if (!$paket) {
            throw new Exception("Paket atau slot tidak ditemukan");
        }
        
        $jumlah_pertemuan_per_bulan = (int)$paket['jumlahpertemuan'];
        $harga_per_bulan = (float)$paket['harga'];
        $hari = $paket['hari'];
        $jam_mulai = $paket['jam_mulai'];
        $jam_selesai = $paket['jam_selesai'];
        
        // Mapping hari Indonesia ke English
        $hari_map = [
            'Senin' => 'Monday',
            'Selasa' => 'Tuesday',
            'Rabu' => 'Wednesday',
            'Kamis' => 'Thursday',
            'Jumat' => 'Friday',
            'Sabtu' => 'Saturday',
            'Minggu' => 'Sunday'
        ];
        
        $hari_english = $hari_map[$hari] ?? 'Monday';
        
        // Generate jadwal ROLLING (tidak peduli batas bulan)
        $current_date = new DateTime($tanggal_mulai);
        
        // Cari hari pertama yang sesuai
        while ($current_date->format('l') !== $hari_english) {
            $current_date->modify('+1 day');
        }
        
        // Prepare statement untuk insert jadwal
        $stmt_insert_jadwal = $conn->prepare("
            INSERT INTO jadwal_pertemuan 
            (siswa_id, datales_id, bulan_ke, semester_ke, tahun_ajaran, pertemuan_ke, tanggal_pertemuan, jam_mulai, jam_selesai, status_pertemuan)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
        ");
        
        // Generate untuk 6 bulan
        for ($bulan_ke = 1; $bulan_ke <= 6; $bulan_ke++) {
            
            // Generate TEPAT jumlah_pertemuan tanpa peduli batas bulan
            $pertemuan_dates = [];
            
            for ($i = 0; $i < $jumlah_pertemuan_per_bulan; $i++) {
                $pertemuan_dates[] = $current_date->format('Y-m-d');
                $current_date->modify('+1 week');
            }
            
            // Insert jadwal pertemuan
            foreach ($pertemuan_dates as $index => $tanggal) {
                $pertemuan_ke = $index + 1;
                $stmt_insert_jadwal->bind_param("iiisissss", 
                    $siswa_id, 
                    $datales_id, 
                    $bulan_ke, 
                    $semester_ke, 
                    $tahun_ajaran, 
                    $pertemuan_ke, 
                    $tanggal, 
                    $jam_mulai, 
                    $jam_selesai
                );
                $stmt_insert_jadwal->execute();
            }
        }
        $stmt_insert_jadwal->close();
        
        // ✅ AUTO-GENERATE INVOICE untuk 6 bulan
        $stmt_insert_invoice = $conn->prepare("
            INSERT INTO pembayaran 
            (siswa_id, datales_id, bulan_ke, semester_ke, tahun_ajaran, periode_bulan, tanggal_transfer, jumlah_bayar, status_pembayaran, is_archived, total_pertemuan) 
            VALUES (?, ?, ?, ?, ?, ?, '0000-00-00', ?, '', 0, ?)
        ");

        $invoice_date = new DateTime($tanggal_mulai);

        for ($bulan_ke = 1; $bulan_ke <= 6; $bulan_ke++) {
            $periode_bulan = $invoice_date->format('F Y');
            
            // ✅ CEK DULU: Apakah invoice untuk bulan ini + semester ini sudah ada?
            $stmt_check = $conn->prepare("
                SELECT pembayaran_id 
                FROM pembayaran 
                WHERE siswa_id = ? 
                AND datales_id = ? 
                AND bulan_ke = ? 
                AND semester_ke = ?
                LIMIT 1
            ");
            $stmt_check->bind_param("iiii", $siswa_id, $datales_id, $bulan_ke, $semester_ke);
            $stmt_check->execute();
            $check_result = $stmt_check->get_result();
            $invoice_exists = $check_result->num_rows > 0;
            $stmt_check->close();
            
            // Kalau belum ada, baru insert
            if (!$invoice_exists) {
                try {
                    $stmt_insert_invoice->bind_param("iiiissdi", 
                        $siswa_id, 
                        $datales_id, 
                        $bulan_ke, 
                        $semester_ke, 
                        $tahun_ajaran, 
                        $periode_bulan, 
                        $harga_per_bulan,
                        $jumlah_pertemuan_per_bulan
                    );
                    $stmt_insert_invoice->execute();
                    error_log("✅ Invoice created: Bulan $bulan_ke, Semester $semester_ke");
                } catch (Exception $e) {
                    // Skip duplicate error, continue to next month
                    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        error_log("⏭️ Invoice already exists (duplicate): Bulan $bulan_ke, Semester $semester_ke, skipping");
                    } else {
                        throw $e; // Re-throw other errors
                    }
                }
            } else {
                error_log("⏭️ Invoice already exists: Bulan $bulan_ke, Semester $semester_ke, skipping");
            }
            
            $invoice_date->modify('+1 month');
        }
        $stmt_insert_invoice->close();

        error_log("✅ SUCCESS! Jadwal + Invoice generated for Semester $semester_ke");
        
        return [
            'success' => true,
            'message' => 'Jadwal semester dan invoice berhasil digenerate',
            'semester_ke' => $semester_ke,
            'tahun_ajaran' => $tahun_ajaran
        ];
        
    } catch (Exception $e) {
        error_log("Error generateJadwalSemester: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}

/**
 * Update summary bulanan setelah ada perubahan absensi
 */
function updateSummaryBulanan($conn, $siswa_id, $datales_id, $bulan_ke, $semester_ke, $tahun_ajaran) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as target,
                SUM(CASE WHEN status_pertemuan = 'hadir' THEN 1 ELSE 0 END) as hadir,
                SUM(CASE WHEN status_pertemuan = 'tidak_hadir' THEN 1 ELSE 0 END) as tidak_hadir,
                SUM(CASE WHEN status_pertemuan = 'scheduled' THEN 1 ELSE 0 END) as belum_absen
            FROM jadwal_pertemuan
            WHERE siswa_id = ? 
            AND datales_id = ? 
            AND bulan_ke = ? 
            AND semester_ke = ?
            AND tahun_ajaran = ?
            AND is_reschedule = FALSE
        ");
        $stmt->bind_param("iiiis", $siswa_id, $datales_id, $bulan_ke, $semester_ke, $tahun_ajaran);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        
        return [
            'success' => true,
            'data' => [
                'target' => $data['target'],
                'hadir' => $data['hadir'],
                'tidak_hadir' => $data['tidak_hadir'],
                'belum_absen' => $data['belum_absen'],
                'sisa_utang' => $data['tidak_hadir']
            ]
        ];
        
    } catch (Exception $e) {
        error_log("Error updateSummaryBulanan: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}
?>