<?php

declare(strict_types=1);

/**
 * Stub of ownCloud's legacy OC_Template, which OCP\Template extends; the
 * signatures are the server's (lib/private/legacy/template.php).
 */
class OC_Template
{
    /**
     * @param string $app
     * @param string $name
     * @param string $renderAs
     * @param bool $registerCall
     * @param string|null $languageCode
     */
    public function __construct($app, $name, $renderAs = '', $registerCall = true, $languageCode = null)
    {
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function assign($key, $value)
    {
        return true;
    }

    /**
     * @param array<string, mixed>|null $additionalParams
     * @return string|false
     */
    public function fetchPage($additionalParams = null)
    {
        return '';
    }
}
