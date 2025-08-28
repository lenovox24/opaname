<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
header('Content-Type: application/json');
include 'koneksi.php';

$id = isset($_GET['id']) ? $_GET['id'] : '';
$doc_number = isset($_GET['doc_number']) ? trim($_GET['doc_number']) : '';
$anchor_id = isset($_GET['anchor_id']) ? $_GET['anchor_id'] : '';

// Prioritas: gunakan ID jika ada, fallback ke document_number untuk kompatibilitas
if (!empty($id)) {
    // Edit berdasarkan ID spesifik
    try {
        $stmt_single = $pdo->prepare("
            SELECT 
                t.id, t.transaction_date, t.description, t.status, t.document_number, t.created_at,
                t.product_id, p.product_name, p.sku, t.incoming_transaction_id as incoming_id, 
                i.batch_number, t.quantity_kg as qty_kg, t.quantity_sacks as qty_sak, t.lot_number as lot_number
            FROM outgoing_transactions t
            JOIN products p ON t.product_id = p.id
            LEFT JOIN incoming_transactions i ON t.incoming_transaction_id = i.id
            WHERE t.id = ?
        ");
        $stmt_single->execute([$id]);
        $transaction = $stmt_single->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            echo json_encode(['error' => 'Transaksi dengan ID ini tidak ditemukan.']);
            exit();
        }

        // Format data untuk edit item spesifik
        $response = [
            'main' => [
                'transaction_date' => $transaction['transaction_date'],
                'description' => $transaction['description'],
                'status' => $transaction['status'],
                'document_number' => $transaction['document_number'],
                'created_at' => $transaction['created_at'] ?? null
            ],
            'items' => [
                [
                    'id' => $transaction['id'],
                    'product_id' => $transaction['product_id'],
                    'product_name' => $transaction['product_name'],
                    'sku' => $transaction['sku'],
                    'incoming_id' => $transaction['incoming_id'],
                    'batch_number' => $transaction['batch_number'],
                    'qty_kg' => $transaction['qty_kg'],
                    'qty_sak' => $transaction['qty_sak']
                ]
            ]
        ];

        echo json_encode($response);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['error' => 'ID tidak valid: ' . $e->getMessage()]);
        exit();
    }
} else if (!empty($doc_number)) {
    // Edit berdasarkan document number - ambil SEMUA items dengan nomor dokumen yang sama
    try {
        // Jika anchor_id disediakan, gunakan baris anchor untuk menentukan header utama.
        // Ini menghindari kegagalan pencarian ketika nomor dokumen berisi karakter khusus
        // atau ada perbedaan whitespace/normalisasi.
        if (!empty($anchor_id)) {
            $stmt_anchor_row = $pdo->prepare("SELECT transaction_date, description, status, document_number, created_at FROM outgoing_transactions WHERE id = ? LIMIT 1");
            $stmt_anchor_row->execute([$anchor_id]);
            $anchor_row = $stmt_anchor_row->fetch(PDO::FETCH_ASSOC);

            if ($anchor_row) {
                // Pakai data dari baris anchor sebagai header utama
                $main_data = [
                    'transaction_date' => $anchor_row['transaction_date'],
                    'description' => $anchor_row['description'],
                    'status' => $anchor_row['status'],
                    'document_number' => $anchor_row['document_number'],
                    'created_at' => $anchor_row['created_at']
                ];

                // Ambil semua item yang tept satu kelompok dengan anchor
                $baseSql = "
                    SELECT 
                        t.id, t.product_id, p.product_name, p.sku, t.incoming_transaction_id as incoming_id, 
                        i.batch_number, t.quantity_kg as qty_kg, t.quantity_sacks as qty_sak, t.lot_number as lot_number
                    FROM outgoing_transactions t
                    JOIN products p ON t.product_id = p.id
                    LEFT JOIN incoming_transactions i ON t.incoming_transaction_id = i.id
                    WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(t.document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) = 
                          TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), ''))
                      AND UNIX_TIMESTAMP(t.created_at) = UNIX_TIMESTAMP(?)
                ";

                $params = [trim($main_data['document_number']), $main_data['created_at']];

                // Tidak perlu filter description saat memakai anchor (created_at sudah mengikat kelompok)

                $baseSql .= " ORDER BY t.id ASC";

                $stmt_items = $pdo->prepare($baseSql);
                $stmt_items->execute($params);
                $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

                // Fallback: jika tidak ada item (data lama mungkin tidak seragam created_at),
                // longgarkan filter dengan mengabaikan created_at agar tetap memuat kelompok dokumen di tanggal tsb.
                if (empty($items)) {
                    $baseSqlFallback = "
                        SELECT 
                            t.id, t.product_id, p.product_name, p.sku, t.incoming_transaction_id as incoming_id, 
                            i.batch_number, t.quantity_kg as qty_kg, t.quantity_sacks as qty_sak, t.lot_number as lot_number
                        FROM outgoing_transactions t
                        JOIN products p ON t.product_id = p.id
                        LEFT JOIN incoming_transactions i ON t.incoming_transaction_id = i.id
                        WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(t.document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) = 
                              TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), ''))
                          AND t.transaction_date = ?
                    ";
                    $paramsFallback = [trim($main_data['document_number']), $main_data['transaction_date']];
                    $baseSqlFallback .= " ORDER BY t.id ASC";
                    $stmt_fb = $pdo->prepare($baseSqlFallback);
                    $stmt_fb->execute($paramsFallback);
                    $items = $stmt_fb->fetchAll(PDO::FETCH_ASSOC);

                    // Fallback kedua: pakai dokumen saja (tanpa tanggal) jika masih kosong
                    if (empty($items)) {
                        $baseSqlFallback2 = "
                            SELECT 
                                t.id, t.product_id, p.product_name, p.sku, t.incoming_transaction_id as incoming_id, 
                                i.batch_number, t.quantity_kg as qty_kg, t.quantity_sacks as qty_sak, t.lot_number as lot_number
                            FROM outgoing_transactions t
                            JOIN products p ON t.product_id = p.id
                            LEFT JOIN incoming_transactions i ON t.incoming_transaction_id = i.id
                            WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(t.document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) = 
                                  TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), ''))
                            ORDER BY t.id ASC
                        ";
                        $stmt_fb2 = $pdo->prepare($baseSqlFallback2);
                        $stmt_fb2->execute([trim($main_data['document_number'])]);
                        $items = $stmt_fb2->fetchAll(PDO::FETCH_ASSOC);

                        // Fallback ketiga: minimal ambil item anchor agar UI tidak kosong sama sekali
                        if (empty($items)) {
                            $stmt_anchor_item = $pdo->prepare("SELECT t.id, t.product_id, p.product_name, p.sku, t.incoming_transaction_id as incoming_id, i.batch_number, t.quantity_kg as qty_kg, t.quantity_sacks as qty_sak FROM outgoing_transactions t JOIN products p ON t.product_id = p.id LEFT JOIN incoming_transactions i ON t.incoming_transaction_id = i.id WHERE t.id = ? LIMIT 1");
                            $stmt_anchor_item->execute([$anchor_id]);
                            $anchor_item = $stmt_anchor_item->fetchAll(PDO::FETCH_ASSOC);
                            $items = $anchor_item ?: [];
                        }
                    }
                }
            } else {
                // Jika anchor_id tidak valid, fallback ke pencarian berbasis doc_number seperti biasa
                $stmt_main = $pdo->prepare("SELECT transaction_date, description, status, document_number, created_at FROM outgoing_transactions WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) LIMIT 1");
                $stmt_main->execute([trim($doc_number)]);
                $main_data = $stmt_main->fetch(PDO::FETCH_ASSOC);
            }
        } else {
            // Tanpa anchor, gunakan pencarian default berbasis nomor dokumen
            $stmt_main = $pdo->prepare("SELECT transaction_date, description, status, document_number, created_at FROM outgoing_transactions WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) LIMIT 1");
            $stmt_main->execute([trim($doc_number)]);
            $main_data = $stmt_main->fetch(PDO::FETCH_ASSOC);
        }

        if (empty($items)) {
            // Jika items belum diisi (kasus tanpa anchor atau anchor invalid), ambil items berdasar header yang ditemukan
            if (!$main_data) {
                echo json_encode(['error' => 'Transaksi dengan nomor dokumen ini tidak ditemukan.']);
                exit();
            }

            $baseSql = "
                SELECT 
                    t.id, t.product_id, p.product_name, p.sku, t.incoming_transaction_id as incoming_id, 
                    i.batch_number, t.quantity_kg as qty_kg, t.quantity_sacks as qty_sak
                FROM outgoing_transactions t
                JOIN products p ON t.product_id = p.id
                LEFT JOIN incoming_transactions i ON t.incoming_transaction_id = i.id
                WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(t.document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) = 
                      TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), ''))
                  AND t.transaction_date = ?
                ORDER BY t.id ASC
            ";

            $params = [trim($main_data['document_number']), $main_data['transaction_date']];

            $stmt_items = $pdo->prepare($baseSql);
            $stmt_items->execute($params);
            $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

            // Fallback kedua: jika tetap kosong, longgarkan ke TRIM(document_number) saja
            if (empty($items)) {
            $sqlDocOnly = "
                    SELECT 
                        t.id, t.product_id, p.product_name, p.sku, t.incoming_transaction_id as incoming_id, 
                        i.batch_number, t.quantity_kg as qty_kg, t.quantity_sacks as qty_sak
                    FROM outgoing_transactions t
                    JOIN products p ON t.product_id = p.id
                    LEFT JOIN incoming_transactions i ON t.incoming_transaction_id = i.id
                    WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(t.document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) = 
                          TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), ''))
                    ORDER BY t.id ASC
                ";
                $stmt_doc_only = $pdo->prepare($sqlDocOnly);
                $stmt_doc_only->execute([trim($main_data['document_number'])]);
                $items = $stmt_doc_only->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Error database: ' . $e->getMessage()]);
        exit();
    }
} else {
    echo json_encode(['error' => 'ID atau nomor dokumen harus disediakan.']);
    exit();
}

try {

    $response = [
        'main' => $main_data,
        'items' => $items
    ];

    echo json_encode($response);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mengambil data: ' . $e->getMessage()]);
    exit();
}
