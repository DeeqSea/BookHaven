// app.js - Simple JavaScript for BookHaven

document.addEventListener('DOMContentLoaded', function() {
    // Book card click handler
    const bookCards = document.querySelectorAll('.book-card');
    bookCards.forEach(card => {
        card.addEventListener('click', function(e) {
            // Don't navigate if clicking on a button or form element
            if (e.target.closest('button') || e.target.closest('form')) {
                return;
            }
            
            // Get book ID from data attribute or from URL
            const bookId = this.getAttribute('data-id');
            if (bookId) {
                window.location.href = 'book.php?id=' + bookId;
            }
        });
    });
    
    // Rating select functionality
    const ratingInputs = document.querySelectorAll('.rating-select input[type="radio"]');
    const ratingLabels = document.querySelectorAll('.rating-select label');
    
    ratingInputs.forEach(input => {
        input.addEventListener('change', function() {
            const rating = this.value;
            
            // Reset all stars
            ratingLabels.forEach(label => {
                label.innerHTML = '<i class="far fa-star"></i>';
            });
            
            // Fill stars up to the selected rating
            for (let i = 0; i < rating; i++) {
                ratingLabels[i].innerHTML = '<i class="fas fa-star"></i>';
            }
        });
    });
    
    // Tab functionality on profile page
    const tabLinks = document.querySelectorAll('.tab-link');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Get the target tab ID
            const targetId = this.getAttribute('href').substring(1);
            
            // Remove active class from all tabs
            tabLinks.forEach(link => link.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Add active class to current tab
            this.classList.add('active');
            document.getElementById(targetId).classList.add('active');
            
            // Update URL hash
            window.location.hash = targetId;
        });
    });
    
    // Check if URL has hash and activate corresponding tab
    if (window.location.hash) {
        const hash = window.location.hash.substring(1);
        const tabLink = document.querySelector(`a[href="#${hash}"]`);
        if (tabLink) {
            tabLink.click();
        }
    }
    
    // Password visibility toggle
    const passwordToggles = document.querySelectorAll('.password-toggle');
    passwordToggles.forEach(toggle => {
        toggle.addEventListener('click', function() {
            const passwordField = this.previousElementSibling;
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                this.innerHTML = '<i class="fas fa-eye-slash"></i>';
            } else {
                passwordField.type = 'password';
                this.innerHTML = '<i class="fas fa-eye"></i>';
            }
        });
    });
    
    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => {
                alert.style.display = 'none';
            }, 500);
        }, 5000);
    });
});