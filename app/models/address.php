<?php
require_once __DIR__ . '/../../app/models/pdo.php';
// address.php - 网站地址


$stmt = $pdo->prepare("SELECT description FROM config WHERE id = ?");
    $stmt->execute([7]);
    $row = $stmt->fetch();

    $address = $row['description'] ?? null;

