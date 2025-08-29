<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
include 'koneksi.php';

/**
 * Format angka untuk export CSV dengan koma sebagai pemisah desimal
 * Tetap menggunakan koma sesuai permintaan user
 */
function formatAngkaExport($angka) {
    if ($angka === null || $angka === '') {
        return '0,00';
    }
    $nomor = (float)$angka;
    // Pastikan tetap gunakan koma sebagai pemisah desimal
    return number_format($nomor, 2, ',', '');
}

$filter_date = $_GET['filter_date'] ?? null; // fallback: filter satu tanggal (opsional)
$start_date = $_GET['start_date'] ?? null;   // sesuai halaman: rentang tanggal
$end_date   = $_GET['end_date'] ?? null;     // sesuai halaman: rentang tanggal
$filter_status = $_GET['status_filter'] ?? '';
$search_query = $_GET['s'] ?? '';
$po_query = $_GET['po'] ?? '';
$supplier_query = $_GET['sup'] ?? '';
$doc_query = $_GET['doc'] ?? '';
$batch_query = $_GET['batch'] ?? '';

$sql = "SELECT 
            t.po_number,
            t.supplier,
            t.license_plate,
            p.product_name,
            p.sku,
            t.quantity_kg,
            t.quantity_sacks,
            t.document_number,
            t.lot_number
        FROM incoming_transactions t
        JOIN products p ON t.product_id = p.id
        WHERE 1=1";

$params = [];
if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND t.transaction_date BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date']   = $end_date;
} elseif (!empty($filter_date)) {
    $sql .= " AND t.transaction_date = :filter_date";
    $params[':filter_date'] = $filter_date;
}
if (!empty($filter_status)) {
    $sql .= " AND t.status = :status_filter";
    $params[':status_filter'] = $filter_status;
}
if (!empty($search_query)) {
    $sql .= " AND (p.product_name LIKE :search OR p.sku LIKE :search)";
    $params[':search'] = '%' . $search_query . '%';
}
if (!empty($po_query)) {
    $sql .= " AND t.po_number LIKE :po_number";
    $params[':po_number'] = '%' . $po_query . '%';
}
if (!empty($supplier_query)) {
    $sql .= " AND t.supplier LIKE :supplier_query";
    $params[':supplier_query'] = '%' . $supplier_query . '%';
}
if (!empty($doc_query)) {
    $sql .= " AND t.document_number LIKE :document_number";
    $params[':document_number'] = '%' . $doc_query . '%';
}
if (!empty($batch_query)) {
    $sql .= " AND t.batch_number LIKE :batch_number";
    $params[':batch_number'] = '%' . $batch_query . '%';
}

$sql .= " ORDER BY t.transaction_date ASC, t.created_at ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($start_date) && !empty($end_date)) {
    $filename = "laporan_barang_masuk_{$start_date}_sd_{$end_date}.csv";
} elseif (!empty($filter_date)) {
    $filename = "laporan_barang_masuk_{$filter_date}.csv";
} else {
    $filename = "laporan_barang_masuk.csv";
}

// Set headers untuk CSV dengan separator semicolon untuk kolom yang rapi
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

// UTF-8 BOM untuk kompatibilitas karakter Indonesia
echo "\xEF\xBB\xBF";
// Instruksi separator untuk aplikasi spreadsheet
echo "sep=;\n";

$output = fopen('php://output', 'w');

$header = ['Nomor PO', 'Supplier', 'Nomor Kendaraan', 'Nama Barang', 'Kode Barang', 'Qty (Sak)', 'Qty (Kg)', 'Nomor Dokumen', '501'];
fputcsv($output, $header, ';', '"');

function cleanCsvValue($value) {
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = trim($value);
    // Escape quotes untuk CSV
    $value = str_replace('"', '""', $value);
    return $value;
}

foreach ($transactions as $row) {
    $csv_row = [
        cleanCsvValue($row['po_number'] ?? ''),
        cleanCsvValue($row['supplier'] ?? ''),
        cleanCsvValue($row['license_plate'] ?? ''),
        cleanCsvValue($row['product_name'] ?? ''),
        cleanCsvValue($row['sku'] ?? ''),
        cleanCsvValue(formatAngkaExport($row['quantity_sacks'] ?? 0)),
        cleanCsvValue(formatAngkaExport($row['quantity_kg'] ?? 0)),
        cleanCsvValue($row['document_number'] ?? ''),
        cleanCsvValue(formatAngkaExport($row['lot_number'] ?? 0))
    ];
    fputcsv($output, $csv_row, ';', '"');
}

fclose($output);
exit();
?>
