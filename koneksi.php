<?php
// Include guard untuk mencegah multiple inclusion
if (defined('KONEKSI_INCLUDED')) {
    return;
}
define('KONEKSI_INCLUDED', true);

/**
 * Database Connection Configuration
 * 
 * @var string $host Database host
 * @var string $db Database name
 * @var string $user Database username
 * @var string $pass Database password
 * @var string $charset Database charset
 * @var PDO $pdo Database connection object
 */

$host = 'localhost';
$db   = 'opname'; // Ganti den gan nama database Anda
$user = 'root'; // Ganti dengan username database Anda
$pass = ''; // Ganti dengan password database Anda
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    throw new PDOException($e->getMessage(), (int)$e->getCode());
}

// Ensure numeric columns preserve up to 6 decimal places (no unintended rounding)
try {
    $columnsToEnsure = [
        'incoming_transactions' => ['quantity_kg', 'quantity_sacks', 'lot_number', 'grossweight_kg'],
        'outgoing_transactions' => ['quantity_kg', 'quantity_sacks', 'lot_number'],
    ];

    foreach ($columnsToEnsure as $table => $cols) {
        foreach ($cols as $col) {
            $stmt = $pdo->prepare("SELECT DATA_TYPE, NUMERIC_PRECISION, NUMERIC_SCALE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $stmt->execute([$table, $col]);
            $info = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$info) { continue; }
            $dataType = strtolower($info['DATA_TYPE'] ?? '');
            $precision = (int)($info['NUMERIC_PRECISION'] ?? 0);
            $scale = (int)($info['NUMERIC_SCALE'] ?? 0);
            if ($dataType !== 'decimal' || $precision < 18 || $scale < 6) {
                $pdo->exec("ALTER TABLE `$table` MODIFY `$col` DECIMAL(18,6) NOT NULL DEFAULT 0");
            }
        }
    }
} catch (Throwable $e) {
    // Ignore migration failures to avoid blocking app usage; can be run manually via scripts/migrate_precision.php
}

// Ensure opening_stock_overrides table exists (used by daily report overrides)
try {
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
} catch (Throwable $e) {
    // If creation fails, the app will continue; the report page should handle absence gracefully
}

/**
 * Format angka untuk tampilan
 * 
 * @param mixed $angka Angka yang akan diformat
 * @return string Angka yang sudah diformat
 */
function formatAngka($angka) {
    if ($angka === null || $angka === '') {
        return '';
    }
    // Pertahankan presisi asli: jika string berisi koma/titik desimal, jangan pangkas
    // Normalisasi: jika nilai adalah numeric, format dengan maksimum 3 desimal tanpa membuang nol signifikan
    if (is_string($angka)) {
        $trimmed = trim($angka);
        // Normalisasi untuk pengecekan numerik
        $normalized = str_replace(',', '.', $trimmed);
        if (is_numeric($normalized)) {
            // Tentukan panjang desimal asli setelah menghapus nol di akhir
            $decSepPos = strrpos($trimmed, ',');
            if ($decSepPos === false) { $decSepPos = strrpos($trimmed, '.'); }
            if ($decSepPos !== false) {
                $decPart = substr($trimmed, $decSepPos + 1);
                // Hapus trailing zeros
                $decPart = rtrim($decPart, '0');
                $decLen = strlen($decPart);
                $decLen = max(0, min(6, $decLen));
                $num = (float)$normalized;
                // Gunakan titik sebagai pemisah desimal untuk ekspor; tidak pakai pemisah ribuan
                return number_format($num, $decLen, '.', '');
            }
            // Tidak ada desimal
            return str_replace('.', ',', (string)(0 + $normalized));
        }
        return $trimmed;
    }
    // Numeric non-string: tampilkan tanpa desimal jika bilangan bulat, atau hingga 6 desimal jika ada fraksi
    $num = (float)$angka;
    $isInteger = floor($num) == $num;
    $decimals = $isInteger ? 0 : 6;
    // Pangkas nol di akhir dengan memformat 6 desimal lalu trim
    $str = number_format($num, $decimals, ',', '');
    if ($decimals > 0) {
        $str = rtrim($str, '0');
        $str = rtrim($str, ',');
    }
    return $str;
}

/**
 * Format angka untuk UI (tampilan) menggunakan koma sebagai pemisah desimal,
 * mempertahankan jumlah digit desimal asli tanpa pembulatan berlebih.
 */
function formatAngkaUI($angka) {
    if ($angka === null || $angka === '') return '';
    // Jadikan string agar bisa menghitung panjang desimal asli
    $raw = (string)$angka;
    $raw = trim($raw);
    // Normalisasi untuk validasi numerik
    $normalized = str_replace(',', '.', $raw);
    if (!is_numeric($normalized)) return $raw;
    // Cari separator desimal asli (',' atau '.')
    $posComma = strrpos($raw, ',');
    $posDot = strrpos($raw, '.');
    $sepPos = $posComma !== false ? $posComma : $posDot;
    $decLen = 0;
    if ($sepPos !== false) {
        $decPart = substr($raw, $sepPos + 1);
        // Hilangkan trailing zero di UI agar tidak menampilkan nol berlebih
        $decPart = rtrim($decPart, '0');
        $decLen = strlen($decPart);
        $decLen = max(0, min(12, $decLen));
    }
    $num = (float)$normalized;
    if ($decLen <= 0) {
        // Tanpa desimal
        return str_replace('.', ',', (string)$num);
    }
    // Format dengan koma sebagai pemisah desimal dan tanpa pemisah ribuan
    $formatted = number_format($num, $decLen, ',', '');
    return $formatted;
}
?>
