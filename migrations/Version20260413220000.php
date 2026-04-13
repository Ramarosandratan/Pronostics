<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Harden race date constraints and add performance indexes for race/import quality lookups';
    }

    public function up(Schema $schema): void
    {
        // Backfill race_date from source_date_code when possible before enforcing NOT NULL.
        $this->addSql("UPDATE race SET race_date = to_date(source_date_code, 'YYYYMMDD') WHERE race_date IS NULL AND source_date_code ~ '^\\d{8}$'");
        $this->addSql("UPDATE race SET source_date_code = to_char(race_date, 'YYYYMMDD') WHERE race_date IS NOT NULL AND (source_date_code IS NULL OR source_date_code = '')");

        $remainingNullDates = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM race WHERE race_date IS NULL');
        $this->abortIf($remainingNullDates > 0, 'Migration aborted: race records with NULL race_date remain after backfill.');

        $this->addSql('ALTER TABLE race ALTER COLUMN race_date SET NOT NULL');
        $this->addSql('CREATE INDEX idx_race_date ON race (race_date)');
        $this->addSql('CREATE INDEX idx_race_identity_lookup ON race (race_date, meeting_number, race_number)');
        $this->addSql('CREATE INDEX idx_scrape_import_state_last_imported ON scrape_import_state (last_imported_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_scrape_import_state_last_imported');
        $this->addSql('DROP INDEX idx_race_identity_lookup');
        $this->addSql('DROP INDEX idx_race_date');
        $this->addSql('ALTER TABLE race ALTER COLUMN race_date DROP NOT NULL');
    }
}
