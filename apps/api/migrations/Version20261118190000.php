<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261118190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compteurs de validation des artefacts : documents valides et règles enfreintes, par jour et par origine';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_validation_stats (
                day DATE NOT NULL,
                source VARCHAR(16) NOT NULL,
                kind VARCHAR(8) NOT NULL,
                rule VARCHAR(120) NOT NULL,
                hits INT DEFAULT 0 NOT NULL,
                PRIMARY KEY (day, source, kind, rule)
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_validation_stats');
    }
}
