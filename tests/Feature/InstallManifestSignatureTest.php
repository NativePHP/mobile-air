<?php

namespace Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Laravel\Prompts\Output\BufferedConsoleOutput;
use Laravel\Prompts\Prompt;
use Native\Mobile\Commands\InstallCommand;
use Tests\TestCase;

/**
 * `native:install` checks the binaries manifest's signature before it reads
 * anything in it, once there is a key to check it against.
 */
class InstallManifestSignatureTest extends TestCase
{
    private const URL = 'https://bin.nativephp.com/main/4.1.0.json';

    private const MANIFEST = '{"versions":{"8.4":{"php_version":"8.4.27"}}}';

    private string $secret;

    private string $public;

    /** @var list<array{request: Request}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Prompt::setOutput(new BufferedConsoleOutput);

        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    public function test_without_a_pinned_key_nothing_is_checked(): void
    {
        [$body, $failures] = $this->verify([], keys: []);

        $this->assertSame(self::MANIFEST, $body);
        $this->assertSame([], $failures);
        $this->assertSame([], $this->requests);
    }

    public function test_a_signed_manifest_is_used(): void
    {
        [$body, $failures] = $this->verify([
            new Response(200, [], $this->sign(self::MANIFEST)),
        ]);

        $this->assertSame(self::MANIFEST, $body);
        $this->assertSame([], $failures);
        $this->assertSame([self::URL.'.sig'], $this->requestedUrls());
    }

    public function test_an_unsigned_manifest_fails_the_install(): void
    {
        [$body, $failures] = $this->verify([
            new Response(404),
        ]);

        $this->assertNull($body);
        $this->assertSame(['The PHP binaries manifest at '.self::URL." isn't signed, so nothing in it will be installed."], $failures);
    }

    /**
     * php-bin-mobile uploads the manifest a moment before its signature, so
     * a fetch in between sees the new manifest with the old signature.
     */
    public function test_a_manifest_caught_mid_upload_is_fetched_again(): void
    {
        $newer = '{"versions":{"8.4":{"php_version":"8.4.28"}}}';

        [$body, $failures] = $this->verify([
            new Response(200, [], $this->sign('{"versions":{"8.4":{"php_version":"8.4.26"}}}')),
            new Response(200, [], $newer),
            new Response(200, [], $this->sign($newer)),
        ], manifest: $newer);

        $this->assertSame($newer, $body);
        $this->assertSame([], $failures);
        $this->assertSame([self::URL.'.sig', self::URL, self::URL.'.sig'], $this->requestedUrls());
    }

    public function test_a_manifest_that_never_matches_its_signature_fails_the_install(): void
    {
        $tampered = '{"versions":{"8.4":{"php_version":"8.4.27","files":{}}}}';

        [$body, $failures] = $this->verify([
            new Response(200, [], $this->sign(self::MANIFEST)),
            new Response(200, [], $tampered),
            new Response(200, [], $this->sign(self::MANIFEST)),
        ], manifest: $tampered);

        $this->assertNull($body);
        $this->assertCount(1, $failures);
        $this->assertStringStartsWith('The PHP binaries manifest at '.self::URL." doesn't match its signature", $failures[0]);
    }

    public function test_a_signature_it_cannot_fetch_fails_the_install(): void
    {
        [$body, $failures] = $this->verify([
            new ConnectException('Connection refused', new Request('GET', self::URL.'.sig')),
        ]);

        $this->assertNull($body);
        $this->assertCount(1, $failures);
        $this->assertStringStartsWith('Failed to fetch the signature of the PHP binaries manifest from: '.self::URL.'.sig', $failures[0]);
    }

    /**
     * @param  list<Response|\Throwable>  $responses
     * @param  list<string>|null  $keys
     * @return array{0: ?string, 1: string[]}
     */
    private function verify(array $responses, ?array $keys = null, string $manifest = self::MANIFEST): array
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->requests));

        $command = new class(new Client(['handler' => $stack]), $keys ?? [$this->public]) extends InstallCommand
        {
            /** @param list<string> $keys */
            public function __construct(private Client $client, private array $keys)
            {
                parent::__construct();
            }

            /** @return array{0: ?string, 1: string[]} */
            public function check(string $url, string $body): array
            {
                return [$this->verifiedManifest($this->client, $url, $body), $this->installFailures];
            }

            protected function manifestKeys(): array
            {
                return $this->keys;
            }
        };

        return $command->check(self::URL, $manifest);
    }

    private function sign(string $manifest): string
    {
        return base64_encode(sodium_crypto_sign_detached($manifest, $this->secret));
    }

    /** @return list<string> */
    private function requestedUrls(): array
    {
        return array_map(fn (array $transaction) => (string) $transaction['request']->getUri(), $this->requests);
    }
}
