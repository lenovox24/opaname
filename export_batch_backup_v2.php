<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
include 'koneksi.php';

try {
    $type = $_GET['type'] ?? 'all';
    $format = $_GET['format'] ?? 'json';
    $batch_ids = [];

    // Get batch IDs based on type
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
            // Query untuk batch habis - disederhanakan
            $sql = "SELECT i.id, i.quantity_kg, i.quantity_sacks,
                           COALESCE(SUM(o.quantity_kg), 0) as used_kg,
                           COALESCE(SUM(o.quantity_sacks), 0) as used_sacks
                    FROM incoming_transactions i
                    LEFT JOIN outgoing_transactions o ON i.id = o.incoming_transaction_id
                    GROUP BY i.id, i.quantity_kg, i.quantity_sacks
                    HAVING (i.quantity_kg - COALESCE(SUM(o.quantity_kg), 0)) <= 0 
                       AND (i.quantity_sacks - COALESCE(SUM(o.quantity_sacks), 0)) <= 0
                    ORDER BY i.id DESC
                    LIMIT 100";
            $stmt = $pdo->query($sql);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $batch_ids = array_column($results, 'id');
            break;
    }

    if (empty($batch_ids)) {
        $response = [
            'status' => 'info',
            'message' => 'Tidak ada batch habis yang ditemukan untuk di-backup',
            'type' => $type,
            'total_batches' => 0
        ];
        
        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Tidak ada batch habis yang ditemukan.\n";
        }
        exit;
    }

    // Kumpulkan data untuk backup
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    
    // Incoming transactions
    $stmt_incoming = $pdo->prepare("
        SELECT i.*, p.product_name, p.sku 
        FROM incoming_transactions i 
        JOIN products p ON i.product_id = p.id 
        WHERE i.id IN ($placeholders)
        ORDER BY i.transaction_date DESC
    ");
    $stmt_incoming->execute($batch_ids);
    $incoming_data = $stmt_incoming->fetchAll(PDO::FETCH_ASSOC);

    // Outgoing transactions
    $stmt_outgoing = $pdo->prepare("
        SELECT o.*, p.product_name, p.sku 
        FROM outgoing_transactions o 
        JOIN products p ON o.product_id = p.id 
        WHERE o.incoming_transaction_id IN ($placeholders)
        ORDER BY o.transaction_date DESC
    ");
    $stmt_outgoing->execute($batch_ids);
    $outgoing_data = $stmt_outgoing->fetchAll(PDO::FETCH_ASSOC);

    // Buat data backup
    $backup_data = [
        'backup_info' => [
            'created_at' => date('Y-m-d H:i:s'),
            'type' => $type,
            'total_batches' => count($batch_ids),
            'batch_ids' => $batch_ids,
            'format' => $format
        ],
        'incoming_transactions' => $incoming_data,
        'outgoing_transactions' => $outgoing_data,
        'summary' => [
            'total_incoming_records' => count($incoming_data),
            'total_outgoing_records' => count($outgoing_data),
            'total_kg_in' => array_sum(array_column($incoming_data, 'quantity_kg')),
            'total_kg_out' => array_sum(array_column($outgoing_data, 'quantity_kg'))
        ]
    ];

    // Output berdasarkan format
    $filename = 'backup_batch_habis_' . date('Y-m-d_H-i-s') . '_' . $type;
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['=== BACKUP BATCH HABIS ===']);
        fputcsv($output, ['Dibuat: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, ['Tipe: ' . $type]);
        fputcsv($output, ['Total Batch: ' . count($batch_ids)]);
        fputcsv($output, []);
        
        // Header incoming
        fputcsv($output, ['=== DATA INCOMING ===']);
        if (!empty($incoming_data)) {
            fputcsv($output, array_keys($incoming_data[0]));
            foreach ($incoming_data as $row) {
                fputcsv($output, $row);
            }
        }
        
        fputcsv($output, []);
        
        // Header outgoing
        fputcsv($output, ['=== DATA OUTGOING ===']);
        if (!empty($outgoing_data)) {
            fputcsv($output, array_keys($outgoing_data[0]));
            foreach ($outgoing_data as $row) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
    } else {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        echo json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Backup gagal: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'type' => $_GET['type'] ?? 'unknown',
        'debug_info' => [
            'php_version' => PHP_VERSION,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ], JSON_PRETTY_PRINT);
} catch (Error $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'status' => 'fatal_error',
        'message' => 'Fatal error: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
?>