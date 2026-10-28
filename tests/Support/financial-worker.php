<?php

declare(strict_types=1);

// §19.D — one independent PHP process = one real application connection/request.
require dirname(__DIR__) . '/bootstrap.php';

use App\Tests\Support\InProcessApi;
use App\Tests\Support\TestDatabase;

try {
    $line = fgets(STDIN);
    $request = json_decode($line === false ? '' : $line, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($request) || ($request['database'] ?? null) !== 'gestion_test') {
        throw new RuntimeException('El worker financiero solo admite gestion_test.');
    }
    TestDatabase::databaseName();
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('Falta barrera GO.');
    }
    fwrite(STDOUT, "DISPATCH\n");
    fflush(STDOUT);
    $result = InProcessApi::call(
        $request['method'], $request['path'], $request['body'],
        $request['headers'], $request['bearer'],
    );
    fwrite(STDOUT, json_encode(['status' => $result->status, 'payload' => $result->payload], JSON_THROW_ON_ERROR) . "\n");
    fflush(STDOUT);
    // Mantener los sockets Windows hasta que el padre haya consumido el resultado.
    // Solo lo solicitan clientes que implementan la confirmación explícita.
    if (($request['await_ack'] ?? false) === true && trim((string) fgets(STDIN)) !== 'ACK') {
        throw new RuntimeException('Falta confirmación de recepción del resultado.');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString());
    exit(1);
}
