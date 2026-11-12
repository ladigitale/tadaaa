<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Runs d’artefacts : index des runs en attente (expiration active)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_artifact_run_pending ON artifact_runs (status, expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_artifact_run_pending');
    }
}
