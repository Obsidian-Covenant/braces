
<html>
<head>
<title>Bluetooth Device Tracking Demo - BlackHat 2004</title>

<style>
    a
    {
        text-decoration: none;
    }

    a:hover
    {
        color: #444444;
        text-decoration: none;
    }

    td
    {
        font-family: verdana,arial,helvetica;
        font-size: 8pt;
        color: #000000;
    }

    text
    {
        font-family: verdana,arial,helvetica;
        font-size: 8pt;
        color: #777777;
    }

   .font           { font-family: verdana,arial,helvetica; font-size: 8pt; }
   .navlink        { font-size: smaller; color: #000000; }
   .smallnospace   { font-size: smaller; }

   .email
    {
        font-family: verdana,arial,helvetica; font-size: 8pt; 
        color: #669999;
        text-decoration: none;
    }

    .big_links
    {
        font-family: verdana,arial,helvetica; font-size: 8pt;
        color: #669999;
        font-weight: bold;
        text-decoration: none;
    }

    .small_links
    {
        font-family: verdana,arial,helvetica; font-size: 8pt;
        color: #777777;
        text-decoration: none;
    }

</style>
</head>

<body bgcolor="#ffffff">

<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

$locations = array();
$sensors   = array();

/**
 * Helper: safely escape text for HTML output
 */
function h($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * draw_device – still uses ImageMagick, but with basic shell escaping
 */
function draw_device( $name, $mac, $loc, $xpos, $ypos, $ymax )
{
    global $sensors;
    global $locations;

    // Use escapeshellarg for safety
    $convert = 'convert';
    $loc     = strtolower($loc);

    if ( isset($sensors[$mac]) ) {
        unset( $sensors[$mac] );
        $convert .= ' -fill red';
    } else {
        $convert .= ' -fill blue';
    }

    // Determine x,y and reset y.
    if ( !isset($locations[$loc]) || $locations[$loc] == 0 ) {
        // first item in this section, account for font height.
        $locations[$loc] = ( $ypos + 10 );
    }

    $y = $locations[$loc];
    $locations[$loc] = ( $y + 10 );

    if ( $y <= $ymax ) {
        // Text to draw
        $label = $name . '(' . $mac . ')';

        // Build command safely
        $cmd  = $convert;
        $cmd .= ' -draw ' . escapeshellarg("text $xpos,$y '$label'");
        $cmd .= ' images/map-temp.jpg images/map-current.jpg';

        exec($cmd);
        exec('cp images/map-current.jpg images/map-temp.jpg');
    }
}

/**
 * DB CONNECTION (mysqli)
 */

$mysqli = mysqli_connect('localhost', 'bt_user', 'YourStrongPasswordHere', 'BT_TRACKER');

if (!$mysqli) {
    echo 'database down, come back later.' . "\n";
    exit;
}

mysqli_set_charset($mysqli, 'utf8mb4');

/**
 * RESET MAP IMAGE
 */
exec('cp images/map.jpg images/map-current.jpg');
exec('cp images/map.jpg images/map-temp.jpg');

/**
 * BUILD SENSOR LIST: sensors seen in last 60s
 */
$sql = "
    SELECT DISTINCT SENSOR, LOC
    FROM BT
    WHERE (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(MOMENT) <= 60)
";

$s_result = mysqli_query($mysqli, $sql);
$num_sensors = $s_result ? mysqli_num_rows($s_result) : 0;

if ($num_sensors > 0 && $s_result) {
    while ($myrow = mysqli_fetch_row($s_result)) {
        // [0] = SENSOR, [1] = LOC
        $sensors[$myrow[0]] = $myrow[1];
    }
    mysqli_free_result($s_result);
}

/**
 * DRAW DEVICES (last 60s)
 */
$sql = "
    SELECT DISTINCT(d.MAC), d.NAME, d.LOC, MAX(d.MOMENT), d.SENSOR,
           l.LOC, l.X0, l.Y0, l.XMAX, l.YMAX
    FROM BT AS d
    JOIN MAP AS l ON d.LOC = l.LOC
    WHERE (UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(d.MOMENT) <= 60)
    GROUP BY d.MAC
    ORDER BY d.MOMENT DESC, d.LOC DESC
";

$result   = mysqli_query($mysqli, $sql);
$num_rows = $result ? mysqli_num_rows($result) : 0;
$count    = 0;

if ($num_rows > 0 && $result) {
    while ($myrow = mysqli_fetch_row($result)) {
        // myrow indices match your old code:
        // [0] MAC, [1] NAME, [3] MOMENT, [5] LOC, [6] X0, [7] Y0, [9] YMAX

        if ($count == 0) {
            echo "<font size='-1' face='arial,helvetica'>";
            echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
            echo "Devices seen in the last 60 seconds";
            echo "  (last known contact: " . h($myrow[3]) . ")<br></font>";
            $count++;
        }

        if ($myrow[0] == "") {
            continue;
        }

        draw_device(
            $myrow[1],   // NAME
            $myrow[0],   // MAC
            $myrow[5],   // LOC (for section tracking only)
            $myrow[6],   // X0
            $myrow[7],   // Y0
            $myrow[9]    // YMAX
        );
    }

    mysqli_free_result($result);
}

/**
 * DRAW ANY UNDETECTED SENSORS (RED TEXT)
 */
foreach ($sensors as $sensorMac => $valueLoc) {
    // Lookup the X location for this sensor
    $loc = $valueLoc;

    $stmt = $mysqli->prepare("SELECT X0, Y0, YMAX FROM MAP WHERE LOC = ?");
    if ($stmt) {
        $stmt->bind_param("s", $loc);
        $stmt->execute();
        $stmt->bind_result($x0, $y0, $ymax);
        if ($stmt->fetch()) {
            draw_device("sensor", $sensorMac, $loc, $x0, $y0, $ymax);
        }
        $stmt->close();
    }
}

/**
 * SHOW CURRENT MAP IMAGE
 */
echo "<img alt='the universe' src='images/map-current.jpg'>";

/**
 * SEARCH FORM – use htmlspecialchars for the values
 */
$sub_device = isset($_POST['device']) ? $_POST['device'] : '';
$sub_mac    = isset($_POST['mac'])    ? $_POST['mac']    : '';

echo "<br><br>";

echo "<form method='POST' name='search'>";
echo "<table bgcolor='#bbbbbb' border='0'>";
echo "<font face='arial,helveticau'>";
echo "<tr><td>Device Name:</td><td colspan='2'>";
echo "<input type='text' name='device' value='" . h($sub_device) . "'>";
echo "</td></tr>";

echo "<tr><td>MAC Address:</td><td colspan='2'><input type='text' name='mac' ";
echo "value='" . h($sub_mac) . "'></td></tr>";

echo "<tr><td>Location:</td><td colspan='2'>";
echo "<input type='text' name='location'><td></tr>";

echo "<tr><td>Date Restrict:</td><td>";
echo "<select name='date_type'>";
echo "<option value='on' selected>on</option>";
echo "<option value='before'>before</option>";
echo "<option value='after'>after</option></select></td>";
echo "<td><input type='text' name='date'>(YYYY-MM-DD)</td></tr>";

echo "<tr><td>Time Restrict:</td><td>";
echo "<select name='time_type'>";
echo "<option value='on' selected>on</option>";
echo "<option value='before'>before</option>";
echo "<option value='after'>after</option></select></td>";
echo "<td><input type='text' name='time'>(HH:MM:SS)</td></tr>";

echo "<tr><td>&nbsp;</td></tr>";
echo "<tr><td><input type='submit' name='submit' value='Search'></td></tr>";

echo "</font>";
echo "</table></form>";

/**
 * SEARCH HANDLER (basic modernization, still simple)
 */
if (!empty($_POST)) {

    $name = isset($_POST['device']) ? trim($_POST['device']) : '';
    $mac  = isset($_POST['mac'])    ? trim($_POST['mac'])    : '';

    if ($name === "" && $mac === "") {
        // nothing to search for
    } else {

        // Build dynamic query + params
        $sql   = "SELECT MAC, NAME, LOC, MOMENT, SENSOR FROM BT WHERE 1=1";
        $types = "";
        $vals  = array();

        if ($name !== "") {
            $sql    .= " AND NAME LIKE ?";
            $types  .= "s";
            $vals[]  = $name . "%";
        }

        if ($mac !== "") {
            $sql    .= " AND MAC LIKE ?";
            $types  .= "s";
            $vals[]  = $mac . "%";
        }

        $sql .= " ORDER BY MOMENT";

        $stmt = $mysqli->prepare($sql);

        if ($stmt) {
            if (!empty($vals)) {
                // bind_param needs references
                $bind_params = array_merge(array($types), $vals);
                foreach ($bind_params as $key => $value) {
                    $bind_params[$key] = &$bind_params[$key];
                }
                call_user_func_array(array($stmt, 'bind_param'), $bind_params);
            }

            $stmt->execute();
            $result = $stmt->get_result();

            $num_rows = $result ? $result->num_rows : 0;

            echo "<font face='arial,helvetica' size='-1'>";
            echo "Search returned (" . h($num_rows) . ") results.";
            echo "</font><br><br>";

            if ($num_rows > 0 && $result) {

                // print results table heading.
                echo "<table width='500' border='0' bgcolor='bbbbbb' cellspacing='2'>";
                echo "<tr bgcolor='bbbbbb'><td><b>Device</b></td><td><b>MAC</b></td>";
                echo "<td><b>Location</b></td><td><b>Time</b></td>";
                echo "<td><b>Detected By</b></td></tr>";

                $row = 0;

                while ($myrow = $result->fetch_row()) {
                    // [0] MAC, [1] NAME, [2] LOC, [3] MOMENT, [4] SENSOR
                    echo "<tr class='small_links'";
                    if ($row % 2 == 0)
                        echo " bgcolor='ffffff'>";
                    else
                        echo " bgcolor='dddddd'>";

                    echo "<td>" . h($myrow[1]) . "</td>"; // NAME
                    echo "<td>" . h($myrow[0]) . "</td>"; // MAC
                    echo "<td>" . h($myrow[2]) . "</td>"; // LOC
                    echo "<td>" . h($myrow[3]) . "</td>"; // MOMENT
                    echo "<td>" . h($myrow[4]) . "</td>"; // SENSOR
                    echo "</tr>";
                    $row++;
                }

                echo "</table>";
                echo "<br><br>";
            }

            if ($result) {
                $result->free();
            }

            $stmt->close();
        }
    }

    mysqli_close($mysqli);
}

?>

</body>
</html>
