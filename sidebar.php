<?php
// Variabel ini diambil dari index.php untuk menandai menu mana yang sedang aktif.
$active_page = $active_page ?? '';
?>

<div class="offcanvas offcanvas-start text-white bg-dark" tabindex="-1" id="sidebar" aria-labelledby="sidebarLabel">

    <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="sidebarLabel">
            <a href="index.php?page=beranda" class="d-flex align-items-center text-white text-decoration-none">
                <i class="bi bi-box-seam-fill me-2 fs-4"></i>
                <span class="fs-4">Manajemen Stok</span>
            </a>
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body d-flex flex-column p-3 pt-0">
        <hr class="text-white mt-0">
        <ul class="nav nav-pills flex-column mb-auto">
            <li class="nav-item mb-1">
                <a href="index.php?page=beranda" class="nav-link <?php echo ($active_page == 'beranda') ? 'active' : 'text-white'; ?>">
                    <i class="bi bi-house-door me-2"></i> Beranda
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="index.php?page=daftar_produk" class="nav-link <?php echo ($active_page == 'daftar_produk') ? 'active' : 'text-white'; ?>">
                    <i class="bi bi-grid me-2"></i> Daftar Produk
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="index.php?page=barang_masuk" class="nav-link <?php echo ($active_page == 'barang_masuk') ? 'active' : 'text-white'; ?>">
                    <i class="bi bi-box-arrow-in-down me-2"></i> Barang Masuk
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="index.php?page=barang_keluar" class="nav-link <?php echo ($active_page == 'barang_keluar') ? 'active' : 'text-white'; ?>">
                    <i class="bi bi-box-arrow-up me-2"></i> Barang Keluar
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="index.php?page=laporan" class="nav-link <?php echo ($active_page == 'laporan') ? 'active' : 'text-white'; ?>">
                    <i class="bi bi-file-earmark-bar-graph me-2"></i> Laporan Harian
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="index.php?page=report_stock" class="nav-link <?php echo ($active_page == 'report_stock') ? 'active' : 'text-white'; ?>">
                    <i class="bi bi-graph-up me-2"></i> Report Stock Per Item
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="index.php?page=stock_jalur" class="nav-link <?php echo ($active_page == 'stock_jalur') ? 'active' : 'text-white'; ?>">
                    <i class="bi bi-card-checklist me-2"></i> Kartu Stok
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="index.php?page=unloading" class="nav-link <?php echo ($active_page == 'unloading') ? 'active' : 'text-white'; ?>">
                    <i class="bi bi-truck me-2"></i> Unloading
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="index.php?page=opname_minyak" class="nav-link <?php echo ($active_page == 'opname_minyak') ? 'active' : 'text-white'; ?>">
                    <i class="bi bi-droplet-half me-2"></i> Opname Minyak
                </a>
            </li>
            <li class="nav-item mb-1">
                <a href="index.php?page=backup_cleanup" class="nav-link <?php echo ($active_page == 'backup_cleanup') ? 'active' : 'text-white'; ?>">
                    <i class="bi bi-archive me-2"></i> Backup & Cleanup
                </a>
            </li>
        </ul>
        <hr class="text-white">
        <div class="d-grid">
            <a href="logout.php" class="btn btn-danger"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
        </div>
    </div>
</div>