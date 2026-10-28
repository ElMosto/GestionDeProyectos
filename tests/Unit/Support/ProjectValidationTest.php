<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Core\ApiException;
use App\Validation\ProjectInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** §8, §15, §19.B/H — closed payloads and exact input formats; no database. */
final class ProjectValidationTest extends TestCase
{
    public static function invalidFields(): array
    {
        return [
            'JSON float money' => [['base_amount' => 0.1], ProjectInput::PROJECT],
            'negative money' => [['base_amount' => '-0.01'], ProjectInput::PROJECT],
            'too many decimals' => [['base_amount' => '0.001'], ProjectInput::PROJECT],
            'exponential notation' => [['base_amount' => '1e2'], ProjectInput::PROJECT],
            'empty description' => [['description' => '   '], ProjectInput::PROJECT],
            'impossible calendar date' => [['planned_start' => '2026-02-30'], ProjectInput::PROJECT],
            'injected privilege' => [['role_id' => '1'], ProjectInput::PROJECT],
            'explicit project bypasses stage' => [['project_id' => '1'], ProjectInput::TASK],
            'fractional progress' => [['progress' => 25.5], ProjectInput::TASK],
            'out of range progress' => [['progress' => 101], ProjectInput::TASK],
            'zero quantity' => [['quantity' => '0.000'], ProjectInput::CHARGE],
            'quantity precision' => [['quantity' => '1.0000'], ProjectInput::CHARGE],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_B_invalid_payload_is_422(array $body, array $schema): void
    {
        try {
            ProjectInput::fields($body, $schema);
            self::fail('Se aceptó un cuerpo inválido');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status);
            self::assertSame('VALIDATION_FAILED', $exception->errorCode);
        }
    }

    public function test_B_internal_zero_and_real_leap_day_are_valid(): void
    {
        $data = ProjectInput::fields([
            'type' => 'INTERNO', 'base_amount' => '0.00', 'planned_start' => '2028-02-29',
        ], ProjectInput::PROJECT);
        self::assertSame('0.00', $data['base_amount']);
        self::assertSame('2028-02-29', $data['planned_start']);
    }

    public function test_B_duplicate_stage_ids_are_rejected(): void
    {
        $this->expectException(ApiException::class);
        ProjectInput::ids(['1', '1']);
    }

    public function test_B_missing_required_description_is_rejected(): void
    {
        $this->expectException(ApiException::class);
        ProjectInput::fields(['name' => 'Proyecto'], ProjectInput::PROJECT, ['name', 'description']);
    }
}
