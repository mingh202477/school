<?php
require_once __DIR__ . '/../sql/pdo.php';
// keywords.php - 网站标题

//try{
$stmt = $pdo->prepare("SELECT description FROM config WHERE id = ?");
    $stmt->execute([2]);
    $row = $stmt->fetch();

    $keywords = $row['description'] ?? null;

// echo $title;
//     if ($title !== null) {
//         echo "查询结果: " . $title;
//     } else {
//         echo "未找到 id=2 的记录，或 description 为空";
//     }
// } catch (PDOException $e) {
//     echo "FACK!错误: " . $e->getMessage();
// }