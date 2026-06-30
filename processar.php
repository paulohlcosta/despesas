<?php
// Processamento do upload e registro da despesa no banco

require_once 'config.php';

// ─── Função stub — será implementada futuramente ───────────────────────────
/**
 * Envia a imagem para a API Gemini e retorna os dados extraídos.
 * Por ora retorna array vazio; os campos ficarão NULL no banco.
 *
 * @param string $caminho_arquivo  Caminho absoluto da imagem salva
 * @param string $tipo_documento   Valor do enum (cupom_fiscal, danfe, etc.)
 * @return array
 */
function extrair_dados_gemini(string $caminho_arquivo, string $tipo_documento): array {
    // TODO: implementar chamada à API Gemini
    return [];
}
// ──────────────────────────────────────────────────────────────────────────

// Função auxiliar para exibir erros e voltar ao capturar.php
function erro_e_sair(string $mensagem): never {
    echo '<!DOCTYPE html><html lang="pt-BR"><head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Erro</title>
        <style>
            body { font-family: Arial, sans-serif; background:#f0f2f5;
                   display:flex; align-items:center; justify-content:center;
                   min-height:100vh; padding:16px; }
            .box { background:#fff; border-radius:12px; padding:24px;
                   max-width:420px; width:100%; box-shadow:0 2px 8px rgba(0,0,0,.1);
                   text-align:center; }
            h2 { color:#c0392b; margin-bottom:12px; }
            p  { color:#555; margin-bottom:20px; font-size:.95rem; }
            a  { display:inline-block; padding:12px 24px; background:#1a73e8;
                 color:#fff; border-radius:8px; text-decoration:none; font-size:1rem; }
        </style>
    </head><body><div class="box">
        <h2>⚠️ Ocorreu um erro</h2>
        <p>' . htmlspecialchars($mensagem) . '</p>
        <a href="capturar.php">← Voltar</a>
    </div></body></html>';
    exit;
}

// ─── 1. Verificar método e presença do arquivo ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    erro_e_sair('Acesso direto não permitido. Use o formulário de captura.');
}

if (empty($_FILES['arquivo_imagem']) || $_FILES['arquivo_imagem']['error'] !== UPLOAD_ERR_OK) {
    $codigos_erro = [
        UPLOAD_ERR_INI_SIZE   => 'Arquivo excede o limite do servidor (upload_max_filesize).',
        UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede o limite do formulário.',
        UPLOAD_ERR_PARTIAL    => 'Upload incompleto. Tente novamente.',
        UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo foi enviado.',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente no servidor.',
        UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo temporário.',
        UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão PHP.',
    ];
    $codigo = $_FILES['arquivo_imagem']['error'] ?? UPLOAD_ERR_NO_FILE;
    erro_e_sair($codigos_erro[$codigo] ?? 'Erro desconhecido no upload.');
}

$arquivo    = $_FILES['arquivo_imagem'];
$tipo_doc   = $_POST['tipo_documento'] ?? 'outro';

// ─── 2. Validar tipo de documento ─────────────────────────────────────────
$tipos_validos = ['cupom_fiscal', 'danfe', 'recibo_cartao', 'outro'];
if (!in_array($tipo_doc, $tipos_validos, true)) {
    erro_e_sair('Tipo de documento inválido.');
}

// ─── 3. Validar tipo MIME real do arquivo (não confia no nome/extensão) ───
$finfo     = new finfo(FILEINFO_MIME_TYPE);
$mime_real = $finfo->file($arquivo['tmp_name']);

if (!in_array($mime_real, TIPOS_PERMITIDOS, true)) {
    erro_e_sair("Formato não permitido ($mime_real). Envie JPG, PNG ou WEBP.");
}

// ─── 4. Validar tamanho ───────────────────────────────────────────────────
if ($arquivo['size'] > MAX_TAMANHO_UPLOAD) {
    $mb = number_format($arquivo['size'] / 1024 / 1024, 1);
    erro_e_sair("Arquivo muito grande ({$mb}MB). O limite é 10MB.");
}

// ─── 5. Garantir existência da pasta de uploads ───────────────────────────
if (!is_dir(PASTA_UPLOADS)) {
    if (!mkdir(PASTA_UPLOADS, 0755, true)) {
        erro_e_sair('Não foi possível criar a pasta de uploads no servidor.');
    }
}

// ─── 6. Gerar nome do arquivo com timestamp ───────────────────────────────
$extensoes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];
$extensao      = $extensoes[$mime_real];
$nome_original = pathinfo($arquivo['name'], PATHINFO_FILENAME);
// Sanitiza o nome original: mantém apenas alfanuméricos e hífens
$nome_sanitizado = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nome_original);
$timestamp        = date('Ymd_His');
$nome_final       = "{$timestamp}_{$nome_sanitizado}.{$extensao}";
$caminho_destino  = PASTA_UPLOADS . $nome_final;

// ─── 7. Mover arquivo para destino final ──────────────────────────────────
if (!move_uploaded_file($arquivo['tmp_name'], $caminho_destino)) {
    erro_e_sair('Falha ao salvar o arquivo no servidor. Verifique as permissões da pasta uploads/.');
}

// ─── 8. Chamar extração de dados (stub por ora) ───────────────────────────
$dados = extrair_dados_gemini($caminho_destino, $tipo_doc);

// ─── 9. Preparar valores para inserção ───────────────────────────────────
// Campos extraídos pelo Gemini (ou NULL se o array vier vazio)
$chave_acesso         = $dados['chave_acesso']         ?? null;
$data_emissao         = $dados['data_emissao']         ?? null; // formato: 'YYYY-MM-DD HH:MM:SS'
$cnpj_emitente        = $dados['cnpj_emitente']        ?? null;
$nome_estabelecimento = $dados['nome_estabelecimento'] ?? null;
$valor_bruto          = $dados['valor_bruto']          ?? null;
$desconto             = $dados['desconto']             ?? 0.00;
$valor_liquido        = $dados['valor_liquido']        ?? null;
$forma_pagamento      = $dados['forma_pagamento']      ?? null;
$categoria            = $dados['categoria']            ?? null;
$confianca_extracao   = $dados['confianca_extracao']   ?? 0;
$observacoes          = $dados['observacoes']          ?? null;

// data_emissao é NOT NULL na tabela — usa NOW() se não foi extraída
$data_emissao_sql = $data_emissao ?? date('Y-m-d H:i:s');

// nome_estabelecimento é NOT NULL — usa placeholder se não extraído
if ($nome_estabelecimento === null) {
    $nome_estabelecimento = '(aguardando extração)';
}

// valor_bruto e valor_liquido são NOT NULL — usa 0.00 se não extraídos
$valor_bruto   = $valor_bruto   ?? 0.00;
$valor_liquido = $valor_liquido ?? 0.00;

// ─── 10. Conectar ao banco via PDO e inserir registro ────────────────────
try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_BANCO . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $sql = "INSERT INTO t_despesas (
                tipo_documento, arquivo_imagem, chave_acesso,
                data_emissao, cnpj_emitente, nome_estabelecimento,
                valor_bruto, desconto, valor_liquido,
                forma_pagamento, categoria, confianca_extracao,
                revisado, observacoes
            ) VALUES (
                :tipo_documento, :arquivo_imagem, :chave_acesso,
                :data_emissao, :cnpj_emitente, :nome_estabelecimento,
                :valor_bruto, :desconto, :valor_liquido,
                :forma_pagamento, :categoria, :confianca_extracao,
                0, :observacoes
            )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tipo_documento'       => $tipo_doc,
        ':arquivo_imagem'       => $nome_final,
        ':chave_acesso'         => $chave_acesso,
        ':data_emissao'         => $data_emissao_sql,
        ':cnpj_emitente'        => $cnpj_emitente,
        ':nome_estabelecimento' => $nome_estabelecimento,
        ':valor_bruto'          => $valor_bruto,
        ':desconto'             => $desconto,
        ':valor_liquido'        => $valor_liquido,
        ':forma_pagamento'      => $forma_pagamento,
        ':categoria'            => $categoria,
        ':confianca_extracao'   => $confianca_extracao,
        ':observacoes'          => $observacoes,
    ]);

    $id_inserido = $pdo->lastInsertId();

} catch (PDOException $e) {
    // Remove o arquivo salvo se o banco falhar (evita arquivos órfãos)
    @unlink($caminho_destino);
    erro_e_sair('Erro ao salvar no banco de dados: ' . $e->getMessage());
}

// ─── 11. Tela de confirmação ─────────────────────────────────────────────
$extracao_ok   = !empty($dados);
$url_imagem    = 'uploads/' . urlencode($nome_final);

$rotulos_tipo  = [
    'cupom_fiscal' => 'Cupom Fiscal',
    'danfe'        => 'DANFE',
    'recibo_cartao'=> 'Recibo de Cartão',
    'outro'        => 'Outro',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Despesa Registrada</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, sans-serif;
            background: #f0f2f5;
            padding: 16px;
            color: #333;
        }
        h1 { font-size: 1.3rem; text-align: center; margin-bottom: 20px; color: #1a1a2e; }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            max-width: 480px;
            margin: 0 auto;
            box-shadow: 0 2px 8px rgba(0,0,0,.1);
        }
        .status {
            text-align: center;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: bold;
            font-size: .95rem;
        }
        .status.ok      { background: #d4edda; color: #155724; }
        .status.pendente{ background: #fff3cd; color: #856404; }

        .preview-img {
            width: 100%;
            max-height: 260px;
            object-fit: contain;
            border-radius: 8px;
            border: 1px solid #ddd;
            margin-bottom: 16px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .9rem;
            margin-bottom: 20px;
        }
        td {
            padding: 8px 6px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: top;
        }
        td:first-child { color: #777; width: 45%; }
        td:last-child   { font-weight: bold; }
        .null-val { color: #bbb; font-weight: normal; font-style: italic; }

        .btn {
            display: block;
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            margin-bottom: 10px;
        }
        .btn-primario   { background: #1a73e8; color: #fff; }
        .btn-secundario { background: #f1f1f1; color: #333; border: 1px solid #ccc; }
    </style>
</head>
<body>

<h1>✅ Despesa Registrada</h1>

<div class="card">

    <!-- Status da extração -->
    <?php if ($extracao_ok): ?>
        <div class="status ok">Dados extraídos com sucesso (ID #<?= $id_inserido ?>)</div>
    <?php else: ?>
        <div class="status pendente">⏳ Aguardando extração de dados (ID #<?= $id_inserido ?>)</div>
    <?php endif; ?>

    <!-- Preview da imagem enviada -->
    <img class="preview-img" src="<?= htmlspecialchars($url_imagem) ?>" alt="Documento enviado">

    <!-- Tabela de dados extraídos -->
    <table>
        <tr>
            <td>Tipo</td>
            <td><?= htmlspecialchars($rotulos_tipo[$tipo_doc] ?? $tipo_doc) ?></td>
        </tr>
        <tr>
            <td>Estabelecimento</td>
            <td><?= $nome_estabelecimento !== '(aguardando extração)'
                    ? htmlspecialchars($nome_estabelecimento)
                    : '<span class="null-val">aguardando extração</span>' ?></td>
        </tr>
        <tr>
            <td>Data emissão</td>
            <td><?= $data_emissao
                    ? date('d/m/Y H:i', strtotime($data_emissao))
                    : '<span class="null-val">não extraída</span>' ?></td>
        </tr>
        <tr>
            <td>CNPJ emitente</td>
            <td><?= $cnpj_emitente
                    ? htmlspecialchars($cnpj_emitente)
                    : '<span class="null-val">não extraído</span>' ?></td>
        </tr>
        <tr>
            <td>Valor bruto</td>
            <td><?= $valor_bruto > 0
                    ? 'R$ ' . number_format($valor_bruto, 2, ',', '.')
                    : '<span class="null-val">não extraído</span>' ?></td>
        </tr>
        <tr>
            <td>Desconto</td>
            <td>R$ <?= number_format($desconto, 2, ',', '.') ?></td>
        </tr>
        <tr>
            <td>Valor líquido</td>
            <td><?= $valor_liquido > 0
                    ? 'R$ ' . number_format($valor_liquido, 2, ',', '.')
                    : '<span class="null-val">não extraído</span>' ?></td>
        </tr>
        <tr>
            <td>Forma pagamento</td>
            <td><?= $forma_pagamento
                    ? htmlspecialchars($forma_pagamento)
                    : '<span class="null-val">não extraída</span>' ?></td>
        </tr>
        <tr>
            <td>Categoria</td>
            <td><?= $categoria
                    ? htmlspecialchars($categoria)
                    : '<span class="null-val">não extraída</span>' ?></td>
        </tr>
        <tr>
            <td>Arquivo salvo</td>
            <td><?= htmlspecialchars($nome_final) ?></td>
        </tr>
    </table>

    <!-- Ações -->
    <a href="capturar.php" class="btn btn-primario">📷 Registrar outra despesa</a>
    <a href="capturar.php" class="btn btn-secundario">← Voltar</a>

</div>

</body>
</html>