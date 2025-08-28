<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
include __DIR__ . '/koneksi.php';

// Ensure overrides table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS opening_stock_overrides (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_date DATE NOT NULL,
    product_id INT NOT NULL,
    opening_stock_kg DECIMAL(18,6) NOT NULL DEFAULT 0,
    opening_stock_sak DECIMAL(18,6) NOT NULL DEFAULT 0,
    note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by VARCHAR(100) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_report_product (report_date, product_id),
    KEY idx_report_date (report_date),
    KEY idx_product_id (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$today = date('Y-m-d');
// Izinkan pilih tanggal; default hari ini
$report_date = $_POST['report_date'] ?? $_GET['report_date'] ?? $today;
$closed_only = false; // Selalu gabungkan Closed + Pending
$action = $_POST['action'] ?? '';
// Cutoff perhitungan sisa batch = tanggal yang dipilih (transaksi s.d. H)
$cutoff_date = $report_date;
// Tidak dipakai lagi; semua status dihitung (Closed+Pending)
$current_user = $_SESSION['username'] ?? ($_SESSION['user'] ?? null);

$preview_rows = [];
$apply_result = null;
$clear_result = null;
$error_msg = null;

try {
    if ($action === 'preview' || $action === 'apply') {
        // Build preview dataset using per-batch remaining formula (align with sisa batch logic)
        $statusFilterIn  = "t_in.status IN ('Closed','Pending')";
        $statusFilterOut = "status IN ('Closed','Pending')";

        $sqlPreview = "
            SELECT
                p.id AS product_id,
                p.sku,
                p.product_name,
                COALESCE(SUM(t_in.quantity_kg   - COALESCE(t_out.total_out_kg, 0)), 0)   AS closing_stock_kg,
                COALESCE(SUM(t_in.quantity_sacks - COALESCE(t_out.total_out_sacks, 0)), 0) AS closing_stock_sak
            FROM products p
            LEFT JOIN incoming_transactions t_in
                ON t_in.product_id = p.id
               AND t_in.transaction_date <= :c1
               AND $statusFilterIn
            LEFT JOIN (
                SELECT incoming_transaction_id,
                       SUM(quantity_kg)   AS total_out_kg,
                       SUM(quantity_sacks) AS total_out_sacks
                FROM outgoing_transactions
                WHERE transaction_date <= :c2 AND $statusFilterOut
                GROUP BY incoming_transaction_id
            ) t_out ON t_out.incoming_transaction_id = t_in.id
            GROUP BY p.id, p.sku, p.product_name
            ORDER BY p.product_name ASC
        ";
        $stmt = $pdo->prepare($sqlPreview);
        $stmt->execute([':c1' => $cutoff_date, ':c2' => $cutoff_date]);
        $preview_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($action === 'apply') {
            // Apply overrides via INSERT ... SELECT with upsert
            $note = 'Opening reset to batch closing as of ' . $cutoff_date . ' [Closed+Pending]';
            $sqlApply = "
                INSERT INTO opening_stock_overrides (report_date, product_id, opening_stock_kg, opening_stock_sak, note, created_by)
                SELECT :report_date, p.id,
                       COALESCE(SUM(t_in.quantity_kg   - COALESCE(t_out.total_out_kg, 0)), 0)   AS closing_stock_kg,
                       COALESCE(SUM(t_in.quantity_sacks - COALESCE(t_out.total_out_sacks, 0)), 0) AS closing_stock_sak,
                       :note, :created_by
                FROM products p
                LEFT JOIN incoming_transactions t_in
                    ON t_in.product_id = p.id
                   AND t_in.transaction_date <= :c1
                   AND $statusFilterIn
                LEFT JOIN (
                    SELECT incoming_transaction_id,
                           SUM(quantity_kg)   AS total_out_kg,
                           SUM(quantity_sacks) AS total_out_sacks
                    FROM outgoing_transactions
                    WHERE transaction_date <= :c2 AND $statusFilterOut
                    GROUP BY incoming_transaction_id
                ) t_out ON t_out.incoming_transaction_id = t_in.id
                GROUP BY p.id
                ON DUPLICATE KEY UPDATE
                    opening_stock_kg = VALUES(opening_stock_kg),
                    opening_stock_sak = VALUES(opening_stock_sak),
                    note = VALUES(note),
                    created_at = CURRENT_TIMESTAMP,
                    created_by = VALUES(created_by)
            ";
            $stmtApply = $pdo->prepare($sqlApply);
            $stmtApply->execute([
                ':report_date' => $report_date,
                ':c1' => $cutoff_date,
                ':c2' => $cutoff_date,
                ':note' => $note,
                ':created_by' => $current_user,
            ]);
            $apply_result = [
                'updated' => count($preview_rows),
                'message' => 'Opening stock overrides saved for ' . date('d/m/Y', strtotime($report_date))
            ];
        }
    } elseif ($action === 'clear') {
        // Remove overrides for a date
        $stmtDel = $pdo->prepare("DELETE FROM opening_stock_overrides WHERE report_date = ?");
        $stmtDel->execute([$report_date]);
        $clear_result = ['deleted' => $stmtDel->rowCount()];
    }
} catch (Throwable $e) {
    $error_msg = $e->getMessage();
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Stock Awal Laporan Harian</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0">Reset Stock Awal Laporan Harian</h5>
            <small class="text-muted">Setel Stock Awal (tanggal H) = Stock Akhir batch (s.d. H-1)</small>
        </div>
        <div class="card-body">
            <?php if ($error_msg): ?>
                <div class="alert alert-danger"><?php echo h($error_msg); ?></div>
            <?php endif; ?>

            <?php if ($apply_result): ?>
                <div class="alert alert-success">Berhasil menyimpan override untuk tanggal <?php echo h(date('d/m/Y', strtotime($report_date))); ?>. Total produk: <?php echo h($apply_result['updated']); ?>.</div>
            <?php endif; ?>

            <?php if ($clear_result): ?>
                <div class="alert alert-warning">Override dihapus untuk tanggal <?php echo h(date('d/m/Y', strtotime($report_date))); ?>. Baris terhapus: <?php echo h($clear_result['deleted']); ?>.</div>
            <?php endif; ?>

            <form method="post" class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Tanggal Laporan (H)</label>
                    <input type="date" name="report_date" class="form-control" value="<?php echo h($report_date); ?>" required>
                </div>
                <!-- Status filter dihapus: selalu menghitung Closed + Pending -->
                <div class="col-md-8 d-flex align-items-end gap-2">
                    <button type="submit" name="action" value="preview" class="btn btn-outline-primary">Preview</button>
                    <button type="submit" name="action" value="apply" class="btn btn-primary" onclick="return confirm('Terapkan override untuk semua item pada tanggal ini?');">Terapkan Override</button>
                    <button type="submit" name="action" value="clear" class="btn btn-outline-danger" onclick="return confirm('Hapus semua override pada tanggal ini?');">Hapus Override</button>
                </div>
            </form>

            <div class="mb-3 small text-muted">
                <div>Cutoff perhitungan: <strong><?php echo h(date('d/m/Y', strtotime($cutoff_date))); ?></strong> (s.d. tanggal dipilih)</div>
                <div>Sumber data: transaksi s.d. tanggal dipilih (Closed+Pending)</div>
            </div>

            <?php if (!empty($preview_rows)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center" style="width:60px;">No</th>
                                <th class="text-center" style="width:140px;">Kode</th>
                                <th>Nama Barang</th>
                                <th class="text-end" style="width:140px;">Opening (Sak)</th>
                                <th class="text-end" style="width:140px;">Opening (Kg)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no=1; foreach ($preview_rows as $r): ?>
                                <tr>
                                    <td class="text-center"><?php echo $no++; ?></td>
                                    <td class="text-center"><?php echo h($r['sku']); ?></td>
                                    <td><?php echo h($r['product_name']); ?></td>
                                    <td class="text-end"><?php echo formatAngkaUI($r['closing_stock_sak'] ?? 0); ?></td>
                                    <td class="text-end"><?php echo formatAngkaUI($r['closing_stock_kg'] ?? 0); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($action === 'preview'): ?>
                <div class="alert alert-info">Tidak ada data untuk ditampilkan.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="text-muted small mt-3">Catatan: Fitur ini menyimpan "stok awal override" untuk tanggal H, sehingga halaman laporan harian akan menggunakan nilai tersebut sebagai stok awal di tanggal tersebut.</div>
</div>
</body>
</html>


