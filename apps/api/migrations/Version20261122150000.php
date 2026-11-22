<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discussion des lieux : cadre des dessins';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_place_messages ADD frame SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_place_messages DROP frame');
    }
}
