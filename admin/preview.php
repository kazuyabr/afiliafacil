<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/AssetProcessor.php';

Auth::requireAuth();

$pm = new PageManager();
$id = (int)($_GET['id'] ?? 0);
$page = $pm->get($id);

if (!$page || empty($page['html'])) {
    http_response_code(404);
    die('Página não encontrada');
}

if (!Auth::canAccessPage($page)) {
    http_response_code(403);
    die('Sem acesso a esta página');
}

$sourceDomain = $page['source_domain'] ?? '';
$html = AssetProcessor::rewriteForPreview($page['html'], $sourceDomain);

$isInspector = !empty($_GET['inspector']);
if ($isInspector) {
    $inspectorScript = <<<'JS'
<script data-af-inspector>
(function () {
    'use strict';
    var interactive = false;
    var hoverEl = null;
    var selectedEl = null;

    function uniqueSelector(el) {
        if (el.id && /^[\w-]+$/.test(el.id)) return '#' + el.id;
        var parts = [];
        var node = el;
        while (node && node.nodeType === 1 && node !== document.documentElement && parts.length < 6) {
            var tag = node.tagName.toLowerCase();
            var parent = node.parentElement;
            var siblingIndex = 1;
            for (var sib = node; sib; sib = sib.previousElementSibling) {
                if (sib.tagName.toLowerCase() === tag) siblingIndex++;
            }
            var part = tag + ':nth-child(' + siblingIndex + ')';
            parts.unshift(part);
            if (parent && parent.id && /^[\w-]+$/.test(parent.id)) {
                parts.unshift('#' + parent.id);
                break;
            }
            node = parent;
        }
        return parts.join('>');
    }

    function outline(el, color, width) {
        clearOutline();
        if (!el) return;
        el.style.outline = width + 'px solid ' + color;
        el.style.outlineOffset = '-2px';
    }

    function clearOutline() {
        if (hoverEl) hoverEl.style.outline = '';
        if (selectedEl) selectedEl.style.outline = '';
        hoverEl = null;
        selectedEl = null;
    }

    function send(data) {
        parent.postMessage(data, '*');
    }

    document.addEventListener('mouseover', function (e) {
        if (interactive) return;
        var el = e.target;
        if (el === document.body || el === document.documentElement) return;
        if (hoverEl && hoverEl !== el) hoverEl.style.outline = '';
        hoverEl = el;
        el.style.outline = '1px solid rgba(13,110,253,.45)';
        el.style.outlineOffset = '-2px';
    }, true);

    document.addEventListener('mouseout', function (e) {
        if (e.target === hoverEl && hoverEl !== selectedEl) {
            hoverEl.style.outline = '';
            hoverEl = null;
        }
    }, true);

    document.addEventListener('click', function (e) {
        if (interactive) return;
        var raw = e;
        if (e.ctrlKey || e.metaKey) return;

        e.preventDefault();
        e.stopPropagation();
        e.stopImmediatePropagation();

        var el = e.target;
        while (el && el.nodeType === 1 && el.parentElement && (!el.tagName || el.tagName === 'HTML')) {
            el = el.parentElement;
        }
        if (!el || el === document.body || el === document.documentElement) return;

        if (hoverEl) hoverEl.style.outline = '';

        var snippet = el.outerHTML || '';
        var tagSnippet = '';
        if (snippet) {
            var closeIdx = snippet.indexOf('>');
            tagSnippet = closeIdx >= 0 ? snippet.slice(0, closeIdx + 1) : snippet;
            if (tagSnippet.length > 1500) tagSnippet = tagSnippet.slice(0, 1500);
            if (snippet.length > 3000) snippet = snippet.slice(0, 3000);
        }

        if (selectedEl) selectedEl.style.outline = '';
        selectedEl = el;
        outline(el, 'rgba(13,110,253,.9)', 2);

        send({
            type: 'af-inspect',
            selector: uniqueSelector(el),
            tag: el.tagName ? el.tagName.toLowerCase() : '',
            text: (el.innerText || '').slice(0, 200),
            src: el.getAttribute ? (el.getAttribute('src') || '') : '',
            href: el.getAttribute ? (el.getAttribute('href') || '') : '',
            snippet: snippet,
            tagSnippet: tagSnippet
        });
    }, true);

    window.addEventListener('message', function (e) {
        var d = e.data;
        if (!d || typeof d !== 'object') return;
        if (d.type === 'af-set-mode') {
            interactive = !!d.interactive;
            clearOutline();
        }
    });
})();
</script>
JS;
    $html = str_replace('</body>', $inspectorScript . "\n</body>", $html);
}

header('Content-Type: text/html; charset=UTF-8');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-Content-Type-Options: nosniff');
echo $html;
