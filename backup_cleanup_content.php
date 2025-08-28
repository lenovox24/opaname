<?php
require_once __DIR__ . '/security_bootstrap.php';

$message = '';
$status_type = '';

// Handle status messages
if (isset($_GET['status'])) {
    $status_messages = [
        'backup_success' => 'Data batch habis berhasil di-backup ke file.',
        'delete_success' => 'Batch yang dipilih berhasil dihapus.',
        'error_backup' => 'Gagal membuat backup. Silakan coba lagi.',
        'error_delete' => 'Gagal menghapus batch. Silakan coba lagi.',
        'no_selection' => 'Tidak ada batch yang dipilih untuk dihapus.',
        'backup_required' => 'Backup diperlukan sebelum menghapus data.'
    ];
    
    if (array_key_exists($_GET['status'], $status_messages)) {
        $message = $status_messages[$_GET['status']];
        $status_type = in_array($_GET['status'], ['backup_success', 'delete_success']) ? 'success' : 'danger';
    }
}

// Query untuk mencari batch dengan stock habis (0)
// Menghitung remaining stock = incoming - outgoing
$sql_empty_batches = "
    SELECT 
        i.id,
        i.batch_number,
        i.product_id,
        p.product_name,
        p.sku,
        i.supplier,
        i.transaction_date,
        i.quantity_kg as original_qty_kg,
        i.quantity_sacks as original_qty_sacks,
        COALESCE(SUM(o.quantity_kg), 0) as used_qty_kg,
        COALESCE(SUM(o.quantity_sacks), 0) as used_qty_sacks,
        (i.quantity_kg - COALESCE(SUM(o.quantity_kg), 0)) as remaining_qty_kg,
        (i.quantity_sacks - COALESCE(SUM(o.quantity_sacks), 0)) as remaining_qty_sacks,
        i.status,
        i.document_number,
        i.created_at
    FROM incoming_transactions i
    JOIN products p ON i.product_id = p.id
    LEFT JOIN outgoing_transactions o ON i.id = o.incoming_transaction_id
    GROUP BY i.id, i.batch_number, i.product_id, p.product_name, p.sku, i.supplier, 
             i.transaction_date, i.quantity_kg, i.quantity_sacks, i.status, 
             i.document_number, i.created_at
    HAVING remaining_qty_kg <= 0 AND remaining_qty_sacks <= 0
    ORDER BY i.transaction_date DESC, i.created_at DESC
";

try {
    $stmt_empty = $pdo->prepare($sql_empty_batches);
    $stmt_empty->execute();
    $empty_batches = $stmt_empty->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $empty_batches = [];
    $message = "Error mengambil data: " . $e->getMessage();
    $status_type = 'danger';
}
?>

<div class="container-fluid">
    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($status_type) ?> alert-dismissible fade show shadow-sm" role="alert">
            <div class="d-flex align-items-center">
                <i class="bi bi-<?= $status_type == 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-3 fs-4"></i>
                <div class="flex-grow-1"><?= $message ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow-lg border-0 overflow-hidden">
        <div class="card-header bg-gradient-danger text-white">
            <div class="d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <div class="icon-circle bg-white bg-opacity-20 me-3">
                        <i class="bi bi-archive"></i>
                    </div>
                    <div>
                        <h2 class="h5 mb-0 fw-bold">Backup & Cleanup Batch Habis</h2>
                        <small class="opacity-75 d-none d-md-block">Kelola batch dengan stock habis (0 Kg & 0 Sak)</small>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <?php if (!empty($empty_batches)): ?>
                        <button type="button" class="btn btn-warning btn-sm fw-semibold" onclick="backupAllBatches()">
                            <i class="bi bi-download me-1"></i>Backup Semua
                        </button>
                        <button type="button" class="btn btn-outline-light btn-sm fw-semibold" onclick="selectAllBatches()">
                            <i class="bi bi-check2-all me-1"></i>Pilih Semua
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-body">
            <?php if (empty($empty_batches)): ?>
                <div class="text-center p-5">
                    <div class="empty-state">
                        <i class="bi bi-emoji-smile display-1 text-success opacity-50"></i>
                        <h5 class="mt-3 text-muted">Tidak Ada Batch Habis</h5>
                        <p class="text-muted">Semua batch masih memiliki stock tersisa. Tidak ada yang perlu di-cleanup.</p>
                        <a href="index.php?page=beranda" class="btn btn-primary btn-sm">
                            <i class="bi bi-house me-1"></i>Kembali ke Beranda
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-warning d-flex align-items-center mb-4">
                    <i class="bi bi-exclamation-triangle-fill me-3 fs-4"></i>
                    <div>
                        <strong>Perhatian!</strong> Ditemukan <strong><?= count($empty_batches) ?></strong> batch dengan stock habis (0).
                        <br><small>Pastikan untuk backup data sebelum menghapus batch. Data yang dihapus tidak dapat dikembalikan.</small>
                    </div>
                </div>

                <form id="cleanupForm" action="index.php" method="POST">
                    <input type="hidden" name="page" value="backup_cleanup">
                    <input type="hidden" name="action" value="delete_selected">
                    
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle mb-0">
                            <thead class="table-dark sticky-top">
                                <tr>
                                    <th class="text-center">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="selectAll">
                                            <label class="form-check-label" for="selectAll"></label>
                                        </div>
                                    </th>
                                    <th class="text-nowrap fw-bold">Tanggal</th>
                                    <th class="text-start text-nowrap fw-bold">Nama Barang</th>
                                    <th class="text-nowrap fw-bold">Kode</th>
                                    <th class="text-nowrap fw-bold">Batch</th>
                                    <th class="text-nowrap fw-bold">Supplier</th>
                                    <th class="text-nowrap fw-bold">Original (Kg)</th>
                                    <th class="text-nowrap fw-bold">Digunakan (Kg)</th>
                                    <th class="text-nowrap fw-bold">Sisa (Kg)</th>
                                    <th class="text-nowrap fw-bold">Status</th>
                                    <th class="text-center text-nowrap fw-bold">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($empty_batches as $batch): ?>
                                    <tr>
                                        <td class="text-center">
                                            <div class="form-check">
                                                <input class="form-check-input batch-checkbox" type="checkbox" 
                                                       name="selected_batches[]" value="<?= $batch['id'] ?>" 
                                                       id="batch_<?= $batch['id'] ?>">
                                                <label class="form-check-label" for="batch_<?= $batch['id'] ?>"></label>
                                            </div>
                                        </td>
                                        <td class="text-nowrap">
                                            <span class="badge bg-light text-dark border">
                                                <?= date('d/m/Y', strtotime($batch['transaction_date'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-start">
                                            <div class="fw-semibold text-truncate" style="max-width: 200px;" 
                                                 title="<?= htmlspecialchars($batch['product_name']) ?>">
                                                <?= htmlspecialchars($batch['product_name']) ?>
                                            </div>
                                        </td>
                                        <td class="text-nowrap">
                                            <code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($batch['sku']) ?></code>
                                        </td>
                                        <td class="text-nowrap">
                                            <span class="badge bg-info text-white"><?= htmlspecialchars($batch['batch_number']) ?></span>
                                        </td>
                                        <td class="text-truncate" style="max-width: 180px;" 
                                            title="<?= htmlspecialchars($batch['supplier']) ?>">
                                            <?= htmlspecialchars($batch['supplier']) ?>
                                        </td>
                                        <td class="text-nowrap">
                                            <span class="badge bg-primary fs-6"><?= formatAngkaUI($batch['original_qty_kg']) ?></span>
                                        </td>
                                        <td class="text-nowrap">
                                            <span class="badge bg-secondary fs-6"><?= formatAngkaUI($batch['used_qty_kg']) ?></span>
                                        </td>
                                        <td class="text-nowrap">
                                            <span class="badge bg-danger fs-6"><?= formatAngkaUI($batch['remaining_qty_kg']) ?></span>
                                        </td>
                                        <td>
                                            <?php if ($batch['status'] === 'Closed'): ?>
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
                                                <button type="button" class="btn btn-outline-primary" 
                                                        onclick="backupSingleBatch(<?= $batch['id'] ?>)" 
                                                        title="Backup Batch Ini">
                                                    <i class="bi bi-download"></i>
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="deleteSingleBatch(<?= $batch['id'] ?>)" 
                                                        title="Hapus Batch Ini">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex justify-content-between align-items-center p-3 bg-light border-top">
                        <div class="d-flex align-items-center gap-3">
                            <span class="text-muted small">
                                <i class="bi bi-info-circle me-1"></i>
                                Total <?= count($empty_batches) ?> batch habis ditemukan
                            </span>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-warning btn-sm" onclick="backupSelected()">
                                <i class="bi bi-download me-1"></i>Backup Terpilih
                            </button>
                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirmDelete()">
                                <i class="bi bi-trash3 me-1"></i>Hapus Terpilih
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Select all functionality
document.getElementById('selectAll')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.batch-checkbox');
    checkboxes.forEach(cb => cb.checked = this.checked);
});

// Select all batches function
function selectAllBatches() {
    const checkboxes = document.querySelectorAll('.batch-checkbox');
    checkboxes.forEach(cb => cb.checked = true);
    document.getElementById('selectAll').checked = true;
}

// Backup all batches
function backupAllBatches() {
    Swal.fire({
        title: 'Memproses Backup...',
        text: 'Mengumpulkan data batch habis',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Gunakan endpoint backup yang telah diperbaiki
    setTimeout(() => {
        Swal.close();
        window.open('export_batch_backup_v2.php?type=all&format=json', '_blank');
    }, 500);
}

// Backup selected batches
function backupSelected() {
    const selected = Array.from(document.querySelectorAll('.batch-checkbox:checked')).map(cb => cb.value);
    if (selected.length === 0) {
        Swal.fire('Peringatan', 'Pilih batch yang akan di-backup terlebih dahulu.', 'warning');
        return;
    }
    
    Swal.fire({
        title: 'Memproses Backup...',
        text: `Mengumpulkan data ${selected.length} batch terpilih`,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    setTimeout(() => {
        Swal.close();
        window.open('export_batch_backup_v2.php?type=selected&ids=' + selected.join(',') + '&format=json', '_blank');
    }, 1000);
}

// Backup single batch
function backupSingleBatch(id) {
    Swal.fire({
        title: 'Memproses Backup...',
        text: 'Mengumpulkan data batch',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    setTimeout(() => {
        Swal.close();
        window.open('export_batch_backup_v2.php?type=single&id=' + id + '&format=json', '_blank');
    }, 1000);
}

// Delete single batch with confirmation
function deleteSingleBatch(id) {
    Swal.fire({
        title: 'Konfirmasi Hapus',
        text: 'Yakin ingin menghapus batch ini? Data yang dihapus tidak dapat dikembalikan!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Ya, Hapus!',
        cancelButtonText: 'Batal'
    }).then((result) => {
        if (result.isConfirmed) {
            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php';
            
            const pageInput = document.createElement('input');
            pageInput.type = 'hidden';
            pageInput.name = 'page';
            pageInput.value = 'backup_cleanup';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_single';
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'batch_id';
            idInput.value = id;
            
            form.appendChild(pageInput);
            form.appendChild(actionInput);
            form.appendChild(idInput);
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Confirm delete selected
function confirmDelete() {
    const selected = document.querySelectorAll('.batch-checkbox:checked');
    if (selected.length === 0) {
        Swal.fire('Peringatan', 'Pilih batch yang akan dihapus terlebih dahulu.', 'warning');
        return false;
    }
    
    return new Promise((resolve) => {
        Swal.fire({
            title: 'Konfirmasi Hapus',
            text: `Yakin ingin menghapus ${selected.length} batch yang dipilih? Data yang dihapus tidak dapat dikembalikan!`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Hapus Semua!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('cleanupForm').submit();
            }
            resolve(result.isConfirmed);
        });
    });
}
</script>