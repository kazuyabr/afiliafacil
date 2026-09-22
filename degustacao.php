<?php
require_once __DIR__ . '/lib/Config.php';
require_once Config::getLibDir() . '/Settings.php';
require_once Config::getLibDir() . '/Plans.php';

$companyEmail = Settings::get('company_email', 'contato@afiliafacil.com');
$trialDays = (int)Settings::get('trial_days', 3);

$legalTitle = 'Aviso da Degustação (plano gratuito)';
$legalActive = 'degustacao';

$plans = Plans::all();
$order = ['trial', 'vsl', 'essencial', 'master'];
$quotaLabels = [
    'max_pages' => 'Páginas clonadas',
    'max_adspy_searches' => 'Buscas de anúncios/mês',
    'max_ai_analyses' => 'Análises IA/mês',
    'max_offers_views' => 'Ofertas vistas/mês',
    'max_transcriptions' => 'Transcrições/mês',
    'max_tts' => 'Narrações/mês',
    'max_agent_messages' => 'Mensagens do Sócio de IA/mês',
];
$quotaKeys = ['max_pages', 'max_adspy_searches', 'max_ai_analyses', 'max_offers_views', 'max_transcriptions', 'max_tts', 'max_agent_messages'];

$formatQuota = function ($value) {
    if ($value === -1) return 'ilimitado';
    if ($value === 0) return '—';
    return (string)$value;
};

ob_start();
?>
<p>A degustação é uma oportunidade para você conhecer a plataforma <strong>sem compromisso e sem cartão de crédito</strong>. Para usá-la bem, é importante entender exatamente <strong>o que está incluído, quais são os limites e o que não faz parte</strong> do plano gratuito.</p>

<h2>1. Duração</h2>
<p>O plano gratuito dura <strong><?= $trialDays ?> dias</strong> a partir da criação da conta. Ao final, os recursos de IA e de criação ficam bloqueados até a contratação de um plano pago (seus dados permanecem salvos).</p>

<h2>2. Limites da degustação</h2>
<table>
    <tr>
        <th>Recurso</th>
        <?php foreach ($order as $planId): ?>
            <?php if (isset($plans[$planId])): ?><th><?= htmlspecialchars($plans[$planId]['name']) ?></th><?php endif; ?>
        <?php endforeach; ?>
    </tr>
    <?php foreach ($quotaKeys as $key): ?>
    <tr>
        <td><?= htmlspecialchars($quotaLabels[$key]) ?></td>
        <?php foreach ($order as $planId): ?>
            <?php if (isset($plans[$planId])): ?><td><?= htmlspecialchars($formatQuota((int)($plans[$planId][$key] ?? 0))) ?></td><?php endif; ?>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
</table>
<p style="font-size:.8rem;color:var(--text-secondary);">Valores vigentes na data desta página. Recursos e limites podem ser ajustados; a tabela atualizada está sempre em <a href="/#plans">Planos</a>.</p>

<h2>3. O que NÃO está incluído na degustação</h2>
<ul>
    <li>Editor de código online e recursos exclusivos dos planos pagos;</li>
    <li>Domínios próprios (quantidade conforme o plano contratado);</li>
    <li>Volume profissional de IA (análises, transcrições, narrações e mensagens do Sócio de IA);</li>
    <li>Garantia de disponibilidade ou suporte prioritário.</li>
</ul>
<p><strong>Dica:</strong> quando a cota da plataforma acabar, você pode continuar usando os recursos de IA configurando a <strong>sua própria chave</strong> (BYOK) — o limite passa a ser o do seu provedor, sem consumir a cota do plano.</p>

<h2>4. Regras de uso (valem para todos os planos)</h2>
<ul>
    <li><strong>Uso lícito obrigatório:</strong> é proibido usar a plataforma para golpes, fraudes, conteúdo ilegal ou enganoso;</li>
    <li><strong>Moderação ativa:</strong> conteúdos ilícitos são bloqueados e registrados (conteúdo, data, hora e IP), podendo ser entregues às autoridades;</li>
    <li><strong>Proibições:</strong> prometer ganhos garantidos, usar dados sensíveis de terceiros, violar direitos autorais ou burlar limites;</li>
    <li><strong>Dados para melhoria (opcional):</strong> com sua autorização expressa, dados anonimizados podem melhorar a IA da plataforma — você pode revogar quando quiser.</li>
</ul>

<h2>5. O que esperar (e o que não esperar)</h2>
<ul>
    <li>A plataforma <strong>não garante resultados</strong> de vendas, conversões ou lucro;</li>
    <li>Tráfego é teste: comece pequeno, meça e escale com dados;</li>
    <li>O Sócio de IA foi desenhado para orientar e proteger você de más escolhas — ele pergunta antes de agir e nunca promete ganhos fáceis.</li>
</ul>

<h2>6. Depois da degustação</h2>
<p>Você pode contratar um plano a qualquer momento em <a href="/admin/plan.php">Meu Plano</a> (PIX ou cartão). Seus dados e páginas continuam disponíveis.</p>

<h2>7. Contato</h2>
<p>Dúvidas sobre a degustação: <a href="mailto:<?= htmlspecialchars($companyEmail) ?>"><?= htmlspecialchars($companyEmail) ?></a>.</p>

<div class="alert alert-warning" style="margin-top:28px;">
    <i class="fas fa-triangle-exclamation"></i> <strong>Aviso:</strong> este documento é um modelo e deve ser revisado por profissional jurídico antes da publicação.
</div>
<?php
$legalContent = ob_get_clean();
include __DIR__ . '/partials/legal.php';
