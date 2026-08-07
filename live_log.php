<?php
/*
 * live_log.php v2 — Pi-Star Live Interface by 4X5ZB
 * שני מצבי עבודה:
 *   1) ברירת מחדל (?mode=log או בלי פרמטרים) — מחזיר את השורות האחרונות מלוג MMDVM
 *   2) ?mode=lookup&callsign=XXXX — proxy ל-radioid.net עם cache מקומי
 *      (פותר את בעיית ה-CORS: הדפדפן פונה ל-Pi-Star, וה-Pi-Star פונה ל-API)
 */

header("Access-Control-Allow-Origin: *");

$mode = $_GET['mode'] ?? 'log';

if ($mode === 'lookup') {
    // ---------- מצב 2: שליפת פרטי תחנה לפי אות קריאה ----------
    header("Content-Type: application/json; charset=UTF-8");

    // ניקוי אות הקריאה — אותיות, ספרות ולוכסן בלבד (4X5ZB/P וכו')
    $callsign = strtoupper(preg_replace('/[^A-Za-z0-9\/]/', '', $_GET['callsign'] ?? ''));
    // ל-API שולחים את הבסיס בלי סיומת /P /M וכדומה
    $baseCall = explode('/', $callsign)[0];

    if ($baseCall === '') {
        http_response_code(400);
        echo json_encode(["error" => "missing callsign"]);
        exit;
    }

    // cache מקומי — קובץ לכל אות קריאה, תוקף 7 ימים
    $cacheDir = "/tmp/dmr_lookup_cache";
    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
    $cacheFile = "$cacheDir/" . preg_replace('/[^A-Z0-9]/', '_', $baseCall) . ".json";
    $ttl = 7 * 24 * 3600;

    if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        echo file_get_contents($cacheFile);
        exit;
    }

    // פנייה ל-radioid.net (עם timeout קצר כדי לא לתקוע את הממשק)
    $url = "https://radioid.net/api/dmr/user/?callsign=" . urlencode($baseCall);
    $ctx = stream_context_create([
        "http" => ["timeout" => 5, "user_agent" => "PiStarLive/2.0 (4X5ZB)"]
    ]);
    $json = @file_get_contents($url, false, $ctx);

    // fallback ל-curl אם allow_url_fopen כבוי
    if ($json === false && function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => "PiStarLive/2.0 (4X5ZB)"
        ]);
        $json = curl_exec($ch);
        curl_close($ch);
    }

    if ($json !== false && json_decode($json) !== null) {
        @file_put_contents($cacheFile, $json);  // שמירה ל-cache
        echo $json;
    } else {
        http_response_code(502);
        echo json_encode(["error" => "lookup failed"]);
    }
    exit;
}

// ---------- מצב 1: קריאת לוג MMDVM ----------
$logDir = "/var/log/pi-star";
$logFiles = glob("$logDir/MMDVM-*.log");

// מיון לפי זמן שינוי — החדש ביותר ראשון
usort($logFiles, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$latestLog = $logFiles[0] ?? null;

if ($latestLog && is_readable($latestLog)) {
    // 60 שורות במקום 3 — כדי לתפוס גם שורות סיום שידור (BER/Loss)
    // וגם היסטוריה ל-Last Heard
    $lines = shell_exec("tail -n 60 " . escapeshellarg($latestLog));
    header("Content-Type: text/plain; charset=UTF-8");
    echo $lines;
} else {
    http_response_code(404);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "log file not found or unreadable";
}
