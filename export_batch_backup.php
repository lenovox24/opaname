<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
include 'koneksi.php';

$type = $_GET['type'] ?? 'all';
$batch_ids = [];

// Determine which batches to backup
switch ($type) {
    case 'single':
        if (isset($_GET['id']) && is_numeric($_GET['id'])) {
            $batch_ids = [(int)$_GET['id']];
        }
        break;
    case 'selected':
        if (isset($_GET['ids'])) {
            $ids = explode(',', $_GET['ids']);
            $batch_ids = array_filter(array_map('intval', $ids));
        }
        break;
    case 'all':
    default:
        // Get all empty batches
        $sql_all_empty = "
            SELECT i.id
            FROM incoming_transactions i
            LEFT JOIN outgoing_transactions o ON i.id = o.incoming_transaction_id
            GROUP BY i.id
            HAVING (i.quantity_kg - COALESCE(SUM(o.quantity_kg), 0)) <= 0 
               AND (i.quantity_sacks - COALESCE(SUM(o.quantity_sacks), 0)) <= 0
        ";
        $stmt = $pdo->prepare($sql_all_empty);
        $stmt->execute();
        $batch_ids = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
        break;
}

if (empty($batch_ids)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tidak ada batch yang dipilih untuk backup']);
    exit;
}

// Build backup data
$backup_data = [
    'backup_info' => [
        'created_at' => date('Y-m-d H:i:s'),
        'type' => $type,
        'total_batches' => count($batch_ids),
        'version' => '1.0'
    ],
    'incoming_transactions' => [],
    'outgoing_transactions' => [],
    'products' => []
];

try {
    // Get incoming transactions (batch data)
    $placeholders = str_repeat('?,', count($batch_ids) - 1) . '?';
    $sql_incoming = "
        SELECT i.*, p.product_name, p.sku
        FROM incoming_transactions i
        JOIN products p ON i.product_id = p.id
        WHERE i.id IN ($placeholders)
        ORDER BY i.transaction_date, i.created_at
    ";
    $stmt_incoming = $pdo->prepare($sql_incoming);
    $stmt_incoming->execute($batch_ids);
    $backup_data['incoming_transactions'] = $stmt_incoming->fetchAll(PDO::FETCH_ASSOC);

    // Get related outgoing transactions
    $sql_outgoing = "
        SELECT o.*, p.product_name, p.sku
        FROM outgoing_transactions o
        JOIN products p ON o.product_id = p.id
        WHERE o.incoming_transaction_id IN ($placeholders)
        ORDER BY o.transaction_date, o.created_at
    ";
    $stmt_outgoing = $pdo->prepare($sql_outgoing);
    $stmt_outgoing->execute($batch_ids);
    $backup_data['outgoing_transactions'] = $stmt_outgoing->fetchAll(PDO::FETCH_ASSOC);

    // Get unique products involved
    $product_ids = array_unique(array_column($backup_data['incoming_transactions'], 'product_id'));
    if (!empty($product_ids)) {
        $placeholders_products = str_repeat('?,', count($product_ids) - 1) . '?';
        $sql_products = "SELECT * FROM products WHERE id IN ($placeholders_products) ORDER BY product_name";
        $stmt_products = $pdo->prepare($sql_products);
        $stmt_products->execute($product_ids);
        $backup_data['products'] = $stmt_products->fetchAll(PDO::FETCH_ASSOC);
    }

    // Calculate summary statistics
    $backup_data['summary'] = [
        'total_incoming_qty_kg' => array_sum(array_column($backup_data['incoming_transactions'], 'quantity_kg')),
        'total_incoming_qty_sacks' => array_sum(array_column($backup_data['incoming_transactions'], 'quantity_sacks')),
        'total_outgoing_qty_kg' => array_sum(array_column($backup_data['outgoing_transactions'], 'quantity_kg')),
        'total_outgoing_qty_sacks' => array_sum(array_column($backup_data['outgoing_transactions'], 'quantity_sacks')),
        'unique_products' => count($product_ids),
        'date_range' => [
            'earliest' => min(array_column($backup_data['incoming_transactions'], 'transaction_date')),
            'latest' => max(array_column($backup_data['incoming_transactions'], 'transaction_date'))
        ]
    ];

} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// Generate filename
$filename = 'backup_batch_habis_' . date('Y-m-d_H-i-s') . '_' . $type;
if ($type === 'single') {
    $filename .= '_id_' . $batch_ids[0];
} elseif ($type === 'selected') {
    $filename .= '_' . count($batch_ids) . '_items';
}

// Determine output format (JSON by default, can be extended for CSV/Excel)
$format = $_GET['format'] ?? 'json';

switch ($format) {
    case 'csv':
        // CSV export for incoming transactions
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // CSV Header
        fputcsv($output, ['=== BACKUP BATCH HABIS - ' . strtoupper($type) . ' ===']);
        fputcsv($output, ['Dibuat pada: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, ['Total batch: ' . count($batch_ids)]);
        fputcsv($output, []);
        
        // Incoming transactions
        fputcsv($output, ['=== DATA BATCH MASUK ===']);
        fputcsv($output, [
            'ID', 'Tanggal', 'Nama Produk', 'SKU', 'Batch Number', 'Supplier', 
            'PO Number', 'Qty (Kg)', 'Qty (Sacks)', 'Document Number', 'Status'
        ]);
        
        foreach ($backup_data['incoming_transactions'] as $row) {
            fputcsv($output, [
                $row['id'],
                $row['transaction_date'],
                $row['product_name'],
                $row['sku'],
                $row['batch_number'],
                $row['supplier'],
                $row['po_number'],
                $row['quantity_kg'],
                $row['quantity_sacks'],
                $row['document_number'],
                $row['status']
            ]);
        }
        
        fputcsv($output, []);
        
        // Outgoing transactions
        fputcsv($output, ['=== DATA PENGELUARAN TERKAIT ===']);
        fputcsv($output, [
            'ID', 'Tanggal', 'Nama Produk', 'SKU', 'Batch ID', 
            'Qty (Kg)', 'Qty (Sacks)', 'Document Number', 'Keterangan', 'Status'
        ]);
        
        foreach ($backup_data['outgoing_transactions'] as $row) {
            fputcsv($output, [
                $row['id'],
                $row['transaction_date'],
                $row['product_name'],
                $row['sku'],
                $row['incoming_transaction_id'],
                $row['quantity_kg'],
                $row['quantity_sacks'],
                $row['document_number'],
                $row['description'],
                $row['status']
            ]);
        }
        
        fclose($output);
        break;
        
    case 'json':
    default:
        // JSON export (detailed format)
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        
        echo json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;
}

exit;
?>