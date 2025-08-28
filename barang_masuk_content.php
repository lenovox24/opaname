<?php
require_once __DIR__ . '/security_bootstrap.php';
$stmt_products = $pdo->query("SELECT id, sku, product_name, standard_qty FROM products ORDER BY product_name ASC");
$products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$status_type = '';
if (isset($_GET['status'])) {
    $status_messages = ['sukses_tambah' => 'Data berhasil disimpan.', 'sukses_edit' => 'Data berhasil diperbarui.', 'dihapus' => 'Data berhasil dihapus.'];
    if (array_key_exists($_GET['status'], $status_messages)) {
        $message = $status_messages[$_GET['status']];
        $status_type = $_GET['status'] == 'dihapus' ? 'warning' : 'success';
    }
}

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$filter_date = $_GET['filter_date'] ?? '';
$filter_status = $_GET['status_filter'] ?? '';
$search_query = $_GET['s'] ?? '';
$po_query = $_GET['po'] ?? '';
$supplier_query = $_GET['sup'] ?? '';
$doc_query = $_GET['doc'] ?? '';
$batch_query = $_GET['batch'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'created_at';
$sort_order = strtoupper($_GET['sort_order'] ?? 'DESC');
$allowed_sort = ['created_at','product_name'];
if (!in_array($sort_by, $allowed_sort)) { $sort_by = 'created_at'; }
if (!in_array($sort_order, ['ASC','DESC'])) { $sort_order = 'DESC'; }

$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int)$_GET['limit'] : 25;
$page_num = isset($_GET['page_num']) && is_numeric($_GET['page_num']) ? (int)$_GET['page_num'] : 1;
$offset = ($page_num - 1) * $limit;

$sql_base = "FROM incoming_transactions t JOIN products p ON t.product_id = p.id WHERE 1=1";
$params = [];

if (!empty($start_date) && !empty($end_date)) {
    $sql_base .= " AND t.transaction_date BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
} 
if (!empty($filter_status)) {
    $sql_base .= " AND t.status = :status_filter";
    $params[':status_filter'] = $filter_status;
}
if (!empty($search_query)) {
    $sql_base .= " AND (p.product_name LIKE :search_name OR p.sku LIKE :search_sku)";
    $params[':search_name'] = '%' . $search_query . '%';
    $params[':search_sku'] = '%' . $search_query . '%';
}
if (!empty($po_query)) {
    $sql_base .= " AND t.po_number LIKE :po_number";
    $params[':po_number'] = '%' . $po_query . '%';
}
if (!empty($supplier_query)) {
    $sql_base .= " AND t.supplier LIKE :supplier_query";
    $params[':supplier_query'] = '%' . $supplier_query . '%';
}
if (!empty($doc_query)) {
    $sql_base .= " AND t.document_number LIKE :document_number";
    $params[':document_number'] = '%' . $doc_query . '%';
}
if (!empty($batch_query)) {
    $sql_base .= " AND t.batch_number LIKE :batch_number";
    $params[':batch_number'] = '%' . $batch_query . '%';
}

$sql_count = "SELECT COUNT(t.id) " . $sql_base;
$stmt_count = $pdo->prepare($sql_count);
$stmt_count->execute($params);
$total_rows = $stmt_count->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$order_sql = $sort_by === 'product_name' ? "p.product_name $sort_order, t.created_at DESC" : "t.created_at $sort_order";
$sql_transactions = "SELECT t.*, p.product_name, p.sku " . $sql_base . " ORDER BY $order_sql LIMIT :limit OFFSET :offset";
$stmt_transactions = $pdo->prepare($sql_transactions);

foreach ($params as $key => $val) {
    $stmt_transactions->bindValue($key, $val);
}

$stmt_transactions->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt_transactions->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_transactions->execute();
$transactions = $stmt_transactions->fetchAll(PDO::FETCH_ASSOC);

$query_params = $_GET;
?>
<div class="container-fluid">
    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($status_type) ?> alert-dismissible fade show fade-in" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>
            <strong><?= $message ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-gradient-primary text-white"">
            <div class=" d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center">
                <div class="icon-circle bg-gradient bg-opacity-20 me-2">
                    <i class="bi bi-box-arrow-in-down"></i>
                </div>
                <div>
                    <h2 class="h5 mb-0 fw-bold text-white">Daftar Barang Masuk</h2>
                    <small class="text-white-50 d-none d-md-block">
                        <?= !empty($filter_date) ? 'Tanggal: ' . date('d F Y', strtotime($filter_date)) : 'Semua Tanggal' ?>
                    </small>
                </div>
            </div>
            <button type="button" class="btn btn-warning btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#incomingTransactionModal">
                <i class="bi bi-plus-circle-fill me-1"></i>Tambah
            </button>
        </div>

        <!-- Compact Filter Form -->
        <form action="index.php" method="GET" class="filter-form">
            <input type="hidden" name="page" value="barang_masuk">
            <div class="row g-2">
                <div class="col-6 col-md-3">
                    <label class="form-label text-white fw-semibold small">Rentang Tanggal</label>
                    <div class="input-group input-group-sm">
                        <input type="date" name="start_date" class="form-control form-control-sm bg-white border-0 shadow-sm" value="<?= htmlspecialchars($start_date) ?>" placeholder="Dari">
                        <span class="input-group-text">s/d</span>
                        <input type="date" name="end_date" class="form-control form-control-sm bg-white border-0 shadow-sm" value="<?= htmlspecialchars($end_date) ?>" placeholder="Sampai">
                    </div>
                    <small class="text-white-50 d-block">Kosongkan untuk semua data</small>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label text-white fw-semibold small">Status</label>
                    <select name="status_filter" class="form-select form-select-sm bg-white border-0 shadow-sm">
                        <option value="">Semua</option>
                        <option value="Pending" <?= ($filter_status ?? '') == 'Pending' ? 'selected' : '' ?>>🟡 Pending</option>
                        <option value="Closed" <?= ($filter_status ?? '') == 'Closed' ? 'selected' : '' ?>>🟢 Closed</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label text-white fw-semibold small">Nama/Kode</label>
                    <input type="text" name="s" class="form-control form-control-sm bg-white border-0 shadow-sm" placeholder="Cari..." value="<?= htmlspecialchars($search_query ?? '') ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label text-white fw-semibold small">No. PO</label>
                    <input type="text" name="po" class="form-control form-control-sm bg-white border-0 shadow-sm" placeholder="Cari..." value="<?= htmlspecialchars($po_query ?? '') ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label text-white fw-semibold small">Supplier</label>
                    <input type="text" name="sup" class="form-control form-control-sm bg-white border-0 shadow-sm" placeholder="Cari..." value="<?= htmlspecialchars($supplier_query ?? '') ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label text-white fw-semibold small">Dokumen</label>
                    <input type="text" name="doc" class="form-control form-control-sm bg-white border-0 shadow-sm" placeholder="Cari..." value="<?= htmlspecialchars($doc_query ?? '') ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label text-white fw-semibold small">Batch</label>
                    <input type="text" name="batch" class="form-control form-control-sm bg-white border-0 shadow-sm" placeholder="Cari..." value="<?= htmlspecialchars($batch_query) ?>">
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-12">
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="submit" class="btn btn-success btn-sm fw-semibold shadow-sm">
                            <i class="bi bi-funnel-fill me-1"></i>Filter
                        </button>
                        <a href="export_csv.php?<?= http_build_query($_GET) ?>" class="btn btn-info btn-sm fw-semibold shadow-sm">
                            <i class="bi bi-download me-1"></i>Export
                        </a>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle mb-0">
                <thead class="table-dark sticky-top">
                    <tr>
                        <th class="text-nowrap fw-bold">Tgl. Transaksi</th>
                        <th class="text-nowrap fw-bold">Nomor PO</th>
                        <th class="text-start text-nowrap fw-bold">Supplier</th>
                        <th class="text-nowrap fw-bold">No. Polisi</th>
                        <?php
                            $link_params = $query_params;
                            $is_current = ($sort_by === 'product_name');
                            $next_order = ($is_current && $sort_order === 'ASC') ? 'DESC' : 'ASC';
                            $link_params['sort_by'] = 'product_name';
                            $link_params['sort_order'] = $next_order;
                        ?>
                        <th class="text-start text-nowrap fw-bold">
                            <a href="?<?= http_build_query($link_params) ?>" class="text-white text-decoration-none">
                                Nama Barang
                                <?php if ($is_current): ?>
                                    <i class="bi bi-chevron-<?= strtolower($sort_order) === 'asc' ? 'up' : 'down' ?> ms-1"></i>
                                <?php else: ?>
                                    <i class="bi bi-chevron-expand ms-1"></i>
                                <?php endif; ?>
                            </a>
                        </th>
                        <th class="text-nowrap fw-bold">Kode</th>
                        <th class="text-nowrap fw-bold">BATCH</th>
                        <th class="text-nowrap fw-bold">QTY (KG)</th>
                        <th class="text-nowrap fw-bold">QTY (SAK)</th>
                        <th class="text-nowrap fw-bold">501 (LOT)</th>
                        <th class="text-nowrap fw-bold">No. Dokumen</th>
                        <th class="text-nowrap fw-bold">Status</th>
                        <th class="text-center text-nowrap fw-bold">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="13" class="text-center text-muted p-5">
                                <div class="empty-state">
                                    <i class="bi bi-inbox display-1 text-muted opacity-50"></i>
                                    <h5 class="mt-3 text-muted">Belum Ada Data Transaksi</h5>
                                    <p class="text-muted">Mulai tambahkan transaksi barang masuk untuk melihat data di sini</p>
                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#incomingTransactionModal">
                                        <i class="bi bi-plus-circle me-1"></i>Tambah Transaksi Pertama
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php else: foreach ($transactions as $tx): ?>
                            <tr class="transaction-row">
                                <td class="text-nowrap">
                                    <span class="badge bg-light text-dark border">
                                        <?= date('d/m/Y', strtotime($tx['transaction_date'])) ?>
                                    </span>
                                </td>
                                <!-- Nomor PO -->
                                <td class="text-nowrap">
                                    <span class="text-primary fw-semibold" title="<?= htmlspecialchars($tx['po_number']) ?>">
                                        <?= htmlspecialchars($tx['po_number']) ?>
                                    </span>
                                </td>
                                <!-- Supplier -->
                                <td class="text-start text-truncate" style="max-width: 140px;">
                                    <span class="fw-semibold" title="<?= htmlspecialchars($tx['supplier']) ?>">
                                        <?= htmlspecialchars($tx['supplier']) ?>
                                    </span>
                                </td>
                                <!-- No. Polisi -->
                                <td class="text-nowrap fw-bold fs-6">
                                    <code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($tx['license_plate']) ?></code>
                                </td>
                                <!-- Nama Barang -->
                                <td class="text-start">
                                    <div class="product-info">
                                        <div class="fw-semibold text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($tx['product_name']) ?>">
                                            <?= htmlspecialchars($tx['product_name']) ?>
                                        </div>
                                    </div>
                                </td>
                                <!-- Kode (SKU) -->
                                <td class="text-nowrap">
                                    <code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($tx['sku']) ?></code>
                                </td>
                                <td class="text-nowrap">
                                    <span class="badge bg-info text-white" title="<?= htmlspecialchars($tx['batch_number']) ?>">
                                        <?= htmlspecialchars($tx['batch_number']) ?>
                                    </span>
                                </td>
                                <td class="text-nowrap">
                                    <span class="badge bg-primary fs-6"><?= formatAngkaUI($tx['quantity_kg']) ?> kg</span>
                                </td>
                                <td class="text-nowrap">
                                    <span class="badge bg-secondary fs-6"><?= formatAngkaUI($tx['quantity_sacks']) ?> sak</span>
                                </td>
                                <td class="text-nowrap">
                                    <span class="badge bg-warning text-dark fs-6"><?= formatAngkaUI($tx['lot_number']) ?></span>
                                </td>
                                <td class="text-truncate" style="max-width: 100px;">
                                    <span class="text-primary fw-semibold" title="<?= htmlspecialchars($tx['document_number']) ?>">
                                        <?= htmlspecialchars($tx['document_number']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($tx['status'] == 'Closed'): ?>
                                        <span class="badge bg-success rounded-pill px-3">
                                            <i class="bi bi-check-circle me-1"></i>Closed
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark rounded-pill px-3">
                                            <i class="bi bi-clock me-1"></i>Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button type="button" class="btn btn-outline-warning edit-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#incomingTransactionModal"
                                            data-id="<?= htmlspecialchars($tx['id']) ?>"
                                            data-po-number="<?= htmlspecialchars($tx['po_number']) ?>"
                                            data-document-number="<?= htmlspecialchars($tx['document_number']) ?>"
                                            title="Edit Item Spesifik">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <?php
                                        $delete_params = $query_params;
                                        $delete_params['action'] = 'delete_incoming';
                                        $delete_params['id'] = $tx['id'];
                                        ?>
                                        <a href="index.php?<?= http_build_query($delete_params) ?>"
                                            class="btn btn-outline-danger delete-incoming-btn"
                                            data-delete-url="<?= htmlspecialchars('index.php?' . http_build_query($delete_params)) ?>"
                                            title="Hapus Item">
                                            <i class="bi bi-trash3-fill"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="d-flex justify-content-between align-items-center p-3 border-top">
            <form action="index.php" method="GET" class="d-flex align-items-center gap-2">
                <input type="hidden" name="page" value="barang_masuk">
                <?php
                foreach ($query_params as $key => $value) {
                    if ($key != 'limit' && $key != 'page_num') {
                        echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($value) . '">';
                    }
                }
                ?>
                <label for="limit" class="form-label small text-nowrap mb-0">Baris:</label>
                <select name="limit" id="limit" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                    <option value="25" <?= ($limit == 25 ? 'selected' : '') ?>>25</option>
                    <option value="50" <?= ($limit == 50 ? 'selected' : '') ?>>50</option>
                    <option value="100" <?= ($limit == 100 ? 'selected' : '') ?>>100</option>
                </select>
            </form>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination pagination-sm mb-0">
                        <?php
                        unset($query_params['page_num']);
                        $prev_page = $page_num - 1;
                        $link_params = $query_params;
                        $link_params['page_num'] = $prev_page;
                        echo '<li class="page-item ' . ($page_num <= 1 ? 'disabled' : '') . '"><a class="page-link" href="?' . http_build_query($link_params) . '">‹</a></li>';

                        $start = max(1, $page_num - 2);
                        $end = min($total_pages, $page_num + 2);

                        for ($i = $start; $i <= $end; $i++) {
                            $link_params['page_num'] = $i;
                            $active_class = ($i == $page_num) ? 'active' : '';
                            echo '<li class="page-item ' . $active_class . '"><a class="page-link" href="?' . http_build_query($link_params) . '">' . $i . '</a></li>';
                        }

                        $next_page = $page_num + 1;
                        $link_params['page_num'] = $next_page;
                        echo '<li class="page-item ' . ($page_num >= $total_pages ? 'disabled' : '') . '"><a class="page-link" href="?' . http_build_query($link_params) . '">›</a></li>';
                        ?>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<!-- Enhanced Modal for Incoming Transaction -->
<div class="modal fade" id="incomingTransactionModal" tabindex="-1" aria-labelledby="incomingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered" style="overflow:visible;">
        <div class="modal-content border-0 shadow-lg" style="overflow:visible;">
            <form action="index.php" method="POST" id="incomingTransactionForm">
<?php
foreach ($_GET as $key => $val) {
    if ($key !== 'status' && $key !== 'form_type' && $key !== 'transaction_id') {
        echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars($val) . '">';
    }
}
?>
                <input type="hidden" name="form_type" value="barang_masuk">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                <input type="hidden" name="transaction_id" id="incoming_transaction_id">
                <input type="hidden" name="items_json" id="incoming_items_json">
                <input type="hidden" name="original_po_number" id="original_po_number">

                <div class="modal-header bg-gradient-warning text-dark border-1">
                    <div class="d-flex align-items-center">
                        <div class="icon-circle bg-white bg-opacity-20 me-3">
                            <i class="bi bi-plus-circle-fill fs-4"></i>
                        </div>
                        <div>
                            <h1 class="modal-title fs-5 fw-bold mb-0" id="incomingModalLabel">Tambah Transaksi Barang Masuk</h1>
                            <small class="opacity-75">Formulir input transaksi penerimaan barang</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body" style="max-height: 70vh; overflow-y: auto; overflow-x: visible;">
                    <!-- Header Info -->
                    <div class="card bg-light border-0 mb-4">
                        <div class="card-body" style="position:relative;">
                            <h6 class="card-title fw-bold text-primary mb-3">
                                <i class="bi bi-info-circle me-2"></i>Informasi Transaksi
                            </h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-calendar3 me-1 text-primary"></i>Tanggal Transaksi
                                    </label>
                                    <input type="date" class="form-control border-0 shadow-sm" id="incoming_transaction_date" name="transaction_date" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-receipt me-1 text-primary"></i>Nomor PO
                                    </label>
                                    <input type="text" class="form-control border-0 shadow-sm" id="incoming_po_number" name="po_number" placeholder="Masukkan nomor PO">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-flag me-1 text-primary"></i>Status Transaksi
                                    </label>
                                    <select class="form-select border-0 shadow-sm" id="incoming_status" name="status" required>
                                        <option value="Pending">🟡 Pending</option>
                                        <option value="Closed" selected>🟢 Closed</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-building me-1 text-primary"></i>Supplier
                                    </label>
                                    <input type="text" class="form-control border-0 shadow-sm" id="incoming_supplier" name="supplier" placeholder="Nama supplier">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-factory me-1 text-primary"></i>Produsen
                                    </label>
                                    <input type="text" class="form-control border-0 shadow-sm" id="incoming_produsen" name="produsen" placeholder="Nama produsen">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-truck me-1 text-primary"></i>No. Polisi
                                    </label>
                                    <input type="text" class="form-control border-0 shadow-sm" id="incoming_license_plate" name="license_plate" placeholder="Nomor polisi kendaraan">
                                </div>
                            </div>
                        </div>
                    </div>

                                         <!-- Document Information -->
                     <div class="card border-info mb-4">
                         <div class="card-header bg-info text-white">
                             <h6 class="card-title fw-bold mb-0">
                                 <i class="bi bi-file-text me-2"></i>Informasi Dokumen
                             </h6>
                         </div>
                         <div class="card-body" style="position:relative;">
                             <div class="row g-3">
                                 <div class="col-md-12">
                                     <label class="form-label fw-semibold">
                                         <i class="bi bi-file-text me-1 text-primary"></i>No. Dokumen
                                     </label>
                                     <input type="text" class="form-control border-0 shadow-sm" id="incoming_document_number" name="document_number" placeholder="Nomor dokumen" required>
                                 </div>
                             </div>
                         </div>
                     </div>

                     <!-- Add Item Form -->
                     <div class="card border-primary mb-4">
                         <div class="card-header bg-primary text-white">
                             <h6 class="card-title fw-bold mb-0">
                                 <i class="bi bi-plus-circle me-2"></i>Tambah Item Barang
                             </h6>
                         </div>
                         <div class="card-body" style="position:relative;">
                             <div class="row g-3">
                                 <div class="row g-3 align-items-end">
                                    <div class="col-md-3 position-relative">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-search me-1 text-primary"></i>Nama Barang
                                        </label>
                                        <input class="form-control border-0 shadow-sm" id="item_product_name_incoming" placeholder="🔍 Ketik untuk mencari produk..." autocomplete="off">
                                        <datalist id="datalistProductsIncoming">
                                            <?php foreach ($products as $p): ?>
                                                <option value="<?= htmlspecialchars($p['product_name']) ?>" label="<?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['sku']) ?>)" data-id="<?= $p['id'] ?>" data-sku="<?= htmlspecialchars($p['sku']) ?>" data-stdqty="<?= htmlspecialchars($p['standard_qty']) ?>">
                                            <?php endforeach; ?>
                                        </datalist>
                                        <input type="hidden" id="item_product_id_hidden">
                                        <small id="item_sku_display_incoming" class="text-muted d-block mt-1"></small>
                                        
                                    </div>
                                    <div class="col-md-2">
                                    <label class="form-label fw-semibold">
                                    <i class="bi bi-tag me-1 text-primary"></i>Batch Number
                                    </label>
                                    <input type="text" class="form-control border-0 shadow-sm" id="item_batch_number" placeholder="Batch number">
                                    <small class="form-text text-muted">Batch boleh sama</small>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-weight me-1 text-primary"></i>Qty (Kg)
                                        </label>
                                        <div class="input-group shadow-sm">
                                            <div class="input-group-text bg-light border-0">
                                                <input class="form-check-input mt-0" type="checkbox" id="incoming_calc_kg_check" title="Auto-hitung dari Qty Sak">
                                            </div>
                                            <input type="text" inputmode="decimal" class="form-control border-0" id="item_quantity_kg" placeholder="0.00">
                                        </div>
                                        <small class="form-text text-muted">Centang untuk auto-hitung</small>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-bag me-1 text-primary"></i>Qty (Sak)
                                        </label>
                                        <div class="input-group shadow-sm">
                                            <div class="input-group-text bg-light border-0">
                                                <input class="form-check-input mt-0" type="checkbox" id="incoming_calc_sak_check" title="Auto-hitung dari Qty Kg">
                                            </div>
                                            <input type="text" inputmode="decimal" class="form-control border-0" id="item_quantity_sacks" placeholder="0">
                                        </div>
                                        <small class="form-text text-muted">Centang untuk auto-hitung</small>
                                    </div>
                                     <div class="col-md-1">
                                         <button type="button" class="btn btn-success w-100 fw-semibold shadow-sm" id="addItemBtn" style="margin-top: 32px;">
                                             <i class="bi bi-plus-lg me-2"></i>Tambah
                                         </button>
                                     </div>
                                </div>
                             </div>
                         </div>
                     </div>

                    <!-- Dedicated 501 Input Section -->
                    <div class="card border-warning mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h6 class="card-title fw-bold mb-0">
                                <i class="bi bi-calculator me-2"></i>Input 501 (Lot)
                            </h6>
                        </div>
                        <div class="card-body" style="position:relative;">
                             <div class="row g-3 align-items-end">
                                <div class="col-md-4 position-relative">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-search me-1 text-primary"></i>Nama Barang (501)
                                    </label>
                                    <input class="form-control border-0 shadow-sm" id="item501_product_name" placeholder="🔍 Ketik untuk mencari produk..." autocomplete="off">
                                    <input type="hidden" id="item501_product_id_hidden">
                                    <small id="item501_sku_display" class="text-muted d-block mt-1"></small>
                                    
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-tag me-1 text-primary"></i>Batch Number
                                    </label>
                                    <input type="text" class="form-control border-0 shadow-sm" id="item501_batch_number" placeholder="Batch number">
                                    <small class="form-text text-muted">Default otomatis dari tanggal</small>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-calculator me-1 text-success"></i>Jumlah 501 (Kg)
                                    </label>
                                    <input type="number" step="any" class="form-control border-0 shadow-sm" id="item501_qty" placeholder="0.00">
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-warning w-100 fw-semibold shadow-sm" id="addItem501Btn" style="margin-top: 32px;">
                                        <i class="bi bi-plus-lg me-2"></i>Tambah 501
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Items List -->
                    <div class="card border-success">
                        <div class="card-header bg-success text-white">
                            <h6 class="card-title fw-bold mb-0">
                                <i class="bi bi-list-ul me-2"></i>Daftar Barang yang Akan Dimasukkan
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 5%;" class="fw-bold">#</th>
                                            <th class="text-start fw-bold">Nama Barang</th>
                                            <th class="fw-bold">Batch</th>
                                            <th class="fw-bold">Qty (Kg)</th>
                                            <th class="fw-bold">Qty (Sak)</th>
                                            <th class="fw-bold">501 (Lot)</th>
                                            <th style="width: 10%;" class="text-center fw-bold">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody id="incoming_items_list">
                                        <tr>
                                            <td colspan="7" class="text-center text-muted p-4">
                                                <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                                                <span>Belum ada item yang ditambahkan</span>
                                                <br>
                                                <small>Gunakan form di atas untuk menambah item</small>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- 501 Items List -->
                    <div class="card border-warning">
                        <div class="card-header bg-warning text-white">
                            <h6 class="card-title fw-bold mb-0">
                                <i class="bi bi-list-ul me-2"></i>Daftar 501 (Lot) yang Akan Dimasukkan
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 5%;" class="fw-bold">#</th>
                                            <th class="text-start fw-bold">Nama Barang</th>
                                            <th class="fw-bold">Batch</th>
                                            <th class="fw-bold">501 (Kg)</th>
                                            <th style="width: 10%;" class="text-center fw-bold">Aksi</th>
                                        </tr>
                                    </thead>
                                    <tbody id="incoming_items_501_list">
                                        <tr>
                                            <td colspan="5" class="text-center text-muted p-4">
                                                <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                                                <span>Belum ada 501 yang ditambahkan</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary fw-semibold" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle me-1"></i>Batal
                    </button>
                    <button type="submit" class="btn btn-primary fw-semibold shadow-sm" id="saveTransactionBtn">
                        <i class="bi bi-save-fill me-1"></i>Simpan Transaksi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    function submitFilterForm() {
        const form = document.querySelector('form[action="index.php"]');
        if (form) form.submit();
    }
    let incomingItems = [];
    let incomingItems501 = [];
    let itemCounter = 1;
    let isEditMode = false;
    let originalPoNumber = '';
    let originalDocumentNumber = '';

    const itemProductNameInput = document.getElementById('item_product_name_incoming');
    const itemProductIdHidden = document.getElementById('item_product_id_hidden');
    const datalistProductsIncoming = document.getElementById('datalistProductsIncoming');

    function updateItemsJSON() {
        const itemsJsonInput = document.getElementById('incoming_items_json');
        if (itemsJsonInput) {
            itemsJsonInput.value = JSON.stringify([ ...incomingItems, ...incomingItems501 ]);
        }
    }

    const itemSkuDisplayIncoming = document.getElementById('item_sku_display_incoming');

    if (itemProductNameInput && datalistProductsIncoming) {
        itemProductNameInput.addEventListener('input', function() {
            const selectedOption = Array.from(datalistProductsIncoming.options).find(option => option.value === this.value);
            if (selectedOption) {
                itemProductIdHidden.value = selectedOption.dataset.id || '';
                if (itemSkuDisplayIncoming) itemSkuDisplayIncoming.textContent = `Kode: ${selectedOption.dataset.sku || ''}`;
            } else {
                itemProductIdHidden.value = '';
                if (itemSkuDisplayIncoming) itemSkuDisplayIncoming.textContent = '';
            }
        });
    }

    const addItemBtn = document.getElementById('addItemBtn');
    const itemsList = document.getElementById('incoming_items_list');

    if (addItemBtn) {
        addItemBtn.addEventListener('click', function() {
            const productName = document.getElementById('item_product_name_incoming').value;
            const productId = document.getElementById('item_product_id_hidden').value;
            const batchNumber = document.getElementById('item_batch_number').value;
            const quantityKg = Number.parseFloat((document.getElementById('item_quantity_kg').value || '').toString().replace(',', '.')) || 0;
            const quantitySacks = Number.parseFloat((document.getElementById('item_quantity_sacks').value || '').toString().replace(',', '.')) || 0;

            if (!productName || !productId) {
                alert('Pilih nama barang terlebih dahulu!');
                return;
            }
            if (!batchNumber) {
                alert('Masukkan batch number!');
                return;
            }
            if (quantityKg <= 0 && quantitySacks <= 0) {
                alert('Masukkan quantity yang valid!');
                return;
            }

            const skuOpt = document.querySelector(`option[value="${productName}"]`);
            const sku = skuOpt ? skuOpt.dataset.sku : '';

            const newItem = {
                no: itemCounter++,
                product_id: productId,
                product_name: productName,
                sku: sku,
                batch_number: batchNumber,
                quantity_kg: quantityKg,
                quantity_kg_display: (document.getElementById('item_quantity_kg').value || '').toString(),
                quantity_sacks: quantitySacks,
                quantity_sacks_display: (document.getElementById('item_quantity_sacks').value || '').toString(),
                grossweight_kg: 0,
                lot_number: 0
            };

            incomingItems.push(newItem);
            updateItemsJSON();
            updateItemsTable();

            document.getElementById('item_product_name_incoming').value = '';
            document.getElementById('item_product_id_hidden').value = '';
            document.getElementById('item_quantity_kg').value = '';
            document.getElementById('item_quantity_sacks').value = '';
            document.getElementById('item_batch_number').value = '';
            fillBatchNumber();
        });
    }

    function updateItemsTable() {
        if (incomingItems.length === 0) {
            itemsList.innerHTML = `
                <tr>
                    <td colspan="7" class="text-center text-muted p-4">
                        <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                        <span>Belum ada item yang ditambahkan</span>
                        <br>
                        <small>Gunakan form di atas untuk menambah item</small>
                    </td>
                </tr>
            `;
        } else {
            const frag = document.createDocumentFragment();
            incomingItems.forEach(function(item, idx) {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                        <td class="text-center">${idx + 1}</td>
                        <td>${item.product_name}</td>
                        <td>${item.batch_number}</td>
                        <td>${item.quantity_kg_display ? item.quantity_kg_display + 'kg' : (item.quantity_kg ? String(item.quantity_kg).replace('.', ',') + 'kg' : '')}</td>
                        <td>${item.quantity_sacks_display ? item.quantity_sacks_display + 'sak' : (item.quantity_sacks ? String(item.quantity_sacks).replace('.', ',') + 'sak' : '')}</td>
                        <td class="fw-bold text-success">${item.lot_number ? Number(item.lot_number).toLocaleString('id-ID') + ' kg' : '0 kg'}</td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem(${idx})">
                                <i class="bi bi-trash"></i>
                            </button>
                        </td>
                `;
                frag.appendChild(tr);
            });
            itemsList.innerHTML = '';
            itemsList.appendChild(frag);
        }
    }

    window.removeItem = function(index) {
        incomingItems.splice(index, 1);
        updateItemsJSON();
        updateItemsTable();
    };

    // 501 Input Handling
    const item501NameInput = document.getElementById('item501_product_name');
    const item501IdHidden = document.getElementById('item501_product_id_hidden');
    const item501SkuDisplay = document.getElementById('item501_sku_display');
    const item501AutoList = document.getElementById('incoming501AutocompleteList');
    const item501BatchInput = document.getElementById('item501_batch_number');
    const item501QtyInput = document.getElementById('item501_qty');
    const addItem501Btn = document.getElementById('addItem501Btn');
    const items501List = document.getElementById('incoming_items_501_list');

    function buildIncoming501Suggestions(term) {
        if (!item501AutoList) return;
        const datalist = document.getElementById('datalistProductsIncoming');
        const query = (term || '').toLowerCase();
        item501AutoList.innerHTML = '';
        if (!query || !datalist) {
            item501AutoList.style.display = 'none';
            return;
        }
        let count = 0;
        Array.from(datalist.options).forEach((opt) => {
            const name = (opt.value || '').toLowerCase();
            const sku = (opt.dataset.sku || '').toLowerCase();
            if (name.includes(query) || sku.includes(query)) {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
                item.innerHTML = `<span>${opt.value}</span><code class="small">${opt.dataset.sku || ''}</code>`;
                item.addEventListener('click', () => {
                    item501NameInput.value = opt.value;
                    item501IdHidden.value = opt.dataset.id || '';
                    if (item501SkuDisplay) item501SkuDisplay.textContent = `Kode: ${opt.dataset.sku || ''}`;
                    item501AutoList.style.display = 'none';
                });
                item501AutoList.appendChild(item);
                count++;
            }
        });
        item501AutoList.style.display = count > 0 ? 'block' : 'none';
    }

    if (item501NameInput) {
        item501NameInput.addEventListener('input', () => {
            const datalist = document.getElementById('datalistProductsIncoming');
            const selected = Array.from(datalist?.options || []).find(
                (opt) => opt.value === item501NameInput.value
            );
            if (selected) {
                item501IdHidden.value = selected.dataset.id || '';
                if (item501SkuDisplay) item501SkuDisplay.textContent = `Kode: ${selected.dataset.sku || ''}`;
            } else {
                item501IdHidden.value = '';
                if (item501SkuDisplay) item501SkuDisplay.textContent = '';
            }
            buildIncoming501Suggestions(item501NameInput.value);
        });
        document.addEventListener('click', (e) => {
            if (!item501AutoList) return;
            if (!item501AutoList.contains(e.target) && e.target !== item501NameInput) {
                item501AutoList.style.display = 'none';
            }
        });
    }

    function updateItems501Table() {
        if (incomingItems501.length === 0) {
            items501List.innerHTML = `
                <tr>
                    <td colspan="5" class="text-center text-muted p-4">
                        <i class="bi bi-inbox display-6 d-block mb-2 opacity-50"></i>
                        <span>Belum ada 501 yang ditambahkan</span>
                    </td>
                </tr>
            `;
            return;
        }
        const frag = document.createDocumentFragment();
        incomingItems501.forEach((item, idx) => {
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="text-center">${idx + 1}</td>
                <td>${item.product_name}</td>
                <td>${item.batch_number}</td>
                <td class="fw-bold text-success">${Number(item.lot_number).toLocaleString('id-ID')} kg</td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeItem501(${idx})">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            `;
            frag.appendChild(tr);
        });
        items501List.innerHTML = '';
        items501List.appendChild(frag);
    }

    window.removeItem501 = function(index) {
        incomingItems501.splice(index, 1);
        updateItemsJSON();
        updateItems501Table();
    }

    function generateBatchNumber(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${year}${month}${day}10`;
    }

    function fillBatchNumber() {
        const transactionDateInput = document.getElementById('incoming_transaction_date');
        const batchNumberInput = document.getElementById('item_batch_number');
        const currentDate = transactionDateInput.value || new Date().toISOString().split('T')[0];
        const generatedBatch = generateBatchNumber(currentDate);
        if (generatedBatch) {
            batchNumberInput.value = generatedBatch;
        }
    }

    function fillBatchNumber501() {
        const transactionDateInput = document.getElementById('incoming_transaction_date');
        const currentDate = transactionDateInput.value || new Date().toISOString().split('T')[0];
        const generatedBatch = generateBatchNumber(currentDate);
        if (generatedBatch && item501BatchInput) {
            item501BatchInput.value = generatedBatch;
        }
    }

    if (addItem501Btn) {
        addItem501Btn.addEventListener('click', function() {
            const productName = item501NameInput.value;
            const productId = item501IdHidden.value;
            const batchNumber = item501BatchInput.value;
            const qty501 = parseFloat(item501QtyInput.value) || 0;

            if (!productName || !productId) {
                alert('Pilih nama barang 501 terlebih dahulu!');
                return;
            }
            if (!batchNumber) {
                alert('Masukkan batch number 501!');
                return;
            }
            if (qty501 <= 0) {
                alert('Masukkan jumlah 501 (Kg) yang valid!');
                return;
            }

            const skuOpt = document.querySelector(`option[value="${productName}"]`);
            const sku = skuOpt ? skuOpt.dataset.sku : '';

            const newItem501 = {
                no: incomingItems501.length + 1,
                product_id: productId,
                product_name: productName,
                sku: sku,
                batch_number: batchNumber,
                quantity_kg: 0,
                quantity_sacks: 0,
                grossweight_kg: 0,
                lot_number: qty501
            };
            incomingItems501.push(newItem501);
            updateItemsJSON();
            updateItems501Table();

            item501NameInput.value = '';
            item501IdHidden.value = '';
            item501QtyInput.value = '';
            item501BatchInput.value = '';
            fillBatchNumber501();
        });
    }

    const editButtons = document.querySelectorAll('.edit-btn');
    editButtons.forEach(button => {
        button.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            const poNumber = this.getAttribute('data-po-number');
            const documentNumber = this.getAttribute('data-document-number');
            if (id || poNumber || documentNumber) {
                loadTransactionData(poNumber, documentNumber, id);
            }
        });
    });

    function loadTransactionData(poNumber, documentNumber, id) {
        let url = 'api_get_incoming_details.php?';
        if (documentNumber && id) {
            url += 'document_number=' + encodeURIComponent(documentNumber) + '&anchor_id=' + encodeURIComponent(id);
        } else if (id) {
            url += 'id=' + encodeURIComponent(id);
        } else if (poNumber) {
            url += 'po_number=' + encodeURIComponent(poNumber);
        } else if (documentNumber) {
            url += 'document_number=' + encodeURIComponent(documentNumber);
        }
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('Error: ' + data.error);
                    return;
                }
                isEditMode = true;
                originalPoNumber = data.transaction_info.po_number;
                originalDocumentNumber = data.transaction_info.document_number;
                document.getElementById('original_po_number').value = data.transaction_info.po_number;
                document.getElementById('incoming_transaction_date').value = data.transaction_info.transaction_date;
                document.getElementById('incoming_po_number').value = data.transaction_info.po_number;
                document.getElementById('incoming_status').value = data.transaction_info.status;
                document.getElementById('incoming_supplier').value = data.transaction_info.supplier || '';
                document.getElementById('incoming_produsen').value = data.transaction_info.produsen || '';
                document.getElementById('incoming_license_plate').value = data.transaction_info.license_plate || '';
                document.getElementById('incoming_document_number').value = data.transaction_info.document_number || '';
                const txIdHidden = document.getElementById('incoming_transaction_id');
                if (txIdHidden) {
                    txIdHidden.value = id || (data.items && data.items.length > 0 ? data.items[0].id : '');
                }
                incomingItems = data.items.map(item => ({
                    id: item.id,
                    product_id: item.product_id,
                    product_name: item.product_name,
                    batch_number: item.batch_number,
                    quantity_kg: item.quantity_kg,
                    quantity_sacks: item.quantity_sacks,
                    lot_number: typeof item.lot_number !== 'undefined' ? item.lot_number : 0,
                    grossweight_kg: typeof item.grossweight_kg !== 'undefined' ? item.grossweight_kg : 0
                }));
                incomingItems501 = [];
                updateItemsJSON();
                updateItemsTable();
                document.getElementById('incomingModalLabel').textContent = 'Edit Item Barang Masuk';
                const generatedBatch = generateBatchNumber(data.transaction_info.transaction_date);
                if (generatedBatch) {
                    document.getElementById('item_batch_number').value = generatedBatch;
                    fillBatchNumber501();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Terjadi kesalahan saat memuat data');
            });
    }

    const modal = document.getElementById('incomingTransactionModal');
    if (modal) {
        modal.addEventListener('hidden.bs.modal', function() {
            resetForm();
        });
        modal.addEventListener('shown.bs.modal', function() {
            const transactionDateInput = document.getElementById('incoming_transaction_date');
            if (!transactionDateInput.value) {
                transactionDateInput.value = new Date().toISOString().split('T')[0];
            }
            fillBatchNumber();
            fillBatchNumber501();
        });
    }

    function resetForm() {
        isEditMode = false;
        originalPoNumber = '';
        originalDocumentNumber = '';
        incomingItems = [];
        incomingItems501 = [];
        document.getElementById('incomingTransactionForm').reset();
        updateItemsJSON();
        updateItemsTable();
        updateItems501Table();
        document.getElementById('incomingModalLabel').textContent = 'Tambah Transaksi Barang Masuk';
        setTimeout(() => {
            const transactionDateInput = document.getElementById('incoming_transaction_date');
            if (transactionDateInput && !transactionDateInput.value) {
                transactionDateInput.value = new Date().toISOString().split('T')[0];
            }
            fillBatchNumber();
            fillBatchNumber501();
        }, 100);
    }

    const form = document.getElementById('incomingTransactionForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (incomingItems.length === 0 && incomingItems501.length === 0) {
                e.preventDefault();
                alert('Tambahkan minimal satu item (barang atau 501) sebelum menyimpan!');
                return;
            }
            updateItemsJSON();
        });
    }

    const transactionDateInput = document.getElementById('incoming_transaction_date');
    const batchNumberInput = document.getElementById('item_batch_number');

    function generateBatchNumberForExisting(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${year}${month}${day}10`;
    }

    function fillBatchNumberExisting() {
        const currentDate = transactionDateInput.value || new Date().toISOString().split('T')[0];
        const generatedBatch = generateBatchNumberForExisting(currentDate);
        if (generatedBatch) {
            batchNumberInput.value = generatedBatch;
        }
    }

    if (transactionDateInput && batchNumberInput) {
        const modal = document.getElementById('incomingTransactionModal');
        if (modal) {
            modal.addEventListener('shown.bs.modal', function() {
                if (!transactionDateInput.value) {
                    transactionDateInput.value = new Date().toISOString().split('T')[0];
                }
                fillBatchNumberExisting();
                fillBatchNumber501();
            });
        }
        transactionDateInput.addEventListener('change', function() {
            fillBatchNumberExisting();
            fillBatchNumber501();
        });
    }

    const itemCalcKgCheck = document.getElementById('incoming_calc_kg_check');
const itemCalcSakCheck = document.getElementById('incoming_calc_sak_check');
const itemQuantityKg = document.getElementById('item_quantity_kg');
const itemQuantitySacks = document.getElementById('item_quantity_sacks');

// Normalize input display: convert commas to dots while still parsing as decimal
if (itemQuantityKg) {
    itemQuantityKg.addEventListener('input', function() {
        if (this.value && this.value.indexOf(',') !== -1) {
            this.value = this.value.replace(/,/g, '.');
        }
    });
}
if (itemQuantitySacks) {
    itemQuantitySacks.addEventListener('input', function() {
        if (this.value && this.value.indexOf(',') !== -1) {
            this.value = this.value.replace(/,/g, '.');
        }
    });
}

if (itemCalcKgCheck && itemCalcSakCheck && itemQuantityKg && itemQuantitySacks) {
        itemCalcKgCheck.addEventListener('change', function() {
            if (this.checked) {
                itemCalcSakCheck.checked = false;
                itemQuantityKg.value = '';
                itemQuantityKg.readOnly = true;
                itemQuantityKg.classList.add('bg-light');
                itemQuantitySacks.readOnly = false;
                itemQuantitySacks.classList.remove('bg-light');
            } else {
                itemQuantityKg.readOnly = false;
                itemQuantityKg.classList.remove('bg-light');
            }
        });

        itemCalcSakCheck.addEventListener('change', function() {
            if (this.checked) {
                itemCalcKgCheck.checked = false;
                itemQuantitySacks.value = '';
                itemQuantitySacks.readOnly = true;
                itemQuantitySacks.classList.add('bg-light');
                itemQuantityKg.readOnly = false;
                itemQuantityKg.classList.remove('bg-light');
            } else {
                itemQuantitySacks.readOnly = false;
                itemQuantitySacks.classList.remove('bg-light');
            }
        });

        itemQuantityKg.addEventListener('input', function() {
            if (itemCalcSakCheck.checked && this.value) {
                const selectedOption = Array.from(datalistProductsIncoming.options).find(option => 
                    option.value === itemProductNameInput.value
                );
                if (selectedOption && selectedOption.dataset.stdqty) {
                    const standardQty = parseFloat(selectedOption.dataset.stdqty);
                    if (standardQty > 0) {
                        itemQuantitySacks.value = (Number.parseFloat((this.value || '').toString().replace(',', '.')) / standardQty).toString();
                    }
                }
            }
        });

        itemQuantitySacks.addEventListener('input', function() {
            if (itemCalcKgCheck.checked && this.value) {
                const selectedOption = Array.from(datalistProductsIncoming.options).find(option => 
                    option.value === itemProductNameInput.value
                );
                if (selectedOption && selectedOption.dataset.stdqty) {
                    const standardQty = parseFloat(selectedOption.dataset.stdqty);
                    if (standardQty > 0) {
                        itemQuantityKg.value = (Number.parseFloat((this.value || '').toString().replace(',', '.')) * standardQty).toString();
                    }
                }
            }
        });
    }
});
</script>