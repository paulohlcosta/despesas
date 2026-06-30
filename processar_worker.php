<?php
// Worker executado pelo cron a cada 15 minutos.
// Processa UMA foto pendente por execução.
// Redimensiona para max 1000x1000 antes de enviar ao Gemini.

require_once __DIR__ . '/config.php';

// ─── Garante pasta de logs ─────────────────────────────────────────────────
$dir_logs = __DIR__ . '/logs';
if (!is_dir($dir_logs)) mkdir($dir_logs, 0755, true);

function log_worker(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
}

// ─── Conexão PDO ───────────────────────────────────────────────────────────
try {
    $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_BANCO.';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    log_worker('ERRO PDO: ' . $e->getMessage());
    exit(1);
}

// ─── Busca a foto pendente mais antiga ────────────────────────────────────
// Pendente = observacoes = 'pendente de processamento' e arquivo sem prefixo __
$row = $pdo->query(
    "SELECT id, arquivo_imagem, tipo_documento
     FROM t_despesas
     WHERE observacoes = 'pendente de processamento'
       AND arquivo_imagem NOT LIKE '\\_\\_%'
     ORDER BY created_at ASC
     LIMIT 1"
)->fetch();

if (!$row) {
    log_worker('Nenhuma foto pendente. Encerrando.');
    exit(0);
}

$id           = (int)$row['id'];
$nome_arquivo = $row['arquivo_imagem'];
$tipo_doc     = $row['tipo_documento'];
$caminho_orig = PASTA_UPLOADS . $nome_arquivo;

log_worker("Processando ID $id — $nome_arquivo");

// ─── Verifica se o arquivo existe ─────────────────────────────────────────
if (!file_exists($caminho_orig)) {
    log_worker("AVISO: arquivo não encontrado em $caminho_orig. Marcando como erro.");
    $pdo->prepare("UPDATE t_despesas SET observacoes = 'arquivo não encontrado' WHERE id = ?")
        ->execute([$id]);
    exit(1);
}

// ─── Redimensiona se necessário (max MAX_LADO_IMAGEM px) ──────────────────
$caminho_envio = redimensionar_se_necessario($caminho_orig);
log_worker('Arquivo para envio: ' . $caminho_envio);

// ─── Chama extração (stub — implementar com Gemini) ───────────────────────
$dados = extrair_dados_gemini($caminho_envio, $tipo_doc);

// Remove arquivo temporário redimensionado (se diferente do original)
if ($caminho_envio !== $caminho_orig && file_exists($caminho_envio)) {
    @unlink($caminho_envio);
}

// ─── Renomeia arquivo original para __ (processado) ───────────────────────
$nome_processado = '__' . $nome_arquivo;
$destino_proc    = PASTA_UPLOADS . $nome_processado;

if (!rename($caminho_orig, $destino_proc)) {
    log_worker("AVISO: não foi possível renomear $nome_arquivo para $nome_processado");
    $nome_processado = $nome_arquivo; // mantém nome original se falhar
}

// ─── Prepara campos com fallback ──────────────────────────────────────────
$chave_acesso         = $dados['chave_acesso']         ?? null;
$data_emissao         = $dados['data_emissao']         ?? date('Y-m-d H:i:s');
$cnpj_emitente        = $dados['cnpj_emitente']        ?? null;
$nome_estab           = $dados['nome_estabelecimento'] ?? '(não extraído)';
$valor_bruto          = (float)($dados['valor_bruto']  ?? 0.00);
$desconto             = (float)($dados['desconto']     ?? 0.00);
$valor_liquido        = (float)($dados['valor_liquido']?? 0.00);
$forma_pagamento      = $dados['forma_pagamento']      ?? null;
$categoria            = $dados['categoria']            ?? null;
$confianca            = (int)($dados['confianca_extracao'] ?? 0);
$obs                  = empty($dados) ? 'extração sem retorno' : 'extraído pelo worker';

// ─── Atualiza registro no banco ───────────────────────────────────────────
try {
    $pdo->prepare("UPDATE t_despesas SET
        arquivo_imagem       = :arquivo,
        chave_acesso         = :chave,
        data_emissao         = :data_emissao,
        cnpj_emitente        = :cnpj,
        nome_estabelecimento = :nome_estab,
        valor_bruto          = :vbruto,
        desconto             = :desconto,
        valor_liquido        = :vliquido,
        forma_pagamento      = :forma,
        categoria            = COALESCE(categoria, :categoria),
        confianca_extracao   = :confianca,
        observacoes          = :obs
        WHERE id = :id"
    )->execute([
        ':arquivo'    => $nome_processado,
        ':chave'      => $chave_acesso,
        ':data_emissao'=> $data_emissao,
        ':cnpj'       => $cnpj_emitente,
        ':nome_estab' => $nome_estab,
        ':vbruto'     => $valor_bruto,
        ':desconto'   => $desconto,
        ':vliquido'   => $valor_liquido,
        ':forma'      => $forma_pagamento,
        ':categoria'  => $categoria,
        ':confianca'  => $confianca,
        ':obs'        => $obs,
        ':id'         => $id,
    ]);
    log_worker("ID $id atualizado com sucesso.");
} catch (PDOException $e) {
    log_worker('ERRO ao atualizar banco: ' . $e->getMessage());
    exit(1);
}

exit(0);

// ══════════════════════════════════════════════════════════════════════════════
// FUNÇÕES
// ══════════════════════════════════════════════════════════════════════════════

/**
 * Redimensiona a imagem para caber em MAX_LADO_IMAGEM x MAX_LADO_IMAGEM.
 * Retorna o caminho do arquivo a enviar (temporário ou o original se já couber).
 * Requer extensão GD do PHP.
 */
function redimensionar_se_necessario(string $caminho): string {
    $info = @getimagesize($caminho);
    if (!$info) return $caminho; // não é imagem reconhecida pelo GD

    [$largura, $altura] = $info;
    $max = MAX_LADO_IMAGEM;

    // Já cabe no quadrado — não precisa redimensionar
    if ($largura <= $max && $altura <= $max) return $caminho;

    // Calcula nova dimensão mantendo proporção
    $ratio     = min($max / $largura, $max / $altura);
    $nova_larg = (int)round($largura * $ratio);
    $nova_alt  = (int)round($altura  * $ratio);

    // Carrega a imagem conforme o tipo MIME
    $mime = $info['mime'];
    $src  = match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($caminho),
        'image/png'  => imagecreatefrompng($caminho),
        'image/webp' => imagecreatefromwebp($caminho),
        default      => null,
    };
    if (!$src) return $caminho;

    $dst = imagecreatetruecolor($nova_larg, $nova_alt);

    // Preserva transparência para PNG/WEBP
    if (in_array($mime, ['image/png','image/webp'])) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparente = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nova_larg, $nova_alt, $transparente);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nova_larg, $nova_alt, $largura, $altura);

    // Salva em arquivo temporário
    $tmp = tempnam(sys_get_temp_dir(), 'despesa_') . '.jpg';
    imagejpeg($dst, $tmp, 85);
    imagedestroy($src);
    imagedestroy($dst);

    log_worker("Redimensionado de {$largura}x{$altura} para {$nova_larg}x{$nova_alt}");
    return $tmp;
}

/**
 * Stub — implementar com chamada real à API Gemini.
 * Deve retornar array com as chaves descritas abaixo, ou array vazio.
 */
function extrair_dados_gemini(string $caminho, string $tipo_documento): array {
    // TODO: implementar
    return [];
}