<?php

function documentApiConfig(): array {
    $config = [
        'provider' => 'decolecta',
        'base_url' => 'https://api.decolecta.com/v1',
        'token' => '',
        'connect_timeout' => 5,
        'timeout' => 12,
    ];

    $configFile = dirname(__DIR__) . '/config/api.php';
    if (is_file($configFile)) {
        $fileConfig = require $configFile;
        if (is_array($fileConfig)) {
            $config = array_merge($config, $fileConfig);
        }
    }

    $environmentToken = getenv('DECOLECTA_API_TOKEN');
    if (is_string($environmentToken) && trim($environmentToken) !== '') {
        $config['token'] = trim($environmentToken);
    }

    $config['base_url'] = rtrim((string)$config['base_url'], '/');
    $config['token'] = trim((string)$config['token']);
    $config['connect_timeout'] = max(1, (int)$config['connect_timeout']);
    $config['timeout'] = max($config['connect_timeout'], (int)$config['timeout']);

    return $config;
}

function documentApiConfigured(): bool {
    return documentApiConfig()['token'] !== '';
}

function consultDocumentApi(string $documentType, string $documentNumber): array {
    $documentType = strtoupper(trim($documentType));
    $documentNumber = preg_replace('/\D+/', '', $documentNumber) ?? '';

    if (!in_array($documentType, ['DNI', 'RUC'], true)) {
        return documentApiError(422, 'Tipo de documento no válido.');
    }

    $expectedLength = $documentType === 'DNI' ? 8 : 11;
    if (!preg_match('/^\d{' . $expectedLength . '}$/', $documentNumber)) {
        return documentApiError(422, $documentType . ' inválido. Debe tener ' . $expectedLength . ' dígitos.');
    }

    if ($documentType === 'RUC' && function_exists('isValidPeruvianRuc') && !isValidPeruvianRuc($documentNumber)) {
        return documentApiError(422, 'RUC inválido. Revisa el dígito verificador.');
    }

    $config = documentApiConfig();
    if ($config['token'] === '') {
        return documentApiError(503, 'La API de documentos todavía no tiene un token configurado.');
    }

    $path = $documentType === 'DNI' ? '/reniec/dni' : '/sunat/ruc';
    $url = $config['base_url'] . $path . '?numero=' . rawurlencode($documentNumber);
    $httpResult = documentApiHttpGet($url, $config);
    $rawResponse = $httpResult['body'];
    $httpCode = $httpResult['status'];

    if ($rawResponse === false) {
        error_log('Fontana API documentos - error HTTP: ' . $httpResult['error']);
        return documentApiError(502, 'No fue posible comunicarse con el servicio de consulta.');
    }

    $payload = json_decode($rawResponse, true);
    if (!is_array($payload)) {
        error_log('Fontana API documentos - respuesta JSON inválida. HTTP ' . $httpCode);
        return documentApiError(502, 'El servicio de consulta devolvió una respuesta no válida.');
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $providerMessage = trim((string)($payload['message'] ?? $payload['error'] ?? ''));
        error_log('Fontana API documentos - HTTP ' . $httpCode . ': ' . $providerMessage);

        if (in_array($httpCode, [401, 403], true)) {
            return documentApiError(503, 'El token de la API no es válido o no tiene acceso a esta consulta.');
        }
        if ($httpCode === 404) {
            return documentApiError(404, 'No se encontraron datos para el ' . $documentType . ' ingresado.');
        }
        if (in_array($httpCode, [400, 422], true)) {
            return documentApiError(422, $providerMessage !== '' ? $providerMessage : 'El documento ingresado no es válido.');
        }
        if ($httpCode === 429) {
            return documentApiError(429, 'Se alcanzó el límite temporal de consultas de la API.');
        }
        return documentApiError(502, 'El servicio de consulta no está disponible en este momento.');
    }

    $normalized = normalizeDocumentApiPayload($documentType, $documentNumber, $payload);
    if ($normalized['nombre'] === '') {
        return documentApiError(404, 'La consulta no devolvió un nombre válido para el documento.');
    }

    return [
        'ok' => true,
        'status' => 200,
        'message' => 'Documento encontrado correctamente.',
        'data' => $normalized,
    ];
}


function documentApiHttpGet(string $url, array $config): array {
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['token'],
        'User-Agent: FontanaVentas/1.0',
    ];

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $config['connect_timeout'],
            CURLOPT_TIMEOUT => $config['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return ['body' => $body, 'status' => $status, 'error' => $error];
    }

    if ((bool)ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => $config['timeout'],
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches)) {
                $status = (int)$matches[1];
            }
        }
        $lastError = error_get_last();

        return [
            'body' => $body,
            'status' => $status,
            'error' => $body === false ? (string)($lastError['message'] ?? 'Error HTTP desconocido') : '',
        ];
    }

    return [
        'body' => false,
        'status' => 0,
        'error' => 'PHP no tiene cURL ni allow_url_fopen habilitado.',
    ];
}

function normalizeDocumentApiPayload(string $documentType, string $documentNumber, array $payload): array {
    if ($documentType === 'DNI') {
        $fullName = trim((string)($payload['full_name'] ?? $payload['nombre'] ?? ''));
        if ($fullName === '') {
            $fullName = trim(implode(' ', array_filter([
                $payload['first_last_name'] ?? $payload['apellidoPaterno'] ?? '',
                $payload['second_last_name'] ?? $payload['apellidoMaterno'] ?? '',
                $payload['first_name'] ?? $payload['nombres'] ?? '',
            ], static function ($value) {
                return trim((string)$value) !== '';
            })));
        }

        return [
            'tipo_documento' => 'DNI',
            'numero_documento' => (string)($payload['document_number'] ?? $payload['numeroDocumento'] ?? $documentNumber),
            'nombre' => $fullName,
            'direccion' => trim((string)($payload['direccion'] ?? '')),
            'estado' => trim((string)($payload['estado'] ?? '')),
            'condicion' => trim((string)($payload['condicion'] ?? '')),
            'proveedor' => 'Decolecta',
        ];
    }

    return [
        'tipo_documento' => 'RUC',
        'numero_documento' => (string)($payload['numero_documento'] ?? $payload['numeroDocumento'] ?? $documentNumber),
        'nombre' => trim((string)($payload['razon_social'] ?? $payload['razonSocial'] ?? $payload['nombre'] ?? '')),
        'direccion' => trim((string)($payload['direccion'] ?? '')),
        'estado' => trim((string)($payload['estado'] ?? '')),
        'condicion' => trim((string)($payload['condicion'] ?? '')),
        'proveedor' => 'Decolecta',
    ];
}

function documentApiError(int $status, string $message): array {
    return ['ok' => false, 'status' => $status, 'message' => $message, 'data' => null];
}
