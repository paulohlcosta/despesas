<?php
// Página de captura de imagem para registro de despesas
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Registrar Despesa</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f0f2f5;
            color: #333;
            padding: 16px;
            min-height: 100vh;
        }

        h1 {
            font-size: 1.3rem;
            text-align: center;
            margin-bottom: 20px;
            color: #1a1a2e;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            max-width: 480px;
            margin: 0 auto;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        label {
            display: block;
            font-size: 0.85rem;
            font-weight: bold;
            margin-bottom: 6px;
            color: #555;
        }

        select, input[type="file"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 8px;
            font-size: 1rem;
            margin-bottom: 16px;
            background: #fafafa;
        }

        /* Botão de câmera estilizado */
        .btn-camera {
            display: block;
            width: 100%;
            padding: 14px;
            background: #1a73e8;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            text-align: center;
            margin-bottom: 16px;
        }

        .btn-camera:active {
            background: #1558b0;
        }

        /* Área de preview */
        #preview-container {
            display: none;
            margin-bottom: 16px;
            text-align: center;
        }

        #preview-img {
            max-width: 100%;
            max-height: 300px;
            border-radius: 8px;
            border: 2px solid #1a73e8;
        }

        #nome-arquivo {
            font-size: 0.8rem;
            color: #777;
            margin-top: 6px;
        }

        /* Botão trocar foto */
        .btn-trocar {
            display: inline-block;
            margin-top: 8px;
            padding: 6px 14px;
            background: #f1f1f1;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 0.85rem;
            cursor: pointer;
            color: #333;
        }

        /* Botão enviar */
        #btn-enviar {
            display: none;
            width: 100%;
            padding: 14px;
            background: #28a745;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1.05rem;
            font-weight: bold;
            cursor: pointer;
        }

        #btn-enviar:active {
            background: #1e7e34;
        }

        /* Input de arquivo oculto (câmera traseira) */
        #input-foto {
            display: none;
        }

        .aviso {
            font-size: 0.78rem;
            color: #999;
            text-align: center;
            margin-top: 14px;
        }
    </style>
</head>
<body>

<h1>📄 Registrar Despesa</h1>

<div class="card">
    <form id="form-captura" action="processar.php" method="POST" enctype="multipart/form-data">

        <!-- Seletor de tipo de documento -->
        <label for="tipo_documento">Tipo de documento</label>
        <select name="tipo_documento" id="tipo_documento" required>
            <option value="cupom_fiscal">Cupom Fiscal</option>
            <option value="danfe">DANFE (Nota Fiscal Eletrônica)</option>
            <option value="recibo_cartao">Recibo de Cartão</option>
            <option value="outro">Outro</option>
        </select>

        <!-- Botão que aciona a câmera traseira -->
        <button type="button" class="btn-camera" id="btn-camera">
            📷 Fotografar documento
        </button>

        <!-- Input file oculto — câmera traseira via capture="environment" -->
        <input
            type="file"
            id="input-foto"
            name="arquivo_imagem"
            accept="image/jpeg,image/png,image/webp"
            capture="environment"
            required
        >

        <!-- Preview da imagem selecionada -->
        <div id="preview-container">
            <img id="preview-img" src="" alt="Preview do documento">
            <div id="nome-arquivo"></div>
            <button type="button" class="btn-trocar" id="btn-trocar">🔄 Trocar foto</button>
        </div>

        <!-- Botão de envio — aparece após selecionar foto -->
        <button type="submit" id="btn-enviar">✅ Enviar para processamento</button>

    </form>

    <p class="aviso">Formatos aceitos: JPG, PNG, WEBP · Tamanho máximo: 10MB</p>
</div>

<script>
    const btnCamera      = document.getElementById('btn-camera');
    const inputFoto      = document.getElementById('input-foto');
    const previewContainer = document.getElementById('preview-container');
    const previewImg     = document.getElementById('preview-img');
    const nomeArquivo    = document.getElementById('nome-arquivo');
    const btnTrocar      = document.getElementById('btn-trocar');
    const btnEnviar      = document.getElementById('btn-enviar');

    // Abre o seletor de arquivo (câmera no celular)
    btnCamera.addEventListener('click', () => inputFoto.click());
    btnTrocar.addEventListener('click', () => inputFoto.click());

    // Quando o usuário seleciona/fotografa um arquivo
    inputFoto.addEventListener('change', function () {
        const arquivo = this.files[0];
        if (!arquivo) return;

        // Exibe o preview
        const leitor = new FileReader();
        leitor.onload = function (e) {
            previewImg.src = e.target.result;
            nomeArquivo.textContent = arquivo.name + ' (' + (arquivo.size / 1024).toFixed(1) + ' KB)';
            previewContainer.style.display = 'block';
            btnCamera.style.display = 'none';
            btnEnviar.style.display = 'block';
        };
        leitor.readAsDataURL(arquivo);
    });
</script>

</body>
</html>