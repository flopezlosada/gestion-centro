<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * One-use token for the forms that CREATE something with an effect on other people — convening a meeting
 * (everyone gets the convocatoria), ordering copies (an e-mail to conserjería). A second press of the same
 * form, with a slow connection, would create a second one and repeat the effect.
 *
 * form-once.js already stops the second press on the page, but only while the first request is in flight
 * and up to its timeout, and only with JavaScript: the server has the last word. A version, like the one
 * that guards publishing the acta, does not work here — there is nothing yet to take the version of, every
 * submission would create a new row.
 *
 * WHY THE SESSION AND NOT A TABLE. The token only has to be unique to the person, and the session is
 * already theirs: no table, no purge. Two presses at once do not race on it because PHP locks the session
 * of a request until it ends (the native "files" handler, which is the one in use: framework.yaml sets no
 * handler_id), so the second request waits for the first and reads the token it left.
 */
class SubmissionOnce
{
    /** Name of the hidden field that carries the token. */
    public const FIELD = '_once';

    /** Session key holding the tokens already used. */
    private const SESSION_KEY = 'submission_once.used';

    /** Tokens remembered: the newest ones are enough, a second press comes seconds after the first. */
    private const REMEMBERED = 20;

    /**
     * A fresh token for a form about to be rendered.
     *
     * @return string 32 hex characters
     */
    public function token(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Takes the token the request brings. True the first time — go ahead and create — and false when the
     * same form was already sent. Call it once the form is valid and right before creating: a form sent
     * back with errors must not use up its token, or the corrected submission would be taken for a repeat.
     *
     * A request with no token (a page opened before this guard existed) goes ahead, as before.
     *
     * @param Request $request the form submission
     *
     * @return bool false when this token was already used
     */
    public function claim(Request $request): bool
    {
        $token = $request->request->getString(self::FIELD);
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $token)) {
            return true;
        }

        $session = $request->getSession();
        /** @var list<string> $used */
        $used = $session->get(self::SESSION_KEY, []);
        if (\in_array($token, $used, true)) {
            return false;
        }
        $session->set(self::SESSION_KEY, \array_slice([...$used, $token], -self::REMEMBERED));

        return true;
    }

    /**
     * Gives back the token of a submission that failed after claiming it (an error saving, a file that could
     * not be stored): nothing was created, so sending the form again must not be taken for a repeat.
     *
     * @param Request $request the form submission whose token was claimed
     */
    public function release(Request $request): void
    {
        $token = $request->request->getString(self::FIELD);
        $session = $request->getSession();
        /** @var list<string> $used */
        $used = $session->get(self::SESSION_KEY, []);
        $session->set(self::SESSION_KEY, array_values(array_filter($used, static fn (string $one): bool => $one !== $token)));
    }
}
