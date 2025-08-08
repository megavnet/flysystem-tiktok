<?php

namespace Megavn\FlysystemTikTok;

use Exception;

class TikTokRequestException extends Exception
{
    protected array $body;

    public function __construct(string $message, array $body = [])
    {
        parent::__construct($message);
        $this->body = $body ?? [];
    }

    public function getBody(): array
    {
        return $this->body;
    }
}
