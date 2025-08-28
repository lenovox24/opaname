<?php
require_once __DIR__ . '/security_bootstrap.php';
// Error reporting untuk debugging (hapus di production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include database connection dengan error handling
try {
    if (!file_exists('koneksi.php')) {
        throw new Exception('File koneksi.php tidak ditemukan');
    }
    require_once 'koneksi.php';
    
    // Pastikan koneksi database tersedia
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Koneksi database tidak tersedia');
    }
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}

// Include database connection
require_once 'koneksi.php';

// Logika untuk menampilkan pesan status
$message = '';
$status_type = '';
if (isset($_GET['status'])) {
    $status_messages = [
        'sukses_edit' => 'Data unloading berhasil diperbarui.',
        'dihapus' => 'Data unloading berhasil dihapus.',
        'gagal' => 'Gagal menyimpan data unloading.',
        'export_sukses' => 'Data berhasil di-export ke LibreOffice Calc.'
    ];
    if (array_key_exists($_GET['status'], $status_messages)) {
        $message = $status_messages[$_GET['status']];
        $status_type = ($_GET['status'] == 'dihapus') ? 'warning' : 'success';
        if ($_GET['status'] == 'gagal') {
            $status_type = 'danger';
        }
    }
}

// Filter untuk mingguan (Senin-Sabtu)
// Gunakan minggu yang dipilih user, atau minggu saat ini sebagai default
if (!isset($_GET['week']) || empty($_GET['week'])) {
    $current_week = date('Y-W'); // Default ke minggu saat ini
} else {
    $current_week = $_GET['week']; // Gunakan pilihan user
}

$week_parts = explode('-', $current_week);
$year = $week_parts[0];
$week = $week_parts[1];

// Hitung tanggal Senin dan Sabtu
$monday = new DateTime();
$monday->setISODate($year, $week, 1); // Senin
$saturday = new DateTime();
$saturday->setISODate($year, $week, 6); // Sabtu

$monday_str = $monday->format('Y-m-d');
$saturday_str = $saturday->format('Y-m-d');

// Ambil daftar produk untuk filter item tertentu
$all_products = [];
try {
    $stmtProducts = $pdo->query("SELECT id, product_name, sku FROM products ORDER BY product_name ASC");
    $all_products = $stmtProducts->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_products = [];
}

// Baca pilihan item (produk) yang ingin ditampilkan
$selected_product_ids = isset($_GET['product_ids']) ? (array)$_GET['product_ids'] : [];
$selected_product_ids = array_values(array_filter($selected_product_ids, function($v){ return ctype_digit((string)$v); }));

// Simpan parameter query untuk digunakan di link hapus
$query_params = $_GET;

// *** HANYA MENAMPILKAN DATA BARANG MASUK (INCOMING) - BUKAN BARANG KELUAR ***
// Query untuk data unloading mingguan HANYA dari incoming_transactions (barang masuk)
try {
    $sql_incoming = "
        SELECT 
            i.id,
            i.transaction_date as tanggal,
            DATE_FORMAT(i.transaction_date, '%d/%m/%Y') as tanggal_formatted,
            COALESCE(i.supplier, 'N/A') as supplier,
            COALESCE(p.product_name, 'Produk Tidak Diketahui') as nama_barang,
            COALESCE(i.license_plate, 'N/A') as nomor_mobil,
            COALESCE(i.quantity_sacks, 0) as qty_sak,
            COALESCE(i.quantity_kg, 0) as qty_kg,
            i.po_number,
            i.batch_number,
            i.status,
            i.created_at
        FROM incoming_transactions i
        LEFT JOIN products p ON i.product_id = p.id
        WHERE i.transaction_date BETWEEN ? AND ?
        ORDER BY i.transaction_date ASC, i.created_at ASC
    ";
    
    $stmt_incoming = $pdo->prepare($sql_incoming);
    $stmt_incoming->execute([$monday_str, $saturday_str]);
    $incoming_records = $stmt_incoming->fetchAll(PDO::FETCH_ASSOC);
    
    // Pastikan kolom nama_supir tersedia di tabel unloading_records
    try {
        $colCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unloading_records' AND COLUMN_NAME = 'nama_supir'");
        $colCheck->execute();
        $hasNamaSupir = (int)$colCheck->fetchColumn() > 0;
        if (!$hasNamaSupir) {
            $pdo->exec("ALTER TABLE unloading_records ADD COLUMN nama_supir VARCHAR(255) NULL AFTER nomor_mobil");
        }
    } catch (PDOException $e) {
        // Abaikan jika tidak bisa alter; fitur input tetap jalan tanpa kolom
    }

    // Sinkronkan dengan tabel unloading_records
    foreach ($incoming_records as $record) {
        // Cek apakah sudah ada di unloading_records
        $check_sql = "SELECT id FROM unloading_records WHERE incoming_transaction_id = ?";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute([$record['id']]);
        
        if ($check_stmt->rowCount() == 0) {
            // Insert ke unloading_records jika belum ada
            $insert_sql = "
                INSERT INTO unloading_records 
                (incoming_transaction_id, tanggal, supplier, nama_barang, nomor_mobil, qty_sak, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ";
            $insert_stmt = $pdo->prepare($insert_sql);
            $insert_stmt->execute([
                $record['id'],
                $record['tanggal'],
                $record['supplier'],
                $record['nama_barang'],
                $record['nomor_mobil'],
                $record['qty_sak']
            ]);
        }
    }
    
    // *** PASTIKAN HANYA DATA BARANG MASUK YANG DITAMPILKAN ***
    // Ambil data dari unloading_records untuk minggu ini (HANYA yang berasal dari incoming_transactions)
    $sql_unloading = "
        SELECT u.*, 
               i.po_number,
               i.product_id,
               DATE_FORMAT(u.tanggal, '%d/%m/%Y') as tanggal_formatted,
               TIME_FORMAT(u.jam_masuk, '%H:%i') as jam_masuk_formatted,
               TIME_FORMAT(u.jam_start_qc, '%H:%i') as jam_start_qc_formatted,
               TIME_FORMAT(u.jam_finish_qc, '%H:%i') as jam_finish_qc_formatted,
               TIME_FORMAT(u.jam_start_bongkar, '%H:%i') as jam_start_bongkar_formatted,
               TIME_FORMAT(u.jam_finish_bongkar, '%H:%i') as jam_finish_bongkar_formatted,
               TIME_FORMAT(u.jam_keluar, '%H:%i') as jam_keluar_formatted
        FROM unloading_records u 
        LEFT JOIN incoming_transactions i ON u.incoming_transaction_id = i.id
        WHERE u.tanggal BETWEEN ? AND ?
        AND u.incoming_transaction_id IS NOT NULL";

    $params_unloading = [$monday_str, $saturday_str];

    // Jika user memilih item tertentu, filter berdasarkan product_id
    if (!empty($selected_product_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_product_ids), '?'));
        $sql_unloading .= " AND i.product_id IN (" . $placeholders . ")";
        $params_unloading = array_merge($params_unloading, $selected_product_ids);
    }

    $sql_unloading .= " ORDER BY u.tanggal ASC, u.jam_masuk ASC";
    
    $stmt_unloading = $pdo->prepare($sql_unloading);
    $stmt_unloading->execute($params_unloading);
    $unloading_records = $stmt_unloading->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($unloading_records)) {
        $message = "Tidak ada data barang masuk untuk minggu $week tahun $year (periode: $monday_str sampai $saturday_str).";
        $status_type = 'info';
    }
    
} catch (PDOException $e) {
    $unloading_records = [];
    $message = 'Error mengambil data: ' . $e->getMessage();
    $status_type = 'danger';
}

// Generate week options untuk dropdown (setahun penuh)
$week_options = [];
try {
    $current_year = date('Y');
    $current_week = date('Y-W');
    
    // Generate untuk tahun sekarang dan tahun sebelumnya
    for ($year = $current_year - 1; $year <= $current_year + 1; $year++) {
        // Cari minggu pertama dan terakhir dalam tahun
        $first_week = 1;
        $last_week = date('W', mktime(0, 0, 0, 12, 28, $year)); // 28 Desember selalu di minggu terakhir
        
        for ($week_num = $first_week; $week_num <= $last_week; $week_num++) {
            $week_key = sprintf('%04d-%02d', $year, $week_num);
            
            // Hitung tanggal Senin dan Sabtu untuk minggu ini
            $monday = new DateTime();
            $monday->setISODate($year, $week_num, 1); // Senin
            $saturday = new DateTime();
            $saturday->setISODate($year, $week_num, 6); // Sabtu
            
            // Format label dengan informasi lebih lengkap
            $month_start = $monday->format('M');
            $month_end = $saturday->format('M');
            $year_display = $year;
            
            if ($month_start == $month_end) {
                $week_options[$week_key] = sprintf(
                    "Minggu %02d - %s %d (%s - %s)",
                    $week_num,
                    $month_start,
                    $year_display,
                    $monday->format('d/m'),
                    $saturday->format('d/m')
                );
            } else {
                $week_options[$week_key] = sprintf(
                    "Minggu %02d - %d (%s - %s)",
                    $week_num,
                    $year_display,
                    $monday->format('d/m/Y'),
                    $saturday->format('d/m/Y')
                );
            }
        }
    }
    
    // Urutkan berdasarkan key (tahun-minggu) secara descending (terbaru dulu)
    krsort($week_options);
    
} catch (Exception $e) {
    // Fallback jika ada error dengan date manipulation
    $week_options[date('Y-W')] = 'Minggu Ini - ' . date('d/m/Y');
}
?>

<div class="container-fluid">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-lg-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-gradient-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="card-title mb-0">
                                <i class="bi bi-truck me-2"></i>Catatan Bongkaran Unloading
                            </h4>
                            <small class="opacity-75">Periode: <?= $monday->format('d/m/Y') ?> - <?= $saturday->format('d/m/Y') ?></small>
                        </div>
                        <div class="btn-group">
                            <a href="#" id="exportLink" class="btn btn-success btn-sm" onclick="exportUnloadingData(); return false;">
                                <i class="bi bi-file-earmark-spreadsheet me-1"></i>Export ke Calc
                            </a>
                            <a href="debug_export_unloading.php" class="btn btn-outline-info btn-sm" target="_blank" title="Debug Export">
                                <i class="bi bi-bug"></i>
                            </a>
                        </div>
                        <script>
                        function exportUnloadingData() {
                            var selectedWeek = document.querySelector('select[name="week"]').value;
                            var exportUrl = 'export_unloading.php?week=' + encodeURIComponent(selectedWeek);
                            
                            // Sertakan filter produk terpilih (jika ada)
                            var hiddenContainer = document.getElementById('selectedProductsInputs');
                            if (hiddenContainer) {
                                var inputs = hiddenContainer.querySelectorAll('input[name="product_ids[]"]');
                                inputs.forEach(function(inp){
                                    exportUrl += '&product_ids[]=' + encodeURIComponent(inp.value);
                                });
                            }

                            if (!selectedWeek || selectedWeek === '') {
                                alert('Error: Minggu tidak dipilih!');
                                return;
                            }
                            window.location.href = exportUrl;
                        }
                        </script>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter dan Pesan -->
    <div class="row mb-4">
        <div class="col-lg-12">
            
            <?php if ($message): ?>
                <div class="alert alert-<?= $status_type ?> alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle me-2"></i><?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Filter Mingguan dengan Statistik -->
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <form method="GET" class="row g-3 align-items-end">
                                <input type="hidden" name="page" value="unloading">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-calendar-week me-1 text-primary"></i>Pilih Minggu
                                    </label>
                                    <select name="week" class="form-select border-0 shadow-sm">
                                        <?php foreach ($week_options as $week_key => $week_label): ?>
                                            <option value="<?= $week_key ?>" <?= ($week_key == $current_week) ? 'selected' : '' ?>>
                                                <?= $week_label ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        <i class="bi bi-box-seam me-1 text-success"></i>Pilih Item (opsional)
                                    </label>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-outline-secondary flex-grow-1 text-start d-flex justify-content-between align-items-center" data-bs-toggle="modal" data-bs-target="#productFilterModal">
                                            <span id="selectedProductsSummary">Semua Item</span>
                                            <i class="bi bi-sliders"></i>
                                        </button>
                                        <button type="button" class="btn btn-outline-danger" id="clearAllProductsBtn">Clear</button>
                                    </div>
                                    <div id="selectedProductsInputs">
                                        <?php foreach ($selected_product_ids as $sid): ?>
                                          <input type="hidden" name="product_ids[]" value="<?= htmlspecialchars($sid) ?>">
                                        <?php endforeach; ?>
                                    </div>
                                    <small class="text-muted">Biarkan kosong untuk menampilkan semua item.</small>
                                </div>
                                <div class="col-md-3">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-search me-1"></i>Filter
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <!-- Statistik Mingguan -->
                            <div class="row text-center">
                                <div class="col-6">
                                    <div class="stat-box bg-primary bg-opacity-10 rounded p-2">
                                        <div class="stat-number text-primary fw-bold fs-4"><?= count($unloading_records) ?></div>
                                        <div class="stat-label small text-muted">Total Record</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="stat-box bg-success bg-opacity-10 rounded p-2">
                                        <div class="stat-number text-success fw-bold fs-4"><?= array_sum(array_column($unloading_records, 'qty_sak')) ?></div>
                                        <div class="stat-label small text-muted">Total Sak</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabel Data Unloading Mingguan -->
    <div class="row">
        <div class="col-lg-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light">
                    <h6 class="card-title fw-bold mb-0">
                        <i class="bi bi-table me-2"></i>Data Unloading Mingguan
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th class="fw-bold text-center" style="width: 120px;">Tanggal</th>
                                    <th class="fw-bold text-center" style="width: 100px;">Jam Masuk</th>
                                    <th class="fw-bold text-center" style="width: 100px;">Start QC</th>
                                    <th class="fw-bold text-center" style="width: 100px;">Finish QC</th>
                                    <th class="fw-bold text-center" style="width: 100px;">Start Bongkar</th>
                                    <th class="fw-bold text-center" style="width: 100px;">Finish Bongkar</th>
                                    <th class="fw-bold text-center" style="width: 100px;">Jam Keluar</th>
                                    <th class="fw-bold text-center" style="width: 80px;">Durasi (Menit)</th>
                                    <th class="fw-bold text-center" style="width: 150px;">Supplier</th>
                                    <th class="fw-bold text-center" style="width: 120px;">No. PO</th>
                                    <th class="fw-bold text-center" style="width: 200px;">Nama Barang</th>
                                    <th class="fw-bold text-center" style="width: 120px;">No. Mobil</th>
                                    <th class="fw-bold text-center" style="width: 80px;">Qty Sak</th>
                                    <th class="fw-bold text-center" style="width: 80px;">Qty Pallet</th>
                                    <th class="fw-bold text-center" style="width: 160px;">Nama Supir</th>
                                    <th class="fw-bold text-center" style="width: 100px;">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($unloading_records)): ?>
                                    <tr>
                                        <td colspan="16" class="text-center text-muted p-5">
                                            <div class="empty-state">
                                                <i class="bi bi-inbox display-1 text-muted opacity-50"></i>
                                                <h5 class="mt-3 text-muted">Belum Ada Data Unloading</h5>
                                                <p class="text-muted">Data unloading untuk minggu ini belum tersedia</p>
                                                <p class="small text-muted">Periode: <?= $monday->format('d/m/Y') ?> - <?= $saturday->format('d/m/Y') ?></p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php 
                                    $current_date = '';
                                    $date_total_sak = 0;
                                    $date_total_pallet = 0;
                                    $grouped_records = [];
                                    
                                    // Group records by date
                                    foreach ($unloading_records as $record) {
                                        $grouped_records[$record['tanggal']][] = $record;
                                    }
                                    ?>
                                    
                                    <?php foreach ($grouped_records as $date => $records): ?>
                                        <?php 
                                        $date_sak_total = array_sum(array_column($records, 'qty_sak'));
                                        $date_pallet_total = array_sum(array_column($records, 'qty_pallet'));
                                        $record_count = count($records);
                                        ?>
                                        
                                        <!-- Header Tanggal -->
                                        <tr class="table-secondary">
                                            <td colspan="16" class="fw-bold py-2">
                                                <i class="bi bi-calendar-day me-2"></i>
                                                <?= date('l, d F Y', strtotime($date)) ?>
                                                <span class="badge bg-info ms-2"><?= $record_count ?> record</span>
                                                <span class="badge bg-success ms-1"><?= $date_sak_total ?> sak</span>
                                                <span class="badge bg-warning ms-1"><?= $date_pallet_total ?> pallet</span>
                                            </td>
                                        </tr>
                                        
                                        <?php foreach ($records as $record): ?>
                                            <tr>
                                                <td class="text-center">
                                                    <span class="badge bg-primary"><?= $record['tanggal_formatted'] ?></span>
                                                </td>
                                            <td class="text-center">
                                                <input type="time" class="form-control form-control-sm" 
                                                       value="<?= $record['jam_masuk'] ?>" 
                                                       onchange="updateTime(<?= $record['id'] ?>, 'jam_masuk', this.value)">
                                            </td>
                                            <td class="text-center">
                                                <input type="time" class="form-control form-control-sm" 
                                                       value="<?= $record['jam_start_qc'] ?>" 
                                                       onchange="updateTime(<?= $record['id'] ?>, 'jam_start_qc', this.value)">
                                            </td>
                                            <td class="text-center">
                                                <input type="time" class="form-control form-control-sm" 
                                                       value="<?= $record['jam_finish_qc'] ?>" 
                                                       onchange="updateTime(<?= $record['id'] ?>, 'jam_finish_qc', this.value)">
                                            </td>
                                            <td class="text-center">
                                                <input type="time" class="form-control form-control-sm" 
                                                       value="<?= $record['jam_start_bongkar'] ?>" 
                                                       onchange="updateTime(<?= $record['id'] ?>, 'jam_start_bongkar', this.value)">
                                            </td>
                                            <td class="text-center">
                                                <input type="time" class="form-control form-control-sm" 
                                                       value="<?= $record['jam_finish_bongkar'] ?>" 
                                                       onchange="updateTime(<?= $record['id'] ?>, 'jam_finish_bongkar', this.value)">
                                            </td>
                                            <td class="text-center">
                                                <input type="time" class="form-control form-control-sm" 
                                                       value="<?= $record['jam_keluar'] ?>" 
                                                       onchange="updateTime(<?= $record['id'] ?>, 'jam_keluar', this.value)">
                                            </td>
                                            <td class="text-center">
                                                <?php if ($record['durasi_bongkar']): ?>
                                                    <span class="badge bg-info"><?= $record['durasi_bongkar'] ?> menit</span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-semibold"><?= htmlspecialchars($record['supplier']) ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-primary"><?= htmlspecialchars($record['po_number'] ?? 'N/A') ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-semibold"><?= htmlspecialchars($record['nama_barang']) ?></span>
                                            </td>
                                            <td class="text-center">
    <code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($record['nomor_mobil']) ?></code>
</td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary"><?= formatAngkaUI($record['qty_sak']) ?></span>
                                            </td>
                                            <td class="text-center">
                                                 <input type="number" class="form-control form-control-sm text-center" 
                                                        value="<?= $record['qty_pallet'] ?>" 
                                                        min="0" step="1"
                                                        onchange="updateQtyPallet(<?= $record['id'] ?>, this.value)"
                                                        style="width: 60px;">
                                             </td>
                                             <td class="text-center">
                                                 <input type="text" class="form-control form-control-sm" 
                                                        value="<?= htmlspecialchars($record['nama_supir'] ?? '') ?>" 
                                                        placeholder="Nama Supir"
                                                        onchange="updateDriverName(<?= $record['id'] ?>, this.value)"
                                                        style="min-width: 140px;">
                                             </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <?php
                                                    // Buat parameter untuk link hapus dengan filter yang ada
                                                    $delete_params = $query_params;
                                                    $delete_params['action'] = 'delete_unloading';
                                                    $delete_params['id'] = $record['id'];
                                                    ?>
                                                    <a href="index.php?<?= http_build_query($delete_params) ?>"
                                                        class="btn btn-outline-danger delete-unloading-btn"
                                                        data-delete-url="<?= htmlspecialchars('index.php?' . http_build_query($delete_params)) ?>"
                                                        title="Hapus">
                                                        <i class="bi bi-trash3-fill"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Filter Produk -->
<div class="modal fade" id="productFilterModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-box-seam me-2"></i>Pilih Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <input type="text" class="form-control" id="productFilterInput" placeholder="Cari item...">
          </div>
          <div class="col-12 d-flex gap-2">
            <button type="button" class="btn btn-sm btn-light" id="selectAllProductsBtn">Pilih Semua</button>
            <button type="button" class="btn btn-sm btn-light" id="clearModalSelectionBtn">Hapus Pilihan</button>
          </div>
          <div class="col-12">
            <div id="productCheckboxList" class="border rounded p-2" style="max-height:60vh; overflow:auto;">
              <?php foreach ($all_products as $p): $pid = (string)$p['id']; ?>
                <div class="form-check product-item py-1">
                  <input class="form-check-input" type="checkbox" value="<?= $p['id'] ?>" id="prod_<?= $p['id'] ?>" <?= in_array($pid, $selected_product_ids, true) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="prod_<?= $p['id'] ?>">
                    <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['sku']) ?>)
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
        <button type="button" class="btn btn-primary" id="applyProductSelectionBtn">Terapkan</button>
      </div>
    </div>
  </div>
  </div>

<script>
// CSRF token for async POST requests
const CSRF_TOKEN = '<?= htmlspecialchars(get_csrf_token()) ?>';
// Gaya kecil untuk list di modal
const style = document.createElement('style');
style.textContent = `
  #productCheckboxList .form-check:hover { background:#f8f9fa; border-radius:6px; }
`;
document.head.appendChild(style);

// ==== UI filter produk modern dengan checkbox, pencarian, dan select all ====
document.addEventListener('DOMContentLoaded', function(){
  const list = document.getElementById('productCheckboxList');
  const filterInput = document.getElementById('productFilterInput');
  const selectAllBtn = document.getElementById('selectAllProductsBtn');
  const clearModalBtn = document.getElementById('clearModalSelectionBtn');
  const applyBtn = document.getElementById('applyProductSelectionBtn');
  const summary = document.getElementById('selectedProductsSummary');
  const hiddenContainer = document.getElementById('selectedProductsInputs');
  const clearAllTopBtn = document.getElementById('clearAllProductsBtn');
  // Persist pilihan item di localStorage agar tidak hilang saat berpindah halaman
  const STORAGE_KEY = 'unloading_selected_product_ids';

  function updateSummaryFromList(){
    const checked = list.querySelectorAll('input[type="checkbox"]:checked');
    if (checked.length === 0) { summary.textContent = 'Semua Item'; return; }
    if (checked.length === 1) {
      const label = checked[0].closest('.form-check').querySelector('label').textContent.trim();
      summary.textContent = label; return;
    }
    summary.textContent = checked.length + ' item dipilih';
  }

  if (filterInput && list) {
    filterInput.addEventListener('input', function(){
      const term = this.value.toLowerCase();
      list.querySelectorAll('.product-item').forEach(el => {
        const text = el.textContent.toLowerCase();
        el.style.display = text.includes(term) ? '' : 'none';
      });
    });
  }

  if (selectAllBtn && list) {
    selectAllBtn.addEventListener('click', function(){
      list.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = true);
      updateSummaryFromList();
    });
  }

  if (clearModalBtn && list) {
    clearModalBtn.addEventListener('click', function(){
      list.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
      updateSummaryFromList();
    });
  }

  if (clearAllTopBtn && list) {
    clearAllTopBtn.addEventListener('click', function(){
      list.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
      updateSummaryFromList();
      // Hapus hidden inputs juga
      if (hiddenContainer) hiddenContainer.innerHTML = '';
    });
  }

  function loadFromStorage() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) return;
      const ids = JSON.parse(raw);
      if (!Array.isArray(ids)) return;
      // centang checkbox yang sesuai
      ids.forEach(id => {
        const cb = document.getElementById('prod_' + id);
        if (cb) cb.checked = true;
      });
      updateSummaryFromList();
      // rebuild hidden inputs agar form menyertakan pilihan
      if (hiddenContainer) {
        hiddenContainer.innerHTML = '';
        ids.forEach(id => {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'product_ids[]';
          input.value = id;
          hiddenContainer.appendChild(input);
        });
      }
    } catch(e) { /* ignore */ }
  }

  function saveToStorage() {
    if (!list) return;
    const checked = Array.from(list.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(checked)); } catch(e) { /* ignore */ }
  }

  if (list) {
    list.addEventListener('change', function(){ updateSummaryFromList(); saveToStorage(); });
    loadFromStorage();
  }

  if (applyBtn && hiddenContainer && list) {
    applyBtn.addEventListener('click', function(){
      // rebuild hidden inputs sesuai pilihan
      hiddenContainer.innerHTML = '';
      const checked = list.querySelectorAll('input[type="checkbox"]:checked');
      checked.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'product_ids[]';
        input.value = cb.value;
        hiddenContainer.appendChild(input);
      });
      updateSummaryFromList();
      saveToStorage();
      // Tutup modal
      const modalEl = document.getElementById('productFilterModal');
      const instance = bootstrap.Modal.getInstance(modalEl);
      if (instance) instance.hide();
    });
  }
});

// Function untuk update waktu secara real-time
function updateTime(recordId, field, value) {
    fetch('index.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `form_type=update_unloading_time&record_id=${recordId}&field=${field}&value=${encodeURIComponent(value)}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`
    })
    .then(response => response.text())
    .then(data => {
        console.log('Time updated successfully');
        // Reload halaman untuk memperbarui durasi
        setTimeout(() => {
            location.reload();
        }, 500);
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Gagal memperbarui waktu');
    });
}

// Function untuk download export
function downloadExport(url) {
    // Buat elemen iframe tersembunyi untuk download
    var iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = url;
    document.body.appendChild(iframe);
    
    // Hapus iframe setelah beberapa detik
    setTimeout(function() {
        document.body.removeChild(iframe);
    }, 3000);
    
    // Tampilkan pesan sukses
    alert('Export sedang diproses... File akan terdownload dalam beberapa detik.');
}

// Function untuk update qty pallet
function updateQtyPallet(recordId, value) {
    fetch('index.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `form_type=update_unloading_qty_pallet&record_id=${recordId}&value=${encodeURIComponent(value)}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`
    })
    .then(response => response.text())
    .then(data => {
        if (data === 'success') {
            console.log('Qty pallet updated successfully');
        } else {
            alert('Gagal memperbarui qty pallet: ' + data);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat memperbarui qty pallet.');
    });
}

// Function untuk update nama supir
function updateDriverName(recordId, value) {
    fetch('index.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `form_type=update_unloading_driver&record_id=${recordId}&value=${encodeURIComponent(value)}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`
    })
    .then(response => response.text())
    .then(data => {
        if (data === 'success') {
            console.log('Nama supir updated successfully');
        } else {
            alert('Gagal memperbarui nama supir: ' + data);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Terjadi kesalahan saat memperbarui nama supir.');
    });
}
</script> 