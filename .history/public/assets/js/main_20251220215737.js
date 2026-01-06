document.addEventListener('DOMContentLoaded', function () {
    // Language switcher
    var languageSelect = document.getElementById('language');
    if (languageSelect) {
        languageSelect.addEventListener('change', function (event) {
            var params = new URLSearchParams(window.location.search);
            params.set('lang', event.target.value);
            window.location.search = params.toString();
        });
    }

    // View mode toggle
    var viewListBtn = document.getElementById('view-list-btn');
    var viewGridBtn = document.getElementById('view-grid-btn');
    var fileListContainer = document.getElementById('file-list-container');

    if (viewListBtn && viewGridBtn && fileListContainer) {
        // Load saved view mode from localStorage
        var savedMode = localStorage.getItem('ple-view-mode') || 'list';
        
        function setViewMode(mode) {
            localStorage.setItem('ple-view-mode', mode);
            fileListContainer.classList.remove('view-list', 'view-grid');
            fileListContainer.classList.add('view-' + mode);
            
            viewListBtn.classList.remove('active');
            viewGridBtn.classList.remove('active');
            
            if (mode === 'grid') {
                viewGridBtn.classList.add('active');
            } else {
                viewListBtn.classList.add('active');
            }
        }
        
        // Initialize with saved mode
        setViewMode(savedMode);

        viewListBtn.addEventListener('click', function () {
            setViewMode('list');
        });

        viewGridBtn.addEventListener('click', function () {
            setViewMode('grid');
        });
    }
});
