<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scrape import state table for payload hash tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scrape_import_state (id SERIAL NOT NULL, race_date DATE NOT NULL, meeting_number INT NOT NULL, race_number INT NOT NULL, payload_hash VARCHAR(64) NOT NULL, last_imported_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_scrape_import_state_identity ON scrape_import_state (race_date, meeting_number, race_number)');
        $this->addSql('CREATE INDEX idx_scrape_import_state_hash ON scrape_import_state (payload_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scrape_import_state');
    }
}
