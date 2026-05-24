<?php
// meiti_list_q4.php - 获取最新4条媒体报道数据
require_once __DIR__ . '/../../app/models/pdo.php';
$sql = "SELECT title, DATE_FORMAT(coverage_date, '%m-%d') AS date, link 
        FROM media_coverage 
        ORDER BY coverage_date DESC 
        LIMIT 4";

$stmt = $pdo->prepare($sql);
$stmt->execute();

// 将结果存入 $meiti_list
$meiti_list = $stmt->fetchAll();
