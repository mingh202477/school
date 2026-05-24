<?php
require_once __DIR__ .'/../sql/pdo.php';
// 预处理
$sql = "SELECT `title`, `desc`, `class` FROM `portal_cards` ORDER BY `id`";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$rows = $stmt->fetchAll();
$portal_cards = [];
foreach ($rows as $row) {
    $portal_cards[] = [
        'title' => $row['title'],
        //JSON解码PHP
        'desc'  => json_decode($row['desc'], true),
        'class' => $row['class'],
    ];
}