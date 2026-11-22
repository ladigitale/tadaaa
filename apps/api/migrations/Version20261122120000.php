<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discussion des lieux : message posé sur place ou commentaire à distance';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_place_messages ADD on_site BOOLEAN DEFAULT true NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_place_messages DROP on_site');
    }
}
