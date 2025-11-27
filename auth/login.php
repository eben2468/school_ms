<?php
session_start();
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) ? true : false;

    $database = new Database();
    $db = $database->getConnection();

    try {
        $query = "SELECT id, name, email, password, role, profile_picture FROM users WHERE email = :email AND status = 'active'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['name'] = $user['name']; // Add this for compatibility
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['email'] = $user['email']; // Add this for compatibility
                $_SESSION['role'] = $user['role'];
                $_SESSION['profile_picture'] = $user['profile_picture'];

                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                    
                    $query = "INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':user_id', $user['id']);
                    $stmt->bindParam(':token', $token);
                    $stmt->bindParam(':expires', $expires);
                    $stmt->execute();

                    setcookie('remember_token', $token, strtotime('+30 days'), '/', '', true, true);
                }

                header("Location: ../dashboard.php");
                exit();
            }
        }
        
        header("Location: ../index.php?error=Invalid email or password");
        exit();
    } catch (PDOException $e) {
        header("Location: ../index.php?error=An error occurred. Please try again later.");
        exit();
    }
} else {
    header("Location: ../index.php");
    exit();
}
?>