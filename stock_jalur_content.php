<?php
$selected_date = $_GET['date'] ?? '';
$selected_product_id = $_GET['product_id_filter'] ?? null;
$selected_incoming_id = $_GET['incoming_id'] ?? null;

$stock_cards_data = [];
if (!empty($selected_date)) {
    $sql_outgoing_by_date = "SELECT DISTINCT o.incoming_transaction_id 
                            FROM outgoing_transactions o 
                            WHERE DATE(o.transaction_date) = ?";
    $params_date = [$selected_date];

    if (!empty($selected_product_id)) {
        $sql_outgoing_by_date .= " AND o.product_id = ?";
        $params_date[] = $selected_product_id;
    }

    $stmt_outgoing_date = $pdo->prepare($sql_outgoing_by_date);
    $stmt_outgoing_date->execute($params_date);
    $outgoing_batch_ids = $stmt_outgoing_date->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($outgoing_batch_ids)) {
        $placeholders = str_repeat('?,', count($outgoing_batch_ids) - 1) . '?';
        $sql_incoming_for_outgoing = "SELECT t.*, p.product_name, p.sku 
                                     FROM incoming_transactions t 
                                     JOIN products p ON t.product_id = p.id 
                                     WHERE t.id IN ($placeholders)
                                     ORDER BY p.product_name ASC, t.created_at ASC";

        $stmt_incoming_for_outgoing = $pdo->prepare($sql_incoming_for_outgoing);
        $stmt_incoming_for_outgoing->execute($outgoing_batch_ids);
        $incoming_for_outgoing = $stmt_incoming_for_outgoing->fetchAll(PDO::FETCH_ASSOC);

        foreach ($incoming_for_outgoing as $incoming) {
            $sql_outgoing = "SELECT * FROM outgoing_transactions WHERE incoming_transaction_id = ? ORDER BY transaction_date ASC, created_at ASC";
            $stmt_outgoing = $pdo->prepare($sql_outgoing);
            $stmt_outgoing->execute([$incoming['id']]);
            $outgoing_data = $stmt_outgoing->fetchAll(PDO::FETCH_ASSOC);

            $stock_cards_data[] = [
                'incoming' => $incoming,
                'outgoing' => $outgoing_data
            ];
        }
    }
}

$sql_products_with_stock = "SELECT DISTINCT p.id, p.product_name, p.sku, p.standard_qty 
                            FROM products p
                            JOIN incoming_transactions t ON p.id = t.product_id
                            ORDER BY p.product_name ASC";
$products_list = $pdo->query($sql_products_with_stock)->fetchAll(PDO::FETCH_ASSOC);

$incoming_data = null;
$outgoing_data = [];

if ($selected_incoming_id) {
    $sql_incoming = "SELECT t.*, p.product_name, p.sku FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE t.id = ?";
    $stmt_incoming = $pdo->prepare($sql_incoming);
    $stmt_incoming->execute([$selected_incoming_id]);
    $incoming_data = $stmt_incoming->fetch(PDO::FETCH_ASSOC);

    if ($incoming_data) {
        $sql_outgoing = "SELECT * FROM outgoing_transactions WHERE incoming_transaction_id = ? ORDER BY transaction_date ASC, created_at ASC";
        $stmt_outgoing = $pdo->prepare($sql_outgoing);
        $stmt_outgoing->execute([$selected_incoming_id]);
        $outgoing_data = $stmt_outgoing->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<div class="container-fluid" id="stockJalurPage" data-selected-batch-id="<?= htmlspecialchars($selected_incoming_id ?? '') ?>">
    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-0 fw-bold d-flex align-items-center">
                <i class="bi bi-graph-up me-2"></i>
                Stock Jalur - Pilih Filter
            </h2>

            <!-- Enhanced Mobile-Friendly Date Filter Form -->
            <div class="mt-3">
                <div class="card border-0 bg-light">
                    <div class="card-header bg-primary text-white py-2">
                        <h6 class="mb-0 fw-semibold">
                            <i class="bi bi-calendar-date me-2"></i>
                            Filter berdasarkan Tanggal
                        </h6>
                    </div>
                    <div class="card-body p-3">
                        <form action="index.php" method="GET" class="mobile-date-filter">
                            <input type="hidden" name="page" value="stock_jalur">

                            <div class="row g-3">
                                <div class="col-12 col-md-4">
                                    <label for="date_filter" class="form-label small fw-semibold text-primary">
                                        <i class="bi bi-calendar3 me-1"></i>Pilih Tanggal
                                    </label>
                                    <input type="date" name="date" id="date_filter"
                                        class="form-control form-control-lg"
                                        value="<?= htmlspecialchars($selected_date) ?>"
                                        style="font-size: 16px;">
                                </div>

                                <div class="col-12 col-md-4">
                                    <label for="product_filter_date" class="form-label small fw-semibold text-primary">
                                        <i class="bi bi-box me-1"></i>Produk (Opsional)
                                    </label>
                                    <select name="product_id_filter" id="product_filter_date"
                                        class="form-select form-select-lg" style="font-size: 16px;">
                                        <option value="">-- Semua Produk --</option>
                                        <?php foreach ($products_list as $product): ?>
                                            <option value="<?= $product['id'] ?>" <?= ($selected_product_id == $product['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($product['product_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-12 col-md-4 d-flex flex-column justify-content-end">
                                    <div class="d-grid gap-2">
                                        <button type="submit" class="btn btn-success btn-lg">
                                            <i class="bi bi-search me-2"></i>Tampilkan
                                        </button>
                                        <?php if (!empty($selected_date)): ?>
                                            <a href="export_stock_jalur.php?date=<?= urlencode($selected_date) ?>&product_id=<?= urlencode($selected_product_id) ?>"
                                                class="btn btn-info btn-lg">
                                                <i class="bi bi-download me-2"></i>Export Excel
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($selected_date)): ?>
                                <div class="mt-3 p-2 bg-success bg-opacity-3 border border-success rounded">
                                    <small class="text-success fw-semibold">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Menampilkan produk dengan pengeluaran pada tanggal: <strong><?= date('d/m/Y', strtotime($selected_date)) ?></strong>
                                        <?php if (!empty($selected_product_id)):
                                            $selected_product_name = '';
                                            foreach ($products_list as $product) {
                                                if ($product['id'] == $selected_product_id) {
                                                    $selected_product_name = $product['product_name'];
                                                    break;
                                                }
                                            }
                                        ?>
                                            - Produk: <strong><?= htmlspecialchars($selected_product_name) ?></strong>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Original Product-Batch Filter with Mobile Enhancement -->
            <div class="mt-4">
                <div class="card border-0 bg-light">
                    <div class="card-header bg-secondary text-white py-2">
                        <h6 class="mb-0 fw-semibold">
                            <i class="bi bi-box-seam me-2"></i>
                            Filter berdasarkan Produk & Batch
                        </h6>
                    </div>
                    <div class="card-body p-3">
                        <form action="index.php" method="GET" class="mobile-batch-filter">
                            <input type="hidden" name="page" value="stock_jalur">
                            <input type="hidden" name="product_id_filter" id="product_id_kartu_stok_hidden">

                            <div class="row g-3">
                                <div class="col-12 col-md-5">
                                    <label for="product_name_kartu_stok" class="form-label small fw-semibold text-secondary">
                                        <span class="badge bg-primary me-1">1</span>Pilih Nama Barang
                                    </label>
                                    <input class="form-control form-control-lg"
                                        list="datalistProductsKartuStok"
                                        id="product_name_kartu_stok"
                                        placeholder="Ketik untuk mencari..."
                                        style="font-size: 16px;">
                                    <datalist id="datalistProductsKartuStok">
                                        <?php foreach ($products_list as $product): ?>
                                            <option value="<?= htmlspecialchars($product['product_name']) ?>" data-id="<?= $product['id'] ?>">
                                            <?php endforeach; ?>
                                    </datalist>
                                </div>

                                <div class="col-12 col-md-5">
                                    <label for="incoming_id" class="form-label small fw-semibold text-secondary">
                                        <span class="badge bg-primary me-1">2</span>Pilih Batch Kedatangan
                                    </label>
                                    <select name="incoming_id" id="incoming_id"
                                        class="form-select form-select-lg"
                                        disabled style="font-size: 16px;">
                                        <option value="">-- Pilih Nama Barang dulu --</option>
                                    </select>
                                </div>

                                <div class="col-12 col-md-2 d-flex flex-column justify-content-end">
                                    <div class="d-grid d-none d-md-block">
                                        <button type="submit" class="btn btn-primary btn-lg">
                                            <i class="bi bi-eye me-2"></i>Tampilkan
                                        </button>
                                    </div>
                                    <!-- Fallback button visible on small screens too -->
                                    <div class="d-grid d-md-none mt-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-funnel me-2"></i>Filter
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Display stock cards based on date filter -->
        <?php if (!empty($selected_date)): ?>
            <div class="card-body border-top">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
                    <h4 class="fw-bold mb-2 mb-md-0">
                        <i class="bi bi-calendar-check me-2 text-primary"></i>
                        Stock Jalur Tanggal: <?= date('d/m/Y', strtotime($selected_date)) ?>
                    </h4>
                    <div class="d-flex flex-column flex-sm-row gap-2">
                        <span class="badge bg-info fs-6 px-3 py-2">
                            <i class="bi bi-box-seam me-1"></i>
                            <?= count($stock_cards_data) ?> PRODUK
                        </span>
                    </div>
                </div>

                <?php if (!empty($stock_cards_data)): ?>
                    <!-- Display each stock card -->
                    <?php foreach ($stock_cards_data as $index => $card_data):
                        $incoming = $card_data['incoming'];
                        $outgoing_list = $card_data['outgoing'];
                    ?>
                        <div class="card border-0 mb-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                            <div class="card-header text-white border-0" style="background: rgba(255,255,255,0.1);">
                                <h5 class="mb-0 fw-bold d-flex align-items-center">
                                    <i class="bi bi-box-seam me-2"></i>
                                    <?= strtoupper(htmlspecialchars($incoming['product_name'])) ?>
                                </h5>
                            </div>
                            <div class="card-body text-white">
                                <!-- Product Details in 2 columns -->
                                <div class="row mb-4">
                                    <div class="col-12 col-md-6">
                                        <div class="row g-2">
                                            <div class="col-5 col-sm-4">
                                                <span class="fw-semibold" style="color: rgba(255,255,255,0.8);">Nama Barang:</span>
                                            </div>
                                            <div class="col-7 col-sm-8">
                                                <strong><?= htmlspecialchars($incoming['product_name']) ?></strong>
                                            </div>

                                            <div class="col-5 col-sm-4">
                                                <span class="fw-semibold" style="color: rgba(255,255,255,0.8);">Tanggal Datang:</span>
                                            </div>
                                            <div class="col-7 col-sm-8">
                                                <strong><?= date('Y-m-d', strtotime($incoming['transaction_date'])) ?></strong>
                                            </div>

                                            <div class="col-5 col-sm-4">
                                                <span class="fw-semibold" style="color: rgba(255,255,255,0.8);">Supplier:</span>
                                            </div>
                                            <div class="col-7 col-sm-8">
                                                <strong><?= htmlspecialchars($incoming['supplier'] ?: '-') ?></strong>
                                            </div>

                                            <div class="col-5 col-sm-4">
                                                <span class="fw-semibold" style="color: rgba(255,255,255,0.8);">Batch:</span>
                                            </div>
                                            <div class="col-7 col-sm-8">
                                                <strong style="color: #ff6b6b;"><?= htmlspecialchars($incoming['batch_number'] ?: '-') ?></strong>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-12 col-md-6 mt-3 mt-md-0">
                                        <div class="row g-2">
                                            <div class="col-5 col-sm-4">
                                                <span class="fw-semibold" style="color: rgba(255,255,255,0.8);">No. PO:</span>
                                            </div>
                                            <div class="col-7 col-sm-8">
                                                <strong><?= htmlspecialchars($incoming['po_number'] ?: '-') ?></strong>
                                            </div>

                                            <div class="col-5 col-sm-4">
                                                <span class="fw-semibold" style="color: rgba(255,255,255,0.8);">Produsen:</span>
                                            </div>
                                            <div class="col-7 col-sm-8">
                                                <strong><?= htmlspecialchars($incoming['produsen'] ?: '-') ?></strong>
                                            </div>

                                            <div class="col-5 col-sm-4">
                                                <span class="fw-semibold" style="color: rgba(255,255,255,0.8);">Jumlah Datang (Kg):</span>
                                            </div>
                                            <div class="col-7 col-sm-8">
                                                <strong style="color: #4ecdc4;"><?= formatAngkaUI($incoming['quantity_kg']) ?></strong>
                                            </div>

                                            <div class="col-5 col-sm-4">
                                                <span class="fw-semibold" style="color: rgba(255,255,255,0.8);">Jumlah Datang (Sak):</span>
                                            </div>
                                            <div class="col-7 col-sm-8">
                                                <strong style="color: #4ecdc4;"><?= formatAngkaUI($incoming['quantity_sacks']) ?></strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Shipment History -->
                                <div class="mt-4">
                                    <h6 class="fw-bold mb-3 d-flex align-items-center">
                                        <i class="bi bi-clock-history me-2"></i>
                                        Riwayat Pengiriman
                                    </h6>
                                    <div class="table-responsive">
                                        <table class="table table-dark table-bordered">
                                            <thead style="background-color: rgba(0,0,0,0.3);">
                                                <tr>
                                                    <th class="text-center" style="width: 8%;">NO.</th>
                                                    <th class="text-center">TANGGAL PENGIRIMAN</th>
                                                    <th class="text-center">JUMLAH KELUAR (SAK)</th>
                                                    <th class="text-center">SISA STOK (SAK)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (empty($outgoing_list)): ?>
                                                    <tr>
                                                        <td colspan="4" class="text-center py-4" style="color: rgba(255,255,255,0.7);">
                                                            <i class="bi bi-inbox fs-4 d-block mb-2"></i>
                                                            Belum ada pengiriman untuk batch ini.
                                                        </td>
                                                    </tr>
                                                    <?php else:
                                                    $sisa_stok_sak = $incoming['quantity_sacks'];
                                                    foreach ($outgoing_list as $out_index => $tx):
                                                        $sisa_stok_sak -= $tx['quantity_sacks'];
                                                    ?>
                                                        <tr>
                                                            <td class="text-center fw-bold"><?= $out_index + 1 ?></td>
                                                            <td class="text-center"><?= date('Y-m-d', strtotime($tx['transaction_date'])) ?></td>
                                                            <td class="text-center fw-bold" style="color: #ff6b6b;"><?= formatAngkaUI($tx['quantity_sacks']) ?></td>
                                                            <td class="text-center fw-bold" style="color: #4ecdc4;"><?= formatAngkaUI($sisa_stok_sak) ?></td>
                                                        </tr>
                                                <?php endforeach;
                                                endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info d-flex align-items-center">
                        <i class="bi bi-info-circle me-3 fs-4"></i>
                        <div>
                            <strong>Tidak ada pengeluaran</strong><br>
                            <small>Tidak ada pengeluaran barang pada tanggal <?= date('d/m/Y', strtotime($selected_date)) ?></small>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Original batch-specific display -->
        <?php if ($incoming_data): ?>
            <div class="card-body border-top">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between"><span>Nama Barang:</span> <strong><?= htmlspecialchars($incoming_data['product_name']) ?></strong></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Tanggal Datang:</span> <strong><?= htmlspecialchars($incoming_data['transaction_date']) ?></strong></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Supplier:</span> <strong><?= htmlspecialchars($incoming_data['supplier'] ?: '-') ?></strong></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Batch:</span> <strong><?= htmlspecialchars($incoming_data['batch_number'] ?: '-') ?></strong></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between"><span>No. PO:</span> <strong><?= htmlspecialchars($incoming_data['po_number'] ?: '-') ?></strong></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Produsen:</span> <strong><?= htmlspecialchars($incoming_data['produsen'] ?: '-') ?></strong></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Jumlah Datang (Kg):</span> <strong><?= formatAngka($incoming_data['quantity_kg']) ?></strong></li>
                            <li class="list-group-item d-flex justify-content-between"><span>Jumlah Datang (Sak):</span> <strong><?= formatAngka($incoming_data['quantity_sacks']) ?></strong></li>
                        </ul>
                    </div>
                </div>
                <div class="table-responsive mt-3">
                    <h6 class="fw-bold">Riwayat Pengiriman</h6>
                    <table class="table table-bordered table-hover">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 5%;">No.</th>
                                <th>Tanggal Pengiriman</th>
                                <th>Jumlah Keluar (Sak)</th>
                                <th>Sisa Stok (Sak)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($outgoing_data)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted">Belum ada pengiriman untuk batch ini.</td>
                                </tr>
                                <?php else:
                                $sisa_stok_sak = $incoming_data['quantity_sacks'];
                                foreach ($outgoing_data as $index => $tx):
                                    $sisa_stok_sak -= $tx['quantity_sacks'];
                                ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($tx['transaction_date']) ?></td>
                                        <td><?= formatAngka($tx['quantity_sacks']) ?></td>
                                        <td class="fw-bold"><?= formatAngka($sisa_stok_sak) ?></td>
                                    </tr>
                            <?php endforeach;
                            endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($selected_incoming_id): ?>
            <div class="card-body text-center text-muted border-top p-4">
                <p>Data untuk batch yang dipilih tidak ditemukan.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
    
    @media (max-width: 768px) {

        .mobile-date-filter .form-control,
        .mobile-date-filter .form-select,
        .mobile-batch-filter .form-control,
        .mobile-batch-filter .form-select {
            font-size: 16px !important;
            padding: 0.75rem !important;
            border-radius: 0.5rem !important;
        }

        .mobile-date-filter .btn,
        .mobile-batch-filter .btn {
            padding: 0.75rem 1rem !important;
            font-size: 16px !important;
            border-radius: 0.5rem !important;
        }

        .card-header h6 {
            font-size: 0.9rem !important;
        }

        .badge.fs-6 {
            font-size: 0.8rem !important;
            padding: 0.5rem 0.75rem !important;
        }

        .table-responsive {
            border-radius: 0.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .table th {
            font-size: 0.8rem !important;
            padding: 0.75rem 0.5rem !important;
            white-space: nowrap;
        }

        .table td {
            font-size: 0.85rem !important;
            padding: 0.75rem 0.5rem !important;
        }

        .list-group-item {
            padding: 0.75rem !important;
            font-size: 0.9rem !important;
        }

        .list-group-item span {
            flex: 1;
            margin-right: 0.5rem;
        }

        .list-group-item strong {
            flex: 0 0 auto;
            max-width: 60%;
            word-break: break-word;
        }
    }

    @media (max-width: 576px) {

        .mobile-date-filter .row,
        .mobile-batch-filter .row {
            --bs-gutter-x: 1rem;
            --bs-gutter-y: 1rem;
        }

        .card-body {
            padding: 1rem !important;
        }

        .table th,
        .table td {
            font-size: 0.75rem !important;
            padding: 0.5rem 0.25rem !important;
        }

        .badge {
            font-size: 0.7rem !important;
            padding: 0.25rem 0.5rem !important;
        }

        .row.g-2>* {
            padding: 0.25rem !important;
        }

        .row.g-2 .col-5,
        .row.g-2 .col-7 {
            font-size: 0.85rem !important;
        }
    }

    
    @media (hover: none) and (pointer: coarse) {


        .form-control,
        .form-select {
            min-height: 44px;
        }

        .table td,
        .table th {
            padding: 1rem 0.5rem !important;
        }
    }
</style>