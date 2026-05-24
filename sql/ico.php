<?php
require_once __DIR__ . '/../sql/pdo.php';
// ico.php - 网站图标


$stmt = $pdo->prepare("SELECT url FROM config WHERE id = ?");
    $stmt->execute([5]);
    $row = $stmt->fetch();

    $ico = $row['url'] ?? null;