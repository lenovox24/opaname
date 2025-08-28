<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
header('Content-Type: application/json');
include 'koneksi.php';

$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
if ($product_id === 0) {
    echo json_encode([]);
    exit();
}

try {
    // REVISI: Menambahkan total keseluruhan sak dari semua batch per item
    $sql = "
        SELECT
            t_in.id,
            t_in.transaction_date,
            t_in.po_number,
            t_in.batch_number,
            t_in.supplier,
            (t_in.quantity_kg - COALESCE(SUM(t_out.quantity_kg), 0)) AS sisa_stok_kg,
            (t_in.quantity_sacks - COALESCE(SUM(t_out.quantity_sacks), 0)) AS sisa_stok_sak,
            -- Total keseluruhan sak dari semua batch per product
            (SELECT SUM(t_in2.quantity_sacks - COALESCE(t_out2.total_out_sacks, 0))
             FROM incoming_transactions t_in2
             LEFT JOIN (
                 SELECT incoming_transaction_id, SUM(quantity_sacks) as total_out_sacks
                 FROM outgoing_transactions 
                 GROUP BY incoming_transaction_id
             ) t_out2 ON t_in2.id = t_out2.incoming_transaction_id
             WHERE t_in2.product_id = t_in.product_id
            ) AS total_sak_keseluruhan
        FROM
            incoming_transactions t_in
        LEFT JOIN
            outgoing_transactions t_out ON t_in.id = t_out.incoming_transaction_id
        WHERE
            t_in.product_id = ?
        GROUP BY
            t_in.id, t_in.transaction_date, t_in.po_number, t_in.batch_number, t_in.supplier, t_in.quantity_kg, t_in.quantity_sacks
        ORDER BY
            t_in.transaction_date ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$product_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($batches);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mengambil data dari database.']);
    exit();
}
