<?php
// Recebe o upload, valida, salva com timestamp e registra no banco como pendente.
// O Gemini é chamado pelo worker em background (processar_worker.php).

require_once 'config.php';

// ─── Helpers ───────────────────────────────────────────────────────────────
function erro_e_sair(string $msg): never {
    echo '<!DOCTYPE html><html lang="pt-BR"><head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Erro</title>
        <style>
            body{font-family:Arial,sans-serif;background:#f0f2f5;display:flex;
                 align-items:center;justify-content:center;min-height:100vh;padding:16px}
            .box{background:#fff;border-radius:12px;padding:24px;max-width:420px;
                 width:100%;box-shadow:0 2px 8px rgba(0,0,0,.1);text-align:center}
            h2{color:#c0392b;margin-bottom:12px} p{color:#555;margin-bottom:20px;font-size:.95rem}
            a{display:inline-block;padding:12px 24px;background:#1a73e8;
              color:#fff;border-radius:8px;text-decoration:none}
        </style></head><body><div class="box">
        <h2>⚠️ Erro</h2>
        <p>' . htmlspecialchars($msg) . '</p>
        <a href="capturar.php">← Voltar</a>
    </div></body></html>';
    exit;
}

/**
 * Gera nome de arquivo único com timestamp, adicionando sufixo _a, _b...
 * em caso de colisão na pasta de uploads.
 */
function gerar_nome_unico(string $extensao): string {
    $base    = date('Ymd_His');
    $sufixos = array_merge([''], range('a', 'z'));
    foreach ($sufixos as $s) {
        $nome = $base . ($s !== '' ? "_$s" : '') . '.' . $extensao;
        if (!file_exists(PASTA_UPLOADS . $nome)) return $nome;
    }
    // Fallback com microssegundos (praticamente impossível chegar aqui)
    return $base . '_' . substr(microtime(false), 2, 6) . '.' . $extensao;
}

// ─── 1. Método e presença do arquivo ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    erro_e_sair('Acesso direto não permitido.');
}

$erro_upload = $_FILES['arquivo_imagem']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($erro_upload !== UPLOAD_ERR_OK) {
    $msgs = [
        UPLOAD_ERR_INI_SIZE   => 'Arquivo excede upload_max_filesize do servidor.',
        UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede limite do formulário.',
        UPLOAD_ERR_PARTIAL    => 'Upload incompleto. Tente novamente.',
        UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo enviado.',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente.',
        UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo temporário.',
    ];
    erro_e_sair($msgs[$erro_upload] ?? 'Erro desconhecido no upload.');
}

$arquivo  = $_FILES['arquivo_imagem'];
$tipo_doc = $_POST['tipo_documento'] ?? 'outro';
$categoria_manual = trim($_POST['categoria'] ?? '');

// ─── 2. Tipo de documento ──────────────────────────────────────────────────
$tipos_validos = ['cupom_fiscal','danfe','recibo_cartao','outro'];
if (!in_array($tipo_doc, $tipos_validos, true)) {
    erro_e_sair('Tipo de documento inválido.');
}

// ─── 3. MIME real ──────────────────────────────────────────────────────────
$finfo    = new finfo(FILEINFO_MIME_TYPE);
$mime     = $finfo->file($arquivo['tmp_name']);
if (!in_array($mime, TIPOS_PERMITIDOS, true)) {
    erro_e_sair("Formato não permitido ($mime). Use JPG, PNG ou WEBP.");
}

// ─── 4. Tamanho ────────────────────────────────────────────────────────────
if ($arquivo['size'] > MAX_TAMANHO_UPLOAD) {
    $mb = number_format($arquivo['size'] / 1024 / 1024, 1);
    erro_e_sair("Arquivo muito grande ({$mb} MB). Limite: 10 MB.");
}

// ─── 5. Pasta de uploads ───────────────────────────────────────────────────
if (!is_dir(PASTA_UPLOADS) && !mkdir(PASTA_UPLOADS, 0755, true)) {
    erro_e_sair('Não foi possível criar a pasta uploads/.');
}

// ─── 6. Nome único com timestamp ───────────────────────────────────────────
$ext        = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];
$nome_final = gerar_nome_unico($ext);
$destino    = PASTA_UPLOADS . $nome_final;

if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
    erro_e_sair('Falha ao salvar o arquivo. Verifique permissões da pasta uploads/.');
}

// ─── 7. Inserir no banco como pendente (confianca_extracao = 0, revisado = 0) ─
try {
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_BANCO.';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->prepare("INSERT INTO t_despesas
        (tipo_documento, arquivo_imagem, data_emissao, nome_estabelecimento,
         valor_bruto, valor_liquido, categoria, confianca_extracao, revisado, observacoes)
        VALUES
        (:tipo, :arquivo, NOW(), '(aguardando extração)',
         0.00, 0.00, :categoria, 0, 0, 'pendente de processamento')");

    $stmt->execute([
        ':tipo'      => $tipo_doc,
        ':arquivo'   => $nome_final,
        ':categoria' => $categoria_manual !== '' ? $categoria_manual : null,
    ]);

    $id = $pdo->lastInsertId();

} catch (PDOException $e) {
    @unlink($destino);
    erro_e_sair('Erro ao registrar no banco: ' . $e->getMessage());
}

// ─── 8. Confirmação ────────────────────────────────────────────────────────
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
    <title>Foto Registrada</title>
    <style>
        *{box-sizing:border-box;margin:0;padding:0}
        body{font-family:Arial,sans-serif;background:#f0f2f5;padding:16px;color:#333}
        h1{font-size:1.3rem;text-align:center;margin-bottom:20px;color:#1a1a2e}
        .card{background:#fff;border-radius:12px;padding:20px;max-width:480px;
              margin:0 auto;box-shadow:0 2px 8px rgba(0,0,0,.1)}
        .status{text-align:center;padding:10px;border-radius:8px;margin-bottom:16px;
                font-weight:bold;background:#fff3cd;color:#856404}
        img{width:100%;max-height:260px;object-fit:contain;border-radius:8px;
            border:1px solid #ddd;margin-bottom:16px}
        table{width:100%;border-collapse:collapse;font-size:.9rem;margin-bottom:20px}
        td{padding:8px 6px;border-bottom:1px solid #f0f0f0;vertical-align:top}
        td:first-child{color:#777;width:45%}
        .btn{display:block;width:100%;padding:13px;border:none;border-radius:8px;
             font-size:1rem;cursor:pointer;text-align:center;text-decoration:none;margin-bottom:10px}
        .azul{background:#1a73e8;color:#fff}
        .cinza{background:#f1f1f1;color:#333;border:1px solid #ccc}
    </style>
</head>
<body>
<h1>✅ Foto Recebida</h1>
<div class="card">
    <div class="status">⏳ Na fila — extração ocorrerá em até <?= CRON_INTERVALO_MIN ?> min (ID #<?= $id ?>)</div>
    <img src="uploads/<?= htmlspecialchars($nome_final) ?>" alt="Documento">
    <table>
        <tr><td>Tipo</td><td><?= htmlspecialchars($rotulos[$tipo_doc]) ?></td></tr>
        <tr><td>Categoria</td><td><?= $categoria_manual !== '' ? htmlspecialchars($categoria_manual) : '<em style="color:#bbb">a definir</em>' ?></td></tr>
        <tr><td>Arquivo</td><td><?= htmlspecialchars($nome_final) ?></td></tr>
    </table>
    <a href="capturar.php" class="btn azul">📷 Registrar outra despesa</a>
    <a href="monitor.php" class="btn cinza">📊 Ver fila de processamento</a>
</div>
</body>
</html>