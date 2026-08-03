<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Repository\CategoryRepository;
use App\Repository\OrderItemRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/products')]
class ProductController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly CategoryRepository $categoryRepository,
        private readonly OrderItemRepository $orderItemRepository,
    ) {
    }

    #[Route('', name: 'app_admin_product_index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/product/index.html.twig', [
            'products' => $this->productRepository->findBy([], ['id' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'app_admin_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $product = new Product();
        $errors = [];

        if ($request->isMethod('POST')) {
            $errors = $this->handleProductForm($request, $product);

            if (count($errors) === 0) {
                $this->entityManager->persist($product);
                $this->entityManager->flush();

                $this->addFlash('success', 'Product created successfully.');

                return $this->redirectToRoute('app_admin_product_index');
            }
        }

        return $this->render('admin/product/new.html.twig', [
            'product' => $product,
            'categories' => $this->categoryRepository->findBy([], ['name' => 'ASC']),
            'errors' => $errors,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_product_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $product = $this->productRepository->find($id);

        if (!$product) {
            throw $this->createNotFoundException('Product not found.');
        }

        $errors = [];

        if ($request->isMethod('POST')) {
            $errors = $this->handleProductForm($request, $product);

            if (count($errors) === 0) {
                $this->entityManager->flush();

                $this->addFlash('success', 'Product updated successfully.');

                return $this->redirectToRoute('app_admin_product_index');
            }
        }

        return $this->render('admin/product/edit.html.twig', [
            'product' => $product,
            'categories' => $this->categoryRepository->findBy([], ['name' => 'ASC']),
            'errors' => $errors,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_admin_product_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $product = $this->productRepository->find($id);

        if (!$product) {
            throw $this->createNotFoundException('Product not found.');
        }

        if (!$this->isCsrfTokenValid('delete_product_'.$product->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');

            return $this->redirectToRoute('app_admin_product_index');
        }

        $existingOrderItem = $this->orderItemRepository->findOneBy([
            'product' => $product,
        ]);

        if ($existingOrderItem) {
            $this->addFlash('error', 'This product cannot be deleted because it already exists in historical orders.');

            return $this->redirectToRoute('app_admin_product_index');
        }

        $this->entityManager->remove($product);
        $this->entityManager->flush();

        $this->addFlash('success', 'Product deleted successfully.');

        return $this->redirectToRoute('app_admin_product_index');
    }

    /**
     * @return array<string, string>
     */
    private function handleProductForm(Request $request, Product $product): array
    {
        $errors = [];

        $name = trim((string) $request->request->get('name'));
        $description = trim((string) $request->request->get('description'));
        $price = trim((string) $request->request->get('price'));
        $stock = trim((string) $request->request->get('stock'));
        $imageUrl = trim((string) $request->request->get('imageUrl'));
        $categoryId = (int) $request->request->get('category');

        if ($name === '') {
            $errors['name'] = 'Product name is required.';
        }

        if ($description === '') {
            $errors['description'] = 'Description is required.';
        }

        if ($price === '') {
            $errors['price'] = 'Price is required.';
        } elseif (!is_numeric($price) || (float) $price <= 0) {
            $errors['price'] = 'Price must be a positive number.';
        }

        if ($stock === '') {
            $errors['stock'] = 'Stock is required.';
        } elseif (!ctype_digit($stock)) {
            $errors['stock'] = 'Stock must be a positive number or zero.';
        }

        $category = $this->categoryRepository->find($categoryId);

        if (!$category) {
            $errors['category'] = 'Category is required.';
        }

        if (count($errors) > 0) {
            return $errors;
        }

        $product
            ->setName($name)
            ->setDescription($description)
            ->setPrice(number_format((float) $price, 2, '.', ''))
            ->setStock((int) $stock)
            ->setImageUrl($imageUrl !== '' ? $imageUrl : null)
            ->setCategory($category);

        return [];
    }
}