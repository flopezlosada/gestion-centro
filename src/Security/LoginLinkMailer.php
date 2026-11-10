<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;

/**
 * E-mails a passwordless sign-in link, for staff and families alike.
 *
 * The link is signed by the login-link handler of the CURRENT request's firewall, so asking from a
 * families' page yields a link back into the families' area, and from the staff login one into the staff
 * area. Every account signs in with its e-mail address as identifier, so that is where the link goes.
 */
final class LoginLinkMailer
{
    public function __construct(
        private readonly LoginLinkHandlerInterface $loginLinkHandler,
        private readonly MailerInterface $mailer,
        #[Autowire('%app.mailer_from%')]
        private readonly string $mailerFrom,
    ) {
    }

    /**
     * Sends the link.
     *
     * The link carries no "Recordarme" choice: it is asked on the page the link opens, on the device that
     * will stay signed in, which is often not the one that asked for the link. It must stay out of the URL,
     * too: Symfony reads the remember-me parameter from the query before the posted form, so one in the
     * link would override the checkbox on that page.
     *
     * @param UserInterface $account who signs in; their identifier is their e-mail address
     * @param string        $where   what the link opens, to complete "Entra en …" in the message
     */
    public function send(UserInterface $account, string $where): void
    {
        $url = $this->loginLinkHandler->createLoginLink($account)->getUrl();

        $this->mailer->send((new Email())
            ->from($this->mailerFrom)
            ->to($account->getUserIdentifier())
            ->subject('Tu enlace de acceso')
            ->text("Entra en {$where} con este enlace (válido 10 minutos):\n\n".$url));
    }
}
