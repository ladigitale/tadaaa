<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactIntakeSchema;
use PHPUnit\Framework\TestCase;

final class ArtifactIntakeSchemaTest extends TestCase
{
    /** @return array<string, mixed> */
    private function decl(): array
    {
        return [
            'fields' => [
                'name' => ['type' => 'string', 'max' => 20, 'required' => true],
                'score' => ['type' => 'integer', 'min' => 0, 'max' => 9999, 'required' => true],
                'level' => ['type' => 'enum', 'values' => ['facile', 'moyen']],
            ],
            'maxRecords' => 40,
            'minInterval' => 5,
        ];
    }

    public function testValidDeclarationNormalizes(): void
    {
        self::assertSame([], ArtifactIntakeSchema::validateDeclaration($this->decl()));
        $n = ArtifactIntakeSchema::normalize($this->decl());
        self::assertSame(40, $n['maxRecords']);
        self::assertSame(5, $n['minInterval']);
        self::assertFalse($n['requireCode']);
        self::assertTrue($n['fields']['name']['required']);
        self::assertFalse($n['fields']['level']['required']);
    }

    public function testDeclarationRejectsUnboundedFields(): void
    {
        $errors = ArtifactIntakeSchema::validateDeclaration(['fields' => [
            'note' => ['type' => 'string'],            // max manquant
            'n' => ['type' => 'integer', 'min' => 0], // max manquant
            'bad name' => ['type' => 'string', 'max' => 5],
            'x' => ['type' => 'object'],
        ], 'maxRecords' => 100000]);
        self::assertCount(5, $errors);
    }

    public function testRecordAcceptsAndCleans(): void
    {
        $fields = ArtifactIntakeSchema::normalize($this->decl())['fields'];
        $r = ArtifactIntakeSchema::validateRecord($fields, ['name' => "  Léa\n", 'score' => 120.0, 'level' => 'moyen']);
        self::assertSame([], $r['errors']);
        self::assertSame(['name' => 'Léa', 'score' => 120, 'level' => 'moyen'], $r['data']);
    }

    public function testRecordRejectsUnknownMissingAndOutOfRange(): void
    {
        $fields = ArtifactIntakeSchema::normalize($this->decl())['fields'];
        $r = ArtifactIntakeSchema::validateRecord($fields, [
            'score' => 99999, 'level' => 'expert', 'hack' => '<script>',
        ]);
        self::assertCount(4, $r['errors']); // champ inconnu, name requis, score hors limites, enum
        self::assertSame(['name : 20 caractères maximum.'], ArtifactIntakeSchema::validateRecord($fields, ['name' => str_repeat('a', 21), 'score' => 1])['errors']);
    }

    public function testRecordRejectsListsAndHugePayloads(): void
    {
        $fields = ArtifactIntakeSchema::normalize($this->decl())['fields'];
        self::assertNotSame([], ArtifactIntakeSchema::validateRecord($fields, ['a', 'b'])['errors']);
        self::assertNotSame([], ArtifactIntakeSchema::validateRecord($fields, ['name' => str_repeat('x', 5000), 'score' => 1])['errors']);
        self::assertNotSame([], ArtifactIntakeSchema::validateRecord($fields, ['name' => 'Léa', 'score' => '12'])['errors']);
    }
}
