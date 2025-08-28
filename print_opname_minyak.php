<?php
require_once 'koneksi.php';
$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$previous_date = date('Y-m-d', strtotime($filter_date . ' -1 day'));

if (!function_exists('formatAngka')) {
  function formatAngka($num) {
    if ($num === null || $num === '') return '0';
    return number_format((float)$num, 2, ',', '.');
  }
}

// Kumpulkan data ulang (tanpa include file export) agar tidak mengirim header download

$products = $pdo->query("SELECT id, sku, product_name FROM products ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$productMap = [];
foreach ($products as $p) { $productMap[$p['id']] = $p; }

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

$stmtGR = $pdo->prepare("SELECT product_id, SUM(quantity_kg) as gr_kg FROM incoming_transactions WHERE transaction_date = ? GROUP BY product_id");
$stmtGR->execute([$filter_date]);
$grRows = $stmtGR->fetchAll(PDO::FETCH_ASSOC);
$productToGR = [];
foreach ($grRows as $r) { $productToGR[$r['product_id']] = (float)$r['gr_kg']; }

$stmtTanks = $pdo->prepare("SELECT * FROM opname_tanks WHERE opname_date = ? ORDER BY id ASC");
$stmtTanks->execute([$filter_date]);
$tankRows = $stmtTanks->fetchAll(PDO::FETCH_ASSOC);

$allowedPatterns = ['GLUCOSE DE 64','RBD PALM OLEIN A O BHA','H. FRUCTOSE SYRUP','SORBITOL'];
function nameAllowedLocal($name, $patterns){ $n = mb_strtolower($name); foreach($patterns as $pat){ if (mb_strpos($n, mb_strtolower($pat))!==false) return true; } return false; }

$productToFisik = [];
foreach ($tankRows as $tr) {
  $pid = (int)$tr['product_id'];
  $productToFisik[$pid] = ($productToFisik[$pid] ?? 0) + (float)$tr['kg_akhir'];
}

$relevantProducts = [];
foreach ($products as $p) {
  if (!nameAllowedLocal($p['product_name'], $allowedPatterns)) continue;
  $pid = (int)$p['id'];
  $closing = $productToClosing[$pid] ?? 0;
  $gr = $productToGR[$pid] ?? 0;
  $fisik = $productToFisik[$pid] ?? 0;
  if ($closing != 0 || $gr != 0 || $fisik != 0) $relevantProducts[] = $pid;
}

$stmtLaporan = $pdo->prepare("
  SELECT p.id as product_id, p.sku, p.product_name, 
         COALESCE(SUM(CASE WHEN i.transaction_type = 'incoming' THEN i.quantity_kg ELSE 0 END), 0) as total_incoming,
         COALESCE(SUM(CASE WHEN o.transaction_type = 'outgoing' THEN o.quantity_kg ELSE 0 END), 0) as total_outgoing
  FROM products p
  LEFT JOIN (SELECT product_id, quantity_kg, 'incoming' as transaction_type FROM incoming_transactions WHERE DATE(transaction_date) <= ?) i ON p.id=i.product_id
  LEFT JOIN (SELECT product_id, quantity_kg, 'outgoing' as transaction_type FROM outgoing_transactions WHERE DATE(transaction_date) <= ?) o ON p.id=o.product_id
  WHERE p.id IN (" . implode(',', array_fill(0, count($relevantProducts), '?')) . ")
  GROUP BY p.id, p.sku, p.product_name
");
$params = array_merge([$filter_date, $filter_date], $relevantProducts);
$stmtLaporan->execute($params);
$laporanData = $stmtLaporan->fetchAll(PDO::FETCH_ASSOC);
$productToLaporan = [];
foreach ($laporanData as $ld) {
  $pid = (int)$ld['product_id'];
  $productToLaporan[$pid] = [
    'sku' => $ld['sku'],
    'product_name' => $ld['product_name'],
    'stock_akhir' => (float)$ld['total_incoming'] - (float)$ld['total_outgoing']
  ];
}

$productToBon = [];
foreach ($relevantProducts as $pid) {
  $closing = $productToClosing[$pid] ?? 0;
  $gr = $productToGR[$pid] ?? 0;
  $fisik = $productToFisik[$pid] ?? 0;
  $productToBon[$pid] = ($closing + $gr) - $fisik;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<title>Print - Stock Opname Bahan Cair 1115</title>
<style>
  @page { size: A4 landscape; margin: 8mm; }
  body { font-family: Arial, sans-serif; }
  .title { text-align:center; font-weight:bold; font-size:18px; margin-bottom:6px; }
  .meta { margin-bottom:8px; }
  table { width:100%; border-collapse: collapse; }
  th, td { border:1px solid #000; padding:4px 6px; vertical-align: middle; }
  th { background:#f0f0f0; }
  .text-center { text-align:center; }
  .text-end { text-align:right; }
</style>
</head>
<body>
  <div class="title">STOCK OPNAME BAHAN CAIR 1115</div>
  <div class="meta">Periode: <?= htmlspecialchars(date('d/m/Y', strtotime($filter_date))) ?></div>

  <table>
    <thead>
      <tr>
        <th class="text-center">Kode Barang</th>
        <th>Nama Material</th>
        <th class="text-center">Saldo Awal SAP</th>
        <th class="text-center">GR Kedatangan</th>
        <th class="text-center">Saldo Akhir SAP Sebelum Bon</th>
        <th class="text-center">Stok Fisik Hari Ini</th>
        <th class="text-center">Bon</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($relevantProducts as $pid):
        $p = $productMap[$pid];
        $closing = $productToClosing[$pid] ?? 0;
        $gr = $productToGR[$pid] ?? 0;
        $fisik = $productToFisik[$pid] ?? 0;
        $saldoAkhirSebelumBon = $closing + $gr;
        $bon = $saldoAkhirSebelumBon - $fisik;
      ?>
      <tr>
        <td class="text-center"><code><?= htmlspecialchars($p['sku']) ?></code></td>
        <td><?= htmlspecialchars($p['product_name']) ?></td>
        <td class="text-end"><?= formatAngka($closing) ?></td>
        <td class="text-end"><?= formatAngka($gr) ?></td>
        <td class="text-end"><?= formatAngka($saldoAkhirSebelumBon) ?></td>
        <td class="text-end"><?= formatAngka($fisik) ?></td>
        <td class="text-end"><?= formatAngka($bon) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <br/>

  <div class="title" style="font-size:16px;">Saldo Akhir Volume Tangki - <?= htmlspecialchars(date('d/m/Y', strtotime($filter_date))) ?></div>
  <table>
    <thead>
      <tr>
        <th class="text-center">Tangki</th>
        <th>Nama Material</th>
        <th class="text-center">CM Awal</th>
        <th class="text-center">Saldo Awal KG</th>
        <th class="text-center"><?= date('d/m/y', strtotime($previous_date)) ?></th>
        <th class="text-center">CM</th>
        <th class="text-center">Liter</th>
        <th class="text-center">Kg</th>
        <th class="text-center">BJ</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tankRows as $row): ?>
      <tr>
        <td class="text-center"><?= htmlspecialchars($row['tank_name']) ?></td>
        <td><?= htmlspecialchars($productMap[$row['product_id']]['product_name'] ?? '') ?></td>
        <td class="text-end"><?= formatAngka($row['cm_awal']) ?></td>
        <td class="text-end"><?= formatAngka($row['saldo_awal_kg']) ?></td>
        <td class="text-end"><?= formatAngka($row['input_kg_hari_ini']) ?></td>
        <td class="text-end"><?= formatAngka($row['cm_akhir']) ?></td>
        <td class="text-end"><?= formatAngka($row['liter_akhir']) ?></td>
        <td class="text-end"><?= formatAngka($row['kg_akhir']) ?></td>
        <td class="text-end"><?= number_format((float)$row['bj'], 3, ',', '.') ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <table>
    <thead>
      <tr>
        <th class="text-center">Kode Barang</th>
        <th>Nama Material</th>
        <th class="text-center">Total Bon</th>
        <th class="text-center">Total SAP</th>
        <th class="text-center">Selisih (SAP - Fisik)</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($relevantProducts as $pid): $lap = $productToLaporan[$pid] ?? null; if (!$lap) continue; $stockFisik = $productToFisik[$pid] ?? 0; $totalSap = $lap['stock_akhir']; $selisih = $totalSap - $stockFisik; ?>
      <tr>
        <td class="text-center"><?= htmlspecialchars($lap['sku']) ?></td>
        <td><?= htmlspecialchars($lap['product_name']) ?></td>
        <td class="text-end"><?= formatAngka($productToBon[$pid] ?? 0) ?></td>
        <td class="text-end"><?= formatAngka($totalSap) ?></td>
        <td class="text-end"><?= formatAngka($selisih) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <script>
    window.onload = () => window.print();
  </script>
</body>
</html>
