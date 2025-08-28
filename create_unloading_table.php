<?php
require_once 'koneksi.php';

try {
    $check_table = $pdo->query("SHOW TABLES LIKE 'unloading_records'");
    
    if ($check_table->rowCount() == 0) {
        $create_sql = "
        CREATE TABLE `unloading_records` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `incoming_transaction_id` int(11) DEFAULT NULL,
            `tanggal` date NOT NULL,
            `jam_masuk` time DEFAULT NULL,
            `jam_start_qc` time DEFAULT NULL,
            `jam_finish_qc` time DEFAULT NULL,
            `jam_start_bongkar` time DEFAULT NULL,
            `jam_finish_bongkar` time DEFAULT NULL,
            `jam_keluar` time DEFAULT NULL,
            `durasi_bongkar` int(11) DEFAULT NULL,
            `supplier` varchar(255) DEFAULT NULL,
            `nama_barang` varchar(255) DEFAULT NULL,
            `nomor_mobil` varchar(50) DEFAULT NULL,
            `no_mobil` varchar(50) DEFAULT NULL,
            `qty_sak` decimal(10,2) DEFAULT 0,
            `qty_pallet` int(11) DEFAULT 0,
            `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_incoming_transaction` (`incoming_transaction_id`),
            KEY `idx_tanggal` (`tanggal`),
            FOREIGN KEY (`incoming_transaction_id`) REFERENCES `incoming_transactions` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        
        $pdo->exec($create_sql);
        echo "✅ Tabel unloading_records berhasil dibuat!\n";
    } else {
        echo "ℹ️ Tabel unloading_records sudah ada.\n";
        
        $describe = $pdo->query("DESCRIBE unloading_records");
        $columns = $describe->fetchAll(PDO::FETCH_ASSOC);
        
        echo "\n📋 Struktur tabel saat ini:\n";
        foreach ($columns as $column) {
            echo "- {$column['Field']} ({$column['Type']}) - {$column['Null']} - {$column['Key']}\n";
        }
        
        $existing_columns = array_column($columns, 'Field');
        $required_columns = [
            'nomor_mobil' => 'VARCHAR(50) DEFAULT NULL',
            'no_mobil' => 'VARCHAR(50) DEFAULT NULL',
            'qty_pallet' => 'INT(11) DEFAULT 0',
            'durasi_bongkar' => 'INT(11) DEFAULT NULL',
            'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        ];
        
        foreach ($required_columns as $column => $definition) {
            if (!in_array($column, $existing_columns)) {
                try {
                    $pdo->exec("ALTER TABLE unloading_records ADD COLUMN {$column} {$definition}");
                    echo "✅ Kolom {$column} berhasil ditambahkan.\n";
                } catch (PDOException $e) {
                    echo "❌ Gagal menambahkan kolom {$column}: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    $count = $pdo->query("SELECT COUNT(*) as total FROM unloading_records")->fetch();
    echo "\n📊 Total data di tabel: {$count['total']} record\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
