<?php
/**
 * functions.php - Helper functions for BookHaven
 */

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Redirect to a URL
 * @param string $url URL to redirect to
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Display error message
 * @param string $message Error message
 * @return string HTML for error message
 */
function showError($message) {
    return '<div class="alert alert-danger">' . htmlspecialchars($message) . '</div>';
}

/**
 * Display success message
 * @param string $message Success message
 * @return string HTML for success message
 */
function showSuccess($message) {
    return '<div class="alert alert-success">' . htmlspecialchars($message) . '</div>';
}

/**
 * Get star rating HTML
 * @param float $rating Rating value (0-5)
 * @return string HTML for star rating
 */
function getStarsHtml($rating) {
    $rating = floatval($rating);
    $fullStars = floor($rating);
    $halfStar = $rating - $fullStars >= 0.5;
    $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
    
    $html = '';
    
    // Full stars
    for ($i = 0; $i < $fullStars; $i++) {
        $html .= '<i class="fas fa-star"></i>';
    }
    
    // Half star
    if ($halfStar) {
        $html .= '<i class="fas fa-star-half-alt"></i>';
    }
    
    // Empty stars
    for ($i = 0; $i < $emptyStars; $i++) {
        $html .= '<i class="far fa-star"></i>';
    }
    
    return $html;
}

/**
 * Get book details from Google Books API
 * @param string $book_id Google Books ID
 * @return array|null Book data or null on failure
 */
function getBookFromAPI($book_id) {
    $url = "https://www.googleapis.com/books/v1/volumes/{$book_id}";
    
    if (defined('GOOGLE_BOOKS_API_KEY')) {
        $url .= "?key=" . GOOGLE_BOOKS_API_KEY;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log('Google Books API Error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    return json_decode($response, true);
}

/**
 * Search books from Google Books API
 * @param string $query Search query
 * @param int $startIndex Starting index for pagination
 * @param int $maxResults Maximum results to return
 * @return array|null Search results or null on failure
 */
function searchBooks($query, $startIndex = 0, $maxResults = 10) {
    $url = "https://www.googleapis.com/books/v1/volumes?q=" . urlencode($query);
    $url .= "&startIndex={$startIndex}&maxResults={$maxResults}";
    
    if (defined('GOOGLE_BOOKS_API_KEY')) {
        $url .= "&key=" . GOOGLE_BOOKS_API_KEY;
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    
    if (curl_errno($ch)) {
        error_log('Google Books API Error: ' . curl_error($ch));
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    return json_decode($response, true);
}

/**
 * Format book data from Google Books API for consistent display
 * @param array $apiBook Data from Google Books API
 * @return array Formatted book data
 */
function formatBookData($apiBook) {
    if (!isset($apiBook['id']) || !isset($apiBook['volumeInfo'])) {
        return null;
    }
    
    $info = $apiBook['volumeInfo'];
    
    $book = [
        'book_id' => $apiBook['id'],
        'title' => isset($info['title']) ? $info['title'] : 'Unknown Title',
        'author' => isset($info['authors']) ? implode(', ', $info['authors']) : 'Unknown Author',
        'description' => isset($info['description']) ? $info['description'] : '',
        'cover_image' => null,
        'publisher' => isset($info['publisher']) ? $info['publisher'] : null,
        'publication_date' => isset($info['publishedDate']) ? $info['publishedDate'] : null,
        'isbn' => null,
        'isbn13' => null,
        'pages' => isset($info['pageCount']) ? $info['pageCount'] : null,
        'language' => isset($info['language']) ? $info['language'] : null,
        'category' => isset($info['categories']) ? $info['categories'][0] : null,
    ];
    
    // Get cover image
    if (isset($info['imageLinks'])) {
        $book['cover_image'] = isset($info['imageLinks']['thumbnail']) ? 
            str_replace('http://', 'https://', $info['imageLinks']['thumbnail']) : null;
    }
    
    // Get ISBN numbers
    if (isset($info['industryIdentifiers'])) {
        foreach ($info['industryIdentifiers'] as $identifier) {
            if ($identifier['type'] === 'ISBN_10') {
                $book['isbn'] = $identifier['identifier'];
            } elseif ($identifier['type'] === 'ISBN_13') {
                $book['isbn13'] = $identifier['identifier'];
            }
        }
    }
    
    return $book;
}

/**
 * Check if a book exists in the cache
 * @param string $book_id Book ID
 * @return bool Whether the book exists in cache
 */
function bookExistsInCache($book_id) {
    global $connection;
    
    $query = "SELECT book_id FROM books_cache WHERE book_id = ?";
    $stmt = $connection->prepare($query);
    $stmt->bind_param("s", $book_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Add book to cache
 * @param array $bookData Book data
 * @return bool Success status
 */
function addBookToCache($bookData) {
    global $connection;
    
    // Check if we have a valid book data
    if (!isset($bookData['id']) || !isset($bookData['volumeInfo'])) {
        return false;
    }
    
    $book_id = $bookData['id'];
    $info = $bookData['volumeInfo'];
    
    // Extract data with defaults for missing values
    $title = isset($info['title']) ? $info['title'] : 'Unknown Title';
    $author = isset($info['authors']) ? implode(', ', $info['authors']) : 'Unknown Author';
    $description = isset($info['description']) ? $info['description'] : '';
    
    // Get cover image if available
    $cover_image = null;
    if (isset($info['imageLinks'])) {
        $cover_image = isset($info['imageLinks']['thumbnail']) ? $info['imageLinks']['thumbnail'] : null;
        // Convert HTTP to HTTPS
        if ($cover_image) {
            $cover_image = str_replace('http://', 'https://', $cover_image);
        }
    }
    
    $publisher = isset($info['publisher']) ? $info['publisher'] : null;
    $publication_date = isset($info['publishedDate']) ? $info['publishedDate'] : null;
    
    // Get ISBN numbers
    $isbn = null;
    $isbn13 = null;
    if (isset($info['industryIdentifiers'])) {
        foreach ($info['industryIdentifiers'] as $identifier) {
            if ($identifier['type'] === 'ISBN_10') {
                $isbn = $identifier['identifier'];
            } elseif ($identifier['type'] === 'ISBN_13') {
                $isbn13 = $identifier['identifier'];
            }
        }
    }
    
    $pages = isset($info['pageCount']) ? $info['pageCount'] : null;
    $language = isset($info['language']) ? $info['language'] : null;
    $category = isset($info['categories']) ? $info['categories'][0] : null;
    
    // Handle publication date
    if ($publication_date) {
        // Format date for MySQL (YYYY-MM-DD)
        // Some dates from Google Books API only have year or year-month
        if (strlen($publication_date) === 4) {
            // Only year
            $publication_date .= '-01-01';
        } elseif (strlen($publication_date) === 7) {
            // Year and month
            $publication_date .= '-01';
        } elseif (strlen($publication_date) > 10) {
            // Take only first 10 characters
            $publication_date = substr($publication_date, 0, 10);
        }
    } else {
        $publication_date = null;
    }
    
    // Check if the book already exists in cache
    if (bookExistsInCache($book_id)) {
        // Update the book in cache
        $query = "UPDATE books_cache SET 
                 title = ?, 
                 author = ?, 
                 description = ?, 
                 cover_image = ?, 
                 publisher = ?, 
                 publication_date = ?, 
                 isbn = ?, 
                 isbn13 = ?, 
                 pages = ?, 
                 language = ?, 
                 category = ?, 
                 cached_at = CURRENT_TIMESTAMP 
                 WHERE book_id = ?";
        
        $stmt = $connection->prepare($query);
        $stmt->bind_param("ssssssssssss", 
            $title, 
            $author, 
            $description, 
            $cover_image, 
            $publisher, 
            $publication_date, 
            $isbn, 
            $isbn13, 
            $pages, 
            $language, 
            $category, 
            $book_id
        );
    } else {
        // Insert new book in cache
        $query = "INSERT INTO books_cache (
                 book_id, 
                 title, 
                 author, 
                 description, 
                 cover_image, 
                 publisher, 
                 publication_date, 
                 isbn, 
                 isbn13, 
                 pages, 
                 language, 
                 category
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $connection->prepare($query);
        $stmt->bind_param("ssssssssssss", 
            $book_id, 
            $title, 
            $author, 
            $description, 
            $cover_image, 
            $publisher, 
            $publication_date, 
            $isbn, 
            $isbn13, 
            $pages,
            $language,
            $category
        );
    }
    
    return $stmt->execute();
}

/**
 * Get book by ID (from cache or API)
 * @param string $book_id Book ID
 * @return array|null Book data or null if not found
 */
function getBook($book_id) {
    global $connection;
    
    // First, check if book exists in cache
    $query = "SELECT * FROM books_cache WHERE book_id = ?";
    $stmt = $connection->prepare($query);
    $stmt->bind_param("s", $book_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        // Get cached book data
        $cachedBook = $result->fetch_assoc();
        
        // Check if cache is expired
        $cacheTime = strtotime($cachedBook['cached_at']);
        $cacheExpiry = $cacheTime + BOOK_CACHE_TIME;
        
        if (time() < $cacheExpiry) {
            // Cache is still valid, return cached data
            return $cachedBook;
        }
    }
    
    // If not in cache or cache expired, fetch from API
    $apiBook = getBookFromAPI($book_id);
    
    if ($apiBook) {
        // Add or update in cache
        addBookToCache($apiBook);
        
        // Convert API response format to match our cached format
        return formatBookData($apiBook);
    } elseif ($result->num_rows === 1) {
        // If API fails but we have cached data, return it even if expired
        return $cachedBook;
    } else {
        // Book not found in cache or API
        return null;
    }
}