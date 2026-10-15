<?php

declare(strict_types=1);

namespace TinyTank\Tests;

use TinyTank\Kernel;

final class DatastarTest extends FrameworkTestCase
{
    public function testSignedTemplateUrlRendersThePartialAsServerSentEvents(): void
    {
        $app = $this->kernel();
        $response = $this->request($app, $this->greetUrl($app, '/') . '&datastar=' . rawurlencode('{"mood":"happy"}'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        self::assertNull($response->getEtag(), 'SSE responses are never cached');

        $events = self::body($response);
        self::assertStringContainsString("event: datastar-patch-elements\ndata: elements <p id=\"greeting\">Hello Ada, happy</p>", $events);
        self::assertStringContainsString("event: datastar-patch-signals\ndata: signals {\"greeted\":true}", $events);
    }

    public function testSignalsArriveInTheBodyForPostRequests(): void
    {
        $app = $this->kernel();
        $response = $this->request($app, self::templateUrl($app, 'post'), 'POST', ['Sec-Fetch-Site' => 'same-origin'], '{"mood":"calm"}');

        self::assertStringContainsString('Hello Ada, calm', self::body($response));
    }

    public function testTheSignedMethodIsTheOnlyOneAccepted(): void
    {
        $app = $this->kernel();
        $same = ['Sec-Fetch-Site' => 'same-origin'];
        $post = self::templateUrl($app, 'post');

        $response = $this->request($app, $post);
        self::assertSame(405, $response->getStatusCode(), 'a POST action replayed as a link or prefetch');
        self::assertSame('POST', $response->headers->get('Allow'));
        self::assertStringNotContainsString('Hello', self::body($response));
        self::assertSame(405, $this->request($app, self::templateUrl($app, 'delete'), 'POST', $same)->getStatusCode());
        self::assertSame(405, $this->request($app, $this->greetUrl($app, '/'), 'POST', $same)->getStatusCode());
        self::assertSame(200, $this->request($app, $this->greetUrl($app, '/'), 'HEAD')->getStatusCode(), 'HEAD goes with GET');
    }

    public function testConfigsSignedWithoutAMethodAreRejected(): void
    {
        // Signed correctly, but from before methods were signed: it could be a POST action.
        $payload = rtrim(strtr(base64_encode('{"t":"_partials/greet","v":{"who":"Ada"}}'), '+/', '-_'), '=');
        $config = $payload . '.' . hash_hmac('sha256', $payload, (string) getenv('APP_SECRET'));

        self::assertSame(400, $this->request($this->kernel(), '/datastar?config=' . $config)->getStatusCode());
    }

    public function testTamperedConfigIsRejected(): void
    {
        $app = $this->kernel();
        $url = $this->greetUrl($app, '/');
        [$payload] = explode('.', (string) parse_url($url, PHP_URL_QUERY));
        $forged = rtrim(strtr(base64_encode('{"t":"_error","v":{"status":1,"message":"x"}}'), '+/', '-_'), '=');

        self::assertSame(400, $this->request($app, $url . 'x')->getStatusCode());
        self::assertSame(400, $this->request($app, '/datastar?config=' . $forged . '.' . substr($url, -64))->getStatusCode());
        self::assertSame(400, $this->request($app, '/datastar?' . $payload)->getStatusCode());
    }

    public function testEventsOfAFailedRequestDontLeakIntoTheNext(): void
    {
        $app = $this->kernel(debug: false);
        $app->get('/half', static function () use ($app) {
            $app->datastar->patchSignals(['stale' => true]); // queued, then the template fails
            throw new \RuntimeException('Template failed halfway');
        });

        self::assertSame(500, $this->request($app, '/half')->getStatusCode());
        $events = self::body($this->request($app, $this->greetUrl($app, '/')));
        self::assertStringNotContainsString('stale', $events);
        self::assertStringContainsString('Hello Ada', $events);
    }

    public function testUrlsKeepTheCurrentLanguage(): void
    {
        $app = $this->kernel();
        $url = $this->greetUrl($app, '/it');

        self::assertStringStartsWith('/it/datastar?config=', $url);
        self::assertStringContainsString('Ciao Ada', self::body($this->request($app, $url)));
    }

    public function testActionsAreDatastarExpressions(): void
    {
        $datastar = $this->kernel()->datastar;

        self::assertSame('@post("/clock")', $datastar->action('POST', '/clock'));
        self::assertSame('@get("/x", {"openWhenHidden":true})', $datastar->action('get', '/x', ['openWhenHidden' => true]));
    }

    /** The greet partial's URL for a datastar.<method>() action. */
    private static function templateUrl(Kernel $app, string $method): string
    {
        $expression = $app->twig->createTemplate("{{ datastar.{$method}('_partials/greet', {who: 'Ada'}) }}")->render();
        self::assertSame(1, preg_match('#@' . $method . '\(&quot;([^&]+)&quot;\)#', $expression, $match));

        return html_entity_decode($match[1] ?? '');
    }

    /** The greet button's URL, as rendered on a page. */
    private function greetUrl(Kernel $app, string $page): string
    {
        self::assertSame(1, preg_match('#@get\(&quot;([^&]+)&quot;\)#', self::body($this->request($app, $page)), $match), 'greet button not found');

        return html_entity_decode($match[1] ?? '');
    }
}
