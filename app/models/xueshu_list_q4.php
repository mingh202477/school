<?php
require_once __DIR__ . '/../../app/models/pdo.php';
$sql = "SELECT title, DATE_FORMAT(activity_date, '%m-%d') AS date, link 
        FROM xueshu_activities 
        ORDER BY activity_date DESC, sort_order ASC 
        LIMIT 4";

$stmt = $pdo->prepare($sql);
$stmt->execute();

// 存入变量
$xueshu_list = $stmt->fetchAll();