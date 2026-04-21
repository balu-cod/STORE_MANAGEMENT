<?php
// setup_user.php - Run this ONCE to create your admin account
require_once 'db_config.php';

$email = "kodimurthybalu@gmail.com";
$web_password = "Balu&54321"; // The password you want to use for the website

// Securely hash the password
$hashed_password = password_hash($web_password, PASSWORD_DEFAULT);

// Insert or Update the user
$sql = "INSERT INTO users (email, password) VALUES ('$email', '$hashed_password') 
        ON DUPLICATE KEY UPDATE password='$hashed_password'";

if ($conn->query($sql) === TRUE) {
    echo "<h1>✅ Success!</h1>";
    echo "<p>Admin User: <b>$email</b></p>";
    echo "<p>Password: <b>$web_password</b></p>";
    echo "<p>You can now <a href='login.html'>Go to Login</a>.</p>";
    echo "<p><small>Please delete this file from your server after use.</small></p>";
} else {
    echo "<h1>❌ Error</h1>";
    echo "Error creating user: " . $conn->error;
}
?>
