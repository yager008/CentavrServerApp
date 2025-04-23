<?php
echo "
<div style='text-align: center; margin-top: 30px;'>
    <div style='font-size: 20px; font-weight: bold; margin-bottom: 10px;'>СКРИНШОТ</div>
    <img src='http://localhost/CentavrServer/uploads/screenshot.bmp' 
         alt='Screenshot' 
         style='max-width: 60%; border: 3px solid #333; padding: 5px; background-color: #fff;'>
</div>
";




file_put_contents('debug_log.txt', date('Y-m-d H:i:s') . " | Method: " . $_SERVER['REQUEST_METHOD'] . " | URI: " . $_SERVER['REQUEST_URI'] . PHP_EOL, FILE_APPEND);

$requestUri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];
$counterFile = 'counter.txt';
$logFile = 'request_log.txt';
$bSendRequest = false;

// ОБРАБОТКА ЗАГРУЗКИ СКРИНШОТА
if ($method === 'PUT' && strpos($requestUri, '/uploads') !== false) {
    parse_str(parse_url($requestUri, PHP_URL_QUERY), $params);
    $login = $params['login'] ?? 'unknown';

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filePath = $uploadDir . "/screenshot.bmp";

$data = file_get_contents("php://input");
if ($data !== false) {
    if (file_put_contents($filePath, $data) !== false) {
        http_response_code(201);
        echo json_encode(["message" => "Screenshot saved", "file" => basename($filePath)]);

    } else {
        http_response_code(500);
        echo json_encode(["error" => "Failed to write file"]);
    }
} else {
    http_response_code(500);
    echo json_encode(["error" => "Failed to read input"]);
}
    exit;
}


// ОБРАБОТКА СООБЩЕНИЯ КНОПКИ 'take screenshot'
if (isset($_GET['action']) && $_GET['action'] === 'screenshot') {
    $bSendRequest = true;
    $user = $_GET['user'] ?? 'unknown';

    file_put_contents("screenshot_flag_$user.txt", '1');

    file_put_contents('screenshot_requests.log', date('Y-m-d H:i:s') . " | Screenshot requested for: $user" . PHP_EOL, FILE_APPEND);

    header('Content-Type: application/json');
    echo json_encode(["message" => "Screenshot request received for $user", "bSendRequest" => true]);
    exit;
}

if (!file_exists($counterFile)) {
    file_put_contents($counterFile, 0);
}
if (!file_exists($logFile)) {
    file_put_contents($logFile, '');
}

//Отображение таблицы активности юзеров
if (strpos($requestUri, '/admin') !== false) {
    header('Content-Type: text/html');

    $logs = file($logFile, FILE_IGNORE_NEW_LINES);
    $userLastSeen = [];

    foreach ($logs as $line) {
        if (preg_match('/^(.+?) \| IP: (.*?) \| MAC: (.*?) \| LOGIN: (.+)$/', $line, $matches)) {
            $timestamp = $matches[1];
            $ip = $matches[2];
            $mac = $matches[3];
            $login = $matches[4];

            if ($login === 'unknown') {
                continue;
            }

            $userLastSeen[$login] = [
                'timestamp' => $timestamp,
                'ip' => $ip,
                'mac' => $mac
            ];
        }
    }

    //сортировка юзеров по последнему действию
    uasort($userLastSeen, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']); 
    });

    echo "<!DOCTYPE html>";
    echo "<html><head><title>User Activity</title>";
    echo "<meta charset='UTF-8'>";
    echo "<style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        h1 { color: #333; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background-color: #f4f4f4; }
        .recent { background-color: #c8f7c5; } /* light green */
        .screenshot-btn { padding: 5px 10px; cursor: pointer; }
    </style>";
    echo "<script>setTimeout(() => { window.location.reload(); }, 5000);</script>";
    echo "</head><body>";


    echo "<h1>Таблица активности юзеров</h1>";
    echo "<table><tr><th>Login</th><th>Status</th><th>IP</th><th>MAC</th><th>Action</th></tr>";

    $now = time();
    foreach ($userLastSeen as $login => $data) {
        $timestamp = $data['timestamp'];
        $ip = $data['ip'];
        $mac = $data['mac'];

        $lastSeen = strtotime($timestamp);
        $secondsAgo = $now - $lastSeen;

        $isVeryRecent = $secondsAgo <= 10;
        $isOnline = $secondsAgo <= 60;

        $rowClass = $isVeryRecent ? ' class="recent"' : '';
        $statusText = $isOnline ? 'online' : htmlspecialchars($timestamp);

        $screenshotAction = "takeScreenshot('$login');";

        echo "<tr$rowClass>
                <td>" . htmlspecialchars($login) . "</td>
                <td>$statusText</td>
                <td>" . htmlspecialchars($ip) . "</td>
                <td>" . htmlspecialchars($mac) . "</td>
                <td><button class='screenshot-btn' onclick=\"$screenshotAction\">Take Screenshot</button></td>
              </tr>";
    }

    echo "</table>";
    echo "<script>

function takeScreenshot(user) {
    fetch('/CentavrServer/main.php?action=screenshot&user=' + encodeURIComponent(user))
        .then(response => response.json())
        .then(data => {
            console.log(data);
            alert(data.message || 'Screenshot requested for ' + user);
        })
        .catch(err => {
            console.error('Screenshot request failed:', err);
        });
}
    </script>";
    echo "</body></html>";
    exit;
}

header('Content-Type: application/json');

//ОБРАБОТКА РЕГУЛЯРНЫХ СООБЩЕНИЙ ОТ КЛИЕНТА
if ($method === 'GET') {
    $ip = $_GET['ip'] ?? $_SERVER['REMOTE_ADDR'];
    $mac = $_GET['mac'] ?? 'unknown';
    $login = $_GET['login'] ?? 'unknown';

    $count = (int)file_get_contents($counterFile) + 1;
    file_put_contents($counterFile, $count);

    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "$timestamp | IP: $ip | MAC: $mac | LOGIN: $login";
    file_put_contents($logFile, $logEntry . PHP_EOL, FILE_APPEND);

    $flagFile = "screenshot_flag_$login.txt";
    if (file_exists($flagFile)) {
        unlink($flagFile); 

        echo json_encode([
            "message" => "good bye",
            "requests" => $count,
            "ip" => $ip,
            "mac" => $mac,
            "login" => $login,
            "sendScreenShot" => "true"
        ]);
        exit;
    }

    echo json_encode([
        "message" => "Hello world",
        "requests" => $count,
        "ip" => $ip,
        "mac" => $mac,
        "login" => $login,
        "sendScreenShot" => "false"
    ]);
    exit;
}

http_response_code(405);
echo json_encode([
    "error" => "Only GET requests are allowed"
]);
?>
