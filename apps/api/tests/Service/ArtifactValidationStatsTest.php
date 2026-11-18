<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ArtifactDocumentValidator;
use App\Service\ArtifactScriptsCatalog;
use App\Service\ArtifactUiRules;
use App\Service\ArtifactValidationStats;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ArtifactValidationStatsTest extends TestCase
{
    private const CONFIG = __DIR__.'/../../config/artifacts';

    private Connection $connection;

    private ArtifactValidationStats $stats;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE artifact_validation_stats (day DATE NOT NULL, source VARCHAR(16) NOT NULL, kind VARCHAR(8) NOT NULL, rule VARCHAR(120) NOT NULL, hits INT DEFAULT 0 NOT NULL, PRIMARY KEY (day, source, kind, rule))');
        $this->stats = new ArtifactValidationStats($this->connection);
    }

    public function testRuleNamesAreStableAcrossDocuments(): void
    {
        self::assertSame('refus:sonic-select', ArtifactValidationStats::ruleOf(ArtifactUiRules::REPLACED_TAGS['sonic-select']));
        self::assertSame('refus:button', ArtifactValidationStats::ruleOf(ArtifactUiRules::REPLACED_TAGS['button']));
        self::assertSame('refus:form', ArtifactValidationStats::ruleOf(ArtifactUiRules::REPLACED_TAGS['form']));
        self::assertSame('hors-liste:sonic-tabs', ArtifactValidationStats::ruleOf('Composant hors liste blanche : sonic-tabs'));
        self::assertSame('Profondeur max N dépassée', ArtifactValidationStats::ruleOf('Profondeur max 32 dépassée.'));
        self::assertSame(
            ArtifactValidationStats::ruleOf('store « etat » introuvable.'),
            ArtifactValidationStats::ruleOf('store « panier » introuvable.'),
        );
        self::assertSame(
            ArtifactValidationStats::ruleOf('"markup" est interdit (sécurité).'),
            ArtifactValidationStats::ruleOf('"innerHTML" est interdit (sécurité).'),
        );
        self::assertSame('autre', ArtifactValidationStats::ruleOf(''));
        self::assertLessThan(121, mb_strlen(ArtifactValidationStats::ruleOf(str_repeat('mot ', 80))));
    }

    public function testARuleCountsOncePerDocument(): void
    {
        $issues = [
            ['path' => '/a', 'message' => 'Composant hors liste blanche : sonic-tabs'],
            ['path' => '/b', 'message' => 'Composant hors liste blanche : sonic-tabs'],
            ['path' => '/c', 'rule' => 'avis:pointerdown', 'message' => 'peu importe'],
        ];
        self::assertSame(['hors-liste:sonic-tabs', 'avis:pointerdown'], ArtifactValidationStats::rules($issues));
    }

    public function testRecordsAndAggregates(): void
    {
        $invalid = ['valid' => false, 'errors' => [
            ['path' => '/x', 'message' => ArtifactUiRules::REPLACED_TAGS['sonic-select']],
            ['path' => '/y', 'message' => ArtifactUiRules::REPLACED_TAGS['sonic-select']],
            ['path' => '/z', 'message' => 'Composant hors liste blanche : sonic-tabs'],
        ]];
        $this->stats->record(ArtifactValidationStats::SOURCE_VALIDATE, $invalid);
        $this->stats->record(ArtifactValidationStats::SOURCE_VALIDATE, $invalid);
        $this->stats->record(ArtifactValidationStats::SOURCE_VALIDATE, ['valid' => true, 'errors' => []], [['path' => '/w', 'rule' => 'avis:pointerdown', 'message' => 'm']]);
        $this->stats->record(ArtifactValidationStats::SOURCE_VALIDATE, ['valid' => true, 'errors' => []]);
        $this->stats->record(ArtifactValidationStats::SOURCE_ATELIER, ['valid' => true, 'errors' => []]);

        $all = $this->stats->stats(7);
        self::assertSame(['atelier', 'validate'], array_keys($all['sources']));
        $validate = $all['sources']['validate'];
        self::assertSame(4, $validate['total']);
        self::assertSame(2, $validate['valid']);
        self::assertSame(0.5, $validate['validRate']);
        self::assertSame(['hors-liste:sonic-tabs' => 2, 'refus:sonic-select' => 2], $validate['errors']);
        self::assertSame(['avis:pointerdown' => 1], $validate['warnings']);
        self::assertSame([gmdate('Y-m-d') => ['total' => 4, 'valid' => 2]], $validate['byDay']);
        self::assertSame(1.0, $all['sources']['atelier']['validRate']);
        self::assertSame('{}', json_encode($all['sources']['atelier']['errors']));

        self::assertSame(['atelier'], array_keys($this->stats->stats(7, 'atelier')['sources']));
    }

    public function testOldDaysFallOutOfTheWindow(): void
    {
        $this->connection->insert('artifact_validation_stats', ['day' => gmdate('Y-m-d', time() - 10 * 86400), 'source' => 'validate', 'kind' => 'total', 'rule' => '', 'hits' => 5]);
        $this->stats->record(ArtifactValidationStats::SOURCE_VALIDATE, ['valid' => true, 'errors' => []]);
        self::assertSame(1, $this->stats->stats(7)['sources']['validate']['total']);
        self::assertSame(6, $this->stats->stats(30)['sources']['validate']['total']);
    }

    public function testRecordingNeverBreaksValidation(): void
    {
        // Table absente : rien n'est levé.
        $this->connection->executeStatement('DROP TABLE artifact_validation_stats');
        $this->stats->record(ArtifactValidationStats::SOURCE_SAVE, ['valid' => false, 'errors' => [['path' => '/', 'message' => 'x']]]);
        self::assertTrue(true);
    }

    public function testNothingIsWrittenInsideAnOpenTransaction(): void
    {
        $this->connection->beginTransaction();
        $this->stats->record(ArtifactValidationStats::SOURCE_SAVE, ['valid' => true, 'errors' => []]);
        $this->connection->commit();
        self::assertSame([], $this->stats->stats(7)['sources']);
    }

    public function testRealValidatorOutputIsClassified(): void
    {
        $validator = new ArtifactDocumentValidator(
            self::CONFIG.'/catalog.json',
            self::CONFIG.'/sdui.schema.json',
            new ArtifactScriptsCatalog(self::CONFIG.'/scripts-catalog.json'),
        );
        $doc = [
            'schema' => 'artifacts/1', 'title' => 'T', 'defaultView' => 'home',
            'views' => [['id' => 'home', 'title' => 'H', 'root' => ['nodes' => [
                ['tagName' => 'sonic-select'],
                ['tagName' => 'input'],
                ['tagName' => 'sonic-tabs'],
            ]]]],
        ];
        $result = $validator->validate($doc);
        self::assertSame(['refus:sonic-select', 'refus:input', 'hors-liste:sonic-tabs'], ArtifactValidationStats::rules($result['errors']));

        $doc['views'][0]['root']['nodes'] = [['tagName' => 'sonic-action', 'attributes' => ['target' => 'etat', 'type' => 'setTab', 'trigger' => 'pointerdown']]];
        $doc['data'] = ['stores' => ['etat' => ['initial' => ['onglet' => 'a'], 'reducer' => '$state']]];
        self::assertTrue($validator->validate($doc)['valid']);
        self::assertSame(['avis:onglet-par-action', 'avis:pointerdown'], ArtifactValidationStats::rules($validator->lint($doc)));
    }
}
