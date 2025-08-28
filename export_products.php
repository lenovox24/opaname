<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
include 'koneksi.php';

$sql = "
    SELECT
        p.id,
        p.sku,
        p.product_name,
        p.standard_qty,
        (SELECT COALESCE(SUM(quantity_kg), 0) FROM incoming_transactions WHERE product_id = p.id) AS total_masuk_kg,
        (SELECT COALESCE(SUM(quantity_sacks), 0) FROM incoming_transactions WHERE product_id = p.id) AS total_masuk_sak,
        (SELECT COALESCE(SUM(quantity_kg), 0) FROM outgoing_transactions WHERE product_id = p.id) AS total_keluar_kg,
        (SELECT COALESCE(SUM(quantity_sacks), 0) FROM outgoing_transactions WHERE product_id = p.id) AS total_keluar_sak,
        (SELECT COALESCE(SUM(lot_number), 0) FROM incoming_transactions WHERE product_id = p.id) AS total_501_masuk,
        (SELECT COALESCE(SUM(lot_number), 0) FROM outgoing_transactions WHERE product_id = p.id) AS total_501_keluar
    FROM
        products p
    ORDER BY
        p.product_name ASC
";

$stmt = $pdo->query($sql);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$filename = "Data_Produk_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

$headers = [
    'No', 'SKU', 'Nama Produk', 'Standard Qty (Kg)', 
    'Total Masuk (Kg)', 'Total Masuk (Sak)', 
    'Total Keluar (Kg)', 'Total Keluar (Sak)',
    'Stok Tersisa (Kg)', 'Stok Tersisa (Sak)',
    'Total 501 Masuk', 'Total 501 Keluar', 'Sisa 501'
];
fputcsv($output, $headers, ';'); // Separator titik koma

function cleanCsvValue($value) {
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = trim($value);
    return $value;
}

$no = 1;
foreach ($products as $product) {
    $stok_kg = $product['total_masuk_kg'] - $product['total_keluar_kg'];
    $stok_sak = $product['total_masuk_sak'] - $product['total_keluar_sak'];
    $sisa_501 = $product['total_501_masuk'] - $product['total_501_keluar'];
    
    $row = [
        cleanCsvValue($no++),
        cleanCsvValue($product['sku'] ?? ''),
        cleanCsvValue($product['product_name'] ?? ''),
        cleanCsvValue(number_format($product['standard_qty'] ?? 0, 2, ',', '.')),
        cleanCsvValue(number_format($product['total_masuk_kg'] ?? 0, 2, ',', '.')),
        cleanCsvValue(number_format($product['total_masuk_sak'] ?? 0, 2, ',', '.')),
        cleanCsvValue(number_format($product['total_keluar_kg'] ?? 0, 2, ',', '.')),
        cleanCsvValue(number_format($product['total_keluar_sak'] ?? 0, 2, ',', '.')),
        cleanCsvValue(number_format($stok_kg, 2, ',', '.')),
        cleanCsvValue(number_format($stok_sak, 2, ',', '.')),
        cleanCsvValue(number_format($product['total_501_masuk'] ?? 0, 2, ',', '.')),
        cleanCsvValue(number_format($product['total_501_keluar'] ?? 0, 2, ',', '.')),
        cleanCsvValue(number_format($sisa_501, 2, ',', '.'))
    ];
    fputcsv($output, $row, ';');
}

fclose($output);
exit();
?>
