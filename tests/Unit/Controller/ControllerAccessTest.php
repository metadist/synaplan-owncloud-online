<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may call each route, read the way ownCloud.online reads it: the
 * capitalised @-words of the method's own doc comment
 * (OC\AppFramework\Utility\ControllerMethodReflector). A route added without
 * a row here fails, so its access is decided on purpose.
 */
final class ControllerAccessTest extends TestCase
{
    private const ADMIN = 'admin';
    private const PERSON = 'person';

    /**
     * Route => who, then the exceptions to the defaults (CSRF checked, the
     * session closed before the controller runs, sign-in required).
     *
     * @var array<string, array{0: string, 1?: list<string>}>
     */
    private const ACCESS = [
        'page#research' => [self::PERSON, ['NoCSRFRequired']],

        'settings#getSettings' => [self::ADMIN],
        'settings#saveSettings' => [self::ADMIN],
        'settings#testConnection' => [self::ADMIN],
        'settings#registerInstance' => [self::ADMIN],
        'settings#forgetInstance' => [self::ADMIN],
        'settings#instanceStatus' => [self::ADMIN],

        'api#summarize' => [self::PERSON],
        'api#translate' => [self::PERSON],
        'api#uploadToKnowledge' => [self::PERSON],
        'api#knowledgeStatus' => [self::PERSON],
        'api#removeFromKnowledge' => [self::PERSON],
        'api#getGroups' => [self::PERSON],
        'api#getModels' => [self::PERSON],
        'api#clientConfig' => [self::PERSON],
        'api#features' => [self::PERSON],

        'consent#getConsent' => [self::PERSON],
        'consent#setConsent' => [self::PERSON],

        // Both legs of the handshake are browser navigations; state is the CSRF check.
        'link#start' => [self::PERSON, ['NoCSRFRequired', 'UseSession']],
        'link#callback' => [self::PERSON, ['NoCSRFRequired', 'UseSession']],
        'link#disconnect' => [self::PERSON],
        'link#status' => [self::PERSON],

        'adminAiUsers#list' => [self::ADMIN],
        'adminAiUsers#usage' => [self::ADMIN],
        'adminAiUsers#deactivate' => [self::ADMIN],

        'chat#chat' => [self::PERSON],
        'chat#chatStream' => [self::PERSON],

        'media#generate' => [self::PERSON],
        'media#save' => [self::PERSON],
        // An <img> source: the browser sends no request token with it.
        'media#proxy' => [self::PERSON, ['NoCSRFRequired']],
    ];

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function routes(): array
    {
        $routes = [];
        foreach ((require dirname(__DIR__, 3) . '/appinfo/routes.php')['routes'] as $route) {
            $routes[$route['name']] = [$route['name'], $route['verb'], $route['url']];
        }

        return $routes;
    }

    #[DataProvider('routes')]
    public function testEachRouteHasTheAccessDecidedForIt(string $name, string $verb, string $url): void
    {
        self::assertArrayHasKey($name, self::ACCESS, $name . ' has no access row in this test');
        [$who, $exceptions] = self::ACCESS[$name] + [1 => []];

        $annotations = $this->annotations($name);
        $expected = $exceptions;
        if ($who === self::PERSON) {
            $expected[] = 'NoAdminRequired';
        }
        sort($expected);

        self::assertSame($expected, $annotations, $name . ' (' . $verb . ' ' . $url . ')');
    }

    #[DataProvider('routes')]
    public function testOnlyBrowserNavigationsSkipTheCsrfCheck(string $name, string $verb): void
    {
        if (in_array('NoCSRFRequired', $this->annotations($name), true)) {
            self::assertSame('GET', $verb, $name . ' changes state without the CSRF check');
        } else {
            $this->addToAssertionCount(1);
        }
    }

    public function testNoPageIsPublic(): void
    {
        foreach (array_keys(self::routes()) as $name) {
            self::assertNotContains('PublicPage', $this->annotations($name), $name);
        }
    }

    public function testEveryPublicControllerMethodHasARoute(): void
    {
        $routed = array_keys(self::routes());
        foreach (glob(dirname(__DIR__, 3) . '/lib/Controller/*Controller.php') ?: [] as $file) {
            $class = 'OCA\\OcoSynaplan\\Controller\\' . basename($file, '.php');
            $prefix = lcfirst(substr(basename($file, '.php'), 0, -strlen('Controller')));
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor()) {
                    continue;
                }
                self::assertContains($prefix . '#' . $method->getName(), $routed, $class . '::' . $method->getName() . ' is public but has no route');
            }
        }
    }

    /**
     * @return list<string> sorted
     */
    private function annotations(string $route): array
    {
        [$controller, $method] = explode('#', $route);
        $class = 'OCA\\OcoSynaplan\\Controller\\' . ucfirst($controller) . 'Controller';
        self::assertTrue(method_exists($class, $method), $route . ' names a method that does not exist');
        preg_match_all('/@([A-Z]\w+)/', (string) (new \ReflectionMethod($class, $method))->getDocComment(), $matches);
        $annotations = array_values(array_unique($matches[1]));
        sort($annotations);

        return $annotations;
    }
}
