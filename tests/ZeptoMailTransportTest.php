<?php

namespace ZohoMail\LaravelZeptoMail\Tests;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use ZohoMail\LaravelZeptoMail\Transport\ZeptoMailTransport;

class ZeptoMailTransportTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    protected $history = [];

    public function testTheMailerResolvesTheZeptoMailTransport()
    {
        $transport = Mail::mailer('zeptomail')->getSymfonyTransport();

        $this->assertInstanceOf(ZeptoMailTransport::class, $transport);
        $this->assertSame('zeptomail', (string) $transport);
    }

    public function testAnEmailIsPostedToTheRegionalEndpoint()
    {
        $transport = $this->transportReturning([new Response(200, [], '{"data":[]}')], 'zoho.eu');

        $email = (new Email())
            ->from(new Address('support@example.com', 'Support'))
            ->to(new Address('ada@example.com', 'Ada'))
            ->cc('copy@example.com')
            ->bcc('hidden@example.com')
            ->subject('Your session is confirmed')
            ->html('<p>See you at 10:00.</p>');

        // Laravel's Mailer always passes an envelope built from the message.
        $sent = $transport->send($email, Envelope::create($email));

        $this->assertInstanceOf(SentMessage::class, $sent);

        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://zeptomail.zoho.eu/v1.1/email', (string) $request->getUri());
        $this->assertSame('Zoho-enczapikey test-token', $request->getHeaderLine('Authorization'));

        $payload = json_decode((string) $request->getBody(), true);

        $this->assertSame(['name' => 'Support', 'address' => 'support@example.com'], $payload['from']);
        $this->assertSame([['email_address' => ['address' => 'ada@example.com', 'name' => 'Ada']]], $payload['to']);
        $this->assertSame([['email_address' => ['address' => 'copy@example.com']]], $payload['cc']);
        $this->assertSame([['email_address' => ['address' => 'hidden@example.com']]], $payload['bcc']);
        $this->assertSame('Your session is confirmed', $payload['subject']);
        $this->assertSame('<p>See you at 10:00.</p>', $payload['htmlbody']);
        $this->assertSame([], $payload['attachments']);
    }

    public function testAPlainTextEmailIsSentAsTheBody()
    {
        $transport = $this->transportReturning([new Response(200, [], '{"data":[]}')]);

        $email = (new Email())
            ->from('support@example.com')
            ->to('ada@example.com')
            ->subject('Reminder')
            ->text('Your session starts in an hour.');

        $transport->send($email, Envelope::create($email));

        $payload = json_decode((string) $this->history[0]['request']->getBody(), true);

        $this->assertSame('https://zeptomail.zoho.com/v1.1/email', (string) $this->history[0]['request']->getUri());
        $this->assertSame('Your session starts in an hour.', $payload['htmlbody']);
    }

    public function testAttachmentsAreSentBase64Encoded()
    {
        $transport = $this->transportReturning([new Response(200, [], '{"data":[]}')]);

        $email = (new Email())
            ->from('support@example.com')
            ->to('ada@example.com')
            ->subject('Your receipt')
            ->html('<p>Attached.</p>')
            ->attach('receipt body', 'receipt.txt', 'text/plain');

        $transport->send($email, Envelope::create($email));

        $payload = json_decode((string) $this->history[0]['request']->getBody(), true);

        $this->assertCount(1, $payload['attachments']);
        $this->assertSame('receipt.txt', $payload['attachments'][0]['name']);
        $this->assertSame('text/plain', $payload['attachments'][0]['mime_type']);
        $this->assertSame('receipt body', base64_decode($payload['attachments'][0]['content']));
    }

    public function testAnApiErrorIsReportedWithTheResponseBody()
    {
        Log::spy();

        $transport = $this->transportReturning([new Response(400, [], '{"error":{"code":"TM_4001"}}')]);

        $email = (new Email())
            ->from('support@example.com')
            ->to('ada@example.com')
            ->subject('Reminder')
            ->text('Your session starts in an hour.');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Mail API error: {"error":{"code":"TM_4001"}}');

        $transport->send($email, Envelope::create($email));
    }

    /**
     * A transport whose HTTP client answers from a queue instead of the network.
     *
     * @param  array<int, Response>  $responses
     */
    protected function transportReturning(array $responses, string $host = 'zoho.com'): ZeptoMailTransport
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        $transport = new class('Zoho-enczapikey test-token', $host) extends ZeptoMailTransport {
            public function useClient(HttpClient $client): void
            {
                $this->client = $client;
            }
        };

        $transport->useClient(new HttpClient(['handler' => $stack]));

        return $transport;
    }
}
