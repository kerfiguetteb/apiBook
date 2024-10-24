<?php

namespace App\Controller;

use App\Entity\Book;
use App\Repository\BookRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\AuthorRepository;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use JMS\Serializer\SerializerInterface;
use JMS\Serializer\SerializationContext;
use App\Service\VersioningService;
use Nelmio\ApiDocBundle\Annotation\Model;

use OpenApi\Annotations as OA;


class BookController extends AbstractController
{

    /**
    * @OA\Response(
    *     response=200,
    *     description="Retourne la liste des livres",
    *     @OA\JsonContent(
    *        type="array",
    *        @OA\Items(ref=@Model(type=Book::class,groups={"getBooks"}))
    *     )
    * )
    * @OA\Parameter(
    *     name="page",
    *     in="query",
    *     description="La page que l'on veut récupérer",
    *     @OA\Schema(type="int")
    * )
    *
    * @OA\Parameter(
    *     name="limit",
    *     in="query",
    *     description="Le nombre d'éléments que l'on veut récupérer",
    *     @OA\Schema(type="int")
    * )
    * @OA\Tag(name="Books")
     *
     * @param BookRepository $bookRepository
     * @param SerializerInterface $serializer
     * @param Request $request
     * @return JsonResponse
     */



    #[Route('/api/books', name: 'books', methods: ['GET'])]
    public function getAllBooks(BookRepository $bookRepository, SerializerInterface $serializer, Request $request, TagAwareCacheInterface $cache,): JsonResponse
    {

        $page = $request->get('page', 1);
        $limit = $request->get('limit', 10);
        $idCache = "getAllBooks-" . $page . "-" . $limit;

        $jsonBookList = $cache->get($idCache, function (ItemInterface $item) use ($bookRepository, $page, $limit, $serializer) {
            $context = SerializationContext::create()->setGroups(['getBooks']);
            $item->tag("bookCache");
            $bookList = $bookRepository->findAllWithPagination($page, $limit);
            $totalBooks = $bookRepository->count([]);
            $totalPages = ceil($totalBooks / $limit);

            $data = [
                'data' => $bookList,
                'current_page' => (int)$page,
                'total_pages' => (int)$totalPages,
                'total' => (int)$totalBooks,
                'previous_page_url' => $page > 1 ? '/api/books?page=' . ($page - 1) . '&limit=' . $limit : null,
                'next_page_url' => $page < $totalPages ? '/api/books?page=' . ($page + 1) . '&limit=' . $limit : null
            ];

            return $serializer->serialize($data, 'json', $context);
        });
        # code...

        return new JsonResponse($jsonBookList, Response::HTTP_OK, [], true);
    }


    #[Route('/api/books/{search}', name: 'booksByTitle', methods: ['GET'])]
    public function getByTitle(string $search, BookRepository $bookRepository, SerializerInterface $serializer, Request $request, TagAwareCacheInterface $cache, VersioningService $versioningService): JsonResponse
    {


        $version = $versioningService->getVersion();
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $context->setVersion($version);

        $jsonBookList = $serializer->serialize($bookRepository->findOneByTitle($search), 'json', $context);


        return new JsonResponse($jsonBookList, Response::HTTP_OK, [], true);
    }

    /**
    * Cette méthode permet de rechercher un livre par son ID.
    *
    * @OA\Response(
    *     response=200,
    *     description="Retourne un livre",
    *     @OA\JsonContent(
    *        type="array",
    *        @OA\Items(ref=@Model(type=Book::class,groups={"getBooks"}))
    *     )
    * )
    * 
    * @OA\Tag(name="Books")
    *
    * @param Book $book
    * @param SerializerInterface $serializer
    * @return JsonResponse
    */
    #[Route('/api/book/{id}', name: 'detailBook', methods: ['GET'])]
    public function getBookDetail($id, SerializerInterface $serializer, BookRepository $bookRepository, VersioningService $versioningService): JsonResponse
    {
        $book = $bookRepository->find($id);
        $version = $versioningService->getVersion();
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $context->setVersion($version);
        $jsonBook = $serializer->serialize($book, 'json', $context);

        return new JsonResponse($jsonBook, Response::HTTP_OK, [], true);
    }

    #[Route('/api/books/{id}', name: 'deleteBook', methods: ['DELETE'])]
    // #[IsGranted('ROLE_ADMIN', message: "Vous devez être administrateur pour supprimer un livre")]
    public function deleteBook(Book $book, EntityManagerInterface $em, TagAwareCacheInterface $cachePool): JsonResponse
    {
        $cachePool->invalidateTags(["bookCache"]);
        $em->remove($book);
        $em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/books', name: 'createBook', methods: ['POST'])]
    // #[IsGranted('ROLE_ADMIN', message: "Vous devez être administrateur pour créer un livre")]
    public function createBook(Request $request, SerializerInterface $serializer, EntityManagerInterface $em, UrlGeneratorInterface $urlGenerator, AuthorRepository $authorRepository, ValidatorInterface $validator): JsonResponse
    {
        $book = $serializer->deserialize($request->getContent(), Book::class, 'json');
        // On vérifie les erreurs
        $errors = $validator->validate($book);
        if (count($errors) > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }
        // Récupération de l'ensemble des données envoyées sous forme de tableau
        $content = $request->toArray();
        // Récupération de l'idAuthor. S'il n'est pas défini, alors on met -1 par défaut.
        $idAuthor = $content['idAuthor'] ?? -1;
        // On cherche l'auteur qui correspond et on l'assigne au livre.
        // Si "find" ne trouve pas l'auteur, alors null sera retourné.
        $book->setAuthor($authorRepository->find($idAuthor));

        $em->persist($book);
        $em->flush();
        $context = SerializationContext::create()->setGroups(['getBooks']);
        $jsonBook = $serializer->serialize($book, 'json', $context);
        $location = $urlGenerator->generate('detailBook', ['id' => $book->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return new JsonResponse($jsonBook, Response::HTTP_CREATED, ["Location" => $location], true);
    }

    #[Route('/api/books/{id}', name: 'updateBook', methods: ['PUT'])]
    // #[IsGranted('ROLE_ADMIN', message: "Vous devez être administrateur pour modifier un livre")]
    public function updateBook(Book $currentBook, Request $request, SerializerInterface $serializer, EntityManagerInterface $em, AuthorRepository $authorRepository, ValidatorInterface $validator, TagAwareCacheInterface $cache): JsonResponse
    {
        $newBook = $serializer->deserialize($request->getContent(), Book::class, 'json');
        $currentBook->setTitle($newBook->getTitle());
        $currentBook->setCoverText($newBook->getCoverText());
        $currentBook->setComment($newBook->getComment());

        $errors = $validator->validate($currentBook);
        if ($errors->count() > 0) {
            return new JsonResponse($serializer->serialize($errors, 'json'), JsonResponse::HTTP_BAD_REQUEST, [], true);
        }

        $content = $request->toArray();
        $idAuthor = $content['idAuthor'] ?? -1;

        $currentBook->setAuthor($authorRepository->find($idAuthor));

        $em->persist($currentBook);
        $em->flush();

        $cache->invalidateTags(["bookCache"]);

        return new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
