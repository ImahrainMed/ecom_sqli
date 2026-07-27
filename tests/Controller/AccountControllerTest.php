<?php

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AccountControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    /** @var object[] */
    private array $createdEntities = [];

    protected function tearDown(): void
    {
        if ($this->createdEntities !== []) {
            foreach (array_reverse($this->createdEntities) as $entity) {
                if ($this->entityManager->contains($entity)) {
                    $this->entityManager->remove($entity);
                }
            }
            $this->entityManager->flush();
            $this->createdEntities = [];
        }

        parent::tearDown();
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('not-used-in-tests');

        $this->entityManager->persist($user);
        $this->createdEntities[] = $user;

        return $user;
    }

    private function createProduct(string $name, string $price = '19.99'): Product
    {
        $category = new Category();
        $category->setName('Test Category ' . uniqid());
        $category->setSlug('test-category-' . uniqid());
        $this->entityManager->persist($category);
        $this->createdEntities[] = $category;

        $product = new Product();
        $product->setName($name);
        $product->setDescription('Test description');
        $product->setPrice($price);
        $product->setStock(50);
        $product->setImageUrl(null);
        $product->setCategory($category);

        $this->entityManager->persist($product);
        $this->createdEntities[] = $product;

        return $product;
    }

    private function createOrder(User $user, Product $product, \DateTimeImmutable $createdAt): Order
    {
        $order = new Order();
        $order->setUser($user);
        $order->setStatus(OrderStatus::Placed);
        $order->setCreatedAt($createdAt);
        $order->setTotal('39.98');
        $order->setStreet('12 rue de Test');
        $order->setCity('Casablanca');
        $order->setPostalCode('20000');
        $order->setCountry('Morocco');

        $orderItem = new OrderItem();
        $orderItem->setProduct($product);
        $orderItem->setQuantity(2);
        $orderItem->setUnitPrice($product->getPrice());
        $order->addOrderItem($orderItem);

        $this->entityManager->persist($order);
        $this->entityManager->persist($orderItem);
        $this->createdEntities[] = $orderItem;
        $this->createdEntities[] = $order;

        return $order;
    }

    public function testOrdersPageListsOwnOrdersSortedByDateDescending(): void
    {
        $client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->createUser('history-user-' . uniqid() . '@example.com');
        $product = $this->createProduct('History Product');

        $olderOrder = $this->createOrder($user, $product, new \DateTimeImmutable('-2 days'));
        $newerOrder = $this->createOrder($user, $product, new \DateTimeImmutable('-1 hour'));
        $this->entityManager->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/account/orders');

        $this->assertResponseIsSuccessful();

        $orderIdsInPageOrder = $crawler->filter('.cart-table tbody tr td:first-child')
            ->each(fn ($node) => trim($node->text()));

        $this->assertSame(
            ['#' . $newerOrder->getId(), '#' . $olderOrder->getId()],
            $orderIdsInPageOrder
        );

        $detailLink = $crawler->filter('a[href="/account/orders/' . $newerOrder->getId() . '"]');
        $this->assertGreaterThan(0, $detailLink->count());
    }

    public function testOrderDetailShowsItemsQuantitiesPricesStatusAndAddress(): void
    {
        $client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $user = $this->createUser('detail-user-' . uniqid() . '@example.com');
        $product = $this->createProduct('Detail Product', '19.99');
        $order = $this->createOrder($user, $product, new \DateTimeImmutable('-1 day'));
        $this->entityManager->flush();

        $client->loginUser($user);
        $client->request('GET', '/account/orders/' . $order->getId());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', '#' . $order->getId());
        $this->assertSelectorTextContains('body', 'Detail Product');
        $this->assertSelectorTextContains('body', 'Placed');
        $this->assertSelectorTextContains('body', '12 rue de Test');
        $this->assertSelectorTextContains('body', 'Casablanca');
        $this->assertSelectorTextContains('body', '19.99');
    }

    public function testOrdersPageRequiresAuthentication(): void
    {
        $client = static::createClient();

        $client->request('GET', '/account/orders');

        // Since SecurityController's form_login was wired up (access_control
        // now covers ^/account with ROLE_USER), an anonymous request is
        // redirected to the login page by the real authentication entry
        // point, rather than the bare 401 returned when no entry point was
        // configured.
        $this->assertResponseRedirects('/login');
    }

    public function testUserCannotViewAnotherUsersOrderDetail(): void
    {
        $client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $victim = $this->createUser('victim-' . uniqid() . '@example.com');
        $attacker = $this->createUser('attacker-' . uniqid() . '@example.com');
        $product = $this->createProduct('Sensitive Product');
        $victimOrder = $this->createOrder($victim, $product, new \DateTimeImmutable('-3 days'));
        $this->entityManager->flush();

        $client->loginUser($attacker);
        $client->request('GET', '/account/orders/' . $victimOrder->getId());

        $this->assertResponseStatusCodeSame(403);
        $this->assertStringNotContainsString('Sensitive Product', (string) $client->getResponse()->getContent());
    }
}
