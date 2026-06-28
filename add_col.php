<?php
require 'C:\xampp\htdocs\school_ms\config\database.php';
$db = (new Database())->getConnection();
try {
    $db->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) NULL");
    echo "Added column.";
} catch (Exception $e) {
    echo "Column already exists or error: " . $e->getMessage();
}
