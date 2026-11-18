<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Liens entre runs d’artefacts en flux (file d’envoi d’un run → flux d’un autre)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_run_links (
                id UUID NOT NULL,
                from_run_id UUID NOT NULL,
                to_run_id UUID NOT NULL,
                created_by_id UUID DEFAULT NULL,
                map TEXT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                active BOOLEAN DEFAULT true NOT NULL,
                forwarded INT DEFAULT 0 NOT NULL,
                failed INT DEFAULT 0 NOT NULL,
                last_error TEXT DEFAULT NULL,
                last_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_artifact_run_links_from ON artifact_run_links (from_run_id)');
        $this->addSql('CREATE INDEX IDX_ARTIFACT_RUN_LINKS_TO ON artifact_run_links (to_run_id)');
        $this->addSql('CREATE INDEX IDX_ARTIFACT_RUN_LINKS_USER ON artifact_run_links (created_by_id)');
        $this->addSql('ALTER TABLE artifact_run_links ADD CONSTRAINT FK_ARTIFACT_RUN_LINKS_FROM FOREIGN KEY (from_run_id) REFERENCES artifact_runs (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_run_links ADD CONSTRAINT FK_ARTIFACT_RUN_LINKS_TO FOREIGN KEY (to_run_id) REFERENCES artifact_runs (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_run_links ADD CONSTRAINT FK_ARTIFACT_RUN_LINKS_USER FOREIGN KEY (created_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_run_links');
    }
}
