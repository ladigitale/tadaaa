<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Artefacts : étiquettes de l\'espace servant de catégories (tag_ids)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE artifacts ADD tag_ids JSON DEFAULT '[]'::json NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE artifacts DROP tag_ids');
    }
}
