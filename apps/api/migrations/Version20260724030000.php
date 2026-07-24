<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260724030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Composite PK (dataset_id, id) for tags and todos — ids are scoped per dataset';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tags DROP CONSTRAINT tags_pkey');
        $this->addSql('DROP INDEX IF EXISTS uniq_tag_dataset_id');
        $this->addSql('ALTER TABLE tags ADD PRIMARY KEY (dataset_id, id)');

        $this->addSql('ALTER TABLE todos DROP CONSTRAINT todos_pkey');
        $this->addSql('DROP INDEX IF EXISTS uniq_todo_dataset_id');
        $this->addSql('ALTER TABLE todos ADD PRIMARY KEY (dataset_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tags DROP CONSTRAINT tags_pkey');
        $this->addSql('ALTER TABLE tags ADD PRIMARY KEY (id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_tag_dataset_id ON tags (dataset_id, id)');

        $this->addSql('ALTER TABLE todos DROP CONSTRAINT todos_pkey');
        $this->addSql('ALTER TABLE todos ADD PRIMARY KEY (id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_todo_dataset_id ON todos (dataset_id, id)');
    }
}
