<?php
/**
 * profile.php - User profile and book library
 */

// Include necessary files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Start session
session_start();

// Redirect if not logged in
if (!isLoggedIn()) {
    redirect('auth.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
}

// Get user info
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$email = $_SESSION['email'];
$first_name = $_SESSION['first_name'];
$last_name = $_SESSION['last_name'];

// Get user's books from library
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build query based on filter
$books_query = "SELECT ub.*, b.* FROM user_books ub
                JOIN books_cache b ON ub.book_id = b.book_id
                WHERE ub.user_id = ?";

if (!empty($status_filter)) {
    $books_query .= " AND ub.status = ?";
}

$books_query .= " ORDER BY ub.added_at DESC";

$books_stmt = $connection->prepare($books_query);

if (!empty($status_filter)) {
    $books_stmt->bind_param("is", $user_id, $status_filter);
} else {
    $books_stmt->bind_param("i", $user_id);
}

$books_stmt->execute();
$books_result = $books_stmt->get_result();

$user_books = [];
while ($row = $books_result->fetch_assoc()) {
    $user_books[] = $row;
}

// Get user's reviews
$reviews_query = "SELECT r.*, b.title as book_title, b.author as book_author, b.cover_image 
                 FROM reviews r
                 JOIN books_cache b ON r.book_id = b.book_id
                 WHERE r.user_id = ?
                 ORDER BY r.created_at DESC";
$reviews_stmt = $connection->prepare($reviews_query);
$reviews_stmt->bind_param("i", $user_id);
$reviews_stmt->execute();
$reviews_result = $reviews_stmt->get_result();

$user_reviews = [];
while ($row = $reviews_result->fetch_assoc()) {
    $user_reviews[] = $row;
}

// Count books by status
$count_query = "SELECT status, COUNT(*) as count FROM user_books WHERE user_id = ? GROUP BY status";
$count_stmt = $connection->prepare($count_query);
$count_stmt->bind_param("i", $user_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result();

$status_counts = [
    'total' => 0,
    'to_read' => 0,
    'reading' => 0,
    'completed' => 0
];

while ($row = $count_result->fetch_assoc()) {
    $status_counts[$row['status']] = $row['count'];
    $status_counts['total'] += $row['count'];
}

// Handle profile form submission
$profile_error = '';
$profile_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_first_name = trim($_POST['first_name']);
    $new_last_name = trim($_POST['last_name']);
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate input
    if (empty($new_first_name) || empty($new_last_name)) {
        $profile_error = 'First name and last name are required.';
    } else {
        // Start transaction for multiple updates
        $connection->begin_transaction();
        
        try {
            // Update name
            $update_query = "UPDATE users SET first_name = ?, last_name = ? WHERE user_id = ?";
            $update_stmt = $connection->prepare($update_query);
            $update_stmt->bind_param("ssi", $new_first_name, $new_last_name, $user_id);
            $update_stmt->execute();
            
            // Update password if provided
            if (!empty($current_password) && !empty($new_password)) {
                if ($new_password !== $confirm_password) {
                    throw new Exception('New passwords do not match.');
                }
                
                // Verify current password
                $password_query = "SELECT password FROM users WHERE user_id = ?";
                $password_stmt = $connection->prepare($password_query);
                $password_stmt->bind_param("i", $user_id);
                $password_stmt->execute();
                $password_result = $password_stmt->get_result();
                $password_row = $password_result->fetch_assoc();
                
                if (!password_verify($current_password, $password_row['password'])) {
                    throw new Exception('Current password is incorrect.');
                }
                
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $password_update_query = "UPDATE users SET password = ? WHERE user_id = ?";
                $password_update_stmt = $connection->prepare($password_update_query);
                $password_update_stmt->bind_param("si", $hashed_password, $user_id);
                $password_update_stmt->execute();
            }
            
            // Commit transaction
            $connection->commit();
            
            // Update session variables
            $_SESSION['first_name'] = $new_first_name;
            $_SESSION['last_name'] = $new_last_name;
            
            // Update local variables
            $first_name = $new_first_name;
            $last_name = $new_last_name;
            
            $profile_success = 'Profile updated successfully.';
        } catch (Exception $e) {
            // Rollback transaction on error
            $connection->rollback();
            $profile_error = $e->getMessage();
        }
    }
}

// Handle book removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_book'])) {
    $book_id = $_POST['book_id'];
    
    $delete_query = "DELETE FROM user_books WHERE user_id = ? AND book_id = ?";
    $delete_stmt = $connection->prepare($delete_query);
    $delete_stmt->bind_param("is", $user_id, $book_id);
    
    if ($delete_stmt->execute()) {
        // Redirect to avoid resubmission
        redirect('profile.php?book_removed=1');
    }
}

// Check for success messages
if (isset($_GET['book_removed']) && $_GET['book_removed'] == 1) {
    $profile_success = 'Book removed from your library.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo SITE_NAME; ?></title>
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
                        <li><a href="profile.php" class="active">My Library</a></li>
                    </ul>
                </nav>
                <div class="auth-buttons">
                    <span class="welcome-text">Hi, <?php echo htmlspecialchars($username); ?></span>
                    <a href="logout.php" class="btn">Log Out</a>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main>
            <div class="container">
                <!-- Profile Overview Section -->
                <div class="profile-header">
                    <div class="profile-info">
                        <h1><?php echo htmlspecialchars($first_name . ' ' . $last_name); ?></h1>
                        <p class="username">@<?php echo htmlspecialchars($username); ?></p>
                    </div>
                    <div class="profile-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $status_counts['total']; ?></div>
                            <div class="stat-label">Books</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $status_counts['reading']; ?></div>
                            <div class="stat-label">Reading</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo $status_counts['completed']; ?></div>
                            <div class="stat-label">Completed</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?php echo count($user_reviews); ?></div>
                            <div class="stat-label">Reviews</div>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($profile_error)): ?>
                    <div class="alert alert-danger"><?php echo $profile_error; ?></div>
                <?php endif; ?>
                
                <?php if (!empty($profile_success)): ?>
                    <div class="alert alert-success"><?php echo $profile_success; ?></div>
                <?php endif; ?>
                
                <!-- Tabs Navigation -->
                <div class="tabs">
                    <ul class="tabs-nav">
                        <li><a href="#library" class="tab-link active">My Library</a></li>
                        <li><a href="#reviews" class="tab-link">My Reviews</a></li>
                        <li><a href="#settings" class="tab-link">Account Settings</a></li>
                    </ul>
                    
                    <!-- Library Tab -->
                    <div id="library" class="tab-content active">
                        <div class="tab-header">
                            <h2>My Library</h2>
                            <div class="filter-options">
                                <a href="profile.php" class="filter-link <?php echo empty($status_filter) ? 'active' : ''; ?>">All</a>
                                <a href="profile.php?status=to_read" class="filter-link <?php echo $status_filter === 'to_read' ? 'active' : ''; ?>">Want to Read</a>
                                <a href="profile.php?status=reading" class="filter-link <?php echo $status_filter === 'reading' ? 'active' : ''; ?>">Currently Reading</a>
                                <a href="profile.php?status=completed" class="filter-link <?php echo $status_filter === 'completed' ? 'active' : ''; ?>">Completed</a>
                            </div>
                        </div>
                        
                        <?php if (empty($user_books)): ?>
                            <div class="empty-state">
                                <i class="fas fa-book"></i>
                                <h3>Your library is empty</h3>
                                <p>Start adding books to your library!</p>
                                <a href="index.php?browse=true" class="btn btn-primary">Browse Books</a>
                            </div>
                        <?php else: ?>
                            <div class="books-grid">
                                <?php foreach ($user_books as $book): ?>
                                    <div class="book-card">
                                        <div class="book-cover">
                                            <img src="<?php echo $book['cover_image'] ?: 'assets/images/cover-placeholder.png'; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                            <div class="book-status <?php echo $book['status']; ?>">
                                                <?php 
                                                    if ($book['status'] === 'to_read') {
                                                        echo '<i class="fas fa-bookmark"></i> Want to Read';
                                                    } elseif ($book['status'] === 'reading') {
                                                        echo '<i class="fas fa-book-reader"></i> Reading';
                                                    } elseif ($book['status'] === 'completed') {
                                                        echo '<i class="fas fa-check"></i> Completed';
                                                    } 
                                                ?>
                                            </div>
                                        </div>
                                        <div class="book-info">
                                            <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                            <p class="book-author">By <?php echo htmlspecialchars($book['author']); ?></p>
                                            <div class="book-actions">
                                                <a href="book.php?id=<?php echo $book['book_id']; ?>" class="btn btn-small">View</a>
                                                <form method="POST" class="inline-form">
                                                    <input type="hidden" name="book_id" value="<?php echo $book['book_id']; ?>">
                                                    <button type="submit" name="remove_book" class="btn btn-small danger" onclick="return confirm('Are you sure you want to remove this book from your library?');">Remove</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Reviews Tab -->
                    <div id="reviews" class="tab-content">
                        <h2>My Reviews</h2>
                        
                        <?php if (empty($user_reviews)): ?>
                            <div class="empty-state">
                                <i class="fas fa-star"></i>
                                <h3>You haven't written any reviews yet</h3>
                                <p>Share your thoughts about the books you've read!</p>
                                <a href="index.php?browse=true" class="btn btn-primary">Browse Books to Review</a>
                            </div>
                        <?php else: ?>
                            <div class="reviews-list">
                                <?php foreach ($user_reviews as $review): ?>
                                    <div class="review-card">
                                        <div class="review-book">
                                            <img src="<?php echo $review['cover_image'] ?: 'assets/images/cover-placeholder.png'; ?>" alt="<?php echo htmlspecialchars($review['book_title']); ?>" class="review-book-cover">
                                            <div class="review-book-info">
                                                <h3><a href="book.php?id=<?php echo $review['book_id']; ?>"><?php echo htmlspecialchars($review['book_title']); ?></a></h3>
                                                <p>By <?php echo htmlspecialchars($review['book_author']); ?></p>
                                            </div>
                                        </div>
                                        <div class="review-content">
                                            <div class="review-rating">
                                                <?php echo getStarsHtml($review['rating']); ?>
                                                <span class="review-date"><?php echo date('F j, Y', strtotime($review['created_at'])); ?></span>
                                            </div>
                                            <div class="review-text">
                                                <p><?php echo nl2br(htmlspecialchars($review['review_text'])); ?></p>
                                            </div>
                                            <div class="review-actions">
                                                <a href="book.php?id=<?php echo $review['book_id']; ?>&edit_review=1" class="btn btn-small">Edit</a>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Settings Tab -->
                    <div id="settings" class="tab-content">
                        <h2>Account Settings</h2>
                        
                        <form method="POST" class="settings-form">
                            <div class="form-section">
                                <h3>Personal Information</h3>
                                
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="first_name">First Name</label>
                                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($first_name); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label for="last_name">Last Name</label>
                                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($last_name); ?>" required>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="email">Email</label>
                                    <input type="email" id="email" value="<?php echo htmlspecialchars($email); ?>" disabled>
                                    <div class="input-note">Email cannot be changed</div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input type="text" id="username" value="<?php echo htmlspecialchars($username); ?>" disabled>
                                    <div class="input-note">Username cannot be changed</div>
                                </div>
                            </div>
                            
                            <div class="form-section">
                                <h3>Change Password</h3>
                                <div class="form-group">
                                    <label for="current_password">Current Password</label>
                                    <input type="password" id="current_password" name="current_password">
                                </div>
                                
                                <div class="form-group">
                                    <label for="new_password">New Password</label>
                                    <input type="password" id="new_password" name="new_password">
                                </div>
                                
                                <div class="form-group">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password">
                                </div>
                                
                                <div class="input-note">Leave password fields empty if you don't want to change your password</div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="update_profile" class="btn btn-primary">Save Changes</button>
                            </div>
                        </form>
                    </div>
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
    
    <script>
        // Tab functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabLinks = document.querySelectorAll('.tab-link');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const target = this.getAttribute('href').substring(1);
                    
                    // Remove active class from all tabs
                    tabLinks.forEach(link => link.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // Add active class to clicked tab
                    this.classList.add('active');
                    document.getElementById(target).classList.add('active');
                });
            });
            
            // Check if a tab is specified in URL hash
            if (window.location.hash) {
                const hash = window.location.hash.substring(1);
                const tabLink = document.querySelector(`.tab-link[href="#${hash}"]`);
                if (tabLink) tabLink.click();
            }
        });
    </script>
</body>
</html>