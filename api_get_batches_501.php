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
    $sql = "
        SELECT
            t_in.id,
            t_in.transaction_date,
            t_in.batch_number,
            t_in.lot_number AS original_lot_number,
            /* Total keluar 501 yang terhubung langsung via incoming_transaction_id */
            COALESCE((
                SELECT SUM(o.lot_number)
                FROM outgoing_transactions o
                WHERE o.lot_number > 0 AND o.incoming_transaction_id = t_in.id
            ), 0) AS total_keluar_501_by_in_id,
            /* Fallback: total keluar 501 yang tidak memiliki incoming_transaction_id, dihitung via batch_number + product_id */
            COALESCE((
                SELECT SUM(o2.lot_number)
                FROM outgoing_transactions o2
                WHERE o2.lot_number > 0
                  AND (o2.incoming_transaction_id IS NULL OR o2.incoming_transaction_id = 0)
                  AND o2.batch_number = t_in.batch_number
                  AND o2.product_id = t_in.product_id
            ), 0) AS total_keluar_501_by_batch,
            /* Sisa 501 = lot incoming - (total by incoming_id + total by batch fallback) */
            (
                t_in.lot_number - (
                    COALESCE((
                        SELECT SUM(o.lot_number)
                        FROM outgoing_transactions o
                        WHERE o.lot_number > 0 AND o.incoming_transaction_id = t_in.id
                    ), 0) +
                    COALESCE((
                        SELECT SUM(o2.lot_number)
                        FROM outgoing_transactions o2
                        WHERE o2.lot_number > 0
                          AND (o2.incoming_transaction_id IS NULL OR o2.incoming_transaction_id = 0)
                          AND o2.batch_number = t_in.batch_number
                          AND o2.product_id = t_in.product_id
                    ), 0)
                )
            ) AS sisa_lot_number,
            (
                t_in.lot_number - (
                    COALESCE((
                        SELECT SUM(o.lot_number)
                        FROM outgoing_transactions o
                        WHERE o.lot_number > 0 AND o.incoming_transaction_id = t_in.id
                    ), 0) +
                    COALESCE((
                        SELECT SUM(o2.lot_number)
                        FROM outgoing_transactions o2
                        WHERE o2.lot_number > 0
                          AND (o2.incoming_transaction_id IS NULL OR o2.incoming_transaction_id = 0)
                          AND o2.batch_number = t_in.batch_number
                          AND o2.product_id = t_in.product_id
                    ), 0)
                )
            ) AS remaining_501
        FROM
            incoming_transactions t_in
        WHERE
            t_in.product_id = ?
            AND t_in.lot_number > 0
        HAVING
            sisa_lot_number > 0
        ORDER BY
            t_in.transaction_date ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$product_id]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formatted_batches = [];
    foreach ($batches as $batch) {
        $total_keluar = (float)($batch['total_keluar_501_by_in_id'] ?? 0) + (float)($batch['total_keluar_501_by_batch'] ?? 0);
        $formatted_batches[] = [
            'id' => $batch['id'],
            'transaction_date' => $batch['transaction_date'],
            'batch_number' => $batch['batch_number'],
            // Keep as string to preserve original decimal scale
            'original_lot_number' => $batch['original_lot_number'],
            'total_keluar_501' => (string)$total_keluar,
            'sisa_lot_number' => $batch['sisa_lot_number'],
            'remaining_501' => $batch['remaining_501']
        ];
    }

    echo json_encode($formatted_batches);
} catch (PDOException $e) {
    error_log("Error in api_get_batches_501.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mengambil data dari database.', 'debug' => $e->getMessage()]);
    exit();
}
?>
