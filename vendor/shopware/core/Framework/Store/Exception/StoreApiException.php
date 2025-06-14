<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Store\Exception;

use GuzzleHttp\Exception\ClientException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Store\StoreException;
use Symfony\Component\HttpFoundation\Response;

#[Package('checkout')]
class StoreApiException extends StoreException
{
    /**
     * @var string
     *
     * @deprecated tag:v6.7.0 - Will be natively typed
     */
    protected $title;

    /**
     * @var string
     *
     * @deprecated tag:v6.7.0 - Will be natively typed
     */
    protected $documentationLink;

    public function __construct(ClientException $exception)
    {
        $data = [];

        try {
            $data = json_decode($exception->getResponse()->getBody()->getContents(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
        }

        parent::__construct(
            $this->getStatusCode(),
            $this->getErrorCode(),
            $data['description'] ?? $exception->getMessage()
        );

        $this->title = $data['title'] ?? '';
        $this->documentationLink = $data['documentationLink'] ?? '';
    }

    public function getStatusCode(): int
    {
        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    public function getErrorCode(): string
    {
        return 'FRAMEWORK__STORE_ERROR';
    }

    public function getErrors(bool $withTrace = false): \Generator
    {
        $error = [
            'code' => $this->getErrorCode(),
            'status' => (string) $this->getStatusCode(),
            'title' => $this->title,
            'detail' => $this->getMessage(),
            'meta' => [
                'documentationLink' => $this->documentationLink,
            ],
        ];

        if ($withTrace) {
            $error['trace'] = $this->getTraceAsString();
        }

        yield $error;
    }
}
