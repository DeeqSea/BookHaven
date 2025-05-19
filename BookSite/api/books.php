<?php
// Include necessary files
require_once 'includes/Database.php';
require_once 'includes/book.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
$user_logged_in = isset($_SESSION['user_id']);
$user_id = $user_logged_in ? $_SESSION['user_id'] : null;

// Get book ID from URL
$book_id = isset($_GET['id']) ? $_GET['id'] : '';

// Redirect to browse page if no book ID
if (empty($book_id)) {
    header("Location: browse.php");
    exit();
}

// Initialize Book class
$bookManager = new Book();

// Get book details
$book = $bookManager->getBook($book_id);

// If book not found, redirect to browse page
if (!$book) {
    header("Location: browse.php?error=book_not_found");
    exit();
}

// Format book data for display
$title = $book['title'];
$author = $book['author'];
$description = $book['description'] ?? '';
$cover_image = $book['cover_image'] ?? 'assets/images/cover-placeholder.png';
$publisher = $book['publisher'] ?? '';
$publication_date = !empty($book['publication_date']) ? date('F j, Y', strtotime($book['publication_date'])) : '';
$isbn = $book['isbn'] ?? '';
$isbn13 = $book['isbn13'] ?? '';
$pages = $book['pages'] ?? '';
$language = $book['language'] ?? '';
$category = $book['category'] ?? '';

// Check if book is in user's library
$in_library = false;
$reading_status = '';
$reading_progress = 0;

if ($user_logged_in) {
    $query = "SELECT status, progress FROM user_books WHERE user_id = ? AND book_id = ?";
    $stmt = $connection->prepare($query);
    $stmt->bind_param("is", $user_id, $book_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $in_library = true;
        $reading_status = $row['status'];
        $reading_progress = $row['progress'];
    }
}

// Get book reviews
$reviews = [];
$total_reviews = 0;
$average_rating = 0;

$review_query = "SELECT r.review_id, r.rating, r.review_title, r.review_text, r.review_date,
                u.user_id, u.username, u.profile_image,
                (SELECT COUNT(*) FROM review_likes WHERE review_id = r.review_id) as likes_count
                FROM reviews r
                JOIN users u ON r.user_id = u.user_id
                WHERE r.book_id = ? AND r.is_approved = 1
                ORDER BY r.review_date DESC
                LIMIT 5";
                
$review_stmt = $connection->prepare($review_query);
$review_stmt->bind_param("s", $book_id);
$review_stmt->execute();
$review_result = $review_stmt->get_result();

while ($row = $review_result->fetch_assoc()) {
    // Check if user has liked this review
    $user_liked = false;
    if ($user_logged_in) {
        $like_query = "SELECT like_id FROM review_likes WHERE review_id = ? AND user_id = ?";
        $like_stmt = $connection->prepare($like_query);
        $like_stmt->bind_param("ii", $row['review_id'], $user_id);
        $like_stmt->execute();
        $like_result = $like_stmt->get_result();
        $user_liked = ($like_result->num_rows > 0);
    }
    
    // Format date
    $date = new DateTime($row['review_date']);
    $formatted_date = $date->format('F j, Y');
    
    $reviews[] = [
        'review_id' => $row['review_id'],
        'rating' => $row['rating'],
        'review_title' => $row['review_title'],
        'review_text' => $row['review_text'],
        'review_date' => $formatted_date,
        'user_id' => $row['user_id'],
        'username' => $row['username'],
        'profile_image' => $row['profile_image'],
        'likes_count' => $row['likes_count'],
        'user_liked' => $user_liked
    ];
}

// Get total review count and average rating
$stats_query = "SELECT COUNT(*) as count, AVG(rating) as avg_rating 
               FROM reviews 
               WHERE book_id = ? AND is_approved = 1";
               
$stats_stmt = $connection->prepare($stats_query);
$stats_stmt->bind_param("s", $book_id);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats_row = $stats_result->fetch_assoc();

$total_reviews = $stats_row['count'];
$average_rating = $stats_row['avg_rating'] ?? 0;

// Get related books (same category)
$related_books = [];

if (!empty($category)) {
    $related_query = "SELECT * FROM books_cache 
                     WHERE book_id != ? AND category LIKE ? 
                     ORDER BY RAND() LIMIT 6";
                     
    $category_param = "%{$category}%";
    $related_stmt = $connection->prepare($related_query);
    $related_stmt->bind_param("ss", $book_id, $category_param);
    $related_stmt->execute();
    $related_result = $related_stmt->get_result();
    
    while ($row = $related_result->fetch_assoc()) {
        $related_books[] = $row;
    }
    
    // If not enough related books, get more from API
    if (count($related_books) < 6 && !empty($category)) {
        $api_books = $bookManager->searchBooks("subject:{$category}", 0, 6 - count($related_books));
        
        if ($api_books && isset($api_books['items'])) {
            foreach ($api_books['items'] as $api_book) {
                if ($api_book['id'] != $book_id) {
                    // Add book to cache
                    $bookManager->addBookToCache($api_book);
                    
                    // Format for display
                    $related_books[] = $bookManager->formatBookData($api_book);
                }
                
                if (count($related_books) >= 6) {
                    break;
                }
            }
        }
    }
}

// Function to display star ratings
function getStarsHTML($rating) {
    $rating = floatval($rating);
    $full_stars = floor($rating);
    $half_star = $rating - $full_stars >= 0.5;
    $empty_stars = 5 - $full_stars - ($half_star ? 1 : 0);
    
    $html = '';
    
    for ($i = 0; $i < $full_stars; $i++) {
        $html .= '<i class="fas fa-star"></i>';
    }
    
    if ($half_star) {
        $html .= '<i class="fas fa-star-half-alt"></i>';
    }
    
    for ($i = 0; $i < $empty_stars; $i++) {
        $html .= '<i class="far fa-star"></i>';
    }
    
    return $html;
}

// Page title
$page_title = "{$title} by {$author} | BookHaven";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    
    <!-- Favicon -->
    <link rel="shortcut icon" href="assets/images/favicon.ico" type="image/x-icon">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Main stylesheet -->
    <link rel="stylesheet" href="style.css">
</head>
<body class="<?php echo $user_logged_in ? 'logged-in' : ''; ?>">
    <!-- Header -->
    <header>
        <div class="container header-container">
            <a href="index.php" class="logo">
                <i class="fas fa-book-open"></i> BookHaven
            </a>
            
            <nav>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="browse.php" class="active">Browse</a></li>
                    <li><a href="categories.php">Categories</a></li>
                    <li><a href="recommendations.php">For You</a></li>
                </ul>
            </nav>
            
            <div class="auth-buttons">
                <?php if ($user_logged_in): ?>
                    <a href="profile.php" class="login-btn">My Profile</a>
                    <a href="logout.php" class="signup-btn">Log Out</a>
                <?php else: ?>
                    <a href="login.php" class="login-btn">Log In</a>
                    <a href="signup.php" class="signup-btn">Sign Up</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="container">
        <!-- Breadcrumbs -->
        <div class="breadcrumbs">
            <a href="index.php">Home</a> <span>/</span>
            <a href="browse.php">Browse</a> <span>/</span>
            <span><?php echo htmlspecialchars($title); ?></span>
        </div>
        
        <!-- Book Detail -->
        <div class="book-detail" data-book-id="<?php echo htmlspecialchars($book_id); ?>">
            <div class="book-header">
                <div class="book-cover">
                    <img src="<?php echo htmlspecialchars($cover_image); ?>" alt="<?php echo htmlspecialchars($title); ?> cover">
                </div>
                
                <div class="book-info">
                    <h1 class="book-title"><?php echo htmlspecialchars($title); ?></h1>
                    <div class="book-author">By <?php echo htmlspecialchars($author); ?></div>
                    
                    <div class="book-rating">
                        <div class="stars">
                            <?php echo getStarsHTML($average_rating); ?>
                        </div>
                        <div class="rating-count">
                            <?php echo number_format($average_rating, 1); ?> 
                            (<?php echo $total_reviews; ?> <?php echo $total_reviews == 1 ? 'rating' : 'ratings'; ?>)
                        </div>
                    </div>
                    
                    <div class="book-meta">
                        <?php if (!empty($publisher)): ?>
                            <div class="book-meta-item">
                                <span class="meta-label">Publisher:</span>
                                <span><?php echo htmlspecialchars($publisher); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($publication_date)): ?>
                            <div class="book-meta-item">
                                <span class="meta-label">Published:</span>
                                <span><?php echo htmlspecialchars($publication_date); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($pages)): ?>
                            <div class="book-meta-item">
                                <span class="meta-label">Pages:</span>
                                <span><?php echo htmlspecialchars($pages); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($language)): ?>
                            <div class="book-meta-item">
                                <span class="meta-label">Language:</span>
                                <span><?php echo htmlspecialchars($bookManager->getLanguageName($language)); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($isbn)): ?>
                            <div class="book-meta-item">
                                <span class="meta-label">ISBN:</span>
                                <span><?php echo htmlspecialchars($isbn); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($isbn13)): ?>
                            <div class="book-meta-item">
                                <span class="meta-label">ISBN13:</span>
                                <span><?php echo htmlspecialchars($isbn13); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="book-actions">
                        <?php if ($user_logged_in): ?>
                            <?php if ($in_library): ?>
                                <button class="btn btn-primary in-library">
                                    <i class="fas fa-check"></i> In Your Library
                                </button>
                                
                                <div class="dropdown">
                                    <button class="btn btn-secondary dropdown-toggle">
                                        <i class="fas fa-book"></i> <?php echo ucfirst(str_replace('_', ' ', $reading_status)); ?>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a href="#" class="dropdown-item update-status" data-status="to_read">To Read</a>
                                        <a href="#" class="dropdown-item update-status" data-status="reading">Currently Reading</a>
                                        <a href="#" class="dropdown-item update-status" data-status="completed">Completed</a>
                                        <div class="dropdown-divider"></div>
                                        <a href="#" class="dropdown-item text-danger remove-from-library">Remove from Library</a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <button class="btn btn-primary add-to-library">
                                    <i class="fas fa-plus"></i> Add to Library
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="login.php?redirect=<?php echo urlencode('book.php?id=' . $book_id); ?>" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Add to Library
                            </a>
                        <?php endif; ?>
                        
                        <button class="btn btn-secondary">
                            <i class="fas fa-share-alt"></i> Share
                        </button>
                    </div>
                    
                    <?php if ($in_library && $reading_status == 'reading' && $reading_progress > 0): ?>
                        <div class="reading-progress">
                            <h3>Your Reading Progress</h3>
                            <div class="progress-container">
                                <div class="progress-bar" style="width: <?php echo $reading_progress; ?>%;"></div>
                            </div>
                            <div class="progress-text"><?php echo $reading_progress; ?>% Complete</div>
                            
                            <div class="progress-controls">
                                <input type="range" min="0" max="100" value="<?php echo $reading_progress; ?>" class="progress-slider" id="progress-slider">
                                <button class="btn btn-primary btn-sm update-progress-btn">Update Progress</button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="book-content">
                <h2>About this book</h2>
                <div class="book-description">
                    <?php if (!empty($description)): ?>
                        <p><?php echo nl2br(htmlspecialchars($description)); ?></p>
                    <?php else: ?>
                        <p>No description available for this book.</p>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($category)): ?>
                    <div class="book-categories">
                        <h3>Categories</h3>
                        <div class="categories-tags">
                            <a href="browse.php?category=<?php echo urlencode($category); ?>" class="category-tag">
                                <?php echo htmlspecialchars($category); ?>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Reviews Section -->
        <div class="reviews-section">
            <div class="section-header">
                <h2 class="section-title">Reviews</h2>
                <?php if ($user_logged_in): ?>
                    <button id="write-review-btn" class="btn btn-primary">
                        <i class="fas fa-pencil-alt"></i> Write a Review
                    </button>
                <?php else: ?>
                    <a href="login.php?redirect=<?php echo urlencode('book.php?id=' . $book_id); ?>" class="btn btn-primary">
                        <i class="fas fa-pencil-alt"></i> Write a Review
                    </a>
                <?php endif; ?>
            </div>
            
            <div class="reviews-content">
                <?php if (empty($reviews)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <h3>No Reviews Yet</h3>
                        <p>Be the first to review this book!</p>
                        <?php if ($user_logged_in): ?>
                            <button id="empty-write-review-btn" class="btn btn-primary">
                                <i class="fas fa-pencil-alt"></i> Write a Review
                            </button>
                        <?php else: ?>
                            <a href="login.php?redirect=<?php echo urlencode('book.php?id=' . $book_id); ?>" class="btn btn-primary">
                                <i class="fas fa-pencil-alt"></i> Write a Review
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="reviews-container">
                        <?php foreach ($reviews as $review): ?>
                            <div class="review-card">
                                <div class="review-header">
                                    <div class="reviewer-info">
                                        <img src="<?php echo htmlspecialchars($review['profile_image'] ?: 'assets/images/user-placeholder.png'); ?>" alt="<?php echo htmlspecialchars($review['username']); ?>" class="reviewer-image">
                                        <div>
                                            <div class="reviewer"><?php echo htmlspecialchars($review['username']); ?></div>
                                            <div class="review-date"><?php echo htmlspecialchars($review['review_date']); ?></div>
                                        </div>
                                    </div>
                                    <div class="review-rating">
                                        <?php echo getStarsHTML($review['rating']); ?>
                                    </div>
                                </div>
                                <div class="review-title"><?php echo htmlspecialchars($review['review_title']); ?></div>
                                <div class="review-text">
                                    <p><?php echo nl2br(htmlspecialchars($review['review_text'])); ?></p>
                                </div>
                                <div class="review-actions">
                                    <?php if ($user_logged_in): ?>
                                        <button class="review-like-btn <?php echo $review['user_liked'] ? 'liked' : ''; ?>" data-review-id="<?php echo $review['review_id']; ?>">
                                            <i class="fa<?php echo $review['user_liked'] ? 's' : 'r'; ?> fa-thumbs-up"></i>
                                            <span class="likes-count"><?php echo $review['likes_count']; ?></span>
                                        </button>
                                    <?php else: ?>
                                        <button class="review-like-btn" onclick="alert('Please log in to like reviews');">
                                            <i class="far fa-thumbs-up"></i>
                                            <span class="likes-count"><?php echo $review['likes_count']; ?></span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($total_reviews > 5): ?>
                        <div class="view-all-reviews">
                            <a href="book_reviews.php?id=<?php echo htmlspecialchars($book_id); ?>" class="btn btn-secondary">
                                View All <?php echo $total_reviews; ?> Reviews
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Related Books Section -->
        <?php if (!empty($related_books)): ?>
            <div class="related-books-section">
                <div class="section-header">
                    <h2 class="section-title">You might also like</h2>
                    <?php if (!empty($category)): ?>
                        <a href="browse.php?category=<?php echo urlencode($category); ?>" class="btn btn-primary">
                            View More <?php echo htmlspecialchars($category); ?> Books
                        </a>
                    <?php endif; ?>
                </div>
                
                <div class="books-grid">
                    <?php foreach ($related_books as $related_book): ?>
                        <div class="book-card" data-book-id="<?php echo htmlspecialchars($related_book['book_id']); ?>">
                            <div class="book-cover">
                                <img src="<?php echo htmlspecialchars($related_book['cover_image'] ?: 'assets/images/cover-placeholder.png'); ?>" alt="<?php echo htmlspecialchars($related_book['title']); ?>" loading="lazy">
                            </div>
                            <div class="book-info">
                                <h3 class="book-title"><?php echo htmlspecialchars($related_book['title']); ?></h3>
                                <div class="book-author"><?php echo htmlspecialchars($related_book['author']); ?></div>
                                <div class="book-actions">
                                    <a href="book.php?id=<?php echo htmlspecialchars($related_book['book_id']); ?>" class="btn btn-primary btn-sm">View Details</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-grid">
                <div class="footer-section">
                    <h3>BookHaven</h3>
                    <p>Your destination for discovering and enjoying books of all genres.</p>
                </div>

                <div class="footer-section">
                    <h3>Explore</h3>
                    <ul class="footer-links">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="browse.php">Browse</a></li>
                        <li><a href="categories.php">Categories</a></li>
                        <li><a href="recommendations.php">For You</a></li>
                    </ul>
                </div>

                <div class="footer-section">
                    <h3>Account</h3>
                    <ul class="footer-links">
                        <li><a href="profile.php">Profile</a></li>
                        <li><a href="library.php">My Library</a></li>
                        <li><a href="settings.php">Settings</a></li>
                    </ul>
                </div>

                <div class="footer-section">
                    <h3>Support</h3>
                    <ul class="footer-links">
                        <li><a href="help.php">Help Center</a></li>
                        <li><a href="contact.php">Contact Us</a></li>
                        <li><a href="privacy.php">Privacy Policy</a></li>
                        <li><a href="about.php">About Us</a></li>
                    </ul>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> BookHaven. All rights reserved.</p>
            </div>
        </div>
    </footer>
    
    <script src="assets/js/app.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Add to Library button
            const addToLibraryBtn = document.querySelector('.add-to-library');
            if (addToLibraryBtn) {
                addToLibraryBtn.addEventListener('click', function() {
                    const bookId = document.querySelector('.book-detail').dataset.bookId;
                    
                    // Send AJAX request
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', 'add_to_library.php', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    // Show success message and reload the page
                                    alert(response.message);
                                    window.location.reload();
                                } else {
                                    alert(response.message);
                                }
                            } catch (e) {
                                alert('Error adding book to library');
                            }
                        }
                    };
                    xhr.send(`book_id=${bookId}`);
                });
            }
            
            // Update status buttons
            const statusButtons = document.querySelectorAll('.update-status');
            if (statusButtons.length > 0) {
                statusButtons.forEach(button => {
                    button.addEventListener('click', function(e) {
                        e.preventDefault();
                        
                        const bookId = document.querySelector('.book-detail').dataset.bookId;
                        const status = this.dataset.status;
                        
                        // Send AJAX request
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', 'update_book_status.php', true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onreadystatechange = function() {
                            if (xhr.readyState === 4 && xhr.status === 200) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    if (response.success) {
                                        // Reload page to show updated status
                                        window.location.reload();
                                    } else {
                                        alert(response.message);
                                    }
                                } catch (e) {
                                    alert('Error updating book status');
                                }
                            }
                        };
                        xhr.send(`book_id=${bookId}&status=${status}`);
                    });
                });
            }
            
            // Remove from library button
            const removeButton = document.querySelector('.remove-from-library');
            if (removeButton) {
                removeButton.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    if (confirm('Are you sure you want to remove this book from your library?')) {
                        const bookId = document.querySelector('.book-detail').dataset.bookId;
                        
                        // Send AJAX request
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', 'remove_from_library.php', true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onreadystatechange = function() {
                            if (xhr.readyState === 4 && xhr.status === 200) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    if (response.success) {
                                        // Reload page
                                        window.location.reload();
                                    } else {
                                        alert(response.message);
                                    }
                                } catch (e) {
                                    alert('Error removing book from library');
                                }
                            }
                        };
                        xhr.send(`book_id=${bookId}`);
                    }
                });
            }
            
            // Update progress slider and button
            const progressSlider = document.getElementById('progress-slider');
            const updateProgressBtn = document.querySelector('.update-progress-btn');
            
            if (progressSlider && updateProgressBtn) {
                updateProgressBtn.addEventListener('click', function() {
                    const bookId = document.querySelector('.book-detail').dataset.bookId;
                    const progress = progressSlider.value;
                    
                    // Send AJAX request
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', 'update_reading_progress.php', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onreadystatechange = function() {
                        if (xhr.readyState === 4 && xhr.status === 200) {
                            try {
                                const response = JSON.parse(xhr.responseText);
                                if (response.success) {
                                    // Update UI without reloading
                                    document.querySelector('.progress-bar').style.width = `${progress}%`;
                                    document.querySelector('.progress-text').textContent = `${progress}% Complete`;
                                    
                                    // Show success message
                                    alert('Reading progress updated');
                                    
                                    // If progress is 100%, reload page to update status
                                    if (progress === '100') {
                                        window.location.reload();
                                    }
                                } else {
                                    alert(response.message);
                                }
                            } catch (e) {
                                alert('Error updating reading progress');
                            }
                        }
                    };
                    xhr.send(`book_id=${bookId}&progress=${progress}`);
                });
            }
            
            // Write review buttons
            const writeReviewBtn = document.getElementById('write-review-btn');
            const emptyWriteReviewBtn = document.getElementById('empty-write-review-btn');
            
            if (writeReviewBtn) {
                writeReviewBtn.addEventListener('click', function() {
                    showReviewForm();
                });
            }
            
            if (emptyWriteReviewBtn) {
                emptyWriteReviewBtn.addEventListener('click', function() {
                    showReviewForm();
                });
            }
            
            // Review like buttons
            const reviewLikeButtons = document.querySelectorAll('.review-like-btn');
            reviewLikeButtons.forEach(button => {
                if (!button.classList.contains('disabled')) {
                    button.addEventListener('click', function() {
                        const reviewId = this.dataset.reviewId;
                        if (!reviewId) return;
                        
                        // Toggle like state in UI
                        const isLiked = this.classList.contains('liked');
                        const icon = this.querySelector('i');
                        const likesCountElement = this.querySelector('.likes-count');
                        let likesCount = parseInt(likesCountElement.textContent);
                        
                        if (isLiked) {
                            this.classList.remove('liked');
                            icon.className = 'far fa-thumbs-up';
                            likesCount--;
                        } else {
                            this.classList.add('liked');
                            icon.className = 'fas fa-thumbs-up';
                            likesCount++;
                        }
                        
                        likesCountElement.textContent = likesCount;
                        
                        // Send AJAX request
                        const xhr = new XMLHttpRequest();
                        xhr.open('POST', 'toggle_like.php', true);
                        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                        xhr.onreadystatechange = function() {
                            if (xhr.readyState === 4 && xhr.status === 200) {
                                try {
                                    const response = JSON.parse(xhr.responseText);
                                    if (!response.success) {
                                        // Revert UI changes if there was an error
                                        if (isLiked) {
                                            button.classList.add('liked');
                                            icon.className = 'fas fa-thumbs-up';
                                            likesCountElement.textContent = likesCount + 1;
                                        } else {
                                            button.classList.remove('liked');
                                            icon.className = 'far fa-thumbs-up';
                                            likesCountElement.textContent = likesCount - 1;
                                        }
                                        
                                        alert(response.message || 'Error toggling like');
                                    }
                                } catch (e) {
                                    alert('Error processing like/unlike');
                                }
                            }
                        };
                        xhr.send(`review_id=${reviewId}`);
                    });
                }
            });
            
            // Share button
            const shareBtn = document.querySelector('.btn-secondary');
            if (shareBtn) {
                shareBtn.addEventListener('click', function() {
                    const bookTitle = document.querySelector('.book-title').textContent;
                    const bookAuthor = document.querySelector('.book-author').textContent;
                    
                    if (navigator.share) {
                        navigator.share({
                            title: `${bookTitle} - BookHaven`,
                            text: `Check out "${bookTitle}" ${bookAuthor} on BookHaven!`,
                            url: window.location.href
                        }).catch(err => {
                            console.error('Share error:', err);
                        });
                    } else {
                        // Fallback for browsers without Web Share API
                        const dummyInput = document.createElement('input');
                        document.body.appendChild(dummyInput);
                        dummyInput.value = window.location.href;
                        dummyInput.select();
                        document.execCommand('copy');
                        document.body.removeChild(dummyInput);
                        
                        alert('Link copied to clipboard! You can now share it.');
                    }
                });
            }
            
            // Dropdown toggle for status menu
            const dropdownToggle = document.querySelector('.dropdown-toggle');
            if (dropdownToggle) {
                dropdownToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    const dropdown = this.nextElementSibling;
                    dropdown.classList.toggle('show');
                    
                    // Close dropdown when clicking outside
                    document.addEventListener('click', function closeDropdown(e) {
                        if (!e.target.closest('.dropdown')) {
                            dropdown.classList.remove('show');
                            document.removeEventListener('click', closeDropdown);
                        }
                    });
                });
            }
        });
        
        // Function to show review form
        function showReviewForm() {
            // Check if form already exists
            if (document.getElementById('review-form-container')) {
                return;
            }
            
            // Create review form
            const reviewFormContainer = document.createElement('div');
            reviewFormContainer.id = 'review-form-container';
            reviewFormContainer.className = 'review-form-container';
            
            reviewFormContainer.innerHTML = `
                <div class="review-form-header">
                    <h3>Write Your Review</h3>
                    <button type="button" class="close-form-btn"><i class="fas fa-times"></i></button>
                </div>
                
                <form id="review-form">
                    <div class="form-group">
                        <label for="rating">Rating</label>
                        <div class="star-rating">
                            <i class="far fa-star" data-rating="1"></i>
                            <i class="far fa-star" data-rating="2"></i>
                            <i class="far fa-star" data-rating="3"></i>
                            <i class="far fa-star" data-rating="4"></i>
                            <i class="far fa-star" data-rating="5"></i>
                        </div>
                        <input type="hidden" id="rating" name="rating" value="" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="review-title">Review Title</label>
                        <input type="text" id="review-title" name="review_title" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="review-text">Your Review</label>
                        <textarea id="review-text" name="review_text" rows="5" required></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-secondary cancel-review-btn">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Review</button>
                    </div>
                </form>
            `;
            
            // Add form to page
            const reviewsSection = document.querySelector('.reviews-section');
            reviewsSection.insertBefore(reviewFormContainer, reviewsSection.querySelector('.reviews-content'));
            
            // Setup star rating functionality
            const starRating = document.querySelector('.star-rating');
            const ratingInput = document.getElementById('rating');
            const stars = starRating.querySelectorAll('i');
            
            stars.forEach(star => {
                // Highlight stars on hover
                star.addEventListener('mouseover', function() {
                    const rating = parseInt(this.dataset.rating);
                    highlightStars(stars, rating);
                });
                
                // Set rating on click
                star.addEventListener('click', function() {
                    const rating = parseInt(this.dataset.rating);
                    ratingInput.value = rating;
                    highlightStars(stars, rating);
                });
            });
            
            // Reset stars when mouse leaves rating area
            starRating.addEventListener('mouseout', function() {
                const rating = parseInt(ratingInput.value) || 0;
                highlightStars(stars, rating);
            });
            
            // Close form buttons
            const closeBtn = document.querySelector('.close-form-btn');
            const cancelBtn = document.querySelector('.cancel-review-btn');
            
            closeBtn.addEventListener('click', function() {
                reviewFormContainer.remove();
            });
            
            cancelBtn.addEventListener('click', function() {
                reviewFormContainer.remove();
            });
            
            // Submit review
            const reviewForm = document.getElementById('review-form');
            reviewForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Validate form
                const rating = document.getElementById('rating').value;
                const title = document.getElementById('review-title').value.trim();
                const text = document.getElementById('review-text').value.trim();
                
                if (!rating) {
                    alert('Please select a rating');
                    return;
                }
                
                if (!title) {
                    alert('Please enter a review title');
                    return;
                }
                
                if (!text) {
                    alert('Please enter your review');
                    return;
                }
                
                // Get book ID
                const bookId = document.querySelector('.book-detail').dataset.bookId;
                
                // Send AJAX request
                const xhr = new XMLHttpRequest();
                xhr.open('POST', 'add_review.php', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4 && xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                alert(response.message);
                                window.location.reload(); // Reload to show the new review
                            } else {
                                alert(response.message);
                            }
                        } catch (e) {
                            alert('Error submitting review');
                        }
                    }
                };
                xhr.send(`book_id=${bookId}&rating=${rating}&review_title=${encodeURIComponent(title)}&review_text=${encodeURIComponent(text)}`);
            });
        }
        
        // Function to highlight stars for rating
        function highlightStars(stars, rating) {
            stars.forEach((star, index) => {
                if (index < rating) {
                    star.className = 'fas fa-star'; // Filled star
                } else {
                    star.className = 'far fa-star'; // Empty star
                }
            });
        }