<?php
session_start();
include 'koneksi.php';

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$login_status = ''; // 'success', 'failed', 'empty'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nik = $_POST['nik'];
    $plant = $_POST['plant'];
    $password = $_POST['password'];

    if (empty($nik) || empty($plant) || empty($password)) {
        $login_status = 'empty';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE nik = ? AND plant = ?");
            $stmt->execute([$nik, $plant]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nik'] = $user['nik'];
                $_SESSION['user_nama'] = $user['nama_lengkap'];
                $_SESSION['user_role'] = $user['role'];

                $login_status = 'success';
            } else {
                $login_status = 'failed';
            }
        } catch (PDOException $e) {
            $login_status = 'db_error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Manajemen Stok</title>
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --bg-start: #0f172a; /* slate-900 */
            --bg-end: #1f2937;   /* gray-800 */
            --card-bg: #111827;  /* gray-900 */
            --card-border: rgba(255,255,255,0.06);
            --text: #e5e7eb;     /* gray-200 */
            --muted: #9ca3af;    /* gray-400 */
            --accent: #6366f1;   /* indigo-500 */
            --accent-2: #22c55e; /* green-500 */
        }
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: radial-gradient(1200px 600px at 10% 10%, rgba(99,102,241,0.08), transparent 50%),
                        radial-gradient(1000px 500px at 90% 90%, rgba(34,197,94,0.07), transparent 50%),
                        linear-gradient(135deg, var(--bg-start), var(--bg-end));
            color: var(--text);
        }
        .login-card {
            max-width: 420px;
            width: 100%;
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 18px;
            overflow: hidden;
        }
        .brand-title {
            color: var(--text);
        }
        .brand-subtitle {
            color: var(--muted);
        }
        .form-label { color: var(--text); letter-spacing: .02em; }
        .text-muted { color: var(--muted) !important; }
        .input-group-text {
            background: rgba(255,255,255,0.06);
            border: 1px solid var(--card-border);
            color: var(--muted);
        }
        .form-control {
            background: rgba(255,255,255,0.04);
            border: 1px solid var(--card-border);
            color: var(--text);
        }
        .form-control::placeholder { color: #9ca3af; }
        .form-control:focus {
            border-color: rgba(99,102,241,0.5);
            box-shadow: 0 0 0 .25rem rgba(99,102,241,0.15);
            background: rgba(255,255,255,0.06);
            color: var(--text);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--accent), #8b5cf6);
            border: 0;
        }
        .btn-primary:hover {
            filter: brightness(1.06);
        }
        .logo-wrap img { filter: drop-shadow(0 6px 24px rgba(99,102,241,0.35)); border-radius: 10px; }
    </style>
</head>

<body>
    <div class="card login-card shadow-lg border-0">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <div class="logo-wrap mb-2">
                    <img src="images/mayora.jpg" alt="Logo Perusahaan" style="width: 110px; height: auto;">
                </div>
                <h3 class="fw-bold mt-1 brand-title">Mayora Portal</h3>
                <p class="brand-subtitle">Silakan login untuk melanjutkan</p>
            </div>

            <form action="login.php" method="POST" id="loginForm">
                <div class="mb-3">
                    <label for="nik" class="form-label small text-uppercase">NIK</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-badge"></i></span>
                        <input type="text" class="form-control" id="nik" name="nik" placeholder="Masukkan NIK" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="plant" class="form-label small text-uppercase">PLANT</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-buildings"></i></span>
                        <input type="text" class="form-control" id="plant" name="plant" placeholder="Masukkan PLANT" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label small text-uppercase">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-shield-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan Password" required>
                    </div>
                </div>
                <div class="d-grid">
                    <button class="btn btn-primary btn-lg" type="submit">Login</button>
                </div>
            </form>
        </div>
    </div>

    <script src="js/bootstrap.bundle.min.js"></script>

    <?php if ($login_status): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                <?php if ($login_status === 'success'): ?>
                    Swal.fire({
                        title: 'Login Berhasil!',
                        text: 'Selamat datang kembali, <?= htmlspecialchars($_SESSION['user_nama']) ?>!',
                        icon: 'success',
                        timer: 2000,
                        timerProgressBar: true,
                        showConfirmButton: false,
                        didOpen: () => {
                            Swal.showLoading()
                        }
                    }).then(() => {
                        window.location.href = 'index.php?page=beranda';
                    });
                <?php elseif ($login_status === 'failed'): ?>
                    Swal.fire({
                        title: 'Login Gagal!',
                        text: 'NIK, Plant, atau Password yang Anda masukkan salah.',
                        icon: 'error',
                        confirmButtonColor: '#d33',
                        confirmButtonText: 'Coba Lagi'
                    });
                <?php elseif ($login_status === 'empty'): ?>
                    Swal.fire({
                        title: 'Input Tidak Lengkap!',
                        text: 'Mohon isi semua kolom yang tersedia.',
                        icon: 'warning',
                        confirmButtonColor: '#ffc107',
                        confirmButtonText: 'Baik'
                    });
                <?php endif; ?>
            });
        </script>
    <?php endif; ?>

</body>

</html>