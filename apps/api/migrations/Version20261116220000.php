<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261116220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sauvegardes en ligne des visiteurs d’un artefact (data.persist.online)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_saves (
                id UUID NOT NULL,
                artifact_id UUID NOT NULL,
                key_hash VARCHAR(64) NOT NULL,
                owner_id UUID DEFAULT NULL,
                data JSON NOT NULL,
                revision INT NOT NULL,
                size_bytes INT NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_save_key ON artifact_saves (artifact_id, key_hash)');
        $this->addSql('CREATE INDEX idx_artifact_save_owner ON artifact_saves (artifact_id, owner_id)');
        $this->addSql('ALTER TABLE artifact_saves ADD CONSTRAINT FK_ARTIFACT_SAVE_ARTIFACT FOREIGN KEY (artifact_id) REFERENCES artifacts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_saves ADD CONSTRAINT FK_ARTIFACT_SAVE_OWNER FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_saves');
    }
}
