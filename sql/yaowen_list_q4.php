<?php
require_once __DIR__ . '/../sql/pdo.php';
$sql = "SELECT img, title, desc_text AS `desc`, link 
        FROM yaowen_list 
        ORDER BY publish_date DESC, sort_order ASC 
        LIMIT 4";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$yaowen_list = $stmt->fetchAll();
