<?php
require_once __DIR__ . '/../../app/models/pdo.php';
$sql = "SELECT text, url FROM top_links ORDER BY sort_order ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$top_links = $stmt->fetchAll();