<?php

/************************************************************
 * JalakFashion CRM - Single-file PHP App (index.php)
 * PHP 8+, PDO, prepared statements, AdminLTE (CDNs), CSRF.
 * DB name: jalakfashion
 *
 * --- Inline Config (edit as needed) ---
 ************************************************************/
$CONFIG = [
    'db_host' => '127.0.0.1',
    'db_user' => 'root',
    'db_pass' => '',
    'db_name' => 'jalakfashion',
    'display_errors' => true,         // flip to false in prod
    'app_name' => 'JalakFashion CRM'
];

if ($CONFIG['display_errors']) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

session_start();

/************************************************************
 * Helpers: JSON response, AJAX detection, CSRF
 ************************************************************/
function is_ajax(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}
function json_out($data, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_check()
{
    $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        if (is_ajax()) json_out(['ok' => false, 'error' => 'CSRF failed'], 400);
        die('CSRF validation failed.');
    }
}

/************************************************************
 * PDO connection helpers
 ************************************************************/
function pdo_root(array $cfg): PDO
{
    // Connect without database (for installer)
    $dsn = "mysql:host={$cfg['db_host']};charset=utf8mb4";
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}
function pdo_db(array $cfg): PDO
{
    $dsn = "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

/************************************************************
 * Install action: run sql/jalakfashion.sql
 ************************************************************/
$action = $_GET['action'] ?? '';
$section = $_GET['section'] ?? 'dashboard';

if ($action === 'install') {
    try {
        $pdo = pdo_root($CONFIG);
        $sqlFile = __DIR__ . '/sql/jalakfashion.sql';
        if (!is_file($sqlFile)) {
            die('SQL file not found: sql/jalakfashion.sql');
        }
        $sql = file_get_contents($sqlFile);

        // Split statements naïvely on semicolons at EOL. Handle DELIMITER-less file.
        $stmts = preg_split('/;[\r\n]+/m', $sql);
        foreach ($stmts as $stmt) {
            $s = trim($stmt);
            if ($s === '') continue;
            $pdo->exec($s);
        }
        // Redirect to login
        if (is_ajax()) json_out(['ok' => true, 'message' => 'Installation complete']);
        header('Location: index.php?installed=1');
        exit;
    } catch (Throwable $e) {
        if (is_ajax()) json_out(['ok' => false, 'error' => $e->getMessage()], 500);
        die("Install error: " . htmlspecialchars($e->getMessage()));
    }
}

/************************************************************
 * Normal DB connection (post-install)
 ************************************************************/
try {
    $pdo = pdo_db($CONFIG);
} catch (Throwable $e) {
    // Helpful message if DB not created yet
    $msg = "Database connection failed. Run installer: <a href='?action=install'>?action=install</a>";
    die($msg);
}

/************************************************************
 * Auth: login/logout (session)
 ************************************************************/
function current_user()
{
    return $_SESSION['user'] ?? null;
}
function require_login()
{
    if (!current_user()) {
        header('Location: index.php?section=login');
        exit;
    }
}
if ($action === 'logout') {
    session_destroy();
    header('Location: index.php?section=login&logged_out=1');
    exit;
}
if ($section === 'login' && $action === 'do_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT id, name, email, password, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user &&  $user['password']) {
        $_SESSION['user'] = ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']];
        header('Location: index.php');
        exit;
    } else {
        $login_error = 'Invalid credentials';
    }
}

/************************************************************
 * Utilities: pagination
 ************************************************************/
function paginate(int $total, int $per_page = 10): array
{
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pages = max(1, (int)ceil($total / $per_page));
    $page = min($page, $pages);
    $offset = ($page - 1) * $per_page;
    return [$page, $pages, $per_page, $offset];
}

/************************************************************
 * Routing: Products CRUD
 ************************************************************/
if ($section === 'products') {
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();

    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $code = strtoupper(trim($_POST['product_code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $size = trim($_POST['size'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $esp = (float)($_POST['default_estimated_selling_price'] ?? 0);
        $stmt = $pdo->prepare("INSERT INTO products (product_code, name, size, description, status, default_estimated_selling_price, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        try {
            $stmt->execute([$code, $name, $size, $desc, $status, $esp]);
            $res = ['ok' => true, 'message' => 'Product created'];
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (is_ajax()) json_out($res);
        header('Location: index.php?section=products');
        exit;
    }
    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $code = strtoupper(trim($_POST['product_code'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $size = trim($_POST['size'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $esp = (float)($_POST['default_estimated_selling_price'] ?? 0);
        $stmt = $pdo->prepare("UPDATE products SET product_code=?, name=?, size=?, description=?, status=?, default_estimated_selling_price=? WHERE id=?");
        try {
            $stmt->execute([$code, $name, $size, $desc, $status, $esp, $id]);
            $res = ['ok' => true, 'message' => 'Product updated'];
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (is_ajax()) json_out($res);
        header('Location: index.php?section=products');
        exit;
    }
}

/************************************************************
 * Routing: Suppliers CRUD
 ************************************************************/
if ($section === 'suppliers') {
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();

    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $stmt = $pdo->prepare("INSERT INTO suppliers (name, address, phone, email, note, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        try {
            $stmt->execute([$name, $address, $phone, $email, $note]);
            $res = ['ok' => true, 'message' => 'Supplier created'];
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (is_ajax()) json_out($res);
        header('Location: index.php?section=suppliers');
        exit;
    }
    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $stmt = $pdo->prepare("UPDATE suppliers SET name=?, address=?, phone=?, email=?, note=? WHERE id=?");
        try {
            $stmt->execute([$name, $address, $phone, $email, $note, $id]);
            $res = ['ok' => true, 'message' => 'Supplier updated'];
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (is_ajax()) json_out($res);
        header('Location: index.php?section=suppliers');
        exit;
    }
}

/************************************************************
 * Routing: Purchases + inventory unit generation
 ************************************************************/
if ($section === 'purchases') {
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();

    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Validate inputs
        $product_id = (int)($_POST['product_id'] ?? 0);
        $supplier_id = (int)($_POST['supplier_id'] ?? 0);
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        $purchase_price = (float)($_POST['purchase_price'] ?? 0);
        $est_price = (float)($_POST['estimated_selling_price'] ?? 0);

        try {
            $pdo->beginTransaction();

            // Insert purchase
            $stmt = $pdo->prepare("INSERT INTO purchases (supplier_id, product_id, quantity, purchase_price, estimated_selling_price, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->execute([$supplier_id, $product_id, $qty, $purchase_price, $est_price]);
            $purchase_id = (int)$pdo->lastInsertId();

            // Fetch product code
            $p = $pdo->prepare("SELECT product_code FROM products WHERE id=?");
            $p->execute([$product_id]);
            $prod = $p->fetch();
            if (!$prod) throw new Exception('Product not found');
            $product_code = strtoupper($prod['product_code']);

            // Determine current max serial for this product
            $maxStmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(unit_code, LENGTH(?) + 1) AS UNSIGNED)) AS max_serial FROM inventory_units WHERE unit_code LIKE CONCAT(?, '%')");
            $maxStmt->execute([$product_code, $product_code]);
            $maxSerial = (int)($maxStmt->fetch()['max_serial'] ?? 0);

            // Generate units
            $ins = $pdo->prepare("INSERT INTO inventory_units (unit_code, product_id, supplier_id, purchase_price, estimated_sell_price, status, created_at) VALUES (?, ?, ?, ?, ?, 'available', NOW())");
            $generated = [];
            for ($i = 1; $i <= $qty; $i++) {
                $serial = $maxSerial + $i;
                $unit_code = $product_code . str_pad((string)$serial, 3, '0', STR_PAD_LEFT);
                $ins->execute([$unit_code, $product_id, $supplier_id, $purchase_price, $est_price]);
                $generated[] = $unit_code;
            }

            $pdo->commit();
            $payload = ['ok' => true, 'purchase_id' => $purchase_id, 'unit_codes' => $generated];
            if (is_ajax()) json_out($payload);
            $_SESSION['flash'] = 'Purchase created: ' . count($generated) . ' units';
            header('Location: index.php?section=purchases');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (is_ajax()) json_out(['ok' => false, 'error' => $e->getMessage()], 500);
            $_SESSION['flash_error'] = $e->getMessage();
            header('Location: index.php?section=purchases');
            exit;
        }
    }
}

/************************************************************
 * Routing: Inventory - status update (single/bulk)
 ************************************************************/
if ($section === 'inventory') {
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();

    if ($action === 'update_unit_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $unit_id = (int)($_POST['unit_id'] ?? 0);
        $status = $_POST['status'] ?? 'available';
        $allowed = ['available', 'sold', 'reserved', 'returned'];
        if (!in_array($status, $allowed, true)) $status = 'available';
        $stmt = $pdo->prepare("UPDATE inventory_units SET status=? WHERE id=?");
        try {
            $stmt->execute([$status, $unit_id]);
            $res = ['ok' => true, 'message' => 'Unit status updated'];
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (is_ajax()) json_out($res);
        header('Location: index.php?section=inventory');
        exit;
    }
    if ($action === 'bulk_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $ids = $_POST['unit_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $status = $_POST['status'] ?? 'available';
        $allowed = ['available', 'sold', 'reserved', 'returned'];
        if (!in_array($status, $allowed, true)) $status = 'available';
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE inventory_units SET status=? WHERE id=?");
            foreach ($ids as $id) {
                $stmt->execute([$status, (int)$id]);
            }
            $pdo->commit();
            $res = ['ok' => true, 'message' => 'Bulk status updated', 'count' => count($ids)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $res = ['ok' => false, 'error' => $e->getMessage()];
        }
        if (is_ajax()) json_out($res);
        header('Location: index.php?section=inventory');
        exit;
    }
}

/************************************************************
 * Routing: Billing (lookup, create bill, return)
 ************************************************************/
if ($section === 'billing') {
    require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_check();

    if ($action === 'lookup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $stmt = $pdo->prepare("SELECT iu.id as unit_id, iu.unit_code, iu.estimated_sell_price, p.id as product_id, p.name, p.size, iu.status 
                               FROM inventory_units iu 
                               JOIN products p ON p.id = iu.product_id
                               WHERE iu.unit_code = ?");
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        if (!$row) json_out(['ok' => false, 'error' => 'Unit not found'], 404);
        if ($row['status'] !== 'available') json_out(['ok' => false, 'error' => 'Unit not available (' . $row['status'] . ')'], 409);
        json_out(['ok' => true, 'data' => $row]);
    }

    if ($action === 'create_bill' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // expected payload: items [{unit_id, product_id, price, qty}], discount_amount
        $raw = $_POST['items'] ?? '[]';
        $items = json_decode($raw, true);
        if (!is_array($items) || empty($items)) json_out(['ok' => false, 'error' => 'No items'], 400);
        $discount_amount = (float)($_POST['discount_amount'] ?? 0);

        try {
            $pdo->beginTransaction();
            // Check all units availability
            $unitIds = array_map(fn($it) => (int)$it['unit_id'], $items);
            $in = implode(',', array_fill(0, count($unitIds), '?'));
            $check = $pdo->prepare("SELECT id, status FROM inventory_units WHERE id IN ($in) FOR UPDATE");
            $check->execute($unitIds);
            $statuses = $check->fetchAll();
            $statusMap = [];
            foreach ($statuses as $s) $statusMap[$s['id']] = $s['status'];
            foreach ($unitIds as $id) {
                if (($statusMap[$id] ?? '') !== 'available') {
                    throw new Exception("Unit $id is not available");
                }
            }

            // Create bill
            $sum = 0;
            foreach ($items as $it) {
                $sum += ((float)$it['price']) * ((int)($it['qty'] ?? 1));
            }
            $total = max(0, $sum - $discount_amount);
            $invoice_no = 'INV' . date('YmdHis') . random_int(100, 999);
            $insBill = $pdo->prepare("INSERT INTO bills (invoice_no, user_id, total, discount_amount, created_at) VALUES (?, ?, ?, ?, NOW())");
            $insBill->execute([$invoice_no, current_user()['id'], $total, $discount_amount]);
            $bill_id = (int)$pdo->lastInsertId();

            // Insert bill items
            $insItem = $pdo->prepare("INSERT INTO bill_items (bill_id, unit_id, product_id, price, qty) VALUES (?, ?, ?, ?, ?)");
            $updUnit = $pdo->prepare("UPDATE inventory_units SET status='sold' WHERE id=?");
            foreach ($items as $it) {
                $insItem->execute([$bill_id, (int)$it['unit_id'], (int)$it['product_id'], (float)$it['price'], (int)$it['qty']]);
                $updUnit->execute([(int)$it['unit_id']]);
            }

            $pdo->commit();
            json_out(['ok' => true, 'bill_id' => $bill_id, 'invoice_no' => $invoice_no, 'total' => $total]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    if ($action === 'return_unit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Return a sold unit back to available; log lightweight entry
        $unit_id = (int)($_POST['unit_id'] ?? 0);
        try {
            $pdo->beginTransaction();

            // Ensure it was sold
            $s = $pdo->prepare("SELECT status FROM inventory_units WHERE id=? FOR UPDATE");
            $s->execute([$unit_id]);
            $row = $s->fetch();
            if (!$row) throw new Exception('Unit not found');
            if ($row['status'] !== 'sold') throw new Exception('Unit is not sold');

            // Create minimal returns_log if missing
            $pdo->exec("CREATE TABLE IF NOT EXISTS returns_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                unit_id INT NOT NULL,
                user_id INT NOT NULL,
                note VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $upd = $pdo->prepare("UPDATE inventory_units SET status='available' WHERE id=?");
            $upd->execute([$unit_id]);

            $ins = $pdo->prepare("
  INSERT INTO bills (invoice_no, user_id, total, discount_amount, created_at)
  VALUES (:invoice_no, :user_id, :total, :discount_amount, NOW())
");
            $ins->execute([
                ':invoice_no' => $invoiceNo,
                ':user_id' => $_SESSION['user_id'] ?? null,
                ':total' => $total,
                ':discount_amount' => $discountAmount,  // from your POST/calculation
            ]);


            $pdo->commit();
            json_out(['ok' => true, 'message' => 'Unit returned']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}

/************************************************************
 * Minimal QR print view: ?section=qr&code=UNIT_CODE
 * No admin chrome, print-friendly.
 ************************************************************/
if ($section === 'qr') {
    $code = strtoupper(trim($_GET['code'] ?? ''));
    if ($code === '') die('Missing code');
    $stmt = $pdo->prepare("SELECT iu.unit_code, iu.estimated_sell_price, p.name, p.size 
                           FROM inventory_units iu JOIN products p ON p.id=iu.product_id
                           WHERE iu.unit_code=?");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) die('Unit not found');
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <title>QR: <?= htmlspecialchars($row['unit_code']) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {
                font-family: Arial, sans-serif;
                padding: 16px;
            }

            .card {
                max-width: 380px;
                margin: 0 auto;
                border: 1px solid #ccc;
                padding: 16px;
            }

            h1 {
                font-size: 22px;
                margin: 0 0 8px;
            }

            .small {
                color: #666;
                font-size: 12px;
            }

            hr {
                margin: 12px 0;
            }

            .notes {
                height: 80px;
                border: 1px dashed #aaa;
                margin-top: 8px;
            }

            @media print {
                .noprint {
                    display: none;
                }
            }
        </style>
    </head>

    <body>
        <div class="card">
            <div id="qrcode" style="text-align:center; margin-bottom:12px;"></div>
            <h1><?= htmlspecialchars($row['unit_code']) ?></h1>
            <div><?= htmlspecialchars($row['name']) ?> — Size: <?= htmlspecialchars($row['size']) ?></div>
            <div class="small">Est. Price: ₹<?= number_format((float)$row['estimated_sell_price'], 2) ?></div>
            <hr>
            <div class="small">Notes:</div>
            <div class="notes"></div>
            <div class="noprint" style="text-align:center;margin-top:10px;">
                <button onclick="window.print()">Print</button>
            </div>
        </div>

        <!-- QRCode.js CDN -->
        <script src="https://cdn.jsdelivr.net/npm/qrcodejs/qrcode.min.js"></script>
        <script>
            new QRCode(document.getElementById("qrcode"), {
                text: window.location.href, // encode same URL
                width: 220,
                height: 220
            });
        </script>
    </body>

    </html>
<?php
    exit;
}

/************************************************************
 * Dashboard data helpers
 ************************************************************/
function count_table(PDO $pdo, string $table): int
{
    return (int)$pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}
function sold_today(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_units WHERE status='sold' AND DATE(created_at)=CURDATE()");
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}
function sales_last_30(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT DATE(created_at) d, SUM(total) s FROM bills
                         WHERE created_at >= (CURDATE() - INTERVAL 29 DAY)
                         GROUP BY DATE(created_at) ORDER BY d");
    $map = [];
    foreach ($stmt as $r) $map[$r['d']] = (float)$r['s'];
    $out = [];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $out[] = ['date' => $d, 'sum' => ($map[$d] ?? 0)];
    }
    return $out;
}

/************************************************************
 * Fetch data for UI sections
 ************************************************************/
if ($section === 'products' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_login();
    $total = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    [$page, $pages, $per, $offset] = paginate($total, 10);
    $stmt = $pdo->prepare("SELECT * FROM products ORDER BY id DESC LIMIT ? OFFSET ?");
    $stmt->bindValue(1, $per, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();
}
if ($section === 'suppliers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_login();
    $suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY id DESC")->fetchAll();
}
if ($section === 'inventory' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_login();
    $product_filter = trim($_GET['product_filter'] ?? '');
    if ($product_filter !== '') {
        $stmt = $pdo->prepare("SELECT iu.*, p.product_code FROM inventory_units iu JOIN products p ON p.id=iu.product_id
                               WHERE p.product_code LIKE ? ORDER BY iu.id DESC LIMIT 500");
        $stmt->execute(['%' . $product_filter . '%']);
        $units = $stmt->fetchAll();
    } else {
        $stmt = $pdo->query("SELECT iu.*, p.product_code FROM inventory_units iu JOIN products p ON p.id=iu.product_id ORDER BY iu.id DESC LIMIT 500");
        $units = $stmt->fetchAll();
    }
}
if ($section === 'purchases' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_login();
    $prods = $pdo->query("SELECT id, product_code, name, size, default_estimated_selling_price FROM products WHERE status='active' ORDER BY name")->fetchAll();
    $sups  = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll();
}
if ($section === 'dashboard' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_login();
    $dash = [
        'products'  => count_table($pdo, 'products'),
        'suppliers' => count_table($pdo, 'suppliers'),
        'units'     => count_table($pdo, 'inventory_units'),
        'sold_today' => sold_today($pdo),
        'series'    => sales_last_30($pdo)
    ];
}

/************************************************************
 * HTML: Login page (if not logged)
 ************************************************************/
if (!current_user() && $section !== 'login') {
    header('Location: index.php?section=login');
    exit;
}
if ($section === 'login') {
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="utf-8">
        <title>Login - <?= $CONFIG['app_name'] ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
    </head>

    <body class="hold-transition login-page">
        <div class="login-box">
            <div class="card card-outline card-primary">
                <div class="card-header text-center">
                    <a href="#" class="h1"><b>Jalak</b>Fashion</a>
                </div>
                <div class="card-body">
                    <p class="login-box-msg">Sign in to start your session</p>
                    <?php if (!empty($login_error)): ?>
                        <div class="alert alert-danger"><?= $login_error ?></div>
                    <?php endif; ?>
                    <form method="post" action="?section=login&action=do_login">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <div class="input-group mb-3">
                            <input type="email" name="email" class="form-control" placeholder="Email" required>
                            <div class="input-group-append">
                                <div class="input-group-text"><span class="fas fa-envelope"></span></div>
                            </div>
                        </div>
                        <div class="input-group mb-3">
                            <input type="password" name="password" class="form-control" placeholder="Password" required>
                            <div class="input-group-append">
                                <div class="input-group-text"><span class="fas fa-lock"></span></div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-8"></div>
                            <div class="col-4"><button type="submit" class="btn btn-primary btn-block">Sign In</button></div>
                        </div>
                    </form>
                    <p class="mt-2 small">If database not installed, run <a href="?action=install">installer</a>.</p>
                </div>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
    </body>

    </html>
<?php
    exit;
}

/************************************************************
 * Admin Layout + Sections
 ************************************************************/
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title><?= $CONFIG['app_name'] ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- AdminLTE / Bootstrap / Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        .badge-status {
            font-size: 11px;
        }

        .unit-tile {
            width: 90px;
            height: 90px;
            border-radius: 8px;
            margin: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            cursor: pointer;
        }

        .unit-available {
            background: #e7f7ea;
            border: 1px solid #7ad08e;
            color: #256f38;
        }

        .unit-sold {
            background: #fde8e8;
            border: 1px solid #f29b9b;
            color: #7a2323;
        }

        .unit-reserved {
            background: #e8ecfd;
            border: 1px solid #9bb6f2;
            color: #23377a;
        }

        .unit-returned {
            background: #fff7d6;
            border: 1px solid #f2d27a;
            color: #7a5f23;
        }

        #qrBatch .qr-item {
            width: 180px;
            padding: 6px;
            margin: 6px;
            border: 1px dashed #ddd;
            border-radius: 8px;
            text-align: center;
        }
    </style>
</head>

<body class="hold-transition sidebar-mini layout-fixed">
    <div class="wrapper">
        <!-- Navbar -->
        <nav class="main-header navbar navbar-expand navbar-light bg-white border-bottom">
            <ul class="navbar-nav">
                <li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a></li>
                <li class="nav-item d-none d-sm-inline-block"><a href="index.php" class="nav-link">Dashboard</a></li>
            </ul>
            <form class="form-inline ml-3" id="scannerForm" onsubmit="return false;">
                <div class="input-group input-group-sm">
                    <input class="form-control form-control-navbar" id="scannerInput" type="search" placeholder="Scan/Enter UNIT CODE" aria-label="Search">
                    <div class="input-group-append">
                        <button class="btn btn-navbar" id="scannerGo"><i class="fas fa-qrcode"></i></button>
                    </div>
                </div>
                <span class="ml-2 text-muted small">Billing scanner</span>
            </form>
            <ul class="navbar-nav ml-auto">
                <li class="nav-item mr-2 align-self-center text-muted small">Hello, <?= htmlspecialchars($user['name']) ?></li>
                <li class="nav-item"><a class="nav-link text-danger" href="?action=logout"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
            </ul>
        </nav>

        <!-- Sidebar -->
        <aside class="main-sidebar sidebar-light-primary elevation-4">
            <a href="index.php" class="brand-link">
                <span class="brand-text font-weight-light"><b>Jalak</b>Fashion</span>
            </a>
            <div class="sidebar">
                <nav class="mt-2">
                    <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview">
                        <li class="nav-item"><a href="?section=dashboard" class="nav-link <?= $section === 'dashboard' ? 'active' : '' ?>"><i class="nav-icon fas fa-tachometer-alt"></i>
                                <p>Dashboard</p>
                            </a></li>
                        <li class="nav-item"><a href="?section=products" class="nav-link <?= $section === 'products' ? 'active' : '' ?>"><i class="nav-icon fas fa-box"></i>
                                <p>Products</p>
                            </a></li>
                        <li class="nav-item"><a href="?section=suppliers" class="nav-link <?= $section === 'suppliers' ? 'active' : '' ?>"><i class="nav-icon fas fa-truck"></i>
                                <p>Suppliers</p>
                            </a></li>
                        <li class="nav-item"><a href="?section=purchases" class="nav-link <?= $section === 'purchases' ? 'active' : '' ?>"><i class="nav-icon fas fa-shopping-cart"></i>
                                <p>Purchases</p>
                            </a></li>
                        <li class="nav-item"><a href="?section=inventory" class="nav-link <?= $section === 'inventory' ? 'active' : '' ?>"><i class="nav-icon fas fa-warehouse"></i>
                                <p>Inventory</p>
                            </a></li>
                        <li class="nav-item"><a href="?section=billing" class="nav-link <?= $section === 'billing' ? 'active' : '' ?>"><i class="nav-icon fas fa-receipt"></i>
                                <p>Billing</p>
                            </a></li>
                        <li class="nav-item"><a href="?section=sales" class="nav-link <?= $section === 'sales' ? 'active' : '' ?>"><i class="nav-icon fas fa-receipt"></i>
                                <p>sales</p>
                            </a></li>
                        <li class="nav-item"><a href="?section=returns" class="nav-link <?= $section === 'returns' ? 'active' : '' ?>"><i class="nav-icon fas fa-undo"></i>
                                <p>Returns</p>
                            </a></li>
                    </ul>
                </nav>
            </div>
        </aside>

        <!-- Content Wrapper -->
        <div class="content-wrapper">
            <section class="content pt-3">
                <div class="container-fluid">
                    <?php if (!empty($_SESSION['flash'])): ?>
                        <div class="alert alert-success"><?= $_SESSION['flash'];
                                                            unset($_SESSION['flash']); ?></div>
                    <?php endif;
                    if (!empty($_SESSION['flash_error'])): ?>
                        <div class="alert alert-danger"><?= $_SESSION['flash_error'];
                                                        unset($_SESSION['flash_error']); ?></div>
                    <?php endif; ?>

                    <?php if ($section === 'dashboard'): ?>
                        <div class="row">
                            <div class="col-lg-3 col-6">
                                <div class="small-box bg-info">
                                    <div class="inner">
                                        <h3><?= $dash['products'] ?></h3>
                                        <p>Total Products</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-box"></i></div>
                                </div>
                            </div>
                            <div class="col-lg-3 col-6">
                                <div class="small-box bg-success">
                                    <div class="inner">
                                        <h3><?= $dash['suppliers'] ?></h3>
                                        <p>Total Suppliers</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-truck"></i></div>
                                </div>
                            </div>
                            <div class="col-lg-3 col-6">
                                <div class="small-box bg-warning">
                                    <div class="inner">
                                        <h3><?= $dash['units'] ?></h3>
                                        <p>Total Units</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-warehouse"></i></div>
                                </div>
                            </div>
                            <div class="col-lg-3 col-6">
                                <div class="small-box bg-danger">
                                    <div class="inner">
                                        <h3><?= $dash['sold_today'] ?></h3>
                                        <p>Sold Today</p>
                                    </div>
                                    <div class="icon"><i class="fas fa-cash-register"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Sales - Last 30 days</h3>
                            </div>
                            <div class="card-body">
                                <canvas id="salesChart" height="90"></canvas>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($section === 'products'): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Products</h3>
                                <div class="card-tools">
                                    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#productModal"><i class="fas fa-plus"></i> Add</button>
                                </div>
                            </div>
                            <div class="card-body table-responsive p-0">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Code</th>
                                            <th>Name</th>
                                            <th>Size</th>
                                            <th>Est. Price</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($products as $p): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($p['product_code']) ?></td>
                                                <td><?= htmlspecialchars($p['name']) ?></td>
                                                <td><?= htmlspecialchars($p['size']) ?></td>
                                                <td>₹<?= number_format((float)$p['default_estimated_selling_price'], 2) ?></td>
                                                <td><span class="badge badge-<?= $p['status'] === 'active' ? 'success' : 'secondary' ?> badge-status"><?= $p['status'] ?></span></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-secondary"
                                                        onclick='editProduct(<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-footer">
                                <?php
                                $q = $_GET;
                                unset($q['page']);
                                $base = 'index.php?section=products&' . http_build_query($q);
                                ?>
                                <nav>
                                    <ul class="pagination pagination-sm m-0 float-right">
                                        <?php for ($i = 1; $i <= $pages; $i++): ?>
                                            <li class="page-item <?= $i == $page ? 'active' : '' ?>"><a class="page-link" href="<?= $base ?>&page=<?= $i ?>"><?= $i ?></a></li>
                                        <?php endfor; ?>
                                    </ul>
                                </nav>
                            </div>
                        </div>

                        <!-- Product Modal -->
                        <div class="modal fade" id="productModal">
                            <div class="modal-dialog">
                                <form class="modal-content" method="post" id="productForm" action="?section=products&action=create">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="id" id="prod_id">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="prod_title">Add Product</h5><button type="button" class="close" data-dismiss="modal">&times;</button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="form-group"><label>Product Code</label><input name="product_code" id="prod_code" class="form-control" required></div>
                                        <div class="form-group"><label>Name</label><input name="name" id="prod_name" class="form-control" required></div>
                                        <div class="form-group"><label>Size</label><input name="size" id="prod_size" class="form-control"></div>
                                        <div class="form-group"><label>Description</label><textarea name="description" id="prod_desc" class="form-control"></textarea></div>
                                        <div class="form-group"><label>Default Est. Sell Price</label><input type="number" step="0.01" name="default_estimated_selling_price" id="prod_esp" class="form-control" value="0"></div>
                                        <div class="form-group"><label>Status</label>
                                            <select name="status" id="prod_status" class="form-control">
                                                <option value="active">Active</option>
                                                <option value="inactive">Inactive</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($section === 'suppliers'): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Suppliers</h3>
                                <div class="card-tools">
                                    <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#supplierModal"><i class="fas fa-plus"></i> Add</button>
                                </div>
                            </div>
                            <div class="card-body table-responsive p-0">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Phone</th>
                                            <th>Email</th>
                                            <th>Note</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($suppliers as $s): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($s['name']) ?></td>
                                                <td><?= htmlspecialchars($s['phone']) ?></td>
                                                <td><?= htmlspecialchars($s['email']) ?></td>
                                                <td><?= htmlspecialchars($s['note']) ?></td>
                                                <td><button class="btn btn-sm btn-outline-secondary" onclick='editSupplier(<?= json_encode($s) ?>)'><i class="fas fa-edit"></i></button></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Supplier Modal -->
                        <div class="modal fade" id="supplierModal">
                            <div class="modal-dialog">
                                <form class="modal-content" method="post" id="supplierForm" action="?section=suppliers&action=create">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="id" id="sup_id">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="sup_title">Add Supplier</h5><button type="button" class="close" data-dismiss="modal">&times;</button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="form-group"><label>Name</label><input name="name" id="sup_name" class="form-control" required></div>
                                        <div class="form-group"><label>Address</label><input name="address" id="sup_address" class="form-control"></div>
                                        <div class="form-group"><label>Phone</label><input name="phone" id="sup_phone" class="form-control"></div>
                                        <div class="form-group"><label>Email</label><input type="email" name="email" id="sup_email" class="form-control"></div>
                                        <div class="form-group"><label>Note</label><input name="note" id="sup_note" class="form-control"></div>
                                    </div>
                                    <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($section === 'purchases'): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">New Purchase</h3>
                            </div>
                            <div class="card-body">
                                <form id="purchaseForm" method="post" action="?section=purchases&action=create" onsubmit="return submitPurchase(event);">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <div class="form-row">
                                        <div class="form-group col-md-3">
                                            <label>Product</label>
                                            <select name="product_id" class="form-control" required>
                                                <option value="">Select</option>
                                                <?php foreach ($prods as $p): ?>
                                                    <option value="<?= $p['id'] ?>" data-esp="<?= $p['default_estimated_selling_price'] ?>"><?= $p['product_code'] ?> — <?= $p['name'] ?> (<?= $p['size'] ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-3">
                                            <label>Supplier</label>
                                            <select name="supplier_id" class="form-control" required>
                                                <option value="">Select</option>
                                                <?php foreach ($sups as $s): ?>
                                                    <option value="<?= $s['id'] ?>"><?= $s['name'] ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group col-md-2">
                                            <label>Purchase Price (₹)</label>
                                            <input type="number" step="0.01" name="purchase_price" class="form-control" required>
                                        </div>
                                        <div class="form-group col-md-2">
                                            <label>Est. Sell Price (₹)</label>
                                            <input type="number" step="0.01" name="estimated_selling_price" class="form-control" required>
                                        </div>
                                        <div class="form-group col-md-2">
                                            <label>Quantity</label>
                                            <input type="number" name="quantity" class="form-control" value="1" min="1" required>
                                        </div>
                                    </div>
                                    <button class="btn btn-primary"><i class="fas fa-save"></i> Create Purchase & Generate Units</button>
                                </form>
                            </div>
                        </div>

                        <div class="card" id="qrBatchCard" style="display:none;">
                            <div class="card-header">
                                <h3 class="card-title">Generated Unit QR Codes</h3>
                                <div class="card-tools">
                                    <button class="btn btn-outline-secondary btn-sm" id="btnDownloadQR"><i class="fas fa-file-pdf"></i> Download QR PDF</button>
                                </div>
                            </div>
                            <div class="card-body d-flex flex-wrap" id="qrBatch"></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($section === 'inventory'): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Inventory</h3>
                            </div>
                            <div class="card-body">
                                <form class="form-inline mb-3">
                                    <input type="hidden" name="section" value="inventory">
                                    <div class="form-group mr-2">
                                        <input type="text" class="form-control" name="product_filter" value="<?= htmlspecialchars($_GET['product_filter'] ?? '') ?>" placeholder="Filter by Product Code">
                                    </div>
                                    <button class="btn btn-outline-primary btn-sm">Filter</button>
                                </form>

                                <!-- Grouped by product -->
                                <table class="table table-bordered table-striped">
                                    <thead>
                                        <tr>
                                            <th>Product Code</th>
                                            <th>Name</th>
                                            <th>Size</th>
                                            <th>Available</th>
                                            <th>Reserved</th>
                                            <th>Sold</th>
                                            <th>Returned</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $stmt = $pdo->query("
          SELECT p.id, p.product_code, p.name, p.size,
            SUM(CASE WHEN iu.status='available' THEN 1 ELSE 0 END) AS available_qty,
            SUM(CASE WHEN iu.status='reserved' THEN 1 ELSE 0 END) AS reserved_qty,
            SUM(CASE WHEN iu.status='sold' THEN 1 ELSE 0 END) AS sold_qty,
            SUM(CASE WHEN iu.status='returned' THEN 1 ELSE 0 END) AS returned_qty
          FROM products p
          LEFT JOIN inventory_units iu ON iu.product_id = p.id
          GROUP BY p.id
        ");
                                        foreach ($stmt as $row): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['product_code']) ?></td>
                                                <td><?= htmlspecialchars($row['name']) ?></td>
                                                <td><?= htmlspecialchars($row['size']) ?></td>
                                                <td><?= $row['available_qty'] ?></td>
                                                <td><?= $row['reserved_qty'] ?></td>
                                                <td><?= $row['sold_qty'] ?></td>
                                                <td><?= $row['returned_qty'] ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-info" data-toggle="collapse" data-target="#invUnits<?= $row['id'] ?>">Expand</button>
                                                </td>
                                            </tr>
                                            <tr class="collapse" id="invUnits<?= $row['id'] ?>">
                                                <td colspan="8">
                                                    <?php
                                                    $units2 = $pdo->prepare("SELECT id, unit_code, status FROM inventory_units WHERE product_id=? LIMIT 500");
                                                    $units2->execute([$row['id']]);
                                                    ?>
                                                    <table class="table table-sm table-hover">
                                                        <thead>
                                                            <tr>
                                                                <th>Unit Code</th>
                                                                <th>Status</th>
                                                                <th>Edit</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($units2 as $u2): ?>
                                                                <tr>
                                                                    <td><?= htmlspecialchars($u2['unit_code']) ?></td>
                                                                    <td><?= htmlspecialchars($u2['status']) ?></td>
                                                                    <td>
                                                                        <button
                                                                            class="btn btn-sm btn-primary"
                                                                            onclick='openUnitModal(<?= json_encode($u2) ?>)'>
                                                                            Edit
                                                                        </button>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Unit Modal -->
                        <div class="modal fade" id="unitModal">
                            <div class="modal-dialog">
                                <form class="modal-content" method="post" action="?section=inventory&action=update_unit_status" onsubmit="return postUnitStatus(event);">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <input type="hidden" name="unit_id" id="unit_id">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Update Unit</h5>
                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="form-group"><label>Unit Code</label><input id="unit_code" class="form-control" readonly></div>
                                        <div class="form-group"><label>Status</label>
                                            <select name="status" id="unit_status" class="form-control">
                                                <option value="available">available</option>
                                                <option value="reserved">reserved</option>
                                                <option value="sold">sold</option>
                                                <option value="returned">returned</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-footer"><button class="btn btn-primary">Save</button></div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
<?php if ($section === 'billing'): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">New Bill</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table" id="billTable">
                    <thead>
                        <tr>
                            <th>Unit Code</th>
                            <th>Product</th>
                            <th>Price (₹)</th>
                            <th>Qty</th>
                            <th>Subtotal</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3"></td>
                            <td class="text-right">Discount (₹)</td>
                            <td><input type="number" step="0.01" id="discount_amount" class="form-control" value="0"></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="4" class="text-right font-weight-bold">Total (₹)</td>
                            <td id="bill_total" class="font-weight-bold">0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Action Buttons -->
            <button class="btn btn-primary mb-2" onclick="initScanner()">
                <i class="fas fa-camera"></i> Open Camera Scanner
            </button>
            <button class="btn btn-success" id="btnFinalizeBill">
                <i class="fas fa-check"></i> Finalize Bill
            </button>
            <div id="billMsg" class="mt-2"></div>

            <!-- Camera Scanner Container -->
            <div id="reader" style="width:300px; display:none; margin-top:15px;"></div>
            <div id="scanResult" style="margin-top:10px; font-weight:bold; color:green;"></div>

            <hr>
            <div class="small text-muted">Tip: Scan unit codes to add items quickly.</div>
        </div>
    </div>

    <!-- QR Scanner Script -->
    <script src="https://unpkg.com/html5-qrcode/minified/html5-qrcode.min.js"></script>
    <script>
        async function initScanner() {
            try {
                // Request camera permission
                const stream = await navigator.mediaDevices.getUserMedia({ video: true });
                stream.getTracks().forEach(track => track.stop());

                // Show scanner div
                document.getElementById("reader").style.display = "block";
                startScanner();
            } catch (err) {
                alert("Camera permission denied or not available. Please allow camera access.");
                console.error("Permission error: ", err);
            }
        }

        function startScanner() {
            const qrCode = new Html5Qrcode("reader");
            qrCode.start(
                { facingMode: "environment" },
                { fps: 10, qrbox: 250 },
                qrCodeMessage => {
                    document.getElementById("scanResult").innerText = "Scanned: " + qrCodeMessage;
                    qrCode.stop();

                    // 🔹 Auto add scanned code to Bill Table
                    addScannedItem(qrCodeMessage);
                },
                errorMessage => { /* ignore scan errors */ }
            ).catch(err => {
                console.error("Camera start error: ", err);
            });
        }

        function addScannedItem(unitCode) {
            // TODO: You can modify this to fetch product details via AJAX/PHP
            const tbody = document.querySelector("#billTable tbody");
            const row = document.createElement("tr");

            row.innerHTML = `
                <td>${unitCode}</td>
                <td>Loading...</td>
                <td>0.00</td>
                <td><input type="number" class="form-control qty" value="1"></td>
                <td class="subtotal">0.00</td>
                <td><button class="btn btn-danger btn-sm removeRow">X</button></td>
            `;
            tbody.appendChild(row);
        }

        // Remove row handler
        document.addEventListener("click", function(e){
            if (e.target.classList.contains("removeRow")) {
                e.target.closest("tr").remove();
            }
        });
    </script>
    
<?php endif; ?>

                    <?php if ($section === 'returns'): ?>
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Return a Unit</h3>
                            </div>
                            <div class="card-body">
                                <form class="form-inline" onsubmit="return submitReturn(event);" action="?section=billing&action=return_unit" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                    <div class="form-group mr-2">
                                        <input type="text" class="form-control" id="return_code" placeholder="Enter UNIT CODE" required>
                                    </div>
                                    <button class="btn btn-warning">Process Return</button>
                                </form>
                                <div id="returnMsg" class="mt-2"></div>
                            </div>

                        </div>

                    <?php endif; ?>

                    <?php if ($section === 'sales'): ?>
                        <h3 class="mb-4">💰 Sales</h3>
                        <?php
                        $billId = isset($_GET['bill_id']) ? (int)$_GET['bill_id'] : 0;

                        if ($billId > 0):
                            $billStmt = $pdo->prepare("
  SELECT 
      b.*,
      b.discount_amount AS discount,  -- alias to 'discount'
      u.name AS user_name
  FROM bills b
  LEFT JOIN users u ON u.id = b.user_id
  WHERE b.id = ?
");


                            $billStmt->execute([$billId]);
                            $bill = $billStmt->fetch();
                            if ($bill):
                                $net = $bill['total'] - $bill['discount'];
                        ?>
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h5>Invoice #<?= htmlspecialchars($bill['invoice_no'] ?: $bill['id']) ?></h5>

                                    </div>
                                    <div class="card-body">
                                        <p><b>User:</b> <?= htmlspecialchars($bill['user_name'] ?? 'Admin') ?></p>
                                        <p><b>Date:</b> <?= $bill['created_at'] ?></p>
                                        <p><b>Total:</b> ₹<?= number_format($bill['total'], 2) ?></p>
                                        <p><b>Discount:</b> ₹<?= number_format($bill['discount'], 2) ?></p>
                                        <p><b>Net:</b> <span class="text-success">₹<?= number_format($net, 2) ?></span></p>
                                    </div>
                                </div>

                                <?php
                                $itemStmt = $pdo->prepare("
          SELECT bi.qty, bi.price, p.product_code, p.name, iu.unit_code
          FROM bill_items bi
          JOIN products p ON p.id=bi.product_id
          LEFT JOIN inventory_units iu ON iu.id=bi.unit_id
          WHERE bi.bill_id=?
      ");
                                $itemStmt->execute([$billId]);
                                ?>
                                <div class="card mb-3">
                                    <div class="card-header">
                                        <h6>Items</h6>
                                    </div>
                                    <div class="card-body table-responsive">
                                        <table class="table table-bordered table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Unit</th>
                                                    <th>Product</th>
                                                    <th>Price</th>
                                                    <th>Qty</th>
                                                    <th>Subtotal</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($itemStmt as $it): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($it['unit_code'] ?? '-') ?></td>
                                                        <td><?= htmlspecialchars($it['product_code'] . ' ' . $it['name']) ?></td>
                                                        <td><?= number_format($it['price'], 2) ?></td>
                                                        <td><?= $it['qty'] ?></td>
                                                        <td><?= number_format($it['price'] * $it['qty'], 2) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <a href="?section=sales" class="btn btn-secondary">← Back</a>
                                <button class="btn btn-info" onclick="window.print()">🖨 Print</button>
                                <button class="btn btn-danger">❌ Delete</button>

                            <?php else: ?>
                                <div class="alert alert-warning">Bill not found.</div>
                            <?php endif; ?>

                        <?php else:
                            $stmt = $pdo->query("
  SELECT 
      b.id,
      b.invoice_no,
      b.total,
      b.discount_amount AS discount,   -- alias fixes Undefined array key 'discount'
      b.created_at,
      u.name AS user_name
  FROM bills b
  LEFT JOIN users u ON b.user_id = u.id
  ORDER BY b.created_at DESC
  LIMIT 100
");


                        ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Invoice No</th>
                                        <th>User</th>
                                        <th>Total</th>
                                        <th>Discount</th>
                                        <th>Net</th>
                                        <th>Date</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <?php foreach ($stmt as $row): $net = $row['total'] - $row['discount']; ?>
                                        <tr>
                                            <td>#<?= $row['id'] ?></td>
                                            <td><?= htmlspecialchars($row['user_name'] ?? 'Admin') ?></td>
                                            <td><?= number_format($row['total'], 2) ?></td>
                                            <td><?= number_format($row['discount'], 2) ?></td>
                                            <td><b><?= number_format($net, 2) ?></b></td>
                                            <td><?= $row['created_at'] ?></td>
                                            <td><a href="?section=sales&bill_id=<?= $row['id'] ?>" class="btn btn-sm btn-primary">View</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    <?php endif; ?>


                </div>
            </section>
        </div>

        <footer class="main-footer small">
            <div class="float-right d-none d-sm-inline">Single-file</div>
            <strong>&copy; <?= date('Y') ?> JalakFashion.</strong>
        </footer>
    </div>

    <!-- JS libs -->
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>

    <script>
        const CSRF = <?= json_encode(csrf_token()) ?>;

        /* Dashboard Chart */
        <?php if ($section === 'dashboard'): ?>
                (function() {
                    const data = <?= json_encode($dash['series']) ?>;
                    const labels = data.map(d => d.date);
                    const sums = data.map(d => d.sum);
                    const ctx = document.getElementById('salesChart').getContext('2d');
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels,
                            datasets: [{
                                label: 'Sales (₹)',
                                data: sums,
                                fill: false
                            }]
                        },
                        options: {
                            responsive: true,
                            plugins: {
                                legend: {
                                    display: true
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true
                                }
                            }
                        }
                    });
                })();
        <?php endif; ?>

        /* Products modal helpers */
        function editProduct(p) {
            $('#prod_title').text('Edit Product');
            $('#productForm').attr('action', '?section=products&action=update');
            $('#prod_id').val(p.id);
            $('#prod_code').val(p.product_code);
            $('#prod_name').val(p.name);
            $('#prod_size').val(p.size);
            $('#prod_desc').val(p.description);
            $('#prod_esp').val(p.default_estimated_selling_price);
            $('#prod_status').val(p.status);
            $('#productModal').modal('show');
        }

        /* Suppliers modal helpers */
        function editSupplier(s) {
            $('#sup_title').text('Edit Supplier');
            $('#supplierForm').attr('action', '?section=suppliers&action=update');
            $('#sup_id').val(s.id);
            $('#sup_name').val(s.name);
            $('#sup_address').val(s.address);
            $('#sup_phone').val(s.phone);
            $('#sup_email').val(s.email);
            $('#sup_note').val(s.note);
            $('#supplierModal').modal('show');
        }

        /* Purchase submit with AJAX to get generated unit codes */
        async function submitPurchase(e) {
            e.preventDefault();
            const form = e.target;
            const fd = new FormData(form);
            try {
                const res = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: fd
                });
                const j = await res.json();
                if (!j.ok) throw new Error(j.error || 'Error');
                // Render batch QR
                const list = j.unit_codes || [];
                const wrap = $('#qrBatch').empty();
                for (const code of list) {
                    const div = $('<div class="qr-item"><div class="qrc"></div><div class="small mt-1">' + code + '</div><div class="small">₹' + Number($('input[name="estimated_selling_price"]').val() || 0).toFixed(2) + '</div></div>');
                    wrap.append(div);
                    new QRCode(div.find('.qrc')[0], {
                        text: window.location.origin + window.location.pathname + '?section=qr&code=' + code,
                        width: 128,
                        height: 128
                    });
                }
                $('#qrBatchCard').show();
                window.scrollTo({
                    top: document.body.scrollHeight,
                    behavior: 'smooth'
                });
            } catch (err) {
                alert(err.message);
            }
            return false;
        }

        /* Download QR PDF using html2canvas + jsPDF (paginate automatically) */
        document.getElementById('btnDownloadQR')?.addEventListener('click', async () => {
            const {
                jsPDF
            } = window.jspdf;
            const pdf = new jsPDF({
                unit: 'pt',
                format: 'a4'
            }); // 595x842
            const items = document.querySelectorAll('#qrBatch .qr-item');
            let x = 20,
                y = 20,
                w = 180,
                h = 200,
                perRow = 3,
                pageH = 842;
            let first = true;
            for (let i = 0; i < items.length; i++) {
                const el = items[i];
                const canvas = await html2canvas(el, {
                    scale: 2
                });
                const img = canvas.toDataURL('imagePNG', 1.0);
                if (y + h > pageH - 20) {
                    pdf.addPage();
                    x = 20;
                    y = 20;
                }
                pdf.addImage(img, 'PNG', x, y, w, h);
                x += w + 10;
                if ((i + 1) % perRow === 0) {
                    x = 20;
                    y += h + 10;
                }
            }
            pdf.save('unit_qr_batch.pdf');
        });

        /* Inventory unit modal */
        function openUnitModal(u) {
            $('#unit_id').val(u.id);
            $('#unit_code').val(u.unit_code);
            $('#unit_status').val(u.status);
            $('#unitModal').modal('show');
        }
        async function postUnitStatus(e) {
            e.preventDefault();
            const form = e.target;
            const fd = new FormData(form);
            const res = await fetch(form.action, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            });
            const j = await res.json();
            if (!j.ok) alert(j.error || 'Error');
            else location.reload();
            return false;
        }

        /* Billing: scanner in navbar adds item */
        $('#scannerGo').on('click', function() {
            const code = $('#scannerInput').val().trim().toUpperCase();
            if (!code) return;
            addByScan(code);
        });
        $('#scannerInput').on('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $('#scannerGo').click();
            }
        });

        async function addByScan(code) {
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('code', code);
            const res = await fetch('?section=billing&action=lookup', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            });
            const j = await res.json();
            if (!j.ok) {
                alert(j.error || 'Lookup error');
                return;
            }
            const d = j.data;
            const tbody = $('#billTable tbody');
            // prevent duplicates (by unit_id)
            if (tbody.find('tr[data-unit="' + d.unit_id + '"]').length) {
                alert('Already added');
                return;
            }
            const row = $(`
    <tr data-unit="${d.unit_id}">
      <td>${d.unit_code}</td>
      <td>${d.name} (${d.size||''})<input type="hidden" class="product_id" value="${d.product_id}"></td>
      <td><input type="number" step="0.01" class="form-control price" value="${Number(d.estimated_sell_price).toFixed(2)}"></td>
      <td style="max-width:80px;"><input type="number" class="form-control qty" value="1" min="1"></td>
      <td class="subtotal">0.00</td>
      <td><button class="btn btn-sm btn-outline-danger remove"><i class="fas fa-times"></i></button></td>
      <input type="hidden" class="unit_id" value="${d.unit_id}">
    </tr>`);
            tbody.append(row);
            recalcBill();
        }

        /* Bill table interactions */
        $('#billTable').on('input', '.price, .qty', recalcBill);
        $('#billTable').on('click', '.remove', function() {
            $(this).closest('tr').remove();
            recalcBill();
        });
        $('#discount_amount').on('input', recalcBill);

        function recalcBill() {
            let total = 0;
            $('#billTable tbody tr').each(function() {
                const price = parseFloat($(this).find('.price').val() || 0);
                const qty = parseInt($(this).find('.qty').val() || 1);
                const sub = price * qty;
                $(this).find('.subtotal').text(sub.toFixed(2));
                total += sub;
            });
            const disc = parseFloat($('#discount_amount').val() || 0);
            total = Math.max(0, total - disc);
            $('#bill_total').text(total.toFixed(2));
        }

        /* Finalize bill */
        $('#btnFinalizeBill').on('click', async function() {
            const items = [];
            $('#billTable tbody tr').each(function() {
                items.push({
                    unit_id: $(this).find('.unit_id').val(),
                    product_id: $(this).find('.product_id').val(),
                    price: $(this).find('.price').val(),
                    qty: $(this).find('.qty').val()
                });
            });
            if (items.length === 0) {
                alert('No items');
                return;
            }
            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('items', JSON.stringify(items));
            fd.append('discount_amount', $('#discount_amount').val() || 0);
            const res = await fetch('?section=billing&action=create_bill', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: fd
            });
            const j = await res.json();
            if (!j.ok) {
                $('#billMsg').html('<div class="alert alert-danger">' + (j.error || 'Error') + '</div>');
                return;
            }
            $('#billMsg').html('<div class="alert alert-success">Bill created. Invoice: <b>' + j.invoice_no + '</b>. Total ₹' + Number(j.total).toFixed(2) + '</div>');
            $('#billTable tbody').empty();
            recalcBill();
            $('#scannerInput').val('');
        });

        /* Returns */
        async function submitReturn(e) {
            e.preventDefault();
            const code = $('#return_code').val().trim().toUpperCase();
            if (!code) {
                return false;
            }
            // Find unit id by code
            try {
                // re-use lookup to get unit id (even if not available). If not found as available, fetch directly:
                const r = await fetch('?section=billing&action=lookup', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new URLSearchParams({
                        csrf_token: CSRF,
                        code
                    })
                });
                let j = await r.json();
                if (j.ok) {
                    $('#returnMsg').html('<div class="alert alert-info">Unit is available already.</div>');
                    return false;
                } else {
                    // Fallback: fetch unit by code to get id
                    const rr = await fetch('index.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: 'csrf_token=' + encodeURIComponent(CSRF) + '&_fetch_unit=1&code=' + encodeURIComponent(code)
                    });
                    const k = await rr.json();
                    if (!k.ok) throw new Error(k.error || 'Unit not found');
                    if (k.data.status !== 'sold') {
                        $('#returnMsg').html('<div class="alert alert-warning">Unit status: ' + k.data.status + '. Only sold items can be returned.</div>');
                        return false;
                    }
                    const fd = new FormData();
                    fd.append('csrf_token', CSRF);
                    fd.append('unit_id', k.data.id);
                    const res = await fetch('?section=billing&action=return_unit', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: fd
                    });
                    const jr = await res.json();
                    if (!jr.ok) throw new Error(jr.error || 'Return error');
                    $('#returnMsg').html('<div class="alert alert-success">Return processed for ' + code + '</div>');
                }
            } catch (e2) {
                $('#returnMsg').html('<div class="alert alert-danger">' + e2.message + '</div>');
            }
            return false;
        }
    </script>

    <?php
    // Small hidden endpoint to get unit by code (used by Returns flow)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_fetch_unit'])) {
        csrf_check();
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $s = $pdo->prepare("SELECT id, unit_code, status FROM inventory_units WHERE unit_code=?");
        $s->execute([$code]);
        $r = $s->fetch();
        json_out(['ok' => (bool)$r, 'data' => $r, 'error' => $r ? null : 'Not found']);
    }
    ?>
</body>

</html>