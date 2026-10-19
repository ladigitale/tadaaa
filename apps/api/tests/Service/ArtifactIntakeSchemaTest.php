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

    public function testRecordRejectsMarkup(): void
    {
        $fields = ArtifactIntakeSchema::normalize($this->decl())['fields'];
        $r = ArtifactIntakeSchema::validateRecord($fields, ['name' => '<img src=x>', 'score' => 1]);
        self::assertSame(['name : caractères < et > non autorisés.'], $r['errors']);
    }

    public function testPermanentAndUniqueDeclaration(): void
    {
        $d = $this->decl();
        unset($d['minInterval']);
        $d['permanent'] = true;
        $d['unique'] = ['field' => 'name', 'keep' => 'max:score'];
        self::assertSame([], ArtifactIntakeSchema::validateDeclaration($d));
        $n = ArtifactIntakeSchema::normalize($d);
        self::assertTrue($n['permanent']);
        self::assertSame(ArtifactIntakeSchema::PERMANENT_MIN_INTERVAL, $n['minInterval']);
        self::assertSame(['field' => 'name', 'keep' => 'max:score'], $n['unique']);

        $bad = $d;
        $bad['unique'] = ['field' => 'score', 'keep' => 'max:score'];
        self::assertNotSame([], ArtifactIntakeSchema::validateDeclaration($bad), 'unique.field doit être une chaîne');
        $bad['unique'] = ['field' => 'name', 'keep' => 'max:name'];
        self::assertNotSame([], ArtifactIntakeSchema::validateDeclaration($bad), 'keep sur un champ non numérique');
        $bad = $d;
        $bad['requireCode'] = true;
        self::assertNotSame([], ArtifactIntakeSchema::validateDeclaration($bad), 'permanent + requireCode');
    }

    public function testBeats(): void
    {
        $max = ['mode' => 'max', 'field' => 'score'];
        self::assertTrue(ArtifactIntakeSchema::beats($max, ['score' => 10], ['score' => 5]));
        self::assertFalse(ArtifactIntakeSchema::beats($max, ['score' => 5], ['score' => 5]));
        self::assertTrue(ArtifactIntakeSchema::beats(['mode' => 'min', 'field' => 'score'], ['score' => 3], ['score' => 5]));
        self::assertTrue(ArtifactIntakeSchema::beats(['mode' => 'last', 'field' => null], ['score' => 1], ['score' => 9]));
    }

    public function testSortAndLimit(): void
    {
        $rows = [
            ['id' => 'a', 'data' => ['nom' => 'Zoe', 'score' => 10], 'createdAt' => '2026-01-01'],
            ['id' => 'b', 'data' => ['nom' => 'Bob', 'score' => 30], 'createdAt' => '2026-01-02'],
            ['id' => 'c', 'data' => ['nom' => 'Ann', 'score' => 30], 'createdAt' => '2026-01-03'],
            ['id' => 'd', 'data' => ['nom' => 'Sans'], 'createdAt' => '2026-01-04'],
        ];
        $ids = static fn (array $r): array => array_column($r, 'id');
        self::assertSame(['c', 'b'], $ids(ArtifactIntakeSchema::sortAndLimit($rows, '-score,nom', 2)));
        self::assertSame(['d', 'c', 'b', 'a'], $ids(ArtifactIntakeSchema::sortAndLimit($rows, '-createdAt', null)));
        self::assertSame(['a', 'b', 'c', 'd'], $ids(ArtifactIntakeSchema::sortAndLimit($rows, 'inconnu;drop', null)), 'tri invalide ignoré');
    }
}
