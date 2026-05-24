<?php
require_once __DIR__ . '/../../app/models/pdo.php';
// icp.php - ICP备案号


$stmt = $pdo->prepare("SELECT description FROM config WHERE id = ?");
    $stmt->execute([6]);
    $row = $stmt->fetch();

    $icp = $row['description'] ?? null;
