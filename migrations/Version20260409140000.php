<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hippodrome_id FK to race table and backfill from text field';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race ADD hippodrome_id INT');

        $this->addSql('
            UPDATE race r
            SET hippodrome_id = h.id
            FROM hippodrome h
            WHERE UPPER(TRIM(r.hippodrome)) = UPPER(TRIM(h.name))
        ');

        $this->addSql('ALTER TABLE race ADD CONSTRAINT FK_8E90B6E6A47ECB98 FOREIGN KEY (hippodrome_id) REFERENCES hippodrome (id) ON DELETE RESTRICT');

        $this->addSql('CREATE INDEX IDX_8E90B6E6A47ECB98 ON race (hippodrome_id)');

        $this->addSql('ALTER TABLE race DROP CONSTRAINT IF EXISTS uniq_race_identity');
        $this->addSql('DROP INDEX IF EXISTS uniq_race_identity');

        $this->addSql('ALTER TABLE race ALTER COLUMN hippodrome DROP NOT NULL');

        $this->addSql('ALTER TABLE race ADD CONSTRAINT uniq_race_identity UNIQUE (race_date, hippodrome_id, meeting_number, race_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE race DROP CONSTRAINT uniq_race_identity');

        $this->addSql('
            UPDATE race
            SET hippodrome = h.name
            FROM hippodrome h
            WHERE race.hippodrome_id = h.id
        ');

        $this->addSql('ALTER TABLE race ALTER COLUMN hippodrome SET NOT NULL');

        $this->addSql('DROP INDEX IDX_8E90B6E6A47ECB98');

        $this->addSql('ALTER TABLE race DROP CONSTRAINT FK_8E90B6E6A47ECB98');

        $this->addSql('ALTER TABLE race DROP COLUMN hippodrome_id');

        $this->addSql('ALTER TABLE race ADD CONSTRAINT uniq_race_identity UNIQUE (race_date, hippodrome, meeting_number, race_number)');
    }
}
