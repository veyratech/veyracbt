<?php
require 'core/Database.php';
$conn = Database::getConnection();
$stmt = $conn->query("SHOW TABLES");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
    echo $row[0] . "\n";
}