<?php
/**
 * book.php - Single book page with details and reviews
 */

// Include necessary files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Start session
session_start();

// Get book ID from URL
$book_id = isset($_GET['id']) ? $_GET['id'] : null;

// Redirect if no book ID
if (empty($book_id)) {
    redirect('index.php');
}

// Get book details
$book = getBook($book_id);

// If book not found, redirect to home
if (!$book) {
    redirect('index.php');
}

// Get book reviews
$reviews = [];
$avg_rating = 0;
$user_review = null;

try {
    // Get average rating
    $rating_query = "SELECT AVG(rating) as avg_rating FROM reviews WHERE book_id = ?";
    $rating_stmt = $connection->prepare($rating_query);
    $rating_stmt->bind_param("s", $book_id);
    $rating_stmt->execute();
    $rating_result = $rating_stmt->get_result();
    $rating_row = $rating_result->fetch_assoc();
    $avg_rating = $rating_row['avg_rating'] ?: 0;
    
    // Get reviews
    $reviews_query = "SELECT r.*, u.username 
                     FROM reviews r 
                     JOIN users u ON r.user_id = u.user_id 
                     WHERE r.book_id = ? 
                     ORDER BY r.created_at DESC 
                     LIMIT 10";
    $reviews_stmt = $connection->prepare($reviews_query);
    $reviews_stmt->bind_param("s", $book_id);
    $reviews_stmt->execute();
    $reviews_result = $reviews_stmt->get_result();
    
    while ($row = $reviews_result->fetch_assoc()) {
        $reviews[] = $row;
    }
    
    // Check if user has already reviewed this book
    if (isLoggedIn()) {
        $user_id = $_SESSION['user_id'];
        $user_review_query = "SELECT * FROM reviews WHERE user_id = ? AND book_id = ?";
        $user_review_stmt = $connection->prepare($user_review_query);
        $user_review_stmt->bind_param("is", $user_id, $book_id);
        $user_review_stmt->execute();
        $user_review_result = $user_review_stmt->get_result();
        
        if ($user_review_result->num_rows > 0) {
            $user_review = $user_review_result->fetch_assoc();
        }
    }
} catch (Exception $e) {
    error_log($e->getMessage());
    // Continue without reviews if there's an error
}

// Check if book is in user's library
$in_library = false;
$book_status = '';

if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    $library_query = "SELECT * FROM user_books WHERE user_id = ? AND book_id = ?";
    $library_stmt = $connection->prepare($library_query);
    $library_stmt->bind_param("is", $user_id, $book_id);
    $library_stmt->execute();
    $library_result = $library_stmt->get_result();
    
    if ($library_result->num_rows > 0) {
        $in_library = true;
        $library_book = $library_result->fetch_assoc();
        $book_status = $library_book['status'];
    }
}

// Process review form submission
$review_error = '';
$review_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    if (!isLoggedIn()) {
        $review_error = 'You must be logged in to submit a review.';
    } else {
        $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
        $review_text = isset($_POST['review_text']) ? trim($_POST['review_text']) : '';
        
        if ($rating < 1 || $rating > 5) {
            $review_error = 'Please select a rating between 1 and 5.';
        } elseif (empty($review_text)) {
            $review_error = 'Please enter your review.';
        } else {
            $user_id = $_SESSION['user_id'];
            
            if ($user_review) {
                // Update existing review
                $update_query = "UPDATE reviews SET rating = ?, review_text = ?, created_at = NOW() WHERE review_id = ?";
                $update_stmt = $connection->prepare($update_query);
                $update_stmt->bind_param("isi", $rating, $review_text, $user_review['review_id']);
                
                if ($update_stmt->execute()) {
                    $review_success = 'Your review has been updated.';
                    
                    // Refresh the page to show updated review
                    redirect("book.php?id={$book_id}&review_updated=1");
                } else {
                    $review_error = 'Error updating review. Please try again.';
                }
            } else {
                // Insert new review
                $insert_query = "INSERT INTO reviews (user_id, book_id, rating, review_text) VALUES (?, ?, ?, ?)";
                $insert_stmt = $connection->prepare($insert_query);
                $insert_stmt->bind_param("isis", $user_id, $book_id, $rating, $review_text);
                
                if ($insert_stmt->execute()) {
                    $review_success = 'Your review has been submitted.';
                    
                    // Refresh the page to show new review
                    redirect("book.php?id={$book_id}&review_added=1");
                } else {
                    $review_error = 'Error submitting review. Please try again.';
                }
            }
        }
    }
}

// Process library actions (add/update)
$library_error = '';
$library_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['library_action'])) {
    if (!isLoggedIn()) {
        $library_error = 'You must be logged in to manage your library.';
    } else {
        $user_id = $_SESSION['user_id'];
        $action = $_POST['library_action'];
        
        if ($action === 'add') {
            // Add to library with default 'to_read' status
            if ($in_library) {
                $library_error = 'This book is already in your library.';
            } else {
                $insert_query = "INSERT INTO user_books (user_id, book_id, status) VALUES (?, ?, 'to_read')";
                $insert_stmt = $connection->prepare($insert_query);
                $insert_stmt->bind_param("is", $user_id, $book_id);
                
                if ($insert_stmt->execute()) {
                    $library_success = 'Book added to your library.';
                    $in_library = true;
                    $book_status = 'to_read';
                } else {
                    $library_error = 'Error adding book to library. Please try again.';
                }
            }
        } elseif ($action === 'update' && $in_library) {
            // Update status
            $new_status = isset($_POST['status']) ? $_POST['status'] : '';
            
            if (!in_array($new_status, ['to_read', 'reading', 'completed'])) {
                $library_error = 'Invalid status.';
            } else {
                $update_query = "UPDATE user_books SET status = ? WHERE user_id = ? AND book_id = ?";
                $update_stmt = $connection->prepare($update_query);
                $update_stmt->bind_param("sis", $new_status, $user_id, $book_id);
                
                if ($update_stmt->execute()) {
                    $library_success = 'Book status updated.';
                    $book_status = $new_status;
                } else {
                    $library_error = 'Error updating book status. Please try again.';
                }
            }
        } elseif ($action === 'remove' && $in_library) {
            // Remove from library
            $delete_query = "DELETE FROM user_books WHERE user_id = ? AND book_id = ?";
            $delete_stmt = $connection->prepare($delete_query);
            $delete_stmt->bind_param("is", $user_id, $book_id);
            
            if ($delete_stmt->execute()) {
                $library_success = 'Book removed from your library.';
                $in_library = false;
                $book_status = '';
            } else {
                $library_error = 'Error removing book from library. Please try again.';
            }
        }
    }
}

// Check for success messages from redirects
if (isset($_GET['review_added']) && $_GET['review_added'] == 1) {
    $review_success = 'Your review has been submitted.';
} elseif (isset($_GET['review_updated']) && $_GET['review_updated'] == 1) {
    $review_success = 'Your review has been updated.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($book['title']); ?> - <?php echo SITE_NAME; ?></title>
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
                        <?php if (isLoggedIn()): ?>
                            <li><a href="profile.php">My Library</a></li>
                        <?php endif; ?>
                    </ul>
                </nav>
                <div class="auth-buttons">
                    <?php if (isLoggedIn()): ?>
                        <span class="welcome-text">Hi, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                        <a href="profile.php" class="btn btn-secondary">Profile</a>
                        <a href="logout.php" class="btn">Log Out</a>
                    <?php else: ?>
                        <a href="auth.php?redirect=<?php echo urlencode('book.php?id=' . $book_id); ?>" class="btn">Log In</a>
                        <a href="auth.php?action=signup&redirect=<?php echo urlencode('book.php?id=' . $book_id); ?>" class="btn btn-primary">Sign Up</a>
                    <?php endif; ?>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main>
            <div class="container">
                <!-- Book Details -->
                <div class="book-detail">
                    <div class="book-header">
                        <div class="book-cover">
                            <img src="<?php echo $book['cover_image'] ?: 'assets/images/cover-placeholder.png'; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                        </div>
                        <div class="book-info">
                            <h1><?php echo htmlspecialchars($book['title']); ?></h1>
                            <p class="book-author">By <?php echo htmlspecialchars($book['author']); ?></p>
                            
                            <?php if ($avg_rating > 0): ?>
                                <div class="book-rating">
                                    <div class="stars">
                                        <?php echo getStarsHtml($avg_rating); ?>
                                    </div>
                                    <span class="rating-value"><?php echo number_format($avg_rating, 1); ?> rating</span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($book['description'])): ?>
                                <div class="book-description">
                                    <h3>Description</h3>
                                    <p><?php echo nl2br(htmlspecialchars($book['description'])); ?></p>
                                </div>
                            <?php endif; ?>
                            
                            <div class="book-meta">
                                <?php if (!empty($book['publisher'])): ?>
                                    <div class="meta-item">
                                        <span class="meta-label">Publisher:</span>
                                        <span><?php echo htmlspecialchars($book['publisher']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($book['publication_date'])): ?>
                                    <div class="meta-item">
                                        <span class="meta-label">Published:</span>
                                        <span><?php echo date('F j, Y', strtotime($book['publication_date'])); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($book['pages'])): ?>
                                    <div class="meta-item">
                                        <span class="meta-label">Pages:</span>
                                        <span><?php echo $book['pages']; ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($book['isbn'])): ?>
                                    <div class="meta-item">
                                        <span class="meta-label">ISBN:</span>
                                        <span><?php echo $book['isbn']; ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($book['category'])): ?>
                                    <div class="meta-item">
                                        <span class="meta-label">Category:</span>
                                        <span><?php echo htmlspecialchars($book['category']); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="book-actions">
                                <?php if (isLoggedIn()): ?>
                                    <?php if ($in_library): ?>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="library_action" value="update">
                                            <select name="status" class="status-select" onchange="this.form.submit()">
                                                <option value="to_read" <?php echo $book_status === 'to_read' ? 'selected' : ''; ?>>Want to Read</option>
                                                <option value="reading" <?php echo $book_status === 'reading' ? 'selected' : ''; ?>>Currently Reading</option>
                                                <option value="completed" <?php echo $book_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            </select>
                                        </form>
                                        
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="library_action" value="remove">
                                            <button type="submit" class="btn btn-small"><i class="fas fa-times"></i> Remove</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="POST">
                                            <input type="hidden" name="library_action" value="add">
                                            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add to Library</button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="auth.php?redirect=<?php echo urlencode('book.php?id=' . $book_id); ?>" class="btn btn-primary"><i class="fas fa-plus"></i> Add to Library</a>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!empty($library_error)): ?>
                                <div class="alert alert-danger"><?php echo $library_error; ?></div>
                            <?php endif; ?>
                            
                            <?php if (!empty($library_success)): ?>
                                <div class="alert alert-success"><?php echo $library_success; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Reviews Section -->
                <div class="reviews-section">
                    <h2>Reviews</h2>
                    
                    <?php if (!empty($review_error)): ?>
                        <div class="alert alert-danger"><?php echo $review_error; ?></div>
                    <?php endif; ?>
                    
                    <?php if (!empty($review_success)): ?>
                        <div class="alert alert-success"><?php echo $review_success; ?></div>
                    <?php endif; ?>
                    
                    <!-- Review Form -->
                    <?php if (isLoggedIn() && (!$user_review || isset($_GET['edit_review']))): ?>
                        <div class="review-form">
                            <h3><?php echo $user_review ? 'Edit Your Review' : 'Write a Review'; ?></h3>
                            <form method="POST">
                                <div class="form-group">
                                    <label>Your Rating</label>
                                    <div class="rating-select">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <input type="radio" name="rating" id="rating-<?php echo $i; ?>" value="<?php echo $i; ?>" <?php echo $user_review && $user_review['rating'] == $i ? 'checked' : ''; ?> required>
                                            <label for="rating-<?php echo $i; ?>"><i class="fas fa-star"></i></label>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="review_text">Your Review</label>
                                    <textarea id="review_text" name="review_text" rows="5" required><?php echo $user_review ? htmlspecialchars($user_review['review_text']) : ''; ?></textarea>
                                </div>
                                
                                <button type="submit" name="submit_review" class="btn btn-primary">
                                    <?php echo $user_review ? 'Update Review' : 'Submit Review'; ?>
                                </button>
                                
                                <?php if ($user_review): ?>
                                    <a href="book.php?id=<?php echo $book_id; ?>" class="btn">Cancel</a>
                                <?php endif; ?>
                            </form>
                        </div>
                    <?php elseif (isLoggedIn() && $user_review): ?>
                        <div class="your-review">
                            <h3>Your Review</h3>
                            <div class="review-card">
                                <div class="review-rating">
                                    <?php echo getStarsHtml($user_review['rating']); ?>
                                    <span class="review-date"><?php echo date('F j, Y', strtotime($user_review['created_at'])); ?></span>
                                </div>
                                <div class="review-text">
                                    <p><?php echo nl2br(htmlspecialchars($user_review['review_text'])); ?></p>
                                </div>
                                <div class="review-actions">
                                    <a href="book.php?id=<?php echo $book_id; ?>&edit_review=1" class="btn btn-small">Edit Review</a>
                                </div>
                            </div>
                        </div>
                    <?php elseif (!isLoggedIn()): ?>
                        <div class="review-login-prompt">
                            <p>Please <a href="auth.php?redirect=<?php echo urlencode('book.php?id=' . $book_id); ?>">log in</a> to write a review.</p>
                        </div>
                    <?php else: ?>
                        <div class="write-review-btn">
                            <a href="book.php?id=<?php echo $book_id; ?>&edit_review=1" class="btn btn-primary">Write a Review</a>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Reviews List -->
                    <?php if (empty($reviews)): ?>
                        <div class="no-reviews">
                            <p>No reviews yet. Be the first to review this book!</p>
                        </div>
                    <?php else: ?>
                        <div class="reviews-list">
                            <?php foreach ($reviews as $review): ?>
                                <?php if ($user_review && $review['user_id'] == $_SESSION['user_id']) continue; // Skip user's own review as it's shown above ?>
                                <div class="review-card">
                                    <div class="review-header">
                                        <div class="reviewer-info">
                                            <span class="reviewer-name"><?php echo htmlspecialchars($review['username']); ?></span>
                                        </div>
                                        <div class="review-date">
                                            <?php echo date('F j, Y', strtotime($review['created_at'])); ?>
                                        </div>
                                    </div>
                                    <div class="review-rating">
                                        <?php echo getStarsHtml($review['rating']); ?>
                                    </div>
                                    <div class="review-text">
                                        <p><?php echo nl2br(htmlspecialchars($review['review_text'])); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
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
    
    <script src="assets/app.js"></script>
</body>
</html>