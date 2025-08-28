<?php
$sort_by = $_GET['sort_by'] ?? 'product_name';
$order = $_GET['order'] ?? 'ASC';

$allowed_sort = ['product_name', 'sku'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'product_name';
}

$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

$next_order = ($order === 'ASC') ? 'DESC' : 'ASC';

$message = '';
$status_type = '';
if (isset($_GET['status'])) {
    $status_messages = ['sukses_tambah' => 'Produk baru berhasil ditambahkan.', 'sukses_edit' => 'Data produk berhasil diperbarui.', 'dihapus' => 'Produk berhasil dihapus.'];
    if (array_key_exists($_GET['status'], $status_messages)) {
        $message = $status_messages[$_GET['status']];
        $status_type = $_GET['status'] == 'dihapus' ? 'warning' : 'success';
    }
}

$sql = "
    SELECT
        p.id,
        p.sku,
        p.product_name,
        p.standard_qty,
        (SELECT COALESCE(SUM(quantity_kg), 0) FROM incoming_transactions WHERE product_id = p.id) AS total_masuk_kg,
        (SELECT COALESCE(SUM(quantity_sacks), 0) FROM incoming_transactions WHERE product_id = p.id) AS total_masuk_sak,
        (SELECT COALESCE(SUM(quantity_kg), 0) FROM outgoing_transactions WHERE product_id = p.id) AS total_keluar_kg,
        (SELECT COALESCE(SUM(quantity_sacks), 0) FROM outgoing_transactions WHERE product_id = p.id) AS total_keluar_sak,
        (SELECT COALESCE(SUM(lot_number), 0) FROM incoming_transactions WHERE product_id = p.id) AS total_501_masuk,
        (SELECT COALESCE(SUM(lot_number), 0) FROM outgoing_transactions WHERE product_id = p.id) AS total_501_keluar
    FROM
        products p
    ORDER BY
        p.{$sort_by} {$order}
";
$stmt = $pdo->query($sql);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query_params = $_GET;

?>
<div class="container-fluid">
    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($status_type) ?> alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                <strong><?= $message ?></strong>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Modern Header Section -->
    <div class="modern-header mb-4">
        <div class="row align-items-center">
            <div class="col-md-8">
                <div class="header-content">
                    <h1 class="display-6 fw-bold text-gradient mb-2">
                        <i class="bi bi-box-seam me-3 icon-bounce"></i>
                        Master Produk
                    </h1>
                    <p class="lead text-muted mb-0">Kelola data produk dan pantau stok secara real-time</p>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <button class="btn btn-gradient-primary btn-lg shadow-lg pulse-button" data-bs-toggle="modal" data-bs-target="#productModal">
                    <i class="bi bi-plus-circle-fill me-2"></i>
                    <span>Tambah Produk</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card bg-gradient-primary text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="icon-circle bg-white bg-opacity-20 me-3">
                            <i class="bi bi-box-seam text-primary fs-4"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-0"><?= count($products) ?></h4>
                            <small class="opacity-75">Total Produk</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card bg-gradient-success text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="icon-circle bg-white bg-opacity-20 me-3">
                            <i class="bi bi-arrow-up-circle text-success fs-4"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-0"><?= array_sum(array_column($products, 'total_masuk_kg')) ?></h4>
                            <small class="opacity-75">Total Masuk (Kg)</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card bg-gradient-warning text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="icon-circle bg-white bg-opacity-20 me-3">
                            <i class="bi bi-arrow-down-circle text-warning fs-4"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-0"><?= array_sum(array_column($products, 'total_keluar_kg')) ?></h4>
                            <small class="opacity-75">Total Keluar (Kg)</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stats-card bg-gradient-info text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="icon-circle bg-white bg-opacity-20 me-3">
                            <i class="bi bi-graph-up text-info fs-4"></i>
                        </div>
                        <div>
                            <h4 class="fw-bold mb-0"><?= array_sum(array_map(function($p) { return $p['total_masuk_kg'] - $p['total_keluar_kg']; }, $products)) ?></h4>
                            <small class="opacity-75">Stok Akhir (Kg)</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Data Table Card -->
    <div class="modern-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="header-info">
                <h5 class="mb-1 text-white fw-bold">
                    <i class="bi bi-table me-2"></i>Data Produk
                </h5>
                <small class="text-white-50">Kelola dan pantau inventori produk Anda</small>
            </div>
            <div class="header-actions">
                <button class="btn btn-light btn-sm me-2" onclick="refreshTable()">
                    <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <!-- Search and Filter Bar -->
            <div class="filter-toolbar">
                <div class="row align-items-center">
                    <div class="col-md-6">
                        <div class="search-box">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" class="form-control search-input" id="searchTable" placeholder="Cari produk...">
                        </div>
                    </div>
                    <div class="col-md-6 text-end">
                        <div class="filter-controls">
                            <select class="form-select form-select-sm d-inline-block w-auto me-2" id="statusFilter">
                                <option value="">Semua Status</option>
                                <option value="baik">Aktif</option>
                                <option value="rendah">Stok Rendah</option>
                                <option value="kosong">Kosong</option>
                            </select>
                            <button class="btn btn-outline-secondary btn-sm" onclick="clearFilters()">
                                <i class="bi bi-x-circle me-1"></i>Reset
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-modern" id="productsTable">
                    <thead>
                        <tr>
                            <th class="sortable" data-sort="product_name">
                                <div class="th-content">
                                    <i class="bi bi-box me-2"></i>
                                    <span>Nama Barang</span>
                                    <i class="bi bi-chevron-expand sort-icon"></i>
                                </div>
                            </th>
                            <th class="sortable" data-sort="sku">
                                <div class="th-content">
                                    <i class="bi bi-upc me-2"></i>
                                    <span>SKU</span>
                                    <i class="bi bi-chevron-expand sort-icon"></i>
                                </div>
                            </th>
                            <th>
                                <div class="th-content">
                                    <i class="bi bi-rulers me-2"></i>
                                    <span>Std Qty (Kg)</span>
                                </div>
                            </th>
                            <th>
                                <div class="th-content">
                                    <i class="bi bi-calculator me-2"></i>
                                    <span>Rata-rata</span>
                                </div>
                            </th>
                            <th class="text-success">
                                <div class="th-content">
                                    <i class="bi bi-arrow-up-circle me-2"></i>
                                    <span>501 Masuk</span>
                                </div>
                            </th>
                            <th class="text-warning">
                                <div class="th-content">
                                    <i class="bi bi-arrow-down-circle me-2"></i>
                                    <span>501 Keluar</span>
                                </div>
                            </th>
                            <th class="text-info">
                                <div class="th-content">
                                    <i class="bi bi-graph-up me-2"></i>
                                    <span>Selisih 501</span>
                                </div>
                            </th>
                            <th class="text-center">
                                <div class="th-content">
                                    <i class="bi bi-gear me-2"></i>
                                    <span>Aksi</span>
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($products)): ?>
                            <tr class="no-data-row">
                                <td colspan="8" class="text-center p-5">
                                    <div class="empty-state">
                                        <i class="bi bi-inbox display-1 text-muted mb-3"></i>
                                        <h5 class="text-muted">Belum ada data produk</h5>
                                        <p class="text-muted mb-4">Tambahkan produk pertama Anda untuk mulai mengelola inventori</p>
                                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal">
                                            <i class="bi bi-plus-lg me-2"></i>Tambah Produk Pertama
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php else: 
                            $index = 0;
                            foreach ($products as $row): 
                                $index++;
                                $stok_akhir_kg = $row['total_masuk_kg'] - $row['total_keluar_kg'];
                                $stok_akhir_sak = $row['total_masuk_sak'] - $row['total_keluar_sak'];
                                $rata_rata_qty = ($stok_akhir_sak != 0) ? ($stok_akhir_kg / $stok_akhir_sak) : 0;

                                $selisih_501 = $row['total_501_masuk'] - $row['total_501_keluar'];
                                
                                $status_class = '';
                                $status_text = '';
                                if ($stok_akhir_kg <= 0) {
                                    $status_class = 'status-empty';
                                    $status_text = 'Kosong';
                                } elseif ($stok_akhir_kg < 100) {
                                    $status_class = 'status-low';
                                    $status_text = 'Rendah';
                                } else {
                                    $status_class = 'status-good';
                                    $status_text = 'Baik';
                                }
                                ?>
                                <tr class="table-row" data-status="<?= $status_text ?>">
                                    <td class="product-name-cell">
                                        <div class="product-info">
                                            <div class="product-name fw-bold"><?= htmlspecialchars($row['product_name']) ?></div>
                                            <div class="product-meta">
                                                <span class="badge status-badge <?= $status_class ?>"><?= $status_text ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <code class="sku-code"><?= htmlspecialchars($row['sku']) ?></code>
                                    </td>
                                    <td>
                                        <span class="data-value"><?= formatAngkaUI($row['standard_qty']) ?></span>
                                    </td>
                                    <td>
                                        <span class="data-value"><?= formatAngkaUI($rata_rata_qty) ?></span>
                                    </td>
                                    <td>
                                        <div class="metric-value metric-success">
                                            <i class="bi bi-arrow-up-circle me-1"></i>
                                            <span><?= formatAngkaUI($row['total_501_masuk']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="metric-value metric-warning">
                                            <i class="bi bi-arrow-down-circle me-1"></i>
                                            <span><?= formatAngkaUI($row['total_501_keluar']) ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="metric-value metric-info">
                                            <i class="bi bi-graph-up me-1"></i>
                                            <span class="fw-bold"><?= formatAngkaUI($selisih_501) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="action-buttons">
                                            <button class="btn btn-action btn-edit edit-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#productModal"
                                                data-id="<?= $row['id'] ?>"
                                                data-sku="<?= htmlspecialchars($row['sku']) ?>"
                                                data-product_name="<?= htmlspecialchars($row['product_name']) ?>"
                                                data-standard_qty="<?= htmlspecialchars($row['standard_qty']) ?>" 
                                                title="Edit Produk">
                                                <i class="bi bi-pencil-square"></i>
                                            </button>
                                            <?php
                                            $delete_params = $query_params;
                                            $delete_params['action'] = 'delete_produk';
                                            $delete_params['id'] = $row['id'];
                                            ?>
                                            <button class="btn btn-action btn-delete" 
                                                onclick="confirmDelete('<?= http_build_query($delete_params) ?>', '<?= htmlspecialchars($row['product_name']) ?>')" 
                                                title="Hapus Produk">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form action="index.php" method="POST" id="productForm">
<?php
foreach ($_GET as $key => $val) {
    if ($key !== 'status' && $key !== 'form_type' && $key !== 'product_id') {
        echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($val) . '">';
    }
}
?>
                <input type="hidden" name="form_type" value="produk">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                <input type="hidden" name="product_id" id="product_id_input">
                <div class="modal-header">
                    <h1 class="modal-title fs-5 fw-bold" id="productModalLabel"></h1>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3"><label for="product_name" class="form-label">Nama Barang</label><input type="text" class="form-control" id="product_name" name="product_name" required></div>
                    <div class="mb-3"><label for="sku" class="form-label">Kode Barang (SKU)</label><input type="text" class="form-control" id="sku" name="sku"></div>
                    <div class="mb-3"><label for="standard_qty" class="form-label">Standar Qty (Kg)</label><input type="number" step="any" class="form-control" id="standard_qty" name="standard_qty" placeholder="Contoh: 25.5"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button><button type="submit" class="btn btn-primary" id="productSubmitButton"></button></div>
            </form>
        </div>
    </div>
</div>