<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/../db/dbcon.php";
require_once __DIR__ . "/auth_bootstrap.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name  = trim($_POST['name'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $pass  = $_POST['password'] ?? '';
  if ($name === '' || $email === '' || $pass === '') {
    $_SESSION['flash'] = ['text'=>'All fields are required','type'=>'warning'];
  } else {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $stmt = $con->prepare("INSERT INTO users (name,email,password_hash) VALUES (?,?,?)");
    $stmt->bind_param("sss", $name,$email,$hash);
    if ($stmt->execute()) {
      $_SESSION['flash'] = ['text'=>'Account created. Please log in.','type'=>'success'];
      header("Location: login.php"); exit;
    } else {
      $_SESSION['flash'] = ['text'=>'Sign up failed (email may already be used).','type'=>'danger'];
    }
  }
}
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<title>Sign Up</title></head><body class="bg-dark text-light">
<div class="container py-5" style="max-width:560px;">
  <h3 class="mb-4">Create Account</h3>
  <?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
    <div class="alert alert-<?=htmlspecialchars($f['type'])?>"><?=htmlspecialchars($f['text'])?></div>
  <?php endif; ?>
  <form method="post">
    <div class="mb-3">
      <label class="form-label">Name</label>
      <input class="form-control" name="name" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Email</label>
      <input class="form-control" name="email" type="email" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Password</label>
      <input class="form-control" name="password" type="password" required>
    </div>
    <button class="btn btn-primary">Sign Up</button>
    <a class="btn btn-outline-secondary" href="login.php">Back to Login</a>
  </form>
</div></body></html>
