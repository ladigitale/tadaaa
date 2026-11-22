<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discussion des lieux : messages retouchés, retirés avec trace, référence du mur des dessins';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_place_messages ADD ref_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_place_messages ADD edited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_place_messages ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_place_messages ADD deleted_by VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_place_messages DROP ref_id');
        $this->addSql('ALTER TABLE artifact_place_messages DROP edited_at');
        $this->addSql('ALTER TABLE artifact_place_messages DROP deleted_at');
        $this->addSql('ALTER TABLE artifact_place_messages DROP deleted_by');
    }
}
