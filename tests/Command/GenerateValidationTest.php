<?php

declare(strict_types=1);

namespace GoetasWebservices\SoapServices\SoapServer\Tests\Command;

use GoetasWebservices\SoapServices\SoapServer\Command\Generate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

/**
 * Covers the "validation" destination end to end through the real `generate`
 * command: a config.yml with destinations_validation must produce Symfony
 * Validator YAML files for both the schema-derived response type and the
 * SOAP envelope/parts wrapper classes, with no destinations_validation at
 * all (the existing fixture) it must keep working exactly as before and
 * write no validation files.
 */
class GenerateValidationTest extends TestCase
{
    private static string $tmpDir;

    public static function setUpBeforeClass(): void
    {
        self::$tmpDir = sys_get_temp_dir() . '/soap-server-validation-test-' . uniqid();
        mkdir(self::$tmpDir . '/php', 0777, true);
        mkdir(self::$tmpDir . '/jms', 0777, true);
        mkdir(self::$tmpDir . '/validation', 0777, true);
        mkdir(self::$tmpDir . '/container', 0777, true);

        $configYml = Yaml::dump([
            'soap_server' => [
                'metadata' => [
                    'tests/Fixtures/Soap/test.wsdl' => null,
                ],
                'namespaces' => [
                    'http://www.example.org/test/' => 'TestNs',
                ],
                'destinations_php' => [
                    'TestNs' => self::$tmpDir . '/php',
                ],
                'destinations_jms' => [
                    'TestNs' => self::$tmpDir . '/jms',
                ],
                'destinations_validation' => [
                    'TestNs' => self::$tmpDir . '/validation',
                ],
                'aliases' => [
                    'http://www.example.org/test/' => [
                        'responseHeaderMessagesResponse' => 'HeaderResponse',
                    ],
                ],
            ],
        ]);
        file_put_contents(self::$tmpDir . '/config.yml', $configYml);

        $application = new Application();
        $application->add(new Generate());
        $tester = new CommandTester($application->find('generate'));
        $tester->execute([
            'config' => self::$tmpDir . '/config.yml',
            'dest-dir' => self::$tmpDir . '/container',
        ]);

        self::assertSame(0, $tester->getStatusCode());
    }

    public static function tearDownAfterClass(): void
    {
        self::deleteDirectory(self::$tmpDir);
    }

    private static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testValidationFileForSchemaDerivedResponseType(): void
    {
        // "out" is a required xsd:string (no minOccurs) inside GetSimpleResponse's
        // anonymous type - the field-level NotNull rule proves the underlying
        // YamlValidatorConverter walked the WSDL-embedded schema.
        $path = self::$tmpDir . '/validation/GetSimpleResponse.GetSimpleResponseAType.yml';
        $this->assertFileExists($path);

        $data = Yaml::parseFile($path);
        $className = array_key_first($data);
        $this->assertSame([
            ['NotNull' => ['groups' => ['xsd_rules']]],
        ], $data[$className]['properties']['out']);
    }

    public function testValidationFileForSoapPartsWrapper(): void
    {
        $path = self::$tmpDir . '/validation/SoapParts.GetSimpleOutput.yml';
        $this->assertFileExists($path);

        $data = Yaml::parseFile($path);
        $className = array_key_first($data);
        $this->assertSame([
            ['NotNull' => null],
            ['Valid' => null],
        ], $data[$className]['properties']['getSimpleResponse']);
    }

    public function testValidationFileForEnvelopeRequiresBodyNotHeader(): void
    {
        $path = self::$tmpDir . '/validation/SoapEnvelope12.Messages.GetSimpleOutput.yml';
        $this->assertFileExists($path);

        $data = Yaml::parseFile($path);
        $className = array_key_first($data);
        $this->assertSame([
            ['NotNull' => null],
            ['Valid' => null],
        ], $data[$className]['properties']['body']);
        $this->assertSame([
            ['Valid' => null],
        ], $data[$className]['properties']['header']);
    }
}
