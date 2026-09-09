let cmEditor = null;
let dirty = false;

document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.EDITOR_INIT || {};
    const textarea = document.getElementById('codeEditor');

    cmEditor = CodeMirror.fromTextArea(textarea, {
        lineNumbers: true,
        matchBrackets: true,
        autoCloseBrackets: true,
        mode: 'htmlmixed',
        theme: cfg.cmTheme || 'default',
        lineWrapping: true,
        tabSize: 2,
        indentUnit: 2
    });

    window.editorStatus('Carregando...');

    fetch('/admin/api/editor.php?action=get&id=' + cfg.pageId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                cmEditor.setValue(data.page.html || '');
                window.editorStatus('Pronto — ' + (data.page.html.length / 1024).toFixed(1) + ' KB');
                renderRevisions(data.revisions || []);
            } else {
                window.editorStatus('Erro: ' + (data.error || 'desconhecido'));
            }
        })
        .catch(err => window.editorStatus('Erro de conexão'));

    cmEditor.on('change', () => {
        dirty = true;
        window.editorStatus('Salvar para aplicar (Ctrl+S)');
    });

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveHtml();
        }
    });

    window.editorStatus = function (msg) {
        const el = document.getElementById('editorStatus');
        if (el) el.textContent = msg;
    };
});

function saveHtml() {
    const cfg = window.EDITOR_INIT || {};
    const btn = document.querySelector('.editor-actions .btn-success');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    window.editorStatus('Salvando...');

    const body = new URLSearchParams();
    body.append('action', 'save');
    body.append('id', cfg.pageId);
    body.append('html', cmEditor.getValue());

    fetch('/admin/api/editor.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                dirty = false;
                window.editorStatus('Salvo às ' + new Date().toLocaleTimeString());
                showToast('Código salvo com sucesso!', 'success');
                iframeRefresh();
                loadRevisions();
            } else {
                window.editorStatus('Erro: ' + (data.error || 'desconhecido'));
                showToast(data.error || 'Erro ao salvar', 'error');
            }
        })
        .catch(err => {
            window.editorStatus('Erro de conexão');
            showToast('Erro de conexão: ' + err.message, 'error');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = original;
        });
}

function iframeRefresh() {
    const frame = document.getElementById('previewFrame');
    if (frame) {
        frame.src = frame.src.split('?')[0] + '?id=' + (window.EDITOR_INIT.pageId) + '&r=' + Date.now();
    }
}

function openPreview() {
    window.open('/admin/preview.php?id=' + window.EDITOR_INIT.pageId, '_blank');
}

function previewLoaded() {
    document.getElementById('previewFrame').contentWindow.focus();
}

function loadRevisions() {
    fetch('/admin/api/editor.php?action=get&id=' + window.EDITOR_INIT.pageId)
        .then(r => r.json())
        .then(data => renderRevisions(data.revisions || []));
}

function renderRevisions(revisions) {
    const list = document.getElementById('revisionList');
    list.innerHTML = '';
    revisions.slice(0, 15).forEach(rev => {
        const item = document.createElement('div');
        item.className = 'revision-item';
        item.textContent = rev.file;
        item.title = 'Restaurar ' + rev.date;
        item.addEventListener('click', () => confirmRestore(rev.file));
        list.appendChild(item);
    });
    list.style.display = 'block';
}

function toggleRevisions() {
    const list = document.getElementById('revisionList');
    list.style.display = list.style.display === 'none' ? 'block' : 'none';
    if (list.style.display === 'block') loadRevisions();
}

function confirmRestore(rev) {
    if (!confirm('Restaurar esta revisão? O código atual será enviado para histórico antes.')) return;
    fetch('/admin/api/editor.php?action=restore&id=' + window.EDITOR_INIT.pageId + '&rev=' + encodeURIComponent(rev))
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('Revisão restaurada!', 'success');
                setTimeout(() => {
                    loadRevisions();
                    fetch('/admin/api/editor.php?action=get&id=' + window.EDITOR_INIT.pageId)
                        .then(r => r.json())
                        .then(d => { if (d.success) cmEditor.setValue(d.page.html || ''); })
                        .then(() => iframeRefresh());
                }, 800);
            } else {
                showToast(data.error || 'Erro ao restaurar', 'error');
            }
        });
}

function restoreLast() {
    toggleRevisions();
}

function showToast(message, type = 'success') {
    let container = document.getElementById('toastContainer');
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    toast.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle') + '"></i> ' + message;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}
