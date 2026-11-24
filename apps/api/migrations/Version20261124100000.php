<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261124100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Modération de tous les artefacts : dossiers ouverts par les signalements (balises, messages, blazes, parcours)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE moderation_cases (
                id UUID NOT NULL,
                artifact_id UUID NOT NULL,
                kind VARCHAR(16) NOT NULL,
                target_id UUID NOT NULL,
                reports INT NOT NULL,
                status VARCHAR(16) NOT NULL,
                first_reported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_reported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                decided_by_id UUID DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_moderation_case_target ON moderation_cases (kind, target_id)');
        $this->addSql('CREATE INDEX idx_moderation_case_queue ON moderation_cases (status, last_reported_at)');
        $this->addSql('CREATE INDEX idx_moderation_case_artifact ON moderation_cases (artifact_id)');
        $this->addSql('ALTER TABLE moderation_cases ADD CONSTRAINT FK_MODERATION_CASES_ARTIFACT FOREIGN KEY (artifact_id) REFERENCES artifacts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE moderation_cases ADD CONSTRAINT FK_MODERATION_CASES_DECIDED_BY FOREIGN KEY (decided_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        // Signalements déjà en base : un dossier chacun, à traiter s'il est masqué ou signalé.
        foreach (['place' => 'artifact_places', 'route' => 'artifact_routes'] as $kind => $table) {
            $this->addSql("INSERT INTO moderation_cases (id, artifact_id, kind, target_id, reports, status, first_reported_at, last_reported_at)
                SELECT gen_random_uuid(), artifact_id, '$kind', id, reports, 'open', NOW(), NOW() FROM $table WHERE reports > 0");
        }
        $this->addSql("INSERT INTO moderation_cases (id, artifact_id, kind, target_id, reports, status, first_reported_at, last_reported_at)
            SELECT gen_random_uuid(), p.artifact_id, 'message', m.id, m.reports, 'open', NOW(), NOW() FROM artifact_place_messages m JOIN artifact_places p ON p.id = m.place_id WHERE m.reports > 0 AND m.deleted_at IS NULL");
        $this->addSql("INSERT INTO moderation_cases (id, artifact_id, kind, target_id, reports, status, first_reported_at, last_reported_at)
            SELECT gen_random_uuid(), artifact_id, 'visitor', id, reports, 'open', NOW(), NOW() FROM artifact_place_visitors WHERE reports > 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE moderation_cases');
    }
}
