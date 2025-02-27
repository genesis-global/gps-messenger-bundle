<?php

declare(strict_types=1);

namespace PetitPress\GpsMessengerBundle\Transport;

use Google\Cloud\PubSub\MessageBuilder;
use Google\Cloud\PubSub\PubSubClient;
use PetitPress\GpsMessengerBundle\Transport\Stamp\OrderingKeyStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * @author Ronald Marfoldi <ronald.marfoldi@petitpress.sk>
 */
final class GpsSender implements SenderInterface
{
    private $gpsConfiguration;
    private $serializer;
    private $topic;

    public function __construct(
        PubSubClient $pubSubClient,
        GpsConfigurationInterface $gpsConfiguration,
        SerializerInterface $serializer
    ) {
        $this->gpsConfiguration = $gpsConfiguration;
        $this->serializer = $serializer;
        $this->topic = $pubSubClient->topic($this->gpsConfiguration->getQueueName());
    }

    /**
     * {@inheritdoc}
     */
    public function send(Envelope $envelope): Envelope
    {
        $encodedMessage = $this->serializer->encode($envelope);

        $messageBuilder = new MessageBuilder();
        $messageBuilder = $messageBuilder->setData($encodedMessage['body']);

        if (\array_key_exists('headers', $encodedMessage)) {
            $messageBuilder = $messageBuilder->addAttribute('headers', json_encode($encodedMessage['headers']));
        }

        $redeliveryStamp = $envelope->last(RedeliveryStamp::class);
        if ($redeliveryStamp instanceof RedeliveryStamp) {
            // do not try to redeliver, message wasn't acknowledged, so let's Google Pub/Sub do its job with retry policy
            return $envelope;
        }

        $orderingKeyStamp = $envelope->last(OrderingKeyStamp::class);
        if ($orderingKeyStamp instanceof OrderingKeyStamp) {
            $messageBuilder = $messageBuilder->setOrderingKey($orderingKeyStamp->getOrderingKey());
        }

        $this->topic->exists() ?: $this->topic->create();
        if ($this->gpsConfiguration->getBatchSize() > 0) {
            $this->topic
                ->batchPublisher(['batchSize' => $this->gpsConfiguration->getBatchSize()])
                ->publish($messageBuilder->build());
        } else {
            $this->topic->publish($messageBuilder->build());
        }

        return $envelope;
    }
}
