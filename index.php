<?php
// ===================================================================================
// SESSION CHECK
// ===================================================================================
require_once __DIR__ . '/security_bootstrap.php';

// ===================================================================================
// HELPER FUNCTIONS
// ===================================================================================
/**
 * Helper function untuk mempertahankan parameter filter saat redirect
 */
function preserveFilterParams($page, $status, $additional_params = []) {
    $filter_params = [];
    
    // Ambil parameter dari POST jika ada, jika tidak dari GET
    $source = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
    
    // Parameter filter yang perlu dipertahankan
    $filter_keys = [
        'start_date', 'end_date', 'status_filter', 's', 'po', 'doc', 'batch', 'ket', 
        'limit', 'page_num', 'filter_date', 'filter_qty_kg', 'date', 'product_id_filter', 
        'incoming_id', 'week'
    ];
    
    foreach ($filter_keys as $key) {
        if (!empty($source[$key])) {
            $filter_params[$key] = $source[$key];
        }
    }
    
    // Tambahkan parameter wajib
    $filter_params['page'] = $page;
    $filter_params['status'] = $status;
    
    // Tambahkan parameter tambahan jika ada
    if (!empty($additional_params)) {
        $filter_params = array_merge($filter_params, $additional_params);
    }
    
    return 'index.php?' . http_build_query($filter_params);
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ===================================================================================
// KONTROLER UTAMA
// ===================================================================================
include 'koneksi.php';

// --- MENANGANI AKSI DARI URL (METHOD GET) ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $id = $_GET['id'] ?? 0;

    if ($action === 'delete_produk' && $id > 0) {
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        
        $redirect_url = preserveFilterParams('daftar_produk', 'dihapus');
        header("Location: " . $redirect_url);
        exit();
    }
    if ($action === 'delete_incoming' && $id > 0) {
        $stmt = $pdo->prepare("DELETE FROM incoming_transactions WHERE id = ?");
        $stmt->execute([$id]);
        
        $redirect_url = preserveFilterParams('barang_masuk', 'dihapus');
        header("Location: " . $redirect_url);
        exit();
    }
    if ($action === 'delete_outgoing' && $id > 0) {
        try {
            $pdo->beginTransaction();

            // Ambil baris anchor untuk menentukan kelompok
            $stmtAnchor = $pdo->prepare("SELECT document_number, created_at, transaction_date FROM outgoing_transactions WHERE id = ? LIMIT 1");
            $stmtAnchor->execute([$id]);
            $anchor = $stmtAnchor->fetch(PDO::FETCH_ASSOC);

            if ($anchor) {
                $doc = trim($anchor['document_number'] ?? '');
                $createdAt = $anchor['created_at'] ?? null;
                $txDate = $anchor['transaction_date'] ?? null;

                $deletedCount = 0;

                // 1) Hapus semua item dengan doc sama (dinormalisasi) dan created_at identik (kelompok asli)
                if (!empty($createdAt)) {
                    $sql1 = "DELETE FROM outgoing_transactions WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) AND UNIX_TIMESTAMP(created_at) = UNIX_TIMESTAMP(?)";
                    $stmt1 = $pdo->prepare($sql1);
                    $stmt1->execute([$doc, $createdAt]);
                    $deletedCount = $stmt1->rowCount();
                }

                // 2) Fallback: jika tidak ada yang terhapus (created_at null/berbeda), pakai doc + tanggal transaksi
                if ($deletedCount === 0 && !empty($txDate)) {
                    $sql2 = "DELETE FROM outgoing_transactions WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) AND transaction_date = ?";
                    $stmt2 = $pdo->prepare($sql2);
                    $stmt2->execute([$doc, $txDate]);
                    $deletedCount = $stmt2->rowCount();
                }

                // 3) Fallback terakhir: hapus berdasarkan nomor dokumen saja (berisiko namun sesuai ekspektasi user bahwa satu dokumen = satu kelompok)
                if ($deletedCount === 0) {
                    $sql3 = "DELETE FROM outgoing_transactions WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), ''))";
                    $stmt3 = $pdo->prepare($sql3);
                    $stmt3->execute([$doc]);
                    $deletedCount = $stmt3->rowCount();
                }

                // Jika masih nol (sangat kecil kemungkinan), hapus item anchor saja agar aksi tetap berjalan
                if ($deletedCount === 0) {
                    $stmtSingle = $pdo->prepare("DELETE FROM outgoing_transactions WHERE id = ?");
                    $stmtSingle->execute([$id]);
                    $deletedCount = $stmtSingle->rowCount();
                }

                $pdo->commit();
            } else {
                // Tidak ada baris anchor, hapus id saja
                $stmt = $pdo->prepare("DELETE FROM outgoing_transactions WHERE id = ?");
                $stmt->execute([$id]);
                $pdo->commit();
            }

            $redirect_url = preserveFilterParams('barang_keluar', 'dihapus');
            header("Location: " . $redirect_url);
            exit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            die('Error Hapus Barang Keluar: ' . $e->getMessage());
        }
    }
    if ($action === 'delete_unloading' && $id > 0) {
        $stmt = $pdo->prepare("DELETE FROM unloading_records WHERE id = ?");
        $stmt->execute([$id]);
        
        $redirect_url = preserveFilterParams('unloading', 'dihapus');
        header("Location: " . $redirect_url);
        exit();
    }
}

// --- MENANGANI AKSI BACKUP & CLEANUP ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['page']) && $_POST['page'] === 'backup_cleanup') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete_selected') {
        // Delete selected batches
        $selected_batches = $_POST['selected_batches'] ?? [];
        if (empty($selected_batches)) {
            header("Location: index.php?page=backup_cleanup&status=no_selection");
            exit();
        }
        
        try {
            $pdo->beginTransaction();
            
            // Delete outgoing transactions first (foreign key constraint)
            $placeholders = str_repeat('?,', count($selected_batches) - 1) . '?';
            $stmt_outgoing = $pdo->prepare("DELETE FROM outgoing_transactions WHERE incoming_transaction_id IN ($placeholders)");
            $stmt_outgoing->execute($selected_batches);
            
            // Delete incoming transactions
            $stmt_incoming = $pdo->prepare("DELETE FROM incoming_transactions WHERE id IN ($placeholders)");
            $stmt_incoming->execute($selected_batches);
            
            $pdo->commit();
            header("Location: index.php?page=backup_cleanup&status=delete_success");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            header("Location: index.php?page=backup_cleanup&status=error_delete");
            exit();
        }
    } elseif ($action === 'delete_single') {
        // Delete single batch
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        if ($batch_id <= 0) {
            header("Location: index.php?page=backup_cleanup&status=error_delete");
            exit();
        }
        
        try {
            $pdo->beginTransaction();
            
            // Delete outgoing transactions first
            $stmt_outgoing = $pdo->prepare("DELETE FROM outgoing_transactions WHERE incoming_transaction_id = ?");
            $stmt_outgoing->execute([$batch_id]);
            
            // Delete incoming transaction
            $stmt_incoming = $pdo->prepare("DELETE FROM incoming_transactions WHERE id = ?");
            $stmt_incoming->execute([$batch_id]);
            
            $pdo->commit();
            header("Location: index.php?page=backup_cleanup&status=delete_success");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            header("Location: index.php?page=backup_cleanup&status=error_delete");
            exit();
        }
    } elseif ($action === 'restore_backup') {
        // Restore backup from uploaded JSON file
        if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            header("Location: index.php?page=backup_cleanup&status=error_restore&msg=" . urlencode("File upload gagal"));
            exit();
        }
        
        $preview_only = isset($_POST['preview_only']);
        $overwrite_existing = isset($_POST['overwrite_existing']);
        
        try {
            // Read and parse JSON file
            $json_content = file_get_contents($_FILES['backup_file']['tmp_name']);
            $backup_data = json_decode($json_content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("File JSON tidak valid: " . json_last_error_msg());
            }
            
            // Validate backup structure
            if (!isset($backup_data['incoming_transactions']) || !isset($backup_data['outgoing_transactions'])) {
                throw new Exception("Struktur backup tidak valid. File harus berisi incoming_transactions dan outgoing_transactions.");
            }
            
            if ($preview_only) {
                // Preview mode - just show summary
                $incoming_count = count($backup_data['incoming_transactions']);
                $outgoing_count = count($backup_data['outgoing_transactions']);
                $summary = $backup_data['summary'] ?? [];
                
                header("Location: index.php?page=backup_cleanup&status=preview_success&incoming=" . $incoming_count . "&outgoing=" . $outgoing_count);
                exit();
            }
            
            // Actual restore
            $pdo->beginTransaction();
            
            $restored_incoming = 0;
            $restored_outgoing = 0;
            $skipped = 0;
            
            // Restore incoming transactions
            foreach ($backup_data['incoming_transactions'] as $incoming) {
                if (!$overwrite_existing) {
                    // Check if ID already exists
                    $check_stmt = $pdo->prepare("SELECT id FROM incoming_transactions WHERE id = ?");
                    $check_stmt->execute([$incoming['id']]);
                    if ($check_stmt->fetch()) {
                        $skipped++;
                        continue;
                    }
                }
                
                $stmt = $pdo->prepare("INSERT INTO incoming_transactions 
                    (id, product_id, po_number, supplier, produsen, license_plate, quantity_kg, quantity_sacks, 
                     grossweight_kg, document_number, batch_number, lot_number, status, transaction_date, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    product_id = VALUES(product_id), po_number = VALUES(po_number), supplier = VALUES(supplier),
                    produsen = VALUES(produsen), license_plate = VALUES(license_plate), quantity_kg = VALUES(quantity_kg),
                    quantity_sacks = VALUES(quantity_sacks), grossweight_kg = VALUES(grossweight_kg),
                    document_number = VALUES(document_number), batch_number = VALUES(batch_number),
                    lot_number = VALUES(lot_number), status = VALUES(status), transaction_date = VALUES(transaction_date)");
                
                $stmt->execute([
                    $incoming['id'], $incoming['product_id'], $incoming['po_number'], $incoming['supplier'],
                    $incoming['produsen'], $incoming['license_plate'], $incoming['quantity_kg'], $incoming['quantity_sacks'],
                    $incoming['grossweight_kg'], $incoming['document_number'], $incoming['batch_number'],
                    $incoming['lot_number'], $incoming['status'], $incoming['transaction_date'], $incoming['created_at']
                ]);
                $restored_incoming++;
            }
            
            // Restore outgoing transactions
            foreach ($backup_data['outgoing_transactions'] as $outgoing) {
                if (!$overwrite_existing) {
                    $check_stmt = $pdo->prepare("SELECT id FROM outgoing_transactions WHERE id = ?");
                    $check_stmt->execute([$outgoing['id']]);
                    if ($check_stmt->fetch()) {
                        $skipped++;
                        continue;
                    }
                }
                
                $stmt = $pdo->prepare("INSERT INTO outgoing_transactions 
                    (id, product_id, incoming_transaction_id, quantity_kg, quantity_sacks, lot_number, 
                     document_number, description, status, transaction_date, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    product_id = VALUES(product_id), incoming_transaction_id = VALUES(incoming_transaction_id),
                    quantity_kg = VALUES(quantity_kg), quantity_sacks = VALUES(quantity_sacks),
                    lot_number = VALUES(lot_number), document_number = VALUES(document_number),
                    description = VALUES(description), status = VALUES(status), transaction_date = VALUES(transaction_date)");
                
                $stmt->execute([
                    $outgoing['id'], $outgoing['product_id'], $outgoing['incoming_transaction_id'],
                    $outgoing['quantity_kg'], $outgoing['quantity_sacks'], $outgoing['lot_number'],
                    $outgoing['document_number'], $outgoing['description'], $outgoing['status'],
                    $outgoing['transaction_date'], $outgoing['created_at']
                ]);
                $restored_outgoing++;
            }
            
            $pdo->commit();
            header("Location: index.php?page=backup_cleanup&status=restore_success&incoming=" . $restored_incoming . "&outgoing=" . $restored_outgoing . "&skipped=" . $skipped);
            exit();
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header("Location: index.php?page=backup_cleanup&status=error_restore&msg=" . urlencode($e->getMessage()));
            exit();
        }
    }
}

// --- MENANGANI PENGIRIMAN FORM (METHOD POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type'])) {
    // CSRF validation for all POST forms
    $posted_token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $posted_token)) {
        http_response_code(419);
        die('Invalid CSRF token');
    }

    switch ($_POST['form_type']) {
        case 'produk':
            try {
                $is_edit = !empty($_POST['product_id']);
                $params = [':sku' => $_POST['sku'], ':product_name' => $_POST['product_name'], ':standard_qty' => $_POST['standard_qty'] ?: null];
                if ($is_edit) {
                    $sql = "UPDATE products SET sku=:sku, product_name=:product_name, standard_qty=:standard_qty WHERE id=:id";
                    $params[':id'] = $_POST['product_id'];
                } else {
                    $sql = "INSERT INTO products (sku, product_name, standard_qty) VALUES (:sku, :product_name, :standard_qty)";
                }
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                $redirect_url = preserveFilterParams('daftar_produk', ($is_edit ? 'sukses_edit' : 'sukses_tambah'));
                header("Location: " . $redirect_url);
                exit();
            } catch (PDOException $e) {
                die('Error Produk: ' . $e->getMessage());
            }

        case 'barang_masuk':
            try {
                $is_edit = !empty($_POST['transaction_id']);
                $items = json_decode($_POST['items_json'], true);

                if (!is_array($items) || empty($items)) {
                    $redirect_url = preserveFilterParams('barang_masuk', 'gagal_no_item');
                    header("Location: " . $redirect_url);
                    exit();
                }

                $pdo->beginTransaction();

                $po_number = $_POST['po_number'];
                $supplier = $_POST['supplier'];
                $produsen = $_POST['produsen'];
                $license_plate = $_POST['license_plate'];
                $status = $_POST['status'];
                $transaction_date = $_POST['transaction_date'];

                // Sinkronisasi penghapusan item saat edit: hapus yang tidak lagi ada di form
                if ($is_edit) {
                    $anchor_id = (int)($_POST['transaction_id'] ?? 0);
                    if ($anchor_id > 0) {
                        // Ambil baris anchor
                        $stmtAnchor = $pdo->prepare("SELECT document_number, created_at, transaction_date FROM incoming_transactions WHERE id = ? LIMIT 1");
                        $stmtAnchor->execute([$anchor_id]);
                        $anchor = $stmtAnchor->fetch(PDO::FETCH_ASSOC);

                        if ($anchor) {
                            $anchorDoc = trim($anchor['document_number'] ?? '');
                            $anchorCreated = $anchor['created_at'] ?? null;
                            $anchorDate = $anchor['transaction_date'] ?? null;

                            // Ambil semua ID item dalam kelompok anchor
                            $existingIds = [];
                            if (!empty($anchorCreated)) {
                                $sqlExisting = "
                                    SELECT id FROM incoming_transactions
                                    WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) =
                                          TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), ''))
                                      AND UNIX_TIMESTAMP(created_at) = UNIX_TIMESTAMP(?)
                                ";
                                $stmtExist = $pdo->prepare($sqlExisting);
                                $stmtExist->execute([$anchorDoc, $anchorCreated]);
                                $existingIds = $stmtExist->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
                            }

                            // Fallback: doc + tanggal
                            if (empty($existingIds) && !empty($anchorDate)) {
                                $sqlExisting2 = "
                                    SELECT id FROM incoming_transactions
                                    WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) =
                                          TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), ''))
                                      AND transaction_date = ?
                                ";
                                $stmtExist2 = $pdo->prepare($sqlExisting2);
                                $stmtExist2->execute([$anchorDoc, $anchorDate]);
                                $existingIds = $stmtExist2->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
                            }

                            // Fallback terakhir: doc saja
                            if (empty($existingIds)) {
                                $sqlExisting3 = "
                                    SELECT id FROM incoming_transactions
                                    WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) =
                                          TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), ''))
                                ";
                                $stmtExist3 = $pdo->prepare($sqlExisting3);
                                $stmtExist3->execute([$anchorDoc]);
                                $existingIds = $stmtExist3->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
                            }

                            // ID yang dipertahankan (masih ada di form)
                            $postedIds = [];
                            foreach ($items as $it) {
                                if (isset($it['id']) && is_numeric($it['id'])) {
                                    $postedIds[] = (int)$it['id'];
                                }
                            }

                            // Hapus item yang tidak ada di form
                            $idsToDelete = array_values(array_diff(array_map('intval', $existingIds), $postedIds));
                            if (!empty($idsToDelete)) {
                                $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
                                $stmtDel = $pdo->prepare("DELETE FROM incoming_transactions WHERE id IN ($placeholders)");
                                $stmtDel->execute($idsToDelete);
                            }

                            // Simpan anchor created_at untuk insert baru agar tetap satu kelompok
                            $incoming_group_created_at = $anchorCreated;
                        }
                    }
                }

                foreach ($items as $item) {
                    if ($is_edit && isset($item['id'])) {
                        // Update item spesifik beserta info transaksi
                        $sql = "UPDATE incoming_transactions SET 
                                product_id = ?, quantity_kg = ?, quantity_sacks = ?, 
                                batch_number = ?, po_number = ?, supplier = ?, 
                                produsen = ?, license_plate = ?, status = ?, 
                                transaction_date = ?, document_number = ?, lot_number = ?, 
                                grossweight_kg = ? 
                                WHERE id = ?";
                        
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            $item['product_id'],
                            $item['quantity_kg'],
                            $item['quantity_sacks'],
                            $item['batch_number'],
                            $po_number,
                            $supplier,
                            $produsen,
                            $license_plate,
                            $status,
                            $transaction_date,
                            $_POST['document_number'],
                            $item['lot_number'] ?? 0,
                            $item['grossweight_kg'] ?? 0,
                            $item['id']
                        ]);
                    } else {
                        // Insert new item; pertahankan created_at anchor agar tergabung dalam kelompok yang sama
                        $sql = isset($incoming_group_created_at) && !empty($incoming_group_created_at)
                            ? "INSERT INTO incoming_transactions 
                                        (product_id, po_number, supplier, produsen, license_plate, 
                                        quantity_kg, quantity_sacks, document_number, batch_number, 
                                        lot_number, grossweight_kg, status, transaction_date, created_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                            : "INSERT INTO incoming_transactions 
                                        (product_id, po_number, supplier, produsen, license_plate, 
                                        quantity_kg, quantity_sacks, document_number, batch_number, 
                                        lot_number, grossweight_kg, status, transaction_date) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        
                        $stmt = $pdo->prepare($sql);
                        $paramsIns = [
                            $item['product_id'],
                            $po_number,
                            $supplier,
                            $produsen,
                            $license_plate,
                            $item['quantity_kg'],
                            $item['quantity_sacks'],
                            $_POST['document_number'],
                            $item['batch_number'],
                            $item['lot_number'],
                            $item['grossweight_kg'] ?? 0,
                            $status,
                            $transaction_date
                        ];
                        if (isset($incoming_group_created_at) && !empty($incoming_group_created_at)) {
                            $paramsIns[] = $incoming_group_created_at;
                        }
                        $stmt->execute($paramsIns);
                    }
                }

                $pdo->commit();
                $redirect_status = $is_edit ? 'sukses_edit' : 'sukses_tambah';
                
                $redirect_url = preserveFilterParams('barang_masuk', $redirect_status);
                header("Location: " . $redirect_url);
                exit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                die('Error Barang Masuk: ' . $e->getMessage());
            }

        case 'barang_keluar':
            try {
                $is_edit = !empty($_POST['original_document_number']);
                $items = json_decode($_POST['items_json'], true);

                if (!is_array($items) || empty($items)) {
                    $redirect_url = preserveFilterParams('barang_keluar', 'gagal_no_item');
                    header("Location: " . $redirect_url);
                    exit();
                }

                $pdo->beginTransaction();

                $document_number = $_POST['document_number'];
                if (empty($document_number)) {
                    $redirect_url = preserveFilterParams('barang_keluar', 'gagal_no_document');
                    header("Location: " . $redirect_url);
                    exit();
                }

                $description = $_POST['description'];
                $transaction_date = $_POST['transaction_date'];
                $status = $_POST['status'];

                // Hapus item yang dikeluarkan dari daftar saat edit (sinkronisasi server dengan UI)
                if ($is_edit) {
                    $original_document_number = $_POST['original_document_number'] ?? '';
                    $group_created_at = $_POST['group_created_at'] ?? '';

                    if (!empty($original_document_number) && !empty($group_created_at)) {
                        // Ambil semua ID item dalam kelompok awal (dokumen dinormalisasi + created_at sama)
                        $sqlExisting = "
                            SELECT id FROM outgoing_transactions 
                            WHERE TRIM(REPLACE(REPLACE(REPLACE(REPLACE(document_number, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), '')) = 
                                  TRIM(REPLACE(REPLACE(REPLACE(REPLACE(?, CHAR(160), ' '), CHAR(9), ' '), CHAR(13), ''), CHAR(10), ''))
                              AND UNIX_TIMESTAMP(created_at) = UNIX_TIMESTAMP(?)
                        ";
                        $stmtExisting = $pdo->prepare($sqlExisting);
                        $stmtExisting->execute([$original_document_number, $group_created_at]);
                        $existingIds = $stmtExisting->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

                        // ID yang dipertahankan (masih ada di form)
                        $postedIds = [];
                        foreach ($items as $it) {
                            if (isset($it['id']) && is_numeric($it['id'])) {
                                $postedIds[] = (int)$it['id'];
                            }
                        }

                        // Tentukan item yang harus dihapus (ada di DB kelompok, tapi tidak ada di form)
                        $idsToDelete = array_values(array_diff(array_map('intval', $existingIds), $postedIds));
                        if (!empty($idsToDelete)) {
                            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
                            $stmtDel = $pdo->prepare("DELETE FROM outgoing_transactions WHERE id IN ($placeholders)");
                            $stmtDel->execute($idsToDelete);
                        }
                    }
                }

                foreach ($items as $item) {
                    if ($is_edit && isset($item['id'])) {
                        // Update item spesifik berdasarkan ID
                        $sql = "UPDATE outgoing_transactions SET 
                                product_id = ?, incoming_transaction_id = ?, quantity_kg = ?, 
                                quantity_sacks = ?, description = ?, document_number = ?, 
                                batch_number = ?, lot_number = ?, status = ?, transaction_date = ?
                                WHERE id = ?";
                        
                        $stmt = $pdo->prepare($sql);
                        $item_desc = $description;
                        $stmt->execute([
                            $item['product_id'],
                            $item['incoming_id'],
                            $item['qty_kg'],
                            $item['qty_sak'],
                            $item_desc,
                            $document_number,
                            $item['batch_number'],
                            $item['lot_number'] ?? 0,
                            $status,
                            $transaction_date,
                            $item['id']
                        ]);
                    } else {
                        // Insert new item. Jika ini tambahan pada dokumen yang sedang di-edit,
                        // pertahankan created_at anchor agar tergabung dalam grup yang sama.
                        $created_at_override = null;
                        if ($is_edit && !empty($_POST['group_created_at'])) {
                            $created_at_override = $_POST['group_created_at'];
                        }
                        
                        if ($created_at_override) {
                            $sql = "INSERT INTO outgoing_transactions 
                                            (product_id, incoming_transaction_id, quantity_kg, quantity_sacks, 
                                            description, document_number, batch_number, lot_number, status, transaction_date, created_at) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        } else {
                            $sql = "INSERT INTO outgoing_transactions 
                                            (product_id, incoming_transaction_id, quantity_kg, quantity_sacks, 
                                            description, document_number, batch_number, lot_number, status, transaction_date) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        }
 
                        $stmt = $pdo->prepare($sql);
                        $item_desc = $description;
                        $paramsIns = [
                            $item['product_id'],
                            $item['incoming_id'],
                            $item['qty_kg'],
                            $item['qty_sak'],
                            $item_desc,
                            $document_number,
                            $item['batch_number'],
                            $item['lot_number'] ?? 0,
                            $status,
                            $transaction_date
                        ];
                        if ($created_at_override) { $paramsIns[] = $created_at_override; }
                        $stmt->execute($paramsIns);
                    }
                }

                $pdo->commit();
                $redirect_status = $is_edit ? 'sukses_edit' : 'sukses_tambah';
                
                $redirect_url = preserveFilterParams('barang_keluar', $redirect_status);
                header("Location: " . $redirect_url);
                exit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                die('Error Barang Keluar: ' . $e->getMessage());
            }

        case 'keluarkan_501':
            try {
                $incoming_id = $_POST['incoming_transaction_id'];
                $product_id = $_POST['product_id'];
                $qty_501_diminta = (float)$_POST['quantity_501'];

                $stmt_sisa = $pdo->prepare("
                        SELECT (t_in.lot_number - COALESCE(SUM(t_out.lot_number), 0)) AS sisa
                        FROM incoming_transactions t_in
                        LEFT JOIN outgoing_transactions t_out ON t_in.id = t_out.incoming_transaction_id
                        WHERE t_in.id = ? GROUP BY t_in.id, t_in.lot_number
                    ");
                $stmt_sisa->execute([$incoming_id]);
                $sisa_lot = (float)$stmt_sisa->fetchColumn();

                $qty_disimpan_501 = 0;
                $status_redirect = '';
                $params_redirect = '';

                if ($sisa_lot <= 0) {
                    $status_redirect = 'stok_habis';
                } elseif ($qty_501_diminta > $sisa_lot) {
                    $qty_disimpan_501 = $sisa_lot;
                    $kekurangan = $qty_501_diminta - $sisa_lot;
                    $status_redirect = 'sukses_parsial_501';
                    $params_redirect = "&kurang={$kekurangan}&dikeluarkan={$qty_disimpan_501}";
                } else {
                    $qty_disimpan_501 = $qty_501_diminta;
                    $status_redirect = 'sukses_501';
                }

                if ($qty_disimpan_501 > 0) {
                    $stmt_batch = $pdo->prepare("SELECT batch_number FROM incoming_transactions WHERE id = ?");
                    $stmt_batch->execute([$incoming_id]);
                    $batch_number = $stmt_batch->fetchColumn();

                    $sql = "INSERT INTO outgoing_transactions 
                                    (product_id, incoming_transaction_id, quantity_kg, quantity_sacks, description, lot_number, batch_number, status, transaction_date) 
                                VALUES (?, ?, 0, 0, ?, ?, ?, 'Closed', ?)";
                    $stmt_insert = $pdo->prepare($sql);
                    $stmt_insert->execute([
                        $product_id,
                        $incoming_id,
                        $_POST['description'],
                        $qty_disimpan_501,
                        $batch_number,
                        $_POST['transaction_date']
                    ]);
                }

                // Add additional parameters if they exist
                $additional_params = [];
                if (!empty($params_redirect)) {
                    parse_str(ltrim($params_redirect, '&'), $additional_params);
                }
                
                $redirect_url = preserveFilterParams('barang_keluar', $status_redirect, $additional_params);
                header("Location: " . $redirect_url);
                exit();
            } catch (PDOException $e) {
                die('Error Pengeluaran 501: ' . $e->getMessage());
            }



        case 'update_unloading_time':
            try {
                $record_id = $_POST['record_id'];
                $field = $_POST['field'];
                $value = $_POST['value'];
                
                // Validasi field yang diizinkan
                $allowed_fields = ['jam_masuk', 'jam_start_qc', 'jam_finish_qc', 'jam_start_bongkar', 'jam_finish_bongkar', 'jam_keluar'];
                if (!in_array($field, $allowed_fields)) {
                    http_response_code(400);
                    echo 'Invalid field';
                    exit();
                }
                
                // Update waktu
                $sql = "UPDATE unloading_records SET $field = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$value ?: null, $record_id]);
                
                // Jika ada update jam start atau finish bongkar, hitung ulang durasi
                if ($field == 'jam_start_bongkar' || $field == 'jam_finish_bongkar') {
                    $sql_duration = "UPDATE unloading_records SET durasi_bongkar = 
                        CASE 
                            WHEN jam_start_bongkar IS NOT NULL AND jam_finish_bongkar IS NOT NULL 
                            THEN TIMESTAMPDIFF(MINUTE, jam_start_bongkar, jam_finish_bongkar)
                            ELSE NULL 
                        END 
                        WHERE id = ?";
                    $stmt_duration = $pdo->prepare($sql_duration);
                    $stmt_duration->execute([$record_id]);
                }
                
                echo 'success';
            } catch (PDOException $e) {
                http_response_code(500);
                echo 'Error: ' . $e->getMessage();
            }
            exit();

        case 'update_unloading_qty_pallet':
            try {
                $record_id = $_POST['record_id'];
                $value = $_POST['value'];
                
                // Validasi nilai qty pallet
                if (!is_numeric($value) || $value < 0) {
                    http_response_code(400);
                    echo 'Invalid qty pallet value';
                    exit();
                }
                
                // Update qty pallet
                $sql = "UPDATE unloading_records SET qty_pallet = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$value, $record_id]);
                
                echo 'success';
            } catch (PDOException $e) {
                http_response_code(500);
                echo 'Error: ' . $e->getMessage();
            }
            exit();

        case 'update_unloading_driver':
            try {
                $record_id = $_POST['record_id'];
                $value = $_POST['value'];

                // Update nama supir (boleh kosong)
                $sql = "UPDATE unloading_records SET nama_supir = ? WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$value ?: null, $record_id]);

                echo 'success';
            } catch (PDOException $e) {
                http_response_code(500);
                echo 'Error: ' . $e->getMessage();
            }
            exit();
    }
}

$page = $_GET['page'] ?? 'beranda';
$active_page = $page;
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manajemen Stok - <?= ucfirst(str_replace('_', ' ', $page)) ?></title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link rel="stylesheet" href="css/style.css">
    
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.5/dist/sweetalert2.min.css">
</head>

<body class="app-body">
    <!-- SINGLE TOP NAVIGATION - NO DUPLICATES -->
    <nav class="navbar navbar-expand-lg top-navbar fixed-top">
        <div class="container-fluid px-3">
            <!-- SINGLE MENU TOGGLE - ALWAYS VISIBLE -->
            <button class="btn btn-link navbar-toggler d-block p-1 me-2" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-controls="sidebar" style="z-index: 1100;">
                <i class="bi bi-list fs-4 text-white"></i>
            </button>

            <!-- BRAND -->
            <div class="navbar-brand d-flex align-items-center me-auto">
                <i class="bi bi-box-seam-fill me-2 text-white fs-5"></i>
                <span class="fw-bold text-white d-none d-md-inline fs-5">Manajemen Stok</span>
            </div>

            <!-- USER INFO & LOGOUT -->
            <div class="d-flex align-items-center">
                <span class="navbar-text me-3 d-none d-sm-inline text-white">
                    Halo, <strong><?= htmlspecialchars($_SESSION['user_nama'] ?? 'Pengguna') ?></strong>!
                </span>
                <a href="logout.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-right me-1"></i>
                    <span class="d-none d-sm-inline">Logout</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- MAIN LAYOUT -->
    <div class="app-container">
        <!-- SIDEBAR -->
        <?php include 'sidebar.php'; ?>

        <!-- MAIN CONTENT WITH CONSISTENT BACKGROUND -->
        <main class="main-content">
            <div class="content-wrapper">
                <?php
                $allowed_pages = ['beranda', 'daftar_produk', 'barang_masuk', 'barang_keluar', 'laporan', 'report_stock', 'stock_jalur', 'unloading', 'opname_minyak', 'backup_cleanup'];
                if (in_array($page, $allowed_pages) && file_exists($page . '_content.php')) {
                    include $page . '_content.php';
                } else {
                    include 'beranda_content.php';
                }
                ?>
            </div>
        </main>
    </div>

    <script src="js/bootstrap.bundle.min.js"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.5/dist/sweetalert2.all.min.js"></script>
    <script src="js/script.js"></script>


</body>

</html>