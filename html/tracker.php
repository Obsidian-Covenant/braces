<html>
<body>
<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

/**
 * DB CONNECTION (mysqli, proper charset)
 */
$db = mysqli_connect("localhost", "bt_user", "YourStrongPasswordHere", "BT_TRACKER");

if (!$db) {
    echo "database down, come back later.\n";
    exit;
}

mysqli_set_charset($db, 'utf8mb4');

/**
 * BASIC INPUT
 */
$count  = 0;
$now    = date("Y-m-d H:i:s");   // FIXED: proper DATETIME format
$loc    = isset($_POST['location']) ? $_POST['location'] : '';
$sensor = isset($_POST['sensor'])   ? $_POST['sensor']   : '';

if (!empty($_POST)) {

    // Prepared statement for “normal” rows (MAC + NAME + LOC + MOMENT + SENSOR)
    $stmt = $db->prepare("
        INSERT INTO BT (MAC, NAME, LOC, MOMENT, SENSOR)
        VALUES (?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        echo "DB error, come back later.\n";
        mysqli_close($db);
        exit;
    }

    foreach ($_POST as $key => $value) {
        if ($key === "location" || $key === "sensor") {
            continue;
        }

        // Only insert if there is at least *something* for name or MAC
        if ($key === '' && $value === '') {
            continue;
        }

        $mac    = $key;
        $name   = $value;
        $locEsc = $loc;
        $sens   = $sensor;
        $moment = $now;

        $stmt->bind_param("sssss", $mac, $name, $locEsc, $moment, $sens);
        $stmt->execute();
        $count++;
    }

    $stmt->close();
}

// If nothing got inserted, add a “sensor only” row:
if ($count === 0) {
    $stmt2 = $db->prepare("
        INSERT INTO BT (LOC, MOMENT, SENSOR)
        VALUES (?, ?, ?)
    ");

    if ($stmt2) {
        $locEsc = $loc;
        $sens   = $sensor;
        $moment = $now;

        $stmt2->bind_param("sss", $locEsc, $moment, $sens);
        $stmt2->execute();
        $stmt2->close();
    }
}

mysqli_close($db);

?>
</body>
</html>
