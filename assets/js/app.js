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

// ---------------------------------------------------------------------------
// Notificacoes do Socio de IA (sino no topbar do painel)
// ---------------------------------------------------------------------------
(function () {
    if (location.pathname.indexOf('/admin') !== 0) return;

    let lastCount = 0;
    let initialized = false;

    function ensureBell() {
        const actions = document.querySelector('.topbar-actions');
        if (!actions || document.getElementById('agentBell')) return;

        const btn = document.createElement('button');
        btn.id = 'agentBell';
        btn.className = 'theme-toggle';
        btn.title = 'Notificacoes do Socio de IA';
        btn.style.position = 'relative';
        btn.innerHTML = '<i class="fas fa-bell"></i>' +
            '<span id="agentBellBadge" style="display:none;position:absolute;top:-4px;right:-4px;background:var(--danger);color:#fff;font-size:.6rem;font-weight:700;border-radius:10px;padding:1px 5px;"></span>';
        btn.addEventListener('click', function () {
            location.href = btn.dataset.conv ? '/admin/agent.php?conv=' + btn.dataset.conv : '/admin/agent.php';
        });
        actions.insertBefore(btn, actions.firstChild);
    }

    async function pollNotifications() {
        ensureBell();
        try {
            const resp = await fetch('/admin/api/agent.php?action=notifications');
            const data = await resp.json();
            const badge = document.getElementById('agentBellBadge');
            const bell = document.getElementById('agentBell');
            if (!badge || !bell) return;

            const count = data.count || 0;
            const working = data.working || 0;
            const pending = data.pending_confirmations || 0;

            // Icone: spinner enquanto a IA trabalha
            const icon = bell.querySelector('i');
            if (icon) {
                icon.className = working > 0 ? 'fas fa-spinner fa-spin' : 'fas fa-bell';
            }

            // Badge: respostas novas (vermelho) > confirmacao pendente (ambar)
            if (count > 0) {
                badge.textContent = count > 9 ? '9+' : count;
                badge.style.background = 'var(--danger)';
                badge.style.display = 'inline-block';
                bell.dataset.conv = (data.items && data.items[0]) ? data.items[0].conversation_id : '';
            } else if (pending > 0) {
                badge.textContent = '!';
                badge.style.background = 'var(--warning)';
                badge.style.display = 'inline-block';
                bell.dataset.conv = data.pending_conversation_id || '';
            } else {
                badge.style.display = 'none';
                bell.dataset.conv = '';
            }

            bell.title = working > 0
                ? 'Socio de IA trabalhando...'
                : (pending > 0
                    ? 'O Socio aguarda sua confirmacao'
                    : (count > 0 ? count + ' resposta(s) nova(s)' : 'Notificacoes do Socio de IA'));

            if (initialized && count > lastCount && data.items && data.items.length) {
                const item = data.items[0];
                const who = item.subagent_name ? item.subagent_name : 'Socio de IA';
                if (typeof showToast === 'function') {
                    showToast(who + ' respondeu: ' + item.preview, 'info');
                }
            }
            lastCount = count;
            initialized = true;
        } catch (e) {}
    }

    setTimeout(pollNotifications, 1500);
    setInterval(pollNotifications, 15000);
})();
