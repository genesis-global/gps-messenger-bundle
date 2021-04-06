<?php

declare(strict_types=1);

namespace PetitPress\GpsMessengerBundle\Transport;

/**
 * @author Ronald Marfoldi <ronald.marfoldi@petitpress.sk>
 */
final class GpsConfiguration implements GpsConfigurationInterface
{
    private $queueName;
    private $subscriptionName;
    private $maxMessagesPull;
    private $keyFilePath;
    private $messageType;

    public function __construct(
        string $queueName,
        string $subscriptionName,
        int $maxMessagesPull,
        ?string $keyFilePath = null,
        ?string $messageType = null
    ) {
        $this->queueName = $queueName;
        $this->subscriptionName = $subscriptionName;
        $this->maxMessagesPull = $maxMessagesPull;
        $this->keyFilePath = $keyFilePath;
        $this->messageType = $messageType;
    }

    public function getQueueName(): string
    {
        return $this->queueName;
    }

    public function getSubscriptionName(): string
    {
        return $this->subscriptionName;
    }

    public function getMaxMessagesPull(): int
    {
        return $this->maxMessagesPull;
    }

    public function getKeyFilePath(): ?string
    {
        return $this->keyFilePath;
    }

    public function getMessageType(): ?string
    {
        return $this->messageType;
    }
}
