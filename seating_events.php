<?php
include 'database.php';
// Server-Sent Events endpoint to notify seating changes for a class
if (!isset($_GET['class_id'])) {
    http_response_code(400);
    echo "Missing class_id";
    exit();
}
$class_id = intval($_GET['class_id']);
// Disable script time limit
set_time_limit(0);
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

$lastSent = 0;

// Optionally accept last event id from client
if (isset($_SERVER["HTTP_LAST_EVENT_ID"])) {
    $lastSent = strtotime($_SERVER["HTTP_LAST_EVENT_ID"]);
}

while (true) {
    // Check DB for last_seating_update
    $stmt = $connection->prepare("SELECT UNIX_TIMESTAMP(last_seating_update) as lu FROM classes WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    $lu = $row && $row['lu'] ? intval($row['lu']) : 0;
    if ($lu > $lastSent) {
        $lastSent = $lu;
        $data = json_encode(['timestamp' => date('c', $lastSent)]);
        echo "event: seating_update\n";
        echo "id: " . date('c', $lastSent) . "\n";
        echo "data: $data\n\n";
        @ob_flush();
        @flush();
    }

    // Sleep a bit to avoid hammering DB
    sleep(1);
}

?>
