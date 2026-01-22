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
if (mb_strlen($pass) < 6) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Пароль минимум 6 символов'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $st = $pdo->prepare("SELECT Id FROM users WHERE Username = :u LIMIT 1");
  $st->execute([':u'=>$username]);
  if ($st->fetch()) {
    http_response_code(409);
    echo json_encode(['ok'=>false,'error'=>'Такой пользователь уже существует'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $hash = password_hash($pass, PASSWORD_DEFAULT);

  $st = $pdo->prepare("INSERT INTO users (Username, PasswordHash) VALUES (:u,:h)");
  $st->execute([':u'=>$username, ':h'=>$hash]);

  $id = (int)$pdo->lastInsertId();
  $_SESSION['user'] = ['Id'=>$id, 'Username'=>$username];

  echo json_encode(['ok'=>true, 'user'=>$_SESSION['user']], JSON_UNESCAPED_UNICODE);
} catch(Exception $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
