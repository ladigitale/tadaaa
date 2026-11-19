<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Appairage des runs d’artefacts (io.stream.pair) : codes et appareils reliés';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_run_pair_codes (
                code VARCHAR(16) NOT NULL,
                host_run_id UUID NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (code)
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_ARTIFACT_RUN_PAIR_CODES_HOST ON artifact_run_pair_codes (host_run_id, expires_at)');
        $this->addSql('ALTER TABLE artifact_run_pair_codes ADD CONSTRAINT FK_ARTIFACT_RUN_PAIR_CODES_HOST FOREIGN KEY (host_run_id) REFERENCES artifact_runs (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_run_pair_claims (
                host_run_id UUID NOT NULL,
                remote_run_id UUID NOT NULL,
                code VARCHAR(16) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (host_run_id, remote_run_id)
            )
        SQL);
        $this->addSql('ALTER TABLE artifact_run_pair_claims ADD CONSTRAINT FK_ARTIFACT_RUN_PAIR_CLAIMS_HOST FOREIGN KEY (host_run_id) REFERENCES artifact_runs (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_run_pair_claims ADD CONSTRAINT FK_ARTIFACT_RUN_PAIR_CLAIMS_REMOTE FOREIGN KEY (remote_run_id) REFERENCES artifact_runs (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_run_pair_claims');
        $this->addSql('DROP TABLE artifact_run_pair_codes');
    }
}
