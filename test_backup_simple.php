<?php
// Test backup sederhana
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
include 'koneksi.php';

header('Content-Type: application/json');

try {
    // Simple backup test
    $backup_data = [
        'status' => 'success',
        'message' => 'Backup test berhasil',
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => [
            'test' => 'This is a test backup file'
        ]
    ];
    
    echo json_encode($backup_data, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>