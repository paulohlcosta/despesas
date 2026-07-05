<?php
require_once __DIR__ . '/config.php';

$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_BANCO . ';charset=utf8mb4';
$pdo = new PDO($dsn, DB_USUARIO, DB_SENHA);
$rows = $pdo->query("SELECT * FROM t_despesas ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>t_despesas</title>
<style>
  body { font-family: monospace; font-size: 13px; padding: 16px; background: #f5f5f5; }
  table { border-collapse: collapse; background: #fff; width: 100%; }
  th { background: #333; color: #fff; padding: 8px 10px; text-align: left; }
  td { padding: 7px 10px; border-bottom: 1px solid #ddd; white-space: nowrap; }
  tr:hover td { background: #f0f7ff; }
</style>
</head>
<body>
<h3>t_despesas (<?= count($rows) ?> registros)</h3>
<div style="overflow-x:auto">
<table>
  <thead>
    <tr><?php foreach (array_keys($rows[0] ?? []) as $col): ?>
      <th><?= htmlspecialchars($col) ?></th>
    <?php endforeach; ?></tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r): ?>
    <tr><?php foreach ($r as $col => $v): ?>
      <td><?php if ($col === 'arquivo_imagem' && $v):
        $arquivo = ltrim($v, '_');
        echo '<a href="uploads/' . htmlspecialchars($arquivo) . '" target="_blank">' . htmlspecialchars($v) . '</a>';
      else:
        echo htmlspecialchars($v ?? '—');
      endif; ?></td>
    <?php endforeach; ?></tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
</body>
</html>
