<?php
require_once __DIR__ . '/lib/Config.php';
require_once Config::getLibDir() . '/Settings.php';

$companyName = Settings::get('company_name', 'AfiliaFacil');
$companyEmail = Settings::get('company_email', 'contato@afiliafacil.com');
$trialDays = (int)Settings::get('trial_days', 3);

$legalTitle = 'Termos de Uso';
$legalActive = 'termos';

ob_start();
?>
<p>Bem-vindo à <?= htmlspecialchars($companyName) ?>. Estes Termos de Uso regulam o acesso e uso da plataforma. Ao criar uma conta ou utilizar qualquer funcionalidade, você declara ter lido, entendido e aceito integralmente estes Termos e a <a href="/privacidade">Política de Privacidade</a>.</p>

<h2>1. Descrição do serviço</h2>
<p>A plataforma oferece ferramentas para produtores e afiliados, incluindo: clonagem de páginas de vendas, editor de código, pressel, player de vídeo, pixel, back redirect, cookie, integrações, espionagem de anúncios em bibliotecas públicas, módulo de ofertas ("Ofertas Escalando"), transcrição de áudio/vídeo (STT), narração (TTS) e o agente de IA "Sócio de IA".</p>

<h2>2. Cadastro e conta</h2>
<ul>
    <li>Você é responsável pela veracidade dos dados informados e pela segurança da sua senha.</li>
    <li>É proibido compartilhar a conta ou utilizá-la para atividades ilícitas.</li>
    <li>Podemos suspender ou encerrar contas que violem estes Termos.</li>
</ul>

<h2>3. Período de degustação</h2>
<p>O plano gratuito ("degustação") tem duração de <strong><?= $trialDays ?> dias</strong> e limites descritos na página <a href="/degustacao">Aviso da Degustação</a>. A degustação é oferecida "como está", sem garantia de resultados, e pode ser alterada ou encerrada a qualquer momento.</p>

<h2>4. Planos pagos e pagamentos</h2>
<ul>
    <li>Os planos pagos concedem os recursos e limites descritos na página de planos, conforme o ciclo contratado.</li>
    <li>Pagamentos via PIX (aprovação manual) ou cartão/Stripe (aprovação automática).</li>
    <li>Não há reembolso por períodos já utilizados, salvo disposição legal em contrário.</li>
</ul>

<h2>5. Uso aceitável (tolerância zero a abuso)</h2>
<p>É expressamente proibido usar a plataforma para:</p>
<ul>
    <li>Qualquer atividade ilícita, incluindo, sem limitação: golpes, estelionato, fraudes, roubo, tráfico, ameaças, assédio, pornografia infantil, lavagem de dinheiro ou violação de direitos de terceiros;</li>
    <li>Promover produtos ou serviços ilegais ou enganosos, incluindo promessas de saúde milagrosas ou ganhos garantidos;</li>
    <li>Violar direitos autorais, marcas ou clonar páginas que você não tenha direito de usar;</li>
    <li>Tentar burlar limites, cotas, mecanismos de segurança ou de moderação.</li>
</ul>
<p><strong>Moderação e registros:</strong> a plataforma possui moderação automatizada que bloqueia conteúdos ilícitos e registra tentativas de uso indevido (conteúdo, data, hora e endereço IP). Esses registros podem ser preservados e entregues às autoridades competentes mediante requisição legal, nos termos da legislação aplicável.</p>

<h2>6. Conteúdo e propriedade intelectual</h2>
<ul>
    <li>Você é o único responsável pelo conteúdo que clona, edita, hospeda ou distribui, incluindo a legalidade e os direitos de uso.</li>
    <li>A plataforma e seus componentes (código, marca, layout) pertencem à <?= htmlspecialchars($companyName) ?> e não podem ser copiados sem autorização.</li>
</ul>

<h2>7. Inteligência artificial e dados de melhoria</h2>
<ul>
    <li>Os recursos de IA podem ser usados via infraestrutura da plataforma (com limites por plano) ou com sua própria chave de API (BYOK), cujo custo e limites são de responsabilidade do provedor contratado por você.</li>
    <li>Quando você autoriza expressamente, dados <strong>anonimizados</strong> das interações podem ser usados para melhorar os modelos da plataforma, conforme a <a href="/privacidade">Política de Privacidade</a>. Você pode revogar essa autorização a qualquer momento nas configurações.</li>
    <li>As respostas da IA são geradas automaticamente e podem conter imprecisões. Elas não constituem aconselhamento financeiro, jurídico ou garantia de resultados.</li>
</ul>

<h2>8. Limitação de responsabilidade</h2>
<ul>
    <li>A plataforma não garante resultados financeiros, conversões, lucros ou desempenho de campanhas.</li>
    <li>Não nos responsabilizamos por bloqueios, custos ou políticas de plataformas de terceiros (Meta, Google, TikTok, gateways de pagamento, provedores de IA etc.).</li>
    <li>Na máxima extensão permitida em lei, nossa responsabilidade limita-se ao valor pago pelo serviço nos últimos 12 meses.</li>
</ul>

<h2>9. Cancelamento e suspensão</h2>
<p>Você pode cancelar a renovação a qualquer momento. Podemos suspender o acesso em caso de violação destes Termos, com ou sem aviso prévio, especialmente em casos de suspeita de ilícito.</p>

<h2>10. Alterações destes Termos</h2>
<p>Podemos atualizar estes Termos periodicamente. A versão vigente estará sempre disponível nesta página, com a data da última atualização. O uso continuado após alterações implica concordância.</p>

<h2>11. Legislação e foro</h2>
<p>Estes Termos são regidos pelas leis brasileiras. Fica eleito o foro do domicílio da <?= htmlspecialchars($companyName) ?> para dirimir eventuais conflitos, salvo disposição legal em contrário aplicável ao consumidor.</p>

<h2>12. Contato</h2>
<p>Dúvidas sobre estes Termos: <a href="mailto:<?= htmlspecialchars($companyEmail) ?>"><?= htmlspecialchars($companyEmail) ?></a>.</p>

<div class="alert alert-warning" style="margin-top:28px;">
    <i class="fas fa-triangle-exclamation"></i> <strong>Aviso:</strong> este documento é um modelo gerado automaticamente e não constitui aconselhamento jurídico. Recomenda-se revisão por profissional de direito antes da publicação.
</div>
<?php
$legalContent = ob_get_clean();
include __DIR__ . '/partials/legal.php';
