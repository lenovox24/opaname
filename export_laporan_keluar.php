<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
include 'koneksi.php';

function format_tanggal_indo_laporan($tanggal)
{
    if (empty($tanggal)) return '';
    $timestamp = strtotime($tanggal);
    return date('d/m/Y', $timestamp);
}

/**
 * Format angka untuk export CSV dengan koma sebagai pemisah desimal,
 * mempertahankan jumlah digit desimal sesuai input (atau hasil agregasi)
 * tanpa pembulatan berlebih.
 */
function formatAngkaExport($angka) {
    if ($angka === null || $angka === '') return '0';
    $raw = trim((string)$angka);
    $normalized = str_replace(',', '.', $raw);
    if (!is_numeric($normalized)) return $raw;
    $num = (float)$normalized;
    // Batasi menjadi maksimal 3 angka desimal (dibulatkan)
    $rounded = round($num, 3);
    $formatted = number_format($rounded, 3, ',', '');
    // Hilangkan nol berlebih dan koma jika tidak ada desimal
    $formatted = rtrim($formatted, '0');
    $formatted = rtrim($formatted, ',');
    return $formatted === '' ? '0' : $formatted;
}

/**
 * Normalisasi string desimal: buang pemisah ribuan, pakai '.' sebagai desimal.
 */
function normalizeDecimal($value) {
    if ($value === null || $value === '') return '0';
    $s = trim((string)$value);
    // Ubah koma jadi titik untuk desimal
    $s = str_replace(',', '.', $s);
    // Buang spasi
    $s = str_replace(' ', '', $s);
    return $s;
}

/**
 * Penjumlahan desimal berbasis string (gunakan BC Math jika tersedia),
 * untuk menghindari kehilangan presisi.
 */
function decimal_add($a, $b, $scale = 12) {
    $a = normalizeDecimal($a);
    $b = normalizeDecimal($b);
    if (function_exists('bcadd')) {
        $sum = bcadd($a, $b, $scale);
        // Hilangkan trailing nol dan titik jika tidak perlu
        $sum = rtrim(rtrim($sum, '0'), '.');
        return $sum === '' ? '0' : $sum;
    }
    // Fallback: gunakan float lalu format skala tinggi, kurangi nol di akhir
    $sum = (float)$a + (float)$b;
    $formatted = number_format($sum, $scale, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}

function cleanCsvValue($value) {
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);
    $value = trim($value);
    $value = str_replace('"', '""', $value);
    return $value;
}

$filter_date = $_GET['filter_date'] ?? null;          // satu tanggal
$start_date  = $_GET['start_date'] ?? null;            // rentang tanggal
$end_date    = $_GET['end_date'] ?? null;
$status      = $_GET['status_filter'] ?? '';           // status
$search_q    = $_GET['s'] ?? '';                       // nama/kode
$doc_q       = $_GET['doc'] ?? '';                     // dokumen
$batch_q     = $_GET['batch'] ?? '';                   // batch
$desc_q      = $_GET['ket'] ?? '';                     // keterangan

$sql = "
    SELECT
        t.transaction_date,
        p.product_name,
        p.sku,
        t.quantity_kg,
        t.quantity_sacks,
        t.document_number,
        t.batch_number,
        t.description,
        t.status
    FROM
        outgoing_transactions t
    JOIN
        products p ON t.product_id = p.id
    WHERE 1=1
    AND (
        -- Kecualikan transaksi 501 (pindahan internal) berdasarkan lot_number
        t.lot_number = 0
    )
";

$params = [];
if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND t.transaction_date BETWEEN :start AND :end";
    $params[':start'] = $start_date;
    $params[':end'] = $end_date;
} elseif (!empty($filter_date)) {
    $sql .= " AND t.transaction_date = :filter_date";
    $params[':filter_date'] = $filter_date;
}

if (!empty($status)) {
    $sql .= " AND t.status = :status";
    $params[':status'] = $status;
}
if (!empty($search_q)) {
    $sql .= " AND (p.product_name LIKE :search OR p.sku LIKE :search)";
    $params[':search'] = '%' . $search_q . '%';
}
if (!empty($doc_q)) {
    $sql .= " AND t.document_number LIKE :doc";
    $params[':doc'] = '%' . $doc_q . '%';
}
if (!empty($batch_q)) {
    $sql .= " AND t.batch_number LIKE :batch";
    $params[':batch'] = '%' . $batch_q . '%';
}
if (!empty($desc_q)) {
    $sql .= " AND t.description LIKE :desc";
    $params[':desc'] = '%' . $desc_q . '%';
}

// Order by urutan input (created_at) untuk konsistensi dari awal input
$sql .= " ORDER BY t.transaction_date ASC, t.created_at ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate filename
if (!empty($start_date) && !empty($end_date)) {
    $filename = "laporan_barang_keluar_{$start_date}_sd_{$end_date}.csv";
} elseif (!empty($filter_date)) {
    $filename = "laporan_barang_keluar_{$filter_date}.csv";
} else {
    $filename = "laporan_barang_keluar_semua.csv";
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

// Header tabel yang rapi
$header = ['Nama Barang', 'Kode Barang', 'Qty (Sak)', 'Qty (Kg)', 'Keterangan', 'No. Dokumen'];
fputcsv($output, $header, ';', '"');

// Group data berdasarkan document_number dan description untuk auto merge & center
$grouped_data = [];
foreach ($results as $row) {
    $key = ($row['document_number'] ?? '') . '|' . ($row['description'] ?? '');
    if (!isset($grouped_data[$key])) {
        $grouped_data[$key] = [
            'document_number' => $row['document_number'] ?? '',
            'description' => $row['description'] ?? '',
            'items' => []
        ];
    }
    $grouped_data[$key]['items'][] = $row;
}

// Output data dengan auto merge & center untuk nomor dokumen dan keterangan
// dan gabungkan item yang sama (berdasarkan SKU + Nama) menjadi satu baris dengan Qty dijumlahkan
foreach ($grouped_data as $group) {
    $first_item = true;

    // Aggregate quantities by SKU + Product Name
    $aggregated_items = [];
    foreach ($group['items'] as $row) {
        $key = ($row['sku'] ?? '') . '|' . ($row['product_name'] ?? '');
        if (!isset($aggregated_items[$key])) {
            $aggregated_items[$key] = $row;
            $aggregated_items[$key]['quantity_sacks'] = normalizeDecimal($row['quantity_sacks'] ?? '0');
            $aggregated_items[$key]['quantity_kg'] = normalizeDecimal($row['quantity_kg'] ?? '0');
        } else {
            $aggregated_items[$key]['quantity_sacks'] = decimal_add($aggregated_items[$key]['quantity_sacks'], $row['quantity_sacks'] ?? '0');
            $aggregated_items[$key]['quantity_kg'] = decimal_add($aggregated_items[$key]['quantity_kg'], $row['quantity_kg'] ?? '0');
        }
    }

    foreach ($aggregated_items as $row) {
        $csv_row = [
            cleanCsvValue($row['product_name'] ?? ''),
            cleanCsvValue($row['sku'] ?? ''),
            cleanCsvValue(formatAngkaExport($row['quantity_sacks'] ?? '0')),
            cleanCsvValue(formatAngkaExport($row['quantity_kg'] ?? '0')),
            // Auto merge & center: keterangan hanya tampil di baris pertama grup yang sama
            $first_item ? cleanCsvValue($group['description']) : '',
            // Auto merge & center: nomor dokumen hanya tampil di baris pertama grup yang sama
            $first_item ? cleanCsvValue($group['document_number']) : ''
        ];
        fputcsv($output, $csv_row, ';', '"');
        $first_item = false;
    }
}

fclose($output);
exit();
?>
