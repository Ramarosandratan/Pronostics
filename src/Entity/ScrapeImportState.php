<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'scrape_import_state')]
#[ORM\UniqueConstraint(name: 'uniq_scrape_import_state_identity', columns: ['race_date', 'meeting_number', 'race_number'])]
#[ORM\Index(name: 'idx_scrape_import_state_hash', columns: ['payload_hash'])]
#[ORM\Index(name: 'idx_scrape_import_state_last_imported', columns: ['last_imported_at'])]
class ScrapeImportState
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'race_date', type: 'date_immutable')]
    private \DateTimeImmutable $raceDate;

    #[ORM\Column(name: 'meeting_number')]
    private int $meetingNumber;

    #[ORM\Column(name: 'race_number')]
    private int $raceNumber;

    #[ORM\Column(name: 'payload_hash', length: 64)]
    private string $payloadHash;

    #[ORM\Column(name: 'last_imported_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $lastImportedAt;

    public function __construct()
    {
        $this->lastImportedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRaceDate(): \DateTimeImmutable
    {
        return $this->raceDate;
    }

    public function setRaceDate(\DateTimeImmutable $raceDate): self
    {
        $this->raceDate = $raceDate;

        return $this;
    }

    public function getMeetingNumber(): int
    {
        return $this->meetingNumber;
    }

    public function setMeetingNumber(int $meetingNumber): self
    {
        $this->meetingNumber = $meetingNumber;

        return $this;
    }

    public function getRaceNumber(): int
    {
        return $this->raceNumber;
    }

    public function setRaceNumber(int $raceNumber): self
    {
        $this->raceNumber = $raceNumber;

        return $this;
    }

    public function getPayloadHash(): string
    {
        return $this->payloadHash;
    }

    public function setPayloadHash(string $payloadHash): self
    {
        $this->payloadHash = trim($payloadHash);

        return $this;
    }

    public function getLastImportedAt(): \DateTimeImmutable
    {
        return $this->lastImportedAt;
    }

    public function setLastImportedAt(\DateTimeImmutable $lastImportedAt): self
    {
        $this->lastImportedAt = $lastImportedAt;

        return $this;
    }
}
