<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261123190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lieux partagés : visiteurs (pseudo, avatar dessiné) et tags « passés par là »';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_place_visitors (
                id UUID NOT NULL,
                artifact_id UUID NOT NULL,
                collection VARCHAR(64) NOT NULL,
                device_hash VARCHAR(64) NOT NULL,
                pseudo VARCHAR(160) DEFAULT NULL,
                avatar TEXT DEFAULT NULL,
                reports INT NOT NULL,
                hidden BOOLEAN NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_place_visitor_device ON artifact_place_visitors (artifact_id, collection, device_hash)');
        $this->addSql('ALTER TABLE artifact_place_visitors ADD CONSTRAINT FK_ARTIFACT_PLACE_VISITORS_ARTIFACT FOREIGN KEY (artifact_id) REFERENCES artifacts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql(<<<'SQL'
            CREATE TABLE artifact_place_tags (
                id UUID NOT NULL,
                place_id UUID NOT NULL,
                visitor_id UUID NOT NULL,
                first_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                last_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_artifact_place_tag ON artifact_place_tags (place_id, visitor_id)');
        $this->addSql('CREATE INDEX idx_artifact_place_tag_place ON artifact_place_tags (place_id, last_at)');
        $this->addSql('CREATE INDEX idx_artifact_place_tag_visitor ON artifact_place_tags (visitor_id, last_at)');
        $this->addSql('ALTER TABLE artifact_place_tags ADD CONSTRAINT FK_ARTIFACT_PLACE_TAGS_PLACE FOREIGN KEY (place_id) REFERENCES artifact_places (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE artifact_place_tags ADD CONSTRAINT FK_ARTIFACT_PLACE_TAGS_VISITOR FOREIGN KEY (visitor_id) REFERENCES artifact_place_visitors (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE artifact_place_tags');
        $this->addSql('DROP TABLE artifact_place_visitors');
    }
}
