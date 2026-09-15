<?php
require_once __DIR__ . '/lib/Config.php';
require_once Config::getLibDir() . '/Settings.php';

$companyEmail = Settings::get('company_email', 'contato@afiliafacil.com');

$legalTitle = 'Política de Cookies';
$legalActive = 'cookies';

ob_start();
?>
<p>Esta Política explica o que são cookies, quais utilizamos e como você pode gerenciá-los.</p>

<h2>1. O que são cookies</h2>
<p>Cookies são pequenos arquivos armazenados no seu navegador que permitem reconhecer sua sessão e lembrar preferências. Também usamos armazenamento local (localStorage) para preferências de interface.</p>

<h2>2. Cookies que utilizamos</h2>
<table>
    <tr><th>Nome/Tipo</th><th>Finalidade</th><th>Categoria</th><th>Duração</th></tr>
    <tr><td>PHPSESSID</td><td>Manter sua sessão autenticada</td><td>Estritamente necessário</td><td>Sessão</td></tr>
    <tr><td>localStorage (theme)</td><td>Lembrar tema claro/escuro e preferências do editor</td><td>Funcional</td><td>Persistente (navegador)</td></tr>
    <tr><td>localStorage (af_*)</td><td>Preferências de uso (ex.: filtros salvos)</td><td>Funcional</td><td>Persistente (navegador)</td></tr>
</table>
<p>Não utilizamos cookies de publicidade nem rastreadores de terceiros para perfilamento.</p>

<h2>3. Cookies de terceiros</h2>
<p>Algumas páginas carregam recursos de terceiros (ex.: Font Awesome, Google Fonts, CDN do editor). Esses provedores podem registrar sua visita conforme as políticas próprias deles. Páginas clonadas de terceiros podem conter cookies dos sites de origem — nesses casos, a responsabilidade é de quem publica a página.</p>

<h2>4. Como gerenciar</h2>
<ul>
    <li>Você pode bloquear ou apagar cookies nas configurações do seu navegador.</li>
    <li>Cookies estritamente necessários são indispensáveis para o login — sem eles, a plataforma não funciona.</li>
    <li>Para limpar preferências locais, apague os dados do site no navegador.</li>
</ul>

<h2>5. Contato</h2>
<p>Dúvidas: <a href="mailto:<?= htmlspecialchars($companyEmail) ?>"><?= htmlspecialchars($companyEmail) ?></a>.</p>

<div class="alert alert-warning" style="margin-top:28px;">
    <i class="fas fa-triangle-exclamation"></i> <strong>Aviso:</strong> este documento é um modelo e deve ser revisado por profissional jurídico antes da publicação.
</div>
<?php
$legalContent = ob_get_clean();
include __DIR__ . '/partials/legal.php';
