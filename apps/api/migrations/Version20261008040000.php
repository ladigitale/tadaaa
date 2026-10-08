<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Datasets: indicateur artifact_store (jeu dédié aux artefacts, non supprimable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE datasets ADD artifact_store BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE datasets DROP artifact_store');
    }
}
