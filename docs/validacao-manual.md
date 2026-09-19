# Validação Manual — AfiliaFacil (antes da homologação)

Checklist para **você** validar no browser antes de enviar ao cliente.
Acesse `http://localhost:9876` (ou a URL do túnel) e marque cada item.

> Dica: rode antes `docker exec afiliafacil php bin/smoke.php` — se der **SMOKE OK**, os fluxos automatizados estão de pé.

## 1. Acesso e conta
- [ ] Login com `admin@afiliafacil.com` / `admin123`
- [ ] Login com cada conta demo (`demo.trial@`, `demo.pro@`, `demo.master@` / `demo123456`)
- [ ] Tema claro/escuro alterna e persiste ao recarregar
- [ ] 2FA: ativar em Configurações → Segurança (QR + códigos de recuperação), sair, entrar e validar a etapa 2; desativar no final
- [ ] Cadastro novo em `/register`: aceite dos termos obrigatório; consentimento opcional

## 2. Páginas e clonador
- [ ] Clonar uma URL real (ex.: uma página de vendas simples) com link de afiliado
- [ ] Preview da página clonada renderiza (assets via proxy)
- [ ] Baixar ZIP e abrir localmente (imagens/CSS carregando)
- [ ] Editar metadados (nome, status, domínio, link de afiliado)
- [ ] Editor IDE: abrir, editar código, salvar, ver revisão anterior, restaurar
- [ ] Inspector: clicar em elemento do preview seleciona a tag no código; Ctrl+Click interage
- [ ] Re-clonar (badge "Clone antigo" quando aplicável)
- [ ] Limite de páginas por plano (trial = 1)

## 3. Planos e pagamentos
- [ ] Página Meu Plano mostra o plano atual correto em cada conta demo
- [ ] Admin vê o card ADMIN e planos comerciais desabilitados
- [ ] Checkout PIX: QR + copia e cola gerados (admin aprova em Pagamentos)
- [ ] Checkout Stripe (se configurado): redireciona para o Stripe e volta
- [ ] Painel de Preços (admin): editar quota e ver refletir

## 4. Ofertas Escalando
- [ ] Swipe file com as ofertas demo (sparkline, badges de escala, filtros)
- [ ] Abrir dossiê de uma oferta (consome quota; reabrir a mesma não consome)
- [ ] Curadoria (admin): aprovar/rejeitar, aprovar em lote, configurações do monitor
- [ ] "Buscar ofertas agora" / "Atualizar métricas agora" executam sem erro
- [ ] Gerar `CRON_KEY` e copiar o comando cron

## 5. Ad Spy
- [ ] Busca por termo/domínio retorna anúncios (ou erro tratado por plataforma)
- [ ] Dossiê a partir de uma página clonada
- [ ] Análise IA gera resumo/ângulos (com chave CF configurada)
- [ ] Pills de quota corretas (Trial 3/3, Pro 30/10, Master 300/100)

## 6. Transcrições (STT)
- [ ] Transcrever uma URL de áudio curta (ex.: mp3 público) — com chave CF
- [ ] Transcrever uma página com vídeo (detecção automática)
- [ ] Upload de arquivo (até 24MB)
- [ ] Copiar texto, baixar TXT e SRT (timestamps)
- [ ] Botão "Discutir com o Sócio de IA" abre o agente com o contexto

## 7. Narração (TTS)
- [ ] Gerar narração de um texto curto — com chave CF
- [ ] Player toca e download funciona
- [ ] Carregar texto de uma transcrição (integração STT→TTS)

## 8. Sócio de IA e Subagentes
- [ ] Conversa: ele pergunta antes de assumir (teste um pedido vago)
- [ ] Ação com confirmação: card com motivo/custo + Confirmar/Cancelar
- [ ] Recusa más práticas (ex.: "prometa ganho garantido") com aviso
- [ ] Subagentes (conta Pro/Master): criar pelo modal (template), conversar, editar, ativar/desativar, excluir
- [ ] Limite de subagentes por plano (Pro 2 / Master 5)
- [ ] Gating: conta Trial sem acesso a subagentes

## 9. Moderação e legal
- [ ] Mensagem criminosa no Sócio é bloqueada (não consome quota)
- [ ] Admin → Moderação registra o evento (usuário, hora, IP, conteúdo)
- [ ] Exportação CSV/JSON da moderação
- [ ] Páginas `/termos`, `/privacidade`, `/cookies`, `/degustacao` com os dados da empresa (Configurações)

## 10. BYOK e quotas
- [ ] Configurar uma chave própria em IA (BYOK) → pill muda para "BYOK — sem limite"
- [ ] Esgotar a quota da plataforma em uma conta demo → mensagem orienta configurar a chave

## 11. Treinamento e dados
- [ ] Admin → Treinamento mostra contadores
- [ ] Exportar JSONL do dataset
- [ ] Configurações → Privacidade: ativar consentimento, gerar interação, ver amostra contada; revogar e confirmar que para
- [ ] "Baixar meus dados" (MD/JSONL) em conta Pro/Master

## 12. Administração
- [ ] Usuários: criar/editar/ativar/excluir; admin só gerenciável por admin
- [ ] Cargos: CRUD (master protegido)
- [ ] Auditoria: registros das ações
- [ ] Storage R2: salvar config e testar conexão (se tiver R2)

## 13. Feedback
- [ ] Enviar feedback (sugestão/reclamação/elogio/bug) como conta demo e ver no histórico
- [ ] Validação: mensagem com menos de 10 caracteres é recusada
- [ ] Admin responde → o usuário vê a resposta e o status "respondido"
- [ ] Badge de novos no menu (admin)
- [ ] Exportar CSV e JSON (admin) — insumo para o especialista de mercado

---

**Se algo falhar**: anote o módulo, o passo e o que aconteceu (print ajuda) — isso vira o plano de correção antes da homologação.
