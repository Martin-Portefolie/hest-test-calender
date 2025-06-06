<?php

namespace App\Controller\Admin;

use App\Entity\Client;
use App\Entity\Team;
use App\Entity\User;
use App\Form\UserType;
use Doctrine\ORM\EntityManagerInterface;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class UsersController extends AbstractController
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher)
    {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
    }

    #[Route('/admin/user', name: 'admin_users')]
    public function index(Request $request): Response
    {
        $searchTerm = $request->query->get('search');

        // Build query for search & pagination
        $queryBuilder = $this->entityManager->getRepository(User::class)->createQueryBuilder('u')
            ->leftJoin('u.teams', 't'); // Assuming a User belongs to multiple Teams

        if ($searchTerm) {
            $queryBuilder->andWhere('u.username LIKE :search OR u.email LIKE :search')
                ->setParameter('search', '%' . $searchTerm . '%');
        }

        // Pagination setup
        $pagerfanta = new Pagerfanta(new QueryAdapter($queryBuilder));
        $pagerfanta->setMaxPerPage(10);
        $pagerfanta->setCurrentPage($request->query->getInt('page', 1));

        // Fetching all teams (for some additional use case)
        $allTeams = $this->entityManager->getRepository(Team::class)->findAll();

        $userDataArray = [];
        foreach ($pagerfanta->getCurrentPageResults() as $user) {
            $userDataArray[] = [
                'id' => $user->getId(),
                'name' => $user->getUsername(),
                'email' => $user->getEmail(),
                'password' => $user->getPassword(),
                'teams' => $user->getTeams(),
                'roles' => $user->getRoles(),
            ];
        }

        return $this->render('admin/users/index.html.twig', [
            'userDataArray' => $userDataArray,
            'allTeams' => $allTeams,
            'pager' => $pagerfanta,  // Passing pagination object to Twig
        ]);
    }


    /**
     * @throws TransportExceptionInterface
     */
    /**
     * @throws TransportExceptionInterface
     */
    #[Route('/admin/user/new', name: 'admin_user_new')]
    public function new(Request $request, MailerInterface $mailer): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (empty($user->getRoles())) {
                $user->setRoles(['ROLE_USER']);
            }

            // Generate a random password
            $plainPassword = bin2hex(random_bytes(4)); // Example: "3a1b2c4d"
            $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // Create email
            $email = (new TemplatedEmail())
                ->from('HestTest@example.com')
                ->to($user->getEmail())
                ->subject('Your Password for Hest-Test-Calender')
                ->htmlTemplate('emails/userinfo.html.twig')
                ->context([
                    'username' => $user->getUsername(),
                    'user_email' => $user->getEmail(),
                    'password' => $plainPassword,
                    'login_url' => $this->generateUrl('app_login', [], UrlGeneratorInterface::ABSOLUTE_URL),
                ]);;

            // Send email and log attempts
            try {
                $mailer->send($email);
                $this->addFlash('success', 'User created and email sent with login details.');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Email sending failed: ' . $e->getMessage());
            }

//            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/users/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }


    #[Route('/admin/user/update/{id}', name: 'admin_user_update', methods: ['POST'])]
    public function update(Request $request, int $id, UserPasswordHasherInterface $passwordHasher): Response
    {
        $user = $this->entityManager->getRepository(User::class)->find($id);

        if (!$user) {
            throw $this->createNotFoundException('User not found');
        }

        // Update basic fields
        $user->setUsername($request->request->get('name'));
        $user->setEmail($request->request->get('email'));

        // Update roles
        $roles = $request->request->all('roles'); // Use all() for array data
        $user->setRoles(!empty($roles) ? $roles : $user->getRoles());

        // Update password if provided
        $newPassword = $request->request->get('new_password');
        if (!empty($newPassword)) {
            $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
            $user->setPassword($hashedPassword);
        }

        // Update team associations
        $selectedTeamIds = $request->request->all('team_ids'); // Use all() for array data
        foreach ($user->getTeams() as $team) {
            if (!in_array($team->getId(), $selectedTeamIds)) {
                $user->removeTeam($team);
            }
        }
        foreach ($selectedTeamIds as $teamId) {
            $team = $this->entityManager->getRepository(Team::class)->find($teamId);
            if ($team && !$user->getTeams()->contains($team)) {
                $user->addTeam($team);
            }
        }

        $this->entityManager->flush();

        return $this->redirectToRoute('admin_users');
    }
}
