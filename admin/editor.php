<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/Plans.php';

Auth::requireAuth();

$user = Auth::user();
if (!Plans::hasFeature($user['plan'], 'editor')) {
    header('Location: /admin/plan.php?upgrade=1');
    exit;
}

$pm = new PageManager();
$id = (int)($_GET['id'] ?? 0);
$page = $pm->get($id);
if (!$page) {
    header('Location: /admin/pages.php');
    exit;
}
if (!Auth::canAccessPage($page)) {
    http_response_code(403);
    die('Sem acesso a esta página');
}

$theme = $_SESSION['theme'] ?? 'light';
$cmTheme = $theme === 'dark' ? 'material-darker' : 'default';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor Online — <?= htmlspecialchars($page['name']) ?> - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/editor.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/foldgutter.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/dialog/dialog.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/default.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/material-darker.min.css">
</head>
<body>
    <div class="editor-shell">
        <header class="editor-header">
            <div class="editor-title">
                <div class="logo"><i class="fas fa-code"></i></div>
                <div>
                    <strong><?= htmlspecialchars($page['name']) ?></strong>
                    <small id="editorStatus">Pronto</small>
                </div>
            </div>
            <div class="editor-actions">
                <button class="btn btn-sm btn-outline theme-toggle" onclick="toggleEditorTheme()" id="themeToggleBtn" title="Alternar tema claro/escuro">
                    <i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i>
                </button>
                <button class="btn btn-sm btn-outline" onclick="toggleInteract()" id="interactBtn" title="Clique normal = inspector · CTRL+Click = interage. Ative para interagir sempre."><i class="fas fa-crosshairs"></i> Interagir</button>
                <button class="btn btn-sm btn-outline" onclick="iframeRefresh()" title="Recarregar preview"><i class="fas fa-sync"></i></button>
                <button class="btn btn-sm btn-outline" onclick="openPreview()" title="Abrir preview em nova aba"><i class="fas fa-external-link-alt"></i></button>
                <button class="btn btn-sm btn-outline" onclick="toggleRevisions()" title="Restaurar última revisão"><i class="fas fa-history"></i> Revisões</button>
                <a href="/admin/pages.php?action=edit&id=<?= $id ?>" class="btn btn-sm btn-outline" title="Voltar ao formulário"><i class="fas fa-arrow-left"></i> Voltar</a>
                <button class="btn btn-sm btn-success" onclick="saveHtml()" title="Salvar código (Ctrl+S)"><i class="fas fa-save"></i> Salvar</button>
            </div>
        </header>

        <div class="editor-body">
            <aside class="editor-sidebar">
                <div class="sidebar-file active">
                    <i class="fab fa-html5" style="color:#e34c26;"></i>
                    <span>index.html</span>
                </div>
                <div class="sidebar-file disabled" title="Edite o HTML para embutir um <style> ou <script> customizado no arquivo principal">
                    <i class="fab fa-css3-alt" style="color:#1572b6;"></i>
                    <span>custom.css (via HTML)</span>
                </div>

                <div class="sidebar-sep"></div>
                <div id="elementPanel" class="element-panel" style="display:none;"></div>

                <div class="sidebar-sep"></div>
                <button class="btn btn-sm btn-outline btn-full" onclick="toggleRevisions()"><i class="fas fa-history"></i> Revisões</button>
                <div id="revisionList" class="revision-list" style="display:none;"></div>
            </aside>

            <div class="editor-panes">
                <div class="editor-code">
                    <textarea id="codeEditor"></textarea>
                </div>
                <div class="editor-preview">
                    <div class="preview-bar"><span>Preview &middot; clique = <strong>inspector</strong> &middot; CTRL+Click = interagir</span></div>
                    <iframe id="previewFrame" src="/admin/preview.php?id=<?= $id ?>&inspector=1" sandbox="allow-scripts allow-same-origin allow-popups" onload="previewLoaded()"></iframe>
                </div>
            </div>
        </div>
    </div>

    <div id="toastContainer" class="toast-container"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/closebrackets.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/foldcode.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/foldgutter.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/xml-fold.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/fold/brace-fold.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/dialog/dialog.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/search/searchcursor.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/search/search.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/search/jump-to-line.min.js"></script>
    <script src="/assets/js/editor.js"></script>
    <script>
        window.EDITOR_INIT = {
            pageId: <?= $id ?>,
            cmTheme: '<?= $cmTheme ?>',
            savedHtmlSize: <?= strlen($page['html'] ?? '') ?>
        };
    </script>
</body>
</html>
