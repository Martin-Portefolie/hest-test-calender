<?php

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Form\ClientType;
use Doctrine\ORM\EntityManagerInterface;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ClientController extends AbstractController
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    #[Route('/admin/client', name: 'admin_client')]
    public function index(Request $request): Response
    {
        $searchTerm = $request->query->get('search');
        $queryBuilder = $this->entityManager->getRepository(Client::class)->createQueryBuilder('c');

        if ($searchTerm) {
            $queryBuilder->andWhere('c.name LIKE :search OR c.contactPerson LIKE :search OR c.contactEmail LIKE :search OR c.contactPhone LIKE :search')
                ->setParameter('search', '%'.$searchTerm.'%');
        }

        $pagerfanta = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pagerfanta->setMaxPerPage(10);
        $pagerfanta->setCurrentPage($request->query->getInt('page', 1));

        $clientdata = $this->entityManager->getRepository(Client::class)->findAll();
        $clientDataArray = [];

        foreach ($clientdata as $clients) {
            $clientDataArray[] = [
                'id' => $clients->getId(),
                'name' => $clients->getName(),
                'contact' => $clients->getContactPerson(),
                'email' => $clients->getContactEmail(),
                'phone' => $clients->getContactPhone(),
                'projects' => $clients->getProjects(),
            ];
        }

        return $this->render('admin/client/index.html.twig', [
            'controller_name' => 'ClientController',
            'clientDataArray' => $clientDataArray,
            'pager' => $pagerfanta,
        ]);
    }

    #[Route('/admin/new', name: 'admin_client_new')]
    public function new(Request $request): Response
    {
        $client = new Client();
        $form = $this->createForm(ClientType::class, $client);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($client);
            $this->entityManager->flush();

            return $this->redirectToRoute('admin_client');
        }

        return $this->render('admin/client/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
