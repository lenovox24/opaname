<?php
require_once __DIR__ . '/../koneksi.php';

/**
 * This migration increases precision/scale for numeric columns so values like 1992,999 are preserved.
 * It is safe to run multiple times; errors are caught and reported per statement.
 */

$alters = [
    // Incoming transactions
    "ALTER TABLE incoming_transactions MODIFY quantity_kg DECIMAL(18,6) NOT NULL DEFAULT 0",
    "ALTER TABLE incoming_transactions MODIFY quantity_sacks DECIMAL(18,6) NOT NULL DEFAULT 0",
    "ALTER TABLE incoming_transactions MODIFY lot_number DECIMAL(18,6) NOT NULL DEFAULT 0",
    "ALTER TABLE incoming_transactions MODIFY grossweight_kg DECIMAL(18,6) NOT NULL DEFAULT 0",

    // Outgoing transactions
    "ALTER TABLE outgoing_transactions MODIFY quantity_kg DECIMAL(18,6) NOT NULL DEFAULT 0",
    "ALTER TABLE outgoing_transactions MODIFY quantity_sacks DECIMAL(18,6) NOT NULL DEFAULT 0",
    "ALTER TABLE outgoing_transactions MODIFY lot_number DECIMAL(18,6) NOT NULL DEFAULT 0",
];

$results = [];
foreach ($alters as $sql) {
    try {
        $pdo->exec($sql);
        $results[] = ["sql" => $sql, "status" => "OK"]; 
    } catch (Throwable $e) {
        $results[] = ["sql" => $sql, "status" => "ERROR", "message" => $e->getMessage()];
    }
}

header('Content-Type: application/json');
echo json_encode(["migrated" => true, "results" => $results], JSON_PRETTY_PRINT);