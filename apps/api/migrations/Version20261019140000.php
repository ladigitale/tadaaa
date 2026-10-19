<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artefacts : version en ligne distincte de la version courante (brouillon avant mise en ligne)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifacts ADD live_version INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifacts DROP live_version');
    }
}
