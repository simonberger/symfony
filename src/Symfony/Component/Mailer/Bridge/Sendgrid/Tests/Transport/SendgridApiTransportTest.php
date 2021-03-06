<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\Sendgrid\Tests\Transport;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Bridge\Sendgrid\Transport\SendgridApiTransport;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class SendgridApiTransportTest extends TestCase
{
    /**
     * @dataProvider getTransportData
     */
    public function testToString(SendgridApiTransport $transport, string $expected)
    {
        $this->assertSame($expected, (string) $transport);
    }

    public function getTransportData()
    {
        return [
            [
                new SendgridApiTransport('KEY'),
                'sendgrid+api://api.sendgrid.com',
            ],
            [
                (new SendgridApiTransport('KEY'))->setHost('example.com'),
                'sendgrid+api://example.com',
            ],
            [
                (new SendgridApiTransport('KEY'))->setHost('example.com')->setPort(99),
                'sendgrid+api://example.com:99',
            ],
        ];
    }

    public function testSend()
    {
        $email = new Email();
        $email->from(new Address('foo@example.com', 'Ms. Foo Bar'))
            ->to(new Address('bar@example.com', 'Mr. Recipient'))
            ->bcc('baz@example.com')
            ->text('content');

        $response = $this->createMock(ResponseInterface::class);

        $response
            ->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(202);
        $response
            ->expects($this->once())
            ->method('getHeaders')
            ->willReturn(['x-message-id' => '1']);

        $httpClient = $this->createMock(HttpClientInterface::class);

        $httpClient
            ->expects($this->once())
            ->method('request')
            ->with('POST', 'https://api.sendgrid.com/v3/mail/send', [
                'json' => [
                    'personalizations' => [
                        [
                            'to' => [[
                                'email' => 'bar@example.com',
                                'name' => 'Mr. Recipient',
                            ]],
                            'subject' => null,
                            'bcc' => [['email' => 'baz@example.com']],
                        ],
                    ],
                    'from' => [
                        'email' => 'foo@example.com',
                        'name' => 'Ms. Foo Bar',
                    ],
                    'content' => [
                        ['type' => 'text/plain', 'value' => 'content'],
                    ],
                ],
                'auth_bearer' => 'foo',
            ])
            ->willReturn($response);

        $mailer = new SendgridApiTransport('foo', $httpClient);
        $mailer->send($email);
    }

    public function testLineBreaksInEncodedAttachment()
    {
        $email = new Email();
        $email->from('foo@example.com')
            ->to('bar@example.com')
            // even if content doesn't include new lines, the base64 encoding performed later may add them
            ->attach('Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod', 'lorem.txt');

        $response = $this->createMock(ResponseInterface::class);

        $response
            ->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(202);
        $response
            ->expects($this->once())
            ->method('getHeaders')
            ->willReturn(['x-message-id' => '1']);

        $httpClient = $this->createMock(HttpClientInterface::class);

        $httpClient
            ->expects($this->once())
            ->method('request')
            ->with('POST', 'https://api.sendgrid.com/v3/mail/send', [
                'json' => [
                    'personalizations' => [
                        [
                            'to' => [['email' => 'bar@example.com']],
                            'subject' => null,
                        ],
                    ],
                    'from' => ['email' => 'foo@example.com'],
                    'content' => [],
                    'attachments' => [
                        [
                            'content' => 'TG9yZW0gaXBzdW0gZG9sb3Igc2l0IGFtZXQsIGNvbnNlY3RldHVyIGFkaXBpc2NpbmcgZWxpdCwgc2VkIGRvIGVpdXNtb2Q=',
                            'filename' => 'lorem.txt',
                            'type' => 'application/octet-stream',
                            'disposition' => 'attachment',
                        ],
                    ],
                ],
                'auth_bearer' => 'foo',
            ])
            ->willReturn($response);

        $mailer = new SendgridApiTransport('foo', $httpClient);

        $mailer->send($email);
    }

    public function testCustomHeader()
    {
        $email = new Email();
        $email->getHeaders()->addTextHeader('foo', 'bar');
        $envelope = new Envelope(new Address('alice@system.com'), [new Address('bob@system.com')]);

        $transport = new SendgridApiTransport('ACCESS_KEY');
        $method = new \ReflectionMethod(SendgridApiTransport::class, 'getPayload');
        $method->setAccessible(true);
        $payload = $method->invoke($transport, $email, $envelope);

        $this->assertArrayHasKey('headers', $payload);
        $this->assertArrayHasKey('foo', $payload['headers']);
        $this->assertEquals('bar', $payload['headers']['foo']);
    }
}
