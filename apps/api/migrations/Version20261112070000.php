<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Runs d’artefacts interactifs : contrat io figé, jeton (empreinte) et expiration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_runs ADD io JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_runs ADD token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_runs ADD expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_runs DROP io');
        $this->addSql('ALTER TABLE artifact_runs DROP token_hash');
        $this->addSql('ALTER TABLE artifact_runs DROP expires_at');
    }
}
