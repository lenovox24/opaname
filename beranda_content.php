<?php
$sql_total_produk = "SELECT COUNT(*) as total FROM products";
$stmt_total_produk = $pdo->prepare($sql_total_produk);
$stmt_total_produk->execute();
$totalProduk = $stmt_total_produk->fetch(PDO::FETCH_ASSOC)['total'];

$sql_barang_masuk_harian = "
    SELECT COUNT(*) as total 
    FROM incoming_transactions 
    WHERE transaction_date = CURDATE()
";
$stmt_barang_masuk_harian = $pdo->prepare($sql_barang_masuk_harian);
$stmt_barang_masuk_harian->execute();
$totalBarangMasukHarian = $stmt_barang_masuk_harian->fetch(PDO::FETCH_ASSOC)['total'];

$sql_barang_keluar_harian = "
    SELECT COUNT(*) as total 
    FROM outgoing_transactions 
    WHERE transaction_date = CURDATE()
";
$stmt_barang_keluar_harian = $pdo->prepare($sql_barang_keluar_harian);
$stmt_barang_keluar_harian->execute();
$totalBarangKeluarHarian = $stmt_barang_keluar_harian->fetch(PDO::FETCH_ASSOC)['total'];
?>

<div class="container-fluid modern-dashboard">
    <!-- Hero Section -->
    <div class="row mb-5">
        <div class="col-lg-12">
            <div class="hero-card animate-fade-in">
                <div class="hero-content">
                    <div class="hero-icon animate-bounce">
                        <i class="bi bi-boxes"></i>
                    </div>
                    <h1 class="hero-title animate-slide-up">Stock Opname GDRM</h1>
                    <div class="hero-stats animate-fade-in-delay">
                        <div class="stat-item">
                            <span class="stat-number" data-count="<?php echo $totalProduk; ?>">0</span>
                            <span class="stat-label">Total Produk</span>
                        </div>
                        <div class="stat-divider"></div>
                        <div class="stat-item">
                            <span class="stat-number" data-count="<?php echo $totalBarangMasukHarian; ?>">0</span>
                            <span class="stat-label">Masuk Hari Ini</span>
                        </div>
                        <div class="stat-divider"></div>
                        <div class="stat-item">
                            <span class="stat-number" data-count="<?php echo $totalBarangKeluarHarian; ?>">0</span>
                            <span class="stat-label">Keluar Hari Ini</span>
                        </div>
                    </div>
                </div>
                <div class="hero-bg-shapes">
                    <div class="shape shape-1"></div>
                    <div class="shape shape-2"></div>
                    <div class="shape shape-3"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions Section -->
    <div class="row mb-5">
        <div class="col-lg-12">
            <h3 class="section-title animate-slide-in-left">Aksi Cepat</h3>
            <div class="quick-actions-grid">
                <a href="index.php?page=barang_masuk" class="quick-action-card animate-scale-in" style="animation-delay: 0.1s">
                    <div class="action-icon bg-success">
                        <i class="bi bi-arrow-down-circle-fill"></i>
                    </div>
                    <h4>Barang Masuk</h4>
                    <p>Catat transaksi barang masuk</p>
                    <div class="action-arrow">
                        <i class="bi bi-chevron-right"></i>
                    </div>
                </a>
                
                <a href="index.php?page=barang_keluar" class="quick-action-card animate-scale-in" style="animation-delay: 0.2s">
                    <div class="action-icon bg-danger">
                        <i class="bi bi-arrow-up-circle-fill"></i>
                    </div>
                    <h4>Barang Keluar</h4>
                    <p>Catat transaksi barang keluar</p>
                    <div class="action-arrow">
                        <i class="bi bi-chevron-right"></i>
                    </div>
                </a>
                
                <a href="index.php?page=daftar_produk" class="quick-action-card animate-scale-in" style="animation-delay: 0.3s">
                    <div class="action-icon bg-primary">
                        <i class="bi bi-box-fill"></i>
                    </div>
                    <h4>Kelola Produk</h4>
                    <p>Tambah & edit produk</p>
                    <div class="action-arrow">
                        <i class="bi bi-chevron-right"></i>
                    </div>
                </a>
                
                <a href="index.php?page=laporan" class="quick-action-card animate-scale-in" style="animation-delay: 0.4s">
                    <div class="action-icon bg-warning">
                        <i class="bi bi-bar-chart-fill"></i>
                    </div>
                    <h4>Laporan</h4>
                    <p>Lihat laporan stok harian</p>
                    <div class="action-arrow">
                        <i class="bi bi-chevron-right"></i>
                    </div>
                </a>
            </div>
        </div>
    </div>
</div>

<style>

.modern-dashboard {
    background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
    min-height: 100vh;
    padding: 2rem 0;
}


.hero-card {
    position: relative;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 25px;
    padding: 4rem 2rem;
    color: white;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(102, 126, 234, 0.3);
}

.hero-content {
    position: relative;
    z-index: 2;
    text-align: center;
}

.hero-icon {
    font-size: 4rem;
    margin-bottom: 1.5rem;
    opacity: 0.9;
}

.hero-title {
    font-size: 3.5rem;
    font-weight: 700;
    margin-bottom: 1rem;
    text-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.hero-subtitle {
    font-size: 1.3rem;
    opacity: 0.9;
    margin-bottom: 3rem;
    font-weight: 300;
}

.hero-stats {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 2rem;
    flex-wrap: wrap;
}

.stat-item {
    text-align: center;
}

.stat-number {
    display: block;
    font-size: 2.5rem;
    font-weight: 700;
    color: #fff;
}

.stat-label {
    display: block;
    font-size: 0.9rem;
    opacity: 0.8;
    margin-top: 0.5rem;
}

.stat-divider {
    width: 2px;
    height: 60px;
    background: rgba(255,255,255,0.3);
}


.hero-bg-shapes {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    overflow: hidden;
    z-index: 1;
}

.shape {
    position: absolute;
    border-radius: 50%;
    background: rgba(255,255,255,0.1);
    animation: float 6s ease-in-out infinite;
}

.shape-1 {
    width: 100px;
    height: 100px;
    top: 20%;
    left: 10%;
    animation-delay: 0s;
}

.shape-2 {
    width: 150px;
    height: 150px;
    top: 60%;
    right: 10%;
    animation-delay: 2s;
}

.shape-3 {
    width: 80px;
    height: 80px;
    bottom: 20%;
    left: 20%;
    animation-delay: 4s;
}


.section-title {
    font-size: 2rem;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 2rem;
    position: relative;
}

.section-title::after {
    content: '';
    position: absolute;
    bottom: -10px;
    left: 0;
    width: 60px;
    height: 4px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 2px;
}


.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 2rem;
    margin-top: 2rem;
}

.quick-action-card {
    background: white;
    border-radius: 20px;
    padding: 2rem;
    text-decoration: none;
    color: inherit;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(0,0,0,0.05);
}

.quick-action-card:hover {
    transform: translateY(-10px);
    box-shadow: 0 20px 40px rgba(0,0,0,0.15);
    text-decoration: none;
    color: inherit;
}

.quick-action-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    transform: scaleX(0);
    transition: transform 0.3s ease;
}

.quick-action-card:hover::before {
    transform: scaleX(1);
}

.action-icon {
    width: 60px;
    height: 60px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.5rem;
    font-size: 1.5rem;
    color: white;
}

.quick-action-card h4 {
    font-size: 1.3rem;
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #2d3748;
}

.quick-action-card p {
    color: #718096;
    margin-bottom: 1.5rem;
    font-size: 0.95rem;
}

.action-arrow {
    position: absolute;
    top: 2rem;
    right: 2rem;
    font-size: 1.2rem;
    color: #cbd5e0;
    transition: all 0.3s ease;
}

.quick-action-card:hover .action-arrow {
    color: #667eea;
    transform: translateX(5px);
}


@keyframes fadeIn {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes slideUp {
    from { opacity: 0; transform: translateY(50px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes slideInLeft {
    from { opacity: 0; transform: translateX(-50px); }
    to { opacity: 1; transform: translateX(0); }
}

@keyframes scaleIn {
    from { opacity: 0; transform: scale(0.8); }
    to { opacity: 1; transform: scale(1); }
}

@keyframes bounce {
    0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
    40% { transform: translateY(-10px); }
    60% { transform: translateY(-5px); }
}

@keyframes float {
    0%, 100% { transform: translateY(0px) rotate(0deg); }
    50% { transform: translateY(-20px) rotate(180deg); }
}

@keyframes countUp {
    from { opacity: 0; }
    to { opacity: 1; }
}


.animate-fade-in {
    animation: fadeIn 1s ease-out;
}

.animate-slide-up {
    animation: slideUp 1s ease-out 0.3s both;
}

.animate-slide-up-delay {
    animation: slideUp 1s ease-out 0.6s both;
}

.animate-fade-in-delay {
    animation: fadeIn 1s ease-out 0.9s both;
}

.animate-slide-in-left {
    animation: slideInLeft 1s ease-out;
}

.animate-scale-in {
    animation: scaleIn 0.6s ease-out both;
}

.animate-bounce {
    animation: bounce 2s infinite;
}


@media (max-width: 768px) {
    .hero-title {
        font-size: 2.5rem;
    }
    
    .hero-stats {
        flex-direction: column;
        gap: 1rem;
    }
    
    .stat-divider {
        width: 60px;
        height: 2px;
    }
    
    .quick-actions-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const counters = document.querySelectorAll('.stat-number');
    
    counters.forEach(counter => {
        const target = parseInt(counter.getAttribute('data-count'));
        const duration = 2000; // 2 seconds
        const step = target / (duration / 16); // 60fps
        let current = 0;
        
        const timer = setInterval(() => {
            current += step;
            if (current >= target) {
                counter.textContent = target;
                clearInterval(timer);
            } else {
                counter.textContent = Math.floor(current);
            }
        }, 16);
    });
});
</script>
</style>