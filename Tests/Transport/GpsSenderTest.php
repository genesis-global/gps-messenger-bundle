<?php

declare(strict_types=1);

namespace PetitPress\GpsMessengerBundle\Tests\Transport;

use Google\Cloud\PubSub\BatchPublisher;
use Google\Cloud\PubSub\PubSubClient;
use Google\Cloud\PubSub\Topic;
use PetitPress\GpsMessengerBundle\Transport\GpsConfigurationInterface;
use PetitPress\GpsMessengerBundle\Transport\GpsSender;
use PetitPress\GpsMessengerBundle\Transport\Stamp\OrderingKeyStamp;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * @author Mickael Prévôt <mickael.prevot@ext.adeo.com>
 */
class GpsSenderTest extends TestCase
{
    private const ORDERED_KEY = 'ordered-key';
    private const TOPIC_NAME = 'topic-name';

    private $gpsConfigurationProphecy;
    private $gpsSender;
    private $serializerProphecy;
    private $topicProphecy;

    protected function setUp(): void
    {
        $this->gpsConfigurationProphecy = $this->prophesize(GpsConfigurationInterface::class);
        $this->serializerProphecy = $this->prophesize(SerializerInterface::class);
        $this->topicProphecy = $this->prophesize(Topic::class);

        $this->gpsConfigurationProphecy->getQueueName()->willReturn(self::TOPIC_NAME)->shouldBeCalledOnce();
        $this->gpsConfigurationProphecy->getBatchSize()->willReturn(0);

        $pubSubClientProphecy = $this->prophesize(PubSubClient::class);
        $pubSubClientProphecy->topic(self::TOPIC_NAME)->willReturn($this->topicProphecy->reveal())->shouldBeCalledOnce();

        $this->gpsSender = new GpsSender(
            $pubSubClientProphecy->reveal(),
            $this->gpsConfigurationProphecy->reveal(),
            $this->serializerProphecy->reveal()
        );
    }

    public function testItDoesNotPublishIfTheLastStampIsOfTyeRedelivery(): void
    {
        $envelope = EnvelopeFactory::create(new RedeliveryStamp(0));
        $envelopeArray = ['body' => [], 'headers' => ['class' => 'class', 'stamps' => 'stamps']];

        $this->serializerProphecy->encode($envelope)->willReturn($envelopeArray)->shouldBeCalledOnce();

        $this->topicProphecy->publish(Argument::any())->shouldNotBeCalled();
        $this->topicProphecy->batchPublisher(Argument::any())->shouldNotBeCalled();

        self::assertSame($envelope, $this->gpsSender->send($envelope));
    }

    public function testItPublishesWithOrderingKey(): void
    {
        $envelope = EnvelopeFactory::create(new OrderingKeyStamp(self::ORDERED_KEY));
        $envelopeArray = ['body' => '[]', 'headers' => ['class' => 'class', 'stamps' => 'stamps']];

        $this->serializerProphecy->encode($envelope)->willReturn($envelopeArray)->shouldBeCalledOnce();

        $this->topicProphecy->exists()->shouldBeCalledOnce()->willReturn(true);
        $this->topicProphecy->publish(Argument::allOf(
            new Argument\Token\ObjectStateToken('data', $envelopeArray['body']),
            new Argument\Token\ObjectStateToken('attributes', ['headers' => \json_encode($envelopeArray['headers'])]),
            new Argument\Token\ObjectStateToken('orderingKey', self::ORDERED_KEY)
        ))->shouldBeCalledOnce();

        self::assertSame($envelope, $this->gpsSender->send($envelope));
    }

    public function testItPublishesWithoutOrderingKey(): void
    {
        $envelope = EnvelopeFactory::create();
        $envelopeArray = ['body' => '[]', 'headers' => ['class' => 'class', 'stamps' => 'stamps']];

        $this->serializerProphecy->encode($envelope)->willReturn($envelopeArray)->shouldBeCalledOnce();

        $this->gpsConfigurationProphecy->getQueueName()->willReturn(self::TOPIC_NAME)->shouldBeCalledOnce();

        $this->topicProphecy->exists()->shouldBeCalledOnce()->willReturn(true);
        $this->topicProphecy->publish(Argument::allOf(
            new Argument\Token\ObjectStateToken('data', $envelopeArray['body']),
            new Argument\Token\ObjectStateToken('attributes', ['headers' => \json_encode($envelopeArray['headers'])]),
            new Argument\Token\ObjectStateToken('orderingKey', null)
        ))->shouldBeCalledOnce();

        self::assertSame($envelope, $this->gpsSender->send($envelope));
    }

    public function testItCreatesTopicWhenNotExists(): void
    {
        $envelope = EnvelopeFactory::create();
        $envelopeArray = ['body' => '[]', 'headers' => ['class' => 'class', 'stamps' => 'stamps']];

        $this->serializerProphecy->encode($envelope)->willReturn($envelopeArray)->shouldBeCalledOnce();

        $this->topicProphecy->exists()->shouldBeCalledOnce()->willReturn(false);
        $this->topicProphecy->create()->shouldBeCalledOnce();
        $this->topicProphecy->publish(Argument::any())->shouldBeCalledOnce();

        self::assertSame($envelope, $this->gpsSender->send($envelope));
    }

    public function testItPublishesInBatch()
    {
        $envelope = EnvelopeFactory::create();
        $envelopeArray = ['body' => '[]', 'headers' => ['class' => 'class', 'stamps' => 'stamps']];

        $this->serializerProphecy->encode($envelope)->willReturn($envelopeArray)->shouldBeCalledOnce();

        $this->gpsConfigurationProphecy->getBatchSize()->willReturn(10);
        $this->topicProphecy->exists()->shouldBeCalledOnce()->willReturn(true);
        $batchPublisher = $this->prophesize(BatchPublisher::class);
        $batchPublisher->publish(Argument::allOf(
            new Argument\Token\ObjectStateToken('data', $envelopeArray['body']),
            new Argument\Token\ObjectStateToken('attributes', ['headers' => \json_encode($envelopeArray['headers'])]),
            new Argument\Token\ObjectStateToken('orderingKey', null)
        ))->shouldBeCalledOnce();
        $this->topicProphecy->batchPublisher(Argument::withEntry('batchSize', 10))->shouldBeCalledOnce()
        ->willReturn($batchPublisher->reveal());

        self::assertSame($envelope, $this->gpsSender->send($envelope));
    }
}
