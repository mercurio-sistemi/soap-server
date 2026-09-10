<?php

declare(strict_types=1);

namespace GoetasWebservices\SoapServices\SoapServer\Tests;

use GoetasWebservices\SoapServices\Metadata\Generator\MetadataGenerator;
use GoetasWebservices\SoapServices\Metadata\Loader\DevMetadataLoader;
use GoetasWebservices\SoapServices\SoapServer\Router\ConfiguredRoute;
use GoetasWebservices\SoapServices\SoapServer\Router\DefaultRouter;
use GoetasWebservices\SoapServices\SoapServer\ServerFactory;
use GoetasWebservices\WsdlToPhp\Tests\Generator;
use GoetasWebservices\XML\SOAPReader\SoapReader;
use GoetasWebservices\XML\WSDLReader\DefinitionsReader;
use GoetasWebservices\Xsd\XsdToPhp\Naming\ShortNamingStrategy;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * setResponseValidator() is meant for a consumer to check the handler's return
 * value against, e.g., Symfony Validator constraints before it reaches
 * serialization - a bug that leaves a required field null would otherwise
 * produce a SOAP response missing a mandatory element instead of an error.
 */
class ResponseValidatorTest extends TestCase
{
    /**
     * @var string[]
     */
    protected static array $namespaces = ['http://www.example.org/test/' => 'Ex'];

    protected static Generator $generator;

    public static function setUpBeforeClass(): void
    {
        self::$generator = new Generator(self::$namespaces, [], __DIR__ . '/tmp-response-validator');
        self::$generator->generate([__DIR__ . '/../Fixtures/Soap/test.wsdl']);
        self::$generator->registerAutoloader();
    }

    public static function tearDownAfterClass(): void
    {
        self::$generator->unRegisterAutoloader();
    }

    private function buildServer(object $controller): \GoetasWebservices\SoapServices\SoapServer\Server
    {
        $serializer = self::$generator->buildSerializer();

        $naming = new ShortNamingStrategy();
        $dispatcher = new EventDispatcher();
        $wsdlReader = new DefinitionsReader(null, $dispatcher);
        $soapReader = new SoapReader();
        $dispatcher->addSubscriber($soapReader);

        $metadataGenerator = new MetadataGenerator($naming, self::$namespaces);
        $metadataGenerator->setUnwrap(true);
        $metadataLoader = new DevMetadataLoader($metadataGenerator, $soapReader, $wsdlReader);

        $router = new DefaultRouter(new ConfiguredRoute($controller, ['action' => 'http://www.example.org/test/getSimple']));
        $factory = new ServerFactory($metadataLoader, $serializer, $router);

        return $factory->getServer(__DIR__ . '/../Fixtures/Soap/test.wsdl');
    }

    private function buildRequest(): ServerRequest
    {
        $body = <<<'XML'
            <?xml version="1.0"?>
            <SOAP:Envelope xmlns:SOAP="http://www.w3.org/2003/05/soap-envelope" xmlns:test="http://www.example.org/test/">
              <SOAP:Body>
                <test:getSimple>
                  <in>hi</in>
                </test:getSimple>
              </SOAP:Body>
            </SOAP:Envelope>
            XML;

        return new ServerRequest(
            'POST',
            '/',
            ['Content-Type' => 'application/soap+xml; action="http://www.example.org/test/getSimple"'],
            $body
        );
    }

    public function testValidatorThatDoesNotThrowLeavesTheResponseUntouched(): void
    {
        $controller = new class {
            public function getSimple(\Ex\GetSimple $in): \Ex\GetSimpleResponse
            {
                $out = new \Ex\GetSimpleResponse();
                $out->setOut('ok');

                return $out;
            }
        };

        $server = $this->buildServer($controller);

        $called = false;
        $server->setResponseValidator(static function ($result) use (&$called): void {
            $called = true;
            self::assertInstanceOf(\Ex\GetSimpleResponse::class, $result);
        });

        $response = $server->handle($this->buildRequest());

        self::assertTrue($called, 'the response validator must be invoked with the handler result');
        self::assertStringContainsStringIgnoringCase('getsimpleresponse', (string) $response->getBody());
    }

    public function testValidatorThrowingProducesASoapFaultInsteadOfTheResponse(): void
    {
        $controller = new class {
            public function getSimple(\Ex\GetSimple $in): \Ex\GetSimpleResponse
            {
                return new \Ex\GetSimpleResponse();
            }
        };

        $server = $this->buildServer($controller);
        $server->setResponseValidator(static function ($result): void {
            throw new \RuntimeException('invalid response for the sake of this test');
        });

        $response = $server->handle($this->buildRequest());
        $body = (string) $response->getBody();

        self::assertStringContainsStringIgnoringCase('fault', $body);
        self::assertStringContainsString('invalid response for the sake of this test', $body);
    }
}
