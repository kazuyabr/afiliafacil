# Vídeos — ffmpeg no painel, branches e parceiros (P9)

## 1. Ferramentas ffmpeg (`/admin/videos.php`, `lib/Video/VideoStudio.php`)

Upload (24MB) ou importação por URL (200MB, anti-SSRF). Operações:

| Operação | O que faz | Saída |
|---|---|---|
| Cortar | Trecho `[início, fim]` sem re-encode (rápido) | `{base}-corte-{A}-{B}.mp4` |
| Capa | Frame em N segundos | `{base}-capa-{N}s.jpg` |
| Legenda | Queima `.srt` (estilo mobile legível) | `{base}-legenda.mp4` |
| Variação | Espelho + resize 98% + re-encode (muda o hash) | `{base}-varN.mp4` |

Tudo com `escapeshellarg` + `timeout` rígido; entrada validada via `ffprobe`
(só vídeo real). Arquivos em `uploads/videos/` (volume Docker), servidos pela
API (`action=download`). Auditoria: `video_uploaded/imported/cut/cover/subtitled/varied`.

## 2. Estratégia de branches (teste A/B)

- O **original nunca é alterado** — cada operação gera um branch com sufixo.
- Sufixos não empilham (`x-corte-0-30-var1` deriva de `x-corte-0-30`).
- Fluxo sugerido: original → corte do gancho (primeiros 3–5s) → legenda queimada
  → 2–3 variações → rode como criativos separados e compare retenção/CTR.
- Capas (`-capa-Ns.jpg`) servem de thumbnail no Player e nos anúncios.

## 3. Parceiros de hospedagem (slot Drift)

O Player das Páginas (`/admin/video.php`) aceita **qualquer URL de vídeo** —
é por ele que parceiros plugam, sem código novo:

| Provedor | Status | Como usar |
|---|---|---|
| Upload local | ✅ | Envie em Vídeos e use a URL de download no Player |
| YouTube / URL externa | ✅ | Cole a URL direto no Player |
| **Drift** | 🔜 parceiro | Quando ativo: cole a URL do vídeo hospedado no Drift no Player (streaming + métricas do parceiro). Integração profunda (webhooks de view) fica para a Fase 4 |

Critério para ativar um parceiro: URL pública estável + embed/streaming direto.
Nada no núcleo depende de parceiro — o ffmpeg local cobre 100% do MVP.
