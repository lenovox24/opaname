<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
include 'koneksi.php';

$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$filter_qty_kg = $_GET['filter_qty_kg'] ?? '';

$previous_date = date('Y-m-d', strtotime($filter_date . ' -1 day'));
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime($today . ' +1 day'));

// Tentukan sistem yang digunakan
$use_batch_closing = ($filter_date <= $tomorrow); // Closingan batch untuk tgl 23-24
$use_mixed_system = ($filter_date > $tomorrow);   // Mixed system untuk tgl 25+

if ($use_batch_closing) {
    // SISTEM CLOSINGAN BATCH - untuk tanggal 23-24
    $sql = "
        SELECT
            p.id, p.sku, p.product_name,
            
            -- Stock awal: total semua transaksi sampai hari sebelumnya (H-1)
            (COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END), 0))
            AS opening_stock_kg,
            
            (COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date <= ? THEN t.quantity_sacks ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date <= ? THEN t.quantity_sacks ELSE 0 END), 0))
            AS opening_stock_sak,

            -- Transaksi masuk pada hari laporan
            COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date = ? THEN t.quantity_kg ELSE 0 END), 0) AS incoming_kg_today,
            COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date = ? THEN t.quantity_sacks ELSE 0 END), 0) AS incoming_sak_today,
            
            -- Transaksi keluar pada hari laporan
            COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date = ? THEN t.quantity_kg ELSE 0 END), 0) AS outgoing_kg_today,
            COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date = ? THEN t.quantity_sacks ELSE 0 END), 0) AS outgoing_sak_today,
            
            -- Stock akhir dari closingan batch sampai tanggal laporan (24)
            (COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END), 0))
            AS closing_stock_kg_batch,
            
            (COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date <= ? THEN t.quantity_sacks ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date <= ? THEN t.quantity_sacks ELSE 0 END), 0))
            AS closing_stock_sak_batch

        FROM
            products p
        LEFT JOIN (
            SELECT 
                product_id, 
                transaction_date, 
                quantity_kg, 
                quantity_sacks, 
                batch_number,
                'IN' as type 
            FROM incoming_transactions
            WHERE status = 'Closed'
            UNION ALL
            SELECT 
                product_id, 
                transaction_date, 
                quantity_kg, 
                quantity_sacks, 
                batch_number,
                'OUT' as type 
            FROM outgoing_transactions
            WHERE status = 'Closed'
        ) as t ON p.id = t.product_id
        GROUP BY
            p.id, p.sku, p.product_name
        ORDER BY
            p.product_name ASC
    ";
    
    $params = [
        $previous_date,  // opening_stock_kg (IN) - sampai H-1
        $previous_date,  // opening_stock_kg (OUT) - sampai H-1
        $previous_date,  // opening_stock_sak (IN) - sampai H-1
        $previous_date,  // opening_stock_sak (OUT) - sampai H-1
        $filter_date,    // incoming_kg_today - pada hari laporan
        $filter_date,    // incoming_sak_today - pada hari laporan
        $filter_date,    // outgoing_kg_today - pada hari laporan
        $filter_date,    // outgoing_sak_today - pada hari laporan
        $filter_date,    // closing_stock_kg_batch (IN) - closing batch sampai tanggal laporan (24)
        $filter_date,    // closing_stock_kg_batch (OUT) - closing batch sampai tanggal laporan (24)
        $filter_date,    // closing_stock_sak_batch (IN) - closing batch sampai tanggal laporan (24)
        $filter_date,    // closing_stock_sak_batch (OUT) - closing batch sampai tanggal laporan (24)
    ];
    
} else {
    // SISTEM MIXED - untuk tanggal 25 dan selanjutnya
    // Stock awal dari stock akhir H-1, stock akhir dengan rumus normal
    
    // Untuk stock awal, gunakan stock sampai H-1
    // Jika H-1 <= tomorrow (tgl 24), maka gunakan stock batch sampai H-1 (tanggal 24)
    // Jika H-1 > tomorrow (tgl 25+), maka gunakan perhitungan normal sampai H-1
    $stock_reference_date = ($previous_date <= $tomorrow) ? $previous_date : $previous_date;
    
    $sql = "
        SELECT
            p.id, p.sku, p.product_name,
            
            -- Stock awal: stock akhir dari H-1
            (COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END), 0))
            AS opening_stock_kg,
            
            (COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date <= ? THEN t.quantity_sacks ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date <= ? THEN t.quantity_sacks ELSE 0 END), 0))
            AS opening_stock_sak,

            -- Transaksi masuk dan keluar pada H-1 
            COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date = ? THEN t.quantity_kg ELSE 0 END), 0) AS incoming_kg_today,
            COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date = ? THEN t.quantity_sacks ELSE 0 END), 0) AS incoming_sak_today,
            
            COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date = ? THEN t.quantity_kg ELSE 0 END), 0) AS outgoing_kg_today,
            COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date = ? THEN t.quantity_sacks ELSE 0 END), 0) AS outgoing_sak_today

        FROM
            products p
        LEFT JOIN (
            SELECT 
                product_id, 
                transaction_date, 
                quantity_kg, 
                quantity_sacks, 
                'IN' as type 
            FROM incoming_transactions
            WHERE status = 'Closed'
            UNION ALL
            SELECT 
                product_id, 
                transaction_date, 
                quantity_kg, 
                quantity_sacks, 
                'OUT' as type 
            FROM outgoing_transactions
            WHERE status = 'Closed'
        ) as t ON p.id = t.product_id
        GROUP BY
            p.id, p.sku, p.product_name
        ORDER BY
            p.product_name ASC
    ";
    
    $params = [
        $stock_reference_date,  // opening_stock_kg (IN) - stock akhir H-1
        $stock_reference_date,  // opening_stock_kg (OUT) - stock akhir H-1
        $stock_reference_date,  // opening_stock_sak (IN) - stock akhir H-1
        $stock_reference_date,  // opening_stock_sak (OUT) - stock akhir H-1
        $previous_date,         // incoming_kg_today - transaksi H-1
        $previous_date,         // incoming_sak_today - transaksi H-1
        $previous_date,         // outgoing_kg_today - transaksi H-1
        $previous_date,         // outgoing_sak_today - transaksi H-1
    ];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "laporan_harian_" . $filter_date . ".csv";

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

echo "\xEF\xBB\xBF";
echo "sep=;\n";

$output = fopen('php://output', 'w');

function formatAngkaCSV($angka) {
    if ($angka === null || $angka === '') {
        return '0,00';
    }
    $nomor = (float)$angka;
    return number_format($nomor, 2, ',', ''); // Gunakan koma untuk desimal
}

fputcsv($output, ['LAPORAN HARIAN STOCK'], ';');
fputcsv($output, ['Tanggal:', date('d/m/Y', strtotime($filter_date))], ';');
fputcsv($output, ['Waktu Export:', date('d/m/Y H:i:s')], ';');

if ($use_batch_closing) {
    if ($filter_date <= $today) {
        fputcsv($output, ['Keterangan:', 'Stock Akhir menggunakan closingan batch per tanggal ' . date('d/m/Y', strtotime($filter_date))], ';');
    } else {
        fputcsv($output, ['Keterangan:', 'Stock Akhir tetap dari closingan batch hari ini (' . date('d/m/Y', strtotime($today)) . '), tidak peduli transaksi'], ';');
    }
} else {
    fputcsv($output, ['Keterangan:', 'Stock Awal dari stock akhir H-1, Stock Akhir = Stock Awal + Masuk H-1 - Keluar H-1'], ';');
}

if (!empty($filter_qty_kg)) {
    fputcsv($output, ['Filter Qty Kg:', '>= ' . number_format($filter_qty_kg, 2, ',', '')], ';');
}
fputcsv($output, ['DETAIL DATA:'], ';');

$header = ['No', 'Kode Barang', 'Nama Barang', 'Stok Awal (Kg)', 'Stok Awal (Sak)', 'Masuk (Kg)', 'Masuk (Sak)', 'Keluar (Kg)', 'Keluar (Sak)', 'Stok Akhir (Kg)', 'Stok Akhir (Sak)', 'Rata-rata Qty'];
fputcsv($output, $header, ';');

$nomor = 1;
foreach ($results as $row) {
    if ($use_batch_closing) {
        // Gunakan stock akhir dari closingan batch (tidak peduli transaksi hari ini)
        $closing_stock_kg = $row['closing_stock_kg_batch'];
        $closing_stock_sak = $row['closing_stock_sak_batch'];
    } else {
        // Gunakan rumus normal: stock awal + masuk - keluar
        $closing_stock_kg = $row['opening_stock_kg'] + $row['incoming_kg_today'] - $row['outgoing_kg_today'];
        $closing_stock_sak = $row['opening_stock_sak'] + $row['incoming_sak_today'] - $row['outgoing_sak_today'];
    }

    if (is_numeric($filter_qty_kg) && $closing_stock_kg < (float)$filter_qty_kg) {
        continue;
    }

    $average_qty = ($closing_stock_sak != 0) ? $closing_stock_kg / $closing_stock_sak : 0;

    $csv_row = [
        $nomor++,
        $row['sku'] ?? '',
        $row['product_name'] ?? '',
        formatAngkaCSV($row['opening_stock_kg'] ?? 0),
        formatAngkaCSV($row['opening_stock_sak'] ?? 0),
        formatAngkaCSV($row['incoming_kg_today'] ?? 0),
        formatAngkaCSV($row['incoming_sak_today'] ?? 0),
        formatAngkaCSV($row['outgoing_kg_today'] ?? 0),
        formatAngkaCSV($row['outgoing_sak_today'] ?? 0),
        formatAngkaCSV($closing_stock_kg),
        formatAngkaCSV($closing_stock_sak),
        formatAngkaCSV($average_qty),
    ];
    fputcsv($output, $csv_row, ';');
}

fclose($output);
exit();
?>
