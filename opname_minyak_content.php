<?php
require_once __DIR__ . '/security_bootstrap.php';
// Opname Minyak Page (Stock Opname Bahan Cair)
// - Menyediakan ringkasan SAP (saldo awal, GR, saldo akhir sebelum bon)
// - Form per-tangki untuk input fisik (CM/Liter/Kg, BJ) dan perhitungan total fisik
// - Bon = (Saldo akhir SAP sebelum bon) - (Total fisik hari ini)

require_once 'koneksi.php';

// Buat tabel penyimpanan per-tangki bila belum ada
$pdo->exec("CREATE TABLE IF NOT EXISTS opname_tanks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  opname_date DATE NOT NULL,
  tank_name VARCHAR(100) NOT NULL,
  product_id INT NOT NULL,
  cm_awal DECIMAL(18,3) DEFAULT 0,
  saldo_awal_kg DECIMAL(18,3) DEFAULT 0,
  input_kg_hari_ini DECIMAL(18,3) DEFAULT 0,
  cm_akhir DECIMAL(18,3) DEFAULT 0,
  liter_akhir DECIMAL(18,3) DEFAULT 0,
  kg_akhir DECIMAL(18,3) DEFAULT 0,
  bj DECIMAL(10,4) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX (opname_date),
  INDEX (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Gunakan helper global dari koneksi.php jika sudah ada
if (!function_exists('formatAngka')) {
  function formatAngka($num) {
    if ($num === null || $num === '') return '0';
    return number_format((float)$num, 2, ',', '.');
  }
}

$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$previous_date = date('Y-m-d', strtotime($filter_date . ' -1 day'));

// Ambil daftar produk
$products = $pdo->query("SELECT id, sku, product_name FROM products ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$productMap = [];
foreach ($products as $p) { $productMap[$p['id']] = $p; }

// Hitung saldo akhir SAP (closing stock) pada H-1 (re-use logika laporan harian)
$sqlClosing = "
  SELECT p.id as product_id,
    (SUM(CASE WHEN t.type = 'IN'  AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END) -
     SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date <= ? THEN t.quantity_kg ELSE 0 END)) AS closing_kg
  FROM products p
  LEFT JOIN (
    SELECT product_id, transaction_date, quantity_kg, 'IN'  as type FROM incoming_transactions
    UNION ALL
    SELECT product_id, transaction_date, quantity_kg, 'OUT' as type FROM outgoing_transactions
  ) t ON p.id = t.product_id
  GROUP BY p.id
";
$stmtClosing = $pdo->prepare($sqlClosing);
$stmtClosing->execute([$previous_date, $previous_date]);
$closingRows = $stmtClosing->fetchAll(PDO::FETCH_ASSOC);
$productToClosing = [];
foreach ($closingRows as $r) { $productToClosing[$r['product_id']] = (float)$r['closing_kg']; }

// Hitung GR kedatangan (qty kg) pada tanggal berjalan (filter_date)
$stmtGR = $pdo->prepare("SELECT product_id, SUM(quantity_kg) as gr_kg FROM incoming_transactions WHERE transaction_date = ? GROUP BY product_id");
$stmtGR->execute([$filter_date]);
$grRows = $stmtGR->fetchAll(PDO::FETCH_ASSOC);
$productToGR = [];
foreach ($grRows as $r) { $productToGR[$r['product_id']] = (float)$r['gr_kg']; }

// Ambil data tangki untuk tanggal berjalan
$stmtTanks = $pdo->prepare("SELECT * FROM opname_tanks WHERE opname_date = ? ORDER BY id ASC");
$stmtTanks->execute([$filter_date]);
$tankRows = $stmtTanks->fetchAll(PDO::FETCH_ASSOC);

// Jika form disubmit untuk simpan baris tangki
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'save_opname_tanks') {
  $date = $_POST['opname_date'] ?? $filter_date;
  $rows = json_decode($_POST['rows_json'] ?? '[]', true);
  if (is_array($rows)) {
    // Simpan satu per satu; gunakan INSERT ... ON DUPLICATE KEY? -> Tidak ada unique; hapus dan insert ulang untuk tanggal tsb
    $pdo->beginTransaction();
    try {
      $del = $pdo->prepare("DELETE FROM opname_tanks WHERE opname_date = ?");
      $del->execute([$date]);

      $ins = $pdo->prepare("INSERT INTO opname_tanks (opname_date, tank_name, product_id, cm_awal, saldo_awal_kg, input_kg_hari_ini, cm_akhir, liter_akhir, kg_akhir, bj) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
      foreach ($rows as $row) {
        $ins->execute([
          $date,
          $row['tank_name'] ?? '',
          (int)($row['product_id'] ?? 0),
          (float)($row['cm_awal'] ?? 0),
          (float)($row['saldo_awal_kg'] ?? 0),
          (float)($row['input_kg_hari_ini'] ?? 0),
          (float)($row['cm_akhir'] ?? 0),
          (float)($row['liter_akhir'] ?? 0),
          (float)($row['kg_akhir'] ?? 0),
          (float)($row['bj'] ?? 0),
        ]);
      }
      $pdo->commit();
      // Gunakan JavaScript redirect karena header sudah terkirim
      echo '<script>window.location.href = "index.php?page=opname_minyak&filter_date=' . urlencode($date) . '&status=saved";</script>';
      exit();
    } catch (Exception $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      die('Gagal menyimpan data: ' . $e->getMessage());
    }
  }
}

// Preset material yang diizinkan (hanya 4 kategori) dan mapping tangki default
$allowedPatterns = [
  'GLUCOSE DE 64',
  'RBD PALM OLEIN A O BHA',
  'H. FRUCTOSE SYRUP',
  'SORBITOL', // mencakup SORBITOL LIQUID
];

function nameAllowed($name, $patterns) {
  $n = mb_strtolower($name);
  foreach ($patterns as $pat) {
    if (mb_strpos($n, mb_strtolower($pat)) !== false) return true;
  }
  return false;
}

$tankPresets = [
  ['TANGKI 3',  'RBD PALM OLEIN A O BHA'],
  ['TANGKI 5',  'GLUCOSE DE 64'],
  ['TANGKI 6',  'H. FRUCTOSE SYRUP'],
  ['TANGKI 7',  'H. FRUCTOSE SYRUP'],
  ['TANGKI 8',  'GLUCOSE DE 64'],
  ['TANGKI 9',  'GLUCOSE DE 64'],
  ['TANGKI 10', 'GLUCOSE DE 64'],
  ['TANGKI 11', 'H. FRUCTOSE SYRUP'],
  ['TANGKI 12', 'GLUCOSE DE 64'],
  ['TANGKI 13', 'SORBITOL'],
  ['TANGKI 20', 'SORBITOL'],
];

// Default BJ per material (berdasarkan nama mengandung pola)
$defaultBjPatterns = [
  'RBD PALM OLEIN' => 0.900,
  'GLUCOSE DE 64' => 1.406,
  'H. FRUCTOSE SYRUP' => 1.377,
  'SORBITOL' => 1.295,
];

// Default BJ spesifik per-nama tangki sesuai urutan yang diminta
$defaultBjByTank = [
  'TANGKI 3'  => 0.900,
  'TANGKI 5'  => 1.377,
  'TANGKI 6'  => 1.406,
  'TANGKI 7'  => 1.406,
  'TANGKI 8'  => 1.377,
  'TANGKI 9'  => 1.377,
  'TANGKI 10' => 91.400,
  'TANGKI 11' => 1.406,
  'TANGKI 12' => 1.377,
  'TANGKI 13' => 1.295,
  'TANGKI 20' => 1.295,
];

function getDefaultBjByName(string $productName, array $patterns): float {
  $n = mb_strtoupper($productName);
  foreach ($patterns as $key => $val) {
    if (mb_strpos($n, mb_strtoupper($key)) !== false) {
      return (float)$val;
    }
  }
  return 1.0000; // fallback aman
}

// Jika belum ada baris untuk tanggal ini, buat tampilan default dari preset
if (empty($tankRows)) {
  foreach ($tankPresets as [$tankName, $prodPattern]) {
    // cari product_id berdasarkan pattern
    $pid = 0; $pname='';
    foreach ($products as $p) {
      if (nameAllowed($p['product_name'], [$prodPattern])) { $pid = (int)$p['id']; $pname=$p['product_name']; break; }
    }
    $row = [
      'tank_name' => $tankName,
      'product_id' => $pid,
      'cm_awal' => 0,
      'saldo_awal_kg' => 0,
      'input_kg_hari_ini' => 0,
      'cm_akhir' => 0,
      'liter_akhir' => 0,
      'kg_akhir' => 0,
      'bj' => isset($defaultBjByTank[$tankName])
                ? $defaultBjByTank[$tankName]
                : (!empty($pid) ? getDefaultBjByName($pname ?: ($prodPattern), $defaultBjPatterns) : 0),
    ];
    // Prefill dari H-1 bila ada
    $key = $tankName . '|' . $pid;
    if (!empty($pid) && isset($prevByTankProduct[$key])) {
      $prev = $prevByTankProduct[$key];
      $row['cm_awal'] = (float)($prev['cm_akhir'] ?? 0);
      $row['saldo_awal_kg'] = (float)($prev['kg_akhir'] ?? 0);
    }
    $tankRows[] = $row;
  }
}

// Hitung total fisik per produk (sum kg_akhir dari baris tangki)
$productToFisik = [];
foreach ($tankRows as $tr) {
  $pid = (int)$tr['product_id'];
  $productToFisik[$pid] = ($productToFisik[$pid] ?? 0) + (float)$tr['kg_akhir'];
}

// Kumpulkan produk yang relevan (punya saldo/gr/fisik)
$relevantProducts = [];
foreach ($products as $p) {
  $pid = (int)$p['id'];
  // Batasi hanya untuk material yang diizinkan
  if (!nameAllowed($p['product_name'], $allowedPatterns)) continue;
  $closing = $productToClosing[$pid] ?? 0;
  $gr = $productToGR[$pid] ?? 0;
  $fisik = $productToFisik[$pid] ?? 0;
  if ($closing != 0 || $gr != 0 || $fisik != 0) {
    $relevantProducts[] = $pid;
  }
}

// Ambil data H-1 untuk default cm_awal/saldo_awal_kg (copy dari cm_akhir/kg_akhir H-1)
$stmtPrev = $pdo->prepare("SELECT * FROM opname_tanks WHERE opname_date = ? ORDER BY id ASC");
$stmtPrev->execute([$previous_date]);
$prevByTankProduct = [];
foreach ($stmtPrev->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $key = $r['tank_name'] . '|' . $r['product_id'];
  $prevByTankProduct[$key] = $r; // cm_akhir, kg_akhir
}

// Ambil data laporan harian untuk tanggal yang sama (untuk kolom TOTAL SAP)
$stmtLaporan = $pdo->prepare("
  SELECT p.id as product_id, p.sku, p.product_name,
         COALESCE(i.total_incoming, 0) as total_incoming,
         COALESCE(o.total_outgoing, 0) as total_outgoing
  FROM products p
  LEFT JOIN (
    SELECT product_id, SUM(quantity_kg) AS total_incoming
    FROM incoming_transactions
    WHERE DATE(transaction_date) <= ?
    GROUP BY product_id
  ) i ON p.id = i.product_id
  LEFT JOIN (
    SELECT product_id, SUM(quantity_kg) AS total_outgoing
    FROM outgoing_transactions
    WHERE DATE(transaction_date) <= ?
    GROUP BY product_id
  ) o ON p.id = o.product_id
  WHERE p.id IN (" . implode(',', array_fill(0, count($relevantProducts), '?')) . ")
  ORDER BY p.product_name ASC
");
$params = array_merge([$filter_date, $filter_date], $relevantProducts);
$stmtLaporan->execute($params);
$laporanData = $stmtLaporan->fetchAll(PDO::FETCH_ASSOC);

// Buat mapping product_id ke data laporan
$productToLaporan = [];
foreach ($laporanData as $ld) {
  $pid = (int)$ld['product_id'];
  $productToLaporan[$pid] = [
    'sku' => $ld['sku'],
    'product_name' => $ld['product_name'],
    'total_incoming' => (float)$ld['total_incoming'],
    'total_outgoing' => (float)$ld['total_outgoing'],
    'stock_akhir' => (float)$ld['total_incoming'] - (float)$ld['total_outgoing']
  ];
}

// Hitung total BON per produk (sum dari kolom BON di ringkasan SAP)
$productToBon = [];
foreach ($relevantProducts as $pid) {
  $closing = $productToClosing[$pid] ?? 0;
  $gr = $productToGR[$pid] ?? 0;
  $fisik = $productToFisik[$pid] ?? 0;
  $bon = ($closing + $gr) - $fisik;
  $productToBon[$pid] = $bon;
}

?>

<div class="container-fluid" id="opnameMinyakPage">
  <?php if (!empty($_GET['status']) && $_GET['status']==='saved'): ?>
  <div class="alert alert-success">Data opname berhasil disimpan.</div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-header bg-primary text-white">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Stock Opname Bahan Cair 1115</h5>
        <form action="index.php" method="GET" class="d-flex gap-2">
          <input type="hidden" name="page" value="opname_minyak" />
          <div class="input-group input-group-sm">
            <span class="input-group-text">Periode</span>
            <input type="date" class="form-control" name="filter_date" value="<?= htmlspecialchars($filter_date) ?>" />
          </div>
          <button class="btn btn-light btn-sm" type="submit">Tampilkan</button>
          <a class="btn btn-success btn-sm" href="export_opname_minyak.php?filter_date=<?= urlencode($filter_date) ?>">
            <i class="bi bi-file-earmark-excel"></i> Export
          </a>
          <a class="btn btn-outline-secondary btn-sm" target="_blank" href="print_opname_minyak.php?filter_date=<?= urlencode($filter_date) ?>">
            <i class="bi bi-printer"></i> Print
          </a>
        </form>
      </div>
    </div>
    <div class="card-body p-2">
      <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle">
          <thead class="table-secondary">
            <tr>
              <th style="min-width:140px;">Kode Barang</th>
              <th style="min-width:220px;">Nama Material</th>
              <th class="text-center">Saldo Awal SAP (Kg)</th>
              <th class="text-center">GR Kedatangan (Kg)</th>
              <th class="text-center">Saldo Akhir SAP<br/>Sebelum Bon (Kg)</th>
              <th class="text-center bg-danger text-white">Stock Fisik Hari Ini (Kg)</th>
              <th class="text-center">Bon (Kg)</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($relevantProducts as $pid):
                $p = $productMap[$pid];
                $closing = $productToClosing[$pid] ?? 0;
                $gr = $productToGR[$pid] ?? 0;
                $saldoAkhirSebelumBon = $closing + $gr;
                $fisik = $productToFisik[$pid] ?? 0;
                $bon = $saldoAkhirSebelumBon - $fisik;
            ?>
            <tr>
              <td class="text-center"><code><?= htmlspecialchars($p['sku']) ?></code></td>
              <td class="text-start fw-semibold"><?= htmlspecialchars($p['product_name']) ?></td>
              <td class="text-end"><?= formatAngka($closing) ?></td>
              <td class="text-end"><?= formatAngka($gr) ?></td>
              <td class="text-end"><?= formatAngka($saldoAkhirSebelumBon) ?></td>
              <td class="text-end bg-danger bg-opacity-10 fw-bold" data-role="fisik-today" data-pid="<?= (int)$pid ?>"><?= formatAngka($fisik) ?></td>
              <td class="text-end fw-bold"><?= formatAngka($bon) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($relevantProducts)): ?>
            <tr><td colspan="7" class="text-center text-muted">Belum ada data untuk periode ini.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<form method="POST" id="opnameTanksForm" class="card">
    <input type="hidden" name="form_type" value="save_opname_tanks" />
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>" />
    <input type="hidden" name="opname_date" value="<?= htmlspecialchars($filter_date) ?>" />
    <input type="hidden" name="rows_json" id="rows_json" />
    <div class="card-header bg-light">
      <div class="d-flex justify-content-between align-items-center">
        <h6 class="mb-0">Saldo Akhir Volume Tangki - <?= date('d/m/Y', strtotime($filter_date)) ?></h6>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-sm btn-success" id="addRowBtn"><i class="bi bi-plus"></i> Tambah Baris</button>
          <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> Simpan</button>
        </div>
      </div>
      <small class="text-muted">CM Awal & Saldo Awal KG diisi otomatis dari data H-1. Kolom tanggal (H-1) dapat diinput manual. CM/Liter/BJ dapat diinput manual; KG akhir = Liter x BJ.</small>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped table-hover mb-0" id="tanksTable">
          <thead class="table-light">
            <tr>
              <th style="min-width:120px;">Tangki</th>
              <th style="min-width:260px;">Nama Material</th>
              <th class="text-center">CM Awal</th>
              <th class="text-center">Saldo Awal KG</th>
              <th class="text-center"><?= date('d/m/y', strtotime($previous_date)) ?></th>
              <th class="text-center">CM</th>
              <th class="text-center">Liter</th>
              <th class="text-center">KG (auto)</th>
              <th class="text-center">BJ</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($tankRows)): foreach ($tankRows as $row): 
              // Prefill BJ default jika 0 dan product_id valid
              if ((float)($row['bj'] ?? 0) == 0) {
                if (!empty($row['tank_name']) && isset($defaultBjByTank[$row['tank_name']])) {
                  $row['bj'] = $defaultBjByTank[$row['tank_name']];
                } elseif (!empty($row['product_id'])) {
                  $pname = $productMap[$row['product_id']]['product_name'] ?? '';
                  $row['bj'] = getDefaultBjByName($pname, $defaultBjPatterns);
                }
              }
            ?>
            <tr>
              <td><input type="text" class="form-control form-control-sm tank_name" value="<?= htmlspecialchars($row['tank_name']) ?>"/></td>
              <td>
                <select class="form-select form-select-sm product_id">
                  <option value="">-- Pilih Material --</option>
                  <?php foreach ($products as $p): if (!nameAllowed($p['product_name'], $allowedPatterns)) continue; ?>
                    <option value="<?= $p['id'] ?>" <?= ($row['product_id']==$p['id']?'selected':'') ?>><?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['sku']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" step="any" class="form-control form-control-sm text-end cm_awal" value="<?= htmlspecialchars($row['cm_awal']) ?>"/></td>
              <td><input type="number" step="any" class="form-control form-control-sm text-end saldo_awal_kg" value="<?= htmlspecialchars($row['saldo_awal_kg']) ?>"/></td>
              <td><input type="number" step="any" class="form-control form-control-sm text-end input_kg_hari_ini" value="<?= htmlspecialchars($row['input_kg_hari_ini']) ?>"/></td>
              <td><input type="number" step="any" class="form-control form-control-sm text-end cm_akhir" value="<?= htmlspecialchars($row['cm_akhir']) ?>"/></td>
              <td><input type="number" step="any" class="form-control form-control-sm text-end liter_akhir" value="<?= htmlspecialchars($row['liter_akhir']) ?>"/></td>
              <td><input type="number" step="any" class="form-control form-control-sm text-end kg_akhir" value="<?= htmlspecialchars($row['kg_akhir']) ?>" readonly/></td>
              <td><input type="number" step="0.001" class="form-control form-control-sm text-end bj" value="<?= number_format((float)$row['bj'], 3, '.', '') ?>"/></td>
              <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger removeRowBtn"><i class="bi bi-trash3"></i></button></td>
            </tr>
            <?php endforeach; else: ?>
            <tr class="empty-row">
              <td colspan="10" class="text-center text-muted py-3">Belum ada baris. Klik "Tambah Baris" untuk memulai.</td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </form>
</div>

<!-- Tabel Ringkasan Issue Level -->
<div class="card mt-4">
  <div class="card-header">
    <h5 class="card-title mb-0">Stock Selisih</h5>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-bordered">
        <thead>
          <tr class="table-warning">
            <th rowspan="2" class="align-middle text-center" style="width: 120px;">Kode Barang</th>
            <th rowspan="2" class="align-middle text-start">NAMA MATERIAL</th>
            <th class="text-center table-warning" style="width: 120px;">TOTAL BON</th>
            <th rowspan="2" class="align-middle text-center table-warning" style="width: 120px;">TOTAL SAP</th>
            <th rowspan="2" class="align-middle text-center" style="width: 140px;">
              <div>Selisih</div>
              <div>SAP & Fisik</div>
            </th>
          </tr>
          
        </thead>
        <tbody>
          <?php 
          $rowNum = 1;
          foreach ($relevantProducts as $pid): 
            $laporan = $productToLaporan[$pid] ?? null;
            if (!$laporan) continue;
            
            $bon = $productToBon[$pid] ?? 0;
            $stockFisik = $productToFisik[$pid] ?? 0; // tetap digunakan untuk hitung selisih
            $totalSap = $laporan['stock_akhir'];
            $selisih = $totalSap - $stockFisik;
          ?>
          <tr>
            <td class="text-center align-middle"><?= htmlspecialchars($laporan['sku']) ?></td>
            <td class="align-middle"><?= htmlspecialchars($laporan['product_name']) ?></td>
            <td class="text-end table-warning align-middle"><?= number_format($bon, 3, ',', '.') ?></td>
            <td class="text-end table-warning align-middle"><?= number_format($totalSap, 3, ',', '.') ?></td>
            <td class="text-end align-middle"><?= number_format($selisih, 3, ',', '.') ?></td>
          </tr>
          <?php 
            $rowNum++;
          endforeach; 
          ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const products = <?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>;
  const prevMap = <?= json_encode($prevByTankProduct, JSON_UNESCAPED_UNICODE) ?>;
  const bjByTank = <?= json_encode($defaultBjByTank ?? [], JSON_UNESCAPED_UNICODE) ?>;

  const table = document.getElementById('tanksTable');
  const tbody = table.querySelector('tbody');
  const addRowBtn = document.getElementById('addRowBtn');
  const rowsJson = document.getElementById('rows_json');

  // Map product_id -> total kg fisik (sum kg_akhir dari tabel tangki)
  function computeFisikTotals() {
    const totals = {};
    tbody.querySelectorAll('tr').forEach(tr => {
      if (tr.classList.contains('empty-row')) return;
      const pid = parseInt(tr.querySelector('.product_id')?.value || '0', 10);
      const kg = parseFloat(tr.querySelector('.kg_akhir')?.value || '0');
      if (!pid || !isFinite(kg)) return;
      totals[pid] = (totals[pid] || 0) + kg;
    });
    return totals;
  }

  // Update kolom "Stock Fisik Hari Ini" berdasarkan sum per produk dari tabel tangki
  function syncFisikToday() {
    const totals = computeFisikTotals();
    document.querySelectorAll('[data-role="fisik-today"]').forEach(cell => {
      const pid = parseInt(cell.getAttribute('data-pid') || '0', 10);
      const total = totals[pid] || 0;
      cell.textContent = formatNumberDisplay(total);
    });
  }

  function buildProductSelect(selectedId) {
    const sel = document.createElement('select');
    sel.className = 'form-select form-select-sm product_id';
    const allowed = (name)=> {
      const pats = ["GLUCOSE DE 64","RBD PALM OLEIN A O BHA","H. FRUCTOSE SYRUP","SORBITOL"]; 
      const nm = (name||'').toLowerCase();
      return pats.some(pt => nm.includes(pt.toLowerCase()));
    };
    sel.innerHTML = '<option value="">-- Pilih Material --</option>' +
      products.filter(p => allowed(p.product_name)).map(p => `<option value="${p.id}" ${String(selectedId)===String(p.id)?'selected':''}>${p.product_name} (${p.sku})</option>`).join('');
    return sel;
  }

  function recalcRow(tr) {
    const liter = parseFloat(tr.querySelector('.liter_akhir')?.value || '0');
    const bj = parseFloat(tr.querySelector('.bj')?.value || '0');
    const kgField = tr.querySelector('.kg_akhir');
    kgField.value = (liter * bj).toFixed(3);
    syncFisikToday();
  }

  function getDefaultBjForName(name) {
    const map = [
      {k: 'RBD PALM OLEIN', v: 0.900},
      {k: 'GLUCOSE DE 64', v: 1.406},
      {k: 'H. FRUCTOSE SYRUP', v: 1.377},
      {k: 'SORBITOL', v: 1.295},
    ];
    const n = (name||'').toUpperCase();
    for (const it of map) { if (n.includes(it.k)) return it.v; }
    return 1.0;
  }

  function serializeRows() {
    const rows = [];
    tbody.querySelectorAll('tr').forEach(tr => {
      if (tr.classList.contains('empty-row')) return;
      const tank_name = tr.querySelector('.tank_name')?.value?.trim() || '';
      const product_id = tr.querySelector('.product_id')?.value || '';
      if (!tank_name && !product_id) return;
      rows.push({
        tank_name,
        product_id,
        cm_awal: parseFloat(tr.querySelector('.cm_awal')?.value || '0'),
        saldo_awal_kg: parseFloat(tr.querySelector('.saldo_awal_kg')?.value || '0'),
        input_kg_hari_ini: parseFloat(tr.querySelector('.input_kg_hari_ini')?.value || '0'),
        cm_akhir: parseFloat(tr.querySelector('.cm_akhir')?.value || '0'),
        liter_akhir: parseFloat(tr.querySelector('.liter_akhir')?.value || '0'),
        kg_akhir: parseFloat(tr.querySelector('.kg_akhir')?.value || '0'),
        bj: parseFloat(tr.querySelector('.bj')?.value || '0')
      });
    });
    rowsJson.value = JSON.stringify(rows);
  }

  function attachRowEvents(tr) {
    ['liter_akhir','bj'].forEach(cls => {
      tr.querySelector('.'+cls)?.addEventListener('input', () => recalcRow(tr));
    });
    // Prefill BJ ketika produk dipilih
    const prodSel = tr.querySelector('.product_id');
    if (prodSel) {
      prodSel.addEventListener('change', () => {
        const pid = prodSel.value;
        const product = products.find(p => String(p.id)===String(pid));
        if (product) {
          const bjInput = tr.querySelector('.bj');
          if (bjInput && (!bjInput.value || parseFloat(bjInput.value)===0)) {
            bjInput.value = getDefaultBjForName(product.product_name).toFixed(3);
            recalcRow(tr);
          }
        }
      });
    }
    // Prefill BJ dari nama tangki jika kosong
    const tankInput = tr.querySelector('.tank_name');
    const bjField = tr.querySelector('.bj');
    const tryFillBjFromTank = () => {
      const name = (tankInput?.value || '').toUpperCase();
      if (bjField && (!bjField.value || parseFloat(bjField.value)===0)) {
        if (bjByTank[name] !== undefined) {
          bjField.value = Number(bjByTank[name]).toFixed(3);
          recalcRow(tr);
        }
      }
    };
    tankInput?.addEventListener('change', tryFillBjFromTank);
    tryFillBjFromTank();
    tr.querySelector('.removeRowBtn')?.addEventListener('click', () => {
      tr.remove();
      if (tbody.querySelectorAll('tr').length === 0) {
        const er = document.createElement('tr');
        er.className = 'empty-row';
        er.innerHTML = '<td colspan="10" class="text-center text-muted py-3">Belum ada baris. Klik "Tambah Baris" untuk memulai.</td>';
        tbody.appendChild(er);
      }
      syncFisikToday();
    });
    // Hitung awal
    recalcRow(tr);
  }

  addRowBtn?.addEventListener('click', () => {
    if (tbody.querySelector('.empty-row')) tbody.querySelector('.empty-row').remove();
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input type="text" class="form-control form-control-sm tank_name" placeholder="TANGKI ..."/></td>
      <td></td>
      <td><input type="number" step="any" class="form-control form-control-sm text-end cm_awal"/></td>
      <td><input type="number" step="any" class="form-control form-control-sm text-end saldo_awal_kg"/></td>
      <td><input type="number" step="any" class="form-control form-control-sm text-end input_kg_hari_ini"/></td>
      <td><input type="number" step="any" class="form-control form-control-sm text-end cm_akhir"/></td>
      <td><input type="number" step="any" class="form-control form-control-sm text-end liter_akhir"/></td>
      <td><input type="number" step="any" class="form-control form-control-sm text-end kg_akhir" readonly/></td>
      <td><input type="number" step="0.001" class="form-control form-control-sm text-end bj"/></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger removeRowBtn"><i class="bi bi-trash3"></i></button></td>
    `;
    // inject select product
    const prodTd = tr.children[1];
    prodTd.appendChild(buildProductSelect(''));

    // Prefill BJ default saat baris baru dibuat (jika kemudian user memilih produk, event di atas akan override jika kosong)
    // Tidak mengisi sekarang karena belum tahu produk; BJ tetap kosong hingga user pilih produk

    // Prefill cm_awal/saldo_awal_kg jika ada data H-1 untuk kombinasi (tank_name + product) — dilakukan setelah user pilih nilai
    const tankInput = tr.querySelector('.tank_name');
    const prodSelect = tr.querySelector('.product_id');
    function tryPrefillPrev() {
      const key = (tankInput.value||'') + '|' + (prodSelect.value||'');
      const prev = prevMap[key];
      if (prev) {
        tr.querySelector('.cm_awal').value = prev.cm_akhir || 0;
        tr.querySelector('.saldo_awal_kg').value = prev.kg_akhir || 0;
      }
    }
    tankInput.addEventListener('change', tryPrefillPrev);
    prodSelect.addEventListener('change', tryPrefillPrev);

    tbody.appendChild(tr);
    attachRowEvents(tr);
  });

  // Attach events for existing rows
  tbody.querySelectorAll('tr').forEach(tr => {
    if (!tr.classList.contains('empty-row')) attachRowEvents(tr);
  });

  // First sync on load
  syncFisikToday();

  // Serialize sebelum submit
  document.getElementById('opnameTanksForm')?.addEventListener('submit', (e) => {
    serializeRows();
  });
});
</script>


