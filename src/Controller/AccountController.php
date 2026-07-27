<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AccountController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
    ) {
    }

    #[Route('/account/orders', name: 'app_account_orders', methods: ['GET'])]
    public function orders(): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $orders = $this->orderRepository->findByUserOrderedByDate($this->getUser());

        return $this->render('account/orders.html.twig', [
            'orders' => $orders,
        ]);
    }

    #[Route('/account/orders/{id}', name: 'app_account_order_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function orderDetail(int $id): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $order = $this->orderRepository->find($id);

        if (!$order) {
            throw $this->createNotFoundException('Order not found');
        }

        if ($order->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('You are not allowed to view this order.');
        }

        return $this->render('account/order_detail.html.twig', [
            'order' => $order,
        ]);
    }
}
