<?php
header('Content-Type: application/json; charset=utf-8');
$host = '127.0.0.1';
$db   = 'study_spots';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

session_start();
if (!isset($_SESSION['user'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'AUTH_REQUIRED'], JSON_UNESCAPED_UNICODE);
  exit;
}
$userId = (int)$_SESSION['user']['Id'];

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
  $pdo = new PDO($dsn, $user, $pass, $options);
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB connection failed: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($userId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Bad input'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $stmt = $pdo->prepare("
    UPDATE checkins
    SET End_time = NOW()
    WHERE User_id = :uid
      AND End_time IS NULL
  ");
  $stmt->execute([':uid' => $userId]);

  echo json_encode(['ok' => true, 'closed' => $stmt->rowCount()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
