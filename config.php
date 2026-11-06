<?php
// อ่านค่าเชื่อมต่อจาก Environment Variables (Railway ตั้งให้เมื่อ reference MySQL service)
function db(): PDO {
    $host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306';
    $user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
    $pass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASS') ?: '';
    $name = getenv('MYSQL_DATABASE') ?: getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'railway';
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET NAMES utf8mb4");
    return $pdo;
}

// ตรวจหาตาราง products และชื่อคอลัมน์อัตโนมัติ
// รองรับชื่อแบบ tb_products และคอลัมน์แบบ i_ProductID, c_ProductName
function schema(PDO $pdo): array {
    $dbname = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $normT = fn($s) => preg_replace('/^tb/', '', strtolower(str_replace('_', '', $s)));
    $normC = fn($s) => strtolower(str_replace('_', '', preg_replace('/^[a-z]{1,2}_/i', '', $s)));
    $table = null;
    foreach ($pdo->query("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA=" . $pdo->quote($dbname)) as $r) {
        if ($normT($r['t']) === 'products') { $table = $r['t']; break; }
    }
    if (!$table) throw new RuntimeException('ไม่พบตาราง products (ยังไม่ได้ import dbNorthwind.sql?)');
    $st = $pdo->prepare("SELECT COLUMN_NAME AS cname, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS def, EXTRA AS extra, DATA_TYPE AS dtype, COLUMN_KEY AS ckey
                         FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? ORDER BY ORDINAL_POSITION");
    $st->execute([$dbname, $table]);
    $cols = $st->fetchAll();
    $want = ['id' => ['productid', 'id'], 'name' => ['productname', 'name'], 'price' => ['unitprice', 'price'],
             'stock' => ['unitsinstock', 'stock'], 'unit' => ['quantityperunit', 'unit']];
    $map = [];
    foreach ($want as $k => $cands) {
        foreach ($cols as $c) if (in_array($normC($c['cname']), $cands, true)) { $map[$k] = $c['cname']; break; }
    }
    if (empty($map['id'])) foreach ($cols as $c) if ($c['ckey'] === 'PRI') { $map['id'] = $c['cname']; break; }
    foreach (['id', 'name'] as $must) if (empty($map[$must])) throw new RuntimeException("ไม่พบคอลัมน์ $must ในตาราง $table");
    return ['table' => $table, 'map' => $map, 'cols' => $cols];
}

function q(string $ident): string { return '`' . str_replace('`', '``', $ident) . '`'; }
