<?php
// objetivo: gerar uma lista de arquivos da pasta, e seus respectivos links


// Obtém um array com os nomes dos arquivos da pasta atual
$files = glob("*");

// Percorre o array com um laço foreach e imprime na tela cada nome de arquivo como um link HTML
foreach ($files as $file) {
echo "<a href='$file'>$file</a><br>";
}
?>
