<?php
// Simple backup version for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/security_bootstrap.php';
    require_auth();
    include 'koneksi.php';
    
    $type = $_GET['type'] ?? 'all';
    $format = $_GET['format'] ?? 'json';
    
    // Simple test query
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM incoming_transactions");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $backup_data = [
        'status' => 'success',
        'type' => $type,
        'format' => $format,
        'timestamp' => date('Y-m-d H:i:s'),
        'total_incoming_transactions' => $result['total'],
        'test' => 'File backup berfungsi dengan baik'
    ];
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="test_backup.csv"');
        echo "Status,Type,Timestamp,Total_Transactions\n";
        echo "success,$type," . date('Y-m-d H:i:s') . "," . $result['total'] . "\n";
    } else {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="test_backup.json"');
        echo json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
?>