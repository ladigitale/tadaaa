<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Todo start_at/end_at: DATE → DATETIME (optional time, UTC)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todos ALTER start_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('ALTER TABLE todos ALTER end_at TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql('COMMENT ON COLUMN todos.start_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN todos.end_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE todos ALTER start_at TYPE DATE');
        $this->addSql('ALTER TABLE todos ALTER end_at TYPE DATE');
        $this->addSql('COMMENT ON COLUMN todos.start_at IS \'(DC2Type:date_immutable)\'');
        $this->addSql('COMMENT ON COLUMN todos.end_at IS \'(DC2Type:date_immutable)\'');
    }
}
