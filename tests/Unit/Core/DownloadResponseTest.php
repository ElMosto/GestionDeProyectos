<?php

declare(strict_types=1);

namespace App\Tests\Unit\Core;

use App\Core\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DownloadResponseTest extends TestCase
{
    public function test_C_download_preserves_binary_content_and_private_headers(): void
    {
        $bytes = "PK\x03\x04\x00\xFF";
        $response = Response::download($bytes, 'proyecto_PRY-2030-000001_gantt.xlsx', 'application/octet-stream');
        $this->assertSame($bytes, $response->body);
        $this->assertSame(200, $response->status);
        $this->assertSame('private, no-store', $response->headers['Cache-Control']);
        $this->assertSame('attachment; filename="proyecto_PRY-2030-000001_gantt.xlsx"', $response->headers['Content-Disposition']);
    }

    public function test_H_download_rejects_header_injection_and_path_names(): void
    {
        foreach (["reporte.xlsx\r\nX-Test: injected", '../archivo.xlsx', 'carpeta/archivo.xlsx', '"file.xlsx'] as $filename) {
            try {
                Response::download('data', $filename, 'application/octet-stream');
                $this->fail('No se debe aceptar un nombre de descarga inseguro.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Response::download('data', 'file.xlsx', "application/octet-stream\r\nX-Test: injected");
    }
}
