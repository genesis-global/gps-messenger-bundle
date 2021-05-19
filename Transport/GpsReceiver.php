<?php

declare(strict_types=1);

namespace PetitPress\GpsMessengerBundle\Transport;

use Google\Cloud\PubSub\Message;
use Google\Cloud\PubSub\PubSubClient;
use JsonException;
use LogicException;
use PetitPress\GpsMessengerBundle\Transport\Stamp\GpsReceivedStamp;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Throwable;

/**
 * @author Ronald Marfoldi <ronald.marfoldi@petitpress.sk>
 */
final class GpsReceiver implements ReceiverInterface
{
    private $pubSubClient;
    private $gpsConfiguration;
    private $serializer;

    public function __construct(
        PubSubClient $pubSubClient,
        GpsConfigurationInterface $gpsConfiguration,
        SerializerInterface $serializer
    ) {
        $this->pubSubClient = $pubSubClient;
        $this->gpsConfiguration = $gpsConfiguration;
        $this->serializer = $serializer;
    }

    /**
     * {@inheritdoc}
     */
    public function get(): iterable
    {
        try {
            $topic = $this->pubSubClient->topic($this->gpsConfiguration->getQueueName());
            $topic->exists() ?: $topic->create();

            $subscription = $this->pubSubClient->subscription(
                $this->gpsConfiguration->getSubscriptionName(),
                $this->gpsConfiguration->getQueueName()
            );
            $subscription->exists() ?: $subscription->create();

            $messages = $subscription->pull(['maxMessages' => $this->gpsConfiguration->getMaxMessagesPull()]);

            foreach ($messages as $message) {
                yield $this->createEnvelopeFromPubSubMessage($message);
            }
        } catch (Throwable $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function ack(Envelope $envelope): void
    {
        try {
            $gpsReceivedStamp = $this->getGpsReceivedStamp($envelope);

            $this->pubSubClient
                ->subscription($this->gpsConfiguration->getSubscriptionName())
                ->acknowledge($gpsReceivedStamp->getGpsMessage());
        } catch (Throwable $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Called when handling the message failed and allows to warn PUB/SUB not to wait the ack.
     * After warning PUB/SUB, it will try to redeliver the message according to set up retry policy.
     *
     * @throws TransportException If there is an issue communicating with the transport
     *
     * @see https://cloud.google.com/pubsub/docs/reference/rest/v1/projects.subscriptions#RetryPolicy
     */
    public function reject(Envelope $envelope): void
    {
        try {
            $gpsReceivedStamp = $this->getGpsReceivedStamp($envelope);

            $this->pubSubClient
                ->subscription($this->gpsConfiguration->getSubscriptionName())
                ->modifyAckDeadline($gpsReceivedStamp->getGpsMessage(), 0);
        } catch (Throwable $exception) {
            throw new TransportException($exception->getMessage(), 0, $exception);
        }
    }

    private function getGpsReceivedStamp(Envelope $envelope): GpsReceivedStamp
    {
        $gpsReceivedStamp = $envelope->last(GpsReceivedStamp::class);
        if ($gpsReceivedStamp instanceof GpsReceivedStamp) {
            return $gpsReceivedStamp;
        }

        throw new LogicException('No GpsReceivedStamp found on the Envelope.');
    }

    /**
     * Creates Symfony Envelope from Google Pub/Sub Message.
     * It adds stamp with received native Google Pub/Sub message.
     */
    private function createEnvelopeFromPubSubMessage(Message $message): Envelope
    {
        try {
            $body = $message->data();
            $headers = [];
            if (array_key_exists('headers', $message->attributes())) {
                $headers = json_decode($message->attribute('headers'), true);
            }
            if (empty($headers['type'])) {
                $headers['type'] = $this->gpsConfiguration->getMessageType();
            }
        } catch (JsonException $exception) {
            throw new MessageDecodingFailedException($exception->getMessage(), 0, $exception);
        }

        try {
            $envelope = $this->serializer->decode(['body' => $body, 'headers' => $headers]);
        } catch (MessageDecodingFailedException $exception) {
            throw $exception;
        }

        return $envelope->with(new GpsReceivedStamp($message));
    }
}
