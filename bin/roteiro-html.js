/**
 * bin/roteiro-html.js — envolve o HTML gerado pelo marked em um template estilizado para impressão.
 * Uso: node bin/roteiro-html.js <fragmento.html> <saida.html> [titulo]
 */
const fs = require('fs');

const [, , fragmentPath, outputPath, title = 'Roteiro de Testes — AfiliaFacil'] = process.argv;

if (!fragmentPath || !outputPath) {
  console.error('Uso: node bin/roteiro-html.js <fragmento.html> <saida.html> [titulo]');
  process.exit(1);
}

const fragment = fs.readFileSync(fragmentPath, 'utf8');

const html = `<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>${title}</title>
<style>
  @page { size: A4; margin: 18mm 16mm; }
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
    color: #1f2937; font-size: 11.5pt; line-height: 1.65; margin: 0;
  }
  h1 {
    font-size: 20pt; color: #0d6efd; border-bottom: 3px solid #0d6efd;
    padding-bottom: 8px; margin: 0 0 18px;
  }
  h2 {
    font-size: 14pt; color: #0b5ed7; margin: 26px 0 10px;
    border-left: 4px solid #0d6efd; padding-left: 10px;
    page-break-after: avoid;
  }
  h3 { font-size: 12pt; color: #374151; margin: 18px 0 8px; page-break-after: avoid; }
  p { margin: 8px 0; }
  ul, ol { padding-left: 22px; margin: 8px 0; }
  li { margin: 4px 0; }
  strong { color: #111827; }
  a { color: #0d6efd; text-decoration: none; }
  code {
    background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 4px;
    padding: 1px 5px; font-family: Consolas, monospace; font-size: 10pt;
  }
  pre { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; overflow-x: auto; page-break-inside: avoid; }
  pre code { background: none; border: none; padding: 0; }
  table { width: 100%; border-collapse: collapse; margin: 12px 0; page-break-inside: avoid; font-size: 10.5pt; }
  th, td { border: 1px solid #d1d5db; padding: 7px 9px; text-align: left; vertical-align: top; }
  th { background: #eff6ff; color: #1e40af; font-weight: 600; }
  tr:nth-child(even) td { background: #f9fafb; }
  blockquote {
    margin: 12px 0; padding: 10px 14px; background: #fffbeb;
    border-left: 4px solid #f59e0b; border-radius: 0 6px 6px 0;
    color: #78350f; page-break-inside: avoid;
  }
  blockquote p { margin: 4px 0; }
  hr { border: none; border-top: 1px solid #e5e7eb; margin: 22px 0; }
  h1 + blockquote { margin-top: 0; }
  input[type=checkbox] { transform: scale(1.1); }
</style>
</head>
<body>
${fragment}
</body>
</html>`;

fs.writeFileSync(outputPath, html, 'utf8');
console.log('HTML gerado: ' + outputPath);
