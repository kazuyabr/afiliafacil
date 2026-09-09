let cmEditor = null;
let dirty = false;

function editorStatus(msg) {
    const el = document.getElementById('editorStatus');
    if (el) el.textContent = msg;
}

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

    editorStatus('Carregando...');

    fetch('/admin/api/editor.php?action=get&id=' + cfg.pageId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                cmEditor.setValue(data.page.html || '');
                editorStatus('Pronto — ' + (data.page.html.length / 1024).toFixed(1) + ' KB');
                renderRevisions(data.revisions || []);
            } else {
                editorStatus('Erro: ' + (data.error || 'desconhecido'));
            }
        })
        .catch(err => editorStatus('Erro de conexão'));

    cmEditor.on('change', () => {
        dirty = true;
        editorStatus('Salvar para aplicar (Ctrl+S)');
    });

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveHtml();
        }
    });

    window.addEventListener('message', onInspectorMessage);
});

function onInspectorMessage(e) {
    const data = e.data;
    if (!data || typeof data !== 'object') return;
    if (data.type === 'af-inspect') {
        selectElementInCode(data);
    } else if (data.type === 'af-mode') {
        const btn = document.getElementById('interactBtn');
        if (btn) {
            btn.classList.toggle('active', data.interactive);
            btn.innerHTML = data.interactive
                ? '<i class="fas fa-hand-pointer"></i> Interagindo'
                : '<i class="fas fa-crosshairs"></i> Interagir';
        }
    }
}

function selectElementInCode(info) {
    const html = cmEditor.getValue();
    let found = null;

    if (info.snippet && info.snippet.length < 3000 && info.snippet.length > 0) {
        const idx = html.indexOf(info.snippet);
        if (idx >= 0) {
            found = { start: idx, end: idx + info.snippet.length };
        }
    }

    if (!found && info.selector) {
        found = locateBySelectorInCode(html, info.selector);
    }

    if (!found && info.tag) {
        const re = new RegExp('<\\s*' + escapeRegExp(info.tag) + '[^>]*>', 'i');
        const m = re.exec(html);
        if (m) found = { start: m.index, end: m.index + m[0].length };
    }

    if (!found) {
        editorStatus('Elemento não localizado no código — salve e recarregue');
        updateElementPanel(info, false);
        return;
    }

    const startLine = countLinesBefore(html, found.start);
    const endLine = countLinesBefore(html, found.end);
    const startCh = found.start - (startLine > 0 ? html.lastIndexOf('\n', found.start - 1) + 1 : 0);
    const endCh = found.end - (endLine > 0 ? html.lastIndexOf('\n', found.end - 1) + 1 : 0);

    cmEditor.setSelection({ line: startLine, ch: startCh }, { line: endLine, ch: endCh });
    cmEditor.scrollIntoView({ line: startLine, ch: startCh }, { line: endLine, ch: endCh });
    cmEditor.focus();

    updateElementPanel(info, true);
    editorStatus('Selecionado: ' + (info.selector || info.tag || 'elemento'));
}

function locateBySelectorInCode(html, selector) {
    if (selector.startsWith('#') && /^#[\w-]+$/.test(selector)) {
        const re = new RegExp('[^\\w-]*\\b(?:class|id)?[^>]*\\sclass?|\\sid=\\s*["\']' + escapeRegExp(selector.replace('#', '')) + '["\']', 'i');
        const m = re.exec(html);
        if (m) return { start: m.index, end: m.index + m[0].length };
    }
    if (selector.startsWith('.')) {
        const re = new RegExp('class="[^"]*\\b' + escapeRegExp(selector.replace('.', '')) + '\\b[^"]*"', 'i');
        const m = re.exec(html);
        if (m) return { start: m.index, end: m.index + m[0].length };
    }
    return null;
}

function countLinesBefore(text, pos) {
    return text.substring(0, pos).split('\n').length - 1;
}

function escapeRegExp(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function updateElementPanel(info, located) {
    const panel = document.getElementById('elementPanel');
    if (!panel) return;
    panel.style.display = 'block';
    panel.innerHTML = '';

    const meta = document.createElement('div');
    meta.className = 'element-meta';
    meta.innerHTML =
        '<span class="el-tag">' + (info.tag || '?') + '</span> ' +
        '<code class="el-selector">' + (info.selector || '—') + '</code>';
    panel.appendChild(meta);

    const rows = [];
    if (info.text) rows.push(['texto', info.text]);
    if (info.src) rows.push(['src', info.src]);
    if (info.href) rows.push(['href', info.href]);
    if (rows.length) {
        const list = document.createElement('div');
        list.className = 'element-attrs';
        rows.forEach(([k, v]) => {
            const row = document.createElement('div');
            row.className = 'element-attr-row';
            row.innerHTML = '<span class="k">' + k + '</span><span class="v">' + (v.length > 60 ? v.slice(0, 60) + '…' : v) + '</span>';
            list.appendChild(row);
        });
        panel.appendChild(list);
    }

    const status = document.createElement('div');
    status.className = 'element-status ' + (located ? 'ok' : 'warn');
    status.textContent = located ? '✔ Localizado no código' : '⚠ Não localizado no código';
    panel.appendChild(status);
}

function toggleInteract() {
    const frame = document.getElementById('previewFrame');
    const btn = document.getElementById('interactBtn');
    const interactive = btn && btn.classList.contains('active');
    if (frame && frame.contentWindow) {
        frame.contentWindow.postMessage({ type: 'af-set-mode', interactive: !interactive }, '*');
    }
}

function saveHtml() {
    const cfg = window.EDITOR_INIT || {};
    const btn = document.querySelector('.editor-actions .btn-success');
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    editorStatus('Salvando...');

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
                editorStatus('Salvo às ' + new Date().toLocaleTimeString());
                showToast('Código salvo com sucesso!', 'success');
                iframeRefresh();
                loadRevisions();
            } else {
                editorStatus('Erro: ' + (data.error || 'desconhecido'));
                showToast(data.error || 'Erro ao salvar', 'error');
            }
        })
        .catch(err => {
            editorStatus('Erro de conexão');
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
        frame.src = frame.src.split('?')[0] + '?id=' + (window.EDITOR_INIT.pageId) + '&inspector=1&r=' + Date.now();
    }
}

function openPreview() {
    window.open('/admin/preview.php?id=' + window.EDITOR_INIT.pageId, '_blank');
}

function previewLoaded() {
    const frame = document.getElementById('previewFrame');
    if (frame && frame.contentWindow) {
        const btn = document.getElementById('interactBtn');
        const interactive = btn && btn.classList.contains('active');
        frame.contentWindow.postMessage({ type: 'af-set-mode', interactive: !!interactive }, '*');
    }
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
