<?php
$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$filter_qty_kg = $_GET['filter_qty_kg'] ?? '';
$hide_zero_closing = isset($_GET['hide_zero_closing']) ? (bool)$_GET['hide_zero_closing'] : false;

// Hitung tanggal sebelumnya (H-1) untuk mengambil data barang masuk/keluar dan stock awal
$previous_date = date('Y-m-d', strtotime($filter_date . ' -1 day'));
$two_days_ago = date('Y-m-d', strtotime($filter_date . ' -2 day'));

// Tentukan sistem yang digunakan
if ($filter_date == '2025-08-23') {
    // SISTEM BATCH CLOSING - HANYA untuk tanggal 23
    $sql = "
        SELECT
            p.id, p.sku, p.product_name,
            
            -- Stock awal: total semua transaksi sampai hari sebelumnya (H-1)
            (COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END), 0))
            AS opening_stock_kg,
            
            (COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date <= ? THEN t.quantity_sacks ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date <= ? THEN t.quantity_sacks ELSE 0 END), 0))
            AS opening_stock_sak,

            -- Transaksi masuk dan keluar pada H-1
            COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date = ? THEN t.quantity_kg ELSE 0 END), 0) AS incoming_kg_today,
            COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date = ? THEN t.quantity_sacks ELSE 0 END), 0) AS incoming_sak_today,
            
            COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date = ? THEN t.quantity_kg ELSE 0 END), 0) AS outgoing_kg_today,
            COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date = ? THEN t.quantity_sacks ELSE 0 END), 0) AS outgoing_sak_today,
            
            -- Stock akhir dari closingan batch sampai tanggal 23
            (COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END), 0))
            AS closing_stock_kg_batch,
            
            (COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date <= ? THEN t.quantity_sacks ELSE 0 END), 0) -
             COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date <= ? THEN t.quantity_sacks ELSE 0 END), 0))
            AS closing_stock_sak_batch

        FROM
            products p
        LEFT JOIN (
            SELECT 
                product_id, 
                transaction_date, 
                quantity_kg, 
                quantity_sacks, 
                'IN' as type 
            FROM incoming_transactions
            WHERE status IN ('Closed', 'Pending')
            UNION ALL
            SELECT 
                product_id, 
                transaction_date, 
                quantity_kg, 
                quantity_sacks, 
                'OUT' as type 
            FROM outgoing_transactions
            WHERE status IN ('Closed', 'Pending')
        ) as t ON p.id = t.product_id
        GROUP BY
            p.id, p.sku, p.product_name
        ORDER BY
            p.product_name ASC
    ";
    
    $params = [
        $previous_date,  // opening_stock_kg (IN) - sampai H-1
        $previous_date,  // opening_stock_kg (OUT) - sampai H-1
        $previous_date,  // opening_stock_sak (IN) - sampai H-1
        $previous_date,  // opening_stock_sak (OUT) - sampai H-1
        $previous_date,  // incoming_kg_today - dari H-1
        $previous_date,  // incoming_sak_today - dari H-1
        $previous_date,  // outgoing_kg_today - dari H-1
        $previous_date,  // outgoing_sak_today - dari H-1
        $filter_date,    // closing_stock_kg_batch (IN) - sampai tanggal laporan
        $filter_date,    // closing_stock_kg_batch (OUT) - sampai tanggal laporan
        $filter_date,    // closing_stock_sak_batch (IN) - sampai tanggal laporan
        $filter_date     // closing_stock_sak_batch (OUT) - sampai tanggal laporan
    ];
    
} else {
    // SISTEM NORMAL - untuk tanggal 24 dan selanjutnya
    // Terapkan jeda 1 hari: transaksi H-1 ditampilkan & dihitung pada tanggal H
    // Stock awal diambil dari stock akhir H-1 (atau override jika ada)
    
    $sql = "
        SELECT
            p.id, p.sku, p.product_name,
            
            -- Nilai dasar untuk perhitungan di PHP
            prev_stock.closing_stock_kg AS prev_closing_stock_kg,
            prev_stock.closing_stock_sak AS prev_closing_stock_sak,

            -- Override untuk tanggal H (langsung dipakai jika ada)
            o_current.opening_stock_kg AS o_current_opening_kg,
            o_current.opening_stock_sak AS o_current_opening_sak,

            -- Override terakhir <= H-1 (untuk propagasi ke depan)
            o_prev.report_date AS o_prev_date,
            o_prev.opening_stock_kg AS o_prev_opening_kg,
            o_prev.opening_stock_sak AS o_prev_opening_sak,

            -- Net range dari (o_prev_date - 1) s.d. (H-2), untuk propagasi
            (COALESCE(SUM(CASE WHEN t.type = 'IN'  AND o_prev.report_date IS NOT NULL AND t.transaction_date BETWEEN DATE_SUB(o_prev.report_date, INTERVAL 1 DAY) AND DATE_SUB(?, INTERVAL 1 DAY) THEN t.quantity_kg ELSE 0 END), 0)
             - COALESCE(SUM(CASE WHEN t.type = 'OUT' AND o_prev.report_date IS NOT NULL AND t.transaction_date BETWEEN DATE_SUB(o_prev.report_date, INTERVAL 1 DAY) AND DATE_SUB(?, INTERVAL 1 DAY) THEN t.quantity_kg ELSE 0 END), 0)) AS net_range_kg,
            (COALESCE(SUM(CASE WHEN t.type = 'IN'  AND o_prev.report_date IS NOT NULL AND t.transaction_date BETWEEN DATE_SUB(o_prev.report_date, INTERVAL 1 DAY) AND DATE_SUB(?, INTERVAL 1 DAY) THEN t.quantity_sacks ELSE 0 END), 0)
             - COALESCE(SUM(CASE WHEN t.type = 'OUT' AND o_prev.report_date IS NOT NULL AND t.transaction_date BETWEEN DATE_SUB(o_prev.report_date, INTERVAL 1 DAY) AND DATE_SUB(?, INTERVAL 1 DAY) THEN t.quantity_sacks ELSE 0 END), 0)) AS net_range_sak,

            -- Transaksi masuk dan keluar pada H-1 (ditampilkan di tanggal H)
            COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date = ? THEN t.quantity_kg ELSE 0 END), 0) AS incoming_kg_today,
            COALESCE(SUM(CASE WHEN t.type = 'IN' AND t.transaction_date = ? THEN t.quantity_sacks ELSE 0 END), 0) AS incoming_sak_today,
            
            COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date = ? THEN t.quantity_kg ELSE 0 END), 0) AS outgoing_kg_today,
            COALESCE(SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date = ? THEN t.quantity_sacks ELSE 0 END), 0) AS outgoing_sak_today

        FROM
            products p
        LEFT JOIN (
            SELECT 
                product_id, 
                transaction_date, 
                quantity_kg, 
                quantity_sacks, 
                'IN' as type 
            FROM incoming_transactions
            WHERE status IN ('Closed', 'Pending')
            UNION ALL
            SELECT 
                product_id, 
                transaction_date, 
                quantity_kg, 
                quantity_sacks, 
                'OUT' as type 
            FROM outgoing_transactions
            WHERE status IN ('Closed', 'Pending')
        ) as t ON p.id = t.product_id
        LEFT JOIN opening_stock_overrides o_current
            ON o_current.product_id = p.id AND o_current.report_date = ?
        LEFT JOIN (
            SELECT o1.product_id, o1.report_date, o1.opening_stock_kg, o1.opening_stock_sak
            FROM opening_stock_overrides o1
            JOIN (
                SELECT product_id, MAX(report_date) AS latest_report_date
                FROM opening_stock_overrides
                WHERE report_date <= ?
                GROUP BY product_id
            ) lx ON lx.product_id = o1.product_id AND lx.latest_report_date = o1.report_date
        ) o_prev ON o_prev.product_id = p.id
        LEFT JOIN (
            -- Subquery untuk stock akhir H-1
            SELECT 
                p2.id as product_id,
                (COALESCE(SUM(CASE WHEN t2.type = 'IN' AND t2.transaction_date <= ? THEN t2.quantity_kg ELSE 0 END), 0) -
                 COALESCE(SUM(CASE WHEN t2.type = 'OUT' AND t2.transaction_date <= ? THEN t2.quantity_kg ELSE 0 END), 0))
                AS closing_stock_kg,
                (COALESCE(SUM(CASE WHEN t2.type = 'IN' AND t2.transaction_date <= ? THEN t2.quantity_sacks ELSE 0 END), 0) -
                 COALESCE(SUM(CASE WHEN t2.type = 'OUT' AND t2.transaction_date <= ? THEN t2.quantity_sacks ELSE 0 END), 0))
                AS closing_stock_sak
            FROM products p2
            LEFT JOIN (
                SELECT 
                    product_id, 
                    transaction_date, 
                    quantity_kg, 
                    quantity_sacks, 
                    'IN' as type 
                FROM incoming_transactions
                WHERE status IN ('Closed', 'Pending')
                UNION ALL
                SELECT 
                    product_id, 
                    transaction_date, 
                    quantity_kg, 
                    quantity_sacks, 
                    'OUT' as type 
                FROM outgoing_transactions
                WHERE status IN ('Closed', 'Pending')
            ) as t2 ON p2.id = t2.product_id
            GROUP BY p2.id
        ) prev_stock ON p.id = prev_stock.product_id
        GROUP BY
            p.id, p.sku, p.product_name, prev_stock.closing_stock_kg, prev_stock.closing_stock_sak, o_current.opening_stock_kg, o_current.opening_stock_sak, o_prev.report_date, o_prev.opening_stock_kg, o_prev.opening_stock_sak
        ORDER BY
            p.product_name ASC
    ";
    
    $params = [
        // net_range boundaries use H-1 (so DATE_SUB(H-1,1) = H-2)
        $previous_date,
        $previous_date,
        $previous_date,
        $previous_date,
        // incoming/outgoing use H-1
        $previous_date,
        $previous_date,
        $previous_date,
        $previous_date,
        // o_current override for H
        $filter_date,
        // o_prev latest override <= H-1
        $previous_date,
        // prev_stock through H-1
        $previous_date,
        $previous_date,
        $previous_date,
        $previous_date
    ];
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

$report_data = [];
foreach ($results as $row) {
    $productId = (int)$row['id'];

    // Hitung stok awal efektif untuk tanggal H
    // Prioritas:
    // 1) Jika ada override untuk tanggal H, gunakan itu
    // 2) Jika ada override terakhir <= H-1, propagasikan hingga H-1 dengan menambahkan net transaksi rentang (R-1 .. H-2)
    // 3) Jika tidak ada override sama sekali, gunakan stock akhir H-1 dari transaksi historis (prev_closing)
    $opening_stock_kg = 0.0;
    $opening_stock_sak = 0.0;
    if (isset($row['o_current_opening_kg']) && $row['o_current_opening_kg'] !== null) {
        $opening_stock_kg = (float)$row['o_current_opening_kg'];
        $opening_stock_sak = (float)$row['o_current_opening_sak'];
    } elseif (isset($row['o_prev_date']) && $row['o_prev_date'] !== null) {
        $opening_stock_kg = (float)$row['o_prev_opening_kg'] + (float)$row['net_range_kg'];
        $opening_stock_sak = (float)$row['o_prev_opening_sak'] + (float)$row['net_range_sak'];
    } else {
        $opening_stock_kg = (float)$row['prev_closing_stock_kg'];
        $opening_stock_sak = (float)$row['prev_closing_stock_sak'];
    }
    $incoming_kg_today = (float)$row['incoming_kg_today'];
    $incoming_sak_today = (float)$row['incoming_sak_today'];
    $outgoing_kg_today = (float)$row['outgoing_kg_today'];
    $outgoing_sak_today = (float)$row['outgoing_sak_today'];

    if ($filter_date == '2025-08-23') {
        // Gunakan stock akhir dari closingan batch
        $closing_stock_kg = (float)$row['closing_stock_kg_batch'];
        $closing_stock_sak = (float)$row['closing_stock_sak_batch'];
    } else {
        // Gunakan rumus normal: stock awal + masuk - keluar
        $closing_stock_kg = $opening_stock_kg + $incoming_kg_today - $outgoing_kg_today;
        $closing_stock_sak = $opening_stock_sak + $incoming_sak_today - $outgoing_sak_today;
    }

    if (is_numeric($filter_qty_kg) && $closing_stock_kg < (float)$filter_qty_kg) {
        continue;
    }
    if ($hide_zero_closing && $closing_stock_kg <= 0) {
        continue;
    }

    $average_qty = ($closing_stock_sak != 0) ? ($closing_stock_kg / $closing_stock_sak) : 0;

    // Kondisi khusus: LIQUID NITROGEN PAL (kode 1202700003) rata-rata = 0 selamanya
    if ($row['sku'] == '1202700003' || stripos($row['product_name'], 'LIQUID NITROGEN PAL') !== false) {
        $average_qty = 0;
    }

    $report_data[] = [
        'sku' => $row['sku'],
        'product_name' => $row['product_name'],
        'opening_stock_kg' => $opening_stock_kg,
        'opening_stock_sak' => $opening_stock_sak,
        'incoming_kg_today' => $incoming_kg_today,
        'incoming_sak_today' => $incoming_sak_today,
        'outgoing_kg_today' => $outgoing_kg_today,
        'outgoing_sak_today' => $outgoing_sak_today,
        'closing_stock_kg' => $closing_stock_kg,
        'closing_stock_sak' => $closing_stock_sak,
        'average_qty' => $average_qty,
    ];
}
?>
<div class="container-fluid">
    <div class="card">
        <div class="card-header bg-white py-3">
            <h2 class="h5 mb-3 fw-bold">Laporan Stok Harian</h2>
            
            <form action="index.php" method="GET" class="filter-form">
                <input type="hidden" name="page" value="laporan">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3"><label class="form-label fw-semibold small">Pilih Tanggal</label><input type="date" name="filter_date" class="form-control" value="<?= htmlspecialchars($filter_date) ?>"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">Stok Akhir (Kg) >=</label><input type="number" step="any" name="filter_qty_kg" class="form-control" placeholder="Contoh: 100" value="<?= htmlspecialchars($filter_qty_kg) ?>"></div>
                    <div class="col-md-3"><label class="form-label fw-semibold small">&nbsp;</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="1" id="hideZeroClosing" name="hide_zero_closing" <?= $hide_zero_closing ? 'checked' : '' ?>>
                            <label class="form-check-label" for="hideZeroClosing">
                                Sembunyikan Stok Akhir = 0
                            </label>
                        </div>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter"></i> Tampilkan</button>
                        <a href="export_laporan_harian.php?<?= http_build_query($_GET) ?>" class="btn btn-success" title="Export"><i class="bi bi-file-earmark-spreadsheet-fill"></i></a>
                        <a href="print_laporan_harian.php?<?= http_build_query($_GET) ?>" target="_blank" class="btn btn-outline-secondary" title="Cetak Laporan">
                            <i class="bi bi-printer-fill"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover laporan-table">
                    <thead>
                        <tr class="table-primary">
                            <th rowspan="2" class="text-center align-middle border-dark" style="min-width: 50px;">No</th>
                            <th rowspan="2" class="text-center align-middle border-dark" style="min-width: 120px;">Kode Barang</th>
                            <th rowspan="2" class="text-start align-middle border-dark" style="min-width: 200px;">Nama Barang</th>
                            <th colspan="2" class="text-center border-dark">Stok Awal</th>
                            <th colspan="2" class="text-center border-dark">Barang Masuk</th>
                            <th colspan="2" class="text-center border-dark">Barang Keluar</th>
                            <th colspan="2" class="text-center border-dark">Stok Akhir</th>
                            <th rowspan="2" class="text-center align-middle border-dark" style="min-width: 100px;">Rata-rata Qty</th>
                        </tr>
                        <tr class="sub-header">
                            <th class="text-center border-dark fw-bold text-white" style="min-width: 80px;">Kg</th>
                            <th class="text-center border-dark fw-bold text-white" style="min-width: 80px;">Sak</th>
                            <th class="text-center border-dark fw-bold text-white" style="min-width: 80px;">Kg</th>
                            <th class="text-center border-dark fw-bold text-white" style="min-width: 80px;">Sak</th>
                            <th class="text-center border-dark fw-bold text-white" style="min-width: 80px;">Kg</th>
                            <th class="text-center border-dark fw-bold text-white" style="min-width: 80px;">Sak</th>
                            <th class="text-center border-dark fw-bold text-white" style="min-width: 80px;">Kg</th>
                            <th class="text-center border-dark fw-bold text-white" style="min-width: 80px;">Sak</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_data)): ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted p-4">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                    Tidak ada data untuk ditampilkan.
                                </td>
                            </tr>
                        <?php else: 
                            $nomor = 1;
                            foreach ($report_data as $data): ?>
                                <tr data-closingkg="<?= (float)$data['closing_stock_kg'] ?>">
                                    <td class="text-center border fw-bold text-dark"><?= $nomor++ ?></td>
                                    <td class="text-center border fw-semibold text-dark"><?= htmlspecialchars($data['sku']) ?></td>
                                    <td class="text-start border fw-semibold text-dark"><?= htmlspecialchars($data['product_name']) ?></td>
                                    <td class="text-center align-middle border text-dark"><?= formatAngkaUI($data['opening_stock_kg']) ?></td>
                                    <td class="text-center align-middle border text-dark"><?= formatAngkaUI($data['opening_stock_sak']) ?></td>
                                    <td class="text-center align-middle border fw-bold" style="color: #2e7d32;"><?= formatAngkaUI($data['incoming_kg_today']) ?></td>
                                    <td class="text-center align-middle border fw-bold" style="color: #2e7d32;"><?= formatAngkaUI($data['incoming_sak_today']) ?></td>
                                    <td class="text-center align-middle border fw-bold" style="color: #d32f2f;"><?= formatAngkaUI($data['outgoing_kg_today']) ?></td>
                                    <td class="text-center align-middle border fw-bold" style="color: #d32f2f;"><?= formatAngkaUI($data['outgoing_sak_today']) ?></td>
                                    <td class="text-center align-middle border fw-bold" style="color: #1976d2;"><?= formatAngkaUI($data['closing_stock_kg']) ?></td>
                                    <td class="text-center align-middle border fw-bold" style="color: #1976d2;"><?= formatAngkaUI($data['closing_stock_sak']) ?></td>
                                    <td class="text-center align-middle border text-dark"><?= formatAngkaUI($data['average_qty']) ?></td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const checkbox = document.getElementById('hideZeroClosing');
    const tbody = document.querySelector('.laporan-table tbody');
    if (!checkbox || !tbody) return;
    function applyHide() {
        const hide = checkbox.checked;
        tbody.querySelectorAll('tr').forEach(tr => {
            // Skip "no data" row
            const firstCell = tr.querySelector('td');
            if (firstCell && firstCell.hasAttribute('colspan')) return;
            const val = parseFloat(tr.dataset.closingkg || '0');
            tr.style.display = (hide && val <= 0) ? 'none' : '';
        });
    }
    checkbox.addEventListener('change', applyHide);
    applyHide();
});
</script>

<style>
.laporan-table {
    border-collapse: collapse;
    width: 100%;
    font-size: 0.9rem;
}

.laporan-table th,
.laporan-table td {
    border: 2px solid #dee2e6 !important;
    padding: 8px 12px;
    vertical-align: middle;
}

.laporan-table thead th {
    border: 2px solid #495057 !important;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
}

.laporan-table .sub-header th {
    border-top: 1px solid #495057 !important;
    font-size: 0.75rem;
    padding: 6px 8px;
    color: white !important;
}

.laporan-table tbody tr:hover {
    background-color: #f8f9fa;
    transition: background-color 0.2s ease;
}

.laporan-table tbody tr:nth-child(even) {
    background-color: #fafafa;
}

.laporan-table .table-primary {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: white !important;
}

.laporan-table .table-primary th {
    color: white !important;
    border-color: #004085 !important;
}

/* Memastikan teks tetap terlihat jelas */
.laporan-table th,
.laporan-table td {
    color: #212529 !important;
}

/* Khusus untuk sub-header, pastikan tetap putih */
.laporan-table .sub-header th {
    color: white !important;
}

.laporan-table tbody tr:hover td {
    background-color: #e9ecef !important;
    color: #212529 !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .laporan-table {
        font-size: 0.8rem;
    }
    
    .laporan-table th,
    .laporan-table td {
        padding: 6px 8px;
    }
    
    .laporan-table thead th {
        font-size: 0.7rem;
    }
    
    .laporan-table .sub-header th {
        font-size: 0.65rem;
        padding: 4px 6px;
    }
}
</style>
