<?php
require_once __DIR__ . '/config.php';

// --- CATEGORIAS DO ARQUIVO ---
$arquivo_cat = __DIR__ . '/categorias.txt';
$categorias  = file_exists($arquivo_cat)
    ? array_filter(array_map('trim', file($arquivo_cat)))
    : ['Diversos'];
$categorias  = array_values($categorias);

// --- SALVAR EDIÇÃO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_BANCO . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA);

    $campos_editaveis = [
        'data_emissao', 'cnpj_emitente', 'nome_estabelecimento',
        'valor_liquido', 'forma_pagamento', 'categoria', 'observacoes'
    ];

    // Validar categoria
    $cat_post = $_POST['categoria'] ?? '';
    if (!in_array($cat_post, $categorias)) {
        $cat_post = $categorias[0]; // fallback para a primeira
    }

    $sets   = [];
    $params = [];
    foreach ($campos_editaveis as $campo) {
        if (!array_key_exists($campo, $_POST)) continue;

        $val = $_POST[$campo];

        // Converter data BR -> banco (dd/mm/aaaa -> aaaa-mm-dd)
        if ($campo === 'data_emissao' && preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $val)) {
            [$d, $m, $a] = explode('/', $val);
            $val = "$a-$m-$d";
        }

        if ($campo === 'categoria') $val = $cat_post;

        $sets[]   = "$campo = ?";
        $params[] = ($val === '') ? null : $val;
    }

    if ($sets) {
        $params[] = (int) $_POST['id'];
        $pdo->prepare(
            "UPDATE t_despesas SET " . implode(', ', $sets) . ", revisado = 1 WHERE id = ?"
        )->execute($params);
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query(array_filter([
        'data_ini' => $_POST['f_data_ini'] ?? '',
        'data_fim' => $_POST['f_data_fim'] ?? '',
        'sem_nome' => $_POST['f_sem_nome'] ?? '',
        'diversos' => $_POST['f_diversos']  ?? '',
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
if ($sem_nome) {
    $where[] = "(nome_estabelecimento IS NULL OR TRIM(nome_estabelecimento) = '' OR nome_estabelecimento = '(não identificado)')";
}
if ($diversos) { $where[] = "categoria = 'Diversos'"; }

$sql  = "SELECT * FROM t_despesas" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY data_emissao DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$formas_pgto = ['', 'Dinheiro', 'Débito', 'Crédito', 'Pix', 'Transferência', 'Boleto'];

// Helper: data banco -> dd/mm/aaaa
function fmtData(?string $d): string {
    if (!$d) return '';
    // aceita aaaa-mm-dd ou aaaa-mm-dd hh:mm:ss
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) return "$m[3]/$m[2]/$m[1]";
    return $d;
}

function vazio(?string $v): bool {
    return $v === null || trim($v) === '' || $v === '(não identificado)';
}
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

  .filtros {
    background: #fff; border: 1px solid #ccc; border-radius: 6px;
    padding: 12px 16px; display: flex; flex-wrap: wrap;
    gap: 14px; align-items: flex-end; margin-bottom: 10px;
  }
  .filtros label { display: flex; flex-direction: column; gap: 3px; font-weight: bold; font-size: 11px; }
  .filtros input[type=date] { padding: 5px 7px; border: 1px solid #bbb; border-radius: 4px; font-size: 12px; }
  .flag-group { display: flex; gap: 16px; align-items: center; }
  .flag-label { display: flex; align-items: center; gap: 5px; font-size: 12px; cursor: pointer; font-weight: normal; }
  .flag-label input { width: 15px; height: 15px; cursor: pointer; }
  .btn { padding: 6px 16px; background: #333; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; }
  .btn:hover { background: #555; }
  .btn-reset { background: #888; }
  .total { font-size: 11px; color: #555; margin-bottom: 6px; }

  .wrap { overflow-x: auto; }
  table { border-collapse: collapse; background: #fff; width: 100%; }
  th {
    background: #2c2c2c; color: #fff; padding: 7px 8px;
    text-align: left; white-space: nowrap;
    position: sticky; top: 0; z-index: 10;
  }
  td { padding: 5px 7px; border-bottom: 1px solid #e0e0e0; vertical-align: middle; white-space: nowrap; }
  tr:hover td { background: #eef4ff; }

  td input[type=text], td input[type=number], td select {
    border: 1px solid transparent; background: transparent;
    font-family: monospace; font-size: 12px;
    width: 100%; padding: 2px 4px; border-radius: 3px;
  }
  td input[type=text]:focus, td input[type=number]:focus, td select:focus {
    border-color: #4a90d9; background: #fff; outline: none;
  }
  td select { min-width: 130px; }

  .ro { color: #888; font-size: 11px; }
  .badge-ok { background: #d4edda; color: #155724; padding: 2px 7px; border-radius: 10px; font-size: 10px; }
  .badge-no { background: #fff3cd; color: #856404; padding: 2px 7px; border-radius: 10px; font-size: 10px; }

  .cel-vazio { background: #ffe0e0 !important; }
  .cel-div   { background: #fff3cd !important; }

  .btn-save {
    padding: 3px 10px; background: #1a7a3f; color: #fff;
    border: none; border-radius: 4px; cursor: pointer; font-size: 11px;
  }
  .btn-save:hover { background: #25a355; }

  #img-preview {
    display: none;
    position: fixed;
    z-index: 1000;
    background: rgba(0,0,0,0.82);
    border-radius: 10px;
    padding: 10px;
    pointer-events: none; /* não interfere no clique do link */
    box-shadow: 0 4px 32px #0008;
  }
  #img-preview img {
    display: block;
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    transform: rotate(90deg);
    /* compensa o rotate: troca largura/altura visualmente */
    transform-origin: center center;
  }    
    
</style>
</head>
<body>

<h3>Corrigir Despesas</h3>

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
      <th>imagem</th>
      <th>data_emissao</th>
      <th>cnpj_emitente</th>
      <th>nome_estabelecimento</th>
      <th>valor_liquido</th>
      <th>forma_pgto</th>
      <th>categoria</th>
      <th>revisado</th>
      <th>observacoes</th>
      <th>created_at</th>
      <th>ação</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r):
    $sem_est = vazio($r['nome_estabelecimento']);
    $eh_div  = ($r['categoria'] === 'Diversos');
  ?>
    <form method="POST">
      <!-- preservar filtros -->
      <input type="hidden" name="f_data_ini" value="<?= htmlspecialchars($data_ini) ?>">
      <input type="hidden" name="f_data_fim" value="<?= htmlspecialchars($data_fim) ?>">
      <input type="hidden" name="f_sem_nome" value="<?= $sem_nome ? 1 : '' ?>">
      <input type="hidden" name="f_diversos"  value="<?= $diversos  ? 1 : '' ?>">
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">

      <tr>
        <!-- id -->
        <td class="ro"><?= (int)$r['id'] ?></td>

        <!-- imagem -->
        <td class="ro">
          <?php if ($r['arquivo_imagem']): ?>
            <a href="uploads/<?= htmlspecialchars($r['arquivo_imagem']) ?>"
               target="_blank"
               data-preview>
              <?= htmlspecialchars($r['arquivo_imagem']) ?>
            </a>
          <?php else: ?>—<?php endif; ?>
        </td>

        <!-- data_emissao — exibe BR, envia BR, PHP converte -->
        <td>
          <input type="text"
                 name="data_emissao"
                 value="<?= htmlspecialchars(fmtData($r['data_emissao'])) ?>"
                 placeholder="dd/mm/aaaa"
                 style="min-width:90px"
                 maxlength="10">
        </td>

        <!-- cnpj_emitente -->
        <td>
          <input type="text" name="cnpj_emitente"
                 value="<?= htmlspecialchars($r['cnpj_emitente'] ?? '') ?>"
                 style="min-width:120px">
        </td>

        <!-- nome_estabelecimento -->
        <td class="<?= $sem_est ? 'cel-vazio' : '' ?>">
          <input type="text" name="nome_estabelecimento"
                 value="<?= htmlspecialchars($sem_est ? '' : ($r['nome_estabelecimento'] ?? '')) ?>"
                 style="min-width:160px; <?= $sem_est ? 'border-color:#e07070' : '' ?>">
        </td>

        <!-- valor_liquido -->
        <td>
          <input type="number" name="valor_liquido" step="0.01"
                 value="<?= htmlspecialchars($r['valor_liquido'] ?? '') ?>"
                 style="min-width:80px">
        </td>

        <!-- forma_pagamento -->
        <td>
          <select name="forma_pagamento">
            <?php foreach ($formas_pgto as $f): ?>
              <option value="<?= htmlspecialchars($f) ?>"
                <?= ($r['forma_pagamento'] ?? '') === $f ? 'selected' : '' ?>>
                <?= $f ?: '—' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>

        <!-- categoria -->
        <td class="<?= $eh_div ? 'cel-div' : '' ?>">
          <select name="categoria">
            <?php foreach ($categorias as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>"
                <?= ($r['categoria'] ?? '') === $c ? 'selected' : '' ?>>
                <?= htmlspecialchars($c) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </td>

        <!-- revisado (ro) -->
        <td class="ro">
          <?= $r['revisado'] ? '<span class="badge-ok">sim</span>' : '<span class="badge-no">não</span>' ?>
        </td>

        <!-- observacoes -->
        <td>
          <input type="text" name="observacoes"
                 value="<?= htmlspecialchars($r['observacoes'] ?? '') ?>"
                 style="min-width:140px">
        </td>

        <!-- created_at (ro) -->
        <td class="ro"><?= htmlspecialchars(fmtData($r['created_at'] ?? '')) ?></td>

        <!-- salvar -->
        <td><button type="submit" class="btn-save">Salvar</button></td>
      </tr>
    </form>
  <?php endforeach; ?>





      
    <div id="img-preview"><img id="img-preview-img" src="" alt=""></div>

    <script>
    (function () {
      const box   = document.getElementById('img-preview');
      const img   = document.getElementById('img-preview-img');
      const VW    = () => window.innerWidth;
      const VH    = () => window.innerHeight;
      const SIZE  = 0.80; // 80% da tela
    
      function posicionar(linkEl) {
        const rect = linkEl.getBoundingClientRect();
    
        // Tamanho do quadro
        const w = Math.round(VW() * SIZE);
        const h = Math.round(VH() * SIZE);
    
        box.style.width  = w + 'px';
        box.style.height = h + 'px';
    
        // A imagem está rotacionada 90°, então precisamos que o img
        // caiba dentro trocando largura e altura
        img.style.width  = (h - 20) + 'px';  // altura vira largura após rotate
        img.style.height = (w - 20) + 'px';  // largura vira altura após rotate
        // Centraliza o img rotacionado dentro da box
        img.style.marginLeft = Math.round((w - h) / 2) + 'px';
        img.style.marginTop  = Math.round((w - h) / 2) + 'px';
    
        // Posição do quadro: à direita do link se couber, senão à esquerda
        let left, top;
    
        const espDireita = VW() - rect.right;
        const espEsquerda = rect.left;
    
        if (espDireita >= w + 20) {
          left = rect.right + 10;
        } else if (espEsquerda >= w + 20) {
          left = rect.left - w - 10;
        } else {
          // fallback centralizado
          left = Math.round((VW() - w) / 2);
        }
    
        // Vertical: centraliza no cursor, ajusta para não sair da tela
        top = Math.round(rect.top + rect.height / 2 - h / 2);
        top = Math.max(10, Math.min(top, VH() - h - 10));
    
        box.style.left = left + 'px';
        box.style.top  = top  + 'px';
      }
    
      document.querySelectorAll('a[data-preview]').forEach(function (link) {
        link.addEventListener('mouseenter', function () {
          img.src = '';
          img.src = this.href;
          box.style.display = 'block';
          posicionar(this);
        });
        link.addEventListener('mouseleave', function () {
          box.style.display = 'none';
          img.src = '';
        });
      });
    })();
    </script>

      
  </tbody>
</table>
</div>

</body>
</html>
