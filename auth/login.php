<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/../db/dbcon.php";
require_once __DIR__ . "/auth_bootstrap.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  $stmt = $con->prepare("SELECT id,name,email,password_hash FROM users WHERE email=?");
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $u = $stmt->get_result()->fetch_assoc();
  if ($u && password_verify($pass, $u['password_hash'])) {
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['owner_key'] = "u:" . (int)$u['id'];
    $_SESSION['flash'] = ['text'=>'Welcome, '.$u['name'],'type'=>'success'];
    header("Location: ../index.php"); exit;
  } else {
    $_SESSION['flash'] = ['text'=>'Invalid credentials','type'=>'danger'];
  }
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<title>Login</title></head><body class="bg-dark text-light">
<div class="container py-5" style="max-width:560px;">
  <h3 class="mb-4">Login</h3>
  <?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert alert-<?=htmlspecialchars($f['type'])?>"><?=htmlspecialchars($f['text'])?></div>
  <?php endif; ?>
  <form method="post">
    <div class="mb-3">
      <label class="form-label">Email</label>
      <input class="form-control" name="email" type="email" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Password</label>
      <input class="form-control" name="password" type="password" required>
    </div>
    <button class="btn btn-primary">Login</button>
    <a class="btn btn-outline-secondary" href="signup.php">Create Account</a>
    <a class="btn btn-outline-info" href="guest.php">Continue as Guest</a>
  </form>
</div></body></html>
