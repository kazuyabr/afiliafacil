let cmEditor = null;
let dirty = false;
let lastInspectInfo = null;
let pageMeta = { affiliateLink: '', sourceDomain: '' };

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
        indentUnit: 2,
        foldGutter: true,
        gutters: ['CodeMirror-foldgutter', 'CodeMirror-linenumbers'],
        foldOptions: { scanUp: false, hint: CodeMirror.fold.auto },
        extraKeys: {
            'Ctrl-F': 'findPersistent',
            'Cmd-F': 'findPersistent',
            'Ctrl-G': 'findNext',
            'Cmd-G': 'findNext',
            'Shift-Ctrl-G': 'findPrev',
            'Shift-Cmd-G': 'findPrev',
            'Ctrl-H': 'replace',
            'Cmd-Alt-F': 'replace',
            'Shift-Ctrl-F': 'replace',
            'Alt-G': 'jumpToLine'
        }
    });

    editorStatus('Carregando...');

    applyThemeFromStorage();

    fetch('/admin/api/editor.php?action=get&id=' + cfg.pageId)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                cmEditor.setValue(data.page.html || '');
                pageMeta = {
                    affiliateLink: data.page.affiliate_link || '',
                    sourceDomain: data.page.source_domain || ''
                };
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

/**
 * Localiza o elemento inspecionado no código (cascata: tag exata -> snippet ->
 * atributo único -> texto). Retorna {start, end} (offsets) ou null.
 */
function locateInCode(html, info, fullOnly) {
    let found = null;

    if (!fullOnly && info.tagSnippet && info.tagSnippet.length < 1500) {
        found = findInCode(html, info.tagSnippet);
    }
    if (!found && info.snippet && info.snippet.length < 3000) {
        found = findInCode(html, info.snippet);
    }
    if (!fullOnly && !found && info.tagSnippet) {
        found = findUniqueAttrAnchor(html, info.tagSnippet);
    }
    if (!found && info.snippet) {
        found = findUniqueAttrAnchor(html, info.snippet);
    }
    if (!fullOnly && !found && info.text) {
        found = findTextAnchor(html, info.text);
    }
    return found;
}

function posFromOffset(html, offset) {
    const line = countLinesBefore(html, offset);
    const lineStart = line > 0 ? html.lastIndexOf('\n', offset - 1) + 1 : 0;
    return { line: line, ch: offset - lineStart };
}

function selectElementInCode(info) {
    lastInspectInfo = info;
    const html = cmEditor.getValue();
    const found = locateInCode(html, info, false);

    if (!found) {
        editorStatus('Elemento não localizado no código — salve e recarregue');
        updateElementPanel(info, false);
        return;
    }

    const from = posFromOffset(html, found.start);
    const to = posFromOffset(html, found.end);

    cmEditor.setSelection(from, to);
    cmEditor.scrollIntoView(from, to);
    cmEditor.focus();

    updateElementPanel(info, true);
    editorStatus('Selecionado: ' + (info.selector || info.tag || 'elemento'));
}

function findInCode(html, needle) {
    if (!needle || needle.length < 4) return null;
    const idx = html.indexOf(needle);
    if (idx < 0) return null;
    return { start: idx, end: idx + needle.length };
}

function findUniqueAttrAnchor(html, snippet) {
    let m;

    m = /id\s*=\s*["']([^"']+)["']/.exec(snippet);
    if (m) {
        const re = new RegExp('\\sid\\s*=\\s*["\']' + escapeRegExp(m[1]) + '["\']', 'gi');
        const matches = [...html.matchAll(re)];
        if (matches.length === 1) return expandToTagOpen(html, matches[0].index);
    }

    m = /class\s*=\s*["']([^"']+)["']/.exec(snippet);
    if (m) {
        const re = new RegExp('class\\s*=\\s*["\']' + escapeRegExp(m[1]) + '["\']', 'gi');
        const matches = [...html.matchAll(re)];
        if (matches.length === 1) return expandToTagOpen(html, matches[0].index);
    }

    m = /src\s*=\s*["']([^"']+)["']/.exec(snippet);
    if (m) {
        const re = new RegExp('src\\s*=\\s*["\']' + escapeRegExp(m[1]) + '["\']', 'gi');
        const matches = [...html.matchAll(re)];
        if (matches.length === 1) return expandToTagOpen(html, matches[0].index);
    }

    return null;
}

function findTextAnchor(html, text) {
    const needle = text.slice(0, 40).trim();
    if (!needle) return null;
    const idx = html.indexOf(needle);
    if (idx < 0) return null;
    return expandToTagOpen(html, idx);
}

function expandToTagOpen(html, pos) {
    const start = html.lastIndexOf('<', pos);
    if (start < 0) return null;
    let end = html.indexOf('>', pos);
    if (end < 0) end = start + 1;
    end = end + 1;
    if (end - start > 300) {
        const nl = html.indexOf('\n', start);
        const newlineBound = nl > 0 && nl - start < 300 ? nl : start;
        end = Math.max(start + 1, newlineBound > start ? newlineBound : start + 300);
    }
    return { start: start, end: end };
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

    if (located) renderVisualForm(panel, info);
}

const VISUAL_NO_TEXT_TAGS = ['img', 'input', 'video', 'iframe', 'source', 'br', 'hr', 'script', 'style'];

function visualKinds(info) {
    const tag = (info.tag || '').toLowerCase();
    return {
        text: !!info.text && !VISUAL_NO_TEXT_TAGS.includes(tag),
        src: !!info.src || tag === 'img',
        href: !!info.href || tag === 'a'
    };
}

function renderVisualForm(panel, info) {
    const kinds = visualKinds(info);
    if (!kinds.text && !kinds.src && !kinds.href) return;

    const wrap = document.createElement('div');
    wrap.className = 'element-edit';
    wrap.style.marginTop = '10px';
    wrap.style.display = 'flex';
    wrap.style.flexDirection = 'column';
    wrap.style.gap = '8px';

    const title = document.createElement('div');
    title.style.cssText = 'font-size:.72rem;font-weight:700;text-transform:uppercase;color:var(--text-secondary);';
    title.textContent = 'Editar sem ver código';
    wrap.appendChild(title);

    function field(label, id, value, multiline) {
        const lab = document.createElement('label');
        lab.style.cssText = 'font-size:.72rem;color:var(--text-secondary);display:block;';
        lab.textContent = label;
        let input;
        if (multiline) {
            input = document.createElement('textarea');
            input.rows = 2;
            input.style.minHeight = '52px';
        } else {
            input = document.createElement('input');
            input.type = 'text';
        }
        input.id = id;
        input.className = 'form-control';
        input.style.fontSize = '.8rem';
        input.value = value || '';
        wrap.appendChild(lab);
        wrap.appendChild(input);
        return input;
    }

    if (kinds.text) field('Texto', 'elEditText', info.text, true);
    if (kinds.src) field('Imagem (URL)', 'elEditSrc', info.src, false);
    if (kinds.href) field('Link (URL)', 'elEditHref', info.href, false);

    const warn = document.createElement('div');
    warn.id = 'elEditWarn';
    warn.style.cssText = 'font-size:.75rem;color:var(--warning);display:none;';
    wrap.appendChild(warn);

    const row = document.createElement('div');
    row.style.display = 'flex';
    row.style.gap = '8px';
    row.style.flexWrap = 'wrap';

    const apply = document.createElement('button');
    apply.className = 'btn btn-sm btn-primary';
    apply.innerHTML = '<i class="fas fa-pen"></i> Aplicar no código';
    apply.onclick = applyVisualEdit;
    row.appendChild(apply);

    if (kinds.href && pageMeta.affiliateLink) {
        const aff = document.createElement('button');
        aff.className = 'btn btn-sm btn-outline';
        aff.title = 'Preencher com o link de afiliado da página';
        aff.innerHTML = '<i class="fas fa-link"></i> Meu link';
        aff.onclick = () => {
            const el = document.getElementById('elEditHref');
            if (el) el.value = pageMeta.affiliateLink;
        };
        row.appendChild(aff);
    }
    wrap.appendChild(row);

    const hint = document.createElement('div');
    hint.style.cssText = 'font-size:.7rem;color:var(--text-secondary);';
    hint.textContent = 'Aplica no código acima — depois salve (Ctrl+S), a revisão é criada sozinha.';
    wrap.appendChild(hint);

    panel.appendChild(wrap);
}

function elWarn(msg) {
    const el = document.getElementById('elEditWarn');
    if (el) {
        el.textContent = msg;
        el.style.display = msg ? 'block' : 'none';
    }
    if (msg) editorStatus(msg);
}

function hostOf(url) {
    try {
        return new URL(url, 'https://x.invalid').hostname.toLowerCase();
    } catch (e) {
        return '';
    }
}

/**
 * Aplica a edição visual direto no HTML do CodeMirror.
 * Texto exige o elemento COMPLETO localizado (snippet); src/href aceitam só a tag.
 */
function applyVisualEdit() {
    const info = lastInspectInfo;
    if (!info) { elWarn('Clique em um elemento no preview primeiro.'); return; }
    const tag = (info.tag || '').toLowerCase();
    const kinds = visualKinds(info);
    const html = cmEditor.getValue();

    const wantText = kinds.text ? document.getElementById('elEditText').value : null;
    const wantSrc = kinds.src ? document.getElementById('elEditSrc').value.trim() : null;
    const wantHref = kinds.href ? document.getElementById('elEditHref').value.trim() : null;

    const changedText = wantText !== null && wantText !== (info.text || '');
    const changedSrc = wantSrc !== null && wantSrc !== (info.src || '');
    const changedHref = wantHref !== null && wantHref !== (info.href || '');
    if (!changedText && !changedSrc && !changedHref) { elWarn('Nada mudou.'); return; }

    // Localiza: snippet completo quando há edição de texto; tag basta p/ src/href
    let found = locateInCode(html, info, changedText);
    if (!found) { elWarn('Elemento com estrutura complexa — edite no código.'); return; }
    const elHtml = html.slice(found.start, found.end);
    const tagEnd = elHtml.indexOf('>');
    if (tagEnd < 0) { elWarn('Tag inválida — edite no código.'); return; }
    let opening = elHtml.slice(0, tagEnd + 1);
    let rest = elHtml.slice(tagEnd + 1);

    const warnings = [];

    if (changedText) {
        if (rest === '' || rest.indexOf('</' + tag + '>') < 0) {
            // Range cobre só a tag de abertura: expande até o fechamento.
            // (Tags iguais aninhadas podem confundir — o guard de filhos protege.)
            const closeTag = '</' + tag + '>';
            const closeIdx = html.indexOf(closeTag, found.start);
            if (closeIdx < 0) { elWarn('Sem conteúdo de texto aqui — edite no código.'); return; }
            found = { start: found.start, end: closeIdx + closeTag.length };
            const expanded = html.slice(found.start, found.end);
            const te = expanded.indexOf('>');
            opening = expanded.slice(0, te + 1);
            rest = expanded.slice(te + 1);
        }
        const closeAt = rest.lastIndexOf('<');
        const inner = closeAt >= 0 ? rest.slice(0, closeAt) : rest;
        if (inner.includes('<')) { elWarn('Elemento com filhos — edite o texto no código.'); return; }
        if (wantText.trim() === '' && (tag === 'a' || tag === 'button')) {
            warnings.push('Botão/link sem texto não converte.');
        }
        rest = wantText + (closeAt >= 0 ? rest.slice(closeAt) : '');
    }

    function setAttr(openingTag, attr, value) {
        const re = new RegExp('(\\s' + attr + '\\s*=\\s*)(["\'])(.*?)\\2', 'i');
        if (re.test(openingTag)) {
            return openingTag.replace(re, (m, p1, p2) => p1 + p2 + value.replace(/\$/g, '$$$$') + p2);
        }
        return openingTag.replace(/>$/, ' ' + attr + '="' + value.replace(/"/g, '&quot;') + '">');
    }

    if (changedSrc) {
        if (wantSrc === '') warnings.push('Imagem sem URL não carrega.');
        opening = setAttr(opening, 'src', wantSrc);
    }
    if (changedHref) {
        if (tag !== 'a') {
            warnings.push('Só <a> tem href — para botões, edite o texto ou converta em link.');
        } else {
            if (wantHref === '') warnings.push('Link vazio não converte.');
            if (wantHref !== '' && pageMeta.sourceDomain !== '') {
                const h = hostOf(wantHref).replace(/^www\./, '');
                if (h !== '' && h === pageMeta.sourceDomain.replace(/^www\./, '').toLowerCase()) {
                    warnings.push('Aponta para o domínio original — use seu link de afiliado (botão Meu link).');
                }
            }
            opening = setAttr(opening, 'href', wantHref);
        }
    }

    const newElHtml = opening + rest;
    const from = posFromOffset(html, found.start);
    const to = posFromOffset(html, found.end);
    cmEditor.replaceRange(newElHtml, from, to);

    // Re-seleciona o trecho aplicado e atualiza o painel
    const newEnd = found.start + newElHtml.length;
    cmEditor.setSelection(from, posFromOffset(cmEditor.getValue(), newEnd));
    lastInspectInfo = Object.assign({}, info, {
        text: changedText ? wantText : info.text,
        src: changedSrc ? wantSrc : info.src,
        href: changedHref ? wantHref : info.href
    });
    updateElementPanel(lastInspectInfo, true);
    elWarn('');
    editorStatus(warnings.length ? 'Aplicado com avisos: ' + warnings.join(' ') : 'Aplicado no código — salve (Ctrl+S).');
    if (warnings.length) {
        const w = document.getElementById('elEditWarn');
        if (w) { w.textContent = warnings.join(' '); w.style.display = 'block'; }
    }
}

function toggleInteract() {
    const frame = document.getElementById('previewFrame');
    const btn = document.getElementById('interactBtn');
    const interactive = btn && btn.classList.contains('active');
    if (frame && frame.contentWindow) {
        frame.contentWindow.postMessage({ type: 'af-set-mode', interactive: !interactive }, '*');
    }
}

function applyThemeFromStorage() {
    const saved = localStorage.getItem('theme');
    if (!saved) return;

    const html = document.documentElement;
    const current = html.getAttribute('data-theme');
    html.setAttribute('data-theme', saved);

    if (cmEditor && saved !== current) {
        cmEditor.setOption('theme', saved === 'dark' ? 'material-darker' : 'default');
    }

    const btn = document.getElementById('themeToggleBtn');
    if (btn) {
        const icon = btn.querySelector('i');
        if (icon) icon.className = 'fas fa-' + (saved === 'dark' ? 'sun' : 'moon');
    }

    if (saved !== current) {
        fetch('/admin/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'theme=' + saved
        });
    }
}

function toggleEditorTheme() {
    const html = document.documentElement;
    const current = html.getAttribute('data-theme');
    const next = current === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', next);
    localStorage.setItem('theme', next);

    if (cmEditor) {
        cmEditor.setOption('theme', next === 'dark' ? 'material-darker' : 'default');
    }

    const btn = document.getElementById('themeToggleBtn');
    if (btn) {
        const icon = btn.querySelector('i');
        if (icon) icon.className = 'fas fa-' + (next === 'dark' ? 'sun' : 'moon');
    }

    fetch('/admin/settings.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'theme=' + next
    });

    editorStatus('Tema ' + (next === 'dark' ? 'escuro' : 'claro') + ' ativado');
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
