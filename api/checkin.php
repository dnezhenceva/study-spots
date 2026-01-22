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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  echo json_encode(['ok' => true, 'hint' => 'Send POST: place_type, place_id'], JSON_UNESCAPED_UNICODE);
  exit;
}

$placeType = isset($_POST['place_type']) ? trim($_POST['place_type']) : '';
$placeId   = isset($_POST['place_id']) ? (int)$_POST['place_id'] : 0;

if (!in_array($placeType, ['library','museum'], true) || $placeId <= 0 || $userId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Bad input'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $stmt = $pdo->prepare("
    SELECT Id
    FROM checkins
    WHERE User_id = :uid
      AND Place_type = :ptype
      AND Place_id = :pid
      AND End_time IS NULL
    ORDER BY Start_time DESC
    LIMIT 1
  ");
  $stmt->execute([':uid'=>$userId, ':ptype'=>$placeType, ':pid'=>$placeId]);
  $active = $stmt->fetch();

  if ($active) {
    echo json_encode(['ok' => true, 'message' => 'Already checked in'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $stmt = $pdo->prepare("
    UPDATE checkins
    SET End_time = NOW()
    WHERE User_id = :uid
      AND End_time IS NULL
  ");
  $stmt->execute([':uid'=>$userId]);

  $stmt = $pdo->prepare("
    INSERT INTO checkins (Place_type, Place_id, User_id, Start_time, End_time)
    VALUES (:ptype, :pid, :uid, NOW(), NULL)
  ");
  $stmt->execute([':ptype'=>$placeType, ':pid'=>$placeId, ':uid'=>$userId]);

  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
