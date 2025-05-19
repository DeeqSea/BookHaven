<?php
/**
 * auth.php - Combined login and signup functionality
 */

// Include necessary files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Start session
session_start();

// Check if user is already logged in
if (isset($_SESSION['user_id'])) {
    redirect('index.php');
}

// Initialize variables
$mode = isset($_GET['action']) && $_GET['action'] === 'signup' ? 'signup' : 'login';
$error = '';
$success = '';

// Process login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    // Validate input
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        // Fetch user
        $query = "SELECT user_id, username, email, password, first_name, last_name 
                 FROM users WHERE email = ?";
        $stmt = $connection->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                // Set session variables
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
                
                // Redirect to previous page or home
                $redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
                redirect($redirect);
            } else {
                $error = 'Invalid email or password.';
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}

// Process signup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    
    // Validate input
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password) || 
        empty($first_name) || empty($last_name)) {
        $error = 'Please fill in all fields.';
    } elseif (strlen($username) < 3 || strlen($username) > 30) {
        $error = 'Username must be between 3 and 30 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        // Check if username already exists
        $check_query = "SELECT user_id FROM users WHERE username = ?";
        $check_stmt = $connection->prepare($check_query);
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error = 'Username already taken.';
        } else {
            // Check if email already exists
            $check_query = "SELECT user_id FROM users WHERE email = ?";
            $check_stmt = $connection->prepare($check_query);
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $error = 'Email already registered.';
            } else {
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Insert user
                $insert_query = "INSERT INTO users (username, email, password, first_name, last_name) 
                                VALUES (?, ?, ?, ?, ?)";
                $insert_stmt = $connection->prepare($insert_query);
                $insert_stmt->bind_param("sssss", $username, $email, $hashed_password, $first_name, $last_name);
                
                if ($insert_stmt->execute()) {
                    // Get the new user ID
                    $user_id = $connection->insert_id;
                    
                    // Set session variables
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['email'] = $email;
                    $_SESSION['first_name'] = $first_name;
                    $_SESSION['last_name'] = $last_name;
                    
                    // Redirect to previous page or home
                    $redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
                    redirect($redirect);
                } else {
                    $error = 'Error creating account: ' . $connection->error;
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $mode === 'signup' ? 'Sign Up' : 'Log In'; ?> | <?php echo SITE_NAME; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <div class="wrapper">
        <!-- Header -->
        <header>
            <div class="container">
                <a href="index.php" class="logo">
                    <i class="fas fa-book-open"></i> <?php echo SITE_NAME; ?>
                </a>
                <nav>
                    <ul>
                        <li><a href="index.php">Home</a></li>
                        <li><a href="index.php?browse=true">Browse</a></li>
                    </ul>
                </nav>
            </div>
        </header>
        
        <!-- Main Content -->
        <main>
            <div class="container">
                <div class="auth-container">
                    <div class="auth-header">
                        <h1><?php echo $mode === 'signup' ? 'Create an Account' : 'Welcome Back'; ?></h1>
                        <p><?php echo $mode === 'signup' ? 'Join BookHaven to track and review your favorite books' : 'Log in to your BookHaven account'; ?></p>
                    </div>
                    
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                    <?php endif; ?>
                    
                    <?php if ($mode === 'login'): ?>
                        <!-- Login Form -->
                        <form method="POST" action="auth.php<?php echo isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : ''; ?>">
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" id="password" name="password" required>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="login" class="btn btn-primary">Log In</button>
                            </div>
                        </form>
                        
                        <div class="auth-footer">
                            <p>Don't have an account? <a href="auth.php?action=signup<?php echo isset($_GET['redirect']) ? '&redirect=' . urlencode($_GET['redirect']) : ''; ?>">Sign Up</a></p>
                        </div>
                    <?php else: ?>
                        <!-- Signup Form -->
                        <form method="POST" action="auth.php?action=signup<?php echo isset($_GET['redirect']) ? '&redirect=' . urlencode($_GET['redirect']) : ''; ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="first_name">First Name</label>
                                    <input type="text" id="first_name" name="first_name" required>
                                </div>
                                
                                <div class="form-group">
                                    <label for="last_name">Last Name</label>
                                    <input type="text" id="last_name" name="last_name" required>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label for="username">Username</label>
                                <input type="text" id="username" name="username" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" id="password" name="password" required>
                                <p class="form-note">Must be at least 8 characters</p>
                            </div>
                            
                            <div class="form-group">
                                <label for="confirm_password">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="signup" class="btn btn-primary">Create Account</button>
                            </div>
                        </form>
                        
                        <div class="auth-footer">
                            <p>Already have an account? <a href="auth.php<?php echo isset($_GET['redirect']) ? '?redirect=' . urlencode($_GET['redirect']) : ''; ?>">Log In</a></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
        
        <!-- Footer -->
        <footer>
            <div class="container">
                <p>&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. All rights reserved.</p>
            </div>
        </footer>
    </div>
</body>
</html>