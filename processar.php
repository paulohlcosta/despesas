<?php
require_once 'config.php';

function erro_e_sair(string $msg): never {
    echo '<!DOCTYPE html><html lang="pt-BR"><head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Erro</title>
        <style>
            body{font-family:Arial,sans-serif;background:#f0f2f5;display:flex;align-items:center;
                 justify-content:center;min-height:100vh;padding:16px;}
            .box{background:#fff;border-radius:12px;padding:24px;max-width:420px;width:100%;
                 box-shadow:0 2px 8px rgba(0,0,0,.1);text-align:center;}
            h2{color:#c0392b;margin-bottom:12px;}
            p{color:#555;margin-bottom:20px;font-size:.95rem;}
            a{display:inline-block;padding:12px 24px;background:#1a73e8;color:#fff;
              border-radius:8px;text-decoration:none;font-size:1rem;}
        </style>
    </head><body><div class="box">
        <h2>⚠️ Erro</h2>
        <p>' . htmlspecialchars($msg) . '</p>
        <a href="capturar.php">← Voltar</a>
    </div></body></html>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    erro_e_sair('Acesso direto não permitido.');
}

if (empty($_FILES['arquivo_imagem']) || $_FILES['arquivo_imagem']['error'] !== UPLOAD_ERR_OK) {
    $codigos = [
        UPLOAD_ERR_INI_SIZE   => 'Arquivo excede o limite do servidor.',
        UPLOAD_ERR_FORM_SIZE  => 'Arquivo excede o limite do formulário.',
        UPLOAD_ERR_PARTIAL    => 'Upload incompleto. Tente novamente.',
        UPLOAD_ERR_NO_FILE    => 'Nenhum arquivo enviado.',
        UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária ausente.',
        UPLOAD_ERR_CANT_WRITE => 'Falha ao gravar arquivo temporário.',
        UPLOAD_ERR_EXTENSION  => 'Upload bloqueado por extensão PHP.',
    ];
    $codigo = $_FILES['arquivo_imagem']['error'] ?? UPLOAD_ERR_NO_FILE;
    erro_e_sair($codigos[$codigo] ?? 'Erro desconhecido no upload.');
}

$arquivo  = $_FILES['arquivo_imagem'];
$tipo_doc = $_POST['tipo_documento'] ?? 'outro';

$tipos_validos = ['cupom_fiscal', 'danfe', 'recibo_cartao', 'outro'];
if (!in_array($tipo_doc, $tipos_validos, true)) {
    erro_e_sair('Tipo de documento inválido.');
}

$finfo     = new finfo(FILEINFO_MIME_TYPE);
$mime_real = $finfo->file($arquivo['tmp_name']);
if (!in_array($mime_real, TIPOS_PERMITIDOS, true)) {
    erro_e_sair("Formato não permitido ($mime_real). Envie JPG, PNG ou WEBP.");
}

if ($arquivo['size'] > MAX_TAMANHO_UPLOAD) {
    $mb = number_format($arquivo['size'] / 1024 / 1024, 1);
    erro_e_sair("Arquivo muito grande ({$mb}MB). Limite: 10MB.");
}

if (!is_dir(PASTA_UPLOADS)) {
    if (!mkdir(PASTA_UPLOADS, 0755, true)) {
        erro_e_sair('Não foi possível criar a pasta de uploads.');
    }
}

$extensoes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$extensao  = $extensoes[$mime_real];

$timestamp    = date('Ymd_His');
$base_nome    = PASTA_UPLOADS . $timestamp;
$contador     = 0;
$nome_final   = $timestamp . '.' . $extensao;
$caminho_dest = PASTA_UPLOADS . $nome_final;

while (file_exists($caminho_dest)) {
    $contador++;
    $nome_final   = $timestamp . '_' . $contador . '.' . $extensao;
    $caminho_dest = PASTA_UPLOADS . $nome_final;
}

if (!move_uploaded_file($arquivo['tmp_name'], $caminho_dest)) {
    erro_e_sair('Falha ao salvar o arquivo. Verifique permissões da pasta uploads/.');
}

$max_lado = 1000;
[$larg_orig, $alt_orig] = getimagesize($caminho_dest);
if ($larg_orig > $max_lado || $alt_orig > $max_lado) {
    $ratio     = min($max_lado / $larg_orig, $max_lado / $alt_orig);
    $nova_larg = (int) round($larg_orig * $ratio);
    $nova_alt  = (int) round($alt_orig  * $ratio);

    $criadores = [
        'image/jpeg' => 'imagecreatefromjpeg',
        'image/png'  => 'imagecreatefrompng',
        'image/webp' => 'imagecreatefromwebp',
    ];
    $salvadores = [
        'image/jpeg' => fn($img, $path) => imagejpeg($img, $path, 85),
        'image/png'  => fn($img, $path) => imagepng($img, $path, 7),
        'image/webp' => fn($img, $path) => imagewebp($img, $path, 85),
    ];

    $img_orig    = $criadores[$mime_real]($caminho_dest);
    $img_reduz   = imagecreatetruecolor($nova_larg, $nova_alt);

    if ($mime_real === 'image/png') {
        imagealphablending($img_reduz, false);
        imagesavealpha($img_reduz, true);
    }

    imagecopyresampled($img_reduz, $img_orig, 0, 0, 0, 0, $nova_larg, $nova_alt, $larg_orig, $alt_orig);
    $salvadores[$mime_real]($img_reduz, $caminho_dest);
    imagedestroy($img_orig);
    imagedestroy($img_reduz);
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_BANCO . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USUARIO, DB_SENHA, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $sql = "INSERT INTO t_despesas (
                tipo_documento, arquivo_imagem, data_emissao,
                nome_estabelecimento, valor_bruto, valor_liquido,
                revisado
            ) VALUES (
                :tipo_documento, :arquivo_imagem, NOW(),
                '(aguardando extração)', 0.00, 0.00, 0
            )";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':tipo_documento' => $tipo_doc,
        ':arquivo_imagem' => $nome_final,
    ]);

    $id_inserido = $pdo->lastInsertId();

} catch (PDOException $e) {
    @unlink($caminho_dest);
    erro_e_sair('Erro ao salvar no banco: ' . $e->getMessage());
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
    <title>Enviado</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 16px; color: #333; }
        h1 { font-size: 1.3rem; text-align: center; margin-bottom: 20px; color: #1a1a2e; }
        .card { background: #fff; border-radius: 12px; padding: 20px; max-width: 480px; margin: 0 auto; box-shadow: 0 2px 8px rgba(0,0,0,.1); }
        .status { text-align: center; padding: 10px; border-radius: 8px; margin-bottom: 16px; font-weight: bold; font-size: .95rem; background: #fff3cd; color: #856404; }
        .preview-img { width: 100%; max-height: 260px; object-fit: contain; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; margin-bottom: 20px; }
        td { padding: 8px 6px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
        td:first-child { color: #777; width: 45%; }
        td:last-child { font-weight: bold; }
        .btn { display: block; width: 100%; padding: 13px; border: none; border-radius: 8px; font-size: 1rem; cursor: pointer; text-align: center; text-decoration: none; margin-bottom: 10px; }
        .btn-primario { background: #1a73e8; color: #fff; }
        .btn-secundario { background: #f1f1f1; color: #333; border: 1px solid #ccc; }
    </style>
</head>
<body>
<h1>✅ Imagem Recebida</h1>
<div class="card">
    <div class="status">⏳ Aguardando processamento (ID #<?= $id_inserido ?>)</div>
    <img class="preview-img" src="uploads/<?= urlencode($nome_final) ?>" alt="Documento enviado">
    <table>
        <tr><td>Tipo</td><td><?= htmlspecialchars($rotulos[$tipo_doc] ?? $tipo_doc) ?></td></tr>
        <tr><td>Arquivo</td><td><?= htmlspecialchars($nome_final) ?></td></tr>
        <tr><td>Status</td><td>Pendente de extração</td></tr>
    </table>
    <a href="capturar.php" class="btn btn-primario">📷 Registrar outra despesa</a>
    <a href="monitor.php" class="btn btn-secundario">📊 Ver fila de processamento</a>
</div>
</body>
</html>