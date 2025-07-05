<?php
$logDir = "/var/log/pi-star";
$pattern = "$logDir/MMDVM-*.log";
$logFiles = glob($pattern);

// מיין לפי זמן אחרון
usort($logFiles, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

// קח את הקובץ האחרון
$latestLog = $logFiles[0] ?? null;

if ($latestLog && is_readable($latestLog)) {
    $lines = shell_exec("tail -n 3 " . escapeshellarg($latestLog));
    header("Content-Type: text/plain; charset=UTF-8");
    echo $lines;
} else {
    http_response_code(404);
    echo "⚠ קובץ הלוג לא נמצא או שאינו נגיש: $latestLog";
}
?>