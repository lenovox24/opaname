<?php
require_once __DIR__ . '/koneksi.php';

// Initialize variables
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$product_id = $_GET['product_id'] ?? '';

// Get all products for dropdown
try {
    $stmt_products = $pdo->query("SELECT id, sku, product_name FROM products ORDER BY product_name ASC");
    $products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
}

// Initialize report data
$report_data = [];
$selected_product = null;
$total_summary = [
    'stock_awal_kg' => 0, 'stock_awal_sak' => 0,
    'stock_masuk_kg' => 0, 'stock_masuk_sak' => 0,
    'stock_keluar_kg' => 0, 'stock_keluar_sak' => 0,
    'stock_akhir_kg' => 0, 'stock_akhir_sak' => 0
];

// Ringkasan akhir periode & sisa per batch
$period_summary = [ 'akhir_kg' => 0, 'akhir_sak' => 0 ];
$batch_remains = [];

// Prefetch selected product if product_id provided, so the info block can always be shown
if (!empty($product_id) && $selected_product === null) {
    try {
        $stmt = $pdo->prepare("SELECT id, sku, product_name FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $selected_product = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $selected_product = null;
    }
}

// Process if all parameters are present
$should_generate = (!empty($start_date) && !empty($end_date) && !empty($product_id));
if ($should_generate) {
    // Get selected product
    try {
        $stmt = $pdo->prepare("SELECT id, sku, product_name FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $selected_product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($selected_product) {
            // Create date range
            $start = new DateTime($start_date);
            $end = new DateTime($end_date);
            
            // Process each date
            $current = clone $start;
            while ($current <= $end) {
                $date_str = $current->format('Y-m-d');
                
                // Stock awal (before current date)
                $stmt_awal_in = $pdo->prepare("
                    SELECT COALESCE(SUM(quantity_kg), 0) as kg, COALESCE(SUM(quantity_sacks), 0) as sak 
                    FROM incoming_transactions 
                    WHERE product_id = ? AND transaction_date < ?
                ");
                $stmt_awal_in->execute([$product_id, $date_str]);
                $awal_in = $stmt_awal_in->fetch(PDO::FETCH_ASSOC);
                
                $stmt_awal_out = $pdo->prepare("
                    SELECT COALESCE(SUM(quantity_kg), 0) as kg, COALESCE(SUM(quantity_sacks), 0) as sak 
                    FROM outgoing_transactions 
                    WHERE product_id = ? AND transaction_date < ?
                ");
                $stmt_awal_out->execute([$product_id, $date_str]);
                $awal_out = $stmt_awal_out->fetch(PDO::FETCH_ASSOC);
                
                $stock_awal_kg = ($awal_in['kg'] ?? 0) - ($awal_out['kg'] ?? 0);
                $stock_awal_sak = ($awal_in['sak'] ?? 0) - ($awal_out['sak'] ?? 0);
                
                // Stock masuk (on current date)
                $stmt_masuk = $pdo->prepare("
                    SELECT COALESCE(SUM(quantity_kg), 0) as kg, COALESCE(SUM(quantity_sacks), 0) as sak 
                    FROM incoming_transactions 
                    WHERE product_id = ? AND transaction_date = ?
                ");
                $stmt_masuk->execute([$product_id, $date_str]);
                $masuk = $stmt_masuk->fetch(PDO::FETCH_ASSOC);
                
                // Stock keluar (on current date)
                $stmt_keluar = $pdo->prepare("
                    SELECT COALESCE(SUM(quantity_kg), 0) as kg, COALESCE(SUM(quantity_sacks), 0) as sak 
                    FROM outgoing_transactions 
                    WHERE product_id = ? AND transaction_date = ?
                ");
                $stmt_keluar->execute([$product_id, $date_str]);
                $keluar = $stmt_keluar->fetch(PDO::FETCH_ASSOC);
                
                // Stock akhir
                $stock_akhir_kg = $stock_awal_kg + ($masuk['kg'] ?? 0) - ($keluar['kg'] ?? 0);
                $stock_akhir_sak = $stock_awal_sak + ($masuk['sak'] ?? 0) - ($keluar['sak'] ?? 0);
                
                // Add to report data
                $report_data[] = [
                    'tanggal' => $date_str,
                    'stock_awal_kg' => $stock_awal_kg,
                    'stock_awal_sak' => $stock_awal_sak,
                    'stock_masuk_kg' => $masuk['kg'] ?? 0,
                    'stock_masuk_sak' => $masuk['sak'] ?? 0,
                    'stock_keluar_kg' => $keluar['kg'] ?? 0,
                    'stock_keluar_sak' => $keluar['sak'] ?? 0,
                    'stock_akhir_kg' => $stock_akhir_kg,
                    'stock_akhir_sak' => $stock_akhir_sak
                ];
                
                // Add to totals
                $total_summary['stock_awal_kg'] += $stock_awal_kg;
                $total_summary['stock_awal_sak'] += $stock_awal_sak;
                $total_summary['stock_masuk_kg'] += ($masuk['kg'] ?? 0);
                $total_summary['stock_masuk_sak'] += ($masuk['sak'] ?? 0);
                $total_summary['stock_keluar_kg'] += ($keluar['kg'] ?? 0);
                $total_summary['stock_keluar_sak'] += ($keluar['sak'] ?? 0);
                $total_summary['stock_akhir_kg'] += $stock_akhir_kg;
                $total_summary['stock_akhir_sak'] += $stock_akhir_sak;
                
                $current->add(new DateInterval('P1D'));
            }

            // Hitung stok akhir pada akhir periode (<= end_date)
            $stmt_sum_in = $pdo->prepare("SELECT COALESCE(SUM(quantity_kg),0) AS kg, COALESCE(SUM(quantity_sacks),0) AS sak FROM incoming_transactions WHERE product_id = ? AND transaction_date <= ?");
            $stmt_sum_in->execute([$product_id, $end_date]);
            $sum_in = $stmt_sum_in->fetch(PDO::FETCH_ASSOC) ?: ['kg' => 0, 'sak' => 0];

            $stmt_sum_out = $pdo->prepare("SELECT COALESCE(SUM(quantity_kg),0) AS kg, COALESCE(SUM(quantity_sacks),0) AS sak FROM outgoing_transactions WHERE product_id = ? AND transaction_date <= ?");
            $stmt_sum_out->execute([$product_id, $end_date]);
            $sum_out = $stmt_sum_out->fetch(PDO::FETCH_ASSOC) ?: ['kg' => 0, 'sak' => 0];

            $period_summary['akhir_kg'] = (float)($sum_in['kg'] ?? 0) - (float)($sum_out['kg'] ?? 0);
            $period_summary['akhir_sak'] = (float)($sum_in['sak'] ?? 0) - (float)($sum_out['sak'] ?? 0);

            // Sisa per batch pada akhir periode, termasuk sisa 501 dengan fallback batch_number
            $sql_batch = "
                SELECT
                    t_in.id,
                    t_in.transaction_date,
                    t_in.batch_number,
                    t_in.quantity_kg,
                    t_in.quantity_sacks,
                    t_in.lot_number,
                    -- total keluar biasa (kg, sak) by incoming id
                    COALESCE((SELECT SUM(o.quantity_kg) FROM outgoing_transactions o WHERE o.incoming_transaction_id = t_in.id AND o.transaction_date <= ?), 0) AS out_kg,
                    COALESCE((SELECT SUM(o.quantity_sacks) FROM outgoing_transactions o WHERE o.incoming_transaction_id = t_in.id AND o.transaction_date <= ?), 0) AS out_sak,
                    -- total keluar 501 by incoming id
                    COALESCE((SELECT SUM(o.lot_number) FROM outgoing_transactions o WHERE o.lot_number > 0 AND o.incoming_transaction_id = t_in.id AND o.transaction_date <= ?), 0) AS out_501_in,
                    -- fallback total keluar 501 by batch when incoming_id is null/0
                    COALESCE((
                        SELECT SUM(o2.lot_number) FROM outgoing_transactions o2
                        WHERE o2.lot_number > 0 AND (o2.incoming_transaction_id IS NULL OR o2.incoming_transaction_id = 0)
                          AND o2.batch_number = t_in.batch_number AND o2.product_id = t_in.product_id AND o2.transaction_date <= ?
                    ), 0) AS out_501_batch
                FROM incoming_transactions t_in
                WHERE t_in.product_id = ?
                ORDER BY t_in.transaction_date ASC, t_in.id ASC
            ";

            $stmt_batch = $pdo->prepare($sql_batch);
            $stmt_batch->execute([$end_date, $end_date, $end_date, $end_date, $product_id]);
            $rows = $stmt_batch->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $r) {
                $sisa_kg = (float)$r['quantity_kg'] - (float)$r['out_kg'];
                $sisa_sak = (float)$r['quantity_sacks'] - (float)$r['out_sak'];
                $total_out_501 = (float)$r['out_501_in'] + (float)$r['out_501_batch'];
                $sisa_501 = (float)$r['lot_number'] - $total_out_501;
                if ($sisa_kg > 0 || $sisa_sak > 0 || $sisa_501 > 0) {
                    $batch_remains[] = [
                        'batch_number' => $r['batch_number'],
                        'transaction_date' => $r['transaction_date'],
                        'sisa_kg' => $sisa_kg,
                        'sisa_sak' => $sisa_sak,
                        'sisa_501' => $sisa_501
                    ];
                }
            }
        }
    } catch (Exception $e) {
        // Silent error handling
    }
}
?>

<div class="container-fluid">
    <div class="card">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-3 fw-bold">
                <i class="bi bi-graph-up me-2 text-primary"></i>Report Stock Per Item
            </h2>
            
            <!-- Form Filter -->
            <form action="index.php" method="GET" class="mb-3">
                <input type="hidden" name="page" value="report_stock">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Tanggal Mulai</label>
                        <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Tanggal Akhir</label>
                        <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Pilih Barang</label>
                        <select name="product_id" class="form-select" required>
                            <option value="">-- Pilih Produk --</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= $product['id'] ?>" <?= ($product_id == $product['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($product['product_name']) ?> (<?= htmlspecialchars($product['sku']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" name="generate" value="1" class="btn btn-primary w-100 d-block">
                            <i class="bi bi-play-circle me-1"></i>Generate
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="card-body">
            <!-- Product Info: always visible when a product is selected; show minimal guide otherwise -->
            <div class="alert alert-success alert-persistent mb-3">
                <h6 class="fw-bold mb-2">Informasi Barang:</h6>
                <?php if ($selected_product): ?>
                    <strong>Nama:</strong> <?= htmlspecialchars($selected_product['product_name']) ?><br>
                    <strong>Kode:</strong> <?= htmlspecialchars($selected_product['sku']) ?><br>
                    <?php if (!empty($start_date) && !empty($end_date)): ?>
                        <strong>Periode:</strong> <?= date('d/m/Y', strtotime($start_date)) ?> - <?= date('d/m/Y', strtotime($end_date)) ?>
                    <?php else: ?>
                        <small class="text-muted">Pilih periode untuk menampilkan rentang tanggal.</small>
                    <?php endif; ?>
                <?php else: ?>
                    <small class="text-muted">Silakan pilih barang untuk menampilkan informasi barang.</small>
                <?php endif; ?>
            </div>

            <?php 
            $show_results = $should_generate;
            if ($show_results): 
            ?>
                <?php if ($selected_product): ?>
                    <!-- Ringkasan Akhir Periode -->
                    <div class="alert alert-info">
                        <div class="d-flex align-items-center">
                            <i class="bi bi-clipboard-data me-2"></i>
                            <div>
                                <div class="fw-semibold">Stock Akhir Periode (s/d <?= htmlspecialchars($end_date) ?>)</div>
                                <div>
                                    Kg: <strong><?= number_format($period_summary['akhir_kg'], 2, '.', ',') ?></strong>
                                    &nbsp; | &nbsp;
                                    Sak: <strong><?= number_format($period_summary['akhir_sak'], 2, '.', ',') ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Report Table -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-primary">
                                <tr>
                                    <th rowspan="2" class="text-center align-middle">Tanggal</th>
                                    <th colspan="2" class="text-center">Stock Awal</th>
                                    <th colspan="2" class="text-center">Stock Masuk</th>
                                    <th colspan="2" class="text-center">Stock Keluar</th>
                                    <th colspan="2" class="text-center">Stock Akhir</th>
                                </tr>
                                <tr>
                                    <th class="text-center">Kg</th>
                                    <th class="text-center">Sak</th>
                                    <th class="text-center">Kg</th>
                                    <th class="text-center">Sak</th>
                                    <th class="text-center">Kg</th>
                                    <th class="text-center">Sak</th>
                                    <th class="text-center">Kg</th>
                                    <th class="text-center">Sak</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($report_data)): ?>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <td class="text-center fw-semibold"><?= date('d/m/Y', strtotime($row['tanggal'])) ?></td>
                                            <td class="text-end"><?= number_format($row['stock_awal_kg'], 2, '.', ',') ?></td>
                                            <td class="text-end"><?= number_format($row['stock_awal_sak'], 2, '.', ',') ?></td>
                                            <td class="text-end text-success fw-semibold"><?= number_format($row['stock_masuk_kg'], 2, '.', ',') ?></td>
                                            <td class="text-end text-success fw-semibold"><?= number_format($row['stock_masuk_sak'], 2, '.', ',') ?></td>
                                            <td class="text-end text-danger fw-semibold"><?= number_format($row['stock_keluar_kg'], 2, '.', ',') ?></td>
                                            <td class="text-end text-danger fw-semibold"><?= number_format($row['stock_keluar_sak'], 2, '.', ',') ?></td>
                                            <td class="text-end text-primary fw-bold"><?= number_format($row['stock_akhir_kg'], 2, '.', ',') ?></td>
                                            <td class="text-end text-primary fw-bold"><?= number_format($row['stock_akhir_sak'], 2, '.', ',') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center p-4">
                                            <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                                            Tidak ada data untuk periode yang dipilih<br>
                                            <small class="text-muted">Mungkin tidak ada transaksi pada rentang tanggal ini</small>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($report_data)): ?>
                                <tfoot class="table-warning">
                                    <tr>
                                        <th class="text-center">TOTAL</th>
                                        <th class="text-end"><?= number_format($total_summary['stock_awal_kg'], 2, '.', ',') ?></th>
                                        <th class="text-end"><?= number_format($total_summary['stock_awal_sak'], 2, '.', ',') ?></th>
                                        <th class="text-end text-success"><?= number_format($total_summary['stock_masuk_kg'], 2, '.', ',') ?></th>
                                        <th class="text-end text-success"><?= number_format($total_summary['stock_masuk_sak'], 2, '.', ',') ?></th>
                                        <th class="text-end text-danger"><?= number_format($total_summary['stock_keluar_kg'], 2, '.', ',') ?></th>
                                        <th class="text-end text-danger"><?= number_format($total_summary['stock_keluar_sak'], 2, '.', ',') ?></th>
                                        <th class="text-end text-primary fw-bold"><?= number_format($total_summary['stock_akhir_kg'], 2, '.', ',') ?></th>
                                        <th class="text-end text-primary fw-bold"><?= number_format($total_summary['stock_akhir_sak'], 2, '.', ',') ?></th>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>

                    <!-- Sisa per Batch (Akhir Periode) -->
                    <div class="card mt-4">
                        <div class="card-header bg-warning">
                            <h6 class="mb-0 fw-bold"><i class="bi bi-collection me-2"></i>Sisa per Batch (Akhir Periode)</h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-bordered mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th class="text-center" style="width: 12%">Tgl Datang</th>
                                            <th class="text-start">Batch</th>
                                            <th class="text-end" style="width: 18%">Sisa Stok (Kg)</th>
                                            <th class="text-end" style="width: 18%">Sisa Stok (Sak)</th>
                                            <th class="text-end" style="width: 18%">Sisa 501 (Kg)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (!empty($batch_remains)): ?>
                                            <?php foreach ($batch_remains as $b): ?>
                                                <tr>
                                                    <td class="text-center"><?= htmlspecialchars($b['transaction_date']) ?></td>
                                                    <td class="text-start"><span class="badge bg-info text-white"><?= htmlspecialchars($b['batch_number'] ?: '-') ?></span></td>
                                                    <td class="text-end fw-semibold"><?= number_format(max(0, $b['sisa_kg']), 2, '.', ',') ?></td>
                                                    <td class="text-end fw-semibold"><?= number_format(max(0, $b['sisa_sak']), 2, '.', ',') ?></td>
                                                    <td class="text-end fw-semibold text-success"><?= number_format(max(0, $b['sisa_501']), 2, '.', ',') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted p-4">
                                                    <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                                                    Tidak ada sisa per batch pada akhir periode.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        Produk tidak ditemukan! (Product ID: <?= htmlspecialchars($product_id) ?>)
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-graph-up display-1 opacity-25"></i>
                    <h5 class="mt-3">Silakan pilih rentang tanggal dan barang</h5>
                    <p>Lalu klik tombol Generate untuk melihat laporan</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.table th { font-size: 0.9rem; font-weight: 600; }
.table td { font-size: 0.9rem; }
.table-primary th { background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white !important; }
.table-warning th { background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); color: #212529 !important; font-weight: 700; }
</style>