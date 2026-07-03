<?php
require_once __DIR__ . '/config.php';

// ─── Conexão ────────────────────────────────────────────────────────────────
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_BANCO . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Erro na conexão: ' . $e->getMessage());
}

// ─── Funções auxiliares ──────────────────────────────────────────────────────
function carregar_categorias(): array {
    if (!file_exists(ARQUIVO_CATEGORIAS)) return [];
    $linhas = file(ARQUIVO_CATEGORIAS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_map('trim', $linhas);
}

function formata_brl(float $valor): string {
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

// ─── Parâmetros de filtro ────────────────────────────────────────────────────
$ano_atual = (int) date('Y');
$mes_atual = (int) date('m');

$ano_sel = isset($_GET['ano']) ? (int) $_GET['ano'] : $ano_atual;
$mes_sel = isset($_GET['mes']) ? (int) $_GET['mes'] : $mes_atual;

// anos disponíveis (do mais antigo ao atual)
$stmt_anos = $pdo->query("SELECT DISTINCT YEAR(data_emissao) AS ano FROM t_despesas ORDER BY ano DESC");
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN);
if (empty($anos_disponiveis)) {
    $anos_disponiveis = [$ano_atual];
}

$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março',    4 => 'Abril',
    5 => 'Maio',    6 => 'Junho',     7 => 'Julho',     8 => 'Agosto',
    9 => 'Setembro',10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
];

// ─── Resumo por categoria ────────────────────────────────────────────────────
$categorias_todas = carregar_categorias();

$stmt_res = $pdo->prepare(
    "SELECT categoria, SUM(valor_liquido) AS total
       FROM t_despesas
      WHERE YEAR(data_emissao) = :ano
        AND MONTH(data_emissao) = :mes
        AND revisado = 1
      GROUP BY categoria"
);
$stmt_res->execute([':ano' => $ano_sel, ':mes' => $mes_sel]);
$totais_db = [];
foreach ($stmt_res->fetchAll() as $row) {
    $totais_db[$row['categoria'] ?? '(sem categoria)'] = (float) $row['total'];
}

// Monta resumo com todas as categorias (zeradas inclusive)
$resumo = [];
foreach ($categorias_todas as $cat) {
    $resumo[$cat] = $totais_db[$cat] ?? 0.00;
}
// categorias que estão no banco mas não no arquivo
foreach ($totais_db as $cat => $val) {
    if (!isset($resumo[$cat])) {
        $resumo[$cat] = $val;
    }
}
arsort($resumo);
$total_geral = array_sum($resumo);

// ─── Lista de despesas ───────────────────────────────────────────────────────
$stmt_lista = $pdo->prepare(
    "SELECT id, data_emissao, nome_estabelecimento, cnpj_emitente,
            categoria, forma_pagamento, valor_bruto, desconto,
            valor_liquido, confianca_extracao, observacoes, arquivo_imagem
       FROM t_despesas
      WHERE YEAR(data_emissao) = :ano
        AND MONTH(data_emissao) = :mes
        AND revisado = 1
      ORDER BY data_emissao DESC"
);
$stmt_lista->execute([':ano' => $ano_sel, ':mes' => $mes_sel]);
$despesas = $stmt_lista->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Despesas — <?= $meses[$mes_sel] . ' ' . $ano_sel ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:       #0f1117;
    --surface:  #1a1d27;
    --border:   #2a2d3e;
    --accent:   #6c63ff;
    --accent2:  #00c9a7;
    --text:     #e2e8f0;
    --muted:    #6b7280;
    --danger:   #ef4444;
    --warn:     #f59e0b;
    --ok:       #22c55e;
    --radius:   10px;
    --shadow:   0 4px 24px rgba(0,0,0,.4);
  }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'Segoe UI', system-ui, sans-serif;
    font-size: 14px;
    line-height: 1.5;
    padding: 24px 16px;
  }

  h1 { font-size: 1.5rem; font-weight: 700; color: #fff; margin-bottom: 4px; }
  h2 { font-size: 1.1rem; font-weight: 600; color: var(--text); margin-bottom: 16px; }

  /* ── Filtro ── */
  .filtro {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 20px;
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 16px;
    margin-bottom: 24px;
    box-shadow: var(--shadow);
  }
  .filtro label { display: block; font-size: 12px; color: var(--muted); margin-bottom: 6px; }
  .filtro select {
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: 6px;
    padding: 8px 12px;
    font-size: 14px;
    cursor: pointer;
  }
  .filtro button {
    background: var(--accent);
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 9px 22px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: opacity .2s;
  }
  .filtro button:hover { opacity: .85; }

  /* ── Cards de resumo ── */
  .resumo-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
  }
  .total-geral {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--accent2);
  }
  .grid-categorias {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 12px;
    margin-bottom: 32px;
  }
  .card-cat {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 16px;
    box-shadow: var(--shadow);
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .card-cat .cat-nome {
    font-size: 12px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .05em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .card-cat .cat-valor {
    font-size: 1.15rem;
    font-weight: 700;
    color: var(--text);
  }
  .card-cat .cat-valor.zero { color: var(--muted); }
  .card-cat .bar-wrap {
    height: 4px;
    background: var(--border);
    border-radius: 99px;
    overflow: hidden;
  }
  .card-cat .bar-fill {
    height: 100%;
    background: var(--accent);
    border-radius: 99px;
    transition: width .4s ease;
  }

  /* ── Tabela de despesas ── */
  .section-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 14px;
  }
  .table-wrap {
    overflow-x: auto;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
  }
  table {
    width: 100%;
    border-collapse: collapse;
    background: var(--surface);
  }
  thead tr { background: #12151f; }
  thead th {
    text-align: left;
    padding: 12px 14px;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: var(--muted);
    white-space: nowrap;
    border-bottom: 1px solid var(--border);
  }
  tbody tr {
    border-bottom: 1px solid var(--border);
    transition: background .15s;
  }
  tbody tr:last-child { border-bottom: none; }
  tbody tr:hover { background: rgba(108,99,255,.08); }
  tbody td {
    padding: 11px 14px;
    vertical-align: middle;
    white-space: nowrap;
  }
  .td-estab { max-width: 180px; overflow: hidden; text-overflow: ellipsis; }
  .td-obs   { max-width: 200px; overflow: hidden; text-overflow: ellipsis; color: var(--muted); font-size: 12px; }

  .badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 600;
    background: rgba(108,99,255,.18);
    color: #a89fff;
  }

  .conf {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 99px;
    font-size: 11px;
    font-weight: 600;
  }
  .conf-ok   { background: rgba(34,197,94,.15);  color: var(--ok); }
  .conf-warn { background: rgba(245,158,11,.15); color: var(--warn); }
  .conf-bad  { background: rgba(239,68,68,.15);  color: var(--danger); }

  .vazio {
    text-align: center;
    padding: 40px;
    color: var(--muted);
    font-size: 15px;
  }

  @media (max-width: 600px) {
    .grid-categorias { grid-template-columns: 1fr 1fr; }
  }
</style>
</head>
<body>

<h1>Despesas</h1>
<p style="color:var(--muted); margin-bottom:20px;">Controle financeiro de documentos fiscais</p>

<!-- ── Filtro ─────────────────────────────────────────────────────────────── -->
<form method="get" class="filtro">
  <div>
    <label for="mes">Mês</label>
    <select name="mes" id="mes">
      <?php foreach ($meses as $num => $nome): ?>
        <option value="<?= $num ?>" <?= $num === $mes_sel ? 'selected' : '' ?>>
          <?= $nome ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label for="ano">Ano</label>
    <select name="ano" id="ano">
      <?php foreach ($anos_disponiveis as $a): ?>
        <option value="<?= $a ?>" <?= (int)$a === $ano_sel ? 'selected' : '' ?>>
          <?= $a ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button type="submit">Filtrar</button>
</form>

<!-- ── Resumo por categoria ────────────────────────────────────────────────── -->
<div class="resumo-header">
  <h2>Resumo por categoria — <?= $meses[$mes_sel] . ' ' . $ano_sel ?></h2>
  <span class="total-geral"><?= formata_brl($total_geral) ?></span>
</div>

<div class="grid-categorias">
  <?php foreach ($resumo as $cat => $val):
    $pct = $total_geral > 0 ? round(($val / $total_geral) * 100) : 0;
  ?>
  <div class="card-cat">
    <span class="cat-nome" title="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></span>
    <span class="cat-valor <?= $val == 0 ? 'zero' : '' ?>"><?= formata_brl($val) ?></span>
    <div class="bar-wrap">
      <div class="bar-fill" style="width:<?= $pct ?>%"></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Lista de despesas ───────────────────────────────────────────────────── -->
<p class="section-title">
  Lançamentos — <?= count($despesas) ?> registro(s)
</p>

<div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Data emissão</th>
        <th>Estabelecimento</th>
        <th>Categoria</th>
        <th>Pagamento</th>
        <th style="text-align:right">Bruto</th>
        <th style="text-align:right">Desconto</th>
        <th style="text-align:right">Líquido</th>
        <th style="text-align:center">Confiança</th>
        <th>Observações</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($despesas)): ?>
      <tr><td colspan="10" class="vazio">Nenhuma despesa encontrada para este período.</td></tr>
    <?php else: ?>
      <?php foreach ($despesas as $d):
        $conf = (int) $d['confianca_extracao'];
        $conf_class = $conf >= 80 ? 'conf-ok' : ($conf >= 50 ? 'conf-warn' : 'conf-bad');
        $data_fmt = $d['data_emissao']
            ? date('d/m/Y H:i', strtotime($d['data_emissao']))
            : '—';
      ?>
      <tr>
        <td style="color:var(--muted)"><?= $d['id'] ?></td>
        <td><?= $data_fmt ?></td>
        <td class="td-estab" title="<?= htmlspecialchars($d['nome_estabelecimento']) ?>">
          <?= htmlspecialchars($d['nome_estabelecimento']) ?>
        </td>
        <td>
          <?php if ($d['categoria']): ?>
            <span class="badge"><?= htmlspecialchars($d['categoria']) ?></span>
          <?php else: ?>
            <span style="color:var(--muted)">—</span>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($d['forma_pagamento'] ?? '—') ?></td>
        <td style="text-align:right"><?= formata_brl((float)$d['valor_bruto']) ?></td>
        <td style="text-align:right; color:var(--muted)"><?= formata_brl((float)$d['desconto']) ?></td>
        <td style="text-align:right; font-weight:700"><?= formata_brl((float)$d['valor_liquido']) ?></td>
        <td style="text-align:center">
          <span class="conf <?= $conf_class ?>"><?= $conf ?>%</span>
        </td>
        <td class="td-obs" title="<?= htmlspecialchars($d['observacoes'] ?? '') ?>">
          <?= htmlspecialchars($d['observacoes'] ?? '—') ?>
        </td>
      </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
  </table>
</div>

</body>
</html>
