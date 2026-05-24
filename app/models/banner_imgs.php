<?php
require_once __DIR__ . '/../../app/models/pdo.php';
$sql = "SELECT img, title, link FROM banner_images ORDER BY sort_order ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$banner_imgs = $stmt->fetchAll();