<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260722120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Todos et tags cloud par dataset (sync phase 2)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE tags (id VARCHAR(64) NOT NULL, dataset_id UUID NOT NULL, name VARCHAR(120) NOT NULL, color VARCHAR(32) NOT NULL, field_versions JSON NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6FBC942694933E11 ON tags (dataset_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tag_dataset_id ON tags (dataset_id, id)');
        $this->addSql('COMMENT ON COLUMN tags.dataset_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN tags.deleted_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('CREATE TABLE todos (id VARCHAR(64) NOT NULL, dataset_id UUID NOT NULL, text VARCHAR(500) NOT NULL, description TEXT DEFAULT NULL, done BOOLEAN NOT NULL, archived BOOLEAN NOT NULL, priority VARCHAR(16) NOT NULL, tag_ids JSON NOT NULL, parent_id VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, field_versions JSON NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_DA6ECBE094933E11 ON todos (dataset_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_todo_dataset_id ON todos (dataset_id, id)');
        $this->addSql('COMMENT ON COLUMN todos.dataset_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN todos.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN todos.deleted_at IS \'(DC2Type:datetime_immutable)\'');

        $this->addSql('ALTER TABLE tags ADD CONSTRAINT FK_6FBC942694933E11 FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE todos ADD CONSTRAINT FK_DA6ECBE094933E11 FOREIGN KEY (dataset_id) REFERENCES datasets (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tags DROP CONSTRAINT FK_6FBC942694933E11');
        $this->addSql('ALTER TABLE todos DROP CONSTRAINT FK_DA6ECBE094933E11');
        $this->addSql('DROP TABLE tags');
        $this->addSql('DROP TABLE todos');
    }
}
