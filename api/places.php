<?php
header('Content-Type: application/json; charset=utf-8');
$host = '127.0.0.1';
$db   = 'study_spots';   
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
function cleanAddress($s) {
  if ($s === null) return '';
  $s = trim((string)$s);

  preg_match_all('/Address:([^A]+?)(?=\s+(?:available_[a-z]+:|AdmArea:|District:|PostalCode:|$))/u', $s, $m);

  if (!empty($m[1])) {
    $addresses = array_map('trim', $m[1]);
    $addresses = array_values(array_unique($addresses));
    return implode('; ', $addresses);
  }

  $s = preg_replace('/\b(AdmArea|District|PostalCode|available_[a-z]+):\S+/u', '', $s);
  $s = preg_replace('/\s{2,}/u', ' ', $s);
  return trim($s);
}

function clamp01($v) {
  if ($v < 0) return 0.0;
  if ($v > 1) return 1.0;
  return (float)$v;
}

function featureVector(array $row): array {
  $reviews = (int)($row['Reviews_count'] ?? 0);
  $avg = isset($row['Avg_rating']) ? (float)$row['Avg_rating'] : 0.0;
  $active = isset($row['Active_people']) ? (float)$row['Active_people'] : 0.0;

  $quiet = (float)($row['Quiet_votes'] ?? 0);
  $wifi  = (float)($row['Wifi_yes'] ?? 0);
  $sock  = (float)($row['Sockets_yes'] ?? 0);

  $ratingNorm = ($reviews > 0) ? clamp01($avg / 5.0) : 0.0;

  $cond = 0.0;
  if ($reviews > 0) {
    $cond = ($quiet + $wifi + $sock) / (3.0 * $reviews);
    $cond = clamp01($cond);
  }

  $crowd = 1.0 - min(1.0, $active / 10.0);
  $crowd = clamp01($crowd);

  return [$ratingNorm, $cond, $crowd];
}

function dist2(array $a, array $b): float {
  $s = 0.0;
  $n = min(count($a), count($b));
  for ($i = 0; $i < $n; $i++) {
    $d = $a[$i] - $b[$i];
    $s += $d * $d;
  }
  return $s;
}

function kmeans(array $points, int $k, int $maxIter = 10): array {
  $n = count($points);
  if ($n === 0) return [[], []];
  if ($k <= 1) return [array_fill(0, $n, 0), [$points[0]]];
  if ($n < $k) {
    $assign = [];
    for ($i = 0; $i < $n; $i++) $assign[$i] = $i;
    return [$assign, array_values($points)];
  }

  $indices = range(0, $n - 1);
  usort($indices, function($i, $j) use ($points) {
    return 0;
  });

  $centroids = [];
  for ($c = 0; $c < $k; $c++) {
    $idx = (int)floor($c * ($n - 1) / ($k - 1));
    $centroids[$c] = $points[$idx];
  }

  $assign = array_fill(0, $n, 0);

  for ($iter = 0; $iter < $maxIter; $iter++) {
    $changed = false;

    for ($i = 0; $i < $n; $i++) {
      $bestC = 0;
      $bestD = dist2($points[$i], $centroids[0]);
      for ($c = 1; $c < $k; $c++) {
        $d = dist2($points[$i], $centroids[$c]);
        if ($d < $bestD) {
          $bestD = $d;
          $bestC = $c;
        }
      }
      if ($assign[$i] !== $bestC) {
        $assign[$i] = $bestC;
        $changed = true;
      }
    }

    $dim = count($points[0]);
    $sum = array_fill(0, $k, array_fill(0, $dim, 0.0));
    $cnt = array_fill(0, $k, 0);

    for ($i = 0; $i < $n; $i++) {
      $c = $assign[$i];
      $cnt[$c]++;
      for ($d = 0; $d < $dim; $d++) $sum[$c][$d] += $points[$i][$d];
    }

    for ($c = 0; $c < $k; $c++) {
      if ($cnt[$c] === 0) {
        $centroids[$c] = $points[random_int(0, $n - 1)];
        continue;
      }
      for ($d = 0; $d < $dim; $d++) $sum[$c][$d] /= $cnt[$c];
      $centroids[$c] = $sum[$c];
    }

    if (!$changed) break;
  }

  return [$assign, $centroids];
}



try {
  $pdo = new PDO($dsn, $user, $pass, $options);

  $placeTypeOne = $_GET['place_type'] ?? null;
$placeIdOne   = isset($_GET['place_id']) ? (int)$_GET['place_id'] : null;

if ($placeTypeOne !== null && !in_array($placeTypeOne, ['library','museum'], true)) {
  $placeTypeOne = null;
}
if ($placeIdOne !== null && $placeIdOne <= 0) $placeIdOne = null;

  $type    = $_GET['type'] ?? 'all';        
  $wifi    = ($_GET['wifi'] ?? '0') === '1';
  $sockets = ($_GET['sockets'] ?? '0') === '1';
  $free    = ($_GET['free'] ?? '0') === '1';
  $noise   = $_GET['noise'] ?? 'any';        

  $maxPeople = isset($_GET['max_people']) ? (int)$_GET['max_people'] : 999999;
  if ($maxPeople < 0) $maxPeople = 0;
  if ($maxPeople > 999999) $maxPeople = 999999;

  $recommend = ($_GET['recommend'] ?? '0') === '1';
  $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 2000;
  if ($limit <= 0) $limit = 50;
  if ($limit > 5000) $limit = 5000;

  $sql = "
SELECT
  t.Place_type,
  t.Place_id,
  t.CommonName,
  t.ObjectAddress,
  t.WebSite,
  t.WorkingHours,

  t.Latitude,
  t.Longitude,
  t.Reviews_count,
  t.Avg_rating,
  t.Active_people,

  t.Quiet_votes,
  t.Background_votes,
  t.Lively_votes,
  t.Kids_votes,

  t.Wifi_yes,
  t.Sockets_yes,
  t.Free_votes,
  t.Paid_votes,

 LEAST(
  100,
  GREATEST(
    0,
    ROUND(
      (
        COALESCE(t.Avg_rating / 5, 0) * 0.4
        +
        COALESCE(
          ((t.Quiet_votes + t.Wifi_yes + t.Sockets_yes) / NULLIF(t.Reviews_count * 3, 0)),
          0
        ) * 0.3
        +
        (1 - LEAST(1, t.Active_people / 10)) * 0.3
      ) * 100
    )
  )
) AS Score


FROM (
  SELECT
    p.Place_type,
    p.Place_id,
    p.CommonName,
    p.ObjectAddress,
    p.WebSite,
      MAX(COALESCE(l.WorkingHours, m.WorkingHours)) AS WorkingHours,

    p.Latitude,
    p.Longitude,


    COUNT(r.Id) AS Reviews_count,
    ROUND(AVG(r.Rating), 2) AS Avg_rating,

    SUM(r.Noise_level='quiet')      AS Quiet_votes,
    SUM(r.Noise_level='background') AS Background_votes,
    SUM(r.Noise_level='lively')     AS Lively_votes,
    SUM(r.Noise_level='kids_loud')  AS Kids_votes,

    SUM(r.Has_wifi=TRUE)    AS Wifi_yes,
    SUM(r.Has_sockets=TRUE) AS Sockets_yes,

    SUM(r.Access_type='free') AS Free_votes,
    SUM(r.Access_type='paid') AS Paid_votes,

  (SELECT COUNT(*)
 FROM checkins c
 WHERE c.Place_type = p.Place_type
   AND c.Place_id   = p.Place_id
   AND c.Start_time >= NOW() - INTERVAL 90 MINUTE
   AND (c.End_time IS NULL OR c.End_time >= NOW())
) AS Active_people


FROM places p

LEFT JOIN libraries l
  ON p.Place_type = 'library' AND l.Id = p.Place_id

LEFT JOIN museums m
  ON p.Place_type = 'museum' AND m.Id = p.Place_id

LEFT JOIN reviews r
  ON r.Place_type=p.Place_type AND r.Place_id=p.Place_id

WHERE p.Latitude IS NOT NULL AND p.Longitude IS NOT NULL

  GROUP BY
    p.Place_type, p.Place_id, p.CommonName, p.ObjectAddress, p.WebSite, p.Latitude, p.Longitude

) t
WHERE 1=1
";

  $params = [];
if ($placeTypeOne !== null && $placeIdOne !== null) {
  $sql .= " AND t.Place_type = :one_type AND t.Place_id = :one_id ";
  $params[':one_type'] = $placeTypeOne;
  $params[':one_id']   = $placeIdOne;
}
  if ($type === 'library' || $type === 'museum') {
    $sql .= " AND t.Place_type COLLATE utf8mb4_unicode_ci = :type ";
    $params[':type'] = $type;
  }

  $sql .= " AND t.Active_people <= $maxPeople ";

  if ($wifi) {
    $sql .= " AND (t.Reviews_count = 0 OR t.Wifi_yes >= 0.6 * t.Reviews_count) ";
  }

  if ($sockets) {
    $sql .= " AND (t.Reviews_count = 0 OR t.Sockets_yes >= 0.6 * t.Reviews_count) ";
  }

  if ($free) {
    $sql .= " AND (t.Reviews_count = 0 OR t.Free_votes > t.Paid_votes) ";
  }
  if (in_array($noise, ['quiet','background','lively','kids_loud'], true)) {
    $sql .= "
      AND (
        t.Reviews_count = 0 OR
        (
          CASE
            WHEN t.Quiet_votes >= t.Background_votes AND t.Quiet_votes >= t.Lively_votes AND t.Quiet_votes >= t.Kids_votes THEN 'quiet'
            WHEN t.Background_votes >= t.Quiet_votes AND t.Background_votes >= t.Lively_votes AND t.Background_votes >= t.Kids_votes THEN 'background'
            WHEN t.Lively_votes >= t.Quiet_votes AND t.Lively_votes >= t.Background_votes AND t.Lively_votes >= t.Kids_votes THEN 'lively'
            ELSE 'kids_loud'
          END
        ) = :noise
      )
    ";
    $params[':noise'] = $noise;
  }
  if ($recommend) {
    $sql .= " ORDER BY Score DESC, t.Active_people ASC, t.Reviews_count DESC ";
  } else {
    $sql .= " ORDER BY t.Active_people ASC, (t.Avg_rating IS NULL) ASC, t.Avg_rating DESC, t.Reviews_count DESC ";
  }
  $sql .= " LIMIT $limit ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  $k = 3;

$points = [];
for ($i = 0; $i < count($rows); $i++) {
  $points[$i] = featureVector($rows[$i]);
}

list($assign, $centroids) = kmeans($points, $k, 10);

$clusterSum = array_fill(0, $k, 0.0);
$clusterCnt = array_fill(0, $k, 0);

for ($i = 0; $i < count($rows); $i++) {
  $c = $assign[$i] ?? 0;
  $score = isset($rows[$i]['Score']) ? (float)$rows[$i]['Score'] : 0.0;
  $clusterSum[$c] += $score;
  $clusterCnt[$c] += 1;
}

$means = [];
for ($c = 0; $c < $k; $c++) {
  $means[$c] = ($clusterCnt[$c] > 0) ? ($clusterSum[$c] / $clusterCnt[$c]) : -1.0;
}

$oldIds = range(0, $k - 1);
usort($oldIds, function($a, $b) use ($means) {
  if ($means[$a] === $means[$b]) return 0;
  return ($means[$a] < $means[$b]) ? 1 : -1;
});

$remap = [];
for ($newId = 1; $newId <= $k; $newId++) {
  $remap[$oldIds[$newId - 1]] = $newId;
}

for ($i = 0; $i < count($rows); $i++) {
  $old = $assign[$i] ?? 0;
  $rows[$i]['Cluster'] = $remap[$old] ?? 1;
}


  foreach ($rows as &$row) {
    $row['DisplayAddress'] = cleanAddress($row['ObjectAddress'] ?? '');
    unset($row['ObjectAddress']);
  }

  echo json_encode($rows, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
