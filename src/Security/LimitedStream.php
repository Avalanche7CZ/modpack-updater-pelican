<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Security;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/** Caps bytes as cURL receives them, including chunked responses without Content-Length. */
final class LimitedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $received = 0;

    public function __construct(private readonly StreamInterface $stream, private readonly int $limit) {}

    public function write(string $string): int
    {
        $this->received += strlen($string);
        if ($this->received > $this->limit) {
            throw new RuntimeException('Provider metadata exceeds the byte limit');
        }

        return $this->stream->write($string);
    }
}
