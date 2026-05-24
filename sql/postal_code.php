<?php
require_once __DIR__ . '/../sql/pdo.php';
// postal_code.php - 邮编


$stmt = $pdo->prepare("SELECT description FROM config WHERE id = ?");
    $stmt->execute([8]);
    $row = $stmt->fetch();

    $postal_code = $row['description'] ?? null;
