<?php
include "config.php";
include "storage.php";

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    header('Content-Type: application/json');

    if ($conn instanceof mysqli) {
        $result = $conn->query("SELECT clock_time FROM clock_settings WHERE id = 1");

        if (!$result) {
            error_log("Clock load failed, using fallback storage: " . $conn->error);
            echo json_encode(["clock_time" => storage_get_clock_time()]);
            exit;
        }

        $row = $result->fetch_assoc();
        echo json_encode(["clock_time" => $row['clock_time']]);
        exit;
    }

    echo json_encode(["clock_time" => storage_get_clock_time()]);
    exit;
}

if ($method === 'POST') {
    $clockTime = isset($_POST['clock_time']) ? trim($_POST['clock_time']) : '';

    if (!preg_match('/^([01]\\d|2[0-3]):[0-5]\\d:[0-5]\\d$/', $clockTime)) {
        http_response_code(400);
        echo "Invalid time";
        exit;
    }

    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare("UPDATE clock_settings SET clock_time = ?, updated_at = CURRENT_TIMESTAMP WHERE id = 1");

        if (!$stmt) {
            error_log("Clock prepare failed, using fallback storage: " . $conn->error);
            if (!storage_set_clock_time($clockTime)) {
                http_response_code(500);
                echo "Failed to update clock";
                exit;
            }
            echo "ok";
            exit;
        }

        $stmt->bind_param("s", $clockTime);
        if (!$stmt->execute()) {
            error_log("Clock update failed, using fallback storage: " . $stmt->error);
            $stmt->close();

            if (!storage_set_clock_time($clockTime)) {
                http_response_code(500);
                echo "Failed to update clock";
                exit;
            }

            echo "ok";
            exit;
        }
        $stmt->close();
    } else {
        if (!storage_set_clock_time($clockTime)) {
            http_response_code(500);
            echo "Failed to update clock";
            exit;
        }
    }

    echo "ok";
    exit;
}

http_response_code(405);
echo "Method not allowed";
?>