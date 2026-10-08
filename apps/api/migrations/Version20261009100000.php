<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artefacts : emplacements des objets JSON vides ({}) de chaque version de document';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_versions ADD empty_objects JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_versions DROP empty_objects');
    }
}
