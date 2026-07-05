<?php
require_once 'config.php';

$mensagem_cron = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['acao'])) {

        if ($_POST['acao'] === 'processar_agora') {
            $script = __DIR__ . '/processar_gemini.php';
            $log    = __DIR__ . '/logs/cron.log';
            shell_exec("nohup /usr/bin/php $script >> $log 2>&1 &");
            $mensagem_cron = 'Processamento iniciado em background. Acompanhe o log.';
            $tipo_mensagem = 'info';
        }

        if ($_POST['acao'] === 'salvar_cron') {
            $php_bin   = trim(shell_exec('which php'));
            $script    = __DIR__ . '/processar_gemini.php';
            $log       = __DIR__ . '/logs/cron.log';
            $intervalo = INTERVALO_CRON_MINUTOS;
            $linha_cron = "*/$intervalo * * * * $php_bin $script >> $log 2>&1";

            $crontab_atual = shell_exec('crontab -l 2>/dev/null') ?? '';

            if (str_contains($crontab_atual, 'processar_gemini.php')) {
                $mensagem_cron = 'Cron já estava configurado. Nenhuma alteração feita.';
                $tipo_mensagem = 'info';
            } else {
                $novo_crontab = rtrim($crontab_atual) . "\n" . $linha_cron . "\n";
                $tmp = tempnam(sys_get_temp_dir(), 'cron_');
                file_put_contents($tmp, $novo_crontab);
                shell_exec("crontab $tmp");
                unlink($tmp);
                $mensagem_cron = "Cron agendado: a cada {$intervalo} minutos.";
                $tipo_mensagem = 'ok';
            }
        }

        if ($_POST['acao'] === 'remover_cron') {
            $crontab_atual = shell_exec('crontab -l 2>/dev/null') ?? '';
            $linhas = explode("\n", $crontab_atual);
            $filtrado = array_filter($linhas, fn($l) => !str_contains($l, 'processar_gemini.php'));
            $novo_crontab = implode("\n", $filtrado);
            $tmp = tempnam(sys_get_temp_dir(), 'cron_');
            file_put_contents($tmp, $novo_crontab);
            shell_exec("crontab $tmp");
            unlink($tmp);
            $mensagem_cron = 'Cron removido.';
            $tipo_mensagem = 'aviso';
        }
    }
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_BANCO . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pendentes = $pdo->query(
        "SELECT id, tipo_documento, arquivo_imagem, created_at
         FROM t_despesas
         WHERE revisado = 0
         ORDER BY created_at ASC"
    )->fetchAll();

    $total_pendentes    = count($pendentes);
    $total_processados  = $pdo->query("SELECT COUNT(*) FROM t_despesas WHERE revisado = 1")->fetchColumn();
    $total_geral        = $pdo->query("SELECT COUNT(*) FROM t_despesas")->fetchColumn();

} catch (PDOException $e) {
    die('Erro BD: ' . $e->getMessage());
}

$crontab_atual  = shell_exec('crontab -l 2>/dev/null') ?? '';
$cron_ativo     = str_contains($crontab_atual, 'processar_gemini.php');

$proximo_exec = null;
if ($cron_ativo) {
    $agora        = time();
    $intervalo_s  = INTERVALO_CRON_MINUTOS * 60;
    $proximo_exec = date('H:i', $agora + ($intervalo_s - ($agora % $intervalo_s)));
}

// --- Proxy LM Studio (chamadas server-side) ---
if (isset($_GET['lm_action'])) {
    header('Content-Type: application/json');

    function lm_request(string $path, string $method = 'GET', mixed $body = null): array {
        $url = LLM_HOST . $path;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $resp];
    }

    $action = $_GET['lm_action'];

    if ($action === 'list') {
        $r = lm_request('/api/v1/models');
        echo $r['body'];

    } elseif ($action === 'load' && isset($_POST['model'])) {
        $r = lm_request('/api/v1/models/load', 'POST', ['model' => $_POST['model']]);
        echo $r['body'];

    } elseif ($action === 'unload' && isset($_POST['instance_id'])) {
        $r = lm_request('/api/v1/models/unload', 'POST', ['instance_id' => $_POST['instance_id']]);
        echo $r['body'];
    } else {
        echo json_encode(['error' => 'ação inválida']);
    }

    exit;
}

$rotulos = [
    'cupom_fiscal'  => 'Cupom Fiscal',
    'danfe'         => 'DANFE',
    'recibo_cartao' => 'Recibo de Cartão',
    'outro'         => 'Outro',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor de Processamento</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 16px; color: #333; }
        h1 { font-size: 1.3rem; text-align: center; margin-bottom: 20px; color: #1a1a2e; }
        .card { background: #fff; border-radius: 12px; padding: 20px; max-width: 720px; margin: 0 auto 16px; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        h2 { font-size: 1rem; margin-bottom: 12px; color: #444; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        .stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 4px; }
        .stat { flex: 1; min-width: 100px; background: #f8f9fa; border-radius: 8px; padding: 12px; text-align: center; }
        .stat .num { font-size: 1.8rem; font-weight: bold; color: #1a73e8; }
        .stat .leg { font-size: 0.75rem; color: #777; margin-top: 4px; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: .78rem; font-weight: bold; }
        .badge-pend { background: #fff3cd; color: #856404; }
        .badge-ok   { background: #d4edda; color: #155724; }
        table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        th { background: #f8f9fa; padding: 8px 6px; text-align: left; color: #555; font-size: .8rem; }
        td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        .thumb { width: 48px; height: 48px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; }
        .btn { display: inline-block; padding: 10px 18px; border: none; border-radius: 8px; font-size: .9rem; cursor: pointer; text-decoration: none; }
        .btn-azul   { background: #1a73e8; color: #fff; }
        .btn-verde  { background: #28a745; color: #fff; }
        .btn-verm   { background: #dc3545; color: #fff; }
        .btn-cinza  { background: #f1f1f1; color: #333; border: 1px solid #ccc; }
        .acoes { display: flex; gap: 10px; flex-wrap: wrap; }
        .msg { padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: .9rem; }
        .msg-ok    { background: #d4edda; color: #155724; }
        .msg-aviso { background: #fff3cd; color: #856404; }
        .msg-info  { background: #d1ecf1; color: #0c5460; }
        .cron-status { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
        .dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
        .dot-verde { background: #28a745; }
        .dot-verm  { background: #dc3545; }
        .proximo { font-size: .85rem; color: #555; }
        .vazio { text-align: center; color: #aaa; padding: 24px; font-size: .95rem; }
        @media(max-width:480px) { .stats { flex-direction: column; } }
    </style>
</head>
<body>
<h1>📊 Monitor de Processamento</h1>

<div class="card">
    <h2>Resumo</h2>
    <div class="stats">
        <div class="stat"><div class="num"><?= $total_pendentes ?></div><div class="leg">Pendentes</div></div>
        <div class="stat"><div class="num"><?= $total_processados ?></div><div class="leg">Processados</div></div>
        <div class="stat"><div class="num"><?= $total_geral ?></div><div class="leg">Total</div></div>
    </div>
</div>

<div class="card">
    <h2>Agendamento (Cron)</h2>

    <?php if ($mensagem_cron): ?>
        <div class="msg msg-<?= $tipo_mensagem ?>"><?= htmlspecialchars($mensagem_cron) ?></div>
    <?php endif; ?>

    <div class="cron-status">
        <div class="dot <?= $cron_ativo ? 'dot-verde' : 'dot-verm' ?>"></div>
        <span><?= $cron_ativo ? 'Cron ativo — executa a cada ' . INTERVALO_CRON_MINUTOS . ' minutos' : 'Cron não configurado' ?></span>
        <?php if ($cron_ativo && $proximo_exec): ?>
            <span class="proximo">Próxima execução aproximada: <strong><?= $proximo_exec ?></strong></span>
        <?php endif; ?>
    </div>

    <div class="acoes">
        <form method="POST" style="display:inline">
            <input type="hidden" name="acao" value="processar_agora">
            <button class="btn btn-azul" type="submit">▶ Processar agora</button>
        </form>
        <?php if (!$cron_ativo): ?>
            <form method="POST" style="display:inline">
                <input type="hidden" name="acao" value="salvar_cron">
                <button class="btn btn-verde" type="submit">⏱ Ativar agendamento</button>
            </form>
        <?php else: ?>
            <form method="POST" style="display:inline">
                <input type="hidden" name="acao" value="remover_cron">
                <button class="btn btn-verm" type="submit">✖ Remover agendamento</button>
            </form>
        <?php endif; ?>
        <a href="monitor.php" class="btn btn-cinza">↻ Atualizar</a>
    </div>
</div>

<div class="card">
    <h2>Fotos pendentes (<?= $total_pendentes ?>)</h2>

    <?php if (empty($pendentes)): ?>
        <div class="vazio">Nenhuma foto aguardando processamento.</div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Foto</th>
                    <th>ID</th>
                    <th>Tipo</th>
                    <th>Arquivo</th>
                    <th>Recebido em</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pendentes as $r): ?>
                <tr>
                    <td>
                        <img
                            class="thumb"
                            src="uploads/<?= urlencode($r['arquivo_imagem']) ?>"
                            alt="thumb"
                            onerror="this.style.display='none'"
                        >
                    </td>
                    <td>#<?= $r['id'] ?></td>
                    <td><span class="badge badge-pend"><?= htmlspecialchars($rotulos[$r['tipo_documento']] ?? $r['tipo_documento']) ?></span></td>
                    <td><?= htmlspecialchars($r['arquivo_imagem']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div style="text-align:center;margin-top:8px;">
    <a href="capturar.php" class="btn btn-cinza">← Capturar nova despesa</a>
</div>
    
<div class="card">
    <h2>LM Studio — Modelos</h2>
    <div id="lm-status" style="font-size:.85rem;color:#555;margin-bottom:10px;">—</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <select id="lm-select" style="flex:1;padding:8px;border-radius:6px;border:1px solid #ccc;font-size:.9rem;">
            <option value="">-- clique em Listar --</option>
        </select>
        <button class="btn btn-cinza" onclick="lmList()">↺ Listar</button>
        <button class="btn btn-verde" onclick="lmAction('load')">▶ Carregar</button>
        <button class="btn btn-verm" onclick="lmAction('unload')">■ Descarregar</button>
    </div>
</div>

<script>
const lmSel = document.getElementById('lm-select');
const lmSt  = document.getElementById('lm-status');
const instanceMap = {};

function lmSetStatus(msg, color = '#555') {
    lmSt.style.color = color;
    lmSt.textContent = msg;
}

async function lmList() {
    lmSetStatus('buscando modelos...');
    try {
        const res  = await fetch('monitor.php?lm_action=list');
        const data = await res.json();
        const models = data.models || [];

        Object.keys(instanceMap).forEach(k => delete instanceMap[k]);

        const prev = lmSel.value;
        lmSel.innerHTML = models.map(m => {
            const loaded = m.loaded_instances?.length > 0;
            if (loaded) instanceMap[m.key] = m.loaded_instances[0].id;
            const dot   = loaded ? '●' : '○';
            const color = loaded ? '#1a7a1a' : '#888';
            const label = `${dot} ${m.key} [${m.quantization?.name || '?'}] ${m.params_string || ''}`;
            return `<option value="${m.key}" data-loaded="${loaded}" style="color:${color}">${label}</option>`;
        }).join('');

        if ([...lmSel.options].find(o => o.value === prev)) lmSel.value = prev;
        else {
            const first = [...lmSel.options].find(o => o.dataset.loaded === 'true');
            if (first) lmSel.value = first.value;
        }

        const nLoaded = models.filter(m => m.loaded_instances?.length > 0).length;
        lmSetStatus(`${models.length} modelo(s) — ${nLoaded} carregado(s)`, '#1a7a1a');
    } catch (e) {
        lmSetStatus('erro ao listar — verifique se o LM Studio está acessível', '#c00');
    }
}

async function lmAction(action) {
    const key = lmSel.value;
    if (!key) return lmSetStatus('selecione um modelo', '#c80');

    const body = new FormData();

    if (action === 'load') {
        body.append('model', key);
    } else {
        const iid = instanceMap[key];
        if (!iid) return lmSetStatus('modelo não está carregado', '#c80');
        body.append('instance_id', iid);
    }

    lmSetStatus((action === 'load' ? 'carregando' : 'descarregando') + ': ' + key);
    try {
        const res = await fetch(`monitor.php?lm_action=${action}`, { method: 'POST', body });
        if (res.ok) {
            lmSetStatus(action === 'load' ? 'carregado ✓' : 'descarregado ✓', '#1a7a1a');
            setTimeout(lmList, 800);
        } else {
            const txt = await res.text();
            lmSetStatus(`erro ${res.status}: ${txt}`, '#c00');
        }
    } catch (e) {
        lmSetStatus('falha: ' + e.message, '#c00');
    }
}

lmList();
</script>
    
</body>
</html>
