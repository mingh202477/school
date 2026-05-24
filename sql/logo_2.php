<?php
require_once __DIR__ . '/../sql/pdo.php';
// logo_2.php - 网站logo


$stmt = $pdo->prepare("SELECT url FROM config WHERE id = ?");
    $stmt->execute([4]);
    $row = $stmt->fetch();

    $logo_2 = $row['url'] ?? null;

//echo $logo_2;