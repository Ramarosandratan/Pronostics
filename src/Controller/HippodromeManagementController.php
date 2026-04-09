<?php

namespace App\Controller;

use App\Entity\Hippodrome;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/gestion/hippodromes')]
class HippodromeManagementController extends AbstractController
{
    private const ITEMS_PER_PAGE = 25;
    /** @var int[] */
    private const PER_PAGE_OPTIONS = [10, 25, 50];

    #[Route('', name: 'app_management_hippodromes', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        if ($request->isMethod('POST')) {
            $name = trim((string) $request->request->get('name', ''));
            if ($name === '') {
                $this->addFlash('error', 'Le nom est obligatoire.');
            } else {
                try {
                    $entityManager->persist((new Hippodrome())->setName($name));
                    $entityManager->flush();
                    $this->addFlash('success', 'Hippodrome ajoute avec succes.');
                } catch (UniqueConstraintViolationException) {
                    $this->addFlash('error', 'Cet hippodrome existe deja.');
                }
            }

            return $this->redirectToRoute('app_management_hippodromes');
        }

        $hippodromes = [];
        $editHippodrome = null;
        $perPage = $this->resolvePerPage($request, self::ITEMS_PER_PAGE);
        $pagination = $this->buildPagination(0, 1, $perPage);
        $page = max(1, (int) $request->query->get('page', 1));
        $editId = (int) $request->query->get('edit', 0);
        try {
            $totalItems = $entityManager->getRepository(Hippodrome::class)->count([]);
            $pagination = $this->buildPagination($totalItems, $page, $perPage);
            $hippodromes = $entityManager->getRepository(Hippodrome::class)->findBy(
                [],
                ['name' => 'ASC'],
                $perPage,
                $pagination['offset']
            );

            if ($editId > 0) {
                $editHippodrome = $entityManager->getRepository(Hippodrome::class)->find($editId);
            }
        } catch (\Throwable $exception) {
            if (!$this->isMissingTableException($exception)) {
                throw $exception;
            }

            $this->addFlash('error', 'Table absente: lancez les migrations Doctrine pour afficher les hippodromes.');
        }

        return $this->render('manage/hippodromes.html.twig', [
            'hippodromes' => $hippodromes,
            'editHippodrome' => $editHippodrome,
            'pagination' => $pagination,
            'perPage' => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    private function resolvePerPage(Request $request, int $defaultPerPage): int
    {
        $requested = (int) $request->query->get('per_page', $defaultPerPage);

        if (in_array($requested, self::PER_PAGE_OPTIONS, true)) {
            return $requested;
        }

        return $defaultPerPage;
    }

    /**
     * @return array{page: int, per_page: int}
     */
    private function readListContext(Request $request, int $defaultPerPage): array
    {
        $page = max(1, (int) $request->request->get('page', 1));
        $perPage = (int) $request->request->get('per_page', $defaultPerPage);
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = $defaultPerPage;
        }

        return [
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    #[Route('/{id}/modifier', name: 'app_management_hippodrome_update', methods: ['POST'])]
    public function update(Hippodrome $hippodrome, Request $request, EntityManagerInterface $entityManager): Response
    {
        $context = $this->readListContext($request, self::ITEMS_PER_PAGE);
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', 'Le nom est obligatoire.');

            return $this->redirectToRoute('app_management_hippodromes', $context + ['edit' => $hippodrome->getId()]);
        } else {
            try {
                $hippodrome->setName($name);
                $entityManager->flush();
                $this->addFlash('success', 'Hippodrome modifie avec succes.');
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Cet hippodrome existe deja.');

                return $this->redirectToRoute('app_management_hippodromes', $context + ['edit' => $hippodrome->getId()]);
            }
        }

        return $this->redirectToRoute('app_management_hippodromes', $context);
    }

    #[Route('/{id}/supprimer', name: 'app_management_hippodrome_delete', methods: ['POST'])]
    public function delete(Hippodrome $hippodrome, EntityManagerInterface $entityManager): Response
    {
        try {
            $entityManager->remove($hippodrome);
            $entityManager->flush();
            $this->addFlash('success', 'Hippodrome supprime avec succes.');
        } catch (\Throwable $exception) {
            $this->addFlash('error', 'Suppression impossible pour cet hippodrome.');
        }

        return $this->redirectToRoute('app_management_hippodromes');
    }

    private function isMissingTableException(\Throwable $exception): bool
    {
        if ($exception instanceof TableNotFoundException) {
            return true;
        }

        return str_contains(strtolower($exception->getMessage()), 'relation')
            && str_contains(strtolower($exception->getMessage()), 'does not exist');
    }

    /**
     * @return array{page: int, total_pages: int, total_items: int, per_page: int, offset: int, has_prev: bool, has_next: bool, prev_page: int, next_page: int}
     */
    private function buildPagination(int $totalItems, int $requestedPage, int $perPage): array
    {
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = min(max(1, $requestedPage), $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'page' => $page,
            'total_pages' => $totalPages,
            'total_items' => $totalItems,
            'per_page' => $perPage,
            'offset' => $offset,
            'has_prev' => $page > 1,
            'has_next' => $page < $totalPages,
            'prev_page' => max(1, $page - 1),
            'next_page' => min($totalPages, $page + 1),
        ];
    }
}
