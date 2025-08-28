<?php
// Debug file untuk testing backup functionality
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/security_bootstrap.php';
require_auth();

try {
    include 'koneksi.php';
    echo "✅ Database connection OK<br>";
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "<br>";
    exit;
}

// Test query untuk batch habis
echo "<h3>Testing Empty Batches Query</h3>";

$sql_all_empty = "
    SELECT i.id, i.product_id, i.batch_number, i.quantity_kg, i.quantity_sacks,
           COALESCE(SUM(o.quantity_kg), 0) as used_kg,
           COALESCE(SUM(o.quantity_sacks), 0) as used_sacks,
           (i.quantity_kg - COALESCE(SUM(o.quantity_kg), 0)) as remaining_kg,
           (i.quantity_sacks - COALESCE(SUM(o.quantity_sacks), 0)) as remaining_sacks
    FROM incoming_transactions i
    LEFT JOIN outgoing_transactions o ON i.id = o.incoming_transaction_id
    GROUP BY i.id, i.product_id, i.batch_number, i.quantity_kg, i.quantity_sacks
    HAVING (i.quantity_kg - COALESCE(SUM(o.quantity_kg), 0)) <= 0 
       AND (i.quantity_sacks - COALESCE(SUM(o.quantity_sacks), 0)) <= 0
    LIMIT 10
";

try {
    $stmt = $pdo->prepare($sql_all_empty);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "✅ Query executed successfully<br>";
    echo "Found " . count($results) . " empty batches<br>";
    
    if (count($results) > 0) {
        echo "<table border='1' style='margin: 10px 0;'>";
        echo "<tr><th>ID</th><th>Batch</th><th>Original KG</th><th>Used KG</th><th>Remaining KG</th></tr>";
        foreach (array_slice($results, 0, 5) as $row) {
            echo "<tr>";
            echo "<td>" . $row['id'] . "</td>";
            echo "<td>" . $row['batch_number'] . "</td>";
            echo "<td>" . $row['quantity_kg'] . "</td>";
            echo "<td>" . $row['used_kg'] . "</td>";
            echo "<td>" . $row['remaining_kg'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No empty batches found - database might not have any used up batches yet<br>";
    }
} catch (PDOException $e) {
    echo "❌ Query error: " . $e->getMessage() . "<br>";
}

// Test basic backup data structure
echo "<h3>Testing Backup Data Structure</h3>";

$test_batch_ids = [1, 2, 3]; // Test with some IDs
$backup_data = [
    'backup_info' => [
        'created_at' => date('Y-m-d H:i:s'),
        'type' => 'test',
        'total_batches' => count($test_batch_ids),
        'version' => '1.0'
    ],
    'test_data' => 'This is a test backup'
];

echo "✅ Backup data structure created successfully<br>";
echo "<pre>" . json_encode($backup_data, JSON_PRETTY_PRINT) . "</pre>";

// Test simple backup endpoint
if (isset($_GET['download'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="test_backup.json"');
    echo json_encode($backup_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

echo "<br><a href='?download=1' target='_blank'>🔗 Test Download Backup</a><br>";
echo "<br><a href='export_batch_backup.php?type=all' target='_blank'>🔗 Test Real Backup (Fixed)</a>";
?>