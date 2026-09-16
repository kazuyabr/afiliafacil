function toggleTheme() {
    const html = document.documentElement;
    const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    applyTheme(next, true);
}

function applyTheme(theme, persist) {
    document.documentElement.setAttribute('data-theme', theme);

    if (persist) {
        try { localStorage.setItem('theme', theme); } catch (e) {}
        try { document.cookie = 'theme=' + theme + '; path=/; max-age=31536000; SameSite=Lax'; } catch (e) {}

        if (location.pathname.indexOf('/admin') === 0) {
            fetch('/admin/settings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'theme=' + theme
            }).catch(function () {});
        }
    }

    document.querySelectorAll('.theme-toggle i').forEach(function (icon) {
        icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    });
}

(function () {
    try {
        const saved = localStorage.getItem('theme');
        const system = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
        const theme = saved || system;

        if (saved) {
            // migra escolhas antigas (localStorage) para o cookie usado no SSR
            try { document.cookie = 'theme=' + saved + '; path=/; max-age=31536000; SameSite=Lax'; } catch (e) {}
        }

        applyTheme(theme, false);
    } catch (e) {}
})();

function showToast(message, type = 'success') {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle') + '"></i> ' + message;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

document.querySelectorAll('.modal-overlay').forEach(modal => {
    modal.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
    });
});
