<?php
require_once __DIR__ . '/security_bootstrap.php';
require_auth();
require_once 'koneksi.php';

$selected_date = $_GET['date'] ?? '';
$selected_product_id = $_GET['product_id'] ?? '';

if (empty($selected_date)) {
    die('Tanggal harus dipilih untuk export.');
}

$sql_outgoing_by_date = "SELECT DISTINCT o.incoming_transaction_id 
                        FROM outgoing_transactions o 
                        WHERE DATE(o.transaction_date) = ?";
$params_date = [$selected_date];

if (!empty($selected_product_id)) {
    $sql_outgoing_by_date .= " AND o.product_id = ?";
    $params_date[] = $selected_product_id;
}

$stmt_outgoing_date = $pdo->prepare($sql_outgoing_by_date);
$stmt_outgoing_date->execute($params_date);
$outgoing_batch_ids = $stmt_outgoing_date->fetchAll(PDO::FETCH_COLUMN);

if (empty($outgoing_batch_ids)) {
    die('Tidak ada pengeluaran pada tanggal tersebut.');
}

$placeholders = str_repeat('?,', count($outgoing_batch_ids) - 1) . '?';
$sql_incoming_for_outgoing = "SELECT t.*, p.product_name, p.sku 
                             FROM incoming_transactions t 
                             JOIN products p ON t.product_id = p.id 
                             WHERE t.id IN ($placeholders)
                             ORDER BY p.product_name ASC, t.created_at ASC";

$stmt_incoming_for_outgoing = $pdo->prepare($sql_incoming_for_outgoing);
$stmt_incoming_for_outgoing->execute($outgoing_batch_ids);
$incoming_for_outgoing = $stmt_incoming_for_outgoing->fetchAll(PDO::FETCH_ASSOC);

$filename = 'Stock_Jalur_' . date('Y-m-d', strtotime($selected_date)) . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename=' . $filename);
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

echo "\xEF\xBB\xBF"; // UTF-8 BOM

?>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        table { border-collapse: collapse; font-family: Arial, sans-serif; font-size: 12px; }
        th, td { border: 1px solid #000; padding: 6px 8px; vertical-align: middle; }
        th { background: #dbe5f1; font-weight: bold; text-align: center; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
    </style>
    <title>Export Stock Jalur</title>
    <!-- Excel will honor colspan/rowspan merges in HTML tables -->
    <!-- Generated at: <?= date('Y-m-d H:i:s') ?> -->
    <!-- Selected date: <?= htmlspecialchars($selected_date) ?> -->
</head>
<body>
<?php
$rows = [];
$maxShipments = 0;
foreach ($incoming_for_outgoing as $incoming) {
    $stmt_out = $pdo->prepare("SELECT * FROM outgoing_transactions WHERE incoming_transaction_id = ? ORDER BY transaction_date ASC, created_at ASC");
    $stmt_out->execute([$incoming['id']]);
    $outgoing_all = $stmt_out->fetchAll(PDO::FETCH_ASSOC);

    $shipments = [];
    $remaining = (float)$incoming['quantity_sacks'];
    foreach ($outgoing_all as $o) {
        $qty = (float)$o['quantity_sacks'];
        $remaining -= $qty;
        $shipments[] = [
            'date' => date('d/m/Y', strtotime($o['transaction_date'])),
            'qty'  => $qty,
            'sisa' => $remaining < 0 ? 0 : $remaining,
        ];
    }
    $maxShipments = max($maxShipments, count($shipments));

    $rows[] = [
        'incoming' => $incoming,
        'shipments' => $shipments,
    ];
}

$pengirimanCols = 1 + max(1, $maxShipments);
?>
<table>
    <tr>
        <th>tanggal transaksi</th>
        <th>nama barang</th>
        <th>nomor po</th>
        <th>supplier</th>
        <th>jumlah sak</th>
        <th colspan="<?= $pengirimanCols ?>">Pengiriman</th>
    </tr>
    <?php foreach ($rows as $row): 
        $incoming = $row['incoming'];
        $shipments = $row['shipments'];
        $fill = $pengirimanCols - 1 - count($shipments); // remaining blank shipment columns
    ?>
    <tr>
        <td class="center" rowspan="3"><?= date('d/m/Y', strtotime($incoming['transaction_date'])) ?></td>
        <td rowspan="3"><?= htmlspecialchars($incoming['product_name']) ?></td>
        <td class="center" rowspan="3"><?= htmlspecialchars($incoming['po_number'] ?? '') ?></td>
        <td rowspan="3"><?= htmlspecialchars($incoming['supplier'] ?? '') ?></td>
        <td class="center" rowspan="3"><?= (string)(float)$incoming['quantity_sacks'] ?> sak</td>
        <td class="bold">tgl</td>
        <?php foreach ($shipments as $s): ?><td class="center"><?= $s['date'] ?></td><?php endforeach; ?>
        <?php for ($i=0; $i<$fill; $i++): ?><td></td><?php endfor; ?>
    </tr>
    <tr>
        <td class="bold">jumlah pengiriman</td>
        <?php foreach ($shipments as $s): ?><td class="center"><?= (string)$s['qty'] ?></td><?php endforeach; ?>
        <?php for ($i=0; $i<$fill; $i++): ?><td></td><?php endfor; ?>
    </tr>
    <tr>
        <td class="bold">sisa stock</td>
        <?php foreach ($shipments as $s): ?><td class="center"><?= (string)$s['sisa'] ?></td><?php endforeach; ?>
        <?php for ($i=0; $i<$fill; $i++): ?><td></td><?php endfor; ?>
    </tr>
    <?php endforeach; ?>
</table>
</body>
</html>
