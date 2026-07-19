# Portal de Despesas

Portal para gestão de despesas via captura de imagens, processamento com LLM e armazenamento em MySQL.

## Estrutura e Funções
- `config/`: Configurações do sistema e chaves de API.
- `capturar.php`: Interface para upload/captura de fotos das despesas.
- `processar.php`: Gerencia o fluxo de processamento das imagens recebidas.
- `processar_gemini.php`: Executa a extração de dados via LLM externo.
- `monitor.php`: Observabilidade do status de extração e saúde do servidor.
- `corrigir.php`: Interface para edição e validação manual dos dados.
- `tabela.php`: Dashboard para visualização e consulta das despesas.
- `uploads/`: Diretório de armazenamento das imagens originais.

## Tecnologias
- Linguagem: PHP
- Banco de Dados: MySQL
- Integração: LLM Externo (via API)

## Gestão
- Repositório: GitHub
