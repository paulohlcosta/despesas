<?php
require_once __DIR__ . '/config.php';

function extrair_dados_gemini(string $caminho_arquivo, string $tipo_documento, array $categorias): array {

    $lista_categorias = implode(', ', $categorias);

    $prompt = <<<PROMPT
Você é um extrator de dados de documentos fiscais brasileiros.
Analise a imagem e retorne SOMENTE um JSON válido, sem texto adicional, sem markdown, sem blocos de código.

Tipo de documento esperado: {$tipo_documento}
Categorias disponíveis: {$lista_categorias}
        
Campos obrigatórios: data_emissao, valor_liquido, nome_estabelecimento.
Os demais campos são opcionais — retorne null se não encontrar a informação na imagem.

Campos do JSON:
{
  "chave_acesso": "string ou null",
  "data_emissao": "YYYY-MM-DD HH:MM:SS ou null",
  "cnpj_emitente": "string só dígitos ou null",
  "nome_estabelecimento": "string",
  "valor_bruto": "número ou null",
  "desconto": "número ou null",
  "valor_liquido": número,
  "forma_pagamento": "string ou null",
  "categoria": "uma das categorias listadas ou null",
  "confianca_extracao": número de 0 a 100,
  "observacoes": "string ou null"
}
PROMPT;

    // Codifica a imagem em base64
    $imagem_b64 = base64_encode(file_get_contents($caminho_arquivo));
    $mime       = mime_content_type($caminho_arquivo);

    $payload = [
        'model'       => LLM_MODEL,
        'temperature' => 0.1,
        'messages'    => [
            [
                'role'    => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                    [
                        'type'      => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mime};base64,{$imagem_b64}",
                        ],
                    ],
                ],
            ],
        ],
    ];

    $ch = curl_init(LLM_HOST . '/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => LLM_TIMEOUT,
    ]);

    $resposta_raw = curl_exec($ch);
    $erro_curl    = curl_error($ch);
    curl_close($ch);

    if ($erro_curl) {
        log_msg("cURL erro: $erro_curl");
        return [];
    }

    $resposta = json_decode($resposta_raw, true);
    $conteudo = $resposta['choices'][0]['message']['content'] ?? '';

    // Remove possíveis blocos markdown que o modelo insira mesmo pedindo para não
    $conteudo = preg_replace('/^```(?:json)?\s*/i', '', trim($conteudo));
    $conteudo = preg_replace('/\s*```$/', '', $conteudo);

    $dados = json_decode(trim($conteudo), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        log_msg("JSON inválido recebido: " . substr($conteudo, 0, 300));
        return [];
    }

    return $dados;
}

function carregar_categorias(): array {
    if (!file_exists(ARQUIVO_CATEGORIAS)) return [];
    $linhas = file(ARQUIVO_CATEGORIAS, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    return array_map('trim', $linhas);
}

function log_msg(string $msg): void {
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(__DIR__ . '/logs/gemini.log', $linha, FILE_APPEND);
}

set_time_limit(600);
ini_set('max_execution_time', 600);

$lockfile = __DIR__ . '/gemini.lock';
if (file_exists($lockfile)) {
    $idade = time() - filemtime($lockfile);
    if ($idade < 600) { // 10 minutos
        log_msg('Outra instância em execução, abortando.');
        exit(0);
    }
}
file_put_contents($lockfile, getmypid());

$dir_logs = __DIR__ . '/logs';
if (!is_dir($dir_logs)) mkdir($dir_logs, 0755, true);

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_BANCO . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    log_msg('ERRO conexão BD: ' . $e->getMessage());
    exit(1);
}

$categorias = carregar_categorias();

$stmt = $pdo->query(
    "SELECT id, arquivo_imagem, tipo_documento FROM t_despesas
     WHERE revisado = 0
       AND arquivo_imagem NOT LIKE '\\_\\_%'
     ORDER BY created_at ASC"
);
$pendentes = $stmt->fetchAll();

if (empty($pendentes)) {
    log_msg('Nenhum registro pendente.');
    exit(0);
}

foreach ($pendentes as $registro) {
    $id            = $registro['id'];
    $nome_arquivo  = $registro['arquivo_imagem'];
    $tipo_doc      = $registro['tipo_documento'];
    $caminho       = PASTA_UPLOADS . $nome_arquivo;

    if (!file_exists($caminho)) {
        log_msg("ID $id: arquivo não encontrado ($nome_arquivo), pulando.");
        continue;
    }

    log_msg("ID $id: iniciando extração de $nome_arquivo");

    $dados = extrair_dados_gemini($caminho, $tipo_doc, $categorias);

    if (empty($dados)) {
        log_msg("ID $id: extração retornou vazio (stub), mantendo pendente.");
        continue;
    }

    $chave_acesso         = $dados['chave_acesso']         ?? null;
    $data_emissao         = $dados['data_emissao']         ?? date('Y-m-d H:i:s');
    $cnpj_emitente        = $dados['cnpj_emitente']        ?? null;
    $nome_estabelecimento = $dados['nome_estabelecimento'] ?? '(não identificado)';
    $valor_bruto          = $dados['valor_bruto']          ?? 0.00;
    $desconto             = $dados['desconto']             ?? 0.00;
    $valor_liquido        = $dados['valor_liquido']        ?? 0.00;
    $forma_pagamento      = $dados['forma_pagamento']      ?? null;
    $categoria            = $dados['categoria']            ?? null;
    $confianca            = $dados['confianca_extracao']   ?? 0;
    $observacoes          = $dados['observacoes']          ?? null;
    
    $valor_bruto   = isset($dados['valor_bruto'])   ? (float) str_replace(',', '.', $dados['valor_bruto'])   : 0.00;
    $desconto      = isset($dados['desconto'])       ? (float) str_replace(',', '.', $dados['desconto'])      : 0.00;
    $valor_liquido = isset($dados['valor_liquido'])  ? (float) str_replace(',', '.', $dados['valor_liquido']) : 0.00;
    $cnpj_emitente = isset($dados['cnpj_emitente'])
        ? preg_replace('/\D/', '', $dados['cnpj_emitente'])
        : null;
    
    try {
        $upd = $pdo->prepare(
            "UPDATE t_despesas SET
                chave_acesso         = :chave_acesso,
                data_emissao         = :data_emissao,
                cnpj_emitente        = :cnpj_emitente,
                nome_estabelecimento = :nome_estabelecimento,
                valor_bruto          = :valor_bruto,
                desconto             = :desconto,
                valor_liquido        = :valor_liquido,
                forma_pagamento      = :forma_pagamento,
                categoria            = :categoria,
                confianca_extracao   = :confianca,
                observacoes          = :observacoes,
                revisado             = 1
             WHERE id = :id"
        );
        $upd->execute([
            ':chave_acesso'         => $chave_acesso,
            ':data_emissao'         => $data_emissao,
            ':cnpj_emitente'        => $cnpj_emitente,
            ':nome_estabelecimento' => $nome_estabelecimento,
            ':valor_bruto'          => $valor_bruto,
            ':desconto'             => $desconto,
            ':valor_liquido'        => $valor_liquido,
            ':forma_pagamento'      => $forma_pagamento,
            ':categoria'            => $categoria,
            ':confianca'            => $confianca,
            ':observacoes'          => $observacoes,
            ':id'                   => $id,
        ]);

        $novo_nome    = '__' . $nome_arquivo;
        $novo_caminho = PASTA_UPLOADS . $novo_nome;
        rename($caminho, $novo_caminho);

        $pdo->prepare("UPDATE t_despesas SET arquivo_imagem = :nome WHERE id = :id")
            ->execute([':nome' => $novo_nome, ':id' => $id]);

        log_msg("ID $id: processado com sucesso. Arquivo renomeado para $novo_nome");

    } catch (PDOException $e) {
        log_msg("ID $id: ERRO ao atualizar BD: " . $e->getMessage());
    }
}

unlink($lockfile);

log_msg('Execução concluída.');
