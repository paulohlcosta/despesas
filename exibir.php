<?php
require_once __DIR__ . '/config.php';

// ── Conexão ──────────────────────────────────────────────────────────────────
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_BANCO . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Erro de conexão: ' . $e->getMessage());
}

// ── Parâmetros de filtro ──────────────────────────────────────────────────────
$ano_atual = (int) date('Y');
$mes_atual = (int) date('m');

$ano_sel = isset($_GET['ano']) ? (int) $_GET['ano'] : $ano_atual;
$mes_sel = isset($_GET['mes']) ? (int) $_GET['mes'] : $mes_atual;

// Anos disponíveis no banco
$anos_disp = $pdo->query("SELECT DISTINCT YEAR(data_emissao) AS ano FROM t_despesas ORDER BY ano DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($anos_disp)) $anos_disp = [$ano_atual];

// ── Categorias (arquivo) ──────────────────────────────────────────────────────
$categorias = [];
if (file_exists(ARQUIVO_CATEGORIAS)) {
    $categorias = array_map('trim', file(ARQUIVO_CATEGORIAS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
}

// ── Resumo por categoria ──────────────────────────────────────────────────────
$stmt_res = $pdo->prepare(
    "SELECT categoria, SUM(valor_liquido) AS total
     FROM t_despesas
     WHERE YEAR(data_emissao) = :ano AND MONTH(data_emissao) = :mes
     GROUP BY categoria"
);
$stmt_res->execute([':ano' => $ano_sel, ':mes' => $mes_sel]);
$totais_db = [];
foreach ($stmt_res->fetchAll() as $row) {
    $totais_db[$row['categoria'] ?? '(sem categoria)'] = (float) $row['total'];
}

// Garante que todas as categorias apareçam, mesmo zeradas
$resumo = [];
foreach ($categorias as $cat) {
    $resumo[$cat] = $totais_db[$cat] ?? 0.00;
}
// Categorias que estão no banco mas não no arquivo
foreach ($totais_db as $cat => $val) {
    if (!isset($resumo[$cat])) $resumo[$cat] = $val;
}
arsort($resumo);

$total_mes = array_sum($resumo);

// ── Lista de despesas ─────────────────────────────────────────────────────────
$stmt_lista = $pdo->prepare(
    "SELECT id, data_emissao, nome_estabelecimento, cnpj_emitente,
            categoria, valor_bruto, desconto, valor_liquido,
            forma_pagamento, confianca_extracao, observacoes, arquivo_imagem
     FROM t_despesas
     WHERE YEAR(data_emissao) = :ano AND MONTH(data_emissao) = :mes
     ORDER BY data_emissao ASC"
);
$stmt_lista->execute([':ano' => $ano_sel, ':mes' => $mes_sel]);
$despesas = $stmt_lista->fetchAll();

$meses_pt = ['', 'Janeiro','Fevereiro','Março','Abril','Maio','Junho',
             'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Despesas — <?= $meses_pt[$mes_sel] . ' ' . $ano_sel ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Segoe UI', sans-serif;
    background: #f4f6f9;
    color: #333;
    padding: 24px;
  }

  h1 { font-size: 1.4rem; margin-bottom: 16px; color: #2c3e50; }
  h2 { font-size: 1.1rem; margin-bottom: 12px; color: #34495e; }

  /* Filtro */
  .filtro {
    display: flex;
    gap: 12px;
    align-items: center;
    margin-bottom: 28px;
    flex-wrap: wrap;
  }
  .filtro select {
    padding: 8px 12px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 0.95rem;
    background: #fff;
  }
  .filtro button {
    padding: 8px 18px;
    background: #2980b9;
    color: #fff;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 0.95rem;
  }
  .filtro button:hover { background: #1f6391; }

  /* Cards de resumo */
  .resumo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 32px;
  }
  .card {
    background: #fff;
    border-radius: 8px;
    padding: 14px 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
    display: flex;
    flex-direction: column;
    gap: 4px;
  }
  .card.total {
    background: #2980b9;
    color: #fff;
  }
  .card .cat-nome {
    font-size: 0.78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #888;
  }
  .card.total .cat-nome { color: #d6eaf8; }
  .card .cat-valor {
    font-size: 1.15rem;
    font-weight: 600;
  }
  .card .cat-valor.zerado { color: #bbb; }

  /* Tabela */
  .tabela-wrap {
    overflow-x: auto;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,.08);
  }
  table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
  }
  thead { background: #2c3e50; color: #fff; }
  th, td { padding: 10px 12px; text-align: left; white-space: nowrap; }
  tbody tr:nth-child(even) { background: #f9fafb; }
  tbody tr:hover { background: #eaf3fb; }

  td.valor { text-align: right; font-variant-numeric: tabular-nums; }
  td.centro { text-align: center; }

  .confianca {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.78rem;
    font-weight: 600;
  }
  .conf-alta  { background: #d5f5e3; color: #1e8449; }
  .conf-media { background: #fef9e7; color: #b7770d; }
  .conf-baixa { background: #fdecea; color: #c0392b; }

  .link-imagem {
    color: #2980b9;
    text-decoration: none;
    font-size: 0.82rem;
  }
  .link-imagem:hover { text-decoration: underline; }

  .sem-dados {
    padding: 24px;
    text-align: center;
    color: #aaa;
  }
</style>
</head>
<body>

<h1>Controle de Despesas</h1>

<!-- Filtro -->
<form method="get" class="filtro">
  <select name="mes">
    <?php for ($m = 1; $m <= 12; $m++): ?>
      <option value="<?= $m ?>" <?= $m === $mes_sel ? 'selected' : '' ?>>
        <?= $meses_pt[$m] ?>
      </option>
    <?php endfor; ?>
  </select>

  <select name="ano">
    <?php foreach ($anos_disp as $a): ?>
      <option value="<?= $a ?>" <?= $a === $ano_sel ? 'selected' : '' ?>><?= $a ?></option>
    <?php endforeach; ?>
  </select>

  <button type="submit">Filtrar</button>
</form>

<!-- Resumo por categoria -->
<h2>Resumo — <?= $meses_pt[$mes_sel] . ' ' . $ano_sel ?></h2>
<div class="resumo-grid">

  <div class="card total">
    <span class="cat-nome">Total do mês</span>
    <span class="cat-valor">R$ <?= number_format($total_mes, 2, ',', '.') ?></span>
  </div>

  <?php foreach ($resumo as $cat => $val): ?>
  <div class="card">
    <span class="cat-nome"><?= htmlspecialchars($cat) ?></span>
    <span class="cat-valor <?= $val == 0 ? 'zerado' : '' ?>">
      R$ <?= number_format($val, 2, ',', '.') ?>
    </span>
  </div>
  <?php endforeach; ?>

</div>

<!-- Lista de despesas -->
<h2>Despesas lançadas</h2>
<div class="tabela-wrap">
<?php if (empty($despesas)): ?>
  <p class="sem-dados">Nenhuma despesa encontrada para o período.</p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Data</th>
        <th>Estabelecimento</th>
        <th>CNPJ</th>
        <th>Categoria</th>
        <th>Bruto</th>
        <th>Desconto</th>
        <th>Líquido</th>
        <th>Pagamento</th>
        <th>Confiança</th>
        <th>Obs.</th>
        <th>Imagem</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($despesas as $d): ?>
      <?php
        $conf = (int) $d['confianca_extracao'];
        $conf_class = $conf >= 80 ? 'conf-alta' : ($conf >= 50 ? 'conf-media' : 'conf-baixa');
        $arquivo = $d['arquivo_imagem'];
        $url_img = rtrim(BASE_URL, '/') . '/uploads/' . ltrim($arquivo, '_');
      ?>
      <tr>
        <td><?= $d['id'] ?></td>
        <td><?= date('d/m/Y H:i', strtotime($d['data_emissao'])) ?></td>
        <td><?= htmlspecialchars($d['nome_estabelecimento']) ?></td>
        <td><?= $d['cnpj_emitente'] ? preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $d['cnpj_emitente']) : '—' ?></td>
        <td><?= htmlspecialchars($d['categoria'] ?? '—') ?></td>
        <td class="valor">R$ <?= number_format((float)$d['valor_bruto'], 2, ',', '.') ?></td>
        <td class="valor">R$ <?= number_format((float)$d['desconto'], 2, ',', '.') ?></td>
        <td class="valor"><strong>R$ <?= number_format((float)$d['valor_liquido'], 2, ',', '.') ?></strong></td>
        <td><?= htmlspecialchars($d['forma_pagamento'] ?? '—') ?></td>
        <td class="centro">
          <span class="confianca <?= $conf_class ?>"><?= $conf ?>%</span>
        </td>
        <td><?= htmlspecialchars($d['observacoes'] ?? '—') ?></td>
        <td class="centro">
          <?php if ($arquivo): ?>
            <a class="link-imagem" href="<?= htmlspecialchars($url_img) ?>" target="_blank">ver</a>
          <?php else: ?>
            —
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

</body>
</html>
