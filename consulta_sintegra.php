
<?php

// ============================================================
// CONFIGURAÇÕES
// ============================================================

$TOKEN = "Informe seu TOKEN aqui";

$URL = "https://api.arquivo-nfe.com/prod/consulta_cadastro";

$MAX_TENTATIVAS = 5;
$INTERVALO = 5;


// ============================================================
// CONSULTAS
// ============================================================

$consultas = [
    
   [
       "uf" => "SP",                         
       "cnpj" => "XXXXXXXXXXXXXX",           
       "cpf" => null,                        
       "ie" => null,                         
       "request_id" => null                  
   ],                                        
   [                                         
       "uf" => "ES",                         
       "cnpj" => null,                       
       "cpf" => "XXX.XXX.XXX-XX",            
       "ie" => null,                         
       "request_id" => null                 
   ],
   [
       "uf" => "GO",                        
       "cnpj" => null,                      
       "cpf" => null,                       
       "ie" => "XXXXXXXXX",                 
       "request_id" => null                 
   ]

];


// ============================================================
// FUNÇÃO: CRIAR PARÂMETROS
// ============================================================

function criarParametros(array $consulta): array
{
    $parametros = [
        "uf" => $consulta["uf"]
    ];

    $identificadores = ["cnpj", "cpf", "ie"];

    $informados = [];

    foreach ($identificadores as $nome) {
        if (
            isset($consulta[$nome]) &&
            $consulta[$nome] !== ""
        ) {
            $informados[] = $nome;
        }
    }

    if (count($informados) !== 1) {
        throw new InvalidArgumentException(
            "Informe exatamente um dos parâmetros: cnpj, cpf ou ie."
        );
    }

    $nome = $informados[0];

    $parametros[$nome] = $consulta[$nome];

    return $parametros;
}


// ============================================================
// FUNÇÃO: ENVIAR REQUISIÇÃO POST
// ============================================================

function enviarRequisicao(array $parametros): array
{
    global $URL, $TOKEN;

    $urlCompleta = $URL . "?" . http_build_query($parametros);

    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => $urlCompleta,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => "",
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $TOKEN,
            "Accept: application/json"
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15
    ]);

    $corpo = curl_exec($curl);

    $erroCurl = curl_error($curl);

    $statusHttp = curl_getinfo($curl, CURLINFO_HTTP_CODE);


    if ($corpo === false) {
        throw new RuntimeException(
            "Erro na comunicação HTTP: " . $erroCurl
        );
    }

    return [
        "status" => $statusHttp,
        "corpo" => $corpo,
        "url" => $urlCompleta
    ];
}


// ============================================================
// ETAPA 1: ENVIAR CONSULTAS EM LOTE
// ============================================================

echo "\n========================================\n";
echo "ETAPA 1 - ENVIO DAS CONSULTAS\n";
echo "========================================\n";

foreach ($consultas as $indice => &$consulta) {

    echo "\nConsulta " . ($indice + 1) . "\n";

    try {

        $parametros = criarParametros($consulta);

        $resposta = enviarRequisicao($parametros);

        echo "URL: " . $resposta["url"] . "\n";
        echo "HTTP Status: " . $resposta["status"] . "\n";

        $dados = json_decode($resposta["corpo"], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Resposta não é um JSON válido:\n";
            echo $resposta["corpo"] . "\n";
            continue;
        }

        if (isset($dados["erro"])) {

            echo "Erro da API: ";

            print_r($dados["erro"]);

            if (isset($dados["request_id"])) {
                $consulta["request_id"] = $dados["request_id"];
            }

            continue;
        }

        $requestId = $dados["request_id"] ?? null;

        if ($requestId === null && isset($dados["retorno"])) {

            if (
                is_array($dados["retorno"]) &&
                isset($dados["retorno"][0]["request_id"])
            ) {
                $requestId = $dados["retorno"][0]["request_id"];
            }
        }

        if ($requestId !== null) {

            $consulta["request_id"] = $requestId;

            echo "Request ID: " . $requestId . "\n";

        } else {

            echo "A resposta não contém request_id.\n";

            echo json_encode(
                $dados,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ) . "\n";
        }

    } catch (Throwable $e) {

        echo "Erro: " . $e->getMessage() . "\n";
    }
}

unset($consulta);


// ============================================================
// ETAPA 2: CONSULTAR RESULTADOS
// ============================================================

echo "\n========================================\n";
echo "ETAPA 2 - CONSULTA DOS RESULTADOS\n";
echo "========================================\n";

foreach ($consultas as $indice => $consulta) {

    $requestId = $consulta["request_id"];

    if ($requestId === null) {

        echo "\nConsulta " . ($indice + 1);
        echo " sem request_id. Ignorando.\n";

        continue;
    }

    echo "\nConsulta " . ($indice + 1);
    echo " - Request ID: " . $requestId . "\n";

    for ($tentativa = 1; $tentativa <= $MAX_TENTATIVAS; $tentativa++) {

        echo "Tentativa " . $tentativa . "/" . $MAX_TENTATIVAS . "\n";

        try {

            $parametros = [
                "uf" => $consulta["uf"],
                "request_id" => $requestId
            ];

            $resposta = enviarRequisicao($parametros);

            echo "HTTP Status: " . $resposta["status"] . "\n";

            $dados = json_decode($resposta["corpo"], true);

            if (json_last_error() !== JSON_ERROR_NONE) {

                echo "Resposta não é um JSON válido:\n";
                echo $resposta["corpo"] . "\n";

                break;
            }

            if (isset($dados["erro"])) {

                echo "Erro da API: ";

                print_r($dados["erro"]);

                break;
            }

            $info = $dados["info"] ?? "";

            $pendente =
                strpos($info, "Aguardando retorno") !== false ||
                strpos($info, "status PENDENTE") !== false;

            if ($pendente) {

                echo $info . "\n";

                if ($tentativa < $MAX_TENTATIVAS) {

                    echo "Aguardando " . $INTERVALO;
                    echo " segundos para nova tentativa...\n";

                    sleep($INTERVALO);

                    continue;

                } else {

                    echo "Consulta não concluída após ";
                    echo $MAX_TENTATIVAS . " tentativas.\n";

                    break;
                }
            }

            if ($info === "sucesso") {

                echo "\nConsulta concluída com sucesso!\n";

                echo json_encode(
                    $dados,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
                ) . "\n";

                break;
            }

            echo "Resposta inesperada:\n";

            echo json_encode(
                $dados,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            ) . "\n";

            break;

        } catch (Throwable $e) {

            echo "Erro: " . $e->getMessage() . "\n";

            break;
        }
    }
}

echo "\nProcessamento finalizado.\n";

?>