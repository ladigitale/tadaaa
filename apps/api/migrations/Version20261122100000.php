<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Appairage : rôles (un code par rôle) et places libérées (appareil retiré ou terminé)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE artifact_run_pair_codes ADD role VARCHAR(24) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE artifact_run_pair_claims ADD role VARCHAR(24) DEFAULT '' NOT NULL");
        $this->addSql('ALTER TABLE artifact_run_pair_claims ADD released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_ARTIFACT_RUN_PAIR_CLAIMS_REMOTE ON artifact_run_pair_claims (remote_run_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_ARTIFACT_RUN_PAIR_CLAIMS_REMOTE');
        $this->addSql('ALTER TABLE artifact_run_pair_claims DROP released_at');
        $this->addSql('ALTER TABLE artifact_run_pair_claims DROP role');
        $this->addSql('ALTER TABLE artifact_run_pair_codes DROP role');
    }
}
