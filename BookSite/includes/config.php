
<?php
/**
 * config.php - Configuration settings for BookHaven
 */

// Database Configuration
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_NAME', 'bookhaven');

// Google Books API key
define('GOOGLE_BOOKS_API_KEY', 'AIzaSyDiISl4z0wyuD4yHveC9HbXFRn-kTNymyU');

// Book cache time in seconds (7 days)
define('BOOK_CACHE_TIME', 7 * 24 * 60 * 60);

// Site settings
define('SITE_NAME', 'BookHaven');
define('SITE_URL', 'http://localhost/bookhaven');