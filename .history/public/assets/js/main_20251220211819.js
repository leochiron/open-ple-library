document.addEventListener('DOMContentLoaded', function () {
    var languageSelect = document.getElementById('language');
    if (!languageSelect) return;

    languageSelect.addEventListener('change', function (event) {
        var params = new URLSearchParams(window.location.search);
        params.set('lang', event.target.value);
        window.location.search = params.toString();
    });
});
