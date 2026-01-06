document.addEventListener('DOMContentLoaded', function () {
    var languageSelect = document.getElementById('language');
    if (!languageSelect) return;

    languageSelect.addEventListener('change', function (event) {
        var params = new URLSearchParams(window.location.search);
        params.set('lang', event.target.value);
        window.location.search = params.toString();
    });
});

// Hide header on scroll down, show on scroll up
var lastScrollTop = 0;
var header = document.querySelector('.app-header');

if (header) {
    window.addEventListener('scroll', function () {
        var scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        
        if (scrollTop > lastScrollTop + 50) {
            // Scrolling down: hide header
            header.style.transform = 'translateY(-100%)';
        } else if (scrollTop < lastScrollTop - 50) {
            // Scrolling up: show header
            header.style.transform = 'translateY(0)';
        }
        
        lastScrollTop = scrollTop <= 0 ? 0 : scrollTop; // For Mobile or negative scrolling
    });
}
