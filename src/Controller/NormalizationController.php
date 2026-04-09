<?php

namespace App\Controller;

use App\Entity\Horse;
use App\Entity\HorseAlias;
use App\Entity\Person;
use App\Entity\PersonAlias;
use App\Service\NameSimilarityService;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/gestion/normalisation')]
class NormalizationController extends AbstractController
{
    #[Route('', name: 'app_management_normalization', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $entityManager,
        NameSimilarityService $similarityService
    ): Response {
        if ($request->isMethod('POST')) {
            $this->handleAliasPost($request, $entityManager, $similarityService);

            return $this->redirectToRoute('app_management_normalization');
        }

        $horses = $entityManager->getRepository(Horse::class)->findBy([], ['name' => 'ASC'], 250);
        $people = $entityManager->getRepository(Person::class)->findBy([], ['name' => 'ASC'], 250);

        $horseSuggestions = $similarityService->findClosePairs(
            array_map(static fn (Horse $horse): string => $horse->getName(), $horses),
            0.85,
            40
        );
        $personSuggestions = $similarityService->findClosePairs(
            array_map(static fn (Person $person): string => $person->getName(), $people),
            0.85,
            40
        );

        $horseAliases = [];
        $personAliases = [];

        try {
            $horseAliases = $entityManager->getRepository(HorseAlias::class)->findBy([], ['id' => 'DESC'], 100);
            $personAliases = $entityManager->getRepository(PersonAlias::class)->findBy([], ['id' => 'DESC'], 100);
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', 'Tables alias absentes: lancez les migrations Doctrine.');
        }

        return $this->render('manage/normalization.html.twig', [
            'horses' => $horses,
            'people' => $people,
            'horseSuggestions' => $horseSuggestions,
            'personSuggestions' => $personSuggestions,
            'horseAliases' => $horseAliases,
            'personAliases' => $personAliases,
        ]);
    }

    private function handleAliasPost(
        Request $request,
        EntityManagerInterface $entityManager,
        NameSimilarityService $similarityService
    ): void {
        $type = trim((string) $request->request->get('type', ''));
        $targetId = (int) $request->request->get('target_id', 0);
        $aliasName = trim((string) $request->request->get('alias_name', ''));
        $errorMessage = $this->validateAliasPayload($type, $targetId, $aliasName);
        if ($errorMessage === null) {
            $canonical = $similarityService->canonicalize($aliasName);
            $errorMessage = $type === 'horse'
                ? $this->persistHorseAlias($entityManager, $targetId, $aliasName, $canonical)
                : $this->persistPersonAlias($entityManager, $targetId, $aliasName, $canonical);
        }

        if ($errorMessage !== null) {
            $this->addFlash('error', $errorMessage);
        } else {
            $entityManager->flush();
            $this->addFlash('success', 'Alias cree avec succes.');
        }
    }

    private function validateAliasPayload(string $type, int $targetId, string $aliasName): ?string
    {
        if (!in_array($type, ['horse', 'person'], true) || $targetId <= 0 || $aliasName === '') {
            return 'Type, cible et alias sont obligatoires.';
        }

        return null;
    }

    private function persistHorseAlias(EntityManagerInterface $entityManager, int $targetId, string $aliasName, string $canonical): ?string
    {
        $horse = $entityManager->getRepository(Horse::class)->find($targetId);
        if (!$horse instanceof Horse) {
            return 'Cheval cible introuvable.';
        }

        $existing = $entityManager->getRepository(HorseAlias::class)->findOneBy(['canonicalForm' => $canonical]);
        if ($existing instanceof HorseAlias) {
            return 'Alias deja utilise pour un cheval.';
        }

        $entityManager->persist(
            (new HorseAlias())
                ->setHorse($horse)
                ->setOriginalForm($aliasName)
                ->setCanonicalForm($canonical)
        );

        return null;
    }

    private function persistPersonAlias(EntityManagerInterface $entityManager, int $targetId, string $aliasName, string $canonical): ?string
    {
        $person = $entityManager->getRepository(Person::class)->find($targetId);
        if (!$person instanceof Person) {
            return 'Personne cible introuvable.';
        }

        $existing = $entityManager->getRepository(PersonAlias::class)->findOneBy(['canonicalForm' => $canonical]);
        if ($existing instanceof PersonAlias) {
            return 'Alias deja utilise pour une personne.';
        }

        $entityManager->persist(
            (new PersonAlias())
                ->setPerson($person)
                ->setOriginalForm($aliasName)
                ->setCanonicalForm($canonical)
        );

        return null;
    }

    private function isMissingTableException(\Throwable $exception): bool
    {
        if ($exception instanceof TableNotFoundException) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'relation')
            && str_contains(strtolower($exception->getMessage()), 'does not exist');
    }
}

