<?php
require_once __DIR__ . '/lib/Config.php';
require_once Config::getLibDir() . '/Settings.php';

$companyName = Settings::get('company_name', 'AfiliaFacil');
$companyEmail = Settings::get('company_email', 'contato@afiliafacil.com');
$companyDpo = Settings::get('company_dpo_email', $companyEmail);

$legalTitle = 'Política de Privacidade';
$legalActive = 'privacidade';

ob_start();
?>
<p>Esta Política descreve como a <?= htmlspecialchars($companyName) ?> coleta, usa, armazena e protege dados pessoais, em conformidade com a Lei Geral de Proteção de Dados (Lei nº 13.709/2018 — LGPD).</p>

<h2>1. Dados que coletamos</h2>
<ul>
    <li><strong>Cadastro:</strong> nome, e-mail e senha (armazenada com hash).</li>
    <li><strong>Uso da plataforma:</strong> páginas criadas/clonadas, domínios, configurações, histórico de transcrições, narrações, conversas com o Sócio de IA e ofertas consultadas.</li>
    <li><strong>Técnicos:</strong> endereço IP, data/hora de acesso, user agent e registros de segurança (incluindo tentativas de uso indevido).</li>
    <li><strong>Pagamentos:</strong> dados de transações processados por gateways (PIX/Stripe). Não armazenamos números completos de cartão.</li>
    <li><strong>Preferências de IA:</strong> quando você configura chaves próprias (BYOK), a chave é armazenada criptografada (AES-256-GCM) e usada apenas para executar suas solicitações.</li>
</ul>

<h2>2. Finalidades e bases legais</h2>
<table>
    <tr><th>Finalidade</th><th>Base legal (LGPD)</th></tr>
    <tr><td>Executar o serviço contratado (conta, ferramentas, suporte)</td><td>Execução de contrato (art. 7º, V)</td></tr>
    <tr><td>Segurança, prevenção a fraudes e moderação de conteúdo ilícito</td><td>Legítimo interesse e cumprimento de obrigação legal (art. 7º, II e IX)</td></tr>
    <tr><td>Melhoria dos modelos de IA com dados anonimizados</td><td>Consentimento (art. 7º, I) — revogável a qualquer momento</td></tr>
    <tr><td>Comunicações sobre a conta e o serviço</td><td>Execução de contrato</td></tr>
    <tr><td>Atendimento a requisições de autoridades</td><td>Cumprimento de obrigação legal/regulatória (art. 7º, II)</td></tr>
</table>

<h2>3. Dados sensíveis e moderação</h2>
<p>Não solicitamos dados sensíveis (saúde, biometria, origem racial, convicções etc.). A plataforma aplica <strong>redação automática</strong> de informações pessoais identificáveis (CPF, CNPJ, telefone, e-mail, cartão, CEP) nos fluxos de IA e, no uso para treinamento, remove PII e dados de saúde.</p>
<p>Conteúdos que indiquem ilícitos são bloqueados e registrados (conteúdo, data, hora e IP) e podem ser compartilhados com autoridades mediante requisição legal.</p>

<h2>4. Compartilhamento com terceiros</h2>
<ul>
    <li><strong>Provedores de infraestrutura:</strong> Cloudflare (Workers AI, R2), provedores de banco de dados e hospedagem.</li>
    <li><strong>Provedores de IA escolhidos por você:</strong> quando usa BYOK, sua chave e conteúdo são enviados diretamente ao provedor selecionado (OpenAI, Anthropic, Google, Groq, Deepgram, AssemblyAI, ElevenLabs etc.), conforme as políticas deles.</li>
    <li><strong>Gateways de pagamento:</strong> PIX e Stripe para processar transações.</li>
    <li><strong>Autoridades:</strong> mediante requisição legal.</li>
</ul>
<p>Não vendemos dados pessoais.</p>

<h2>5. Retenção</h2>
<ul>
    <li>Dados de conta: enquanto a conta existir; após exclusão, por até 6 meses para obrigações legais e defesa de direitos.</li>
    <li>Registros de moderação e segurança: até 5 anos, para atendimento a autoridades e prevenção a fraudes.</li>
    <li>Datasets de treinamento (quando autorizado): dados anonimizados, sem vínculo direto com você.</li>
</ul>

<h2>6. Seus direitos (LGPD)</h2>
<p>Você pode solicitar: confirmação de tratamento, acesso, correção, anonimização/bloqueio/eliminação de dados desnecessários, portabilidade, informação sobre compartilhamentos, e <strong>revogação do consentimento</strong> de uso para treinamento. Para exercer, escreva para <a href="mailto:<?= htmlspecialchars($companyDpo) ?>"><?= htmlspecialchars($companyDpo) ?></a>.</p>

<h2>7. Segurança</h2>
<ul>
    <li>Senhas com hash, chaves de API criptografadas (AES-256-GCM), banco com role de menor privilégio, rate limiting no login e auditoria de ações.</li>
    <li>Ainda assim, nenhum sistema é 100% imune. Mantenha sua senha forte e não a compartilhe.</li>
</ul>

<h2>8. Cookies e tecnologias semelhantes</h2>
<p>Usamos cookies estritamente necessários (sessão, preferência de tema). Detalhes na <a href="/cookies">Política de Cookies</a>.</p>

<h2>9. Transferência internacional</h2>
<p>Alguns provedores (IA, infraestrutura) podem processar dados fora do Brasil. Nesses casos, buscamos garantias contratuais adequadas conforme a LGPD.</p>

<h2>10. Alterações e contato</h2>
<p>Podemos atualizar esta Política periodicamente, publicando a nova versão nesta página. Encarregado de dados (DPO): <a href="mailto:<?= htmlspecialchars($companyDpo) ?>"><?= htmlspecialchars($companyDpo) ?></a> · Contato geral: <a href="mailto:<?= htmlspecialchars($companyEmail) ?>"><?= htmlspecialchars($companyEmail) ?></a>.</p>

<div class="alert alert-warning" style="margin-top:28px;">
    <i class="fas fa-triangle-exclamation"></i> <strong>Aviso:</strong> este documento é um modelo gerado automaticamente e não constitui aconselhamento jurídico. Recomenda-se revisão por profissional de direito e, se aplicável, nomeação formal de encarregado (DPO) antes da publicação.
</div>
<?php
$legalContent = ob_get_clean();
include __DIR__ . '/partials/legal.php';
