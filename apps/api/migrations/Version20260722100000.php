<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260722100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Users, datasets cloud et jeu actif par compte';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (id UUID NOT NULL, active_dataset_id UUID DEFAULT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7927C74 ON users (email)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1483A5E9E7C9D9C8 ON users (active_dataset_id)');
        $this->addSql('COMMENT ON COLUMN users.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN users.active_dataset_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN users.created_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE datasets (id UUID NOT NULL, owner_id UUID NOT NULL, base_id UUID NOT NULL, name VARCHAR(120) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D2CFF066F7C8F2BF ON datasets (base_id)');
        $this->addSql('CREATE INDEX IDX_D2CFF0667E3C61F9 ON datasets (owner_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_dataset_owner_name ON datasets (owner_id, name)');
        $this->addSql('COMMENT ON COLUMN datasets.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN datasets.owner_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN datasets.base_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN datasets.updated_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('ALTER TABLE datasets ADD CONSTRAINT FK_D2CFF0667E3C61F9 FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE users ADD CONSTRAINT FK_1483A5E9E7C9D9C8 FOREIGN KEY (active_dataset_id) REFERENCES datasets (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP CONSTRAINT FK_1483A5E9E7C9D9C8');
        $this->addSql('ALTER TABLE datasets DROP CONSTRAINT FK_D2CFF0667E3C61F9');
        $this->addSql('DROP TABLE datasets');
        $this->addSql('DROP TABLE users');
    }
}
