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
 * anything in it.
 */
class InstallManifestSignatureTest extends TestCase
{
    private const URL = 'https://bin.nativephp.com/main/versions.json';

    private const MANIFEST = '{"versions":{"8.4":{"php_version":"8.4.27"}}}';

    private string $secret;

    private string $public;

    private BufferedConsoleOutput $output;

    /** @var list<array{request: Request}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = new BufferedConsoleOutput;
        Prompt::setOutput($this->output);

        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    public function test_without_a_pinned_key_nothing_is_checked(): void
    {
        $this->assertSame(self::MANIFEST, $this->verify([], keys: []));
        $this->assertSame([], $this->requests);
    }

    public function test_a_signed_manifest_is_used(): void
    {
        $body = $this->verify([new Response(200, [], $this->sign(self::MANIFEST))]);

        $this->assertSame(self::MANIFEST, $body);
        $this->assertSame([self::URL.'.sig'], $this->requestedUrls());
        $this->assertSame('', $this->printed());
    }

    public function test_an_unsigned_manifest_is_not_used(): void
    {
        $this->assertNull($this->verify([new Response(404)]));
        $this->assertStringContainsString('The PHP binaries manifest at '.self::URL." isn't signed", $this->printed());
    }

    /**
     * php-bin-mobile uploads the manifest a moment before its signature, so
     * a fetch in between sees the new manifest with the old signature.
     */
    public function test_a_manifest_caught_mid_upload_is_fetched_again(): void
    {
        $newer = '{"versions":{"8.4":{"php_version":"8.4.28"}}}';

        $body = $this->verify([
            new Response(200, [], $this->sign('{"versions":{"8.4":{"php_version":"8.4.26"}}}')),
            new Response(200, [], $newer),
            new Response(200, [], $this->sign($newer)),
        ], manifest: $newer);

        $this->assertSame($newer, $body);
        $this->assertSame([self::URL.'.sig', self::URL, self::URL.'.sig'], $this->requestedUrls());
    }

    public function test_a_manifest_that_never_matches_its_signature_is_not_used(): void
    {
        $tampered = '{"versions":{"8.4":{"php_version":"8.4.27","files":{}}}}';

        $body = $this->verify([
            new Response(200, [], $this->sign(self::MANIFEST)),
            new Response(200, [], $tampered),
            new Response(200, [], $this->sign(self::MANIFEST)),
        ], manifest: $tampered);

        $this->assertNull($body);
        $this->assertStringContainsString("doesn't match its signature", $this->printed());
    }

    public function test_a_signature_it_cannot_fetch_means_the_manifest_is_not_used(): void
    {
        $body = $this->verify([new ConnectException('Connection refused', new Request('GET', self::URL.'.sig'))]);

        $this->assertNull($body);
        $this->assertStringContainsString('Failed to fetch the signature of the PHP binaries manifest', $this->printed());
    }

    /**
     * @param  list<Response|\Throwable>  $responses
     * @param  list<string>|null  $keys
     */
    private function verify(array $responses, ?array $keys = null, string $manifest = self::MANIFEST): ?string
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

            public function check(string $url, string $body): ?string
            {
                return $this->verifiedManifest($this->client, $url, $body);
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

    private function printed(): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $this->output->fetch())));
    }

    /** @return list<string> */
    private function requestedUrls(): array
    {
        return array_map(fn (array $transaction) => (string) $transaction['request']->getUri(), $this->requests);
    }
}
