<?php
require_once __DIR__ . '/../sql/pdo.php';
// logo_1.php - 网站logo


$stmt = $pdo->prepare("SELECT url FROM config WHERE id = ?");
    $stmt->execute([3]);
    $row = $stmt->fetch();

    $logo_1 = $row['url'] ?? null;

//echo $logo_1;