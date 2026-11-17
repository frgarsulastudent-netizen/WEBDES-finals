<?php
ob_start();
ini_set('display_errors', '0');

function out(array $d, int $c = 200): void {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($c);
    echo json_encode($d);
    exit;
}

$cfg = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : null;
if (!is_array($cfg) || empty($cfg['host']) || strpos($cfg['host'], 'sqlXXX') !== false) {
    out(['success' => false, 'message' => 'Set your real DB credentials in config.php.'], 500);
}

try {
    $pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['db']};charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, full_name VARCHAR(120) NOT NULL, program VARCHAR(80) NOT NULL DEFAULT 'BSIT', email VARCHAR(160) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'student', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS items (id INT AUTO_INCREMENT PRIMARY KEY, reporter_id INT NOT NULL, type VARCHAR(20) NOT NULL, title VARCHAR(150) NOT NULL, category VARCHAR(80) NOT NULL DEFAULT 'ID / Card', location VARCHAR(150) NOT NULL, item_date DATE NOT NULL, public_desc TEXT NOT NULL, secret_detail TEXT NULL, photo_url MEDIUMTEXT NULL, status VARCHAR(30) NOT NULL DEFAULT 'OPEN', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $pdo->exec("CREATE TABLE IF NOT EXISTS claims (id INT AUTO_INCREMENT PRIMARY KEY, item_id INT NOT NULL, claimant_id INT NOT NULL, proof_text TEXT NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'PENDING', reviewed_by INT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $st = $pdo->prepare("SELECT id FROM users WHERE email=?"); $st->execute(['staff@campus.edu']);
    if (!$st->fetchColumn()) {
        $pdo->prepare("INSERT INTO users (full_name, program, email, password_hash, role) VALUES ('Campus Staff Desk', 'Faculty / Staff', 'staff@campus.edu', ?, 'staff')")->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
    }
} catch (Throwable $e) { out(['success' => false, 'message' => 'DB error: ' . $e->getMessage()], 500); }

$act = $_GET['action'] ?? '';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';

function requireStaff(PDO $pdo): int {
    $uid = (int)($_POST['user_id'] ?? 0);
    $st = $pdo->prepare("SELECT role FROM users WHERE id=?"); $st->execute([$uid]);
    if ($st->fetchColumn() !== 'staff') out(['success' => false, 'message' => 'Staff access required.'], 403);
    return $uid;
}

if ($act === 'register' && $post) {
    $n = trim($_POST['full_name'] ?? ''); $p = trim($_POST['program'] ?? 'BSIT'); $e = strtolower(trim($_POST['email'] ?? '')); $pw = $_POST['password'] ?? '';
    if (!$n || !$e || strlen($pw) < 4) out(['success' => false, 'message' => 'Fill all fields (min 4 char password).'], 400);
    try {
        $pdo->prepare("INSERT INTO users (full_name, program, email, password_hash, role) VALUES (?,?,?,?,'student')")->execute([$n, $p, $e, password_hash($pw, PASSWORD_DEFAULT)]);
        out(['success' => true, 'user' => ['id' => (int)$pdo->lastInsertId(), 'full_name' => $n, 'program' => $p, 'email' => $e, 'role' => 'student']]);
    } catch (PDOException $ex) { out(['success' => false, 'message' => ($ex->errorInfo[1] ?? 0) == 1062 ? 'Email already registered. Log in instead!' : $ex->getMessage()], 409); }
}

if ($act === 'login' && $post) {
    $st = $pdo->prepare("SELECT id, full_name, program, email, password_hash, role FROM users WHERE email=?");
    $st->execute([strtolower(trim($_POST['email'] ?? ''))]); $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || !password_verify($_POST['password'] ?? '', $u['password_hash'])) out(['success' => false, 'message' => 'Invalid email or password.'], 401);
    unset($u['password_hash']); out(['success' => true, 'user' => $u]);
}

if ($act === 'items') {
    out(['success' => true, 'items' => $pdo->query("SELECT i.*, COALESCE(u.full_name,'Campus Member') reporter_name, COALESCE(u.program,'Campus') reporter_program FROM items i LEFT JOIN users u ON u.id=i.reporter_id ORDER BY i.id DESC")->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($act === 'report' && $post) {
    $uid = (int)($_POST['user_id'] ?? 0); $t = trim($_POST['title'] ?? ''); $l = trim($_POST['location'] ?? ''); $d = trim($_POST['public_desc'] ?? '');
    if ($uid <= 0 || !$t || !$l || !$d) out(['success' => false, 'message' => 'Log in and fill in all required fields.'], 400);
    $photo = trim($_POST['photo_data'] ?? '');
    if (strpos($photo, 'data:image/') !== 0) $photo = null;
    $pdo->prepare("INSERT INTO items (reporter_id, type, title, category, location, item_date, public_desc, secret_detail, photo_url) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$uid, ($_POST['type'] ?? '') === 'found' ? 'found' : 'lost', $t, $_POST['category'] ?? 'ID / Card', $l, $_POST['item_date'] ?: date('Y-m-d'), $d, trim($_POST['secret_detail'] ?? ''), $photo]);
    out(['success' => true]);
}

if ($act === 'claim' && $post) {
    $uid = (int)($_POST['user_id'] ?? 0); $iid = (int)($_POST['item_id'] ?? 0); $pr = trim($_POST['proof_text'] ?? '');
    if ($uid <= 0 || $iid <= 0 || !$pr) out(['success' => false, 'message' => 'Log in and enter proof of ownership.'], 400);
    $st = $pdo->prepare("SELECT status FROM items WHERE id=?"); $st->execute([$iid]);
    if ($st->fetchColumn() === 'CLOSED') out(['success' => false, 'message' => 'This item is already claimed/closed.'], 400);

    // Prevent queue flooding: replace any prior active PENDING claim on this item so only the latest 1 is active
    $pdo->prepare("DELETE FROM claims WHERE item_id=? AND status='PENDING'")->execute([$iid]);
    $pdo->prepare("INSERT INTO claims (item_id, claimant_id, proof_text) VALUES (?,?,?)")->execute([$iid, $uid, $pr]);
    $pdo->prepare("UPDATE items SET status='UNDER_REVIEW' WHERE id=? AND status='OPEN'")->execute([$iid]);
    out(['success' => true]);
}

if ($act === 'claims') {
    out(['success' => true, 'claims' => $pdo->query("SELECT c.*, i.title item_title, i.secret_detail, u.full_name claimant_name, u.program claimant_program FROM claims c JOIN items i ON i.id=c.item_id JOIN users u ON u.id=c.claimant_id ORDER BY c.id DESC")->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($act === 'verify_claim' && $post) {
    $sid = requireStaff($pdo);
    $cid = (int)($_POST['claim_id'] ?? 0); $dec = ($_POST['decision'] ?? '') === 'APPROVED' ? 'APPROVED' : 'REJECTED';
    $st = $pdo->prepare("SELECT item_id FROM claims WHERE id=?"); $st->execute([$cid]); $iid = (int)$st->fetchColumn();
    if (!$iid) out(['success' => false, 'message' => 'Claim not found.'], 404);
    $pdo->prepare("UPDATE claims SET status=?, reviewed_by=? WHERE id=?")->execute([$dec, $sid, $cid]);
    $pdo->prepare("UPDATE items SET status=? WHERE id=?")->execute([$dec === 'APPROVED' ? 'CLOSED' : 'OPEN', $iid]);
    out(['success' => true]);
}

if ($act === 'clear_claims' && $post) {
    requireStaff($pdo);
    $pdo->exec("DELETE FROM claims WHERE status != 'PENDING'");
    out(['success' => true]);
}

if ($act === 'update_item' && $post) {
    requireStaff($pdo);
    $iid = (int)($_POST['item_id'] ?? 0);
    $st = in_array($_POST['status'] ?? '', ['OPEN', 'UNDER_REVIEW', 'CLOSED'], true) ? $_POST['status'] : 'OPEN';
    $pdo->prepare("UPDATE items SET status=? WHERE id=?")->execute([$st, $iid]);
    out(['success' => true]);
}

if ($act === 'delete_item' && $post) {
    requireStaff($pdo);
    $iid = (int)($_POST['item_id'] ?? 0);
    $pdo->prepare("DELETE FROM claims WHERE item_id=?")->execute([$iid]);
    $pdo->prepare("DELETE FROM items WHERE id=?")->execute([$iid]);
    out(['success' => true]);
}

out(['success' => false, 'message' => 'Unknown action'], 404);