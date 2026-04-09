<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'participation')]
#[ORM\UniqueConstraint(name: 'uniq_participation_race_horse', columns: ['race_id', 'horse_id'])]
#[ORM\Index(name: 'idx_participation_race', columns: ['race_id'])]
#[ORM\Index(name: 'idx_participation_horse', columns: ['horse_id'])]
class Participation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Race $race;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Horse $horse;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Person $jockey = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Person $trainer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Person $owner = null;

    #[ORM\Column(name: 'saddle_number', nullable: true)]
    private ?int $saddleNumber = null;

    #[ORM\Column(name: 'finishing_position', nullable: true)]
    private ?int $finishingPosition = null;

    #[ORM\Column(name: 'age_at_race', nullable: true)]
    private ?int $ageAtRace = null;

    #[ORM\Column(name: 'distance_or_weight', type: 'float', nullable: true)]
    private ?float $distanceOrWeight = null;

    #[ORM\Column(name: 'shoeing_or_draw', length: 50, nullable: true)]
    private ?string $shoeingOrDraw = null;

    #[ORM\Column(name: 'performance_indicator', length: 50, nullable: true)]
    private ?string $performanceIndicator = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $odds = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $music = null;

    #[ORM\Column(name: 'career_earnings', type: 'bigint', nullable: true)]
    private ?string $careerEarnings = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRace(): Race
    {
        return $this->race;
    }

    public function setRace(Race $race): self
    {
        $this->race = $race;

        return $this;
    }

    public function getHorse(): Horse
    {
        return $this->horse;
    }

    public function setHorse(Horse $horse): self
    {
        $this->horse = $horse;

        return $this;
    }

    public function getJockey(): ?Person
    {
        return $this->jockey;
    }

    public function setJockey(?Person $jockey): self
    {
        $this->jockey = $jockey;

        return $this;
    }

    public function getTrainer(): ?Person
    {
        return $this->trainer;
    }

    public function setTrainer(?Person $trainer): self
    {
        $this->trainer = $trainer;

        return $this;
    }

    public function getOwner(): ?Person
    {
        return $this->owner;
    }

    public function setOwner(?Person $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getSaddleNumber(): ?int
    {
        return $this->saddleNumber;
    }

    public function setSaddleNumber(?int $saddleNumber): self
    {
        $this->saddleNumber = $saddleNumber;

        return $this;
    }

    public function getFinishingPosition(): ?int
    {
        return $this->finishingPosition;
    }

    public function setFinishingPosition(?int $finishingPosition): self
    {
        $this->finishingPosition = $finishingPosition;

        return $this;
    }

    public function getAgeAtRace(): ?int
    {
        return $this->ageAtRace;
    }

    public function setAgeAtRace(?int $ageAtRace): self
    {
        $this->ageAtRace = $ageAtRace;

        return $this;
    }

    public function getDistanceOrWeight(): ?float
    {
        return $this->distanceOrWeight;
    }

    public function setDistanceOrWeight(?float $distanceOrWeight): self
    {
        $this->distanceOrWeight = $distanceOrWeight;

        return $this;
    }

    public function getShoeingOrDraw(): ?string
    {
        return $this->shoeingOrDraw;
    }

    public function setShoeingOrDraw(?string $shoeingOrDraw): self
    {
        $this->shoeingOrDraw = $shoeingOrDraw !== null ? trim($shoeingOrDraw) : null;

        return $this;
    }

    public function getPerformanceIndicator(): ?string
    {
        return $this->performanceIndicator;
    }

    public function setPerformanceIndicator(?string $performanceIndicator): self
    {
        $this->performanceIndicator = $performanceIndicator !== null ? trim($performanceIndicator) : null;

        return $this;
    }

    public function getOdds(): ?float
    {
        return $this->odds;
    }

    public function setOdds(?float $odds): self
    {
        $this->odds = $odds;

        return $this;
    }

    public function getMusic(): ?string
    {
        return $this->music;
    }

    public function setMusic(?string $music): self
    {
        $this->music = $music !== null ? trim($music) : null;

        return $this;
    }

    public function getCareerEarnings(): ?string
    {
        return $this->careerEarnings;
    }

    public function setCareerEarnings(?string $careerEarnings): self
    {
        $this->careerEarnings = $careerEarnings;

        return $this;
    }
}
