<?php
// Página de monitoramento da fila de processamento.
// Permite iniciar processamento manual e gerenciar o cron job real.

require_once 'config.php';

// ─── Ação: processar agora (executa o worker via CLI) ─────────────────────
$msg_acao = '';
if (isset($_POST['acao'])) {
    switch ($_POST['acao']) {

        case 'processar_agora':
            // Executa o worker em background — retorna imediatamente
            $cmd = '/usr/bin/php ' . escapeshellarg(__DIR__ . '/processar_worker.php')
                 . ' >> ' . escapeshellarg(__DIR__ . '/logs/worker.log') . ' 2>&1 &';
            exec($cmd);
            $msg_acao = 'Worker iniciado em background. Aguarde alguns instantes e recarregue a página.';
            break;

        case 'ativar_cron':
            $linha = CRON_CMD . PHP_EOL;
            if (file_put_contents(CRON_ARQUIVO, $linha) !== false) {
                // Permissão correta para /etc/cron.d
                @chmod(CRON_ARQUIVO, 0644);
                $msg_acao = 'Cron job criado em ' . CRON_ARQUIVO . '.';
            } else {
                $msg_acao = 'ERRO: não foi possível escrever em ' . CRON_ARQUIVO
                          . '. O Apache precisa de sudo para isso. Ver instruções abaixo.';
            }
            break;

        case 'desativar_cron':
            if (file_exists(CRON_ARQUIVO)) {
                if (@unlink(CRON_ARQUIVO)) {
                    $msg_acao = 'Cron job removido.';
                } else {
                    $msg_acao = 'ERRO: não foi possível remover ' . CRON_ARQUIVO . '.';
                }
            } else {
                $msg_acao = 'Cron job já estava inativo.';
            }
            break;
    }
}

// ─── Dados para exibição ──────────────────────────────────────────────────
try {
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_BANCO.';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Pendentes = observacoes = 'pendente de processamento'
    $pendentes = $pdo->query(
        "SELECT id, tipo_documento, arquivo_imagem, created_at
         FROM t_despesas
         WHERE observacoes = 'pendente de processamento'
         ORDER BY created_at ASC"
    )->fetchAll();

    // Últimas 10 processadas
    $processadas = $pdo->query(
        "SELECT id, nome_estabelecimento, valor_liquido, categoria,
                confianca_extracao, arquivo_imagem, created_at
         FROM t_despesas
         WHERE observacoes != 'pendente de processamento'
           AND observacoes != 'arquivo não encontrado'
         ORDER BY created_at DESC LIMIT 10"
    )->fetchAll();

} catch (PDOException $e) {
    $pendentes = $processadas = [];
    $msg_acao  = 'Erro de banco: ' . $e->getMessage();
}

// ─── Status do cron ───────────────────────────────────────────────────────
$cron_ativo      = file_exists(CRON_ARQUIVO);
$intervalo       = CRON_INTERVALO_MIN;

// Calcula próxima execução (múltiplo de 15 min)
$agora           = time();
$min_atual       = (int)date('i', $agora);
$min_prox        = (int)(ceil(($min_atual + 1) / $intervalo) * $intervalo);
if ($min_prox >= 60) {
    $prox_exec = mktime(date('H', $agora) + 1, $min_prox - 60, 0);
} else {
    $prox_exec = mktime(date('H', $agora), $min_prox, 0);
}
$prox_exec_str   = date('H:i', $prox_exec);
$faltam_min      = (int)round(($prox_exec - $agora) / 60);

$rotulos_tipo = [
    'cupom_fiscal'  => 'Cupom',
    'danfe'         => 'DANFE',
    'recibo_cartao' => 'Cartão',
    'outro'         => 'Outro',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor — Despesas</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:Arial,sans-serif;background:#f0f2f5;color:#333;padding:16px}
        h1{font-size:1.3rem;margin-bottom:20px;color:#1a1a2e;text-align:center}
        h2{font-size:1rem;margin-bottom:10px;color:#444}
        .card{background:#fff;border-radius:12px;padding:16px;margin-bottom:16px;
              max-width:700px;margin-left:auto;margin-right:auto;
              box-shadow:0 2px 8px rgba(0,0,0,.1)}

        /* Status cron */
        .cron-status{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px}
        .badge{padding:4px 12px;border-radius:20px;font-size:.85rem;font-weight:bold}
        .badge.ativo{background:#d4edda;color:#155724}
        .badge.inativo{background:#f8d7da;color:#721c24}

        /* Próxima execução */
        .prox{background:#e8f0fe;border-radius:8px;padding:10px 14px;
              font-size:.9rem;color:#1a1a2e;margin-bottom:12px}

        /* Botões */
        .btns{display:flex;gap:8px;flex-wrap:wrap}
        button,a.btn{padding:9px 16px;border:none;border-radius:8px;
                     font-size:.9rem;cursor:pointer;text-decoration:none;display:inline-block}
        .verde{background:#28a745;color:#fff}
        .vermelho{background:#dc3545;color:#fff}
        .azul{background:#1a73e8;color:#fff}
        .cinza{background:#f1f1f1;color:#333;border:1px solid #ccc}
        button:active,a.btn:active{opacity:.8}

        /* Mensagem de ação */
        .msg{background:#fff3cd;border:1px solid #ffc107;border-radius:8px;
             padding:10px 14px;margin-bottom:12px;font-size:.9rem;color:#856404}

        /* Tabelas */
        table{width:100%;border-collapse:collapse;font-size:.85rem}
        th{background:#f8f9fa;padding:8px 6px;text-align:left;border-bottom:2px solid #ddd;color:#555}
        td{padding:8px 6px;border-bottom:1px solid #f0f0f0;vertical-align:top}
        tr:hover td{background:#fafafa}
        .vazio{color:#999;font-style:italic;padding:12px 6px}

        /* Confiança */
        .conf{display:inline-block;padding:2px 8px;border-radius:10px;font-size:.8rem}
        .conf.alta{background:#d4edda;color:#155724}
        .conf.media{background:#fff3cd;color:#856404}
        .conf.baixa{background:#f8d7da;color:#721c24}
        .conf.zero{background:#e9ecef;color:#6c757d}

        /* Instruções sudo */
        .instrucoes{background:#f8f9fa;border:1px solid #ddd;border-radius:8px;
                    padding:12px;font-size:.82rem;color:#555;margin-top:10px}
        .instrucoes code{background:#e9ecef;padding:2px 6px;border-radius:4px;font-family:monospace}

        /* Contador pendentes */
        .contador{font-size:1.8rem;font-weight:bold;text-align:center;
                  color:#1a73e8;margin:6px 0}
        .contador-label{font-size:.8rem;color:#999;text-align:center;margin-bottom:12px}

        @media(max-width:480px){
            th:nth-child(4),td:nth-child(4){display:none} /* oculta coluna arquivo em telas pequenas */
        }
    </style>
</head>
<body>

<h1>📊 Monitor de Processamento</h1>

<!-- ── CARD: Agendamento ─────────────────────────────────────────────── -->
<div class="card">
    <h2>⏰ Agendamento (cron)</h2>

    <?php if ($msg_acao): ?>
        <div class="msg"><?= htmlspecialchars($msg_acao) ?></div>
    <?php endif; ?>

    <div class="cron-status">
        <span>Status:</span>
        <span class="badge <?= $cron_ativo ? 'ativo' : 'inativo' ?>">
            <?= $cron_ativo ? '✅ Ativo' : '⛔ Inativo' ?>
        </span>
        <span style="font-size:.85rem;color:#777">
            (exec

