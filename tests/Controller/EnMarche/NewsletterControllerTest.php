<?php

namespace Tests\AppBundle\Controller\EnMarche;

use AppBundle\DataFixtures\ORM\LoadNewsletterSubscriptionData;
use AppBundle\Entity\NewsletterSubscription;
use AppBundle\Mailjet\Message\NewsletterInvitationMessage;
use AppBundle\Mailjet\Message\NewsletterSubscriptionMessage;
use AppBundle\Repository\MailjetEmailRepository;
use AppBundle\Repository\NewsletterInviteRepository;
use AppBundle\Repository\NewsletterSubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Client;
use AppBundle\DataFixtures\ORM\LoadHomeBlockData;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\AppBundle\Controller\ControllerTestTrait;
use Tests\AppBundle\SqliteWebTestCase;

/**
 * @group functional
 */
class NewsletterControllerTest extends SqliteWebTestCase
{
    use ControllerTestTrait;

    /** @var Client */
    private $client;

    /** @var NewsletterSubscriptionRepository */
    private $subscriptionsRepository;

    /** @var NewsletterInviteRepository */
    private $newsletterInviteRepository;

    /** @var MailjetEmailRepository */
    private $mailjetEmailRepository;

    public function testSubscriptionAndRetry()
    {
        $this->assertCount(5, $this->subscriptionsRepository->findAll());

        // Initial form
        $crawler = $this->client->request(Request::METHOD_GET, '/newsletter');

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $this->client->submit($crawler->filter('form[name=app_newsletter_subscription]')->form([
            'app_newsletter_subscription[email]' => 'titouan.galopin@en-marche.fr',
            'app_newsletter_subscription[postalCode]' => '10000',
        ]));

        // Subscription should have been saved
        $this->assertCount(6, $subscriptions = $this->subscriptionsRepository->findAll());

        /** @var NewsletterSubscription $subscription */
        $subscription = $subscriptions[5];

        $this->assertSame('titouan.galopin@en-marche.fr', $subscription->getEmail());
        $this->assertSame('10000', $subscription->getPostalCode());
        $this->assertResponseStatusCode(Response::HTTP_FOUND, $this->client->getResponse());

        // Email should have been sent
        $this->assertCount(1, $this->mailjetEmailRepository->findMessages(NewsletterSubscriptionMessage::class));

        // Try another time with the same email (should fail)
        $crawler = $this->client->request(Request::METHOD_GET, '/newsletter');

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $this->client->submit($crawler->filter('form[name=app_newsletter_subscription]')->form([
            'app_newsletter_subscription[email]' => 'titouan.galopin@en-marche.fr',
            'app_newsletter_subscription[postalCode]' => '20000',
        ]));

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        // Subscription should not have been saved
        $this->assertCount(6, $this->subscriptionsRepository->findAll());
    }

    public function testSubscriptionFromHome()
    {
        $this->assertCount(5, $this->subscriptionsRepository->findAll());

        // Initial form
        $crawler = $this->client->request(Request::METHOD_GET, '/');

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $this->client->submit($crawler->filter('form[name=app_newsletter_subscription]')->form([
            'app_newsletter_subscription[email]' => 'titouan.galopin@en-marche.fr',
            'app_newsletter_subscription[postalCode]' => '10000',
        ]));

        // Subscription should have been saved
        $this->assertCount(6, $this->subscriptionsRepository->findAll());

        // Email should have been sent
        $this->assertCount(1, $this->mailjetEmailRepository->findMessages(NewsletterSubscriptionMessage::class));
    }

    public function testInvitationAndRetry()
    {
        $this->assertCount(0, $this->newsletterInviteRepository->findAll());

        // Initial form
        $crawler = $this->client->request(Request::METHOD_GET, '/newsletter/invitation');

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $this->client->submit($crawler->filter('form[name=newsletter_invitation]')->form([
            'newsletter_invitation[firstName]' => 'Titouan',
            'newsletter_invitation[lastName]' => 'Galopin',
            'newsletter_invitation[guests][0]' => 'hugo.hamon@clichy-beach.com',
            'newsletter_invitation[guests][1]' => 'jules.pietri@clichy-beach.com',
        ]));

        $this->assertResponseStatusCode(Response::HTTP_FOUND, $this->client->getResponse());
        $this->assertClientIsRedirectedTo('/newsletter/invitation/merci', $this->client);

        $crawler = $this->client->followRedirect();

        $this->assertResponseStatusCode(Response::HTTP_OK, $this->client->getResponse());

        $this->assertContains('Vos 2 invitations ont bien été envoyées', trim($crawler->filter('.newsletter-result > h2')->text()));

        // Invitations should have been saved
        $this->assertCount(2, $invitations = $this->newsletterInviteRepository->findAll());

        $invite1 = $this->newsletterInviteRepository->findMostRecentInvite('hugo.hamon@clichy-beach.com');
        $this->assertSame('hugo.hamon@clichy-beach.com', $invite1->getEmail());
        $this->assertSame('Titouan Galopin', $invite1->getSenderFullName());

        $invite2 = $this->newsletterInviteRepository->findMostRecentInvite('jules.pietri@clichy-beach.com');
        $this->assertSame('jules.pietri@clichy-beach.com', $invite2->getEmail());
        $this->assertSame('Titouan Galopin', $invite2->getSenderFullName());

        // Email should have been sent
        $this->assertCount(2, $messages = $this->mailjetEmailRepository->findMessages(NewsletterInvitationMessage::class));
        $this->assertCount(1, $messages = $this->mailjetEmailRepository->findRecipientMessages(NewsletterInvitationMessage::class, $invite1->getEmail()));
        $this->assertContains('/newsletter?mail=hugo.hamon%40clichy-beach.com', $messages[0]->getRequestPayloadJson());

        $this->assertCount(1, $messages = $this->mailjetEmailRepository->findRecipientMessages(NewsletterInvitationMessage::class, $invite2->getEmail()));
        $this->assertContains('/newsletter?mail=jules.pietri%40clichy-beach.com', $messages[0]->getRequestPayloadJson());
    }

    public function testInvitationSentWithoutRedirection()
    {
        $this->client->request(Request::METHOD_GET, '/newsletter/invitation/merci');

        $this->assertResponseStatusCode(Response::HTTP_PRECONDITION_FAILED, $this->client->getResponse());
    }

    protected function setUp()
    {
        parent::setUp();

        $this->init([
            LoadHomeBlockData::class,
            LoadNewsletterSubscriptionData::class,
        ]);

        $this->subscriptionsRepository = $this->getNewsletterSubscriptionRepository();
        $this->newsletterInviteRepository = $this->getNewsletterInvitationRepository();
        $this->mailjetEmailRepository = $this->getMailjetEmailRepository();
    }

    protected function tearDown()
    {
        $this->kill();

        $this->subscriptionsRepository = null;
        $this->newsletterInviteRepository = null;
        $this->mailjetEmailRepository = null;

        parent::tearDown();
    }
}
