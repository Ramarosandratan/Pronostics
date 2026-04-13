<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'race')]
#[ORM\UniqueConstraint(name: 'uniq_race_identity', columns: ['race_date', 'hippodrome_id', 'meeting_number', 'race_number'])]
class Race
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'race_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $raceDate = null;

    #[ORM\ManyToOne(targetEntity: Hippodrome::class)]
    #[ORM\JoinColumn(name: 'hippodrome_id', nullable: false, onDelete: 'RESTRICT')]
    private ?Hippodrome $hippodrome = null;

    #[ORM\Column(name: 'hippodrome', length: 255, nullable: true)]
    private ?string $hippodromeName = null;

    #[ORM\Column(name: 'meeting_number')]
    private int $meetingNumber;

    #[ORM\Column(name: 'race_number')]
    private int $raceNumber;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $discipline = null;

    #[ORM\Column(name: 'distance_meters', nullable: true)]
    private ?int $distanceMeters = null;

    #[ORM\Column(type: 'bigint', nullable: true)]
    private ?string $allocation = null;

    #[ORM\Column(name: 'race_category', length: 120, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(name: 'race_time', length: 20, nullable: true)]
    private ?string $raceTime = null;

    #[ORM\Column(name: 'track_type', length: 120, nullable: true)]
    private ?string $trackType = null;

    #[ORM\Column(name: 'track_rope', length: 120, nullable: true)]
    private ?string $trackRope = null;

    #[ORM\Column(nullable: true)]
    private ?bool $autostart = null;

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

    public function getHippodrome(): ?Hippodrome
    {
        return $this->hippodrome;
    }

    public function setHippodrome(Hippodrome|string|null $hippodrome): self
    {
        if ($hippodrome instanceof Hippodrome) {
            $this->hippodrome = $hippodrome;
            $this->hippodromeName = $hippodrome->getName();

            return $this;
        }

        if (is_string($hippodrome)) {
            $this->hippodrome = null;
            $this->hippodromeName = strtoupper(trim($hippodrome));

            return $this;
        }

        $this->hippodrome = null;
        $this->hippodromeName = null;

        return $this;
    }

    public function getHippodromeName(): ?string
    {
        return $this->hippodromeName;
    }

    public function setHippodromeName(?string $hippodromeName): self
    {
        $this->hippodromeName = $hippodromeName !== null ? strtoupper(trim($hippodromeName)) : null;

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

    public function getDistanceMeters(): ?int
    {
        return $this->distanceMeters;
    }

    public function setDistanceMeters(?int $distanceMeters): self
    {
        $this->distanceMeters = $distanceMeters;

        return $this;
    }

    public function getAllocation(): ?string
    {
        return $this->allocation;
    }

    public function setAllocation(?string $allocation): self
    {
        $this->allocation = $allocation;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): self
    {
        $this->category = $category !== null ? trim($category) : null;

        return $this;
    }

    public function getRaceTime(): ?string
    {
        return $this->raceTime;
    }

    public function setRaceTime(?string $raceTime): self
    {
        $this->raceTime = $raceTime !== null ? trim($raceTime) : null;

        return $this;
    }

    public function getTrackType(): ?string
    {
        return $this->trackType;
    }

    public function setTrackType(?string $trackType): self
    {
        $this->trackType = $trackType !== null ? trim($trackType) : null;

        return $this;
    }

    public function getTrackRope(): ?string
    {
        return $this->trackRope;
    }

    public function setTrackRope(?string $trackRope): self
    {
        $this->trackRope = $trackRope !== null ? trim($trackRope) : null;

        return $this;
    }

    public function isAutostart(): ?bool
    {
        return $this->autostart;
    }

    public function setAutostart(?bool $autostart): self
    {
        $this->autostart = $autostart;

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
