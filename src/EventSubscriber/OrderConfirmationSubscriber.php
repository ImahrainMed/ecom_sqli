<?php

namespace App\EventSubscriber;

use App\Event\OrderPlacedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Sends the order confirmation email once an order has been successfully
 * placed. Deliberately swallows every failure: a broken mail server must
 * never turn a successful checkout into a failed one, since by the time
 * OrderPlacedEvent fires the order is already committed to the database.
 */
class OrderConfirmationSubscriber implements EventSubscriberInterface
{
    private const FROM_ADDRESS = 'no-reply@ecom-sqli.test';
    private const FROM_NAME = 'Ecom SQLI';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            OrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(OrderPlacedEvent $event): void
    {
        $order = $event->getOrder();

        try {
            $recipient = $order->getUser()?->getEmail();

            if (!$recipient) {
                $this->logger->error('Order confirmation email not sent: no recipient email.', [
                    'orderId' => $order->getId(),
                ]);

                return;
            }

            $email = (new TemplatedEmail())
                ->from(new Address(self::FROM_ADDRESS, self::FROM_NAME))
                ->to($recipient)
                ->subject(sprintf('Confirmation de votre commande #%d', $order->getId()))
                ->htmlTemplate('emails/order_confirmation.html.twig')
                ->context(['order' => $order]);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send order confirmation email.', [
                'orderId' => $order->getId(),
                'exception' => $e,
            ]);
        }
    }
}
