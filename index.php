<?php
session_start();

$dataFolder = dirname(__DIR__) . '/vip-guest-data';

if (!is_dir($dataFolder)) {
    mkdir($dataFolder, 0775, true);
}

$guestFile = $dataFolder . '/guests.json';
$preferenceFile = $dataFolder . '/preferences.json';
$reservationFile = $dataFolder . '/reservations.json';
$notificationFile = $dataFolder . '/notifications.json';

function loadData($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveData($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function clean($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function guestName($guestId, $guests) {
    foreach ($guests as $guest) {
        if (($guest['id'] ?? '') === $guestId) {
            return $guest['name'];
        }
    }
    return 'Unknown guest';
}

$guests = loadData($guestFile);
$preferences = loadData($preferenceFile);
$reservations = loadData($reservationFile);
$notifications = loadData($notificationFile);
// MODULE 5: EXPORT REPORTS TO CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="silent-hill-hotel-report.csv"');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Record Type',
        'Guest Name',
        'Details',
        'Date',
        'Status or Priority'
    ]);

    foreach ($guests as $guest) {
        fputcsv($output, [
            'VIP Guest',
            $guest['name'] ?? '',
            'Phone: ' . ($guest['phone'] ?? '') .
            '; Email: ' . ($guest['email'] ?? '') .
            '; VIP Level: ' . ($guest['level'] ?? ''),
            $guest['date'] ?? '',
            'Registered'
        ]);
    }

    foreach ($preferences as $p) {
        fputcsv($output, [
            'Guest Preference',
            guestName($p['guest_id'] ?? '', $guests),
            'Room: ' . ($p['room'] ?? '') .
            '; Food: ' . ($p['food'] ?? '') .
            '; Drinks: ' . ($p['drinks'] ?? '') .
            '; Requests: ' . ($p['requests'] ?? ''),
            $p['date'] ?? '',
            'Saved'
        ]);
    }

    foreach ($reservations as $r) {
        fputcsv($output, [
            'Reservation',
            guestName($r['guest_id'] ?? '', $guests),
            'Room: ' . ($r['room'] ?? '') .
            '; Check-in: ' . ($r['checkin'] ?? '') .
            '; Check-out: ' . ($r['checkout'] ?? ''),
            $r['date'] ?? '',
            $r['status'] ?? ''
        ]);
    }

    foreach ($notifications as $n) {
        fputcsv($output, [
            'Staff Notification',
            guestName($n['guest_id'] ?? '', $guests),
            'Subject: ' . ($n['subject'] ?? '') .
            '; Details: ' . ($n['details'] ?? ''),
            $n['date'] ?? '',
            $n['priority'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}

if (empty($_SESSION['token'])) {
    $_SESSION['token'] = bin2hex(random_bytes(32));
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['token'], $_POST['token'] ?? '')) {
        $message = 'Invalid form submission. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'register_guest') {
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $level = $_POST['level'] ?? '';

            if ($name && $phone &&
                filter_var($email, FILTER_VALIDATE_EMAIL) &&
                in_array($level, ['Platinum', 'Gold', 'Silver'], true)) {

                $guests[] = [
                    'id' => uniqid('guest_'),
                    'name' => $name,
                    'phone' => $phone,
                    'email' => $email,
                    'level' => $level,
                    'date' => date('Y-m-d')
                ];

                saveData($guestFile, $guests);
                header('Location: index.php?success=guest');
                exit;
            }
            $message = 'Please enter valid guest information.';
        }

        if ($action === 'save_preference') {
            $guestId = $_POST['guest_id'] ?? '';
            $room = $_POST['room'] ?? '';
            $guestExists = false;

            foreach ($guests as $guest) {
                if (($guest['id'] ?? '') === $guestId) {
                    $guestExists = true;
                    break;
                }
            }

            if ($guestExists && $room !== '') {
                $preferences[] = [
                    'guest_id' => $guestId,
                    'room' => $room,
                    'food' => trim($_POST['food'] ?? ''),
                    'drinks' => trim($_POST['drinks'] ?? ''),
                    'requests' => trim($_POST['requests'] ?? ''),
                    'date' => date('Y-m-d')
                ];

                saveData($preferenceFile, $preferences);
                header('Location: index.php?success=preference');
                exit;
            }
            $message = 'Please select a guest and room type.';
        }

        if ($action === 'save_reservation') {
            $guestId = $_POST['reservation_guest'] ?? '';
            $checkin = $_POST['checkin'] ?? '';
            $checkout = $_POST['checkout'] ?? '';
            $room = $_POST['reservation_room'] ?? '';
            $status = $_POST['status'] ?? '';

            $guestExists = false;
            foreach ($guests as $guest) {
                if (($guest['id'] ?? '') === $guestId) {
                    $guestExists = true;
                    break;
                }
            }

            if (
                $guestExists &&
                $checkin !== '' &&
                $checkout !== '' &&
                strtotime($checkout) > strtotime($checkin) &&
                in_array($room, ['Standard', 'Deluxe', 'Suite', 'Presidential'], true) &&
                in_array($status, ['Pending', 'Confirmed', 'Cancelled'], true)
            ) {
                $reservations[] = [
                    'id' => uniqid('reservation_'),
                    'guest_id' => $guestId,
                    'checkin' => $checkin,
                    'checkout' => $checkout,
                    'room' => $room,
                    'status' => $status,
                    'date' => date('Y-m-d')
                ];

                saveData($reservationFile, $reservations);
                header('Location: index.php?success=reservation');
                exit;
            }
            $message = 'Please check the guest, room, dates and status.';
        }

        // MODULE 4: STAFF NOTIFICATION
        if ($action === 'save_notification') {
            $guestId = $_POST['notification_guest'] ?? '';
            $subject = trim($_POST['subject'] ?? '');
            $details = trim($_POST['details'] ?? '');
            $priority = $_POST['priority'] ?? '';

            $guestExists = false;
            foreach ($guests as $guest) {
                if (($guest['id'] ?? '') === $guestId) {
                    $guestExists = true;
                    break;
                }
            }

            if (
                $guestExists &&
                $subject !== '' &&
                $details !== '' &&
                in_array($priority, ['Normal', 'High', 'Urgent'], true)
            ) {
                $notifications[] = [
                    'id' => uniqid('notification_'),
                    'guest_id' => $guestId,
                    'subject' => $subject,
                    'details' => $details,
                    'priority' => $priority,
                    'date' => date('Y-m-d H:i'),
                    'status' => 'Pending'
                ];

                saveData($notificationFile, $notifications);
                header('Location: index.php?success=notification');
                exit;
            }
            $message = 'Please complete all notification fields.';
        }
    }
}

if (isset($_GET['success'])) {
    $messages = [
        'guest' => 'VIP guest registered successfully!',
        'preference' => 'Guest preferences saved successfully!',
        'reservation' => 'Reservation saved successfully!',
        'notification' => 'Staff notification saved successfully!'
    ];
    $message = $messages[$_GET['success']] ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Silent Hill Hotel - VIP Guest Management</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f2f4f7;
    color: #263238;
}

header {
    background: #173b35;
    color: white;
    padding: 25px;
    text-align: center;
}

nav {
    background: #24564b;
    padding: 14px;
    text-align: center;
}

nav a {
    color: white;
    text-decoration: none;
    margin: 8px;
    font-weight: bold;
}

.container {
    width: 92%;
    max-width: 1100px;
    margin: 25px auto;
}

.card {
    background: white;
    padding: 22px;
    margin-bottom: 25px;
    border-radius: 9px;
    box-shadow: 0 2px 8px #00000012;
}

h2 { color: #24564b; }

input, select, textarea {
    display: block;
    width: 100%;
    padding: 11px;
    margin: 8px 0 15px;
    border: 1px solid #ccd3d1;
    border-radius: 5px;
    font-size: 15px;
}

button {
    background: #24564b;
    color: white;
    padding: 12px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-weight: bold;
}

button:hover { background: #173b35; }

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
}

th, td {
    border: 1px solid #ddd;
    padding: 10px;
    text-align: left;
    overflow-wrap: anywhere;
}

th {
    background: #24564b;
    color: white;
}

.message {
    background: #e1f3e7;
    color: #176332;
    padding: 14px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.priority-urgent { color: #b00020; font-weight: bold; }
.priority-high { color: #a45b00; font-weight: bold; }
.priority-normal { color: #176332; font-weight: bold; }

footer {
    background: #173b35;
    color: white;
    text-align: center;
    padding: 18px;
    margin-top: 35px;
}

@media(max-width: 650px) {
    table { font-size: 12px; }
    th, td { padding: 6px; }
    nav a { display: inline-block; margin: 7px; }
}
</style>
</head>

<body>

<header>
    <h1>Silent Hill Hotel</h1>
    <p>VIP Guest Relations Management Information System</p>
</header>

<nav>
    <a href="#registration">VIP Registration</a>
    <a href="#preferences">Preferences</a>
    <a href="#reservations">Reservations</a>
    <a href="#notifications">Staff Notifications</a>
    <a href="#reports">Reports</a>
</nav>

<div class="container">

<?php if ($message !== ''): ?>
    <div class="message"><?= clean($message) ?></div>
<?php endif; ?>

<section class="card" id="registration">
<h2>1. VIP Guest Registration</h2>

<form method="POST">
<input type="hidden" name="token" value="<?= clean($_SESSION['token']) ?>">
<input type="hidden" name="action" value="register_guest">

<label>Guest Full Name</label>
<input type="text" name="name" required>

<label>Phone Number</label>
<input type="tel" name="phone" required>

<label>Email Address</label>
<input type="email" name="email" required>

<label>VIP Level</label>
<select name="level" required>
<option value="">Select VIP Level</option>
<option>Platinum</option>
<option>Gold</option>
<option>Silver</option>
</select>

<button type="submit">Register Guest</button>
</form>

<h3>Registered VIP Guests (<?= count($guests) ?>)</h3>
<table>
<tr><th>Name</th><th>Phone</th><th>Email</th><th>VIP Level</th></tr>
<?php foreach ($guests as $guest): ?>
<tr>
<td><?= clean($guest['name'] ?? '') ?></td>
<td><?= clean($guest['phone'] ?? '') ?></td>
<td><?= clean($guest['email'] ?? '') ?></td>
<td><?= clean($guest['level'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</table>
</section>

<section class="card" id="preferences">
<h2>2. Preference Management</h2>

<form method="POST">
<input type="hidden" name="token" value="<?= clean($_SESSION['token']) ?>">
<input type="hidden" name="action" value="save_preference">

<label>Select Registered Guest</label>
<select name="guest_id" required>
<option value="">Choose Guest</option>
<?php foreach ($guests as $guest): ?>
<option value="<?= clean($guest['id']) ?>"><?= clean($guest['name']) ?></option>
<?php endforeach; ?>
</select>

<label>Preferred Room Type</label>
<select name="room" required>
<option value="">Select Room</option>
<option>Standard</option>
<option>Deluxe</option>
<option>Suite</option>
<option>Presidential</option>
</select>

<label>Food Preferences</label>
<input type="text" name="food" placeholder="Example: Vegetarian">

<label>Preferred Drinks</label>
<input type="text" name="drinks" placeholder="Example: Orange juice">

<label>Special Requests</label>
<textarea name="requests" rows="3"></textarea>

<button type="submit">Save Preferences</button>
</form>

<h3>Saved Guest Preferences</h3>
<table>
<tr><th>Guest</th><th>Room</th><th>Food</th><th>Drinks</th><th>Requests</th></tr>
<?php foreach ($preferences as $p): ?>
<tr>
<td><?= clean(guestName($p['guest_id'] ?? '', $guests)) ?></td>
<td><?= clean($p['room'] ?? '') ?></td>
<td><?= clean($p['food'] ?? '') ?></td>
<td><?= clean($p['drinks'] ?? '') ?></td>
<td><?= clean($p['requests'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</table>
</section>

<section class="card" id="reservations">
<h2>3. Reservation Tracking</h2>

<form method="POST">
<input type="hidden" name="token" value="<?= clean($_SESSION['token']) ?>">
<input type="hidden" name="action" value="save_reservation">

<label>Select VIP Guest</label>
<select name="reservation_guest" required>
<option value="">Choose Registered Guest</option>
<?php foreach ($guests as $guest): ?>
<option value="<?= clean($guest['id']) ?>"><?= clean($guest['name']) ?></option>
<?php endforeach; ?>
</select>

<label>Check-in Date</label>
<input type="date" name="checkin" required>

<label>Check-out Date</label>
<input type="date" name="checkout" required>

<label>Room Type</label>
<select name="reservation_room" required>
<option value="">Select Room</option>
<option>Standard</option>
<option>Deluxe</option>
<option>Suite</option>
<option>Presidential</option>
</select>

<label>Reservation Status</label>
<select name="status" required>
<option>Pending</option>
<option>Confirmed</option>
<option>Cancelled</option>
</select>

<button type="submit">Save Reservation</button>
</form>

<h3>Reservation Records</h3>
<table>
<tr><th>Guest</th><th>Check-in</th><th>Check-out</th><th>Room</th><th>Status</th></tr>
<?php foreach ($reservations as $r): ?>
<tr>
<td><?= clean(guestName($r['guest_id'] ?? '', $guests)) ?></td>
<td><?= clean($r['checkin'] ?? '') ?></td>
<td><?= clean($r['checkout'] ?? '') ?></td>
<td><?= clean($r['room'] ?? '') ?></td>
<td><?= clean($r['status'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</table>
</section>

<!-- MODULE 4 -->
<section class="card" id="notifications">
<h2>4. Staff Notification</h2>
<p>Record important VIP guest requests for hotel staff to review.</p>

<form method="POST">
<input type="hidden" name="token" value="<?= clean($_SESSION['token']) ?>">
<input type="hidden" name="action" value="save_notification">

<label>Select VIP Guest</label>
<select name="notification_guest" required>
<option value="">Choose Guest</option>
<?php foreach ($guests as $guest): ?>
<option value="<?= clean($guest['id']) ?>"><?= clean($guest['name']) ?></option>
<?php endforeach; ?>
</select>

<label>Notification Subject</label>
<input type="text" name="subject" placeholder="Example: VIP guest arrival" required>

<label>Notification Details</label>
<textarea name="details" rows="3" placeholder="Describe what staff should know" required></textarea>

<label>Priority Level</label>
<select name="priority" required>
<option value="">Select Priority</option>
<option>Normal</option>
<option>High</option>
<option>Urgent</option>
</select>

<button type="submit">Send Staff Notification</button>
</form>

<h3>Staff Notifications</h3>
<table>
<tr>
<th>Guest</th>
<th>Subject</th>
<th>Details</th>
<th>Priority</th>
<th>Date</th>
<th>Status</th>
</tr>

<?php foreach ($notifications as $n): ?>
  
<tr>
<td><?= clean(guestName($n['guest_id'] ?? '', $guests)) ?></td>
<td><?= clean($n['subject'] ?? '') ?></td>
<td><?= clean($n['details'] ?? '') ?></td>
<td class="priority-<?= strtolower(clean($n['priority'] ?? 'normal')) ?>">
<?= clean($n['priority'] ?? '') ?>
</td>
<td><?= clean($n['date'] ?? '') ?></td>
<td><?= clean($n['status'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</table>
</section>

<!-- SYSTEM OVERVIEW -->
<section class="card" id="reports">
<h2>5. System Overview</h2>
<p><strong>Total VIP Guests:</strong> <?= count($guests) ?></p>
<p><strong>Total Guest Preferences:</strong> <?= count($preferences) ?></p>
<p><strong>Total Reservations:</strong> <?= count($reservations) ?></p>
<p><strong>Confirmed Reservations:</strong>
<?= count(array_filter($reservations, function($r) {
    return ($r['status'] ?? '') === 'Confirmed';
})) ?>
</p>
<p><strong>Total Staff Notifications:</strong> <?= count($notifications) ?></p>
<p><strong>Urgent Notifications:</strong>
<?= count(array_filter($notifications, function($n) {
    return ($n['priority'] ?? '') === 'Urgent';
})) ?>
</p>
<h3>Download Hotel Reports</h3>
<p>Download guest details, preferences, reservations and staff notifications.</p>

<a href="index.php?export=csv">
    <button type="button">Download CSV Report</button>
</a>

</section>

</div>

<footer>
<p>Silent Hill Hotel | VIP Guest Relations Management System</p>
</footer>

</body>
</html>