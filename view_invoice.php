<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'check_login.php';
require 'config.php';
date_default_timezone_set('Asia/Jakarta');

// Get parameter
$pembayaran_id = isset($_GET['pembayaran_id']) ? (int)$_GET['pembayaran_id'] : 0;

if ($pembayaran_id <= 0) {
    die("Error: Parameter pembayaran_id tidak valid");
}

try {
    // ========================================
    // 1. AMBIL DATA INVOICE UTAMA
    // ========================================
    $query_invoice = "
        SELECT 
            p.pembayaran_id,
            p.siswa_id,
            p.datales_id,
            p.bulan_ke,
            p.semester_ke,
            p.tahun_ajaran,
            p.periode_bulan,
            p.jumlah_bayar,
            p.status_pembayaran,
            p.tanggal_transfer,
            p.bukti_transfer,
            s.name as nama_siswa,
            s.nama_orangtua,
            s.no_telp,
            c.nama_cabang
        FROM pembayaran p
        INNER JOIN siswa s ON p.siswa_id = s.siswa_id
        INNER JOIN cabang c ON s.cabang_id = c.cabang_id
        WHERE p.pembayaran_id = ?
    ";
    
    $stmt = $conn->prepare($query_invoice);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $pembayaran_id);
    $stmt->execute();
    $invoice_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$invoice_data) {
        throw new Exception("Invoice tidak ditemukan untuk pembayaran_id: $pembayaran_id");
    }
    
    // ========================================
    // 2. AMBIL SEMUA INVOICE DI BULAN & SEMESTER YANG SAMA
    // ========================================
    $query_all = "
        SELECT 
            MIN(p.pembayaran_id) as pembayaran_id,
            p.datales_id,
            MAX(p.jumlah_bayar) as jumlah_bayar,
            jl.name as nama_jenisles,
            tl.name as nama_tipe,
            jt.nama_jenistingkat,
            tl.jumlahpertemuan
        FROM pembayaran p
        LEFT JOIN datales d ON p.datales_id = d.datales_id
        LEFT JOIN jenistingkat jt ON d.jenistingkat_id = jt.jenistingkat_id
        LEFT JOIN tipeles tl ON jt.tipeles_id = tl.tipeles_id
        LEFT JOIN jenisles jl ON tl.jenisles_id = jl.jenisles_id
        WHERE p.siswa_id = ? 
        AND p.bulan_ke = ? 
        AND p.semester_ke = ?
        AND p.tahun_ajaran = ?
        AND (p.is_archived = 0 OR p.is_archived IS NULL)
        GROUP BY p.datales_id, jl.name, tl.name, jt.nama_jenistingkat, tl.jumlahpertemuan
        ORDER BY MIN(p.pembayaran_id) ASC
    ";
    
    $stmt_all = $conn->prepare($query_all);
    $stmt_all->bind_param("iiis", 
        $invoice_data['siswa_id'], 
        $invoice_data['bulan_ke'], 
        $invoice_data['semester_ke'], 
        $invoice_data['tahun_ajaran']
    );
    $stmt_all->execute();
    $result_all = $stmt_all->get_result();
    
    $invoice_items = [];
    $total_gabungan = 0;
    $all_pembayaran_ids = [];
    
    while ($row = $result_all->fetch_assoc()) {
        $invoice_items[] = $row;
        $total_gabungan += $row['jumlah_bayar'];
        $all_pembayaran_ids[] = $row['pembayaran_id'];
    }
    $stmt_all->close();
    
    // Tentukan apakah gabungan atau single
    $is_combined = (count($invoice_items) > 1);
    
    // ========================================
    // 3. AMBIL JADWAL PERTEMUAN (untuk semua paket)
    // ========================================
    $all_tanggal_list = [];
    
    foreach ($invoice_items as $item) {
        $query_jadwal = "
            SELECT 
                tanggal_pertemuan,
                pertemuan_ke,
                jam_mulai,
                jam_selesai
            FROM jadwal_pertemuan
            WHERE siswa_id = ? 
            AND datales_id = ? 
            AND bulan_ke = ?
            AND semester_ke = ?
            AND is_history = 0
            AND is_reschedule = FALSE
            ORDER BY pertemuan_ke ASC
        ";
        
        $stmt_jadwal = $conn->prepare($query_jadwal);
        $stmt_jadwal->bind_param("iiii", 
            $invoice_data['siswa_id'], 
            $item['datales_id'], 
            $invoice_data['bulan_ke'],
            $invoice_data['semester_ke']  
        );
        $stmt_jadwal->execute();
        $result_jadwal = $stmt_jadwal->get_result();
        
        $tanggal_list = [];
        while ($row = $result_jadwal->fetch_assoc()) {
            $tanggal_list[] = [
                'tanggal' => $row['tanggal_pertemuan'],
                'pertemuan_ke' => $row['pertemuan_ke'],
                'jam' => substr($row['jam_mulai'], 0, 5) . '-' . substr($row['jam_selesai'], 0, 5)
            ];
        }
        $stmt_jadwal->close();
        
        $all_tanggal_list[$item['datales_id']] = $tanggal_list;
    }
    
    // Invoice info
    $invoice_number = 'INV/' . date('Ym') . '/' . str_pad($pembayaran_id, 6, '0', STR_PAD_LEFT);
    $invoice_date = date('d/m/Y');
    $tanggal_jatuh_tempo = date('d/m/Y', strtotime('+7 days'));
    
} catch (Exception $e) {
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Error</title></head><body>";
    echo "<div style='padding: 20px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px; font-family: Arial;'>";
    echo "<h3 style='color: #721c24;'>🔴 ERROR</h3>";
    echo "<p><strong>Message:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><strong>File:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>Line:</strong> " . $e->getLine() . "</p>";
    echo "<hr><p><a href='absensi.php' style='padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>← Kembali</a></p>";
    echo "</div></body></html>";
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice - <?php echo $invoice_number; ?></title>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        .invoice-container { max-width: 800px; margin: 0 auto; background: white; padding: 40px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        
        /* Badge Combined */
        .badge-combined {
            background: linear-gradient(45deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            display: inline-block;
            margin-left: 10px;
        }
        
        .header { margin-bottom: 30px; }
        .header h1 { font-size: 32px; color: #2c3e50; margin-bottom: 10px; }
        .company-name { font-size: 18px; color: #555; font-weight: bold; margin-bottom: 5px; }
        .company-address { font-size: 12px; color: #666; line-height: 1.6; }
        .billing-info { margin: 30px 0; display: flex; justify-content: space-between; }
        .invoice-details { text-align: right; font-size: 12px; color: #666; }
        
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        table thead { background: #4a5568; color: white; }
        table th { padding: 12px; text-align: left; font-size: 14px; }
        table th:nth-child(1) { width: 5%; text-align: center; }
        table th:nth-child(2) { width: 60%; }
        table th:nth-child(3), table th:nth-child(4) { text-align: right; }
        table td { padding: 12px; font-size: 13px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        table td:nth-child(1) { text-align: center; }
        table td:nth-child(3), table td:nth-child(4) { text-align: right; }
        
        .item-name { font-weight: bold; color: #2c3e50; margin-bottom: 4px; }
        .item-period { font-size: 11px; color: #666; line-height: 1.5; }
        
        .totals { text-align: right; margin-top: 20px; }
        .total-row { display: flex; justify-content: flex-end; padding: 8px 12px; font-size: 14px; }
        .total-row .label { width: 150px; text-align: right; padding-right: 20px; }
        .total-row .value { width: 150px; text-align: right; font-weight: bold; }
        .total-row.grand-total { background: #f8f9fa; font-size: 16px; margin-top: 5px; }
        
        .payment-info { margin: 30px 0; padding: 15px; background: #f8f9fa; border-left: 4px solid #3498db; }
        .btn { display: inline-block; padding: 12px 30px; margin: 10px; border: none; border-radius: 5px; 
               font-size: 16px; cursor: pointer; text-decoration: none; }
        .btn-primary { background: #3498db; color: white; }
        .btn-secondary { background: #95a5a6; color: white; }
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="header">
            <h1>
                INVOICE
                <?php if ($is_combined): ?>
                <span class="badge-combined">INVOICE GABUNGAN</span>
                <?php endif; ?>
            </h1>
            <div class="company-name">JiaJia Education Center</div>
            <div class="company-address">
                <?php echo htmlspecialchars($invoice_data['nama_cabang']); ?>
            </div>
        </div>
        
        <div class="billing-info">
            <div>
                <h3>Tagihan Kepada:</h3>
                <p style="margin-top: 5px; font-size: 16px;"><?php echo htmlspecialchars($invoice_data['nama_siswa']); ?></p>
            </div>
            <div class="invoice-details">
                <div><strong>Faktur:</strong> <?php echo $invoice_number; ?></div>
                <div><strong>Tanggal:</strong> <?php echo $invoice_date; ?></div>
                <div><strong>Jatuh Tempo:</strong> <?php echo $tanggal_jatuh_tempo; ?></div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>No</th>
                    <th>Item</th>
                    <th>Biaya</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoice_items as $index => $item): 
                    $nama_paket_item = $item['nama_jenisles'] . ' - ' . $item['nama_tipe'] . ' - ' . $item['nama_jenistingkat'];
                    
                    // Format tanggal untuk item ini
                    $formatted_dates = [];
                    if (isset($all_tanggal_list[$item['datales_id']])) {
                        foreach ($all_tanggal_list[$item['datales_id']] as $tgl_item) {
                            $formatted_dates[] = date('d M', strtotime($tgl_item['tanggal']));
                        }
                    }
                    $periode_display = !empty($formatted_dates) ? implode(', ', $formatted_dates) : "Bulan " . $invoice_data['bulan_ke'];
                ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td>
                        <div class="item-name"><?php echo htmlspecialchars($nama_paket_item); ?></div>
                        <div class="item-period">
                            <?php echo $item['jumlahpertemuan']; ?>x Pertemuan: <?php echo htmlspecialchars($periode_display); ?>
                        </div>
                    </td>
                    <td>Rp<?php echo number_format($item['jumlah_bayar'], 0, ',', '.'); ?></td>
                    <td>Rp<?php echo number_format($item['jumlah_bayar'], 0, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="totals">
            <div class="total-row">
                <div class="label">Subtotal</div>
                <div class="value">Rp<?php echo number_format($total_gabungan, 0, ',', '.'); ?></div>
            </div>
            <div class="total-row grand-total">
                <div class="label">Total Keseluruhan</div>
                <div class="value">Rp<?php echo number_format($total_gabungan, 0, ',', '.'); ?></div>
            </div>
        </div>
        
        <div class="payment-info">
            <h3 style="margin-bottom: 10px;">Info Pembayaran</h3>
            <p>Transfer ke BCA 398-127-1619</p>
            <p>a.n. Shirley Hilsen</p>
            <?php if ($is_combined): ?>
            <p style="margin-top: 10px; font-size: 12px; color: #666;">
                <strong>Catatan:</strong> Total pembayaran mencakup <?php echo count($invoice_items); ?> paket kelas untuk bulan <?php echo $invoice_data['bulan_ke']; ?>.
            </p>
            <?php endif; ?>
        </div>
        
        <div style="text-align: center; margin: 30px 0;">
            <button onclick="downloadPDF()" class="btn btn-primary">📥 Download PDF</button>
            <a href="detail_absensi.php?siswa_id=<?php echo $invoice_data['siswa_id']; ?>&paket=<?php echo $invoice_items[0]['datales_id']; ?>&bulan=<?php echo $invoice_data['bulan_ke']; ?>" class="btn btn-secondary">← Kembali</a>
        </div>
        
        <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #666;">
            <strong>Syarat & Ketentuan:</strong><br>
            Pembayaran diselesaikan sebelum tanggal pertemuan pertama.
        </div>
    </div>

<script>
function downloadPDF() {
    const { jsPDF } = window.jspdf;
    const pdf = new jsPDF('p', 'mm', 'a4');
    
    // Data dari PHP
    const invoiceNumber = '<?php echo $invoice_number; ?>';
    const invoiceDate = '<?php echo $invoice_date; ?>';
    const dueDate = '<?php echo $tanggal_jatuh_tempo; ?>';
    const studentName = '<?php echo addslashes($invoice_data['nama_siswa']); ?>';
    const cabang = '<?php echo addslashes($invoice_data['nama_cabang']); ?>';
    const isCombined = <?php echo $is_combined ? 'true' : 'false'; ?>;
    const total = <?php echo $total_gabungan; ?>;
    
    // Invoice items
    const items = <?php echo json_encode(array_map(function($item) use ($all_tanggal_list, $invoice_data) {
        $formatted_dates = [];
        if (isset($all_tanggal_list[$item['datales_id']])) {
            foreach ($all_tanggal_list[$item['datales_id']] as $tgl) {
                $formatted_dates[] = date('d M', strtotime($tgl['tanggal']));
            }
        }
        return [
            'nama' => $item['nama_jenisles'] . ' - ' . $item['nama_tipe'] . ' - ' . $item['nama_jenistingkat'],
            'jumlah_pertemuan' => $item['jumlahpertemuan'],
            'periode' => !empty($formatted_dates) ? implode(', ', $formatted_dates) : 'Bulan ' . $invoice_data['bulan_ke'],
            'biaya' => (float)$item['jumlah_bayar']
        ];
    }, $invoice_items)); ?>;
    
    let y = 20;
    
    try {
        // HEADER
        pdf.setFontSize(28);
        pdf.setFont('helvetica', 'bold');
        pdf.setTextColor(44, 62, 80);
        pdf.text('INVOICE', 15, y);
        
        if (isCombined) {
            pdf.setFontSize(10);
            pdf.setFillColor(240, 147, 251);
            pdf.roundedRect(70, y - 5, 45, 8, 2, 2, 'F');
            pdf.setTextColor(255, 255, 255);
            pdf.text('INVOICE GABUNGAN', 92.5, y, { align: 'center' });
            pdf.setTextColor(44, 62, 80);
        }
        
        y += 10;
        pdf.setFontSize(14);
        pdf.text('JiaJia Education Center', 15, y);
        
        y += 6;
        pdf.setFontSize(10);
        pdf.setFont('helvetica', 'normal');
        pdf.setTextColor(100, 100, 100);
        pdf.text(cabang, 15, y);
        
        // BILLING INFO
        y += 15;
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(11);
        pdf.setTextColor(44, 62, 80);
        pdf.text('Tagihan Kepada:', 15, y);
        
        // Invoice details (right)
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(9);
        pdf.text('Faktur: ' + invoiceNumber, 195, y, { align: 'right' });
        pdf.text('Tanggal: ' + invoiceDate, 195, y + 5, { align: 'right' });
        pdf.text('Jatuh Tempo: ' + dueDate, 195, y + 10, { align: 'right' });
        
        y += 6;
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(12);
        pdf.text(studentName, 15, y);
        
        // TABLE
        y += 15;
        
        // Header
        pdf.setFillColor(74, 85, 104);
        pdf.rect(15, y, 180, 10, 'F');
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(10);
        pdf.setTextColor(255, 255, 255);
        pdf.text('No', 20, y + 6.5, { align: 'center' });
        pdf.text('Item', 40, y + 6.5);
        pdf.text('Biaya', 155, y + 6.5, { align: 'right' });
        pdf.text('Total', 185, y + 6.5, { align: 'right' });
        
        y += 10;
        
        // Items
        items.forEach((item, index) => {
            pdf.setTextColor(0, 0, 0);
            pdf.setFontSize(9);
            pdf.text((index + 1).toString(), 20, y + 6, { align: 'center' });
            
            pdf.setFont('helvetica', 'bold');
            pdf.setFontSize(10);
            
            // Split long text
            const itemName = pdf.splitTextToSize(item.nama, 105);
            pdf.text(itemName, 40, y + 6);
            
            const lineCount = itemName.length;
            
            pdf.setFont('helvetica', 'normal');
            pdf.setFontSize(8);
            pdf.setTextColor(100, 100, 100);
            pdf.text(item.jumlah_pertemuan + 'x Pertemuan: ' + item.periode, 40, y + 6 + (lineCount * 5));
            
            pdf.setFontSize(10);
            pdf.setTextColor(0, 0, 0);
            pdf.setFont('helvetica', 'normal');
            pdf.text('Rp' + item.biaya.toLocaleString('id-ID'), 155, y + 11, { align: 'right' });
            pdf.setFont('helvetica', 'bold');
            pdf.text('Rp' + item.biaya.toLocaleString('id-ID'), 185, y + 11, { align: 'right' });
            
            y += 20;
        });
        
        y += 5;
        pdf.setDrawColor(226, 232, 240);
        pdf.line(15, y, 195, y);
        
        // TOTALS
        y += 10;
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(11);
        pdf.setTextColor(0, 0, 0);
        pdf.text('Subtotal', 125, y);
        pdf.setFont('helvetica', 'bold');
        pdf.text('Rp' + total.toLocaleString('id-ID'), 188, y, { align: 'right' });
        
        y += 10;
        pdf.setFillColor(248, 249, 250);
        pdf.rect(120, y - 6, 75, 12, 'F');
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(13);
        pdf.text('Total Keseluruhan', 125, y);
        pdf.text('Rp' + total.toLocaleString('id-ID'), 188, y, { align: 'right' });
        
        // PAYMENT INFO
        y += 18;
        pdf.setFillColor(232, 245, 255);
        pdf.rect(15, y, 180, 22, 'F');
        pdf.setDrawColor(52, 152, 219);
        pdf.setLineWidth(2);
        pdf.line(15, y, 15, y + 22);
        
        y += 8;
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(11);
        pdf.setTextColor(44, 62, 80);
        pdf.text('Info Pembayaran', 20, y);
        
        y += 6;
        pdf.setFont('helvetica', 'normal');
        pdf.setFontSize(10);
        pdf.text('Transfer ke BCA 398-127-1619', 20, y);
        
        y += 5;
        pdf.text('a.n. Shirley Hilsen', 20, y);
        
        // FOOTER
        y += 18;
        pdf.setDrawColor(226, 232, 240);
        pdf.line(15, y, 195, y);
        
        y += 8;
        pdf.setFont('helvetica', 'bold');
        pdf.setFontSize(9);
        pdf.setTextColor(100, 100, 100);
        pdf.text('Syarat & Ketentuan:', 15, y);
        
        y += 5;
        pdf.setFont('helvetica', 'normal');
        pdf.text('Pembayaran diselesaikan sebelum tanggal pertemuan pertama.', 15, y);
        
        pdf.save('Invoice_' + invoiceNumber.replace(/\//g, '_') + '.pdf');
        alert('✅ PDF berhasil di-download!');
        
    } catch (error) {
        console.error('Error:', error);
        alert('❌ Gagal generate PDF: ' + error.message);
    }
}
</script>
</body>
</html>

<?php
if (isset($conn)) {
    mysqli_close($conn);
}
?>