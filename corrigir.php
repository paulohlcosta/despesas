<?php
require_once __DIR__ . '/config.php';

// --- SALVAR EDIÇÃO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_BANCO . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA);

    $campos_editaveis = [
        'tipo_documento', 'chave_acesso', 'data_emissao', 'cnpj_emitente',
        'nome_estabelecimento', 'valor_bruto', 'desconto', 'valor_liquido',
        'forma_pagamento', 'categoria', 'observacoes'
    ];

    $sets = [];
    $params = [];
    foreach ($campos_editaveis as $campo) {
        if (array_key_exists($campo, $_POST)) {
            $val = $_POST[$campo];
            // campos NOT NULL que não aceitam null/vazio
            $not_null = ['tipo_documento'];
            if (in_array($campo, $not_null) && $val === '') {
                $val = 'Outro'; // valor padrão fallback
            } else {
                $val = ($val === '') ? null : $val;
            }
            $sets[]   = "$campo = ?";
            $params[] = $val;
        }
    }

    if ($sets) {
        $params[] = (int) $_POST['id'];
        $sql = "UPDATE t_despesas SET " . implode(', ', $sets) . ", revisado = 1 WHERE id = ?";
        $pdo->prepare($sql)->execute($params);
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query(array_filter([
        'data_ini'   => $_POST['data_ini'] ?? '',
        'data_fim'   => $_POST['data_fim'] ?? '',
        'sem_nome'   => $_POST['sem_nome'] ?? '',
        'diversos'   => $_POST['diversos'] ?? '',
    ])));
    exit;
}

// --- BUSCAR REGISTROS ---
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_BANCO . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USUARIO, DB_SENHA);

$where  = [];
$params = [];

$data_ini = $_GET['data_ini'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';
$sem_nome = !empty($_GET['sem_nome']);
$diversos = !empty($_GET['diversos']);

if ($data_ini) { $where[] = 'data_emissao >= ?'; $params[] = $data_ini; }
if ($data_fim) { $where[] = 'data_emissao <= ?'; $params[] = $data_fim; }
if ($sem_nome) { $where[] = "(nome_estabelecimento IS NULL OR nome_estabelecimento = '')"; }
if ($diversos) { $where[] = "categoria = 'Diversos'"; }

$sql = "SELECT * FROM t_despesas" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY data_emissao DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categorias = [
    '', 'Alimentação', 'Combustível', 'Farmácia', 'Saúde', 'Transporte',
    'Moradia', 'Serviços', 'Educação', 'Lazer', 'Vestuário', 'Diversos', 'Outros'
];
$formas_pgto = ['', 'Dinheiro', 'Débito', 'Crédito', 'Pix', 'Transferência', 'Boleto'];
$tipos_doc   = ['', 'NFe', 'NFCe', 'Cupom Fiscal', 'Recibo', 'Outro'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Corrigir Despesas</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: monospace; font-size: 12px; padding: 12px; background: #f0f0f0; margin: 0; }

  h3 { margin: 0 0 10px; font-size: 14px; }

  /* FILTROS */
  .filtros {
    background: #fff;
    border: 1px solid #ccc;
    border-radius: 6px;
    padding: 12px 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    align-items: flex-end;
    margin-bottom: 14px;
  }
  .filtros label { display: flex; flex-direction: column; gap: 3px; font-weight: bold; font-size: 11px; }
  .filtros input[type=date], .filtros select { padding: 5px 7px; border: 1px solid #bbb; border-radius: 4px; font-size: 12px; }
  .flag-group { display: flex; gap: 16px; align-items: center; }
  .flag-label { display: flex; align-items: center; gap: 5px; font-size: 12px; cursor: pointer; font-weight: normal !important; }
  .flag-label input { width: 15px; height: 15px; cursor: pointer; }
  .btn { padding: 6px 16px; background: #333; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; }
  .btn:hover { background: #555; }
  .btn-reset { background: #888; }

  /* TABELA */
  .wrap { overflow-x: auto; }
  table { border-collapse: collapse; background: #fff; width: 100%; min-width: 1200px; }
  th { background: #2c2c2c; color: #fff; padding: 7px 8px; text-align: left; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
  td { padding: 5px 7px; border-bottom: 1px solid #e0e0e0; vertical-align: middle; white-space: nowrap; }
  tr:hover td { background: #eef4ff; }

  /* célula editável */
  td input[type=text], td input[type=date], td input[type=number], td select {
    border: 1px solid transparent;
    background: transparent;
    font-family: monospace;
    font-size: 12px;
    width: 100%;
    padding: 2px 4px;
    border-radius: 3px;
    min-width: 80px;
  }
  td input[type=text]:focus, td input[type=date]:focus, td input[type=number]:focus, td select:focus {
    border-color: #4a90d9;
    background: #fff;
    outline: none;
  }
  td select { min-width: 110px; }

  /* campos readonly */
  .ro { color: #888; font-size: 11px; }

  /* badge revisado */
  .badge-ok  { background: #d4edda; color: #155724; padding: 2px 7px; border-radius: 10px; font-size: 10px; }
  .badge-no  { background: #fff3cd; color: #856404; padding: 2px 7px; border-radius: 10px; font-size: 10px; }

  /* vazio destacado */
  .vazio { background: #fff0f0 !important; }

  /* botão salvar por linha */
  .btn-save {
    padding: 3px 10px;
    background: #1a7a3f;
    color: #fff;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 11px;
    white-space: nowrap;
  }
  .btn-save:hover { background: #25a355; }

  .total { font-size: 11px; color: #555; margin-bottom: 6px; }
</style>
</head>
<body>

<h3>Corrigir Despesas</h3>

<!-- FILTROS -->
<form method="GET">
  <div class="filtros">
    <label>Data início
      <input type="date" name="data_ini" value="<?= htmlspecialchars($data_ini) ?>">
    </label>
    <label>Data fim
      <input type="date" name="data_fim" value="<?= htmlspecialchars($data_fim) ?>">
    </label>
    <div class="flag-group">
      <label class="flag-label">
        <input type="checkbox" name="sem_nome" value="1" <?= $sem_nome ? 'checked' : '' ?>>
        Sem estabelecimento
      </label>
      <label class="flag-label">
        <input type="checkbox" name="diversos" value="1" <?= $diversos ? 'checked' : '' ?>>
        Categoria "Diversos"
      </label>
    </div>
    <button type="submit" class="btn">Filtrar</button>
    <a href="<?= $_SERVER['PHP_SELF'] ?>"><button type="button" class="btn btn-reset">Limpar</button></a>
  </div>
</form>

<p class="total"><?= count($rows) ?> registro(s) encontrado(s)</p>

<div class="wrap">
<table>
  <thead>
    <tr>
      <th>id</th>
      <th>tipo_doc</th>
      <th>imagem</th>
      <th>chave_acesso</th>
      <th>data_emissao</th>
      <th>cnpj_emitente</th>
      <th>nome_estabelecimento</th>
      <th>valor_bruto</th>
      <th>desconto</th>
      <th>valor_liquido</th>
      <th>forma_pgto</th>
      <th>categoria</th>
      <th>confiança</th>
      <th>revisado</th>
      <th>observacoes</th>
      <th>created_at</th>
      <th>ação</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r):
    $sem_est = empty($r['nome_estabelecimento']);
    $eh_div  = ($r['categoria'] === 'Diversos');
    $row_class = ($sem_est || $eh_div) ? 'vazio' : '';
  ?>
    <form method="POST">
      <!-- campos ocultos para filtro atual -->
      <input type="hidden" name="data_ini" value="<?= htmlspecialchars($data_ini) ?>">
      <input type="hidden" name="data_fim" value="<?= htmlspecialchars($data_fim) ?>">
      <input type="hidden" name="sem_nome" value="<?= $sem_nome ? 1 : '' ?>">
      <input type="hidden" name="diversos"  value="<?= $diversos  ? 1 : '' ?>">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

      <tr class="<?= $row_class ?>">
        <!-- id (ro) -->
        <td class="ro"><?= (int)$r['id'] ?></td>

        <!-- tipo_documento -->
        <td>
          <select name="tipo_documento">
            <?php foreach ($tipos_doc as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>" <?= $r['tipo_documento'] === $t ? 'selected' : '' ?>><?= $t ?: '—' ?></option>
            <?php endforeach; ?>
          </select>
        </td>

        <!-- arquivo_imagem (ro, link) -->
        <td class="ro">
          <?php if ($r['arquivo_imagem']): ?>
            <a href="uploads/<?= htmlspecialchars($r['arquivo_imagem']) ?>" target="_blank">
              <?= htmlspecialchars($r['arquivo_imagem']) ?>
            </a>
          <?php else: ?>—<?php endif; ?>
        </td>

        <!-- chave_acesso -->
        <td><input type="text" name="chave_acesso" value="<?= htmlspecialchars($r['chave_acesso'] ?? '') ?>" style="min-width:160px"></td>

        <!-- data_emissao -->
        <td><input type="date" name="data_emissao" value="<?= htmlspecialchars(substr($r['data_emissao'] ?? '', 0, 10)) ?>"></td>

        <!-- cnpj_emitente -->
        <td><input type="text" name="cnpj_emitente" value="<?= htmlspecialchars($r['cnpj_emitente'] ?? '') ?>" style="min-width:120px"></td>

        <!-- nome_estabelecimento — destaca se vazio -->
        <td style="<?= $sem_est ? 'background:#ffe0e0' : '' ?>">
          <input type="text" name="nome_estabelecimento" value="<?= htmlspecialchars($r['nome_estabelecimento'] ?? '') ?>" style="min-width:150px; <?= $sem_est ? 'border-color:#e07070' : '' ?>">
        </td>

        <!-- valor_bruto -->
        <td><input type="number" name="valor_bruto" step="0.01" value="<?= htmlspecialchars($r['valor_bruto'] ?? '') ?>" style="min-width:80px"></td>

        <!-- desconto -->
        <td><input type="number" name="desconto" step="0.01" value="<?= htmlspecialchars($r['desconto'] ?? '') ?>" style="min-width:70px"></td>

        <!-- valor_liquido -->
        <td><input type="number" name="valor_liquido" step="0.01" value="<?= htmlspecialchars($r['valor_liquido'] ?? '') ?>" style="min-width:80px"></td>

        <!-- forma_pagamento -->
        <td>
          <select name="forma_pagamento">
            <?php foreach ($formas_pgto as $f): ?>
              <option value="<?= htmlspecialchars($f) ?>" <?= $r['forma_pagamento'] === $f ? 'selected' : '' ?>><?= $f ?: '—' ?></option>
            <?php endforeach; ?>
          </select>
        </td>

        <!-- categoria — destaca se Diversos -->
        <td style="<?= $eh_div ? 'background:#fff3cd' : '' ?>">
          <select name="categoria">
            <?php foreach ($categorias as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>" <?= $r['categoria'] === $c ? 'selected' : '' ?>><?= $c ?: '—' ?></option>
            <?php endforeach; ?>
          </select>
        </td>

        <!-- confianca_extracao (ro) -->
        <td class="ro"><?= htmlspecialchars($r['confianca_extracao'] ?? '—') ?></td>

        <!-- revisado (ro, badge) -->
        <td class="ro">
          <?php if ($r['revisado']): ?>
            <span class="badge-ok">sim</span>
          <?php else: ?>
            <span class="badge-no">não</span>
          <?php endif; ?>
        </td>

        <!-- observacoes -->
        <td><input type="text" name="observacoes" value="<?= htmlspecialchars($r['observacoes'] ?? '') ?>" style="min-width:140px"></td>

        <!-- created_at (ro) -->
        <td class="ro"><?= htmlspecialchars($r['created_at'] ?? '—') ?></td>

        <!-- salvar -->
        <td><button type="submit" class="btn-save">Salvar</button></td>
      </tr>
    </form>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

</body>
</html>
