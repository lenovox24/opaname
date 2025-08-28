<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
error_reporting(E_ALL);
// Jangan tampilkan error ke output agar tidak mengganggu header CSV
ini_set('display_errors', 0);

try {
    if (!file_exists('koneksi.php')) {
        throw new Exception('File koneksi.php tidak ditemukan');
    }
    require_once 'koneksi.php';
    
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Koneksi database tidak tersedia');
    }
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'unloading_records'");
    if ($check_table->rowCount() == 0) {
        // Buat tabel jika belum ada
        $create_table_sql = "
        CREATE TABLE IF NOT EXISTS `unloading_records` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `incoming_transaction_id` int(11) DEFAULT NULL,
            `tanggal` date NOT NULL,
            `jam_masuk` time DEFAULT NULL,
            `jam_start_qc` time DEFAULT NULL,
            `jam_finish_qc` time DEFAULT NULL,
            `jam_start_bongkar` time DEFAULT NULL,
            `jam_finish_bongkar` time DEFAULT NULL,
            `jam_keluar` time DEFAULT NULL,
            `durasi_bongkar` int(11) DEFAULT NULL,
            `supplier` varchar(255) DEFAULT NULL,
            `nama_barang` varchar(255) DEFAULT NULL,
            `nomor_mobil` varchar(50) DEFAULT NULL,
            `no_mobil` varchar(50) DEFAULT NULL,
            `qty_sak` decimal(10,2) DEFAULT 0,
            `qty_pallet` int(11) DEFAULT 0,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_tanggal` (`tanggal`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $pdo->exec($create_table_sql);
    }
} catch (PDOException $e) {
    die('Error: ' . $e->getMessage());
}

$current_week = $_GET['week'] ?? date('Y-W');

if (!preg_match('/^\d{4}-\d{2}$/', $current_week)) {
    die('Error: Format minggu tidak valid');
}

$week_parts = explode('-', $current_week);
$year = intval($week_parts[0]);
$week = intval($week_parts[1]);

if ($year < 2020 || $year > 2030 || $week < 1 || $week > 53) {
    die('Error: Tahun atau minggu tidak valid');
}

try {
    $monday = new DateTime();
    $monday->setISODate($year, $week, 1); // Senin
    $saturday = new DateTime();
    $saturday->setISODate($year, $week, 6); // Sabtu
    
    $monday_str = $monday->format('Y-m-d');
    $saturday_str = $saturday->format('Y-m-d');
} catch (Exception $e) {
    die('Error: Gagal menghitung tanggal - ' . $e->getMessage());
}

// Baca filter pilihan item (produk) dari request
$selected_product_ids = isset($_GET['product_ids']) ? (array)$_GET['product_ids'] : [];
$selected_product_ids = array_values(array_filter($selected_product_ids, function($v){ return ctype_digit((string)$v); }));

try {
    $total_check = $pdo->query("SELECT COUNT(*) as total FROM unloading_records");
    $total_data = $total_check->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    die('Error cek total data: ' . $e->getMessage());
}

if ($total_data == 0) {
    try {
        $create_table_sql = "
        CREATE TABLE IF NOT EXISTS `unloading_records` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `incoming_transaction_id` int(11) DEFAULT NULL,
            `tanggal` date NOT NULL,
            `jam_masuk` time DEFAULT NULL,
            `jam_start_qc` time DEFAULT NULL,
            `jam_finish_qc` time DEFAULT NULL,
            `jam_start_bongkar` time DEFAULT NULL,
            `jam_finish_bongkar` time DEFAULT NULL,
            `jam_keluar` time DEFAULT NULL,
            `durasi_bongkar` int(11) DEFAULT NULL,
            `supplier` varchar(255) DEFAULT NULL,
            `nama_barang` varchar(255) DEFAULT NULL,
            `nomor_mobil` varchar(50) DEFAULT NULL,
            `no_mobil` varchar(50) DEFAULT NULL,
            `qty_sak` decimal(10,2) DEFAULT 0,
            `qty_pallet` int(11) DEFAULT 0,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_tanggal` (`tanggal`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $pdo->exec($create_table_sql);
        
        $sample_data = [
            [$monday_str, '08:30:00', '09:00:00', '09:30:00', '10:00:00', '12:00:00', '13:00:00', 120, 'PT Supplier A', 'Beras Premium', 'B 1234 XYZ', 100.50, 5],
            [$monday_str, '14:00:00', '14:30:00', '15:00:00', '15:30:00', '17:00:00', '18:00:00', 90, 'PT Supplier B', 'Gula Pasir', 'B 5678 ABC', 75.25, 3],
            [date('Y-m-d', strtotime($monday_str . ' +1 day')), '09:00:00', '09:30:00', '10:00:00', '10:30:00', '12:30:00', '13:30:00', 60, 'PT Supplier C', 'Minyak Goreng', 'B 9012 DEF', 50.75, 2]
        ];
        
        $insert_sql = "INSERT INTO unloading_records (tanggal, jam_masuk, jam_start_qc, jam_finish_qc, jam_start_bongkar, jam_finish_bongkar, jam_keluar, durasi_bongkar, supplier, nama_barang, nomor_mobil, qty_sak, qty_pallet) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $pdo->prepare($insert_sql);
        
        foreach ($sample_data as $data) {
            $insert_stmt->execute($data);
        }
    } catch (PDOException $e) {
        error_log("Export unloading - Error creating sample data: " . $e->getMessage());
    }
}

try {
    $sql_incoming = "
        SELECT 
            i.id,
            i.transaction_date as tanggal,
            DATE_FORMAT(i.transaction_date, '%d/%m/%Y') as tanggal_formatted,
            COALESCE(i.supplier, 'N/A') as supplier,
            COALESCE(p.product_name, 'Produk Tidak Diketahui') as nama_barang,
            COALESCE(i.license_plate, 'N/A') as nomor_mobil,
            COALESCE(i.quantity_sacks, 0) as qty_sak,
            COALESCE(i.quantity_kg, 0) as qty_kg,
            i.po_number,
            i.batch_number,
            i.status,
            i.created_at
        FROM incoming_transactions i
        LEFT JOIN products p ON i.product_id = p.id
        WHERE i.transaction_date BETWEEN ? AND ?
        ORDER BY i.transaction_date ASC, i.created_at ASC
    ";
    
    $stmt_incoming = $pdo->prepare($sql_incoming);
    $stmt_incoming->execute([$monday_str, $saturday_str]);
    $incoming_records = $stmt_incoming->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($incoming_records as $record) {
        $check_sql = "SELECT id FROM unloading_records WHERE incoming_transaction_id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$record['id']]);
        
        if ($check_stmt->rowCount() == 0) {
            $insert_sql = "
                INSERT INTO unloading_records 
                (incoming_transaction_id, tanggal, supplier, nama_barang, nomor_mobil, qty_sak, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $record['id'],
                $record['tanggal'],
                $record['supplier'],
                $record['nama_barang'],
                $record['nomor_mobil'],
                $record['qty_sak']
            ]);
        }
    }
    
} catch (PDOException $e) {
    error_log("Export unloading - Error sinkronisasi data: " . $e->getMessage());
}

try {
    $sql_unloading = "
        SELECT u.*, 
               COALESCE(i.po_number, 'N/A') as po_number,
               DATE_FORMAT(u.tanggal, '%d/%m/%Y') as tanggal_formatted,
               DATE_FORMAT(u.tanggal, '%W') as hari_formatted,
               TIME_FORMAT(u.jam_masuk, '%H:%i') as jam_masuk_formatted,
               TIME_FORMAT(u.jam_start_qc, '%H:%i') as jam_start_qc_formatted,
               TIME_FORMAT(u.jam_finish_qc, '%H:%i') as jam_finish_qc_formatted,
               TIME_FORMAT(u.jam_start_bongkar, '%H:%i') as jam_start_bongkar_formatted,
               TIME_FORMAT(u.jam_finish_bongkar, '%H:%i') as jam_finish_bongkar_formatted,
               TIME_FORMAT(u.jam_keluar, '%H:%i') as jam_keluar_formatted
        FROM unloading_records u 
        LEFT JOIN incoming_transactions i ON u.incoming_transaction_id = i.id
        WHERE u.tanggal BETWEEN ? AND ?
        AND u.incoming_transaction_id IS NOT NULL
    ";
    
    $params_unloading = [$monday_str, $saturday_str];
    // Jika user memilih item tertentu, filter berdasarkan product_id
    if (!empty($selected_product_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_product_ids), '?'));
        $sql_unloading .= " AND i.product_id IN (" . $placeholders . ")";
        $params_unloading = array_merge($params_unloading, $selected_product_ids);
    }

    // Tambahkan ORDER BY di akhir setelah semua filter tersusun
    $sql_unloading .= " ORDER BY u.tanggal ASC, u.jam_masuk ASC";
    $stmt_unloading = $pdo->prepare($sql_unloading);
    $stmt_unloading->execute($params_unloading);
    $unloading_records = $stmt_unloading->fetchAll(PDO::FETCH_ASSOC);
    
    if (isset($_GET['debug'])) {
        echo "<pre>";
        echo "=== DEBUG EXPORT UNLOADING ===\n";
        echo "Parameter week: " . ($current_week ?? 'tidak ada') . "\n";
        echo "Year: $year, Week: $week\n";
        echo "Query periode: $monday_str sampai $saturday_str\n";
        echo "Jumlah data ditemukan: " . count($unloading_records) . "\n";
        
        if (!empty($unloading_records)) {
            echo "\nSample data pertama:\n";
            print_r($unloading_records[0]);
            
            echo "\nSemua tanggal yang ditemukan:\n";
            foreach ($unloading_records as $record) {
                echo "- {$record['tanggal']} ({$record['tanggal_formatted']})\n";
            }
        } else {
            echo "\nTidak ada data untuk periode ini.\n";
            
            $all_data_sql = "SELECT tanggal, COUNT(*) as jumlah FROM unloading_records WHERE incoming_transaction_id IS NOT NULL GROUP BY tanggal ORDER BY tanggal DESC LIMIT 5";
            $all_data_stmt = $pdo->prepare($all_data_sql);
            $all_data_stmt->execute();
            $all_data = $all_data_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($all_data)) {
                echo "\nData terakhir di tabel unloading_records:\n";
                foreach ($all_data as $data) {
                    echo "- {$data['tanggal']}: {$data['jumlah']} record\n";
                }
            }
        }
        echo "</pre>";
        exit();
    }
    
} catch (PDOException $e) {
    die('Error mengambil data: ' . $e->getMessage());
}

$total_records = count($unloading_records);
$total_sak = array_sum(array_column($unloading_records, 'qty_sak'));
$total_pallet = array_sum(array_column($unloading_records, 'qty_pallet'));

$filename = "Unloading_Minggu_" . $week . "_" . $year . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
echo "sep=;\n";

$output = fopen('php://output', 'w');

$headers = [
    'No', 'Tanggal', 'Hari', 'Jam Masuk', 'Start QC', 'Finish QC', 'Start Bongkar', 'Finish Bongkar', 'Jam Keluar', 'Durasi (Menit)', 'Supplier', 'No. PO', 'Nama Barang', 'Nomor Mobil', 'Qty Sak', 'Qty Pallet'
];
fputcsv($output, $headers, ';'); // Separator titik koma

function cleanCsvValue($value) {
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = trim($value);
    return $value;
}

$no = 1;
foreach ($unloading_records as $record) {
    if (empty($record['supplier']) && empty($record['nama_barang']) && (empty($record['qty_sak']) || $record['qty_sak'] == 0)) {
        continue;
    }
    
    $hari_inggris = $record['hari_formatted'] ?? '';
    $hari_indonesia = [
        'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu'
    ];
    $hari_formatted = $hari_indonesia[$hari_inggris] ?? $hari_inggris;
    
    $row = [
        cleanCsvValue($no++),
        cleanCsvValue($record['tanggal_formatted'] ?? ''),
        cleanCsvValue($hari_formatted),
        cleanCsvValue($record['jam_masuk_formatted'] ?? ''),
        cleanCsvValue($record['jam_start_qc_formatted'] ?? ''),
        cleanCsvValue($record['jam_finish_qc_formatted'] ?? ''),
        cleanCsvValue($record['jam_start_bongkar_formatted'] ?? ''),
        cleanCsvValue($record['jam_finish_bongkar_formatted'] ?? ''),
        cleanCsvValue($record['jam_keluar_formatted'] ?? ''),
        cleanCsvValue($record['durasi_bongkar'] ?? ''),
        cleanCsvValue($record['supplier'] ?? ''),
        cleanCsvValue($record['po_number'] ?? 'N/A'),
        cleanCsvValue($record['nama_barang'] ?? ''),
        cleanCsvValue($record['nomor_mobil'] ?? $record['no_mobil'] ?? ''),
        cleanCsvValue(number_format($record['qty_sak'] ?? 0, 2, ',', '.')),
        cleanCsvValue($record['qty_pallet'] ?? '0')
    ];
    fputcsv($output, $row, ';');
}

fclose($output);

exit(); 