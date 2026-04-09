<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'race')]
#[ORM\UniqueConstraint(name: 'uniq_race_identity', columns: ['race_date', 'hippodrome', 'meeting_number', 'race_number'])]
class Race
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'race_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $raceDate = null;

    #[ORM\Column(length: 255)]
    private string $hippodrome;

    #[ORM\Column(name: 'meeting_number')]
    private int $meetingNumber;

    #[ORM\Column(name: 'race_number')]
    private int $raceNumber;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $discipline = null;

    #[ORM\Column(name: 'source_date_code', length: 30, nullable: true)]
    private ?string $sourceDateCode = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRaceDate(): ?\DateTimeImmutable
    {
        return $this->raceDate;
    }

    public function setRaceDate(?\DateTimeImmutable $raceDate): self
    {
        $this->raceDate = $raceDate;

        return $this;
    }

    public function getHippodrome(): string
    {
        return $this->hippodrome;
    }

    public function setHippodrome(string $hippodrome): self
    {
        $this->hippodrome = strtoupper(trim($hippodrome));

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

    public function getDiscipline(): ?string
    {
        return $this->discipline;
    }

    public function setDiscipline(?string $discipline): self
    {
        $this->discipline = $discipline !== null ? trim($discipline) : null;

        return $this;
    }

    public function getSourceDateCode(): ?string
    {
        return $this->sourceDateCode;
    }

    public function setSourceDateCode(?string $sourceDateCode): self
    {
        $this->sourceDateCode = $sourceDateCode !== null ? strtoupper(trim($sourceDateCode)) : null;

        return $this;
    }
}
