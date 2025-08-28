<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
header('Content-Type: application/json');
include 'koneksi.php';

$id = $_GET['id'] ?? '';
$po_number = $_GET['po_number'] ?? '';
$document_number = $_GET['document_number'] ?? '';
$anchor_id = $_GET['anchor_id'] ?? '';

// Helper untuk normalisasi dokumen (hapus NBSP, tab, CR/LF, dan trim)
$normalizeExpr = "TRIM(REPLACE(REPLACE(REPLACE(REPLACE(%s, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), ''))";

try {
    if (!empty($anchor_id) && !empty($document_number)) {
        // Mode doc + anchor: pakai anchor baris untuk menentukan created_at dan header
        $stmtAnchor = $pdo->prepare("SELECT id, transaction_date, po_number, supplier, produsen, license_plate, status, document_number, created_at FROM incoming_transactions WHERE id = ? LIMIT 1");
        $stmtAnchor->execute([$anchor_id]);
        $anchor = $stmtAnchor->fetch(PDO::FETCH_ASSOC);
        if (!$anchor) {
            echo json_encode(['error' => 'Anchor tidak ditemukan']);
            exit();
        }

        $main = [
            'id' => $anchor['id'],
            'po_number' => $anchor['po_number'],
            'supplier' => $anchor['supplier'],
            'produsen' => $anchor['produsen'],
            'license_plate' => $anchor['license_plate'],
            'status' => $anchor['status'],
            'transaction_date' => $anchor['transaction_date'],
            'document_number' => $anchor['document_number'],
            'created_at' => $anchor['created_at'] ?? null
        ];

        // Ambil semua item kelompok dengan created_at yang sama
        $sqlItems = sprintf(
            "SELECT t.*, p.product_name, p.sku FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE %s = %s AND UNIX_TIMESTAMP(t.created_at) = UNIX_TIMESTAMP(?) ORDER BY t.id ASC",
            sprintf($normalizeExpr, 't.document_number'),
            sprintf($normalizeExpr, '?')
        );
        $stmtItems = $pdo->prepare($sqlItems);
        $stmtItems->execute([trim($main['document_number']), $main['created_at']]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            // Fallback tanggal transaksi
            $sqlItems2 = sprintf(
                "SELECT t.*, p.product_name, p.sku FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE %s = %s AND t.transaction_date = ? ORDER BY t.id ASC",
                sprintf($normalizeExpr, 't.document_number'),
                sprintf($normalizeExpr, '?')
            );
            $stmtItems2 = $pdo->prepare($sqlItems2);
            $stmtItems2->execute([trim($main['document_number']), $main['transaction_date']]);
            $items = $stmtItems2->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($items)) {
            // Fallback doc saja
            $sqlItems3 = sprintf(
                "SELECT t.*, p.product_name, p.sku FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE %s = %s ORDER BY t.id ASC",
                sprintf($normalizeExpr, 't.document_number'),
                sprintf($normalizeExpr, '?')
            );
            $stmtItems3 = $pdo->prepare($sqlItems3);
            $stmtItems3->execute([trim($main['document_number'])]);
            $items = $stmtItems3->fetchAll(PDO::FETCH_ASSOC);
        }

        echo json_encode([
            'transaction_info' => $main,
            'items' => array_map(function ($row) {
                return [
                    'id' => $row['id'], 'product_id' => $row['product_id'], 'product_name' => $row['product_name'], 'sku' => $row['sku'],
                    'batch_number' => $row['batch_number'], 'quantity_kg' => (float)($row['quantity_kg'] ?? 0), 'quantity_sacks' => (float)($row['quantity_sacks'] ?? 0),
                    'lot_number' => isset($row['lot_number']) ? (float)$row['lot_number'] : 0, 'grossweight_kg' => isset($row['grossweight_kg']) ? (float)$row['grossweight_kg'] : 0,
                ];
            }, $items)
        ]);
        exit();
    } elseif (!empty($id)) {
        // Ambil baris anchor
        $stmtAnchor = $pdo->prepare("SELECT id, transaction_date, po_number, supplier, produsen, license_plate, status, document_number, created_at FROM incoming_transactions WHERE id = ? LIMIT 1");
        $stmtAnchor->execute([$id]);
        $anchor = $stmtAnchor->fetch(PDO::FETCH_ASSOC);

        if (!$anchor) {
            echo json_encode(['error' => 'Data tidak ditemukan']);
            exit();
        }

        // Header utama
        $main = [
            'id' => $anchor['id'],
            'po_number' => $anchor['po_number'],
            'supplier' => $anchor['supplier'],
            'produsen' => $anchor['produsen'],
            'license_plate' => $anchor['license_plate'],
            'status' => $anchor['status'],
            'transaction_date' => $anchor['transaction_date'],
            'document_number' => $anchor['document_number'],
            'created_at' => $anchor['created_at'] ?? null
        ];

        // Ambil semua item satu kelompok dengan anchor: doc (normalized) + created_at sama
        $sqlItems = sprintf(
            "SELECT t.*, p.product_name, p.sku FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE %s = %s AND UNIX_TIMESTAMP(t.created_at) = UNIX_TIMESTAMP(?) ORDER BY t.id ASC",
            sprintf($normalizeExpr, 't.document_number'),
            sprintf($normalizeExpr, '?')
        );
        $stmtItems = $pdo->prepare($sqlItems);
        $stmtItems->execute([trim($main['document_number']), $main['created_at']]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        // Fallback: doc + transaction_date
        if (empty($items)) {
            $sqlItems2 = sprintf(
                "SELECT t.*, p.product_name, p.sku FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE %s = %s AND t.transaction_date = ? ORDER BY t.id ASC",
                sprintf($normalizeExpr, 't.document_number'),
                sprintf($normalizeExpr, '?')
            );
            $stmtItems2 = $pdo->prepare($sqlItems2);
            $stmtItems2->execute([trim($main['document_number']), $main['transaction_date']]);
            $items = $stmtItems2->fetchAll(PDO::FETCH_ASSOC);
        }

        // Fallback terakhir: doc saja
        if (empty($items)) {
            $sqlItems3 = sprintf(
                "SELECT t.*, p.product_name, p.sku FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE %s = %s ORDER BY t.id ASC",
                sprintf($normalizeExpr, 't.document_number'),
                sprintf($normalizeExpr, '?')
            );
            $stmtItems3 = $pdo->prepare($sqlItems3);
            $stmtItems3->execute([trim($main['document_number'])]);
            $items = $stmtItems3->fetchAll(PDO::FETCH_ASSOC);
        }

        if (empty($items)) {
            // Minimal kembalikan anchor saja agar UI tidak kosong
            $sqlOne = "SELECT t.*, p.product_name, p.sku FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE t.id = ? LIMIT 1";
            $stmtOne = $pdo->prepare($sqlOne);
            $stmtOne->execute([$id]);
            $items = $stmtOne->fetchAll(PDO::FETCH_ASSOC);
        }

        $response = [
            'transaction_info' => $main,
            'items' => array_map(function ($row) {
                return [
                    'id' => $row['id'],
                    'product_id' => $row['product_id'],
                    'product_name' => $row['product_name'],
                    'sku' => $row['sku'],
                    'batch_number' => $row['batch_number'],
                    'quantity_kg' => (float)($row['quantity_kg'] ?? 0),
                    'quantity_sacks' => (float)($row['quantity_sacks'] ?? 0),
                    'lot_number' => isset($row['lot_number']) ? (float)$row['lot_number'] : 0,
                    'grossweight_kg' => isset($row['grossweight_kg']) ? (float)$row['grossweight_kg'] : 0,
                ];
            }, $items)
        ];

        echo json_encode($response);
        exit();
    } elseif (!empty($po_number) || !empty($document_number)) {
        // Temukan satu baris header berdasarkan PO atau Document untuk menentukan kelompok
        $base = "SELECT id, transaction_date, po_number, supplier, produsen, license_plate, status, document_number, created_at FROM incoming_transactions WHERE 1=1";
        $params = [];
        if (!empty($po_number)) { $base .= " AND po_number = ?"; $params[] = $po_number; }
        if (!empty($document_number)) { $base .= " AND document_number = ?"; $params[] = $document_number; }
        $base .= " ORDER BY created_at ASC LIMIT 1";

        $stmtHeader = $pdo->prepare($base);
        $stmtHeader->execute($params);
        $header = $stmtHeader->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            echo json_encode(['error' => 'Data tidak ditemukan']);
            exit();
        }

        // Reuse path by id (anchor)
        $_GET['id'] = $header['id'];
        // Simple recursion-like reuse: build response again as if id provided
        // To avoid recursion, duplicate minimal logic
        $main = [
            'id' => $header['id'],
            'po_number' => $header['po_number'],
            'supplier' => $header['supplier'],
            'produsen' => $header['produsen'],
            'license_plate' => $header['license_plate'],
            'status' => $header['status'],
            'transaction_date' => $header['transaction_date'],
            'document_number' => $header['document_number'],
            'created_at' => $header['created_at'] ?? null
        ];

        $sqlItems = sprintf(
            "SELECT t.*, p.product_name, p.sku FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE %s = %s AND UNIX_TIMESTAMP(t.created_at) = UNIX_TIMESTAMP(?) ORDER BY t.id ASC",
            sprintf($normalizeExpr, 't.document_number'),
            sprintf($normalizeExpr, '?')
        );
        $stmtItems = $pdo->prepare($sqlItems);
        $stmtItems->execute([trim($main['document_number']), $main['created_at']]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        if (empty($items)) {
            $sqlItems2 = sprintf(
                "SELECT t.*, p.product_name, p.sku FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE %s = %s AND t.transaction_date = ? ORDER BY t.id ASC",
                sprintf($normalizeExpr, 't.document_number'),
                sprintf($normalizeExpr, '?')
            );
            $stmtItems2 = $pdo->prepare($sqlItems2);
            $stmtItems2->execute([trim($main['document_number']), $main['transaction_date']]);
            $items = $stmtItems2->fetchAll(PDO::FETCH_ASSOC);
        }
        if (empty($items)) {
            $sqlItems3 = sprintf(
                "SELECT t.*, p.product_name, p.sku FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE %s = %s ORDER BY t.id ASC",
                sprintf($normalizeExpr, 't.document_number'),
                sprintf($normalizeExpr, '?')
            );
            $stmtItems3 = $pdo->prepare($sqlItems3);
            $stmtItems3->execute([trim($main['document_number'])]);
            $items = $stmtItems3->fetchAll(PDO::FETCH_ASSOC);
        }

        $response = [
            'transaction_info' => $main,
            'items' => array_map(function ($row) {
                return [
                    'id' => $row['id'],
                    'product_id' => $row['product_id'],
                    'product_name' => $row['product_name'],
                    'sku' => $row['sku'],
                    'batch_number' => $row['batch_number'],
                    'quantity_kg' => (float)($row['quantity_kg'] ?? 0),
                    'quantity_sacks' => (float)($row['quantity_sacks'] ?? 0),
                    'lot_number' => isset($row['lot_number']) ? (float)$row['lot_number'] : 0,
                    'grossweight_kg' => isset($row['grossweight_kg']) ? (float)$row['grossweight_kg'] : 0,
                ];
            }, $items)
        ];

        echo json_encode($response);
        exit();
    } else {
        echo json_encode(['error' => 'ID, PO number atau document number harus disediakan']);
        exit();
    }
} catch (PDOException $e) {
    error_log("Error in api_get_incoming_details.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Gagal mengambil data dari database.', 'debug' => $e->getMessage()]);
    exit();
}
