<?php
include 'koneksi.php';

// Helper pemformatan angka lokal (Indonesian style)
function formatAngkaLocal($angka) {
    if ($angka === null || $angka === '') return '0,00';
    $nomor = (float)$angka;
    return number_format($nomor, 2, ',', '.');
}

$filter_date = $_GET['filter_date'] ?? date('Y-m-d');
$filter_qty_kg = $_GET['filter_qty_kg'] ?? '';
$hide_zero_closing = isset($_GET['hide_zero_closing']) ? (bool)$_GET['hide_zero_closing'] : false;

// Metadata opsional (untuk header cetak)
$plant = $_GET['plant'] ?? '1115';
$dept  = $_GET['dept']  ?? 'GDRM (GUDANG BAHAN BAKU)';

// Formatter tanggal panjang Indonesia
function formatTanggalIndoPanjang($date) {
    $hari = ["Minggu","Senin","Selasa","Rabu","Kamis","Jumat","Sabtu"];
    $bulan = [1=>"Januari","Februari","Maret","April","Mei","Juni","Juli","Agustus","September","Oktober","November","Desember"];
    $ts = strtotime($date);
    $namaHari = $hari[(int)date('w', $ts)];
    $tgl = (int)date('j', $ts);
    $namaBulan = $bulan[(int)date('n', $ts)];
    $tahun = date('Y', $ts);
    return "$namaHari, $tgl $namaBulan $tahun";
}

// Hitung tanggal sebelumnya (H-1)
$previous_date = date('Y-m-d', strtotime($filter_date . ' -1 day'));

$sql = "
    SELECT
        p.id, p.sku, p.product_name,
        (SUM(CASE WHEN t.type = 'IN' AND t.transaction_date < ? THEN t.quantity_kg ELSE 0 END) -
         SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date < ? THEN t.quantity_kg ELSE 0 END)) AS opening_stock_kg,
        (SUM(CASE WHEN t.type = 'IN' AND t.transaction_date < ? THEN t.quantity_sacks ELSE 0 END) -
         SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date < ? THEN t.quantity_sacks ELSE 0 END)) AS opening_stock_sak,
        SUM(CASE WHEN t.type = 'IN' AND t.transaction_date = ? THEN t.quantity_kg ELSE 0 END) AS incoming_kg_today,
        SUM(CASE WHEN t.type = 'IN' AND t.transaction_date = ? THEN t.quantity_sacks ELSE 0 END) AS incoming_sak_today,
        SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date = ? THEN t.quantity_kg ELSE 0 END) AS outgoing_kg_today,
        SUM(CASE WHEN t.type = 'OUT' AND t.transaction_date = ? THEN t.quantity_sacks ELSE 0 END) AS outgoing_sak_today
    FROM products p
    LEFT JOIN (
        SELECT product_id, transaction_date, quantity_kg, quantity_sacks, 'IN'  AS type FROM incoming_transactions
        UNION ALL
        SELECT product_id, transaction_date, quantity_kg, quantity_sacks, 'OUT' AS type FROM outgoing_transactions
    ) AS t ON p.id = t.product_id
    GROUP BY p.id, p.sku, p.product_name
    ORDER BY p.product_name ASC
";

$stmt = $pdo->prepare($sql);
$params = [
    $previous_date, $previous_date, $previous_date, $previous_date,
    $previous_date, $previous_date, $previous_date, $previous_date,
];
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Proses data
$data = [];
foreach ($rows as $row) {
    $closing_stock_kg = ($row['opening_stock_kg'] ?? 0) + ($row['incoming_kg_today'] ?? 0) - ($row['outgoing_kg_today'] ?? 0);
    $closing_stock_sak = ($row['opening_stock_sak'] ?? 0) + ($row['incoming_sak_today'] ?? 0) - ($row['outgoing_sak_today'] ?? 0);

    if (is_numeric($filter_qty_kg) && $closing_stock_kg < (float)$filter_qty_kg) {
        continue;
    }
    // Permintaan: baris dengan Qty Kg stok akhir = 0 tidak dicetak
    if ($closing_stock_kg <= 0) {
        continue;
    }

    $average_qty = ($closing_stock_sak != 0) ? $closing_stock_kg / $closing_stock_sak : 0;

    $data[] = [
        'sku' => $row['sku'] ?? '',
        'product_name' => $row['product_name'] ?? '',
        'closing_stock_kg' => $closing_stock_kg,
        'closing_stock_sak' => $closing_stock_sak,
        'average_qty' => $average_qty,
    ];
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Print Laporan Stock Opname Harian</title>
  <link rel="stylesheet" href="css/bootstrap.min.css">
  <style>
    :root { --teal:#28a39b; }
    /* upaya meminimalkan header/footer default dari browser */
    @page { size: A4 portrait; margin: 8mm 6mm; }
    body { background: #fff; font-size: 11px; }
    .heading { background: var(--teal); color:#fff; font-weight:800; text-transform:uppercase; letter-spacing:.5px; padding:10px 14px; border-radius:6px; margin-bottom:12px; text-align:center; }
    .meta-table th { width: 120px; }
    .meta-table td { font-weight: 600; }
    table.table-bordered>thead>tr>th, table.table-bordered>tbody>tr>td { border: 1px solid #000 !important; vertical-align: middle !important; }
    table.table-bordered th, table.table-bordered td { padding: 4px 6px !important; }
    .td-nowrap { white-space: nowrap !important; }
    .name-cell { white-space: nowrap !important; }
    .w-no { width: 40px; }
    .w-code { width: 120px; }
    .w-zak { width: 70px; }
    .w-kg { width: 90px; }
    .w-avg { width: 80px; }
    .no-print { display: block; margin-bottom: 12px; }
    @media print {
      .no-print { display: none !important; }
      a[href]:after { content: "" !important; }
      body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      /* Catatan: beberapa engine mendukung margin box rules, namun tidak semua.
         Dihapus demi kompatibilitas linter. Header/footer tetap diminimalkan via margin @page. */
    }
    .group-header { background: #e9f7f6; font-weight: 700; border: 1px solid #000 !important; }
    .th-teal { background: #d1f2f0; font-weight: 700; }
    .signature-title { font-weight: 600; }
    .signature-box { height: 80px; border-bottom: 1px dotted #000; margin: 24px 12px 8px; }
  </style>
  <script>
    // Upaya menghilangkan judul di header printer: kosongkan title saat mencetak
    const __originalTitle = document.title;
    window.addEventListener('beforeprint', () => { document.title = ' '; });
    window.addEventListener('afterprint', () => { document.title = __originalTitle; });
    window.addEventListener('load', function(){
      window.print();
    });
  </script>
  </head>
<body>
  <div class="container-fluid p-4">
    <div class="heading">Laporan Stock Opname Harian</div>
    <div class="row g-3 align-items-center mb-3">
      <div class="col-12 col-md-8">
        <table class="table table-sm table-borderless meta-table mb-0">
          <tbody>
            <tr>
              <th>Tanggal</th>
              <td><?php echo htmlspecialchars(formatTanggalIndoPanjang($filter_date)); ?></td>
            </tr>
            <tr>
              <th>Plant</th>
              <td><?php echo htmlspecialchars($plant); ?></td>
            </tr>
            <tr>
              <th>Dept.</th>
              <td><?php echo htmlspecialchars($dept); ?></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="col-12 col-md-4 text-end">
        <button class="btn btn-secondary no-print" onclick="window.print()"><i class="bi bi-printer-fill"></i> Cetak</button>
      </div>
    </div>

    <table class="table table-sm table-bordered" style="table-layout:auto; width:100%;">
      <thead>
        <tr class="group-header text-center">
          <th rowspan="2" class="align-middle w-no">No</th>
          <th rowspan="2" class="align-middle w-code">Kode Barang</th>
          <th rowspan="2" class="text-start align-middle">Nama Barang</th>
          <th colspan="3" class="text-center">Stock Akhir</th>
        </tr>
        <tr class="text-center th-teal">
          <th class="w-zak">Zak</th>
          <th class="w-kg">Kg</th>
          <th class="w-avg">Rata2</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($data)): ?>
          <tr><td colspan="6" class="text-center py-4">Tidak ada data.</td></tr>
        <?php else: $no = 1; foreach ($data as $row): ?>
          <tr>
            <td class="text-center td-nowrap w-no"><?php echo $no++; ?></td>
            <td class="text-center td-nowrap w-code"><?php echo htmlspecialchars($row['sku']); ?></td>
            <td class="text-start name-cell"><?php echo htmlspecialchars($row['product_name']); ?></td>
            <td class="text-end td-nowrap w-zak"><?php echo formatAngkaLocal($row['closing_stock_sak']); ?></td>
            <td class="text-end td-nowrap w-kg"><?php echo formatAngkaLocal($row['closing_stock_kg']); ?></td>
            <td class="text-end td-nowrap w-avg"><?php echo formatAngkaLocal($row['average_qty']); ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <div class="mt-4">
      <div class="row">
        <div class="col-6 text-center">
          <div class="signature-title">Dibuat</div>
          <div class="signature-box"></div>
          <div class="fw-bold">ADM</div>
        </div>
        <div class="col-6">
          <div class="text-center signature-title">Mengetahui</div>
          <div class="row">
            <div class="col-6 text-center">
              <div class="signature-box"></div>
              <div class="fw-bold">UH/SH GDRM</div>
            </div>
            <div class="col-6 text-center">
              <div class="signature-box"></div>
              <div class="fw-bold">DH WAREHOUSE</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>


