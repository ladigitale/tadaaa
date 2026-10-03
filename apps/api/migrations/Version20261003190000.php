<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artefacts: collecte contrôlée (intake) + jeton de lecture secret sur artifact_collections';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_collections ADD intake_schema JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_collections ADD intake_opened_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_collections ADD intake_open_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_collections ADD intake_max_records INT DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_collections ADD intake_code VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE artifact_collections ADD read_token VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifact_collections DROP intake_schema');
        $this->addSql('ALTER TABLE artifact_collections DROP intake_opened_at');
        $this->addSql('ALTER TABLE artifact_collections DROP intake_open_until');
        $this->addSql('ALTER TABLE artifact_collections DROP intake_max_records');
        $this->addSql('ALTER TABLE artifact_collections DROP intake_code');
        $this->addSql('ALTER TABLE artifact_collections DROP read_token');
    }
}
