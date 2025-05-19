<?php
/**
 * index.php - Homepage and book browsing
 */

// Include necessary files
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Start session
session_start();

// Initialize variables
$search_query = isset($_GET['query']) ? trim($_GET['query']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$is_browse = isset($_GET['browse']) && $_GET['browse'] === 'true';
$limit = 12; // Books per page
$books = [];
$error = '';

// Process search or get featured books
if (!empty($search_query)) {
    // Search books
    $search_results = searchBooks($search_query, ($page - 1) * $limit, $limit);
    
    if ($search_results && isset($search_results['items'])) {
        foreach ($search_results['items'] as $book) {
            // Add book to cache and reformat
            addBookToCache($book);
            $books[] = formatBookData($book);
        }
    }
} elseif (!empty($category)) {
    // Get books by category
    $category_query = "subject:{$category}";
    $category_results = searchBooks($category_query, ($page - 1) * $limit, $limit);
    
    if ($category_results && isset($category_results['items'])) {
        foreach ($category_results['items'] as $book) {
            // Add book to cache and reformat
            addBookToCache($book);
            $books[] = formatBookData($book);
        }
    }
} elseif ($is_browse) {
    // Get popular books - simplified to just get recent books
    $browse_query = "subject:fiction";
    $browse_results = searchBooks($browse_query, ($page - 1) * $limit, $limit);
    
    if ($browse_results && isset($browse_results['items'])) {
        foreach ($browse_results['items'] as $book) {
            // Add book to cache and reformat
            addBookToCache($book);
            $books[] = formatBookData($book);
        }
    }
} else {
    // Home page - featured books (random popular subjects)
    $popular_subjects = ['fiction', 'fantasy', 'science fiction', 'mystery', 'romance'];
    $random_subject = $popular_subjects[array_rand($popular_subjects)];
    $featured_results = searchBooks("subject:{$random_subject}", 0, 8);
    
    if ($featured_results && isset($featured_results['items'])) {
        foreach ($featured_results['items'] as $book) {
            // Add book to cache and reformat
            addBookToCache($book);
            $books[] = formatBookData($book);
        }
    }
}

// Define page title
if (!empty($search_query)) {
    $page_title = "Search: " . htmlspecialchars($search_query) . " - " . SITE_NAME;
} elseif (!empty($category)) {
    $page_title = ucwords($category) . " Books - " . SITE_NAME;
} elseif ($is_browse) {
    $page_title = "Browse Books - " . SITE_NAME;
} else {
    $page_title = SITE_NAME . " - Your Online Book Discovery Platform";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
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
                        <li><a href="index.php" class="<?php echo (!$is_browse && empty($search_query) && empty($category)) ? 'active' : ''; ?>">Home</a></li>
                        <li><a href="index.php?browse=true" class="<?php echo $is_browse ? 'active' : ''; ?>">Browse</a></li>
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
                        <a href="auth.php" class="btn">Log In</a>
                        <a href="auth.php?action=signup" class="btn btn-primary">Sign Up</a>
                    <?php endif; ?>
                </div>
            </div>
        </header>
        
        <!-- Main Content -->
        <main>
            <?php if (!$is_browse && empty($search_query) && empty($category)): ?>
                <!-- Homepage Hero -->
                <section class="hero">
                    <div class="container">
                        <h1>Discover Your Next Great Book</h1>
                        <p>Search millions of books, track your reading, and share your reviews</p>
                        
                        <form class="search-form" action="index.php" method="GET">
                            <input type="text" name="query" placeholder="Search by title, author, or category..." required>
                            <button type="submit"><i class="fas fa-search"></i></button>
                        </form>
                        
                        <div class="popular-categories">
                            <span>Popular:</span>
                            <a href="index.php?category=fiction">Fiction</a>
                            <a href="index.php?category=fantasy">Fantasy</a>
                            <a href="index.php?category=mystery">Mystery</a>
                            <a href="index.php?category=romance">Romance</a>
                        </div>
                    </div>
                </section>
            <?php else: ?>
                <!-- Search Form for Browse/Search Pages -->
                <section class="search-section">
                    <div class="container">
                        <form class="search-form" action="index.php" method="GET">
                            <input type="text" name="query" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search by title, author, or category...">
                            <button type="submit"><i class="fas fa-search"></i></button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
            
            <!-- Books Section -->
            <section class="books-section">
                <div class="container">
                    <?php if (!$is_browse && empty($search_query) && empty($category)): ?>
                        <h2>Featured Books</h2>
                    <?php elseif (!empty($search_query)): ?>
                        <h2>Search Results for "<?php echo htmlspecialchars($search_query); ?>"</h2>
                    <?php elseif (!empty($category)): ?>
                        <h2><?php echo ucwords($category); ?> Books</h2>
                    <?php else: ?>
                        <h2>All Books</h2>
                    <?php endif; ?>
                    
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                    <?php endif; ?>
                    
                    <?php if (empty($books)): ?>
                        <div class="empty-state">
                            <i class="fas fa-book-open"></i>
                            <h3>No Books Found</h3>
                            <p>Try a different search term or browse our categories.</p>
                        </div>
                    <?php else: ?>
                        <div class="books-grid">
                            <?php foreach ($books as $book): ?>
                                <div class="book-card">
                                    <div class="book-cover">
                                        <img src="<?php echo $book['cover_image'] ?: 'assets/images/cover-placeholder.png'; ?>" alt="<?php echo htmlspecialchars($book['title']); ?>">
                                    </div>
                                    <div class="book-info">
                                        <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                        <p class="book-author">By <?php echo htmlspecialchars($book['author']); ?></p>
                                        <a href="book.php?id=<?php echo $book['book_id']; ?>" class="btn btn-primary">View Details</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($is_browse || !empty($search_query) || !empty($category)): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="btn">&laquo; Previous</a>
                                <?php endif; ?>
                                
                                <span class="page-info">Page <?php echo $page; ?></span>
                                
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="btn">Next &raquo;</a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>
            
            <?php if (!$is_browse && empty($search_query) && empty($category)): ?>
                <!-- Categories Section for Homepage -->
                <section class="categories-section">
                    <div class="container">
                        <h2>Browse by Category</h2>
                        <div class="categories-grid">
                            <a href="index.php?category=fiction" class="category-card">
                                <i class="fas fa-book"></i>
                                <h3>Fiction</h3>
                            </a>
                            <a href="index.php?category=fantasy" class="category-card">
                                <i class="fas fa-dragon"></i>
                                <h3>Fantasy</h3>
                            </a>
                            <a href="index.php?category=science%20fiction" class="category-card">
                                <i class="fas fa-rocket"></i>
                                <h3>Science Fiction</h3>
                            </a>
                            <a href="index.php?category=mystery" class="category-card">
                                <i class="fas fa-search"></i>
                                <h3>Mystery</h3>
                            </a>
                            <a href="index.php?category=romance" class="category-card">
                                <i class="fas fa-heart"></i>
                                <h3>Romance</h3>
                            </a>
                            <a href="index.php?category=biography" class="category-card">
                                <i class="fas fa-user"></i>
                                <h3>Biography</h3>
                            </a>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
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