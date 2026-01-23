<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
require __DIR__ . '/db.php';

$username = trim($_POST['username'] ?? '');
$pass = (string)($_POST['password'] ?? '');

if ($username === '' || $pass === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Заполни имя пользователя и пароль'], JSON_UNESCAPED_UNICODE);
  exit;
}
try {
  $st = $pdo->prepare("SELECT Id, Username, PasswordHash FROM users WHERE Username=:u LIMIT 1");
  $st->execute([':u'=>$username]);
  $u = $st->fetch();

  if (!$u || !$u['PasswordHash'] || !password_verify($pass, $u['PasswordHash'])) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Неверный логин или пароль'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $_SESSION['user'] = ['Id'=>(int)$u['Id'], 'Username'=>$u['Username']];
  echo json_encode(['ok'=>true,'user'=>$_SESSION['user']], JSON_UNESCAPED_UNICODE);
} catch(Exception $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
